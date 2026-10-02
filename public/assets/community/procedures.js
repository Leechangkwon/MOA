// 8-A: server-authorized verification/procedure metadata; existing design components only.
function node(tag, text, cls) {
  const el = document.createElement(tag);
  if (text) el.textContent = text;
  if (cls) el.className = cls;
  return el;
}
export function drawTrust(data, pageId, api, reload) {
  const props = document.getElementById('page-props');
  const menu = document.querySelector('#page-more .menu__list');
  if (!props || !menu) return;
  for (const el of document.querySelectorAll('[data-trust]')) el.remove();
  const v = data.verify || { state: 'none' };
  const badge = node('span', '', 'badge badge--internal');
  badge.dataset.trust = 'badge';
  const parts = [];
  if (data.page_kind === 'procedure') parts.push('절차서');
  if (data.page_kind === 'dashboard') parts.push('대시보드');
  if (v.state !== 'none') {
    parts.push(v.state === 'expired' ? '⚠ 검증 만료' : '✓ 검증됨');
    if (v.by) parts.push(v.by.name);
    if (v.at) parts.push(v.at.slice(0, 10));
    parts.push(v.expires_at ? v.expires_at.slice(0, 10) + ' 까지' : '만료 없음');
    if (v.edited_after) parts.push('검증 뒤 고침');
  }
  badge.textContent = parts.join(' · ');
  badge.hidden = parts.length === 0;
  props.appendChild(badge);
  if (data.can_edit !== true) return;
  const patch = async (payload, msg) => {
    const result = await api('PATCH', '/api/pages/' + pageId + '/properties', payload);
    if (result.status === 200) { await reload(); return true; }
    msg.textContent = result.data.error || '변경하지 못했습니다.';
    return false;
  };
  const verify = node('button', '이 문서 확인함', 'menu__item');
  verify.type = 'button'; verify.dataset.trust = 'verify';
  verify.addEventListener('click', () => {
    const dialog = node('dialog', '', 'modal modal--form'); dialog.dataset.trust = 'dialog';
    const head = node('div', '', 'modal__head'); head.appendChild(node('h2', '이 문서 확인함', 'modal__title'));
    dialog.setAttribute('aria-label', '이 문서 확인함');
    const body = node('div', '', 'modal__body form');
    const label = node('label', '', 'field'); label.appendChild(node('span', '검증 유효기간', 'field__label'));
    const select = node('select'); select.setAttribute('aria-label', '검증 유효기간');
    for (const [value, text] of [[30,'30일'],[90,'90일'],[180,'180일'],[0,'만료 없음']]) {
      const option = node('option', text); option.value = String(value); option.selected = value === 90; select.appendChild(option);
    }
    label.appendChild(select); body.appendChild(label);
    const msg = node('p', '', 'msg'); msg.setAttribute('role', 'alert'); body.appendChild(msg);
    const actions = node('div', '', 'form-actions');
    const close = node('button', '닫기', 'btn btn--quiet'); close.type = 'button'; close.onclick = () => dialog.close();
    const save = node('button', '확인함', 'btn'); save.type = 'button';
    let saving = false;
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    save.onclick = async () => {
      if (saving) return; saving = true; save.disabled = close.disabled = select.disabled = true;
      try { if (await patch({ verify: Number(select.value) }, msg)) dialog.close(); }
      catch (_) { msg.textContent = '연결하지 못했습니다. 다시 시도해 주세요.'; }
      finally { saving = false; save.disabled = close.disabled = select.disabled = false; }
    };
    actions.append(close, save); dialog.append(head, body, actions); document.body.appendChild(dialog); dialog.showModal();
    dialog.addEventListener('close', () => { dialog.remove(); const opener = document.querySelector('[data-trust=verify]'); if (opener) opener.focus(); });
  });
  menu.appendChild(verify);
  const msg = node('p', '', 'msg'); msg.dataset.trust = 'msg'; props.appendChild(msg);
  for (const [text, payload] of [[data.page_kind==='dashboard'?'대시보드 표시 빼기':'대시보드로 표시',{kind:data.page_kind==='dashboard'?'page':'dashboard'}],['검증 해제', {verify:null}], [data.page_kind === 'procedure' ? '절차서 표시 빼기' : '절차서로 표시', {kind:data.page_kind === 'procedure' ? 'page':'procedure'}]]) {
    const button = node('button', text, 'menu__item'); button.type = 'button'; button.dataset.trust = 'action';
    button.onclick = () => patch(payload, msg).catch(() => { msg.textContent = '연결하지 못했습니다.'; }); menu.appendChild(button);
  }
}
export function bindProcedures(api, base) {
  const title = document.getElementById('community-space-title');
  if (!title) return;
  title.textContent = '절차서';
  const sub = document.getElementById('community-space-sub'); if (sub) sub.textContent = '볼 수 있는 업무 절차서를 검증 상태별로 찾습니다.';
  for (const id of ['community-new', 'community-more']) { const el = document.getElementById(id); if (el) el.hidden = true; }
  const area = title.closest('header').parentElement;
  const controls = node('div', '', 'toolbar');
  const q = node('input'); q.type = 'search'; q.placeholder = '절차서 제목 찾기'; q.setAttribute('aria-label', q.placeholder);
  const verify = node('select'); verify.setAttribute('aria-label', '검증 상태');
  for (const [value, text] of [['','전체'],['verified','검증됨'],['expired','만료'],['none','검증 없음']]) { const o = node('option', text); o.value = value; verify.appendChild(o); }
  const list = node('div', '', 'card-grid'); const msg = node('p', '', 'msg');
  const next = node('button', '더 보기', 'btn btn--quiet'); next.type = 'button';
  controls.append(q, verify); area.replaceChildren(title.closest('header'), controls, msg, list, next);
  let page = 1;
  async function load(append) {
    const result = await api('GET', '/api/procedures?q=' + encodeURIComponent(q.value) + '&verify=' + encodeURIComponent(verify.value) + '&page=' + page);
    if (result.status !== 200) { msg.textContent = result.data.error || '목록을 읽지 못했습니다.'; return; }
    if (!append) list.replaceChildren();
    for (const p of result.data.pages) {
      const a = node('a', '', 'card card--link'); a.href = base + '/p/' + p.id;
      a.append(node('h2', p.title, 'card__title'), node('p', p.space.name + ' · ' + p.owner.name, 'muted'), node('span', {none:'검증 없음',expired:'검증 만료',verified:'검증됨'}[p.verify_state], 'badge badge--internal'));
      list.appendChild(a);
    }
    next.hidden = result.data.pages.length < 20;
    msg.textContent = list.children.length === 0 ? '절차서가 없습니다.' : '';
  }
  verify.onchange = q.onchange = () => { page = 1; load(false).catch(() => { msg.textContent = '연결하지 못했습니다.'; }); };
  next.onclick = async () => { next.disabled = true; page += 1; try { await load(true); } catch (_) { page -= 1; msg.textContent = '연결하지 못했습니다.'; } finally { next.disabled = false; } }; load(false).catch(() => { msg.textContent = '연결하지 못했습니다.'; });
}
