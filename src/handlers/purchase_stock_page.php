<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_stock_page.php — GET /purchase/stock  (정책: purchase.read)
 *
 * ★ 21단계 **현황** 화면 (시안 ⑩ Status · ⑪ Backorder, PRD F-34).
 *
 * 세 탭이 한 화면에 있다: 장소별 재고 · 수불부 · 미입고 현황.
 * 틀만 만들고 값은 `purchase_stock.js` 가 `textContent` 로 채운다 (PRD S-03).
 * 무엇이 보일지는 서버의 SQL WHERE(`Authz::purchaseScopeWhere`)가 정한다 — 화면은 고르지 않는다.
 *
 * ★ `$activeNav` 는 `src/views/shell_top.php` 의 허용 목록에 아직 없는 값이다(그 파일은
 *   이번 지시서의 「수정 파일」 밖이다). 목록에 없는 값은 빈 문자열이 되어 **아무 내비 항목도
 *   켜지지 않을 뿐** 오류가 아니다 — 내비에 항목을 더하는 것은 별도 작업이다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '재고 현황';
$activeNav = 'purchase-stock';
$pageKey   = 'purchase';
require __DIR__ . '/../views/purchase_stock.php';
