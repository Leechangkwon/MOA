<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_stock_status.php — GET /api/purchase/stock  (정책: purchase.read)
 *
 * ★ 21단계 **장소별 재고 현황** (PRD F-34, 시안 ⑩ Status) + 23단계 **발주 제안**(PRD F-36).
 *
 *   ?location=  한 장소만        ?branch=  한 지점만
 *   ?cat1=      대분류            ?q=       품목 검색
 *   ?short=1    과부족이 음수인 것만 (시안의 「부족만」)
 *   ?page=      1부터
 *
 * 한 행 = **(장소 × 품목)** 이고 칸은 전부 서버 계산값이다:
 *   현재고    원장의 합 (뷰 `p_stock_balance`) — 컬럼이 아니다 (ARCH 5.18 불변 규칙 2)
 *   인가량    `p_par_levels` 중 오늘 유효한 마지막 것
 *   과부족    현재고 − 인가량
 *   미입고    뷰 `p_backorder` 에서 이 장소로 올 잔량의 합
 *   주평균 사용  최근 4주(28일) 사용량 ÷ 4. 사용량은 **사용(IS) 전표의 음수 행**이고,
 *              재고관리를 하지 않는 품목만 **입고(GR) 의 양수 행**을 사용량으로 본다
 *              (그 품목은 IS 전표를 쓰지 않는다 — ARCH 5.18 「재고관리 안 하는 품목」)
 *   발주 제안  ⌈(인가량+안전−현재고−미입고+조달기간 예상 사용)÷최소단위⌉×최소단위.
 *              **`src/purchase/Suggest.php` 한 곳**이 계산하고 근거 값(`need_qty`·`lead_use`·
 *              `usage_basis`)도 함께 내려 준다 — 화면은 표시만 한다 (PRD F-36, 23단계)
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'l')` 를 **장소**(`p_locations`)에 건다. 재고는 장소에
 *   붙어 있으므로 장소를 좁히면 재고도 좁는다. `?branch=` 에 남의 지점을 적어도 조건은
 *   바뀌지 않는다 — 그 값은 **내 범위 안에서 더 좁히는** 데만 쓰인다 (PRD S-01, T-42).
 *   조회 SQL 은 `Suggest::rows` 에 있지만 **범위 조각은 여기서 만들어 넘긴다** —
 *   판정이 Authz 밖으로 새지 않는다 (ARCH 4.1, `Usage.php` 와 같은 모양).
 *
 * 조회는 감사 로그를 남기지 않는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../purchase/Suggest.php';

const TBOX_STOCK_PER_PAGE = Suggest::PER_PAGE;

$db = Db::instance();

$locationId = Http::queryInt('location', 0, 1, 999999999);
$branchId   = Http::queryInt('branch', 0, 1, 999999999);
$cat1       = Http::queryInt('cat1', 0, 1, 999999999);
$page       = Http::queryInt('page', 1, 1, 500);
$short      = Http::queryChoice('short', ['0', '1'], '0') === '1';
$q          = Http::queryText('q', 100);

$scopeL = Authz::purchaseScopeWhere($user, 'l');

$result = Suggest::rows($db, $scopeL, [
    'location_id' => $locationId,
    'branch_id'   => $branchId,
    'cat1'        => $cat1,
    'q'           => $q,
    'gap_short'   => $short,
    // 현황은 제안이 0 인 줄도 보여 준다 — 「지금 시켜야 하나」를 판단하려면 0 도 정보다.
    'only_short'  => false,
    'page'        => $page,
    'per_page'    => TBOX_STOCK_PER_PAGE,
]);

Http::json([
    'items'    => $result['items'],
    'total'    => $result['total'],
    'page'     => $page,
    'per_page' => TBOX_STOCK_PER_PAGE,
]);
