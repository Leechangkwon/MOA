<?php
declare(strict_types=1);
Http::json(['automation'=>community_automation_out(community_automation_owned($params[0],$user))]);
