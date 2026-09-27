# T:F-72 휴대폰 지문·얼굴로 로그인(패스키) — 구현 기록 (복제본 d3pw · 검토 전)

> 지시서: `/var/www/qaredi/docs/passkey-mobile-directive.md`(결정 PK-D1~D6 모두 정해짐) · B1-1 원문 `/var/www/qaredi/docs/qf7-small-conveniences-directive.md` 라운드 C.
> 기준 판: 운영 t-box **f106e30**(v2.31.0 fd96aca 위에 d75 nginx 비밀 창구 넷 — 2026-09-27 02:3x 에 fd96aca 에서 rebase). 작성: claude(구현 작업자 — 개발 세션 changkwon-59 가 맡김). 앞 작업자가 2026-09-26 23:45 사용 한도로 멈춘 뒤
> 2026-09-27 01:47 에 이어받았다. **독립 검토·운영 반영·발행은 개발 세션 몫** — 이 기록은 복제본까지다.

## 0. 한 줄

휴대폰 안의 이 사이트 전용 열쇠(WebAuthn 패스키)로 로그인한다. 지문·얼굴·화면 잠금 PIN(UV)이 필수라 비밀번호+2단계 코드를 **둘 다** 대신한다(F-52 ⑦).
로그인 화면이 열리면 이 브라우저에 「이 기기 표시」가 있을 때 지문 창이 곧바로 뜨고, 성공하면 **보던 화면**(Q:F-7 B1-1)으로 돌아간다.
목표 지표 「홈 화면 아이콘 → 글 쓰기 화면」: **누름 약 12 · 글자 약 26 → 누름 2 · 지문 1 · 글자 0**(PK-T13 — 헤드리스 412 터치로 코드가 셈).

## 1. 라운드와 커밋 (복제본 d3pw — 모두 작성자 claude)

| 커밋 | 무엇 |
|---|---|
| `92ba4fe` 라운드 A | Q:F-7 B1-1 — `Http::returnPath()` 허용 목록(`/tbox/` folder·q·sort·dir·page · `/tbox/settings|activity` · `/community/` space · `/community/p/<uuid>` edit=1 · `/community/b/<n>`) · `Http::redirect` 허용 모양 넓힘 · 미로그인 302 꼬리표(t-box `public/index.php` · 커뮤니티 `routes.php`) · 로그인 화면 `data-to` · `app.js` 같은 이름 함수 + 로그인 뒤 이동 한 함수 · `editor.js` `toLogin` · 벡터 목록 `tests/lib/return_path_vectors.txt` · 「새 페이지」 곧바로 편집(`?edit=1` — 열면 주소에서 지움) |
| `218707a` 라운드 B | 패스키 본체 — 표 둘(마이그레이션 `2026-10-06_passkeys.sql` · schema · grants · install 4-4-1) · `src/Passkey.php`(직접 검증) · 창구 여섯 · `Auth::issueSession`(비밀번호 로그인 8)을 뽑은 한 함수) · `loginWithPasskey` · `revokeAllPasskeys`(S 자리 셋 + 역할·비활성) · 감사 문장·detail 키 · AL-23 · AL-2 목록 · nginx 다섯 창구 × 끝 슬래시 · 로그인 화면 · 내 계정 패널 · `passkey.js` |
| `0a74c4b` 라운드 C | 재인증을 지문으로(창구 둘 · 화면·창) · 권장 카드 · 홈 화면 앱(`/tbox/app.webmanifest` · PNG 192·512 · 셸·런처·로그인 화면 `<link rel=manifest>`) · 좁은 폭 「내 공간」 첫 화면 즐겨찾기(PK-D5 · d71) |
| `c32d61a` WIP | 첫 수락 시험 직전 — 태그 `selftest-base/pk` |
| `70bec14` 시험 | `tests/passkey.sh` · `tests/passkey_api.py` · `tests/browser/passkey.py` · 단위 시험 표본을 파일로 · 카탈로그 · 업그레이드 동선 |
| `e7b6f82` 채움 | 도움말 「지문·얼굴 로그인」(내 계정) · 위험 번호 **R-109**(주석) |
| `5933d9d` 시험 | PK-T1 「다른 사람의 공개 키」 확인이 순번 대신 다른 키를 고름 |
| (문서) | PRD · ARCH · SECURITY · API · DESIGN · README · STAGES · DEPENDENCIES · 위키 · 이 기록 |
| (반영 스크립트) | `review-scripts/` — DB · nginx(15분 자동 되돌림) · 저장소 · 고정 값 찍기 |

## 2. 지시서 대조 (1장 설계 → 구현 자리)

| 지시서 | 구현 | 시험 |
|---|---|---|
| 1.1 등록(비밀번호+코드 먼저 · 5개 · 이름 1~40) | `me_passkeys_options.php`(verifyCurrentPassword·verifyOtpOnce · 실패는 countFailureAndRevoke) · `me_passkeys_register.php`(한 트랜잭션 · `FOR UPDATE` · 5 · UNIQUE → duplicate) | PK-T2·T3 |
| 1.1 로그인(아이디 없이 · allowCredentials 빈 목록) | `passkey_login_options.php` · `passkey_login.php` · `Auth::loginWithPasskey` | PK-T4·T5 |
| 1.1 관리(목록·해제·동기화 표시) | `me_passkeys_list.php` · `me_passkeys_revoke.php`(남의 번호 = 없는 번호 404) · `settings_page.php` 패널 | PK-T7·T11 |
| 1.1 재인증(라운드 C) | `reauth_passkey_options.php` · `reauth_passkey.php` · `Auth::reauthWithPasskey`(열쇠 주인 = 세션 사람 · 챌린지 세션 묶음 · 실패 R-48) · 재인증 화면·창 단추(`passkey.js`) | PK-T6 · 화면 |
| 1.2 곧바로 지문 창 · 자동 완성 · 「이 기기 표시」 | `passkey.js` initLogin(ⓒ → 끝나면 ⓑ · AbortController · 로그아웃 표식 · 탭 취소 · 연달아 두 번 취소 → 표시 꺼짐 · 0.3초 안 거절은 세지 않음 · 꺼진 열쇠 → 표시 꺼짐) · `login_page.php` 칸 표시 | PK-T10 |
| 1.3 설정값(RP ID·origin = APP_URL · IP 꺼짐 · UV · none · ES256 · platform · resident · 챌린지 · 로그인 전 쿠키) | `Passkey::config()`·`configFrom()` · `issueChallenge`·`consumeLogin`·`consumeSession`(한 문장 소비) · `Http::setPasskeyPreCookie`(`__Host-tbox_pk` · Strict · 600) | PK-T1(설정 검사) · T3(IP 503) · T4(쿠키) |
| 1.4 검증 순서(등록 1~7 · 로그인 1~9 · 재인증) | `Passkey::verifyAttestation`·`parseAuthData`·`coseToSpki`·`verifySignature`(=== 1) · `Auth` 두 함수 | PK-T1~T6 |
| 1.5 직접 구현(외부 코드 0) | `src/Passkey.php`(openssl·hash) — `WebPush::publicKeyFromRaw`·`b64uDecode`·`b64uEncode`·`deviceHint` 를 **고치지 않고** 부름 | PK-T1(파이썬 cryptography 교차 · 가상 인증기 표본 · 거부 목록 · 무작위 1만) |
| 1.6 권한·보안 규칙(F-52 · 잠금 같은 카운터 · nginx · 자동 해제 · 90일 · 5개 · Guest · AL-23 · 기록 금지 · detail 키 이름) | `Auth` · `users_update.php`(역할·비활성) · `users_password_reset.php` · `users_mfa_reset.php` · `password_change.php`(`passkeys_revoked`) · `scripts/alerts.php` · `src/Audit.php` · `deploy/sites/*` | PK-T5·T7·T9 · 정적(nginx 10자리 × 두 파일 · detail 키) |
| 1.7 DB | 마이그레이션 · schema · grants · install | PK-T8 · 두 번 실행 종료 0 |
| 1.8 창구·감사 문장 · `/api/me` 두 값 · 토큰 창구 집합·강제 절차 허용 목록 불변 | `public/index.php` 아홉 줄 · `me.php` · `Audit.php` 문장 표 | 정적(라우트 아홉·정책 · 불변 대조) |
| 1.9 화면(로그인·내 계정·재인증·권장 카드·홈 화면 앱·도움말) | `login_page.php` · `settings_page.php` · `passkey.js` · `app.js` · `shell_top.php` · `launcher.php` · `site_manifest.php` · `home_page.php` · `src/help/screens.php` | PK-T10·T11·T12·T13 · 스크린샷 |
| 1.10 세션(PK-D4 그대로) | 코드 0 — 같은 `issueSession` | — |

지시서와 다른 점(작은 것): 화면 시험 파일 이름은 `tests/browser/passkey.py`(지시서 `passkey_flow.py`) · 재인증 화면 단추는 `reauth_page.php` 가 아니라 `passkey.js` 가 붙인다(화면·창 공용) ·
위험 번호는 **R-109**(지시서의 R-108 은 디버깅 묶음 S2 의 `__Host-` 쿠키가 먼저 씀 — 7장 6 「다음 빈 번호로」) · 저장소 `CLAUDE.md` 보안 핵심 목록의 `src/Passkey.php` 한 줄은 취합 지시(2026-09-27)로 들어갔다.

## 3. 시험 (`bash tests/passkey.sh` — 임시 인스턴스만 · 운영 요청 0)

환경: 임시 DB `tbox_d3p_8301_test`(운영 pg_dump 읽기 → 파이프 · 마이그레이션 2026-10-01~06) · `php -S 127.0.0.1:8301`(주소 `http://localhost:8301` — RP ID localhost) ·
8302(APP_URL 이 IP — 꺼짐) · 8303(다른 origin) · 검토 계정 `claude_` 다섯 · UA `tbox-review/<날짜>` · 비밀은 표준입력 · 헤드리스 브라우저는 공용 잠금 아래 한 번에 하나
(`PK_BROWSER_WRAP` — 취합 규칙 2026-09-27 02:1x, 띄우기 직전 available ≥ 700MB).

| # | 결과(2026-09-27 최종 실행) |
|---|---|
| 정적 | php -l 27 · bash -n · py_compile · 보안 린트 0 · 부품 버전 --check 0 · passkey.js 금지 호출 0 · Fetch:: 0 · 요청 머리 0 · detail 키 · 라우트 아홉·정책 · 토큰 창구 집합 셋·강제 절차 허용 목록 둘 불변 · nginx 10자리 × 두 파일 — 전부 통과 |
| PK-T1 | 단위 **137/0**(파이썬 cryptography 표본 등록 3·로그인 3 · CBOR 거부 목록 · 곡선 밖 점 · x 31바이트 · alg -257 · kty 3 · 무작위 1만 건 · PHP 경고 0) · 가상 인증기 표본 교차 **171/0** |
| PK-T2~T9 · B1-1 서버 | 창구 몸통 전부 통과 — 등록 양성·음성 · 로그인 양성(`/api/login` 과 같은 모양 · 옛 쿠키 세션 지움 · 로그인 전 쿠키 지움 · Admin 코드 없이 · 곧바로 `/tbox/users` → 302 `/reauth`) · 로그인 음성 전부(다른 origin · rpIdHash · UV 0 · UP 0 · BE 다름 · AT · type · 챌린지 재사용 · 만료 · 로그인 전 쿠키 없음·다름 · **서명 한 바이트 뒤집기 실패 +1** · **남의 열쇠 실패 +1** · 서명 횟수 역행·같음 → passkey_counter · userHandle 남의 것·빠짐 · 없는 열쇠 · 해제 · 90일 · 비활성 · Guest 만료 · forced_step · 잠금 423 · 「없는 열쇠」=「서명 틀림」 바이트) · 재인증 · 자동 해제 다섯 + 한 트랜잭션 · 챌린지 표·권한 · AL-23 dry-run · B1-1 벡터 69줄(서버 302 Location · data-to) |
| PK-T10~T13 | 화면 몸통 전부 통과 — 등록 창 · 목록 · 해제 확인 창 · 로그아웃 직후 곧바로 뜨는 창 없음 · 단추 → 보던 곳 · 곧바로 뜬 창으로 누름 0 로그인 · 편집 주소로 돌아감 · 화면 벡터 69줄 · `data-to` 를 `//evil.example` 로 고쳐도 첫 화면 · 두 번 취소 → 표시 꺼짐 · 꺼진 열쇠 → 표시 꺼짐 · 다른 origin 등록 거부 · PC 단추·자동 완성 0 · 비밀번호 길 되돌아가기(2단계 켠 관리자 포함) · 재인증 지문 · 권장 카드 · 좁은 폭 즐겨찾기 · 매니페스트 · 360·412·820(터치)·1280(마우스) × 라이트·다크 넘침 0 · 기본 창 0 · 콘솔 오류 0 |
| PK-T13 | **누름 2 · 지문 1 · 글자 0**(목표 3 — 「새 페이지」 곧바로 편집 한 줄로 2) |
| PK-T14 | 회귀는 5장 |
| PK-T15 | **대기**(사용자 폴드8 · 삼성 인터넷 — 6장) |

## 4. 이어받은 뒤 고친 것 (2026-09-27 01:47~)

1. **앞 실행의 실패 둘**(서명 한 바이트 뒤집기 · 남의 열쇠 → 401 passkey_invalid 는 맞는데 실패 수 5 → 5): **시험 쪽 전제**. 격리 재현(임시 인스턴스 · 그 갈래만) —
   앞 음성 다섯(rpIdHash·UV 0·UP 0·BE·AT)이 한 번씩 +1 → 5회째(AT)에 잠금(`auth.lock failed_limit` 행) → 잠긴 동안은 세지 않는 설계(`denyPasskeyLogin` — 비밀번호 길도
   잠긴 계정은 세지 않고 423)라 5 → 5. 풀어 둔 뒤에는 둘 다 **0 → 1**. 지시서 1.4 「4) 이후 실패는 그 계정 실패 1회」를 앱이 지킨다 → **앱 수정 없음**, 시험 `neg()` 가 매번 풀어 둔다.
2. 화면 PK-T12 시간 초과: 새 탭마다 **처음 인증기**의 열쇠를 복사해 서명 횟수가 역행 → 서버가 `passkey_counter`(열쇠 복제 의심)로 거부(앱은 맞게 동작). 성공한 탭의 마지막 판을 옮기게.
3. 「두 번 취소 → 표시 꺼짐」 흔들림: 가상 인증기(UV 거부)는 즉시 거절 → 앱의 「0.3초 안 거절은 브라우저가 막은 것」 규칙에 걸림. 사람이 보고 취소하는 흉내(0.7초 뒤 거절) · 고정 sleep 대신 표식을 기다림.
4. 뒤 절이 PK-T10 로그아웃으로 끊긴 세션을 다시 씀 → 비밀번호 길 로그인(B1-1 ① — 2단계 켠 관리자 포함 · 새로 더한 확인)으로 새 세션.
5. PK-T1 「다른 사람의 공개 키」가 순번(로그인 0·1)을 다른 열쇠로 가정 → 공개 키가 다른 표본을 고름.
6. 빠진 것: 도움말 「지문·얼굴 로그인」 · 820 폭 · 업그레이드 동선 한 줄 · 카탈로그 등록 · 문서 전부(PRD F-72 행·F-52 ⑦·5.4·AL-23 · ARCH 5.35·7.4·7.5·10·11.4 · SECURITY R-109·7장 · API 16장 · DESIGN 2.10·2.11 · README · DEPENDENCIES · STAGES 0장 · 위키 guide-security) · 반영 스크립트.

## 5. 회귀 (임시 인스턴스 · 운영 쓰기 시험 제외 — 카탈로그 writes_prod·manual_only 거부)

최종 한 번(2026-09-27 02:0x~03:00 · `REGRESS=1 REGRESS_TEMP=1` · 브라우저·회귀 한 건마다 공용 잠금) — **통과 255 · 실패 6**(본 시험 249 전부 통과 · 회귀 12 중 6 통과).
실패 6은 모두 가렸다 — 앱 결함 0:

| 회귀 | 결과 | 판정 · 처리 |
|---|---|---|
| help_button | 16/0 | 통과 |
| api_read | 25/0(미검증 4) | 통과 |
| f68 | 109/0(미검증 2) | 통과 |
| dbg_s_security | 153/0 | 통과 |
| community/stage5a2 | 139/0 | 통과 |
| community/stage7b | 141/0 | 통과 |
| api_whoami | 21/1 | (e) `/api/me` 키 집합 — 새 두 값(`passkey_available`·`passkeys_active`, 의도) + `can_community_write`(디버깅 묶음 U 가 더했는데 목록을 안 고쳐 **기준 판에서도 실패**하던 항목). 기대값 고침 → 재실행 **22/0**(기준 판 f106e30 은 21/1 — 같은 항목) |
| q_launcher | 96/5 | ① `/tbox/settings` 302 Location 꼬리표 — B1-1 의 의도된 변경 → 기대값 고침 ② 생성기 CSP 머리 · 옛 `/assets/app.css` 308 ×2 · 공유 파일 받기 200 — 임시 인스턴스(php -S · nginx 없음 · 빈 저장소) 환경. 기준 판 대조 — 기준 판 f106e30 도 **97/4 같은 네 항목** → 새 실패 0 · 재실행 97/4(①만 고쳐짐) |
| dbg_s2_security | 106/4 | ① 옛 쿠키 이름 `/tbox/settings` 꼬리표 — 의도 → 고침 ② nginx `tbox_login` 자리 12 — 이번 묶음이 10 자리를 더함 → 22 로 고침 ③④ 「설치본 /etc = 저장소 deploy/sites」 — **반영 순서 확인**(② nginx·③ 저장소 반영 전에는 다르고 반영 뒤 같아짐) — 고치지 않음. 재실행 **107/3** — 남은 셋 = ③④(반영 순서) + 「비밀번호·코드 묻는 나머지 창구에는 nginx 제한 없음(기대 0 · 실제 7)」: 기준 판 f106e30 에서도 실패(109/1 — d75 가 그 창구에 `tbox_secret` 7자리를 넣어 시험 문장이 낡음) → 새 실패 아님, 고치지 않음(S2 시험 주인 몫) |
| qf3c_zip | 114/3 | 정적 **범위 확인**(자기 기준 5de7467 대비 `public/index.php` 에 라우트 한 줄만) — 기준 판 f106e30 에서는 통과(`git diff` 로 셈: 더한 라우트 1 · 뺀 줄 0) · 이번 묶음의 라우트 아홉·꼬리표 줄이 걸림. 기능 몸통 통과. 그 시험의 범위라 고치지 않음 |
| dbg_u_ui | 17/2 | 정적 대조 둘 — 라우트 파일 차이 0(자기 기준 e88f690 대비) · **기준 판에서도 실패**(14줄) / 「더한 줄에 인라인 script」 1 = `src/views/shell_top.php:275` `<script defer src=…passkey.js>` — 패턴 `<script [^s]` 가 `defer` 에 걸린 **오탐**(외부 파일 · 인라인 0 · 같은 파일 app.js 줄과 같은 모양). 화면 몸통 통과 |
| dbg_u2_ui | 30/2 | 같은 둘 — 보안 핵심 파일 차이 0(자기 기준 081af21 대비 — 기준 판에서는 0, 이번 묶음은 지시서 ★ 파일을 고침) · 인라인 오탐(위와 같은 줄). 화면 몸통 통과 |

정적: `scripts/security_lint.sh` 0 · 봇 `tests/static/selftest.sh` 238/0 · `php scripts/community_asset_version.php --check` 0(editor·database·templates import 버전 다시 찍음) · `php scripts/wiki_check.php` 0(부모 폴더 배치를 흉내 낸 임시 폴더).
돌리지 않은 것: 카탈로그 writes_prod·manual_only(f46·f51·f52·f54·f65·f66·fetch_hosts·stage26 — 틀이 거부) · f70 · `upgrade_smoke.sh`(새 동선 `tbox.settings_passkeys` — ① DB 뒤 운영 사본으로).

운영 대조(읽기만): 감사 max(id) 25114(01:47) → 25556(03:00) — 늘어난 것은 `alert.raised`(cli:alerts.php) 415 = 운영 경보 AL-9 가 이 시험의 `sudo psql`·`pg_dump`(임시 DB)를 적은 것 ·
다른 세션의 운영 스모크(claude_ 계정 · 검토 UA) · 실제 사용자 행. **패스키 행 0** · alerts.log 1965 → 2307(AL-9 398 · AL-10 claude-review 2 — 다른 세션 로그인) · `/etc/tbox-v2/qa-totp.txt` 시각 그대로(2026-09-26 16:57:23).

## 6. 반영 (개발 세션 몫 — 순서 **DB → nginx → 저장소**)

| 순서 | 스크립트(검토 뒤 `/var/www/qaredi/scripts/` 로) | 연습(경로만 바꾼 사본 · 모의 운영 트리 + 임시 DB — 결과는 스크립트 머리 주석) |
|---|---|---|
| ① | `apply-pk-db-2026-09-27.sh --dry-run|--migrate <마이그레이션 사본>`(root) — 문장 목록 고정 · 사본만 실행 · 반영 뒤 모양 sha(열·제약·색인·소유자·권한 행렬) · 앱 passkeys DELETE 없음 · 틀리면 빈 표 둘 지움 | 반쯤 상태·다른 파일·사람 사용 중 거부 · 본 실행 · 다시(이미 적용) · 모양 불일치 → 빈 표 지움 |
| ② | `apply-nginx-pk-2026-09-27.sh --plan|--dry-run|--apply|--confirm|--revert`(root) — 두 사이트 파일(반영 전 = 운영 f106e30 판 — tbox-v2 `b882cc85…` · local `5906bfb2…`) · 더하기만(파일마다 96줄 · 지움 0) · tbox_login 12 → 22 · tbox_secret 7 그대로 · **15분 자동 되돌림** | 인자 거부 · --plan · --dry-run(`nginx -t -c` 사본) · --apply(가짜 systemctl — reload 0) · 다시 · --revert · 반쯤 거부 · --confirm |
| ③ | `apply-pk-repo-2026-09-27.sh <패치> <sha256> [--dry-run]`(changkwon) — `git diff --binary`(PNG) · 경로·요약 고정 · ①②가 먼저인지 봄 · 라우트 +9 글자 대조 · 토큰 창구·강제 절차·커뮤니티 라우트 그대로 · HTTP(감사 0) | sha·인자 거부 · ② 전 멈춤 · --dry-run · 본 실행 · 다시(기준 판 아님) · 확인 실패 → apply -R |

되돌릴 때는 ③ 만 되돌려도 된다(표·nginx 는 남아도 무해).

- **왜 nginx(②)가 저장소(③)보다 먼저인가**(실측 — `/etc/nginx/sites-available/tbox-v2` 읽기만, 2026-09-27): 지금 설정에는 새 경로의 `location =` 가 없어
  `/tbox/api/login/passkey/options` 등은 `location /`(172줄 — try_files → `location = /index.php`)로 가고 **속도 제한이 없다**. ② 를 먼저 넣으면 ③ 전에는
  새 location 이 앱의 JSON 404(없는 경로 — 라우터 [1] 이 인증 전에 끝내 감사 0)로 떨어질 뿐이라 해가 없다. nginx 스크립트의 확인은 새 창구 응답을 요구하지 않는다
  (설정 검사 · 기존 제한 그대로 · 새 location 열이 설정에 있음 · GET 하나씩 앱 JSON 404/405).
- **zone** — 기존 `tbox_login` 재사용(`tbox_secret` 아님): 다섯 창구가 POST 만이라 GET 을 빼는 map 이 필요 없고, 로그인·재인증은 비밀번호 길과 같은 종류의 인증 시도라
  한 IP 버킷을 같이 쓰는 편이 더 조인다(두 길로 나눠 두 배를 시도할 수 없다). d75 `tbox_secret` 일곱 자리는 그대로(스크립트가 센다).
- 연습 결과(경로만 바꾼 사본)는 **각 반영 스크립트 머리 주석**에 적는다(`review-scripts/` — 패치 밖이라 패치 sha 가 연습 뒤에 바뀌지 않게).

## 7. 실제 기기에서만 알 수 있는 것 (지시서 7장) · 사용자 확인(PK-T15)

1. 삼성 인터넷(폴드8)이 누름 없이 부른 지문 창(ⓒ)을 허용하는지 — 막으면 단추 한 번 더(누름 +1).
2. 홈 화면 앱(standalone)에서 패스키·세션 쿠키가 어떻게 남는지 · 앱을 닫았다 켤 때 로그인 화면 → 지문 창.
3. 휴대폰이 attestation `none` 요청에 형식 `none` 으로 답하는지(아니면 등록 400 — 보고).
4. 삼성 패스 · Google 비밀번호 관리자 중 어디에 열쇠가 저장되는지(「계정에 동기화됨」 표시) · 서명 횟수가 0 인지(0 이 아니면 매번 늘어야 한다).
5. 비밀번호 관리자 자동 채움이 로그인 폼 `autocomplete="off"` 아래에서 칸 표시만으로 붙는지.
6. 접은 화면(좁은 폭)·펼친 화면(820 안팎) 둘 다에서 로그인·내 계정·즐겨찾기 배치.

사용자 확인 순서(지시서 6장): ① 폰 브라우저로 비밀번호+코드 로그인 → 「내 계정」 → 「이 휴대폰 등록하기」 → 이름·비밀번호·코드 → 지문 → 목록 한 줄(휴대폰 경보를 켠 관리자면 AL-23 알림)
② 로그아웃 → 로그인 화면(곧바로 뜨는 창 없음) → 「지문·얼굴로 로그인」 → 지문 → 첫 화면 ③ 브라우저를 닫고 → 메뉴 「홈 화면에 추가」 → 홈 화면 「Qaredi」 → 지문 창이 곧바로 뜨는지 →
지문 → 「내 공간」 → 「새 페이지」(곧바로 편집) — **누른 수를 센다**(목표 2~3) ④ 커뮤니티 페이지 주소를 로그아웃 상태로 열기 → 지문 → 그 페이지 ⑤ 관리 화면 → 재인증 지문
⑥ 「내 계정」에서 해제 → 로그아웃 → 지문 → 「꺼져 있습니다」 문구 → 비밀번호 길. 결과는 현황판에 사용자 한 줄 — 받기 전까지 「대기」.

## 8. 안 한 것 · 결정이 필요한 것

- 독립 검토(다른 문맥) · 운영 반영 · 발행 · 사용자 확인 — 개발 세션·사용자 몫.
- 같은 시간의 디버깅 묶음 U3(복제본 d3uw)와 **겹치는 파일**: `DESIGN.md`(이 묶음은 2.10·2.11 에만 더함 — 8장 이력 줄은 넣지 않았다, 합칠 때) · 커뮤니티 JS(`editor.js`·`database.js`·`templates.js` — import 버전 다시 찍음) ·
  `public/index.php`(이 묶음은 라우트 아홉 줄 · 미로그인 302 꼬리표). 먼저 들어가는 쪽 뒤에 합치고 `pins-pk-repo.sh` 로 다시 찍는다.
- 업그레이드 스모크 새 동선 `tbox.settings_passkeys` 는 운영 DB 에 표가 있어야(① 뒤) 통과한다(pg_dump 에 표가 들어가야 `passkey_available` 참).
- PC(윈도우 Hello · QR) · 관리자 화면에서 남의 열쇠 보기 · 다른 비밀번호+코드 자리(토큰 발급 등)를 지문으로 — 지시서 1.11 범위 밖.

## 9. 독립 검토 반영 (2026-09-27 — 판정 「반영 가능」 · 낮음 3 · 권고 3 · 태그 `review-fix/pk`)

- **RV-PK-1 고침 · RV-PK-2·3 현행 유지** — `SQL_TOUCH_PASSKEY` 에 `AND sign_count = :stored`(③ 에서 읽어 ⑦ 에서 비교한 값): 로그인은 0 행이면 세션 발급 트랜잭션을 되돌리고 401 한 문구 · 감사 `passkey_counter`(실패 1회), 재인증은 기존 `passkey_invalid` 갈래(창 안 열림) · 시험 PK-T5·T6 음성 둘(다른 연결이 그 행을 잠근 채 서명 횟수를 올리고, 서버 UPDATE 가 기다리는 것을 본 뒤 커밋) — **고치기 전 판에서는 둘 다 실패**(로그인 200 + 세션 + 서명 횟수 뒤로 · 재인증 200 + 창 열림) · 고친 판 `tests/passkey.sh` **251/0**(창구 몸통 152/0 · 화면 65/0 · PK-T1 137/0·171/0) · 회귀 dbg_s2_security 107/3(5장과 같은 셋 — 새 실패 0) · 보안 린트 0 · 봇 selftest 238/0.
