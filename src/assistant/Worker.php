<?php
declare(strict_types=1);
namespace QarediAssistant;

final class Worker
{
    public static function claim(): ?array
    {
        Provider::ensureEnabled();
        return Store::transaction(static function () {
            $sql = <<<'SQL'
                SELECT *, (approval_expires_at IS NOT NULL AND approval_expires_at <= clock_timestamp()) AS approval_expired
                  FROM assistant_runs
                 WHERE status='queued'
                    OR (status='cancel_requested' AND (worker_id IS NULL OR lease_expires_at <= clock_timestamp()))
                    OR (status IN ('planning','running') AND lease_expires_at <= clock_timestamp())
                    OR (status='awaiting_confirmation' AND approval_expires_at <= clock_timestamp())
                 ORDER BY created_at,id LIMIT 1 FOR UPDATE SKIP LOCKED
                SQL;
            $raw = \Db::instance()->run($sql)->fetch();
            if (!is_array($raw)) return null;
            $r = Store::row($raw);
            if ($r['status'] === 'cancel_requested') { Store::finish($r, 'cancelled'); return $r; }
            if ($r['status'] === 'awaiting_confirmation') {
                Store::finish($r, 'failed', new Error(409, 'approval_expired', '실행 확인 기한이 지났습니다.')); return $r;
            }
            if ((int) $r['attempts'] >= 3) {
                Store::finish($r, 'failed', new Error(503, 'worker_lease_exhausted', '워커 재시작 한도를 넘었습니다.', true)); return $r;
            }
            $time = Store::window();
            $r['worker_id'] = \tbox_uuid_v4(); $r['lease_expires_at'] = $time['lease_until'];
            $r['attempts'] = (int) $r['attempts'] + 1;
            $r['started_at'] = $r['started_at'] ?? $time['current_time'];
            $r['status'] = 'planning'; $r['phase'] = 'planning';
            Store::save($r); Store::event($r, 'phase.changed', ['status'=>'planning','phase'=>'planning']);
            return $r;
        });
    }

    /** 행 잠금 뒤 취소·리스 소유자·원래 토큰을 확인한다. */
    private static function guarded(array $claim, callable $fn)
    {
        return Store::transaction(static function () use ($claim,$fn) {
            $r = Store::own((int) $claim['user_id'], $claim['id'], true);
            if (in_array($r['status'], Store::FINAL, true) || $r['worker_id'] !== $claim['worker_id']) return false;
            if ($r['status'] === 'cancel_requested') { Store::finish($r, 'cancelled'); return false; }
            if ($r['lease_expired'] === true) return false;
            $user = Store::freshUser((int) $r['user_id'], (int) $r['token_id']);
            $r['lease_expires_at'] = Store::window()['lease_until'];
            return $fn($r, $user);
        });
    }

    public static function execute(array $claim): void
    {
        if (in_array($claim['status'], Store::FINAL, true)) return;
        try {
            $ready = self::guarded($claim, static function (array $r, array $user) {
                Provider::kind($r['request']);
                if ($r['plan'] === null) {
                    $r['plan'] = Provider::plan($r['request']);
                    $r['steps'] = array_map(static fn($s) => ['step_id'=>$s['step_id'],'tool'=>$s['tool'],'status'=>'planned'], $r['plan']['steps']);
                }
                if ($r['request']['require_confirmation'] && $r['decision'] === null) {
                    $time = Store::window();
                    $r['approval_id'] = \tbox_uuid_v4();
                    $r['plan_hash'] = hash('sha256', Request::json([$r['id'],(int)$r['user_id'],$r['approval_id'],$r['plan']]));
                    $r['approval_expires_at'] = $time['approval_until'];
                    $r['status'] = 'awaiting_confirmation'; $r['phase'] = 'approval';
                    $r['worker_id'] = null; $r['lease_expires_at'] = null;
                    Store::save($r); Store::event($r,'phase.changed',['status'=>$r['status'],'phase'=>$r['phase']]);
                    Store::event($r,'approval.required',['approval_id'=>$r['approval_id']]);
                    return false;
                }
                $r['status']='running'; $r['phase']='running';
                Store::save($r); Store::event($r,'phase.changed',['status'=>'running','phase'=>'running']);
                return true;
            });
            if (!$ready) return;
            $r = Store::own((int) $claim['user_id'], $claim['id']);
            foreach ($r['plan']['steps'] as $i => $step) {
                $ok = self::guarded($claim, static function (array $r, array $user) use ($i,$step) {
                    if ($r['steps'][$i]['status'] === 'succeeded') return true;
                    if (!in_array($step['tool'], $r['request']['allowed_tools'], true))
                        throw new Error(403,'tool_not_permitted','실행 계획의 도구가 허용되지 않았습니다.');
                    $r['steps'][$i]['status']='running'; Store::save($r);
                    Store::event($r,'tool.started',['step_id'=>$step['step_id'],'tool'=>$step['tool']]);
                    $files = Files::execute($user,$step);
                    if ($r['result'] === null) $r['result']=['kind'=>$r['plan']['steps'][0]['tool']==='files.search' ? 'file_search':'file_evidence','references'=>[],'content_read'=>false];
                    $seen = array_column($r['result']['references'],'file_id');
                    foreach ($files as $file) {
                        $id = (string) $file['public_id'];
                        if (in_array($id,$seen,true)) continue;
                        $ref=['artifact_id'=>\tbox_uuid_v4(),'file_id'=>$id];
                        $r['result']['references'][]=$ref; $seen[]=$id;
                        Store::event($r,'artifact.created',$ref);
                    }
                    $r['steps'][$i]['status']='succeeded'; Store::save($r);
                    Store::event($r,'tool.finished',['step_id'=>$step['step_id'],'tool'=>$step['tool']]);
                    return true;
                });
                if (!$ok) return;
            }
            self::guarded($claim, static function (array $r, array $user) {
                if ($r['provider']==='test') Store::event($r,'message.delta',['text'=>'테스트 어댑터 결과입니다. 실제 AI 추론이 아닙니다.','provider'=>'test']);
                Store::finish($r,'succeeded'); return true;
            });
        } catch (\Throwable $e) {
            $error = $e instanceof Error ? $e : new Error(500,'worker_error','작업을 완료하지 못했습니다.',true);
            Store::transaction(static function () use ($claim,$error) {
                $r=Store::own((int)$claim['user_id'],$claim['id'],true);
                if (!in_array($r['status'],Store::FINAL,true) && $r['worker_id']===$claim['worker_id'])
                    Store::finish($r,$r['status']==='cancel_requested' ? 'cancelled':'failed',$r['status']==='cancel_requested' ? null:$error);
            });
        }
    }

    public static function once(): ?array
    {
        $claim=self::claim();
        if ($claim===null) return null;
        self::execute($claim);
        return Store::own((int)$claim['user_id'],$claim['id']);
    }
}
