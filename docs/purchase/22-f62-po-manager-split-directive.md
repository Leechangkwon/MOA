# F-62 구현 지시서 — 발주서 분할에 담당자 축 · 담당자는 발주요청서에서 온다

> 근거: PRD F-62 · 12.2 T-74 · F-60 · F-61 · S-01 / ARCHITECTURE 5.18 「발주서 담당자·3축 분할」·
> 「F-62·F-63 창구 변화」 / SECURITY R-65 · R-73 · **R-92** / 시안 ⑤⑥.
> 설계: Opus 5(Fable 한도 초과로 사용자가 지시, 2026-09-17). 사용자 결정 2026-09-17 — 담당자 값은
> **요청서에서 물려받고**, 머리 담당자 칸은 **이번에 함께 만든다**.

## 진단 (구현 전에 알고 있어야 하는 현재 상태)

| 무엇 | 현재 |
|---|---|
| 묶음 열쇠 | `$key = vendor_id . ':' . location_id` — 두 축 |
| 발주서 담당자 | `$managerId = $mgrReq ?? (int) $user['id']` — 머리 명시 없으면 **로그인한 구매실 사용자** |
| 출처 줄 조회 | `$sqlSrcHead` 가 `d.vendor_id`·`d.to_location_id` 는 읽고 **`d.manager_id` 는 읽지 않는다** |
| 화면 머리 | 일자 · 입고 장소 · 거래처 · 참조·비고 — **담당자 칸이 없다** |
| 사람 목록 창구 | 구매 모듈에 **없다**(발주요청 화면의 담당자 칸은 `/api/me` 로 자기 이름만) |

## 안전 근거 (실측 — 추정 아님)

`manager_id` 는 **권한 판단에 한 곳도 쓰이지 않는다.** 그것을 읽는 필터는 둘이고 **둘 다 발주요청으로 좁혀져 있다**:

| 쓰는 곳 | 무엇에 | 영향 |
|---|---|---|
| `Authz::purchaseScopeWhere` | — | 쓰지 않는다. 지점 격리는 `branch_id` 로만 |
| `purchase_pr_list.php` | `?mine=1` | 같은 조회에 `d.doc_type = :type`(`pr`) 가 있다 |
| `Recommend::topItems` | 「내 담당」 추천 | `SQL_HEAD` 에 `WHERE d.doc_type = 'pr'` |
| `purchase_po_list.php` | 표시만 | `mine` 필터 없음 |

그래서 발주서의 담당자를 요청자로 바꿔도 **누가 무엇을 보는지는 바뀌지 않는다.**

```
목표        : 발주서 분할 열쇠에 담당자 축을 더하고, 발주서의 담당자를 발주요청 전표에서 물려받는다.
              그리고 발주서 머리에 담당자를 고르는 칸을 만든다.

요구사항    : PRD F-62 · 12.2 T-74 · S-01, ARCHITECTURE 5.18 두 행 + 창구 표, SECURITY R-65 · R-73 · R-92.
              위 절을 읽고 시작할 것. F-60·F-61 이 만든 코드 구조를 그대로 쓴다.

수정 파일   : src/handlers/purchase_po_create.php
              src/handlers/purchase_managers_list.php   (새 파일)
              public/index.php                          (라우트 표 한 줄)
              src/views/purchase_po.php
              public/assets/purchase_docs.js
              tests/purchase_f62.sh                     (새 파일)
              — 여기 없는 파일은 만들거나 고치지 않는다.
              특히 Doc.php · Authz.php · Audit.php · db/ · *.css 는 한 글자도 고치지 않는다.

해야 할 것  : ① $sqlSrcHead 에 `d.manager_id AS doc_manager_id`
              ② 요청 줄 준비부 — 'manager_id' => $mgrReq ?? (int) $row['doc_manager_id']
              ③ 직접 줄 준비부 — 'manager_id' => $mgrReq ?? (int) $user['id']
              ④ $split !== true 이면 묶음 만들기 직전에 전 줄의 manager_id 를 기존 $managerId 로 덮어쓴다
                 (옛 계약 보존 — 담당자는 머리 ?? 로그인 사용자 하나뿐)
              ⑤ 열쇠를 vendor_id : location_id : manager_id 로, 묶음에 manager_id 를 담는다
              ⑥ 묶음마다 Doc::managerOk (실패 → rollback + $reject('bad_reference')),
                 insertDoc 의 manager_id 를 묶음 값으로, 응답 docs[] 에 manager_id 추가(이름은 넣지 않는다)
              ⑦ 새 창구 GET /api/purchase/managers — 정책 purchase.buy.write,
                 SQL 은 Doc::managerOk 와 같은 정의(is_active AND purchase_role IS NOT NULL),
                 응답 {"items":[{"id","name"}]} 뿐, 지점 격리는 걸지 않고 lint:no-branch-scope 표식으로 이유를 적는다,
                 감사 없음
              ⑧ 화면: 머리에 「담당자」 select(#po-manager, 빈 라벨 「— 줄에서 자동 —」),
                 입고 장소 도움말을 「거래처 × 입고 장소 × 담당자로 나뉩니다」로,
                 lineManager() (lineLocation 과 같은 모양), pickedFrom 에 manager_id,
                 직접 줄은 /api/me 의 id·이름, renderSplitPreview 3축 + **갈리는 축만** 문구,
                 payload 에 manager_id(비었으면 키 자체를 싣지 않는다)
              ⑨ tests/purchase_f62.sh — T-74 (a)~(k)

금지 패턴   : SQL 문자열 결합, innerHTML, 사용자 입력이 들어간 파일 경로,
              curl_exec/file_get_contents('http…'), 역할 판단을 Authz 밖에서 하는 코드,
              요청 파라미터의 role 값 사용, purchase_role **값** 비교,
              새 감사 문장 · 새 감사 detail 키 · 새 사유 코드 · 스키마 변경 · 새 CSS 클래스,
              split 없는 요청의 응답·상태·감사 변경,
              Doc.php 에 함수 추가(응답에 담당자 이름을 넣으려 하지 말 것),
              담은 줄 표에 담당자 열 추가(요청 줄 담당자는 이미 「출처」 칸 작은 글씨에 있다)

완료 기준   : PRD 12.2 T-74 (a)~(k) 전부 실행. 회귀 purchase_f61.sh · purchase_f60.sh 통과.
              scripts/security_lint.sh 0 건. php -l.

보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유 / 지시서와 다르게 판단한 것
```

## 미리보기 문구 (회귀가 글자를 본다)

| 갈리는 축 | 문구 |
|---|---|
| 거래처만 | `저장하면 거래처별 N장: A(2줄) · B(1줄)` — **F-60 문장 그대로**(`purchase_f60.sh` 가 본다) |
| 거래처 + 장소 | `저장하면 거래처×입고장소별 N장: A·중앙창고(2줄) · …` — **F-61 그대로**(`purchase_f61.sh`) |
| 거래처 + 담당자 | `저장하면 거래처×담당자별 N장: A·이재순(2줄) · …` |
| 셋 다 | `저장하면 거래처×입고장소×담당자별 N장: A·중앙창고·이재순(2줄) · …` |
| 묶음 하나 | 지금의 `A · 1장` 모양 유지 — 갈리는 축이 없으니 축 이름을 붙이지 않는다 |

## 조정자(Opus 5)가 지시서 범위 밖에서 고친 것 (2026-09-17, 기록)

독립 검토가 F-62 로 낡아진 시험 문장을 찾아냈고, 구현자의 수정 파일 목록에 없는 두 파일을 조정자가 고쳤다.

| 파일 | 무엇 | 왜 |
|---|---|---|
| `tests/purchase_f61.sh` | 미리보기 단언을 3축 문구로 · 「섞기」 장면의 발주요청을 `qa_manager01` 이 만들게(+`branch_id`) | F-62 가 의도한 새 거동이라 고칠 곳이 시험의 기대값이다. 「섞기」 장면은 **요청 줄과 직접 줄이 한 장에 들어간다**는 성질을 계속 검증해야 하므로 담당자를 맞췄다 |
| `PRD.md` T-73 | 「같은 (거래처,장소) 면 한 장」 → 담당자 축을 더한 문구 | 한 문서에 두 규칙이 남으면 다음 사람이 어느 쪽을 믿을지 모른다 |
| `tests/purchase_f62.sh` | 정리에서 `is_active` 원복 · `split_count` 부재 단언을 T0·qa 로 좁힘 | 검토 지적 ②4·②6 |
| `SECURITY.md` R-78 | 잔여 한 문장(`p_doc_seq` 되돌리기가 `doc_date` 기준이라 사고 형태가 남아 있다) | 검토 지적 ②5 — F-62 가 만든 것이 아니므로 별건으로 남긴다 |

**아직 안 고친 것**: 미리보기 장 수 어긋남(`managerKey` 를 이름 우선으로 — 검토 지적 ②2). F-63 이 `purchase_docs.js` 를 고치는 중이라 그 뒤에 적용한다.
