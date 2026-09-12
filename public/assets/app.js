'use strict';
/* public/assets/app.js — 12단계(12-1): 공통 셸 + 기능별 화면
 *   로그인 · 파일(`/`) · 휴지통(`/trash`) · 내 계정(`/settings`) ·
 *   계정 관리(`/users`) · 조직·권한(`/org`, 12-3) · 감사 로그(`/audit`)
 *
 * 어느 화면인지는 서버가 <body data-page="…"> 로 알려 주고, 아래 bootPage() 가 그 값으로만
 * 갈라진다. 화면이 늘어나도 기능 함수(initFiles·initTrash·initAccount·initUsers·initAudit·
 * initForcedGate·initLogin)는 그대로다 — 12-1 은 **자리를 옮겼을 뿐** 요청·검증 규칙을
 * 한 줄도 바꾸지 않았다 (PRD F-20).
 *
 * 아이콘은 서버 HTML 의 <template id="tpl-icon-…"> 을 cloneNode 로 복제해 붙인다.
 * 문자열을 HTML 로 바꾸는 API(inner/outer HTML 계열)를 쓰지 않고, 이모지도 쓰지 않는다.
 *
 * 규칙 (PRD S-03, S-09-3, ARCHITECTURE 13장 체크리스트)
 *   · DOM 에 값을 넣을 때는 textContent 만 쓴다. HTML 문자열을 그대로 넣는 API
 *     (inner/outer HTML 계열, document 에 직접 쓰는 계열)는 쓰지 않는다.
 *     금지 패턴이며 scripts/security_lint.sh 가 반려한다.
 *     표의 행도 createElement + textContent 로만 만든다 — 파일명·설명에 어떤 문자가 있어도
 *     문자로만 화면에 들어간다(저장형 XSS 차단).
 *   · 권한 판단을 브라우저에서 하지 않는다. 버튼을 숨기는 것은 편의일 뿐,
 *     허용 여부는 서버 Authz 가 매 요청 판단한다 (PRD S-01).
 *     ★ 5단계: 역할 문자열을 이 파일에서 비교하지 않는다. 화면이 보는 것은 서버가 준
 *       세 값뿐이다 — 목록 행의 can_delete(삭제 버튼)·can_edit(수정 버튼) 와
 *       /api/me 의 upload_levels(레벨 선택지).
 *       버튼이 보이지 않아도, 콘솔에서 직접 요청을 보내도 서버가 다시 검사한다 (PRD 10장).
 *     ★ 수정(PATCH)에 넣을 값도 화면이 판단하지 않는다. 사용자가 적은 등급·팀을 **그대로**
 *       보내고, 허용 여부와 거부 사유 문구는 전부 서버 응답을 그대로 보여 준다 (PRD S-01).
 *   · 상태를 바꾸는 요청에는 CSRF 토큰 헤더(X-CSRF-Token)를 붙인다 (PRD S-09-3).
 *     토큰은 GET /api/me 로 받는다. 세션 쿠키는 HttpOnly 이므로 JS 가 읽지 않는다.
 *     업로드(FormData)에도 같은 헤더를 붙이고, Content-Type 은 **지정하지 않는다**
 *     (브라우저가 multipart boundary 를 직접 붙여야 한다).
 *   · 화면에 띄우는 오류 문구는 **서버가 준 문구만** 쓴다. 브라우저가 사유를 추측해
 *     "그런 아이디는 없습니다" 같은 말을 만들지 않는다 (PRD 10장).
 *   · 세션이 끊기면(401) 어떤 요청이든 로그인 화면으로 보낸다.
 */

var LOGIN_PATH = '/login';
var HOME_PATH = '/';
var REAUTH_PATH = '/reauth';
var GENERIC_ERROR = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';

/**
 * 16-1 재인증(sudo 모드) — 돌아갈 화면의 **키 → 경로** 고정 맵 (PRD F-23).
 *
 * 서버(public/index.php 의 TBOX_REAUTH_PATHS)와 **같은 내용**의 표다. `/reauth` 화면의
 * <form data-to="…"> 에는 서버가 이 표의 키(users·org·audit·trash·wiki)만 넣어 주고,
 * 화면은 키가 이 맵에 있을 때만 그 **맵의 값**으로 이동한다. 주소 문자열을 요청 값이나
 * data 속성에서 만들지 않는다 — 오픈 리다이렉트가 생길 자리가 없다.
 */
var REAUTH_PATHS = {
  'users': '/users',
  'org': '/org',
  'audit': '/audit',
  'trash': '/trash',
  'wiki': '/wiki'
};

/** 서버가 내려 준 파일 ID 형태 (UUIDv4). 링크를 만들기 전에 반드시 확인한다. */
var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/**
 * 업로드 레벨의 **화면 문구**. 어떤 레벨을 고를 수 있는지는 서버(/api/me 의 upload_levels)가
 * 정하고, 여기 표는 그 값에 붙일 한국어 설명일 뿐이다. 표에 없는 값이 오면 만들지 않는다
 * (서버가 보낸 문자열이 그대로 <option value> 가 되지 않게 하는 방어).
 */
var LEVEL_LABELS = {
  'public': 'public — 전체 공개',
  'internal': 'internal — 사내 전체',
  'team': 'team — 팀 전용',
  'admin-only': 'admin-only — 관리자 전용'
};

/** 아직 아무것도 고르지 않았을 때의 자리표시 문구 (사용자 피드백 ②) */
var LEVEL_PLACEHOLDER = '접근 레벨을 선택하세요';

/**
 * <select> 를 서버가 준 목록으로 채운다. createElement + textContent 만 쓴다.
 * 만들어진 **실제 레벨** 개수를 돌려준다 (0 이면 업로드할 수 있는 레벨이 하나도 없다는 뜻).
 *
 * 맨 앞에는 value="" 인 **비활성 자리표시**를 넣는다 — 고르지 않은 채로 올리기를 누르는 일을
 * 막기 위해서다 (PRD 4장 "레벨을 반드시 고른다"). 기본 선택은 가장 좁은 team 이고,
 * team 을 고를 수 없거나 소속 팀이 없는 계정(Admin 등)은 자리표시가 선택된 채로 남아
 * 사용자가 직접 고르게 된다.
 *
 * ★ 여기서도 역할을 보지 않는다. 고를 수 있는 레벨은 서버가 준 upload_levels 뿐이고,
 *   기본값을 team 으로 미리 고르는 것은 **편의**일 뿐이다 — 허용 여부는 서버가 매 요청 판단한다.
 *
 * @param {Element|null}   select
 * @param {Array|null}     levels    서버(/api/me)가 준 upload_levels
 * @param {boolean}        hasTeam   이 사용자에게 소속 팀(team_id)이 있나
 */
function fillLevelOptions(select, levels, hasTeam) {
  var made = 0;
  if (!select) {
    return 0;
  }
  while (select.firstChild) {
    select.removeChild(select.firstChild);
  }
  if (!Array.isArray(levels)) {
    return 0;
  }

  var placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.disabled = true;
  setText(placeholder, LEVEL_PLACEHOLDER);
  select.appendChild(placeholder);

  var hasTeamLevel = false;
  levels.forEach(function (level) {
    if (typeof level !== 'string' || !Object.prototype.hasOwnProperty.call(LEVEL_LABELS, level)) {
      return;
    }
    var option = document.createElement('option');
    option.value = level;
    setText(option, LEVEL_LABELS[level]);
    select.appendChild(option);
    made += 1;
    if (level === 'team') {
      hasTeamLevel = true;
    }
  });

  if (made === 0) {
    // 올릴 수 있는 레벨이 없으면 자리표시만 남는다(폼 자체를 호출부가 감춘다).
    select.value = '';
    return 0;
  }
  // 기본값: 팀이 있고 team 을 고를 수 있으면 가장 안전한 team, 아니면 자리표시.
  if (hasTeam === true && hasTeamLevel) {
    select.value = 'team';
  } else {
    placeholder.selected = true;
    select.value = '';
  }
  return made;
}

/** 텍스트를 안전하게 넣는 유일한 통로. 화면 코드는 이 함수만 쓴다. */
function setText(el, value) {
  if (el) {
    el.textContent = value === null || value === undefined ? '' : String(value);
  }
}

/* ─── 아이콘 — <template> 복제만 쓴다 (CSP `script-src 'self'`, S-03) ───
 *
 * SVG 마크업은 **서버가 만든 HTML**(src/views/shell_bottom.php · login_page.php)의
 * <template> 안에 있고, 여기서는 그 조각을 cloneNode 로 복제해 붙이기만 한다.
 * 문자열 → HTML 변환이 한 곳도 없으므로 저장형 XSS 의 통로가 생기지 않는다.
 */
function makeIcon(name) {
  var host = document.createElement('span');
  host.className = 'icon';
  var tpl = document.getElementById('tpl-icon-' + String(name));
  if (tpl && tpl.content) {
    host.appendChild(tpl.content.cloneNode(true));
  }
  return host;
}

/** HTML 에 미리 놓인 [data-icon] 자리를 채운다 (내비·머리 단추 등) */
function paintIcons(root) {
  var scope = root || document;
  var slots = scope.querySelectorAll('[data-icon]');
  var i;
  for (i = 0; i < slots.length; i++) {
    if (slots[i].firstChild === null) {
      slots[i].appendChild(makeIcon(slots[i].getAttribute('data-icon')));
    }
  }
}

/**
 * 표의 작업 칸에 쓰는 아이콘 단추. 그림만 있으면 뜻이 전해지지 않으므로
 * **한글 이름**을 title 과 aria-label 에 함께 넣는다(화면 낭독기·마우스 올림 모두).
 */
function makeIconButton(iconName, label) {
  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn btn--icon';
  button.setAttribute('title', label);
  button.setAttribute('aria-label', label);
  button.appendChild(makeIcon(iconName));
  return button;
}

/**
 * 접근 레벨 배지의 **화면 문구**. 값 자체는 서버가 정하고(PRD 4장의 네 가지),
 * 여기 표는 그 값에 붙일 한국어 라벨일 뿐이다. 표에 없는 값이 오면 배지를 만들지 않고
 * 받은 문자열을 그대로 글자로만 보여 준다 — 서버 문자열이 class 이름이 되지 않게 하는 방어.
 */
var LEVEL_BADGE_LABELS = {
  'public': '공개',
  'internal': '사내',
  'team': '팀',
  'admin-only': '관리자'
};

/**
 * 미리보기 갈래의 **화면 문구** (12-2, PRD F-18). 갈래 자체는 서버가 DB `ext` 로 정하고
 * (`Preview::kindFor`), 여기 표는 그 값에 붙일 한국어 설명일 뿐이다 — 화면은 확장자를 보지 않는다.
 */
var PREVIEW_KIND_LABELS = {
  'image': '이미지',
  'pdf': 'PDF 문서',
  'text': '텍스트 원문',
  'table': '표 (csv)',
  'archive': '압축 파일 항목 목록',
  'office': '문서 텍스트 발췌',
  'none': '미리보기를 지원하지 않는 형식'
};

/**
 * <img>·<iframe>·「새 탭에서 열기」의 주소는 **서버가 준 값이 `/api/files/` 로 시작할 때만**
 * 쓴다 (ARCH 5.8 화면 규칙, SECURITY R-41). 다른 값이면 빈 문자열을 돌려주고 호출부가
 * 정보 카드로 바꾼다 — 서버 응답이 바뀌어도 화면이 남의 출처를 끼워 넣지 않는다.
 */
function safeFileSrc(value) {
  if (typeof value !== 'string' || value.indexOf('/api/files/') !== 0) {
    return '';
  }
  return value;
}

/** 접근 레벨 배지 하나를 만든다(표의 칸·미리보기 머리 양쪽에서 쓴다). 모르는 값이면 null. */
function makeLevelBadge(level) {
  if (typeof level !== 'string' || !Object.prototype.hasOwnProperty.call(LEVEL_BADGE_LABELS, level)) {
    return null;
  }
  var badge = document.createElement('span');
  badge.className = 'badge badge--' + level;
  badge.setAttribute('title', level);
  setText(badge, LEVEL_BADGE_LABELS[level]);
  return badge;
}

/**
 * 표의 「이름」 칸 — 파일명 + (있으면) 「민감」 배지 (15-3, PRD F-22 · 10장).
 *
 * 배지를 붙일지는 서버가 준 `sensitive` 불리언 **하나만** 본다. 화면이 파일명·확장자를 보고
 * 스스로 판단하지 않는다. 배지는 표시일 뿐이고 등급 규칙은 서버가 막는다 (PRD 10장).
 * 파일명은 언제나 textContent 로 넣는다.
 */
function addNameCell(row, name, sensitive) {
  var td = document.createElement('td');
  var text = document.createElement('span');
  setText(text, name);
  td.appendChild(text);
  if (sensitive === true) {
    var badge = document.createElement('span');
    badge.className = 'badge badge--sensitive';
    badge.setAttribute('title', '민감정보 패턴이 검출된 파일입니다 (검사는 보조 장치이며 오탐·미검출이 있습니다)');
    setText(badge, '민감');
    td.appendChild(document.createTextNode(' '));
    td.appendChild(badge);
  }
  row.appendChild(td);
  return td;
}

/** 표의 「레벨」 칸 — 배지 span 하나. 원래 값은 title 에 남겨 둔다(감사·문의용). */
function addLevelCell(row, level) {
  var td = document.createElement('td');
  if (typeof level === 'string' && Object.prototype.hasOwnProperty.call(LEVEL_BADGE_LABELS, level)) {
    var badge = document.createElement('span');
    badge.className = 'badge badge--' + level;
    badge.setAttribute('title', level);
    setText(badge, LEVEL_BADGE_LABELS[level]);
    td.appendChild(badge);
  } else {
    setText(td, level);
  }
  row.appendChild(td);
  return td;
}

/**
 * 옮겨 적기 오류를 줄이려고 **표시용으로만** 4자씩 띄운다 (PRD F-17-2).
 * 값 자체는 바뀌지 않는다 — otpauth 주소·서버로 보내는 값에는 쓰지 않는다.
 */
function groupFours(value) {
  return String(value === null || value === undefined ? '' : value).replace(/(.{4})/g, '$1 ').trim();
}

/** 서버 응답 본문에서 오류 문구만 꺼낸다. 문자열이 아니면 고정 문구로 바꾼다. */
function errorMessage(body) {
  if (body && typeof body.error === 'string' && body.error !== '') {
    return body.error;
  }
  return GENERIC_ERROR;
}

/** 응답을 JSON 으로 읽는다. 본문이 JSON 이 아니면 빈 객체로 취급한다. */
function readJson(response) {
  return response.json().then(
    function (data) {
      return data && typeof data === 'object' ? data : {};
    },
    function () {
      return {};
    }
  );
}

function toLogin() {
  window.location.assign(LOGIN_PATH);
}

/**
 * 최초 로그인 강제 절차 관문이 막은 응답인가 (11단계 — PRD F-16, ARCH 4.0).
 *
 * 서버는 절차를 마치지 않은 계정의 요청을 `403 {error, next}` 로 막는다. 화면이 그 상태를
 * 모른 채 남아 있으면 "아무것도 안 되는 화면" 이 되므로, 홈으로 보내 절차 화면을 보여 준다.
 * **막는 것은 서버**이고 이 함수는 안내일 뿐이다 (PRD S-01).
 */
function toGate(response, data) {
  if (response && response.status === 403
      && data && typeof data.next === 'string' && data.next !== '') {
    // 16-1 — `next:"reauth"` 는 강제 절차가 아니라 **재인증 관문**이다 (PRD F-23).
    // 홈으로 보내지 않고 모달로 비밀번호를 다시 묻고, 성공하면 막혔던 요청을 다시 보낸다.
    if (data.next === 'reauth') {
      return handleReauth(response, data, null);
    }
    window.location.assign(HOME_PATH);
    return true;
  }
  return false;
}

/* ─── 관리 기능 재인증 — sudo 모드 (16-1, PRD F-23 · 10장 「재인증」, ARCH 4.0) ───
 *
 * 관리 화면(계정·조직·감사 로그·휴지통·위키)의 API 가 15분 창이 닫힌 뒤에 불리면 서버가
 * `403 {error, next:"reauth"}` 로 답한다. 그때 이 모달이 열려 현재 비밀번호를 한 번 받고,
 * 성공하면 **막혔던 요청을 그대로 다시 보낸다**(모달을 띄운 쪽이 넘긴 함수 또는 화면의 로더).
 *
 * ★ 막는 것도 여는 것도 서버다. 이 코드는 안내일 뿐이며, 모달을 지워도 관리 API 는 그대로
 *   403 이다 (PRD S-01). 실패 문구는 **서버가 준 문구만** 쓴다.
 * ★ 모달은 createElement + textContent 로만 만든다(문자열 → HTML 변환 0, PRD S-03).
 * ★ 비밀번호는 요청 본문에만 실리고 변수에 남겨 두지 않는다(보낸 직후 칸을 비운다).
 */

/** 재인증이 끝난 뒤 다시 부를 화면의 데이터 로더. 화면이 시작할 때 등록한다. */
var reauthRetry = null;

/** 모달이 두 번 겹쳐 열리지 않게 하는 빗장 (여러 요청이 동시에 403 을 받을 수 있다) */
var reauthOpen = false;

/** 화면별 데이터 로더 등록 — 재인증 성공 뒤 이 함수를 다시 부른다 */
function setReauthRetry(fn) {
  reauthRetry = typeof fn === 'function' ? fn : null;
}

/** 상태 변경 요청에 붙일 CSRF 토큰 (bootPage 가 /api/me 에서 받아 넣는다) */
var reauthCsrf = null;

/**
 * 재인증이 필요해서 막힌 응답인가. 맞으면 모달을 열고 true 를 돌려준다.
 *
 * @param retry 성공 뒤 다시 보낼 요청(함수). 없으면 화면이 등록한 로더, 그것도 없으면
 *              현재 화면을 다시 읽는다(reload) — 어느 쪽이든 **원래 요청이 다시 나간다**.
 */
function handleReauth(response, data, retry) {
  if (!response || response.status !== 403
      || !data || data.next !== 'reauth') {
    return false;
  }
  openReauthModal(function () {
    if (typeof retry === 'function') {
      retry();
      return;
    }
    if (typeof reauthRetry === 'function') {
      reauthRetry();
      return;
    }
    window.location.reload();
  });
  return true;
}

/** 모달 하나를 만들어 띄운다. 성공하면 onDone() 을 부르고 닫는다. */
function openReauthModal(onDone) {
  if (reauthOpen) {
    return;
  }
  if (typeof window.HTMLDialogElement !== 'function' || typeof document.createElement('dialog').showModal !== 'function') {
    // <dialog> 를 못 쓰는 브라우저는 재인증 **화면**으로 보낸다 (같은 서버 관문을 지난다).
    window.location.assign(REAUTH_PATH);
    return;
  }
  reauthOpen = true;

  var dialog = document.createElement('dialog');
  dialog.className = 'modal reauth-modal';

  var title = document.createElement('h2');
  title.className = 'modal__title';
  setText(title, '비밀번호 다시 확인');
  dialog.appendChild(title);

  var lead = document.createElement('p');
  lead.className = 'modal__lead';
  setText(lead, '관리 기능을 쓰려면 비밀번호를 다시 확인합니다. 확인한 창은 15분 동안 열려 있습니다.');
  dialog.appendChild(lead);

  var form = document.createElement('form');
  form.setAttribute('method', 'dialog');
  form.className = 'reauth__form';

  var label = document.createElement('label');
  label.className = 'field';
  var labelText = document.createElement('span');
  labelText.className = 'field__label';
  setText(labelText, '현재 비밀번호');
  var input = document.createElement('input');
  input.type = 'password';
  input.maxLength = 1024;
  input.autocomplete = 'current-password';
  input.required = true;
  label.appendChild(labelText);
  label.appendChild(input);
  form.appendChild(label);

  var row = document.createElement('div');
  row.className = 'modal__actions';
  var submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'btn';
  setText(submit, '확인');
  var cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn btn--quiet';
  setText(cancel, '취소');
  row.appendChild(submit);
  row.appendChild(cancel);
  form.appendChild(row);

  var hint = document.createElement('p');
  hint.className = 'muted';
  setText(hint, '비밀번호를 5번 틀리면 계정이 10분 잠기고 열려 있던 모든 창이 닫힙니다.');
  form.appendChild(hint);

  var modalMsg = document.createElement('p');
  modalMsg.className = 'msg';
  modalMsg.setAttribute('role', 'alert');
  modalMsg.setAttribute('aria-live', 'polite');
  form.appendChild(modalMsg);

  dialog.appendChild(form);
  document.body.appendChild(dialog);

  function close() {
    reauthOpen = false;
    if (dialog.open) {
      dialog.close();
    }
    if (dialog.parentNode) {
      dialog.parentNode.removeChild(dialog);
    }
  }

  cancel.addEventListener('click', function () {
    close();
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (reauthCsrf === null) {
      setText(modalMsg, GENERIC_ERROR);
      return;
    }
    var value = input.value;
    if (value === '') {
      return;
    }
    submit.disabled = true;
    setText(modalMsg, '');
    postReauth(value, reauthCsrf).then(function (result) {
      submit.disabled = false;
      input.value = '';
      if (result.ok) {
        close();
        if (typeof onDone === 'function') {
          onDone();
        }
        return;
      }
      if (result.toLogin) {
        toLogin();
        return;
      }
      setText(modalMsg, result.message);
    });
    value = '';
  });

  dialog.showModal();
  input.focus();
}

/**
 * POST /api/me/reauth — 재인증 창구 (PRD 8장). 응답 문구는 서버가 준 것만 쓴다.
 *
 * @return Promise<{ok:boolean, message:string, toLogin:boolean}>
 */
function postReauth(password, csrfToken) {
  return fetch('/api/me/reauth', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify({ password: password }),
    credentials: 'same-origin',
    cache: 'no-store'
  })
    .then(function (response) {
      if (response.status === 401) {
        // 5회째 실패로 계정이 잠기면 세션까지 끊긴다 (R-48) — 그때는 로그인 화면으로.
        return { ok: false, message: GENERIC_ERROR, toLogin: true };
      }
      return readJson(response).then(function (data) {
        if (response.ok) {
          return { ok: true, message: '', toLogin: false };
        }
        return { ok: false, message: errorMessage(data), toLogin: false };
      });
    })
    .catch(function () {
      return { ok: false, message: GENERIC_ERROR, toLogin: false };
    });
}

/**
 * `/reauth` 화면 (PRD 8장 `/reauth?to=키` 행). 확인에 성공하면 `to` 키가 가리키는 화면으로
 * 간다 — 키는 **서버가 HTML 에 넣어 준 고정 값**이고, 경로는 위 REAUTH_PATHS 맵에서 온다.
 * 맵에 없는 키(또는 빈 값)면 홈으로 간다.
 */
function initReauthPage(form, csrfToken) {
  var msg = document.getElementById('reauth-msg');
  var input = document.getElementById('reauth-password');
  var submit = document.getElementById('reauth-submit');
  var key = form.getAttribute('data-to');
  var target = typeof key === 'string' && Object.prototype.hasOwnProperty.call(REAUTH_PATHS, key)
    ? REAUTH_PATHS[key]
    : HOME_PATH;

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (csrfToken === null) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    var value = input.value;
    if (value === '') {
      return;
    }
    submit.disabled = true;
    setText(msg, '');
    postReauth(value, csrfToken).then(function (result) {
      submit.disabled = false;
      input.value = '';
      if (result.ok) {
        window.location.assign(target);
        return;
      }
      if (result.toLogin) {
        toLogin();
        return;
      }
      setText(msg, result.message);
    });
    value = '';
  });
}

/** 크기 표시 — 숫자만 다룬다(서버 문자열을 그대로 쓰지 않는다) */
function formatSize(bytes) {
  var n = typeof bytes === 'number' && isFinite(bytes) && bytes >= 0 ? bytes : 0;
  if (n < 1024) {
    return n + ' B';
  }
  if (n < 1024 * 1024) {
    return (n / 1024).toFixed(1) + ' KB';
  }
  return (n / (1024 * 1024)).toFixed(1) + ' MB';
}

/* ─── 로그인 화면 — 순차 2단계 (ARCH 4.0 "2단계 인증 로그인 흐름") ───
 *
 * ① 아이디·비밀번호만 보낸다. 코드 칸(#otp-field)은 처음엔 hidden 이다.
 * ② 서버가 401 + mfa_required:true 로 답하면(= 비밀번호는 맞았고 그 계정이 TOTP 를 켰다)
 *    코드 칸을 펼쳐 포커스를 주고, 같은 아이디·비밀번호에 코드를 더해 다시 보낸다.
 * ③ 코드가 틀리면 401 동일 문구(mfa_required 없음) — 코드 칸만 비우고 2단계에 머문다
 *    (다시 코드를 넣어 재시도할 수 있게. 틀린 코드는 서버가 실패 5회로 세어 잠근다).
 *
 * ★ 화면은 "이 계정이 2단계 인증을 쓰는지" 를 **미리 알지 못한다**. 서버가 1단계 응답으로
 *   알려 줄 때까지 칸을 열지 않는다 (PRD S-01: 판단은 서버, 화면은 결과를 그릴 뿐).
 * ★ 비밀번호는 2단계 요청에 다시 실어야 하므로 **JS 변수에 잠깐만** 들고 있는다.
 *   쿠키·localStorage·sessionStorage 에 넣지 않는다 — 페이지를 떠나면 같이 사라진다.
 *   비밀번호 칸은 2단계로 넘어갈 때 비우고, 비어 있어도 전송이 막히지 않게
 *   그동안만 required 를 뗀다(브라우저 기본 검사는 submit 이벤트보다 먼저 돈다).
 */
function initLogin(form) {
  var msg = document.getElementById('msg');
  var submit = document.getElementById('login-submit');
  var otpField = document.getElementById('otp-field');
  var otpInput = document.getElementById('otp');
  var passwordInput = form.elements.password;

  /** 1단계에서 받은 비밀번호를 2단계 요청까지만 들고 있는 자리 (저장소에 쓰지 않는다) */
  var pendingPassword = null;

  /** 지금 2단계(코드 입력) 화면인가 */
  function inStep2() {
    return otpField !== null && otpField.hidden !== true;
  }

  /** 1단계로 되돌린다 — 들고 있던 비밀번호를 버리고 코드 칸을 닫는다 */
  function resetToStep1() {
    pendingPassword = null;
    if (otpField) {
      otpField.hidden = true;
    }
    if (otpInput) {
      otpInput.value = '';
    }
    if (passwordInput) {
      passwordInput.value = '';
      passwordInput.required = true;
    }
  }

  /** 서버가 "코드가 더 필요하다" 고 답했을 때만 2단계로 넘어간다 */
  function enterStep2(password) {
    pendingPassword = password;
    if (passwordInput) {
      // 비밀번호는 화면에서 지우고 변수에만 둔다. 빈 칸이 전송을 막지 않게 required 를 뗀다.
      passwordInput.value = '';
      passwordInput.required = false;
    }
    if (otpField) {
      otpField.hidden = false;
    }
    if (otpInput) {
      otpInput.value = '';
      otpInput.focus();
    }
  }

  form.addEventListener('submit', function (event) {
    // 브라우저 기본 폼 전송(폼 인코딩)을 막는다 — 서버는 JSON 만 받는다 (ARCH 4.0).
    event.preventDefault();
    setText(msg, '');
    if (submit) {
      submit.disabled = true;
    }

    var typed = passwordInput ? passwordInput.value : '';
    var payload = {
      username: form.elements.username.value,
      // 2단계에서는 비밀번호 칸이 비어 있다 — 들고 있던 값을 다시 쓴다.
      // 사용자가 2단계에서 비밀번호를 다시 적었다면 적은 쪽이 이긴다.
      password: typed !== '' ? typed : (pendingPassword === null ? '' : pendingPassword)
    };
    // 코드는 2단계에서 값이 있을 때만 싣는다. 비어 있으면 키 자체를 넣지 않는다 —
    // 2단계 인증을 켜지 않은 계정의 요청은 예전과 한 글자도 달라지지 않는다.
    var otp = inStep2() && otpInput ? String(otpInput.value).trim() : '';
    if (otp !== '') {
      payload.otp = otp;
    }

    fetch('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 200) {
          pendingPassword = null;
          window.location.assign(HOME_PATH);
          return null;
        }
        return readJson(response).then(function (body) {
          if (body && body.mfa_required === true) {
            // 1단계 통과 — 비밀번호는 맞았다. 코드만 받아서 다시 보내면 된다.
            // (여기서는 서버가 실패 횟수를 올리지 않는다 — 아직 아무것도 틀리지 않았다.)
            enterStep2(payload.password);
            setText(msg, '이 계정은 2단계 인증이 켜져 있습니다. 인증 앱의 6자리 코드를 넣으세요');
            return null;
          }
          // 서버가 준 문구만 띄운다. 브라우저가 사유를 추측하지 않는다.
          setText(msg, errorMessage(body));
          if (inStep2() && response.status === 401) {
            // 코드가 틀렸다 — 2단계에 머물고 코드 칸만 비운다.
            // (코드는 30초마다 바뀐다. 틀린 값을 남겨 두면 다시 눌러도 또 틀린다.)
            if (otpInput) {
              otpInput.value = '';
              otpInput.focus();
            }
            return null;
          }
          // 그 밖의 실패(아이디·비밀번호 틀림, 잠금 423 등)는 처음부터 다시.
          resetToStep1();
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
        resetToStep1();
      })
      .then(function () {
        if (submit) {
          submit.disabled = false;
        }
      });
  });

  // 화면을 새로 열 때는 언제나 1단계에서 시작한다(뒤로 가기로 되살아난 값 제거).
  resetToStep1();
}

/* ─── 파일 화면 (5단계) ───────────────────────────────────────
 * myTeamId — /api/me 가 준 이 사용자의 소속 팀(team_id, 없으면 null).
 *   레벨 기본값(team)과 팀 <select> 기본 선택에만 쓰는 **화면 편의 값**이다.
 *   다른 팀을 골라 보내는 것을 화면에서 막지 않는다 — 서버가 403 으로 막고 감사 로그에 남긴다.
 */
function initFiles(csrfToken, uploadLevels, canFetch, myTeamId) {
  var msg = document.getElementById('msg');
  var body = document.getElementById('files-body');
  var empty = document.getElementById('files-empty');
  var pageInfo = document.getElementById('page-info');
  var prev = document.getElementById('page-prev');
  var next = document.getElementById('page-next');
  var searchForm = document.getElementById('search-form');
  var qInput = document.getElementById('search-q');
  var sortSelect = document.getElementById('search-sort');
  var dirSelect = document.getElementById('search-dir');
  var uploadForm = document.getElementById('upload-form');
  var fileInput = document.getElementById('upload-file');
  var descInput = document.getElementById('upload-description');
  var levelSelect = document.getElementById('upload-level');
  var teamField = document.getElementById('upload-team-field');
  var teamSelect = document.getElementById('upload-team');
  var uploadSubmit = document.getElementById('upload-submit');
  var uploadPanel = document.getElementById('upload-panel');
  var uploadToggle = document.getElementById('upload-toggle');
  var fetchToggle = document.getElementById('fetch-toggle');
  var fetchPanel = document.getElementById('fetch-panel');
  var fetchForm = document.getElementById('fetch-form');
  var fetchUrl = document.getElementById('fetch-url');
  var fetchDesc = document.getElementById('fetch-description');
  var fetchLevel = document.getElementById('fetch-level');
  var fetchTeamField = document.getElementById('fetch-team-field');
  var fetchTeam = document.getElementById('fetch-team');
  var fetchSubmit = document.getElementById('fetch-submit');

  var state = { q: '', sort: 'created_at', dir: 'desc', page: 1, total: 0, perPage: 20 };

  /** 상태 변경 요청 공통 헤더 (PRD S-09-3) */
  function csrfHeaders() {
    return { 'X-CSRF-Token': csrfToken };
  }

  function clearRows() {
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value);
    row.appendChild(td);
    return td;
  }

  function deleteFile(id) {
    if (!UUID_RE.test(id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    setText(msg, '');
    fetch('/api/files/' + id, {
      method: 'DELETE',
      headers: csrfHeaders(),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        if (response.status === 200) {
          loadFiles();
          return null;
        }
        return readJson(response).then(function (data) {
          setText(msg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /**
   * 설명·접근 등급 바꾸기 (PATCH /api/files/{id}).
   * 사용자가 적은 값을 **그대로** 보낸다 — 등급을 고를 자격이 있는지, 낮추는 변경인지는
   * 서버(Authz)가 판단한다. 여기서 미리 걸러내면 그것이 곧 화면에 심은 권한 판단이 된다.
   * 보내는 것은 **바뀐 키만**이다 (허용 항목은 description·access_level·team_id 셋뿐).
   */
  function editFile(file) {
    if (!UUID_RE.test(file.id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }

    var currentDescription = typeof file.description === 'string' ? file.description : '';
    var description = window.prompt('설명 (500자 이내)', currentDescription);
    if (description === null) {
      return;   // 취소 → 아무것도 보내지 않는다
    }

    var payload = {};
    if (description !== currentDescription) {
      payload.description = description;
    }

    var currentLevel = typeof file.access_level === 'string' ? file.access_level : '';
    var level = window.prompt('접근 등급 (public / internal / team / admin-only)', currentLevel);
    if (level !== null) {
      if (level !== currentLevel) {
        payload.access_level = level;
      }
      if (level === 'team') {
        var currentTeam =
          file.team_id === null || file.team_id === undefined ? '' : String(file.team_id);
        var team = window.prompt('팀 번호', currentTeam);
        if (team !== null && team !== currentTeam) {
          payload.team_id = team;
        }
      }
    }

    if (Object.keys(payload).length === 0) {
      // 서버도 400 으로 막지만, 굳이 바뀌는 값이 없는 요청을 보내지 않는다.
      return;
    }

    setText(msg, '');
    fetch('/api/files/' + file.id, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        if (response.status === 200) {
          loadFiles();
          return null;
        }
        return readJson(response).then(function (data) {
          // 403·400 문구는 서버가 준 것만 쓴다 (사유를 브라우저가 추측하지 않는다).
          setText(msg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ─── 미리보기 모달 (12-2 — PRD F-18, ARCH 5.8, SECURITY R-41) ──────────
   *
   * 규칙
   *   · 본문은 **서버 응답의 갈래(kind)** 로만 갈라진다. 화면이 확장자·MIME 을 보고 정하지 않는다.
   *   · 글자는 전부 textContent 다. 문자열을 HTML 로 바꾸는 API(inner/outer HTML 계열)는
   *     여기에도 없다 (PRD S-03).
   *   · <img>·<iframe> 의 src 는 safeFileSrc() 를 통과한 값만 setAttribute 로 넣는다.
   *     PDF 는 토큰 없는 sandbox(스크립트·폼·팝업·같은 출처 전부 금지) + referrerpolicy 로 끼운다.
   *     응답 자체에도 `Content-Security-Policy: sandbox` 가 붙어 있다(서버가 붙인다).
   *   · 닫을 때 본문을 비우고 src 를 지운다 — 닫힌 모달이 파일을 계속 물고 있지 않게.
   */
  var previewDialog = document.getElementById('preview-dialog');
  var previewBody = document.getElementById('preview-body');
  var previewName = document.getElementById('preview-name');
  var previewSize = document.getElementById('preview-size');
  var previewLevel = document.getElementById('preview-level');
  var previewTeam = document.getElementById('preview-team');
  var previewUploader = document.getElementById('preview-uploader');
  var previewKind = document.getElementById('preview-kind');
  var previewSha = document.getElementById('preview-sha');
  var previewCreated = document.getElementById('preview-created');
  var previewMsg = document.getElementById('preview-msg');
  var previewDownload = document.getElementById('preview-download');
  var previewOpen = document.getElementById('preview-open');
  var previewClose = document.getElementById('preview-close');
  var previewCloseX = document.getElementById('preview-close-x');

  /** 본문을 비운다. <img>·<iframe> 은 지우기 전에 src 를 먼저 없앤다. */
  function clearPreviewBody() {
    if (!previewBody) {
      return;
    }
    var media = previewBody.querySelectorAll('img, iframe');
    var i;
    for (i = 0; i < media.length; i++) {
      media[i].removeAttribute('src');
    }
    previewBody.replaceChildren();
  }

  /** 본문 자리에 놓는 안내 카드(글자만). 지원하지 않는 형식·읽기 실패에 쓴다. */
  function previewCard(text, extra) {
    var card = document.createElement('div');
    card.className = 'preview-card';
    var p = document.createElement('p');
    p.className = 'preview-card__text';
    setText(p, text);
    card.appendChild(p);
    if (typeof extra === 'string' && extra !== '') {
      var sub = document.createElement('p');
      sub.className = 'preview-card__sub';
      setText(sub, extra);
      card.appendChild(sub);
    }
    return card;
  }

  /** 본문 위에 붙이는 한 줄 안내(잘림·인코딩). 글자만 넣는다. */
  function previewNote(text) {
    var note = document.createElement('p');
    note.className = 'preview-note';
    setText(note, text);
    return note;
  }

  function renderPreviewImage(data) {
    var src = safeFileSrc(data.src);
    if (src === '') {
      return previewCard('이미지를 표시할 수 없습니다.', '서버가 준 주소가 파일 주소 형태가 아닙니다.');
    }
    var img = document.createElement('img');
    img.className = 'preview-img';
    img.setAttribute('alt', typeof data.name === 'string' ? data.name : '미리보기 이미지');
    img.setAttribute('src', src);
    return img;
  }

  function renderPreviewPdf(data) {
    var src = safeFileSrc(data.src);
    if (src === '') {
      return previewCard('문서를 표시할 수 없습니다.', '서버가 준 주소가 파일 주소 형태가 아닙니다.');
    }
    var frame = document.createElement('iframe');
    frame.className = 'preview-frame';
    // 토큰이 하나도 없는 sandbox = 스크립트·폼·팝업·같은 출처 전부 금지
    frame.setAttribute('sandbox', '');
    frame.setAttribute('referrerpolicy', 'no-referrer');
    frame.setAttribute('title', typeof data.name === 'string' ? data.name : 'PDF 미리보기');
    frame.setAttribute('src', src);
    return frame;
  }

  function renderPreviewText(data) {
    var wrap = document.createDocumentFragment();
    if (data.truncated === true) {
      wrap.appendChild(previewNote('파일이 길어 앞부분 64KiB 만 표시합니다. 전체 내용은 다운로드로 확인하세요.'));
    }
    if (data.encoding === 'CP949') {
      wrap.appendChild(previewNote('CP949 로 저장된 파일이라 UTF-8 로 바꿔 표시합니다.'));
    } else if (data.encoding === 'unknown') {
      wrap.appendChild(previewNote('문자 인코딩을 알 수 없어 일부 글자가 치환문자(�)로 보일 수 있습니다.'));
    }
    var pre = document.createElement('pre');
    pre.className = 'preview-pre';
    setText(pre, typeof data.content === 'string' ? data.content : '');
    wrap.appendChild(pre);
    return wrap;
  }

  function renderPreviewOffice(data) {
    var wrap = document.createDocumentFragment();
    if (data.truncated === true) {
      wrap.appendChild(previewNote('문서가 길어 앞부분 20,000자만 표시합니다. 전체 내용은 다운로드로 확인하세요.'));
    }
    wrap.appendChild(previewNote('서식·그림 없이 글자만 뽑은 발췌입니다.'));
    var pre = document.createElement('pre');
    pre.className = 'preview-pre';
    setText(pre, typeof data.excerpt === 'string' ? data.excerpt : '');
    wrap.appendChild(pre);
    return wrap;
  }

  function renderPreviewTable(data) {
    var rows = Array.isArray(data.rows) ? data.rows : [];
    if (rows.length === 0) {
      return previewCard('표에 표시할 내용이 없습니다.');
    }
    var wrap = document.createDocumentFragment();
    if (data.truncated === true) {
      wrap.appendChild(previewNote('표가 커서 앞 200행 × 50열까지만 표시합니다.'));
    }
    var table = document.createElement('table');
    table.className = 'preview-table';
    var tbody = document.createElement('tbody');
    rows.forEach(function (cells) {
      var tr = document.createElement('tr');
      var list = Array.isArray(cells) ? cells : [];
      list.forEach(function (cell) {
        // 칸 하나하나가 textContent 다 — `<script>` 든 `<img onerror>` 든 글자로만 들어간다.
        var td = document.createElement('td');
        setText(td, cell);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    var scroll = document.createElement('div');
    scroll.className = 'preview-scroll';
    scroll.appendChild(table);
    wrap.appendChild(scroll);
    return wrap;
  }

  function renderPreviewArchive(data) {
    var entries = Array.isArray(data.entries) ? data.entries : [];
    var wrap = document.createDocumentFragment();
    var total = typeof data.total_entries === 'number' ? data.total_entries : entries.length;
    wrap.appendChild(previewNote(
      '압축을 풀지 않고 목록만 읽었습니다 · 전체 ' + total + '개 중 ' + entries.length + '개 표시'
    ));
    if (entries.length === 0) {
      wrap.appendChild(previewCard('압축 파일 안에 항목이 없습니다.'));
      return wrap;
    }
    var table = document.createElement('table');
    table.className = 'preview-table';
    var thead = document.createElement('thead');
    var headRow = document.createElement('tr');
    ['이름', '크기', '압축 크기', '폴더'].forEach(function (label) {
      var th = document.createElement('th');
      th.setAttribute('scope', 'col');
      setText(th, label);
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);
    var tbody = document.createElement('tbody');
    entries.forEach(function (entry) {
      var tr = document.createElement('tr');
      var name = document.createElement('td');
      setText(name, entry && entry.name);
      tr.appendChild(name);
      var size = document.createElement('td');
      setText(size, formatSize(entry && entry.size));
      tr.appendChild(size);
      var comp = document.createElement('td');
      setText(comp, formatSize(entry && entry.compressed));
      tr.appendChild(comp);
      var dir = document.createElement('td');
      setText(dir, entry && entry.is_dir === true ? '예' : '—');
      tr.appendChild(dir);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    var scroll = document.createElement('div');
    scroll.className = 'preview-scroll';
    scroll.appendChild(table);
    wrap.appendChild(scroll);
    return wrap;
  }

  function renderPreviewNone(data) {
    if (data.reason === 'parse_failed') {
      return previewCard(
        '내용을 읽어 내지 못했습니다.',
        '파일이 손상되었거나 미리보기가 다루지 못하는 구조입니다. 다운로드로 확인하세요.'
      );
    }
    return previewCard(
      '미리보기를 지원하지 않는 형식입니다.',
      '파일 정보는 오른쪽에 있습니다. 내용은 다운로드로 확인하세요.'
    );
  }

  /** 서버 응답 하나로 모달 전체(머리·본문·정보 패널·발)를 그린다. */
  function renderPreview(data) {
    var kind = typeof data.kind === 'string' ? data.kind : 'none';

    setText(previewName, data.name);
    setText(previewSize, formatSize(data.size_bytes));
    if (previewLevel) {
      previewLevel.replaceChildren();
      var badge = makeLevelBadge(data.access_level);
      if (badge) {
        previewLevel.appendChild(badge);
      } else {
        setText(previewLevel, data.access_level);
      }
    }
    setText(previewTeam, data.team_name === null || data.team_name === undefined ? '' : data.team_name);
    setText(previewUploader, data.uploaded_by);
    setText(previewCreated, data.created_at);
    setText(previewSha, data.sha256_short);

    var kindLabel = Object.prototype.hasOwnProperty.call(PREVIEW_KIND_LABELS, kind)
      ? PREVIEW_KIND_LABELS[kind]
      : PREVIEW_KIND_LABELS.none;
    var ext = typeof data.ext === 'string' && data.ext !== '' ? ' (' + data.ext + ')' : '';
    setText(previewKind, kindLabel + ext);

    clearPreviewBody();
    var node;
    if (kind === 'image') {
      node = renderPreviewImage(data);
    } else if (kind === 'pdf') {
      node = renderPreviewPdf(data);
    } else if (kind === 'text') {
      node = renderPreviewText(data);
    } else if (kind === 'table') {
      node = renderPreviewTable(data);
    } else if (kind === 'archive') {
      node = renderPreviewArchive(data);
    } else if (kind === 'office') {
      node = renderPreviewOffice(data);
    } else {
      node = renderPreviewNone(data);
    }
    if (previewBody && node) {
      previewBody.appendChild(node);
    }

    // 「새 탭에서 열기」는 원본을 그대로 띄우는 이미지·PDF 일 때만 보인다.
    // 그 응답에도 서버가 붙인 CSP sandbox·nosniff 가 그대로 적용된다 (R-41).
    if (previewOpen) {
      var rawSrc = kind === 'image' || kind === 'pdf' ? safeFileSrc(data.src) : '';
      if (rawSrc === '') {
        previewOpen.hidden = true;
        previewOpen.setAttribute('href', '/');
      } else {
        previewOpen.setAttribute('href', rawSrc);
        previewOpen.hidden = false;
      }
    }
  }

  /** 행의 「미리보기」 단추가 부른다. 권한은 서버가 이 요청에서 다시 판단한다 (PRD S-01). */
  function openPreview(file) {
    if (!previewDialog || !previewBody || !UUID_RE.test(file.id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }

    // 응답이 오기 전에도 머리에는 목록이 준 값을 먼저 채운다(빈 모달이 뜨지 않게).
    clearPreviewBody();
    setText(previewMsg, '');
    setText(previewName, file.name);
    setText(previewSize, formatSize(file.size_bytes));
    setText(previewTeam, file.team_name === null || file.team_name === undefined ? '' : file.team_name);
    setText(previewUploader, file.uploaded_by);
    setText(previewCreated, file.created_at);
    setText(previewSha, '');
    setText(previewKind, '');
    if (previewLevel) {
      previewLevel.replaceChildren();
      var badge = makeLevelBadge(file.access_level);
      if (badge) {
        previewLevel.appendChild(badge);
      }
    }
    if (previewOpen) {
      previewOpen.hidden = true;
      previewOpen.setAttribute('href', '/');
    }
    if (previewDownload) {
      // 다운로드는 기존 동작 그대로(항상 첨부) — 같은 접두 검사를 거쳐 주소를 넣는다.
      var downloadHref = safeFileSrc('/api/files/' + file.id + '/download');
      previewDownload.setAttribute('href', downloadHref === '' ? '/' : downloadHref);
    }
    previewBody.appendChild(previewCard('불러오는 중입니다…'));

    if (typeof previewDialog.showModal === 'function') {
      if (!previewDialog.open) {
        previewDialog.showModal();
      }
    } else {
      setText(msg, '이 브라우저에서는 미리보기를 열 수 없습니다');
      return;
    }

    fetch('/api/files/' + file.id + '/preview', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (toGate(response, data)) {
            return null;
          }
          if (!response.ok) {
            // 403·404 문구는 서버가 준 것만 쓴다(사유를 브라우저가 추측하지 않는다).
            clearPreviewBody();
            previewBody.appendChild(previewCard(errorMessage(data)));
            setText(previewKind, '');
            return null;
          }
          renderPreview(data);
          return null;
        });
      })
      .catch(function () {
        clearPreviewBody();
        previewBody.appendChild(previewCard(GENERIC_ERROR));
      });
  }

  function closePreview() {
    if (previewDialog && previewDialog.open) {
      previewDialog.close();
    }
  }

  if (previewDialog) {
    // close 는 Esc 키로도 일어난다 — 어느 경로든 본문을 비운다.
    previewDialog.addEventListener('close', function () {
      clearPreviewBody();
      setText(previewMsg, '');
    });
  }
  if (previewClose) {
    previewClose.addEventListener('click', closePreview);
  }
  if (previewCloseX) {
    previewCloseX.addEventListener('click', closePreview);
  }

  /* ─── 공유 링크 모달 (15-2 — PRD F-13, ARCH 5.12) ──────────────────────
   *
   * 규칙
   *   · 만들어진 주소는 **한 번만** 보인다. 서버는 토큰의 sha256 만 갖고 있어 다시 줄 수 없다 —
   *     그래서 닫으면 지우고, 「내 공유 링크」 목록에도 주소가 없다.
   *   · 화면은 값을 판단하지 않는다. 사용자가 적은 시간·횟수를 그대로 보내고, 범위를 벗어나면
   *     서버가 400 으로 막는다(그 문구를 그대로 보여 준다) — 화면에 규칙을 복사하지 않는다.
   *   · 주소도 사유도 전부 textContent 로 넣는다 (PRD S-03).
   */
  var shareDialog = document.getElementById('share-dialog');
  var shareForm = document.getElementById('share-form');
  var shareFileName = document.getElementById('share-file');
  var shareHours = document.getElementById('share-hours');
  var shareMax = document.getElementById('share-max');
  var shareReason = document.getElementById('share-reason');
  var shareSubmit = document.getElementById('share-submit');
  var shareResult = document.getElementById('share-result');
  var shareUrlText = document.getElementById('share-url');
  var shareMeta = document.getElementById('share-meta');
  var shareCopy = document.getElementById('share-copy');
  var shareMsg = document.getElementById('share-msg');
  var shareClose = document.getElementById('share-close');
  var shareCloseX = document.getElementById('share-close-x');

  /** 지금 모달이 다루는 파일 id (서버가 준 UUID). 요청을 보낼 때 형태를 한 번 더 본다. */
  var shareTargetId = null;

  function resetShareResult() {
    if (shareResult) {
      shareResult.hidden = true;
    }
    setText(shareUrlText, '');
    setText(shareMeta, '');
  }

  function openShare(file) {
    if (!shareDialog || !UUID_RE.test(file.id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    shareTargetId = file.id;
    setText(shareFileName, file.name);
    setText(shareMsg, '');
    resetShareResult();
    if (shareForm) {
      shareForm.hidden = false;
    }
    if (shareHours) {
      shareHours.value = '24';
    }
    if (shareMax) {
      shareMax.value = '1';
    }
    if (shareReason) {
      shareReason.value = '';
    }
    if (shareSubmit) {
      shareSubmit.disabled = false;
    }
    shareDialog.showModal();
  }

  function closeShare() {
    if (shareDialog && shareDialog.open) {
      shareDialog.close();
    }
  }

  if (shareDialog) {
    // close 는 Esc 로도 일어난다 — 어느 경로든 주소를 화면에서 지운다.
    shareDialog.addEventListener('close', function () {
      shareTargetId = null;
      resetShareResult();
      setText(shareMsg, '');
    });
  }
  if (shareClose) {
    shareClose.addEventListener('click', closeShare);
  }
  if (shareCloseX) {
    shareCloseX.addEventListener('click', closeShare);
  }

  if (shareForm) {
    shareForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (shareTargetId === null || !UUID_RE.test(shareTargetId)) {
        setText(shareMsg, GENERIC_ERROR);
        return;
      }

      /* 허용 키는 서버와 같은 셋뿐이다. 사유가 비어 있으면 키 자체를 보내지 않는다 —
         서버는 모르는 키가 하나라도 있으면 400 이므로 화면도 같은 형태만 만든다. */
      var payload = {
        expires_hours: shareHours && shareHours.value !== '' ? shareHours.value : '24',
        max_downloads: shareMax && shareMax.value !== '' ? shareMax.value : '1'
      };
      if (shareReason && typeof shareReason.value === 'string' && shareReason.value !== '') {
        payload.reason_text = shareReason.value;
      }

      setText(shareMsg, '');
      if (shareSubmit) {
        shareSubmit.disabled = true;
      }

      fetch('/api/files/' + shareTargetId + '/share', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(function (response) {
          if (response.status === 401) {
            toLogin();
            return null;
          }
          return readJson(response).then(function (data) {
            if (response.status !== 201) {
              // 403·400 문구는 서버가 준 것만 쓴다 (사유를 브라우저가 추측하지 않는다).
              setText(shareMsg, errorMessage(data));
              if (shareSubmit) {
                shareSubmit.disabled = false;
              }
              return null;
            }
            // 주소는 여기서 한 번만 보인다. 폼은 접어 두어 실수로 또 만들지 않게 한다.
            if (shareForm) {
              shareForm.hidden = true;
            }
            if (shareResult) {
              shareResult.hidden = false;
            }
            setText(shareUrlText, typeof data.url === 'string' ? data.url : '');
            setText(
              shareMeta,
              '만료 ' + (typeof data.expires_at === 'string' ? data.expires_at : '—') +
                ' · 최대 ' + (typeof data.max_downloads === 'number' ? data.max_downloads : '—') + '회'
            );
            return null;
          });
        })
        .catch(function () {
          setText(shareMsg, GENERIC_ERROR);
          if (shareSubmit) {
            shareSubmit.disabled = false;
          }
        });
    });
  }

  if (shareCopy) {
    shareCopy.addEventListener('click', function () {
      var text = shareUrlText ? shareUrlText.textContent : '';
      if (typeof text !== 'string' || text === '') {
        return;
      }
      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(text).then(
          function () {
            setText(shareMsg, '주소를 복사했습니다.');
          },
          function () {
            setText(shareMsg, '복사하지 못했습니다. 주소를 직접 선택해 복사하세요.');
          }
        );
        return;
      }
      setText(shareMsg, '이 브라우저에서는 복사 단추를 쓸 수 없습니다. 주소를 직접 선택해 복사하세요.');
    });
  }

  /*
   * 작업 칸 — 12단계부터 **아이콘 단추**다. 뜻은 title·aria-label 의 한글이 전한다.
   * 무엇을 만들지는 여전히 서버가 행마다 내려 준 can_delete·can_edit 만 본다 —
   * 화면이 역할·소유자를 따지지 않는다 (PRD S-01, 10장). 규칙은 그대로이고 모양만 바뀌었다.
   */
  function addActions(row, file) {
    var td = document.createElement('td');
    td.className = 'actions';

    // 서버가 준 ID 가 UUID 형태일 때만 링크를 만든다(주소에 예상 밖의 문자가 들어가지 않게).
    if (UUID_RE.test(file.id)) {
      // 「미리보기」는 **모든 행**에 만든다 — 지원하지 않는 형식이면 서버가 kind:'none' 을 주고
      // 정보 카드만 나온다. preview_kind 는 마우스 올림 문구(title)의 참고용일 뿐이고,
      // 무엇을 보여 줄지는 서버 응답이 정한다 (ARCH 5.8).
      var kindLabel = Object.prototype.hasOwnProperty.call(PREVIEW_KIND_LABELS, file.preview_kind)
        ? PREVIEW_KIND_LABELS[file.preview_kind]
        : '';
      var previewButton = makeIconButton('eye', kindLabel === '' ? '미리보기' : '미리보기 — ' + kindLabel);
      previewButton.addEventListener('click', function () {
        openPreview(file);
      });
      td.appendChild(previewButton);

      var link = document.createElement('a');
      link.setAttribute('href', '/api/files/' + file.id + '/download');
      link.className = 'btn btn--icon';
      link.setAttribute('title', '다운로드');
      link.setAttribute('aria-label', '다운로드');
      link.appendChild(makeIcon('download'));
      td.appendChild(link);

      // 수정 버튼은 서버가 그 행에 대해 can_edit 를 참으로 준 경우에만 만든다.
      if (file.can_edit === true) {
        var editButton = makeIconButton('edit', '수정');
        editButton.addEventListener('click', function () {
          editFile(file);
        });
        td.appendChild(editButton);

        /* 15-2 — 「공유 링크」도 **같은 can_edit** 로 갈린다: 링크를 만들 수 있는 사람은
           그 파일을 고칠 수 있는 사람이다 (PRD F-13). 화면이 역할·소유자를 따지지 않는다.
           단추가 보이지 않아도, 콘솔에서 직접 POST 해도 서버가 다시 판단한다 (PRD S-01). */
        var shareButton = makeIconButton('link', '공유 링크 만들기');
        shareButton.addEventListener('click', function () {
          openShare(file);
        });
        td.appendChild(shareButton);
      }

      // 삭제 버튼도 서버가 그 행에 대해 can_delete 를 참으로 준 경우에만 만든다.
      // 화면이 역할·소유자를 따져서 정하지 않는다 — 판단은 서버가 이미 했다 (PRD S-01, 10장).
      if (file.can_delete === true) {
        var button = makeIconButton('trash', '삭제');
        button.addEventListener('click', function () {
          deleteFile(file.id);
        });
        td.appendChild(button);
      }
    }

    row.appendChild(td);
  }

  function renderFiles(data) {
    clearRows();
    var files = data && Array.isArray(data.files) ? data.files : [];
    state.total = typeof data.total === 'number' ? data.total : 0;
    state.perPage = typeof data.per_page === 'number' && data.per_page > 0 ? data.per_page : 20;
    state.page = typeof data.page === 'number' && data.page > 0 ? data.page : 1;

    files.forEach(function (file) {
      var row = document.createElement('tr');
      // 15-3 — 「민감」 배지는 서버가 준 sensitive 불리언만 보고 붙인다 (PRD F-22).
      addNameCell(row, file.name, file.sensitive === true);
      addCell(row, formatSize(file.size_bytes));
      addLevelCell(row, file.access_level);
      addCell(row, file.team_name === null || file.team_name === undefined ? '—' : file.team_name);
      addCell(row, file.uploaded_by);
      addCell(row, file.created_at);
      addCell(row, file.description);
      addActions(row, file);
      body.appendChild(row);
    });

    if (empty) {
      empty.hidden = files.length !== 0;
    }

    var lastPage = state.total === 0 ? 1 : Math.ceil(state.total / state.perPage);
    setText(pageInfo, state.page + ' / ' + lastPage + ' 페이지 · 전체 ' + state.total + '건');
    if (prev) {
      prev.disabled = state.page <= 1;
    }
    if (next) {
      next.disabled = state.page >= lastPage;
    }
  }

  function loadFiles() {
    var query =
      '?q=' + encodeURIComponent(state.q) +
      '&sort=' + encodeURIComponent(state.sort) +
      '&dir=' + encodeURIComponent(state.dir) +
      '&page=' + encodeURIComponent(String(state.page));

    fetch('/api/files' + query, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (!response.ok) {
            setText(msg, errorMessage(data));
            return null;
          }
          renderFiles(data);
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  function loadTeams() {
    fetch('/api/teams', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        if (!response.ok) {
          return null;
        }
        return readJson(response).then(function (data) {
          var teams = data && Array.isArray(data.teams) ? data.teams : [];
          var mine = myTeamId === null || myTeamId === undefined ? '' : String(myTeamId);
          teams.forEach(function (team) {
            // 같은 목록을 업로드 폼과 가져오기 폼 두 곳의 <select> 에 만든다.
            [teamSelect, fetchTeam].forEach(function (select) {
              if (!select) {
                return;
              }
              var option = document.createElement('option');
              option.value = String(team.id);
              setText(option, team.name);
              // 내 팀을 기본 선택으로 둔다(편의). 다른 팀을 고르는 것은 그대로 허용한다 —
              // 남의 팀으로 올리려는 요청은 서버가 403 으로 막고 감사 로그에 남긴다 (PRD S-01).
              if (mine !== '' && String(team.id) === mine) {
                option.selected = true;
              }
              select.appendChild(option);
            });
          });
          return null;
        });
      })
      .catch(function () {
        // 팀 목록을 못 받아도 나머지 화면은 동작한다. 문구는 서버 응답이 없으므로 띄우지 않는다.
      });
  }

  function syncTeamField() {
    if (teamField) {
      teamField.hidden = levelSelect.value !== 'team';
    }
  }

  function syncFetchTeamField() {
    if (fetchTeamField && fetchLevel) {
      fetchTeamField.hidden = fetchLevel.value !== 'team';
    }
  }

  /**
   * URL 로 가져오기 (POST /api/files/fetch, 7단계 F-14).
   * 사용자가 적은 주소를 **그대로** 보낸다 — 허용된 주소인지, 내부망을 가리키는지는 서버가 본다.
   * 실패 문구는 서버가 준 "가져올 수 없습니다" 하나뿐이고, 브라우저는 사유를 추측하지 않는다.
   */
  function fetchByUrl(event) {
    event.preventDefault();
    setText(msg, '');

    if (!fetchUrl || fetchUrl.value === '') {
      return;
    }

    var payload = { url: fetchUrl.value, description: fetchDesc ? fetchDesc.value : '' };
    payload.access_level = fetchLevel ? fetchLevel.value : '';
    if (payload.access_level === '') {
      // 서버도 400 으로 막지만, 무엇을 빠뜨렸는지 바로 알려 준다 (사용자 피드백 ②).
      setText(msg, LEVEL_PLACEHOLDER);
      return;
    }
    if (payload.access_level === 'team' && fetchTeam) {
      payload.team_id = fetchTeam.value;
    }

    fetchSubmit.disabled = true;
    fetch('/api/files/fetch', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (response.status === 201) {
            fetchForm.reset();
            syncFetchTeamField();
            state.page = 1;
            loadFiles();
            return null;
          }
          setText(msg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      })
      .then(function () {
        fetchSubmit.disabled = false;
      });
  }

  function upload(event) {
    event.preventDefault();
    setText(msg, '');

    if (!fileInput.files || fileInput.files.length !== 1) {
      // 서버도 400 으로 막지만, 굳이 빈 요청을 보내지 않는다.
      return;
    }
    if (!levelSelect || levelSelect.value === '') {
      // 접근 레벨을 고르지 않은 채로는 보내지 않는다. 서버도 400 으로 막는다 (사용자 피드백 ②).
      setText(msg, LEVEL_PLACEHOLDER);
      return;
    }

    var form = new FormData();
    form.append('file', fileInput.files[0]);
    form.append('description', descInput.value);
    form.append('access_level', levelSelect.value);
    if (levelSelect.value === 'team') {
      form.append('team_id', teamSelect.value);
    }

    uploadSubmit.disabled = true;
    // headers 에 Content-Type 을 넣지 않는다 — 브라우저가 boundary 를 붙여야 한다.
    fetch('/api/files', {
      method: 'POST',
      headers: csrfHeaders(),
      body: form,
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (response.status === 201) {
            uploadForm.reset();
            syncTeamField();
            state.page = 1;
            loadFiles();
            return null;
          }
          setText(msg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      })
      .then(function () {
        uploadSubmit.disabled = false;
      });
  }

  if (searchForm) {
    searchForm.addEventListener('submit', function (event) {
      event.preventDefault();
      state.q = qInput.value;
      state.sort = sortSelect.value;
      state.dir = dirSelect.value;
      state.page = 1;
      loadFiles();
    });
  }
  if (prev) {
    prev.addEventListener('click', function () {
      if (state.page > 1) {
        state.page -= 1;
        loadFiles();
      }
    });
  }
  if (next) {
    next.addEventListener('click', function () {
      state.page += 1;
      loadFiles();
    });
  }
  if (levelSelect) {
    levelSelect.addEventListener('change', syncTeamField);
  }
  if (uploadForm) {
    uploadForm.addEventListener('submit', upload);
  }
  if (fetchLevel) {
    fetchLevel.addEventListener('change', syncFetchTeamField);
  }
  if (fetchForm) {
    fetchForm.addEventListener('submit', fetchByUrl);
  }

  /**
   * 머리 단추로 접었다 펴는 패널 (12단계 — 한 화면에 폼이 늘 펼쳐져 있지 않게).
   * 여는 것은 표현일 뿐이고, 무엇을 보낼 수 있는지는 서버가 다시 판단한다 (PRD S-01).
   */
  function bindPanelToggle(toggle, panel) {
    if (!toggle || !panel) {
      return;
    }
    toggle.addEventListener('click', function () {
      var open = panel.hidden === true;
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // 업로드 레벨 선택지는 서버가 준 목록으로만 만든다. 하나도 없으면(Guest) 올리기 단추·패널을
  // 감춘다. 감추는 것은 편의일 뿐이다 — 그래도 POST /api/files 는 서버가 다시 막는다 (PRD S-01).
  var hasTeam = myTeamId !== null && myTeamId !== undefined && String(myTeamId) !== '';
  var canUpload = fillLevelOptions(levelSelect, uploadLevels, hasTeam) > 0;
  if (uploadToggle) {
    uploadToggle.hidden = !canUpload;
  }
  if (uploadPanel) {
    uploadPanel.hidden = true;
  }
  if (canUpload) {
    bindPanelToggle(uploadToggle, uploadPanel);
  }

  // "URL 로 가져오기" 단추·패널은 서버가 can_fetch === true 를 준 경우에만 연다
  // (F-14, Manager 이상). 레벨 선택지는 업로드와 같은 목록(서버가 정한 upload_levels)을 쓴다.
  var canFetchUrl = canFetch === true && fillLevelOptions(fetchLevel, uploadLevels, hasTeam) > 0;
  if (fetchToggle) {
    fetchToggle.hidden = !canFetchUrl;
  }
  if (fetchPanel) {
    fetchPanel.hidden = true;
  }
  if (canFetchUrl) {
    bindPanelToggle(fetchToggle, fetchPanel);
  }

  syncTeamField();
  syncFetchTeamField();
  loadTeams();
  loadFiles();
}

/* ─── 감사 로그 화면 (8단계 — GET /audit + GET /api/audit) ────────
 *
 * PRD 10장 "감사 로그" · S-07 "조회 화면 필터" · ARCH 7.6 · SECURITY 1.4.
 *
 * ★ 이 화면이 그리는 값은 **전부 공격자가 값을 정할 수 있는 문자열**이다:
 *   target_name(파일명)·user_agent(요청 헤더)·summary(파일명이 섞인 문장)·detail.
 *   그래서 다른 화면과 똑같이 createElement + textContent 로만 만든다.
 *   detail 은 JSON.stringify 한 **문자열**을 textContent 로 넣고 <details> 로 접는다 —
 *   접었다 펴는 동작은 브라우저 기본 기능이라 인라인 스크립트가 필요 없다(CSP).
 * ★ 권한 판단을 여기서 하지 않는다. 이 화면에 들어온 것 자체가 라우터 정책 audit.read 를
 *   통과했다는 뜻이고, /api/audit 도 같은 정책이 매 요청 다시 검사한다 (PRD S-01).
 */
function initAudit() {
  var msg = document.getElementById('msg');
  var body = document.getElementById('audit-body');
  var empty = document.getElementById('audit-empty');
  var pageInfo = document.getElementById('page-info');
  var prev = document.getElementById('page-prev');
  var next = document.getElementById('page-next');
  var form = document.getElementById('audit-filter');
  var reset = document.getElementById('audit-reset');

  /** 쿼리 파라미터 이름 → 입력 칸 id (육하원칙 6항목). 서버가 값을 다시 검사한다. */
  var FILTERS = [
    ['actor', 'f-actor'],
    ['from', 'f-from'],
    ['to', 'f-to'],
    ['ip', 'f-ip'],
    ['target', 'f-target'],
    ['level', 'f-level'],
    ['action', 'f-action'],
    ['result', 'f-result'],
    ['reason', 'f-reason'],
    ['granted_by', 'f-granted']
  ];

  var state = { page: 1, total: 0, perPage: 20 };

  function clearRows() {
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value === null || value === undefined || value === '' ? '—' : value);
    row.appendChild(td);
    return td;
  }

  /** 표에 넣지 않은 나머지 항목(요청 id · user_agent · detail)을 한 덩어리 글자로 만든다. */
  function detailText(row) {
    var detail = '';
    try {
      detail = row.detail === null || row.detail === undefined ? '' : JSON.stringify(row.detail);
    } catch (e) {
      detail = '';
    }
    return 'request_id: ' + (row.request_id || '—') + '\n' +
      'user_agent: ' + (row.user_agent === null || row.user_agent === undefined ? '—' : row.user_agent) + '\n' +
      'detail: ' + (detail === '' ? '—' : detail);
  }

  function addDetailCell(row, data) {
    var td = document.createElement('td');
    var details = document.createElement('details');
    var label = document.createElement('summary');
    setText(label, '펼치기');
    details.appendChild(label);
    var pre = document.createElement('pre');
    pre.className = 'audit-detail';
    setText(pre, detailText(data));
    details.appendChild(pre);
    td.appendChild(details);
    row.appendChild(td);
  }

  /** "왜" 칸 — 거부는 reason, 허용은 detail.granted_by (PRD 7.7 "허용에도 근거") */
  function whyOf(data) {
    if (typeof data.reason === 'string' && data.reason !== '') {
      return data.reason;
    }
    if (data.detail && typeof data.detail.granted_by === 'string') {
      return data.detail.granted_by;
    }
    return '';
  }

  function targetOf(data) {
    var name = data.target_name === null || data.target_name === undefined ? '' : data.target_name;
    if (name === '') {
      return '';
    }
    if (typeof data.target_type === 'string' && data.target_type !== '') {
      return data.target_type + ' · ' + name;
    }
    return name;
  }

  function render(data) {
    clearRows();
    var rows = data && Array.isArray(data.rows) ? data.rows : [];
    state.total = typeof data.total === 'number' ? data.total : 0;
    state.perPage = typeof data.per_page === 'number' && data.per_page > 0 ? data.per_page : 20;
    state.page = typeof data.page === 'number' && data.page > 0 ? data.page : 1;

    rows.forEach(function (item) {
      var row = document.createElement('tr');
      addCell(row, item.happened_at);
      addCell(row, item.actor_name);
      addCell(row, item.actor_role);
      addCell(row, item.action);
      addCell(row, targetOf(item));
      addCell(row, item.access_level);
      addCell(row, item.result);
      addCell(row, whyOf(item));
      addCell(row, item.ip);
      addCell(row, item.summary);
      addDetailCell(row, item);
      body.appendChild(row);
    });

    if (empty) {
      empty.hidden = rows.length !== 0;
    }

    var lastPage = state.total === 0 ? 1 : Math.ceil(state.total / state.perPage);
    setText(pageInfo, state.page + ' / ' + lastPage + ' 페이지 · 전체 ' + state.total + '건');
    if (prev) {
      prev.disabled = state.page <= 1;
    }
    if (next) {
      next.disabled = state.page >= lastPage;
    }
  }

  /** 비어 있지 않은 칸만 쿼리에 싣는다. 값 검사·허용 목록은 전부 서버가 한다. */
  function buildQuery() {
    var parts = ['page=' + encodeURIComponent(String(state.page))];
    FILTERS.forEach(function (pair) {
      var el = document.getElementById(pair[1]);
      if (!el) {
        return;
      }
      var value = typeof el.value === 'string' ? el.value : '';
      if (value === '') {
        return;
      }
      parts.push(encodeURIComponent(pair[0]) + '=' + encodeURIComponent(value));
    });
    return '?' + parts.join('&');
  }

  function load() {
    fetch('/api/audit' + buildQuery(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          // 16-1 — 15분 창이 닫힌 뒤의 403 이면 모달로 다시 묻고, 성공하면 이 요청을 다시 보낸다
          if (handleReauth(response, data, load)) {
            return null;
          }
          if (!response.ok) {
            setText(msg, errorMessage(data));
            return null;
          }
          setText(msg, '');
          render(data);
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      state.page = 1;
      load();
    });
  }
  if (reset) {
    reset.addEventListener('click', function () {
      if (form) {
        form.reset();
      }
      state.page = 1;
      load();
    });
  }
  if (prev) {
    prev.addEventListener('click', function () {
      if (state.page > 1) {
        state.page -= 1;
        load();
      }
    });
  }
  if (next) {
    next.addEventListener('click', function () {
      state.page += 1;
      load();
    });
  }

  load();
}

/* ─── 내 계정 (10단계 — 비밀번호 변경 · 2단계 인증 TOTP) ──────────
 *
 * PRD 7.7("비밀번호 변경 · 다른 기기 세션 강제 종료") · S-09-4, SECURITY R-05 · R-10 · R-33.
 *
 * ★ 대상은 언제나 **세션의 본인**이다. 화면이 "누구의 비밀번호를" 을 고르지 않는다.
 * ★ 2단계 인증 비밀(secret)과 otpauth 주소는 서버가 enroll 응답으로 딱 한 번 내려 준다.
 *   QR 라이브러리를 쓰지 않으므로 **글자 그대로** 보여 주고 사용자가 인증 앱에 옮겨 적는다.
 *   두 값 모두 textContent 로만 넣는다(다른 모든 값과 같은 규칙).
 * ★ 오류 문구는 서버가 준 것만 쓴다 — 브라우저가 "코드가 틀렸습니다" 같은 말을 지어내지 않는다.
 */
function initAccount(csrfToken, mfaEnabled, onMfaEnabled) {
  var msg = document.getElementById('msg');
  var pwForm = document.getElementById('password-form');
  var pwCurrent = document.getElementById('pw-current');
  var pwNew = document.getElementById('pw-new');
  var pwSubmit = document.getElementById('pw-submit');

  var mfaState = document.getElementById('mfa-state');
  var mfaOff = document.getElementById('mfa-off');
  var mfaSetup = document.getElementById('mfa-setup');
  var mfaEnrollBtn = document.getElementById('mfa-enroll');
  var mfaSecret = document.getElementById('mfa-secret');
  var mfaUri = document.getElementById('mfa-uri');
  var mfaUriLink = document.getElementById('mfa-uri-link');
  var mfaConfirmForm = document.getElementById('mfa-confirm-form');
  var mfaCode = document.getElementById('mfa-code');
  var mfaDisableForm = document.getElementById('mfa-disable-form');
  var mfaOffPassword = document.getElementById('mfa-off-password');
  var mfaOffCode = document.getElementById('mfa-off-code');

  /** 상태 변경 요청 공통 (JSON + CSRF 토큰). 401 이면 로그인 화면으로. */
  function post(path, payload) {
    return fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      return readJson(response).then(function (data) {
        // 강제 절차 관문(403 + next)에 막혔으면 홈으로 — 화면과 서버 상태를 맞춘다 (11단계)
        if (toGate(response, data)) {
          return null;
        }
        return { ok: response.ok, data: data };
      });
    });
  }

  /** "코드를 어디서 구하나" 에 대한 답 — 상태 문구 뒤에 늘 붙인다 (사용자 피드백 ③) */
  var MFA_CODE_HINT =
    ' 코드는 스마트폰 인증 앱(Google Authenticator·Microsoft Authenticator 등)이 ' +
    '30초마다 만들어 주는 6자리 숫자입니다.';

  /** otpauth 링크를 지운다(등록이 끝났거나 아직 받지 않았을 때) */
  function clearMfaUriLink() {
    if (mfaUriLink) {
      mfaUriLink.removeAttribute('href');
      mfaUriLink.hidden = true;
    }
  }

  function showMfa(enabled) {
    setText(
      mfaState,
      (enabled
        ? '2단계 인증이 켜져 있습니다. 로그인할 때 인증 앱의 6자리 코드가 필요합니다.'
        : '2단계 인증이 꺼져 있습니다. 켜면 비밀번호가 새어 나가도 코드 없이는 로그인할 수 없습니다.'
      ) + MFA_CODE_HINT
    );
    if (mfaOff) {
      mfaOff.hidden = enabled;
    }
    if (mfaDisableForm) {
      mfaDisableForm.hidden = !enabled;
    }
    if (mfaSetup && enabled) {
      mfaSetup.hidden = true;
    }
  }

  if (pwForm) {
    pwForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(msg, '');
      pwSubmit.disabled = true;
      post('/api/me/password', { current: pwCurrent.value, 'new': pwNew.value })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          pwCurrent.value = '';
          pwNew.value = '';
          if (!result.ok) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          var revoked = typeof result.data.sessions_revoked === 'number' ? result.data.sessions_revoked : 0;
          setText(msg, '비밀번호를 바꿨습니다. 끊은 다른 기기 세션: ' + revoked + '개');
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        })
        .then(function () {
          pwSubmit.disabled = false;
        });
    });
  }

  if (mfaEnrollBtn) {
    mfaEnrollBtn.addEventListener('click', function () {
      setText(msg, '');
      mfaEnrollBtn.disabled = true;
      post('/api/me/mfa/enroll', {})
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          // 비밀 키는 **표시만** 4자씩 띄운다 (PRD F-17-2). 값은 그대로 —
          // otpauth 주소·복사 대상은 원본이어야 인증 앱이 읽는다.
          setText(mfaSecret, groupFours(result.data.secret));
          setText(mfaUri, result.data.otpauth);
          // 서버가 준 문자열을 링크로 만들기 전에 **스킴을 확인**한다 — javascript: 같은 값이
          // href 로 들어가지 못하게 하는 최소 방어다. 확인을 통과할 때만 href 를 넣는다.
          clearMfaUriLink();
          if (mfaUriLink && typeof result.data.otpauth === 'string'
              && result.data.otpauth.indexOf('otpauth://') === 0) {
            mfaUriLink.setAttribute('href', result.data.otpauth);
            mfaUriLink.hidden = false;
          }
          if (mfaSetup) {
            mfaSetup.hidden = false;
          }
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        })
        .then(function () {
          mfaEnrollBtn.disabled = false;
        });
    });
  }

  if (mfaConfirmForm) {
    mfaConfirmForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(msg, '');
      post('/api/me/mfa/confirm', { otp: mfaCode.value })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          mfaCode.value = '';
          if (!result.ok) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          // 비밀은 화면에서도 지운다 — 등록이 끝나면 더 보여 줄 이유가 없다.
          setText(mfaSecret, '');
          setText(mfaUri, '');
          clearMfaUriLink();
          showMfa(true);
          setText(msg, '2단계 인증을 켰습니다.');
          // 11단계 — 최초 로그인 강제 절차에서 불렀다면 여기서 끝이다(호출자가 다음을 정한다).
          if (typeof onMfaEnabled === 'function') {
            onMfaEnabled();
          }
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        });
    });
  }

  if (mfaDisableForm) {
    mfaDisableForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(msg, '');
      post('/api/me/mfa/disable', {
        current_password: mfaOffPassword.value,
        otp: mfaOffCode.value
      })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          mfaOffPassword.value = '';
          mfaOffCode.value = '';
          if (!result.ok) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          showMfa(false);
          setText(msg, '2단계 인증을 껐습니다.');
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        });
    });
  }

  showMfa(mfaEnabled === true);
}

/* ─── 휴지통 (10단계 F-11 — Admin 만) ────────────────────────────
 *
 * 패널을 여는 것은 서버가 준 can_manage_users 뿐이다(화면이 역할을 보지 않는다).
 * 되살려서 직접 호출해도 GET /api/files/trash · POST …/restore 는 정책 role:Admin 이 다시 막는다.
 */
function initTrash(csrfToken, onRestored) {
  var msg = document.getElementById('msg');
  var body = document.getElementById('trash-body');
  var empty = document.getElementById('trash-empty');

  function clearRows() {
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value === null || value === undefined || value === '' ? '—' : value);
    row.appendChild(td);
    return td;
  }

  function restore(id) {
    if (!UUID_RE.test(id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    setText(msg, '');
    fetch('/api/files/' + id + '/restore', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: '{}',
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        if (response.status === 200) {
          load();
          if (typeof onRestored === 'function') {
            onRestored();
          }
          return null;
        }
        return readJson(response).then(function (data) {
          // 16-1 — 창이 닫혀 막혔으면 모달로 다시 묻고, 성공하면 이 복구 요청을 다시 보낸다
          if (handleReauth(response, data, function () { restore(id); })) {
            return null;
          }
          setText(msg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  function render(data) {
    clearRows();
    var files = data && Array.isArray(data.files) ? data.files : [];
    files.forEach(function (file) {
      var row = document.createElement('tr');
      addCell(row, file.name);
      addCell(row, formatSize(file.size_bytes));
      addLevelCell(row, file.access_level);
      addCell(row, file.uploaded_by);
      addCell(row, file.deleted_by);
      addCell(row, file.deleted_at);
      var td = document.createElement('td');
      td.className = 'actions';
      if (UUID_RE.test(file.id)) {
        var button = makeIconButton('restore', '복구');
        button.addEventListener('click', function () {
          restore(file.id);
        });
        td.appendChild(button);
      }
      row.appendChild(td);
      body.appendChild(row);
    });
    if (empty) {
      empty.hidden = files.length !== 0;
    }
  }

  function load() {
    fetch('/api/files/trash', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          // 16-1 — 재인증 창이 닫혀 막힌 요청이면 모달을 띄우고 성공 뒤 다시 보낸다 (F-23)
          if (handleReauth(response, data, load)) {
            return null;
          }
          if (!response.ok) {
            setText(msg, errorMessage(data));
            return null;
          }
          render(data);
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  load();
}

/* ─── 계정 관리 화면 (10단계 F-10 — GET /users + /api/users, Admin 만) ─
 *
 * PRD 10장 화면 요구사항 · S-07("관리자 행동 사유") · 3장(1인 1계정), SECURITY R-12 · R-20.
 *
 * ★ 이 화면이 그리는 값(아이디·역할·팀 이름)도 사용자가 정한 문자열이다 →
 *   다른 화면과 똑같이 createElement + textContent 로만 만든다.
 * ★ 권한 판단을 브라우저에서 하지 않는다. 이 화면에 들어온 것 자체가 라우터 정책 user.manage 를
 *   통과했다는 뜻이고, /api/users 도 같은 정책이 매 요청 다시 검사한다.
 *   자기 자신·마지막 관리자 금지도 서버(Authz)가 판단한다 — 화면은 서버가 준 is_self 로
 *   버튼을 감추기만 하고, 되살려 눌러도 400 이 돌아온다.
 * ★ 역할 변경·활성 변경에는 **사유가 필수**다. 사유가 비면 보내지 않고(그래도 서버가 400),
 *   사유 원문은 서버 감사 로그 detail.reason_text 에만 남는다.
 */
function initUsers(csrfToken) {
  var msg = document.getElementById('msg');
  var body = document.getElementById('users-body');
  var empty = document.getElementById('users-empty');
  var pageInfo = document.getElementById('page-info');
  var prev = document.getElementById('page-prev');
  var next = document.getElementById('page-next');
  var form = document.getElementById('user-create-form');
  var fUsername = document.getElementById('u-username');
  var fPassword = document.getElementById('u-password');
  var fRole = document.getElementById('u-role');
  var fTeam = document.getElementById('u-team');
  var fExpires = document.getElementById('u-expires');
  var fReason = document.getElementById('u-reason');
  var fSubmit = document.getElementById('u-submit');

  var state = { page: 1, total: 0, perPage: 50 };

  /*
   * 11단계 (PRD F-16 ①) — 생성 폼의 **비밀번호 칸을 없앤다.** 비밀번호는 서버가 만들고
   * 201 응답에 딱 한 번 돌려준다. 칸을 남겨 두면 Admin 이 정한 값을 계속 아는 옛 방식으로
   * 되돌아갈 여지가 생기고, 서버는 이제 `password` 키를 400 으로 거부한다.
   */
  if (fPassword && fPassword.parentNode && fPassword.parentNode.parentNode) {
    fPassword.parentNode.parentNode.removeChild(fPassword.parentNode);
  }
  fPassword = null;

  /**
   * 임시 비밀번호를 **한 번만** 보여 주는 상자 (11단계, SECURITY R-39).
   * 값은 읽기 전용 입력칸의 value 로만 넣고(문서에 HTML 로 쓰지 않는다), 닫으면 지운다.
   * 다시 볼 방법은 없다 — 서버에도 해시만 있고, 잃으면 재발급이 답이다.
   */
  function showTempPassword(username, temp) {
    if (typeof temp !== 'string' || temp === '' || !form || !form.parentNode) {
      return;
    }
    var old = document.getElementById('temp-password-box');
    if (old && old.parentNode) {
      old.parentNode.removeChild(old);
    }

    var box = document.createElement('div');
    box.id = 'temp-password-box';
    box.className = 'notice';

    var title = document.createElement('p');
    title.className = 'notice__title';
    setText(title, '계정 「' + username + '」 의 임시 비밀번호');
    box.appendChild(title);

    var field = document.createElement('input');
    field.type = 'text';
    field.readOnly = true;
    field.className = 'temp-password';
    field.value = temp;                 // 값은 value 로만 (문서에 글자로 심지 않는다)
    box.appendChild(field);

    var warn = document.createElement('p');
    warn.className = 'warn';
    setText(warn, '이 값은 다시 볼 수 없습니다. 안전한 경로로 본인에게만 전달하세요');
    box.appendChild(warn);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn--quiet btn--small';
    setText(close, '닫기');
    close.addEventListener('click', function () {
      field.value = '';
      if (box.parentNode) {
        box.parentNode.removeChild(box);
      }
    });
    box.appendChild(close);

    form.parentNode.insertBefore(box, form.nextSibling);
  }

  /** 상태 변경 요청 공통 (JSON + CSRF). 401 → 로그인, 403+next → 강제 절차 화면 */
  function postJson(path, payload) {
    return fetch(path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      return readJson(response).then(function (data) {
        if (toGate(response, data)) {
          return null;
        }
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }

  function clearRows() {
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value === null || value === undefined || value === '' ? '—' : value);
    row.appendChild(td);
    return td;
  }

  /** PATCH /api/users/{uid} — 바뀌는 값 + 사유만 보낸다 */
  function patch(id, payload) {
    if (typeof id !== 'number' || !isFinite(id) || id <= 0) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    setText(msg, '');
    fetch('/api/users/' + String(id), {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (toGate(response, data)) {
            return null;
          }
          if (!response.ok) {
            setText(msg, errorMessage(data));
            return null;
          }
          load();
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /** 사유를 받는다. 비우거나 취소하면 아무것도 보내지 않는다(서버도 400 으로 막는다). */
  function askReason(what) {
    var reason = window.prompt(what + '\n사유를 적어 주세요 (필수 · 감사 로그에 남습니다)', '');
    if (reason === null) {
      return null;
    }
    if (reason.replace(/\s+/g, '') === '') {
      setText(msg, '사유를 적어야 합니다.');
      return null;
    }
    return reason;
  }

  function addActions(row, item) {
    var td = document.createElement('td');
    td.className = 'actions';

    // 자기 자신은 이 화면에서 바꿀 수 없다(서버 Authz 의 self_change 와 같은 규칙).
    if (item.is_self === true) {
      setText(td, '(본인)');
      row.appendChild(td);
      return;
    }

    var roleButton = document.createElement('button');
    roleButton.type = 'button';
    roleButton.className = 'btn btn--quiet btn--small';
    setText(roleButton, '역할·팀·만료');
    roleButton.addEventListener('click', function () {
      var role = window.prompt('역할 (Admin / Manager / Employee / Guest)', item.role);
      if (role === null) {
        return;
      }
      var payload = {};
      if (role !== item.role) {
        payload.role = role;
      }
      var currentTeam = item.team_id === null || item.team_id === undefined ? '' : String(item.team_id);
      var team = window.prompt('팀 번호 (비우면 팀 없음)', currentTeam);
      if (team !== null && team !== currentTeam) {
        payload.team_id = team === '' ? null : team;
      }
      var expires = window.prompt('만료일 YYYY-MM-DD (Guest 는 필수 · 비우면 없음)', '');
      if (expires !== null && expires !== '') {
        payload.expires_at = expires;
      }
      if (Object.keys(payload).length === 0) {
        return;
      }
      var reason = askReason('계정 「' + item.username + '」 변경');
      if (reason === null) {
        return;
      }
      payload.reason_text = reason;
      patch(item.id, payload);
    });
    td.appendChild(roleButton);

    /*
     * 11단계 (PRD F-16 ⑤) — 임시 비밀번호 재발급 · 2단계 인증 초기화.
     * 둘 다 **사유가 필수**이고 자기 자신에게는 쓸 수 없다(위에서 (본인) 로 끝났다).
     * 화면은 사유를 받아 보낼 뿐이고, 허용 여부·자기 자신 금지는 서버가 다시 판단한다.
     */
    var pwResetButton = document.createElement('button');
    pwResetButton.type = 'button';
    pwResetButton.className = 'btn btn--quiet btn--small';
    setText(pwResetButton, '임시 비밀번호 재발급');
    pwResetButton.addEventListener('click', function () {
      var reason = askReason('계정 「' + item.username + '」 임시 비밀번호 재발급');
      if (reason === null) {
        return;
      }
      setText(msg, '');
      postJson('/api/users/' + String(item.id) + '/password/reset', { reason_text: reason })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          setText(msg, '임시 비밀번호를 재발급하고 그 계정의 모든 세션을 끊었습니다.');
          showTempPassword(item.username, result.data.temp_password);
          load();
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        });
    });
    td.appendChild(pwResetButton);

    var mfaResetButton = document.createElement('button');
    mfaResetButton.type = 'button';
    mfaResetButton.className = 'btn btn--quiet btn--small';
    setText(mfaResetButton, '2단계 인증 초기화');
    mfaResetButton.addEventListener('click', function () {
      var reason = askReason('계정 「' + item.username + '」 2단계 인증 초기화');
      if (reason === null) {
        return;
      }
      setText(msg, '');
      postJson('/api/users/' + String(item.id) + '/mfa/reset', { reason_text: reason })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          setText(msg, '2단계 인증을 초기화했습니다. 대상은 다음 로그인 뒤 다시 등록해야 합니다.');
          load();
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        });
    });
    td.appendChild(mfaResetButton);

    var activeButton = document.createElement('button');
    activeButton.type = 'button';
    activeButton.className = 'btn btn--quiet btn--small';
    setText(activeButton, item.is_active === true ? '비활성화' : '다시 활성화');
    activeButton.addEventListener('click', function () {
      var reason = askReason(
        '계정 「' + item.username + '」 ' + (item.is_active === true ? '비활성화' : '재활성화')
      );
      if (reason === null) {
        return;
      }
      patch(item.id, { is_active: item.is_active !== true, reason_text: reason });
    });
    td.appendChild(activeButton);

    row.appendChild(td);
  }

  function render(data) {
    clearRows();
    var users = data && Array.isArray(data.users) ? data.users : [];
    state.total = typeof data.total === 'number' ? data.total : 0;
    state.perPage = typeof data.per_page === 'number' && data.per_page > 0 ? data.per_page : 50;
    state.page = typeof data.page === 'number' && data.page > 0 ? data.page : 1;

    users.forEach(function (item) {
      var row = document.createElement('tr');
      addCell(row, item.id);
      addCell(row, item.username);
      addCell(row, item.role);
      addCell(row, item.team_name);
      addCell(row, item.is_active === true ? '활성' : '비활성');
      addCell(row, item.expires_at === null ? '—' : item.expires_at + (item.is_expired === true ? ' (만료)' : ''));
      addCell(row, item.mfa_enabled === true ? '켜짐' : '꺼짐');
      addCell(row, item.created_at);
      addActions(row, item);
      body.appendChild(row);
    });

    if (empty) {
      empty.hidden = users.length !== 0;
    }

    var lastPage = state.total === 0 ? 1 : Math.ceil(state.total / state.perPage);
    setText(pageInfo, state.page + ' / ' + lastPage + ' 페이지 · 전체 ' + state.total + '명');
    if (prev) {
      prev.disabled = state.page <= 1;
    }
    if (next) {
      next.disabled = state.page >= lastPage;
    }
  }

  function load() {
    fetch('/api/users?page=' + encodeURIComponent(String(state.page)), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (toGate(response, data)) {
            return null;
          }
          if (!response.ok) {
            setText(msg, errorMessage(data));
            return null;
          }
          render(data);
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  function loadTeams() {
    if (!fTeam) {
      return;
    }
    var blank = document.createElement('option');
    blank.value = '';
    setText(blank, '(팀 없음)');
    fTeam.appendChild(blank);
    fetch('/api/teams', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) {
          return null;
        }
        return readJson(response).then(function (data) {
          var teams = data && Array.isArray(data.teams) ? data.teams : [];
          teams.forEach(function (team) {
            var option = document.createElement('option');
            option.value = String(team.id);
            setText(option, team.name);
            fTeam.appendChild(option);
          });
          return null;
        });
      })
      .catch(function () {
        // 팀 목록을 못 받아도 나머지 화면은 동작한다.
      });
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(msg, '');
      // 비밀번호는 보내지 않는다 — 서버가 만들어 201 응답으로 한 번 돌려준다 (F-16 ①)
      var payload = {
        username: fUsername.value,
        role: fRole.value,
        reason_text: fReason.value
      };
      if (fTeam && fTeam.value !== '') {
        payload.team_id = fTeam.value;
      }
      if (fExpires && fExpires.value !== '') {
        payload.expires_at = fExpires.value;
      }
      fSubmit.disabled = true;
      var newName = String(payload.username);
      postJson('/api/users', payload)
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (result.status !== 201) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          form.reset();
          setText(msg, '계정을 만들었습니다. 아래 임시 비밀번호를 본인에게만 전달하세요 — 첫 로그인에서 반드시 바꾸게 됩니다.');
          showTempPassword(newName, result.data.temp_password);
          load();
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        })
        .then(function () {
          fSubmit.disabled = false;
        });
    });
  }
  if (prev) {
    prev.addEventListener('click', function () {
      if (state.page > 1) {
        state.page -= 1;
        load();
      }
    });
  }
  if (next) {
    next.addEventListener('click', function () {
      state.page += 1;
      load();
    });
  }

  // 16-1 — 재인증 모달이 성공한 뒤 다시 부를 로더 (PRD F-23). 등록하지 않으면 화면을
  // 통째로 다시 읽는다 — 어느 쪽이든 막혔던 요청이 다시 나간다.
  setReauthRetry(load);

  loadTeams();
  load();
}

/* ─── 조직·권한 화면 (12-3 — PRD F-19, ARCH 5.9) ───────────────────
 *
 * 좌측 팀 카드 → 우측 상세·구성원 표 → 아래 권한 매트릭스(읽기 전용).
 *
 * ★ 이 화면도 역할 문자열을 비교하지 않는다. 구성원 표의 역할·매트릭스의 역할 이름은
 *   서버가 준 값을 **글자 그대로** 보여 줄 뿐이고, 매트릭스의 행 순서마저 서버가 보낸
 *   순서를 그대로 따른다. 무엇을 할 수 있는지는 서버 Authz 가 매 요청 판단한다 (PRD S-01).
 * ★ 구성원 배정은 새 API 가 아니라 기존 PATCH /api/users/{uid} 를 부른다 —
 *   자기 자신 금지·마지막 Admin 보호·세션 종료(T-22)가 두 곳으로 갈라지지 않게 (ARCH 5.9).
 * ★ 매트릭스에는 편집 경로가 없다. 앱 DB 계정이 permissions 표에 읽기 권한만 갖는 것이
 *   권한 상승 방어의 핵심이기 때문이다 (ARCH 6.2-1, SECURITY R-43, PRD Q-12).
 * ★ 실패 문구는 서버가 준 것만 보여 준다(브라우저가 사유를 추측하지 않는다).
 */

/** 매트릭스 표의 **열 순서** — 화면 머리글(공개·사내·팀·관리자)과 같은 차례다. */
var MATRIX_LEVELS = ['public', 'internal', 'team', 'admin-only'];

function initOrg(csrfToken) {
  var msg = document.getElementById('msg');

  var cardBox = document.getElementById('org-teams');
  var cardEmpty = document.getElementById('org-teams-empty');

  var detail = document.getElementById('org-detail');
  var detailNone = document.getElementById('org-detail-none');
  var detailName = document.getElementById('org-detail-name');
  var detailBadge = document.getElementById('org-detail-badge');
  var detailMeta = document.getElementById('org-detail-meta');
  var membersBody = document.getElementById('org-members');
  var membersEmpty = document.getElementById('org-members-empty');

  var newButton = document.getElementById('org-team-new');
  var renameButton = document.getElementById('org-rename');
  var assignButton = document.getElementById('org-assign');
  var toggleButton = document.getElementById('org-toggle');

  var createDialog = document.getElementById('team-create-dialog');
  var createForm = document.getElementById('team-create-form');
  var createName = document.getElementById('tc-name');
  var createReason = document.getElementById('tc-reason');
  var createMsg = document.getElementById('tc-msg');
  var createCancel = document.getElementById('tc-cancel');

  var renameDialog = document.getElementById('team-rename-dialog');
  var renameForm = document.getElementById('team-rename-form');
  var renameTarget = document.getElementById('tr-target');
  var renameName = document.getElementById('tr-name');
  var renameReason = document.getElementById('tr-reason');
  var renameMsg = document.getElementById('tr-msg');
  var renameCancel = document.getElementById('tr-cancel');

  var assignDialog = document.getElementById('team-assign-dialog');
  var assignForm = document.getElementById('team-assign-form');
  var assignUser = document.getElementById('ta-user');
  var assignTeam = document.getElementById('ta-team');
  var assignReason = document.getElementById('ta-reason');
  var assignMsg = document.getElementById('ta-msg');
  var assignCancel = document.getElementById('ta-cancel');

  var activeDialog = document.getElementById('team-active-dialog');
  var activeForm = document.getElementById('team-active-form');
  var activeTitle = document.getElementById('td-title');
  var activeNote = document.getElementById('td-note');
  var activeReason = document.getElementById('td-reason');
  var activeMsg = document.getElementById('td-msg');
  var activeCancel = document.getElementById('td-cancel');
  var activeSubmit = document.getElementById('td-submit');

  /** 화면이 들고 있는 것: 팀 목록과 지금 고른 팀 번호뿐이다(권한 값은 들지 않는다). */
  var state = { teams: [], selected: null };

  /* ── 통신 ──────────────────────────────────────────────────── */

  /** GET — 401 → 로그인, 403+next → 강제 절차 화면. 그 밖은 {ok,status,data} */
  function getJson(path) {
    return fetch(path, { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      return readJson(response).then(function (data) {
        if (toGate(response, data)) {
          return null;
        }
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }

  /** 상태를 바꾸는 요청 — CSRF 토큰 헤더를 반드시 붙인다 (PRD S-09-3) */
  function sendJson(method, path, payload) {
    return fetch(path, {
      method: method,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      return readJson(response).then(function (data) {
        if (toGate(response, data)) {
          return null;
        }
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }

  /* ── 대화상자 열고 닫기 ────────────────────────────────────── */

  function openDialog(dialog) {
    if (!dialog || typeof dialog.showModal !== 'function') {
      setText(msg, '이 브라우저에서는 이 창을 열 수 없습니다');
      return false;
    }
    if (!dialog.open) {
      dialog.showModal();
    }
    return true;
  }

  function closeDialog(dialog) {
    if (dialog && dialog.open) {
      dialog.close();
    }
  }

  /* ── 팀 카드 ───────────────────────────────────────────────── */

  function statBlock(label, value) {
    var span = document.createElement('span');
    setText(span, label + ' ');
    var strong = document.createElement('strong');
    setText(strong, value);
    span.appendChild(strong);
    return span;
  }

  function makeCard(team) {
    var card = document.createElement('button');
    card.type = 'button';
    card.className = team.id === state.selected ? 'team-card is-selected' : 'team-card';
    card.setAttribute('aria-pressed', team.id === state.selected ? 'true' : 'false');

    var head = document.createElement('span');
    head.className = 'team-card__head';
    var name = document.createElement('strong');
    name.className = 'team-card__name';
    setText(name, team.name);
    head.appendChild(name);

    var badge = document.createElement('span');
    badge.className = team.is_active === true ? 'badge badge--ok' : 'badge badge--off';
    setText(badge, team.is_active === true ? '활성' : '비활성');
    head.appendChild(badge);
    card.appendChild(head);

    var stats = document.createElement('span');
    stats.className = 'team-card__stats';
    stats.appendChild(statBlock('구성원', team.member_count));
    stats.appendChild(statBlock('Manager', team.manager_count));
    stats.appendChild(statBlock('파일', team.file_count));
    card.appendChild(stats);

    card.addEventListener('click', function () {
      select(team.id);
    });
    return card;
  }

  function renderCards() {
    if (!cardBox) {
      return;
    }
    cardBox.replaceChildren();
    state.teams.forEach(function (team) {
      cardBox.appendChild(makeCard(team));
    });
    if (cardEmpty) {
      cardEmpty.hidden = state.teams.length !== 0;
    }
  }

  /** 지금 고른 팀의 자료 (없으면 null) */
  function selectedTeam() {
    var found = null;
    state.teams.forEach(function (team) {
      if (team.id === state.selected) {
        found = team;
      }
    });
    return found;
  }

  /* ── 상세·구성원 ───────────────────────────────────────────── */

  function renderDetail() {
    var team = selectedTeam();
    if (detailNone) {
      detailNone.hidden = team !== null;
    }
    if (detail) {
      detail.hidden = team === null;
    }
    if (team === null) {
      return;
    }
    setText(detailName, team.name);
    if (detailBadge) {
      detailBadge.className = team.is_active === true ? 'badge badge--ok' : 'badge badge--off';
      setText(detailBadge, team.is_active === true ? '활성' : '비활성');
    }
    setText(detailMeta, '만든 날 ' + team.created_at + ' · 마지막 변경 ' + team.updated_at);
    if (toggleButton) {
      toggleButton.className = team.is_active === true
        ? 'btn btn--danger btn--small'
        : 'btn btn--quiet btn--small';
      setText(toggleButton, team.is_active === true ? '비활성화' : '활성화');
    }
  }

  function renderMembers(data) {
    if (!membersBody) {
      return;
    }
    membersBody.replaceChildren();
    var members = data && Array.isArray(data.members) ? data.members : [];
    members.forEach(function (member) {
      var row = document.createElement('tr');

      var nameCell = document.createElement('td');
      var strong = document.createElement('strong');
      setText(strong, member.username);
      nameCell.appendChild(strong);
      row.appendChild(nameCell);

      var roleCell = document.createElement('td');
      setText(roleCell, member.role);
      row.appendChild(roleCell);

      var stateCell = document.createElement('td');
      var badge = document.createElement('span');
      badge.className = member.is_active === true ? 'badge badge--ok' : 'badge badge--off';
      setText(badge, member.is_active === true ? '활성' : '비활성');
      stateCell.appendChild(badge);
      row.appendChild(stateCell);

      var actions = document.createElement('td');
      actions.className = 'actions';
      var move = document.createElement('button');
      move.type = 'button';
      move.className = 'btn btn--quiet btn--small';
      setText(move, '다른 팀으로…');
      move.addEventListener('click', function () {
        openAssign(member.id, null);
      });
      actions.appendChild(move);
      row.appendChild(actions);

      membersBody.appendChild(row);
    });
    if (membersEmpty) {
      membersEmpty.hidden = members.length !== 0;
    }
  }

  function loadMembers() {
    if (state.selected === null) {
      return;
    }
    getJson('/api/org/teams/' + encodeURIComponent(String(state.selected)) + '/members')
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        renderMembers(result.data);
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  function select(id) {
    state.selected = typeof id === 'number' ? id : null;
    renderCards();
    renderDetail();
    if (membersBody) {
      membersBody.replaceChildren();
    }
    loadMembers();
  }

  function loadTeams(keepSelection) {
    return getJson('/api/org/teams')
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        state.teams = result.data && Array.isArray(result.data.teams) ? result.data.teams : [];
        if (keepSelection !== true) {
          state.selected = null;
        }
        renderCards();
        renderDetail();
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ── 권한 매트릭스 (읽기 전용) ─────────────────────────────── */

  function fillMatrix(tbody, roles, byRole, flag) {
    if (!tbody) {
      return;
    }
    tbody.replaceChildren();
    roles.forEach(function (role) {
      var row = document.createElement('tr');
      var head = document.createElement('td');
      setText(head, role);
      row.appendChild(head);
      MATRIX_LEVELS.forEach(function (level) {
        var cell = document.createElement('td');
        cell.className = 'matrix__cell';
        var entry = Object.prototype.hasOwnProperty.call(byRole[role], level) ? byRole[role][level] : null;
        if (entry !== null && entry[flag] === true) {
          cell.appendChild(makeIcon('check'));
          cell.setAttribute('title', '허용');
        } else {
          setText(cell, '—');
          cell.setAttribute('title', '허용 안 됨');
        }
        row.appendChild(cell);
      });
      tbody.appendChild(row);
    });
  }

  function renderMatrix(data) {
    var readBody = document.getElementById('org-matrix-read');
    var uploadBody = document.getElementById('org-matrix-upload');
    var rulesBox = document.getElementById('org-matrix-rules');

    var rows = data && Array.isArray(data.permissions) ? data.permissions : [];
    var byRole = Object.create(null);
    var roles = [];      // 서버가 보낸 차례 그대로 (화면이 역할 순서를 정하지 않는다)
    rows.forEach(function (entry) {
      if (!entry || typeof entry.role !== 'string' || typeof entry.access_level !== 'string') {
        return;
      }
      if (!Object.prototype.hasOwnProperty.call(byRole, entry.role)) {
        byRole[entry.role] = Object.create(null);
        roles.push(entry.role);
      }
      byRole[entry.role][entry.access_level] = entry;
    });

    fillMatrix(readBody, roles, byRole, 'can_read');
    fillMatrix(uploadBody, roles, byRole, 'can_upload');

    if (rulesBox) {
      rulesBox.replaceChildren();
      var rules = data && Array.isArray(data.rules) ? data.rules : [];
      rules.forEach(function (text) {
        var item = document.createElement('li');
        setText(item, text);
        rulesBox.appendChild(item);
      });
    }
  }

  function loadMatrix() {
    getJson('/api/org/permissions')
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        renderMatrix(result.data);
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ── 배정 대화상자 — 계정 목록과 팀 목록을 채운다 ──────────── */

  /** 계정 <select> 를 기존 /api/users 응답으로 채운다(계정 관리 화면과 같은 자료). */
  function fillUserOptions(preselectId) {
    if (!assignUser) {
      return;
    }
    assignUser.replaceChildren();
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.disabled = true;
    placeholder.selected = true;
    setText(placeholder, '계정을 고르세요');
    assignUser.appendChild(placeholder);

    function addPage(pageNo) {
      return getJson('/api/users?page=' + encodeURIComponent(String(pageNo))).then(function (result) {
        if (result === null || !result.ok) {
          return null;
        }
        var data = result.data;
        var users = data && Array.isArray(data.users) ? data.users : [];
        users.forEach(function (item) {
          var option = document.createElement('option');
          option.value = String(item.id);
          var label = item.username + ' · ' + item.role;
          if (typeof item.team_name === 'string' && item.team_name !== '') {
            label += ' · ' + item.team_name;
          }
          if (item.is_active !== true) {
            label += ' (비활성)';
          }
          setText(option, label);
          if (typeof preselectId === 'number' && item.id === preselectId) {
            option.selected = true;
          }
          assignUser.appendChild(option);
        });
        var perPage = typeof data.per_page === 'number' && data.per_page > 0 ? data.per_page : 50;
        var total = typeof data.total === 'number' ? data.total : 0;
        if (pageNo * perPage < total && pageNo < 20) {
          return addPage(pageNo + 1);
        }
        return null;
      });
    }

    addPage(1).catch(function () {
      setText(assignMsg, GENERIC_ERROR);
    });
  }

  /** 팀 <select> — **활성 팀만** 담는다. 비활성 팀으로의 배정은 서버가 400 으로 막는다. */
  function fillTeamOptions(preselectId) {
    if (!assignTeam) {
      return;
    }
    assignTeam.replaceChildren();
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.disabled = true;
    placeholder.selected = true;
    setText(placeholder, '팀을 고르세요');
    assignTeam.appendChild(placeholder);

    state.teams.forEach(function (team) {
      if (team.is_active !== true) {
        return;
      }
      var option = document.createElement('option');
      option.value = String(team.id);
      setText(option, team.name);
      if (typeof preselectId === 'number' && team.id === preselectId) {
        option.selected = true;
      }
      assignTeam.appendChild(option);
    });
  }

  function openAssign(userId, teamId) {
    setText(assignMsg, '');
    if (assignReason) {
      assignReason.value = '';
    }
    fillUserOptions(typeof userId === 'number' ? userId : null);
    fillTeamOptions(typeof teamId === 'number' ? teamId : null);
    openDialog(assignDialog);
  }

  /* ── 단추 ──────────────────────────────────────────────────── */

  if (newButton) {
    newButton.addEventListener('click', function () {
      setText(createMsg, '');
      if (createName) {
        createName.value = '';
      }
      if (createReason) {
        createReason.value = '';
      }
      openDialog(createDialog);
    });
  }

  if (renameButton) {
    renameButton.addEventListener('click', function () {
      var team = selectedTeam();
      if (team === null) {
        return;
      }
      setText(renameMsg, '');
      setText(renameTarget, '지금 이름: ' + team.name);
      if (renameName) {
        renameName.value = team.name;
      }
      if (renameReason) {
        renameReason.value = '';
      }
      openDialog(renameDialog);
    });
  }

  if (assignButton) {
    assignButton.addEventListener('click', function () {
      var team = selectedTeam();
      if (team === null) {
        return;
      }
      openAssign(null, team.id);
    });
  }

  if (toggleButton) {
    toggleButton.addEventListener('click', function () {
      var team = selectedTeam();
      if (team === null) {
        return;
      }
      setText(activeMsg, '');
      if (activeReason) {
        activeReason.value = '';
      }
      if (team.is_active === true) {
        setText(activeTitle, '팀 비활성화');
        setText(activeNote, '「' + team.name + '」 을(를) 비활성화합니다. 비활성 팀에는 새 배정·새 업로드가 막힙니다.'
          + ' 활성 구성원이 0명일 때만 됩니다 — 지금 활성 구성원 ' + team.member_count + '명.');
        setText(activeSubmit, '비활성화');
      } else {
        setText(activeTitle, '팀 활성화');
        setText(activeNote, '「' + team.name + '」 을(를) 다시 활성화합니다. 배정·업로드 선택지에 다시 나타납니다.');
        setText(activeSubmit, '활성화');
      }
      openDialog(activeDialog);
    });
  }

  if (createCancel) {
    createCancel.addEventListener('click', function () {
      closeDialog(createDialog);
    });
  }
  if (renameCancel) {
    renameCancel.addEventListener('click', function () {
      closeDialog(renameDialog);
    });
  }
  if (assignCancel) {
    assignCancel.addEventListener('click', function () {
      closeDialog(assignDialog);
    });
  }
  if (activeCancel) {
    activeCancel.addEventListener('click', function () {
      closeDialog(activeDialog);
    });
  }

  /* ── 제출 ──────────────────────────────────────────────────── */

  if (createForm) {
    createForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(createMsg, '');
      sendJson('POST', '/api/org/teams', {
        name: createName.value,
        reason_text: createReason.value
      })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (result.status !== 201) {
            setText(createMsg, errorMessage(result.data));
            return null;
          }
          closeDialog(createDialog);
          setText(msg, '팀을 만들었습니다.');
          return loadTeams(true);
        })
        .catch(function () {
          setText(createMsg, GENERIC_ERROR);
        });
    });
  }

  if (renameForm) {
    renameForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var team = selectedTeam();
      if (team === null) {
        return;
      }
      setText(renameMsg, '');
      sendJson('PATCH', '/api/org/teams/' + encodeURIComponent(String(team.id)), {
        name: renameName.value,
        reason_text: renameReason.value
      })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            setText(renameMsg, errorMessage(result.data));
            return null;
          }
          closeDialog(renameDialog);
          setText(msg, '팀 이름을 바꿨습니다. 이전값 → 새값이 감사 로그에 남았습니다.');
          return loadTeams(true);
        })
        .catch(function () {
          setText(renameMsg, GENERIC_ERROR);
        });
    });
  }

  if (activeForm) {
    activeForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var team = selectedTeam();
      if (team === null) {
        return;
      }
      setText(activeMsg, '');
      sendJson('PATCH', '/api/org/teams/' + encodeURIComponent(String(team.id)), {
        is_active: team.is_active !== true,
        reason_text: activeReason.value
      })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            // 「구성원이 남아 있다」 같은 사유 문구도 서버가 준 것을 그대로 보여 준다.
            setText(activeMsg, errorMessage(result.data));
            return null;
          }
          closeDialog(activeDialog);
          setText(msg, '팀 상태를 바꿨습니다.');
          return loadTeams(true).then(function () {
            renderDetail();
            return null;
          });
        })
        .catch(function () {
          setText(activeMsg, GENERIC_ERROR);
        });
    });
  }

  if (assignForm) {
    assignForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(assignMsg, '');
      var userId = assignUser ? assignUser.value : '';
      var teamId = assignTeam ? assignTeam.value : '';
      if (userId === '' || teamId === '') {
        setText(assignMsg, '계정과 팀을 모두 고르세요.');
        return;
      }
      // 배정은 **기존 계정 변경 API** 다 (ARCH 5.9). 값은 그대로 보내고 판단은 서버가 한다.
      sendJson('PATCH', '/api/users/' + encodeURIComponent(userId), {
        team_id: teamId,
        reason_text: assignReason.value
      })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            setText(assignMsg, errorMessage(result.data));
            return null;
          }
          closeDialog(assignDialog);
          setText(msg, '팀을 배정했습니다. 그 계정의 모든 기기 세션이 끊겼습니다.');
          return loadTeams(true).then(function () {
            renderDetail();
            loadMembers();
            return null;
          });
        })
        .catch(function () {
          setText(assignMsg, GENERIC_ERROR);
        });
    });
  }

  // 16-1 — 재인증 성공 뒤 팀 목록부터 다시 읽는다 (PRD F-23)
  setReauthRetry(function () {
    loadTeams(false);
    loadMatrix();
  });

  loadTeams(false);
  loadMatrix();
}

/* ─── 최초 로그인 강제 절차 화면 (11단계 — PRD F-16, ARCH 4.0) ────
 *
 * 서버가 /api/me 로 내려 준 `next` 가 있으면 홈은 **그 절차 화면만** 보여 준다.
 *   'password_change' → 비밀번호 변경 (현재·새·확인)
 *   'mfa_setup'       → 2단계 인증 등록 (「내 계정」의 등록 UI 를 그대로 옮겨 쓴다)
 *   null              → 절차 끝. 홈을 다시 열어 평소 화면을 그린다
 *
 * ★ 화면을 감추는 것은 **편의**일 뿐이다. 개발자도구로 되살려 API 를 직접 불러도
 *   라우터 관문이 403 으로 막고 감사 로그(auth.gate)에 남긴다 (PRD S-01).
 * ★ 모든 문구는 textContent 로만 넣는다(다른 화면과 같은 규칙).
 */
function initForcedGate(csrfToken, firstStep, mfaEnabled) {
  var msg = document.getElementById('msg');
  var gate = document.getElementById('forced-gate');
  var pwBox = document.getElementById('forced-password');
  var mfaBox = document.getElementById('forced-mfa');
  var pwForm = document.getElementById('forced-password-form');
  var pwCurrent = document.getElementById('forced-pw-current');
  var pwNew = document.getElementById('forced-pw-new');
  var pwConfirm = document.getElementById('forced-pw-confirm');
  var pwSubmit = document.getElementById('forced-pw-submit');
  var logoutLink = document.getElementById('forced-logout');
  var slot = document.getElementById('forced-mfa-slot');

  /** 절차 화면 말고는 전부 감춘다 (상단 머리말과 알림 줄만 남긴다) */
  function hideEverythingElse() {
    var main = document.querySelector('main');
    if (!main) {
      return;
    }
    var kids = main.children;
    for (var i = 0; i < kids.length; i++) {
      var el = kids[i];
      if (el === gate || el.id === 'msg' || el.tagName === 'HEADER') {
        continue;
      }
      el.hidden = true;
    }
  }

  /** 지금 보여 줄 걸음을 고른다. null 이면 절차가 끝났으므로 홈을 다시 연다. */
  function show(step) {
    if (step !== 'password_change' && step !== 'mfa_setup') {
      window.location.assign(HOME_PATH);
      return;
    }
    if (gate) {
      gate.hidden = false;
    }
    if (pwBox) {
      pwBox.hidden = step !== 'password_change';
    }
    if (mfaBox) {
      mfaBox.hidden = step !== 'mfa_setup';
    }
  }

  /** 서버에 "다음은 무엇인가" 를 다시 묻는다 — 판단은 언제나 서버가 한다 */
  function refresh() {
    fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (body) {
          show(typeof body.next === 'string' ? body.next : null);
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  hideEverythingElse();

  /*
   * 2단계 인증 등록 화면은 「내 계정」(`/settings`)과 **같은 파일**을 서버가 이 슬롯
   * (`#forced-mfa-slot`)에 include 해 둔 것이다 (src/views/mfa_enroll.php). 예전처럼
   * 화면 조각을 JS 가 옮겨 붙이지 않는다 — 같은 마크업이 한 벌뿐이라 옮길 것이 없다.
   * initAccount 가 그 조각에 이벤트를 붙이고, 2단계 인증이 꺼진 상태이므로 스스로 펼쳐진다.
   */
  if (slot) {
    initAccount(csrfToken, mfaEnabled, function () {
      refresh();
    });
  }

  if (pwForm) {
    pwForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(msg, '');
      if (pwNew.value !== pwConfirm.value) {
        // 서버에 보내기 전의 입력 확인이다(권한·사유 판단이 아니다).
        setText(msg, '새 비밀번호와 확인 값이 다릅니다.');
        return;
      }
      pwSubmit.disabled = true;
      fetch('/api/me/password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ current: pwCurrent.value, 'new': pwNew.value }),
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(function (response) {
          if (response.status === 401) {
            toLogin();
            return null;
          }
          return readJson(response).then(function (data) {
            pwCurrent.value = '';
            pwNew.value = '';
            pwConfirm.value = '';
            if (!response.ok) {
              // 서버가 준 문구만 띄운다(같은 값·10자 미만·현재 비밀번호 오류를 구분하지 않는다).
              setText(msg, errorMessage(data));
              return null;
            }
            setText(msg, '비밀번호를 바꿨습니다.');
            refresh();
            return null;
          });
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        })
        .then(function () {
          pwSubmit.disabled = false;
        });
    });
  }

  if (logoutLink) {
    // 로그아웃은 홈의 로그아웃 단추가 하는 일과 **똑같다** — 그 단추를 그대로 누른다.
    logoutLink.addEventListener('click', function () {
      var real = document.getElementById('logout');
      if (real) {
        real.click();
      }
    });
  }

  show(firstStep);
}

/* ─── 내 계정 화면의 「내 정보」 (12단계 F-20) ─────────────────────
 *
 * 아이디·역할은 서버가 이미 HTML 에 넣었고(공통 셸·설정 화면, htmlspecialchars),
 * 팀 이름만 /api/me 의 team_name 을 textContent 로 넣는다. 화면이 team_id 로 팀 이름을
 * 스스로 찾지 않는다 — 무엇을 보여 줄지는 서버가 정한다 (PRD S-01).
 */
/* ─── 내 활동 (15-1 — PRD F-12, ARCH 5.11) ────────────────────────
 *
 * 요청에 싣는 값은 `page` **하나**뿐이다. "누구의 활동인가" 를 화면이 정하지 않는다 —
 * 서버가 세션에서 본인을 읽어 조건절에 넣는다 (PRD S-02). 주소에 `?user_id=` 같은 값을
 * 붙여도 서버가 읽지 않으므로 결과가 달라지지 않는다 (T-33).
 *
 * 표의 값은 전부 textContent 로만 넣는다. `summary` 에는 파일명이 섞여 들어가므로
 * 다른 사용자 입력과 똑같이 다룬다 (PRD S-03, ARCH 7.4).
 */
function initActivity() {
  var msg = document.getElementById('msg');
  var body = document.getElementById('activity-body');
  var empty = document.getElementById('activity-empty');
  var pageInfo = document.getElementById('page-info');
  var prev = document.getElementById('page-prev');
  var next = document.getElementById('page-next');

  var state = { page: 1, total: 0, perPage: 20 };

  function clearRows() {
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value === null || value === undefined || value === '' ? '—' : value);
    row.appendChild(td);
    return td;
  }

  function render(data) {
    clearRows();
    var rows = data && Array.isArray(data.rows) ? data.rows : [];
    state.total = typeof data.total === 'number' ? data.total : 0;
    state.perPage = typeof data.per_page === 'number' && data.per_page > 0 ? data.per_page : 20;
    state.page = typeof data.page === 'number' && data.page > 0 ? data.page : 1;

    rows.forEach(function (item) {
      var row = document.createElement('tr');
      addCell(row, item.happened_at);
      addCell(row, item.summary);
      addCell(row, item.result);
      body.appendChild(row);
    });

    if (empty) {
      empty.hidden = rows.length !== 0;
    }

    var lastPage = state.total === 0 ? 1 : Math.ceil(state.total / state.perPage);
    setText(pageInfo, state.page + ' / ' + lastPage + ' 페이지 · 전체 ' + state.total + '건');
    if (prev) {
      prev.disabled = state.page <= 1;
    }
    if (next) {
      next.disabled = state.page >= lastPage;
    }
  }

  function load() {
    fetch('/api/me/activity?page=' + encodeURIComponent(String(state.page)), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (!response.ok) {
            setText(msg, errorMessage(data));
            return null;
          }
          setText(msg, '');
          render(data);
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  if (prev) {
    prev.addEventListener('click', function () {
      if (state.page > 1) {
        state.page -= 1;
        load();
      }
    });
  }
  if (next) {
    next.addEventListener('click', function () {
      state.page += 1;
      load();
    });
  }

  load();
}

function initSettings(me) {
  var team = document.getElementById('me-team');
  if (team) {
    setText(team, typeof me.team_name === 'string' && me.team_name !== '' ? me.team_name : '—');
  }
}

/* ─── 내 공유 링크 (15-2 — PRD F-13, ARCH 5.12 「회수·목록」) ──────────
 *
 * 목록은 /api/me/shares 가 내려 주고 **내가 만든 것만** 나온다 — 화면이 "누구의" 를 고르지
 * 않는다 (PRD S-02). 응답에 토큰도 그 해시도 없다: 만들 때 한 번 보여 준 주소는 여기서
 * 다시 볼 수 없고, 이 화면이 할 수 있는 일은 **회수**뿐이다.
 *
 * 회수 자격(만든 사람 또는 Admin)은 서버 Authz::decideShareRevoke 가 판단한다. 여기서 회수
 * 단추를 그리는 기준은 "아직 살아 있는 링크인가" 라는 **상태**일 뿐 권한이 아니다.
 */
function initShares(csrfToken) {
  var msg = document.getElementById('msg');
  var body = document.getElementById('shares-body');
  var empty = document.getElementById('shares-empty');

  if (!body) {
    return;
  }

  function clearRows() {
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value === null || value === undefined || value === '' ? '—' : value);
    row.appendChild(td);
    return td;
  }

  /** 상태 칸 — 회수됨 / 만료됨 / 다 씀 / 쓸 수 있음. 서버가 준 값만 조합한다. */
  function stateOf(link) {
    if (link.revoked_at !== null && link.revoked_at !== undefined) {
      return '회수됨';
    }
    if (link.is_expired === true) {
      return '만료됨';
    }
    if (link.download_count >= link.max_downloads) {
      return '다 씀';
    }
    return '쓸 수 있음';
  }

  function revoke(id) {
    if (typeof id !== 'number' || !isFinite(id) || id <= 0) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    setText(msg, '');
    fetch('/api/shares/' + encodeURIComponent(String(id)), {
      method: 'DELETE',
      headers: { 'X-CSRF-Token': csrfToken },
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        if (response.status === 200) {
          load();
          return null;
        }
        return readJson(response).then(function (data) {
          setText(msg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  function render(data) {
    clearRows();
    var links = data && Array.isArray(data.links) ? data.links : [];

    links.forEach(function (link) {
      var row = document.createElement('tr');
      addCell(row, link.file_name);
      addCell(row, link.expires_at);
      addCell(row, link.download_count + ' / ' + link.max_downloads);
      addCell(row, stateOf(link));
      addCell(row, link.created_at);
      addCell(row, link.reason_text);

      var actions = document.createElement('td');
      actions.className = 'actions';
      // 이미 회수된 링크에는 단추를 만들지 않는다(서버도 400 으로 막는다 — 표시는 편의).
      if (link.revoked_at === null || link.revoked_at === undefined) {
        var button = makeIconButton('x', '회수');
        button.addEventListener('click', function () {
          revoke(link.id);
        });
        actions.appendChild(button);
      }
      row.appendChild(actions);

      body.appendChild(row);
    });

    if (empty) {
      empty.hidden = links.length !== 0;
    }
  }

  function load() {
    fetch('/api/me/shares', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (!response.ok) {
            setText(msg, errorMessage(data));
            return null;
          }
          render(data);
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  load();
}

/* ─── 공통 셸 (12단계 F-20 — PRD 10장 「공통 셸」) ─────────────────
 *
 * 모든 화면이 공유하는 좌측 내비·사용자 칩·로그아웃·좁은 화면 메뉴를 맡는다.
 *
 * ★ 내비 항목은 서버 HTML 에서 전부 `hidden` 으로 나가고, 여기서 여는 근거는 오직
 *   /api/me 의 can_* 플래그뿐이다. 이 파일은 역할 문자열을 비교하지 않는다 (PRD S-01).
 *   항목을 개발자도구로 되살려도 /trash·/users·/org·/audit 은 라우트 정책이 다시 막고
 *   그 시도가 authz.route|deny 로 감사 로그에 남는다 — 표시는 편의, 근거는 라우트 정책이다.
 */
function initShell(me, csrfToken) {
  var msg = document.getElementById('msg');
  var sidebar = document.getElementById('sidebar');
  var navToggle = document.getElementById('nav-toggle');
  var logout = document.getElementById('logout');

  /** 최초 로그인 강제 절차가 남았나 (있으면 다른 화면으로 갈 길을 접어 둔다) */
  var inGate = typeof me.next === 'string' && me.next !== '';

  var canTrash = me.can_view_trash === true;
  var canUsers = me.can_manage_users === true;
  var canAudit = me.can_view_audit === true;
  // 12-3 — 「조직·권한」은 /api/me 의 can_manage_org 가 참일 때만 열린다 (F-19).
  // 다른 can_* 와 같은 **표시용** 값이다. 항목을 개발자도구로 되살려 /org 나 /api/org/* 를
  // 직접 불러도 라우트 정책 org.manage 가 막고 authz.route|deny 로 남는다 (PRD S-01, T-27).
  var canOrg = me.can_manage_org === true;
  // 14단계 — 「위키」는 /api/me 의 can_view_wiki 가 참일 때만 열린다 (F-21, Admin 만).
  // 다른 can_* 와 같은 **표시용** 값이다. 항목을 개발자도구로 되살려 /wiki 나
  // /wiki/{slug} 를 직접 불러도 라우트 정책 role:Admin 이 막는다 (PRD S-01, T-32).
  var canWiki = me.can_view_wiki === true;

  function showNav(id, open) {
    var el = document.getElementById(id);
    if (el) {
      el.hidden = inGate ? true : !open;
    }
  }

  showNav('nav-files', true);
  showNav('nav-settings', true);
  // 15-1 — 「내 활동」은 모든 로그인 사용자에게 열린다(권한 플래그가 없다, PRD F-12).
  // 강제 절차(F-16) 중에는 다른 항목과 함께 닫힌다 — showNav 가 inGate 를 먼저 본다.
  showNav('nav-activity', true);
  showNav('nav-trash', canTrash);
  showNav('nav-users', canUsers);
  showNav('nav-org', canOrg);
  showNav('nav-audit', canAudit);
  showNav('nav-wiki', canWiki);

  // 「관리」 묶음은 그 안에 열린 항목이 하나라도 있을 때만 제목째 보인다.
  var adminGroup = document.getElementById('nav-admin-group');
  if (adminGroup) {
    adminGroup.hidden = inGate || !(canUsers || canOrg || canAudit || canWiki);
  }

  /* 좁은 화면(960px 미만)의 접이식 내비. 넓은 화면에서는 CSS 가 내비를 늘 펼쳐 두므로
     여기서 hidden 을 켜 두어도 영향이 없다 (app.css 의 min-width: 960px 규칙). */
  if (sidebar && navToggle) {
    sidebar.hidden = true;
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.addEventListener('click', function () {
      var open = sidebar.hidden === true;
      sidebar.hidden = !open;
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  if (logout) {
    logout.disabled = false;
    logout.addEventListener('click', function () {
      if (csrfToken === null) {
        setText(msg, GENERIC_ERROR);
        return;
      }
      logout.disabled = true;
      fetch('/api/logout', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(function (response) {
          if (response.status === 200 || response.status === 401) {
            toLogin();
            return null;
          }
          return readJson(response).then(function (body) {
            setText(msg, errorMessage(body));
            logout.disabled = false;
            return null;
          });
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
          logout.disabled = false;
        });
    });
  }
}

/* ─── 화면별 시작 (12단계 F-20) ────────────────────────────────────
 *
 * 어느 화면인지는 서버가 <body data-page="…"> 로 알려 준다. 화면 코드가 URL 을 읽어
 * 스스로 판단하지 않는다. 무엇을 그릴 수 있는지는 여전히 서버가 정하고(라우트 정책 ·
 * /api/me 의 can_*), 여기 분기는 **어떤 함수를 부를지**만 고른다.
 */
function bootPage(page) {
  var msg = document.getElementById('msg');

  fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
    .then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      if (!response.ok) {
        return null;
      }
      return readJson(response).then(function (me) {
        if (typeof me.csrf_token !== 'string') {
          return null;
        }
        var csrfToken = me.csrf_token;
        // 16-1 — 재인증 모달이 쓸 CSRF 토큰 (PRD F-23). 모달은 어느 화면에서든 열릴 수 있어
        // 토큰을 한 곳에 둔다. 토큰은 세션에 결속돼 있어 다른 세션에서는 쓸 수 없다 (ARCH 4.0).
        reauthCsrf = csrfToken;
        var next = typeof me.next === 'string' && me.next !== '' ? me.next : null;

        /*
         * 최초 로그인 강제 절차(F-16)가 남았는데 파일 화면이 아니면 `/` 로 보낸다.
         * 서버 관문도 화면 요청을 302 로 돌려보내지만, 이미 열려 있는 창을 맞춰 주는 것은
         * 화면의 몫이다. **막는 것은 서버**이고 이 줄은 안내일 뿐이다 (PRD S-01).
         */
        if (next !== null && page !== 'files') {
          window.location.replace(HOME_PATH);
          return null;
        }

        initShell(me, csrfToken);

        if (page === 'files') {
          if (next !== null && document.getElementById('forced-gate')) {
            initForcedGate(csrfToken, next, me.mfa_enabled === true);
            return null;
          }
          if (document.getElementById('files-body')) {
            initFiles(
              csrfToken,
              Array.isArray(me.upload_levels) ? me.upload_levels : [],
              me.can_fetch === true,
              // team_id 는 레벨 기본값(team)·팀 선택 기본값에만 쓰는 화면 편의 값이다 (PRD S-01).
              typeof me.team_id === 'number' || typeof me.team_id === 'string' ? me.team_id : null
            );
          }
          return null;
        }
        if (page === 'trash') {
          // 이 화면에 들어왔다는 것 자체가 라우트 정책 role:Admin 을 통과했다는 뜻이다.
          if (document.getElementById('trash-body')) {
            initTrash(csrfToken);
          }
          return null;
        }
        if (page === 'settings') {
          initSettings(me);
          if (document.getElementById('password-form')) {
            initAccount(csrfToken, me.mfa_enabled === true);
          }
          // 15-2 — 「내 공유 링크」. 로그인한 사람이면 누구나 자기 링크를 본다 (PRD F-13).
          initShares(csrfToken);
          return null;
        }
        if (page === 'users') {
          // 라우트 정책 user.manage 를 통과한 화면이다 (Admin 만).
          if (document.getElementById('users-body')) {
            initUsers(csrfToken);
          }
          return null;
        }
        if (page === 'org') {
          // 라우트 정책 org.manage 를 통과한 화면이다 (Admin 만, 12-3 F-19).
          if (document.getElementById('org-teams')) {
            initOrg(csrfToken);
          }
          return null;
        }
        if (page === 'audit') {
          // 라우트 정책 audit.read 를 통과한 화면이다 (Admin 만).
          if (document.getElementById('audit-body')) {
            initAudit();
          }
          return null;
        }
        if (page === 'activity') {
          // 15-1 — 로그인한 사람이면 누구나 들어오는 화면이다. 보이는 내용은 서버가
          // 세션 본인으로 고정한다 (PRD F-12, ARCH 5.11).
          if (document.getElementById('activity-body')) {
            initActivity();
          }
          return null;
        }
        return null;
      });
    })
    .catch(function () {
      setText(msg, GENERIC_ERROR);
    });
}

document.addEventListener('DOMContentLoaded', function () {
  // 서버 HTML 의 <template> 아이콘을 [data-icon] 자리에 붙인다 (문자열 → HTML 변환 0).
  paintIcons(document);

  var page = document.body && document.body.dataset ? document.body.dataset.page : '';

  if (page === 'login') {
    var form = document.getElementById('login-form');
    if (form) {
      initLogin(form);
    }
    return;
  }
  /*
   * 16-1 재인증 화면 (`/reauth`, PRD F-23). 공통 셸을 쓰지만 셸의 화면 키 목록에 없는
   * 화면이라 <body data-page> 는 비어 있다 — 그래서 **폼이 있는지**로 알아본다
   * (로그인 화면과 같은 방식). 셸(내비·로그아웃)은 다른 화면과 똑같이 initShell 이 그린다.
   */
  var reauthForm = document.getElementById('reauth-form');
  if (reauthForm) {
    fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (me) {
          if (typeof me.csrf_token !== 'string') {
            return null;
          }
          reauthCsrf = me.csrf_token;
          initShell(me, me.csrf_token);
          initReauthPage(reauthForm, me.csrf_token);
          return null;
        });
      })
      .catch(function () {
        setText(document.getElementById('reauth-msg'), GENERIC_ERROR);
      });
    return;
  }
  if (typeof page !== 'string' || page === '') {
    return;
  }
  bootPage(page);
});
