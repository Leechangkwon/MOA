import { createDeferredEditor, createModuleImporter, createModuleLoader } from './editor-loader.js?v=a9fddd18fefd';
import { bindMySuggestions } from './suggestions.js?v=a9fddd18fefd';
import { mentionTokens } from './mention-input.js?v=a9fddd18fefd';
import { bindTemplateGallery } from './templates.js?v=a9fddd18fefd';
import { bindEmbedAdmin } from './embeds.js?v=a9fddd18fefd';
import { bindPopular } from './popular.js?v=a9fddd18fefd';
import { bindPalettes } from './palettes.js?v=a9fddd18fefd';
import { bindAutomations, drawAutomationSpace } from './automations.js?v=a9fddd18fefd';
import { bindProcedures } from './procedures.js?v=a9fddd18fefd';
import { createDrafts } from './drafts.js?v=a9fddd18fefd';
import { bindAiCenter } from './ai.js?v=a9fddd18fefd';
import { bindIntegrations } from './integrations.js?v=a9fddd18fefd';
import { createWork } from './work.js?v=a9fddd18fefd';
import { createCafe, personInto } from './cafe.js?v=a9fddd18fefd';
/**
 * public/assets/community/editor.js — 커뮤니티 화면의 동작 한 벌 (모듈 스크립트)
 *
 * 요구사항: C:F-15 · C:F-16 · C:F-17(최소) · C:S-11 · C:S-12,
 *           ../../../../community/ARCHITECTURE.md 12.4 · 13.3 · 14장 · 21.3,
 *           DESIGN.md 2.7 · 2.14 · 6장,
 *           ../../../../community/stage3b-editor-rework-directive.md (사용자 결정 2026-09-23).
 *
 * 다루는 화면 둘 (서버가 `<body data-page>` 로 알려 준다 — app.js 는 이 두 키를 무시한다)
 *   community-home  내 공간 — 목록·찾기·새 페이지
 *   community-page  페이지 — 읽기(렌더러) ↔ 편집(ProseMirror), 자동 저장, 409 안내, 삭제
 *
 * 2026-09-23 다시 짠 것 (관문 ③ 에서 사용자가 만난 것들)
 *   · **문자 꾸미기(굵게·기울임·밑줄·취소선·인라인 코드)를 만들지 않는다.** 툴바는 「색」
 *     「배경」 「링크」 셋뿐이고 Ctrl+B 같은 단축키도 등록하지 않는다. PM 스키마의 마크는
 *     그대로 두어(읽기 호환) **에디터가 만들지 않을** 뿐이다.
 *   · **`/` 슬래시 메뉴를 없앴다.** 블록은 「블록 추가」 창(`<dialog id="block-dialog">`)으로 넣는다.
 *     `/` 는 그냥 글자로 들어간다.
 *   · **Enter·Backspace 를 우리가 짠다.** `baseKeymap` 의 기본 분리는 우리 평평한 블록 구조에서
 *     목록을 문단으로 떨어뜨렸다(사용자 보고 「목록에서 줄바꿈이 안 된다」).
 *   · **저장 경합을 번호로 막는다.** 보낸 시점의 변경 번호를 기억해 두고, 응답이 200 이어도
 *     그 뒤에 변경이 있으면 「저장됨」을 보이지 않고 한 번 더 보낸다.
 *
 * 규칙
 *   · 절대 경로는 `<body data-community-base>`(커뮤니티)와 `data-base`(t-box) 로만 만든다.
 *     이 파일에 `/community/…`·`/tbox/…` 같은 문자열 리터럴이 없다.
 *   · 요소는 `createElement`, 글자는 `textContent` 로만 만든다. 문자열을 HTML 로 바꾸지 않는다.
 *   · **화면은 판단하지 않는다.** 단추가 보이고 안 보이고는 서버가 준 `can_edit` 을 따를 뿐이고,
 *     허용 여부는 서버가 요청마다 다시 판단한다 (DESIGN 원칙 1, PRD S-01).
 *   · 저장 형식은 `editor-schema.js` 의 `toDoc`/`fromDoc` 한 통로만 지난다. 그 결과는
 *     서버 `Doc::validate` 가 다시 검사한다(서버 검증이 진실이다).
 */


// Q:F-12 D — 업무 화면(홈 · 내 작업 · 프로젝트 목록 · 작업 상세 칸 · 포트폴리오 · 「새로 만들기」). 이 파일은 고리 몇 개만 이어 준다

/* ─── 주소와 공통 도우미 ─────────────────────────────────────── */

const body = document.body;
const COMMUNITY_BASE = (body && body.getAttribute('data-community-base')) || '';
const APP_BASE = (body && body.getAttribute('data-base')) || '';
const GENERIC_ERROR = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';
/** 카페 C2 (DESIGN 2.29 「카페 셸」) — 서버가 읽기 판정을 지난 카페 게시판 · 글 화면에만 `<body data-area="cafe">` 를 단다. 표시용(판정 0) */
const IS_CAFE = !!(body && body.getAttribute('data-area') === 'cafe');
/** 카페 C2 — 카페 부품 한 벌(cafe.js) · 지금 페이지가 익명게시판 글인가(창구가 준 `board.board_kind` — 멘션 끔 · 자료실 탭 0) */
let cafe = null;
let pageIsAnon = false;

/** 상태 변경 요청에 붙일 CSRF 토큰. t-box 창구 `GET /api/me` 가 준다(같은 세션·같은 토큰). */
let csrfToken = null;

function setText(el, text) {
  if (el) { el.textContent = text === null || text === undefined ? '' : String(text); }
}

function show(el, on) {
  if (el) { el.hidden = !on; }
}

function clear(el) {
  if (!el) { return; }
  while (el.firstChild) { el.removeChild(el.firstChild); }
}

/** 응답 본문을 JSON 으로 읽는다. 아니면 빈 객체 — 화면이 사유를 지어내지 않는다. */
function readJson(response) {
  return response.text().then((raw) => {
    try { return JSON.parse(raw); } catch (e) { return {}; }
  });
}

/**
 * 5-A″ — 사람을 보이는 모양 「이름 (아이디)」(이름이 없으면 「아이디」). **모양은 한 벌**이다 —
 * t-box 셸 스크립트(app.js)의 `personLabel` 을 빌린다(셸이 이 파일보다 먼저 실행된다: app.js 는
 * `<body>` 끝의 일반 스크립트, 이 파일은 지연 실행되는 모듈). 없으면 아이디만 보인다 — 모양을
 * 여기서 다시 짓지 않는다. 넣는 쪽은 언제나 `textContent` 다.
 */
function personLabel(displayName, username) {
  const shared = typeof window.qarediPersonLabel === 'function' ? window.qarediPersonLabel : null;
  return shared ? shared(displayName, username) : String(username === null || username === undefined ? '' : username);
}

/**
 * 역할의 화면 글자 — 한글(디버깅 묶음 U M7 · DESIGN 5장). 셸(app.js)의 `window.qarediRoleLabel` 한 벌을 쓰고, 셸이 없는 날에는
 * 받은 글자 그대로다. 값(`role`)을 비교하는 코드가 아니다 — 보이는 글자만 바꾼다.
 */
function roleText(role) {
  const shared = typeof window.qarediRoleLabel === 'function' ? window.qarediRoleLabel : null;
  return shared ? shared(role) : String(role === null || role === undefined ? '' : role);
}

function errorMessage(data) {
  return data && typeof data.error === 'string' && data.error !== '' ? data.error : GENERIC_ERROR;
}

/**
 * Q:F-4 U-3 — **사람이 누르지 않은 주기 요청**(알림 폴링·편집 heartbeat)에 다는 표식. 서버 `Auth::BACKGROUND_HEADER`·
 * `BACKGROUND_VALUE` 와 같은 이름·값이고, 이 파일에서는 여기 한 곳이다. 이 표식이 달린 요청은 세션을 **확인만** 하고
 * 늘리지 않는다 — 켜 둔 탭이 「활동 없는 30분 로그아웃」을 끝없이 미루지 않게. 저장·댓글 같은 쓰기에는 달지 않는다(늘 연장).
 */
const BACKGROUND_HEADER = 'X-Qaredi-Background';
const BACKGROUND_VALUE = '1';

/** 창을 닫을 때 보내는 저장(`keepalive`)의 본문 상한 — 브라우저가 keepalive 요청에 64KiB 까지만 허락한다. 넘으면 평소대로 보낸다. */
const KEEPALIVE_MAX_BYTES = 60000;

/**
 * 사용감 라운드 1 UX-11 — 다른 탭에서 **다른 계정**으로 로그인해 이 탭을 멈췄는가. 참이면 쓰기 요청을 보내지 않는다(요청 0 ·
 * 옛 토큰으로 30초마다 쌓이던 `csrf|deny` 감사 행도 0). 새로 고치면 풀린다(이 변수는 화면 수명 동안만).
 */
let tabHalted = false;
const HALT_TEXT = '다른 계정으로 로그인되어 이 탭은 멈췄습니다 — 새로 고치세요';

/** 멈춘 까닭을 화면 알림 줄에 한 번 적는다(글자만 — 서버 요청 0) */
function haltNotice() {
  setText(document.getElementById('msg'), HALT_TEXT);
}

/**
 * 커뮤니티 창구 부르기. 경로는 코드에 적힌 모듈 경로이고 접두는 여기서 한 번만 붙는다.
 *
 * 사용감 라운드 1 UX-11 — 쓰기 요청(POST·PUT·PATCH·DELETE)이 403 이고 재인증·강제 절차(`next`)가 아니면 셸의 `qarediSessionProbe`
 * (app.js — `/api/me` no-store 를 **한 번**, 진행 중이면 그 약속을 나눠 쓴다)로 세션을 본다:
 *   · 로그인이 없다 → 401 로 돌려준다(부르는 쪽이 전처럼 로그인 흐름 — 임시저장 지킴 · 자동 요청은 조용히 그대로)
 *   · 다른 계정 → 다시 보내지 않고 이 탭을 멈춘다(알림 한 줄) · 이 뒤의 쓰기는 보내지 않는다
 *   · 같은 계정인데 토큰이 다르다(다른 탭에서 다시 로그인) → 새 토큰으로 바꾸고(셸도 `qarediSetCsrf`) **그 요청을 한 번만** 다시 보낸다.
 *     다시 보낸 것도 403 이면 그대로 실패(고리 0)
 *   · 같은 계정 · 같은 토큰 → 진짜 거부 — 받은 답 그대로(셸이 그 토큰을 기억해 같은 토큰의 다음 403 은 다시 묻지 않는다)
 * 견주는 토큰은 **그 요청이 보낸 토큰**이다(`apiSend` 가 함께 돌려준다). 그 요청이 나가 있는 사이 다른 요청의 확인이 이미 토큰을 바꿨으면
 * (보낸 토큰 ≠ 지금 토큰) 같은 계정 확인은 끝난 값이다 — `/api/me` 없이 지금 토큰으로 **한 번만** 다시 보낸다.
 * 자동 요청(`{background: true}`)이 부른 확인은 자동 요청이다 — 셸이 `/api/me` 에도 자동 요청 표식을 단다(세션 활동 시각을 밀지 않는다).
 * 막는 것은 여전히 서버다 — 토큰은 같은 출처 `/api/me` 의 답에서만 오고, 교차 사이트 요청은 그 답을 읽을 수 없다(SECURITY 7장).
 * 일괄 작업은 `{sessionRetry: false}` 로 이 길을 끄고 제 `sessionCheck`(멈춤 · 「이어서 하기」)를 그대로 쓴다.
 *
 * @param {Object} [opts]  `{background: true}` — 자동 요청 표식(U-3) · `{keepalive: true}` — 창을 닫는 중에도 끝까지 보낸다(B1-2 설계 7) ·
 *                         `{sessionRetry: false}` — 위 세션 확인·한 번 다시 보내기를 하지 않는다(일괄 작업)
 */
function api(method, path, payload, opts) {
  const write = method !== 'GET' && method !== 'HEAD';
  if (write && tabHalted) {
    return Promise.resolve({ status: 403, data: { error: HALT_TEXT } });
  }
  // 멈춘 탭의 자동 요청(알림 폴링 — 사람이 누르지 않은 것)도 보내지 않는다: 쿠키는 이미 다른 계정이라 그 계정의 알림 수가 이 탭에 그려진다
  if (tabHalted && opts && opts.background === true) {
    return Promise.resolve({ status: 0, data: {} });
  }
  // 부르는 쪽에는 상태·본문만 — 보낸 토큰은 이 함수 안에서만 쓴다
  const answer = (sent) => ({ status: sent.status, data: sent.data, retryAfter: sent.retryAfter });
  const again = () => apiSend(method, path, payload, opts).then(answer);
  return apiSend(method, path, payload, opts).then((sent) => {
    const result = answer(sent);
    // 사이트 Q:F-13 P1 — AI 국외 전송 동의가 없어 서버가 409 로 막은 요청(커뮤니티 AI): 동의 창 → 동의하면 같은 요청을 한 번만 다시(again 은 이 갈래를 지나지 않는다)
    if (write && result.status === 409 && result.data && result.data.code === 'ai_consent_required') {
      return aiConsentDialog(result.data.notice).then((agreed) => (agreed ? again()
        : { status: 409, data: { error: 'AI 국외 전송에 동의하지 않아 AI 기능을 쓸 수 없어요. 다른 기능은 그대로 쓸 수 있어요.' } }));
    }
    if (!write || result.status !== 403 || (opts && opts.sessionRetry === false)) { return result; }
    if (result.data && typeof result.data.next === 'string' && result.data.next !== '') { return result; }
    const probe = typeof window.qarediSessionProbe === 'function' ? window.qarediSessionProbe : null;
    if (probe === null || meUsername === null) { return result; }
    if (tabHalted) { return { status: 403, data: { error: HALT_TEXT } }; }
    if (sent.token !== csrfToken) { return again(); }
    return probe(meUsername, sent.token, { background: !!(opts && opts.background === true) }).then((got) => {
      if (got.why === 'fresh') {
        csrfToken = got.token;
        if (typeof window.qarediSetCsrf === 'function') { window.qarediSetCsrf(got.token); }
        return again();
      }
      if (got.why === 'login') { return { status: 401, data: {} }; }
      if (got.why === 'other') {
        tabHalted = true;
        haltNotice();
        return { status: 403, data: { error: HALT_TEXT } };
      }
      return result;
    }, () => result);
  });
}

/**
 * 사이트 Q:F-13 P1 — AI 국외 전송 동의 창(결정 PT-8). 서버가 409 `{code:"ai_consent_required", notice}` 로 막은 요청에서만 연다.
 * 글자는 서버가 준 notice(처리방침 제7조를 줄로 나눈 것 — src/Legal.php 한 곳)를 textContent 로 그린다. 「동의하고 계속하기」면
 * t-box 창구 `POST /api/me/consents {doc:"ai_overseas"}`(같은 세션·같은 CSRF 토큰)를 보내고 참 — api() 가 막혔던 요청을 한 번 다시 보낸다.
 * 닫기 · ✕ · Esc 는 거짓. 막는 것은 서버다. 구성 요소는 공용 창(dialog.modal.modal--form · DESIGN 2.7). AI 창 위에 겹쳐 뜬다 —
 * 공용 재인증 창과 같은 예외(아래 창에 적어 둔 값이 그대로 남아야 같은 요청을 다시 보낸다).
 */
function aiConsentDialog(notice) {
  return new Promise((resolve) => {
    const n = notice && typeof notice === 'object' ? notice : {};
    const el = (tag, text, cls) => { const e = document.createElement(tag); if (text !== undefined) { e.textContent = text; } if (cls) { e.className = cls; } return e; };
    const d = el('dialog', undefined, 'modal modal--form');
    const head = el('div', undefined, 'modal__head');
    const title = el('h2', typeof n.title === 'string' ? n.title : 'AI 기능 — 국외 전송 동의', 'modal__title');
    title.id = 'ai-consent-title';
    d.setAttribute('aria-labelledby', title.id);
    const x = el('button', '×', 'btn btn--icon btn--plain'); x.type = 'button'; x.setAttribute('aria-label', '닫기');
    head.append(title, x);
    const box = el('div', undefined, 'modal__body');
    box.append(el('p', 'AI 기능을 처음 쓸 때 한 번 묻습니다. 요청한 내용이 아래처럼 국외로 전송됩니다.', 'help'));
    const grid = el('div', undefined, 'info-grid');
    (Array.isArray(n.rows) ? n.rows : []).forEach((row) => {
      if (!Array.isArray(row) || typeof row[0] !== 'string' || typeof row[1] !== 'string') { return; }
      const item = el('div', undefined, 'info-item');
      item.append(el('span', row[0], 'field__label'), el('span', row[1], 'info-item__value'));
      grid.append(item);
    });
    box.append(grid);
    box.append(el('p', typeof n.note === 'string' ? n.note : '', 'help'));
    if (typeof n.policy_url === 'string' && /^\/tbox\/privacy(#[a-z0-9-]+)?$/.test(n.policy_url)) {
      const links = el('p', undefined, 'legal-links');
      const a = el('a', '개인정보 처리방침 제7조 보기'); a.href = n.policy_url; a.target = '_blank'; a.rel = 'noopener'; links.append(a); box.append(links);
    }
    const foot = el('div', undefined, 'modal__foot');
    const msg = el('p', '', 'msg'); msg.setAttribute('role', 'alert');
    const close = el('button', '닫기', 'btn btn--quiet'); close.type = 'button';
    const ok = el('button', '동의하고 계속하기', 'btn'); ok.type = 'button';
    foot.append(msg, close, ok);
    d.append(head, box, foot);
    let agreed = false;
    const shut = () => { if (d.open) { d.close(); } };
    x.addEventListener('click', shut);
    close.addEventListener('click', shut);
    ok.addEventListener('click', () => {
      ok.disabled = true; close.disabled = true; msg.textContent = '';
      fetch((APP_BASE || '/tbox') + '/api/me/consents', { method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken === null ? '' : csrfToken }, body: JSON.stringify({ doc: 'ai_overseas' }) })
        .then((r) => readJson(r).then((got) => {
          if (r.status !== 200 || !got || got.ai_overseas !== true) { msg.textContent = (got && typeof got.error === 'string') ? got.error : GENERIC_ERROR; return; }
          agreed = true; shut();
        }), () => { msg.textContent = GENERIC_ERROR; })
        .finally(() => { ok.disabled = false; close.disabled = false; });
    });
    d.addEventListener('close', () => { d.remove(); resolve(agreed); });
    document.body.append(d);
    d.showModal();
    close.focus();
  });
}

/**
 * 요청 한 번 — `api()` 만 부른다(위 세션 확인 뒤 한 번 다시 보낼 때도 이 함수).
 * 돌려주는 값: `{status, data, token}` — `token` 은 이 요청이 실제로 보낸 CSRF 토큰(쓰기만 · 읽기는 null). `api()` 가 견주는 데만 쓰고 밖으로 내지 않는다.
 */
function apiSend(method, path, payload, opts) {
  const options = {
    method: method,
    credentials: 'same-origin',
    cache: 'no-store',
    headers: {}
  };
  let token = null;
  if (opts && opts.background === true) { options.headers[BACKGROUND_HEADER] = BACKGROUND_VALUE; }
  if (method !== 'GET' && method !== 'HEAD') {
    if (csrfToken === null) { return Promise.reject(new Error('no-csrf')); }
    token = csrfToken;
    options.headers['X-CSRF-Token'] = token;
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(payload === undefined ? {} : payload);
    if (opts && opts.keepalive === true && new TextEncoder().encode(options.body).length <= KEEPALIVE_MAX_BYTES) {
      options.keepalive = true;
    }
  }
  if (opts && opts.signal) { options.signal = opts.signal; }
  return fetch(COMMUNITY_BASE + path, options).then((response) =>
    readJson(response).then((data) => ({ status: response.status, data: data, token: token, retryAfter: response.headers.get('Retry-After') }))
  );
}

/**
 * Q:F-4 B1-2 — 로그인 화면으로 **가기 전에** 부를 일(쓰던 글 임시저장). 페이지 화면이 등록한다.
 * 401 을 받은 그 순간 열쇠는 이미 메모리에 있다 — 암호화해 쓴 뒤에 떠난다(늦어도 1.5초 뒤에는 떠난다).
 */
const leaveHooks = [];
/** 일부러 떠나는 중인가 — 이때는 「저장 안 된 변경」 닫기 경고를 띄우지 않는다(임시저장이 이미 남았다) */
let leavingOnPurpose = false;

function toLogin() {
  if (leavingOnPurpose) { return; }
  leavingOnPurpose = true;
  const jobs = leaveHooks.map((fn) => {
    try { return Promise.resolve(fn()).catch(() => null); } catch (e) { return Promise.resolve(null); }
  });
  const late = new Promise((resolve) => { window.setTimeout(resolve, 1500); });
  Promise.race([Promise.all(jobs), late]).then(() => {
    window.location.assign(APP_BASE + '/login?to=' + loginReturnTag());
  });
}

/**
 * Q:F-7 B1-1 — 로그인 뒤 돌아올 곳의 꼬리표. 셸(app.js)의 `qarediReturnPath` 로 걸러 허용 목록 안이면 그 정규형(rawurlencode),
 * 아니면 옛 키 `community`. 편집 중이었으면(쓸 수 있는 본문이 열려 있으면) `?edit=1` 을 붙여 — 로그인 뒤 그 페이지가 편집 모드로 열리고
 * 임시저장 알림 줄(B1-2)이 뜬다. 거르기는 서버(login_page.php · Http::returnPath)가 한 번 더 한다.
 */
function loginReturnTag() {
  const rp = typeof window.qarediReturnPath === 'function' ? window.qarediReturnPath : null;
  const enc = typeof window.qarediRawEncode === 'function' ? window.qarediRawEncode : null;
  if (rp === null || enc === null) { return 'community'; }
  const editing = document.querySelector('#page-doc [contenteditable="true"], .ProseMirror[contenteditable="true"]') !== null;
  const here = rp(window.location.pathname + (editing ? '?edit=1' : window.location.search));
  return here !== null && here !== COMMUNITY_BASE + '/' ? enc(here) : 'community';
}

/**
 * Q:F-4 B1-2 — 이 화면의 임시저장 창구(`drafts.js`). `boot` 가 `/api/me` 의 **진짜 사용자** 아이디로 만든다.
 * 열쇠는 그 모듈 안에만 있다(이 파일에 열쇠를 담는 변수가 없다).
 */
let drafts = null;

/** 임시저장 열쇠 받기 — `drafts.js` 가 한 화면에 한 번만 부른다. 값은 곧바로 그 모듈에 넘어가고 여기 남지 않는다. */
function loadDraftKey() {
  return api('GET', '/api/me/draft-key').then((result) => (
    result.status === 200 && result.data && result.data.v === 1 && typeof result.data.key === 'string'
      ? result.data.key : null
  ));
}

/** 이 브라우저에서 잰 시각(ms) → 「2026-09-24 15:12」 (DESIGN 5장 날짜 모양 — 임시저장은 서버 시각이 없다) */
function localWhen(ms) {
  const d = new Date(ms);
  if (isNaN(d.getTime())) { return ''; }
  const two = (n) => (n < 10 ? '0' : '') + String(n);
  return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()) + ' ' + two(d.getHours()) + ':' + two(d.getMinutes());
}

/**
 * Q:F-4 P-4a — **탭이 보일 때만** 도는 주기 작업(알림 폴링 · 편집 heartbeat).
 *
 * 숨은 탭(`document.hidden`)에서는 타이머를 걷어 요청을 0 으로 만들고, 다시 보이면 **곧바로 한 번** 부른 뒤
 * 원래 주기로 돌아간다. `start()` 가 불리지 않았거나 `stop()` 뒤에는 보임·숨김이 바뀌어도 아무것도 하지 않는다.
 * 주기 값은 부르는 쪽이 코드에 적은 상수다 — 시험용 분기·전역 손잡이 없음.
 * (숨김 동안 편집 저장 flush 는 이것과 따로, 페이지의 `visibilitychange` 가 전처럼 한다.)
 *
 * @param {Function} fn  부를 일(요청 하나)
 * @param {number}   ms  주기(밀리초)
 * @return {{start: Function, stop: Function}}
 */
function whileVisible(fn, ms) {
  let timer = null;
  let running = false;
  const arm = () => { if (timer === null) { timer = window.setInterval(fn, ms); } };
  const disarm = () => { if (timer !== null) { window.clearInterval(timer); timer = null; } };
  document.addEventListener('visibilitychange', () => {
    if (!running) { return; }
    if (document.hidden) { disarm(); return; }
    if (timer === null) { fn(); arm(); }
  });
  return {
    start() {
      running = true;
      disarm();
      // 숨은 채로 열린 탭(배경 탭으로 연 링크)은 보일 때까지 부르지 않는다 — 보이는 순간 한 번 부른다.
      if (!document.hidden) { fn(); arm(); }
    },
    stop() {
      running = false;
      disarm();
    }
  };
}

/* ─── 새 페이지 제목 (사용감 라운드 1 UX-1) ───────────────────────
 * 서버가 제목 없이 만든 페이지에 붙이는 기본 제목(`pages_create.php` · `board_pages_create.php` 의 글자와 같다).
 * 편집 모드에서 제목 값이 이 셋 중 하나면 칸을 **비워** 자리표시 「제목」이 보이게 한다 — 지우는 데 탭이 더 들지 않게.
 * 칸을 비운 채 두면 저장은 전과 같이 지금 제목(`state.title` = 서버 값)을 보낸다 — 저장 창구·규칙은 그대로다.
 * 이 표는 보이는 칸의 편의일 뿐 판정이 아니다(DESIGN 원칙 1).
 */
const DEFAULT_TITLES = ['제목 없는 페이지', '제목 없는 데이터베이스', '제목 없는 글'];

/** 편집 칸에 넣을 제목 — 서버 기본 제목이면 빈 글자(자리표시가 보인다) */
function titleForInput(title) {
  const t = typeof title === 'string' ? title : '';
  return DEFAULT_TITLES.indexOf(t) >= 0 ? '' : t;
}

/* ─── 공개 범위 (4-B) ────────────────────────────────────────────
 *
 * ★ 이 표는 **보이는 말과 색**일 뿐 판정이 아니다. 무엇을 고를 수 있고 무엇이 거부되는지는
 *   서버가 요청마다 정하고(`CommunityAuthz`), 화면은 그 답을 그린다 (DESIGN 원칙 1).
 * ★ 색은 t-box 등급 배지의 뜻 그대로다 — 좁을수록 빨강, 넓을수록 파랑 (DESIGN 2.2).
 *   새 색값을 만들지 않았다.
 */
const VISIBILITY_LABELS = {
  private: { text: '비공개', badge: 'badge--admin-only' },
  team: { text: '팀', badge: 'badge--team' },
  internal: { text: '사내', badge: 'badge--internal' }
};

/** 거부 사유(서버가 준 `reason`) → 사람 문장. 표에 없으면 서버가 준 문구를 그대로 쓴다. */
const VISIBILITY_REASONS = {
  children_wider: '하위 페이지가 더 넓게 열려 있습니다. 하위까지 함께 바꾸려면 아래를 켜세요',
  wider_than_parent: '상위 페이지보다 넓게 열 수 없습니다. 상위 페이지를 먼저 넓히세요',
  space_governed: '팀 공간의 페이지는 공간 등급을 따릅니다',
  // 5-A — 공유·인계에서 고를 수 없는 사람(없는·쓰지 않는 계정, 주인 자신, 인계는 손님도)
  target_not_permitted: '그 사람에게는 줄 수 없습니다. 쓰고 있는 계정인지 확인하세요'
};

/* ─── 공유 (5-A) ─────────────────────────────────────────────────
 * 보이는 말뿐이다. 무엇을 줄 수 있고 누가 받을 수 있는지는 서버가 정한다(`CommunityAuthz`). */
const SHARE_LABELS = { view: '보기', edit: '편집' };

/* ─── 상태 (4-D, C:F-37) ─────────────────────────────────────────
 *
 * ★ 여기 있는 것은 **보이는 말과 색**뿐이다. 값이 다섯인지·무엇이 거부되는지는 서버가
 *   정하고(`COMMUNITY_STATUSES`, DB CHECK), 화면은 그 답을 그린다 (DESIGN 원칙 1).
 * ★ 색은 DESIGN 2.2 의 배지 뜻 그대로다 — 회색(꺼짐)·파랑(정보)·보라·초록(허용)·노랑(주의).
 *   새 색값을 만들지 않았다: `community.css` 의 다섯 줄은 app.css 의 같은 토큰을 가리킨다.
 */
const STATUS_LABELS = {
  todo: { text: '할 일', badge: 'badge--status-todo' },
  doing: { text: '진행', badge: 'badge--status-doing' },
  review: { text: '검토', badge: 'badge--status-review' },
  done: { text: '완료', badge: 'badge--status-done' },
  hold: { text: '보류', badge: 'badge--status-hold' }
};

/** 「상태 없음」 — 값이 아니라 값이 없는 상태다. 배지도 기본(회색) 하나뿐이다. */
const STATUS_NONE_TEXT = '없음';

/** 배지 한 칸을 상태로 채운다. 모르는 값이면 「없음」으로 둔다(지어내지 않는다). */
function paintStatus(el, status) {
  if (!el) { return; }
  const entry = STATUS_LABELS[status];
  el.className = 'badge' + (entry ? ' ' + entry.badge : '');
  if (entry) { el.setAttribute('title', status); } else { el.removeAttribute('title'); }
  setText(el, entry ? entry.text : STATUS_NONE_TEXT);
}

/** 목록 줄에 붙일 작은 상태 배지 하나. 상태가 없으면 아무것도 만들지 않는다. */
function statusBadge(status) {
  const entry = STATUS_LABELS[status];
  if (!entry) { return null; }
  const span = document.createElement('span');
  span.className = 'badge ' + entry.badge;
  span.setAttribute('title', status);
  span.textContent = entry.text;
  return span;
}

/** 목록 줄에 붙일 「보기 공유」·「편집 공유」 배지 (5-A). 모르는 값이면 아무것도 만들지 않는다. */
function shareBadge(level) {
  const text = SHARE_LABELS[level];
  if (!text) { return null; }
  const span = document.createElement('span');
  span.className = 'badge';
  span.setAttribute('title', level);
  span.textContent = text + ' 공유';
  return span;
}

/** 이력 줄의 속성 값 — 상태는 한글 라벨, 담당자는 계정명, 없음은 「없음」. */
function propValueText(prop, value) {
  if (value === null || value === undefined || value === '') { return STATUS_NONE_TEXT; }
  if (prop === 'status') {
    const entry = STATUS_LABELS[value];
    return entry ? entry.text : String(value);
  }
  return String(value);
}

/**
 * 아이콘 한 칸. `app.js` 의 `makeIcon` 과 **같은 방식**이다 — SVG 마크업은 서버가 만든
 * `<template>` 안에 있고 여기서는 `cloneNode` 로 복제만 한다. 문자열을 HTML 로 바꾸는
 * 자리가 없다 (CSP `script-src 'self'`, C:S-03). 이 파일은 모듈이라 `app.js` 의 함수를
 * 부를 수 없어 같은 여섯 줄을 여기에도 둔다.
 */
function makeIcon(name) {
  const host = document.createElement('span');
  host.className = 'icon';
  const tpl = document.getElementById('tpl-icon-' + String(name));
  if (tpl && tpl.content) { host.appendChild(tpl.content.cloneNode(true)); }
  return host;
}

/** 배지 한 칸을 공개 범위로 채운다. 모르는 값이면 숨긴다(지어내지 않는다). */
function paintVisibility(el, visibility) {
  if (!el) { return; }
  const entry = VISIBILITY_LABELS[visibility];
  if (!entry) { show(el, false); return; }
  el.className = 'badge ' + entry.badge;
  el.setAttribute('title', visibility);
  setText(el, entry.text);
  show(el, true);
}

/**
 * 트리·카드 줄에 붙일 작은 배지 하나.
 * **기본값(비공개)은 그리지 않는다** — 내 공간의 페이지는 처음에 비공개라, 트리 거의 모든 줄에 빨간 「비공개」가 붙어
 * 정작 넓게 연 페이지(팀·사내)가 눈에 띄지 않았다(디버깅 묶음 U M11 · 2026-09-26). 줄에는 **기본과 다른 것**만 보인다.
 * 페이지 화면 속성 줄의 「공개 범위」 배지(`paintVisibility`)는 그대로 비공개도 보인다 — 거기는 그 페이지의 값을 묻는 자리다.
 */
function visibilityBadge(visibility) {
  if (visibility === 'private') { return null; }
  const entry = VISIBILITY_LABELS[visibility];
  if (!entry) { return null; }
  const span = document.createElement('span');
  span.className = 'badge ' + entry.badge;
  span.setAttribute('title', visibility);
  span.textContent = entry.text;
  return span;
}

/**
 * 주소의 `?space=` — 숫자만 받는다 (4-B).
 * 형태가 아니면 없는 것으로 다룬다: 창구가 어차피 404 로 답하지만, 이상한 값을 그대로
 * 창구에 실어 보내면 「무엇이 잘못됐나」가 주소와 응답 두 곳으로 흩어진다.
 */
function currentSpaceId() {
  let raw = '';
  try {
    raw = new URLSearchParams(window.location.search).get('space') || '';
  } catch (e) {
    raw = '';
  }
  return /^[1-9][0-9]{0,17}$/.test(raw) ? raw : '';
}

/** 공간 하나의 주소. 개인 공간(기본)은 꼬리표 없이 둔다. */
function spaceHref(id, kind) {
  return COMMUNITY_BASE + '/workspace' + (kind === 'personal' ? '' : '?space=' + String(id));
}

/**
 * 셸 왼쪽 내비의 「팀 공간」 묶음을 채운다 (4-B, 두 화면 공통).
 *
 * 항목은 창구가 준 것만 들어간다 — 이 파일에는 「내 팀이 무엇인가」를 아는 코드가 없다.
 * 팀 공간이 없으면 제목 줄까지 숨은 채로 끝난다.
 */
function fillSpaceNav(spaces) {
  const box = document.getElementById('nav-community-teams');
  const title = document.getElementById('nav-community-team-title');
  if (!box) { return; }
  clear(box);
  const teams = (Array.isArray(spaces) ? spaces : []).filter((s) => s && s.kind === 'team');
  // 팀마다의 줄과 트리는 `fillSidebarTrees` 가 그린다(5-A′). 여기서는 묶음을 열고 닫기만 한다.
  show(box, teams.length > 0);
  show(title, teams.length > 0);
}

/**
 * 내가 볼 수 있는 공간들. **팀 공간이 아직 없으면 창구가 그 자리에서 만든다**(C:T-36) —
 * 그래서 이 호출은 화면을 여는 것만으로 팀 공간을 여는 일을 겸한다. 두 번 불러도 같다.
 */
/** Q:F-12 D — 이 화면에서 받은 공간 목록(같은 화면에서 다시 묻지 않게 work.js 가 나눠 쓴다) */
let lastSpaces = null;
function loadSpaces(strict = false) {
  if (lastSpaces) { return lastSpaces; }
  lastSpaces = api('GET', '/api/spaces').then(async (result) => {
    if (result.status !== 200) { if (strict) throw new Error(errorMessage(result.data)); return []; }
    const spaces = Array.isArray(result.data.spaces) ? result.data.spaces : [];
    fillSpaceNav(spaces);
    await fillSidebarTrees(spaces, strict);
    return spaces;
  }).catch(error => { if (strict) throw error; return []; });
  return lastSpaces;
}

/* ─── 사이드바 페이지 트리 (5-A′, 사용자 지적 2026-09-23) ─────────────
 *
 * 「사이드바에 내 공간·+ 새 페이지가 있는데 그 아래 페이지가 보여야 할 것 같다」 — 4-A 지시서의
 * 목표 문장(「왼쪽 내 공간이 트리로」)이 구현에서 빠졌던 것을 채운다.
 *   · 내 공간 · 팀 공간마다 · 공유받은 페이지 — 항목은 **창구가 준 것만**(`?tree=1` 은 행 관문을
 *     지난 목록이다). 이 파일에는 누가 무엇을 볼 수 있나를 아는 코드가 없다 (PRD S-01).
 *   · 접기·펼치기는 ▸ 단추. 지금 페이지와 그 윗줄들만 펼쳐 두고 지금 페이지를 강조한다.
 *   · 들여쓰기는 목록이 겹칠 때마다 CSS 가 준다 — 인라인 style 0 (DESIGN 0장 2).
 */
function currentPageId() {
  const meta = document.getElementById('page-meta');
  return meta ? (meta.getAttribute('data-page-id') || '') : '';
}

/** 펼침 단추 하나 — 하위가 없으면 자리만 차지하는 빈 칸이다(제목 왼쪽 끝을 가지런히) */
function navToggle(label, kids, expanded) {
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'nav-tree__toggle';
  if (!kids) {
    toggle.disabled = true;
    toggle.tabIndex = -1;
    toggle.setAttribute('aria-hidden', 'true');
    return toggle;
  }
  toggle.textContent = '▸';
  toggle.setAttribute('aria-label', label + ' 하위 ' + (expanded ? '접기' : '펼치기'));
  toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  if (expanded) { toggle.classList.add('is-open'); }
  toggle.addEventListener('click', () => {
    const box = toggle.parentNode && toggle.parentNode.nextElementSibling;
    if (!box) { return; }
    const open = box.hidden;
    box.hidden = !open;
    toggle.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', label + ' 하위 ' + (open ? '접기' : '펼치기'));
  });
  return toggle;
}

/** 평평한 목록(`?tree=1`) → 사이드바 트리. `found` 는 지금 페이지가 이 트리에 있었나 */
function navTree(pages, activeId) {
  const byParent = new Map();
  const byId = new Map(pages.map(page=>[page.id,page]));
  for (const page of pages) {
    const key = typeof page.parent_id === 'string' && byId.has(page.parent_id) ? page.parent_id : '';
    if (!byParent.has(key)) { byParent.set(key, []); }
    byParent.get(key).push(page);
  }
  // 지금 페이지의 윗줄들은 펼친다(깊이 8 — 16 에서 멈추는 것은 자료가 어긋났을 때의 안전장치)
  const openIds = new Set();
  let cursor = byId.get(activeId);
  for (let guard = 0; cursor && guard < 16; guard++) {
    const up = typeof cursor.parent_id === 'string' ? cursor.parent_id : '';
    if (up === '') { break; }
    openIds.add(up);
    cursor = byId.get(up);
  }

  function level(key, depth) {
    const ul = document.createElement('ul');
    ul.className = 'nav-tree';
    for (const node of byParent.get(key) || []) {
      const kids = depth < 12 ? (byParent.get(node.id) || []) : [];
      const expanded = openIds.has(node.id) || node.id === activeId;
      const title = (node.icon ? node.icon + ' ' : '') + node.title;
      const li = document.createElement('li');
      const row = document.createElement('div');
      row.className = 'nav-tree__row' + (node.id === activeId ? ' is-active' : '');
      const link = document.createElement('a');
      link.className = 'nav-tree__link';
      link.setAttribute('href', COMMUNITY_BASE + '/p/' + node.id);
      link.textContent = title;
      if (node.id === activeId) { link.setAttribute('aria-current', 'page'); }
      row.appendChild(navToggle(title, kids.length > 0, expanded));
      row.appendChild(link);
      row.appendChild(navStar(node));
      documentTrashRow(row, node);   // 라운드 B — 트리 항목의 ☆(넣기·빼기)
      li.appendChild(row);
      if (kids.length > 0) {
        const sub = level(node.id, depth + 1);
        sub.hidden = !expanded;
        li.appendChild(sub);
      }
      ul.appendChild(li);
    }
    return ul;
  }
  return { el: level('', 0), found: byId.has(activeId) };
}

function spaceTree(space, strict = false) {
  const tail = space.kind === 'personal' ? '' : '&space=' + encodeURIComponent(String(space.id));
  return api('GET', '/api/pages?tree=1' + tail).then((result) => {
    if (result.status === 200 && Array.isArray(result.data.pages)) return result.data.pages;
    if (strict) throw new Error(errorMessage(result.data));
    return [];
  }).catch(error => { if (strict) throw error; return []; });
}

let sidebarTreeGeneration = 0;
function fillSidebarTrees(spaces, strict = false) {
  const ticket = ++sidebarTreeGeneration;
  const pendingTrees = [];
  const mineBox = document.getElementById('nav-community-tree');
  const teamBox = document.getElementById('nav-community-teams');
  const sharedBox = document.getElementById('nav-community-shared');
  const sharedTitle = document.getElementById('nav-community-shared-title');
  const activeId = currentPageId();
  const hereSpace = currentSpaceId();
  const list = Array.isArray(spaces) ? spaces : [];

  const personal = list.find((s) => s && s.kind === 'personal');
  if (mineBox && personal) {
    pendingTrees.push(spaceTree(personal, strict).then((pages) => {
      if (ticket !== sidebarTreeGeneration) return;
      clear(mineBox);
      mineBox.appendChild(navTree(pages.map(page=>({...page,_trash_space_id:personal.id})), activeId).el);
      markActiveVisible(mineBox);
    }));
  }

  // 팀 공간 — 팀 이름 줄(공간으로 가는 링크) 밑에 그 팀의 트리. 지금 페이지가 그 안에 있거나
  // 그 공간을 보는 중이면 펼친다.
  if (teamBox) {
    clear(teamBox);
    for (const space of list.filter((s) => s && s.kind === 'team')) {
      const group = document.createElement('div');
      const row = document.createElement('div');
      row.className = 'nav-tree__row nav-tree__row--space' + (hereSpace === String(space.id) && activeId === '' ? ' is-active' : '');
      const link = document.createElement('a');
      link.className = 'nav-tree__link';
      link.setAttribute('href', spaceHref(space.id, space.kind));
      link.appendChild(makeIcon('users'));
      const name = document.createElement('span');
      name.textContent = space.name;
      link.appendChild(name);
      group.appendChild(row);
      teamBox.appendChild(group);
      pendingTrees.push(spaceTree(space, strict).then((pages) => {
        if (ticket !== sidebarTreeGeneration) return;
        const tree = navTree(pages.map(page=>({...page,_trash_space_id:space.id})), activeId);
        const expanded = tree.found || hereSpace === String(space.id);
        row.appendChild(navToggle(space.name, pages.length > 0, expanded));
        row.appendChild(link);
        if (pages.length > 0) {
          tree.el.hidden = !expanded;
          group.appendChild(tree.el);
        }
        markActiveVisible(group);
      }));
    }
  }

  // 공유받은 페이지 (5-A) — 공간을 가리지 않는 한 번의 창구 호출. 없으면 제목째 숨는다.
  if (sharedBox) {
    pendingTrees.push(api('GET', '/api/pages?shared=me').then((result) => {
      if (ticket !== sidebarTreeGeneration) return;
      const pages = result.status === 200 && Array.isArray(result.data.pages) ? result.data.pages : [];
      clear(sharedBox);
      const ul = document.createElement('ul');
      ul.className = 'nav-tree';
      for (const page of pages) {
        const li = document.createElement('li');
        const row = document.createElement('div');
        row.className = 'nav-tree__row' + (page.id === activeId ? ' is-active' : '');
        row.appendChild(navToggle('', false, false));
        const link = document.createElement('a');
        link.className = 'nav-tree__link';
        link.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);
        link.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
        if (page.id === activeId) { link.setAttribute('aria-current', 'page'); }
        row.appendChild(link);
        row.appendChild(navStar(page));
        documentTrashRow(row, page);   // 라운드 B — 트리 항목의 ☆
        li.appendChild(row);
        ul.appendChild(li);
      }
      sharedBox.appendChild(ul);
      show(sharedBox, pages.length > 0);
      show(sharedTitle, pages.length > 0);
    }).catch(() => {}));
  }
  return Promise.allSettled(pendingTrees).then(results => {
    if (strict) { const failed = results.find(result=>result.status==='rejected'); if (failed) throw failed.reason; }
    return results;
  });
}

/** 지금 페이지 줄이 사이드바 칸 밖이면 그 칸 안에서만 보이게 굴린다(본문은 움직이지 않는다) */
function markActiveVisible(box) {
  const row = box.querySelector('.nav-tree__row.is-active');
  const side = document.getElementById('sidebar');
  if (!row || !side || row.getClientRects().length === 0) { return; }
  const r = row.getBoundingClientRect();
  const b = side.getBoundingClientRect();
  if (r.top < b.top || r.bottom > b.bottom) {
    side.scrollTop += r.top - b.top - b.height / 3;
  }
}

/* ─── 즐겨찾기 · 최근 (개발 대기열 6 · Q:F-3a 라운드 A — 지시서 1.1 · 1.2 · 결정 M-1) ─────────────
 *
 * 사이드바 맨 위 「즐겨찾기」(접을 수 있음) → 「최근」 5개 + 「더 보기」. 세 화면(내 공간·페이지·게시판) 공통.
 *   · 항목은 **창구가 준 것만** — 두 창구 모두 검색과 같은 조건절 안에서만 돌려준다(못 보게 된 페이지는 제목째 없다).
 *     이 파일에는 누가 무엇을 볼 수 있나를 아는 코드가 없다 (PRD S-01, DESIGN 원칙 1).
 *   · 줄은 사이드바 트리와 같은 `.nav-tree` 줄이고 글자는 textContent 뿐이다. 새 클래스·인라인 style 0.
 *   · 즐겨찾기 순서: 줄을 끌어 놓거나(HTML 끌어 놓기) 링크에 초점을 두고 Alt+↑/↓ → `PATCH /api/me/favorites {ids}`.
 *     실패하면 창구의 순서로 다시 그린다.
 *   · 「최근 기록 지우기」는 창 안의 빨간 단추를 **두 번** 눌러야 나간다(댓글 지우기와 같은 방식 — 모달 안에 모달 0).
 */
let favoriteIds = new Set();
/** 즐겨찾기를 새로 받았을 때 알릴 곳 — 페이지 화면의 ☆ 메뉴가 등록한다 */
const favoriteListeners = [];
/** 최근 목록(창구의 답 그대로) — 「더 보기」 창이 쓴다 */
let recentPages = [];
const FAV_OPEN_KEY = 'qaredi-nav-fav-open';

/** 사이드바 한 줄 — 트리 줄과 같은 모양(왼쪽 빈 칸은 트리의 펼침 단추 자리) */
function navPageRow(page, activeId) {
  const li = document.createElement('li');
  const row = document.createElement('div');
  row.className = 'nav-tree__row' + (page.id === activeId ? ' is-active' : '');
  row.appendChild(navToggle('', false, false));
  const link = document.createElement('a');
  link.className = 'nav-tree__link';
  link.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);
  link.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
  if (page.id === activeId) { link.setAttribute('aria-current', 'page'); }
  row.appendChild(link);
  row.appendChild(navStar(page));
  documentTrashRow(row, page);   // 라운드 B — 즐겨찾기·최근 줄에도 같은 ☆(즐겨찾기 줄에서는 누르면 빠진다)
  li.appendChild(row);
  return { li: li, link: link };
}

/** 즐겨찾기 줄들의 지금 순서(페이지 id) */
function favoriteOrder(ul) {
  return Array.from(ul.querySelectorAll('li[data-fav-id]')).map((li) => li.getAttribute('data-fav-id'));
}

function saveFavoriteOrder(ul, before) {
  const ids = favoriteOrder(ul);
  if (ids.join(',') === before.join(',')) { return; }
  api('PATCH', '/api/me/favorites', { ids: ids }).then((result) => {
    if (result.status === 401) { toLogin(); return; }
    if (result.status !== 200) {
      setText(document.getElementById('msg'), errorMessage(result.data));
      loadFavoritesNav();   // 창구의 순서로 되돌린다
    }
  }).catch(() => { loadFavoritesNav(); });
}

function drawFavoritesNav(pages) {
  const box = document.getElementById('nav-community-fav-box');
  const nav = document.getElementById('nav-community-fav');
  if (!box || !nav) { return; }
  clear(nav);
  const activeId = currentPageId();
  const ul = document.createElement('ul');
  ul.className = 'nav-tree';
  let dragging = null;
  let before = [];
  for (const page of pages) {
    const made = navPageRow(page, activeId);
    const li = made.li;
    li.setAttribute('data-fav-id', page.id);
    li.draggable = true;
    // 링크 자체가 끌리면(주소 끌기) 줄이 움직이지 않는다 — 줄(li)이 끌리게 링크의 끌기를 끈다
    made.link.draggable = false;
    made.link.setAttribute('aria-keyshortcuts', 'Alt+ArrowUp Alt+ArrowDown');
    made.link.addEventListener('keydown', (event) => {
      if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) { return; }
      event.preventDefault();
      const was = favoriteOrder(ul);
      if (event.key === 'ArrowUp' && li.previousElementSibling) {
        ul.insertBefore(li, li.previousElementSibling);
      } else if (event.key === 'ArrowDown' && li.nextElementSibling) {
        ul.insertBefore(li.nextElementSibling, li);
      }
      made.link.focus();
      saveFavoriteOrder(ul, was);
    });
    li.addEventListener('dragstart', (event) => {
      dragging = li;
      before = favoriteOrder(ul);
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', page.id);
      }
    });
    li.addEventListener('dragover', (event) => {
      if (dragging === null || dragging === li) { return; }
      event.preventDefault();
      const r = li.getBoundingClientRect();
      ul.insertBefore(dragging, event.clientY > r.top + r.height / 2 ? li.nextSibling : li);
    });
    li.addEventListener('drop', (event) => { event.preventDefault(); });
    li.addEventListener('dragend', () => {
      if (dragging === null) { return; }
      dragging = null;
      saveFavoriteOrder(ul, before);
    });
    ul.appendChild(li);
  }
  nav.appendChild(ul);
  show(box, pages.length > 0);
}

/* T:F-72 라운드 C · 결정 PK-D5 — 좁은 폭(960px 미만) 「내 공간」 첫 화면 맨 위의 즐겨찾기(`#community-home-fav`, 홈 화면에만 있다).
   사이드바와 **같은 답 · 같은 줄**(navPageRow — ☆ 로 빼기도 같다)을 한 번 더 그린다. 넓은 폭은 사이드바가 늘 보이므로 숨긴다. */
const HOME_FAV_NARROW = '(max-width: 959.98px)';
let homeFavPages = [];
let homeFavWatch = false;
function drawHomeFavorites(pages) {
  const box = document.getElementById('community-home-fav');
  const list = document.getElementById('community-home-fav-list');
  if (!box || !list) { return; }
  homeFavPages = pages;
  clear(list);
  const ul = document.createElement('ul');
  ul.className = 'nav-tree';
  for (const page of pages) { ul.appendChild(navPageRow(page, '').li); }
  list.appendChild(ul);
  let mq = null;
  try { mq = window.matchMedia(HOME_FAV_NARROW); } catch (e) { mq = null; }
  if (mq && !homeFavWatch && typeof mq.addEventListener === 'function') {
    homeFavWatch = true;
    mq.addEventListener('change', () => { drawHomeFavorites(homeFavPages); });
  }
  show(box, pages.length > 0 && mq !== null && mq.matches);
}

/** 즐겨찾기를 받아 사이드바를 그리고, 등록된 곳(☆ 메뉴)에 알린다 */
let favoriteGeneration = 0;
function loadFavoritesNav() {
  const ticket = ++favoriteGeneration;
  return api('GET', '/api/me/favorites').then((result) => {
    if (ticket !== favoriteGeneration) return [];
    if (result.status === 401) { toLogin(); return []; }
    const pages = result.status === 200 && Array.isArray(result.data.pages) ? result.data.pages : [];
    favoriteIds = new Set(pages.map((p) => p.id));
    drawFavoritesNav(pages);
    drawHomeFavorites(pages);
    for (const fn of favoriteListeners) { fn(); }
    return pages;
  }).catch(() => []);
}

/** 접은 상태를 이 브라우저에만 기억한다(화면 취향 — 저장이 막혀도 기본은 펼침) */
function bindFavoritesFold() {
  const box = document.getElementById('nav-community-fav-box');
  if (!box) { return; }
  const narrow = window.matchMedia('(max-width: 959.98px)');
  function restoreDesktopFold() {
    if (narrow.matches) { return; }
    try { box.open = window.localStorage.getItem(FAV_OPEN_KEY) !== '0'; } catch (e) { /* 기본 펼침 */ }
  }
  restoreDesktopFold();
  const sidebar = document.getElementById('sidebar');
  if (sidebar) { sidebar.addEventListener('qaredi:sidebar-layout', restoreDesktopFold); }
  box.addEventListener('toggle', () => {
    // 모바일 묶음 접기가 PC에서 사용자가 정한 즐겨찾기 취향을 덮지 않는다.
    if (narrow.matches) { return; }
    try { window.localStorage.setItem(FAV_OPEN_KEY, box.open ? '1' : '0'); } catch (e) { /* 무시 */ }
  });
}

function drawRecentNav(pages) {
  const title = document.getElementById('nav-community-recent-title');
  const nav = document.getElementById('nav-community-recent');
  if (!nav) { return; }
  clear(nav);
  const activeId = currentPageId();
  const ul = document.createElement('ul');
  ul.className = 'nav-tree';
  for (const page of pages.slice(0, 5)) {
    ul.appendChild(navPageRow(page, activeId).li);
  }
  nav.appendChild(ul);
  if (pages.length > 0) {
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'nav__item';
    more.id = 'nav-community-recent-more';
    more.setAttribute('aria-haspopup', 'dialog');
    more.appendChild(makeIcon('log'));
    const label = document.createElement('span');
    label.textContent = '더 보기';
    more.appendChild(label);
    more.addEventListener('click', openRecentDialog);
    nav.appendChild(more);
  }
  show(nav, pages.length > 0);
  show(title, pages.length > 0);
  drawHomeRecent(pages);
}

/* 사용감 라운드 1 UX-4 — 「내 공간」 첫 화면 맨 위(즐겨찾기 판 다음)의 「최근」 다섯 줄(`#community-home-recent`, 홈 화면에만 있다).
   사이드바 「최근」과 **같은 답 · 같은 줄**(navPageRow — ☆ 도 같다)을 한 번 더 그린다(새 창구 · 새 요청 0). 폰·넓은 폭 둘 다 보이고, 비었으면 숨는다. */
function drawHomeRecent(pages) {
  const box = document.getElementById('community-home-recent');
  const list = document.getElementById('community-home-recent-list');
  if (!box || !list) { return; }
  clear(list);
  const ul = document.createElement('ul');
  ul.className = 'nav-tree';
  for (const page of pages.slice(0, 5)) { ul.appendChild(navPageRow(page, '').li); }
  list.appendChild(ul);
  show(box, pages.length > 0);
}

let recentGeneration = 0;
function loadRecentNav() {
  const ticket = ++recentGeneration;
  return api('GET', '/api/me/recent').then((result) => {
    if (ticket !== recentGeneration) return [];
    if (result.status === 401) { toLogin(); return []; }
    recentPages = result.status === 200 && Array.isArray(result.data.pages) ? result.data.pages : [];
    drawRecentNav(recentPages);
    return recentPages;
  }).catch(() => []);
}

function fillRecentDialog() {
  const list = document.getElementById('recent-list');
  const empty = document.getElementById('recent-empty');
  const clearButton = document.getElementById('recent-clear');
  clear(list);
  for (const page of recentPages) {
    const li = document.createElement('li');
    li.className = 'list__item';
    const head = document.createElement('div');
    head.className = 'list__head';
    const link = document.createElement('a');
    link.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);
    link.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
    head.appendChild(link);
    li.appendChild(head);
    const body = document.createElement('div');
    body.className = 'list__body';
    const where = document.createElement('span');
    where.textContent = page.space && typeof page.space.name === 'string' ? page.space.name : '';
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(page.last_viewed_at);
    if (typeof page.last_viewed_at === 'string') { when.setAttribute('title', page.last_viewed_at); }
    body.appendChild(where);
    body.appendChild(when);
    li.appendChild(body);
    list.appendChild(li);
  }
  show(empty, recentPages.length === 0);
  if (clearButton) { clearButton.disabled = recentPages.length === 0; }
}

let recentDialogBound = false;

function openRecentDialog() {
  const dialog = document.getElementById('recent-dialog');
  if (!dialog) { return; }
  const msg = document.getElementById('recent-msg');
  const clearButton = document.getElementById('recent-clear');
  if (!recentDialogBound) {
    recentDialogBound = true;
    // 좁은 화면에서는 사이드바가 숨는다 — 창을 body 끝으로 옮겨 둔다(알림 창과 같은 까닭)
    document.body.appendChild(dialog);
    for (const close of dialog.querySelectorAll('[data-recent-close]')) {
      close.addEventListener('click', () => dialog.close());
    }
    dialog.addEventListener('close', () => {
      if (clearButton) { delete clearButton.dataset.armed; clearButton.textContent = '최근 기록 지우기'; }
    });
    if (clearButton) {
      // 지우기 — 되돌릴 수 없는 동작이라 한 번 더 누르게 한다(DESIGN 4장 「위험 동작 → 확인」 · 댓글 지우기와 같다)
      clearButton.addEventListener('click', () => {
        if (clearButton.dataset.armed !== '1') {
          clearButton.dataset.armed = '1';
          clearButton.textContent = '정말 지우기';
          return;
        }
        clearButton.disabled = true;
        setText(msg, '');
        api('DELETE', '/api/me/recent').then((result) => {
          if (result.status === 401) { toLogin(); return; }
          delete clearButton.dataset.armed;
          clearButton.textContent = '최근 기록 지우기';
          if (result.status !== 200) {
            clearButton.disabled = false;
            setText(msg, errorMessage(result.data));
            return;
          }
          recentPages = [];
          drawRecentNav(recentPages);
          fillRecentDialog();
        }).catch(() => { clearButton.disabled = false; setText(msg, GENERIC_ERROR); });
      });
    }
  }
  setText(msg, '');
  fillRecentDialog();
  dialog.showModal();
  // 창을 연 뒤 창구의 지금 답으로 한 번 더 맞춘다(다른 탭에서 연 페이지까지)
  loadRecentNav().then(() => { if (dialog.open) { fillRecentDialog(); } });
}

/* ─── 사이드바 트리 줄의 ☆ (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.1 「넣기 | … 트리 항목 메뉴」) ───────────
 *
 * 사이드바의 페이지 줄(내 공간 · 팀 공간 · 공유받은 페이지 · 즐겨찾기 · 최근)마다 끝에 ☆ 단추 하나. 항목 메뉴(「⋯」)로 만들지 않은
 * 까닭: 항목이 하나뿐인 메뉴는 DESIGN 2.15 「하지 말 것」이다(정의된 항목이 둘 이하인 메뉴 → 그냥 단추). 모양은 줄의 펼침 단추와 같은
 * `.nav-tree__toggle`(20px · 테두리 없음) — 새 클래스 0. 넣은 줄(★)은 늘 보이고, 안 넣은 줄은 줄에 올리거나 초점이 있을 때만
 * 보인다(community.css — 「하위 추가」와 같은 규칙).
 * 넣을 수 있는지는 창구가 판단한다(`PUT /api/pages/{id}/favorite` — 그 순간 읽을 수 있어야 · 못 읽으면 404 문구). 화면은 창구가 준
 * 즐겨찾기 목록(`favoriteIds`)에 있나 없나로만 별을 칠한다.
 */
function navStar(page) {
  const title = (page.icon ? page.icon + ' ' : '') + page.title;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'nav-tree__toggle';
  button.setAttribute('data-fav-toggle', page.id);
  button.setAttribute('data-fav-title', title);
  paintNavStar(button);
  button.addEventListener('click', (event) => {
    event.preventDefault();
    const on = favoriteIds.has(page.id);
    button.disabled = true;
    api(on ? 'DELETE' : 'PUT', '/api/pages/' + page.id + '/favorite', {})
      .then((result) => {
        button.disabled = false;
        if (result.status === 401) { toLogin(); return null; }
        if (result.status !== 200) { setText(document.getElementById('msg'), errorMessage(result.data)); return null; }
        return loadFavoritesNav();   // 목록·모든 ☆ 를 창구의 답으로 다시 맞춘다
      })
      .catch(() => { button.disabled = false; setText(document.getElementById('msg'), GENERIC_ERROR); });
  });
  return button;
}

function paintNavStar(button) {
  const id = button.getAttribute('data-fav-toggle') || '';
  const on = favoriteIds.has(id);
  button.replaceChildren(makeIcon(on ? 'star-fill' : 'star'));
  button.setAttribute('aria-pressed', on ? 'true' : 'false');
  button.setAttribute('aria-label', '「' + (button.getAttribute('data-fav-title') || '') + '」 ' + (on ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기'));
}

/** 즐겨찾기를 새로 받을 때마다 사이드바의 모든 ☆ 를 맞춘다 */
function paintNavStars() {
  for (const button of document.querySelectorAll('#sidebar [data-fav-toggle]')) { paintNavStar(button); }
}
favoriteListeners.push(paintNavStars);

/* ─── 일괄 작업 진행기 (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.3 · 결정 M-4 · M-7) ───────────────────
 *
 * 원칙: **새 서버 창구 0** — 한 건 작업과 같은 창구를 항목마다 한 번씩, 차례로 부른다(t-box F-27 일괄 올리기·파일 화면 일괄과 같은 방식).
 * 할 수 있는지는 창구가 항목마다 판단하고(판정·감사·거부 문장이 한 건과 같다), 화면은 그 답을 줄마다 적을 뿐이다(DESIGN 원칙 1).
 *   · 줄 상태: 대기 → 하는 중… → 완료 글자 / 창구가 준 거부 문장(F-68). 423 은 그 줄만 「나중에」.
 *   · 부모와 그 하위를 함께 골랐으면 하위는 보내지 않는다 — 부모가 되면 「부모와 함께 …」, 부모가 거부되면 「… 부모를 … 못 했습니다」
 *     (순서 섞임·깊이 오류를 막는다 — 지시서 1.3). 부모가 늘 하위보다 먼저 오게 트리 순서로 보낸다.
 *   · 401 이면 멈추고 남은 줄을 그대로 둔다 → 새 탭에서 로그인한 뒤 「이어서 하기」: `/api/me` 를 새로 물어 **같은 계정**이면 새 CSRF
 *     토큰으로 그 줄부터(다른 계정이면 잇지 않는다 — QF3A-T7). 403 reauth 는 셸의 재인증 창을 띄우고 통과하면 그 줄부터.
 *     「이어서 하기」는 멈춘 까닭과 상관없이 보내기 전에 `/api/me` 로 같은 계정인지 보고, 새 토큰을 셸에도 넘긴다(`window.qarediSetCsrf`).
 *     구간(시작 · 이어 가기)의 첫 403 에서도 `/api/me` 로 세션이 그대로인지 본다 — 바뀌었으면 거부로 세지 않고 멈춘다.
 *   · 「되돌리기」(휴지통으로 보낸 것 — M-7 커뮤니티는 바로): 10초 동안, 지운 페이지마다 `POST /api/pages/{id}/restore`.
 *   · 글자는 전부 textContent · 인라인 style 0 · 새 클래스 0(`.upload-status` 진행 표 · `.notice` · `.table-foot` 그대로).
 *
 * @param {Object} el     진행 표 요소들 {panel, title, body, stop, stopText, login, resume, summary, undo?, close?}
 * @param {Object} hooks  {onChange?() — 도는 중인지가 바뀔 때, beforeReauth?() · afterReauth?() — 재인증 창을 띄우기 전·뒤(모달 안 모달 0)}
 */
const BULK_PICK_MAX = 100;   // 결정 M-4
const BULK_UNDO_MS = 10000;  // 결정 M-7
/** 「이어서 하기」가 같은 계정인지 견줄 아이디(boot 가 `/api/me` 의 username 으로 채운다 — 역할로 보기 중에도 실제 계정) */
let meUsername = null;

function createBulkRunner(el, hooks) {
  let job = null;
  let undoTimer = null;
  let undoJob = null;

  function setState(item, text, cls) {
    setText(item.stateCell, text);
    item.stateCell.className = cls ? 'upload-status__state ' + cls : 'upload-status__state';
  }
  function busy() { return job !== null && !job.finished; }
  function changed() { if (hooks && typeof hooks.onChange === 'function') { hooks.onChange(); } }

  /** 「n개 완료 · m개 못 함」 — 부모와 함께 된 하위는 완료, 나중에는 못 함 */
  function tally(j) {
    let ok = 0;
    let no = 0;
    for (const item of j.items) {
      if (item.result === 'ok') { ok += 1; } else if (item.result === 'deny' || item.result === 'later') { no += 1; }
    }
    return String(ok) + '개 완료 · ' + String(no) + '개 못 함';
  }

  function hideUndo() {
    if (undoTimer !== null) { window.clearTimeout(undoTimer); undoTimer = null; }
    undoJob = null;
    show(el.undo, false);
  }

  function start(next) {
    if (busy() || !el.panel || !el.body) { return false; }
    hideUndo();
    job = next;
    job.index = 0;
    job.finished = false;
    job.paused = null;
    job.sessionChecked = false;
    clear(el.body);
    for (const item of job.items) {
      const tr = document.createElement('tr');
      const name = document.createElement('td');
      name.textContent = item.name;
      tr.appendChild(name);
      item.stateCell = document.createElement('td');
      tr.appendChild(item.stateCell);
      item.result = 'wait';
      setState(item, item.parent ? '대기 — 부모와 함께' : '대기', '');
      el.body.appendChild(tr);
    }
    setText(el.title, job.title + ' · ' + String(job.items.length) + '개');
    show(el.stop, false);
    setText(el.summary, '');
    if (el.close) { el.close.disabled = true; }
    show(el.panel, true);
    el.title.focus();
    changed();
    step(job);
    return true;
  }

  function step(j) {
    if (job !== j || j.paused !== null) { return; }
    // 부모와 함께 고른 하위 — 보내지 않고 부모의 결과로 적는다(부모가 트리 순서상 먼저 끝났다)
    while (j.index < j.items.length && j.items[j.index].parent) {
      const kid = j.items[j.index];
      if (kid.parent.result === 'ok') {
        kid.result = 'ok';
        setState(kid, j.withParentText || '부모와 함께 처리됨', 'is-ok');
      } else {
        kid.result = 'deny';
        setState(kid, j.parentFailText || '부모를 처리하지 못해 그대로 두었습니다', 'is-deny');
      }
      j.index += 1;
    }
    if (j.index >= j.items.length) { finish(j); return; }
    const item = j.items[j.index];
    setState(item, '하는 중…', 'is-busy');
    j.send(item)
      .then((result) => {
        if (result.status === 401) { pause(j, 'login'); return 'stop'; }
        if (result.status === 403 && result.data && result.data.next === 'reauth' && typeof window.qarediHandleReauth === 'function') {
          pause(j, 'reauth');
          if (hooks && typeof hooks.beforeReauth === 'function') { hooks.beforeReauth(); }
          window.qarediHandleReauth({ status: 403 }, result.data, () => {
            if (hooks && typeof hooks.afterReauth === 'function') { hooks.afterReauth(); }
            cont(j);
          });
          return 'stop';
        }
        if (result.status === 403 && !j.sessionChecked) {
          // 이 구간의 첫 403 — 거부로 세기 전에 세션이 그대로인지 `/api/me` 로 본다(다른 탭에서 다시 로그인했으면 이 탭의
          // CSRF 토큰이 옛 세션 것이라 줄마다 403 이 된다 — 그건 거부가 아니라 멈출 일이다)
          j.sessionChecked = true;
          return sessionCheck().then((why) => {
            if (why !== null) { pause(j, why); return 'stop'; }
            item.result = 'deny';
            setState(item, errorMessage(result.data), 'is-deny');   // 세션은 그대로 — 창구의 거부 문장 그대로
            return 'next';
          });
        }
        if (result.status >= 200 && result.status < 300) {
          item.result = 'ok';
          setState(item, typeof j.okText === 'function' ? j.okText(result.data) : j.okText, 'is-ok');
        } else if (result.status === 423) {
          item.result = 'later';
          setState(item, '나중에 — ' + errorMessage(result.data), 'is-deny');
        } else {
          item.result = 'deny';
          setState(item, errorMessage(result.data), 'is-deny');   // 한 건 작업과 같은 서버 문장 그대로
        }
        return 'next';
      })
      .catch(() => {
        item.result = 'deny';
        setState(item, GENERIC_ERROR, 'is-deny');
        return 'next';
      })
      .then((what) => {
        if (what === 'next') { j.index += 1; step(j); }
      });
  }

  /**
   * 세션이 이 작업을 시작한 그대로인가 — `/api/me` 를 새로 묻는다(셸과 같은 창구 · no-store). 그대로면 null, 아니면 멈출 까닭:
   * 'login' 세션 없음(401) · 'other' 다른 계정 · 'session' 같은 계정이지만 세션이 바뀜(토큰이 세션 id 로 계산되므로 토큰이 다르다) ·
   * 'check' 물어보지 못함 — 모르는 채 거부로 세지 않는다.
   */
  function sessionCheck() {
    if (typeof window.qarediMe !== 'function') { return Promise.resolve('check'); }
    return window.qarediMe(true).then((result) => {
      if (result.status === 401 || !result.me) { return 'login'; }
      if (meUsername === null || result.me.username !== meUsername) { return 'other'; }
      if (result.me.csrf_token !== csrfToken) { return 'session'; }
      return null;
    }, () => 'check');
  }

  function pause(j, why) {
    j.paused = why;
    const left = j.items.length - j.index;
    const item = j.items[j.index];
    if (why === 'login') {
      setState(item, '멈춤 — 로그인이 필요합니다', 'is-deny');
      setText(el.stopText, '로그인이 끝나 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 새 탭에서 다시 로그인한 뒤 「이어서 하기」를 누르세요.');
    } else if (why === 'other') {
      setState(item, '멈춤 — 다른 계정으로 로그인됨', 'is-deny');
      setText(el.stopText, '다른 계정으로 로그인했습니다 — 이어서 할 수 없습니다. 이 화면을 새로 고친 뒤 다시 고르세요.');
    } else if (why === 'session') {
      setState(item, '멈춤 — 로그인이 바뀌었습니다', 'is-deny');
      setText(el.stopText, '다른 탭에서 다시 로그인해 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 「이어서 하기」를 누르면 같은 계정인지 확인하고 이어 갑니다.');
    } else if (why === 'check') {
      setState(item, '멈춤 — 로그인 상태를 확인하지 못했습니다', 'is-deny');
      setText(el.stopText, '로그인 상태를 확인하지 못해 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 「이어서 하기」를 누르면 다시 확인합니다.');
    } else {
      setState(item, '멈춤 — 비밀번호 확인이 필요합니다', 'is-deny');
      setText(el.stopText, '비밀번호 확인이 필요해 멈췄습니다 — 남은 ' + String(left) + '개는 그대로 있습니다. 「이어서 하기」를 누르면 다시 묻습니다.');
    }
    show(el.login, why === 'login');
    show(el.resume, why !== 'other');   // 다른 계정이면 잇지 않는다
    if (el.resume) { el.resume.disabled = false; }
    show(el.stop, true);
    if (el.close) { el.close.disabled = false; }
    setText(el.summary, tally(j) + ' · 남은 ' + String(left) + '개');
    changed();
  }

  function cont(j) {
    if (job !== j || j.paused === null) { return; }
    j.paused = null;
    j.sessionChecked = false;   // 이어 간 구간의 첫 403 도 다시 세션을 본다
    show(el.stop, false);
    if (el.close) { el.close.disabled = true; }
    setText(el.summary, '');
    changed();
    step(j);
  }

  /* 「이어서 하기」 — 멈춘 까닭과 상관없이(로그인 · 재인증 · 세션 바뀜 · 확인 못 함) **보내기 전에** `/api/me` 를 새로 물어 같은 계정인지
     본다. 다른 계정이면 요청 0 으로 끝. 같은 계정이면 새 CSRF 토큰(이 모듈 · 셸의 재인증 창 · 로그아웃 둘 다)으로 멈춘 줄부터.
     재인증 멈춤이면 그 줄을 다시 보내 창구가 또 403 reauth 를 주고, 그때 셸의 재인증 창이 뜬다. */
  function resumeClick() {
    const j = job;
    if (j === null || j.paused === null) { return; }
    if (el.resume) { el.resume.disabled = true; }
    const ask = typeof window.qarediMe === 'function'
      ? window.qarediMe(true)
      : Promise.resolve({ status: 0, me: null });
    ask.then((result) => {
      if (el.resume) { el.resume.disabled = false; }
      if (job !== j || j.paused === null) { return; }
      if (result.status === 401 || !result.me) {
        if (j.paused !== 'login') { pause(j, 'login'); }   // 세션이 끝났다 — 로그인 멈춤으로(「로그인 화면 열기」가 보인다)
        setText(el.stopText, '아직 로그인되지 않았습니다 — 새 탭에서 로그인한 뒤 다시 「이어서 하기」를 누르세요. 남은 ' + String(j.items.length - j.index) + '개는 그대로 있습니다.');
        return;
      }
      if (meUsername === null || result.me.username !== meUsername) {
        // 다른 계정으로 로그인했다 — 그 계정으로 이 작업을 잇지 않는다
        pause(j, 'other');
        return;
      }
      if (typeof result.me.csrf_token === 'string') {
        csrfToken = result.me.csrf_token;   // 새 세션의 토큰
        if (typeof window.qarediSetCsrf === 'function') { window.qarediSetCsrf(result.me.csrf_token); }   // 셸도 같은 값으로
      }
      cont(j);
    }).catch(() => {
      if (el.resume) { el.resume.disabled = false; }
      setText(el.stopText, GENERIC_ERROR);
    });
  }

  function finish(j) {
    j.finished = true;
    show(el.stop, false);
    if (el.close) { el.close.disabled = false; }
    let line = tally(j);
    const undoItems = j.items.filter((item) => item.result === 'ok' && !item.parent);
    if (typeof j.undo === 'function' && el.undo && undoItems.length > 0) {
      line += ' — ' + String(BULK_UNDO_MS / 1000) + '초 안에 되돌릴 수 있습니다';
      undoJob = j.undo(undoItems.map((item) => ({ id: item.id, name: item.name })));
      show(el.undo, true);
      undoTimer = window.setTimeout(hideUndo, BULK_UNDO_MS);
    }
    setText(el.summary, line);
    changed();
    if (typeof j.done === 'function') { j.done(j); }
  }

  if (el.resume) { el.resume.addEventListener('click', resumeClick); }
  if (el.undo) {
    el.undo.addEventListener('click', () => {
      const next = undoJob;
      hideUndo();
      if (next) { start(next); }
    });
  }
  if (el.close) {
    el.close.addEventListener('click', () => {
      // 끝났거나 멈춘 작업만 닫힌다(도는 동안은 단추가 비활성). 멈춘 작업을 닫으면 남은 줄은 보내지 않는다.
      if (job !== null && !job.finished && job.paused === null) { return; }
      job = null;
      hideUndo();
      show(el.panel, false);
      changed();
    });
  }
  return {
    start: start,
    busy: busy,
    current: () => job,
    cancelPaused() {
      if (job && job.paused !== null) { job = null; hideUndo(); show(el.panel, false); changed(); }
    },
    /** 창을 다시 열 때 — **끝난** 작업의 진행 표를 치운다(도는 중이거나 멈춘 작업은 남은 줄과 함께 그대로 둔다) */
    reset() {
      if (busy()) { return; }
      job = null;
      hideUndo();
      show(el.panel, false);
      changed();
    }
  };
}

/** 트리 순서(부모가 하위보다 먼저)로 고른 페이지를 늘어놓고, 고른 조상이 있는 것에는 그 조상 항목을 `parent` 로 단다 */
function bulkTreeItems(pickedIds, pages) {
  const byParent = new Map();
  const byId = new Map();
  for (const page of pages) {
    const old = byId.get(page.id);
    byId.set(page.id, {...old, ...page, parent_id: Object.prototype.hasOwnProperty.call(page,'parent_id') ? page.parent_id : old?.parent_id ?? null});
  }
  for (const page of byId.values()) {
    const key = typeof page.parent_id === 'string' ? page.parent_id : '';
    if (!byParent.has(key)) byParent.set(key, []);
    byParent.get(key).push(page);
  }
  const order = [];
  const visited = new Set();
  const walk = (key, depth) => {
    if (depth > 16) { return; }
    for (const node of byParent.get(key) || []) { if (visited.has(node.id)) continue; visited.add(node.id); order.push(node); walk(node.id, depth + 1); }
  };
  walk('', 0);
  for (const node of byId.values()) {
    if (!visited.has(node.id) && !byId.has(node.parent_id)) { visited.add(node.id); order.push(node); walk(node.id, 1); }
  }
  for (const node of byId.values()) {
    if (!visited.has(node.id)) { visited.add(node.id); order.push(node); walk(node.id, 1); }
  }
  const items = [];
  const itemOf = new Map();
  for (const node of order) {
    if (!pickedIds.has(node.id)) { continue; }
    const item = { id: node.id, name: (node.icon ? node.icon + ' ' : '') + node.title, parent: null };
    // 가장 가까운 고른 조상(있으면) — 그 조상과 함께 처리된다
    let up = typeof node.parent_id === 'string' ? byId.get(node.parent_id) : undefined;
    for (let guard = 0; up && guard < 16; guard++) {
      if (itemOf.has(up.id)) { item.parent = itemOf.get(up.id); break; }
      up = typeof up.parent_id === 'string' ? byId.get(up.parent_id) : undefined;
    }
    itemOf.set(node.id, item);
    items.push(item);
  }
  return items;
}

// UUID selection is shared by every sidebar appearance. Capabilities come only from list responses.
let documentTrashGuard = async () => () => {
};
let documentTrashHomeRefresh = null;
let documentTrashHomeBusy = () => false;
let documentTrashRestoreBusy = () => false;
let documentTrashController = null;
let documentTrashAfterClose = () => {
};
let documentTrashAfterRestore = () => {
};
function documentTrashRow(row, page) {
  if (!documentTrashController || !page || typeof page.id !== 'string') return;
  documentTrashController.attach(row, page);
}
function initDocumentTrash() {
  const get = (name) => document.getElementById('document-trash-' + name);
  const dialog = get('dialog'), results = get('results');
  if (!dialog || !results || documentTrashController) return;
  let selected = new Map(), selecting = false, pending = [], confirming = false, opener = null;
  let release = null;
  let activeJob = null;
  let reauthClosing = false;
  const pages = new Map();
  const tools = (name) => document.getElementById('sidebar-document-' + name);
  const runner = createBulkRunner({
    panel:get('panel'),title:get('progress-title'),body:get('body'),stop:get('stop'),stopText:get('stop-text'),login:get('login'),resume:get('resume'),summary:get('summary'),undo:get('undo'),close:get('close')
  }, {
    onChange:sync,beforeReauth:()=>{
      if(results.open){
        reauthClosing=true;
        results.close();
      }
    },afterReauth:()=>{
      if(!results.open)results.showModal();
    }
  });
  function sync() {
    const sidebar = document.getElementById('nav-community-tree')?.closest('aside') || document.querySelector('.sidebar');
    for (const check of document.querySelectorAll('[data-sidebar-document-check]')) {
      check.hidden = !selecting;
      if (check.parentElement.classList.contains('document-select-hit')) check.parentElement.hidden = !selecting;
      check.checked = selected.has(check.dataset.sidebarDocumentCheck);
      check.disabled = check.dataset.canDelete !== 'true' || runner.busy() || confirming;
    }
    if(tools('selection')) tools('selection').hidden = !selecting;
    if(tools('select')) {
      tools('select').setAttribute('aria-pressed',String(selecting));
      tools('select').disabled=runner.busy() || confirming;
    }
    if(tools('count')) tools('count').textContent=selected.size+'개 선택';
    if(tools('trash')) tools('trash').disabled=selected.size===0 || runner.busy() || confirming;
    for(const key of ['all','clear','done']) if(tools(key)) tools(key).disabled=runner.busy() || confirming;
    if(sidebar) sidebar.classList.toggle('is-document-selecting',selecting);
  }
  function attach(row,page) {
    const existing=pages.get(page.id);
    // Unknown parents from recent/favorites must not erase a known tree relationship.
    pages.set(page.id,{
      ...existing,...page,parent_id:Object.prototype.hasOwnProperty.call(page,'parent_id') ? page.parent_id : existing?.parent_id ?? null
    });
    if (page.can_delete !== true) selected.delete(page.id);
    else if (selected.has(page.id)) selected.set(page.id,pages.get(page.id));
    row.dataset.documentId=page.id;
    const check=document.createElement('input');
    check.type='checkbox';
    check.dataset.sidebarDocumentCheck=page.id;
    check.dataset.canDelete=String(page.can_delete===true);
    check.hidden=!selecting;
    check.checked=selected.has(page.id);
    check.disabled=page.can_delete!==true;
    check.setAttribute('aria-label',page.title+' 선택');
    if(page.can_delete!==true) check.title='이 문서를 휴지통으로 옮길 권한이 없습니다.';
    check.addEventListener('click',e=>e.stopPropagation());
    check.addEventListener('change',()=>{
      if(check.checked){
        if(selected.size>=BULK_PICK_MAX){
          check.checked=false;
          note('한 번에 100개까지 선택합니다.');
        }else selected.set(page.id,pages.get(page.id));
      }else selected.delete(page.id);
      sync();
    });
    const hit = document.createElement('label');
    hit.className = 'document-select-hit';
    hit.hidden = !selecting;
    hit.appendChild(check);
    hit.addEventListener('click',event=>event.stopPropagation());
    row.prepend(hit);
    if(page.can_delete===true){
      const trash=document.createElement('button');
      trash.type='button';
      trash.className='document-row-trash btn btn--icon';
      trash.dataset.documentTrash=page.id;
      trash.setAttribute('aria-label',page.title+' 휴지통으로 옮기기');
      trash.appendChild(makeIcon('trash'));
      trash.addEventListener('click',e=>{
        e.preventDefault();
        e.stopPropagation();
        open([pages.get(page.id)],trash);
      });
      row.appendChild(trash);
    }
    sync();
  }
  function note(text){
    if(tools('note'))tools('note').textContent=text;
  }
  async function refresh(){
    selected.clear();
    pages.clear();
    sync();
    lastSpaces = null;
    await Promise.allSettled([loadSpaces(),loadFavoritesNav(),loadRecentNav(),documentTrashHomeRefresh?.()]);
    if(documentTrashController.refreshProjects) await documentTrashController.refreshProjects();
  }
  function restoreFocus(){
    if(opener?.isConnected && !opener.disabled)opener.focus();
    else tools('select')?.focus();
  }
  function open(items,trigger){
    if(runner.busy() || confirming || documentTrashHomeBusy() || documentTrashRestoreBusy())return;
    pending=items.filter(p=>p.can_delete===true).slice(0,BULK_PICK_MAX);
    if(!pending.length)return;
    opener=trigger;
    setText(get('msg'),'');
    setText(get('lead'),'고른 '+items.length+'개 중 삭제 가능한 '+pending.length+'개를 휴지통으로 옮깁니다.'+(items.length>pending.length?' 권한 없는 '+(items.length-pending.length)+'개는 제외됩니다.':''));
    clear(get('items'));
    for(const page of pending){
      const li=document.createElement('li');
      li.textContent=page.title;
      get('items').appendChild(li);
    }
    if(!dialog.open)dialog.showModal();
  }
  async function confirm(){
    if(confirming || runner.busy() || !pending.length)return;
    confirming=true;
    get('confirm').disabled=true;
    get('cancel').disabled=true;
    sync();
    try{
      // Refresh complete existing sidebar trees, including team spaces, before deriving cascade roots.
      lastSpaces = null;
      await loadSpaces(true);
      pending = pending.map(page => pages.get(page.id) || page);
      if (pending.some(page=>page.can_delete !== true)) throw new Error('문서의 삭제 권한이 바뀌었습니다. 목록을 확인한 뒤 다시 선택하세요.');
      release=await documentTrashGuard(pending);
      const items=bulkTreeItems(new Set(pending.map(p=>p.id)),Array.from(pages.values()));
      dialog.close();
      selected.clear();
      sync();
      results.showModal();
      const finish=async(job)=>{
        if(release){
          const done=release;
          release=null;
          done(job);
        }
        await refresh();
      };
      activeJob = {
        title:'휴지통으로 옮기기',items,okText:'휴지통으로 옮김',withParentText:'부모와 함께 휴지통으로 옮김',parentFailText:'부모를 옮기지 못해 그대로 유지',send:item=>api('DELETE','/api/pages/'+item.id,{
        }, {
          sessionRetry:false
        }),done:finish,
        undo:done=>({
          title:'되돌리기',items:done.map(p=>({
            ...p,parent:null
          })),okText:data=>data?.moved_to_root?'되살림 — 맨 위로 이동':'되살림',send:item=>api('POST','/api/pages/'+item.id+'/restore',{
          }, {
            sessionRetry:false
          }),done:job=>{
            documentTrashAfterRestore(job);
            return refresh();
          }
        })
      };
      runner.start(activeJob);
      pending=[];
    }catch(e){
      if(release){
        release();
        release=null;
      }setText(get('msg'),e.message || GENERIC_ERROR);
    }
    finally{
      confirming=false;
      get('confirm').disabled=false;
      get('cancel').disabled=false;
      sync();
    }
  }
  get('confirm').addEventListener('click',confirm);
  get('cancel').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('cancel',e=>{
    if(confirming)e.preventDefault();
  });
  dialog.addEventListener('close',()=>{
    if(!confirming){
      pending=[];
      restoreFocus();
    }
  });
  results.addEventListener('cancel',e=>{
    if(runner.busy() && !runner.current()?.paused)e.preventDefault();
  });
  get('close').addEventListener('click',()=>{
    if(!runner.busy() || runner.current()?.paused){
      results.close();
    }
  });
  results.addEventListener('close',()=>{
    if (reauthClosing) {
      reauthClosing=false;
      return;
    }
    const job = runner.current() || activeJob;
    if (release) {
      const done=release;
      release=null;
      done(job);
    }
    runner.cancelPaused();
    runner.reset();
    activeJob=null;
    restoreFocus();
    documentTrashAfterClose();
  });
  tools('select')?.addEventListener('click',()=>{
    selecting=!selecting;
    selected.clear();
    sync();
  });
  tools('done')?.addEventListener('click',()=>{
    selecting=false;
    selected.clear();
    sync();
    tools('select').focus();
  });
  tools('clear')?.addEventListener('click',()=>{
    selected.clear();
    sync();
  });
  tools('trash')?.addEventListener('click',()=>open(Array.from(selected.keys()).map(id=>pages.get(id)).filter(Boolean),tools('trash')));
  tools('all')?.addEventListener('click',()=>{
    selected.clear();
    const visible=new Map();
    for(const check of document.querySelectorAll('[data-sidebar-document-check]')) if(check.dataset.canDelete==='true' && check.checkVisibility() && check.closest('aside'))visible.set(check.dataset.sidebarDocumentCheck,pages.get(check.dataset.sidebarDocumentCheck));
    for(const [id,page]of visible){
      if(selected.size>=BULK_PICK_MAX)break;
      selected.set(id,page);
    }
    note('현재 보이는 허용 문서 '+visible.size+'개 중 '+selected.size+'개 선택'+(visible.size>BULK_PICK_MAX?' — 나머지는 다음 작업에서 선택하세요.':''));
    sync();
  });
  documentTrashController={
    attach,open,busy:()=>runner.busy() || confirming,register:page=>{
      const old=pages.get(page.id);
      pages.set(page.id,{
        ...old,...page,parent_id:Object.prototype.hasOwnProperty.call(page,'parent_id') ? page.parent_id : old?.parent_id ?? null
      });
    },refresh
  };
  documentTrashController.refreshProjects = async () => {
    const nav = document.getElementById('nav-work-projects');
    if (!nav) return;
    const response = await api('GET','/api/pages?view=projects');
    if (response.status !== 200 || !Array.isArray(response.data.pages)) return;
    for (const row of nav.querySelectorAll('.document-project-row')) row.remove();
    const fixed = nav.querySelector('#nav-work-all-projects,#nav-work-new-project');
    for (const page of response.data.pages) {
      const row=document.createElement('div');
      row.className='nav-tree__row document-project-row';
      const link=document.createElement('a');
      link.className='nav-tree__link';
      link.href=COMMUNITY_BASE+'/p/'+page.id;
      link.dataset.projectId=page.id;
      link.dataset.projectPurpose=page.purpose;
      link.textContent=(page.icon ? page.icon+' ' : '')+page.title;
      const icon=document.createElement('span');
      icon.className='work-dot';
      if(page.color)icon.dataset.color=page.color;
      icon.setAttribute('aria-hidden','true');
      link.prepend(icon);
      row.appendChild(link);
      row.appendChild(navStar(page));
      attach(row,page);
      nav.insertBefore(row,fixed);
    }
    sync();
  };
  for(const anchor of document.querySelectorAll('#nav-work-projects a[data-project-id]')){
    const row=document.createElement('div');
    row.className='nav-tree__row document-project-row';
    anchor.before(row);
    row.appendChild(anchor);
    anchor.classList.add('nav-tree__link');
    row.appendChild(navStar({
      id:anchor.dataset.projectId,title:anchor.textContent.trim()
    }));
    attach(row,{
      id:anchor.dataset.projectId,title:anchor.textContent.trim(),can_delete:anchor.dataset.canDelete==='true'
    });
  }
}

/* ─── 내 공간 (community-home) ───────────────────────────────── */

/** 손님 「내 공간」 설명 — home_page.php 가 서버에서 고르는 글자와 같은 문장(디버깅 묶음 U3) */
const GUEST_SPACE_SUB = '손님 계정은 읽기만 합니다. 공유받은 페이지와 볼 수 있는 게시판 글을 읽을 수 있습니다.';

function trustListBadges(page,box) {
  for (const text of [page.page_kind==='procedure' ? '절차서' : '',
    ({verified:'검증됨',expired:'검증 만료'})[page.verify_state] || '',page.due ? '기한 '+page.due : '']) {
    if (!text) continue;const badge=document.createElement('span');badge.className='badge badge--internal';badge.textContent=text;box.appendChild(badge);
  }
}
function initHome() {
  const msg = document.getElementById('msg');
  const list = document.getElementById('community-list');
  const treeBox = document.getElementById('community-tree');
  const empty = document.getElementById('community-empty');
  const count = document.getElementById('community-count');
  const search = document.getElementById('community-search');
  const spaceSelect = document.getElementById('community-space');
  const spaceLabel = document.getElementById('community-space-label');
  const headTitle = document.getElementById('community-space-title');
  const headSub = document.getElementById('community-space-sub');
  const statusSelect = document.getElementById('community-status');
  const verifySelect = document.getElementById('community-verify');
  const dueSelect = document.getElementById('community-due');
  const sharedWrap = document.getElementById('community-shared-wrap');
  const sharedBox = document.getElementById('community-shared');
  const sharedCount = document.getElementById('community-shared-count');
  const spaceId = currentSpaceId();
  let timer = null;

  /** 지금 고른 상태 거르개. 값은 `<option>` 에 적힌 것뿐이고 화면이 지어내지 않는다 (4-D). */
  function pickedStatus() {
    return statusSelect ? statusSelect.value : '';
  }

  /** 목록 창구 주소 — 고른 공간과 상태를 꼬리표로 붙인다 (4-B · 4-D). */
  function listPath(extra) {
    const parts = [];
    if (spaceId !== '') { parts.push('space=' + encodeURIComponent(spaceId)); }
    const status = pickedStatus();
    if (status !== '') { parts.push('status=' + encodeURIComponent(status)); }
    if (verifySelect && verifySelect.value) parts.push('verify=' + encodeURIComponent(verifySelect.value));
    if (dueSelect && dueSelect.value) parts.push('due=' + encodeURIComponent(dueSelect.value));
    if (extra) { parts.push(extra); }
    return '/api/pages' + (parts.length > 0 ? '?' + parts.join('&') : '');
  }

  /** 화면 머리를 창구가 준 공간 요약으로 바꾼다. 화면이 이름을 지어내지 않는다. */
  function drawSpaceHead(space) {
    if (!space) { return; }
    drawAutomationSpace(space, api);
    setText(headTitle, space.kind === 'team' ? space.name + ' · 팀 문서' : '내 문서');
    // 디버깅 묶음 U3 — 손님(쓰기 자격 없음 — `/api/me` 의 can_community_write, 서버 판정 값)에게는 할 수 없는 「공개 범위」 문장 대신 읽기 안내
    setText(headSub, space.kind === 'team'
      ? '팀이 함께 쓰는 문서입니다. 새 문서를 작성하면 지금 선택한 팀 공간에 만들어집니다.'
      : (meCanWrite
        ? '메모·업무 방법·인수인계를 문서로 남기세요. 개인 문서는 비공개로 시작하고, 문서마다 공개 범위를 고를 수 있어요.'
        : GUEST_SPACE_SUB));
    document.title = (space.kind === 'team' ? space.name : '내 문서') + ' · 모아 업무';
  }

  // 지금 보고 있는 공간의 종류. 창구가 준 값이고 화면이 정하지 않는다.
  let spaceKind = 'personal';

  function homeTrashButton(page) {
    const button = document.createElement('button');
    button.type = 'button'; button.className = 'btn btn--icon document-row-trash';
    button.dataset.homeDocumentTrash = page.id;
    button.setAttribute('aria-label', page.title + ' 휴지통으로 옮기기');
    button.appendChild(makeIcon('trash'));
    button.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); documentTrashController?.open([page], button); });
    return button;
  }
  function drawSearch() {
    clear(list);
    for (const page of resultPages) list.appendChild(card(page));
    syncSel();
  }

  function card(page) {
    const a = document.createElement('a');
    a.className = 'card card--link';
    a.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);

    const title = document.createElement('span');
    title.className = 'card__title';
    title.textContent = (page.icon ? page.icon + ' ' : '') + page.title;

    const desc = document.createElement('span');
    desc.className = 'card__desc';
    desc.textContent = page.excerpt && page.excerpt !== '' ? page.excerpt : '(내용 없음)';

    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(page.updated_at);

    a.appendChild(title);
    a.appendChild(desc);
    trustListBadges(page,a);
    // 공개 범위 배지 (4-B). 팀 공간의 페이지에는 자기 범위가 없어 값이 와도 그리지 않는다.
    const mark = spaceKind === 'team' ? null : visibilityBadge(page.visibility);
    if (mark) { a.appendChild(mark); }
    // 상태 배지 (4-D). 창구가 준 값이고, 없으면 아무것도 붙지 않는다.
    // 4-D 의 담당자 표시는 5-A 에서 뺐다 — 담당자는 이제 소유자이고, 지정을 멈췄다.
    const st = statusBadge(page.status);
    if (st) { a.appendChild(st); }
    a.appendChild(when);
    const wrap = document.createElement('div');
    wrap.className = 'document-search-row';
    if (sel.on) wrap.appendChild(selCheck(page));
    wrap.appendChild(a);
    if (page.can_delete === true) wrap.appendChild(homeTrashButton(page));
    documentTrashController?.register(page);
    return wrap;
  }

  /** 「공유받은 페이지」 줄 하나 — 누구의 공간인지와 받은 권한을 함께 보인다 (5-A).
      구조는 DESIGN 2.3 의 누를 수 있는 카드 그대로다: `.card__body` > `.card__head`(제목 + 배지) ·
      `.card__desc`(어느 공간) · `.card__meta`(고친 때). 새 클래스 0. */
  function sharedCard(page) {
    const a = document.createElement('a');
    a.className = 'card card--link';
    a.setAttribute('href', COMMUNITY_BASE + '/p/' + page.id);

    const bodyBox = document.createElement('span');
    bodyBox.className = 'card__body';
    const head = document.createElement('span');
    head.className = 'card__head';
    const title = document.createElement('span');
    title.className = 'card__title';
    title.textContent = (page.icon ? page.icon + ' ' : '') + page.title;
    head.appendChild(title);
    const level = shareBadge(page.share_level);
    if (level) { head.appendChild(level); }
    const st = statusBadge(page.status);
    if (st) { head.appendChild(st); }
    bodyBox.appendChild(head);
    trustListBadges(page,bodyBox);

    const where = document.createElement('span');
    where.className = 'card__desc';
    where.textContent = page.space && typeof page.space.name === 'string' ? page.space.name : '';
    bodyBox.appendChild(where);

    const when = document.createElement('span');
    when.className = 'card__meta';
    when.textContent = shortWhen(page.updated_at);
    bodyBox.appendChild(when);

    a.appendChild(bodyBox);
    return a;
  }

  /** 공유받은 페이지 — 공간을 가리지 않는 하나의 창구 호출이다 (SQL WHERE, C:T-57). */
  function loadShared() {
    const status = pickedStatus();
    const path = '/api/pages?shared=me' + (status === '' ? '' : '&status=' + encodeURIComponent(status));
    return api('GET', path).then((result) => {
      if (result.status !== 200) { show(sharedWrap, false); return; }
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      clear(sharedBox);
      for (const page of pages) { sharedBox.appendChild(sharedCard(page)); }
      // 규모 D2a A5 (DESIGN 2.4) — 서버는 1,000 까지만 센다(넘으면 total_capped → 「1,000+」)
      setText(sharedCount, pages.length === 0 ? '' : (result.data.total_capped === true ? '1,000+' : String(result.data.total)) + '개');
      // 공유받은 페이지가 하나도 없으면 묶음 자체를 보이지 않는다(빈 칸을 늘리지 않는다).
      show(sharedWrap, pages.length > 0);
    }).catch(() => { show(sharedWrap, false); });
  }

  /** 트리 한 줄 — 제목 링크 + 「하위 추가」. 하위가 있으면 `<details>` 안에 들어간다. */
  function treeRow(node, kidCount) {
    const row = document.createElement('span');
    row.className = 'page-tree__row';
    documentTrashController?.register(node);
    // 라운드 B — 「선택」 모드면 줄 맨 앞에 체크(`<summary>` 안이어도 체크를 누르면 접힘·펼침은 일어나지 않는다 — 체크가 누름의 대상)
    if (sel.on) { row.appendChild(selCheck(node)); }

    // 삼각형 자리. 하위가 없어도 자리를 비워 둬 제목의 왼쪽 끝이 층마다 가지런하다.
    const caret = document.createElement('span');
    caret.className = 'page-tree__caret';
    caret.setAttribute('aria-hidden', 'true');
    caret.textContent = kidCount > 0 ? '▸' : '';

    const link = document.createElement('a');
    link.className = 'page-tree__link';
    link.setAttribute('href', COMMUNITY_BASE + '/p/' + node.id);
    link.textContent = (node.icon ? node.icon + ' ' : '') + node.title;

    const mark = spaceKind === 'team' ? null : visibilityBadge(node.visibility);

    /* 독립 검토 L-1 — 「하위 추가」는 **쓸 수 있는 사람에게만** 만든다(`/api/me` 의 can_community_write — 위 `meCanWrite`).
       손님에게는 줄에 올리면 나타났다가 누르면 권한 없음만 나오던 단추다. 숨김은 편의이고 `POST /api/pages` 는 정책이 다시 막는다. */
    const add = meCanWrite ? document.createElement('button') : null;
    if (add) {
    add.type = 'button';
    add.className = 'btn btn--small btn--quiet page-tree__add';
    add.textContent = '하위 추가';
    add.addEventListener('click', (event) => {
      // `<summary>` 안에서 눌렸을 때 접힘·펼침이 함께 일어나지 않게 기본 동작을 멈춘다.
      event.preventDefault();
      event.stopPropagation();
      add.disabled = true;
      // 부모가 정해지면 공간도 따라 정해진다 — 창구가 부모의 공간을 그대로 쓴다.
      api('POST', '/api/pages', { parent_id: node.id })
        .then((result) => {
          if (result.status === 401) { toLogin(); return; }
          if (result.status === 201 && result.data.page && typeof result.data.page.id === 'string') {
            // 사용감 라운드 1 UX-1 — 트리의 「하위 추가」도 「새 페이지」와 같이 곧바로 편집 모드 · 제목 칸부터
            window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id + '?edit=1&new=1');
            return;
          }
          setText(msg, errorMessage(result.data));
          add.disabled = false;
        })
        .catch(() => { setText(msg, GENERIC_ERROR); add.disabled = false; });
    });
    }

    row.appendChild(caret);
    row.appendChild(link);
    trustListBadges(node,row);
    if (mark) { row.appendChild(mark); }
    // 상태 배지 (4-D) — 창구가 준 값이고, 없으면 아무것도 붙지 않는다.
    const st = statusBadge(node.status);
    if (st) { row.appendChild(st); }
    if (kidCount > 0) {
      const badge = document.createElement('span');
      badge.className = 'muted';
      badge.textContent = '하위 ' + String(kidCount);
      row.appendChild(badge);
    }
    if (add) { row.appendChild(add); }
    if (node.can_delete === true) row.appendChild(homeTrashButton(node));
    return row;
  }

  /**
   * 평평한 목록을 트리로 그린다. **접기·펼치기는 `<details>` 가 한다** — 여닫는 스크립트가
   * 없으므로 JS 가 멈춰 있어도(그리고 키보드로도) 동작한다.
   * 깊이는 서버가 8 까지만 허용하지만, 그래도 12 에서 멈춘다 — 자료가 어긋나도 화면이
   * 끝없이 파고들지 않게 하는 안전장치다.
   */
  function treeLevel(byParent, key, depth) {
    const box = document.createDocumentFragment();
    const nodes = byParent.get(key) || [];
    if (depth > 12) { return box; }

    for (const node of nodes) {
      const kids = byParent.get(node.id) || [];
      if (kids.length === 0) {
        const leaf = document.createElement('div');
        leaf.className = 'page-tree__node';
        leaf.appendChild(treeRow(node, 0));
        box.appendChild(leaf);
        continue;
      }
      const details = document.createElement('details');
      details.className = 'page-tree__node';
      details.open = true;
      const summary = document.createElement('summary');
      summary.appendChild(treeRow(node, kids.length));
      const kidBox = document.createElement('div');
      kidBox.className = 'page-tree__kids';
      kidBox.appendChild(treeLevel(byParent, node.id, depth + 1));
      details.appendChild(summary);
      details.appendChild(kidBox);
      box.appendChild(details);
    }
    return box;
  }

  function loadTree(ticket) {
    return api('GET', listPath('tree=1')).then((result) => {
      if (ticket !== listGeneration) return;
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      setText(msg, '');
      if (result.data.space) { spaceKind = result.data.space.kind; drawSpaceHead(result.data.space); }
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      treePages = pages;
      treeTotal = result.data.total;
      // 라운드 B — 새 트리에 없는 페이지(지웠거나 옮겨 나간 것)는 고른 것에서 뺀다
      const here = new Set(pages.map((p) => p.id));
      for (const id of Array.from(sel.picked.keys())) { if (!here.has(id)) { sel.picked.delete(id); } }
      drawTree();
    });
  }

  /** 받아 둔 트리(treePages)를 그린다 — 「선택」 모드를 켜고 끌 때도 창구를 다시 부르지 않고 이것만 부른다 */
  function drawTree() {
    clear(treeBox);
    const pages = treePages;
    const byParent = new Map();
    for (const page of pages) {
      const key = typeof page.parent_id === 'string' && pages.some(item=>item.id===page.parent_id) ? page.parent_id : '';
      if (!byParent.has(key)) { byParent.set(key, []); }
      byParent.get(key).push(page);
    }
    treeBox.appendChild(treeLevel(byParent, '', 0));
    show(treeBox, pages.length > 0);
    show(list, false);
    show(empty, pages.length === 0);
    setText(count, pages.length === 0 ? '' : '페이지 ' + String(treeTotal) + '개');
    syncSel();
  }

  /* ─── 「선택」 모드 · 일괄 작업 (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.3 · 결정 M-4 · M-6 · M-7) ───────────
   * 트리 줄마다 체크 → 막대의 옮기기 · 즐겨찾기 · 휴지통. 100개까지. 공간을 바꾸면 화면이 다시 열려 풀리고(M-4), 상태 거르개를 바꾸면
   * 풀린다(목록이 바뀐다). 찾기(카드 목록)를 시작하면 모드가 끝난다. Esc · ✕ · 「선택 끝내기」로 끝낸다. 공개 범위 일괄은 없다(M-6).
   * 동작은 **한 건과 같은 창구**를 페이지마다 한 번씩(진행기 `createBulkRunner`) — 새 서버 창구 0. 판단은 창구가 한다.
   */
  let treePages = [];
  let treeTotal = 0;
  let resultPages = [];
  let listGeneration = 0;
  const sel = { on: false, picked: new Map(), full: false };
  const selButton = document.getElementById('community-select');
  const selLabel = document.getElementById('community-select-label');
  const bulkBar = document.getElementById('community-bulk-bar');
  const bulkCount = document.getElementById('community-bulk-count');
  const bulkNote = document.getElementById('community-bulk-note');
  const bulkMoveBtn = document.getElementById('community-bulk-move');
  const bulkFavBtn = document.getElementById('community-bulk-fav');
  const bulkTrashBtn = document.getElementById('community-bulk-trash');
  const bulkDoneBtn = document.getElementById('community-bulk-done');
  const runner = createBulkRunner({
    panel: document.getElementById('community-bulk-panel'),
    title: document.getElementById('community-bulk-title'),
    body: document.getElementById('community-bulk-body'),
    stop: document.getElementById('community-bulk-stop'),
    stopText: document.getElementById('community-bulk-stop-text'),
    login: document.getElementById('community-bulk-login'),
    resume: document.getElementById('community-bulk-resume'),
    summary: document.getElementById('community-bulk-summary'),
    undo: document.getElementById('community-bulk-undo'),
    close: document.getElementById('community-bulk-close')
  }, { onChange: () => syncSel() });

  function selCheck(node) {
    const check = document.createElement('input');
    check.type = 'checkbox';
    check.setAttribute('data-bulk', '');
    check.setAttribute('data-bulk-page', node.id);
    check.setAttribute('aria-label', '「' + (node.icon ? node.icon + ' ' : '') + node.title + '」 고르기');
    check.checked = sel.picked.has(node.id);

    check.addEventListener('click', (event) => {
      event.stopPropagation();   // 줄(요약) 누름으로 번지지 않게 — 체크 자체의 켜고 끄기는 그대로
      sel.full = false;
      if (check.checked) {
        if (sel.picked.size >= BULK_PICK_MAX) {
          check.checked = false;
          sel.full = true;
        } else {
          sel.picked.set(node.id, node);
        }
      } else {
        sel.picked.delete(node.id);
      }
      syncSel();
    });
    const hit = document.createElement('label'); hit.className = 'document-select-hit';
    hit.appendChild(check); hit.addEventListener('click',event=>event.stopPropagation());
    return hit;
  }

  /** 체크·막대·「선택」 단추를 지금 상태에 맞춘다 */
  function syncSel() {
    for (const check of document.querySelectorAll('#community-tree input[data-bulk-page], #community-list input[data-bulk-page]')) {
      check.checked = sel.picked.has(check.getAttribute('data-bulk-page'));
    }
    if (selButton) {
      selButton.setAttribute('aria-pressed', sel.on ? 'true' : 'false');
      setText(selLabel, sel.on ? '선택 끝내기' : '선택하기');
    }
    const n = sel.picked.size;
    const running = runner.busy() || documentTrashController?.busy() || documentTrashRestoreBusy();
    setText(bulkCount, String(n) + '개 선택됨');
    // 안내는 막대 아래 한 줄(막대는 폰 폭에서도 한 줄 — 개수 + 아이콘 단추)
    let note = '줄 앞의 체크로 고르세요 — 한 번에 ' + String(BULK_PICK_MAX) + '개까지 · Esc 로 끝냅니다.';
    if (sel.full) {
      note = '한 번에 ' + String(BULK_PICK_MAX) + '개까지 고를 수 있습니다 — 더 고르려면 먼저 몇 개를 푸세요.';
    } else if (running && n > 0) {
      note = '앞 작업이 끝나면 고른 페이지로 할 수 있습니다.';
    }
    setText(bulkNote, note);
    for (const b of [bulkMoveBtn, bulkFavBtn]) { if (b) b.disabled = n === 0 || running; }
    if (bulkTrashBtn) bulkTrashBtn.disabled = !Array.from(sel.picked.values()).some(page=>page.can_delete===true) || running;
    show(bulkBar, sel.on);
    show(bulkNote, sel.on);
  }

  function selMode(on) {
    sel.on = on;
    sel.picked.clear();
    sel.full = false;
    if (treeBox.hidden) { drawSearch(); } else { drawTree(); }
    syncSel();
  }

  function selItems() {
    return bulkTreeItems(new Set(sel.picked.keys()), treeBox.hidden ? resultPages : treePages);
  }

  documentTrashHomeRefresh = () => load(search ? search.value : '');
  documentTrashHomeBusy = () => runner.busy();
  document.getElementById('community-bulk-all')?.addEventListener('click', () => {
    sel.picked.clear();
    const eligible = treeBox.hidden ? resultPages : treePages;
    for (const page of eligible) { if (sel.picked.size >= BULK_PICK_MAX) break; sel.picked.set(page.id, page); }
    syncSel();
    setText(bulkNote, '현재 목록 문서 ' + eligible.length + '개 중 ' + sel.picked.size + '개 선택' + (eligible.length > BULK_PICK_MAX ? ' — 나머지는 다음 작업에서 선택하세요.' : ''));
  });
  document.getElementById('community-bulk-clear')?.addEventListener('click', () => { sel.picked.clear(); sel.full = false; syncSel(); });

  function afterBulk() {
    // 고른 것은 시작할 때 풀렸다(startBulk) — 도는 동안 새로 고른 것은 남기고, 트리에서 사라진 줄은 loadTree 가 뺀다
    lastSpaces = null;
    loadSpaces();   // 사이드바 트리도 창구의 새 답으로(옮기거나 지운 페이지가 그 자리에 남지 않게)
    return load(search ? search.value : '').catch(() => setText(msg, GENERIC_ERROR));
  }

  /** 작업을 시작한다 — 고른 것은 작업으로 넘어가며 풀린다(t-box 파일 화면과 같다 · 도는 동안 새로 고르면 다음 작업 몫) */
  function startBulk(next) {
    if (documentTrashController?.busy() || documentTrashRestoreBusy()) return;
    if (!runner.start(next)) { return; }
    sel.picked.clear();
    sel.full = false;
    syncSel();
  }

  /* 옮기기 — 고른 페이지와 그 하위는 새 부모 후보에서 뺀다(서버가 cycle 로 다시 막는다). 부모가 늘 하위보다 먼저 간다. */
  const bulkMoveDialog = document.getElementById('bulk-move-dialog');
  const bulkMoveTree = document.getElementById('bulk-move-tree');
  const bulkMoveMsg = document.getElementById('bulk-move-msg');
  const bulkMoveMeta = document.getElementById('bulk-move-meta');
  const bulkMoveConfirm = document.getElementById('bulk-move-confirm');
  let bulkMovePick = null;
  let movePages = [];
  let moveLoading = false;
  let bulkMovePicked = false;

  function bulkMoveButton(label, id) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'move-tree__pick';
    button.setAttribute('role', 'option');
    button.setAttribute('aria-selected', 'false');
    button.textContent = label;
    button.addEventListener('click', () => {
      bulkMovePick = id;
      bulkMovePicked = true;
      for (const other of bulkMoveTree.querySelectorAll('.move-tree__pick')) {
        const on = other === button;
        other.className = 'move-tree__pick' + (on ? ' is-selected' : '');
        other.setAttribute('aria-selected', on ? 'true' : 'false');
      }
    });
    return button;
  }

  function bulkMoveLevel(byParent, key, depth, skip) {
    const box = document.createDocumentFragment();
    if (depth > 12) { return box; }
    for (const node of byParent.get(key) || []) {
      if (skip.has(node.id)) { continue; }   // 고른 페이지와 그 아래는 새 부모가 될 수 없다
      const wrap = document.createElement('div');
      wrap.className = 'move-tree__node';
      wrap.appendChild(bulkMoveButton((node.icon ? node.icon + ' ' : '') + node.title, node.id));
      const kids = bulkMoveLevel(byParent, node.id, depth + 1, skip);
      if (kids.childNodes.length > 0) {
        const kidBox = document.createElement('div');
        kidBox.className = 'move-tree__kids';
        kidBox.appendChild(kids);
        wrap.appendChild(kidBox);
      }
      box.appendChild(wrap);
    }
    return box;
  }

  async function openBulkMove() {
    if (!bulkMoveDialog || sel.picked.size === 0 || moveLoading || runner.busy() || documentTrashController?.busy() || documentTrashRestoreBusy()) return;
    moveLoading = true;
    try {
      const response = await api('GET','/api/pages?tree=1'+(spaceId ? '&space='+encodeURIComponent(spaceId) : ''));
      if (response.status !== 200) { setText(msg,errorMessage(response.data)); return; }
      movePages = Array.isArray(response.data.pages) ? response.data.pages : [];
    } finally { moveLoading = false; }
    if (!sel.picked.size) return;
    bulkMovePick = null;
    bulkMovePicked = false;
    setText(bulkMoveMsg, '');
    setText(bulkMoveMeta, '고른 페이지 ' + String(sel.picked.size) + '개');
    clear(bulkMoveTree);
    const byParent = new Map();
    for (const page of movePages) {
      const k = typeof page.parent_id === 'string' ? page.parent_id : '';
      if (!byParent.has(k)) { byParent.set(k, []); }
      byParent.get(k).push(page);
    }
    bulkMoveTree.appendChild(bulkMoveButton('맨 위 (상위 없음)', null));
    bulkMoveTree.appendChild(bulkMoveLevel(byParent, '', 0, new Set(sel.picked.keys())));
    if (!bulkMoveDialog.open) { bulkMoveDialog.showModal(); }
  }

  function runBulkMove() {
    if (!bulkMovePicked) { setText(bulkMoveMsg, '새 상위 페이지를 고르세요'); return; }
    const target = bulkMovePick;
    const targetNode = target === null ? null : movePages.find((p) => p.id === target);
    const where = targetNode ? (targetNode.icon ? targetNode.icon + ' ' : '') + targetNode.title : '맨 위';
    bulkMoveDialog.close();
    const items = bulkTreeItems(new Set(sel.picked.keys()),movePages);
    startBulk({
      title: '「' + where + '」 아래로 옮기기',
      items: items,
      okText: '옮김',
      withParentText: '부모와 함께 옮겨짐',
      parentFailText: '옮기지 않음 — 부모를 옮기지 못했습니다',
      send: (item) => api('PATCH', '/api/pages/' + item.id + '/move', { parent_id: target }, { sessionRetry: false }),
      done: () => { afterBulk(); }
    });
  }

  function runBulkFavorite() {
    const items = selItems().map((item) => ({ id: item.id, name: item.name, parent: null }));   // 즐겨찾기는 페이지마다(하위도 제 줄)
    if (items.length === 0) { return; }
    startBulk({
      title: '즐겨찾기에 넣기',
      items: items,
      okText: (data) => (data && data.listed === false ? '넣음 — 목록에는 나오지 않습니다' : '넣음'),
      send: (item) => api('PUT', '/api/pages/' + item.id + '/favorite', {}, { sessionRetry: false }),
      done: () => { loadFavoritesNav(); afterBulk(); }
    });
  }

  function openBulkDelete() {
    if (sel.picked.size) documentTrashController?.open(Array.from(sel.picked.values()), bulkTrashBtn);
  }

  if (selButton) {
    selButton.addEventListener('click', () => { selMode(!sel.on); });
  }
  if (bulkDoneBtn) {
    bulkDoneBtn.addEventListener('click', () => {
      selMode(false);
      if (selButton) { selButton.focus(); }
    });
  }
  if (bulkMoveBtn) { bulkMoveBtn.addEventListener('click', openBulkMove); }
  if (bulkFavBtn) { bulkFavBtn.addEventListener('click', runBulkFavorite); }
  if (bulkTrashBtn) { bulkTrashBtn.addEventListener('click', openBulkDelete); }
  if (bulkMoveDialog) {
    for (const close of bulkMoveDialog.querySelectorAll('[data-bulk-move-close]')) {
      close.addEventListener('click', () => bulkMoveDialog.close());
    }
    if (bulkMoveConfirm) { bulkMoveConfirm.addEventListener('click', runBulkMove); }
  }
  document.addEventListener('keydown', (event) => {
    // Esc — 「선택」 모드를 끝낸다. 창·메뉴가 열려 있으면 그쪽이 먼저 닫힌다(DESIGN 2.7 · 2.15)
    if (event.key !== 'Escape' || !sel.on) { return; }
    if (document.querySelector('dialog[open]') || document.querySelector('details.menu[open]')) { return; }
    selMode(false);
    if (selButton) { selButton.focus(); }
  });

  function load(q) {
    const ticket = ++listGeneration;
    sel.picked.clear(); sel.full = false; syncSel();
    // 「공유받은 페이지」는 공간·찾기와 상관없이 늘 같은 자리에 있다 (5-A).
    loadShared();
    // 찾기를 껐으면 트리로 돌아간다 — 트리 안에서 찾는 것은 뜻이 없다.
    if (!q || q === '') { return loadTree(ticket); }
    return api('GET', listPath('q=' + encodeURIComponent(q))).then((result) => {
      if (ticket !== listGeneration) return;
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      setText(msg, '');
      if (result.data.space) { spaceKind = result.data.space.kind; drawSpaceHead(result.data.space); }
      clear(list);
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      resultPages = pages;
      drawSearch();
      show(treeBox, false);
      show(list, true);
      show(empty, pages.length === 0);
      // 규모 D2a A6 (DESIGN 2.4) — 서버는 1,000 까지만 센다(넘으면 total_capped → 「1,000+」)
      setText(count, pages.length === 0 ? '' : '페이지 ' + (result.data.total_capped === true ? '1,000+' : String(result.data.total)) + '개');
    });
  }

  /* 공간 고르개 (4-B) — 항목은 창구가 준 것만이다. 고르면 주소를 바꿔 **화면을 다시
     연다**: 고른 공간이 주소에 남아야 새로 고침·뒤로 가기·즐겨찾기가 같은 곳을 연다. */
  function fillSpacePicker(spaces) {
    if (!spaceSelect) { return; }
    clear(spaceSelect);
    for (const space of spaces) {
      const option = document.createElement('option');
      option.value = String(space.id);
      option.setAttribute('data-kind', space.kind);
      option.textContent = space.name;
      if (spaceId === '' ? space.kind === 'personal' : spaceId === String(space.id)) {
        option.selected = true;
      }
      spaceSelect.appendChild(option);
    }
    // 고를 것이 하나뿐이면 고르개를 보이지 않는다(팀이 없는 계정).
    show(spaceSelect, spaces.length > 1);
    show(spaceLabel, spaces.length > 1);
  }

  if (spaceSelect) {
    spaceSelect.addEventListener('change', () => {
      const picked = spaceSelect.value;
      const option = spaceSelect.options[spaceSelect.selectedIndex];
      const kind = option && option.getAttribute('data-kind') === 'personal' ? 'personal' : 'team';
      window.location.assign(spaceHref(picked, kind));
    });
  }

  // 개인 공간은 첫 방문에 서버가 만든다(「시작하기」 페이지와 함께).
  // 팀 공간은 `GET /api/spaces` 가 만든다 — 두 창구 모두 두 번 불러도 결과가 같다.
  api('GET', '/api/me/space')
    .then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return null; }
      return loadSpaces();
    })
    .then((spaces) => {
      if (spaces === null) { return null; }
      const list2 = Array.isArray(spaces) ? spaces : [];
      fillSpacePicker(list2);
      for (const space of list2) {
        if (spaceId === '' ? space.kind === 'personal' : spaceId === String(space.id)) {
          drawSpaceHead(space);
        }
      }
      return load('');
    })
    .catch(() => setText(msg, GENERIC_ERROR));

  if (search) {
    search.addEventListener('input', () => {
      ++listGeneration;
      sel.picked.clear(); sel.full = false; syncSel();
      // 라운드 B — 찾기(카드 목록)를 시작하면 「선택」 모드가 끝난다(모드는 트리에서만 — 고른 것이 화면에서 사라진 채 남지 않게)
      if (sel.on && search.value !== '') {
        sel.on = false;
        sel.picked.clear();
        sel.full = false;
        syncSel();
      }
      window.clearTimeout(timer);
      timer = window.setTimeout(() => { load(search.value).catch(() => setText(msg, GENERIC_ERROR)); }, 300);
    });
  }

  // 상태 거르개 (4-D) — 고르면 창구를 다시 부른다. 화면이 목록에서 줄을 빼지 않는다.
  for (const filter of [statusSelect,verifySelect,dueSelect].filter(Boolean)) {
    filter.addEventListener('change', () => {
      sel.picked.clear();   // 라운드 B — 목록이 바뀌면 고른 것이 풀린다(M-4)
      sel.full = false;
      load(search ? search.value : '').catch(() => setText(msg, GENERIC_ERROR));
    });
  }

  // 휴지통 (4-C) — 되살린 뒤에는 트리를 다시 받는다(화면이 카드를 지어내지 않는다).
  bindTrash(() => { documentTrashController?.refresh(); });
  // 7-B — 「템플릿에서」 창. 만든 페이지는 지금 보고 있는 공간에 생긴다(「새 페이지」와 같은 규칙 — 창구가 다시 판단한다)
  bindTemplateGallery({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin, base: COMMUNITY_BASE, currentSpaceId: currentSpaceId, canWrite: meCanWrite
  });
  // 7-C — 「임베드 허용 목록」 창(단추는 창구가 준 `can_manage` 일 때만)
  bindEmbedAdmin({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin
  });
  // 7-D1 — 「많이 읽힌 문서」 창(단추는 관리자에게만)
  bindPopular({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin, base: COMMUNITY_BASE
  });
  // 7-D2 — 「팔레트 관리」 창(메뉴 항목은 관리자에게만 — 판단은 창구가 준 can_manage, 막는 것은 서버)
  bindPalettes({
    api: api, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
    genericError: GENERIC_ERROR, toLogin: toLogin
  });
  // B1-2 — 「저장 안 된 글 n개」(B1-1 이 없어도 그 페이지로 찾아가게)
  drawHomeDrafts(msg);
}

/**
 * Q:F-4 B1-2 설계 5 — 첫 화면의 「저장 안 된 글 n개」 줄. **이 사람 몫만**(`drafts.entries` 가 내 접두만 훑는다).
 *
 * 항목이 있을 때만 열쇠를 받고(한 번), 풀리는 것만 센다 — 풀리지 않는 것(열쇠가 바뀜·손상)은 `read` 가 조용히 지운다.
 * 줄마다 그 페이지 링크이고 글자는 푼 제목(댓글은 앞 40자)이다 — 전부 `textContent`. 구성 요소는 DESIGN 2.6 `.notice`
 * (주황 — 「알고 하라」) 그대로다(새 클래스 0). 열쇠를 못 받으면(503 등) 줄을 그리지 않는다 — 셀 수 없는 것을 세지 않는다.
 *
 * @param {Element|null} anchor  이 요소 **뒤**에 줄을 넣는다(`#msg`)
 */
function drawHomeDrafts(anchor) {
  if (!drafts || !drafts.available() || !anchor || !anchor.parentNode) { return; }
  const list = drafts.entries();
  if (list.length === 0) { return; }
  drafts.ready().then((ok) => {
    if (!ok) { return null; }
    return Promise.all(list.map((e) => drafts.read(e.key).then((got) => (got ? { entry: e, data: got.data } : null))));
  }).then((items) => {
    const found = (items || []).filter((x) => x !== null)
      .sort((a, b) => b.entry.savedAt - a.entry.savedAt);
    if (found.length === 0) { return; }
    const box = document.createElement('section');
    box.className = 'notice';
    box.setAttribute('role', 'status');
    box.setAttribute('data-drafts-home', '');
    const title = document.createElement('p');
    title.className = 'notice__title';
    title.textContent = '저장 안 된 글 ' + String(found.length) + '개 — 이 브라우저에 암호화해 남아 있습니다. 페이지를 열어 불러오거나 버리세요.';
    box.appendChild(title);
    const ul = document.createElement('ul');
    ul.className = 'notice__list';
    for (const x of found) {
      const li = document.createElement('li');
      const a = document.createElement('a');
      a.setAttribute('href', COMMUNITY_BASE + '/p/' + x.entry.pageId);
      if (x.entry.kind === 'page') {
        const t = typeof x.data.title === 'string' && x.data.title.trim() !== '' ? x.data.title : '제목 없는 페이지';
        a.textContent = t;
      } else {
        const b = typeof x.data.body === 'string' ? x.data.body.replace(/\s+/g, ' ').trim() : '';
        const cut = Array.from(b).slice(0, 40).join('');
        a.textContent = (x.entry.target === 'new' ? '댓글: 「' : '답글: 「') + cut + (Array.from(b).length > 40 ? '…」' : '」');
      }
      li.appendChild(a);
      const when = document.createElement('span');
      when.className = 'muted';
      when.textContent = ' · ' + localWhen(x.entry.savedAt);
      li.appendChild(when);
      ul.appendChild(li);
    }
    box.appendChild(ul);
    anchor.parentNode.insertBefore(box, anchor.nextSibling);
  }).catch(() => { /* 임시저장은 편의 — 첫 화면을 막지 않는다 */ });
}

/* ─── 휴지통 (4-C, C:F-29) ─────────────────────────────────────
 *
 * 「내 공간」 화면의 단추 하나가 창을 열고, 목록은 창구 `GET /api/me/trash` 가 주는 것만
 * 그린다 — 화면에는 「내가 지운 것인가」·「되살릴 수 있나」를 따지는 코드가 한 줄도 없다
 * (DESIGN 원칙 1, PRD S-01). 되살리기는 서버가 다시 판단한다.
 *
 * 한 줄은 **한 번의 삭제**다. 함께 내려간 하위는 「하위 n」으로만 세고, 되살리면 함께
 * 돌아온다. 부모가 그 사이 다시 지워졌다면 창구가 뿌리로 올리고 그 사실을 응답에 적는데,
 * 그때는 **그렇게 됐다고 말한다** — 조용히 옮기지 않는다.
 *
 * @param onRestored 되살린 뒤 목록을 다시 그리는 일 (initHome 이 넘긴다)
 */
const TRASH_MOVE_REASONS = {
  parent_deleted: '되살렸습니다. 상위 페이지가 아직 휴지통에 있어 맨 위로 올렸습니다',
  parent_narrower: '되살렸습니다. 상위 페이지가 그 사이 더 좁게 닫혀 맨 위로 올렸습니다'
};

function bindTrash(onRestored) {
  const button = document.getElementById('community-trash');
  const dialog = document.getElementById('trash-dialog');
  const tbody = document.getElementById('trash-body');
  const empty = document.getElementById('trash-empty');
  const meta = document.getElementById('trash-meta');
  const msg = document.getElementById('trash-msg');
  if (!button || !dialog || !tbody) { return; }

  /* 라운드 B — 여러 줄 되살리기(지시서 1.3 「휴지통 화면에서 되살리기」). 줄 앞 체크 · 머리 체크(모두) · 100개까지 → 「되살리기」가
     고른 줄마다 `POST /api/pages/{id}/restore` 를 한 번씩(한 줄 되살리기와 같은 창구 · 같은 판정). 진행은 창 안의 표. */
  const picked = new Map();
  let shown = [];   // 지금 그린 줄 [{page, check}]
  const checkAll = document.getElementById('trash-check-all');
  const bulkBarEl = document.getElementById('trash-bulk-bar');
  const bulkCountEl = document.getElementById('trash-bulk-count');
  const bulkRestore = document.getElementById('trash-bulk-restore');
  const trashRunner = createBulkRunner({
    panel: document.getElementById('trash-bulk-panel'),
    title: document.getElementById('trash-bulk-title'),
    body: document.getElementById('trash-bulk-body'),
    stop: document.getElementById('trash-bulk-stop'),
    stopText: document.getElementById('trash-bulk-stop-text'),
    login: document.getElementById('trash-bulk-login'),
    resume: document.getElementById('trash-bulk-resume'),
    summary: document.getElementById('trash-bulk-summary')
  }, {
    onChange: () => syncPicked(),
    // 재인증 창은 모달이다 — 모달 안에 모달을 열지 않게 휴지통 창을 잠시 닫았다가 통과하면 다시 연다(popular.js 와 같은 방식)
    beforeReauth: () => { if (dialog.open) { dialog.close(); } },
    afterReauth: () => { if (!dialog.open) { dialog.showModal(); } }
  });
  let restoring = 0;
  documentTrashRestoreBusy = () => trashRunner.busy() || restoring > 0;
  let full = false;

  function syncPicked() {
    let onShown = 0;
    for (const s of shown) {
      const on = picked.has(s.page.id);
      s.check.checked = on;
      if (on) { onShown += 1; }
    }
    if (checkAll) {
      checkAll.checked = shown.length > 0 && onShown === shown.length;
      checkAll.indeterminate = onShown > 0 && onShown < shown.length;
      checkAll.disabled = shown.length === 0 || trashRunner.busy();
    }
    const n = picked.size;
    setText(bulkCountEl, String(n) + '개 선택됨');
    // 100개에 닿으면 창의 알림 줄에(막대는 좁은 창에서도 한 줄로 둔다)
    if (full) { setText(msg, '한 번에 ' + String(BULK_PICK_MAX) + '개까지 고를 수 있습니다 — 더 고르려면 먼저 몇 개를 푸세요.'); }
    if (bulkRestore) { bulkRestore.disabled = n === 0 || trashRunner.busy(); }
    show(bulkBarEl, n > 0);
  }

  function pickCheck(page) {
    const check = document.createElement('input');
    check.type = 'checkbox';
    check.setAttribute('data-bulk', '');
    check.setAttribute('data-bulk-trash', page.id);
    check.setAttribute('aria-label', '「' + (page.icon ? page.icon + ' ' : '') + page.title + '」 고르기');
    check.addEventListener('click', () => {
      full = false;
      if (check.checked) {
        if (picked.size >= BULK_PICK_MAX) { check.checked = false; full = true; } else { picked.set(page.id, page); }
      } else {
        picked.delete(page.id);
      }
      syncPicked();
    });
    shown.push({ page: page, check: check });
    return check;
  }

  if (checkAll) {
    checkAll.addEventListener('click', () => {
      const all = shown.length > 0 && shown.every((s) => picked.has(s.page.id));
      full = false;
      for (const s of shown) {
        if (all) { picked.delete(s.page.id); } else if (!picked.has(s.page.id)) {
          if (picked.size >= BULK_PICK_MAX) { full = true; break; }
          picked.set(s.page.id, s.page);
        }
      }
      syncPicked();
    });
  }
  if (bulkRestore) {
    bulkRestore.addEventListener('click', () => {
      if (documentTrashController?.busy() || documentTrashHomeBusy()) return;
      const items = Array.from(picked.values()).map((p) => ({ id: p.id, name: (p.icon ? p.icon + ' ' : '') + p.title, parent: null }));
      if (items.length === 0) { return; }
      setText(msg, '');
      const started = trashRunner.start({
        title: '되살리기',
        items: items,
        okText: (data) => (data && data.moved_to_root === true
          ? (TRASH_MOVE_REASONS[data.moved_reason] || '되살렸습니다. 원래 자리로 돌아갈 수 없어 맨 위로 올렸습니다')
          : '되살림'),
        send: (item) => api('POST', '/api/pages/' + item.id + '/restore', {}, { sessionRetry: false }),
        done: () => {
          // 고른 것은 시작할 때 풀렸다 — 도는 동안 새로 고른 것은 남기고, 목록에서 사라진 줄은 load 가 뺀다
          lastSpaces = null;
          loadSpaces();   // 사이드바 트리도 새 답으로
          load().then(() => { if (typeof onRestored === 'function') { onRestored(); } }).catch(() => setText(msg, GENERIC_ERROR));
        }
      });
      // 작업을 시작하면 고른 것은 작업으로 넘어가며 풀린다(t-box 파일 화면 · 내 공간 트리와 같다)
      if (started) { picked.clear(); full = false; syncPicked(); }
    });
  }

  function row(page) {
    const tr = document.createElement('tr');

    const title = document.createElement('td');
    title.appendChild(pickCheck(page));   // 라운드 B — 줄 앞 체크(열을 늘리지 않는다)
    title.appendChild(document.createTextNode((page.icon ? page.icon + ' ' : '') + page.title));
    tr.appendChild(title);

    const space = document.createElement('td');
    space.textContent = page.space && page.space.name ? page.space.name : '—';
    tr.appendChild(space);

    const kids = document.createElement('td');
    kids.textContent = page.descendants > 0 ? String(page.descendants) + '개' : '—';
    tr.appendChild(kids);

    const when = document.createElement('td');
    setWhenParts(when, page.deleted_at);   // 디버깅 묶음 U M2
    when.setAttribute('title', String(page.deleted_at));
    tr.appendChild(when);

    const actions = document.createElement('td');
    actions.className = 'actions';
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'btn btn--small btn--quiet';
    back.textContent = '되살리기';
    back.addEventListener('click', () => {
      if (documentTrashController?.busy() || documentTrashHomeBusy() || documentTrashRestoreBusy()) return;
      restoring += 1;
      back.disabled = true;
      api('POST', '/api/pages/' + page.id + '/restore', {})
        .then((result) => {
          if (result.status === 401) { toLogin(); return; }
          if (result.status !== 200) {
            setText(msg, errorMessage(result.data));
            back.disabled = false;
            return;
          }
          /* 왜 맨 위로 올렸는지는 **창구가 준 까닭**으로 가른다. 화면이 「상위가 지워졌겠지」
             하고 지어내지 않는다 (DESIGN 원칙 1). 모르는 까닭이면 까닭 없이 알린다. */
          setText(msg, result.data.moved_to_root !== true
            ? '되살렸습니다'
            : (TRASH_MOVE_REASONS[result.data.moved_reason] || '되살렸습니다. 원래 자리로 돌아갈 수 없어 맨 위로 올렸습니다'));
          return load().then(() => { if (typeof onRestored === 'function') { onRestored(); } });
        })
        .catch(() => { setText(msg, GENERIC_ERROR); back.disabled = false; }).finally(() => { restoring -= 1; });
    });
    actions.appendChild(back);
    tr.appendChild(actions);
    return tr;
  }

  function load() {
    return api('GET', '/api/me/trash').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      clear(tbody);
      shown = [];
      for (const page of pages) { tbody.appendChild(row(page)); }
      // 목록에 없는 줄(되살렸거나 30일 정리된 것)은 고른 것에서 뺀다
      const here = new Set(pages.map((p) => p.id));
      for (const id of Array.from(picked.keys())) { if (!here.has(id)) { picked.delete(id); } }
      syncPicked();
      show(empty, pages.length === 0);
      setText(meta, pages.length === 0
        ? ''
        : String(pages.length) + '건 · ' + String(result.data.keep_days) + '일 뒤 지워집니다');
    });
  }

  function openTrash() {
    if (typeof dialog.showModal !== 'function') { return; }
    setText(msg, '');
    // 라운드 B — 창을 새로 열 때마다 고른 것·끝난 진행 표를 치운다(도는 중인 작업은 그대로 둔다)
    trashRunner.reset();
    if (!trashRunner.busy()) { picked.clear(); full = false; }
    if (!dialog.open) { dialog.showModal(); }
    load().catch(() => setText(msg, GENERIC_ERROR));
  }
  button.addEventListener('click', openTrash);
  /* 디버깅 묶음 U3 — 사이드바 「내 휴지통」(모든 커뮤니티 화면 · 주소 `/community/?trash=1`). 이 화면(첫 화면)에서는 옮겨 가지 않고
     같은 창을 연다(좁은 폭이면 열린 서랍을 먼저 닫는다). 다른 화면에서 온 꼬리표는 창을 연 뒤 주소에서 뗀다 — 새로 고침이 창을 또 열지 않게. */
  const navTrash = document.getElementById('nav-community-trash');
  if (navTrash) {
    navTrash.addEventListener('click', (event) => {
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
      event.preventDefault();
      const drawerToggle = document.getElementById('nav-toggle');
      if (drawerToggle && drawerToggle.getAttribute('aria-expanded') === 'true') { drawerToggle.click(); }
      openTrash();
    });
  }
  let wantTrash = false;
  try { wantTrash = new URLSearchParams(window.location.search).get('trash') === '1'; } catch (e) { wantTrash = false; }
  if (wantTrash) {
    try {
      const u = new URL(window.location.href);
      u.searchParams.delete('trash');
      window.history.replaceState(window.history.state, '', u.pathname + u.search + u.hash);
    } catch (e) { /* 주소를 못 고쳐도 창은 연다 */ }
    openTrash();
  }
  for (const close of dialog.querySelectorAll('[data-trash-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
}

/** 시각을 짧게 (t-box 의 `shortWhen` 과 같은 뜻 — 이 파일은 모듈이라 따로 둔다) */
function shortWhen(value) {
  if (typeof value !== 'string' || value === '') { return ''; }
  return value.slice(0, 16).replace('T', ' ');
}

/**
 * 표 칸의 시각 — 날짜와 시각을 **두 조각**(`.nowrap` 둘 + 빈칸)으로 (디버깅 묶음 U M2 · 2026-09-26, t-box `addWhenCell` 과 같은 방식).
 * 좁은 칸에서 날짜의 하이픈이 줄바꿈 자리가 되어 「2026-」/「09-26」/「11:54」 세 줄로 갈라졌다 — 이제 많아야 두 줄이다.
 * 글자(textContent)는 전과 같다. 요소로 만들고 글자는 textContent 로만 넣는다.
 */
function setWhenParts(el, value) {
  const text = shortWhen(value);
  const m = text.match(/^(\S+) (\S+)$/);   // String#match — 보안 린트의 코드 실행 함수 이름 규칙(S-09)에 걸리지 않게
  el.textContent = '';
  if (!m) { el.textContent = text; return; }
  const day = document.createElement('span');
  day.className = 'nowrap';
  day.textContent = m[1];
  const clock = document.createElement('span');
  clock.className = 'nowrap';
  clock.textContent = m[2];
  el.appendChild(day);
  el.appendChild(document.createTextNode(' '));
  el.appendChild(clock);
}

/* ─── 새 페이지 만들기 (두 화면 모두의 단추) ─────────────────── */

function bindNewPage() {
  const buttons = [document.getElementById('community-new'), document.getElementById('nav-community-new')];
  const msg = document.getElementById('msg');
  for (const button of buttons) {
    if (!button) { continue; }
    button.addEventListener('click', () => {
      button.disabled = true;
      /* 4-B — 지금 보고 있는 공간에 만든다. 값은 주소의 `?space=` 이고 창구가 다시
         판단한다(팀 공간이면 그 팀원만). 개인 공간이면 꼬리표가 없으므로 칸을 보내지
         않고, 창구의 기본값(내 개인 공간)이 그대로 쓰인다. */
      const here = currentSpaceId();
      api('POST', '/api/pages', here === '' ? {} : { space_id: here })
        .then((result) => {
          if (result.status === 401) { toLogin(); return; }
          if (result.status === 201 && result.data.page && typeof result.data.page.id === 'string') {
            /* T:F-72 2장 ① (취합 결정) — 「새 페이지」는 열자마자 편집 모드: 게시판 「새 글」과 같은 `?edit=1`
               (페이지 화면이 창구의 can_edit 을 보고 편집을 켠 뒤 주소에서 꼬리표를 지운다). */
            // 사용감 라운드 1 UX-1 — `new=1`: 편집기가 커서를 **제목 칸**에 둔다(읽은 뒤 주소에서 지운다)
            window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id + '?edit=1&new=1');
            return;
          }
          setText(msg, errorMessage(result.data));
          button.disabled = false;
        })
        .catch(() => { setText(msg, GENERIC_ERROR); button.disabled = false; });
    });
  }
}

/* ─── 게시판 (5-C) ─────────────────────────────────────────────
 *
 * 사이드바 「게시판」 묶음 · 게시판 화면(community-board) · 페이지 화면의 게시 창이 쓴다.
 * ★ 무엇이 보이고 무엇을 할 수 있나는 창구가 준 값(`boards`·`can_write`·`can_moderate`·`can_manage`)뿐이다.
 *   이 파일에는 등급·역할을 따지는 코드가 없다 (DESIGN 원칙 1, PRD S-01).
 * ★ 등급 배지의 색은 t-box 파일 등급 배지 그대로다(DESIGN 2.2 — 새 색값 0).
 */
const BOARD_LEVEL_LABELS = {
  // audience — 게시판 글의 상태 글자 「게시됨 · …」(사용감 라운드 1 UX-6). 등급 낱말은 DESIGN 5장(app.js LEVEL_LABELS 「공개 — 손님까지 모두」 …)을 줄인 것
  public: { text: '공개', badge: 'badge--public', audience: '손님까지 모두 봄' },
  internal: { text: '사내', badge: 'badge--internal', audience: '사내 모두 봄' },
  team: { text: '팀', badge: 'badge--team', audience: '게시판 팀만 봄' },
  'admin-only': { text: '관리자', badge: 'badge--admin-only', audience: '관리자만 봄' }
};
const BOARD_KIND_LABELS = {
  notice: { text: '공지', icon: 'log' },
  discussion: { text: '토론', icon: 'users' },
  qa: { text: '질문답변', icon: 'help' },
  anonymous: { text: '익명', icon: 'users' }
};
/** 게시판 창구가 돌려주는 거부 사유 → 사람 문장. 없으면 서버가 준 문구를 그대로 쓴다. */
const BOARD_REASONS = {
  board_inactive: '보관 중인 게시판입니다. 읽기만 됩니다',
  already_published: '이미 게시 중입니다. 다른 게시판에 걸려면 먼저 게시를 해제하세요',
  not_published: '게시 중인 페이지가 아닙니다',
  published: '게시 중에는 공개 범위를 바꿀 수 없습니다. 먼저 게시를 해제하세요',
  not_qa: '질문과 답변 게시판의 글만 「해결됨」을 켤 수 있습니다'
};

/** 게시판 한 줄의 배지(등급). 모르는 값이면 만들지 않는다(지어내지 않는다). */
function boardLevelBadge(level) {
  const entry = BOARD_LEVEL_LABELS[level];
  if (!entry) { return null; }
  const span = document.createElement('span');
  span.className = 'badge ' + entry.badge;
  span.setAttribute('title', level);
  span.textContent = entry.text;
  return span;
}

/** 이 화면이 보고 있는 게시판 번호 (게시판 화면에서만). 숫자가 아니면 빈 글자. */
function currentBoardId() {
  const meta = document.getElementById('board-meta');
  const raw = meta ? (meta.getAttribute('data-board-id') || '') : '';
  return /^[1-9][0-9]{0,8}$/.test(raw) ? raw : '';
}

/**
 * 사이드바 「게시판」 묶음 — 창구 `GET /api/boards` 가 준 **볼 수 있는 게시판만**.
 * 돌려주는 값은 창구의 답 전체(게시 창·관리 창이 다시 쓴다). 실패하면 묶음은 숨은 채다.
 */
let boardsCache = null;
/** 카페 C2 — 게시판 **전부**(관리 창 · 게시 창이 쓴다 — 자리를 가리지 않는다). 사이드바는 아래 loadBoards(자리별) */
function allBoards() {
  return api('GET', '/api/boards').then((result) => {
    if (result.status !== 200 || !Array.isArray(result.data.boards)) { return null; }
    boardsCache = result.data;
    return result.data;
  }).catch(() => null);
}
/* 카페 C2 (ARCH 25.2 · DESIGN 2.29) — 사이드바 게시판 묶음은 셸의 자리만: 업무 셸 `?area=work`(공지사항 · 질문과 답변) ·
   「우리 공간」 셸 `?area=cafe`(자유 · 익명 …). 거르는 것은 창구의 SQL WHERE 다. */
function loadBoards() {
  return api('GET', '/api/boards?area=' + (IS_CAFE ? 'cafe' : 'work')).then((result) => {
    if (result.status !== 200 || !Array.isArray(result.data.boards)) { return null; }
    const box = document.getElementById('nav-community-boards');
    const title = document.getElementById('nav-community-board-title');
    if (box) {
      clear(box);
      const here = currentBoardId();
      for (const board of result.data.boards) {
        const a = document.createElement('a');
        a.className = 'nav__item' + (String(board.id) === here ? ' is-active' : '');
        a.setAttribute('href', COMMUNITY_BASE + '/b/' + String(board.id));
        if (String(board.id) === here) { a.setAttribute('aria-current', 'page'); }
        const kind = BOARD_KIND_LABELS[board.board_kind];
        a.appendChild(makeIcon(kind ? kind.icon : 'files'));
        const name = document.createElement('span');
        name.textContent = board.name + (board.is_active ? '' : ' (보관)');
        a.appendChild(name);
        box.appendChild(a);
      }
      show(box, result.data.boards.length > 0);
      show(title, result.data.boards.length > 0);
    }
    return result.data;
  }).catch(() => null);
}

/** 쉼표로 나눈 태그 글자 → 목록(앞뒤 공백을 떼고 빈 것은 뺀다). 검사는 창구가 한다. */
function splitTags(raw) {
  return String(raw || '').split(/[,，]/).map((t) => t.trim()).filter((t) => t !== '');
}

/** 게시판 한 곳에서 쓰인 태그(자동완성) → `<datalist>` 를 채운다. 창구가 볼 수 있는 글의 태그만 준다. */
function fillTagOptions(listEl, boardId, q) {
  if (!listEl || !boardId) { return Promise.resolve([]); }
  const path = '/api/boards/' + String(boardId) + '/tags' + (q ? '?q=' + encodeURIComponent(q) : '');
  return api('GET', path).then((result) => {
    const tags = result.status === 200 && Array.isArray(result.data.tags) ? result.data.tags : [];
    clear(listEl);
    for (const t of tags) {
      const option = document.createElement('option');
      option.value = t.tag;
      listEl.appendChild(option);
    }
    return tags;
  }).catch(() => []);
}

/* ─── 게시판 화면 (community-board) ──────────────────────────── */

function initBoard() {
  const boardId = currentBoardId();
  if (boardId === '') { return; }
  const msg = document.getElementById('msg');
  const titleEl = document.getElementById('board-title');
  const subEl = document.getElementById('board-sub');
  const levelEl = document.getElementById('board-level');
  const kindEl = document.getElementById('board-kind');
  const inactiveEl = document.getElementById('board-inactive');
  const newButton = document.getElementById('board-new');
  const manageButton = document.getElementById('board-manage');
  const searchEl = document.getElementById('board-search');
  const tagEl = document.getElementById('board-tag');
  const tagOptions = document.getElementById('board-tag-options');
  const tagsBox = document.getElementById('board-tags');
  const sortEl = document.getElementById('board-sort');
  const bodyEl = document.getElementById('board-body');
  const emptyEl = document.getElementById('board-empty');
  const pageInfo = document.getElementById('board-page-info');
  const prevButton = document.getElementById('board-prev');
  const nextButton = document.getElementById('board-next');

  let cursor = null;
  let prevCursor = null;
  let nextCursor = null;
  let timer = null;
  let board = null;
  let requestController = null;
  let requestSerial = 0;
  function cancelList() { requestSerial++; if (requestController) { requestController.abort(); } }
  window.addEventListener('pagehide', () => { cancelList(); clearTimeout(timer); });

  function listPath() {
    const params = new URLSearchParams();
    const q = searchEl ? searchEl.value.trim() : '';
    const tag = tagEl ? tagEl.value.trim() : '';
    if (q !== '') { params.set('q', q); }
    if (tag !== '') { params.set('tag', tag); }
    if (sortEl && sortEl.value !== 'new') { params.set('sort', sortEl.value); }
    if (cursor) { params.set('cursor', cursor); }
    const tail = params.toString();
    return '/api/boards/' + boardId + '/pages' + (tail === '' ? '' : '?' + tail);
  }

  function drawHead(b) {
    board = b;
    setText(titleEl, b.name);
    document.title = b.name + ' · 모아';
    setText(subEl, b.description);
    const level = BOARD_LEVEL_LABELS[b.access_level];
    if (levelEl && level) {
      levelEl.className = 'badge ' + level.badge;
      levelEl.setAttribute('title', b.access_level);
      setText(levelEl, level.text + (b.team_name ? ' · ' + b.team_name : ''));
      show(levelEl, true);
    }
    const kind = BOARD_KIND_LABELS[b.board_kind];
    if (kindEl && kind) {
      kindEl.setAttribute('title', b.board_kind);
      setText(kindEl, kind.text);
      show(kindEl, true);
    }
    show(inactiveEl, b.is_active !== true);
    show(newButton, b.can_write === true);
  }

  function row(item) {
    const tr = document.createElement('tr');
    const titleCell = document.createElement('td');
    const a = document.createElement('a');
    a.setAttribute('href', COMMUNITY_BASE + '/p/' + item.id);
    a.textContent = (item.icon ? item.icon + ' ' : '') + item.title;
    titleCell.appendChild(a);
    if (item.resolved === true && board && board.board_kind === 'qa') {
      const done = document.createElement('span');
      done.className = 'badge badge--ok';
      done.textContent = '해결됨';
      titleCell.appendChild(document.createTextNode(' '));
      titleCell.appendChild(done);
    }
    if (item.kind === 'published') {
      const pub = document.createElement('span');
      pub.className = 'badge';
      pub.setAttribute('title', '개인 공간의 페이지를 게시한 것입니다');
      pub.textContent = '게시됨';
      titleCell.appendChild(document.createTextNode(' '));
      titleCell.appendChild(pub);
    }
    tr.appendChild(titleCell);

    const who = document.createElement('td');
    personInto(who, item.writer, personLabel);   // 카페 C2 — 익명게시판은 「글쓴이」(+ 내 것이면 「(나)」 --muted)
    tr.appendChild(who);

    const when = document.createElement('td');
    setWhenParts(when, item.posted_at);   // 디버깅 묶음 U M2
    when.setAttribute('title', String(item.posted_at));
    tr.appendChild(when);

    const tags = document.createElement('td');
    const list = Array.isArray(item.tags) ? item.tags : [];
    if (list.length === 0) { tags.textContent = '—'; }
    for (const t of list) {
      const chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'btn btn--small btn--quiet';
      chip.textContent = '#' + t;
      chip.setAttribute('aria-label', '태그 ' + t + ' 로 거르기');
      chip.addEventListener('click', () => { if (tagEl) { tagEl.value = t; } cursor = null; load(); });
      if (tags.firstChild) { tags.appendChild(document.createTextNode(' ')); }
      tags.appendChild(chip);
    }
    tr.appendChild(tags);

    const views = document.createElement('td');
    views.textContent = String(item.views);
    tr.appendChild(views);
    return tr;
  }

  function drawPopularTags() {
    if (!tagsBox) { return; }
    fillTagOptions(tagOptions, boardId, '').then((tags) => {
      clear(tagsBox);
      for (const t of tags) {
        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn btn--small btn--quiet';
        chip.textContent = '#' + t.tag + ' ' + String(t.count);
        chip.setAttribute('aria-label', '태그 ' + t.tag + ' 로 거르기');
        chip.addEventListener('click', () => { if (tagEl) { tagEl.value = t.tag; } cursor = null; load(); });
        tagsBox.appendChild(chip);
      }
      show(tagsBox, tags.length > 0);
    });
  }

  function load(retrying = false) {
    cancelList();
    const mine = requestSerial;
    if (prevButton) { prevButton.disabled = true; }
    if (nextButton) { nextButton.disabled = true; }
    const q = searchEl ? searchEl.value.normalize('NFC').trim() : '';
    if (q && Array.from(q.replace(/[\s\p{Z}]/gu, '')).length < 2) {
      clear(bodyEl); show(emptyEl, false); setText(msg, '공백을 제외하고 두 글자 이상 입력해 주세요.');
      return Promise.resolve(false);
    }
    requestController = new AbortController();
    return api('GET', listPath(), undefined, { signal: requestController.signal }).then((result) => {
      if (mine !== requestSerial) { return false; }
      if (result.status === 429 && !retrying) {
        setText(msg, '앞선 검색을 마치는 중입니다…');
        timer = setTimeout(() => { if (mine === requestSerial) { load(true); } }, Math.min(3000, Math.max(1000, Number(result.retryAfter || 1) * 1000)));
        return false;
      }
      if (result.data && result.data.notice) {
        if (result.data.board) { drawHead(result.data.board); }
        clear(bodyEl); show(emptyEl, false); setText(msg, result.data.notice); return false;
      }
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) {
        setText(msg, errorMessage(result.data));
        show(emptyEl, false);
        return false;
      }
      drawHead(result.data.board);
      clear(bodyEl);
      const pages = Array.isArray(result.data.pages) ? result.data.pages : [];
      for (const item of pages) { bodyEl.appendChild(row(item)); }
      prevCursor = result.data.prev_cursor || null;
      nextCursor = result.data.next_cursor || null;
      setText(pageInfo, pages.length === 0 ? '' : (nextCursor ? '다음 글을 더 볼 수 있습니다.' : '마지막 글까지 보았습니다.'));
      if (prevButton) { prevButton.disabled = !prevCursor; }
      if (nextButton) { nextButton.disabled = !nextCursor; }
      const filtered = (searchEl && searchEl.value.trim() !== '') || (tagEl && tagEl.value.trim() !== '');
      setText(emptyEl, filtered ? '조건에 맞는 글이 없습니다.' : '아직 글이 없습니다.');
      show(emptyEl, pages.length === 0);
      setText(msg, '');
      return true;
    }).catch((error) => { if (mine === requestSerial && error.name !== 'AbortError') { setText(msg, GENERIC_ERROR); } return false; });
  }

  function later() {
    cancelList();
    if (timer !== null) { clearTimeout(timer); }
    timer = setTimeout(() => { timer = null; cursor = null; load(); }, 250);
  }
  if (searchEl) { searchEl.addEventListener('input', later); }
  if (tagEl) {
    tagEl.addEventListener('input', () => {
      fillTagOptions(tagOptions, boardId, tagEl.value.trim());
      later();
    });
  }
  if (sortEl) { sortEl.addEventListener('change', () => { cursor = null; load(); }); }
  if (prevButton) { prevButton.addEventListener('click', () => { if (prevCursor) { cursor = prevCursor; load(); } }); }
  if (nextButton) { nextButton.addEventListener('click', () => { if (nextCursor) { cursor = nextCursor; load(); } }); }

  /* ── 「새 글」 창 — 제목·태그만 받고, 만든 뒤 그 글을 편집 모드로 연다 ── */
  const newDialog = document.getElementById('board-new-dialog');
  const newInput = document.getElementById('board-new-input');
  const newTags = document.getElementById('board-new-tags');
  const newMsg = document.getElementById('board-new-msg');
  const newSave = document.getElementById('board-new-save');
  if (newButton && newDialog && typeof newDialog.showModal === 'function') {
    newButton.addEventListener('click', () => {
      setText(newMsg, '');
      if (newInput) { newInput.value = ''; }
      if (newTags) { newTags.value = ''; }
      newDialog.showModal();
      if (newInput) { newInput.focus(); }
    });
    for (const close of newDialog.querySelectorAll('[data-board-new-close]')) {
      close.addEventListener('click', () => newDialog.close());
    }
    const create = () => {
      const title = newInput ? newInput.value.trim() : '';
      if (title === '') { setText(newMsg, '제목을 적어 주세요.'); return; }
      newSave.disabled = true;
      api('POST', '/api/boards/' + boardId + '/pages', { title: title, tags: splitTags(newTags ? newTags.value : '') })
        .then((result) => {
          newSave.disabled = false;
          if (result.status === 401) { toLogin(); return; }
          if (result.status === 201 && result.data.page && typeof result.data.page.id === 'string') {
            window.location.assign(COMMUNITY_BASE + '/p/' + result.data.page.id + '?edit=1');
            return;
          }
          const reason = result.data && typeof result.data.reason === 'string' ? result.data.reason : '';
          setText(newMsg, BOARD_REASONS[reason] || errorMessage(result.data));
        })
        .catch(() => { newSave.disabled = false; setText(newMsg, GENERIC_ERROR); });
    };
    if (newSave) { newSave.addEventListener('click', create); }
    if (newInput) {
      newInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); create(); }
      });
    }
  }

  bindBoardManage(manageButton, () => { loadBoards(); load(); });
  // 태그 단추 줄은 목록을 **볼 수 있을 때만** 부른다(못 보는 게시판에 창구를 한 번 더 두드리지 않는다).
  load().then((ok) => { if (ok) { drawPopularTags(); } });
}

/**
 * 「게시판 관리」 창 (관리자) — 만들기 · 이름 · 설명 · 보관. 등급·팀·종류는 만들 때만 고른다.
 * 단추는 창구가 준 `can_manage` 가 참일 때만 보인다. 창구는 `role:Admin` + 재인증이 다시 막고,
 * 재인증이 필요하면 셸의 **같은 재인증 창**(`window.qarediHandleReauth`)을 띄운 뒤 다시 보낸다.
 */
function bindBoardManage(button, onChanged) {
  const dialog = document.getElementById('board-manage-dialog');
  if (!button || !dialog || typeof dialog.showModal !== 'function') { return; }
  const msg = document.getElementById('board-manage-msg');
  const list = document.getElementById('board-manage-list');
  const mode = document.getElementById('board-manage-mode');
  const nameEl = document.getElementById('board-manage-name');
  const descEl = document.getElementById('board-manage-desc');
  const levelEl = document.getElementById('board-manage-level');
  const teamEl = document.getElementById('board-manage-team');
  const kindEl = document.getElementById('board-manage-kind');
  const activeEl = document.getElementById('board-manage-active');
  const levelField = document.getElementById('board-manage-level-field');
  const teamField = document.getElementById('board-manage-team-field');
  const kindField = document.getElementById('board-manage-kind-field');
  const activeField = document.getElementById('board-manage-active-field');
  const save = document.getElementById('board-manage-save');
  const reset = document.getElementById('board-manage-reset');
  let editing = null;   // 고치는 중인 게시판(창구가 준 줄) — null 이면 만들기
  let teamsLoaded = false;

  function showButton(data) {
    show(button, !!data && data.can_manage === true);
  }
  if (boardsCache) { showButton(boardsCache); }
  allBoards().then(showButton);

  function drawMode() {
    const isNew = editing === null;
    setText(mode, isNew ? '새 게시판 만들기' : '「' + editing.name + '」 고치기');
    setText(save, isNew ? '만들기' : '바꾸기');
    show(levelField, isNew);
    show(kindField, isNew);
    show(teamField, isNew && levelEl && levelEl.value === 'team');
    show(activeField, !isNew);
    show(reset, !isNew);
    if (nameEl) { nameEl.value = isNew ? '' : editing.name; }
    if (descEl) { descEl.value = isNew ? '' : editing.description; }
    if (activeEl) { activeEl.checked = isNew ? true : editing.is_active === true; }
  }

  function drawList() {
    clear(list);
    const boards = boardsCache && Array.isArray(boardsCache.boards) ? boardsCache.boards : [];
    for (const b of boards) {
      const li = document.createElement('li');
      li.className = 'list__item';
      const head = document.createElement('div');
      head.className = 'list__head';
      const pick = document.createElement('button');
      pick.type = 'button';
      pick.className = 'btn btn--small btn--quiet';
      pick.textContent = b.name;
      pick.setAttribute('aria-label', '게시판 ' + b.name + ' 고치기');
      pick.addEventListener('click', () => { editing = b; setText(msg, ''); drawMode(); });
      head.appendChild(pick);
      const badge = boardLevelBadge(b.access_level);
      if (badge) { head.appendChild(badge); }
      const kind = BOARD_KIND_LABELS[b.board_kind];
      if (kind) {
        const k = document.createElement('span');
        k.className = 'badge';
        k.textContent = kind.text;
        head.appendChild(k);
      }
      if (b.is_active !== true) {
        const off = document.createElement('span');
        off.className = 'badge badge--off';
        off.textContent = '보관';
        head.appendChild(off);
      }
      li.appendChild(head);
      list.appendChild(li);
    }
  }

  function loadTeams() {
    if (teamsLoaded || !teamEl) { return; }
    teamsLoaded = true;
    fetch(APP_BASE + '/api/teams', { credentials: 'same-origin', cache: 'no-store' })
      .then((response) => (response.ok ? readJson(response) : { teams: [] }))
      .then((data) => {
        clear(teamEl);
        for (const t of Array.isArray(data.teams) ? data.teams : []) {
          const option = document.createElement('option');
          option.value = String(t.id);
          option.textContent = t.name;
          teamEl.appendChild(option);
        }
      })
      .catch(() => {});
  }

  if (levelEl) {
    levelEl.addEventListener('change', () => {
      show(teamField, editing === null && levelEl.value === 'team');
      if (levelEl.value === 'team') { loadTeams(); }
    });
  }

  button.addEventListener('click', () => {
    editing = null;
    setText(msg, '');
    drawList();
    drawMode();
    dialog.showModal();
  });
  for (const close of dialog.querySelectorAll('[data-board-manage-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
  if (reset) { reset.addEventListener('click', () => { editing = null; setText(msg, ''); drawMode(); }); }

  function submit() {
    let method = 'POST';
    let path = '/api/boards';
    let payload;
    if (editing === null) {
      payload = {
        name: nameEl ? nameEl.value : '',
        description: descEl ? descEl.value : '',
        access_level: levelEl ? levelEl.value : '',
        board_kind: kindEl ? kindEl.value : ''
      };
      if (payload.access_level === 'team') { payload.team_id = teamEl ? Number(teamEl.value) : 0; }
    } else {
      method = 'PATCH';
      path = '/api/boards/' + String(editing.id);
      payload = {
        name: nameEl ? nameEl.value : '',
        description: descEl ? descEl.value : '',
        is_active: activeEl ? activeEl.checked : true
      };
    }
    save.disabled = true;
    api(method, path, payload).then((result) => {
      save.disabled = false;
      if (result.status === 401) { toLogin(); return; }
      if (result.status === 200 || result.status === 201) {
        setText(msg, '');
        editing = null;
        allBoards().then(() => { drawList(); drawMode(); });
        if (typeof onChanged === 'function') { onChanged(); }
        return;
      }
      /* 재인증 — 셸의 같은 창을 띄우고, 통과하면 이 창을 다시 열어 요청을 그대로 다시 보낸다(막는 것은
         서버다). 모달 안에 모달을 열지 않도록(DESIGN 2.7) 이 창을 먼저 닫는다 — 적은 값은 칸에 남는다. */
      if (result.status === 403 && result.data && result.data.next === 'reauth'
          && typeof window.qarediHandleReauth === 'function') {
        dialog.close();
        window.qarediHandleReauth({ status: 403 }, result.data, () => { dialog.showModal(); submit(); });
        return;
      }
      setText(msg, result.status === 409 ? '이미 있는 이름입니다. 다른 이름을 적어 주세요.' : errorMessage(result.data));
    }).catch(() => { save.disabled = false; setText(msg, GENERIC_ERROR); });
  }
  if (save) { save.addEventListener('click', submit); }
}

/* ─── 알림 (5-D, PRD F-22) — 사이드바 종 · 30초 폴링 · 알림 창 ─────────
 * ★ 알림 목록의 제목은 창구가 **지금도 볼 수 있는 페이지만** 준다(아니면 `page: null` → 「볼 수 없는 페이지」).
 *   이 파일은 누가 무엇을 볼 수 있나를 모른다.
 */
const NOTIFY_TEXT = {
  mention: '님이 댓글에서 나를 불렀습니다',
  reply: '님이 내 댓글에 답글을 달았습니다',
  comment_on_my_page: '님이 내 페이지에 댓글을 달았습니다',
  handover: '님이 페이지를 나에게 넘겼습니다',
  recurring_created: '반복 페이지가 만들어졌습니다',
  review_requested: '검토할 문서가 있습니다',
  due_tomorrow: '내일 기한인 문서가 있습니다',
  automation_denied: '자동 작업의 권한을 확인해 주세요',
  automation_disabled: '자동 작업이 꺼졌습니다',
  suggestion_new: '님이 수정 제안을 보냈습니다',
  suggestion_accepted: '님이 수정 제안을 받아들였습니다',
  suggestion_rejected: '님이 수정 제안을 거절했습니다',
  suggestion_stale: '수정 제안의 원문이 바뀌었습니다',
  // t-box G-0 (F-73) — 권한 요청(페이지 없이 · 창구가 `access` 칸을 준다)
  access_request: '님이 권한을 요청했습니다',
  access_decided: '님이 권한 요청을 결정했습니다',
  // Q:F-12 (ARCH 23.9) — 담당으로 정해졌다(받는 사람 기준 다시 판정한 알림 · 「○○ 님이 작업을 맡겼어요」)
  assigned: '님이 작업을 맡겼어요'
};
/** t-box G-0 — 결정 알림은 상태로 글자를 고른다(서버가 준 status 그대로) */
const NOTIFY_DECIDED = { approved: '님이 권한 요청을 허락했습니다', rejected: '님이 권한 요청을 거절했습니다' };

/** t-box G-0 — 권한 요청 알림이 가리키는 곳: 서버가 준 t-box 주소(`/tbox/…`)만 — 그 밖의 값은 링크로 만들지 않는다 */
function notifyAccessHref(access) {
  return access && typeof access.href === 'string' && access.href.indexOf('/tbox/') === 0 ? access.href : null;
}

function initNotifications() {
  const bell = document.getElementById('nav-community-bell');
  const count = document.getElementById('nav-community-bell-count');
  const dialog = document.getElementById('notify-dialog');
  if (!bell || !dialog) { return; }
  // 좁은 화면에서는 사이드바가 숨는다 — 창을 `body` 끝으로 옮겨 둔다(검색 창과 같은 까닭, 5-B).
  document.body.appendChild(dialog);
  const list = document.getElementById('notify-list');
  const empty = document.getElementById('notify-empty');
  const msg = document.getElementById('notify-msg');
  const allRead = document.getElementById('notify-all-read');
  // 사용감 라운드 1 UX-3 — 폰 윗줄(960 미만에서만 보인다)의 종과 ☰ 단추. 숫자는 **같은 폴링 한 벌**이 세 자리에 함께 그린다(새 요청 0).
  const barBell = document.getElementById('appbar-bell');
  const barCount = document.getElementById('appbar-bell-count');
  const navToggle = document.getElementById('nav-toggle');

  function paintCount(n) {
    const text = n > 99 ? '99+' : String(n);
    const label = n > 0 ? '알림 — 안 읽은 것 ' + String(n) + '개' : '알림';
    setText(count, text);
    show(count, n > 0);
    bell.setAttribute('aria-label', label);
    setText(barCount, text);
    show(barCount, n > 0);
    if (barBell) { barBell.setAttribute('aria-label', label); }
    // ☰ — 서랍 안의 「알림」 숫자를 화면 읽기로도 알린다(보이는 숫자는 옆의 종이 맡는다 — 두 벌로 그리지 않는다)
    if (navToggle) { navToggle.setAttribute('aria-label', n > 0 ? '메뉴 열기 · 안 읽은 알림 ' + String(n) : '메뉴 열기'); }
  }
  // U-3 — 30초 폴링은 사람이 누른 것이 아니다: 표식을 달아 세션을 늘리지 않는다(창을 열어 목록을 보는 open() 은 사람 요청).
  function poll() {
    api('GET', '/api/notifications?unread=1', undefined, { background: true }).then((result) => {
      if (result.status === 200) { paintCount(Number(result.data.unread) || 0); }
    }).catch(() => {});
  }
  function item(n) {
    const li = document.createElement('li');
    li.className = 'list__item';
    const head = document.createElement('div');
    head.className = 'list__head';
    const automationStatus = n.automation_status === true;
    const access = n.access && typeof n.access === 'object' ? n.access : null;
    const accessHref = notifyAccessHref(access);
    const what = document.createElement(n.page || automationStatus || accessHref ? 'a' : 'span');
    const who = n.actor ? personLabel(n.actor.display_name, n.actor.username) : '누군가';
    const decided = n.kind === 'access_decided' && access ? NOTIFY_DECIDED[access.status] : null;
    what.textContent = automationStatus ? NOTIFY_TEXT[n.kind] : n.kind === 'verify_expired' ? '검증이 만료됐습니다' : who + ' ' + (decided || NOTIFY_TEXT[n.kind] || '님의 알림');
    if (n.page || automationStatus || accessHref) {
      what.setAttribute('href', automationStatus ? COMMUNITY_BASE + '/automations' : n.page ? COMMUNITY_BASE + '/p/' + n.page.id + (n.comment_id ? '#comment-' + n.comment_id : '') : accessHref);
      what.addEventListener('click', () => {
        api('POST', '/api/notifications/read', { ids: [n.id] }).catch(() => {});
      });
    }
    head.appendChild(what);
    if (!n.read) {
      const b = document.createElement('span');
      b.className = 'badge badge--internal';
      b.textContent = '새 알림';
      head.appendChild(b);
    }
    li.appendChild(head);
    const body = document.createElement('div');
    body.className = 'list__body';
    const page = document.createElement('span');
    page.textContent = automationStatus ? '자동 작업에서 실행 기록을 확인하세요.' : n.page ? '「' + n.page.title + '」'
      : (access ? (access.item ? '「' + access.item.name + '」' : (access.status === null ? '지워진 항목' : '볼 수 없는 항목'))
        : '볼 수 없는 페이지');
    const when = document.createElement('span');
    when.className = 'muted';
    when.textContent = shortWhen(n.at);
    body.appendChild(page);
    body.appendChild(when);
    li.appendChild(body);
    return li;
  }
  function open() {
    setText(msg, '');
    api('GET', '/api/notifications').then((result) => {
      if (result.status === 401) { toLogin(); return; }
      if (result.status !== 200) { setText(msg, errorMessage(result.data)); return; }
      clear(list);
      const items = Array.isArray(result.data.notifications) ? result.data.notifications : [];
      for (const n of items) { list.appendChild(item(n)); }
      show(empty, items.length === 0);
      paintCount(Number(result.data.unread) || 0);
    }).catch(() => setText(msg, GENERIC_ERROR));
    if (typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
  }
  bell.addEventListener('click', open);
  if (barBell) { barBell.addEventListener('click', open); }   // UX-3 — 윗줄 종도 같은 창
  for (const close of dialog.querySelectorAll('[data-notify-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
  if (allRead) {
    allRead.addEventListener('click', () => {
      allRead.disabled = true;
      api('POST', '/api/notifications/read', { all: true }).then(() => {
        allRead.disabled = false;
        open();
      }).catch(() => { allRead.disabled = false; setText(msg, GENERIC_ERROR); });
    });
  }
  // P-4a — 30초 폴링은 탭이 보일 때만(숨으면 멈추고, 다시 보이면 곧바로 한 번).
  whileVisible(poll, 30000).start();
}

const ATT_UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const UPLOAD_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif'];
const UPLOAD_TOO_LARGE = '파일이 100MB 를 넘습니다';
const ATT_POLL_MS = 5000;
const ATT_POLL_LIMIT_MS = 180000;
const ATT_PREVIEW_MAX_BYTES = 20 * 1024 * 1024;
const ATT_PREVIEW_RE = /^data:image\/(png|jpeg|gif);base64,[A-Za-z0-9+/=]+$/;
const attachmentPreviews = Object.create(null);
/** 지금 그려진 편집기 그림 부품들 — { ref() → file_ref, draw() } (페이지 · 칸 편집기 공용 · 부품이 걷히면 빠진다) */
const liveImageViews = new Set();

/** 올린 그림 파일 → 브라우저 안 사본(`data:image/…`) 또는 null(그림 셋이 아니거나 너무 크거나 읽지 못함) */
function readPreview(file) {
  return new Promise((resolve) => {
    if (!file || UPLOAD_IMAGE_MIMES.indexOf(file.type) === -1 || !(file.size > 0) || file.size > ATT_PREVIEW_MAX_BYTES || typeof FileReader !== 'function') {
      resolve(null);
      return;
    }
    const reader = new FileReader();
    reader.addEventListener('load', () => {
      const url = typeof reader.result === 'string' ? reader.result : '';
      resolve(ATT_PREVIEW_RE.test(url) ? url : null);
    });
    reader.addEventListener('error', () => resolve(null));
    reader.addEventListener('abort', () => resolve(null));
    try { reader.readAsDataURL(file); } catch (e) { resolve(null); }
  });
}

/** 그 첨부를 쓰는 편집기 그림 부품만 다시 그린다(노드 값 — 폭 · 설명 — 은 그대로) */
function redrawAttachmentViews(ids) {
  for (const v of Array.from(liveImageViews)) {
    const ref = v.ref();
    if (typeof ref === 'string' && ref.slice(0, 4) === 'att:' && ids.has(ref.slice(4))) { v.draw(); }
  }
}

/**
 * 검사 중 첨부 다시 묻기 한 벌(페이지 · 작업 칸 공용). cfg: { pageId() → 문서 id, titles() → 지금 지도(고쳐 쓴다), onChange(Set<첨부 id>) }
 * → { kick(now) — pending 이 있으면 3분 창을 새로 열고(now 면 곧바로 한 번), stop() }
 */
function watchAttachments(cfg) {
  let timer = null;
  let until = 0;
  let busy = false;
  let stopped = false;
  const pending = () => {
    const map = cfg.titles();
    return !!map && typeof map === 'object' && Object.keys(map).some((k) => map[k] && map[k].scan_status === 'pending');
  };
  function arm(ms) {
    window.clearTimeout(timer);
    timer = null;
    if (!stopped && !document.hidden && Date.now() < until && pending()) { timer = window.setTimeout(ask, ms); }
  }
  function ask() {
    timer = null;
    if (stopped || busy || document.hidden) { return; }
    const id = String(cfg.pageId() || '');
    if (!ATT_UUID_RE.test(id)) { return; }
    busy = true;
    api('GET', '/api/pages/' + id + '/attachments', null, { background: true }).then((r) => {
      busy = false;
      if (stopped || String(cfg.pageId() || '') !== id) { return; }
      const got = r.status === 200 && r.data && r.data.attachment_titles && typeof r.data.attachment_titles === 'object' && !Array.isArray(r.data.attachment_titles)
        ? r.data.attachment_titles : null;
      if (got === null) {
        if (r.status === 401 || r.status === 403 || r.status === 404) { until = 0; }   // 볼 수 없게 됐다 · 로그인이 끊겼다 — 묻기를 멈춘다
        arm(ATT_POLL_MS);
        return;
      }
      const map = cfg.titles();
      const changed = new Set();
      for (const k of Object.keys(got)) {
        const now = got[k];
        if (!ATT_UUID_RE.test(k) || !now || typeof now !== 'object') { continue; }
        const before = map[k];
        if (!before || before.scan_status !== now.scan_status) {
          map[k] = { name: typeof now.name === 'string' ? now.name : '', size: Number(now.size) || 0, image: now.image === true, scan_status: String(now.scan_status || '') };
          changed.add(k);
        }
        if (now.scan_status !== 'pending') { delete attachmentPreviews[k]; }
      }
      if (changed.size) { cfg.onChange(changed); }
      arm(ATT_POLL_MS);
    }).catch(() => { busy = false; arm(ATT_POLL_MS); });
  }
  const onVisible = () => { if (!document.hidden && !stopped && pending()) { until = Date.now() + ATT_POLL_LIMIT_MS; arm(0); } };
  document.addEventListener('visibilitychange', onVisible);
  return {
    kick(now) {
      if (stopped || !pending()) { return; }
      until = Date.now() + ATT_POLL_LIMIT_MS;
      if (now === true && !busy) { window.clearTimeout(timer); timer = null; ask(); return; }
      arm(ATT_POLL_MS);
    },
    stop() {
      stopped = true;
      window.clearTimeout(timer);
      timer = null;
      document.removeEventListener('visibilitychange', onVisible);
    }
  };
}

/* ── 남긴 댓글의 멘션 표시 (카페 C4 · DESIGN 2.27 「남긴 댓글의 멘션」 · 사용자 지적 2026-10-14 「태그가 됐는지 · 실패인지 · 오타인지 구분」) ──
 * 댓글 본문(평문) 글자 조각에서 `@아이디` 를 입력 칸과 같은 표시로: 확인됨 `span.mention-mark--ok`(+ title · aria-label 「이름(아이디) — 알림이 갔어요」) ·
 * 못 찾음 `span.mention-mark--miss`(「찾을 수 없는 사람 — 알림이 가지 않았어요」). 토큰 모양은 입력 부품과 같은 `mentionTokens`(= 서버
 * `community_mentions_in` — 끝 마침표는 떼고 `@아이디.te` 는 `아이디.te` 한 토큰). 판단은 서버 지도(`comments_list` 의 `mention_status`)뿐 —
 * 지도에 없는 토큰(3자 미만 · 판정 상한 밖 · 손님 · 익명게시판)은 글자 그대로. 전체 화면 댓글(editor.js) · 작업 칸 댓글(work.js)이 이 함수 하나를 쓴다.
 * 요소는 createElement, 글자는 createTextNode 로만(HTML 문자열 0). */
const MENTION_OK_NOTE = '알림이 갔어요';
const MENTION_MISS_NOTE = '찾을 수 없는 사람 — 알림이 가지 않았어요';
function mentionInto(host, text, status) {
  const s = String(text === null || text === undefined ? '' : text);
  const map = status && typeof status === 'object' && !Array.isArray(status) ? status : {};
  let at = 0;
  for (const tok of mentionTokens(s)) {
    const got = Object.prototype.hasOwnProperty.call(map, tok.name) ? map[tok.name] : null;
    if (!got || typeof got !== 'object') { continue; }
    if (tok.at > at) { host.appendChild(document.createTextNode(s.slice(at, tok.at))); }
    const ok = got.ok === true;
    const mark = document.createElement('span');
    mark.className = ok ? 'mention-mark--ok' : 'mention-mark--miss';
    mark.textContent = s.slice(tok.at, tok.end);
    const note = ok
      ? personLabel(typeof got.display_name === 'string' && got.display_name !== '' ? got.display_name : null, tok.name) + ' — ' + MENTION_OK_NOTE
      : MENTION_MISS_NOTE;
    mark.setAttribute('title', note);
    mark.setAttribute('aria-label', mark.textContent + ' — ' + note);
    host.appendChild(mark);
    at = tok.end;
  }
  if (at < s.length) { host.appendChild(document.createTextNode(s.slice(at))); }
}

/** 첨부 하나를 올린다(진행률이 필요해 XHR — 같은 CSRF 토큰 · 같은 출처). → { promise: 201 답, abort() } */
function sendAttachment(pageId, file, onProgress) {
  let xhr = null;
  const promise = new Promise((resolve, reject) => {
    if (tabHalted) { reject(new Error(HALT_TEXT)); return; }
    if (csrfToken === null || !ATT_UUID_RE.test(String(pageId))) { reject(new Error(GENERIC_ERROR)); return; }
    xhr = new XMLHttpRequest();
    xhr.open('POST', COMMUNITY_BASE + '/api/pages/' + pageId + '/attachments');
    xhr.setRequestHeader('X-CSRF-Token', csrfToken);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.addEventListener('progress', (e) => { if (e.lengthComputable && onProgress) { onProgress(Math.min(100, Math.floor((e.loaded * 100) / Math.max(1, e.total)))); } });
    xhr.addEventListener('load', () => {
      let data = {};
      try { data = JSON.parse(xhr.responseText); } catch (e) { data = {}; }
      if (xhr.status === 201 && data && typeof data.id === 'string' && ATT_UUID_RE.test(data.id)) { resolve(data); return; }
      const err = new Error(xhr.status === 413 ? UPLOAD_TOO_LARGE : errorMessage(data));
      err.status = xhr.status;
      reject(err);
    });
    xhr.addEventListener('error', () => reject(new Error(GENERIC_ERROR)));
    xhr.addEventListener('abort', () => { const err = new Error(''); err.aborted = true; reject(err); });
    const form = new FormData();
    form.append('file', file, typeof file.name === 'string' && file.name !== '' ? file.name : 'file');
    xhr.send(form);
  });
  return { promise: promise, abort: () => { if (xhr) { xhr.abort(); } } };
}


/* ── 페이지 화면 전체 ──────────────────────────────────────── */

let pageTemplateSave = null;   // Q:F-16 1차 — initPage 의 「템플릿으로 저장」 창 한 벌을 업무 화면(작업 칸 더보기)도 쓴다
/* ─── 시작 ───────────────────────────────────────────────────── */

/**
 * P-5a — `GET /api/me` 는 한 화면에 한 번. 셸 스크립트(app.js — 이 모듈보다 먼저 실행된다)가 내놓은
 * `window.qarediMe` 가 첫 호출의 Promise 를 나눠 준다. 셸이 없는 날(파일이 빠진 배포 등)에만 전처럼 직접 부른다.
 *
 * @return Promise<{status:number, me:(Object|null)}>
 */
function loadMe() {
  if (typeof window.qarediMe === 'function') { return window.qarediMe(); }
  return fetch(APP_BASE + '/api/me', { credentials: 'same-origin', cache: 'no-store' })
    .then((response) => {
      if (!response.ok) { return { status: response.status, me: null }; }
      return readJson(response).then((me) => ({ status: response.status, me: me }));
    });
}

let booted = false;

/**
 * 디버깅 묶음 U H3 (2026-09-26) — 이 사람이 커뮤니티에 **쓸 수 있는 역할인가**(`/api/me` 의 `can_community_write` — 서버가
 * 라우트 정책 `community.write` 와 같은 함수로 정한 값). 페이지 화면은 창구가 준 페이지별 can_*(편집·넘기기·공개 범위·태그…)와
 * **둘 다** 참일 때만 단추를 보인다 — 손님이 자기 「시작하기」 페이지에서 누르면 권한 없음만 나오던 단추들이다.
 * 값이 없으면(옛 창구) 페이지별 값만 따른다. 숨김은 편의이고 막는 것은 서버다 (PRD S-01).
 */
let meCanWrite = true;

/** Q:F-12 D — 업무 화면 한 벌(work.js). boot 가 `/api/me` 를 받은 뒤 만든다 */
let work = null;

/**
 * Q:F-12 D — 작업 상세 칸 안에서 본문을 고치는 편집기(DESIGN 2.21 「설명 — 읽기 기본, 누르면 편집」).
 * 페이지 화면 편집기와 **같은 스키마 · 같은 키 동작 · 같은 저장 창구**(`PATCH /api/pages/{id}` — `doc_version` 으로 409)를 쓴다.
 * Q:F-12 U3 B(DESIGN 2.21 「칸 편집기」) — 전체 편집기와 **같은 함수**로 같은 도구를 단다: 꾸밈 도구줄(`createToolbar` — 「블록 추가」 · 색 ·
 * 배경 · 링크 · 멘션), 「블록 추가」 창(`blockDialogFor` — 같은 목록 `BLOCK_TYPES`), 「블록 고치기」 창(`atomDialogFor` — 그림 · 파일 · 표 · 토글 …),
 * Ctrl+Alt+숫자 바꾸기 · 덩어리 카드 Enter/두 번 누르기 · 할 일 상자 · 목차. U3 C(DESIGN 2.24) — 붙여넣기 · 끌어놓기 · 「이 문서에 올리기」 탭
 * (`createUploads` — 전체 편집기와 같은 함수). 용지 고르개 · 블록 손잡이 · 임시저장 · 제안은 전체 화면 몫이다. 409 면 멈추고 「전체 화면에서 열어 확인」을 알린다.
 *
 * @param {HTMLElement} mount
 * @param {{pageId:string, doc:Object, version:number, titles:Object, onState:Function, onSaved:Function, onError:Function}} opts
 *        titles = 칸의 읽기 지도(child · link · file · attachment_titles · mention_labels) — 이 편집기가 고르거나 올린 것의 이름을 여기 더해 둔다
 *        (편집을 마친 뒤 읽기 렌더러가 곧바로 그리게 · 다음 GET 에서 서버 것으로 바뀐다)
 * @return {{flush:Function, destroy:Function, focus:Function}|null}
 */
const shared = {
  get pageIsAnon() { return pageIsAnon; }, set pageIsAnon(value) { pageIsAnon = value; },
  get pageTemplateSave() { return pageTemplateSave; }, set pageTemplateSave(value) { pageTemplateSave = value; },
  get documentTrashGuard() { return documentTrashGuard; }, set documentTrashGuard(value) { documentTrashGuard = value; },
  get documentTrashAfterClose() { return documentTrashAfterClose; }, set documentTrashAfterClose(value) { documentTrashAfterClose = value; },
  get documentTrashAfterRestore() { return documentTrashAfterRestore; }, set documentTrashAfterRestore(value) { documentTrashAfterRestore = value; },
  get leavingOnPurpose() { return leavingOnPurpose; }, set leavingOnPurpose(value) { leavingOnPurpose = value; },
  get favoriteIds() { return favoriteIds; }, set favoriteIds(value) { favoriteIds = value; },
};

const importEditorRuntime = createModuleImporter('./editor-runtime.js?v=a9fddd18fefd', import.meta.url);
const loadEditorRuntime = createModuleLoader(attempt => importEditorRuntime(attempt)
  .then(({ createEditorRuntime }) => createEditorRuntime({ APP_BASE, ATT_UUID_RE, COMMUNITY_BASE, GENERIC_ERROR, UPLOAD_IMAGE_MIMES, api, attachmentPreviews, body, clear, currentPageId, errorMessage, liveImageViews, makeIcon, personLabel, readJson, readPreview, sendAttachment, setText, show, toLogin, watchAttachments, shared })));
function mountPaneEditor(mount, opts) {
  return createDeferredEditor(mount, {
    load: () => loadEditorRuntime().then(runtime => () => runtime.mountPaneEditor(mount, opts)),
    onState: opts.onState, onError: opts.onError
  });
}
function initPage() {
  return import('./page.js?v=a9fddd18fefd').then(page => page.initPage({ APP_BASE, BOARD_LEVEL_LABELS, BOARD_REASONS, COMMUNITY_BASE, GENERIC_ERROR, IS_CAFE, SHARE_LABELS, STATUS_LABELS, VISIBILITY_REASONS, allBoards, api, boardLevelBadge, body, cafe, clear, drafts, errorMessage, favoriteListeners, fillTagOptions, leaveHooks, loadFavoritesNav, loadRecentNav, localWhen, makeIcon, meCanWrite, meUsername, mentionInto, paintStatus, paintVisibility, personLabel, propValueText, redrawAttachmentViews, roleText, setText, setWhenParts, shareBadge, shortWhen, show, spaceHref, splitTags, titleForInput, toLogin, watchAttachments, whileVisible, work, shared, loadEditorRuntime }));
}

function boot() {
  if (booted) { return; }
  booted = true;

  const page = body && body.dataset ? body.dataset.page : '';
  // Q:F-12 D — 업무 홈 · 포트폴리오(`community-overview`) · 내 작업(`community-today`)도 이 한 벌로 시작한다(셸 키는 C 가 정한 그대로)
  if (page !== 'community-home' && page !== 'community-page' && page !== 'community-board' && page !== 'community-ai' && page !== 'community-threads'
      && page !== 'community-overview' && page !== 'community-today' && page !== 'community-reports' && page !== 'community-board-requests'
      && page !== 'community-cafe') { return; }   // 사이트 H3 — 카페 홈(/community/cafe)
  if (COMMUNITY_BASE === '' || APP_BASE === '') { return; }

  // CSRF 토큰은 t-box 창구가 준다 — 한 앱·한 세션이라 같은 토큰이 커뮤니티 창구에도 통한다.
  // P-5a — 셸(app.js)과 **같은 한 번**을 나눠 쓴다(`window.qarediMe`, 화면 수명 동안만 · no-store).
  loadMe()
    .then((result) => {
      if (result.status === 401) { toLogin(); return null; }
      if (result.me === null) { return null; }
      return Promise.resolve(result.me).then((me) => {
        if (typeof me.csrf_token !== 'string') { return null; }
        csrfToken = me.csrf_token;
        // B1-2 — 임시저장 창구(진짜 사용자 아이디 — 역할로 보기 중이어도 `username` 은 실제 계정). 열쇠는 아직 받지 않는다.
        // 7일이 지난 내 항목은 커뮤니티 화면을 열 때마다 정리한다(`saved_at` 은 평문이라 열쇠가 필요 없다).
        drafts = createDrafts(typeof me.username === 'string' ? me.username : '', loadDraftKey);
        // 라운드 B — 일괄 작업의 「이어서 하기」가 같은 계정인지 견줄 아이디
        meUsername = typeof me.username === 'string' ? me.username : null;
        // 디버깅 묶음 U H3 — 쓰기 자격(서버 판정 값). false 일 때만 끈다
        meCanWrite = me.can_community_write !== false;
        drafts.sweep();
        // Q:F-12 D — 업무 화면 한 벌. 쓰는 단추는 창구의 can_* 와 이 쓰기 자격(`meCanWrite`)이 둘 다 참일 때만이다(work.js)
        work = createWork({
          api: api, base: COMMUNITY_BASE, tboxBase: APP_BASE, setText: setText, show: show, clear: clear, errorMessage: errorMessage,
          genericError: GENERIC_ERROR, toLogin: toLogin, statusLabels: STATUS_LABELS, personLabel: personLabel, shortWhen: shortWhen,
          favorites: { star: navStar, ids: () => favoriteIds, onChange: (fn) => { favoriteListeners.push(fn); } },   // Q:F-12 U2 — 업무함 ★ = 사이드바 즐겨찾기의 같은 함수 · 같은 창구
          makeIcon: makeIcon, canWrite: () => meCanWrite, meName: () => meUsername || '', loadSpaces: loadSpaces, mountEditor: mountPaneEditor,
          // 카페 C4 — 검사 중 첨부 다시 묻기 · 편집기 그림 부품 다시 그리기 · 남긴 댓글의 멘션 표시(전체 화면과 같은 함수 한 벌)
          watchAttachments: watchAttachments, redrawAttachments: redrawAttachmentViews, mentionInto: mentionInto,
          templateSave: () => pageTemplateSave   // Q:F-16 1차 — 작업 칸 더보기 「템플릿으로 저장」(창이 없는 화면이면 open 이 없다)
        });
        // 카페 C2 — 카페 부품(신고 · 이름표 · 관리자 화면 둘) · 「우리 공간」 셸의 관리 묶음(can_admin — 표시용)
        cafe = createCafe({ api: api, personLabel: personLabel, base: COMMUNITY_BASE });
        cafe.bindShell(me);
        initDocumentTrash();
        work.bindShell();
        bindNewPage();
        bindIntegrations(api).catch(() => {});
        bindAiCenter(api).catch(() => {});
        // 개발 대기열 6 — 사이드바 「즐겨찾기」「최근」(세 화면 공통). 페이지 화면의 「최근」은 그 페이지를 연 뒤 initPage 가 받는다.
        bindFavoritesFold();
        loadFavoritesNav();
        if (page !== 'community-page') { loadRecentNav(); }
        if (page === 'community-reports') { cafe.initReports(); }
        else if (page === 'community-board-requests') { cafe.initBoardRequests(); }
        else if (page === 'community-cafe') { cafe.initHome(); }   // 사이트 H3 — 카페 홈(/community/cafe · DESIGN 2.29 「「우리 공간」 = 카페」)
        else if (page === 'community-ai' || page === 'community-threads') { loadSpaces(); }
        else if (page === 'community-overview') {
          // Q:F-12 D — 업무 홈 · 포트폴리오 화면(같은 셸 키 — 화면은 자기 뼈대의 id 로 고른다)
          loadSpaces();
          work.initHome();
          work.initPortfolio();
        } else if (page === 'community-today') {
          // Q:F-12 D — 내 작업(옛 「오늘」 키를 잇는다)
          loadSpaces();
          work.initMyTasks();
        }
        else if (page === 'community-home') {
          if (location.pathname === COMMUNITY_BASE + '/ai') { loadSpaces(); } else if (location.pathname === COMMUNITY_BASE + '/integrations') { loadSpaces(); } else if (location.pathname === COMMUNITY_BASE + '/suggestions') { bindMySuggestions(api, COMMUNITY_BASE); loadSpaces(); } else if (location.pathname === COMMUNITY_BASE + '/automations') { bindAutomations(api, COMMUNITY_BASE); loadSpaces(); } else if (location.pathname === COMMUNITY_BASE + '/procedures') { bindProcedures(api, COMMUNITY_BASE); loadSpaces(); } else { initHome(); }
        } else if (page === 'community-board') {
          // 5-C — 게시판 화면. 사이드바의 공간·게시판 묶음은 여기서 함께 채운다.
          loadSpaces();
          initBoard();
        } else {
          // 4-B — 페이지 화면에서도 셸 왼쪽의 「팀 공간」 묶음을 채운다(홈은 initHome 이 한다).
          loadSpaces();
          initPage().catch(() => setText(document.getElementById('msg'), GENERIC_ERROR));
        }
        // 5-C — 사이드바 「게시판」 묶음(모든 화면). 카페 C2 — 자리별(`?area=`) 목록이라 관리 창 · 게시 창의 전체 목록(allBoards)과 따로 받는다
        loadBoards();
        // 5-D — 사이드바 알림 종(세 화면 공통, 30초 폴링)
        initNotifications();
        return null;
      });
    })
    .catch(() => setText(document.getElementById('msg'), GENERIC_ERROR));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
