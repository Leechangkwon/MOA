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

/**
 * Qaredi C — t-box 모듈의 주소 접두 (../docs/launcher/ARCHITECTURE.md 3장).
 *
 * 서버가 <body data-base="/tbox"> 로 알려 준다. 이 파일의 **모든** 절대 경로는 이 값 뒤에
 * 붙는다 — 주소를 만드는 자리가 한 곳이라, 접두가 바뀌어도 고칠 곳이 여기 하나다.
 * 값은 서버가 그린 고정 문자열이고 사용자가 고칠 수 있는 입력이 아니다(요청 값 아님).
 * 속성이 없으면(옛 캐시된 HTML) 서버 상수와 같은 기본값을 쓴다.
 */
var APP_BASE = (document.body && document.body.getAttribute('data-base')) || '/tbox';

var LOGIN_PATH = APP_BASE + '/login';
var HOME_PATH = APP_BASE + '/';
var REAUTH_PATH = APP_BASE + '/reauth';
var GENERIC_ERROR = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';

/**
 * 커뮤니티 모듈의 주소 접두 (커뮤니티 ARCHITECTURE.md 21.1).
 *
 * 서버가 <body data-community-base="/community"> 로 알려 준다. 이 파일이 이 값을 쓰는 자리는
 * **로그인 성공 뒤 어디로 가나** 하나뿐이다 — 커뮤니티 화면의 동작은 전부
 * `assets/community/editor.js` 가 맡는다.
 */
var COMMUNITY_BASE = (document.body && document.body.getAttribute('data-community-base')) || '/community';

/**
 * 로그인 화면의 `?to=` **키 → 갈 곳** 고정 맵 (커뮤니티 ARCHITECTURE.md 21.2).
 *
 * 서버(`src/handlers/login_page.php`)가 허용 목록으로 거른 키만 <body data-to> 에 넣고,
 * 화면은 키가 이 맵에 있을 때만 **맵의 값**으로 이동한다 — 주소 문자열을 요청 값이나
 * data 속성에서 만들지 않는다(재인증 REAUTH_PATHS 와 같은 방식, 오픈 리다이렉트 0).
 */
var LOGIN_TO_PATHS = {
  'tbox': HOME_PATH,
  'community': COMMUNITY_BASE + '/'
};

/**
 * 16-1 재인증(sudo 모드) — 돌아갈 화면의 **키 → 경로** 고정 맵 (PRD F-23).
 *
 * 서버(public/index.php 의 TBOX_REAUTH_PATHS)와 **같은 내용**의 표다. `/reauth` 화면의
 * <form data-to="…"> 에는 서버가 이 표의 키(users·org·audit·trash·wiki)만 넣어 주고,
 * 화면은 키가 이 맵에 있을 때만 그 **맵의 값**으로 이동한다. 주소 문자열을 요청 값이나
 * data 속성에서 만들지 않는다 — 오픈 리다이렉트가 생길 자리가 없다.
 */
var REAUTH_PATHS = {
  'users': APP_BASE + '/users',
  'org': APP_BASE + '/org',
  'audit': APP_BASE + '/audit',
  'trash': APP_BASE + '/trash',
  'wiki': APP_BASE + '/wiki',
  // 17-1 관리자 허브 (PRD F-24). `/admin`·`/admin/permissions` 둘 다 이 키 하나를 쓰고,
  // 확인이 끝나면 허브로 돌아온다 (서버 TBOX_REAUTH_RETURN 과 같은 내용).
  'admin': APP_BASE + '/admin',
  // F-70 — 휴대폰 경보 화면(설치한 앱의 시작 화면)은 확인 뒤 그 화면으로 돌아온다.
  'alerts': APP_BASE + '/alerts-app'
};

/* ─── Q:F-7 B1-1 「로그인하면 보던 곳으로」 — 서버 `Http::returnPath()` 의 **짝** (T:F-72 라운드 A) ─────────
 *
 * 규칙은 서버와 글자 그대로 같다(허용 목록 · 길이 600바이트 · 퍼센트 디코딩 한 번 · 제어 문자·`\`·`//`·`..`·`@`·스킴 거부 ·
 * 정규형 = 경로 + 고정 순서의 허용 쿼리, 값은 rawurlencode). 두 쪽을 `tests/lib/return_path_vectors.txt` 한 벌로 시험한다.
 * 이 함수가 하는 일은 **이동할 곳을 거르는 것**뿐이다 — 로그인 화면의 `data-to`(서버가 이미 거른 값)를 한 번 더 걸러,
 * 속성을 개발자도구로 고쳐도 목록 밖으로는 못 간다. 목록 밖은 첫 화면이다(오류 화면 아님).
 */
var RETURN_PATH_MAX = 600;
var RETURN_UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
var RETURN_RULES = [
  [/^\/tbox\/$/, [['folder', /^[1-9][0-9]{0,8}$/], ['q', 'text200'], ['sort', /^(name|created_at|size)$/],
    ['dir', /^(asc|desc)$/], ['page', /^[1-9][0-9]{0,5}$/]]],
  [/^\/tbox\/(settings|activity)$/, []],
  [/^\/community\/$/, [['space', /^[1-9][0-9]{0,17}$/]]],
  [new RegExp('^/community/p/' + RETURN_UUID + '$'), [['edit', /^1$/]]],
  [/^\/community\/b\/[1-9][0-9]{0,8}$/, []]
];

/** PHP rawurlencode 와 같은 글자 — 영숫자·`-_.~` 밖은 %XX(대문자) */
function rawEncode(s) {
  return encodeURIComponent(s).replace(/[!'()*]/g, function (c) {
    return '%' + c.charCodeAt(0).toString(16).toUpperCase();
  });
}

/** PHP rawurldecode 와 같은 한 번 — %XX 만 바이트로(`+` 는 그대로), 결과가 UTF-8 이 아니면 null */
function rawDecodeOnce(s) {
  var bytes;
  try {
    bytes = new TextEncoder().encode(s);
  } catch (e) {
    return null;
  }
  var out = [];
  var hex = /^[0-9A-Fa-f]{2}$/;
  for (var i = 0; i < bytes.length; i++) {
    if (bytes[i] === 0x25 && i + 2 < bytes.length
        && hex.test(String.fromCharCode(bytes[i + 1], bytes[i + 2]))) {
      out.push(parseInt(String.fromCharCode(bytes[i + 1], bytes[i + 2]), 16));
      i += 2;
    } else {
      out.push(bytes[i]);
    }
  }
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(new Uint8Array(out));
  } catch (e) {
    return null;
  }
}

function returnPath(raw) {
  if (typeof raw !== 'string' || raw === '') {
    return null;
  }
  var byteLen;
  try {
    byteLen = new TextEncoder().encode(raw).length;
  } catch (e) {
    return null;
  }
  if (byteLen > RETURN_PATH_MAX) {
    return null;
  }
  var dec = rawDecodeOnce(raw);
  if (dec === null
      || /[\x00-\x1f\x7f\\@]/.test(dec)
      || dec.indexOf('//') !== -1
      || dec.indexOf('..') !== -1
      || /^[A-Za-z][A-Za-z0-9+.-]*:/.test(dec)
      || /[/?&=][A-Za-z][A-Za-z0-9+.-]*:/.test(dec)) {
    return null;
  }
  var q = raw.indexOf('?');
  var path = q === -1 ? raw : raw.slice(0, q);
  var query = q === -1 ? null : raw.slice(q + 1);
  var allowed = null;
  for (var r = 0; r < RETURN_RULES.length; r++) {
    if (RETURN_RULES[r][0].test(path)) {
      allowed = RETURN_RULES[r][1];
      break;
    }
  }
  if (allowed === null) {
    return null;
  }
  if (query === null) {
    return path;
  }
  if (query === '' || allowed.length === 0) {
    return null;
  }
  var got = Object.create(null);
  var pairs = query.split('&');
  for (var p = 0; p < pairs.length; p++) {
    var eq = pairs[p].indexOf('=');
    if (eq === -1) {
      return null;
    }
    var key = pairs[p].slice(0, eq);
    var val = rawDecodeOnce(pairs[p].slice(eq + 1));
    var rule = null;
    for (var k = 0; k < allowed.length; k++) {
      if (allowed[k][0] === key) {
        rule = allowed[k][1];
      }
    }
    if (rule === null || key in got || val === null || val === '') {
      return null;
    }
    if (rule === 'text200') {
      var cps = Array.from(val).length;
      if (cps < 1 || cps > 200 || /^ *$/.test(val)) {
        return null;
      }
    } else if (!rule.test(val)) {
      return null;
    }
    got[key] = val;
  }
  var parts = [];
  for (var a = 0; a < allowed.length; a++) {
    if (allowed[a][0] in got) {
      parts.push(allowed[a][0] + '=' + rawEncode(got[allowed[a][0]]));
    }
  }
  return path + '?' + parts.join('&');
}

/**
 * 로그인에 성공한 뒤 어디로 가나 — **비밀번호 로그인과 지문·얼굴 로그인이 같이 부르는 한 함수**(T:F-72 1.1 「원래 가던 곳으로」).
 * `data-to` 가 옛 키(tbox·community)면 고정 맵, 경로면 `returnPath` 로 한 번 더 거른 값, 그 밖은 첫 화면.
 */
function loginDestination() {
  var to = document.body ? document.body.getAttribute('data-to') : null;
  if (to !== null && Object.prototype.hasOwnProperty.call(LOGIN_TO_PATHS, to)) {
    return LOGIN_TO_PATHS[to];
  }
  var rp = to === null ? null : returnPath(to);
  return rp !== null ? rp : HOME_PATH;
}

/* 지문·얼굴 로그인(passkey.js)·편집기(editor.js)도 같은 거르기와 같은 이동을 쓴다 — 이름만 내놓는다 */
window.qarediReturnPath = returnPath;
window.qarediRawEncode = rawEncode;
window.qarediLoginGo = function () {
  window.location.assign(loginDestination());
};
/* T:F-72 라운드 C — `/reauth` 화면의 지문·얼굴 확인(passkey.js)이 성공 뒤 갈 곳: 위 고정 맵의 값만(키가 없으면 첫 화면) */
window.qarediReauthTarget = function (key) {
  return typeof key === 'string' && Object.prototype.hasOwnProperty.call(REAUTH_PATHS, key) ? REAUTH_PATHS[key] : HOME_PATH;
};

/** 서버가 내려 준 파일 ID 형태 (UUIDv4). 링크를 만들기 전에 반드시 확인한다. */
var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/**
 * 업로드 레벨의 **화면 문구**. 어떤 레벨을 고를 수 있는지는 서버(/api/me 의 upload_levels)가
 * 정하고, 여기 표는 그 값에 붙일 한국어 설명일 뿐이다. 표에 없는 값이 오면 만들지 않는다
 * (서버가 보낸 문자열이 그대로 <option value> 가 되지 않게 하는 방어).
 */
/* 디버깅 묶음 U2(2026-09-26) — 고르개 글자는 **한글 이름만**(값은 그대로). 전에는 「internal — 사내 전체」처럼 영어 값이 앞에 보였다.
   배지 이름(공개·사내·팀·관리자) + 누가 보는지를 짧게 — 폰 폭(360) 고르개 칸에서 잘리지 않는 길이. 커뮤니티 게시판 등급 고르개
   (board_view.php)도 같은 글자다(DESIGN 5장 낱말 표). */
var LEVEL_LABELS = {
  'public': '공개 — 손님까지 모두',
  'internal': '사내 — 사내 구성원 모두',
  'team': '팀 — 고른 팀만',
  'admin-only': '관리자 — 관리자만'
};

/** 아직 아무것도 고르지 않았을 때의 자리표시 문구 (사용자 피드백 ②) */
var LEVEL_PLACEHOLDER = '등급을 고르세요';

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

/**
 * 5-A″ — 사람을 보이는 모양 **한 벌**: 「이름 (아이디)」, 이름이 없으면 「아이디」.
 * 창구는 `display_name` 과 `username` 을 **따로** 주고, 잇는 일은 화면에서 이 함수 하나만 한다
 * (서버가 그리는 셸은 PHP `tbox_person_label` — 같은 모양). 돌려주는 것은 **글자**이고
 * 넣는 쪽은 언제나 `textContent`(setText)다 — `<script>` 라는 이름도 글자 그대로 보인다 (PRD S-03).
 * 커뮤니티 모듈 스크립트(editor.js)도 이 함수를 쓴다 — 아래 `window.qarediPersonLabel`.
 */
function personLabel(displayName, username) {
  var id = username === null || username === undefined ? '' : String(username);
  var name = typeof displayName === 'string' ? displayName.trim() : '';
  return name === '' ? id : name + ' (' + id + ')';
}
window.qarediPersonLabel = personLabel;

/* DESIGN.md 2.4 · 5장 — 시각은 「YYYY-MM-DD HH:MM:SS」 까지만 보이고 마이크로초·시간대는 title 로 옮긴다.
   서버 값(PostgreSQL timestamptz 문자열, 예 2026-09-21 14:27:21.850281+09)은 바꾸지 않는다 — 표시만 자른다.
   형식이 다르면(빈 값·'—'·이미 짧은 값·날짜만) 그대로 둔다. */
var WHEN_RE = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/;
function shortWhen(value) {
  if (typeof value !== 'string') { return { text: '—', full: '' }; }
  var m = value.match(WHEN_RE);   // String#match 로만 본다 — security_lint 의 코드 실행 함수 규칙(S-09)에 걸리는 이름을 쓰지 않는다
  if (!m) { return { text: value === '' ? '—' : value, full: '' }; }
  var text = m[1] + ' ' + m[2];
  return { text: text, full: value.length > text.length ? value : '' };
}
/* 표 칸 하나 — 글자는 짧게, title 에 전체 값. suffix 는 「 (만료)」 같은 꼬리(없으면 생략).
   날짜와 시각은 **두 조각**(`.nowrap` 둘 + 빈칸 하나)으로 넣는다 — 좁은 칸에서 날짜의 하이픈이 줄바꿈 자리가 되어
   「2026-」 / 「09-26」 / 「11:54:07」 세 줄로 갈라졌다(디버깅 묶음 U M2 · 2026-09-26). 이제 많아야 날짜 줄 · 시각 줄 두 줄이다.
   글자(textContent)는 전과 같은 「YYYY-MM-DD HH:MM:SS」 — 조각은 요소로 만들고 글자는 setText 로만 넣는다(PRD S-03). */
function addWhenCell(row, value, suffix) {
  var td = document.createElement('td');
  var w = shortWhen(value);
  var tail = typeof suffix === 'string' ? suffix : '';
  var m = w.full !== '' || WHEN_RE.test(w.text) ? w.text.match(/^(\S+) (\S+)$/) : null;
  if (m) {
    var day = document.createElement('span');
    day.className = 'nowrap';
    setText(day, m[1]);
    var clock = document.createElement('span');
    clock.className = 'nowrap';
    setText(clock, m[2] + tail);
    td.appendChild(day);
    td.appendChild(document.createTextNode(' '));
    td.appendChild(clock);
  } else {
    setText(td, w.text + tail);
  }
  if (w.full !== '') { td.setAttribute('title', w.full); }
  row.appendChild(td);
  return td;
}
/* 표 밖 글자(모달 정보 판·카드 메타)용 */
function setWhen(el, value) {
  var w = shortWhen(value);
  setText(el, w.text);
  if (w.full !== '') { el.setAttribute('title', w.full); } else { el.removeAttribute('title'); }
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
 * **한글 이름**을 aria-label 에 넣는다 — 화면 낭독기가 읽고, CSS 툴팁(DESIGN 2.8)이
 * `attr(aria-label)` 로 같은 글을 보인다. title 은 넣지 않는다(D-3): 브라우저 기본
 * 툴팁과 CSS 툴팁이 겹쳐 같은 글이 두 번 떴다.
 */
function makeIconButton(iconName, label) {
  var button = document.createElement('button');
  button.type = 'button';
  button.className = 'btn btn--icon';
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
 * 역할의 **화면 문구** (DESIGN 5장 「등급·역할」 — 한글로 보이고 원래 값은 title, 디버깅 묶음 U M7 · 2026-09-26).
 * 값(창구가 준 `role`·보내는 값·<option value>)은 **그대로**다 — 보이는 글자만 바꾼다. 표에 없는 값은 받은 글자 그대로.
 * 서버가 그리는 셸·런처·내 계정은 PHP 쪽에 같은 표가 있다(`shell_top.php` 등). 역할을 **비교하는** 코드가 아니다 — 글자 바꾸기뿐이다.
 * 커뮤니티 모듈(editor.js)도 이 함수를 쓴다 — `window.qarediRoleLabel`.
 */
var ROLE_LABELS = {
  'Admin': '관리자',
  'Manager': '팀장',
  'Employee': '직원',
  'Guest': '손님'
};
function roleLabel(role) {
  var raw = role === null || role === undefined ? '' : String(role);
  return Object.prototype.hasOwnProperty.call(ROLE_LABELS, raw) ? ROLE_LABELS[raw] : raw;
}
window.qarediRoleLabel = roleLabel;
/** 역할 칸 하나 — 한글 글자 + title 에 원래 값 */
function addRoleCell(row, role) {
  var td = document.createElement('td');
  setText(td, roleLabel(role));
  if (typeof role === 'string' && role !== '') { td.setAttribute('title', role); }
  row.appendChild(td);
  return td;
}
/** 역할 고르개의 글자 — 값이 역할이고 글자가 그 값 그대로인 <option> 만 「직원」 으로(값은 그대로).
    디버깅 묶음 U2(2026-09-26) — 고르개 글자는 한글 이름만(등급 고르개와 같은 규칙, DESIGN 5장). 전에는 「직원 (Employee)」 */
function relabelRoleOptions(root) {
  Array.prototype.forEach.call((root || document).querySelectorAll('select option'), function (option) {
    var v = option.value;
    if (Object.prototype.hasOwnProperty.call(ROLE_LABELS, v) && String(option.textContent).trim() === v) {
      setText(option, ROLE_LABELS[v]);
    }
  });
}

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
  if (typeof value !== 'string' || value.indexOf(APP_BASE + '/api/files/') !== 0) {
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
/*
 * F-54 (PRD F-54 ③, ARCH 5.28) — 악성코드 검사 상태의 화면 문구.
 *
 * 화면은 **서버가 준 문자열**만 본다. 이 표에 없는 값('clean' 포함)은 배지가 없다 —
 * 서버 문자열이 class 이름이 되지 않게 하는 방어는 레벨 배지와 같다.
 * 배지는 표시일 뿐이고, 실제 차단은 서버의 403 이다 (PRD 10장).
 */
var SCAN_BADGE = {
  'pending': {
    label: '검사 중',
    cls: 'badge badge--scan-pending',
    title: '악성코드 검사 중입니다. 잠시 뒤 다시 시도하세요'
  },
  'infected': {
    label: '격리됨',
    cls: 'badge badge--scan-infected',
    title: '악성코드가 발견되어 격리된 파일입니다'
  }
};

/** 서버가 준 scan_status 에 붙일 배지 정보. 모르는 값·'clean' 이면 null. */
function scanBadgeFor(scanStatus) {
  if (typeof scanStatus !== 'string') {
    return null;
  }
  return Object.prototype.hasOwnProperty.call(SCAN_BADGE, scanStatus) ? SCAN_BADGE[scanStatus] : null;
}

/*
 * F-71 ④ (2026-09-24) — 「누가 일으켰나」 배지. 화면은 서버가 판정한 `origin`·`origin_hint`
 * **두 값만** 본다(판정은 src/Origin.php 한 곳 — 계정 qa_·claude_ **그리고** UA tbox-review/).
 * 계정 이름·UA 를 여기서 다시 보지 않는다. 보여 주기 전용이고 권한과 무관하다.
 * 색은 DESIGN 2.2 의 뜻대로 기존 변형만 쓴다 — 「Claude」 파랑(정보), 「확인 필요」 흰 바탕(아직 모른다).
 * F-69 의 경보 목록도 같은 표를 쓰도록 함수 하나로 둔다.
 *
 * 돌려주는 것: `claude-*` → 「Claude」, partial → 「확인 필요」, 그 밖 null.
 * DESIGN 2.2 규칙대로 한글 라벨을 보이고 원래 값(origin·origin_hint)은 title 앞머리에 둔다.
 */
function originBadgeFor(origin, hint) {
  if (typeof origin === 'string' && origin.indexOf('claude-') === 0) {
    return {
      label: 'Claude',
      cls: 'badge badge--internal',
      title: origin + ' — 검토 계정과 검토 표식(User-Agent)이 둘 다 맞는 Claude 의 검토·시험 행동입니다'
    };
  }
  if (hint === 'partial') {
    return {
      label: '확인 필요',
      cls: 'badge badge--scan-pending',
      title: 'partial — 검토 계정과 검토 표식(User-Agent) 중 하나만 맞습니다. 사람의 행동으로 셉니다'
    };
  }
  return null;
}

/*
 * F-69 ③ (2026-09-24) — 관리자 경보 목록의 「누가 일으켰나」 배지. 화면은 서버(admin_alerts.php)가 내려 준
 * 줄의 `origin` 과 `evidence.origin_hint` **두 값만** 본다 — 판정은 배치가 src/Origin.php 로 이미 했다.
 * 위 originBadgeFor(감사 화면)의 뜻은 그대로 두고, 경보에만 있는 두 갈래를 앞에 더한다.
 *   origin 없음(null — 2026-09-24 전의 옛 줄) → 「출처 표기 전」 .badge--off (흐린 회색 — 모른다)
 *   system                                    → 「시스템」 .badge--team (보라 — 사람도 Claude 도 아닌 배치 판정)
 *   claude-session                            → 「Claude」 (감사 화면과 같은 모양, 설명만 서버 명령용)
 *   claude-review · partial · human           → originBadgeFor 그대로(「Claude」·「확인 필요」·없음)
 * 색은 DESIGN 2.2 의 기존 변형만 쓴다(새 클래스·색 없음). 배지는 표시일 뿐 경보를 거르거나 숨기지 않는다.
 */
function alertOriginBadgeFor(origin, hint) {
  if (origin === null || typeof origin === 'undefined') {
    return {
      label: '출처 표기 전',
      cls: 'badge badge--off',
      title: '출처(origin) 표기가 생기기 전(2026-09-24 전)에 남은 경보 줄입니다 — 누가 일으켰는지 적혀 있지 않습니다'
    };
  }
  if (origin === 'system') {
    return {
      label: '시스템',
      cls: 'badge badge--team',
      title: 'system — 행위자가 없는 배치 판정(정합성·백업·감사 끊김)입니다'
    };
  }
  if (origin === 'claude-session') {
    return {
      label: 'Claude',
      cls: 'badge badge--internal',
      title: 'claude-session — AI 세션이 서버에서 임시·시험 DB 만 다룬 명령입니다(운영 DB 를 겨누면 사람으로 셉니다)'
    };
  }
  return originBadgeFor(origin, hint);
}

/*
 * F-65 (PRD F-65 ③, ARCH 5.29 「목록·화면」) — 「실행 파일 포함」 배지.
 *
 * 화면은 서버가 준 `exec_content` 불리언 **하나만** 본다. 파일명·확장자를 보고 스스로
 * 판단하지 않는다. 배지는 표시일 뿐이고, 받는 길을 가르는 것은 서버의 관문 여섯이다
 * (PRD 10장 「버튼 표시는 권한의 근거가 아님」).
 */
var EXEC_BADGE = {
  label: '실행 파일 포함',
  cls: 'badge badge--exec',
  title: '실행 파일이 든 압축본입니다. 받으려면 비밀번호와 2단계 인증 코드를 다시 넣어야 합니다'
};

function addNameCell(row, name, sensitive, scanStatus, execContent) {
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
  // F-54 — 「검사 중」(회색) · 「격리됨」(빨강). 문구는 textContent 로만 넣는다.
  var scan = scanBadgeFor(scanStatus);
  if (scan !== null) {
    var scanBadge = document.createElement('span');
    scanBadge.className = scan.cls;
    scanBadge.setAttribute('title', scan.title);
    setText(scanBadge, scan.label);
    td.appendChild(document.createTextNode(' '));
    td.appendChild(scanBadge);
  }
  // F-65 — 「실행 파일 포함」. scan 배지와 **같은 방식**(createElement + textContent)이다.
  if (execContent === true) {
    var execBadge = document.createElement('span');
    execBadge.className = EXEC_BADGE.cls;
    execBadge.setAttribute('title', EXEC_BADGE.title);
    setText(execBadge, EXEC_BADGE.label);
    td.appendChild(document.createTextNode(' '));
    td.appendChild(execBadge);
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
  forgetMe();   // P-5a — 로그인 화면으로 가기 전에 나눠 쓰던 `/api/me` 답을 버린다(다음 화면은 새로 묻는다)
  // Q:F-7 B1-1 — 지금 화면이 허용 목록 안이면 꼬리표로 싣는다(첫 화면이면 꼬리표 없이 — 로그인 뒤 기본값과 같다)
  var here = returnPath(window.location.pathname + window.location.search);
  window.location.assign(here !== null && here !== HOME_PATH ? LOGIN_PATH + '?to=' + rawEncode(here) : LOGIN_PATH);
}

/* ─── `GET /api/me` 는 한 화면에 한 번 (Q:F-4 P-5a) ─────────────────────────
 *
 * 커뮤니티 화면은 이 셸 스크립트(bootPage)와 모듈 `editor.js`(boot)가 각자 `/api/me` 를 불러
 * 화면 한 번 이동에 두 번 나갔다. 이제 **첫 호출의 Promise 하나**를 이 화면이 사는 동안 나눠 쓴다.
 *
 *   · 나눠 쓰는 것은 응답 **글자(raw)** 다. 부르는 쪽마다 `JSON.parse` 로 새 객체를 받으므로 한쪽이
 *     값을 고쳐도 다른 쪽에 번지지 않는다. `cache: 'no-store'` 는 그대로다(브라우저·중간 캐시 0).
 *   · 붙잡아 두는 것은 **200 이고 JSON 객체인 응답만**이다. 401·그 밖의 실패·깨진 본문·네트워크 오류는 버린다 — 다음에 부르는
 *     쪽이 다시 묻고, 401 이면 지금처럼 로그인 화면으로 간다.
 *   · 저장소(localStorage·쿠키)에 넣지 않는다 — 메모리에만, 화면을 떠나면 사라진다.
 *   · 다시 물어야 하는 흐름은 `fresh === true` 로 부르거나 `forgetMe()` 로 버린다: 재인증 성공 뒤
 *     (`reauth_fresh` 가 바뀐다) · 강제 절차의 「다음 걸음」 · 로그인 화면으로 갈 때(`toLogin`).
 *     CSRF 토큰은 세션 id 에 묶여 있어 재인증으로는 바뀌지 않는다(Auth::csrfToken) — 그래도 한 번
 *     버려 두어, 그 뒤에 묻는 쪽은 서버의 지금 값을 받는다.
 *   · 권한은 여전히 서버가 요청마다 정한다. 이 값은 화면을 그리는 편의값이다 (PRD S-01).
 *
 * @return Promise<{status:number, me:(Object|null)}>  `me` 는 200 이고 JSON 객체일 때만, 아니면 null
 */
var meShared = null;

function forgetMe() {
  meShared = null;
}

function fetchMe(fresh) {
  if (fresh === true || meShared === null) {
    var request = fetch(APP_BASE + '/api/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        return response.text().then(
          function (raw) { return { status: response.status, raw: raw }; },
          function () { return { status: response.status, raw: '' }; }
        );
      });
    meShared = request;
    request.then(function (result) {
      // 붙잡는 것은 200 이고 JSON 객체인 답만 — 깨진 본문을 화면 내내 쓰지 않게(독립 검토 Q:F-4 A L-3)
      var keep = result.status === 200;
      if (keep) {
        try {
          var parsed = JSON.parse(result.raw);
          keep = parsed !== null && typeof parsed === 'object';
        } catch (e) {
          keep = false;
        }
      }
      if (!keep && meShared === request) {
        meShared = null;
      }
    }, function () {
      if (meShared === request) {
        meShared = null;
      }
    });
  }
  return meShared.then(function (result) {
    var me = null;
    if (result.status === 200) {
      try {
        me = JSON.parse(result.raw);
      } catch (e) {
        me = null;
      }
    }
    return { status: result.status, me: me && typeof me === 'object' ? me : null };
  });
}

/* 커뮤니티 모듈(editor.js)도 같은 한 번을 쓴다 — `qarediHandleReauth` 와 같은 방식으로 이름 하나만 내놓는다.
   이 스크립트(`<body>` 끝의 일반 스크립트)가 모듈보다 먼저 실행되므로 모듈이 부를 때는 이미 있다. */
window.qarediMe = fetchMe;

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

/** 셸(로그아웃 · 역할로 보기 끄기)이 붙일 CSRF 토큰 — initShell 이 넣고, 세션이 바뀌면 setSessionCsrf 가 바꾼다 */
var shellCsrf = null;

/**
 * 이 탭을 연 뒤 세션이 바뀌었을 때(새 탭에서 다시 로그인하고 일괄 작업의 「이어서 하기」를 누름) 셸이 쥔 토큰을 새 값으로 바꾼다 —
 * 재인증 창 · 로그아웃 · 역할로 보기 끄기가 이 값을 쓴다. 값은 **`/api/me` 의 답(csrf_token)에서만** 온다(부르는 쪽이 같은 계정인지
 * 먼저 본다). 화면(파일 화면 · 커뮤니티)은 제 토큰을 따로 바꾼다. 막는 것은 여전히 서버다(Http CSRF 검사).
 */
function setSessionCsrf(token) {
  if (typeof token !== 'string' || token === '') {
    return;
  }
  reauthCsrf = token;
  shellCsrf = token;
}

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

/**
 * 사용감 라운드 1 UX-11 — 쓰기 요청이 403(재인증·강제 절차 아님)을 받았을 때 **세션이 바뀌었나** 한 번 본다. 두 앱이 같이 쓰는 한 곳이다
 * (커뮤니티 editor.js 의 `api()` · 파일 화면의 `writeFetch`).
 *   · `/api/me` 를 새로 묻는다(no-store · fetchMe(true)). 한 탭에서 동시에 여러 요청이 403 을 받아도 **묻는 것은 한 번** — 진행 중인 약속을 나눠 쓴다.
 *   · 답: `{why:'login'}` 로그인 없음(401) · `{why:'other'}` 다른 계정 · `{why:'fresh', token}` 같은 계정인데 토큰이 다르다(다른 탭에서 다시 로그인) ·
 *         `{why:'same'}` 같은 계정 · 같은 토큰(진짜 거부) · `{why:'check'}` 묻지 못함(네트워크)
 *   · 계정을 견주는 값은 이 화면을 연 때의 `/api/me` 아이디(부르는 쪽이 넘긴다) — 역할로 보기 중에도 실제 계정이다.
 * 토큰은 같은 출처 `/api/me` 의 답에서만 온다. 교차 사이트 요청은 그 답을 읽을 수 없으므로 CSRF 방어는 그대로다(SECURITY 7장). 막는 것은 서버다.
 */
var sessionProbeInFlight = null;

function probeSession(expectUser, sentToken) {
  if (sessionProbeInFlight === null) {
    var mine = fetchMe(true);
    sessionProbeInFlight = mine;
    var done = function () {
      if (sessionProbeInFlight === mine) {
        sessionProbeInFlight = null;
      }
    };
    mine.then(done, done);
  }
  return sessionProbeInFlight.then(function (result) {
    if (result.status === 401 || result.me === null) {
      return { why: 'login' };
    }
    if (typeof expectUser !== 'string' || expectUser === '' || result.me.username !== expectUser) {
      return { why: 'other' };
    }
    if (typeof result.me.csrf_token === 'string' && result.me.csrf_token !== '' && result.me.csrf_token !== sentToken) {
      return { why: 'fresh', token: result.me.csrf_token };
    }
    return { why: 'same' };
  }, function () {
    return { why: 'check' };
  });
}

window.qarediSessionProbe = probeSession;

/** UX-11 — 다른 계정으로 로그인해 멈춘 탭의 안내(커뮤니티 editor.js 의 HALT_TEXT 와 같은 글자) */
var HALT_TEXT = '다른 계정으로 로그인되어 이 탭은 멈췄습니다 — 새로 고치세요';

/* 커뮤니티 5-C — 게시판 관리 창(editor.js, 모듈 스크립트)도 **같은 재인증 창**을 쓴다(DESIGN 2.7 D-9 —
   「15분 창이 닫혀 API 가 403 을 돌려줄 때 뜨는 모달과 같은 모달」). 모듈은 이 파일의 함수를 이름으로
   부를 수 없어 `personLabel` 과 같은 방식으로 하나만 내놓는다. 막는 것은 여전히 서버다. */
window.qarediHandleReauth = handleReauth;
/* 커뮤니티의 일괄 「이어서 하기」도 셸의 토큰을 같은 함수로 바꾼다(모듈은 이 파일의 변수에 닿지 못한다) */
window.qarediSetCsrf = setSessionCsrf;

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
  dialog.className = 'modal modal--form';

  // 표준 세 구역 — 머리(제목 + 메타) / 본문(폼) / 바닥(오류 한 줄 + 단추). DESIGN 2.7 · 9장 C-2.
  var head = document.createElement('div');
  head.className = 'modal__head';

  var title = document.createElement('h2');
  title.className = 'modal__title';
  setText(title, '비밀번호 다시 확인');
  head.appendChild(title);

  var lead = document.createElement('span');
  lead.className = 'modal__meta';
  setText(lead, '관리 기능을 쓰려면 비밀번호를 다시 확인합니다. 확인한 창은 15분 동안 열려 있습니다.');
  head.appendChild(lead);

  // 디버깅 묶음 U2 M3 — 모든 창의 머리에 ✕(DESIGN 2.7 「✕ 와 「닫기」 둘 다」)
  var closeX = makeIconButton('x', '닫기');
  head.appendChild(closeX);

  dialog.appendChild(head);

  var body = document.createElement('div');
  body.className = 'modal__body';

  var form = document.createElement('form');
  form.setAttribute('method', 'dialog');
  form.className = 'form';
  // 제출 단추는 `.modal__foot` 에 있다 — 폼 밖의 단추가 이 폼을 제출하려면 id 가 필요하다.
  form.id = 'reauth-dialog-form';

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

  var hint = document.createElement('p');
  hint.className = 'muted';
  setText(hint, '비밀번호를 5번 틀리면 계정이 10분 잠기고 열려 있던 모든 창이 닫힙니다.');
  form.appendChild(hint);

  body.appendChild(form);
  dialog.appendChild(body);

  var foot = document.createElement('div');
  foot.className = 'modal__foot';

  var modalMsg = document.createElement('p');
  modalMsg.className = 'msg';
  modalMsg.setAttribute('role', 'alert');
  modalMsg.setAttribute('aria-live', 'polite');
  foot.appendChild(modalMsg);

  var cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn btn--quiet';
  setText(cancel, '닫기');
  foot.appendChild(cancel);

  var submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'btn';
  submit.setAttribute('form', form.id);
  setText(submit, '확인');
  foot.appendChild(submit);

  dialog.appendChild(foot);
  document.body.appendChild(dialog);

  var closed = false;   // 이 창이 이미 닫혔나 — 늦게 오는 close 이벤트가 **다음** 재인증 창의 빗장을 풀지 않게
  function close() {
    if (closed) {
      return;
    }
    closed = true;
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
  closeX.addEventListener('click', function () {
    close();
  });
  /* 디버깅 묶음 U2 — Esc 로 닫으면 브라우저가 창만 닫고(cancel → close) 위 close() 는 불리지 않았다: 「열려 있음」 빗장(reauthOpen)이
     남아 새로고침 전까지 다음 재인증 창이 뜨지 않았고(수정 창은 문구 없이 저장 단추만 살아 있었다 — a57cf6b 부터), 닫힌 창이 DOM 에 쌓였다.
     닫히는 **모든 길**(닫기 · ✕ · Esc · 성공)이 지나는 close 이벤트에서 빗장을 풀고 창을 지운다. close() 가 다시 불려도 같은 결과다. */
  dialog.addEventListener('close', function () {
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
        forgetMe();   // P-5a — `reauth_fresh` 가 바뀌었다. 이 뒤에 `/api/me` 를 묻는 쪽은 새로 받는다
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

  /* T:F-72 라운드 C — 휴대폰이고 내 열쇠가 있으면 비밀번호 칸 위에 「지문·얼굴로 확인」(passkey.js 가 붙인다 — 없으면 아무 일 없음).
     성공하면 비밀번호 확인과 같은 뒤처리(창 닫기 · `/api/me` 새로 · 막혔던 요청 다시). 막는 것은 여전히 서버 관문이다. */
  if (typeof window.qarediPasskeyReauth === 'function') {
    window.qarediPasskeyReauth(body, form, function () {
      close();
      forgetMe();
      if (typeof onDone === 'function') {
        onDone();
      }
    }, modalMsg);
  }

  dialog.showModal();
  input.focus();
}

/**
 * POST /api/me/reauth — 재인증 창구 (PRD 8장). 응답 문구는 서버가 준 것만 쓴다.
 *
 * @return Promise<{ok:boolean, message:string, toLogin:boolean}>
 */
function postReauth(password, csrfToken) {
  return fetch(APP_BASE + '/api/me/reauth', {
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

/**
 * D-9 (DESIGN.md 2.7 · 7장 D-9, PRD F-23, ARCH 4.0 관문 [3-2]) — 관리 화면으로 가는 링크가
 * 가리키는 곳이 재인증 대상인가. 라우터 고정 표(public/index.php 의 TBOX_REAUTH_RETURN)와
 * **같은 목록**이고, 화면이 새 경로를 지어내지 않는다.
 */
function isAdminPath(href) {
  /* 28단계부터 링크는 `APP_BASE`(`/tbox`) 를 달고 나온다(`Http::url`). 앞머리를 떼고 본다 —
     떼지 않아 28단계 이후 재인증 **모달**이 한 번도 걸리지 않고 늘 `/reauth` 화면으로 넘어갔다
     (2026-09-23 커뮤니티 앱 메뉴에서 발견). 막는 것은 여전히 서버 관문 [3-2] 다. */
  var path = href.indexOf(APP_BASE + '/') === 0 ? href.slice(APP_BASE.length) : href;
  return /^\/(admin(\/|$)|users$|org$|audit$|trash$|wiki(\/|$)|alerts-app$)/.test(path);
}

/**
 * D-9 — 관리 화면으로 가는 링크를 누르면, 재인증이 필요할 때만 여기서 모달을 먼저 띄운다.
 *
 * 판단의 근거는 서버다: 모달을 건너뛰어도(주소 직접 입력·스크립트 차단) 서버 관문 [3-2]가
 * 302 `/reauth` 로 보낸다. `reauth_fresh` 는 `/api/me` 가 준 **편의값**이고, 값을 고쳐도
 * 서버가 요청마다 DB 를 다시 본다 (PRD S-01). 통과 뒤 이동도 `location.assign` 이라
 * 그 요청을 서버가 다시 판정한다 — 모달이 권한을 만들지 않는다.
 */
function armReauthLinks(me) {
  if (!me || me.reauth_fresh === true) {
    return;   // 이미 확인된 세션 — 그냥 이동 (관리 화면 안에서 관리 화면으로 가는 링크가 이 경우다)
  }
  document.querySelectorAll('a.nav__item[href], a.card--link[href]').forEach(function (a) {
    var href = a.getAttribute('href') || '';
    /* Q:F-4 U-2b — 「내 휴지통」(`data-no-reauth`)은 관리 화면과 같은 주소(`/trash`)지만 본인 자료라
       재인증을 묻지 않는다. 막는 판단은 여전히 서버다: 전체 범위(Admin)면 서버가 302 `/reauth` 로 보낸다. */
    if (!isAdminPath(href) || a.hasAttribute('data-no-reauth')) {
      return;
    }
    a.addEventListener('click', function (ev) {
      // 새 탭·새 창·다운로드 등 브라우저 기본 동작은 그대로 둔다(그 요청은 서버 관문이 302 로 받는다).
      if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
        return;
      }
      ev.preventDefault();
      // 기존 모달 그대로 — 성공하면 onDone, 「닫기」면 아무 일 없음. <dialog> 가 없는 브라우저는
      // openReauthModal 이 이미 `/reauth` 화면으로 보낸다(후퇴 경로).
      openReauthModal(function () {
        window.location.assign(href);   // href 는 마크업에 적힌 값 그대로
      });
    });
  });
}

/** 크기 표시 — 숫자만 다룬다(서버 문자열을 그대로 쓰지 않는다).
    숫자와 단위 사이는 **줄바꿈 없는 빈칸**(U+00A0) — 좁은 표에서 「68」 / 「B」 가 두 줄로 갈라졌다(디버깅 묶음 U M1 · 2026-09-26).
    보이는 모양은 DESIGN 5장 그대로 「1.8 MB」(단위 앞 한 칸)다. */
var SIZE_GAP = '\u00a0';
function formatSize(bytes) {
  var n = typeof bytes === 'number' && isFinite(bytes) && bytes >= 0 ? bytes : 0;
  if (n < 1024) {
    return n + SIZE_GAP + 'B';
  }
  if (n < 1024 * 1024) {
    return (n / 1024).toFixed(1) + SIZE_GAP + 'KB';
  }
  return (n / (1024 * 1024)).toFixed(1) + SIZE_GAP + 'MB';
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

  /** 보내는 중인가 — 참이면 두 번째 제출(Enter 연타 · 자동 제출과 단추가 겹침)을 보내지 않는다 (사용감 라운드 1 UX-9) */
  var sending = false;
  /** 자동 제출한 코드 — 같은 여섯 자리로 두 번 자동 제출하지 않는다(틀리면 칸이 비워지고 새로 넣은 코드만 다시 간다) */
  var autoSentCode = '';

  /** 지금 2단계(코드 입력) 화면인가 */
  function inStep2() {
    return otpField !== null && otpField.hidden !== true;
  }

  /** 1단계로 되돌린다 — 들고 있던 비밀번호를 버리고 코드 칸을 닫는다 */
  function resetToStep1() {
    pendingPassword = null;
    autoSentCode = '';
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
    /* 디버깅 묶음 U M13 (2026-09-26) — 비밀번호 칸을 **비우지 않는다**(가려진 점 그대로). 예전엔 2단계로 넘어갈 때 칸을 비웠고,
       코드가 틀리면 「아이디 또는 비밀번호가 올바르지 않습니다」 + 빈 비밀번호 칸이 함께 보여 비밀번호를 다시 쳐야 하는 줄 알았다.
       값은 전과 같이 이 화면(JS 변수·칸)에만 있고 저장소에 쓰지 않는다. 칸을 고쳐 적으면 적은 쪽이 이긴다(아래 submit). */
    if (passwordInput) {
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

  /* 사용감 라운드 1 UX-9 — 2단계 코드 칸에 **여섯 자리 숫자**가 다 들어오면(치기 · 붙여넣기 · 문자 자동 채움 모두 `input` 한 길) 한 번만
     스스로 제출한다. 1단계(칸이 숨은 때)에는 하지 않는다 · 보내는 중이면 하지 않는다 · 같은 코드로 두 번 하지 않는다. 틀리면 전처럼 칸을 비우고
     초점을 준다(아래 401 갈래) — 새 코드를 넣으면 다시 한 번. 보내는 요청·서버 검사(실패 횟수·재사용 거부)는 단추로 보낼 때와 같다(SECURITY 7장). */
  if (otpInput) {
    otpInput.addEventListener('input', function () {
      var code = String(otpInput.value);
      if (!inStep2() || sending || !/^[0-9]{6}$/.test(code) || code === autoSentCode) {
        return;
      }
      autoSentCode = code;
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(submit || undefined);
      } else if (submit) {
        submit.click();
      }
    });
  }

  form.addEventListener('submit', function (event) {
    // 브라우저 기본 폼 전송(폼 인코딩)을 막는다 — 서버는 JSON 만 받는다 (ARCH 4.0).
    event.preventDefault();
    if (sending) {
      return;   // UX-9 — 보내는 중의 두 번째 제출은 버린다(요청 한 번)
    }
    sending = true;
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

    fetch(APP_BASE + '/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 200) {
          pendingPassword = null;
          /* 어디로 돌아가나 (커뮤니티 ARCHITECTURE.md 21.2).
             서버가 `?to=` 를 **허용 목록**으로 걸러 <body data-to> 에 넣어 준 키 하나를 읽고,
             그 키가 고정 맵에 있을 때만 맵의 값으로 간다. 주소를 data 속성 값으로 만들지
             않으므로, 속성을 개발자도구로 고쳐도 갈 수 있는 곳은 맵의 두 값뿐이다. */
          // Q:F-7 B1-1 — 옛 키 둘 + 허용 목록 경로. 지문·얼굴 로그인(passkey.js)과 같은 한 함수다.
          window.location.assign(loginDestination());
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
            /* 디버깅 묶음 U M13 — 서버는 코드가 틀려도 1단계와 **같은 문구**(「아이디 또는 비밀번호…」)로 답한다(계정 상태를 새지 않으려는
               것 — Auth::login 7). 이 화면은 이미 2단계다: 비밀번호가 맞았다는 것은 서버가 1단계 답(mfa_required)으로 **이미 알려 준 것**이라
               「코드가 맞지 않다」고 말해도 새로 새는 것이 없다. 그래서 2단계의 401 만 코드 문장으로 바꿔 보인다 — 2단계에 닿은 뒤의 401 은
               코드가 틀린 경우다(만료된 임시 비밀번호는 코드보다 먼저 1단계에서 걸리고, 다섯 번 틀려 잠기면 423 이라 아래에서 처음부터 다시).
               드물게 그 사이 계정이 꺼진 경우도 같은 401 이라 문장에 「시간이 지났습니다」를 함께 둔다. */
            /* 독립 검토 L-4 — 비밀번호 칸을 비우지 않으므로(고쳐 적을 수 있다) 문장이 둘 다를 가리킨다. 계정이 있는지는 말하지 않는다. */
            setText(msg, '비밀번호나 인증 코드가 맞지 않습니다. 비밀번호를 확인하고, 인증 앱에 지금 보이는 6자리 코드를 넣으세요.');
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
        sending = false;
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
/**
 * 등급의 "노출 범위" 순서 — 서버 `Authz::LEVEL_RANK` 와 **같은 값**이다 (PRD 4장).
 *
 * ★ 이 표는 **권한 판단이 아니다.** 화면이 선택지를 미리 줄여 주는 편의일 뿐이고,
 *   무엇을 올릴 수 있고 어떤 폴더에 넣을 수 있는지는 서버가 매 요청 다시 판단한다
 *   (`Authz::decideUploadAs` · `Authz::fitsUnderFolder`, PRD S-01).
 *   화면이 이 표를 무시하고 아무 값이나 보내도 서버가 403/400 으로 막는다.
 */
var LEVEL_RANK = { 'public': 0, 'internal': 1, 'team': 2, 'admin-only': 3 };

/** 한 번에 고를 수 있는 파일 개수 (F-27 화면 상수, ARCH 5.17) */
var BULK_MAX = 50;

/**
 * 접근 등급 <select> 를 **폴더 등급 이상**으로만 채운다 (18-1, PRD F-26 불변 규칙 ①).
 *
 * `minLevel` 이 null 이면(루트) 제한이 없다. `preferLevel` 이 목록에 있으면 그것을 기본값으로
 * 고른다 — 구글 드라이브처럼 "폴더의 등급을 물려받는" 기본값이다 (ARCH 5.16 「구글 드라이브와 비교」).
 * 서버는 여전히 **명시값**을 요구하므로, 고를 것이 하나도 없으면 자리표시만 남는다.
 *
 * 만들어진 실제 등급 개수를 돌려준다.
 */
function fillLevelOptionsUnder(select, levels, minLevel, preferLevel) {
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
  var floor = typeof minLevel === 'string' && Object.prototype.hasOwnProperty.call(LEVEL_RANK, minLevel)
    ? LEVEL_RANK[minLevel]
    : 0;

  var placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.disabled = true;
  setText(placeholder, LEVEL_PLACEHOLDER);
  select.appendChild(placeholder);

  var wanted = '';
  levels.forEach(function (level) {
    if (typeof level !== 'string' || !Object.prototype.hasOwnProperty.call(LEVEL_LABELS, level)) {
      return;
    }
    if (LEVEL_RANK[level] < floor) {
      return;   // 폴더보다 넓은 등급은 서버가 400 parent_narrower 로 막는다
    }
    var option = document.createElement('option');
    option.value = level;
    setText(option, LEVEL_LABELS[level]);
    select.appendChild(option);
    made += 1;
    if (level === preferLevel) {
      wanted = level;
    }
  });

  if (made === 0) {
    select.value = '';
    return 0;
  }
  if (wanted !== '') {
    select.value = wanted;
  } else {
    placeholder.selected = true;
    select.value = '';
  }
  return made;
}

/** 폴더 한 줄의 「등급」 배지 + 개수 꼬리표. 전부 textContent 다 (PRD S-03). */
function folderMeta(folder) {
  var meta = document.createElement('span');
  meta.className = 'foldertree__meta';
  var badge = makeLevelBadge(folder.access_level);
  if (badge) {
    meta.appendChild(badge);
  }
  if (folder.team_name !== null && folder.team_name !== undefined && folder.team_name !== '') {
    var team = document.createElement('span');
    team.className = 'foldertree__team';
    setText(team, folder.team_name);
    meta.appendChild(team);
  }
  var counts = document.createElement('span');
  counts.className = 'foldertree__count';
  setText(counts, '파일 ' + (typeof folder.file_count === 'number' ? folder.file_count : 0)
    + ' · 하위 ' + (typeof folder.child_count === 'number' ? folder.child_count : 0));
  meta.appendChild(counts);
  return meta;
}

/* ─── 확인 창 — 지우기 전에 한 번 묻는다 (Q:F-4 U-2a, DESIGN 4장 「위험 동작 → 확인 모달」 · 2.7) ──
 *
 * 파일 행의 휴지통 단추는 누르자마자 `DELETE` 를 보냈고, 폴더 삭제만 `window.confirm` 으로 물었다.
 * 이제 둘 다 **이 창 하나**로 묻는다(확인 방식 하나 — 리서치 X-14). 창이 떠 있는 동안 요청은 0 이고,
 * 「닫기」·✕·Esc 는 아무것도 보내지 않는다. 빨간 단추(`.btn--danger`)를 눌러야 onYes 가 불린다.
 *
 * 구성은 기존 구성 요소만(새 클래스 0): `dialog.modal.modal--form`(520px, 머리·바닥 고정 · 본문만 스크롤 —
 * 390px 에서도 단추가 잘리지 않는다) > `.modal__head`(제목 + ✕) · `.modal__body`(문장 + `.muted` 한 줄) ·
 * `.modal__foot`(「닫기」 `.btn--quiet` + 확인 `.btn--danger`). 요소로 만들고 글자는 전부 textContent 다
 * (문자열 → HTML 변환 0, 인라인 style 0 — PRD S-03). 창은 한 번 만들어 두고 다시 쓴다.
 *
 * 화면이 판단하는 것은 없다 — 지울 수 있는지는 단추를 보이게 한 `can_delete` 와 요청을 받은 서버가 정한다.
 *
 * @param opts  { title, lead, note(선택), okLabel } — 코드에 적힌 문장 + 서버가 준 이름(textContent)
 * @param onYes 확인 단추를 눌렀을 때만 한 번 불린다
 */
var confirmBox = null;

function buildConfirmBox() {
  if (confirmBox) {
    return confirmBox;
  }
  var dialog = document.createElement('dialog');
  dialog.id = 'confirm-dialog';
  dialog.className = 'modal modal--form';
  dialog.setAttribute('aria-labelledby', 'confirm-dialog-title');

  var head = document.createElement('div');
  head.className = 'modal__head';
  var title = document.createElement('h2');
  title.className = 'modal__title';
  title.id = 'confirm-dialog-title';
  head.appendChild(title);
  var x = makeIconButton('x', '닫기');
  head.appendChild(x);
  dialog.appendChild(head);

  /* 본문은 문단 **하나**(문장 + 줄바꿈 + `.muted` 한 줄) — 폼 모달 본문은 격자라 문단 둘이면 문단 여백과
     격자 간격이 겹쳐 두 줄 사이가 벌어진다(390px 스크린샷 확인). 새 CSS 없이 한 문단으로 묶는다. */
  var bodyBox = document.createElement('div');
  bodyBox.className = 'modal__body';
  var para = document.createElement('p');
  var lead = document.createElement('span');
  para.appendChild(lead);
  var br = document.createElement('br');
  para.appendChild(br);
  var note = document.createElement('span');
  note.className = 'muted';
  para.appendChild(note);
  bodyBox.appendChild(para);
  dialog.appendChild(bodyBox);

  var foot = document.createElement('div');
  foot.className = 'modal__foot';
  var cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn btn--quiet';
  setText(cancel, '닫기');
  foot.appendChild(cancel);
  var ok = document.createElement('button');
  ok.type = 'button';
  ok.className = 'btn btn--danger';
  foot.appendChild(ok);
  dialog.appendChild(foot);

  var box = { dialog: dialog, title: title, lead: lead, br: br, note: note, cancel: cancel, ok: ok, onYes: null };

  function dismiss() {
    box.onYes = null;
    if (dialog.open) {
      dialog.close();
    }
  }
  x.addEventListener('click', dismiss);
  cancel.addEventListener('click', dismiss);
  // Esc(cancel 이벤트)는 브라우저가 창을 닫는다 — 닫히는 모든 길에서 대기 중인 동작을 버린다.
  dialog.addEventListener('close', function () {
    box.onYes = null;
  });
  ok.addEventListener('click', function () {
    var fn = box.onYes;
    box.onYes = null;
    if (dialog.open) {
      dialog.close();
    }
    if (typeof fn === 'function') {
      fn();
    }
  });

  document.body.appendChild(dialog);
  confirmBox = box;
  return box;
}

function openConfirm(opts, onYes) {
  var box = buildConfirmBox();
  if (box.dialog.open) {
    return;   // 모달 안에 모달을 열지 않는다 (DESIGN 2.7)
  }
  setText(box.title, opts.title);
  setText(box.lead, opts.lead);
  var note = typeof opts.note === 'string' ? opts.note : '';
  setText(box.note, note);
  box.note.hidden = note === '';
  box.br.hidden = note === '';
  setText(box.ok, opts.okLabel);
  box.onYes = typeof onYes === 'function' ? onYes : null;
  box.dialog.showModal();
  // 처음 초점은 「닫기」 — Enter 를 잘못 눌러도 지워지지 않는다.
  box.cancel.focus();
}
/* T:F-72 — 「내 계정」 지문·얼굴 로그인 기기 해제(passkey.js)도 **같은 확인 창**을 쓴다(브라우저 기본 창 0 — DESIGN 2.7) */
window.qarediOpenConfirm = openConfirm;

function initFiles(csrfToken, uploadLevels, canFetch, myTeamId) {
  var msg = document.getElementById('msg');
  var body = document.getElementById('files-body');
  var empty = document.getElementById('files-empty');
  var filesOk = document.getElementById('files-ok');   // U-2a — 지운 뒤 결과 한 줄(.ok-note)
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
  var uploadToggle = document.getElementById('upload-toggle');
  var fetchToggle = document.getElementById('fetch-toggle');
  // 18-3 — 올리기·가져오기는 새 폴더와 같은 <dialog class="modal"> 다.
  var uploadDialog = document.getElementById('upload-dialog');
  var uploadDialogClose = document.getElementById('upload-dialog-close');
  var uploadDialogX = document.getElementById('upload-dialog-x');
  var uploadMsg = document.getElementById('upload-msg');
  var uploadWhere = document.getElementById('upload-where');
  var fetchDialog = document.getElementById('fetch-dialog');
  var fetchDialogClose = document.getElementById('fetch-dialog-close');
  var fetchDialogX = document.getElementById('fetch-dialog-x');
  var fetchMsg = document.getElementById('fetch-msg');
  var fetchWhere = document.getElementById('fetch-where');
  var fetchForm = document.getElementById('fetch-form');
  var fetchUrl = document.getElementById('fetch-url');
  var fetchDesc = document.getElementById('fetch-description');
  var fetchLevel = document.getElementById('fetch-level');
  var fetchTeamField = document.getElementById('fetch-team-field');
  var fetchTeam = document.getElementById('fetch-team');
  var fetchSubmit = document.getElementById('fetch-submit');
  var uploadDrop = document.getElementById('upload-drop');
  var uploadPicked = document.getElementById('upload-picked');
  var uploadProgress = document.getElementById('upload-progress');
  var uploadStatusBody = document.getElementById('upload-status-body');

  // 18-1 폴더 (PRD F-26, ARCH 5.16)
  var filesFolderHead = document.getElementById('files-folder-head');
  var folderMsg = document.getElementById('folder-msg');
  var folderCrumbs = document.getElementById('folder-crumbs');
  var folderList = document.getElementById('folder-list');
  var folderEmpty = document.getElementById('folder-empty');
  var folderActions = document.getElementById('folder-actions');
  var folderNewBtn = document.getElementById('folder-new');
  var folderRenameBtn = document.getElementById('folder-rename');
  var folderLevelBtn = document.getElementById('folder-level');
  var folderDeleteBtn = document.getElementById('folder-delete');
  var folderDialog = document.getElementById('folder-dialog');
  var folderDialogTitle = document.getElementById('folder-dialog-title');
  var folderDialogWhere = document.getElementById('folder-dialog-where');
  var folderDialogMsg = document.getElementById('folder-dialog-msg');
  var folderForm = document.getElementById('folder-form');
  var folderNameField = document.getElementById('folder-name-field');
  var folderNameInput = document.getElementById('folder-name');
  var folderLevelSelect = document.getElementById('folder-level-select');
  var folderTeamField = document.getElementById('folder-team-field');
  var folderTeamSelect = document.getElementById('folder-team');
  var folderCascadeField = document.getElementById('folder-cascade-field');
  var folderCascade = document.getElementById('folder-cascade');
  var folderReasonField = document.getElementById('folder-reason-field');
  var folderReason = document.getElementById('folder-reason');
  var folderSubmit = document.getElementById('folder-submit');
  var folderDialogClose = document.getElementById('folder-dialog-close');
  var folderDialogX = document.getElementById('folder-dialog-x');

  var moveDialog = document.getElementById('move-dialog');
  var moveFileLabel = document.getElementById('move-file');
  var moveCrumbs = document.getElementById('move-crumbs');
  var moveList = document.getElementById('move-list');
  var moveEmpty = document.getElementById('move-empty');
  var moveMsg = document.getElementById('move-msg');
  var moveHere = document.getElementById('move-here');
  var moveClose = document.getElementById('move-close');
  var moveX = document.getElementById('move-x');

  /* ─── 개발 대기열 6 · Q:F-3a 라운드 A — 보기 셋(파일·즐겨찾기·최근) · ☆ (지시서 1.1 · 1.2) ──────────
     화면은 판단하지 않는다: 즐겨찾기에 넣을 수 있는지는 창구가 `Authz::decideRead` 로, 목록에 무엇이 나오나는 창구의
     읽기 조건절(`visibleFilesWhere`)이 정한다. 별이 찬지(넣었나)는 창구가 돌려준 목록에 있나 없나만 본다 (PRD S-01·S-02). */
  var viewButtons = {
    files: document.getElementById('files-view-files'),
    favorites: document.getElementById('files-view-favorites'),
    recent: document.getElementById('files-view-recent')
  };
  var favFoldersWrap = document.getElementById('fav-folders-wrap');
  var favFoldersList = document.getElementById('fav-folders');
  var whenHead = document.getElementById('files-when-head');
  var folderFavBtn = document.getElementById('folder-fav');
  var folderFavLabel = document.getElementById('folder-fav-label');
  /** 창구가 돌려준 즐겨찾기(지금 볼 수 있는 것) — 파일 id · 폴더 id → true */
  var favFiles = {};
  var favFolders = {};
  /** 보기를 빨리 바꿀 때 늦게 온 옛 응답이 새 보기를 덮지 않게 — 부를 때마다 하나씩 오른다 */
  var loadSeq = 0;
  /** 보기마다의 빈 목록 문장(DESIGN 2.4 「(무엇)이 없습니다.」 + 다음에 할 일 한 문장) */
  var EMPTY_TEXT = {
    files: '표시할 파일이 없습니다.',
    favorites: '즐겨찾기한 파일이 없습니다. 파일 줄 이름 앞의 별(☆)을 누르면 여기에 모입니다.',
    recent: '최근 30일 동안 미리보기·받기로 연 파일이 없습니다.'
  };

  /* state.folder 는 **지금 열어 둔 폴더**(0 = 루트)이고, folderInfo·crumbs 는 서버가
     `GET /api/files` 응답의 `folder` 로 내려 준 값 그대로다 — 화면이 지어내지 않는다. */
  var state = {
    q: '', sort: 'created_at', dir: 'desc', page: 1, total: 0, perPage: 20,
    folder: 0, folderInfo: null, crumbs: [], children: [],
    // 개발 대기열 6 — 지금 보기(files · favorites · recent)
    view: 'files'
  };

  /** 폴더 모달의 모드 — 'create' · 'rename' · 'level' */
  var folderMode = 'create';
  /** 파일 이동 모달이 지금 펼쳐 보고 있는 폴더(0 = 루트)와 옮길 파일 */
  var movePick = { folder: 0, crumbs: [], file: null, bulk: null };   // bulk — 라운드 B 「고른 파일 여럿」(openBulkMove)

  /* 사용감 라운드 1 UX-5 — 올리기가 **모두** 성공해 창을 닫은 뒤, 목록을 다시 그리면 새 파일 줄로 데려가 잠깐 강조한다.
     줄은 이 화면이 방금 그린 것(pageRows)에서 서버가 준 id 로 찾는다(선택자 조립 0). 강조는 `.is-fresh`(DESIGN 2.4 「방금 올린 줄」 — 배경만,
     움직임 0) 2.5초 · 스크롤은 「움직임 줄이기」 설정이면 곧바로(부드럽게 0). 새 파일이 이 쪽에 없으면(정렬·쪽) 아무 일도 하지 않는다. */
  var freshIds = null;
  function showFresh() {
    if (freshIds === null) {
      return;
    }
    var want = freshIds;
    freshIds = null;
    var rows = [];
    pageRows.forEach(function (entry) {
      if (entry && entry.file && want.indexOf(entry.file.id) >= 0 && entry.check) {
        var tr = entry.check.closest('tr');
        if (tr) {
          rows.push(tr);
        }
      }
    });
    if (rows.length === 0) {
      return;
    }
    var reduce = false;
    try {
      reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) {
      reduce = false;
    }
    rows.forEach(function (tr) {
      tr.classList.add('is-fresh');
    });
    rows[0].scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
    window.setTimeout(function () {
      rows.forEach(function (tr) {
        tr.classList.remove('is-fresh');
      });
    }, 2500);
  }

  /** 상태 변경 요청 공통 헤더 (PRD S-09-3) */
  function csrfHeaders() {
    return { 'X-CSRF-Token': csrfToken };
  }

  /**
   * 사용감 라운드 1 UX-11 — 이 화면의 **한 건** 쓰기 요청(올리기 · 지우기 · 공유 · 고치기 · 옮기기 · 즐겨찾기 …)을 보내는 한 곳.
   * 403 이고 재인증·강제 절차(`next`)가 아니면 셸의 `probeSession`(`/api/me` 한 번 — 진행 중이면 나눠 씀)으로 세션을 본다:
   * 같은 계정 · 새 토큰 → 이 화면(csrfToken)과 셸(setSessionCsrf)의 토큰을 바꾸고 **그 요청을 한 번만** 다시 보낸다(또 403 이면 그대로) ·
   * 다른 계정 → 다시 보내지 않고 이 탭을 멈춘다(뒤의 쓰기도 요청 0 · 안내 한 줄은 부르는 쪽이 늘 하던 대로 서버 문장 자리에 보인다) ·
   * 로그인 없음 → 받은 답 그대로(부르는 쪽의 401·로그인 흐름은 전과 같다). 일괄 작업(bulkFetch)은 제 「이어서 하기」 흐름을 그대로 쓴다.
   * 헤더는 부르는 쪽이 만든 글자 객체이고, 다시 보낼 때 X-CSRF-Token 한 칸만 새 값으로 바꾼다(본문은 같은 것 — JSON 글자·FormData).
   */
  var filesHalted = false;
  function haltedResponse() {
    return new Response(JSON.stringify({ error: HALT_TEXT }), { status: 403, headers: { 'Content-Type': 'application/json' } });
  }
  function writeFetch(url, options) {
    if (filesHalted) {
      return Promise.resolve(haltedResponse());
    }
    var sentToken = options && options.headers ? options.headers['X-CSRF-Token'] : null;
    return fetch(url, options).then(function (response) {
      if (response.status !== 403) {
        return response;
      }
      return response.clone().json().then(function (d) { return d; }, function () { return {}; }).then(function (data) {
        if (data && typeof data.next === 'string' && data.next !== '') {
          return response;
        }
        return fetchMe().then(function (r) { return r.me && typeof r.me.username === 'string' ? r.me.username : null; }, function () { return null; })
          .then(function (who) { return probeSession(bulk.username !== null ? bulk.username : who, sentToken); })
          .then(function (got) {
            if (got.why === 'fresh') {
              csrfToken = got.token;
              setSessionCsrf(got.token);
              var again = {};
              Object.keys(options).forEach(function (k) { again[k] = options[k]; });
              again.headers = {};
              Object.keys(options.headers).forEach(function (k) { again.headers[k] = options.headers[k]; });
              again.headers['X-CSRF-Token'] = got.token;
              return fetch(url, again);
            }
            if (got.why === 'other') {
              filesHalted = true;
              return haltedResponse();
            }
            return response;
          });
      });
    });
  }

  function clearRows() {
    while (body.firstChild) {
      body.removeChild(body.firstChild);
    }
    pageRows = [];   // 라운드 B — 머리 체크·Shift 범위가 보는 「지금 그린 줄」도 함께 비운다
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value);
    row.appendChild(td);
    return td;
  }

  /* U-2a — 지운 뒤 결과 한 줄(DESIGN 4장 「성공」 = `.ok-note` 한 줄 + 목록이 곧바로 바뀜).
     자리는 서버 HTML 의 `#files-ok`(기본 hidden)이고 글자는 textContent 다. 다음 동작을 시작하면 지운다. */
  function showDone(text) {
    if (!filesOk) {
      return;
    }
    setText(filesOk, text);
    filesOk.hidden = text === '';
  }

  /* U-2a — 휴지통 단추는 곧바로 지우지 않고 확인 창(openConfirm)을 먼저 띄운다. 창이 떠 있는 동안
     요청 0 · 「닫기」·Esc 도 요청 0. 「휴지통으로 옮기기」를 눌러야 `DELETE /api/files/{id}` 한 건이 나간다.
     거부(403·404 …)는 전과 같이 서버 문구를 그대로 `#msg` 에 보인다. */
  function deleteFile(file) {
    if (!file || !UUID_RE.test(file.id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    var name = String(file.name === null || file.name === undefined ? '' : file.name);
    showDone('');
    openConfirm({
      title: '파일을 휴지통으로 옮길까요?',
      lead: '「' + name + '」을(를) 휴지통으로 옮깁니다 — 30일 안에 되살릴 수 있습니다.',
      /* Q:F-4 U-2b — 내가 올린 파일을 내가 지우면 「내 휴지통」에서 되살린다. 남의 파일은 관리자가 되살린다. */
      note: '내가 올린 파일은 「내 휴지통」에서 되살릴 수 있고, 다른 사람의 파일은 관리자가 되살립니다. 30일이 지나면 완전히 지워집니다.',
      okLabel: '휴지통으로 옮기기'
    }, function () {
      sendDelete(file.id, name);
    });
  }

  function sendDelete(id, name) {
    setText(msg, '');
    writeFetch(APP_BASE + '/api/files/' + id, {
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
          showDone('「' + name + '」을(를) 휴지통으로 옮겼습니다.');
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
   * 설명·접근 등급 바꾸기 (PATCH /api/files/{id}) — **창 하나**(`#file-edit-dialog`, 디버깅 묶음 U H2 · 2026-09-26).
   * 예전엔 브라우저 기본 입력 창이 세 번 떴다(설명 → 등급 영어 값을 직접 입력 → 팀 **번호**). 이제 설명 칸 + 등급 고르개 + 팀 고르개(이름).
   *
   * ★ 선택지는 **서버가 준 것만** — 등급은 /api/me 의 upload_levels(올리기 창·등급 바꾸기 창과 같은 목록)에 이 파일의 **지금 등급**을
   *   더한 것(지금 값이 목록 밖이어도 그대로 보이게), 팀은 /api/teams 의 이름(loadTeams 가 채운다). 고를 수 있다고 허용되는 것이 아니다 —
   *   바꿀 자격·낮추는 변경인지·폴더보다 넓은지는 서버(Authz)가 판단하고, 거부 문구는 창 아래 줄에 **서버가 준 그대로** 보인다.
   *   여기서 미리 걸러내면 그것이 곧 화면에 심은 권한 판단이다 (PRD S-01).
   * ★ 보내는 것은 **바뀐 키만**이다 (허용 항목은 description·access_level·team_id 셋뿐) — 전과 같다.
   * ★ F-65 — 「실행 파일 포함」 파일은 **등급을 묻지 않는다**: admin-only 를 내리면 「받는 사람은 언제나 Admin(2단계 인증 필수)」 전제가
   *   깨지므로 서버가 `access_level` 키 자체를 400 으로 막는다 (ARCH 5.29). 설명은 그대로 바꿀 수 있다.
   * 「닫기」·✕·Esc·바깥 누르기는 요청 0. 닫히면 초점은 연 단추로 돌아간다(<dialog> 기본 동작).
   */
  var editDialog = document.getElementById('file-edit-dialog');
  var editForm = document.getElementById('file-edit-form');
  var editName = document.getElementById('file-edit-name');
  var editDesc = document.getElementById('file-edit-description');
  var editLevelField = document.getElementById('file-edit-level-field');
  var editLevel = document.getElementById('file-edit-level');
  var editTeamField = document.getElementById('file-edit-team-field');
  var editTeam = document.getElementById('file-edit-team');
  var editLevelNote = document.getElementById('file-edit-level-note');
  var editMsg = document.getElementById('file-edit-msg');
  var editSubmit = document.getElementById('file-edit-submit');
  var editTarget = null;     // 지금 고치는 파일(서버가 준 행 그대로)
  var editBusy = false;
  var editTeamInitial = '';  // 창을 열 때의 팀 고르개 값 — 사용자가 바꾼 경우에만 team_id 를 보낸다(독립 검토 M-1)

  /** 등급 고르개 — 서버가 준 목록 + 이 파일의 지금 등급. 표(LEVEL_LABELS)에 없는 값은 만들지 않는다 */
  function fillEditLevels(current) {
    if (!editLevel) {
      return;
    }
    editLevel.replaceChildren();
    var offered = {};
    (Array.isArray(uploadLevels) ? uploadLevels : []).forEach(function (level) {
      if (typeof level === 'string') { offered[level] = true; }
    });
    if (typeof current === 'string') {
      offered[current] = true;
    }
    ['public', 'internal', 'team', 'admin-only'].forEach(function (level) {
      if (offered[level] !== true || !Object.prototype.hasOwnProperty.call(LEVEL_LABELS, level)) {
        return;
      }
      var option = document.createElement('option');
      option.value = level;
      setText(option, LEVEL_LABELS[level]);
      editLevel.appendChild(option);
    });
    editLevel.value = typeof current === 'string' ? current : '';
  }

  function syncEditTeamField() {
    if (editTeamField) {
      editTeamField.hidden = !editLevel || (editLevelField !== null && editLevelField.hidden) || editLevel.value !== 'team';
    }
  }

  function editFile(file) {
    if (!UUID_RE.test(file.id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    if (!editDialog || typeof editDialog.showModal !== 'function' || editDialog.open) {
      return;
    }
    editTarget = file;
    setText(msg, '');
    setText(editMsg, '');
    setText(editName, file.name);
    if (editDesc) {
      editDesc.value = typeof file.description === 'string' ? file.description : '';
    }
    var locked = file.exec_content === true;
    if (editLevelField) {
      editLevelField.hidden = locked;
    }
    if (editLevelNote) {
      editLevelNote.hidden = !locked;
    }
    fillEditLevels(typeof file.access_level === 'string' ? file.access_level : '');
    /* 독립 검토 M-1 — 팀 고르개를 **이 파일의 팀**으로 맞추고 그 값을 기억한다. 앞서 연 파일의 「지금 팀」 자리는 지운다.
       파일의 팀이 목록에 없으면(쓰지 않는 팀 · 팀 목록이 아직 안 옴) 「지금 팀」 자리를 하나 두고 그것을 고른다 — 전에는
       고르개에 이전 값(또는 늦게 온 목록이 고른 「내 팀」)이 남아 설명만 고쳐도 team_id 가 실렸다(관리자는 파일이 다른 팀으로
       조용히 옮겨지고, 팀장·직원은 뜻밖의 403). 보내는 쪽(submitEdit)은 사용자가 팀을 바꿨거나 등급을 team 으로 바꿀 때만 싣는다. */
    if (editTeam) {
      Array.prototype.forEach.call(editTeam.querySelectorAll('option[data-edit-current]'), function (option) {
        option.remove();
      });
      if (file.team_id !== null && file.team_id !== undefined && String(file.team_id) !== '') {
        var want = String(file.team_id);
        var found = Array.prototype.some.call(editTeam.options, function (option) { return option.value === want; });
        if (!found) {
          var cur = document.createElement('option');
          cur.value = want;
          cur.setAttribute('data-edit-current', '');
          var curName = typeof file.team_name === 'string' && file.team_name !== '' ? file.team_name + ' — ' : '';
          setText(cur, curName + '지금 팀(쓰지 않는 팀)');
          editTeam.insertBefore(cur, editTeam.firstChild);
        }
        editTeam.value = want;
      }
      editTeamInitial = editTeam.value;
    }
    syncEditTeamField();
    if (editSubmit) {
      editSubmit.disabled = false;
    }
    editDialog.showModal();
    if (editDesc) {
      editDesc.focus();
    }
  }

  function submitEdit(event) {
    event.preventDefault();
    var file = editTarget;
    if (!file || editBusy) {
      return;
    }
    var payload = {};
    var currentDescription = typeof file.description === 'string' ? file.description : '';
    var description = editDesc ? editDesc.value : currentDescription;
    if (description !== currentDescription) {
      payload.description = description;
    }
    if (editLevelField && !editLevelField.hidden && editLevel) {
      var currentLevel = typeof file.access_level === 'string' ? file.access_level : '';
      var level = editLevel.value;
      if (level !== '' && level !== currentLevel) {
        payload.access_level = level;
      }
      // 독립 검토 M-1 — team_id 는 등급을 team 으로 **바꿀 때** · 사용자가 팀 고르개를 **바꿨을 때**만 싣는다(설명만 고치면 키 자체가 없다)
      if (level === 'team' && editTeam && editTeam.value !== ''
          && (level !== currentLevel || editTeam.value !== editTeamInitial)) {
        payload.team_id = editTeam.value;
      }
    }
    if (Object.keys(payload).length === 0) {
      // 서버도 400 으로 막지만, 굳이 바뀌는 값이 없는 요청을 보내지 않는다.
      setText(editMsg, '바뀐 내용이 없습니다.');
      return;
    }

    editBusy = true;
    if (editSubmit) {
      editSubmit.disabled = true;
    }
    setText(editMsg, '');
    /* 디버깅 묶음 S-8 (e88f690 과 합침) — 관리자의 등급 **내리기**는 15분 재인증 창 안에서만 된다(서버가 403 next:"reauth").
       그때는 공용 재인증 창을 띄우고, 통과하면 **같은 값**을 다시 보낸다(값을 다시 묻지 않는다). 수정 창은 열어 둔 채다 —
       재인증 창을 닫으면(그만두면) 저장 단추가 다시 살아나 고쳐서 다시 낼 수 있다(디버깅 묶음 U 의 창 흐름). */
    function sendEdit() {
      editBusy = true;
      if (editSubmit) {
        editSubmit.disabled = true;
      }
      writeFetch(APP_BASE + '/api/files/' + file.id, {
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
            if (editDialog.open) {
              editDialog.close();
            }
            showDone('「' + file.name + '」을(를) 고쳤습니다.');
            loadFiles();
            return null;
          }
          return readJson(response).then(function (data) {
            if (handleReauth(response, data, sendEdit)) {
              /* 디버깅 묶음 U2 — 재인증 창을 그만두면(닫기·✕·Esc) 수정 창에는 저장 단추만 살아 있고 이유가 없었다.
                 서버가 준 문구를 이 창 아래 줄에 남긴다 — 다시 「저장하기」를 누르면 재인증 창이 다시 뜬다. */
              setText(editMsg, errorMessage(data));
              return null;
            }
            // 403·400 문구는 서버가 준 것만 쓴다 (사유를 브라우저가 추측하지 않는다). 창은 열어 둔다 — 고쳐서 다시 낼 수 있게.
            setText(editMsg, errorMessage(data));
            return null;
          });
        })
        .catch(function () {
          setText(editMsg, GENERIC_ERROR);
        })
        .then(function () {
          editBusy = false;
          if (editSubmit) {
            editSubmit.disabled = false;
          }
        });
    }
    sendEdit();
  }

  if (editForm) {
    editForm.addEventListener('submit', submitEdit);
  }
  if (editLevel) {
    editLevel.addEventListener('change', syncEditTeamField);
  }
  if (editDialog) {
    editDialog.addEventListener('close', function () {
      editTarget = null;
      setText(editMsg, '');
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
      wrap.appendChild(previewNote('파일이 길어 앞부분 64KiB 만 표시합니다. 전체 내용은 받아서 확인하세요.'));
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
      wrap.appendChild(previewNote('문서가 길어 앞부분 20,000자만 표시합니다. 전체 내용은 받아서 확인하세요.'));
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
        '파일이 손상되었거나 미리보기가 다루지 못하는 구조입니다. 받아서 확인하세요.'
      );
    }
    return previewCard(
      '미리보기를 지원하지 않는 형식입니다.',
      '파일 정보는 오른쪽에 있습니다. 내용은 받아서 확인하세요.'
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
    setWhen(previewCreated, data.created_at);
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
        previewOpen.setAttribute('href', HOME_PATH);
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
    setWhen(previewCreated, file.created_at);
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
      previewOpen.setAttribute('href', HOME_PATH);
    }
    if (previewDownload) {
      // 다운로드는 기존 동작 그대로(항상 첨부) — 같은 접두 검사를 거쳐 주소를 넣는다.
      var downloadHref = safeFileSrc(APP_BASE + '/api/files/' + file.id + '/download');
      previewDownload.setAttribute('href', downloadHref === '' ? HOME_PATH : downloadHref);
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

    fetch(APP_BASE + '/api/files/' + file.id + '/preview', { credentials: 'same-origin', cache: 'no-store' })
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
      shareSubmit.hidden = false;   // 디버깅 묶음 U2 M3 — 제출 단추는 바닥에 있다(폼 밖) · 주소를 만든 뒤 숨긴 것을 되돌린다
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

      writeFetch(APP_BASE + '/api/files/' + shareTargetId + '/share', {
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
              if (shareSubmit) {
                shareSubmit.disabled = false;
              }
              /* 디버깅 묶음 S-8 — 관리자 권한으로 만드는 링크는 15분 재인증 창 안에서만 된다(403 next:"reauth").
                 공용 재인증 창을 띄우고, 통과하면 같은 폼을 다시 보낸다(적어 둔 값은 폼에 그대로 있다). */
              if (handleReauth(response, data, function () {
                if (shareSubmit && !shareSubmit.disabled) {
                  shareSubmit.click();
                }
              })) {
                return null;
              }
              // 403·400 문구는 서버가 준 것만 쓴다 (사유를 브라우저가 추측하지 않는다).
              setText(shareMsg, errorMessage(data));
              return null;
            }
            // 주소는 여기서 한 번만 보인다. 폼은 접어 두어 실수로 또 만들지 않게 한다.
            if (shareForm) {
              shareForm.hidden = true;
            }
            if (shareSubmit) {
              shareSubmit.hidden = true;   // 바닥의 「링크 만들기」도 함께 접는다 — 남는 것은 「닫기」 하나
            }
            if (shareResult) {
              shareResult.hidden = false;
            }
            setText(shareUrlText, typeof data.url === 'string' ? data.url : '');
            setText(
              shareMeta,
              '만료 ' + shortWhen(data.expires_at).text +
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

  /* ─── F-65 확인 다운로드 (PRD F-65 ③, ARCH 5.29 「받기」 표) ─────────────────
   *
   * 「실행 파일 포함」 파일의 다운로드 단추가 이 창을 연다. 보통 다운로드는 그 파일에 대해
   * 403 이고(서버가 막는다), 여기서 **요청마다** 비밀번호와 2단계 코드를 넣어야 받는다.
   *
   * 받는 방법이 링크가 아니라 `fetch` + blob 인 이유: 비밀번호를 주소에 실을 수 없어
   * POST 본문으로 보내야 하고, POST 응답 본문을 파일로 저장하려면 blob 이 필요하다.
   * 만든 객체 URL 은 바로 되돌린다(revokeObjectURL) — 창이 남아 있는 동안 그 주소로
   * 파일을 다시 열 수 있게 두지 않는다.
   *
   * 비밀번호·코드는 입력 칸에만 있고, 창을 닫거나 보낸 뒤에 지운다. 화면 어디에도,
   * 브라우저 저장소에도 남기지 않는다 (PRD 7.7).
   */
  var execDialog = document.getElementById('exec-download-dialog');
  var execForm = document.getElementById('exec-dl-form');
  var execFileName = document.getElementById('exec-dl-file');
  var execUploader = document.getElementById('exec-dl-uploader');
  var execCreated = document.getElementById('exec-dl-created');
  var execPassword = document.getElementById('exec-dl-password');
  var execOtp = document.getElementById('exec-dl-otp');
  var execSubmit = document.getElementById('exec-dl-submit');
  var execMsg = document.getElementById('exec-dl-msg');
  var execClose = document.getElementById('exec-dl-close');
  var execCloseX = document.getElementById('exec-dl-x');

  /** 지금 창이 다루는 파일 (서버가 준 목록 항목 그대로). 보낼 때 id 형태를 한 번 더 본다. */
  var execTarget = null;

  /** 입력 칸을 비운다 — 창을 열 때와 닫을 때, 그리고 보낸 뒤에 부른다. */
  function clearExecFields() {
    if (execPassword) {
      execPassword.value = '';
    }
    if (execOtp) {
      execOtp.value = '';
    }
  }

  function openExecDownload(file) {
    if (!execDialog || !UUID_RE.test(file.id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    execTarget = file;
    setText(execFileName, file.name);
    setText(execUploader, typeof file.uploaded_by === 'string' ? file.uploaded_by : '—');
    setWhen(execCreated, file.created_at);
    setText(execMsg, '');
    clearExecFields();
    if (execSubmit) {
      execSubmit.disabled = false;
    }
    execDialog.showModal();
  }

  function closeExecDownload() {
    if (execDialog && execDialog.open) {
      execDialog.close();
    }
  }

  if (execDialog) {
    // close 는 Esc 로도 일어난다 — 어느 경로든 비밀번호·코드를 화면에서 지운다.
    execDialog.addEventListener('close', function () {
      execTarget = null;
      clearExecFields();
      setText(execMsg, '');
    });
  }
  if (execClose) {
    execClose.addEventListener('click', closeExecDownload);
  }
  if (execCloseX) {
    execCloseX.addEventListener('click', closeExecDownload);
  }

  /** 받은 blob 을 파일로 저장한다 — a[download] 한 번 누르고 객체 URL 을 되돌린다. */
  function saveBlob(blob, name) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.setAttribute('href', url);
    a.setAttribute('download', typeof name === 'string' && name !== '' ? name : 'download');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  if (execForm) {
    execForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (execTarget === null || !UUID_RE.test(execTarget.id)) {
        setText(execMsg, GENERIC_ERROR);
        return;
      }
      var target = execTarget;
      // 서버가 받는 키는 이 둘뿐이다 — 모르는 키가 하나라도 있으면 400 이므로
      // 화면도 같은 형태만 만든다 (share 폼과 같은 규칙).
      var payload = {
        password: execPassword ? execPassword.value : '',
        otp: execOtp ? execOtp.value : ''
      };

      setText(execMsg, '');
      if (execSubmit) {
        execSubmit.disabled = true;
      }

      writeFetch(APP_BASE + '/api/files/' + target.id + '/exec-download', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(function (response) {
          // 보낸 값은 응답을 기다리는 동안에도 남겨 두지 않는다.
          clearExecFields();
          if (response.status === 401) {
            toLogin();
            return null;
          }
          if (response.status === 200) {
            return response.blob().then(function (blob) {
              saveBlob(blob, target.name);
              closeExecDownload();
              return null;
            });
          }
          // 400·403·423 문구는 **서버가 준 것만** 쓴다 — 「비밀번호가 틀렸나 코드가
          // 틀렸나」를 브라우저가 추측해 알려 주지 않는다 (서버도 갈라 주지 않는다).
          return readJson(response).then(function (data) {
            setText(execMsg, errorMessage(data));
            if (execSubmit) {
              execSubmit.disabled = false;
            }
            return null;
          });
        })
        .catch(function () {
          clearExecFields();
          setText(execMsg, GENERIC_ERROR);
          if (execSubmit) {
            execSubmit.disabled = false;
          }
        });
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

    /* F-54 (PRD F-54 ③, ARCH 5.28) — 검사 전(pending)·격리(infected) 파일은 받지도 미리
       보지도 못한다. 화면은 단추를 **비활성**으로 두고 왜인지를 title 로 알린다.
       이것은 편의일 뿐이다 — 주소를 직접 쳐도 서버가 403 으로 막는다 (PRD 10장). */
    var scanBlock = scanBadgeFor(file.scan_status);

    // 서버가 준 ID 가 UUID 형태일 때만 링크를 만든다(주소에 예상 밖의 문자가 들어가지 않게).
    if (UUID_RE.test(file.id)) {
      // 「미리보기」는 **모든 행**에 만든다 — 지원하지 않는 형식이면 서버가 kind:'none' 을 주고
      // 정보 카드만 나온다. preview_kind 는 마우스 올림 문구(title)의 참고용일 뿐이고,
      // 무엇을 보여 줄지는 서버 응답이 정한다 (ARCH 5.8).
      var kindLabel = Object.prototype.hasOwnProperty.call(PREVIEW_KIND_LABELS, file.preview_kind)
        ? PREVIEW_KIND_LABELS[file.preview_kind]
        : '';
      var previewButton = makeIconButton('eye', kindLabel === '' ? '미리보기' : '미리보기 — ' + kindLabel);
      if (scanBlock !== null) {
        previewButton.disabled = true;
        previewButton.setAttribute('aria-label', scanBlock.title);
      } else {
        previewButton.addEventListener('click', function () {
          openPreview(file);
        });
      }
      td.appendChild(previewButton);

      if (scanBlock !== null) {
        /* 막힌 동안에는 **주소가 없는 단추**를 둔다. <a> 에는 disabled 가 없어(클릭이 그대로
           나간다) 링크 대신 button 으로 바꾼다 — 화면에서 사라지지는 않게 해서 "왜 못 받나" 가
           title 로 보이게 한다. */
        var blockedDownload = makeIconButton('download', scanBlock.title);
        blockedDownload.disabled = true;
        td.appendChild(blockedDownload);
      } else if (file.exec_content === true) {
        /* F-65 — 「실행 파일 포함」 파일은 **링크가 아니라 단추**다. 링크(`<a href>`)는
           누르는 순간 GET 이 나가는데 그 창구는 이 파일에 대해 403 이다(ARCH 5.29 「받기」).
           단추는 확인 창을 열고, 거기서 비밀번호·코드를 넣어 POST 로 받는다.
           단추가 아니라 주소를 직접 쳐도 서버가 같은 403 을 준다 (PRD S-01). */
        var execButton = makeIconButton('download', '확인하고 받기 — ' + EXEC_BADGE.label);
        execButton.addEventListener('click', function () {
          openExecDownload(file);
        });
        td.appendChild(execButton);
      } else {
        var link = document.createElement('a');
        link.setAttribute('href', APP_BASE + '/api/files/' + file.id + '/download');
        link.className = 'btn btn--icon';
        link.setAttribute('aria-label', '받기');
        link.appendChild(makeIcon('download'));
        td.appendChild(link);
      }

      // 수정 버튼은 서버가 그 행에 대해 can_edit 를 참으로 준 경우에만 만든다.
      if (file.can_edit === true) {
        var editButton = makeIconButton('edit', '수정');
        editButton.addEventListener('click', function () {
          editFile(file);
        });
        td.appendChild(editButton);

        /* 15-2 — 「공유 링크」도 **같은 can_edit** 로 갈린다: 링크를 만들 수 있는 사람은
           그 파일을 고칠 수 있는 사람이다 (PRD F-13). 화면이 역할·소유자를 따지지 않는다.
           단추가 보이지 않아도, 콘솔에서 직접 POST 해도 서버가 다시 판단한다 (PRD S-01).
           ★ F-65 — 「실행 파일 포함」 파일에는 이 단추를 **만들지 않는다**. 공유 링크는
             로그인하지 않은 사람이 OTP 없이 받는 길이라 서버가 400 으로 막는다
             (ARCH 5.29 「받기」 표). 단추를 지우는 것은 편의이고 근거는 서버다. */
        if (file.exec_content !== true) {
          var shareButton = makeIconButton('link', '공유 링크 만들기');
          shareButton.addEventListener('click', function () {
            openShare(file);
          });
          td.appendChild(shareButton);
        }

        /* 18-1 — 「폴더로 이동」도 **같은 can_edit** 로 갈린다 (PRD F-26: 파일을 옮기는 것은
           그 파일을 고칠 수 있는 사람). 단추가 보이든 안 보이든 서버가 다시 판단하고,
           대상 폴더를 읽을 수 있는지·불변 규칙 ①까지 서버가 본다. */
        var moveButton = makeIconButton('files', '폴더로 이동');
        moveButton.addEventListener('click', function () {
          openMove(file);
        });
        td.appendChild(moveButton);
      }

      // 삭제 버튼도 서버가 그 행에 대해 can_delete 를 참으로 준 경우에만 만든다.
      // 화면이 역할·소유자를 따져서 정하지 않는다 — 판단은 서버가 이미 했다 (PRD S-01, 10장).
      if (file.can_delete === true) {
        var button = makeIconButton('trash', '지우기');
        button.addEventListener('click', function () {
          deleteFile(file);   // U-2a — 확인 창을 먼저 띄운다(요청은 확인 뒤에만)
        });
        td.appendChild(button);
      }
    }

    row.appendChild(td);
  }

  /**
   * 표의 한 줄 — 「파일」 보기와 「즐겨찾기」·「최근」 보기가 같은 줄을 그린다(개발 대기열 6 — 전에는 renderFiles 안에 있던 몸통).
   * showFolder 가 참이면 「폴더」 경로 칸을 넣는다(전역 검색·즐겨찾기·최근). whenValue 는 시각 칸의 값(올린 때 또는 마지막으로 연 때).
   */
  function fileRow(file, showFolder, whenValue) {
    var row = document.createElement('tr');
    // 15-3 — 「민감」 배지는 서버가 준 sensitive 불리언만 보고 붙인다 (PRD F-22).
    // F-54 — 「검사 중」·「격리됨」 배지도 서버가 준 scan_status 하나만 보고 붙인다.
    // F-65 — 「실행 파일 포함」 배지도 서버가 준 exec_content 하나만 보고 붙인다.
    var nameCell = addNameCell(row, file.name, file.sensitive === true, file.scan_status, file.exec_content === true);
    // 개발 대기열 6 — 이름 앞의 별(즐겨찾기 넣기·빼기). 행 동작 칸(보기→받기→…→삭제, DESIGN 2.1)은 그대로 둔다.
    var star = makeFileStar(file);
    if (star !== null) {
      nameCell.insertBefore(star, nameCell.firstChild);
    }
    // 라운드 B — 줄 체크(일괄 작업 고르기)는 별보다 앞 · 이름 칸 안(열을 늘리지 않는다 — DESIGN 2.4 폭 규칙 그대로)
    var check = makeRowCheck(file);
    if (check !== null) {
      nameCell.insertBefore(check, nameCell.firstChild);
    }
    addCell(row, formatSize(file.size_bytes));
    addLevelCell(row, file.access_level);
    addCell(row, file.team_name === null || file.team_name === undefined ? '—' : file.team_name);
    if (showFolder) {
      // 서버가 준 folder_path 그대로. 루트면 「—」 (경로는 표시용이며 권한과 무관하다).
      addCell(row, typeof file.folder_path === 'string' && file.folder_path !== ''
        ? file.folder_path
        : '—');
    }
    addCell(row, file.uploaded_by);
    addWhenCell(row, whenValue);
    addCell(row, file.description);
    addActions(row, file);
    return row;
  }

  /* ─── 즐겨찾기 ☆ (개발 대기열 6 · 지시서 1.1) ────────────────────────
     별은 `.btn--icon`(makeIconButton — aria-label = 툴팁 글, DESIGN 2.1·2.8)이고 모양 둘(빈 별 · 찬 별)은 home.php 의
     <template> 이다. 눌린 상태는 aria-pressed. 넣을 수 있는지는 창구가 다시 판단한다 — 못 읽는 파일이면 404 문구를 그대로 보인다. */
  function starLabel(on) {
    return on ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기';
  }

  function paintStar(button, on) {
    button.replaceChildren(makeIcon(on ? 'star-fill' : 'star'));
    button.setAttribute('aria-label', starLabel(on));
    button.setAttribute('aria-pressed', on ? 'true' : 'false');
  }

  function makeFileStar(file) {
    if (!file || !UUID_RE.test(file.id)) {
      return null;
    }
    var button = makeIconButton('star', starLabel(false));
    button.setAttribute('data-fav-file', file.id);
    paintStar(button, favFiles[file.id] === true);
    button.addEventListener('click', function () {
      toggleFileFavorite(file, button);
    });
    return button;
  }

  /** 즐겨찾기 창구 부르기 — 401 이면 로그인으로(null), 아니면 {ok, status, data}. 주소는 부르는 쪽이 `APP_BASE + '/…'` 로 만든다(Q:T-4) */
  function favFetch(method, url) {
    var options = { method: method, credentials: 'same-origin', cache: 'no-store' };
    if (method !== 'GET') {
      options.headers = csrfHeaders();
    }
    return (method === 'GET' ? fetch(url, options) : writeFetch(url, options)).then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      return readJson(response).then(function (data) {
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }

  function toggleFileFavorite(file, button) {
    var on = favFiles[file.id] === true;
    button.disabled = true;
    setText(msg, '');
    showDone('');
    favFetch(on ? 'DELETE' : 'PUT', APP_BASE + '/api/files/' + file.id + '/favorite')
      .then(function (result) {
        button.disabled = false;
        if (result === null) {
          return;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return;
        }
        if (on) {
          delete favFiles[file.id];
        } else {
          favFiles[file.id] = true;
        }
        paintStar(button, !on);
        // 「즐겨찾기」 보기에서 뺐으면 그 줄이 목록에서 사라져야 한다 — 창구의 답으로 다시 그린다
        if (state.view === 'favorites') {
          loadFiles();
        }
      })
      .catch(function () {
        button.disabled = false;
        setText(msg, GENERIC_ERROR);
      });
  }

  /** 즐겨찾기 목록 둘(파일·폴더)을 받아 별 표시용 표를 새로 만든다. 401 이면 null. */
  function loadFavoriteLists() {
    return Promise.all([favFetch('GET', APP_BASE + '/api/files/favorites'), favFetch('GET', APP_BASE + '/api/folders/favorites')])
      .then(function (res) {
        if (res[0] === null || res[1] === null) {
          return null;
        }
        if (!res[0].ok) {
          setText(msg, errorMessage(res[0].data));
        }
        var files = res[0].ok && res[0].data && Array.isArray(res[0].data.files) ? res[0].data.files : [];
        var folders = res[1].ok && res[1].data && Array.isArray(res[1].data.folders) ? res[1].data.folders : [];
        favFiles = {};
        favFolders = {};
        files.forEach(function (f) {
          if (f && typeof f.id === 'string') {
            favFiles[f.id] = true;
          }
        });
        folders.forEach(function (d) {
          if (d && typeof d.id === 'number') {
            favFolders[d.id] = true;
          }
        });
        return { files: files, folders: folders };
      });
  }

  /** 지금 표의 별과 폴더 단추를 표에 맞춘다(목록을 새로 받은 뒤) */
  function syncStars() {
    var stars = body ? body.querySelectorAll('button[data-fav-file]') : [];
    var i;
    for (i = 0; i < stars.length; i++) {
      paintStar(stars[i], favFiles[stars[i].getAttribute('data-fav-file')] === true);
    }
    syncFolderFav();
  }

  /** 지금 연 폴더의 「즐겨찾기에 넣기/빼기」 단추 — 루트에는 없다(폴더 동작 줄째 숨는다) */
  function syncFolderFav() {
    if (!folderFavBtn) {
      return;
    }
    var on = state.folder !== 0 && favFolders[state.folder] === true;
    folderFavBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
    setText(folderFavLabel, on ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기');
    var icon = folderFavBtn.querySelector('.icon');
    var fresh = makeIcon(on ? 'star-fill' : 'star');
    if (icon) {
      folderFavBtn.replaceChild(fresh, icon);
    } else {
      folderFavBtn.insertBefore(fresh, folderFavBtn.firstChild);
    }
  }

  function toggleFolderFavorite() {
    var id = state.folder;
    if (typeof id !== 'number' || id <= 0) {
      return;
    }
    var on = favFolders[id] === true;
    folderFavBtn.disabled = true;
    setText(folderMsg, '');
    favFetch(on ? 'DELETE' : 'PUT', APP_BASE + '/api/folders/' + String(id) + '/favorite')
      .then(function (result) {
        folderFavBtn.disabled = false;
        if (result === null) {
          return;
        }
        if (!result.ok) {
          setText(folderMsg, errorMessage(result.data));
          return;
        }
        if (on) {
          delete favFolders[id];
        } else {
          favFolders[id] = true;
        }
        syncFolderFav();
      })
      .catch(function () {
        folderFavBtn.disabled = false;
        setText(folderMsg, GENERIC_ERROR);
      });
  }

  /** 「즐겨찾기」 보기의 폴더 묶음 — 이름(경로)을 누르면 그 폴더를 「파일」 보기로 연다 */
  function drawFavoriteFolders(folders) {
    if (!favFoldersList || !favFoldersWrap) {
      return;
    }
    favFoldersList.replaceChildren();
    folders.forEach(function (folder) {
      if (!folder || typeof folder.id !== 'number') {
        return;
      }
      var li = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'foldertree__item';
      var name = document.createElement('span');
      name.className = 'foldertree__name';
      setText(name, typeof folder.path === 'string' && folder.path !== '' ? folder.path : folder.name);
      button.appendChild(name);
      var meta = document.createElement('span');
      meta.className = 'foldertree__meta';
      var badge = makeLevelBadge(folder.access_level);
      if (badge) {
        meta.appendChild(badge);
      }
      if (typeof folder.team_name === 'string' && folder.team_name !== '') {
        var team = document.createElement('span');
        team.className = 'foldertree__team';
        setText(team, folder.team_name);
        meta.appendChild(team);
      }
      button.appendChild(meta);
      button.addEventListener('click', function () {
        goToFolder(folder.id);
      });
      li.appendChild(button);
      favFoldersList.appendChild(li);
    });
    favFoldersWrap.hidden = folders.length === 0;
  }

  /** 「즐겨찾기」·「최근」 보기의 표 — 쪽 나눔이 없다(창구가 200 · 20 까지만 준다). 개수는 **보이는 줄 수**뿐이다 */
  function drawPlainList(files, label, whenKey) {
    clearRows();
    if (filesFolderHead) {
      filesFolderHead.hidden = false;
    }
    files.forEach(function (file) {
      body.appendChild(fileRow(file, true, whenKey === 'last_used_at' ? file.last_used_at : file.created_at));
    });
    if (empty) {
      empty.hidden = files.length !== 0;
    }
    setText(pageInfo, label + ' ' + files.length + '건');
    syncBulkUi();   // 라운드 B — 머리 체크를 새 줄에 맞춘다
  }

  function loadFavoritesView() {
    var seq = ++loadSeq;
    loadFavoriteLists()
      .then(function (lists) {
        if (lists === null || seq !== loadSeq || state.view !== 'favorites') {
          return;
        }
        drawPlainList(lists.files, '즐겨찾기', 'created_at');
        drawFavoriteFolders(lists.folders);
        syncFolderFav();
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  function loadRecentView() {
    var seq = ++loadSeq;
    favFetch('GET', APP_BASE + '/api/files/recent')
      .then(function (result) {
        if (result === null || seq !== loadSeq || state.view !== 'recent') {
          return;
        }
        if (!result.ok) {
          clearRows();
          setText(msg, errorMessage(result.data));
          return;
        }
        drawPlainList(result.data && Array.isArray(result.data.files) ? result.data.files : [], '최근', 'last_used_at');
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /** 보기 바꾸기 — 고른 단추는 테두리 있는 outline, 나머지는 테두리를 걷는다(새 클래스 0) */
  function setView(view) {
    if (view !== 'files' && view !== 'favorites' && view !== 'recent') {
      return;
    }
    state.view = view;
    Object.keys(viewButtons).forEach(function (key) {
      var button = viewButtons[key];
      if (!button) {
        return;
      }
      var on = key === view;
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
      button.classList.toggle('btn--plain', !on);
    });
    if (searchForm) {
      searchForm.hidden = view !== 'files';
    }
    if (favFoldersWrap && view !== 'favorites') {
      favFoldersWrap.hidden = true;
    }
    if (prev) {
      prev.hidden = view !== 'files';
    }
    if (next) {
      next.hidden = view !== 'files';
    }
    setText(whenHead, view === 'recent' ? '마지막으로 연 때' : '올린 시각');
    setText(empty, EMPTY_TEXT[view]);
    setText(msg, '');
    showDone('');
    bulkClear();   // 라운드 B (M-4) — 보기를 바꾸면 고른 것이 풀린다
    loadFiles();
  }

  function renderFiles(data) {
    clearRows();
    var files = data && Array.isArray(data.files) ? data.files : [];
    /* 18-1 — 이번 응답이 전역 검색인가는 **서버가 준 scope** 로만 판단한다.
       참이면 결과가 여러 폴더에 흩어져 있으므로 「폴더」 경로 열을 보여 준다. */
    var searching = !!(data && data.scope === 'all');
    if (filesFolderHead) {
      filesFolderHead.hidden = !searching;
    }
    /* 18-1 — 지금 연 폴더와 경로는 **서버가 준 값**이다. 화면이 계산하지 않는다.
       18-3 — 다만 전역 검색(scope === 'all')은 폴더를 가리지 않은 응답이라 `folder` 가
       언제나 루트다. 그것으로 덮어쓰면 열어 둔 폴더(state.folder)와 「올릴 위치」 글자가
       어긋나므로(= 화면이 거짓말을 하므로) 검색 응답에서는 경로를 그대로 둔다. */
    if (!searching) {
      if (data && data.folder && typeof data.folder === 'object') {
        state.folderInfo = data.folder;
        state.crumbs = Array.isArray(data.folder.breadcrumb) ? data.folder.breadcrumb : [];
      } else {
        state.folderInfo = null;
        state.crumbs = [];
      }
    }
    renderCrumbs();
    syncFolderActions();
    // 18-3 — 폴더를 옮기면 대화상자가 열려 있어도 「올릴 위치」와 등급 선택지가 따라간다.
    syncUploadWhere();
    state.total = typeof data.total === 'number' ? data.total : 0;
    state.perPage = typeof data.per_page === 'number' && data.per_page > 0 ? data.per_page : 20;
    state.page = typeof data.page === 'number' && data.page > 0 ? data.page : 1;

    files.forEach(function (file) {
      body.appendChild(fileRow(file, searching, file.created_at));
    });
    showFresh();

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
    syncBulkUi();   // 라운드 B — 쪽을 넘겨도 고른 것은 남는다(M-4). 이 쪽의 체크·머리 체크만 새로 맞춘다
  }

  /** 지금 보기의 목록을 다시 받는다 — 올리기·지우기·고치기·옮기기 뒤의 새로 고침이 모두 여기를 부른다 */
  function loadFiles() {
    if (state.view === 'favorites') {
      loadFavoritesView();
      return;
    }
    if (state.view === 'recent') {
      loadRecentView();
      return;
    }
    loadFolderFiles();
  }

  function loadFolderFiles() {
    var seq = ++loadSeq;
    var query =
      '?q=' + encodeURIComponent(state.q) +
      '&sort=' + encodeURIComponent(state.sort) +
      '&dir=' + encodeURIComponent(state.dir) +
      '&page=' + encodeURIComponent(String(state.page));

    /* 18-1 — 검색어가 있으면 `folder` 를 **아예 보내지 않는다** → 서버가 폴더를 가리지 않는
       전역 검색으로 답한다(폴더 안에 갇힌 검색은 찾기 기능이 아니다). 검색어가 없으면
       지금 연 폴더(0 = 루트)를 보낸다. 보이는 범위는 어느 쪽이든 서버의 visibleFilesWhere
       하나가 정하고, 화면은 그 결과만 그린다 (PRD S-02). */
    if (state.q === '') {
      query += '&folder=' + encodeURIComponent(String(state.folder));
    }

    fetch(APP_BASE + '/api/files' + query, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          // 개발 대기열 6 — 그 사이 다른 보기로 바뀌었으면 옛 응답으로 표를 덮지 않는다
          if (seq !== loadSeq || state.view !== 'files') {
            return null;
          }
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

  /* ─── 일괄 작업 (개발 대기열 6 · Q:F-3a 라운드 B — 지시서 1.3 · 결정 M-4 · M-7) ─────────────────────
   *
   * 원칙: **새 서버 창구 0** — 한 건 작업과 같은 창구를 항목마다 한 번씩, 차례로 부른다(F-27 일괄 올리기와 같은 방식).
   *   · 할 수 있는지는 창구가 **항목마다** 판단한다. 화면은 can_delete·can_edit 로 미리 거르지 않는다 — 남의 파일을 섞어 골라도
   *     보내고, 창구가 준 거부 문장(F-68)을 그 줄에 그대로 적는다. 판정·감사·거부 문장이 한 건 작업과 같다(QF3A-T6, PRD S-01).
   *   · 고르기: 줄 체크(이름 칸 맨 앞) · 머리 체크(이 쪽 전부) · Shift+클릭(범위) · 쪽을 넘겨도 유지 · 100개까지 ·
   *     폴더·보기·검색을 바꾸면 풀림(M-4) · Esc 로 풀기. 고른 동안은 검색 줄 자리에 선택 막대가 온다.
   *   · 진행: 앞 응답을 받은 뒤 다음(한 번에 하나). 줄마다 대기 → 하는 중 → 완료/거부 문장.
   *       401          멈추고 **남은 줄을 그대로 둔다** → 새 탭에서 로그인한 뒤 「이어서 하기」 — `/api/me` 를 새로 물어 **같은 계정**
   *                    인지 보고, 새 CSRF 토큰으로 그 줄부터 이어 간다(다른 계정이면 잇지 않는다 — QF3A-T7).
   *       403 reauth   15분 재인증 창이 닫혀 막힘 → 재인증 창을 띄우고 통과하면 그 줄부터(닫으면 멈춤 + 「이어서 하기」).
   *       403 그 밖    구간(시작 · 이어 가기)의 첫 403 에서 `/api/me` 로 세션이 그대로인지 본다 — 그대로면 거부, 바뀌었으면 멈춤
   *                    (다른 탭에서 다시 로그인하면 이 탭의 토큰이 옛 세션 것이라 줄마다 403 이 된다 — 거부로 세지 않는다).
   *       「이어서 하기」 멈춘 까닭과 상관없이 보내기 전에 `/api/me` — 같은 계정일 때만 새 토큰(화면 · 셸)으로 잇는다.
   *       423          그 줄만 「나중에」(검사 중) — 나머지는 계속.
   *   · 휴지통으로 보낸 뒤 10초 「되돌리기」(M-7 — Q:F-4 U-2b 본인 복구가 들어왔다): 옮긴 파일마다 `POST …/restore` 한 번씩.
   *     되살릴 수 있는지도 창구가 판단한다(남의 파일은 관리자만 — 그 줄에 거부 문장).
   *   · 글자는 전부 textContent(서버가 준 이름·거부 문장 포함) · 인라인 style 0 · 새 클래스 0.
   */
  var BULK_PICK_MAX = 100;       // 결정 M-4 — 한 번에 고를 수 있는 파일 수
  var BULK_UNDO_MS = 10000;      // 결정 M-7 — 「되돌리기」가 보이는 시간
  var bulkBar = document.getElementById('files-bulk-bar');
  var bulkCountEl = document.getElementById('files-bulk-count');
  var bulkNote = document.getElementById('files-bulk-note');
  var bulkZipBtn = document.getElementById('files-bulk-zip');   // 라운드 C — zip 으로 받기
  var bulkMsg = document.getElementById('files-bulk-msg');       // 라운드 C — 막대 동작의 거부 문장(막대 바로 아래 · .msg)
  var bulkLevelBtn = document.getElementById('files-bulk-level');
  var bulkMoveBtn = document.getElementById('files-bulk-move');
  var bulkFavBtn = document.getElementById('files-bulk-fav');
  var bulkTrashBtn = document.getElementById('files-bulk-trash');
  var bulkClearBtn = document.getElementById('files-bulk-clear');
  var checkAll = document.getElementById('files-check-all');
  var bulkPanel = document.getElementById('files-bulk-panel');
  var bulkTitle = document.getElementById('files-bulk-title');
  var bulkBody = document.getElementById('files-bulk-body');
  var bulkStop = document.getElementById('files-bulk-stop');
  var bulkStopText = document.getElementById('files-bulk-stop-text');
  var bulkLogin = document.getElementById('files-bulk-login');
  var bulkResume = document.getElementById('files-bulk-resume');
  var bulkSummary = document.getElementById('files-bulk-summary');
  var bulkUndo = document.getElementById('files-bulk-undo');
  var bulkClose = document.getElementById('files-bulk-close');
  var bulkLevelDialog = document.getElementById('bulk-level-dialog');
  var bulkLevelCount = document.getElementById('bulk-level-count');
  var bulkLevelForm = document.getElementById('bulk-level-form');
  var bulkLevelSelect = document.getElementById('bulk-level-select');
  var bulkTeamField = document.getElementById('bulk-team-field');
  var bulkTeamSelect = document.getElementById('bulk-team-select');
  var bulkLevelMsg = document.getElementById('bulk-level-msg');

  /** 고른 것 — 고른 순서의 id 와 id → 목록 응답의 줄(이름 표시용). 쪽을 넘겨도 남는다. */
  var bulk = { order: [], files: {}, anchor: null, job: null, username: null, undoTimer: null, undoItems: null, full: false, zipBusy: false };
  /** 지금 표에 그린 줄 [{file, check}] — 머리 체크(이 쪽 전부)와 Shift+클릭 범위가 본다(clearRows 가 비운다) */
  var pageRows = [];

  function bulkHas(id) {
    return Object.prototype.hasOwnProperty.call(bulk.files, id);
  }

  /** 하나 더 고른다 — 100개면 고르지 않고 false */
  function bulkAdd(file) {
    if (bulkHas(file.id)) {
      return true;
    }
    if (bulk.order.length >= BULK_PICK_MAX) {
      bulk.full = true;
      return false;
    }
    bulk.order.push(file.id);
    bulk.files[file.id] = file;
    return true;
  }

  function bulkRemove(id) {
    if (!bulkHas(id)) {
      return;
    }
    delete bulk.files[id];
    bulk.order = bulk.order.filter(function (x) { return x !== id; });
  }

  /** 고른 것을 모두 푼다 — 폴더·보기·검색이 바뀔 때 · 「선택 풀기」 · Esc */
  function bulkClear() {
    bulk.order = [];
    bulk.files = {};
    bulk.anchor = null;
    bulk.full = false;
    syncBulkUi();
  }

  /** 줄 체크 하나 — 이름 칸 맨 앞. 화면 읽기용 이름은 「이름」 고르기 */
  function makeRowCheck(file) {
    if (!file || !UUID_RE.test(file.id)) {
      return null;
    }
    var check = document.createElement('input');
    check.type = 'checkbox';
    check.setAttribute('data-bulk', '');
    check.setAttribute('data-bulk-file', file.id);
    check.setAttribute('aria-label', '「' + String(file.name === null || file.name === undefined ? '' : file.name) + '」 고르기');
    check.checked = bulkHas(file.id);
    var entry = { file: file, check: check };
    pageRows.push(entry);
    // click 에서 받는다 — change 에는 Shift 가 실리지 않는다. 이 때 check.checked 는 이미 바뀐 뒤의 값이다.
    check.addEventListener('click', function (event) {
      var want = check.checked;
      bulk.full = false;
      var from = -1;
      var to = pageRows.indexOf(entry);
      if (event.shiftKey && bulk.anchor !== null) {
        pageRows.forEach(function (r, i) {
          if (r.file.id === bulk.anchor) {
            from = i;
          }
        });
      }
      var range = from < 0 ? [entry] : pageRows.slice(Math.min(from, to), Math.max(from, to) + 1);
      range.forEach(function (r) {
        if (want) {
          bulkAdd(r.file);
        } else {
          bulkRemove(r.file.id);
        }
      });
      bulk.anchor = file.id;
      syncBulkUi();
    });
    return check;
  }

  /** 체크·머리 체크·선택 막대를 고른 것에 맞춘다 */
  function syncBulkUi() {
    var n = bulk.order.length;
    var onPage = 0;
    pageRows.forEach(function (r) {
      var on = bulkHas(r.file.id);
      r.check.checked = on;
      if (on) {
        onPage += 1;
      }
    });
    if (checkAll) {
      checkAll.checked = pageRows.length > 0 && onPage === pageRows.length;
      checkAll.indeterminate = onPage > 0 && onPage < pageRows.length;
      checkAll.disabled = pageRows.length === 0;
    }
    var busy = bulk.job !== null && !bulk.job.finished;
    setText(bulkCountEl, n + '개 선택됨');
    // 알림은 막대 아래 한 줄 — 막대는 폰 폭에서도 한 줄로 둔다(개수 + 아이콘 단추)
    var note = '';
    if (bulk.full) {
      note = '한 번에 ' + BULK_PICK_MAX + '개까지 고를 수 있습니다 — 더 고르려면 먼저 몇 개를 푸세요.';
    } else if (bulk.zipBusy && n > 0) {
      note = 'zip 을 만드는 중입니다 — 파일이 많거나 크면 시간이 걸립니다.';
    } else if (busy && n > 0) {
      note = '앞 작업이 끝나면 고른 파일로 할 수 있습니다.';
    }
    setText(bulkNote, note);
    if (bulkNote) {
      bulkNote.hidden = note === '' || n === 0;
    }
    [bulkLevelBtn, bulkMoveBtn, bulkFavBtn, bulkTrashBtn].forEach(function (b) {
      if (b) {
        b.disabled = busy;
      }
    });
    if (bulkZipBtn) {
      bulkZipBtn.disabled = busy || bulk.zipBusy;   // zip 은 한 번에 하나(서버도 사람마다 하나 — 429)
    }
    if (n === 0) {
      setText(bulkMsg, '');   // 고른 것을 모두 풀면 막대의 거부 문장도 지운다
    }
    if (bulkFavBtn) {
      // 「즐겨찾기」 보기에서는 빼기, 그 밖에서는 넣기 — 아이콘도 따라간다(찬 별 = 빼기)
      var off = state.view === 'favorites';
      bulkFavBtn.setAttribute('aria-label', off ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기');
      bulkFavBtn.replaceChildren(makeIcon(off ? 'star-fill' : 'star'));
    }
    if (bulkBar) {
      bulkBar.hidden = n === 0;
    }
    // 고른 동안은 검색 줄 자리에 선택 막대가 온다(검색은 「파일」 보기에서만 보인다 — setView 와 같은 규칙)
    if (searchForm) {
      searchForm.hidden = n > 0 || state.view !== 'files';
    }
  }

  /** 고른 것을 작업 항목으로 — {id, name}. 고른 순서 그대로 */
  function bulkItems() {
    return bulk.order.map(function (id) {
      var f = bulk.files[id];
      return { id: id, name: String(f && f.name !== null && f.name !== undefined ? f.name : '') };
    });
  }

  /** 창구 부르기 — 응답을 그대로 돌려준다(401·403·423 갈래는 진행기가 가른다). 주소는 부르는 쪽이 `APP_BASE + '/…'` 로(Q:T-4) */
  function bulkFetch(method, url, payload) {
    var headers = { 'X-CSRF-Token': csrfToken };
    var options = { method: method, headers: headers, credentials: 'same-origin', cache: 'no-store' };
    if (payload !== undefined) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(payload);
    }
    return fetch(url, options);
  }

  function bulkSet(item, text, cls) {
    setText(item.stateCell, text);
    item.stateCell.className = cls ? 'upload-status__state ' + cls : 'upload-status__state';
  }

  /** 새 작업 — 진행 표를 새로 그리고 첫 줄부터 보낸다. 고른 것은 작업으로 넘어가며 풀린다 */
  function bulkStart(job) {
    if (!bulkPanel || !bulkBody || (bulk.job !== null && !bulk.job.finished)) {
      return;
    }
    bulkHideUndo();
    job.index = 0;
    job.finished = false;
    job.paused = null;
    job.sessionChecked = false;
    bulkBody.replaceChildren();
    job.items.forEach(function (item) {
      var row = document.createElement('tr');
      var nameCell = document.createElement('td');
      setText(nameCell, item.name);
      row.appendChild(nameCell);
      item.stateCell = document.createElement('td');
      row.appendChild(item.stateCell);
      bulkSet(item, '대기', '');
      item.result = 'wait';
      bulkBody.appendChild(row);
    });
    bulk.job = job;
    setText(bulkTitle, job.title + ' · ' + job.items.length + '개');
    bulkStop.hidden = true;
    setText(bulkSummary, '');
    bulkClose.disabled = true;
    bulkPanel.hidden = false;
    setText(msg, '');
    showDone('');
    bulkClear();
    bulkTitle.focus();
    bulkStep(job);
  }

  function bulkStep(job) {
    if (bulk.job !== job || job.paused !== null) {
      return;
    }
    if (job.index >= job.items.length) {
      bulkFinish(job);
      return;
    }
    var item = job.items[job.index];
    bulkSet(item, '하는 중…', 'is-busy');
    job.send(item)
      .then(function (response) {
        if (response.status === 401) {
          bulkPause(job, 'login');
          return 'stop';
        }
        return readJson(response).then(function (data) {
          if (response.status === 403 && data && data.next === 'reauth') {
            bulkPause(job, 'reauth');
            handleReauth(response, data, function () {
              bulkContinue(job);
            });
            return 'stop';
          }
          if (response.status === 403 && !job.sessionChecked) {
            // 이 구간의 첫 403 — 거부로 세기 전에 세션이 그대로인지 `/api/me` 로 본다. 다른 탭에서 로그아웃·다시 로그인했으면
            // 이 탭의 CSRF 토큰이 옛 세션 것이라 모든 줄이 403(토큰 불일치)이 된다 — 그건 거부가 아니라 멈출 일이다.
            job.sessionChecked = true;
            return bulkSessionCheck().then(function (why) {
              if (why !== null) {
                bulkPause(job, why);
                return 'stop';
              }
              item.result = 'deny';
              bulkSet(item, errorMessage(data), 'is-deny');   // 세션은 그대로 — 창구의 거부 문장 그대로(F-68)
              return 'next';
            });
          }
          if (response.ok) {
            item.result = 'ok';
            bulkSet(item, typeof job.okText === 'function' ? job.okText(data) : job.okText, 'is-ok');
          } else if (response.status === 423) {
            item.result = 'later';
            bulkSet(item, '나중에 — ' + errorMessage(data), 'is-deny');
          } else {
            item.result = 'deny';
            bulkSet(item, errorMessage(data), 'is-deny');   // 한 건 작업과 같은 서버 문장 그대로(F-68)
          }
          return 'next';
        });
      })
      .catch(function () {
        item.result = 'deny';
        bulkSet(item, GENERIC_ERROR, 'is-deny');
        return 'next';
      })
      .then(function (what) {
        if (what === 'next') {
          job.index += 1;
          bulkStep(job);
        }
      });
  }

  /**
   * 세션이 이 작업을 시작한 그대로인가 — `/api/me` 를 새로 묻는다. 그대로면 null, 아니면 멈출 까닭:
   *   'login'   세션이 없다(401) · 'other' 다른 계정으로 로그인됐다 · 'session' 같은 계정이지만 세션이 바뀌었다(토큰이 다르다) ·
   *   'check'   물어보지 못했다(네트워크) — 모르는 채 거부로 세지 않는다.
   * CSRF 토큰은 세션 id 로 계산되므로(Auth::csrfToken) 토큰이 다르면 세션이 바뀐 것이다.
   */
  function bulkSessionCheck() {
    return fetchMe(true).then(function (result) {
      if (result.status === 401 || result.me === null) {
        return 'login';
      }
      if (bulk.username === null || result.me.username !== bulk.username) {
        return 'other';
      }
      if (result.me.csrf_token !== csrfToken) {
        return 'session';
      }
      return null;
    }, function () {
      return 'check';
    });
  }

  /** 멈춤 — 남은 줄(지금 줄 포함)은 「대기」 그대로 두고 까닭과 「이어서 하기」를 보인다 */
  function bulkPause(job, why) {
    job.paused = why;
    var item = job.items[job.index];
    var left = job.items.length - job.index;
    if (why === 'login') {
      bulkSet(item, '멈춤 — 로그인이 필요합니다', 'is-deny');
      setText(bulkStopText, '로그인이 끝나 멈췄습니다 — 남은 ' + left + '개는 그대로 있습니다. 새 탭에서 다시 로그인한 뒤 「이어서 하기」를 누르세요.');
    } else if (why === 'other') {
      bulkSet(item, '멈춤 — 다른 계정으로 로그인됨', 'is-deny');
      setText(bulkStopText, '다른 계정으로 로그인했습니다 — 이어서 할 수 없습니다. 이 화면을 새로 고친 뒤 다시 고르세요.');
    } else if (why === 'session') {
      bulkSet(item, '멈춤 — 로그인이 바뀌었습니다', 'is-deny');
      setText(bulkStopText, '다른 탭에서 다시 로그인해 멈췄습니다 — 남은 ' + left + '개는 그대로 있습니다. 「이어서 하기」를 누르면 같은 계정인지 확인하고 이어 갑니다.');
    } else if (why === 'check') {
      bulkSet(item, '멈춤 — 로그인 상태를 확인하지 못했습니다', 'is-deny');
      setText(bulkStopText, '로그인 상태를 확인하지 못해 멈췄습니다 — 남은 ' + left + '개는 그대로 있습니다. 「이어서 하기」를 누르면 다시 확인합니다.');
    } else {
      bulkSet(item, '멈춤 — 비밀번호 확인이 필요합니다', 'is-deny');
      setText(bulkStopText, '비밀번호 확인이 필요해 멈췄습니다 — 남은 ' + left + '개는 그대로 있습니다. 「이어서 하기」를 누르면 다시 묻습니다.');
    }
    if (bulkLogin) {
      bulkLogin.hidden = why !== 'login';
    }
    if (bulkResume) {
      // 다른 계정이면 잇지 않는다 — 「이어서 하기」를 감춘다(누르면 어차피 같은 확인에서 막힌다)
      bulkResume.hidden = why === 'other';
      bulkResume.disabled = false;
    }
    bulkStop.hidden = false;
    bulkClose.disabled = false;
    setText(bulkSummary, bulkTally(job) + ' · 남은 ' + left + '개');
    syncBulkUi();
  }

  /** 멈춘 줄부터 다시 — 그 줄은 아직 처리되지 않았다(401·재인증은 핸들러 전에 막힌다) */
  function bulkContinue(job) {
    if (bulk.job !== job || job.paused === null) {
      return;
    }
    job.paused = null;
    job.sessionChecked = false;   // 이어 간 구간의 첫 403 도 다시 세션을 본다
    bulkStop.hidden = true;
    bulkClose.disabled = true;
    setText(bulkSummary, '');
    syncBulkUi();
    bulkStep(job);
  }

  /**
   * 「이어서 하기」 — 멈춘 까닭과 상관없이(로그인 · 재인증 · 세션 바뀜 · 확인 못 함) **보내기 전에** `/api/me` 를 새로 물어
   * 같은 계정인지 본다. 다른 계정이면 요청 0 으로 끝. 같은 계정이면 새 CSRF 토큰(이 화면 · 셸 둘 다)으로 멈춘 줄부터.
   * 재인증 멈춤이면 그 줄을 다시 보내 창구가 또 403 reauth 를 주고, 그때 재인증 창이 뜬다.
   */
  function bulkResumeClick() {
    var job = bulk.job;
    if (job === null || job.paused === null) {
      return;
    }
    bulkResume.disabled = true;
    fetchMe(true)
      .then(function (result) {
        bulkResume.disabled = false;
        if (bulk.job !== job || job.paused === null) {
          return;
        }
        if (result.status === 401 || result.me === null) {
          if (job.paused !== 'login') {
            bulkPause(job, 'login');   // 세션이 끝났다 — 로그인 멈춤으로 바꾼다(「로그인 화면 열기」가 보인다)
          }
          setText(bulkStopText, '아직 로그인되지 않았습니다 — 새 탭에서 로그인한 뒤 다시 「이어서 하기」를 누르세요. 남은 ' + (job.items.length - job.index) + '개는 그대로 있습니다.');
          return;
        }
        var me = result.me;
        if (bulk.username === null || me.username !== bulk.username) {
          // 다른 계정으로 로그인했다 — 그 계정으로 남의 작업을 잇지 않는다
          bulkPause(job, 'other');
          return;
        }
        if (typeof me.csrf_token === 'string') {
          csrfToken = me.csrf_token;   // 새 세션의 토큰 — 이 화면의 모든 요청(csrfHeaders·bulkFetch)이 이 값을 쓴다
          setSessionCsrf(me.csrf_token);   // 셸(재인증 창 · 로그아웃)도 같은 값으로
        }
        bulkContinue(job);
      })
      .catch(function () {
        bulkResume.disabled = false;
        setText(bulkStopText, GENERIC_ERROR);
      });
  }

  /** 「n개 완료 · m개 못 함」 (지시서 1.3 「끝나면」) — 못 함 = 거부 + 나중에 */
  function bulkTally(job) {
    var ok = 0;
    var no = 0;
    job.items.forEach(function (item) {
      if (item.result === 'ok') {
        ok += 1;
      } else if (item.result === 'deny' || item.result === 'later') {
        no += 1;
      }
    });
    return ok + '개 완료 · ' + no + '개 못 함';
  }

  function bulkFinish(job) {
    job.finished = true;
    bulkStop.hidden = true;
    bulkClose.disabled = false;
    var doneItems = job.items.filter(function (item) { return item.result === 'ok'; });
    var line = bulkTally(job);
    if (job.undo && doneItems.length > 0) {
      line += ' — ' + (BULK_UNDO_MS / 1000) + '초 안에 되돌릴 수 있습니다';
      bulkShowUndo(doneItems);
    }
    setText(bulkSummary, line);
    syncBulkUi();
    if (typeof job.done === 'function') {
      job.done(job);
    }
  }

  /* M-7 — 휴지통으로 옮긴 파일을 10초 동안 되돌린다: 파일마다 `POST /api/files/{id}/restore`(Q:F-4 U-2b 본인 복구 창구 그대로) */
  function bulkShowUndo(items) {
    if (!bulkUndo) {
      return;
    }
    bulkUndo.hidden = false;
    bulk.undoItems = items.map(function (item) { return { id: item.id, name: item.name }; });
    bulk.undoTimer = window.setTimeout(bulkHideUndo, BULK_UNDO_MS);
  }

  function bulkHideUndo() {
    if (bulk.undoTimer !== null) {
      window.clearTimeout(bulk.undoTimer);
      bulk.undoTimer = null;
    }
    if (bulkUndo) {
      bulkUndo.hidden = true;
    }
    bulk.undoItems = null;
  }

  function bulkUndoClick() {
    var items = bulk.undoItems;
    bulkHideUndo();
    if (!items || items.length === 0) {
      return;
    }
    bulkStart({
      title: '되돌리기 — 휴지통에서 되살리기',
      items: items,
      okText: '되살림',
      send: function (item) {
        return bulkFetch('POST', APP_BASE + '/api/files/' + item.id + '/restore', {});
      },
      done: function () {
        loadFiles();
        loadTree();
      }
    });
  }

  /* ─── zip 으로 받기 (Q:F-3a 라운드 C — 지시서 1.4 · 결정 M-5 · ARCH 5.34) ─────────────────────
   *
   * 고른 파일 id 를 **한 번에** `POST /api/files/zip {ids}` 로 보낸다 — 위 일괄 동작들(한 건 창구를 항목마다)과 다른 점은
   * 결과가 파일 **하나**여야 한다는 것뿐이다. 무엇을 넣을지는 서버가 파일마다 한 건 받기와 같은 판정(권한 · 검사 중 · 실행 파일이
   * 든 압축본)으로 정하고, 뺀 파일은 **개수만** 알려 준다(`X-Zip-Skipped` 머리 · 거부 본문의 `skipped`) — 이름·까닭은 서버 감사에만.
   * 화면은 미리 거르지 않는다(검사 중인 파일을 골라도 보낸다 — 빼는 것은 서버다, PRD S-01).
   * 받는 방법은 확인 다운로드(F-65)와 같은 fetch + blob + a[download](`saveBlob`) — CSRF 머리를 실어야 하는 POST 라 링크로는 못 받는다.
   * 고른 것은 그대로 둔다(파일이 바뀌지 않는다 — 이어서 다른 동작을 할 수 있다). 결과 한 줄은 `#files-ok`, 거부 문장은 막대 바로 아래
   * `#files-bulk-msg`(`.msg` — 서버 문장 그대로).
   */
  function zipNameFrom(disposition) {
    // 서버가 만든 ASCII 이름만 받는다(`t-box-YYYYMMDD-HHMMSS.zip`) — 모양이 다르면 고정 이름
    var m = (typeof disposition === 'string' ? disposition : '').match(/filename="([A-Za-z0-9._-]{1,100}\.zip)"/);
    return m ? m[1] : 't-box.zip';
  }

  function zipDoneText(count, skipped) {
    var line = (isFinite(count) && count > 0 ? count + '개를' : '고른 파일을') + ' zip 으로 받았습니다.';
    if (isFinite(skipped) && skipped > 0) {
      line += ' ' + skipped + '개는 받을 수 없어 뺐습니다 — 권한이 없거나 검사 중이거나 실행 파일이 든 압축본은 zip 에 넣지 않습니다.';
    }
    return line;
  }

  function bulkZip() {
    var ids = bulk.order.slice();
    if (ids.length === 0 || bulk.zipBusy || (bulk.job !== null && !bulk.job.finished)) {
      return;
    }
    bulk.zipBusy = true;
    setText(msg, '');
    setText(bulkMsg, '');
    showDone('');
    syncBulkUi();
    writeFetch(APP_BASE + '/api/files/zip', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ ids: ids }),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        if (response.status === 200) {
          var count = parseInt(response.headers.get('X-Zip-Count') || '', 10);
          var skipped = parseInt(response.headers.get('X-Zip-Skipped') || '', 10);
          var name = zipNameFrom(response.headers.get('Content-Disposition'));
          return response.blob().then(function (blob) {
            saveBlob(blob, name);
            showDone(zipDoneText(count, skipped));
            return null;
          });
        }
        // 400(100개 · 500MB) · 403(넣을 것 없음 — 뺀 개수가 문장 뒤에) · 429(만드는 중) · 503(공간) — 서버 문장 그대로(F-68).
        // 자리는 막대 바로 아래 한 줄(#files-bulk-msg) — 화면 끝 #msg 는 긴 목록 아래라 누른 사람에게 안 보인다
        return readJson(response).then(function (data) {
          var line = errorMessage(data);
          if (typeof data.skipped === 'number' && data.skipped > 0) {
            line += ' (뺀 파일 ' + data.skipped + '개)';
          }
          setText(bulkMsg, line);
          return null;
        });
      })
      .catch(function () {
        setText(bulkMsg, GENERIC_ERROR);
      })
      .then(function () {
        bulk.zipBusy = false;
        syncBulkUi();
      });
  }

  /* ── 동작 넷 — 고르기 → 창(확인·폴더·등급) → 진행 표 ── */
  function bulkTrash() {
    var items = bulkItems();
    if (items.length === 0) {
      return;
    }
    openConfirm({
      title: '파일 ' + items.length + '개를 휴지통으로 옮길까요?',
      lead: '고른 파일 ' + items.length + '개를 하나씩 휴지통으로 옮깁니다 — 30일 안에 되살릴 수 있습니다.',
      note: '내가 올린 파일은 「내 휴지통」에서, 다른 사람의 파일은 관리자가 되살립니다. 지울 수 없는 파일은 그 파일만 거부되고 이유가 진행 표에 남습니다.',
      okLabel: '휴지통으로 옮기기'
    }, function () {
      bulkStart({
        title: '휴지통으로 옮기기',
        items: items,
        okText: '휴지통으로 옮김',
        undo: true,
        send: function (item) {
          return bulkFetch('DELETE', APP_BASE + '/api/files/' + item.id);
        },
        done: function () {
          loadFiles();
          loadTree();
        }
      });
    });
  }

  function bulkFavorite() {
    var items = bulkItems();
    if (items.length === 0) {
      return;
    }
    var off = state.view === 'favorites';
    bulkStart({
      title: off ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기',
      items: items,
      okText: off ? '뺌' : '넣음',
      send: function (item) {
        return bulkFetch(off ? 'DELETE' : 'PUT', APP_BASE + '/api/files/' + item.id + '/favorite');
      },
      done: function () {
        loadFavoriteLists().then(function (lists) {
          if (lists !== null) {
            syncStars();
          }
        }).catch(function () {});
        if (state.view === 'favorites') {
          loadFiles();
        }
      }
    });
  }

  function openBulkLevel() {
    var items = bulkItems();
    if (!bulkLevelDialog || items.length === 0) {
      return;
    }
    setText(bulkLevelMsg, '');
    setText(bulkLevelCount, '고른 파일 ' + items.length + '개');
    syncBulkTeamField();
    bulkLevelDialog.showModal();
  }

  function syncBulkTeamField() {
    if (bulkTeamField && bulkLevelSelect) {
      bulkTeamField.hidden = bulkLevelSelect.value !== 'team';
    }
  }

  function submitBulkLevel(event) {
    event.preventDefault();
    var level = bulkLevelSelect ? bulkLevelSelect.value : '';
    if (level === '') {
      setText(bulkLevelMsg, LEVEL_PLACEHOLDER);
      return;
    }
    // 보내는 값은 고른 것 그대로 — 바꿀 자격·폴더 등급·팀은 창구가 파일마다 본다(한 건 수정과 같은 PATCH)
    var payload = { access_level: level };
    if (level === 'team') {
      payload.team_id = bulkTeamSelect ? bulkTeamSelect.value : '';
    }
    var items = bulkItems();
    if (bulkLevelDialog.open) {
      bulkLevelDialog.close();
    }
    bulkStart({
      title: '등급·팀 바꾸기 — ' + (Object.prototype.hasOwnProperty.call(LEVEL_BADGE_LABELS, level) ? LEVEL_BADGE_LABELS[level] : level),
      items: items,
      okText: '바꿈',
      send: function (item) {
        return bulkFetch('PATCH', APP_BASE + '/api/files/' + item.id, payload);
      },
      done: function () {
        loadFiles();
        loadTree();
      }
    });
  }

  /* ─── 폴더 트리 (18-1 — PRD F-26, ARCH 5.16) ─────────────────────────────
   *
   * 규칙
   *   · 트리는 `GET /api/folders?parent=` 로 **한 층씩** 받는다(전체를 한 번에 내리지 않는다).
   *   · 응답에 있는 폴더가 곧 **읽을 수 있는 폴더**다 — 화면이 걸러 내지 않는다 (PRD S-02).
   *   · 이름·등급·개수는 전부 textContent 다. 문자열을 HTML 로 바꾸는 API 는 없다 (PRD S-03).
   *   · 단추를 보일지는 서버가 준 값으로만 정하고, 실제 허용은 서버가 다시 판단한다 (PRD S-01).
   */

  /** 경로 표시(breadcrumb). 서버가 준 `folder.breadcrumb` 만 그린다. */
  function renderCrumbs() {
    if (!folderCrumbs) {
      return;
    }
    folderCrumbs.replaceChildren();

    var root = document.createElement('button');
    root.type = 'button';
    root.className = 'crumbs__item';
    setText(root, '맨 위');   // 디버깅 묶음 U2 낱말 표 — 「루트」 → 「맨 위」(커뮤니티와 같은 말)
    root.addEventListener('click', function () {
      goToFolder(0);
    });
    folderCrumbs.appendChild(root);

    state.crumbs.forEach(function (step, index) {
      var sep = document.createElement('span');
      sep.className = 'crumbs__sep';
      setText(sep, '›');
      folderCrumbs.appendChild(sep);

      var last = index === state.crumbs.length - 1;
      var item = document.createElement('button');
      item.type = 'button';
      item.className = last ? 'crumbs__item is-current' : 'crumbs__item';
      setText(item, step.name);
      item.addEventListener('click', function () {
        goToFolder(step.id);
      });
      folderCrumbs.appendChild(item);
    });
  }

  /** 지금 폴더에 쓸 수 있는 단추를 보일지 — 루트에는 이름 변경·등급 변경·삭제가 없다. */
  function syncFolderActions() {
    if (folderActions) {
      folderActions.hidden = state.folder === 0;
    }
    syncFolderFav();
  }

  /** 지금 열어 둔 폴더의 등급(루트면 null) — 새 폴더·업로드 기본값에 쓴다. */
  function currentFolderLevel() {
    return state.folderInfo && typeof state.folderInfo.access_level === 'string'
      ? state.folderInfo.access_level
      : null;
  }

  /** 지금 열어 둔 폴더의 팀(루트·팀 폴더가 아니면 null) — 서버가 준 값 그대로다. */
  function currentFolderTeamId() {
    return state.folderInfo && typeof state.folderInfo.team_id === 'number'
      ? state.folderInfo.team_id
      : null;
  }

  /** 한 층 아래 폴더 목록을 받아 그린다. */
  function loadTree() {
    setText(folderMsg, '');
    fetch(APP_BASE + '/api/folders?parent=' + encodeURIComponent(String(state.folder)), {
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
            state.children = [];
            renderTree();
            setText(folderMsg, errorMessage(data));
            return null;
          }
          state.children = data && Array.isArray(data.folders) ? data.folders : [];
          renderTree();
          return null;
        });
      })
      .catch(function () {
        setText(folderMsg, GENERIC_ERROR);
      });
  }

  function renderTree() {
    if (!folderList) {
      return;
    }
    folderList.replaceChildren();

    if (state.folder !== 0) {
      var upItem = document.createElement('li');
      var up = document.createElement('button');
      up.type = 'button';
      up.className = 'foldertree__item foldertree__item--up';
      setText(up, '.. 상위 폴더로');
      var parentId = state.crumbs.length >= 2 ? state.crumbs[state.crumbs.length - 2].id : 0;
      up.addEventListener('click', function () {
        goToFolder(parentId);
      });
      upItem.appendChild(up);
      folderList.appendChild(upItem);
    }

    state.children.forEach(function (folder) {
      var li = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'foldertree__item';
      var name = document.createElement('span');
      name.className = 'foldertree__name';
      setText(name, folder.name);
      button.appendChild(name);
      button.appendChild(folderMeta(folder));
      button.addEventListener('click', function () {
        goToFolder(folder.id);
      });
      li.appendChild(button);
      folderList.appendChild(li);
    });

    if (folderEmpty) {
      folderEmpty.hidden = state.children.length !== 0;
    }
  }

  function goToFolder(id) {
    state.folder = typeof id === 'number' && id >= 0 ? id : 0;
    state.page = 1;
    setText(msg, '');
    showDone('');   // U-2a — 다른 폴더로 가면 지난 결과 한 줄을 거둔다
    bulkClear();    // 라운드 B (M-4) — 폴더를 바꾸면 고른 것이 풀린다
    syncFolderFav();
    if (state.view !== 'files') {
      setView('files');   // 개발 대기열 6 — 즐겨찾기 폴더·트리에서 폴더를 고르면 「파일」 보기로 연다(목록은 setView 가 부른다)
    } else {
      loadFiles();
    }
    loadTree();
  }

  /* ─── 올릴 위치 = 지금 열어 둔 폴더 (18-3, 사용자 요청 2026-09-13) ──────────
   * 고르는 자리를 없앴다. 대화상자는 **지금 연 폴더**에만 올리고, 경로 글자는 서버가
   * `GET /api/files` 로 내려 준 `folder.breadcrumb` 의 이름을 이어 만든다(textContent).
   * 등급 선택지는 종전처럼 서버가 준 `upload_levels` ∩ (그 폴더 등급 이상)이다 —
   * 화면이 권한을 판단하지 않는다. 폴더 번호를 손으로 바꿔 보내도 서버가 읽기 판정과
   * 불변 규칙 ①(`Authz::fitsUnderFolder`)로 다시 막는다 (PRD S-01, ARCH 5.16).
   */

  /** 지금 연 폴더의 경로 글자 — 맨 위면 「맨 위」, 아니면 「A › B」 (크럼과 같은 글자) */
  function folderPathText() {
    if (state.crumbs.length === 0) {
      return '맨 위';
    }
    return state.crumbs.map(function (step) {
      return step.name;
    }).join(' \u203a ');
  }

  /** 두 대화상자의 「올릴 위치」 글자와 등급 선택지를 지금 폴더 기준으로 다시 그린다. */
  function syncUploadWhere() {
    var path = folderPathText();
    setText(uploadWhere, '올릴 위치: ' + path);
    setText(fetchWhere, '가져올 위치: ' + path);
    syncUploadLevels();
    syncFetchLevels();
  }

  /** 지금 연 폴더의 등급을 최소값·기본값으로 삼아 업로드 등급 선택지를 다시 만든다. */
  function syncUploadLevels() {
    if (!levelSelect) {
      return;
    }
    var floor = currentFolderLevel();
    var folderTeam = currentFolderTeamId();
    fillLevelOptionsUnder(levelSelect, uploadLevels, floor, floor);
    if (folderTeam !== null && teamSelect) {
      teamSelect.value = String(folderTeam);
    }
    syncTeamField();
  }

  function syncFetchLevels() {
    if (!fetchLevel) {
      return;
    }
    var floor = currentFolderLevel();
    var folderTeam = currentFolderTeamId();
    fillLevelOptionsUnder(fetchLevel, uploadLevels, floor, floor);
    if (folderTeam !== null && fetchTeam) {
      fetchTeam.value = String(folderTeam);
    }
    syncFetchTeamField();
  }

  /* ─── 폴더 모달 — 새 폴더 · 이름 변경 · 등급 변경 ───────────────────── */

  function openFolderDialog(mode) {
    folderMode = mode;
    if (!folderDialog) {
      return;
    }
    setText(folderDialogMsg, '');
    if (folderCascade) {
      folderCascade.checked = false;
    }
    if (folderReason) {
      folderReason.value = '';
    }

    var here = state.folderInfo && state.folderInfo.name ? state.folderInfo.name : '맨 위';
    var levelField = folderLevelSelect ? folderLevelSelect.parentElement : null;

    if (mode === 'create') {
      setText(folderDialogTitle, '새 폴더');
      setText(folderDialogWhere, '만들 위치: ' + here);
      setText(folderSubmit, '만들기');
      if (folderNameField) { folderNameField.hidden = false; }
      if (folderNameInput) { folderNameInput.value = ''; }
      if (levelField) { levelField.hidden = false; }
      fillLevelOptionsUnder(folderLevelSelect, uploadLevels, currentFolderLevel(), currentFolderLevel());
      if (folderCascadeField) { folderCascadeField.hidden = true; }
      if (folderReasonField) { folderReasonField.hidden = true; }
    } else if (mode === 'rename') {
      setText(folderDialogTitle, '이름 바꾸기');
      setText(folderDialogWhere, here);
      setText(folderSubmit, '저장');
      if (folderNameField) { folderNameField.hidden = false; }
      if (folderNameInput) { folderNameInput.value = here === '맨 위' ? '' : here; }
      if (levelField) { levelField.hidden = true; }
      if (folderCascadeField) { folderCascadeField.hidden = true; }
      if (folderReasonField) { folderReasonField.hidden = false; }
    } else {
      setText(folderDialogTitle, '등급 바꾸기');
      setText(folderDialogWhere, here);
      setText(folderSubmit, '저장');
      if (folderNameField) { folderNameField.hidden = true; }
      if (levelField) { levelField.hidden = false; }
      // 부모 폴더보다 넓게는 못 바꾼다(불변 규칙 ①) — 선택지를 미리 줄여 준다.
      var parentLevel = state.crumbs.length >= 2
        ? state.crumbs[state.crumbs.length - 2].access_level
        : null;
      fillLevelOptionsUnder(folderLevelSelect, uploadLevels, parentLevel, currentFolderLevel());
      if (folderCascadeField) { folderCascadeField.hidden = false; }
      if (folderReasonField) { folderReasonField.hidden = false; }
    }
    syncFolderTeamField();
    folderDialog.showModal();
  }

  function closeFolderDialog() {
    if (folderDialog && folderDialog.open) {
      folderDialog.close();
    }
  }

  function syncFolderTeamField() {
    if (folderTeamField && folderLevelSelect) {
      folderTeamField.hidden = folderLevelSelect.value !== 'team';
    }
  }

  function submitFolderDialog(event) {
    event.preventDefault();
    setText(folderDialogMsg, '');

    var payload = {};
    var method = 'PATCH';
    var path = APP_BASE + '/api/folders/' + encodeURIComponent(String(state.folder));

    if (folderMode === 'create') {
      method = 'POST';
      path = APP_BASE + '/api/folders';
      payload.name = folderNameInput ? folderNameInput.value : '';
      payload.access_level = folderLevelSelect ? folderLevelSelect.value : '';
      if (payload.access_level === '') {
        setText(folderDialogMsg, LEVEL_PLACEHOLDER);
        return;
      }
      if (payload.access_level === 'team' && folderTeamSelect) {
        payload.team_id = folderTeamSelect.value;
      }
      if (state.folder !== 0) {
        payload.parent_id = state.folder;
      }
    } else if (folderMode === 'rename') {
      payload.name = folderNameInput ? folderNameInput.value : '';
      if (folderReason && folderReason.value !== '') {
        payload.reason_text = folderReason.value;
      }
    } else {
      payload.access_level = folderLevelSelect ? folderLevelSelect.value : '';
      if (payload.access_level === '') {
        setText(folderDialogMsg, LEVEL_PLACEHOLDER);
        return;
      }
      if (payload.access_level === 'team' && folderTeamSelect) {
        payload.team_id = folderTeamSelect.value;
      }
      if (folderCascade && folderCascade.checked) {
        payload.cascade = true;
      }
      if (folderReason && folderReason.value !== '') {
        payload.reason_text = folderReason.value;
      }
    }

    if (folderSubmit) {
      folderSubmit.disabled = true;
    }
    writeFetch(path, {
      method: method,
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
          if (response.status === 200 || response.status === 201) {
            closeFolderDialog();
            loadFiles();
            loadTree();
            return null;
          }
          // 거부 문구는 **서버가 준 것만** 쓴다 (사유를 브라우저가 추측하지 않는다).
          setText(folderDialogMsg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(folderDialogMsg, GENERIC_ERROR);
      })
      .then(function () {
        if (folderSubmit) {
          folderSubmit.disabled = false;
        }
      });
  }

  /* ─── 26단계 폴더 삭제 (PRD F-41, 사용자 지적 2026-09-15 「빈 폴더인데 안 지워진다」) ────
   *
   * 서버가 세 갈래로 답한다. 화면은 그 코드에 따라 다르게 말한다:
   *   folder_has_children  하위 폴더를 먼저 지우세요        (그대로 알림)
   *   folder_not_empty     파일이 있는 폴더는 지울 수 없습니다 (그대로 알림)
   *   folder_has_trashed   휴지통 파일만 남았다 → **무엇이 남았는지 보여 주고** 되묻는다
   *
   * 되묻기를 `window.confirm` 으로 하지 않는 이유: 확인 창에는 목록을 넣을 수 없어서 사용자가
   * **무엇을 지우는지 모른 채** 「예」를 누르게 된다. 서버가 준 `trashed` 목록(그 사람이 볼 수
   * 있는 파일만)을 이름·삭제일로 보여 주고, 볼 수 없는 파일이 섞였으면 그 사실만 한 줄 적는다
   * (개수·이름은 오지 않는다 — SECURITY R-63).
   *
   * 모달은 여기서 **요소로** 만든다(문자열 → HTML 변환 0, 인라인 style 0). 값은 전부
   * textContent 로만 들어간다 (PRD S-03).
   */
  var trashedDialog = null;
  var trashedList = null;
  var trashedOthers = null;
  var trashedOk = null;

  function buildTrashedDialog() {
    if (trashedDialog) {
      return trashedDialog;
    }
    var dialog = document.createElement('dialog');
    dialog.id = 'folder-trashed-dialog';
    dialog.className = 'modal modal--form';

    var head = document.createElement('div');
    head.className = 'modal__head';
    var title = document.createElement('h2');
    title.className = 'modal__title';
    setText(title, '휴지통에 있는 파일이 남아 있습니다');
    head.appendChild(title);
    var trashedX = makeIconButton('x', '닫기');   // 디버깅 묶음 U2 M3 — 머리에 ✕
    trashedX.addEventListener('click', function () {
      dialog.close();
      setText(folderMsg, '취소했습니다');
    });
    head.appendChild(trashedX);
    dialog.appendChild(head);

    var bodyBox = document.createElement('div');
    bodyBox.className = 'modal__body';

    trashedList = document.createElement('ul');
    trashedList.className = 'notice__list';
    bodyBox.appendChild(trashedList);

    trashedOthers = document.createElement('p');
    trashedOthers.className = 'muted';
    setText(trashedOthers, '이 밖에 내가 볼 수 없는 파일도 있습니다');
    trashedOthers.hidden = true;
    bodyBox.appendChild(trashedOthers);

    var lead = document.createElement('p');
    lead.className = 'warn';
    setText(lead, '폴더를 지우면 이 파일들은 맨 위로 옮겨진 채 휴지통에 남습니다(되살릴 수 있음). 지울까요?');
    bodyBox.appendChild(lead);

    dialog.appendChild(bodyBox);

    var foot = document.createElement('div');
    foot.className = 'modal__foot';
    var cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'btn btn--quiet';
    setText(cancel, '닫기');
    cancel.addEventListener('click', function () {
      dialog.close();
      setText(folderMsg, '취소했습니다');
    });
    foot.appendChild(cancel);

    trashedOk = document.createElement('button');
    trashedOk.type = 'button';
    trashedOk.className = 'btn btn--danger';
    setText(trashedOk, '휴지통 파일을 옮기고 폴더 지우기');
    foot.appendChild(trashedOk);
    dialog.appendChild(foot);

    dialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      dialog.close();
      setText(folderMsg, '취소했습니다');
    });

    document.body.appendChild(dialog);
    trashedDialog = dialog;
    return dialog;
  }

  /** 서버가 준 목록으로 되묻는다. 「예」면 `?move_trashed=1` 로 다시 보낸다. */
  function askTrashed(data, again) {
    var dialog = buildTrashedDialog();
    var items = data && Array.isArray(data.trashed) ? data.trashed : [];
    trashedList.replaceChildren();
    items.forEach(function (file) {
      var li = document.createElement('li');
      var when = typeof file.deleted_at === 'string' ? file.deleted_at.slice(0, 10) : '';
      setText(li, String(file.name) + (when === '' ? '' : ' · ' + when + ' 지움'));
      trashedList.appendChild(li);
    });
    trashedOthers.hidden = !(data && data.others === true);
    trashedOk.disabled = false;
    trashedOk.onclick = function () {
      trashedOk.disabled = true;
      dialog.close();
      again();
    };
    dialog.showModal();
  }

  function deleteFolder() {
    if (state.folder === 0) {
      return;
    }
    var parentId = state.crumbs.length >= 2 ? state.crumbs[state.crumbs.length - 2].id : 0;
    var folderId = state.folder;

    function send(confirmMove) {
      setText(folderMsg, '');
      var path = APP_BASE + '/api/folders/' + encodeURIComponent(String(folderId))
        + (confirmMove ? '?move_trashed=1' : '');
      writeFetch(path, {
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
            goToFolder(parentId);
            showDone('폴더 「' + here + '」을(를) 지웠습니다.');
            return null;
          }
          return readJson(response).then(function (data) {
            // 되묻기는 **서버가 그 코드를 준 경우에만** 한다 — 화면이 사유를 추측하지 않는다.
            if (!confirmMove && data && data.code === 'folder_has_trashed') {
              askTrashed(data, function () {
                send(true);
              });
              return null;
            }
            setText(folderMsg, errorMessage(data));
            return null;
          });
        })
        .catch(function () {
          setText(folderMsg, GENERIC_ERROR);
        });
    }

    var here = state.folderInfo && state.folderInfo.name ? String(state.folderInfo.name) : '';
    /* U-2a — `window.confirm` 대신 파일 삭제와 **같은 확인 창**(openConfirm). 「닫기」·Esc 면 요청 0.
       휴지통 파일만 남은 경우의 되묻기(askTrashed — 목록을 보여 주는 창)는 서버가 그 코드를 준 뒤라 그대로다. */
    showDone('');
    openConfirm({
      title: '폴더를 지울까요?',
      lead: '폴더 「' + here + '」을(를) 지웁니다.',
      note: '안에 살아 있는 파일·하위 폴더가 없어야 지워집니다. 휴지통에 있는 파일만 남았다면 어떤 파일인지 보여 주고 한 번 더 묻습니다.',
      okLabel: '폴더 지우기'
    }, function () {
      send(false);
    });
  }

  /* ─── 파일 이동 모달 ─────────────────────────────────────────────────
   * 폴더를 한 층씩 펼쳐 보고 「여기로 이동」을 누른다. 보이는 폴더는 서버가 내려 준
   * **읽을 수 있는 폴더**뿐이고, 옮길 수 있는지는 서버가 다시 판단한다 (PRD S-01).
   */
  function openMove(file) {
    if (!moveDialog || !UUID_RE.test(file.id)) {
      return;
    }
    movePick.file = file;
    movePick.bulk = null;
    movePick.folder = state.folder;
    movePick.crumbs = state.crumbs.slice();
    setText(moveMsg, '');
    setText(moveFileLabel, file.name);
    loadMoveTree();
    moveDialog.showModal();
  }

  /* 라운드 B — 같은 창으로 **고른 파일 여럿**을 옮긴다. 「여기로 이동」을 누르면 창을 닫고 파일마다
     `PATCH /api/files/{id} {folder_id}` 를 한 번씩 보낸다(doMove 의 bulk 갈래). 옮길 수 있는지는 창구가 파일마다 판단한다. */
  function openBulkMove(items) {
    if (!moveDialog || items.length === 0) {
      return;
    }
    movePick.file = null;
    movePick.bulk = items;
    movePick.folder = state.folder;
    movePick.crumbs = state.crumbs.slice();
    setText(moveMsg, '');
    setText(moveFileLabel, '고른 파일 ' + items.length + '개');
    loadMoveTree();
    moveDialog.showModal();
  }

  function loadMoveTree() {
    fetch(APP_BASE + '/api/folders?parent=' + encodeURIComponent(String(movePick.folder)), {
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
            setText(moveMsg, errorMessage(data));
            return null;
          }
          movePick.crumbs = data && data.folder && Array.isArray(data.folder.breadcrumb)
            ? data.folder.breadcrumb
            : [];
          renderMoveTree(data && Array.isArray(data.folders) ? data.folders : []);
          return null;
        });
      })
      .catch(function () {
        setText(moveMsg, GENERIC_ERROR);
      });
  }

  function renderMoveTree(children) {
    if (!moveCrumbs || !moveList) {
      return;
    }
    moveCrumbs.replaceChildren();
    var root = document.createElement('button');
    root.type = 'button';
    root.className = 'crumbs__item';
    setText(root, '맨 위');   // 디버깅 묶음 U2 낱말 표 — 「루트」 → 「맨 위」(커뮤니티와 같은 말)
    root.addEventListener('click', function () {
      movePick.folder = 0;
      loadMoveTree();
    });
    moveCrumbs.appendChild(root);
    movePick.crumbs.forEach(function (step, index) {
      var sep = document.createElement('span');
      sep.className = 'crumbs__sep';
      setText(sep, '›');
      moveCrumbs.appendChild(sep);
      var item = document.createElement('button');
      item.type = 'button';
      item.className = index === movePick.crumbs.length - 1 ? 'crumbs__item is-current' : 'crumbs__item';
      setText(item, step.name);
      item.addEventListener('click', function () {
        movePick.folder = step.id;
        loadMoveTree();
      });
      moveCrumbs.appendChild(item);
    });

    moveList.replaceChildren();
    children.forEach(function (folder) {
      var li = document.createElement('li');
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'foldertree__item';
      var name = document.createElement('span');
      name.className = 'foldertree__name';
      setText(name, folder.name);
      button.appendChild(name);
      button.appendChild(folderMeta(folder));
      button.addEventListener('click', function () {
        movePick.folder = folder.id;
        loadMoveTree();
      });
      li.appendChild(button);
      moveList.appendChild(li);
    });
    if (moveEmpty) {
      moveEmpty.hidden = children.length !== 0;
    }
  }

  function doMove() {
    if (movePick.bulk && movePick.bulk.length > 0) {
      // 라운드 B — 고른 파일 여럿: 창을 닫고 진행 표로(파일마다 한 건 이동과 같은 요청)
      var items = movePick.bulk;
      var target = movePick.folder === 0 ? null : movePick.folder;
      var where = movePick.crumbs.length === 0 ? '맨 위' : movePick.crumbs[movePick.crumbs.length - 1].name;
      movePick.bulk = null;
      if (moveDialog.open) {
        moveDialog.close();
      }
      bulkStart({
        title: '「' + where + '」 폴더로 옮기기',
        items: items,
        okText: '옮김',
        send: function (item) {
          return bulkFetch('PATCH', APP_BASE + '/api/files/' + item.id, { folder_id: target });
        },
        done: function () {
          loadFiles();
          loadTree();
        }
      });
      return;
    }
    if (!movePick.file) {
      return;
    }
    setText(moveMsg, '');
    var body = { folder_id: movePick.folder === 0 ? null : movePick.folder };
    writeFetch(APP_BASE + '/api/files/' + movePick.file.id, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify(body),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        if (response.status === 200) {
          if (moveDialog.open) {
            moveDialog.close();
          }
          loadFiles();
          loadTree();
          return null;
        }
        return readJson(response).then(function (data) {
          // 서버가 준 거부 문구를 그대로 보여 준다 (사유를 화면이 추측하지 않는다).
          setText(moveMsg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(moveMsg, GENERIC_ERROR);
      });
  }
  function loadTeams() {
    fetch(APP_BASE + '/api/teams', { credentials: 'same-origin', cache: 'no-store' })
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
            // 18-1 — 폴더 모달의 팀 선택지도 같은 목록으로 채운다.
            // 디버깅 묶음 U H2 — 파일 「수정」 창의 팀 고르개(이름으로 고른다)도 같은 목록이다.
            [teamSelect, fetchTeam, folderTeamSelect, bulkTeamSelect, editTeam].forEach(function (select) {
              if (!select) {
                return;
              }
              var option = document.createElement('option');
              option.value = String(team.id);
              setText(option, team.name);
              if (select === editTeam) {
                /* 독립 검토 M-1 — 파일 「수정」 창은 창을 연 파일의 팀이 기준이다. 「내 팀」을 미리 고르지 않는다(목록이 늦게 오면
                   고르개가 내 팀으로 바뀌어 설명만 고쳐도 team_id 가 실렸다). 같은 값의 「지금 팀」 자리가 있으면 진짜 선택지로 바꾼다. */
                var placeholder = select.querySelector('option[data-edit-current][value="' + String(team.id).replace(/[^0-9]/g, '') + '"]');
                if (placeholder) {
                  var wasPicked = placeholder.selected;
                  placeholder.remove();
                  select.appendChild(option);
                  if (wasPicked) { select.value = option.value; }
                  return;
                }
                select.appendChild(option);
                return;
              }
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
    setText(fetchMsg, '');

    if (!fetchUrl || fetchUrl.value === '') {
      return;
    }

    var payload = { url: fetchUrl.value, description: fetchDesc ? fetchDesc.value : '' };
    payload.access_level = fetchLevel ? fetchLevel.value : '';
    if (payload.access_level === '') {
      // 서버도 400 으로 막지만, 무엇을 빠뜨렸는지 바로 알려 준다 (사용자 피드백 ②).
      setText(fetchMsg, LEVEL_PLACEHOLDER);
      return;
    }
    if (payload.access_level === 'team' && fetchTeam) {
      payload.team_id = fetchTeam.value;
    }
    // 18-3 — 저장 위치는 **지금 연 폴더**다(루트면 보내지 않는다). 허용 여부는 서버가
    // 폴더 읽기 판정 + 불변 규칙 ①로 다시 정한다 (ARCH 5.16, files_fetch.php).
    if (state.folder !== 0) {
      payload.folder_id = state.folder;
    }

    fetchSubmit.disabled = true;
    writeFetch(APP_BASE + '/api/files/fetch', {
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
            closeDialog(fetchDialog);
            state.page = 1;
            loadFiles();
            loadTree();
            return null;
          }
          setText(fetchMsg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        setText(fetchMsg, GENERIC_ERROR);
      })
      .then(function () {
        fetchSubmit.disabled = false;
      });
  }

  /* ─── 일괄 업로드 (18-2 — PRD F-27, ARCH 5.17) ─────────────────────────
   *
   * **서버 계약은 바뀌지 않는다.** `POST /api/files` 는 여전히 요청당 파일 1개이고
   * (php-fpm `max_file_uploads=1`), 화면이 고른 목록을 **한 번에 하나씩 순서대로** 보낸다.
   *   · 동시 1 — 한 사용자가 워커 5개를 점유하지 못하게 하고, 일일 총량(2GB) 검사가
   *     파일 순서대로 누적되게 한다 (SECURITY R-64).
   *   · 400(형식·확장자·민감정보 등)이면 **그 파일만** 거부로 적고 다음 파일로 계속한다.
   *   · 401·403(세션·권한)이면 **멈춘다** — 세션이 끊겼거나 등급 자체가 막힌 것이라
   *     나머지를 계속 보내 봐야 같은 결과이고 감사 로그만 늘린다.
   *   · 파일마다 확장자·MIME·크기·총량·민감정보 검사와 감사 행이 그대로 남는다.
   */

  /** 이번에 고른 파일들 (input 과 끌어 놓기가 같은 배열을 채운다) */
  var picked = [];

  function setPicked(list) {
    picked = [];
    var i;
    for (i = 0; i < list.length && picked.length < BULK_MAX; i++) {
      picked.push(list[i]);
    }
    var note = picked.length === 0
      ? ''
      : picked.length + '개 선택됨';
    if (list.length > BULK_MAX) {
      note = BULK_MAX + '개까지만 한 번에 올릴 수 있습니다 (고른 ' + list.length + '개 중 앞 '
        + BULK_MAX + '개만 보냅니다)';
    }
    setText(uploadPicked, note);
  }

  /**
   * 진행 표에 파일 한 줄을 만들고 `{ set, row }` 를 돌려준다.
   *   set(text, cls) — 상태 칸 글자·색
   *   row            — 그 <tr>. F-65 확인 블록을 **바로 아래 줄**에 끼우는 데 쓴다.
   */
  function addStatusRow(file) {
    var row = document.createElement('tr');
    var nameCell = document.createElement('td');
    setText(nameCell, file.name);
    row.appendChild(nameCell);
    var sizeCell = document.createElement('td');
    setText(sizeCell, formatSize(file.size));
    row.appendChild(sizeCell);
    var stateCell = document.createElement('td');
    setText(stateCell, '대기');
    row.appendChild(stateCell);
    uploadStatusBody.appendChild(row);
    return {
      row: row,
      set: function (text, cls) {
        // 문구는 **서버가 준 것 또는 고정 문구**뿐이고 언제나 textContent 다 (PRD S-03).
        setText(stateCell, text);
        stateCell.className = cls ? 'upload-status__state ' + cls : 'upload-status__state';
      }
    };
  }

  /**
   * 파일 하나를 보낸다 — 기존 단일 업로드와 **완전히 같은 요청**이다.
   *
   * F-65 (ARCH 5.29 「올리기」) — `exec` 가 있으면 확인 필드 셋을 **같은 multipart 요청**에
   * 더한다(`exec_ack`·`password`·`otp`). 따로 만든 창구가 아니라 같은 `POST /api/files` 라서,
   * 확인을 통과해도 등급·폴더·민감정보·ClamAV 검사를 똑같이 받는다.
   * 세 값은 이 FormData 에만 있고 화면·저장소 어디에도 남지 않는다 (PRD 7.7).
   */
  function uploadOne(file, common, exec) {
    var form = new FormData();
    form.append('file', file);
    form.append('description', common.description);
    form.append('access_level', common.level);
    if (common.level === 'team') {
      form.append('team_id', common.teamId);
    }
    if (common.folderId !== '' && common.folderId !== '0') {
      form.append('folder_id', common.folderId);
    }
    if (exec) {
      form.append('exec_ack', '1');
      form.append('password', exec.password);
      form.append('otp', exec.otp);
    }
    // headers 에 Content-Type 을 넣지 않는다 — 브라우저가 boundary 를 붙여야 한다.
    return writeFetch(APP_BASE + '/api/files', {
      method: 'POST',
      headers: csrfHeaders(),
      body: form,
      credentials: 'same-origin',
      cache: 'no-store'
    }).then(function (response) {
      return readJson(response).then(function (data) {
        return { status: response.status, data: data };
      });
    });
  }

  /* ─── F-65 올리기 확인 블록 (PRD F-65 ②, ARCH 5.29 「올리기」 ③) ─────────────
   *
   * 서버가 400 `{next:"exec_ack", entries:[…]}` 를 주면 그 파일의 상태 줄 **바로 아래**에
   * 이 블록을 편다. 사용자는 무엇이 걸렸는지(항목 이름)를 보고, 확인 문구에 체크하고,
   * 비밀번호와 2단계 코드를 넣어 **같은 파일을 다시** 보낸다.
   *
   * 체크가 없으면 **요청을 보내지 않는다** — 안내만 띄운다. (서버도 체크 없는 요청을 다시
   * 400 으로 막으므로 이 검사는 편의다. 근거는 언제나 서버다 — PRD S-01.)
   *
   * 항목 이름은 서버가 zip 에서 읽은 문자열이므로 **textContent 로만** 넣는다 (PRD S-03).
   */
  function showExecAck(setter, file, common, entries) {
    var tr = document.createElement('tr');
    tr.className = 'upload-exec__row';
    var td = document.createElement('td');
    td.setAttribute('colspan', '3');

    var boxEl = document.createElement('div');
    boxEl.className = 'upload-exec';

    var warn = document.createElement('p');
    warn.className = 'upload-exec__warn';
    setText(warn, '이 압축본 안에 실행 파일이 들어 있습니다. 관리자만, admin-only 등급으로, '
      + '내용을 확인했다고 밝힌 뒤에만 올릴 수 있습니다.');
    boxEl.appendChild(warn);

    var list = document.createElement('ul');
    list.className = 'upload-exec__list';
    var names = Array.isArray(entries) ? entries : [];
    var n;
    for (n = 0; n < names.length; n++) {
      var li = document.createElement('li');
      setText(li, names[n]);
      list.appendChild(li);
    }
    if (names.length > 0) {
      boxEl.appendChild(list);
    }

    var ackLabel = document.createElement('label');
    ackLabel.className = 'upload-exec__ack';
    var ackBox = document.createElement('input');
    ackBox.type = 'checkbox';
    ackLabel.appendChild(ackBox);
    var ackText = document.createElement('span');
    setText(ackText, '이 묶음에는 실행 파일이 있습니다. 내가 만든 것이 맞고 내용을 압니다');
    ackLabel.appendChild(ackText);
    boxEl.appendChild(ackLabel);

    var pwLabel = document.createElement('label');
    pwLabel.className = 'upload-exec__field';
    var pwText = document.createElement('span');
    setText(pwText, '비밀번호');
    pwLabel.appendChild(pwText);
    var pwInput = document.createElement('input');
    pwInput.type = 'password';
    pwInput.maxLength = 1024;
    pwInput.autocomplete = 'off';
    pwLabel.appendChild(pwInput);
    boxEl.appendChild(pwLabel);

    var otpLabel = document.createElement('label');
    otpLabel.className = 'upload-exec__field';
    var otpText = document.createElement('span');
    setText(otpText, '2단계 인증 코드 (6자리)');
    otpLabel.appendChild(otpText);
    var otpInput = document.createElement('input');
    otpInput.type = 'text';
    otpInput.inputMode = 'numeric';
    otpInput.maxLength = 6;
    otpInput.autocomplete = 'off';
    otpLabel.appendChild(otpInput);
    boxEl.appendChild(otpLabel);

    var send = document.createElement('button');
    send.type = 'button';
    send.className = 'btn btn--small';
    setText(send, '확인하고 올리기');
    boxEl.appendChild(send);

    var note = document.createElement('p');
    note.className = 'upload-exec__msg';
    boxEl.appendChild(note);

    td.appendChild(boxEl);
    tr.appendChild(td);
    // 그 파일의 상태 줄 **바로 아래**에 끼운다 (여러 파일을 한 번에 올릴 때 짝이 흐려지지 않게).
    if (setter.row && setter.row.parentNode) {
      setter.row.parentNode.insertBefore(tr, setter.row.nextSibling);
    } else {
      uploadStatusBody.appendChild(tr);
    }

    /** 확인 칸을 비운다 — 보낸 뒤·성공 뒤에 부른다. */
    function clearAckFields() {
      pwInput.value = '';
      otpInput.value = '';
    }

    send.addEventListener('click', function () {
      if (!ackBox.checked) {
        // **요청을 보내지 않는다.** 확인 문구는 사람이 읽고 누르는 것이 전부이므로
        // 체크 없이 보내는 것은 그 뜻을 비우는 일이다 (PRD F-65 ②).
        setText(note, '확인란에 체크해야 올릴 수 있습니다.');
        return;
      }
      var exec = { password: pwInput.value, otp: otpInput.value };
      clearAckFields();
      send.disabled = true;
      setText(note, '');
      setter.set('확인하고 올리는 중…', 'is-busy');

      uploadOne(file, common, exec)
        .then(function (result) {
          if (result.status === 401) {
            setter.set('중단 — 로그인이 필요합니다', 'is-deny');
            toLogin();
            return;
          }
          if (result.status === 201) {
            setter.set('완료', 'is-ok');
            // 블록은 지운다 — 남겨 두면 같은 파일을 또 보낼 수 있는 자리가 된다.
            if (tr.parentNode) {
              tr.parentNode.removeChild(tr);
            }
            loadFiles();
            loadTree();
            return;
          }
          // 400·403 문구는 **서버가 준 것만** 쓴다 (사유를 브라우저가 추측하지 않는다).
          setter.set(errorMessage(result.data), 'is-deny');
          setText(note, errorMessage(result.data));
          send.disabled = false;
        })
        .catch(function () {
          setter.set(GENERIC_ERROR, 'is-deny');
          setText(note, GENERIC_ERROR);
          send.disabled = false;
        });
    });
  }

  function upload(event) {
    event.preventDefault();
    setText(uploadMsg, '');

    if (picked.length === 0) {
      // 서버도 400 으로 막지만, 굳이 빈 요청을 보내지 않는다.
      return;
    }
    if (!levelSelect || levelSelect.value === '') {
      // 접근 레벨을 고르지 않은 채로는 보내지 않는다. 서버도 400 으로 막는다.
      setText(uploadMsg, LEVEL_PLACEHOLDER);
      return;
    }

    /* 18-3 — 올릴 위치는 **지금 연 폴더**다(0 = 루트면 folder_id 를 보내지 않는다).
       화면에 고르는 자리가 없으므로 「루트로 올라가 버리는」 일이 생기지 않는다. */
    var common = {
      description: descInput ? descInput.value : '',
      level: levelSelect.value,
      teamId: teamSelect ? teamSelect.value : '',
      folderId: String(state.folder)
    };

    var queue = picked.slice();
    uploadStatusBody.replaceChildren();
    if (uploadProgress) {
      uploadProgress.hidden = false;
    }
    var setters = queue.map(function (file) {
      return addStatusRow(file);
    });

    uploadSubmit.disabled = true;
    // 18-3 — 올리는 동안에는 대화상자를 닫지 않는다(진행 표가 사라지면 무엇이 거부됐는지
    // 알 수 없다). 닫기 단추 비활성 + ESC·바깥 누르기 무시.
    setUploadBusy(true);

    /* 순차 전송 — 한 번에 하나. 다음 요청은 앞 요청의 응답을 받은 뒤에 시작한다. */
    var index = 0;
    var stopped = false;
    /* 사용감 라운드 1 UX-5 — **모두** 201 로 끝났나(하나라도 거부·확인 필요·중단·오류면 거짓). 참이면 창을 닫고 새 줄로 데려간다 */
    var madeIds = [];
    var allOk = true;

    function finish() {
      uploadSubmit.disabled = false;
      setUploadBusy(false);
      state.page = 1;
      if (allOk && !stopped && madeIds.length === queue.length && uploadDialog && uploadDialog.open) {
        // 다음에 열었을 때 방금 올린 파일이 그대로 골라져 있지 않게 칸을 비운다(진행 표도) — 설명·등급 고른 값은 둔다
        setPicked([]);
        if (fileInput) {
          fileInput.value = '';
        }
        uploadStatusBody.replaceChildren();
        if (uploadProgress) {
          uploadProgress.hidden = true;
        }
        freshIds = madeIds.slice();
        closeDialog(uploadDialog);
      }
      // 완료 뒤 목록·트리 새로 고침은 **지금 폴더 기준**이다 (state.folder 를 바꾸지 않는다).
      loadFiles();
      loadTree();
    }

    function step() {
      if (stopped || index >= queue.length) {
        finish();
        return;
      }
      var i = index;
      index += 1;
      setters[i].set('올리는 중…', 'is-busy');

      uploadOne(queue[i], common)
        .then(function (result) {
          if (result.status === 401) {
            setters[i].set('중단 — 로그인이 필요합니다', 'is-deny');
            stopped = true;
            toLogin();
            return;
          }
          if (result.status === 201) {
            setters[i].set('완료', 'is-ok');
            if (result.data && typeof result.data.id === 'string' && UUID_RE.test(result.data.id)) {
              madeIds.push(result.data.id);
            } else {
              allOk = false;
            }
          } else if (result.status === 403) {
            // 권한 문제는 나머지 파일도 같은 결과다 → 멈춘다 (ARCH 5.17).
            setters[i].set(errorMessage(result.data), 'is-deny');
            stopped = true;
            var k;
            for (k = index; k < queue.length; k++) {
              setters[k].set('중단', 'is-deny');
            }
            setText(uploadMsg, errorMessage(result.data));
          } else if (result.status === 400 && result.data && result.data.next === 'exec_ack') {
            /* F-65 — 「실행 파일이 든 압축본」이고 이 사람·이 등급·이 입구라면 확인으로
               올릴 수 있다. 줄 아래에 확인 블록을 펴고 **여기서는 멈추지 않는다** —
               나머지 파일은 그대로 이어 올리고, 이 파일만 사용자가 확인한 뒤 다시 간다.
               `next` 가 없는 400(자격이 없는 사람·등급·토큰)은 아래 갈래로 떨어져
               지금까지와 같은 통일 문구만 보인다 (ARCH 5.29 ①②). */
            setters[i].set('확인이 필요합니다 — 아래에서 확인하세요', 'is-deny');
            showExecAck(setters[i], queue[i], common, result.data.entries);
          } else {
            // 400 계열 — 이 파일만 거부하고 다음으로 계속한다.
            setters[i].set(errorMessage(result.data), 'is-deny');
          }
          if (result.status !== 201) {
            allOk = false;   // UX-5 — 창을 열어 둔다(무엇이 왜 안 됐는지가 진행 표에 남는다)
          }
        })
        .catch(function () {
          allOk = false;
          setters[i].set(GENERIC_ERROR, 'is-deny');
        })
        .then(function () {
          step();
        });
    }

    step();
  }

  if (searchForm) {
    searchForm.addEventListener('submit', function (event) {
      event.preventDefault();
      state.q = qInput.value;
      state.sort = sortSelect.value;
      state.dir = dirSelect.value;
      state.page = 1;
      state.view = 'files';
      bulkClear();   // 라운드 B — 검색·정렬이 바뀌면 목록이 바뀐다(고른 것이 화면에서 사라진 채 남지 않게)
      loadFiles();
    });
  }
  // 개발 대기열 6 — 보기 셋 · 폴더 즐겨찾기 단추
  Object.keys(viewButtons).forEach(function (key) {
    if (viewButtons[key]) {
      viewButtons[key].addEventListener('click', function () {
        setView(key);
      });
    }
  });
  if (folderFavBtn) {
    folderFavBtn.addEventListener('click', toggleFolderFavorite);
  }
  // 라운드 B — 일괄 작업: 머리 체크 · 선택 막대 · 진행 표 · 등급 창 · Esc
  if (checkAll) {
    checkAll.addEventListener('click', function () {
      var all = pageRows.length > 0 && pageRows.every(function (r) { return bulkHas(r.file.id); });
      bulk.full = false;
      pageRows.forEach(function (r) {
        if (all) {
          bulkRemove(r.file.id);
        } else {
          bulkAdd(r.file);
        }
      });
      bulk.anchor = null;
      syncBulkUi();
    });
  }
  if (bulkZipBtn) {
    bulkZipBtn.addEventListener('click', bulkZip);
  }
  if (bulkLevelBtn) {
    bulkLevelBtn.addEventListener('click', openBulkLevel);
  }
  if (bulkMoveBtn) {
    bulkMoveBtn.addEventListener('click', function () {
      openBulkMove(bulkItems());
    });
  }
  if (bulkFavBtn) {
    bulkFavBtn.addEventListener('click', bulkFavorite);
  }
  if (bulkTrashBtn) {
    bulkTrashBtn.addEventListener('click', bulkTrash);
  }
  if (bulkClearBtn) {
    bulkClearBtn.addEventListener('click', function () {
      bulkClear();
      if (checkAll && !checkAll.disabled) {
        checkAll.focus();   // 막대가 숨으니 초점을 머리 체크로
      }
    });
  }
  if (bulkResume) {
    bulkResume.addEventListener('click', bulkResumeClick);
  }
  if (bulkUndo) {
    bulkUndo.addEventListener('click', bulkUndoClick);
  }
  if (bulkClose) {
    bulkClose.addEventListener('click', function () {
      // 끝났거나 멈춘 작업만 닫힌다(도는 동안은 단추가 비활성). 멈춘 작업을 닫으면 남은 줄은 보내지 않는다.
      if (bulk.job !== null && !bulk.job.finished && bulk.job.paused === null) {
        return;
      }
      bulk.job = null;
      bulkHideUndo();
      if (bulkPanel) {
        bulkPanel.hidden = true;
      }
      syncBulkUi();
    });
  }
  if (bulkLevelForm) {
    bulkLevelForm.addEventListener('submit', submitBulkLevel);
  }
  if (bulkLevelSelect) {
    bulkLevelSelect.addEventListener('change', syncBulkTeamField);
  }
  document.addEventListener('keydown', function (event) {
    // Esc — 고른 것을 푼다. 창·메뉴가 열려 있으면 그쪽이 먼저 닫힌다(DESIGN 2.7 · 2.15)
    if (event.key !== 'Escape' || bulk.order.length === 0) {
      return;
    }
    if (document.querySelector('dialog[open]') || document.querySelector('details.menu[open]')) {
      return;
    }
    bulkClear();
  });
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

  // ─── 18-2 파일 선택·끌어 놓기 (F-27) ───────────────────────────
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      setPicked(fileInput.files ? fileInput.files : []);
    });
  }
  if (uploadDrop) {
    ['dragenter', 'dragover'].forEach(function (name) {
      uploadDrop.addEventListener(name, function (event) {
        event.preventDefault();
        uploadDrop.classList.add('is-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (name) {
      uploadDrop.addEventListener(name, function (event) {
        event.preventDefault();
        uploadDrop.classList.remove('is-over');
      });
    });
    uploadDrop.addEventListener('drop', function (event) {
      if (event.dataTransfer && event.dataTransfer.files) {
        setPicked(event.dataTransfer.files);
      }
    });
  }

  // ─── 18-1 폴더 단추 (F-26) ─────────────────────────────────────
  if (folderNewBtn) {
    folderNewBtn.addEventListener('click', function () {
      openFolderDialog('create');
    });
  }
  if (folderRenameBtn) {
    folderRenameBtn.addEventListener('click', function () {
      openFolderDialog('rename');
    });
  }
  if (folderLevelBtn) {
    folderLevelBtn.addEventListener('click', function () {
      openFolderDialog('level');
    });
  }
  if (folderDeleteBtn) {
    folderDeleteBtn.addEventListener('click', deleteFolder);
  }
  if (folderLevelSelect) {
    folderLevelSelect.addEventListener('change', syncFolderTeamField);
  }
  if (folderForm) {
    folderForm.addEventListener('submit', submitFolderDialog);
  }
  if (folderDialogClose) {
    folderDialogClose.addEventListener('click', closeFolderDialog);
  }
  if (folderDialogX) {
    folderDialogX.addEventListener('click', closeFolderDialog);
  }
  if (moveHere) {
    moveHere.addEventListener('click', doMove);
  }
  if (moveClose) {
    moveClose.addEventListener('click', function () {
      if (moveDialog && moveDialog.open) {
        moveDialog.close();
      }
    });
  }
  if (moveX) {
    moveX.addEventListener('click', function () {
      if (moveDialog && moveDialog.open) {
        moveDialog.close();
      }
    });
  }

  /* ─── 18-3 올리기·가져오기 대화상자 열고 닫기 ─────────────────────────────
   * 새 폴더 모달과 같은 방식이다: showModal() 로 열고, ESC·「닫기」·바깥(배경) 누르기로
   * 닫는다. 여는 것은 표현일 뿐이고, 무엇을 보낼 수 있는지는 서버가 다시 판단한다 (PRD S-01).
   */

  function closeDialog(dialog) {
    if (dialog && dialog.open) {
      dialog.close();
    }
  }

  /** 올리는 중인가 — 참이면 대화상자를 닫지 않는다. */
  var uploadBusy = false;

  function setUploadBusy(busy) {
    uploadBusy = busy;
    if (uploadDialogClose) {
      uploadDialogClose.disabled = busy;
    }
    if (uploadDialogX) {
      uploadDialogX.disabled = busy;
    }
  }

  function uploadIsBusy() {
    return uploadBusy;
  }

  /**
   * 닫기 규칙을 한 대화상자에 건다.
   *   · 단추(닫기·X) 누름  · 배경(backdrop) 누름  · ESC(<dialog> 의 cancel)
   * `guard()` 가 참이면 셋 다 무시한다.
   */
  function bindDialogDismiss(dialog, buttons, guard) {
    if (!dialog) {
      return;
    }
    function tryClose() {
      if (guard && guard()) {
        return;
      }
      closeDialog(dialog);
    }
    buttons.forEach(function (button) {
      if (button) {
        button.addEventListener('click', tryClose);
      }
    });
    // 배경을 누르면 이벤트 대상이 <dialog> 자신이다(안쪽 칸은 자식이 받는다).
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        tryClose();
      }
    });
    dialog.addEventListener('cancel', function (event) {
      if (guard && guard()) {
        event.preventDefault();
      }
    });
  }

  function openUploadDialog() {
    if (!uploadDialog) {
      return;
    }
    setText(uploadMsg, '');
    // 열 때마다 「올릴 위치」와 등급 선택지를 지금 폴더 기준으로 새로 그린다 (18-3 ②③).
    syncUploadWhere();
    uploadDialog.showModal();
  }

  function openFetchDialog() {
    if (!fetchDialog) {
      return;
    }
    setText(fetchMsg, '');
    syncUploadWhere();
    fetchDialog.showModal();
  }

  bindDialogDismiss(uploadDialog, [uploadDialogClose, uploadDialogX], uploadIsBusy);
  bindDialogDismiss(fetchDialog, [fetchDialogClose, fetchDialogX], null);
  // 라운드 B — 등급·팀 바꾸기 창(닫기·✕·Esc·바깥 — 새 폴더 창과 같은 규칙)
  bindDialogDismiss(bulkLevelDialog, [document.getElementById('bulk-level-close'), document.getElementById('bulk-level-x')], null);
  // 디버깅 묶음 U H2 — 파일 한 건 「수정」 창(닫기·✕·Esc·바깥 — 같은 규칙. 저장 중에는 닫지 않는다)
  bindDialogDismiss(editDialog, [document.getElementById('file-edit-close'), document.getElementById('file-edit-x')], function () { return editBusy; });

  // 업로드 레벨 선택지는 서버가 준 목록으로만 만든다. 하나도 없으면(Guest) 올리기 단추를
  // 감춘다. 감추는 것은 편의일 뿐이다 — 그래도 POST /api/files 는 서버가 다시 막는다 (PRD S-01).
  var hasTeam = myTeamId !== null && myTeamId !== undefined && String(myTeamId) !== '';
  var canUpload = fillLevelOptions(levelSelect, uploadLevels, hasTeam) > 0;
  /* 라운드 B — 등급·팀 바꾸기 창의 선택지는 올리기 창과 **같은 목록**(서버가 준 upload_levels). 고를 등급이 하나도 없으면(Guest)
     막대의 그 단추를 감춘다 — 편의일 뿐이고, PATCH 는 창구가 다시 막는다(PRD S-01). */
  if (fillLevelOptions(bulkLevelSelect, uploadLevels, hasTeam) === 0 && bulkLevelBtn) {
    bulkLevelBtn.hidden = true;
  }
  // 「이어서 하기」가 같은 계정인지 견줄 아이디(역할로 보기 중에도 실제 계정 — P-5a 로 이미 받은 답을 나눠 쓴다)
  fetchMe().then(function (result) {
    if (result.me && typeof result.me.username === 'string') {
      bulk.username = result.me.username;
    }
  }).catch(function () {});
  if (uploadToggle) {
    uploadToggle.hidden = !canUpload;
    if (canUpload) {
      uploadToggle.addEventListener('click', openUploadDialog);
    }
  }

  // "URL 로 가져오기" 단추는 서버가 can_fetch && fetch_enabled 를 준 경우에만 연다
  // (F-14 · F-43). 레벨 선택지는 업로드와 같은 목록(서버가 정한 upload_levels)을 쓴다.
  var canFetchUrl = canFetch === true && fillLevelOptions(fetchLevel, uploadLevels, hasTeam) > 0;
  if (fetchToggle) {
    fetchToggle.hidden = !canFetchUrl;
    if (canFetchUrl) {
      fetchToggle.addEventListener('click', openFetchDialog);
    }
  }

  /* F-43 — 대화상자에 안내 한 줄을 더한다. 글자는 **고정 문자열**이고 textContent 로만
     들어간다(문자열 → HTML 변환 0, PRD S-03). 화면 틀(home.php)을 고치지 않고 여기서
     만드는 이유는, 이 안내가 가져오기 기능과 함께 켜지고 꺼지는 값이기 때문이다. */
  if (fetchDialog) {
    var fetchBody = fetchDialog.querySelector('.modal__body');
    var fetchForm0 = document.getElementById('fetch-form');
    if (fetchBody && fetchForm0 && !fetchBody.querySelector('[data-fetch-hint]')) {
      var hint = document.createElement('p');
      hint.className = 'panel__sub';
      hint.setAttribute('data-fetch-hint', '');
      setText(hint, '구글 드라이브·OneDrive 의 공개 링크(「링크가 있는 모든 사용자」)는 보이는 주소 그대로 붙여도 됩니다. 로그인이 필요한 문서는 가져올 수 없습니다.');
      fetchBody.insertBefore(hint, fetchForm0);
    }
  }

  // 「새 폴더」는 올릴 수 있는 등급이 하나라도 있는 사람에게만 보인다(Guest 는 라우터에서 끝).
  // 감추는 것은 편의일 뿐이다 — POST /api/folders 는 서버가 다시 막는다 (PRD S-01).
  if (folderNewBtn) {
    folderNewBtn.hidden = !canUpload;
  }

  /* Q:F-2 — 통합 검색 창(public/assets/search.js)의 파일 결과는 `/tbox/?q=<검색어>` 로 이 화면을 연다.
     주소의 q 를 검색 칸에 넣고 첫 목록을 그 검색어로 부른다 — state.q 가 있으면 loadFiles 가 folder 를
     보내지 않으므로 **전역 검색**이다(18-1). 값은 칸의 value 와 요청 인자로만 쓴다(textContent·HTML 0).
     보이는 파일은 여전히 서버의 visibleFilesWhere 하나가 정한다 (PRD S-02). */
  var wantQ = '';
  try {
    wantQ = new URLSearchParams(window.location.search).get('q') || '';
  } catch (e) {
    wantQ = '';
  }
  wantQ = wantQ.trim().slice(0, 200);
  if (wantQ !== '' && qInput) {
    qInput.value = wantQ;
    state.q = wantQ;
  }

  syncTeamField();
  syncFetchTeamField();
  loadTeams();
  loadFiles();
  loadTree();
  // 개발 대기열 6 — 별 표시용 즐겨찾기 목록(표를 먼저 그리고, 목록이 오면 별만 맞춘다)
  loadFavoriteLists()
    .then(function (lists) {
      if (lists !== null) {
        syncStars();
      }
    })
    .catch(function () {});
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

  /**
   * 「누가」 칸 — 계정명 + origin 배지(F-71 ④). 배지는 서버 값으로만 고르고(originBadgeFor),
   * 글자는 전부 textContent 로 넣는다 — 계정명도 공격자가 정할 수 있는 문자열이다.
   */
  function addActorCell(row, data) {
    var td = addCell(row, data.actor_name);
    var badge = originBadgeFor(data.origin, data.origin_hint);
    if (badge !== null) {
      var span = document.createElement('span');
      span.className = badge.cls;
      span.setAttribute('title', badge.title);
      setText(span, badge.label);
      td.appendChild(document.createTextNode(' '));
      td.appendChild(span);
    }
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

  /**
   * 역할 · 등급 · 결과 칸의 **보이는 글자**만 한글로(디버깅 묶음 U3 · DESIGN 5장 「등급·역할」 — 시연 2026-09-26 「Admin · allow 가 영어」).
   * 원래 값은 `title` 에 남긴다(감사 기록·거르기와 대조하는 값 — 거르기 칸과 쿼리는 그대로). 표에 없는 값은 받은 글자 그대로.
   */
  var AUDIT_RESULT_LABELS = { 'allow': '허용', 'deny': '거부', 'error': '오류' };
  function addLabelCell(row, value, table) {
    var raw = value === null || value === undefined ? '' : String(value);
    var shown = Object.prototype.hasOwnProperty.call(table, raw) ? table[raw] : raw;
    var td = addCell(row, shown);
    if (shown !== raw && raw !== '') {
      td.setAttribute('title', raw);
    }
    return td;
  }

  /**
   * 행동·사유 코드 칸(`page.visibility` · `reauth_required` …) — 코드는 그대로 두고 `.`·`_` 뒤에서만 줄이 꺾이게 `<wbr>` 을 끼운다
   * (디버깅 묶음 U3 — 1280 폭 감사 표가 긴 코드 한 칸 때문에 넘쳤다). 글자는 textContent 조각, 꺾을 자리는 빈 요소뿐(문자열 → HTML 0).
   */
  function addCodeCell(row, value) {
    var raw = value === null || value === undefined ? '' : String(value);
    if (raw === '') {
      return addCell(row, raw);
    }
    var td = document.createElement('td');
    var parts = raw.split(/([._])/);
    for (var i = 0; i < parts.length; i++) {
      if (parts[i] === '') {
        continue;
      }
      td.appendChild(document.createTextNode(parts[i]));
      if (parts[i] === '.' || parts[i] === '_') {
        td.appendChild(document.createElement('wbr'));
      }
    }
    row.appendChild(td);
    return td;
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
      addWhenCell(row, item.happened_at);
      addActorCell(row, item);
      addLabelCell(row, item.actor_role, ROLE_LABELS);
      addCodeCell(row, item.action);
      addCell(row, targetOf(item));
      addLabelCell(row, item.access_level, LEVEL_BADGE_LABELS);
      addLabelCell(row, item.result, AUDIT_RESULT_LABELS);
      addCodeCell(row, whyOf(item));
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
    fetch(APP_BASE + '/api/audit' + buildQuery(), { credentials: 'same-origin', cache: 'no-store' })
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
 *   두 값 모두 textContent 로만 넣는다(다른 모든 값과 같은 규칙).
 * ★ QR 은 서버(src/Qr.php)가 만든 **모듈 행렬**(`qr.rows` — '0'/'1' 문자열)로 내려온다.
 *   화면은 그것을 <canvas> 에 fillRect 로 그린다 — 마크업·SVG 문자열·`data:` 주소를 만들지
 *   않는다(PRD S-03 · F-51 ③, ARCH 5.25). 모양이 조금이라도 어긋나면 그리지 않고 숨긴다.
 * ★ 오류 문구는 서버가 준 것만 쓴다 — 브라우저가 "코드가 틀렸습니다" 같은 말을 지어내지 않는다.
 */
/* mfaRequired — /api/me 의 `mfa_required`(boolean) 그대로다. 상태 문구가 「필수」와
   「선택(권장)」으로 갈리는 데만 쓴다. 화면은 역할 문자열을 비교하지 않는다 (F-52). */
function initAccount(csrfToken, mfaEnabled, onMfaEnabled, mfaRequired) {
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
  var mfaQr = document.getElementById('mfa-qr');
  var mfaConfirmForm = document.getElementById('mfa-confirm-form');
  var mfaCode = document.getElementById('mfa-code');
  var mfaDisableForm = document.getElementById('mfa-disable-form');
  var mfaOffPassword = document.getElementById('mfa-off-password');
  var mfaOffCode = document.getElementById('mfa-off-code');
  /* D-10 (DESIGN 2.7, 사용자 지시 2026-09-22) — 끄기 폼은 상태 문장 아래 「2단계 인증 끄기」
     단추가 여는 모달 안에 있다. 폼 id·칸·요청 본문은 그대로고 자리만 옮겼다.
     (최초 로그인 강제 절차 화면에는 이 요소들이 없다 — 전부 null 검사 뒤에 쓴다.) */
  var mfaDisableOpen   = document.getElementById('mfa-disable-open');
  var mfaDisableDialog = document.getElementById('mfa-disable-dialog');
  var mfaDisableX      = document.getElementById('mfa-disable-dialog-x');
  var mfaDisableClose  = document.getElementById('mfa-disable-dialog-close');
  /* 끄기 실패 문구는 화면 공통 `#msg` 가 아니라 **모달 안**의 이 줄에 쓴다(모달이 열려
     있는 동안 공통 줄은 백드롭 뒤에 가려 보이지 않는다). 열 때·닫을 때·성공 때 비운다.
     성공 끝맺음은 모달이 닫히므로 그대로 `#msg` 에 남긴다. */
  var mfaDisableMsg    = document.getElementById('mfa-disable-msg');

  /** 끄기 모달 닫기 — 비밀은 남기지 않고, 초점은 연 단추로 돌려준다 (키보드 자리 유지) */
  function closeMfaDisableDialog() {
    if (mfaDisableDialog && mfaDisableDialog.open) {
      mfaDisableDialog.close();
    }
    if (mfaOffPassword) {
      mfaOffPassword.value = '';
    }
    if (mfaOffCode) {
      mfaOffCode.value = '';
    }
    if (mfaDisableOpen && mfaDisableOpen.hidden !== true) {
      mfaDisableOpen.focus();
    }
  }

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

  /** QR 한 모듈의 크기(px)와 규격이 요구하는 여백(quiet zone, 모듈 수) */
  var MFA_QR_MODULE = 4;
  var MFA_QR_QUIET = 4;

  /** canvas 를 비우고 숨긴다 (등록 전 · 확정 뒤 · 이미 켜져 있을 때) */
  function clearMfaQr() {
    if (!mfaQr) {
      return;
    }
    if (mfaQr.getContext) {
      var ctx = mfaQr.getContext('2d');
      if (ctx) {
        ctx.clearRect(0, 0, mfaQr.width, mfaQr.height);
      }
    }
    mfaQr.width = 0;
    mfaQr.height = 0;
    mfaQr.hidden = true;
  }

  /**
   * 서버가 준 모듈 행렬을 그린다. 그리기 전에 **모양을 확인**한다 —
   * 객체인가 · size 가 21~177 의 정수인가 · rows 가 size 줄인가 · 각 줄이 size 글자 '0'/'1' 인가.
   * 하나라도 어긋나면 그리지 않고 숨긴다(비밀 키·링크로 등록하는 길은 그대로 남는다).
   */
  function drawMfaQr(qr) {
    clearMfaQr();
    if (!mfaQr || !mfaQr.getContext || !qr || typeof qr !== 'object') {
      return;
    }
    var size = qr.size;
    if (typeof size !== 'number' || !isFinite(size) || Math.floor(size) !== size
        || size < 21 || size > 177) {
      return;
    }
    var rows = qr.rows;
    if (!Array.isArray(rows) || rows.length !== size) {
      return;
    }
    var r;
    for (r = 0; r < size; r++) {
      if (typeof rows[r] !== 'string' || rows[r].length !== size || !/^[01]+$/.test(rows[r])) {
        return;
      }
    }
    var side = (size + MFA_QR_QUIET * 2) * MFA_QR_MODULE;
    mfaQr.width = side;
    mfaQr.height = side;
    var ctx2 = mfaQr.getContext('2d');
    if (!ctx2) {
      clearMfaQr();
      return;
    }
    // 어두운 테마에서도 흰 바탕·검정 모듈이어야 인증 앱이 읽는다 (색 토큰을 쓰지 않는다)
    ctx2.fillStyle = '#ffffff';
    ctx2.fillRect(0, 0, side, side);
    ctx2.fillStyle = '#000000';
    for (r = 0; r < size; r++) {
      var line = rows[r];
      for (var c = 0; c < size; c++) {
        if (line.charAt(c) === '1') {
          ctx2.fillRect((c + MFA_QR_QUIET) * MFA_QR_MODULE, (r + MFA_QR_QUIET) * MFA_QR_MODULE,
                        MFA_QR_MODULE, MFA_QR_MODULE);
        }
      }
    }
    mfaQr.hidden = false;
  }

  function showMfa(enabled) {
    /*
     * 꺼져 있을 때의 문구는 **필수인 역할인지**에 따라 갈린다 (F-52, ARCH 5.26).
     * 판정은 서버가 했고(`/api/me` 의 mfa_required) 화면은 그 boolean 만 본다.
     */
    var offText = mfaRequired === true
      ? '2단계 인증이 꺼져 있습니다. 이 계정의 역할(관리자)은 필수라 켜기 전에는 다른 기능을 쓸 수 없습니다.'
      : '2단계 인증이 꺼져 있습니다. 선택이지만 켜 두기를 권장합니다 — 비밀번호가 새어도 계정을 지킵니다.';
    setText(
      mfaState,
      (enabled
        ? '2단계 인증이 켜져 있습니다. 로그인할 때 인증 앱의 6자리 코드가 필요합니다.'
        : offText
      ) + MFA_CODE_HINT
    );
    if (mfaOff) {
      mfaOff.hidden = enabled;
    }
    if (mfaDisableForm) {
      mfaDisableForm.hidden = !enabled;
    }
    // D-10 — 모달을 여는 단추는 폼과 **같은 조건**으로 켜고 끈다.
    if (mfaDisableOpen) {
      mfaDisableOpen.hidden = !enabled;
    }
    if (mfaSetup && enabled) {
      mfaSetup.hidden = true;
      clearMfaQr();
    }
  }

  if (pwForm) {
    pwForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(msg, '');
      pwSubmit.disabled = true;
      post(APP_BASE + '/api/me/password', { current: pwCurrent.value, 'new': pwNew.value })
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
          // 디버깅 묶음 S-5 — 개인 프로그램 토큰도 전부 취소된다(개수는 서버가 준 값).
          var tokensRevoked = typeof result.data.tokens_revoked === 'number' ? result.data.tokens_revoked : 0;
          setText(msg, '비밀번호를 바꿨습니다. 끊은 다른 기기 세션: ' + revoked + '개 · 취소한 프로그램 토큰: ' + tokensRevoked + '개');
          if (tokensRevoked > 0) {
            document.dispatchEvent(new CustomEvent('qaredi:tokens-changed'));
          }
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
      post(APP_BASE + '/api/me/mfa/enroll', {})
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
          drawMfaQr(result.data.qr);
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
      post(APP_BASE + '/api/me/mfa/confirm', { otp: mfaCode.value })
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
          clearMfaQr();
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
      post(APP_BASE + '/api/me/mfa/disable', {
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
            // 실패는 **모달 안**에서 말한다(모달은 열린 채 — 비밀번호·코드를 고쳐 다시 보낸다).
            setText(mfaDisableMsg, errorMessage(result.data));
            return null;
          }
          setText(mfaDisableMsg, '');
          showMfa(false);
          setText(msg, '2단계 인증을 껐습니다.');
          /* D-10 — 성공에는 모달을 닫고 바뀐 상태 문장으로 초점을 옮긴다(실패는 열어 둔다).
             <p> 는 원래 초점을 받지 못하므로 tabIndex 를 -1 로 두고 부른다(탭 순서에는
             들어가지 않는다). */
          if (mfaDisableDialog && mfaDisableDialog.open) {
            mfaDisableDialog.close();
          }
          if (mfaState && typeof mfaState.focus === 'function') {
            mfaState.tabIndex = -1;
            mfaState.focus();
          }
          return null;
        })
        .catch(function () {
          setText(mfaDisableMsg, GENERIC_ERROR);
        });
    });
  }

  /* ── D-10 「2단계 인증 끄기」 단추 · 모달의 ✕ 와 「닫기」 (Esc 는 <dialog> 기본) ── */
  if (mfaDisableOpen) {
    mfaDisableOpen.addEventListener('click', function () {
      if (!mfaDisableDialog || typeof mfaDisableDialog.showModal !== 'function' || mfaDisableDialog.open) {
        return;
      }
      setText(msg, '');
      setText(mfaDisableMsg, '');
      mfaDisableDialog.showModal();
      if (mfaOffPassword) {
        mfaOffPassword.focus();
      }
    });
  }
  [mfaDisableX, mfaDisableClose].forEach(function (btn) {
    if (btn) {
      btn.addEventListener('click', closeMfaDisableDialog);
    }
  });
  if (mfaDisableDialog) {
    // Esc 로 닫으면 위의 처리기를 거치지 않는다 — 어떤 길로 닫혀도 비밀은 남기지 않는다 (PRD 7.7)
    mfaDisableDialog.addEventListener('close', function () {
      if (mfaOffPassword) {
        mfaOffPassword.value = '';
      }
      if (mfaOffCode) {
        mfaOffCode.value = '';
      }
      setText(mfaDisableMsg, '');
    });
  }

  showMfa(mfaEnabled === true);
}

/* ─── 휴지통 (10단계 F-11 · Q:F-4 U-2b) ─────────────────────────
 *
 * Admin 에게는 「휴지통」(전체), 그 밖에는 「내 휴지통」(내가 올리고 내가 지운 파일)이다.
 * 어느 쪽인지는 서버가 그린 표(`#trash-body` 의 `data-trash-own`)로만 안다 — 화면이 역할을 보지 않는다.
 * 그 표시는 열 구성(올린 사람·지운 사람 칸을 뺀다)에만 쓰이고, 어떤 행이 오느냐는 서버 SQL WHERE 가
 * 정한다. 남의 파일 UUID 로 POST …/restore 를 직접 불러도 없는 파일과 같은 404 다 (PRD 5.4 각주 ¹, S-02).
 */
function initTrash(csrfToken, onRestored) {
  var msg = document.getElementById('msg');
  var body = document.getElementById('trash-body');
  var empty = document.getElementById('trash-empty');
  var okNote = document.getElementById('trash-ok');   // 되살린 뒤 결과 한 줄(.ok-note, 기본 hidden)
  var ownOnly = body.hasAttribute('data-trash-own');

  function showDone(text) {
    if (!okNote) {
      return;
    }
    setText(okNote, text);
    okNote.hidden = text === '';
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

  function restore(id, name) {
    if (!UUID_RE.test(id)) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    setText(msg, '');
    showDone('');
    fetch(APP_BASE + '/api/files/' + id + '/restore', {
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
          showDone('「' + name + '」을(를) 되살렸습니다 — 원래 폴더로 돌아갔습니다(그 폴더가 지워졌다면 맨 위).');
          load();
          if (typeof onRestored === 'function') {
            onRestored();
          }
          return null;
        }
        return readJson(response).then(function (data) {
          // 16-1 — 창이 닫혀 막혔으면 모달로 다시 묻고, 성공하면 이 복구 요청을 다시 보낸다
          if (handleReauth(response, data, function () { restore(id, name); })) {
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
      if (!ownOnly) {
        // 「내 휴지통」에서는 두 칸이 언제나 나 자신이라 표에서 뺀다(서버 thead 도 같은 구성).
        addCell(row, file.uploaded_by);
        addCell(row, file.deleted_by);
      }
      addWhenCell(row, file.deleted_at);
      var td = document.createElement('td');
      td.className = 'actions';
      if (UUID_RE.test(file.id)) {
        var button = makeIconButton('restore', '되살리기');
        var fileName = String(file.name === null || file.name === undefined ? '' : file.name);
        button.addEventListener('click', function () {
          restore(file.id, fileName);
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
    fetch(APP_BASE + '/api/files/trash', { credentials: 'same-origin', cache: 'no-store' })
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
/* ─── 프로그램 토큰 — 두 화면이 **함께 쓰는** 조각 (F-59 → F-66, ARCH 4.6 · 4.7) ──────
 *
 * 같은 표·같은 「한 번만 보이는 값」을 두 자리가 쓴다:
 *   · `/settings` 「프로그램 토큰」 절 — **본인**의 목록·발급·회수 (`/api/me/api-tokens`)
 *   · `/users` 토큰 모달        — **남의** 목록·회수 (`/api/users/{uid}/api-tokens`).
 *     F-66 으로 그 모달의 **발급 폼은 사라졌다** — 관리자가 남을 대신해 자격을 만드는 길이
 *     없어지는 것이 R-91 의 핵심 완화다(라우트도 없어 404 다).
 * 코드를 두 벌로 두면 한쪽만 고치는 날이 오고, 그날 두 화면의 말이 달라진다.
 *
 * ★ 이 조각에는 역할 비교가 한 줄도 없다. 무엇이 보이는지는 서버가 내려 준 값
 *   (`can_issue_tokens`·`token_scopes`)과 라우트 정책이 정한다 (DESIGN 0장 1, PRD S-01).
 * ★ 값은 전부 `setText`(textContent)다. 발급된 토큰만 읽기 전용 칸의 `value` 로 들어간다 —
 *   문서에 글자로 심지 않는다 (PRD S-03, S-09-11).
 */

/** 범위 세 값의 화면 라벨 (ARCH 4.7 · DESIGN 5장 「영어 약어는 한글 뜻을 붙인다」) */
var TOKEN_SCOPE_LABELS = {
  'read': '읽기만',
  'read+upload': '읽기 + 올리기',
  'read+upload+delete': '읽기 + 올리기 + 지우기'
};

/** 모르는 값이 오면 **서버가 준 값 그대로** 보인다 — 화면이 문장을 지어내지 않는다 */
function tokenScopeLabel(scope) {
  if (typeof scope !== 'string' || scope === '') { return '—'; }
  return Object.prototype.hasOwnProperty.call(TOKEN_SCOPE_LABELS, scope)
    ? TOKEN_SCOPE_LABELS[scope] : scope;
}

/**
 * 토큰 창구의 요청 하나 — 세션 쿠키 + CSRF (토큰 창구 셋은 전부 **세션**이다).
 * 재인증 관문(403 `next:"reauth"`)에 걸리면 모달을 띄우고 **막혔던 요청을 다시** 보낸다
 * (관리자 창구만 해당된다 — 본인 창구의 정책은 `authenticated` 라 그 관문이 없다).
 */
function tokenApiFetch(csrfToken, method, path, payload, retry) {
  var init = {
    method: method,
    headers: { 'X-CSRF-Token': csrfToken },
    credentials: 'same-origin',
    cache: 'no-store'
  };
  if (payload) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(payload);
  }
  return fetch(path, init).then(function (response) {
    if (response.status === 401) {
      toLogin();
      return null;
    }
    return readJson(response).then(function (data) {
      if (handleReauth(response, data, retry)) {
        return null;
      }
      if (toGate(response, data)) {
        return null;
      }
      return { ok: response.ok, status: response.status, data: data };
    });
  });
}

/** 한 번 보여 준 토큰 상자를 지운다(값은 상자와 함께 사라진다) */
function clearIssuedToken() {
  var once = document.getElementById('api-token-once');
  if (once) {
    var field = once.querySelector('input');
    if (field) {
      field.value = '';
    }
    if (once.parentNode) {
      once.parentNode.removeChild(once);
    }
  }
}

/**
 * 발급된 토큰을 **한 번만** 보여 주는 상자 — 닫으면 값이 사라진다(다시 볼 방법은 없다).
 * 값은 읽기 전용 입력칸의 `value` 로만 넣는다(문서에 글자로 심지 않는다) — 임시 비밀번호
 * 상자와 같은 방식이다 (11단계 showTempPassword, SECURITY R-39 · R-91).
 * @param host  상자를 붙일 자리 · @param msgEl 복사 결과를 말할 줄
 */
function showIssuedToken(host, msgEl, tokenValue) {
  if (!host || typeof tokenValue !== 'string' || tokenValue === '') {
    return;
  }
  clearIssuedToken();

  var box = document.createElement('div');
  box.id = 'api-token-once';
  box.className = 'notice';

  var title = document.createElement('p');
  title.className = 'notice__title';
  setText(title, '새 프로그램 토큰');
  box.appendChild(title);

  var field = document.createElement('input');
  field.type = 'text';
  field.readOnly = true;
  field.className = 'temp-password';
  field.value = tokenValue;            // 값은 value 로만 (문서에 글자로 심지 않는다)
  box.appendChild(field);

  var copy = document.createElement('button');
  copy.type = 'button';
  copy.className = 'btn btn--quiet btn--small';
  setText(copy, '복사');
  copy.addEventListener('click', function () {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      navigator.clipboard.writeText(field.value).then(function () {
        setText(msgEl, '복사했습니다.');
      }, function () {
        setText(msgEl, '복사하지 못했습니다 — 칸의 값을 직접 선택해 복사하세요.');
      });
      return;
    }
    field.focus();
    field.select();
    setText(msgEl, '이 브라우저는 자동 복사를 지원하지 않습니다 — 선택된 값을 복사하세요.');
  });
  box.appendChild(copy);

  var warn = document.createElement('p');
  warn.className = 'warn';
  setText(warn, '지금만 보입니다. 닫으면 다시 볼 수 없습니다');
  box.appendChild(warn);

  var close = document.createElement('button');
  close.type = 'button';
  close.className = 'btn btn--quiet btn--small';
  setText(close, '닫기');
  close.addEventListener('click', clearIssuedToken);
  box.appendChild(close);

  host.appendChild(box);
}

/**
 * 표 한 줄 — 어느 칸을 그릴지는 `ctx.columns` 가 정한다(두 화면의 `<thead>` 순서 그대로).
 *   name · purpose · hint · scope · expires · created · used · state · actions
 * 시각 칸은 전부 `addWhenCell` 이다 — 초 단위까지만 보이고 전체 값은 title 로 간다 (DESIGN 2.4).
 * @param ctx {body, columns, onRevoke}
 */
function addTokenRowShared(ctx, token) {
  var row = document.createElement('tr');
  var live = token.revoked_at === null || token.revoked_at === undefined;

  ctx.columns.forEach(function (column) {
    if (column === 'created') { addWhenCell(row, token.created_at); return; }
    if (column === 'used')    { addWhenCell(row, live ? token.last_used_at : token.revoked_at); return; }
    if (column === 'expires') {
      // 「곧 만료」 는 **서버가 판정한** expires_soon 만 본다 — 화면이 날짜를 계산하지 않는다
      // (본인 목록에만 있는 값이라, 관리자 목록에서는 꼬리가 붙지 않는다).
      addWhenCell(row, token.expires_at, live && token.expires_soon === true ? ' (곧 만료)' : '');
      return;
    }

    var td = document.createElement('td');
    if (column === 'name')    { setText(td, token.name); }
    else if (column === 'purpose') { setText(td, token.purpose === null || token.purpose === undefined || token.purpose === '' ? '—' : token.purpose); }
    else if (column === 'hint')    { setText(td, token.hint === null || token.hint === undefined || token.hint === '' ? '—' : token.hint); }
    else if (column === 'scope')   { setText(td, tokenScopeLabel(token.scope)); }
    else if (column === 'state')   { setText(td, live ? '살아있음' : '회수됨'); }
    else if (column === 'actions') {
      td.className = 'actions';
      if (live && typeof ctx.onRevoke === 'function') {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn--quiet btn--small';
        setText(button, '회수');
        button.addEventListener('click', function () { ctx.onRevoke(token, button); });
        td.appendChild(button);
      }
    }
    row.appendChild(td);
  });

  ctx.body.appendChild(row);
}

function initUsers(csrfToken) {
  var msg = document.getElementById('msg');
  var body = document.getElementById('users-body');
  var empty = document.getElementById('users-empty');
  var pageInfo = document.getElementById('page-info');
  var prev = document.getElementById('page-prev');
  var next = document.getElementById('page-next');
  var form = document.getElementById('user-create-form');
  var fUsername = document.getElementById('u-username');
  var fName = document.getElementById('u-name');
  var fPassword = document.getElementById('u-password');
  var fRole = document.getElementById('u-role');
  var fTeam = document.getElementById('u-team');
  var fExpires = document.getElementById('u-expires');
  var fReason = document.getElementById('u-reason');
  var fSubmit = document.getElementById('u-submit');
  /* D-10 (DESIGN 2.7, 사용자 지시 2026-09-22) — 만들기 폼은 화면에 펼쳐 두지 않는다.
     화면 머리의 「계정 만들기」 단추가 모달을 열고, 폼·제출 단추는 그 안에 있다.
     폼의 id·칸·요청 본문은 그대로다 — 자리만 옮겼다. */
  var createOpen = document.getElementById('user-create-open');
  var createDialog = document.getElementById('user-create-dialog');
  var createDialogX = document.getElementById('user-create-dialog-x');
  var createDialogClose = document.getElementById('user-create-dialog-close');
  /* D-10 2차 B — 만들기 실패 문구는 화면 맨 아래 공통 `#msg` 가 아니라 **모달 안**의
     이 줄에 쓴다(모달이 열려 있는 동안 공통 줄은 뒷면에 가려 보이지 않는다).
     성공·닫기 때 비운다. 문구는 서버가 준 것 그대로다 (PRD S-03). */
  var createMsg = document.getElementById('user-create-msg');

  var state = { page: 1, total: 0, perPage: 50 };

  /** 「계정 만들기」 모달 열기 — 첫 칸(아이디)으로 초점을 옮긴다 (DESIGN 4). */
  function openCreateDialog() {
    if (!createDialog || typeof createDialog.showModal !== 'function' || createDialog.open) {
      return;
    }
    setText(msg, '');
    setText(createMsg, '');
    createDialog.showModal();
    if (fUsername) {
      fUsername.focus();
    }
  }

  /** 닫고 초점을 연 단추로 돌려준다 (키보드만 쓰는 사람이 제자리를 잃지 않게). */
  function closeCreateDialog() {
    if (createDialog && createDialog.open) {
      createDialog.close();
    }
    setText(createMsg, '');
    if (createOpen) {
      createOpen.focus();
    }
  }

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
   *
   * D-10 뒤로 폼이 모달 안에 있으므로 이 상자도 **모달 안**(폼 바로 아래)에 나온다.
   * 그래서 만들기 성공에 모달을 곧바로 닫지 않는다 — 상자의 「닫기」 를 누른 **뒤**에 닫는다.
   * 보였는지를 돌려주어(true/false), 상자가 없을 때만 바로 닫게 한다.
   */
  function showTempPassword(username, temp, where) {
    /* 디버깅 묶음 U2 M8 — `where`(선택) = { before: 상자를 넣을 자리의 다음 요소, onClose: 상자의 「닫기」 뒤에 할 일 }.
       재발급은 계정 「관리」 창 안에 보인다. 전에는 재발급도 이 함수가 **닫혀 있는 「계정 만들기」 창** 안에 상자를 넣어
       임시 비밀번호가 화면에 보이지 않았다(알림 줄만 「재발급했습니다」). */
    var anchor = where && where.before ? where.before : (form ? form.nextSibling : null);
    var host = where && where.before ? where.before.parentNode : (form ? form.parentNode : null);
    if (typeof temp !== 'string' || temp === '' || !host) {
      return false;
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
      // 임시 비밀번호를 다 읽은 뒤에야 모달이 닫힌다 (D-10).
      if (where && typeof where.onClose === 'function') {
        where.onClose();
      } else {
        closeCreateDialog();
      }
    });
    box.appendChild(close);

    host.insertBefore(box, anchor);
    close.focus();
    return true;
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

  /**
   * PATCH /api/users/{uid} — 바뀌는 값 + 사유만 보낸다.
   * 26단계 — 성공했을 때 **무엇이 됐는지 화면에 말한다**(`okText`). 전에는 조용히 다시 읽기만 해서
   * 사용자가 "바뀌었나?" 를 알 수 없었다 (PRD F-40).
   */
  function patch(id, payload, okText) {
    if (typeof id !== 'number' || !isFinite(id) || id <= 0) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    setText(msg, '');
    fetch(APP_BASE + '/api/users/' + String(id), {
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
          setText(msg, typeof okText === 'string' && okText !== '' ? okText : '저장했습니다');
          load();
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ─── 26단계 모달 두 개 (PRD F-40, 사용자 지적 2026-09-15) ─────────────────────
   *
   * 여기 있던 것은 `window.prompt` 여섯 번이었다. 하나라도 취소하면 **조용히 return** 했고,
   * 화면에 아무 알림이 없어 사용자는 「바꿨다」고 느꼈지만 요청은 한 번도 나가지 않았다
   * (그날 감사 로그에 user.role_change 0건이 그 증거였다). 그래서 두 가지를 바꾼다:
   *   ① 입력은 폼 하나(모달)로 받는다 — 셀렉트·날짜·사유. 오타로 팀 번호를 잘못 치는 일이 없다.
   *   ② **조용한 return 을 없앤다** — 취소·무변경·실패를 전부 #msg 로 말한다.
   * 이 화면에 `window.prompt` 는 이제 0개다 (T-53 (c) 가 정적으로 센다).
   *
   * 값은 전부 value·textContent 로만 넣는다(문자열 → HTML 변환 0). 무엇을 바꿀 수 있는지는
   * 여전히 서버가 정한다 — 모달은 요청을 만들 뿐이고 자기 자신 금지·마지막 Admin 보호는
   * `PATCH /api/users/{uid}` 의 Authz::decideUserChange 가 다시 본다 (PRD S-01, T-22).
   */
  var ueDialog = document.getElementById('user-edit');
  var ueForm = document.getElementById('user-edit-form');
  var ueTitle = document.getElementById('ue-title');
  var ueRole = document.getElementById('ue-role');
  var ueTeam = document.getElementById('ue-team');
  var ueExpires = document.getElementById('ue-expires');
  var ueName = document.getElementById('ue-name');
  var ueReason = document.getElementById('ue-reason');
  var ueMsg = document.getElementById('ue-msg');
  var ueCancel = document.getElementById('ue-cancel');

  var rdDialog = document.getElementById('reason-dialog');
  var rdForm = document.getElementById('reason-form');
  var rdTitle = document.getElementById('rd-title');
  var rdLead = document.getElementById('rd-lead');
  var rdReason = document.getElementById('rd-reason');
  var rdMsg = document.getElementById('rd-msg');
  var rdCancel = document.getElementById('rd-cancel');
  var rdSubmit = document.getElementById('rd-submit');

  /** 지금 열려 있는 모달이 끝나면 부를 함수. 닫히는 길은 「확인」·「취소」·Esc 셋뿐이다. */
  var reasonDone = null;
  var editDone = null;
  /** 지금 모달이 고치고 있는 계정 행(서버가 준 값 그대로). 열 때 담고 닫을 때 비운다. */
  var editItem = null;

  function closeReason(value) {
    var done = reasonDone;
    reasonDone = null;
    if (rdDialog && rdDialog.open) {
      rdDialog.close();
    }
    if (done) {
      done(value);
    }
  }

  /**
   * 사유만 받는다 (임시 비밀번호 재발급 · 2단계 인증 초기화 · 비활성화/재활성화).
   * `done(사유)` 또는 `done(null)`(취소) 로 끝난다 — 어느 쪽이든 **반드시 부른다**.
   */
  function askReason(what, done, okLabel) {
    if (!rdDialog || !rdForm) {
      setText(msg, GENERIC_ERROR);
      done(null);
      return;
    }
    reasonDone = done;
    setText(rdTitle, what);
    // 디버깅 묶음 U2 — 바닥 단추는 그 동작의 이름(「확인」은 재인증 창 한 곳만 — DESIGN 5장)
    if (rdSubmit) {
      setText(rdSubmit, typeof okLabel === 'string' && okLabel !== '' ? okLabel : '진행하기');
    }
    setText(rdLead, '이 행동은 사유와 함께 감사 로그에 남습니다.');
    setText(rdMsg, '');
    if (rdReason) {
      rdReason.value = '';
    }
    rdDialog.showModal();
    if (rdReason) {
      rdReason.focus();
    }
  }

  if (rdForm) {
    rdForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var text = rdReason ? rdReason.value : '';
      if (text.replace(/\s+/g, '') === '') {
        setText(rdMsg, '사유를 적어야 합니다.');
        return;
      }
      closeReason(text);
    });
  }
  if (rdCancel) {
    rdCancel.addEventListener('click', function () {
      closeReason(null);
    });
  }
  var rdX = document.getElementById('rd-x');
  if (rdX) {
    rdX.addEventListener('click', function () {
      closeReason(null);
    });
  }
  if (rdDialog) {
    // Esc 로 닫아도 「취소했습니다」 가 남게 한다 (조용한 종료 금지).
    rdDialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      closeReason(null);
    });
  }

  function closeEdit(changed) {
    var done = editDone;
    editDone = null;
    editItem = null;
    if (ueDialog && ueDialog.open) {
      ueDialog.close();
    }
    if (done) {
      done(changed);
    }
  }

  /** 셀렉트를 값으로 맞춘다. 없는 값이면 아무것도 고르지 않는다(화면이 값을 지어내지 않는다). */
  function selectValue(select, value) {
    if (!select) {
      return;
    }
    select.value = value === null || value === undefined ? '' : String(value);
  }

  /** 계정 변경 모달을 연다. 현재 값을 채워 두고, **바뀐 칸만** 보낸다. */
  function openUserEdit(item) {
    if (!ueDialog || !ueForm) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    setText(msg, '');
    setText(ueTitle, '계정 「' + personLabel(item.display_name, item.username) + '」 변경');
    setText(ueMsg, '');
    if (ueName) {
      // 5-A″ — 지금 이름(없으면 빈 칸). 바꾸지 않으면 보내지 않는다.
      ueName.value = typeof item.display_name === 'string' ? item.display_name : '';
    }
    selectValue(ueRole, item.role);
    selectValue(ueTeam, item.team_id);
    if (ueExpires) {
      // 서버가 주는 만료는 날짜(YYYY-MM-DD)다. 없으면 비워 두고, 비운 채 저장하면 안 보낸다.
      ueExpires.value = typeof item.expires_at === 'string' ? item.expires_at.slice(0, 10) : '';
    }
    if (ueReason) {
      ueReason.value = '';
    }

    editItem = item;
    editDone = function (payload) {
      if (payload === null) {
        setText(msg, '취소했습니다');
        return;
      }
      if (payload === false) {
        setText(msg, '바뀐 내용이 없습니다');
        return;
      }
      patch(item.id, payload, '저장했습니다');
    };
    ueDialog.showModal();
    if (ueReason) {
      ueReason.focus();
    }
  }

  /* ─── F-59 → **F-66** 「프로그램 토큰」 모달 (ARCH 4.6 · 4.7, API.md 13장) ────────
   *
   * 계정 관리 화면에서 그 계정의 개인 프로그램 토큰을 **보고 회수한다**.
   * 창구 둘(`GET`·`DELETE /api/users/{uid}/api-tokens…`)은 관리자 세션 + CSRF 이고,
   * 이 화면에 들어온 것으로 권한이 생기지 않는다 — 막는 것은 서버(user.manage + 재인증
   * 관문 [3-2])이고 여기 있는 코드는 요청을 만들 뿐이다 (PRD S-01).
   *
   * ★ **발급 폼이 없다**(F-66, 2026-09-22). 관리자가 남을 대신해 자격을 만드는 길이
   *   사라지는 것이 R-91 의 핵심 완화이고, 라우트 자체가 없어 404 다. 관리자 자신의
   *   토큰도 이제 「내 계정」 화면에서 본인이 발급한다(비밀번호 + 2단계 코드).
   * ★ 표에 나오는 값(이름·hint·범위)은 전부 `setText`(textContent)다. 이 구역에는 문자열을
   *   HTML 로 바꾸는 코드가 한 곳도 없다 (PRD S-03).
   * ★ 표를 그리는 코드는 「내 계정」 절과 **한 벌**이다(모듈 위쪽 `addTokenRow`) —
   *   두 벌로 두면 한쪽만 고치는 날 두 화면의 말이 달라진다.
   */
  var tkDialog = null;   // 전용 모달
  var tkTitle = null;    // 모달 제목 (「프로그램 토큰 — <username>」)
  var tkBox = null;      // 구역 전체 (한 번만 만든다)
  var tkBody = null;     // 표의 tbody
  var tkMsg = null;      // 이 구역의 알림 줄 (서버 문구를 그대로 쓴다)
  var tkUid = null;      // 지금 보고 있는 계정 id
  var tkConfirm = null;        // 회수 확인 줄(`.notice`) — 디버깅 묶음 U H2
  var tkConfirmText = null;
  var tkConfirmCancel = null;

  /** 「내 계정」 절과 같은 표를 그린다 — 칸 차례만 다르다(`<thead>` 순서 그대로) */
  var TK_COLUMNS = ['name', 'hint', 'scope', 'expires', 'created', 'used', 'state', 'actions'];

  /** 이 구역의 요청 하나 — 공용 조각에 그대로 넘긴다(세션 쿠키 + CSRF, 재인증 재시도 포함) */
  function tokenFetch(method, path, payload, retry) {
    return tokenApiFetch(csrfToken, method, path, payload, retry);
  }

  /** 표 한 줄 — 「내 계정」 절과 같은 공용 함수로 그린다(칸 차례만 이 화면 것) */
  function addTokenRow(token) {
    addTokenRowShared({ body: tkBody, columns: TK_COLUMNS, onRevoke: revokeToken }, token);
  }

  /** 목록을 다시 읽어 표를 그린다. 실패는 알림 줄에 **서버 문구**로 말한다. */
  function loadTokens() {
    if (tkUid === null || !tkBody) {
      return;
    }
    var uid = tkUid;
    tokenFetch('GET', APP_BASE + '/api/users/' + String(uid) + '/api-tokens', null, loadTokens)
      .then(function (result) {
        if (result === null || tkUid !== uid) {
          return null;
        }
        while (tkBody.firstChild) {
          tkBody.removeChild(tkBody.firstChild);
        }
        if (!result.ok) {
          setText(tkMsg, errorMessage(result.data));
          return null;
        }
        var tokens = result.data && Array.isArray(result.data.tokens) ? result.data.tokens : [];
        if (tokens.length === 0) {
          setText(tkMsg, '발급된 토큰이 없습니다.');
          return null;
        }
        tokens.forEach(addTokenRow);
        return null;
      })
      .catch(function () {
        setText(tkMsg, GENERIC_ERROR);
      });
  }

  /** 회수 — 먼저 **창 안의 확인 줄**로 묻는다(디버깅 묶음 U H2 · 2026-09-26). 예전엔 브라우저 기본 확인 창(window.confirm)이었다.
      이 창이 이미 모달이라 확인 창(#confirm-dialog)을 또 띄우지 않는다 — 모달 안에 모달을 열지 않는다(DESIGN 2.7).
      모양은 이력 창의 되돌리기 확인과 같다(`.notice` + 「그만두기」 `.btn--quiet` · 「회수하기」 `.btn--danger`, 처음 초점은 「그만두기」).
      「그만두기」·Esc(창을 닫음)는 요청 0. 회수한 순간부터 그 토큰은 401 이다. */
  var tkPending = null;   // 확인 줄이 묻고 있는 { token, button }
  function revokeToken(token, button) {
    if (tkUid === null || !token || typeof token.id !== 'number' || !tkConfirm) {
      return;
    }
    tkPending = { token: token, button: button || null };
    setText(tkMsg, '');
    setText(tkConfirmText, '토큰 「' + String(token.name) + '」 을(를) 회수합니다. 그 프로그램은 바로 쓸 수 없게 됩니다.');
    tkConfirm.hidden = false;
    tkConfirmCancel.focus();
  }
  function hideTokenConfirm(returnFocus) {
    var pending = tkPending;
    tkPending = null;
    if (tkConfirm) {
      tkConfirm.hidden = true;
    }
    if (returnFocus && pending && pending.button && pending.button.isConnected) {
      pending.button.focus();
    }
  }
  function sendRevokeToken(token) {
    if (tkUid === null || !token || typeof token.id !== 'number') {
      return;
    }
    var uid = tkUid;
    setText(tkMsg, '');
    tokenFetch('DELETE', APP_BASE + '/api/users/' + String(uid) + '/api-tokens/' + String(token.id),
      null, function () { sendRevokeToken(token); })
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(tkMsg, errorMessage(result.data));
          return null;
        }
        setText(tkMsg, '토큰을 회수했습니다.');
        loadTokens();
        return null;
      })
      .catch(function () {
        setText(tkMsg, GENERIC_ERROR);
      });
  }

  /** 구역과 그것을 담는 **전용 모달**을 한 번 만든다. 두 번째부터는 있는 것을 다시 쓴다. */
  function buildTokenSection() {
    if (tkBox) {
      return;
    }
    tkDialog = document.createElement('dialog');
    tkDialog.id = 'token-dialog';
    tkDialog.className = 'modal token-modal';
    tkDialog.setAttribute('aria-labelledby', 'token-title');

    var head = document.createElement('div');
    head.className = 'modal__head';
    tkTitle = document.createElement('h2');
    tkTitle.className = 'modal__title';
    tkTitle.id = 'token-title';
    setText(tkTitle, '프로그램 토큰');
    head.appendChild(tkTitle);
    var tkX = makeIconButton('x', '닫기');   // 디버깅 묶음 U2 M3 — 머리에 ✕
    tkX.addEventListener('click', function () { closeTokenSection(); });
    head.appendChild(tkX);
    tkDialog.appendChild(head);

    var body = document.createElement('div');
    body.className = 'modal__body';
    tkDialog.appendChild(body);

    tkBox = document.createElement('section');
    tkBox.className = 'token-box';

    // 패널 머리 꼴 — 제목 위, 설명 한 줄 아래 (DESIGN 2.3 · 9장 C-6).
    var boxHead = document.createElement('div');
    boxHead.className = 'panel__head';

    var title = document.createElement('h3');
    title.className = 'panel__title';
    setText(title, '프로그램 토큰');
    boxHead.appendChild(title);

    var lead = document.createElement('p');
    lead.className = 'panel__sub';
    setText(lead, '이 계정의 프로그램 토큰입니다. 발급은 본인만 「내 계정」 화면에서 하고, 여기서는 보고 회수합니다.');
    boxHead.appendChild(lead);

    tkBox.appendChild(boxHead);

    /* 디버깅 묶음 U H1 — 다른 화면과 같은 표(`table.files` + `.table-wrap`). 좁은 폭에서는 같은 규칙으로 행이 쌓인다(DESIGN 2.4) */
    var tableWrap = document.createElement('div');
    tableWrap.className = 'table-wrap';
    var table = document.createElement('table');
    table.className = 'files token-box__table';
    var head = document.createElement('thead');
    var headRow = document.createElement('tr');
    // F-66 — 범위·만료 두 칸이 늘었다 (위 TK_COLUMNS 와 같은 차례여야 한다).
    ['이름', '앞 6자', '범위', '만료', '발급일', '마지막 사용 · 회수', '상태', ''].forEach(function (label) {
      var th = document.createElement('th');
      setText(th, label);
      headRow.appendChild(th);
    });
    head.appendChild(headRow);
    table.appendChild(head);
    tkBody = document.createElement('tbody');
    table.appendChild(tkBody);
    tableWrap.appendChild(table);
    tkBox.appendChild(tableWrap);
    watchStackTable(table);

    // 회수 확인 줄 — 표 아래, 알림 줄 위(이력 창의 되돌리기 확인과 같은 모양 · 새 클래스 0)
    tkConfirm = document.createElement('div');
    tkConfirm.className = 'notice';
    tkConfirm.setAttribute('role', 'alert');
    tkConfirm.hidden = true;
    tkConfirmText = document.createElement('p');
    tkConfirmText.className = 'notice__title';
    tkConfirm.appendChild(tkConfirmText);
    var tkConfirmNote = document.createElement('p');
    tkConfirmNote.className = 'muted';
    setText(tkConfirmNote, '되돌릴 수 없습니다. 필요하면 본인이 「내 계정」에서 새로 발급합니다.');
    tkConfirm.appendChild(tkConfirmNote);
    var tkConfirmActions = document.createElement('div');
    tkConfirmActions.className = 'toolbar';
    tkConfirmCancel = document.createElement('button');
    tkConfirmCancel.type = 'button';
    tkConfirmCancel.className = 'btn btn--quiet btn--small';
    setText(tkConfirmCancel, '그만두기');
    tkConfirmCancel.addEventListener('click', function () { hideTokenConfirm(true); });
    tkConfirmActions.appendChild(tkConfirmCancel);
    var tkConfirmOk = document.createElement('button');
    tkConfirmOk.type = 'button';
    tkConfirmOk.className = 'btn btn--danger btn--small';
    setText(tkConfirmOk, '회수하기');
    tkConfirmOk.addEventListener('click', function () {
      var pending = tkPending;
      hideTokenConfirm(false);
      if (pending) {
        sendRevokeToken(pending.token);
      }
    });
    tkConfirmActions.appendChild(tkConfirmOk);
    tkConfirm.appendChild(tkConfirmActions);
    tkBox.appendChild(tkConfirm);

    // F-66 — 발급 폼이 있던 자리다. 관리자는 남의 토큰을 **만들지 않는다**(라우트도 없다).
    //         발급은 본인이 「내 계정」 화면에서 비밀번호 + 2단계 코드로 한다.

    tkMsg = document.createElement('p');
    tkMsg.className = 'msg';
    tkMsg.setAttribute('role', 'alert');
    tkMsg.setAttribute('aria-live', 'polite');
    tkBox.appendChild(tkMsg);

    body.appendChild(tkBox);

    var foot = document.createElement('div');
    foot.className = 'modal__foot';
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn btn--quiet';
    setText(close, '닫기');
    close.addEventListener('click', closeTokenSection);
    foot.appendChild(close);
    tkDialog.appendChild(foot);

    // Esc 로 닫아도 보여 준 토큰 값이 남지 않게 같은 길로 보낸다.
    tkDialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      closeTokenSection();
    });

    document.body.appendChild(tkDialog);
  }

  /** 닫는다 — **보여 준 토큰 값을 지우고** 나서 닫는다(닫으면 다시 볼 수 없다). */
  function closeTokenSection() {
    clearIssuedToken();
    hideTokenConfirm(false);
    tkUid = null;
    if (tkDialog && tkDialog.open) {
      tkDialog.close();
    }
  }

  /** 모달을 연다 — 구역을 비우고 그 계정의 토큰을 읽는다. 자기 자신도 대상이 된다. */
  function openTokenSection(item) {
    buildTokenSection();
    if (!tkBox || !tkDialog) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    clearIssuedToken();                    // 앞서 본 토큰 값을 다음 계정으로 끌고 가지 않는다
    hideTokenConfirm(false);               // 앞 계정에서 묻던 확인 줄도 버린다
    setText(tkTitle, '프로그램 토큰 — ' + item.username);
    setText(tkMsg, '');
    while (tkBody && tkBody.firstChild) {
      tkBody.removeChild(tkBody.firstChild);
    }
    tkUid = typeof item.id === 'number' ? item.id : null;
    loadTokens();
    tkDialog.showModal();
  }

  if (ueForm) {
    ueForm.addEventListener('submit', function (event) {
      event.preventDefault();
      var payload = {};
      var before = editItem || {};

      // 5-A″ 이름 — 앞뒤 공백을 뺀 값이 지금과 다를 때만 보낸다. 비우면 null(이름 지우기).
      // 길이·글자 규칙은 서버(`tbox_clean_display_name`)가 정한다 — 화면은 maxlength 만 둔다.
      var beforeName = typeof before.display_name === 'string' ? before.display_name : '';
      var nameValue = ueName ? ueName.value.trim() : beforeName;
      if (ueName && nameValue !== beforeName) {
        payload.display_name = nameValue === '' ? null : nameValue;
      }

      if (ueRole && ueRole.value !== String(before.role)) {
        payload.role = ueRole.value;
      }
      var beforeTeam = before.team_id === null || before.team_id === undefined
        ? '' : String(before.team_id);
      if (ueTeam && ueTeam.value !== beforeTeam) {
        payload.team_id = ueTeam.value === '' ? null : ueTeam.value;
      }
      var beforeExpires = typeof before.expires_at === 'string' ? before.expires_at.slice(0, 10) : '';
      if (ueExpires && ueExpires.value !== beforeExpires && ueExpires.value !== '') {
        payload.expires_at = ueExpires.value;
      }

      if (Object.keys(payload).length === 0) {
        closeEdit(false);
        return;
      }
      var reasonText = ueReason ? ueReason.value : '';
      if (reasonText.replace(/\s+/g, '') === '') {
        setText(ueMsg, '사유를 적어야 합니다.');
        return;
      }
      payload.reason_text = reasonText;
      closeEdit(payload);
    });
  }
  if (ueCancel) {
    ueCancel.addEventListener('click', function () {
      closeEdit(null);
    });
  }
  // 디버깅 묶음 U2 M3 — 머리의 ✕ 도 「닫기」와 같은 길(취소했다고 말한다)
  var ueX = document.getElementById('ue-x');
  if (ueX) {
    ueX.addEventListener('click', function () {
      closeEdit(null);
    });
  }
  if (ueDialog) {
    ueDialog.addEventListener('cancel', function (event) {
      event.preventDefault();
      closeEdit(null);
    });
  }

  /* ─── d74 로그인 아이디 바꾸기 창 (#user-rename, 2026-09-27 — SECURITY R-06 완화) ─────────────────────────────
   * `PATCH /api/users/{uid}` 에 `{ username, reason_text }` 만 보낸다. 규칙(형식·이미 있음·검토 접두·검토 계정·공용 토큰)은
   * 서버 한 곳(`_username_common.php`)이 정한다 — 여기는 빈 칸·같은 이름만 먼저 말한다(요청 0).
   * 막히면 **서버 문구 그대로** 이 창 안(#ur-msg)에 말하고 창을 연 채 둔다(고쳐 다시 보낸다). 성공하면 창을 닫고 목록을 다시 읽는다 —
   * 목록·상세가 새 이름으로 보인다. 재인증 관문(403 next:"reauth")이면 공용 재인증 창을 띄우고, 통과하면 **같은 값을 다시** 보낸다.
   * 새 아이디는 입력칸 값으로만 있고, 창이 닫히면(어느 길이든 — close 이벤트) 비운다. 알림 줄에도 새 아이디를 적지 않는다.
   */
  var urDialog = document.getElementById('user-rename');
  var urForm = document.getElementById('user-rename-form');
  var urTitle = document.getElementById('ur-title');
  var urInput = document.getElementById('ur-username');
  var urReason = document.getElementById('ur-reason');
  var urMsg = document.getElementById('ur-msg');
  var urSubmit = document.getElementById('ur-submit');
  var urItem = null;   // 지금 아이디 창이 고치는 계정(서버가 준 행 그대로). 닫으면 비운다
  var urBusy = false;  // 보내는 중에는 한 번 더 보내지 않는다

  function closeRename(note) {
    urItem = null;
    if (urDialog && urDialog.open) {
      urDialog.close();
    }
    if (typeof note === 'string') {
      setText(msg, note);
    }
  }

  function openRename(item) {
    if (!urDialog || !urForm || !urInput || !urReason || typeof urDialog.showModal !== 'function') {
      setText(msg, GENERIC_ERROR);
      return;
    }
    urItem = item;
    setText(msg, '');
    setText(urTitle, '계정 「' + personLabel(item.display_name, item.username) + '」 로그인 아이디 바꾸기');
    setText(urMsg, '');
    urInput.value = '';
    urReason.value = '';
    urBusy = false;
    if (urSubmit) {
      urSubmit.disabled = false;
    }
    urDialog.showModal();
    urInput.focus();
  }

  function sendRename(id, payload) {
    if (typeof id !== 'number' || !isFinite(id) || id <= 0) {
      setText(urMsg, GENERIC_ERROR);
      return;
    }
    urBusy = true;
    if (urSubmit) {
      urSubmit.disabled = true;
    }
    fetch(APP_BASE + '/api/users/' + String(id), {
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
          if (handleReauth(response, data, function () { sendRename(id, payload); })) {
            return null;
          }
          if (toGate(response, data)) {
            return null;
          }
          if (!response.ok) {
            setText(urMsg, errorMessage(data));
            return null;
          }
          closeRename('로그인 아이디를 바꿨습니다. 그 사람은 다음 로그인부터 새 아이디를 씁니다.');
          load();
          return null;
        });
      })
      .catch(function () {
        setText(urMsg, GENERIC_ERROR);
      })
      .then(function () {
        urBusy = false;
        if (urSubmit) {
          urSubmit.disabled = false;
        }
      });
  }

  if (urForm) {
    urForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (urBusy || !urItem) {
        return;
      }
      var value = urInput.value.trim();
      if (value === '') {
        setText(urMsg, '새 아이디를 적어야 합니다.');
        urInput.focus();
        return;
      }
      if (value === urItem.username) {
        setText(urMsg, '지금 아이디와 같습니다 — 바뀐 내용이 없습니다.');
        urInput.focus();
        return;
      }
      var reasonText = urReason.value;
      if (reasonText.replace(/\s+/g, '') === '') {
        setText(urMsg, '사유를 적어야 합니다.');
        urReason.focus();
        return;
      }
      setText(urMsg, '');
      sendRename(urItem.id, { username: value, reason_text: reasonText });
    });
  }
  if (urDialog) {
    var urCancel = function () {
      closeRename('취소했습니다');
    };
    document.getElementById('ur-cancel').addEventListener('click', urCancel);
    document.getElementById('ur-x').addEventListener('click', urCancel);
    urDialog.addEventListener('cancel', function (event) {
      // Esc 로 닫아도 「취소했습니다」 가 남게 한다 (조용한 종료 금지 — 다른 창과 같다).
      event.preventDefault();
      urCancel();
    });
    urDialog.addEventListener('close', function () {
      // 어느 길로 닫혀도 새 아이디·사유를 DOM 에 남기지 않는다.
      if (urDialog.open) {
        return;
      }
      if (urInput) {
        urInput.value = '';
      }
      if (urReason) {
        urReason.value = '';
      }
      setText(urMsg, '');
    });
  }

  /* ─── 계정 한 줄의 동작 — 디버깅 묶음 U2 M8 (2026-09-26) ──────────────────────────────
   * 전에는 줄마다 글자 단추 다섯(이름·역할·팀·만료 · 임시 비밀번호 재발급 · 2단계 인증 초기화 · 비활성화 · 프로그램 토큰)이었다 —
   * 표가 단추로 가득 차고 폴드 820 에서도 잘렸다. 이제 줄에는 「관리」 하나, 상세 창(#user-manage-dialog)에 그 계정의 사실과
   * **기존 동작 그대로**(같은 함수 · 같은 창구 · 같은 사유 창)를 둔다. 동작을 고르면 상세 창을 **닫고** 그 동작의 창을 연다 —
   * 창 안에 창을 겹쳐 열지 않는다(DESIGN 2.7). 무엇을 할 수 있는지는 여전히 서버가 정한다(자기 자신 금지·마지막 관리자 보호 —
   * Authz::decideUserChange, PRD S-01). 화면이 가리는 것(본인 줄의 바꾸기 넷)은 전과 같은 편의다.
   */
  function resetPassword(item) {
    askReason('계정 「' + item.username + '」 임시 비밀번호 재발급', function (reason) {
      if (reason === null) {
        setText(msg, '취소했습니다');
        return;
      }
      setText(msg, '');
      postJson(APP_BASE + '/api/users/' + String(item.id) + '/password/reset', { reason_text: reason })
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            setText(msg, errorMessage(result.data));
            return null;
          }
          // 디버깅 묶음 U2 M8 — 상자는 **그 계정의** 「관리」 창 맨 위에(한 번만 보인다 · 창을 닫으면 지운다).
          // 독립 검토 낮음 — 답을 기다리는 동안 다른 계정의 「관리」 창을 열어 두었으면 그 창에 넣지 않는다: 닫고 이 계정 창으로 다시 연다
          if (umDialog && umDialog.open && (umItem === null || umItem.id !== item.id)) {
            umDialog.close();   // close 이벤트가 그 창에 남은 상자·값도 지운다
          }
          if (umDialog && !umDialog.open) {
            openManage(item);
          }
          if (umDialog && umDialog.open && umItem !== null && umItem.id === item.id) {
            showTempPassword(item.username, result.data.temp_password, {
              before: umFacts,
              onClose: function () {
                if (umDialog.open) {
                  umDialog.close();
                }
              }
            });
          }
          // 결과 한 줄은 창을 연 **뒤**에(openManage 가 알림 줄을 비운다)
          setText(msg, '임시 비밀번호를 재발급하고 그 계정의 모든 세션을 끊었습니다.');
          load();
          return null;
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
        });
    }, '재발급하기');
  }

  function resetMfa(item) {
    askReason('계정 「' + item.username + '」 2단계 인증 초기화', function (reason) {
      if (reason === null) {
        setText(msg, '취소했습니다');
        return;
      }
      setText(msg, '');
      postJson(APP_BASE + '/api/users/' + String(item.id) + '/mfa/reset', { reason_text: reason })
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
    }, '초기화하기');
  }

  function toggleActive(item) {
    askReason(
      '계정 「' + item.username + '」 ' + (item.is_active === true ? '비활성화' : '재활성화'),
      function (reason) {
        if (reason === null) {
          setText(msg, '취소했습니다');
          return;
        }
        patch(
          item.id,
          { is_active: item.is_active !== true, reason_text: reason },
          item.is_active === true ? '비활성화했습니다' : '다시 활성화했습니다'
        );
      },
      item.is_active === true ? '비활성화하기' : '다시 활성화하기'
    );
  }

  var umDialog = document.getElementById('user-manage-dialog');
  var umSub = document.getElementById('um-sub');
  var umFacts = document.getElementById('user-manage-facts');
  var umSelf = document.getElementById('um-self');
  var umActiveTitle = document.getElementById('um-active-title');
  var umActiveDesc = document.getElementById('um-active-desc');
  var umItem = null;   // 지금 상세 창이 보여 주는 계정(서버가 준 행 그대로). 닫으면 비운다

  /** 이름표 + 값 한 칸(`.info-item`) — 글자는 전부 textContent */
  function umFact(label, value, title) {
    var itemBox = document.createElement('div');
    itemBox.className = 'info-item';
    var l = document.createElement('span');
    l.className = 'field__label';
    setText(l, label);
    var v = document.createElement('span');
    v.className = 'info-item__value';
    setText(v, value === null || value === undefined || value === '' ? '—' : String(value));
    if (typeof title === 'string' && title !== '') {
      v.setAttribute('title', title);
    }
    itemBox.appendChild(l);
    itemBox.appendChild(v);
    umFacts.appendChild(itemBox);
  }

  function openManage(item) {
    if (!umDialog || typeof umDialog.showModal !== 'function' || umDialog.open) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    umItem = item;
    setText(msg, '');
    setText(umSub, personLabel(item.display_name, item.username));
    umFacts.replaceChildren();
    umFact('아이디', item.username);
    umFact('역할', roleLabel(item.role), typeof item.role === 'string' ? item.role : '');
    umFact('팀', item.team_name);
    umFact('상태', item.is_active === true ? '활성' : '비활성');
    umFact('만료', item.expires_at === null || item.expires_at === undefined ? '—'
      : shortWhen(item.expires_at).text + (item.is_expired === true ? ' (만료)' : ''));
    umFact('2단계 인증', item.mfa_enabled === true ? '켜짐' : '꺼짐');
    umFact('만든 시각', item.created_at ? shortWhen(item.created_at).text : '—');
    var self = item.is_self === true;
    umSelf.hidden = !self;
    Array.prototype.forEach.call(umDialog.querySelectorAll('[data-user-action]'), function (button) {
      // 본인 줄은 바꾸기 넷을 보이지 않는다(서버 self_change 와 같은 규칙 — 전에도 그 줄엔 「프로그램 토큰」만 있었다)
      button.hidden = self && button.getAttribute('data-user-action') !== 'tokens';
    });
    // 활성 계정의 「비활성화」는 위험 동작(빨간 아이콘 — `.card--warn`), 「다시 활성화」는 보통 카드(D-2 · 팀 토글과 같은 규칙)
    var activeCard = umDialog.querySelector('[data-user-action="active"]');
    activeCard.classList.toggle('card--warn', item.is_active === true);
    setText(umActiveTitle, item.is_active === true ? '비활성화' : '다시 활성화');
    setText(umActiveDesc, item.is_active === true
      ? '그 사람은 바로 로그인할 수 없게 됩니다. 사유가 필요하고, 다시 활성화할 수 있습니다.'
      : '다시 로그인할 수 있게 합니다. 사유가 필요합니다.');
    umDialog.showModal();
    var first = umDialog.querySelector('[data-user-action]:not([hidden])');
    if (first) {
      first.focus();
    }
  }

  if (umDialog) {
    var umClose = function () {
      if (umDialog.open) {
        umDialog.close();
      }
    };
    document.getElementById('um-close').addEventListener('click', umClose);
    document.getElementById('um-x').addEventListener('click', umClose);
    umDialog.addEventListener('close', function () {
      /* close 이벤트는 늦게(다음 차례에) 온다 — 재발급 답이 다른 계정 창을 닫고 곧바로 이 계정 창을 다시 열면, 앞 창의 이벤트가
         **다시 연 창**의 상자와 계정을 지웠다(독립 검토 고침 뒤 수락 시험이 잡음). 창이 다시 열려 있으면 앞 창의 이벤트다 — 건너뛴다 */
      if (umDialog.open) {
        return;
      }
      umItem = null;
      // 임시 비밀번호 상자가 남아 있으면 값을 지우고 뺀다(Esc 로 닫아도 — 다시 볼 수 없는 값이다, R-39)
      var box = document.getElementById('temp-password-box');
      if (box && umDialog.contains(box)) {
        Array.prototype.forEach.call(box.querySelectorAll('input'), function (f) { f.value = ''; });
        box.parentNode.removeChild(box);
      }
    });
    Array.prototype.forEach.call(umDialog.querySelectorAll('[data-user-action]'), function (button) {
      button.addEventListener('click', function () {
        var item = umItem;
        var action = button.getAttribute('data-user-action');
        // 상세 창을 먼저 닫는다 — 다음 창을 그 위에 겹쳐 열지 않는다(DESIGN 2.7). 닫으면 초점은 줄의 「관리」로 돌아가고,
        // 다음 창이 닫히면 다시 그 「관리」로 돌아간다(<dialog> 기본 동작).
        umClose();
        if (!item) {
          return;
        }
        if (action === 'edit') {
          openUserEdit(item);
        } else if (action === 'username') {
          openRename(item);   // d74 — 로그인 아이디 바꾸기 창
        } else if (action === 'password') {
          resetPassword(item);
        } else if (action === 'mfa') {
          resetMfa(item);
        } else if (action === 'tokens') {
          setText(msg, '');
          openTokenSection(item);
        } else if (action === 'active') {
          toggleActive(item);
        }
      });
    });
  }

  function addActions(row, item) {
    var td = document.createElement('td');
    td.className = 'actions';
    if (item.is_self === true) {
      var selfLabel = document.createElement('span');
      selfLabel.className = 'muted';
      setText(selfLabel, '(본인)');
      td.appendChild(selfLabel);
    }
    var manage = document.createElement('button');
    manage.type = 'button';
    manage.className = 'btn btn--quiet btn--small';
    manage.setAttribute('aria-haspopup', 'dialog');
    manage.setAttribute('aria-label', '관리 — ' + personLabel(item.display_name, item.username));
    setText(manage, '관리');
    manage.addEventListener('click', function () {
      openManage(item);
    });
    td.appendChild(manage);
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
      addCell(row, personLabel(item.display_name, item.username));
      addRoleCell(row, item.role);   // 디버깅 묶음 U M7 — 한글 글자 · title 에 원래 값
      addCell(row, item.team_name);
      addCell(row, item.is_active === true ? '활성' : '비활성');
      item.expires_at === null
        ? addCell(row, '—')
        : addWhenCell(row, item.expires_at, item.is_expired === true ? ' (만료)' : '');
      addCell(row, item.mfa_enabled === true ? '켜짐' : '꺼짐');
      addWhenCell(row, item.created_at);
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
    fetch(APP_BASE + '/api/users?page=' + encodeURIComponent(String(state.page)), {
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

  /* 26단계 — 같은 목록을 **만들기 폼과 변경 모달 둘 다**에 채운다 (PRD F-40).
     모달이 팀 「번호」 대신 이름을 고르게 하려면 목록이 필요하고, 목록을 두 벌로 두면
     언젠가 한쪽만 낡는다. 값은 전부 value·textContent 로만 넣는다 (PRD S-03). */
  function loadTeams() {
    var targets = [];
    if (fTeam) {
      var blank = document.createElement('option');
      blank.value = '';
      setText(blank, '(팀 없음)');
      fTeam.appendChild(blank);
      targets.push(fTeam);
    }
    if (ueTeam) {
      targets.push(ueTeam);   // 「— 팀 없음 —」 은 서버 마크업에 이미 있다
    }
    if (targets.length === 0) {
      return;
    }
    fetch(APP_BASE + '/api/teams', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) {
          return null;
        }
        return readJson(response).then(function (data) {
          var teams = data && Array.isArray(data.teams) ? data.teams : [];
          teams.forEach(function (team) {
            targets.forEach(function (select) {
              var option = document.createElement('option');
              option.value = String(team.id);
              setText(option, team.name);
              select.appendChild(option);
            });
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
      setText(createMsg, '');
      // 비밀번호는 보내지 않는다 — 서버가 만들어 201 응답으로 한 번 돌려준다 (F-16 ①)
      var payload = {
        username: fUsername.value,
        role: fRole.value,
        reason_text: fReason.value
      };
      // 5-A″ — 이름은 선택이다. 비어 있으면 보내지 않는다(이름 없음).
      if (fName && fName.value.trim() !== '') {
        payload.display_name = fName.value;
      }
      if (fTeam && fTeam.value !== '') {
        payload.team_id = fTeam.value;
      }
      if (fExpires && fExpires.value !== '') {
        payload.expires_at = fExpires.value;
      }
      fSubmit.disabled = true;
      var newName = String(payload.username);
      postJson(APP_BASE + '/api/users', payload)
        .then(function (result) {
          if (result === null) {
            return null;
          }
          if (result.status !== 201) {
            // D-10 2차 B — 실패는 **모달 안**에서 말한다(모달은 열린 채다).
            setText(createMsg, errorMessage(result.data));
            return null;
          }
          form.reset();
          setText(createMsg, '');
          setText(msg, '계정을 만들었습니다. 아래 임시 비밀번호를 본인에게만 전달하세요 — 첫 로그인에서 반드시 바꾸게 됩니다.');
          // 성공했을 때만 모달을 닫는다. 임시 비밀번호 상자가 떴으면 그 상자의 「닫기」 가 닫는다 (D-10).
          if (!showTempPassword(newName, result.data.temp_password)) {
            closeCreateDialog();
          }
          load();
          return null;
        })
        .catch(function () {
          setText(createMsg, GENERIC_ERROR);
        })
        .then(function () {
          fSubmit.disabled = false;
        });
    });
  }
  // D-10 — 화면 머리의 「계정 만들기」 단추 · 모달의 ✕ 와 「닫기」 (Esc 는 <dialog> 기본)
  if (createOpen) {
    createOpen.addEventListener('click', openCreateDialog);
  }
  [createDialogX, createDialogClose].forEach(function (btn) {
    if (btn) {
      btn.addEventListener('click', function () {
        closeCreateDialog();
      });
    }
  });
  if (createDialog) {
    // Esc 로 닫으면 위의 처리기를 거치지 않는다 — 지난 오류 문구를 남겨 두지 않는다 (D-10 2차 B)
    createDialog.addEventListener('close', function () {
      if (createDialog.open) {
        return;   // 늦게 온 앞 창의 이벤트(위 「관리」 창과 같은 까닭)
      }
      setText(createMsg, '');
      /* 독립 검토 낮음(기존 결함 · R-39 「한 번만」) — 만든 계정의 임시 비밀번호 상자는 상자의 「닫기」로만 지웠다. Esc·✕·바깥으로
         창을 닫으면 상자와 값이 창 안에 남아, 다음에 「계정 만들기」를 열면 **지난 임시 비밀번호가 다시 보였다**. 닫히는 모든 길에서 지운다. */
      var box = document.getElementById('temp-password-box');
      if (box && createDialog.contains(box)) {
        Array.prototype.forEach.call(box.querySelectorAll('input'), function (f) { f.value = ''; });
        box.parentNode.removeChild(box);
      }
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
    card.className = team.id === state.selected ? 'card card--link is-selected' : 'card card--link';
    card.setAttribute('aria-pressed', team.id === state.selected ? 'true' : 'false');

    /* 누를 수 있는 카드 한 벌 (DESIGN 2.3, C-7): 아이콘 없이 .card__body 하나만 둔다. */
    var body = document.createElement('span');
    body.className = 'card__body';

    var head = document.createElement('span');
    head.className = 'card__head';
    var name = document.createElement('strong');
    name.className = 'card__title';
    setText(name, team.name);
    head.appendChild(name);

    var badge = document.createElement('span');
    badge.className = team.is_active === true ? 'badge badge--ok' : 'badge badge--off';
    setText(badge, team.is_active === true ? '활성' : '비활성');
    head.appendChild(badge);
    body.appendChild(head);

    var stats = document.createElement('span');
    stats.className = 'card__meta';
    stats.appendChild(statBlock('구성원', team.member_count));
    stats.appendChild(statBlock('Manager', team.manager_count));
    stats.appendChild(statBlock('파일', team.file_count));
    body.appendChild(stats);
    card.appendChild(body);

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
    setText(detailMeta, '만든 날 ' + shortWhen(team.created_at).text + ' · 마지막 변경 ' + shortWhen(team.updated_at).text);
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
      setText(roleCell, roleLabel(member.role));   // 디버깅 묶음 U M7 — 한글 글자 · title 에 원래 값
      if (typeof member.role === 'string') { roleCell.setAttribute('title', member.role); }
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
    getJson(APP_BASE + '/api/org/teams/' + encodeURIComponent(String(state.selected)) + '/members')
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
    return getJson(APP_BASE + '/api/org/teams')
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
      setText(head, roleLabel(role));
      head.setAttribute('title', role);
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
    getJson(APP_BASE + '/api/org/permissions')
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
      return getJson(APP_BASE + '/api/users?page=' + encodeURIComponent(String(pageNo))).then(function (result) {
        if (result === null || !result.ok) {
          return null;
        }
        var data = result.data;
        var users = data && Array.isArray(data.users) ? data.users : [];
        users.forEach(function (item) {
          var option = document.createElement('option');
          option.value = String(item.id);
          var label = item.username + ' · ' + roleLabel(item.role);
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
        setText(activeNote, '「' + team.name + '」 을(를) 비활성화합니다. 비활성 팀에는 새 배정·새 올리기가 막힙니다.'
          + ' 활성 구성원이 0명일 때만 됩니다 — 지금 활성 구성원 ' + team.member_count + '명.');
        setText(activeSubmit, '비활성화');
      } else {
        setText(activeTitle, '팀 활성화');
        setText(activeNote, '「' + team.name + '」 을(를) 다시 활성화합니다. 배정·올리기 선택지에 다시 나타납니다.');
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
  // 디버깅 묶음 U2 M3 — 네 창의 머리에 ✕(DESIGN 2.7 「✕ 와 「닫기」 둘 다」). 「닫기」와 같은 길이다
  [['tc-x', createDialog], ['tr-x', renameDialog], ['ta-x', assignDialog], ['td-x', activeDialog]].forEach(function (pair) {
    var x = document.getElementById(pair[0]);
    if (x && pair[1]) {
      x.addEventListener('click', function () {
        closeDialog(pair[1]);
      });
    }
  });

  /* ── 제출 ──────────────────────────────────────────────────── */

  if (createForm) {
    createForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(createMsg, '');
      sendJson('POST', APP_BASE + '/api/org/teams', {
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
      sendJson('PATCH', APP_BASE + '/api/org/teams/' + encodeURIComponent(String(team.id)), {
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
          setText(msg, '팀 이름을 바꿨습니다.');
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
      sendJson('PATCH', APP_BASE + '/api/org/teams/' + encodeURIComponent(String(team.id)), {
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
      sendJson('PATCH', APP_BASE + '/api/users/' + encodeURIComponent(userId), {
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

/* ─── 2단계 인증 권장 카드 (F-52 ③ — PRD F-52, ARCH 5.26) ────────
 *
 * **필수가 아닌 역할**이 아직 켜지 않았을 때만 홈에 나온다. 서버가 `/api/me` 로 준
 * `mfa_recommended`(boolean) 하나만 보고 정한다 — 화면은 역할 문자열을 비교하지 않는다.
 *
 * 「나중에」는 이 브라우저의 **이 세션**(sessionStorage)에만 남는다. 서버·DB·쿠키에는
 * 아무것도 저장하지 않으므로 탭을 닫고 다시 로그인하면 또 나온다 — 막지 않되 매번
 * 상기시키는 것이 「권장」의 강도다(SECURITY R-85 완화책). 저장이 막힌 브라우저
 * (사생활 보호 모드 등)에서도 카드는 닫혀야 하므로 try/catch 로 감싼다.
 */
function initMfaRecommend(me) {
  var box = document.getElementById('mfa-recommend');
  var later = document.getElementById('mfa-recommend-later');
  if (!box || me.mfa_recommended !== true) {
    return;
  }
  var dismissed = false;
  try {
    dismissed = window.sessionStorage.getItem('tbox-mfa-later') === '1';
  } catch (e) {
    dismissed = false;
  }
  if (dismissed) {
    return;
  }
  box.hidden = false;
  if (later) {
    later.addEventListener('click', function () {
      try {
        window.sessionStorage.setItem('tbox-mfa-later', '1');
      } catch (e) {
        /* 저장하지 못해도 닫는다 */
      }
      box.hidden = true;
    });
  }
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
function initForcedGate(csrfToken, firstStep, mfaEnabled, mfaRequired) {
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
    // P-5a — 걸음이 끝나 상태가 바뀌었으니 나눠 쓰던 답이 아니라 **새로** 묻는다(fresh).
    fetchMe(true)
      .then(function (result) {
        if (result.status === 401) {
          toLogin();
          return null;
        }
        var body = result.me || {};
        show(typeof body.next === 'string' ? body.next : null);
        return null;
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
    }, mfaRequired === true);
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
      fetch(APP_BASE + '/api/me/password', {
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
      addWhenCell(row, item.happened_at);
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
    fetch(APP_BASE + '/api/me/activity?page=' + encodeURIComponent(String(state.page)), {
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

function initSettings(me, csrfToken) {
  var team = document.getElementById('me-team');
  if (team) {
    setText(team, typeof me.team_name === 'string' && me.team_name !== '' ? me.team_name : '—');
  }
  initContrastToggle(me, csrfToken);
}

/* ─── 커뮤니티 7-D3 「고대비 화면」 — 「내 계정」 「화면」 패널 (DESIGN.md 2.16 · 지시서 2장 H-6) ───────────
 *
 * 체크의 처음 상태는 /api/me 의 high_contrast(계정 칸)다. 바꾸면 PATCH /api/me/settings 하나를 보내고,
 * **200 을 받은 뒤에만** `<html>` 에 data-contrast="high" 를 놓거나 뗀다 — 새로 여는 화면은 서버가 같은 계정 칸으로
 * 속성을 그리므로(셸·런처) 여기서 쿠키·저장소에 남기지 않는다. 실패면 체크를 되돌리고 서버 문구를 #msg 에(4장).
 * 성공 알림은 없다(화면이 바뀌는 것이 곧 알림). 해/달 단추(data-theme)와는 독립이다.
 * 보내는 값은 체크 상태 불린 하나뿐이고 대상 계정 칸이 없다 — 서버가 언제나 세션의 본인에게만 쓴다.
 */
function initContrastToggle(me, csrfToken) {
  var box = document.getElementById('contrast-toggle');
  if (!box || typeof csrfToken !== 'string') {
    return;
  }
  var msg = document.getElementById('msg');
  box.checked = me && me.high_contrast === true;
  box.disabled = false;
  box.addEventListener('change', function () {
    var want = box.checked === true;
    box.disabled = true;
    setText(msg, '');
    fetch(APP_BASE + '/api/me/settings', {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ high_contrast: want }),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          if (response.ok && data && typeof data.high_contrast === 'boolean') {
            box.checked = data.high_contrast;
            if (data.high_contrast) {
              document.documentElement.setAttribute('data-contrast', 'high');
            } else {
              document.documentElement.removeAttribute('data-contrast');
            }
            forgetMe();   // 나눠 쓰던 /api/me 답의 high_contrast 가 옛 값이 되었다 — 다음에 묻는 쪽이 새로 받는다
            return null;
          }
          box.checked = !want;
          setText(msg, errorMessage(data));
          return null;
        });
      })
      .catch(function () {
        box.checked = !want;
        setText(msg, GENERIC_ERROR);
      })
      .then(function () {
        box.disabled = false;
      });
  });
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
/* ─── F-66 「프로그램 토큰」 절 — `/settings` 본인 창구 (PRD F-66, ARCH 4.7 「화면」) ────
 *
 * 창구 셋은 `/api/me/api-tokens` 이고 대상은 언제나 **세션의 본인**이다 — 이 화면은
 * 「누구의」 를 고르지 않고, 요청 본문에도 대상 계정이 들어가지 않는다.
 *
 * ★ 역할 비교가 한 줄도 없다. 범위 선택지는 `/api/me` 의 `token_scopes`, 폼을 보일지는
 *   `can_issue_tokens` 만 본다 (DESIGN 0장 1, PRD S-01). 두 값을 개발자도구로 고쳐도
 *   서버가 요청마다 다시 판단한다(역할 밖 범위 → 400, 2단계 꺼짐 → 403).
 * ★ 발급 응답의 `token` 은 그 순간 한 번만 보인다 — 공용 `showIssuedToken` 이 읽기 전용
 *   칸의 `value` 로만 넣는다(문서 텍스트에 넣지 않는다). 비밀번호·코드 칸은 응답을 받은
 *   **즉시** 비운다(성공이든 실패든).
 * ★ 실패 문구는 서버가 준 것을 그대로 `.msg` 에 보인다 — 화면이 문장을 지어내지 않는다
 *   (DESIGN 2.5 · 5장).
 */
function initMyTokens(csrfToken, me) {
  var body = document.getElementById('my-tokens-body');
  var form = document.getElementById('token-issue-form');
  if (!body || !form) {
    return;
  }
  var empty    = document.getElementById('my-tokens-empty');
  var expiring = document.getElementById('tokens-expiring');
  var blocked  = document.getElementById('token-issue-blocked');
  var msg      = document.getElementById('token-issue-msg');
  var scopeSel = document.getElementById('token-scope');
  var nameEl   = document.getElementById('token-name');
  var purpEl   = document.getElementById('token-purpose');
  var pwEl     = document.getElementById('token-password');
  var otpEl    = document.getElementById('token-otp');
  var submit   = document.getElementById('token-issue');
  /* D-10 (DESIGN 2.7, 사용자 지시 2026-09-22) — 발급 폼은 패널에 펼쳐 두지 않는다.
     패널 머리의 「토큰 발급」 단추가 모달을 열고, 폼·제출 단추·`.msg` 는 그 안에 있다.
     폼 id·칸·요청 본문은 그대로다 — 자리만 옮겼다. */
  var openBtn     = document.getElementById('token-issue-open');
  var dialog      = document.getElementById('token-issue-dialog');
  var dialogX     = document.getElementById('token-issue-dialog-x');
  var dialogClose = document.getElementById('token-issue-dialog-close');

  /** 비밀은 브라우저 안에서도 오래 두지 않는다 — 응답을 받는 즉시 비운다 (PRD 7.7) */
  function clearSecrets() {
    if (pwEl)  { pwEl.value = ''; }
    if (otpEl) { otpEl.value = ''; }
  }

  /** 모달 닫기 — 한 번만 보이는 토큰 상자도 함께 지우고(값은 상자와 함께 사라진다),
   *  초점은 연 단추로 돌려준다 (키보드 자리 유지). */
  function closeIssueDialog() {
    if (dialog && dialog.open) {
      dialog.close();
    }
    clearSecrets();
    clearIssuedToken();
    setText(msg, '');
    if (openBtn && openBtn.hidden !== true) {
      openBtn.focus();
    }
  }

  // 범위 선택지 — **서버가 내려 준 목록만**. 화면에 고정 목록을 두지 않는다.
  var scopes = me && Array.isArray(me.token_scopes) ? me.token_scopes : [];
  if (scopeSel) {
    while (scopeSel.firstChild) {
      scopeSel.removeChild(scopeSel.firstChild);
    }
    scopes.forEach(function (scope) {
      var option = document.createElement('option');
      option.value = scope;
      setText(option, tokenScopeLabel(scope));
      scopeSel.appendChild(option);
    });
  }

  // 폼이냐 안내냐 — `can_issue_tokens`(= 2단계 인증이 켜졌나) 하나만 본다.
  var canIssue = me && me.can_issue_tokens === true;
  form.hidden = !canIssue;
  // D-10 — 모달을 여는 단추는 폼과 **같은 조건**으로 켜고 끈다(발급 불가면 단추도 숨고
  // 패널에는 `#token-issue-blocked` 안내만 남는다).
  if (openBtn) {
    openBtn.hidden = !canIssue;
  }
  if (blocked) {
    blocked.hidden = canIssue;
  }

  /** 회수 — 먼저 파일 지우기와 **같은 확인 창**(openConfirm · #confirm-dialog)으로 묻는다(디버깅 묶음 U H2 · 2026-09-26 —
      예전엔 브라우저 기본 확인 창). 「닫기」·✕·Esc 는 요청 0, 「회수하기」(빨강)를 눌러야 DELETE. 회수한 순간부터 그 토큰은 401 이다. */
  function revoke(token) {
    if (!token || typeof token.id !== 'number') {
      return;
    }
    setText(msg, '');
    openConfirm({
      title: '토큰 회수',
      lead: '토큰 「' + String(token.name) + '」 을(를) 회수합니다.',
      note: '그 프로그램은 바로 쓸 수 없게 됩니다. 되돌릴 수 없습니다.',
      okLabel: '회수하기'
    }, function () { sendRevoke(token); });
  }
  function sendRevoke(token) {
    setText(msg, '');
    tokenApiFetch(csrfToken, 'DELETE', APP_BASE + '/api/me/api-tokens/' + String(token.id), null,
      function () { sendRevoke(token); })
      .then(function (result) {
        if (result === null) { return null; }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        setText(msg, '토큰을 회수했습니다.');
        load();
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /** 목록을 다시 읽어 표를 그린다. 만료 14일 안(`expires_soon`)이 있으면 안내 줄을 켠다. */
  function load() {
    tokenApiFetch(csrfToken, 'GET', APP_BASE + '/api/me/api-tokens', null, load)
      .then(function (result) {
        if (result === null) { return null; }
        while (body.firstChild) {
          body.removeChild(body.firstChild);
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        var tokens = result.data && Array.isArray(result.data.tokens) ? result.data.tokens : [];
        var soon = false;
        tokens.forEach(function (token) {
          addTokenRowShared({
            body: body,
            columns: ['name', 'purpose', 'hint', 'scope', 'expires', 'used', 'state', 'actions'],
            onRevoke: revoke
          }, token);
          if (token.expires_soon === true && (token.revoked_at === null || token.revoked_at === undefined)) {
            soon = true;
          }
        });
        if (empty) {
          empty.hidden = tokens.length !== 0;
        }
        if (expiring) {
          expiring.hidden = !soon;
        }
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var payload = {
      name: nameEl ? nameEl.value : '',
      purpose: purpEl ? purpEl.value : '',
      scope: scopeSel ? scopeSel.value : '',
      password: pwEl ? pwEl.value : '',
      otp: otpEl ? otpEl.value : ''
    };
    setText(msg, '');
    if (submit) { submit.disabled = true; }
    // 재시도(retry)를 넘기지 않는다 — 2단계 코드는 1회성이라 같은 요청을 다시 보낼 수 없다.
    tokenApiFetch(csrfToken, 'POST', APP_BASE + '/api/me/api-tokens', payload, null)
      .then(function (result) {
        clearSecrets();                     // 성공이든 실패든 비밀은 여기서 사라진다
        if (submit) { submit.disabled = false; }
        if (result === null) { return null; }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        if (nameEl) { nameEl.value = ''; }
        if (purpEl) { purpEl.value = ''; }
        setText(msg, '토큰을 발급했습니다. 값은 지금만 보입니다.');
        /* D-10 — 폼이 모달 안이므로 한 번만 보이는 토큰 상자도 **모달 안**(폼 바로 아래)에
           나온다. 그래서 성공에도 모달을 곧바로 닫지 않는다 — 값을 복사한 뒤 「닫기」 를
           누르면 상자와 함께 값이 사라진다. 실패도 모달을 연 채 `.msg` 만 바뀐다. */
        showIssuedToken(form.parentNode, msg, result.data ? result.data.token : '');
        load();
        return null;
      })
      .catch(function () {
        clearSecrets();
        if (submit) { submit.disabled = false; }
        setText(msg, GENERIC_ERROR);
      });
  });

  /* ── D-10 「토큰 발급」 단추 · 모달의 ✕ 와 「닫기」 (Esc 는 <dialog> 기본) ── */
  if (openBtn) {
    openBtn.addEventListener('click', function () {
      if (!dialog || typeof dialog.showModal !== 'function' || dialog.open) {
        return;
      }
      setText(msg, '');
      clearIssuedToken();
      dialog.showModal();
      if (nameEl) {
        nameEl.focus();
      }
    });
  }
  [dialogX, dialogClose].forEach(function (btn) {
    if (btn) {
      btn.addEventListener('click', closeIssueDialog);
    }
  });
  if (dialog) {
    /* Esc 로 닫으면 위의 처리기를 거치지 않는다 — 어떤 길로 닫혀도 비밀번호·코드와
       한 번만 보이는 토큰 값은 남기지 않는다 (PRD 7.7, SECURITY R-91). */
    dialog.addEventListener('close', function () {
      clearSecrets();
      clearIssuedToken();
    });
  }

  /* 디버깅 묶음 S-5 — 같은 화면에서 비밀번호를 바꾸면 서버가 토큰을 전부 회수한다. 그때 표를 다시 읽는다
     (값은 서버 목록 그대로 — 화면이 「회수됨」을 스스로 적지 않는다). */
  document.addEventListener('qaredi:tokens-changed', load);

  load();
}

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
    fetch(APP_BASE + '/api/shares/' + encodeURIComponent(String(id)), {
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
      addWhenCell(row, link.expires_at);
      addCell(row, link.download_count + ' / ' + link.max_downloads);
      addCell(row, stateOf(link));
      addWhenCell(row, link.created_at);
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
    fetch(APP_BASE + '/api/me/shares', { credentials: 'same-origin', cache: 'no-store' })
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
/* ─── 17-1 관리자 허브 — `/admin` (PRD F-24 · 10장, ARCH 5.14) ─────────────────
 *
 * 관리 기능의 단일 입구다. 카드(계정·조직·권한 매트릭스·감사 로그·휴지통·위키)는 서버 HTML 의
 * 고정 링크이고, 이 함수가 그리는 것은 **두 목록**(공유 링크 전체·최근 경보)과 재인증 배지다.
 *
 * ★ 값은 전부 textContent 로만 넣는다 (PRD S-03) — 파일명·계정명·경보 근거에는 사람이 정한
 *   문자열이 섞여 있다. ★ 이 화면에 들어왔다는 것 자체가 라우트 정책 role:Admin 과 재인증
 *   관문 [3-2] 를 통과했다는 뜻이다 — 화면은 아무 것도 판단하지 않는다 (PRD S-01).
 */
function initAdmin(csrfToken, me) {
  var msg = document.getElementById('msg');
  var reauthBadge = document.getElementById('admin-reauth');
  var sharesBody = document.getElementById('admin-shares');
  var sharesEmpty = document.getElementById('admin-shares-empty');
  var alertsBox = document.getElementById('admin-alerts');
  var alertsEmpty = document.getElementById('admin-alerts-empty');

  /** 서버가 판정해 내려 준 상태 코드 → 화면 문구 (화면이 시각을 비교해 정하지 않는다) */
  var SHARE_STATUS = {
    'active': '살아 있음',
    'revoked': '회수됨',
    'expired': '만료됨',
    'exhausted': '횟수 소진'
  };

  /* 재인증 창이 지금 열려 있나 — /api/me 의 reauth_fresh **표시용** 값이다.
     닫혀 있으면 다음 관리 요청에서 서버가 403 {next:"reauth"} 로 막고 모달이 뜬다 (F-23). */
  if (reauthBadge) {
    var fresh = me && me.reauth_fresh === true;
    reauthBadge.className = fresh ? 'badge badge--ok' : 'badge badge--off';
    // 디버깅 묶음 U M17 — 사용자 말로(예전: 「재인증 확인됨 (15분 창)」·「재인증 필요」)
    setText(reauthBadge, fresh ? '비밀번호 확인됨 (15분 동안)' : '비밀번호 다시 확인 필요');
  }

  /** GET — 401 → 로그인, 403+next → 강제 절차/재인증 처리. 그 밖은 {ok,status,data} */
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

  function statusBadge(status) {
    var badge = document.createElement('span');
    badge.className = status === 'active' ? 'badge badge--ok' : 'badge badge--off';
    setText(badge, Object.prototype.hasOwnProperty.call(SHARE_STATUS, status) ? SHARE_STATUS[status] : status);
    return badge;
  }

  function renderShares(links) {
    if (!sharesBody) {
      return;
    }
    sharesBody.replaceChildren();
    links.forEach(function (link) {
      var row = document.createElement('tr');

      var name = document.createElement('td');
      setText(name, link.file_name);
      row.appendChild(name);

      var by = document.createElement('td');
      setText(by, link.issued_by);
      row.appendChild(by);

      var st = document.createElement('td');
      st.appendChild(statusBadge(typeof link.status === 'string' ? link.status : ''));
      row.appendChild(st);

      var count = document.createElement('td');
      setText(count, String(link.download_count) + ' / ' + String(link.max_downloads));
      row.appendChild(count);

      var exp = document.createElement('td');
      setWhen(exp, link.expires_at);
      row.appendChild(exp);

      sharesBody.appendChild(row);
    });
    if (sharesEmpty) {
      sharesEmpty.hidden = links.length !== 0;
    }
  }

  function renderAlerts(data) {
    if (!alertsBox) {
      return;
    }
    var alerts = data && Array.isArray(data.alerts) ? data.alerts : [];
    alertsBox.replaceChildren();

    alerts.forEach(function (alert) {
      var item = document.createElement('li');
      item.className = 'list__item';

      var head = document.createElement('div');
      head.className = 'list__head';
      var rule = document.createElement('strong');
      setText(rule, alert.rule);
      head.appendChild(rule);

      /* F-69 ③ — 출처 배지 + 제목. 둘 다 서버 값 그대로이고 글자는 textContent 로만 넣는다
         (제목의 `[Claude] ` 앞머리도 서버가 붙인 것이다 — 화면이 판정해 덧붙이지 않는다). */
      var evidenceForHint = alert.evidence && typeof alert.evidence === 'object' ? alert.evidence : {};
      var originBadge = alertOriginBadgeFor(
        typeof alert.origin === 'string' ? alert.origin : null,
        typeof evidenceForHint.origin_hint === 'string' ? evidenceForHint.origin_hint : null
      );
      if (originBadge !== null) {
        var badgeEl = document.createElement('span');
        badgeEl.className = originBadge.cls;
        badgeEl.setAttribute('title', originBadge.title);
        setText(badgeEl, originBadge.label);
        head.appendChild(badgeEl);
      }
      if (typeof alert.title === 'string' && alert.title !== '') {
        var titleEl = document.createElement('span');
        setText(titleEl, alert.title);
        head.appendChild(titleEl);
      }

      var when = document.createElement('span');
      when.className = 'muted';
      setWhen(when, alert.raised_at);
      head.appendChild(when);
      item.appendChild(head);

      /* 근거는 배치가 남긴 그대로다. 어떤 키가 오는지는 규칙마다 다르므로 화면이 목록을
         고정하지 않고, 온 키를 차례로 `이름: 값` 으로 적는다. 값이 객체·배열이면 JSON
         문자열로 바꿔 **문자로만** 넣는다 (문자열 → HTML 변환 0). */
      var evidence = alert.evidence && typeof alert.evidence === 'object' ? alert.evidence : {};
      var body = document.createElement('div');
      body.className = 'list__body';
      Object.keys(evidence).forEach(function (key) {
        var value = evidence[key];
        var text = value === null || typeof value === 'undefined'
          ? ''
          : (typeof value === 'object' ? JSON.stringify(value) : String(value));
        var pair = document.createElement('span');
        setText(pair, key + ': ' + text);
        body.appendChild(pair);
      });
      item.appendChild(body);

      alertsBox.appendChild(item);
    });

    if (alertsEmpty) {
      alertsEmpty.hidden = alerts.length !== 0;
      // 로그를 **못 읽은** 경우와 "경보 0건" 은 다른 상태다 — 서버가 readable 로 갈라 준다.
      if (alerts.length === 0) {
        setText(
          alertsEmpty,
          data && data.readable === false
            ? '경보 로그를 읽지 못했습니다 (파일이 없거나 읽을 수 없습니다).'
            : '기록된 경보가 없습니다.'
        );
      }
    }
  }

  function load() {
    getJson(APP_BASE + '/api/admin/shares')
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        renderShares(result.data && Array.isArray(result.data.links) ? result.data.links : []);
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });

    getJson(APP_BASE + '/api/admin/alerts')
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        renderAlerts(result.data);
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ─── 26단계 「역할로 보기」 폼 (PRD F-39, ARCH 5.20) ─────────────────────────
   *
   * 고른 값을 `POST /api/me/act` 로 보낼 뿐이다. 무엇을 고를 수 있는지도, 그 값이 유효한지도
   * 서버가 정한다 — 화면은 역할 문자열을 비교하지 않고, 목록에 없는 값을 손으로 보내도
   * 서버가 400 으로 막는다 (PRD S-01). 성공하면 화면을 다시 읽는다: 셸의 띠·내비·can_* 가
   * 통째로 새 역할 기준으로 와야 하기 때문이다.
   *
   * 선택지는 다른 화면과 같은 API 에서 받는다(팀 `/api/teams`) — 화면이 고정 목록을 들고
   * 있으면 팀이 늘 때마다 화면을 고쳐야 하고 언젠가 어긋난다.
   */
  var actForm = document.getElementById('act-form');
  var actRole = document.getElementById('act-role');
  var actTeam = document.getElementById('act-team');
  var actSubmit = document.getElementById('act-submit');
  var actMsg = document.getElementById('act-msg');

  /** $1 셀렉트 · $2 경로 · $3 응답 배열 이름 — 값은 전부 textContent·value 로만 넣는다 */
  function fillSelect(select, path, key) {
    if (!select) {
      return;
    }
    fetch(path, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) {
        if (!response.ok) {
          return null;
        }
        return readJson(response).then(function (data) {
          var rows = data && Array.isArray(data[key]) ? data[key] : [];
          rows.forEach(function (row) {
            var option = document.createElement('option');
            option.value = String(row.id);
            setText(option, row.name);
            select.appendChild(option);
          });
          return null;
        });
      })
      .catch(function () {
        // 목록을 못 받아도 나머지 화면은 그대로 동작한다 (선택 칸이다).
      });
  }

  if (actForm) {
    fillSelect(actTeam, APP_BASE + '/api/teams', 'teams');

    actForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(actMsg, '');
      var payload = { role: actRole ? actRole.value : '' };
      if (actTeam && actTeam.value !== '') {
        payload.team_id = actTeam.value;
      }
      if (actSubmit) {
        actSubmit.disabled = true;
      }
      fetch(APP_BASE + '/api/me/act', {
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
            if (toGate(response, data)) {
              return null;
            }
            if (!response.ok) {
              // 거부 문구는 **서버가 준 것만** 쓴다 (사유는 감사 로그에만 갈라져 남는다).
              setText(actMsg, errorMessage(data));
              if (actSubmit) {
                actSubmit.disabled = false;
              }
              return null;
            }
            window.location.reload();
            return null;
          });
        })
        .catch(function () {
          setText(actMsg, GENERIC_ERROR);
          if (actSubmit) {
            actSubmit.disabled = false;
          }
        });
    });
  }

  // 재인증 모달이 닫힌 뒤 다시 읽을 로더를 등록한다 (16-1 과 같은 방식).
  setReauthRetry(load);
  load();
}

/* ─── 17-2 권한 매트릭스 편집 — `/admin/permissions` (PRD F-25, ARCH 5.15) ──────
 *
 * 4×4 표의 칸마다 「읽기」·「올리기」 체크상자를 놓고, 바꾼 것을 미리보기로 보여 준 뒤
 * 사유·2단계 인증 코드와 함께 `PATCH /api/admin/permissions` 로 보낸다.
 *
 * ★ 이 화면은 **아무 것도 막지 않는다.** 잠긴 칸의 disabled 를 개발자도구로 풀어도, 코드나
 *   사유를 비워 보내도 서버가 400 으로 막고 `permission.change deny …` 로 남긴다 (T-39).
 *   잠긴 칸의 이유 문구도 서버가 내려 준 것(`locked_reason`)을 그대로 쓴다 — 화면이 규칙을
 *   지어내지 않는다 (PRD S-01).
 * ★ 실패 문구는 서버가 준 문구만 쓴다. 값은 전부 textContent 로만 넣는다 (PRD S-03).
 */
function initAdminPermissions(csrfToken) {
  var msg = document.getElementById('msg');
  var tableBody = document.getElementById('perm-table');
  var rulesBox = document.getElementById('perm-rules');
  var previewBox = document.getElementById('perm-preview');
  var previewEmpty = document.getElementById('perm-preview-empty');
  var form = document.getElementById('perm-form');
  var reasonInput = document.getElementById('perm-reason');
  var otpInput = document.getElementById('perm-otp');
  var submit = document.getElementById('perm-submit');
  var resetButton = document.getElementById('perm-reset');
  var formMsg = document.getElementById('perm-msg');
  /* D-10 (DESIGN 2.7, 사용자 지시 2026-09-22) — 사유·코드 폼은 화면에 펼쳐 두지 않는다.
     화면에는 미리보기와 단추 줄만 남고, 「적용」 이 이 모달을 연다. 폼 id·칸·요청 본문
     (`PATCH /api/admin/permissions`)은 그대로다 — 허용 사이트 화면과 같은 꼴이다. */
  var applyOpen = document.getElementById('perm-apply-open');
  var dialog = document.getElementById('perm-dialog');
  var dialogX = document.getElementById('perm-dialog-x');
  var dialogClose = document.getElementById('perm-dialog-close');
  var dialogCount = document.getElementById('perm-dialog-count');

  function closeDialog() {
    if (dialog && dialog.open) {
      dialog.close();
    }
  }

  /** 모달 닫기 + 초점을 연 단추로 (닫혀 있으면 아무 일도 하지 않는다) */
  function closePermDialog() {
    closeDialog();
    if (applyOpen && applyOpen.disabled !== true) {
      applyOpen.focus();
    }
  }

  /** 서버에서 받은 16행(기준값)과, 칸마다 만든 체크상자 두 개 */
  var state = { rows: [], boxes: Object.create(null) };

  function cellKey(role, level) {
    return role + '|' + level;
  }

  /* ── 통신 (initOrg 와 같은 모양) ───────────────────────────── */

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

  /* ── 표 그리기 ─────────────────────────────────────────────── */

  /** 체크상자 하나 (잠긴 칸이면 disabled + 서버가 준 이유를 툴팁으로) */
  function makeToggle(row, flag, label) {
    var wrap = document.createElement('label');
    wrap.className = 'perm-toggle';

    var box = document.createElement('input');
    box.type = 'checkbox';
    box.checked = row[flag] === true;
    if (row.locked === true) {
      box.disabled = true;
      wrap.className = 'perm-toggle is-locked';
      if (typeof row.locked_reason === 'string' && row.locked_reason !== '') {
        wrap.setAttribute('title', row.locked_reason);
      }
    }
    box.addEventListener('change', renderPreview);
    wrap.appendChild(box);

    var text = document.createElement('span');
    setText(text, label);
    wrap.appendChild(text);

    return { wrap: wrap, box: box };
  }

  function renderTable(rows) {
    if (!tableBody) {
      return;
    }
    state.rows = rows;
    state.boxes = Object.create(null);
    tableBody.replaceChildren();

    // 역할의 차례는 **서버가 보낸 차례** 그대로다 (화면이 역할 순서를 정하지 않는다).
    var byRole = Object.create(null);
    var roles = [];
    rows.forEach(function (row) {
      if (!row || typeof row.role !== 'string' || typeof row.access_level !== 'string') {
        return;
      }
      if (!Object.prototype.hasOwnProperty.call(byRole, row.role)) {
        byRole[row.role] = Object.create(null);
        roles.push(row.role);
      }
      byRole[row.role][row.access_level] = row;
    });

    roles.forEach(function (role) {
      var tr = document.createElement('tr');
      var head = document.createElement('td');
      var name = document.createElement('strong');
      setText(name, roleLabel(role));
      name.setAttribute('title', role);
      head.appendChild(name);
      tr.appendChild(head);

      MATRIX_LEVELS.forEach(function (level) {
        var td = document.createElement('td');
        td.className = 'perm-cell';
        var row = Object.prototype.hasOwnProperty.call(byRole[role], level) ? byRole[role][level] : null;
        if (row === null) {
          setText(td, '—');
          tr.appendChild(td);
          return;
        }
        if (row.locked === true) {
          td.className = 'perm-cell is-locked';
          var lock = makeIcon('lock');
          td.appendChild(lock);
        }
        var read = makeToggle(row, 'can_read', '읽기');
        var upload = makeToggle(row, 'can_upload', '올리기');
        td.appendChild(read.wrap);
        td.appendChild(upload.wrap);
        state.boxes[cellKey(role, level)] = { read: read.box, upload: upload.box };
        tr.appendChild(td);
      });

      tableBody.appendChild(tr);
    });

    renderPreview();
  }

  function renderRules(rules) {
    if (!rulesBox) {
      return;
    }
    rulesBox.replaceChildren();
    rules.forEach(function (text) {
      var item = document.createElement('li');
      setText(item, text);
      rulesBox.appendChild(item);
    });
  }

  /* ── 변경 미리보기 ─────────────────────────────────────────── */

  function yesNo(value) {
    return value === true ? '예' : '아니오';
  }

  /** 지금 표에서 바뀐 칸 목록 (서버에 보낼 모양 그대로 + 미리보기 문장) */
  function changedCells() {
    var out = [];
    state.rows.forEach(function (row) {
      var boxes = state.boxes[cellKey(row.role, row.access_level)];
      if (!boxes) {
        return;
      }
      var read = boxes.read.checked === true;
      var upload = boxes.upload.checked === true;
      if (read === (row.can_read === true) && upload === (row.can_upload === true)) {
        return;
      }
      var parts = [];
      if (read !== (row.can_read === true)) {
        parts.push('읽기 ' + yesNo(row.can_read === true) + ' → ' + yesNo(read));
      }
      if (upload !== (row.can_upload === true)) {
        parts.push('올리기 ' + yesNo(row.can_upload === true) + ' → ' + yesNo(upload));
      }
      out.push({
        change: {
          role: row.role,
          access_level: row.access_level,
          can_read: read,
          can_upload: upload
        },
        text: roleLabel(row.role) + ' · ' + row.access_level + ' : ' + parts.join(', ')
      });
    });
    return out;
  }

  function renderPreview() {
    var cells = changedCells();
    if (previewBox) {
      previewBox.replaceChildren();
      cells.forEach(function (cell) {
        var item = document.createElement('li');
        item.className = 'list__item';
        setText(item, cell.text);
        previewBox.appendChild(item);
      });
    }
    if (previewEmpty) {
      previewEmpty.hidden = cells.length !== 0;
    }
    if (submit) {
      // 바꾼 칸이 없으면 보낼 것이 없다(서버도 규칙 (e) 로 거부한다).
      submit.disabled = cells.length === 0;
    }
    // D-10 — 모달을 여는 「적용」 에도 **같은 조건**을 건다.
    if (applyOpen) {
      applyOpen.disabled = cells.length === 0;
    }
  }

  /* ── 읽기 ──────────────────────────────────────────────────── */

  function load() {
    return getJson(APP_BASE + '/api/admin/permissions')
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        var data = result.data || {};
        renderTable(Array.isArray(data.matrix) ? data.matrix : []);
        renderRules(Array.isArray(data.rules) ? data.rules : []);
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ── 적용 ──────────────────────────────────────────────────── */

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(formMsg, '');

      var cells = changedCells();
      if (cells.length === 0) {
        return;
      }
      var changes = cells.map(function (cell) {
        return cell.change;
      });

      if (submit) {
        submit.disabled = true;
      }
      sendJson('PATCH', APP_BASE + '/api/admin/permissions', {
        changes: changes,
        reason_text: reasonInput ? reasonInput.value : '',
        otp: otpInput ? otpInput.value : ''
      })
        .then(function (result) {
          if (result === null) {
            if (submit) {
              submit.disabled = false;
            }
            /* 재인증 관문(403 next:"reauth")에 걸리면 toGate 가 **재인증 모달**을 띄운다.
               모달 위에 모달을 겹치지 않으려고 이 모달을 닫는다 — 재인증을 통과하면
               등록해 둔 로더(load)가 표를 다시 읽으므로, 이 모달을 다시 열지는 않는다
               (D-10 2차 ③ 의 「어느 쪽인지 보고」 에 대한 선택). */
            closeDialog();
            return null;
          }
          if (!result.ok) {
            // 실패 문구는 **서버가 준 것만** 쓴다 (사유는 감사 로그에만 갈라져 남는다).
            // 모달은 열어 둔다 — 사유·코드를 고쳐 다시 보낼 자리가 여기다.
            setText(formMsg, errorMessage(result.data));
            if (submit) {
              submit.disabled = false;
            }
            return null;
          }
          var data = result.data || {};
          var changedCount = Array.isArray(data.changed) ? data.changed.length : 0;
          /* 성공 뒤에는 GET 으로 **다시 읽는다**. PATCH 응답의 16행에는 잠금 표시(locked·이유)가
             없어서 그대로 그리면 잠긴 칸이 풀린 것처럼 보인다 — 서버는 그래도 막지만, 화면이
             거짓말을 하면 안 된다 (PRD 10장 "버튼 표시는 권한의 근거가 아님"). */
          load();
          if (reasonInput) {
            reasonInput.value = '';
          }
          if (otpInput) {
            // 코드는 1회성이다 — 남겨 두면 다음 요청에서 code_reused 로 거부된다.
            otpInput.value = '';
          }
          /* D-10 — 성공에는 모달을 닫는다. 그래서 끝맺는 문구는 모달 안(`#perm-msg`)이 아니라
             화면의 알림 줄(`#msg`)에 쓴다 — 문구 자체는 예전 그대로다. */
          setText(formMsg, '');
          closePermDialog();
          setText(msg, String(changedCount) + '개 칸을 바꿨습니다. 효과는 다음 요청부터이며 감사 로그에 남았습니다.');
          return null;
        })
        .catch(function () {
          setText(formMsg, GENERIC_ERROR);
          if (submit) {
            submit.disabled = false;
          }
        });
    });
  }

  /* ── D-10 「적용」 단추 → 모달 · ✕ 와 「닫기」 (Esc 는 <dialog> 기본) ── */
  if (applyOpen) {
    applyOpen.addEventListener('click', function () {
      var cells = changedCells();
      if (cells.length === 0) {
        return;
      }
      if (!dialog || typeof dialog.showModal !== 'function' || dialog.open) {
        return;
      }
      setText(formMsg, '');
      if (dialogCount) {
        setText(dialogCount, '바뀐 칸 ' + String(cells.length) + '개를 적용합니다');
      }
      dialog.showModal();
      if (reasonInput) {
        reasonInput.focus();
      }
    });
  }
  [dialogX, dialogClose].forEach(function (btn) {
    if (btn) {
      btn.addEventListener('click', closePermDialog);
    }
  });
  if (dialog) {
    // Esc 로 닫아도 1회성 코드는 남기지 않는다 (사유는 다시 쓰지 않아도 되게 남긴다)
    dialog.addEventListener('close', function () {
      if (otpInput) {
        otpInput.value = '';
      }
    });
  }

  if (resetButton) {
    resetButton.addEventListener('click', function () {
      setText(formMsg, '');
      setText(msg, '');
      closeDialog();
      load();
    });
  }

  setReauthRetry(load);
  load();
}

/* ─── F-43 URL 가져오기 허용 사이트 — `/admin/fetch-hosts` (PRD F-43, ARCH 5.22) ────
 *
 * 표의 「허용」 체크상자를 켜고 끄거나 아래 줄에서 사이트를 추가한 뒤, 무엇이 바뀌는지를
 * 미리보기로 보여 주고 **사유·인증 앱 코드 모달**을 거쳐 `POST /api/fetch-hosts` 로 보낸다.
 * 권한 매트릭스 편집(initAdminPermissions)과 같은 모양이며, 다른 점은 셋이다:
 *   · 행이 고정 16칸이 아니라 **늘어난다**(추가 줄이 임시 행을 표에 얹는다)
 *   · **지우는 단추가 없다** — 「허용」을 끄는 것이 그만 쓰는 방법이다
 *   · 사유·코드를 폼이 아니라 **모달**에서 받는다(저장 직전에 한 번 더 읽게)
 *
 * ★ 이 화면은 아무 것도 막지 않는다. 체크상자를 개발자도구로 고쳐도, 와일드카드를 적어도,
 *   코드를 비워도 서버가 400 으로 막고 `fetch_host.change deny …` 로 남긴다 (T-56).
 * ★ 값은 전부 textContent 로만 넣는다. 실패 문구는 서버가 준 것만 쓴다 (PRD S-03).
 */
function initAdminFetchHosts(csrfToken) {
  var msg = document.getElementById('msg');
  var tableBody = document.getElementById('fetch-hosts-table');
  var emptyNote = document.getElementById('fetch-hosts-empty');
  var rulesBox = document.getElementById('fetch-hosts-rules');
  var addForm = document.getElementById('fetch-hosts-add');
  var addInput = document.getElementById('fetch-hosts-new');
  var addNote = document.getElementById('fetch-hosts-new-note');
  var addMsg = document.getElementById('fetch-hosts-add-msg');
  /* D-10 (DESIGN 2.7, 사용자 지시 2026-09-22) — 추가 폼은 화면 머리의 「사이트 추가」 단추가
     여는 모달 안에 있다. 폼 id·칸·요청은 그대로고 자리만 옮겼다(요청은 여전히 「저장」 에서만
     나간다 — 이 모달은 표에 임시 행을 얹을 뿐이다). */
  var addOpenBtn = document.getElementById('fetch-hosts-add-open');
  var addDialog = document.getElementById('fetch-hosts-add-dialog');
  var addDialogX = document.getElementById('fetch-hosts-add-dialog-x');
  var addDialogClose = document.getElementById('fetch-hosts-add-dialog-close');
  var previewBox = document.getElementById('fetch-hosts-preview');
  var previewEmpty = document.getElementById('fetch-hosts-preview-empty');
  var saveButton = document.getElementById('fetch-hosts-save');
  var resetButton = document.getElementById('fetch-hosts-reset');
  var pageMsg = document.getElementById('fetch-hosts-msg');
  var dialog = document.getElementById('fetch-hosts-dialog');
  var dialogCount = document.getElementById('fetch-hosts-dialog-count');
  var dialogList = document.getElementById('fetch-hosts-dialog-list');
  var dialogClose = document.getElementById('fetch-hosts-dialog-close');
  var dialogX = document.getElementById('fetch-hosts-dialog-x');
  var dialogMsg = document.getElementById('fetch-hosts-dialog-msg');
  var form = document.getElementById('fetch-hosts-form');
  var reasonInput = document.getElementById('fetch-hosts-reason');
  var otpInput = document.getElementById('fetch-hosts-otp');
  var submit = document.getElementById('fetch-hosts-submit');

  /* 서버에서 받은 행(기준값) · 아직 저장하지 않은 새 줄 · 행마다 만든 체크상자 */
  var state = { rows: [], added: [], boxes: Object.create(null), notes: Object.create(null) };

  /* 화면에서도 형식을 한 번 본다 — **막는 것이 아니라 알려 주는 것**이다.
     같은 규칙을 서버(fetch_hosts_update.php)와 DB CHECK 가 다시 본다 (PRD S-01). */
  var HOST_RE = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/;

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

  function openDialog(el) {
    if (el && typeof el.showModal === 'function' && !el.open) {
      el.showModal();
    }
  }
  function closeDialog(el) {
    if (el && el.open) {
      el.close();
    }
  }

  /** 「사이트 추가」 모달 닫기 — 초점을 연 단추로 돌려준다 (키보드 자리 유지). */
  function closeAddDialog() {
    closeDialog(addDialog);
    if (addOpenBtn) {
      addOpenBtn.focus();
    }
  }

  /** 「허용」 체크상자 하나 */
  function makeToggle(host, checked) {
    var wrap = document.createElement('label');
    wrap.className = 'perm-toggle';
    var box = document.createElement('input');
    box.type = 'checkbox';
    box.checked = checked === true;
    box.addEventListener('change', renderPreview);
    wrap.appendChild(box);
    var text = document.createElement('span');
    setText(text, '허용');
    wrap.appendChild(text);
    state.boxes[host] = box;
    return wrap;
  }

  function addCell(row, value) {
    var td = document.createElement('td');
    setText(td, value);
    row.appendChild(td);
    return td;
  }

  function renderTable() {
    if (!tableBody) {
      return;
    }
    tableBody.replaceChildren();
    state.boxes = Object.create(null);

    state.rows.forEach(function (row) {
      if (!row || typeof row.host !== 'string') {
        return;
      }
      var tr = document.createElement('tr');
      var head = document.createElement('td');
      var name = document.createElement('strong');
      setText(name, row.host);
      head.appendChild(name);
      tr.appendChild(head);
      addCell(tr, typeof row.note === 'string' ? row.note : '');
      var toggleCell = document.createElement('td');
      toggleCell.className = 'perm-cell';
      toggleCell.appendChild(makeToggle(row.host, row.is_active === true));
      tr.appendChild(toggleCell);
      addCell(tr, typeof row.created_by === 'string' ? row.created_by : '');
      addWhenCell(tr, row.updated_at);
      tableBody.appendChild(tr);
    });

    // 아직 저장하지 않은 새 줄 — 표 아래쪽에 붙고, 저장하면 서버 응답의 행으로 바뀐다.
    state.added.forEach(function (row) {
      var tr = document.createElement('tr');
      var head = document.createElement('td');
      var name = document.createElement('strong');
      setText(name, row.host);
      head.appendChild(name);
      var mark = document.createElement('span');
      mark.className = 'badge';
      setText(mark, '저장 전');
      head.appendChild(mark);
      tr.appendChild(head);
      addCell(tr, row.note);
      var toggleCell = document.createElement('td');
      toggleCell.className = 'perm-cell';
      toggleCell.appendChild(makeToggle(row.host, true));
      tr.appendChild(toggleCell);
      addCell(tr, '');
      addCell(tr, '');
      tableBody.appendChild(tr);
    });

    if (emptyNote) {
      emptyNote.hidden = state.rows.length !== 0 || state.added.length !== 0;
    }
    renderPreview();
  }

  function renderRules(rules) {
    if (!rulesBox) {
      return;
    }
    rulesBox.replaceChildren();
    rules.forEach(function (text) {
      var item = document.createElement('li');
      setText(item, text);
      rulesBox.appendChild(item);
    });
  }

  /** 지금 표에서 바뀐 것 (서버에 보낼 모양 + 미리보기 문장) */
  function changedHosts() {
    var out = [];
    state.rows.forEach(function (row) {
      var box = state.boxes[row.host];
      if (!box) {
        return;
      }
      var active = box.checked === true;
      if (active === (row.is_active === true)) {
        return;
      }
      out.push({
        change: { host: row.host, is_active: active },
        text: row.host + ' : 허용 ' + (row.is_active === true ? '예' : '아니오') + ' → ' + (active ? '예' : '아니오')
      });
    });
    state.added.forEach(function (row) {
      var box = state.boxes[row.host];
      var active = box ? box.checked === true : true;
      var entry = { host: row.host, is_active: active };
      if (row.note !== '') {
        entry.note = row.note;
      }
      out.push({
        change: entry,
        text: row.host + ' : 새로 추가 (허용 ' + (active ? '예' : '아니오') + ')'
      });
    });
    return out;
  }

  function renderPreview() {
    var list = changedHosts();
    if (previewBox) {
      previewBox.replaceChildren();
      list.forEach(function (item) {
        var li = document.createElement('li');
        li.className = 'list__item';
        setText(li, item.text);
        previewBox.appendChild(li);
      });
    }
    if (previewEmpty) {
      previewEmpty.hidden = list.length !== 0;
    }
    if (saveButton) {
      // 바꾼 것이 없으면 보낼 것이 없다(서버도 invariant 로 거부한다).
      saveButton.disabled = list.length === 0;
    }
  }

  function load() {
    return getJson(APP_BASE + '/api/fetch-hosts')
      .then(function (result) {
        if (result === null) {
          return null;
        }
        if (!result.ok) {
          setText(msg, errorMessage(result.data));
          return null;
        }
        var data = result.data || {};
        state.rows = Array.isArray(data.hosts) ? data.hosts : [];
        state.added = [];
        renderTable();
        renderRules(Array.isArray(data.rules) ? data.rules : []);
        return null;
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ── D-10 「사이트 추가」 단추 · 모달의 ✕ 와 「닫기」 (Esc 는 <dialog> 기본) ── */
  if (addOpenBtn) {
    addOpenBtn.addEventListener('click', function () {
      setText(addMsg, '');
      openDialog(addDialog);
      if (addInput) {
        addInput.focus();
      }
    });
  }
  [addDialogX, addDialogClose].forEach(function (btn) {
    if (btn) {
      btn.addEventListener('click', closeAddDialog);
    }
  });

  /* ── 「목록에 추가」 — 표에 임시 행을 얹기만 한다(요청 없음) ── */
  if (addForm) {
    addForm.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(addMsg, '');
      var host = addInput ? addInput.value.trim().toLowerCase() : '';
      var note = addNote ? addNote.value.trim() : '';
      if (host === '') {
        // D-10 2차 C — 전에는 조용히 return 했다(아무 일도 일어나지 않아 보였다).
        setText(addMsg, '호스트를 넣으세요.');
        return;
      }
      if (!HOST_RE.test(host)) {
        setText(addMsg, '호스트 이름만 넣습니다 — https:// 나 경로는 빼고, 점이 들어간 정확한 이름으로 적으세요 (와일드카드 * 는 쓸 수 없습니다).');
        return;
      }
      var exists = state.rows.some(function (row) { return row.host === host; })
        || state.added.some(function (row) { return row.host === host; });
      if (exists) {
        setText(addMsg, '이미 목록에 있는 사이트입니다. 표에서 「허용」을 켜세요.');
        return;
      }
      state.added.push({ host: host, note: note });
      if (addInput) {
        addInput.value = '';
      }
      if (addNote) {
        addNote.value = '';
      }
      renderTable();
      // 미리보기에 한 줄이 들어갔으니 모달은 할 일을 마쳤다 (D-10).
      // 실패했을 때는 위에서 이미 return 했으므로 모달이 열린 채 .msg 만 바뀐다.
      closeAddDialog();
    });
  }

  /* ── 「저장」 → 사유·코드 모달 ── */
  if (saveButton) {
    saveButton.addEventListener('click', function () {
      var list = changedHosts();
      if (list.length === 0) {
        return;
      }
      setText(dialogMsg, '');
      if (dialogCount) {
        setText(dialogCount, String(list.length) + '건을 바꿉니다');
      }
      if (dialogList) {
        dialogList.replaceChildren();
        list.forEach(function (item) {
          var li = document.createElement('li');
          li.className = 'list__item';
          setText(li, item.text);
          dialogList.appendChild(li);
        });
      }
      openDialog(dialog);
    });
  }

  [dialogClose, dialogX].forEach(function (btn) {
    if (btn) {
      btn.addEventListener('click', function () {
        closeDialog(dialog);
      });
    }
  });

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      setText(dialogMsg, '');
      var list = changedHosts();
      if (list.length === 0) {
        closeDialog(dialog);
        return;
      }
      var changes = list.map(function (item) {
        return item.change;
      });
      if (submit) {
        submit.disabled = true;
      }
      sendJson('POST', APP_BASE + '/api/fetch-hosts', {
        changes: changes,
        reason_text: reasonInput ? reasonInput.value : '',
        otp: otpInput ? otpInput.value : ''
      })
        .then(function (result) {
          if (submit) {
            submit.disabled = false;
          }
          if (result === null) {
            return null;
          }
          if (!result.ok) {
            // 실패 문구는 **서버가 준 것만** 쓴다 (사유는 감사 로그에만 갈라져 남는다).
            setText(dialogMsg, errorMessage(result.data));
            return null;
          }
          var data = result.data || {};
          var n = Array.isArray(data.changed) ? data.changed.length : 0;
          state.rows = Array.isArray(data.hosts) ? data.hosts : state.rows;
          state.added = [];
          renderTable();
          if (reasonInput) {
            reasonInput.value = '';
          }
          if (otpInput) {
            // 코드는 1회성이다 — 남겨 두면 다음 요청에서 거부된다.
            otpInput.value = '';
          }
          closeDialog(dialog);
          setText(pageMsg, String(n) + '건을 바꿨습니다. 효과는 다음 요청부터입니다.');
          return null;
        })
        .catch(function () {
          setText(dialogMsg, GENERIC_ERROR);
          if (submit) {
            submit.disabled = false;
          }
        });
    });
  }

  if (resetButton) {
    resetButton.addEventListener('click', function () {
      setText(pageMsg, '');
      setText(addMsg, '');
      load();
    });
  }

  setReauthRetry(load);
  load();
}

/* ─── F-43 「사용 방법」 도움말의 허용 사이트 목록 (PRD F-43, ARCH 5.22) ──────────
 *
 * 도움말 글 자체는 서버의 고정 배열(`src/help/screens.php`)이지만, **지금 허용된 사이트**는
 * 그때그때 달라지는 값이라 글에 적어 둘 수 없다. 그래서 `/api/me` 의 `fetch_hosts_public`
 * (자격이 있는 사람에게만 내려온다)을 받아 도움말 모달 안에 목록 하나를 만들어 넣는다.
 *
 * 값은 **서버가 준 문자열**이고 전부 textContent 로 들어간다 (문자열 → HTML 변환 0, PRD S-03).
 * 키가 아예 없는 응답(Employee·Guest)에서는 아무것도 그리지 않는다.
 */
function renderFetchHostsHelp(me) {
  var dialog = document.getElementById('help-dialog');
  if (!dialog || !Array.isArray(me.fetch_hosts_public)) {
    return;
  }
  var body = dialog.querySelector('.modal__body');
  if (!body || body.querySelector('[data-fetch-hosts]')) {
    return;
  }

  var title = document.createElement('h3');
  title.className = 'help-dialog__title';
  setText(title, '지금 가져올 수 있는 사이트');
  body.appendChild(title);

  var list = document.createElement('ul');
  list.className = 'help-dialog__list';
  list.setAttribute('data-fetch-hosts', '');
  if (me.fetch_hosts_public.length === 0) {
    var none = document.createElement('li');
    setText(none, '아직 없습니다. 관리자가 사이트를 등록해야 「URL 로 가져오기」를 쓸 수 있습니다.');
    list.appendChild(none);
  } else {
    me.fetch_hosts_public.forEach(function (host) {
      var item = document.createElement('li');
      setText(item, String(host));
      list.appendChild(item);
    });
  }
  body.appendChild(list);
}

/* ─── 로그아웃 때 그 사람의 「쓰던 글 임시저장」을 지운다 (Q:F-4 B1-2 설계 4, 결정 W-3) ──────────
 *
 * 커뮤니티(`community/drafts.js`)는 저장 안 된 글을 이 브라우저의 localStorage 에 **암호화해서** 남긴다 —
 * 열쇠 이름 `qaredi-draft:v1:<아이디>:…`. 로그아웃 단추(와 그 단추를 누르는 「강제 로그아웃」 링크)를 누르면
 * **그 사람 몫만** 지운다. 모듈(drafts.js)을 부르지 않는다 — t-box 화면에는 그 모듈이 없고, 여기서 하는 일은
 * 접두가 맞는 이름을 지우는 것뿐이다(값을 읽지 않는다 · 다른 사람 접두는 건드리지 않는다).
 * 아이디는 `/api/me` 의 username(역할로 보기 중에도 실제 계정)이고 글자 집합에 `:` 가 없어 접두가 겹치지 않는다.
 * 저장소를 쓸 수 없는 브라우저(사생활 모드 등)는 지울 것도 없다 — 조용히 넘어간다.
 */
var DRAFT_PREFIX = 'qaredi-draft:v1:';

function clearMyDrafts(owner) {
  if (typeof owner !== 'string' || !/^[a-z0-9_.-]{3,64}$/.test(owner)) {
    return;
  }
  var mine = DRAFT_PREFIX + owner + ':';
  try {
    var store = window.localStorage;
    var doomed = [];
    for (var i = 0; i < store.length; i++) {
      var name = store.key(i);
      if (typeof name === 'string' && name.indexOf(mine) === 0) {
        doomed.push(name);
      }
    }
    doomed.forEach(function (n) {
      store.removeItem(n);
    });
  } catch (e) {
    // 막힌 저장소 — 남은 것이 없다
  }
}

function initShell(me, csrfToken) {
  var msg = document.getElementById('msg');
  var sidebar = document.getElementById('sidebar');
  var navToggle = document.getElementById('nav-toggle');
  var logout = document.getElementById('logout');
  // 로그아웃 · 역할로 보기 끄기가 붙일 토큰 — 일괄 「이어서 하기」가 새 세션의 값으로 바꿀 수 있게 셸 변수에 둔다(setSessionCsrf)
  shellCsrf = typeof csrfToken === 'string' ? csrfToken : null;

  /* 최초 로그인 강제 절차가 남았나 (있으면 다른 화면으로 갈 길을 접어 둔다).
   *
   * ★ 26단계 (PRD F-40, 사용자 지적 2026-09-15 「관리자 페이지에 들어가면 사이드바에서
   *   관리자 페이지가 사라진다」). 전에는 `me.next !== ''` 였다. `next` 에는 강제 절차의 두 값
   *   (`password_change`·`mfa_setup`)만이 아니라 **재인증 안내**(`reauth`)도 들어온다 —
   *   관리 화면은 전부 재인증 대상이라, 창이 닫힌 순간 사이드바의 모든 항목이 함께 닫혔다.
   *   재인증은 "지금 할 일이 하나 더 있다" 일 뿐 **다른 화면을 막는 절차가 아니다**(관문 [3-2] 는
   *   인가 뒤에 있고 권한을 만들지도 없애지도 않는다). 그래서 닫는 것은 그 두 값뿐이다. */
  var inGate = me.next === 'password_change' || me.next === 'mfa_setup';

  /* 17-1 (PRD F-24, ARCH 5.14) — 일반 셸의 관리 항목은 「관리자 페이지」 **하나**다.
     전에는 계정·조직·감사·휴지통·위키 다섯 항목을 can_* 다섯 개로 각각 열었다. 이제 그
     화면들은 허브(/admin)의 카드로만 가고, 셸은 can_admin 하나만 본다.
     다른 can_* 와 같은 **표시용** 값이다 — 항목을 개발자도구로 되살려 /admin 이나
     /api/admin/* 를 직접 불러도 라우트 정책 role:Admin 과 재인증 관문이 막고
     authz.route|deny 로 남는다 (PRD S-01, T-38). */
  var canAdmin = me.can_admin === true;

  /* D-9 (DESIGN.md 2.7, PRD F-23, ARCH 4.0) — 관리 화면 링크에 재인증 모달을 건다.
     initShell 은 셸이 있는 **모든** 화면이 딱 한 번 부르고 이미 `/api/me` 의 me 를 갖고 있어,
     화면 분기마다 걸지 않고 여기서 한 번 건다 — 새 요청은 0 이다. 무엇을 묻는지는 서버가 준
     reauth_fresh 가 정하고, 막는 것은 여전히 서버 관문 [3-2] 다 (PRD S-01). */
  armReauthLinks(me);

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
  // 앱 메뉴(브랜드 단추)의 계정 줄 — 사이드바 「설정」 묶음과 같은 규칙이다 (2026-09-23).
  showNav('menu-settings', true);
  showNav('menu-activity', true);
  /* 26단계 (PRD F-40) — 「관리」 묶음의 링크 **7개를 같은 값 하나로** 함께 연다.
     화면이 역할별로 더 감추기 시작하면 그것이 곧 화면에 심은 권한 판단이 된다. 항목을 개발자도구로 되살려도
     각 경로의 라우트 정책(user.manage·org.manage·audit.read·role:Admin)과 재인증 관문이
     서버에서 다시 막는다 (PRD S-01, T-38·T-53). */
  ['nav-admin', 'nav-users', 'nav-org', 'nav-admin-permissions', 'nav-admin-fetch-hosts',
   'nav-audit', 'nav-trash', 'nav-wiki', 'menu-admin'].forEach(function (id) {
    showNav(id, canAdmin);
  });
  /* Q:F-4 U-2b (PRD 5.4 각주 ¹) — 「파일」 묶음의 「내 휴지통」. 관리 묶음의 「휴지통」(전체)이 열리는
     사람(can_view_trash)에게는 같은 주소가 전체 휴지통이라 열지 않고, 올릴 수 있는 등급이 하나도 없는
     사람(upload_levels 비어 있음 — 되살릴 내 파일이 생길 수 없다)에게도 열지 않는다.
     둘 다 서버가 준 **표시용** 값이다 — 항목을 되살려도 보이는 행은 서버 SQL WHERE 가 정한다 (PRD S-01·S-02). */
  showNav('nav-my-trash', me.can_view_trash !== true
    && Array.isArray(me.upload_levels) && me.upload_levels.length > 0);

  // 「관리」 묶음은 그 안의 항목이 열릴 때만 제목째 보인다.
  var adminGroup = document.getElementById('nav-admin-group');
  if (adminGroup) {
    adminGroup.hidden = inGate || !canAdmin;
  }

  /* 좁은 화면(960px 미만)의 접이식 내비. 넓은 화면에서는 CSS 가 내비를 늘 펼쳐 두므로
     여기서 hidden 을 켜 두어도 영향이 없다 (app.css 의 min-width: 960px 규칙). */
  var appsButton = document.getElementById('appbar-apps');
  var appsBox = sidebar ? sidebar.querySelector('details.appbar__apps') : null;
  if (sidebar && navToggle) {
    sidebar.hidden = true;
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.addEventListener('click', function () {
      var open = sidebar.hidden === true;
      sidebar.hidden = !open;
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (appsButton) {
        appsButton.setAttribute('aria-expanded', open && appsBox && appsBox.open ? 'true' : 'false');
      }
    });
  }

  /* 사용감 라운드 1 UX-12 (DESIGN 2.9 「폰 윗줄」) — 윗줄 앱 이름 = 앱 전환. 서랍을 열고 그 안의 앱 메뉴(`<details class="appbar__apps">`)를
     펼친 뒤 **다른 앱**(지금 앱이 아닌 첫 줄)에 초점을 둔다 → ☰ · 앱 전환 · t-box 세 번이 앱 이름 · t-box 두 번. 이미 펼쳐 있으면 접고 서랍을 닫는다.
     새 메뉴를 만들지 않는다 — 앱 목록은 서랍의 그 메뉴 한 벌(Apps::all)이고, 이동은 그 링크다(권한 판단 0). */
  if (appsButton && sidebar && navToggle && appsBox) {
    appsButton.addEventListener('click', function () {
      var showing = sidebar.hidden !== true && appsBox.open === true;
      if (showing) {
        appsBox.open = false;
        sidebar.hidden = true;
        navToggle.setAttribute('aria-expanded', 'false');
        appsButton.setAttribute('aria-expanded', 'false');
        return;
      }
      sidebar.hidden = false;
      navToggle.setAttribute('aria-expanded', 'true');
      appsBox.open = true;
      appsButton.setAttribute('aria-expanded', 'true');
      var other = appsBox.querySelector('a.app-menu__app:not([aria-current])[href]:not([href="/"])');
      if (other) {
        other.focus();
      }
    });
    appsBox.addEventListener('toggle', function () {
      appsButton.setAttribute('aria-expanded', sidebar.hidden !== true && appsBox.open ? 'true' : 'false');
    });
  }

  /* 26단계 「역할로 보기」 — 띠의 「원래대로」 (PRD F-39, ARCH 5.20).
     띠는 **서버(shell_top.php)가** 그린다. 보는 중에는 can_admin 이 거짓이라 관리 묶음이 닫히고,
     이 단추가 유일한 복귀 경로이기 때문이다. 여기서는 그 단추에 동작만 건다.
     끄기는 `DELETE /api/me/act` 이고 정책은 authenticated 다 — 보는 중의 유효 역할로도 닿는다.
     성공하면 화면을 다시 읽는다(서버가 그린 띠·내비가 통째로 새로 와야 한다). */
  var actStop = document.querySelector('[data-act-stop]');
  if (actStop) {
    actStop.addEventListener('click', function () {
      if (shellCsrf === null) {
        setText(msg, GENERIC_ERROR);
        return;
      }
      actStop.disabled = true;
      fetch(APP_BASE + '/api/me/act', {
        method: 'DELETE',
        headers: { 'X-CSRF-Token': shellCsrf },
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(function (response) {
          if (response.status === 401) {
            toLogin();
            return null;
          }
          if (response.ok) {
            window.location.reload();
            return null;
          }
          return readJson(response).then(function (data) {
            setText(msg, errorMessage(data));
            actStop.disabled = false;
            return null;
          });
        })
        .catch(function () {
          setText(msg, GENERIC_ERROR);
          actStop.disabled = false;
        });
    });
  }

  if (logout) {
    logout.disabled = false;
    logout.addEventListener('click', function () {
      if (shellCsrf === null) {
        setText(msg, GENERIC_ERROR);
        return;
      }
      logout.disabled = true;
      fetch(APP_BASE + '/api/logout', {
        method: 'POST',
        headers: { 'X-CSRF-Token': shellCsrf },
        credentials: 'same-origin',
        cache: 'no-store'
      })
        .then(function (response) {
          if (response.status === 200 || response.status === 401) {
            // Q:F-4 B1-2 — 로그아웃했다: 이 사람의 쓰던 글 임시저장(암호문)을 이 브라우저에서 지운다.
            // 지우기 **전에** 커뮤니티 편집기에 알려 떠나는 길이 사본을 다시 쓰지 않게 한다(독립 검토 M-1).
            try { document.dispatchEvent(new CustomEvent('qaredi:logout')); } catch (e) { /* 옛 브라우저 — 지우기는 그대로 */ }
            clearMyDrafts(me.username);
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

/* ─── 「사용 방법」 도움말 (PRD F-38, ARCH 5.19) ──────────────────
 *
 * 화면 머리의 「사용 방법」 단추(`[data-help-open]`)가 셀이 그린 `#help-dialog` 를 열고,
 * 닫기 단추(`[data-help-close]`)가 닫는다. ESC 는 <dialog> 가 스스로 닫는다(브라우저 기본).
 *
 * 위임 하나만 둔다 — 화면이 스무 개라 화면마다 리스너를 달면 언젠가 한 화면만 빠진다.
 * 도움말에는 요청도 권한 판단도 없다 — 열고 닫는 것이 전부다.
 * 내용은 이미 서버 HTML 에 있다. 이 함수는 글자를 하나도 만들지 않는다
 * (inner/outer HTML 계열 미사용 — PRD S-03).
 */
function initHelpDialog() {
  /* 이 파일의 openDialog()·closeDialog() 는 화면 함수 안(initFiles·initOrg)에 있는 **지역 함수**라
     밖에서 부를 수 없다. 같은 규칙(showModal 이 없는 브라우저에선 아무 일도 하지 않는다)을
     쓰는 한 쌍을 여기 둔다 — 다른 함수는 한 줄도 고치지 않는다. */
  function openHelp(dialog) {
    if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
      dialog.showModal();
    }
  }
  function closeHelp(dialog) {
    if (dialog && dialog.open) {
      dialog.close();
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || typeof target.closest !== 'function') {
      return;
    }
    if (target.closest('[data-help-open]')) {
      openHelp(document.getElementById('help-dialog'));
      return;
    }
    if (target.closest('[data-help-close]')) {
      closeHelp(document.getElementById('help-dialog'));
    }
  });
}

/* ─── 「변경 이력」 (PRD F-47, ARCH 5.23) ──────────────────────────
 *
 * 사이드바 아래의 버전 단추(`[data-changelog-open]`)가 셸이 그린 `#changelog-dialog` 를 열고,
 * 닫기 단추(`[data-changelog-close]`)와 배경 누름이 닫는다. ESC 는 <dialog> 가 스스로 닫는다.
 *
 * 「사용 방법」(initHelpDialog)과 **같은 방식**이다 — 위임 하나, 요청 0, 권한 판단 0.
 * 내용은 이미 서버 HTML 에 있으므로 이 함수는 글자를 하나도 만들지 않는다
 * (inner/outer HTML 계열 미사용 — PRD S-03).
 */
function initChangelogDialog() {
  function changelogDialog() {
    return document.getElementById('changelog-dialog');
  }
  function openChangelog(dialog) {
    if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
      dialog.showModal();
    }
  }
  function closeChangelog(dialog) {
    if (dialog && dialog.open) {
      dialog.close();
    }
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || typeof target.closest !== 'function') {
      return;
    }
    if (target.closest('[data-changelog-open]')) {
      openChangelog(changelogDialog());
      return;
    }
    if (target.closest('[data-changelog-close]')) {
      closeChangelog(changelogDialog());
      return;
    }
    // 배경(backdrop)을 누르면 이벤트 대상이 <dialog> 자신이다 — 안쪽 칸은 자식이 받는다.
    if (target === changelogDialog()) {
      closeChangelog(changelogDialog());
    }
  });
}

/* ─── 화면별 시작 (12단계 F-20) ────────────────────────────────────
 *
 * 어느 화면인지는 서버가 <body data-page="…"> 로 알려 준다. 화면 코드가 URL 을 읽어
 * 스스로 판단하지 않는다. 무엇을 그릴 수 있는지는 여전히 서버가 정하고(라우트 정책 ·
 * /api/me 의 can_*), 여기 분기는 **어떤 함수를 부를지**만 고른다.
 */
function bootPage(page) {
  var msg = document.getElementById('msg');

  // P-5a — 커뮤니티 화면에서는 editor.js 가 먼저 부른 같은 한 번을 받는다(fetchMe).
  fetchMe()
    .then(function (result) {
      if (result.status === 401) {
        toLogin();
        return null;
      }
      if (result.me === null) {
        return null;
      }
      return Promise.resolve(result.me).then(function (me) {
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

        /* 커뮤니티 화면 (커뮤니티 ARCHITECTURE.md 21.1 · 21.3).
           셸(내비·로그아웃·해달·도움말)은 t-box 화면과 같은 것을 쓰므로 위에서 그렸고,
           **화면 동작은 여기서 끝낸다** — 본문·에디터는 `assets/community/editor.js`
           (type="module") 가 맡는다. 이 두 키를 여기서 무시하지 않으면 아래 갈래가 없는 채로
           흘러가므로 지금도 아무 일이 없지만, 나중에 t-box 화면 키가 늘 때 섞이지 않도록
           **뜻을 코드에 적어 둔다**. */
        if (page === 'community-home' || page === 'community-page' || page === 'community-board') {
          return null;
        }

        if (page === 'files') {
          // F-43 — 「사용 방법」 도움말에 지금 허용된 사이트를 채운다(자격이 있을 때만 온다).
          renderFetchHostsHelp(me);
          if (next !== null && document.getElementById('forced-gate')) {
            initForcedGate(csrfToken, next, me.mfa_enabled === true, me.mfa_required === true);
            return null;
          }
          // F-52 — 관문에 걸리지 않은(next === null) 사람에게만 권장 카드를 물어본다.
          initMfaRecommend(me);
          if (document.getElementById('files-body')) {
            initFiles(
              csrfToken,
              Array.isArray(me.upload_levels) ? me.upload_levels : [],
              /* F-43 — 「URL 로 가져오기」 단추는 **자격 + 허용 목록**이 둘 다 있어야 열린다.
                 목록이 비면 어떤 주소도 가져올 수 없으므로(전부 host_not_allowed), 눌러도
                 반드시 실패하는 단추를 보여 주지 않는다. 감추는 것은 편의일 뿐이고,
                 단추를 되살려 POST /api/files/fetch 를 불러도 서버가 다시 막는다 (PRD S-01). */
              me.can_fetch === true && me.fetch_enabled === true,
              // team_id 는 레벨 기본값(team)·팀 선택 기본값에만 쓰는 화면 편의 값이다 (PRD S-01).
              typeof me.team_id === 'number' || typeof me.team_id === 'string' ? me.team_id : null
            );
          }
          return null;
        }
        if (page === 'trash') {
          // Q:F-4 U-2b — 정책은 authenticated 이고, 전체(Admin)·내 휴지통 중 무엇인지는 서버가 그린 표가 말한다.
          if (document.getElementById('trash-body')) {
            initTrash(csrfToken);
          }
          return null;
        }
        if (page === 'settings') {
          initSettings(me, csrfToken);
          if (document.getElementById('password-form')) {
            initAccount(csrfToken, me.mfa_enabled === true, null, me.mfa_required === true);
          }
          // 15-2 — 「내 공유 링크」. 로그인한 사람이면 누구나 자기 링크를 본다 (PRD F-13).
          initShares(csrfToken);
          // F-66 — 「프로그램 토큰」. 대상은 언제나 본인이고, 폼·선택지는 me 의
          //        can_issue_tokens·token_scopes 가 정한다 (역할 비교 0).
          initMyTokens(csrfToken, me);
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
        if (page === 'admin') {
          /* 17단계 — 라우트 정책 role:Admin 과 재인증 관문을 통과한 화면이다 (F-24 · F-25).
             허브(`/admin`)와 매트릭스 편집(`/admin/permissions`)은 셸 키가 같으므로,
             **그 화면에만 있는 요소**로 가른다(`/reauth` 화면을 폼으로 가르는 것과 같은 방식). */
          if (document.getElementById('perm-table')) {
            initAdminPermissions(csrfToken);
          } else if (document.getElementById('fetch-hosts-table')) {
            // F-43 — 허용 사이트 화면. 셸 키가 `admin` 으로 같아서 요소로 가른다.
            initAdminFetchHosts(csrfToken);
          } else if (document.getElementById('admin-shares')) {
            initAdmin(csrfToken, me);
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

/* ─── 해/달 단추 — 밝은 화면 ↔ 어두운 화면 (DESIGN.md 2.12, D-5) ─────────────
 *
 * 상태는 <html data-theme> **하나**뿐이고 색은 CSS 가 그 값으로 그린다. 여기서는
 *   ① 지금 무엇으로 보이는지 읽고(속성이 없으면 OS 설정)
 *   ② 속성을 뒤집고
 *   ③ 다음 방문 때 서버가 첫 그림부터 맞게 그리도록 쿠키에 남긴다
 * 세 가지만 한다. 아이콘은 갈아 끼우지 않는다 — 해·달이 둘 다 들어 있고 CSS 가 하나를 감춘다.
 * 쿠키는 취향일 뿐 비밀이 아니라 HttpOnly 가 아니고, 서버는 그 값을 허용 목록(dark|light)으로
 * 거른 뒤에야 속성으로 그린다 (shell_top.php · login_page.php, PRD S-03).
 */
function currentTheme() {
  var set = document.documentElement.getAttribute('data-theme');
  if (set === 'dark' || set === 'light') { return set; }
  return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/** 단추의 뜻은 「지금 무엇인가」가 아니라 「누르면 무엇이 되는가」다 (DESIGN.md 2.8 — 툴팁이 이 글을 보인다) */
function applyThemeLabel(button) {
  button.setAttribute('aria-label', currentTheme() === 'dark' ? '밝은 화면으로' : '어두운 화면으로');
}

/** 셸에도 로그인 화면에도 같은 단추가 있으므로 화면 분기 밖에서 한 번 건다 */
function initThemeToggle() {
  document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
    applyThemeLabel(button);
    button.addEventListener('click', function () {
      var next = currentTheme() === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      var secure = location.protocol === 'https:' ? '; Secure' : '';
      document.cookie = 'tbox_theme=' + next + '; Path=/; Max-Age=31536000; SameSite=Lax' + secure;
      applyThemeLabel(button);
    });
  });
}

/** 더보기 메뉴 `details.menu` (DESIGN.md 2.15, 2026-09-25) — 여닫기는 <details> 가 하고, 여기서는 닫기만 한다:
    안의 항목을 누르면 · 밖을 누르면 · Esc 면. 문서에 한 번만 걸어 나중에 생긴 메뉴에도 먹는다. */
function initMenus() {
  function closeAll(except) {
    document.querySelectorAll('details.menu[open]').forEach(function (menu) {
      if (menu !== except) { menu.open = false; }
    });
  }
  /* 항목을 누르면 항목 자신의 처리보다 **먼저**(capture) 닫고 초점을 「더보기」로 돌려 둔다 — 그래야 항목이 여는
     창(showModal)이 기억하는 「이전 초점」이 사라진 항목이 아니라 「더보기」가 되어, 창을 닫으면 초점이 돌아온다
     (DESIGN 4장 「키보드」 · 독립 검토 M-1). */
  document.addEventListener('click', function (event) {
    var target = event.target;
    var item = target && target.closest ? target.closest('details.menu .menu__item') : null;
    if (!item) { return; }
    var menu = item.closest('details.menu');
    menu.open = false;
    var summary = menu.querySelector('summary');
    if (summary) { summary.focus(); }
  }, true);
  document.addEventListener('click', function (event) {
    var target = event.target;
    closeAll(target && target.closest ? target.closest('details.menu') : null);
  });
  /* Tab 으로 메뉴 밖으로 나가면 닫는다(검토 L-1) */
  document.addEventListener('focusout', function (event) {
    var menu = event.target && event.target.closest ? event.target.closest('details.menu[open]') : null;
    if (menu && !(event.relatedTarget && menu.contains(event.relatedTarget))) { menu.open = false; }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') { return; }
    var open = document.querySelector('details.menu[open]');
    if (!open) { return; }
    open.open = false;
    var summary = open.querySelector('summary');
    if (summary) { summary.focus(); }
  });
}

/**
 * 좁은 폭의 표 — 쌓인 행의 값 칸 앞 이름표 (DESIGN.md 2.4 「좁은 폭」, 디버깅 묶음 U H1 · 2026-09-26).
 *
 * 720px 미만에서 행 동작 칸이 있는 표는 CSS 가 행을 「이름 줄 · 값 줄 · 동작 줄」로 쌓는다(app.css). 쌓이면 표 머리가
 * 숨으므로 「68 B」·「—」 가 무슨 값인지 알 수 없다 — 그래서 **표 머리(th)의 글자**를 그 열의 칸마다 `data-label` 로 옮겨 적고,
 * CSS 가 `attr(data-label)` 로 값 앞에 작게 그린다. 옮기는 것은 우리가 쓴 머리 글자뿐이고(서버 값 0) 속성 값으로만 넣는다
 * (문자열 → HTML 변환 0 · 인라인 style 0 — PRD S-03). 숨은 머리 칸(검색할 때만 보이는 「폴더」 열)은 건너뛴다 — 그때 행에도
 * 그 칸이 없다. 체크가 든 머리 칸은 그 안의 이름(`span[id]`) 글자를 쓴다.
 * 행은 표를 그린 뒤 스크립트가 채우므로 tbody 의 줄 바뀜을 지켜보다 다시 적는다(표마다 한 번 · 머리 글자가 바뀌어도 따라간다).
 * 넓은 화면에서는 CSS 가 이 속성을 쓰지 않는다 — 표 모양·시험이 보는 DOM(`#… tr td`)은 그대로다.
 */
function watchStackTable(table) {
  if (!table || table.__stackWatched === true || !table.tHead || !table.tBodies || !table.tBodies[0]) {
    return;
  }
  table.__stackWatched = true;
  var head = table.tHead;
  var body = table.tBodies[0];
  function labels() {
    var row = head.rows[0];
    if (!row) { return []; }
    var out = [];
    Array.prototype.forEach.call(row.cells, function (th) {
      if (th.hidden) { return; }
      var named = th.querySelector('span[id]');
      out.push(String((named || th).textContent || '').replace(/\s+/g, ' ').trim());
    });
    return out;
  }
  function apply() {
    var names = labels();
    Array.prototype.forEach.call(body.rows, function (tr) {
      Array.prototype.forEach.call(tr.cells, function (td, i) {
        var name = td.colSpan === 1 && i < names.length ? names[i] : '';
        if (name !== '') {
          if (td.getAttribute('data-label') !== name) { td.setAttribute('data-label', name); }
        } else if (td.hasAttribute('data-label')) {
          td.removeAttribute('data-label');
        }
      });
    });
  }
  apply();
  if (typeof MutationObserver === 'function') {
    new MutationObserver(apply).observe(body, { childList: true });
    new MutationObserver(apply).observe(head, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['hidden'] });
  }
}
function labelStackTables(root) {
  Array.prototype.forEach.call((root || document).querySelectorAll('table.files'), watchStackTable);
}

document.addEventListener('DOMContentLoaded', function () {
  // 서버 HTML 의 <template> 아이콘을 [data-icon] 자리에 붙인다 (문자열 → HTML 변환 0).
  paintIcons(document);

  /* 더보기 메뉴 닫기 (DESIGN.md 2.15) — 메뉴가 없는 화면에서는 아무 일도 하지 않는다 */
  initMenus();

  /* 좁은 폭의 표 — 쌓인 행의 이름표(DESIGN.md 2.4). 서버가 그린 표 전부(창 안의 표 포함) — 표가 없는 화면은 아무 일도 없다 */
  labelStackTables(document);

  /* 역할 고르개의 글자를 한글로(값은 그대로 — DESIGN 5장, 디버깅 묶음 U M7). 역할 고르개가 없는 화면은 아무 일도 없다 */
  relabelRoleOptions(document);

  /* 해/달 단추 (D-5, DESIGN.md 2.12). 셸과 로그인 화면이 같이 쓰므로 아래 화면 분기 **밖**에서
     한 번만 건다 — 단추가 없는 화면에서는 아무 일도 하지 않는다. */
  initThemeToggle();

  /* 「사용 방법」 도움말 (F-38). 어느 화면이든 단추가 있으면 열리게 여기서 한 번 건다 —
     아래에서 먼저 return 하는 갈래기도 도움말은 같이 쓰기 때문이다. */
  initHelpDialog();

  /* 「변경 이력」(F-47). 사이드바 버전 단추는 셸을 쓰는 **모든** 화면에 있으므로
     도움말과 같은 자리에서 한 번 건다. 로그인 화면에는 셸도 단추도 없어 아무 일도 하지 않는다. */
  initChangelogDialog();

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
    fetchMe()
      .then(function (result) {
        if (result.status === 401) {
          toLogin();
          return null;
        }
        return Promise.resolve(result.me || {}).then(function (me) {
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
  /*
   * 18-3 보정 (사용자 관찰 2026-09-13: 셸을 쓰는 화면인데 내비의 「관리자」가 사라짐).
   *
   * 공통 셸을 쓰지만 셸의 화면 키 목록에 없는 화면은 <body data-page> 가 비어 있다.
   * 전에는 여기서 그대로 return 해 `initShell` 이 아예 불리지 않았고, 기본이 hidden 인
   * `#nav-admin-group`·`#nav-admin` 이 닫힌 채 남았다. 재인증 화면(`/reauth`)이
   * 「폼이 있는지」로 예외 처리한 것과 같은 방식을 **셸이 있는지**(`#nav-files`)로 일반화한다.
   *
   * 이 분기는 셸만 그린다. 무엇이 열리는지는 여전히 `/api/me` 의 `can_*` 가 정하고,
   * 항목을 개발자도구로 되살려도 라우트 정책이 다시 막는다 (PRD S-01).
   */
  if (typeof page !== 'string' || page === '') {
    if (!document.getElementById('nav-files')) {
      return;   // 셸이 없는 화면 — 종전과 같이 아무것도 하지 않는다
    }
    fetchMe()
      .then(function (result) {
        if (result.status === 401) {
          toLogin();
          return null;
        }
        if (result.me === null) {
          return null;   // 내비는 기본 상태(닫힘) 그대로 — 화면이 권한을 추측하지 않는다
        }
        return Promise.resolve(result.me).then(function (me) {
          if (typeof me.csrf_token !== 'string') {
            return null;
          }
          // 재인증 모달이 어느 화면에서든 열릴 수 있어 토큰을 한 곳에 둔다 (16-1, F-23).
          reauthCsrf = me.csrf_token;
          initShell(me, me.csrf_token);
          return null;
        });
      })
      .catch(function () {
        // 조용히 둔다 — 셸이 안 열리는 것이 잘못된 항목을 여는 것보다 낫다.
      });
    return;
  }
  bootPage(page);
});
