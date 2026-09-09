# T-Box_Re — 사내 파일 공유 서비스 t-box 재구축

교육용으로 일부러 취약하게 만든 t-box(해킹 실습 대상)를, **실습에서 뚫린 5가지 취약점이 없는 형태**로 처음부터 다시 만드는 과제 저장소입니다.

| 항목 | 내용 |
|---|---|
| 스택 | 순수 PHP 8.1 (프레임워크 없음) · PostgreSQL 14 · nginx 1.18 · Ubuntu 22.04 — 실습 VM 설치본 그대로 (운영 전환 시 업그레이드, SECURITY R-21) |
| 진행 방식 | AI와 기능 하나씩 → **내가 직접 공격해 검증** → 한글 커밋 |
| 상태 | 1단계(뼈대·DB) 구현·검토 완료 — 자기 공격 확인 후 커밋 예정 (2026-09-09) |

## 문서

| 문서 | 무엇이 들어 있나 | 언제 읽나 |
|---|---|---|
| [`PRD.md`](PRD.md) | 역할 4종 × 접근 레벨 4종 권한 매트릭스, 보안 요구사항 S-01~S-11, 수락 기준 T-1~T-23, **9단계 로드맵(13장)** | 기능·정책이 궁금할 때 |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | 요청 처리 순서, 업로드/목록/다운로드/삭제 흐름과 **권한 검사 위치**, 저장 위치, 감사 로그, nginx/php/DB 설정, 서버 기준선, 백업·복구·침해 대응 | 코드를 짜거나 서버를 세울 때 |
| [`SECURITY.md`](SECURITY.md) | **보안 자체점검 문서**. 5대 취약점 대조, 잔여 위험 R-xx, 앱 밖 위험, 단계별 공격 결과 이력(7장) | 매 단계 끝, 제출 전 |
| [`CLAUDE.md`](CLAUDE.md) | AI 작업 지침 — Fable(설계·검토) / Opus(구현) 역할, 검토 증거 규칙, 커밋 규칙 | AI 와 작업할 때 |

## 실습에서 뚫린 5가지 → 이번 설계의 방어 (요약)

| 공격 | 방어 | 검증 |
|---|---|---|
| 프론트 권한 우회 | 모든 요청을 서버 `Authz` 가 판단, 라우트에 권한 정책 필수, CSRF | T-1 |
| 권한 없는 데이터 노출 | SQL `WHERE` 에서 제외 — 응답에 아예 없음 | T-2, T-3 |
| XSS | 서버 이스케이프 + `textContent` + CSP + 다운로드 `attachment` | T-4 |
| SQL 인젝션 | Prepared Statement 만, 정렬 허용 목록, 오류 은닉 | T-5 |
| SSRF | "URL로 가져오기"는 `src/Fetch.php` 한 곳에서만: https·443 만, 도메인 화이트리스트, IP 검증 + 고정 접속, 리다이렉트 금지, 업로드와 동일 검증 | T-6 |

자세한 근거와 남은 위험은 `SECURITY.md` 를 보세요.

## 저장소 구조 (구현 후)

```
public/      nginx root — index.php(단일 입구), assets/
src/         Auth · Authz · Audit · Db · Storage · Upload · handlers/
db/          schema.sql · grants.sql · seed.sql
deploy/      sites/tbox-v{1,2}{,-local}.conf · switch-site.sh · install.sh · php-fpm-pool.conf · DEPENDENCIES.md
scripts/     security_lint.sh · backup_*.sh · consistency_check.php
.env.example 설정 키 예시 (실제 .env 는 커밋하지 않음)
```

## 설치 · 실행

실습 VM(Ubuntu 22.04 · PHP 8.1 · PostgreSQL 14 · nginx 1.18) 기준입니다. 설정 내용은 `ARCHITECTURE.md` 3장 배포 메모와 8·9·10장 그대로입니다.

```bash
sudo bash deploy/install.sh          # 1) 계정·디렉터리·DB·.env·php-fpm 풀·nginx 사이트 파일
sudo bash deploy/switch-site.sh v2   # 2) 80 포트(도메인)에 v2 를 꽂는다
```

**1) `install.sh`** 는 한 번에 다 합니다 — 전용 계정 `tbox-php`, 웹 루트 밖 디렉터리(`/var/lib/tbox`, `/var/log/tbox`, `/etc/tbox`),
PostgreSQL 역할·DB·스키마·최소 권한, `.env` 생성, php-fpm 풀(`/etc/php/8.1/fpm/pool.d/tbox.conf`),
nginx 사이트 파일 설치와 로컬 포트 enable.
**도메인에 공개 중인 버전은 바꾸지 않습니다** — 그건 2)의 몫입니다. (TLS 인증서는 만들지 않습니다. 아래 "왜 80 포트인가" 참고.)

**2) `switch-site.sh`** 는 80 포트에 꽂을 버전을 고릅니다. `v1` / `v2` / `v3` / `status`.

**멱등합니다.** 두 스크립트 모두 여러 번 실행해도 안전하고, 이미 있는 `.env` 의 비밀번호는 바꾸지 않습니다.
패키지는 설치하지 않습니다 — 없으면 무엇이 없는지 알려 주고 멈춥니다.

### 왜 80 포트인가 — 세 버전을 한 포트로 (`ARCHITECTURE.md` 3장 배포 메모)

이 VM 의 외부 노출 구조는 VM 에서 바꿀 수 없습니다. 강사 리버스 프록시가 TLS 를 종단하고 `http→https` 301 을 한 뒤
VM 의 **80 포트 하나**로만 전달합니다. 그래서 VM 은 TLS 를 하지 않고(자체 서명 인증서 불필요), 도메인으로 보이는 웹은 한 번에 한 버전입니다.

```
브라우저 ──https──▶ 강사 리버스 프록시 (changkwon.mso.so = 112.220.89.250, TLS 종단, http→https 301)
                       └──http──▶ 이 VM : 80 포트 하나 (프록시 내부 IP 192.168.104.100)
```

| 버전 | 디렉터리 | 공개 사이트 | 항상 열린 로컬 포트 | DB | php-fpm 풀 |
|---|---|---|---|---|---|
| v1 실습(취약) | `/var/www/html` (강사 저장소, 불변) | `tbox-v1` | `127.0.0.1:8081` | `tbox` | 기본 `www` |
| **v2 보안 보완 (이 저장소)** | `/var/www/html/T-Box_Re` | `tbox-v2` | `127.0.0.1:8082` | `tbox_re` | `tbox` (tbox-php) |
| v3 새 T-BOX (미착수) | `/var/www/tbox-v3` | `tbox-v3` | `127.0.0.1:8083` | 별도 | 별도 |

- 실제 접속 IP 는 `set_real_ip_from 192.168.104.100` + `real_ip_header X-Forwarded-For` 로 복원합니다 — **프록시 IP 가 보낸 헤더만** 신뢰하므로 다른 곳에서 온 위조 헤더는 무시됩니다 (`SECURITY.md` R-30·R-31).
- HSTS(`S-09-1`)는 공개용 `tbox-v2` 에만 붙습니다. 로컬 8082(평문)에 붙이면 접속이 불가능해집니다.
- v1 사이트에는 `deploy/old-site-deny.conf` 가 include 되어 `/T-Box_Re/` 접근이 404 입니다 (v2 소스가 v1 경유로 새는 것 차단).

### 접속 주소

| 대상 | 주소 | 비고 |
|---|---|---|
| **공개 (도메인)** | `https://changkwon.mso.so/` | 지금 80 포트에 꽂힌 버전. `switch-site.sh status` 로 확인 |
| v1 실습 (로컬 전용) | `http://localhost:8081/` | SSH 포워딩 필요 |
| **v2 재구축 (로컬 전용)** | `http://localhost:8082/` | SSH 포워딩 필요. 공개 버전과 무관하게 항상 열림 |

로컬 포트는 `127.0.0.1` 로만 listen 하므로 사내망에서도 직접 닿지 않습니다. 보려면 SSH 포트 포워딩을 씁니다.

```bash
ssh -L 8081:127.0.0.1:8081 -L 8082:127.0.0.1:8082 changkwon@VM
# 그다음 브라우저에서 http://localhost:8082/
```

### 버전 전환 · 상태 확인

```bash
sudo bash deploy/switch-site.sh status   # 지금 공개 중인 버전과 로컬 포트 상태
sudo bash deploy/switch-site.sh v1       # 도메인에 실습(취약) 사이트를 꽂는다
sudo bash deploy/switch-site.sh v2       # 도메인에 재구축 사이트를 꽂는다
```

`nginx -t` 가 실패하면 **바꾸기 전 링크 상태로 되돌리고 중단**합니다(reload 하지 않음).
옛 사이트 파일(`tbox`, `tbox-re`, `tbox.bak-1단계`)은 지우지 않고 `/etc/nginx/sites-available/legacy-1단계/` 로 옮겨 보존합니다.

### 설치 후 확인

공개 80 포트에 무엇이 꽂혀 있든 v2 를 확인할 수 있도록, 아래는 **v2 로컬 포트 8082** 기준입니다.

```bash
# 1) 애플리케이션이 살아 있나
curl -s http://127.0.0.1:8082/api/health                                   # {"ok":true}

# 2) 소스·설정·DB 파일이 URL 로 보이지 않나 (전부 404)
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8082/.env
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8082/src/Db.php
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8082/db/schema.sql
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8082/protected/x
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8082/foo.php

# 3) v1 실습 사이트 경유로도 보이지 않나 (404)
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8081/T-Box_Re/src/Db.php

# 4) 도메인 경유(v2 공개 중일 때): 200 + HSTS + CSP
curl -s -D - -o /dev/null https://changkwon.mso.so/api/health

# 5) 실제 접속 IP 가 로그에 남나 ($remote_addr "$realip_remote_addr" "$http_x_forwarded_for" …)
sudo tail -5 /var/log/nginx/tbox-v2.access.log

# 6) 테이블 6개
sudo -u postgres psql -d tbox_re -c '\dt'

# 7) 감사 로그는 앱 계정이 지울 수 없나 (tbox_app 에 UPDATE/DELETE 가 없어야 한다)
sudo -u postgres psql -d tbox_re -c '\dp audit_logs'

# 8) 금지 패턴 정적 검사 (0건이어야 통과)
bash scripts/security_lint.sh
```

`.env` 는 `deploy/install.sh` 가 만들고 **커밋하지 않습니다**(`.gitignore`). 키 목록은 `.env.example` 을 보세요.

## 백업 · 복구 절차

> 9단계에서 채움. 절차 초안은 `ARCHITECTURE.md` 11.1~11.2, `PRD.md` 7.8.

## 보안 자체점검

각 단계가 끝나면 `PRD.md` 13장의 "직접 해볼 공격"을 수행하고, 결과를 `SECURITY.md` 7장 검토 이력에 기록합니다.
정적 점검: `bash scripts/security_lint.sh`
