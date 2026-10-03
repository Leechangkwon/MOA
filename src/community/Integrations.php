<?php
declare(strict_types=1);
// Stage 12 shared operations. All SQL values are bound; network transport lives in Fetch.
function community_integration_json($value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
function community_integration_body(array $allowed): array
{
    $b=Http::jsonBody();
    if (array_diff(array_keys($b),$allowed)!==[]) { Http::error(400,Http::MSG_BAD_REQUEST); }
    return $b;
}
function community_integration_name($value): string
{
    if (!is_string($value) || preg_match('//u',$value)!==1 || trim($value)==='' || iconv_strlen(trim($value),'UTF-8')>50 || preg_match('/\p{C}/u',$value)) { Http::error(400,Http::MSG_BAD_REQUEST); }
    return trim($value);
}
function community_integration_audit(string $action,?int $id=null,array $detail=[]): void
{
    Audit::allow($action,['target_type'=>'system','target_id'=>$id,'detail'=>$detail]);
}
function community_group_row(string $uuid,array $u,bool $lock=false): ?array
{
    $sql='SELECT g.*,EXISTS(SELECT 1 FROM community.share_group_members m WHERE m.group_id=g.id AND m.user_id=:uid) AS is_member FROM community.share_groups g WHERE g.public_id=CAST(:id AS uuid)';
    if ($lock) { $sql.=' FOR UPDATE OF g'; }
    $r=Db::instance()->run($sql,[':uid'=>(int)$u['id'],':id'=>$uuid])->fetch();
    return is_array($r) ? $r : null;
}
function community_group_out(array $r,array $u): array
{
    $members=Db::instance()->run('SELECT u.username,u.display_name FROM community.share_group_members m JOIN public.users u ON u.id=m.user_id WHERE m.group_id=:id ORDER BY u.username',
        [':id'=>(int)$r['id']])->fetchAll();
    return ['id'=>$r['public_id'],'name'=>$r['name'],'members'=>$members,'member_count'=>count($members),'can_manage'=>CommunityAuthz::decideGroupManage($u,$r)];
}
function community_group_shares(array $page,array $u): array
{
    $rows=Db::instance()->run('WITH RECURSIVE chain AS (SELECT id,parent_page_id,space_id,1 AS depth FROM community.pages WHERE id=:pid UNION ALL SELECT p.id,p.parent_page_id,p.space_id,c.depth+1 FROM community.pages p JOIN chain c ON p.id=c.parent_page_id WHERE p.space_id=c.space_id AND p.deleted_at IS NULL AND c.depth<16) SELECT g.*,sh.level,sh.page_id,EXISTS(SELECT 1 FROM community.share_group_members m WHERE m.group_id=g.id AND m.user_id=:uid) AS is_member,(SELECT count(*) FROM community.share_group_members m WHERE m.group_id=g.id) AS member_count FROM chain c JOIN community.page_shares sh ON sh.page_id=c.id JOIN community.share_groups g ON g.id=sh.group_id WHERE g.deleted_at IS NULL',
        [':pid'=>(int)$page['id'],':uid'=>(int)$u['id']])->fetchAll();
    return array_map(static function(array $r) use($u,$page): array {
        $can=CommunityAuthz::decideGroupRead($u,$r);
        return ['id'=>$can ? $r['public_id']:null,'name'=>$can ? $r['name']:null,'member_count'=>(int)$r['member_count'],'level'=>$r['level'],'inherited'=>(int)$r['page_id']!==(int)$page['id']];
    },$rows);
}

/** Source blocks are authored only inside their owning page. References carry no children. */
function community_sync_after_save(array $u,array $row,array $doc,array $extract,int $newVersion): void
{
    $db=Db::instance(); $sources=[]; $refs=[];
    $walk=static function(array $list) use (&$walk,&$sources,&$refs): void {
        foreach ($list as $b) {
            if (($b['type']??'')==='synced_source') { $sources[$b['source_id']]=$b['children']; }
            elseif (($b['type']??'')==='synced_ref') { $refs[$b['source_id']]=true; }
            else { $walk($b['children']??[]); }
        }
    }; $walk($doc['blocks']??[]);
    $db->run('UPDATE community.synced_sources SET deleted_at=now() WHERE page_id=:pid',
        [':pid'=>(int)$row['id']]);
    foreach ($sources as $uuid=>$blocks) {
        $old=$db->run('SELECT page_id FROM community.synced_sources WHERE public_id=CAST(:id AS uuid) FOR UPDATE',
        [':id'=>$uuid])->fetchColumn();
        if ($old!==false && (int)$old!==(int)$row['id']) { throw new InvalidArgumentException('synced_source_owner'); }
        $saved=$db->run('INSERT INTO community.synced_sources(public_id,page_id,blocks,version,updated_by) VALUES(CAST(:id AS uuid),:pid,CAST(:blocks AS jsonb),:version,:uid) ON CONFLICT(public_id) DO UPDATE SET blocks=EXCLUDED.blocks,version=EXCLUDED.version,updated_by=EXCLUDED.updated_by,updated_at=now(),deleted_at=NULL WHERE community.synced_sources.page_id=EXCLUDED.page_id',
        [':id'=>$uuid,':pid'=>(int)$row['id'],':blocks'=>community_integration_json($blocks),':version'=>$newVersion,':uid'=>(int)$u['id']])->rowCount();
        if($saved!==1) { throw new InvalidArgumentException('synced_source_owner'); }
    }
    $db->run('DELETE FROM community.synced_references WHERE page_id=:id',
        [':id'=>(int)$row['id']]);
    foreach ($refs as $uuid=>$_) {
        $db->run('INSERT INTO community.synced_references(page_id,source_id) VALUES(:pid,CAST(:id AS uuid))',
        [':pid'=>(int)$row['id'],':id'=>$uuid]);
    }
}
function community_sync_map(array $doc,?array $u,array $publicPages=[]): array
{
    $ids=[]; $walk=static function(array $list) use(&$walk,&$ids): void {
        foreach($list as $b) { if (($b['type']??'')==='synced_ref') { $ids[$b['source_id']]=true; } $walk($b['children']??[]); }
    }; $walk($doc['blocks']??[]); $out=[];
    foreach(array_slice(array_keys($ids),0,20) as $uuid) {
        $r=Db::instance()->run('SELECT s.*,p.public_id AS page_uuid,p.deleted_at AS page_deleted FROM community.synced_sources s JOIN community.pages p ON p.id=s.page_id WHERE s.public_id=CAST(:id AS uuid)',
        [':id'=>$uuid])->fetch();
        if (!is_array($r)) { $out[$uuid]=['message'=>'원본 없음']; continue; }
        $p=$u===null ? null:community_page_row($r['page_uuid'],(int)$u['id']);
        $accessPage=$p;if($accessPage!==null) { $accessPage['deleted_at']=null; }
        $allowed=$u===null ? in_array((int)$r['page_id'],$publicPages,true) : ($accessPage!==null && CommunityAuthz::decidePageRead($u,$accessPage)[0]);
        if (!$allowed) { $out[$uuid]=['message'=>'볼 수 없는 원본']; continue; }
        if ($r['page_deleted']!==null) { $out[$uuid]=['message'=>'원본이 삭제됨']; continue; }
        if ($r['deleted_at']!==null) { $out[$uuid]=['message'=>'원본 없음']; continue; }
        $out[$uuid]=['blocks'=>json_decode($r['blocks'],true),'page_id'=>$r['page_uuid'],'version'=>(int)$r['version']];
    }
    return $out;
}

function community_public_headers(): void
{
    header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Robots-Tag: noindex, nofollow');
}
function community_public_missing(): void { community_public_headers(); Http::error(404,Http::MSG_NOT_FOUND); }
function community_publication(string $token): array
{
    if (preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)!==1) { community_public_missing(); }
    $r=Db::instance()->run('SELECT w.* FROM community.web_publications w JOIN community.pages p ON p.id=w.page_id JOIN community.spaces s ON s.id=p.space_id WHERE w.token_hash=:hash AND w.revoked_at IS NULL AND (w.expires_at IS NULL OR w.expires_at>now()) AND p.deleted_at IS NULL AND s.web_publish_allowed',
        [':hash'=>hash('sha256',$token)])->fetch();
    if (!is_array($r)) { community_public_missing(); }
    return $r;
}
function community_public_pages(array $pub): array
{
    return Db::instance()->run('SELECT p.id,p.public_id,p.title,p.doc,u.display_name FROM community.web_publication_pages wp JOIN community.pages p ON p.id=wp.page_id JOIN community.spaces s ON s.id=p.space_id LEFT JOIN public.users u ON u.id=p.author_id WHERE wp.publication_id=:id AND p.deleted_at IS NULL AND s.web_publish_allowed ORDER BY CASE WHEN p.id=:root THEN 0 ELSE 1 END,p.position,p.id',
        [':id'=>(int)$pub['id'],':root'=>(int)$pub['page_id']])->fetchAll();
}
function community_public_rate(string $key,int $seconds,int $limit): bool
{
    $bucket=intdiv(time(),$seconds);
    $hash=hash_hmac('sha256',$key,Env::get('APP_SECRET'));
    $n=Db::instance()->run('INSERT INTO community.public_rate_buckets(key_hash,bucket,hits) VALUES(:key,:bucket,1) ON CONFLICT(key_hash) DO UPDATE SET bucket=EXCLUDED.bucket,hits=CASE WHEN community.public_rate_buckets.bucket=EXCLUDED.bucket THEN community.public_rate_buckets.hits+1 ELSE 1 END RETURNING hits',
        [':key'=>$hash,':bucket'=>$bucket])->fetchColumn();
    return (int)$n<=$limit;
}
function community_public_form(array $pub,string $viewId): array
{
    $db=Db::instance()->run('SELECT d.*,p.public_id AS page_uuid FROM community.databases d JOIN community.pages p ON p.id=d.page_id WHERE d.page_id=:page',
        [':page'=>(int)$pub['page_id']])->fetch();
    if (!is_array($db)) { community_public_missing(); }
    foreach(json_decode($db['views'],true)??[] as $v) {
        if (($v['id']??null)===$viewId && ($v['type']??'')==='form' && ($v['public']??false)===true && ($v['open']??false)===true) { return [$db,$v]; }
    }
    community_public_missing();
}
function community_public_form_signature(array $pub,string $view): string
{
    $body=(time()+1800).'.'.bin2hex(random_bytes(24));
    return $body.'.'.hash_hmac('sha256',$pub['id'].'.'.$view.'.'.$body,Env::get('APP_SECRET'));
}
function community_public_form_consume(array $pub,string $view,string $sig): bool
{
    if (preg_match('/^([0-9]{10})\.([a-f0-9]{48})\.([a-f0-9]{64})$/D',$sig,$m)!==1 || (int)$m[1]<time() || (int)$m[1]>time()+1800) { return false; }
    if (!hash_equals(hash_hmac('sha256',$pub['id'].'.'.$view.'.'.$m[1].'.'.$m[2],Env::get('APP_SECRET')),$m[3])) { return false; }
    return Db::instance()->run('INSERT INTO community.public_form_uses(nonce_hash,publication_id) VALUES(:hash,:pub) ON CONFLICT DO NOTHING',
        [':hash'=>hash('sha256',$sig),':pub'=>(int)$pub['id']])->rowCount()===1;
}

/** Fixed root + integer ID only; no request-defined secret paths. No secret in exceptions. */
function community_integration_secret(string $kind,int $id): ?string
{
    if (!in_array($kind,['hooks','connectors'],true) || $id<1) { throw new InvalidArgumentException('secret_kind'); }
    $path='/etc/tbox-v2/'.$kind.'/'.$id.($kind==='hooks'?'.url':'.key');
    if (!community_secret_configured($kind,$id)) { return null; }
    $stat=stat($path);
    if ($stat===false || $stat['size']>4096 || ($stat['mode']&0007)!==0 || ($stat['mode']&0022)!==0) { return null; }
    $s=file_get_contents($path); return $s===false ? null:trim($s);
}
function community_messenger_payload(array $n,array $page): array
{
    $public=in_array(CommunityAuthz::baseVisibility($page),['internal','published'],true)
        || ($page['space_kind']!=='personal' && in_array($page['space_access_level'],['internal','public'],true));
    return ['text'=>$n['kind'].' · '.($public?$page['title']:'(제목 숨김)')."\n".rtrim(Env::get('APP_URL'),'/').'/community/p/'.$page['public_id']];
}
function community_run_integrations(int $limit=10): array
{
    if (PHP_SAPI!=='cli') { throw new LogicException('CLI only'); }
    $db=Db::instance();$sent=0;$synced=0;$started=microtime(true);
    $rows=$db->run("SELECT id FROM community.notification_outbox WHERE status='pending' AND next_at<=now() ORDER BY id LIMIT :lim",
        [':lim'=>max(1,min($limit,10))])->fetchAll();
    foreach($rows as $item) {
        if (microtime(true)-$started>25) { break; }
        $db->begin();
        try {
            $n=$db->run('SELECT o.*,n.user_id,n.kind,n.retracted_at,p.public_id AS page_uuid,h.url_host,h.is_active FROM community.notification_outbox o JOIN community.notifications n ON n.id=o.notification_id JOIN community.pages p ON p.id=n.page_id JOIN community.messenger_hooks h ON h.id=o.hook_id WHERE o.id=:id AND o.status=\'pending\' FOR UPDATE OF o SKIP LOCKED',
        [':id'=>(int)$item['id']])->fetch();
            if (!is_array($n)) { $db->commit();continue; }
            $u=community_user_by_id((int)$n['user_id']); $p=community_page_row($n['page_uuid'],(int)$n['user_id']);
            $opt=$db->run('SELECT 1 FROM community.user_messenger WHERE user_id=:u AND hook_id=:h AND :kind=ANY(kinds)',
        [':u'=>(int)$n['user_id'],':h'=>(int)$n['hook_id'],':kind'=>$n['kind']])->fetchColumn();
            $status='skipped';$reason='denied';$tries=(int)$n['tries'];
            if ($n['retracted_at']===null && $n['is_active'] && $opt && $u!==null && $p!==null && CommunityAuthz::decideMentionTarget($u,$p)[0]) {
                $secret=community_integration_secret('hooks',(int)$n['hook_id']);
                try {
                    if ($secret===null) { throw new RuntimeException('secret_missing'); }
                    if (strtolower((string)parse_url($secret,PHP_URL_HOST))!==$n['url_host']) { throw new RuntimeException('host_not_allowed'); }
                    Fetch::post($secret,community_messenger_payload($n,$p));$status='sent';$reason='ok';$sent++;
                } catch(Throwable $e) { $tries++;$status=$tries>=4?'failed':'pending';$reason=$e instanceof FetchDenied?$e->reason:($e->getMessage()==='secret_missing'?'secret_missing':'failed'); }
            }
            $minutes=[1,5,30][min(2,max(0,$tries-1))];
            $db->run("UPDATE community.notification_outbox SET status=:status,tries=:tries,next_at=now()+make_interval(mins=>:mins),sent_at=CASE WHEN :ok THEN now() ELSE NULL END,last_reason=:reason WHERE id=:id",
        [':status'=>$status,':tries'=>$tries,':mins'=>$minutes,':ok'=>$status==='sent'?'true':'false',':reason'=>$reason,':id'=>(int)$n['id']]);
            community_integration_audit('integration.result',(int)$n['id'],['purpose'=>'messenger','status'=>$status,'reason'=>$reason]);$db->commit();
        } catch(Throwable $e) { $db->rollback();throw $e; }
    }
    foreach($db->run('SELECT * FROM community.data_connectors WHERE is_active ORDER BY last_run_at NULLS FIRST,id LIMIT 3')->fetchAll()



         as $c) {
        if(microtime(true)-$started>40) { break; }
        $now=new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul'));
        $slot=Automation::latestSlot(json_decode($c['schedule'],true),$now);
        if ($c['last_run_at']!==null && $slot<=new DateTimeImmutable($c['last_run_at'])) { continue; }
        community_connector_run($c);$synced++;
    }
    return ['sent'=>$sent,'connectors'=>$synced];
}
function community_connector_run(array $initial): void
{
    $db=Db::instance();$db->begin();
    try {
        $c=$db->run('SELECT * FROM community.data_connectors WHERE id=:id AND is_active FOR UPDATE SKIP LOCKED',
        [':id'=>(int)$initial['id']])->fetch();
        if(!is_array($c)) { $db->commit();return; }
        $u=community_user_by_id((int)$c['runner_user_id']);$d=$db->run('SELECT d.*,p.public_id AS page_uuid FROM community.databases d JOIN community.pages p ON p.id=d.page_id WHERE d.id=:id FOR UPDATE OF d',
        [':id'=>(int)$c['database_id']])->fetch();
        $p=is_array($d)?community_page_row($d['page_uuid'],(int)$c['runner_user_id']):null;
        if($u===null || !$u['is_active'] || ($u['expires_at']!==null && strtotime($u['expires_at'])<=time()) || $p===null || !Authz::canCommunityWrite($u) || !CommunityAuthz::decidePageChange($u,$p)[0]) {
            $db->run("UPDATE community.data_connectors SET is_active=false,last_status='denied',last_run_at=now() WHERE id=:id",
        [':id'=>(int)$c['id']]);
            community_integration_audit('integration.result',(int)$c['id'],['purpose'=>'connector','status'=>'denied']);
            if($u!==null && $p!==null) { community_notify((int)$c['runner_user_id'],'automation_denied',$p,null,null); }
            $db->commit();return;
        }
        $key=community_integration_secret('connectors',(int)$c['id']);
        if($key===null) { throw new RuntimeException('secret_missing'); }
        $rows=Fetch::getJson('https://'.$c['url_host'].$c['url_path'],$key);
        community_connector_import($c,$d,$p,$u,$rows);
        $db->run("UPDATE community.data_connectors SET last_run_at=now(),last_status='ok' WHERE id=:id",
        [':id'=>(int)$c['id']]);
        community_integration_audit('integration.result',(int)$c['id'],['purpose'=>'connector','status'=>'ok','rows'=>count($rows)]);$db->commit();
    } catch(Throwable $e) {
        $db->rollback();$reason=$e instanceof FetchDenied ? $e->reason : ($e->getMessage()==='secret_missing'?'secret_missing':'invalid_data');
        $db->run('UPDATE community.data_connectors SET last_run_at=now(),last_status=:status WHERE id=:id',
        [':status'=>$reason,':id'=>(int)$initial['id']]);
        community_integration_audit('integration.result',(int)$initial['id'],['purpose'=>'connector','status'=>$reason]);
    }
}
function community_connector_import(array $c,array $d,array $parent,array $u,array $rows): void
{
    if (!array_is_list($rows) || count($rows)>5000) { throw new InvalidArgumentException('too_many_rows'); }
    $db=Db::instance();$map=json_decode($c['field_map'],true);$schema=community_db_schema_of($d['schema']);$clean=[];$seen=[];
    foreach($rows as $r) {
        if(!is_array($r) || !isset($r['external_key']) || !is_scalar($r['external_key'])) { throw new InvalidArgumentException('external_key'); }
        $key=(string)$r['external_key'];if($key==='' || strlen($key)>200 || preg_match('/\p{C}/u',$key) || isset($seen[$key])) { throw new InvalidArgumentException('external_key'); }$seen[$key]=true;
        $values=[];
        foreach($map as $from=>$to) {
            $prop=DbProps::find($schema,$to);
            if($prop===null || !in_array($prop['type'],['text','number','checkbox','url','date','select','multi_select'],true)) { throw new InvalidArgumentException('field_map'); }
            [$ok,$value]=DbProps::cleanValue($prop,$r[$from]??null);if(!$ok) { throw new InvalidArgumentException('bad_value'); }$values[$to]=$value;
        }
        $clean[]=[$key,$values];
    }
    $db->run('UPDATE community.pages SET external_missing=true WHERE external_connector_id=:id',
        [':id'=>(int)$c['id']]);
    foreach($clean as [$key,$values]) {
        $old=$db->run('SELECT id,external_connector_id FROM community.pages WHERE database_id=:db AND external_key=:key FOR UPDATE',
        [':db'=>(int)$d['id'],':key'=>$key])->fetch();
        if(is_array($old)) {
            if((int)$old['external_connector_id']!==(int)$c['id']) { throw new InvalidArgumentException('external_key_conflict'); }
            $db->run('UPDATE community.pages SET properties=CAST(:props AS jsonb),external_missing=false WHERE id=:id',
        [':props'=>community_integration_json($values?:new stdClass()),':id'=>(int)$old['id']]);continue;
        }
        if(community_db_row_count((int)$d['id'])>=DbProps::MAX_ROWS) { throw new InvalidArgumentException('too_many_rows'); }
        $doc=['schema'=>1,'blocks'=>[['id'=>Doc::uuid4(),'type'=>'paragraph','text'=>[]]]];
        $created=community_insert_page(['space_id'=>(int)$parent['space_id'],'parent_id'=>(int)$parent['id'],
            'title'=>$key,'doc_json'=>community_integration_json($doc),'visibility'=>'private',
            'position'=>community_next_position((int)$parent['space_id'],(int)$parent['id']),
            'database_id'=>(int)$d['id'],'properties'=>$values],Doc::extract($doc),$u,'connector');
        $db->run('UPDATE community.pages SET external_key=:key,external_connector_id=:connector WHERE id=:id',
        [':key'=>$key,':connector'=>(int)$c['id'],':id'=>(int)$created['id']]);
    }
}


function community_form_public_allowed(array $page,array $u): bool
{
    return CommunityAuthz::decideWebPublish($u,$page)[0] && (bool)Db::instance()->run('SELECT 1 FROM community.web_publications WHERE page_id=:p AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>now())',
        [':p'=>(int)$page['id']])->fetchColumn();
}
function community_secret_configured(string $kind,int $id): bool
{
    if(!in_array($kind,['hooks','connectors'],true)||$id<1) { return false; }
    $path='/etc/tbox-v2/'.$kind.'/'.$id.($kind==='hooks'?'.url':'.key');
    $s=@lstat($path);$group=function_exists('posix_getgrnam')?posix_getgrnam('tbox-php'):false;
    return is_array($s) && is_array($group) && $s['uid']===0 && $s['gid']===$group['gid'] && ($s['mode']&0777)===0640 && (($s['mode']&0170000)===0100000) && ($s['mode']&0027)===0 && $s['size']>0 && $s['size']<=4096 && is_readable($path);
}

function community_sync_uses(array $page,array $u): array
{
    [$where,$bind]=community_visible_pages_where($u);
    $sql=<<<SQL
        SELECT DISTINCT p.public_id AS id,p.title FROM community.synced_sources ss
        JOIN community.synced_references sr ON sr.source_id=ss.public_id
        JOIN community.pages p ON p.id=sr.page_id JOIN community.spaces s ON s.id=p.space_id
        LEFT JOIN public.users owner ON owner.id=s.owner_user_id
        WHERE ss.page_id=:source_page AND ss.deleted_at IS NULL AND
        {$where}
        ORDER BY p.title,p.public_id
        SQL;
    return Db::instance()->run($sql,[':source_page'=>(int)$page['id']]+$bind)->fetchAll();
}
