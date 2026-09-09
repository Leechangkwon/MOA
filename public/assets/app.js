'use strict';
/* public/assets/app.js — 3단계: 로그인 · 로그아웃 · 파일 목록/업로드/다운로드/삭제
 *
 * 규칙 (PRD S-03, S-09-3, ARCHITECTURE 13장 체크리스트)
 *   · DOM 에 값을 넣을 때는 textContent 만 쓴다. HTML 문자열을 그대로 넣는 API
 *     (inner/outer HTML 계열, document 에 직접 쓰는 계열)는 쓰지 않는다.
 *     금지 패턴이며 scripts/security_lint.sh 가 반려한다.
 *     표의 행도 createElement + textContent 로만 만든다 — 파일명·설명에 어떤 문자가 있어도
 *     문자로만 화면에 들어간다(저장형 XSS 차단).
 *   · 권한 판단을 브라우저에서 하지 않는다. 버튼을 숨기는 것은 편의일 뿐,
 *     허용 여부는 서버 Authz 가 매 요청 판단한다 (PRD S-01).
 *     ★ 3단계에는 화면 권한 로직이 아예 없다 — 삭제 버튼을 모든 사용자에게 보인다.
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

/* ─── 파일 화면 (3단계) ─────────────────────────────────────── */
function initFiles(csrfToken) {
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

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn btn--quiet btn--small';
      setText(button, '삭제');
      button.addEventListener('click', function () {
        deleteFile(file.id);
      });
      td.appendChild(button);
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
          teams.forEach(function (team) {
            var option = document.createElement('option');
            option.value = String(team.id);
            setText(option, team.name);
            teamSelect.appendChild(option);
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

  function upload(event) {
    event.preventDefault();
    setText(msg, '');

    if (!fileInput.files || fileInput.files.length !== 1) {
      // 서버도 400 으로 막지만, 굳이 빈 요청을 보내지 않는다.
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

  syncTeamField();
  loadTeams();
  loadFiles();
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
          // 파일 화면은 토큰을 받은 뒤에 시작한다(업로드·삭제에 토큰이 필요하다).
          if (document.getElementById('files-body')) {
            initFiles(csrfToken);
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
