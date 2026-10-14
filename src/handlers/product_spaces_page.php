<?php
declare(strict_types=1);
// Authenticated page route: common session, first-login and consent gates run before this view.
// 사이트 H3 (DESIGN 2.29 「「우리 공간」 = 카페」) — 「우리 공간」은 이제 카페(/community/cafe). 쿼리 `space` 가 없으면 그리로 302 ·
// `?space=<id>`(대시보드 위젯 「전체 보기」 · 옛 링크)가 있으면 지금 공간 화면 그대로(판정은 공간 창구가 한다 — 이 파일은 갈래만).
if (!isset($_GET['space'])) Http::redirect(Http::communityUrl('/cafe'));
require __DIR__.'/../views/space_page.php';
