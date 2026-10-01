<?php
declare(strict_types=1);
// Route role:Admin includes existing administrator reauthentication; no sources/bodies returned.
if (isset($params[0])) {
    $r=community_automation_row($params[0]);
    if ($r===null || $r['deleted_at']!==null) { Http::error(404,Http::MSG_NOT_FOUND); }
    Db::instance()->run('UPDATE community.automations SET is_enabled=false WHERE id=:id'
,[':id'=>(int)$r['id']]);
    Audit::allow('automation.delete',['target_type'=>'automation','target_id'=>(int)$r['id'],'target_name'=>community_target_title($r['name'])]);
    Http::json(['disabled'=>true]);
} else {
    $after = Http::queryText('after', 20);
    if ($after !== '' && preg_match('/^[1-9][0-9]{0,17}$/', $after) !== 1) { Http::error(400, Http::MSG_BAD_REQUEST); }
    $rows = Db::instance()->run(
        'SELECT a.id AS cursor_id,a.public_id AS id,a.name,a.is_enabled,coalesce(u.display_name,u.username) AS owner,
        (SELECT r.status FROM community.automation_runs r WHERE r.automation_id=a.id AND NOT r.dry_run ORDER BY r.id DESC LIMIT 1) AS last_result
        FROM community.automations a JOIN users u ON u.id=a.owner_user_id
        WHERE a.deleted_at IS NULL AND a.id>CAST(:after AS bigint) ORDER BY a.id LIMIT 101',
        [':after'=>$after === '' ? '0' : $after])->fetchAll();
    $hasMore = count($rows) > 100;
    if ($hasMore) { array_pop($rows); }
    $cursor = $hasMore ? (string)$rows[count($rows)-1]['cursor_id'] : null;
    foreach ($rows as &$item) { unset($item['cursor_id']); } unset($item);
    Http::json(['automations'=>$rows, 'next_cursor'=>$cursor]);
}
