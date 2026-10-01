<?php
declare(strict_types=1);
$r=community_automation_owned($params[0],$user);
Http::json(community_automation_execute($r,(int)$user['id'],true,null,null,null,$user));
