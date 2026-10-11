<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!isset($db,$svc,$sid)||!str_starts_with((string)getenv('PRODUCT_TEST_DB'),'tbox_product_test_'))exit(2);
$shortcutIds=['spaces','work','tasks','library','story','messages','assistant','account'];
$shortcutDefault=['spaces','work','library','tasks','messages','assistant'];
$beforeOther=$svc['other']->dashboard(null);
$layout=widgetSettings();$saved=saveWidgets($svc['guest'],null,$layout);
check(!array_key_exists('shortcuts',$saved['layout']),'shortcut omission remains absent on new legacy-compatible v3');
$untouched=layoutState();$svc['guest']->dashboard(null);check(layoutState()===$untouched,'shortcuts GET makes no writes');
$layout['shortcuts']=$shortcutIds;$saved=saveWidgets($svc['guest'],null,$layout);
check($saved['layout']['shortcuts']===$shortcutIds,'all eight fixed IDs retain exact order');
$layout['shortcuts']=array_reverse($shortcutDefault);$saved=saveWidgets($svc['guest'],null,$layout);
check($saved['layout']['shortcuts']===$layout['shortcuts'],'shortcut order preserved');
$incoming=$layout;unset($incoming['shortcuts']);$incoming['guide']='done';$oldRevision=$saved['revision'];$saved=$svc['guest']->dashboardSave(null,['layout'=>$incoming,'revision'=>$oldRevision]);
check($saved['layout']['shortcuts']===$layout['shortcuts']&&$saved['layout']['guide']==='done'&&$saved['revision']===$oldRevision+1,'omitting shortcuts preserves current stored value under same revision');
$state=array_slice(layoutState(),0,2);denied(fn()=>$svc['guest']->dashboardSave(null,['layout'=>$incoming,'revision'=>$oldRevision]),409,'stale omitted shortcut save conflicts');check(array_slice(layoutState(),0,2)===$state,'stale save leaves all dashboard rows unchanged');
foreach([null,true,0,1.0,'work',new stdClass(),['work','work'],['unknown'],['https://invalid.example'],[1],['work',false],[['id'=>'work']],['a'=>'work'],[...$shortcutIds,'work']] as $bad){$invalid=$layout;$invalid['shortcuts']=$bad;denied(fn()=>saveWidgets($svc['guest'],null,$invalid),400,'strict shortcut type/list/allowlist '.json_encode($bad));}
$shared=widgetSettings('space_notice',['space_id'=>$sid,'scope'=>'all']);$shared['shortcuts']=[];denied(fn()=>saveWidgets($svc['editor'],$sid,$shared),400,'shared v3 rejects even empty shortcuts');
foreach([ProductSpaces::defaultLayout(true),freeLayout(true)] as $legacy){$invalid=$legacy;$invalid['shortcuts']=[];denied(fn()=>saveWidgets($svc['guest'],null,$invalid),400,'legacy rejects shortcuts presence');$state=array_slice(layoutState(),0,2);denied(fn()=>saveWidgets($svc['guest'],null,$legacy),409,'stored shortcuts block downgrade');check(array_slice(layoutState(),0,2)===$state,'downgrade conflict preserves dashboard rows');}
$layout['shortcuts']=[];$saved=saveWidgets($svc['guest'],null,$layout);check($saved['layout']['shortcuts']===[],'empty shortcuts intentionally hide all');$saved=saveWidgets($svc['guest'],null,$incoming);check($saved['layout']['shortcuts']===[],'omission preserves explicitly empty shortcuts');
$layout['shortcuts']=$shortcutDefault;$saved=saveWidgets($svc['guest'],null,$layout);check($saved['layout']['shortcuts']===$shortcutDefault,'explicit default restoration replaces empty shortcuts');
check(json_encode($svc['other']->dashboard(null))===json_encode($beforeOther),'shortcut saves cannot change another account');
$acting=new ProductSpaces([...$users['guest'],'acting'=>true]);denied(fn()=>saveWidgets($acting,null,$layout),403,'acting account cannot save shortcut preferences');
$plain=saveWidgets($svc['other'],null,widgetSettings());check(!array_key_exists('shortcuts',$plain['layout']),'another account omission remains absent');$plain=saveWidgets($svc['other'],null,ProductSpaces::defaultLayout(true));check($plain['layout']['version']===1,'legacy downgrade without shortcut preference remains supported');
