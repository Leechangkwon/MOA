<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_recon_page.php — GET /purchase/recon  (정책: purchase.read)
 *
 * ★ 22단계 **거래처 월 대사 · 월 마감** 화면 (시안 Recon, PRD F-35).
 *
 * 두 탭이 한 화면에 있다: 월 대사 · 월 마감. 마감 단추는 `purchase.close` 정책의 API 를
 * 부르므로 자격이 없으면 403 이고, 재인증 창이 닫혀 있으면 403 `next:reauth` 다 —
 * **화면이 판단하지 않는다**(PRD S-01).
 *
 * 틀만 만들고 값은 `purchase_recon.js` 가 `textContent` 로 채운다 (PRD S-03).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '월 대사 · 마감';
$activeNav = 'purchase-recon';
$pageKey   = 'purchase';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_recon.php';
