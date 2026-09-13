<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_suggest.php — GET /api/purchase/suggest  (정책: purchase.read)
 *
 * ★ 23단계 **발주 제안 목록** (PRD F-36, 시안 ⑩ Status 의 「발주 제안」 열).
 *
 *   ?location=    한 장소만        ?branch=  한 지점만
 *   ?cat1=        대분류            ?q=       품목 검색
 *   ?only_short=1 **제안이 있는 줄만** (제안 0 은 빼고 본다 — 시안의 「부족만」 과 다른 축이다)
 *   ?page=        1부터
 *
 * 왜 별도 창구인가: `/api/purchase/stock` 은 "지금 얼마나 있나" 를 보는 표라 제안 0 인 줄도
 * 보여 준다. 이 창구는 "무엇을 시켜야 하나" 만 뽑는다 — 화면에서 제안을 골라 발주요청으로
 * 옮기는 흐름(`POST /api/purchase/suggest/apply`)이 이 목록을 재료로 쓴다.
 *
 * 계산은 `src/purchase/Suggest.php` 한 곳이고, 응답에는 **근거 값이 전부** 실린다
 * (`par_qty`·`safety_qty`·`on_hand`·`incoming`·`weekly_use`·`lead_days`·`lead_use`·
 *  `need_qty`·`min_order_qty`·`usage_basis`). 화면은 그것을 그대로 보여 주기만 한다 —
 * 브라우저에서 다시 계산하면 두 곳이 갈라지고, 갈라진 날 사람은 화면 쪽을 믿는다.
 *
 * 지점 격리 (SECURITY R-65): `Authz::purchaseScopeWhere($user, 'l')` 를 **장소**에 건다.
 * requester 는 자기 지점 장소만 보이고, `?branch=` 에 남의 지점을 적어도 조건은 좁아질 뿐이다
 * (PRD S-01, T-42 · T-48 a).
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Suggest.php';

$db = Db::instance();

$locationId = Http::queryInt('location', 0, 1, 999999999);
$branchId   = Http::queryInt('branch', 0, 1, 999999999);
$cat1       = Http::queryInt('cat1', 0, 1, 999999999);
$page       = Http::queryInt('page', 1, 1, 500);
$onlyShort  = Http::queryChoice('only_short', ['0', '1'], '1') === '1';
$q          = Http::queryText('q', 100);

$scopeL = Authz::purchaseScopeWhere($user, 'l');

$result = Suggest::rows($db, $scopeL, [
    'location_id' => $locationId,
    'branch_id'   => $branchId,
    'cat1'        => $cat1,
    'q'           => $q,
    'gap_short'   => false,
    'only_short'  => $onlyShort,
    'page'        => $page,
    'per_page'    => Suggest::PER_PAGE,
]);

Http::json([
    'items'    => $result['items'],
    'total'    => $result['total'],
    'page'     => $page,
    'per_page' => Suggest::PER_PAGE,
    /*
     * 식을 응답에도 한 줄로 적어 둔다 — 화면이 계산하지 않는다는 계약을 사람이 눈으로
     * 확인할 수 있게 하는 문장이고, 값이 아니라 설명이다 (시안 ⑩ 표 아래 줄과 같은 말).
     */
    'formula'  => '⌈(인가량 + 안전재고 − 현재고 − 미입고 + 조달기간 예상 사용) ÷ 최소구매단위⌉ × 최소구매단위',
]);
