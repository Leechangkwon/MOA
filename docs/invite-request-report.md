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
| 브라우저(격리) | `… --suite invite_request_browser.py --output …/h2-browser-final` | 아래 「최종」 줄 | `h2-browser-final/`(results · metrics · shots/) |
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
