<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_move_page.php — GET /purchase/move  (정책: purchase.read)
 *
 * ★ 21단계 **이동 · 사용 · 실사** 화면 (시안 ⑦ MoveUse, PRD F-33 · Q-18③).
 *
 * 탭 셋이 한 화면에 있다.
 *   이동  보내는 장소 → 받는 장소. 「중앙장 부족분 자동 채우기」 제안 단추가 있다
 *   사용  한 장소에서 쓴 만큼 뺀다(받는 장소가 없다)
 *   실사  장부 스냅샷 옆에 실제 센 수량을 적어 **차이만** 원장에 남긴다. 주기 3종(Q-18③)
 *
 * 셋 다 저장은 `purchase.stock.write` 이고, 음수 재고는 서버가 막는다(400 `negative_stock`).
 * 화면의 빨간 줄은 안내일 뿐 근거가 아니다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '이동 · 사용 · 실사';
$activeNav = 'purchase-move';
$pageKey   = 'purchase';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_move.php';
