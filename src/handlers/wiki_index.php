<?php
declare(strict_types=1);

/**
 * src/handlers/wiki_index.php — GET /wiki/{book} (정책: role:Admin → PRD F-21, SECURITY R-56)
 *
 * 요구사항: PRD F-21 · 8장 `/wiki` 행 · 10장 화면 요구사항(「위키」 행), S-01, S-03,
 *           ARCHITECTURE 5.10, SECURITY R-56
 *
 * 책 하나(`qaredi`·`tbox`·`community`)의 **차례**다. 안내 글 목록과 단계 카드를 보여 주고,
 * 각 글은 `/wiki/{book}/{slug}` 로 간다. 책 고르기는 `/wiki`(wiki_books.php)다 (2026-09-23).
 *
 * ★ `$params[0]` 은 라우터 자리표시자 `{book}` 이 고정 목록 셋으로 이미 걸러 준 값이다. 그래도
 *   `Wiki::bookKey()` 로 **목록 쪽 문자열**로 바꿔 쓰고, 목록에 없으면 404 다 — 검사는 값을
 *   쓰는 쪽에 있어야 한다(Wiki.php 머리의 ③).
 *
 * ★ 이 파일에 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `role:Admin` 이 판단하고,
 *   화면은 여기까지 왔다는 사실 자체가 통과의 증거다 (PRD S-01). 좌측 내비의 「위키」 항목을
 *   개발자도구로 되살려도 이 경로는 라우터가 다시 403 으로 막는다 — 항목 표시는 편의이고
 *   근거는 라우트 정책이다.
 *
 * ★ 본문은 저장소 `wiki/<책>/*.json` 에서 오고, HTML 은 `Wiki` 가 만든다. 글에서 온 문자열은
 *   예외 없이 htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE) 를 거친다 (PRD S-03, R-56 ②).
 *   그래서 이 파일은 `Wiki::renderIndex()` 가 돌려준 문자열을 그대로 내보낸다 —
 *   여기서 값을 덧붙이거나 이어 붙이지 않는다(책 이름·설명은 Wiki::BOOKS 코드 상수).
 *
 * ★ 감사 기록을 남기지 않는다 (PRD F-21) — 문서 열람은 보안 사건이 아니고 개인정보도 없다.
 *
 * ★ 차례 파일이 아직 없으면 **오류가 아니라 안내**다. 글은 사람이 쓰는 것이고, 한 편도
 *   없는 상태는 정상이다. (파일이 있는데 모양이 틀리면 그때는 500 이다 — 조용히 넘기면
 *   "글이 안 보인다" 의 원인을 아무도 못 찾는다, ARCH 5.10.)
 *
 * `src/Wiki.php` 는 여기서 직접 읽어 온다. 부트스트랩의 require 목록에 넣지 않은 이유는
 * ① 위키 화면 말고는 쓰는 곳이 없고 ② `scripts/wiki_check.php`(CLI)가 DB·세션 없이
 * 이 클래스 하나만 읽어서 전 글을 검사할 수 있어야 하기 때문이다.
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params [0] => book
 */

require_once dirname(__DIR__) . '/Wiki.php';

$wikiBook = Wiki::bookKey((string) ($params[0] ?? ''));
if ($wikiBook === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$wikiBookInfo = Wiki::BOOKS[$wikiBook];

$wikiIndex = Wiki::loadIndex($wikiBook);
$wikiBody  = $wikiIndex === null ? null : Wiki::renderIndex($wikiBook, $wikiIndex);

$pageTitle = '위키 — ' . $wikiBookInfo['name'];
$activeNav = 'wiki';
$pageKey   = 'wiki';
require __DIR__ . '/../views/shell_top.php';
?>
    <p class="wiki-crumb"><a href="<?= htmlspecialchars(Http::url('/wiki'), ENT_QUOTES, 'UTF-8') ?>">← 위키 책 고르기</a></p>
    <header class="page-head">
      <div class="page-head__text">
        <h1 class="page-head__title">위키 — <?= htmlspecialchars($wikiBookInfo['name'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-head__sub"><?= htmlspecialchars($wikiBookInfo['desc'], ENT_QUOTES, 'UTF-8') ?> (관리자).</p>
      </div>
    </header>

<?php if ($wikiBody === null) { ?>
    <section class="card card--pad">
      <p class="muted">아직 글이 없습니다. 글이 저장소의 <code>wiki/<?= htmlspecialchars($wikiBook, ENT_QUOTES, 'UTF-8') ?>/</code> 에 놓이면 여기에 차례가 나옵니다.</p>
    </section>
<?php } else { ?>
<?= $wikiBody ?>
<?php } ?>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
