<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(2);
require_once dirname(__DIR__,2).'/src/Env.php';
$cfg=getenv('ASSISTANT_FIXTURE_CONFIG_FILE');if (!is_string($cfg) || $cfg==='') exit(3);
Env::load($cfg);require_once dirname(__DIR__,2).'/src/bootstrap.php';
if (!str_starts_with(Env::get('DB_NAME'),'tbox_assistant_test_')) exit(3);
require_once dirname(__DIR__,2).'/src/ClientDiagnostics.php';
$arg=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);$db=Db::instance();
switch ($arg['action'] ?? '') {
case 'snapshot':
    $files=$db->run("SELECT md5(string_agg(to_jsonb(f)::text,'' ORDER BY id)) FROM files f")->fetchColumn();
    $scopes=$db->run('SELECT id,scope,token_hash FROM api_tokens ORDER BY id')->fetchAll();
    echo json_encode(['files'=>$files,'scopes'=>$scopes]);break;
case 'grants':
    echo json_encode($db->run("SELECT has_table_privilege('tbox_backup','client_diagnostic_events','SELECT') AS backup_events,has_table_privilege('tbox_backup','client_diagnostic_policy','SELECT') AS backup_policy,has_table_privilege('tbox_backup','client_diagnostic_rollups','SELECT') AS backup_rollups,has_sequence_privilege('tbox_backup','client_diagnostic_events_id_seq','SELECT') AS backup_sequence,has_table_privilege('tbox_backup','client_diagnostic_events','INSERT') AS backup_write,has_table_privilege('tbox_app','client_diagnostic_events','UPDATE') AS app_update,has_table_privilege('tbox_app','client_diagnostic_events','INSERT') AS app_insert")->fetch());break;
case 'counts':
    echo json_encode($db->run('SELECT owner_id,count(*) AS events FROM client_diagnostic_events GROUP BY owner_id ORDER BY owner_id')->fetchAll());break;
case 'age':
    $db->run("UPDATE client_diagnostic_events SET received_at=clock_timestamp()-INTERVAL '35 days' WHERE event_id=CAST(:id AS uuid)",
             [':id'=>$arg['id']]);echo '{}';break;
case 'rollup':
    echo json_encode(ClientDiagnostics::rollup(new DateTimeImmutable($arg['now'])));break;
case 'quota':
    $uid=(int)$arg['uid'];
    $source=$db->run('SELECT * FROM client_diagnostic_events WHERE owner_id=:id LIMIT 1',
                     [':id'=>$uid])->fetch();
    if (!$source) exit(4);
    for ($i=0;$i<1000;$i++) {
        $db->run(<<<'SQL'
            INSERT INTO client_diagnostic_events(owner_id,event_id,installation_id,run_id,request_id,app_version,code_id,event,stage,status,error_code,attempt_kind,elapsed_ms,occurred_at,payload,payload_sha256)
            VALUES(:uid,CAST(:eid AS uuid),CAST(:iid AS uuid),CAST(:rid AS uuid),NULL,:version,:code,'run_started','prepare','running','none','initial',0,clock_timestamp(),CAST(:payload AS jsonb),:sha)
            SQL,[':uid'=>$uid,':eid'=>tbox_uuid_v4(),':iid'=>$source['installation_id'],':rid'=>$source['run_id'],':version'=>$source['app_version'],':code'=>$source['code_id'],':payload'=>$source['payload'],':sha'=>$source['payload_sha256']]);
    }echo '{}';break;
default:exit(2);
}
