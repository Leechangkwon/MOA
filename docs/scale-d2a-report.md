# 규모 D2a 보고 — 먼저 무너지는 창구 아홉 고치기 (2026-10-15)

> 지시서 `docs/scale-d2a-directive.md` · D1 보고 `docs/scale-d1-report.md`. 측정 도구 `tests/scale/d1_measure.py`(그대로 + `--select` · 상한 칸 인식 · 연결 열쇠 `QCHECK_ENV_SRC`).
> 원문 결과 `/home/changkwon/qaredi-scale-d2a-results-20261015/`: `run3/`(10-15 구현자 S · M · L — 첫 비교표의 원본; 10-16 인수 최종 측정과 구분) · `run1/` · `run2/`(중간 판 — 아래 ⑤ 의 까닭) · `equiv-final/`(보안 바닥) · `budget/` · `index-sizes-run2.txt`.

## 요약

- A1~A9 창구를 「읽는 행 수 ≈ 보이는 행 수」 모양으로 바꿨다. L(글 50만 · 나머지 33% — D1 과 같은 비율)에서 **카페 홈 16.3s(500) → 58ms** · 자료실 4.56s → 18ms · 폴더 3.13s → 95ms · 공유 받은 글 6.13s → 29ms · 공간 목록 2.23s → 44ms · 나무 2.15s → 56ms · 휴지통 3.11s → 27ms · 게시판 목록 2.19s → 12ms · 알림 610ms(질의 122) → 62ms(질의 24).
- **새로 찾은 원인 하나 — JIT 컴파일.** 큰 권한 조건절이 붙은 문장은 계획기가 비용을 크게 어림해 PostgreSQL JIT 를 켰고, L 에서 실행은 수십 ms 인데 **컴파일이 2~2.6초**였다(D1 의 공간 목록 · 나무 · 휴지통 시간 대부분, D2a 1차의 공지 문장 374ms). 그 문장들만 `SET LOCAL jit = off`(트랜잭션 안 — 검색의 `enable_seqscan` 과 같은 방식, 서버 설정 0).
- 보안 바닥 ①~④: 원본 30/30, **10-16 인수 최종 32/32 통과** — 옛 SQL(D2a 앞 판 63aba86) vs 새 SQL 응답 **3,732/3,732 쌍 글자 그대로 같음**(23명 × 15 창구 갈래), 미리 센 글 수 19가지 쓰기 섞은 뒤 24개 게시판 모두 같음, 상한 개수 권한 조건 그대로, 익명 글쓴이 0.
- L 기준을 아직 넘는 것: **오늘 브리핑 384ms(기준 300)** · 폴더 목록 p95 158ms(p50 95) · 자료실 **깊은 쪽(page=501, 화면 상한 밖)** 172ms. S→L 3배 넘는 것: 자료실 검색(흔한 낱말 · D2b 범위) 5.0배.

## ① 창구별 전 · 후 (ms, 서버 p50 · 7번 · 데우기 1번 뺌 — run3)

| 창구 | 기준 | D1 S / M / L p50 | D2a S / M / L p50 | D2a L p95 · 깊은 쪽 p95 | 질의 수 D1→D2a(L) | L/S D1→D2a | 판정 |
|---|---|---|---|---|---|---|---|
| `/community/api/cafe` A1 | 300 | 77.1 / 3210.1 / 16319.6(500) | 27.8 / 26.3 / 57.9 | 63.5 | 14 → 16 | 211.67 → 2.08 | 통과 |
| `/community/api/boards` A2 | 150 | 24.3 / 109.0 / 2186.7 | 14.2 / 15.5 / 12.4 | 13.1 | 12 → 12 | 89.99 → 0.87 | 통과 |
| `/community/api/boards?area=cafe` A2 | 150 | 22.0 / 75.2 / 1253.1 | 14.0 / 15.9 / 14.8 | 15.1 | 12 → 12 | 56.96 → 1.06 | 통과 |
| `/tbox/api/files` A3 | 150 | 64.0 / 2486.0 / 4558.5 | 19.4 / 20.9 / 17.7 | 22.5 · **172.2**(쪽 501) | 12 → 12 | 71.23 → 0.91 | 깊은 쪽만 넘음 |
| `/tbox/api/folders` A4 | 150 | 102.9 / 1356.2 / 3125.9 | 37.7 / 60.6 / 95.2 | **158.0** | 14 → 15 | 30.38 → 2.53 | p95 넘음 |
| `/community/api/pages?shared=me` A5 | 150 | 125.6 / 1074.2 / 6126.0 | 38.7 / 42.3 / 29.4 | 30.8 | 14 → 14 | 48.77 → 0.76 | 통과 |
| `/community/api/pages?space=`(개인) A6 | 150 | 38.6 / 48.5 / 2233.0 | 23.7 / 42.3 / 44.3 | 45.9 · 61.0 | 16 → 18 | 57.85 → 1.87 | 통과 |
| `/community/api/pages?space=`(팀) A6 | 150 | 38.7 / 81.5 / 546.3 | 44.2 / 49.6 / 64.4 | 66.3 · 70.7 | 16 → 18 | 14.12 → 1.46 | 통과 |
| `/community/api/pages?tree=1` A6 | 150 | 26.2 / 77.3 / 2153.0 | 32.8 / 33.7 / 56.3 | 60.2 | 14 → 15 | 82.18 → 1.72 | 통과 |
| `/community/api/pages?q=`(공간 안) A6 | 1000 | 33.2 / 77.9 / 505.4 | 38.4 / 56.9 / 72.4 | 74.2 | 16 → 18 | 15.22 → 1.89 | 통과 |
| `/community/api/me/trash` A7 | 150 | 24.5 / 320.0 / 3108.9 | 24.9 / 27.9 / 26.7 | 28.6 | 12 → 12 | 126.89 → 1.07 | 통과 |
| `/community/api/briefing` A8 | 300 | 172.0 / 1460.3 / 4677.9 | 149.2 / 252.9 / **384.4** | 393.6 | 36 → 37 | 27.2 → 2.58 | **기준 넘음** |
| `/community/api/notifications` A9 | 150 | 416.6 / 403.0 / 610.3 | 52.0 / 55.7 / 61.8 | 61.9 | **122 → 24** | 1.46 → 1.19 | 통과 |
| (함께 잼) `/tbox/api/files?q=` 흔한 · 드문 | 1000 | 766.6 · 114.0 (L) | 118.0 · 39.6 (L) | 120.2 · 44.1 | 21 → 21 | 17.5 → **5.02** · 1.72 | 흔한 낱말 5배(D2b 검색) |
| (함께 잼) 파일 즐겨찾기 · 최근 · 폴더 즐겨찾기 · 공간 상태 거르기 · 안 읽은 알림 수 · 게시판 커서 목록 | 150 | — | L 9.4 · 16.6 · 12.8 · 35.0 · 22.1 · 63.9 | ≤ 66 | 같음 | ≤ 1.8 | 통과(Authz 개인 갈래 모양 변경의 회귀 없음) |

- 질의 수 = D1 과 같은 셈(PDO 의 `DEALLOCATE` 포함 — 문장 하나가 2). 알림 24 = SQL **12개**(세션 · 동의 · 권한 표 등 고정 8 + 개수 · 목록 · 별칭 · 글 묶음 읽기) — 이 페이지 알림 조건에서 지시서 A9 「≤ 15」를 만족한다. 권한요청/만료생성의 한계는 ⑤에 별도 기록했다. 공간 목록 16→18 은 JIT 끄기 트랜잭션(`SET LOCAL` 1문장 ×2)이다.
- 깊은 쪽 = 약 1만 번째 줄(`page=501`). 화면은 개수 상한(1,000건 = 50쪽)까지만 쪽 번호를 그린다. 토큰 API 의 깊은 OFFSET 은 남아 있다(D2b 「나머지 OFFSET」).
- 측정 조건은 D1 ④ 와 같다(같은 기계 · 같은 클러스터 · 운영 부하가 섞인다). run3 시작 여유 11.6GB · 최저 9.07GB(L 생성 직후) · DB L 2.44GB.

## ② 바꾼 질의 · 새 색인 · 트리거

| 항목 | 바꾼 것(결과 같음은 ③) |
|---|---|
| A1 카페 홈 `cafe_home.php` | 「최근 10」 = 두 갈래 `UNION ALL` — ① 읽을 수 있는 카페 게시판마다 그 게시판 글(LATERAL · `pages_board_new_idx` 순서 · 10개) ② 카페 게시판마다 거기 게시된 개인 페이지(`pages_published_new_idx` · 10개) → 합쳐 10개, users · 공간 이름은 남은 10행에만. 「인기 5」 = 같은 두 갈래 · 30일 창으로 후보를 모은 뒤 7일 조회 순. 정렬 · 조인 키 CASE 0. 게시판 글 수 = `spaces.post_count` · 24시간 새 글 = 두 갈래 count(겹치면 한 번) |
| A2 게시판 글 수 | `community.spaces.post_count integer NOT NULL DEFAULT 0` + 문장 단위 트리거 `scale_board_post_count`(INSERT · DELETE · UPDATE의 transition table). 한 문장의 게시판별 증감을 모두 집계하고 ID 오름차순으로 갱신한다. 관련 칸이 그대로인 UPDATE는 바로 끝나므로 본문 자동 저장의 게시판 UPDATE 실행 0 · xmin 불변. 정의 = 옛 하위 질의 그대로(한 글이 두 조건이면 1번 · 게시판 행만). `boards_list` · `cafe_home` 이 읽는다 |
| A3 자료실 `files_list.php` | 두 겹: 안쪽이 권한 조건 · 폴더 · 검색으로 거른 뒤 정렬 · LIMIT · OFFSET 으로 번호 20개 → 바깥이 그 20행에만 users · teams. 폴더 조건 `IS NOT DISTINCT FROM` → 루트 `IS NULL` / `= :folder` 두 고정 조각. 안쪽 정렬 앞에 `f.folder_id` 를 둬(거른 행은 한 폴더라 순서 같음) `(folder_id, 정렬 키)` 색인 순서로 한 쪽만 읽는다(루트의 `IS NULL` 은 계획기가 「한 값」으로 보지 않아 전체 정렬을 했다). 개수 = `LIMIT 1001` 상한 → `total`(≤1000) + `total_capped` |
| A3 · A4 `Authz::privateVisibleSql`(개인 갈래 **모양만**) | 행마다 `EXISTS(… LEFT JOIN folders pv_c1 … pv_c5)` → 「내가 닿는 개인 폴더」 묶음을 문장마다 한 번(상관 없는 재귀 하위 질의: 내가 만든 · 나에게 권한 준 개인 폴더에서 개인 폴더만 따라 아래로 4걸음) + 파일: 올린 사람 · 파일 권한 · 든 폴더 ∈ 묶음 / 폴더: 자기 ∈ 묶음. 옛 사슬(위로 개인인 동안 5 · 4걸음)과 같은 묶음(머리글에 증명 요지). NULL 이 나오지 않게(불리언 칸으로도 쓰임). `privateReach`(한 건 판정)는 그대로 |
| A4 폴더 `folders_list.php` | 하위 폴더마다 `LATERAL (… LIMIT 101)` · 보이는 하위 폴더 번호만 → `file_count`(≤100) + `file_count_capped`. 그 문장만 JIT 끔 |
| A5 공유 받은 글 `pages_list.php?shared=me` | 모든 글에서 LATERAL 로 묻던 것 → `page_shares(user_id = 나 OR group_id ∈ 내 그룹)` 에서 출발해 글마다 max 등급 → 그 글만 읽기. 행 관문 그대로. 개수 상한 1,001. 지시서의 하위 재귀 추가는 하지 않았다: 기존 API는 직접 공유된 글만 목록에 넣으며, 하위 글까지 추가하면 응답 집합이 달라지기 때문이다 |
| A6 공간 목록 · 나무 | 개수 상한 1,001 · 목록은 옛 순서(**고친 시각 DESC** — 지시서의 `(position, id)` 는 나무 순서라, 목록 순서를 바꾸지 않으려고 새 색인 `pages_space_updated_idx` 로 대신) · 담당자 이름 · 역할은 줄마다 기본 키 하위 질의(조인이면 계획기가 사용자 표를 줄마다 훑었다 — M 377줄 × 860) · 개수 · 목록 · 나무 문장 JIT 끔. 나무는 이미 그리는 칸만이고 L 56ms 라 「뿌리 먼저」는 하지 않았다 |
| A7 휴지통 | 질의 그대로 · 색인 둘(지운 사람 · 지운 하위) |
| A8 브리핑 `Briefing.php` | 「내 것」 CASE 식 → 겹치지 않는 두 갈래(내 개인 공간 글 · 개인 공간 밖 내가 쓴 글) `UNION ALL` · 공지도 두 갈래(공지 게시판 글 · 공지 게시판에 게시된 글) · 갈래마다 LIMIT 20 · 공간 · 주인 조인과 조건절은 `EXISTS` 안(바깥이 글 표 하나라 색인 순서로 20개에서 멈춤) · 반복 작업 조건은 오늘 실행에서 출발(`p.id IN (unnest…)`) · 문장 전체 JIT 끔. 캐시 0 |
| A9 알림 | 글마다 `community_page_row()`(50번) → `community_page_rows_nodoc()` 한 번(같은 SQL 한 벌의 `WHERE` 만 `= ANY`) · 판정은 줄마다 같은 함수 |
| 화면 | `app.js`: 「전체 1,000+건」 · 「1,000+개의 파일」(쪽 번호 상한 50) · 「파일 100+」 / `editor.js`: 공유받은 「1,000+개」 · 공간 찾기 「페이지 1,000+개」 / `automations.js`: 공유 목록을 상한 너머까지 받기(빈 쪽까지). 커뮤니티 부품 버전 다시 찍음 |

새 색인(L 합성 크기 — `index-sizes-run2.txt`, 표: 글 812MB · 파일 158MB):

| 색인 | 정의 | L 크기 |
|---|---|---|
| `pages_space_updated_idx` | pages(space_id, updated_at DESC, id DESC) WHERE deleted_at IS NULL | 28 MB |
| `pages_author_updated_idx` | pages(author_id, updated_at DESC, id DESC) WHERE deleted_at IS NULL | 30 MB |
| `pages_author_due_idx` | pages(author_id, due_date) WHERE deleted_at IS NULL AND due_date IS NOT NULL | 1.9 MB |
| `pages_author_status_idx` | pages(author_id, status) WHERE deleted_at IS NULL AND status IS NOT NULL | 0.5 MB |
| `pages_trash_by_idx` · `pages_trash_parent_idx` | pages(deleted_by, deleted_at DESC) · (parent_page_id, deleted_at) WHERE deleted_at IS NOT NULL | 0.4 · 0.3 MB |
| `page_shares_group_idx` · `share_group_members_user_idx` | page_shares(group_id) WHERE group_id IS NOT NULL · share_group_members(user_id, group_id) (10-16 인수 검토: 미반영 C 후보와 동명 색인 정의를 맞춤) | 기존 대량 측정은 합성 0행 · 복합 정의 변경 후 크기 미측정 |
| `files_folder_created_idx` | files(folder_id, created_at DESC, id DESC) WHERE deleted_at IS NULL | 11 MB |
| `files_folder_name_idx` · `files_folder_size_idx` | files(folder_id, original_name) · (folder_id, size_bytes) WHERE deleted_at IS NULL — 화면 정렬 고르개(이름 · 크기 × 오름 · 내림 — 같은 값 안 id 는 증분 정렬) | 16 · 7 MB |
| `files_pending_uploader_idx` | files(uploaded_by, created_at DESC, id DESC) WHERE scan_status='pending' AND deleted_at IS NULL | 합성 0행 |

트리거: `community.scale_board_post_count()`(SECURITY INVOKER · search_path=pg_catalog · PUBLIC 실행권 회수) + 트리거 셋(`scale_board_post_count_insert/_delete/_update`).

2026-10-16 독립 검토 보정: 이전 행 단위 트리거는 이전 게시판 감소 → 새 게시판 증가 순서여서 A→B/B→A 동시 변경과 여러 게시판 글 삭제에서 교착을 재현했다. 새 트리거는 문장 전체의 이전/이후 집계를 상쇄한 뒤 남은 게시판만 ID 순서로 한 행씩 UPDATE한다. 비키열 `post_count`만 바꾸므로 FK의 KEY SHARE와 충돌하는 `FOR UPDATE` 잠금은 추가하지 않는다. PostgreSQL 14에서는 [transition table과 UPDATE OF를 함께 쓸 수 없으므로](https://www.postgresql.org/docs/14/sql-createtrigger.html) UPDATE 트리거 호출은 문장마다 한 번 생긴다. 대신 관련 칸이 그대로면 집계 전에 반환한다. 본문 자동 저장에서 **게시판 UPDATE 문장·행 0, xmin 불변**을 별도 관찰 트리거로 확인했다. 원래 지시서의 「트리거 호출 0」와 이 선택을 구분한다.

현재 삭제·복원 핸들러는 가지 전체를 한 UPDATE로 처리하므로 게시판 전체 순서가 고정된다. 위치 이동은 parent/position, 범위 cascade의 게시 중인 글은 published_from만 바꾸므로 개수 갱신이 없다. **임의의 여러 문장을 한 트랜잭션에서 A→B / B→A 순서로 실행하면 여전히 교착할 수 있다**(별도 재현, 실패 변경은 롤백돼 개수 일치). 이 보정은 모든 글쓰기를 전역 잠금으로 직렬화하지 않는다. 앞으로 여러 개수 변경 문장을 묶는 기능은 트랜잭션 전체 게시판 잠금 순서를 지켜야 한다.

## ③ 보안 바닥 ①~④ — `tests/scale/d2a_equivalence.py`(카탈로그 `core.scale_d2a_equiv`) · 원본 30/30, 인수 메인 32/32

| 바닥 | 무엇을 | 결과 |
|---|---|---|
| ① 옛/새 결과 같음 | 합성 DB(d1_seed S + `d2a_mix.sql`: 사람 · 그룹 · 지운 그룹 · 비활성 회원 공유 · 개인 폴더 사슬 깊이 1~5 + 권한 자리 9 · 파일 권한 · 익명 게시판 글 · 댓글 · 별칭 · 숨김 · 지운 글 · 휴지통 뿌리/하위 · 게시 · 공지 읽음 · 검사 중 파일 · 할 일 블록 · 마감 · 검증 만료 · 상한 경계 묶음)에 PHP 둘(새 판 · 옛 판 63aba86). 23명(관리자 2 · 팀장 3 · 직원 16 · 손님 2 — 팀 9개) + 비활성 1 × 자료실(루트 정렬 6 · 쪽 2·3·50·51·170 · 폴더 36곳 · 정렬 깊은 쪽 · 전역 검색 둘) · 폴더 37곳 · 카페 홈 · 게시판 3 · 공유 받은 글 7 · 공간 7곳 × 5 · 나무 · 휴지통 · 브리핑 · 알림 2 | **3,732/3,732 쌍 같음**(응답 JSON 전체 — 목록 id · 순서 · 모든 칸, 상한 칸만 옛 값에서 계산) · 500 0 |
| ② 미리 센 글 수 | 만들기 · 숨김/지운 채 만들기 · 게시판↔게시판 · 게시판→팀 옮기기 · 게시 · 자기 게시판에 게시(같은 번호 한 번) · 다른 게시판으로 다시 게시 · 게시 취소 · 숨김 · 숨김 풀기 · 지움 · 되살림 · 게시된 글 지움 · 영구삭제(purged_at · 행 DELETE) · 숨김+지움 한 문장 · 둘 다 되살림 · 두 세션 동시 200+200 · 이주 다시 돌림 | 단계마다 **24개 게시판 모두 post_count = 그때 센 값**(19번) · 개인 · 팀 공간 0 · 본문/제목만 고치면 게시판 행 xmin 그대로 · 이주 재실행 채움 0행 |
| ③ 상한 개수 = 같은 권한 조건 | ①의 total 대조(사람마다 옛 total 은 권한 조건으로 센 값) + 명시 경우 | 직원: 1,200개 중 보이는 300 → 300(관리자 전용 900 셈 0) · 관리자 1,000+ · 못 읽는 1,100개 폴더 403 그대로 · 폴더 수 100+ / 50 · 안 보이는 폴더 없음 · 공유 1,050 → 1,000+ |
| ④ 익명 가림 | 모든 사람 시점으로 카페 홈(최근 · 인기) · 알림 · 브리핑 | 익명 게시판 글 63줄 · 알림 135줄에서 글쓴이 아이디 · 이름 0(이름표만) · 브리핑에 사람 칸 0. 기존 시험 `community.cafe_c1_http` 215/0 |

추가 대조: `tbox.g0_private_grants`(privateReach ↔ 목록 대조 시험)는 이 환경에서 준비 단계가 기준 판과 똑같이 멈춘다(아래 ⑦ — 미검증).

## ④ 보이는 변화 (DESIGN 2.4 에 적음)

① 자료실 목록 · 공유받은 페이지 · 공간 페이지 목록 개수가 1,000 을 넘으면 「1,000+」(쪽 번호는 상한까지만 — 50쪽). ② 폴더 줄 파일 수 100 을 넘으면 「파일 100+」. 응답에 새 칸 `total_capped` · `file_count_capped`(기존 칸 이름 · 뜻 그대로, `total` · `file_count` 는 상한 값).

## ⑤ 남은 기준 넘음 · D2b 로 넘길 것

| 창구 | L | 까닭 · 다음 |
|---|---|---|
| 오늘 브리핑 | p50 384 / p95 394(기준 300) · L/S 2.58 | 문장 13개 × 계획 시간 약 10ms(큰 권한 조건절 — 갈래로 나눠 둘씩) + 할 일 블록 갈래가 내가 쓴 글 본문(5,293개)을 다 읽음(110ms). 지시서대로 캐시는 넣지 않음 — 사람별 짧은 캐시(60초) 또는 「내 것」 묶음을 한 문장으로 모으기 · 할 일 블록 색인(page_blocks 의 종류)을 D2b 후보로 |
| 폴더 목록 | p50 95 / p95 158 | 폴더마다 ≤101행(상수)이고 늘어난 것은 화면의 폴더 수(S 40 → L 129). 폴더가 수백이면 넘는다 — 폴더별 미리 센 수(트리거)가 다음 |
| 자료실 깊은 쪽 `page=501` | 172 | 화면 밖(상한 50쪽). 토큰 API 의 OFFSET — D2b 「나머지 OFFSET」 커서 |
| 자료실 흔한 낱말 검색 | 118(기준 1,000 안) · S→L 5.0배 | D2b 검색 |
| (중간 판 기록) | run1 — 공간 목록 · 나무 2.1~2.9s(JIT) · 브리핑 709 · 자료실 루트 정렬 / run2 — 브리핑 공지 문장 JIT · 폴더 JIT | 고친 뒤 run3. 세 판의 원문은 결과 폴더에 그대로 |

2026-10-16 독립 검토 추가: A9의 12개 SQL은 **페이지 알림 50개**인 측정 조건이다. 권한 요청 알림(`access_request`·`access_decided`)은 항목별 조회가 남고, 만료 알림을 처음 만드는 요청도 건별 조회·생성이 남아 있다. 모든 알림 유형에서 SQL ≤15를 달성했다는 뜻이 아니다. 기존 결과·인가를 유지한 채 이 두 갈래의 일괄 처리는 후속 범위로 기록한다.

## ⑥ 2억 8500만 글 외삽 다시 (D1 ③ 방식 — run3 `summary.json` 의 `extrap`)

| 창구 | k(D1 → D2a) | 2억 8500만에서(어림) |
|---|---|---|
| 카페 홈 | 1.42 → 0.23 | 136,234s → 0.2s |
| 공유 받은 글 | 1.01 → 0 | 3,738s → 수십 ms(공유 수 비례) |
| 게시판 목록 | 1.25 → 0 | 6,219s → 수십 ms(게시판 수 비례) |
| 휴지통 | 1.37 → 0.03 | 18,305s → 수십 ms |
| 알림 | 0.09 → 0.06 | 1.1s → 0.1s |
| 공간 목록 · 팀 · 나무 · 공간 찾기 | 1.19 · 0.72 · 1.21 · 0.76 → 0.11 · 0.11 · 0.17 · 0.2 | 7,522 · 53 · 4,496 · 62s → 0.1~0.2s(나무는 공간 크기 비례 — 한 공간 2,000줄 상한) |
| 폴더 목록 | 1.22 → 0.36 | 12,286s → 1.0s(폴더 수 비례) |
| 브리핑 | 0.87 → 0.25 | 1,133s → 1.9s — 기준 넘음(⑤) |
| 자료실 목록 | 1.61 → 0.9 | 237,780s → 첫 쪽은 평평(L 18ms) — k 는 깊은 쪽(OFFSET 1만) 때문, 화면 밖 |

(D1 · D2a 모두 15초 문장 마감 전의 「시간」 어림 — 순서를 보는 값.)

먼저 무너지는 다섯(D1) 중 카페 홈 · 공유 받은 글 · 공간 목록은 k ≈ 0 ~ 0.2(커서 게시판 목록과 같은 꼴), 자료실 첫 쪽도 평평. 남은 것은 브리핑(문장 수 × 계획 시간)과 폴더 목록(폴더 수)이다.

## ⑦ 이주 운영 적용 어림 · 실행한 시험

| 이주 | 잠금 | 어림 |
|---|---|---|
| `2026-10-26_scale_d2a_board_counts.sql`(두 트랜잭션 · 각각 lock_timeout 3s · statement_timeout 600s) | ① `ALTER TABLE spaces ADD COLUMN … DEFAULT 0`(PG14 — 표 다시 쓰기 0)의 ACCESS EXCLUSIVE를 바로 COMMIT으로 해제. ② `LOCK pages IN SHARE ROW EXCLUSIVE` → 트리거 교체와 채움 → COMMIT. 두 번째 단계는 pages 쓰기를 막고 spaces 일반 조회는 허용한다. 첫 단계 뒤 실패하면 재실행해 두 번째 단계를 완료해야 한다 | 운영 행 수와 적용 시간은 미검증. 기존 「쓰기 멈춤 1초 안」 추정은 보장하지 않는다. 합성 시험에서 실제 채움 뒤 COMMIT 직전 잠금을 유지한 채 spaces/pages 조회 성공, ACCESS EXCLUSIVE 없음 확인. 원래 한 트랜잭션 판은 이 지점의 spaces 조회가 잠금 시간 초과였음 |
| `2026-10-26_scale_d2a_indexes.sql`(CONCURRENTLY 12개 · BEGIN 없음) | SHARE UPDATE EXCLUSIVE(읽기 · 쓰기 그대로) — 끝나기 전 열린 트랜잭션을 기다림 | 운영 생성 시간은 미검증. 적용 전후 이름·정의·indisvalid·indisready를 대조한다. 실패 시 invalid 색인 유무를 기록하고 자동 삭제·재시도하지 않는다 |

반영 순서: **이주(둘) → 코드**. 코드가 `spaces.post_count` 를 읽으므로 이주 전 코드면 게시판 목록 · 카페 홈이 500 이다(운영 사본에 옛 이주만 거는 `community.stage5c` 가 그렇게 실패 — 아래).

실행한 명령 · 결과(원문 `~/.cache/qaredi-check/runs/32 · 33 · 34`, 결과 폴더 `*.log`):

| 명령 | 결과 |
|---|---|
| `python3 tests/scale/d2a_equivalence.py --output …/equiv-final` (최종 판) | `D2A_EQUIV pass=30 fail=0 pairs=3732/3732 synthetic_db=dropped` |
| `python3 tests/scale/d2a_equivalence.py --output …/resume-codex-20261016/accept-final` (인수 최종) | **32/32**, **3,732/3,732** 전체 응답 일치, 동시 삽입 정확히400행, DB DROP·서버·임시 디렉터리 정리 확인. 15.8분, 여유 11.51→11.48GB. |
| `python3 tests/scale/d2a_counter_lock.py --output …/resume-codex-20261016/counter-fix-acceptance` (2026-10-16 트리거 보정) | **28/28**: FK가 있는 새 격리 PG14에서 단일문장 반대 이동, 실제 핸들러 형태의 서브트리 동시 삭제·복원, 두 세션 **삽입 400건 완료·개수 일치**, 본문·위치·published_from 변경의 게시판 UPDATE 문장/행 0·xmin 불변, 중복 게시 한 번·숨김·삭제·복원·영구삭제·재적용, 권한/소유 보존, 채움 중 조회 허용. 임의 복수문장의 잔여 교착도 구분해서 재현. DB DROP·클러스터 정지·디렉터리 삭제 확인. 후보 함수 변경 없이 시험용 관찰 트리거가 첫 게시판 쓰기 직후 0.4초 지연해 동시 진입을 보장 |
| `python3 tests/scale/d2a_budget.py --output …/budget` (M) | 최초 43/5. JIT 제어문 증가에 맞춰 질의 수 상한을 보정하고 같은 원본을 `--from-dir …/budget`로 재판정해 48/0(2026-10-15 16:05). 새 시간 측정이 아니며 시간 목표는 그대로. 10-16 독립 검토에서 계획 증거 누락을 발견해 이 통과 판정은 최종 수락 근거로 사용하지 않음(아래 인수 검토). |
| `python3 tests/scale/d1_measure.py --tiers S,M,L --l-pages-factor 0.5 --l-other-factor 0.33 --select …`(창구 21) | run3 — ① 표 |
| `scripts/security_lint.sh` · `php -l`(바뀐 PHP 전부) · `node --check`(app.js · editor.js · automations.js) · `php scripts/community_asset_version.php --check` · `php scripts/wiki_check.php` | 금지 패턴 0 · 구문 0 · 부품 버전 맞음(f9367461b689) · 위키 70편 문제 0 |
| `tests/run.sh run`(A1~A9 가 닿는 기존 묶음 15 — 후보 #32 · 같은 묶음을 기준 판 사본으로 #33 · 고친 뒤 다시 #34) | 통과: `community.cafe_c1_http` 215/0 · `community.qf12_server_http` 204/0 · `tbox.stage89_cross_regression` 196/0 · `community.qf12_floor_http` 268/0(#34 — #32 는 스키마 절 머리에 색인 이주 이름을 빠뜨려 실패 → 고침) · `tbox.file_rename` 123 PASS/0 FAIL(#34 — #32 는 동시 부하로 25초 읽기 시간 초과). **기준 판과 똑같이 실패**(실패 줄 집합 같음 — 이 판과 무관): stage7a 38/17 · qf2b_files_search 32/8 · dbg_s_security 44/3 · stage5a 131/57 · stage5d 36/57 · stage89_http · stage89_backend · stage9d(합계 줄 형식) · g0_private_grants(준비 단계 rc 4). **다름 하나**: `community.stage5c` 110/78 — 실패 수 같고 한 줄이 달라짐: 같은 팀 직원의 게시판 목록이 기준 판은 200(팀 게시판 없음) · 이 판은 **500** — 운영 사본에 D2a 이주를 걸지 않는 시험이라 `post_count` 칸이 없다(반영 순서 문제 — 위) |

## ⑧ 밤 회귀 대상 시험 id

2026-10-16 최종 선택(운영 반영 뒤 대기열에 넣음): `community.cafe_c1_http` · `community.qf12_server_http` · `community.qf12_floor_http` · `community.stage89_http` · `community.stage89_backend` · `community.stage89_integrity` · `community.stage89_legacy` · `community.stage9d` · `community.qf12_work_browser` · `tbox.stage89_cross_regression` · `tbox.file_rename` · `core.scale_d2a_counter_lock` · `core.scale_d2a_evidence` · `core.scale_d2a_equiv` · `core.scale_d2a_budget`.

제외: `stage4d/5a/5c/5d/7a`, `g0_private_grants`, `qf2b_files_search`, `dbg_s_security`는 운영 DB를 pg_dump로 읽으므로 이번 범위의 격리 규칙에 맞지 않는다. `api_folders/api_read`도 운영 시험이어서 제외한다. `stage5c` 이주 누락의 게시판 500은 같은 창구의 합성 최종 동등성 결과로 별도 확인한다. 정적 검사는 최종 후보에서 끝내므로 밤에 전체 린트를 중복 실행하지 않는다. 기존 known_fail 정책은 유지하며 새 실패를 그 목록에 숨기지 않는다.

## 한계 · 지킨 것 · 못 지킨 것

### 2026-10-16 인수 검토 — 완료 판정 전 보정 중

- Claude는 10-15 16:05 예산 재판정 뒤 보고서를 마무리하기 전에 주간 한도로 중단했다. 기존 36파일과 결과는 `resume-codex-20261016/before/` 및 SHA256 manifest에 보존했다. 이 문서의 앞부분 수치는 당시 구현자가 잰 값이다.
- 기존 예산 시험은 계획이 없거나 실패해도 통과했고, 동시 쓰기 시험은 작업 스레드의 실패를 부모 시험에 전달하지 않았다. 누락·실패를 거절하는 판정과 정확한 400건 삽입 검사를 보완했다. 합성 JSON/가짜 작업/가짜 소켓 기반 음성 시험 28/28 통과. 중간 재판정 44/4를 보존했고, SQL·인자·SET LOCAL 설정까지 대조하는 최종 재판정은 **40/8**(계획 증거 부족 4곳 + 옛 계획에 설정 기록이 없는 폴더·공간·팀공간·나무 4곳)이다. 이 8건을 성능 실패로 단정하거나, 증거 없이 통과로 바꾸지 않는다. 원본 `budget/` 파일은 재판정 전후 SHA256 동일하며 새 결과는 `resume-codex-20261016/budget-rejudge/`에 있다.
- 기존 M 예산 시험은 30.1분으로 지시서의 20분 목표도 충족하지 못했다. 최종 시험은 측정·정리 시간을 따로 기록한다. L의 남은 성능 목표 초과는 앞의 ⑤처럼 그대로 공개한다.
- 독립 검토에서는 실제 새 PostgreSQL 14 임시 클러스터로 기존 글 수 트리거의 교착과 이주 중 조회 차단을 재현했다. `resume-codex-20261016/lock-repro-result.json`에 오류·잠금·정리 확인을 남겼다. 후보에 대한 보정 및 최종 수락은 진행 중이며 운영 반영 전이다.

- **운영 DB 를 읽은 시험이 있었다(지시 위반 — 보고).** `tests/run.sh` 의 temp-instance 묶음 중 sudo 를 쓰는 7개(stage5a · 5c · 5d · 7a · qf2b · dbg_s · g0)는 `pg_dump tbox_re` 로 운영 DB 를 **읽어** 임시 DB 를 만든다(쓰기 0 · 임시 DB 는 시험이 지움). 후보 · 기준 판 비교로 두 번 돌렸다. 시작 전 시험 스크립트를 확인하지 않은 실수다. 운영 .env · env-src 내용은 열지 않았다(경로만 `QCHECK_ENV_SRC` — 파이썬 틀 다섯에 같은 규칙을 넣음).
- 합성 DB DROP: L 을 잰 세 번 모두 도구의 끝 정리가 「다른 연결이 쓰는 중」으로 5분 재시도 뒤 실패해, 그때마다 손으로 `dropdb` 하고 0개를 확인했다(D1 과 같은 증상 — 남은 연결은 확인 시점에 0). 도구 수정은 하지 않음.
- 측정은 같은 기계의 운영 부하와 겹친다(D1 한계 ③). 브리핑 · 폴더는 판마다 ±20% 흔들렸다.
- 「할 일 블록」 갈래는 한 글에 할 일이 20개를 넘어 경계에 걸리면 옛 질의와 같이 고르는 줄이 정해지지 않는다(옛 질의도 같은 미정 — 합성 자료는 글마다 2개).
- 보안 핵심 파일: `src/Authz.php`(개인 갈래 SQL 모양 — 같음 증명 ①) · `db/migrations` 새 파일 둘 · `db/schema.sql` · `db/community/schema.sql`(새 색인 · 칸 · 트리거 반영)만. `CommunityAuthz.php` 는 고치지 않았다(공유 판정 SQL 은 `pages_list.php` 에 있다).

### 10-16 보정 전 메인 새 M 측정 — 기준 미달 1개 보존

`resume-codex-20261016/budget-final`의 새 합성 M(글10만) 전체 읽기 SQL 계획 측정은 47/48. 브리핑 p50 278.6ms/p95 305.2ms로 300ms 기준 초과. 다른 47항목의 HTTP·시간·질의 상한·전체계획은 통과했다. 13.2분, 최소 디스크10.22GiB/DB1.093GiB, 종료 후 DB·프로세스·임시폴더 정리 확인. 이때 임계값을 완화하거나 실패를 통과로 표시하지 않고 배포 관문을 닫았다. 후속 보정과 실제 보충 gate 검증 결과는 아래에 구분한다.

합성 이전판 업그레이드 검사는 `synthetic-upgrade/result.json` 56/56: 112표/5892기존행·소유권·ACL·시퀀스 보존,12색인/3문장트리거,이주2회,소스SHA불변. 운영자료/덤프 사용0.

|10-16 새 M 창구|p50 ms|p95 ms|깊은 쪽 p95 ms|기록 질의 수|전체 계획|
|---|---:|---:|---:|---:|---:|
|GET /tbox/api/files|21.5|21.8|95.8|12|6|
|GET /tbox/api/folders|35.1|44.8|—|15|6|
|GET /community/api/cafe|21.7|27.7|—|16|7|
|GET /community/api/briefing|278.6|305.2|—|37|17|
|GET /community/api/pages|43.8|46.5|41.5|18|8|
|GET /community/api/pages#team|38.7|40.0|44.5|18|8|
|GET /community/api/pages#tree|25.9|27.4|—|15|6|
|GET /community/api/pages#shared|39.1|43.5|—|14|6|
|GET /community/api/me/trash|23.9|25.2|—|12|5|
|GET /community/api/notifications|59.9|62.6|—|24|11|
|GET /community/api/boards|14.0|15.3|—|12|5|
|GET /community/api/boards#cafe|15.3|16.6|—|12|5|

총90개 실행계획. 이 보정 전 측정 원본과 실패 판정은 보존한다. 브리핑 보정 후 그 항목만 측정한 결과와 별도 권한 비교는 아래에 구분한다.

### 브리핑 성능 보정 결과와 권한 비교 분리

`briefing-fix/tier-M.json`: 동일 M 데이터·기존 7회 방식에서 p50 170.1ms/p95 223.1ms, 질의37·전체계획17, 성능 **4/4**. 이후 같은 DB의 권한 fixture를 적용하는 **psql 배치가 120초 제한에 걸려** 전체 실행은 **11통과/1실패**이며, 이 실행에서는 24명 응답 비교를 시작하지 못했다. 기록은 배치 전체의 timeout이므로 마지막 ANALYZE만 원인이라고 확정하지 않는다. DB/프로세스/디렉터리 정리를 확인했으며 원본 실패 결과는 그대로 보존한다.

추가 `briefing-semantics/result.json`은 **14통과/0실패**, 기준 `63aba86` 대비 **24/24쌍 전체 응답 일치**(generated_at만 제외): 활성23명은200, 비활성1명은401이다. 사람1000·팀30·기본 글1000의 새 작은 합성 DB에서 동일 fixture 데이터 문장을 적용하고 마지막 ANALYZE만 제외했다. 소유자의 숨김·폼·직접 공유 폼·숨김 게시 글, 삭제 제외, 다른 팀 폼의 공유 유무, 공지 숨김 및 익명성·브리핑 종류를 확인했다. 성능은 다시 측정하지 않았다. DB 0.026GiB, 최소 여유11.36GiB이며 DB/프로세스/임시 디렉터리 정리를 확인했다.

원본 전체 M **47통과/1실패**, 후속 M 실행 **11통과/1실패**(성능4/4), 별도 작은 DB 의미 검사 **14/0**을 각각 보존한다. 동일 앱 소스 SHA와 원본 증거 SHA로 두 추가 검사를 연결했다. `resume-codex-20261016/acceptance-gate-precommit.json`의 실제 보충 gate 검증에서 기존 나머지11개 창구와 보정한 브리핑 성능을 합친 예산 **48통과/0실패**를 확인했다(`measured+briefing-performance-and-semantics`). 관문은 카운터28, 기존 동등성32·3,732쌍, 추가 브리핑24쌍, 합성 업그레이드56 및 실행 소스26개 SHA 연결을 확인한다. 이는 별도 실행의 증거를 결합한 판정이며 원본47/1이나 후속M11/1을 바꾸지 않는다. 후보 확정·최신 main 통합은 후속 단계다. **D2a 운영 배포는 아직 하지 않았다.**

측정기는 반복별 HTTP 상태를 저장하지 않는다. 별도 PHP로그 관찰도 상태코드를 제공하지 않았다(관찰 결과false, HTTP오류가 검출됐다는 뜻은 아님). 따라서 7회 모두200으로 주장하지 않으며 마지막 응답/프록시200과 별도24명 상태·내용 비교를 구분한다. 관찰로그원본도 보존한다.

### 최종 main 통합·정적 검사

기능 커밋 `6bba091` 뒤 운영 main `b6a6385`(붙여넣기 v2.68.3)를 합쳤다. JS 충돌은 동일 import의 생성 버전뿐이어서 최신 내용을 보존하고 버전을 다시 생성했으며, 두 작업의 보안/단계 이력을 모두 남겼다. 관련 수락 입력26개 중 Briefing.php의 SQL WHERE 한 줄에 공백 줄바꿈만 추가했다. 원문은 `resume-codex-20261016/briefing-format/Briefing.tested.php`에 보존하며 정확히 그 한 치환만 별도 관문에서 허용한다. 값·바인딩·조건식·실행 경로는 동일하다. 이 줄의 정적 lint 실패를 숨기지 않으며 수정 후 PHP 구문 및 lint0을 확인했다. 통합 PHP/JS19파일 구문, 자산42파일/75import, 위키70편/0문제 통과. 실행 소스의 의미 변화가 없어 대규모 측정과24명 비교를 반복하지 않았다. 배포 준비 시 동적 제외16표 중 audit_logs와 automation_runs를 제외에서 빼 기존행 보존 범위를 넓혔다.
