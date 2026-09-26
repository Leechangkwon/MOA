/*
 * public/assets/alerts-app.js — 「휴대폰 경보」 화면 (`/tbox/alerts-app`, PRD F-70 · 지시서 D 「화면·앱」, ARCH 5.32)
 *
 * 하는 일 셋
 *   ① 이 기기에서 **알림 받기 켜기** — 알림 허락 → VAPID 공개 키(GET /api/admin/push/key) → 서비스 워커 등록
 *      (/tbox/alerts-sw.js, 범위 /tbox/alerts-app) → 받기 만들기(pushManager.subscribe) → 서버 등록(POST, 허용 키 셋만)
 *   ② **받는 기기** 목록(GET) · 해지(DELETE — 확인 창 뒤)
 *   ③ **시험 알림**(POST /api/admin/push/test — 서버는 대기표에 한 행만 넣고, 1분 안에 배치가 보낸다)
 *
 * ★ 화면 글자는 textContent 로만 만든다(문자열을 HTML 로 바꾸는 속성·함수 0 — PRD S-03). 오류 문구는 **서버가 준 문구만** 쓴다.
 * ★ 권한·재인증은 서버가 정한다 — 403 `next:"reauth"` 면 셸(app.js)의 같은 재인증 창(`qarediHandleReauth`)을 띄우고
 *   막힌 요청을 다시 보낸다. 화면이 역할을 보지 않는다.
 * ★ 받기 주소(endpoint)·키는 서버로 **한 번** 보낼 뿐 화면에 그리거나 저장소에 넣지 않는다. 브라우저 저장소에는
 *   서버가 준 받기 **번호**만 둔다(「이 기기」 표시·「이 기기 끄기」 용 — 비밀 아님).
 * ★ 알림 허락 요청은 단추를 누른 그 자리에서 **먼저** 한다 — 아이폰은 사용자 동작 안에서만 물을 수 있다.
 * ★ 인라인 스크립트 없음(CSP script-src 'self'). 셸 스크립트(app.js) 뒤에 실린다.
 */
(function () {
  'use strict';

  var root = document.getElementById('push-app');
  if (!root) {
    return;
  }

  var BASE = (document.body && document.body.getAttribute('data-base')) || '/tbox';
  var SW_PATH = BASE + '/alerts-sw.js';
  var SCOPE = BASE + '/alerts-app';
  var LOCAL_KEY = 'qaredi-push-sub-id';
  var GENERIC = '요청을 처리할 수 없습니다. 잠시 후 다시 시도하세요';
  var HOST_LABELS = {
    'fcm.googleapis.com': '구글(안드로이드 Chrome)',
    'web.push.apple.com': '애플(아이폰 Safari)',
    'updates.push.services.mozilla.com': '모질라(Firefox)'
  };

  var el = {
    msg: document.getElementById('msg'),
    enable: document.getElementById('push-enable'),
    test: document.getElementById('push-test'),
    disable: document.getElementById('push-disable'),
    installed: document.getElementById('push-installed'),
    permission: document.getElementById('push-permission'),
    state: document.getElementById('push-state'),
    iosNote: document.getElementById('push-ios-note'),
    list: document.getElementById('push-devices'),
    empty: document.getElementById('push-devices-empty'),
    confirm: document.getElementById('push-confirm'),
    confirmOk: document.getElementById('push-confirm-ok')
  };

  var csrf = '';
  var busy = false;
  // 서버 쪽 반영 전이면 화면이 data-push-ready="0" 을 준다 — 켜기·시험을 막고 창구를 부르지 않는다(안내는 화면이 이미 보인다)
  var ready = root.getAttribute('data-push-ready') !== '0';

  // ─── 작은 도우미 ─────────────────────────────────────────────
  function say(text, ok) {
    if (!el.msg) {
      return;
    }
    el.msg.textContent = text || '';
    el.msg.className = ok === true && text ? 'ok-note' : 'msg';
  }

  function setText(node, text) {
    if (node) {
      node.textContent = text;
    }
  }

  function localId() {
    try {
      var v = window.localStorage.getItem(LOCAL_KEY);
      return v !== null && /^[0-9]{1,9}$/.test(v) ? parseInt(v, 10) : null;
    } catch (e) {
      return null;
    }
  }

  function setLocalId(id) {
    try {
      if (id === null) {
        window.localStorage.removeItem(LOCAL_KEY);
      } else {
        window.localStorage.setItem(LOCAL_KEY, String(id));
      }
    } catch (e) {
      // 저장소를 못 쓰는 브라우저 — 「이 기기」 표시만 빠진다
    }
  }

  function shortWhen(v) {
    if (typeof v !== 'string' || v === '') {
      return '—';
    }
    var m = v.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/);
    return m ? m[1] + ' ' + m[2] : v;
  }

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
      || window.navigator.standalone === true;
  }

  function isIos() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');
  }

  function supported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }

  /** base64url → Uint8Array (VAPID 공개 키 — applicationServerKey) */
  function keyBytes(b64u) {
    var s = String(b64u).replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4 !== 0) {
      s += '=';
    }
    var bin = window.atob(s);
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) {
      out[i] = bin.charCodeAt(i);
    }
    return out;
  }

  /**
   * 서버 창구 한 번. 403 `next:"reauth"` 면 셸의 재인증 창을 띄우고, 통과하면 같은 요청을 다시 보낸다(retry).
   * 401 이면 로그인 화면으로. 돌려주는 것은 {status, data}.
   */
  function api(method, path, body, retry) {
    var opts = { method: method, credentials: 'same-origin', cache: 'no-store', headers: {} };
    if (method !== 'GET') {
      opts.headers['X-CSRF-Token'] = csrf;
    }
    if (body !== undefined) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(BASE + path, opts).then(function (response) {
      return response.text().then(function (raw) {
        var data = null;
        try {
          data = raw === '' ? null : JSON.parse(raw);
        } catch (e) {
          data = null;
        }
        if (response.status === 401) {
          window.location.assign(BASE + '/login');
          return { status: 401, data: data, stop: true };
        }
        if (response.status === 403 && data && data.next === 'reauth'
            && typeof window.qarediHandleReauth === 'function') {
          window.qarediHandleReauth(response, data, retry || null);
          return { status: 403, data: data, stop: true };
        }
        return { status: response.status, data: data, stop: false };
      });
    });
  }

  function errorOf(result) {
    return result && result.data && typeof result.data.error === 'string' ? result.data.error : GENERIC;
  }

  // ─── 이 기기의 상태 ─────────────────────────────────────────
  function registration() {
    if (!('serviceWorker' in navigator)) {
      return Promise.resolve(null);
    }
    return navigator.serviceWorker.getRegistration(SCOPE).then(function (reg) {
      return reg || null;
    }, function () {
      return null;
    });
  }

  function currentSubscription() {
    return registration().then(function (reg) {
      if (!reg || !reg.pushManager) {
        return null;
      }
      return reg.pushManager.getSubscription().then(function (s) {
        return s || null;
      }, function () {
        return null;
      });
    });
  }

  function paintDevice() {
    setText(el.installed, isStandalone() ? '홈 화면 앱으로 열림' : '브라우저에서 보는 중');
    if (!supported()) {
      setText(el.permission, '이 브라우저는 알림을 받을 수 없습니다');
      setText(el.state, '쓸 수 없음');
      el.enable.disabled = true;
      if (isIos() && el.iosNote) {
        el.iosNote.hidden = false;
      }
      return Promise.resolve();
    }
    if (isIos() && !isStandalone() && el.iosNote) {
      el.iosNote.hidden = false;
    }
    var perm = Notification.permission;
    setText(el.permission, perm === 'granted' ? '허락함' : (perm === 'denied' ? '막음 — 브라우저 설정에서 이 사이트 알림을 허락하세요' : '아직 묻지 않음'));
    return currentSubscription().then(function (sub) {
      setText(el.state, sub ? '켜짐 — 이 기기로 경보가 옵니다' : '꺼짐');
      el.enable.disabled = busy || perm === 'denied' || !ready;
      el.disable.hidden = !sub;
    });
  }

  // ─── 받는 기기 목록 ─────────────────────────────────────────
  function loadDevices() {
    return api('GET', '/api/admin/push/subscriptions', undefined, loadDevices).then(function (r) {
      if (r.stop) {
        return;
      }
      if (r.status !== 200 || !r.data || !Array.isArray(r.data.subscriptions)) {
        say(errorOf(r));
        return;
      }
      renderDevices(r.data.subscriptions);
    });
  }

  function renderDevices(items) {
    while (el.list.firstChild) {
      el.list.removeChild(el.list.firstChild);
    }
    var mine = localId();
    items.forEach(function (d) {
      if (!d || typeof d.id !== 'number') {
        return;
      }
      var li = document.createElement('li');
      li.className = 'list__item';

      var head = document.createElement('div');
      head.className = 'list__head';
      var name = document.createElement('strong');
      name.textContent = typeof d.device_hint === 'string' && d.device_hint !== '' ? d.device_hint : '기기';
      head.appendChild(name);
      if (mine !== null && mine === d.id) {
        var badge = document.createElement('span');
        badge.className = 'badge badge--ok';
        badge.textContent = '이 기기';
        head.appendChild(badge);
      }
      var revoke = document.createElement('button');
      revoke.type = 'button';
      revoke.className = 'btn btn--danger btn--small';
      revoke.textContent = '해지';
      revoke.addEventListener('click', function () {
        askRevoke(d.id);
      });
      head.appendChild(revoke);
      li.appendChild(head);

      var body = document.createElement('div');
      body.className = 'list__body';
      var host = typeof d.host === 'string' ? d.host : '';
      [
        '푸시 서버: ' + (HOST_LABELS[host] || host || '—'),
        '등록: ' + shortWhen(d.created_at),
        '마지막 전달: ' + shortWhen(d.last_ok_at)
      ].forEach(function (text) {
        var span = document.createElement('span');
        span.textContent = text;
        body.appendChild(span);
      });
      li.appendChild(body);
      el.list.appendChild(li);
    });
    var none = el.list.childNodes.length === 0;
    el.list.hidden = none;
    el.empty.hidden = !none;
    el.test.disabled = none;
  }

  // ─── 해지 (확인 창 뒤) ──────────────────────────────────────
  var pendingRevoke = null;

  function askRevoke(id) {
    pendingRevoke = id;
    if (el.confirm && typeof el.confirm.showModal === 'function') {
      el.confirm.showModal();
      return;
    }
    /* <dialog> 가 없는 브라우저 — 확인 없이 지우지 않는다. 예전엔 브라우저 기본 확인 창(window.confirm)으로 물었다 —
       디버깅 묶음 U H2(2026-09-26): 확인 방식은 이 화면의 확인 창(#push-confirm) 하나다. 창을 못 여는 브라우저에서는 해지하지 않고 알린다. */
    pendingRevoke = null;
    say('이 브라우저에서는 확인 창을 열 수 없어 해지하지 않았습니다. 다른 브라우저(최신 Chrome·삼성 인터넷)에서 해지하세요.');
  }

  function closeConfirm() {
    if (el.confirm && el.confirm.open) {
      el.confirm.close();
    }
  }

  function doRevoke() {
    var id = pendingRevoke;
    pendingRevoke = null;
    closeConfirm();
    if (typeof id !== 'number') {
      return Promise.resolve();
    }
    return api('DELETE', '/api/admin/push/subscriptions/' + id, undefined, function () {
      pendingRevoke = id;
      doRevoke();
    }).then(function (r) {
      if (r.stop) {
        return null;
      }
      if (r.status !== 200) {
        say(errorOf(r));
        return null;
      }
      say('기기를 해지했습니다.', true);
      var after = Promise.resolve();
      if (localId() === id) {
        setLocalId(null);
        after = currentSubscription().then(function (sub) {
          return sub ? sub.unsubscribe().catch(function () { return false; }) : false;
        });
      }
      return after.then(loadDevices).then(paintDevice);
    });
  }

  if (el.confirm) {
    el.confirm.querySelectorAll('[data-push-confirm-close]').forEach(function (b) {
      b.addEventListener('click', function () {
        pendingRevoke = null;
        closeConfirm();
      });
    });
  }
  if (el.confirmOk) {
    el.confirmOk.addEventListener('click', function () {
      doRevoke();
    });
  }

  // ─── 켜기 ──────────────────────────────────────────────────
  function register(body) {
    return api('POST', '/api/admin/push/subscriptions', body, function () {
      register(body).then(finish);
    });
  }

  function finish(r) {
    if (!r || r.stop) {
      return null;
    }
    if (r.status === 201 || r.status === 200) {
      var sub = r.data && r.data.subscription;
      if (sub && typeof sub.id === 'number') {
        setLocalId(sub.id);
      }
      say('이 기기로 경보 알림을 받습니다. 「시험 알림」으로 확인해 보세요.', true);
      return loadDevices().then(paintDevice);
    }
    // 서버가 받지 않은 받기는 브라우저에도 남기지 않는다
    say(errorOf(r));
    return currentSubscription().then(function (s) {
      return s ? s.unsubscribe().catch(function () { return false; }) : false;
    }).then(paintDevice);
  }

  function enable() {
    if (busy || !supported() || !ready) {
      return;
    }
    busy = true;
    el.enable.disabled = true;
    say('');
    // ★ 알림 허락은 단추를 누른 그 자리에서 먼저 묻는다(아이폰은 사용자 동작 안에서만 물을 수 있다)
    var ask = Notification.permission === 'granted'
      ? Promise.resolve('granted')
      : Promise.resolve(Notification.requestPermission());
    ask.then(function (perm) {
      if (perm !== 'granted') {
        say('알림을 허락하지 않아 켜지 않았습니다. 브라우저 설정에서 이 사이트의 알림을 허락한 뒤 다시 누르세요.');
        return null;
      }
      return api('GET', '/api/admin/push/key', undefined, null).then(function (r) {
        if (r.stop) {
          return null;
        }
        if (r.status !== 200 || !r.data || typeof r.data.public_key !== 'string') {
          say(errorOf(r));
          return null;
        }
        var key = keyBytes(r.data.public_key);
        return navigator.serviceWorker.register(SW_PATH, { scope: SCOPE }).then(function () {
          return navigator.serviceWorker.ready;
        }).then(function (reg) {
          // 새 받기 주소로 — 남아 있던 받기는 먼저 끊는다(같은 주소를 두 계정이 나눠 쓰지 않게)
          return reg.pushManager.getSubscription().then(function (old) {
            return old ? old.unsubscribe().catch(function () { return false; }) : false;
          }).then(function () {
            return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
          });
        }).then(function (sub) {
          var j = sub.toJSON();
          var keys = j && j.keys ? j.keys : {};
          // 서버는 허용 키 셋만 받는다 — expirationTime 등은 싣지 않는다
          return register({
            endpoint: String(j.endpoint || ''),
            keys: { p256dh: String(keys.p256dh || ''), auth: String(keys.auth || '') }
          }).then(finish);
        });
      });
    }).catch(function () {
      say('이 브라우저에서 알림 받기를 켜지 못했습니다. 아이폰은 홈 화면에 추가한 앱 안에서만 켤 수 있습니다.');
    }).then(function () {
      busy = false;
      return paintDevice();
    });
  }

  function disableHere() {
    if (busy) {
      return;
    }
    busy = true;
    say('');
    var id = localId();
    var server = id === null
      ? Promise.resolve({ status: 200, stop: false })
      : api('DELETE', '/api/admin/push/subscriptions/' + id, undefined, disableHere);
    server.then(function (r) {
      if (r.stop) {
        return null;
      }
      // 404 는 이미 해지된 받기 — 브라우저 쪽만 끊으면 된다
      if (r.status !== 200 && r.status !== 404) {
        say(errorOf(r));
        return null;
      }
      setLocalId(null);
      return currentSubscription().then(function (sub) {
        return sub ? sub.unsubscribe().catch(function () { return false; }) : false;
      }).then(function () {
        say('이 기기의 알림 받기를 껐습니다.', true);
        return loadDevices();
      });
    }).catch(function () {
      say(GENERIC);
    }).then(function () {
      busy = false;
      return paintDevice();
    });
  }

  // ─── 시험 알림 ──────────────────────────────────────────────
  function sendTest() {
    el.test.disabled = true;
    say('');
    api('POST', '/api/admin/push/test', {}, sendTest).then(function (r) {
      if (r.stop) {
        return;
      }
      if (r.status === 202) {
        say('시험 알림을 요청했습니다. 1분 안에 등록한 기기 전부에 옵니다.', true);
        return;
      }
      say(errorOf(r));
    }).catch(function () {
      say(GENERIC);
    }).then(function () {
      el.test.disabled = el.list.childNodes.length === 0;
    });
  }

  // ─── 시작 ──────────────────────────────────────────────────
  el.enable.addEventListener('click', enable);
  el.disable.addEventListener('click', disableHere);
  el.test.addEventListener('click', sendTest);

  var meFetch = typeof window.qarediMe === 'function'
    ? window.qarediMe()
    : fetch(BASE + '/api/me', { credentials: 'same-origin', cache: 'no-store' }).then(function (res) {
      return res.json().then(function (me) { return { status: res.status, me: me }; });
    });
  meFetch.then(function (result) {
    if (!result || result.status !== 200 || !result.me || typeof result.me.csrf_token !== 'string') {
      say(GENERIC);
      return null;
    }
    csrf = result.me.csrf_token;
    return paintDevice().then(function () {
      if (!ready) {
        setText(el.state, '준비 안 됨 — 서버 쪽 반영 전');
        return null;
      }
      return loadDevices();
    });
  }).catch(function () {
    say(GENERIC);
  });
}());
