<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
$db=Db::instance();
if (Http::method()==='GET') {
    $rows=$db->run('SELECT i.id,i.label,i.team_id,t.name AS team_name,i.created_at,i.expires_at,i.max_uses,i.used_count,i.revoked_at FROM member_invites i LEFT JOIN teams t ON t.id=i.team_id ORDER BY i.id DESC LIMIT 100')->fetchAll();
    Http::json(['invites'=>$rows,'buddy_download_url'=>Member::installer()]); exit;
}
$body=Http::jsonBody(4096);
if (Http::method()==='DELETE') {
    $db->run('UPDATE member_invites SET revoked_at=now() WHERE id=:id AND revoked_at IS NULL',
        [':id'=>(int)$params[0]]);
    Audit::allow('member.invite.revoke',
        ['target_type'=>'system','target_id'=>(int)$params[0]]);
    Http::json(['ok'=>true]); exit;
}
if (array_diff(array_keys($body),['label','team_id','max_uses','days'])!==[]) Http::error(400,Http::MSG_BAD_REQUEST);
$label=$body['label']??null; $team=$body['team_id']??null; $uses=$body['max_uses']??1; $days=$body['days']??7;
if (!is_string($label)||preg_match('//u',$label)!==1||trim($label)===''||iconv_strlen($label,'UTF-8')>80||preg_match('/\p{C}/u',$label)||!is_int($uses)||$uses<1||$uses>100||!is_int($days)||$days<1||$days>30||($team!==null&&(!is_int($team)||$team<1))) Http::error(400,Http::MSG_BAD_REQUEST);
if ($team!==null&&!$db->run('SELECT id FROM teams WHERE id=:id AND is_active',
        [':id'=>$team])->fetch()) Http::error(400,'활성 팀을 골라 주세요.');
Member::rate('invite',(string)$user['id'],10);
$code=bin2hex(random_bytes(32));
$row=$db->run("INSERT INTO member_invites(code_hash,label,team_id,created_by,expires_at,max_uses) VALUES(:hash,:label,:team,:by,now()+make_interval(days=>:days),:uses) RETURNING id,expires_at",
        [':hash'=>hash('sha256',$code),':label'=>trim($label),':team'=>$team,':by'=>(int)$user['id'],':days'=>$days,':uses'=>$uses])->fetch();
Audit::allow('member.invite.create',
        ['target_type'=>'system','target_id'=>(int)$row['id'],'detail'=>['reason_text'=>trim($label),'target_team'=>$team]]);
Http::json(['id'=>(int)$row['id'],'path'=>Http::url('/join').'#invite='.$code,'expires_at'=>$row['expires_at']],201);
