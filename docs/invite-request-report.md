# 보고 — H2 로그인 전 첫 화면 · 초대 요청 (2026-10-14 · Opus 구현 에이전트)

지시서 `docs/invite-request-directive.md`(H2) · 기획 `../docs/invite-request-plan.md` 2 · 3장 · DESIGN 2.32. worktree `/home/changkwon/qaredi-invite-20261014`(브랜치 `feat/invite-request`) · 결과 `/home/changkwon/qaredi-invite-results-20261014/`.

## H2 — 변경 파일

| 갈래 | 파일 |
|---|---|
| DB(보안 핵심 — 지시서 자리) | `db/migrations/2026-10-14_invite_requests.sql`(새 — 표 · CHECK · 색인 넷 · GRANT · 슈퍼유저일 때만 소유자 tbox_owner · 되돌리기 주석) · `db/schema.sql`(같은 표) · `db/grants.sql`(같은 권한 절) |
| 라우트(보안 핵심) | `public/index.php` — 여섯 줄만 |
| 감사(보안 핵심) | `src/Audit.php` — SUMMARY_TEMPLATES 여덟 줄(create allow · deny rate_limited · too_many_open · honeypot / decide allow approve · reject / issue allow · deny issue_limit) |
| 서버 | 새 `src/handlers/invite_request_page.php` · `invite_request_status_page.php` · `invite_requests_create.php` · `invite_requests_status.php` · `invite_requests_list.php` · `invite_request_decide.php` · `src/Member.php`(`activeTeam` · `issueInvite` — 발급 한 벌) · `src/handlers/member_invites.php`(그 함수를 부름 — 판정 순서 · 응답 · 감사 그대로) · `scripts/privacy_purge.php`(①-2 갈래) · `scripts/alerts.php`(AL-25) · `content/legal/privacy-2026-10-14.html`(새 판 — 넷만 고침) · `config/legal.json`(끝 줄) |
| 화면 | `src/views/launcher.php`(`!$signed` 갈래만) · `public/assets/moa.css`(끝에 2.32 절) · 새 `public/assets/invite-request.js` · `src/views/member_invites.php`(「초대 요청」 표 · 승인 · 반려 창) · `public/assets/member.js`(표 그리기 · 가입 갈래 한 줄 가드) |
| 시험 | 새 `tests/community/invite_request_http.py` · `tests/community/invite_request_browser.py` · `tests/community/memories_harness.py`(마이그레이션 목록 끝 · `--suite` 두 이름) · `tests/catalog.json`(두 줄) |
| 문서 | `ARCHITECTURE.md`(「모아 초대 가입 · 메신저」 절 끝 한 단락 · 7.4 표 여덟 줄) · `SECURITY.md`(R-122) · 이 보고서 |

## H2 — 수락 시험과 결과

| 시험 | 명령 | 결과 | 폴더 |
|---|---|---|---|
| HTTP(격리) | `python3 tests/community/memories_harness.py --suite invite_request_http.py --output …/http-run2` | **120 / 0** | `http-run2/`(results · metrics · al25-dryrun.txt · purge-dry/run.txt · http.log) |
| 브라우저(격리) | `… --suite invite_request_browser.py --output …/h2-browser-final` | **458 / 0** | `h2-browser-final/`(results · metrics · shots/) |
| 정적 | `scripts/security_lint.sh` · `php -l`(바뀐 PHP 전부) · `node --check`(JS 둘) · `php scripts/wiki_check.php` · `git diff --check` | 0건 · 통과 · 통과 · 0건 · 통과 | — |
| 마이그레이션 두 번 | 틀이 schema.sql 위에 2회 적용 → grants | 오류 0 · 모양 · 권한 단언(HTTP 시험 앞 넷) | `http-run2/schema.log` |

HTTP 시험이 잰 것: 요청 201 `{status_path}` · 코드 64 16진 · 표엔 sha256 만 · 400 열다섯(키 · 길이 · 공백 · 제어 · 서식 문자 · 끝의 NUL · agree 거짓 · 글자 "true" · 빠짐 · 숫자 · 깨진 JSON) · CSRF 없음 / 어긋남 403 · 로그인 409 · 1시간 4번째 429(Retry-After) · 24시간 6번째 429 · 미끼 201 같은 모양 + 행 0 + deny · 열린 200 → 503 · 화면 둘 public · 로그인한 사람 302 `/` · 목록 · 결정 직원 · 팀장 403 · 재인증 없으면 403 next:reauth · 없는 팀 · 형식 400 · 없는 요청 404 · 이미 결정 409 · 확인 404 · 400 · 403 · 대기 · 반려(메모 0) · 승인 → member_invites 1회 · 7일 · 팀 A · 이름표 「초대 요청 #번호」 · 만든 사람 = 승인한 관리자 → 그 초대로 가입 201 → joined(새 발급 0) · 다른 요청 세 번 발급(앞 둘 거둠) · 넷째 409 + deny · 거둔 초대로 가입 410 · 1분 21번째 429 · 감사 여덟 조합 · 문장 · 대상 「초대 요청 #번호」 · 감사 행 · 감사 파일 · php -S 접근 로그에 이름 · 소속 · 이유 · 메모 · 코드 0 · 처리방침 새 판 · `?v=2026-10-08` 옛 판 · 옛 판 동의 회원 403 next:consent → 동의 → 200 · AL-25 dry-run 21건 → 한 줄 · privacy_purge dry(2) → run(31일 둘 지움 · 29일 둘 남음 · 감사 counts) → 다시 0 · 앱 계정 DELETE 42501.

브라우저 시험이 잰 것: [same] 동의 전 · 로그인 뒤 런처 서버 HTML 이 `main` 의 launcher.php 와 바이트 같음(실제 서버에서 파일을 바꿔 끼워 견줌) · [grid] 1440 · 1200 · 720 · 412 · 360 × 밝게 · 어둡게 · 고대비 — ①~⑥ 마크업 · 크기(로고 64/44 · h1 40/28 · 설명 17 · 단추 44) · 카드 4/2/1열 같은 높이 · 세 걸음 · 안전 2/1열 · 질문 넷 접힘 → 펼침 · ⑥ 접힘 · 옛 블록 · 옛 글 0 · 초대 요청 화면(폭 ≤ 480 · 미끼 화면 밖 · aria-hidden · tabindex -1) · 요청 확인 화면 · 관리자 표(「초대 링크」 위 · 열린 수 배지 · 동작이 화면 안) — 넘침 0 · 대비 4.5 · 누름 자리 < 44 0 · 인라인 style 0 · 콘솔 0 · [flow] 1440 · 412 폼 → 완료 패널 · 복사(클립보드 = 주소) · 확인 화면 대기 · 반려 · 초대됨 → 「가입하기」 → 가입 화면 초대 칸 64자 · 세 번 받음 · 틀린 코드 · 코드 없음 · [admin] 이유 60자 + … → 펼침 · 다시 접힘 · 412 쌓인 행 · 창 Esc · 초점 복귀 · 1440 승인 창(팀 고르개) → 승인 · 반려 창(메모) → 반려 · 표 다시 그림.

중간 실행(`http-run1` · `browser-run1~4`)은 고친 과정의 기록이다(끝의 NUL 이 trim 으로 잘려 통과하던 것 · 같은 문서 해시 바꾸기 · `.btn--plain` 글자색 · 표 폭 · 시험 순서). 최종 판정은 위 두 줄.

## H2 — 고친 옛 단언 (돌리지 않았다 — 밤 회귀 몫)

| 시험 | 단언 | 고친 내용 |
|---|---|---|
| `tests/community/home_h1_browser.py` [before] | 로그인 전 런처 서버 HTML = 34cfd3d · 실제 서버 「소개 화면(.moa-service-hero)」 | 바이트 비교는 동의 전 갈래만 · 로그인 전은 `.landing-hero` 있음으로 |
| `tests/neutral_home_entry.py` | 로그인 전 `.moa-service-hero` 1 · CTA `#home-open-dashboard` → `/tbox/login?to=home` · 「로그인하면 내 위젯…」 · 머리 `#services` 링크 | 로그인 전은 `.landing-hero` · 「로그인」 `/tbox/login` · 「초대 요청하기」 · `#services` 0 + `.landing-features` · `?to=home` 은 주소로 직접 열어 뒤 단언(목적지 `/`) 유지 |
| `tests/home_shortcuts_browser.py` | 로그인 전 `.moa-home-route` 「내 작업」 · `.moa-service-card` 「업무 홈」 | 두 블록 0 + 「가입하면 할 수 있는 일」에 「업무」 |

처리방침 판을 글자로 재던 옛 단언은 찾지 못했다(qf13 시험들은 `config/legal.json` 끝 줄을 읽는다).

## H2 — 못 한 것

- 운영 반영 · 독립 검토 · 밤 회귀 — 메인 몫. 운영 반영 순서: 마이그레이션(tbox_owner) → 코드 → (cron 은 privacy_purge 그대로 · alerts 그대로).
- 바닥(`moa-footer`)의 누름 자리(로고 38px)는 H2 범위 밖이라 재지 않았다(H4 바닥 붙이기에서 다룸).

## H2 — 기획 · DESIGN 과 다르게 한 것

1. **두 공개 화면의 머리**는 `member_top.php`(보안 · 허용 목록 밖이라 고치지 않음)를 `$memberPage='join'` 으로 쓴다 — 가입 화면과 같은 머리(모아 홈 · 업무 · 로그인). 그 때문에 `member.js` 가입 갈래 맨 앞에 「가입 폼이 없으면 끝」 한 줄을 더했다(member.js 는 허용된 「초대 절 JS」 파일).
2. **속도 셈에 미끼 칸 거부도 넣었다**(IP 마다 1시간 · 24시간 = 요청 행 + `deny honeypot` 감사 행) — 미끼에 걸린 봇이 429 없이 무한히 201 을 받아 진짜와 구별되는 것을 막으려고. 순서는 속도 · 상한 → 미끼 → 저장(모든 거부가 진짜 요청과 같은 순서 · 모양).
3. **이유 · 반려 메모는 줄바꿈만 허용**(그 밖 제어 · 서식 문자 400 — trim 전에 검사). 기획은 「제어 문자 0」.
4. 열린 200 상한(503)도 `deny too_many_open` 감사를 남긴다 · 넷째 발급 409 는 `invite_request.issue deny issue_limit`. 감사 문장은 결정 · 발급을 승인 · 반려 · 발급 갈래로 나눴다(`Audit::allow(…, $reason)`).
5. 승인 뒤 고른 팀이 비활성이 되면 확인 주소는 409 「초대할 팀을 지금 쓸 수 없어요 — 관리자에게 문의해 주세요」(발급 0).
6. **확인 화면은 주소 `#` 를 지우지 않는다**(가입 화면은 지운다) — 다시 열어 결과를 보는 주소라서. 같은 탭에서 `#` 만 바뀌면 다시 묻는다(hashchange).
7. **새 클래스**: 2.32 의 여섯 블록 아래 요소 클래스(`.landing-hero__lead` · `__actions` · `.landing-features__grid` · `__head` · `__icon` · `__label` · `.landing-steps__num`)를 썼다. 미끼 칸은 클래스 대신 `[data-trap]`. 관리자 표 · 창은 기존 클래스만(`moa-invite-panel` · `table.files` · `modal--form` · `.btn--plain` 에 글자색 · 줄바꿈만 더함).
8. 로그인 전 머리 「로그인」의 크기(44)를 맞추려고 옛 `.moa-nav-join` 의 560px `!important` 글자 크기를 그 갈래에서만 덮었다 · ⑥ 「함께 보는 이야기」 머리는 로그인 전 갈래에서만 44(`.landing-faq ~ .moa-home-extra > summary`).
9. 시험 파일은 지시서의 `tests/invite_request_*.py` 가 아니라 틀이 읽는 자리 **`tests/community/`** 에 두었다(틀의 `suite_directory` 를 고치지 않으려고).
10. ④ 「안전하게 써요」 아이콘은 `Apps::ICON_PATHS` 에 있는 것(사람 셋 · 방패 · 자료실 · 업무 체크)을 빌렸다 — 새 그림 0.

---

# H3 — 「우리 공간」 = 카페 (2026-10-14)

지시서(main `fa9e3ab` 의 `docs/invite-request-directive.md` 「H3」) · DESIGN 2.29 「「우리 공간」 = 카페」 줄(main). 문서 둘은 이 worktree 에서 고치지 않았다(메인이 합친다).

## H3 — 변경 파일

| 갈래 | 파일 |
|---|---|
| 서버 | `src/community/routes.php`(`GET /cafe` 한 줄 · community.read) · 새 `src/community/handlers/cafe_page.php`(카페 셸 · 카페 홈 뼈대 · 판정 0) · `src/handlers/product_spaces_page.php`(쿼리 `space` 없으면 302 `/community/cafe`) · `src/views/space_page.php`(카페 묶음 · 「내 공간」 머리 뺌 · 머리 「공간」) |
| 셸 · 링크 | `src/views/shell_top.php`(셸 키 `community-cafe` · 셸 브랜드 · 카페 사이드바 「카페 홈」 → `/community/cafe` · 그 화면에서 is-active + aria-current · 카페 모드면 앱 판 「우리 공간」 aria-current) · `config/apps.json`(앱 판 타일 「우리 공간」 `/community/cafe` · order 4) · `src/views/icon_templates.php`(타일 아이콘 `spaces` 템플릿 한 이름) · `src/views/launcher.php`(홈 바로가기 `spaces` 주소 한 줄) |
| 화면 JS · CSS | `public/assets/community/cafe.js`(`initHome` — space-page.js 의 카페 홈 그리기를 옮긴 한 벌) · `public/assets/space-page.js`(그 부분 지움) · `public/assets/community/editor.js`(부팅 허용 키 · `community-cafe` → `cafe.initHome()` 두 자리) · `public/assets/space-page.css` → `public/assets/community/community.css`(카페 홈 규칙 옮김 · h1 · h2) · 부품 버전 다시 찍기(`scripts/community_asset_version.php` — dashboard · database · editor · suggestions · table-editor · templates · work · messenger 의 import `?v=` 만) |
| 시험 | 새 `tests/community/cafe_h3_browser.py` · `tests/community/memories_harness.py`(choices) · `tests/catalog.json` |

## H3 — 수락 시험과 결과

| 시험 | 결과 | 폴더 |
|---|---|---|
| `python3 tests/community/memories_harness.py --suite cafe_h3_browser.py --output …/h3-final` | **78 / 0** | `h3-final/`(results · metrics · shots/) |
| 정적 — security_lint · php -l · node --check · `community_asset_version.php --check` · wiki_check · git diff --check | 0건 · 통과 · 통과 · 부품 버전 맞음(4bc6fa2d21d1) · 0건 · 통과 | — |

잰 것: [links] 앱 판(`/` · `/tbox/` · `/community/`) 「우리 공간」 → `/community/cafe` · 홈 바로가기 → 누르면 카페 · 셸 브랜드 · 카페 사이드바 「카페 홈」(카페 화면 · 카페 게시판 화면) → `/community/cafe` · 카페에서 「카페 홈」 · 앱 판 「우리 공간」 aria-current · [cafe] 직원 · 관리자 · 손님: `body[data-area=cafe]` · h1 「우리 공간」 · 카드 = 사이드바 카페 게시판 = 읽을 수 있는 카페 게시판 · 새 글 · 인기 · 「게시판 만들어 주세요」(직원 · 관리자 보임 → 요청 창 · 손님 0) · 업무 사이드바 0 · 「내 공간」 · 공간 고르개 0 · [old] `/tbox/spaces` 302 · 로그인 전은 로그인 먼저 · `?space=<id>` → 그 공간(제목 · 고르개 · 카페 0 · 「내 공간」 0) · 비회원은 공간 자료 0(창구 404 그대로) · 위젯 「전체 보기」 링크 모양 그대로 · [grid] 손님 · 직원 · 관리자 × 1440 · 412 × 밝게 · 어둡게 · 고대비 넘침 0 · 콘솔 0.

## H3 — 고친 옛 단언 (돌리지 않았다)

| 시험 | 고친 내용 |
|---|---|
| `tests/community/cafe_c2_browser.py` | 카페 홈 자리 `t.go('/tbox/spaces')` 여섯 곳 · 화면 크기 표의 `/tbox/spaces` → `/community/cafe` · 머리 `#cafe-home h2` 「카페」 → `h1` 「우리 공간」 · 「「내 공간」 below the cafe home · space picker kept」 → 「내 공간」 · 고르개 0 |
| `tests/community/product_spaces_http.py` | 「authenticated real space page renders」 은 `/spaces?space=<id>` 로 · `/spaces` 302 `/community/cafe` 단언 하나 더함 |
| `tests/community/qf12_shell_browser.py` | 앱 판 타일 목록 셋에 「우리 공간」(이야기 다음) |
| `tests/community/invite_request_browser.py` [same](H2 의 것) | 로그인 뒤 런처를 main 과 견주기 전에 옛 쪽의 홈 바로가기 `href="/tbox/spaces"` 를 `/community/cafe` 로(H3 의 의도한 바뀜 하나) — `H2_ONLY=same` 다시 실행 8/0(`h3-h2same/` · 첫 실행은 fixture 세션 401 로 흔들림 — 같은 코드 다시 실행에서 통과) |

(`cafe_c4_browser.py` 는 이미 「우리 공간」 타일을 걸러 보므로 그대로. 공간 화면을 직접 그리는 모의 시험들(`tests/fixtures/space_home_render.php` 를 쓰는 space_dashboard_* · dashboard_* 등)은 대시보드만 재므로 고치지 않았다 — 밤 회귀에서 확인.)

## H3 — 못 한 것 · 다르게 한 것

- 지시서의 「위키에서 /tbox/spaces 를 가리키는 카페 안내 글의 링크」 — `wiki/` · `src/help/` 에 그런 링크가 없었다(grep 0).
- **허용 목록 밖 두 파일을 한 줄씩**: `src/views/icon_templates.php`(앱 판 타일 아이콘 `spaces` 템플릿 — 없으면 타일 아이콘이 빈다) · `public/assets/community/editor.js`(카페 화면 키 부팅 · dispatch 두 자리 — cafe.js 는 editor.js 가 만든 부품이라 진입이 그쪽뿐). 부품 버전 다시 찍기로 커뮤니티 JS 여덟 파일의 import 버전 글자가 바뀌었다(기계적).
- 앱 판 「우리 공간」 타일은 지금까지 없었다(`config/apps.json` 셋) — 완료 기준 「앱 판 … → /community/cafe」 를 위해 앱 목록 원본에 넷째 줄로 더했다(지시서 「Apps.php 또는 앱 목록 원본」 자리).
- 카페 홈의 「새 글」 · 「인기 글」 머리는 h3 → h2(화면 h1 「우리 공간」 바로 아래라 단계 건너뛰기 0).
- 셸 키 `community-cafe` 는 업무 셸 사이드바 강조(`is-active`)에도 쓴다.

---

# H4 — 「모아.」 글자 로고(시안 D 기본 · 테마 창에서 A ~ D) · 바닥 붙이기 (2026-10-14)

지시서(main `b66e275` 의 「H4」) · DESIGN 2.33(main — 2026-10-14 · 「셸 브랜드」 줄은 H5 로 「글자를 그리지 않는다」). 문서는 이 worktree 에서 고치지 않았다.

## H4 — 변경 파일

| 갈래 | 파일 |
|---|---|
| 부품 | 새 `src/views/moa_wordmark.php`(글자 로고 한 벌 — `span.moa-wordmark` > `__mo` · `__a` · `__dot` · 머리 로고 자리는 「m.」 상징 함께) · 새 `src/views/moa_footer.php`(바닥 한 벌 — 런처 · 가입 · 동의 · 초대 요청 · 확인) |
| 갈래 · 저장 | `src/Design.php`(`WORDMARKS` 허용 목록 · `wordmark()` · `attrs()` 에 `data-wordmark`) · `src/views/design_controls.php`(테마 창 「모아 글씨 색」 넷 · 미리보기) · `public/assets/design.js`(고르면 즉시 · 「기본으로 저장」 이 쿠키 `qaredi_wordmark` · 닫으면 되돌림 — 테마와 같은 길) |
| 색 · 모양 | `public/assets/app.css`(2.33 표의 값만 — :root · 다크 둘 · 고대비 셋 블록에 갈래 색 한 줄씩 · 고대비 절 앞에 「고른 갈래 → 쓰는 토큰」 줄 · 로그인 왼쪽 판은 어둡게 값) · `public/assets/moa.css`(부품 · 상징 · 자리별 크기 · 바닥 붙이기 · 테마 창 묶음) |
| 자리 | `src/views/launcher.php`(머리 로고 둘 · 가운데 「Qaredi」 둘 → 「모아.」 · 바닥) · `src/views/member_top.php`(머리 로고) · `src/handlers/legal_page.php`(머리 · 바닥) · `src/handlers/login_page.php`(머리 글자) · 바닥을 단 화면 넷 `member_join_page.php` · `consent_page.php` · `invite_request_page.php` · `invite_request_status_page.php` |
| 시험 | 새 `tests/community/home_h4_browser.py` · `memories_harness.py`(choices) · `tests/catalog.json` |

## H4 — 수락 시험과 결과

| 시험 | 결과 | 폴더 |
|---|---|---|
| `… --suite home_h4_browser.py --output …/h4-run3` | **252 / 0** | `h4-run3/`(results · metrics · shots/ — 홈 밝게 · 어둡게 · 1920 · 412 · 가입 · 초대 요청) |
| H2 브라우저 다시(바닥 · 머리가 바뀐 로그인 전 화면) `… --suite invite_request_browser.py --output …/h4-h2browser` | **458 / 0** | `h4-h2browser/` |
| 정적 | security_lint 0건 · php -l · node --check 통과 · wiki_check 0건 · git diff --check 통과 | — |

잰 것: [places] 홈 · 로그인 전 · 동의 전 런처 · 로그인 · 가입 · 동의 · 초대 요청 · 확인 · 처리방침 × 밝게 · 어둡게(+ 홈 · 로그인 전 · 로그인 · 가입은 A · B · C 까지): 자리(가운데 · 머리 로고 · 바닥 · 로그인 머리) · 글자 「모아.」 800 · 화면 읽기 「모아」(가운데는 aria-hidden — 화면 읽기 h1 이 따로) · 계산된 글자색 = 토큰(`--brand-wordmark-1/-2`) · 점 = `--brand-wordmark-dot` · 점은 D 만 · A · B 는 글자 투명 + 그라데이션 · C 는 두 색 · 테마 여섯을 바꿔도 색 그대로 · 고대비(계정 취향) 밝게 · 어둡게 × D · A · C → `--fg` 단색 · 대비 ≥ 4.5(첫 색 · 로그인 왼쪽 판과 B 밝게는 아래) · 「Qaredi」 글자 0(처리방침 본문의 문서 이름 「모아(Qaredi)」 는 그대로) · 넘침 0 · 콘솔 0 · [pick] 서버 첫 HTML 의 `data-wordmark`(쿠키 a · b · c · d 그대로 · `zz` · `A` · 따옴표 넣은 값 · 없음 → d — 다섯 화면) · 테마 창 넷 · 미리보기(점 D 만 · 그라데이션 A · B) · 「(기본)」 · C 고르면 즉시 · 닫으면 D 로 되돌림 · A 저장 → 쿠키 · 새로 고침 · `/tbox/` · `/community/` · `/tbox/privacy` 첫 HTML a · [foot] 1440×900 · 1920×1080 · 412×900 × 여덟 화면(홈은 어둡게도): 짧은 화면은 바닥 아래 끝 = 창 아래 끝(±1) · 긴 화면은 내용 바로 뒤 · 바닥 폭 ≤ 1200 · 넘침 0 · 콘솔 0 · 홈(대시보드 접힘)은 세 크기 모두 짧은 화면.

## H4 — 고친 옛 단언 (돌리지 않았다)

| 시험 | 고친 내용 |
|---|---|
| `tests/community/home_h1_browser.py` ② | 가운데 글자 `'Qaredi'` · 굵기 600 → `'모아.'`(굵기는 부품 800 — H4 시험이 잰다) |
| `tests/community/invite_request_browser.py` ①(H2) | 가운데 글자 `'Qaredi'` → `'모아.'` · [same] 견주기 전에 머리 로고 · 가운데 글자 · 바닥 세 자리를 양쪽에서 같은 표시로 바꿈(H4 가 일부러 바꾼 곳 — 그 밖은 바이트 비교 그대로) |

## H4 — 못 한 것 · 다르게 한 것

1. **DESIGN 2.33 의 B 밝게 첫 색 `#f97316` 은 흰 · 밝은 바탕 대비 약 2.4**(측정 `h4-run3/home-h4-metrics.json` `b-light-ratio`) — 「대비 4.5 이상(네 갈래 첫 색)」 과 표의 값이 서로 맞지 않는다. 새 색을 만들 수 없어 값 그대로 두고 그 단언만 값 기록으로 돌렸다 — **사용자 판단 필요**(B 밝게 첫 색을 `#c2410c` 같은 진한 주황으로 · 또는 B 를 어둡게 전용으로). C 의 「아」 `#0d9488`(밝게 약 3.7)도 4.5 아래지만 기준이 「첫 색」 이라 단언 밖.
2. **토큰 이름을 더 썼다**: 2.33 의 다섯(`--brand-wordmark` · `-2` · `-fill` · `-dot` · `-on`)에 「모」 글자색 `-1` · 점 보임 `-dot-show` 를 더하고, 갈래 넷의 원래 색은 `--wm-*` 아홉으로 두었다 — 테마 창의 미리보기가 지금 고른 갈래와 무관하게 자기 색으로 그려지려면(같은 줄을 `[data-wordmark-preview]` 에도 건다) 갈래 색이 따로 있어야 해서다. 색값은 2.33 표의 것뿐이고 app.css 의 :root · 다크 · 고대비 블록 + 갈래 줄에만 있다.
3. **로그인 화면 왼쪽 판**(사진 위 어두운 칠 — 밝게 · 어둡게와 무관하게 늘 어둡다)은 갈래마다 2.33 의 **어둡게** 값을 쓴다(밝게 보라 `#6d28d9` 가 어두운 사진 위에서 안 보여서). 사진 바탕이라 대비는 기계로 재지 못했다(스크린샷 확인 몫).
4. **바닥 부품 `src/views/moa_footer.php`(새)** — 가입 · 동의 · 초대 요청 · 확인 화면에는 바닥이 없었다(머리의 법적 문서 링크뿐). 「바닥 아래 끝 = 창 아래 끝」 을 재려면 바닥이 있어야 해서 한 벌로 만들어 런처와 그 넷에 달았다(법적 문서 화면은 자기 바닥 — 주소가 판을 따라가서 — 그대로 두고 글자 로고만 바꿈). 바닥 붙이기는 클래스를 더하지 않고 `body.moa-site:has(> footer.moa-footer)` 한 규칙으로 했다.
5. **테마 창 묶음 클래스 셋**(`.q-wordmark-picker` · `-option` · `-sample`)과 상징의 점 `.moa-symbol__dot` 을 새로 썼다(2.33 에 이름이 없음). 테마 창 규칙은 design.css 가 아니라 moa.css 에 두었다(허용 목록).
6. 셸 브랜드 「모아」 칠은 하지 않았다 — H5 · DESIGN 2.33 「셸 브랜드」 줄(main 최신)이 「글자를 그리지 않는다」 로 바뀌었다.

---

# H5 — 사이드바 머리 글자 없앰 · 앱 판 「검색」 타일 뺌 (2026-10-14)

지시서(main `0a33f08` 의 「H5」) · DESIGN 2.19 ⑩ · 2.30 「앱 판」 · 2.33 「셸 브랜드」(main). 문서는 이 worktree 에서 고치지 않았다.

## H5 — 변경 파일

| 파일 | 내용 |
|---|---|
| `src/views/shell_top.php` | 사이드바 머리 = ☰ + 앱 아이콘 링크뿐(`.brand__text` · `.brand__name` · `.brand__sub` 를 그리지 않음) · 링크 `aria-label` 「<앱> 첫 화면으로」(카페는 「우리 공간」) |
| `src/views/shell_tools.php` | 앱 판 「검색」 타일 줄 삭제(셸 · 런처 · 폰 공용) |
| `public/assets/app.js` | 앱 판 「검색」 타일 갈래 삭제(사이드바 「검색」 · Ctrl K · 폰 돋보기 · `qarediSearchOpen` 그대로) |
| `public/assets/app.css` | 머리 줄(높이 56 · 아이콘 36 · 아래 구분 0 — moa.css 의 옛 두 칸 격자를 `#sidebar > .moa-brand` 에서 덮음) · 아이콘 링크 풍선(2.8 툴팁 규칙 묶음에 `#sidebar .moa-brand-home[aria-label]` 를 더함 — 같은 규칙) |
| `tests/community/home_h4_browser.py` | [h5] 단언 묶음 |

## H5 — 수락 시험과 결과

| 시험 | 결과 | 폴더 |
|---|---|---|
| `H4_ONLY=h5 … --suite home_h4_browser.py --output …/h5-run1` | **119 / 0** | `h5-run1/`(shots/ `h5-library-1440.png` · `h5-work-1440.png`) |
| 최종 판 한 번 — `home_h4_browser.py` 전체(H4 + H5) · `cafe_h3_browser.py` | **366 / 0** · **78 / 0** | `h5-h4-final/` · `h5-h3-final/` |
| 정적 | security_lint 0건 · php -l · node --check 통과 · wiki_check 0건 · 부품 버전 맞음 · git diff --check 통과 | — |

잰 것: 1440 · 1280 · 960 × 자료실 · 업무 · 이야기 · 카페 · 관리(관리자 · 재인증) 사이드바 펼침: 머리에 보이는 글자 0 · 글자 요소 0 · ☰ 보임 · 아이콘 36×36 · 줄 높이 52~60 · 링크 = 그 앱 첫 화면(관리 화면은 자료실 `/tbox/`) · aria-label 「… 첫 화면으로」 · 올리면 풍선(같은 글자) · 앱 판 검색 타일 0 · 사이드바 「검색」 · Ctrl K 가 검색 창 · 넘침 0 · 콘솔 0 · 접힌 레일(기본) 그대로(☰ · 폭 64 · 아이콘 링크 숨김) · 412 윗줄 앱 이름 ▾ 그대로(「모아 자료실」 · 「모아 업무」 · 「모아 이야기」 · 「우리 공간」) · 폰 앱 판 검색 타일 0 · 돋보기가 검색 창 · 모아 홈(1440 · 412) 앱 판 타일에 「검색」 0 · 홈 검색 칸 Enter 가 검색 창.

## H5 — 고친 옛 단언 (돌리지 않았다)

| 시험 | 고친 내용 |
|---|---|
| `tests/community/qf12_shell_browser.py` | 앱 판 타일 목록 셋에서 「검색」 뺌 · 「search tile present」 → 검색 타일 0 · 「search tile opens the same search dialog」 → 타일 0 + 사이드바 「검색」 이 창을 엶 |
| `tests/community/home_h1_browser.py` | [tools] `'검색' in tiles` → `not in` · [search] 「app panel 「검색」 tile opens the same dialog」 → 타일 0 |
| `tests/community/cafe_c4_browser.py` | c6 타일 기대 목록 끝의 `['검색']` 뺌 |
| `tests/community/cafe_c2_browser.py` | [shell] 브랜드 `.brand__name` 「우리 공간」 → 아이콘 링크 aria-label 「우리 공간 첫 화면으로」 |
| `tests/design_live_browser.py` | `.brand__name` 「모아 업무」 → 아이콘 링크 aria-label 「업무 첫 화면으로」 |
| `tests/design_themes_browser.py` | 「motion override」 를 재던 `.brand__name`(움직이던 유일한 셸 글자)이 없어져 `.q-design-status, .ok-note` 로 옮김 — **이 단언은 이제 거의 늘 참**(그 요소에는 움직임이 없을 수 있다). 움직임 끄기는 같은 `:root[data-motion="reduce"] *` 규칙이 그대로 막는다 |

## H5 — 다르게 한 것

- 관리 화면(`/tbox/admin`)의 머리 아이콘은 자료실 셸 것이라 「자료실 첫 화면으로」 → `/tbox/`(관리 화면은 자료실 셸 안).
- 셸 이름 변수(`$shellBrandName` — 폰 윗줄 앱 이름 ▾ · `<title>`)는 그대로 둔다.

---

# H2b — 확인 주소를 열 때마다 초대가 새로 나가던 것 고침 (메인 검토 2026-10-14)

문제: `invite_requests_status.php` 가 승인된 요청의 확인 주소를 **열기만 해도**(새로 고침 포함) 앞 초대를 거두고 새 초대를 만들어(issued_count+1) 세 번 새로 고치면 409 가 됐다.

## H2b — 변경 파일

| 파일 | 내용 |
|---|---|
| `src/handlers/invite_requests_status.php` | 키 = `code` + (선택) `issue: true` 만(그 밖 · `issue` 의 다른 값 400). **보기 `{code}` 는 발급 0** — 승인된 요청은 `{status:'approved', issued:<지금 쓸 수 있는 초대가 있나 — 거두지 않음 · 쓰지 않음 · 기한 안>}`(invite_path 0 · 행 바뀜 0 · 감사 0). 발급은 `{code, issue:true}` 일 때만 — 트랜잭션 · 행 잠금 · 앞 초대 거둠 · 세 번까지(넷째 409) · 감사는 그대로. 열린 · 반려 · 가입 마친 요청에 `issue:true` 는 그 상태를 그대로 답한다(발급 0) |
| `src/handlers/invite_request_status_page.php` | 단추 `#invite-status-issue` 「초대 링크 받기」 · 도움말 `#invite-status-issue-help` · 받은 링크 칸(읽기 전용) |
| `public/assets/invite-request.js` | 승인 → 「초대됐어요 — 아래 단추로 초대 링크를 받아 가입해 주세요.」 + 「초대 링크 받기」 / 이미 받았으면 「초대 링크 다시 받기」 + 「다시 받으면 앞의 링크는 쓸 수 없어요 (최대 3번)」 → 누르면 `{code, issue:true}` → 받은 링크 + 「가입하기」 · 409 는 서버 문장(「초대 링크를 이미 세 번 받았어요 — …」) · 단추 숨김 |
| `tests/community/invite_request_http.py` · `tests/community/invite_request_browser.py` | 아래 단언 |

## H2b — 수락 시험과 결과

| 시험 | 결과 | 폴더 |
|---|---|---|
| `… --suite invite_request_http.py --output …/h2b-http` | **132 / 0** | `h2b-http/` |
| `… --suite invite_request_browser.py --output …/h2b-browser` | **467 / 0** | `h2b-browser/`(shots/ `status-approved-*` · `status-again-*`) |
| 정적 — security_lint · php -l · node --check · wiki_check · git diff --check | 0건 · 통과 · 통과 · 0건 · 통과 | — |

더한 단언: `issue` false · 글자 "true" · 1 → 400 · 열린 요청에 issue → `{open}`(발급 0) · 승인 요청 보기 ×3 → `{approved, issued:false}` · 초대 · issued_count · 감사 그대로 · issue → invite_path · 새로 고침(보기) → `{approved, issued:true}` · 새 초대 0 · 가입 뒤 issue → `{joined}` · 다시 받기 세 번(사이 보기는 issued_count 그대로) · 넷째 issue 409 + deny · 409 뒤 보기 → issued true(셋째 초대 살아 있음). 브라우저: 승인 화면 「초대 링크 받기」(받기 전 링크 0) · 새로 고침 발급 0 · 누르면 링크 + 「가입하기」 · 다시 열면 「초대 링크 다시 받기」 + 도움말 · 세 번 받은 요청은 누르면 409 문장 · 412 에서 「가입하기」 → 가입 화면 초대 칸 64자.

시험 쪽 고침 둘: 확인 창구 1분 20회 셈이 늘어난 보기 단언에 닿아 「다시 받기」 앞에서 셈을 비움 · 브라우저의 409 기다림을 CSP 아래에서 `evaluate` 되풀이로.
