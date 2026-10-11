<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
define('TBOX_BASE','/tbox');define('COMMUNITY_BASE','/community');define('TBOX_HOME_PATH','/');
require dirname(__DIR__,2).'/src/Http.php';
$_COOKIE['tbox_theme']='light';require dirname(__DIR__,2).'/src/views/space_page.php';
