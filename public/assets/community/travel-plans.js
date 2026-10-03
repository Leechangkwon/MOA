(() => {
  'use strict';
  const root = document.getElementById('community-threads'), dialog = document.getElementById('travel-dialog');
  if (!root || !dialog || root.dataset.shared) return;
  const $ = (id) => document.getElementById(id);
  const base = root.dataset.base, copy = (value) => value ? JSON.parse(JSON.stringify(value)) : value;
  const loadingPlans = new Set();
  let plans = [], active = null, csrf = '', steps = [], selection = null, requestId = '', editorBusy = false, disabledBeforeBusy = new Map(), dirty = false, noticeTimer, pendingAction = null, confirmFocus = null;
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
  function node(tag, text, cls) { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; }
  function action(text, run, cls = 'secondary') { const n = node('button', text, cls); n.type = 'button'; n.addEventListener('click', run); return n; }
  function notice(message, persistent = false) { if (dialog.open) $('travel-status').textContent = message; clearTimeout(noticeTimer); $('travel-notice').textContent = message; $('travel-notice').hidden = false; if (!persistent) noticeTimer = setTimeout(() => { $('travel-notice').hidden = true; }, 8000); }
  async function request(path, options = {}) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(base + path, { credentials: 'same-origin', ...options, signal: controller.signal });
      let data;
      try { data = await response.json(); } catch (error) {
        if (controller.signal.aborted) throw error;
        throw new Error('서버 응답을 확인할 수 없어요. 작성 중인 내용은 그대로 남아 있어요.');
      }
      if (!response.ok) throw new Error(data.error || '계획을 처리하지 못했어요. 다시 로그인하거나 잠시 뒤 시도해 주세요.');
      return data;
    } catch (error) {
      if (controller.signal.aborted) throw new Error('응답이 늦어 요청을 멈췄어요. 작성한 내용은 남아 있어요. 연결을 확인하고 다시 시도해 주세요.');
      if (error instanceof TypeError) throw new Error('연결을 확인해 주세요. 작성 중인 계획은 이 창에 남아 있어요.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  async function api(path = '', method = 'GET', body) {
    return request('/api/travel-plans' + path, { method, headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(method !== 'GET' ? { 'X-CSRF-Token': csrf } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  }
  function syncBusyControls() {
    if (!editorBusy) return;
    dialog.querySelectorAll('button,input,textarea,select').forEach((control) => {
      if (!disabledBeforeBusy.has(control)) { disabledBeforeBusy.set(control, control.disabled); control.disabled = true; }
    });
  }
  function setBusy(value) {
    editorBusy = value;
    if (value) {
      disabledBeforeBusy = new Map(); syncBusyControls(); dialog.setAttribute('aria-busy', 'true');
    } else {
      disabledBeforeBusy.forEach((wasDisabled, control) => { control.disabled = wasDisabled; });
      disabledBeforeBusy.clear(); dialog.removeAttribute('aria-busy');
    }
  }
  new MutationObserver(syncBusyControls).observe(dialog, { childList: true, subtree: true });
  function askInline(message, accept, run) {
    confirmFocus = document.activeElement; pendingAction = run;
    $('travel-confirm-message').textContent = message;
    $('travel-confirm-accept').textContent = accept;
    $('travel-confirm-cancel').textContent = accept === '계획 삭제' ? '취소' : '계속 작성';
    $('travel-confirm').hidden = false; setBusy(true);
    $('travel-confirm-cancel').disabled = false; $('travel-confirm-accept').disabled = false;
    $('travel-confirm').scrollIntoView({ block: 'nearest' }); $('travel-confirm-cancel').focus();
  }
  function cancelInline() {
    pendingAction = null; $('travel-confirm').hidden = true; setBusy(false);
    if (confirmFocus?.isConnected) confirmFocus.focus();
  }
  function leaveEditor(run) {
    if (editorBusy) return;
    if (!dirty) { run(); return; }
    askInline('저장하지 않은 계획이 있어요. 작성 중인 내용을 버리고 이동할까요?', '저장하지 않고 이동', () => {
      if (active) fill(active); else { fill(null); $('travel-form').hidden = true; }
      run();
    });
  }
  async function refresh() {
    const result = await api(); plans = result.plans; csrf = result.csrf_token || ''; renderList(); renderChoice();
    $('travel-status').textContent = plans.length ? `내 여행 ${plans.length}개 · 계획은 나만 볼 수 있어요` : '첫 여행을 계획해 보세요. 날짜가 정해지지 않아도 좋아요.';
  }
  function renderList() { $('travel-list').replaceChildren(...plans.map((plan) => action(`${plan.title} · ${plan.destination || '목적지 미정'} · ${plan.done_count ?? (plan.steps || []).filter((s) => s.status === 'done').length}/${plan.step_count ?? (plan.steps || []).length} 다녀옴`, () => openPlan(plan.id)))); }
  function openPlan(id) {
    leaveEditor(async () => {
      setBusy(true);
      try { const data = await api('/' + id); fill(data.plan); } catch (e) { $('travel-status').textContent = e.message; } finally { setBusy(false); }
    });
  }
  function fill(plan) {
    active = plan; steps = copy(plan?.steps || []); requestId = uuid(); dirty = false;
    $('travel-form').hidden = false; $('travel-title').value = plan?.title || ''; $('travel-destination').value = plan?.destination || '';
    $('travel-start').value = plan?.starts_on || ''; $('travel-end').value = plan?.ends_on || ''; $('travel-notes').value = plan?.notes || '';
    $('travel-error').textContent = ''; $('travel-delete').hidden = !plan; $('travel-record').hidden = !plan; $('travel-linked').hidden = !plan;
    $('travel-existing').replaceChildren(node('option', '내 기록을 불러와 골라 주세요')); $('travel-existing').firstChild.value = '';
    renderSteps(); renderRecords(); renderPrompt();
  }
  function field(labelText, input) { const label = node('label', undefined, 'field'); label.append(document.createTextNode(labelText), input); return label; }
  function input(value, type, max, onChange) { const n = node('input'); n.type = type; n.value = value || ''; if (max) n.maxLength = max; n.addEventListener('input', () => { onChange(n.value); dirty = true; renderPrompt(); }); return n; }
  function renderSteps() {
    $('travel-steps').replaceChildren(...steps.map((step, index) => {
      const card = node('section', undefined, 'travel-step');
      const title = input(step.title, 'text', 160, (value) => { step.title = value; }); title.required = true;
      card.append(field(`일정 ${index + 1}`, title));
      const row = node('div', undefined, 'field-row'), status = node('select');
      [['planned', '예정'], ['done', '다녀옴'], ['skipped', '건너뜀']].forEach(([value, label]) => { const option = node('option', label); option.value = value; status.append(option); });
      status.value = step.status; status.addEventListener('change', () => { step.status = status.value; dirty = true; });
      row.append(field('날짜 (선택)', input(step.date, 'date', null, (value) => { step.date = value || null; })), field('진행', status)); card.append(row);
      const notes = node('textarea'); notes.value = step.notes; notes.maxLength = 2000; notes.rows = 2; notes.addEventListener('input', () => { step.notes = notes.value; dirty = true; }); card.append(field('변경 이유·실제 경험', notes));
      const tools = node('div', undefined, 'travel-step-tools');
      const move = (offset) => { [steps[index], steps[index + offset]] = [steps[index + offset], steps[index]]; dirty = true; renderSteps(); };
      const up = action('↑ 위로', () => move(-1)), down = action('↓ 아래로', () => move(1)); up.disabled = index === 0; down.disabled = index === steps.length - 1;
      tools.append(up, down, action('일정 빼기', () => { steps.splice(index, 1); dirty = true; renderSteps(); }));
      if (active) tools.append(action('이 순간 기록', () => recordFor(step.id)));
      card.append(tools); return card;
    }));
  }
  function renderRecords() {
    $('travel-records').replaceChildren(...(active?.records || []).map((record) => {
      const row = node('div', undefined, 'travel-record-row'), link = node('a', record.caption || record.location || record.taken_on || '여행 기록');
      link.href = base + '/memories/p/' + record.post_id;
      row.append(link, action('연결 해제', async () => { if (editorBusy) return; const planId = active.id; setBusy(true); try { const result = await api('/' + planId + '/records', 'DELETE', { post_id: record.post_id }); if (active?.id === planId) { active.records = result.plan.records; renderRecords(); } } catch (e) { $('travel-error').textContent = e.message; } finally { setBusy(false); } })); return row;
    }));
  }
  function planBody() { return { title: $('travel-title').value, destination: $('travel-destination').value, starts_on: $('travel-start').value || null, ends_on: $('travel-end').value || null, notes: $('travel-notes').value, steps, ...(active ? { revision: active.revision } : { request_id: requestId }) }; }
  async function save(event) {
    event?.preventDefault(); if (editorBusy) return;
    setBusy(true); $('travel-error').textContent = '';
    try { const result = await api(active ? '/' + active.id : '', active ? 'PATCH' : 'POST', planBody()); await refresh(); fill(result.plan); notice('여행 계획을 저장했어요. 일정은 언제든 바꿀 수 있어요.'); }
    catch (e) { $('travel-error').textContent = e.message; }
    finally { setBusy(false); }
  }
  async function recordFor(stepId = null) {
    if (editorBusy) return;
    if (dirty) { $('travel-error').textContent = '바뀐 계획을 먼저 저장한 뒤 기록해 주세요.'; return; }
    if (!active) return;
    const value = { plan_id: active.id, step_id: stepId }; dialog.close();
    const opened = await window.MemoryComposer?.open();
    if (opened === false || !document.getElementById('compose-dialog')?.open) return;
    // Set itinerary after draft recovery and composer initialization have completed.
    selection = value; renderChoice(); document.getElementById('compose-form')?.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function renderChoice() {
    const host = $('travel-compose-selection'); if (!host) return;
    host.replaceChildren(); host.className = 'travel-compose-choice';
    if (!plans.length) { host.append(action('여행 계획과 함께 기록하기', openDialog)); return; }
    const select = node('select'); select.id = 'memory-travel-plan'; const empty = node('option', '여행 계획 연결 안 함'); empty.value = ''; select.append(empty);
    plans.forEach((plan) => { const option = node('option', plan.title); option.value = plan.id; select.append(option); });
    if (selection && !plans.some((p) => p.id === selection.plan_id)) { const missing = node('option', '이전에 선택한 계획 (불러오기 필요)'); missing.value = selection.plan_id; select.append(missing); }
    select.value = selection?.plan_id || '';
    select.addEventListener('change', () => { selection = select.value ? { plan_id: select.value, step_id: null } : null; renderChoice(); $('compose-form')?.dispatchEvent(new Event('input', { bubbles: true })); });
    host.append(field('여행 계획 (선택)', select));
    const plan = plans.find((p) => p.id === selection?.plan_id);
    if (plan && !plan.steps && !loadingPlans.has(plan.id)) { loadingPlans.add(plan.id); api('/' + plan.id).then((data) => { Object.assign(plan, data.plan); renderChoice(); }).catch((e) => notice(e.message)).finally(() => loadingPlans.delete(plan.id)); }
    if (plan?.steps) { const activity = node('select'); const whole = node('option', '여행 전체 · 계획에 없던 순간'); whole.value = ''; activity.append(whole); plan.steps.forEach((step) => { const option = node('option', step.title); option.value = step.id; activity.append(option); }); activity.value = selection.step_id || ''; activity.addEventListener('change', () => { selection.step_id = activity.value || null; $('compose-form')?.dispatchEvent(new Event('input', { bubbles: true })); }); host.append(field('어떤 일정의 기록인가요?', activity)); }
    host.append(node('p', '계획은 비공개로 유지되며, 이 글의 공개 범위는 아래에서 선택해요.', 'field-hint'));
  }
  async function attachRecord(postId, value = selection) {
    if (!value || !postId) return;
    if (!csrf) await refresh();
    await api('/' + value.plan_id + '/records', 'POST', { post_id: postId, step_id: value.step_id || null });
  }
  function renderPrompt() { $('travel-ai-prompt').value = `나만의 여행 계획을 함께 다듬어 주세요. 여행: ${$('travel-title').value || '아직 미정'}. 장소: ${$('travel-destination').value || '추천해 주세요'}. 기간: ${$('travel-start').value || '미정'} ~ ${$('travel-end').value || '미정'}. ${$('travel-notes').value}\n무리하지 않는 일정과 대안을 제안해 주세요. 운영시간·가격은 확인이 필요하면 표시하고, 내 확인을 거쳐 저장해 주세요.`; }
  async function openDialog() { if (editorBusy) return; if (!dialog.open) dialog.showModal(); setBusy(true); $('travel-status').textContent = '여행 계획을 불러오고 있어요…'; try { await refresh(); } catch (e) { $('travel-status').textContent = e.message; } finally { setBusy(false); } }
  root.querySelectorAll('[data-travel-open]').forEach((button) => button.addEventListener('click', openDialog));
  dialog.querySelector('[data-travel-close]').addEventListener('click', () => leaveEditor(() => dialog.close()));
  dialog.addEventListener('cancel', (event) => { event.preventDefault(); if (pendingAction) cancelInline(); else leaveEditor(() => dialog.close()); });
  $('travel-confirm-cancel').addEventListener('click', cancelInline);
  $('travel-confirm-accept').addEventListener('click', () => { const run = pendingAction; pendingAction = null; $('travel-confirm').hidden = true; setBusy(false); if (run) run(); });
  $('travel-new').addEventListener('click', () => leaveEditor(() => { fill(null); $('travel-title').focus(); }));
  $('travel-refresh').addEventListener('click', () => leaveEditor(() => { dirty = false; $('travel-form').hidden = true; $('travel-linked').hidden = true; openDialog(); }));
  $('travel-form').addEventListener('submit', save); $('travel-form').addEventListener('input', () => { dirty = true; renderPrompt(); });
  $('travel-add-step').addEventListener('click', () => { if (steps.length >= 100) { $('travel-error').textContent = '일정은 최대 100개예요.'; return; } steps.push({ id: uuid(), title: '', date: null, status: 'planned', notes: '' }); dirty = true; renderSteps(); });
  $('travel-record').addEventListener('click', () => recordFor());
  $('travel-delete').addEventListener('click', () => {
    if (editorBusy || !active) return;
    const planId = active.id;
    askInline('여행 계획을 삭제할까요? 연결했던 게시글과 사진은 그대로 남아요.', '계획 삭제', async () => {
      setBusy(true);
      try { await api('/' + planId, 'DELETE'); fill(null); $('travel-form').hidden = true; await refresh(); }
      catch (e) { $('travel-error').textContent = e.message; } finally { setBusy(false); }
    });
  });
  $('travel-load-posts').addEventListener('click', async () => { if (editorBusy) return; setBusy(true); try { const data = await request('/api/memories?view=mine', { headers: { Accept: 'application/json' } }); $('travel-existing').replaceChildren(node('option', '최근 내 기록을 고르세요')); $('travel-existing').firstChild.value = ''; (data.posts || []).forEach((post) => { const option = node('option', (post.caption || post.location || '사진 기록').slice(0, 80)); option.value = post.id; $('travel-existing').append(option); }); } catch (e) { $('travel-error').textContent = e.message; } finally { setBusy(false); } });
  $('travel-link').addEventListener('click', async () => { if (editorBusy || !active || !$('travel-existing').value) return; const planId = active.id; setBusy(true); try { const result = await api('/' + planId + '/records', 'POST', { post_id: $('travel-existing').value, step_id: null }); if (active?.id === planId) { active.records = result.plan.records; renderRecords(); } } catch (e) { $('travel-error').textContent = e.message; } finally { setBusy(false); } });
  $('travel-copy-prompt').addEventListener('click', async () => { renderPrompt(); try { await navigator.clipboard.writeText($('travel-ai-prompt').value); notice('요청문을 복사했어요. AI 채팅에 붙여 넣어 주세요.'); } catch { $('travel-ai-prompt').focus(); $('travel-ai-prompt').select(); notice('요청문을 선택했어요. 길게 눌러 복사해 주세요.'); } });
  window.MemoryTravel = { getSelection: () => copy(selection), setSelection: (value) => { selection = value && typeof value.plan_id === 'string' ? copy(value) : null; renderChoice(); if (!plans.length) refresh().catch(() => {}); }, attachRecord };
  window.addEventListener('memory:saved', async (event) => { const value = event.detail.selection || null; if (!value) return; try { await attachRecord(event.detail.postId, value); notice('기록을 여행 계획에 연결했어요.'); } catch (e) { notice('게시글은 저장됐지만 계획 연결은 완료하지 못했어요. 여행 계획에서 기존 기록을 연결해 주세요. ' + e.message, true); } });
  window.addEventListener('beforeunload', (event) => { if (dirty || editorBusy) { event.preventDefault(); event.returnValue = ''; } });
  renderPrompt(); renderChoice();
  if (new URLSearchParams(location.search).get('travel') === '1') openDialog();
})();
