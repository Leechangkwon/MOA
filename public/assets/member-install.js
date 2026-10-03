'use strict';
(() => {
  const root = document.getElementById('member-install');
  if (!root) return;
  const button = document.getElementById('member-install-start');
  const help = document.getElementById('member-install-help');
  const device = document.getElementById('member-install-device');
  const status = document.getElementById('member-install-status');
  const standalone = window.matchMedia('(display-mode: standalone)');
  const ua = navigator.userAgent || '';
  const inApp = /KAKAOTALK|NAVER\(|Instagram|FBAN|FBAV|Line\//i.test(ua);
  let pendingPrompt = null;
  let prompting = false;
  let installed = standalone.matches || navigator.standalone === true;

  device.value = /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/i.test(ua) && navigator.maxTouchPoints > 1)
    ? 'ios' : /Android/i.test(ua) ? 'android' : 'desktop';
  document.getElementById('member-install-browser-note').hidden = !inApp;

  const renderGuide = () => {
    root.querySelectorAll('[data-install-guide]').forEach(panel => {
      panel.hidden = panel.dataset.installGuide !== device.value;
    });
  };
  const renderButton = () => {
    button.textContent = installed ? '다른 기기 설치 안내' : pendingPrompt ? '모아 앱 설치하기' : '앱 설치 방법 보기';
  };
  const showGuide = () => {
    help.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    renderGuide();
    device.focus();
  };
  const didInstall = () => {
    installed = true;
    pendingPrompt = null;
    renderButton();
    status.textContent = '모아 앱이 설치되어 있어요. 홈 화면이나 앱 목록에서 열어 주세요. 로그인 안내가 나오면 등록한 방법으로 로그인하면 됩니다.';
  };
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    pendingPrompt = event;
    renderButton();
  });
  window.addEventListener('appinstalled', didInstall);
  standalone.addEventListener?.('change', event => { if (event.matches) didInstall(); });
  device.addEventListener('change', renderGuide);
  button.addEventListener('click', async () => {
    if (prompting) return;
    if (!pendingPrompt || installed) { showGuide(); return; }
    const prompt = pendingPrompt;
    pendingPrompt = null;
    prompting = true;
    button.disabled = true;
    try {
      await prompt.prompt();
      const choice = await prompt.userChoice;
      if (!installed) {
        status.textContent = choice.outcome === 'accepted'
          ? '브라우저에 설치를 요청했어요. 설치가 끝나면 홈 화면이나 앱 목록에서 모아를 열어 주세요. 별도 토큰 입력은 필요 없습니다.'
          : '설치를 건너뛰었어요. 지금은 브라우저에서 사용하고 원할 때 다시 설치할 수 있어요.';
      }
    } catch (_) {
      status.textContent = '설치 창을 열지 못했어요. 아래에서 기기에 맞는 설치 방법을 확인해 주세요.';
      showGuide();
    } finally {
      prompting = false;
      button.disabled = false;
      renderButton();
    }
  });
  renderGuide();
  if (installed) didInstall(); else renderButton();
})();
