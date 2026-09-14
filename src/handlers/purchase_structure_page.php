<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_structure_page.php — GET /purchase/structure
 *   (정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 24단계 「브랜드·지점·창고」 한 페이지 (PRD F-37, ARCH 5.18 「창고 대기표」).
 *
 * 이 파일은 **틀만** 만든다. 나무·대기표·추천은 `purchase_structure.js` 가
 * `/api/purchase/structure` 를 불러 `textContent` 로만 그린다 (PRD S-03).
 *
 * ★ 역할 비교를 두지 않는다 (PRD S-01, ARCH 13장). 여기까지 온 것 자체가 라우트 정책
 *   `purchase.master.write` 와 재인증 관문을 통과했다는 증거다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '브랜드 · 지점 · 창고';
$activeNav = 'purchase-structure';
$pageKey   = 'purchase';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'purchase-structure';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_structure.php';
