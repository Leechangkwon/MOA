<?php
declare(strict_types=1);

function community_ai_body(array $keys,bool $confirmed=false): array
{
    $body=Http::jsonBody(100000);
    if(array_diff(array_keys($body),$keys)!==[]){throw new CommunityAiError('invalid_request');}
    if($confirmed && ($body['confirmed']??null)!==true){throw new CommunityAiError('confirmation_required');}
    return $body;
}
function community_ai_text(mixed $value,int $max): string
{
    if(!is_string($value)||preg_match('//u',$value)!==1||trim($value)===''||CommunityAi::length($value)>$max
       ||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)){throw new CommunityAiError('invalid_request');}
    return trim($value);
}
function community_ai_permission_info(array $user,string $id): array
{
    $p=CommunityAi::page($id,$user);
    if($p===null || !CommunityAuthz::decidePageRead($user,$p)[0]){throw new CommunityAiError('not_available',404);}
    [$read,$reason]=CommunityAuthz::decidePageRead($user,$p);
    $eligible=$read&&!in_array($reason,[CommunityAuthz::CODE_ADMIN_ALL,CommunityAuthz::CODE_BOARD_MODERATOR],true)&&!$p['ai_excluded'];
    return ['page_id'=>$id,'doc_version'=>(int)$p['doc_version'],'allowed'=>$eligible&&$p['ai_allowed_version']===(int)$p['doc_version'],
        'eligible'=>$eligible,'can_manage'=>$eligible&&Authz::canCommunityWrite($user)&&CommunityAuthz::decidePageManage($user,$p)[0]];
}
function community_ai_permission(array $user,string $id): array
{
    $body=community_ai_body(['allowed','doc_version']);
    if(!is_bool($body['allowed']??null)||!is_int($body['doc_version']??null)){throw new CommunityAiError('invalid_request');}
    $db=Db::instance();$db->begin();try{
        $p=CommunityAi::page($id,$user);if($p===null){throw new CommunityAiError('not_available',404);}
        $db->run(COMMUNITY_PAGE_LOCK_SQL,[':pid'=>(int)$p['id']]);$info=community_ai_permission_info($user,$id);
        if(!$info['can_manage']){throw new CommunityAiError('not_available',404);}
        if($info['doc_version']!==$body['doc_version']){throw new CommunityAiError('version_conflict',409);}
        $pid=(int)$p['id'];
        $db->run('DELETE FROM community.page_embeddings WHERE page_id=:id',
                [':id'=>$pid]);
        if($body['allowed']){
            $sql='INSERT INTO community.ai_page_permissions(page_id,version,allowed_by) VALUES(:id,:v,:u) ON CONFLICT(page_id) DO UPDATE SET version=EXCLUDED.version,allowed_by=EXCLUDED.allowed_by,allowed_at=now()';
            $db->run($sql,[':id'=>$pid,':v'=>$info['doc_version'],':u'=>(int)$user['id']]);
            $sql="INSERT INTO community.embedding_queue(page_id,version) VALUES(:id,:v) ON CONFLICT(page_id) DO UPDATE SET version=EXCLUDED.version,next_chunk=0,total_chunks=0,state='pending',available_at=now(),updated_at=now()";
            $db->run($sql,[':id'=>$pid,':v'=>$info['doc_version']]);
        }else{
            $db->run('DELETE FROM community.ai_page_permissions WHERE page_id=:id',
                [':id'=>$pid]);
            $db->run('DELETE FROM community.embedding_queue WHERE page_id=:id',
                [':id'=>$pid]);
        }
        Audit::allow('ai.permission',['target_type'=>'page','target_id'=>$pid,'detail'=>['allowed'=>$body['allowed'],'doc_version'=>$info['doc_version']]]);
        $db->commit();return community_ai_permission_info($user,$id);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function community_ai_settings_update(array $user): array
{
    $body=community_ai_body(['external_data_mode','features']);
    $mode=$body['external_data_mode']??null;$features=$body['features']??null;
    if(!in_array($mode,['unconfigured','free_public','paid_private'],true)||!is_array($features)
       ||array_diff(array_keys($features),CommunityAi::FEATURES)!==[]||count($features)!==5){throw new CommunityAiError('invalid_request');}
    foreach($features as $v){if(!is_bool($v)){throw new CommunityAiError('invalid_request');}}
    if(in_array(true,$features,true)&&($mode==='unconfigured'||!Ai::configured())){throw new CommunityAiError('configuration_required');}
    $sql='UPDATE community.ai_settings SET external_data_mode=:mode,search_enabled=CAST(:search AS boolean),summary_enabled=CAST(:summary AS boolean),assistant_enabled=CAST(:assistant AS boolean),transcribe_enabled=CAST(:transcribe AS boolean),briefing_enabled=CAST(:briefing AS boolean),updated_by=:uid,updated_at=now() WHERE singleton';
    $bind=[':mode'=>$mode,':uid'=>(int)$user['id']];foreach($features as $k=>$v){$bind[':'.$k]=$v?'true':'false';}
    $db=Db::instance();$db->begin();try{
        $db->run($sql,$bind);Audit::allow('ai.settings',['detail'=>['mode'=>$mode,'features'=>$features]]);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}return CommunityAi::settings();
}
function community_ai_summary(array $user,string $id): array
{
    $body=community_ai_body(['doc_version','confirmed'],true);
    if(!is_int($body['doc_version']??null)){throw new CommunityAiError('invalid_request');}
    CommunityAi::ready('summary');$p=CommunityAi::input($id,$user,$body['doc_version']);
    $input=$p['title']."\n".Doc::extract(json_decode($p['doc'],true))['plain_text'];
    return CommunityAi::generate($user,'summary',$input,[CommunityAi::ref($p)]);
}
function community_ai_assistant(array $user): array
{
    $body=community_ai_body(['question','confirmed'],true);$question=community_ai_text($body['question']??null,2000);
    CommunityAi::ready('assistant');$found=CommunityAi::search($user,$question,true);$refs=[];$sources=[];
    foreach(array_slice($found['results'],0,5) as $r){
        $p=CommunityAi::input($r['page_id'],$user,$r['version']);
        $text=CommunityAi::cut(Doc::extract(json_decode($p['doc'],true))['plain_text'],2800);
        $sources[]=['source'=>count($refs)+1,'title'=>$p['title'],'text'=>$text];$refs[]=CommunityAi::ref($p);
    }
    if($refs===[]){return ['text'=>'현재 외부 AI 사용이 허용된 절차서에서 근거를 찾지 못했습니다.','citations'=>[],'warning'=>CommunityAi::WARNING,'log_id'=>null,'remaining'=>CommunityAi::remaining($user)];}
    $input=CommunityAi::json(['question'=>$question,'sources'=>$sources]);
    return CommunityAi::generate($user,'assistant',$input,$refs);
}
function community_ai_briefing(array $user): array
{
    community_ai_body(['confirmed'],true);CommunityAi::ready('briefing');
    $brief=Briefing::collect($user,new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')));$refs=[];$items=[];$seen=[];
    foreach(array_merge($brief['top'],$brief['missed'],$brief['others']) as $item){
        if(!str_starts_with($item['key'],'page:')){continue;}$id=substr($item['key'],5);if(isset($seen[$id])){continue;}$seen[$id]=true;
        $p=CommunityAi::page($id,$user);if($p===null||!CommunityAuthz::decideAiInput($user,$p)[0]){continue;}
        $refs[]=CommunityAi::ref($p);$items[]=['title'=>$p['title'],'due'=>$p['due_date']];if(count($items)>=40){break;}
    }
    if($items===[]){return ['text'=>'AI 사용이 허용된 오늘의 항목이 없습니다.','citations'=>[],'warning'=>CommunityAi::WARNING,'log_id'=>null,'remaining'=>CommunityAi::remaining($user)];}
    return CommunityAi::generate($user,'briefing',CommunityAi::json($items),$refs);
}
function community_ai_log_list(array $user,bool $admin): array
{
    $before=Http::queryText('before',36);if($before!==''&&!preg_match('/^[0-9a-f-]{36}$/D',$before)){throw new CommunityAiError('invalid_request');}
    $sql=<<<'SQL'
        SELECT l.* FROM community.ai_log l
        WHERE (CAST(:admin AS boolean) OR l.user_id=:uid)
          AND (CAST(:before AS text)='' OR l.id<(SELECT prev.id FROM community.ai_log prev WHERE prev.public_id=NULLIF(:cursor,'')::uuid AND (CAST(:adm2 AS boolean) OR prev.user_id=:uid2)))
        ORDER BY l.id DESC LIMIT 51
        SQL;
    $rows=Db::instance()->run($sql,[':admin'=>$admin?'true':'false',':uid'=>(int)$user['id'],':before'=>$before,':cursor'=>$before,':adm2'=>$admin?'true':'false',':uid2'=>(int)$user['id']])->fetchAll();
    $more=count($rows)>50;$rows=array_slice($rows,0,50);$out=[];
    foreach($rows as $r){
        $refs=json_decode($r['page_refs'],true);$files=json_decode($r['file_refs'],true);$visible=true;
        if(!$admin){try{CommunityAi::checkHistory($user,$refs,$files);}catch(Throwable $e){$visible=false;}}
        $out[]=['id'=>$r['public_id'],'feature'=>$r['feature'],'status'=>$r['status'],'model'=>$r['model'],
            'started_at'=>$r['started_at'],'finished_at'=>$r['finished_at'],'chars_in'=>(int)$r['chars_in'],'chars_out'=>(int)$r['chars_out'],
            'input_text'=>$visible?$r['input_text']:null,'output_text'=>$visible?$r['output_text']:null,
            'page_refs'=>$visible?$refs:[],'file_refs'=>$visible?$files:[],'content_visible'=>$visible,'scrubbed'=>$r['scrubbed_at']!==null];
    }
    if($admin){Audit::allow('ai.log_read',['detail'=>['count'=>count($out)]]);}
    return ['logs'=>$out,'next_before'=>$more?$out[count($out)-1]['id']:null];
}
function community_ai_insert(array $user,string $id): array
{
    $body=community_ai_body(['log_id','doc_version']);
    if(!is_int($body['doc_version']??null)||!is_string($body['log_id']??null)||!preg_match('/^[0-9a-f-]{36}$/D',$body['log_id'])){throw new CommunityAiError('invalid_request');}
    $sql='SELECT * FROM community.ai_log WHERE public_id=:id AND user_id=:uid';
    $r=Db::instance()->run($sql,[':id'=>$body['log_id'],':uid'=>(int)$user['id']])->fetch();
    if(!is_array($r)||$r['status']!=='ok'||$r['scrubbed_at']!==null||!in_array($r['feature'],['summary','assistant','transcribe','briefing'],true)||$r['output_text']===''){throw new CommunityAiError('not_available',404);}
    $refs=json_decode($r['page_refs'],true);$files=json_decode($r['file_refs'],true);
    $db=Db::instance();$db->begin();try{
        // Stable ascending order prevents two cross-page inserts from acquiring opposite locks.
        $ids=array_values(array_unique(array_merge([$id],array_column($refs,'page_id'))));
        $lockSql='SELECT id FROM community.pages WHERE public_id IN (SELECT value::uuid FROM jsonb_array_elements_text(CAST(:ids AS jsonb))) ORDER BY id FOR NO KEY UPDATE';
        $db->run($lockSql,[':ids'=>CommunityAi::json($ids)])->fetchAll();
        $u=CommunityAi::checkRefs($user,$refs,$files);$p=community_page_row($id,(int)$u['id']);
        if($p===null||!Authz::canCommunityWrite($u)||!CommunityAuthz::decidePageChange($u,$p)[0]){throw new CommunityAiError('not_available',404);}
        if((int)$p['doc_version']!==$body['doc_version']){throw new CommunityAiError('version_conflict',409);}
        $doc=json_decode($p['doc'],true);$text=CommunityAi::WARNING."\n".$r['output_text'];
        foreach(CommunityAi::citations($refs,$u) as $cite){$text.="\n출처: ".$cite['title'].' '.$cite['url'];}
        foreach(preg_split('/\R/u',$text) as $line){
            for($at=0;$at<max(1,CommunityAi::length($line));$at+=1000){
                $chunk=(string)iconv_substr($line,$at,1000,'UTF-8');
                $doc['blocks'][]=['id'=>Doc::uuid4(),'type'=>'paragraph','text'=>$chunk===''?[]:[['t'=>$chunk,'m'=>[]]]];
            }
        }
        $result=community_save_doc($u,$id,['doc_version'=>$body['doc_version'],'doc'=>$doc],['force_snapshot'=>true]);
        Audit::allow('ai.insert',['target_type'=>'page','target_id'=>(int)$p['id'],'detail'=>community_anon_audit_detail($p,['ai_log_id'=>$r['public_id'],'doc_version'=>$result['doc_version']])]);
        $db->commit();return $result;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function community_ai_dispatch(string $action,array $user,array $params=[]): void
{
    try{
        switch($action){
            case 'status': $out=CommunityAi::settings()+['remaining'=>CommunityAi::remaining($user),'daily_limit'=>50,'can_admin'=>Authz::canAdmin($user)];break;
            case 'settings_get': $out=CommunityAi::settings();break;
            case 'settings_update': $out=community_ai_settings_update($user);break;
            case 'key':
                $b=community_ai_body(['key']);if(!is_string($b['key']??null)){throw new CommunityAiError('invalid_request');}
                Ai::setApiKey($b['key']);Audit::allow('ai.key_update');$out=Ai::health();break;
            case 'health': community_ai_body([]);$out=Ai::health();break;
            case 'permission_get': $out=community_ai_permission_info($user,$params[0]);break;
            case 'permission_update': $out=community_ai_permission($user,$params[0]);break;
            case 'summary': $out=community_ai_summary($user,$params[0]);break;
            case 'assistant': $out=community_ai_assistant($user);break;
            case 'briefing': $out=community_ai_briefing($user);break;
            case 'search': $b=community_ai_body(['q','confirmed'],true);$out=CommunityAi::search($user,community_ai_text($b['q']??null,2000));unset($out['text']);break;
            case 'transcribe': $b=community_ai_body(['file_id','language','confirmed'],true);$out=CommunityAi::transcribe($user,community_ai_text($b['file_id']??null,36),community_ai_text($b['language']??'auto',4));break;
            case 'insert': $out=community_ai_insert($user,$params[0]);break;
            case 'my_log': $out=community_ai_log_list($user,false);break;
            case 'admin_log': $out=community_ai_log_list($user,true);break;
            default: throw new LogicException('Unknown AI action');
        }
        Http::json($out);
    }catch(CommunityAiError|AiUnavailable $e){
        Db::instance()->rollback();
        $messages=['busy'=>'앞에 1건이 처리 중입니다. 잠시 후 다시 시도하세요.','daily_limit'=>'오늘 AI 사용 한도 50회에 도달했습니다. 내일 다시 이용하세요.',
            'unavailable'=>'AI를 지금 사용할 수 없습니다. 관리자 설정과 연결 상태를 확인하세요.','confirmation_required'=>'Google 전송과 자료 적합성을 확인해 주세요.',
            'version_conflict'=>'문서가 바뀌었습니다. 새로 열어 다시 시도하세요.','not_available'=>'이 자료는 AI에 사용할 수 없습니다.',
            'permission_changed'=>'자료의 권한이 바뀌었습니다. 다시 확인하세요.','audio_too_large'=>'녹음 파일은 10MB까지 사용할 수 있습니다.'];
        Http::json(['error'=>$messages[$e->reason]??'AI 요청을 처리할 수 없습니다. 입력과 설정을 확인하세요.','reason'=>$e->reason],$e->httpStatus);
    }
}
