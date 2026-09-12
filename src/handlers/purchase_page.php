<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_page.php — GET /purchase  (정책: purchase.read)
 *
 * ★ 19단계에는 화면이 **기초정보 하나뿐**이라 그리로 보낸다 (PRD F-28).
 *   전표·현황 탭은 20~23단계에 생긴다. 그때 이 파일이 「입력 · 전표 · 현황」 3탭 허브가 된다.
 *
 * 보내는 곳은 **코드 상수**다 — 요청 값이 Location 헤더에 섞이는 자리가 없다(오픈 리다이렉트 0).
 * 여기까지 왔다는 것 자체가 라우트 정책 `purchase.read` 를 통과했다는 뜻이고,
 * 가는 곳(`/purchase/masters`)도 **같은 정책**을 다시 검사한다 (PRD S-01).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

const TBOX_PURCHASE_HOME = '/purchase/masters';

Http::redirect(TBOX_PURCHASE_HOME);
