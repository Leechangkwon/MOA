# F-44 설계 초안 — 구글 드라이브 · OneDrive/SharePoint 계정 연결(OAuth)로 회사 문서 가져오기

> **철회(2026-09-15, 사용자 결정)**: 「OAuth 는 빼자. 전체 공유된 링크만 지원하면 인증이 필요 없다.」 이 문서는 참고용으로만 남긴다. F-43 의 「이음새」(connector 종류·고정 호스트·준비 중 절)도 만들지 않는다. 다시 필요해지면 0장(회사 앱 등록)부터 시작한다.

작성 2026-09-15 (Fable). 사용자 결정: 「F-44 가 안 되면 F-43 도 의미 없다 · 회사는 구글·마이크로소프트 둘 다 쓴다 · 지금 진행」. 이 문서는 **설계 초안**이며, 회사 쪽 준비(아래 0장)가 확인되면 지시서로 바꾼다.

## 0. 회사 쪽 준비 — 코드로 대신할 수 없는 것 (사용자가 확인·요청)

| 항목 | 구글 워크스페이스 | 마이크로소프트 365 |
|---|---|---|
| 앱 등록 | Google Cloud 프로젝트에 OAuth 클라이언트(웹) 생성. **워크스페이스 관리자**가 「내부 앱」으로 승인하면 동의 화면 심사가 필요 없다 | Entra ID(Azure AD) 앱 등록. **테넌트 관리자**의 admin consent |
| 요청 권한(최소) | `https://www.googleapis.com/auth/drive.readonly`(또는 `drive.file` — 사용자가 고른 파일만. 링크 붙여넣기 방식이면 readonly 가 필요) | `Files.Read`(내 OneDrive) · SharePoint 문서까지면 `Sites.Read.All`(관리자 동의 필수) · `offline_access` |
| 리다이렉트 URI | `https://changkwon.mso.so/oauth/google/callback` | `https://changkwon.mso.so/oauth/microsoft/callback` |
| 우리에게 필요한 값 | client_id · client_secret | tenant_id · client_id · client_secret |
| 보관 | `.env`(비밀) — 저장소에 넣지 않음 | 같음 |

두 개 다 회사 관리자 권한이 필요하다. **이 준비가 안 되면 F-44 는 착수할 수 없다** — 그 판단을 먼저 받는다.

## 1. 동작 (사용자 눈높이)

1. 「내 계정」에 「클라우드 계정 연결」 절: 「구글 연결」「마이크로소프트 연결」 버튼. 누르면 해당 로그인 화면 → 동의 → t-box 로 돌아옴. 연결 상태·연결 해제 버튼.
2. 홈 「URL 로 가져오기」에 드라이브·SharePoint·OneDrive 링크를 붙이면, 서버가 링크에서 공급자·파일 ID 를 뽑아 **그 사용자의 연결 토큰으로 API 를 불러** 파일 내용을 받는다(공개 여부 무관). 받은 내용은 지금과 같은 업로드 검사(형식·크기·민감정보)를 지난다.
3. 구글 문서·시트·슬라이드는 docx·xlsx·pptx 로 내보내 받는다(구글 API export). 연결이 없으면 「먼저 계정을 연결하세요」 안내.

## 2. 서버 설계

- **표** `cloud_connections(id, user_id, provider CHECK IN('google','microsoft'), account_email, access_token_enc, refresh_token_enc, expires_at, scopes, created_at, updated_at, revoked_at)` — 토큰은 **AES-256-GCM** 으로 암호화(키는 `.env` `CLOUD_TOKEN_KEY`, 32바이트), 한 사용자당 공급자별 1행. 앱 계정 SELECT·INSERT·UPDATE(DELETE 없음, revoked_at).
- **라우트**: `GET /oauth/{provider}/start`(authenticated, state = 서명된 난수+user_id, PKCE) · `GET /oauth/{provider}/callback`(authenticated, state 검증, code→token 교환은 서버가 `Fetch` 계층으로) · `DELETE /api/cloud/{provider}`(연결 해제, 공급자 revoke 호출 시도) · `GET /api/me` 에 `cloud:{google:bool, microsoft:bool}`.
- **외부 요청**: `src/Fetch.php` 의 `CONNECTOR_HOSTS`(F-43 자리)에 `oauth2.googleapis.com`·`www.googleapis.com`·`login.microsoftonline.com`·`graph.microsoft.com` 고정. 이 호스트로의 요청만 토큰을 실을 수 있고, 사용자 URL 가져오기 경로와는 **다른 함수**(`Fetch::connector()`)를 쓴다 — 사용자 주소에 토큰이 붙는 경로를 만들지 않는다(S-05).
- **가져오기 흐름**: `CloudLink::parse()` → {provider, file_id, export?} → 연결 있음? → API 호출(구글 `files/{id}?alt=media` 또는 `export`, MS `drives/{drive}/items/{id}/content` 또는 공유 링크 인코딩 `shares/{u!…}/driveItem/content`) → 스트리밍으로 임시 파일(100MB 상한) → `Upload::validate` → 저장. 감사 `file.fetch` detail 에 provider·file_id·hops 0.
- **토큰 갱신**: 만료 60초 전이면 refresh. 실패(해지·비밀 변경)면 revoked_at 기록 + 「다시 연결」 안내.
- **경보** AL-19: 한 사용자가 1시간에 연결 N회 이상 / 토큰 갱신 연속 실패.

## 3. 새 위험 (SECURITY 후보)

- **R-78 토큰 보관**: 서버가 외부 계정의 읽기 토큰을 쥔다. DB 유출 시 암호화 키(.env)가 함께 새지 않으면 토큰은 읽히지 않는다. 키 교체 절차 필요. 연결 해제 시 공급자 revoke 호출.
- **R-79 state/PKCE**: 콜백 위조·CSRF. state 는 서명 + 세션 귀속 + 10분 만료, PKCE S256.
- **R-80 범위 과다**: `drive.readonly`·`Sites.Read.All` 은 「그 사용자가 볼 수 있는 모든 문서」다. 가져오는 것은 사용자가 붙인 링크 하나지만 토큰 능력은 넓다. 대안 `drive.file`(구글 파일 선택기 UI 필요)은 링크 붙여넣기와 맞지 않아 1차는 readonly 로 두고 위험 수용 여부를 결정한다.
- **S-05 보충**: 서버가 나가는 곳이 「관리자가 넣은 허용 호스트」 + 「고정 커넥터 호스트 4개」로 늘어난다. 둘은 코드에서 다른 함수·다른 감사 문장.

## 4. 크기와 순서

1. 회사 관리자에게 앱 등록 요청(0장 표의 값 3~4개) — **지금 요청 가능**. 등록에 며칠 걸릴 수 있다.
2. 그 사이 F-43 완료(허용 목록 관리·공개 링크·통제된 리다이렉트).
3. 값이 오면 F-44 지시서(이 문서 → 「수정 파일·완료 기준」 형식) 작성 → 구현 2일 → 보안 검토 반나절(R-78~80 실측: 토큰 암호문 확인·state 위조 400·해지 뒤 401 처리·범위 확인).

## 5. 질문(사용자)

- SharePoint(팀 사이트) 문서도 대상인가, OneDrive 개인 문서만인가 → `Sites.Read.All` 필요 여부.
- 연결은 사용자 개인 계정마다 하는가(권장), 아니면 회사 공용 서비스 계정 하나로 하는가(감사의 「누가」가 흐려져 비권장).
