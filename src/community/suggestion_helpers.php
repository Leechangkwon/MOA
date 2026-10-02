<?php
declare(strict_types=1);

/** C:F-46 helpers. All writes use the request actor, prepared SQL and page-first locks. */
function community_suggestion_fail(string $reason, int $status = 400, array $extra = []): never
{
    Db::instance()->rollback();
    $messages = [
        'can_edit'=>'고칠 수 있는 페이지는 직접 편집해 주세요.',
        'board_inactive'=>'보관 중인 게시판에는 제안할 수 없습니다.',
        'suggestion_block_not_allowed'=>'제안에는 글 블록만 넣을 수 있고 할 일의 체크 상태는 바꿀 수 없습니다.',
        'suggestion_stale'=>'그 사이 이 블록이 바뀌었습니다. 새로 불러와 다시 제안해 주세요.',
        'already_decided'=>'이미 처리한 제안입니다.',
        'suggestion_limit'=>'열린 제안 수가 한도에 도달했습니다.',
        'version_conflict'=>'다른 곳에서 먼저 저장되었습니다. 다시 불러와 주세요.',
        'suggestion_too_large'=>'제안 내용이 너무 큽니다.',
    ];
    $message = $status === 404 ? Http::MSG_NOT_FOUND : ($status === 403 ? Http::MSG_FORBIDDEN : ($messages[$reason] ?? Http::MSG_BAD_REQUEST));
    Http::json(array_merge(['error'=>$message, 'reason'=>$reason], $extra), $status);
    exit;
}

function community_suggestion_body(array $allowed): array
{
    $body = Http::jsonBody(Suggestion::MAX_BYTES + 65536, 2 * (Doc::limit('depth') + 1) + 10);
    if (array_diff(array_keys($body), $allowed) !== []) { community_suggestion_fail('invalid_request'); }
    return $body;
}

function community_suggestion_note(mixed $note): ?string
{
    if ($note === null) { return null; }
    if (!is_string($note) || preg_match('//u', $note) !== 1 || iconv_strlen($note, 'UTF-8') > 500
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note) === 1) {
        community_suggestion_fail('invalid_note');
    }
    return trim($note) === '' ? null : trim($note);
}

function community_suggestion_page(string $publicId, array $user, bool $lock = false): array
{
    $row = community_page_row($publicId, (int) $user['id']);
    if ($row !== null && $lock) {
        Db::instance()->run(COMMUNITY_PAGE_LOCK_SQL, [':pid'=>(int) $row['id']]);
        $row = community_page_row($publicId, (int) $user['id']);
    }
    if ($row === null || !CommunityAuthz::decidePageRead($user, $row)[0]) {
        community_suggestion_fail('not_found', 404);
    }
    return $row;
}

function community_suggestion_row(string $id): ?array
{
    $sql = <<<'SQL'
        SELECT s.*, p.public_id AS page_public_id,
               a.username AS author_name, a.display_name AS author_display,
               d.username AS decided_name, d.display_name AS decided_display
          FROM community.page_suggestions s
          JOIN community.pages p ON p.id=s.page_id
          JOIN public.users a ON a.id=s.author_id
          LEFT JOIN public.users d ON d.id=s.decided_by
         WHERE s.public_id=CAST(:id AS uuid)
        SQL;
    $row = Db::instance()->run($sql, [':id'=>$id])->fetch();
    return is_array($row) ? $row : null;
}

/** Page first, then proposal: the same ordering as document writes, handover and purge. */
function community_suggestion_locked(string $id, array $user): array
{
    $s = community_suggestion_row($id);
    if ($s === null) { community_suggestion_fail('not_found', 404); }
    $page = community_suggestion_page((string) $s['page_public_id'], $user, true);
    $lockSql='SELECT id FROM community.page_suggestions WHERE id=:id FOR UPDATE';
    Db::instance()->run($lockSql, [':id'=>(int) $s['id']]);
    $s = community_suggestion_row($id);
    if ($s === null) { community_suggestion_fail('not_found', 404); }
    return [$page, $s];
}

function community_suggestion_audit(string $action, array $page, string $id, ?int $version = null): void
{
    $detail = ['suggestion_id'=>$id];
    if ($version !== null) { $detail['version']=$version; }
    Audit::allow($action, ['target_type'=>'page', 'target_id'=>(int) $page['id'],
        'target_name'=>community_target_title((string) $page['title']), 'detail'=>$detail]);
}

function community_suggestion_summary(array $s, ?array $page, array $user): array
{
    $visible = $page !== null && CommunityAuthz::decidePageRead($user, $page)[0];
    $summary = [
        'id'=>(string) $s['public_id'], 'status'=>(string) $s['status'], 'at'=>(string) $s['created_at'],
        'page'=>$visible ? ['id'=>(string) $page['public_id'], 'title'=>(string) $page['title']] : null,
        'page_title'=>$visible ? (string) $page['title'] : '볼 수 없는 페이지',
        'author'=>['username'=>(string) $s['author_name'], 'display_name'=>$s['author_display']],
        'decided_by'=>$s['decided_name'] === null ? null : ['username'=>(string) $s['decided_name'], 'display_name'=>$s['decided_display']],
        'decided_at'=>$s['decided_at'], 'decision_note'=>$visible ? $s['decision_note'] : null,
        'note'=>$visible ? $s['note'] : null,
        'applied_version'=>$s['applied_version'] === null ? null : (int) $s['applied_version'],
        'can_view'=>$visible && CommunityAuthz::decideSuggestionView($user, $page, $s)[0],
        'can_withdraw'=>$visible && $s['status'] === 'open' && (int) $s['author_id'] === (int) $user['id'],
    ];
    if ($page !== null && function_exists('community_form_identity_hidden')
        && community_form_identity_hidden($user, $page)) {
        $summary = community_form_redact_metadata($summary);
    }
    return $summary;
}

function community_suggestions_create(array $user, string $pid): void
{
    $body = community_suggestion_body(['base_version','ops','note']);
    if (!is_int($body['base_version'] ?? null) || !array_key_exists('ops', $body)) { community_suggestion_fail('invalid_request'); }
    $note = community_suggestion_note($body['note'] ?? null);
    $db = Db::instance(); $db->begin();
    try {
        // Global author quota across different pages must also be serialized.
        $authorLockSql='SELECT pg_advisory_xact_lock(1046, CAST(:uid AS integer))';
        $db->run($authorLockSql, [':uid'=>(int) $user['id']]);
        $page = community_suggestion_page($pid, $user, true);
        [$ok,$reason] = CommunityAuthz::decideSuggestionCreate($user,$page);
        if (!$ok) { community_suggestion_fail($reason, in_array($reason,['can_edit','board_inactive'],true) ? 400 : 403); }
        if ($body['base_version'] !== (int) $page['doc_version']) { community_suggestion_fail('version_conflict',409,['current_version'=>(int)$page['doc_version']]); }
        $sql = <<<'SQL'
            SELECT count(*) FILTER (WHERE page_id=:page) AS page_count,
                   count(*) FILTER (WHERE page_id=:own_page AND author_id=:own_author) AS own_page_count,
                   count(*) FILTER (WHERE author_id=:author) AS author_count
              FROM community.page_suggestions WHERE status='open' AND (page_id=:target OR author_id=:who)
            SQL;
        $limits=$db->run($sql,[':page'=>$page['id'],':own_page'=>$page['id'],':own_author'=>$user['id'],':author'=>$user['id'],':target'=>$page['id'],':who'=>$user['id']])->fetch();
        if ((int)$limits['page_count']>=50 || (int)$limits['own_page_count']>=5 || (int)$limits['author_count']>=100) { community_suggestion_fail('suggestion_limit'); }
        $prepared=Suggestion::prepare(json_decode((string)$page['doc'],true),$body['ops'],COMMUNITY_DOC_STAGE);
        if ($prepared['reason']!==null) { community_suggestion_fail($prepared['reason'],400,['errors'=>$prepared['errors']]); }
        $ops=json_encode($prepared['ops'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $base=json_encode((object)$prepared['base_blocks'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $sizeSql='SELECT octet_length(CAST(:ops AS jsonb)::text)+octet_length(CAST(:base AS jsonb)::text)';
        $bytes=$db->run($sizeSql, [':ops'=>$ops,':base'=>$base])->fetchColumn();
        if ((int)$bytes>Suggestion::MAX_BYTES) { community_suggestion_fail('suggestion_too_large'); }
        $id=Doc::uuid4();
        $sql = <<<'SQL'
            INSERT INTO community.page_suggestions(public_id,page_id,author_id,base_version,ops,base_blocks,note)
            VALUES(CAST(:id AS uuid),:page,:author,:version,CAST(:ops AS jsonb),CAST(:base AS jsonb),:note)
            SQL;
        $db->run($sql,[':id'=>$id,':page'=>$page['id'],':author'=>$user['id'],':version'=>$body['base_version'],':ops'=>$ops,':base'=>$base,':note'=>$note]);
        $recipient=$page['space_kind']==='personal' ? $page['space_owner_user_id'] : $page['author_id'];
        community_notify((int)$recipient,'suggestion_new',$page,null,(int)$user['id']);
        community_suggestion_audit('suggestion.create',$page,$id);
        $db->commit();
        Http::json(['id'=>$id],201);
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}

function community_suggestions_list(array $user, ?string $pid): void
{
    $page=$pid===null ? null : community_suggestion_page($pid,$user);
    $editor=$page!==null && CommunityAuthz::decideSuggestionDecide($user,$page)[0];
    $status=Http::queryText('status',16);
    if ($status!=='' && !in_array($status,['open','accepted','rejected','stale','withdrawn'],true)) { community_suggestion_fail('invalid_status'); }
    $after=Http::queryText('after',20);
    if ($after!=='' && preg_match('/^[0-9]{1,18}$/',$after)!==1) { community_suggestion_fail('invalid_cursor'); }
    $sql=<<<'SQL'
        SELECT s.*, p.public_id AS page_public_id,
               a.username AS author_name,a.display_name AS author_display,
               d.username AS decided_name,d.display_name AS decided_display
          FROM community.page_suggestions s
          JOIN community.pages p ON p.id=s.page_id
          JOIN public.users a ON a.id=s.author_id
          LEFT JOIN public.users d ON d.id=s.decided_by
         WHERE (:all_pages=1 OR s.page_id=:page)
           AND (:editor=1 OR s.author_id=:author)
           AND (:all_status=1 OR s.status=:status)
           AND (:first=1 OR s.id<CAST(:after AS bigint))
         ORDER BY s.id DESC LIMIT 100
        SQL;
    $rows=Db::instance()->run($sql,[':all_pages'=>$pid===null?1:0,':page'=>$page['id']??0,':editor'=>$editor?1:0,
        ':author'=>$user['id'],':all_status'=>$status===''?1:0,':status'=>$status,':first'=>$after===''?1:0,':after'=>$after===''?0:$after])->fetchAll();
    $items=[];
    foreach($rows as $s) {
        $row=$page ?? community_page_row((string)$s['page_public_id'],(int)$user['id']);
        $items[]=community_suggestion_summary($s,$row,$user);
    }
    Http::json(['suggestions'=>$items,'next_after'=>count($rows)===100?(string)$rows[count($rows)-1]['id']:null]);
}

function community_suggestion_get(array $user,string $id): void
{
    $s=community_suggestion_row($id);
    if($s===null) { community_suggestion_fail('not_found',404); }
    $page=community_suggestion_page((string)$s['page_public_id'],$user);
    if(!CommunityAuthz::decideSuggestionView($user,$page,$s)[0]) { community_suggestion_fail('not_found',404); }
    $doc=json_decode((string)$page['doc'],true);$blocks=Suggestion::blocks($doc);
    $base=json_decode((string)$s['base_blocks'],true);$ops=json_decode((string)$s['ops'],true);
    $stale=Suggestion::stale($doc,$base);$compare=[];$render=[];
    foreach($ops as $op) {
        $before=$op['op']==='insert_after'?null:($blocks[$op['block_id']]??$base[$op['block_id']]??null);
        $after=$op['op']==='delete'?null:$op['block'];
        $compare[]=['op'=>$op['op'],'before'=>$before,'after'=>$after];
        if($before!==null) {$render[]=$before;} if($after!==null) {$render[]=$after;}
    }
    $extract=Doc::extract(['schema'=>1,'blocks'=>$render]);
    $links=array_values(array_unique(array_column($extract['links'],'to_page_id')));
    $children=[];
    foreach (array_slice($extract['child_pages'],0,200) as $childId) {
        $child=community_page_row($childId,(int)$user['id']);
        if ($child !== null && (int)$child['space_id']===(int)$page['space_id'] && CommunityAuthz::decidePageRead($user,$child)[0]) {
            $children[$childId]=['title'=>(string)$child['title'],'icon'=>$child['icon']];
        }
    }
    $summary=community_suggestion_summary($s,$page,$user);
    Http::json(['suggestion'=>$summary,'doc_version'=>(int)$page['doc_version'],'stale'=>$stale,'comparison'=>$compare,
        'can_accept'=>$s['status']==='open'&&!$stale&&CommunityAuthz::decideSuggestionDecide($user,$page)[0],
        'can_reject'=>$s['status']==='open'&&CommunityAuthz::decideSuggestionDecide($user,$page)[0],
        'file_titles'=>community_file_titles($extract['files'],$user),'link_titles'=>community_link_titles($links,$user),
        'mention_labels'=>community_mention_labels($extract['mentions'],$user),'child_titles'=>$children===[]?new stdClass():$children]);
}

function community_suggestion_decide(array $user,string $id,string $decision): void
{
    $body=community_suggestion_body($decision==='accepted'?['doc_version']:($decision==='rejected'?['note']:[]));
    $note=$decision==='rejected'?community_suggestion_note($body['note']??null):null;
    if($decision==='accepted'&&!is_int($body['doc_version']??null)) { community_suggestion_fail('invalid_request'); }
    $db=Db::instance();$db->begin();
    try {
        [$page,$s]=community_suggestion_locked($id,$user);
        if($decision==='withdrawn') {
            if((int)$s['author_id']!==(int)$user['id']) { community_suggestion_fail('not_found',404); }
        } elseif(!CommunityAuthz::decideSuggestionDecide($user,$page)[0]) { community_suggestion_fail('forbidden',403); }
        if($s['status']!=='open') { community_suggestion_fail('already_decided'); }
        $version=null;$saved=null;
        if($decision==='accepted') {
            if($body['doc_version']!==(int)$page['doc_version']) { community_suggestion_fail('version_conflict',409,['current_version'=>(int)$page['doc_version']]); }
            $doc=json_decode((string)$page['doc'],true);$base=json_decode((string)$s['base_blocks'],true);
            if(Suggestion::stale($doc,$base)) {$decision='stale';}
            else {
                try {$result=Suggestion::apply($doc,json_decode((string)$s['ops'],true));}
                catch(InvalidArgumentException $e) {community_suggestion_fail('invalid_ops');}
                $saved=community_save_doc($user,(string)$page['public_id'],['doc_version'=>$body['doc_version'],'doc'=>$result],['force_snapshot'=>true,'suggestion_id'=>$id]);
                $version=$saved['doc_version'];
            }
        }
        $sql=<<<'SQL'
            UPDATE community.page_suggestions SET status=:status,decided_by=:actor,decided_at=now(),decision_note=:note,applied_version=:version
             WHERE id=:id AND status='open'
            SQL;
        $db->run($sql,[':status'=>$decision,':actor'=>$user['id'],':note'=>$note,':version'=>$version,':id'=>$s['id']]);
        $action=['accepted'=>'suggestion.accept','rejected'=>'suggestion.reject','stale'=>'suggestion.stale','withdrawn'=>'suggestion.withdraw'][$decision];
        community_suggestion_audit($action,$page,$id,$version);
        if($decision!=='withdrawn') {community_notify((int)$s['author_id'],'suggestion_'.$decision,$page,null,(int)$user['id']);}
        $db->commit();
        if($decision==='stale') {community_suggestion_fail('suggestion_stale',409);}
        Http::json(array_merge(['id'=>$id,'status'=>$decision],$saved??[]));
    } catch(Throwable $e) {$db->rollback();throw $e;}
}

/** Immutable attribution, filtered by the same published-history cutoff as versions/history. */
function community_suggestion_versions(int $pageId,?string $since): array
{
    $sql=<<<'SQL'
        SELECT s.applied_version,s.public_id,s.decided_at,
               a.username AS author_name,a.display_name AS author_display,
               d.username AS decided_name,d.display_name AS decided_display
          FROM community.page_suggestions s
          JOIN public.users a ON a.id=s.author_id
          JOIN public.users d ON d.id=s.decided_by
         WHERE s.page_id=:page AND s.status='accepted'
           AND s.decided_at>=coalesce(CAST(:since AS timestamptz),'-infinity'::timestamptz)
         ORDER BY s.applied_version DESC LIMIT 100
        SQL;
    $out=[];
    foreach(Db::instance()->run($sql,[':page'=>$pageId,':since'=>$since])->fetchAll() as $s) {
        $out[(int)$s['applied_version']]=['id'=>(string)$s['public_id'],'version'=>(int)$s['applied_version'],'at'=>(string)$s['decided_at'],
            'author'=>['username'=>(string)$s['author_name'],'display_name'=>$s['author_display']],
            'accepted_by'=>['username'=>(string)$s['decided_name'],'display_name'=>$s['decided_display']]];
    }
    return $out;
}
