<?php
declare(strict_types=1);
namespace QarediAssistant;

final class Store
{
    public const FINAL = ['succeeded', 'failed', 'cancelled'];

    public static function transaction(callable $fn)
    {
        $db = \Db::instance();
        $db->begin();
        try { $result = $fn(); $db->commit(); return $result; }
        catch (\Throwable $e) { $db->rollback(); throw $e; }
    }

    public static function row(array $row): array
    {
        foreach (['request', 'plan', 'steps', 'result', 'error'] as $name) {
            if (is_string($row[$name])) $row[$name] = json_decode($row[$name], true, 32, JSON_THROW_ON_ERROR);
        }
        return $row;
    }

    public static function own(int $owner, string $id, bool $lock = false): array
    {
        $sql = <<<'SQL'
            SELECT *, (approval_expires_at IS NOT NULL AND approval_expires_at <= clock_timestamp()) AS approval_expired,
                   (lease_expires_at IS NOT NULL AND lease_expires_at <= clock_timestamp()) AS lease_expired
              FROM assistant_runs WHERE id = CAST(:id AS uuid) AND user_id = :owner
            SQL;
        $suffix = $lock ? ' FOR UPDATE' : '';
        $row = \Db::instance()->run($sql . $suffix, [':id' => $id, ':owner' => $owner])->fetch();
        if (!is_array($row)) throw new Error(404, 'run_not_found', '실행을 찾을 수 없습니다.');
        return self::row($row);
    }

    public static function freshUser(int $owner, int $token): array
    {
        $sql = <<<'SQL'
            SELECT u.id, u.username, u.role, u.team_id, u.mfa_enabled, u.must_change_password, u.mfa_setup_required
              FROM users u JOIN api_tokens t ON t.user_id = u.id
             WHERE u.id = :owner AND t.id = :token AND t.scope = 'assistant.run'
               AND t.revoked_at IS NULL AND t.expires_at > clock_timestamp()
               AND u.is_active AND (u.expires_at IS NULL OR u.expires_at >= clock_timestamp())
             FOR SHARE OF u,t
            SQL;
        $user = \Db::instance()->run($sql, [':owner' => $owner, ':token' => $token])->fetch();
        if (!is_array($user) || \Auth::nextStep($user) !== null)
            throw new Error(403, 'authorization_lost', '실행에 사용한 사용자 또는 비서 토큰의 권한이 유효하지 않습니다.');
        \Authz::refreshPermissions();
        return $user;
    }

    public static function window(): array
    {
        return \Db::instance()->run("SELECT clock_timestamp() AS current_time, clock_timestamp() + interval '120 seconds' AS lease_until, clock_timestamp() + interval '10 minutes' AS approval_until")->fetch();
    }

    public static function date($value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable((string) $value))->format('Y-m-d\TH:i:s.uP');
    }

    public static function save(array $r): void
    {
        $sql = <<<'SQL'
            UPDATE assistant_runs
               SET status=:status, phase=:phase, plan=CAST(:plan AS jsonb), steps=CAST(:steps AS jsonb),
                   result=CAST(:result AS jsonb), error=CAST(:error AS jsonb),
                   approval_id=CAST(:approval_id AS uuid), plan_hash=:plan_hash,
                   approval_expires_at=CAST(:approval_expires_at AS timestamptz), decision=:decision,
                   worker_id=CAST(:worker_id AS uuid), lease_expires_at=CAST(:lease_expires_at AS timestamptz),
                   attempts=:attempts, started_at=CAST(:started_at AS timestamptz),
                   finished_at=CAST(:finished_at AS timestamptz), updated_at=clock_timestamp()
             WHERE id=CAST(:id AS uuid)
            SQL;
        $params = [];
        foreach (['id','status','phase','approval_id','plan_hash','approval_expires_at','decision','worker_id',
            'lease_expires_at','attempts','started_at','finished_at'] as $key) $params[':' . $key] = $r[$key];
        foreach (['plan','steps','result','error'] as $key) $params[':' . $key] = $r[$key] === null ? null : Request::json($r[$key]);
        \Db::instance()->run($sql, $params);
    }

    /** 호출자는 실행 행 잠금을 보유한다. 상태 변경과 이벤트는 같은 트랜잭션이다. */
    public static function event(array &$r, string $name, array $payload): void
    {
        $sql = <<<'SQL'
            UPDATE assistant_runs SET event_seq=event_seq+1, updated_at=clock_timestamp()
             WHERE id=CAST(:id AS uuid) RETURNING event_seq, updated_at
            SQL;
        $changed = \Db::instance()->run($sql, [':id' => $r['id']])->fetch();
        $r['event_seq'] = (int) $changed['event_seq'];
        $r['updated_at'] = $changed['updated_at'];
        $sql = <<<'SQL'
            INSERT INTO assistant_events(run_id, seq, name, payload, created_at)
            VALUES(CAST(:id AS uuid), :seq, :name, CAST(:payload AS jsonb), CAST(:at AS timestamptz))
            SQL;
        \Db::instance()->run($sql, [':id' => $r['id'], ':seq' => $r['event_seq'], ':name' => $name,
            ':payload' => Request::json($payload), ':at' => $r['updated_at']]);
    }

    public static function events(string $id, int $after): array
    {
        $sql = <<<'SQL'
            SELECT seq, name, payload, created_at FROM assistant_events
             WHERE run_id=CAST(:id AS uuid) AND seq > :after ORDER BY seq LIMIT 100
            SQL;
        return \Db::instance()->run($sql, [':id' => $id, ':after' => $after])->fetchAll();
    }

    public static function finish(array &$r, string $status, ?Error $error = null): void
    {
        $r['status'] = $status;
        $r['phase'] = $status === 'succeeded' ? 'completed' : $status;
        $r['error'] = $error?->payload($r['id']);
        $r['finished_at'] = self::window()['current_time'];
        $r['worker_id'] = null;
        $r['lease_expires_at'] = null;
        foreach ($r['steps'] as &$step) {
            if ($step['status'] !== 'succeeded') $step['status'] = $status;
        }
        unset($step);
        self::save($r);
        self::event($r, $status === 'succeeded' ? 'run.completed' : 'run.' . $status,
            ['status' => $status] + ($error === null ? [] : ['error' => $r['error']]));
    }
}
