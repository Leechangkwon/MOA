<?php
declare(strict_types=1);
$db=Db::instance();$me=(int)$user['id'];$method=$_SERVER['REQUEST_METHOD'];
$id=isset($params[0])?(int)$params[0]:null;
if($method==='GET' && $id===null) {
    Http::json(['collections'=>$db->run('SELECT id,name,parent_id,position FROM community.collections WHERE owner_user_id=:u ORDER BY position,id',
        [':u'=>$me])->fetchAll()]);return;
}
$db->begin();
try {
    // One owner's collection limits and hierarchy edits serialize together.
    $db->run('SELECT id FROM public.users WHERE id=:id FOR UPDATE',
        [':id'=>$me]);
    $r=$id===null?null:$db->run('SELECT * FROM community.collections WHERE id=:id AND owner_user_id=:u FOR UPDATE',
        [':id'=>$id,':u'=>$me])->fetch();
    if($id!==null && !is_array($r)) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
    if($method==='GET') {
        [$where,$bind]=community_visible_pages_where($user);
        $sql=<<<SQL
        SELECT p.public_id AS id,p.title,ci.position FROM community.collection_items ci
        JOIN community.pages p ON p.id=ci.page_id JOIN community.spaces s ON s.id=p.space_id
        LEFT JOIN public.users owner ON owner.id=s.owner_user_id
        WHERE ci.collection_id=:collection AND p.deleted_at IS NULL
          AND {$where}
        ORDER BY ci.position,p.id
        SQL;
        $rows=$db->run($sql,$bind+[':collection'=>$id])->fetchAll();
        $db->commit();Http::json(['collection'=>['id'=>$id,'name'=>$r['name']],'items'=>$rows]);return;
    }
    if(isset($params[1])) {
        $p=community_page_row($params[1],$me);
        if($p===null || !CommunityAuthz::decidePageRead($user,$p)[0]) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
        if($method==='PUT') {
            $n=(int)$db->run('SELECT count(*) FROM community.collection_items WHERE collection_id=:id',
        [':id'=>$id])->fetchColumn();
            $has=$db->run('SELECT 1 FROM community.collection_items WHERE collection_id=:id AND page_id=:p',
        [':id'=>$id,':p'=>(int)$p['id']])->fetchColumn();
            if(!$has && $n>=500) { throw new InvalidArgumentException('collection_limit'); }
            $db->run('INSERT INTO community.collection_items(collection_id,page_id,position) VALUES(:id,:page,:position) ON CONFLICT DO NOTHING',
        [':id'=>$id,':page'=>(int)$p['id'],':position'=>$n]);
        } else { $db->run('DELETE FROM community.collection_items WHERE collection_id=:id AND page_id=:page',
        [':id'=>$id,':page'=>(int)$p['id']]); }
    } elseif($method==='DELETE') {
        $db->run('DELETE FROM community.collections WHERE id=:id AND owner_user_id=:u',
        [':id'=>$id,':u'=>$me]);
    } else {
        $b=community_integration_body(['name','parent_id','position']);$name=community_integration_name($b['name']??($r['name']??null));
        $parent=$b['parent_id']??null;$position=$b['position']??0;
        if(($parent!==null && (!is_int($parent)||$parent<1)) || !is_int($position)||abs($position)>100000) { throw new InvalidArgumentException('bad_value'); }
        $all=$db->run('SELECT id,parent_id FROM community.collections WHERE owner_user_id=:u',
        [':u'=>$me])->fetchAll();$parents=[];
        foreach($all as $a) { $parents[(int)$a['id']]=$a['parent_id']===null?null:(int)$a['parent_id']; }
        if($id===null && count($all)>=50) { throw new InvalidArgumentException('collection_limit'); }
        $testId=$id??0;$parents[$testId]=$parent;
        foreach(array_keys($parents) as $start) {
            $seen=[];$node=$start;$depth=0;
            while($node!==null) {
                if(isset($seen[$node]) || !array_key_exists($node,$parents) || ++$depth>3) { throw new InvalidArgumentException('collection_depth'); }
                $seen[$node]=true;$node=$parents[$node];
            }
        }
        if($id===null) { $id=(int)$db->run('INSERT INTO community.collections(owner_user_id,name,parent_id,position) VALUES(:u,:name,:parent,:position) RETURNING id',
        [':u'=>$me,':name'=>$name,':parent'=>$parent,':position'=>$position])->fetchColumn(); }
        else { $db->run('UPDATE community.collections SET name=:name,parent_id=:parent,position=:position WHERE id=:id',
        [':name'=>$name,':parent'=>$parent,':position'=>$position,':id'=>$id]); }
    }
    $db->commit();Http::json(['ok'=>true,'id'=>$id]);
} catch(InvalidArgumentException $e) { $db->rollback();Http::json(['error'=>'모음의 이름·깊이·개수 한도를 확인해 주세요','reason'=>$e->getMessage()],400); }
catch(Throwable $e) { $db->rollback();throw $e; }
