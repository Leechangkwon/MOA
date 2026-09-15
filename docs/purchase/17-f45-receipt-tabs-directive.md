# F-45 지시서 (Fable → Opus) — 입고 화면에 입력 · 전표 · 현황 탭 (시안 ⑥ GR 복원)

작성 2026-09-15. 근거: 사용자 지적 2026-09-15 「입고에는 왜 입력/전표/현황이 안 나오고 입력만 있나」. 시안 `docs/purchase/design/GR.dc.html` 은 탭 3개(입력·전표·현황)이고 발주요청(pr)·발주서(po) 화면도 같은 구조인데, 25단계에서 입고만 「불러오기 → 줄」 두 카드로 줄인 것은 검토자(Fable)의 결정 오류였다.
PRD F-45(신설)·12.2 T-57 · 8장 API 2행 ; ARCH 5.18 보충 ; STAGES 「F-45」.

## 0. 사실

- 입고(GR) API 는 `GET /api/purchase/gr/load`(불러오기 후보)·`POST /api/purchase/gr`(만들기)·`POST /api/purchase/gr/{num}/void`(취소) 셋뿐. **목록·상세 조회 API 가 없다** → 전표 탭을 만들려면 조회 창구 2개가 필요하다.
- 본보기: `src/handlers/purchase_po_list.php`(q·page·status·sent·branch·vendor·from·to, `purchaseScopeWhere` 격리, 20건 페이지) · `purchase_po_get.php`(doc·lines·totals·can_write). `p_docs.doc_type='gr'`, 줄은 `p_doc_lines`(src_line_id 로 발주서 줄을 가리킴), 재고 반영은 `p_stock_moves`(append-only).
- 현황 탭 = 미입고 현황. `GET /api/purchase/backorder`(재고·미입고 화면의 미입고 탭)를 **그대로** 쓴다 — 새 API 없음. 화면 코드도 `purchase_stock.js` 의 미입고 렌더러를 공통 헬퍼로 빼서 두 화면이 같은 함수를 부른다(25단계 「사이클 표 한 벌」과 같은 원칙).
- 25단계 공통 규범: `.tabs/.tab`·`.panel__head--row`·`.table-foot`·`.empty`·`badge`·`mono/num`·`PurchaseUI` 헬퍼. 탭 초기화는 `PurchaseUI.initTabs`.

```
목표        : 입고 화면(/purchase/receipt)이 발주요청·발주서와 같은 「입력 · 전표 · 현황」 3탭이 된다. 입력 탭 = 지금 화면 그대로(불러오기 카드 + 입고 줄 카드). 전표 탭 = 입고 전표 목록(기간·거래처·검색·페이지)과 상세(줄·로트·유효기간·출처 발주서·취소 버튼). 현황 탭 = 미입고 현황(기존 API·기존 렌더러 재사용).
요구사항    : PRD F-34(입고)·F-45 · S-01(purchaseScopeWhere)·S-03 · 12.2 T-57 ; ARCH 5.18(구매 화면 공통 규칙·전표 API 모양)·4.0·7.4 ; SECURITY R-65(지점 격리)·R-66(금액은 서버) ;
              본보기 코드 purchase_po_list.php·purchase_po_get.php·purchase_po.php(전표 탭 마크업)·purchase_docs.js(전표 탭 렌더)·purchase_stock.js(미입고 렌더러) · 시안 GR.dc.html(전표·현황 탭 내용).
수정 파일   :
  [새로 만듦]
    src/handlers/purchase_gr_list.php   — GET /api/purchase/gr  ?q&page&vendor&branch&from&to&status(''|done|void)  정책 purchase.read. po_list 와 같은 모양 {docs:[{id, doc_no, doc_date, vendor_name, location_name, manager_name, line_count, qty_total, status, voided_at}], total, page}. `purchaseScopeWhere` 필수. 정렬 doc_date desc, id desc.
    src/handlers/purchase_gr_get.php    — GET /api/purchase/gr/{num}  정책 purchase.read. {doc:{…po_get 과 같은 머리 키…, memo}, lines:[{line_no, item_code, item_name, spec, unit, qty, lot, expiry, src_doc_no(발주서 번호), src_line_no, memo}], totals:{qty}, can_void:bool(purchase.stock.write 이고 status!='void' 이고 마감 달 아님)}. 남의 지점 전표 → 404(존재를 알리지 않음, po_get 과 같음).
    tests/purchase_f45.sh                — T-57 (a)~(f). R-73 규칙(관문·`S45-` 접두·qa created_by 정리).
  [고침]
    public/index.php                     — 라우트 2줄(GET /api/purchase/gr · GET /api/purchase/gr/{num}, purchase.read). **핸들러 파일을 먼저 만든다.** `{num}` 라우트가 기존 `POST /api/purchase/gr/{num}/void` 와 겹치지 않는지 확인.
    src/views/purchase_receipt.php       — `.tabs`(입력·전표·현황) 추가. 입력 탭 = 기존 두 카드 그대로(id 유지). 전표 탭 = po.php 의 전표 탭 마크업을 본떠 필터 한 줄(검색·거래처·기간·상태)·표(일자·번호 mono·거래처·장소·담당자·품목 수·수량·상태 badge)·`.table-foot` 페이징·상세 패널(줄 표: # 품목 규격 수량 로트 유효기간 출처 발주서(mono) 비고 · 머리 · 「취소」 danger 버튼(사유 모달 — 26단계 reason-dialog 형식 재사용)). 현황 탭 = 미입고 표 자리(`<div id="gr-backorder">` + 필터 한 줄: 거래처·납기 지남만·다시 보기).
    public/assets/purchase_stock.js      — 미입고 렌더러(필터 읽기·표·KPI·그룹 접기)를 `PurchaseUI.renderBackorder(rootEl, opts)` 로 뺀다(purchase_common.js 로 옮기거나 stock.js 가 노출) — 재고 화면의 미입고 탭도 그 함수를 부른다(동작 변화 0).
    public/assets/purchase_stock.js 또는 새 파일 없이 purchase_stock.js 안 — 입고 화면 로직: 전표 탭(목록·상세·취소 = 기존 `POST /api/purchase/gr/{num}/void` 호출)·현황 탭(renderBackorder). 사이클 표처럼 **한 벌**.
    src/handlers/purchase_receipt_page.php — 변경 없음(제목 「입고 입력」 → 「입고」로만).
    src/help/screens.php                 — purchase-receipt 도움말에 「전표」「현황」 절 두 줄.
    PRD.md(F-45 · T-57 · 8장 API 2행) · ARCHITECTURE.md 5.18 표에 gr 목록·상세 행 · STAGES.md 「F-45」 개발 사항.
금지 패턴   : 새 SQL 에 문자열 결합 · purchaseScopeWhere 없는 p_ 조회(린트 (a)) · 금액·단가를 요청에서 받기 · 남의 지점 전표를 403 으로(존재 노출) — 404 · innerHTML · 인라인 style · 화면별 CSS 신설 · 미입고 렌더러 복제(반드시 한 벌) · 취소를 새 API 로(기존 void 사용) · 시드 계정 · 운영 표 DELETE.
             라이브 서버 규칙: 핸들러 먼저 → 라우트 · index.php 저장 직후 php -l + /login + 로그인 요청 1회.
완료 기준   : tests/purchase_f45.sh —
             (a) qa 픽스처(S45- 거래처·품목·발주요청→발주서 보냄→입고 2건, 지점 A·B) 뒤 GET /api/purchase/gr → 목록 2, 필드 전부, 페이지 20 · from/to·vendor·q 필터 동작
             (b) GET /api/purchase/gr/{id} → doc·lines(로트·유효기간·src_doc_no)·totals·can_void ; 지점 B 전표를 requester(지점 A)로 → 404 ; 목록도 지점 A 만
             (c) 취소: 화면과 같은 호출(`POST …/void` 사유 포함) → 목록 status=void, 상세 can_void=false, 원장 반대 부호 행(기존 21단계 규칙 회귀)
             (d) 현황 탭: 입고 화면의 `/api/purchase/backorder` 호출이 재고 화면과 **같은 파라미터·같은 응답** — 렌더러 함수가 하나임을 정적 grep(renderBackorder 정의 1 · 호출 2)
             (e) 화면: `scripts/ui_snapshot.sh` 1440 에서 /purchase/receipt 3탭 모두 결함 0 · 스크린샷 3장(입력·전표·현황) 눈 확인 · 재고 화면 미입고 탭 결함 0(회귀)
             (f) 회귀: tests/purchase_stage21.sh(입고·원장) · purchase_stage25.sh (a) · help_button.sh · 린트 0 · php -l
보고 형식   : 변경 파일 / 실행 명령·출력 원문 / 스크린샷 / 못 한 것 / ARCH 13장 체크리스트.
```
