<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TBOX_BASE','/tbox'); define('COMMUNITY_BASE','/community'); define('TBOX_HOME_PATH','/');
require dirname(__DIR__,2).'/src/Http.php';
final class Auth { public static function currentUser(): ?array { return null; } }
final class Authz { public static function canCommunityWrite(array $u): bool { return false; } }
final class Legal { public static function needsConsent(int $id): bool { return false; } }
$_COOKIE['tbox_theme']=$argv[3]??'light';
if(($argv[1]??'')==='login') {
 if(isset($argv[2])&&$argv[2]!=='default') $_GET['to']=$argv[2]==='array'?['bad']:$argv[2];
 require dirname(__DIR__,2).'/src/handlers/login_page.php';
} elseif(($argv[1]??'')==='manifest') {
 require dirname(__DIR__,2).'/src/handlers/site_manifest.php';
} else {
 $launcherUser=($argv[2]??'')==='signed'?['id'=>1001,'username'=>'검토 사용자']:null;
 $launcherBriefingCount=$launcherUser?3:null;
 require dirname(__DIR__,2).'/src/views/launcher.php';
}
