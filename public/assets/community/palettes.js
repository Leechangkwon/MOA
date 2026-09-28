/**
 * public/assets/community/palettes.js — 커뮤니티 7-D2 「팔레트 관리」 창 (C:F-33 · 결정 L-2 · 지시서 1.5 · P-7 · P-12)
 *
 *   홈 「더보기」 → 「팔레트 관리」 — 창구 `GET /api/boards` 가 `can_manage`(관리자)를 참으로 줄 때만 항목이 보인다(7-D1 과 같은 자리).
 *   목록 GET /api/palettes · 더하기 POST /api/palettes {name, light, dark} · 고치기 PATCH /api/palettes/{번호} {light?, dark?} ·
 *   빼기 DELETE /api/palettes/{번호} — 바꾸는 셋은 `role:Admin` + 재인증(서버가 막는다 · 필요하면 셸의 재인증 창).
 *
 * ★ 역할을 따지는 코드가 없다 — 보이는지는 `can_manage`, 막는 것은 서버다. 글자는 전부 textContent.
 * ★ 인라인 style 0 — 견본 색은 서버가 만든 CSS(`<link id="palette-css">`)의 클래스 `pal-<이름>`·`pal-<이름>-bg` 가 준다.
 *   창구가 준 `version` 으로 그 링크의 `href` 를 바꿔 **그 자리에서 다시 읽는다**(P-7 — 더하기·고치기·빼기 뒤 견본이 바로 보이게).
 * ★ 대비 경고(P-12)는 알림일 뿐 저장을 막지 않는다 — WCAG 2.1 상대 휘도로 라이트값 대 흰 바탕, 다크값 대 다크 바탕을 재어 4.5:1 미만이면
 *   그 줄에 주황 배지(`.badge--exec` — 「알고 하라」, DESIGN 0장 4) + `.muted` 한 줄.
 */

/**
 * 대비를 잴 화면 바탕 — app.css `--bg` 두 값(라이트 흰색 · 다크 zinc-950)과 같다(서버 `Palette::LIGHT_BG`·`DARK_BG` — 시험이 셋을 대조한다).
 * 화면에 칠하는 색이 아니라 **계산의 재료**다.
 */
const LIGHT_PAGE_BG = '#ffffff';
const DARK_PAGE_BG = '#09090b';
const MIN_CONTRAST = 4.5;

/** `#rrggbb` → WCAG 2.1 상대 휘도. 모양이 아니면 null */
function luminance(hex) {
  if (typeof hex !== 'string' || !/^#[0-9a-f]{6}$/.test(hex)) { return null; }
  const ch = [1, 3, 5].map((i) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
}

/** 두 색의 대비(1~21). 하나라도 모양이 아니면 null */
export function contrastRatio(a, b) {
  const la = luminance(a);
  const lb = luminance(b);
  if (la === null || lb === null) { return null; }
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** 한 줄의 대비 경고 문장들(없으면 빈 목록) — 소수 첫째 자리에서 **내린** 값으로 적는다(4.49 가 「4.5」로 보이지 않게) */
function contrastWarnings(light, dark) {
  const out = [];
  const pairs = [['밝은 화면', light, LIGHT_PAGE_BG], ['어두운 화면', dark, DARK_PAGE_BG]];
  for (const [where, fg, bg] of pairs) {
    const r = contrastRatio(fg, bg);
    if (r !== null && r < MIN_CONTRAST) {
      out.push(where + ' 대비 ' + (Math.floor(r * 10) / 10).toFixed(1) + ':1 — 본문 글자로는 낮습니다');
    }
  }
  return out;
}

/** `#palette-css` 링크를 이 버전으로 — 같은 출처 CSS 파일을 다시 받는다(인라인 style 0). 버전 모양이 아니면 건드리지 않는다 */
function refreshPaletteCss(version) {
  const link = document.getElementById('palette-css');
  if (!link || typeof version !== 'string' || !/^[0-9a-f]{12}$/.test(version)) { return; }
  const href = link.getAttribute('href') || '';
  const at = href.indexOf('?');
  const next = (at === -1 ? href : href.slice(0, at)) + '?v=' + version;
  if (next !== href) { link.setAttribute('href', next); }
}

/**
 * @param d {api, setText, show, clear, errorMessage, genericError, toLogin}
 */
export function bindPalettes(d) {
  const button = document.getElementById('community-palettes');
  const dialog = document.getElementById('palette-dialog');
  const list = document.getElementById('palette-list');
  const empty = document.getElementById('palette-empty');
  const count = document.getElementById('palette-count');
  const msg = document.getElementById('palette-msg');
  const form = document.getElementById('palette-form');
  const nameInput = document.getElementById('palette-name');
  const lightInput = document.getElementById('palette-light');
  const darkInput = document.getElementById('palette-dark');
  const formWarn = document.getElementById('palette-form-warn');
  const add = document.getElementById('palette-add');
  if (!button || !dialog || !list || !form) { return; }

  /** 재인증이 필요하면 셸의 같은 창을 띄우고, 통과하면 이 창을 다시 열어 같은 일을 다시 한다(모달 안에 모달을 열지 않는다) */
  function reauthThen(result, again) {
    if (result.status === 403 && result.data && result.data.next === 'reauth' && typeof window.qarediHandleReauth === 'function') {
      dialog.close();
      window.qarediHandleReauth({ status: 403 }, result.data, () => { dialog.showModal(); again(); });
      return true;
    }
    return false;
  }

  function sample(cls, label) {
    const s = document.createElement('span');
    s.className = cls;
    s.textContent = '가';
    s.setAttribute('title', label);
    return s;
  }

  /** 대비 경고 — 배지 「대비 낮음」 + `.muted` 한 줄씩(사이는 빈칸 글자 — 새 클래스·간격 규칙 0) */
  function warnInto(box, light, dark) {
    d.clear(box);
    for (const line of contrastWarnings(light, dark)) {
      if (box.firstChild) { box.appendChild(document.createTextNode(' ')); }
      const badge = document.createElement('span');
      badge.className = 'badge badge--exec';
      badge.textContent = '대비 낮음';
      const text = document.createElement('span');
      text.className = 'muted';
      text.textContent = line;
      box.appendChild(badge);
      box.appendChild(document.createTextNode(' '));
      box.appendChild(text);
    }
  }

  function colorField(labelText, value) {
    const label = document.createElement('label');
    const t = document.createElement('span');
    t.textContent = labelText + ' ';
    const input = document.createElement('input');
    input.type = 'color';
    input.className = 'input';
    input.value = value;
    input.setAttribute('aria-label', labelText);
    label.appendChild(t);
    label.appendChild(input);
    return { label: label, input: input };
  }

  function row(p) {
    const li = document.createElement('li');
    li.className = 'list__item';
    li.setAttribute('data-palette', p.name);
    const head = document.createElement('div');
    head.className = 'list__head';
    const strong = document.createElement('strong');
    strong.textContent = p.name;
    head.appendChild(strong);
    head.appendChild(sample('pal-' + p.name, '글자색 견본'));
    head.appendChild(sample('pal-' + p.name + '-bg', '배경색 견본'));
    const vals = document.createElement('span');
    vals.className = 'muted';
    vals.textContent = '밝은 ' + p.light + ' · 어두운 ' + p.dark;
    head.appendChild(vals);
    const warn = document.createElement('span');
    warn.setAttribute('data-palette-warn', '');
    warnInto(warn, p.light, p.dark);
    head.appendChild(warn);
    li.appendChild(head);

    const body = document.createElement('div');
    body.className = 'list__body';
    const light = colorField('밝은 화면 색 — ' + p.name, p.light);
    const dark = colorField('어두운 화면 색 — ' + p.name, p.dark);
    body.appendChild(light.label);
    body.appendChild(dark.label);
    const onInput = () => warnInto(warn, light.input.value, dark.input.value);
    light.input.addEventListener('input', onInput);
    dark.input.addEventListener('input', onInput);

    const save = document.createElement('button');
    save.type = 'button';
    save.className = 'btn btn--small btn--quiet';
    save.textContent = '고치기';
    save.setAttribute('aria-label', p.name + ' 고치기');
    const change = () => {
      const payload = {};
      if (light.input.value !== p.light) { payload.light = light.input.value; }
      if (dark.input.value !== p.dark) { payload.dark = dark.input.value; }
      if (Object.keys(payload).length === 0) { d.setText(msg, p.name + ' — 바뀐 색이 없습니다'); return; }
      save.disabled = true;
      d.api('PATCH', '/api/palettes/' + String(p.id), payload).then((r) => {
        save.disabled = false;
        if (r.status === 401) { d.toLogin(); return; }
        if (reauthThen(r, change)) { return; }
        if (r.status !== 200) { d.setText(msg, d.errorMessage(r.data)); return; }
        refreshPaletteCss(r.data.version);
        d.setText(msg, p.name + ' 의 색을 고쳤습니다. 이 색을 쓴 글이 모두 새 색으로 보입니다');
        load().catch(() => d.setText(msg, d.genericError));
      }).catch(() => { save.disabled = false; d.setText(msg, d.genericError); });
    };
    save.addEventListener('click', change);
    body.appendChild(save);

    // 빼기 — **두 번 누르기**(5-D 댓글 지우기와 같은 규칙 · 모달·confirm 0). 초점이 떠나면 처음으로
    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn btn--small btn--danger';
    del.textContent = '빼기';
    del.setAttribute('aria-label', p.name + ' 빼기');
    let armed = false;
    del.addEventListener('blur', () => { if (armed) { armed = false; del.textContent = '빼기'; } });
    const remove = () => {
      del.disabled = true;
      d.api('DELETE', '/api/palettes/' + String(p.id)).then((r) => {
        del.disabled = false;
        if (r.status === 401) { d.toLogin(); return; }
        if (reauthThen(r, remove)) { return; }
        if (r.status !== 200) { d.setText(msg, d.errorMessage(r.data)); return; }
        refreshPaletteCss(r.data.version);
        d.setText(msg, p.name + ' 을(를) 뺐습니다. 이 색을 쓴 글은 색 없이 보입니다');
        load().catch(() => d.setText(msg, d.genericError));
      }).catch(() => { del.disabled = false; d.setText(msg, d.genericError); });
    };
    del.addEventListener('click', () => {
      if (!armed) { armed = true; del.textContent = '정말 빼기'; return; }
      armed = false;
      remove();
    });
    body.appendChild(del);
    li.appendChild(body);
    return li;
  }

  function load() {
    return d.api('GET', '/api/palettes').then((r) => {
      if (r.status === 401) { d.toLogin(); return; }
      if (r.status !== 200) { d.setText(msg, d.errorMessage(r.data)); return; }
      const rows = Array.isArray(r.data.palettes) ? r.data.palettes : [];
      d.clear(list);
      for (const p of rows) {
        if (p && typeof p.name === 'string' && typeof p.light === 'string' && typeof p.dark === 'string') { list.appendChild(row(p)); }
      }
      d.show(list, rows.length > 0);
      d.show(empty, rows.length === 0);
      d.setText(count, String(rows.length) + ' / ' + String(r.data.limit));
      refreshPaletteCss(r.data.version);
    });
  }

  function formWarning() {
    if (formWarn) { warnInto(formWarn, lightInput.value, darkInput.value); }
  }

  function submit() {
    const name = nameInput.value;
    if (name === '') { nameInput.focus(); return; }
    add.disabled = true;
    d.api('POST', '/api/palettes', { name: name, light: lightInput.value, dark: darkInput.value }).then((r) => {
      add.disabled = false;
      if (r.status === 401) { d.toLogin(); return; }
      if (reauthThen(r, submit)) { return; }
      if (r.status !== 201) { d.setText(msg, d.errorMessage(r.data)); return; }
      nameInput.value = '';
      if (formWarn) { d.clear(formWarn); }
      refreshPaletteCss(r.data.version);
      d.setText(msg, r.data.palette.name + ' 을(를) 더했습니다. 편집기의 「색」·「배경」 줄에서 고를 수 있습니다');
      load().catch(() => d.setText(msg, d.genericError));
    }).catch(() => { add.disabled = false; d.setText(msg, d.genericError); });
  }

  form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });
  lightInput.addEventListener('input', formWarning);
  darkInput.addEventListener('input', formWarning);
  button.addEventListener('click', () => {
    if (typeof dialog.showModal !== 'function') { return; }
    d.setText(msg, '');
    if (formWarn) { d.clear(formWarn); }   // 더하기 칸의 경고는 색을 고른 뒤에만(처음 여는 순간의 기본값으로 경고를 띄우지 않는다)
    if (!dialog.open) { dialog.showModal(); }
    load().catch(() => d.setText(msg, d.genericError));
  });
  for (const close of dialog.querySelectorAll('[data-palette-close]')) {
    close.addEventListener('click', () => dialog.close());
  }

  // 항목은 관리자에게만 — 판단은 창구가 준 `can_manage`(게시판 목록 창구와 같은 값 · 7-D1 과 같은 자리)
  d.api('GET', '/api/boards').then((r) => {
    d.show(button, r.status === 200 && r.data && r.data.can_manage === true);
  }).catch(() => {});
}
