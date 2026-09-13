<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_recommend.php — GET /api/purchase/recommend  (정책: purchase.read)
 *
 * ★ 20단계 「자주 주문하는 품목」 추천 (PRD F-31, 시안 ③ MyList, SECURITY R-68).
 *
 *   ?scope=me       (기본) 내 계정이 담당자인 발주요청 줄만, 최근 90일
 *   ?scope=branch   위 + 내 지점의 줄. **두 조건이 모두 맞을 때만** 열린다:
 *                     ① 내가 지점에 묶인 사람이다 (`purchaseScopeWhere` 가 조건을 준다)
 *                     ② 내가 「지점과 공유」를 켠 리스트를 하나라도 갖고 있다
 *                   아니면 조용히 `me` 로 되돌린다 — 400 을 내면 "그런 범위가 있다" 만 알려 준다.
 *
 * 왜 이렇게 좁히나 (R-68): 추천은 "누가 무엇을 얼마나 시키나" 의 요약이다. 범위를 한 칸만
 * 넓혀도 다른 지점·다른 사람의 주문 패턴이 그대로 드러난다. 판정 규칙은
 * `src/purchase/Recommend.php` 한 곳에 있고, 지점 조건은 `Authz` 가 만든다.
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Recommend.php';

$want  = Http::queryChoice('scope', ['me', 'branch'], 'me');
$scope = Authz::purchaseScopeWhere($user, 'd');

$db          = Db::instance();
$branchReady = Recommend::branchAllowed($db, $scope, (int) $user['id']);
$withBranch  = $want === 'branch' && $branchReady;

$items = Recommend::topItems($db, $scope, (int) $user['id'], $withBranch);

Http::json([
    'scope'         => $withBranch ? 'branch' : 'me',
    'scope_asked'   => $want,
    // 화면이 「지점 전체」 단추를 켤 수 있는지. 판정은 서버가 다시 한다 (PRD S-01).
    'branch_ready'  => $branchReady,
    'days'          => Recommend::DAYS,
    'items'         => $items,
    'total'         => count($items),
]);
