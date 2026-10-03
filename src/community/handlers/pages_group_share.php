<?php
declare(strict_types=1);
$db=Db::instance();$db->begin();
try {
    $db->run('SELECT id FROM community.pages WHERE public_id=CAST(:id AS uuid) FOR UPDATE',
        [':id'=>$params[0]]);
    $p=community_page_row($params[0],(int)$user['id']);
    if($p===null) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
    if(!CommunityAuthz::decidePageShare($user,$p)[0]) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
    $g=community_group_row($params[1],$user,true);
    if($g===null || !CommunityAuthz::decideGroupRead($user,$g)) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
    if($_SERVER['REQUEST_METHOD']==='DELETE') {
        $db->run('DELETE FROM community.page_shares WHERE page_id=:p AND group_id=:g',
        [':p'=>(int)$p['id'],':g'=>(int)$g['id']]);
    }else{
        $b=community_integration_body(['level']);if(!in_array($b['level']??null,['view','edit'],true)) { throw new InvalidArgumentException('level'); }
        $db->run('INSERT INTO community.page_shares(page_id,group_id,level,granted_by) VALUES(:p,:g,:level,:u) ON CONFLICT(page_id,group_id) DO UPDATE SET level=EXCLUDED.level,granted_by=EXCLUDED.granted_by',
        [':p'=>(int)$p['id'],':g'=>(int)$g['id'],':level'=>$b['level'],':u'=>(int)$user['id']]);
    }
    Audit::allow('page.share',['target_type'=>'page','target_id'=>(int)$p['id'],'target_name'=>community_target_title($p['title']),'detail'=>['group_id'=>$g['public_id']]]);
    $db->commit();Http::json(['ok'=>true]);
}catch(InvalidArgumentException $e){$db->rollback();Http::error(400,Http::MSG_BAD_REQUEST);}
catch(Throwable $e){$db->rollback();throw $e;}
