<?php
declare(strict_types=1);
$db=Db::instance();$me=(int)$user['id'];$method=$_SERVER['REQUEST_METHOD'];$uuid=$params[0]??null;
if($method==='GET') {
    if($uuid!==null) {
        $r=community_group_row($uuid,$user);
        if($r===null || !CommunityAuthz::decideGroupRead($user,$r)) { Http::error(404,Http::MSG_NOT_FOUND); }
        Http::json(['group'=>community_group_out($r,$user)]);return;
    }
    $admin=Authz::authorize('role:Admin',$user);
    $rows=$db->run('SELECT g.*,EXISTS(SELECT 1 FROM community.share_group_members m WHERE m.group_id=g.id AND m.user_id=:u) AS is_member FROM community.share_groups g WHERE g.deleted_at IS NULL AND (:admin OR g.created_by=:u OR EXISTS(SELECT 1 FROM community.share_group_members m WHERE m.group_id=g.id AND m.user_id=:u)) ORDER BY lower(g.name)',
        [':u'=>$me,':admin'=>$admin?'true':'false'])->fetchAll();
    Http::json(['groups'=>array_map(static fn(array $r): array=>community_group_out($r,$user),$rows),'can_create'=>Authz::authorize('role:Manager+',$user)]);return;
}
$db->begin();
try {
    if($uuid===null) {
        if(!Authz::authorize('role:Manager+',$user)) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
        $b=community_integration_body(['name','members']);$name=community_integration_name($b['name']??null);$members=$b['members']??[];
        $uuid=tbox_uuid_v4();$admin=Authz::authorize('role:Admin',$user);
        $db->run('INSERT INTO community.share_groups(public_id,name,created_by,kind,manager_team_id) VALUES(CAST(:uuid AS uuid),:name,:uid,:kind,:team)',
        [':uuid'=>$uuid,':name'=>$name,':uid'=>$me,':kind'=>$admin?'admin':'manager',':team'=>$admin?null:$user['team_id']]);
        $r=community_group_row($uuid,$user,true);community_integration_audit('group.create',(int)$r['id']);
    } else {
        $r=community_group_row($uuid,$user,true);
        if($r===null || !CommunityAuthz::decideGroupRead($user,$r)) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
        if(!CommunityAuthz::decideGroupManage($user,$r)) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
        $b=$method==='DELETE'?[]:community_integration_body(['name','members']);$members=$b['members']??null;
        if(isset($b['name'])) { $db->run('UPDATE community.share_groups SET name=:name WHERE id=:id',
        [':name'=>community_integration_name($b['name']),':id'=>(int)$r['id']]); }
    }
    if($method==='DELETE') {
        $members=[];$db->run('UPDATE community.share_groups SET deleted_at=now() WHERE id=:id',
        [':id'=>(int)$r['id']]);community_integration_audit('group.delete',(int)$r['id']);
    }
    if($members!==null) {
        if(!is_array($members)||!array_is_list($members)||count($members)>500) { throw new InvalidArgumentException('members'); }
        $want=[];
        foreach($members as $name) {
            if(!is_string($name)) { throw new InvalidArgumentException('members'); }
            $u=community_user_by_name($name);
            if($u===null || !CommunityAuthz::decideGroupMember($user,$r,$u)) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
            $want[(int)$u['id']]=true;
        }
        $old=$db->run('SELECT user_id FROM community.share_group_members WHERE group_id=:g',
        [':g'=>(int)$r['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach($old as $uid) {
            if(isset($want[(int)$uid])) { unset($want[(int)$uid]);continue; }
            $db->run('DELETE FROM community.share_group_members WHERE group_id=:g AND user_id=:u',
        [':g'=>(int)$r['id'],':u'=>(int)$uid]);
            $db->run("INSERT INTO community.group_member_events(group_id,user_id,action,by_user_id) VALUES(:g,:u,'remove',:by)",
        [':g'=>(int)$r['id'],':u'=>(int)$uid,':by'=>$me]);community_integration_audit('group.member_remove',(int)$r['id']);
        }
        foreach(array_keys($want) as $uid) {
            $db->run('INSERT INTO community.share_group_members(group_id,user_id,added_by) VALUES(:g,:u,:by)',
        [':g'=>(int)$r['id'],':u'=>$uid,':by'=>$me]);
            $db->run("INSERT INTO community.group_member_events(group_id,user_id,action,by_user_id) VALUES(:g,:u,'add',:by)",
        [':g'=>(int)$r['id'],':u'=>$uid,':by'=>$me]);community_integration_audit('group.member_add',(int)$r['id']);
        }
    }
    $db->commit();Http::json(['ok'=>true,'id'=>$uuid]);
}catch(InvalidArgumentException $e) { $db->rollback();Http::error(400,Http::MSG_BAD_REQUEST); }
catch(PDOException $e) { $db->rollback();if($e->getCode()==='23505') { Http::error(400,'이미 쓰는 그룹 이름입니다'); }throw $e; }
catch(Throwable $e) { $db->rollback();throw $e; }
