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
