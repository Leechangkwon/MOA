<?php
declare(strict_types=1);

/** Immutable evidence plus bounded, optimistic and idempotent meeting-minute writes. */
final class WhiteboardSnapshot
{
    public static function confirm(array $user,string $id,string $expected,int $seq,array $png): array
    {
        $stored=null;
        try {
            Whiteboards::transaction($user,$id,$expected,static function(array $page,?array $board) use($user,$seq,$png,&$stored): void {
                if ($board['state']!=='open' || (int)$board['last_seq']!==$seq) { Http::error(409,'방금 누가 그렸어요. 최신 판을 확인한 뒤 다시 확정해 주세요.'); }
                [$checked,$sensitive]=WhiteboardAttachments::prepare($user,$page,$png);
                $elements=array_values(array_filter(Whiteboards::elements((int)$board['id']),static fn(array $e): bool=>!$e['isDeleted']));
                $scene=['type'=>'excalidraw','version'=>2,'source'=>'moa','elements'=>$elements];
                $sceneJson=WhiteboardSync::canonical($scene);
                $sceneFp=hash('sha256',$sceneJson);
                $stored=Storage::newStoredName($checked['ext']);
                $att=WhiteboardAttachments::store($user,$page,$checked,(string)$png['tmp_name'],
                    '회의 그림판 '.$board['version_no'].'.png',$sensitive,$stored);
                $db=Db::instance();
                $head=$db->run('SELECT hash FROM community.whiteboard_events WHERE whiteboard_id=:id ORDER BY seq DESC LIMIT 1',
                    [':id'=>(int)$board['id']])->fetchColumn() ?: WhiteboardSync::ZERO;
                $db->run('INSERT INTO community.whiteboard_snapshots
                    (whiteboard_id,scene,scene_sha256,png_attachment_id,png_sha256,element_count,last_seq,chain_head,locked_by)
                    VALUES(:id,CAST(:scene AS jsonb),:scene_fp,:png,:png_fp,:count,:seq,:head,:user)',
                    [':id'=>(int)$board['id'],':scene'=>$sceneJson,':scene_fp'=>$sceneFp,':png'=>(int)$att['id'],
                     ':png_fp'=>$checked['sha256'],':count'=>count($elements),':seq'=>$seq,':head'=>$head,':user'=>(int)$user['id']]);
                $db->run("UPDATE community.whiteboards SET state='locked',locked_by=:user,locked_at=now() WHERE id=:id",
                    [':user'=>(int)$user['id'],':id'=>(int)$board['id']]);
                Whiteboards::audit('confirm',$page,$board,['scene_fp'=>$sceneFp,'chain_tip'=>$head]);
            });
        } catch (Throwable $e) {
            if (is_string($stored)) { Storage::removeUploaded($stored,Storage::AREA_ATTACHMENTS); }
            throw $e;
        }
        return Whiteboards::read($user,$id);
    }

    public static function version(array $page,?int $version): ?int
    {
        if ($version!==null) { return $version; }
        $n=Db::instance()->run("SELECT version_no FROM community.whiteboards WHERE page_id=:id AND state='locked' ORDER BY version_no DESC LIMIT 1",
            [':id'=>(int)$page['id']])->fetchColumn();
        return $n===false ? null : (int)$n;
    }

    public static function read(array $user,string $id,?int $version,int $after=0): array
    {
        $page=Whiteboards::page($user,$id); $version=self::version($page,$version);
        $data=Whiteboards::read($user,$id,$version);
        if ($data['board']===null) { Http::error(404,Http::MSG_NOT_FOUND); }
        $graph=WhiteboardGraph::describe($data['elements']);
        $board=Whiteboards::board($page,$version);
        $authors=Db::instance()->run('SELECT e.element_id,e.created_by,e.created_seq,e.updated_by,e.updated_seq,
            COALESCE(c.display_name,c.username) AS created_name,COALESCE(u.display_name,u.username) AS updated_name
            FROM community.whiteboard_elements e JOIN public.users c ON c.id=e.created_by JOIN public.users u ON u.id=e.updated_by
            WHERE e.whiteboard_id=:id ORDER BY e.created_seq',
            [':id'=>(int)$board['id']])->fetchAll();
        $map=[]; foreach($authors as $r) { $map[$r['element_id']]=$r; }
        $nodes=array_slice($graph['nodes'],$after,100);
        foreach($nodes as &$node) { $node['author']=$map[$node['id']] ?? null; } unset($node);
        return ['board'=>$data['board'],'page_id'=>$id,'title'=>$page['title'],'doc_version'=>(int)$page['doc_version'],
            'kind'=>'meeting','nodes'=>$nodes,'connections'=>$graph['connections'],'frames'=>$graph['frames'],
            'summary'=>iconv_substr($graph['summary'],0,32000,'UTF-8'),'mermaid'=>iconv_substr($graph['mermaid'],0,32000,'UTF-8'),
            'next'=>$after+100<count($graph['nodes']) ? $after+100 : null,
            'participants'=>Db::instance()->run('SELECT e.actor_id,COALESCE(u.display_name,u.username) AS name,count(*) AS events
                FROM community.whiteboard_events e JOIN public.users u ON u.id=e.actor_id WHERE e.whiteboard_id=:id
                GROUP BY e.actor_id,u.display_name,u.username ORDER BY e.actor_id',
            [':id'=>(int)$board['id']])->fetchAll()];
    }

    public static function listing(array $user,array $args): array
    {
        $limit=WhiteboardSync::integer($args['limit'] ?? 30,1,100);
        $after=isset($args['after_id']) ? WorkDocuments::id($args['after_id']) : WhiteboardSync::ZERO;
        if ($after===WhiteboardSync::ZERO) { $after='00000000-0000-0000-0000-000000000000'; }
        if (isset($args['kind']) && $args['kind']!=='meeting') { Http::error(400,'판 유형을 확인해 주세요.'); }
        [$where,$bind]=community_search_pages_where($user);
        $extra='';
        if (isset($args['project_id'])) { $extra=' AND project.public_id=CAST(:project AS uuid)'; $bind[':project']=WorkDocuments::id($args['project_id']); }
        $sql="SELECT p.public_id AS page_id,p.title,project.public_id AS project_id,b.public_id AS id,b.kind,b.version_no,b.state,b.locked_at,
            (SELECT count(*) FROM community.whiteboard_elements e WHERE e.whiteboard_id=b.id AND NOT e.is_deleted) AS element_count
            FROM community.whiteboards b JOIN community.pages p ON p.id=b.page_id
            JOIN community.databases d ON d.id=p.database_id AND d.purpose='project'
            JOIN community.pages project ON project.id=d.page_id JOIN community.spaces s ON s.id=p.space_id
            LEFT JOIN public.users owner ON owner.id=s.owner_user_id
            WHERE p.deleted_at IS NULL AND p.visibility<>'published' AND s.is_active AND s.kind IN ('personal','team')
              AND NOT EXISTS(SELECT 1 FROM community.whiteboards newer WHERE newer.page_id=b.page_id AND newer.version_no>b.version_no)
              AND p.public_id>CAST(:after AS uuid) AND ({$where}){$extra}
            ORDER BY p.public_id LIMIT CAST(:limit AS int)";
        $rows=Db::instance()->run($sql,$bind+[':after'=>$after,':limit'=>$limit+1])->fetchAll();
        $more=count($rows)>$limit; $rows=array_slice($rows,0,$limit);
        return ['items'=>$rows,'next_after_id'=>$more ? $rows[count($rows)-1]['page_id'] : null];
    }

    public static function image(array $user,string $id,?int $version): array
    {
        $page=Whiteboards::page($user,$id); $board=Whiteboards::board($page,self::version($page,$version));
        if ($board===null || $board['state']!=='locked') { Http::error(409,'먼저 판을 확정해 주세요.'); }
        $att=Db::instance()->run('SELECT a.* FROM community.whiteboard_snapshots s JOIN community.page_attachments a ON a.id=s.png_attachment_id
            WHERE s.whiteboard_id=:id',
            [':id'=>(int)$board['id']])->fetch();
        if (!$att) { Http::error(404,Http::MSG_NOT_FOUND); }
        [, $att]=community_attachment_gate($user,$id,(string)$att['public_id'],'raw');
        $path=Storage::pathFor((string)$att['stored_name'],Storage::AREA_ATTACHMENTS);
        $size=@getimagesize($path);
        if (!$size || $size[0]*$size[1]>16000000 || $size[0]>16000 || $size[1]>16000) { Http::error(422,'그림 크기를 확인해 주세요.'); }
        $memoryLimit=trim((string)ini_get('memory_limit'));
        if (preg_match('/^(\d+)([KMG]?)$/iD',$memoryLimit,$parts)) {
            $max=(int)$parts[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($parts[2])]));
            if (memory_get_usage(true)+$size[0]*$size[1]*6+16777216>$max) { Http::error(422,'그림이 너무 커서 미리보기를 만들 수 없습니다.'); }
        }
        $source=@imagecreatefrompng($path);
        if (!$source) { Http::error(422,'그림을 읽을 수 없습니다.'); }
        $scale=min(1,1280/max($size[0],$size[1])); $w=max(1,(int)floor($size[0]*$scale)); $h=max(1,(int)floor($size[1]*$scale));
        $target=imagecreatetruecolor($w,$h); $bytes=null;
        try {
            imagefill($target,0,0,imagecolorallocate($target,255,255,255));
            imagecopyresampled($target,$source,0,0,0,0,$w,$h,$size[0],$size[1]);
            foreach([82,65,45] as $quality) {
                ob_start(); try { imagejpeg($target,null,$quality); $candidate=ob_get_contents(); } finally { ob_end_clean(); }
                if (is_string($candidate) && strlen($candidate)<=786432) { $bytes=$candidate; break; }
            }
        } finally { imagedestroy($source); imagedestroy($target); }
        if (!is_string($bytes)) { Http::error(422,'그림 미리보기 크기를 줄여 주세요.'); }
        return ['mime_type'=>'image/jpeg','data'=>base64_encode($bytes),'width'=>$w,'height'=>$h,'reference_only'=>true,
            'version_no'=>(int)$board['version_no'],'original_sha256'=>$att['sha256']];
    }

    private static function blockId(string $page,string $suffix): string
    {
        $h=hash('sha256','whiteboard-minutes:'.$page.':'.$suffix);
        return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);
    }

    private static function block(string $page,string $key,string $type,string $text,array $more=[]): array
    {
        return ['id'=>self::blockId($page,$key),'type'=>$type,'text'=>[['t'=>$text,'m'=>[]]]]+$more;
    }

    public static function minutes(array $user,string $id,array $body): array
    {
        $version=WhiteboardSync::integer($body['whiteboard_version'] ?? null,1);
        $docVersion=WhiteboardSync::integer($body['doc_version'] ?? null,1);
        $request=WorkDocuments::id($body['request_id'] ?? null); $minutes=$body['minutes'] ?? null;
        if (!is_array($minutes) || array_is_list($minutes) || array_diff(array_keys($minutes),['summary','agenda','discussion','decisions','actions','next_meeting'])) {
            Http::error(400,'회의록은 요약·안건·논의·결정·할 일·다음 회의로 보내 주세요.');
        }
        $fingerprint=hash('sha256',WhiteboardSync::canonical(['page'=>$id,'version'=>$version,'minutes'=>$minutes]));
        return Whiteboards::transaction($user,$id,null,static function(array $page,?array $latest) use($user,$id,$version,$docVersion,$request,$minutes,$fingerprint): array {
            $db=Db::instance(); $board=Whiteboards::board($page,$version,true);
            if ($board===null || $board['state']!=='locked') { Http::error(409,'확정한 판에만 회의록을 저장할 수 있습니다.'); }
            $prior=$db->run('SELECT * FROM community.whiteboard_minutes_requests WHERE request_id=CAST(:id AS uuid)',
            [':id'=>$request])->fetch();
            if ($prior) {
                if ((int)$prior['actor_id']!==(int)$user['id'] || (int)$prior['whiteboard_id']!==(int)$board['id']
                    || !hash_equals($prior['body_sha256'],$fingerprint)) { Http::error(409,'이미 사용한 요청 번호입니다.'); }
                return ['doc_version'=>(int)$prior['doc_version'],'saved'=>false];
            }
            if ((int)$page['doc_version']!==$docVersion) { Http::error(409,'문서가 바뀌었습니다. 다시 읽고 저장해 주세요.'); }
            $refs=$db->run('SELECT e.seq,COALESCE(u.display_name,u.username) AS name FROM community.whiteboard_events e
                JOIN public.users u ON u.id=e.actor_id WHERE e.whiteboard_id=:id',
            [':id'=>(int)$board['id']])->fetchAll();
            $names=[]; foreach($refs as $r) { $names[(int)$r['seq']]=$r['name']; }
            $blocks=[self::block($id,'credit','paragraph','Claude가 씀 · 저장한 사람 '.($user['display_name'] ?? $user['username']).' · 판 '.$version.' · '.gmdate('Y-m-d H:i:s').' UTC')];
            $count=0;
            foreach(['summary'=>'회의 정보','agenda'=>'안건','discussion'=>'논의 내용','decisions'=>'결정 사항','actions'=>'할 일','next_meeting'=>'다음 회의'] as $key=>$label) {
                $items=$minutes[$key] ?? [];
                if (is_string($items)) { $items=[['text'=>$items,'refs'=>[]]]; }
                if (!is_array($items) || !array_is_list($items) || count($items)>30) { Http::error(400,'회의록 항목 수를 줄여 주세요.'); }
                $blocks[]=self::block($id,$key.'-heading','heading',$label,['level'=>2]);
                foreach($items as $n=>$item) {
                    if (!is_array($item) || array_diff(array_keys($item),['text','refs','assignee','due']) || !is_string($item['text'] ?? null)
                        || iconv_strlen($item['text'],'UTF-8')>2000 || preg_match('//u',$item['text'])!==1
                        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$item['text'])
                        || !is_array($item['refs'] ?? []) || !array_is_list($item['refs'] ?? []) || count($item['refs'] ?? [])>50) { Http::error(400,'회의록은 평문과 근거 순번만 넣어 주세요.'); }
                    if (++$count>70) { Http::error(400,'회의록을 70개 항목 이내로 줄여 주세요.'); }
                    $citations=[];
                    foreach($item['refs'] ?? [] as $ref) {
                        if (!is_int($ref) || !isset($names[$ref])) { Http::error(400,'이 판에 없는 근거 순번입니다.'); }
                        $citations[]='#'.$ref.' '.$names[$ref];
                    }
                    $text=$item['text'].($citations===[] ? '' : ' ('.implode(', ',$citations).')');
                    foreach(['assignee'=>'담당','due'=>'기한'] as $field=>$prefix) {
                        if (isset($item[$field])) {
                            if (!is_string($item[$field]) || iconv_strlen($item[$field],'UTF-8')>100 || preg_match('/\p{C}/u',$item[$field])) { Http::error(400,'담당·기한을 확인해 주세요.'); }
                            $text.=' · '.$prefix.' '.$item[$field];
                        }
                    }
                    $blocks[]=self::block($id,$key.'-'.$n,$key==='actions' ? 'to_do' : 'paragraph',$text,$key==='actions' ? ['checked'=>false] : []);
                }
            }
            $snapshot=$db->run('SELECT s.*,a.public_id AS png_id FROM community.whiteboard_snapshots s JOIN community.page_attachments a ON a.id=s.png_attachment_id
                WHERE s.whiteboard_id=:id',
            [':id'=>(int)$board['id']])->fetch();
            $scene=json_decode($snapshot['scene'],true); $graph=WhiteboardGraph::describe($scene['elements']);
            $blocks[]=self::block($id,'evidence-heading','heading','회의 그림판 근거',['level'=>2]);
            $blocks[]=['id'=>self::blockId($id,'evidence-image'),'type'=>'image','width'=>'normal','file_ref'=>'att:'.$snapshot['png_id']];
            $blocks[]=self::block($id,'evidence-fp','paragraph','판 '.$version.' · 원본 지문 '.$snapshot['scene_sha256'].' · 기록 지문 '.$snapshot['chain_head']);
            $blocks[]=self::block($id,'evidence-summary','paragraph',iconv_substr($graph['summary'],0,2000,'UTF-8') ?: '글이 없는 그림판');
            $blocks[]=['id'=>self::blockId($id,'evidence-mermaid'),'type'=>'code','language'=>'mermaid','code'=>iconv_substr($graph['mermaid'],0,8000,'UTF-8')];
            $doc=json_decode($page['doc'],true,64,JSON_THROW_ON_ERROR);
            // Only deterministic IDs in the two managed sections are replaced. Human blocks retain IDs and content.
            $managed=[];
            foreach(['credit','evidence-heading','evidence-image','evidence-fp','evidence-summary','evidence-mermaid'] as $key) { $managed[self::blockId($id,$key)]=true; }
            foreach(array_keys($minutes+['summary'=>[],'agenda'=>[],'discussion'=>[],'decisions'=>[],'actions'=>[],'next_meeting'=>[]]) as $key) {
                $managed[self::blockId($id,$key.'-heading')]=true;
                for($i=0;$i<30;$i++) { $managed[self::blockId($id,$key.'-'.$i)]=true; }
            }
            $doc['blocks']=array_values(array_filter($doc['blocks'],static fn(array $b): bool=>!isset($managed[$b['id']])));
            array_push($doc['blocks'],...$blocks);
            if (!Doc::validate($doc,COMMUNITY_DOC_STAGE)['ok']) { Http::error(400,'문서 길이 또는 회의록 형식을 확인해 주세요.'); }
            $result=community_save_doc($user,$id,['doc_version'=>$docVersion,'doc'=>$doc],['force_snapshot'=>true]);
            $db->run('INSERT INTO community.whiteboard_minutes_requests(request_id,whiteboard_id,actor_id,body_sha256,doc_version)
                VALUES(CAST(:request AS uuid),:board,:user,:fp,:version)',
                [':request'=>$request,':board'=>(int)$board['id'],':user'=>(int)$user['id'],':fp'=>$fingerprint,':version'=>$result['doc_version']]);
            Whiteboards::audit('minutes.save',$page,$board);
            return ['doc_version'=>$result['doc_version'],'saved'=>true];
        });
    }
}
