# 21단계 구현 지시서 (Fable → Opus) — 입고(GR) · 이동(MV) · 사용(IS) · 실사(ADJ) · 재고 원장 · 장소별 재고현황 · 수불부 · 미입고 현황 · 사이클 완성

작성 2026-09-13. 근거: PRD F-33·F-34(일부)·Q-18③, ARCH 5.18(원장·음수 재고·재고관리 안 하는 품목·실사 주기), SECURITY R-66(원장 부분 ◐), STAGES 20단계 학습 기록, 시안 GR·MoveUse·Status·Backorder·Cycle·Docs.

```
목표        : 발주서를 불러와 입고하고, 장소 사이 이동·수술방 사용·실사 차이를 전표로 남기며, 모든 수량 변화를 append-only 원장(p_stock_moves) 한 곳에만 쓴다.
              현재고는 원장의 합(뷰)이고, 음수 재고는 트랜잭션 안에서 막는다. 장소별 재고·수불부·미입고 현황·사이클(GR 칸)을 보여 준다.
요구사항    : PRD F-33·F-34·T-46, ARCH 5.18 표(원장·연결·음수 재고·채번·마감·재고관리 안 하는 품목·실사 주기), 4.0·4.1·7.4·7.5·13장, docs/purchase/03 불변 규칙 7개, 20단계 코드(Doc.php 채번·잔량·상태 전이) — 읽고 시작.
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-14_purchase_stock.sql — p_docs.doc_type CHECK 에 gr|mv|is|adj 추가, p_docs.from_location_id NULL·count_cycle CHECK(daily|weekly|monthly) NULL·lot_no 는 줄에,
                                                 p_doc_lines.lot_no text NULL·expiry_date date NULL·counted_qty numeric NULL(실사),
                                                 p_stock_moves(id, doc_line_id→p_doc_lines, location_id→p_locations, item_id, qty_delta numeric(14,3) CHECK<>0, moved_at, reverses_move_id NULL→p_stock_moves, created_by)
                                                 인덱스 (location_id,item_id), (doc_line_id), (item_id, moved_at)
                                                 뷰 p_stock_balance(location_id,item_id, qty = SUM(qty_delta)) · 뷰 p_backorder(발주서 줄 기준 qty − Σ 입고 qty, sent_at 있고 잔량>0)
                                                 GRANT: p_stock_moves **tbox_app INSERT·SELECT 만**(UPDATE·DELETE 없음 — 원장), 뷰 SELECT, tbox_backup SELECT. p_docs·p_doc_lines 는 20단계 그대로.
    src/purchase/Stock.php     — 원장 기록(한 트랜잭션 안: 관련 (location,item) 을 p_stock_balance 대신 **SELECT … FROM p_stock_moves WHERE … FOR UPDATE 가 불가하니 p_locations 행 잠금 + 합 재계산** 순서로), 음수 검사, 취소 = 반대 부호 행(reverses_move_id), 재고관리 안 하는 품목은 +·− 두 행(순변화 0)
    src/handlers/purchase_gr_*.php  — 발주서 불러오기(미입고 잔량만, 보낸 것만) → 부분 입고·로트·유통기한 → 확정 시 원장 +, 발주서 줄 상태(일부 입고/완료), 사이클 GR 칸
    src/handlers/purchase_mv_*.php  — 이동(from→to, 같은 지점 안 기본·buyer 는 지점 간 가능), 「중앙장 부족분 자동 채우기」 제안(to 장소의 인가량 − 현재고 를 from 창고 재고 한도로), 확정 시 − 와 + 두 행
    src/handlers/purchase_is_*.php  — 사용(수술방 등 장소에서 차감), 확정 시 − 행
    src/handlers/purchase_adj_*.php — 실사(장소·주기 daily|weekly|monthly, 품목별 실사 수량 입력 → 차이만 ± 행), 실사 시작 시 그 장소의 현재고 스냅샷을 줄에 채워 화면에 보여 줌
    src/handlers/purchase_stock_status.php — GET /api/purchase/stock?location=&branch=&cat1=&q=&page= : 현재고·인가량(p_par_levels 최신)·과부족·미입고(p_backorder)·주평균 사용(최근 4주 IS)·발주 제안 자리(23단계 값은 null)
    src/handlers/purchase_stock_ledger.php — GET /api/purchase/ledger?location=&item=&from=&to= : 수불부(원장 행 + 전표 번호·종류)
    src/handlers/purchase_backorder.php    — GET /api/purchase/backorder?vendor=&overdue=1 : 미입고 현황(거래처별·납기 지남·독촉 표시용 sent_at 경과일)
    src/handlers/purchase_stock_page.php · purchase_receipt_page.php · purchase_move_page.php(이동·사용·실사 3탭) 화면 핸들러 + views + public/assets/purchase_stock.js·purchase_stock.css (다른 JS 파일은 손대지 않는다)
    tests/purchase_stage21.sh  — T-46 + 아래 완료 기준. 관문·정리 규칙은 20단계 스크립트와 같게(R-73).
  [고침 — 범위만]
    public/index.php(라우트·REAUTH 키 purchasestock 등 — 필요하면), src/Authz.php(purchase.stock.write 판정은 있음 — 별칭 추가만), src/Audit.php(문장 틀 purchase.receipt|move|use|count.{create|confirm|void}, detail 키 lot_no·count_cycle·qty_delta·moves·location_id·from_location_id·to_location_id), scripts/security_lint.sh(규칙 (e): purchase_gr_/mv_/is_/adj_ 핸들러가 `INSERT INTO p_stock_moves` 를 직접 쓰면 반려 — Stock.php 만 허용), scripts/alerts.php(AL-15 미입고 30일 — 규칙 골격만, 23단계에서 임계값 설정), purchase_cycle.php(received_qty·not_received 채움), src/handlers/purchase_page.php(stock 역할 첫 화면 /purchase/stock), README, STAGES(21단계 절·체크), db/grants.sql·db/schema.sql
금지 패턴   : SQL 결합, innerHTML, 클라이언트 수량 외 값 신뢰(원가·금액은 서버), Authz 밖 판정, p_stock_moves UPDATE/DELETE, 현재고 컬럼(합만), 원장을 Stock.php 밖에서 INSERT, 사용자 활동 중 시험(관문), 자격증명 cat, 새 라이브러리.
완료 기준   : T-46 — 발주서(보냄) 5 중 3 입고 → p_stock_moves +3(로트)·현재고 뷰 3·미입고 2·p_backorder 에 등장·사이클 received 3 ; 이동 5(재고 3) → 400 negative_stock ; 이동 2 → from −2/to +2 ; 사용 1 → −1 ; 실사(daily) 실사수량 5 vs 현재고 → 차이 +/− 한 행만 ; 취소 → 반대 부호 행 + reverses_move_id, 현재고 복구 ;
             재고관리 안 하는 품목 입고 → +·− 두 행, 순변화 0, 사용 통계엔 포함 ; 동시 이동 2건(재고 3, 각 2) → 한쪽 400 ; `tbox_app` 으로 `UPDATE p_stock_moves` → permission denied ;
             stock 역할(부평점)이 다른 지점 장소 재고 → 0건·상세 404, 이동 to 다른 지점 → 400/403 ; requester 는 입고·이동 403 ; 미입고 현황 requester 도 자기 지점만 ; 린트 0(규칙 (e) 포함) ; \dp p_stock_moves = tbox_app ar 만 ; 회귀 T-45(20단계 스크립트) 관문 열릴 때.
보고 형식   : 변경 파일 / 명령·출력 원문 / 못 한 것 / 13장 체크리스트 / 사용자가 볼 것 3줄. 커밋 금지.
```

## 보충 규칙
1. **원장 쓰기 순서(음수 방지)**: 트랜잭션 → 관련 `p_locations` 행 `FOR UPDATE`(장소 단위 직렬화) → `SELECT COALESCE(SUM(qty_delta),0) FROM p_stock_moves WHERE location_id=? AND item_id=?` → 결과 + 변화량 < 0 이면 400 `negative_stock` → INSERT. 장소 단위 잠금이라 같은 장소 동시 요청은 직렬, 다른 장소는 병행.
2. **입고 잔량**: 발주서 줄 qty − Σ(그 줄을 src 로 하는 GR 줄 qty, void 제외). 초과는 400 `remaining_exceeded`(20단계와 같은 사유 코드). 발주서 상태: 일부 입고 → 완료(잔량 0). 보내지 않은(sent_at NULL) 발주서는 불러오기 목록에 없음.
3. **이동 자동 채우기**: 제안 API `GET /api/purchase/mv/suggest?to=<중앙장>` → to 장소 인가량(p_par_levels 최신 effective_from ≤ 오늘) − 현재고 > 0 인 품목을 from(지점 창고) 현재고 한도로 제안. 화면은 제안값을 수정 가능, 확정은 일반 이동과 같다.
4. **실사**: 전표 생성 시 장소·주기 선택 → 그 장소 현재고 스냅샷(줄마다 `qty` = 스냅샷, `counted_qty` = 입력). 확정 시 차이(counted − 스냅샷)가 0 이 아닌 줄만 원장 한 행. 스냅샷 뒤 다른 전표가 재고를 바꿨으면(확정 시 재계산 값 ≠ 스냅샷) 400 `stale_snapshot` 으로 다시 열게.
5. **재고관리 안 하는 품목**(`p_items.stock_managed=false`): 입고 확정 시 +qty·−qty 두 행(같은 doc_line), 이동·실사 대상 아님(400 `not_stock_managed`), 사용 통계(주평균)에는 IS 가 아니라 GR 의 + 행을 사용량으로 본다.
6. **지점 격리**: 모든 조회 `purchaseScopeWhere` 를 p_locations 별칭(`l`)에 붙인다(원장·전표는 장소로 조인). stock 역할은 자기 지점 장소만, buyer 전 지점.
7. **화면**: 시안 GR(발주서 불러오기·부분 입고·로트)·MoveUse(이동/사용/실사 3탭)·Status(장소별 재고: 현재고·인가량·과부족·미입고·주평균·제안)·Backorder(거래처별·납기 지남·독촉)·Cycle(GR 칸 채움). purchase_stock.js 만 새로.
8. **시험 안전(R-73)**: 관문·created_by 정리·인구조사는 20단계 스크립트 그대로. 픽스처 접두 `S21-`.
