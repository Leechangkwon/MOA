(() => {
  'use strict';
  const modal = document.getElementById('community-messenger');
  if (!modal) return;
  const $ = (id) => document.getElementById('messenger-' + id);
  const base = modal.dataset.base;
  let mode = 'people', selected = '', csrf = '', enabled = false, searchTimer, searchVersion = 0, refreshBusy = false, generation = 0, fromStudio = false;
  let messages = [], conversations = [], turns = [], hasMore = false;
  const drafts = new Map(), pending = new Map();
  const key = () => mode === 'gpt' ? 'gpt' : selected;
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
  const el = (tag, text, cls) => { const n = document.createElement(tag); if (text !== undefined) n.textContent = text; if (cls) n.className = cls; return n; };
  const status = (text) => { $('status').textContent = text; };
  function remember() { if (key()) { const old = drafts.get(key()); drafts.set(key(), { text: $('body').value, request: old?.text === $('body').value ? old.request : uuid() }); } }
  function composer() { const k = key(); $('form').hidden = mode === 'people' && !selected; $('body').maxLength = mode === 'gpt' ? 6000 : 4000; $('body').value = drafts.get(k)?.text || ''; $('send').disabled = pending.has(k) || !csrf || (mode === 'gpt' && !enabled); $('body').readOnly = pending.has(k); $('send').textContent = pending.has(k) ? '전송 중…' : '보내기'; }
  async function api(path = '', options = {}) {
    const response = await fetch(base + '/api/messenger' + path, { credentials:'same-origin', ...options, headers:{ Accept:'application/json', ...(options.body ? { 'Content-Type':'application/json', 'X-CSRF-Token':csrf } : {}) } });
    let data; try { data = await response.json(); } catch { throw new Error('서버에 연결하지 못했어요. 잠시 뒤 다시 시도해 주세요.'); }
    if (!response.ok) throw new Error(response.status === 401 ? '로그인이 만료됐어요. 다시 로그인해 주세요.' : data.error || '메신저에 연결하지 못했어요.');
    if (data.csrf_token) csrf = data.csrf_token;
    return data;
  }
  function bubble(text, mine, at, gpt = false) {
    const n = el('article', undefined, 'messenger-bubble' + (mine ? ' mine' : '')); n.append(el('p', text));
    if (at) { const t = el('time', new Intl.DateTimeFormat('ko-KR', { month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' }).format(new Date(at))); t.dateTime = at; n.append(t); }
    if (gpt) {
      const copy = el('button', '복사'); copy.type = 'button'; copy.addEventListener('click', async () => { try { await navigator.clipboard.writeText(text); status('답변을 복사했어요.'); } catch { status('답변을 길게 눌러 복사해 주세요.'); } }); n.append(copy);
      if (fromStudio) { const apply = el('button', '꾸미기 화면으로'); apply.type = 'button'; apply.addEventListener('click', () => { close(); window.dispatchEvent(new CustomEvent('qaredi:gpt-result', { detail:{ text } })); }); n.append(apply); }
    }
    return n;
  }
  function renderMessages(older = false) {
    const list = $('messages'), previous = list.scrollHeight, top = list.scrollTop;
    const bottom = previous - top - list.clientHeight < 70;
    list.replaceChildren(...messages.map((m) => bubble(m.body, m.mine, m.created_at)));
    if (!messages.length) list.append(el('p', '첫 인사를 남겨 보세요.', 'messenger-note'));
    $('older').hidden = !hasMore;
    if (older) list.scrollTop = top + list.scrollHeight - previous;
    else if (bottom) list.scrollTop = list.scrollHeight;
  }
  function renderGpt() {
    const list = $('gpt-messages'); list.replaceChildren();
    for (const t of turns) list.append(bubble(t.prompt, true, t.created_at), bubble(t.reply, false, t.created_at, true));
    if (!turns.length) list.append(el('p', '“오늘 여행에서 느낀 마음을 글로 남기고 싶어.” 편하게 이야기를 시작해 보세요.', 'messenger-note'));
    list.scrollTop = list.scrollHeight;
  }
  function renderConversations() {
    $('conversations').replaceChildren(...conversations.map((c) => {
      const n = el('button', undefined, 'messenger-contact'); n.type = 'button'; n.setAttribute('aria-current', String(c.id === selected));
      n.append(el('strong', (c.display_name || c.username) + (Number(c.unread) ? ' · ' + c.unread : '')), el('small', c.preview || '첫 메시지를 남겨 보세요.'));
      n.addEventListener('click', () => select(c.id)); return n;
    }));
    if (!conversations.length) $('conversations').append(el('p', '위에서 회원을 찾아 이야기를 시작해 보세요.', 'messenger-note'));
    const count = conversations.reduce((sum, c) => sum + Number(c.unread || 0), 0);
    document.querySelectorAll('[data-message-unread]').forEach((n) => { n.textContent = String(count); n.hidden = count === 0; });
  }
  async function read(id) {
    if (!modal.open || document.hidden || mode !== 'people' || selected !== id || !messages.length) return;
    await api('/conversations/' + id + '/read', { method:'POST', body:JSON.stringify({ last_id:messages[messages.length - 1].id }) });
  }
  async function refresh() {
    if (refreshBusy || !modal.open || document.hidden) return;
    refreshBusy = true; const g = generation, id = selected;
    try {
      const data = await api();
      if (g !== generation) return;
      conversations = data.conversations; renderConversations();
      if (mode === 'people' && id && id === selected) {
        const data = await api('/conversations/' + id);
        if (g !== generation || id !== selected || mode !== 'people') return;
        const known = new Map(messages.map((m) => [m.id, m])); data.messages.forEach((m) => known.set(m.id, m));
        messages = Array.from(known.values()).sort((a,b) => Number(a.id) - Number(b.id)); renderMessages(); await read(id);
      }
    } catch (e) { if (g === generation) status(e.message); }
    finally { refreshBusy = false; composer(); }
  }
  async function select(id) {
    remember(); selected = id; mode = 'people'; generation++; const g = generation;
    messages = []; hasMore = false; $('peer').textContent = '대화를 불러오는 중…'; modal.classList.add('has-conversation'); renderConversations(); renderMessages(); composer(); status('대화를 불러오고 있어요…');
    try { const data = await api('/conversations/' + id); if (g !== generation) return; messages = data.messages; hasMore = data.has_more; $('peer').textContent = data.peer.display_name || data.peer.username; renderMessages(); $('messages').scrollTop = $('messages').scrollHeight; status(''); await read(id); }
    catch (e) { if (g === generation) status(e.message); }
  }
  async function switchMode(next) {
    remember(); mode = next; generation++; const g = generation;
    $('people').hidden = mode !== 'people'; $('gpt').hidden = mode !== 'gpt'; status('');
    for (const m of ['people','gpt']) { $(m + '-tab').setAttribute('aria-selected', String(m === mode)); $(m + '-tab').tabIndex = m === mode ? 0 : -1; }
    composer();
    if (mode === 'gpt') {
      try { const data = await api('/gpt'); if (g !== generation) return; enabled = data.enabled; turns = data.turns; $('gpt-unavailable').hidden = enabled; renderGpt(); composer(); }
      catch (e) { if (g === generation) status(e.message); }
    } else await refresh();
  }
  async function open(detail = {}) {
    if (!modal.open) modal.showModal();
    if (detail.mode === 'gpt') {
      fromStudio = typeof detail.prompt === 'string';
      await switchMode('gpt');
      if (typeof detail.prompt === 'string') { remember(); const old = drafts.get('gpt')?.text || ''; drafts.set('gpt', { text:((old ? old + '\n\n' : '') + detail.prompt).slice(0, 6000), request:uuid() }); composer(); }
    } else { composer(); await (mode === 'gpt' ? switchMode('gpt') : refresh()); }
  }
  function close() { remember(); generation++; modal.close(); }
  document.querySelectorAll('[data-messenger-open]').forEach((n) => n.addEventListener('click', () => open()));
  window.addEventListener('qaredi:messenger', (event) => open(event.detail || {}));
  $('close').addEventListener('click', close); modal.addEventListener('cancel', remember);
  modal.addEventListener('click', (e) => { if (e.target === modal) { const r = modal.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close(); } });
  ['people','gpt'].forEach((m) => $(m + '-tab').addEventListener('click', () => switchMode(m)));
  modal.querySelector('[role=tablist]').addEventListener('keydown', (e) => { if (['ArrowLeft','ArrowRight','Home','End'].includes(e.key)) { e.preventDefault(); const next = e.key === 'Home' ? 'people' : e.key === 'End' ? 'gpt' : mode === 'people' ? 'gpt' : 'people'; switchMode(next); $(next + '-tab').focus(); } });
  $('back').addEventListener('click', () => { remember(); selected = ''; generation++; modal.classList.remove('has-conversation'); composer(); refresh(); });
  $('body').addEventListener('input', remember);
  $('body').addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !$('send').disabled) { e.preventDefault(); $('form').requestSubmit(); } });
  $('search').addEventListener('input', () => {
    clearTimeout(searchTimer); const version = ++searchVersion, q = $('search').value.trim(); $('members').replaceChildren(); if (q.length < 2) return;
    searchTimer = setTimeout(async () => {
      try { const data = await api('/members?q=' + encodeURIComponent(q)); if (version !== searchVersion) return;
        $('members').replaceChildren(...data.members.map((m) => { const n = el('button', (m.display_name || m.username) + ' (@' + m.username + ')', 'messenger-contact'); n.type = 'button'; n.addEventListener('click', async () => { n.disabled = true; const requestedGeneration = generation; try { const result = await api('/conversations', { method:'POST', body:JSON.stringify({ username:m.username }) }); if (requestedGeneration !== generation || mode !== 'people') return; $('search').value = ''; $('members').replaceChildren(); await select(result.id); await refresh(); } catch (e) { status(e.message); } finally { n.disabled = false; } }); return n; }));
        if (!data.members.length) $('members').append(el('p','일치하는 회원이 없어요.','messenger-note'));
      } catch (e) { if (version === searchVersion) status(e.message); }
    }, 300);
  });
  $('older').addEventListener('click', async () => {
    const id = selected, g = generation; if (!id || !messages.length) return; $('older').disabled = true;
    try { const data = await api('/conversations/' + id + '?before=' + messages[0].id); if (g !== generation) return; messages = data.messages.concat(messages); hasMore = data.has_more; renderMessages(true); }
    catch (e) { if (g === generation) status(e.message); } finally { $('older').disabled = false; }
  });
  $('form').addEventListener('submit', async (e) => {
    e.preventDefault(); remember(); const k = key(), draft = drafts.get(k), targetMode = mode;
    if (!k || pending.has(k) || !draft?.text.trim() || !csrf || (mode === 'gpt' && !enabled)) return;
    pending.set(k, true); composer(); status(mode === 'gpt' ? 'GPT가 답변을 작성하고 있어요…' : '전송 중…');
    try {
      const data = await api(targetMode === 'gpt' ? '/gpt' : '/conversations/' + k + '/messages', { method:'POST', body:JSON.stringify({ body:draft.text, request_id:draft.request }) });
      drafts.delete(k);
      if (targetMode === 'gpt') { if (!turns.some((t) => String(t.id) === String(data.turn.id))) turns.push(data.turn); if (mode === 'gpt') renderGpt(); }
      else if (selected === k) { if (!messages.some((m) => m.id === data.message.id)) messages.push(data.message); renderMessages(); $('messages').scrollTop = $('messages').scrollHeight; await read(k); }
      if (key() === k) status('');
    } catch (err) { if (key() === k) status(err.message + ' 작성한 내용은 남아 있어요.'); }
    finally { pending.delete(k); composer(); }
  });
  setInterval(() => { if (mode === 'people') refresh(); }, 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden && mode === 'people') refresh(); });
})();
