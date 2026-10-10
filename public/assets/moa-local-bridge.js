'use strict';

// Session credentials stay in memory; a scoped browser grant may be remembered.
// Site cookies and OpenAI/account tokens never cross this bridge.
window.MoaLocalBridge = function (user) {
  const base = 'http://127.0.0.1:43821';
  const storageKey = 'moa-pc:v2:' + user;
  const validUser = /^[1-9][0-9]{0,18}$/.test(user || '');
  let memoryGrant = null;
  let credential = null, expires = 0, serial = 0, active = null, pairing = null;
  const errors = {eligibility_unconfirmed:'PC 비서의 ChatGPT 배포 자격이 아직 확인되지 않았습니다.',
    reauth_required:'PC 비서에서 다시 로그인해 주세요.', model_required:'PC 비서에서 모델을 선택해 주세요.',
    permission_denied:'연결이 만료되었거나 승인이 거절됐습니다. 다시 연결해 주세요.',
    busy:'PC 비서가 다른 요청을 처리 중입니다.', cancelled:'요청을 취소했습니다.',
    usage_limit:'ChatGPT 사용 한도에 도달했습니다.'};
  const pause = () => new Promise(resolve => setTimeout(resolve, 800));
  const connected = () => Boolean(credential && Date.now() < expires);
  function remembered() {
    try {
      const item = memoryGrant || JSON.parse(localStorage.getItem(storageKey) || 'null');
      return validUser && item && /^[A-Za-z0-9_-]{43}$/.test(item.token) && item.until > Date.now() ? item : null;
    } catch (_) { return null; }
  }
  function saveGrant(token) {
    memoryGrant = {token, until:Date.now() + 30 * 86400000};
    try { localStorage.setItem(storageKey, JSON.stringify(memoryGrant)); } catch (_) { /* Private mode: current page only. */ }
  }
  function clearGrant() {
    memoryGrant = null;
    try { localStorage.removeItem(storageKey); } catch (_) { /* Storage may be disabled. */ }
  }
  async function request(path, method, value, auth) {
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const headers = {};
      if (value !== undefined) headers['Content-Type'] = 'application/json';
      if (auth) headers.Authorization = auth;
      const response = await fetch(base + path, {method, headers, mode:'cors', credentials:'omit',
        cache:'no-store', redirect:'error', referrerPolicy:'no-referrer', signal:controller.signal,
        body:value === undefined ? undefined : JSON.stringify(value)});
      const raw = await response.text();
      if (raw.length > 700000) throw Error('PC 비서 응답이 너무 큽니다.');
      const data = JSON.parse(raw);
      if (!response.ok) {
        const error = Error(response.status === 404 ? 'PC 비서를 업데이트하거나 아래 코드로 연결해 주세요.' : errors[data.error_code] || 'PC 비서가 요청을 처리하지 못했습니다. 다시 연결 상태를 확인해 주세요.');
        error.code = data.error_code; throw error;
      }
      return data;
    } catch (error) {
      if (error instanceof TypeError || error.name === 'AbortError') throw Error('같은 Windows PC에서 ‘PC 비서 실행’을 누르고, 브라우저의 로컬 네트워크 접근을 허용한 뒤 다시 연결해 주세요.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function cancel() {
    ++serial;
    const attempt = pairing; pairing = null;
    if (attempt) request('/v1/pair/' + attempt.id + '/cancel', 'POST', {}, 'MoaPair ' + attempt.proof).catch(() => {});
    const job = active; active = null;
    if (job) request('/v1/jobs/' + job.id + '/cancel', 'POST', {}, job.auth).catch(() => {});
  }
  async function disconnect() {
    const token = credential, grant = remembered(); cancel(); credential = null; expires = 0; clearGrant();
    if (grant) await request('/v2/forget', 'POST', {user, token:grant.token});
    else if (token) await request('/v1/unpair', 'POST', {}, 'MoaLocal ' + token);
  }
  async function resume() {
    const grant = remembered();
    if (!grant) return false;
    const ticket = ++serial;
    try {
      const data = await request('/v2/resume', 'POST', {user, token:grant.token});
      if (ticket !== serial) throw Error('연결을 취소했습니다.');
      if (!/^[A-Za-z0-9_-]{43}$/.test(data.credential)) throw Error('PC 연결 응답을 확인할 수 없습니다.');
      credential = data.credential; expires = Date.now() + 1800000; return true;
    } catch (error) {
      if (error.code === 'permission_denied') clearGrant();
      throw error;
    }
  }
  async function pair(code) {
    if (location.origin !== 'https://changkwon.mso.so') throw Error('공식 모아 사이트에서만 PC 연결을 사용할 수 있습니다.');
    const easy = code === undefined;
    if (easy && !validUser) throw Error('모아에 다시 로그인한 뒤 연결해 주세요.');
    if (!easy && !/^[A-Za-z0-9_-]{32}$/.test(code)) throw Error('PC 비서에서 복사한 페어링 코드를 입력해 주세요.');
    if (easy && remembered() && await resume()) return;
    if (!easy) await disconnect();
    cancel(); credential = null; expires = 0;
    const ticket = ++serial, id = crypto.randomUUID(), until = Date.now() + 120000;
    const pending = await request(easy ? '/v2/connect' : '/v1/pair', 'POST', easy ? {request_id:id, user} : {request_id:id, code});
    if (pending.request_id !== id || !/^[A-Za-z0-9_-]{43}$/.test(pending.poll_token)) throw Error('PC 연결 응답을 확인할 수 없습니다.');
    pairing = {id, proof:pending.poll_token};
    if (ticket !== serial) { cancel(); throw Error('연결을 취소했습니다.'); }
    try {
    while (ticket === serial && Date.now() < until) {
      const data = await request('/v1/pair/' + id, 'GET', undefined, 'MoaPair ' + pending.poll_token);
      if (ticket !== serial) {
        if (data.remember_token) request('/v2/forget', 'POST', {user, token:data.remember_token}).catch(() => {});
        throw Error('연결을 취소했습니다.');
      }
      if (data.request_id !== id) throw Error('PC 연결 응답이 일치하지 않습니다.');
      if (data.state === 'approved' && /^[A-Za-z0-9_-]{43}$/.test(data.credential)) {
        if (easy && /^[A-Za-z0-9_-]{43}$/.test(data.remember_token)) saveGrant(data.remember_token);
        credential = data.credential; expires = Date.now() + 1800000; return;
      }
      if (data.state !== 'awaiting_confirmation') throw Error('PC에서 연결을 승인하지 않았습니다.');
      await pause();
    }
    throw Error('연결 시간이 만료되었거나 취소됐습니다. 다시 연결해 주세요.');
    } finally { if (pairing?.id === id) pairing = null; }
  }
  async function run(payload) {
    if (!connected() && remembered()) await resume();
    if (!connected()) throw Error('먼저 PC 비서와 연결해 주세요.');
    if (active) throw Error('기존 요청이 끝난 뒤 실행해 주세요.');
    const ticket = ++serial, id = crypto.randomUUID(), auth = 'MoaLocal ' + credential;
    active = {id, auth}; const until = Date.now() + 300000;
    try {
      // A failed POST is never automatically replayed; GET alone retrieves status.
      let data = await request('/v1/jobs', 'POST', {...payload, schema_version:1, request_id:id}, auth);
      while (ticket === serial && Date.now() < until) {
        if (data.request_id !== id || data.provider !== 'openai_chatgpt_plan') throw Error('PC 결과의 요청을 확인할 수 없습니다.');
        if (data.state === 'completed') {
          if (typeof data.text !== 'string' || !data.text.trim() || Array.from(data.text).length > 4000) throw Error('완료 결과가 비어 있거나 4,000자를 초과합니다. PC 비서에서 확인해 주세요.');
          return data;
        }
        if (!['running','awaiting_confirmation'].includes(data.state)) throw Error(errors[data.error_code] || 'PC에서 요청을 완료하지 못했습니다.');
        await pause(); if (ticket !== serial) break;
        data = await request('/v1/jobs/' + id, 'GET', undefined, auth);
      }
      throw Error('요청이 취소되었거나 처리 시간이 초과됐습니다.');
    } catch (error) {
      if (active?.id === id) cancel();
      throw error;
    } finally { if (active?.id === id) active = null; }
  }
  window.addEventListener('pagehide', () => { cancel(); credential = null; expires = 0; });
  document.addEventListener('qaredi:logout', () => { disconnect().catch(() => {}); });
  window.addEventListener('storage', event => {
    if (event.key === storageKey && !event.newValue) { memoryGrant = null; cancel(); credential = null; expires = 0; }
  });
  return {connected, remembered:() => Boolean(remembered()), pair, resume, run, cancel, disconnect};
};
