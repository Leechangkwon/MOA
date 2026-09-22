# 지시서 — D-9: 관리 화면에 들어갈 때 비밀번호 재확인을 페이지 대신 모달로

CLAUDE.md 2장 형식. 작성 Fable 2026-09-22. 구현 Opus. **착수 조건: F-66 세션의 `app.js` 커밋이 끝난 뒤**(같은 파일).

```
목표        : 내비·관리자 허브 카드에서 관리 화면으로 들어갈 때, 재인증이 필요하면 지금 화면 위에 기존 재인증 모달을
              먼저 띄우고 통과하면 이동한다. 서버 관문·정책·/reauth 화면은 그대로(후퇴 경로).
요구사항    : DESIGN.md 2.7(D-9) · 7장 D-9, PRD F-23(관문은 서버 — 화면은 편의), ARCHITECTURE 4.0 재인증 관문 [3-2],
              S-01(화면은 판단하지 않는다 — reauth_fresh 는 편의값, 근거는 서버 302/403) — 해당 절을 읽고 시작할 것.
              CSS 는 고치지 않는다.
수정 파일   : public/assets/app.js  (이 하나만)
금지 패턴   : 서버·라우트·정책 변경, 요청 파라미터로 돌아갈 경로를 서버에 보내는 것, innerHTML, 인라인 script/style/on*,
              id·data-*·문구 변경, `/reauth` 화면·`openReauthModal` 의 기존 동작(403 재시도) 변경
완료 기준   : 아래 「확인」 1~6 실행, 원문 첨부. security_lint 0건. ui_snapshot 1440 결함 이전과 같음·console 0.
보고 형식   : 변경 줄 before → after / 명령과 출력 원문 / 못 한 것 / 판단이 필요했던 것
```

## 어떻게

`DOMContentLoaded` 안, 화면 분기 **밖**(셸이 있는 모든 화면에 적용)에 한 번:

```js
/* DESIGN.md 2.7 · D-9 — 관리 화면으로 가는 링크를 누르면, 재인증이 필요할 때만 여기서 모달을 먼저 띄운다.
   판단의 근거는 서버다: 모달을 건너뛰어도(주소 직접 입력·스크립트 차단) 서버 관문 [3-2]가 302 /reauth 로 보낸다.
   reauth_fresh 는 /api/me 가 준 편의값이고, 값을 고쳐도 서버가 요청마다 DB 를 다시 본다 (S-01). */
function isAdminPath(href) {
  // 라우터 고정 표(TBOX_REAUTH_PATHS)와 같은 목록 — 화면이 새 경로를 지어내지 않는다
  return /^\/(admin(\/|$)|users$|org$|audit$|trash$|wiki(\/|$))/.test(href);
}
function armReauthLinks(me) {
  if (!me || me.reauth_fresh === true) { return; }          // 이미 확인된 세션 — 그냥 이동
  document.querySelectorAll('a.nav__item[href], a.card--link[href]').forEach(function (a) {
    var href = a.getAttribute('href') || '';
    if (!isAdminPath(href)) { return; }
    a.addEventListener('click', function (ev) {
      if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) { return; }  // 새 탭 등은 그대로
      ev.preventDefault();
      openReauthModal(function () { window.location.assign(href); });   // 통과하면 이동. href 는 마크업에 적힌 값 그대로
    });
  });
}
```
- `me` 는 이미 `/api/me` 를 읽어 셸을 여는 코드(내비 `hidden` 을 푸는 곳)가 갖고 있는 객체를 그대로 넘긴다. **새 요청을 추가하지 않는다.**
- `openReauthModal(onDone)` 은 기존 함수 그대로 쓴다(성공 시 `onDone`, 「닫기」 면 아무 일 없음 — 그 자리에 남는다). `<dialog>` 가 없는 브라우저는 함수가 이미 `/reauth` 로 보낸다.
- 재인증 통과 뒤 이동은 `window.location.assign(href)` — 서버가 그 요청을 다시 판정한다(모달이 권한을 만들지 않는다).
- 이미 관리 화면 안에서 다른 관리 화면으로 가는 링크는 `reauth_fresh === true` 라 걸리지 않는다.

## 확인 (검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"`)

1. `grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write' public/assets/app.js` → 0 · `grep -n 'fetch(' public/assets/app.js | wc -l` 이 변경 전과 같다(새 요청 0)
2. Playwright 1440: **새 로그인**(reauth 없음) 뒤 `/` 에서 내비 「관리자 페이지」 클릭 → 주소가 `/` 그대로이고 `dialog[open].modal--form` 이 열림, 제목 「비밀번호 다시 확인」. 「닫기」 → 모달 닫히고 주소 `/` 그대로. 다시 클릭 → 비밀번호 입력 「확인」 → 주소 `/admin`, 200. 스크린샷 두 장(모달·이동 뒤)
3. 같은 세션에서 `/admin` 의 허브 카드 「계정 관리」 클릭 → 모달 없이 바로 `/users`(reauth_fresh true)
4. Ctrl+클릭(새 탭)은 모달을 띄우지 않고 브라우저 기본 동작 — `ev.ctrlKey` 경로 확인(새 페이지가 302 → `/reauth?to=…` 로 가는 것이 정상)
5. 주소창에 직접 `/users` 입력(새 로그인 세션, curl `-L` 없이) → 여전히 `302 Location: /reauth?to=users` — 서버 관문 무변경
6. `scripts/security_lint.sh` 0건 · `scripts/ui_snapshot.sh <폴더> 1440` 이전과 같음, console 0 · `UI_PAGES="/reauth" …` console 0(후퇴 경로 화면 그대로)

## 하지 않는 것

- 서버·라우트·`/reauth` 화면 변경(후퇴 경로로 유지). CSS 변경. 새 API 요청.
- PRD F-23·ARCH 4.0 의 문구 보충은 Fable 이 한다.
