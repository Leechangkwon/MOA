<?php
declare(strict_types=1);
// Stage 9: existing app role only. No external commands or elevated DB role.
require_once __DIR__.'/lib/cli_bootstrap.php';
require_once __DIR__.'/../src/community/routes.php';
$args=$_SERVER['argv'];
$nowText=cli_option($args,'now',null);
if ($nowText!==null && !str_starts_with(Env::get('DB_NAME'),'tbox_stage89_test_')) { cli_warn('--now is restricted to the isolated acceptance database'); exit(TBOX_CLI_BAD_USAGE); }
$now=$nowText===null ? new DateTimeImmutable('now',new DateTimeZone('Asia/Seoul')) : new DateTimeImmutable($nowText,new DateTimeZone('Asia/Seoul'));
$now=$now->setTimezone(new DateTimeZone('Asia/Seoul'));
$lock=fopen(Env::get('STORAGE_DIR').'/community-automations.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { cli_say('Skipped: another owned worker holds the lock'); exit(TBOX_CLI_OK); }
$statePath=Env::get('STORAGE_DIR').'/community-automations.cursor.json';
$state=is_file($statePath) ? json_decode((string)file_get_contents($statePath),true) : null;
$cursor=max(0,(int)($state['cursor'] ?? 0));
$queueFirst=($state['queue_first'] ?? false)===true;
$db=Db::instance(); $runs=0; $work=0; $created=0;
try {
    // Alternate queue/schedule priority across invocations. A busy lane cannot starve the other.
    foreach ($queueFirst ? ['queue','schedule'] : ['schedule','queue'] as $lane) {
        if ($work>=Automation::BATCH_WORK_LIMIT) { break; }
        if ($lane==='queue') {
            $queue=$db->run('SELECT q.id,q.page_id,q.event_id,a.public_id,e.by_user_id FROM community.automation_queue q JOIN community.automations a ON a.id=q.automation_id JOIN community.page_property_events e ON e.id=q.event_id WHERE q.taken_at IS NULL ORDER BY q.id LIMIT CAST(:limit AS integer)',
                [':limit'=>Automation::BATCH_WORK_LIMIT-$work])->fetchAll();
            foreach ($queue as $q) {
                $db->begin();
                try {
                    $taken=$db->run('UPDATE community.automation_queue SET taken_at=now() WHERE id=:id AND taken_at IS NULL',[':id'=>(int)$q['id']])->rowCount();
                    $r=community_automation_row($q['public_id']);
                    if ($taken) { $work++; }
                    if ($taken && $r!==null && $r['is_enabled'] && $r['deleted_at']===null && $r['kind']==='on_status') {
                        $uuid=$db->run('SELECT public_id FROM community.pages WHERE id=:id',[':id'=>(int)$q['page_id']])->fetchColumn();
                        $page=$uuid ? community_page_row($uuid,(int)$r['owner_user_id']) : null;
                        if ($page!==null) {
                            $result=community_automation_execute($r,(int)$r['owner_user_id'],false,null,$page,(int)$q['by_user_id'],null,$now,Automation::BATCH_PAGE_LIMIT-$created,'on_status');
                            if ($result['deferred'] ?? false) { $db->rollback(); $work--; break; }
                            if (!($result['skipped'] ?? false)) { $runs++; $created+=count($result['created_pages'] ?? []); }
                        }
                    }
                    $db->commit();
                } catch (Throwable $e) { $db->rollback(); throw $e; }
            }
            continue;
        }
        // At most 200 rules are inspected; a durable round-robin cursor reaches later users next time.
        $rows=$db->run("SELECT id,public_id FROM community.automations WHERE is_enabled AND deleted_at IS NULL AND kind IN ('recurring','due_reminder') ORDER BY CASE WHEN id>:cursor THEN 0 ELSE 1 END,id LIMIT 200",[':cursor'=>$cursor])->fetchAll();
        foreach ($rows as $row) {
            if ($work>=Automation::BATCH_WORK_LIMIT) { break; }
            $r=community_automation_row($row['public_id']);
            if ($r===null || !$r['is_enabled'] || $r['deleted_at']!==null) { $cursor=(int)$row['id']; continue; }
            if ($r['kind']==='recurring') {
                $slot=Automation::latestSlot(json_decode($r['trigger'],true),$now);
                if ($slot>=new DateTimeImmutable($r['created_at'])) {
                    // The executor reserves the entire rule's creation allowance after locking its current row.
                    $result=community_automation_execute($r,(int)$r['owner_user_id'],false,$slot,null,null,null,$now,Automation::BATCH_PAGE_LIMIT-$created,'recurring');
                    if ($result['deferred'] ?? false) { break; }
                    if (!($result['skipped'] ?? false)) { $work++; $runs++; $created+=count($result['created_pages'] ?? []); }
                }
            } else {
                $slot=$now->setTime(9,0);
                if ($now>=$slot) {
                    $u=community_user_by_id((int)$r['owner_user_id']);
                    try { community_automation_scope(community_automation_rule($r),$u,false); $allowed=true; }
                    catch (InvalidArgumentException $e) { $allowed=false; }
                    if (!$allowed || !$u['is_active'] || ($u['expires_at']!==null && strtotime($u['expires_at'])<=time()) || !Authz::canCommunityWrite($u)) {
                        community_automation_execute($r,(int)$r['owner_user_id'],false,null,null,null,null,$now,0,'due_reminder'); $work++; $runs++;
                    } else {
                        [$visible,$bind]=community_search_pages_where($u);
                        $bind+=[':due'=>$now->modify('+1 day')->format('Y-m-d'),':day'=>$now->format('Y-m-d'),':page'=>$r['scope_page_id'],':space'=>$r['scope_space_id'],':limit'=>Automation::BATCH_WORK_LIMIT-$work];
                        $sql=<<<SQL
                            SELECT p.public_id FROM community.pages p JOIN community.spaces s ON s.id=p.space_id LEFT JOIN public.users owner ON owner.id=s.owner_user_id
                            WHERE p.deleted_at IS NULL AND {$visible}
                              AND p.due_date=CAST(:due AS date) AND (p.status IS NULL OR p.status NOT IN ('done','hold'))
                              AND (p.id=CAST(:page AS bigint) OR p.space_id=CAST(:space AS bigint))
                              AND NOT EXISTS (SELECT 1 FROM community.notifications n WHERE n.page_id=p.id AND n.kind='due_tomorrow'
                                AND n.user_id=CASE WHEN s.kind='personal' THEN s.owner_user_id ELSE p.author_id END
                                AND n.created_at>=CAST(:day AS date)::timestamptz)
                            ORDER BY p.id LIMIT CAST(:limit AS integer)
                            SQL;
                        foreach ($db->run($sql,$bind)->fetchAll() as $p) {
                            $page=community_page_row($p['public_id'],(int)$u['id']);
                            community_automation_execute($r,(int)$u['id'],false,null,$page,null,null,$now,0,'due_reminder'); $work++; $runs++;
                            $latest=community_automation_row($r['public_id']);
                            if ($latest===null || !$latest['is_enabled']) { break; }
                        }
                    }
                }
            }
            $cursor=(int)$row['id'];
        }
    }
    cli_say('Processed runs: '.$runs.'; work: '.$work.'; created pages: '.$created);
} finally {
    // Fixed app-owned path only. A crash before this write can rescan but cannot duplicate committed slots.
    $json=json_encode(['cursor'=>$cursor,'queue_first'=>!$queueFirst],JSON_THROW_ON_ERROR);
    if (file_put_contents($statePath.'.tmp',$json)===false || !rename($statePath.'.tmp',$statePath)) { cli_warn('Could not persist automation scan cursor'); }
    flock($lock,LOCK_UN); fclose($lock);
}
