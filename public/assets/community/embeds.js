/**
 * public/assets/community/embeds.js — 커뮤니티 7-C 임베드 허용 목록 관리 창 (C:F-32 · T-33 · 결정 K-1·K-2)
 *
 *   홈 「임베드 허용 목록」 단추 — 창구 `GET /api/embed-domains` 가 `can_manage` 를 참으로 줄 때만 보인다(Admin).
 *   더하기 POST /api/embed-domains {host} · 빼기 DELETE /api/embed-domains/{번호} — 둘 다 `role:Admin` + 재인증(서버가 막는다).
 *
 * ★ 역할을 따지는 코드가 없다 — 보이는지는 `can_manage`, 막는 것은 서버다(재인증이 필요하면 셸의 재인증 창을 띄운다).
 * ★ 글자는 전부 textContent 로 넣는다.
 */

function addedBy(d) {
  if (!d.added_by) { return '—'; }
  return d.added_by.display_name ? d.added_by.display_name + ' (' + d.added_by.username + ')' : d.added_by.username;
}

/**
 * @param d {api, setText, show, clear, errorMessage, genericError, toLogin}
 */
export function bindEmbedAdmin(d) {
  const button = document.getElementById('community-embed-admin');
  const dialog = document.getElementById('embed-dialog');
  const tbody = document.getElementById('embed-body');
  const empty = document.getElementById('embed-empty');
  const meta = document.getElementById('embed-meta');
  const msg = document.getElementById('embed-msg');
  const input = document.getElementById('embed-host');
  const add = document.getElementById('embed-add');
  if (!button || !dialog || !tbody) { return; }

  /** 재인증이 필요하면 셸의 같은 창을 띄우고, 통과하면 이 창을 다시 열어 같은 일을 다시 한다(모달 안에 모달을 열지 않는다) */
  function reauthThen(result, again) {
    if (result.status === 403 && result.data && result.data.next === 'reauth' && typeof window.qarediHandleReauth === 'function') {
      dialog.close();
      window.qarediHandleReauth({ status: 403 }, result.data, () => { dialog.showModal(); again(); });
      return true;
    }
    return false;
  }

  function row(dom) {
    const tr = document.createElement('tr');
    const host = document.createElement('td');
    host.textContent = dom.host;
    tr.appendChild(host);
    const by = document.createElement('td');
    by.textContent = addedBy(dom);
    tr.appendChild(by);
    const when = document.createElement('td');
    when.textContent = typeof dom.added_at === 'string' ? dom.added_at.slice(0, 16).replace('T', ' ') : '';
    tr.appendChild(when);
    const actions = document.createElement('td');
    actions.className = 'actions';
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn btn--small btn--quiet btn--danger';
    del.textContent = '빼기';
    del.setAttribute('aria-label', dom.host + ' 빼기');
    let armed = false;
    del.addEventListener('blur', () => { if (armed) { armed = false; del.textContent = '빼기'; } });
    const remove = () => {
      del.disabled = true;
      d.api('DELETE', '/api/embed-domains/' + String(dom.id)).then((r) => {
        del.disabled = false;
        if (r.status === 401) { d.toLogin(); return; }
        if (reauthThen(r, remove)) { return; }
        if (r.status !== 200) { d.setText(msg, d.errorMessage(r.data)); return; }
        d.setText(msg, dom.host + ' 을(를) 뺐습니다. 이미 넣은 임베드는 「허용 목록 밖」 카드로 보입니다');
        load().catch(() => d.setText(msg, d.genericError));
      }).catch(() => { del.disabled = false; d.setText(msg, d.genericError); });
    };
    del.addEventListener('click', () => {
      if (!armed) { armed = true; del.textContent = '정말 빼기'; return; }
      remove();
    });
    actions.appendChild(del);
    tr.appendChild(actions);
    return tr;
  }

  function load() {
    return d.api('GET', '/api/embed-domains').then((r) => {
      if (r.status === 401) { d.toLogin(); return; }
      if (r.status !== 200) { d.setText(msg, d.errorMessage(r.data)); return; }
      const list = Array.isArray(r.data.domains) ? r.data.domains : [];
      d.clear(tbody);
      for (const dom of list) { tbody.appendChild(row(dom)); }
      d.show(empty, list.length === 0);
      d.setText(meta, String(list.length) + ' / ' + String(r.data.limit) + ' · '
        + (r.data.frames === true ? '틀 열림 — 목록 안 임베드가 페이지 안에 보입니다' : '틀 닫힘 — 사이트 설정(CSP)을 열기 전까지 모든 임베드는 「허용 대기」 카드입니다'));
    });
  }

  function submit() {
    const host = input.value.trim();
    if (host === '') { input.focus(); return; }
    add.disabled = true;
    d.api('POST', '/api/embed-domains', { host: host }).then((r) => {
      add.disabled = false;
      if (r.status === 401) { d.toLogin(); return; }
      if (reauthThen(r, submit)) { return; }
      if (r.status !== 201) { d.setText(msg, d.errorMessage(r.data)); return; }
      input.value = '';
      d.setText(msg, r.data.domain.host + ' 을(를) 더했습니다');
      load().catch(() => d.setText(msg, d.genericError));
    }).catch(() => { add.disabled = false; d.setText(msg, d.genericError); });
  }

  add.addEventListener('click', submit);
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
  button.addEventListener('click', () => {
    if (typeof dialog.showModal !== 'function') { return; }
    d.setText(msg, '');
    if (!dialog.open) { dialog.showModal(); }
    load().catch(() => d.setText(msg, d.genericError));
  });
  for (const close of dialog.querySelectorAll('[data-embed-close]')) {
    close.addEventListener('click', () => dialog.close());
  }

  // 단추는 관리할 수 있는 사람에게만 보인다 — 판단은 창구가 준 `can_manage`
  d.api('GET', '/api/embed-domains').then((r) => {
    d.show(button, r.status === 200 && r.data && r.data.can_manage === true);
  }).catch(() => {});
}
