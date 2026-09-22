# 지시서 — D-10 (2차): 남은 「화면에 펼쳐진 폼」 셋을 단추 → 모달로

CLAUDE.md 2장 형식. 작성 Fable 2026-09-22. 구현 Opus. **착수 조건: d10(1차) 이 끝난 뒤**(같은 app.js).

```
목표        : ① 내 계정의 「프로그램 토큰」 발급 폼 ② 내 계정의 「2단계 인증 끄기」 폼 ③ 권한 매트릭스 편집의 「사유·인증 코드·적용」 폼을
              표·패널 아래에서 떼어, 단추가 여는 모달로 옮긴다. 동작(검증·요청·목록 갱신·비밀번호+OTP 확인)은 그대로.
요구사항    : DESIGN.md 2.7(D-10 — 만들기·추가 폼과 위험 동작 확인은 모달) · 2.1(위험 동작 .btn--danger, 화면·패널마다 검정 하나)
              · 2.5 · 6장, PRD F-66(토큰 발급은 요청마다 비밀번호+OTP) · F-25(매트릭스 적용은 사유+OTP) · S-03. 서버·라우트·정책 무변경.
              CSS 는 고치지 않는다.
수정 파일   : src/handlers/settings_page.php, src/handlers/admin_permissions_page.php, public/assets/app.js  (이 셋만)
금지 패턴   : 서버·요청 본문·검증 문구 변경, innerHTML, 인라인 script/style/on*, 기존 id 변경(새 id 는 아래 것만), 실제 발급·끄기·적용 수행
완료 기준   : 「확인」 1~6 실행, 원문 첨부. security_lint 0건. ui_snapshot 1440 결함 이전과 같음·console 0(/settings·/admin/permissions 포함).
보고 형식   : 변경 줄 before → after / 명령과 출력 원문 / 못 한 것 / 판단이 필요했던 것
```

본보기·구조는 d10 1차와 같다(`dialog.modal.modal--form` · `.modal__head`(마크 + 제목 + 메타 + ✕) · `.modal__body`(기존 `<form>` 그대로) · `.modal__foot`(`.msg` · 「닫기」 · 제출 단추 `form="…"`)). 기존 id 는 옮기기만 한다.

## ① 프로그램 토큰 발급 — `settings_page.php` `#token-issue-form` (F-66 이 만든 절)

- 「프로그램 토큰」 패널 머리(`.panel__head`) 오른쪽에 단추: `<button id="token-issue-open" class="btn" type="button" aria-haspopup="dialog" aria-controls="token-issue-dialog" hidden><span class="icon" data-icon="plus"></span>토큰 발급</button>`. 패널 머리를 「제목·설명 | 단추」 가로 배치로 만들어야 하면 기존 `.org-detail__head`(flex, space-between) 를 감싸 쓴다 — 새 CSS 없이.
- `#token-issue-form`(hidden 속성 포함, 지금 JS 가 발급 가능 여부로 켜고 끈다) 을 새 `<dialog id="token-issue-dialog" class="modal modal--form" aria-labelledby="token-issue-title">` 의 `.modal__body` 로 옮긴다. 제목 「토큰 발급」, 메타 「값은 발급할 때 한 번만 보이고 180일 뒤 만료됩니다」. `#token-issue`(「토큰 발급」 제출) 는 `.modal__foot` 으로, `form="token-issue-form"`. `#token-issue-msg` 는 `.modal__foot` 첫 자식.
- **JS**: 지금 `#token-issue-form` 의 `hidden` 을 켜고 끄는 코드는 그대로 두되 **같은 조건으로 `#token-issue-open` 의 `hidden` 도** 켜고 끈다(발급 불가 — 2단계 꺼짐·상한 — 이면 단추도 숨고 `#token-issue-blocked` 안내만 보인다). 단추 클릭 → `showModal()` + `#token-name` 포커스. ✕·「닫기」 → `close()`. 발급 **성공**은 토큰 값을 한 번 보여 주는 흐름이 있다 — 그 표시가 **모달 안**에서 이뤄지면 그대로 두고, 패널 안 다른 요소에 표시되면 `close()` 뒤 그 요소로 포커스. 실패는 모달 열린 채 `.msg`.

## ② 2단계 인증 끄기 — `settings_page.php` `#mfa-disable-form`

- 2단계 인증 패널의 상태 문장(`#mfa-state`) 옆·아래에 단추: `<button id="mfa-disable-open" class="btn btn--danger" type="button" aria-haspopup="dialog" aria-controls="mfa-disable-dialog" hidden>2단계 인증 끄기</button>` — 지금 `#mfa-disable-form` 의 `hidden` 을 켜고 끄는 조건(켜져 있을 때만)으로 이 단추도 켜고 끈다.
- `#mfa-disable-form` 을 `<dialog id="mfa-disable-dialog" class="modal modal--form" aria-labelledby="mfa-disable-title">` 의 `.modal__body` 로. 제목 「2단계 인증 끄기」, 메타 「끄면 로그인에 코드를 묻지 않습니다 — 관리자 권한 계정은 끌 수 없습니다」(기존 안내 문장이 있으면 그것을 그대로 쓴다). `#mfa-disable`(제출, `.btn--danger`) 은 `.modal__foot` 으로, `form="mfa-disable-form"`. 관련 `.msg` 가 있으면 `.modal__foot` 첫 자식.
- **JS**: 클릭 → `showModal()` + `#mfa-off-password` 포커스; ✕·「닫기」 → `close()`; 끄기 **성공** → `close()` 뒤 상태 문장 갱신(지금 코드 그대로) 및 `#mfa-state` 로 포커스. 실패는 모달 열린 채 `.msg`.

## ③ 권한 매트릭스 적용 — `admin_permissions_page.php` `#perm-form`

- 화면에는 「변경 미리보기」 목록(`#perm-preview`·`#perm-preview-empty`)과 단추 줄만 남긴다: `<div class="perm-form__actions"><button id="perm-apply-open" class="btn" type="button" aria-haspopup="dialog" aria-controls="perm-dialog" disabled>적용</button><button id="perm-reset" class="btn btn--quiet" type="button">되돌리기</button></div>` — `#perm-reset` 은 기존 것을 옮긴다. `#perm-apply-open` 은 바뀐 칸이 0 이면 `disabled`(지금 `#perm-submit` 을 비활성으로 두는 조건이 있으면 그것을 그대로 이 단추에).
- `#perm-form`(사유·코드) 을 `<dialog id="perm-dialog" class="modal modal--form" aria-labelledby="perm-dialog-title">` 의 `.modal__body` 로 옮기고, 그 위에 `<p id="perm-dialog-count" class="muted"></p>`(「바뀐 칸 N개를 적용합니다」 — 지금 미리보기 개수를 세는 값으로 JS 가 채운다) 를 둔다. 제목 「권한 매트릭스 변경 적용」, 메타 「사유와 인증 앱 코드가 필요합니다. 바로 적용됩니다」. `#perm-submit`(「적용」 제출) 은 `.modal__foot` 으로, `form="perm-form"`. `#perm-msg` 는 `.modal__foot` 첫 자식. 가져오기 허용 사이트의 `#fetch-hosts-dialog` 와 같은 꼴이다 — 그 화면의 코드(`fetch-hosts-save` 클릭 → 모달 열기)를 본보기로.
- **JS**: `#perm-apply-open` 클릭 → count 문구 채움 + `showModal()` + `#perm-reason` 포커스; ✕·「닫기」 → `close()`; 적용 **성공** → `close()` 뒤 표 다시 읽기(지금 코드) · 실패(invariant·bad_code·reauth) 는 모달 열린 채 `.msg`(재인증 403 은 기존 `openReauthModal` 재시도 흐름이 있으면 그대로 — 모달 위에 모달을 열지 않으려면 먼저 이 모달을 `close()` 한 뒤 재인증 모달을 띄우고, 통과 뒤 다시 이 모달을 연다 — 어느 쪽인지 보고).

## 확인 (검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"` · **실제 발급·끄기·적용은 하지 않는다**)

1. `php -l` 두 파일 · 금지 패턴 신규 0 · innerHTML 류 0 · 기존 id 전부 존재(`token-issue-form token-name token-purpose token-scope token-password token-otp token-issue token-issue-msg`, `mfa-disable-form mfa-off-password mfa-off-code mfa-disable`, `perm-form perm-reason perm-otp perm-submit perm-reset perm-msg perm-preview`)
2. `/settings`: 패널 안에 펼쳐진 폼이 없고(`#token-issue-form`·`#mfa-disable-form` 의 `offsetParent === null`), 「토큰 발급」 단추의 `hidden` 이 예전 폼의 `hidden` 과 같은 값, 클릭 → 모달·포커스 → 이름만 넣고 제출 → 모달 열린 채 오류(required 또는 서버 400 — 어느 쪽인지) → 「닫기」. 「2단계 인증 끄기」 단추(빨강) 클릭 → 모달 → 빈 채 제출 → 열린 채 오류 → 「닫기」. 스크린샷 셋(패널·토큰 모달·끄기 모달)
3. `/admin/permissions`(재인증 세션): 화면에 사유·코드 칸이 없고 「적용」 이 `disabled`; 체크 하나 바꿈 → 미리보기 1건·「적용」 활성 → 클릭 → 모달, count 문구 「바뀐 칸 1개…」, `#perm-reason` 포커스 → 사유만 넣고 코드 빈 채 제출 → 열린 채 오류 → 「닫기」 → 「되돌리기」 로 원상복구(서버에 아무것도 보내지 않음: Playwright 요청 로그에 PATCH 0). 스크린샷 둘
4. **CSRF 헤더**: ③ 에서 코드 `000000` 같은 틀린 값으로 실제 PATCH 를 **한 번** 보내(서버가 `bad_code` 로 거부 → 표 무변경) 요청 헤더에 `X-CSRF-Token` 이 실리는지 Playwright `request.headers()` 원문으로 확인. 응답 400/403 본문도 첨부. 그 뒤 「되돌리기」.
5. `scripts/security_lint.sh` 0건 · `scripts/ui_snapshot.sh <폴더> 1440` 이전과 같음, console 0 · `UI_PAGES="/settings,/admin/permissions" …` console 0, clipped 0
6. Esc 로 세 모달이 닫히고 `dialog[open]` 0

## 하지 않는 것

- CSS 수정(필요하면 보고) · 서버·요청 본문·검증 문구 변경 · 실제 발급·끄기·적용.
- 비밀번호 변경 폼(내 계정의 본래 목적), 감사 필터, 역할로 보기, 첫 로그인 관문·2단계 등록 화면은 대상이 아니다(DESIGN 2.7).

## 추가(1차 보고 후속 — 같은 지시서에서 함께)

- **A. 계정 만들기 모달 메타의 `<br>` 제거** (`users_page.php`): Fable 이 CSS 에 `.modal__head > .preview__headtext { flex: 1 1 16rem }` 를 넣었으니, 메타 문구는 한 줄 「Guest 는 만료일이 필수 · 비밀번호는 본인이 첫 로그인에서 정합니다」 로 되돌린다. 확인: 1440 에서 `.modal__head` 높이 ≤ 90px, ✕ 가 첫 줄.
- **B. 계정 만들기 서버 오류 문구 자리** (`users_page.php` · `app.js`): 새 id `user-create-msg` 로 `<p id="user-create-msg" class="msg" role="alert" aria-live="polite"></p>` 를 `#user-create-dialog .modal__foot` 첫 자식에 두고, 계정 만들기 요청의 실패 문구를 화면 공통 `#msg` 대신 **이 요소**에 쓴다(성공·닫기 때 비움). 확인: 이미 있는 아이디(`qa_admin01`)로 제출 → 서버 400/409 → 모달 안 `#user-create-msg` 에 문구, `#msg` 는 빈 채. 요청 헤더에 `X-CSRF-Token` 실림을 원문으로(이 요청은 서버가 거부하므로 운영 데이터 무변경).
- **C. 허용 사이트 「목록에 추가」 빈 호스트** (`app.js`): `host === ''` 면 조용히 `return` 하던 것을 `setText(addMsg, '호스트를 넣으세요.')` 뒤 `return` 으로(문구 추가 허용). 확인: 빈 채 제출 → 모달 열린 채 그 문구.
