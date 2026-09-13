# 22단계 구현 지시서 (Fable → Opus) — 주간 사용량 · 거래처 월 대사 · 월 마감 · 기공 명세서 자동 전표 · 채번 통합

작성 2026-09-13. 근거: PRD F-34(주간 사용량)·F-35·Q-18(월 마감 규칙: ARCH 5.18 「마감」), SECURITY R-66·R-69, STAGES 20·21단계 학습 기록(Doc.php 채번 통합·ARCH 7.4 표·재인증 키), 시안 Weekly·Recon·Docs.

```
목표        : 대/중/소 분류×지점의 주간 사용량 표를 내고, 거래처 명세서(엑셀·PDF 텍스트)와 우리 입고를 월 단위로 대사해 차이 4종을 자동 분류하며,
              월 마감으로 그 달의 전표를 잠근다(수정·취소는 purchase.close + 사유 + 감사). 기공 명세서는 재고관리 안 하는 품목의 입고=사용 전표로 자동 생성한다.
              20·21단계에 흩어진 채번·전표 INSERT 를 Doc.php 한 곳으로 합친다.
요구사항    : PRD F-34·F-35·T-47(신설: 아래 완료 기준을 PRD 12.2 에 T-47 로 등록), ARCH 5.18(마감·가져오기·감사), R-69(명세서 개인정보 최소화), docs/purchase/03(월 대사·Preview 텍스트 추출 재사용), 시안 Weekly·Recon — 읽고 시작.
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-14_purchase_recon.sql — p_recon_statements(id, vendor_id, month char(7) 'YYYY-MM', file_id→files, mapping_json, total_amount numeric NULL, status CHECK(open|closed), created_by, created_at, UNIQUE(vendor_id, month, file_id))
                                                p_recon_lines(id, statement_id, row_no, item_code text NULL, item_name text, qty numeric, unit_price numeric NULL, amount numeric NULL, matched_line_id NULL→p_doc_lines, diff_type CHECK(none|qty|price|missing_ours|missing_theirs) NULL, note)
                                                p_month_close(branch_id NULL(전사=NULL), month char(7), closed_at, closed_by, reason_text NULL, UNIQUE(branch_id, month))
                                                p_docs.closed_month 는 20단계에 있음 — 마감 시 그 달 전표에 채움(UPDATE, 앱 권한 안).
                                                GRANT: 앱 SELECT·INSERT·UPDATE(DELETE 없음), tbox_backup SELECT.
    src/purchase/Recon.php   — 명세서 행 ↔ 우리 GR 줄 대조(거래처·월·품목 코드 우선, 없으면 이름 정규화 일치·수량·단가), 차이 4종 분류, 합계
    src/purchase/Usage.php   — 주간 사용량(IS 원장 − 행 + 재고관리 안 하는 품목의 GR + 행)을 대/중/소 × 지점 × ISO 주로 집계(SQL 한 번, purchaseScopeWhere)
    src/handlers/purchase_weekly.php(GET /api/purchase/usage/weekly?from=&to=&branch=&cat1=&cat2=&group=cat1|cat2|cat3|item) · purchase_weekly_page.php · views/purchase_weekly.php
    src/handlers/purchase_recon_upload.php(POST /api/purchase/recon — vendor·month·파일: 기존 Upload::validate → files(폴더 「구매/명세서/거래처/월」 admin-only, 없으면 생성) → 표 추출(Sheet.php 재사용, PDF 는 Preview::text 재사용) → 열 매핑 후보)
    src/handlers/purchase_recon_preview.php(매핑·대조 결과) · purchase_recon_commit.php(p_recon_* 저장, 한 트랜잭션) · purchase_recon_list.php · purchase_recon_get.php · purchase_recon_page.php · views/purchase_recon.php
    src/handlers/purchase_close_create.php(POST /api/purchase/close — branch|전사, month, 재인증+TOTP 정책 purchase.close: 그 달 미확정 전표가 있으면 400 open_docs_exist, 아니면 p_month_close INSERT + p_docs.closed_month UPDATE) · purchase_close_list.php
    src/handlers/purchase_lab_create.php(POST /api/purchase/lab — 기공 명세서: 거래처·월·품목(재고관리 안 하는 것만)·수량·금액 → GR 전표 자동 생성(Stock.php 의 재고관리 안 하는 품목 규칙으로 +·− 두 행) + 대사 표에 연결)
    public/assets/purchase_recon.js · purchase_recon.css (주간·대사·마감 화면. 다른 JS 파일은 손대지 않는다)
    tests/purchase_stage22.sh — 관문·정리·S22- 접두(R-73)
  [고침 — 범위만]
    src/purchase/Doc.php    — 채번 접두 표에 gr|mv|is|adj 추가, insertDoc/insertLine 에 21단계 칸(from_location_id·count_cycle·lot_no·expiry_date·counted_qty) 허용 → src/purchase/Stock.php 의 자체 채번·INSERT 를 Doc.php 호출로 바꾸고 중복 SQL 삭제(동작 동일, tests/purchase_stage21.sh 로 회귀)
    src/purchase/Stock.php  — 위 통합 + 마감 검사: 전표의 doc_date 가 마감된 달이면 confirm/void 에서 400 month_closed (purchase.close 보유자가 reason_text 를 주면 통과 + 감사 detail.after_close)
    src/handlers/purchase_pr_update.php · purchase_po_update.php · purchase_po_void.php · purchase_gr_void.php · purchase_mv_void.php · purchase_is_void.php · purchase_adj_void.php — 마감 검사 호출 한 줄씩(Stock/Doc 공통 함수)
    public/index.php(라우트·REAUTH 키 purchaseclose·purchaserecon — app.js 쌍둥이 표도 같은 키 추가만), public/assets/app.js(REAUTH_PATHS 항목 추가만), src/Authz.php(purchase.close 는 있음 — 필요 시 별칭만), src/Audit.php(문장 틀 purchase.recon.{upload|preview|commit}·purchase.close.{create}·purchase.lab.{create}·deny month_closed·open_docs_exist, detail 키 month·vendor_id·diff_counts·statement_id·after_close), scripts/security_lint.sh(필요 시), scripts/alerts.php(AL-16 단가 급변: 대사에서 price 차이 ±20% 이상 — 골격), ARCHITECTURE.md 7.4 표에 purchase.* 문장 행 요약 추가(19~22단계 몫 한 번에), README, STAGES(22단계 절·체크), db/grants.sql·db/schema.sql, PRD.md 12.2 에 T-47 행 추가만
금지 패턴   : SQL 결합, innerHTML, 클라이언트 금액 신뢰(명세서 금액은 파일 값이지만 「우리 금액」은 서버 계산), Authz 밖 판정, p_* DELETE, 원장 INSERT 를 Stock.php 밖에서, 명세서의 연락처·계좌 열 저장(R-69: 품목·수량·단가·금액 열만 p_recon_lines 에, 원본 파일은 admin-only 폴더), 새 라이브러리(PDF 는 Preview::text 의 텍스트만), 사용자 활동 중 시험, 자격증명 cat.
완료 기준   : T-47 — (a) 주간 사용량: IS 3건·GR(비재고) 1건 → 대분류×지점×주 표 합이 원장과 일치, requester 는 자기 지점 열만 ; (b) 대사: 명세서 xlsx 5행(일치 2·수량 차이 1·단가 차이 1·우리에게 없음 1) + 우리 GR 에만 있는 1 → diff_type 5종 분포 정확, 합계·차액, 확정 한 트랜잭션, 연락처 열은 저장 안 됨(p_recon_lines 컬럼에 없음) ; (c) 마감: 미확정 전표 있으면 400 open_docs_exist, 없으면 마감 → 그 달 전표 취소 시 400 month_closed, buyer 가 reason_text 로 취소 → 200 + 감사 after_close + AL-14/16 dry-run ; 마감은 재인증+TOTP 없이 403 next:reauth ; (d) 기공 명세서 → GR 전표 + 원장 +·− 두 행, 대사 표에 연결 ; (e) 회귀 tests/purchase_stage21.sh(채번 통합 뒤)·stage20.sh 통과 ; 린트 0 ; \dp 새 표 arw·원장 ar 유지.
보고 형식   : 변경 파일 / 명령·출력 원문 / 못 한 것 / 13장 체크리스트 / 사용자가 볼 것 3줄. 커밋 금지.
```

## 보충 규칙
1. **대조 우선순위**: 명세서 품목 코드 = p_items.code(같은 거래처) → 이름 정규화(공백·괄호·대소문자 제거) 일치 → 그 뒤 수량·단가 비교. 여러 GR 줄이 후보면 같은 달 안에서 수량 합으로 대조하고 `note` 에 「n줄 합」.
2. **차이 4종 + none**: `qty`(수량 다름) · `price`(단가 다름, ±1원 초과) · `missing_ours`(명세서엔 있고 우리 입고 없음) · `missing_theirs`(우리 입고는 있고 명세서에 없음 — 우리 GR 줄 기준 행을 p_recon_lines 에 추가) · `none`.
3. **월 마감 대상**: 그 달 doc_date 의 pr·po·gr·mv·is·adj 전부. 지점 마감(branch_id)과 전사 마감(NULL) 둘 다 지원, 전사가 있으면 지점 것도 잠김.
4. **기공 명세서**: 거래처 종류 구분 없이 「재고관리 안 하는 품목」만 담을 수 있고, 재고관리 품목이 섞이면 400 `stock_managed_item`. 생성되는 GR 은 src_line 없음(발주서 없이 입고), 감사 문장은 purchase.lab.create.
5. **주간 표**: 열 = ISO 주(월요일 시작), 행 = 선택한 group 층, 값 = 수량(단위 낱개). 응답 상한 주 26·행 500.
6. **채번 통합**은 동작 무변경이 목표 — tests/purchase_stage21.sh 를 통합 전·후 모두 돌려 같은 결과(105/105)여야 한다.
7. 시험 안전 R-73 규칙 그대로(관문·created_by 정리·인구조사·S22- 접두).
