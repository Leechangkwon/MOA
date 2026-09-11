'use strict';
/* public/assets/app.js — 8단계: 로그인 · 로그아웃 · 파일 목록/업로드/다운로드/삭제/수정 ·
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

/**
 * <select> 를 서버가 준 목록으로 채운다. createElement + textContent 만 쓴다.
 * 만들어진 선택지 개수를 돌려준다 (0 이면 업로드할 수 있는 레벨이 하나도 없다는 뜻).
 */
function fillLevelOptions(select, levels) {
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
  levels.forEach(function (level) {
    if (typeof level !== 'string' || !Object.prototype.hasOwnProperty.call(LEVEL_LABELS, level)) {
      return;
    }
    var option = document.createElement('option');
    option.value = level;
    setText(option, LEVEL_LABELS[level]);
    select.appendChild(option);
    made += 1;
  });
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

/* ─── 파일 화면 (5단계) ─────────────────────────────────────── */
function initFiles(csrfToken, uploadLevels, canFetch) {
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
          teams.forEach(function (team) {
            // 같은 목록을 업로드 폼과 가져오기 폼 두 곳의 <select> 에 만든다.
            [teamSelect, fetchTeam].forEach(function (select) {
              if (!select) {
                return;
              }
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
  var levelCount = fillLevelOptions(levelSelect, uploadLevels);
  if (levelCount === 0 && uploadForm && uploadForm.parentNode) {
    uploadForm.parentNode.hidden = true;
  }

  // "URL 로 가져오기" 패널은 서버가 can_fetch === true 를 준 경우에만 연다 (F-14, Manager 이상).
  // 레벨 선택지는 업로드와 같은 목록(서버가 정한 upload_levels)을 쓴다.
  if (fetchPanel) {
    fetchPanel.hidden = canFetch !== true || fillLevelOptions(fetchLevel, uploadLevels) === 0;
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
          // upload_levels 는 서버가 정한 업로드 레벨 선택지다(4단계). 배열이 아니면 빈 목록으로 본다.
          // can_fetch 는 "URL 로 가져오기" 패널 표시 여부다(7단계). true 가 아니면 열지 않는다.
          if (document.getElementById('files-body')) {
            initFiles(
              csrfToken,
              Array.isArray(body.upload_levels) ? body.upload_levels : [],
              body.can_fetch === true
            );
          }
          // 8단계 — "감사 로그" 링크는 서버가 can_view_audit === true 를 준 경우에만 연다
          // (F-09, Admin 만). 링크를 되살려도 /audit·/api/audit 은 서버가 다시 막는다.
          var auditLink = document.getElementById('audit-link');
          if (auditLink) {
            auditLink.hidden = body.can_view_audit !== true;
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
