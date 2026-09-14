<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_import_page.php — GET /purchase/import  (정책: purchase.master.write)
 *
 * ★ 19단계 엑셀 가져오기 화면 (시안 ⑯ ExcelImport, PRD F-29).
 *
 * 정책이 `purchase.master.write` 라 **화면과 API 가 같은 자격**을 쓴다 — 화면만 열어 두고
 * API 를 막거나 그 반대면 그것이 곧 우회 경로다 (PRD S-01, 계정 관리 화면과 같은 원칙).
 * 그 정책은 `REAUTH_POLICIES` 에 있으므로 재인증 관문 [3-2] 가 자동으로 따라붙는다:
 * 15분 안에 비밀번호를 다시 확인하지 않았으면 라우터가 `/reauth` 로 보낸다 (F-23).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '엑셀 가져오기';
$activeNav = 'purchase-import';
// 20단계 — 셸이 화면 키를 받아야 app.js 가 initShell 을 돈다(19단계 「내비가 사라짐」 사고).
$pageKey   = 'purchase';
/* 「사용 방법」 도움말의 키 — 글은 `src/help/screens.php` 한 벌이고 셸이 그린다 (PRD F-38, ARCH 5.19). */
$pageHelp  = 'purchase-import';
/* 25단계 — 스타일시트는 **셸이 head 에서** 싣는다(본문 한가운데 <link> 를 없애 FOUC 0),
   알림 줄(`#msg`)은 화면이 .page-head 바로 아래에 한 번만 놓는다(id 중복 0). T-50 (b). */
$pageCss    = ['purchase.css'];
$pageMsgTop = true;
require __DIR__ . '/../views/purchase_import.php';
