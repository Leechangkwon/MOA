<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__,2);
define('TBOX_BASE','/tbox');define('COMMUNITY_BASE','/community');define('TBOX_HOME_PATH','/');
require $root.'/src/Http.php';
$kind=$argv[1]??'employee';$module=$argv[2]??'files';
final class Auth {public static function currentUser(): ?array {return $GLOBALS['user'];}}
final class Authz {
 public static function canCommunityWrite(array $u): bool {return ($u['role']??'')!=='Guest';}
 public static function canAdmin(array $u): bool {return false;}
 public static function canManageUsers(array $u): bool {return false;}
}
final class Legal {public static function needsConsent(int $id): bool {return false;}}
function tbox_person_label(?string $name,string $username): string {return $name?:$username;}
$user=['id'=>1001,'username'=>'synthetic_rail','display_name'=>'검토 사용자','role'=>$kind==='guest'?'Guest':'Employee','acting'=>$kind==='acting'];
$_COOKIE['tbox_theme']='dark';$_COOKIE['qaredi_design']='aurora';
$pageTitle='합성 레일 검토';$pageKey='rail-fixture';$activeNav='files';
if($module!=='files')$shellModule=$module;
require $root.'/src/views/shell_top.php';
echo '<h1>합성 자료실</h1><input class="input" id="synthetic-context" value="검색·폴더 상태"><button id="synthetic-focus">본문 초점</button>';
require $root.'/src/views/shell_bottom.php';
