# T-Box_Re — 사내 파일 공유 서비스 t-box 재구축

교육용으로 일부러 취약하게 만든 t-box(해킹 실습 대상)를, **실습에서 뚫린 5가지 취약점이 없는 형태**로 처음부터 다시 만드는 과제 저장소입니다.

| 항목 | 내용 |
|---|---|
| 스택 | 순수 PHP 8.1 (프레임워크 없음) · PostgreSQL 14 · nginx 1.18 · Ubuntu 22.04 — 실습 VM 설치본 그대로 (운영 전환 시 업그레이드, SECURITY R-21) |
| 진행 방식 | AI와 기능 하나씩 → **내가 직접 공격해 검증** → 한글 커밋 |
| 상태 | 1~10단계 구현·Fable 검토 완료 (2026-09-11) — 사용자 자기 공격(관문 ③) 일괄 수행 대기, 순서는 `STAGES.md` 2-1. 방화벽·SSH 강화 적용(`deploy/hardening.sh --apply`)·WAL·원격 로그는 미적용(운영 결정) |

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
             hardening.sh(기준선 적용 — 기본 출력만) · cron.d/tbox-v2 · logrotate.d/tbox-v2
             postgresql/archive.conf.example · rsyslog/tbox-v2.conf.example   (예시만, 미적용)
scripts/     security_lint.sh · seed_users.php
             backup_db.sh · backup_files.sh · verify_backup.sh · restore.sh   (백업·복구)
             purge_trash.php · consistency_check.php · audit_chain.php · alerts.php · audit_cli.php  (운영 배치)
             check_baseline.sh (서버 기준선 점검, 읽기 전용) · lib/cli_bootstrap.php
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
php-fpm 풀(`/etc/php/8.1/fpm/pool.d/tbox.conf`), nginx 사이트 파일 설치와 로컬 포트 enable,
그리고 9단계 운영 자리 — 읽기 전용 백업 역할 `tbox_backup`, 백업 키 `/etc/tbox-v2/backup.key`,
백업 디렉터리 `/var/backups/tbox-v2`(0700 root), cron 일정 `/etc/cron.d/tbox-v2`, logrotate 설정.
**도메인에 공개 중인 버전은 바꾸지 않습니다** — 그건 2)의 몫입니다. (TLS 인증서는 만들지 않습니다. 아래 "왜 80 포트인가" 참고.)

**2) `switch-site.sh`** 는 80 포트에 꽂을 버전을 고릅니다. `v1` / `v2` / `v3` / `status`.

**이미 돌고 있는 DB 에는 마이그레이션이 따로 필요합니다.** `install.sh` 는 표가 이미 있으면 `schema.sql` 을 건너뛰므로,
나중에 더해진 컬럼은 `db/migrations/*.sql` 로 들어옵니다. `install.sh` 가 무엇이 빠졌는지 알려 주기만 하고
**자동으로 적용하지는 않습니다**(운영 DB 에 DDL 을 몰래 거는 스크립트를 만들지 않습니다).

```bash
sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-10_audit_summary.sql  # audit_logs.summary (8단계)
sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-11_purged_at.sql      # files.purged_at  (9단계)
```

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

# 9) 운영(9단계) 자리가 갖춰졌나 — 백업 키·역할·일정
sudo ls -ld /var/backups/tbox-v2                      # drwx------ root root
sudo ls -l  /etc/tbox-v2/backup.key                   # -rw------- root root
cat /etc/cron.d/tbox-v2                               # 일정 7줄
sudo -u postgres psql -d tbox_re -c '\du tbox_backup' # 읽기 전용 백업 역할
sudo bash scripts/check_baseline.sh                   # 서버 기준선 점검 (T-17~T-19, 읽기 전용)
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

> 10단계부터 **Guest 계정에는 만료일이 함께 들어갑니다**(생성 시점 + 90일, `SECURITY.md` R-12).
> 만료된 뒤에는 비밀번호가 맞아도 로그인할 수 없습니다.
>
> 🔴 **11단계 패치 뒤 첫 로그인에는 비밀번호 변경 · 2단계 인증 등록이 강제됩니다**
> (`PRD.md` F-16). 패치 시점에 살아 있던 계정 전부(시드 5개 · 검토 5개 포함)가 대상이며,
> 마이그레이션 `db/migrations/2026-09-11_users_first_login.sql` 이 플래그만 켭니다 —
> 계정을 지우거나 다시 만들지 않습니다. 그래서 위 표의 비밀번호로 로그인하면
> 곧바로 「비밀번호를 변경해 주세요」 화면이 뜨고, 그 절차를 마치기 전에는 다른 기능이
> 전부 **403** 입니다(서버 관문이 막습니다. 화면만 되살려도 소용없습니다).

```bash
sudo cat /etc/tbox-v2/seed-accounts.txt      # 한 줄에 "아이디 역할 비밀번호"
```

#### 🔴 시드 비밀번호 파일은 **쓰고 나면 지웁니다** (`SECURITY.md` R-33)

`/etc/tbox-v2/seed-accounts.txt` 는 **평문 비밀번호**가 그대로 들어 있는 파일입니다.
`0600 root` 라 일반 사용자는 못 읽지만, root 를 얻은 침입자에게는 계정 전부가 한 번에 넘어갑니다.
그래서 이 파일의 수명을 아래처럼 정합니다.

| 순서 | 할 일 | 명령 |
|---|---|---|
| 1 | 계정 주인이 **첫 로그인** | 브라우저에서 `/` |
| 2 | 화면 **「내 계정 → 비밀번호 바꾸기」** 로 각자 비밀번호 변경 (12자 이상) | `POST /api/me/password` |
| 3 | 전원이 바꾼 것을 확인 — `password_changed_at` 이 채워졌으면 본인이 바꾼 것입니다 (11단계 F-16) | 아래 SQL |
| 4 | **파일 삭제** | `sudo shred -u /etc/tbox-v2/seed-accounts.txt` |

```bash
# 3) 아직 최초(또는 임시) 비밀번호를 쓰고 있는 계정 — password_changed_at 이 비어 있습니다
sudo -u postgres psql -d tbox_re -c \
  "SELECT username, role, must_change_password, mfa_setup_required, password_changed_at
     FROM users WHERE is_active AND password_changed_at IS NULL ORDER BY id"

# 4) 전원이 바꿨으면 지웁니다 (되돌릴 수 없습니다)
sudo shred -u /etc/tbox-v2/seed-accounts.txt
```

> 과제 기간에는 관문 ③(사용자 자기 공격)에서 시드 계정으로 로그인해야 하므로 **파일을 남겨 둡니다**
> — 위험을 알고 받아들인 상태입니다(`SECURITY.md` R-33). 운영 배포에서는 위 4단계를 반드시 끝냅니다.
> 비밀번호를 잃어버린 계정은 파일을 되살릴 수 없으므로, Admin 이 계정 관리 화면에서
> **새 계정을 만들어 넘기거나** `psql` 로 해시를 교체합니다.

## 계정 관리 (10단계 · `PRD.md` F-10)

Admin 만 `/users` 화면과 `GET/POST /api/users`, `PATCH /api/users/{uid}` 를 쓸 수 있습니다.
다른 역할은 화면도 API 도 **403** 이고, 그 시도 자체가 `authz.route|deny` 로 감사 로그에 남습니다.

| 규칙 | 내용 |
|---|---|
| 1인 1개 | 계정은 사람마다 하나입니다. 공용 계정·시드 계정을 실무에 쓰지 않습니다 — 감사 로그의 「누가」는 계정이 사람에 1:1 로 묶일 때만 성립합니다 (`PRD.md` 3장 · S-07) |
| 사유 필수 | 계정 생성·역할 변경·비활성화·재활성화에는 **사유(`reason_text`, 500자)가 필수**입니다. 없으면 400 이고, 적은 사유는 감사 로그 `detail.reason_text` 에 남습니다 (S-07 "관리자 행동 사유", R-20) |
| 비밀번호 | **Admin 이 비밀번호를 정하지 않습니다**(11단계 F-16). 서버가 임시 비밀번호 16자를 만들어 **생성 응답에 딱 한 번** 돌려주고, 저장은 Argon2id 해시뿐입니다. 요청에 `password` 키를 넣으면 400 입니다. 감사 로그에는 값이 아니라 `detail.temp_password_issued: true` 만 남습니다 |
| 첫 로그인 | 새 계정은 **비밀번호 변경 → 2단계 인증 등록**을 마쳐야 다른 기능을 쓸 수 있습니다. 마치기 전의 모든 요청은 403 `{"next":"password_change"\|"mfa_setup"}` 이고 `auth.gate|deny` 로 남습니다 |
| 재발급 · 초기화 | 비밀번호를 잊었거나 인증 앱이 든 휴대폰을 잃으면 Admin 이 **임시 비밀번호 재발급**·**2단계 인증 초기화**를 합니다. 둘 다 **사유 필수 · 자기 자신 금지**이고, 대상의 **모든 세션이 끊깁니다** |
| 역할 | `Admin`·`Manager`·`Employee`·`Guest` 넷뿐입니다. 그 밖의 값은 400 입니다 |
| Guest 만료 | **Guest 계정은 만료일이 필수**입니다(`SECURITY.md` R-12). 만료된 뒤에는 비밀번호가 맞아도 로그인할 수 없고 `user.expired` 가 남습니다. DB CHECK 가 마지막 그물입니다 |
| 자기 자신 | Admin 도 **자기 역할·활성 상태는 바꿀 수 없습니다**(`self_change`). 자기 계정의 비밀번호·2단계 인증은 「내 계정」에서 합니다 |
| 마지막 관리자 | **마지막 활성 Admin** 은 강등·비활성화되지 않습니다(`last_admin`). 관리자가 0명이 되면 아무도 되돌릴 수 없습니다 |
| 전 기기 로그아웃 | 역할·팀을 바꾸거나 계정을 끄면 **그 사람의 모든 세션이 즉시 삭제**됩니다 (`PRD.md` T-22, `SECURITY.md` R-10). 끊은 개수는 `detail.sessions_revoked` 에 남습니다 |
| 삭제 없음 | 계정을 지우는 기능은 없습니다. 파일·감사 로그가 계정을 가리키고 있어 지우면 추적이 끊어집니다 — 그만 쓸 계정은 **비활성화**합니다 |

```bash
# 목록 (Admin 세션 필요. 비밀번호 해시·TOTP 비밀은 응답에 없습니다)
curl -s -b jar https://changkwon.mso.so/api/users

# 계정 만들기 — 비밀번호는 보내지 않습니다. 201 응답의 temp_password 가 한 번만 나옵니다
curl -s -b jar -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  --data-binary '{"username":"hong.gildong","role":"Employee",
                  "team_id":1,"reason_text":"2026-09-11 입사 (인사팀 요청)"}' \
  https://changkwon.mso.so/api/users
# → {"id":25,"username":"hong.gildong",…,"temp_password":"…16자…"}
#   이 값은 **다시 볼 수 없습니다.** 안전한 경로로 본인에게만 전달하고, 본인은 첫 로그인에서 바꿉니다.

# 임시 비밀번호 재발급 · 2단계 인증 초기화 (사유 필수 · 자기 자신 금지 · 대상 세션 전부 종료)
curl -s -b jar -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  --data-binary '{"reason_text":"본인 확인 후 비밀번호 분실 재발급 (2026-09-11)"}' \
  https://changkwon.mso.so/api/users/25/password/reset
curl -s -b jar -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  --data-binary '{"reason_text":"휴대폰 분실 — 인증 앱 재등록 필요 (2026-09-11)"}' \
  https://changkwon.mso.so/api/users/25/mfa/reset

# 역할 변경 · 비활성화 (사유 필수)
curl -s -b jar -X PATCH -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  --data-binary '{"role":"Manager","reason_text":"팀장 발령 (2026-09-11)"}' \
  https://changkwon.mso.so/api/users/12
curl -s -b jar -X PATCH -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  --data-binary '{"is_active":false,"reason_text":"퇴사 (2026-09-30)"}' \
  https://changkwon.mso.so/api/users/12
```

### 삭제된 파일 복구 — 휴지통 (10단계 · F-11)

삭제는 **논리 삭제**이고 30일 동안 되돌릴 수 있습니다. Admin 은 홈 화면의 「휴지통」 패널
또는 API 로 복구합니다. 30일이 지나 배치(`scripts/purge_trash.php`)가 실제 파일을 지운 뒤에는
목록에 나오지 않고 복구도 되지 않습니다(`purged_at` 이 채워진 행).

```bash
curl -s -b jar https://changkwon.mso.so/api/files/trash
curl -s -b jar -X POST -H 'Content-Type: application/json' -H "X-CSRF-Token: $TOKEN" \
  https://changkwon.mso.so/api/files/<파일UUID>/restore
```

## 2단계 인증 (TOTP) — 10단계 · `SECURITY.md` R-05

비밀번호 하나만으로는 Admin 계정이 털리면 급여·인사 자료가 통째로 넘어갑니다.
그래서 **2단계 인증(TOTP, RFC 6238)은 모든 역할에 필수입니다** (11단계 사용자 결정 · `PRD.md` F-16 ③).

| 언제 | 무슨 일이 일어나나 |
|---|---|
| 새 계정의 첫 로그인 | ① 임시 비밀번호로 로그인 → ② **비밀번호 변경** → ③ **2단계 인증 등록**. ②③ 을 마치기 전에는 홈 화면이 그 절차만 보여 주고, 서버는 다른 요청을 403 `{"next":…}` 으로 막습니다 |
| 기존 계정 (패치 뒤 첫 로그인) | 같습니다. 이미 2단계 인증을 켜 둔 계정은 ③ 을 건너뜁니다(`mfa_setup_required = NOT mfa_enabled`) |
| 2단계 인증을 끄면 | 끌 수는 있지만 **다음 요청부터 다시 등록을 요구**합니다(`mfa_setup_required` 가 다시 켜집니다). "끄기" 는 비밀 키를 갈아 끼우는 길이지 면제가 아닙니다 |
| 인증 앱을 잃으면 | 본인은 로그인할 수 없습니다. Admin 이 **2단계 인증 초기화**(사유 필수)를 하면 다음 로그인 뒤 다시 등록합니다 |

| 항목 | 값 |
|---|---|
| 방식 | TOTP (HMAC-SHA1 · 30초 · 6자리 · 허용 오차 ±1스텝) |
| 인증 앱 | Google Authenticator · Aegis · 1Password 등 표준 TOTP 앱이면 모두 |
| 구현 | `src/Totp.php` — 외부 라이브러리 없이 `hash_hmac('sha1')` 로 직접 (공급망 최소화, S-10-C) |
| 비밀 보관 | `users.mfa_secret`(base32). **켜져 있을 때만** 값이 있고, 끄면 `NULL` 로 지웁니다 |
| 화면 | QR 라이브러리를 쓰지 않으므로 비밀 키와 `otpauth://` 주소를 **글자로** 보여 줍니다 — 인증 앱에 손으로 입력합니다 |

### 코드는 어디서 나오나요 (인증 앱)

6자리 코드는 서버가 보내 주는 것이 아니라 **스마트폰 인증 앱이 30초마다 스스로 만들어 냅니다.**
앱과 서버가 같은 비밀 키와 같은 시계를 보고 각자 계산하기 때문에, 네트워크도 문자메시지도 필요 없습니다.

켜는 순서 (홈 화면 「내 계정」)

1. 스마트폰에 **인증 앱**을 설치합니다 — Google Authenticator · Microsoft Authenticator ·
   Aegis · 1Password 중 아무거나 됩니다.
2. 홈 화면에서 **「2단계 인증 켜기」** — 서버가 비밀 키와 `otpauth://` 주소를 보여 줍니다.
   이때는 아직 **켜지지 않았습니다**. 비밀 키는 **다시 보여 주지 않으므로 지금 옮겨 적습니다.**
3. 앱에서 **「수동 입력」(설정 키 입력)** 을 고르고, 계정 이름 `t-box:아이디` · 키는 화면의 비밀 키를
   그대로 넣습니다. 종류는 **시간 기반(Time based) · 6자리** 입니다.
   스마트폰에서 이 화면을 보고 있다면 **「인증 앱에 바로 추가 (otpauth 링크)」** 를 눌러도 됩니다.
4. 앱에 뜬 6자리 코드를 **「확인하고 켜기」** 에 적습니다.
   코드가 맞아야 켜집니다 — 등록에 실패한 사람이 자기 계정에 못 들어가는 일을 막기 위해서입니다.
5. 그 뒤 로그인은 **두 걸음**이 됩니다 — 먼저 아이디·비밀번호만 넣고 「로그인」,
   그러면 **그때 코드 칸이 나타나므로** 인증 앱의 6자리를 넣고 다시 「로그인」 합니다.
   (2단계 인증을 켜지 않은 계정에는 코드 칸이 아예 나타나지 않습니다. 비밀번호가 틀리면
   코드 칸도 나오지 않습니다 — 비밀번호를 모르는 사람은 그 계정의 2단계 인증 여부를 알 수 없습니다.)

### 인증 앱 없이 코드 구하기 (`scripts/totp_code.php`) — 검토·시험용

스마트폰을 못 쓰는 상황(서버에서 관리자가 직접 시험할 때)에서는 같은 계산을 서버에서 합니다.
화면에 나온 비밀 키를 그대로 넣으면 **지금의 코드 한 개와 남은 초**를 찍습니다.

```bash
# 사용법 (인자 없이 실행하면 사용법을 내고 종료 코드 2)
sudo php /var/www/tbox-v2/scripts/totp_code.php

# 지금의 코드 (비밀은 화면 「2단계 인증 켜기」 가 보여 준 값)
sudo php /var/www/tbox-v2/scripts/totp_code.php JBSWY3DPEHPK3PXP
# 282760
# 남은 시간: 17초 (30초마다 바뀝니다)
```

> ⚠️ **비밀 키를 어디에도 남기지 마세요.** 이 명령은 비밀을 인자로 받으므로
> 셸 히스토리(`~/.bash_history`)에 그대로 남습니다 — 명령 앞에 **공백 한 칸**을 두고 실행하거나
> (`HISTCONTROL=ignorespace`), 실행 뒤 해당 줄을 지웁니다.
> 비밀을 스크립트·설정 파일·메모장에 적어 두면 2단계 인증의 의미가 사라집니다.
> 이 스크립트 자체는 DB 에 붙지 않고 감사 로그도 쓰지 않습니다(비밀·코드를 기록하지 않기 위해).
> 계산은 화면·로그인과 **같은 `src/Totp.php`** 가 하므로 값이 어긋날 일이 없습니다.

끄려면 **현재 비밀번호와 지금의 코드**가 모두 필요합니다 — 세션만 훔친 사람이 2단계 인증을
꺼 버리지 못하게 하기 위해서입니다.

> 코드가 없거나 틀리면 응답은 로그인 실패와 **똑같은 401 문구**입니다
> ("이 계정은 2단계 인증을 쓴다" 를 알려 주지 않습니다). 사유는 감사 로그에만
> `auth.mfa|deny|code_required`·`bad_code` 로 남고, **실패 5회면 계정이 10분 잠깁니다**
> — 비밀번호를 알아낸 공격자가 코드 6자리를 무제한으로 시험하지 못하게 하는 장치입니다.
>
> **인증 앱을 잃어버렸다면** 스스로 끌 수 없습니다. Admin 이 `psql` 로 되돌립니다:
> `sudo -u postgres psql -d tbox_re -c "UPDATE users SET mfa_enabled=false, mfa_secret=NULL WHERE username='아이디'"`
> (이 작업은 감사 로그에 남지 않으므로, 누가 왜 했는지 운영 기록에 따로 남깁니다.)

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

## 운영 — 백업 · 복구 · 감시

이 절은 `PRD.md` 7.8(S-08 백업과 복구) · 7.11(S-11 감시)과 `ARCHITECTURE.md` 11장을 **실제로 돌아가는 명령**으로 옮긴 것입니다.
9단계(2026-09-11)에서 구현하고 이 VM 에서 한 번씩 실행해 확인했습니다.

### 자동 일정 (`/etc/cron.d/tbox-v2`)

`deploy/install.sh` 가 `deploy/cron.d/tbox-v2` 를 복사합니다. 실행 계정은 **root** 입니다 — 백업 키(`0600 root`)와
백업 디렉터리(`0700 root`)를 읽어야 하기 때문입니다. DB 에는 여전히 `.env` 의 `tbox_app` 계정으로 붙습니다.

표의 시각은 **서버 로컬 시각 = KST** 입니다(아래 「서버 시간대」).

| 시각 | 스크립트 | 하는 일 | 남는 기록 |
|---|---|---|---|
| 03:00 | `scripts/backup_db.sh` | `pg_dump -Fc` → 파이프로 바로 `gpg AES256` → `.sha256`. 보관 일 7 · 주 4 · 월 6 | `backup.run allow\|error` |
| 03:30 | `scripts/backup_files.sh` | `tar -cz \| gpg` 전체 사본 + `rsync -a --delete` 로컬 미러. 보관 7 | `backup.run` |
| 04:00 | `scripts/purge_trash.php` | 30일 지난 휴지통 파일의 **바이트만** 삭제, `files.purged_at` 기록(행은 남김) | `file.purge allow\|error` |
| 04:30 | `scripts/verify_backup.sh` | 최신 산출물 `sha256sum -c` + gpg 복호화 시험(`pg_restore -l`·`tar -tz` 목록만) | `audit.integrity`, 2회 연속 실패 → `alert.raised AL-8` |
| 00:05 | `scripts/audit_chain.php` | `audit.log` 일별 sha256 해시 체인 | `audit.integrity` (`chain_prev`·`chain_hash`) |
| 일 02:00 | `scripts/consistency_check.php` | DB ↔ 저장소 정합성(누락·고아·sha256) | `audit.integrity`, 불일치 → `alert.raised AL-6` |
| 5분마다 | `scripts/alerts.php` | 경보 규칙 AL-1·AL-2·AL-5·AL-7·AL-10·AL-12 판정 | `/var/log/tbox-v2/alerts.log` + `alert.raised` |

```bash
cat /etc/cron.d/tbox-v2                 # 일정 확인 (cron.d 는 6번째 칸이 실행 계정이다)
sudo bash scripts/backup_db.sh          # 손으로 한 번 돌려보기
sudo bash scripts/verify_backup.sh
sudo php  scripts/consistency_check.php
sudo php  scripts/alerts.php --dry-run  # 판정만 하고 경보는 남기지 않는다
```

### 서버 시간대 — KST 로 통일 (2026-09-11)

VM 은 UTC 로 설치돼 있었고 감사 로그·`date`·cron 이 모두 UTC 로 보여 사용자가 읽기 어려웠습니다.
세 층을 **같은 값**으로 맞췄습니다. 하나만 바꾸면 화면과 로그의 시각이 9시간 어긋나 보이니 항상 함께 바꿉니다.

| 층 | 설정 | 확인 |
|---|---|---|
| OS (`date`, cron, auth.log, nginx 로그) | `sudo timedatectl set-timezone Asia/Seoul` → `cron`·`rsyslog` 재시작, `nginx` reload | `timedatectl` → `Time zone: Asia/Seoul (KST, +0900)` |
| PostgreSQL (`now()` 표시, 날짜 필터 해석, 서버 로그) | `ALTER SYSTEM SET timezone='Asia/Seoul'; ALTER SYSTEM SET log_timezone='Asia/Seoul'; SELECT pg_reload_conf();` (`postgresql.auto.conf` 에 기록돼 `postgresql.conf` 의 `Etc/UTC` 를 덮음) | `psql -Atc "show timezone"` |
| PHP (`date()`, 경보 AL-7 업무시간 판정) | `/etc/php/8.1/{fpm,cli}/conf.d/99-tbox-timezone.ini` = `date.timezone = Asia/Seoul` (사본 `deploy/php/99-tbox-timezone.ini`) → `php8.1-fpm` 재시작 | `php -r 'echo date("c");'` → `+09:00` |

**저장된 데이터는 바뀌지 않습니다.** 모든 시각 컬럼이 `TIMESTAMPTZ`(절대 시각)이고 앱은 DB `now()` 와
오프셋이 붙은 `date('c')` 만 쓰므로, 바뀐 것은 표시·해석입니다. 같은 사건이 전에는 `07:15+00`, 지금은 `16:15+09` 로 보입니다.
감사 로그 화면에서 날짜 필터 `2026-09-11` 은 이제 **한국 시간 하루**를 뜻합니다.
cron 일정(03:00 백업 등)도 KST 로 돕니다.

### 산출물이 어디에 있나

| 무엇 | 경로 | 권한 |
|---|---|---|
| DB 백업 | `/var/backups/tbox-v2/db/db-YYYYMMDD-HHMM.dump.gpg` (+ `.sha256`) | 0600 root |
| 주간·월간 사본 | `/var/backups/tbox-v2/{weekly,monthly}/` | 0600 root |
| 파일 백업 | `/var/backups/tbox-v2/files/files-YYYYMMDD.tar.gz.gpg` (+ `.sha256`) | 0600 root |
| 파일 로컬 미러 | `/var/backups/tbox-v2/files-mirror/` | 0700 root |
| 암호화 키 | `/etc/tbox-v2/backup.key` | 0600 root |

백업 디렉터리는 `0700 root` 이고 **웹 루트·업로드 디렉터리 밖**입니다 (`PRD.md` S-08 백업 보안).
앱 계정 `tbox-php` 는 여기를 읽지도 못합니다 — 앱이 뚫려도 "정리된 전체 데이터 사본"에는 닿지 않습니다.

### 🔑 백업 키 관리 (`SECURITY.md` R-08, `PRD.md` S-10-E)

> **키를 잃으면 모든 백업이 쓸모없는 바이트 덩어리가 됩니다.** 복구 수단이 통째로 사라집니다.

- 키는 `/etc/tbox-v2/backup.key`(0600 root) 한 곳에 있습니다. `deploy/install.sh` 가 `openssl rand -hex 32` 로 만들고,
  **이미 있으면 절대 바꾸지 않습니다** (바꾸면 그 전 백업을 못 엽니다).
- **사본을 서버 밖에 2인 보관하세요.** 사내 비밀 관리 도구(Vault/Bitwarden)에 넣고, 파일·메일·메신저로 돌리지 않습니다.
  서버가 죽어서 복구하는 상황인데 키가 그 서버에만 있으면 아무것도 못 합니다.
- 키는 백업과 **다른 장소**에 둡니다. 같은 디렉터리에 두면 백업을 가져간 사람이 키도 함께 가져갑니다.
- 연 1회 회전합니다 (`PRD.md` S-11-B). 회전하면 **옛 키도 보관 기간 동안 함께 보관**해야 옛 백업을 열 수 있습니다.

```bash
sudo cat /etc/tbox-v2/backup.key        # 값 보기 (화면·히스토리에 남지 않게 주의)
sudo gpg --batch --decrypt --passphrase-file /etc/tbox-v2/backup.key \
     /var/backups/tbox-v2/db/db-YYYYMMDD-HHMM.dump.gpg | pg_restore -l | head   # 키가 맞는지 확인
```

### 복구 절차 (`ARCHITECTURE.md` 11.2 · `PRD.md` 7.8)

`scripts/restore.sh` 가 아래 순서를 그대로 수행합니다. **산출물은 이름만** 넘깁니다(경로를 받지 않습니다).

```bash
# 0) 무엇이 있는지 본다
sudo ls -l /var/backups/tbox-v2/db /var/backups/tbox-v2/files

# 1) 파일만 복구 (실수로 지운 업로드 되돌리기)
sudo bash scripts/restore.sh --files files-20260911.tar.gz.gpg

# 2) DB 복구 — 리허설은 반드시 별도 DB 에서 (운영 DB 에 --clean 은 되돌릴 수 없다)
sudo -u postgres createdb -O tbox_owner tbox_re_restore
sudo bash scripts/restore.sh --db db-20260911-0441.dump.gpg --dbname tbox_re_restore
sudo -u postgres psql -d tbox_re_restore -c '\dt'          # 표 6개
sudo -u postgres dropdb tbox_re_restore

# 3) 진짜 사고로 운영 DB 를 되돌려야 할 때만 (서비스를 멈추고)
sudo bash scripts/restore.sh --db db-20260911-0441.dump.gpg \
     --dbname tbox_re --yes-production --stop-service

# 4) ★ 운영 DB 를 복구한 뒤에는 최소 권한을 다시 건다 — 덤프는 --no-privileges 로 떠서
#    복구된 표에 tbox_app · tbox_backup 권한(ACL)이 비어 있다 (9단계 검토 실측: 복구 DB \dp 공란).
#    이 단계를 빼면 앱이 DB 에 아무것도 못 하거나(권한 없음), 반대로 PUBLIC 기본 권한이 남는다.
sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/grants.sql
sudo -u postgres psql -d tbox_re -c '\dp audit_logs'      # tbox_app=ar / tbox_backup=r 이어야 한다
sudo systemctl start php8.1-fpm                             # --stop-service 를 썼다면
```

`restore.sh` 가 하는 일 — `ARCHITECTURE.md` 11.2 의 번호와 같습니다.

| # | 하는 일 | 왜 |
|---|---|---|
| 1 | (`--stop-service`) php-fpm 정지 | 복구 중 새 데이터가 섞이지 않게 |
| 3 | `sha256sum -c` 로 산출물 무결성 확인 | 깨진 백업으로 덮는 것은 아무것도 안 하는 것보다 나쁘다 |
| 4 | `gpg -d \| pg_restore --clean --if-exists` (역할 `tbox_owner`) | 평문 덤프를 디스크에 남기지 않는다 |
| 5 | `gpg -d \| tar -xz` → 소유·권한 재설정(0750/0640 `tbox-php`) | `stored_name` 기준 복원 (`PRD.md` 7.8 5단계) |
| 6 | `php scripts/consistency_check.php --strict` | DB 레코드 ↔ 실제 파일 ↔ sha256 |
| 8 | `backup.restore allow` 감사 기록 (누가 = `$SUDO_USER`) | 복구 자체도 기록 대상 |

7단계 **스모크 테스트**(로그인 → 목록 → 업로드 → 다운로드 → 권한 매트릭스)는 사람이 합니다 — 아래 "로그인 확인" 절의 명령을 씁니다.

**복구 리허설 실측 (T-15, 2026-09-11)**

| 항목 | 값 |
|---|---|
| 범위 | DB 백업 + 파일 백업 → 파일 1개의 저장 바이트 삭제(사고 재현) → 파일 복구 → 별도 DB 로 DB 복구 → 정합성 검사 |
| 데이터 규모 | `files` 113행 · 저장소 93개 파일 132MB · DB 9.8MB |
| **총 소요 시간** | **321초 (5분 21초)** — RTO 4시간 대비 약 2.2% |
| 내역 | 파일 복구 3초 / DB 복구 1초 / 나머지는 백업 생성·검증·확인 |
| 결과 | 정합성 검사 통과(불일치 0), 복구된 파일의 sha256 이 DB 기록과 일치 |

> 리허설은 **분기 1회** 합니다 (`PRD.md` S-11-B). 데이터가 커지면 시간도 늘어나므로 매번 다시 재고, RTO 4시간 안에 드는지 확인합니다.

### 지금 지켜지지 않는 것 — 운영 전환 전에 해결할 항목

거짓으로 "안전"이라고 적지 않기 위해 여기에 모아 둡니다. 근거는 `deploy/DEPENDENCIES.md` 5.3, `SECURITY.md`.

| 항목 | 문서가 요구하는 것 | 지금 상태 | 그래서 |
|---|---|---|---|
| RPO | 15분 (WAL 아카이브) | **미적용** — PostgreSQL 재시작 필요 + 원격 저장소 미결(Q-4) | **실제 RPO 는 일 1회(최대 24시간)**. 예시 설정: `deploy/postgresql/archive.conf.example` |
| 3-2-1 원칙 | 사본 3 · 매체 2 · 원격 1 | **원격 사본 없음** — 전부 같은 디스크 | 서버가 통째로 죽으면 복구 불가. Q-4 결정 후 원격 전송 추가 |
| 원격 로그 | 감사 로그 실시간 원격 전송 | **미적용** — 대상 서버 없음 | 대체: `chattr +a` + `scripts/audit_chain.php` 해시 체인. root 를 잃으면 무력 (R-11) |
| 방화벽 | 인·아웃바운드 기본 거부 | **ufw inactive** | 적용은 사람이 콘솔에서: `sudo bash deploy/hardening.sh --apply` |
| SSH | 키 인증만 · root 금지 | **비밀번호 인증 켜짐** | 위와 같음. 적용 전 공개키 로그인이 되는지 먼저 확인 |
| 알림 채널 | 경보를 담당자에게 전송 | **없음** — 로그에만 남음 | `.env` 의 `ALERT_COMMAND` 는 자리만. `alerts.log` 와 감사 로그를 사람이 본다 |

### 서버 기준선 점검 (T-17 ~ T-19)

```bash
sudo bash scripts/check_baseline.sh      # 읽기 전용. 아무것도 바꾸지 않는다
sudo bash deploy/hardening.sh            # 무엇을 바꿀지 출력만 (dry-run)
sudo bash deploy/hardening.sh --apply    # 실제 적용 — ★ 콘솔에서 사람이 직접
```

> `--apply` 는 **원격 SSH 세션을 끊을 수 있습니다.** `ufw enable` 은 기본 거부 상태로 켜지고,
> `PasswordAuthentication no` 는 공개키를 등록하지 않은 계정을 잠급니다.
> 적용 전에 ① 공개키 로그인이 되는지 ② VM 콘솔로 들어갈 방법이 있는지 ③ 관리망 대역이 맞는지 확인하세요.

## 감사 로그 보는 법

Admin 만 볼 수 있습니다 (`PRD.md` Q-8). 화면은 `/audit`, API 는 `GET /api/audit` 이며 **조회한 것도 기록됩니다**(`audit.view`).

### 육하원칙 6필터

| 질문 | 파라미터 | 예 |
|---|---|---|
| 누가 | `actor` (`actor_name` 또는 `login_ref`) | `?actor=employee01` |
| 언제 | `from` · `to` | `?from=2026-09-11&to=2026-09-12` |
| 어디서 | `ip` | `?ip=10.0.1.23` |
| 무엇을 | `target` (파일명·계정명 부분 일치) · `level` | `?target=급여&level=admin-only` |
| 어떻게 | `action` | `?action=file.download` |
| 왜 | `result` · `reason` · `granted_by` | `?result=deny&reason=role_not_permitted` |

### ★ 로그인 실패는 「누가」로 찾을 수 없습니다

`auth.login` 은 세션이 만들어지기 **전**이라 `actor_name` 이 비어 있습니다 (`ARCHITECTURE.md` 7.3).
입력된 아이디는 **`target_name`** 에 남습니다. 그래서 "이 계정으로 로그인 실패가 몇 번 있었나" 는
「누가」가 아니라 **「무엇을(target)」 필터에 계정명**을 넣어 찾습니다.

```bash
# 화면·API
curl -s -b jar 'http://127.0.0.1:8082/api/audit?action=auth.login&result=deny&target=employee01'

# psql 로 직접
sudo -u postgres psql -d tbox_re -c "
  SELECT happened_at, target_name, ip, reason, summary
    FROM audit_logs
   WHERE action='auth.login' AND result='deny'
   ORDER BY id DESC LIMIT 20"
```

같은 이유로 **운영 배치(9단계)** 행도 `actor_*` 가 비어 있습니다 — cron 이 남긴 것이라 사람이 없습니다.
`action` 으로 고릅니다: `file.purge` · `backup.run` · `backup.restore` · `audit.integrity` · `alert.raised`.
(`user_agent` 가 `cli:스크립트명`, `detail.route` 가 `cli:스크립트명` 입니다.)

### ★ 내 행동과 AI 검토 행동 가르기

AI(Fable·Opus)의 검토 요청은 **두 겹**으로 표시됩니다 (`PRD.md` S-07 "검토 요청 표식").

1. 계정이 `qa_` 로 시작합니다 (`qa_admin01`·`qa_manager01`·`qa_employee01`·`qa_employee02`·`qa_guest01`)
2. `User-Agent` 가 `tbox-review/<날짜>` 입니다

둘 다 아니면 사용자(또는 침입자)의 행동입니다.

```sql
-- 사용자 행동만 보기 (검토 계정·검토 UA 제외)
SELECT happened_at, actor_name, ip, action, result, summary
  FROM audit_logs
 WHERE COALESCE(actor_name, target_name) NOT LIKE 'qa\_%'
   AND COALESCE(user_agent, '') NOT LIKE 'tbox-review/%'
 ORDER BY id DESC LIMIT 20;
```

```bash
# 파일 사본을 그냥 읽어도 됩니다 — 한 줄 JSON 이고 마지막 키가 한국어 요약입니다
sudo tail -5 /var/log/tbox-v2/audit.log | python3 -c 'import sys,json;[print(json.loads(l)["summary"]) for l in sys.stdin]'
sudo tail -5 /var/log/tbox-v2/alerts.log     # 경보
```

### 감사 로그를 지울 수 없게 만든 두 겹

| 어디 | 어떻게 | 확인 |
|---|---|---|
| DB `audit_logs` | 앱 계정에 `SELECT, INSERT` 만 (`UPDATE`·`DELETE`·`TRUNCATE` 없음) | `sudo -u postgres psql -d tbox_re -c '\dp audit_logs'` → `tbox_app=ar` |
| 파일 `audit.log` | `chattr +a` (추가만 가능, 해제는 root) + logrotate 365일 | `sudo lsattr /var/log/tbox-v2/audit.log` → `a` |
| 지워졌는지 알기 | 일별 sha256 해시 체인 | `sudo php scripts/audit_chain.php --verify` |

### 개인정보 취급 (`PRD.md` S-07 "개인정보 취급")

감사 로그의 **`ip` 와 `user_agent` 는 개인정보**입니다. 다음 세 가지를 지킵니다.

| 항목 | 규칙 |
|---|---|
| 목적 제한 | **보안 사고 조사와 감사에만** 씁니다. 근태 확인·업무 감시 등 다른 목적으로 조회하지 않습니다 |
| 접근 제한 | 조회는 **Admin 만** 가능하고 (`PRD.md` Q-8), 조회한 사실 자체가 `audit.view` 로 남습니다 |
| 보존 기간 | **1년**. DB 는 운영 정책에 따라, 파일은 `logrotate rotate 365` 로 자동 삭제됩니다 |

로그에 절대 넣지 않는 것: 비밀번호, 세션 토큰·쿠키, 파일 본문 (`src/Audit.php` 의 `scrub()` 이 비밀 키 이름을 `[제거됨]` 으로 바꿉니다).
파일 내용은 남기지 않고 `detail.sha256` 으로만 가리킵니다.

## 검토 계정 (`qa_*`) — 운영 배포에서는 만들지 않습니다

`scripts/seed_users.php` 는 기본으로 시드 계정 5개와 **검토 계정 5개**(`qa_` 접두)를 함께 만듭니다.
검토 계정은 AI 검토(관문 ②)가 남기는 행을 사용자의 행동과 갈라 놓기 위한 것이며, **과제 환경 전용**입니다.

```bash
php scripts/seed_users.php --no-qa      # 검토 계정을 만들지 않는다 (운영 배포)

# 이미 만들어진 검토 계정을 막으려면: 지우지 말고 **비활성화**합니다.
# users 를 지우면 files·audit_logs 의 참조가 깨집니다 (감사 추적이 끊어집니다).
sudo -u postgres psql -d tbox_re -c "UPDATE users SET is_active=false WHERE username LIKE 'qa\_%'"
sudo -u postgres psql -d tbox_re -c "DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'qa\_%')"
```

## 보안 자체점검

각 단계가 끝나면 `PRD.md` 13장의 "직접 해볼 공격"을 수행하고, 결과를 `SECURITY.md` 7장 검토 이력에 기록합니다.
정적 점검: `bash scripts/security_lint.sh`
