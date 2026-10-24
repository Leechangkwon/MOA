<?php
declare(strict_types=1);

/**
 * src/handlers/push_app_page.php — GET /alerts-app (정책: role:Admin + 재인증, PRD F-70 · 지시서 D 「화면·앱」)
 *
 * 「Qaredi 경보」 설치형 웹 앱의 화면이다. 휴대폰에서 「홈 화면에 추가」로 설치하면 이 주소가 시작 화면이다
 * (매니페스트 start_url · scope = /tbox/alerts-app). 하는 일은 셋 — 이 기기에서 **알림 받기 켜기**,
 * 내 **받는 기기 목록**(해지), **시험 알림**. 동작은 public/assets/alerts-app.js 가 API 응답을 textContent 로 그린다.
 *
 * ★ 공통 셸(shell_top/bottom) 그대로 — 셸 키는 관리자 영역과 같은 `admin`(app.js 가 셸만 그리고 물러난다).
 *   `$pageApp = 'alerts'` 한 줄이 셸 머리에 매니페스트·아이폰 홈 화면 메타를, 셸 바닥에 alerts-app.js 를 붙인다
 *   (허용 목록 한 값 — 다른 값이면 아무것도 붙지 않는다).
 * ★ DESIGN.md 구성 요소만(page-head · panel · grid-2 · info-grid · list · notice--recommend · steps · modal) — 새 클래스·색값 0.
 * ★ 인라인 <script>·style 속성·on* 속성 없음(CSP script-src 'self'). HTML 에 넣는 동적 값은 셸의 다섯 개 + 아래 「준비됨」 0/1 하나뿐이다.
 * ★ 서버 쪽 반영 전(표 없음 · 공개 키 파일 없음)이면 화면은 200 그대로 「준비 안 됨」 안내를 보이고 `data-push-ready="0"` —
 *   alerts-app.js 는 켜기·시험 단추를 막고 창구를 부르지 않는다(창구도 503).
 * ★ 이 화면은 밖으로 나가지 않는다 — 「시험 알림」도 대기표에 한 행만 넣는다(push_test.php).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$pageTitle  = '휴대폰 경보';
$activeNav  = 'admin';
$pageKey    = 'admin';
$pageMsgTop = true;
$pageApp    = 'alerts';
require_once __DIR__ . '/../WebPush.php';
$pushReady  = WebPush::tablesReady(Db::instance()) && WebPush::readPublicKeyFile(WebPush::PUBLIC_KEY_FILE) !== null;
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">휴대폰 경보</h1>
        <p class="page-head__sub">관리자 휴대폰에 「모아 경보」 앱을 설치해 두면 사람·시스템 경보는 곧바로, Claude 경보는 하루 한 번(18시) 요약으로 알림이 옵니다.</p>
      </div>
      <div class="page-head__actions">
        <button type="button" class="btn btn--quiet" id="push-test" disabled>
          <span class="icon" data-icon="bell"></span><span>시험 알림</span>
        </button>
        <button type="button" class="btn" id="push-enable" disabled>알림 받기 켜기</button>
      </div>
    </header>
    <p id="msg" class="msg" role="alert" aria-live="polite"></p>

<?php if (!$pushReady): ?>
    <div class="notice notice--recommend" id="push-notready">
      <p>휴대폰 경보가 아직 준비되지 않았습니다 — 서버 쪽 반영(관리자 작업)이 끝나면 이 화면에서 켤 수 있습니다. 경보는 지금도 관리자 페이지·감사 로그에 그대로 남습니다.</p>
    </div>
<?php endif; ?>
    <div class="notice notice--recommend" id="push-ios-note" hidden>
      <p>아이폰은 Safari 의 공유 단추 → 「홈 화면에 추가」 로 먼저 설치한 뒤, 홈 화면의 「모아 경보」 아이콘으로 열어서 「알림 받기 켜기」를 누르세요 (iOS 16.4 이상).</p>
    </div>

    <section class="grid-2" id="push-app" data-push-ready="<?= $pushReady ? '1' : '0' ?>">
      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">이 기기</h2>
          <p class="panel__sub">지금 이 화면을 연 브라우저의 상태입니다.</p>
        </div>
        <div class="info-grid">
          <div class="info-item"><span class="field__label">설치</span><span class="info-item__value" id="push-installed">확인 중…</span></div>
          <div class="info-item"><span class="field__label">알림 허락</span><span class="info-item__value" id="push-permission">확인 중…</span></div>
          <div class="info-item"><span class="field__label">받기</span><span class="info-item__value" id="push-state">확인 중…</span></div>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn--quiet btn--small" id="push-disable" hidden>이 기기 끄기</button>
        </div>
      </section>

      <section class="panel">
        <div class="panel__head">
          <h2 class="panel__title">받는 기기</h2>
          <p class="panel__sub">내 계정에 등록된 기기입니다 (한 사람 3대까지). 잃어버린 기기는 여기서 해지합니다.</p>
        </div>
        <ul class="list" id="push-devices" hidden></ul>
        <p class="muted" id="push-devices-empty">등록된 기기가 없습니다. 휴대폰에서 「알림 받기 켜기」를 누르세요.</p>
      </section>

      <section class="panel span-all">
        <div class="panel__head">
          <h2 class="panel__title">설치 순서</h2>
          <p class="panel__sub">알림에는 규칙 번호·짧은 이름·출처·시각만 보입니다. 누르면 관리자 페이지가 열리고, 자세한 내용은 로그인해서 봅니다.</p>
        </div>
        <ol class="steps">
          <li>안드로이드 Chrome: 이 화면에서 「알림 받기 켜기」 → 알림 허락. 메뉴의 「홈 화면에 추가」로 설치하면 앱처럼 열립니다.</li>
          <li>아이폰 Safari (iOS 16.4 이상): 공유 단추 → 「홈 화면에 추가」 → 홈 화면의 「모아 경보」로 열기 → 「알림 받기 켜기」 → 알림 허락.</li>
          <li>「시험 알림」을 누르면 1분 안에 등록한 기기 전부에 시험 알림이 옵니다 (10분에 한 번).</li>
          <li>휴대폰을 잃어버렸으면 다른 기기에서 이 화면의 「받는 기기」 목록에서 그 기기를 해지하세요.</li>
        </ol>
        <p class="help">받는 사람은 관리자뿐입니다. 관리자 역할이 바뀌거나 계정이 비활성화되면 그 계정의 기기는 자동으로 해지됩니다.</p>
      </section>
    </section>

    <dialog id="push-confirm" class="modal modal--form" aria-labelledby="push-confirm-title">
      <div class="modal__head">
        <h2 id="push-confirm-title" class="modal__title">기기 해지</h2>
        <button class="btn btn--icon btn--plain" type="button" data-push-confirm-close aria-label="닫기">
          <span class="icon" data-icon="x"></span>
        </button>
      </div>
      <div class="modal__body">
        <p id="push-confirm-text">이 기기로는 더 이상 경보 알림이 오지 않습니다. 다시 받으려면 그 기기에서 「알림 받기 켜기」를 누르세요.</p>
      </div>
      <div class="modal__foot">
        <button class="btn btn--quiet" type="button" data-push-confirm-close>닫기</button>
        <button class="btn btn--danger" type="button" id="push-confirm-ok">해지하기</button>
      </div>
    </dialog>
<?php
require __DIR__ . '/../views/shell_bottom.php';
