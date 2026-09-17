<?php
declare(strict_types=1);

/**
 * src/purchase/Doc.php — 구매 전표의 규칙 한 곳 (20단계, PRD F-30~F-32, ARCH 5.18)
 *
 * 핸들러마다 흩어지면 어긋나는 것들을 여기 모은다.
 *
 *   채번        `PR|PO-YYYYMMDD-N` — `p_doc_seq` 행 하나를 잠그고 1 올린다
 *   상태 전이   전표·줄의 상태 값과 "이 상태에서 무엇을 할 수 있나"
 *   잔량        요청 줄 qty − Σ(자식 줄 qty, 취소 제외) — **컬럼이 아니라 계산값**
 *   금액        qty × `p_items.price_in` — **서버만** 계산한다 (SECURITY R-66)
 *   발주서 머리 `header_json` 을 서버가 채우고, buyer 는 허용 키만 고친다 (Q-18②)
 *
 * ★ 이 파일에는 **권한 판단이 없다.** 역할·지점은 `Authz::purchaseAuthorize` 와
 *   `Authz::purchaseScopeWhere` 두 함수만 본다 (ARCH 4.1). 여기 있는 것은 "업무 규칙" 이다.
 *
 * ★ SQL 은 전부 고정 문자열이고 값은 바인딩뿐이다 (PRD S-04). 줄 목록처럼 개수가 변하는
 *   IN 절은 **자리표시자 이름**(`:l0`, `:l1` …)을 만들어 붙인다 — 값은 한 글자도 붙지 않는다.
 */
final class Doc
{
    /* ── 전표 종류 ─────────────────────────────────────────── */
    public const TYPE_PR = 'pr';
    public const TYPE_PO = 'po';
    /*
     * 22단계 채번 통합 — 21단계 재고 전표 넷도 여기로 들어온다 (지시서 07 「채번 통합」).
     * 21단계에는 `Stock.php` 가 같은 표(`p_doc_seq`)를 두 번째 코드로 증가시키고 있었다.
     * 같은 규칙이 두 파일에 적히면 한쪽만 고쳐지는 날이 온다 — 값은 `Stock::TYPE_*` 가
     * 그대로 쓰이도록 남기고(핸들러는 한 글자도 바뀌지 않는다), **문장은 여기 하나**다.
     */
    public const TYPE_GR  = 'gr';
    public const TYPE_MV  = 'mv';
    public const TYPE_IS  = 'is';
    public const TYPE_ADJ = 'adj';

    /** 번호 접두 (ARCH 5.18 「채번」). 코드에 적힌 고정 문자열만 쓴다. */
    private const PREFIX = [
        self::TYPE_PR  => 'PR',
        self::TYPE_PO  => 'PO',
        self::TYPE_GR  => 'GR',
        self::TYPE_MV  => 'MV',
        self::TYPE_IS  => 'IS',
        self::TYPE_ADJ => 'ADJ',
    ];

    /* ── 전표 상태 (PRD F-30 「미확인→진행중→일부 발주→완료」) ── */
    public const ST_UNCONFIRMED = 'unconfirmed';   // 미확인 — 요청자가 아직 고칠 수 있다
    public const ST_IN_PROGRESS = 'in_progress';   // 진행중 — 발주서의 기본 상태
    public const ST_PARTIAL     = 'partial';       // 일부 발주
    public const ST_DONE        = 'done';          // 완료(전량 발주)
    public const ST_VOID        = 'void';          // 취소 — **삭제가 아니다**

    /* ── 줄 상태 ───────────────────────────────────────────── */
    public const LINE_UNCONFIRMED = 'unconfirmed'; // 아직 아무도 담지 않았다
    public const LINE_ORDERED     = 'ordered';     // 일부가 발주서에 담겼다
    public const LINE_DONE        = 'done';        // 전량이 담겼다(잠김)
    public const LINE_VOID        = 'void';        // 취소

    /**
     * 발주서 머리에서 **buyer 가 고칠 수 있는 키** (Q-18② 「발행 전 수정 가능」).
     * 목록 밖 키가 하나라도 오면 요청 전체를 거부한다 (SECURITY R-07 과 같은 방식).
     * 금액 세 칸(공급가액·부가세·합계)은 여기 없다 — **매 응답 서버가 다시 계산한다**.
     *
     * @var list<string>
     */
    public const HEADER_EDITABLE = ['receiver', 'cc', 'tel', 'delivery_location', 'due_date', 'memo'];

    /** 부가세율 — 10%. 화면·인쇄가 같은 값을 쓰도록 여기 한 곳에만 둔다. */
    public const VAT_RATE = 0.1;

    /** 한 전표에 담을 수 있는 줄 수 상한 (한 요청이 표를 밀어내지 못하게) */
    public const MAX_LINES = 200;

    /* ══════════════════════════════════════════════════════════
     * 채번
     * ══════════════════════════════════════════════════════════ */

    private const SQL_SEQ_BUMP = <<<'SQL'
        INSERT INTO p_doc_seq (doc_type, seq_date, last_no)
        VALUES (:t, CURRENT_DATE, 1)
        ON CONFLICT (doc_type, seq_date)
        DO UPDATE SET last_no = p_doc_seq.last_no + 1
        RETURNING last_no, to_char(seq_date, 'YYYYMMDD') AS ymd
        SQL;

    /**
     * 다음 전표 번호 — `PR-20260914-1` (ARCH 5.18 「채번」).
     *
     * **트랜잭션 안에서만** 부른다. `ON CONFLICT … DO UPDATE` 는 부딪힌 행에
     * `SELECT … FOR UPDATE` 와 같은 행 잠금을 걸고 증가시키므로, 같은 날 두 요청이
     * 동시에 들어와도 번호가 겹치지 않는다. 먼저 잡은 쪽이 커밋할 때까지 뒤의 요청이
     * 기다린다. 「행이 아직 없는 첫 번째 전표」까지 한 문장으로 처리되는 것이
     * `SELECT FOR UPDATE` 를 먼저 하는 방식보다 안전하다(없는 행은 잠글 수 없다).
     *
     * 마지막 그물은 `p_docs_doc_no_uidx` 다 — 어떤 경로로도 같은 번호가 두 번 들어갈 수 없다.
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
     * 잔량 — 요청 줄 qty − Σ(자식 줄 qty). 취소된 자식은 세지 않는다.
     * ══════════════════════════════════════════════════════════ */

    /**
     * 잔량 계산에 쓰는 **고정 SQL 조각**. 값이 한 글자도 없고, 바깥 SQL 의 별칭 `l` 을 쓴다.
     * 취소된 자식 줄(`status='void'`)과 취소된 전표의 줄은 세지 않는다 —
     * 발주서를 취소하면 요청의 잔량이 **되돌아와야** 한다.
     */
    public const SQL_TAKEN = <<<'SQL'
        COALESCE((SELECT sum(c.qty)
                    FROM p_doc_lines c
                    JOIN p_docs      cd ON cd.id = c.doc_id
                   WHERE c.src_line_id = l.id
                     AND c.status <> 'void'
                     AND cd.status <> 'void'), 0)
        SQL;

    private const SQL_LOCK_LINES = <<<'SQL'
        SELECT l.id, l.doc_id, l.item_id, l.qty, l.status, l.locked_at
          FROM p_doc_lines l
         WHERE l.id IN
        SQL;

    private const SQL_LOCK_TAIL = <<<'SQL'
        ORDER BY l.id ASC
        FOR UPDATE
        SQL;

    /**
     * 요청 줄들을 **잠그고** 읽는다 (ARCH 5.18 「연결」: 담기·입고 확정은 FOR UPDATE 뒤 판정).
     *
     * `ORDER BY l.id` 로 항상 같은 순서로 잠근다 — 두 요청이 서로의 줄을 엇갈려 잡아
     * 교착(deadlock)에 빠지는 것을 막는다.
     *
     * @param list<int> $lineIds
     * @return array<int,array<string,mixed>> 줄 id → 행
     */
    public static function lockLines(Db $db, array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }
        $names = [];
        $bind  = [];
        foreach (array_values($lineIds) as $i => $id) {
            $name        = ':l' . (string) $i;
            $names[]     = $name;
            $bind[$name] = (int) $id;
        }
        // 자리표시자 이름만 이어 붙인다 — 값은 $bind 로만 간다 (PRD S-04).
        $inList = '(' . implode(', ', $names) . ') ';
        $rows   = $db->run(self::SQL_LOCK_LINES . ' ' . $inList . self::SQL_LOCK_TAIL, $bind)->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private const SQL_TAKEN_ONE = <<<'SQL'
        SELECT
        SQL;

    private const SQL_TAKEN_ONE_TAIL = <<<'SQL'
        AS taken
          FROM p_doc_lines l
         WHERE l.id = CAST(:id AS bigint)
        SQL;

    /**
     * 이 요청 줄이 지금까지 발주서에 담긴 수량 (취소 제외).
     * `lockLines()` 로 줄을 잠근 **뒤** 부른다 — 그래야 판정과 갱신 사이에 값이 바뀌지 않는다.
     */
    public static function takenQty(Db $db, int $lineId): string
    {
        $row = $db->run(
            self::SQL_TAKEN_ONE . ' ' . self::SQL_TAKEN . ' ' . self::SQL_TAKEN_ONE_TAIL,
            [':id' => $lineId]
        )->fetch();
        return is_array($row) ? (string) $row['taken'] : '0';
    }

    /**
     * 수량 비교는 **문자열 소수**로 한다 (numeric(14,3)).
     * float 로 바꿔 비교하면 0.1 세 번이 0.3 이 아닌 부동소수 문제가 잔량 판정에 섞인다.
     * 천분의 1 단위까지만 쓰므로 1000 을 곱한 정수로 견준다.
     */
    public static function milli(string $qty): int
    {
        return (int) round(((float) $qty) * 1000);
    }

    /** 수량을 표에 넣을 문자열로 (소수 셋째 자리까지) */
    public static function qtyText(int $milli): string
    {
        return number_format($milli / 1000, 3, '.', '');
    }

    /* ══════════════════════════════════════════════════════════
     * 금액 — 서버만 계산한다 (SECURITY R-66, 완료 기준 「클라이언트 amount 무시」)
     * ══════════════════════════════════════════════════════════ */

    /**
     * 줄 금액 = 수량 × 단가. 단가는 **언제나 `p_items.price_in`** 이고,
     * 요청 본문의 `unit_price`·`amount` 는 읽지 않는다.
     * 단가가 없는 품목(`price_in IS NULL`)이면 금액도 null 이다 — 0 을 지어내지 않는다.
     */
    public static function lineAmount(string $qty, ?string $unitPrice): ?string
    {
        if ($unitPrice === null) {
            return null;
        }
        return number_format(((float) $qty) * ((float) $unitPrice), 2, '.', '');
    }

    /**
     * 전표 금액 세 칸 — 공급가액·부가세·합계 (Q-18② 「금액 세 칸은 매 응답 서버 계산」).
     *
     * @param list<array<string,mixed>> $lines
     * @return array{supply:string,vat:string,total:string}
     */
    public static function totals(array $lines): array
    {
        $supply = 0.0;
        foreach ($lines as $line) {
            if (($line['status'] ?? '') === self::LINE_VOID) {
                continue;
            }
            $amount = $line['amount'] ?? null;
            if ($amount === null) {
                continue;
            }
            $supply += (float) $amount;
        }
        $vat = round($supply * self::VAT_RATE, 2);
        return [
            'supply' => number_format($supply, 2, '.', ''),
            'vat'    => number_format($vat, 2, '.', ''),
            'total'  => number_format($supply + $vat, 2, '.', ''),
        ];
    }

    /* ══════════════════════════════════════════════════════════
     * 상태 전이
     * ══════════════════════════════════════════════════════════ */

    private const SQL_RECALC_SELECT = <<<'SQL'
        SELECT l.id, l.qty, l.status, l.locked_at,
        SQL;

    private const SQL_RECALC_TAIL = <<<'SQL'
        AS taken
          FROM p_doc_lines l
         WHERE l.doc_id = CAST(:doc AS bigint)
        SQL;

    private const SQL_SET_DOC_STATUS = <<<'SQL'
        UPDATE p_docs SET status = :st, updated_at = now()
         WHERE id = CAST(:id AS bigint) AND status <> 'void'
        SQL;

    /**
     * 요청 전표의 상태를 줄 상태에서 **다시 계산**한다 (F-30 상태 어휘).
     *
     *   살아 있는 줄이 하나도 없다            → 건드리지 않는다(취소는 따로)
     *   모든 살아 있는 줄이 전량 담겼다        → done   (완료)
     *   일부라도 담긴 줄이 있다               → partial(일부 발주)
     *   아무것도 담기지 않았다                → unconfirmed (미확인)
     *
     * 상태를 컬럼으로 들고 다니되 **언제나 줄에서 다시 계산**하는 이유는, 담기·취소가
     * 여러 경로로 일어나기 때문이다. 경로마다 상태를 손으로 옮기면 한 곳만 빠뜨려도
     * "완료인데 잔량이 남은" 전표가 생긴다.
     *
     * @return string 계산된 상태
     */
    public static function recalcRequestStatus(Db $db, int $docId): string
    {
        $rows = $db->run(
            self::SQL_RECALC_SELECT . ' ' . self::SQL_TAKEN . ' ' . self::SQL_RECALC_TAIL,
            [':doc' => $docId]
        )->fetchAll();

        $alive  = 0;
        $allDone = true;
        $anyTaken = false;
        foreach ($rows as $row) {
            if ((string) $row['status'] === self::LINE_VOID) {
                continue;
            }
            $alive++;
            $taken = self::milli((string) $row['taken']);
            $want  = self::milli((string) $row['qty']);
            if ($taken > 0) {
                $anyTaken = true;
            }
            if ($taken < $want) {
                $allDone = false;
            }
        }

        if ($alive === 0) {
            return self::ST_UNCONFIRMED;
        }
        $status = $allDone ? self::ST_DONE : ($anyTaken ? self::ST_PARTIAL : self::ST_UNCONFIRMED);
        $db->run(self::SQL_SET_DOC_STATUS, [':st' => $status, ':id' => $docId]);
        return $status;
    }

    private const SQL_SET_LINE_TAKEN = <<<'SQL'
        UPDATE p_doc_lines
           SET status = :st, locked_at = COALESCE(locked_at, now()), updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    private const SQL_SET_LINE_FREE = <<<'SQL'
        UPDATE p_doc_lines
           SET status = :st, locked_at = NULL, updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    /**
     * 요청 줄 하나의 상태를 잔량으로 다시 정한다.
     *   전량 담김 → done + locked_at   ·   일부 담김 → ordered + locked_at
     *   하나도 안 담김(발주서 취소 뒤) → unconfirmed + locked_at 을 **지운다**
     *     (다시 요청자의 수정 창이 열린다 — 담긴 것이 없으면 잠글 이유가 없다)
     */
    public static function retouchRequestLine(Db $db, int $lineId, string $wantQty, string $takenQty): string
    {
        $want  = self::milli($wantQty);
        $taken = self::milli($takenQty);

        if ($taken <= 0) {
            $db->run(self::SQL_SET_LINE_FREE, [':st' => self::LINE_UNCONFIRMED, ':id' => $lineId]);
            return self::LINE_UNCONFIRMED;
        }
        $status = $taken >= $want ? self::LINE_DONE : self::LINE_ORDERED;
        $db->run(self::SQL_SET_LINE_TAKEN, [':st' => $status, ':id' => $lineId]);
        return $status;
    }

    /* ══════════════════════════════════════════════════════════
     * 발주서 머리 (Q-18② — 이카운트 발주서 양식, files #188)
     * ══════════════════════════════════════════════════════════ */

    private const SQL_HEADER_SOURCE = <<<'SQL'
        SELECT v.name AS vendor_name, v.biz_no AS vendor_biz_no,
               loc.name AS location_name, br.name AS branch_name,
               bd.name AS brand_name, bd.biz_no, bd.ceo_name, bd.address, bd.tel,
               bd.logo_file_id,
               u.username AS manager_name
          FROM p_docs d
          LEFT JOIN p_vendors   v   ON v.id  = d.vendor_id
          LEFT JOIN p_locations loc ON loc.id = d.to_location_id
          JOIN p_branches br ON br.id = d.branch_id
          LEFT JOIN p_brands   bd ON bd.id = br.brand_id
          JOIN users u ON u.id = d.manager_id
         WHERE d.id = CAST(:id AS bigint)
        SQL;

    private const SQL_DUE_DATE = <<<'SQL'
        SELECT to_char(d.doc_date + (COALESCE(max(i.lead_days), 0) || ' days')::interval, 'YYYY-MM-DD') AS due
          FROM p_docs d
          JOIN p_doc_lines l ON l.doc_id = d.id AND l.status <> 'void'
          JOIN p_items     i ON i.id = l.item_id
         WHERE d.id = CAST(:id AS bigint)
         GROUP BY d.doc_date
        SQL;

    /**
     * 발주서 머리를 **서버가 채운다** (Q-18② · ARCH 5.18 「발주서 PDF」).
     *
     *   일련번호 = doc_no · 수신 = 거래처명 · 납품창고 = 입고 장소 이름
     *   납기     = 전표 일자 + 품목 `lead_days` 의 **최대값**
     *   회사     = 브랜드 열(biz_no·ceo_name·address·tel·logo_file_id)
     *   담당     = manager 표시명
     *
     * 여기서 만든 값은 `p_docs.header_json` 에 저장되고, buyer 는 `HEADER_EDITABLE` 의
     * 여섯 키만 고칠 수 있다. 금액 세 칸은 저장하지 않는다 — 줄이 바뀌면 틀린 값이 되므로
     * **읽을 때마다** `totals()` 로 계산한다.
     *
     * @return array<string,mixed>
     */
    public static function buildHeader(Db $db, int $docId, string $docNo): array
    {
        $src = $db->run(self::SQL_HEADER_SOURCE, [':id' => $docId])->fetch();
        $due = $db->run(self::SQL_DUE_DATE, [':id' => $docId])->fetch();
        $src = is_array($src) ? $src : [];

        $text = static function (array $row, string $key): ?string {
            $v = $row[$key] ?? null;
            return $v === null || $v === '' ? null : (string) $v;
        };

        return [
            'serial_no'         => $docNo,
            'receiver'          => $text($src, 'vendor_name'),
            'receiver_biz_no'   => $text($src, 'vendor_biz_no'),
            'cc'                => null,
            'tel'               => $text($src, 'tel'),
            'delivery_location' => $text($src, 'location_name'),
            'branch_name'       => $text($src, 'branch_name'),
            'due_date'          => is_array($due) ? (string) $due['due'] : null,
            'company_name'      => $text($src, 'brand_name'),
            'company_biz_no'    => $text($src, 'biz_no'),
            'company_ceo'       => $text($src, 'ceo_name'),
            'company_address'   => $text($src, 'address'),
            'company_tel'       => $text($src, 'tel'),
            'logo_file_id'      => isset($src['logo_file_id']) && $src['logo_file_id'] !== null
                ? (int) $src['logo_file_id'] : null,
            'manager_name'      => $text($src, 'manager_name'),
            'memo'              => null,
        ];
    }

    /* ══════════════════════════════════════════════════════════
     * 쓰기 — 전표·줄 만들기 (언제나 트랜잭션 안에서 부른다)
     * ══════════════════════════════════════════════════════════ */

    /*
     * 22단계 채번 통합 — 21단계에서 늘어난 칸(from_location_id·count_cycle,
     * lot_no·expiry_date·counted_qty)을 **이 문장 하나**가 함께 받는다.
     * 값이 없는 전표 종류는 null 이 들어가고, 그것은 21단계 `Stock.php` 의 두 번째 문장이
     * 하던 것과 똑같다 — 동작은 바뀌지 않고 문장이 하나로 줄었을 뿐이다.
     */
    private const SQL_INSERT_DOC = <<<'SQL'
        INSERT INTO p_docs
            (doc_type, doc_no, doc_date, branch_id, vendor_id,
             from_location_id, to_location_id, manager_id, status, memo,
             count_cycle, split_from_id, created_by)
        VALUES
            (:doc_type, :doc_no, CAST(:doc_date AS date), CAST(:branch AS bigint),
             CAST(:vendor AS bigint), CAST(:from_loc AS bigint), CAST(:loc AS bigint),
             CAST(:manager AS bigint), :status, :memo, :cycle,
             CAST(:split AS bigint), CAST(:by AS bigint))
        RETURNING id
        SQL;

    /**
     * 전표 머리 한 건. 값은 전부 바인딩이고, `doc_no` 는 `nextDocNo()` 가 만든 것만 온다.
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
            ':loc'      => $f['to_location_id'] ?? null,
            ':manager'  => (int) $f['manager_id'],
            ':status'   => (string) $f['status'],
            ':memo'     => $f['memo'] ?? null,
            ':cycle'    => $f['count_cycle'] ?? null,
            ':split'    => $f['split_from_id'] ?? null,
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
     * 전표 줄 한 개.
     *
     * ★ `unit_price`·`amount` 는 **호출자가 `p_items.price_in`(또는 출처 발주서 줄의 단가)
     *   에서 가져온 값**이다. 요청 본문의 금액은 어느 경로로도 여기 오지 않는다
     *   (SECURITY R-66, 완료 기준).
     *
     * 뒤의 세 인자(22단계 채번 통합)는 21단계 재고 줄의 칸이다 — 로트·유효기간(입고)과
     * 「센 수량」(실사). 20단계 호출자는 적지 않으므로 기본값 null 로 들어간다.
     */
    public static function insertLine(
        Db $db,
        int $docId,
        int $lineNo,
        int $itemId,
        string $qty,
        ?string $unitPrice,
        ?string $remark,
        ?int $srcLineId,
        string $status = self::LINE_UNCONFIRMED,
        ?string $lotNo = null,
        ?string $expiryDate = null,
        ?string $countedQty = null
    ): int {
        $row = $db->run(self::SQL_INSERT_LINE, [
            ':doc'     => $docId,
            ':no'      => $lineNo,
            ':item'    => $itemId,
            ':qty'     => $qty,
            ':price'   => $unitPrice,
            ':amount'  => self::lineAmount($qty, $unitPrice),
            ':remark'  => $remark,
            ':src'     => $srcLineId,
            ':status'  => $status,
            ':lot'     => $lotNo,
            ':exp'     => $expiryDate,
            ':counted' => $countedQty,
        ])->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('전표 줄을 만들지 못했습니다');
        }
        return (int) $row['id'];
    }

    private const SQL_NEXT_LINE_NO = <<<'SQL'
        SELECT COALESCE(max(line_no), 0) + 1 AS n
          FROM p_doc_lines
         WHERE doc_id = CAST(:doc AS bigint)
        SQL;

    /** 이 전표의 다음 줄 번호. UNIQUE(doc_id, line_no) 가 마지막 그물이다. */
    public static function nextLineNo(Db $db, int $docId): int
    {
        $row = $db->run(self::SQL_NEXT_LINE_NO, [':doc' => $docId])->fetch();
        return is_array($row) ? (int) $row['n'] : 1;
    }

    private const SQL_LINES_HEAD = <<<'SQL'
        SELECT l.id, l.line_no, l.item_id, CAST(l.qty AS text) AS qty,
               CAST(l.unit_price AS text) AS unit_price,
               CAST(l.amount AS text) AS amount,
               l.remark, l.src_line_id, l.locked_at, l.status,
               i.code, i.name, i.spec, i.unit, i.vendor_id,
               v.name AS vendor_name,
               src.doc_id AS src_doc_id, srcd.doc_no AS src_doc_no,
        SQL;

    private const SQL_LINES_TAIL = <<<'SQL'
        AS taken
          FROM p_doc_lines l
          JOIN p_items   i ON i.id = l.item_id
          JOIN p_vendors v ON v.id = i.vendor_id
          LEFT JOIN p_doc_lines src  ON src.id = l.src_line_id
          LEFT JOIN p_docs      srcd ON srcd.id = src.doc_id
         WHERE l.doc_id = CAST(:doc AS bigint)
         ORDER BY l.line_no ASC
        SQL;

    /**
     * 전표의 줄 목록 (화면·인쇄가 쓰는 모양).
     *
     * 이 함수에는 **지점 조건이 없다** — 부르는 핸들러가 이미 전표 머리를
     * `purchaseScopeWhere` 로 걸러 "볼 수 있는 전표" 임을 확인한 뒤에 부른다.
     * 줄은 전표에 딸린 것이라 전표를 볼 수 있으면 줄도 볼 수 있다(파일 상세와 같은 판단).
     *
     * @return list<array<string,mixed>>
     */
    public static function lines(Db $db, int $docId): array
    {
        $rows = $db->run(
            self::SQL_LINES_HEAD . ' ' . self::SQL_TAKEN . ' ' . self::SQL_LINES_TAIL,
            [':doc' => $docId]
        )->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $want  = self::milli((string) $row['qty']);
            $taken = self::milli((string) $row['taken']);
            $out[] = [
                'id'          => (int) $row['id'],
                'line_no'     => (int) $row['line_no'],
                'item_id'     => (int) $row['item_id'],
                'code'        => (string) $row['code'],
                'name'        => (string) $row['name'],
                'spec'        => $row['spec'] === null ? null : (string) $row['spec'],
                'unit'        => $row['unit'] === null ? null : (string) $row['unit'],
                'vendor_id'   => (int) $row['vendor_id'],
                'vendor_name' => (string) $row['vendor_name'],
                'qty'         => (string) $row['qty'],
                'unit_price'  => $row['unit_price'] === null ? null : (string) $row['unit_price'],
                'amount'      => $row['amount'] === null ? null : (string) $row['amount'],
                'remark'      => $row['remark'] === null ? null : (string) $row['remark'],
                'src_line_id' => $row['src_line_id'] === null ? null : (int) $row['src_line_id'],
                'src_doc_id'  => $row['src_doc_id'] === null ? null : (int) $row['src_doc_id'],
                'src_doc_no'  => $row['src_doc_no'] === null ? null : (string) $row['src_doc_no'],
                'locked_at'   => $row['locked_at'] === null ? null : (string) $row['locked_at'],
                'status'      => (string) $row['status'],
                'taken_qty'   => self::qtyText($taken),
                'remaining'   => self::qtyText(max(0, $want - $taken)),
            ];
        }
        return $out;
    }

    /* ══════════════════════════════════════════════════════════
     * 참조 확인 — "이 지점·장소·거래처·품목을 이 사람이 쓸 수 있나"
     *
     * 지점 조건(`$scope`)은 **핸들러가** `Authz::purchaseScopeWhere` 로 만들어 넘긴다.
     * 이 파일이 역할·지점을 스스로 판단하는 곳은 한 군데도 없다 (ARCH 4.1).
     * ══════════════════════════════════════════════════════════ */

    private const SQL_BRANCH_ONE = <<<'SQL'
        WITH b AS (SELECT p.*, p.id AS branch_id FROM p_branches p)
        SELECT b.id
          FROM b
         WHERE b.id = CAST(:id AS bigint) AND b.is_active AND
        SQL;

    private const SQL_BRANCH_MINE = <<<'SQL'
        WITH b AS (SELECT p.*, p.id AS branch_id FROM p_branches p)
        SELECT b.id
          FROM b
         WHERE b.is_active AND
        SQL;

    private const SQL_BRANCH_MINE_TAIL = <<<'SQL'
        ORDER BY b.id ASC LIMIT 2
        SQL;

    /**
     * 전표의 지점을 정한다.
     *
     *   요청이 지점을 적었다 → **내 범위 안인지 확인**한다. 밖이면 null(= 거부).
     *     requester 가 URL·본문에 남의 지점을 적어도 여기서 걸린다 (PRD S-01, T-42).
     *   요청이 비웠다       → 내 범위에 지점이 **하나뿐**이면 그것으로 본다(지점 사용자).
     *                        여럿이면 null — 구매팀은 어느 지점의 요청인지 스스로 적어야 한다.
     *
     * @param array{sql:string,params:array<string,mixed>} $scope `b` 별칭 기준 지점 조건
     */
    public static function resolveBranch(Db $db, array $scope, ?int $requested): ?int
    {
        if ($requested !== null) {
            $row = $db->run(
                self::SQL_BRANCH_ONE . ' ' . $scope['sql'],
                [':id' => $requested] + $scope['params']
            )->fetch();
            return is_array($row) ? (int) $row['id'] : null;
        }

        $rows = $db->run(
            self::SQL_BRANCH_MINE . ' ' . $scope['sql'] . ' ' . self::SQL_BRANCH_MINE_TAIL,
            $scope['params']
        )->fetchAll();
        return count($rows) === 1 ? (int) $rows[0]['id'] : null;
    }

    private const SQL_ITEMS_HEAD = <<<'SQL'
        SELECT i.id, i.code, i.name, i.spec, i.unit, i.vendor_id, i.lead_days,
               CAST(i.price_in AS text) AS price_in,
               v.name AS vendor_name
          FROM p_items i
          JOIN p_vendors v ON v.id = i.vendor_id
         WHERE i.is_active AND i.id IN
        SQL;

    /**
     * 품목들을 **볼 수 있는 범위 안에서만** 읽는다.
     *
     * 지점 격리는 품목 찾기 API(purchase_items_search.php)와 **같은 EXISTS 조건**이다:
     *   "내 지점에서 쓰는 품목" 또는 "사용 지점이 하나도 지정되지 않은 품목(브랜드 전체)".
     * 다른 지점 전용 품목의 id 를 손으로 적어 보내도 여기서 사라진다 → 핸들러가 400 으로 끝낸다.
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeIb `ib` 별칭 기준 조건
     * @param list<int> $itemIds
     * @return array<int,array<string,mixed>> 품목 id → 행 (범위 밖·중단된 품목은 빠진다)
     */
    public static function loadItems(Db $db, array $scopeIb, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $names = [];
        $bind  = [];
        foreach (array_values(array_unique($itemIds)) as $i => $id) {
            $name        = ':i' . (string) $i;
            $names[]     = $name;
            $bind[$name] = (int) $id;
        }
        $inList = '(' . implode(', ', $names) . ')';

        $visible = '1=1';
        if ($scopeIb['sql'] === '1=0') {
            $visible = '1=0';
        } elseif ($scopeIb['sql'] !== '1=1') {
            $visible  = '(EXISTS (SELECT 1 FROM p_item_branches ib';
            $visible .= ' WHERE ib.item_id = i.id AND ib.is_active AND ';
            $visible .= $scopeIb['sql'];
            $visible .= ') OR NOT EXISTS (SELECT 1 FROM p_item_branches ibx';
            $visible .= ' WHERE ibx.item_id = i.id AND ibx.is_active))';
            $bind     = $bind + $scopeIb['params'];
        }

        $rows = $db->run(self::SQL_ITEMS_HEAD . ' ' . $inList . ' AND ' . $visible, $bind)->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private const SQL_LOCATION_ONE = <<<'SQL'
        SELECT l.id
          FROM p_locations l
         WHERE l.id = CAST(:id AS bigint) AND l.is_active
           AND l.branch_id = CAST(:branch AS bigint) AND
        SQL;

    /**
     * 입고 장소가 이 전표의 지점 것이고 내 범위 안인가.
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeL `l` 별칭 기준 조건
     */
    public static function locationOk(Db $db, array $scopeL, int $locationId, int $branchId): bool
    {
        $row = $db->run(
            self::SQL_LOCATION_ONE . ' ' . $scopeL['sql'],
            [':id' => $locationId, ':branch' => $branchId] + $scopeL['params']
        )->fetch();
        return is_array($row);
    }

    private const SQL_LOCATION_BRANCH = <<<'SQL'
        SELECT l.branch_id
          FROM p_locations l
         WHERE l.id = CAST(:id AS bigint) AND l.is_active AND
        SQL;

    /**
     * 입고 장소가 속한 지점 — 발주서의 `branch_id` 는 **장소에서 나온다**(요청 값이 아니다).
     * 범위 밖이거나 중단된 장소면 null 이고, 그때 핸들러가 400 으로 끝낸다.
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeL `l` 별칭 기준 조건
     */
    public static function locationBranch(Db $db, array $scopeL, int $locationId): ?int
    {
        $row = $db->run(
            self::SQL_LOCATION_BRANCH . ' ' . $scopeL['sql'],
            [':id' => $locationId] + $scopeL['params']
        )->fetch();
        return is_array($row) ? (int) $row['branch_id'] : null;
    }

    private const SQL_SAVE_HEADER = <<<'SQL'
        UPDATE p_docs SET header_json = CAST(:h AS jsonb), updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    /**
     * 발주서 머리를 저장한다. 값은 이 파일이 만든 배열뿐이고, 요청 본문이 통째로 들어오지 않는다
     * (buyer 의 수정은 `HEADER_EDITABLE` 여섯 키만 골라 덮어쓴다 — purchase_po_update.php).
     *
     * @param array<string,mixed> $header
     */
    public static function saveHeader(Db $db, int $docId, array $header): void
    {
        $json = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('발주서 머리를 저장하지 못했습니다');
        }
        $db->run(self::SQL_SAVE_HEADER, [':h' => $json, ':id' => $docId]);
    }

    private const SQL_VENDOR_ONE = <<<'SQL'
        SELECT id FROM p_vendors WHERE id = CAST(:id AS bigint) AND is_active
        SQL;

    /** 거래처가 있고 사용 중인가 (지점 무관 마스터라 지점 조건이 없다) */
    public static function vendorOk(Db $db, int $vendorId): bool
    {
        return is_array($db->run(self::SQL_VENDOR_ONE, [':id' => $vendorId])->fetch());
    }

    private const SQL_VENDOR_NAME = <<<'SQL'
        SELECT name FROM p_vendors WHERE id = CAST(:id AS bigint)
        SQL;

    /**
     * 거래처 이름 — **응답에 보여 줄 값**만 읽는다 (F-60 분할 저장의 `docs[].vendor_name`).
     * 판정에는 쓰지 않는다: 쓸 수 있는 거래처인가는 여전히 `vendorOk` 한 곳이 본다.
     * 없는 id 면 null (지어내지 않는다).
     */
    public static function vendorName(Db $db, int $vendorId): ?string
    {
        $row = $db->run(self::SQL_VENDOR_NAME, [':id' => $vendorId])->fetch();
        return is_array($row) ? (string) $row['name'] : null;
    }

    private const SQL_USER_ONE = <<<'SQL'
        SELECT id FROM users
         WHERE id = CAST(:id AS bigint) AND is_active AND purchase_role IS NOT NULL
        SQL;

    /**
     * 담당자로 적을 수 있는 계정인가 — **모듈 역할이 있는 활성 계정만**.
     * 값을 견주지 않고 "NULL 이 아니다" 만 본다 — 역할 값 비교는 Authz 밖에 두지 않는다 (R-70).
     */
    public static function managerOk(Db $db, int $userId): bool
    {
        return is_array($db->run(self::SQL_USER_ONE, [':id' => $userId])->fetch());
    }

    /**
     * 화면·인쇄가 읽는 머리 정보. 저장된 값이 없으면 빈 칸을 만들어 준다 —
     * 인쇄 화면이 `null` 을 만나 깨지지 않게 하는 것이 목적이고, **값을 지어내지 않는다**.
     *
     * @param mixed $stored p_docs.header_json (문자열 또는 null)
     * @return array<string,mixed>
     */
    public static function headerOf($stored): array
    {
        $out = [];
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                $out = $decoded;
            }
        }
        return $out;
    }

    /* ══════════════════════════════════════════════════════════
     * 월 마감 — 22단계 (PRD F-35 · Q-18, ARCH 5.18 「마감」)
     * ══════════════════════════════════════════════════════════ */

    /** 마감 검사 결과 — 마감되지 않은 달 */
    public const CLOSE_OPEN = 'open';
    /** 마감 검사 결과 — 막아야 한다 (핸들러는 400 `month_closed`) */
    public const CLOSE_BLOCKED = 'blocked';
    /** 마감 검사 결과 — `purchase.close` 보유자가 사유를 적었다 (감사 detail.after_close) */
    public const CLOSE_AFTER = 'after_close';

    private const SQL_MONTH_CLOSED = <<<'SQL'
        SELECT 1
          FROM p_month_close
         WHERE month = CAST(:m AS char(7))
           AND (branch_id IS NULL OR branch_id = CAST(:b AS bigint))
         LIMIT 1
        SQL;

    /**
     * 이 지점·이 달이 마감됐나 — **전사 마감(`branch_id IS NULL`)이 있으면 지점 것도 잠긴다**
     * (지시서 보충 3). 값은 전부 바인딩이다.
     */
    public static function monthClosed(Db $db, ?int $branchId, string $month): bool
    {
        return is_array($db->run(self::SQL_MONTH_CLOSED, [
            ':m' => $month,
            ':b' => $branchId,
        ])->fetch());
    }

    /**
     * 마감된 달의 전표를 고치거나 취소하려는 요청을 **한 줄로** 판정한다
     * (ARCH 5.18 「마감」: "수정·취소는 `purchase.close` + 사유 + 감사").
     *
     *   open         마감되지 않았다 — 평소대로 진행
     *   blocked      마감됐고 자격 또는 사유가 없다 — 핸들러가 400 `month_closed`
     *   after_close  마감됐지만 `purchase.close` 보유자가 사유를 적었다 — 진행하되
     *                감사 detail 에 `after_close = true` 를 남긴다(경보 AL-14 가 본다)
     *
     * ★ 역할 판정은 여기서 하지 않는다 — `Authz::purchaseAuthorize` 를 부를 뿐이다
     *   (ARCH 4.1 "판단은 Authz 한 곳"). 이 함수가 더하는 것은 "마감됐나 + 사유가 있나" 다.
     *
     * `closedMonth` 는 전표의 `p_docs.closed_month`(마감 때 채워지는 표식)다. 그 칸이 차 있으면
     * `doc_date` 를 보지 않고도 마감된 전표임을 안다 — 마감 뒤에 `doc_date` 만 바꿔 빠져나가는
     * 길을 막는다. 둘 중 **하나라도** 마감이면 마감으로 본다.
     *
     * @param array<string,mixed> $user 라우터가 넘긴 현재 사용자 (요청 값이 아니다)
     */
    public static function closeGuard(
        Db $db,
        array $user,
        ?int $branchId,
        ?string $docDate,
        ?string $closedMonth,
        ?string $reasonText
    ): string {
        $closed = is_string($closedMonth) && $closedMonth !== '';
        if (!$closed && is_string($docDate) && strlen($docDate) >= 7) {
            $closed = self::monthClosed($db, $branchId, substr($docDate, 0, 7));
        }
        if (!$closed) {
            return self::CLOSE_OPEN;
        }
        if (!is_string($reasonText) || trim($reasonText) === '') {
            return self::CLOSE_BLOCKED;
        }
        return Authz::purchaseAuthorize('purchase.close', $user)
            ? self::CLOSE_AFTER
            : self::CLOSE_BLOCKED;
    }
}
