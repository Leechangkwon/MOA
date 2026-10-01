<?php
declare(strict_types=1);
$db=Db::instance(); $me=(int)$user['id']; $body=Http::jsonBody();
$db->begin();
try {
    // User-level lock makes the enabled-rule cap safe against parallel requests.
    $db->run('SELECT pg_advisory_xact_lock(9401,CAST(:id AS integer))'
,[':id'=>$me]);
    $old=isset($params[0]) ? community_automation_owned($params[0],$user) : null;
    if ($old!==null) {
        $db->run('SELECT id FROM community.automations WHERE id=:id FOR UPDATE'
,[':id'=>(int)$old['id']]);
        $old=community_automation_owned($params[0],$user);
    }
    $enabled=array_key_exists('is_enabled',$body) ? $body['is_enabled'] : null;
    if ($enabled!==null && !is_bool($enabled)) { Http::error(400,Http::MSG_BAD_REQUEST); }
    if (array_key_exists('is_enabled',$body) && $body['is_enabled']===null) { Http::error(400,Http::MSG_BAD_REQUEST); }
    unset($body['is_enabled']);
    $rule=$old===null ? $body : array_merge(community_automation_rule($old),$body);
    Automation::validate($rule);
    [$pageId,$spaceId]=community_automation_scope($rule,$user);
    $changed=$old===null || $rule!==community_automation_rule($old);
    if ($enabled===true && !in_array($rule['kind'],['on_status','due_reminder'],true) && ($old===null || $changed || $old['last_dry_run_at']===null || strtotime($old['updated_at'])>strtotime($old['last_dry_run_at']))) { throw new InvalidArgumentException('dry_run_required'); }
    if ($enabled===true && ($old===null || !$old['is_enabled'])) {
        $n=(int)$db->run('SELECT count(*) FROM community.automations WHERE owner_user_id=:id AND is_enabled AND deleted_at IS NULL'
,[':id'=>$me])->fetchColumn();
        if ($n>=20) { throw new InvalidArgumentException('enabled_limit'); }
    }
    $id=$old['public_id'] ?? tbox_uuid_v4();
    $bind=[':uuid'=>$id,':owner'=>$me,':kind'=>$rule['kind'],':name'=>$rule['name'],':page'=>$pageId,':space'=>$spaceId,
        ':trigger'=>json_encode($rule['trigger'],JSON_THROW_ON_ERROR),':actions'=>json_encode($rule['actions'],JSON_THROW_ON_ERROR),
        ':press'=>$rule['press_scope'] ?? null,':enabled'=>($enabled ?? ($changed ? false:($old['is_enabled'] ?? false))) ? 'true':'false'];
    if ($old===null) {
        $sql=<<<'SQL'
          INSERT INTO community.automations(public_id,owner_user_id,kind,name,scope_page_id,scope_space_id,trigger,actions,press_scope,is_enabled)
          VALUES(CAST(:uuid AS uuid),:owner,:kind,:name,:page,:space,CAST(:trigger AS jsonb),CAST(:actions AS jsonb),:press,CAST(:enabled AS boolean))
          SQL;
    } else {
        $bind[':changed']=$changed?'true':'false';
        $sql=<<<'SQL'
          UPDATE community.automations SET kind=:kind,name=:name,scope_page_id=:page,scope_space_id=:space,trigger=CAST(:trigger AS jsonb),actions=CAST(:actions AS jsonb),press_scope=:press,is_enabled=CAST(:enabled AS boolean),
          updated_at=CASE WHEN CAST(:changed AS boolean) THEN clock_timestamp() ELSE updated_at END,
          last_dry_run_at=CASE WHEN CAST(:changed AS boolean) THEN NULL ELSE last_dry_run_at END
          WHERE public_id=CAST(:uuid AS uuid) AND owner_user_id=:owner AND deleted_at IS NULL
          SQL;
    }
    $db->run($sql,$bind);
    $row=community_automation_owned($id,$user);
    Audit::allow('automation.save',['target_type'=>'automation','target_id'=>(int)$row['id'],'target_name'=>community_target_title($row['name'])]);
    $db->commit(); Http::json(['automation'=>community_automation_out($row)],$old===null?201:200);
} catch (InvalidArgumentException $e) {
    $db->rollback(); Http::json(['error'=>'자동 작업 설정을 확인하세요.','reason'=>$e->getMessage()], in_array($e->getMessage(), ['scope_denied','scope_owner_required'], true) ? 403 : 400);
} catch (Throwable $e) { $db->rollback(); throw $e; }
