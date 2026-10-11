<?php
declare(strict_types=1);
// Included only inside the harness-owned empty database service suite.
if(PHP_SAPI!=='cli'||!isset($db,$svc,$sid)||!str_starts_with((string)getenv('PRODUCT_TEST_DB'),'tbox_product_test_'))exit(2);
function widgetSettings(string $type='my_tasks',array|stdClass $source=new stdClass()): array {
    return ['version'=>3,'source'=>'all','view'=>'recent','guide'=>'pending','widgets'=>[
        ['id'=>'widget-a','type'=>$type,'source'=>$source,'visible'=>true,'locked'=>false,
            'desktop'=>['x'=>0,'y'=>0,'w'=>480,'h'=>420,'size'=>'m'],
            'mobile'=>['x'=>0,'y'=>0,'w'=>360,'h'=>420,'size'=>'m']],
    ]];
}
function saveWidgets(ProductSpaces $service,?string $space,array $layout): array {
    return $service->dashboardSave($space,['layout'=>$layout,'revision'=>$service->dashboard($space)['revision']]);
}
$generic=ProductSpaces::defaultWidgetLayout();
check(array_column($generic['widgets'],'type')===['recent','favorites','briefing']&&$generic['guide']==='pending','v3 generic default is three widgets and pending guide');
check(array_column(array_column($generic['widgets'],'desktop'),'x')===[0,504,828],'v3 default first-fit shelf matches frontend coordinates');
check(array_column(array_column($generic['widgets'],'mobile'),'y')===[0,436,672],'v3 default mobile sequential geometry matches frontend');
$company=ProductSpaces::defaultWidgetLayout(['id'=>$sid,'kind'=>'company']);
check(array_column($company['widgets'],'type')===['space_task','space_notice','space_event','recent','favorites'],'v3 company default uses real space context');
check(array_column(array_column($company['widgets'],'desktop'),'y')===[0,0,444,444,0],'v3 later small card fills first-row gap');
$contextUser=$db->run('INSERT INTO users(username,password_hash,role,expires_at) VALUES(\'claude_widget_context\',\'synthetic-hash\',\'Employee\',now()+interval \'30 days\') RETURNING *')->fetch();
$contextService=new ProductSpaces($contextUser);
check($contextService->dashboard(null)['layout']==$generic,'v3 no-membership account gets generic recommendation');
$contextCompany=$contextService->create(['name'=>'추천 회사','kind'=>'company'])['space'];
check(array_column($contextService->dashboard(null)['layout']['widgets'],'type')===array_column($company['widgets'],'type'),'v3 exactly one company chooses company recommendation');
$contextGroup=$contextService->create(['name'=>'추천 모임','kind'=>'group'])['space'];
check($contextService->dashboard(null)['layout']==$generic,'v3 multiple memberships do not infer primary space');
$contextService->update($contextCompany['id'],['status'=>'archived']);
check(array_column($contextService->dashboard(null)['layout']['widgets'],'type')===['space_event','space_notice','recent','favorites'],'v3 only active group gives four widgets');
check($contextService->dashboard(null)['revision']===0,'v3 contextual defaults never create settings row');

$all=widgetSettings();$all['widgets']=[];
foreach(['my_tasks','briefing','recent','favorites','space_notice','space_event','space_task','legacy_recent','legacy_services','legacy_tools','legacy_stories'] as $i=>$type){
    $source=str_starts_with($type,'space_')?['space_id'=>$sid,'scope'=>$type==='space_task'?'mine':'all']:(in_array($type,['recent','favorites'],true)?['kind'=>'pages']:(object)[]);
    $widget=widgetSettings($type,$source)['widgets'][0];$widget['id']='widget-type-'.$i;$widget['desktop']['y']=$i*500;$widget['mobile']['y']=$i*500;$all['widgets'][]=$widget;
}
$savedV3=saveWidgets($svc['owner'],null,$all);
check($savedV3['layout']==$all,'v3 all seven personal and four legacy types roundtrip');
check(str_contains(json_encode($savedV3),'"source":{}'),'v3 empty source serialized as object rather than array');
$stable=layoutState();$loaded=$svc['owner']->dashboard(null);check($loaded==$savedV3&&layoutState()===$stable,'v3 GET preserves exact settings revision timestamps and audit');
$sharedV3=ProductSpaces::defaultWidgetLayout(['id'=>$sid,'kind'=>'company'],true);
foreach($sharedV3['widgets'] as &$widget)$widget['locked']=true;unset($widget);
check(saveWidgets($svc['editor'],$sid,$sharedV3)['layout']==$sharedV3,'v3 five shared widgets permit nonoverlapping locked anchors');
$twenty=widgetSettings();$baseWidget=$twenty['widgets'][0];$twenty['widgets']=[];
for($i=0;$i<20;$i++){$widget=$baseWidget;$widget['id']='widget-instance-'.$i;$twenty['widgets'][]=$widget;}
check(count(saveWidgets($svc['guest'],null,$twenty)['layout']['widgets'])===20,'v3 twenty distinct same-type instances allowed');
$empty=$twenty;$empty['widgets']=[];$empty['guide']='later';
check(saveWidgets($svc['guest'],null,$empty)['layout']==$empty,'v3 empty widget list and later guide remain writable');
$normal=widgetSettings();$normal['widgets'][0]['source']=[];$normal['guide']='done';
check(saveWidgets($svc['guest'],null,$normal)['layout']['widgets'][0]['source'] instanceof stdClass,'v3 associative-decoder empty source normalized');

$invalid=[];$base=widgetSettings();
$set=function(array $path,mixed $value,string $label)use(&$invalid,$base):void{$bad=$base;$at=&$bad;foreach($path as $key)$at=&$at[$key];$at=$value;unset($at);$invalid[]=[$bad,$label];};
$remove=function(array $path,string $label)use(&$invalid,$base):void{$bad=$base;$last=array_pop($path);$at=&$bad;foreach($path as $key)$at=&$at[$key];unset($at[$last]);unset($at);$invalid[]=[$bad,$label];};
foreach(['version','widgets','source','view','guide'] as $key)$remove([$key],'missing root '.$key);
foreach(['id','type','source','visible','locked','desktop','mobile'] as $key)$remove(['widgets',0,$key],'missing widget '.$key);
foreach(['desktop','mobile'] as $screen){
    foreach(['x','y','w','h','size'] as $key)$remove(['widgets',0,$screen,$key],"$screen missing $key");
    foreach(['x','y','w','h'] as $key)foreach([true,'160',1.0,null,PHP_INT_MAX,PHP_INT_MIN] as $bad)$set(['widgets',0,$screen,$key],$bad,"$screen $key ".get_debug_type($bad));
    foreach(['wide','M',0,null] as $bad)$set(['widgets',0,$screen,'size'],$bad,"$screen bad size ".json_encode($bad));
    $set(['widgets',0,$screen,'style'],'x','unexpected geometry key');
}
foreach(['','Widget-a','1a','a b','a/b','a'."\n",str_repeat('a',65)] as $id)$set(['widgets',0,'id'],$id,'invalid instance ID '.json_encode($id));
foreach([0,'true',null] as $bad){$set(['widgets',0,'visible'],$bad,'bad visibility');$set(['widgets',0,'locked'],$bad,'bad lock');}
foreach(['space_file','space_story','unknown'] as $type)$set(['widgets',0,'type'],$type,'personal invalid type '.$type);
foreach([null,true,'all',['title'=>'private']] as $source)$set(['widgets',0,'source'],$source,'invalid personal source');
$set(['owner_id'],123,'identity outside allowed root');$set(['widgets',0,'html'],'<b>x</b>','arbitrary widget content');
$set(['guide'],'seen','unknown guide');$set(['widgets'],['named'=>$baseWidget],'non-list widgets');
$set(['guide'],str_repeat('x',32769),'payload exceeds 32 KiB');
$tooMany=$twenty;$tooMany['widgets'][]=$baseWidget;$invalid[]=[$tooMany,'more than twenty'];
$duplicate=$base;$duplicate['widgets'][]=$base['widgets'][0];$invalid[]=[$duplicate,'duplicate instance'];
$duplicate=widgetSettings('legacy_services');$widget=$duplicate['widgets'][0];$widget['id']='widget-other';$duplicate['widgets'][]=$widget;$invalid[]=[$duplicate,'duplicate legacy DOM type'];
foreach([['space_id'=>$sid],['space_id'=>$sid,'scope'=>'mine'],['space_id'=>$sid,'scope'=>'all','title'=>'secret'],['space_id'=>$sid."\n",'scope'=>'all']] as $source)$invalid[]=[widgetSettings('space_notice',$source),'bad space source shape/scope/UUID'];
foreach([[],['kind'=>'unknown'],['kind'=>'all','url'=>'x']] as $source)$invalid[]=[widgetSettings('recent',$source),'bad recent source'];
$current=$svc['owner']->dashboard(null);$stable=layoutState();
foreach($invalid as [$bad,$label])denied(fn()=>$svc['owner']->dashboardSave(null,['layout'=>$bad,'revision'=>$current['revision']]),400,'v3 '.$label);
check(layoutState()===$stable,'v3 malformed layouts never alter settings or success audit');

$pair=widgetSettings();$next=$pair['widgets'][0];$next['id']='widget-b';$next['desktop']['y']=500;$next['mobile']['y']=500;$pair['widgets'][]=$next;
foreach($pair['widgets'] as &$widget)$widget['locked']=true;unset($widget);
foreach(['desktop','mobile'] as $screen){$collision=$pair;$collision['widgets'][1][$screen]=$collision['widgets'][0][$screen];denied(fn()=>saveWidgets($svc['owner'],null,$collision),400,'v3 visible locked overlap rejected '.$screen);}
$touching=$pair;foreach(['desktop','mobile'] as $screen)$touching['widgets'][1][$screen]['y']=$touching['widgets'][0][$screen]['h'];
check(saveWidgets($svc['owner'],null,$touching)['layout']==$touching,'v3 locked touching edges are allowed');
$pair['widgets'][1]['visible']=false;$pair['widgets'][1]['desktop']=$pair['widgets'][0]['desktop'];$pair['widgets'][1]['mobile']=$pair['widgets'][0]['mobile'];
check(saveWidgets($svc['owner'],null,$pair)['layout']==$pair,'v3 hidden locked widget does not reserve visible anchor');
$pair['widgets'][1]['visible']=true;$pair['widgets'][1]['locked']=false;
check(saveWidgets($svc['owner'],null,$pair)['layout']==$pair,'v3 unlocked overlap is not mistaken for locked anchor conflict');
$foreign=widgetSettings('space_task',['space_id'=>$contextGroup['id'],'scope'=>'all']);
denied(fn()=>saveWidgets($svc['owner'],null,$foreign),404,'v3 personal source membership rechecked');
denied(fn()=>saveWidgets($svc['editor'],$sid,$foreign),400,'v3 shared source cannot point at other space');
denied(fn()=>saveWidgets($svc['editor'],$sid,widgetSettings()),400,'v3 shared home rejects private personal type');
denied(fn()=>saveWidgets($svc['editor'],$sid,widgetSettings('space_task',['space_id'=>$sid,'scope'=>'mine'])),400,'v3 shared task cannot use private mine scope');
$ownSource=widgetSettings('space_task',['space_id'=>strtoupper($sid),'scope'=>'mine']);
$normalSource=saveWidgets($svc['owner'],null,$ownSource);
check($normalSource['layout']['widgets'][0]['source']['space_id']===$sid,'v3 source UUID canonicalized to lowercase');
$svc['owner']->update($sid,['status'=>'archived']);denied(fn()=>saveWidgets($svc['owner'],null,$ownSource),403,'v3 archived source cannot be saved');
check(saveWidgets($svc['owner'],null,$empty)['layout']['widgets']===[],'v3 inaccessible source can be removed and saved');$svc['owner']->update($sid,['status'=>'active']);
$inviteContext=$contextService->inviteCreate($contextGroup['id'],['role'=>'viewer']);$svc['owner']->join(['token'=>$inviteContext['token']]);
$foreign['widgets'][0]['visible']=false;saveWidgets($svc['owner'],null,$foreign);
$contextService->memberChange($contextGroup['id'],(int)$users['owner']['id'],null);
denied(fn()=>saveWidgets($svc['owner'],null,$foreign),404,'v3 revoked hidden source still requires removal');
check(saveWidgets($svc['owner'],null,$empty)['layout']['widgets']===[],'v3 revoked source removal restores save path');
foreach(['viewer','member'] as $role)denied(fn()=>saveWidgets($svc[$role],$sid,$sharedV3),403,'v3 shared edit restriction '.$role);
$actingWidgets=new ProductSpaces($users['owner']+['acting'=>true]);
denied(fn()=>saveWidgets($actingWidgets,null,$empty),403,'v3 acting personal preferences denied');
$revision=$svc['owner']->dashboard(null)['revision'];denied(fn()=>$svc['owner']->dashboardSave(null,['layout'=>$empty,'revision'=>$revision-1]),409,'v3 stale revision denied');

// More than 100 rows prove filtering and date ordering happen before LIMIT.
$querySpace=$contextService->create(['name'=>'정렬 전용','kind'=>'company'])['space']['id'];
$today=new DateTimeImmutable('today',new DateTimeZone('Asia/Seoul'));$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul'));
$insert=function(string $kind,string $title,?string $due=null,?string $start=null,?string $end=null,string $status='todo',?int $assignee=null,string $updated='now')use($db,$querySpace,$contextUser):array{
    return $db->run('INSERT INTO product_space_items(space_id,kind,title,due_at,starts_at,ends_at,status,assignee_id,created_by,updated_at) VALUES(:sid,:kind,:title,CAST(:due AS timestamptz),CAST(:start AS timestamptz),CAST(:end AS timestamptz),:status,:assignee,:uid,CAST(:updated AS timestamptz)) RETURNING id',
        [':sid'=>$querySpace,':kind'=>$kind,':title'=>$title,':due'=>$due,':start'=>$start,':end'=>$end,':status'=>$status,':assignee'=>$assignee,':uid'=>$contextUser['id'],':updated'=>$updated])->fetch();
};
for($i=0;$i<105;$i++)$insert('task','새 완료 작업 '.$i,$today->modify('-5 days')->format(DATE_ATOM),null,null,'done');
$late=$insert('task','오래된 지난 기한',$today->modify('-2 days')->format(DATE_ATOM),null,null,'todo',(int)$contextUser['id'],'2020-01-01');
$todayTask=$insert('task','오늘 기한',$today->modify('+12 hours')->format(DATE_ATOM),null,null,'doing',null,'2020-01-02');
$future=$insert('task','미래 기한',$today->modify('+2 days')->format(DATE_ATOM),null,null,'todo',null,'2020-01-03');
$noDue=$insert('task','기한 없음',null,null,null,'todo',null,'2020-01-04');
$opts=['view'=>'dashboard','limit'=>8];$result=$contextService->items($querySpace,'task',$opts);
check(array_column($result['items'],'id')===[$late['id'],$todayTask['id'],$future['id'],$noDue['id']],'dashboard tasks exclude done and sort all rows before LIMIT');
check(count($contextService->items($querySpace,'task')['items'])===100,'normal item list retains previous 100 latest rows');
$limited=$contextService->items($querySpace,'task',['view'=>'dashboard','limit'=>'1']);
check(count($limited['items'])===1&&$limited['truncated']&&$limited['items'][0]['id']===$late['id'],'dashboard task limit plus one reports truncated');
$mine=$contextService->items($querySpace,'task',['view'=>'dashboard','assignee'=>'me','limit'=>8]);
check(array_column($mine['items'],'id')===[$late['id']],'dashboard mine uses actual assignee and excludes unassigned own-created tasks');
for($i=0;$i<105;$i++)$insert('event','새 종료 일정 '.$i,null,$today->modify('-2 days')->format(DATE_ATOM),$today->modify('-1 day')->format(DATE_ATOM));
$unknown=$insert('event','오늘 종료시각 미정',null,$today->format(DATE_ATOM),null,'todo',null,'2020-01-01');
$ongoing=$insert('event','진행 중 일정',null,$now->modify('-1 minute')->format(DATE_ATOM),$now->modify('+1 hour')->format(DATE_ATOM),'todo',null,'2020-01-02');
$nextEvent=$insert('event','다음 일정',null,$today->modify('+1 day')->format(DATE_ATOM),$today->modify('+1 day +2 hours')->format(DATE_ATOM),'todo',null,'2020-01-03');
$insert('event','어제 종료시각 미정',null,$today->modify('-1 day')->format(DATE_ATOM),null);
$events=$contextService->items($querySpace,'event',$opts);
check(array_column($events['items'],'id')===[$ongoing['id'],$unknown['id'],$nextEvent['id']],'dashboard true ongoing precedes earlier null-end event; previous-day null ends excluded');
check(str_ends_with($events['as_of'],'+09:00'),'dashboard as_of uses Asia/Seoul');
$insert('notice','안내');$insert('story','이야기');
check(count($contextService->items($querySpace,'notice',$opts)['items'])===1&&count($contextService->items($querySpace,'story',$opts)['items'])===1,'dashboard notice and story use bounded existing visibility');
$privateFile=$db->run('INSERT INTO files(original_name,stored_name,ext,mime,size_bytes,sha256,access_level,uploaded_by) VALUES(\'hidden-dashboard.pdf\',\'hidden-dashboard.pdf\',\'pdf\',\'application/pdf\',1,:sha,\'private\',:owner) RETURNING public_id',
    [':sha'=>str_repeat('1',64),':owner'=>$users['owner']['id']])->fetchColumn();
$visibleFile=$db->run('INSERT INTO files(original_name,stored_name,ext,mime,size_bytes,sha256,access_level,uploaded_by) VALUES(\'visible-dashboard.pdf\',\'visible-dashboard.pdf\',\'pdf\',\'application/pdf\',1,:sha,\'private\',:owner) RETURNING public_id',
    [':sha'=>str_repeat('2',64),':owner'=>$contextUser['id']])->fetchColumn();
for($i=0;$i<12;$i++)$db->run('INSERT INTO product_space_items(space_id,kind,title,file_id,created_by) VALUES(:space,\'file\',\'접근 불가 링크\',:file,:creator)',
    [':space'=>$querySpace,':file'=>$privateFile,':creator'=>$contextUser['id']]);
$visibleLink=$db->run('INSERT INTO product_space_items(space_id,kind,title,file_id,created_by,updated_at) VALUES(:space,\'file\',\'접근 가능 오래된 링크\',:file,:creator,\'2020-01-01\') RETURNING id',
    [':space'=>$querySpace,':file'=>$visibleFile,':creator'=>$contextUser['id']])->fetchColumn();
$fileResult=$contextService->items($querySpace,'file',['view'=>'dashboard','limit'=>1]);
check(array_column($fileResult['items'],'id')===[$visibleLink]&&!$fileResult['truncated'],'dashboard original file authorization happens before LIMIT and truncation');
$db->run('UPDATE files SET uploaded_by=:owner WHERE public_id=:file',
    [':owner'=>$users['owner']['id'],':file'=>$visibleFile]);
check($contextService->items($querySpace,'file',$opts)['items']===[],'dashboard revoked original file ownership immediately removes metadata');
foreach([['limit'=>8],['view'=>'other'],['view'=>'dashboard','limit'=>0],['view'=>'dashboard','limit'=>9],['view'=>'dashboard','limit'=>1.0],['view'=>'dashboard','limit'=>'01'],['view'=>'dashboard','limit'=>true],['view'=>'dashboard','assignee'=>'other'],['view'=>'dashboard','unknown'=>'x']] as $bad)denied(fn()=>$contextService->items($querySpace,'task',$bad),400,'dashboard rejects malformed query '.json_encode($bad));
denied(fn()=>$contextService->items($querySpace,'event',['view'=>'dashboard','assignee'=>'me']),400,'dashboard assignee me is task-only');
denied(fn()=>$contextService->items($querySpace,null,$opts),400,'dashboard kind is required');
denied(fn()=>$svc['owner']->items($querySpace,'task',$opts),404,'dashboard data never bypasses membership');
$business=function()use($db):string{return $db->run('SELECT jsonb_build_object(\'items\',(SELECT jsonb_agg(to_jsonb(i) ORDER BY id) FROM product_space_items i),\'spaces\',(SELECT jsonb_agg(to_jsonb(s) ORDER BY id) FROM product_spaces s),\'members\',(SELECT jsonb_agg(to_jsonb(m) ORDER BY space_id,user_id) FROM product_space_members m))::text')->fetchColumn();};
$stable=layoutState();$businessBefore=$business();$contextService->items($querySpace,'task',$opts);$contextService->items($querySpace,'event',$opts);$contextService->dashboard(null);$contextService->dashboard($querySpace);
check(layoutState()===$stable&&$business()===$businessBefore,'dashboard query and recommendation GET have no business/settings/audit writes');
