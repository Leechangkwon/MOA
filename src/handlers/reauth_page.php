<?php
declare(strict_types=1);

/**
 * src/handlers/reauth_page.php — GET /reauth (정책: authenticated)
 *
 * 요구사항: PRD F-23 · 8장 `/reauth?to=키` 행 · 10장 화면 요구사항(「재인증」), S-01, S-03,
 *           ARCH 4.0 「관리 기능 재인증 — sudo 모드」
 *
 * 관리 화면(계정 관리·조직·권한·감사 로그·휴지통·위키)에 들어가려 했는데 세션의 재인증 창이
 * 닫혀 있으면, 라우터 관문 [3-2] 가 이 화면으로 302 를 보낸다. 여기서 현재 비밀번호를 한 번
 * 확인하면(`POST /api/me/reauth`) 원래 가려던 화면으로 돌아간다.
 *
 * ★ 정책은 `authenticated` 다 — 재인증하러 오는 길까지 재인증을 요구하면 들어갈 방법이 없다.
 *   이 화면은 **아무 권한도 주지 않는다**: 여기서 비밀번호를 맞혀도 무엇을 할 수 있는지는
 *   여전히 라우트 정책(Authz)이 정한다. Employee 가 이 화면에서 확인에 성공해도 `/users` 는
 *   그대로 403 이다 (PRD T-36, ARCH 4.0 "재인증은 권한을 만들지 않는다").
 *
 * ★ 강제 절차(F-16) 중에는 이 화면이 [3-1] 관문에 막혀 `/` 로 돌아간다 — 허용 목록에 넣지
 *   않는다. 절차를 마치지 않은 사람에게는 "먼저 절차를 끝내라" 가 답이기 때문이다.
 *
 * ★ 돌아갈 곳(`?to=`)은 **요청 값을 그대로 쓰지 않는다** (오픈 리다이렉트 방지, PRD F-23).
 *   `TBOX_REAUTH_PATHS`(public/index.php 의 고정 표)에 **키로 존재할 때만** 그 키를 HTML 에
 *   넣고, 그 밖의 값은 빈 문자열로 떨어뜨린다(= app.js 가 홈으로 보낸다). HTML 에 들어가는
 *   것은 표에 적힌 `users`·`org`·`audit`·`trash`·`wiki` 다섯 글자 중 하나뿐이며,
 *   실제 경로로 바꾸는 일은 app.js 의 **같은 고정 맵**이 한다 — 경로 문자열이 요청에서
 *   만들어지는 곳은 서버에도 화면에도 없다.
 *
 * 서버가 HTML 에 넣는 동적 값은 공통 셸의 사용자명·역할과 위 `to` 키뿐이고 전부
 * htmlspecialchars 를 거친다 (PRD S-03). 인라인 <script>·style 속성·on* 이벤트 속성을
 * 쓰지 않는다 (CSP: script-src 'self').
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

/*
 * 돌아갈 화면의 **키**. 요청 값은 "표에 있는가" 를 묻는 데만 쓰이고, 있으면 표의 키를
 * (요청 문자열이 아니라) 그대로 쓴다. 표에 없거나 값이 없으면 빈 문자열이다.
 */
$reauthTo    = '';
$reauthToRaw = $_GET['to'] ?? null;
if (is_string($reauthToRaw)) {
    foreach (array_keys(TBOX_REAUTH_PATHS) as $key) {
        if ($key === $reauthToRaw) {
            $reauthTo = $key;      // HTML 로 나가는 값은 **표에 적힌 문자열**이다 (요청 값이 아니다)
            break;
        }
    }
}
unset($reauthToRaw);

$reauthToAttr = htmlspecialchars($reauthTo, ENT_QUOTES, 'UTF-8');

/*
 * 공통 셸을 쓴다. $activeNav·$pageKey 는 셸의 허용 목록(files·trash·settings·activity·
 * users·org·audit·wiki)에 없는 화면이라 빈 문자열이 된다 — 켜 둘 내비 항목이 없고,
 * app.js 는 `#reauth-form` 이 있는지로 이 화면을 알아본다(로그인 화면과 같은 방식).
 */
$pageTitle = '비밀번호 다시 확인';
$activeNav = '';
$pageKey   = '';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">비밀번호 다시 확인</h1>
        <p class="page-head__sub">관리 기능을 쓰려면 비밀번호를 다시 확인합니다.</p>
      </div>
    </header>

    <section class="panel panel--narrow">


      <form id="reauth-form" class="form" method="post" action="/api/me/reauth"
            autocomplete="off" data-to="<?= $reauthToAttr ?>">
        <label class="field">
          <span class="field__label">현재 비밀번호</span>
          <input id="reauth-password" name="password" type="password"
                 maxlength="1024" autocomplete="current-password" required autofocus>
        </label>
        <button id="reauth-submit" class="btn btn--block" type="submit">확인</button>

        <p id="reauth-msg" class="msg" role="alert" aria-live="polite"></p>
      </form>
    </section>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
