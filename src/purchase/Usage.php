<?php
declare(strict_types=1);

require_once __DIR__ . '/Doc.php';

/**
 * src/purchase/Usage.php — 주간 사용량 집계 한 곳 (22단계, PRD F-34, 시안 Weekly)
 *
 * 무엇을 세나 (시안 「집계 규칙」과 같은 말)
 *   사용 = **사용(IS) 전표가 원장에 남긴 음수 행** + **재고관리를 하지 않는 품목의
 *   입고(GR) 양수 행**. 재고관리를 하지 않는 품목(기공료 등)은 입고 확정 때 `+`·`−` 두 행이
 *   같이 들어가 현재고는 늘지 않지만(ARCH 5.18 「재고관리 안 하는 품목」), **쓴 것은 맞다**.
 *   이동(MV)은 사용이 아니다 — 장소만 바뀐다. 실사(ADJ) 차이도 사용이 아니다.
 *   이것은 `purchase_stock_status.php` 의 「주평균 사용」과 **같은 계산식**이다.
 *
 * 왜 원장을 세나 (전표 줄이 아니라)
 *   취소가 반대 부호 행으로 들어오므로 원장만 더하면 취소가 저절로 빠진다. 전표 줄을 세면
 *   `status='void'` 를 빼는 조건을 곳곳에 적어야 하고, 한 곳만 빠뜨려도 숫자가 어긋난다.
 *
 * 지점 격리 (SECURITY R-65)
 *   `Authz::purchaseScopeWhere($user, 'l')` 를 **장소**에 건다 — 재고는 장소에 붙어 있고,
 *   장소는 지점에 붙어 있다. requester 는 자기 지점 장소만 보이므로 표의 **열(지점)도**
 *   자기 지점 하나만 나온다. `?branch=` 에 남의 지점을 적어도 조건은 더 좁아질 뿐이다.
 *
 * ★ 이 파일에는 **권한 판단이 없다.** 범위 조각은 호출자가 `Authz` 에서 받아 넘긴다.
 * ★ SQL 은 고정 문자열이고 값은 바인딩뿐이다 (PRD S-04). 바인딩할 수 없는 자리(그룹 층의
 *   컬럼 이름)는 **허용 목록**으로 고른다 (ARCH 13장 체크리스트).
 */
final class Usage
{
    /** 한 응답에 담을 수 있는 주 수 (ISO 주, 지시서 보충 5) */
    public const MAX_WEEKS = 26;

    /** 한 응답에 담을 수 있는 행 수 (그룹 층 × 지점) */
    public const MAX_ROWS = 500;

    /**
     * 고를 수 있는 그룹 층 — 바인딩할 수 없는 **컬럼 이름**이 걸린 자리라 허용 목록으로만 고른다.
     * 요청 값은 "이 목록에 있는 값인가" 를 묻는 데만 쓰이고, SQL 은 아래 KEYED_SQL 의
     * 고정 문자열에서만 온다 (ARCH 13장 체크리스트).
     *
     * @var list<string>
     */
    public const GROUPS = ['cat1', 'cat2', 'cat3', 'item'];

    /** 그룹 층의 한국어 이름 (화면 표시용) */
    public const GROUP_LABELS = [
        'cat1' => '대분류',
        'cat2' => '중분류',
        'cat3' => '소분류',
        'item' => '품목',
    ];

    /**
     * 그룹 층마다의 **완성된 SQL 조각**. 컬럼 이름은 바인딩할 수 없으므로 조각을 통째로
     * 표에 적어 둔다 — 조각 안에 값이 한 글자도 없고, 요청 값은 "이 표에 있는 키인가" 를
     * 묻는 데만 쓰인다 (ARCH 13장 「ORDER BY·컬럼명은 허용 목록으로」).
     *
     * @var array<string,string>
     */
    private const KEYED_SQL = [
        'cat1' => <<<'SQL'
            ), keyed AS (
                SELECT branch_id, week, qty, cat1_id AS grp_id,
                       COALESCE((SELECT c.name FROM p_categories c WHERE c.id = used.cat1_id), :nogrp)
                         AS grp_name
                  FROM used
            SQL,
        'cat2' => <<<'SQL'
            ), keyed AS (
                SELECT branch_id, week, qty, cat2_id AS grp_id,
                       COALESCE((SELECT c.name FROM p_categories c WHERE c.id = used.cat2_id), :nogrp)
                         AS grp_name
                  FROM used
            SQL,
        'cat3' => <<<'SQL'
            ), keyed AS (
                SELECT branch_id, week, qty, cat3_id AS grp_id,
                       COALESCE((SELECT c.name FROM p_categories c WHERE c.id = used.cat3_id), :nogrp)
                         AS grp_name
                  FROM used
            SQL,
        'item' => <<<'SQL'
            ), keyed AS (
                SELECT branch_id, week, qty, item_id AS grp_id,
                       (SELECT ii.name FROM p_items ii WHERE ii.id = used.item_id) AS grp_name
                  FROM used
            SQL,
    ];

    /** 분류가 비어 있는 품목이 모이는 자리의 이름 (지어낸 값이 아니라 "없음" 이라고 적는다) */
    public const NO_GROUP = '(분류 없음)';

    /* ══════════════════════════════════════════════════════════
     * SQL — 조각은 전부 고정 문자열, 값은 바인딩
     * ══════════════════════════════════════════════════════════ */

    private const SQL_HEAD = <<<'SQL'
        WITH loc AS (
            SELECT l.id, l.branch_id
              FROM p_locations l
             WHERE
        SQL;

    /**
     * 원장 → 사용량. `to_char(…, 'IYYY-"W"IW')` 가 ISO 주(월요일 시작)다.
     * 취소 행은 반대 부호로 들어오므로 그대로 더하면 상쇄된다 — 따로 뺄 조건이 없다.
     */
    private const SQL_MOVES = <<<'SQL'
        ),
        used AS (
            SELECT loc.branch_id                            AS branch_id,
                   to_char(m.moved_at, 'IYYY-"W"IW')        AS week,
                   i.id                                     AS item_id,
                   i.cat1_id, i.cat2_id, i.cat3_id,
                   CASE
                     WHEN d.doc_type = 'is' AND m.qty_delta < 0 THEN -m.qty_delta
                     WHEN d.doc_type = 'gr' AND NOT i.stock_managed AND m.qty_delta > 0
                          THEN m.qty_delta
                     ELSE 0
                   END                                      AS qty
              FROM p_stock_moves m
              JOIN loc          ON loc.id = m.location_id
              JOIN p_doc_lines l ON l.id  = m.doc_line_id
              JOIN p_docs      d ON d.id  = l.doc_id
              JOIN p_items     i ON i.id  = m.item_id
             WHERE m.moved_at >= CAST(:from AS date)
               AND m.moved_at <  CAST(:to AS date) + 1
               AND d.doc_type IN ('is', 'gr')
        SQL;

    private const SQL_TAIL = <<<'SQL'
        )
        SELECT branch_id, week, grp_id, grp_name, CAST(sum(qty) AS text) AS qty
          FROM keyed
         WHERE qty <> 0
         GROUP BY branch_id, week, grp_id, grp_name
         ORDER BY grp_name ASC, branch_id ASC, week ASC
         LIMIT :lim
        SQL;

    /**
     * 주간 사용량 — 대/중/소 분류(또는 품목) × 지점 × ISO 주.
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeL `l` 별칭 기준 지점 조건
     * @param array{from:string,to:string,group:string,branch_id:?int,cat1:?int,cat2:?int} $f
     * @return array{cells: list<array<string,mixed>>, weeks: list<string>, truncated: bool}
     */
    public static function weekly(Db $db, array $scopeL, array $f): array
    {
        $group = isset(self::KEYED_SQL[$f['group']]) ? (string) $f['group'] : 'cat1';

        $locWhere = ['l.is_active', $scopeL['sql']];
        $bind     = $scopeL['params'];
        $bind[':from'] = $f['from'];
        $bind[':to']   = $f['to'];

        if (($f['branch_id'] ?? null) !== null) {
            $locWhere[]      = 'l.branch_id = CAST(:branch AS bigint)';
            $bind[':branch'] = (int) $f['branch_id'];
        }

        $moveWhere = [];
        if (($f['cat1'] ?? null) !== null) {
            $moveWhere[]   = 'i.cat1_id = CAST(:cat1 AS bigint)';
            $bind[':cat1'] = (int) $f['cat1'];
        }
        if (($f['cat2'] ?? null) !== null) {
            $moveWhere[]   = 'i.cat2_id = CAST(:cat2 AS bigint)';
            $bind[':cat2'] = (int) $f['cat2'];
        }

        /*
         * 그룹 층의 id·이름 조각. 고른 것은 **표의 키**이고, 조각 자체는 코드에 적힌
         * 고정 문자열이다 — 요청 값이 SQL 로 들어가는 자리가 없다.
         */
        $keyed = self::KEYED_SQL[$group];

        if ($group !== 'item') {
            $bind[':nogrp'] = self::NO_GROUP;
        }
        $bind[':lim'] = self::MAX_ROWS + 1;

        $sql = self::SQL_HEAD . ' ' . implode(' AND ', $locWhere) . ' '
             . self::SQL_MOVES
             . ($moveWhere === [] ? '' : ' AND ' . implode(' AND ', $moveWhere))
             . ' ' . $keyed . ' '
             . self::SQL_TAIL;

        $rows = $db->run($sql, $bind)->fetchAll();

        $truncated = count($rows) > self::MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_ROWS);
        }

        $weeks = [];
        $cells = [];
        foreach ($rows as $row) {
            $week          = (string) $row['week'];
            $weeks[$week]  = true;
            $cells[]       = [
                'branch_id' => (int) $row['branch_id'],
                'week'      => $week,
                'group_id'  => $row['grp_id'] === null ? null : (int) $row['grp_id'],
                'name'      => (string) $row['grp_name'],
                'qty'       => (string) $row['qty'],
            ];
        }
        $weekList = array_keys($weeks);
        sort($weekList);
        if (count($weekList) > self::MAX_WEEKS) {
            // 상한을 넘으면 **최근** 주만 남기고, 남지 않은 주의 칸도 함께 뺀다.
            $weekList = array_slice($weekList, -self::MAX_WEEKS);
            $keep     = array_fill_keys($weekList, true);
            $cells    = array_values(array_filter($cells, static function (array $c) use ($keep): bool {
                return isset($keep[$c['week']]);
            }));
            $truncated = true;
        }

        return ['cells' => $cells, 'weeks' => $weekList, 'truncated' => $truncated];
    }
}
