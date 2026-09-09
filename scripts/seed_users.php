<?php
declare(strict_types=1);

/**
 * scripts/seed_users.php — 시드 계정 5개 (ARCH 4.0 "시드 계정", PRD S-09-4 · S-09-6)
 *
 * 실행: sudo php scripts/seed_users.php      (deploy/install.sh 8단계가 자동으로 부른다)
 *
 * 왜 SQL 시드가 아니라 PHP 인가
 *   비밀번호는 Argon2id 해시로만 저장해야 하고(PRD S-09-4), 고정 해시를 db/seed.sql 에 적으면
 *   그 값이 저장소에 커밋되어 모든 설치본의 비밀번호가 같아진다(S-09-6 위반).
 *   그래서 설치할 때 이 스크립트가 **무작위 16자**를 만들어 해시만 DB 에 넣고,
 *   평문은 표준출력에 한 번만 내보낸다. install.sh 가 그것을
 *   /etc/tbox-v2/seed-accounts.txt (0600 root) 로 저장한다. 저장소에는 남지 않는다.
 *
 * 출력 형식 (한 줄에 계정 하나):  username role password
 *
 * 웹으로는 절대 실행되지 않는다 — public/ 밖에 있고(URL 에 없음), 아래에서 SAPI 도 확인한다.
 * src/bootstrap.php 는 웹 전용(보안 헤더·예외 핸들러·라우팅 전제)이므로 쓰지 않고
 * 필요한 클래스 두 개만 직접 require 한다.
 */

if (PHP_SAPI !== 'cli') {
    // 웹에서 도달할 경로가 없지만, 만들어지더라도 실행되지 않게 한다.
    http_response_code(404);
    exit(1);
}

$repoDir = dirname(__DIR__);
require_once $repoDir . '/src/Env.php';
require_once $repoDir . '/src/Db.php';

// 설정 파일은 저장소 루트 .env 한 곳뿐이다 (PRD S-09-6. ARCH 3장).
// changkwon:tbox-php 0640 이고 이 스크립트는 sudo(root) 로 실행하므로 읽을 수 있다.
// 경로는 코드 상수 — 인자·환경변수로 다른 설정 파일을 먹일 수 없다.
Env::load($repoDir . '/.env');
Db::boot(
    Env::get('DB_HOST'),
    Env::get('DB_PORT'),
    Env::get('DB_NAME'),
    Env::get('DB_USER'),
    Env::get('DB_PASS')
);

$db = Db::instance();

/**
 * 이미 계정이 하나라도 있으면 아무것도 하지 않는다.
 * 재실행이 기존 비밀번호를 바꾸거나 계정을 늘리지 않게 하는 것이 목적이다(멱등).
 */
$sqlCount = 'SELECT count(*) AS n FROM users';
$count = (int) $db->run($sqlCount)->fetch()['n'];
if ($count > 0) {
    fwrite(STDOUT, "건너뜀 — users 에 이미 계정이 " . $count . "개 있습니다\n");
    exit(0);
}

/** 팀 id 는 이름으로 찾는다 (db/seed.sql 이 '개발팀','영업팀' 2행을 넣었다). */
function tbox_team_id(Db $db, string $name): int
{
    $sql = 'SELECT id FROM teams WHERE name = :name';
    $row = $db->run($sql, [':name' => $name])->fetch();
    if (!is_array($row)) {
        fwrite(STDERR, "팀을 찾을 수 없습니다: " . $name . " (db/seed.sql 을 먼저 적용하세요)\n");
        exit(1);
    }
    return (int) $row['id'];
}

$devTeam   = tbox_team_id($db, '개발팀');
$salesTeam = tbox_team_id($db, '영업팀');

/** ARCH 4.0 시드 계정 표 그대로 */
$accounts = [
    ['admin01',    'Admin',    null],
    ['manager01',  'Manager',  $devTeam],
    ['employee01', 'Employee', $devTeam],
    ['employee02', 'Employee', $salesTeam],
    ['guest01',    'Guest',    null],
];

$insert = <<<'SQL'
    INSERT INTO users (username, password_hash, role, team_id)
    VALUES (:username, :password_hash, :role, :team_id)
    SQL;

$lines = [];
$db->begin();
try {
    foreach ($accounts as [$username, $role, $teamId]) {
        // 16자 hex = 64비트 무작위. PRD S-09-4 의 최소 12자를 넘고, 사람이 옮겨 적을 수 있는 길이.
        $plain = bin2hex(random_bytes(8));
        $hash  = password_hash($plain, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            throw new RuntimeException('비밀번호 해시를 만들 수 없습니다');
        }
        $db->run($insert, [
            ':username'      => $username,
            ':password_hash' => $hash,
            ':role'          => $role,
            ':team_id'       => $teamId,
        ]);
        $lines[] = $username . ' ' . $role . ' ' . $plain;
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    // 예외 메시지에 비밀번호가 들어갈 여지가 없도록 우리 문구만 낸다.
    fwrite(STDERR, "시드 계정을 만들지 못했습니다 (DB 는 되돌렸습니다)\n");
    exit(1);
}

// 평문 비밀번호는 여기 한 번만 나온다. 화면에 남기지 말고 install.sh 가 만든
// /etc/tbox-v2/seed-accounts.txt (0600 root) 를 보라.
fwrite(STDOUT, implode("\n", $lines) . "\n");
exit(0);
