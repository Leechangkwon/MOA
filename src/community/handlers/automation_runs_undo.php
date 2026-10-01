<?php
declare(strict_types=1);
$db=Db::instance(); $me=(int)$user['id']; $db->begin();
try {
    $run=$db->run('SELECT * FROM community.automation_runs WHERE public_id=CAST(:uuid AS uuid) FOR UPDATE'
,[':uuid'=>$params[0]])->fetch();
    if (!is_array($run) || !CommunityAuthz::decideAutomationUndo($user,$run)[0]) { $db->rollback(); Http::error(404,Http::MSG_NOT_FOUND); }
    if ($run['dry_run'] || $run['undone_at']!==null || strtotime($run['started_at'])<time()-7*86400) { $db->rollback(); Http::error(400,Http::MSG_BAD_REQUEST); }
    $ids=array_filter(explode(',',trim($run['created_page_ids'],'{}'))); $skipped=[]; $deleted=[];
    foreach ($ids as $id) {
        $uuid=$db->run('SELECT public_id FROM community.pages WHERE id=:id AND deleted_at IS NULL FOR UPDATE'
,[':id'=>(int)$id])->fetchColumn();
        if (!$uuid) { continue; }
        $page=community_page_row($uuid,$me);
        $branch=community_subtree_live_ids((int)$id); $allow=true;
        foreach ($branch as $childId) {
            $childUuid=$db->run('SELECT public_id FROM community.pages WHERE id=:id FOR UPDATE'
,[':id'=>$childId])->fetchColumn();
            $child=community_page_row($childUuid,$me);
            $changed=$db->run('SELECT 1 FROM community.page_versions WHERE page_id=:id AND edited_by<>:uid AND edited_at>=CAST(:at AS timestamptz) LIMIT 1'
,[':id'=>$childId,':uid'=>$me,':at'=>$run['started_at']])->fetchColumn();
            if (!CommunityAuthz::decidePageManage($user,$child)[0] || $changed || (int)$child['last_edited_by']!==$me || (int)$child['author_id']!==$me) { $allow=false; break; }
        }
        if (!$allow) { $skipped[]=['id'=>$uuid,'reason'=>'edited_or_permission_changed']; continue; }
        $db->run("UPDATE community.pages SET deleted_at=now(),deleted_by=:uid,purge_after=now()+interval '30 days' WHERE id=ANY(CAST(:ids AS bigint[])) AND deleted_at IS NULL"
,[':uid'=>$me,':ids'=>'{'.implode(',',$branch).'}']);
        $deleted[]=$uuid;
    }
    $db->run('UPDATE community.notifications SET retracted_at=now() WHERE id=ANY(CAST(:ids AS bigint[]))'
,[':ids'=>$run['notification_ids']]);
    $db->run('UPDATE community.automation_runs SET undone_at=now(),undone_by=:uid WHERE id=:id'
,[':uid'=>$me,':id'=>(int)$run['id']]);
    Audit::allow('automation.undo',['target_type'=>'automation_run','target_id'=>(int)$run['id'],'detail'=>['run_id'=>$run['public_id']]]);
    $db->commit(); Http::json(['undone'=>true,'deleted_pages'=>$deleted,'skipped'=>$skipped]);
} catch (Throwable $e) { $db->rollback(); throw $e; }
