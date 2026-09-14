# deploy/DEPENDENCIES.md — 이 앱이 의존하는 것 전부 (PRD S-10-C, CLAUDE.md 7장)

버전은 **실습 VM 에서 실측한 값**이다. 기억이나 문서가 아니라 명령 출력이 근거다.
측정일: 2026-09-09 (1단계) · 경로 갱신 2026-09-10 (4단계, `-v2` 분리 반영) · 운영 도구 추가 2026-09-11 (9단계)

## 1. 실측 버전

| 구성요소 | 버전 (실측) | 확인 명령 |
|---|---|---|
| OS | Ubuntu 22.04.5 LTS | `lsb_release -ds` |
| PHP (FPM/CLI) | 8.1.2-1ubuntu2.25 | `php -v` |
| PostgreSQL | 14.24 (Ubuntu 14.24-0ubuntu0.22.04.1) | `psql --version` |
| nginx | 1.18.0 (Ubuntu) | `nginx -v` |
| GnuPG (`gpg`) | 2.2.27 (libgcrypt 1.9.4) | `gpg --version` |
| rsync | 3.2.7 (protocol 31) | `rsync --version` |
| tar (GNU) | 1.34 | `tar --version` |
| cron | Vixie cron (Ubuntu `cron` 패키지, 서비스 active) | `systemctl is-active cron` |
| systemd-timesyncd | systemd 249 (NTP 동기화 active) | `timedatectl` |

## 2. 지원 종료(EOL) 와 그에 따른 위험

| 구성요소 | 지원 종료 | 남은 기간(2026-09-09 기준) | 판단 |
|---|---|---|---|
| PHP 8.1 | **2025-12-31 (이미 종료)** | 없음 — 이미 지났다 | ❗ 위험 **수용**: 실습 VM 설치본을 그대로 쓴다 (PRD Q-11, SECURITY R-21). 운영 전환 시 8.4 로 업그레이드가 **필수 조건**이다 |
| PostgreSQL 14 | 2026-11-12 | 약 2개월 | ◐ 1년 미만. 9단계(운영) 전에 15 이상 업그레이드 계획을 세운다 |
| nginx 1.18 (Ubuntu 22.04 패키지) | 2027-04 (Ubuntu 22.04 표준 지원 종료와 동일) | 약 1년 7개월 | ◐ 배포판 보안 패치로 유지된다 |
| Ubuntu 22.04 LTS | 2027-04 (표준 지원) | 약 1년 7개월 | ◐ ESM 은 2032-04 |

> PRD S-10-C 는 "EOL 까지 2년 이상" 을 기준으로 한다. 위 표의 어느 항목도 그 기준을 만족하지 않는다.
> 이는 **실습 VM 이라는 전제** 아래 의도적으로 수용한 위험이며, `SECURITY.md` R-21 에 기록돼 있다.
> 운영 환경에 올릴 때는 Ubuntu 24.04 + PHP 8.3/8.4 + PostgreSQL 16 조합으로 다시 세운다.

## 3. PHP 확장

| 확장 | 필요 이유 | 상태 (2026-09-09 실측) |
|---|---|---|
| `pdo_pgsql`, `pgsql` | DB 접속 (`src/Db.php`) | ✅ 설치됨 |
| `json` | API 응답·감사 로그 (`src/Http.php`, `src/Audit.php`) | ✅ 설치됨 (PHP 8 내장) |
| `hash`, `openssl` | 세션 토큰 해시, `random_bytes` | ✅ 설치됨 |
| `fileinfo` | 업로드 MIME 판별 (6단계, PRD S-06) | ✅ 설치됨 |
| `sodium` | Argon2id 비밀번호 해시 (2단계, PRD S-09-4) | ✅ 설치됨 |
| `php8.1-curl` | F-14 "URL 로 파일 가져오기" (`src/Fetch.php`) | ✅ **설치됨 (7단계, 2026-09-11 실측)** — `php8.1-curl 8.1.2-1ubuntu2.25`, libcurl 7.81.0. 확인: `php -m \| grep -x curl`, `dpkg -l php8.1-curl`. `deploy/install.sh` 1절 패키지 확인 목록에 포함 |
| `php8.1-zip` | **F-18 미리보기** — zip 항목 목록·office(docx·pptx·xlsx·hwpx) 텍스트 발췌 (`src/Preview.php`). **중앙 디렉터리와 지정한 멤버만** 읽고 압축을 풀지 않는다 (ARCH 5.8, SECURITY R-42) | ✅ **설치됨 (12-2, 2026-09-12 실측)** — `php8.1-zip 8.1.2-1ubuntu2.26`, ext zip 1.19.5 / libzip 1.7.3. 확인: `php -m \| grep -x zip`, `dpkg -l php8.1-zip`. 배포판(Ubuntu 22.04) 패키지라 보안 패치 경로·EOL 이 PHP 8.1 과 같다 (PRD S-10-C) |
| `php8.1-mbstring` | 파일명·설명 문자열 처리 | ⏳ **미설치 — 7단계 전 설치 예정** (`apt-get install php8.1-mbstring`) |
| `iconv` (PHP 코어 내장, 8.1 기본 로드) | 파일명·설명의 문자 수 계산·자르기 (`iconv_strlen`/`iconv_substr`) — mbstring 미설치 동안의 대체. 3단계 도입(2026-09-09) | ✅ 설치됨 (별도 패키지 없음). mbstring 설치 후에도 그대로 써도 됨 |

확인: `php -m`

## 4. 외부 라이브러리 · 패키지 매니저

**없다.** Composer 를 쓰지 않고, 서드파티 PHP 라이브러리를 한 개도 넣지 않는다.
프론트엔드도 CDN·npm 없이 순수 CSS/JS 다 (CSP `default-src 'self'` 이므로 외부 자원은 애초에 차단된다).

| 왜 | 근거 |
|---|---|
| 공급망 공격 표면을 0 으로 유지 | PRD S-10-C |
| 업데이트 추적 대상이 OS 패키지 4개뿐 | 운영 부담 최소화 |

PostgreSQL 확장도 설치하지 않는다 — UUID 는 PostgreSQL 13+ 내장 `gen_random_uuid()` 로 충분하므로 `pgcrypto` 가 필요 없다.

## 5. 시스템 계정 · 경로

| 항목 | 값 | 권한 |
|---|---|---|
| php-fpm 계정 | `tbox-php` (system, nologin, 홈 없음) | — |
| php-fpm 소켓 | `/run/php/php8.1-fpm-tbox.sock` | 0660 www-data:www-data |
| 업로드 저장소 | `/var/lib/tbox-v2/storage` | 0750 tbox-php |
| PHP 세션(예비) | `/var/lib/tbox-v2/sessions` | 0700 tbox-php |
| 로그 | `/var/log/tbox-v2/{audit,app}.log` (audit.log 는 `chattr +a`) | 0640 tbox-php:adm |
| 설정(저장소 밖) | `/etc/tbox-v2/owner.pgpass`, `/etc/tbox-v2/seed-accounts.txt` | 0600 root |
| 백업 키 (9단계) | `/etc/tbox-v2/backup.key` — gpg 대칭키. **잃으면 백업 전체를 못 연다**(R-08) | 0600 root |
| 백업 계정 비밀번호 (9단계) | `/etc/tbox-v2/backup.pgpass` (`tbox_backup`, 읽기 전용) | 0600 root |
| 백업 산출물 (9단계) | `/var/backups/tbox-v2/{db,files,weekly,monthly,files-mirror}` — 웹 루트·업로드 밖 | 0700 root |
| 경보 로그 (9단계) | `/var/log/tbox-v2/alerts.log` | 0640 tbox-php:adm |
| 경보 상태·해시 체인 (9단계) | `/var/lib/tbox-v2/alerts-state.json`, `/var/lib/tbox-v2/audit-chain.txt` | 0600 root |
| cron 일정 (9단계) | `/etc/cron.d/tbox-v2` (`deploy/cron.d/tbox-v2` 복사, 실행 계정 root) | 0644 root |
| TLS | `/etc/ssl/tbox/{fullchain,privkey}.pem` | privkey 0640 root:www-data |
| DB 역할 | `tbox_owner`(소유), `tbox_app`(런타임 최소 권한), `tbox_backup`(**SELECT 만**, 9단계) | `db/grants.sql` |

### 5.1 쓰는 외부 도구 (배포판 기본 패키지, 4단계 추가 · 9단계 확장)

감사 로그 **파일 사본**을 보호하기 위해 두 도구를 쓴다 (ARCH 7.1 표, PRD S-07 "파일 사본 보호", SECURITY R-11).
둘 다 Ubuntu 22.04 기본 설치본에 이미 들어 있다 — 새로 설치하는 패키지가 아니다(공급망 표면 증가 없음, PRD S-10-C).

| 도구 | 패키지 | 쓰는 곳 | 왜 필요한가 | 확인 명령 |
|---|---|---|---|---|
| `chattr` · `lsattr` | `e2fsprogs` (기본 설치) | `deploy/install.sh` 2절, `deploy/logrotate.d/tbox-v2` 의 pre/postrotate | `audit.log` 에 `+a`(추가 전용) 를 걸어 앱·침입자가 **덧붙이기만** 하게 한다. 해제는 root 만 | `lsattr /var/log/tbox-v2/audit.log` 에 `a` |
| `logrotate` | `logrotate` (기본 설치) | `deploy/install.sh` 6절이 `deploy/logrotate.d/tbox-v2` → `/etc/logrotate.d/tbox-v2` 로 복사 | 감사 로그를 일 단위로 회전하며 **365일 보존**(PRD S-07). 회전 전후로 `+a` 를 풀고 다시 건다 | `logrotate -d /etc/logrotate.d/tbox-v2` 오류 0 |

> 파일시스템이 `+a` 를 지원하지 않으면(overlayfs 등) `install.sh` 는 멈추지 않고 경고만 남긴다 —
> 보호가 걸리지 않은 사실이 설치 출력과 `lsattr` 에 드러나는 편이, 걸린 줄 알고 넘어가는 것보다 안전하다.

### 5.2 운영(백업·경보) 도구 — 9단계 추가 (2026-09-11)

전부 Ubuntu 22.04 기본 설치본에 이미 있다. **새로 설치한 패키지는 0개**다 (PRD S-10-C).

| 도구 | 패키지 | 쓰는 곳 | 왜 필요한가 | 확인 명령 |
|---|---|---|---|---|
| `gpg` | `gnupg` (기본) | `scripts/backup_db.sh`·`backup_files.sh`·`verify_backup.sh`·`restore.sh` | 백업 산출물을 **저장 전에** AES-256 대칭키로 암호화한다 (PRD S-08 "백업 보안"). 키는 `/etc/tbox-v2/backup.key` 0600 root | `gpg --version` |
| `tar` | `tar` (기본) | `backup_files.sh`·`restore.sh` | 업로드 저장소 전체를 한 스트림으로 묶어 gpg 에 바로 파이프한다(평문 중간 파일 없음) | `tar --version` |
| `rsync` | `rsync` (기본) | `backup_files.sh`·`restore.sh` | 로컬 미러(`files-mirror/`) 동기화 — 파일 하나를 실수로 지웠을 때 즉시 되돌리는 자리 | `rsync --version` |
| `pg_dump`/`pg_restore` | `postgresql-client-14` (DB 와 함께 설치됨) | `backup_db.sh`·`verify_backup.sh`·`restore.sh` | DB 전체 백업(`-Fc`)과 복구·목록 검증 | `pg_dump --version` |
| `cron` | `cron` (기본, 서비스 active) | `/etc/cron.d/tbox-v2` (`deploy/cron.d/tbox-v2` 를 install.sh 가 복사) | 백업·정리·검증·정합성·체인·경보 일정 (ARCH 11.1) | `cat /etc/cron.d/tbox-v2` |
| `systemd-timesyncd` | `systemd` (기본, NTP active) | 시각 동기화 | 감사 로그 시각 정합성 (PRD S-10-A) | `timedatectl` |
| `openssl` | `openssl` (기본) | `deploy/install.sh` | 백업 키·DB 비밀번호 난수 생성 | `openssl version` |
| `sha256sum` | `coreutils` (기본) | 백업 체크섬 | PRD S-08 "백업마다 SHA-256 체크섬 기록, 매일 검증" | `sha256sum --version` |

### 5.3 문서가 요구했지만 **쓰지 않기로 한** 도구 (9단계 결정, 2026-09-11)

PRD S-10-C 가 "배포판 패키지만·의존성 0 이 기본" 이라고 못박고 있어, 문서가 예시로 든 도구라도
실습 VM 에 없으면 **설치하지 않고 대체**했다. 결정과 대체 수단, 남은 위험을 함께 적는다.

| 문서가 든 도구 | 어디에 | 결정 | 대체 수단 | 남은 위험 |
|---|---|---|---|---|
| `age` | PRD S-08 "암호화(`age`/GPG)" | **미사용** (미설치) | `gpg --symmetric --cipher-algo AES256` | 없음 — 문서가 GPG 를 나란히 허용한다 |
| `restic` | PRD S-08 "증분 백업(`restic`/`rsync`)" | **미사용** (미설치) | `tar` 전체 + `rsync --delete` 로컬 미러, 보관 7개 | 중복 제거가 없어 **디스크를 더 쓴다**. 저장소가 커지면 재검토 |
| `chrony` | PRD S-10-A "시간 동기화: `chrony` 필수" | **미사용** (미설치) | `systemd-timesyncd` (동기화 active 실측) | 낮음 — 동기화 자체는 되고 있다. `chronyc tracking` 대신 `timedatectl` 로 확인 |
| `fail2ban` | PRD S-10-A "SSH ... `fail2ban`" | **미적용** (미설치) | sshd `MaxAuthTries 3`(hardening.sh), 앱 로그인 잠금 5회/10분(S-09-4), nginx 로그인 속도 제한 | ◐ SSH 브루트포스를 **차단**하지는 못한다. 운영 전환 조건 |
| `ClamAV` | PRD S-10-A·S-11-B | **미포함** (Q-3 결정 2026-09-11) | `files.scan_status` 컬럼과 차단 자리만 유지 | ◐ R-02 유지 |
| `AIDE`/`debsums` | ARCH 11.3 "무결성 감시 → AL-9" | **미적용** | `scripts/check_baseline.sh` 수동 점검 | ◐ AL-9 자동 탐지 없음 |
| `rsyslog` TLS 전송 | PRD S-10-E, ARCH 11.4 | **미적용** — 보낼 서버가 없다 | 예시 설정 `deploy/rsyslog/tbox-v2.conf.example` + `chattr +a` + `scripts/audit_chain.php` 해시 체인 | ❗ root 를 잃으면 감사 로그를 믿을 수 없다 (R-11). 운영 전환 조건 |
| PostgreSQL WAL 아카이브 | PRD S-08 RPO 15분 | **미적용** — 재시작 필요 + 원격 저장소 미결(Q-4) | 예시 설정 `deploy/postgresql/archive.conf.example` | ❗ **실제 RPO 는 일 1회(최대 24시간)** 다. 운영 전환 조건 |

### 5.4 검토 도구 (**런타임 아님** — 서버가 돌아가는 데 필요하지 않다, 25단계 추가)

구매 화면의 시각 결함(잘림·겹침·같은 줄 높이·빈 공백)을 **눈이 아니라 측정**으로 잡기 위해
25단계에서 `scripts/ui_snapshot.sh`·`ui_snapshot.py` 를 두었다. 검토할 때만 돌린다.

| 도구 | 설치 위치 | 어디에 | 왜 | 확인 |
|---|---|---|---|---|
| `python3-playwright` (chromium 채널) | **사용자 로컬 `~/.local`** — 배포판 패키지가 아니며 `deploy/install.sh` 가 설치하지 않는다 | `scripts/ui_snapshot.py` (`scripts/ui_snapshot.sh` 가 부른다) | 검토 계정(qa_admin01)으로 구매 13개 화면을 실제로 열어 스크린샷을 찍고 DOM 결함을 센다 (PRD 12.2 T-50) | `PATH="$HOME/.local/bin:$PATH" python3 -c "import playwright"` |

- **운영 서버에는 넣지 않는다.** 앱 코드가 이 패키지를 부르는 곳이 한 곳도 없고(런타임 의존성 0),
  없으면 `ui_snapshot.sh` 만 실패한다 — 서비스는 그대로다 (PRD S-10-C 「의존성 0 이 기본」).
- 브라우저는 headless_shell 대신 **정식 chromium 빌드**를 쓴다(디스크 절약 — shell 을 따로 받지 않는다).

## 6. 갱신 규칙

- 패키지·확장·외부 도구를 **하나라도** 추가하면 이 파일을 같은 커밋에서 갱신한다 (CLAUDE.md 7장).
- 버전을 올리면: EOL 확인 → 스테이징에서 T-1~T-23 재실행 → 운영. "작년에 통과했으니 안전" 은 근거가 아니다.
- 분기마다 이 표의 EOL 을 다시 확인한다 (PRD S-11-B 운영 캘린더).
