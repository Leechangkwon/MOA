// 9-A/B: explicit previews, plain-language results and serialized user actions.
function el(tag, text, cls) {
  const n = document.createElement(tag);
  if (text) n.textContent = text;
  if (cls) n.className = cls;
  return n;
}
function button(text, fn, cls = 'btn btn--quiet') {
  const n = el('button', text, cls); n.type = 'button'; n.addEventListener('click', fn); return n;
}
function field(body, label, control) {
  control.setAttribute('aria-label', label);
  const l = el('label', '', 'field'); l.append(el('span', label, 'field__label'), control); body.append(l); return control;
}
function select(choices, value) {
  const n = el('select');
  for (const [v, t] of choices) { const o = el('option', t); o.value = String(v); o.selected = String(v) === String(value); n.append(o); }
  return n;
}
function input(type, value) { const n = el('input'); n.type = type; if (value !== undefined) n.value = String(value); return n; }
let dialogNumber = 0;
function dialog(title) {
  const opener = document.activeElement;
  const d = el('dialog', '', 'modal modal--form');
  const h = el('div', '', 'modal__head'), heading = el('h2', title, 'modal__title');
  heading.id = 'automation-dialog-title-' + (++dialogNumber); d.setAttribute('aria-labelledby', heading.id); h.append(heading);
  const body = el('form', '', 'modal__body form'), actions = el('div', '', 'form-actions'), msg = el('p', '', 'msg');
  body.addEventListener('submit', event => event.preventDefault());
  msg.setAttribute('role', 'status'); msg.setAttribute('aria-live', 'polite'); body.append(msg);
  actions.append(button('닫기', () => d.close())); d.append(h, body, actions);
  d.addEventListener('close', () => { d.remove(); if (opener && opener.isConnected) opener.focus(); });
  document.body.append(d); return { d, body, actions, msg };
}
const reasons = {
  dry_run_required: '설정을 바꾼 뒤에는 다시 시험 실행해 주세요.',
  enabled_limit: '켜진 자동 작업은 20개까지입니다. 사용하지 않는 작업을 먼저 꺼 주세요.',
  source_denied: '원본을 볼 권한이 없거나 절차서가 해제되었습니다.',
  parent_denied: '만들 자리를 수정할 권한이 없습니다.', parent_shape: '선택한 자리에는 페이지를 만들 수 없습니다.',
  source_scope: '절차서 자신이나 그 아래 페이지를 만들 자리로 골라 주세요.',
  scope_denied: '이 작업의 대상에 접근할 권한이 없습니다.', scope_owner_required: '내 문서나 내 팀의 업무에 설정해 주세요.',
  template_scope: '이 템플릿을 사용할 수 있는 자리를 골라 주세요.',
  executor_denied: '현재 계정 권한으로 실행할 수 없습니다.', recipient_denied: '알림 받을 사람이 문서를 볼 수 없습니다.',
  bad_title: '제목은 1~200자로 입력해 주세요.', bad_name: '작업 이름은 1~100자로 입력해 주세요.',
  bad_schedule: '시각은 10분 단위로 입력해 주세요.', bad_due: '기한은 0~365일로 입력해 주세요.',
  duplicate_press: '이미 실행 중이거나 방금 실행했습니다. 잠시 뒤 확인해 주세요.',
  already_notified: '오늘 이미 알림을 보냈습니다.', disabled: '꺼진 작업입니다.',
};
const statusText = value => ({ ok: '완료', partial: '일부 완료', denied: '권한 확인 필요', failed: '실패', skipped: '건너뜀' }[value] || '확인 필요');
const stepText = value => ({ create_page: '페이지 만들기', notify: '알림 보내기' }[value] || '작업');
function failure(error) { return error && error.message ? error.message : '처리하지 못했습니다. 다시 시도해 주세요.'; }
async function request(api, method, path, payload) {
  let r;
  try { r = await api(method, path, payload); } catch (_) { throw new Error('서버에 연결하지 못했습니다. 다시 시도해 주세요.'); }
  if (r.status < 200 || r.status >= 300) {
    throw new Error(reasons[r.data && r.data.reason] || (r.status === 401 ? '로그인이 만료되었습니다. 다시 로그인해 주세요.'
      : r.status === 403 || r.status === 404 ? '현재 권한으로 이 작업을 열거나 실행할 수 없습니다.'
        : '처리하지 못했습니다. 입력 내용을 확인한 뒤 다시 시도해 주세요.'));
  }
  return r.data;
}
async function action(container, msg, fn) {
  if (container.dataset.busy === '1') return;
  container.dataset.busy = '1'; container.setAttribute('aria-busy', 'true'); msg.textContent = '';
  const controls = Array.from(container.querySelectorAll('button,input,select'));
  const states = controls.map(n => n.disabled); controls.forEach(n => { n.disabled = true; });
  try { return await fn(); } catch (error) { msg.textContent = failure(error); }
  finally { controls.forEach((n, i) => { n.disabled = states[i]; }); delete container.dataset.busy; container.removeAttribute('aria-busy'); }
}
function stepsView(steps) {
  const list = el('ul', '', 'list');
  for (const step of steps || []) {
    const row = el('li', '', 'list__item'); row.append(el('strong', stepText(step.type) + ' · ' + statusText(step.status)));
    if (step.reason) row.append(el('div', reasons[step.reason] || '대상과 현재 권한을 확인해 주세요.', 'list__body'));
    list.append(row);
  }
  return list;
}
function previewView(target, result) {
  target.replaceChildren(el('h3', '시험 결과'), el('p', result.status === 'ok' ? '실행할 준비가 되었습니다.' : '아래 항목을 확인해 주세요.', 'notice'));
  for (const p of result.would_create || []) target.append(el('p', '만들 문서: ' + p.title + ' · 자리: ' + (p.parent_title || '내 공간'), 'help'));
  for (const who of result.would_notify || []) target.append(el('p', '알림 받을 사람: ' + who, 'help'));
  target.append(stepsView(result.steps));
}
function dateText(value) {
  const d = new Date(value); return value && !Number.isNaN(d.getTime()) ? d.toLocaleString('ko-KR', { timeZone: 'Asia/Seoul' }) : '';
}

export async function loadAutomations(api) {
  const automations = []; let cursor = null;
  do {
    const data = await request(api, 'GET', '/api/automations' + (cursor ? '?after=' + encodeURIComponent(cursor) : ''));
    automations.push(...data.automations); cursor = data.next_cursor || null;
  } while (cursor);
  return { automations };
}

export function repeatDialog(api, pageId, data) {
  const template = data.template || null;
  const sourceTitle = template ? template.name || template.title : data.page.title;
  const { d, body, actions, msg } = dialog('반복·버튼 작업 만들기');
  const kind = field(body, '작업', select(template ? [['recurring', '정해진 시각 반복']]
    : [['recurring', '정해진 시각 반복'], ['button', '본문에서 누르는 버튼']], 'recurring'));
  const name = field(body, '작업 이름', input('text', Array.from(sourceTitle).slice(0, 95).join('') + ' 반복')); name.maxLength = 100;
  const freq = field(body, '주기', select([['daily', '매일'], ['weekly', '매주'], ['monthly', '매월']], 'monthly'));
  const weekday = field(body, '요일', select([[1, '월'], [2, '화'], [3, '수'], [4, '목'], [5, '금'], [6, '토'], [7, '일']], 1));
  const monthday = field(body, '월 날짜', select([...Array.from({ length: 28 }, (_, i) => [i + 1, String(i + 1) + '일']), ['last', '마지막 날']], 1));
  const time = field(body, '시각 · 서울', input('time', '09:00')); time.step = 600;
  const parentChoices = template ? [['', template.scope === 'database' ? '이 템플릿의 데이터베이스' : '내 공간 맨 위']]
    : [[pageId, '이 절차서 아래'], ...(data.children || []).map(p => [p.id, p.title])];
  const parent = field(body, '만들 자리', select(parentChoices, pageId || ''));
  const pattern = field(body, '제목 틀', input('text', '{제목} – {YYYY-MM}')); pattern.maxLength = 200;
  body.append(el('p', '{제목} · {YYYY} · {MM} · {DD} · {YYYY-MM} · {주}를 사용할 수 있습니다.', 'help'));
  const status = field(body, '새 페이지 상태', select([['', '없음'], ['todo', '할 일'], ['doing', '진행'], ['review', '검토'], ['done', '완료'], ['hold', '보류']], 'todo'));
  const due = field(body, '기한 · 며칠 뒤 (선택)', input('number', '')); due.min = '0'; due.max = '365'; due.step = '1';
  const press = field(body, '버튼을 누르는 사람', select([['owner', '작업 주인'], ['editors', '이 페이지 편집자']], 'owner'));
  const notify = field(body, '나에게 알림', input('checkbox')); notify.checked = true;
  const preview = el('div'); preview.setAttribute('aria-live', 'polite'); body.append(preview);
  let id = null, eligible = false, busy = false, loadingParents = Boolean(template && template.scope !== 'database');
  const controls = [kind, name, freq, weekday, monthday, time, parent, pattern, status, due, press, notify];
  function sync() {
    const schedule = kind.value === 'recurring';
    freq.parentElement.hidden = !schedule; time.parentElement.hidden = !schedule;
    weekday.parentElement.hidden = !schedule || freq.value !== 'weekly';
    monthday.parentElement.hidden = !schedule || freq.value !== 'monthly'; press.parentElement.hidden = schedule;
    controls.forEach(n => { n.disabled = busy || n.parentElement.hidden; });
    dry.disabled = busy || loadingParents || parent.options.length === 0; enable.disabled = busy || !eligible;
    actions.querySelector('button').disabled = busy;
  }
  async function work(fn) {
    if (busy) return; busy = true; msg.textContent = ''; sync();
    try { await fn(); } catch (error) { eligible = false; msg.textContent = failure(error); }
    finally { busy = false; sync(); }
  }
  const enable = button('켜기', () => work(async () => {
    await request(api, 'PATCH', '/api/automations/' + id, { is_enabled: true }); d.close();
  }), 'btn');
  const dry = button('시험 실행', () => work(async () => {
    eligible = false;
    if (!name.value.trim() || !pattern.value.trim()) throw new Error('작업 이름과 제목 틀을 입력해 주세요.');
    const trigger = kind.value === 'button' ? { type: 'button' } : { type: 'schedule', freq: freq.value, time: time.value };
    if (kind.value === 'recurring' && freq.value === 'weekly') trigger.weekday = Number(weekday.value);
    if (kind.value === 'recurring' && freq.value === 'monthly') trigger.monthday = monthday.value === 'last' ? 'last' : Number(monthday.value);
    const create = { type: 'create_page', source: template ? { template: template.id } : { page: pageId },
      parent: parent.value || null, title_pattern: pattern.value };
    if (status.value) create.status = status.value; if (due.value !== '') create.due_in_days = Number(due.value);
    const rule = { kind: kind.value, name: name.value.trim(), scope_page_id: template ? null : pageId, trigger, actions: [create] };
    if (notify.checked) rule.actions.push({ type: 'notify', to: 'owner', text_key: 'recurring_created' });
    if (kind.value === 'button') rule.press_scope = press.value;
    const saved = await request(api, id ? 'PATCH' : 'POST', '/api/automations' + (id ? '/' + id : ''), rule); id = saved.automation.id;
    const result = await request(api, 'POST', '/api/automations/' + id + '/dry-run', {});
    previewView(preview, result); eligible = result.status === 'ok';
  }), 'btn');
  for (const n of controls) n.addEventListener('input', () => { eligible = false; preview.replaceChildren(); sync(); });
  d.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  actions.append(dry, enable); sync(); d.showModal(); name.focus();
  if (template && template.scope !== 'database') {
    (async () => {
      try {
        let parents = data.parents;
        if (!Array.isArray(parents)) {
          const spaces = await request(api, 'GET', '/api/spaces'); parents = [];
          for (const space of spaces.spaces || []) {
            if (!space.can_write || space.kind === 'board') continue;
            const pages = await request(api, 'GET', '/api/pages?tree=1&space=' + encodeURIComponent(space.id));
            parents.push(...(pages.pages || []).filter(p => p.can_edit !== false).map(p => ({ id: p.id, title: space.name + ' · ' + p.title })));
          }
        }
        if (!Array.isArray(data.parents)) {
          let page = 1, remaining = true;
          while (remaining) {
            const shared = await request(api, 'GET', '/api/pages?shared=me&page=' + page);
            const rows = shared.pages || [];
            parents.push(...rows.filter(p => p.share_level === 'edit' || p.can_edit === true).map(p => ({ id: p.id, title: '공유받음 · ' + p.title })));
            // 규모 D2a A5 — 개수는 1,000 까지만 센다(total_capped) — 넘으면 빈 쪽이 나올 때까지 계속 받는다
            remaining = rows.length > 0 && (page * (shared.per_page || 20) < (shared.total || 0) || shared.total_capped === true); page += 1;
          }
        }
        if (!d.isConnected) return;
        const known = new Set(Array.from(parent.options).map(o => o.value));
        for (const p of parents) if (!known.has(p.id)) { const option = el('option', p.title); option.value = p.id; parent.append(option); known.add(p.id); }
        if (!parent.options.length) msg.textContent = '만들 자리로 사용할 페이지를 먼저 만들어 주세요.';
      } catch (error) { msg.textContent = failure(error); }
      finally { loadingParents = false; sync(); }
    })();
  }
}

export function drawAutomationPage(data, pageId, api, reload) {
  const props = document.getElementById('page-props'), menu = document.querySelector('#page-more .menu__list'); if (!props || !menu) return;
  for (const n of document.querySelectorAll('[data-automation-ui]')) n.remove();
  const add = (n, parent) => { n.dataset.automationUi = '1'; parent.append(n); return n; };
  if (data.due) add(el('span', (data.due < new Date().toLocaleDateString('sv-SE', { timeZone: 'Asia/Seoul' }) && !['done', 'hold'].includes(data.page.status) ? '기한 지남 ' : '기한 ') + data.due, 'badge badge--internal'), props);
  if (data.completed_at) add(el('span', '완료 ' + data.completed_at.slice(0, 10), 'badge badge--internal'), props);
  if (data.repeat) {
    add(el('span', '반복: ' + data.repeat.name, 'muted'), props);
    const source = data.repeat.source;
    if (source && typeof source.url === 'string' && /^\/(?!\/)/.test(source.url)) {
      const link = el('a', '원본 열기'); link.href = source.url; link.title = source.title || '반복 원본'; add(link, props);
    }
  }
  if (data.can_edit) {
    add(button('기한 바꾸기', () => {
      const { d, body, actions, msg } = dialog('기한'); const date = field(body, '기한 (비우면 해제)', input('date', data.due || ''));
      actions.append(button('저장', () => action(d, msg, async () => {
        await request(api, 'PATCH', '/api/pages/' + pageId + '/properties', { due: date.value || null }); d.close(); await reload();
      }), 'btn')); d.showModal(); date.focus();
    }, 'menu__item'), menu);
    if (data.page_kind === 'procedure') add(button('⟳ 반복·버튼 만들기', () => repeatDialog(api, pageId, data), 'menu__item'), menu);
  }
  if (data.can_manage) add(button('상태 알림', () => fixedNotifications(api, { pageId }), 'menu__item'), menu);
}

function fixedNotifications(api, scope) {
    const { d, body, actions, msg } = dialog(scope.spaceId ? '공간 상태 알림' : '고정 알림');
    d.showModal(); msg.textContent = '설정을 불러오는 중입니다.';
    action(d, msg, async () => {
      const mine = await loadAutomations(api);
      if (!d.isConnected) return;
      for (const [kind, key, text] of [['on_status', 'review_requested', '검토 상태가 되면 담당자에게 알림'], ['due_reminder', 'due_tomorrow', '기한 하루 전 담당자에게 알림']]) {
        let existing = mine.automations.find(a => (scope.spaceId ? Number(a.scope_space_id) === Number(scope.spaceId) : a.scope_page_id === scope.pageId) && a.kind === kind);
        const on = field(body, text, input('checkbox')); on.checked = Boolean(existing && existing.is_enabled);
        actions.append(button(text + ' 저장', () => action(d, msg, async () => {
          const trigger = { type: kind }; if (kind === 'on_status') trigger.to = 'review';
          const result = await request(api, existing ? 'PATCH' : 'POST', '/api/automations' + (existing ? '/' + existing.id : ''), {
            kind, name: text, scope_page_id: scope.pageId || null, scope_space_id: scope.spaceId || null, trigger, actions: [{ type: 'notify', to: 'owner', text_key: key }], is_enabled: on.checked,
          }); existing = result.automation; msg.textContent = '저장했습니다.';
        })));
      }
    });

}

export function drawAutomationSpace(space, api) {
  const title = document.getElementById('community-space-title');
  const head = title && title.closest('header'); if (!head) return;
  const old = document.getElementById('community-space-notifications'); if (old) old.remove();
  if (!space.can_write || !['personal', 'team'].includes(space.kind)) return;
  const settings = button('공간 상태 알림', () => fixedNotifications(api, { spaceId: Number(space.id) }));
  settings.id = 'community-space-notifications'; head.append(settings);
}

export function bindAutomations(api, base) {
  const title = document.getElementById('community-space-title'); if (!title) return;
  const head = title.closest('header'); title.textContent = '자동 작업';
  const sub = document.getElementById('community-space-sub'); if (sub) sub.textContent = '시험 실행을 확인한 뒤 켭니다. 권한은 실행할 때 다시 확인합니다.';
  for (const id of ['community-more', 'community-new']) { const n = document.getElementById(id); if (n) n.hidden = true; }
  const body = head.parentElement, list = el('div', '', 'list'), msg = el('p', '', 'msg'); msg.setAttribute('role', 'status');
  body.replaceChildren(head, msg, list);
  async function load() {
    const data = await loadAutomations(api); list.replaceChildren();
    if (!data.automations.length) list.append(el('p', '자동 작업이 없습니다. 절차서의 더보기에서 반복을 만드세요.', 'notice'));
    for (const a of data.automations) {
      const card = el('section', '', 'list__item'); card.append(el('h2', a.name), el('span', a.is_enabled ? '켜짐' : '꺼짐', 'badge badge--internal'));
      if (a.next_run_at) card.append(el('p', '다음 실행 · ' + dateText(a.next_run_at), 'help'));
      if (a.last_result) card.append(el('p', '최근 결과 · ' + statusText(a.last_result.status) + ' · ' + dateText(a.last_result.started_at), 'help'));
      if (a.scope_page_id) { const source = el('a', '묶인 원본 열기'); source.href = base + '/p/' + a.scope_page_id; card.append(source); }
      const preview = el('div'); card.append(preview);
      card.append(button('시험 실행', () => action(card, msg, async () => {
        const result = await request(api, 'POST', '/api/automations/' + a.id + '/dry-run', {}); previewView(preview, result);
        if (result.status === 'ok') preview.append(button('켜기', () => action(card, msg, async () => {
          await request(api, 'PATCH', '/api/automations/' + a.id, { is_enabled: true }); await load();
        }), 'btn'));
      })));
      card.append(button('끄기', () => action(card, msg, async () => {
        await request(api, 'PATCH', '/api/automations/' + a.id, { is_enabled: false }); await load();
      })));
      card.append(button('실행 기록', () => action(card, msg, async () => {
        const result = await request(api, 'GET', '/api/automations/' + a.id + '/runs'); preview.replaceChildren();
        if (!result.runs.length) preview.append(el('p', '실행 기록이 없습니다.', 'help'));
        for (const run of result.runs) {
          const details = el('details', '', 'card');
          details.append(el('summary', dateText(run.started_at) + ' · ' + statusText(run.status) + (run.dry_run ? ' · 시험' : '')), stepsView(run.steps));
          if (run.undone_at) details.append(el('p', dateText(run.undone_at) + '에 되돌렸습니다.', 'help'));
          else if (!run.dry_run && Date.now() - new Date(run.started_at).getTime() <= 7 * 86400000) details.append(button('이 실행 되돌리기', () => action(card, msg, async () => {
            const result = await request(api, 'POST', '/api/automation-runs/' + run.id + '/undo', {});
            await load(); msg.textContent = '휴지통으로 옮긴 문서 ' + result.deleted_pages.length + '개 · 변경되어 보존한 문서 ' + result.skipped.length + '개';
          }), 'btn btn--danger'));
          preview.append(details);
        }
      })));
      list.append(card);
    }
  }
  load().catch(error => { msg.textContent = failure(error); });
}
