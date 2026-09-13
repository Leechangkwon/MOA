<?php
declare(strict_types=1);

require_once __DIR__ . '/Doc.php';
require_once __DIR__ . '/Sheet.php';
require_once __DIR__ . '/../Preview.php';

/**
 * src/purchase/Recon.php — 거래처 월 대사의 규칙 한 곳 (22단계, PRD F-35, 시안 Recon)
 *
 * 무엇을 하나
 *   거래처가 보내온 **명세서 한 장**(엑셀·CSV·PDF 텍스트)의 줄과, 같은 거래처·같은 달의
 *   **우리 입고(GR) 줄**을 맞춰 차이를 네 가지로 가른다.
 *
 *     none            같다
 *     qty             수량이 다르다
 *     price           단가가 다르다 (±1원 초과 — 절사·반올림 차이는 차이가 아니다)
 *     missing_ours    명세서엔 있는데 우리 입고가 없다
 *     missing_theirs  우리 입고는 있는데 명세서에 없다
 *
 * 대조 우선순위 (지시서 보충 1)
 *   ① 명세서 품목 코드 = `p_items.code` (**같은 거래처 안에서만** — 코드는 거래처 카탈로그
 *      번호라 거래처가 다르면 같은 코드가 다른 물건이다)
 *   ② 코드가 없거나 못 찾으면 **이름 정규화**(공백·괄호·대소문자·전각 제거) 일치
 *   ③ 그 뒤에 수량·단가를 견준다. 우리 입고가 여러 줄이면 **같은 달 안에서 수량 합**으로
 *      대조하고 `note` 에 「n줄 합」이라고 적는다.
 *
 * ★ R-69 — 명세서에서 읽는 칸은 **품목 코드·품목명·수량·단가·금액** 다섯뿐이다.
 *   담당자 연락처·계좌번호는 매핑 대상 목록(`TARGETS`)에 아예 없고, `p_recon_lines` 에도
 *   담을 컬럼이 없다. 원본 파일은 admin-only 폴더에만 남는다.
 *
 * ★ 「우리 금액」은 **서버가 DB 에서 읽은 값**이다(`p_doc_lines.amount`, 입고 확정 때 서버가
 *   수량 × 단가로 계산해 넣은 것). 명세서 쪽 금액만 파일에서 온다 — 그 둘을 견주는 것이
 *   대사이므로, 명세서 값을 우리 장부에 쓰는 경로는 이 파일 어디에도 없다 (SECURITY R-66).
 *
 * ★ 이 파일에는 **권한 판단이 없다.** 지점 범위 조각은 호출자가 `Authz` 에서 받아 넘긴다.
 * ★ SQL 은 고정 문자열이고 값은 바인딩뿐이다 (PRD S-04).
 */
final class Recon
{
    /** 명세서에서 읽어 들이는 **열의 전부** (R-69). 여기 없는 열은 저장되지 않는다. */
    public const TARGETS = ['item_code', 'item_name', 'qty', 'unit_price', 'amount'];

    /** 없으면 대조를 할 수 없는 열 */
    public const REQUIRED = ['item_name', 'qty'];

    /** 한 명세서에서 읽을 수 있는 줄 수 상한 (한 파일이 표를 밀어내지 못하게) */
    public const MAX_LINES = 2000;

    /** 머리행을 고를 수 있는 범위 (가져오기와 같은 규칙) */
    public const MAX_HEADER_ROW = 20;

    /** 단가 차이를 「차이」로 보는 문턱 — 1원 **초과** (지시서 보충 2) */
    public const PRICE_EPS = 1.0;

    /** 보관 폴더 (admin-only). 요청 값이 닿지 않는 코드 상수다. */
    public const FOLDER_ROOT      = '구매';
    public const FOLDER_STATEMENT = '명세서';

    /** 열 이름 추측에 쓰는 동의어 (가져오기 Importer 와 같은 방식) */
    private const SYNONYMS = [
        'item_code'  => ['품목코드', '상품코드', '코드', 'code', '자재코드', '품번'],
        'item_name'  => ['품목명', '상품명', '품명', '제품명', 'name', '규격명'],
        'qty'        => ['수량', '박스수량', '출고수량', 'qty', '개수'],
        'unit_price' => ['단가', '공급단가', '판매단가', 'price', '단위단가'],
        'amount'     => ['금액', '공급가액', '합계금액', 'amount', '공급가'],
    ];

    /* ══════════════════════════════════════════════════════════
     * 파일 → 표
     * ══════════════════════════════════════════════════════════ */

    /**
     * 명세서 파일에서 표를 읽는다.
     *
     * xlsx·csv 는 19단계 `Sheet::rows` 를 **그대로** 쓴다(새 파서를 만들지 않는다).
     * pdf 는 본체 `Preview::text` 가 뽑아 주는 **글자**만 본다 — 새 라이브러리를 넣지 않는다는
     * 규칙 때문이다(지시서 금지 패턴). 글자가 나오지 않는 PDF(스캔 이미지)는 빈 표가 되고,
     * 그때 화면은 「사람이 입력」으로 간다 (docs/purchase/03 「이미지 PDF 는 review」).
     *
     * @return array{rows: list<list<string>>, truncated: bool, kind: string}
     */
    public static function rows(string $path, string $ext): array
    {
        if ($ext === 'pdf') {
            $out = self::pdfRows($path);
            return ['rows' => $out['rows'], 'truncated' => $out['truncated'], 'kind' => 'pdf'];
        }
        $sheet = Sheet::rows($path, $ext);
        return ['rows' => $sheet['rows'], 'truncated' => $sheet['truncated'], 'kind' => 'sheet'];
    }

    /**
     * PDF 의 **텍스트 층**만 줄 단위로 잘라 표 흉내를 낸다.
     *
     * 공백 2칸 이상 또는 탭을 칸 구분으로 본다(명세서 PDF 를 텍스트로 복사했을 때의 모양).
     * 칸이 둘 미만인 줄은 표가 아니므로 버린다. **글자를 못 뽑으면 빈 표**이며, 그것을
     * "0건 일치" 로 보고하지 않는다 — 호출자가 `rows === []` 를 보고 사람에게 알린다.
     *
     * @return array{rows: list<list<string>>, truncated: bool}
     */
    private static function pdfRows(string $path): array
    {
        $text = Preview::text($path);
        $body = (string) $text['content'];
        if (trim($body) === '') {
            return ['rows' => [], 'truncated' => (bool) $text['truncated']];
        }

        $rows  = [];
        $lines = preg_split('/\r\n|\r|\n/', $body);
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (count($rows) >= self::MAX_LINES) {
                return ['rows' => $rows, 'truncated' => true];
            }
            $cells = preg_split('/\t+| {2,}/', trim((string) $line));
            if (!is_array($cells)) {
                continue;
            }
            $cells = array_values(array_filter(array_map('trim', $cells), static function ($c): bool {
                return $c !== '';
            }));
            if (count($cells) < 2) {
                continue;
            }
            $rows[] = $cells;
        }
        return ['rows' => $rows, 'truncated' => (bool) $text['truncated']];
    }

    /**
     * 머리행을 보고 열 매핑을 **추측**한다. 서버는 이 값을 판정에 쓰지 않는다 —
     * 화면이 셀렉트의 기본값으로 쓰고, 사람이 고친 매핑만 미리보기·확정에 온다.
     *
     * @param list<string> $header
     * @return array<string,int>
     */
    public static function guessMapping(array $header): array
    {
        $guess = [];
        foreach ($header as $idx => $rawName) {
            $name = self::squeeze((string) $rawName);
            if ($name === '') {
                continue;
            }
            foreach (self::SYNONYMS as $target => $words) {
                if (isset($guess[$target])) {
                    continue;
                }
                foreach ($words as $word) {
                    if ($name === self::squeeze($word)) {
                        $guess[$target] = (int) $idx;
                        break 2;
                    }
                }
            }
        }
        return $guess;
    }

    /** 머리행 후보 — 동의어가 가장 많이 맞는 줄 (가져오기와 같은 방식) */
    public static function detectHeaderRow(array $rows): int
    {
        $best  = 0;
        $score = -1;
        $limit = min(count($rows), self::MAX_HEADER_ROW);
        for ($i = 0; $i < $limit; $i++) {
            $hit = count(self::guessMapping(is_array($rows[$i]) ? $rows[$i] : []));
            if ($hit > $score) {
                $score = $hit;
                $best  = $i;
            }
        }
        return $best;
    }

    /**
     * 표 + 매핑 → 명세서 줄 목록. 값의 형식이 아니면 그 줄은 **건너뛴다**(수량이 숫자가
     * 아닌 줄은 소계·합계 줄일 가능성이 높다 — 거기서 멈추면 대사를 시작할 수 없다).
     *
     * @param list<list<string>>  $rows
     * @param array<string,int>   $mapping
     * @return list<array<string,mixed>>
     */
    public static function parse(array $rows, array $mapping, int $headerRow): array
    {
        $out   = [];
        $total = count($rows);
        for ($i = $headerRow + 1; $i < $total; $i++) {
            if (count($out) >= self::MAX_LINES) {
                break;
            }
            $row  = is_array($rows[$i]) ? $rows[$i] : [];
            $name = self::cell($row, $mapping, 'item_name');
            $qty  = self::number(self::cell($row, $mapping, 'qty'));
            if ($name === null || $qty === null) {
                continue;
            }
            $price  = self::number(self::cell($row, $mapping, 'unit_price'));
            $amount = self::number(self::cell($row, $mapping, 'amount'));
            if ($amount === null && $price !== null) {
                // 금액 열이 없는 명세서 — 수량 × 단가로 **명세서 쪽** 금액을 세운다.
                $amount = round($qty * $price, 2);
            }
            $code = self::cell($row, $mapping, 'item_code');
            $out[] = [
                'row_no'     => count($out) + 1,
                'item_code'  => $code === null ? null : self::cut($code, 60),
                'item_name'  => self::cut($name, 200),
                'qty'        => number_format($qty, 3, '.', ''),
                'unit_price' => $price === null ? null : number_format($price, 2, '.', ''),
                'amount'     => $amount === null ? null : number_format($amount, 2, '.', ''),
            ];
        }
        return $out;
    }

    /* ══════════════════════════════════════════════════════════
     * 우리 입고 줄 — 같은 거래처·같은 달
     * ══════════════════════════════════════════════════════════ */

    private const SQL_OURS = <<<'SQL'
        SELECT l.id, l.item_id, i.code, i.name,
               CAST(l.qty AS text)        AS qty,
               CAST(l.unit_price AS text) AS unit_price,
               CAST(l.amount AS text)     AS amount,
               d.doc_no, d.branch_id, CAST(d.doc_date AS text) AS doc_date
          FROM p_doc_lines l
          JOIN p_docs      d ON d.id = l.doc_id
          JOIN p_items     i ON i.id = l.item_id
         WHERE d.doc_type = 'gr'
           AND d.status  <> 'void'
           AND l.status  <> 'void'
           AND d.vendor_id = CAST(:vendor AS bigint)
           AND to_char(d.doc_date, 'YYYY-MM') = CAST(:month AS char(7))
           AND
        SQL;

    private const SQL_OURS_TAIL = <<<'SQL'
         ORDER BY i.name ASC, l.id ASC
         LIMIT 5000
        SQL;

    /**
     * 우리 입고 줄 — **지점 격리를 지난다** (`$scopeD` 는 `d` 별칭 기준, SECURITY R-65).
     * requester 는 자기 지점 입고만 보이므로, 그 계정의 대사는 자기 지점만 맞는다.
     *
     * @param array{sql:string,params:array<string,mixed>} $scopeD
     * @return list<array<string,mixed>>
     */
    public static function ourLines(Db $db, array $scopeD, int $vendorId, string $month): array
    {
        return $db->run(
            self::SQL_OURS . ' ' . $scopeD['sql'] . ' ' . self::SQL_OURS_TAIL,
            [':vendor' => $vendorId, ':month' => $month] + $scopeD['params']
        )->fetchAll();
    }

    private const SQL_STATEMENT = <<<'SQL'
        SELECT s.id, s.vendor_id, s.month, s.file_id, s.status, s.created_by,
               CAST(s.mapping_json AS text) AS mapping_json,
               CAST(s.total_amount AS text) AS total_amount,
               s.created_at,
               v.name AS vendor_name,
               f.original_name, f.ext, f.stored_name,
               (SELECT count(*) FROM p_recon_lines rl WHERE rl.statement_id = s.id) AS line_count
          FROM p_recon_statements s
          JOIN p_vendors v ON v.id = s.vendor_id
          JOIN files     f ON f.id = s.file_id
         WHERE s.id = CAST(:id AS bigint)
        SQL;

    /**
     * 명세서 한 장 — 파일·거래처와 함께 읽는다.
     *
     * 지점 조건이 없는 이유: 명세서는 **거래처**에 묶이고, 한 장에 여러 지점 입고가 섞여
     * 있는 것이 정상이다(시안 Recon 의 「지점」 열). 지점 격리는 **대사에 들어가는 우리
     * 입고 줄**(`ourLines`)에 걸리고, 이 창구에 닿는 자격은 라우트 정책이 정한다.
     *
     * @return array<string,mixed>|null
     */
    public static function statement(Db $db, int $id): ?array
    {
        $row = $db->run(self::SQL_STATEMENT, [':id' => $id])->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * 저장된 열 매핑을 읽는다 (확정 뒤 다시 볼 때·다음 달 재사용).
     *
     * @param mixed $stored p_recon_statements.mapping_json
     * @return array<string,int>
     */
    public static function mappingOf($stored): array
    {
        $out = [];
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (in_array($key, self::TARGETS, true) && is_int($value) && $value >= 0) {
                        $out[$key] = $value;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * 요청으로 들어온 매핑을 **허용 목록으로 좁힌다** (R-69 · SECURITY R-07).
     * 목록 밖 키가 하나라도 있으면 null — 요청 전체를 거부한다. 필수 열이 빠져도 null 이다.
     *
     * @param mixed $raw
     * @return array<string,int>|null
     */
    public static function cleanMapping($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !in_array($key, self::TARGETS, true)) {
                return null;   // 연락처·계좌 같은 열은 여기서 끝난다 (R-69)
            }
            if (is_string($value) && preg_match('/^[0-9]{1,3}$/', $value) === 1) {
                $value = (int) $value;
            }
            if (!is_int($value) || $value < 0 || $value > Sheet::SHEET_COLS) {
                return null;
            }
            $out[$key] = $value;
        }
        foreach (self::REQUIRED as $need) {
            if (!isset($out[$need])) {
                return null;
            }
        }
        return $out;
    }

    /* ══════════════════════════════════════════════════════════
     * 대조
     * ══════════════════════════════════════════════════════════ */

    /**
     * 이름 정규화 — 공백·괄호·따옴표·마침표를 지우고 소문자로. 「DSO GAUZE 2*2inch (10ea)」와
     * 「dso gauze 2*2inch」가 같은 물건으로 보이게 하는 최소한의 처리다.
     * **지어내지 않는다**: 글자를 바꾸지 않고 지우기만 한다.
     */
    public static function normalizeName(string $name): string
    {
        $out = preg_replace('/[\s\x{3000}]+/u', '', $name);
        $out = preg_replace('/[()\[\]{}“”"\'`.,\-_]/u', '', is_string($out) ? $out : $name);
        $out = is_string($out) ? $out : $name;
        return strtolower($out);
    }

    /**
     * 명세서 줄 ↔ 우리 입고 줄 (지시서 보충 1·2).
     *
     * @param list<array<string,mixed>> $theirs `parse()` 결과
     * @param list<array<string,mixed>> $ours   `ourLines()` 결과
     * @return array{lines: list<array<string,mixed>>, totals: array<string,string>,
     *               diff_counts: array<string,int>}
     */
    public static function match(array $theirs, array $ours): array
    {
        /* 우리 줄을 품목별로 묶는다 — 한 달에 같은 품목이 여러 번 들어오면 **합**이 대조 단위다 */
        $byItem = [];
        foreach ($ours as $row) {
            $itemId = (int) $row['item_id'];
            if (!isset($byItem[$itemId])) {
                $byItem[$itemId] = [
                    'item_id'  => $itemId,
                    'code'     => (string) $row['code'],
                    'name'     => (string) $row['name'],
                    'line_ids' => [],
                    'qty'      => 0,
                    'amount'   => 0.0,
                    'doc_nos'  => [],
                ];
            }
            $byItem[$itemId]['line_ids'][] = (int) $row['id'];
            $byItem[$itemId]['qty']       += Doc::milli((string) $row['qty']);
            $byItem[$itemId]['amount']    += $row['amount'] === null ? 0.0 : (float) $row['amount'];
            $byItem[$itemId]['doc_nos'][(string) $row['doc_no']] = true;
        }

        /* 찾아보기 두 벌 — 코드(우선)와 정규화한 이름 */
        $byCode = [];
        $byName = [];
        foreach ($byItem as $itemId => $g) {
            $code = self::squeeze($g['code']);
            if ($code !== '' && !isset($byCode[$code])) {
                $byCode[$code] = $itemId;
            }
            $key = self::normalizeName($g['name']);
            if ($key !== '' && !isset($byName[$key])) {
                $byName[$key] = $itemId;
            }
        }

        $lines  = [];
        $usedId = [];
        $counts = ['none' => 0, 'qty' => 0, 'price' => 0, 'missing_ours' => 0, 'missing_theirs' => 0];
        $sumTheirs = 0.0;
        $sumOurs   = 0.0;

        foreach ($theirs as $t) {
            $theirQty    = Doc::milli((string) $t['qty']);
            $theirAmount = $t['amount'] === null ? null : (float) $t['amount'];
            $theirPrice  = $t['unit_price'] === null ? null : (float) $t['unit_price'];
            if ($theirAmount !== null) {
                $sumTheirs += $theirAmount;
            }

            $itemId = null;
            $how    = null;
            $code   = $t['item_code'] === null ? '' : self::squeeze((string) $t['item_code']);
            if ($code !== '' && isset($byCode[$code])) {
                $itemId = $byCode[$code];
                $how    = 'code';
            } else {
                $key = self::normalizeName((string) $t['item_name']);
                if ($key !== '' && isset($byName[$key])) {
                    $itemId = $byName[$key];
                    $how    = 'name';
                }
            }

            if ($itemId === null || isset($usedId[$itemId])) {
                $counts['missing_ours']++;
                $lines[] = $t + [
                    'diff_type'       => 'missing_ours',
                    'matched_line_id' => null,
                    'our_qty'         => null,
                    'our_unit_price'  => null,
                    'our_amount'      => null,
                    'our_doc_no'      => null,
                    'amount_diff'     => $theirAmount === null ? null : number_format($theirAmount, 2, '.', ''),
                    'note'            => null,
                ];
                continue;
            }

            $g               = $byItem[$itemId];
            $usedId[$itemId] = true;
            $ourQty          = (int) $g['qty'];
            $ourAmount       = (float) $g['amount'];
            $sumOurs        += $ourAmount;
            $ourPrice        = $ourQty === 0 ? null : round($ourAmount / ($ourQty / 1000), 2);

            $diff = 'none';
            if ($ourQty !== $theirQty) {
                $diff = 'qty';
            } elseif ($theirPrice !== null && $ourPrice !== null
                      && abs($ourPrice - $theirPrice) > self::PRICE_EPS) {
                $diff = 'price';
            }
            $counts[$diff]++;

            $note = count($g['line_ids']) > 1
                ? (string) count($g['line_ids']) . '줄 합'
                : null;
            if ($how === 'name') {
                $note = $note === null ? '이름으로 대조' : $note . ' · 이름으로 대조';
            }

            $lines[] = $t + [
                'diff_type'       => $diff,
                // 여러 줄이 묶였으면 **첫 줄**을 대표로 남긴다(나머지는 note 의 「n줄 합」).
                'matched_line_id' => $g['line_ids'][0],
                'our_qty'         => Doc::qtyText($ourQty),
                'our_unit_price'  => $ourPrice === null ? null : number_format($ourPrice, 2, '.', ''),
                'our_amount'      => number_format($ourAmount, 2, '.', ''),
                'our_doc_no'      => implode(',', array_keys($g['doc_nos'])),
                'amount_diff'     => number_format(($theirAmount ?? 0.0) - $ourAmount, 2, '.', ''),
                'note'            => $note,
            ];
        }

        /* 우리 입고에만 있는 품목 — 명세서 줄이 아니므로 row_no 를 이어 붙인다 */
        $rowNo = count($lines);
        foreach ($byItem as $itemId => $g) {
            if (isset($usedId[$itemId])) {
                continue;
            }
            $rowNo++;
            $ourQty    = (int) $g['qty'];
            $ourAmount = (float) $g['amount'];
            $sumOurs  += $ourAmount;
            $ourPrice  = $ourQty === 0 ? null : round($ourAmount / ($ourQty / 1000), 2);
            $counts['missing_theirs']++;
            $lines[] = [
                'row_no'          => $rowNo,
                'item_code'       => $g['code'],
                'item_name'       => $g['name'],
                'qty'             => Doc::qtyText($ourQty),
                'unit_price'      => $ourPrice === null ? null : number_format($ourPrice, 2, '.', ''),
                'amount'          => number_format($ourAmount, 2, '.', ''),
                'diff_type'       => 'missing_theirs',
                'matched_line_id' => $g['line_ids'][0],
                'our_qty'         => Doc::qtyText($ourQty),
                'our_unit_price'  => $ourPrice === null ? null : number_format($ourPrice, 2, '.', ''),
                'our_amount'      => number_format($ourAmount, 2, '.', ''),
                'our_doc_no'      => implode(',', array_keys($g['doc_nos'])),
                'amount_diff'     => number_format(-$ourAmount, 2, '.', ''),
                'note'            => '명세서에 없음',
            ];
        }

        return [
            'lines'       => $lines,
            'diff_counts' => $counts,
            'totals'      => [
                'theirs' => number_format($sumTheirs, 2, '.', ''),
                'ours'   => number_format($sumOurs, 2, '.', ''),
                'diff'   => number_format($sumTheirs - $sumOurs, 2, '.', ''),
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════
     * 작은 도우미
     * ══════════════════════════════════════════════════════════ */

    /** 공백을 지우고 소문자로 — 코드·머리행 이름 견주기에만 쓴다 */
    public static function squeeze(string $value): string
    {
        $out = preg_replace('/[\s\x{3000}]+/u', '', $value);
        return strtolower(is_string($out) ? $out : $value);
    }

    /**
     * @param list<string>      $row
     * @param array<string,int> $mapping
     */
    private static function cell(array $row, array $mapping, string $target): ?string
    {
        if (!isset($mapping[$target])) {
            return null;
        }
        $idx   = (int) $mapping[$target];
        $value = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
        return $value === '' ? null : $value;
    }

    /** 「1,187,500」·「₩23,750」·「(50)」 같은 표기를 수로. 수가 아니면 null. */
    private static function number(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/u', '', $raw);
        if (!is_string($clean) || $clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        if (preg_match('/^-?[0-9]{1,12}(\.[0-9]{1,4})?$/', $clean) !== 1) {
            return null;
        }
        return (float) $clean;
    }

    /**
     * 글자 수로 자르기 — 이 서버에는 mbstring 이 없고 iconv 만 있다 (Audit.php 와 같은 사정).
     * DB 의 `length()` 제약(글자 수)과 같은 단위로 잘라야 저장에서 23514 가 나지 않는다.
     */
    private static function cut(string $value, int $maxChars): string
    {
        if (preg_match('//u', $value) !== 1) {
            // 올바른 UTF-8 이 아니면 바이트로 자르지 않는다 — 깨진 글자를 만들지 않기 위해서다.
            $value = (string) preg_replace('/[\x80-\xFF]/', '?', $value);
        }
        if (iconv_strlen($value, 'UTF-8') <= $maxChars) {
            return $value;
        }
        $cut = iconv_substr($value, 0, $maxChars, 'UTF-8');
        return is_string($cut) ? $cut : $value;
    }
}
