<?php
declare(strict_types=1);

require_once __DIR__ . '/Doc.php';

/**
 * src/purchase/Suggest.php — 발주 제안 계산 **한 곳** (23단계, PRD F-36, 시안 ⑩ Status)
 *
 * 제안 식 (시안 ⑩ 아래 줄과 **같은 문장**, PRD F-36)
 *
 *     제안 = ⌈(인가량 + 안전재고 − 현재고 − 미입고 + 조달기간 예상 사용) ÷ 최소구매단위⌉
 *            × 최소구매단위
 *
 *   인가량        `p_par_levels` 중 오늘 유효한 마지막 것 (없으면 0)
 *   안전재고      `p_items.safety_qty`
 *   현재고        원장의 합 (뷰 `p_stock_balance`) — 컬럼이 아니다 (ARCH 5.18)
 *   미입고        뷰 `p_backorder` 에서 **그 장소로 올** 잔량의 합
 *   조달기간 예상  주평균 사용 × `p_items.lead_days` ÷ 7
 *   최소구매단위   `p_items.min_order_qty` (0 이하로 적힌 자료가 있어도 1 로 본다)
 *
 *   괄호 안이 0 이하면 **제안 없음(0)** 이다. 음수를 올림해 억지로 한 단위를 만들지 않는다.
 *
 * ★ 계산은 **여기 SQL 한 곳**에서만 한다 (지시서 23단계 「제안 계산은 서버만」).
 *   화면(`purchase_stock.js`)은 서버가 준 값과 근거를 **표시만** 한다 — 브라우저에서 다시
 *   계산하면 두 곳이 갈라지고, 갈라진 날 사람은 화면 쪽을 믿는다.
 *   PHP 로 한 번 더 더하지 않는 것도 같은 이유다: 더하는 곳이 둘이면 언젠가 어긋난다.
 *   숫자는 전부 `numeric` 이라 반올림이 자리마다 정해져 있다(부동소수점 오차가 없다).
 *
 * ★ 이 파일에는 **권한 판단이 없다.** 범위 조각(`Authz::purchaseScopeWhere($user,'l')`)은
 *   호출자가 받아서 넘긴다 — `Usage.php`(22단계)와 같은 모양이다 (ARCH 4.1, SECURITY R-65).
 *
 * ★ SQL 은 전부 고정 문자열이고 값은 바인딩뿐이다 (PRD S-04). 조각을 잇는 줄에는
 *   SQL 낱말도 값도 없다 — 낱말은 아래 상수 안에만 있다.
 */
final class Suggest
{
    /** 한 쪽에 담는 행 수 (장소 × 품목) */
    public const PER_PAGE = 50;

    /** 한 번에 발주요청으로 옮길 수 있는 제안 줄 수 — 전표 줄 상한과 같다 */
    public const MAX_APPLY_LINES = Doc::MAX_LINES;

    /** 주평균 사용을 세는 창 (일). 화면의 「주평균 사용」과 같은 값이어야 한다 */
    public const USAGE_DAYS = 28;

    /** 주평균 사용 근거 — 값이 있을 때 */
    public const BASIS_WEEKLY = 'weekly_avg_4w';
    /** 주평균 사용 근거 — 원장에 쓴 기록이 없어 0 으로 둔 때 (지시서 보충 1) */
    public const BASIS_NONE   = 'none';

    /* ══════════════════════════════════════════════════════════
     * SQL — 조각은 전부 고정 문자열, 값은 바인딩
     * ══════════════════════════════════════════════════════════ */

    /**
     * 보이는 장소. 지점 격리는 여기 한 줄(`$scopeL['sql']`)에서 끝난다 — 재고도 인가량도
     * 장소에 붙어 있으므로 장소를 좁히면 전부 좁는다 (SECURITY R-65).
     */
    private const SQL_LOC = <<<'SQL'
        WITH loc AS (
            SELECT l.id, l.name, l.kind, l.branch_id
              FROM p_locations l
             WHERE
        SQL;

    /**
     * 「셀 것」 = 인가량이 있는 (장소×품목) ∪ 재고가 있는 (장소×품목).
     * 둘 중 하나만 있어도 한 줄이다 — 인가량만 있고 재고가 0 인 줄이 곧 부족이고,
     * 재고만 있고 인가량이 없는 줄은 "왜 여기 있나" 를 물어야 하는 줄이다.
     */
    private const SQL_BASE = <<<'SQL'
        ),
        par AS (
            SELECT DISTINCT ON (p.location_id, p.item_id) p.location_id, p.item_id, p.qty
              FROM p_par_levels p
              JOIN loc ON loc.id = p.location_id
             WHERE p.is_active AND p.effective_from <= CURRENT_DATE
             ORDER BY p.location_id, p.item_id, p.effective_from DESC, p.id DESC
        ),
        bal AS (
            SELECT b.location_id, b.item_id, b.qty
              FROM p_stock_balance b
              JOIN loc ON loc.id = b.location_id
        ),
        keys AS (
            SELECT location_id, item_id FROM par
            UNION
            SELECT location_id, item_id FROM bal
        ),
        base AS (
            SELECT k.location_id, k.item_id,
                   loc.name AS location_name, loc.branch_id, loc.kind,
                   i.code, i.name AS item_name, i.spec, i.unit, i.stock_managed,
                   i.cat1_id, i.safety_qty, i.lead_days,
                   GREATEST(i.min_order_qty, 1) AS min_order_qty,
                   i.vendor_id, v.name AS vendor_name,
                   COALESCE(bal.qty, 0) AS on_hand,
                   par.qty               AS par_qty,
                   COALESCE(bal.qty, 0) - COALESCE(par.qty, 0) AS gap,
                   COALESCE((SELECT sum(bo.remaining_qty) FROM p_backorder bo
                              WHERE bo.to_location_id = k.location_id AND bo.item_id = k.item_id), 0)
                     AS incoming,
                   COALESCE((SELECT sum(CASE
                                 WHEN dd.doc_type = 'is' THEN -m.qty_delta
                                 WHEN dd.doc_type = 'gr' AND NOT i.stock_managed AND m.qty_delta > 0
                                      THEN m.qty_delta
                                 ELSE 0 END)
                               FROM p_stock_moves m
                               JOIN p_doc_lines ml ON ml.id = m.doc_line_id
                               JOIN p_docs      dd ON dd.id = ml.doc_id
                              WHERE m.location_id = k.location_id AND m.item_id = k.item_id
                                AND m.moved_at > now() - interval '28 days'), 0) / 4
                     AS weekly_use
              FROM keys k
              JOIN loc       ON loc.id = k.location_id
              JOIN p_items i ON i.id = k.item_id
              LEFT JOIN p_vendors v ON v.id = i.vendor_id
              LEFT JOIN par ON par.location_id = k.location_id AND par.item_id = k.item_id
              LEFT JOIN bal ON bal.location_id = k.location_id AND bal.item_id = k.item_id
             WHERE
        SQL;

    /**
     * 제안 — **식이 적힌 유일한 자리**다 (PRD F-36).
     * `need` 가 0 이하면 0 이고, 그 밖에는 최소구매단위로 올림한다.
     *
     * 계산(`calc`)과 거르기(`rows`)를 **두 층으로** 나눈 이유: SQL 은 같은 SELECT 의
     * WHERE 에서 그 SELECT 가 만든 별칭(`suggest_qty`)을 볼 수 없다. 한 층에 두면
     * `column "suggest_qty" does not exist` 다 — 2026-09-13 구현 중 실측.
     */
    private const SQL_CALC = <<<'SQL'
        ),
        needs AS (
            SELECT base.*,
                   round(base.weekly_use * base.lead_days / 7.0, 3) AS lead_use,
                   round(COALESCE(base.par_qty, 0) + base.safety_qty
                         - base.on_hand - base.incoming
                         + base.weekly_use * base.lead_days / 7.0, 3) AS need_qty
              FROM base
        ),
        calc AS (
            SELECT needs.*,
                   CASE WHEN needs.need_qty <= 0 THEN 0
                        ELSE ceil(needs.need_qty / needs.min_order_qty) * needs.min_order_qty
                   END AS suggest_qty
              FROM needs
        ),
        rows AS (
            SELECT calc.*
              FROM calc
             WHERE
        SQL;

    /** 건수 꼬리 — 값이 한 글자도 없는 고정 문자열이다 */
    private const SQL_COUNT = <<<'SQL'
         SELECT count(*) AS n FROM rows
        SQL;

    /** 한 쪽 꼬리 — 값이 한 글자도 없는 고정 문자열이다 */
    private const SQL_PAGE = <<<'SQL'
         SELECT location_id, location_name, branch_id, kind, item_id, code, item_name, spec, unit,
                stock_managed, cat1_id, safety_qty, lead_days, min_order_qty,
                vendor_id, vendor_name,
                CAST(on_hand AS text)              AS on_hand,
                par_qty,
                CAST(gap AS text)                  AS gap,
                CAST(incoming AS text)             AS incoming,
                CAST(round(weekly_use, 2) AS text) AS weekly_use,
                CAST(lead_use AS text)             AS lead_use,
                CAST(need_qty AS text)             AS need_qty,
                CAST(round(suggest_qty, 3) AS text) AS suggest_qty
           FROM rows
          ORDER BY location_name ASC, item_name ASC, item_id ASC
          LIMIT :lim OFFSET :off
        SQL;

    /* ══════════════════════════════════════════════════════════
     * 조회
     * ══════════════════════════════════════════════════════════ */

    /**
     * (장소 × 품목) 한 쪽 + 건수. 값은 전부 서버 계산이고, 응답에는 **근거 값도 함께** 실린다
     * (화면이 계산하지 않게 하려면 근거까지 줘야 한다 — 지시서 23단계).
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeL `l` 별칭 기준 지점 조건
     * @param array{location_id:int,branch_id:int,cat1:int,q:string,gap_short:bool,
     *              only_short:bool,page:int,per_page:int} $f
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public static function rows(Db $db, array $scopeL, array $f): array
    {
        $locWhere = ['l.is_active', $scopeL['sql']];
        $bind     = $scopeL['params'];

        if ($f['location_id'] > 0) {
            $locWhere[]   = 'l.id = CAST(:loc AS bigint)';
            $bind[':loc'] = $f['location_id'];
        }
        if ($f['branch_id'] > 0) {
            $locWhere[]      = 'l.branch_id = CAST(:branch AS bigint)';
            $bind[':branch'] = $f['branch_id'];
        }

        $itemWhere = ['i.is_active'];
        if ($f['cat1'] > 0) {
            $itemWhere[]   = 'i.cat1_id = CAST(:cat1 AS bigint)';
            $bind[':cat1'] = $f['cat1'];
        }
        if ($f['q'] !== '') {
            $bind[':q']  = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $f['q']) . '%';
            $itemWhere[] = "(i.name ILIKE :q ESCAPE '#' OR i.code ILIKE :q ESCAPE '#')";
        }
        if ($f['gap_short']) {
            // 시안 ⑩ 의 「부족만」 — 과부족(현재고 − 인가량)이 음수인 줄
            $itemWhere[] = 'COALESCE(bal.qty,0) - COALESCE(par.qty,0) < 0';
        }

        // 제안이 있는 줄만 (시안 ⑩ 의 「제안만」). 제안은 위 CTE 가 이미 계산했다.
        $sugWhere = $f['only_short'] ? 'suggest_qty > 0' : '1=1';

        // 조각을 잇는 줄에는 SQL 낱말도 값도 없다 — 낱말은 위 상수 안에만 있다 (PRD S-04).
        $head = self::SQL_LOC . ' ' . implode(' AND ', $locWhere)
              . ' ' . self::SQL_BASE . ' ' . implode(' AND ', $itemWhere)
              . ' ' . self::SQL_CALC . ' ' . $sugWhere
              . ' )';

        $total    = 0;
        $countRow = $db->run($head . self::SQL_COUNT, $bind)->fetch();
        if (is_array($countRow)) {
            $total = (int) $countRow['n'];
        }

        $perPage      = $f['per_page'] > 0 ? $f['per_page'] : self::PER_PAGE;
        $bind[':lim'] = $perPage;
        $bind[':off'] = ($f['page'] - 1) * $perPage;

        $items = [];
        foreach ($db->run($head . self::SQL_PAGE, $bind)->fetchAll() as $row) {
            $items[] = self::shape($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * DB 행 → 응답 한 줄. 근거 값을 **전부** 싣는다 — 화면은 이 값을 그대로 보여 주기만 한다.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function shape(array $row): array
    {
        $weekly = (string) $row['weekly_use'];

        return [
            'location_id'   => (int) $row['location_id'],
            'location_name' => (string) $row['location_name'],
            'branch_id'     => (int) $row['branch_id'],
            'kind'          => (string) $row['kind'],
            'item_id'       => (int) $row['item_id'],
            'code'          => (string) $row['code'],
            'name'          => (string) $row['item_name'],
            'spec'          => $row['spec'] === null ? null : (string) $row['spec'],
            'unit'          => $row['unit'] === null ? null : (string) $row['unit'],
            'stock_managed' => $row['stock_managed'] === true,
            'vendor_id'     => $row['vendor_id'] === null ? null : (int) $row['vendor_id'],
            'vendor_name'   => $row['vendor_name'] === null ? null : (string) $row['vendor_name'],
            'on_hand'       => (string) $row['on_hand'],
            'par_qty'       => $row['par_qty'] === null ? null : (int) $row['par_qty'],
            'gap'           => (string) $row['gap'],
            'incoming'      => (string) $row['incoming'],
            'weekly_use'    => $weekly,
            'safety_qty'    => (int) $row['safety_qty'],
            'lead_days'     => (int) $row['lead_days'],
            'min_order_qty' => (int) $row['min_order_qty'],
            // ── 제안과 그 근거 (PRD F-36) ──────────────────────────
            'suggest_qty'   => (string) $row['suggest_qty'],
            'need_qty'      => (string) $row['need_qty'],
            'lead_use'      => (string) $row['lead_use'],
            // 원장에 쓴 기록이 없으면 조달기간 예상 사용은 0 이다 — 그것을 숨기지 않고 적는다
            // (지시서 보충 1). 0 인 이유가 "안 썼다" 인지 "자료가 없다" 인지는 여기서만 갈린다.
            'usage_basis'   => ((float) $weekly) > 0 ? self::BASIS_WEEKLY : self::BASIS_NONE,
        ];
    }

    /* ══════════════════════════════════════════════════════════
     * 제안 → 발주요청
     * ══════════════════════════════════════════════════════════ */

    /**
     * 최소구매단위의 배수인가 — 담당자가 제안값을 고쳤을 때 서버가 다시 본다 (완료 기준 T-48 b).
     *
     * 수량은 소수 셋째 자리까지라 `Doc::milli` 로 정수화해서 나눈다. 최소단위는 낱개 정수다.
     */
    public static function isMultiple(string $qty, int $minOrderQty): bool
    {
        $unit = $minOrderQty > 0 ? $minOrderQty : 1;
        return Doc::milli($qty) % ($unit * 1000) === 0;
    }

    /** 제안으로 만든 발주요청의 메모 (지시서 보충 2) */
    public static function memo(string $date): string
    {
        return '발주 제안 ' . $date;
    }
}
