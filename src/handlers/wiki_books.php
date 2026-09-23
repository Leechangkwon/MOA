<?php
declare(strict_types=1);

/**
 * src/handlers/wiki_books.php — GET /wiki (정책: role:Admin → PRD F-21, SECURITY R-56)
 *
 * 위키의 **첫 화면 = 책 고르기**다 (사용자 지시 2026-09-23 「위키 접속 시 Qaredi · T-box ·
 * Community 3개를 보여 주고, 클릭하여 접속하면 해당하는 상세 위키를 볼 수 있도록. 로그인 전에
 * tbox·community 를 골라 들어가듯이」). 타일을 누르면 그 책의 차례(`/wiki/{book}`)로 간다.
 *
 * 모양은 런처(`src/views/launcher.php`)와 같은 부품(`.card-grid` > `.card.card--link`)이고,
 * 그 HTML 은 `Wiki::renderBooks()` 가 만든다 — 책 이름·설명은 코드 상수(`Wiki::BOOKS`),
 * 글 수는 차례 파일에서 센 숫자다. 요청에서 온 값은 한 글자도 화면에 들어가지 않는다.
 *
 * ★ 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `role:Admin` 이 판단한다 (PRD S-01).
 *   재인증 관문 [3-2] 도 그 정책을 따라 자동으로 걸린다(`TBOX_REAUTH_RETURN` 의 `wiki` 키).
 * ★ 감사 기록을 남기지 않는다 (PRD F-21).
 * ★ 스크립트 없이 동작한다 — 타일은 그냥 링크다. 인라인 <script>·style·on* 없음 (CSP).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

require_once dirname(__DIR__) . '/Wiki.php';

$wikiBody = Wiki::renderBooks();

$pageTitle = '위키';
$activeNav = 'wiki';
$pageKey   = 'wiki';
require __DIR__ . '/../views/shell_top.php';
?>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">위키</h1>
        <p class="page-head__sub">사이트와 두 앱의 구조·운영 해설입니다. 읽을 책을 고르세요 (관리자).</p>
      </div>
    </header>

<?= $wikiBody ?>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
