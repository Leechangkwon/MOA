<?php
declare(strict_types=1);
$r=community_automation_owned($params[0],$user);
$rows=Db::instance()->run('SELECT public_id,status,dry_run,started_at,finished_at,steps,undone_at FROM community.automation_runs WHERE automation_id=:id ORDER BY started_at DESC,id DESC LIMIT 50'
,[':id'=>(int)$r['id']])->fetchAll();
foreach ($rows as &$row) { $row['id']=$row['public_id']; unset($row['public_id']); $row['steps']=json_decode($row['steps'],true); }
unset($row);
Http::json(['runs'=>$rows]);
