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
$activeNav = 'purchase';
$pageKey   = '';
require __DIR__ . '/../views/purchase_import.php';
