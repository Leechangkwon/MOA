<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_po_page.php — GET /purchase/po  (정책: purchase.read)
 *
 * ★ 20단계 발주서 화면 (시안 ④ ImportModal · ⑤ PO, PRD F-32).
 *
 * 틀만 만들고, 불러오기 트리·발주서 줄·머리 수정은 `purchase_docs.js` 가 그린다.
 * 「담기」·「보냄」 단추를 보일지는 API 의 `can_write`·`can_send` 가 정하고,
 * 실제 차단은 라우트 정책 `purchase.buy.write`·`purchase.send` 가 한다 (PRD S-01).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '발주서';
$activeNav = 'purchase';
$pageKey   = 'purchase';
require __DIR__ . '/../views/purchase_po.php';
