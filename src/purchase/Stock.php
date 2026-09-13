<?php
declare(strict_types=1);

require_once __DIR__ . '/Doc.php';

/**
 * 재고 규칙이 거절한 요청. 메시지는 **사유 코드**(`negative_stock` …)이며 사람이 읽는 문장이
 * 아니다 — 핸들러가 그대로 `Audit::deny()` 의 `reason` 으로 넘기고, 응답은 언제나 공통 문구다.
 */
final class StockError extends RuntimeException
{
}

/**
 * src/purchase/Stock.php — 재고 원장의 규칙 한 곳 (21단계, PRD F-33 · F-34, ARCH 5.18)
 *
 * ★ **`p_stock_moves` 에 INSERT 하는 코드는 이 파일 하나뿐이다.**
 *   `scripts/security_lint.sh` 규칙 (e) 가 입고·이동·사용·실사 핸들러에서 그 문장을 찾으면
 *   반려한다. 이유: 음수 재고 검사·잠금 순서·취소 행 짝맞춤이 한 줄만 빠져도 무너지는데,
 *   쓰는 곳이 여럿이면 "이 경로만 검사를 빠뜨렸다" 가 생긴다. 앱 계정에는 이 표의
 *   UPDATE·DELETE 권한 자체가 없으므로(마이그레이션 GRANT), 잘못 적힌 행도 **지울 수 없다** —
 *   그래서 들어가기 전에 막는 것이 유일한 방어다 (SECURITY R-66).
 *
 * 여기 모으는 것
 *   채번        `GR|MV|IS|ADJ-YYYYMMDD-N` — 20단계 `p_doc_seq` 행을 잠그고 1 올린다
 *   원장 기록   장소 행 잠금 → 합 재계산 → 음수면 거부 → INSERT
 *   취소        원본 행마다 **반대 부호 행 + `reverses_move_id`** (삭제가 아니다)
 *   재고관리 안 하는 품목  입고 확정 시 `+`·`−` 두 행(순변화 0) — 사용 통계에는 잡힌다
 *
 * ★ 이 파일에는 **권한 판단이 없다.** 역할·지점은 `Authz::purchaseAuthorize` 와
 *   `Authz::purchaseScopeWhere` 두 함수만 본다 (ARCH 4.1). 여기 있는 것은 "업무 규칙" 이다.
 *
 * ★ SQL 은 전부 고정 문자열이고 값은 바인딩뿐이다 (PRD S-04). 개수가 변하는 IN 절은
 *   **자리표시자 이름**(`:m0`, `:m1` …)만 이어 붙인다 — 값은 한 글자도 붙지 않는다.
 *
 * ※ 20단계 `Doc.php` 를 고치지 않는 이유: 그 파일은 이번 지시서의 「수정 파일」 밖이다.
 *   그래서 채번·전표 INSERT 처럼 **칸이 늘어난** 것만 여기서 다시 쓰고, 바뀌지 않은 규칙
 *   (수량 비교 `Doc::milli`, 잔량 조각 `Doc::SQL_TAKEN`, 줄 잠금 `Doc::lockLines`,
 *   금액 계산 `Doc::lineAmount`, 참조 확인)은 **그대로 부른다**.
 */
final class Stock
{
    /* ── 전표 종류 (마이그레이션 p_docs_doc_type_check 와 같은 값) ── */
    public const TYPE_GR  = 'gr';    // 입고(구매)
    public const TYPE_MV  = 'mv';    // 이동
    public const TYPE_IS  = 'is';    // 사용(출고)
    public const TYPE_ADJ = 'adj';   // 실사

    /** 번호 접두 (ARCH 5.18 「채번」). 코드에 적힌 고정 문자열만 쓴다. */
    private const PREFIX = [
        self::TYPE_GR  => 'GR',
        self::TYPE_MV  => 'MV',
        self::TYPE_IS  => 'IS',
        self::TYPE_ADJ => 'ADJ',
    ];

    /** 실사 주기 3종 (Q-18③ · 마이그레이션 p_docs_count_cycle_check 와 같은 값) */
    public const CYCLES = ['daily', 'weekly', 'monthly'];

    /** 한 전표에 담을 수 있는 줄 수 상한 (한 요청이 원장을 밀어내지 못하게) */
    public const MAX_LINES = 200;

    /** 실사 스냅샷 한 번에 보여 줄 줄 수 상한 */
    public const MAX_SNAPSHOT = 300;

    /* ══════════════════════════════════════════════════════════
     * 채번 — 20단계와 **같은 표**(p_doc_seq)를 쓴다
     * ══════════════════════════════════════════════════════════ */

    private const SQL_SEQ_BUMP = <<<'SQL'
        INSERT INTO p_doc_seq (doc_type, seq_date, last_no)
        VALUES (:t, CURRENT_DATE, 1)
        ON CONFLICT (doc_type, seq_date)
        DO UPDATE SET last_no = p_doc_seq.last_no + 1
        RETURNING last_no, to_char(seq_date, 'YYYYMMDD') AS ymd
        SQL;

    /**
     * 다음 전표 번호 — `GR-20260914-1`.
     *
     * **트랜잭션 안에서만** 부른다. `ON CONFLICT … DO UPDATE` 가 부딪힌 행에 행 잠금을 걸고
     * 증가시키므로 같은 날 두 요청이 동시에 들어와도 번호가 겹치지 않는다. 마지막 그물은
     * `p_docs_doc_no_uidx` 다 (20단계에서 만든 UNIQUE).
     */
    public static function nextDocNo(Db $db, string $docType): string
    {
        if (!isset(self::PREFIX[$docType])) {
            throw new RuntimeException('알 수 없는 전표 종류입니다');
        }
        $row = $db->run(self::SQL_SEQ_BUMP, [':t' => $docType])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('전표 번호를 발급하지 못했습니다');
        }
        return self::PREFIX[$docType] . '-' . (string) $row['ymd'] . '-' . (string) $row['last_no'];
    }

    /* ══════════════════════════════════════════════════════════
     * 전표 머리·줄 — 21단계에서 늘어난 칸까지 넣는다
     * ══════════════════════════════════════════════════════════ */

    private const SQL_INSERT_DOC = <<<'SQL'
        INSERT INTO p_docs
            (doc_type, doc_no, doc_date, branch_id, vendor_id,
             from_location_id, to_location_id, manager_id, status, memo,
             count_cycle, created_by)
        VALUES
            (:doc_type, :doc_no, CAST(:doc_date AS date), CAST(:branch AS bigint),
             CAST(:vendor AS bigint), CAST(:from_loc AS bigint), CAST(:to_loc AS bigint),
             CAST(:manager AS bigint), :status, :memo, :cycle, CAST(:by AS bigint))
        RETURNING id
        SQL;

    /**
     * 재고 전표 머리 한 건. 값은 전부 바인딩이고, `doc_no` 는 `nextDocNo()` 가 만든 것만 온다.
     *
     * @param array<string,mixed> $f
     */
    public static function insertDoc(Db $db, array $f): int
    {
        $row = $db->run(self::SQL_INSERT_DOC, [
            ':doc_type' => (string) $f['doc_type'],
            ':doc_no'   => (string) $f['doc_no'],
            ':doc_date' => (string) $f['doc_date'],
            ':branch'   => (int) $f['branch_id'],
            ':vendor'   => $f['vendor_id'] ?? null,
            ':from_loc' => $f['from_location_id'] ?? null,
            ':to_loc'   => $f['to_location_id'] ?? null,
            ':manager'  => (int) $f['manager_id'],
            ':status'   => (string) $f['status'],
            ':memo'     => $f['memo'] ?? null,
            ':cycle'    => $f['count_cycle'] ?? null,
            ':by'       => (int) $f['created_by'],
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('전표를 만들지 못했습니다');
        }
        return (int) $row['id'];
    }

    private const SQL_INSERT_LINE = <<<'SQL'
        INSERT INTO p_doc_lines
            (doc_id, line_no, item_id, qty, unit_price, amount, remark, src_line_id,
             status, lot_no, expiry_date, counted_qty)
        VALUES
            (CAST(:doc AS bigint), CAST(:no AS int), CAST(:item AS bigint),
             CAST(:qty AS numeric), CAST(:price AS numeric), CAST(:amount AS numeric),
             :remark, CAST(:src AS bigint), :status, :lot, CAST(:exp AS date),
             CAST(:counted AS numeric))
        RETURNING id
        SQL;

    /**
     * 재고 전표 줄 한 개.
     *
     * ★ `unit_price`·`amount` 는 **호출자가 DB 에서 가져온 값**이다(출처 발주서 줄의 단가,
     *   없으면 `p_items.price_in`). 요청 본문의 금액은 어느 경로로도 여기 오지 않는다
     *   (SECURITY R-66, 린트 규칙 (d)).
     *
     * @param array<string,mixed> $f
     */
    public static function insertLine(Db $db, int $docId, int $lineNo, array $f): int
    {
        $qty = (string) $f['qty'];
        $row = $db->run(self::SQL_INSERT_LINE, [
            ':doc'     => $docId,
            ':no'      => $lineNo,
            ':item'    => (int) $f['item_id'],
            ':qty'     => $qty,
            ':price'   => $f['unit_price'] ?? null,
            ':amount'  => Doc::lineAmount($qty, $f['unit_price'] ?? null),
            ':remark'  => $f['remark'] ?? null,
            ':src'     => $f['src_line_id'] ?? null,
            ':status'  => (string) ($f['status'] ?? Doc::LINE_DONE),
            ':lot'     => $f['lot_no'] ?? null,
            ':exp'     => $f['expiry_date'] ?? null,
            ':counted' => $f['counted_qty'] ?? null,
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('전표 줄을 만들지 못했습니다');
        }
        return (int) $row['id'];
    }

    /* ══════════════════════════════════════════════════════════
     * 원장 — 여기가 `p_stock_moves` 에 INSERT 하는 **유일한 곳**이다
     * ══════════════════════════════════════════════════════════ */

    private const SQL_LOCK_LOCATIONS_HEAD = <<<'SQL'
        SELECT id FROM p_locations WHERE id IN
        SQL;

    private const SQL_LOCK_LOCATIONS_TAIL = <<<'SQL'
        ORDER BY id ASC
        FOR UPDATE
        SQL;

    private const SQL_BALANCE = <<<'SQL'
        SELECT COALESCE(sum(qty_delta), 0)::text AS qty
          FROM p_stock_moves
         WHERE location_id = CAST(:loc AS bigint)
           AND item_id     = CAST(:item AS bigint)
        SQL;

    /** ★ 원장에 행을 넣는 **유일한** 문장. 이 상수는 이 파일 밖에서 쓰이지 않는다. */
    private const SQL_INSERT_MOVE = <<<'SQL'
        INSERT INTO p_stock_moves
            (doc_line_id, location_id, item_id, qty_delta, reverses_move_id, created_by)
        VALUES
            (CAST(:line AS bigint), CAST(:loc AS bigint), CAST(:item AS bigint),
             CAST(:delta AS numeric), CAST(:rev AS bigint), CAST(:by AS bigint))
        RETURNING id
        SQL;

    /**
     * 장소 행을 **잠근다** — 음수 재고를 막는 직렬화의 기준점 (지시서 보충 1).
     *
     * 왜 `p_stock_moves` 를 잠그지 않나: 원장은 **추가만** 되는 표라, 지금 있는 행을 잠가도
     * 그 사이에 새 행이 들어오는 것을 막지 못한다(팬텀). 그래서 "이 장소를 지금 만지는 사람은
     * 한 명" 이라는 약속을 `p_locations` 행 하나로 세운다. 같은 장소의 동시 요청은 줄을 서고,
     * 다른 장소는 그대로 병행한다.
     *
     * `ORDER BY id` 로 언제나 같은 순서로 잠근다 — 이동(두 장소)이 서로 엇갈려 잡아 교착에
     * 빠지는 것을 막는다 (`Doc::lockLines` 와 같은 이유).
     *
     * @param list<int> $locationIds
     */
    public static function lockLocations(Db $db, array $locationIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $locationIds)));
        if ($ids === []) {
            return;
        }
        sort($ids);
        $names = [];
        $bind  = [];
        foreach ($ids as $i => $id) {
            $name        = ':k' . (string) $i;
            $names[]     = $name;
            $bind[$name] = $id;
        }
        // 자리표시자 이름만 이어 붙인다 — 값은 $bind 로만 간다 (PRD S-04).
        $db->run(
            self::SQL_LOCK_LOCATIONS_HEAD . ' (' . implode(', ', $names) . ') '
                . self::SQL_LOCK_LOCATIONS_TAIL,
            $bind
        );
    }

    /**
     * 이 장소·품목의 현재고 — **원장의 합**이다 (ARCH 5.18, 불변 규칙 2).
     * 뷰 `p_stock_balance` 와 같은 값이며, 잠금 뒤 다시 세는 자리에서는 이 문장을 쓴다.
     */
    public static function balance(Db $db, int $locationId, int $itemId): string
    {
        $row = $db->run(self::SQL_BALANCE, [':loc' => $locationId, ':item' => $itemId])->fetch();
        return is_array($row) ? (string) $row['qty'] : '0';
    }

    /**
     * 원장에 행들을 넣는다 — **순서가 곧 안전장치다** (지시서 보충 1).
     *
     *   ① 관련 장소를 전부 `FOR UPDATE` 로 잠근다 (오름차순)
     *   ② (장소, 품목) 짝마다 이 전표가 만드는 **순변화**를 더한다
     *   ③ 순변화가 음수인 짝만 현재고를 **다시 세어** `현재고 + 순변화 < 0` 이면 거부
     *   ④ 그제서야 INSERT
     *
     * ③에서 순변화가 0 이상인 짝을 건너뛰는 이유: 현재고는 언제나 0 이상이라는 성질이
     * 이 함수 하나로 유지되므로(귀납), 늘어나는 짝은 음수가 될 수 없다. 재고관리를 하지
     * 않는 품목의 `+`·`−` 두 행도 순변화가 0 이라 통과한다 — 그것이 의도다(불변 규칙 7).
     *
     * @param list<array{doc_line_id:int,location_id:int,item_id:int,qty_delta:string,reverses?:?int}> $moves
     * @return list<int> 들어간 원장 행 id
     * @throws StockError `negative_stock`
     */
    public static function apply(Db $db, array $moves, int $by): array
    {
        if ($moves === []) {
            return [];
        }

        // ① 장소 잠금
        self::lockLocations($db, array_column($moves, 'location_id'));

        // ② 짝마다 순변화
        $net = [];
        foreach ($moves as $m) {
            $key        = (int) $m['location_id'] . ':' . (int) $m['item_id'];
            $net[$key]  = ($net[$key] ?? 0) + Doc::milli((string) $m['qty_delta']);
        }

        // ③ 줄어드는 짝만 다시 센다
        foreach ($net as $key => $delta) {
            if ($delta >= 0) {
                continue;
            }
            [$loc, $item] = array_map('intval', explode(':', (string) $key));
            $have = Doc::milli(self::balance($db, $loc, $item));
            if ($have + $delta < 0) {
                throw new StockError('negative_stock');
            }
        }

        // ④ INSERT — 원장에 손이 닿는 유일한 자리
        $ids = [];
        foreach ($moves as $m) {
            $row = $db->run(self::SQL_INSERT_MOVE, [
                ':line'  => (int) $m['doc_line_id'],
                ':loc'   => (int) $m['location_id'],
                ':item'  => (int) $m['item_id'],
                ':delta' => (string) $m['qty_delta'],
                ':rev'   => $m['reverses'] ?? null,
                ':by'    => $by,
            ])->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('재고 원장을 기록하지 못했습니다');
            }
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    private const SQL_MOVES_OF_DOC = <<<'SQL'
        SELECT m.id, m.doc_line_id, m.location_id, m.item_id,
               CAST(m.qty_delta AS text) AS qty_delta
          FROM p_stock_moves m
          JOIN p_doc_lines   l ON l.id = m.doc_line_id
         WHERE l.doc_id = CAST(:doc AS bigint)
           AND m.reverses_move_id IS NULL
         ORDER BY m.id ASC
        SQL;

    /**
     * 전표 취소 — **반대 부호 행**을 넣는다 (ARCH 5.18 「원장」, 불변 규칙 1).
     *
     * 원장 행을 지우거나 고치지 않는다(앱 계정에 그 권한이 없다). 되돌릴 행마다
     * `qty_delta` 의 부호를 뒤집은 새 행을 넣고 `reverses_move_id` 로 원본을 가리킨다.
     * 그래서 "무엇이 언제 왜 되돌려졌나" 가 원장만 읽어도 남는다.
     *
     * 이미 되돌린 행은 `reverses_move_id IS NULL` 조건과 부분 UNIQUE 인덱스가 함께 막는다.
     * 되돌리면 재고가 음수가 되는 경우(입고한 물건을 이미 써 버린 뒤의 입고 취소)는
     * `apply()` 가 `negative_stock` 으로 거부한다 — 장부가 음수가 되는 것보다 취소를
     * 막는 편이 낫고, 그때는 실사로 맞춘다.
     *
     * @return array{moves:int,qty:string} 되돌린 행 수와 되돌린 수량의 절대합
     * @throws StockError `negative_stock`
     */
    public static function reverseDoc(Db $db, int $docId, int $by): array
    {
        $rows = $db->run(self::SQL_MOVES_OF_DOC, [':doc' => $docId])->fetchAll();
        if ($rows === []) {
            return ['moves' => 0, 'qty' => '0.000'];
        }

        $back = [];
        $sum  = 0;
        foreach ($rows as $row) {
            $milli = Doc::milli((string) $row['qty_delta']);
            $sum  += abs($milli);
            $back[] = [
                'doc_line_id' => (int) $row['doc_line_id'],
                'location_id' => (int) $row['location_id'],
                'item_id'     => (int) $row['item_id'],
                'qty_delta'   => Doc::qtyText(-$milli),
                'reverses'    => (int) $row['id'],
            ];
        }
        self::apply($db, $back, $by);
        return ['moves' => count($back), 'qty' => Doc::qtyText($sum)];
    }

    /* ══════════════════════════════════════════════════════════
     * 발주서 상태 — 입고가 얼마나 들어왔나로 다시 계산한다
     * ══════════════════════════════════════════════════════════ */

    private const SQL_ORDER_LINES = <<<'SQL'
        SELECT l.id, CAST(l.qty AS text) AS qty, l.status,
        SQL;

    private const SQL_ORDER_LINES_TAIL = <<<'SQL'
        AS taken
          FROM p_doc_lines l
         WHERE l.doc_id = CAST(:doc AS bigint)
        SQL;

    private const SQL_SET_DOC_STATUS = <<<'SQL'
        UPDATE p_docs SET status = :st, updated_at = now()
         WHERE id = CAST(:id AS bigint) AND status <> 'void'
        SQL;

    /**
     * 발주서(PO) 전표의 상태를 **입고 실적으로** 다시 계산한다 (시안 GR 「발주서는 진행중 유지」).
     *
     *   살아 있는 줄이 없다        → 건드리지 않는다
     *   모든 줄이 전량 입고됐다     → done   (완료)
     *   일부라도 들어왔다          → partial(일부 입고)
     *   아직 하나도 안 들어왔다     → in_progress (진행중)
     *
     * `Doc::recalcRequestStatus` 를 쓰지 않는 이유: 그 함수는 **요청 전표**의 어휘라
     * 하나도 담기지 않았을 때 `unconfirmed`(미확인)로 되돌린다. 발주서는 이미 만들어진
     * 문서이므로 그 상태가 없다 — 어휘가 다르면 상태 표가 조용히 어긋난다.
     */
    public static function recalcOrderStatus(Db $db, int $docId): string
    {
        $rows = $db->run(
            self::SQL_ORDER_LINES . ' ' . Doc::SQL_TAKEN . ' ' . self::SQL_ORDER_LINES_TAIL,
            [':doc' => $docId]
        )->fetchAll();

        $alive    = 0;
        $allDone  = true;
        $anyTaken = false;
        foreach ($rows as $row) {
            if ((string) $row['status'] === Doc::LINE_VOID) {
                continue;
            }
            $alive++;
            $taken = Doc::milli((string) $row['taken']);
            $want  = Doc::milli((string) $row['qty']);
            if ($taken > 0) {
                $anyTaken = true;
            }
            if ($taken < $want) {
                $allDone = false;
            }
        }
        if ($alive === 0) {
            return Doc::ST_IN_PROGRESS;
        }
        $status = $allDone ? Doc::ST_DONE : ($anyTaken ? Doc::ST_PARTIAL : Doc::ST_IN_PROGRESS);
        $db->run(self::SQL_SET_DOC_STATUS, [':st' => $status, ':id' => $docId]);
        return $status;
    }

    /* ══════════════════════════════════════════════════════════
     * 공통 검사 — 핸들러 넷이 같은 말을 쓰게
     * ══════════════════════════════════════════════════════════ */

    /**
     * 수량 문자열 형식 — 소수 셋째 자리까지의 **양수**. 부호·지수·공백이 들어올 자리가 없다.
     * 실사의 「센 수량」은 0 일 수 있으므로 `$allowZero` 로 연다.
     */
    public static function isQty(mixed $raw, bool $allowZero = false): bool
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (!is_string($raw) || preg_match('/^[0-9]{1,10}(\.[0-9]{1,3})?$/', $raw) !== 1) {
            return false;
        }
        return $allowZero ? true : Doc::milli($raw) > 0;
    }

    private const SQL_LOCATION_ROW = <<<'SQL'
        SELECT l.id, l.branch_id, l.kind, l.name
          FROM p_locations l
         WHERE l.id = CAST(:id AS bigint) AND l.is_active AND
        SQL;

    /**
     * 장소 한 곳을 **내 범위 안에서만** 읽는다. 범위 밖·중단된 장소면 null 이고,
     * 그때 핸들러가 `not_in_scope` 로 끝낸다 (SECURITY R-65).
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeL `l` 별칭 기준 조건
     * @return array<string,mixed>|null
     */
    public static function location(Db $db, array $scopeL, int $locationId): ?array
    {
        $row = $db->run(
            self::SQL_LOCATION_ROW . ' ' . $scopeL['sql'],
            [':id' => $locationId] + $scopeL['params']
        )->fetch();
        return is_array($row) ? $row : null;
    }

    private const SQL_ITEMS_STOCK_HEAD = <<<'SQL'
        SELECT i.id, i.code, i.name, i.spec, i.unit, i.stock_managed, i.vendor_id,
               CAST(i.price_in AS text) AS price_in
          FROM p_items i
         WHERE i.is_active AND i.id IN
        SQL;

    /**
     * 품목들을 읽는다 (재고 쓰기 경로용 — `stock_managed` 를 함께 본다).
     *
     * 지점 격리는 품목이 아니라 **장소**로 건다: 재고는 장소에 붙어 있고, 장소는 이미
     * `location()` 에서 `purchaseScopeWhere` 를 지났다. 품목 자체는 브랜드 범위 공개다
     * (19단계 품목 찾기 API 와 같은 판단).
     *
     * @param list<int> $itemIds
     * @return array<int,array<string,mixed>> 품목 id → 행 (중단된 품목은 빠진다)
     */
    public static function items(Db $db, array $itemIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        if ($ids === []) {
            return [];
        }
        $names = [];
        $bind  = [];
        foreach ($ids as $i => $id) {
            $name        = ':it' . (string) $i;
            $names[]     = $name;
            $bind[$name] = $id;
        }
        $rows = $db->run(
            self::SQL_ITEMS_STOCK_HEAD . ' (' . implode(', ', $names) . ')',
            $bind
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private const SQL_DOC_ONE = <<<'SQL'
        SELECT d.id, d.doc_no, d.doc_type, d.status, d.branch_id, d.closed_month,
               d.from_location_id, d.to_location_id, d.count_cycle
          FROM p_docs d
         WHERE d.id = CAST(:id AS bigint) AND d.doc_type = :type AND
        SQL;

    /**
     * 재고 전표 하나를 **내 범위 안에서만** 읽는다 (취소 핸들러 넷이 함께 쓴다).
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeD `d` 별칭 기준 조건
     * @return array<string,mixed>|null
     */
    public static function doc(Db $db, array $scopeD, int $docId, string $docType): ?array
    {
        $row = $db->run(
            self::SQL_DOC_ONE . ' ' . $scopeD['sql'],
            [':id' => $docId, ':type' => $docType] + $scopeD['params']
        )->fetch();
        return is_array($row) ? $row : null;
    }

    private const SQL_VOID_LINES = <<<'SQL'
        UPDATE p_doc_lines SET status = 'void', updated_at = now()
         WHERE doc_id = CAST(:doc AS bigint) AND status <> 'void'
        SQL;

    private const SQL_VOID_DOC = <<<'SQL'
        UPDATE p_docs SET status = 'void', updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    /** 전표와 그 줄을 취소 표시한다 — **삭제가 아니다**(앱에 DELETE 권한이 없다). */
    public static function markVoid(Db $db, int $docId): void
    {
        $db->run(self::SQL_VOID_LINES, [':doc' => $docId]);
        $db->run(self::SQL_VOID_DOC, [':id' => $docId]);
    }

    private const SQL_LINES_OF_DOC = <<<'SQL'
        SELECT l.id, l.line_no, l.item_id, CAST(l.qty AS text) AS qty,
               CAST(l.unit_price AS text) AS unit_price, CAST(l.amount AS text) AS amount,
               CAST(l.counted_qty AS text) AS counted_qty,
               l.remark, l.lot_no, CAST(l.expiry_date AS text) AS expiry_date,
               l.src_line_id, l.status,
               i.code, i.name, i.spec, i.unit, i.stock_managed,
               srcd.doc_no AS src_doc_no
          FROM p_doc_lines l
          JOIN p_items i ON i.id = l.item_id
          LEFT JOIN p_doc_lines src  ON src.id = l.src_line_id
          LEFT JOIN p_docs      srcd ON srcd.id = src.doc_id
         WHERE l.doc_id = CAST(:doc AS bigint)
         ORDER BY l.line_no ASC
        SQL;

    /**
     * 재고 전표의 줄 목록 (응답이 쓰는 모양).
     *
     * 이 함수에는 **지점 조건이 없다** — 부르는 핸들러가 이미 전표 머리를
     * `purchaseScopeWhere` 로 걸러 "볼 수 있는 전표" 임을 확인한 뒤에 부른다
     * (`Doc::lines` 와 같은 판단).
     *
     * @return list<array<string,mixed>>
     */
    public static function lines(Db $db, int $docId): array
    {
        $rows = $db->run(self::SQL_LINES_OF_DOC, [':doc' => $docId])->fetchAll();
        $out  = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'line_no'     => (int) $row['line_no'],
                'item_id'     => (int) $row['item_id'],
                'code'        => (string) $row['code'],
                'name'        => (string) $row['name'],
                'spec'        => $row['spec'] === null ? null : (string) $row['spec'],
                'unit'        => $row['unit'] === null ? null : (string) $row['unit'],
                'stock_managed' => $row['stock_managed'] === true,
                'qty'         => (string) $row['qty'],
                'counted_qty' => $row['counted_qty'] === null ? null : (string) $row['counted_qty'],
                'unit_price'  => $row['unit_price'] === null ? null : (string) $row['unit_price'],
                'amount'      => $row['amount'] === null ? null : (string) $row['amount'],
                'lot_no'      => $row['lot_no'] === null ? null : (string) $row['lot_no'],
                'expiry_date' => $row['expiry_date'] === null ? null : (string) $row['expiry_date'],
                'remark'      => $row['remark'] === null ? null : (string) $row['remark'],
                'src_line_id' => $row['src_line_id'] === null ? null : (int) $row['src_line_id'],
                'src_doc_no'  => $row['src_doc_no'] === null ? null : (string) $row['src_doc_no'],
                'status'      => (string) $row['status'],
            ];
        }
        return $out;
    }
}
