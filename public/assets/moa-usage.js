'use strict';
(() => {
  // Shortcuts activate existing controls, so their permission and save flows stay authoritative.
  const actions = new Set(['community-new','community-template-new','upload-toggle','search-q','moa-file-list']);
  for (const shortcut of document.querySelectorAll('[data-moa-action]')) {
    const id = shortcut.dataset.moaAction;
    if (!actions.has(id)) continue;
    const target = document.getElementById(id);
    if (!target) { shortcut.hidden = true; continue; }
    const sync = () => { shortcut.hidden = target.hidden; shortcut.disabled = Boolean(target.disabled); };
    sync(); new MutationObserver(sync).observe(target, {attributes:true, attributeFilter:['hidden','disabled']});
    shortcut.addEventListener('click', () => {
      if (target.hidden || target.disabled) return;
      if (id === 'search-q' || id === 'moa-file-list') {
        target.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',block:'center'}); target.focus({preventScroll:true});
      } else {
        const menu = target.closest('details'); if (menu) menu.open = true;
        target.click(); if (menu) menu.open = false;
      }
    });
  }
  const boards = document.getElementById('nav-community-boards');
  const doors = document.getElementById('moa-board-doors');
  if (!boards || !doors) return;
  function mirror() {
    const links = [...boards.querySelectorAll('a[href]')]; doors.replaceChildren();
    for (const source of links.slice(0,6)) {
      const link = document.createElement('a'); link.className = 'moa-board-door';
      link.href = source.href; link.textContent = source.textContent.trim() + ' ↗'; doors.append(link);
    }
    if (!links.length) {
      const note = document.createElement('p'); note.className = 'moa-muted';
      note.textContent = '현재 표시할 게시판이 없어요. 계속 보이지 않으면 관리자에게 게시판과 접근 권한을 확인해 주세요.';
      doors.append(note);
    }
  }
  new MutationObserver(mirror).observe(boards, {childList:true,subtree:true});
  if (boards.children.length) mirror();
  else setTimeout(mirror,5000);
})();
