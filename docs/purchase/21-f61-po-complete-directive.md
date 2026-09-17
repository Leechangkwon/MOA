# F-61 지시서 — 발주서 화면 완성: 「현황」 탭 · 입고 장소 자동(거래처×장소 분할) · 세 갈래 입력(직접·발주요청·부족 산출)

> Fable 작성 2026-09-17. 사용자 요청 원문은 STAGES F-61 배경. 문서가 먼저 갱신돼 있다: PRD 13장 **F-61** · 12.2 **T-73**, ARCH 5.18 **「발주서 세 갈래·장소 규칙」**. F-60 지시서(`20-f60-po-split-directive.md`)·20단계 지시서(`05-stage20-directive.md`)·시안 ⑤⑥⑩ 도 읽고 시작할 것. **F-60 이 방금 들어갔다** — 그 코드(`$buckets` 루프·`split`) 위에 쌓는다.

## 목표

발주서 화면을 요청·입고와 같은 3탭으로 완성하고, 저장이 **줄의 입고 장소**를 따르게 하며(요청서와 같은 창고), 발주서 줄의 출처를 셋(발주요청·직접 입력·부족 산출)으로 넓힌다.

## 요구사항

- PRD F-61 ①~⑤ · F-60(불변 부분) · F-36(제안 공식·API 불변) · F-34 R6 · T-73 · S-01
- ARCH 5.18 「연결」·「분할 저장」·「발주서 세 갈래·장소 규칙」 · SECURITY R-65(격리 SQL 불변) · R-66(단가·금액은 요청 본문에서 받지 않는다 — 직접 줄의 단가는 **품목에서**) · R-73(시험 정리)

## 명세

| 항목 | 내용 |
|---|---|
| 저장 `purchase_po_create.php` — 장소 | `split === true` 이면 `to_location_id` 머리값 **선택**. 줄의 장소 = 머리값이 있으면 머리값, 없으면 요청 줄은 그 요청 전표의 `to_location_id`(`$src` 조회에 열 추가), 직접 줄은 줄의 `location_id`. 정할 수 없으면 400 `bad_request`. **묶음 열쇠 = `vendor_id . ':' . location_id`**. 묶음마다 `Doc::locationOk($db, $scopeL, $locationId, $branchId)`(기존 함수 — 실패 `not_in_scope`)와 `vendorOk`. `insertDoc` 의 `to_location_id` 는 묶음의 장소. `split` 없는 요청은 **머리 필수 그대로**(옛 검증 줄 유지) |
| 저장 — 직접 줄 | `TBOX_PO_LINE_KEYS` 에 `item_id`·`location_id` 추가. 줄마다 `src_line_id` 와 `item_id` 중 **정확히 하나**(둘·둘 다 없음 → 400). 직접 줄: `Doc::loadItems($db, $scopeIb, [item_id…])` 로 활성·범위 확인(없음·비활성 → 400 `bad_request`, 범위 밖 → `not_in_scope`), 거래처 = 줄 `vendor_id` ?? 품목 `vendor_id`, `price_in` = 품목 값(요청 본문의 단가 금지 — R-66), `insertLine(... $srcLineId = null ...)`, `retouchRequestLine`·`recalcRequestStatus`·`srcDocs` 는 **요청 줄에만**. 잠금(`lockLines`)은 요청 줄에만(직접 줄은 잠글 대상이 없다). 같은 `item_id` 를 직접 줄로 두 번 → 400(요청 줄 중복 규칙과 같음) |
| 저장 — 불변 | 요청 줄의 잠금·격리·상태·마감·잔량 검사 코드 **한 글자도 변경 금지**. `split` 없는 요청의 응답·감사 불변. 감사 detail 은 F-60 그대로(`split_count`), 직접 줄만인 발주서는 `src_doc_no` 없음(이미 그렇게 동작하면 그대로) |
| 화면 — 3탭 | `purchase_po.php` 탭에 「현황」(`data-tab="status"`) + `#po-status` 패널 + `#po-backorder`. `purchase_docs.js` 는 입고 화면과 **같은** `renderBackorder(host, opts)`(`purchase_common.js`) 호출. 새 API·새 렌더러 금지 |
| 화면 — 장소 | 머리 「입고 장소」 select 첫 항목 「— 줄에서 자동 —」(값 ''), 기본값. 줄 표에 「입고 장소」 열(요청 줄은 `location_name`, 직접 줄은 고른 장소). 미리보기: 머리 비면 「저장하면 거래처×입고장소별 N장: A·중앙창고(2줄) · B·수술방(1줄)」, 머리 명시면 그 장소로 거래처별 N장. payload 에 머리 장소는 **비어 있으면 넣지 않는다** |
| 화면 — 품목 찾기 | 발주요청 화면(화면 ①)의 ItemPicker 코드를 **공통 함수로 빼서**(`purchase_common.js` 또는 `purchase_docs.js` 안 공유 함수 — 복사 금지) 발주서에서도 연다. 발주서용에는 머리 장소가 비어 있으면 **장소 select**(보이는 장소 = 머리 select 와 같은 목록)가 창 안에 있고 필수. 담긴 줄 = `{item_id, code, name, spec, unit, vendor_id, vendor_name, price_in, qty, location_id, location_name, source:'direct'}` |
| 화면 — 부족 산출 | 「부족 산출」 단추 → 창: 장소 select(필수) · 「부족만」 체크(기본 on) · 검색 → `GET /api/purchase/suggest?location=<id>&only_short=1&q=` (기존 응답: `items[]` 에 `item_id`·`code`·`name`·`par_qty`·현재고·미입고·`suggest_qty` 등 — 실제 키는 `purchase_suggest.php`/`Suggest::rows` 를 읽어 그대로 쓴다) → 표(품목·인가·현재고·미입고·제안·담을 수량(기본 제안)) + 체크 → 담기 = 직접 줄(`source:'suggest'`, 장소 = 고른 장소). 응답의 `formula` 문구를 창 아래 작은 글씨로 |
| 화면 — 출처 열 | 줄 표에 「출처」: 요청 줄 = PR 번호(+요청자, 지금 그대로), 직접 = 「직접」, 부족 산출 = 「부족 산출」 |
| 시험 `tests/purchase_f61.sh` | **새 파일**. T-73 (a)~(g). `tests/purchase_f60.sh` 구조·정리 방식 그대로(qa 계정·R-73 관문·R-78 카운터 규칙·`F60_OUT` 같은 `F61_OUT`). (d) 는 인가량(`p_par_levels`)·재고(입고 또는 실사로 5)·미입고(발주서 3)를 qa 데이터로 만들고 끝에 되돌린다 — 재고 원장은 append-only 라 반대 전표(사용/실사)로 0 으로 되돌리고 그 사실을 note |

## 수정 파일 — 허용 목록

`src/handlers/purchase_po_create.php` · `src/views/purchase_po.php` · `public/assets/purchase_docs.js` · `public/assets/purchase_common.js`(ItemPicker 공통화·필요 시) · `public/assets/purchase.css` · `tests/purchase_f61.sh`(새) · 필요 시 `src/purchase/Doc.php`(공개 함수 추가만, 보고).

**손대지 않는다**: `src/Authz.php` · `src/Db.php` · `src/purchase/Suggest.php` · `purchase_suggest*.php` · `purchase_backorder.php` · `purchase_pr_*.php` · `purchase_gr_*.php` · `db/*` · `.env` · `tests/purchase_f60.sh`·`stage20.sh`·`stage23.sh`(회귀 기준).

## 금지 패턴

- 단가·금액을 요청 본문에서 받는 것(R-66 — 직접 줄 단가는 품목에서만) · 화면이 제안 수량을 서버 공식과 다르게 계산해 **보내는** 것은 되지만(사용자가 수정 가능) 서버가 그 값을 잔량처럼 검증하는 새 코드는 만들지 않는다(직접 줄은 잔량이 없다)
- 요청 줄 경로 코드 변경 · `split` 없는 요청의 동작 변경 · 새 정책·새 API·새 감사 문장 · ItemPicker 복사(공통화만) · SQL 결합 · innerHTML · 시드 계정 · 커밋

## 완료 기준 — PRD 12.2 T-73

(a)~(g) 전부. 회귀 `tests/purchase_f60.sh` **99/0 유지**, `stage20.sh` 는 절대 채번 2건 외 통과, `stage23.sh` 제안 항목 통과, lint 0, `php -l`. Playwright 는 `tests/f60` 방식(있으면 실행, 없으면 SKIP 보고).

## 보고 형식

변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유 / 지시와 다르게 판단한 것 / `git diff --stat`
