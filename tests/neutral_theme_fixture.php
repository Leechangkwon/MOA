<?php
declare(strict_types=1);
// Isolated view fixture: actual PHP views/renderers, no session/bootstrap/database.
if (PHP_SAPI !== 'cli-server') { exit(1); }
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/assets/')) { return false; }
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:");
if ($path === '/theme-palette.css') {
    require $root . '/src/community/Doc.php';
    require $root . '/src/community/Palette.php';
    header('Content-Type: text/css');
    echo Palette::css([['name'=>'review','light'=>'#2859a8','dark'=>'#a0c2ff']], '123456789abc');
    return;
}
if (($_GET['screen'] ?? '') !== 'components') {
    require __DIR__ . '/design_fixture.php';
    return;
}
$_GET['screen'] = 'community-page';
ob_start();
require __DIR__ . '/design_fixture.php';
$html = ob_get_clean();
require $root . '/src/community/Doc.php';
require $root . '/src/community/DocRender.php';
$doc = ['schema'=>1, 'blocks'=>[
    ['id'=>'00000000-0000-4000-8000-000000000001','type'=>'paragraph','text'=>[['t'=>'합성 문서 · 원문 색 보존 검사','m'=>['bold'=>true]]]],
    ['id'=>'00000000-0000-4000-8000-000000000002','type'=>'callout','color'=>'blue_bg','text'=>[['t'=>'지정한 강조 배경','m'=>[]]]],
    ['id'=>'00000000-0000-4000-8000-000000000003','type'=>'callout','color'=>'pal:review_bg','text'=>[['t'=>'사용자 지정 팔레트','m'=>[]]]],
    ['id'=>'00000000-0000-4000-8000-000000000004','type'=>'table','header_row'=>true,'header_col'=>true,'rows'=>[
        ['color'=>'yellow_bg','cells'=>[['color'=>'green_bg','text'=>[['t'=>'셀 지정','m'=>[]]]],['text'=>[['t'=>'행 지정','m'=>[]]]]]],
        ['cells'=>[['text'=>[['t'=>'기본 머리칸','m'=>[]]]],['text'=>[['t'=>'본문 칸','m'=>[]]]]]],
    ]],
]];
$html = str_replace('<article id="page-doc" class="panel" aria-live="polite"></article>', '<article id="page-doc" class="panel" aria-live="polite">' . DocRender::html($doc) . '</article>', $html);
$controls = <<<'HTML'
<section class="panel" id="neutral-controls" aria-label="합성 구성 요소 검사">
 <h2 class="panel__title">구성 요소 검사</h2><p id="neutral-body">본문은 중립색으로 읽습니다.</p><p class="help" id="neutral-muted">보조 설명과 기능 상태를 구분합니다.</p>
 <div class="toolbar"><button id="neutral-primary" type="button" class="btn">변경 저장</button><button id="neutral-quiet" type="button" class="btn btn--quiet">취소</button><a id="neutral-link" href="#neutral-controls">자세히 보기</a></div>
 <label class="field"><span>자료 이름</span><input id="neutral-input" class="input" value="합성 업무 자료 이름"></label>
 <label class="field"><span>표시 방식</span><select id="neutral-select" class="input"><option>최근 자료와 즐겨찾기</option></select></label>
 <a id="neutral-active" class="nav__item is-active" href="#neutral-controls">선택한 메뉴</a>
 <p><span class="badge badge--public">공개</span><span class="badge badge--admin">관리</span></p>
</section>
HTML;
$html = str_replace('</main>', $controls . '</main>', $html);
$html = str_replace('</head>', '<link rel="stylesheet" href="/theme-palette.css"></head>', $html);
echo $html;
