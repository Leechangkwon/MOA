/*
 * src/push/alerts-sw.js — 「Qaredi 경보」 서비스 워커 (PRD F-70 · 지시서 docs/f70-push-alerts-directive.md D 「화면·앱」, ARCH 5.32)
 *
 * 원본은 public/ **밖**에 둔다 — /tbox/assets/ 로는 서비스되지 않는다. 브라우저는 PHP 라우트
 * `GET /tbox/alerts-sw.js`(정책 role:Admin, src/handlers/push_sw.php)로만 받는다. 그래서 등록 범위를
 * `/tbox/alerts-app` 로 잡아도 Service-Worker-Allowed 머리가 필요 없고 nginx 설정을 바꾸지 않는다(구현 기록 1).
 * 핸들러가 바꾸는 글자는 아이콘 버전 자리 하나(`__ICON_VERSION__` → 정수)뿐이다.
 *
 * ★ 처리기는 **둘뿐**이다 — `push`(알림 띄우기) · `notificationclick`(고정 주소 열기).
 *   `fetch` 처리기(캐시)를 두지 않는다 — 로그인한 화면을 기기에 남기지 않는다(지시서 금지 패턴).
 * ★ 알림을 누르면 **고정 주소** `/tbox/admin` 으로만 간다. 알림 내용의 어떤 값도 주소로 쓰지 않는다(R-99 ④ 피싱 통로).
 * ★ 내용(암호를 푼 JSON)은 모양을 좁혀서만 쓴다 — 규칙 번호는 `AL-n` 모양, 이름은 40자, 개수는 정수.
 *   알림 글자는 브라우저가 글자로만 그린다(HTML 해석 없음). 내용에는 계정명·IP·파일명·명령이 없다(L-1).
 * ★ 알 수 없는 내용이 와도 알림은 **하나** 띄운다 — 푸시를 받고 알림을 띄우지 않으면 브라우저가 받기를 끊는다
 *   (userVisibleOnly). 그때 제목은 고정 글자다.
 * ★ 인라인 스크립트 없음(CSP script-src 'self') · 다른 스크립트 불러오기 0 · 저장소(IndexedDB·캐시) 0 · 네트워크 요청 0.
 */
'use strict';

/** 누르면 여는 곳 — 코드 상수 (TBOX_BASE + /admin) */
var OPEN_PATH = '/tbox/admin';

/** 알림 아이콘 — 버전은 핸들러가 파일 시각(정수)으로 채운다 */
var ICON_PATH = '/tbox/assets/alerts-icon-192.png?v=__ICON_VERSION__';

/** 출처 표시 (F-69 origin 넷) — 모르는 값은 좁은 쪽(사람)으로 */
var ORIGIN_LABELS = {
  'human': '사람',
  'system': '시스템',
  'claude-review': 'Claude',
  'claude-session': 'Claude'
};

var KINDS = ['alert', 'digest', 'test', 'more'];
var RULE_RE = /^AL-[0-9]{1,2}$/;
var AT_RE = /^\d{4}-(\d{2})-(\d{2})T(\d{2}):(\d{2})/;
var FALLBACK_TITLE = '[경보] 모아';
var BODY_TAIL = '눌러서 관리자 페이지에서 확인하세요 (로그인 필요)';

function readPayload(event) {
  var d = null;
  try {
    d = event.data ? event.data.json() : null;
  } catch (e) {
    d = null;
  }
  if (!d || typeof d !== 'object' || d.v !== 1 || KINDS.indexOf(d.kind) < 0) {
    return null;
  }
  return d;
}

function clip(s, max) {
  if (typeof s !== 'string') {
    return '';
  }
  return s.length > max ? s.slice(0, max) : s;
}

function count(n) {
  return typeof n === 'number' && isFinite(n) && n >= 0 && n <= 100000 ? Math.floor(n) : 0;
}

function shortWhen(at) {
  var m = typeof at === 'string' ? at.match(AT_RE) : null;
  return m ? m[1] + '-' + m[2] + ' ' + m[3] + ':' + m[4] : '';
}

function isClaude(origin) {
  return origin === 'claude-review' || origin === 'claude-session';
}

/** 제목·본문 — 「[사람] AL-1 권한 우회 시도」 · 「[시스템] AL-8 백업 실패」 · 「[Claude] 오늘 경보 37건」 */
function build(d) {
  var title;
  if (d.kind === 'alert') {
    var who = Object.prototype.hasOwnProperty.call(ORIGIN_LABELS, d.origin) ? ORIGIN_LABELS[d.origin] : '사람';
    var rule = RULE_RE.test(typeof d.rule === 'string' ? d.rule : '') ? d.rule : '경보';
    var name = clip(d.name, 40);
    title = '[' + who + '] ' + rule + (name === '' ? '' : ' ' + name);
  } else if (d.kind === 'digest') {
    title = (isClaude(d.origin) ? '[Claude]' : '[요약]') + ' 오늘 경보 ' + count(d.n) + '건';
  } else if (d.kind === 'more') {
    title = '[경보] 지난 한 시간 외 ' + count(d.n) + '건';
  } else {
    title = '[시험] 모아 경보 알림이 이 기기에 옵니다';
  }
  var when = shortWhen(d.at);
  return { title: title, body: (when === '' ? '' : when + ' · ') + BODY_TAIL, kind: d.kind };
}

self.addEventListener('push', function (event) {
  var d = readPayload(event);
  var n = d === null ? { title: FALLBACK_TITLE, body: BODY_TAIL, kind: '' } : build(d);
  var options = { body: n.body, icon: ICON_PATH, lang: 'ko' };
  if (n.kind === 'test') {
    options.tag = 'qaredi-test';
  }
  event.waitUntil(self.registration.showNotification(n.title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  // 고정 주소만 — 알림 내용·data 의 값으로 주소를 만들지 않는다
  event.waitUntil(self.clients.openWindow(OPEN_PATH));
});
