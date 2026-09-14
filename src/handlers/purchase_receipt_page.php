<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_receipt_page.php — GET /purchase/receipt  (정책: purchase.read)
 *
 * ★ 21단계 **구매(입고) 입력** 화면 (시안 ⑥ GR, PRD F-33).
 *
 * 「발주서 불러오기」로 미입고 잔량을 끌어와 **실제 온 수량만** 적고, 로트·유효기간을 단다.
 * 틀만 만들고 값은 `purchase_stock.js` 가 그린다. 저장 요청 본문에는 **수량·로트·유효기간**만
 * 실린다 — 단가·금액 칸이 아예 없다(서버가 발주서 줄에서 읽는다, SECURITY R-66).
 *
 * 화면은 `purchase.read` 로 열리지만 저장은 `purchase.stock.write` 다 — 볼 수는 있어도
 * 넣지 못하는 사람이 있고, 그 차단은 라우트 정책이 한다 (PRD S-01).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '입고 입력';
$activeNav = 'purchase-receipt';
$pageKey   = 'purchase';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'purchase-receipt';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_receipt.php';
