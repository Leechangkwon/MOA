'use strict';

// Uses the same authenticated session and CSRF helper as the human messenger.
// Q:F-11 (지시서 A): the messenger module may pass `drafts` — a Map-like {get,set,delete} keyed by
// thread number (0 = new conversation) that keeps the text encrypted in this browser (without it, memory only) —
// and `onState`, told when the open thread is known (loaded, or created by the first answer) so the tab can resume it.
window.MoaAiConversation = function ({api, me, say, node, date, activate, drafts: store, onState}) {
  const $ = id => document.getElementById(id);
  let active = false, generation = 0, thread = null, oldest = null, ready = false;
  let sending = false, loading = false, pending = false, lastAttempt = null, optimistic = null;
  let refreshedAt = 0, filled = false;
  let turns = new Map();
  const drafts = store || new Map();
  const notify = () => { if (typeof onState === 'function') onState(); };
  const key = () => thread || 0;

  function controls() {
    if (!active) return;
    $('message-text').disabled = !ready || sending || pending;
    $('message-send').disabled = !ready || sending || pending;
    $('message-send').textContent = sending || pending ? '답변 작성 중…' : '보내기 ↑';
    $('message-ai-new').disabled = sending;
    $('message-ai-threads').disabled = sending;
  }
  function bubble(mine, text, time, state) {
    const box = node('article', undefined, 'moa-bubble' + (mine ? ' moa-bubble--mine' : ' moa-bubble--ai'));
    box.append(node('strong', mine ? '나 · ' + me.username : '✦ 모아 AI · Gemini 3.5', 'moa-message-author'));
    box.append(node('p', text));
    if (time) box.append(node('small', date(time) + (state ? ' · ' + state : '')));
    return box;
  }
  function empty() {
    const box = node('div', undefined, 'moa-empty moa-ai-welcome');
    box.append(node('span', '✦', 'moa-ai-orb'), node('p', 'YOUR LITTLE THINKING PARTNER', 'moa-kicker'));
    box.append(node('h3', '같이 생각하면, 조금 더 쉬워요.'), node('p', ready ? '생각을 정리하거나 문장을 다듬고 싶을 때, 편하게 물어보세요.' : '관리자가 Gemini 연결을 마치면 여기서 바로 대화할 수 있어요.'));
    const choices = node('div', undefined, 'moa-ai-prompts');
    for (const [title, prompt] of [
      ['✎ 문장 다듬기', '문장을 자연스럽고 친절하게 다듬고 싶어. 원문을 보내면 도와줄래?'],
      ['☀ 작은 아이디어', '오늘 커뮤니티에 올릴 가볍고 기분 좋은 대화 주제 5개를 제안해 줘.'],
      ['☷ 생각 정리하기', '머릿속 생각을 정리하고 싶어. 하나씩 질문하면서 정리를 도와줘.']
    ]) {
      const button = node('button', title, 'btn btn--quiet');
      button.type = 'button'; button.disabled = !ready;
      button.addEventListener('click', () => { $('message-text').value = prompt; $('message-text').focus(); });
      choices.append(button);
    }
    box.append(choices, node('small', 'AI는 이 대화에 직접 입력한 내용만 참고해요. 최근 대화를 바탕으로 답하며 중요한 내용은 다시 확인해 주세요.'));
    return box;
  }
  function render(scroll = true, older = false) {
    if (!active) return;
    const list = $('message-list'), previousHeight = list.scrollHeight, previousTop = list.scrollTop;
    list.replaceChildren();
    for (const turn of [...turns.values()].sort((a, b) => a.id - b.id)) {
      list.append(bubble(true, turn.prompt, turn.created_at, '전송됨'));
      if (turn.state === 'complete') list.append(bubble(false, turn.answer, turn.finished_at, ''));
      else if (turn.state === 'failed') {
        const error = bubble(false, turn.error, turn.finished_at, '응답 실패');
        const retry = node('button', '질문을 다시 입력하기', 'btn btn--quiet btn--small');
        retry.type = 'button';
        retry.addEventListener('click', () => { $('message-text').value = turn.prompt; $('message-text').focus(); });
        error.append(retry); list.append(error);
      } else list.append(bubble(false, '생각을 모으고 있어요…', null, ''));
    }
    if (optimistic && ![...turns.values()].some(t => t.request_id === optimistic.request_id)) {
      list.append(bubble(true, optimistic.text, new Date().toISOString(), '전송 중'));
      list.append(bubble(false, '생각을 모으고 있어요…', null, ''));
    }
    if (!turns.size && !optimistic) list.append(empty());
    if (scroll) list.scrollTop = list.scrollHeight;
    else list.scrollTop = older ? previousTop + list.scrollHeight - previousHeight : previousTop;
  }
  async function load(older = false, force = false) {
    if (!active || loading || (document.hidden && !older)) return;
    if (!older && !force && !pending && Date.now() - refreshedAt < 30000) return;
    loading = true;
    const ticket = generation;
    const list = $('message-list'), nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 100;
    try {
      const data = await api('/api/messages/ai?thread=' + (thread || 0) + (older && oldest ? '&before=' + oldest : ''), 'GET', undefined, false, true);
      if (!active || ticket !== generation) return;
      apply(data, older, nearBottom);
    } finally { loading = false; }
  }
  function apply(data, older = false, nearBottom = true) {
    refreshedAt = Date.now();
    ready = data.ready; thread = data.thread ? Number(data.thread.id) : null;
    $('message-ai-remaining').textContent = `오늘 ${data.remaining}/${data.user_daily}회 남음`;
    const select = $('message-ai-threads'); select.replaceChildren();
    const fresh = node('option', '＋ 새 대화'); fresh.value = '0'; select.append(fresh);
    for (const item of data.threads) {
      const option = node('option', item.title); option.value = String(item.id); select.append(option);
    }
    if (thread && !data.threads.some(t => Number(t.id) === thread)) {
      const option = node('option', data.thread.title); option.value = String(thread); select.append(option);
    }
    select.value = String(thread || 0);
    let changed = !turns.size;
    for (const turn of data.turns) {
      const previous = turns.get(turn.id);
      if (!previous || previous.state !== turn.state || previous.answer !== turn.answer) changed = true;
      turns.set(turn.id, turn);
    }
    if (turns.size) oldest = Math.min(...turns.keys());
    if (older || turns.size <= 30) $('message-older').hidden = !data.has_more;
    pending = [...turns.values()].some(t => t.state === 'pending');
    controls();
    if (changed) render(!older && nearBottom, older);
  }
  async function open(id) {
    if (sending) { say('AI 답변을 받고 있어요. 답변이 도착한 뒤 AI 대화를 열어 주세요.'); return false; }
    // Save only a composer that was filled for this thread (a switch before loading must not blank a saved draft).
    if (active && filled) drafts.set(key(), $('message-text').value);
    active = true; filled = false; generation++; const ticket = generation;
    thread = id === undefined ? null : (Number(id) || null);
    oldest = null; pending = false; ready = false; turns = new Map(); optimistic = null;
    activate();
    $('message-ai').setAttribute('aria-pressed', 'true');
    $('message-ai-new').hidden = false; $('message-ai-tools').hidden = false; $('message-ai-notice').hidden = false;
    $('message-peer-name').textContent = '모아 AI';
    $('message-peer-meta').textContent = 'Gemini 3.5 Flash · 내 계정의 AI 대화';
    $('message-older').hidden = true;
    $('message-text').value = ''; $('message-text').placeholder = '모아 AI에게 물어보세요. Enter로 전송, Shift+Enter로 줄바꿈';
    $('message-list').replaceChildren(node('p', 'AI 대화를 불러오는 중…', 'moa-empty'));
    say(''); controls();
    try {
      const data = await api('/api/messages/ai' + (id === undefined ? '' : '?thread=' + (Number(id) || 0)), 'GET', undefined, false, true);
      if (!active || ticket !== generation) return false;
      apply(data); $('message-text').value = drafts.get(key()) || ''; filled = true;
      if (!data.ready) say(data.notice);
      notify();
      return true;
    } catch (e) { if (active && ticket === generation) say(e.message); return false; }
  }
  function close() {
    if (active && filled) drafts.set(key(), $('message-text').value);
    active = false; filled = false; generation++;
    $('message-ai').setAttribute('aria-pressed', 'false');
    $('message-ai-new').hidden = true; $('message-ai-tools').hidden = true; $('message-ai-notice').hidden = true;
    $('message-send').textContent = '보내기 ↑';
    $('message-text').value = ''; $('message-text').placeholder = '메시지를 입력하세요. Enter로 전송, Shift+Enter로 줄바꿈';
  }
  async function send() {
    if (!active || !ready || sending || pending) return;
    const text = $('message-text').value.trim(); if (!text) return;
    const ticket = generation, originalThread = thread;
    if (!lastAttempt || lastAttempt.text !== text || lastAttempt.thread_id !== thread) {
      lastAttempt = {text, thread_id: thread, request_id: crypto.randomUUID()};
    }
    const attempt = lastAttempt;
    sending = true; optimistic = attempt; controls(); render(); say('');
    try {
      const result = await api('/api/messages/ai', 'POST', attempt);
      lastAttempt = null;
      if (!active || ticket !== generation) return;
      const turn = result.turn; thread = turn.thread_id; optimistic = null; turns.set(turn.id, turn); notify();
      pending = turn.state === 'pending';
      if (turn.state !== 'failed') { $('message-text').value = ''; drafts.delete(originalThread || 0); }
      render(); await load(false, true);
      if (turn.state === 'failed') say(turn.error);
    } catch (e) {
      if (e.status && e.status < 500) lastAttempt = null;
      if (active && ticket === generation) { optimistic = null; render(); say(e.message + ' 입력한 질문은 보관했어요.'); }
      // Keep the request UUID after an uncertain HTTP result; a manual retry cannot double-charge.
    } finally {
      sending = false; optimistic = null; controls();
    }
  }
  $('message-ai').addEventListener('click', () => open());
  $('message-ai-new').addEventListener('click', () => open(0));
  $('message-ai-threads').addEventListener('change', e => open(Number(e.target.value)));
  // Q:F-11 restore hooks: read the current thread / reopen a saved one (null = new conversation screen).
  // setState resolves true when that thread was loaded (open() resolves the same; existing callers ignore it).
  const getState = () => ({active, thread});
  const setState = ({thread: saved} = {}) => open(saved === null || saved === undefined ? 0 : saved);
  return {get active() {return active;}, open, close, load, send, getState, setState};
};
