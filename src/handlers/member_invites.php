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
if (!Member::activeTeam($team)) Http::error(400,'활성 팀을 골라 주세요.');
Member::rate('invite',(string)$user['id'],10);
// 사이트 H2 — 발급(코드 · 해시 · 행)은 Member::issueInvite 한 벌(초대 요청 확인 주소도 같은 함수). 판정 · 응답 · 감사는 전과 같다.
$invite=Member::issueInvite((int)$user['id'],trim($label),$team,$uses,$days);
Audit::allow('member.invite.create',
        ['target_type'=>'system','target_id'=>$invite['id'],'detail'=>['reason_text'=>trim($label),'target_team'=>$team]]);
Http::json(['id'=>$invite['id'],'path'=>$invite['path'],'expires_at'=>$invite['expires_at']],201);
