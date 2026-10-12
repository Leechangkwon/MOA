<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(1);}
$root=dirname(__DIR__,2);
define('TBOX_BASE','/tbox');define('COMMUNITY_BASE','/community');define('TBOX_HOME_PATH','/');
require $root.'/src/Http.php';
final class Auth {public static function currentUser(): ?array{return null;}}
final class Authz {public static function canCommunityWrite(array $u): bool{return false;}public static function canAdmin(array $u): bool{return false;}public static function canManageUsers(array $u): bool{return false;}}
final class Legal {public static function needsConsent(int $id): bool{return ($GLOBALS['argv'][1]??'')==='consent';}}
function community_palette_version(): string{return 'synthetic';}
function community_work_projects(array $u,int $limit,bool $a=false,bool $b=false): array{return ['items'=>[],'more'=>false];}
function tbox_person_label(?string $name,string $username): string{return $name?:$username;}
$_COOKIE['tbox_theme']='dark';$_COOKIE['qaredi_design']='aurora';
$user=['id'=>1001,'username'=>'synthetic','role'=>'Guest'];
$mode=$argv[1]??'signed';
if(in_array($mode,['work','tasks'],true)){
 require $root.'/src/community/handlers/'.($mode==='work'?'work_home_page.php':'my_tasks_page.php');
}else{
 $launcherUser=$mode==='anon'?null:$user;
 require $root.'/src/views/launcher.php';
}
