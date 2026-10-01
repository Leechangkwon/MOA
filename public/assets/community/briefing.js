/* C:F-42. Server-filtered projections, DOM text only; checkbox uses the normal
 * versioned document save so the original document/history remain authoritative. */
const body = document.body;
const base = body.dataset.communityBase;
const appBase = body.dataset.base;
const msg = document.getElementById('briefing-msg');
const refresh = document.getElementById('briefing-refresh');
let busy = false;
let csrf = '';

function node(tag, className, text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text) element.textContent = text;
  return element;
}

async function request(path, options = {}) {
  const response = await fetch(base + path, { credentials: 'same-origin', cache: 'no-store', ...options });
  if (response.status === 401) {
    window.location.assign(appBase + '/login?to=community');
    throw new Error('로그인이 필요합니다.');
  }
  if (!response.ok) {
    const error = new Error(response.status === 409 ? '다른 곳에서 문서가 바뀌었습니다. 최신 내용을 다시 확인해 주세요.'
      : response.status === 403 ? '이 작업을 할 권한이 없습니다.' : '불러오지 못했습니다. 잠시 후 다시 시도해 주세요.');
    error.status = response.status;
    throw error;
  }
  return response.json();
}

function findCheck(blocks, id) {
  for (const block of blocks || []) {
    if (block.id === id && block.type === 'to_do') return block;
    const nested = findCheck(block.children, id);
    if (nested) return nested;
  }
  return null;
}

async function complete(check, control) {
  if (busy) { control.checked = false; return; }
  busy = true; control.disabled = true; refresh.disabled = true; msg.textContent = '';
  try {
    const data = await request('/api/pages/' + encodeURIComponent(check.page_id));
    if (!data.can_edit) throw new Error('이 문서를 수정할 권한이 없습니다.');
    const block = findCheck(data.page.doc.blocks, check.block_id);
    if (!block) throw new Error('체크 항목이 바뀌었습니다. 원본을 확인해 주세요.');
    if (!block.checked) {
      block.checked = true;
      await request('/api/pages/' + encodeURIComponent(check.page_id), {
        method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ doc: data.page.doc, doc_version: data.page.doc_version }),
      });
    }
    await load();
    msg.textContent = '원본 문서에 완료를 기록했습니다.';
    refresh.disabled = false;
    refresh.focus();
  } catch (error) {
    control.checked = false; control.disabled = false;
    if (error.status === 409) await load().catch(() => {});
    msg.textContent = error.message;
  } finally { busy = false; refresh.disabled = false; }
}

function fill(id, items, empty) {
  const list = document.getElementById(id);
  list.replaceChildren();
  for (const item of items) {
    const li = node('li', 'list__item');
    const content = node('div');
    const link = node('a', 'list__head', item.title);
    // The API supplies fixed local paths; reject accidental external redirects in UI too.
    if (typeof item.url === 'string' && /^\/(?!\/)/.test(item.url)) link.href = item.url;
    content.append(link, node('div', 'list__body', item.summary + (item.due ? ' · 기한 ' + item.due : '')));
    if (item.check) {
      const label = node('label', 'list__body');
      const input = node('input'); input.type = 'checkbox';
      input.disabled = !item.check.can_attempt || !csrf;
      input.setAttribute('aria-label', (item.check.label || '체크 항목') + ' 완료');
      input.addEventListener('change', () => { if (input.checked) complete(item.check, input); });
      label.append(input, node('span', '', item.check.label || '체크 항목'));
      content.append(label);
    }
    li.append(content); list.append(li);
  }
  if (!items.length) list.append(node('li', 'list__item muted', empty));
}

async function load() {
  refresh.disabled = true;
  try {
    const data = await request('/api/briefing');
    fill('briefing-top', data.top, '지금 우선 처리할 일이 없습니다.');
    fill('briefing-missed', data.missed, '어제 놓친 일이 없습니다.');
    fill('briefing-others', data.others, '추가로 확인할 일이 없습니다.');
    document.getElementById('briefing-others-title').textContent = '그 밖에 · ' + data.others.length + '건';
    document.getElementById('briefing-updated').textContent = new Date(data.generated_at).toLocaleTimeString('ko-KR', { hour: '2-digit', minute: '2-digit' })
      + ' 기준 · 확인할 일 ' + data.total + '건';
  } finally { if (!busy) refresh.disabled = false; }
}

refresh.addEventListener('click', () => {
  if (busy) return;
  msg.textContent = '';
  load().catch(error => { msg.textContent = error.message; });
});

async function boot() {
  const identity = typeof window.qarediMe === 'function' ? await window.qarediMe()
    : { me: await (await fetch(appBase + '/api/me', { credentials: 'same-origin', cache: 'no-store' })).json() };
  csrf = identity.me && typeof identity.me.csrf_token === 'string' ? identity.me.csrf_token : '';
  const create = document.getElementById('nav-community-new');
  if (create) create.addEventListener('click', async () => {
    if (busy) return;
    busy = true; create.disabled = true;
    try {
      // First-time users have no personal space yet. Initialize only after this
      // explicit create action; merely opening Today must remain read-only.
      await request('/api/me/space');
      const data = await request('/api/pages', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: '{}' });
      window.location.assign(base + '/p/' + encodeURIComponent(data.page.id));
    } catch (error) { msg.textContent = error.message; busy = false; create.disabled = false; }
  });
  await load();
}

boot().catch(error => { msg.textContent = error.message; });
