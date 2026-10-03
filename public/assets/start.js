/* Personal start screen, existing readonly endpoints only. No writes, polling or HTML parsing. */
(() => {
  'use strict';
  const panel = document.getElementById('q-start');
  if (!panel) return;
  const root = document.documentElement;
  const base = panel.dataset.base;
  const communityBase = panel.dataset.communityBase;
  const list = document.getElementById('q-start-list');
  const status = document.getElementById('q-start-status');
  const tabs = [...panel.querySelectorAll('[data-start-view]')];
  const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
  let seq = 0;
  let current = 'recent';
  let started = false;
  const get = url => fetch(url, {credentials:'same-origin', cache:'no-store'}).then(async response => {
    let data = null;
    try { data = await response.json(); } catch (_) { /* invalid response shown as a loading error */ }
    return {status:response.status, data};
  }).catch(() => ({status:0, data:null}));
  function render(items) {
    list.replaceChildren();
    for (const item of items.slice(0,6)) {
      const link = document.createElement('a');
      link.className = 'q-start-item';
      link.href = item.href;
      const kind = document.createElement('span'); kind.className = 'q-start-kind'; kind.textContent = item.kind;
      const title = document.createElement('strong'); title.textContent = item.title;
      const arrow = document.createElement('span'); arrow.className='q-start-arrow'; arrow.textContent='↗'; arrow.setAttribute('aria-hidden','true');
      link.append(kind,title,arrow); list.appendChild(link);
    }
  }
  async function load(view) {
    if (!['recent','favorites'].includes(view) || root.dataset.design === 'claude') return;
    started = true; current = view;
    const ticket = ++seq;
    tabs.forEach(tab => tab.setAttribute('aria-pressed',String(tab.dataset.startView === view)));
    status.textContent = '내 작업을 불러오는 중입니다.';
    list.replaceChildren(); panel.setAttribute('aria-busy','true');
    const me = await get(base + '/api/me');
    if (ticket !== seq) return;
    if (me.status !== 200 || !me.data || me.data.next) {
      status.textContent = me.data && me.data.next ? '계정 설정을 마치면 내 작업을 이어 볼 수 있습니다.' : '내 작업을 불러오지 못했습니다. 다시 불러오기를 눌러 주세요.';
      panel.setAttribute('aria-busy','false'); return;
    }
    const [files,pages] = await Promise.all([get(base+'/api/files/'+view),get(communityBase+'/api/me/'+view)]);
    if (ticket !== seq || root.dataset.design === 'claude') return;
    if (files.status === 401 || pages.status === 401) {
      status.textContent='로그인 상태를 확인한 뒤 다시 열어 주세요.'; panel.setAttribute('aria-busy','false'); return;
    }
    const items=[];
    for (const file of files.status === 200 && Array.isArray(files.data?.files) ? files.data.files : []) {
      if (!file || !uuid.test(file.id) || typeof file.name !== 'string' || !file.name.trim()) continue;
      items.push({kind:'자료실',title:file.name,href:base+'/?q='+encodeURIComponent(file.name.slice(0,200)),time:Date.parse(file.last_used_at)||0});
    }
    for (const page of pages.status === 200 && Array.isArray(pages.data?.pages) ? pages.data.pages : []) {
      if (!page || !uuid.test(page.id) || typeof page.title !== 'string') continue;
      items.push({kind:'커뮤니티',title:page.title || '제목 없는 페이지',href:communityBase+'/p/'+page.id,time:Date.parse(page.last_viewed_at)||0});
    }
    if (view === 'recent') items.sort((a,b) => b.time-a.time);
    render(items);
    status.textContent = files.status !== 200 || pages.status !== 200 ? '일부 항목을 가져오지 못했습니다. 다시 불러오기를 눌러 주세요.'
      : items.length ? '현재 볼 수 있는 항목 ' + items.length + '개 중 ' + Math.min(items.length,6) + '개를 표시합니다.'
      : view === 'recent' ? '파일을 미리 보거나 문서를 열면 여기에 모입니다.' : '파일과 문서에 별을 달면 여기에 모입니다.';
    panel.setAttribute('aria-busy','false');
  }
  tabs.forEach(tab => tab.addEventListener('click',() => load(tab.dataset.startView)));
  document.getElementById('q-start-retry').addEventListener('click',() => load(current));
  new MutationObserver(() => {
    if (root.dataset.design === 'claude') { seq++; started=false; list.replaceChildren(); panel.setAttribute('aria-busy','false'); }
    else if (!started) load(current);
  }).observe(root,{attributes:true,attributeFilter:['data-design']});
  load('recent');
})();
