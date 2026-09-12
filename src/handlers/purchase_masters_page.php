<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_masters_page.php — GET /purchase/masters  (정책: purchase.read)
 *
 * ★ 19단계 기초정보 화면 (시안 ⑭ Masters, PRD F-28).
 *
 * 이 파일은 **틀만** 만든다. 목록·편집은 `purchase.js` 가 `/api/purchase/*` 를 불러
 * `textContent` 로만 그린다 (PRD S-03 — inner/outer HTML 계열을 쓰지 않는다).
 * 화면이 그리는 값(브랜드·지점·장소·거래처 이름)은 전부 사람이 적은 문자열이다.
 *
 * ★ 역할 비교를 두지 않는다 (PRD S-01, ARCH 13장). 여기까지 온 것 자체가 라우트 정책
 *   `purchase.read` 를 통과했다는 증거이고, 편집 단추를 보일지는 API 가 내려 주는
 *   `can_edit` 가 정한다 — 단추를 개발자도구로 되살려도 `purchase.master.write` 정책과
 *   재인증 관문이 서버에서 다시 막는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

$pageTitle = '기초정보';
$activeNav = 'purchase';
$pageKey   = '';
require __DIR__ . '/../views/purchase_masters.php';
