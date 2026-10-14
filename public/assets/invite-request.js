'use strict';
/* 사이트 H2 — 초대 요청 화면 · 요청 확인 화면 (DESIGN 2.32 · 기획 ../docs/invite-request-plan.md 2장).
   ① /tbox/request-invite      폼 → POST /api/invite-requests(가입과 같은 CSRF 헤더 X-Join-CSRF) → 같은 패널이 완료로 바뀐다:
                               확인 주소(읽기 전용 칸 + 「복사」) · 「다시 보여 드릴 수 없어요」 안내. 주소는 응답의 status_path 하나뿐이다.
   ② /tbox/request-invite/status  주소 `#code=` 뒤 코드를 읽어 POST /api/invite-requests/status 의 **본문**으로만 보낸다(주소 쿼리 · 저장소 0).
                               결과 = 검토 중 · 반려 · 초대됨(「가입하기」 = 받은 초대 주소) · 가입을 마침 · 세 번 받음 · 주소가 틀림.
   글자는 전부 textContent · 인라인 style 0 · 외부 요청 0. 판단은 서버가 한다(화면은 받은 상태를 그릴 뿐이다). */
(() => {
  const base = document.body.dataset.base || '/tbox';
  const csrf = document.body.dataset.joinCsrf || '';
  const $ = (id) => document.getElementById(id);

  async function post(path, body) {
    const r = await fetch(base + path, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-Join-CSRF': csrf },
      body: JSON.stringify(body)
    });
    let data = {};
    try { data = await r.json(); } catch (_) { data = {}; }
    return { status: r.status, ok: r.ok, data };
  }

  // ── ① 요청 폼 ──
  const form = $('invite-request-form');
  if (form) {
    const reason = $('invite-request-reason');
    const count = $('invite-request-count');
    const msg = $('invite-request-msg');
    const paint = () => { count.textContent = String(reason.value.length) + ' / 500자'; };
    reason.addEventListener('input', paint);
    paint();
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      msg.textContent = '';
      const name = $('invite-request-name').value.trim();
      const org = $('invite-request-org').value.trim();
      const why = reason.value.trim();
      if (!name || !org || !why) { msg.textContent = '이름 · 소속 · 쓰려는 이유를 모두 적어 주세요.'; return; }
      if (!$('invite-request-agree').checked) { msg.textContent = '개인정보 처리방침(초대 요청 항목)을 확인해 주세요.'; return; }
      const button = form.querySelector('button[type="submit"]');
      button.disabled = true;
      try {
        const res = await post('/api/invite-requests', { name, org, reason: why, agree: true, website: $('invite-request-website').value });
        if (!res.ok || typeof res.data.status_path !== 'string') {
          msg.textContent = typeof res.data.error === 'string' ? res.data.error : '요청을 보내지 못했어요. 잠시 뒤 다시 시도해 주세요.';
          return;
        }
        $('invite-request-url').value = location.origin + res.data.status_path;
        form.hidden = true;
        $('invite-request-done').hidden = false;
        $('invite-request-url').focus();
        $('invite-request-url').select();
      } catch (_) {
        msg.textContent = '요청을 보내지 못했어요. 연결을 확인하고 다시 시도해 주세요.';
      } finally {
        button.disabled = false;
      }
    });
    $('invite-request-copy').addEventListener('click', async () => {
      const input = $('invite-request-url');
      const note = $('invite-request-copied');
      try {
        await navigator.clipboard.writeText(input.value);
        note.textContent = '복사했어요.';
      } catch (_) {
        input.focus();
        input.select();
        note.textContent = '자동 복사가 막혔어요 — 선택된 주소를 직접 복사해 주세요.';
      }
    });
    addEventListener('pagehide', () => { $('invite-request-url').value = ''; });
  }

  // ── ② 요청 확인 ──
  const statusText = $('invite-status-text');
  if (statusText) {
    const join = $('invite-status-join');
    const login = $('invite-status-login');
    const say = (text) => { statusText.textContent = text; };
    const lookup = () => {
      join.hidden = true;
      login.hidden = true;
      const code = new URLSearchParams(location.hash.slice(1)).get('code') || '';
      if (!/^[a-f0-9]{64}$/.test(code)) { say('요청 확인 주소가 올바르지 않아요.'); return; }
      say('확인하고 있어요…');
      post('/api/invite-requests/status', { code }).then((res) => {
        const st = res.data && res.data.status;
        if (res.ok && st === 'open') { say('검토 중이에요 — 관리자가 확인하면 여기서 바로 볼 수 있어요.'); return; }
        if (res.ok && st === 'rejected') { say('이번에는 초대해 드리기 어려워요.'); return; }
        if (res.ok && st === 'joined') { say('가입을 마쳤어요 — 로그인해서 시작해 주세요.'); login.hidden = false; return; }
        if (res.ok && st === 'approved' && typeof res.data.invite_path === 'string' && /^\/tbox\/join#invite=[a-f0-9]{64}$/.test(res.data.invite_path)) {
          say('초대됐어요 — 아래 「가입하기」로 계정을 만들어 주세요. 초대 링크는 7일 동안 한 번 쓸 수 있어요.');
          join.href = res.data.invite_path;
          join.hidden = false;
          return;
        }
        if (res.status === 404) { say('요청 확인 주소가 올바르지 않아요.'); return; }
        if (res.status === 409 && typeof res.data.error === 'string') { say(res.data.error); return; }
        say(typeof res.data.error === 'string' ? res.data.error : '결과를 불러오지 못했어요. 잠시 뒤 다시 열어 주세요.');
      }).catch(() => say('결과를 불러오지 못했어요. 연결을 확인하고 다시 열어 주세요.'));
    };
    lookup();
    // 같은 탭에 다른 확인 주소를 붙여 넣으면(주소 `#` 뒤만 바뀜 — 화면을 다시 읽지 않는다) 다시 묻는다
    addEventListener('hashchange', lookup);
  }
})();
