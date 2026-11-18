<?php
declare(strict_types=1);

/** Q:F-17. Every endpoint uses these page gates, including token requests. */
final class Whiteboards
{
    public static function page(array $user, string $id, bool $write = false): array
    {
        $row=WorkDocuments::row($user,$id);
        if (($row['db_purpose'] ?? null)!=='project' || $row['database_id']===null
            || ($row['external_key'] ?? null)!==null) { Http::error(404,Http::MSG_NOT_FOUND); }
        if ($write && (!Authz::canCommunityWrite($user) || !CommunityAuthz::decidePageChange($user,$row)[0])) {
            if (Db::instance()->inTransaction()) { Db::instance()->rollback(); }
            Audit::deny('whiteboard.change','refused',['target_type'=>'page','target_id'=>(int)$row['id'],
                'detail'=>['cause'=>'read_only']]);
            Http::error(403,Http::MSG_FORBIDDEN);
        }
        return $row;
    }

    public static function board(array $page, ?int $version = null, bool $lock = false): ?array
    {
        $sql='SELECT * FROM community.whiteboards WHERE page_id=CAST(:page AS bigint)';
        $bind=[':page'=>(int)$page['id']];
        if ($version!==null) { $sql.=' AND version_no=CAST(:version AS int)'; $bind[':version']=$version; }
        $sql.=' ORDER BY version_no DESC LIMIT 1';
        if ($lock) { $sql.=' FOR UPDATE'; }
        $row=Db::instance()->run($sql,$bind)->fetch();
        return is_array($row) ? $row : null;
    }

    public static function elements(int $id): array
    {
        return array_map(static fn(array $r): array=>json_decode($r['data'],true,32,JSON_THROW_ON_ERROR),
            Db::instance()->run("SELECT data FROM community.whiteboard_elements WHERE whiteboard_id=CAST(:id AS bigint)
                ORDER BY (data->>'index') COLLATE \"C\",element_id COLLATE \"C\"",
            [':id'=>$id])->fetchAll());
    }

    public static function summary(array $user, array $page): ?array
    {
        [$ok,$reason]=CommunityAuthz::decidePageRead($user,$page);
        if (!$ok || in_array($reason,[CommunityAuthz::CODE_ADMIN_ALL,CommunityAuthz::CODE_BOARD_MODERATOR],true)
            || ($page['db_purpose'] ?? null)!=='project' || $page['database_id']===null
            || ($page['external_key'] ?? null)!==null) { return null; }
        $board=self::board($page);
        return ['can_create'=>Authz::canCommunityWrite($user) && CommunityAuthz::decidePageChange($user,$page)[0],
            'exists'=>$board!==null,'version_no'=>$board===null ? null : (int)$board['version_no'],
            'state'=>$board['state'] ?? null,'url'=>COMMUNITY_BASE.'/p/'.$page['public_id'].'/whiteboard'];
    }

    public static function read(array $user, string $id, ?int $version = null): array
    {
        $page=self::page($user,$id); $board=self::board($page,$version);
        if ($board===null) { return ['board'=>null,'elements'=>[],'can'=>['create'=>Authz::canCommunityWrite($user) && CommunityAuthz::decidePageChange($user,$page)[0]]]; }
        $db=Db::instance(); $bid=(int)$board['id'];
        $db->run("INSERT INTO community.whiteboard_presence(whiteboard_id,user_id) VALUES(:board,:user)
            ON CONFLICT(whiteboard_id,user_id) DO UPDATE SET seen_at=now()
            WHERE whiteboard_presence.seen_at<now()-interval '5 seconds'",
            [':board'=>$bid,':user'=>(int)$user['id']]);
        $presence=$db->run("SELECT u.id,COALESCE(u.display_name,u.username) AS name FROM community.whiteboard_presence p
            JOIN public.users u ON u.id=p.user_id WHERE p.whiteboard_id=:id AND p.seen_at>now()-interval '20 seconds'
            ORDER BY u.id LIMIT 100",
            [':id'=>$bid])->fetchAll();
        $can=Authz::canCommunityWrite($user) && CommunityAuthz::decidePageChange($user,$page)[0];
        $snapshot=$db->run('SELECT scene_sha256,png_sha256,chain_head FROM community.whiteboard_snapshots WHERE whiteboard_id=:id',
            [':id'=>$bid])->fetch();
        $lockedName=$board['locked_by']===null ? null : $db->run('SELECT COALESCE(display_name,username) FROM public.users WHERE id=:id',
            [':id'=>(int)$board['locked_by']])->fetchColumn();
        return ['board'=>['id'=>$board['public_id'],'version_no'=>(int)$board['version_no'],'state'=>$board['state'],
            'last_seq'=>(int)$board['last_seq'],'locked_at'=>$board['locked_at'],'locked_by_name'=>$lockedName,'snapshot'=>$snapshot ?: null],
            'title'=>$page['title'],'page_id'=>$id,'doc_version'=>(int)$page['doc_version'],
            'elements'=>self::elements($bid),'can'=>['draw'=>$can && $board['state']==='open',
            'confirm'=>$can && $board['state']==='open','new_version'=>$can && $board['state']==='locked',
            'minutes'=>$can && $board['state']==='locked'], 'presence'=>$presence,
            'versions'=>$db->run('SELECT version_no,state,locked_at FROM community.whiteboards WHERE page_id=:page ORDER BY version_no DESC LIMIT 100',
                [':page'=>(int)$page['id']])->fetchAll()];
    }

    /** Page lock always precedes board lock; stale tabs must explicitly identify their board. */
    public static function transaction(array $user,string $id,?string $expected,callable $fn)
    {
        $db=Db::instance(); $db->begin();
        try {
            $page=self::page($user,$id,true);
            $db->run(COMMUNITY_PAGE_LOCK_SQL,[':pid'=>(int)$page['id']]);
            $page=self::page($user,$id,true); $board=self::board($page,null,true);
            if ($expected!==null && ($board===null || $board['public_id']!==$expected)) { Http::error(409,'판이 바뀌었습니다. 새로 열어 주세요.'); }
            $out=$fn($page,$board); $db->commit(); return $out;
        } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollback(); } throw $e; }
    }

    public static function create(array $user,string $id,?string $expected): array
    {
        self::transaction($user,$id,$expected,static function(array $page,?array $old) use ($user): void {
            if ($old!==null && $old['state']==='open') { return; }
            $board=Db::instance()->run('INSERT INTO community.whiteboards(page_id,version_no,created_by)
                VALUES(:page,:version,:user) RETURNING *',
            [':page'=>(int)$page['id'],':version'=>$old===null ? 1 : (int)$old['version_no']+1,
                ':user'=>(int)$user['id']])->fetch();
            if ($old!==null) {
                foreach (self::elements((int)$old['id']) as $e) {
                    if (!$e['isDeleted']) { self::append($board,$e,$user,null); }
                }
            }
            self::audit($old===null ? 'create' : 'version',$page,$board);
        });
        return self::read($user,$id);
    }

    public static function audit(string $action,array $page,array $board,array $detail=[]): void
    {
        Audit::allow('whiteboard.'.$action,['target_type'=>'page','target_id'=>(int)$page['id'],
            'target_name'=>community_target_title((string)$page['title']),
            'detail'=>$detail+['version_no'=>(int)$board['version_no'],'last_seq'=>(int)$board['last_seq']]]);
    }

    /** Called with both locks held. Origin author remains unchanged on edits. */
    public static function append(array &$board,array $e,array $user,?array $old): void
    {
        $db=Db::instance(); $bid=(int)$board['id']; $seq=(int)$board['last_seq']+1;
        $previous=$db->run('SELECT hash FROM community.whiteboard_events WHERE whiteboard_id=:id ORDER BY seq DESC LIMIT 1',
            [':id'=>$bid])->fetchColumn();
        $at=$db->run("SELECT to_char(now() AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')")->fetchColumn();
        $event=['prev_hash'=>$previous ?: WhiteboardSync::ZERO,'seq'=>$seq,'element_id'=>$e['id'],
            'op'=>$old===null ? 'create' : ($e['isDeleted'] ? 'delete' : 'update'),
            'actor_id'=>(int)$user['id'],'at_utc'=>$at,'element_version'=>$e['version'],'data'=>$e];
        $db->run('INSERT INTO community.whiteboard_events(whiteboard_id,seq,element_id,op,actor_id,at,element_version,data,prev_hash,hash)
            VALUES(:board,:seq,:element,:op,:actor,CAST(:at AS timestamptz),:version,CAST(:data AS jsonb),:prev,:hash)',
            [':board'=>$bid,':seq'=>$seq,':element'=>$e['id'],':op'=>$event['op'],':actor'=>(int)$user['id'],':at'=>$at,
            ':version'=>$e['version'],':data'=>WhiteboardSync::canonical($e),':prev'=>$event['prev_hash'],':hash'=>WhiteboardSync::eventHash($event)]);
        $db->run('INSERT INTO community.whiteboard_elements
            (whiteboard_id,element_id,type,version,version_nonce,is_deleted,data,created_by,created_seq,updated_by,updated_seq)
            VALUES(:board,:id,:type,:version,:nonce,CAST(:deleted AS boolean),CAST(:data AS jsonb),:by,:seq,:upby,:upseq)
            ON CONFLICT(whiteboard_id,element_id) DO UPDATE SET version=EXCLUDED.version,version_nonce=EXCLUDED.version_nonce,
              is_deleted=EXCLUDED.is_deleted,data=EXCLUDED.data,updated_by=EXCLUDED.updated_by,updated_seq=EXCLUDED.updated_seq,updated_at=now()',
            [':board'=>$bid,':id'=>$e['id'],':type'=>$e['type'],':version'=>$e['version'],':nonce'=>$e['versionNonce'],
             ':deleted'=>$e['isDeleted'] ? 'true' : 'false',':data'=>WhiteboardSync::canonical($e),
             ':by'=>(int)$user['id'],':seq'=>$seq,':upby'=>(int)$user['id'],':upseq'=>$seq]);
        $board['last_seq']=$seq;
        $db->run('UPDATE community.whiteboards SET last_seq=:seq WHERE id=:id',
            [':seq'=>$seq,':id'=>$bid]);
    }

    public static function push(array $user,string $id,array $body): array
    {
        $expected=WorkDocuments::id($body['whiteboard_id'] ?? null);
        $base=WhiteboardSync::integer($body['base_seq'] ?? null,0,PHP_INT_MAX);
        $elements=$body['elements'] ?? null;
        if (!is_array($elements) || !array_is_list($elements) || count($elements)>200 || $elements===[]) { Http::error(400,'한 번에 1~200개 요소를 보내 주세요.'); }
        return self::transaction($user,$id,$expected,static function(array $page,?array $board) use($user,$base,$elements): array {
            if ($board['state']!=='open') { Http::error(409,'확정한 판은 고칠 수 없습니다. 새 판에서 이어 그려 주세요.'); }
            if ($base>(int)$board['last_seq']) { Http::error(409,'그린 순번을 다시 확인해 주세요.'); }
            $db=Db::instance(); $bid=(int)$board['id']; $accepted=[]; $rejected=[];
            $rateSql="INSERT INTO community.whiteboard_presence(whiteboard_id,user_id,written_at) VALUES(:board,:user,clock_timestamp())
                ON CONFLICT(whiteboard_id,user_id) DO UPDATE SET written_at=EXCLUDED.written_at
                WHERE whiteboard_presence.written_at IS NULL OR whiteboard_presence.written_at<clock_timestamp()-interval '240 milliseconds'
                RETURNING user_id";
            if (!$db->run($rateSql,[':board'=>$bid,':user'=>(int)$user['id']])->fetchColumn()) { Http::error(429,'잠시 뒤 다시 저장해 주세요.'); }
            $sizeSql='SELECT count(*) AS count,COALESCE(sum(octet_length(data::text)),0) AS bytes FROM community.whiteboard_elements WHERE whiteboard_id=:id';
            $size=$db->run($sizeSql,[':id'=>$bid])->fetch();
            $count=(int)$size['count']; $sceneBytes=(int)$size['bytes'];
            foreach($elements as $input) {
                try { $e=WhiteboardSync::element($input); }
                catch(InvalidArgumentException $err) {
                    if ($err->getMessage()==='element_too_large') {
                        $db->rollback();
                        Audit::deny('whiteboard.change','refused',['target_type'=>'page','target_id'=>(int)$page['id'],'detail'=>['cause'=>'element_too_large']]);
                        Http::error(413,'요소 크기를 줄여 주세요.');
                    }
                    $rejected[]=['id'=>is_string($input['id'] ?? null) ? substr($input['id'],0,64) : '', 'reason'=>$err->getMessage()]; continue;
                }
                $old=$db->run('SELECT * FROM community.whiteboard_elements WHERE whiteboard_id=:board AND element_id=:id',
                    [':board'=>$bid,':id'=>$e['id']])->fetch();
                if ($old && ($old['type']!==$e['type'] || (int)$old['version']>$e['version']
                    || ((int)$old['version']===$e['version'] && (int)$old['version_nonce']<=$e['versionNonce']))) {
                    if ($old['type']===$e['type'] && (int)$old['version']===$e['version'] && (int)$old['version_nonce']===$e['versionNonce']
                        && WhiteboardSync::canonical(json_decode($old['data'],true))===WhiteboardSync::canonical($e)) {
                        $accepted[]=['id'=>$e['id'],'seq'=>(int)$old['updated_seq'],'element'=>json_decode($old['data'],true)];
                    } else { $rejected[]=['id'=>$e['id'],'reason'=>'conflict','element'=>json_decode($old['data'],true)]; }
                    continue;
                }
                if (!$old && $count>=WhiteboardSync::MAX_ELEMENTS) { $rejected[]=['id'=>$e['id'],'reason'=>'element_limit']; continue; }
                $nextBytes=max(0,$sceneBytes-($old ? strlen($old['data']) : 0))+strlen(WhiteboardSync::canonical($e))*2;
                if ($nextBytes>4194304) { $rejected[]=['id'=>$e['id'],'reason'=>'scene_limit']; continue; }
                self::append($board,$e,$user,$old ?: null); if (!$old) { $count++; }
                $sceneBytes=$nextBytes;
                $accepted[]=['id'=>$e['id'],'seq'=>(int)$board['last_seq'],'element'=>$e];
            }
            return ['accepted'=>$accepted,'rejected'=>$rejected,'last_seq'=>(int)$board['last_seq']];
        });
    }

    public static function events(array $user,string $id,?int $version,int $after,int $limit,bool $shapes=true): array
    {
        $page=self::page($user,$id); $board=self::board($page,$version);
        if ($board===null) { Http::error(404,Http::MSG_NOT_FOUND); }
        $statement=Db::instance()->run("SELECT e.seq,e.element_id,e.op,e.actor_id,COALESCE(u.display_name,u.username) AS actor_name,
            to_char(e.at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS at_utc,
            e.element_version,e.data,e.prev_hash,e.hash FROM community.whiteboard_events e JOIN public.users u ON u.id=e.actor_id
            WHERE e.whiteboard_id=:id AND e.seq>CAST(:after AS bigint) ORDER BY e.seq LIMIT CAST(:limit AS int)",
            [':id'=>(int)$board['id'],':after'=>$after,':limit'=>$limit+1]);
        $rows=[]; $bytes=0; $more=false;
        while($r=$statement->fetch()) {
            if (count($rows)>=$limit || ($rows!==[] && $bytes+strlen($r['data'])>1048576)) { $more=true; break; }
            $rows[]=$r; $bytes+=strlen($r['data']);
        }
        foreach($rows as &$r) {
            $r['seq']=(int)$r['seq']; $r['actor_id']=(int)$r['actor_id'];
            $r['data']=json_decode($r['data'],true,32,JSON_THROW_ON_ERROR);
            if (!$shapes) { $r['text']=iconv_substr((string)($r['data']['text'] ?? ''),0,160,'UTF-8'); $r['type']=$r['data']['type'] ?? null; unset($r['data']); }
        }
        unset($r);
        return ['events'=>$rows,'last_seq'=>(int)$board['last_seq'],'has_more'=>$more,
            'next_seq'=>$more ? $rows[count($rows)-1]['seq'] : null];
    }

    public static function changes(array $user,string $id,int $after): array
    {
        $page=self::page($user,$id); $board=self::board($page);
        if ($board===null) { Http::error(404,Http::MSG_NOT_FOUND); }
        $sql='SELECT data,updated_seq FROM community.whiteboard_elements WHERE whiteboard_id=:id AND updated_seq>CAST(:after AS bigint)
            ORDER BY updated_seq LIMIT 201';
        $rows=Db::instance()->run($sql,[':id'=>(int)$board['id'],':after'=>$after])->fetchAll();
        $more=count($rows)>200; $rows=array_slice($rows,0,200);
        return ['whiteboard_id'=>$board['public_id'],'state'=>$board['state'],'last_seq'=>(int)$board['last_seq'],
            'elements'=>array_map(static fn(array $r): array=>json_decode($r['data'],true,32,JSON_THROW_ON_ERROR),$rows),
            'has_more'=>$more,'next_seq'=>$more ? (int)$rows[count($rows)-1]['updated_seq'] : null];
    }

    public static function ticket(array $user,string $id,string $expected): array
    {
        $page=self::page($user,$id); $board=self::board($page);
        if ($board===null || $board['public_id']!==$expected) { Http::error(409,'판을 다시 열어 주세요.'); }
        $key=Env::getOrEmpty('WHITEBOARD_RELAY_SECRET');
        if (strlen($key)<32) { Http::error(503,'실시간 연결을 준비 중입니다. 그린 내용은 계속 저장됩니다.'); }
        $origin=rtrim(Env::getOrEmpty('APP_URL'),'/');
        $payload=['v'=>1,'room'=>$board['public_id'],'uid'=>(int)$user['id'],
            'name'=>(string)($user['display_name'] ?? $user['username']),'origin'=>$origin,'exp'=>time()+60,
            'nonce'=>bin2hex(random_bytes(16)),
            'draw'=>$board['state']==='open' && Authz::canCommunityWrite($user) && CommunityAuthz::decidePageChange($user,$page)[0]];
        $encoded=rtrim(strtr(base64_encode(WhiteboardSync::canonical($payload)),'+/','-_'),'=');
        return ['ticket'=>$encoded.'.'.hash_hmac('sha256',$encoded,$key),
            'url'=>COMMUNITY_BASE.'/whiteboard-relay','expires_in'=>60];
    }
}
