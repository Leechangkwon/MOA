# 24단계 구현 지시서 (Fable → Opus) — 브랜드·지점·창고 한 페이지 · 창고 대기표 · 연결/제외

작성 2026-09-13. 근거: 사용자 결정 2026-09-13 저녁(아래 「확정 사항」), PRD F-29(가져오기)·F-28(기초정보), ARCH 5.18(purchaseScopeWhere · 마스터에 지점 축이 없는 표의 린트 표식), SECURITY R-65·R-73, STAGES 19단계 보정 2·5차(창고 파일에 지점 열이 없어 생긴 우회로), 실측 2026-09-13 20:05 사고(가져오기 확정 400 → 커밋 7014e9d 로 고침)와 실자료 미리보기 결과(창고 96행 중 「기본 지점」 없이는 0건 진행).

## 확정 사항 (사용자, 2026-09-13) — 다시 묻지 않는다
1. **브랜드·지점은 사람이 만든다.** 창고 파일에서 지점을 파생 등록하는 19단계 흐름은 **그대로 두되**(회귀 유지) 새 페이지의 주 경로가 아니다. 새 페이지는 「연결 추천」에만 이름 해석을 쓴다.
2. **창고 파일은 브랜드·지점 없이 그냥 올린다.** 이카운트 창고 내보내기(ESA005M: 창고코드·창고명·구분·생산공정명·외주거래처명·사용·추가사업장명)를 매핑 마법사 없이 **창고 대기표**에 넣는다. 「기본 지점」을 고르라는 400 이 없다.
3. **창고 이름은 이카운트 이름 그대로** 저장한다(다듬지 않는다). 예: 「플란치과_강남점」·「라미트치과」·「중앙창고」.
4. **지점이 없는 창고는 「제외」 체크로 뺀다.** 제외된 창고는 연결하지 않아도 나머지 진행을 막지 않는다. 언제든 되살릴 수 있다.
5. **창고 하나는 지점 하나에만 속한다** (`p_locations.branch_id NOT NULL` 유지 — 스키마를 느슨하게 풀지 않는다).
6. **한 페이지**에서 브랜드 → 지점 나무, 지점별 연결된 창고, 미연결 창고 목록, 제외 목록, 창고 파일 업로드를 모두 다룬다.

```
목표        : 「브랜드·지점·창고」 한 페이지(/purchase/structure)에서 ① 브랜드·지점을 그 자리에서 만들고 ② 이카운트 창고 파일을 대기표(p_location_inbox)로 바로 올리고
              ③ 미연결 창고를 골라 지점에 연결(그때만 p_locations 행이 생김)하거나 ④ 제외 체크로 빼며 ⑤ 지점별 연결 결과를 한눈에 본다. 실자료 ESA005M(96행)이 기본 지점 없이 끝까지 간다.
요구사항    : 이 지시서 + PRD F-28·F-29·S-01·S-03·S-04·S-06, ARCH 4.1·5.18·7.4·7.5, SECURITY R-65·R-73, CLAUDE.md 2장 금지 패턴 — 읽고 시작.
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-15_purchase_location_inbox.sql — 표 p_location_inbox(아래 3장) + GRANT(SELECT·INSERT·UPDATE, DELETE 없음) + 시퀀스. 멱등(IF NOT EXISTS). db/schema.sql·db/grants.sql 에도 같은 정의를 반영.
    src/purchase/Structure.php — 대기표 upsert(파일 → 행) · 나무 조회(브랜드→지점→연결 창고) · 연결 추천(이름 해석) · 연결(트랜잭션) · 제외/되살리기. SQL 은 전부 고정 문장(조립 없음). 이 파일에 역할·branch_id 판정 없음(ARCH 4.1 — 판정은 Authz·라우트 정책).
    src/handlers/purchase_structure_page.php   — GET  /purchase/structure                (정책 purchase.master.write)
    src/handlers/purchase_structure_get.php    — GET  /api/purchase/structure            (purchase.master.write) 나무 + 대기표(미연결·제외) + 추천 + counts
    src/handlers/purchase_structure_upload.php — POST /api/purchase/structure/upload     (purchase.master.write, multipart `file`) 창고 파일 → 대기표 upsert
    src/handlers/purchase_structure_link.php   — POST /api/purchase/structure/link       (purchase.master.write) {links:[{inbox_id,branch_id}], kind?} 최대 200
    src/handlers/purchase_structure_exclude.php— POST /api/purchase/structure/exclude    (purchase.master.write) {inbox_ids:[…], excluded:true|false} 최대 200
    src/views/purchase_structure.php · public/assets/purchase_structure.js · public/assets/purchase_structure.css — 화면(아래 5장). purchase.js 는 건드리지 않는다.
    tests/purchase_stage24.sh — 관문(R-73)·인구조사(p_location_inbox 포함)·S24- 접두·qa 행만 정리.
  [고침 — 범위만]
    public/index.php 라우트 표(위 5 라우트, `/api/purchase/structure` 는 `{num}` 과 충돌 없음) · src/views/shell_top.php(내비 「브랜드·지점·창고」 항목, id nav-purchase-structure, 기본 hidden) · public/assets/app.js(showNav('nav-purchase-structure', canPurchaseMaster) 한 줄)
    src/handlers/purchase_import_upload.php ↔ 새 업로드 핸들러 사이의 **공통 조각**(원본 파일명 정리 · Upload::validate · Sheet::SHEET_EXT 검사 · 민감정보 검출 · 「구매/가져오기」 폴더 확보 · files 행 + Storage::put · 롤백 시 파일 삭제)을 `Importer::storeUploaded(array $uploaded, array $user, string $auditAction): array{file_id,public_id,original,ext,sha256,stored_name}` 같은 **한 함수**로 빼서 둘이 함께 쓴다(동작 무변경 — stage19 시험이 그 증거). 거부 사유 문자열은 그대로 유지.
    src/Audit.php — 7.4 문장 틀(아래 6장) · ALLOWED_DETAIL_KEYS 는 새 키가 부분 일치에 걸릴 때만
    ARCHITECTURE.md 5.18(「창고 대기표」 절 + 라우트/정책 표 5행) · 7.4 표(아래 6장 행) · 7.5 등록부(새 detail 키) ; PRD.md F-37 신설(이 지시서 1·2장 요지) · 12.2 T-49 행 · 13장 24단계 ; SECURITY.md R-65 표에 「p_location_inbox 는 지점 축이 없는 마스터 — 라우트가 buyer·Admin 전용」 한 줄 ; STAGES.md 24단계 절(개발 체크·검토 체크 틀) ; README 라우트 목록 한 줄
금지 패턴   : SQL 결합 · innerHTML · 화면에서 판정(추천도 서버 값만 표시) · Authz 밖 역할 비교 · p_* DELETE(시험 정리는 postgres 로, qa created_by 만) · 새 라이브러리 · 사용자 활동 중 시험 · 자격증명 cat · p_locations.branch_id NULL 허용 · 이카운트 창고명 변형(trim 만 허용).
완료 기준   : T-49 — (a) 제목행+머리행+창고 6행+꼬리행(작성 일시 1칸) xlsx 업로드 → inserted 6 · skipped 1(꼬리행) · 대기표 6행 status=unlinked, 이름은 원문 그대로, 「사용 No」 행은 ecount_active=false ; 같은 파일 재업로드 → inserted 0 · updated 0 · unchanged 6(코드 기준 멱등) ; 구분·사용 값을 바꾼 파일 → updated 1 ;
              (b) GET /api/purchase/structure → 미연결 6, 추천: 「S24-플란_S24-강남」 → 브랜드 S24-플란 의 지점 S24-강남 (suggest.by=name), 「S24-중앙창고」 → suggest null ;
              (c) link 3건(한 지점) → p_locations 3행(name 원문 · code=창고코드 · legacy_name=원문 · kind: 구분 「창고」→warehouse, 그 외→other, 요청 kind 가 있으면 그것 · is_active=ecount_active) · 대기표 status=linked·location_id 채움 · 감사 purchase.structure.link allow 1줄(detail links=3) ; 같은 지점에 같은 이름이 이미 있으면 **묶음 전체 409 conflict·0행**(트랜잭션) ; 중단된 지점 → 400 bad_reference ; 이미 linked/excluded 행 → 400 not_unlinked ;
              (d) exclude 2건 → status=excluded, 나무의 미연결 수 = 1 ; excluded:false 로 되살리기 → unlinked ; 제외 행에 link → 400 not_unlinked ;
              (e) 지점 없는 창고가 제외된 상태에서 나머지 연결이 막히지 않는다(counts.unlinked=0 이면 화면 「모두 정리됨」) ;
              (f) qa_employee01·qa_guest01 → 페이지·API 5개 전부 403(정책 purchase.master.write, 재인증 없는 buyer 세션은 재인증 관문 응답) ;
              (g) 실자료 ESA005M(사용자가 올린 파일 — 시험은 **읽기만**: sudo 로 복사해 qa 로 올리지 않는다. 대신 같은 열 구조의 S24- 픽스처를 쓴다) ;
              (h) scripts/security_lint.sh 0 · tests/purchase_stage19.sh 회귀(관문 열릴 때) · 새 시험 전부 통과 · 비-qa 행 수 변동 0.
보고 형식   : 변경 파일 / 명령·출력 원문 / 못 한 것 / 완료 기준 (a)~(h) 체크 / 사용자가 볼 것 3줄. **커밋 금지**(Fable 이 검토 뒤 커밋).
```

## 1. 왜 대기표인가 (한 번만 설명)
`p_locations.branch_id` 는 NOT NULL 이고, 지점 격리(`Authz::purchaseScopeWhere`, R-65)·전표·재고·주간 사용량이 전부 그 열로 훑는다. 「지점 없는 창고」를 본 표에 넣으려면 그 열을 NULL 허용으로 풀고 격리·전표·현황 코드가 NULL 을 다시 다뤄야 한다. 대신 **연결되기 전의 창고는 본 표 밖(대기표)에** 두고, 연결하는 순간에만 `p_locations` 행을 만든다. 전표·재고 쪽 코드는 한 줄도 바뀌지 않고, 격리도 그대로다.

## 2. 흐름 (사용자 시점)
1. 「브랜드·지점·창고」 를 연다(구매팀·관리자, 재인증 15분 창 — 기존 기초정보 편집과 같다).
2. 왼쪽 나무에서 브랜드 「+」, 지점 「+」 로 이름만 적어 만든다(기존 `POST /api/purchase/brands`·`/branches` 그대로 호출).
3. 「창고 파일 올리기」 로 ESA005M 을 올린다 → 응답의 등록/갱신/변동 없음/건너뜀 수를 한 줄로 보여 주고 목록을 다시 읽는다.
4. 오른쪽 「미연결 창고」 표에서 행마다 **추천 지점**이 미리 채워져 있다(없으면 빈 선택). 체크 → 「선택한 창고를 [지점] 에 연결」 또는 「추천대로 연결」.
5. 지점이 없는 창고는 행의 「제외」 체크 → 제외 목록으로 내려간다. 제외 목록에서 「되살리기」.
6. 미연결 0 이면 상단에 「모두 정리됨 — 연결 N · 제외 K」.

## 3. 표 `p_location_inbox` (마이그레이션)
```
id             BIGSERIAL PRIMARY KEY
ecount_code    TEXT NOT NULL                      -- 창고코드 (원문 trim)
name           TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 80)   -- 창고명 원문 그대로
kind_text      TEXT                               -- 「구분」 원문 (창고·기타 …)
site_name      TEXT                               -- 「추가사업장명」 원문 (표시용)
ecount_active  BOOLEAN NOT NULL DEFAULT true      -- 「사용」 Yes/No (대소문자 무시, 'Y'·'YES'·'예' → true)
status         TEXT NOT NULL DEFAULT 'unlinked' CHECK (status IN ('unlinked','linked','excluded'))
location_id    BIGINT REFERENCES p_locations(id)  -- linked 일 때만
source_file_id BIGINT REFERENCES files(id)        -- 마지막으로 이 행을 만들거나 갱신한 업로드
created_by     BIGINT NOT NULL REFERENCES users(id)
created_at / updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
linked_by      BIGINT REFERENCES users(id) · linked_at TIMESTAMPTZ
UNIQUE (ecount_code)
INDEX (status)
```
- GRANT: `SELECT, INSERT, UPDATE` → tbox_app. **DELETE 없음**(기초정보와 같다). 시퀀스 USAGE·SELECT.
- upsert 규칙(코드 기준): 없으면 INSERT(unlinked) · 있으면 name·kind_text·site_name·ecount_active 가 하나라도 다르면 UPDATE(status 는 **바꾸지 않는다** — linked/excluded 는 그대로), 같으면 unchanged. 코드가 빈 행·머리행 이전 행·꼬리행(창고코드 칸이 날짜꼴이거나 나머지 칸이 전부 빈 행)은 skipped 로 센다.
- linked 행의 name 이 갱신돼도 `p_locations.name` 은 건드리지 않는다(응답 `renamed_linked` 수만 알린다 — 사람이 판단).

## 4. 서버 규칙
- **머리행·열 해석**: `Importer::headerRowFrom(null, 'locations', $rows)` 로 머리행을 찾고, 열 이름은 Importer 의 이카운트 창고 동의어(창고코드·창고명·구분·사용 + 추가사업장명)를 **그 표에서 읽어** 쓴다(동의어 표를 복사하지 않는다). 창고코드·창고명 열이 없으면 400 `bad_header`.
- **추천(suggest)**: 창고명을 `_`·공백으로 잘라 토큰을 만든다. 활성 지점 이름과 **정확히**(trim·대소문자 무시) 같은 토큰이 있으면 그 지점. 후보가 둘 이상이면 그중 앞 토큰이 그 지점의 브랜드 이름과 같은 것을 고르고, 그래도 둘 이상이면 추천 없음(`suggest: null`, `why: ambiguous`). 창고명 전체가 지점 이름과 같아도 추천한다(「라미트치과」 지점이 있으면). 추천은 **표시용 값**이고 연결은 요청이 보낸 branch_id 로만 한다.
- **link**: 본문 `{links:[{inbox_id,branch_id}], kind?}` (허용 키 밖 400 `bad_request`, 1~200건, 같은 inbox_id 중복 400). 한 트랜잭션: 각 행을 `FOR UPDATE` 로 잠가 status=unlinked 확인(아니면 400 `not_unlinked`) → 지점이 있고 활성인지(`bad_reference`) → `INSERT p_locations(branch_id, kind, name, code, legacy_name, is_active, created_by)` (name=원문, code=ecount_code, legacy_name=원문, is_active=ecount_active) → 유일 인덱스 위반은 409 `conflict` 로 **전체 롤백** → 대기표 UPDATE status=linked·location_id·linked_by·linked_at. 감사 **한 줄** `purchase.structure.link allow` detail `{links: N, branch_ids: [...], location_ids: [...]}`.
- **exclude**: `{inbox_ids:[…], excluded:true}` 는 unlinked → excluded, `excluded:false` 는 excluded → unlinked. 그 밖의 상태 400 `not_unlinked`(되살리기는 `not_excluded`). 감사 한 줄.
- **GET /api/purchase/structure** 응답:
  `{brands:[{id,name,is_active,branches:[{id,name,code,is_active,locations:[{id,name,kind,code,is_active,inbox_id}]}]}], unlinked:[{id,ecount_code,name,kind_text,site_name,ecount_active,suggest:{branch_id,brand_id,by:'name'}|null,why?}], excluded:[…같은 모양, suggest 없음], counts:{unlinked,linked,excluded,brands,branches}}`. 중단된 브랜드·지점은 `is_active=false` 로 포함(화면이 흐리게).
- **린트**: `p_location_inbox` 는 지점 축이 없는 마스터이고 다섯 라우트가 전부 buyer·Admin 전용이라 `// lint:no-branch-scope` 표식을 **조회 단위**로 적는다(23단계 규칙). 나무 조회의 `p_locations` 도 같은 이유(전 지점을 보는 마스터 편집 화면)로 표식 — 이유를 한 줄로.

## 5. 화면 (`/purchase/structure`, 시안 없음 — 기초정보 화면의 패널·표·단추 클래스를 그대로 쓴다)
- 상단: 제목 「구매관리 › 브랜드·지점·창고」, 부제 「브랜드와 지점은 여기서 만들고, 창고는 이카운트 파일을 그대로 올린 뒤 지점에 연결합니다. 지점이 없는 창고는 제외하면 됩니다.」, 오른쪽에 「창고 파일 올리기」(파일 선택 + 단추) 와 counts 칩 3개(미연결·연결·제외).
- 왼쪽 패널 「브랜드 → 지점」: 브랜드마다 접이식 블록, 그 안에 지점 행(이름·코드·연결 창고 수), 지점 아래 연결 창고 목록(창고명 원문 · 코드 · 구분 · 중단 표시). 브랜드 블록 끝에 「+ 지점」(이름 입력 한 칸 → POST /api/purchase/branches {brand_id,name}), 패널 끝에 「+ 브랜드」(POST /api/purchase/brands {name}). 만든 뒤 전체 다시 읽기.
- 오른쪽 패널 「미연결 창고 N」: 표 열 = 체크 · 창고코드 · 창고명 · 구분 · 사용 · 지점(select, 브랜드별 optgroup, 추천이 있으면 미리 선택·「추천」 배지) · 제외(체크박스, 누르면 즉시 exclude 호출). 표 위 도구줄: 「전체 선택」·「추천대로 연결」(체크된 행 중 select 값이 있는 것만, 행마다 자기 select 값) ·「선택한 창고를 [지점 select] 에 연결」·kind select(창고/중앙/진료실/기타, 기본 「구분」에서 유추). 결과 문장은 `say()` 한 줄.
- 아래 접이식 「제외된 창고 K」: 코드·이름·구분 + 「되살리기」.
- 오류 응답의 사유는 기존 화면들의 `errorMessage()` 규칙과 같은 한국어 문장으로: bad_header 「창고코드·창고명 열을 찾지 못했습니다 — 이카운트 창고 내보내기 파일인지 확인하세요」, conflict 「그 지점에 같은 이름의 창고가 이미 있어 이번 묶음은 저장하지 않았습니다」, not_unlinked 「이미 연결되었거나 제외된 창고가 섞여 있습니다 — 목록을 다시 읽습니다」, bad_reference 「중단되었거나 없는 지점입니다」.
- 모든 값은 `textContent`. 인라인 script·style·on* 없음. 재인증 필요 응답(기존 `request()` 의 처리)은 기초정보 화면과 같게.

## 6. 감사 문장 (ARCH 7.4 에 그대로 등록 — 없는 조합은 500 이다)
```
purchase.structure.upload|allow                 {actor} 이(가) 창고 파일 「{target}」 을(를) 창고 대기표에 올렸습니다 (등록·갱신·건너뜀 수는 상세 기록)
purchase.structure.upload|deny|<업로드 거부 사유 12종 — purchase.import.upload 와 같은 키·같은 문장 골격>
purchase.structure.upload|deny|bad_header       {actor} 이(가) 창고코드·창고명 열이 없는 파일 「{target}」 을(를) 창고 대기표에 올리려 해서 거부했습니다
purchase.structure.upload|error|parse_failed    {actor} 이(가) 올린 창고 파일 「{target}」 에서 표를 읽어 내지 못했습니다
purchase.structure.link|allow                   {actor} 이(가) 대기표의 창고를 지점에 연결했습니다 (연결 건수·지점·장소 id 는 상세 기록)
purchase.structure.link|deny|bad_request        {actor} 이(가) 창고 연결을 잘못된 형식으로 요청해서 거부했습니다
purchase.structure.link|deny|bad_reference      {actor} 이(가) 없는(또는 중단된) 지점에 창고를 연결하려 해서 거부했습니다
purchase.structure.link|deny|not_unlinked       {actor} 이(가) 이미 연결되었거나 제외된 창고를 다시 연결하려 해서 거부했습니다
purchase.structure.link|deny|conflict           {actor} 이(가) 지점에 같은 이름의 창고가 이미 있어 연결 묶음을 저장하지 않았습니다
purchase.structure.exclude|allow                {actor} 이(가) 대기표의 창고를 제외했거나 되살렸습니다 (건수·방향은 상세 기록)
purchase.structure.exclude|deny|bad_request     {actor} 이(가) 창고 제외를 잘못된 형식으로 요청해서 거부했습니다
purchase.structure.exclude|deny|not_unlinked    {actor} 이(가) 미연결 상태가 아닌 창고를 제외하려 해서 거부했습니다
purchase.structure.exclude|deny|not_excluded    {actor} 이(가) 제외 상태가 아닌 창고를 되살리려 해서 거부했습니다
```
detail 키: `file_id`·`sha256`·`ext`·`inserted`·`updated`·`unchanged`·`skipped`·`renamed_linked`·`links`·`branch_ids`·`location_ids`·`inbox_ids`·`excluded`(bool)·`kind`. 개인정보 없음. 창고명은 target 에만(업로드는 파일명).

## 7. 시험 `tests/purchase_stage24.sh`
- 23단계 스크립트의 관문·로그인·재인증·call·인구조사·정리 골격을 그대로 복사한다. 인구조사 표에 `p_location_inbox` 를 더한다.
- 픽스처 xlsx 는 19단계 시험처럼 PHP ZipArchive 로 만든다: 0행 「회사명 : S24」, 1행 머리(창고코드·창고명·구분·생산공정명·외주거래처명·사용·추가사업장명), 데이터 6행(S24-플란_S24-강남 · S24-플란_S24-일산 · S24-라미트 · S24-중앙창고 · S24-폐기창고(사용 No) · S24-기타창고(구분 기타)), 꼬리행 1칸 「2026/09/13  오전 4:56:43」.
- 브랜드 S24-플란 · 지점 S24-강남·S24-일산·S24-라미트 를 먼저 만든다(qa_admin01, 기존 API).
- (a)~(h) 를 expect 로 하나씩. 정리: `DELETE FROM p_location_inbox WHERE created_by IN (qa ids)` · `DELETE FROM p_locations/p_branches/p_brands WHERE created_by IN (qa ids) AND name LIKE 'S24-%'`(postgres 로) · files 는 19단계 정리와 같은 방식.
- 시험 시작 조건 R-73 ②(비-qa 10분 무활동) 그대로. 종료 코드 3 이면 「관문 닫힘」으로 보고.

## 8. 보충 규칙
1. `Importer::storeUploaded()` 로 빼는 리팩터는 **동작 무변경**이 조건이다 — 먼저 빼고 stage19 ⑩~⑬ 절이 그대로 통과하는지 본 뒤 새 핸들러를 얹는다.
2. 창고명 trim 외의 변형(대소문자·기호 제거)은 **추천 계산 안에서만** 하고 저장 값에는 손대지 않는다.
3. 재인증 관문: 다섯 라우트가 모두 `purchase.master.write` 라 REAUTH_POLICIES 가 자동으로 붙는다 — 새 정책 이름을 만들지 않는다.
4. 19단계 가져오기 화면의 창고·지점 파생 흐름은 **삭제하지 않는다**. 가져오기 화면 상단 안내에 「창고는 새 화면 「브랜드·지점·창고」 에서 올리는 것이 쉽습니다」 링크 한 줄만 더한다(purchase_import.php 뷰의 문장, JS 무변경).
5. 문장·주석은 한국어, 기존 파일들의 머리 주석 형식(경로 · 라우트 · 정책 · 왜)을 따른다.
