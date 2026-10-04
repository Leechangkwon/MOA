<?php
declare(strict_types=1);

/** Plans are always private to their owner; a linked post retains its own visibility. */
final class TravelPlans
{
    public static function requireReady(): void
    {
        $ready = Db::instance()->run("SELECT to_regclass('community.travel_plan_records') IS NOT NULL")->fetchColumn();
        if (!$ready) { Http::error(503, '여행 계획 업데이트를 준비하고 있어요. 잠시 뒤 다시 열어 주세요.'); }
    }

    public static function date($value): ?string
    {
        if ($value === null || $value === '') { return null; }
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1) {
            Http::error(400, '날짜를 확인해 주세요. 아직 정하지 않았다면 비워 두세요.');
        }
        return $value;
    }

    public static function body(array $body): array
    {
        if (array_diff(array_keys($body), ['title','destination','starts_on','ends_on','notes','steps','revision','request_id'])) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $out = [];
        foreach (['title'=>120,'destination'=>160,'notes'=>5000] as $key=>$max) {
            $out[$key] = Memories::text($body[$key] ?? '', $max);
        }
        if ($out['title'] === '') { Http::error(400, '여행 이름을 적어 주세요.'); }
        $out['starts_on'] = self::date($body['starts_on'] ?? null);
        $out['ends_on'] = self::date($body['ends_on'] ?? null);
        if ($out['starts_on'] && $out['ends_on'] && $out['starts_on'] > $out['ends_on']) {
            Http::error(400, '여행 마지막 날은 첫날보다 앞설 수 없어요.');
        }
        $steps = $body['steps'] ?? [];
        if (!is_array($steps) || array_values($steps) !== $steps || count($steps)>100) {
            Http::error(400, '일정은 최대 100개까지 넣을 수 있어요.');
        }
        $seen = [];
        $out['steps'] = [];
        foreach ($steps as $step) {
            if (!is_array($step) || array_diff(array_keys($step), ['id','title','date','status','notes'])) {
                Http::error(400, Http::MSG_BAD_REQUEST);
            }
            $id = $step['id'] ?? null;
            if (!Memories::uuid($id) || isset($seen[$id])) { Http::error(400, '일정 번호를 확인해 주세요.'); }
            $seen[$id] = true;
            $title = Memories::text($step['title'] ?? '', 160);
            $status = $step['status'] ?? 'planned';
            if ($title === '' || !in_array($status, ['planned','done','skipped'], true)) { Http::error(400, Http::MSG_BAD_REQUEST); }
            // An activity may move outside the original travel dates, or remain undated.
            $out['steps'][] = ['id'=>$id, 'title'=>$title, 'date'=>self::date($step['date'] ?? null),
                'status'=>$status, 'notes'=>Memories::text($step['notes'] ?? '', 2000)];
        }
        return $out;
    }

    public static function row(string $id, array $user, bool $lock=false): array
    {
        if (!Memories::uuid($id)) { Http::error(404, Http::MSG_NOT_FOUND); }
        $sql = $lock
            ? 'SELECT * FROM community.travel_plans WHERE id=CAST(:id AS uuid) AND owner_user_id=:owner AND deleted_at IS NULL FOR UPDATE'
            : 'SELECT * FROM community.travel_plans WHERE id=CAST(:id AS uuid) AND owner_user_id=:owner AND deleted_at IS NULL';
        $row = Db::instance()->run($sql, [':id'=>$id, ':owner'=>(int)$user['id']])->fetch();
        if (!$row) { Http::error(404, Http::MSG_NOT_FOUND); }
        return $row;
    }

    public static function output(array $row, array $user, bool $records=true): array
    {
        $out = array_intersect_key($row, array_flip(['id','title','destination','starts_on','ends_on','notes','created_at','updated_at']));
        $out['revision'] = (int)$row['revision'];
        $out['steps'] = json_decode($row['steps'], true, 10, JSON_THROW_ON_ERROR);
        $out['visibility'] = 'private';
        if ($records) {
            // Query only my active posts; no sharing or publication takes place through a plan.
            $sql = <<<'SQL'
                SELECT r.post_id,r.step_id,left(p.caption,240) AS caption,p.taken_on,p.location FROM community.travel_plan_records r
                JOIN community.memory_posts p ON p.id=r.post_id
                WHERE r.plan_id=CAST(:plan AS uuid) AND p.owner_user_id=:owner AND p.deleted_at IS NULL
                ORDER BY p.taken_on,r.created_at,r.post_id
                SQL;
            $out['records'] = Db::instance()->run($sql, [':plan'=>$row['id'], ':owner'=>(int)$user['id']])->fetchAll();
        }
        return $out;
    }

    public static function listing(array $user, bool $withCsrf = true): array
    {
        // List summaries, not the potentially large notes of every itinerary.
        $sql = <<<'SQL'
            SELECT id,title,destination,starts_on,ends_on,revision,updated_at,jsonb_array_length(steps) AS step_count,
             (SELECT count(*) FROM jsonb_array_elements(steps) s WHERE s->>'status'='done') AS done_count
            FROM community.travel_plans WHERE owner_user_id=:owner AND deleted_at IS NULL
            ORDER BY updated_at DESC,id DESC LIMIT 200
            SQL;
        $rows = Db::instance()->run($sql, [':owner'=>(int)$user['id']])->fetchAll();
        return ['plans'=>$rows] + (!$withCsrf || defined('TBOX_API_TOKEN_SCOPE') ? [] : ['csrf_token'=>Auth::csrfToken()]);
    }

    public static function save(array $user, ?string $id, array $body): array
    {
        $data = self::body($body);
        if ($id !== null && (!is_int($body['revision'] ?? null) || $body['revision'] < 1)) {
            Http::error(400, '계획을 새로 열고 다시 저장해 주세요.');
        }
        if ($id === null && !Memories::uuid($body['request_id'] ?? null)) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $db = Db::instance(); $db->begin();
        $db->run('SELECT id FROM public.users WHERE id=:owner FOR UPDATE',
            [':owner'=>(int)$user['id']]);
        if ($id !== null) {
            $old = self::row($id,$user,true);
            if ((int)$old['revision'] !== $body['revision']) {
                $db->rollback(); Http::error(409, '다른 곳에서 계획이 바뀌었어요. 현재 입력을 복사해 둔 뒤 계획을 새로 열어 주세요.');
            }
        } else {
            $prior = $db->run('SELECT * FROM community.travel_plans WHERE owner_user_id=:owner AND request_id=CAST(:request AS uuid)',
                [':owner'=>(int)$user['id'], ':request'=>$body['request_id']])->fetch();
            if ($prior) {
                if ($prior['deleted_at'] !== null) { $db->rollback(); Http::error(409, '삭제한 계획이에요. 새로운 계획으로 만들어 주세요.'); }
                $db->commit(); return ['plan'=>self::output($prior,$user)];
            }
            $count = $db->run('SELECT count(*) FROM community.travel_plans WHERE owner_user_id=:owner AND deleted_at IS NULL',
                [':owner'=>(int)$user['id']])->fetchColumn();
            if ((int)$count >= 200) { $db->rollback(); Http::error(409, '진행 중인 계획은 200개까지 보관할 수 있어요.'); }
        }
        $bind = [':title'=>$data['title'], ':destination'=>$data['destination'], ':starts'=>$data['starts_on'], ':ends'=>$data['ends_on'],
            ':notes'=>$data['notes'], ':steps'=>json_encode($data['steps'], JSON_UNESCAPED_UNICODE), ':owner'=>(int)$user['id'], ':id'=>$id ?? tbox_uuid_v4()];
        if ($id === null) {
            $sql = <<<'SQL'
                INSERT INTO community.travel_plans(id,owner_user_id,title,destination,starts_on,ends_on,notes,steps,request_id)
                VALUES(CAST(:id AS uuid),:owner,:title,:destination,CAST(:starts AS date),CAST(:ends AS date),:notes,CAST(:steps AS jsonb),CAST(:request AS uuid)) RETURNING *
                SQL;
            $bind[':request']=$body['request_id'];
        } else {
            $sql = <<<'SQL'
                UPDATE community.travel_plans SET title=:title,destination=:destination,starts_on=CAST(:starts AS date),ends_on=CAST(:ends AS date),
                notes=:notes,steps=CAST(:steps AS jsonb),revision=revision+1,updated_at=now()
                WHERE id=CAST(:id AS uuid) AND owner_user_id=:owner RETURNING *
                SQL;
        }
        $row = $db->run($sql,$bind)->fetch();
        // Deleting a planned activity leaves its existing memories attached to the trip.
        $db->run('UPDATE community.travel_plan_records SET step_id=NULL WHERE plan_id=CAST(:plan AS uuid) AND step_id IS NOT NULL AND step_id NOT IN (SELECT CAST(value->>\'id\' AS uuid) FROM jsonb_array_elements(CAST(:steps AS jsonb)))',
            [':plan'=>$row['id'], ':steps'=>json_encode($data['steps'])]);
        self::audit($id === null ? 'travel_create':'travel_update');
        $db->commit(); return ['plan'=>self::output($row,$user)];
    }

    public static function record(array $user, string $id, array $body, bool $remove=false): array
    {
        if (array_diff(array_keys($body), ['post_id','step_id']) || !Memories::uuid($body['post_id'] ?? null)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $db = Db::instance(); $db->begin();
        $db->run('SELECT id FROM public.users WHERE id=:owner FOR UPDATE',
            [':owner'=>(int)$user['id']]);
        $plan = self::row($id,$user,true);
        $postSql = 'SELECT id FROM community.memory_posts WHERE id=CAST(:id AS uuid) AND owner_user_id=:owner AND deleted_at IS NULL FOR UPDATE';
        $post = $db->run($postSql, [':id'=>$body['post_id'], ':owner'=>(int)$user['id']])->fetch();
        if (!$post) { $db->rollback(); Http::error(404, Http::MSG_NOT_FOUND); }
        $stepId = $body['step_id'] ?? null;
        if (!$remove && $stepId !== null && (!Memories::uuid($stepId) || !in_array($stepId,array_column(json_decode($plan['steps'],true),'id'),true))) {
            $db->rollback(); Http::error(400, '일정이 바뀌었어요. 여행 전체에 연결하거나 일정을 다시 골라 주세요.');
        }
        if (!$remove) {
            $limitSql = 'SELECT count(*) FROM community.travel_plan_records WHERE plan_id=CAST(:plan AS uuid) AND post_id<>CAST(:post AS uuid)';
            $count = $db->run($limitSql,[':plan'=>$id,':post'=>$body['post_id']])->fetchColumn();
            if ((int)$count>=1000) { $db->rollback(); Http::error(409,'한 여행에는 기록을 1,000개까지 연결할 수 있어요.'); }
        }
        if ($remove) {
            $db->run('DELETE FROM community.travel_plan_records WHERE plan_id=CAST(:plan AS uuid) AND post_id=CAST(:post AS uuid)',
                [':plan'=>$id, ':post'=>$body['post_id']]);
        } else {
            $db->run('INSERT INTO community.travel_plan_records(plan_id,post_id,step_id) VALUES(CAST(:plan AS uuid),CAST(:post AS uuid),CAST(:step AS uuid)) ON CONFLICT(plan_id,post_id) DO UPDATE SET step_id=EXCLUDED.step_id',
                [':plan'=>$id, ':post'=>$body['post_id'], ':step'=>$stepId]);
        }
        // Records are part of the returned plan: every client must advance its revision on link changes.
        $revisionSql = 'UPDATE community.travel_plans SET revision=revision+1,updated_at=now() WHERE id=CAST(:id AS uuid) AND owner_user_id=:owner RETURNING *';
        $plan = $db->run($revisionSql, [':id'=>$id, ':owner'=>(int)$user['id']])->fetch();
        self::audit($remove ? 'travel_unlink':'travel_link'); $db->commit();
        return ['plan'=>self::output($plan,$user)];
    }

    public static function remove(array $user, string $id): void
    {
        $db=Db::instance(); $db->begin();
        $db->run('SELECT id FROM public.users WHERE id=:owner FOR UPDATE',
            [':owner'=>(int)$user['id']]);
        self::row($id,$user,true);
        $db->run('UPDATE community.travel_plans SET deleted_at=now() WHERE id=CAST(:id AS uuid) AND owner_user_id=:owner',
            [':id'=>$id, ':owner'=>(int)$user['id']]);
        self::audit('travel_delete'); $db->commit();
    }

    private static function audit(string $operation): void
    {
        Audit::allow('memory.change', ['target_type'=>'system','target_name'=>'여행 계획','detail'=>['operation'=>$operation]]);
    }
}
