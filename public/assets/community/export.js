/** 8-B. Server-produced files only; the browser never converts document JSON. */
export function createPageExport({ base, pageId, beforeOpen }) {
  const button = document.getElementById('page-export');
  let data = null;
  let controller = null;
  let busy = false;
  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text) { node.textContent = text; }
    return node;
  }
  const dialog = el('dialog', 'modal modal--form');
  dialog.id = 'page-export-dialog';
  dialog.setAttribute('aria-labelledby', 'page-export-title');
  const head = el('div', 'modal__head');
  const title = el('h2', 'modal__title', '내보내기');
  title.id = 'page-export-title';
  const close = el('button', 'btn btn--icon btn--plain', '×');
  close.type = 'button'; close.setAttribute('aria-label', '내보내기 닫기');
  head.append(title, close);
  const form = el('form', 'modal__body form');
  form.id = 'page-export-form';
  const body = form;
  const label = el('label', 'field', '형식');
  const select = el('select', 'input');
  select.id = 'page-export-format';
  for (const [value, text] of [['md', 'Markdown'], ['html', 'HTML'], ['print', '인쇄 · PDF로 저장']]) {
    const option = el('option', '', text); option.value = value; select.append(option);
  }
  label.append(select);
  const treeLabel = el('label', 'field');
  const tree = el('input'); tree.type = 'checkbox'; tree.id = 'page-export-tree';
  treeLabel.append(tree, document.createTextNode(' 하위 페이지까지'));
  const note = el('p', 'muted', '볼 수 있는 페이지만 들어갑니다. 첨부 파일은 링크로만 들어갑니다.');
  const error = el('p', 'msg'); error.setAttribute('role', 'status'); error.setAttribute('aria-live', 'polite');
  const actions = el('div', 'form-actions');
  const cancel = el('button', 'btn btn--quiet', '닫기'); cancel.type = 'button';
  const download = el('button', 'btn', '다운로드'); download.type = 'submit';
  download.setAttribute('form', form.id);
  const print = el('a', 'btn', '인쇄 화면 열기');
  print.href = base + '/p/' + encodeURIComponent(pageId) + '/print';
  print.target = '_blank'; print.rel = 'noopener noreferrer'; print.hidden = true;
  actions.append(cancel, download, print); body.append(label, treeLabel, note, error);
  dialog.append(head, form, actions); document.body.append(dialog);
  function choices() {
    const printing = select.value === 'print';
    treeLabel.hidden = printing || !data || !Array.isArray(data.children) || data.children.length === 0;
    download.hidden = printing; print.hidden = !printing;
    error.textContent = ''; error.hidden = true;
  }
  function dismiss() { if (controller) { controller.abort(); } dialog.close(); }
  close.addEventListener('click', dismiss); cancel.addEventListener('click', dismiss);
  dialog.addEventListener('cancel', () => { if (controller) { controller.abort(); } });
  dialog.addEventListener('close', () => { if (button) { button.focus(); } });
  select.addEventListener('change', choices);
  if (button) {
    button.addEventListener('click', async () => {
      if (!data || data.can_export !== true) { return; }
      button.disabled = true;
      try {
        await beforeOpen();
        tree.checked = false; download.disabled = false; choices(); dialog.showModal(); select.focus();
      } catch (failure) {
        choices(); error.textContent = failure.message || '저장을 마친 뒤 다시 내보내 주세요.';
        error.hidden = false;
        download.disabled = true; print.hidden = true; dialog.showModal();
      } finally { button.disabled = false; }
    });
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || select.value === 'print') { return; }
    busy = true; download.disabled = true; select.disabled = true; tree.disabled = true;
    error.textContent = '파일을 준비하고 있습니다…'; controller = new AbortController();
    error.hidden = false;
    try {
      const format = select.value;
      const response = await fetch(base + '/api/pages/' + encodeURIComponent(pageId) + '/export?format=' + format
        + '&tree=' + (tree.checked && !treeLabel.hidden ? '1' : '0'),
      { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (!response.ok) {
        const result = await response.json();
        throw new Error(typeof result.error === 'string' ? result.error : '내보내지 못했습니다. 다시 시도해 주세요.');
      }
      const disposition = response.headers.get('Content-Disposition') || '';
      if (!disposition.startsWith('attachment;')) { throw new Error('로그인 상태를 확인한 뒤 다시 시도해 주세요.'); }
      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const link = el('a'); link.href = objectUrl;
      const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
      link.download = encoded ? decodeURIComponent(encoded[1]) : 'page.' + format;
      link.hidden = true; document.body.append(link); link.click(); link.remove();
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000);
      error.textContent = '다운로드를 시작했습니다.';
    } catch (failure) {
      if (failure.name !== 'AbortError') { error.textContent = failure.message || '내보내지 못했습니다. 다시 시도해 주세요.'; }
    } finally {
      busy = false; controller = null; download.disabled = false; select.disabled = false; tree.disabled = false;
    }
  });
  return { draw(result) { data = result; if (button) { button.hidden = !result || result.can_export !== true; } } };
}
