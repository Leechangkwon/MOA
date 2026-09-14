# 19단계 보정 9회차 지시서 (Fable → Opus) — 이카운트 품목 파일(ESA009M) 자동 마이그레이션: 분류·거래처 함께 등록 · 빈 단위 허용 · 매핑+옵션 기억

작성 2026-09-14. 근거: 사용자 결정 2026-09-14 「B로 진행. 빈 단위는 빈 값 그대로(원본과 같도록, 안 되면 "-"). 앞으로 해당 양식으로 자동 마이그레이션 가능하도록」.
PRD F-29 보충·12.2 T-43 (i)~(n), ARCH 5.18 「가져오기」 행 보충, STAGES 19단계 보정 9회차.

## 0. 사실 (검토자가 실측 — 추정 아님)

사용자가 2026-09-14 17:49 에 admin01 로 올린 파일 `ESA009M (1) (1).xlsx`(files #609) 를 `Sheet::rows` 로 읽었다.

| 항목 | 값 |
|---|---|
| 머리행 | 1 (0행은 이카운트 제목) — `Importer::detectHeaderRow('items', …)` 가 이미 맞게 찾음 |
| 열 15개 | 구매처명 · 대분류 · 중분류 · 소분류 · 품목코드 · 품목명 · 규격 · 단위 · 입고단가 · 당수량(분자) · 당수량(분모) · 최초작성자 · 최종수정자 · 문자형추가항목2 · 최종수정일자 |
| 데이터 행 | 8,100 |
| 단위 빈칸 | **2,678** (채워진 것은 EA 4,130 · 「1BOX * 6EA」 같은 포장 표기 다수) |
| 거래처 빈칸 | 10 · 거래처 이름이 있으나 미등록 약 11 (디오(충남PKG)·신세기메디텍 등) — **거래처 코드 열은 없음** |
| 분류 고유 이름 | 대 12 · 중 70 · 소 75 = 157개, `p_categories` 는 **0건** |
| 저장된 매핑 | `p_import_mappings` 0건 |

현재 판정(`Importer::judgeItem`)은 ① 단위 빈칸 → `unit_missing` 오류 ② 분류 이름이 있는데 미등록 → `category_not_found` 오류 ③ 거래처 미등록 → `vendor_not_found` 오류. 그래서 8,100행 중 대부분이 오류다. 창고 가져오기에는 `create_brands`·`create_branches` 옵션이 있는데 품목에는 짝이 없다 — 이 비대칭을 없앤다.

DB 제약(실측): `p_items.unit` **NULL 허용**(마이그레이션 불필요) · `p_items.vendor_id` NOT NULL(거래처 빈칸은 등록 불가가 맞다) · `p_vendors.code` NULL 허용 · `p_categories(parent_id NULL 허용, name, level NOT NULL)`. `Doc.php:594`·`Stock.php:537`·`purchase_docs.js:300`·`purchase.js:741` 은 이미 `unit === null` 을 다룬다.

```
목표        : 이카운트 품목 내보내기 파일을 「올리기 → (자동 매핑·옵션) → 미리보기 → 확정」 두 번의 클릭으로 반복 가져올 수 있게 한다.
              빈 단위는 빈 값(NULL) 그대로 저장, 파일의 분류·미등록 거래처는 옵션으로 **같은 트랜잭션 안에서 함께 등록**, 매핑과 옵션은 저장해 다음 업로드에 자동 적용.
요구사항    : PRD F-29(가져오기)·F-28·S-01·S-03·S-04 ; 12.2 T-43 (i)~(n)(이번에 신설) ; ARCH 5.18 가져오기·감사 7.4 ; SECURITY R-65·R-72(거래처 이름 모호성)·R-73(시험 안전 규칙) ;
              docs/purchase/04-stage19-directive.md 의 가져오기 절과 보충 7 ; 기존 코드 src/purchase/Importer.php(judgeItem·commit·lookup)·src/handlers/purchase_import_{upload,preview,commit,mappings}.php ·
              public/assets/purchase.js 의 가져오기 걸음(step/renderMapping) ; tests/purchase_stage19.sh 의 create_brands/create_branches 시험(⑫·1206행 부근)을 본보기로.
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-15_purchase_import_options.sql   — p_import_mappings.options_json jsonb NOT NULL DEFAULT '{}'  (+ db/schema.sql 동기화)
    tests/purchase_import_items.sh                         — T-43 (i)~(n). R-73 안전 규칙 그대로(최근 10분 비-qa 활동 있으면 종료 3 · 픽스처 접두 `S19I-` · 정리는 qa created_by 행만 · 전체 DELETE 금지 · UA tbox-review). 픽스처 xlsx 는 스크립트가 만든다(0장의 15개 열 **그대로**, 머리행 1, 제목행 0).
  [고침]
    src/purchase/Importer.php
      · judgeItem: 단위 빈칸 → 오류 아님, `unit` NULL. (`unit_missing` 사유·상수는 지운다. 어디서도 안 나와야 한다.)
      · judgeItem: `category_not_found` 는 옵션 `create_categories` 가 참이면 오류 대신 **정상 판정 + 표시 `category_new`**(몇 단계·어느 이름·부모까지). 파일 안에서 같은 이름은 한 번만 센다(lower(name), 부모 기준). 미리보기 요약에 `new_categories: N`(+ 앞 20개 이름 목록).
      · judgeItem: `vendor_not_found` 는 이름이 **있고** 옵션 `create_vendors` 가 참이면 정상 판정 + `vendor_new`(이름). 거래처 이름이 **빈칸**이면 그대로 오류 `vendor_missing`(vendor_id NOT NULL 이니 등록할 수 없다). 요약에 `new_vendors: N`(+이름 목록). R-72: 이름이 이미 **둘 이상** 있으면 지금처럼 `vendor_ambiguous` 확인 필요 — 새로 만들지 않는다.
      · commit(items): 한 트랜잭션 안에서 **먼저** 새 분류(1→2→3단, 부모 id 연결)·새 거래처(name 만, code NULL, created_by)를 INSERT 하고 lookup 을 갱신한 뒤 품목 행을 처리한다. 결과에 `categories_created`·`vendors_created` 추가. 감사 detail 에 두 수와 이름 앞 5개(전체 이름 나열 금지 — detail 크기).
      · 옵션 배열에 `create_categories`·`create_vendors`(bool) 추가. 종류가 items 가 아닐 때는 무시.
    src/handlers/purchase_import_preview.php · purchase_import_commit.php — 허용 키에 `create_categories`·`create_vendors` 추가(bool 만, 다른 타입은 400 bad_request). 그 밖 로직 변경 금지.
    src/handlers/purchase_import_mappings.php — 저장 본문에 `options`(객체, 허용 키 create_brands·create_branches·create_categories·create_vendors·derive·default_brand… **지금 preview 가 받는 옵션 키와 같은 목록**, 값 타입 검사) 추가 → options_json 저장·응답에 포함.
    src/handlers/purchase_import_upload.php — 응답의 저장 매핑 목록에 `options` 포함. **자동 적용 후보** `match`: 저장 매핑 중 「매핑의 원본 열 이름이 모두 이 파일 머리행에 있는」 것이 있으면 그 id(둘 이상이면 원본 열 수가 가장 많은 것). 서버는 이 값을 판정에 쓰지 않는다(화면 편의).
    public/assets/purchase.js — 가져오기 2단계에 종류가 items 일 때 체크 2개(「파일의 분류를 함께 등록」·「없는 거래처를 함께 등록」, 기본 **켬**) ; 미리보기 요약에 「새 분류 N · 새 거래처 N」 배지(accent)와 이름 펼치기(details) ; 판정 표의 `category_new`·`vendor_new` 행은 「등록」 판정 그대로에 작은 `badge--accent` 「분류 새로」/「거래처 새로」 ; 매핑 저장 대화에 옵션도 함께 저장 ;
                                 **자동 경로**: 업로드 응답에 `match` 가 있으면 그 매핑·옵션·머리행을 적용하고 **미리보기를 바로 호출**해 3단계로 간다(2단계는 「열 맞추기 보기」 링크로 열 수 있다). 없으면 지금처럼 2단계.
    src/views/purchase_import.php — 체크 2개·요약 배지 자리·「열 맞추기 보기」 링크 마크업. textContent 만.
    db/schema.sql — 열 추가 반영.
    scripts/security_lint.sh — 바꾸지 않는다(새 표·새 라우트 없음).
    PRD.md — F-29 표 행에 「분류·거래처 함께 등록 옵션 / 빈 단위 허용 / 매핑+옵션 기억·자동 적용」 한 문장, 12.2 T-43 에 (i)~(n) 추가 ; ARCHITECTURE.md 5.18 가져오기 행 보충(옵션 표) ; STAGES.md 19단계 학습 기록에 「보정(9회차)」 행(Opus 는 개발 사항만, 검토 결과는 Fable).
금지 패턴   : SQL 문자열 결합 · innerHTML · 인라인 style · 새 라우트/새 표 · 분류·거래처를 **옵션이 꺼져 있는데** 만드는 코드 · 거래처 이름이 둘 이상 있을 때 임의로 하나에 붙이는 코드(R-72) · 거래처 빈칸 행을 「미지정 거래처」 같은 가짜 거래처에 붙이는 코드 ·
             확정을 두 트랜잭션으로 나누는 것(분류만 만들어지고 품목이 실패하면 전부 롤백이어야 한다) · 사용자 실자료(files #609) 를 qa 계정으로 **확정**하는 것(미리보기는 됨 — 쓰지 않으니) · 시드 계정 사용 · 운영 표 DELETE/TRUNCATE.
완료 기준   : tests/purchase_import_items.sh 로 T-43 (i)~(n) 실행(응답 원문) —
             (i) 15열 픽스처(행 12: 정상 3 · 단위 빈칸 2 · 새 분류 3단 2(같은 대분류 공유) · 새 거래처 1 · 거래처 빈칸 1 · 거래처 둘 이상 동명 1 · 파일 내 중복 1 · 판정용 review 1) 를 옵션 없이 미리보기 → 분류·거래처 행은 `category_not_found`/`vendor_not_found` 오류, 단위 빈칸 행은 **오류 아님**(insert) ;
             (ii) `create_categories:true, create_vendors:true` 미리보기 → 오류는 「거래처 빈칸 1(vendor_missing) · 파일 내 중복 1」만, 요약 `new_categories`=새 분류 고유 수(대1·중1·소2 등 실제 수)·`new_vendors`=1, 동명 거래처 행은 여전히 `vendor_ambiguous` review ;
             (iii) 같은 옵션으로 확정 → `p_categories` 가 그 수만큼 늘고 부모 연결(level·parent_id)이 맞음, `p_vendors` +1(code NULL), 품목 행 저장 수 = insert 수, 단위 빈칸 행의 `unit IS NULL` ; 감사 `purchase.import.commit allow` detail 에 `categories_created`·`vendors_created` ;
             (iv) 같은 파일 재확정 → inserted 0 · updated 0 · categories_created 0 · vendors_created 0 (멱등) ;
             (v) 확정 중 실패 유도(픽스처 두 번째 행에 60자 넘는 코드 등 서버가 400 을 내는 행 → 확정 자체가 거부되거나, 트랜잭션 안 실패면) 분류·거래처도 **0건 늘어야** 함 — 어느 쪽이든 「부분 저장 없음」을 psql 로 확인 ;
             (vi) 매핑 저장 `POST /api/purchase/import/mappings {kind, name, mapping, header_row, options:{create_categories:true,create_vendors:true}}` → 응답에 options ; 다시 업로드 → `match` = 그 id, options 포함 ; 열 하나가 빠진 파일 업로드 → `match` 없음 ;
             (vii) 옵션 타입 오류(`create_categories:"yes"`) → 400 ; 종류 vendors 에 create_categories 를 보내도 무시되고 정상 ;
             (viii) 실자료 미리보기(읽기만): files #609 를 qa 로 다시 올리지 **말고**, 검토자(Fable)가 한다 — Opus 는 하지 않는다.
             회귀: tests/purchase_stage19.sh 전부(관문 종료 3 이면 기다렸다 재실행) · tests/purchase_stage25.sh (a) 1440 만(가져오기 화면 결함 0) · scripts/security_lint.sh 0건 · php -l.
보고 형식   : 변경 파일 / 실행 명령·출력 원문(시험 (i)~(vii) 응답, psql 결과, 회귀 수치, 린트) / 못 한 것과 이유 / ARCH 13장 체크리스트.
```

## 1. 판정·확정 규칙 표 (구현자가 그대로 옮길 것)

| 상황 | 옵션 꺼짐 | 옵션 켜짐 |
|---|---|---|
| 단위 빈칸 | **정상**, `unit` NULL (옵션 무관 — 사용자 결정 「원본과 같게」) | 같음 |
| 분류 이름 있음 · 미등록 | error `category_not_found`(지금과 같음, 팝업 등록 유지) | 정상 + `flags.category_new = [{level, name, parent_name}]` ; 확정 때 생성 |
| 분류 중간 단계 빈칸 | 그 아래는 보지 않음(지금과 같음) | 같음 |
| 거래처 이름 있음 · 미등록 | error `vendor_not_found` | 정상 + `flags.vendor_new = name` ; 확정 때 생성(code NULL) |
| 거래처 이름 있음 · 동명 2개 이상 | review `vendor_ambiguous` | 같음(**새로 만들지 않음**) |
| 거래처 빈칸 | error `vendor_missing`(새 사유 — 지금은 `vendor_not_found` 에 섞여 있음) | 같음 |
| 파일 내 (거래처, 코드) 중복 | error `duplicate_in_file` | 같음 |

확정 순서(한 트랜잭션): ① 새 거래처 INSERT(이름 lower 로 중복 제거, 이미 다른 행이 만든 것은 재사용) → ② 새 분류 INSERT(1단 → 2단 → 3단, 같은 부모 아래 lower(name) 중복 제거) → ③ lookup 갱신 → ④ 품목 insert/update(기존 로직) → ⑤ 감사 한 줄 → COMMIT. 어느 단계든 예외면 전부 롤백.

## 2. 화면 문구 (그대로)

- 체크 라벨: 「파일의 분류를 함께 등록」 / 「없는 거래처를 함께 등록」. 도움말(`.help`): 「이카운트 파일의 대·중·소분류와 새 거래처를 확정과 같은 순간에 만듭니다. 거래처 이름이 비어 있는 행과, 같은 이름의 거래처가 둘 이상인 행은 만들지 않고 확인을 기다립니다.」
- 요약 배지: 「새 분류 N」 「새 거래처 N」(accent). 각 배지 옆 「이름 보기」 details.
- 자동 경로 안내(3단계 상단 muted 한 줄): 「저장된 매핑 「{name}」 을 적용했습니다 — 열 맞추기 보기」.
- 미리보기 판정 표 행 표시: 「등록 · 분류 새로」 / 「등록 · 거래처 새로」.
