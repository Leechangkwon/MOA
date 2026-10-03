<?php
declare(strict_types=1);
$db=Db::instance();$method=$_SERVER['REQUEST_METHOD'];$me=(int)$user['id'];$connector=str_contains(TBOX_ROUTE,'/connectors');$admin=str_contains(TBOX_ROUTE,'/admin/');
if(!$admin) {
    if($method==='GET') {
        $rows=$db->run('SELECT h.id,h.name,h.is_active,m.kinds FROM community.messenger_hooks h LEFT JOIN community.user_messenger m ON m.hook_id=h.id AND m.user_id=:u ORDER BY h.id',
        [':u'=>$me])->fetchAll();
        foreach($rows as &$r) { $r['kinds']=$r['kinds']===null?[]:str_getcsv(trim($r['kinds'],'{}'));if($r['kinds']===['']){$r['kinds']=[];} }unset($r);
        Http::json(['hooks'=>$rows]);return;
    }
    $b=community_integration_body(['hook_id','kinds']);$id=$b['hook_id']??null;$kinds=$b['kinds']??null;
    $allowed=['mention','comment_on_my_page','reply','verify_expired','form_response','handover','recurring_created','review_requested','due_tomorrow','automation_denied','automation_disabled'];
    if(!is_int($id)||!is_array($kinds)||!array_is_list($kinds)||count($kinds)>20||count(array_filter($kinds,'is_string'))!==count($kinds)||array_diff($kinds,$allowed)!==[]) { Http::error(400,Http::MSG_BAD_REQUEST); }
    if(!$db->run('SELECT 1 FROM community.messenger_hooks WHERE id=:id',
        [':id'=>$id])->fetchColumn()) { Http::error(404,Http::MSG_NOT_FOUND); }
    $db->run('INSERT INTO community.user_messenger(user_id,hook_id,kinds) VALUES(:u,:h,CAST(:kinds AS text[])) ON CONFLICT(user_id,hook_id) DO UPDATE SET kinds=EXCLUDED.kinds',
        [':u'=>$me,':h'=>$id,':kinds'=>'{'.implode(',',array_unique($kinds)).'}']);Http::json(['ok'=>true]);return;
}
if($method==='GET') {
    $rows=$connector?$db->run('SELECT c.*,d.public_id AS database_uuid FROM community.data_connectors c JOIN community.databases d ON d.id=c.database_id ORDER BY c.id')->fetchAll()



        :$db->run('SELECT * FROM community.messenger_hooks ORDER BY id')->fetchAll()



        ;
    foreach($rows as &$r) {
        $id=(int)$r['id'];$r['secret_configured']=community_secret_configured($connector?'connectors':'hooks',$id);
        if($connector) { $r['database_id']=$r['database_uuid'];unset($r['database_uuid']);$r['schedule']=json_decode($r['schedule'],true);$r['field_map']=json_decode($r['field_map'],true); }
    }unset($r);Http::json([$connector?'connectors':'hooks'=>$rows]);return;
}
$b=community_integration_body($connector?['name','database_id','url_host','url_path','schedule','field_map','is_active']:['name','url_host','is_active']);
$id=isset($params[0])?($connector?$params[0]:(int)$params[0]):null;
$old=$id===null?null:($connector?$db->run('SELECT * FROM community.data_connectors WHERE public_id=CAST(:id AS uuid)',
        [':id'=>$id])->fetch():$db->run('SELECT * FROM community.messenger_hooks WHERE id=:id',
        [':id'=>$id])->fetch());
if($id!==null && !is_array($old)) { Http::error(404,Http::MSG_NOT_FOUND); }
$name=community_integration_name($b['name']??($old['name']??null));$host=$b['url_host']??($old['url_host']??null);$path=$connector?($b['url_path']??($old['url_path']??null)):'/';$active=$b['is_active']??($old['is_active']??false);
if(!is_string($host)||!is_string($path)||!Fetch::integrationHost($host,$path)||!is_bool($active)||str_contains($path,'?')||str_contains($path,'#')) { Http::error(400,Http::MSG_BAD_REQUEST); }
$db->begin();
try {
    if($connector) {
        $database=isset($b['database_id'])?community_db_row($b['database_id']):($old===null?null:$db->run('SELECT d.*,p.public_id AS page_public_id FROM community.databases d JOIN community.pages p ON p.id=d.page_id WHERE d.id=:id',
        [':id'=>(int)$old['database_id']])->fetch());
        if(!$database) { throw new InvalidArgumentException('database'); }
        $uuid=$db->run('SELECT public_id FROM community.pages WHERE id=:id',
        [':id'=>(int)$database['page_id']])->fetchColumn();$page=community_page_row($uuid,$me);
        if($page===null || !CommunityAuthz::decidePageChange($user,$page)[0]) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
        if($old!==null && (int)$old['database_id']!==(int)$database['id']) { throw new InvalidArgumentException('database_immutable'); }
        $schedule=$b['schedule']??json_decode($old['schedule']??'null',true);$map=$b['field_map']??json_decode($old['field_map']??'null',true);
        if(!is_array($schedule)||!is_array($map)||array_is_list($map)||count($map)>30) { throw new InvalidArgumentException('shape'); }
        Automation::validate(['kind'=>'recurring','name'=>'connector','scope_page_id'=>$page['public_id'],'trigger'=>$schedule,'actions'=>[['type'=>'notify','text_key'=>'recurring_created','to'=>'owner']]]);
        $schema=community_db_schema_of($database['schema']);
        foreach($map as $from=>$to) {
            if(!is_string($from)||preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$from)!==1||!is_string($to)) { throw new InvalidArgumentException('field_map'); }
            $prop=DbProps::find($schema,$to);if($prop===null||!in_array($prop['type'],['text','number','checkbox','url','date','select','multi_select'],true)) { throw new InvalidArgumentException('field_map'); }
        }
        if($old===null) {
            $id=tbox_uuid_v4();$n=(int)$db->run('INSERT INTO community.data_connectors(public_id,name,database_id,url_host,url_path,runner_user_id,schedule,field_map,is_active,created_by) VALUES(CAST(:uuid AS uuid),:name,:db,:host,:path,:runner,CAST(:schedule AS jsonb),CAST(:map AS jsonb),:active,:creator) RETURNING id',
        [':uuid'=>$id,':name'=>$name,':db'=>(int)$database['id'],':host'=>$host,':path'=>$path,':runner'=>$me,':schedule'=>community_integration_json($schedule),':map'=>community_integration_json($map),':active'=>$active?'true':'false',':creator'=>$me])->fetchColumn();
        } else {
            $n=(int)$old['id'];$db->run('UPDATE community.data_connectors SET name=:name,url_host=:host,url_path=:path,schedule=CAST(:schedule AS jsonb),field_map=CAST(:map AS jsonb),is_active=:active WHERE id=:id',
        [':name'=>$name,':host'=>$host,':path'=>$path,':schedule'=>community_integration_json($schedule),':map'=>community_integration_json($map),':active'=>$active?'true':'false',':id'=>$n]);
        }
    }else{
        if($old===null) { $n=(int)$db->run('INSERT INTO community.messenger_hooks(name,url_host,is_active,created_by) VALUES(:name,:host,:active,:u) RETURNING id',
        [':name'=>$name,':host'=>$host,':active'=>$active?'true':'false',':u'=>$me])->fetchColumn();$id=$n; }
        else { $n=(int)$old['id'];$db->run('UPDATE community.messenger_hooks SET name=:name,url_host=:host,is_active=:active WHERE id=:id',
        [':name'=>$name,':host'=>$host,':active'=>$active?'true':'false',':id'=>$n]); }
    }
    community_integration_audit('integration.configure',$n,['purpose'=>$connector?'connector':'messenger','host'=>$host,'is_active'=>$active]);$db->commit();
    Http::json(['ok'=>true,'id'=>$id,'secret_file_id'=>$n,'secret_configured'=>community_secret_configured($connector?'connectors':'hooks',$n)]);
}catch(InvalidArgumentException $e){$db->rollback();Http::json(['error'=>'연결 설정을 확인해 주세요','reason'=>$e->getMessage()],400);}
catch(Throwable $e){$db->rollback();throw $e;}
