<?php
declare(strict_types=1);

/** Optional display preference; never used to grant access or modify user content. */
final class UsageGuide
{
    public const VERSION = 1;
    public const CONTEXTS = ['overview','threads','workspace','files','account','messenger','composer','story','travel','today'];

    public static function ready(): bool
    {
        return (bool) Db::instance()->run("SELECT to_regclass('community.usage_guides') IS NOT NULL AND to_regclass('community.usage_guide_versions') IS NOT NULL")->fetchColumn();
    }

    public static function state(int $userId): array
    {
        $sql = <<<'SQL'
            SELECT g.status,g.context,g.step,u.created_at>=v.activated_at AS eligible
            FROM public.users u CROSS JOIN community.usage_guide_versions v
            LEFT JOIN community.usage_guides g ON g.user_id=u.id AND g.version=v.version
            WHERE u.id=:user_id AND v.version=:version
            SQL;
        $r = Db::instance()->run($sql, [':user_id'=>$userId, ':version'=>self::VERSION])->fetch();
        if (!is_array($r)) { throw new RuntimeException('Usage guide version missing'); }
        return ['version'=>self::VERSION, 'status'=>$r['status'] ?? 'new',
            'context'=>$r['context'] ?? 'overview', 'step'=>(int) ($r['step'] ?? 0),
            'offer'=>$r['status'] === null && $r['eligible'] === true];
    }

    public static function validate(array $body): array
    {
        if (count($body) !== 3 || array_diff(array_keys($body), ['status','context','step']) ||
            !in_array($body['status'] ?? null, ['active','dismissed','completed'], true) ||
            !in_array($body['context'] ?? null, self::CONTEXTS, true) ||
            !is_int($body['step'] ?? null) || $body['step'] < 0 || $body['step'] > 30) {
            throw new InvalidArgumentException('guide_state');
        }
        return $body;
    }

    public static function save(int $userId, array $body): array
    {
        $b = self::validate($body);
        $sql = <<<'SQL'
            INSERT INTO community.usage_guides(user_id,version,status,context,step)
            VALUES(:user_id,:version,:status,:context,:step)
            ON CONFLICT(user_id,version) DO UPDATE SET status=EXCLUDED.status,
                context=EXCLUDED.context,step=EXCLUDED.step,updated_at=now()
            SQL;
        Db::instance()->run($sql, [':user_id'=>$userId, ':version'=>self::VERSION,
            ':status'=>$b['status'], ':context'=>$b['context'], ':step'=>$b['step']]);
        return self::state($userId);
    }
}
