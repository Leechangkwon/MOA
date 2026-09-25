# 보안 재점검 — 외부 해커 관점 (2026-09-15)

| 항목 | 내용 |
|---|---|
| 목적 | 외부 공격자로부터 데이터를 지킬 취약점을 새로 찾는다 |
| 방식 | 정적 코드 분석 + 읽기 전용 서버 점검(`scripts/check_baseline.sh`, ss, sshd -T, df, 백업 디렉터리). 라이브 요청·설정 변경 없음 |
| 관점 | ① SECURITY.md 대조 ② 비인증 외부 공격면 ③ 파일 입출력 ④ 인가·IDOR·권한 상승 ⑤ 서버·운영·복구 |
| 한 줄 결론 | **앱 층은 SECURITY.md 판정대로 대부분 구현돼 있다. 뚫리는 곳은 9-11 사고와 같은 서버 층이다** — SSH 비밀번호 로그인 허용·root 로그인 허용·방화벽 꺼짐·백업이 같은 디스크에만·디스크 98% |

## 1. 심각도순 발견 사항

| # | 심각도 | 제목 | 근거 | 공격 시나리오 | 조치 |
|---|---|---|---|---|---|
| 1 | **치명** | SSH 비밀번호 로그인·root 로그인이 여전히 열려 있다 | `sshd -T`: passwordauthentication yes, permitrootlogin without-password, maxauthtries 6. `/etc/ssh/sshd_config.d/50-cloud-init.conf` 가 덮어씀(SECURITY 8.4-12 그대로) | 9-11 사고 재현. VPN 안 어느 PC 든 22 번에 비밀번호 대입. 잠금·fail2ban 없음 | `deploy/hardening.sh --apply` 전에 50-cloud-init.conf 를 먼저 고친다(8.4-12). 키 등록 뒤 적용. **09-16 재확인: 비밀번호 로그인 `no` 적용됨**(50-cloud-init.conf 09-15 13:51). root `without-password`·ufw 는 그대로. eric 키 미등록 → SSH 불가(콘솔만), 사용자 결정 유지 — SECURITY R-22·8.4-3 |
| 2 | **치명** | 방화벽 inactive — 22·80 이 0.0.0.0 전체 개방 | `ufw status` inactive, `ss`: 0.0.0.0:22·:80 | 프록시(192.168.104.100)를 거치지 않고 80 에 직접 붙으면 **평문 HTTP 로 로그인 비밀번호가 흐른다**(TLS 는 프록시에만 있고 nginx 80 은 리다이렉트 없음). 22 는 1번과 결합 | ufw: 22 는 관리 PC IP 만, 80 은 192.168.104.100 만. 8.4-16 의 ADMIN_NET 기본값 오류를 먼저 고친다 |
| 3 | **높음** | 백업이 같은 디스크·같은 호스트에만 있다 (3-2-1 미충족) | `/var/backups/tbox-v2` (root 0700) 에 db 5일·files 5일분, `backup.key` 도 `/etc/tbox-v2/` 같은 서버. 원격 사본·WAL 아카이브 미적용(check_baseline) | root 를 잡은 공격자(1·2번)가 `rm -rf` 하면 데이터·백업·복호화 키가 한 번에 사라진다. 9-11 사고와 동일한 피해 | 매일 `db-*.dump.gpg`+`files-*.tar.gz.gpg` 를 다른 PC/NAS 로 pull 방식 복사(서버에 원격 자격증명을 두지 않는다). backup.key 는 오프라인 보관 |
| 4 | **높음** | 루트 디스크 98% (322MB 남음) — **09-16 재확인: 53%, 여유 11GB (lvextend, 해소)** | `df -h /`. 큰 것: ~/.cache 437M(playwright), 백업 359M, apt 134M | 디스크가 차면 audit.log 기록·DB 쓰기·백업이 실패한다. 공격자가 100MB 업로드 3~4번이면 **의도적으로 감사 로그를 멈출 수 있다**(업로드 상한 100MB·저장소 같은 디스크) | 즉시 playwright 캐시·apt 캐시 정리. 저장소·백업을 별도 볼륨으로 |
| 5 | **높음** | 로그가 서버 안에만 있다 | rsyslog 원격 전송은 `tbox-v2.conf.example` 만. 대체 수단은 해시 체인+chattr | root 권한 공격자는 흔적을 지운다. 해시 체인은 「지워졌다」는 사실만 알려 주고 내용은 못 살린다 | 3번 pull 복사에 `/var/log/tbox-v2/*` 를 포함시키면 최소한의 원격 로그가 된다 |
| 6 | 중간 | 계정 잠금이 계정 단위라 아이디만 알면 누구든 그 계정을 계속 잠글 수 있다 | `Auth.php` countFailure 5회→10분. nginx limit_req 30r/m 는 IP 단위 | 관리자 아이디(admin01)를 알면 10분마다 요청 5개로 영구 잠금 = 서비스 거부 | SECURITY 8.4-14 에 이미 기록. 잠금 대신 IP+계정 조합 지연, 또는 잠금 중에도 MFA 성공 시 통과 |
| 7 | 중간 | PHP 8.1 은 2025-12-31 보안 지원 종료 | `php -v` 8.1.2. check_baseline 「주의」 | 이후 공개되는 PHP 취약점 패치를 못 받는다. Ubuntu 22.04 ESM 은 별도 | 운영 전환 시 8.3 으로. Q-11 결정 재검토 |
| 8 | 중간 | postgres 계정에 로그인 셸이 있다 | check_baseline 「주의」 | root 획득 뒤 `su postgres` 로 DB 전체 평문 덤프(9-11 사고의 06:37 덤프가 이 경로) | `usermod -s /usr/sbin/nologin postgres` |
| 9 | 낮음 | 민감정보 검출 결과의 excerpt 가 감사 로그에 남는 형태를 코드만으로 확정 못 함 | `src/Sensitive.php:115` 가 excerpt 를 반환 | 주민번호 등이 검출 근거로 audit.log 에 원문 기록되면 로그 자체가 유출 통로 | excerpt 가 마스킹되는지 런타임으로 확인(주민번호 패턴 파일 업로드 뒤 audit.log 열람) |

## 2. SECURITY.md 대조 — 확인된 것 (관점 ①)

| 문서 판정 | 코드·설정 근거 | 결과 |
|---|---|---|
| ① 프론트 권한 우회 막힘 | `public/index.php:684` 정책이 `Authz::KNOWN_POLICIES` 밖이면 기동 실패, `:788~851` public 외 전부 requireLogin→CSRF→authorize | 일치 |
| ② 권한 없는 데이터 노출 막힘 | 구매 API 는 `Authz::purchaseScopeWhere`(Authz.php:1004). 스코프 없이 도는 구매 핸들러 20개는 전부 **페이지(뷰)·전지점 기초정보(master.write, 재인증 대상)** 라 설계상 전지점이 맞음 | 일치 |
| ③ XSS 막힘 | CSP `script-src 'self'` (bootstrap.php:117 + nginx), 뷰에 인라인 스크립트 0, 다운로드 attachment, inline 은 image·pdf 만(`files_preview_raw.php`), 업로드에서 html/svg/js 차단(`Upload.php:44`) | 일치 |
| ④ SQL 인젝션 막힘 | 값 바인딩, `p_stock_moves` 는 `tbox_app` 에 SELECT·INSERT 만(grants.sql:129), audit_logs UPDATE/DELETE 없음 | 일치 |
| ⑤ SSRF 막힘 | `Fetch.php` https·443·허용표·CURLOPT_RESOLVE·FOLLOWLOCATION false·CURLPROTO_HTTPS·사설 IP(IPv4 매핑 포함)·0x 표기 차단, fpm 에서 allow_url_fopen off·disable_functions | 일치 |
| 공유 링크(R-57) | 토큰 random_bytes(32), DB 에 sha256 만, `FOR UPDATE`+조건부 UPDATE 로 횟수 초과 불가, sha256 대조 뒤 attachment 스트림 | 일치 |
| 세션 | fpm: httponly·secure·samesite Lax·use_strict_mode, DB 세션 | 일치 |
| 오류 은닉·정찰 | display_errors off, expose_php 0, server_tokens off, health/time 는 `ok`·시각만 | 일치 |
| 저장소 격리 | STORAGE_DIR=/var/lib/tbox-v2/storage (웹 루트 밖, tbox-php 0750), 경로는 UUID+realpath 만, open_basedir | 일치 |
| 엑셀 파싱 XXE | `Sheet.php`·`Preview.php` 가 XML 파서를 쓰지 않고 ZipArchive+정규식만 | 일치 |
| 8.4-3 SSH 하드닝 | **미적용** (1번) | 불일치 |
| 8.4-4 원격 백업 | **미적용** (3번) | 불일치 |
| 8.4-5 원격 로그 | **미적용** (5번) | 불일치 |
| 8.4-6 정합성 일 1회 | cron `0 2 * * *` consistency_check | 일치 |
| R-62 qa_ 자격증명 교체 | alerts.log 에 qa_admin01 이 오늘도 활동 — 교체 여부 코드로 확인 불가 | 확인 필요 |

## 3. 관점별 요약

- **② 비인증 공격면**: 공개 라우트는 `/login`, `/api/login`, `/api/health`, `/api/time`, `/s/{token}`, `/assets/totp-generator.html` 여섯 개. 전부 정보 노출 없음. 로그인은 IP 30r/m + 계정 5회 잠금. 남는 약점은 6번(잠금 DoS)과 2번(평문 80 직접 접근).
- **③ 파일 입출력**: 업로드 화이트리스트+이중확장자+finfo 짝 검사, 저장 이름 UUID, 미리보기 inline 은 image·pdf 만, 구매 엑셀은 XML 파서 없이 파싱, 대사 업로드는 `admin-only` 로 저장. 발견 없음. 9번만 런타임 확인.
- **④ 인가**: 라우트 정책 누락 시 기동 실패. 구매 원장 append-only 는 DB 권한으로 강제. 발견 없음.
- **⑤ 서버·운영**: 1~5·7·8번 전부 여기. **SECURITY.md 8.4 가 "사용자" 몫으로 남긴 항목이 나흘째 그대로**이며, 이것이 이번 점검의 핵심 결론이다.

## 4. 런타임으로 확인해야 할 것
1. `50-cloud-init.conf` 수정 → `sshd -T | grep passwordauthentication` = no 확인 → 새 터미널에서 키 접속 확인 뒤에만 기존 세션 종료 — **✅ 09-16 확인: `no`**. 사용자(changkwon) 키 접속 정상(09-15 17:09·23:17 세션). eric 은 키 없음 → SSH 불가, 사용자 결정 유지
2. ufw 적용 전 관리 PC 의 실제 출처 IP 를 `who` 로 확인(8.4-16)
3. 주민번호 패턴 파일 업로드 → `audit.log` 의 excerpt 마스킹 여부
4. `restore.sh --db` 실제 리허설(T-15 는 9-11 이전 기록)
5. qa_ 계정 비밀번호 교체 여부(R-62)

## 5. 2026-09-16 재확인 요약

| # | 상태 | 근거 |
|---|---|---|
| 1 | 해소(비밀번호)·부분(root 키 허용 유지) | `sshd -T` passwordauthentication no |
| 2 | 미해소 | `ufw status` inactive |
| 3 | 미해소 | 원격 사본 설정 없음 |
| 4 | 해소 | `df` 53% |
| 5 | 미해소 | rsyslog 예시만 |
| 6·7 | 수용 | 8.4-14 · Q-11 |
| 8 | 미해소 | postgres `/bin/bash` |
| 9 | 확인됨(09-25 문서 대조 — 새 시험 아님) | 런타임 시험 미실행 → 이미 있던 실측: SECURITY R-58 15-3(2026-09-12) — 시험 주민번호 원문이 DB·`audit.log`·`app.log` 어디에도 0건, 감사 detail 은 패턴 이름·건수만(`src/handlers/_upload_common.php:372·449`). `src/Sensitive.php:115` 의 excerpt 는 오피스 본문 추출(검사 입력)이라 감사로 가지 않고, 이 파일은 그 뒤 바뀌지 않음(마지막 커밋 `b9f6b79` 2026-09-12) |

강사(eric) 접근: `authorized_keys` 0 바이트 → SSH 불가, 콘솔(tty1) 가능. **사용자 결정 2026-09-16: 유지.** 보호(프록시·하이퍼바이저)는 VM 밖이라 eric 로그인과 무관. 공개키를 받으면 등록. ufw 를 켤 때 22 번을 관리망(10.0.4.0/24)만으로 좁히면 eric 출처(192.168.200.50·10.0.11.63)가 막히므로 22 는 열어 두고 키 인증으로 지킨다.
