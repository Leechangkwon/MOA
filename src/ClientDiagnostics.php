<?php
declare(strict_types=1);

/** Optional, bounded, pseudonymous summaries. Never accepts business text or attachments. */
final class ClientDiagnostics
{
    public const DAILY_LIMIT=1000;
    public const SERVICE_DAILY_LIMIT=10000;
    public const POLICY_VERSION='2026-10-17';

    public static function contract(): array
    {
        static $value=null;
        if ($value===null) $value=json_decode(file_get_contents(dirname(__DIR__).'/protocols/client-diagnostics.json'),true,64,JSON_THROW_ON_ERROR);
        return $value;
    }

    public static function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[45][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i',$value)!==1) throw new InvalidArgumentException('invalid diagnostic identifier');
        return strtolower($value);
    }

    public static function cleanEvent(mixed $value): array
    {
        $schema=self::contract();
        if (!is_array($value)) throw new InvalidArgumentException('invalid diagnostic event');
        $keys=array_keys($value);sort($keys);$wanted=$schema['fields'];sort($wanted);
        if ($keys!==$wanted) throw new InvalidArgumentException('unknown diagnostic fields');
        $out=[];
        foreach ($schema['uuids'] as $key) $out[$key]=self::uuid($value[$key]);
        $out['request_id']=$value['request_id']===null ? null:self::uuid($value['request_id']);
        foreach (['event'=>'events','stage'=>'stages','error_code'=>'errors','status'=>'statuses','cache'=>'cache','attempt_kind'=>'attempt_kinds'] as $field=>$options) {
            if (!is_string($value[$field]) || !in_array($value[$field],$schema[$options],true)) throw new InvalidArgumentException('invalid diagnostic enum');
            $out[$field]=$value[$field];
        }
        if (!is_string($value['version']) || strlen($value['version'])>32 || preg_match('/\A20[0-9]{2}\.[0-9]{2}\.[0-9]{2}\.[1-9][0-9]*\z/',$value['version'])!==1 ||
            !is_string($value['code_id']) || preg_match('/\A[a-f0-9]{64}\z/',$value['code_id'])!==1) throw new InvalidArgumentException('invalid diagnostic build');
        $out['version']=$value['version'];$out['code_id']=$value['code_id'];
        foreach ($schema['integers'] as $key=>[$min,$max]) {
            if (!is_int($value[$key]) || $value[$key]<$min || $value[$key]>$max) throw new InvalidArgumentException('invalid diagnostic number');
            $out[$key]=$value[$key];
        }
        if ($out['at']<time()-14*86400-300 || $out['at']>time()+300) throw new InvalidArgumentException('invalid diagnostic time');
        $missing=$value['missing_fields'];
        if (!is_array($missing) || array_diff(array_keys($missing),$schema['missing_fields'])!==[]) throw new InvalidArgumentException('invalid diagnostic missing fields');
        $counts=[];
        foreach ($schema['missing_fields'] as $field) {
            if (!array_key_exists($field,$missing)) continue;
            if (!is_int($missing[$field]) || $missing[$field]<0 || $missing[$field]>1000000) throw new InvalidArgumentException('invalid diagnostic count');
            $counts[$field]=$missing[$field];
        }
        $out['missing_fields']=(object)$counts;
        $stages=['run_started'=>'prepare','request_started'=>'request','response_received'=>'request',
                 'validation_completed'=>'validate','cache_reused'=>'validate','checkpoint_reused'=>'validate',
                 'result_saved'=>'save','run_completed'=>'complete'];
        if (isset($stages[$out['event']]) && $out['stage']!==$stages[$out['event']]) throw new InvalidArgumentException('invalid diagnostic stage');
        if (($out['event']==='run_failed' && ($out['status']!=='failure' || $out['error_code']==='none')) ||
            ($out['event']==='run_completed' && ($out['status']!=='success' || $out['error_code']!=='none')) ||
            (in_array($out['event'],['request_started','response_received'],true) && $out['request_id']===null)) throw new InvalidArgumentException('invalid diagnostic outcome');
        if (($out['attempt_kind']==='initial')!==($out['attempt_index']===1)) throw new InvalidArgumentException('invalid attempt kind');
        return $out;
    }

    public static function policy(): array
    {
        $row=Db::instance()->run('SELECT retention_days,daily_enabled,weekly_enabled,daily_hour,weekly_weekday FROM client_diagnostic_policy WHERE singleton')->fetch();
        if (!$row) Http::error(503,'요약 진단 설정을 준비하고 있습니다.');
        return ['version'=>self::POLICY_VERSION,'retention_days'=>(int)$row['retention_days'],
                'daily_enabled'=>(bool)$row['daily_enabled'],'weekly_enabled'=>(bool)$row['weekly_enabled'],
                'daily_hour'=>(int)$row['daily_hour'],'weekly_weekday'=>(int)$row['weekly_weekday'],
                'max_batch'=>100,'daily_limit'=>self::DAILY_LIMIT,'service_daily_limit'=>self::SERVICE_DAILY_LIMIT,'scope'=>'diagnostics.write',
                'purpose'=>'PC 비서 오류 유형·완료율·재개·처리 시간 개선',
                'excluded'=>['업무 원문','AI 답변','인증정보','계정 이름','사용자 경로'],
                'access'=>'관리자만 집계 조회 · 본인은 자기 설치 기록 삭제 가능'];
    }

    public static function own(array $user): array
    {
        $row=Db::instance()->run('SELECT count(*) AS events,count(DISTINCT installation_id) AS installations FROM client_diagnostic_events WHERE owner_id=:uid',
            [':uid'=>(int)$user['id']])->fetch();
        return ['policy'=>self::policy(),'events'=>(int)$row['events'],'installations'=>(int)$row['installations']];
    }

    public static function receive(array $user,array $body): array
    {
        $keys=array_keys($body);sort($keys);
        if ($keys!==['ack','events','policy_version','schema'] || $body['schema']!==1 || $body['ack']!==true ||
            $body['policy_version']!==self::POLICY_VERSION || !is_array($body['events']) || !array_is_list($body['events']) ||
            count($body['events'])<1 || count($body['events'])>100) Http::error(400,Http::MSG_BAD_REQUEST);
        $batch=[];
        try {
            foreach ($body['events'] as $raw) {
                $event=self::cleanEvent($raw);$json=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$sha=hash('sha256',$json);
                if (isset($batch[$event['event_id']]) && $batch[$event['event_id']]['sha']!==$sha) throw new InvalidArgumentException('conflicting diagnostic id');
                $batch[$event['event_id']]=['event'=>$event,'json'=>$json,'sha'=>$sha];
            }
        } catch (InvalidArgumentException|JsonException $e) { Http::error(400,'허용된 요약 진단 형식과 PC 시간을 확인해 주세요.'); }
        $db=Db::instance();$db->begin();$uid=(int)$user['id'];
        try {
            $db->run('SELECT pg_advisory_xact_lock(74626712,CAST(:uid AS integer))',
                     [':uid'=>$uid]);
            $db->run('SELECT pg_advisory_xact_lock(74626714)');
            $existing=$db->run('SELECT event_id,payload_sha256 FROM client_diagnostic_events WHERE owner_id=:uid AND event_id=ANY(CAST(:ids AS uuid[]))',
                              [':uid'=>$uid,':ids'=>'{'.implode(',',array_keys($batch)).'}'])->fetchAll();
            $old=[];
            foreach ($existing as $row) {
                $old[$row['event_id']]=true;
                if (!hash_equals($row['payload_sha256'],$batch[$row['event_id']]['sha'])) {
                    $db->rollback();Http::error(409,'같은 진단 식별자의 내용이 달라 전송하지 않았습니다.');
                }
            }
            $today=(int)$db->run("SELECT count(*) FROM client_diagnostic_events WHERE owner_id=:uid AND received_at>=date_trunc('day',clock_timestamp())",
                                 [':uid'=>$uid])->fetchColumn();
            $total=(int)$db->run("SELECT count(*) FROM client_diagnostic_events WHERE received_at>=date_trunc('day',clock_timestamp())")->fetchColumn();
            $newCount=count($batch)-count($old);
            if ($newCount>0 && ($today+$newCount>self::DAILY_LIMIT || $total+$newCount>self::SERVICE_DAILY_LIMIT)) {
                $db->rollback();header('Retry-After: 3600');Http::error(429,'오늘의 요약 진단 전송 한도입니다. 업무는 계속 사용할 수 있습니다.');
            }
            foreach ($batch as $id=>$item) {
                if (isset($old[$id])) continue;
                $v=$item['event'];
                $db->run(<<<'SQL'
                    INSERT INTO client_diagnostic_events(owner_id,event_id,installation_id,run_id,request_id,app_version,code_id,event,stage,status,error_code,attempt_kind,elapsed_ms,occurred_at,payload,payload_sha256)
                    VALUES(:uid,CAST(:eid AS uuid),CAST(:iid AS uuid),CAST(:rid AS uuid),CAST(:request AS uuid),:version,:code,:event,:stage,:status,:error,:kind,:elapsed,to_timestamp(:at),CAST(:payload AS jsonb),:sha)
                    SQL,[':uid'=>$uid,':eid'=>$id,':iid'=>$v['installation_id'],':rid'=>$v['run_id'],':request'=>$v['request_id'],
                         ':version'=>$v['version'],':code'=>$v['code_id'],':event'=>$v['event'],':stage'=>$v['stage'],':status'=>$v['status'],
                         ':error'=>$v['error_code'],':kind'=>$v['attempt_kind'],':elapsed'=>$v['elapsed_ms'],':at'=>$v['at'],':payload'=>$item['json'],':sha'=>$item['sha']]);
            }
            Audit::allow('client_diagnostics.receive',['target_type'=>'user','target_id'=>$uid,
                'detail'=>['diag_received'=>count($batch)-count($old),'diag_duplicate'=>count($old)]]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollback();throw $e; }
        return ['accepted_ids'=>array_keys($batch),'stored'=>count($batch)-count($old),'duplicate'=>count($old)];
    }

    public static function forget(array $user,array $body): void
    {
        if (array_keys($body)!==['installation_id']) Http::error(400,Http::MSG_BAD_REQUEST);
        try { $id=self::uuid($body['installation_id']); } catch (InvalidArgumentException $e) { Http::error(400,Http::MSG_BAD_REQUEST); }
        $count=Db::instance()->run('DELETE FROM client_diagnostic_events WHERE owner_id=:uid AND installation_id=CAST(:id AS uuid)',
                                 [':uid'=>(int)$user['id'],':id'=>$id])->rowCount();
        Audit::allow('client_diagnostics.delete',['target_type'=>'user','target_id'=>(int)$user['id'],'detail'=>['diag_deleted'=>$count]]);
    }

    public static function setPolicy(array $body): void
    {
        $keys=array_keys($body);sort($keys);
        if ($keys!==['daily_enabled','daily_hour','retention_days','weekly_enabled','weekly_weekday']) Http::error(400,Http::MSG_BAD_REQUEST);
        foreach (['daily_enabled','weekly_enabled'] as $k) if (!is_bool($body[$k])) Http::error(400,Http::MSG_BAD_REQUEST);
        foreach (['daily_hour'=>[0,23],'weekly_weekday'=>[1,7],'retention_days'=>[1,30]] as $k=>[$min,$max]) {
            if (!is_int($body[$k]) || $body[$k]<$min || $body[$k]>$max) Http::error(400,Http::MSG_BAD_REQUEST);
        }
        Db::instance()->run('UPDATE client_diagnostic_policy SET retention_days=:days,daily_enabled=:daily,weekly_enabled=:weekly,daily_hour=:hour,weekly_weekday=:weekday WHERE singleton',
            [':days'=>$body['retention_days'],':daily'=>$body['daily_enabled'],':weekly'=>$body['weekly_enabled'],':hour'=>$body['daily_hour'],':weekday'=>$body['weekly_weekday']]);
        Audit::allow('client_diagnostics.policy',['target_type'=>'system','detail'=>['diag_policy'=>$body]]);
    }

    public static function summary(string $start,string $end): array
    {
        $db=Db::instance();$bind=[':start'=>$start,':end'=>$end];
        $metrics=$db->run(<<<'SQL'
            WITH runs AS (
                SELECT installation_id,run_id,bool_or(event='run_started') AS observed,
                    bool_or(event='run_completed') AS completed,
                    bool_or(event='run_completed' AND attempt_kind='initial') AS first_done,
                    bool_or(attempt_kind='resume') AS resumed,
                    bool_or(event='run_completed' AND attempt_kind='resume') AS resume_done
                FROM client_diagnostic_events WHERE received_at>=CAST(:start AS timestamptz) AND received_at<CAST(:end AS timestamptz)
                GROUP BY installation_id,run_id
            ) SELECT count(*) FILTER(WHERE observed) AS observed_runs,
                count(*) FILTER(WHERE observed AND completed) AS completed_runs,
                count(*) FILTER(WHERE observed AND first_done) AS first_completed_runs,
                count(*) FILTER(WHERE observed AND resumed) AS resumed_runs,
                count(*) FILTER(WHERE observed AND resume_done) AS resumed_completed_runs,
                count(*) FILTER(WHERE NOT observed) AS censored_runs FROM runs
            SQL,$bind)->fetch();
        $totals=$db->run(<<<'SQL'
            SELECT count(*) AS events,count(DISTINCT installation_id) AS installations,
                count(DISTINCT request_id) FILTER(WHERE event='request_started') AS ai_request_attempts,
                count(DISTINCT request_id) FILTER(WHERE event='response_received') AS completed_responses,
                count(*) FILTER(WHERE event IN ('cache_reused','checkpoint_reused')) AS cache_reuses,
                percentile_cont(0.5) WITHIN GROUP(ORDER BY elapsed_ms) FILTER(WHERE event='run_completed') AS median_completion_ms
            FROM client_diagnostic_events WHERE received_at>=CAST(:start AS timestamptz) AND received_at<CAST(:end AS timestamptz)
            SQL,$bind)->fetch();
        $groups=$db->run(<<<'SQL'
            SELECT app_version,code_id,stage,error_code,count(*) AS failure_events,
                count(DISTINCT installation_id) AS affected_installations,
                count(DISTINCT (installation_id,run_id)) AS affected_runs,
                count(*) FILTER(WHERE event='run_failed') AS interrupted_attempts
            FROM client_diagnostic_events WHERE received_at>=CAST(:start AS timestamptz) AND received_at<CAST(:end AS timestamptz) AND status='failure'
            GROUP BY app_version,code_id,stage,error_code ORDER BY affected_installations DESC,affected_runs DESC,interrupted_attempts DESC LIMIT 100
            SQL,$bind)->fetchAll();
        foreach ($metrics as $k=>$v) $metrics[$k]=(int)$v;
        foreach ($totals as $k=>$v) $totals[$k]=$v===null ? null:(int)$v;
        $metrics['first_completion_rate']=$metrics['observed_runs'] ? $metrics['first_completed_runs']/$metrics['observed_runs']:null;
        $metrics['resume_success_rate']=$metrics['resumed_runs'] ? $metrics['resumed_completed_runs']/$metrics['resumed_runs']:null;
        foreach ($groups as &$group) {
            foreach (['failure_events','affected_installations','affected_runs','interrupted_attempts'] as $key) $group[$key]=(int)$group[$key];
            $group['reproduction']='관측 기록입니다. 합성 재현·검토 전에는 원인 확정이나 자동 수정으로 취급하지 않습니다.';
            $group['test_target']=self::testTarget($group['error_code']);
        }
        unset($group);
        return ['schema'=>1,'from'=>$start,'to'=>$end,'metrics'=>$metrics+$totals,'improvement_candidates'=>$groups,
                'observed_only'=>true,'unobserved_is_not_success'=>true,'usage_tokens'=>null,'cost'=>null];
    }

    public static function testTarget(string $code): string
    {
        return match($code) {
            'source_numbers_invalid'=>'test_closing_missing_values',
            'checkpoint_storage','checkpoint_integrity','save_unconfirmed','save_failed'=>'test_closing_checkpoints + test_closing_safe_resume',
            'clock_error','invalid_identity','signature_error'=>'test_auth_clock',
            'stream_interrupted','stream_timeout','incomplete'=>'test_chatgpt_streaming',
            default=>'test_closing_safe_resume + 원인별 합성 재현 추가',
        };
    }

    public static function rollup(?DateTimeImmutable $now=null): array
    {
        $now=($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Asia/Seoul'));
        $policy=self::policy();$db=Db::instance();$db->begin();$made=[];
        try {
            $locked=$db->run('SELECT pg_try_advisory_xact_lock(74626713)')->fetchColumn();
            if (!$locked) { $db->rollback();return ['busy'=>true,'created'=>[]]; }
            $db->run("DELETE FROM client_diagnostic_events WHERE received_at<clock_timestamp()-CAST(:days AS integer)*INTERVAL '1 day'",
                     [':days'=>$policy['retention_days']]);
            $db->run("DELETE FROM client_diagnostic_rollups WHERE created_at<clock_timestamp()-INTERVAL '30 days'");
            if ((int)$now->format('G') >= $policy['daily_hour']) {
                if ($policy['daily_enabled']) $made['day']=[$now->setTime(0,0)->modify('-1 day'),$now->setTime(0,0)];
                if ($policy['weekly_enabled'] && (int)$now->format('N')===$policy['weekly_weekday']) {
                    $made['week']=[$now->setTime(0,0)->modify('-7 days'),$now->setTime(0,0)];
                }
            }
            foreach ($made as $kind=>[$start,$end]) {
                $exists=$db->run('SELECT 1 FROM client_diagnostic_rollups WHERE kind=:kind AND period_start=CAST(:start AS date)',
                                [':kind'=>$kind,':start'=>$start->format('Y-m-d')])->fetchColumn();
                if ($exists) { unset($made[$kind]);continue; }
                $summary=self::summary($start->format(DateTimeInterface::ATOM),$end->format(DateTimeInterface::ATOM));
                $db->run('INSERT INTO client_diagnostic_rollups(kind,period_start,period_end,summary) VALUES(:kind,CAST(:start AS date),CAST(:end AS date),CAST(:summary AS jsonb))',
                    [':kind'=>$kind,':start'=>$start->format('Y-m-d'),':end'=>$end->format('Y-m-d'),':summary'=>json_encode($summary,JSON_THROW_ON_ERROR)]);
            }
            $db->commit();return ['busy'=>false,'created'=>array_keys($made)];
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollback();throw $e; }
    }
}
