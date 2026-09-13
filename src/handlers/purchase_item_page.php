<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_item_page.php — GET /purchase/items/{num}  (정책: purchase.read)
 *
 * ★ 19단계 품목 상세·등록 화면 (시안 ⑮ Item, PRD F-28).
 *
 * 경로의 `{num}` 은 `p_items.id` 다. **이 파일은 그 값으로 DB 를 읽지 않는다** —
 * 화면이 `/api/purchase/items/{id}` 를 불러 채우고, 볼 수 있는지는 그 API 가 판단한다
 * (지점 격리 포함). 여기서 HTML 에 심는 것은 `data-item-id` 속성 하나이고, 값은
 * 라우터의 자리표시자 정규식(`[0-9]{1,9}`)을 통과한 숫자뿐이며 htmlspecialchars 를 거친다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params $params[0] = p_items.id
 */

$itemIdRaw = $params[0] ?? '';
$itemId    = is_string($itemIdRaw) && preg_match('/^[0-9]{1,9}$/', $itemIdRaw) === 1 ? $itemIdRaw : '';

$pageTitle = '품목';
$activeNav = 'purchase';
// 20단계 — 셸이 화면 키를 받아야 app.js 가 initShell 을 돈다(19단계 「내비가 사라짐」 사고).
$pageKey   = 'purchase';
require __DIR__ . '/../views/purchase_item.php';
