<?php
declare(strict_types=1);

/**
 * src/purchase/Recommend.php — 「자주 주문하는 품목」 추천 (20단계, PRD F-31, 시안 ③ MyList)
 *
 * 규칙은 하나다: **내 것만 센다** (SECURITY R-68).
 *
 *   기본 (`scope=me`)      내 계정이 담당자(`p_docs.manager_id`)인 발주요청 줄, 최근 90일
 *   선택 (`scope=branch`)  위 + **내 지점**의 요청 줄. 두 조건이 모두 맞을 때만 열린다:
 *                            ① 내가 지점에 묶인 사람이다(`purchaseScopeWhere` 가 조건을 준다)
 *                            ② 내가 「지점과 공유」를 켠 리스트를 하나라도 갖고 있다
 *                          buyer·viewer 처럼 지점 조건이 없는 사람에게는 `branch` 를 주지 않는다 —
 *                          그 사람에게 지점 확장은 곧 **전 지점 주문 패턴**이 되기 때문이다.
 *
 * 왜 이렇게까지 좁히나: 추천은 "누가 무엇을 얼마나 시키나" 를 요약해 보여 주는 화면이다.
 * 범위를 한 칸만 넓혀도 다른 지점·다른 사람의 주문 패턴이 그대로 드러난다(R-68).
 * 넓히는 판단은 **사용자가 자기 리스트를 공유한다는 행동**으로만 열린다.
 *
 * ★ 권한 판단은 여기 없다. 지점 조건은 `Authz::purchaseScopeWhere` 가 만든 조각을 받아 쓴다.
 */
final class Recommend
{
    /** 세는 기간 (일) — 시안 ③ 「최근 90일」 */
    public const DAYS = 90;

    /** 돌려주는 최대 건수 (지시서: 상위 30) */
    public const LIMIT = 30;

    private const SQL_HEAD = <<<'SQL'
        SELECT l.item_id,
               count(*)                       AS times,
               round(avg(l.qty), 3)           AS avg_qty,
               to_char(max(d.doc_date), 'YYYY-MM-DD') AS last_date,
               i.code, i.name, i.spec, i.unit,
               CAST(i.price_in AS text) AS price_in,
               v.name AS vendor_name
          FROM p_doc_lines l
          JOIN p_docs   d ON d.id = l.doc_id
          JOIN p_items  i ON i.id = l.item_id
          JOIN p_vendors v ON v.id = i.vendor_id
         WHERE d.doc_type = 'pr'
           AND d.status <> 'void'
           AND l.status <> 'void'
           AND i.is_active
           AND d.doc_date > CURRENT_DATE - CAST(:days AS int)
           AND
        SQL;

    private const SQL_TAIL = <<<'SQL'
        GROUP BY l.item_id, i.code, i.name, i.spec, i.unit, i.price_in, v.name
        ORDER BY times DESC, last_date DESC, i.name ASC
        LIMIT :lim
        SQL;

    private const SQL_HAS_SHARED_LIST = <<<'SQL'
        SELECT 1 AS ok
          FROM p_lists
         WHERE owner_id = CAST(:me AS bigint) AND is_shared AND is_active
         LIMIT 1
        SQL;

    /**
     * 이 사람에게 지점 확장을 열어 줄 수 있나.
     *
     * @param array{sql:string,params:array<string,mixed>} $scope purchaseScopeWhere 의 결과
     */
    public static function branchAllowed(Db $db, array $scope, int $userId): bool
    {
        // 지점 조건이 붙지 않는 사람(buyer·viewer·Admin)에게는 열지 않는다.
        if ($scope['params'] === []) {
            return false;
        }
        return is_array($db->run(self::SQL_HAS_SHARED_LIST, [':me' => $userId])->fetch());
    }

    /**
     * 추천 목록.
     *
     * @param array{sql:string,params:array<string,mixed>} $scope `d` 별칭 기준 지점 조건
     * @param bool $withBranch true 면 "내 담당 **또는** 내 지점", false 면 내 담당만
     * @return list<array<string,mixed>>
     */
    public static function topItems(Db $db, array $scope, int $userId, bool $withBranch): array
    {
        $bind = [
            ':me'   => $userId,
            ':days' => self::DAYS,
            ':lim'  => self::LIMIT,
        ];

        // 조각을 잇는 줄에 **값이 한 글자도 없다** — 지점 번호는 $scope['params'] 로만 간다.
        $mine = 'd.manager_id = CAST(:me AS bigint)';
        if ($withBranch) {
            $who  = '(' . $mine . ' OR (' . $scope['sql'] . '))';
            $bind = $bind + $scope['params'];
        } else {
            // 지점 조건도 함께 건다 — 내 담당이면서 내가 볼 수 있는 지점의 줄만.
            $who  = '(' . $mine . ') AND (' . $scope['sql'] . ')';
            $bind = $bind + $scope['params'];
        }

        $rows = $db->run(self::SQL_HEAD . ' ' . $who . ' ' . self::SQL_TAIL, $bind)->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'item_id'     => (int) $row['item_id'],
                'code'        => (string) $row['code'],
                'name'        => (string) $row['name'],
                'spec'        => $row['spec'] === null ? null : (string) $row['spec'],
                'unit'        => $row['unit'] === null ? null : (string) $row['unit'],
                'vendor_name' => (string) $row['vendor_name'],
                'price_in'    => $row['price_in'] === null ? null : (string) $row['price_in'],
                'times'       => (int) $row['times'],
                'avg_qty'     => (string) $row['avg_qty'],
                'last_date'   => (string) $row['last_date'],
            ];
        }
        return $out;
    }
}
