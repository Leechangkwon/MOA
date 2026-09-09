# deploy/DEPENDENCIES.md — 이 앱이 의존하는 것 전부 (PRD S-10-C, CLAUDE.md 7장)

버전은 **실습 VM 에서 실측한 값**이다. 기억이나 문서가 아니라 명령 출력이 근거다.
측정일: 2026-09-09 (1단계)

## 1. 실측 버전

| 구성요소 | 버전 (실측) | 확인 명령 |
|---|---|---|
| OS | Ubuntu 22.04.5 LTS | `lsb_release -ds` |
| PHP (FPM/CLI) | 8.1.2-1ubuntu2.25 | `php -v` |
| PostgreSQL | 14.24 (Ubuntu 14.24-0ubuntu0.22.04.1) | `psql --version` |
| nginx | 1.18.0 (Ubuntu) | `nginx -v` |

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
| `php8.1-curl` | F-14 "URL 로 파일 가져오기" (`src/Fetch.php`) | ⏳ **미설치 — 7단계 전 설치 예정** (`apt-get install php8.1-curl`) |
| `php8.1-mbstring` | 파일명·설명 문자열 처리 | ⏳ **미설치 — 7단계 전 설치 예정** (`apt-get install php8.1-mbstring`) |

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
| 업로드 저장소 | `/var/lib/tbox/storage` | 0750 tbox-php |
| PHP 세션(예비) | `/var/lib/tbox/sessions` | 0700 tbox-php |
| 로그 | `/var/log/tbox/{audit,app}.log` | 0640 tbox-php:adm |
| 설정(저장소 밖) | `/etc/tbox/owner.pgpass` | 0600 root |
| TLS | `/etc/ssl/tbox/{fullchain,privkey}.pem` | privkey 0640 root:www-data |
| DB 역할 | `tbox_owner`(소유), `tbox_app`(런타임 최소 권한) | `db/grants.sql` |

## 6. 갱신 규칙

- 패키지·확장·외부 도구를 **하나라도** 추가하면 이 파일을 같은 커밋에서 갱신한다 (CLAUDE.md 7장).
- 버전을 올리면: EOL 확인 → 스테이징에서 T-1~T-23 재실행 → 운영. "작년에 통과했으니 안전" 은 근거가 아니다.
- 분기마다 이 표의 EOL 을 다시 확인한다 (PRD S-11-B 운영 캘린더).
