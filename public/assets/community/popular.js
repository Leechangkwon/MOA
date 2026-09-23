/**
 * public/assets/community/popular.js — 커뮤니티 7-D1 「많이 읽힌 문서」 창 (N-34 · 결정 J-6)
 *
 *   홈 「많이 읽힌 문서」 단추 — 창구 `GET /api/boards` 가 `can_manage`(관리자)를 참으로 줄 때만 보인다.
 *   목록 GET /api/admin/popular — `role:Admin` + 재인증(서버가 막는다 · 필요하면 셸의 재인증 창).
 *
 * ★ 목록은 창구가 준 것뿐이다(사내 전체에 열린 페이지만 · 수만). 화면은 범위를 따지지 않는다.
 * ★ 글자는 전부 textContent 로 넣는다.
 */

const SPACE_LABELS = { personal: '개인 공간', board: '게시판', team: '팀 공간' };

/**
 * @param d {api, setText, show, clear, errorMessage, genericError, toLogin, base}
 */
export function bindPopular(d) {
  const button = document.getElementById('community-popular');
  const dialog = document.getElementById('popular-dialog');
  const tbody = document.getElementById('popular-body');
  const empty = document.getElementById('popular-empty');
  const meta = document.getElementById('popular-meta');
  const msg = document.getElementById('popular-msg');
  if (!button || !dialog || !tbody) { return; }

  function row(p, rank) {
    const tr = document.createElement('tr');
    const n = document.createElement('td');
    n.textContent = String(rank);
    tr.appendChild(n);
    const title = document.createElement('td');
    const a = document.createElement('a');
    a.setAttribute('href', d.base + '/p/' + p.id);
    a.textContent = (p.icon ? p.icon + ' ' : '') + p.title;
    title.appendChild(a);
    tr.appendChild(title);
    const kind = document.createElement('td');
    kind.textContent = SPACE_LABELS[p.space_kind] || p.space_kind;
    tr.appendChild(kind);
    const views = document.createElement('td');
    views.textContent = String(p.views);
    tr.appendChild(views);
    const readers = document.createElement('td');
    readers.textContent = String(p.readers);
    tr.appendChild(readers);
    return tr;
  }

  function load() {
    return d.api('GET', '/api/admin/popular').then((r) => {
      if (r.status === 401) { d.toLogin(); return; }
      // 재인증 — 셸의 같은 창을 띄우고, 통과하면 이 창을 다시 열어 다시 읽는다(모달 안에 모달을 열지 않는다)
      if (r.status === 403 && r.data && r.data.next === 'reauth' && typeof window.qarediHandleReauth === 'function') {
        dialog.close();
        window.qarediHandleReauth({ status: 403 }, r.data, () => { dialog.showModal(); load().catch(() => d.setText(msg, d.genericError)); });
        return;
      }
      if (r.status !== 200) { d.clear(tbody); d.setText(meta, ''); d.setText(msg, d.errorMessage(r.data)); return; }
      const list = Array.isArray(r.data.pages) ? r.data.pages : [];
      d.clear(tbody);
      list.forEach((p, i) => tbody.appendChild(row(p, i + 1)));
      d.show(empty, list.length === 0);
      d.setText(meta, '지난 ' + String(r.data.days) + '일 · 사내 전체에 열린 페이지만 · 검토 계정의 조회는 빼고 셉니다');
    });
  }

  button.addEventListener('click', () => {
    if (typeof dialog.showModal !== 'function') { return; }
    d.setText(msg, '');
    if (!dialog.open) { dialog.showModal(); }
    load().catch(() => d.setText(msg, d.genericError));
  });
  for (const close of dialog.querySelectorAll('[data-popular-close]')) {
    close.addEventListener('click', () => dialog.close());
  }
  // 단추는 관리자에게만 — 판단은 창구가 준 `can_manage`(게시판 목록 창구와 같은 값)
  d.api('GET', '/api/boards').then((r) => {
    d.show(button, r.status === 200 && r.data && r.data.can_manage === true);
  }).catch(() => {});
}
