'use strict';
(() => {
  const form = document.getElementById('client-diagnostics-policy');
  if (!form) return;
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button');
    const status = document.getElementById('client-diagnostics-status');
    button.disabled = true;
    try {
      const body = {daily_enabled: form.elements.daily_enabled.checked,
        weekly_enabled: form.elements.weekly_enabled.checked,
        daily_hour: Number(form.elements.daily_hour.value),
        weekly_weekday: Number(form.elements.weekly_weekday.value),
        retention_days: Number(form.elements.retention_days.value)};
      const response = await fetch(form.dataset.base + '/api/admin/client-diagnostics', {
        method: 'POST', credentials: 'same-origin', redirect: 'error',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': form.dataset.csrf},
        body: JSON.stringify(body)});
      status.textContent = response.ok ? '진단 운영 설정을 저장했습니다.' : '저장하지 못했습니다. 로그인·재인증과 입력 범위를 확인하세요.';
    } catch (_) { status.textContent = '서버 연결을 확인하세요. 기존 설정은 다시 조회해 확인할 수 있습니다.'; }
    finally { button.disabled = false; }
  });
})();
