# 19단계 구현 지시서 (Fable → Opus) — 구매·재고 모듈 골격 · 기초정보 · 엑셀 가져오기 · 품목 찾기 API

작성 2026-09-13. 확정 근거: 사용자 "확정"(2026-09-13) → PRD F-28·F-29·F-30(API만)·T-42~T-44, ARCH 5.18, SECURITY R-65~R-70, STAGES 19단계.

```
목표        : 구매·재고 모듈이 데이터를 받아들일 자리를 만든다 — 모듈 역할·지점 컬럼, 기초정보 6종(브랜드·지점·장소·거래처·분류/품목·인가량) 화면·API,
              엑셀(xlsx·csv) 가져오기 파이프라인(업로드→추출→매핑→미리보기→한 트랜잭션 확정), 품목 찾기 API. 전표(PR/PO/GR…)는 20단계 — 이번엔 표도 만들지 않는다.
요구사항    : PRD 6장 F-28·F-29·F-30(품목 API 부분), 8장 `/purchase/*` 행, 9장 `p_*` 행, 12.2 T-42·T-43·T-44 ;
              ARCHITECTURE 5.18(모듈 경계·권한 두 축·가져오기·감사), 4.0 요청 흐름, 4.1 판정 코드, 7.4/7.5 감사, 10장 권한, 13장 체크리스트 ;
              SECURITY R-65·R-67·R-70 ; docs/purchase/02-prd-draft.md 3장(데이터 모델)·03-architecture-draft.md 전체 ;
              시안 docs/purchase/design/Masters.dc.html(⑭)·Item.dc.html(⑮)·ExcelImport.dc.html(⑯)·ItemPicker.dc.html(② — API 응답 모양의 근거) — 읽고 시작할 것.
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-13_purchase_masters.sql        — p_brands p_branches p_locations p_vendors p_categories p_items p_item_branches p_par_levels p_import_mappings
                                                           + users.branch_id (FK p_branches, NULL 허용) · users.purchase_role (CHECK requester|buyer|stock|viewer, NULL 허용)
                                                           + 인덱스: p_items(code) UNIQUE(vendor_id, code), p_items(lower(name)) , p_items(cat1_id,cat2_id,cat3_id), p_locations(branch_id), p_par_levels UNIQUE(location_id,item_id,effective_from)
    src/purchase/Sheet.php                                — xlsx·csv → 행 배열. xlsx 는 ZipArchive + **정규식만**(Preview.php 머리말 규칙 4: XML 파서 금지) 로 sharedStrings·sheet1 을 읽는다.
                                                           상한 상수: SHEET_ROWS 20000 · SHEET_COLS 60 · MEMBER_BYTES 16 MiB · 멤버는 statName 크기 확인 뒤 getFromName (Preview::office 와 같은 순서). 넘으면 truncated=true 로 끊고 확정 단계에서 400.
                                                           csv 는 Preview::toUtf8·scrubControls 와 같은 방식(private 이면 Sheet 에 복제하지 말고 Preview 에 public static 으로 여는 것만 허용 — 아래 Preview.php 항목).
    src/purchase/Master.php                               — 기초정보 6종 공통 저장 로직(허용 키 목록, 중단=is_active=false, before/after 스냅샷 반환). SQL 은 전부 바인딩.
    src/purchase/Importer.php                             — 종류(items|vendors|locations) 별 필수 열·판정(insert|update|review|error)·확정(Db 트랜잭션 하나, 실패 시 전부 롤백). 거래처 코드 매칭·분류 이름→id 매칭 포함.
    src/handlers/purchase_page.php                        — GET /purchase → /purchase/masters 로 302 (19단계엔 화면이 기초정보만)
    src/handlers/purchase_masters_page.php                — GET /purchase/masters (시안 ⑭ 탭 6개)
    src/handlers/purchase_item_page.php                   — GET /purchase/items/{id} (시안 ⑮)
    src/handlers/purchase_import_page.php                 — GET /purchase/import (시안 ⑯)
    src/handlers/purchase_masters_list.php                — GET /api/purchase/{brands|branches|locations|vendors|categories|par-levels}  (종류는 라우트 표에서 각각 등록, 핸들러 하나가 $ctx['kind'] 로 분기해도 됨 — 단 라우트마다 정책은 명시)
    src/handlers/purchase_masters_create.php              — POST  같은 경로
    src/handlers/purchase_masters_update.php              — PATCH 같은 경로/{id}  (중단·재개 포함)
    src/handlers/purchase_items_search.php                — GET /api/purchase/items?q&cat1&cat2&cat3&vendor&branch_used&stock_managed&sort&page  → {items:[…20건], total, page}
    src/handlers/purchase_item_get.php                    — GET /api/purchase/items/{id}  (사용 지점·인가량 포함)
    src/handlers/purchase_items_create.php / purchase_items_update.php
    src/handlers/purchase_import_upload.php               — POST /api/purchase/import  (multipart) → Upload::validate → files 에 저장(폴더 「구매/가져오기」 admin-only, 없으면 생성) → Sheet 추출 → 머리행·표본 5행·저장된 매핑 후보 반환 {import_id, columns, sample, mapping?}
    src/handlers/purchase_import_preview.php              — POST /api/purchase/import/{import_id}/preview  body {kind, mapping:{열→필드}} → 행별 판정 목록 {insert,update,review,error 개수 + rows[{row_no,status,reason,data}]}
    src/handlers/purchase_import_commit.php               — POST /api/purchase/import/{import_id}/commit  → 한 트랜잭션, 결과 {inserted, updated, skipped} , 오류 행은 저장하지 않음. 감사 detail: file_id·sha256·rows·inserted·updated
    src/handlers/purchase_import_mappings.php             — GET/POST/DELETE /api/purchase/import/mappings  (p_import_mappings: kind, name, mapping_json, created_by)
    src/views/purchase_masters.php · purchase_item.php · purchase_import.php  (기존 views 골격·토큰 재사용)
    public/assets/purchase.js · public/assets/purchase.css  (textContent 만. innerHTML 금지)
    tests/purchase_stage19.sh                             — T-42·T-43·T-44 실행 스크립트(qa_ 계정, -A "tbox-review/$(date +%F)")
  [고침 — 아래 범위만]
    public/index.php                                      — 라우트 표에 위 경로 추가(정책 필수), 내비 그룹 「구매관리」(me 의 can_purchase 가 true 인 사용자만 보이게 — 표시는 편의, 판정은 서버)
    src/Authz.php                                         — KNOWN_POLICIES 에 purchase.read · purchase.request.write · purchase.buy.write · purchase.stock.write · purchase.master.write · purchase.close 추가 ;
                                                           REAUTH_POLICIES 에 purchase.master.write · purchase.close 추가 ;
                                                           새 함수 purchaseAuthorize(string $policy, array $u): bool  — Admin 은 전부 true. 그 밖: read = purchase_role 있음 / request.write = requester|buyer / buy.write = buyer / stock.write = stock|buyer / master.write = buyer / close = buyer. Guest 는 항상 false.
                                                           새 함수 purchaseScopeWhere(array $u, string $alias): array{sql:string, params:array} — Admin·buyer·viewer: ['1=1', []] ; requester·stock: ["$alias.branch_id = :p_my_branch", ['p_my_branch'=>$u['branch_id']]] ; branch_id NULL 이면 ['1=0', []].
                                                           authorize() 에서 정책이 'purchase.' 로 시작하면 purchaseAuthorize 로 위임. 기존 함수·판정 코드는 바꾸지 않는다.
    src/Audit.php                                         — 문장 틀 추가: purchase.master.{create|update|deactivate} · purchase.import.{upload|preview|commit} · purchase.item.search 는 기록하지 않음(조회) · 각 allow/deny/error. detail 허용 키에 kind·target_id·before·after·file_id·sha256·rows·inserted·updated·branch_id 추가
    src/handlers/me.php                                   — branch_id · purchase_role · can_purchase(bool) 추가
    src/handlers/users_update.php · users_create.php · users_list.php · src/views/users.php  — 허용 키에 branch_id · purchase_role (Admin 만, decideUserChange 경유), 화면에 두 칸(지점 셀렉트·모듈 역할 셀렉트)
    src/Preview.php                                       — toUtf8 / scrubControls / openZip 을 public static 으로 여는 것만 허용. 로직 변경 금지.
    db/schema.sql · deploy/postgresql/grants.sql          — p_* 표 반영. tbox_app: p_* SELECT·INSERT·UPDATE(DELETE 없음), 시퀀스 USAGE. tbox_backup: SELECT. tbox_perm: 없음.
    scripts/security_lint.sh                              — 새 규칙 2개: (a) src/handlers/purchase_*.php 안에서 'FROM p_' 가 있는데 같은 파일에 'purchaseScopeWhere(' 가 없으면 반려(S-01/R-65) — 예외: purchase_masters_* 중 브랜드·거래처·분류(지점 무관 마스터)는 파일 상단 주석 `// lint:no-branch-scope <이유>` 로 표시하고 그 표식이 있으면 통과 ; (b) 'purchase_role' 문자열 비교가 Authz.php 밖에 있으면 반려.
    README.md                                             — 「구매·재고 모듈」 절(역할·지점·기초정보·가져오기 사용법)
    STAGES.md                                             — 19단계 「개발 사항」 체크 표시만
금지 패턴   : SQL 문자열 결합(정렬 컬럼은 허용 목록 배열에서만 고름), innerHTML/outerHTML, 사용자 입력이 들어간 파일 경로, curl_exec/file_get_contents('http…'),
             역할·purchase_role·branch_id 판단을 Authz 밖에서 하는 코드, 요청 파라미터의 role/purchase_role/branch 값을 판정에 쓰는 코드(requester 의 ?branch= 는 무시하고 purchaseScopeWhere 가 결정),
             DOMDocument·SimpleXML·XMLReader·libxml_* 사용, 재고·전표 표 생성(20단계), p_* 에 DELETE, 시드·검토 계정 자격증명 파일 전체 출력(cat 금지 — 필요한 필드만 awk).
완료 기준   : PRD 12.2 T-42 · T-43 · T-44 를 tests/purchase_stage19.sh 로 실제 실행(응답 원문 첨부) ; 회귀 T-1 · T-26 · T-36 ; scripts/security_lint.sh 0건(새 규칙 포함) ; psql `\dp p_*` 로 권한 확인 ;
             마이그레이션 적용 뒤 기존 사용자 로그인·파일 목록 정상 ; ARCHITECTURE 13장 체크리스트 자체 점검 표.
             T-43 성능: p_items 에 1.35만 행 더미 적재(스크립트 tests 안, 확인 뒤 삭제) 후 검색 응답 시간 실측.
보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문(curl 상태·본문, psql 결과, 린트 출력) / 못 한 것과 이유 / 13장 체크리스트 표.
             커밋은 하지 않는다(Fable 이 관문 ② 검토 뒤 커밋).
```

## 보충 규칙

1. **지점 격리는 SQL 에서.** 장소·인가량·품목 사용 지점·(20단계 이후) 전표 조회는 모두 `Authz::purchaseScopeWhere($u, 'alias')` 의 sql 을 WHERE 에 AND 로 붙이고 params 를 합친다. 응답 전 필터링으로 대체하지 않는다.
2. **모듈 역할 없는 사용자**(purchase_role NULL, Admin 아님)는 `/purchase/*` 전부 403, 감사 `authz.route deny` (기존 코드 재사용). 내비에 「구매관리」를 보이지 않게 하는 것은 편의일 뿐이다.
3. **기초정보 편집은 재인증 뒤.** `purchase.master.write` 가 REAUTH_POLICIES 에 있으므로 기존 관문 [3-2] 가 자동으로 붙는다. 화면은 403 `next:reauth` 를 받으면 기존 재인증 이동 코드를 재사용.
4. **삭제 없음.** 기초정보는 `is_active=false` 로 중단. 중단된 품목은 검색 기본값에서 제외(`include_inactive=1` 은 buyer·Admin 만).
5. **품목 찾기 API** — 조건은 전부 바인딩, `q` 는 `lower(name) LIKE lower(:q)`·`code LIKE`·`barcode =` 3중 OR (LIKE 인자에 `%`·`_` 는 이스케이프), 정렬은 `['name','code','vendor','updated_at']` 배열에서만, page 는 1~500 정수 캐스팅, 20건 고정. 응답 필드는 시안 ② 의 열(코드·품목명·규격·단위·포장 낱개·거래처·분류·재고관리·현재고는 21단계라 null).
6. **엑셀 추출 안전 규칙**(Preview.php 머리말 그대로): 업로드 검사(확장자 xlsx·csv 만, `.xlsm`·`.xls` 거부는 기존 Upload 규칙), zip 은 중앙 디렉터리 → 멤버 크기 확인 → 상한 안에서만 읽기, 정규식 추출, 셀 값은 문자열로만(수식 무시, 셀 앞의 `=`·`+`·`-`·`@` 는 내려받기 때 앞에 `'` 를 붙여 CSV 인젹션 차단).
7. **가져오기 판정** — items: 필수 열 code·name·vendor(코드 또는 이름) ; 같은 (vendor, code) 있으면 update(달라진 필드만 before/after), 없으면 insert ; 거래처 없음·분류 3단 이름 불일치·단위 없음 → error ; 이름만 다르고 코드 같음 → review(사용자가 미리보기에서 update 로 승격 가능). vendors·locations 는 code 또는 (branch, name) 기준.
8. **감사**: 확정 1건에 `purchase.import.commit allow` 한 줄(detail rows·inserted·updated·file_id·sha256). 행마다 기록하지 않는다. 기초정보 개별 편집은 건당 한 줄(before/after).
9. **파일**: 업로드된 원본은 `files` 표·저장소에 남긴다(F-22 민감정보 검출 대상). 폴더 「구매/가져오기」 는 access_level admin-only, 처음 필요할 때 시스템이 만든다(감사 `folder.create allow`, actor 는 요청자).
10. **검토 계정**: 새 qa_ 계정은 만들지 않는다. T-42 용 지점·역할은 기존 qa_ 계정 5개 중 Employee 두 계정에 `psql` 로 branch_id·purchase_role 을 심는다(테스트 스크립트에 넣고 끝에 원복). 값은 보고서에 계정명만 적는다.
