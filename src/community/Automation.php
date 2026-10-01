<?php
declare(strict_types=1);

/** C:F-41..44: pure allowlist validation and Seoul schedule/title calculation. No I/O. */
final class Automation
{
    public const BATCH_WORK_LIMIT = 200;
    public const BATCH_PAGE_LIMIT = 500;
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    public const TEXT_KEYS = ['recurring_created','review_requested','due_tomorrow','automation_denied','automation_disabled'];

    private static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) { throw new InvalidArgumentException('unknown_key'); }
    }
    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }
    public static function validate(array $rule): array
    {
        self::keys($rule, ['kind','name','scope_page_id','scope_space_id','trigger','actions','press_scope']);
        $kind = $rule['kind'] ?? null;
        if (!in_array($kind, ['recurring','on_status','due_reminder','button'], true)) { throw new InvalidArgumentException('bad_kind'); }
        if (!is_string($rule['name'] ?? null) || iconv_strlen(trim($rule['name']), 'UTF-8') < 1 || iconv_strlen($rule['name'], 'UTF-8') > 100 || preg_match('/\p{C}/u', $rule['name'])) { throw new InvalidArgumentException('bad_name'); }
        foreach (['scope_page_id'] as $field) {
            if (($rule[$field] ?? null) !== null && !self::uuid($rule[$field])) { throw new InvalidArgumentException('bad_scope'); }
        }
        if (($rule['scope_space_id'] ?? null) !== null && (!is_int($rule['scope_space_id']) || $rule['scope_space_id'] < 1)) { throw new InvalidArgumentException('bad_scope'); }
        if (($rule['scope_page_id'] ?? null) !== null && ($rule['scope_space_id'] ?? null) !== null) { throw new InvalidArgumentException('bad_scope'); }
        $t = $rule['trigger'] ?? null;
        if (!is_array($t) || array_is_list($t)) { throw new InvalidArgumentException('bad_trigger'); }
        if ($kind === 'recurring') {
            self::keys($t, ['type','freq','weekday','monthday','time']);
            if (($t['type'] ?? '') !== 'schedule' || !in_array($t['freq'] ?? '', ['daily','weekly','monthly'], true)
                || !is_string($t['time'] ?? null) || preg_match('/^(?:[01][0-9]|2[0-3]):[0-5]0$/D', $t['time']) !== 1) { throw new InvalidArgumentException('bad_schedule'); }
            if ($t['freq'] === 'weekly' && (!is_int($t['weekday'] ?? null) || $t['weekday'] < 1 || $t['weekday'] > 7)) { throw new InvalidArgumentException('bad_weekday'); }
            if ($t['freq'] === 'monthly' && ($t['monthday'] ?? null) !== 'last' && (!is_int($t['monthday'] ?? null) || $t['monthday'] < 1 || $t['monthday'] > 28)) { throw new InvalidArgumentException('bad_monthday'); }
            if (($t['freq'] !== 'weekly' && array_key_exists('weekday', $t)) || ($t['freq'] !== 'monthly' && array_key_exists('monthday', $t))) { throw new InvalidArgumentException('bad_schedule'); }
        } else {
            self::keys($t, $kind === 'on_status' ? ['type','to'] : ['type']);
            if (($t['type'] ?? '') !== $kind || ($kind === 'on_status' && ($t['to'] ?? '') !== 'review')) { throw new InvalidArgumentException('bad_trigger'); }
        }
        $actions = $rule['actions'] ?? null;
        if (!is_array($actions) || !array_is_list($actions) || count($actions) < 1 || count($actions) > 5) { throw new InvalidArgumentException('bad_actions'); }
        foreach ($actions as $a) {
            if (!is_array($a)) { throw new InvalidArgumentException('bad_action'); }
            if (($a['type'] ?? '') === 'create_page') {
                self::keys($a, ['type','source','parent','title_pattern','status','due_in_days']);
                $source = $a['source'] ?? null;
                if (!is_array($source) || count($source) !== 1 || !in_array(array_key_first($source), ['page','template'], true) || !self::uuid(reset($source))) { throw new InvalidArgumentException('bad_source'); }
                if (!array_key_exists('parent', $a) || ($a['parent'] !== null && !self::uuid($a['parent']))) { throw new InvalidArgumentException('bad_parent'); }
                if (!is_string($a['title_pattern'] ?? null) || iconv_strlen($a['title_pattern'], 'UTF-8') < 1 || iconv_strlen($a['title_pattern'], 'UTF-8') > 200 || preg_match('/\p{C}/u', $a['title_pattern'])) { throw new InvalidArgumentException('bad_title'); }
                if (isset($a['status']) && !in_array($a['status'], ['todo','doing','review','done','hold'], true)) { throw new InvalidArgumentException('bad_status'); }
                if (array_key_exists('due_in_days', $a) && (!is_int($a['due_in_days']) || $a['due_in_days'] < 0 || $a['due_in_days'] > 365)) { throw new InvalidArgumentException('bad_due'); }
            } elseif (($a['type'] ?? '') === 'notify') {
                self::keys($a, ['type','to','text_key']);
                if (($a['to'] ?? '') !== 'owner' || !in_array($a['text_key'] ?? '', self::TEXT_KEYS, true)) { throw new InvalidArgumentException('bad_notify'); }
            } else { throw new InvalidArgumentException('bad_action'); }
        }
        if (in_array($kind, ['on_status','due_reminder'], true)) {
            foreach ($actions as $a) {
                if ($a['type'] !== 'notify' || $a['to'] !== 'owner' || $a['text_key'] !== ($kind === 'on_status' ? 'review_requested' : 'due_tomorrow')) { throw new InvalidArgumentException('fixed_rule'); }
            }
        }
        if ($kind === 'button') {
            if (!in_array($rule['press_scope'] ?? '', ['owner','editors'], true) || ($rule['scope_page_id'] ?? null) === null) { throw new InvalidArgumentException('bad_press_scope'); }
        } elseif (array_key_exists('press_scope', $rule) && $rule['press_scope'] !== null) { throw new InvalidArgumentException('bad_press_scope'); }
        if (in_array($kind, ['on_status','due_reminder'], true) && (($rule['scope_page_id'] ?? null) === null && ($rule['scope_space_id'] ?? null) === null)) { throw new InvalidArgumentException('bad_scope'); }
        return $rule;
    }
    public static function title(string $pattern, string $sourceTitle, DateTimeImmutable $now): string
    {
        $now = $now->setTimezone(new DateTimeZone('Asia/Seoul'));
        $title = strtr($pattern, ['{제목}'=>$sourceTitle,'{YYYY}'=>$now->format('Y'),'{MM}'=>$now->format('m'),
            '{DD}'=>$now->format('d'),'{YYYY-MM}'=>$now->format('Y-m'),'{주}'=>$now->format('W')]);
        $title = trim($title);
        if (iconv_strlen($title, 'UTF-8') < 1 || iconv_strlen($title, 'UTF-8') > 200 || preg_match('/\p{C}/u', $title)) { throw new InvalidArgumentException('bad_title'); }
        return $title;
    }
    /** Only the latest elapsed slot is caught up; never replay every missed day. */
    public static function latestSlot(array $trigger, DateTimeImmutable $now): DateTimeImmutable
    {
        $now = $now->setTimezone(new DateTimeZone('Asia/Seoul'));
        [$h,$m] = array_map('intval', explode(':', $trigger['time']));
        $slot = $now->setTime($h,$m);
        if ($trigger['freq'] === 'daily') { return $slot > $now ? $slot->modify('-1 day') : $slot; }
        if ($trigger['freq'] === 'weekly') {
            $days = ((int)$now->format('N') - $trigger['weekday'] + 7) % 7;
            $slot = $slot->modify('-' . $days . ' days');
            return $slot > $now ? $slot->modify('-7 days') : $slot;
        }
        $day = $trigger['monthday'] === 'last' ? (int)$now->format('t') : $trigger['monthday'];
        $slot = $slot->setDate((int)$now->format('Y'), (int)$now->format('m'), $day);
        if ($slot > $now) {
            $prev = $now->modify('first day of previous month');
            $slot = $prev->setDate((int)$prev->format('Y'), (int)$prev->format('m'), $trigger['monthday'] === 'last' ? (int)$prev->format('t') : $trigger['monthday'])->setTime($h,$m);
        }
        return $slot;
    }
    public static function nextSlot(array $trigger, DateTimeImmutable $now): DateTimeImmutable
    {
        $last=self::latestSlot($trigger,$now);
        if ($trigger['freq']==='daily') { return $last->modify('+1 day'); }
        if ($trigger['freq']==='weekly') { return $last->modify('+7 days'); }
        $next=$last->modify('first day of next month');
        return $next->setDate((int)$next->format('Y'),(int)$next->format('m'),
            $trigger['monthday']==='last' ? (int)$next->format('t') : $trigger['monthday'])
            ->setTime((int)$last->format('H'),(int)$last->format('i'));
    }

}
