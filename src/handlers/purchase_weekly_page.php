<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_weekly_page.php — GET /purchase/usage  (정책: purchase.read)
 *
 * ★ 22단계 **주간 사용량** 화면 (시안 Weekly, PRD F-34).
 *
 * 틀만 만들고 값은 `purchase_recon.js` 가 `textContent` 로 채운다 (PRD S-03).
 * 무엇이 보일지는 서버의 SQL WHERE(`Authz::purchaseScopeWhere`)가 정한다 — 화면은 고르지 않는다.
 *
 * ★ `$activeNav` 는 `src/views/shell_top.php` 의 허용 목록에 아직 없는 값이다(그 파일은
 *   이번 지시서의 「수정 파일」 밖이다). 목록에 없는 값은 빈 문자열이 되어 **아무 내비 항목도
 *   켜지지 않을 뿐** 오류가 아니다 (21단계 화면 셋과 같은 사정).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '주간 사용량';
$activeNav = 'purchase-usage';
$pageKey   = 'purchase';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'purchase-usage';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_weekly.php';
