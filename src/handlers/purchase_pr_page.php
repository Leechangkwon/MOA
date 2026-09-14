<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_pr_page.php — GET /purchase/pr  (정책: purchase.read)
 *
 * ★ 20단계 발주요청 화면 (시안 ① Main — 입력·전표·현황 3탭, PRD F-30).
 *
 * 이 파일은 **틀만** 만든다. 목록·입력은 `purchase_docs.js` 가 `/api/purchase/*` 를 불러
 * `textContent` 로만 그린다 (PRD S-03 — inner/outer HTML 계열을 쓰지 않는다).
 *
 * ★ 역할 비교를 두지 않는다 (PRD S-01, ARCH 13장). 여기까지 온 것 자체가 라우트 정책
 *   `purchase.read` 를 통과했다는 증거이고, 「저장」 단추를 보일지는 API 가 내려 주는
 *   `can_write` 가 정한다 — 단추를 개발자도구로 되살려도 `purchase.request.write` 가 다시 막는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '발주요청 입력';
$activeNav = 'purchase-pr';
// 19단계 사고(내비가 사라짐)의 후속 — 셸이 화면 키를 받아야 app.js 가 initShell 을 돈다.
$pageKey   = 'purchase';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'purchase-pr';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_pr.php';
