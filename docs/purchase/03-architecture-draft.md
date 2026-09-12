# 구매·재고 모듈 — 3. 아키텍처 초안 (2026-09-13)

> 상태: **초안**. PRD 초안(02)이 확정되면 `ARCHITECTURE.md` 5.18~5.2x · 6.2-5~ · 7.4 · 10장 · 11.4 로 옮긴다. t-box 본체의 규칙(4장 공통 관문 · 4.1 Authz 한 곳 · 7장 감사 · 13장 체크리스트)을 **그대로 상속**하고, 이 문서는 모듈이 더하는 것만 적는다.

## 1. 모듈 경계 — 같은 저장소, 다른 접두

```
public/index.php  라우트 표에 /purchase/* (화면) · /api/purchase/* (API) 추가 — 정책 이름 접두 purchase.*
src/purchase/     Authz 확장(PurchaseAuthz) · Ledger(원장) · DocNo(채번) · Lineage(연결) · Recommend · ExcelMap
src/handlers/purchase_*.php     핸들러(전표 5종 × 입력/목록/상세, 기초정보, 현황)
db/migrations/2026-09-xx_purchase_*.sql   표는 접두 p_ (p_items, p_docs …) — 본체 표와 이름이 섞이지 않게
public/assets/purchase.js · purchase.css  화면(t-box 셸 안, 토큰 재사용, innerHTML 0)
```
재사용하는 본체 부품: 로그인·세션·CSRF(4.0), 재인증 관문(F-23 — 기초정보 편집·월 마감·매트릭스는 재인증 대상), 감사 `Audit`(문장 틀에 `purchase.*` 행 추가), 관리자 영역 허브(카드 「구매 관리」), 파일 업로드 검사 `Upload`·본문 추출 `Preview`(엑셀·명세서), 폴더(명세서 보관 폴더 `구매/명세서/거래처/월`), 경보 `alerts.php`.

## 2. 권한 — 두 축: 모듈 역할 × 지점

- `users.branch_id`(NULL 허용 — 구매팀·경영진은 지점 없음) + `users.purchase_role`(`requester`·`buyer`·`stock`·`viewer`·NULL). Admin 은 모두 가능.
- **판정은 `Authz` 한 곳**(본체 규칙): `Authz::purchaseAuthorize(policy, user)` 와 `Authz::purchaseScopeWhere(user, alias)` 를 추가한다. 라우트 정책 예: `purchase.request.write`(requester·buyer·Admin) · `purchase.buy.write`(buyer·Admin) · `purchase.stock.write`(stock·buyer·Admin) · `purchase.read`(모듈 역할 있는 모두) · `purchase.master.write`(buyer·Admin, 재인증) · `purchase.close`(buyer·Admin, 재인증 + TOTP).
- **지점 격리 = SQL WHERE**(S-02 방식): requester·stock 은 `d.branch_id = :my_branch`, buyer·viewer·Admin 은 조건 없음. 목록·현황·검색·불러오기·추천 **모든 조회**가 이 절을 거친다(린트: 모듈 핸들러의 SELECT 는 `purchaseScopeWhere` 호출이 있어야 함 — `security_lint.sh` 규칙 추가).
- 전표 행 단위 판정: 자기 지점 요청만 수정, 담기 전(`status=unconfirmed`)까지만 요청자가 수정, 그 뒤는 구매팀. 발주서·입고는 buyer 만. 수정·취소는 사유 필수(본체 계정 관리와 같은 `reason_text`).

## 3. 데이터 — 표와 불변 규칙

```
p_brands · p_branches(brand_id, warehouse_location_id, central_location_id) · p_locations(branch_id, kind, name, legacy_name)
p_vendors · p_categories(parent_id, level) · p_items(vendor_id, code, … stock_managed, safety_qty, lead_days, min_order_qty, price_in)
p_item_branches · p_par_levels(location_id, item_id, qty, effective_from)
p_docs(doc_type, doc_no, doc_date, branch_id, vendor_id, from_location_id, to_location_id, manager_id, status, memo, sent_at, closed_month, created_by)
p_doc_lines(doc_id, line_no, item_id, qty, unit_price, amount, remark, src_line_id → p_doc_lines)
p_stock_moves(doc_line_id, location_id, item_id, qty_delta, moved_at)          -- append-only
p_lists(owner_id, branch_id, name, shared bool) · p_list_items(list_id, item_id, default_qty, sort)
p_recon_statements(vendor_id, month, file_id → files, mapping_json, total) · p_recon_lines
p_import_mappings(kind items|vendors|locations|statement, vendor_id NULL, name, columns_json)
```
불변 규칙(앱 + DB 제약):
1. **원장은 추가만** — `p_stock_moves` 에 `tbox_app` INSERT·SELECT 만(UPDATE·DELETE 없음, 감사 로그와 같은 급). 취소·정정은 **반대 부호 행** + 원본 참조(`reverses_move_id`).
2. **현재고는 컬럼이 아니라 합** — `SUM(qty_delta) WHERE location_id, item_id`. 화면 성능은 `p_stock_balance` **뷰(또는 일별 스냅샷 표)** 로, 진실은 원장.
3. **음수 재고 금지** — 이동·사용 확정 시 트랜잭션 안에서 `SELECT … FOR UPDATE` 후 합을 다시 계산해 0 미만이면 거부(공유 링크 횟수와 같은 조건부 방식).
4. **연결은 `src_line_id` 하나** — 발주서 줄 → 요청 줄, 입고 줄 → 발주서 줄. 미발주 = `pr.qty − Σ po.qty(src=pr)`, 미입고 = `po.qty − Σ gr.qty(src=po)`. 담기·입고 확정 때 **잔량 초과 거부**(FOR UPDATE).
5. **채번** — `doc_no = 접두-YYYYMMDD-N`, `p_doc_seq(doc_type, date, last)` 행을 잠가 증가(중복 UNIQUE).
6. **월 마감** — `closed_month` 가 있는 달의 전표 수정·취소는 `purchase.close` 권한 + 사유 + 감사, 마감 자체는 재인증 + TOTP.
7. **재고관리 안 하는 품목**(기공료 등) — 입고 확정 시 `+`·`−` 두 행을 같은 트랜잭션에 넣어 순변화 0, 사용 통계에는 잡힘.

## 4. 흐름 — 요청 처리 순서 (본체 4장 [0]~[5] 그대로 + 모듈 단계)

```
[4] Authz::authorize('purchase.request.write') → [5] 핸들러
    ① 허용 키 화이트리스트(R-07) ② 지점 격리(요청 branch = 내 branch, buyer 는 자유) ③ 참조 존재 검사(품목·장소·거래처는 활성인가)
    ④ 트랜잭션: 채번 → p_docs → p_doc_lines (→ 원장 행: 입고/이동/사용/실사만) → 감사 → COMMIT
    ⑤ 응답은 전표 번호·연결 요약. 값은 서버 계산(금액 = 수량×단가, 클라이언트 값 무시)
```
- **품목 찾기 API** `GET /api/purchase/items?q&brand&cat1&cat2&vendor&branch_used&low_stock&recent&page` — 조건은 전부 바인딩, 정렬은 허용 목록, `LIMIT 20`, `total` 은 별도 count(1.35만 건 — `pg_trgm` 없이 `ILIKE` + 코드·이름 인덱스, 응답 200ms 목표 실측 T). 지점 격리는 「그 지점에서 쓰는 품목」 필터로(품목 자체는 브랜드 범위 공개).
- **추천** — `p_doc_lines JOIN p_docs WHERE manager_id = :me AND doc_type='pr' AND doc_date > now()-90d GROUP BY item_id ORDER BY count DESC LIMIT 50`. 요청 시 계산(캐시 없음, 1.35만 건이라도 담당자 행은 적음).
- **불러오기** — `GET /api/purchase/requests/open?group=vendor,location,manager&...` : 잔량 > 0 인 요청 줄을 지점 격리 뒤 돌려주고, 묶기는 **클라이언트**가 순서대로 한다(서버는 평평한 줄 + 키). 담기 = `POST /api/purchase/orders {lines:[{src_line_id, qty}]}` → 잔량 검사.
- **사이클 추적** — 요청 줄 기준 재귀 CTE(`src_line_id` 를 따라 PO·GR 합) 한 문장. 미입고 현황은 같은 CTE 를 PO 줄 기준으로.
- **엑셀 가져오기** — t-box 업로드 검사 → `Preview::table`(xlsx/csv 파서 재사용, 상한 상수 상향은 별도 결정) → 매핑 → 행별 판정(`insert`·`update`·`review`·`error`) → 확정은 **한 트랜잭션**, 실패 시 전부 롤백. 파일은 `files` 에 남기고(폴더 `구매/가져오기`) 결과 감사에 파일 sha256·행 수.
- **명세서 대사** — 같은 파이프라인 + 거래처별 매핑 저장 + 차이 분류 4종 SQL. 이미지 PDF 는 `Preview` 가 글자를 못 뽑으면 `review` 로 사람 입력.

## 5. 감사·경보

- 문장 틀 `purchase.request|order|receipt|move|use|count|master|import|recon.close … allow/deny/error` (ARCH 7.4 에 추가). detail 표준 키: `doc_no`·`doc_type`·`branch_id`·`lines`(수)·`amount`·`src_doc_no`·`before/after`(기초정보)·`reason_text`.
- 경보 후보 AL-14 마감 뒤 수정 · AL-15 미입고 30일 · AL-16 단가 급변(최근 단가 대비 ±30%) · AL-17 대량 취소(하루 10줄 이상).

## 6. 보안 위험(SECURITY R-65~ 후보)

지점 격리 누락(린트로 강제) · 금액·수량 서버 계산 · 원장 불변(DB 권한) · 엑셀 대량 오류(미리보기·트랜잭션) · 명세서 파일의 개인정보 최소화 · 추천 리스트가 남의 주문을 드러내지 않게(내 것 또는 지점 공유만) · 품목 검색 DoS(LIMIT·인덱스·기존 nginx 속도 제한 재사용) · 재인증 대상 확대(기초정보·마감).

## 7. 단계 (PRD 5장과 같음) 와 검토 방법

19 골격+기초정보+가져오기 → 20 요청·불러오기·발주서 → 21 입고·이동·사용·실사·원장·재고현황 → 22 주간 사용량·월 대사·기공 명세서 → 23 발주 제안·미입고 경보. 단계마다 T-42~ 를 PRD 12.2 에 등록하고 Fable 이 실행 검토(지점 격리는 requester 두 지점 계정으로, 원장은 SUM 대조, 잔량은 동시 요청 2개로).
