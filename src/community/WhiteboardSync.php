<?php
declare(strict_types=1);

/** Q:F-17: strict wire validation and deterministic, DB-verifiable event encoding. */
final class WhiteboardSync
{
    public const MAX_ELEMENTS = 5000;
    public const ZERO = '0000000000000000000000000000000000000000000000000000000000000000';
    private const TYPES = ['rectangle','diamond','ellipse','arrow','line','freedraw','text','frame'];
    private const KEYS = ['id','type','x','y','width','height','angle','strokeColor','backgroundColor',
        'fillStyle','strokeWidth','strokeStyle','roughness','opacity','groupIds','frameId','index',
        'roundness','seed','version','versionNonce','isDeleted','boundElements','updated','link','locked',
        'text','originalText','fontSize','fontFamily','textAlign','verticalAlign','containerId','autoResize',
        'lineHeight','points','pressures','simulatePressure','lastCommittedPoint','startBinding','endBinding',
        'startArrowhead','endArrowhead','elbowed','fixedSegments','startIsSpecial','endIsSpecial','name'];

    public static function integer($value, int $min = 0, int $max = 2147483646): int
    {
        if (!is_int($value) || $value < $min || $value > $max) { Http::error(400, '순번과 판 번호를 확인해 주세요.'); }
        return $value;
    }

    public static function canonical($value): string
    {
        $sort = static function ($v) use (&$sort) {
            if (is_array($v)) {
                if (!array_is_list($v)) { ksort($v, SORT_STRING); }
                foreach ($v as &$item) { $item = $sort($item); }
                unset($item);
            }
            if (is_float($v) && $v == 0.0) { return 0; }
            return $v;
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function eventHash(array $event): string
    {
        $data = is_string($event['data']) ? json_decode($event['data'], true, 32, JSON_THROW_ON_ERROR) : $event['data'];
        return hash('sha256', self::canonical(['prev'=>(string)$event['prev_hash'], 'seq'=>(int)$event['seq'],
            'element'=>(string)$event['element_id'], 'op'=>(string)$event['op'], 'actor'=>(int)$event['actor_id'],
            'at'=>(string)$event['at_utc'], 'version'=>(int)$event['element_version'],
            'data_sha256'=>hash('sha256', self::canonical($data))]));
    }

    public static function element($input): array
    {
        if (!is_array($input) || array_is_list($input)) { throw new InvalidArgumentException('bad_element'); }
        $e = array_intersect_key($input, array_flip(self::KEYS));
        if (!is_string($e['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $e['id'])
            || !in_array($e['type'] ?? null, self::TYPES, true)) { throw new InvalidArgumentException('unsupported_element'); }
        foreach (['x','y','width','height','angle','strokeWidth','roughness','opacity','updated','seed'] as $key) {
            $v = $e[$key] ?? null;
            if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || abs($v) > 1.0e13
                || (in_array($key, ['width','height','strokeWidth','opacity'], true) && $v < 0)) {
                throw new InvalidArgumentException('bad_geometry');
            }
        }
        if (abs($e['x'])>10000000 || abs($e['y'])>10000000 || $e['width']>1000000 || $e['height']>1000000
            || $e['opacity']>100 || !is_int($e['version'] ?? null) || $e['version']<1 || $e['version']>2147483646
            || !is_int($e['versionNonce'] ?? null) || $e['versionNonce']<0 || $e['versionNonce']>4294967295
            || !is_bool($e['isDeleted'] ?? null) || !is_bool($e['locked'] ?? null)) {
            throw new InvalidArgumentException('bad_version');
        }
        foreach (['strokeColor','backgroundColor'] as $key) {
            if (!is_string($e[$key] ?? null) || !preg_match('/^(?:transparent|#[0-9a-fA-F]{3,8})$/D', $e[$key])) {
                throw new InvalidArgumentException('bad_color');
            }
        }
        foreach (['text','originalText','name'] as $key) {
            if (isset($e[$key]) && (!is_string($e[$key]) || strlen($e[$key])>32000
                || preg_match('//u',$e[$key])!==1 || str_contains($e[$key],"\0"))) { throw new InvalidArgumentException('bad_text'); }
        }
        if (($e['link'] ?? null) !== null && (!is_string($e['link']) || strlen($e['link'])>2048
            || preg_match('/[\x00-\x20\x7f]/', $e['link'])
            || !preg_match('~^(?:https?://|mailto:|/(?!/))~i', $e['link']))) { throw new InvalidArgumentException('bad_link'); }
        foreach (['groupIds','boundElements','points','pressures','fixedSegments'] as $key) {
            if (isset($e[$key]) && (!is_array($e[$key]) || !array_is_list($e[$key]) || count($e[$key])>5000)) {
                throw new InvalidArgumentException('too_many_points');
            }
        }
        foreach (['fillStyle'=>['hachure','cross-hatch','solid','zigzag'],
            'strokeStyle'=>['solid','dashed','dotted'], 'textAlign'=>['left','center','right'],
            'verticalAlign'=>['top','middle','bottom']] as $key=>$values) {
            if (isset($e[$key]) && !in_array($e[$key],$values,true)) { throw new InvalidArgumentException('bad_style'); }
        }
        $ref=static fn($v): bool=>is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$v)===1;
        foreach(['frameId','containerId'] as $key) {
            if (isset($e[$key]) && !$ref($e[$key])) { throw new InvalidArgumentException('bad_reference'); }
        }
        foreach($e['groupIds'] ?? [] as $group) { if (!$ref($group)) { throw new InvalidArgumentException('bad_group'); } }
        if (isset($e['index']) && (!is_string($e['index']) || !preg_match('/^[A-Za-z0-9]{1,256}$/D',$e['index']))) { throw new InvalidArgumentException('bad_index'); }
        foreach($e['boundElements'] ?? [] as $bound) {
            if (!is_array($bound) || array_diff(array_keys($bound),['id','type']) || !$ref($bound['id'] ?? null)
                || !in_array($bound['type'] ?? null,['arrow','text'],true)) { throw new InvalidArgumentException('bad_binding'); }
        }
        foreach(['startBinding','endBinding'] as $key) {
            if (!isset($e[$key])) { continue; }
            $binding=$e[$key];
            if (!is_array($binding) || array_diff(array_keys($binding),['elementId','focus','gap','fixedPoint'])
                || !$ref($binding['elementId'] ?? null)) { throw new InvalidArgumentException('bad_binding'); }
            foreach(['focus','gap'] as $number) {
                if (isset($binding[$number]) && ((!is_int($binding[$number]) && !is_float($binding[$number]))
                    || !is_finite((float)$binding[$number]) || abs($binding[$number])>1000000)) { throw new InvalidArgumentException('bad_binding'); }
            }
        }
        foreach($e['points'] ?? [] as $point) {
            if (!is_array($point) || !array_is_list($point) || count($point)!==2) { throw new InvalidArgumentException('bad_point'); }
            foreach($point as $v) {
                if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || abs($v)>10000000) { throw new InvalidArgumentException('bad_point'); }
            }
        }
        if ($e['type']==='text' && (!is_string($e['text'] ?? null) || !is_int($e['fontFamily'] ?? null)
            || !in_array($e['fontFamily'],[1,2,3,5,6,7,8],true) || !is_numeric($e['fontSize'] ?? null)
            || is_string($e['fontSize']) || $e['fontSize']<1 || $e['fontSize']>1000)) { throw new InvalidArgumentException('bad_text_style'); }
        // No arbitrarily nested objects, embedded markup or non-finite coordinates may reach the renderer.
        $walk = static function ($v, int $depth = 0) use (&$walk): void {
            if ($depth>8 || (is_float($v) && !is_finite($v)) || (is_numeric($v) && !is_string($v) && abs((float)$v)>1.0e13)) {
                throw new InvalidArgumentException('bad_value');
            }
            if (is_array($v)) { foreach ($v as $item) { $walk($item, $depth+1); } }
            elseif (!is_scalar($v) && $v!==null) { throw new InvalidArgumentException('bad_value'); }
        };
        $walk($e);
        if (strlen(self::canonical($e))>65536) { throw new InvalidArgumentException('element_too_large'); }
        return $e;
    }
}
