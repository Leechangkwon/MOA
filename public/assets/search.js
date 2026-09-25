/*
 * public/assets/search.js — 통합 검색 창 (두 앱 공통 — 사이트 PRD Q:F-2, 지시서 docs/qf2-unified-search-directive.md 2.5)
 *
 * 전에는 커뮤니티 안의 페이지 검색이었다(public/assets/community/search.js — 5-B). 이제 t-box 셸에도 실려
 * 사이드바 「검색」 단추나 Ctrl+K(맥 ⌘K)로 연다. 치고 **250ms** 멈추면 두 창구를 **동시에** 부른다:
 *   · 커뮤니티 `GET /community/api/search?q=` — 페이지 · 댓글 두 묶음
 *   · t-box `GET /tbox/api/files?q=`(folder 없이 = 전역 검색, T:F-26) — 파일 묶음
 * 한쪽이 401·403 이면(Guest 등 — 각 앱의 판정이 정한다) 그 묶음만 조용히 뺀다. 둘 다 401 이면 로그인이 끝난 것 — 새로 고친다.
 * 먼저 온 창구의 묶음부터 그리고, 늦은 쪽이 오면 더한다(안내 줄 「더 찾는 중…」 — 고른 줄은 그대로 둔다).
 * 묶음마다 처음 5개, 「더 보기」로 받은 만큼(최대 20). ↑↓ 로 고르고 Enter 로 가고 Esc 로 닫는다.
 *   · 페이지 → 그 페이지 · 댓글 → 그 페이지의 그 댓글(`#comment-<id>` — 알림과 같은 자리 이동)
 *   · 파일 → t-box 목록이 그 검색어로 열린다(`/tbox/?q=` — app.js 가 검색 칸에 넣고 전역 검색).
 * 검색어는 `normalize('NFC')` 뒤 보낸다 — 맥에서 친 자모가 음절로 합쳐진다(서버도 한 번 더 합친다).
 *
 * 규칙
 *   · **무엇이 나오나는 서버가 정한다** — 이 파일은 받은 것만 그린다(화면에 권한 판단 0, PRD S-01).
 *     전체 개수는 받지도 보이지도 않는다(지시서 2.6) — 「앞의 20개」까지만 말한다.
 *   · 서버 글자는 전부 `textContent` 로만 넣는다. 강조도 문자열을 HTML 로 바꾸지 않고
 *     글자를 나눠 `<mark>` 요소에 `textContent` 로 담는다 (PRD S-03 — HTML 문자열을 요소로 바꾸는 API 를 쓰지 않는다).
 *   · 인라인 style 0. 모양은 app.css 의 `.search-*`·`.nav-search`(DESIGN 2.14 — 두 앱이 같이 쓰게 옮겼다),
 *     묶음 제목은 `.nav-title`, 「더 보기」는 `.btn.btn--small.btn--quiet` — 새 클래스·색값 0.
 *   · 주소는 서버가 알려 준 두 기준(`<body data-base>` · 창의 `data-community-base`)에 붙인다 — 절대 주소를 짓지 않는다.
 */
(function () {
  'use strict';

  var body = document.body;
  var dialog = document.getElementById('search-dialog');
  var input = document.getElementById('search-input');
  var list = document.getElementById('search-results');
  var note = document.getElementById('search-note');
  var opener = document.getElementById('nav-search');
  if (!body || !dialog || !input || !list || !note) { return; }
  var TBOX = body.getAttribute('data-base') || '';
  var COMM = dialog.getAttribute('data-community-base') || '';
  if (!TBOX || !COMM) { return; }

  var SHOW = 5;          // 묶음마다 처음 보이는 수
  var WAIT = 250;        // 입력 멈춤(ms)
  var LABEL = { pages: '페이지', comments: '댓글', files: '파일' };

  // 창은 사이드바 안에 그려져 온다 — 좁은 화면에서 사이드바가 숨으면 그 안의 창도 보이지 않으므로
  // 본문 끝으로 옮겨 둔다(같은 요소를 옮길 뿐 새로 만들지 않는다).
  body.appendChild(dialog);
  var key = document.querySelector('.nav-search__key');
  if (key && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '')) { key.textContent = '⌘K'; }

  var timer = 0;
  var serial = 0;
  var items = [];        // 고를 수 있는 줄(li) — 결과와 「더 보기」
  var index = -1;
  var last = null;       // 마지막으로 그린 답 {q, terms, groups}
  var expanded = {};     // 「더 보기」를 누른 묶음

  function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }

  /** 5-A″ 「이름 (아이디)」 — 모양은 한 벌(app.js 의 window.qarediPersonLabel). 없으면 아이디만 — 여기서 다시 짓지 않는다 */
  function person(a) {
    var u = a && a.username !== undefined && a.username !== null ? String(a.username) : '';
    var shared = typeof window.qarediPersonLabel === 'function' ? window.qarediPersonLabel : null;
    return shared ? shared(a ? a.display_name : null, u) : u;
  }

  /** 찾은 낱말을 강조한 조각 — 낱말마다, 대소문자를 가리지 않고 모든 자리 */
  function highlighted(text, terms) {
    var frag = document.createDocumentFragment();
    var hay = text.toLowerCase();
    var at = 0;
    var needles = terms.map(function (t) { return t.toLowerCase(); }).filter(function (t) { return t !== ''; });
    for (;;) {
      var hit = -1;
      var len = 0;
      for (var k = 0; k < needles.length; k++) {
        var h = hay.indexOf(needles[k], at);
        if (h >= 0 && (hit < 0 || h < hit || (h === hit && needles[k].length > len))) { hit = h; len = needles[k].length; }
      }
      if (hit < 0) { break; }
      if (hit > at) { frag.appendChild(document.createTextNode(text.slice(at, hit))); }
      var mark = document.createElement('mark');
      mark.textContent = text.slice(hit, hit + len);
      frag.appendChild(mark);
      at = hit + len;
    }
    if (at < text.length) { frag.appendChild(document.createTextNode(text.slice(at))); }
    return frag;
  }

  function select(i) {
    if (items.length === 0) { index = -1; return; }
    index = ((i % items.length) + items.length) % items.length;
    for (var k = 0; k < items.length; k++) {
      var on = k === index;
      items[k].classList.toggle('is-active', on);
      items[k].setAttribute('aria-selected', on ? 'true' : 'false');
    }
    items[index].scrollIntoView({ block: 'nearest' });
  }

  /** 결과 한 줄 — 제목 · 경로(작은 글) · 조각(있으면) */
  function hitRow(href, titleText, pathText, snippetText, terms) {
    var li = document.createElement('li');
    li.setAttribute('role', 'option');
    li.setAttribute('aria-selected', 'false');
    var a = document.createElement('a');
    a.className = 'search-hit';
    a.setAttribute('href', href);
    var title = document.createElement('span');
    title.className = 'search-hit__title';
    title.appendChild(highlighted(titleText, terms));
    var path = document.createElement('span');
    path.className = 'search-hit__path';
    path.textContent = pathText;
    a.appendChild(title);
    a.appendChild(path);
    if (snippetText) {
      var snip = document.createElement('span');
      snip.className = 'search-hit__snippet';
      snip.appendChild(highlighted(snippetText, terms));
      a.appendChild(snip);
    }
    li.appendChild(a);
    return li;
  }

  function rowsFor(kind, rows, q, terms) {
    var out = [];
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      if (kind === 'pages') {
        var trail = [r.space && r.space.name ? r.space.name : ''];
        for (var j = 0; j < (r.path || []).length; j++) { trail.push(r.path[j].title); }
        out.push(hitRow(COMM + '/p/' + encodeURIComponent(r.id), (r.icon ? r.icon + ' ' : '') + r.title,
          trail.filter(function (t) { return t !== ''; }).join(' › '), r.snippet || '', terms));
      } else if (kind === 'comments') {
        var pg = r.page || {};
        var where = [pg.space && pg.space.name ? pg.space.name : '', '댓글 · ' + person(r.author)];
        out.push(hitRow(COMM + '/p/' + encodeURIComponent(pg.id || '') + '#comment-' + encodeURIComponent(r.id),
          (pg.icon ? pg.icon + ' ' : '') + String(pg.title || ''),
          where.filter(function (t) { return t !== ''; }).join(' › '), r.snippet || '', terms));
      } else {
        // 파일 — 누르면 t-box 목록이 그 검색어로 열린다(파일 하나를 바로 여는 창구를 새로 두지 않는다)
        var info = ['t-box'];
        if (r.folder_path) { info.push(String(r.folder_path)); }
        out.push(hitRow(TBOX + '/?q=' + encodeURIComponent(q), String(r.name || ''),
          info.join(' › '), r.description ? String(r.description) : '', terms));
      }
    }
    return out;
  }

  function draw() {
    clear(list);
    items = [];
    index = -1;
    if (!last) { return; }
    var order = ['pages', 'comments', 'files'];
    for (var g = 0; g < order.length; g++) {
      var kind = order[g];
      var rows = last.groups[kind];
      if (!rows || rows.length === 0) { continue; }
      var head = document.createElement('li');
      head.setAttribute('role', 'presentation');
      head.className = 'nav-title';
      head.textContent = LABEL[kind] + (rows.length >= 20 ? ' (앞의 20개)' : '');
      list.appendChild(head);
      var built = rowsFor(kind, rows, last.q, last.terms);
      var shown = expanded[kind] ? built.length : Math.min(SHOW, built.length);
      for (var i = 0; i < shown; i++) {
        list.appendChild(built[i]);
        items.push(built[i]);
      }
      if (shown < built.length) {
        var more = document.createElement('li');
        more.setAttribute('role', 'option');
        more.setAttribute('aria-selected', 'false');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn--small btn--quiet';
        btn.textContent = LABEL[kind] + ' 더 보기 (' + String(built.length - shown) + ')';
        (function (k) {
          btn.addEventListener('click', function () {
            expanded[k] = true;
            var keep = items.length;
            draw();
            select(Math.min(keep, items.length - 1));
            input.focus();
          });
        })(kind);
        more.appendChild(btn);
        list.appendChild(more);
        items.push(more);
      }
    }
    for (var n = 0; n < items.length; n++) {
      (function (x) { items[x].addEventListener('mousemove', function () { if (index !== x) { select(x); } }); })(n);
    }
    if (items.length > 0) { select(0); }
  }

  /** 한 창구를 부른다 — 결과 배열, 권한 없음(401·403)은 'denied', 그 밖의 실패는 null */
  function ask(url, pick) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        if (res.status === 401) { return { status: 401 }; }
        if (res.status === 403) { return { status: 403 }; }
        if (!res.ok) { return { status: res.status }; }
        return res.json().then(function (d) { return { status: 200, data: pick(d) }; });
      })
      .catch(function () { return { status: 0 }; });
  }

  function run() {
    var q = input.value.normalize ? input.value.normalize('NFC').trim() : input.value.trim();
    var mine = ++serial;
    expanded = {};
    if (q === '') {
      last = null;
      draw();
      note.textContent = '페이지 제목·본문, 댓글, t-box 파일 이름·설명에서 찾습니다. 내가 볼 수 있는 것만 나옵니다.';
      return;
    }
    note.textContent = '찾는 중…';
    var enc = encodeURIComponent(q);
    // 두 창구는 따로 도착한다 — 먼저 온 묶음부터 그린다(늦은 쪽을 기다리느라 첫 결과가 늦지 않게). 앞 검색어의 결과는
    // 이 검색어의 첫 답이 올 때까지 그대로 둔다(깜빡이지 않게).
    var cur = { q: q, terms: q.split(/\s+/).filter(function (t) { return t !== ''; }), groups: { pages: [], comments: [], files: [] } };
    var st = { c: -1, f: -1 };   // -1 = 아직 · 0 = 연결 실패 · 그 밖 = HTTP 상태
    var shown = false;
    function settle() {
      if (mine !== serial) { return; }   // 그 사이 더 쳤다 — 늦게 온 답은 버린다
      if (st.c === 401 && st.f === 401) { window.location.reload(); return; }
      var keep = shown ? index : -1;
      last = cur;
      draw();
      if (keep >= 0 && items.length > 0) { select(Math.min(keep, items.length - 1)); }
      shown = true;
      var g = cur.groups;
      var waiting = st.c === -1 || st.f === -1;
      var failed = (st.c !== -1 && st.c !== 200 && st.c !== 401 && st.c !== 403)
        || (st.f !== -1 && st.f !== 200 && st.f !== 401 && st.f !== 403);
      var n = g.pages.length + g.comments.length + g.files.length;
      var parts = [];
      if (g.pages.length) { parts.push('페이지 ' + String(g.pages.length)); }
      if (g.comments.length) { parts.push('댓글 ' + String(g.comments.length)); }
      if (g.files.length) { parts.push('파일 ' + String(g.files.length)); }
      if (waiting) {
        note.textContent = (parts.length ? parts.join(' · ') + ' — ' : '') + '더 찾는 중…';
      } else if (n === 0) {
        note.textContent = failed ? '찾지 못했습니다. 잠시 뒤 다시 해 보세요.' : '「' + q + '」 이(가) 든 페이지·댓글·파일이 없습니다.';
      } else {
        note.textContent = parts.join(' · ') + (failed ? ' — 일부는 찾지 못했습니다' : '');
      }
    }
    ask(COMM + '/api/search?q=' + enc, function (d) {
      return { pages: Array.isArray(d.results) ? d.results : [], comments: Array.isArray(d.comments) ? d.comments : [] };
    }).then(function (r) {
      st.c = r.status;
      if (r.status === 200) { cur.groups.pages = r.data.pages; cur.groups.comments = r.data.comments; }
      settle();
    });
    ask(TBOX + '/api/files?q=' + enc, function (d) { return Array.isArray(d.files) ? d.files : []; })
      .then(function (r) {
        st.f = r.status;
        if (r.status === 200) { cur.groups.files = r.data; }
        settle();
      });
  }

  function open() {
    if (typeof dialog.showModal !== 'function') { return; }
    if (!dialog.open) { dialog.showModal(); }
    input.focus();
    input.select();
    run();
  }

  input.addEventListener('input', function () {
    window.clearTimeout(timer);
    timer = window.setTimeout(run, WAIT);
  });
  input.addEventListener('keydown', function (ev) {
    // `type=search` 칸에서 Esc 는 브라우저가 「지우기」로 먼저 써서 창이 닫히지 않았다(2026-09-23 실측) — 직접 닫는다
    if (ev.key === 'Escape') { ev.preventDefault(); dialog.close(); return; }
    if (ev.key === 'ArrowDown') { ev.preventDefault(); select(index + 1); return; }
    if (ev.key === 'ArrowUp') { ev.preventDefault(); select(index - 1); return; }
    if (ev.key === 'Enter' && index >= 0) {
      ev.preventDefault();
      var btn = items[index].querySelector('button');
      if (btn) { btn.click(); return; }
      var a = items[index].querySelector('a');
      if (a) { window.location.assign(a.getAttribute('href')); }
    }
  });
  if (opener) { opener.addEventListener('click', open); }
  var closeBtn = dialog.querySelector('[data-search-close]');
  if (closeBtn) { closeBtn.addEventListener('click', function () { dialog.close(); }); }
  // Ctrl+K / ⌘K — 어느 칸에 있든 연다(편집기 안에서도). 이미 열렸으면 입력 칸으로.
  document.addEventListener('keydown', function (ev) {
    if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && !ev.shiftKey && (ev.key === 'k' || ev.key === 'K')) {
      ev.preventDefault();
      open();
    }
  });
})();
