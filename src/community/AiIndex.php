<?php
declare(strict_types=1);

/** Cron tail hook: at most 20 chunks / 40 seconds; no generation or waiting for AI. */
function community_run_ai_index(): array
{
    try{CommunityAi::ready('index');}catch(CommunityAiError $e){return ['chunks'=>0,'deferred'=>true];}
    $lock=fopen(Env::get('STORAGE_DIR').'/community-ai-index.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock)){fclose($lock);}return ['chunks'=>0,'deferred'=>true];}
    $count=0;$deadline=microtime(true)+40;$db=Db::instance();
    try{
        while($count<20 && $deadline-microtime(true)>=10){
            $sql="SELECT q.*,p.public_id,ap.allowed_by FROM community.embedding_queue q JOIN community.pages p ON p.id=q.page_id LEFT JOIN community.ai_page_permissions ap ON ap.page_id=p.id AND ap.version=q.version WHERE q.state='pending' AND q.available_at<=now() ORDER BY q.available_at,q.page_id LIMIT 1";
            $q=$db->run($sql)->fetch();if(!is_array($q)){break;}
            $user=$q['allowed_by']===null?null:community_user_by_id((int)$q['allowed_by']);
            try{
                if($user===null){throw new CommunityAiError('not_available',404);}
                $user=CommunityAi::freshUser($user);$page=CommunityAi::input($q['public_id'],$user,(int)$q['version']);
                $text=$page['title']."\n".Doc::extract(json_decode($page['doc'],true))['plain_text'];
                if(CommunityAi::length($text)>Ai::MAX_TEXT){throw new CommunityAiError('input_too_long');}
                $total=max(1,(int)ceil(CommunityAi::length($text)/2000));$n=(int)$q['next_chunk'];
                if($n>=$total){throw new CommunityAiError('invalid_cursor');}
                $chunk=(string)iconv_substr($text,$n*2000,2000,'UTF-8');
                Audit::automationActor((int)$user['id']);
                $result=CommunityAi::perform($user,'index','',[CommunityAi::ref($page)],[],static fn($cb)=>Ai::embed([$chunk],$cb));
                $db->begin();
                $db->run(COMMUNITY_PAGE_LOCK_SQL,[':pid'=>(int)$page['id']]);
                CommunityAi::input($q['public_id'],CommunityAi::freshUser($user),(int)$q['version']);
                // Consent revocation/grant resets the cursor; don't commit a stale in-flight chunk.
                $check=$db->run('SELECT * FROM community.embedding_queue WHERE page_id=:id FOR UPDATE',
                    [':id'=>(int)$page['id']])->fetch();
                if(!is_array($check)||$check['state']!=='pending'||(int)$check['version']!==(int)$q['version']||(int)$check['next_chunk']!==$n){throw new CommunityAiError('permission_changed',403);}
                $sql='INSERT INTO community.page_embeddings(page_id,chunk_no,version,vec) VALUES(:id,:n,:v,CAST(:vec AS real[])) ON CONFLICT(page_id,chunk_no) DO UPDATE SET version=EXCLUDED.version,vec=EXCLUDED.vec';
                $db->run($sql,[':id'=>(int)$page['id'],':n'=>$n,':v'=>(int)$q['version'],':vec'=>'{'.implode(',',$result['vectors'][0]).'}']);
                $sql='UPDATE community.embedding_queue SET next_chunk=:n,total_chunks=:total,state=:state,available_at=now(),updated_at=now() WHERE page_id=:id';
                $db->run($sql,[':n'=>$n+1,':total'=>$total,':state'=>$n+1===$total?'done':'pending',':id'=>(int)$page['id']]);
                $db->commit();$count++;
            }catch(CommunityAiError|AiUnavailable $e){
                $db->rollback();
                $retry=in_array($e->reason,['busy','daily_limit','unavailable','rate_limited','timeout'],true)||$e instanceof AiUnavailable;
                $sql="UPDATE community.embedding_queue SET state=:state,available_at=now()+CAST(:delay AS interval),updated_at=now() WHERE page_id=:id AND version=:v";
                $db->run($sql,[':state'=>$retry?'pending':'blocked',':delay'=>$e->reason==='daily_limit'?'1 hour':'1 minute',':id'=>(int)$q['page_id'],':v'=>(int)$q['version']]);
                if($e->reason==='busy'){break;}
                // Bound denied/offline work as well as successful chunks.
                $count++;
            }finally{Audit::automationActor(null);}
        }
    }finally{flock($lock,LOCK_UN);fclose($lock);}
    return ['chunks'=>$count,'deferred'=>$count>=20||$deadline-microtime(true)<10];
}
