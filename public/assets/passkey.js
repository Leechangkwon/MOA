'use strict';
/* public/assets/passkey.js — T:F-72 휴대폰 지문·얼굴로 로그인(패스키 · WebAuthn) — 로그인 · 내 계정 · 재인증 · 권장 카드 공용
 *
 * 지시서 /var/www/qaredi/docs/passkey-mobile-directive.md 1.1 · 1.2 · 1.9 · 결정 PK-D1(PC 는 화면에서만 숨김) ·
 * PK-D2(로그인 화면이 열리면 「이 기기 표시」가 있을 때 곧바로 지문 창) · PK-D3(등록마다 비밀번호 + 2단계 코드) · PK-D5(앱 하나).
 *
 * ★ 화면은 판단하지 않는다(DESIGN 0장 1). 휴대폰인지·열쇠가 있는지는 **단추를 보일지** 고르는 편의일 뿐이고, 서버가 요청마다
 *   챌린지(한 번 · 묶음)·origin·서명·서명 횟수·계정 상태를 다시 본다. 휴대폰 판정은 서버로 보내지 않는다.
 * ★ 「이 기기 표시」 = localStorage `qaredi.passkey-here` 한 값 `1` — 아이디·계정 번호·열쇠 번호 **없음**. 켜는 때: 이 브라우저에서 등록 성공 ·
 *   지문 로그인 성공. 끄는 때: 「내 계정」에서 해제해 내 열쇠가 0 이 됨 · 서버가 「꺼진 열쇠」라고 답함 · 곧바로 뜬 창을 연달아 두 번 취소.
 *   읽기·쓰기는 전부 try/catch — 막힌 브라우저면 표시 없음(단추와 자동 완성만).
 * ★ 글자는 전부 textContent · 요소는 createElement(문자열 → HTML 변환 0) · 브라우저 기본 창(alert·prompt·confirm) 0 — 확인은 셸의
 *   공용 확인 창(`window.qarediOpenConfirm`). 새 CSS 클래스·색값 0(`.btn` · `.btn--quiet` · `.btn--block` · `.btn--small` ·
 *   `.notice` · `.table-wrap` · `.modal--form` 등 기존 구성 요소만).
 * 로그인 뒤 이동은 비밀번호 로그인과 **한 함수**(`window.qarediLoginGo` — app.js loginDestination, Q:F-7 B1-1 허용 목록).
 */
(function () {
  var BASE = (document.body && document.body.getAttribute('data-base')) || '/tbox';
  var HERE_KEY = 'qaredi.passkey-here';          // localStorage — 이 브라우저에서 지문 로그인을 쓴 적 있다(값 1)
  var AUTO_CANCEL_KEY = 'qaredi.passkey-autocancel'; // localStorage — 곧바로 뜬 창을 연달아 취소한 수
  var OUT_KEY = 'qaredi.just-logged-out';        // sessionStorage — 이 탭에서 방금 로그아웃했다(탭마다 한 번)
  var TAB_CANCEL_KEY = 'qaredi.passkey-tab-cancel'; // sessionStorage — 이 탭에서 곧바로 뜬 창을 한 번 취소했다
  var LATER_KEY = 'qaredi.passkey-card-later';   // sessionStorage — 권장 카드 「나중에」
  var TRANSPORTS = ['internal', 'hybrid', 'usb', 'nfc', 'ble', 'smart-card'];
  var MSG_FAIL = '지문·얼굴 로그인이 되지 않았습니다. 다시 누르거나 비밀번호로 로그인하세요. 이 휴대폰을 아직 등록하지 않았다면 비밀번호로 로그인한 뒤 「내 계정」에서 등록하세요.';
  var GENERIC = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';

  // ─── 작은 도우미 ─────────────────────────────────────────────
  function setText(el, t) { if (el) { el.textContent = t === null || t === undefined ? '' : String(t); } }
  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* 저장이 막힌 브라우저 — 표시 없음으로 돈다 */ } }
  function lsDel(k) { try { window.localStorage.removeItem(k); } catch (e) { /* 무시 */ } }
  function ssGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* 무시 */ } }
  function ssDel(k) { try { window.sessionStorage.removeItem(k); } catch (e) { /* 무시 */ } }
  function markHere() { lsSet(HERE_KEY, '1'); lsDel(AUTO_CANCEL_KEY); }
  function unmarkHere() { lsDel(HERE_KEY); lsDel(AUTO_CANCEL_KEY); }
  function hasHere() { return lsGet(HERE_KEY) === '1'; }

  function b64uToBuf(s) {
    var b = String(s).replace(/-/g, '+').replace(/_/g, '/');
    while (b.length % 4) { b += '='; }
    var bin = atob(b);
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
    return out.buffer;
  }
  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf);
    var bin = '';
    for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function supported() {
    return typeof window.PublicKeyCredential === 'function' && !!navigator.credentials
      && typeof navigator.credentials.get === 'function' && window.isSecureContext === true;
  }

  /** 휴대폰·태블릿인가(PK-D1 — 화면 편의, 권한 아님): userAgentData.mobile · UA · 터치 맥(iPad) · 손가락만 있는 기기(폴드 데스크톱 모드) */
  function isMobile() {
    try {
      if (navigator.userAgentData && navigator.userAgentData.mobile === true) { return true; }
    } catch (e) { /* 무시 */ }
    var ua = navigator.userAgent || '';
    if (/Android|iPhone|iPad|iPod|Mobile/i.test(ua)) { return true; }
    if (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) { return true; }
    try {
      return window.matchMedia('(pointer: coarse)').matches && !window.matchMedia('(any-pointer: fine)').matches;
    } catch (e) {
      return false;
    }
  }

  /** 이 기기에 지문·얼굴 확인 인증기가 있나(휴대폰 판정과 둘 다 참일 때만 단추) */
  function platformReady() {
    if (!supported() || !isMobile()) { return Promise.resolve(false); }
    try {
      return window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
        .then(function (ok) { return ok === true; }, function () { return false; });
    } catch (e) {
      return Promise.resolve(false);
    }
  }

  function postJson(path, body, csrf) {
    var h = { 'Content-Type': 'application/json' };
    if (csrf) { h['X-CSRF-Token'] = csrf; }
    return fetch(BASE + path, {
      method: 'POST', headers: h, body: JSON.stringify(body || {}), credentials: 'same-origin', cache: 'no-store'
    }).then(function (r) {
      return r.json().then(function (d) { return { status: r.status, data: d && typeof d === 'object' ? d : {} }; },
        function () { return { status: r.status, data: {} }; });
    });
  }
  function errText(data) { return data && typeof data.error === 'string' && data.error !== '' ? data.error : GENERIC; }

  /** 서버가 준 get 옵션(JSON) → 브라우저 모양 */
  function getOptions(pk, signal, mediation) {
    var o = {
      challenge: b64uToBuf(pk.challenge),
      rpId: pk.rpId,
      timeout: pk.timeout,
      userVerification: 'required',
      allowCredentials: (pk.allowCredentials || []).map(function (c) {
        var d = { type: 'public-key', id: b64uToBuf(c.id) };
        if (Array.isArray(c.transports) && c.transports.length > 0) { d.transports = c.transports; }
        return d;
      })
    };
    var opt = { publicKey: o };
    if (signal) { opt.signal = signal; }
    if (mediation) { opt.mediation = mediation; }
    return opt;
  }
  /** 브라우저가 준 확인(assertion) → 서버로 보낼 JSON(base64url) */
  function assertionJson(cred) {
    var r = cred.response;
    return {
      id: cred.id,
      rawId: bufToB64u(cred.rawId),
      type: cred.type,
      clientDataJSON: bufToB64u(r.clientDataJSON),
      authenticatorData: bufToB64u(r.authenticatorData),
      signature: bufToB64u(r.signature),
      userHandle: r.userHandle ? bufToB64u(r.userHandle) : null,
      authenticatorAttachment: cred.authenticatorAttachment || null
    };
  }

  // 로그아웃하면 이 탭에 표식 — 다음 로그인 화면은 곧바로 지문 창을 띄우지 않는다(PK-D2 — 방금 나간 사람에게 다시 묻지 않게)
  document.addEventListener('qaredi:logout', function () { ssSet(OUT_KEY, '1'); });

  // ═══ 로그인 화면 (지시서 1.2 — ⓐ 단추 · ⓑ 자동 완성 · ⓒ 곧바로 지문 창) ═══════════════════════
  function initLogin() {
    var form = document.getElementById('login-form');
    var btn = document.getElementById('passkey-login-btn');
    var pwOpen = document.getElementById('passkey-password-open');
    var msg = document.getElementById('msg');
    var submit = document.getElementById('login-submit');
    var user = document.getElementById('username');
    var pass = document.getElementById('password');
    if (!form || !btn || !pwOpen) { return; }
    var pwParts = [user ? user.closest('label') : null, pass ? pass.closest('label') : null, submit];
    var justOut = ssGet(OUT_KEY) === '1';
    ssDel(OUT_KEY);   // 탭마다 한 번

    platformReady().then(function (ready) {
      if (!ready) { return; }   // PC·지원 안 함 — 지금 폼 그대로(단추·자동 완성 0)
      var here = hasHere();
      var conditional = null;   // 자동 완성(ⓑ)의 AbortController
      var busy = false;

      function showPassword() {
        pwParts.forEach(function (el) { if (el) { el.hidden = false; } });
        pwOpen.hidden = true;
        btn.className = 'btn btn--block btn--quiet';   // 검정은 폼마다 하나(DESIGN 2.1) — 비밀번호 폼의 「로그인」이 검정
        if (user) { user.focus(); }
      }

      btn.hidden = false;
      if (here) {
        // 표시 있음 → 검정 「지문·얼굴로 로그인」 + 아래 「비밀번호로 로그인」(누르면 칸이 펼쳐짐)
        btn.className = 'btn btn--block';
        pwParts.forEach(function (el) { if (el) { el.hidden = true; } });
        pwOpen.hidden = false;
        if (user) { user.removeAttribute('autofocus'); }
      }
      pwOpen.addEventListener('click', showPassword);

      function finish(cred) {
        return postJson('/api/login/passkey', assertionJson(cred), null).then(function (res) {
          if (res.status === 200 && res.data && res.data.ok === true) {
            markHere();
            if (typeof window.qarediLoginGo === 'function') {
              window.qarediLoginGo();
            } else {
              window.location.assign(BASE + '/');
            }
            return true;
          }
          if (res.data && res.data.passkey_off === true) {
            unmarkHere();   // 서버가 「꺼진 열쇠」라고 답했다 — 다음에 곧바로 뜨는 창을 막는다
            setText(msg, errText(res.data));
            showPassword();
            return false;
          }
          setText(msg, res.status === 423 || res.status === 503 ? errText(res.data) : MSG_FAIL);
          return false;
        });
      }

      function fetchOptions() {
        return postJson('/api/login/passkey/options', {}, null).then(function (res) {
          if (res.status !== 200 || !res.data.publicKey) {
            throw new Error(errText(res.data));
          }
          return res.data.publicKey;
        });
      }

      // ⓑ 자동 완성 — 아이디 칸을 누르면 키보드 위 제안에 「Qaredi」 열쇠. 4분마다 새 문제로 다시 건다(로그인 챌린지 5분)
      var condTimer = null;
      function armConditional() {
        if (busy || conditional !== null || typeof window.PublicKeyCredential.isConditionalMediationAvailable !== 'function') {
          return;
        }
        window.PublicKeyCredential.isConditionalMediationAvailable().then(function (ok) {
          if (!ok || busy || conditional !== null) { return; }
          fetchOptions().then(function (pk) {
            if (busy) { return; }
            var ac = new AbortController();
            conditional = ac;
            if (condTimer !== null) { window.clearTimeout(condTimer); }
            condTimer = window.setTimeout(function () {
              if (conditional === ac) { ac.abort(); conditional = null; armConditional(); }
            }, 240000);
            navigator.credentials.get(getOptions(pk, ac.signal, 'conditional')).then(function (cred) {
              if (conditional === ac) { conditional = null; }
              if (!cred) { return; }
              busy = true;
              finish(cred).then(function (ok) { if (!ok) { busy = false; armConditional(); } });
            }, function () {
              if (conditional === ac) { conditional = null; }
            });
          }, function () { /* 옵션을 못 받음 — 단추·비밀번호 길은 그대로 */ });
        }, function () { /* 무시 */ });
      }
      function stopConditional() {
        if (conditional !== null) { conditional.abort(); conditional = null; }
        if (condTimer !== null) { window.clearTimeout(condTimer); condTimer = null; }
      }

      // ⓐ·ⓒ 지문 창(모달)
      function modal(auto) {
        if (busy) { return; }
        busy = true;
        stopConditional();
        setText(msg, '');
        btn.disabled = true;
        var started = Date.now();
        fetchOptions().then(function (pk) {
          return navigator.credentials.get(getOptions(pk, null, null)).then(function (cred) {
            if (!cred) { throw new Error('none'); }
            return finish(cred).then(function (ok) {
              if (ok) { return; }
              busy = false;
              btn.disabled = false;
              armConditional();
            });
          });
        }).catch(function (err) {
          busy = false;
          btn.disabled = false;
          var name = err && err.name ? err.name : '';
          if (auto) {
            // 곧바로 뜬 창을 취소(또는 브라우저가 누름 없는 호출을 거절) — 조용히 단추로. 0.3초 안에 끝난 거절은 사람의 취소가 아니다
            ssSet(TAB_CANCEL_KEY, '1');
            if (name === 'NotAllowedError' && Date.now() - started > 300) {
              var n = parseInt(lsGet(AUTO_CANCEL_KEY) || '0', 10) + 1;
              if (n >= 2) { unmarkHere(); } else { lsSet(AUTO_CANCEL_KEY, String(n)); }
            }
          } else if (name === 'NotAllowedError' || name === 'AbortError') {
            setText(msg, MSG_FAIL);
          } else {
            setText(msg, err && err.message && err.message !== 'none' && name === '' ? err.message : MSG_FAIL);
          }
          armConditional();
        });
      }
      btn.addEventListener('click', function () { modal(false); });

      // ⓒ 곧바로 — 표시가 있고 · 방금 로그아웃하지 않았고 · 이 탭에서 취소한 적이 없을 때만. 끝난(취소된) 뒤에 ⓑ
      if (here && !justOut && ssGet(TAB_CANCEL_KEY) !== '1') {
        modal(true);
      } else {
        armConditional();
      }
    });
  }

  // ═══ 「내 계정」 — 지문·얼굴 로그인 패널 (지시서 1.9) ═════════════════════════════════════════
  function deviceGuess() {
    var ua = navigator.userAgent || '';
    var dev = /iPhone/.test(ua) ? 'iPhone' : (/iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) ? 'iPad'
      : /Android/.test(ua) ? 'Android' : '휴대폰';
    var br = /SamsungBrowser/.test(ua) ? 'Samsung Internet' : /Edg\//.test(ua) ? 'Edge' : /Firefox|FxiOS/.test(ua) ? 'Firefox'
      : /Chrome|CriOS/.test(ua) ? 'Chrome' : /Safari/.test(ua) ? 'Safari' : '';
    return (dev + (br === '' || dev === 'iPhone' || dev === 'iPad' ? '' : ' ' + br)).slice(0, 40);
  }

  function initSettings(me) {
    var panel = document.getElementById('passkey-panel');
    if (!panel) { return; }
    if (!me || me.passkey_available !== true) { panel.hidden = true; return; }
    panel.hidden = false;
    var csrf = me.csrf_token || '';
    var body = document.getElementById('passkey-body');
    var empty = document.getElementById('passkey-empty');
    var msg = document.getElementById('passkey-msg');
    var ok = document.getElementById('passkey-ok');
    var open = document.getElementById('passkey-register-open');
    var pcNote = document.getElementById('passkey-pc-note');
    var dialog = document.getElementById('passkey-register-dialog');
    var form = document.getElementById('passkey-register-form');
    var nameIn = document.getElementById('passkey-name');
    var pwIn = document.getElementById('passkey-password');
    var otpField = document.getElementById('passkey-otp-field');
    var otpIn = document.getElementById('passkey-otp');
    var dMsg = document.getElementById('passkey-register-msg');
    var submit = document.getElementById('passkey-register');
    var closeBtn = document.getElementById('passkey-register-close');
    var closeX = document.getElementById('passkey-register-x');
    var mfaOn = me.mfa_enabled === true;
    var opener = null;

    function when(v) { return typeof v === 'string' && v.length >= 19 ? v.slice(0, 19).replace('T', ' ') : '—'; }

    function draw(list) {
      while (body.firstChild) { body.removeChild(body.firstChild); }
      list.forEach(function (p) {
        var tr = document.createElement('tr');
        var cells = [
          p.name,
          p.device_hint + (p.synced ? ' · 계정에 동기화됨' : ''),
          when(p.created_at),
          p.last_used_at ? when(p.last_used_at) : '아직 안 씀',
          p.state === 'stale' ? '90일 안 써서 꺼짐' : '쓰는 중'
        ];
        cells.forEach(function (t) { var td = document.createElement('td'); setText(td, t); tr.appendChild(td); });
        var act = document.createElement('td');
        act.className = 'actions';
        var rb = document.createElement('button');
        rb.type = 'button';
        rb.className = 'btn btn--quiet btn--small';
        setText(rb, '해제');
        rb.setAttribute('aria-label', '해제 — ' + p.name);
        rb.addEventListener('click', function () { askRevoke(p, rb); });
        act.appendChild(rb);
        tr.appendChild(act);
        body.appendChild(tr);
      });
      empty.hidden = list.length > 0;
    }

    function load() {
      return fetch(BASE + '/api/me/passkeys', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
          if (res.status === 200 && Array.isArray(res.data.passkeys)) { draw(res.data.passkeys); return res.data.passkeys; }
          setText(msg, errText(res.data));
          return [];
        })
        .catch(function () { setText(msg, GENERIC); return []; });
    }

    function askRevoke(p, button) {
      if (typeof window.qarediOpenConfirm !== 'function') { return; }
      setText(msg, '');
      setText(ok, ''); ok.hidden = true;
      window.qarediOpenConfirm({
        title: '지문·얼굴 로그인 해제',
        lead: '「' + String(p.name) + '」 의 지문·얼굴 로그인을 해제할까요?',
        note: '그 휴대폰에서는 비밀번호로 로그인해야 합니다.',
        okLabel: '해제하기'
      }, function () {
        button.disabled = true;
        fetch(BASE + '/api/me/passkeys/' + String(p.id), {
          method: 'DELETE', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin', cache: 'no-store'
        }).then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
          .then(function (res) {
            button.disabled = false;
            if (res.status === 401) { window.location.assign(BASE + '/login'); return; }
            if (res.status !== 200) { setText(msg, errText(res.data)); return; }
            if (res.data.remaining === 0) { unmarkHere(); }   // 내 열쇠가 0 — 이 브라우저의 표시를 끈다
            setText(ok, '「' + String(p.name) + '」 의 지문·얼굴 로그인을 해제했습니다.');
            ok.hidden = false;
            load();
          })
          .catch(function () { button.disabled = false; setText(msg, GENERIC); });
      });
    }

    function closeDialog() {
      if (dialog.open) { dialog.close(); }
    }
    dialog.addEventListener('close', function () {
      pwIn.value = '';
      otpIn.value = '';
      submit.disabled = false;
      if (opener) { opener.focus(); }
    });
    closeBtn.addEventListener('click', closeDialog);
    closeX.addEventListener('click', closeDialog);

    platformReady().then(function (ready) {
      open.hidden = !ready;
      pcNote.hidden = ready;
    });
    open.addEventListener('click', function () {
      opener = open;
      setText(dMsg, '');
      nameIn.value = deviceGuess();
      otpField.hidden = !mfaOn;
      otpIn.required = mfaOn;
      dialog.showModal();
      pwIn.focus();
    });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      setText(dMsg, '');
      var payload = { name: nameIn.value.trim(), password: pwIn.value };
      if (mfaOn) { payload.otp = otpIn.value.trim(); }
      submit.disabled = true;
      postJson('/api/me/passkeys/options', payload, csrf).then(function (res) {
        payload = null;
        pwIn.value = '';
        otpIn.value = '';
        if (res.status === 401) { window.location.assign(BASE + '/login'); return null; }
        if (res.status !== 200 || !res.data.publicKey) { submit.disabled = false; setText(dMsg, errText(res.data)); return null; }
        var pk = res.data.publicKey;
        var name = res.data.name;
        var opts = {
          publicKey: {
            challenge: b64uToBuf(pk.challenge),
            rp: pk.rp,
            user: { id: b64uToBuf(pk.user.id), name: pk.user.name, displayName: pk.user.displayName },
            pubKeyCredParams: pk.pubKeyCredParams,
            timeout: pk.timeout,
            attestation: 'none',
            authenticatorSelection: pk.authenticatorSelection,
            excludeCredentials: (pk.excludeCredentials || []).map(function (c) {
              var d = { type: 'public-key', id: b64uToBuf(c.id) };
              if (Array.isArray(c.transports) && c.transports.length > 0) { d.transports = c.transports; }
              return d;
            })
          }
        };
        return navigator.credentials.create(opts).then(function (cred) {
          var r = cred.response;
          var tr = typeof r.getTransports === 'function' ? r.getTransports() : [];
          return postJson('/api/me/passkeys', {
            name: name,
            id: cred.id,
            rawId: bufToB64u(cred.rawId),
            type: cred.type,
            clientDataJSON: bufToB64u(r.clientDataJSON),
            attestationObject: bufToB64u(r.attestationObject),
            transports: (Array.isArray(tr) ? tr : []).filter(function (t, i, a) { return TRANSPORTS.indexOf(t) !== -1 && a.indexOf(t) === i; }),
            authenticatorAttachment: cred.authenticatorAttachment || null
          }, csrf);
        }, function (err) {
          submit.disabled = false;
          var n = err && err.name ? err.name : '';
          setText(dMsg, n === 'InvalidStateError' ? '이 휴대폰은 이미 등록되어 있습니다'
            : '등록하지 않았습니다(지문·얼굴 확인을 취소했거나 시간이 지났습니다). 다시 「등록하기」를 누르면 처음부터 합니다.');
          return null;
        }).then(function (res2) {
          if (res2 === null || res2 === undefined) { return; }
          submit.disabled = false;
          if (res2.status === 201) {
            markHere();
            closeDialog();
            setText(ok, '다음부터 이 휴대폰에서 지문·얼굴로 로그인합니다.');
            ok.hidden = false;
            load();
            return;
          }
          setText(dMsg, errText(res2.data));
        });
      }).catch(function () { submit.disabled = false; setText(dMsg, GENERIC); });
    });

    load();
  }

  // ═══ 재인증 — 「지문·얼굴로 확인」 (라운드 C · 지시서 1.9) ═══════════════════════════════════
  /** 재인증 단추 하나를 `before` 앞에 붙인다(휴대폰이고 내 열쇠가 있을 때만). 성공하면 onDone() */
  function reauthButton(host, before, msgEl, onDone) {
    if (!host || !supported() || typeof window.qarediMe !== 'function') { return; }
    Promise.all([platformReady(), window.qarediMe()]).then(function (v) {
      var ready = v[0];
      var me = v[1] && v[1].me ? v[1].me : null;
      if (!ready || !me || me.passkey_available !== true || !(me.passkeys_active > 0)) { return; }
      var csrf = me.csrf_token || '';
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn--block btn--quiet';
      setText(b, '지문·얼굴로 확인');
      b.id = 'passkey-reauth-btn';
      host.insertBefore(b, before || null);
      b.addEventListener('click', function () {
        b.disabled = true;
        setText(msgEl, '');
        postJson('/api/me/reauth/passkey/options', {}, csrf).then(function (res) {
          if (res.status === 401) { window.location.assign(BASE + '/login'); return null; }
          if (res.status !== 200 || !res.data.publicKey) { throw new Error(errText(res.data)); }
          return navigator.credentials.get(getOptions(res.data.publicKey, null, null)).then(function (cred) {
            return postJson('/api/me/reauth/passkey', assertionJson(cred), csrf);
          });
        }).then(function (res2) {
          b.disabled = false;
          if (!res2) { return; }
          if (res2.status === 200 && res2.data.ok === true) { onDone(); return; }
          if (res2.status === 401) { window.location.assign(BASE + '/login'); return; }
          setText(msgEl, '지문·얼굴로 확인하지 못했습니다. 다시 누르거나 비밀번호를 넣으세요.');
        }).catch(function () {
          b.disabled = false;
          setText(msgEl, '지문·얼굴로 확인하지 못했습니다. 다시 누르거나 비밀번호를 넣으세요.');
        });
      });
    });
  }
  /* app.js 의 재인증 창(openReauthModal)이 부른다 — 창 본문·폼·알림 줄·성공 뒤 할 일 */
  window.qarediPasskeyReauth = function (bodyEl, formEl, onDone, msgEl) {
    reauthButton(bodyEl, formEl, msgEl, onDone);
  };

  function initReauthPage() {
    var form = document.getElementById('reauth-form');
    if (!form) { return; }
    var msgEl = document.getElementById('reauth-msg');
    reauthButton(form, form.firstElementChild, msgEl, function () {
      var key = form.getAttribute('data-to');
      window.location.assign(typeof window.qarediReauthTarget === 'function' ? window.qarediReauthTarget(key) : BASE + '/');
    });
  }

  // ═══ 권장 카드 (라운드 C · 지시서 1.9) — 휴대폰 · 내 열쇠 0 · 이 기기 표시 없음 · 이 세션에 「나중에」 안 누름 ═══
  function initCard() {
    var page = document.body ? document.body.getAttribute('data-page') : '';
    if (page !== 'files' && page !== 'community-home') { return; }
    if (hasHere() || ssGet(LATER_KEY) === '1' || typeof window.qarediMe !== 'function') { return; }
    Promise.all([platformReady(), window.qarediMe()]).then(function (v) {
      var me = v[1] && v[1].me ? v[1].me : null;
      if (!v[0] || !me || me.passkey_available !== true || me.passkeys_active !== 0 || me.next) { return; }
      var headEl = document.querySelector('main .page-head');
      if (!headEl || document.getElementById('passkey-recommend')) { return; }
      var card = document.createElement('section');
      card.className = 'notice notice--recommend';
      card.id = 'passkey-recommend';
      var p1 = document.createElement('p');
      var strong = document.createElement('strong');
      setText(strong, '다음부터 지문·얼굴로 로그인하기');
      p1.appendChild(strong);
      p1.appendChild(document.createTextNode(' 이 휴대폰을 등록하면 비밀번호와 2단계 코드 없이 지문이나 얼굴로 들어옵니다.'));
      card.appendChild(p1);
      var p2 = document.createElement('p');
      p2.className = 'notice__actions';
      var go = document.createElement('a');
      go.className = 'btn btn--small';
      go.setAttribute('href', BASE + '/settings#passkey-panel');
      setText(go, '등록하러 가기');
      var later = document.createElement('button');
      later.type = 'button';
      later.className = 'btn btn--quiet btn--small';
      setText(later, '나중에');
      later.addEventListener('click', function () { ssSet(LATER_KEY, '1'); if (card.parentNode) { card.parentNode.removeChild(card); } });
      p2.appendChild(go);
      p2.appendChild(later);
      card.appendChild(p2);
      headEl.parentNode.insertBefore(card, headEl.nextSibling);
    });
  }

  // ═══ 시작 ═════════════════════════════════════════════════════════════════════════════════
  var pageKey = document.body ? document.body.getAttribute('data-page') : '';
  if (pageKey === 'login') {
    initLogin();
  } else {
    if (pageKey === 'settings' && typeof window.qarediMe === 'function') {
      window.qarediMe().then(function (res) { initSettings(res && res.me ? res.me : null); });
    }
    initReauthPage();
    initCard();
  }
}());
