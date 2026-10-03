<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(2);
require_once dirname(__DIR__,2) . '/src/Env.php';
// 임시 인스턴스에만 생성하는 0600 파일. 웹/워커는 앱 계정을 그대로 사용한다.
$fixtureConfig=getenv('ASSISTANT_FIXTURE_CONFIG_FILE');
if (!is_string($fixtureConfig) || $fixtureConfig==='') exit(3);
Env::load($fixtureConfig);
require_once dirname(__DIR__,2) . '/src/bootstrap.php';
require_once dirname(__DIR__,2) . '/src/assistant/bootstrap.php';
if (!str_starts_with(Env::get('DB_NAME'),'tbox_assistant_test_')) exit(3);
$db=Db::instance(); $command=$argv[1] ?? '';
if ($command==='init') {
    $sql = <<<'SQL'
        INSERT INTO permissions(role,access_level,can_read,can_upload)
        VALUES(:role,:level,CAST(:read AS boolean),CAST(:upload AS boolean))
        SQL;
    foreach (['Admin'=>['public','internal','team','admin-only'],'Manager'=>['public','internal','team'],
        'Employee'=>['public','internal','team'],'Guest'=>['public']] as $role=>$levels)
        foreach (['public','internal','team','admin-only'] as $level)
            $db->run($sql,[':role'=>$role,':level'=>$level,':read'=>in_array($level,$levels,true) ? 'true':'false',
                ':upload'=>($role!=='Guest' && in_array($level,$levels,true)) ? 'true':'false']);
    $teamSql='INSERT INTO teams(name) VALUES(:name) RETURNING id';
    $a=(int)$db->run($teamSql,[':name'=>'검증팀 A'])->fetch()['id'];
    $b=(int)$db->run($teamSql,[':name'=>'검증팀 B'])->fetch()['id'];
    $data=['users'=>[],'files'=>[],'teams'=>['a'=>$a,'b'=>$b]];
    $users=['employee'=>['claude_employee01','Employee',$a],'other'=>['claude_employee02','Employee',$b],
        'admin'=>['claude_admin01','Admin',$a],'guest'=>['claude_guest01','Guest',null],'manager'=>['claude_manager01','Manager',$a]];
    foreach ($users as $key=>[$name,$role,$team]) {
        $password=bin2hex(random_bytes(16)); $secret=Totp::generateSecret();
        $sql=<<<'SQL'
            INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at)
            VALUES(:name,:hash,:role,:team,:secret,true,clock_timestamp()+interval '14 days') RETURNING id
            SQL;
        $id=(int)$db->run($sql,[':name'=>$name,':hash'=>password_hash($password,PASSWORD_ARGON2ID),':role'=>$role,':team'=>$team,':secret'=>$secret])->fetch()['id'];
        $session=bin2hex(random_bytes(32)); $sessionHash=hash('sha256',$session);
        $sql=<<<'SQL'
            INSERT INTO sessions(id,user_id,expires_at,ip,user_agent)
            VALUES(:hash,:user,clock_timestamp()+interval '8 hours','127.0.0.1',:ua)
            SQL;
        $db->run($sql,[':hash'=>$sessionHash,':user'=>$id,':ua'=>'tbox-review/' . date('Y-m-d')]);
        $item=['id'=>$id,'username'=>$name,'password'=>$password,'secret'=>$secret,'role'=>$role,'team_id'=>$team,
            'session'=>$session,'csrf'=>hash_hmac('sha256','csrf:' . $sessionHash,Env::get('APP_SECRET'))];
        foreach (['assistant.run','read'] as $scope) {
            $token=bin2hex(random_bytes(24));
            $sql=<<<'SQL'
                INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at)
                VALUES(:user,:name,:hash,:hint,:creator,:scope,clock_timestamp()+interval '1 day') RETURNING id
                SQL;
            $tid=(int)$db->run($sql,[':user'=>$id,':name'=>'검증용 ' . $scope,':hash'=>hash('sha256',$token),':hint'=>substr($token,0,6),':creator'=>$id,':scope'=>$scope])->fetch()['id'];
            $item[$scope]=['token'=>$token,'id'=>$tid];
        }
        $data['users'][$key]=$item;
    }
    foreach (['public'=>['public',null],'internal'=>['internal',null],'team_a'=>['team',$a],'team_b'=>['team',$b],'admin'=>['admin-only',null],'deleted'=>['public',null]] as $key=>[$level,$team]) {
        $id=tbox_uuid_v4(); $stored=Storage::newStoredName('txt'); $bytes="SYNTHETIC FIXTURE ONLY\n";
        $path=Storage::pathFor($stored);
        if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
        file_put_contents($path,$bytes);
        $sql=<<<'SQL'
            INSERT INTO files(public_id,original_name,stored_name,ext,mime,size_bytes,sha256,description,access_level,team_id,uploaded_by,deleted_at)
            VALUES(CAST(:pid AS uuid),:name,:stored,'txt','text/plain',:size,:sha,:description,:level,:team,:owner,CAST(:deleted AS timestamptz)) RETURNING id
            SQL;
        $fid=(int)$db->run($sql,[':pid'=>$id,':name'=>'재료비_' . $key . '.txt',':stored'=>$stored,':size'=>strlen($bytes),':sha'=>hash('sha256',$bytes),
            ':description'=>'가짜 검증 자료 ' . $key . ' <script>not executable</script>',':level'=>$level,':team'=>$team,
            ':owner'=>$data['users']['employee']['id'],':deleted'=>$key==='deleted' ? date(DATE_ATOM):null])->fetch()['id'];
        $data['files'][$key]=['id'=>$id,'internal_id'=>$fid,'level'=>$level,'team_id'=>$team];
    }
    $target=trim(stream_get_contents(STDIN)); if ($target==='') exit(2);
    file_put_contents($target,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)); chmod($target,0600);
    echo "Fixture created; credentials written to private file only\n";
    exit;
}
$raw=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
if (!is_array($raw)) exit(2);
if ($command==='token') {
    $scope=$raw['scope']; $token=bin2hex(random_bytes(24));
    $sql=<<<'SQL'
        INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at,bound_ip)
        VALUES(:user,'scope regression',:hash,:hint,:creator,:scope,clock_timestamp()+interval '1 day',CAST(:ip AS inet)) RETURNING id
        SQL;
    $id=$db->run($sql,[':user'=>$raw['user_id'],':creator'=>$raw['user_id'],':hash'=>hash('sha256',$token),':hint'=>substr($token,0,6),
        ':scope'=>$scope,':ip'=>$scope==='session.open' ? '127.0.0.1':null])->fetch()['id'];
    // 호출 프로세스 메모리로만 전달. 보고/로그/명령 인자에 쓰지 않는다.
    echo json_encode(['token'=>$token,'id'=>$id]); exit;
}
$action=$raw['action'] ?? '';
$pid=$raw['file_id'] ?? null; $uid=$raw['user_id'] ?? null; $tid=$raw['token_id'] ?? null; $rid=$raw['run_id'] ?? null;
$map=[
    'file_private'=>["UPDATE files SET access_level='admin-only' WHERE public_id=CAST(:id AS uuid)",
        [':id'=>$pid]],
    'file_deleted'=>["UPDATE files SET deleted_at=clock_timestamp() WHERE public_id=CAST(:id AS uuid)",
        [':id'=>$pid]],
    'file_restore'=>["UPDATE files SET access_level=:level,team_id=:team,deleted_at=NULL WHERE public_id=CAST(:id AS uuid)",
        [':id'=>$pid,':level'=>$raw['level'] ?? 'public',':team'=>$raw['team_id'] ?? null]],
    'user_disabled'=>['UPDATE users SET is_active=false WHERE id=:id',
        [':id'=>$uid]],
    'user_expired'=>["UPDATE users SET expires_at=clock_timestamp()-interval '1 second' WHERE id=:id",
        [':id'=>$uid]],
    'user_gate'=>['UPDATE users SET must_change_password=true WHERE id=:id',
        [':id'=>$uid]],
    'user_guest'=>["UPDATE users SET role='Guest',team_id=NULL,expires_at=clock_timestamp()+interval '1 day' WHERE id=:id",
        [':id'=>$uid]],
    'user_team'=>['UPDATE users SET team_id=:team WHERE id=:id',
        [':id'=>$uid,':team'=>$raw['team_id'] ?? null]],
    'user_restore'=>["UPDATE users SET is_active=true,must_change_password=false,role=:role,team_id=:team,expires_at=clock_timestamp()+interval '14 days' WHERE id=:id",
        [':id'=>$uid,':role'=>$raw['role'] ?? 'Employee',':team'=>$raw['team_id'] ?? null]],
    'token_revoke'=>['UPDATE api_tokens SET revoked_at=clock_timestamp() WHERE id=:id',
        [':id'=>$tid]],
    'token_expire'=>["UPDATE api_tokens SET expires_at=clock_timestamp()-interval '1 second' WHERE id=:id",
        [':id'=>$tid]],
    'token_restore'=>["UPDATE api_tokens SET revoked_at=NULL,expires_at=clock_timestamp()+interval '1 day' WHERE id=:id",
        [':id'=>$tid]],
    'approval_expire'=>["UPDATE assistant_runs SET approval_expires_at=clock_timestamp()-interval '1 second' WHERE id=CAST(:id AS uuid)",
        [':id'=>$rid]],
    'lease_expire'=>["UPDATE assistant_runs SET lease_expires_at=clock_timestamp()-interval '1 second' WHERE id=CAST(:id AS uuid)",
        [':id'=>$rid]],
    'lease_exhaust'=>["UPDATE assistant_runs SET attempts=3,lease_expires_at=clock_timestamp()-interval '1 second' WHERE id=CAST(:id AS uuid)",
        [':id'=>$rid]],
    'age_history'=>["UPDATE assistant_runs SET created_at=created_at-interval '2 minutes'",
        []],
];
if ($action==='claim') { $r=QarediAssistant\Worker::claim(); echo json_encode($r===null ? null:['run_id'=>$r['id'],'worker_id'=>$r['worker_id']]); exit; }
if ($action==='execute_claim') {
    $r=QarediAssistant\Store::own((int)$uid,(string)$rid);
    $r['worker_id']=(string)$raw['worker_id'];
    QarediAssistant\Worker::execute($r);
    echo "ok\n"; exit;
}
if (!isset($map[$action])) exit(2);
[$sql,$params]=$map[$action]; $db->run($sql,$params); echo "ok\n";
