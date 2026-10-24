'use strict';

// Q:F-11 (지시서 A): `changed(peer)` (optional) is told when a pending AI image attachment is set or removed,
// so the messenger module can keep the draft's attachment_id; `restore()` re-attaches a verified image after reload.
window.MoaMessageAssist = function ({api, node, context, say, changed}) {
  const $ = id => document.getElementById(id), dialog = $('message-assist-dialog');
  const notify = peer => { if (typeof changed === 'function') changed(peer); };
  const titles = {polish:'문장 다듬기', spelling:'맞춤법 검사', translate:'번역하기', image:'이미지 만들기'};
  const labels = {polish:'다듬을 문장', spelling:'맞춤법을 확인할 문장', translate:'번역할 문장', image:'어떤 이미지를 만들까요?'};
  const languages = {ko:'한국어', en:'영어', ja:'일본어', zh:'중국어', vi:'베트남어', es:'스페인어'};
  const attachments = new Map(), undos = new Map();
  // Q:F-11 (지시서 B-3 · 기획 4.6-A): the PC helper (loopback) is wired only on /messages, where moa-local-bridge.js is loaded
  // and that page's CSP allows it. In the floating window there is no bridge: 「내 PC의 ChatGPT」 shows one line and a link to the
  // full messenger instead — no code path here reaches the loopback address.
  const localBridge = typeof window.MoaLocalBridge === 'function' ? window.MoaLocalBridge(dialog.dataset.pcUser) : null;
  const bridgeNote = localBridge !== null ? null : (() => {
    const note = node('p', 'PC 비서 연결은 메신저 전체 화면에서 할 수 있습니다. ', 'moa-muted'), link = node('a', '크게 보기');
    link.href = document.body.dataset.base + '/messages';
    note.append(link); note.hidden = true; $('assist-local-task').before(note);
    return note;
  })();
  const bridgeConnected = () => localBridge !== null && localBridge.connected();
  let localBusy = false, connectionTimer = null;
  let epoch = 0, target = null, action = 'polish', source = null, sourceText = '', ready = false;
  let imageEnabled = false, running = false, waiting = false, result = null, attempt = null, jobs = [], timer = null;
  let draftAtOpen = '';
  let provider = 'gemini', originalAtOpen = '', editRevision = 0;
  const tell = text => { $('assist-status').textContent = text; };
  const matches = ticket => dialog.open && ticket === epoch && context().peer === target;
  const imagePrefix = document.body.dataset.base + '/api/messages/attachments/';
  const safeImage = path => typeof path === 'string' && path.startsWith(imagePrefix) && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(path.slice(imagePrefix.length));

  function buttons() {
    const image = action === 'image', bridgeless = provider === 'local' && localBridge === null;
    $('assist-local-state').textContent = bridgeConnected()
      ? 'PC 연결됨 · 웹에서 직접 누른 요청만 처리합니다.'
      : localBridge?.remembered() ? '기억한 PC · GPT 연결로 다시 연결할 수 있습니다.' : 'PC 연결 전 · GPT 연결 후 PC 승인창을 확인하세요.';
    $('assist-local-connect').disabled = running || waiting || bridgeConnected();
    $('assist-local-pair').disabled = running || waiting;
    $('assist-local-run').disabled = running || waiting || (!bridgeConnected() && !localBridge?.remembered()) || image;
    $('assist-local-unpair').disabled = !bridgeConnected() && !localBusy && !localBridge?.remembered();
    $('assist-local-cancel').disabled = !localBusy;
    const manual = provider !== 'gemini';
    for (const button of dialog.querySelectorAll('[data-assist-provider]')) {
      button.setAttribute('aria-pressed', String(button.dataset.assistProvider === provider));
      button.disabled = running || waiting;
    }
    for (const button of dialog.querySelectorAll('[data-assist-action]')) {
      button.setAttribute('aria-pressed', String(button.dataset.assistAction === action));
      button.disabled = running || waiting || (source !== null && button.dataset.assistAction !== 'translate');
    }
    $('assist-language-field').hidden = action !== 'translate';
    $('assist-aspect-field').hidden = !image;
    $('assist-input-label').textContent = labels[action];
    $('assist-input').readOnly = source !== null || running || waiting;
    $('assist-language').disabled = running || waiting;
    $('assist-aspect').disabled = running || waiting;
    $('assist-history').disabled = running;
    $('assist-history-field').hidden = manual;
    $('assist-gemini-run').hidden = manual;
    if ($('assist-settings')) $('assist-settings').hidden = manual;
    $('assist-local').hidden = provider !== 'local';
    $('assist-external').hidden = provider !== 'web';
    $('assist-manual-result').hidden = !manual || image || bridgeless;
    $('assist-local-image-note').hidden = !image;
    $('assist-local-text-steps').hidden = image || bridgeless;
    $('assist-local-task').hidden = bridgeless;
    if (bridgeNote !== null) bridgeNote.hidden = !bridgeless;
    $('assist-local-task').textContent = image ? 'PC 비서에서는 문장 다듬기·맞춤법·번역을 사용할 수 있습니다.' : 'PC 비서에서 선택할 작업: ' + titles[action] + (action === 'translate' ? ' · 목표 언어: ' + languages[$('assist-language').value] : ' · 원문의 언어 유지');
    $('assist-disclosure').textContent = bridgeless ? 'PC 비서 연결은 메신저 전체 화면에서 할 수 있습니다.'
      : provider === 'local'
      ? 'PC 연결 후 “GPT로 처리”를 누르면 위 원문·작업·목표 언어만 내 PC로 보냅니다. 처음 연결에서 허용한 브라우저는 추가 PC 확인 없이 OpenAI가 처리합니다. 코드 연결은 매번 PC 확인이 필요합니다. 결과는 미리보기로 돌아오며 상대에게 자동으로 보내지 않습니다. 직접 복사도 가능합니다.'
      : manual
      ? '직접 복사·붙여 넣는 방식입니다. 모아는 PC나 ChatGPT의 연결 상태를 확인하지 않으며, 위 원문을 자동으로 보내지 않습니다.'
      : '위 내용만 Google AI로 보냅니다. 무료 API에는 개인정보·기밀을 입력하지 마세요. 이미지는 별도 유료 기능이며 결과를 확인한 뒤 첨부합니다.';
    $('assist-restore-input').disabled = running || waiting || source !== null || $('assist-input').value === originalAtOpen;
    counts();
    $('assist-source-note').hidden = source === null;
    $('assist-run').textContent = running || waiting ? 'AI가 준비하고 있어요…' : image ? '이미지 만들기 · 유료' : titles[action];
    $('assist-run').disabled = manual || running || waiting || !ready || (image && !imageEnabled);
    $('assist-input').placeholder = image ? '예: 고생한 동료에게 보낼 따뜻한 수채화 감사 카드. 노란 꽃과 커피, 문구는 “오늘도 고마워요”.' : '동료에게 전하고 싶은 내용을 적어 주세요.';
  }
  function counts() {
    $('assist-input-count').textContent = Array.from($('assist-input').value).length.toLocaleString('ko-KR') + ' / 4,000자';
    $('assist-manual-count').textContent = Array.from($('assist-manual-input').value).length.toLocaleString('ko-KR') + ' / 4,000자 · 상대에게 아직 보내지 않습니다.';
  }
  function validText(text, field, label) {
    if (!text.trim() || Array.from(text).length > 4000 || /[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/.test(text)) {
      tell(label + '을 공백만 있는 값 없이 1~4,000자로 입력해 주세요. 초과한 내용은 자동으로 자르지 않습니다.');
      field.focus(); return false;
    }
    return true;
  }
  function edited() {
    ++editRevision; attempt = null; clearResult(); $('assist-manual-input').value = ''; buttons();
  }
  function providerNote() {
    if (provider === 'local' && localBridge === null) return 'PC 비서 연결은 메신저 전체 화면에서 할 수 있습니다.';
    if (provider === 'local') return '처음에는 ‘GPT 연결’을 누르고 PC에서 승인하세요. 이후에는 기억한 연결을 사용합니다. 내 요금제 또는 사용 가능한 크레딧으로 처리하며 유료 API로 자동 전환하지 않습니다.';
    if (provider === 'web') return '요청문을 복사해 ChatGPT에서 작업한 뒤 결과를 직접 가져오세요.';
    return ready ? '원문을 확인하고 원하는 도구를 실행하세요.' : '관리자가 Gemini 키를 등록하고 AI 사용을 켜면 이용할 수 있습니다.';
  }
  function clearResult() {
    result = null; $('assist-result').hidden = true; $('assist-image').removeAttribute('src');
    $('assist-output').value = ''; $('assist-external-prompt').hidden = true;
  }
  function paintJob(job) {
    result = job; clearTimeout(timer); waiting = job.state === 'pending';
    $('assist-result').hidden = job.state !== 'complete';
    if (job.state === 'complete') {
      $('assist-result-origin').textContent = job.local ? '내 PC의 ChatGPT 완료 결과 · 확인 후 작성란에 넣어 주세요.' : job.manual ? '직접 붙여 넣은 결과 · 생성 출처와 처리 완료 여부는 작업한 앱에서 확인해 주세요.' : '모아 AI 결과';
      const image = Boolean(job.image_url && safeImage(job.image_url));
      $('assist-image').hidden = !image; $('assist-text-result').hidden = image; $('assist-copy').hidden = image;
      if (image) $('assist-image').src = job.image_url;
      else { $('assist-image').removeAttribute('src'); $('assist-output').value = job.result || ''; }
      $('assist-apply').textContent = image ? '이 대화에 이미지 첨부' : '작성란에 넣기';
      tell(source !== null ? '번역 결과입니다. 원문은 그대로 남아 있어요.' : '결과를 확인하고 필요하면 작성란에 넣어 주세요.');
    } else if (job.state === 'failed') tell(job.error);
    else { tell('요청을 처리하고 있어요. 창을 닫아도 최근 결과에서 다시 볼 수 있습니다.'); schedule(job.request_id); }
    buttons();
  }
  function history(data) {
    ready = data.ready; imageEnabled = data.image_enabled; jobs = data.jobs;
    $('assist-quota').textContent = `오늘 AI ${data.remaining}회 · 이미지 ${data.image_remaining}회 남음`;
    const select = $('assist-history'), selected = select.value; select.replaceChildren();
    const first = node('option', '최근 결과 다시 보기'); first.value = ''; select.append(first);
    for (const job of jobs) {
      const option = node('option', `${titles[job.action]} · ${job.state === 'pending' ? '처리 중' : job.state === 'failed' ? '실패' : '완료'} · ${Array.from(job.input).slice(0,24).join('')}`);
      option.value = job.id; select.append(option);
    }
    select.value = jobs.some(job => job.id === selected) ? selected : '';
    buttons();
  }
  function schedule(request) {
    clearTimeout(timer); const ticket = epoch, peer = target;
    timer = setTimeout(async () => {
      if (!matches(ticket)) return;
      try {
        const data = await api('/api/messages/assist?peer=' + peer + '&request=' + request, 'GET', undefined, false, true);
        if (!matches(ticket)) return;
        history(data);
        if (data.job) paintJob(data.job);
        else { waiting = false; tell('처리 상태를 확인하지 못했어요. 같은 내용으로 다시 누르면 중복 요청을 방지하며 확인합니다.'); buttons(); }
      } catch (error) {
        if (!matches(ticket)) return;
        waiting = false; tell(error.message + ' 최근 결과에서 처리 여부를 확인해 주세요.'); buttons();
      }
    }, 4000);
  }
  function sync() {
    const current = context(), attachment = attachments.get(current.peer);
    $('message-assist-toolbar').hidden = !current.peer;
    $('message-assist').disabled = !current.ready || current.sending;
    $('message-attachment').hidden = !attachment || !current.peer;
    $('message-text').required = !attachment || !current.peer;
    if (attachment && current.peer) $('message-attachment-image').src = attachment.image_url;
    else $('message-attachment-image').removeAttribute('src');
    $('message-attachment-remove').disabled = Boolean(current.sending);
    $('message-assist-undo').hidden = !current.peer || !undos.has(current.peer);
    $('message-assist-undo').disabled = Boolean(current.sending);
    if (dialog.open && target !== current.peer) dialog.close();
  }
  async function open(message) {
    const current = context(); if (!current.peer || !current.ready || current.sending) return;
    clearTimeout(timer); const ticket = ++epoch; target = current.peer;
    source = message ? Number(message.id) : null; sourceText = message ? message.body : '';
    draftAtOpen = $('message-text').value; action = message ? 'translate' : 'polish'; provider = 'gemini'; ++editRevision;
    $('assist-language').value = message ? 'ko' : 'en';
    $('assist-input').value = message ? sourceText : draftAtOpen;
    originalAtOpen = $('assist-input').value; $('assist-manual-input').value = ''; $('assist-external').open = true;
    $('assist-history').replaceChildren(); $('assist-recipient').textContent = current.name + ' 님과의 대화를 돕습니다.';
    ready = false; running = false; waiting = false; attempt = null; clearResult(); buttons(); tell('모아 AI 설정을 확인하고 있어요…');
    if (!dialog.open) dialog.showModal();
    clearInterval(connectionTimer);
    // No network probe: expire the UI using the existing tab credential's clock.
    connectionTimer = setInterval(() => { if (dialog.open && provider === 'local') buttons(); }, 1000);
    $('assist-input').focus();
    try {
      const data = await api('/api/messages/assist?peer=' + target);
      if (!matches(ticket)) return;
      history(data); if (provider === 'gemini') tell(providerNote());
    } catch (error) { if (matches(ticket) && provider === 'gemini') tell(error.message); }
  }
  $('message-assist').addEventListener('click', () => open());
  $('assist-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => { if (localBridge !== null) localBridge.cancel(); localBusy = false; ++epoch; clearTimeout(timer); clearInterval(connectionTimer); connectionTimer = null; running = false; waiting = false; });
  for (const button of dialog.querySelectorAll('[data-assist-provider]')) button.addEventListener('click', () => {
    if (running || waiting || button.dataset.assistProvider === provider) return;
    provider = button.dataset.assistProvider; edited(); tell(providerNote());
    if (provider === 'local' && localBridge?.remembered() && !bridgeConnected()) connectLocal();
  });
  for (const button of dialog.querySelectorAll('[data-assist-action]')) button.addEventListener('click', () => {
    if (running || waiting) return;
    action = button.dataset.assistAction; edited();
    tell(action === 'image' && provider === 'gemini' && !imageEnabled ? '이미지 생성은 유료 API입니다. 관리자가 이미지 사용을 켜야 합니다.' : providerNote());
    $('assist-input').focus();
  });
  for (const id of ['assist-input', 'assist-language', 'assist-aspect']) $(id).addEventListener('input', () => {
    if (!running && !waiting) edited();
  });
  $('assist-restore-input').addEventListener('click', () => {
    if ($('assist-restore-input').disabled) return;
    $('assist-input').value = originalAtOpen; edited(); tell('이 창을 열었을 때의 원문으로 되돌렸습니다.'); $('assist-input').focus();
  });
  async function connectLocal(code) {
    if (running || waiting || localBridge === null) return;
    const ticket = epoch, revision = editRevision;
    running = localBusy = true; buttons(); tell('PC 비서에서 처음 한 번 연결을 승인해 주세요.');
    try {
      await localBridge.pair(code);
      if (matches(ticket) && revision === editRevision) tell('PC 연결됨. 원문을 확인한 뒤 “GPT로 처리”를 누르세요.');
    } catch (error) { if (matches(ticket) && revision === editRevision) tell(error.message); }
    finally { if (matches(ticket) && revision === editRevision) { running = localBusy = false; buttons(); } }
  }
  $('assist-local-connect').addEventListener('click', () => connectLocal());
  $('assist-local-pair').addEventListener('click', () => {
    const code = $('assist-local-code').value.trim(); $('assist-local-code').value = ''; connectLocal(code);
  });
  $('assist-local-unpair').addEventListener('click', async () => {
    if (localBridge === null) return;
    const ticket = epoch; ++editRevision; localBusy = running = waiting = false; clearResult();
    try { await localBridge.disconnect(); if (matches(ticket)) tell('PC 연결을 해제했습니다.'); }
    catch (error) { if (matches(ticket)) tell('웹 연결 정보를 지웠습니다. PC 비서에서도 연결 중지를 눌러 주세요.'); }
    finally { if (matches(ticket)) buttons(); }
  });
  $('assist-local-cancel').addEventListener('click', () => {
    if (localBridge === null) return;
    localBridge.cancel(); localBusy = running = waiting = false; ++editRevision; clearResult(); buttons(); tell('요청을 취소했습니다. 늦게 도착하는 결과도 적용하지 않습니다.');
  });
  $('assist-local-run').addEventListener('click', async () => {
    if ($('assist-local-run').disabled || provider !== 'local' || localBridge === null) return;
    const text = $('assist-input').value;
    if (!validText(text, $('assist-input'), '원문')) return;
    const ticket = epoch, revision = editRevision;
    running = localBusy = true; clearResult(); buttons(); tell(localBridge.remembered() ? '연결한 PC에서 GPT로 처리 중입니다.' : 'PC 비서에서 원문과 작업을 확인해 주세요.');
    try {
      const data = await localBridge.run({action, text, target_language:action === 'translate' ? $('assist-language').value : null});
      if (!matches(ticket) || revision !== editRevision) return;
      paintJob({state:'complete', result:data.text, local:true, revision});
    } catch (error) { if (matches(ticket) && revision === editRevision) tell(error.message); }
    finally { if (matches(ticket) && revision === editRevision) { running = localBusy = false; buttons(); } }
  });
  $('assist-local-copy').addEventListener('click', async () => {
    if (provider !== 'local' || action === 'image' || running || waiting) return;
    const input = $('assist-input'), text = input.value, ticket = epoch, revision = editRevision;
    if (!validText(text, input, '원문')) return;
    try {
      await navigator.clipboard.writeText(text);
      if (matches(ticket) && revision === editRevision) tell('원문만 복사했습니다. PC 비서에서 작업·언어를 선택하고 붙여 넣으세요.');
    } catch (_) {
      if (!matches(ticket) || revision !== editRevision) return;
      input.focus(); input.select(); tell('자동 복사가 제한되었습니다. 선택된 원문을 Ctrl+C 또는 기기의 복사 메뉴로 직접 복사해 주세요.');
    }
  });
  $('assist-manual-input').addEventListener('input', () => { clearResult(); counts(); });
  $('assist-manual-preview').addEventListener('click', () => {
    if (provider === 'gemini' || action === 'image' || running || waiting) return;
    if (!validText($('assist-input').value, $('assist-input'), '원문')) return;
    const text = $('assist-manual-input').value;
    if (!validText(text, $('assist-manual-input'), '결과')) return;
    paintJob({state:'complete', result:text, manual:true, revision:editRevision});
    tell('직접 가져온 결과를 미리 보고 있습니다. 확인 후 “작성란에 넣기”를 눌러 주세요.');
    $('assist-result').scrollIntoView({block:'nearest'});
  });
  $('assist-history').addEventListener('change', () => {
    if (provider !== 'gemini' || running || waiting) return;
    const job = jobs.find(item => item.id === $('assist-history').value); if (!job) return;
    action = job.action; source = job.source_id; sourceText = job.input; attempt = null;
    $('assist-input').value = job.input; $('assist-language').value = job.language; $('assist-aspect').value = job.aspect;
    ++editRevision; $('assist-manual-input').value = ''; clearResult(); paintJob(job);
  });
  $('assist-run').addEventListener('click', async () => {
    if ($('assist-run').disabled) return;
    const original = source !== null ? sourceText : $('assist-input').value;
    if (!validText(original, $('assist-input'), action === 'image' ? '이미지 설명' : '원문')) return;
    const text = original.trim();
    const payload = {peer_id:target, action, source_id:source, text, language:$('assist-language').value, aspect:$('assist-aspect').value};
    const signature = JSON.stringify(payload);
    if (!attempt || attempt.signature !== signature) attempt = {signature, body:{...payload, request_id:crypto.randomUUID()}};
    const currentAttempt = attempt, ticket = epoch;
    running = true; clearResult(); buttons(); tell('AI가 준비하고 있어요…');
    try {
      const data = await api('/api/messages/assist', 'POST', currentAttempt.body);
      if (!matches(ticket)) return;
      if (data.job.state !== 'pending') attempt = null;
      paintJob(data.job);
      try {
        const state = await api('/api/messages/assist?peer=' + target, 'GET', undefined, false, true);
        if (matches(ticket)) history(state);
      } catch (_) { /* A quota refresh failure must not hide a completed result. */ }
    } catch (error) {
      if (!matches(ticket)) return;
      if (error.status && error.status < 500) { attempt = null; tell(error.message); }
      else { waiting = true; tell('응답을 확인하고 있어요. 같은 요청을 자동으로 다시 보내지는 않습니다.'); schedule(currentAttempt.body.request_id); }
    } finally { if (matches(ticket)) { running = false; buttons(); } }
  });
  $('assist-apply').addEventListener('click', () => {
    const current = context();
    if (!result || result.state !== 'complete' || current.peer !== target || !current.ready || current.sending) return;
    if ((result.manual || result.local) && result.revision !== editRevision) return;
    if (result.image_url && safeImage(result.image_url)) { attachments.set(target, {id:result.id, image_url:result.image_url}); notify(target); }
    else {
      if (!validText(result.result || '', $('assist-output'), '결과')) return;
      if ($('message-text').value !== draftAtOpen) { tell('작성 중인 문장이 바뀌었습니다. 결과를 복사해 필요한 부분에 붙여 넣어 주세요.'); return; }
      undos.set(target, {before:$('message-text').value, after:result.result});
      $('message-text').value = result.result || '';
      $('message-text').dispatchEvent(new Event('input', {bubbles:true}));
    }
    dialog.close(); sync(); $('message-text').focus(); say('작성란에 준비했습니다. 확인한 뒤 보내기를 눌러 주세요.');
  });
  $('assist-copy').addEventListener('click', async () => {
    const ticket = epoch, currentResult = result;
    if (!currentResult || currentResult.state !== 'complete' || currentResult.image_url) return;
    try { await navigator.clipboard.writeText($('assist-output').value); if (matches(ticket) && result === currentResult) tell('결과를 복사했습니다.'); }
    catch (_) { if (!matches(ticket) || result !== currentResult) return; $('assist-output').focus(); $('assist-output').select(); tell('선택된 결과를 직접 복사해 주세요.'); }
  });
  $('assist-external-copy').addEventListener('click', async () => {
    if (provider !== 'web' || running || waiting) return;
    const text = $('assist-input').value;
    if (!validText(text, $('assist-input'), '원문')) return;
    const ticket = epoch, revision = editRevision;
    const instruction = {polish:'아래 문장을 뜻과 사실을 유지하며 자연스럽고 정중하게 다듬어 줘. 완성된 본문만 반환해 줘.', spelling:'아래 문장의 맞춤법과 띄어쓰기만 고쳐 줘. 완성된 본문만 반환해 줘.', translate:'아래 문장을 '+languages[$('assist-language').value]+'로 번역해 줘. 번역 본문만 반환해 줘.', image:'다음 설명으로 이미지를 만들어 줘. 비율은 '+$('assist-aspect').value+'.'}[action];
    const prompt = instruction + '\n\n' + text;
    try { await navigator.clipboard.writeText(prompt); if (matches(ticket) && revision === editRevision) tell('요청문을 복사했습니다. ChatGPT 열기를 눌러 붙여 넣어 주세요.'); }
    catch (_) { if (!matches(ticket) || revision !== editRevision) return; $('assist-external-prompt').hidden = false; $('assist-external-prompt').value = prompt; $('assist-external-prompt').focus(); $('assist-external-prompt').select(); tell('선택된 요청문을 직접 복사해 주세요.'); }
  });
  $('message-assist-undo').addEventListener('click', () => {
    const peer = context().peer, undo = undos.get(peer); if (!undo) return;
    if ($('message-text').value !== undo.after) { say('문장이 다시 바뀌어 자동으로 되돌릴 수 없습니다.'); return; }
    $('message-text').value = undo.before; $('message-text').dispatchEvent(new Event('input', {bubbles:true}));
    undos.delete(peer); sync(); $('message-text').focus();
  });
  $('message-attachment-remove').addEventListener('click', () => { const peer = context().peer; attachments.delete(peer); sync(); notify(peer); });
  return {
    open, sync,
    attachment: peer => attachments.get(peer)?.id || null,
    sent(peer, attachment) { if (attachment && attachments.get(peer)?.id === attachment) attachments.delete(peer); undos.delete(peer); sync(); },
    // Only a server-listed, completed image of this requester/peer (checked by the caller) with a safe URL.
    restore(peer, id, imageUrl) {
      if (attachments.has(peer) || typeof id !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(id) || !safeImage(imageUrl) || imageUrl.slice(imagePrefix.length) !== id) return false;
      attachments.set(peer, {id, image_url:imageUrl}); sync(); return true;
    },
  };
};
