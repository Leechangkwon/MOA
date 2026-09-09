'use strict';
/* public/assets/app.js — 2단계: 로그인 · 로그아웃
 *
 * 규칙 (PRD S-03, S-09-3, ARCHITECTURE 13장 체크리스트)
 *   · DOM 에 값을 넣을 때는 textContent 만 쓴다. HTML 문자열을 그대로 넣는 API
 *     (inner/outer HTML 계열, document 에 직접 쓰는 계열)는 쓰지 않는다.
 *     금지 패턴이며 scripts/security_lint.sh 가 반려한다.
 *   · 권한 판단을 브라우저에서 하지 않는다. 버튼을 숨기는 것은 편의일 뿐,
 *     허용 여부는 서버 Authz 가 매 요청 판단한다 (PRD S-01).
 *   · 상태를 바꾸는 요청에는 CSRF 토큰 헤더(X-CSRF-Token)를 붙인다 (PRD S-09-3).
 *     토큰은 GET /api/me 로 받는다. 세션 쿠키는 HttpOnly 이므로 JS 가 읽지 않는다.
 *   · 화면에 띄우는 오류 문구는 **서버가 준 문구만** 쓴다. 브라우저가 사유를 추측해
 *     "그런 아이디는 없습니다" 같은 말을 만들지 않는다 (PRD 10장).
 */

var LOGIN_PATH = '/login';
var HOME_PATH = '/';
var GENERIC_ERROR = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';

/** 텍스트를 안전하게 넣는 유일한 통로. 화면 코드는 이 함수만 쓴다. */
function setText(el, value) {
  if (el) {
    el.textContent = value === null || value === undefined ? '' : String(value);
  }
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

/* ─── 로그인 화면 ───────────────────────────────────────────── */
function initLogin(form) {
  var msg = document.getElementById('msg');
  var submit = document.getElementById('login-submit');

  form.addEventListener('submit', function (event) {
    // 브라우저 기본 폼 전송(폼 인코딩)을 막는다 — 서버는 JSON 만 받는다 (ARCH 4.0).
    event.preventDefault();
    setText(msg, '');
    if (submit) {
      submit.disabled = true;
    }

    var payload = {
      username: form.elements.username.value,
      password: form.elements.password.value
    };

    fetch('/api/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then(function (response) {
        if (response.status === 200) {
          window.location.assign(HOME_PATH);
          return null;
        }
        return readJson(response).then(function (body) {
          setText(msg, errorMessage(body));
          form.elements.password.value = '';
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      })
      .then(function () {
        if (submit) {
          submit.disabled = false;
        }
      });
  });
}

/* ─── 로그인한 화면 (홈) ────────────────────────────────────── */
function initHome(button) {
  var msg = document.getElementById('msg');
  var csrfToken = null;

  function toLogin() {
    window.location.assign(LOGIN_PATH);
  }

  // 세션이 살아 있는지 확인하고 CSRF 토큰을 받아 둔다.
  fetch('/api/me', { credentials: 'same-origin', cache: 'no-store' })
    .then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      if (!response.ok) {
        return null;
      }
      return readJson(response).then(function (body) {
        if (typeof body.csrf_token === 'string') {
          csrfToken = body.csrf_token;
          button.disabled = false;
        }
        return null;
      });
    })
    .catch(function () {
      setText(msg, GENERIC_ERROR);
    });

  button.disabled = true;
  button.addEventListener('click', function () {
    if (csrfToken === null) {
      setText(msg, GENERIC_ERROR);
      return;
    }
    button.disabled = true;
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
          button.disabled = false;
          return null;
        });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
        button.disabled = false;
      });
  });
}

document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('login-form');
  if (form) {
    initLogin(form);
    return;
  }
  var logout = document.getElementById('logout');
  if (logout) {
    initHome(logout);
  }
});
