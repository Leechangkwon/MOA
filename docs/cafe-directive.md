# 지시서 카페 — 「우리 공간」 카페 · 자유 · 익명게시판 · 신고 · 작성자 확인 (2026-10-14)

사용자 요청(2026-10-13): 「우리 공간은 이름은 좋은데 아이콘이 우리 공간처럼 안 느껴짐. 자유게시판, 익명게시판 등 업무와 관련되지 않은 네이버 카페나 다음 카페 같은 기능을 넣고 싶음」. 결정 CF-1 ~ CF-7(2026-10-13 · 14).
기준 문서(**정답지**): 기획 `/var/www/qaredi/community/cafe-plan.md` · **`/var/www/qaredi/community/ARCHITECTURE.md` 25장**(둘이 다르면 25장) · `DESIGN.md` **2.29**(+ 2.3 · 2.4 · 2.6 · 2.7 · 2.21 · 6장) · `ARCHITECTURE.md` 13장 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장.
단위: 한 기능 묶음 두 조각 — **C1 보안 바닥**(서버 · 스키마 · 판정 · 가림 · 신고 · 확인) → 메인 검토 → **C2 화면**. 시험은 「한 기능에 한 번」 — 구현 에이전트는 **그 조각의 수락 시험만**. 기준 판 시험 · 다른 회귀 · 같은 묶음 반복은 하지 않는다(메인이 최종 판에서 한 번).

## 공통

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-cafe-20261014`**(브랜치 `feat/cafe` — 메인이 만든다). 결과 **`/home/changkwon/qaredi-cafe-results-20261014/`**. 운영 · 운영 DB · 다른 worktree 읽기 · 쓰기 0(기준 문서만 읽기) |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <폴더>` — 포트 8768 한 번에 하나(시작 전 `ss -ltn \| grep 8768`). 새 스위트는 `--suite` choices 에, 새 마이그레이션은 `migrations` 목록 **끝**에(그 두 자리만) |
| 브라우저 | `. /home/changkwon/qaredi-cafe-results-20261014/env.sh` |
| 계정 | 시험은 틀이 만드는 합성 계정 · 검토 표식 UA `tbox-review/<날짜>` — 시드 계정 0 |
| 커밋 | 파일 지정(`git add -A` 금지) · 한글 · 첫 줄 `Qaredi 카페 C1: …` / `C2: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |
| 디스크 | 스크린샷은 필요한 것만 |

## C1 — 보안 바닥 (서버)

```
목표        : 게시판에 자리(업무 · 카페)와 익명 종류를 더하고, 익명게시판 글 · 댓글의 쓴 사람을 모든 응답에서 별칭으로 바꾸며,
              신고 · 관리자 처리 · 작성자 확인(재인증 + 사유 + 감사) · 게시판 요청 · 카페 홈 창구를 서버에 만든다.
요구사항    : community ARCH 25장 전부(25.1 원칙 ~ 25.8) — 읽고 시작할 것. 조사 근거(파일:줄)는 25장 표와 cafe-plan 2장.
수정 파일   : DB — db/migrations/2026-10-14_community_cafe.sql(새 — 25.2 열 · CHECK · 25.4 anon_aliases · 25.5 reports · 25.7 board_requests ·
                 알림 종류 CHECK 누적(2026-10-19_qf12_work.sql 방식) · GRANT · 자유게시판 area 이동 · 익명게시판 행 — 두 번 돌려도 같음 · 되돌리기 주석) ·
                 db/community/schema.sql · db/community/grants.sql(같은 내용을 정본에) ·
              판정 — src/community/CommunityAuthz.php(BOARD_KINDS · decideBoardWrite · decidePublish · decideSuggestionCreate 갈래 · 새 decideReportCreate ·
                 decideReportManage · decideAnonReveal · decideBoardRequest — 25.1 의 「얇은 갈래」만) ·
              가림 — src/community/anon_helpers.php(새 — 25.4 헬퍼 다섯 + community_anon_audit_detail) · src/community/routes.php(require · 라우트 줄 · community_page_row_sql 읽기 열(게시판 kind · area) ·
                 숨김 쓰기 공용 함수 · 게시판 만들기 공용 함수 · 검색 댓글 SQL 의 이름표) ·
                 handlers: pages_get.php · board_pages_list.php · src/community/BoardCursor.php · board_pages_create.php(별칭 0) · comments_create.php(별칭 · 멘션 끔) ·
                 comments_list.php · search.php · notifications_list.php · history.php · versions_list.php · version_get.php · mention_candidates.php ·
                 export_helpers.php · procedures_list.php · pages_properties.php(검증 거부) · pages_update.php(자료실 참조 거부) · attachments_upload.php(그림 메타 지움) ·
                 pages_hide.php · comment_hide.php(공용 함수로 옮김 — 입력 · 응답 · 감사 그대로) · boards_create.php(area · anonymous) · boards_list.php(?area) ·
                 감사 detail 표식이 필요한 커뮤니티 핸들러(구현자가 grep 으로 목록화 — 보고서에) ·
                 새 handlers: reports_create.php · reports_list.php · report_resolve.php · report_reveal.php · board_requests_create.php · board_requests_list.php ·
                 board_request_decide.php · cafe_home.php ·
              감사 — src/Audit.php(SUMMARY_TEMPLATES 에 새 동작 다섯만) · src/handlers/audit_list.php(25.6 가림 · 필터 제외 — 화면 · 내보내기 같은 함수) ·
              그 밖 — src/community/Memories.php(stripPhotoMetadata 가시성만 public static) ·
              시험 — tests/community/cafe_c1_http.py(새 수락) · tests/community/memories_harness.py(두 자리) · tests/catalog.json ·
                 옛 시험이 바뀐 동작(boards_list 항목 키 · BOARD_KINDS 개수 등)으로 깨지면 그 파일의 그 단언만(이유를 보고서에) ·
              문서 — docs/cafe-report.md(새 — C1 절)
              그 밖 0. 보안 핵심 파일은 위에 적은 자리만: Audit.php(틀 다섯) · db/**(위 셋). Authz.php · Auth.php · Http.php · Db.php · Upload.php · Doc.php · public/index.php 0.
금지 패턴   : SQL 문자열 결합 · innerHTML · 판정 두 벌(익명 여부 판정은 community_page_is_anon 하나) · 가린 행으로 판정 · 감사 · 총량 계산 ·
              응답에 익명 대상의 사용자 id · username · 실제 display_name 싣기(본인 포함 — 본인은 is_me 만) · 작성자 확인 결과 저장 ·
              화면에서만 막고 서버가 받아 주는 「끈 기능」 · N+1(목록의 이름표는 질의 하나) · 기존 hide 창구의 입력 · 응답 모양 바꾸기 · git add -A.
완료 기준   : cafe_c1_http(격리) — 계정: 글쓴이 W(아이디 · 이름에 카나리아 문자열 예 `anoncanary7f3a` · `익명카나리아`) · 댓글 쓴 사람 A · B · 다른 직원 E · 관리자 M · 손님 G.
              ① 스키마: 자유게시판 area=cafe · 익명게시판 1행 · 이주 두 번 같음 · anonymous+work 조합 CHECK 거부 · tbox_app 의 reports DELETE 권한 0 · anon_aliases UPDATE · DELETE 0
              ② 카나리아: W 가 익명게시판에 글 · A · B · W 가 댓글 · 답글 · 도움됐어요 · 해결 → E 와 M 시점으로 25.4 표의 **모든 자리**(pages_get · 게시판 목록 · 런처 피드에 쓰는 같은 목록 ·
                 comments_list · search · notifications_list(답글 · 내 글 댓글 알림) · history · versions_list · version_get · export(md · html) · page_print · cafe 홈 · audit_list(M · 재인증) ·
                 reports_list) 응답 본문에 카나리아 아이디 · 이름 0 · W/A/B 의 사용자 id 키 0 · 이름표 「글쓴이」 · 「익명1」 · 「익명2」(A 가 먼저면 1) · 같은 사람 같은 번호 · W 시점은 is_me true
              ③ 끈 기능: 개인 페이지를 익명게시판에 게시 403 · 수정 제안 403 · 검증 400/403 · mention_candidates 403 · `@E` 댓글 → 알림 0 · 절차 목록에 0 · 자료실 참조 본문 저장 400 · 같은 본문을 자유게시판엔 200
              ④ 일반 카페 게시판(자유게시판)은 이름이 그대로(가림이 익명게시판에만) · 업무 게시판 · 개인 문서 응답은 전과 같은 키(바뀐 것 0)
              ⑤ 신고: E 가 W 글 신고 201 · 같은 것 다시 409 · W 가 자기 글 신고 400 · G 403 · 못 보는 글 404 · 업무 게시판 글 400 · 하루 21번째 429 · reports_list E 403 · M 재인증 없으면 403(reauth) ·
                 resolve hide 메모 없으면 400 · hide → 글 숨김 · 같은 대상 열린 신고 모두 hidden · W 에게 moderated 알림(actor 없음) · reject → 그 신고만 · 감사 행
              ⑥ 확인: M · 재인증 · 사유 20자 → 200 실제 이름 · 사유 19자 400 · 재인증 없음 403 · 자유게시판 대상 신고 400 · 신고 없는 익명 글 확인 창구 없음(신고 id 로만) · E 403 ·
                 감사 anon.reveal allow/deny · W · 신고자 알림 0 · 응답 뒤 어떤 창구에도 실제 이름 0(②를 다시 한 자리만)
              ⑦ 감사 화면: 익명 대상 행 actor · ip · login_ref 가림 · `actor=<W 아이디>` 필터 결과에 익명 행 0 · `ip=` 필터도 0 · report.* · anon.reveal 행은 M 이름 그대로
              ⑧ 게시판 요청: 직원 201 · 열린 요청 4번째 429 · 손님 403 · M 알림 · decide create → area=cafe 게시판 · 요청자 알림 · reject 메모
              ⑨ 카페 홈: 읽을 수 있는 카페 게시판만(team 등급 다른 팀 0) · recent 숨김 · 지운 글 0 · 질의 수 고정(행 10 · 100 에서 같은 수 — 틀의 질의 수 세기 또는 EXPLAIN 기록)
              ⑩ 그림: 익명게시판 글에 GPS EXIF 붙은 JPEG 첨부 → 저장된 파일에 EXIF 0 · 자유게시판은 전처럼
              ⑪ 정적: security_lint 0 · php -l(바뀐 PHP) · git diff --check · 마이그레이션 두 번(틀)
              그 밖 시험(stage* · qf12_* · board 시험 등)은 **돌리지 않는다** — 메인이 최종 판에서.
보고 형식   : docs/cafe-report.md C1 절 — 변경 파일 / 감사 표식을 단 핸들러 목록 / 돌린 수락 시험과 결과(결과 폴더) / 못 한 것 / ARCH 25장과 다르게 한 것. 짧게.
```

## C1b — 검토 보정 (메인 검토 2026-10-14 · C2 를 시작하기 전에 · 같은 worktree)

C1(`dabdea8`)의 검토 결과 — 설계대로 된 것: 이름표 · 안전망 · 작성자 확인(감사 먼저) · 감사 화면 가림 · 이주(운영 알림 CHECK 는 하나 — 2026-10-19 방식 그대로 성립). 고칠 것 둘:

```
목표        : 익명게시판 글의 AI 넣기 감사 행에도 익명 표식을 달고, 수락 시험의 이름 단언을 댓글 쓴 사람까지 넓힌다.
수정 파일   : src/community/ai_helpers.php(`ai.insert` 의 detail 을 community_anon_audit_detail 로 — 한 줄) · tests/community/cafe_c1_http.py · docs/cafe-report.md(C1b 절)
완료 기준   : ① ai.insert 를 익명게시판 글에 부르면 감사 행 detail.anon_board = true · 감사 목록 `actor=<W>` 필터에 그 행 0
                 (AI 열쇠 없이 — 합성 ai_log 행(status ok · feature summary · output_text)을 틀에서 넣고 `POST /community/api/pages/{id}/ai-insert` 를 부른다. 이 길이 틀에서 막히면 「미검증」으로 보고)
              ② ② 의 카나리아 단언에 A · B 의 아이디 · 표시 이름도 더함(E · M 시점 모든 자리 — 본인 시점 제외)
              ③ cafe_c1_http 한 번 · php -l · security_lint 0
```

## C2 — 화면 (C1 검토 통과 뒤 · 같은 worktree)

```
목표        : 「우리 공간」 맨 위에 카페 홈(게시판 카드 · 새 글 · 인기 글 · 게시판 요청)을 놓고, 카페 게시판 · 글을 「우리 공간」 셸로 보여 주며,
              익명 표시 · 신고 · 관리자 신고함 · 작성자 확인 · 게시판 요청 화면을 만든다.
요구사항    : DESIGN 2.29 · 6장 체크리스트 · ARCH 25장(창구 모양) — 읽고 시작할 것. 새 색값 0 · 새 클래스는 2.29 의 다섯만.
수정 파일   : 서버 화면 — src/views/space_page.php(카페 홈 자리) · src/community/handlers/board_view.php · page_view.php(data-area — 읽기 판정 통과 때만) ·
                 src/community/handlers/reports_page.php · board_requests_page.php(새 — 관리자 화면 · 라우트는 routes.php 에 줄만) · src/views/shell_top.php(카페 사이드바 묶음) ·
              JS — public/assets/space-page.js(카페 홈) · public/assets/community/editor.js(loadBoards area=work · 카페 셸 갈래 · 익명 표시 · 신고 메뉴 · 자료실 탭 0) ·
                 public/assets/community/work.js(칸 댓글 익명 표시 — 칸이 게시판 글을 열 때만) · public/assets/community/mention-input.js(끄기 옵션 하나) ·
                 public/assets/community/cafe.js(새 — 신고 모달 · 신고함 · 확인 모달 · 요청 모달) · public/assets/space-page.css · public/assets/community/community.css · public/assets/app.css(필요한 최소) ·
                 커뮤니티 JS import 버전 줄(`php scripts/community_asset_version.php` 인자 없이) ·
              시험 — tests/community/cafe_c2_browser.py(새 수락) · memories_harness.py(choices) · tests/catalog.json · 바뀐 화면을 재던 옛 단언이 깨지면 그 단언만(이유를 보고서에) ·
              문서 — wiki/community/guide-cafe.json(새 — 카페 · 익명 · 신고 · 요청 사용법, 「화면만 익명 — 신고가 들어오면 관리자가 확인할 수 있어요」 문장 필수) · 위키 index · docs/cafe-report.md(C2 절)
              그 밖 0. 보안 핵심 파일 0(C2 는 서버 판정을 바꾸지 않는다 — 판정이 모자라면 멈추고 보고).
금지 패턴   : innerHTML · 인라인 style · 새 색값 · 작성자 확인 결과를 DOM 밖(저장소 · 주소 · 콘솔)에 남기기 · 모달 닫은 뒤 이름이 DOM 에 남기 · 부품 두 벌(칸 · 모달 · 멘션) · git add -A.
완료 기준   : cafe_c2_browser(격리 · 1440 · 412): 「우리 공간」 카페 홈(카드 · 새 글 · 인기 · 빈 상태) · 「내 공간」 아래 그대로 · 카페 게시판 · 글 → 카페 사이드바(업무 트리 0) ·
                 업무 사이드바 게시판 묶음에 자유게시판 0 · 익명 글 머리 「글쓴이」 · 댓글 「익명N」 · 내 것 「(나)」 · 안내 한 줄 · @ 목록 0 · 자료실 탭 0 ·
                 신고 모달(라디오 · 409 문구 · 토스트) · 신고함(관리자만 · 탭 · 칸 · 메모 필수 · 숨기기 · 반려) · 작성자 확인(재인증 → 사유 20 → 이름 → 닫으면 DOM 에서 0) ·
                 게시판 요청 → 관리자 만들기 → 카페 홈에 카드 · 폭 여섯 × 밝게 · 어둡게 · 고대비 넘침 0 · 콘솔 0 · 누름 자리 < 44 0
              정적: security_lint 0 · php -l · node --check · asset_version --check · wiki_check · git diff --check
보고 형식   : docs/cafe-report.md C2 절 — 변경 파일 / 수락 시험과 결과 / 못 한 것 / DESIGN 과 다르게 한 것. 짧게.
```

## C3 — 오른쪽 위 「앱」 단추 (사용자 지적 2026-10-14 · C2 뒤 · 같은 worktree)

사용자(스크린샷): 「ㅁ 9개가 각 앱 아이콘보다 작고 시각적으로 못생김 — 좀 더 좋은 아이콘으로, 좀 더 크게, 색상으로 강조해서 클릭해야 하는 것임을 직관적으로 알 수 있게」.

```
목표        : 오른쪽 위 앱 격자 단추를 2×2 둥근 네모 아이콘 24 · 글자 15/600 · 높이 44 · 파란 강조 바탕으로 바꿔 누르는 단추임이 바로 보이게 한다.
요구사항    : DESIGN 2.28 「앱 단추」 줄(2026-10-14 고침 — main 의 DESIGN.md 를 읽는다) · 2.16 고대비 · 6장.
수정 파일   : src/Apps.php(ICON_PATHS 에 'apps' 한 줄 — 채움 path) · src/views/shell_top.php(summary 의 data-icon 과 클래스만) · public/assets/app.css(2.28 값 · 채움 아이콘 규칙) ·
              app.js 가 아이콘을 그리는 곳이 채움을 지원하지 않으면 그 함수에 채움 갈래 하나 · tests/community/cafe_c2_browser.py(C3 단언 더함) · docs/cafe-report.md(C3 절)
금지 패턴   : 새 색값 · 인라인 style · 아이콘 두 벌 · 옛 'grid' 를 바꾸기(다른 자리가 씀)
완료 기준   : 1440 · 1280 · 960 에서 summary 높이 44 · 아이콘 24 · 바탕이 --accent-soft 계산값 · 글자 대비 4.5 이상(밝게 · 어둡게 · 고대비 · 열림 상태 포함) ·
              타일 아이콘(20)보다 단추 아이콘이 큼 · 412 에서 summary 0(그대로) · 안 읽은 점 자리 그대로 · 넘침 0 · 스크린샷 3장(밝게 · 어둡게 · 열림)
```

## C4 — 편집 중 그림 「검사 중」 · 남긴 댓글의 멘션 표시 (사용자 지적 2026-10-14 · C3 뒤 · 같은 worktree)

사용자(스크린샷 — 작업 칸): 「업무에서 이미지 삽입 시 계속 검사 중임 — 편집 중에는 이미지를 어디에 어떻게 배치할지, 이미지를 보며 글을 쓴 뒤 편집 완료했을 때 검증하면 될 것 같은데」 ·
「댓글을 @ 해서 태그했을 때만 파란 글씨가 뜨고, 정작 작성된 글은 검은색으로 태그가 된 건지 직관적으로 보여지지 않음 — 실패인지 오타인지 제대로 된 건지 구분 필요」.
메인 조사: 운영 첨부 3개 모두 DB 는 `clean`(검사 cron `scan_pending.php` 1분마다) — **편집기가 올린 순간의 pending 을 기억한 채 다시 묻지 않는 화면 버그**. 댓글은 평문 저장 · 멘션은 알림에만 해석 · `comments_list` 에 해석 결과 없음.

```
목표        : 올린 사람은 편집 중 그림을 바로 보고(브라우저 사본) 검사가 끝나면 저절로 서버 그림으로 바뀌며, 남긴 댓글의 @아이디 가 확인됨(파랑) · 못 찾음(빨강)으로 보이게 한다.
요구사항    : DESIGN 2.26 「검사 중 미리보기」 줄 · 2.27 「남긴 댓글의 멘션」 줄(main 의 DESIGN.md — 2026-10-14) · 커뮤니티 ARCH 24장(첨부 판정 그대로).
수정 파일   : 서버 — src/community/handlers/attachments_list.php(새 — GET /api/pages/{id}/attachments · 판정 decideAttachmentRead · 응답 {attachment_titles} 만 · 감사 0) ·
                 src/community/routes.php(라우트 줄 하나 · community.read) · src/community/handlers/comments_list.php(mention_status — 목록 안 아이디만 · 질의 고정 · 익명게시판 빈 지도) ·
              화면 — public/assets/community/editor.js(올리기 → data: 사본 · 그림 NodeView 가 사본을 쓰는 갈래 · 확인 반복 · 저장 뒤 한 번 · 댓글 멘션 표시) ·
                 public/assets/community/work.js(칸 댓글 멘션 표시 — 같은 함수) · public/assets/community/doc-render.js(필요하면 사본 지도 인자 하나) · community.css · app.css ·
                 커뮤니티 JS import 버전 줄 ·
              시험 — tests/community/cafe_c4_browser.py(새 수락 — HTTP + 브라우저) · memories_harness.py(choices) · tests/catalog.json · docs/cafe-report.md(C4 절)
금지 패턴   : 검사 전 서버 사본을 내보내기(raw · download 의 423 그대로) · CSP 바꾸기 · blob: · innerHTML · 인라인 style · 새 색값 · 렌더러 · 멘션 표시 두 벌 ·
              mention_status 에 볼 수 없는 사람의 이름 싣기(못 보는 사람은 ok:false — 존재 여부를 드러내지 않음) · N+1 · git add -A.
완료 기준   : HTTP — attachments 목록: 볼 수 있는 사람 200(지도 · scan_status) · 못 보는 사람 404 · 손님 규칙 그대로 · 응답에 다른 문서 첨부 0 ·
                 comments_list mention_status: 있는 · 볼 수 있는 아이디 ok:true+이름 · 없는 아이디 ok:false · 그 문서를 못 보는 사람의 아이디 ok:false(이름 0) · 익명게시판 {} · 질의 수 댓글 3 · 30 같음
              브라우저(1440 · 412) — 그림을 붙여넣기 · 끌어놓기 · 「이 문서에 올리기」 셋 다: 즉시 img(data:) + 「검사 중 · 나만 보여요」 배지 · 손잡이로 폭 바꿈 ·
                 서버 scan_status 를 clean 으로 바꾸면(틀이 scan_pending 실행 또는 DB) 5초 안에 서버 주소 img 로 바뀜 · 배지 0 · 폭 그대로 · 저장 뒤 다시 열어도 그림 ·
                 새로 고침 뒤 pending 이면 「검사 중이에요」 상자 → clean 이 되면 바뀜 · 다른 사람 화면은 clean 전 상자 · 콘솔 0 ·
                 댓글(전체 화면 · 칸): `@있는아이디` 파랑 + aria-label 「… 알림이 갔어요」 · `@없는아이디` 빨강 + 물결 · `@아이디.te` 처럼 붙은 글자도 서버 규칙(community_mentions_in)대로 끊어 표시 ·
                 대비 4.5 이상(밝게 · 어둡게 · 고대비) · 넘침 0
              정적: security_lint 0 · php -l · node --check · asset_version --check · git diff --check
```

## C5 — 접힌 사이드바 「＋」 풍선 · 기본 접힘 (사용자 지적 2026-10-14 · C4 뒤 · 같은 worktree)

사용자(스크린샷 셋 — 접힌 「＋」에 마우스를 올리면 단추 안에 「로 만들」 잘린 글자 · 「홈」은 옆에 풍선): 「다른 건 마우스를 올리면 『홈』처럼 설명 글이 나오는 방식이 되어야 함, 단 펼쳐서 칸 크기가 충분할 때만 『새로 만들기』로 · 페이지 접근했을 당시 가장 기본은 사이드바가 전부 간소화된 상태로」.

```
목표        : 접힌 레일의 「＋」를 다른 줄과 같은 아이콘 + 오른쪽 풍선으로 만들고, 저장된 선택이 없으면 모든 셸이 접힌 레일로(첫 HTML 부터) 시작하게 한다.
요구사항    : DESIGN 2.19 ⑨ 「2026-10-14 사용자 지적 보정」 세 줄(main 의 DESIGN.md) · 2.8 툴팁.
수정 파일   : src/views/shell_top.php(쿠키 `qaredi_sidebar` === 'open' 이 아니면 body 에 data-sidebar="collapsed" · ☰ aria 첫 값 — 값은 비교만, 출력 0) ·
              public/assets/app.js(☰ 누름 → 쿠키 쓰기 · 옛 localStorage 키 한 번 지움 · 끝 스크립트의 임시 속성 갈래 정리) · public/assets/app.css(접힌 「＋」 — 글자 숨김 · 풍선) ·
              이 셸을 그리는 다른 뷰가 body 를 따로 그리면 그 자리(구현자가 grep — 보고서에) ·
              시험 — tests/community/cafe_c4_browser.py 에 C5 단언 · 접힘 기본이 깨뜨리는 옛 단언(펼침을 가정한 셸 시험)은 시험 시작 때 쿠키 `qaredi_sidebar=open` 을 넣는 한 줄로 · 이유를 보고서에 · docs/cafe-report.md(C5 절)
금지 패턴   : 머리 인라인 스크립트(CSP) · 인라인 style · 새 색값 · 쿠키에 사용자 정보 · 쿠키 값을 HTML 에 그대로 출력 · git add -A.
완료 기준   : 1440 · 1280 · 960(업무 · 이야기 · 자료실 셸): 쿠키 없음 → 첫 HTML(서버 응답 원문)에 data-sidebar="collapsed" · 레일 64 · 첫 프레임 스크린샷도 접힘 ·
              ☰ 펼침 → 쿠키 open → 다른 화면 · 새로 고침 펼침(첫 HTML 에 속성 0) · 다시 접기 → 접힘 ·
              접힌 「＋」: 올리기 · 초점 때 단추 안 보이는 글자 0(계산된 폭 · 글자 노드 보임 0) · 오른쪽 풍선 「새로 만들기」(「홈」 풍선과 같은 top · left 기준) · 누르면 메뉴 그대로 ·
              펼침 폭 240 에서 「＋ 새로 만들기」 글자 · 412 는 서랍 그대로(쿠키 무관) · 넘침 0 · 콘솔 0
```

## C6 — 오른쪽 위 셋: 메신저 · 앱 · 내 계정 (사용자 지적 2026-10-14 · C5 뒤 · 같은 worktree)

사용자(스크린샷 — 앱 판): 「앱에 내 계정 경로가 있는데 오른쪽 아이디를 눌러도 내 계정으로 감. 내 계정 · 내 활동 같은 내 계정 관련 정보를 앱 표현 방식과 같게 — 아이디를 클릭하면 모달이 뜨고 골라서 접근. 메신저는 다른 페이지로 가는 경로가 아니라 전역 팝업 기능이니 모달에서 제거하고 앱 왼쪽에 메신저 단추를 따로」.

```
목표        : 오른쪽 위를 메신저 단추 · 앱 단추 · 내 계정 칩(누르면 앱 판과 같은 모양의 내 계정 판)으로 나누고, 앱 판에는 앱과 도구만 남긴다.
요구사항    : DESIGN 2.30(main 의 DESIGN.md — 2026-10-14) · 2.18 · 2.28(C3 의 앱 단추) · 2.17 메신저 창 · 6장.
수정 파일   : src/views/shell_top.php(셋 · 판 둘 · 폰 #appbar-account) · public/assets/app.js(판 하나만 열림 · 닫기 · 초점 · 폰 단추 — 지금 앱 판 함수를 두 판에 쓰도록 넓힘 · 두 벌 0) ·
              public/assets/messenger.js · messenger-access.js(여는 단추가 타일에서 단추로 바뀐 데 따른 선택자 · aria-expanded · 초점 되돌림 · 안 읽은 수 자리) · public/assets/app.css ·
              이 셸 말고 런처(`src/views/launcher.php` · 모아 머리 `member_top.php`)에 같은 칩 · 앱 판이 있으면 같은 부품으로(구현자가 grep — 보고서에) ·
              시험 — tests/community/cafe_c4_browser.py 에 C6 단언 · 앱 판 타일 수 · 메신저 타일 · 내 계정 타일을 재던 옛 단언(qf12_shell_browser · messenger_* · q_launcher 등)은
              **그 단언만** 새 규칙으로(파일 · 단언 이름 목록을 보고서에 — 그 시험들을 통째로 돌리지는 않는다) · docs/cafe-report.md(C6 절)
금지 패턴   : 판 · 메뉴 부품 두 벌 · 인라인 style · 새 색값 · innerHTML · 메신저 창을 여는 길 두 벌 · git add -A.
완료 기준   : 1440 · 1280 · 960: 순서 메신저 · 앱 · 칩 · 높이 44 셋 · 메신저 단추 누름 → 창 열림(aria-expanded true) · 다시 → 닫힘 · Esc 초점 단추로 · 안 읽은 수 배지가 메신저 단추에(앱 단추에 0) ·
              앱 판 타일에 메신저 · 내 계정 · 내 활동 · 로그아웃 0 · 관리자 타일은 관리자만 ·
              칩 누름 → 내 계정 판(머리 이름 · 아이디 · 역할 · 타일 내 계정 · 내 활동 · 로그아웃 줄) · 타일 누르면 이동 · 로그아웃 동작 그대로 · 앱 판과 같은 폭 · 같은 클래스 ·
              두 판 동시 열림 0 · 바깥 누름 · Esc 닫힘 · /tbox/settings 에서 「내 계정」 타일 aria-current ·
              412: 윗줄 메신저 · 아바타 단추 · 아바타 → 판 전체 폭 · 서랍 · 앱 판 · 계정 판 하나만 · 손님(메신저 없음) · 직원 · 관리자 세 계정 · 밝게 · 어둡게 · 고대비 넘침 0 · 대비 4.5 · 콘솔 0 · 누름 자리 < 44 0
```
