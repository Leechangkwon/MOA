<?php
declare(strict_types=1);
$after = Http::queryText('after', 20);
if ($after !== '' && preg_match('/^[1-9][0-9]{0,17}$/', $after) !== 1) { Http::error(400, Http::MSG_BAD_REQUEST); }
$sql = community_automation_select_sql() . ' WHERE a.owner_user_id=:id AND a.deleted_at IS NULL AND a.id>CAST(:after AS bigint) ORDER BY a.id LIMIT 101';
$rows = Db::instance()->run($sql, [':id'=>(int)$user['id'], ':after'=>$after === '' ? '0' : $after])->fetchAll();
$hasMore = count($rows) > 100;
if ($hasMore) { array_pop($rows); }
$cursor = $hasMore ? (string)$rows[count($rows)-1]['id'] : null;
Http::json(['automations'=>array_map('community_automation_out', $rows), 'next_cursor'=>$cursor]);
