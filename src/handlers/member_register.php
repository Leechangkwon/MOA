<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
require_once __DIR__.'/_username_common.php';
require_once __DIR__.'/../Legal.php';
Member::requireJoinCsrf();
if (Auth::currentUser() !== null) Http::error(409, '이미 로그인되어 있습니다.');
Member::rate('join', Http::clientIp() ?? 'unknown', 10);
$body=Http::jsonBody(8192);
if (array_diff(array_keys($body), ['invite','username','display_name','password','agree_terms','age14']) !== []) Http::error(400, Http::MSG_BAD_REQUEST);
// 사이트 Q:F-13 P1 (결정 PT-4 · PT-9) — 이용약관 동의와 만 14세 이상 확인은 **서버가 강제**한다: 둘 다 JSON true 가 아니면(빠짐 · false · "true" 글자) 400 · 계정 0.
// 화면의 체크는 편의다. 동의 줄 셋(terms · privacy(=확인) · age14)은 아래 계정 만들기와 **같은 트랜잭션**에 남는다(하나라도 실패하면 계정도 없다).
if (($body['agree_terms'] ?? null) !== true || ($body['age14'] ?? null) !== true) Http::error(400, '이용약관 동의와 만 14세 이상 확인이 필요합니다.');
$code=$body['invite']??null; $username=$body['username']??null; $name=$body['display_name']??null; $password=$body['password']??null;
if (!is_string($code) || !preg_match('/\A[a-f0-9]{64}\z/',$code)) Http::error(400,'초대 링크를 다시 확인해 주세요.');
if (!tbox_username_format_ok($username)) Http::error(400,tbox_username_message('bad_format'));
// Q:F-13 P2-R1(RV-P2-2) — `deleted-` 는 탈퇴 익명화(`deleted-<번호>`)만 쓰는 접두 — 계정 만들기·아이디 바꾸기와 같은 400 · 같은 문구.
if (str_starts_with($username,'deleted-')) Http::error(400,'탈퇴한 계정 전용 접두(deleted-)로 시작하는 아이디는 쓸 수 없습니다 — 다른 아이디로 정해 주세요');
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
    Legal::record(['id'=>(int)$row['id'],'username'=>$username], ['terms','privacy','age14'], 'agree', ['via'=>'register']);
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
// 사이트 Q:F-15 S2 (D-8) — 가입 직후 갈 곳: 남은 단계(password_change · mfa_setup)가 있으면 그 단계를 맡는 시작 안내 화면, 없으면 사이트 첫 화면 `/`(모아 홈).
// 화면(member.js)도 같은 규칙으로 간다 — 이 값은 어긋나지 않게 맞춘 것뿐이다(판정 · 세션 · 감사 · 가입 검사 0).
$next=Auth::nextStep($result);
Http::json(['ok'=>true,'next'=>$next,'redirect'=>$next===null?'/':Http::url('/welcome')],201);
