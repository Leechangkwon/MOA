<?php
declare(strict_types=1);
$b=community_integration_body(['allowed']);
if(!is_bool($b['allowed']??null)) { Http::error(400,Http::MSG_BAD_REQUEST); }
$db=Db::instance();$db->begin();
try {
    $changed=$db->run('UPDATE community.spaces SET web_publish_allowed=:allowed WHERE id=:id',
        [':allowed'=>$b['allowed']?'true':'false',':id'=>(int)$params[0]])->rowCount();
    if(!$changed) { $db->rollback();Http::error(404,Http::MSG_NOT_FOUND); }
    community_integration_audit('space.web_publish',(int)$params[0],['allowed'=>$b['allowed']]);$db->commit();Http::json(['ok'=>true]);
}catch(Throwable $e){$db->rollback();throw $e;}
