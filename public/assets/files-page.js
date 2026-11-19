/** 자료실 전용 동작. 공통 app.js의 검증·세션·표현 도우미를 그대로 사용한다. */
export function initFiles(csrfToken, uploadLevels, canFetch, myTeamId) {
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
    recent: document.getElementById('files-view-recent'),
    shared: null   // G-0 — 공유받음은 사이드바 항목(`?view=shared`)으로 연다 — 보기 단추 줄에는 없다(폰 폭에서 한 줄 유지)
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
  var fileSearchController = null;
  var fileSearchRetry = 0;
  function cancelFileSearch() {
    loadSeq++;
    clearTimeout(fileSearchRetry);
    if (fileSearchController) { fileSearchController.abort(); fileSearchController = null; }
  }
  window.addEventListener('pagehide', cancelFileSearch);
  /** 보기마다의 빈 목록 문장(DESIGN 2.4 「(무엇)이 없습니다.」 + 다음에 할 일 한 문장) */
  var EMPTY_TEXT = {
    files: '표시할 파일이 없습니다.',
    favorites: '즐겨찾기한 파일이 없습니다. 파일 줄 이름 앞의 별(☆)을 누르면 여기에 모입니다.',
    recent: '최근 30일 동안 미리보기·받기로 연 파일이 없습니다.',
    shared: '나에게 직접 권한을 준 개인 파일이 없습니다.'
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
  function writeFetch(url, options, transport) {
    var send = transport || fetch;
    if (filesHalted) {
      return Promise.resolve(haltedResponse());
    }
    var sentToken = options && options.headers ? options.headers['X-CSRF-Token'] : null;
    return send(url, options).then(function (response) {
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
              return send(url, again);
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
    ['public', 'internal', 'team', 'admin-only', 'private'].forEach(function (level) {
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
    // G-0 — 개인 파일의 등급은 주인만 바꾼다(서버 decideLevelChange) — 「편집」 권한자에게는 등급 칸을 보이지 않는다(설명만)
    var privateLocked = file.access_level === 'private' && file.can_manage_access !== true;
    if (editLevelField) {
      editLevelField.hidden = locked || privateLocked;
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
        /* G-0 — 개인 파일의 공유 링크·옮기기는 **주인만**(서버 decidePrivateOwner). 「편집」 권한자에게는 단추를 만들지 않는다 —
           서버가 준 can_manage_access 만 본다(화면이 주인을 따지지 않는다). */
        var privateNotOwner = file.access_level === 'private' && file.can_manage_access !== true;
        if (file.exec_content !== true && !privateNotOwner) {
          var shareButton = makeIconButton('link', '공유 링크 만들기');
          shareButton.addEventListener('click', function () {
            openShare(file);
          });
          td.appendChild(shareButton);
        }

        /* 18-1 — 「폴더로 이동」도 **같은 can_edit** 로 갈린다 (PRD F-26: 파일을 옮기는 것은
           그 파일을 고칠 수 있는 사람). 단추가 보이든 안 보이든 서버가 다시 판단하고,
           대상 폴더를 읽을 수 있는지·불변 규칙 ①까지 서버가 본다. */
        if (!privateNotOwner) {
          var moveButton = makeIconButton('files', '폴더로 이동');
          moveButton.addEventListener('click', function () {
            openMove(file);
          });
          td.appendChild(moveButton);
        }
      }

      /* G-0 — 개인 파일이면 「권한」(받은 사람 보기 · 주인은 사람 추가·빼기). 보이는 사람은 누구나 목록을 본다 —
         고치기 단추는 창구가 준 can_manage 로만 열린다(openGrants). */
      if (file.access_level === 'private') {
        var accessButton = makeIconButton('users', '권한 — 받은 사람');
        accessButton.addEventListener('click', function () {
          openGrants('file', file.id, file.name);
        });
        td.appendChild(accessButton);
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

    // Shared dialog exposes only buttons already allowed by the response. Claude keeps its original icons.
    var secondaryButtons = Array.prototype.slice.call(td.children, 2);
    if (secondaryButtons.length > 0) {
      var secondary = document.createElement('span');
      secondary.className = 'q-file-secondary';
      secondaryButtons.forEach(function (button) { secondary.appendChild(button); });
      td.appendChild(secondary);
      var more = document.createElement('button');
      more.type = 'button';
      more.className = 'btn btn--icon q-file-more';
      more.setAttribute('aria-label', '더보기 — ' + file.name);
      more.setAttribute('aria-haspopup', 'dialog');
      more.setAttribute('aria-controls', 'q-file-actions-dialog');
      more.setAttribute('aria-expanded', 'false');
      setText(more, '···');
      more.addEventListener('click', function () { openFileActions(file, secondaryButtons, more); });
      td.appendChild(more);
    }
    row.appendChild(td);
  }

  var fileActionsDialog = document.getElementById('q-file-actions-dialog');
  var fileActionsList = document.getElementById('q-file-actions-list');
  var fileActionsInvoker = null;
  function openFileActions(file, buttons, invoker) {
    if (!fileActionsDialog || !fileActionsList || fileActionsDialog.open) { return; }
    setText(document.getElementById('q-file-actions-title'), file.name);
    fileActionsList.replaceChildren();
    buttons.forEach(function (source) {
      var action = source.cloneNode(true);
      action.classList.add('q-file-operation');
      var label = document.createElement('span');
      setText(label, source.getAttribute('aria-label') || '작업');
      action.appendChild(label);
      action.addEventListener('click', function () {
        fileActionsDialog.close();
        source.click(); // Original handler retains confirmation, reauthentication and request semantics.
      });
      fileActionsList.appendChild(action);
    });
    fileActionsInvoker = invoker;
    invoker.setAttribute('aria-expanded', 'true');
    fileActionsDialog.showModal();
    var first = fileActionsList.querySelector('button:not(:disabled)');
    if (first) { first.focus(); }
  }
  if (fileActionsDialog) {
    document.getElementById('q-file-actions-close').addEventListener('click', function () { fileActionsDialog.close(); });
    fileActionsDialog.addEventListener('close', function () {
      fileActionsList.replaceChildren();
      if (fileActionsInvoker) {
        fileActionsInvoker.setAttribute('aria-expanded', 'false');
        if (fileActionsInvoker.isConnected && !document.querySelector('dialog[open]')) { fileActionsInvoker.focus(); }
      }
    });
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
    nameCell.classList.add('q-file-name-cell');
    var filename = nameCell.firstChild;
    filename.classList.add('q-file-name');
    var nameLine = document.createElement('span');
    nameLine.className = 'q-file-name-line';
    nameCell.insertBefore(nameLine, filename);
    nameLine.appendChild(filename);
    var detailParts = [];
    if (typeof file.team_name === 'string' && file.team_name !== '') { detailParts.push('팀: ' + file.team_name); }
    if (typeof file.description === 'string' && file.description !== '') { detailParts.push(file.description); }
    if (detailParts.length > 0) {
      var detail = document.createElement('span');
      detail.className = 'q-file-name-detail';
      setText(detail, detailParts.join(' · '));
      nameCell.appendChild(detail);
    }
    // 개발 대기열 6 — 이름 앞의 별(즐겨찾기 넣기·빼기). 행 동작 칸(보기→받기→…→삭제, DESIGN 2.1)은 그대로 둔다.
    var star = makeFileStar(file);
    if (star !== null) {
      nameLine.insertBefore(star, nameLine.firstChild);
    }
    // 라운드 B — 줄 체크(일괄 작업 고르기)는 별보다 앞 · 이름 칸 안(열을 늘리지 않는다 — DESIGN 2.4 폭 규칙 그대로)
    var check = makeRowCheck(file);
    if (check !== null) {
      nameLine.insertBefore(check, nameLine.firstChild);
    }
    addCell(row, formatSize(file.size_bytes)).className = 'q-file-size';
    addLevelCell(row, file.access_level, file.grants);
    addCell(row, file.team_name === null || file.team_name === undefined ? '—' : file.team_name).className = 'q-file-team';
    if (showFolder) {
      // 서버가 준 folder_path 그대로. 루트면 「—」 (경로는 표시용이며 권한과 무관하다).
      addCell(row, typeof file.folder_path === 'string' && file.folder_path !== ''
        ? file.folder_path
        : '—');
    }
    addCell(row, file.uploaded_by).className = 'q-file-uploader';
    addWhenCell(row, whenValue).className = 'q-file-when';
    addCell(row, file.description).className = 'q-file-description';
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
    syncFileContext(files.length, false);
    syncEmptyAction();
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
    if (view !== 'files' && view !== 'favorites' && view !== 'recent' && view !== 'shared') {
      return;
    }
    accessHideForView(view);   // G-0 — 공유받음 묶음·요청 화면은 그 보기에서만
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
    setText(whenHead, view === 'recent' ? '마지막으로 연 때' : (view === 'shared' ? '받은 때' : '올린 시각'));
    setText(empty, EMPTY_TEXT[view]);
    setText(msg, '');
    showDone('');
    bulkClear();   // 라운드 B (M-4) — 보기를 바꾸면 고른 것이 풀린다
    loadFiles();
  }

  function syncFileContext(count, searching) {
    var title = state.view === 'favorites' ? '즐겨찾기' : state.view === 'recent' ? '최근 파일'
      : searching ? '검색 결과' : state.crumbs.length ? state.crumbs[state.crumbs.length - 1].name : '맨 위';
    setText(document.getElementById('q-location-title'), title);
    setText(document.getElementById('q-location-count'), (searching ? '전체 폴더에서 ' : '') + count + '개의 파일');
  }

  function syncEmptyAction() {
    var action = document.getElementById('q-files-empty-action');
    if (!action) { return; }
    var filtered = state.view === 'files' && state.q !== '';
    setText(empty, filtered ? '검색 결과가 없습니다.' : EMPTY_TEXT[state.view]);
    setText(document.getElementById('q-files-empty-hint'), filtered ? '검색어를 바꾸거나 지워서 다시 찾아보세요.'
      : state.view === 'files' ? '이 폴더에 첫 파일을 올려 업무를 시작하세요.' : '파일 보기에서 미리보기·받기 또는 별 표시를 사용해 보세요.');
    setText(action, filtered ? '검색 지우기' : state.view !== 'files' ? '파일 보기' : '파일 올리기');
    action.hidden = !empty || empty.hidden || (state.view === 'files' && !filtered && uploadLevels.length === 0);
  }
  var emptyAction = document.getElementById('q-files-empty-action');
  if (emptyAction) {
    emptyAction.addEventListener('click', function () {
      if (state.view !== 'files') { setView('files'); }
      else if (state.q !== '') { state.q = ''; state.page = 1; if (qInput) { qInput.value = ''; } loadFiles(); }
      else if (uploadLevels.length > 0) { document.getElementById('upload-toggle').click(); }
    });
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
    // 규모 D2a A3 (DESIGN 2.4) — 서버는 1,000 까지만 센다. 넘으면 total_capped 가 참 → 「1,000+」 · 쪽 번호는 상한(1,000)까지만
    state.totalCapped = data.total_capped === true;
    state.perPage = typeof data.per_page === 'number' && data.per_page > 0 ? data.per_page : 20;
    state.page = typeof data.page === 'number' && data.page > 0 ? data.page : 1;

    files.forEach(function (file) {
      body.appendChild(fileRow(file, searching, file.created_at));
    });
    showFresh();

    if (empty) {
      empty.hidden = files.length !== 0;
    }

    var totalText = state.totalCapped ? '1,000+' : String(state.total);
    syncFileContext(totalText, searching);
    syncEmptyAction();
    var lastPage = state.total === 0 ? 1 : Math.ceil(state.total / state.perPage);
    setText(pageInfo, state.page + ' / ' + lastPage + ' 페이지 · 전체 ' + totalText + '건');
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
    cancelFileSearch();
    if (state.view === 'favorites') {
      loadFavoritesView();
      return;
    }
    if (state.view === 'shared') {
      loadSharedView();   // G-0
      return;
    }
    if (state.view === 'recent') {
      loadRecentView();
      return;
    }
    loadFolderFiles();
  }

  function loadFolderFiles(retrying) {
    cancelFileSearch();
    var seq = ++loadSeq;
    var normalized = state.q.normalize('NFC');
    if (state.q !== '' && Array.from(normalized.replace(/[\s\p{Z}]/gu, '')).length < 2) {
      clearRows();
      setText(msg, '공백을 제외하고 두 글자 이상 입력해 주세요.');
      return;
    }
    fileSearchController = new AbortController();
    var signal = fileSearchController.signal;
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

    fetch(APP_BASE + '/api/files' + query, { credentials: 'same-origin', cache: 'no-store', signal: signal })
      .then(function (response) {
        if (seq !== loadSeq || signal.aborted) { return null; }
        if (response.status === 429 && !retrying) {
          var seconds = Number(response.headers.get('Retry-After')) || 1;
          fileSearchRetry = setTimeout(function () {
            if (seq === loadSeq && state.view === 'files') { loadFolderFiles(true); }
          }, Math.min(3, Math.max(1, seconds)) * 1000);
          setText(msg, '앞선 검색을 마치는 중입니다. 잠시 기다려 주세요.');
          return null;
        }
        if (response.status === 401) {
          toLogin();
          return null;
        }
        return readJson(response).then(function (data) {
          // 개발 대기열 6 — 그 사이 다른 보기로 바뀌었으면 옛 응답으로 표를 덮지 않는다
          if (seq !== loadSeq || state.view !== 'files') {
            return null;
          }
          if (data && data.notice) {
            clearRows();
            setText(msg, data.notice);
            return null;
          }
          if (!response.ok) {
            // G-0 — 연 폴더를 볼 수 없으면(없는 번호도 같은 403) 이름 없이 권한 요청 화면을 연다
            if (response.status === 403 && state.q === '' && state.folder !== 0) {
              clearRows();
              showAccessRequest('folder', String(state.folder));
              return null;
            }
            setText(msg, errorMessage(data));
            return null;
          }
          accessRequestHide();
          renderFiles(data);
          return null;
        });
      })
      .catch(function (error) {
        if (seq !== loadSeq || error.name === 'AbortError') { return; }
        setText(msg, GENERIC_ERROR);
      });
  }

  /* ─── G-0 「개인」 등급 · 사람 지정 권한 · 권한 요청 (t-box PRD F-73 · DESIGN 2.4 「권한」 · 5장 낱말) ─────────────
   * 화면은 판단하지 않는다 — 누가 보는지·누가 고칠 수 있는지는 창구(`…/grants` · `/api/grants/mine` · `/api/access-requests`)가
   * 정하고 여기서는 그 답만 그린다(PRD S-01). 글자는 전부 textContent · 인라인 style 0 · 새 클래스 0(.list·.badge·.toolbar·.field).
   *   권한 창     개인 폴더·파일의 받은 사람(직접 · 위 폴더에서 이어받음) — 주인(can_manage)에게만 사람 추가·보기/편집·빼기
   *   요청 화면   주소로 연 폴더·파일을 볼 수 없을 때(없는 번호도 같은 화면) — 이름 없이 보기/편집 + 한 줄 메모
   *   공유받음    나에게 준 권한으로 여는 폴더·파일 + 받은 권한 요청(허락·거절) + 보낸 권한 요청의 상태
   */
  var folderAccessBtn = document.getElementById('folder-access');
  var folderAccessLabel = document.getElementById('folder-access-label');
  var folderPutNote = document.getElementById('folder-put-note');
  var folderPutRequest = document.getElementById('folder-put-request');
  var grantsDialog = document.getElementById('grants-dialog');
  var grantsItem = document.getElementById('grants-item');
  var grantsMsg = document.getElementById('grants-msg');
  var grantsAddWrap = document.getElementById('grants-add-wrap');
  var grantsSearch = document.getElementById('grants-search');
  var grantsLevel = document.getElementById('grants-level');
  var grantsHint = document.getElementById('grants-hint');
  var grantsCandidates = document.getElementById('grants-candidates');
  var grantsCount = document.getElementById('grants-count');
  var grantsList = document.getElementById('grants-list');
  var grantsClose = document.getElementById('grants-close');
  var grantsCloseX = document.getElementById('grants-close-x');
  var accessPanel = document.getElementById('access-request');
  var accessTitle = document.getElementById('access-request-title');
  var accessForm = document.getElementById('access-request-form');
  var accessMessage = document.getElementById('access-request-message');
  var accessSend = document.getElementById('access-request-send');
  var accessMsg = document.getElementById('access-request-msg');
  var accessDone = document.getElementById('access-request-done');
  var accessWriteLabel = document.getElementById('access-want-write-label');
  var sharedFoldersWrap = document.getElementById('shared-folders-wrap');
  var sharedFoldersList = document.getElementById('shared-folders');
  var accessInWrap = document.getElementById('access-in-wrap');
  var accessInTitle = document.getElementById('access-in-title');
  var accessInList = document.getElementById('access-in-list');
  var accessInEmpty = document.getElementById('access-in-empty');
  var accessInMsg = document.getElementById('access-in-msg');
  var accessOutWrap = document.getElementById('access-out-wrap');
  var accessOutList = document.getElementById('access-out-list');
  var accessOutEmpty = document.getElementById('access-out-empty');
  /** 지금 권한 창이 보는 항목 {kind, key, name} · 요청 화면의 항목 {kind, key} */
  var grantsTarget = null;
  var grantsTimer = 0;
  var accessTarget = null;
  /** `?requests=in` 으로 열었나 — 받은 요청 묶음을 비어 있어도 보이고 제목에 초점 */
  var accessFocusIn = false;
  /** 편집 권한을 요청할 수 있나 — 올릴 수 있는 등급이 하나라도 있는 계정(손님은 보기만 · 서버가 다시 막는다) */
  var canAskWrite = Array.isArray(uploadLevels) && uploadLevels.length > 0;
  var ACCESS_STATUS = { pending: '기다리는 중', approved: '허락됨', rejected: '거절됨', cancelled: '거둠' };
  var ACCESS_STATUS_BADGE = { pending: 'badge badge--internal', approved: 'badge badge--ok', rejected: 'badge badge--off', cancelled: 'badge badge--off' };

  /** 권한 창구 공통 — 401 은 로그인, 403 next:reauth 는 재인증 창(통과하면 같은 요청을 한 번 더), 그 밖은 {ok,status,data} */
  function accessFetch(method, url, payload) {
    var options = { method: method, credentials: 'same-origin', cache: 'no-store' };
    if (method !== 'GET') {
      options.headers = csrfHeaders();
      if (payload !== undefined) {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(payload);
      }
    }
    return fetch(url, options).then(function (response) {
      if (response.status === 401) {
        toLogin();
        return null;
      }
      return readJson(response).then(function (data) {
        if (handleReauth(response, data, function () { accessFetch(method, url, payload).then(accessAfterReauth); })) {
          return null;
        }
        return { ok: response.ok, status: response.status, data: data };
      });
    });
  }
  /** 재인증 뒤 다시 보낸 요청의 뒤처리 — 창을 새로 그린다(열려 있는 것만) */
  function accessAfterReauth() {
    if (grantsDialog && grantsDialog.open) {
      loadGrants();
    }
    if (state.view === 'shared') {
      loadAccessRequests();
    }
  }

  /** 개인 폴더의 「권한」 단추 · 보기 권한만일 때의 한 줄 · 올리기·새 폴더 잠금(서버가 준 can_put 그대로) */
  function syncFolderAccess() {
    var info = state.folderInfo;
    var isPrivate = state.folder !== 0 && !!info && info.access_level === 'private';
    if (folderAccessBtn) {
      folderAccessBtn.hidden = !isPrivate;
    }
    if (isPrivate) {
      setText(folderAccessLabel, typeof info.grants === 'number' && info.grants > 0 ? '권한 · ' + info.grants + '명' : '권한');
    }
    var blocked = state.folder !== 0 && !!info && info.can_put === false;
    if (folderPutNote) {
      folderPutNote.hidden = !(blocked && isPrivate);
    }
    if (uploadToggle) {
      uploadToggle.disabled = blocked;
    }
    if (folderNewBtn) {
      folderNewBtn.disabled = blocked;
    }
  }

  function grantsUrl(target, userId) {
    var base = target.kind === 'file'
      ? APP_BASE + '/api/files/' + encodeURIComponent(target.key) + '/grants'
      : APP_BASE + '/api/folders/' + encodeURIComponent(target.key) + '/grants';
    return userId === undefined ? base : base + '/' + encodeURIComponent(String(userId));
  }

  /** 권한 창 열기 — 항목 종류와 서버가 준 번호(파일 UUID · 폴더 번호)만 받는다 */
  function openGrants(kind, key, name) {
    var ok = kind === 'file' ? UUID_RE.test(String(key)) : /^[1-9][0-9]{0,8}$/.test(String(key));
    if (!ok || !grantsDialog || typeof grantsDialog.showModal !== 'function' || grantsDialog.open) {
      return;
    }
    grantsTarget = { kind: kind, key: String(key), name: typeof name === 'string' ? name : '' };
    setText(grantsItem, (kind === 'file' ? '파일 ' : '폴더 ') + '「' + grantsTarget.name + '」');
    setText(grantsMsg, '');
    setText(grantsCount, '불러오는 중입니다…');
    if (grantsList) { grantsList.replaceChildren(); }
    if (grantsAddWrap) { grantsAddWrap.hidden = true; }
    if (grantsSearch) { grantsSearch.value = ''; }
    if (grantsCandidates) { grantsCandidates.replaceChildren(); grantsCandidates.hidden = true; }
    if (grantsHint) { grantsHint.hidden = false; }
    grantsDialog.showModal();
    loadGrants();
  }

  function closeGrants() {
    if (grantsDialog && grantsDialog.open) {
      grantsDialog.close();
    }
  }

  function grantBadge(write) {
    var badge = document.createElement('span');
    badge.className = write ? 'badge badge--team' : 'badge badge--internal';
    setText(badge, write ? '편집' : '보기');
    return badge;
  }

  /** 권한 한 줄 — 이름(아이디) · 보기/편집 · 준 날 · (주인) 보기/편집 바꾸기 · 빼기. 이어받은 줄은 어디서 왔는지만 */
  function grantRow(grant, canManage) {
    var li = document.createElement('li');
    li.className = 'list__item';
    var head = document.createElement('div');
    head.className = 'list__head';
    var who = document.createElement('strong');
    setText(who, personLabel(grant.display_name, grant.username) + (grant.active === false ? ' (쓰지 않는 계정)' : ''));
    head.appendChild(who);
    head.appendChild(grantBadge(grant.write === true));
    li.appendChild(head);
    var body = document.createElement('div');
    body.className = 'list__body';
    var when = document.createElement('span');
    setText(when, '준 날 ' + shortWhen(grant.granted_at).text);
    body.appendChild(when);
    if (grant.inherited === true) {
      var from = document.createElement('span');
      setText(from, grant.from && typeof grant.from.name === 'string'
        ? '위 폴더 「' + grant.from.name + '」 에서 이어받음'
        : '위 폴더에서 이어받음');
      body.appendChild(from);
    } else if (canManage && typeof grant.user === 'number') {
      var pick = document.createElement('select');
      pick.setAttribute('aria-label', personLabel(grant.display_name, grant.username) + ' 의 권한');
      [['view', '보기'], ['write', '편집']].forEach(function (pair) {
        var option = document.createElement('option');
        option.value = pair[0];
        setText(option, pair[1]);
        option.selected = (pair[0] === 'write') === (grant.write === true);
        pick.appendChild(option);
      });
      pick.addEventListener('change', function () {
        putGrant(grant.username, pick.value === 'write');
      });
      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn btn--quiet btn--small btn--danger';
      setText(remove, '빼기');
      remove.addEventListener('click', function () {
        remove.disabled = true;
        removeGrant(grant.user);
      });
      // 고르개·빼기는 도구줄 하나에(`.toolbar` — 고르개가 다른 창의 고르개와 같은 높이·테두리 · 새 클래스 0)
      var tools = document.createElement('div');
      tools.className = 'toolbar';
      tools.appendChild(pick);
      tools.appendChild(remove);
      li.appendChild(body);
      li.appendChild(tools);
      return li;
    }
    li.appendChild(body);
    return li;
  }

  function loadGrants() {
    var target = grantsTarget;
    if (!target) {
      return;
    }
    accessFetch('GET', grantsUrl(target))
      .then(function (result) {
        if (result === null || target !== grantsTarget) {
          return;
        }
        if (!result.ok) {
          setText(grantsCount, '');
          setText(grantsMsg, errorMessage(result.data));
          return;
        }
        var data = result.data || {};
        if (data.item && typeof data.item.name === 'string') {
          setText(grantsItem, (target.kind === 'file' ? '파일 ' : '폴더 ') + '「' + data.item.name + '」');
        }
        var canManage = data.can_manage === true && data.effective === true;
        if (grantsAddWrap) {
          grantsAddWrap.hidden = !canManage;
        }
        var rows = Array.isArray(data.grants) ? data.grants : [];
        if (grantsList) {
          grantsList.replaceChildren();
          rows.forEach(function (grant) {
            if (grant && typeof grant.username === 'string') {
              grantsList.appendChild(grantRow(grant, canManage));
            }
          });
        }
        var inherited = rows.filter(function (grant) { return grant && grant.inherited === true; }).length;
        if (data.effective !== true) {
          setText(grantsCount, '이 항목은 지금 「개인」 등급이 아니어서 아래 줄은 효력이 없습니다(다시 개인으로 좁히면 되살아납니다).');
        } else if (rows.length === 0) {
          setText(grantsCount, '아직 권한을 준 사람이 없습니다 — 주인만 봅니다.');
        } else {
          setText(grantsCount, '권한을 받은 사람 ' + rows.length + '명'
            + (inherited > 0 ? ' (위 폴더에서 이어받은 ' + inherited + '명 포함)' : ''));
        }
      })
      .catch(function () {
        setText(grantsMsg, GENERIC_ERROR);
      });
  }

  /** 주거나 바꾸기 — 누르는 즉시 창구로 간다. 성공하면 창과 목록(「개인 · n명」)을 다시 그린다 */
  function putGrant(username, write) {
    var target = grantsTarget;
    if (!target) {
      return;
    }
    setText(grantsMsg, '');
    accessFetch('POST', grantsUrl(target), { user: username, write: write === true })
      .then(function (result) {
        if (result === null) {
          return;
        }
        if (!result.ok) {
          setText(grantsMsg, errorMessage(result.data));
          loadGrants();
          return;
        }
        if (grantsSearch) { grantsSearch.value = ''; }
        if (grantsCandidates) { grantsCandidates.replaceChildren(); grantsCandidates.hidden = true; }
        if (grantsHint) { grantsHint.hidden = false; }
        loadGrants();
        loadFiles();
        loadTree();
      })
      .catch(function () {
        setText(grantsMsg, GENERIC_ERROR);
      });
  }

  function removeGrant(userId) {
    var target = grantsTarget;
    if (!target) {
      return;
    }
    setText(grantsMsg, '');
    accessFetch('DELETE', grantsUrl(target, userId))
      .then(function (result) {
        if (result === null) {
          return;
        }
        if (!result.ok) {
          setText(grantsMsg, errorMessage(result.data));
        }
        loadGrants();
        loadFiles();
        loadTree();
      })
      .catch(function () {
        setText(grantsMsg, GENERIC_ERROR);
      });
  }

  /** 사람 후보 — 한 글자부터(커뮤니티 공유 창과 같은 모양). 고르면 곧바로 준다(보기/편집은 옆 고르개) */
  function loadGrantCandidates() {
    var q = grantsSearch ? grantsSearch.value.trim() : '';
    if (grantsHint) {
      grantsHint.hidden = q !== '';
    }
    if (q === '') {
      if (grantsCandidates) { grantsCandidates.replaceChildren(); grantsCandidates.hidden = true; }
      return;
    }
    accessFetch('GET', APP_BASE + '/api/access/people?q=' + encodeURIComponent(q))
      .then(function (result) {
        if (result === null || !grantsCandidates) {
          return;
        }
        if (!result.ok) {
          setText(grantsMsg, errorMessage(result.data));
          return;
        }
        var people = result.data && Array.isArray(result.data.candidates) ? result.data.candidates : [];
        grantsCandidates.replaceChildren();
        people.forEach(function (person) {
          if (!person || typeof person.username !== 'string') {
            return;
          }
          var li = document.createElement('li');
          li.className = 'list__item';
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'btn btn--quiet btn--small';
          setText(button, personLabel(person.display_name, person.username) + ' · ' + roleLabel(person.role) + ' — 추가');
          button.addEventListener('click', function () {
            button.disabled = true;
            putGrant(person.username, grantsLevel ? grantsLevel.value === 'write' : false);
          });
          li.appendChild(button);
          grantsCandidates.appendChild(li);
        });
        grantsCandidates.hidden = people.length === 0;
        setText(grantsMsg, people.length === 0 ? '그런 아이디나 이름이 없습니다' : '');
      })
      .catch(function () {
        setText(grantsMsg, GENERIC_ERROR);
      });
  }

  /* ── 요청 화면 — 이름을 보이지 않는다(없는 번호도 같은 화면) ── */
  function showAccessRequest(kind, key, wantWrite) {
    if (!accessPanel) {
      return;
    }
    accessTarget = { kind: kind, key: String(key) };
    accessPanel.hidden = false;
    if (accessForm) { accessForm.hidden = false; }
    if (accessDone) { accessDone.hidden = true; setText(accessDone, ''); }
    setText(accessMsg, '');
    if (accessMessage) { accessMessage.value = ''; }
    if (accessWriteLabel) { accessWriteLabel.hidden = !canAskWrite; }
    var want = wantWrite === true && canAskWrite ? 'write' : 'view';
    Array.prototype.forEach.call(document.querySelectorAll('input[name="access-want"]'), function (radio) {
      radio.checked = radio.value === want;
    });
    if (empty) { empty.hidden = true; }
    setText(pageInfo, '');
    if (accessSend) { accessSend.disabled = false; }
    if (accessTitle && typeof accessTitle.focus === 'function') {
      accessTitle.focus();
    }
  }

  function accessRequestHide() {
    if (accessPanel) {
      accessPanel.hidden = true;
    }
    accessTarget = null;
  }

  function submitAccessRequest(event) {
    event.preventDefault();
    var target = accessTarget;
    if (!target) {
      return;
    }
    var picked = document.querySelector('input[name="access-want"]:checked');
    var payload = {
      write: !!picked && picked.value === 'write',
      message: accessMessage ? accessMessage.value.trim().slice(0, 200) : ''
    };
    if (target.kind === 'file') {
      payload.file = target.key;
    } else {
      payload.folder = parseInt(target.key, 10);
    }
    setText(accessMsg, '');
    if (accessSend) { accessSend.disabled = true; }
    accessFetch('POST', APP_BASE + '/api/access-requests', payload)
      .then(function (result) {
        if (accessSend) { accessSend.disabled = false; }
        if (result === null) {
          return;
        }
        if (!result.ok) {
          setText(accessMsg, errorMessage(result.data));
          return;
        }
        if (accessForm) { accessForm.hidden = true; }
        if (accessDone) {
          accessDone.hidden = false;
          setText(accessDone, result.data && typeof result.data.message === 'string' ? result.data.message : '요청을 보냈습니다');
        }
      })
      .catch(function () {
        if (accessSend) { accessSend.disabled = false; }
        setText(accessMsg, GENERIC_ERROR);
      });
  }

  /* ── 공유받음 보기 ── */
  function accessHideForView(view) {
    accessRequestHide();
    if (view !== 'shared') {
      [sharedFoldersWrap, accessInWrap, accessOutWrap].forEach(function (wrap) {
        if (wrap) { wrap.hidden = true; }
      });
    }
  }

  function drawSharedFolders(folders) {
    if (!sharedFoldersList || !sharedFoldersWrap) {
      return;
    }
    sharedFoldersList.replaceChildren();
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
      setText(name, folder.name);
      button.appendChild(name);
      var meta = document.createElement('span');
      meta.className = 'foldertree__meta';
      meta.appendChild(grantBadge(folder.write === true));
      var owner = document.createElement('span');
      owner.className = 'foldertree__team';
      setText(owner, folder.owner ? personLabel(folder.owner.display_name, folder.owner.username) : '');
      meta.appendChild(owner);
      button.appendChild(meta);
      button.addEventListener('click', function () {
        goToFolder(folder.id);
      });
      li.appendChild(button);
      sharedFoldersList.appendChild(li);
    });
    sharedFoldersWrap.hidden = folders.length === 0;
  }

  /** 받은 파일 한 줄 — 서버가 준 칸을 표 한 줄 모양(fileRow)으로 옮긴다. 편집 권한이면 설명 고치기 단추(can_edit) */
  function sharedFileRow(file) {
    return {
      id: file.id, name: file.name, size_bytes: file.size_bytes, access_level: 'private', grants: null,
      team_name: null, folder_path: null, description: '',
      uploaded_by: file.owner ? personLabel(file.owner.display_name, file.owner.username) : '',
      created_at: file.granted_at, can_edit: file.write === true, can_delete: false, can_manage_access: false,
      preview_kind: '', sensitive: false, scan_status: file.scan_status, exec_content: file.exec_content === true
    };
  }

  function loadSharedView() {
    var seq = ++loadSeq;
    accessFetch('GET', APP_BASE + '/api/grants/mine')
      .then(function (result) {
        if (result === null || seq !== loadSeq || state.view !== 'shared') {
          return;
        }
        if (!result.ok) {
          clearRows();
          setText(msg, errorMessage(result.data));
          return;
        }
        var data = result.data || {};
        drawSharedFolders(Array.isArray(data.folders) ? data.folders : []);
        drawPlainList((Array.isArray(data.files) ? data.files : []).map(sharedFileRow), '공유받은 파일', 'created_at');
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
    loadAccessRequests();
  }

  function accessStatusBadge(status) {
    var badge = document.createElement('span');
    badge.className = Object.prototype.hasOwnProperty.call(ACCESS_STATUS_BADGE, status) ? ACCESS_STATUS_BADGE[status] : 'badge';
    setText(badge, Object.prototype.hasOwnProperty.call(ACCESS_STATUS, status) ? ACCESS_STATUS[status] : String(status));
    return badge;
  }

  function accessItemText(item) {
    if (!item || typeof item.name !== 'string' || item.name === '') {
      return '볼 수 없는 항목';
    }
    var text = (item.kind === 'file' ? '파일 ' : '폴더 ') + '「' + (typeof item.path === 'string' && item.path !== '' ? item.path : item.name) + '」';
    return text;
  }

  /** 받은 요청 한 줄 — 누가 · 무엇을 · 보기/편집 · 메모 · 때 · (기다리면) 허락(보기)·허락(편집)·거절 */
  function accessInRow(req) {
    var li = document.createElement('li');
    li.className = 'list__item';
    var head = document.createElement('div');
    head.className = 'list__head';
    var who = document.createElement('strong');
    var rq = req.requester || {};
    setText(who, personLabel(rq.display_name, rq.username) + ' 님이 ' + (req.want_write === true ? '편집' : '보기') + ' 권한을 요청');
    head.appendChild(who);
    head.appendChild(accessStatusBadge(req.status));
    li.appendChild(head);
    var body = document.createElement('div');
    body.className = 'list__body';
    var what = document.createElement('span');
    setText(what, accessItemText(req.item));
    body.appendChild(what);
    if (typeof req.message === 'string' && req.message !== '') {
      var note = document.createElement('span');
      setText(note, '「' + req.message + '」');
      body.appendChild(note);
    }
    var when = document.createElement('span');
    setWhen(when, req.created_at);
    body.appendChild(when);
    li.appendChild(body);
    if (req.status === 'pending' && typeof req.id === 'number') {
      var bar = document.createElement('div');
      bar.className = 'toolbar';
      [['approve_view', '허락(보기)'], ['approve_write', '허락(편집)'], ['reject', '거절']].forEach(function (pair) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = pair[0] === 'reject' ? 'btn btn--quiet btn--small btn--danger' : 'btn btn--quiet btn--small';
        setText(button, pair[1]);
        if (pair[0] === 'approve_write' && rq.can_write !== true) {
          button.disabled = true;   // 손님에게는 편집을 줄 수 없다(서버가 다시 막는다)
        }
        button.addEventListener('click', function () {
          Array.prototype.forEach.call(bar.querySelectorAll('button'), function (b) { b.disabled = true; });
          decideAccess(req.id, pair[0]);
        });
        bar.appendChild(button);
      });
      li.appendChild(bar);
    }
    return li;
  }

  /** 보낸 요청 한 줄 — 무엇에(볼 수 있을 때만 이름) · 보기/편집 · 상태 · 허락되어 보이면 열기 */
  function accessOutRow(req) {
    var li = document.createElement('li');
    li.className = 'list__item';
    var head = document.createElement('div');
    head.className = 'list__head';
    var what = document.createElement('strong');
    setText(what, accessItemText(req.item) + ' · ' + (req.want_write === true ? '편집' : '보기'));
    head.appendChild(what);
    head.appendChild(accessStatusBadge(req.status));
    li.appendChild(head);
    var body = document.createElement('div');
    body.className = 'list__body';
    var when = document.createElement('span');
    setWhen(when, req.decided_at || req.created_at);
    body.appendChild(when);
    var item = req.item || {};
    if (req.status === 'approved' && item.id !== null && item.id !== undefined) {
      var open = document.createElement('button');
      open.type = 'button';
      open.className = 'btn btn--quiet btn--small';
      setText(open, '열기');
      open.addEventListener('click', function () {
        if (item.kind === 'folder' && typeof item.id === 'number') {
          goToFolder(item.id);
        } else if (UUID_RE.test(String(item.id))) {
          openPreview({ id: String(item.id), name: item.name, access_level: item.access_level });
        }
      });
      body.appendChild(open);
    }
    li.appendChild(body);
    return li;
  }

  function loadAccessRequests() {
    accessFetch('GET', APP_BASE + '/api/access-requests?box=in')
      .then(function (result) {
        if (result === null || !accessInList || !accessInWrap) {
          return;
        }
        if (!result.ok) {
          accessInWrap.hidden = !accessFocusIn;
          setText(accessInMsg, errorMessage(result.data));
          return;
        }
        var reqs = result.data && Array.isArray(result.data.requests) ? result.data.requests : [];
        accessInList.replaceChildren();
        reqs.forEach(function (req) {
          if (req && typeof req.id === 'number') {
            accessInList.appendChild(accessInRow(req));
          }
        });
        if (accessInEmpty) { accessInEmpty.hidden = reqs.length !== 0; }
        accessInWrap.hidden = state.view !== 'shared' || (reqs.length === 0 && !accessFocusIn);
        paintAccessRequestsNav(result.data && typeof result.data.pending === 'number' ? result.data.pending : 0, false);
        if (accessFocusIn && accessInTitle && typeof accessInTitle.focus === 'function') {
          accessFocusIn = false;
          accessInTitle.focus();
        }
      })
      .catch(function () {
        setText(accessInMsg, GENERIC_ERROR);
      });
    accessFetch('GET', APP_BASE + '/api/access-requests?box=out')
      .then(function (result) {
        if (result === null || !result.ok || !accessOutList || !accessOutWrap) {
          return;
        }
        var reqs = result.data && Array.isArray(result.data.requests) ? result.data.requests : [];
        accessOutList.replaceChildren();
        reqs.forEach(function (req) {
          if (req && typeof req.id === 'number') {
            accessOutList.appendChild(accessOutRow(req));
          }
        });
        if (accessOutEmpty) { accessOutEmpty.hidden = reqs.length !== 0; }
        accessOutWrap.hidden = state.view !== 'shared' || reqs.length === 0;
      })
      .catch(function () {});
  }

  function decideAccess(id, decision) {
    setText(accessInMsg, '');
    accessFetch('POST', APP_BASE + '/api/access-requests/' + encodeURIComponent(String(id)), { decision: decision })
      .then(function (result) {
        if (result === null) {
          return;
        }
        if (!result.ok) {
          setText(accessInMsg, errorMessage(result.data));
        }
        loadAccessRequests();
      })
      .catch(function () {
        setText(accessInMsg, GENERIC_ERROR);
      });
  }

  /** 주소로 연 파일(`?file=`) — 볼 수 있으면 미리보기, 아니면(없는 파일도) 요청 화면. 묻는 창구는 권한 줄 보기(감사 0 · 이름만) */
  function openFileByAddress(id) {
    accessFetch('GET', APP_BASE + '/api/files/' + encodeURIComponent(id) + '/grants')
      .then(function (result) {
        if (result === null) {
          return;
        }
        if (!result.ok) {
          showAccessRequest('file', id);
          return;
        }
        var item = result.data && result.data.item ? result.data.item : {};
        openPreview({ id: id, name: typeof item.name === 'string' ? item.name : '', access_level: item.access_level });
      })
      .catch(function () {
        setText(msg, GENERIC_ERROR);
      });
  }

  /** 주소 꼬리표 넷 — `?folder=번호` · `?file=UUID` · `?requests=in|out` · `?view=shared`. 읽은 뒤 주소에서 지운다(새로 고침에 다시 열리지 않게) */
  function readAccessAddress() {
    var out = { file: null, requests: null };
    var params;
    try {
      params = new URLSearchParams(window.location.search);
    } catch (e) {
      return out;
    }
    var folderParam = params.get('folder');
    var fileParam = params.get('file');
    var reqParam = params.get('requests');
    if (reqParam === null && params.get('view') === 'shared') {
      reqParam = 'out';   // 사이드바 「공유받음」 — 받은 요청은 있을 때만 보인다
    }
    if (folderParam !== null && /^[1-9][0-9]{0,8}$/.test(folderParam)) {
      state.folder = parseInt(folderParam, 10);
    }
    if (fileParam !== null && UUID_RE.test(fileParam)) {
      out.file = fileParam;
    }
    if (reqParam === 'in' || reqParam === 'out') {
      out.requests = reqParam;
    }
    if ((folderParam !== null || fileParam !== null || reqParam !== null || params.get('view') !== null) && params.get('q') === null
        && window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState(null, '', window.location.pathname);
    }
    return out;
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
    return m ? m[1] : 'moa.zip';
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
    syncFolderAccess();   // G-0 — 개인 폴더의 「권한」 · 보기 권한만일 때의 한 줄
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
    var stateText = document.createElement('span');
    setText(stateText, '대기');
    stateCell.appendChild(stateText);
    var progress = document.createElement('progress');
    progress.setAttribute('aria-label', '파일 전송 진행');
    progress.hidden = true;
    stateCell.appendChild(progress);
    row.appendChild(stateCell);
    uploadStatusBody.appendChild(row);
    return {
      row: row,
      set: function (text, cls) {
        // 문구는 **서버가 준 것 또는 고정 문구**뿐이고 언제나 textContent 다 (PRD S-03).
        setText(stateText, text);
        stateCell.className = cls ? 'upload-status__state ' + cls : 'upload-status__state';
        if (cls !== 'is-busy') { progress.hidden = true; }
      },
      transfer: function (value) {
        progress.hidden = false;
        if (value.stage === 'transferred') {
          progress.removeAttribute('value');
          setText(stateText, '전송 완료 · 서버 확인 중…');
          progress.setAttribute('aria-valuetext', '서버 확인 중');
        } else if (value.total > 0) {
          progress.max = value.total; progress.value = value.loaded;
          var pct = Math.min(100, Math.floor(value.loaded / value.total * 100));
          setText(stateText, '전송 중 · ' + pct + '%');
          progress.setAttribute('aria-valuetext', '전송 ' + pct + '%');
        } else {
          progress.removeAttribute('value');
          setText(stateText, '전송 중…');
          progress.setAttribute('aria-valuetext', '전송 중');
        }
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
  function uploadOne(file, common, exec, notify) {
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
    }, function (url, options) { return sendFileUpload(url, options, notify); }).then(function (response) {
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

      uploadOne(file, common, exec, setter.transfer)
        .then(function (result) {
          if (result.status === 401) {
            setter.set('중단 — 로그인이 필요합니다', 'is-deny');
            toLogin();
            return;
          }
          if (result.status === 201) {
            setter.set('올림 완료 · 검사 상태는 목록에서 확인', 'is-ok');
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
        .catch(function (error) {
          var message = error && error.uploadUncertain ? error.message : GENERIC_ERROR;
          setter.set(message, 'is-deny');
          setText(note, message);
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
      if (levelSelect) { showFieldError(levelSelect, LEVEL_PLACEHOLDER); levelSelect.focus(); }
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

      uploadOne(queue[i], common, null, setters[i].transfer)
        .then(function (result) {
          if (result.status === 401) {
            setters[i].set('중단 — 로그인이 필요합니다', 'is-deny');
            stopped = true;
            toLogin();
            return;
          }
          if (result.status === 201) {
            setters[i].set('올림 완료 · 검사 상태는 목록에서 확인', 'is-ok');
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
        .catch(function (error) {
          allOk = false;
          setters[i].set(error && error.uploadUncertain ? error.message : GENERIC_ERROR, 'is-deny');
        })
        .then(function () {
          step();
        });
    }

    step();
  }

  if (qInput) { qInput.addEventListener('input', cancelFileSearch); }
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

  /* G-0 — 권한 창·요청 화면·공유받음의 단추들과 주소 꼬리표(`?folder=` · `?file=` · `?requests=`). 꼬리표는 첫 목록을 부르기 **전에**
     읽는다(연 폴더를 그 번호로). 볼 수 없으면 요청 화면이 뜬다(없는 번호도 같은 화면 — 서버가 같은 403 을 준다). */
  if (folderAccessBtn) {
    folderAccessBtn.addEventListener('click', function () {
      var info = state.folderInfo;
      openGrants('folder', String(state.folder), info && typeof info.name === 'string' ? info.name : '');
    });
  }
  if (folderPutRequest) {
    folderPutRequest.addEventListener('click', function () {
      if (state.folder !== 0) {
        clearRows();
        showAccessRequest('folder', String(state.folder), true);
      }
    });
  }
  if (grantsClose) {
    grantsClose.addEventListener('click', closeGrants);
  }
  if (grantsCloseX) {
    grantsCloseX.addEventListener('click', closeGrants);
  }
  if (grantsDialog) {
    grantsDialog.addEventListener('close', function () {
      grantsTarget = null;
      window.clearTimeout(grantsTimer);
    });
  }
  if (grantsSearch) {
    grantsSearch.addEventListener('input', function () {
      window.clearTimeout(grantsTimer);
      grantsTimer = window.setTimeout(loadGrantCandidates, 300);
    });
  }
  if (accessForm) {
    accessForm.addEventListener('submit', submitAccessRequest);
  }
  var accessAddress = readAccessAddress();

  syncTeamField();
  syncFetchTeamField();
  loadTeams();
  if (accessAddress.requests !== null) {
    accessFocusIn = accessAddress.requests === 'in';
    setView('shared');
  } else {
    loadFiles();
  }
  loadTree();
  if (accessAddress.file !== null) {
    openFileByAddress(accessAddress.file);
  }
  // 개발 대기열 6 — 별 표시용 즐겨찾기 목록(표를 먼저 그리고, 목록이 오면 별만 맞춘다)
  loadFavoriteLists()
    .then(function (lists) {
      if (lists !== null) {
        syncStars();
      }
    })
    .catch(function () {});
}


/** Same-origin multipart upload with actual byte progress; retries remain in writeFetch. */
export function sendFileUpload(url, options, notify) {
  return new Promise(function (resolve, reject) {
    var xhr = new XMLHttpRequest();
    xhr.open(options.method || 'POST', url, true);
    xhr.withCredentials = options.credentials === 'same-origin';
    // Keep the existing upload timeout policy; large transfers must not get a new client cutoff.
    Object.keys(options.headers || {}).forEach(function (name) { xhr.setRequestHeader(name, options.headers[name]); });
    var last = -1;
    xhr.upload.addEventListener('progress', function (event) {
      var pct = event.lengthComputable && event.total > 0 ? Math.floor(event.loaded / event.total * 100) : -1;
      if (notify && (pct !== last || pct === -1)) {
        last = pct;
        notify({stage:'transferring',loaded:event.loaded,total:event.lengthComputable ? event.total : 0});
      }
    });
    xhr.upload.addEventListener('load', function () { if (notify) { notify({stage:'transferred',loaded:0,total:0}); } });
    xhr.addEventListener('load', function () {
      if (xhr.status === 0) { failed(); return; }
      try {
        var headers = new Headers();
        xhr.getAllResponseHeaders().trim().split(/[\r\n]+/).forEach(function (line) {
          var colon = line.indexOf(':'); if (colon > 0) { headers.append(line.slice(0, colon), line.slice(colon + 1).trim()); }
        });
        var payload = [204, 205, 304].indexOf(xhr.status) !== -1 ? null : xhr.responseText;
        resolve(new Response(payload, {status:xhr.status,statusText:xhr.statusText,headers:headers}));
      } catch (_) { failed(); }
    });
    function failed() {
      // The server may have stored the upload before the response was lost. Never retry it automatically.
      var error = new Error('전송 결과를 확인하지 못했어요. 목록에서 파일을 확인한 뒤 다시 선택해 주세요.');
      error.uploadUncertain = true; reject(error);
    }
    xhr.addEventListener('error', failed);xhr.addEventListener('abort', failed);xhr.addEventListener('timeout', failed);
    xhr.send(options.body);
  });
}
