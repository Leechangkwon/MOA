/* Qaredi design study: in-memory samples, no production requests. */
(() => {
  'use strict';
  const root = document.documentElement;
  const main = document.getElementById('preview-main');
  const names = {home: 'Qaredi 홈', files: 't-box 파일', community: '커뮤니티', document: '문서 읽기', settings: '디자인 설정', login: '로그인 화면', components: '디자인 시스템'};
  const icons = {
    grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
    folder: 'M3 7V5a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z',
    layers: 'm12 3 10 5-10 5L2 8l10-5ZM2 12l10 5 10-5M2 16l10 5 10-5',
    palette: 'M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-4c-1-1 0-2 1-2h2a4 4 0 0 0 4-4c0-5-4-8-9-8ZM7 9h.01M11 6h.01M16 7h.01M6 14h.01',
    settings: 'm9 3-1 3-3 1-2 4 2 2v3l3 2 1 3h6l1-3 3-2v-3l2-2-2-4-3-1-1-3H9ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    search: 'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
    arrow: 'M4 12h16m-6-6 6 6-6 6',
    'arrow-up': 'M6 18 18 6M6 6h12v12',
    'arrow-left': 'M20 12H4m6-6-6 6 6 6',
    moon: 'M21 13a9 9 0 0 1-10-10A9 9 0 1 0 21 13Z',
    sun: 'M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1 1M18 18l1 1M5 19l1-1M18 6l1-1M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    pin: 'm8 3 8 0-1 6 4 4v2H5v-2l4-4-1-6ZM12 15v7',
    shield: 'm12 2 8 3v6c0 5-5 9-8 11-3-2-8-6-8-11V5l8-3Zm-4 10 3 3 5-6',
    document: 'M6 3h12a2 2 0 0 1 2 2v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a2 2 0 0 1 2-2ZM8 8h8M8 12h8M8 16h5',
    file: 'M14 2H5v20h14V7l-5-5ZM14 2v6h5M8 13h8M8 17h5',
    upload: 'M12 16V3m-5 5 5-5 5 5M4 16v5h16v-5',
    users: 'M16 21v-2a5 5 0 0 0-5-5H7a5 5 0 0 0-5 5v2M13 6a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM16 3a4 4 0 0 1 0 8M18 14a5 5 0 0 1 4 5v2',
    lock: 'M6 10h12a1 1 0 0 1 1 1v10H5V11a1 1 0 0 1 1-1ZM8 10V6a4 4 0 0 1 8 0v4M12 14v3',
    spark: 'm12 2 3 7 7 3-7 3-3 7-3-7-7-3 7-3 3-7Z',
    plus: 'M12 5v14M5 12h14',
    close: 'm6 6 12 12M18 6 6 18',
    check: 'm5 12 4 4L19 6',
    heart: 'M20 4c-3-2-6 0-8 2-2-2-5-4-8-2-5 4 0 10 8 16 8-6 13-12 8-16Z',
    edit: 'm16 3 5 5-12 12H4v-5L16 3ZM13 6l5 5',
    eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Zm13 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
  };
  function paintIcons(scope = document) {
    scope.querySelectorAll('[data-icon]').forEach(el => {
      const d = icons[el.dataset.icon];
      if (!d || el.firstChild) return;
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      for (const [key, value] of Object.entries({viewBox:'0 0 24 24',fill:'none',stroke:'currentColor','stroke-width':'1.7','stroke-linecap':'round','stroke-linejoin':'round','aria-hidden':'true'})) svg.setAttribute(key, value);
      path.setAttribute('d', d); svg.append(path); el.append(svg);
    });
  }
  function node(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  }
  function icon(name) { const el = node('span'); el.dataset.icon = name; return el; }
  function read(key, fallback) { try { return localStorage.getItem('qaredi.preview.' + key) ?? fallback; } catch { return fallback; } }
  function save(prefs) {
    try { Object.entries(prefs).forEach(([key,value]) => localStorage.setItem('qaredi.preview.' + key, value)); return true; }
    catch { return false; }
  }
  const validDesign = value => ['claude', 'astra'].includes(value) ? value : 'claude';
  const designLabel = value => value === 'astra' ? 'Astra' : 'Claude';
  let toastTimer;
  function toast(message) {
    const el = document.querySelector('.toast');
    clearTimeout(toastTimer); el.textContent = message; el.hidden = false;
    toastTimer = setTimeout(() => { el.hidden = true; }, 4500);
  }
  function updateChrome() {
    document.querySelectorAll('[data-mode]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mode === root.dataset.design)));
    const themeButton = document.getElementById('theme-toggle');
    themeButton.setAttribute('aria-label', root.dataset.theme === 'dark' ? '밝은 화면으로' : '어두운 화면으로');
    themeButton.replaceChildren(icon(root.dataset.theme === 'dark' ? 'sun' : 'moon'));
    document.getElementById('default-label').textContent = '이 브라우저 기본 · ' + designLabel(validDesign(read('design', 'claude')));
    paintIcons(themeButton);
  }
  function setDesign(value, persistQuery = true) {
    root.dataset.design = validDesign(value);
    if (persistQuery) {
      const url = new URL(location.href); url.searchParams.set('design', root.dataset.design);
      history.replaceState(null, '', url);
    }
    updateChrome();
  }
  function removeDesignQuery() {
    const url = new URL(location.href); url.searchParams.delete('design'); history.replaceState(null, '', url);
  }
  const scheme = matchMedia('(prefers-color-scheme: dark)');
  function setAppearance(appearance) {
    root.dataset.appearance = ['light','dark','system'].includes(appearance) ? appearance : 'system';
    root.dataset.theme = root.dataset.appearance === 'system' ? (scheme.matches ? 'dark' : 'light') : root.dataset.appearance;
    updateChrome();
  }
  scheme.addEventListener('change', () => { if (root.dataset.appearance === 'system') setAppearance('system'); });
  document.querySelectorAll('[data-mode]').forEach(button => button.addEventListener('click', () => setDesign(button.dataset.mode)));
  document.getElementById('save-default').addEventListener('click', () => {
    if (save({design: root.dataset.design})) {
      removeDesignQuery(); updateChrome();
      const selected = main.querySelector('[name="default-design"][value="' + root.dataset.design + '"]');
      if (selected) selected.checked = true;
      toast(designLabel(root.dataset.design) + '를 이 브라우저의 기본 디자인으로 저장했어요.');
    } else toast('브라우저 저장 공간을 사용할 수 없어 기본값을 저장하지 못했어요.');
  });
  document.getElementById('theme-toggle').addEventListener('click', () => {
    const appearance = root.dataset.theme === 'dark' ? 'light' : 'dark'; setAppearance(appearance);
    if (!save({appearance})) toast('화면은 바뀌었지만 이 브라우저에 저장하지 못했어요.');
    const select = main.querySelector('[name="appearance"]'); if (select) select.value = appearance;
  });
  const files = [
    {name:'Qaredi 브랜드 가이드.pdf', scope:'team', type:'PDF 문서', size:'2.4 MB', date:'2026. 09. 30', tint:'', icon:'file'},
    {name:'팀 프로젝트 리소스.zip', scope:'team', type:'압축 파일', size:'18.6 MB', date:'2026. 09. 29', tint:'file-glyph--mint', icon:'folder'},
    {name:'10월 업무 계획.xlsx', scope:'team', type:'스프레드시트', size:'248 KB', date:'2026. 09. 28', tint:'file-glyph--mint', icon:'grid'},
    {name:'새로운 아이디어 모음.txt', scope:'private', type:'텍스트 문서', size:'12 KB', date:'2026. 09. 27', tint:'file-glyph--peach', icon:'document'},
  ];
  const docs = [
    {title:'함께 만드는 우리 팀 가이드', scope:'팀 공간'},
    {title:'다음 프로젝트 아이디어', scope:'내 공간'},
    {title:'주간 미팅 노트', scope:'팀 공간'},
  ];
  let filter = 'all';
  let fileQuery = '';
  let documentIndex = 0;
  let currentScreen = '';
  function renderFiles() {
    const tbody = document.getElementById('file-rows'); if (!tbody) return;
    tbody.replaceChildren();
    const matching = files.filter(file => (filter === 'all' || file.scope === filter) && file.name.toLocaleLowerCase().includes(fileQuery.toLocaleLowerCase()));
    matching.forEach(file => {
      const row = node('tr');
      const nameCell = node('td');
      const nameWrap = node('div', 'file-name');
      const glyph = node('span', 'file-glyph ' + file.tint); glyph.append(icon(file.icon));
      const words = node('span'); words.append(node('strong','',file.name),node('small','',file.type));
      nameWrap.append(glyph,words); nameCell.append(nameWrap);
      const scopeCell = node('td'); scopeCell.append(node('span','badge ' + (file.scope === 'team' ? 'badge--team' : ''),file.scope === 'team' ? '팀 공유' : '비공개'));
      const actions = node('td'); const button = node('button','studio-icon');
      button.type = 'button'; button.setAttribute('aria-label', file.name + ' 미리보기'); button.append(icon('eye'));
      button.addEventListener('click', () => openFile(file)); actions.append(button);
      row.append(nameCell,scopeCell,node('td','',file.size),node('td','',file.date),actions); tbody.append(row);
    });
    document.getElementById('file-empty').hidden = matching.length !== 0;
    document.getElementById('file-result-count').textContent = matching.length + '개 파일';
    document.getElementById('file-count').replaceChildren(document.createTextNode(files.length + ' '),node('small','','개'));
    main.querySelectorAll('[data-filter]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.filter === filter)));
    paintIcons(tbody);
  }
  function renderDocs() {
    const list = document.getElementById('community-rows'); if (!list) return;
    list.replaceChildren(); docs.forEach((doc, index) => {
      const link = node('a', 'document-row'); link.href = '#document';
      const glyph = node('span','file-glyph file-glyph--mint'); glyph.append(icon('document'));
      const words = node('span'); words.append(node('strong','',doc.title),node('small','',doc.scope));
      const arrow = node('span','row-trailing','문서 열기 '); arrow.append(icon('arrow'));
      link.append(glyph,words,arrow); link.addEventListener('click', () => { documentIndex = index; }); list.append(link);
    }); paintIcons(list);
  }
  function renderDocument() {
    const doc = docs[documentIndex] || docs[0];
    document.getElementById('document-title').textContent = doc.title;
    if (doc.body !== undefined) {
      const content = main.querySelector('.article-content');
      const heading = node('h1', '', doc.title); heading.id = 'document-title';
      content.replaceChildren(node('span','badge','내 공간 · 이번 시연에서 만든 문서'),heading);
      doc.body.split('\n').forEach(line => content.append(node('p','',line || '\u00a0')));
      content.append(node('p','muted','이 문서는 메모리에만 보관되며 새로고침하면 사라집니다.'));
    } else if (documentIndex > 0) {
      main.querySelector('.article-description').textContent = '공통 문서 디자인을 보여 주는 예시 본문입니다.';
    }
  }
  function setupSettings() {
    const form = document.getElementById('settings-form');
    form.elements['default-design'].value = validDesign(read('design', 'claude'));
    form.elements.appearance.value = root.dataset.appearance;
    form.elements['reduce-motion'].checked = root.dataset.motion === 'reduce';
    form.elements['high-contrast'].checked = root.dataset.contrast === 'high';
    form.addEventListener('submit', event => {
      event.preventDefault();
      const prefs = {design: validDesign(form.elements['default-design'].value), appearance: form.elements.appearance.value, motion: form.elements['reduce-motion'].checked ? 'reduce' : 'full', contrast: form.elements['high-contrast'].checked ? 'high' : 'normal'};
      const success = save(prefs);
      setDesign(prefs.design, false); setAppearance(prefs.appearance); root.dataset.motion = prefs.motion; root.dataset.contrast = prefs.contrast;
      if (success) removeDesignQuery();
      const message = success ? '저장했어요. 다음에도 이 디자인으로 만나요.' : '현재 화면에만 적용했어요. 브라우저 저장 공간을 사용할 수 없습니다.';
      document.getElementById('settings-state').textContent = message; toast(message); updateChrome();
    });
  }
  function renderScreen(focus = false) {
    const requested = location.hash.slice(1) || 'home';
    const screen = Object.hasOwn(names, requested) ? requested : 'home';
    if (screen === 'document' && currentScreen !== 'community' && currentScreen !== 'home') documentIndex = Math.min(documentIndex, docs.length - 1);
    currentScreen = screen;
    main.replaceChildren(document.getElementById('screen-' + screen).content.cloneNode(true));
    document.getElementById('breadcrumb').textContent = names[screen];
    document.title = names[screen] + ' · Qaredi 디자인 스튜디오';
    document.querySelectorAll('[data-nav]').forEach(link => {
      if (link.dataset.nav === (screen === 'document' ? 'community' : screen)) link.setAttribute('aria-current','page');
      else link.removeAttribute('aria-current');
    });
    if (screen === 'files') { renderFiles(); document.getElementById('file-search').value = fileQuery; }
    if (screen === 'community') {
      renderDocs();
      main.querySelectorAll('.document-card').forEach((link,index) => link.addEventListener('click', () => { documentIndex = index; }));
    }
    if (screen === 'home') main.querySelector('[href="#document"]').addEventListener('click', () => { documentIndex = 0; });
    if (screen === 'document') renderDocument();
    if (screen === 'settings') setupSettings();
    if (screen === 'login') document.getElementById('login-form').addEventListener('submit', event => {
      event.preventDefault(); event.currentTarget.reset();
      document.getElementById('login-status').textContent = '로그인 화면 시연을 마쳤어요. 입력값은 지웠습니다. 왼쪽 메뉴에서 다른 화면도 살펴보세요.';
    });
    paintIcons(main);
    if (focus) { main.focus({preventScroll: true}); window.scrollTo({top: 0, behavior: 'instant'}); }
  }
  window.addEventListener('hashchange', () => renderScreen(true));
  main.addEventListener('input', event => { if (event.target.id === 'file-search') { fileQuery = event.target.value; renderFiles(); } });
  main.addEventListener('click', event => {
    const button = event.target.closest('[data-filter]');
    if (button) { filter = button.dataset.filter; renderFiles(); }
  });
  const searchDialog = document.getElementById('search-dialog');
  function renderSearch() {
    const query = document.getElementById('global-search').value.trim().toLocaleLowerCase();
    const results = document.getElementById('search-results'); results.replaceChildren();
    const choices = [...files.map(file => ({label:file.name,type:'t-box',screen:'files',file})), ...docs.map((doc,index) => ({label:doc.title,type:'커뮤니티',screen:'document',index}))].filter(item => item.label.toLocaleLowerCase().includes(query));
    choices.forEach(item => {
      const link = node('a'); link.href = '#' + item.screen; link.append(icon(item.screen === 'files' ? 'file' : 'document'),node('span','',item.label),node('small','',item.type));
      link.addEventListener('click', () => {
        searchDialog.close();
        if (item.file) { filter = 'all'; fileQuery = item.file.name; }
        if (item.index !== undefined) documentIndex = item.index;
        if (location.hash === '#' + item.screen) renderScreen(true);
      }); results.append(link);
    });
    if (!choices.length) results.append(node('p','','찾은 항목이 없습니다. 다른 검색어로 다시 찾아보세요.'));
    paintIcons(results);
  }
  function openSearch() {
    if (document.querySelector('dialog[open]')) return;
    document.getElementById('global-search').value = ''; renderSearch(); searchDialog.showModal(); document.getElementById('global-search').focus();
  }
  document.getElementById('global-search').addEventListener('input', renderSearch);
  document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openSearch(); } });
  document.addEventListener('click', event => {
    if (event.target.closest('[data-open-search]')) openSearch();
    const close = event.target.closest('[data-close-dialog]'); if (close) close.closest('dialog').close();
    const action = event.target.closest('[data-action]'); if (action) openAction(action.dataset.action);
  });
  const actionDialog = document.getElementById('action-dialog');
  const actionBody = document.getElementById('action-body');
  const actionSubmit = document.getElementById('action-submit');
  let actionKind = '';
  function field(label, input) { const wrap = node('label','demo-field'); wrap.append(node('span','',label),input); return wrap; }
  function openAction(kind) {
    if (document.querySelector('dialog[open]')) return;
    actionKind = kind; actionBody.replaceChildren(); actionSubmit.hidden = false; actionSubmit.className = 'btn';
    const title = document.getElementById('action-title');
    if (kind === 'upload') {
      title.textContent = '파일 올리기'; actionSubmit.textContent = '시안에 추가하기';
      const box = node('div','demo-upload'); box.append(icon('upload'),node('p','','선택한 파일의 이름과 크기로 업로드 화면을 시연합니다.'));
      const input = node('input'); input.type = 'file'; input.name = 'demo-file'; input.required = true; input.setAttribute('aria-label','시안에 추가할 파일'); box.append(input);
      actionBody.append(box,node('p','muted','파일 내용은 읽거나 서버로 전송하지 않습니다. 새로고침하면 시연 목록에서 사라집니다.'));
    } else if (kind === 'write') {
      title.textContent = '새 페이지 쓰기'; actionSubmit.textContent = '시안에 추가하기';
      const titleInput = node('input'); titleInput.type = 'text'; titleInput.name = 'demo-title'; titleInput.required = true; titleInput.maxLength = 100; titleInput.placeholder = '새로운 생각에 이름을 붙여 주세요';
      const bodyInput = node('textarea'); bodyInput.name = 'demo-body'; bodyInput.placeholder = '짧은 메모부터 시작해 보세요'; bodyInput.maxLength = 5000;
      actionBody.append(field('제목',titleInput),field('메모',bodyInput),node('p','muted','현재 시연에만 추가됩니다. 새로고침하면 사라지며 실제 커뮤니티에는 저장되지 않습니다.'));
    } else {
      title.textContent = '지우기 전 한 번 더'; actionSubmit.textContent = '확인 동작 시연하기'; actionSubmit.className = 'btn btn--danger';
      actionBody.append(node('p','','이 항목을 지울까요?'),node('p','muted','위험한 동작의 확인창 시안입니다. 실제 파일은 삭제하지 않습니다.'));
    }
    paintIcons(actionBody); actionDialog.showModal();
    if (kind === 'delete') actionDialog.querySelector('.dialog-foot [data-close-dialog]').focus();
  }
  function openFile(file) {
    actionKind = 'view'; actionBody.replaceChildren(); actionSubmit.hidden = true;
    document.getElementById('action-title').textContent = '파일 미리보기';
    const detail = node('div','file-detail'); const glyph = node('span','file-glyph ' + file.tint); glyph.append(icon(file.icon));
    detail.append(glyph,node('strong','',file.name),node('p','',file.type + ' · ' + file.size),node('p','',file.scope === 'team' ? '공개 범위: 팀 공유' : '공개 범위: 비공개'),node('p','muted','예시 파일입니다. 이 창은 미리보기 부품의 디자인을 보여 줍니다.'));
    actionBody.append(detail); paintIcons(actionBody); actionDialog.showModal();
  }
  document.getElementById('demo-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    if (actionKind === 'upload') {
      const file = form.elements['demo-file'].files[0]; if (!file) return;
      files.unshift({name:file.name,scope:'private',type:'이번 시연에서 추가한 파일',size:file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.max(1,Math.round(file.size / 1024)) + ' KB',date:new Intl.DateTimeFormat('ko-KR').format(new Date()),tint:'',icon:'file'});
      filter = 'all'; fileQuery = ''; actionDialog.close();
      if (location.hash === '#files') renderScreen(); else location.hash = 'files';
      toast('파일 정보만 시안에 추가했어요. 실제 업로드는 하지 않았습니다.');
    } else if (actionKind === 'write') {
      const title = form.elements['demo-title'].value.trim();
      if (!title) { form.elements['demo-title'].setCustomValidity('제목을 입력해 주세요.'); form.elements['demo-title'].reportValidity(); form.elements['demo-title'].addEventListener('input', () => form.elements['demo-title'].setCustomValidity(''), {once:true}); return; }
      docs.push({title,scope:'내 공간 · 시연에서 추가',body:form.elements['demo-body'].value}); documentIndex = docs.length - 1;
      actionDialog.close(); if (location.hash === '#document') renderScreen(); else location.hash = 'document';
      toast('시안에 페이지를 추가했어요. 새로고침하면 사라집니다.');
    } else { actionDialog.close(); toast('확인 동작을 시연했어요. 실제 변경은 없습니다.'); }
  });
  paintIcons(); updateChrome(); renderScreen();
})();
