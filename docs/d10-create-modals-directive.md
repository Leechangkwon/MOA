# 지시서 — D-10: 화면에 펼쳐진 「추가·만들기」 폼을 화면 머리 단추 → 모달로

CLAUDE.md 2장 형식. 작성 Fable 2026-09-22. 구현 Opus.

```
목표        : 가져오기 허용 사이트의 「목록에 추가」 폼과 계정 관리의 「계정 만들기」 폼을 표 아래에서 떼어,
              화면 머리의 단추(「사이트 추가」 「계정 만들기」)가 여는 모달 안으로 옮긴다. 파일 화면의 「새 폴더」 와 같은 꼴.
              동작(검증·요청·목록 갱신)은 그대로.
요구사항    : DESIGN.md 2.7(D-10 — 만들기 폼은 모달, 폼과 안내 문장은 모달 안) · 2.1(화면 머리: ghost 도움말 → outline → 검정 하나)
              · 2.5(폼) · 6장 체크리스트, PRD S-03 — 해당 절을 읽고 시작할 것. CSS 는 고치지 않는다(.modal.modal--form, .modal__head/__body/__foot,
              .preview__mark, .preview__headtext, .form.form--grid, .field--full, .notice__list 전부 있음).
수정 파일   : src/handlers/admin_fetch_hosts_page.php, src/handlers/users_page.php, public/assets/app.js  (이 셋만)
금지 패턴   : 서버·라우트·정책·요청 본문 변경, innerHTML, 인라인 script/style/on*, **기존 id 변경**(새 id 는 아래 것만 추가),
              폼 검증·에러 문구 변경(아래 「빈 표 문구」 한 곳만 예외)
완료 기준   : 아래 「확인」 1~6 실행, 원문 첨부. security_lint 0건. ui_snapshot 1440 결함 이전과 같음·console 0(/admin/fetch-hosts·/users 포함).
보고 형식   : 변경 줄 before → after / 명령과 출력 원문 / 못 한 것 / 판단이 필요했던 것
```

## 본보기 — `home.php` 의 `#folder-dialog`

```html
<dialog id="…-dialog" class="modal modal--form" aria-labelledby="…-dialog-title">
  <div class="modal__head">
    <span class="preview__mark"><span class="icon" data-icon="plus"></span></span>
    <div class="preview__headtext">
      <h2 class="modal__title" id="…-dialog-title">사이트 추가</h2>
      <div class="modal__meta"><span>…한 줄 설명…</span></div>
    </div>
    <button id="…-dialog-x" class="btn btn--icon" type="button" aria-label="닫기"><span class="icon" data-icon="x"></span></button>
  </div>
  <div class="modal__body">
    <form id="(기존 폼 id 그대로)" class="form form--grid" …>(기존 칸 그대로)</form>
    (안내 문장 목록은 폼 아래, 모달 본문 안)
  </div>
  <div class="modal__foot">
    <p id="(기존 msg id)" class="msg" role="alert" aria-live="polite"></p>
    <button id="…-dialog-close" class="btn btn--quiet" type="button">닫기</button>
    <button class="btn" type="submit" form="(폼 id)">(기존 제출 단추 — 폼 밖이므로 form 속성으로 연결)</button>
  </div>
</dialog>
```
- 제출 단추가 폼 밖 `.modal__foot` 으로 나가므로 `form="<폼 id>"` 를 붙인다(C-2 재인증 모달과 같은 방식). 단추의 **기존 id 는 유지**한다.
- `.msg` 는 `.modal__foot` 첫 자식(`margin-right: auto` 규칙이 받는다).
- 화면 머리 `.page-head__actions` 는 **ghost 「사용 방법」 → 검정 새 단추** 순서(검정은 하나).

## A. 가져오기 허용 사이트 `admin_fetch_hosts_page.php`

1. `.page-head__actions` 에 「사용 방법」 뒤 `<button id="fetch-hosts-add-open" class="btn" type="button" aria-haspopup="dialog" aria-controls="fetch-hosts-add-dialog"><span class="icon" data-icon="plus"></span>사이트 추가</button>`.
2. 표 아래의 `<form id="fetch-hosts-add">…</form>` 와 `<ul id="fetch-hosts-rules" class="notice__list muted">` 를 **잘라서** 새 `<dialog id="fetch-hosts-add-dialog" class="modal modal--form" aria-labelledby="fetch-hosts-add-title">` 의 `.modal__body` 로 옮긴다(본보기 구조). 제목 「사이트 추가」, 메타 「정확한 호스트 이름만, https 로만 나갑니다」. `#fetch-hosts-add-btn`(「목록에 추가」, `type="submit"`)은 `.modal__foot` 으로 옮기고 `form="fetch-hosts-add"` 를 붙인다. `#fetch-hosts-add-msg` 도 `.modal__foot` 첫 자식으로.
3. 기존 `#fetch-hosts-dialog`(변경 확인 모달)와 「변경 미리보기」·「저장」「되돌리기」 는 그대로 — 화면에는 표 · 미리보기 · 저장 줄만 남는다.
4. **빈 표 문구 한 곳 수정(허용)**: `#fetch-hosts-empty` 의 「아래에서 추가하세요.」 → 「오른쪽 위 「사이트 추가」 로 넣으세요.」
5. `app.js`(허용 사이트 화면 초기화 함수, `addForm = document.getElementById('fetch-hosts-add')` 부근): `#fetch-hosts-add-open` 클릭 → `dialog.showModal()` + `#fetch-hosts-new` 포커스; `#fetch-hosts-add-dialog-x` · `#fetch-hosts-add-dialog-close` → `dialog.close()`; 폼 제출이 **성공**해 미리보기에 항목이 들어가면 `dialog.close()` 하고 `#fetch-hosts-add-open` 으로 포커스를 돌린다(실패 시 모달은 열린 채 `.msg` 에 문구 — 지금 동작 그대로). 규칙 목록(`#fetch-hosts-rules`)을 채우는 코드는 요소 위치와 무관하니 그대로.

## B. 계정 관리 `users_page.php`

1. `.page-head__actions` 에 「사용 방법」 뒤 `<button id="user-create-open" class="btn" type="button" aria-haspopup="dialog" aria-controls="user-create-dialog"><span class="icon" data-icon="plus"></span>계정 만들기</button>`.
2. `<section class="panel">` 「계정 만들기」 패널 전체를 지우고, 그 안의 `<form id="user-create-form" …>` 를 새 `<dialog id="user-create-dialog" class="modal modal--form" aria-labelledby="user-create-title">` 의 `.modal__body` 로 옮긴다. 제목 「계정 만들기」, 메타 「Guest 는 만료일이 필수 · 비밀번호는 본인이 첫 로그인에서 정합니다」. `#u-submit`(「만들기」)은 `.modal__foot` 으로 옮기고 `form="user-create-form"`. 계정 만들기 결과 문구를 넣는 `.msg` 가 폼 안에 있으면 `.modal__foot` 첫 자식으로; 없고 화면 공통 `#msg` 를 쓴다면 그대로 둔다(보고에 적는다).
3. 화면 위 `.help` 두 줄은 그대로(화면 규칙 설명이지 폼 안내가 아니다).
4. `app.js`(`form = document.getElementById('user-create-form')` 부근): `#user-create-open` 클릭 → `showModal()` + `#u-username` 포커스; ✕ · 「닫기」 → `close()`; 만들기 **성공**(임시 비밀번호를 한 번 보여 주는 흐름이 있으면 그 표시가 끝난 뒤, 없으면 목록 갱신 직후) 에 `close()` 하고 `#user-create-open` 으로 포커스. 실패는 모달 열린 채 문구.

## 확인 (검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"` · **실제로 계정을 만들거나 사이트를 저장하지 않는다** — 「목록에 추가」 는 미리보기까지만, 「만들기」 는 검증 실패(빈 사유)까지만)

1. `php -l` 두 파일 · `grep -nE 'onclick|style=|<script>'` 세 파일 주석·기존 제외 신규 0 · `grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write' public/assets/app.js` → 0 · 기존 id 전부 존재: `grep -c 'id="fetch-hosts-add"\|id="fetch-hosts-new"\|id="fetch-hosts-new-note"\|id="fetch-hosts-add-btn"\|id="fetch-hosts-add-msg"\|id="fetch-hosts-rules"' src/handlers/admin_fetch_hosts_page.php` → 6, `grep -c 'id="user-create-form"\|id="u-username"\|id="u-role"\|id="u-team"\|id="u-expires"\|id="u-reason"\|id="u-submit"' src/handlers/users_page.php` → 7
2. `/admin/fetch-hosts`(로그인+재인증): 화면에 `#fetch-hosts-add` 가 `dialog` 안에 있고 열리기 전엔 보이지 않음(`offsetParent === null`), `.page-head__actions` 자식이 [ghost 사용 방법, 검정 사이트 추가] 순. 「사이트 추가」 클릭 → 모달 열림·`#fetch-hosts-new` 포커스 → 호스트 `example.invalid` 메모 `시험` 「목록에 추가」 → 모달 닫힘·미리보기에 1건·포커스가 「사이트 추가」 단추 → 「되돌리기」 로 비움(저장 안 함). 호스트 빈 채 제출 → 모달 열린 채 `.msg` 문구. 스크린샷 두 장(모달·닫힌 뒤)
3. `/users`: 「계정 만들기」 패널이 없고 표가 바로 옴, 머리 단추 순서 같음. 클릭 → 모달·`#u-username` 포커스 → 아이디만 넣고 사유 빈 채 「만들기」 → 모달 열린 채 오류 문구(HTML required 또는 서버 400 — 어느 쪽인지 적는다) → 「닫기」. 스크린샷 두 장
4. 규칙 목록 `#fetch-hosts-rules` 가 모달 안에서 채워짐(li 수 > 0)
5. `scripts/security_lint.sh` 0건 · `scripts/ui_snapshot.sh <폴더> 1440` 이전과 같음, console 0 · `UI_PAGES="/admin/fetch-hosts,/users" …` console 0, clipped 0
6. Esc 로 두 모달이 닫히고, 닫힌 뒤 `dialog[open]` 0

## 하지 않는 것

- CSS 수정(필요하면 보고). 서버·요청 본문·검증 문구 변경. 실제 저장·계정 생성.
- 다른 화면의 폼(내 계정 비밀번호 변경·감사 필터·역할로 보기·권한 매트릭스 사유 칸)은 대상이 아니다(DESIGN 2.7).
