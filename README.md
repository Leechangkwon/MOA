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
.env.example 설정 키 예시 (실제 설정은 저장소 루트 .env — .gitignore 로 Git 제외)
```

## 설치 · 실행

실습 VM(Ubuntu 22.04 · PHP 8.1 · PostgreSQL 14 · nginx 1.18) 기준입니다. 설정 내용은 `ARCHITECTURE.md` 3장 배포 메모와 8·9·10장 그대로입니다.

```bash
sudo bash deploy/install.sh          # 1) 계정·디렉터리·DB·설정 파일(저장소 루트 .env)·php-fpm 풀·nginx 사이트 파일
sudo bash deploy/switch-site.sh v2   # 2) 80 포트(도메인)에 v2 를 꽂는다
```

**1) `install.sh`** 는 한 번에 다 합니다 — 전용 계정 `tbox-php`, 웹 루트 밖 디렉터리(`/var/lib/tbox-v2`, `/var/log/tbox-v2`, `/etc/tbox-v2`),
PostgreSQL 역할·DB·스키마·최소 권한, 설정 파일 `.env`(저장소 루트) 생성(`APP_SECRET` 포함), **시드 계정 5개**,
php-fpm 풀(`/etc/php/8.1/fpm/pool.d/tbox.conf`), nginx 사이트 파일 설치와 로컬 포트 enable.
**도메인에 공개 중인 버전은 바꾸지 않습니다** — 그건 2)의 몫입니다. (TLS 인증서는 만들지 않습니다. 아래 "왜 80 포트인가" 참고.)

**2) `switch-site.sh`** 는 80 포트에 꽂을 버전을 고릅니다. `v1` / `v2` / `v3` / `status`.

**멱등합니다.** 두 스크립트 모두 여러 번 실행해도 안전하고, 이미 있는 저장소 루트 `.env` 의 비밀번호는 바꾸지 않습니다.
운영 전환 검토용으로 옮겨 두었던 `/etc/tbox-v2/tbox.env` 가 남아 있으면 값을 그대로 보존해 저장소 루트 `.env` 로 **되돌려 옮깁니다**(`mv`).
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
| **v2 보안 보완 (이 저장소)** | `/var/www/tbox-v2` | `tbox-v2` | `127.0.0.1:8082` | `tbox_re` | `tbox` (tbox-php) |
| v3 새 T-BOX (미착수) | `/var/www/tbox-v3` | `tbox-v3` | `127.0.0.1:8083` | 별도 | 별도 |

- 실제 접속 IP 는 `set_real_ip_from 192.168.104.100` + `real_ip_header X-Forwarded-For` 로 복원합니다 — **프록시 IP 가 보낸 헤더만** 신뢰하므로 다른 곳에서 온 위조 헤더는 무시됩니다 (`SECURITY.md` R-30·R-31).
- HSTS(`S-09-1`)는 공개용 `tbox-v2` 에만 붙습니다. 로컬 8082(평문)에 붙이면 접속이 불가능해집니다.
- v2 저장소는 `/var/www/tbox-v2` 로, v1 웹 루트(`/var/www/html`) 밖입니다 — v1 경유로 v2 의 소스·`.env`·SQL 에 닿는 URL 이 없습니다 (`SECURITY.md` R-29).

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

> **저장소 위치를 옮기면** nginx `root`·php-fpm `open_basedir` 가 옛 경로를 가리켜 사이트가 멈춥니다(503). 옮긴 직후 `sudo bash deploy/install.sh && sudo bash deploy/switch-site.sh v2` 를 다시 실행하세요. 스크립트는 자기 위치에서 저장소 경로를 계산합니다.

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

# 3) v1 실습 사이트가 여전히 살아 있나 (200) — v2 저장소는 v1 웹 루트 밖이라 경유 노출 없음
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8081/index.php

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

#### 설정 파일 — 저장소 루트 `.env` (`PRD.md` S-09-6)

실제 설정은 저장소 루트 `.env` 한 곳에만 있습니다. `public/` 밖이라 URL 로 보이지 않고, `.gitignore` 의 `.env` 줄로 Git 에는 들어가지 않습니다.
소유 `changkwon:tbox-php`, 권한 `0640` — 개발자(`changkwon`)는 읽고 고치고, 앱 계정(`tbox-php`)은 그룹으로 **읽기만** 해 자기 설정을 고쳐 쓸 수 없으며, 그 외 계정은 읽지도 못합니다.
`deploy/install.sh` 가 만들고 소유·권한을 매번 맞춥니다. 키 목록은 저장소의 `.env.example` 을 보세요(값 없음, 이것만 커밋).
(운영 전환 시 `/etc/tbox-v2/tbox.env`(`root:tbox-php`)로 이전 검토 — 심층 방어. `PRD.md` S-09-6)

```bash
ls -l .env                           # -rw-r----- changkwon tbox-php  이어야 합니다 (앱 그룹 밖이면 sudo)
cat .env                             # 값 보기
nano .env                            # 값 고치기
sudo systemctl reload php8.1-fpm     # 고친 뒤 반영
```

`APP_SECRET`(CSRF 토큰 HMAC 키)이 없으면 설치 스크립트가 `openssl rand -hex 32` 로 만들어 덧붙입니다 — 기존 값은 바꾸지 않습니다.

### 시드 계정 (2단계)

`install.sh` 는 `users` 가 **비어 있을 때만** `scripts/seed_users.php` 를 실행해 아래 5계정을 만듭니다.
비밀번호는 계정마다 무작위 16자로 생성되며, **저장소·README 에는 적지 않습니다**(`PRD.md` S-09-6).
평문은 `/etc/tbox-v2/seed-accounts.txt`(`0600 root`) 한 곳에만 있습니다.

| 아이디 | 역할 | 팀 |
|---|---|---|
| `admin01` | Admin | — |
| `manager01` | Manager | 개발팀 |
| `employee01` | Employee | 개발팀 |
| `employee02` | Employee | 영업팀 |
| `guest01` | Guest | — |

```bash
sudo cat /etc/tbox-v2/seed-accounts.txt      # 한 줄에 "아이디 역할 비밀번호"
```

계정이 이미 있으면 스크립트는 `건너뜀` 만 출력하고 아무것도 바꾸지 않습니다(비밀번호 파일도 그대로 둡니다).
비밀번호를 잃어버렸다면 `sudo -u postgres psql -d tbox_re -c 'DELETE FROM users'` 후 재설치가 아니라,
10단계 계정 관리(F-10)나 `psql` 로 해시를 직접 교체하세요 — `users` 를 지우면 파일·감사 로그의 참조가 깨집니다.

### 로그인 확인 (2단계)

```bash
# 1) 로그인 없이 API → 401 / 화면 → 302 /login
curl -s -o /dev/null -w '%{http_code}\n' https://changkwon.mso.so/api/me          # 401
curl -s -D - -o /dev/null https://changkwon.mso.so/ | grep -i '^location'           # /login

# 2) 로그인 (비밀번호는 파일에서 셸 변수로만 읽습니다 — 화면·히스토리에 남기지 않기 위해)
P=$(sudo awk '$1=="employee01"{print $NF}' /etc/tbox-v2/seed-accounts.txt)
curl -s -c jar -X POST -H 'Content-Type: application/json' \
     --data-binary "$(printf '{"username":"employee01","password":"%s"}' "$P")" \
     https://changkwon.mso.so/api/login                                             # {"ok":true}

# 3) 내 정보 + CSRF 토큰 (쿠키는 HttpOnly·Secure·SameSite=Lax, 만료 없음 — 수명은 DB 가 판단)
curl -s -b jar https://changkwon.mso.so/api/me

# 4) CSRF 헤더 없이 로그아웃 → 403
curl -s -b jar -X POST https://changkwon.mso.so/api/logout                          # 권한이 없습니다
```

## 백업 · 복구 절차

> 9단계에서 채움. 절차 초안은 `ARCHITECTURE.md` 11.1~11.2, `PRD.md` 7.8.

## 보안 자체점검

각 단계가 끝나면 `PRD.md` 13장의 "직접 해볼 공격"을 수행하고, 결과를 `SECURITY.md` 7장 검토 이력에 기록합니다.
정적 점검: `bash scripts/security_lint.sh`
