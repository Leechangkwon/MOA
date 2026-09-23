<?php
declare(strict_types=1);

/**
 * src/handlers/wiki_page.php — GET /wiki/{book}/{slug} (정책: role:Admin → PRD F-21, SECURITY R-56)
 *
 * 요구사항: PRD F-21 · 8장 `/wiki/{slug}` 행 · 10장 화면 요구사항(「위키」 행), S-01, S-03,
 *           ARCHITECTURE 5.10, SECURITY R-56, T-32
 *
 * 위키 **글 한 편**이다. 제목·요약·절 차례(앵커)·본문·이전/다음 글을 그린다.
 * 책이 나뉘기 전의 주소 `/wiki/{slug}` 는 wiki_legacy.php 가 `/wiki/tbox/{slug}` 로 308 을 준다.
 *
 * ★ 역할 비교를 두지 않는다. "Admin 만" 은 라우트 정책 `role:Admin` 이 판단한다 (PRD S-01).
 *
 * ★ `$params[0]` 은 자리표시자 `{book}`(고정 목록 셋), `$params[1]` 은 `{slug}`(정규식
 *   `[a-z0-9][a-z0-9-]{0,39}`)가 이미 걸러 준 값이다. 그래도 책은 `Wiki::bookKey()` 로 목록 쪽
 *   문자열로 바꾸고, `Wiki::pathFor()` 가 slug 정규식을 다시 보고 책 디렉터리와 글 파일을 둘 다
 *   realpath 로 풀어 `wiki/<책>/` 안인지 확인한다 — 검사는 값을 **쓰는 쪽**에 있어야 한다
 *   (ARCH 5.10, Storage::pathFor 와 같은 방식). 그래서 `/wiki/tbox/../x` 같은 경로는 애초에
 *   라우트에 맞지 않아 404 이고, 설령 맞더라도 파일 경로가 만들어지지 않는다.
 *
 * ★ 없는 글은 `404 찾을 수 없습니다` — 다른 404 와 **같은 문구**다. 글이 있는지 없는지
 *   말고는 아무것도 알리지 않는다 (PRD 8장 `/wiki` 행, S-09-5 취지).
 *
 * ★ 본문 문자열은 예외 없이 htmlspecialchars(ENT_QUOTES|ENT_SUBSTITUTE) 를 거쳐 나간다
 *   (PRD S-03, SECURITY R-56 ②). 글에 `<script>` 가 적혀 있어도 태그가 아니라 글자로 보인다.
 *   모르는 블록 종류·빠진 필드는 조용히 건너뛰지 않고 예외 → 500 이다 (ARCH 5.10).
 *
 * ★ 감사 기록을 남기지 않는다 (PRD F-21).
 *
 * 차례(`index.json`)는 **링크 판단·이전/다음**에만 쓴다. 차례에 없는 글이어도 파일이 있으면
 * 그린다 — 차례를 고치지 않아 글이 통째로 사라지는 일을 만들지 않기 위해서다.
 *
 * 인라인 <script>·style 속성·on* 이벤트 속성을 쓰지 않는다 (CSP).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params [0] => book, [1] => slug
 */

require_once dirname(__DIR__) . '/Wiki.php';

$wikiBook = Wiki::bookKey((string) ($params[0] ?? ''));
$wikiSlug = (string) ($params[1] ?? '');
if ($wikiBook === null || preg_match(Wiki::SLUG_RE, $wikiSlug) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$wikiPage = Wiki::loadPage($wikiBook, $wikiSlug);
if ($wikiPage === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$wikiIndex = Wiki::loadIndex($wikiBook) ?? [];
$wikiBody  = Wiki::renderPage($wikiBook, $wikiPage, $wikiIndex);

$pageTitle = (string) $wikiPage['title'];
$activeNav = 'wiki';
$pageKey   = 'wiki';
require __DIR__ . '/../views/shell_top.php';
?>
<?= $wikiBody ?>
<?php require __DIR__ . '/../views/shell_bottom.php'; ?>
