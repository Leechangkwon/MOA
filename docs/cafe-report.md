# 카페 보고서 (지시서 `docs/cafe-directive.md`)

## C1 — 보안 바닥 (서버) · 2026-10-14 · Opus 구현 에이전트

### 변경 파일
- DB: `db/migrations/2026-10-14_community_cafe.sql`(새 — spaces.area · board_kind anonymous · CHECK 셋 · anon_aliases · reports · board_requests · 알림 종류 누적 CHECK · GRANT · 자유게시판 → cafe · 익명게시판 행 · 되돌리기 주석) · `db/community/schema.sql` · `db/community/grants.sql`
- 판정: `src/community/CommunityAuthz.php` — BOARD_KINDS + anonymous · `decidePublish(…, $board)` 갈래 · `decideSuggestionCreate` 갈래 · `boardOfRow` 에 area · 새 `decideReportCreate` · `decideReportManage` · `decideAnonReveal` · `decideBoardRequest`
- 가림: `src/community/anon_helpers.php`(새) · `src/community/routes.php`(require · 라우트 8줄 · page_row 읽기 열 area 둘 · `community_board_create` · `community_page_hide_write` · `community_comment_hide_write` · 검색 댓글 SQL 이름표 재료) · `src/community/BoardCursor.php`
- 핸들러(고침): pages_get · board_pages_list · board_pages_create · comments_create · comments_list · search · notifications_list · history · versions_list · version_get · mention_candidates · procedures_list · pages_properties · pages_update · attachments_upload · pages_hide · comment_hide · boards_create · boards_list · `export_helpers.php` · (표식) pages_delete · page_restore · version_restore · pages_tags_put · comment_update · comment_delete · comment_resolve · (목록 밖 — 아래) pages_publish · `SaveDoc.php`
- 핸들러(새): reports_create · reports_list · report_resolve · report_reveal · board_requests_create · board_requests_list · board_request_decide · cafe_home
- 감사: `src/Audit.php`(새 동작 다섯의 틀 12줄 — report.create · report.resolve · anon.reveal · board_request.create · board_request.decide) · `src/handlers/audit_list.php`(가림 · 누가/IP 필터 제외 — 한 벌의 SQL 조각 + 함수)
- 그 밖: `src/community/Memories.php`(stripPhotoMetadata → public static 만)
- 시험: `tests/community/cafe_c1_http.py`(새) · `tests/community/memories_harness.py`(두 자리) · `tests/catalog.json`(community.cafe_c1_http)

### 감사 표식(`detail.anon_board`)을 단 커뮤니티 자리 — `community_anon_audit_detail`
board_pages_create(page.create) · SaveDoc(page.update — pages_update 의 몸) · pages_delete(page.delete) · page_restore(page.restore) · version_restore(page.restore_version) · pages_properties(page.property · page.assign) · pages_tags_put(page.tags) · comments_create(comment.create) · comment_update · comment_delete · comment_resolve(comment.resolve · unresolve) · attachments_upload(attachment.upload) · export_helpers(page.export) · reports_create(report.create — 관리자 화면에서는 가리지 않음).
일부러 안 단 것: page.hide/unhide · comment.hide/unhide(관리자의 행위) · attachment.download(읽는 사람) · page.read(게시판 글은 감사 대상 갈래 없음) · 거부 행(행위자가 쓴 사람이 아님). 합성 DB 실측 표식 동작: attachment.upload · comment.create · comment.resolve · page.create · page.export · page.property · page.update · report.create.

### 수락 시험
- 명령: `python3 tests/community/memories_harness.py --suite cafe_c1_http.py --output /home/changkwon/qaredi-cafe-results-20261014/c1-run2`
- 결과: **pass 209 · fail 0**(rc 0) — `c1-run2/cafe-c1-results.json`(단언 · 확정 값) · `cafe-c1-schema-shape.txt` · `stdout.txt` · `http.log`. 첫 실행 `c1-run1` 은 207/2(시험 순서 — 숨긴 글의 댓글 쓴 사람은 그 글을 못 봐 알림 0 이 맞음 · 질의 수 기대값 3 → 실제 3 + 권한 표 1)를 시험 쪽만 고쳐 다시 돌렸다.
- 확정 값: 별칭 W=0 · A=1 · B=2 · 이름표 익명1/익명2/글쓴이/익명1 · /api/cafe 질의 4 = 4(행 3 → 10, 셋 + Authz 권한 표 한 번) · tbox_app 권한 `false,false,false,false,false,true`.
- 정적(⑪): `security_lint` 통과 0건 · `php -l` 44 파일 0 오류 · `git diff --check` 0 — `c1-static.txt`. 마이그레이션 두 번은 틀이 하고, 시험이 첫 게시판 셋을 넣은 뒤 두 번 더 돌려 SAME.
- 다른 회귀(stage* · qf12_* 등)는 지시대로 돌리지 않았다.

### 못 한 것
- ARCH 7.4 감사 문장 표 · SECURITY C:R-18 · ARCH 25 의 문서 갱신 — 허용 목록 밖(메인 몫).
- AI 넣기(`ai_helpers.php` `ai.insert`)는 표식이 없다 — 목록 밖 파일. 행에 target_name 이 없어 글과 바로 잇지는 못하지만 `actor=<W>` 필터에 시각으로 남는다.
- 자료실 참조 거부는 `pages_update.php` 창구에만(지시대로) — AI 넣기 길은 같은 검사를 지나지 않는다(수정 제안은 익명게시판에서 꺼짐).
- 신고 · 요청의 거부(400/404/409, 손님 403)는 감사하지 않고 서버 로그만 — 새 동작 틀을 다섯 동작으로 묶느라 rate_limited 만 deny 행.

### ARCH 25장과 다르게 한 것
- **목록 밖 파일 둘**: `pages_publish.php`(익명게시판 게시 거부 — `decidePublish` 가 게시할 게시판을 알아야 함, 403 `anon_board`) · `SaveDoc.php`(page.update 감사 표식 한 줄 — pages_update 의 실제 몸).
- `anon_helpers.php` 의 헬퍼가 다섯 + 셋: `community_anon_page_sql`(목록 SQL 짝 — 절차 목록) · `community_anon_label`(이름표 규칙 하나) · `community_anon_is_label`(안전망). `community_anon_assign_alias` 가 다섯째.
- 응답에 더한 키: 이력 · 판의 `by_is_me`(익명 글만) · 알림 `moderation_note`(moderated 만) · boards_list 항목 `area` · `anonymous`(community_board_summary 는 그대로 — 업무 응답 키 0 변경).
- 감사 가림이 ARCH 보다 넓다: actor · ip · login_ref 에 더해 user_agent · actor_role · autoDetail 의 actor_team · token_id · acting_as · real_role 도 뺀다.
- 「하루」 상한은 24시간 굴림. `decideReportCreate` 는 관리자 특권 갈래(admin_all · board_moderator)로만 보는 글을 「못 봄」(404)으로 본다.
- board_request 알림은 페이지 없는 행(요청 번호를 잇는 열 없음 — 화면은 신고함 · 요청 목록으로).
- schema.sql 은 익명게시판 행을 넣지 않는다(다른 첫 게시판처럼 이주 파일만).
- 게시판 만들기 검사가 공용 함수로 옮겨져 400 검사가 트랜잭션 안에서 난다(응답 같음 · 요청 끝 되돌림 로그 한 줄).

## C1b — 검토 보정 · 2026-10-14 · Opus 구현 에이전트

### 변경 파일
- `src/community/ai_helpers.php` — `ai.insert` 감사 detail 을 `community_anon_audit_detail($p, …)` 로(한 줄).
- `tests/community/cafe_c1_http.py` — ② 카나리아 단언에 A · B 의 아이디 · 표시 이름(`no_names` — 준비에서 DB 로 읽음. 합성 fixture 의 A · B 는 표시 이름이 비어 있어 아이디만 셈: `wab_names_checked` W 2 · A 1 · B 1)을 더함: E · M 시점 모든 자리 · 알림(W/A/B 시점은 본인 이름만 빼고) · 신고함의 익명 대상 칸(신고자 이름은 설계상 보이므로 대상 칸만) · 확인 뒤 댓글 목록 · 익명 표식 감사 행. C1b① AI 넣기: 합성 `ai_log` 행(status ok · summary · output_text) → `POST /api/pages/{id}/ai-insert` 200 → `detail.anon_board = true` · `actor=<W>` 필터에 그 행 0 · 감사 화면에서 「익명(익명게시판)」 · ip null(`ai.insert` 는 「어떻게」 필터 목록 밖이라 최신 첫 쪽에서 찾음).

### 수락 시험
- `python3 tests/community/memories_harness.py --suite cafe_c1_http.py --output /home/changkwon/qaredi-cafe-results-20261014/c1b-run1` → **pass 215 · fail 0**(rc 0). 정적: security_lint 0 · php -l 0 · git diff --check 0 — `c1b-static.txt`.

### 못 한 것
- 없음(AI 넣기 길은 틀에서 막히지 않아 「미검증」 0).
