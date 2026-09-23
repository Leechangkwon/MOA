/*
 * public/assets/community/search.js — 커뮤니티 검색 창 (5-B, 사용자 결정 2026-09-23)
 *
 * 사이드바 맨 위 「검색」 단추 또는 Ctrl+K(맥 ⌘K)로 연다. 치는 대로 `GET /community/api/search?q=`
 * 를 불러(200ms 쉬고) 결과를 그린다 — 제목 · 경로(공간 › 상위) · 찾은 문장 조각(찾은 글자 강조).
 * ↑↓ 로 고르고 Enter 로 가고 Esc 로 닫는다(<dialog> 가 스스로 한다).
 *
 * 규칙
 *   · **무엇이 나오나는 서버가 정한다** — 이 파일은 받은 것만 그린다(행 관문·관리자 특권 갈래 끔,
 *     `src/community/handlers/search.php` 머리글). 화면에 권한 판단이 없다 (PRD S-01).
 *   · 서버 글자는 전부 `textContent` 로만 넣는다. 강조도 문자열을 HTML 로 바꾸지 않고
 *     글자를 나눠 `<mark>` 요소에 `textContent` 로 담는다 (PRD S-03 — HTML 문자열을 요소로 바꾸는 API 를 쓰지 않는다).
 *   · 인라인 style 0. 모양은 community.css 의 `.search-*` (DESIGN 2.14).
 *   · editor.js 와 따로 둔다 — 편집기가 없는 화면(홈)에서도 같은 창이다.
 */
(function () {
  'use strict';

  var body = document.body;
  var BASE = body ? (body.getAttribute('data-community-base') || '') : '';
  var dialog = document.getElementById('search-dialog');
  var input = document.getElementById('search-input');
  var list = document.getElementById('search-results');
  var note = document.getElementById('search-note');
  var opener = document.getElementById('nav-community-search');
  if (!BASE || !dialog || !input || !list || !note) { return; }

  // 창은 사이드바 안에 그려져 온다 — 좁은 화면에서 사이드바가 숨으면 그 안의 창도 보이지 않으므로
  // 본문 끝으로 옮겨 둔다(같은 요소를 옮길 뿐 새로 만들지 않는다).
  body.appendChild(dialog);
  var key = document.querySelector('.nav-search__key');
  if (key && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '')) { key.textContent = '⌘K'; }

  var timer = 0;
  var serial = 0;
  var items = [];
  var index = -1;

  function clear(el) { while (el.firstChild) { el.removeChild(el.firstChild); } }

  /** 찾은 글자를 강조한 조각 — 대소문자를 가리지 않고 모든 자리 */
  function highlighted(text, q) {
    var frag = document.createDocumentFragment();
    var hay = text.toLowerCase();
    var needle = q.toLowerCase();
    var at = 0;
    if (needle === '') { frag.appendChild(document.createTextNode(text)); return frag; }
    for (;;) {
      var hit = hay.indexOf(needle, at);
      if (hit < 0) { break; }
      if (hit > at) { frag.appendChild(document.createTextNode(text.slice(at, hit))); }
      var mark = document.createElement('mark');
      mark.textContent = text.slice(hit, hit + needle.length);
      frag.appendChild(mark);
      at = hit + needle.length;
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

  function draw(results, q) {
    clear(list);
    items = [];
    index = -1;
    for (var i = 0; i < results.length; i++) {
      var r = results[i];
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      var a = document.createElement('a');
      a.className = 'search-hit';
      a.setAttribute('href', BASE + '/p/' + r.id);
      var title = document.createElement('span');
      title.className = 'search-hit__title';
      title.appendChild(highlighted((r.icon ? r.icon + ' ' : '') + r.title, q));
      var path = document.createElement('span');
      path.className = 'search-hit__path';
      var trail = [r.space && r.space.name ? r.space.name : ''];
      for (var j = 0; j < (r.path || []).length; j++) { trail.push(r.path[j].title); }
      path.textContent = trail.filter(function (t) { return t !== ''; }).join(' › ');
      a.appendChild(title);
      a.appendChild(path);
      if (r.snippet) {
        var snip = document.createElement('span');
        snip.className = 'search-hit__snippet';
        snip.appendChild(highlighted(r.snippet, q));
        a.appendChild(snip);
      }
      li.appendChild(a);
      list.appendChild(li);
      items.push(li);
      (function (n) { li.addEventListener('mousemove', function () { if (index !== n) { select(n); } }); })(i);
    }
    if (results.length > 0) { select(0); }
  }

  function run() {
    var q = input.value.trim();
    var mine = ++serial;
    if (q === '') {
      clear(list); items = []; index = -1;
      note.textContent = '제목이나 본문에 든 말을 입력하세요. 내가 볼 수 있는 페이지에서만 찾습니다.';
      return;
    }
    note.textContent = '찾는 중…';
    fetch(BASE + '/api/search?q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (res) {
        if (res.status === 401) { window.location.reload(); return null; }
        return res.ok ? res.json() : null;
      })
      .then(function (data) {
        if (mine !== serial) { return; }   // 그 사이 더 쳤다 — 늦게 온 답은 버린다
        var results = data && Array.isArray(data.results) ? data.results : [];
        draw(results, q);
        note.textContent = results.length === 0
          ? '「' + q + '」 이(가) 든 페이지가 없습니다.'
          : '페이지 ' + String(results.length) + '개' + (results.length >= 20 ? ' (앞의 20개)' : '');
      })
      .catch(function () { if (mine === serial) { note.textContent = '찾지 못했습니다. 잠시 뒤 다시 해 보세요.'; } });
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
    timer = window.setTimeout(run, 200);
  });
  input.addEventListener('keydown', function (ev) {
    // `type=search` 칸에서 Esc 는 브라우저가 「지우기」로 먼저 써서 창이 닫히지 않았다(2026-09-23 실측) — 직접 닫는다
    if (ev.key === 'Escape') { ev.preventDefault(); dialog.close(); return; }
    if (ev.key === 'ArrowDown') { ev.preventDefault(); select(index + 1); return; }
    if (ev.key === 'ArrowUp') { ev.preventDefault(); select(index - 1); return; }
    if (ev.key === 'Enter' && index >= 0) {
      ev.preventDefault();
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
