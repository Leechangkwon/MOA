/** Keep dependencies canonical; only a failed entry request gets a fresh module-map key. */
export const createModuleImporter = (specifier, base, importModule = url => import(url)) => {
  if (!/^\.{1,2}\/[^?#]+\.js\?v=[a-f0-9]{12}$/.test(specifier)) throw new Error('Invalid versioned module');
  const canonical = new URL(specifier, base);
  return (attempt = 0) => {
    const url = new URL(canonical.href);
    if (attempt > 0) url.searchParams.set('retry', String(attempt));
    return importModule(url.href);
  };
};

/** One module/factory instance per page; failures advance a shared retry URL. */
export function createModuleLoader(load) {
  let pending = null;
  let attempt = 0;
  return () => {
    if (!pending) pending = Promise.resolve().then(() => load(attempt)).catch(error => { attempt++; pending = null; throw error; });
    return pending;
  };
}

/** Keep an editing surface available while the editing-only module arrives. */
export function createDeferredEditor(mount, opts) {
  const doc = mount.ownerDocument;
  const input = doc.createElement('textarea');
  input.className = 'input';
  input.rows = 5;
  input.setAttribute('aria-label', '편집기를 불러오는 동안 입력');
  input.placeholder = '편집기를 불러오는 중… 지금 입력해도 이어집니다';
  const note = doc.createElement('p');
  note.className = 'help';
  note.setAttribute('role', 'status');
  note.textContent = '편집기를 불러오는 중…';
  const retry = doc.createElement('button');
  retry.type = 'button';
  retry.className = 'btn btn--small btn--quiet';
  retry.textContent = '다시 불러오기';
  retry.hidden = true;
  mount.append(input, note, retry);
  mount.setAttribute('aria-busy', 'true');
  let editor = null;
  let destroyed = false;
  let loading = false;
  let composing = false;
  let finishComposition = null;
  let resolveReady;
  let focused = false;
  let removalObserver = null;
  const files = [];
  const ready = new Promise(resolve => { resolveReady = resolve; });
  const warnUnsaved = event => {
    if (!destroyed && !editor && (input.value || files.length)) {
      event.preventDefault();
      event.returnValue = '';
    }
  };
  doc.defaultView.addEventListener('beforeunload', warnUnsaved);
  const ownsFocus = () => mount.contains(doc.activeElement);
  input.addEventListener('compositionstart', () => { composing = true; });
  input.addEventListener('compositionend', () => {
    composing = false;
    if (finishComposition) { const done = finishComposition; finishComposition = null; done(); }
  });
  input.addEventListener('paste', event => {
    const got = event.clipboardData && event.clipboardData.files;
    if (!got || !got.length) return;
    event.preventDefault();
    files.push(...Array.from(got));
  });
  input.addEventListener('dragover', event => { if (event.dataTransfer && event.dataTransfer.files.length) event.preventDefault(); });
  input.addEventListener('drop', event => {
    if (!event.dataTransfer || !event.dataTransfer.files.length) return;
    event.preventDefault();
    files.push(...Array.from(event.dataTransfer.files));
  });
  const clean = () => {
    input.remove(); note.remove(); retry.remove();
    mount.removeAttribute('aria-busy');
    doc.defaultView.removeEventListener('beforeunload', warnUnsaved);
    if (removalObserver) removalObserver.disconnect();
  };
  async function load() {
    if (loading || destroyed || editor) return;
    loading = true;
    retry.hidden = true;
    note.textContent = '편집기를 불러오는 중…';
    if (opts.onState) opts.onState(note.textContent);
    try {
      const create = await opts.load();
      if (destroyed) return;
      if (composing) await new Promise(resolve => { finishComposition = resolve; });
      // A DOM removal during loading must never create a detached ProseMirror view.
      if (destroyed || !mount.isConnected) { controller.destroy(); return; }
      const focus = focused && ownsFocus();
      const text = input.value;
      editor = create();
      if (!editor) throw new Error('편집기를 열지 못했습니다. 다시 불러와 주세요.');
      // Keep the buffer until construction succeeds; an import/constructor failure can be retried.
      clean();
      if (text && editor.insertText) editor.insertText(text);
      const hasFiles = files.length > 0;
      if (hasFiles && editor.insertFiles) editor.insertFiles(files.splice(0));
      if (focus) editor.focus();
      if (opts.onState) opts.onState(text || hasFiles ? '저장 중…' : '');
      resolveReady(editor);
    } catch (error) {
      if (destroyed) return;
      note.textContent = '편집기를 불러오지 못했습니다. 입력한 내용은 여기에 남아 있습니다.';
      retry.hidden = false;
      mount.removeAttribute('aria-busy');
      if (opts.onState) opts.onState('편집기 불러오기 실패');
      if (opts.onError) opts.onError(error && error.message ? error.message : note.textContent);
    } finally { loading = false; }
  }
  retry.addEventListener('click', () => { mount.setAttribute('aria-busy', 'true'); load(); });
  const controller = {
    ready,
    focus() { focused = true; if (editor) editor.focus(); else if (!destroyed) input.focus(); },
    flush() { return ready.then(current => current ? current.flush() : undefined); },
    destroy() {
      if (destroyed) return;
      destroyed = true;
      if (finishComposition) { const done = finishComposition; finishComposition = null; done(); }
      if (editor) editor.destroy();
      files.length = 0;
      clean();
      resolveReady(null);
    }
  };
  if (typeof doc.defaultView.MutationObserver === 'function') {
    removalObserver = new doc.defaultView.MutationObserver(() => { if (!mount.isConnected) controller.destroy(); });
    removalObserver.observe(doc.documentElement, { childList: true, subtree: true });
  }
  load();
  return controller;
}
