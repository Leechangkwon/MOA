# 모아 제품 공간 API 계약

모든 주소는 `/tbox` 접두, 세션 인증만. 기존 최초 로그인·약관·CSRF 관문 적용, 프로그램 토큰 미지원. UUID는 v4. 변경 요청은 `X-CSRF-Token`, JSON. 회사/모임 모두 다중 멤버십이며 기존 팀/문서/파일은 자동 연결하지 않는다.

## 공간과 멤버

- `GET /api/product-spaces` → `{can_create,spaces:[{id,name,kind:company|group,status:active|archived,role,can_manage,can_edit_home,can_write,can_restore,can_transfer,created_at}]}`. 본인 멤버십만 최대100.
- `POST` 같은 주소 `{name,kind}` →201 `{space}`. 작성자가 sole owner. 생성 최대50/사용자.
- `GET /api/product-spaces/{id}` → `{space}`. `PATCH {name?,status?}`; 상태 변경/복구 owner, 활성 공간 이름 변경 owner/admin.
- `GET /{id}/members` → `{members:[{user_id,username,display_name,role}],me,space}` 최대500명.
- `PATCH /{id}/members/{num} {role}`; owner는 owner 이외 역할 변경, admin은 editor/member/viewer 사이만. owner 역할은 직접 부여 금지.
- `DELETE /{id}/members/{num}` → `{ok:true}`. 본인 탈퇴 또는 owner/admin 관리 범위 제거. 마지막 owner 제거/강등409. 공간 행을 먼저 잠가 동시 변경 직렬화.
- `POST /{id}/transfer {user_id,password,otp?}` → `{space}`. 기존 owner 비밀번호 재인증 및 MFA 활성 계정 fresh OTP, 대상은 활성 멤버/계정이며 전역 쓰기 자격 필요. 원 소유자는 admin, 대상 owner.

## 초대

- `GET /{id}/invites` → `{invites:[{id,role,expires_at,max_uses,uses,revoked_at,created_at}]}`. 비밀/해시 미출력.
- `POST /{id}/invites {role,expires_hours,max_uses}` →201 `{invite,token}`. 역할 admin/editor/member/viewer, admin 초대 owner만. 1~168시간·1~100회·유효초대 최대100. 토큰 creation에 한 번만 출력, DB SHA256 해시.
- `DELETE /{id}/invites/{uuid}` → `{ok:true}`.
- `POST /api/product-spaces/join {token}` → `{space}`. 비밀은 JSON 입력만. 보관 공간/취소/만료/소진/발급자 비활성·탈퇴·권한상실은404. 공간→초대 잠금 아래 횟수 확인/멤버십 생성. 이미 활성 멤버면 역할 변경/횟수 증가 없음. 기존 inactive 멤버 재가입은 현재 초대 역할로 복구.

## 홈 설정

- `GET/PATCH /api/me/home-dashboard` 및 `GET/PATCH /api/product-spaces/{id}/dashboard`.
- GET `{layout,revision}`; 저장하지 않은 홈 revision0, GET 쓰기0. PATCH `{layout,revision}`→ 새 revision; 동시 저장409 `reason:revision_conflict`.
- layout `{version:1,widgets:[{id,visible:boolean,size:wide|small}],source:all|files|pages,view:recent|favorites}`. 개인 IDs recent/services/tools/stories 정확4, 공간 notice/task/event/file/story 정확5, 중복/추가/누락/임의 소스 거부,4KiB 상한. 공간 홈 editor 이상 편집(전역 쓰기 자격), 개인 홈 본인만(guest 취향 저장 가능), 역할로 보기 저장 금지.
- 회사 초기 순서 notice/task/file/event/story, 모임 event/task/notice/story/file. 샘플 자료 생성0.
- 자유 배치 v2: root `{version:2,widgets,source,view}`, 각 위젯 정확히 `{id,visible,desktop,mobile}`, 양 배치 정확히 `{x,y,w,h}` 정수. IDs/visible/source/view는 v1과 동일. desktop 폭1200·w240..1200, mobile 폭360·w160..360, x/y≥0, h160..2000, x+w≤해당 폭, y+h≤20000. JSON8KiB 상한, 추가/누락/중복/잘못된 형식 거부. 기존 v1은4KiB 기준으로 계속 허용. GET 자동변환/쓰기0; 사용자 저장으로만 v2 전환. 배치 UI는 760px 미만에서 mobile, 그 외 desktop을 사용한다. x/w는 폭 비례, y/h는 CSS px. 상세 docs/freeform-dashboard-directive.md.

## 실제 기록과 위젯

- `GET /{id}/items?kind=notice|task|event|file|story` → `{items,truncated}`, 최근 수정/UUID 순서100행. `GET /{id}/items/{uuid}`→ `{item}`.
- `POST /{id}/items {kind,title,body?,status?,assignee_id?,due_at?,starts_at?,ends_at?,file_id?}` →201 `{item}`.
- `PATCH /{id}/items/{uuid}` 같은 partial+`revision` → `{item}`. kind 변경 금지, stale revision409. `DELETE` → `{ok:true}` 논리 삭제, 원본 파일 삭제0.
- item `{id,space_id,space_name,kind,title,body,status:todo|doing|done,assignee_id,due_at,starts_at,ends_at,file_id,revision,created_by,created_at,updated_at,can_edit,can_delete,download_url}`. 날짜 ISO8601, 입력 local 날짜/시각은 Asia/Seoul. 제목200자/본문20,000자 plain text, 공간10,000행. event 시작 필수/종료≥시작, task 담당자는 같은 공간 활성 비viewer·활성 전역 쓰기 자격 계정.
- viewer 읽기만, member 본인 task/event/story/file 기록 작성·편집·삭제, editor 이상 공지 작성/모든 기록 편집. 전역 Guest/역할로 보기 쓰기 불가. 보관 공간 읽기만, owner 복구와 멤버 삭제/자기 탈퇴 관리 예외.
- file 기록은 기존 file UUID 명시 연결. 원본 `Authz` 판정은 목록 SQL/단건/다운로드마다 재검사, 권한 잃은 파일은 제목·본문·ID 포함 전체 링크 응답에서 제외. 링크를 추가해도 파일 원본 공유 범위를 넓히지 않는다.
- `GET /{id}/items/{uuid}/download`: 공간 멤버십+현재 원본 읽기 권한 뒤 기존 다운로드 scan/exec/integrity/감사/stream 처리 재사용.
- `GET /{id}/home` → `{space,layout,revision,widgets:{notice:[],task:[],event:[],file:[],story:[]},truncated:{...}}`, 각6행, 기존 행 권한과 원본파일 권한.
- `GET /api/me/product-home` → 개인 layout/revision+위 widgets, 활성 본인 멤버십 공간만. 보관/탈퇴 제외, 전역Admin 특별열람 없음.

## 보존과 한계

새 표6개만 추가, 기존 DB owner/ACL/자료 범위 변경 없음. 기존 커뮤니티 DB뷰 dashboard 유지. 원본 파일 링크는 기존 권한도 필요하므로 공간 멤버라고 자동 내려받지 못한다. 현재 공간 이야기/업무/일정은 독립 plain-text 기록이며 기존 커뮤니티 데이터 자동 이동·사진 업로드·AI 권한 연동은 이 API가 제공하지 않는다.


## 콘텐츠 위젯 v3 — 구현 계약 (2026-10-11)

기존 v1/v2 읽기·저장을 보존한다. v3 root는 `version,widgets,source,view,guide`이며 guide=`pending|later|done`. widget는 고유 안전 ID, type, 검증한 source, visible/locked boolean, desktop/mobile 정수 geometry와 `size=s|m|l`만 저장한다. 20개/32KiB 상한, visible locked 앵커끼리 겹침 거부. 개인 첫7종 및 레거시4종, 공동5종의 타입과 출처 allowlist는 docs/dashboard-widgets-directive.md를 따른다. 공동 출처는 현재 공간+all로 고정한다. 개인 공간 출처는 저장 시 멤버십·활성 상태를 확인하고 콘텐츠 요청은 다시 권한을 검사한다. GET은 기본값을 제공해도 DB에 쓰지 않는다.

`GET /{id}/items?view=dashboard&kind=notice|task|event|file|story&assignee=me|all&limit=1..8`은 별도 읽기 표현이다. 기본 목록 동작은 유지한다. task는 완료 제외 후 마감 우선, event는 종료된 일정 제외 후 진행 중·가까운 시작 순으로 SQL에서 정렬한 다음 LIMIT+1을 적용한다. null 종료 시각은 당일 시작 항목까지만 기본 목록에 남긴다(Asia/Seoul). 응답 `items,truncated,as_of`는 표시 범위이며 전체 건수로 부르지 않는다.


공동 홈의 새 렌더러는 `/home?view=layout`을 사용한다. 이 선택형 조회는 기존 멤버십과 layout revision 검증을 거친 `space,layout,revision` 및 빈 `widgets,truncated` 객체만 반환한다. 콘텐츠5종 SQL을 수행하지 않으며 표시된 각 위젯만 `/items`로 읽는다. 쿼리 없는 기존 `/home`은 기존 응답을 유지한다. 잘못된 view는400. 따라서 레거시 배치를 읽는 새 브라우저도 숨김 위젯 때문에 불필요한 콘텐츠 조회를 하지 않는다.


## 개인 홈 상단 바로가기
개인 v3 root에 선택 `shortcuts` 배열을 허용한다. 값은 `spaces,work,tasks,library,story,messages,assistant,account` 중 중복없는 문자열 최대8개. 없음은 UI 기본 `spaces,work,library,tasks,messages,assistant`, 명시 `[]`는 전체숨김, 배열순서는표시순서다. URL/이름/아이콘은저장하지않는다. 공동v3 및v1/v2에는키존재자체를400거부. 기존개인GET/PATCH의계정격리·CSRF·acting거부·revision409·감사는그대로. GET은기존값에필드를강제추가하지않고DB쓰기0.

개인v3 PATCH의shortcuts생략은현재저장된shortcuts가있으면revision잠금안에서보존한다. 기본복원은기본배열을명시한다. 기존shortcuts가있는v3를v1/v2로내리는요청은409 layout_version_conflict(새로고침필요); 기존v1/v2설정계약은그대로다. 원본JSON객체{}와배열[]를구별하며객체는400거부한다.
