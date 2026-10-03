<?php
declare(strict_types=1);
$db=Db::instance();$method=$_SERVER['REQUEST_METHOD'];$me=(int)$user['id'];$admin=str_contains(TBOX_ROUTE,'/admin/');
if($method==='GET') {
    $rows=$db->run('SELECT w.id,p.public_id AS page_id,p.title,u.display_name AS creator,w.created_at,w.expires_at,w.revoked_at,w.allow_index,w.view_count,w.last_viewed_at FROM community.web_publications w JOIN community.pages p ON p.id=w.page_id JOIN public.users u ON u.id=w.created_by WHERE :admin OR w.created_by=:me ORDER BY w.id DESC LIMIT 500',
        [':admin'=>$admin?'true':'false',':me'=>$me])->fetchAll();
    Http::json(['publications'=>$rows]);return;
}
$db->begin();
try {
    $db->run('SELECT id FROM community.pages WHERE public_id=CAST(:id AS uuid) FOR UPDATE',
        [':id'=>$params[0]]);
    $p=community_page_row($params[0],$me);
    if($p===null) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
    if($method==='DELETE' || $method==='PATCH') {
        $w=$db->run('SELECT * FROM community.web_publications WHERE id=:id AND page_id=:p FOR UPDATE',
        [':id'=>(int)$params[1],':p'=>(int)$p['id']])->fetch();
        if(!is_array($w)) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
        $admin=Authz::authorize('role:Admin',$user) && ($user['reauth_fresh']??false);
        if($method==='PATCH') {
            if(!$admin) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
            $b=community_integration_body(['allow_index']);if(!is_bool($b['allow_index']??null)) { throw new InvalidArgumentException('allow_index'); }
            $db->run('UPDATE community.web_publications SET allow_index=:allow WHERE id=:id',
        [':allow'=>$b['allow_index']?'true':'false',':id'=>(int)$w['id']]);
            community_integration_audit('page.web_publish',(int)$p['id'],['allow_index'=>$b['allow_index']]);
        }else{
            if(!$admin && (int)$w['created_by']!==$me && !CommunityAuthz::decidePageManage($user,$p)[0]) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
            $db->run('UPDATE community.web_publications SET revoked_at=coalesce(revoked_at,now()),revoked_by=:u WHERE id=:id',
        [':u'=>$me,':id'=>(int)$w['id']]);
            community_integration_audit('page.web_unpublish',(int)$p['id']);
        }
        $db->commit();Http::json(['ok'=>true]);return;
    }
    [$ok,$code]=CommunityAuthz::decideWebPublish($user,$p);
    if(!$ok) { $db->rollback();Http::error($code==='invalid_target'?400:403,$code==='invalid_target'?Http::MSG_BAD_REQUEST:Http::MSG_FORBIDDEN); }
    $b=community_integration_body(['children','expires_days','allow_index','confirm_public']);
    $days=array_key_exists('expires_days',$b)?$b['expires_days']:30;$children=$b['children']??[];$index=$b['allow_index']??false;
    if(!in_array($days,[7,30,90,null],true)||!is_array($children)||!array_is_list($children)||count($children)>200||!is_bool($index)) { throw new InvalidArgumentException('shape'); }
    if($index && !(Authz::authorize('role:Admin',$user)&&($user['reauth_fresh']??false))) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
    $ids=[(int)$p['id']];$desc=community_subtree_live_ids((int)$p['id']);$confirm=in_array(CommunityAuthz::baseVisibility($p),['private','team'],true);
    foreach($children as $uuid) { if(!is_string($uuid)||!Doc::isMentionPageId($uuid)) { throw new InvalidArgumentException('children'); } }
    foreach(array_unique($children) as $uuid) {
        if(!is_string($uuid)||!Doc::isMentionPageId($uuid)) { throw new InvalidArgumentException('children'); }
        $child=community_page_row($uuid,$me);
        if($child===null || !in_array((int)$child['id'],$desc,true) || !CommunityAuthz::decideWebPublish($user,$child)[0]) { throw new InvalidArgumentException('children'); }
        $ids[]=(int)$child['id'];$confirm=$confirm||in_array(CommunityAuthz::baseVisibility($child),['private','team'],true);
    }
    if($confirm && ($b['confirm_public']??false)!==true) { $db->rollback();Http::json(['error'=>'이 페이지의 내용을 로그인 없이 공개합니다. 공개를 확인해 주세요','reason'=>'confirmation_required'],400);return; }
    $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$expires=$days===null?null:(new DateTimeImmutable())->modify('+'.$days.' days')->format(DATE_ATOM);
    $id=(int)$db->run('INSERT INTO community.web_publications(token_hash,page_id,created_by,expires_at,allow_index) VALUES(:hash,:page,:u,CAST(:expires AS timestamptz),:allow) RETURNING id',
        [':hash'=>hash('sha256',$token),':page'=>(int)$p['id'],':u'=>$me,':expires'=>$expires,':allow'=>$index?'true':'false'])->fetchColumn();
    foreach(array_unique($ids) as $pid) { $db->run('INSERT INTO community.web_publication_pages(publication_id,page_id) VALUES(:w,:p)',
        [':w'=>$id,':p'=>$pid]); }
    community_integration_audit('page.web_publish',(int)$p['id'],['publication_id'=>$id,'pages'=>count($ids)]);
    $db->commit();Http::json(['id'=>$id,'url'=>Http::communityUrl('/pub/'.$token),'expires_at'=>$expires],201);
}catch(InvalidArgumentException $e){$db->rollback();Http::error(400,Http::MSG_BAD_REQUEST);}
catch(Throwable $e){$db->rollback();throw $e;}
