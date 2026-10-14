# 규모 D1 보고 — 데이터 S · M · L 측정 (2026-10-14 · 코드 변경 0)

> 지시서 `docs/scale-d1-directive.md` · 기획 `/var/www/qaredi/docs/scale-d-plan.md` 2 · 3장. 고치기는 하지 않았다(D2 에서 메인이 정함).
> 도구 `tests/scale/d1_seed.sql` · `tests/scale/d1_measure.py` · 원문 결과 `/home/changkwon/qaredi-scale-d-results-20261014/run1/`.

## 요약

- 라우트 표 GET **198개**(런처 `/` 포함) 중 데이터와 관계 있는 **99개 창구 · 126개 측정 경우**를 S · M · L 에서 실제 HTTP 로 쟀다. 나머지 99개는 정적 화면 · 설정 · 파일 바이트 · 바깥 AI · 공유 토큰 · 이 단계 표에 데이터가 없는 기능(아래 ①-나).
- **L 에서 기준을 넘는 경우 27개, S→L 3배를 넘는 경우 30개(합쳐 36개)**. 하나는 이미 **HTTP 500**(카페 홈 `/community/api/cafe` — 15초 문장 마감).
- 원인은 거의 넷으로 모인다: **(가) 화면 하나를 그리려고 범위 전체를 센다(count) · 전체를 정렬한 뒤 20개만 쓴다(OFFSET 목록 · 게시판 글 수 · 태그 수 · 이야기 통계)**, **(나) 권한 조건식의 OR · CASE · 하위 질의 때문에 색인을 못 타고 표 전체를 훑는다(공유 받은 글 · 내 작업 · 브리핑 · 휴지통 · 카페 최근 글)**, **(다) 작은 표(users · folders)를 행마다 Seq Scan 하는 Nested Loop(자료실 목록 · 폴더 수 · 카페)**, **(라) 행마다 질의(N+1 — 알림 50건 → 질의 122개)**.
- 규모 B 가 고친 **게시판 커서 목록 · 댓글 · 글 상세 · 기록 · 즐겨찾기 · 최근**은 L 에서도 30~65ms 로 평평하다(커서 · 정렬별 색인이 제 역할).
- **2억 8500만 글 외삽: 먼저 무너지는 다섯 = 카페 홈 → 자료실 목록 → 폴더 목록 → 공유 받은 글 → 공간 페이지 목록**(모두 글 50만~150만 상당에서 15초 마감 = 500). 지금 구조 그대로면 2억 8500만에는 목록 · 검색 창구 대부분이 마감에 걸린다 — 색인 몇 개가 아니라 (가)~(라)의 모양을 바꿔야 한다.
- 디스크 여유가 6GB 미만(4.73GB)이라 **L 을 줄였다: 글 50만(기획 100만의 50%) · 나머지 큰 표 33%** — DB 2.34GB · 최저 여유 2.31GB.

## ① 창구 목록 · S · M · L 서버 시간

- 창구 = `public/index.php`(앞에 `/tbox`) · `src/community/routes.php`(앞에 `/community`) 의 GET 전부 + 런처 `/`. 같은 창구의 질의 갈래(`?q=` · `?sort=` 등)는 `#이름`으로 따로 쟀다.
- 분류: list 목록 · cursor 커서 목록 · offset OFFSET 목록(쪽 번호 · 줄 번호 커서 포함) · search 검색 · home 홈 · count 개수 · detail 상세. **기준(ms)** = 기획 2장: 목록 · 커서 · OFFSET · 개수 · 상세 150 · 검색 1,000 · 홈 300 (L p95 · 다음 쪽 · 깊은 쪽 중 가장 나쁜 값으로 판정).
- 시간 = 같은 기계 HTTP 왕복(ms, 7번 p50/p95 · 데우기 1번 뺌). 질의 수 = 요청 하나의 SQL 문장 수(세션 확인 등 고정 6~8 포함). L/S = p50 비율(글 50배 · 나머지 16.5~20배 데이터). 깊은 쪽 = 약 1만 번째 줄 쪽(괄호: 쪽 번호 · 받은 줄 수 — 0줄이면 그 사람 데이터가 1만 줄에 못 미침).
- 계정: emp = 직원(팀 1, 글을 많이 쓰는 사람 — 개인 공간 글 1,770개@L) · admin = 관리자(재인증 세션).

### ①-가. 측정한 126개 경우

| 창구 | 분류 | 기준 | S p50/p95 | M p50/p95 | L p50/p95 | L 다음 쪽 p95 | L 깊은 쪽 p95 | 질의 수 S/M/L | L/S | 판정 |
|---|---|---|---|---|---|---|---|---|---|---|
| `/` | home | 300 | 8.8/10.0 | 11.5/24.2 | 6.9/9.2 | — | — | 8/8/8 | 0.78 | 통과 |
| `/tbox/` | home | 300 | 11.6/12.5 | 16.5/25.3 | 14.1/25.1 | — | — | 8/8/8 | 1.22 | 통과 |
| `/tbox/api/me` | count | 150 | 10.8/11.9 | 7.7/8.8 | 11.5/19.6 | — | — | 12/12/12 | 1.06 | 통과 |
| `/tbox/api/me/home-dashboard` | home | 300 | 12.1/12.5 | 7.8/8.1 | 9.6/10.7 | — | — | 10/10/10 | 0.79 | 통과 |
| `/tbox/api/me/product-home` | home | 300 | 30.5/32.5 | 17.8/19.5 | 28.4/32.4 | — | — | 22/22/22 | 0.93 | 통과 |
| `/tbox/api/product-spaces` | list | 150 | 10.2/10.5 | 6.9/7.0 | 11.5/15.4 | — | — | 10/10/10 | 1.13 | 통과 |
| `/tbox/api/files` | offset | 150 | 64.0/91.1 | 2486.0/3069.9 | 4558.5/5111.2 | — | 4263.9(쪽 501 · 20줄) | 12/12/12 | 71.23 | **기준 넘음** **71.23배** |
| `/tbox/api/files#q` | search | 1000 | 43.8/45.3 | 345.1/346.4 | 766.6/771.5 | — | — | 21/21/21 | 17.5 | **17.5배** |
| `/tbox/api/files#rare` | search | 1000 | 21.3/22.2 | 94.6/95.9 | 114.0/115.4 | — | — | 21/21/21 | 5.35 | **5.35배** |
| `/tbox/api/files/trash` | offset | 150 | 10.2/12.4 | 14.9/17.7 | 16.5/19.5 | — | 22.8(쪽 501 · 0줄) | 12/12/12 | 1.62 | 통과 |
| `/tbox/api/files/trash#admin` | offset | 150 | 10.9/11.9 | 11.5/11.5 | 14.4/16.0 | — | 34.3(쪽 501 · 0줄) | 10/10/10 | 1.32 | 통과 |
| `/tbox/api/files/favorites` | list | 150 | 12.4/12.6 | 13.2/14.5 | 14.7/15.5 | — | — | 10/10/10 | 1.19 | 통과 |
| `/tbox/api/files/recent` | list | 150 | 16.6/18.9 | 17.7/21.2 | 18.0/21.1 | — | — | 12/12/12 | 1.08 | 통과 |
| `/tbox/api/folders/favorites` | list | 150 | 13.1/13.3 | 13.7/16.8 | 13.6/15.8 | — | — | 10/10/10 | 1.04 | 통과 |
| `/tbox/api/folders` | list | 150 | 102.9/145.3 | 1356.2/1857.6 | 3125.9/3501.2 | — | — | 14/14/14 | 30.38 | **기준 넘음** **30.38배** |
| `/tbox/api/teams` | list | 150 | 8.3/8.7 | 8.8/9.0 | 7.4/7.7 | — | — | 8/8/8 | 0.89 | 통과 |
| `/tbox/api/audit` | offset | 150 | 23.0/35.2 | 208.4/279.2 | 196.2/316.0 | — | 352.4(쪽 501 · 20줄) | 12/12/12 | 8.53 | **기준 넘음** **8.53배** |
| `/tbox/api/audit#filter` | offset | 150 | 50.4/54.2 | 271.7/290.7 | 380.1/569.9 | — | — | 12/12/12 | 7.54 | **기준 넘음** **7.54배** |
| `/tbox/api/users` | offset | 150 | 12.3/12.8 | 6.9/7.1 | 7.2/7.3 | — | 8.4(쪽 201 · 0줄) | 10/10/10 | 0.59 | 통과 |
| `/tbox/api/me/activity` | offset | 150 | 16.2/17.5 | 17.3/18.9 | 9.1/9.2 | — | 9.6(쪽 501 · 0줄) | 10/10/10 | 0.56 | 통과 |
| `/tbox/api/me/shares` | list | 150 | 11.7/12.5 | 9.5/11.7 | 6.3/6.6 | — | — | 8/8/8 | 0.54 | 통과 |
| `/tbox/api/invite-requests` | list | 150 | 13.2/15.0 | 13.2/19.2 | 20.2/20.6 | — | — | 10/10/10 | 1.53 | 통과 |
| `/tbox/api/invites` | list | 150 | 11.9/15.1 | 11.4/14.0 | 10.9/11.1 | — | — | 10/10/10 | 0.92 | 통과 |
| `/tbox/api/admin/deletion-requests` | list | 150 | 13.1/14.8 | 12.6/14.7 | 12.5/12.7 | — | — | 12/12/12 | 0.95 | 통과 |
| `/tbox/api/admin/shares` | list | 150 | 11.4/12.8 | 11.0/12.2 | 11.2/11.5 | — | — | 8/8/8 | 0.98 | 통과 |
| `/tbox/api/org/teams` | count | 150 | 12.4/14.3 | 19.3/19.7 | 24.3/25.6 | — | — | 8/8/8 | 1.96 | 통과 |
| `/tbox/api/org/teams/{num}/members` | list | 150 | 12.0/17.3 | 11.8/13.1 | 12.4/15.3 | — | — | 10/10/10 | 1.03 | 통과 |
| `/tbox/api/grants/mine` | list | 150 | 12.0/13.0 | 11.8/12.3 | 11.2/12.1 | — | — | 8/8/8 | 0.93 | 통과 |
| `/tbox/api/access-requests` | list | 150 | 17.5/18.6 | 17.9/18.5 | 17.2/18.2 | — | — | 14/14/14 | 0.98 | 통과 |
| `/tbox/api/access/people` | search | 1000 | 12.9/14.2 | 15.3/15.7 | 18.2/19.5 | — | — | 10/10/10 | 1.41 | 통과 |
| `/tbox/api/messages` | cursor | 150 | 15.8/16.8 | 25.6/27.7 | 37.0/40.1 | — | — | 10/10/10 | 2.34 | 통과 |
| `GET /tbox/api/assistant/runs` | cursor | — | — | — | — | — | — | — | — | 제외: 도우미 꺼짐(ASSISTANT_ENABLED=0 · 토큰 전용) — 제외 |
| `GET /tbox/api/files/{id}/preview` | detail | — | — | — | — | — | — | — | — | 제외: 파일 본문 미리보기 — 디스크 파일 0 이라 제외 |
| `/tbox/audit` | home | 300 | 13.9/14.5 | 14.0/18.4 | 13.9/15.3 | — | — | 8/8/8 | 1.0 | 통과 |
| `/tbox/users` | home | 300 | 14.1/14.6 | 14.4/18.8 | 14.2/14.5 | — | — | 8/8/8 | 1.01 | 통과 |
| `/tbox/trash` | home | 300 | 13.0/13.6 | 12.9/12.9 | 12.6/13.0 | — | — | 8/8/8 | 0.97 | 통과 |
| `/tbox/activity` | home | 300 | 13.0/13.5 | 15.1/17.8 | 12.8/12.9 | — | — | 8/8/8 | 0.98 | 통과 |
| `/tbox/admin` | home | 300 | 14.3/18.8 | 14.1/15.1 | 13.9/16.6 | — | — | 8/8/8 | 0.97 | 통과 |
| `/tbox/org` | home | 300 | 14.1/14.6 | 13.9/14.2 | 13.8/19.9 | — | — | 8/8/8 | 0.98 | 통과 |
| `/tbox/settings` | home | 300 | 13.7/14.1 | 12.9/13.4 | 14.1/16.6 | — | — | 8/8/8 | 1.03 | 통과 |
| `/tbox/messages` | home | 300 | 11.0/11.4 | 10.1/10.2 | 10.4/10.6 | — | — | 8/8/8 | 0.95 | 통과 |
| `/tbox/spaces` | home | 300 | 10.2/10.4 | 9.4/9.6 | 9.9/10.2 | — | — | 6/6/6 | 0.97 | HTTP 302 |
| `/tbox/programs` | home | 300 | 16.6/18.1 | 16.8/19.0 | 16.1/16.3 | — | — | 10/10/10 | 0.97 | 통과 |
| `/tbox/api/programs` | list | 150 | 14.0/14.4 | 13.6/14.1 | 13.6/13.9 | — | — | 10/10/10 | 0.97 | 통과 |
| `/community/` | home | 300 | 43.0/46.2 | 43.5/47.7 | 57.6/59.3 | — | — | 16/16/16 | 1.34 | 통과 |
| `/community/workspace` | home | 300 | 29.5/32.4 | 30.7/32.2 | 35.7/36.7 | — | — | 14/14/14 | 1.21 | 통과 |
| `/community/cafe` | home | 300 | 17.2/18.8 | 17.2/21.6 | 18.1/20.4 | — | — | 12/12/12 | 1.05 | 통과 |
| `/community/api/cafe` | home | 300 | 77.1/83.9 | 3210.1/3480.8 | 16319.6/16484.4 | — | — | 16/16/14 | 211.67 | **기준 넘음** **211.67배** HTTP 500 |
| `/community/today` | home | 300 | 10.8/13.9 | 11.4/11.7 | 8.3/10.0 | — | — | 8/8/8 | 0.77 | HTTP 308 |
| `/community/api/briefing` | home | 300 | 172.0/193.5 | 1460.3/1926.0 | 4677.9/5138.0 | — | — | 36/36/36 | 27.2 | **기준 넘음** **27.2배** |
| `/community/my-tasks` | home | 300 | 25.3/27.1 | 22.6/23.4 | 23.8/26.6 | — | — | 14/14/14 | 0.94 | 통과 |
| `/community/api/my-tasks` | offset | 150 | 48.5/52.1 | 132.2/161.5 | 606.6/781.6 | — | — | 20/20/20 | 12.51 | **기준 넘음** **12.51배** |
| `/community/api/my-tasks#done` | offset | 150 | 33.6/34.1 | 122.6/159.7 | 356.0/379.9 | — | — | 12/12/12 | 10.6 | **기준 넘음** **10.6배** |
| `/community/api/me/space` | count | 150 | 13.8/14.4 | 10.4/11.9 | 11.3/13.8 | — | — | 12/12/12 | 0.82 | 통과 |
| `/community/api/spaces` | list | 150 | 16.8/17.0 | 11.4/15.2 | 16.4/16.8 | — | — | 16/16/16 | 0.98 | 통과 |
| `/community/api/pages` | offset | 150 | 38.6/39.7 | 48.5/54.3 | 2233.0/2615.6 | — | 4230.7(쪽 501 · 0줄) | 16/16/16 | 57.85 | **기준 넘음** **57.85배** |
| `/community/api/pages#team` | offset | 150 | 38.7/40.3 | 81.5/82.9 | 546.3/653.7 | — | 385.7(쪽 501 · 0줄) | 16/16/16 | 14.12 | **기준 넘음** **14.12배** |
| `/community/api/pages#tree` | list | 150 | 26.2/27.4 | 77.3/80.3 | 2153.0/2727.8 | — | — | 14/14/14 | 82.18 | **기준 넘음** **82.18배** |
| `/community/api/pages#shared` | offset | 150 | 125.6/181.7 | 1074.2/1285.5 | 6126.0/6707.2 | — | — | 14/14/14 | 48.77 | **기준 넘음** **48.77배** |
| `/community/api/pages#q` | search | 1000 | 33.2/33.5 | 77.9/80.4 | 505.4/640.4 | — | — | 16/16/16 | 15.22 | **15.22배** |
| `/community/api/pages#status` | list | 150 | 30.6/30.9 | 31.2/32.0 | 33.9/34.4 | — | — | 16/16/16 | 1.11 | 통과 |
| `/community/api/procedures` | offset | 150 | 21.7/23.3 | 21.6/23.3 | 22.2/24.5 | — | 27.0(쪽 501 · 0줄) | 12/12/12 | 1.02 | 통과 |
| `/community/api/search` | search | 1000 | 105.6/141.8 | 578.4/684.0 | 1715.9/1998.4 | — | — | 29/29/29 | 16.25 | **기준 넘음** **16.25배** |
| `/community/api/search#rare` | search | 1000 | 59.4/83.0 | 138.1/197.8 | 689.5/906.0 | — | — | 29/29/29 | 11.61 | **11.61배** |
| `/community/api/search#two` | search | 1000 | 80.8/83.5 | 192.7/216.5 | 570.5/665.3 | — | — | 29/29/29 | 7.06 | **7.06배** |
| `/community/api/search#admin` | search | 1000 | 129.5/134.9 | 554.8/594.6 | 1645.7/1669.3 | — | — | 29/29/29 | 12.71 | **기준 넘음** **12.71배** |
| `/community/api/search#latin` | search | 1000 | 564.1/882.3 | 3063.4/3077.9 | 3087.5/3089.0 | — | — | 27/27/20 | 5.47 | **기준 넘음** **5.47배** |
| `/community/api/pages/{id}` | detail | 150 | 72.2/82.3 | 98.3/100.9 | 84.5/85.2 | — | — | 40/40/40 | 1.17 | 통과 |
| `/community/api/pages/{id}#doc` | detail | 150 | 59.0/80.1 | 98.0/100.2 | 69.5/70.2 | — | — | 46/46/46 | 1.18 | 통과 |
| `/community/p/{id}` | detail | 150 | 23.5/24.4 | 30.3/31.2 | 31.0/32.4 | — | — | 16/16/16 | 1.32 | 통과 |
| `/community/api/pages/{id}/comments` | list | 150 | 28.7/29.7 | 37.2/38.5 | 43.4/45.5 | — | — | 14/14/14 | 1.51 | 통과 |
| `/community/api/pages/{id}/versions` | list | 150 | 27.9/28.8 | 27.8/29.9 | 28.7/29.5 | — | — | 16/16/16 | 1.03 | 통과 |
| `/community/api/pages/{id}/history` | list | 150 | 32.8/33.4 | 39.7/40.6 | 36.4/36.8 | — | — | 14/14/14 | 1.11 | 통과 |
| `/community/api/pages/{id}/reads` | count | 150 | 28.2/28.6 | 32.0/33.0 | 35.7/36.2 | — | — | 16/16/16 | 1.27 | 통과 |
| `/community/api/pages/{id}/attachments` | list | 150 | 26.1/27.3 | 27.9/28.0 | 28.1/28.9 | — | — | 14/14/14 | 1.08 | 통과 |
| `/community/api/pages/{id}/shares` | list | 150 | 31.4/32.6 | 32.9/33.4 | 33.5/35.3 | — | — | 16/16/16 | 1.07 | 통과 |
| `/community/api/pages/{id}/suggestions` | list | 150 | 25.8/26.8 | 28.7/31.1 | 29.2/29.6 | — | — | 14/14/14 | 1.13 | 통과 |
| `/community/api/pages/{id}/assignee-candidates` | search | 1000 | 179.1/248.1 | 180.4/279.5 | 179.6/259.0 | — | — | 54/54/54 | 1.0 | 통과 |
| `/community/api/me/trash` | list | 150 | 24.5/25.1 | 320.0/413.8 | 3108.9/3855.9 | — | — | 12/12/12 | 126.89 | **기준 넘음** **126.89배** |
| `/community/api/me/favorites` | list | 150 | 26.2/26.8 | 17.8/21.7 | 23.8/26.9 | — | — | 12/12/12 | 0.91 | 통과 |
| `/community/api/me/recent` | list | 150 | 27.4/32.6 | 17.8/21.0 | 29.5/31.5 | — | — | 12/12/12 | 1.08 | 통과 |
| `/community/api/me/suggestions` | list | 150 | 15.3/15.5 | 13.5/14.5 | 15.1/16.6 | — | — | 10/10/10 | 0.99 | 통과 |
| `/community/api/notifications` | list | 150 | 416.6/575.0 | 403.0/636.2 | 610.3/634.9 | — | — | 122/122/122 | 1.46 | **기준 넘음** |
| `/community/api/notifications#unread` | count | 150 | 20.5/22.7 | 19.3/20.0 | 19.5/23.9 | — | — | 18/18/18 | 0.95 | 통과 |
| `/community/api/mention-candidates` | search | 1000 | 91.0/146.5 | 132.2/154.8 | 135.4/147.6 | — | — | 34/34/34 | 1.49 | 통과 |
| `/community/api/share-candidates` | search | 1000 | 20.2/21.1 | 18.9/29.0 | 18.1/21.9 | — | — | 16/16/16 | 0.9 | 통과 |
| `/community/b/{num}` | home | 300 | 17.8/19.7 | 16.7/17.0 | 16.3/16.8 | — | — | 16/16/16 | 0.92 | 통과 |
| `/community/api/boards` | list | 150 | 24.3/25.9 | 109.0/120.8 | 2186.7/2303.4 | — | — | 12/12/12 | 89.99 | **기준 넘음** **89.99배** |
| `/community/api/boards#cafe` | list | 150 | 22.0/22.5 | 75.2/79.2 | 1253.1/1755.4 | — | — | 12/12/12 | 56.96 | **기준 넘음** **56.96배** |
| `/community/api/boards/{num}/pages` | cursor | 150 | 33.6/34.9 | 46.2/49.9 | 62.9/66.1 | 53.7, 62.2 | — | 14/14/14 | 1.87 | 통과 |
| `/community/api/boards/{num}/pages#updated` | cursor | 150 | 30.8/31.3 | 45.6/47.0 | 44.2/53.2 | 64.6, 62.7 | — | 14/14/14 | 1.44 | 통과 |
| `/community/api/boards/{num}/pages#views` | cursor | 150 | 33.4/33.7 | 27.0/30.8 | 62.7/63.6 | 220.2, 275.8 | — | 14/14/14 | 1.88 | **기준 넘음** |
| `/community/api/boards/{num}/pages#small` | cursor | 150 | 35.6/37.5 | 41.4/43.0 | 62.9/65.2 | 64.8, 65.0 | — | 14/14/14 | 1.77 | 통과 |
| `/community/api/boards/{num}/pages#tag` | cursor | 150 | 30.1/39.1 | 57.2/61.5 | 119.8/127.6 | 89.0, 120.0 | — | 14/14/14 | 3.98 | **3.98배** |
| `/community/api/boards/{num}/pages#q` | search | 1000 | 57.5/61.9 | 200.6/262.3 | 583.7/616.1 | 584.6, 602.4 | — | 19/19/19 | 10.15 | **10.15배** |
| `/community/api/boards/{num}/pages#legacy` | offset | 150 | 218.2/224.4 | 360.1/498.0 | 550.0/948.4 | 17.6 | — | 52/52/52 | 2.52 | **기준 넘음** |
| `/community/api/boards/{num}/tags` | count | 150 | 37.1/38.7 | 139.4/227.5 | 796.2/910.4 | — | — | 14/14/14 | 21.46 | **기준 넘음** **21.46배** |
| `/community/api/reports` | list | 150 | 24.1/28.8 | 54.2/55.3 | 61.3/62.1 | — | — | 10/10/10 | 2.54 | 통과 |
| `/community/api/reports#all` | list | 150 | 24.1/31.1 | 49.2/50.3 | 101.5/103.5 | — | — | 10/10/10 | 4.21 | **4.21배** |
| `/community/reports` | home | 300 | 16.1/16.7 | 16.5/16.8 | 17.1/21.0 | — | — | 12/12/12 | 1.06 | 통과 |
| `/community/api/board-requests` | list | 150 | 11.9/12.5 | 12.3/13.0 | 14.4/14.9 | — | — | 10/10/10 | 1.21 | 통과 |
| `/community/api/admin/popular` | count | 150 | 18.7/19.7 | 20.4/21.0 | 20.2/20.6 | — | — | 14/14/14 | 1.08 | 통과 |
| `/community/memories` | home | 300 | 16.7/18.4 | 16.6/17.4 | 17.1/18.6 | — | — | 12/12/12 | 1.02 | 통과 |
| `/community/api/memories` | cursor | 150 | 41.1/43.1 | 467.9/727.2 | 553.4/822.7 | 834.2, 786.3 | — | 30/30/30 | 13.46 | **기준 넘음** **13.46배** |
| `/community/api/memories#discover` | cursor | 150 | 31.2/31.5 | 61.9/62.8 | 86.7/109.1 | 168.9, 183.0 | — | 32/32/32 | 2.78 | **기준 넘음** |
| `/community/api/memories#mine` | cursor | 150 | 18.6/18.6 | 19.0/19.5 | 25.9/26.0 | 172.0, 103.1 | — | 30/30/30 | 1.39 | **기준 넘음** |
| `/community/api/memories#profile` | cursor | 150 | 25.7/31.3 | 21.9/22.8 | 31.1/32.8 | 161.6, 44.2 | — | 34/34/34 | 1.21 | **기준 넘음** |
| `/community/api/memories#q` | search | 1000 | 26.0/27.3 | 85.2/113.9 | 218.5/226.3 | 249.5, 291.9 | — | 32/32/32 | 8.4 | **8.4배** |
| `/community/api/memories#offset` | offset | 150 | 29.3/39.4 | 524.7/532.5 | 633.6/845.2 | — | — | 22/22/22 | 21.62 | **기준 넘음** **21.62배** |
| `/community/api/memories/{id}` | detail | 150 | 15.1/30.3 | 13.6/14.2 | 13.0/14.2 | — | — | 18/18/18 | 0.86 | 통과 |
| `/community/api/memories/{id}/comments` | cursor | 150 | 9.6/10.1 | 12.0/15.4 | 11.9/12.5 | — | — | 14/14/14 | 1.24 | 통과 |
| `/community/api/memories/social` | list | 150 | 10.8/11.9 | 18.6/19.0 | 19.0/21.5 | — | — | 16/16/16 | 1.76 | 통과 |
| `/community/memories/u/{username}` | home | 300 | 10.4/12.5 | 16.2/18.4 | 18.4/18.7 | — | — | 12/12/12 | 1.77 | 통과 |
| `/community/api/databases` | list | 150 | 14.3/14.8 | 24.1/26.7 | 23.5/24.0 | — | — | 12/12/12 | 1.64 | 통과 |
| `/community/api/templates` | list | 150 | 13.0/14.2 | 16.0/17.1 | 16.0/16.4 | — | — | 12/12/12 | 1.23 | 통과 |
| `/community/api/automations` | cursor | 150 | 13.0/13.2 | 14.9/15.9 | 14.7/15.3 | — | — | 10/10/10 | 1.13 | 통과 |
| `/community/api/collections` | list | 150 | 12.1/12.2 | 13.2/14.6 | 13.7/14.2 | — | — | 10/10/10 | 1.13 | 통과 |
| `/community/api/groups` | list | 150 | 13.3/15.1 | 14.0/14.8 | 14.1/15.9 | — | — | 10/10/10 | 1.06 | 통과 |
| `/community/api/me/web-publications` | list | 150 | 13.4/13.5 | 14.2/14.8 | 14.5/15.2 | — | — | 10/10/10 | 1.08 | 통과 |
| `/community/api/palettes` | list | 150 | 14.2/15.0 | 13.6/13.9 | 14.0/20.8 | — | — | 10/10/10 | 0.99 | 통과 |
| `/community/api/embed-domains` | list | 150 | 14.8/16.5 | 14.8/15.1 | 19.4/29.1 | — | — | 12/12/12 | 1.31 | 통과 |
| `/community/api/me/guide` | detail | 150 | 14.9/16.3 | 15.0/21.7 | 12.9/13.6 | — | — | 12/12/12 | 0.87 | 통과 |
| `/community/api/travel-plans` | list | 150 | 16.0/16.7 | 15.1/15.8 | 11.5/12.0 | — | — | 14/14/14 | 0.72 | 통과 |
| `/community/procedures` | home | 300 | 31.2/33.8 | 30.2/33.4 | 25.2/30.4 | — | — | 14/14/14 | 0.81 | 통과 |
| `/community/api/me/ai-log` | list | 150 | 14.5/15.0 | 14.3/18.8 | 13.7/14.7 | — | — | 10/10/10 | 0.94 | 통과 |
| `/community/api/admin/ai-log` | list | 150 | 15.9/17.5 | 15.8/17.9 | 14.9/15.4 | — | — | 12/12/12 | 0.94 | 통과 |
| `/community/api/admin/automations` | cursor | 150 | 14.4/15.1 | 14.3/16.7 | 14.4/15.1 | — | — | 10/10/10 | 1.0 | 통과 |
| `/community/api/admin/web-publications` | list | 150 | 14.9/16.6 | 14.2/15.4 | 14.2/14.4 | — | — | 10/10/10 | 0.95 | 통과 |

### ①-나. 측정하지 않은 GET 99개(이유별)

| 이유 | 창구 |
|---|---|
| 정적 화면 · 안내 · 상태(데이터 크기와 무관) | `/tbox/login` `/tbox/join` `/tbox/welcome` `/tbox/request-invite` `/tbox/request-invite/status` `/tbox/privacy` `/tbox/terms` `/tbox/consent` `/tbox/reauth` `/tbox/session/open` `/tbox/app.webmanifest` `/tbox/alerts-app` `/tbox/alerts-sw.js` `/tbox/alerts-app.webmanifest` `/tbox/admin/permissions` `/tbox/admin/fetch-hosts` `/tbox/wiki` `/tbox/wiki/{book}` `/tbox/wiki/{book}/{slug}` `/tbox/wiki/{slug}` `/community/integrations` `/community/ai` `/community/automations` `/community/suggestions` `/community/board-requests` `/community/palette.css` `/tbox/api/health` `/tbox/api/time` `/tbox/api/whoami` |
| 사람 한 명의 설정 · 토큰 · 동의 · 권한 표(행 수가 사람당 상수) | `/tbox/api/me/consents` `/tbox/api/me/delete-request` `/tbox/api/me/api-tokens` `/tbox/api/users/{num}/api-tokens` `/tbox/api/me/passkeys` `/tbox/api/member-ai-settings` `/tbox/api/org/permissions` `/tbox/api/admin/permissions` `/tbox/api/admin/alerts` `/tbox/api/admin/push/key` `/tbox/api/admin/push/subscriptions` `/tbox/api/fetch-hosts` `/community/api/me/messenger` `/community/api/admin/messenger-hooks` `/community/api/admin/connectors` `/community/api/me/draft-key` `/community/api/ai/status` `/community/api/admin/ai/settings` `/community/api/pages/{id}/ai-permission` `/tbox/api/folders/{num}/grants` `/tbox/api/files/{id}/grants` |
| 파일 · 미디어 바이트 · 내보내기(디스크 파일 0) | `/tbox/api/files/{id}/download` `/tbox/api/files/{id}/preview` `/tbox/api/files/{id}/preview/raw` `/tbox/s/{token}` `/community/api/memories/media/{id}` `/community/memories/s/{token}/media/{id}` `/community/api/pages/{id}/attachments/{gid}/raw` `/community/api/pages/{id}/attachments/{gid}/download` `/tbox/api/product-spaces/{id}/items/{id}/download` `/community/p/{id}/print` `/community/api/pages/{id}/export` |
| 바깥 AI · 도우미(꺼짐 — 외부 요청 0) | `/tbox/api/messages/ai` `/tbox/api/messages/assist` `/tbox/api/messages/attachments/{id}` `/tbox/api/assistant/chat` `/tbox/api/assistant/runs` `/tbox/api/assistant/runs/{id}` `/tbox/api/assistant/runs/{id}/events` `/community/api/messenger/gpt` `/community/api/memories/ai` |
| 기본키 한 줄 상세(색인 한 번) | `/tbox/api/messages/{num}` `/community/api/suggestions/{id}` `/community/api/pages/{id}/versions/{num}` `/community/api/templates/{id}` `/community/api/automations/{id}` `/community/memories/p/{id}` `/community/api/travel-plans/{id}` |
| 이 단계 표 밖 기능 — 합성 데이터 0 | 제품 공간 `/tbox/api/product-spaces/{id}` · `…/home` · `…/dashboard` · `…/members` · `…/invites` · `…/items` · `…/items/{id}` / 데이터베이스 `/community/api/databases/{id}/rows` · `…/summary` · `…/chart` / 양식 `/community/f/{id}/{view}` · `/community/api/forms/{id}/{view}` / 포트폴리오 `/community/portfolios/{id}` · `/community/api/spaces/{num}/portfolios` · `/community/api/portfolios/{id}` · `/community/api/projects/{id}/status` / `/community/api/automations/{id}/runs` · `/community/api/collections/{num}` · `/community/api/groups/{id}` / 공개 토큰 `/community/pub/{pubtoken}` · `…/p/{id}` · `…/form/{viewid}` · `/community/memories/s/{token}` · `…/feed` — **데이터베이스 행 · 포트폴리오는 D2 전에 합성해 재야 한다(한계 ⑤)** |

## ② 기준 넘음 · 3배 넘음 — 원인과 고칠 방안 후보 (L, 시간 = 서버 p50)

SQL 은 잡힌 원문을 줄인 것(값 = `$n` 자리표시 · 권한 조건절 = `<볼 수 있음>`). 원문 전체 · 계획 전체는 `run1/plans/L-*.json`.

| 창구 (L p50 · S→L) | 느린 질의(요지) | EXPLAIN 요지 | 원인 한 줄 | 고칠 방안 후보 → 예상 효과 |
|---|---|---|---|---|
| `/community/api/cafe` 카페 홈 **16.3s · HTTP 500** (212×) | `… FROM pages p JOIN spaces s … LEFT JOIN users w ON w.id = CASE WHEN s.kind='board' THEN p.author_id ELSE s.owner_user_id END WHERE <카페 게시판 글 OR 카페로 게시한 글> ORDER BY CASE … END /*posted_at*/ DESC, p.id DESC LIMIT 10` + 게시판마다 `(SELECT count(*) FROM pages … WHERE space_id=b.id OR published_board_id=b.id)` | Seq Scan pages 48.5만 → 20만 행에 **Nested Loop · Seq Scan users 991행 × 20만 번 = 1.98억 행**(46s) · 게시판 수 세기 11 × 1.8만 행 1.7s | 조인 키 · 정렬 키가 CASE 식 → 색인 0, 「최근 10개」를 위해 카페 글 전체를 읽고 정렬 | 두 갈래 `UNION ALL`(게시판 글 = `pages_board_new_idx` 게시판별 Merge Append LIMIT 10 · 게시 글 = `pages_published_new_idx` LIMIT 10) 뒤 10행만 users 조인 · 글 수는 미리 센 개수(아래 boards) → **16s → 수십 ms, 글 수와 무관** |
| `/tbox/api/files` 자료실 목록 **4.56s** (71×) · 깊은 쪽 4.26s | `… FROM files f JOIN users u ON u.id=f.uploaded_by LEFT JOIN teams t … WHERE f.deleted_at IS NULL AND <볼 수 있음: OR · 개인 폴더 5단 EXISTS> AND f.folder_id IS NOT DISTINCT FROM $6 ORDER BY f.created_at DESC, f.id DESC LIMIT $7 OFFSET $8` + 같은 WHERE 의 `count(*)` | Bitmap Heap Scan files 2.8만 → **Nested Loop · Seq Scan users 999 × 2.8만 = 2,806만 행**(2.7s) · teams 같은 꼴 · 2.8만 행 전부 Sort 뒤 20개 · 깊은 쪽 Sort external merge 4.5MB · count 1.0s | 권한식이 행 수를 낮게 어림 → 작은 표를 행마다 훑는 계획 + 전체 정렬 + 정확한 전체 개수 | ① 정렬 · LIMIT 을 안쪽 질의에서 먼저, users · teams 는 20행에만 ② `files(folder_id, created_at DESC, id DESC) WHERE deleted_at IS NULL` 색인 + `IS NOT DISTINCT FROM $` 를 `IS NULL` / `= $` 두 갈래로(지금 꼴은 색인 불가) + (created_at, id) 커서 ③ 전체 개수 없애거나 상한(「1,000+」) → **4.6s → 20ms 안팎, 깊은 쪽도 같음** |
| `/tbox/api/files?q=` 자료실 검색(흔한 낱말) 0.77s (17.5×) · 드문 0.11s (5.4×) | 위 목록 + `search_tsv @@ $` · `count(*)` | Bitmap(tsv) 8,608행 → 개인 갈래 Seq Scan folders 49만 행 제거 × 4 · count 0.74s | 후보 전부에 권한식 + 정확한 개수 | 개수 상한 · LIMIT 먼저 · 개인 폴더 조상 판정을 미리 계산한 조상 배열로 → **< 200ms** |
| `/tbox/api/folders` 폴더 목록 **3.13s** (30×) | `SELECT f.folder_id, count(*) FROM files f JOIN folders d ON d.id=f.folder_id WHERE d.parent_id IS NOT DISTINCT FROM $1 AND <볼 수 있음> GROUP BY 1` | 폴더 170개 × Bitmap files → 개인 파일 2.75만 행마다 **Seq Scan folders 5번(467.5만 행 제거)** | 하위 폴더마다 볼 수 있는 파일을 다 세고, 개인 갈래 조상 조인이 행마다 작은 표 훑기 | 폴더별 미리 센 개수(등급 · 팀별 — 트리거) 또는 `LIMIT 101` 하위 질의(「100+」) · 조상 배열 → **3.1s → < 30ms** |
| `/tbox/api/audit` 감사 0.20s (8.5×) · 깊은 쪽 0.35s | `SELECT count(*) AS total FROM audit_logs WHERE TRUE` + `… ORDER BY happened_at DESC, id DESC LIMIT 20 OFFSET $` | count = Index Only Scan audit_logs_pkey 165만 행(병렬 3) 0.27~0.66s · 목록은 `audit_time_idx` 로 빠름(깊은 쪽 포함) | 화면마다 정확한 전체 개수 | 개수 없애기(또는 `reltuples` 어림 · 「1만+」) + (happened_at, id) 커서 → **< 20ms** |
| `/tbox/api/audit?actor=` 0.38s (7.5×) | `count(*) … WHERE actor_name = $1 AND NOT (detail->>'anon_board' …)` | **Seq Scan audit_logs 165만 행**(822행 남김) | `actor_name` 색인 없음(있는 것은 actor_id) | `actor_id` 로 거르기(색인 있음) 또는 `(actor_name, happened_at DESC)` 색인 + 개수 상한 → **< 20ms** |
| `/community/api/briefing` 오늘 브리핑 **4.68s** (27×) · 질의 36 | ① 안 읽은 공지 `… <볼 수 있음> AND (s.board_kind='notice' OR EXISTS(…notice)) AND NOT EXISTS(page_reads …) ORDER BY p.updated_at DESC LIMIT 20` ② `files … AND f.uploaded_by=$6 AND f.scan_status='pending'` ③ 내 할 일 `… CASE WHEN s.kind='personal' THEN s.owner_user_id ELSE p.author_id END = $17` | ①③ **Seq Scan pages 49만**(0.7s씩) ② Bitmap files 8만(권한식) 0.9s | 질의마다 권한식 + CASE · OR 로 표 전체 | ① 공지 게시판 id 로 `pages_board_updated_idx` 직접 + 안 읽음 anti-join ② `files(uploaded_by) WHERE scan_status='pending' AND deleted_at IS NULL` 부분 색인 ③ 개인 공간 · 내가 쓴 글 두 갈래 UNION + `(author_id, updated_at DESC)` · 사람별 60초 캐시 → **< 150ms** |
| `/community/api/my-tasks` 내 작업 0.61s (12.5×) · `?done=1` 0.36s (10.6×) | `WITH mine AS (… WHERE p.deleted_at IS NULL AND (p.assignee_id=$4 OR (p.assignee_id IS NULL AND CASE WHEN s.kind='personal' THEN s.owner_user_id ELSE p.author_id END=$5 AND …)) AND <볼 수 있음>) … row_number() OVER (PARTITION BY bucket …) WHERE rn > $22 AND rn <= $23` | **Seq Scan pages 50만 → 31행** | OR + CASE 로 `pages_assignee` 를 못 탐 · `cursor` 는 실은 줄 번호(OFFSET) | 갈래 UNION ALL(담당자 = 나: `pages_assignee` · 담당자 없음 + 내가 주인: 부분 색인 `(author_id) WHERE assignee_id IS NULL AND (status IS NOT NULL OR due_date IS NOT NULL)`) + (due_date, updated_at, id) 커서 → **< 30ms** |
| `/community/api/pages?space=` 글 많은 개인 공간 **2.23s** (58×) · 깊은 쪽 4.23s · 팀 공간 0.55s (14×) | `count(*) … WHERE p.space_id=$ AND p.deleted_at IS NULL AND <볼 수 있음>` + 목록 `ORDER BY … LIMIT 20 OFFSET $` | count: Bitmap Heap Scan **1,770행에 1.99s(블록 1,645 읽기)** — 행이 표 전체에 흩어짐(DB 2.3GB > 캐시 몫) · 목록: 1,770행 정렬 + 행마다 select 목록 | 쪽마다 공간 전체를 세고 정렬 — 흩어진 행을 다 읽음 | 개수 없애기 · `pages_space_live_idx (space_id, position, id)` 순서 그대로 커서(LIMIT 21) · 목록 칸을 INCLUDE 로 덮는 색인 → **< 50ms, 공간 크기와 무관** |
| `/community/api/pages?tree=1` 공간 나무 **2.15s** (82×) | `… WHERE space_id=$ AND <볼 수 있음> ORDER BY p.position, p.id LIMIT 2000` | 1,770행 · Sort 13ms 인데 Limit 까지 2.6s(행마다 select 목록 · 흩어진 읽기) | 나무 전체(최대 2,000행)를 한 번에 | 뿌리만 먼저 · 펼칠 때 자식만(`parent`) · 칸 줄이기 → **< 50ms** |
| `/community/api/pages?shared=me` 공유 받은 글 **6.13s** (49×) — 결과 0건인데도 | `count(*)` + 목록 `… FROM pages p JOIN spaces … JOIN LATERAL (SELECT max(level) FROM page_shares ds WHERE ds.page_id=p.id AND (ds.user_id=$2 OR EXISTS(그룹))) …` + 행마다 `share_rank`(재귀 CTE) | **Seq Scan pages 49만** → Hash Join 32.5만 → 행마다 공유 판정 32.5만 번 | 「나에게 공유된 것」을 공유 표가 아니라 모든 글에서 찾음 | `page_shares(user_id)` · 그룹 회원에서 출발(공유 수만큼) → 하위 글만 재귀로 → **공유 수에 비례(수 ms)** |
| `/community/api/me/trash` 내 휴지통 **3.11s** (127×) | `WITH RECURSIVE roots AS (… p.deleted_by=$1 AND p.deleted_at > now()-30일 …), down AS (… JOIN pages c ON c.parent_page_id = d.id …)` | `down` 재귀가 **Seq Scan pages 50만**(0.5s) × 뿌리 · 하위 개수 SubPlan | 하위 글 색인 `pages_parent_idx` 가 `WHERE deleted_at IS NULL` 부분 색인 → 지운 글에 못 씀 | `pages(parent_page_id) WHERE deleted_at IS NOT NULL` 부분 색인 → **< 20ms** |
| `/community/api/search` 통합 검색 흔한 낱말 **1.72s** (16×) · 관리자 1.65s (13×) · 드문 0.69s (12×) · 두 낱말 0.57s (7×) | 글 `… WHERE p.deleted_at IS NULL AND p.search_tsv @@ to_tsquery('simple',$tq) ORDER BY in_title DESC, p.updated_at DESC, p.id DESC OFFSET 0` → LATERAL 권한 → LIMIT 20 / 댓글 같은 꼴 | 글: GIN **후보 42만 행** → 정렬 13.7만 행/작업자 **external merge(임시 1,376블록)** 1.1s / 댓글: **Seq Scan comments 99만** 0.48s | 흔한 낱말은 후보가 거의 전부 — 「제목 먼저」 정렬로 후보 전체를 정렬 · 댓글은 순차 읽기 | 낱말 빈도로 길 고르기(흔하면 `updated_at` 순서로 걸으며 tsv 거르기 · 20개 차면 멈춤, 드물면 지금 GIN) · 「제목 먼저」는 상위 N 안에서만 · 기간 · 공간 범위 기본값 · 댓글도 같은 두 길 → **흔한 낱말 < 200ms**(합성 '회의'는 글 84%에 있어 최악 쪽 — 한계 ②) |
| `/community/api/search?q=d1x` 영문 · 숫자 **3.09s = 3초 보호 마감(결과 0 · 안내)** — M 부터 | `… (p.title ILIKE $1 ESCAPE '#') … AND (p.title ILIKE … OR p.plain_text ILIKE …)` (`SET LOCAL enable_seqscan = off`) | EXPLAIN 도 60초에 취소 · S 계획은 `pages_parent_idx` 전체 훑기 + 행마다 ILIKE | 한글 밖 검색은 색인 0(J-10 ⓑ) | `pg_trgm` GIN(제목 · 본문 앞부분) 또는 영문 · 숫자 낱말도 `search_tsv` 토큰으로 → **< 200ms** |
| `/community/api/notifications` 알림 **0.61s — S 부터 0.42s** (1.5×) · **질의 122** | 알림 50건 한 질의 뒤 **행마다 `community_page_row(public_id, me)`**(같은 문장 50번 + 권한) | 문장 하나는 2~8ms(색인) — 합이 큼 | N+1(상수 50 — 그래서 S 부터 넘음) | 글 50개를 `WHERE public_id = ANY($ids)` 한 번 + 권한 한 번 → 질의 122 → 약 10, **< 60ms** |
| `/community/api/boards` 게시판 목록 **2.19s** (90×) · `?area=cafe` 1.25s (57×) | `SELECT b.*, (SELECT count(*) FROM pages p WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL AND (p.space_id=b.id OR (p.visibility='published' AND p.published_board_id=b.id))) AS post_count FROM spaces b … LIMIT 200` | 게시판 23개 × Bitmap Heap Scan 1.26만 행 · 블록 읽기 19.2만 | 게시판마다 글 수를 그때그때 셈(SR-5 — 규모 B 범위 밖) | 게시판별 미리 센 글 수(트리거 — view_count 와 같은 방식) 또는 60초 캐시 → **< 20ms** |
| `/community/api/boards/{n}/tags` 태그별 수 0.80s (21×) | `SELECT t.tag_norm, count(*) FROM page_tags t JOIN pages p … WHERE (p.space_id=$1 OR published …) AND <볼 수 있음> GROUP BY … LIMIT 10` | **Seq Scan pages 50만**(37.4만 제거) · Sort 6.2만 행 external merge | 게시판 전체 태그를 권한식과 함께 집계 | 게시판별 태그 수 표(트리거) 또는 최근 N개 글 안에서만 · 5분 캐시 → **< 20ms** |
| `/community/api/boards/{n}/pages?q=` 게시판 안 찾기 0.58s (10×) | 커서 목록 + `q` 거르기 | **Seq Scan pages 50만**(39.5만 제거) | 게시판 안 검색은 tsv 색인 갈래를 안 씀 | 통합 검색과 같은 `search_tsv` 조건 + space_id → **< 150ms**(기준 1초 안이나 글 110만 근처에서 넘음) |
| `/community/api/boards/{n}/pages?tag=` 0.12s (4×) | 커서 + 태그 | Index Only `pages_board_new_idx` **12.6만 행** → Hash(page_tags) 3,516 → Sort | 태그 조건이 색인 밖 — 게시판 글을 다 거쳐 거름 | `page_tags(tag_norm, page_id)` 에서 출발 또는 (space_id, tag_norm, created_at) 색인 → **< 30ms** |
| `/community/api/boards/{n}/pages?page=20` 옛 쪽 번호 0.55s (p95 0.95s) · 질의 52 | 커서를 20번 걸음(규모 B 설계 — OFFSET 없음) | 걸음마다 Merge Append Index Only 30~46ms × 20 | 상한은 있으나 20배 | 옛 `page` 상한을 5 로 또는 410 안내(화면은 이미 커서) → **< 150ms** |
| `/community/api/boards/{n}/pages?sort=views` 다음 쪽 p95 0.22~0.28s | 커서 다음 쪽 | Merge Append Index Only(정상) · p50 44~75ms | 3번 중 1번 튐(공유 기계) | 계획 문제 아님 — D2 에서 다시 잼 |
| `/community/api/memories` 이야기 피드 0.55s (13×) · `?offset=10000` 0.63s (22×) | `SELECT count(*), count(DISTINCT NULLIF(p.trip,'')), sum(jsonb_array_length(p.media_ids)) FROM memory_posts p JOIN users u … WHERE <팔로우 피드>`(매 첫 쪽) · offset 갈래 `LIMIT 21 OFFSET 10000` | 통계: Bitmap memory_posts_owner **16.2만 → 2,000행 0.86s** · offset: Index Scan 2,000행 0.33s | 첫 쪽마다 피드 전체 통계 · offset 상한 10,000 | 통계는 사람별 미리 센 값 또는 따로 · 캐시 · offset 상한을 낮추고 커서만 → **< 50ms** |
| `/community/api/memories?view=discover` (다음 쪽 p95 0.18s) · `mine` · `profile`(다음 쪽 p95 0.10~0.17s) | 둘러보기 첫 쪽 통계 count(4.95만 행 0.18s) | 다음 쪽 p50 은 28~45ms — p95 는 3번 중 1번 튐 | 통계 count 가 늘어남 · 나머지는 흔들림 | 위 통계 처리 · 튐은 D2 에서 다시 잼 |
| `/community/api/memories?q=` 0.22s (8.4×) | 통계 count + `strpos(lower(caption …))` | Index Scan discover 2.37만 행 0.2s | 통계 + 순차 거르기 | 통계 처리 · 이야기 글 tsv(글과 같은 방식) → **< 50ms** |
| `/community/api/pages?space=…&q=` 공간 안 찾기 0.51s (15×) | count + 목록(OFFSET) + ILIKE | 공간 행 전부 Bitmap 뒤 거르기 | 공간 목록과 같은 모양 | 공간 목록 고치기 + tsv 조건 → **< 100ms** |
| `/community/api/reports?status=all` 신고함 0.10s (4.2×) | 신고 100개 + 행마다 이름표 · 개수 하위 질의 | Seq Scan reports 3,300 × 100번(33만 제거) | 행마다 작은 표 훑기 | 하위 질의를 한 조인으로 · `reports(page_id)` 색인 → **< 30ms** |

**기준 안이지만 질의가 많은 것(상수)**: 글 읽기 `/community/api/pages/{id}` 질의 40~46(85ms) · 담당자 후보 54(180ms — 후보마다 권한 판정, 상한 50) · 멘션 후보 34(135ms). 데이터와 함께 늘지 않지만 D2 「질의 수 고정」 단언 대상.

**잘 버틴 것(L p50)**: 게시판 커서 목록 새 글 63ms · 고친 순 44ms · 조회순 63ms(다음 쪽 p50 44~75ms) · 작은 게시판 63ms · 인기 글 댓글 416개 43ms · 글 화면 31ms · 작업 기록 36ms · 판 목록 29ms · 안 읽은 알림 수 20ms · 즐겨찾기 24ms · 최근 30ms · 신고함 61ms · 사용자 7ms · 이야기 상세 13ms.

## ③ 2억 8500만 글 외삽 — 먼저 무너지는 다섯

방법: 창구마다 시간을 끄는 표 n(글 · 파일 · 감사 · 이야기 · 알림 …)에 대해 S · M · L 세 점의 log-log 기울기 k(시간 ∝ n^k, 고정 10ms 를 뺌)를 구해 「L 기획 비율 × 285」(글 2억 8500만 · 파일 1.4억 · 감사 14억 …)까지 늘렸다. **무너짐 = 앱 문장 마감 15초(→ HTTP 500)에 닿는 데이터 크기**를 같은 비율의 「글 수」로 바꿔 순서를 매겼다(`summary.json` 의 `extrap`). 숫자는 순서를 보는 어림이다(k 는 2 로 묶음 · 캐시 크기 효과 미반영).

| 순서 | 창구 | k | 기준(예산) 넘는 글 수 | **15초 마감(500) 글 수** | 2억 8500만 글에서 |
|---|---|---|---|---|---|
| 1 | 카페 홈 `/community/api/cafe` | 1.42 | 약 3만 | **약 47만 — 이미 L(50만)에서 500** | 열리지 않음 |
| 2 | 자료실 목록 `/tbox/api/files` | 1.61 | 약 4만 | **약 69만** | 열리지 않음 |
| 3 | 폴더 목록 `/tbox/api/folders` | 1.22 | 약 3만 | **약 119만** | 열리지 않음 |
| 4 | 공유 받은 글 `/community/api/pages?shared=me` | 1.01 | 약 1만 | **약 121만** | 열리지 않음 |
| 5 | 공간 페이지 목록(글 많은 공간) `/community/api/pages?space=` | 1.19 | 약 3만 | **약 152만** | 열리지 않음 |

그다음: 내 휴지통(158만) · 오늘 브리핑(192만) · 게시판 목록 글 수(233만) · 공간 나무(251만) · 이야기 offset(383만) · 카페 게시판 목록(432만) · 이야기 피드 통계(465만) · 자료실 검색(532만) · 통합 검색(946만 — 그전에 3초 검색 보호로 「결과 없음 안내」, 영문 검색은 이미 M 에서) · 감사 목록(전체 개수 — 감사 수십억 행에서).
**결론**: L(글 50만)과 2억 8500만은 570배다. 위 열 개는 모두 글 50만~500만 상당에서 15초를 넘고, 그 전에 **DB 부하**(요청 하나가 표 전체를 읽음 — 동시 몇 명이면 CPU 2개 포화)가 먼저 온다. 커서 + 정렬별 색인으로 바꾼 게시판 목록(k≈0.2)처럼 **「읽는 행 수 = 화면에 보이는 행 수」** 모양인 창구만 2억 8500만에서도 버틴다.

## ④ 측정 조건

| 항목 | 값 |
|---|---|
| 대상 | 격리 worktree `/home/changkwon/qaredi-scale-d-20261014`(feat/scale-d1 = 운영 v2.68.1) 사본을 `/tmp/qaredi-scale-d-*` 에 복사 · PHP 8.1 내장 서버 2개(127.0.0.1:8838 직결 = 시간 · :8839 = 질의 수 · SQL — 중계 :8848) · opcache 켬 · 앱 역할 `tbox_app`(문장 마감 15s · 임시 파일 64MB · idle 60s) · 검색 보호 3초 그대로 |
| DB | 운영과 같은 PostgreSQL 14.24 클러스터에 합성 DB `tbox_scale_d_<pid>`(소유 tbox_testdb · 표 112개 **UNLOGGED** — 대량 생성의 WAL 0 · 읽기 계획 같음 · 외래키 235개 그대로) · 스키마 = `db/schema.sql` + 커뮤니티 + 시험 틀 이주 목록 + 운영에 반영된 규모 A·B 이주 3개 · 단계마다 TRUNCATE 뒤 다시 생성 + `VACUUM (ANALYZE)` · 데이터는 `generate_series` 만(파일 0 · 개인정보 0 · 운영 사본 0) |
| PG 설정 | shared_buffers 160MB · work_mem 4MB · effective_cache_size 5GB · random_page_cost 4 · max_parallel_workers_per_gather 2 · jit on · default_statistics_target 100 · lc_collate en_US.UTF-8 |
| 서버 | CPU 2 · RAM 3.8GB(여유 약 2.5GB) · 운영 서비스와 같은 기계(측정 중 load 1.2~1.5) — 운영 요청이 시간에 섞일 수 있다 |
| 행 수 (S / M / L) | 사용자 200 / 1,000 / 2,000 · 팀 10 / 30 / 50 · 게시판 24 · **글 1만 / 10만 / 50만** · 댓글 5만 / 50만 / 99만 · 속성 사건 5만 / 50만 / 99만 · 알림 5만 / 50만 / 99만 · 감사 10만 / **110만**(한계 ⑥) / 165만 · 이야기 글 · 미디어 행 · 이야기 댓글 각 1만 / 10만 / 16.5만 · 자료실 파일 행 1만 / 10만 / 16.5만 · 신고 · 초대 요청 각 100 / 1,000 / 3,300 · 태그 3천 / 3만 / 15만 |
| L 을 줄인 까닭 | 시작 여유 **4.73GB < 6GB**. M 의 행당 크기(글 1.56KB · 댓글 476B · 감사 347B …)로 기획 L 전체 ≈ 6.6GB → 상한(DB 3GB · 여유 2GB 바닥)에 맞춰 글 0.5 · 나머지 0.33 |
| DB 크기 | S 0.13GB · M 1.09GB · L **2.34GB**(글 751MB · 감사 532MB · 댓글 461MB) |
| 디스크 남은 공간 | 시작 4.73GB · 최저 **2.31GB**(L 생성 직후) · 끝 4.8GB(DB 삭제 뒤) |
| 걸린 시간 | S 생성 30s + VACUUM 135s + 측정 86s · M 292s + 399s + 304s · L 757s + 397s + 904s — 합계 약 1시간 10분(개발 시험 제외) |
| 측정 방식 | 창구마다 데우기 1번 + 7번(p50 · p95 보간) · 커서가 있으면 다음 두 쪽 각 3번 · OFFSET 목록은 약 1만 번째 줄 쪽(`page=501` · 사용자 `201`) 3번 · 중계 서버로 1번 더 불러 Parse/Bind/Execute 를 세고 SQL · 값을 잡음 · 느린 문장(2ms 넘음 · L 은 창구마다 1등 · 깊은 쪽)을 tbox_testdb 로 `EXPLAIN (ANALYZE, BUFFERS)`(트랜잭션 안 · ROLLBACK) |
| 원문 결과 | `/home/changkwon/qaredi-scale-d-results-20261014/run1/`: `tier-S.json` `tier-M.json` `tier-L.json`(창구별 시간 · 질의 · SQL · 값 · 계획 요지) · `plans/`(EXPLAIN JSON 422개) · `summary.json` · `summary.md`(①-가 표) · `inventory.json` · `meta.json` · `run1.log` · L 원본 `run1-L/`(같은 코드로 따로 실행) |
| 정리 | 단계 끝마다 PHP 정지 · 합성 DB DROP · `/tmp` 작업 폴더 삭제 확인(합성 DB 0 · 작업 폴더 0 · PHP 0 · 포트 8838/8839/8848 닫힘). L 실행 끝 정리에서 앱 연결 1개 때문에 dropdb 가 120초 안에 실패 → 결과를 다 쓴 뒤라 손으로 DROP. 도구는 그 뒤 5분 재시도 · 자기 연결 끊기 · 실패해도 결과를 남기도록 고쳤고 S 한 창구로 다시 돌려 정리까지 확인 |

## 한계

① **L 은 기획의 50%(글) · 33%(나머지)** — 비율 외삽으로 메웠다. 첫 실행(S · M · L)이 자동으로 정한 L 비율(글 0.866 · 나머지 0.182)은 나머지 표가 M 보다 작아져 L 생성 도중 멈추고(취소 · DB 삭제), 글 0.5 · 나머지 0.33 으로 L 만 다시 쟀다. ② 합성 낱말은 120개를 앞쪽에 몰리게 뽑아 '회의'가 글의 84%에 있다 — 흔한 낱말 검색은 실제보다 나쁜 쪽. ③ 같은 기계 · 같은 클러스터의 운영 요청 · OS 캐시를 나눠 써서 p95 가 한 번씩 튄다(이야기 · 조회순 다음 쪽) — p50 과 같이 봤다. ④ 질의 수에는 요청마다 같은 고정 문장(세션 확인 · 갱신 · 동의 · 권한 표 · PDO `DEALLOCATE`) 6~8개가 들어 있다(빈 창구 = 8). ⑤ 공유(page_shares) · 그룹 · 데이터베이스 행 · 포트폴리오 · 첨부 · 판(page_versions) · 조회 기록 · 읽음 기록은 합성하지 않았다 — 「공유 받은 글」은 결과 0건에서의 비용이고, 이 표들이 크면 관련 창구는 더 느리다. ⑥ 첫 실행에서 S 의 감사 10만 행이 M 에 남아 M 감사가 110만(seed 를 고침 — 참조 없는 표도 TRUNCATE, 확인함). ⑦ EXPLAIN 은 소유 역할로 같은 값을 다시 돌린 것이라 캐시 상태가 앱 실행과 다르다(영문 검색은 60초에 취소). ⑧ 글 많은 공간 1개(사람 2번 · 글 1,770)만 쟀다. ⑨ 동시 요청 · 쓰기 부하는 재지 않았다(D2 후보). ⑩ glibc `en_US.UTF-8` 정렬이 한글 긴 문자열에서 매우 느리다(합성 이야기 캡션 1만 개 정렬 15s · `COLLATE "C"` 13ms — ANALYZE 가 느렸던 까닭). 앱이 한글 칸으로 정렬하는 창구는 이번 측정에서 두드러지지 않았지만 D2 에서 볼 거리.
