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
    if (!link.matches('.app-rail__messenger, #appbar-messenger')) {
      const note = document.createElement('small'); note.className = 'messenger-access-note'; note.textContent = '이용 제한'; link.append(note);
    }
    link.addEventListener('click', (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); opener = link; if (!dialog.open) dialog.showModal();
    });
  });
  dialog.addEventListener('close', () => { if (opener?.isConnected) opener.focus({ preventScroll: true }); });
})();
