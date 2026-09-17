<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_managers_list.php
 *   GET /api/purchase/managers  (정책: purchase.buy.write)
 *
 * ★ F-62 **발주서 머리 담당자 칸** (2026-09-17, ARCH 5.18 「발주서 담당자·3축 분할」,
 *   완료 기준 T-74 (i), 사용자 결정 2026-09-17).
 *
 * 발주서 머리의 「담당자」 칸이 고를 수 있는 사람 목록이다. 비워 두면 줄마다 발주요청서의
 * 담당자로 나뉘고, 고르면 그 담당자로 한 장이 된다 — 고르는 사람은 **발주서를 만드는
 * 사람뿐**이므로 정책이 `purchase.buy.write`(buyer·Admin)다. `purchase.read` 로 열면
 * 요청자·재고 담당까지 사내 계정 목록을 받아 보게 되고, 그것은 이 칸이 필요한 범위 밖이다.
 *
 * ★ 조회는 **`Doc::managerOk` 와 같은 정의**다 (활성 · 모듈 역할이 있는 계정).
 *   목록과 검사가 어긋나면 화면에서 고를 수 있는데 저장이 `bad_reference` 로 거부된다 —
 *   같은 문장을 두 곳에 적는 대신 **같은 조건**을 적는 것이 이 파일의 유일한 규칙이다.
 *   `purchase_role` 은 「NULL 이 아니다」 만 본다. **값을 견주지 않는다** — 역할 값 비교는
 *   Authz 밖에 두지 않는다 (SECURITY R-70, scripts/security_lint.sh 규칙 (b)).
 *
 * 내보내는 칸은 **`id`·`name` 둘뿐**이다. 이메일·역할·팀·지점·전화는 담당자를 고르는 데
 * 필요하지 않고, 필요하지 않은 값을 내보내는 창구가 곧 사내 명부 유출 경로다 (PRD S-02 취지).
 *
 * 조회이므로 **감사 로그를 남기지 않는다** (ARCH 5.1 마지막 줄, PRD S-07 — 조회는 사건이 아니다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

/**
 * 값 바인딩이 없는 **고정 문장**이다 — 요청 문자열이 이 SQL 에 닿는 경로가 없다
 * (걸개도 페이지도 받지 않는다: 담당자로 적을 수 있는 사람은 사내 계정 수십 명이다).
 *
 * lint:no-branch-scope 담당자 후보는 전 지점에서 고른다 — Doc::managerOk 와 같은 정의이고 창구는 buyer·Admin 전용이다
 */
$sqlManagers = <<<'SQL'
    SELECT id, username AS name FROM users
     WHERE is_active AND purchase_role IS NOT NULL
     ORDER BY username ASC
    SQL;

$rows = Db::instance()->run($sqlManagers, [])->fetchAll();

// DB 는 정수를 문자열로 돌려줄 수 있다 — 화면이 숫자로 다루게 맞춰 준다
// (purchase_masters_list.php 의 「값 모양 다듬기」 와 같은 이유·같은 방식).
$items = [];
foreach ($rows as $row) {
    $items[] = [
        'id'   => (int) $row['id'],
        'name' => (string) $row['name'],
    ];
}

Http::json(['items' => $items]);
