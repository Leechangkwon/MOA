<?php
declare(strict_types=1);

/** C:F-46 / K10-4: pure proposal validation, application and touched-block comparison. */
final class Suggestion
{
    public const MAX_OPS = 50;
    public const MAX_BYTES = 1048576;
    private const TEXT_TYPES = ['paragraph', 'heading', 'bulleted', 'numbered',
        'to_do', 'quote', 'callout', 'code', 'divider', 'toc', 'toggle', 'table', 'column_list', 'column'];

    public static function blocks(array $doc): array
    {
        $out = [];
        $walk = static function (array $list) use (&$walk, &$out): void {
            foreach ($list as $block) {
                if (!is_array($block)) { continue; }
                if (is_string($block['id'] ?? null)) { $out[$block['id']] = $block; }
                if (is_array($block['children'] ?? null)) { $walk($block['children']); }
            }
        };
        $walk($doc['blocks'] ?? []);
        return $out;
    }

    /** Associative-key order is irrelevant; ordered arrays and scalar types remain significant. */
    public static function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) { return $a === $b; }
        if (array_is_list($a) !== array_is_list($b) || count($a) !== count($b)) { return false; }
        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b) || !self::same($value, $b[$key])) { return false; }
        }
        return true;
    }

    public static function stale(array $doc, array $baseBlocks): bool
    {
        $current = self::blocks($doc);
        foreach ($baseBlocks as $id => $before) {
            if (!isset($current[$id]) || !self::same($before, $current[$id])) { return true; }
        }
        return false;
    }

    private static function allowed(array $block, array $original): bool
    {
        if (!in_array($block['type'] ?? null, self::TEXT_TYPES, true)) { return false; }
        if (($block['type'] ?? null) === 'to_do') {
            $old = $original[$block['id'] ?? ''] ?? null;
            $expected = is_array($old) && ($old['type'] ?? null) === 'to_do' ? ($old['checked'] ?? false) : false;
            if (($block['checked'] ?? null) !== $expected) { return false; }
        }
        if (isset($block['children']) && !is_array($block['children'])) { return false; }
        foreach ($block['children'] ?? [] as $child) {
            if (!is_array($child) || !self::allowed($child, $original)) { return false; }
        }
        return true;
    }

    /** @return array{ops:array,base_blocks:array,doc:array,errors:array,reason:?string} */
    public static function prepare(array $doc, mixed $rawOps, int $stage): array
    {
        $fail = static fn (string $reason, array $errors = []): array =>
            ['ops'=>[], 'base_blocks'=>[], 'doc'=>$doc, 'errors'=>$errors, 'reason'=>$reason];
        if (!is_array($rawOps) || !array_is_list($rawOps) || count($rawOps) < 1 || count($rawOps) > self::MAX_OPS) {
            return $fail('invalid_ops');
        }
        $original = self::blocks($doc);
        $changed = []; $ops = []; $base = [];
        foreach ($rawOps as $op) {
            if (!is_array($op) || !isset($op['op']) || !is_string($op['op'])
                || !in_array($op['op'], ['replace', 'insert_after', 'delete'], true)
                || !array_key_exists('block_id', $op)) { return $fail('invalid_ops'); }
            $kind = $op['op']; $id = $op['block_id'];
            $keys = $kind === 'delete' ? ['op', 'block_id'] : ['op', 'block_id', 'block'];
            if (array_diff(array_keys($op), $keys) !== [] || count($op) !== count($keys)
                || !($id === null && $kind === 'insert_after') && (!is_string($id) || !isset($original[$id]))) {
                return $fail('invalid_ops');
            }
            if ($kind !== 'insert_after') {
                if (isset($changed[$id])) { return $fail('duplicate_block'); }
                $changed[$id] = true;
            }
            if ($id !== null) { $base[$id] = $original[$id]; }
            if ($kind !== 'delete') {
                if (!is_array($op['block'])) { return $fail('invalid_ops'); }
                if ($kind === 'replace' && ($op['block']['id'] ?? null) !== $id) { return $fail('invalid_ops'); }
                if ($kind === 'insert_after') {
                    // Never trust caller-chosen identifiers, including descendants.
                    $op['block'] = Doc::freshIds(['schema'=>1, 'blocks'=>[$op['block']]])['blocks'][0];
                }
                if (!self::allowed($op['block'], $kind === 'insert_after' ? [] : $original)) {
                    return $fail('suggestion_block_not_allowed');
                }
            }
            $ops[] = $op;
        }
        try { $result = self::apply($doc, $ops); }
        catch (InvalidArgumentException $e) { return $fail('invalid_ops'); }
        $valid = Doc::validate($result, $stage);
        if (!$valid['ok']) { return $fail('invalid_doc', $valid['errors']); }
        $bytes = strlen((string) json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            + strlen((string) json_encode((object) $base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($bytes > self::MAX_BYTES) { return $fail('suggestion_too_large'); }
        return ['ops'=>$ops, 'base_blocks'=>$base, 'doc'=>$result, 'errors'=>[], 'reason'=>null];
    }

    public static function apply(array $doc, array $ops): array
    {
        foreach ($ops as $op) {
            if ($op['op'] === 'insert_after' && $op['block_id'] === null) {
                array_unshift($doc['blocks'], $op['block']);
                continue;
            }
            $found = false;
            $walk = static function (array $list) use (&$walk, &$found, $op): array {
                $out = [];
                foreach ($list as $block) {
                    if (($block['id'] ?? null) === $op['block_id']) {
                        $found = true;
                        if ($op['op'] === 'replace') { $out[] = $op['block']; }
                        elseif ($op['op'] === 'insert_after') { $out[] = $block; $out[] = $op['block']; }
                    } else {
                        if (isset($block['children'])) { $block['children'] = $walk($block['children']); }
                        $out[] = $block;
                    }
                }
                return $out;
            };
            $doc['blocks'] = $walk($doc['blocks']);
            if (!$found) { throw new InvalidArgumentException('missing_block'); }
        }
        return $doc;
    }
}
