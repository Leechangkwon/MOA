'use strict';
/* public/assets/app.js — 10단계: 로그인 · 로그아웃 · 파일 목록/업로드/다운로드/삭제/수정 ·
 *                          URL 로 가져오기 · 감사 로그 조회 화면
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
var GENERIC_ERROR = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';

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
    window.location.assign(HOME_PATH);
    return true;
  }
  return false;
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

  function addActions(row, file) {
    var td = document.createElement('td');
    td.className = 'actions';

    // 서버가 준 ID 가 UUID 형태일 때만 링크를 만든다(주소에 예상 밖의 문자가 들어가지 않게).
    if (UUID_RE.test(file.id)) {
      var link = document.createElement('a');
      link.setAttribute('href', '/api/files/' + file.id + '/download');
      link.className = 'btn btn--quiet btn--small';
      setText(link, '다운로드');
      td.appendChild(link);

      // 삭제 버튼은 서버가 그 행에 대해 can_delete 를 참으로 준 경우에만 만든다.
      // 화면이 역할·소유자를 따져서 정하지 않는다 — 판단은 서버가 이미 했다 (PRD S-01, 10장).
      if (file.can_delete === true) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn--quiet btn--small';
        setText(button, '삭제');
        button.addEventListener('click', function () {
          deleteFile(file.id);
        });
        td.appendChild(button);
      }

      // 수정 버튼도 서버가 그 행에 대해 can_edit 를 참으로 준 경우에만 만든다.
      if (file.can_edit === true) {
        var editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'btn btn--quiet btn--small';
        setText(editButton, '수정');
        editButton.addEventListener('click', function () {
          editFile(file);
        });
        td.appendChild(editButton);
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
      addCell(row, file.name);
      addCell(row, formatSize(file.size_bytes));
      addCell(row, file.access_level);
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

  // 업로드 레벨 선택지는 서버가 준 목록으로만 만든다. 하나도 없으면(Guest) 업로드 패널을 감춘다.
  // 감추는 것은 편의일 뿐이다 — 그래도 POST /api/files 는 서버가 다시 막는다 (PRD S-01).
  var hasTeam = myTeamId !== null && myTeamId !== undefined && String(myTeamId) !== '';
  var levelCount = fillLevelOptions(levelSelect, uploadLevels, hasTeam);
  if (levelCount === 0 && uploadForm && uploadForm.parentNode) {
    uploadForm.parentNode.hidden = true;
  }

  // "URL 로 가져오기" 패널은 서버가 can_fetch === true 를 준 경우에만 연다 (F-14, Manager 이상).
  // 레벨 선택지는 업로드와 같은 목록(서버가 정한 upload_levels)을 쓴다.
  if (fetchPanel) {
    fetchPanel.hidden = canFetch !== true || fillLevelOptions(fetchLevel, uploadLevels, hasTeam) === 0;
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
          setText(mfaSecret, result.data.secret);
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
      addCell(row, file.access_level);
      addCell(row, file.uploaded_by);
      addCell(row, file.deleted_by);
      addCell(row, file.deleted_at);
      var td = document.createElement('td');
      td.className = 'actions';
      if (UUID_RE.test(file.id)) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn--quiet btn--small';
        setText(button, '복구');
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

  loadTeams();
  load();
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
   * 2단계 인증 등록 화면은 「내 계정」의 것을 **그대로 옮겨 온다**(initAccount 가 붙인
   * 이벤트도 함께 따라온다). 같은 화면을 두 벌 만들면 언젠가 한쪽만 고쳐진다.
   */
  initAccount(csrfToken, mfaEnabled, function () {
    refresh();
  });
  var mfaOff = document.getElementById('mfa-off');
  if (slot && mfaOff) {
    slot.appendChild(mfaOff);
    mfaOff.hidden = false;
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

/* ─── 로그인한 화면 (홈) ────────────────────────────────────── */
function initHome(button) {
  var msg = document.getElementById('msg');
  var csrfToken = null;

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
          /*
           * 11단계 (PRD F-16) — 최초 로그인 강제 절차가 남아 있으면 **그 화면만** 그린다.
           * 값은 서버가 준 `next` 하나뿐이고 화면이 스스로 판단하지 않는다.
           * 절차 화면이 없는 다른 페이지(/users·/audit)는 관문이 애초에 403 으로 막는다.
           */
          var forcedNext = typeof body.next === 'string' ? body.next : null;
          if (forcedNext !== null && document.getElementById('forced-gate')) {
            initForcedGate(csrfToken, forcedNext, body.mfa_enabled === true);
            return null;
          }
          // 파일 화면은 토큰을 받은 뒤에 시작한다(업로드·삭제에 토큰이 필요하다).
          // upload_levels 는 서버가 정한 업로드 레벨 선택지다(4단계). 배열이 아니면 빈 목록으로 본다.
          // can_fetch 는 "URL 로 가져오기" 패널 표시 여부다(7단계). true 가 아니면 열지 않는다.
          if (document.getElementById('files-body')) {
            initFiles(
              csrfToken,
              Array.isArray(body.upload_levels) ? body.upload_levels : [],
              body.can_fetch === true,
              // team_id 는 레벨 기본값(team)·팀 선택 기본값에만 쓰는 화면 편의 값이다 (PRD S-01).
              typeof body.team_id === 'number' || typeof body.team_id === 'string' ? body.team_id : null
            );
          }
          // 8단계 — "감사 로그" 링크는 서버가 can_view_audit === true 를 준 경우에만 연다
          // (F-09, Admin 만). 링크를 되살려도 /audit·/api/audit 은 서버가 다시 막는다.
          var auditLink = document.getElementById('audit-link');
          if (auditLink) {
            auditLink.hidden = body.can_view_audit !== true;
          }
          // 10단계 — "계정 관리" 링크는 can_manage_users === true 일 때만 연다 (F-10, Admin 만).
          // 링크를 되살려도 /users·/api/users 는 라우터 정책 user.manage 가 다시 막는다.
          var usersLink = document.getElementById('users-link');
          if (usersLink) {
            usersLink.hidden = body.can_manage_users !== true;
          }
          // 10단계 — 내 계정(비밀번호 변경·2단계 인증). 로그인한 사람이면 누구나 자기 것만.
          if (document.getElementById('password-form')) {
            initAccount(csrfToken, body.mfa_enabled === true);
          }
          // 10단계 — 휴지통 패널도 서버가 준 can_manage_users 로만 연다 (F-11, Admin 만).
          var trashPanel = document.getElementById('trash-panel');
          if (trashPanel && body.can_manage_users === true) {
            trashPanel.hidden = false;
            initTrash(csrfToken);
          }
          // 10단계 — 계정 관리 화면(/users). 여기 들어왔다는 것 자체가 정책 user.manage 통과다.
          if (document.getElementById('users-body')) {
            initUsers(csrfToken);
          }
          // 감사 로그 화면(/audit)이면 표를 채운다. 이 화면에 들어왔다는 것 자체가
          // 라우터 정책 audit.read 를 통과했다는 뜻이다.
          if (document.getElementById('audit-body')) {
            initAudit();
          }
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
