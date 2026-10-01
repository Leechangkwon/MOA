<?php
declare(strict_types=1);
$r=community_automation_owned($params[0],$user);
Db::instance()->begin();
try {
    Db::instance()->run('UPDATE community.automations SET is_enabled=false,deleted_at=now(),updated_at=now() WHERE id=:id AND owner_user_id=:uid'
,[':id'=>(int)$r['id'],':uid'=>(int)$user['id']]);
    Audit::allow('automation.delete',['target_type'=>'automation','target_id'=>(int)$r['id'],'target_name'=>community_target_title($r['name'])]);
    Db::instance()->commit(); Http::json(['deleted'=>true]);
} catch (Throwable $e) { Db::instance()->rollback(); throw $e; }
