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
| ClamAV (`clamscan`·`freshclam`, 데몬 없음) | 1.5.3+dfsg-0ubuntu0.22.04.2 (2026-09-16 설치, 시그니처 main 63 · daily 28124 · bytecode 339) | `clamscan --version` · `sigtool --info /var/lib/clamav/daily.cvd` |

## 2. 지원 종료(EOL) 와 그에 따른 위험

| 구성요소 | 지원 종료 | 남은 기간(2026-09-09 기준) | 판단 |
|---|---|---|---|
| PHP 8.1 | **2025-12-31 (이미 종료)** | 없음 — 이미 지났다 | ❗ 위험 **수용**: 실습 VM 설치본을 그대로 쓴다 (PRD Q-11, SECURITY R-21). 운영 전환 시 8.4 로 업그레이드가 **필수 조건**이다 |
| PostgreSQL 14 | 2026-11-12 | 약 2개월 | ◐ 1년 미만. 9단계(운영) 전에 15 이상 업그레이드 계획을 세운다 |
| nginx 1.18 (Ubuntu 22.04 패키지) | 2027-04 (Ubuntu 22.04 표준 지원 종료와 동일) | 약 1년 7개월 | ◐ 배포판 보안 패치로 유지된다 |
| ClamAV 1.5 (Ubuntu 22.04 패키지) | 2027-04 (배포판 지원과 동일; 상류 1.5 LTS 는 2028) | 약 1년 7개월 | ◐ 배포판 보안 패치. 시그니처는 `clamav-freshclam` 이 매일 갱신(R-87) |
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

**서버는 0 이다.** Composer 를 쓰지 않고, 서드파티 PHP 라이브러리를 한 개도 넣지 않는다.
패키지 매니저(npm·Composer)도 이 VM 에 없고, node 도 설치하지 않는다.

| 왜 | 근거 |
|---|---|
| 공급망 공격 표면을 0 으로 유지 | PRD S-10-C |
| 업데이트 추적 대상이 OS 패키지 4개뿐 | 운영 부담 최소화 |

> 지문·얼굴 로그인(T:F-72 패스키 · WebAuthn, 2026-09-27)도 **외부 코드 0** 을 지킨다 — 서버 검증은 `src/Passkey.php` 가 PHP 기본 `openssl`·`hash` 로 직접 하고(CBOR 부분집합 · authData · COSE → 공개 키 · `openssl_verify`), 브라우저 쪽은 표준 `navigator.credentials` 만 부른다(`public/assets/passkey.js`). WebAuthn 라이브러리(Composer)·`gmp`·`cbor` 확장을 넣지 않았다(지시서 1.5).

### 4.1 브라우저에서 도는 외부 코드 — ProseMirror (커뮤니티 3단계, 2026-09-22)

**의존성 0 원칙의 첫 예외**다 (사용자 결정 Q-6 · D-1, 커뮤니티 PRD 12장). 노션식 블록 에디터를
자체 구현하는 대신 ProseMirror 를 들이되, 아래 조건을 전부 건다 — 커뮤니티 `C:S-12`.

| 조건 | 어떻게 지키나 |
|---|---|
| 고정 버전 | 아래 표의 버전 그대로. 범위 표기(`^`·`~`)가 들어갈 자리가 없다(`package.json` 자체가 없다) |
| 저장소 안 고정 | `public/assets/community/vendor/pm/<패키지>.js` — **CDN 0**, CSP 는 `script-src 'self'` 그대로 |
| 무결성 | tarball 의 sha256 을 아래 표에 적고, `bash scripts/vendor_prosemirror.sh --verify` 가 **다시 받아 대조**한다. 산출물도 바이트까지 견준다 — 단, 커뮤니티 핫픽스(2026-09-24) 뒤로는 배포 때 `scripts/community_asset_version.php` 가 파일 안 import 에 버전 표시(`?v=…`)를 찍으므로, `--verify` 는 **그 표시만 지운 뒤** 바이트를 견준다(표시 외의 한 바이트라도 다르면 실패) |
| 서버 실행 0 | 서버(PHP)는 이 파일들을 **열지도 않는다**. 브라우저에만 나가는 정적 자산이다 |
| node 0 | 번들러를 쓰지 않는다. 각 패키지의 ESM 진입 파일 하나를 꺼내 **bare import 를 상대 경로로 바꿔** 두고, 브라우저가 서로를 불러 쓰게 한다(import map 도 쓰지 않는다 — 인라인 스크립트가 필요해 CSP 에 걸린다) |
| 라이선스 | 14개 전부 **MIT** |
| 후퇴 경로 | 저장 형식(블록 JSON)·검증기·렌더러는 전부 우리 것이다. 에디터를 걷어 내도 저장된 문서는 그대로 읽힌다 |

**다시 만드는 절차** (이 VM 에서 그대로 돌아간다 — 준비물은 `curl`·`tar`·`sha256sum` 뿐이다)

```
bash scripts/vendor_prosemirror.sh            # 받아서 vendor/pm 에 두고 아래 표를 갱신한다
bash scripts/vendor_prosemirror.sh --verify   # 받지만 쓰지 않는다 — 해시·산출물 일치만 본다
```

버전을 올릴 때는 스크립트 안의 고정 버전 목록과 커뮤니티 `ARCHITECTURE.md` 21.3 을 **함께** 고치고,
`--verify` 가 통과한 출력을 커밋 메시지에 붙인다.

<!-- PM-VENDOR-BEGIN -->
| 패키지 | 버전 | 라이선스 | tarball sha256 |
|---|---|---|---|
| `prosemirror-model` | 1.25.12 | MIT | `0ac7e5deed004b7f983ca295fa1e84b19f7ee38995e39dee9809d9a4ebd5c6c8` |
| `prosemirror-state` | 1.4.4 | MIT | `ad3eb60bd6db8e74b2576dc0b7c2800d6def362e0e990c9fb108fc4b724ee651` |
| `prosemirror-view` | 1.42.5 | MIT | `e6dd816d77e1a9f65962734bcfa520be51120ca37922905eec8454f0331300c1` |
| `prosemirror-transform` | 1.12.1 | MIT | `ff213c7ec902517c9013513c59e71a6a8ff892dd32f1e3f8dd37928c7baeb976` |
| `prosemirror-commands` | 1.7.2 | MIT | `7cca5b9379ff9f83d6bb54d69373e79715cad1f33d8b73d5cb604bc5e73e90d0` |
| `prosemirror-keymap` | 1.2.3 | MIT | `3131823ce73eaf3487cada22c85b76349da02c114dee3de696f4c1c3c645ca8c` |
| `prosemirror-history` | 1.5.0 | MIT | `5ffc66c053f3e2d4d6c1f7d5f352c4401fee49b075a5b1bb5b03e7b9d84172a7` |
| `prosemirror-inputrules` | 1.5.1 | MIT | `785502d7e5ce0b9617d1aa6c0ef2aa2a0a6b06a5d8efb8942eb7c1b2d3b155aa` |
| `prosemirror-dropcursor` | 1.8.3 | MIT | `cb91fb2971ed4b93a68eb6d036c0a1ae8f9211c465579a5ac107aa7bd09b97f6` |
| `prosemirror-gapcursor` | 1.4.1 | MIT | `961e88b82ce7f434cc781ba644a82810461cf9ba3993834f654384c493998eba` |
| `prosemirror-schema-list` | 1.5.1 | MIT | `c60638c4fd055328cebada5a36a661d454eb87114919bfc2bfbfbcda2b939c2e` |
| `orderedmap` | 2.1.1 | MIT | `9ad98abe6ac4156daef2232d7c1d2c8b3681545604c54f7f3754e791f12c168d` |
| `w3c-keyname` | 2.2.8 | MIT | `05d21484026d1f2dd842c843181e391e66d60f1d5dff292d562c3f40302dab36` |
| `rope-sequence` | 1.3.4 | MIT | `2a483ee02e5a21b59b63a29bca162e6aa2cd8fafb474261040c3d8538a1f067c` |
<!-- PM-VENDOR-END -->

PostgreSQL 확장도 설치하지 않는다 — UUID 는 PostgreSQL 13+ 내장 `gen_random_uuid()` 로 충분하므로 `pgcrypto` 가 필요 없다.

### 4.2 밖으로 나가는 서비스 — 웹 푸시 서버 세 곳 (F-70, 2026-09-24 · 지시서 L-4 · SECURITY R-99)

라이브러리가 아니라 **서버가 요청을 보내는 바깥 회사**다. 코드 의존성은 0 이다 — 암호화(RFC 8291)·서명(RFC 8292)은
PHP 내장 openssl·hash 로 `src/WebPush.php` 가, 보내기는 `src/Fetch.php` 의 `pushSend()` 가 한다(curl 은 그 파일에만).
허용 목록은 **코드 상수** `Fetch::PUSH_HOSTS` 이고 표 `fetch_hosts` 와 따로다(화면에서 넓힐 수 없다). 보내는 것은 cron 배치
`scripts/alert_push.php`(OS 계정 tbox-push) 하나뿐이고, 웹 요청 처리 중에는 한 번도 나가지 않는다.

| 서비스 | 호스트(정확 일치, 443 · https 만) | 누가 쓰나 | 보내는 것 | 그쪽에 보이는 것 |
|---|---|---|---|---|
| Google FCM (Web Push) | `fcm.googleapis.com` | 안드로이드 Chrome 의 받기 | 기기 키로 암호화한 내용(≤ 1KB) + VAPID 서명(JWT: `aud` 스킴+호스트 · `exp` 12시간 · `sub` = 사이트 주소) | 보낸 시각·횟수·크기·서버 IP·사이트 주소(`sub`). **내용은 못 읽는다**(RFC 8291) |
| Apple Web Push | `web.push.apple.com` | 아이폰 Safari(홈 화면 앱, iOS 16.4+) | 같음 | 같음 |
| Mozilla Push | `updates.push.services.mozilla.com` | Firefox | 같음 | 같음 |

- 빼는 것: Microsoft Edge(`*.notify.windows.com` — 하위 도메인이 바뀌어 정확 일치가 안 된다, L-4).
- 멈추면: 알림만 안 온다. 경보 자체는 `alerts.log`·감사·관리자 화면에 그대로 남는다(푸시는 덧붙은 전달 경로).
  24시간 동안 살아 있는 받기 전부가 실패하거나, 보내는 배치가 15분 넘게 돌지 못하면 경보 AL-22(system).
- 방화벽(아웃바운드 443 만 — S-05 ⑫)은 **없다** — L-8 결정으로 R-22 와 함께 따로 계획. 그동안 코드 잠금 한 겹(R-99).

## 5. 시스템 계정 · 경로

| 항목 | 값 | 권한 |
|---|---|---|
| php-fpm 계정 | `tbox-php` (system, nologin, 홈 없음) | — |
| php-fpm 소켓 | `/run/php/php8.1-fpm-tbox.sock` | 0660 www-data:www-data |
| 격리 디렉터리 (F-54) | `/var/lib/tbox-v2/quarantine` — 악성 판정 파일의 바이트를 옮겨 두는 곳(삭제 안 함, 증거) | 0750 tbox-php:tbox-php · `scripts/scan_pending.php`(cron, tbox-php)만 쓴다 |
| 검사 잠금 (F-54) | `/var/lib/tbox-v2/scan.lock` — 경로는 저장소(STORAGE_DIR)의 형제로 계산한다. 운영에서는 이 파일 그대로, 시험은 임시 저장소 옆(대기열 2e 242dece) | tbox-php · `flock` 로 동시 실행 방지 |
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
| DB 역할 | `tbox_owner`(소유), `tbox_app`(런타임 최소 권한), `tbox_backup`(**SELECT 만**, 9단계), `tbox_push`(F-70 보내는 배치 — 받기·대기표·`users` 세 칸·`audit_logs` INSERT 만, 문장 15s·유휴 트랜잭션 60s) | `db/grants.sql` · `db/migrations/2026-09-30_f70_push.sql` |
| 휴대폰 경보 보내는 계정 (F-70, L-3) | `tbox-push` (system, nologin, 홈 `/var/lib/tbox-push`, 무리 `tbox-push` 하나 — sudo·adm 없음). `/var/log/tbox-v2`·`/etc/tbox-v2` 에 **통과(x) ACL 만** — alerts.log 읽기 · app.log·audit.log·.env·다른 비밀 파일 못 읽음 | cron `alert_push.php` 매분 |
| 휴대폰 경보 설정·비밀 (F-70) | `/etc/tbox-v2/push.conf`(비밀 없음) · `/etc/tbox-v2/push.pgpass`(tbox_push 비밀번호) · `/etc/tbox-v2/vapid-private.pem`(VAPID 비밀 키) · `/etc/tbox-v2/vapid-public.txt`(공개 키 — 웹이 낸다) | root 0644 · tbox-push 0600 · tbox-push 0600 · root 0644 |
| 휴대폰 경보 상태·감사 사본 (F-70) | `/var/lib/tbox-push/alert-push-state.json`(읽기 위치·대기 — 받기 번호·개수만) · `/var/log/tbox-push/audit.log`(자기 감사 사본, 구현 기록 4) · `/var/log/tbox-push/error.log`(배치 오류 기록 — 설정 오류·예외, 값 없음) | 0700·0600 tbox-push · 0750·0640 tbox-push |

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
| `openssl` | `openssl` (기본) | `deploy/install.sh` · 반영 스크립트 `apply-f70-host` | 백업 키·DB 비밀번호 난수 생성 · **F-70 VAPID 키 한 쌍**(`openssl ecparam -name prime256v1` — 비밀 키는 파일로만) | `openssl version` |
| `setfacl`·`getfacl` | `acl` 2.3.1-1 — **설치됨 2026-09-25**(사용자 확정 d39, 배포판 패키지 `apt-get install acl`). F-70 반영에 필요 | 반영 스크립트 `apply-f70-host` · `deploy/install.sh` 6-3 | 보내는 계정 tbox-push 에게 `/var/log/tbox-v2`·`/etc/tbox-v2` **통과(x)만** 준다(`setfacl -m u:tbox-push:x`). adm 무리에 넣으면 auth.log·app.log 까지 읽혀 L-3 결정으로 뺐다. 없으면 반영 스크립트는 멈춘다(exit 1) — ACL 이진 형식을 손으로 쓰는 우회는 두지 않는다(구현 기록 9) | `getfacl -p /var/log/tbox-v2` 에 `user:tbox-push:--x` |
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
| `ClamAV` (`clamav` + `clamav-freshclam`, 배포판 패키지) | PRD S-10-A·S-11-B·F-54 | **포함** (Q-3 재결정 2026-09-16). `clamav-daemon` 은 설치하지 않는다(메모리, ARCH 5.28) | `scripts/scan_pending.php` 가 `clamscan` 실행, `clamav-freshclam` 데몬이 시그니처 갱신(아웃바운드 `database.clamav.net` HTTPS) | R-02 → ✅, R-27·R-87 |
| `AIDE`/`debsums` | ARCH 11.3 "무결성 감시 → AL-9" | **미적용** | `scripts/check_baseline.sh` 수동 점검 | ◐ AL-9 자동 탐지 없음 |
| `rsyslog` TLS 전송 | PRD S-10-E, ARCH 11.4 | **미적용** — 보낼 서버가 없다 | 예시 설정 `deploy/rsyslog/tbox-v2.conf.example` + `chattr +a` + `scripts/audit_chain.php` 해시 체인 | ❗ root 를 잃으면 감사 로그를 믿을 수 없다 (R-11). 운영 전환 조건 |
| PostgreSQL WAL 아카이브 | PRD S-08 RPO 15분 | **미적용** — 재시작 필요 + 원격 저장소 미결(Q-4) | 예시 설정 `deploy/postgresql/archive.conf.example` | ❗ **실제 RPO 는 일 1회(최대 24시간)** 다. 운영 전환 조건 |

### 5.4 검토 도구 (**런타임 아님** — 서버가 돌아가는 데 필요하지 않다, 25단계 추가)

구매 화면의 시각 결함(잘림·겹침·같은 줄 높이·빈 공백)을 **눈이 아니라 측정**으로 잡기 위해
25단계에서 `scripts/ui_snapshot.sh`·`ui_snapshot.py` 를 두었다. 검토할 때만 돌린다.

| 도구 | 설치 위치 | 어디에 | 왜 | 확인 |
|---|---|---|---|---|
| `python3-playwright` (chromium 채널) | **사용자 로컬 `~/.local`** — 배포판 패키지가 아니며 `deploy/install.sh` 가 설치하지 않는다 | `scripts/ui_snapshot.py` (`scripts/ui_snapshot.sh` 가 부른다) | 검토 계정(qa_admin01)으로 구매 13개 화면을 실제로 열어 스크린샷을 찍고 DOM 결함을 센다 (PRD 12.2 T-50) | `PATH="$HOME/.local/bin:$PATH" python3 -c "import playwright"` |
| `python3` `esprima` (pip, 순수 파이썬 4.0.1) | **사용자 로컬 `~/.local`** — 배포판 패키지가 아니며 `deploy/install.sh` 가 설치하지 않는다. **앱·배포는 부르지 않는다(검토 전용)** | 커뮤니티 3단계 구현·검토 — `public/assets/**/*.js` 를 구문 분석해 오타를 잡는다(이 VM 에 node 가 없어 `node --check` 를 쓸 수 없다) | 브라우저에서만 도는 JS 라 `php -l` 같은 관문이 없다. 파서 하나가 「괄호가 안 맞는 파일을 커밋」을 막는다 | `python3 -c "import esprima"` |
| `python3` `qrcode` (pip, 순수 파이썬) | **사용자 로컬 `~/.local`** — 배포판 패키지가 아니며 `deploy/install.sh` 가 설치하지 않는다. **앱·배포는 부르지 않는다(시험 전용 오라클)** | `tests/f51.sh` (a) | `src/Qr.php`(자체 QR 인코더, PRD F-51)가 만든 모듈 행렬을 독립 구현과 비트 단위로 대조한다 (PRD 12.2 T-63) | `python3 -c "import qrcode"` (2026-09-16 `pip install --user qrcode`) |

- **운영 서버에는 넣지 않는다.** 앱 코드가 이 패키지를 부르는 곳이 한 곳도 없고(런타임 의존성 0),
  없으면 `ui_snapshot.sh` 만 실패한다 — 서비스는 그대로다 (PRD S-10-C 「의존성 0 이 기본」).
- 브라우저는 headless_shell 대신 **정식 chromium 빌드**를 쓴다(디스크 절약 — shell 을 따로 받지 않는다).

### 5.5 PC 클라이언트 — Qaredi MCP 서버 `clients/mcp/` (Q:F-8 조각 C, 2026-09-28)

**PC 클라이언트 전용 · 서버에 설치하지 않는다.** 사용자의 PC 에서 Claude Desktop·Claude Code 가 띄우는 stdio 프로그램이고,
`deploy/install.sh`·운영 서버·앱 코드는 이 패키지를 부르지 않는다(서버 런타임 의존성 0 그대로 — PRD S-10-C).
HTTP 는 표준 라이브러리(`urllib` · `ssl.create_default_context()`)로 하고 외부 HTTP 라이브러리를 직접 쓰지 않는다.

| 패키지 | 버전(고정) | 라이선스 | sha256 | 어디에 | 왜 |
|---|---|---|---|---|---|
| `mcp` (PyPI, Model Context Protocol 공식 Python SDK) | `1.12.4` (`clients/mcp/pyproject.toml`) — **판단 대기**: 이 문맥에서 PyPI 가 막혀(403) 이 번호의 존재·최신 여부를 확인하지 못했다. 반영 전 확인해 고친다 | MIT (반영 전 받은 배포본의 메타데이터로 확인) | **이 문맥에서 받지 못함 — 반영 전 채운다** (`pip download mcp==<버전> --no-deps` 뒤 `sha256sum`) | `clients/mcp/qaredi_mcp/server.py` 한 곳(`mcp.server.fastmcp.FastMCP` · `ToolError` · `mcp.types.ToolAnnotations`) | stdio MCP 서버 틀(도구 등록·스키마·전송) |
| (`mcp` 가 끌어오는 것 — `pydantic` 등) | `mcp` 의 의존 선언을 따른다 | 각자 | 받지 못함 — 반영 전 `pip download mcp==<버전>` 로 목록·sha256 을 적는다 | 같은 PC 환경(`pipx` 로 따로 떨어진 환경 권장) | — |

- 변환기(`markdown_doc.py`)·HTTP 클라이언트(`client.py`)는 표준 라이브러리만 쓴다 — 단위 시험은 `mcp` 없이 돈다.
- Python 3.10 이상. 설치는 `pipx install ./clients/mcp`(README).
- 버전을 올릴 때: 릴리스 노트에서 `FastMCP.tool(name=, description=, annotations=)` 서명이 그대로인지 확인 → `python3 -m unittest discover -s clients/mcp/tests` → Claude Code 에 붙여 `qaredi_whoami` 확인.

## 6. 갱신 규칙

- 패키지·확장·외부 도구를 **하나라도** 추가하면 이 파일을 같은 커밋에서 갱신한다 (CLAUDE.md 7장).
- 버전을 올리면: EOL 확인 → 스테이징에서 T-1~T-23 재실행 → 운영. "작년에 통과했으니 안전" 은 근거가 아니다.
- 분기마다 이 표의 EOL 을 다시 확인한다 (PRD S-11-B 운영 캘린더).


## 커뮤니티 12단계 (2026-10-01)
외부 라이브러리 추가 없음. 기존 PHP cURL/OpenSSL/PostgreSQL 및 ProseMirror 고정 의존성을 사용한다. 공개 화면은 스크립트·외부 자산을 읽지 않는다. 메신저/연결 비밀 파일은 `/etc/tbox-v2/hooks/<id>.url`, `/etc/tbox-v2/connectors/<id>.key`의 root:tbox-php 0640이며 저장소 밖이다. 실제 연결 대상은 추후 지정한다.


## 사진 이야기 참조 이미지 (2026-10-03)
Ubuntu 배포판 php8.1-gd 8.1.2-1ubuntu2.26 / libgd3 2.3.0-2ubuntu2.3를 사용한다(PHP License / libgd BSD 계열). PHP 런타임 버전은 유지한다. 운영 gd.so SHA-256 `d31d9e4ccb91744cce73397f88260a357147a637628ee30649a84af92159b780`. 새 설치는 `php8.1-gd`를 설치하고 PHP-FPM에 로드한다. GD는 원본을 읽어 메모리 안에서 최대1280px JPEG 참조본을 만들며 원본 파일을 쓰거나 전송본을 저장하지 않는다. 원본 무결성·사진 MIME/해상도·메모리 예산을 확인한다. 기존 FPM open_basedir/disable_functions 및 네트워크 제한은 그대로다.
