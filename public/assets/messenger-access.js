(() => {
  'use strict';
  const dialog = document.getElementById('messenger-unavailable');
  if (!dialog) return;
  let opener = null;
  document.querySelectorAll('a[href]').forEach((link) => {
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || url.pathname !== dialog.dataset.path) return;
    link.setAttribute('aria-haspopup', 'dialog');
    link.setAttribute('aria-controls', dialog.id);
    link.title = '메신저 이용 제한 · 안내 보기';
    // 글자 없는 아이콘 단추(폰 윗줄 #appbar-messenger)에만 작은 글자를 붙이지 않는다 — 앱 격자의 메신저 타일
    // (`#app-grid a.app-menu__app`, Q:F-12 U2 · DESIGN 2.18)과 본문 링크에는 「이용 제한」을 붙인다. 옛 아이콘 줄(.app-rail__messenger)은 U2 에서 없어졌다.
    if (!link.matches('#appbar-messenger')) {
      const note = document.createElement('small'); note.className = 'messenger-access-note'; note.textContent = '이용 제한'; link.append(note);
    }
    link.addEventListener('click', (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); opener = link; if (!dialog.open) dialog.showModal();
    });
  });
  dialog.addEventListener('close', () => { if (opener?.isConnected) opener.focus({ preventScroll: true }); });
})();
