<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
require_once __DIR__.'/_username_common.php';
Member::requireJoinCsrf();
if (Auth::currentUser() !== null) Http::error(409, '이미 로그인되어 있습니다.');
Member::rate('join', Http::clientIp() ?? 'unknown', 10);
$body=Http::jsonBody(8192);
if (array_diff(array_keys($body), ['invite','username','display_name','password']) !== []) Http::error(400, Http::MSG_BAD_REQUEST);
$code=$body['invite']??null; $username=$body['username']??null; $name=$body['display_name']??null; $password=$body['password']??null;
if (!is_string($code) || !preg_match('/\A[a-f0-9]{64}\z/',$code)) Http::error(400,'초대 링크를 다시 확인해 주세요.');
if (!tbox_username_format_ok($username)) Http::error(400,tbox_username_message('bad_format'));
if (!is_string($name) || preg_match('//u',$name)!==1 || trim($name)==='' || iconv_strlen(trim($name),'UTF-8')>40 || preg_match('/\p{C}/u',$name)) Http::error(400,'이름은 1~40자로 입력해 주세요.');
if (!is_string($password) || preg_match('//u',$password)!==1 || strlen($password)>Auth::MAX_PASSWORD_BYTES || iconv_strlen($password,'UTF-8')<Auth::MIN_PASSWORD_CHARS) Http::error(400,'비밀번호는 10자 이상으로 정해 주세요.');
$db=Db::instance();
// Check invitation before password hashing; the transaction checks again under a row lock.
$bind=[':hash'=>hash('sha256',$code)]; unset($code,$body);
$validSql="SELECT i.* FROM member_invites i JOIN users creator ON creator.id=i.created_by WHERE i.code_hash=:hash AND i.revoked_at IS NULL AND i.expires_at>now() AND i.used_count<i.max_uses AND creator.is_active AND creator.role='Admin' AND (creator.expires_at IS NULL OR creator.expires_at>now()) AND (i.team_id IS NULL OR EXISTS(SELECT 1 FROM teams t WHERE t.id=i.team_id AND t.is_active))";
if (!$db->run($validSql,$bind)->fetch()) Http::error(410,'초대가 만료되었거나 사용할 수 없습니다. 새 초대 링크를 받아 주세요.');
if (tbox_username_unavailable($db,$username)!==null) Http::error(409,'사용할 수 없는 아이디입니다. 다른 아이디를 정해 주세요.');
$hash=password_hash($password,PASSWORD_ARGON2ID);
$db->begin();
try {
    $invite=$db->run($validSql.' FOR UPDATE OF i',
        $bind)->fetch();
    if (!$invite) { $db->rollback(); Http::error(410,'초대가 만료되었거나 사용할 수 없습니다. 새 초대 링크를 받아 주세요.'); }
    $row=$db->run("INSERT INTO users(username,display_name,password_hash,role,team_id,must_change_password,mfa_setup_required,password_changed_at,invited_account) VALUES(:username,:name,:hash,'Employee',:team,false,false,now(),true) RETURNING id",
        [':username'=>$username,':name'=>trim($name),':hash'=>$hash,':team'=>$invite['team_id']])->fetch();
    $db->run('UPDATE member_invites SET used_count=used_count+1 WHERE id=:id',
        [':id'=>$invite['id']]);
    Audit::allow('user.create',
        ['target_type'=>'user','target_id'=>(int)$row['id'],'target_name'=>$username,'detail'=>['target_role'=>'Employee','target_team'=>$invite['team_id'],'reason_text'=>'초대 링크로 본인 가입','display_name_set'=>true]]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode()==='23505') Http::error(409,'사용할 수 없는 아이디입니다. 다른 아이디를 정해 주세요.');
    throw $e;
} catch (Throwable $e) { $db->rollback(); throw $e; }
// Account is durable even if a later session creation fails; the user can log in normally.
$result=Auth::login($username,$password); unset($password,$hash);
if ($result === null) {
    Http::json(['ok'=>true,'next'=>'login','redirect'=>Http::url('/login')],201);
    return;
}
Http::json(['ok'=>true,'next'=>Auth::nextStep($result),'redirect'=>Http::url('/welcome')],201);
