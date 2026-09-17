# F-60 지시서 — 발주요청 불러오기: 전표 단위 담기 · 저장 시 거래처별 발주서 분할

> Fable 작성 2026-09-17. 사용자 요청(원문은 STAGES F-60 배경). 문서가 먼저 갱신돼 있다: PRD 13장 **F-60** · 12.2 **T-72**, ARCH 5.18 **「분할 저장」** 행. `docs/purchase/05-stage20-directive.md`(20단계 계약)·시안 ④ ImportModal 도 읽고 시작할 것.

## 목표

발주서 입력에서 발주요청을 **전표(묶음) 단위로 체크해 담고**, 담은 줄의 거래처가 섞여 있어도 저장 한 번에 **거래처별로 발주서 N 장**이 한 트랜잭션에서 만들어지게 한다. 지금은 `mixed_vendor` 400 이다.

## 요구사항

- PRD F-60 ①~④ · F-32(R3 트리·부분 담기 불변) · T-72 · S-01
- ARCH 5.18 「연결」·「분할 저장」·「채번」 · SECURITY R-65(지점 격리 SQL 불변)·R-73(시험 정리)
- 금지 패턴(CLAUDE.md 2장) + 구매 모듈 규칙: 판정은 `Authz::purchaseAuthorize`·`purchaseScopeWhere` 만, 새 정책 이름 없음

## 명세

| 항목 | 내용 |
|---|---|
| 불러오기 `purchase_pr_load.php` | `TBOX_LOAD_GROUPS` 에 `'doc'` 추가(여섯). 묶음 key = `doc_id`(int), label = `doc_no . ' · ' . doc_date`. 줄 필드는 이미 `doc_id`·`doc_no`·`doc_date` 를 갖고 있으니 **새 SQL 열 없음**. 정렬은 기존 `d.doc_date, d.id, l.line_no` 그대로 |
| 저장 `purchase_po_create.php` | `TBOX_PO_CREATE_KEYS` 에 `'split'`(bool, 선택). 흐름: ① 입력 검증·`lockLines`·전 줄 격리/상태/마감/잔량 검사 = **기존 코드 그대로 한 번** ② 거래처 판정도 기존(줄 `vendor_id` → 전표 거래처 → 품목 거래처) ③ 거래처가 둘 이상이고 (`split !== true` **또는** `vendor_id` 머리값 있음) → 기존 `mixed_vendor` 400 ④ `split === true` 이면 거래처별로 묶어 **묶음마다** `nextDocNo`·`insertDoc`(같은 머리: to_location·manager·doc_date·memo)·`insertLine`·`lineAmount`·`retouchRequestLine`·`recalcRequestStatus`·`saveHeader(buildHeader)` — 기존 단일 장 코드 블록을 **함수/루프로 감싸기만** 한다(복사 금지, 판정 코드 추가 0) ⑤ 감사: 발주서마다 `purchase.order.create allow` 기존 문장, detail 에 `split_count`(int, 이번 저장의 장 수) — `Audit.php` `ALLOWED_DETAIL_KEYS` 에 `split_count` 등록 + ARCH 7.5 행은 Fable 이 적었다고 보고 **키 이름만 이대로** ⑥ 한 트랜잭션·한 `commit`. 어느 묶음에서든 거부·예외 → `rollback` + 기존 `$reject` ⑦ 응답: `split === true` 이면 항상 `{"docs":[{id,doc_no,vendor_id,vendor_name,line_count,totals}],"count":N}` 201 (1장이어도). `split` 없으면 **기존 응답 그대로** |
| `Doc.php` | 무수정이 원칙. 꼭 필요하면 **공개 함수 추가만**(기존 함수 시그니처 변경 금지) 하고 보고 |
| 화면 `purchase_po.php`·`purchase_docs.js` | (1) 묶음 칩에 「전표」(`data-group="doc"`) 추가 (2) `drawTree` 의 **모든 노드 머리**에 체크박스: 체크 → 그 노드 아래 모든 잎 줄을 **잔량 그대로** `pickedLines` 에 넣고 잎 화면의 줄 체크·수량 동기화, 해제 → 제거. 하위 일부만 담겨 있으면 `indeterminate`. 노드 머리 클릭(펼치기)과 체크박스 클릭은 서로 전파되지 않게 (3) 담은 줄 표에 「거래처」 열 (4) 저장 단추 옆 **분할 미리보기** 문구: 거래처 1 → 「거래처 A · 1장」, 둘 이상 → 「저장하면 거래처별 N장: A(3줄) · B(2줄)」. 판정은 서버가 하므로 화면은 `line.vendor_id`(담을 때 이미 계산해 둔 값)로 **보여만** 준다 (5) 저장 payload 에 `split: true` 항상. 성공 시 알림 「발주서 N장을 만들었습니다: PO-…, PO-…」, 줄 비우기·전표 탭 갱신(기존) (6) 머리 거래처 `<select>`: 담은 줄의 거래처가 둘 이상이면 비우고 비활성(+도움 문구 「거래처별로 나눠 저장됩니다」), 하나면 그 값으로 미리 채움(기존 동작 유지) (7) 전부 `textContent`·`createElement`, CSS 는 `purchase.css` 최소 |
| 시험 `tests/purchase_f60.sh` | **새 파일**. T-72 (a)~(g). `tests/purchase_stage20.sh` 의 준비(qa 계정·품목·거래처 시드 방식)·정리(qa 가 만든 행만, R-73·R-78 규칙 — 카운터 DELETE 금지)·`call`·`expect` 구조 그대로. (f) 화면은 Playwright 파이썬(`tests/f51.sh` (d) 방식) — 없으면 SKIP 로 보고. 만든 요청·발주서는 정리 단계에서 취소(void) |

## 수정 파일 — 허용 목록

`src/handlers/purchase_pr_load.php` · `src/handlers/purchase_po_create.php` · `src/Audit.php`(`ALLOWED_DETAIL_KEYS` 에 `split_count` 한 줄만) · `src/views/purchase_po.php` · `public/assets/purchase_docs.js` · `public/assets/purchase.css` · `tests/purchase_f60.sh`(새) · 필요 시 `src/purchase/Doc.php`(공개 함수 추가만, 보고).

**손대지 않는다**: `src/Authz.php` · `src/Db.php` · 다른 `purchase_*` 핸들러 · `db/*` · `.env` · `tests/purchase_stage20.sh`(회귀 기준이라 기대값 변경 금지 — `split` 없는 `mixed_vendor` 는 그대로 통과해야 한다).

## 금지 패턴

- 단일 장 저장 코드를 **복사**해 두 벌 만드는 것(루프/함수로 감싼다 — 판정·감사·채번 코드는 한 곳)
- 묶음 반복부 안에서 잔량·격리·상태를 **다시** 판정하는 새 코드(전 줄 검사는 반복 전에 한 번)
- `split` 없는 요청의 응답·상태 코드·감사 변경 · 화면에서 거래처 판정을 서버와 다르게 계산해 **보내는** 것(화면은 보여만 준다, 서버가 나눈다)
- SQL 결합 · innerHTML · 시드 계정 요청 · 운영 데이터 삭제 · 커밋·`git add`

## 완료 기준 — PRD 12.2 T-72

(a) `group=doc` 노드·label·2층 (b) `split:true` 2거래처 → 201 `docs` 2·단일 거래처·`src_line_id`·잔량 0·감사 2행 `split_count=2` (c) `split` 없음 → 400 `mixed_vendor` · `split`+`vendor_id` → 400 `mixed_vendor` (d) 원자성: 잔량 초과 1줄 → 400 `remaining_exceeded`·발주서 0장 (e) 거래처 하나 `split:true` → `docs` 1 · requester 403 (f) Playwright (g) `tests/purchase_stage20.sh` 통과 · lint 0 · `php -l`.

검토 포트 `http://127.0.0.1:8082`, 검토 계정만, `-A "tbox-review/$(date +%F)"`. 시작 전 R-73 관문(최근 10분 비-qa 활동)을 지킨다.

## 보고 형식

변경 파일 목록 / 실행한 명령과 출력 원문(시험 결과·`php -l`·lint·`git diff --stat`) / 못 한 것과 이유(SKIP 명시) / 지시와 다르게 판단한 것
