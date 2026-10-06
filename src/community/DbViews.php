<?php
declare(strict_types=1);

/** Stage 11 view shapes and SQL fragments. All user keys are bound values. */
final class DbViews
{
    public const FORM_TYPES = ['text', 'number', 'checkbox', 'url', 'email', 'phone', 'date', 'select', 'multi_select'];
    public const BASE_FIELDS = ['name', 'type', 'filter', 'sort', 'hidden'];
    public static function viewFields(string $type): array
    {
        $fields = [
            'form' => ['questions', 'intro', 'audience', 'anonymous', 'open', 'notify', 'public'],
            'calendar' => ['date_prop', 'mode'],
            'timeline' => ['date_prop', 'depends_prop', 'scale'],
            'chart' => ['kind', 'group_by', 'bucket', 'aggregate', 'on_prop'],
            // Q:F-12 (ARCH 23.5) — 보드·목록을 섹션(또는 status · 다른 select 칸)으로 묶는다
            'board' => ['group_by'],
            'list' => ['group_by'],
        ];
        return array_merge(self::BASE_FIELDS, $fields[$type] ?? []);
    }
    public static function dateProp(string $key, array $schema): bool
    {
        return $key === 'due' || (DbProps::find($schema, $key)['type'] ?? null) === 'date';
    }
    public static function cleanViewOptions(array $raw, array $schema): ?array
    {
        $type = $raw['type'] ?? '';
        if ($type === 'form') {
            $qs = $raw['questions'] ?? null;
            if (!is_array($qs) || !array_is_list($qs) || count($qs) < 1 || count($qs) > 30) { return null; }
            $out = [];
            $seen = [];
            foreach ($qs as $q) {
                if (!is_array($q) || array_diff(array_keys($q), ['prop', 'required', 'show_if']) !== []
                    || !is_string($q['prop'] ?? null) || !is_bool($q['required'] ?? null) || isset($seen[$q['prop']])) { return null; }
                $prop = $q['prop'] === 'title' ? ['key' => 'title', 'name' => '제목', 'type' => 'text'] : DbProps::find($schema, $q['prop']);
                if ($prop === null || !in_array($prop['type'], self::FORM_TYPES, true)) { return null; }
                $one = ['prop' => $q['prop'], 'required' => $q['required']];
                if (array_key_exists('show_if', $q)) {
                    $condition = $q['show_if'];
                    if (!is_array($condition) || array_diff(array_keys($condition), ['prop', 'equals']) !== []
                        || !is_string($condition['prop'] ?? null) || !array_key_exists('equals', $condition)
                        || !isset($seen[$condition['prop']]) || isset($seen[$condition['prop']]['conditional'])) { return null; }
                    $parent = $seen[$condition['prop']];
                    if (!in_array($parent['type'], ['select', 'checkbox'], true)) { return null; }
                    [$ok, $clean] = DbProps::cleanValue($parent, $condition['equals']);
                    if (!$ok || $clean === null) { return null; }
                    $one['show_if'] = ['prop' => $condition['prop'], 'equals' => $clean];
                    $prop['conditional'] = true;
                }
                $out[] = $one;
                $seen[$q['prop']] = $prop;
            }
            $intro = $raw['intro'] ?? '';
            if (!is_string($intro) || preg_match('//u', $intro) !== 1 || iconv_strlen($intro, 'UTF-8') > 500
                || preg_match('/[^\P{C}\n]/u', $intro) === 1 || !in_array($raw['audience'] ?? 'readers', ['readers', 'internal'], true)) { return null; }
            foreach (['anonymous', 'open', 'notify', 'public'] as $key) {
                if (array_key_exists($key, $raw) && !is_bool($raw[$key])) { return null; }
            }
            return ['questions' => $out, 'intro' => $intro, 'audience' => $raw['audience'] ?? 'readers',
                'anonymous' => $raw['anonymous'] ?? false, 'open' => $raw['open'] ?? false, 'notify' => $raw['notify'] ?? true, 'public' => $raw['public'] ?? false];
        }
        if ($type === 'calendar' || $type === 'timeline') {
            $key = $raw['date_prop'] ?? null;
            if (!is_string($key) || !self::dateProp($key, $schema)) { return null; }
            $out = ['date_prop' => $key];
            if ($type === 'calendar') {
                if (!in_array($raw['mode'] ?? 'month', ['month', 'week'], true)) { return null; }
                $out['mode'] = $raw['mode'] ?? 'month';
            } else {
                if (!in_array($raw['scale'] ?? 'month', ['month', 'week'], true)) { return null; }
                $out['scale'] = $raw['scale'] ?? 'month';
                if (isset($raw['depends_prop'])) {
                    if (!is_string($raw['depends_prop'])) { return null; }
                    $prop = DbProps::find($schema, $raw['depends_prop']);
                    if (($prop['type'] ?? '') !== 'relation' || ($prop['depends'] ?? false) !== true) { return null; }
                    $out['depends_prop'] = $raw['depends_prop'];
                }
            }
            return $out;
        }
        if ($type === 'chart') {
            if (!in_array($raw['kind'] ?? null, ['bar', 'line', 'donut', 'number'], true)
                || !in_array($raw['aggregate'] ?? null, ['count', 'sum', 'avg'], true)) { return null; }
            $out = ['kind' => $raw['kind'], 'aggregate' => $raw['aggregate']];
            if (isset($raw['on_prop'])) {
                if (!is_string($raw['on_prop']) || (DbProps::find($schema, $raw['on_prop'])['type'] ?? null) !== 'number') { return null; }
                $out['on_prop'] = $raw['on_prop'];
            }
            if ($raw['aggregate'] !== 'count' && !isset($out['on_prop'])) { return null; }
            if (isset($raw['group_by'])) {
                if (!is_string($raw['group_by'])) { return null; }
                $kind = self::groupKind($raw['group_by'], $schema);
                if ($kind === null) { return null; }
                $out['group_by'] = $raw['group_by'];
                if ($kind === 'date') {
                    if (!in_array($raw['bucket'] ?? 'month', ['day', 'week', 'month'], true)) { return null; }
                    $out['bucket'] = $raw['bucket'] ?? 'month';
                } elseif (isset($raw['bucket'])) { return null; }
            } elseif (isset($raw['bucket'])) { return null; }
            return $out;
        }
        if ($type === 'board' || $type === 'list') {
            // Q:F-12 — 묶는 칸은 `status` 또는 이 DB 의 **select** 칸 key 만(글 칸·다중 선택·없는 key 는 null → 400).
            // 행 응답은 그대로이고 묶기는 화면이 한다(database.js drawBoard) — 저장 모양 `{"group_by": "<key>"}`.
            if (!isset($raw['group_by'])) { return []; }
            $g = $raw['group_by'];
            if (!is_string($g) || ($g !== 'status' && (DbProps::find($schema, $g)['type'] ?? null) !== 'select')) { return null; }
            return ['group_by' => $g];
        }
        return [];
    }
    public static function groupKind(string $key, array $schema): ?string
    {
        if (in_array($key, ['status', 'owner', 'verified'], true)) { return $key; }
        if ($key === 'due') { return 'date'; }
        $kind = DbProps::find($schema, $key)['type'] ?? null;
        return in_array($kind, ['select', 'multi_select', 'checkbox', 'person', 'date'], true) ? $kind : null;
    }
    public static function day($value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
        return $value;
    }
    public static function dateRange(string $key, array $schema, $from, $to): array
    {
        if (!self::dateProp($key, $schema) || self::day($from) === null || self::day($to) === null
            || $from > $to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 399) {
            throw new InvalidArgumentException('bad_date_range');
        }
        if ($key === 'due') {
            return ['p.due_date <= CAST(:range_to AS date) AND p.due_date >= CAST(:range_from AS date)', [':range_to' => $to, ':range_from' => $from]];
        }
        return ["left(p.properties->CAST(:range_key AS text)->>'start',10) <= CAST(:range_to AS text) AND left(coalesce(p.properties->CAST(:range_key AS text)->>'end',p.properties->CAST(:range_key AS text)->>'start'),10) >= CAST(:range_from AS text)",
            [':range_key' => $key, ':range_to' => $to, ':range_from' => $from]];
    }
}
