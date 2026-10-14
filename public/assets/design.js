/* Qaredi visual preferences. No API calls; native dialog and allowlisted cookies only. */
(() => {
  'use strict';
  const root = document.documentElement;
  const dialog = document.getElementById('q-design-dialog');
  if (!dialog) return;
  const themes = ['moa', 'claude', 'astra', 'aurora', 'bloom', 'nova'];
  const names = { moa: '모아', claude: 'Claude', astra: 'Astra', aurora: 'Aurora', bloom: 'Bloom', nova: 'Nova' };
  const choices = [...dialog.querySelectorAll('[data-design-choice]')];
  const motion = document.getElementById('q-motion-reduce');
  const status = document.getElementById('q-design-status');
  const save = document.getElementById('q-design-save');
  let original = root.dataset.design;
  let originalMotion = root.dataset.motion;
  let opener = null;
  function sync() {
    choices.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.designChoice === root.dataset.design)));
    document.querySelectorAll('[data-design-name]').forEach(label => { label.textContent = names[root.dataset.design] || 'Astra'; });
    motion.checked = root.dataset.motion === 'reduce';
  }
  function restore() {
    root.dataset.design = original;
    root.dataset.motion = originalMotion;
    sync();
  }
  function write(name, value) {
    document.cookie = name + '=' + value + '; Max-Age=31536000; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    return document.cookie.split(';').some(part => part.trim() === name + '=' + value);
  }
  document.querySelectorAll('[data-design-open]').forEach(button => button.addEventListener('click', () => {
    if (dialog.open) return;
    original = root.dataset.design;
    originalMotion = root.dataset.motion;
    opener = button;
    status.textContent = '';
    save.disabled = false;
    sync();
    dialog.showModal();
    const current = choices.find(choice => choice.dataset.designChoice === original);
    if (current) current.focus();
  }));
  choices.forEach(button => button.addEventListener('click', () => {
    const theme = button.dataset.designChoice;
    if (!themes.includes(theme)) return;
    root.dataset.design = theme;
    status.textContent = names[theme] + ' 미리보기 중 · 기본으로 저장하면 적용됩니다.';
    sync();
  }));
  motion.addEventListener('change', () => { root.dataset.motion = motion.checked ? 'reduce' : 'auto'; });
  save.addEventListener('click', () => {
    const theme = root.dataset.design;
    if (!themes.includes(theme)) return;
    let stored = false;
    try { stored = write('qaredi_design', theme) && write('qaredi_motion', motion.checked ? 'reduce' : 'auto'); } catch (_) { /* cookie may be disabled */ }
    original = theme;
    originalMotion = root.dataset.motion;
    sync();
    status.textContent = stored ? names[theme] + '를 이 브라우저의 기본 테마로 저장했습니다.' : '현재 화면에 적용했습니다. 쿠키가 차단되어 다음 방문에는 저장되지 않습니다.';
  });
  dialog.querySelector('[data-design-close]').addEventListener('click', () => dialog.close());
  // 사이트 H1b — 연 단추가 닫힌 판(details — 내 계정 판의 「테마」 타일) 안이면 그 판을 여는 보이는 단추로 돌아간다(summary · 폰 윗줄의 aria-controls 단추)
  function returnTarget() {
    const box = opener && opener.closest('details');
    if (!box || box.open) return opener;
    const shown = el => el && el.getClientRects().length > 0;
    return [box.querySelector(':scope > summary'), box.id ? document.querySelector('[aria-controls="' + box.id + '"]') : null].find(shown) || opener;
  }
  dialog.addEventListener('close', () => { restore(); const back = returnTarget(); if (back && back.isConnected) back.focus(); });
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const box = dialog.getBoundingClientRect();
    if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
  });
  document.querySelectorAll('[data-ux-community-start]').forEach(button => button.addEventListener('click', () => {
    const original = document.getElementById('community-new');
    if (original && !original.hidden && !original.disabled) original.click();
  }));
  sync();
})();
