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

$pageTitle = '전표 · 사이클';
$activeNav = 'purchase-docs';
$pageKey   = 'purchase';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'purchase-docs';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_docs.php';
