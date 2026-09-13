<?php
declare(strict_types=1);

/**
 * src/purchase/Structure.php — 「브랜드·지점·창고」 한 페이지의 **읽기와 저장** (24단계, PRD F-37)
 *
 * 무엇을 하나 (지시서 docs/purchase/09-stage24-directive.md)
 *   ① 이카운트 창고 내보내기(ESA005M)를 **창고 대기표**(`p_location_inbox`)로 넣는다(upsert).
 *   ② 브랜드 → 지점 → 연결된 창고 나무를 읽는다.
 *   ③ 미연결 창고마다 **추천 지점**을 계산한다(이름 해석 — 표시용 값일 뿐이다).
 *   ④ 사람이 고른 (창고, 지점) 짝을 **연결**한다 — 그때 비로소 `p_locations` 행이 생긴다.
 *   ⑤ 지점이 없는 창고를 **제외**하거나 되살린다(상태값만 바뀐다 — 행을 지우지 않는다).
 *
 * 왜 대기표인가 (지시서 1장)
 *   `p_locations.branch_id` 는 NOT NULL 이고 지점 격리(`Authz::purchaseScopeWhere`, R-65)·전표·
 *   재고·주간 사용량이 전부 그 열로 훑는다. 지점 없는 창고를 본 표에 넣으려면 그 열을 NULL
 *   허용으로 풀어야 하고, 그러면 격리·전표·현황 코드가 전부 NULL 을 다시 다뤄야 한다.
 *   대신 **연결되기 전의 창고는 본 표 밖**에 둔다 — 전표·재고 코드는 한 줄도 바뀌지 않는다.
 *
 * 이 파일이 하지 **않는** 것 (ARCH 4.1)
 *   · 권한·역할·지점 판정 — 라우트 정책 `purchase.master.write`(buyer·Admin, 재인증 대상)가
 *     이미 지났다. 이 파일에는 role·purchase_role·branch_id 비교가 한 글자도 없다.
 *   · 감사 기록 — 결과(건수·id 목록)만 돌려주고 `Audit::allow` 는 핸들러가 **한 줄** 남긴다.
 *   · HTTP 응답 — 실패는 예외로 알리고, 예외 메시지는 **감사 로그용 사유 코드**다
 *     (`Master`·`Upload` 와 같은 방식). 사람에게 보일 문장은 아래 MSG_* 상수다.
 *   · 트랜잭션 열기 — 여는 곳은 핸들러다(감사 기록까지 한 트랜잭션에 묶기 위해서, PRD S-07).
 *
 * SQL 은 전부 **고정 문장**이다. 표·컬럼 이름이 변수로 들어가는 자리가 없고 값은 바인딩뿐이다.
 */
final class Structure
{
    /** 한 번에 연결·제외할 수 있는 최대 건수 (지시서 4장) */
    public const MAX_BATCH = 200;

    /**
     * 사람에게 보이는 오류 문장 (지시서 5장).
     *
     * 다른 화면들은 400 을 **동일 문구** 하나로 돌려준다(PRD S-09-5 — 사유는 감사 로그에만).
     * 이 다섯만 예외적으로 "무엇이 막았는지" 를 알려 주는 이유는, 여기까지 온 사람이
     * buyer·Admin 이고 **전 지점의 기초정보를 이미 다 보는 사람**이라 새어 나갈 정보가 없고,
     * 이유를 모르면 다음에 무엇을 해야 하는지 알 수 없기 때문이다
     * (`MSG_FOLDER_NOT_EMPTY`·`MSG_SENSITIVE_LEVEL_REQUIRED` 와 같은 성격).
     * 값은 **코드에 적힌 고정 문자열**이며 요청 값이 섞이지 않는다.
     */
    public const MSG_BAD_HEADER    = '창고코드·창고명 열을 찾지 못했습니다 — 이카운트 창고 내보내기 파일인지 확인하세요';
    public const MSG_CONFLICT      = '그 지점에 같은 이름의 창고가 이미 있어 이번 묶음은 저장하지 않았습니다';
    public const MSG_NOT_UNLINKED  = '이미 연결되었거나 제외된 창고가 섞여 있습니다 — 목록을 다시 읽습니다';
    public const MSG_NOT_EXCLUDED  = '제외 상태가 아닌 창고가 섞여 있습니다 — 목록을 다시 읽습니다';
    public const MSG_BAD_REFERENCE = '중단되었거나 없는 지점입니다';

    /**
     * 「사용」 칸이 **참**으로 읽히는 값 (대소문자 무시. 그 밖은 거짓).
     * `Importer` 의 같은 목록은 private 이라 여기서 한 번 더 적는다 — 두 표가 갈라지지 않게
     * 값을 **똑같이** 유지한다(19단계 ACTIVE_TRUE).
     *
     * @var list<string>
     */
    private const ACTIVE_TRUE = ['yes', 'y', 'true', '1', 'o', '사용', '사용함', '예'];

    /**
     * 이카운트 창고 내보내기의 「추가사업장명」 열 이름.
     *
     * 나머지 네 열(창고코드·창고명·구분·사용)은 `Importer` 의 **동의어 표에서 읽어** 쓴다
     * (아래 columns()). 이 열만 여기 적는 이유는, 우리 칸에 대응이 없어 그 표에서
     * `skip` 으로 표시돼 있어 이름으로 되찾을 수 없기 때문이다. 표시용 값이다.
     */
    private const COLUMN_SITE = '추가사업장명';

    /** 「구분」 원문 → 장소 용도. 표에 없으면 other 다 (지시서 완료 기준 (c)) */
    private const KIND_FROM_TEXT = [
        '창고'   => 'warehouse',
        '중앙'   => 'central',
        '진료실' => 'or',
    ];

    /* ══════════════════════════════════════════════════════════════════
     * ① 파일 → 대기표 (upsert)
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * 머리행을 찾고 **열 번호**를 정한다.
     *
     * 머리행 감지와 동의어 해석은 19단계 것을 그대로 쓴다(`Importer`) — 이카운트 파일의
     * 0행이 「회사명 : …」 한 칸인 문제를 이미 푼 코드이고, 동의어 표를 여기 복사하면
     * 언젠가 두 표가 갈라진다.
     *
     * @param list<list<string>> $rows
     * @return array{header_row:int, code:int, name:int, kind:?int, active:?int, site:?int}
     * @throws RuntimeException `bad_header` — 창고코드·창고명 열이 없다
     */
    public static function columns(array $rows): array
    {
        $headerRow = Importer::headerRowFrom(null, 'locations', $rows);
        $header    = isset($rows[$headerRow]) && is_array($rows[$headerRow]) ? $rows[$headerRow] : [];

        try {
            // guessMapping {엑셀 열 이름 → 우리 칸} → resolve {우리 칸 → 열 번호}
            $resolved = Importer::resolve('locations', $header, Importer::guessMapping('locations', $header));
        } catch (RuntimeException $e) {
            throw new RuntimeException('bad_header');
        }
        if (!array_key_exists('code', $resolved) || !array_key_exists('name', $resolved)) {
            throw new RuntimeException('bad_header');
        }

        $site = null;
        foreach ($header as $index => $label) {
            if (trim((string) $label) === self::COLUMN_SITE) {
                $site = (int) $index;
                break;
            }
        }

        return [
            'header_row' => $headerRow,
            'code'       => (int) $resolved['code'],
            'name'       => (int) $resolved['name'],
            'kind'       => array_key_exists('kind', $resolved) ? (int) $resolved['kind'] : null,
            'active'     => array_key_exists('active', $resolved) ? (int) $resolved['active'] : null,
            'site'       => $site,
        ];
    }

    /**
     * 대기표에 넣는다 — 코드가 없으면 INSERT, 있으면 **달라진 것이 있을 때만** UPDATE.
     *
     * 규칙 (지시서 3장)
     *   · 같은 파일을 다시 올리면 `unchanged` 만 늘고 행은 그대로다(코드 기준 멱등).
     *   · UPDATE 는 name·kind_text·site_name·ecount_active 만 건드리고 **status 는 바꾸지 않는다**
     *     — 이미 연결했거나 제외한 판단을 파일이 되돌리지 못하게 하려는 것이다.
     *   · 이미 연결된 행의 이름이 바뀌어도 `p_locations.name` 은 건드리지 않는다. 몇 건인지만
     *     알려 주고(`renamed_linked`) 사람이 판단한다.
     *   · 건너뛰는 행: 코드·이름이 빈 행, 꼬리행(코드 칸이 날짜꼴), 나머지 칸이 전부 빈 행,
     *     이름·코드가 표의 길이 제한(80·40자)을 넘는 행.
     *
     * 트랜잭션은 **호출자(핸들러)가** 연다.
     *
     * @param list<list<string>> $rows Sheet::rows 의 결과
     * @return array{inserted:int,updated:int,unchanged:int,skipped:int,renamed_linked:int}
     */
    public static function ingest(array $rows, int $fileId, int $actorId): array
    {
        $col = self::columns($rows);
        $db  = Db::instance();

        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'renamed_linked' => 0];

        $total = count($rows);
        for ($i = $col['header_row'] + 1; $i < $total; $i++) {
            $row  = is_array($rows[$i]) ? $rows[$i] : [];
            $code = trim((string) ($row[$col['code']] ?? ''));
            $name = trim((string) ($row[$col['name']] ?? ''));

            if (!self::isDataRow($code, $name)) {
                $counts['skipped']++;
                continue;
            }

            $kindText = $col['kind'] === null ? null : self::textOrNull((string) ($row[$col['kind']] ?? ''));
            $siteName = $col['site'] === null ? null : self::textOrNull((string) ($row[$col['site']] ?? ''));
            $active   = $col['active'] === null ? true : self::activeFrom((string) ($row[$col['active']] ?? ''));

            // lint:no-branch-scope p_location_inbox 는 **지점 축이 없는 마스터**다 (연결 전의 창고).
            $found = $db->run(self::SQL_INBOX_BY_CODE, [':code' => $code])->fetch();

            if (!is_array($found)) {
                $db->run(self::SQL_INBOX_INSERT, [
                    ':code'       => $code,
                    ':name'       => $name,
                    ':kind_text'  => $kindText,
                    ':site_name'  => $siteName,
                    ':active'     => $active ? 'true' : 'false',
                    ':file_id'    => $fileId,
                    ':created_by' => $actorId,
                ]);
                $counts['inserted']++;
                continue;
            }

            $same = (string) $found['name'] === $name
                && self::textOrNull((string) ($found['kind_text'] ?? '')) === $kindText
                && self::textOrNull((string) ($found['site_name'] ?? '')) === $siteName
                && (($found['ecount_active'] === true) === $active);

            if ($same) {
                $counts['unchanged']++;
                continue;
            }

            $db->run(self::SQL_INBOX_UPDATE, [
                ':id'        => (int) $found['id'],
                ':name'      => $name,
                ':kind_text' => $kindText,
                ':site_name' => $siteName,
                ':active'    => $active ? 'true' : 'false',
                ':file_id'   => $fileId,
            ]);
            $counts['updated']++;
            if ((string) $found['status'] === 'linked' && (string) $found['name'] !== $name) {
                $counts['renamed_linked']++;
            }
        }

        return $counts;
    }

    /**
     * 데이터 행인가 — 코드·이름이 **둘 다** 있고, 꼬리행이 아니고, 표의 길이 제한 안인가.
     *
     * 꼬리행(이카운트가 마지막 줄에 한 칸으로 넣는 「2026/09/13  오전 4:56:43」)과 빈 행·
     * 제목 줄은 이름 칸이 비어 있어 여기서 걸린다. 그래도 날짜꼴 코드를 따로 보는 이유는,
     * 열 수가 적은 파일에서 그 한 칸이 이름 열에 걸릴 수 있기 때문이다.
     */
    private static function isDataRow(string $code, string $name): bool
    {
        if ($code === '' || $name === '') {
            return false;
        }
        if (preg_match('#^[0-9]{4}[-/.][0-9]{1,2}[-/.][0-9]{1,2}#', $code) === 1) {
            return false;
        }
        // 표의 길이 제약(CHECK 80·40)을 넘는 값은 넣지 않는다 — 자르면 원문이 아니게 된다.
        return iconv_strlen($name, 'UTF-8') <= 80 && iconv_strlen($code, 'UTF-8') <= 40;
    }

    /** 표시용 문자열 한 칸 — 비어 있으면 null, 200자를 넘으면 자른다 */
    private static function textOrNull(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (iconv_strlen($value, 'UTF-8') > 200) {
            $cut = iconv_substr($value, 0, 200, 'UTF-8');
            return is_string($cut) ? $cut : $value;
        }
        return $value;
    }

    /** 「사용」 Yes/No — 빈 칸은 사용 중으로 본다(열이 없는 파일과 같은 취급) */
    private static function activeFrom(string $raw): bool
    {
        $value = trim($raw);
        if ($value === '') {
            return true;
        }
        // `strtolower` 는 ASCII 만 내린다 — 한글은 대소문자가 없으므로 그것으로 충분하고,
        // 이 서버에는 mbstring 확장이 없다(Importer 도 같은 함수를 쓴다).
        return in_array(strtolower($value), self::ACTIVE_TRUE, true);
    }

    /* ══════════════════════════════════════════════════════════════════
     * ② 나무 · ③ 대기표와 추천
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * 브랜드 → 지점 → **연결된 창고** 나무.
     *
     * 중단된 브랜드·지점·창고도 `is_active=false` 로 함께 내려보낸다(화면이 흐리게 그린다) —
     * 중단된 지점 아래에 창고가 남아 있는 것을 사람이 봐야 정리할 수 있다.
     *
     * @return array{brands:list<array<string,mixed>>, branches:int, locations:int}
     */
    public static function tree(): array
    {
        /* lint:no-branch-scope 전 지점을 한 화면에서 정리하는 **마스터 편집 화면**의 조회다.
         * 이 다섯 라우트는 전부 `purchase.master.write`(buyer·Admin 전용)이고, 그 둘의
         * 지점 범위는 언제나 전 지점(`1=1`)이라 좁힐 기준이 존재하지 않는다. 모듈 역할이
         * 넓어지는 날 이 표식을 지우고 `Authz::purchaseScopeWhere` 를 붙이는 것이 검토 지점이다. */
        $rows = Db::instance()->run(self::SQL_TREE)->fetchAll();

        $brands     = [];
        $order      = [];
        $branchSeen = [];
        $locations  = 0;

        foreach ($rows as $r) {
            $brandId = (int) $r['brand_id'];
            if (!array_key_exists($brandId, $brands)) {
                $brands[$brandId] = [
                    'id'        => $brandId,
                    'name'      => (string) $r['brand_name'],
                    'is_active' => $r['brand_active'] === true,
                    'branches'  => [],
                ];
                $order[] = $brandId;
            }
            if ($r['branch_id'] === null) {
                continue;
            }
            $branchId = (int) $r['branch_id'];
            if (!array_key_exists($branchId, $branchSeen)) {
                $branchSeen[$branchId] = true;
                $brands[$brandId]['branches'][$branchId] = [
                    'id'        => $branchId,
                    'name'      => (string) $r['branch_name'],
                    'code'      => $r['branch_code'] === null ? null : (string) $r['branch_code'],
                    'is_active' => $r['branch_active'] === true,
                    'locations' => [],
                ];
            }
            if ($r['location_id'] === null) {
                continue;
            }
            $locations++;
            $brands[$brandId]['branches'][$branchId]['locations'][] = [
                'id'        => (int) $r['location_id'],
                'name'      => (string) $r['location_name'],
                'kind'      => (string) $r['location_kind'],
                'code'      => $r['location_code'] === null ? null : (string) $r['location_code'],
                'is_active' => $r['location_active'] === true,
                'inbox_id'  => $r['inbox_id'] === null ? null : (int) $r['inbox_id'],
            ];
        }

        $out = [];
        foreach ($order as $brandId) {
            $brand             = $brands[$brandId];
            $brand['branches'] = array_values($brand['branches']);
            $out[]             = $brand;
        }

        return ['brands' => $out, 'branches' => count($branchSeen), 'locations' => $locations];
    }

    /**
     * 대기표 — 미연결(추천 포함)·제외 목록과 건수.
     *
     * @return array{unlinked:list<array<string,mixed>>, excluded:list<array<string,mixed>>, counts:array<string,int>}
     */
    public static function inbox(): array
    {
        $db = Db::instance();

        // lint:no-branch-scope p_location_inbox 는 지점 축이 없는 마스터다 (위 tree() 와 같은 이유).
        $rows = $db->run(self::SQL_INBOX_LIST)->fetchAll();

        // lint:no-branch-scope 추천에 쓸 **활성 지점 이름표** — 같은 화면이 이미 전부 보는 값이다.
        $branches = $db->run(self::SQL_ACTIVE_BRANCHES)->fetchAll();

        $index = self::branchIndex($branches);

        $unlinked = [];
        $excluded = [];
        foreach ($rows as $r) {
            $item = [
                'id'            => (int) $r['id'],
                'ecount_code'   => (string) $r['ecount_code'],
                'name'          => (string) $r['name'],
                'kind_text'     => $r['kind_text'] === null ? null : (string) $r['kind_text'],
                'site_name'     => $r['site_name'] === null ? null : (string) $r['site_name'],
                'ecount_active' => $r['ecount_active'] === true,
            ];
            if ((string) $r['status'] === 'excluded') {
                $excluded[] = $item;
                continue;
            }
            $guess          = self::suggest((string) $r['name'], $index);
            $item['suggest'] = $guess['suggest'];
            if ($guess['why'] !== null) {
                $item['why'] = $guess['why'];
            }
            $unlinked[] = $item;
        }

        // lint:no-branch-scope 상태별 건수 — 위 목록과 같은 표, 같은 이유로 지점 축이 없다.
        $counts = $db->run(self::SQL_INBOX_COUNTS)->fetch();

        return [
            'unlinked' => $unlinked,
            'excluded' => $excluded,
            'counts'   => [
                'unlinked' => is_array($counts) ? (int) $counts['unlinked'] : 0,
                'linked'   => is_array($counts) ? (int) $counts['linked'] : 0,
                'excluded' => is_array($counts) ? (int) $counts['excluded'] : 0,
            ],
        ];
    }

    /**
     * 지점 이름표 — {정규화한 지점 이름 → [ {branch_id, brand_id, brand_key} … ]}.
     *
     * 정규화는 **추천 계산 안에서만** 한다(trim + 소문자). 저장되는 이름은 언제나 원문이다.
     *
     * @param list<array<string,mixed>> $branches
     * @return array<string,list<array<string,mixed>>>
     */
    private static function branchIndex(array $branches): array
    {
        $index = [];
        foreach ($branches as $b) {
            $key = self::normalize((string) $b['name']);
            if ($key === '') {
                continue;
            }
            $index[$key][] = [
                'branch_id' => (int) $b['id'],
                'brand_id'  => (int) $b['brand_id'],
                'brand_key' => self::normalize((string) $b['brand_name']),
            ];
        }
        return $index;
    }

    private static function normalize(string $raw): string
    {
        // ASCII 대소문자만 내린다(한글은 대소문자가 없다). mbstring 확장에 기대지 않는다.
        return strtolower(trim($raw));
    }

    /**
     * 창고명에서 **추천 지점**을 고른다 (지시서 4장).
     *
     *   ① 창고명을 `_`·공백으로 잘라 토큰을 만들고, 창고명 전체도 토큰 하나로 본다
     *      (「라미트치과」 지점이 있으면 같은 이름의 창고가 그 지점이다).
     *   ② 활성 지점 이름과 **정확히** 같은 토큰이 있으면 그 지점.
     *   ③ 후보가 둘 이상이면 그중 **앞 토큰이 그 지점의 브랜드 이름과 같은 것**을 고른다.
     *   ④ 그래도 둘 이상이면 추천 없음(`why: ambiguous`).
     *
     * 추천은 **표시용 값**이다. 연결은 요청이 보낸 `branch_id` 로만 하고, 이 함수의 결과는
     * 어떤 저장에도 쓰이지 않는다.
     *
     * @param array<string,list<array<string,mixed>>> $index
     * @return array{suggest:?array<string,mixed>, why:?string}
     */
    public static function suggest(string $name, array $index): array
    {
        $parts = preg_split('/[\s_]+/u', trim($name));
        $parts = is_array($parts) ? $parts : [];
        $tokens = [];
        foreach ($parts as $part) {
            $key = self::normalize((string) $part);
            if ($key !== '') {
                $tokens[] = $key;
            }
        }
        $whole = self::normalize($name);
        if ($whole !== '' && !in_array($whole, $tokens, true)) {
            $tokens[] = $whole;
        }

        $found = [];
        foreach ($tokens as $token) {
            foreach ($index[$token] ?? [] as $hit) {
                $found[$hit['branch_id']] = $hit;
            }
        }

        if ($found === []) {
            return ['suggest' => null, 'why' => 'no_match'];
        }
        if (count($found) === 1) {
            return ['suggest' => self::asSuggest(reset($found)), 'why' => null];
        }

        // ③ 앞 토큰(= 보통 브랜드 자리)이 그 지점의 브랜드 이름과 같은 후보
        $front  = $tokens[0] ?? '';
        $narrow = [];
        foreach ($found as $hit) {
            if ($front !== '' && $hit['brand_key'] === $front) {
                $narrow[$hit['branch_id']] = $hit;
            }
        }
        if (count($narrow) === 1) {
            return ['suggest' => self::asSuggest(reset($narrow)), 'why' => null];
        }
        return ['suggest' => null, 'why' => 'ambiguous'];
    }

    /** @param array<string,mixed> $hit */
    private static function asSuggest(array $hit): array
    {
        return [
            'branch_id' => (int) $hit['branch_id'],
            'brand_id'  => (int) $hit['brand_id'],
            'by'        => 'name',
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * ④ 연결 · ⑤ 제외
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * 요청 본문의 `links` 를 좁힌다 — 1~200건, 키는 둘뿐, 같은 창고가 두 번 오면 거부.
     *
     * @return list<array{inbox_id:int,branch_id:int}>
     * @throws RuntimeException `bad_request`
     */
    public static function normalizeLinks(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_BATCH) {
            throw new RuntimeException('bad_request');
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $one) {
            if (!is_array($one) || array_diff(array_keys($one), ['inbox_id', 'branch_id']) !== []) {
                throw new RuntimeException('bad_request');
            }
            $inboxId  = Master::id($one['inbox_id'] ?? null);
            $branchId = Master::id($one['branch_id'] ?? null);
            if (array_key_exists($inboxId, $seen)) {
                throw new RuntimeException('bad_request');
            }
            $seen[$inboxId] = true;
            $out[] = ['inbox_id' => $inboxId, 'branch_id' => $branchId];
        }
        return $out;
    }

    /**
     * 요청 본문의 `inbox_ids` 를 좁힌다 — 1~200건, 정수, 중복 금지.
     *
     * @return list<int>
     * @throws RuntimeException `bad_request`
     */
    public static function normalizeIds(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > self::MAX_BATCH) {
            throw new RuntimeException('bad_request');
        }
        $out  = [];
        $seen = [];
        foreach ($raw as $one) {
            $id = Master::id($one);
            if (array_key_exists($id, $seen)) {
                throw new RuntimeException('bad_request');
            }
            $seen[$id] = true;
            $out[]     = $id;
        }
        return $out;
    }

    /**
     * 연결 — 대기표의 창고를 지점에 붙인다. **한 트랜잭션**(호출자가 연다)이고 한 건이라도
     * 실패하면 전부 되돌아간다.
     *
     * 각 행을 `FOR UPDATE` 로 잠그고 status=unlinked 를 확인한 뒤(`not_unlinked`),
     * 지점이 있고 활성인지 보고(`bad_reference`), `p_locations` 행을 만든다.
     * 같은 지점에 같은 이름이 이미 있으면 유일 인덱스가 막고 `conflict` 로 전체가 롤백된다.
     *
     * @param list<array{inbox_id:int,branch_id:int}> $links
     * @param string|null $kind 요청이 정한 용도(없으면 「구분」 원문에서 유추)
     * @return array{links:int, branch_ids:list<int>, location_ids:list<int>}
     * @throws RuntimeException `not_unlinked` · `bad_reference` · `conflict`
     */
    public static function link(array $links, ?string $kind, int $actorId): array
    {
        $db          = Db::instance();
        $branchIds   = [];
        $locationIds = [];

        foreach ($links as $one) {
            // lint:no-branch-scope 대기표 행 잠그기 — 지점 축이 없는 마스터다 (위와 같은 이유).
            $row = $db->run(self::SQL_INBOX_LOCK, [':id' => $one['inbox_id']])->fetch();
            if (!is_array($row) || (string) $row['status'] !== 'unlinked') {
                throw new RuntimeException('not_unlinked');
            }

            // lint:no-branch-scope 지점이 **있고 활성인가**만 묻는다 — 결과는 응답에 나가지 않는다.
            $branch = $db->run(self::SQL_BRANCH_ACTIVE, [':id' => $one['branch_id']])->fetch();
            if (!is_array($branch)) {
                throw new RuntimeException('bad_reference');
            }

            $locationKind = $kind ?? self::kindFrom($row['kind_text'] === null ? '' : (string) $row['kind_text']);

            try {
                $made = $db->run(self::SQL_LOCATION_INSERT, [
                    ':branch_id'   => $one['branch_id'],
                    ':kind'        => $locationKind,
                    ':name'        => (string) $row['name'],
                    ':code'        => (string) $row['ecount_code'],
                    ':legacy_name' => (string) $row['name'],
                    ':is_active'   => $row['ecount_active'] === true ? 'true' : 'false',
                    ':created_by'  => $actorId,
                ])->fetch();
            } catch (PDOException $e) {
                // 23505 = unique_violation — 그 지점에 같은 이름의 창고가 이미 있다
                if ($e->getCode() === '23505') {
                    throw new RuntimeException('conflict');
                }
                // 23503 = foreign_key_violation — 확인과 INSERT 사이에 지점이 사라졌다
                if ($e->getCode() === '23503') {
                    throw new RuntimeException('bad_reference');
                }
                throw $e;
            }
            if (!is_array($made)) {
                throw new RuntimeException('insert_failed');
            }
            $locationId = (int) $made['id'];

            $db->run(self::SQL_INBOX_LINKED, [
                ':id'          => $one['inbox_id'],
                ':location_id' => $locationId,
                ':linked_by'   => $actorId,
            ]);

            $branchIds[]   = $one['branch_id'];
            $locationIds[] = $locationId;
        }

        return [
            'links'        => count($links),
            'branch_ids'   => array_values(array_unique($branchIds)),
            'location_ids' => $locationIds,
        ];
    }

    /** 「구분」 원문 → 장소 용도 (표에 없으면 other) */
    public static function kindFrom(string $kindText): string
    {
        $value = trim($kindText);
        return self::KIND_FROM_TEXT[$value] ?? 'other';
    }

    /**
     * 제외 / 되살리기 — 상태값만 바꾼다. **행을 지우지 않는다**(앱에 DELETE 권한이 없다).
     *
     * 누가 바꿨는지는 감사 로그가 남긴다 — 그러자고 표에 칸을 늘리지 않는다.
     *
     * @param list<int> $ids
     * @return int 바뀐 건수
     * @throws RuntimeException `not_unlinked`(제외) · `not_excluded`(되살리기)
     */
    public static function exclude(array $ids, bool $excluded): int
    {
        $db   = Db::instance();
        $from = $excluded ? 'unlinked' : 'excluded';
        $to   = $excluded ? 'excluded' : 'unlinked';

        foreach ($ids as $id) {
            // lint:no-branch-scope 대기표 행 잠그기 — 지점 축이 없는 마스터다.
            $row = $db->run(self::SQL_INBOX_LOCK, [':id' => $id])->fetch();
            if (!is_array($row) || (string) $row['status'] !== $from) {
                throw new RuntimeException($excluded ? 'not_unlinked' : 'not_excluded');
            }
            $db->run(self::SQL_INBOX_STATUS, [':id' => $id, ':status' => $to]);
        }

        return count($ids);
    }

    /* ══════════════════════════════════════════════════════════════════
     * 고정 SQL — 표·컬럼 이름이 변수로 들어가는 자리가 없고 값은 전부 바인딩이다.
     * ══════════════════════════════════════════════════════════════════ */

    private const SQL_INBOX_BY_CODE = <<<'SQL'
        SELECT id, name, kind_text, site_name, ecount_active, status
          FROM p_location_inbox
         WHERE ecount_code = :code
        SQL;

    private const SQL_INBOX_INSERT = <<<'SQL'
        INSERT INTO p_location_inbox
            (ecount_code, name, kind_text, site_name, ecount_active, status, source_file_id, created_by)
        VALUES
            (:code, :name, :kind_text, :site_name, CAST(:active AS boolean), 'unlinked',
             CAST(:file_id AS bigint), :created_by)
        SQL;

    private const SQL_INBOX_UPDATE = <<<'SQL'
        UPDATE p_location_inbox
           SET name           = :name,
               kind_text      = :kind_text,
               site_name      = :site_name,
               ecount_active  = CAST(:active AS boolean),
               source_file_id = CAST(:file_id AS bigint),
               updated_at     = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    private const SQL_INBOX_LIST = <<<'SQL'
        SELECT id, ecount_code, name, kind_text, site_name, ecount_active, status
          FROM p_location_inbox
         WHERE status IN ('unlinked', 'excluded')
         ORDER BY status ASC, ecount_code ASC
         LIMIT 500
        SQL;

    private const SQL_INBOX_COUNTS = <<<'SQL'
        SELECT count(*) FILTER (WHERE status = 'unlinked') AS unlinked,
               count(*) FILTER (WHERE status = 'linked')   AS linked,
               count(*) FILTER (WHERE status = 'excluded') AS excluded
          FROM p_location_inbox
        SQL;

    private const SQL_INBOX_LOCK = <<<'SQL'
        SELECT id, ecount_code, name, kind_text, ecount_active, status
          FROM p_location_inbox
         WHERE id = CAST(:id AS bigint)
           FOR UPDATE
        SQL;

    private const SQL_INBOX_LINKED = <<<'SQL'
        UPDATE p_location_inbox
           SET status      = 'linked',
               location_id = CAST(:location_id AS bigint),
               linked_by   = :linked_by,
               linked_at   = now(),
               updated_at  = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    private const SQL_INBOX_STATUS = <<<'SQL'
        UPDATE p_location_inbox
           SET status = :status, updated_at = now()
         WHERE id = CAST(:id AS bigint)
        SQL;

    private const SQL_BRANCH_ACTIVE = <<<'SQL'
        SELECT 1 AS ok FROM p_branches WHERE id = CAST(:id AS bigint) AND is_active
        SQL;

    private const SQL_LOCATION_INSERT = <<<'SQL'
        INSERT INTO p_locations (branch_id, kind, name, code, legacy_name, is_active, created_by)
        VALUES (CAST(:branch_id AS bigint), :kind, :name, :code, :legacy_name,
                CAST(:is_active AS boolean), :created_by)
        RETURNING id
        SQL;

    private const SQL_ACTIVE_BRANCHES = <<<'SQL'
        SELECT br.id, br.name, br.brand_id, b.name AS brand_name
          FROM p_branches br
          JOIN p_brands  b ON b.id = br.brand_id
         WHERE br.is_active
         ORDER BY br.id ASC
         LIMIT 500
        SQL;

    private const SQL_TREE = <<<'SQL'
        SELECT b.id   AS brand_id,   b.name  AS brand_name,  b.is_active  AS brand_active,
               br.id  AS branch_id,  br.name AS branch_name, br.code      AS branch_code,
               br.is_active AS branch_active,
               l.id   AS location_id, l.name AS location_name, l.kind     AS location_kind,
               l.code AS location_code, l.is_active AS location_active,
               i.id   AS inbox_id
          FROM p_brands b
          LEFT JOIN p_branches  br ON br.brand_id = b.id
          LEFT JOIN p_locations l  ON l.branch_id = br.id
          LEFT JOIN p_location_inbox i ON i.location_id = l.id
         ORDER BY b.name ASC, br.name ASC, l.name ASC
         LIMIT 5000
        SQL;
}
