# 규모 B — 게시판 목록 커서와 조회수

- 상태: 사용자 승인으로 재개, 모바일 겹침 해결·수락 통과, 성능/최종독립검토 진행 중
- 브랜치: cloud/scale-b
- 기준: e1d0ce80f454 (운영 v2.60.2 / 4e71c9d)
- 사용자 지시: root docs/scale-fix-codex-prompt-2026-10-12.md ④. A 운영/기록/push 완료 뒤 시작.

## 범위와 개발 사항

- [ ] 같은130만합성DB의 구 목록 new/updated/views·깊은쪽·q/tag 기준
- [ ] page_views 규칙 유지, pages.view_count 값 채움·새조회만같은트랜잭션+1
- [ ] pages 조회수정렬색인의 HOT/쓰기비용 vs 별도카운터 비교 후 선택
- [ ] 3정렬·양방향 엄격커서·원래권한조건그대로 LATERAL·갈래별색인/Merge Append
- [ ] 옛 page≤20 호환, 호출자전수조사, 전체개수/쪽번호제거
- [ ] 실제HTTP·순서/경계·권한·삭제·필터·동시조회, light/dark390브라우저클릭/스크린샷직접확인
- [ ] 독립검토·백업/복호화·고정/모의·드리프트·운영 minor 발행·보존·기록/push

허용: src/community/BoardCursor.php(신규), src/community/handlers/board_pages_list.php, src/community/routes.php의 community_record_view/community_view_count만, pages_get.php(필요한 응답), public/assets/community/editor.js·board화면 뷰, moa.js·clients/호출자(계약정합필요시), 자산생성import, db/migrations/2026-10-25_scale_board*.sql, tests/scale/* 및 격리실행기·관련회귀기대값, API/ARCHITECTURE/DESIGN/SECURITY/STAGES·위키/차례·인계·production-state·발행파일/이기록. C권한함수 변경금지, SR5게시판개수 등 범위밖기록만.

## 검증·배포·보존

측정클러스터 /home/changkwon/qaredi-scale-results-20261012/cluster port55432 Unix전용. A와같은합성행/실제운영설정 유지, 이전쿼리SQL은 perf/board.php. 디스크3GB미만즉시중단. 운영데이터DELETE/TRUNCATE/재시드/복원금지. 신규색인CONCURRENTLY/트랜잭션밖. 보안4부·수치·명령/원문증거·백업/운영보존은진행에따라아래추가. 후보와독립검토해시고정. C는이후별도세션검토승인전배포금지.

## 후속

SR5/SR12 및 검색trgm/정렬변경·캐시·파티션은여전히범위밖.

## 진행 기록

- 130만 행 조회수 초기화 첫 시도는 120초 statement_timeout으로 취소되어 전체 트랜잭션 롤백. 디스크 최저 3GB 중단은 발생하지 않았고 현재 4.9GB. 대량 초기화에만 600초 상한을 두고 재측정하며 lock_timeout 3초는 유지한다. 운영에는 아직 B를 적용하지 않았다.
- 독립 검토에서 날짜 +24:00을 PHP가 허용하지만 PostgreSQL은 거절함을 확인. 발행/검증 날짜를 UTC +00:00으로 단일화하여 범위 밖 오프셋을 거절한다.
- 기존 purge_trash의 synced_references DELETE page_id 모호성은 B와 무관한 기존 결함. 운영 실행/수정하지 않고 후속으로 남긴다.

## 설계 선택과 비용

기존 pages.view_count를 진실로 유지한다. 5천 행 쓰기: 기존 pages 283.308ms/WAL8,693,774B, 여섯 색인 추가 pages376.010ms/10,404,104B, 별도 작은 카운터표68.483ms/2,379,944B. HOT 비교는 fillfactor70의 같은 임시 복제표에서 조회색인 없을 때 2283/5000 HOT, 추가 후 HOT 증가0을 확인한다. 별도표가 더 빠르지만 두 곳 동기화와 게시/이동 메타데이터 관리가 추가되므로 사용자 지정 canonical 칸을 유지한다. 상세 원문 evidence/b-view-cost.log·b-hot-cost.log.

호출자 검색: 실제 UI editor.js를 커서로 전환. moa.js는 첫 페이지 pages.slice(0,5)만 쓰며 total/page 미사용. clients/MCP에는 이 게시판 endpoint의 쪽 번호 소비자가 없다. 기존 회귀 스크립트의 페이지/개수 가정은 새 합성 HTTP 수락 시험과 독립 구/신SQL 순서 대조로 검증한다. 구 page는 최대20회×21행 읽기로 제한(OFFSET 없음), 임의 깊은 쪽 점프/정확한 전체개수는 사용자 결정대로 제거한다.

첫 HTTP 묶음은 공통196/0·A25/0 통과 후 B fixture의 page_tags.created_by 누락으로 중단했다. 제품 결함과 구분해 fixture를 수정했으며 실제 게시판 주소는 /community/b/{id}이다.

두 번째 HTTP 시험은 공통196/0·A25/0·B52/0 후 시험용 브라우저 주소 오타(/boards 대신 /b)로 실패. 제품 라우트 변경 없이 시험 주소를 수정했다.

## 2026-10-12 중단 기록 — 사용자 프롬프트 7절

B 수락 묶음이 세 번 완주하지 못했으므로 「같은 항목 3회 실패」 중단 조건을 보수적으로 적용한다. 첫 두 건은 제품 오류가 아닌 시험 준비 실수(created_by 누락, /boards 대신 /b 주소)였고, 세 번째는 실제 제품 모바일 클릭 결함이다. 각각의 이유가 다르지만 같은 수락 묶음을 세 번 실패했으므로 더 재시도하지 않는다. B 운영 반영/발행/push 없음, C 미착수. 운영 A v2.60.2는 유지한다.

- 공통 회귀196/0·A HTTP25/0은 세 번 모두 통과. 마지막 B HTTP/초기 화면53개 성공 후 390px 화면에서 #board-next 클릭이 30초 동안 실패. `#usage-guide-root .usage-guide-launch`가 포인터를 가로챈다는 Playwright 원문을 evidence/http-b-3.log에 보존했다. force click/안내 숨김으로 우회하지 않았다. 실제 게시판 light/dark 스크린샷 검증은 완료하지 못했다.
- 독립 검토(별도 컨텍스트) 구/신 SQL 180조건 동일 순서·이전240회, 엄격 커서·동시조회/롤백/권한·JS 취소/1회재시도 통과. 날짜 비표준offset 결함은 UTC canonical으로 수정/재시험 통과. 최종 고정SHA 검토는 아직 하지 않았다. 원문 review-b/review-b.txt 및 check.log, concurrency.log, http-check.log, ui-check.log.
- 같은130만 DB: 기존 1M 목록 new7959.915ms/updated4776.237ms/views35432.234ms, 깊은쪽21142.750ms. 새쿼리 사후 성능/계획 측정 미실행. q 기준 재측정6076.951ms(count)+7383.320ms(list)는 보호장치를 통하지 않은 SQL 진단값이며 실제 A 검색은3초 제한이다.
- 조회수 초기화 1회120초취소/전체롤백 후600초 제한 재시도 성공(760838행 채움), 여섯 CONCURRENTLY 색인 성공. 이는 합성 DB에만 적용했다. evidence/b-migration-retry.log·b-migration-disk.json, 최저여유4.20GiB. 같은DB 비교를 재개할 수 있도록 Unix전용55432 클러스터를 fast 정상정지하여 보존했다. 삭제하지 않은 이유는 작업이 완료되지 않았고 같은DB 전후비교가 아직 필요하기 때문이다. 재개 후 최종 완료 시 이 클러스터와 독립검토 임시DB를 제거한다. 현재 디스크여유4.3GiB.
- 금지패턴0, 위키69편/문제0, PHP/JS 구문 확인, 자산버전537c77a69ff8. 세 번째 실행이 끝난 뒤 제품 수정은 하지 않고 이 중단 기록만 남긴다.

### 보안 검토 네 부분

① 안전한 이유: 기존 권한 조건절을 그대로 적용하고 매요청 게시판 gate를 통과한다. 독립 180조건 순서대조·변조/범위거절·동시중복조회/롤백/권한 시험과 실제HTTP53개로 확인했다. 새 cursor는 내부id를 암호화하며 권한 증명으로 사용하지 않는다.
② 불안전한 이유: 모바일 안내 버튼이 다음 버튼을 덮는다. 조회수 정렬색인은 HOT 갱신을 막고 별도 카운터보다 쓰기 비용이 높다. 현재 B는 릴리스할 수 없다.
③ 미검증: 최종모바일 light/dark 스크린샷·사후130만성능/Merge Append·최종고정SHA독립검토·운영모의/백업/반영/보존. C 전수대조·운영 반영도 미착수다.
④ 다음 조치: 사용자 재개 지시 후 안내/페이지단추 겹침을 기존 디자인 토큰 범위에서 해소하고 클릭/스크린샷 재검증 → 같은 합성DB 후측정 → 독립최종검토 → B 백업·고정·모의·운영·기록. 그 뒤 C를 구현/전수대조하고 별도 세션 검토 전에는 반영하지 않는다.

## 재개 — 2026-10-12

사용자가 「재개해」 지시. B부터 계속한다. 중단 사이 문서 선택 삭제 v2.61.0/c2294ec 운영 기록이 추가되어 이 변경을 후보에 병합해 보존했다. 충돌은 문서 양쪽기록 결합과 생성 import 버전뿐이며 실제 editor/routes 변경은 자동병합 후 diff/회귀로 대조한다. B 발행은 최신 minor v2.62.0 예정. public/assets/community/community.css를 수정 허용목록에 추가해 게시판 바닥과 안내 단추 겹침을 기존 토큰 범위에서 해결한다. 재개 이후 실패 횟수는 새로 기록한다. 병합 도우미 정규식1회 오류는 수정했으며 실행 증거에 남긴다.

2026-10-12 사용자 재개 후: 운영 c2294ec 문서선택삭제를 보존 병합, routes diff는 B 조회함수 둘·editor diff는 initBoard와 생성버전뿐. 게시판 table-foot 아래64px+safearea 여유로 안내단추겹침 해결. 재개 수락1회에서 공통196/0·A25/0·B59/0, 실제390px light/dark next/prev 클릭과1280검색/정렬 통과·JS0. 스크린샷3장을 직접 확인했다. evidence/http-b-resume-1/. 다른 운영변경 보존 확인은 독립 재검토 중, 아직 B배포 전이다.
