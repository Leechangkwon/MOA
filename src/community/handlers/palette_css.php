<?php
declare(strict_types=1);

/**
 * src/community/handlers/palette_css.php — GET /community/palette.css (정책: community.read — 그리는 화면이 모두 로그인 뒤)
 *
 * 요구사항: `C:F-33` · 지시서 7-D2 P-6 · P-7 · P-17 · 1.4 · ARCH 12.5(「관리자 팔레트는 `<style>` 이 아니라 `:root` 에 CSS 변수로 서버가 그린다」).
 *   셸(`shell_top.php`)이 커뮤니티 화면 셋(홈·페이지·게시판)에 `<link id="palette-css" … ?v=<버전>>` 으로 부른다 — 같은 출처 파일이라
 *   CSP `style-src 'self'` 그대로 · 인라인 `<style>`·`style=` 0. `/tbox/assets/` 밑에 파일을 쓰지 않는다(php-fpm 은 저장소에 쓰지 못한다).
 *   · `Content-Type: text/css; charset=utf-8` · `Cache-Control: private, max-age=604800` · `ETag: "<버전>"`(= 셸의 `?v=` — 내용에서, P-7)
 *     `If-None-Match` 가 같은 버전이면 304(본문 없음).
 *   · 살아 있는 줄만 · 이름순 · 이름·값은 서버의 모양 검사를 지난 글자만 이어 붙인다(`community_palette_rows` → `Palette::css`).
 *   · 줄이 없거나 표를 읽지 못하면(P-17) 머리 주석 한 줄뿐인 200.
 * `Http::json` 이 아니라 `push_sw.php` 처럼 머리를 놓고 본문을 낸다. 보안 머리글(CSP · nosniff …)은 bootstrap 이 붙인다.
 *
 * ★ 역할 판단·쓰기 없음 · 감사 없음(색 파일 받기) · 요청 값이 한 글자도 섞이지 않는다(`?v=` 는 캐시를 가르는 글자일 뿐 읽지 않는다).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$paletteRows    = community_palette_rows();
$paletteVersion = Palette::version($paletteRows);
$paletteEtag    = '"' . $paletteVersion . '"';

if (!headers_sent()) {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: private, max-age=604800');
    header('ETag: ' . $paletteEtag);
}
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $paletteEtag) {
    http_response_code(304);
    exit;
}
http_response_code(200);
echo Palette::css($paletteRows, $paletteVersion);
