<?php
declare(strict_types=1);
final class MemoRules
{
    public const WHY_NOT_WRITER = 'not_writer';
    public const WHY_LOCKED     = 'locked_memo';
    public const WHY_WRONG_DESK = 'wrong_desk';

    /** @return array{0:bool,1:string} */
    public static function mayEdit(array $u, array $memo): array
    {
        if ($memo['locked']) {
            return [false, self::WHY_LOCKED];
        }
        return [$u['id'] === $memo['writer_id'], self::WHY_NOT_WRITER];
    }
}
