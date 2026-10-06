<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require_once dirname(__DIR__, 2) . '/src/Env.php';
$config = getenv('FILE_RENAME_FIXTURE_CONFIG');
if (!is_string($config) || $config === '') exit(3);
Env::load($config);
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
if (!str_starts_with(Env::get('DB_NAME'), 'tbox_stage89_test_')) exit(3);
$db = Db::instance();
if (($argv[1] ?? '') === 'init') {
    $sql = 'INSERT INTO permissions(role,access_level,can_read,can_upload) VALUES(:role,:level,CAST(:read AS boolean),CAST(:upload AS boolean))';
    foreach (['Admin' => ['public','internal','team','admin-only'], 'Manager' => ['public','internal','team'],
        'Employee' => ['public','internal','team'], 'Guest' => ['public']] as $role => $levels) {
        foreach (['public','internal','team','admin-only'] as $level) {
            $db->run($sql, [':role'=>$role, ':level'=>$level, ':read'=>in_array($level,$levels,true) ? 'true':'false',
                ':upload'=>$role !== 'Guest' && in_array($level,$levels,true) ? 'true':'false']);
        }
    }
    $teamSql = 'INSERT INTO teams(name) VALUES(:name) RETURNING id';
    $a = (int)$db->run($teamSql, [':name'=>'검증팀 A'])->fetch()['id'];
    $b = (int)$db->run($teamSql, [':name'=>'검증팀 B'])->fetch()['id'];
    $data = ['users'=>[], 'files'=>[], 'folders'=>[]];
    foreach (['employee'=>['claude_employee01','Employee',$a], 'other'=>['claude_employee02','Employee',$b],
        'admin'=>['claude_admin01','Admin',$a], 'manager'=>['claude_manager01','Manager',$a],
        'guest'=>['claude_guest01','Guest',null]] as $key=>[$username,$role,$team]) {
        $sql = <<<'SQL'
            INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at)
            VALUES(:username,:hash,:role,:team,:secret,true,clock_timestamp()+interval '14 days') RETURNING id
            SQL;
        $id = (int)$db->run($sql, [':username'=>$username, ':hash'=>password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID),
            ':role'=>$role, ':team'=>$team, ':secret'=>Totp::generateSecret()])->fetch()['id'];
        $session = bin2hex(random_bytes(32)); $hash = hash('sha256',$session);
        $sql = <<<'SQL'
            INSERT INTO sessions(id,user_id,expires_at,ip,user_agent)
            VALUES(:hash,:user,clock_timestamp()+interval '8 hours','127.0.0.1',:ua)
            SQL;
        $db->run($sql, [':hash'=>$hash, ':user'=>$id, ':ua'=>'tbox-review/'.date('Y-m-d')]);
        $item = ['id'=>$id, 'session'=>$session, 'csrf'=>hash_hmac('sha256','csrf:'.$hash,Env::get('APP_SECRET'))];
        foreach (['read','read+upload','read+upload+delete','community.write','session.open'] as $scope) {
            $token = bin2hex(random_bytes(24));
            $sql = <<<'SQL'
                INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at,bound_ip)
                VALUES(:user,'synthetic rename test',:hash,:hint,:user2,:scope,clock_timestamp()+interval '1 day',CAST(:ip AS inet)) RETURNING id
                SQL;
            $tid = (int)$db->run($sql, [':user'=>$id, ':user2'=>$id, ':hash'=>hash('sha256',$token), ':hint'=>substr($token,0,6),
                ':scope'=>$scope, ':ip'=>$scope === 'session.open' ? '127.0.0.1':null])->fetch()['id'];
            $item[$scope] = ['token'=>$token, 'id'=>$tid];
        }
        // 사이트 Q:F-13 P1 — 이 합성 계정들은 「이미 동의한 기존 회원」이다: 약관 동의 · 처리방침 확인 · 만 14세 · AI 국외 전송(지금 판) 줄을 둔다.
        // 동의 줄이 없는 회원·AI 동의가 없는 회원은 tests/qf13_consent_http.py 가 따로 만들어 시험한다(이 줄은 기존 시험의 기대값을 바꾸지 않기 위한 준비).
        require_once dirname(__DIR__, 2) . '/src/Legal.php';
        foreach (['terms','privacy','age14','ai_overseas'] as $consentDoc) {
            $db->run("INSERT INTO user_consents(user_id,doc,version,action,ip) VALUES(:user,:doc,:version,'agree','127.0.0.1')",
                [':user'=>$id, ':doc'=>$consentDoc, ':version'=>Legal::current($consentDoc)]);
        }
        $data['users'][$key] = $item;
    }
    foreach (['a','b'] as $key) {
        $sql = 'INSERT INTO folders(name,depth,access_level,created_by) VALUES(:name,1,\'public\',:owner) RETURNING id';
        $fid = (int)$db->run($sql, [':name'=>'synthetic-'.$key, ':owner'=>$data['users']['employee']['id']])->fetch()['id'];
        $data['folders'][$key] = ['id'=>$fid, 'internal_id'=>$fid];
    }
    foreach (['main'=>['employee','public',null], 'duplicate'=>['employee','public',null],
        'team'=>['employee','team',$a], 'hidden'=>['other','team',$b], 'deleted'=>['employee','public',null],
        'concurrent'=>['employee','public',null], 'admin'=>['admin','admin-only',null],
        'manager'=>['manager','public',null]] as $key=>[$owner,$level,$team]) {
        $pid = tbox_uuid_v4(); $stored = Storage::newStoredName('txt');
        $bytes = "SYNTHETIC FIXTURE ONLY\n"; $path = Storage::pathFor($stored);
        if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
        file_put_contents($path,$bytes);
        $name = in_array($key,['main','duplicate'],true) ? '기존자료.txt':'검증_'.$key.'.txt';
        $description = '설명근거 ' . $key;
        $sql = <<<'SQL'
            INSERT INTO files(public_id,original_name,stored_name,ext,mime,size_bytes,sha256,description,search_tsv,access_level,team_id,uploaded_by,folder_id,deleted_at)
            VALUES(CAST(:pid AS uuid),:name,:stored,'txt','text/plain',:size,:sha,:description,strip(to_tsvector('simple',:tsv)),:level,:team,:owner,:folder,CAST(:deleted AS timestamptz)) RETURNING id
            SQL;
        $id = (int)$db->run($sql, [':pid'=>$pid, ':name'=>$name, ':stored'=>$stored, ':size'=>strlen($bytes), ':sha'=>hash('sha256',$bytes),
            ':description'=>$description, ':tsv'=>SearchText::indexTextFile([$name,$description],'synthetic'), ':level'=>$level, ':team'=>$team,
            ':owner'=>$data['users'][$owner]['id'], ':folder'=>$data['folders']['a']['internal_id'],
            ':deleted'=>$key === 'deleted' ? date(DATE_ATOM):null])->fetch()['id'];
        $data['files'][$key] = ['id'=>$pid, 'internal_id'=>$id, 'name'=>$name];
    }
    $target = trim(stream_get_contents(STDIN));
    if ($target === '') exit(2);
    file_put_contents($target,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); chmod($target,0600);
    echo "SYNTHETIC_FIXTURE_CREATED=1; AUTH_WRITTEN_PRIVATE_ONLY=1\n";
    exit;
}
if (($argv[1] ?? '') === 'vectors') {
    $rows = $db->run('SELECT id,original_name,description,search_tsv FROM files WHERE deleted_at IS NULL')->fetchAll();
    $sql = 'SELECT CAST(:actual AS tsvector) = strip(to_tsvector(\'simple\',:expected)) AS same';
    $mismatches = 0;
    foreach ($rows as $row) {
        $same = $db->run($sql, [':actual'=>$row['search_tsv'], ':expected'=>SearchText::indexTextFile([$row['original_name'],$row['description']],'synthetic')])->fetch()['same'];
        if ($same !== true) $mismatches++;
    }
    echo json_encode(['vector_mismatches'=>$mismatches]);
    exit;
}
exit(2);
