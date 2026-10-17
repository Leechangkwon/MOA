'use strict';
(() => {
  const root = document.getElementById('programs-root');
  if (!root) return;
  const status = document.getElementById('programs-status');
  const base = root.dataset.base;
  const csrf = root.dataset.csrf;
  async function post(path, body) {
    const response = await fetch(base + path, {method: 'POST', credentials: 'same-origin', redirect: 'error',
      headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(body)});
    if (!response.ok) {
      const error = await response.json().catch(() => ({}));
      throw new Error(typeof error.error === 'string' ? error.error : '처리하지 못했습니다. 로그인과 권한을 확인해 주세요.');
    }
    return response;
  }
  root.querySelectorAll('[data-program-download]').forEach(form => {
    form.addEventListener('submit', async event => {
      event.preventDefault();
      const button = form.querySelector('button'); button.disabled = true;
      status.textContent = '업데이트 파일을 준비하고 있습니다…';
      try {
        const response = await post('/api/programs/' + encodeURIComponent(form.dataset.id) + '/download', {ack: true});
        const blob = await response.blob();
        const url = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = url; link.download = form.dataset.name; document.body.appendChild(link); link.click(); link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 30000);
        status.textContent = '다운로드를 시작했습니다. 기존 비서 폴더와 설정을 백업한 뒤 동봉된 업데이트 안내를 따라 주세요.';
      } catch (error) { status.textContent = error.message; }
      finally { button.disabled = false; }
    });
  });
  const control = document.getElementById('program-control');
  if (control) control.addEventListener('submit', async event => {
    event.preventDefault();
    const option = control.elements.target.selectedOptions[0];
    if (!option) return;
    const button = control.querySelector('button'); button.disabled = true;
    const body = {ack: true, expected_current_id: control.dataset.current || null,
      sha256: option.dataset.sha, password: control.elements.password.value, otp: control.elements.otp.value};
    control.elements.password.value = ''; control.elements.otp.value = '';
    try { await post('/api/programs/' + encodeURIComponent(option.value) + '/' + option.dataset.action, body); window.location.reload(); }
    catch (error) { status.textContent = error.message; }
    finally { body.password = ''; body.otp = ''; button.disabled = false; }
  });
  const publish = document.getElementById('program-publish');
  if (publish) publish.addEventListener('submit', async event => {
    event.preventDefault();
    const button = publish.querySelector('button'); button.disabled = true;
    const data = new FormData(publish), level = data.get('level');
    const body = {file_id: data.get('file_id'), version: data.get('version'), sha256: data.get('sha256'),
      level, team_id: level === 'team' ? Number(data.get('team_id')) : null,
      password: data.get('password'), otp: data.get('otp'), ack: true};
    publish.elements.password.value = ''; publish.elements.otp.value = '';
    status.textContent = '게시 정보를 확인하고 있습니다…';
    try { await post('/api/programs', body); window.location.reload(); }
    catch (error) { status.textContent = error.message; }
    finally { body.password = ''; body.otp = ''; button.disabled = false; }
  });
})();
