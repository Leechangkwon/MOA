<?php
declare(strict_types=1);
namespace QarediAssistant;

final class Service
{
    public static function create(array $user, int $token, array $body, string $key): array
    {
        Provider::ensureEnabled();
        $request = Request::normalize($body);
        $keyHash = Request::key($key);
        $bodyHash = hash('sha256', Request::json($request));
        return Store::transaction(static function () use ($user, $token, $request, $keyHash, $bodyHash) {
            $db = \Db::instance();
            $lockUser = 'SELECT id FROM users WHERE id=:owner FOR UPDATE';
            $db->run($lockUser, [':owner' => $user['id']]);
            $sql = 'SELECT * FROM assistant_runs WHERE user_id=:owner AND idempotency_hash=:key';
            $found = $db->run($sql, [':owner' => $user['id'], ':key' => $keyHash])->fetch();
            if (is_array($found)) {
                if (!hash_equals($found['request_hash'], $bodyHash))
                    throw new Error(409, 'idempotency_conflict', '같은 멱등 키에 다른 요청을 보낼 수 없습니다.');
                return self::receipt(Store::row($found), true);
            }
            Store::freshUser((int) $user['id'], $token);
            $provider = Provider::kind($request);
            foreach ($request['attachments'] as $ref) Files::evidence($user, $ref['file_id']);
            if ($request['tool'] !== null && $request['tool']['name'] === 'files.evidence')
                Files::evidence($user, $request['tool']['arguments']['file_id']);
            $sql = <<<'SQL'
                SELECT count(*) FILTER (WHERE status NOT IN ('succeeded','failed','cancelled')) AS active,
                       count(*) FILTER (WHERE created_at > clock_timestamp() - interval '1 minute') AS recent
                  FROM assistant_runs WHERE user_id=:owner
                SQL;
            $counts = $db->run($sql, [':owner' => $user['id']])->fetch();
            if ((int) $counts['active'] >= 10 || (int) $counts['recent'] >= 30)
                throw new Error(429, 'rate_limited', '진행 중 작업 또는 최근 요청 한도를 넘었습니다.', true);
            $sql = <<<'SQL'
                INSERT INTO assistant_runs(id,user_id,token_id,idempotency_hash,request_hash,request,provider)
                VALUES(CAST(:id AS uuid),:owner,:token,:key,:hash,CAST(:request AS jsonb),:provider) RETURNING *
                SQL;
            $r = Store::row($db->run($sql, [':id' => \tbox_uuid_v4(), ':owner' => $user['id'], ':token' => $token,
                ':key' => $keyHash, ':hash' => $bodyHash, ':request' => Request::json($request), ':provider' => $provider])->fetch());
            Store::event($r, 'run.created', ['status' => 'queued']);
            return self::receipt($r, false);
        });
    }

    private static function receipt(array $r, bool $replay): array
    {
        return ['run_id' => $r['id'], 'status' => $r['status'],
            'events_url' => \Http::url('/api/assistant/runs/' . $r['id'] . '/events'),
            'created_at' => Store::date($r['created_at']), 'provider' => $r['provider'],
            'inference_available' => false, 'idempotent_replay' => $replay];
    }

    public static function approval(array $r): ?array
    {
        if ($r['approval_id'] === null) return null;
        return ['approval_id' => $r['approval_id'], 'plan_hash' => $r['plan_hash'],
            'expires_at' => Store::date($r['approval_expires_at']), 'decision' => $r['decision'], 'plan' => $r['plan']];
    }

    public static function view(array $user, array $r, ?array $files = null): array
    {
        $stored = in_array($r['status'], Store::FINAL, true) ? $r['result'] : null;
        [$result, $artifacts] = Files::project($user, $stored, $files);
        $out = ['run_id' => $r['id'], 'conversation_id' => $r['request']['conversation_id'],
            'mode' => $r['request']['mode'], 'status' => $r['status'], 'phase' => $r['phase'],
            'provider' => $r['provider'], 'inference_available' => false, 'message' => $r['request']['message'],
            'steps' => $r['steps'], 'artifacts' => $artifacts, 'result' => $result,
            'approval' => self::approval($r), 'error' => $r['error'], 'last_event_id' => (int) $r['event_seq']];
        foreach (['created_at','updated_at','started_at','finished_at'] as $field) $out[$field] = Store::date($r[$field]);
        return $out;
    }

    public static function get(array $user, string $id): array
    {
        Provider::ensureEnabled();
        return self::view($user, Store::own((int) $user['id'], $id));
    }

    public static function listing(array $user, int $limit, ?string $cursor): array
    {
        Provider::ensureEnabled();
        $sql = 'SELECT * FROM assistant_runs WHERE user_id=:owner';
        $bind = [':owner' => $user['id'], ':lim' => $limit + 1];
        $tail = '';
        if ($cursor !== null) {
            if (strlen($cursor) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $cursor)) Request::invalid();
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            try { $c = json_decode($raw === false ? '' : $raw, true, 4, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { Request::invalid(); }
            if (!is_array($c) || count($c) !== 2 || !is_string($c[0] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/D', $c[0])) Request::invalid();
            try { new \DateTimeImmutable($c[0]); } catch (\Exception $e) { Request::invalid(); }
            $id = Request::uuid($c[1] ?? null);
            $tail = ' AND (created_at,id) < (CAST(:at AS timestamptz), CAST(:id AS uuid))';
            $bind += [':at' => $c[0], ':id' => $id];
        }
        $order = ' ORDER BY created_at DESC,id DESC LIMIT CAST(:lim AS integer)';
        $rows = array_map([Store::class, 'row'], \Db::instance()->run($sql . $tail . $order, $bind)->fetchAll());
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        $next = null;
        if ($more) {
            $last = $rows[count($rows)-1];
            $next = rtrim(strtr(base64_encode(Request::json([Store::date($last['created_at']), $last['id']])), '+/', '-_'), '=');
        }
        $refs = [];
        foreach ($rows as $r) foreach (($r['result']['references'] ?? []) as $ref) $refs[] = $ref;
        $files = Files::rowsForRefs($user, $refs);
        return ['runs' => array_map(static fn($r) => self::view($user, $r, $files), $rows),
            'next_cursor' => $next, 'capabilities' => Provider::capabilities()];
    }

    public static function cancel(array $user, string $id): array
    {
        Provider::ensureEnabled();
        return Store::transaction(static function () use ($user, $id) {
            $r = Store::own((int) $user['id'], $id, true);
            $final = in_array($r['status'], Store::FINAL, true);
            if (!$final && $r['status'] !== 'cancel_requested') {
                $r['status'] = 'cancel_requested'; $r['phase'] = 'cancelling';
                Store::save($r); Store::event($r, 'phase.changed', ['status' => $r['status'], 'phase' => $r['phase']]);
            }
            return [['run_id' => $id, 'status' => $r['status']], $final ? 200 : 202];
        });
    }

    public static function confirm(array $user, string $id, array $body): array
    {
        Provider::ensureEnabled();
        Request::keys($body, ['approval_id','plan_hash','decision']);
        $approval = Request::uuid($body['approval_id'] ?? null);
        $hash = $body['plan_hash'] ?? null; $decision = $body['decision'] ?? null;
        if (!is_string($hash) || !preg_match('/^[0-9a-f]{64}$/D', $hash)
            || !in_array($decision, ['approve','reject'], true)) Request::invalid();
        return Store::transaction(static function () use ($user,$id,$approval,$hash,$decision) {
            $r = Store::own((int) $user['id'], $id, true);
            if ($r['approval_id'] !== $approval || !is_string($r['plan_hash']) || !hash_equals($r['plan_hash'], $hash))
                throw new Error(409, 'approval_mismatch', '현재 서버 실행 계획과 일치하지 않습니다.');
            if ($r['decision'] !== null) {
                if ($r['decision'] !== $decision) throw new Error(409, 'approval_conflict', '이미 다른 결정으로 처리했습니다.');
                return [['run_id' => $id, 'status' => $r['status']], 200];
            }
            if ($r['status'] !== 'awaiting_confirmation') throw new Error(409, 'approval_state_conflict', '이 실행은 확인 대기 상태가 아닙니다.');
            if ($r['approval_expired'] === true) throw new Error(409, 'approval_expired', '실행 확인 기한이 지났습니다.');
            $r['decision'] = $decision;
            if ($decision === 'reject') Store::finish($r, 'cancelled');
            else {
                $r['status']='queued'; $r['phase']='queued'; Store::save($r);
                Store::event($r, 'phase.changed', ['status'=>'queued','phase'=>'queued']);
            }
            return [['run_id' => $id, 'status' => $r['status']], $decision === 'approve' ? 202 : 200];
        });
    }

    public static function projectEvent(array $user, array $r, array $event): array
    {
        $payload = json_decode($event['payload'], true, 32, JSON_THROW_ON_ERROR);
        if ($event['name'] === 'artifact.created') {
            $artifact = Files::artifact($user, $payload);
            $payload = $artifact === null ? ['artifact_id'=>$payload['artifact_id'],'available'=>false] : ['artifact'=>$artifact];
        } elseif ($event['name'] === 'approval.required') $payload = ['approval' => self::approval($r)];
        return ['run_id'=>$r['id'], 'at'=>Store::date($event['created_at']), 'payload'=>$payload];
    }
}
