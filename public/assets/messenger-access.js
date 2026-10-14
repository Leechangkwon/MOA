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
    // 글자 없는 아이콘 단추(폰 윗줄 #appbar-messenger)와 오른쪽 위 메신저 단추(`.shell-tools__messenger` — 카페 C6 · DESIGN 2.30, 높이 44 한 줄 단추 ·
    // 접근 이름 「메신저 이용 안내」)에는 작은 글자를 붙이지 않는다 — 본문 링크에는 「이용 제한」을 붙인다(앱 판의 메신저 타일은 C6 에서 없어졌다).
    if (!link.matches('#appbar-messenger, .shell-tools__messenger')) {
      const note = document.createElement('small'); note.className = 'messenger-access-note'; note.textContent = '이용 제한'; link.append(note);
    }
    link.addEventListener('click', (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault(); opener = link; if (!dialog.open) dialog.showModal();
    });
  });
  // 닫으면 초점은 연 링크로 — 연 링크가 안 보이면(판 안에 있다가 판이 닫힘) 판을 여는 보이는 단추로
  // (넓은 화면 `#app-grid > summary` · 폰 윗줄 앱 이름 ▾ `#appbar-apps` — Q:F-12 U2 · DESIGN 2.15 「창을 닫으면 초점이 돌아온다」).
  const shown = (el) => el !== null && el.isConnected && el.getClientRects().length > 0;
  dialog.addEventListener('close', () => {
    if (!opener?.isConnected) return;
    const back = shown(opener) ? opener : [document.querySelector('#app-grid > summary'), document.getElementById('appbar-apps')].find(shown);
    if (back) back.focus({ preventScroll: true });
  });
})();
