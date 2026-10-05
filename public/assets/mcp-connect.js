'use strict';
(() => {
  const root = document.getElementById('mcp-connect');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const scopeInfo = {
    'memories.read': ['내 글 읽기 (기본)', '내가 쓴 글의 목록과 본문을 읽습니다.'],
    'photos.read': ['내 사진 읽기', '내 글에 현재 첨부된 사진의 축소본을 ChatGPT로 보냅니다.'],
    'memories.write': ['내 글 쓰기', '새 비공개 글을 만들고 내 글을 수정합니다. 내 글 읽기를 포함합니다.'],
    'travel.read': ['내 여행 계획 읽기', '내 여행 계획과 연결된 글 정보를 읽습니다.'],
    'travel.write': ['내 여행 계획 쓰기', '내 여행 계획을 저장하고 글을 연결합니다. 여행 계획 읽기를 포함합니다.']
  };
  const node = (tag, text, className) => {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
  };
  const say = text => { $('member-status').textContent = text; };
  const errors = {
    invalid_request: '연결 요청을 확인할 수 없습니다. ChatGPT에서 연결 추가를 다시 시작해 주세요.',
    invalid_scope: '이 요청에서 허용할 수 없는 권한입니다. ChatGPT에서 필요한 권한으로 다시 연결해 주세요.',
    temporarily_unavailable: '연결 기능을 잠시 사용할 수 없습니다. 잠시 뒤 다시 확인해 주세요.',
    access_denied: '이 계정에서 연결 요청을 허용할 수 없습니다. 계정과 요청한 권한을 확인해 주세요.',
    login_required: '다시 로그인한 뒤 ChatGPT에서 연결을 시작해 주세요.',
    not_found: '연결 요청을 찾을 수 없습니다. 이미 처리했거나 만료되었을 수 있습니다.',
    'Failed to fetch': '서버에 연결하지 못했습니다. 네트워크 연결을 확인하고 다시 시도해 주세요.'
  };
  const error = value => {
    $('mcp-error').hidden = false;
    const message = value && value.message ? value.message : '연결 정보를 확인하지 못했습니다. 잠시 뒤 다시 시도해 주세요.';
    $('mcp-error').textContent = Object.hasOwn(errors, message) ? errors[message] : message;
    $('mcp-login-help').hidden = !value || (![401, 403].includes(value.status) && message !== 'login_required');
  };
  const clearError = () => { $('mcp-error').hidden = true; $('mcp-error').textContent = ''; $('mcp-login-help').hidden = true; };
  const date = value => {
    if (!value) return '아직 사용하지 않음';
    const time = new Date(value);
    return Number.isNaN(time.getTime()) ? '확인할 수 없음' : time.toLocaleString('ko-KR');
  };
  function publicUrl(value) {
    if (typeof value !== 'string') return null;
    try {
      const url = new URL(value);
      return url.protocol === 'https:' && !url.username && !url.password && !url.search && !url.hash ? url : null;
    } catch (_) { return null; }
  }
  let initialized = false;
  window.MoaMcpConnect = Object.freeze({failure: value => {
    say('로그인과 연결 정보를 불러오지 못했습니다.');
    $('mcp-readiness').textContent = '확인 실패';
    $('mcp-readiness-note').textContent = '로그인과 연결 정보를 확인하지 못했습니다. 다시 로그인하거나 페이지를 새로 열어 주세요.';
    $('mcp-refresh').disabled = false;
    $('mcp-refresh').addEventListener('click', () => location.reload(), {once: true});
    error(value);
  }, init: async ({api, me}) => {
    if (initialized) return;
    initialized = true;
    let pending = null;
    let busy = false;
    let expiresTimer = null;
    const inputs = new Map();
    const query = new URLSearchParams(location.search);
    const rawPending = query.get('pending');
    const pendingId = rawPending && /^[A-Za-z0-9_-]{43}$/.test(rawPending) ? rawPending : null;
    const name = value => {
      const account = value || me;
      return account.display_name ? account.display_name + ' (' + account.username + ')' : account.username;
    };
    $('mcp-account').textContent = name(me);
    $('mcp-consent-account').textContent = name(me);

    function lockConsent(locked) {
      $('mcp-approve').disabled = locked;
      $('mcp-deny').disabled = locked;
      for (const [scope, input] of inputs) input.disabled = locked || scope === 'memories.read';
    }
    function expireConsent() {
      pending = null;
      lockConsent(true);
      error(Error('승인 요청의 유효기간이 지났습니다. ChatGPT에서 연결 추가를 다시 시작해 주세요.'));
    }
    function syncScopes(changed) {
      if (changed === 'travel.write' && inputs.get(changed)?.checked) inputs.get('travel.read').checked = true;
      if (changed === 'travel.read' && !inputs.get(changed)?.checked && inputs.has('travel.write')) inputs.get('travel.write').checked = false;
      $('mcp-write-note').hidden = !['memories.write', 'travel.write'].some(scope => inputs.get(scope)?.checked);
    }
    function consent(data) {
      pending = null;
      inputs.clear();
      $('mcp-scopes').replaceChildren();
      $('mcp-consent').hidden = true;
      if (expiresTimer !== null) clearTimeout(expiresTimer);
      const request = data.pending;
      if (!request || data.enabled !== true) {
        if (pendingId && data.enabled === true) error(Error('승인 요청을 찾을 수 없거나 이미 처리했습니다. ChatGPT에서 연결을 다시 시작해 주세요.'));
        return;
      }
      if (request.id !== pendingId || !Array.isArray(request.requested_scopes)) throw Error('승인 요청을 확인하지 못했습니다. ChatGPT에서 다시 시작해 주세요.');
      const available = new Set(data.available_scopes || []);
      const requested = request.requested_scopes.filter(scope => Object.hasOwn(scopeInfo, scope) && available.has(scope));
      if (!requested.includes('memories.read')) throw Error('이 계정으로 요청한 읽기 권한을 승인할 수 없습니다.');
      pending = request;
      $('mcp-client').textContent = request.client_name;
      $('mcp-consent-account').textContent = name(data.user);
      for (const scope of requested) {
        if (scope === 'travel.write' && !requested.includes('travel.read')) continue;
        const label = node('label', undefined, 'field');
        const title = node('span', undefined, 'field__label');
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'scope';
        input.value = scope;
        input.id = 'mcp-scope-' + scope.replace('.', '-');
        input.checked = scope === 'memories.read';
        input.disabled = scope === 'memories.read';
        const help = node('span', scopeInfo[scope][1], 'help');
        help.id = input.id + '-help';
        input.setAttribute('aria-describedby', help.id);
        title.append(input, document.createTextNode(scopeInfo[scope][0]));
        label.append(title, help);
        $('mcp-scopes').append(label);
        inputs.set(scope, input);
        input.addEventListener('change', () => syncScopes(scope));
      }
      syncScopes();
      $('mcp-consent-expiry').textContent = '이 승인 요청의 만료: ' + date(request.expires_at);
      $('mcp-consent').hidden = false;
      lockConsent(false);
      const remaining = new Date(request.expires_at).getTime() - Date.now();
      if (!Number.isFinite(remaining) || remaining <= 0) expireConsent();
      else expiresTimer = setTimeout(expireConsent, Math.min(remaining, 2147483647));
    }

    function connections(items) {
      const list = $('mcp-connections');
      list.replaceChildren();
      for (const item of items) {
        const row = node('li', undefined, 'list__item');
        const head = node('div', undefined, 'list__head');
        const title = node('strong', item.client_name);
        head.append(title);
        const scopeNames = (item.scopes || []).map(scope => scopeInfo[scope]?.[0] || scope).join(' · ');
        const details = node('div', '허용 권한: ' + scopeNames, 'list__body');
        const times = node('p', '승인: ' + date(item.created_at) + ' · 만료: ' + date(item.expires_at) + ' · 마지막 사용: ' + date(item.last_used_at), 'help');
        const revoke = node('button', '연결 해제하기', 'btn btn--danger');
        revoke.type = 'button';
        revoke.setAttribute('aria-label', item.client_name + ' 연결 해제하기');
        const confirm = node('div', undefined, 'form');
        confirm.hidden = true;
        const question = node('p', '이 연결의 이후 접근을 차단할까요? 다시 사용하려면 ChatGPT에서 새로 연결해야 합니다.');
        const actions = node('div', undefined, 'form-actions');
        const cancel = node('button', '취소하기', 'btn btn--quiet');
        const remove = node('button', '이 연결 해제하기', 'btn btn--danger');
        cancel.type = remove.type = 'button';
        actions.append(cancel, remove);
        confirm.append(question, actions);
        revoke.addEventListener('click', () => { if (busy) return; confirm.hidden = false; revoke.hidden = true; cancel.focus(); });
        cancel.addEventListener('click', () => { confirm.hidden = true; revoke.hidden = false; revoke.focus(); });
        confirm.addEventListener('keydown', event => { if (event.key === 'Escape' && !busy) { event.preventDefault(); cancel.click(); } });
        remove.addEventListener('click', async () => {
          if (busy) return;
          busy = true;
          remove.disabled = cancel.disabled = $('mcp-refresh').disabled = true;
          lockConsent(true);
          clearError();
          try {
            await api('/api/mcp/connections/' + encodeURIComponent(item.id), 'DELETE', {});
            row.remove();
            const empty = list.children.length === 0;
            list.hidden = empty;
            $('mcp-connections-empty').hidden = !empty;
            say('연결을 해제했습니다. 모아의 글·사진·여행 계획은 그대로 유지됩니다.');
          } catch (value) { error(value); }
          finally {
            busy = false;
            remove.disabled = cancel.disabled = $('mcp-refresh').disabled = false;
            lockConsent(!pending);
            if (!row.isConnected) $('mcp-refresh').focus();
          }
        });
        row.append(head, details, times, revoke, confirm);
        list.append(row);
      }
      list.hidden = items.length === 0;
      $('mcp-connections-empty').hidden = items.length !== 0;
    }

    async function refresh() {
      if (busy) return;
      busy = true;
      $('mcp-refresh').disabled = true;
      $('mcp-copy').disabled = true;
      lockConsent(true);
      clearError();
      say('서버 설정과 승인한 연결을 확인하고 있습니다…');
      try {
        const data = await api('/api/mcp/connections' + (pendingId ? '?pending=' + encodeURIComponent(pendingId) : ''));
        $('mcp-account').textContent = name(data.user);
        const resource = publicUrl(data.resource);
        $('mcp-resource').value = resource ? resource.href : '';
        $('mcp-copy').disabled = data.enabled !== true || !resource;
        const ready = data.enabled === true && resource;
        $('mcp-readiness').textContent = ready ? '준비됨' : data.status === 'unconfigured' || data.configured === false || (data.enabled === true && !resource) ? '서버 설정 대기' : '기능 꺼짐';
        $('mcp-readiness-note').textContent = typeof data.status_message === 'string' && data.status_message ? data.status_message : ready ? '서버 설정이 준비되었습니다. 실제 ChatGPT 등록·연결은 아직 확인되지 않았습니다.' : '현재 새 연결을 사용할 수 없습니다. 관리자에게 원격 연결 설정을 확인해 주세요.';
        const issuer = publicUrl(data.issuer);
        $('mcp-metadata').hidden = !ready || !issuer;
        if (ready && issuer) {
          const resourceMetadata = publicUrl(data.metadata?.protected_resource_url) || new URL('/.well-known/oauth-protected-resource/mcp', resource);
          const oauthMetadata = publicUrl(data.metadata?.authorization_server_url) || new URL('/.well-known/oauth-authorization-server', issuer);
          $('mcp-resource-metadata').href = resourceMetadata.href;
          $('mcp-oauth-metadata').href = oauthMetadata.href;
        }
        connections(Array.isArray(data.connections) ? data.connections : []);
        consent(data);
        if (rawPending && !pendingId) error(Error('올바르지 않은 승인 요청입니다. ChatGPT에서 연결 추가를 다시 시작해 주세요.'));
        say(ready ? '계정과 서버 설정을 확인했습니다.' : '연결 준비가 끝나지 않았습니다. 아래 안내를 확인해 주세요.');
      } catch (value) {
        pending = null;
        lockConsent(true);
        $('mcp-readiness').textContent = '확인 실패';
        $('mcp-readiness-note').textContent = '서버의 현재 상태를 확인하지 못했습니다.';
        $('mcp-resource').value = '';
        $('mcp-metadata').hidden = true;
        say('연결 정보를 불러오지 못했습니다.');
        error(value);
      } finally { busy = false; $('mcp-refresh').disabled = false; }
    }

    async function decide(approve) {
      if (busy || !pending) return;
      if (new Date(pending.expires_at).getTime() <= Date.now()) { expireConsent(); return; }
      busy = true;
      lockConsent(true);
      $('mcp-refresh').disabled = true;
      clearError();
      try {
        const scopes = approve ? [...inputs].filter(([, input]) => input.checked).map(([scope]) => scope) : [];
        const data = await api('/oauth/authorize', 'POST', {pending: pending.id, approve, scopes});
        // The server validates the registered callback. Never navigate non-HTTPS schemes.
        if (typeof data.redirect_url !== 'string') throw Error('승인 결과를 확인하지 못했습니다. ChatGPT에서 다시 시작해 주세요.');
        let target;
        try { target = new URL(data.redirect_url); }
        catch (_) { throw Error('승인 결과의 이동 주소를 확인하지 못했습니다. ChatGPT에서 다시 시작해 주세요.'); }
        if (target.protocol !== 'https:' || target.username || target.password || target.hash) throw Error('승인 결과의 이동 주소를 확인하지 못했습니다. ChatGPT에서 다시 시작해 주세요.');
        pending = null;
        if (expiresTimer !== null) clearTimeout(expiresTimer);
        say(approve ? '승인했습니다. 연결을 요청한 앱으로 돌아갑니다…' : '허용하지 않았습니다. 연결을 요청한 앱으로 돌아갑니다…');
        location.replace(target.href);
      } catch (value) {
        // A lost response can already have consumed the request; do not replay it.
        pending = null;
        if (expiresTimer !== null) clearTimeout(expiresTimer);
        say('요청 결과를 확인하지 못했습니다. ChatGPT에서 연결 상태를 확인하고 필요하면 다시 시작하세요.');
        error(value);
      } finally { busy = false; $('mcp-refresh').disabled = false; lockConsent(!pending); }
    }

    $('mcp-consent-form').addEventListener('submit', event => { event.preventDefault(); decide(true); });
    $('mcp-deny').addEventListener('click', () => decide(false));
    $('mcp-refresh').addEventListener('click', refresh);
    $('mcp-copy').addEventListener('click', async () => {
      const value = publicUrl($('mcp-resource').value);
      if (!value || $('mcp-copy').disabled) return;
      try { await navigator.clipboard.writeText(value.href); say('연결 주소를 복사했습니다. ChatGPT의 원격 MCP 주소 칸에 붙여 넣으세요.'); }
      catch (_) { $('mcp-resource').focus(); $('mcp-resource').select(); say('자동 복사를 사용할 수 없습니다. 선택된 연결 주소를 직접 복사해 주세요.'); }
    });
    addEventListener('pagehide', () => { pending = null; if (expiresTimer !== null) clearTimeout(expiresTimer); lockConsent(true); });
    await refresh();
  }});
})();
