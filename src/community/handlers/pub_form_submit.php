<?php
declare(strict_types=1);
if((int)($_SERVER['CONTENT_LENGTH']??0)>65536) { Http::error(413,'내용이 너무 큽니다'); }
$pub=community_publication($params[0]);[$database,$view]=community_public_form($pub,$params[1]);
if(!community_public_rate('submit:'.Http::clientIp(),60,5)) { Http::error(429,'잠시 후 다시 시도해 주세요'); }
$json=str_contains($_SERVER['CONTENT_TYPE']??'','application/json');$body=$json?Http::jsonBody():$_POST;
if(array_diff(array_keys($body),['signature','values'])!==[] || !is_array($body['values']??null) || $_FILES!==[]) { Http::error(400,Http::MSG_BAD_REQUEST); }
if(!is_string($body['signature']??null)) { Http::error(403,Http::MSG_FORBIDDEN); }
$schema=community_db_schema_of($database['schema']);$values=$body['values'];
foreach($values as $key=>$value) {
    $p=$key==='title'?['key'=>'title','name'=>'제목','type'=>'text']:DbProps::find($schema,(string)$key);
    if($p===null || !in_array($p['type'],['text','number','checkbox','date','select','multi_select'],true)) { Http::error(400,Http::MSG_BAD_REQUEST); }
    if(!$json && is_string($value)) {
        if($p['type']==='number') { $values[$key]=$value===''?null:(is_numeric($value)?0+$value:$value); }
        elseif($p['type']==='checkbox') { $values[$key]=$value==='1'; }
        elseif($p['type']==='date') { $values[$key]=$value===''?null:['start'=>$value]; }
        elseif($value==='') { $values[$key]=null; }
    }
}
$db=Db::instance();$db->begin();
try {
    $db->run('SELECT id FROM community.web_publications WHERE id=:id FOR UPDATE',
        [':id'=>(int)$pub['id']]);
    $pub=community_publication($params[0]);[$database,$view]=community_public_form($pub,$params[1]);
    if(!community_public_form_consume($pub,$params[1],$body['signature'])) { $db->rollback();Http::error(403,Http::MSG_FORBIDDEN); }
    $db->run('SELECT id FROM community.databases WHERE id=:id FOR UPDATE',
        [':id'=>(int)$database['id']]);
    [$database,$view]=community_public_form($pub,$params[1]);
    $schema=community_db_schema_of($database['schema']);
    $clean=community_form_values($view,$schema,$values);
    $page=community_page_row($database['page_uuid'],0);
    community_form_create_response($database,$page,$view,$clean,null,'public_form');
    community_integration_audit('pub.submit',(int)$pub['id']);$db->commit();
    if($json) { Http::json(['ok'=>true],201); } else { header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="ko"><meta charset="utf-8"><title>제출 완료</title><p>답변을 제출했습니다.</p></html>'; }
}catch(InvalidArgumentException $e){$db->rollback();Http::error(400,Http::MSG_BAD_REQUEST);}
catch(OverflowException $e){$db->rollback();Http::error(429,'잠시 후 다시 시도해 주세요');}
catch(DomainException $e){$db->rollback();Http::error(400,Http::MSG_BAD_REQUEST);}
catch(Throwable $e){$db->rollback();throw $e;}
