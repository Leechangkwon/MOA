/**
 * public/assets/community/templates.js — 커뮤니티 7-B 템플릿 (C:F-31 · 결정 J-3)
 *
 *   홈      「템플릿에서」 창 — GET /api/templates(내 것 · 내 팀 · 사이트 기본) → 보기 GET /api/templates/{id}
 *           → 만들기 POST /api/pages {template_id, space_id?} → 새 페이지로 간다 · 지우기 DELETE /api/templates/{id}
 *   페이지  「템플릿으로 저장」 창 — 범위 고르개의 항목은 창구가 준 `template_scopes` 뿐 · POST /api/templates
 *   DB      새 행 고르개 — GET /api/templates?database= (행을 더할 수 있을 때만 부른다) · 고른 행 템플릿 지우기(`can_delete`)
 *
 * ★ 무엇이 보이고 무엇을 할 수 있나는 창구가 준 값(`template_scopes`·`can_delete`·목록)뿐이다.
 *   이 파일에는 역할·공간·범위를 따지는 코드가 없다 (DESIGN 원칙 1, PRD S-01).
 * ★ 미리보기는 읽기 렌더러(`renderDoc`)가 그린다 — 보이는 것과 만들어지는 것이 같다.
 *   글자는 전부 textContent 로 넣는다(HTML 문자열을 넣는 자리가 없다).
 */
import { renderDoc } from './doc-render.js?v=38bbaf960686';

import { repeatDialog } from './automations.js?v=38bbaf960686';

const SCOPE_LABELS = {
  site: '사이트 기본',
  team: '팀',
  personal: '내 템플릿',
  database: '이 데이터베이스'
};
const SCOPE_HELP = {
  personal: '나만 봅니다',
  team: '이 팀 공간의 팀원이 봅니다',
  site: '커뮤니티를 쓰는 모두가 봅니다 (관리자)',
  database: '이 데이터베이스에 행을 더할 수 있는 사람이 봅니다 (속성 값도 담습니다 — 사람·관계·파일 빼고)'
};

function byName(t) {
  if (!t.created_by) { return '기본'; }
  const dn = t.created_by.display_name;
  return dn ? dn + ' (' + t.created_by.username + ')' : t.created_by.username;
}

function scopeText(t) {
  const label = SCOPE_LABELS[t.scope] || t.scope;
  return t.scope === 'team' && t.team ? label + ' · ' + t.team : label;
}

/**
 * 홈 「템플릿에서」 창.
 * @param d {api, setText, show, clear, errorMessage, genericError, toLogin, base, currentSpaceId}
 */
export function bindTemplateGallery(d) {
  const button = document.getElementById('community-template-new');
  const dialog = document.getElementById('template-dialog');
  const tbody = document.getElementById('template-body');
  const empty = document.getElementById('template-empty');
  const msg = document.getElementById('template-msg');
  const listWrap = document.getElementById('template-list-wrap');
  const preview = document.getElementById('template-preview');
  const previewTitle = document.getElementById('template-preview-title');
  const previewDoc = document.getElementById('template-preview-doc');
  const previewBack = document.getElementById('template-preview-back');
  const previewUse = document.getElementById('template-preview-use');
  if (!button || !dialog || !tbody) { return; }
  const repeat = document.createElement('button');
  repeat.type='button';repeat.className='btn btn--quiet';repeat.textContent='이 템플릿으로 반복 만들기';repeat.hidden=true;
  previewUse.parentElement.appendChild(repeat);
  repeat.addEventListener('click', async () => {
    if (!previewing || repeat.disabled) return;
    repeat.disabled=true;
    try {
      const template = previewing; dialog.close();
      repeatDialog(d.api, null, { template });
    } catch (_) { d.setText(msg,d.genericError); }
    finally { repeat.disabled=false; }
  });
  let previewing = null;
  let lastLook = null;   // 미리보기를 연 단추 — 「목록으로」 뒤 초점을 돌려준다(독립 검토 L-8)

  function use(t, btn) {
    btn.disabled = true;
    const here = d.currentSpaceId();
    const payload = { template_id: t.id };
    if (here !== '') { payload.space_id = here; }
    d.api('POST', '/api/pages', payload).then((r) => {
      if (r.status === 401) { d.toLogin(); return; }
      if (r.status === 201 && r.data.page && typeof r.data.page.id === 'string') {
        window.location.assign(d.base + '/p/' + r.data.page.id);
        return;
      }
      d.setText(msg, d.errorMessage(r.data));
      btn.disabled = false;
    }).catch(() => { d.setText(msg, d.genericError); btn.disabled = false; });
  }

  /* 7-C — 미리보기의 임베드 재료(허용 목록). 한 번 받아 둔다 — 없으면 모든 임베드가 「허용 목록 밖」으로 보인다(독립 검토 7-C L-6).
     · 호스트는 창구의 `hosts`(그릴 때의 판정 — 페이지 응답 `embeds.hosts` 와 같음: 이 사이트 자신·상위·하위 도메인은 빠진다).
     · 틀은 **열지 않는다**(frames 거짓 — 목록 안은 「허용 대기 · 새 탭에서 열기」 카드). 틀을 받는 CSP(`frame-src`)는 페이지 화면에만
       붙고(K-5 · nginx 도 그 경로만), 이 창이 뜨는 홈 화면의 CSP 는 바깥 틀을 막는다 — 틀을 그리면 빈 틀이 된다. */
  let embedCtx = null;
  function loadEmbedCtx() {
    if (embedCtx) { return Promise.resolve(embedCtx); }
    return d.api('GET', '/api/embed-domains').then((r) => {
      embedCtx = r.status === 200 && r.data && Array.isArray(r.data.hosts)
        ? { hosts: r.data.hosts.filter((h) => typeof h === 'string'), frames: false }
        : { hosts: [], frames: false };
      return embedCtx;
    }).catch(() => ({ hosts: [], frames: false }));
  }

  function openPreview(t) {
    d.setText(msg, '');
    Promise.all([d.api('GET', '/api/templates/' + t.id), loadEmbedCtx()]).then(([r, emb]) => {
      if (r.status === 401) { d.toLogin(); return; }
      if (r.status !== 200 || !r.data.template) { d.setText(msg, d.errorMessage(r.data)); return; }
      previewing = r.data.template;
      d.setText(previewTitle, (previewing.icon ? previewing.icon + ' ' : '') + previewing.name + ' — 제목: ' + previewing.title);
      d.clear(previewDoc);
      // 템플릿에는 첨부·하위 페이지가 없다 — 링크 제목 지도가 비어 있으면 렌더러가 「볼 수 없는 페이지」로 그린다
      previewDoc.appendChild(renderDoc(previewing.doc, { embeds: emb }));
      d.show(listWrap, false);
      d.show(preview, true);
      previewUse.disabled = false;
      repeat.hidden = d.canWrite !== true;
      previewBack.focus();
    }).catch(() => d.setText(msg, d.genericError));
  }

  function row(t) {
    const tr = document.createElement('tr');
    const name = document.createElement('td');
    name.textContent = (t.icon ? t.icon + ' ' : '') + t.name;
    tr.appendChild(name);
    const scope = document.createElement('td');
    scope.textContent = scopeText(t);
    tr.appendChild(scope);
    const by = document.createElement('td');
    by.textContent = byName(t);
    tr.appendChild(by);
    const actions = document.createElement('td');
    actions.className = 'actions';

    const look = document.createElement('button');
    look.type = 'button';
    look.className = 'btn btn--small btn--quiet';
    look.textContent = '보기';
    look.setAttribute('aria-label', t.name + ' 보기');
    look.addEventListener('click', () => { lastLook = look; openPreview(t); });
    actions.appendChild(look);

    const make = document.createElement('button');
    make.type = 'button';
    make.className = 'btn btn--small btn--quiet';   // 디버깅 묶음 U2 M3 — 창 본문 안의 줄 단추는 테두리(검정은 창마다 바닥의 하나뿐, DESIGN 2.7)
    make.textContent = '만들기';
    make.setAttribute('aria-label', t.name + ' 으로 새 페이지 만들기');
    make.setAttribute('data-template-use', t.id);
    make.addEventListener('click', () => use(t, make));
    actions.appendChild(make);

    if (t.can_delete === true) {
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn--small btn--quiet btn--danger';
      del.textContent = '지우기';
      del.setAttribute('aria-label', t.name + ' 지우기');
      let armed = false;
      // 확인 상태는 단추를 떠나면 풀린다(엉뚱한 때에 한 번 눌러 지워지지 않게)
      del.addEventListener('blur', () => { if (armed) { armed = false; del.textContent = '지우기'; } });
      del.addEventListener('click', () => {
        // 되돌릴 수 없는 동작은 확인이 뒤따른다(DESIGN 4장) — 한 번 더 누르면 지운다
        if (!armed) { armed = true; del.textContent = '정말 지우기'; return; }
        del.disabled = true;
        d.api('DELETE', '/api/templates/' + t.id).then((r) => {
          if (r.status === 401) { d.toLogin(); return; }
          if (r.status !== 200) { d.setText(msg, d.errorMessage(r.data)); del.disabled = false; return; }
          d.setText(msg, '템플릿을 지웠습니다. 이미 만든 페이지는 그대로입니다');
          load().catch(() => d.setText(msg, d.genericError));
        }).catch(() => { d.setText(msg, d.genericError); del.disabled = false; });
      });
      actions.appendChild(del);
    }
    tr.appendChild(actions);
    return tr;
  }

  function load() {
    return d.api('GET', '/api/templates').then((r) => {
      if (r.status === 401) { d.toLogin(); return; }
      if (r.status !== 200) { d.setText(msg, d.errorMessage(r.data)); return; }
      const list = Array.isArray(r.data.templates) ? r.data.templates : [];
      d.clear(tbody);
      for (const t of list) { tbody.appendChild(row(t)); }
      d.show(empty, list.length === 0);
    });
  }

  button.addEventListener('click', () => {
    if (typeof dialog.showModal !== 'function') { return; }
    d.setText(msg, '');
    d.show(preview, false);
    d.show(listWrap, true);
    if (!dialog.open) { dialog.showModal(); }
    load().catch(() => d.setText(msg, d.genericError));
  });
  previewBack.addEventListener('click', () => {
    d.show(preview, false);
    d.show(listWrap, true);
    if (lastLook && lastLook.isConnected) { lastLook.focus(); }
  });
  previewUse.addEventListener('click', () => { if (previewing) { use(previewing, previewUse); } });
  const initialTemplate=new URLSearchParams(window.location.search).get('template');
  if (initialTemplate && /^[0-9a-f-]{36}$/.test(initialTemplate)) {
    if (!dialog.open) dialog.showModal();
    openPreview({id:initialTemplate});
  }

  for (const close of dialog.querySelectorAll('[data-template-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
}

/**
 * 페이지 「템플릿으로 저장」 단추와 창. 돌려주는 `draw(pageId, data)` 를 페이지를 읽을 때마다 부른다.
 * @param d {api, setText, show, clear, errorMessage, genericError, toLogin}
 */
export function createTemplateSave(d) {
  const button = document.getElementById('page-save-template');
  const dialog = document.getElementById('template-save-dialog');
  const nameInput = document.getElementById('template-save-name');
  const scopesBox = document.getElementById('template-save-scopes');
  const msg = document.getElementById('template-save-msg');
  const confirm = document.getElementById('template-save-confirm');
  let pageId = '';
  let title = '';
  let scopes = [];
  if (!button || !dialog) { return { draw: () => {} }; }

  function drawScopes() {
    d.clear(scopesBox);
    scopes.forEach((s, i) => {
      const label = document.createElement('label');
      label.className = 'field field--full';
      const input = document.createElement('input');
      input.type = 'radio';
      input.name = 'template-scope';
      input.value = s;
      input.checked = i === 0;
      const strong = document.createElement('strong');
      strong.textContent = ' ' + (SCOPE_LABELS[s] || s);
      const help = document.createElement('span');
      help.className = 'muted';
      help.textContent = ' — ' + (SCOPE_HELP[s] || '');
      label.appendChild(input);
      label.appendChild(strong);
      label.appendChild(help);
      scopesBox.appendChild(label);
    });
  }

  button.addEventListener('click', () => {
    if (typeof dialog.showModal !== 'function') { return; }
    d.setText(msg, '');
    // 글자(코드 포인트) 단위로 자른다 — UTF-16 단위로 자르면 이모지가 반으로 갈린다(독립 검토 L-7)
    nameInput.value = Array.from(title).slice(0, 60).join('');
    drawScopes();
    confirm.disabled = false;
    if (!dialog.open) { dialog.showModal(); }
    nameInput.focus();
  });
  confirm.addEventListener('click', () => {
    const picked = scopesBox.querySelector('input[name="template-scope"]:checked');
    const name = nameInput.value.trim();
    if (name === '') { nameInput.focus(); return; }
    confirm.disabled = true;
    d.api('POST', '/api/templates', { page_id: pageId, name: name, scope: picked ? picked.value : 'personal' }).then((r) => {
      if (r.status === 401) { d.toLogin(); return; }
      if (r.status !== 201) { confirm.disabled = false; d.setText(msg, d.errorMessage(r.data)); return; }
      // 저장한 뒤에는 「저장」을 끈 채 둔다 — 한 번 더 눌러 같은 템플릿이 둘 생기지 않게(창을 다시 열면 켜진다)
      const n = typeof r.data.dropped_blocks === 'number' ? r.data.dropped_blocks : 0;
      d.setText(msg, '템플릿으로 저장했습니다' + (n > 0 ? ' — 첨부·하위 페이지 블록 ' + String(n) + '개는 담지 않았습니다' : ''));
    }).catch(() => { confirm.disabled = false; d.setText(msg, d.genericError); });
  });
  for (const close of dialog.querySelectorAll('[data-template-save-close]')) {
    close.addEventListener('click', () => dialog.close());
  }

  return {
    draw(id, data) {
      pageId = id;
      title = data && data.page && typeof data.page.title === 'string' ? data.page.title : '';
      scopes = data && Array.isArray(data.template_scopes) ? data.template_scopes.filter((s) => !!SCOPE_LABELS[s]) : [];
      d.show(button, scopes.length > 0);
    }
  };
}

/**
 * DB 새 행 고르개 — 이 DB 의 행 템플릿을 `<select>` 에 채운다(없으면 숨긴 채). 첫 항목은 「빈 행」.
 * 고른 템플릿이 `can_delete` 면 옆의 「템플릿 지우기」가 보인다(두 번 눌러 지운다 — 독립 검토 M-2: 행 템플릿은
 * 홈 목록에 없어, 여기서 못 지우면 한도 20 에 닿았을 때 막힌다).
 * 늦게 온 목록 응답은 버린다(`draw` 가 겹쳐 불려도 항목이 두 번 붙지 않게).
 * @param d {api, show, clear, setText, errorMessage, genericError, toLogin}
 */
export function createRowTemplatePicker(d, select, delButton, msgEl) {
  let seq = 0;
  let dbId = '';
  let byId = {};
  let armed = false;
  const repeat = document.createElement('button');
  repeat.type = 'button'; repeat.className = 'btn btn--quiet'; repeat.textContent = '행 템플릿으로 반복 만들기'; repeat.hidden = true;
  if (select) select.parentElement.appendChild(repeat);
  repeat.addEventListener('click', () => {
    const template = select && byId[select.value];
    if (template) repeatDialog(d.api, null, { template });
  });

  function disarm() { armed = false; if (delButton) { delButton.textContent = '템플릿 지우기'; } }
  function syncDelete() {
    disarm();
    const t = select ? byId[select.value] : null;
    if (delButton) { d.show(delButton, !!t && t.can_delete === true); }
    repeat.hidden = !t;
  }

  function load(id) {
    if (!select) { return; }
    dbId = id;
    const mine = ++seq;
    d.clear(select);
    select.appendChild(new Option('빈 행', ''));
    byId = {};
    d.show(select, false);
    syncDelete();
    d.api('GET', '/api/templates?database=' + encodeURIComponent(id)).then((r) => {
      if (mine !== seq) { return; }
      if (r.status !== 200 || !Array.isArray(r.data.templates) || r.data.templates.length === 0) { return; }
      for (const t of r.data.templates) {
        byId[t.id] = t;
        select.appendChild(new Option((t.icon ? t.icon + ' ' : '') + t.name, t.id));
      }
      d.show(select, true);
    }).catch(() => {});
  }

  if (select) { select.addEventListener('change', syncDelete); }
  if (delButton) {
    delButton.addEventListener('blur', disarm);
    delButton.addEventListener('click', () => {
      const t = byId[select.value];
      if (!t) { return; }
      if (!armed) { armed = true; delButton.textContent = '정말 지우기'; return; }
      delButton.disabled = true;
      d.api('DELETE', '/api/templates/' + t.id).then((r) => {
        delButton.disabled = false;
        if (r.status === 401) { d.toLogin(); return; }
        if (r.status !== 200) { d.setText(msgEl, d.errorMessage(r.data)); return; }
        d.setText(msgEl, '행 템플릿을 지웠습니다. 이미 만든 행은 그대로입니다');
        load(dbId);
      }).catch(() => { delButton.disabled = false; d.setText(msgEl, d.genericError); });
    });
  }

  return {
    load: load,
    /** 새 행을 만든 뒤 「빈 행」으로 되돌린다 — 다음 행이 모르고 같은 틀로 만들어지지 않게(독립 검토 L-7) */
    reset() { if (select) { select.value = ''; } syncDelete(); }
  };
}
