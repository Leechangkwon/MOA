# 20단계 구현 지시서 (Fable → Opus) — 발주요청(PR) · 추천/내 리스트 · 불러오기→발주서(PO) · 발주서 출력·검토·보냄

작성 2026-09-13. 근거: PRD F-30·F-31·F-32·Q-18(확정), ARCH 5.18(원장·연결·채번·요청 수정 창·발주서·송부 전 검토), SECURITY R-65~R-72, STAGES 19단계 학습 기록(20단계로 미룬 것: `purchase.send`·`p_item_branches.is_active`·린트 예외 라우트 단위·경보 후보·`/purchase/*` `$pageKey`·`bad_header_row` 등), 시안 Main·ItemPicker·MyList·ImportModal·PO·Docs·Cycle(docs/purchase/design/).

```
목표        : 요청자가 품목을 찾아 발주요청 전표(PR)를 만들고, 구매실이 여러 요청의 미발주 잔량을 거래처·장소·담당자 기준으로 묶어 발주서(PO)를 만들어
              출력·검토·「보냄」까지 한다. 재고는 움직이지 않는다(입고는 21단계).
요구사항    : PRD 6장 F-30·F-31·F-32, 14장 Q-18 ①②④, ARCHITECTURE 5.18 표(연결·채번·마감·요청 수정 창·발주서 PDF·송부 전 검토), 4.0·4.1·7.4·7.5·13장 ;
              docs/purchase/02-prd-draft.md 3장 데이터 모델(docs·doc_lines) · 03-architecture-draft.md(불러오기·사이클 재귀 CTE) ; 시안 7장 — 읽고 시작.
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-14_purchase_docs.sql   — p_docs(id, doc_type CHECK pr|po, doc_no UNIQUE, doc_date, branch_id, vendor_id NULL, to_location_id NULL, manager_id, status CHECK, memo,
                                                    header_json jsonb NULL(발주서 머리, Q-18②), split_from_id NULL→p_docs, sent_at·sent_by NULL, closed_month NULL, created_by, created_at, updated_at)
                                                    p_doc_lines(id, doc_id, line_no, item_id, qty numeric(14,3) CHECK>0, unit_price numeric(14,2) NULL, amount numeric(16,2) NULL(서버 계산), remark,
                                                    src_line_id NULL→p_doc_lines, locked_at NULL, status CHECK, UNIQUE(doc_id,line_no))
                                                    p_doc_seq(doc_type, seq_date, last_no, PK(doc_type,seq_date))
                                                    p_lists(id, owner_id, branch_id NULL, name, is_shared bool, is_active) · p_list_items(list_id, item_id, default_qty, sort_no, UNIQUE(list_id,item_id))
                                                    p_item_branches.is_active bool DEFAULT true (사용 지점 빼기 = false)
                                                    인덱스: p_docs(branch_id,doc_type,status), p_doc_lines(doc_id), p_doc_lines(src_line_id), p_doc_lines(item_id)
                                                    GRANT: tbox_app SELECT·INSERT·UPDATE(DELETE 없음), tbox_backup SELECT. 상태 전이는 UPDATE 로, 취소는 status='void'.
    src/purchase/Doc.php          — 채번(p_doc_seq FOR UPDATE → PR-YYYYMMDD-N), 상태 전이 표, 잔량 계산(요청 줄 qty − Σ 자식 PO 줄 qty, 재귀 CTE 뷰 p_line_chain 또는 함수), 금액 서버 계산(qty×p_items.price_in)
    src/purchase/Recommend.php    — 담당자별 90일 빈도(p_doc_lines JOIN p_docs WHERE manager_id=본인 OR (branch_id=내 지점 AND 공유) — R-68), 상위 30
    src/handlers/purchase_pr_page.php · purchase_po_page.php · purchase_docs_page.php · purchase_po_print_page.php(인쇄용 HTML, 시안 PO + files #188 양식)
    src/handlers/purchase_pr_list.php · _get.php · _create.php · _update.php(미확인 상태 줄만, locked_at IS NULL — Q-18①) · _void.php
    src/handlers/purchase_pr_load.php  — GET /api/purchase/pr/load?group=vendor,location,manager&branch=&vendor=&from=&to=  → 미발주 잔량 줄을 group 순서의 트리로(R3)
    src/handlers/purchase_po_create.php — POST /api/purchase/po  body {lines:[{src_line_id, qty}], vendor_id, to_location_id, manager_id, memo}
                                                    → 트랜잭션: src 줄 FOR UPDATE → 잔량 초과 400 → PO INSERT → src 줄 locked_at·status 갱신 → 요청 전표 상태 재계산(일부 발주/완료)
    src/handlers/purchase_po_list.php · _get.php · _update.php(머리 header_json 수정 — 보냄 전, buyer) · _send.php(POST /api/purchase/po/{id}/send — viewer·buyer, sent_at 한 번, 재송부는 reason 필수) · _void.php
    src/handlers/purchase_lists_*.php  — 내 리스트 CRUD + 「전체 담기」 는 화면 몫(리스트 → 요청 줄 채우기)
    src/handlers/purchase_recommend.php — GET /api/purchase/recommend
    src/handlers/purchase_cycle.php     — GET /api/purchase/cycle?pr=|po=|item=  (요청 줄 기준 한 행: PR/PO 번호·누가·언제·미발주 — GR 은 21단계에 칸만)
    src/views/purchase_pr.php · purchase_po.php · purchase_docs.php · purchase_po_print.php
    public/assets/purchase_docs.js · purchase_docs.css   (purchase.js 와 **별도 파일** — 19단계 보정과 충돌 방지. 공통 함수가 필요하면 purchase_common.js 로 뽑되 purchase.js 는 고치지 않는다)
    tests/purchase_stage20.sh  — T-45 + 아래 검토 항목. **정리는 created_by IN (qa) 만, 표 전체 DELETE 금지, 시작·끝에 비-qa 행 수 출력**(R-73)
  [고침 — 범위만]
    public/index.php      — 라우트 추가(정책 필수), TBOX_REAUTH_PATHS 에 purchase_pr·purchase_po·purchase_docs 키(4회차 방식 그대로)
    src/Authz.php         — KNOWN_POLICIES 에 purchase.send 추가, purchaseAuthorize 에 send = viewer|buyer, request.write 는 requester|buyer 그대로. 그 밖 무변경
    src/Audit.php         — 문장 틀 추가: purchase.request.{create|update|void} · purchase.order.{create|update|send|void} · purchase.list.{create|update|delete} · deny 사유 remaining_exceeded·line_locked·status_invalid·already_sent·not_owner … ; detail 키 doc_no·doc_type·src_doc_no·lines·amount·sent_to·reason_text
    src/handlers/purchase_page.php — GET /purchase → 역할별 첫 화면(requester: /purchase/pr, buyer·viewer: /purchase/po, 그 밖 /purchase/masters)
    src/handlers/purchase_*_page.php — $pageKey = 'purchase' (내비 활성·data-page)
    scripts/security_lint.sh — 린트 예외를 **파일 단위 → 라우트(파일) 단위 표식 유지하되**, 새 핸들러 purchase_pr_*·purchase_po_*·purchase_cycle·purchase_recommend 는 예외 없이 purchaseScopeWhere 필수
    scripts/alerts.php — AL-14 「보냄 뒤 발주서 수정」(void/update after sent_at) 한 규칙만(경보 골격 재사용)
    README.md · STAGES.md(20단계 개발 사항 체크만)
금지 패턴   : SQL 결합, innerHTML, 클라이언트 금액·단가 신뢰(qty 만 받고 단가·금액은 서버가 p_items 에서), 역할·지점 판단을 Authz 밖에서, 요청 파라미터 branch 로 격리 우회,
             p_* DELETE, 재고 표(p_stock_moves) 생성(21단계), 외부 요청·PDF 라이브러리(S-10-C — 출력은 인쇄용 HTML + 브라우저 「PDF 로 저장」), 시드·qa 자격증명 cat,
             **사용자 활동 중 데이터 시험 실행**(최근 10분 감사 로그에 비-qa actor 가 있으면 시험을 시작하지 않고 보고에 그 사실을 적는다).
완료 기준   : T-45(PRD 12.2) — 요청 2건(요청자 둘, 다른 지점 포함) → buyer 불러오기 group=vendor,location,manager 잔량만 → 부분 담기(5 중 3) → PO 줄 src_line_id = 요청 줄·요청 잔량 2·요청 전표 상태 「일부 발주」 →
             같은 줄 다시 담기 4 → 400 remaining_exceeded → 동시 요청 2개(잔량 2 를 각 2 씩) → 한쪽 400 ; 요청자가 담긴 줄 PATCH → 400 line_locked ; 담긴 전표에 요청자가 줄 추가 → 새 PR(split_from_id) 생성 ;
             requester 가 다른 지점 PR 조회 → 0건·상세 404 ; viewer 가 PO send → 200 sent_at, 두 번째 send → 400 already_sent, reason 있으면 200 + 감사 reason_text ; requester 가 send → 403 ;
             금액 = Σ qty×price_in 서버 값(클라이언트 amount 무시) ; 채번 PR-YYYYMMDD-1,2 연속·동시 2건 UNIQUE 충돌 없음 ; 추천이 다른 사람·다른 지점 주문을 포함하지 않음(R-68) ;
             인쇄 화면 /purchase/po/{id}/print 가 머리 정보(일련번호·수신·납품창고·납기·회사 정보·담당)와 표 8열·공급가액·부가세·합계를 서버 값으로만 그림(textContent/htmlspecialchars) ;
             회귀 T-42·T-43·T-44(19단계 스크립트, 정리 규칙 확인 뒤) ; 린트 0 ; \dp p_docs p_doc_lines p_doc_seq p_lists p_list_items.
보고 형식   : 변경 파일 / 명령·출력 원문(마이그레이션·\dp·린트·T-45 curl·동시성·인쇄 화면 curl) / 못 한 것·판단 필요 / 13장 체크리스트 / 사용자가 볼 것 3줄. 커밋 금지(Fable 검토 뒤).
```

## 보충 규칙
1. **요청 수정 창(Q-18①)**: 요청 줄은 `status='unconfirmed' AND locked_at IS NULL` 일 때만 요청자(본인 또는 같은 지점 requester)가 수정·취소. buyer 가 담는 순간 그 줄 `locked_at`. 전표에 잠긴 줄이 하나라도 있으면 요청자의 「줄 추가」는 새 PR(`split_from_id` = 원 전표)로 만들어 붙이고 응답에 `new_doc_no` 를 알려 화면이 안내한다.
2. **불러오기 트리(R3)**: `group` 은 `vendor|location|manager|branch|category` 중 1~3개, 순서대로 층. 응답은 `{groups:[{key, label, children|lines}]}` 와 각 층 소계(줄 수·수량). 거래처 미지정 줄은 「미지정」 묶음 + 즉석 지정(POST 에서 vendor_id 를 줄마다 받되 서버는 PO 단위 vendor 하나만 허용 — 여러 거래처가 섞이면 400 mixed_vendor).
3. **발주서 머리(Q-18②)**: 생성 시 서버가 header_json 을 채운다(일련번호=doc_no, 수신=거래처명, 납품창고=to_location 이름, 납기=doc_date+lead_days 최대값, 회사=브랜드 열(biz_no·ceo_name·address·tel·logo_file_id), 담당=manager 표시명). buyer 가 PATCH 로 고칠 수 있는 키는 허용 목록(`receiver`,`cc`,`tel`,`delivery_location`,`due_date`,`memo`)만, 보냄 전만. 금액 세 칸은 매 응답 서버 계산.
4. **인쇄 화면**: `/purchase/po/{id}/print` 는 셸 없이 A4 세로 인쇄 CSS(`@page`), 시안 PO·캡처 양식. 로고는 `logo_file_id` 가 있으면 기존 파일 미리보기 라우트로만(권한 판정 그대로).
5. **보냄(Q-18④)**: viewer·buyer. `sent_at` 이 있으면 update/void 는 `purchase.buy.write` + reason_text 필수 + 감사, alerts AL-14.
6. **추천(R-68)**: 본인 manager_id 의 최근 90일 줄 + (is_shared 리스트와 같은 지점 공유 옵션이 켜진 경우만) 지점 줄. 다른 지점·다른 사람 기본 제외.
7. **화면**: 시안 Main(요청 입력 3탭: 입력·전표·현황)·ItemPicker(다중 선택+수량, 품목 API 재사용)·MyList·ImportModal(트리 다중 묶기·부분 담기)·PO·Docs·Cycle. purchase_docs.js 만 새로 쓰고 purchase.js 는 손대지 않는다. 시안 ⑥⑦(입고·이동)은 21단계.
8. **시험 안전(R-73)**: tests/purchase_stage20.sh 는 시작 시 `SELECT count(*) FROM audit_logs WHERE happened_at > now()-interval '10 minutes' AND actor_name NOT LIKE 'qa_%'` 가 0 이 아니면 **실행하지 않고 종료 코드 3** 으로 끝낸다. 정리는 created_by/owner_id/manager_id 가 qa 계정인 행만.
