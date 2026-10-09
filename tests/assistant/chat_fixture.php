<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require_once dirname(__DIR__,2) . '/src/Env.php';
Env::load((string)getenv('ASSISTANT_FIXTURE_CONFIG_FILE'));
require_once dirname(__DIR__,2) . '/src/bootstrap.php';
require_once dirname(__DIR__,2) . '/src/Legal.php';
if (!str_starts_with(Env::get('DB_NAME'), 'tbox_assistant_test_')) exit(3);
$db = Db::instance();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$uid = (int)($input['uid'] ?? 0);
switch ($input['action']) {
    case 'reset_rate': $db->run('DELETE FROM member_rate_limits'); break;
    case 'enable': $db->run('UPDATE member_ai_settings SET enabled=true,user_daily=40,site_daily=300 WHERE id=1'); break;
    case 'disable': $db->run('UPDATE member_ai_settings SET enabled=false WHERE id=1'); break;
    case 'consent':
        $db->run("INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(:uid,'ai_overseas',:version,:action,'127.0.0.1')",
            [':uid'=>$uid, ':version'=>Legal::current('ai_overseas'), ':action'=>$input['agree'] ? 'agree':'withdraw']); break;
    case 'limit': $db->run('UPDATE member_ai_settings SET user_daily=1 WHERE id=1'); break;
    case 'counts': echo json_encode($db->run('SELECT count(*) AS turns FROM member_ai_turns')->fetch()); break;
    case 'stale': $db->run("UPDATE member_ai_turns SET state='pending',answer=NULL,error_code=NULL,created_at=now()-interval '121 seconds' WHERE request_id=CAST(:id AS uuid)",
        [':id'=>$input['id']]); break;
    default: exit(2);
}
