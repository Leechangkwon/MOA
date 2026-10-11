<?php
declare(strict_types=1);
// Included by the guarded, fresh-database service harness only.
if(PHP_SAPI!=='cli'||!isset($db,$svc,$sid)||!str_starts_with((string)getenv('PRODUCT_TEST_DB'),'tbox_product_test_'))exit(2);
function freeLayout(bool $personal): array {
    $layout=ProductSpaces::defaultLayout($personal);$layout['version']=2;
    foreach($layout['widgets'] as $i=>&$widget){
        unset($widget['size']);
        $widget['desktop']=['x'=>($i%4)*240,'y'=>intdiv($i,4)*400,'w'=>240,'h'=>360];
        $widget['mobile']=['x'=>0,'y'=>$i*300,'w'=>360,'h'=>280];
    }
    unset($widget);return $layout;
}
function layoutState(): array {
    global $db;
    return [
        $db->run('SELECT user_id,layout,revision,updated_at FROM product_personal_dashboards ORDER BY user_id')->fetchAll(),
        $db->run('SELECT space_id,layout,revision,updated_by,updated_at FROM product_space_dashboards ORDER BY space_id')->fetchAll(),
        count(Audit::$rows),
    ];
}
$untouched=layoutState();
check($svc['other']->dashboard(null)['revision']===0,'fresh personal GET keeps revision zero');
check($svc['owner']->dashboard($sid)['layout']['version']===1,'stored legacy shared layout remains v1 on GET');
check($svc['guest']->dashboard(null)['layout']['version']===1,'stored legacy personal layout remains v1 on GET');
check(layoutState()===$untouched,'default and legacy GET preserve dashboard rows timestamps and audit');

foreach([false,true] as $isPersonal){
    $target=$isPersonal?null:$sid;$service=$isPersonal?$svc['guest']:$svc['editor'];$scope=$isPersonal?'personal':'shared';
    $layout=freeLayout($isPersonal);$current=$service->dashboard($target);
    $cases=[
        ['desktop',['x'=>0,'y'=>0,'w'=>240,'h'=>160]],
        ['desktop',['x'=>960,'y'=>19840,'w'=>240,'h'=>160]],
        ['desktop',['x'=>0,'y'=>18000,'w'=>1200,'h'=>2000]],
        ['desktop',['x'=>127,'y'=>319,'w'=>743,'h'=>577]],
        ['mobile',['x'=>0,'y'=>0,'w'=>160,'h'=>160]],
        ['mobile',['x'=>200,'y'=>19840,'w'=>160,'h'=>160]],
        ['mobile',['x'=>0,'y'=>18000,'w'=>360,'h'=>2000]],
        ['mobile',['x'=>13,'y'=>287,'w'=>317,'h'=>913]],
    ];
    foreach($cases as $i=>[$screen,$box]){
        $layout['widgets'][0][$screen]=$box;$layout['widgets'][0]['visible']=$i%2===0;
        $layout['source']=['all','files','pages'][$i%3];$layout['view']=$i%2===0?'recent':'favorites';
        $next=$service->dashboardSave($target,['layout'=>$layout,'revision'=>$current['revision']]);
        check($next['layout']==$layout&&$next['revision']===$current['revision']+1,"$scope v2 $screen boundary/free geometry $i persists exactly");
        $current=$next;
    }
    $layout['widgets']=array_reverse($layout['widgets']);foreach($layout['widgets'] as &$widget)$widget['visible']=false;unset($widget);
    $current=$service->dashboardSave($target,['layout'=>$layout,'revision'=>$current['revision']]);
    check($current['layout']==$layout,"$scope v2 reordered hidden widgets retain both geometries");
    $stable=layoutState();
    check($service->dashboard($target)==$current,"$scope v2 GET preserves saved layout and revision");
    check(layoutState()===$stable,"$scope v2 GET has no write or audit side effect");
    denied(fn()=>$service->dashboardSave($target,['layout'=>$layout,'revision'=>$current['revision']-1]),409,"$scope v2 stale revision rejected");

    $invalid=[];
    $set=function(array $path,mixed $value,string $label)use(&$invalid,$layout):void{
        $bad=$layout;$at=&$bad;foreach($path as $part)$at=&$at[$part];$at=$value;unset($at);$invalid[]=[$bad,$label];
    };
    $remove=function(array $path,string $label)use(&$invalid,$layout):void{
        $bad=$layout;$key=array_pop($path);$at=&$bad;foreach($path as $part)$at=&$at[$part];unset($at[$key]);unset($at);$invalid[]=[$bad,$label];
    };
    foreach(['version','widgets','source','view'] as $key)$remove([$key],'missing root '.$key);
    foreach([0,3,'2',2.0,true,null] as $value)$set(['version'],$value,'invalid version '.json_encode($value));
    $set(['owner_user_id'],123,'unknown identity root');
    $set(['source'],'<script>','unknown source');$set(['view'],'compact','unknown view');
    $set(['widgets'],array_slice($layout['widgets'],1),'missing widget');
    $set(['widgets'],array_merge($layout['widgets'],[$layout['widgets'][0]]),'extra widget');
    $set(['widgets'],['named'=>$layout['widgets'][0]],'non-list widgets');
    $set(['widgets',0],null,'non-object widget');
    $set(['widgets',0,'id'],$layout['widgets'][1]['id'],'duplicate widget ID');
    $set(['widgets',0,'id'],$isPersonal?'notice':'recent','foreign scope widget ID');
    $set(['widgets',0,'id'],'unknown','unknown widget ID');
    foreach(['id','visible','desktop','mobile'] as $key)$remove(['widgets',0,$key],'missing widget '.$key);
    foreach([0,'false',null] as $value)$set(['widgets',0,'visible'],$value,'non-boolean visibility '.json_encode($value));
    $set(['widgets',0,'size'],'wide','legacy size in v2');$set(['widgets',0,'style'],'color:red','arbitrary widget style');
    $set(['source'],str_repeat('x',8193),'over 8KiB v2 layout');
    foreach(['desktop'=>[1200,240],'mobile'=>[360,160]] as $screen=>[$width,$minimum]){
        foreach([null,[],true,'0,0,240,160'] as $value)$set(['widgets',0,$screen],$value,$screen.' non-object geometry '.json_encode($value));
        $set(['widgets',0,$screen,'z'],1,$screen.' extra coordinate');
        foreach(['x','y','w','h'] as $key){
            $remove(['widgets',0,$screen,$key],"$screen missing $key");
            foreach([1.0,'1',true,null,PHP_INT_MAX,PHP_INT_MIN] as $value)$set(['widgets',0,$screen,$key],$value,"$screen invalid $key ".get_debug_type($value).' '.json_encode($value));
        }
        foreach([
            ['x'=>-1,'y'=>0,'w'=>$minimum,'h'=>160],
            ['x'=>0,'y'=>-1,'w'=>$minimum,'h'=>160],
            ['x'=>0,'y'=>0,'w'=>$minimum-1,'h'=>160],
            ['x'=>0,'y'=>0,'w'=>$width+1,'h'=>160],
            ['x'=>0,'y'=>0,'w'=>$minimum,'h'=>159],
            ['x'=>0,'y'=>0,'w'=>$minimum,'h'=>2001],
            ['x'=>$width-$minimum+1,'y'=>0,'w'=>$minimum,'h'=>160],
            ['x'=>0,'y'=>19841,'w'=>$minimum,'h'=>160],
            ['x'=>0,'y'=>18001,'w'=>$minimum,'h'=>2000],
        ] as $i=>$box)$set(['widgets',0,$screen],$box,"$screen outside bounds $i");
    }
    foreach($invalid as [$bad,$label])denied(fn()=>$service->dashboardSave($target,['layout'=>$bad,'revision'=>$current['revision']]),400,"$scope v2 $label");
    foreach([-1,1.0,'1',true,null] as $revision)denied(fn()=>$service->dashboardSave($target,['layout'=>$layout,'revision'=>$revision]),400,"$scope invalid revision ".get_debug_type($revision));
    denied(fn()=>$service->dashboardSave($target,['layout'=>$layout,'revision'=>$current['revision'],'user_id'=>123]),400,"$scope body identity cannot override current account");
    check(layoutState()===$stable,"$scope rejected v2 saves preserve rows revisions and audit");
    // Continued support includes saving v1 again after a v2 row exists.
    $legacy=ProductSpaces::defaultLayout($isPersonal);$legacy['widgets'][0]['size']='small';
    $restored=$service->dashboardSave($target,['layout'=>$legacy,'revision'=>$current['revision']]);
    check($restored['layout']==$legacy&&$restored['revision']===$current['revision']+1,"$scope v1 remains writable after v2");
    $legacy['source']=str_repeat('x',4097);
    denied(fn()=>$service->dashboardSave($target,['layout'=>$legacy,'revision'=>$restored['revision']]),400,"$scope v1 over 4KiB rejected");
}
$shared=['layout'=>freeLayout(false),'revision'=>$svc['owner']->dashboard($sid)['revision']];
foreach(['viewer','member'] as $role)denied(fn()=>$svc[$role]->dashboardSave($sid,$shared),403,"v2 $role cannot edit shared dashboard");
foreach(['other','globaladmin'] as $role)denied(fn()=>$svc[$role]->dashboardSave($sid,$shared),404,"v2 $role cannot cross shared membership");
$acting=new ProductSpaces(array_merge($users['owner'],['acting'=>true]));
denied(fn()=>$acting->dashboardSave($sid,$shared),403,'v2 acting user shared save denied');
denied(fn()=>$acting->dashboardSave(null,['layout'=>freeLayout(true),'revision'=>0]),403,'v2 acting user personal save denied');
$svc['owner']->update($sid,['status'=>'archived']);
denied(fn()=>$svc['owner']->dashboardSave($sid,$shared),403,'v2 archived shared save denied');
$svc['owner']->update($sid,['status'=>'active']);
check($svc['other']->dashboard(null)['revision']===0,'v2 personal writes never create another account dashboard');
