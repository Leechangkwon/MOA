# F-63 구현 지시서 — 입고 불러오기: 단추 뒤 창 · 발주요청 불러오듯 전표 단위

> 근거: PRD F-63 · 12.2 T-75 · F-33 · F-45 · S-01 / ARCHITECTURE 5.18 「입고 불러오기 — 단추 뒤 창·묶는 기준」·
> 25단계 「JS 헬퍼 한 벌」 / SECURITY R-65 · R-73 / 시안 ⑥.
> 설계: Opus 5(2026-09-17). 사용자 결정 — 조회는 **창 안 「불러오기」를 눌러야** 돈다 · 묶기 코드는 **새 파일 한 벌**.
> **F-62 를 먼저 구현·검토한 뒤에 착수한다**(`purchase_docs.js` 를 두 건이 함께 만진다).

## 진단

| 무엇 | 현재 |
|---|---|
| 자리 | 입고 「입력」 탭 **맨 위에 늘 펼쳐진 패널** `#gr-load` 「발주서 불러오기 — 미입고 잔량만」 |
| 조회 시점 | **화면 진입마다 자동** — `purchase_stock.js` 의 `loadMaster('vendors').then(… loadOrders())`, 300줄 상한 |
| 담기 | 발주서별 묶음마다 「줄 전부 담기」 + 줄마다 「담기」 — 묶는 기준도, 여러 전표를 한 번에 고를 체크박스도 없다 |
| API | `GET /api/purchase/gr/load` — `?po=&vendor=&branch=&location=&overdue=&q=`, **언제나** 발주서별 `orders` |
| 비교 | 발주서 화면은 `load-modal` 안에 묶는 기준 칩 여섯 + 트리(`drawTree`) + 묶음 체크 + 담을 수량. 서버는 `pr_load` 의 `?group=`(`$keyOf`·`$build`) |

```
목표        : 입고의 「발주서 불러오기 — 미입고 잔량만」을 단추 뒤 창으로 옮기고,
              그 창을 발주요청 불러오기와 같은 틀(묶는 기준·트리·묶음 체크)로 만든다.

요구사항    : PRD F-63 · 12.2 T-75 · F-33 · F-45 · S-01,
              ARCHITECTURE 5.18 「입고 불러오기 — 단추 뒤 창·묶는 기준」·「F-62·F-63 창구 변화」·
              25단계 공통 규칙 표(「JS 헬퍼 한 벌」), SECURITY R-65 · R-73.

수정 파일   : src/purchase/Grouping.php                 (새 파일)
              src/handlers/purchase_gr_load.php
              src/handlers/purchase_pr_load.php         (묶기 코드를 공통 조각으로 옮기는 것만)
              src/views/purchase_receipt.php
              public/assets/purchase_stock.js
              public/assets/purchase_common.js          (loadPicker 추가)
              public/assets/purchase_docs.js            (공통 함수 호출로 바꾸는 것만)
              tests/purchase_f63.sh                     (새 파일)
              — purchase.css 는 무수정(기존 p-tree·p-chipbar·.split·.modal 을 쓴다).

해야 할 것  : ① Grouping.php — 순수 함수 한 벌(rows, levels, keyOf → 트리 + 층마다 줄 수·수량 소계).
                 DB 접근 0. pr_load 의 $keyOf·$build 를 옮긴 것이고 **동작은 같다**
              ② pr_load 가 그 조각을 부르게 — 응답이 한 글자도 같아야 한다(f60·f61 회귀가 증거)
              ③ gr_load 에 선택 키 ?group=(1~3개, 화이트리스트 여섯: vendor·location·manager·branch·category·doc)
                 — 있으면 nodes, 없으면 지금의 orders 그대로. 모르는 값은 무시
              ④ gr_load SELECT 에 분류 두 칸(i.cat1_id·분류 이름)만 추가
              ⑤ purchase_receipt.php — #gr-load 패널 제거, 입고 줄 카드 도구에 「발주서 불러오기」 단추 +
                 <dialog id="gr-load-modal">(발주서 화면 모달과 같은 뼈대: 칩바·걸개·「불러오기」 단추·2단 split)
              ⑥ purchase_stock.js — 진입 자동 loadOrders() 제거. 단추 → 창 열기(조회 0회),
                 창 안 「불러오기」 → 조회. 빈 문구는 「「발주서 불러오기」로 입고할 줄을 고르세요」
              ⑦ purchase_common.js — UI.loadPicker(...): 트리·묶음 체크(중간 상태 포함)·담을 수량.
                 줄 표의 열과 담기 뒤 동작은 인자로 받는다
              ⑧ purchase_docs.js — 발주서 화면이 그 공통 함수를 부르게(중복 코드 삭제)
              ⑨ tests/purchase_f63.sh — T-75 (a)~(j)

금지 패턴   : SQL 문자열 결합(group 값은 SQL 에 닿지 않는다), innerHTML,
              역할 판단을 Authz 밖에서 하는 코드, 요청 파라미터의 role 값 사용,
              새 API 창구 · 새 정책 · 새 표 · 스키마 변경 · 새 CSS 클래스 · 새 감사,
              POST /api/purchase/gr(입고 확정) 변경,
              group 없는 gr/load 응답 모양 변경,
              pr_load 의 거동 변경,
              트리·체크 코드를 두 벌로 두기

완료 기준   : PRD 12.2 T-75 (a)~(j) 전부 실행.
              회귀 purchase_stage21.sh · purchase_f45.sh · purchase_f60.sh · purchase_f61.sh · purchase_f62.sh.
              scripts/security_lint.sh 0 건. php -l. scripts/ui_snapshot.sh 결함 0.

보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유 / 지시서와 다르게 판단한 것
```
