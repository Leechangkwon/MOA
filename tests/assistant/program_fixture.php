<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(2);
require_once dirname(__DIR__,2).'/src/Env.php';
Env::load((string)getenv('ASSISTANT_FIXTURE_CONFIG_FILE'));
require_once dirname(__DIR__,2).'/src/bootstrap.php';
if (!str_starts_with(Env::get('DB_NAME'),'tbox_assistant_test_')) exit(3);
$db=Db::instance(); $in=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
switch ($in['action']) {
    case 'otp_reset': $db->run('UPDATE users SET mfa_last_step=NULL WHERE id=:id',
        [':id'=>$in['uid']]); break;
    case 'scan':
        $row=$db->run('SELECT * FROM files WHERE public_id=CAST(:id AS uuid)',
            [':id'=>$in['id']])->fetch();
        $db->run("UPDATE files SET scan_status='clean' WHERE id=:id",
            [':id'=>$row['id']]);
        if ($in['proof'] ?? true) Audit::allow('file.scan',['target_type'=>'file','target_id'=>(int)$row['id'],
            'target_name'=>$row['original_name'],'detail'=>['sha256'=>$row['sha256'],'mode'=>'scan','signatures'=>'SYNTHETIC ONLY']]); break;
    case 'infect': $db->run("UPDATE files SET scan_status='infected' WHERE public_id=CAST(:id AS uuid)",
            [':id'=>$in['id']]); break;
    case 'corrupt':
        $row=$db->run('SELECT stored_name FROM files WHERE public_id=CAST(:id AS uuid)',
            [':id'=>$in['id']])->fetch();
        file_put_contents(Storage::pathFor($row['stored_name']), 'synthetic corrupted bytes'); break;
    case 'summary': echo json_encode($db->run('SELECT count(*) AS files,count(*) FILTER(WHERE program) AS programs,count(*) FILTER(WHERE program_current) AS current FROM files')->fetch()); break;
    default: exit(2);
}
