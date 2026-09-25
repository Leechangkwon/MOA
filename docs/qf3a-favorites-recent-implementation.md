# 개발 대기열 6 · Q:F-3a 라운드 A — 즐겨찾기 · 최근(두 앱) 구현 기록 (2026-09-25, claude)

> 지시서(확정, 본문 고치지 않음): `/var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md` — 1.1 즐겨찾기 · 1.2 최근 · 2장 지시서 ·
> 3장 완료 기준(라운드 A 몫 = QF3A-T1~T5 · T9 의 ☆·사이드바·탭 · T10) · 4장 결정 M-1~M-3 · M-8.
> 구현: 취합 세션이 개발 세션 대신 부른 구현자(Opus). 복제본 `~/.local/share/qaredi-dev/qf3aw`(운영 HEAD `eaf09e8` 에서 뜸) ·
> 사이트 문서 복제본 `~/.local/share/qaredi-dev/qf3a-site`(`/var/www/qaredi`, HEAD `e011730`).
> 상태: **구현·자체 시험 끝 — 독립 검토 전.** 운영 반영(① DB 표 넷 → ② 코드)은 검토 뒤 취합 세션 · DB 쪽은 사용자 승인 뒤 sudo.
> 태그: `selftest-base/qf3a-A`(첫 수락 시험 직전 WIP `b6480ba`) · `review-base/qf3a-A`(검토 받을 판) — 해시·sha256 은 8장.

## 1. 한 줄

두 앱에 **즐겨찾기**(t-box 파일·폴더 · 커뮤니티 페이지)와 **최근**(t-box 는 감사 로그를 읽기만 · 커뮤니티는 작은 새 표)을 붙였다.
넣을 때는 그 순간의 읽기 판정(`Authz::decideRead` · `CommunityAuthz::decidePageRead`)을, 목록은 **지금의 읽기 조건절**(파일 목록·폴더 트리·검색과
같은 것)을 그대로 쓴다 — 새 판정 코드 0, 못 보게 된 것은 제목·id·개수 어느 것도 나가지 않는다.

## 2. 바뀐 파일

| 파일 | 무엇 |
|---|---|
| `db/migrations/2026-10-03_qf3a_favorites_recent.sql` (새) | 표 넷 `public.file_favorites` · `public.folder_favorites` · `community.page_favorites`(position) · `community.page_recent` + 보조 색인 다섯 · 소유자 tbox_owner · 권한(앱 SELECT·칸 단위 INSERT·DELETE · 순서/시각 칸 UPDATE · 백업 SELECT) · `lock_timeout 5s` · 한 트랜잭션 |
| `db/schema.sql` · `db/community/schema.sql` | 새 설치가 같은 모양(수락 시험 A-T0 이 칸·제약·색인 35줄을 글자 대조) |
| `db/grants.sql` · `db/community/grants.sql` | 끝에 **표가 있을 때만** 도는 절 하나씩(없으면 NOTICE — 마이그레이션 전 DB 에서 두 파일을 돌려도 멈추지 않게). `db/community/grants.sql` 은 지시서 목록에 글자로는 없다 — 결정 D-11 |
| `public/index.php` | 라우트 일곱(정책 전부 `file.read` · 토큰 창구 집합 불변) |
| `src/handlers/favorites_files.php` (새) | `GET /api/files/favorites` · `PUT·DELETE /api/files/{id}/favorite` |
| `src/handlers/favorites_folders.php` (새) | `GET /api/folders/favorites` · `PUT·DELETE /api/folders/{num}/favorite` |
| `src/handlers/files_recent.php` (새) | `GET /api/files/recent` — 감사 로그(`file.download`·`file.preview` allow, 30일) → 지금 볼 수 있는 파일 20 |
| `src/handlers/home.php` | 보기 셋(파일·즐겨찾기·최근) · 「즐겨찾기 폴더」 묶음 · 폴더 동작 줄의 「즐겨찾기에 넣기/빼기」 · 시각 칸 머리 id · 별 아이콘 템플릿 둘 |
| `public/assets/app.js` | 줄 그리기를 `fileRow()` 로 뽑음(파일·즐겨찾기·최근 보기가 같은 줄) · 이름 앞 ☆ · 보기 바꾸기 · 늦게 온 응답 무시(`loadSeq`) · 폴더 ☆ |
| `src/community/routes.php` | 라우트 여섯(정책 `community.read`) · `community_record_recent()`(5분에 한 번 · 50줄) · 상한 상수 둘 |
| `src/community/handlers/favorites.php` (새) | `GET·PATCH /community/api/me/favorites`(목록 · 순서) · `PUT·DELETE /community/api/pages/{id}/favorite` |
| `src/community/handlers/recent_list.php` · `recent_clear.php` (새) | `GET /community/api/me/recent` · `DELETE /community/api/me/recent`(내 줄만) |
| `src/community/handlers/pages_get.php` | 조회수 바로 옆에 최근 한 줄(실패해도 읽기는 막지 않음) |
| `src/views/shell_top.php` | 커뮤니티 사이드바 맨 위 「즐겨찾기」(`<details>` — 접기) → 「최근」 → 「내 공간」… (결정 M-1) · 「최근 본 페이지」 창 |
| `src/community/handlers/page_view.php` | 「더보기」 맨 위 항목 「즐겨찾기에 넣기/빼기」 · 별 아이콘 템플릿 둘 |
| `public/assets/community/editor.js` | 사이드바 즐겨찾기(끌어 놓기 · Alt+↑/↓ 순서 · 접은 상태는 이 브라우저에만) · 최근 5 + 「더 보기」 창(두 번 눌러 지우기) · 페이지 ☆ |
| `public/assets/community/database.js` · `templates.js` | **import 줄의 `?v=` 만** — `scripts/community_asset_version.php` 가 다시 찍음(editor.js 가 바뀌어 부품 버전이 바뀜 — D-12) |
| `src/help/screens.php` · `src/help/community.php` | 「사용 방법」에 즐겨찾기·최근(t-box 파일 · 커뮤니티 내 공간 · 페이지) |
| `tests/qf3a_tbox.sh` · `tests/community/qf3a.sh` · `tests/browser/qf3a_favorites.py` (새) | 수락 시험 둘 + 화면 시험(4장) |
| `review-scripts/apply-qf3a-db-2026-09-25.sh` · `apply-qf3a-repo-2026-09-25.sh` (새) | 반영 스크립트 둘(7장) — 패치에서는 뺀다 |
| 문서 | 이 기록 · `ARCHITECTURE.md` 5.33 · `SECURITY.md` 7장 한 줄 · `STAGES.md` 0장 한 줄 · 사이트 문서 패치(PRD Q:F-3 상태 · 커뮤니티 ARCH 21.24) |

## 3. 결정 — 지시서가 비워 둔 곳 · 지시서와 다르게 간 곳

| # | 결정 | 왜 |
|---|---|---|
| D-1 | **창구 주소·정책** — t-box `GET /api/files/favorites` · `PUT·DELETE /api/files/{id}/favorite` · `GET /api/folders/favorites` · `PUT·DELETE /api/folders/{num}/favorite` · `GET /api/files/recent`(지시서 그대로), 전부 `file.read`. 커뮤니티 `GET·PATCH /api/me/favorites` · `PUT·DELETE /api/pages/{id}/favorite` · `GET·DELETE /api/me/recent`, 전부 `community.read` | 기존 모양(`/api/files/{id}/share`·`/restore`, `/api/me/…`)을 따랐다. 새 정책 이름 0 — 라우터는 「읽는 종류인가」까지, 대상 판정은 핸들러가 기존 함수로. 토큰 창구 집합(`TBOX_API_TOKEN_*`)은 한 글자도 안 바뀜(A-T6 이 기준 판과 글자 대조) |
| D-2 | **외래 키 — 대상(파일·폴더·페이지)은 `ON DELETE CASCADE`, 사람(users)은 ON DELETE 절 없음. 커뮤니티 30일 정리 함수(`community.purge_trash`)는 고치지 않았다** | ★ 지시서의 전제(「30일 정리는 **행을 지우므로** 새 표도 함께 정리해야」)가 지금 코드와 다르다 — 7-A 결정 J-1 로 정리 함수는 페이지 **행을 남기고**(`purged_at`) 내용만 비운다. 그래서 CASCADE 는 정리에서 불리지 않고, 정리된 페이지의 즐겨찾기·최근 줄은 남는다. 그 줄은 목록이 `deleted_at IS NULL` 과 읽기 조건절로 거르므로 **화면·응답에 나오지 않고**(시험 T2 휴지통 갈래), 최근은 사람당 50줄 상한으로 밀려나며, 즐겨찾기는 한도에 닿으면 걷힌다(D-5). 폴더는 빈 폴더를 **행째 지우므로** CASCADE 가 꼭 필요하다(없으면 즐겨찾기가 걸린 폴더 삭제가 500 — 시험 T2 「빈 폴더 지움 200」). 정리 함수에 두 줄(`DELETE FROM community.page_favorites/page_recent WHERE page_id = ANY (ids)`)을 넣는 것은 검토·운영 반영이 끝난 7-A 함수를 `CREATE OR REPLACE` 로 다시 쓰는 일이라 가장 좁은 쪽(안 고침)을 골랐다 — 줄 정리가 필요하면 U-2 |
| D-3 | **거부는 「찾을 수 없습니다」 404 한 문구** — 없는 id · 지운 것 · 못 읽는 것이 본문까지 같다(시험이 바이트 대조). 폴더도 404(트리 창구는 403 이지만 즐겨찾기는 두 앱을 한 문구로) | 지시서 1.1 「404 같은 문구」. 즐겨찾기 넣기가 「그런 파일·페이지가 있다」를 알려 주는 창구가 되지 않게 |
| D-4 | **감사에 남기지 않는다 — 거부도** | 결정 M-2(개인 화면 설정). 거부를 남기려면 새 행동 이름과 문장 틀이 필요한데 `src/Audit.php` 는 이번 수정 목록 밖이고, 기존 행동(`file.download`·`page.read` 거부)을 빌리면 감사의 뜻이 틀어진다. 이 창구는 이름·본문·존재 어느 것도 돌려주지 않는다(D-3) |
| D-5 | **한도(파일 200 · 폴더 50 · 페이지 200)는 내 줄 전부를 센다. 가득 찼으면 「지금 목록에 안 나오는 내 줄」을 먼저 걷고 다시 센다. 그래도 가득이면 409 + 사람 문장** | 못 보게 된 줄은 화면에 없어 사람이 뺄 수 없다 — 그 줄 때문에 영영 못 넣는 일을 막는다. 걷는 조건은 목록과 **같은 조건절**. 400 이 아니라 409 인 까닭: F-68 의 400 이유 표(`Http::reasonMessage`, 보안 핵심 파일)를 건드리지 않으려고. 한계: 동시에 넣으면 한두 줄 넘칠 수 있다(부드러운 상한) |
| D-6 | **순서** — t-box 는 「추가한 순」= 넣은 때 오름차순(먼저 넣은 것이 위, 지시서 1.1 글자 그대로). 커뮤니티는 position 오름차순(새 줄 = 가장 큰 순서 + 1), 끌어 놓기·Alt+↑/↓ → `PATCH {ids}` 가 **내 줄**의 position 만 1..n 으로 다시 매김(목록에 없는 내 줄은 그대로) | 지시서 1.1 표 |
| D-7 | **t-box 최근** — `actor_id = 나 · action IN ('file.download','file.preview') · result='allow' · target_type='file' · 30일` 을 파일마다 마지막 시각으로 묶은 **뒤에** 읽기 조건절을 붙이고 20개(못 보는 파일이 자리를 먹지 않게). `file.download_exec`(실행 파일 압축본 확인 받기)는 지시서 목록에 없어 넣지 않았다. 프로그램 토큰으로 받은 것은 그 계정의 받기라 들어간다 | 지시서 1.2. 새로 쓰는 행 0(시험 T4 가 감사·세션 밖 표의 쓰기 수를 전후로 잰다) |
| D-8 | **커뮤니티 최근 쓰기** — 한 문장 `INSERT … ON CONFLICT DO UPDATE SET last_viewed_at = now() WHERE last_viewed_at < now() - 5분 RETURNING (xmax = 0)` · 새 줄일 때만 50줄 넘는 가장 오래된 줄 삭제 · `pages_get.php`(창구)에서만 · 실패해도 읽기 200(조회수와 같은 try/catch) | 지시서 1.2. 화면 `page_view.php` 는 페이지를 읽지 않는다(창구가 읽는다) |
| D-9 | **t-box 화면** — 보기 셋은 `.btn.btn--quiet.btn--small` + 고르지 않은 것에 `.btn--plain`(테두리·바탕 걷음) + `aria-pressed`(새 클래스 0 · 커뮤니티 DB 보기 단추와 같은 뜻). ☆ 는 **이름 칸 맨 앞**의 `.btn--icon`(행 동작 칸은 DESIGN 2.1 순서·「두 줄까지」 그대로). 폴더 ☆ 는 폴더 동작 줄의 글자 단추. 별 아이콘 둘은 화면 템플릿(`home.php` · `page_view.php`) — `shell_bottom.php` 는 수정 목록 밖 | DESIGN 원칙 6 · 2.1 · 2.11(「각 화면의 `<template>`」) |
| D-10 | **커뮤니티 화면** — 페이지 ☆ 는 「더보기」 메뉴의 **맨 위 항목**(머리 단추가 이미 넷 — 사용 방법·이력·더보기·편집, DESIGN 2.9). 사이드바 「즐겨찾기」는 `<details open>` + `summary.nav-title`(접기에 스크립트 0 · 접은 상태는 localStorage — 저장이 막혀도 펼침으로 시작). 「최근」 5 + 「더 보기」 창(`.modal--form.modal--stack` · `.list`) · 「최근 기록 지우기」는 빨간 단추 두 번(댓글 지우기와 같은 방식 — 모달 안 모달 0). 관리자가 `admin_all` 로 연 페이지를 넣으면 창구가 `listed:false` 를 주고 화면이 「목록에 나오지 않습니다」를 알린다 | M-1 · DESIGN 2.9 · 2.14 · 2.15 · 4장 |
| D-11 | **`db/community/grants.sql` 에도 절 하나** | 지시서 「수정 파일」에는 `db/grants.sql` 만 글자로 있다. 그러나 새 설치는 커뮤니티 표 권한을 이 파일로만 받는다 — schema 두 파일에 새 표를 넣은 것(취합 지시 「대기열 5c 와 같은 판단」)과 한 벌이어야 새 설치에서 창구가 500 이 나지 않는다. 권한을 두 파일로 흩지 않으려고 커뮤니티 표는 커뮤니티 파일에 뒀다. **검토 때 확인 요청** |
| D-12 | `database.js`·`templates.js` 의 import `?v=` 두 줄 | editor.js 가 바뀌면 부품 버전이 바뀐다 — `scripts/community_asset_version.php` 가 찍은 기계적 변경(2026-09-24 핫픽스 규칙) |
| D-13 | 라운드 A 에 넣지 않은 것 — 트리 항목 메뉴의 ☆(사이드바 트리에 아직 항목 메뉴가 없다) · `home_page.php`·휴지통 화면(라운드 B 선택 모드) · Ctrl+K 빈 검색어의 최근(지시서 1.2 「Q:F-2 쪽 선택」) | 8장 U-3 · U-4 |
| D-14 | 시험에서 `db/grants.sql` **전체**를 임시 DB 에 돌리지 않았다 — 새 절만 | 그 파일에는 `GRANT CONNECT ON DATABASE tbox_re` 가 있다 — DB 단위 권한은 클러스터 단위라 임시 DB 에 붙어 돌려도 **운영 DB** 의 권한을 다시 쓴다. 대신 새 절을 잘라 돌리고(DATABASE 문장 0 확인), 커뮤니티 grants 는 전체를 돌렸다(DB 단위 문장 없음) |

## 4. 보안 — 새 판정 코드 0 을 지킨 방법

| 무엇 | 쓰는 판정 | 새로 만든 것 |
|---|---|---|
| 파일 넣기 | `Authz::decideRead`(다운로드·미리보기와 같은 함수) + `deleted_at IS NULL` | 없음 |
| 폴더 넣기 | `Authz::decideRead`(폴더 트리 `folders_list.php` [권한 ✔ 1]과 같은 함수) | 없음 |
| 페이지 넣기 | `community_page_row_nodoc` + `CommunityAuthz::decidePageRead`(페이지 읽기와 같은 행·같은 함수) | 없음 |
| 파일 목록(즐겨찾기·최근) | `Authz::visibleFilesWhere`(파일 목록과 같은 조건절 — 논리 삭제 제외 포함) | 없음 |
| 폴더 목록 | `Authz::visibleWhere($user, 'd')`(폴더 트리와 같은 조건절) | 없음 |
| 페이지 목록(즐겨찾기·최근) | `community_search_pages_where`(검색과 같은 — 관리자·게시판 관리자 특권 끔) + `deleted_at IS NULL` + `NOT (board AND hidden)`(검색 문장과 같은 두 줄) | 없음(조건절 문자열을 그대로 붙인다) |
| 누구의 것 | 세션의 `$user['id']` 고정 — 쿼리·본문에서 사람을 받지 않는다(시험: `?user_id=` 를 줘도 결과 같음) | — |

값은 전부 바인딩(순서 바꾸기의 id 목록은 UUID 모양만 통과시킨 뒤 쉼표로 이은 **값 하나**를 DB 가 `uuid[]` 로 바꾼다) · SQL 조각은 코드 상수 ·
화면 글자는 전부 `textContent` · 인라인 style·스크립트 0 · 새 색값·새 클래스 0 · 응답에 전체 개수 칸 없음(못 보는 즐겨찾기의 수가 새지 않게).
보안 린트 0 · 토큰 창구 불변.

## 5. 수락 시험 · 회귀 (있는 그대로)

### 5.1 `tests/qf3a_tbox.sh` (임시 DB = 운영 사본 · 임시 인스턴스 8160 · claude_ 다섯)

| 회차 | 결과 | 실패와 원인 |
|---|---|---|
| 1 | 통과 78 · 실패 6 | 전부 **시험 결함** — ① 「넣기·빼기가 감사 행을 늘리지 않는다」 창 안에 CSRF 없는 PUT 시험이 있어 라우터 관문의 `csrf deny` 한 줄을 셌다 ② 한도 시험 재료 — 운영 사본에는 직원이 볼 수 있는 살아 있는 사내·공개 파일이 8개뿐이라 200 줄을 못 채움(연쇄 3) ③ 몸통 헛돎 문턱 60 |
| 2 | 통과 83 · 실패 1 | 시험 결함 — 몸통 단언이 57개인데 문턱 60 |
| **3** | **통과 84 · 실패 0** | — |
| **4** (고친 판 — app.js Q:T-4 · PATCH 목록 검사) | **통과 84 · 실패 0** | — |

### 5.2 `tests/community/qf3a.sh` (임시 인스턴스 8161 · 화면 시험 포함)

| 회차 | 결과 | 실패와 원인 |
|---|---|---|
| 1 | 통과 65 · 실패 9 | 전부 **시험 결함** — ① 같은 CSRF 감사 창 ② PG14 통계가 앱 연결(php -S 한 워커)에서 500ms 간격으로만 나가 UPDATE 수를 늦게 셌다(행 xmin 대조는 통과) ③ 「남의 줄 그대로」를 볼 employee01 의 최근 줄을 만들지 않았다 ④ 한도 재료(employee02 페이지 54장 — 연쇄 4) ⑤ 화면 — 직원1 루트의 파일이 전부 격리된 시험 파일이라 「받기 → 최근」 재료가 없었다(연쇄 3) |
| 2 | 통과 73 · 실패 2 | 시험 결함 — 보기 단추 색을 단추 전환(0.12s) 도중에 쟀다(다크 1440 스크린샷의 「파일」 단추 테두리도 그 순간을 찍은 것) |
| 3 (`REGRESS=1`) | 통과 82 · 실패 1 | 수락 몸통 68/0 · 화면 T9 0 실패 · 회귀 일곱 통과 — 실패 1 = 회귀 `q_launcher` 86/4/1 의 넷 중 **하나가 제품 결함**: Q:T-4 정적 검사 「app.js 절대 경로는 줄마다 `APP_BASE` 로 감싼다」 — 새 `favFetch` 가 안에서 `APP_BASE` 를 붙이고 부르는 줄에는 `'/api/…'` 글자만 있어 네 줄이 걸렸다(기준 판 0). **고침**: `favFetch(method, url)` 로 바꾸고 부르는 줄이 `APP_BASE + '/api/…'` 를 넘김(동작은 같다). 나머지 셋(생성기 CSP · 옛 `/assets/app.css` 308 둘)은 nginx 가 하는 일이라 php -S 임시 인스턴스에서는 늘 실패 — 기준 판 대조는 5.4 |
| 4 (`REGRESS=1` — `q_launcher`·`api_*`) | 통과 78 · 실패 3 | 수락 몸통 69/0(PATCH 「목록이 아닌 객체」 400 추가) · 회귀 `q_launcher` **87/3/1**(남은 셋 = nginx 몫 — 아래) · `api_whoami` 22/0 · `api_read` 25/0/4 · `api_folders` 25/0/5 · `api_tokens` 69/0/3 — 실패 셋 = 화면 시험 1(**시험 결함**: 「즐겨찾기」 단추를 누른 뒤 「줄이 하나라도 있으면」 곧바로 재서, 앞 보기(「최근」 1줄)의 표를 재고 뒤이어 즐겨찾기 2줄이 그려져 표 높이가 133→226 으로 「늘어난」 것처럼 보였다 — 쪽 글이 「즐겨찾기 n건」이 될 때까지 기다리게 고침) + 그 연쇄 2 + `q_launcher` 종료 코드 |
| **5** (마지막) | **통과 76 · 실패 0** | 수락 몸통 **69/0** · 화면 T9 **✔ 55 · 실패 0**(두 앱 · 라이트·다크 1440 · 390 · 좁은 폭 360·412 터치·412 마우스·820 · pageerror·console.error·요청 실패·HTTP≥400 0) · 서버 로그 새 오류 0 |

### 5.3 화면 — 스크린샷을 직접 본 것 (구현자가 열어 봄)

- **t-box 1440 라이트·다크** — 화면 머리 아래 목록 위에 「파일 · 즐겨찾기 · 최근」 세 단추가 한 줄. 고른 것만 흰(다크는 어두운) 테두리 상자이고
  나머지는 글자만(확대 캡처 `tbox-tabs-*-zoom.png` 로 확인 — 2회차 다크 1440 에서 「파일」에 테두리가 보인 것은 단추 색 전환 0.12s 도중을 찍은 것이라
  시험이 0.4초 기다린 뒤 재도록 고쳤다). 「즐겨찾기」 보기: 검색 줄이 사라지고, 「즐겨찾기 폴더」 카드(폴더 이름 + 등급 배지)가 표 위에, 표에는
  「폴더」 칸이 생기고 아래에 「즐겨찾기 n건」. 이름 칸 맨 앞에 회색 찬 별(넣은 것) · 빈 별(안 넣은 것) — 이름 글자보다 1~2px 위에 앉는다(행 높이는
  동작 칸의 32px 아이콘이 이미 정한다 — 줄이 늘지 않음). 「최근」 보기: 시각 칸 머리가 「마지막으로 연 때」, 아래 「최근 n건」.
  폴더 카드 아래 동작 줄 맨 앞에 「★ 즐겨찾기에서 빼기」(넣은 뒤).
- **t-box 390 라이트·다크** — 화면 머리 아래 폴더 카드, 그 아래 보기 세 단추가 한 줄, 즐겨찾기 폴더 카드, 표(가로로만 밀림). 문서 가로 넘침 0.
- **커뮤니티 1440 라이트·다크** — 사이드바: 알림 → 「▾ 즐겨찾기」(펼침 삼각 표시가 붙은 묶음 제목) 아래 줄들 → 「최근」 줄 + 「≡ 더 보기」 →
  「내 공간」… 순서(M-1). 지금 페이지 줄은 즐겨찾기·최근·내 공간 트리 세 곳에서 같이 강조된다. 페이지 머리 「더보기」를 열면 맨 위에 「☆ 즐겨찾기에
  넣기」, 그 아래 옮기기·템플릿으로 저장·게시 · 구분선 · 빨간 삭제. 「더 보기」 창: 제목 「최근 본 페이지」 · 안내 한 줄 · 목록(제목 링크 파랑 +
  공간 이름 · 시각) · 바닥 빨간 글자 「최근 기록 지우기」 + 「닫기」.
- **커뮤니티 390 라이트·다크(서랍)** — 메뉴 단추로 연 서랍에 즐겨찾기·최근이 알림 아래로 보이고 가로 넘침 0.

### 5.4 회귀 (T10) — 운영에 쓰지 않는 방식만

| 무엇 | 어떻게 | 결과 |
|---|---|---|
| 커뮤니티 stage3 · 4a · 4b · 4c · `stage26` · `help_button` · `f68` | `tests/community/qf3a.sh REGRESS=1`(같은 임시 인스턴스 — 주소·DB 만 바꾼 사본) | 89/0 · 93/0 · 94/0 · 107/0 · **113/0** · **16/0** · **109/0/2** |
| `q_launcher` | 같은 틀 | 3회차 86/4/1(제품 결함 1 — 5.2) → **4회차 87/3/1**. 남은 셋(생성기 CSP · 옛 `/assets/app.css` 308 · 그 Location)은 nginx 가 하는 일이라 php -S 에서는 늘 실패 — **기준 판 eaf09e8** 을 같은 틀(`qf2_search.sh REGRESS_ONLY=1`)로 돌려도 86/4/1(같은 셋 + 「새 주소로 공유 파일 받기」 1 — 그 판 실행의 환경 흔들림, 이 판에서는 통과) |
| `api_whoami` · `api_read` · `api_folders` · `api_tokens`(토큰 창구 불변) | 같은 틀 | 22/0 · 25/0/4 · 25/0/5 · 69/0/3. `api_upload` 는 몸 안에 운영 포트 글자(안내 문장)가 있어 회귀 틀의 안전 거르개가 돌리지 않는다 — 토큰 창구 목록은 A-T6 이 기준 판과 글자 대조 |
| 커뮤니티 스스로 임시 DB 를 만드는 시험 17 | 임시 사본(.git 없는 tar)에서 차례로 — 포트 줄만 8163(허용 범위)으로, 사본의 `db/community/grants.sql` 앞에 이번 마이그레이션을 붙여 **「① DB → ② 코드」 반영 순서**를 흉내(그 시험들은 운영 DB 사본을 쓰는데 운영에는 아직 새 표가 없다) | stage4d 94/0 · 5a **187/1** · 5a2 139/0 · 5c 188/0 · 5d 93/0 · 5e 77/0 · 6a 122/0 · 6b 70/0 · 6c 41/0 · 7a 55/0 · 7b 141/0 · 7c 92/0 · 7d1 63/0 · qf4_draft_key 81/0 · qf4_onboarding 8/0 · asset_version·stale_modules 는 git 이 있어야 해 git 붙은 임시 사본에서 **37/0 · 8/0**. stage5a 의 실패 1(「비활성 계정 × R — 읽기 거짓」 기대, 실제 `admin_all`)은 **기준 판에서도 같은 1**(같은 틀로 돌림 — 운영 사본의 비활성 계정 자료에 달린 것, 이번 변경과 무관) |
| ★ 마이그레이션 없이 돌린 첫 `stale_modules` | 위 흉내 없이 | 6/2 — 즐겨찾기·최근 창구가 **500**(표 없음)이라 화면 시험의 HTTP≥400·로그 오류. 반영 순서가 틀리면 생기는 일 그대로다 → repo 반영 스크립트가 표 넷이 없으면 시작하지 않는다(7장) |
| t-box 화면 시험을 품은 것 `qf4a`(all) · `qf4_restore_owner` | 위와 같은 흉내 · `qf4a` 는 「고치기 전 = git HEAD」 비교가 있어 git 붙은 사본(HEAD = eaf09e8)에서 | **21/0** · **101/0** |
| 점검 봇 정적 규칙 22(`tests/static/r*.py --walk`) | 이 판 · 기준 판을 각각 | 규칙마다 발견 수 **같음**(r15 4=4 · r19 3=3 · r1 1=1 · 나머지 0=0) — 늘어난 발견 0 |
| `php -l` · `scripts/security_lint.sh` · `php scripts/wiki_check.php` | 바뀐 PHP 전부 · 저장소 전체 · 운영과 같은 배치의 임시 폴더(형제 `community` 링크 · 사이트 `STAGES.md` 사본) | 0 · 통과(금지 패턴 0) · 통과(구조 0 · 빠진 글 0) |

## 6. 운영 반영 절차 (검토 뒤 — 이 복제본은 운영에 쓰지 않았다)

① `sudo bash scripts/apply-qf3a-db-2026-09-25.sh --migrate <MIG 사본>`(표 넷 — 빈 표 · 참조 표에 짧은 잠금 · 5초 넘으면 바뀐 것 없이 오류)
→ ② `bash scripts/apply-qf3a-repo-2026-09-25.sh <PATCH> <SHA256>`(기준 판·검토 통과 판 sha256 · git 이 읽은 경로·요약 · 표식 수 · 부품 버전 · HTTP —
틀리면 스스로 되돌림) → 파일 지정 커밋 → claude_ 계정으로 두 앱 스모크 → 발행(minor — 새 기능).
①만 있고 ② 전이면 옛 코드는 새 표를 모른다(아무 일 없음). **②를 ① 보다 먼저 하면** 즐겨찾기·최근 창구가 500 이고 사이드바 요청이 오류다
(페이지 읽기 자체는 최근 기록 실패를 삼켜 200) — 그래서 repo 스크립트가 표 넷이 없으면 시작하지 않는다.

## 7. 반영 스크립트

`review-scripts/apply-qf3a-db-2026-09-25.sh`(root) — `--dry-run`(기본)/`--migrate [MIG]` · 모르는 인자 거부 · 마이그레이션 sha256 고정 + 주석 뺀 문장
목록 **글자 그대로** 대조 · 사용 중(최근 10분 사람 감사 행) 확인 · 적용 뒤 표·소유자·색인·외래 키 동작·권한(표·칸 단위 — 문자열 전체 비교)·
참조 표 행 수(반영 전 id 이하만)·빈 표·HTTP · 틀리면 **이번에 만든 표만** DROP · `(query-failed)` 는 늘 ✖.
`review-scripts/apply-qf3a-repo-2026-09-25.sh`(changkwon) — qf2b 틀 그대로(패치 sha256 · git 이 읽은 경로 = 목록 · 요약 줄 = 목록 ·
rename/copy/지우기/심볼릭 링크 머리 거부 · 기준 판 · 커밋 안 된 변경 · ① 확인 · 사용 중 · 적용 뒤 검토 통과 판 · php -l · bash -n ·
표식 수는 그 파일 안에서만 · 부품 버전 `--check` · HTTP 넷 401 · 되돌린 뒤 기준 판 확인).

## 8. 못 한 것 · 결정이 필요한 것

| # | 무엇 | 왜 · 다음 |
|---|---|---|
| U-1 | 운영 반영 승인(사용자) | DB 스키마 변경 — 현황판 결정 탭 |
| U-2 | 30일 정리된 페이지의 즐겨찾기·최근 줄 정리 | D-2 — 화면·응답에는 안 나오지만 줄은 남는다. 원하면 7-A 정리 함수에 두 줄(따로 검토) |
| U-3 | 트리 항목 메뉴의 ☆ · Ctrl+K 빈 검색어에 최근 | D-13 — 라운드 B(선택 모드와 함께) · Q:F-2 쪽 선택 |
| U-4 | `DESIGN.md` 에 이번 쓰임 등록(보기 단추 = `.btn--quiet.btn--small` + `.btn--plain` + `aria-pressed` · 이름 칸 ☆ · 사이드바 `<details>` 묶음 · 별 아이콘 규격) | 새 클래스·색값 0 이라 규칙은 안 바뀌었지만 8장 이력 한 줄이 맞다 — 수정 목록 밖이라 취합이 적는다 |
| U-5 | `deploy/install.sh` 마이그레이션 확인 목록 | 보안 핵심 파일(deploy/**) — 5b U-3 과 함께 |
| U-6 | 점검 봇 `tests/catalog.json` 에 새 시험 셋 | 봇 세션 몫 |
| U-7 | 위키 qaredi 글 | STAGES 0장의 Q-n 단계가 아니라 대기열 항목이라 단계 글 대상이 아니다(wiki_check 0) — 필요하면 취합이 |

## 9. 태그 · 묶음 · 시험 마지막 결과

- 마지막 결과: `tests/qf3a_tbox.sh` **84/0**(4회차) · `tests/community/qf3a.sh` **76/0**(5회차 — 몸통 69/0 · 화면 55/0) · 회귀 5.4.
- 태그: `selftest-base/qf3a-A`(`b6480ba` — 첫 수락 시험 직전 WIP) · `review-base/qf3a-A`(이 문서가 든 커밋).
- 묶음 `~/.local/share/qaredi-dev/review-base-qf3a-A.bundle` · 패치 `~/.local/share/qaredi-dev/qf3a-A-repo.patch`(= `git diff eaf09e8 review-base/qf3a-A -- . ':!review-scripts'`) ·
  마이그레이션 사본 `~/.local/share/qaredi-dev/qf3a-A-2026-10-03_qf3a_favorites_recent.sql` · 사이트 문서 패치 `~/.local/share/qaredi-dev/qf3a-A-site.patch`(0600) —
  sha256 은 반영 스크립트 두 개의 `@@HASHES@@` 절과 커밋 메시지에 적는다(이 문서에 적으면 이 문서의 해시가 바뀐다).
- 모의 반영: 운영 HEAD 를 푼 임시 git 트리 + 운영 사본 임시 DB + 임시 인스턴스(8164)에 스크립트 사본(경로·포트·DB 이름만 바꿈, root 확인만 건너뜀) —
  모르는 인자 거부 · dry-run · **①보다 먼저 ② → 멈춤(3)** · 틀린 sha256 → 멈춤(1) · **확인 실패 흉내(인스턴스를 멈춘 채 --migrate) → 이번에 만든 표만 되돌림(4)** ·
  --migrate · 다시 --migrate = 「이미 적용됨」 · ② dry-run · 적용(검토 통과 판 30파일 · php -l · 표식 · 부품 버전 · HTTP 넷 401) · `git apply -R` 뒤 기준 판 · 다시 적용.
  review-base 직전 판으로 한 번(22:00 — 확인 실패 흉내 없이 통과), 이 판으로 한 번 — 결과는 커밋 메시지.
