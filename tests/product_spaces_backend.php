<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||!str_starts_with((string)getenv('PRODUCT_TEST_DB'),'tbox_product_test_'))exit(2);
define('TBOX_BASE','/tbox');define('COMMUNITY_BASE','/community');
require dirname(__DIR__).'/src/Db.php';require dirname(__DIR__).'/src/Authz.php';require dirname(__DIR__).'/src/Http.php';
final class Audit {public static array $rows=[];public static function deny(string $a,string $r,array $d=[]):void{self::$rows[]=[$a,$d];}public static function allow(string $a,array $d=[]): void {self::$rows[]=[$a,$d];}}
final class Auth {public const MAX_PASSWORD_BYTES=1024;public static function verifyCurrentPassword(array $u,string $p): bool{return $p==='synthetic-password';}public static function countFailureAndRevoke(int $id): bool{return false;}public static function reauth(array $u,string $p): array{return [$p==='synthetic-password',null];}public static function verifyOtpOnce(array $u,string $otp): string{return $otp==='123456'?'ok':'bad_code';}}
require dirname(__DIR__).'/src/site/ProductSpaces.php';
Db::boot('127.0.0.1','5432',(string)getenv('PRODUCT_TEST_DB'),'tbox_testdb',(string)getenv('PRODUCT_TEST_PASS'));
$db=Db::instance();$n=0;
function check(bool $v,string $label): void {global $n;if(!$v)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label."\n";}
function denied(callable $fn,int $status,string $label): void {try{$fn();}catch(ProductSpaceError $e){check($e->status===$status,$label);return;}throw new RuntimeException('FAIL '.$label.' allowed');}
$users=[];foreach(['owner','admin','editor','member','viewer','other','guest','globaladmin','secondowner'] as $name){
 $role=$name==='guest'?'Guest':($name==='globaladmin'?'Admin':'Employee');
 $users[$name]=$db->run('INSERT INTO users(username,password_hash,role,expires_at) VALUES(:name,:hash,:role,now()+interval \'30 days\') RETURNING *',

 [':name'=>'claude_'.$name,':hash'=>'synthetic-hash',':role'=>$role])->fetch();
}
$svc=[];foreach($users as $k=>$u)$svc[$k]=new ProductSpaces($u);
$space=$svc['owner']->create(['name'=>'합성 회사','kind'=>'company'])['space'];$sid=$space['id'];
check($space['role']==='owner'&&$space['can_manage'],'creator sole owner');
check(count($svc['other']->spaces()['spaces'])===0,'other account no spaces');
denied(fn()=>$svc['globaladmin']->get($sid),404,'global admin cannot cross membership');
denied(fn()=>$svc['guest']->create(['name'=>'x','kind'=>'group']),403,'global guest cannot create');
foreach(['admin','editor','member','viewer','secondowner'] as $name){$invite=$svc['owner']->inviteCreate($sid,['role'=>$name==='secondowner'?'member':$name,'max_uses'=>1]);$svc[$name]->join(['token'=>$invite['token']]);}
check(count($svc['owner']->members($sid)['members'])===6,'members joined without global team change');
$group=$svc['other']->create(['name'=>'합성 모임','kind'=>'group'])['space']['id'];
denied(fn()=>$svc['member']->get($group),404,'other space isolated');
denied(fn()=>$svc['viewer']->itemCreate($sid,['kind'=>'task','title'=>'blocked']),403,'viewer cannot write');
$task=$svc['member']->itemCreate($sid,['kind'=>'task','title'=>'준비','due_at'=>'2026-10-20','assignee_id'=>(int)$users['editor']['id']])['item'];
check($task['can_edit'],'member edits own task');
denied(fn()=>$svc['member']->itemCreate($sid,['kind'=>'notice','title'=>'blocked']),403,'member cannot publish notice');
$notice=$svc['editor']->itemCreate($sid,['kind'=>'notice','title'=>'공지','body'=>'<script>plain text</script>'])['item'];
$event=$svc['member']->itemCreate($sid,['kind'=>'event','title'=>'모임','starts_at'=>'2026-10-20T19:00','ends_at'=>'2026-10-20T20:00'])['item'];
$story=$svc['member']->itemCreate($sid,['kind'=>'story','title'=>'후기','body'=>'실제 저장된 합성 기록'])['item'];
check(count($svc['viewer']->home($sid)['widgets']['task'])===1,'real stored task widget');
check($svc['viewer']->item($sid,$notice['id'])['item']['body']==='<script>plain text</script>','plain text storage only');
denied(fn()=>$svc['member']->itemChange($sid,$notice['id'],['revision'=>1,'title'=>'no']),403,'member cannot edit editor notice');
$changed=$svc['editor']->itemChange($sid,$task['id'],['revision'=>1,'status'=>'doing'])['item'];check($changed['revision']===2,'editor modifies others with revision');
denied(fn()=>$svc['member']->itemChange($sid,$task['id'],['revision'=>1,'status'=>'done']),409,'stale item revision rejected');
denied(fn()=>$svc['member']->itemCreate($sid,['kind'=>'event','title'=>'bad','starts_at'=>'2026-10-20','ends_at'=>'2026-10-19']),400,'invalid event range rejected');
denied(fn()=>$svc['member']->itemCreate($sid,['kind'=>'task','title'=>'bad','assignee_id'=>(int)$users['other']['id']]),400,'cross space assignee rejected');
$layout=$svc['owner']->dashboard($sid);check($layout['revision']===0,'GET dashboard no row creation');
$layout['layout']['widgets'][0]['visible']=false;$saved=$svc['editor']->dashboardSave($sid,$layout);check($saved['revision']===1,'designated editor saves shared layout');
denied(fn()=>$svc['owner']->dashboardSave($sid,$layout),409,'stale shared layout rejected');
denied(fn()=>$svc['viewer']->dashboardSave($sid,$saved),403,'viewer layout readonly');
$personal=$svc['guest']->dashboard(null);$personal['layout']['source']='files';check($svc['guest']->dashboardSave(null,$personal)['revision']===1,'personal preferences guest allowed');check($svc['other']->dashboard(null)['revision']===0,'personal settings isolated');
$bad=$saved;$bad['layout']['widgets'][1]['id']=$bad['layout']['widgets'][0]['id'];denied(fn()=>$svc['owner']->dashboardSave($sid,$bad),400,'duplicate widget rejected');
$invite=$svc['owner']->inviteCreate($sid,['role'=>'member','max_uses'=>1]);$raw=$db->run('SELECT token_hash FROM product_space_invites WHERE id=:id',
  [':id'=>$invite['invite']['id']])->fetchColumn();check($raw!==$invite['token']&&$raw===hash('sha256',$invite['token']),'invite secret hashed');
$svc['other']->join(['token'=>$invite['token']]);denied(fn()=>$svc['globaladmin']->join(['token'=>$invite['token']]),404,'exhausted invite rejected');
$inv=$svc['admin']->inviteCreate($sid,['role'=>'member']);$svc['owner']->memberChange($sid,(int)$users['admin']['id'],['role'=>'member']);denied(fn()=>$svc['globaladmin']->join(['token'=>$inv['token']]),404,'demoted issuer invite rejected');
$svc['owner']->memberChange($sid,(int)$users['admin']['id'],['role'=>'admin']);
$inv=$svc['admin']->inviteCreate($sid,['role'=>'member']);$svc['owner']->memberChange($sid,(int)$users['admin']['id'],null);denied(fn()=>$svc['globaladmin']->join(['token'=>$inv['token']]),404,'removed issuer invite rejected');
$inv=$svc['owner']->inviteCreate($sid,['role'=>'member']);$db->run('UPDATE users SET is_active=false WHERE id=:uid',
  [':uid'=>$users['owner']['id']]);denied(fn()=>$svc['globaladmin']->join(['token'=>$inv['token']]),404,'disabled issuer invite rejected');$db->run('UPDATE users SET is_active=true WHERE id=:uid',
  [':uid'=>$users['owner']['id']]);
$inv=$svc['owner']->inviteCreate($sid,['role'=>'viewer']);$svc['owner']->inviteRevoke($sid,$inv['invite']['id']);denied(fn()=>$svc['globaladmin']->join(['token'=>$inv['token']]),404,'revoked invite rejected');
$inv=$svc['owner']->inviteCreate($sid,['role'=>'viewer']);$db->run('UPDATE product_space_invites SET expires_at=now()-interval \'1 second\' WHERE id=:id',
  [':id'=>$inv['invite']['id']]);denied(fn()=>$svc['globaladmin']->join(['token'=>$inv['token']]),404,'expired invite rejected');
denied(fn()=>$svc['owner']->memberChange($sid,(int)$users['owner']['id'],null),409,'last owner leave rejected');
denied(fn()=>$svc['owner']->memberChange($sid,(int)$users['owner']['id'],['role'=>'member']),409,'last owner demotion rejected');
denied(fn()=>$svc['owner']->memberChange($sid,(int)$users['member']['id'],['role'=>'owner']),403,'owner elevation only explicit transfer');
$adminToken=$svc['owner']->inviteCreate($sid,['role'=>'admin']);
$svc['owner']->transfer($sid,['user_id'=>(int)$users['secondowner']['id'],'password'=>'synthetic-password']);
check($svc['secondowner']->get($sid)['space']['role']==='owner','explicit transfer changes owner');
denied(fn()=>$svc['globaladmin']->join(['token'=>$adminToken['token']]),404,'old owner admin invitation loses authority');
$svc['secondowner']->update($sid,['status'=>'archived']);check(count($svc['viewer']->home($sid)['widgets']['story'])===1,'archived space readable');
denied(fn()=>$svc['member']->itemCreate($sid,['kind'=>'story','title'=>'blocked']),403,'archive writes blocked');
check(count($svc['member']->home(null)['widgets']['story'])===0,'archived content not active personal widget');
$svc['secondowner']->update($sid,['status'=>'active']);
// Actual legacy private file visibility, explicit links never grant new rights.
$file=$db->run('INSERT INTO files(original_name,stored_name,ext,mime,size_bytes,sha256,access_level,uploaded_by) VALUES(\'secret.pdf\',\'synthetic.pdf\',\'pdf\',\'application/pdf\',1,:sha,\'private\',:uid) RETURNING *',
  [':sha'=>str_repeat('0',64),':uid'=>$users['member']['id']])->fetch();
$link=$svc['member']->itemCreate($sid,['kind'=>'file','title'=>'자료','file_id'=>$file['public_id']])['item'];check($link['download_url']!==null,'private owner file link works');
check(count($svc['viewer']->items($sid,'file')['items'])===0,'ungranted original file omitted including title');
denied(fn()=>$svc['viewer']->downloadFile($sid,$link['id']),404,'download requires original file access');
check($svc['member']->downloadFile($sid,$link['id'])===$file['public_id'],'original owner download lookup');
$db->run('INSERT INTO access_grants(file_id,user_id,granted_by) VALUES(:file,:uid,:owner)',
 [':file'=>$file['id'],':uid'=>$users['viewer']['id'],':owner'=>$users['member']['id']]);
check(count($svc['viewer']->items($sid,'file')['items'])===1,'original granted private file visible in space');
check($svc['viewer']->downloadFile($sid,$link['id'])===$file['public_id'],'original private grant download lookup');
$db->run('DELETE FROM access_grants WHERE file_id=:file AND user_id=:uid',
 [':file'=>$file['id'],':uid'=>$users['viewer']['id']]);
check(count($svc['viewer']->items($sid,'file')['items'])===0,'revoked original grant immediately hides space file');

$db->run('UPDATE files SET uploaded_by=:uid WHERE id=:id',
  [':uid'=>$users['other']['id'],':id'=>$file['id']]);check(count($svc['member']->items($sid,'file')['items'])===0,'revoked original ownership hides linked metadata');
denied(fn()=>$svc['member']->item($sid,$link['id']),404,'revoked original rights block single item');
$svc['secondowner']->memberChange($sid,(int)$users['viewer']['id'],null);denied(fn()=>$svc['viewer']->home($sid),404,'member removal immediately hides home');
$svc['member']->itemChange($sid,$story['id'],null);check(count($svc['member']->items($sid,'story')['items'])===0,'delete is logical item deletion');
check(count(Audit::$rows)>20,'mutations audited');check(!str_contains(json_encode(Audit::$rows),$inv['token']),'audit contains no invitation secret');
echo 'PASS '.$n." checks\n";
