<?php
declare(strict_types=1);

/** Stage 13 / C:F-56: permissions, immutable evidence and bounded AI orchestration.
 * The only network implementation is Ai.php. No model text is ever executable. */
final class CommunityAiError extends RuntimeException
{
    public function __construct(public string $reason, public int $httpStatus=400)
    { parent::__construct($reason); }
}
final class CommunityAi
{
    public const FEATURES=['search','summary','assistant','transcribe','briefing'];
    public const WARNING='AI가 만든 글 — 틀릴 수 있습니다';
    public static function cut(string $text,int $length): string
    { return (string)iconv_substr($text,0,$length,'UTF-8'); }
    public static function length(string $text): int
    { return (int)iconv_strlen($text,'UTF-8'); }
    public static function json(mixed $value): string
    { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    public static function settings(): array
    {
        $r=Db::instance()->run('SELECT * FROM community.ai_settings WHERE singleton')->fetch();
        if (!is_array($r)) { throw new CommunityAiError('unavailable',503); }
        $features=[];foreach(self::FEATURES as $f) { $features[$f]=$r[$f.'_enabled']===true; }
        return ['external_data_mode'=>$r['external_data_mode'],'features'=>$features,'configured'=>Ai::configured(),
            'provider'=>'Google Gemini','model'=>Ai::MODEL,'embedding_model'=>Ai::EMBED_MODEL];
    }
    public static function ready(string $feature): void
    {
        $s=self::settings();$f=$feature==='index'?'search':$feature;
        if ($s['external_data_mode']==='unconfigured' || !$s['configured'] || !($s['features'][$f]??false)) {
            throw new CommunityAiError('unavailable',503);
        }
    }
    public static function freshUser(array $user): array
    {
        $u=community_user_by_id((int)$user['id']);
        if ($u===null || !$u['is_active'] || ($u['expires_at']!==null && strtotime($u['expires_at'])<=time())) {
            throw new CommunityAiError('permission_changed',403);
        }
        // Never turn an administrative role preview into the real administrator during inference.
        if (($user['acting']??false)===true) {
            if ($u['role']!==($user['real_role']??null) || $u['team_id']!==($user['real_team_id']??null)) {
                throw new CommunityAiError('permission_changed',403);
            }
            $u['role']=$user['role'];$u['team_id']=$user['team_id'];
            $u['acting']=true;$u['real_role']=$user['real_role'];$u['real_team_id']=$user['real_team_id'];
        }
        return $u;
    }
    /** Extra context is read from canonical tables, never accepted from a request. */
    public static function page(string $id,array $user): ?array
    {
        $r=community_page_row($id,(int)$user['id']);if($r===null){return null;}
        $sql=<<<'SQL'
            SELECT p.purged_at,ap.version AS allowed_version,
              (COALESCE((to_jsonb(p)->>'form_anonymous')::boolean,false)
               OR COALESCE((to_jsonb(p)->>'public_form_response')::boolean,false)
               OR COALESCE(to_jsonb(p)->>'absence_projection','false')='true'
               OR COALESCE(p.properties->>'tool','')='leave'
               OR EXISTS (SELECT 1 FROM community.databases d WHERE (d.id=p.database_id OR d.page_id=p.id) AND d.tool='leave')) AS excluded
            FROM community.pages p LEFT JOIN community.ai_page_permissions ap ON ap.page_id=p.id WHERE p.id=:id
            SQL;
        $x=Db::instance()->run($sql,[':id'=>(int)$r['id']])->fetch();
        $r['purged_at']=$x['purged_at'];$r['ai_excluded']=$x['excluded']===true;
        $r['ai_allowed_version']=$x['allowed_version']===null?0:(int)$x['allowed_version'];return $r;
    }
    public static function input(string $id,array $user,?int $version=null): array
    {
        $r=self::page($id,$user);
        if($r===null || !CommunityAuthz::decideAiInput($user,$r)[0]){throw new CommunityAiError('not_available',404);}
        if($version!==null && (int)$r['doc_version']!==$version){throw new CommunityAiError('version_conflict',409);}
        return $r;
    }
    public static function ref(array $r): array
    { return ['page_id'=>(string)$r['public_id'],'version'=>(int)$r['doc_version']]; }
    public static function citations(array $refs,array $user): array
    {
        $out=[];foreach($refs as $ref){
            $p=self::input($ref['page_id'],$user,(int)$ref['version']);
            $out[]=['page_id'=>$ref['page_id'],'version'=>(int)$ref['version'],'title'=>$p['title'],
                'url'=>Http::communityUrl('/p/'.$ref['page_id'])];
        }return $out;
    }
    public static function checkRefs(array $user,array $refs,array $files=[]): array
    {
        $u=self::freshUser($user);
        foreach($refs as $r){self::input($r['page_id'],$u,(int)$r['version']);}
        foreach($files as $r){
            $f=self::audioFile($r['file_id'],$u,false);
            if(!hash_equals($r['sha256'],$f['sha256']) || (int)$r['bytes']!==(int)$f['size_bytes']){
                throw new CommunityAiError('permission_changed',403);
            }
        }return $u;
    }
    public static function checkHistory(array $user,array $refs,array $files): void
    {
        $u=self::freshUser($user);
        foreach($refs as $ref){
            $p=self::page($ref['page_id'],$u);
            if($p===null){throw new CommunityAiError('not_available',404);}
            // Historical consent is evidence; reading a journal is not another external transmission.
            $p['ai_allowed_version']=(int)$p['doc_version'];
            if(!CommunityAuthz::decideAiInput($u,$p)[0]){throw new CommunityAiError('not_available',404);}
        }
        foreach($files as $ref){self::audioFile($ref['file_id'],$u,false);}
    }
    public static function remaining(array $user): int
    {
        $sql="SELECT count(*) FROM community.ai_requests WHERE user_id=:id AND day=(now() AT TIME ZONE 'Asia/Seoul')::date";
        return max(0,50-(int)Db::instance()->run($sql,[':id'=>(int)$user['id']])->fetchColumn());
    }
    /** Called only after Ai's nonblocking global lock. Never hold this transaction over HTTP. */
    private static function reserve(array $user,string $feature): array
    {
        $db=Db::instance();if($db->inTransaction()){throw new LogicException('AI cannot run in a database transaction');}
        $db->begin();try{
            $db->run('SELECT pg_advisory_xact_lock(1053,CAST(:uid AS integer))',
                [':uid'=>(int)$user['id']]);
            if(self::remaining($user)===0){throw new CommunityAiError('daily_limit',429);}
            $sql='INSERT INTO community.ai_requests(user_id,feature) VALUES(:uid,:feature) RETURNING id,started_at';
            $r=$db->run($sql,[':uid'=>(int)$user['id'],':feature'=>$feature])->fetch();$db->commit();return $r;
        }catch(Throwable $e){$db->rollback();throw $e;}
    }
    private static function log(array $u,string $feature,string $input,string $output,array $refs,array $files,?array $request,string $model,string $status,string $start): string
    {
        if($feature==='index'){$input='';$output='';}
        $sql=<<<'SQL'
            INSERT INTO community.ai_log(request_id,user_id,feature,page_refs,file_refs,input_text,output_text,model,started_at,status,chars_in,chars_out)
            VALUES(:request,:uid,:feature,CAST(:pages AS jsonb),CAST(:files AS jsonb),:input,:output,:model,CAST(:started AS timestamptz),:status,:ci,:co) RETURNING public_id
            SQL;
        $id=(string)Db::instance()->run($sql,[':request'=>$request['id']??null,':uid'=>(int)$u['id'],':feature'=>$feature,
            ':pages'=>self::json($refs),':files'=>self::json($files),':input'=>$input,':output'=>$output,':model'=>$model,
            ':started'=>$request['started_at']??$start,':status'=>$status,':ci'=>self::length($input),':co'=>self::length($output)])->fetchColumn();
        $ctx=['detail'=>['ai_log_id'=>$id,'feature'=>$feature,'pages'=>count($refs)]];
        if($status==='ok'){Audit::allow('ai.request',$ctx);}else{Audit::deny('ai.request','unavailable',$ctx);}
        return $id;
    }
    /** invoke($beforeSend) and finish($result) are fixed PHP callables, never request data. */
    public static function perform(array $user,string $feature,string $input,array $refs,array $files,callable $invoke,?callable $finish=null): array
    {
        self::ready($feature);self::checkRefs($user,$refs,$files);
        if(self::length($input)>Ai::MAX_TEXT){throw new CommunityAiError('input_too_long',400);}
        $request=null;$start=gmdate(DATE_ATOM);$sent=false;
        $model=in_array($feature,['index','search'],true)?Ai::EMBED_MODEL:Ai::MODEL;
        try{
            $before=function()use($user,$feature,$refs,$files,&$request,&$sent):void{
                self::ready($feature);$u=self::checkRefs($user,$refs,$files);
                $request=self::reserve($u,$feature);$sent=true;
            };
            $result=$invoke($before);
            $u=self::checkRefs($user,$refs,$files);self::ready($feature);
            if($finish!==null){$result=$finish($result,$u);}
            $logRefs=$result['_log_refs']??$refs;unset($result['_log_refs']);
            self::checkRefs($user,$logRefs,$files);
            $citations=self::citations($refs,$u);
            $text=(string)($result['text']??'');
            $id=self::log($user,$feature,$input,$text,$logRefs,$files,$request,$model,'ok',$start);
            return $result+['log_id'=>$id,'warning'=>self::WARNING,'citations'=>$citations,'remaining'=>self::remaining($u)];
        }catch(Throwable $e){
            $reason=$e instanceof CommunityAiError || $e instanceof AiUnavailable ? $e->reason:'unavailable';
            // The journal never includes an output that was withheld after permission/version changes.
            self::log($user,$feature,$sent?$input:'','',$refs,$files,$request,$model,$reason,$start);
            throw $e;
        }
    }
    /** Exact same ordinary WHERE as keyword search, then inner products inside that scope. */
    public static function ranked(array $user,array $vector,bool $procedures=false): array
    {
        [$visible,$bind]=community_search_pages_where($user);
        $sql=<<<SQL
            SELECT p.public_id,p.title,p.doc_version,p.plain_text,max(score.value) AS score
            FROM community.pages p JOIN community.spaces s ON s.id=p.space_id
            LEFT JOIN public.users owner ON owner.id=s.owner_user_id
            JOIN community.ai_page_permissions ap ON ap.page_id=p.id AND ap.version=p.doc_version
            JOIN community.embedding_queue eq ON eq.page_id=p.id AND eq.version=p.doc_version AND eq.state='done'
            JOIN community.page_embeddings pe ON pe.page_id=p.id AND pe.version=p.doc_version
            CROSS JOIN LATERAL (SELECT sum(v*x) AS value FROM unnest(pe.vec,CAST(:vector AS real[])) AS dot(v,x)) score
            WHERE p.deleted_at IS NULL AND p.purged_at IS NULL
              AND {$visible}
              AND NOT COALESCE((to_jsonb(p)->>'form_anonymous')::boolean,false)
              AND NOT COALESCE((to_jsonb(p)->>'public_form_response')::boolean,false)
              AND COALESCE(to_jsonb(p)->>'absence_projection','false')<>'true'
              AND COALESCE(p.properties->>'tool','')<>'leave'
              AND NOT EXISTS(SELECT 1 FROM community.databases d WHERE (d.id=p.database_id OR d.page_id=p.id) AND d.tool='leave')
              AND (NOT CAST(:procedures AS boolean) OR p.page_kind='procedure')
              AND eq.total_chunks>0 AND eq.next_chunk=eq.total_chunks
              AND (SELECT count(*) FROM community.page_embeddings allpe WHERE allpe.page_id=p.id AND allpe.version=p.doc_version)=eq.total_chunks
            GROUP BY p.id ORDER BY score DESC,p.id LIMIT 20
            SQL;
        $bind+=[':vector'=>'{'.implode(',',array_map(static fn($n):string=>(string)(float)$n,$vector)).'}',':procedures'=>$procedures?'true':'false'];
        return Db::instance()->run($sql,$bind)->fetchAll();
    }
    public static function search(array $user,string $q,bool $procedures=false): array
    {
        if($q==='' || self::length($q)>2000){throw new CommunityAiError('invalid_question');}
        return self::perform($user,'search',$q,[],[],static fn($cb)=>Ai::embed([$q],$cb,'query'),
            static function(array $result,array $u)use($procedures):array{
                $out=[];foreach(self::ranked($u,$result['vectors'][0],$procedures) as $r){
                    $p=self::input($r['public_id'],$u,(int)$r['doc_version']);
                    $out[]=['page_id'=>$p['public_id'],'version'=>(int)$p['doc_version'],'title'=>$p['title'],
                        'url'=>Http::communityUrl('/p/'.$p['public_id']),'snippet'=>self::cut($r['plain_text'],160)];
                }
                return ['results'=>$out,'text'=>self::json(array_column($out,'page_id')),
                    '_log_refs'=>array_map(static fn($r)=>['page_id'=>$r['page_id'],'version'=>$r['version']],$out)];
            });
    }
    public static function generate(array $user,string $feature,string $input,array $refs): array
    { return self::perform($user,$feature,$input,$refs,[],static fn($cb)=>Ai::generate($input,$feature,$cb)); }
    /** Read/gate/hash is shared with ordinary download. No filename enters AI or its audit. */
    public static function audioFile(string $id,array $user,bool $bytes): array
    {
        require_once __DIR__ . '/../handlers/_download_common.php';
        if(!preg_match('/^[0-9a-f-]{36}$/D',$id)){throw new CommunityAiError('not_available',404);}
        $f=tbox_download_find($id);if($f===null){throw new CommunityAiError('not_available',404);}
        $safe=$f;$safe['original_name']='녹음 파일';
        if(tbox_download_gate($user,$safe)[0]!==null){throw new CommunityAiError('not_available',404);}
        if((int)$f['size_bytes']>Ai::MAX_AUDIO){throw new CommunityAiError('audio_too_large',413);}
        if(tbox_download_integrity($safe)[0]!==null){throw new CommunityAiError('not_available',404);}
        $path=Storage::pathFor($f['stored_name']);
        try{$probe=Upload::validatePath($path,$f['original_name']);}catch(Throwable $e){throw new CommunityAiError('invalid_audio');}
        if(!in_array($probe['ext'],['wav','mp3','m4a','ogg'],true) || (int)$probe['size']>Ai::MAX_AUDIO){throw new CommunityAiError('invalid_audio');}
        if(!hash_equals($f['sha256'],$probe['sha256'])){throw new CommunityAiError('not_available',404);}
        $f['mime']=$probe['mime'];$f['size_bytes']=(int)$probe['size'];
        if($bytes){$data=file_get_contents($path);if(!is_string($data)||strlen($data)!==$f['size_bytes']||!hash_equals($f['sha256'],hash('sha256',$data))){throw new CommunityAiError('not_available',404);} $f['bytes']=$data;}
        return $f;
    }
    public static function transcribe(array $user,string $id,string $language): array
    {
        self::ready('transcribe');if(!in_array($language,['ko','en','auto'],true)){throw new CommunityAiError('invalid_language');}
        $f=self::audioFile($id,$user,true);$refs=[['file_id'=>$id,'sha256'=>$f['sha256'],'bytes'=>(int)$f['size_bytes']]];
        return self::perform($user,'transcribe','',[],$refs,static fn($cb)=>Ai::transcribe($f['bytes'],$language,$f['mime'],$cb));
    }
}

/** Called by the one common save path, in the same transaction. New versions require new consent. */
function community_embedding_after_save(array $user,array $row,array $doc,array $extract,int $newVersion): void
{
    $db=Db::instance();$id=(int)$row['id'];
    $db->run('DELETE FROM community.ai_page_permissions WHERE page_id=:id',
                [':id'=>$id]);
    $db->run('DELETE FROM community.page_embeddings WHERE page_id=:id',
                [':id'=>$id]);
    $sql="INSERT INTO community.embedding_queue(page_id,version,state) VALUES(:id,:v,'blocked') ON CONFLICT(page_id) DO UPDATE SET version=EXCLUDED.version,next_chunk=0,total_chunks=0,state='blocked',updated_at=now()";
    $db->run($sql,[':id'=>$id,':v'=>$newVersion]);
}
