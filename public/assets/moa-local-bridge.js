'use strict';

// A credential lives only in this page. Site cookies and account tokens never cross.
window.MoaLocalBridge = function () {
  const base = 'http://127.0.0.1:43821';
  let credential = null, expires = 0, serial = 0, active = null;
  const errors = {eligibility_unconfirmed:'PC 비서의 ChatGPT 배포 자격이 아직 확인되지 않았습니다.',
    reauth_required:'PC 비서에서 다시 로그인해 주세요.', model_required:'PC 비서에서 모델을 선택해 주세요.',
    permission_denied:'연결이 만료되었거나 승인이 거절됐습니다. 다시 연결해 주세요.',
    busy:'PC 비서가 다른 요청을 처리 중입니다.', cancelled:'요청을 취소했습니다.',
    usage_limit:'ChatGPT 사용 한도에 도달했습니다.'};
  const pause = () => new Promise(resolve => setTimeout(resolve, 800));
  const connected = () => Boolean(credential && Date.now() < expires);
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
      if (!response.ok) throw Error(errors[data.error_code] || 'PC 비서가 요청을 처리하지 못했습니다. 다시 연결 상태를 확인해 주세요.');
      return data;
    } catch (error) {
      if (error instanceof TypeError || error.name === 'AbortError') throw Error('PC 비서 연결과 브라우저의 로컬 네트워크 허용을 확인해 주세요. 연결할 수 없으면 직접 복사를 이용하세요.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function cancel() {
    ++serial;
    const job = active; active = null;
    if (job) request('/v1/jobs/' + job.id + '/cancel', 'POST', {}, job.auth).catch(() => {});
  }
  async function disconnect() {
    const token = credential; cancel(); credential = null; expires = 0;
    if (token) await request('/v1/unpair', 'POST', {}, 'MoaLocal ' + token);
  }
  async function pair(code) {
    if (location.origin !== 'https://changkwon.mso.so') throw Error('공식 모아 사이트에서만 PC 연결을 사용할 수 있습니다.');
    if (!/^[A-Za-z0-9_-]{32}$/.test(code)) throw Error('PC 비서에서 복사한 페어링 코드를 입력해 주세요.');
    await disconnect(); const ticket = ++serial, id = crypto.randomUUID(), until = Date.now() + 120000;
    const pending = await request('/v1/pair', 'POST', {request_id:id, code});
    if (pending.request_id !== id || !/^[A-Za-z0-9_-]{43}$/.test(pending.poll_token)) throw Error('PC 연결 응답을 확인할 수 없습니다.');
    while (ticket === serial && Date.now() < until) {
      const data = await request('/v1/pair/' + id, 'GET', undefined, 'MoaPair ' + pending.poll_token);
      if (ticket !== serial) throw Error('연결을 취소했습니다.');
      if (data.request_id !== id) throw Error('PC 연결 응답이 일치하지 않습니다.');
      if (data.state === 'approved' && /^[A-Za-z0-9_-]{43}$/.test(data.credential)) {
        credential = data.credential; expires = Date.now() + 1800000; return;
      }
      if (data.state !== 'awaiting_confirmation') throw Error('PC에서 연결을 승인하지 않았습니다.');
      await pause();
    }
    throw Error('연결 시간이 만료되었거나 취소됐습니다. 새 코드를 발급해 주세요.');
  }
  async function run(payload) {
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
  window.addEventListener('pagehide', () => { disconnect().catch(() => {}); });
  return {connected, pair, run, cancel, disconnect};
};
