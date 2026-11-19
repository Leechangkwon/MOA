import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createDeferredEditor, createModuleImporter, createModuleLoader } from '../../public/assets/community/editor-loader.js';

class Element extends EventTarget {
  constructor(doc, tag = 'div') { super(); this.ownerDocument = doc; this.tagName = tag; this.children = []; this.attrs = new Map(); this.value = ''; this.parentNode = null; }
  append(...nodes) { for (const node of nodes) { node.parentNode = this; this.children.push(node); } }
  remove() { if (this.parentNode) { this.parentNode.children = this.parentNode.children.filter(node => node !== this); this.parentNode = null; } }
  setAttribute(key, value) { this.attrs.set(key, value); }
  removeAttribute(key) { this.attrs.delete(key); }
  contains(node) { return this === node || this.children.some(child => child.contains(node)); }
  get isConnected() { return this === this.ownerDocument.root || !!this.parentNode?.isConnected; }
  focus() { this.ownerDocument.activeElement = this; }
}
function fixture() {
  const doc = { defaultView: new EventTarget(), createElement(tag) { return new Element(this, tag); } };
  doc.root = new Element(doc); doc.activeElement = doc.root;
  const mount = new Element(doc); doc.root.append(mount);
  return { doc, mount };
}
const delay = () => { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; };
const turn = () => new Promise(resolve => setImmediate(resolve));
const event = (type, props = {}) => Object.assign(new Event(type, { cancelable: true }), props);
let passed = 0;
async function check(name, run) { await run(); passed++; console.log('PASS ' + name); }
function actual(log) { return { insertText: text => log.push(['text', text]), insertFiles: files => log.push(['files', files]), focus: () => log.push(['focus']), flush: async () => log.push(['flush']), destroy: () => log.push(['destroy']) }; }

await check('concurrent edit actions share one runtime and failed loading is retriable', async () => {
  let loads = 0; const instance = {};
  const load = createModuleLoader(async () => { if (++loads === 1) throw new Error('offline'); return instance; });
  const first = load(); assert.equal(load(), first); await assert.rejects(first, /offline/);
  const retried = load(); assert.equal(load(), retried); assert.equal(await retried, instance); assert.equal(await load(), instance); assert.equal(loads, 2);
});
await check('cached module-map failures get fresh retry URLs and share the successful factory', async () => {
  const urls = [], moduleMap = new Map(), instance = {}; let creates = 0;
  const importer = createModuleImporter('./editor-runtime.js?v=aaaaaaaaaaaa', 'https://fixture.invalid/tbox/assets/community/editor.js?v=aaaaaaaaaaaa', url => {
    if (!moduleMap.has(url)) {
      urls.push(url);
      moduleMap.set(url, urls.length < 3 ? Promise.reject(new Error('cached network failure')) : Promise.resolve({ factory: () => { creates++; return instance; } }));
    }
    return moduleMap.get(url);
  });
  const load = createModuleLoader(attempt => importer(attempt).then(module => module.factory()));
  for (let attempt = 0; attempt < 2; attempt++) {
    const loading = load(); assert.equal(load(), loading); await assert.rejects(loading, /cached network failure/);
  }
  const loading = load(); assert.equal(load(), loading); assert.equal(await loading, instance);
  assert.equal(await load(), instance); assert.equal(creates, 1);
  assert.deepEqual(urls.map(url => new URL(url).search), ['?v=aaaaaaaaaaaa', '?v=aaaaaaaaaaaa&retry=1', '?v=aaaaaaaaaaaa&retry=2']);
});
await check('retry entries keep every relative dependency at its canonical app or PM identity', async () => {
  const urls = [];
  const importer = createModuleImporter('./editor-runtime.js?v=aaaaaaaaaaaa', 'https://fixture.invalid/tbox/assets/community/editor.js?v=aaaaaaaaaaaa', async url => { urls.push(url); });
  await importer(); await importer(1); await importer(2);
  for (const dependency of ['./doc-render.js?v=aaaaaaaaaaaa', './editor-schema.js?v=aaaaaaaaaaaa', './vendor/pm/prosemirror-view.js?v=bbbbbbbbbbbb']) {
    assert.equal(new Set(urls.map(url => new URL(dependency, url).href)).size, 1);
    assert.equal(new URL(dependency, urls[0]).search.includes('retry'), false);
  }
  assert.throws(() => createModuleImporter('./editor-runtime.js', urls[0]), /Invalid versioned module/);
  assert.throws(() => createModuleImporter('./editor-runtime.js?v=aaaaaaaaaaaa&free=1', urls[0]), /Invalid versioned module/);
});
await check('first input, queued focus and flush survive loading', async () => {
  const { mount } = fixture(), pending = delay(), log = [];
  let creates = 0;
  const editor = createDeferredEditor(mount, { load: () => pending.promise });
  editor.focus(); mount.children[0].value = '첫 입력\n둘째 줄';
  let flushed = false; const flush = editor.flush().then(() => { flushed = true; });
  await turn(); assert.equal(flushed, false);
  pending.resolve(() => { creates++; return actual(log); });
  await editor.ready; await flush;
  assert.equal(creates, 1); assert.deepEqual(log, [['text', '첫 입력\n둘째 줄'], ['focus'], ['flush']]);
  assert.equal(mount.children.length, 0); editor.destroy(); editor.destroy();
  assert.equal(log.filter(row => row[0] === 'destroy').length, 1);
});
await check('IME composition completes before mounting and transfer', async () => {
  const { mount } = fixture(), pending = delay(), log = []; let creates = 0;
  const editor = createDeferredEditor(mount, { load: () => pending.promise });
  const input = mount.children[0]; input.dispatchEvent(event('compositionstart')); input.value = 'ㅎ';
  pending.resolve(() => { creates++; return actual(log); }); await turn(); assert.equal(creates, 0);
  input.value = '한글'; input.dispatchEvent(event('compositionend')); await editor.ready;
  assert.equal(creates, 1); assert.deepEqual(log, [['text', '한글']]);
});
await check('destroy during loading prevents stale view and resolves pending flush', async () => {
  const { mount } = fixture(), pending = delay(); let creates = 0;
  const editor = createDeferredEditor(mount, { load: () => pending.promise });
  const flush = editor.flush(); editor.destroy();
  pending.resolve(() => { creates++; return actual([]); });
  assert.equal(await editor.ready, null); await flush; await turn(); assert.equal(creates, 0);
});
await check('detached mount never creates an editor', async () => {
  const { mount } = fixture(), pending = delay(); let creates = 0;
  const editor = createDeferredEditor(mount, { load: () => pending.promise }); mount.remove();
  pending.resolve(() => { creates++; return actual([]); });
  assert.equal(await editor.ready, null); assert.equal(creates, 0);
});
await check('removal after an import failure cancels the waiting editor', async () => {
  const { doc, mount } = fixture(); let observer, disconnected = false;
  doc.defaultView.MutationObserver = class {
    constructor(fn) { observer = fn; }
    observe() {}
    disconnect() { disconnected = true; }
  };
  const editor = createDeferredEditor(mount, { load: async () => { throw new Error('offline'); } });
  await turn(); mount.remove(); observer(); assert.equal(await editor.ready, null); assert.equal(disconnected, true);
});
await check('import failure keeps input and flush until a successful retry', async () => {
  const { mount } = fixture(), pending = delay(), log = [], errors = []; let loads = 0;
  const editor = createDeferredEditor(mount, { load: () => ++loads === 1 ? pending.promise : Promise.resolve(() => actual(log)), onError: text => errors.push(text) });
  mount.children[0].value = '남겨 둘 입력'; const flush = editor.flush(); pending.reject(new Error('module unavailable')); await turn();
  assert.equal(mount.children[0].value, '남겨 둘 입력'); assert.equal(mount.children[2].hidden, false); assert.equal(errors.length, 1);
  mount.children[2].dispatchEvent(event('click')); await editor.ready; await flush;
  assert.equal(loads, 2); assert.deepEqual(log, [['text', '남겨 둘 입력'], ['flush']]);
});
await check('async completion respects a focus moved elsewhere', async () => {
  const { doc, mount } = fixture(), pending = delay(), log = [];
  const editor = createDeferredEditor(mount, { load: () => pending.promise }); editor.focus(); doc.root.focus();
  pending.resolve(() => actual(log)); await editor.ready; assert.equal(log.length, 0);
});
await check('early file paste queues the same files for the upload path', async () => {
  const { mount } = fixture(), pending = delay(), log = [], files = [{ name: 'image.png' }];
  const editor = createDeferredEditor(mount, { load: () => pending.promise });
  const pasted = event('paste', { clipboardData: { files } }); mount.children[0].dispatchEvent(pasted); assert.equal(pasted.defaultPrevented, true);
  pending.resolve(() => actual(log)); await editor.ready; assert.deepEqual(log, [['files', files]]);
});
await check('unsaved loading input warns on unload, then removes the listener', async () => {
  const { doc, mount } = fixture(), pending = delay();
  const editor = createDeferredEditor(mount, { load: () => pending.promise }); mount.children[0].value = '보존';
  const before = event('beforeunload'); doc.defaultView.dispatchEvent(before); assert.equal(before.defaultPrevented, true);
  pending.resolve(() => actual([])); await editor.ready;
  const after = event('beforeunload'); doc.defaultView.dispatchEvent(after); assert.equal(after.defaultPrevented, false);
});
await check('page retry clears only its own error after a real editor succeeds', async () => {
  // Exercise the actual page controller without loading its DOM/rendering dependency graph.
  const source = fs.readFileSync(new URL('../../public/assets/community/page.js', import.meta.url), 'utf8');
  const start = source.slice(source.indexOf('  function startEditing() {'), source.indexOf('  function startEditingNow('));
  const boot = new Function('ready', 'msg', `
    let pendingEditing = null, state = { editing: false, canEdit: true }, view = null, proposal = null;
    let documentDeletionLocked = false, documentPageRemoved = false, focusTitleOnce = false, titleInput = null;
    const docEl = {}, editorEl = {}, show = () => {}, setText = (node, text) => { node.textContent = text; };
    let options;
    const createDeferredEditor = (mount, opts) => { options = opts; return { ready, focus() {} }; };
    ${start}
    startEditing(); return options;
  `);
  for (const [editor, replacement, expected] of [[{}, 'loading failed', ''], [{}, '권한이 바뀌었습니다', '권한이 바뀌었습니다'], [null, 'loading failed', 'loading failed']]) {
    const pending = delay(), msg = { textContent: '' }, options = boot(pending.promise, msg);
    options.onError('loading failed'); msg.textContent = replacement; pending.resolve(editor); await turn();
    assert.equal(msg.textContent, expected);
  }
});
await check('shell and page read graphs exclude the editing runtime and ProseMirror', async () => {
  const base = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public/assets/community');
  function graph(entry, seen = new Set()) {
    if (seen.has(entry)) return seen; seen.add(entry);
    const source = fs.readFileSync(path.join(base, entry), 'utf8');
    const imports = source.matchAll(/^import\s+(?:[^;]*?\sfrom\s+)?['"]([^'"]+)['"]/gm);
    for (const match of imports) graph(path.normalize(path.join(path.dirname(entry), match[1].split('?')[0])), seen);
    return seen;
  }
  for (const entry of ['editor.js', 'page.js']) {
    const files = [...graph(entry)];
    assert.equal(files.some(file => file.startsWith('vendor/pm/') || ['editor-schema.js', 'editor-runtime.js', 'table-editor.js'].includes(file)), false);
  }
  assert.match(fs.readFileSync(path.join(base, 'editor.js'), 'utf8'), /createModuleImporter\('\.\/editor-runtime\.js\?v=[a-f0-9]+', import\.meta\.url\)/);
});
console.log('Editor loading: ' + passed + ' passed, 0 failed');
