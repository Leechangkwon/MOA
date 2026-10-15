# 지시서 D2a — 규모 고치기 1차(먼저 무너지는 창구) (2026-10-15)

D1 보고(`docs/scale-d1-report.md` — 브랜치 `feat/scale-d1`) → 사용자 「백업 이후 규모 D2a 진행하자」.
기준 문서: 기획 **`/var/www/qaredi/docs/scale-d-plan.md`** 2장(기준 · D2 방법) · D1 보고 ②(원인 · 방안 후보) ③(외삽) · `docs/work-items/scale-b.md`(커서 · 정렬별 색인 선례) · `db/migrations/2026-10-25_scale_board_counts.sql`(미리 센 개수 트리거 선례) · `db/migrations/2026-10-25_scale_board_indexes.sql`(CONCURRENTLY 선례) · `/var/www/qaredi/CLAUDE.md` 2 · 4장 · `community/ARCHITECTURE.md` 25장(카페 익명 바닥).

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-scale-d2a-20261015`**(브랜치 `feat/scale-d2a` = main + `feat/scale-d1` 합침 — 메인이 만든다) · 결과 **`/home/changkwon/qaredi-scale-d2a-results-20261015/`** · 운영 · 운영 DB 0 |
| 범위(D2a) | 아래 A1~A9 만. 검색 · 내 작업 · 감사 · 이야기 피드 · 태그 수 · 나머지 OFFSET 은 **D2b**(손대지 않는다) |
| 보안 핵심 파일 | 이 지시서가 허락하는 것만: **`db/migrations/` 새 파일**(아래 이름) · **`src/Authz.php` 의 개인 갈래 SQL 모양**(A3 · A4 — 결과 같음 증명 필수) · `src/community/CommunityAuthz.php` 의 공유 판정 SQL 모양(A5 — 같음 증명 필수) · `db/schema.sql` · `db/community/*` 는 새 색인 · 칸 · 트리거를 반영하는 데만. `Auth` · `Http` · `Db` · `Upload` · `Doc` · `Audit` · `public/index.php` 0 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `Qaredi 규모 D2a: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : D1 에서 「먼저 무너지는」 창구들의 질의 모양을 「읽는 행 수 ≈ 화면에 보이는 행 수」로 바꿔, L(글 50만)에서 기준 안 · S→L 3배 이하로 만든다.
              화면 · 응답 모양 · 권한 · 익명 가림은 그대로(사용자에게 보이는 변화 = 아래 「보이는 변화」 둘뿐).
요구사항    : (창구마다 D1 보고 ② 의 그 줄이 원인 · 방안 후보다. 아래는 정한 것 — 더 나은 길이 있으면 써도 되나 보고에 까닭)
  A1 카페 홈 GET /community/api/cafe — 「최근 글」을 두 갈래 UNION ALL(게시판 글 = pages_board_new_idx 게시판별 · 카페로 게시한 글 = pages_published_new_idx)
     각 LIMIT 뒤 합쳐 LIMIT, users 조인은 남은 행에만. 정렬 · 조인 키에 CASE 식 0. 게시판별 글 수는 A2 의 미리 센 값.
     ※ 익명 게시판 글은 지금과 똑같이 가린다(글쓴이 · 익명N 별칭 — community_anon_* 그대로) — 시험으로 단언.
  A2 게시판 글 수 — community.spaces 에 미리 센 칸(예: post_count) + community.pages 트리거. 정의는 지금 하위 질의와 **정확히 같게**:
     deleted_at IS NULL AND hidden_at IS NULL AND (space_id = b.id OR (visibility='published' AND published_board_id = b.id)) — 한 글이 두 조건에 다 걸리면 1번.
     트리거는 관련 칸(space_id · published_board_id · visibility · deleted_at · hidden_at)이 바뀔 때만(UPDATE OF + WHEN) — 본문 자동 저장에는 안 돈다.
     이주에서 한 번 채움(view_count 선례처럼 차이 있는 행만). 쓰는 곳: 게시판 목록 GET /community/api/boards(?area=cafe 포함) · 카페 홈.
  A3 자료실 목록 GET /tbox/api/files — 정렬 · LIMIT · OFFSET 을 안쪽 질의에서 먼저 하고 users · teams 는 그 20행에만.
     `folder_id IS NOT DISTINCT FROM $` 를 IS NULL / = $ 두 갈래로. 색인 files(folder_id, created_at DESC, id DESC) WHERE deleted_at IS NULL
     (+ 화면이 고를 수 있는 다른 정렬마다 필요한 색인 — 화면 정렬 목록을 코드에서 확인해 정하고 보고).
     전체 개수는 상한 1,001 까지만 센다(같은 권한 조건) → 응답에 total(≤1000) + total_capped(true/false). 권한 조건절이 행 수를 낮게 어림해
     작은 표를 행마다 훑는 계획이면 Authz 개인 갈래 SQL 모양을 바꿔도 된다(결과 같음 증명).
  A4 폴더 목록 GET /tbox/api/folders — 하위 폴더마다 「볼 수 있는 파일 수」는 LATERAL (… LIMIT 101) 로 상한(「100+」). 개인 갈래 조상 판정이 행마다
     folders 를 훑는 것은 A3 과 같은 방식으로.
  A5 공유 받은 글 GET /community/api/pages?shared=me — 모든 글이 아니라 page_shares(user_id = 나) · 내 그룹의 공유에서 출발 → 그 하위 글만 재귀.
     색인 page_shares(user_id) 등 필요한 것. 개수는 상한(1,001).
  A6 공간 글 목록 GET /community/api/pages?space= · 나무 ?tree=1 — 개수는 상한(1,001), 목록은 pages_space_live_idx 순서(position, id)를 그대로 타게.
     나무는 그리는 데 쓰는 칸만 고른다(본문 · 큰 칸 0). 그래도 L 에서 150ms 를 넘으면 그때만 「뿌리 먼저 · 펼칠 때 자식」(보고에 적고 화면 동작 같게).
  A7 내 휴지통 GET /community/api/me/trash — 색인 pages(parent_page_id) WHERE deleted_at IS NOT NULL (+ 필요하면 deleted_by, deleted_at).
  A8 오늘 브리핑 GET /community/api/briefing — ① 안 읽은 공지는 공지 게시판 id 들에서 pages_board_updated_idx 로 직접 + 안 읽음 anti-join
     ② 부분 색인 files(uploaded_by) WHERE scan_status='pending' AND deleted_at IS NULL ③ 내 할 일 = 「개인 공간 글」 · 「내가 쓴 글」 두 갈래 UNION(CASE 0)
     + 필요한 색인(예: pages(author_id, updated_at DESC) WHERE deleted_at IS NULL). 캐시는 쓰지 않는다(APCu 없음 — 필요하면 보고만).
  A9 알림 GET /community/api/notifications — 행마다 community_page_row 를 부르지 말고 글 50개를 한 번(`= ANY`) + 권한 판정 한 번. 질의 수 상수(≤ 15).

보이는 변화 : ① 자료실 · 공유 받은 글 · 공간 목록 개수가 1,000 을 넘으면 「1,000+」(화면 쪽 번호는 상한까지만) ② 폴더 파일 수 100 을 넘으면 「100+」.
              그 밖에 화면 · 문구 · 응답 칸 이름이 바뀌면 안 된다(새 칸 추가는 됨). DESIGN.md 해당 절에 위 두 줄을 적는다.
이주        : db/migrations/2026-10-26_scale_d2a_indexes.sql — CREATE INDEX CONCURRENTLY IF NOT EXISTS 만(BEGIN 없음 · 선례 머리말 그대로)
              db/migrations/2026-10-26_scale_d2a_board_counts.sql — 칸 · 트리거 · 채움(BEGIN · lock_timeout 3s · statement_timeout 600s · 선례 방식).
              두 번 돌려도 같은 결과(IF NOT EXISTS · CREATE OR REPLACE · 채움은 차이 행만). 데이터 삭제 · 재시드 0. 시험 틀 이주 목록 · schema 에도 반영.
보안 바닥   : ① 바꾼 질의마다 「옛 SQL vs 새 SQL」 결과 같음 시험 — 합성 DB(D1 seed S + 공유 · 그룹 · 개인 폴더 · 익명 게시판 · 숨김 · 지운 글을 일부러 섞음)에서
                 사람 ≥ 20명(직원 · 팀장 · 관리자 · 외부 · 다른 팀) × 범위(폴더 · 공간 · 게시판)마다 돌려 **id 와 순서까지 같음**(개수는 상한 안에서 같음).
              ② 미리 센 글 수 = 그때그때 센 값 — 글 만들기 · 옮기기 · 게시 · 게시 취소 · 숨기기 · 지우기 · 되살리기 · 영구삭제를 섞은 뒤 모든 게시판에서 같음.
              ③ 상한 개수도 같은 권한 조건으로만 센다(안 보이는 것의 존재가 새지 않음).
              ④ 카페 홈 · 브리핑 · 알림 응답에 익명 게시판 글쓴이 id · 이름이 나오지 않음(지금 시험 그대로 통과 + 새 단언).
금지 패턴   : 권한 판정 느슨하게 하기 · 「일단 관리자만 빠르게」 같은 갈래 · 운영 DB 접속 · 운영 설정(postgresql.conf) 바꾸기 · 결과 같음을 「대충 비슷」으로 · known_fail 로 숨기기 · git add -A.
측정        : feat/scale-d1 의 tests/scale/d1_measure.py 를 그대로(필요하면 --select 창구 거르기 · 개수 상한 칸 인식만 덧붙임) S · M · L 로 다시 재서 D1 과 나란히.
              디스크: 각 단계 시작 전 df -h / 남은 공간 확인 — L 은 **남은 ≥ 6GB 일 때만**(지금 약 4GB — 메인이 바깥 백업을 지우면 늘어남).
              모자라면 S · M 까지만 하고 「L 대기」로 보고(메인이 L 을 돌린다). 합성 DB 는 끝나면 DROP 확인.
시험        : 수락 = 위 보안 바닥 ①~④ 시험(새 파일 tests/scale/d2a_equivalence.py 등) + A1~A9 가 닿는 기존 시험 묶음 각 한 번(CLAUDE.md 3.1 「한 기능에 한 번」).
              전체 회귀는 돌리지 않는다(밤 대기열이 돈다 — 대상 시험 id 목록을 보고에 적는다).
              카탈로그: core.scale_d2a_equiv(보안 바닥 — 밤 회귀가 돌 수 있게 temp-instance · manual_only 아님) · core.scale_d2a_budget(M 단계 · 이 창구들의
              시간 기준 · 질의 수 상한 · 「pages · files 큰 표 Seq Scan 0」 계획 단언 — manual_only 아님, 20분 안).
완료 기준   : docs/scale-d2a-report.md — ① 창구별 D1(전) · D2a(후) S · M · L p50/p95 · 깊은 쪽 · 질의 수 · L/S 표 ② 바꾼 질의 요지 · 새 색인 · 트리거 목록(크기 포함)
              ③ 보안 바닥 ①~④ 결과(사람 · 범위 수 · 같음 건수) ④ 보이는 변화 ⑤ 남은 기준 넘음(D2b 로 넘길 것 포함) ⑥ 2억 8500만 외삽 다시
              ⑦ 이주 운영 적용 어림(색인 만들기 시간 · 잠금 — 운영 행 수 기준) ⑧ 밤 회귀 대상 시험 id. 짧고 표 위주.
```
