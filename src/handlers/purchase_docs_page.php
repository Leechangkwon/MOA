<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_docs_page.php — GET /purchase/docs  (정책: purchase.read)
 *
 * ★ 20단계 전표·사이클 추적 화면 (시안 ⑧ Docs · ⑨ Cycle, PRD F-32 R4).
 *
 * 요청·발주서 목록과 「요청 줄 하나가 어디까지 갔나」 를 한 화면에서 본다.
 * 입고(GR) 칸은 21단계 것이라 자리만 있고 값은 비어 있다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '전표';
$activeNav = 'purchase';
$pageKey   = 'purchase';
require __DIR__ . '/../views/purchase_docs.php';
