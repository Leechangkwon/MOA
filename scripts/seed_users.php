<?php
declare(strict_types=1);

/**
 * scripts/seed_users.php — 시드 계정 5개 + 검토 계정 10개(qa_ 5 · claude_ 5)
 *                           (ARCH 4.0, PRD S-07 · S-09-4 · S-09-6 · F-71)
 *
 * 실행: sudo php scripts/seed_users.php                (deploy/install.sh 7절이 자동으로 부른다 — 시드 + qa + claude)
 *       sudo php scripts/seed_users.php --no-qa        (운영 배포 — 검토 계정을 **하나도** 만들지 않는다: qa·claude 둘 다)
 *       sudo php scripts/seed_users.php --claude-only  (F-71 — claude_ 5개만. 시드·qa 계정은 보지도 않는다)
 *
 * 인자는 위 둘 중 **하나**만, **정확히 일치할 때만** 인정한다. 그 밖의 글자(`--claude`·`--noqa`·
 * 인자 두 개 …)는 사용법 오류로 끝난다(종료 코드 2, DB 에 붙기 전). 부분 일치·약어를 받아 주면
 * 오타 하나로 검토 계정이 운영에 만들어지거나, 거꾸로 만들 줄 알았던 계정이 조용히 빠진다.
 *
 * 계정은 세 갈래다 (PRD 3장 · S-07 "검토 요청 표식")
 *   · 시드 계정 `admin01` 등 5개      — 사용자의 자기 공격·시연 전용
 *   · 검토 계정 `claude_` 접두 5개    — Claude 의 자동 검토·시험 전용, **새 시험은 이것을 쓴다** (F-71, 2026-09-24)
 *              `qa_` 접두 5개        — 옛 시험(2026-09-10~)의 검토 계정. 지우지 않는다(옛 기록 확인용)
 *              둘 다 같은 이름의 시드 계정과 역할·팀이 같다
 *   · 실사용 계정                      — 운영 배포 후 1인 1개 (여기서 만들지 않는다)
 * 감사 로그에서 `actor_name LIKE 'qa\_%'` **또는** `actor_name LIKE 'claude\_%'` 이면 검토 행동,
 * 아니면 사용자 행동이다 (시험의 판정 조각은 tests/lib/review_accounts.sh 한 벌 — F-71 C-1).
 * 운영 배포에서는 `--no-qa` 로 검토 계정(qa·claude)을 만들지 않는다.
 *
 * 새로 만든 계정은 첫 로그인 절차(F-16)가 켜진 채로 나온다. claude_ 계정의 첫 로그인은
 * scripts/review_account_setup.php 가 창구를 그대로 타서 마친다(F-71 B — DB 를 직접 고치지 않는다).
 *
 * 멱등성: **계정별**로 판단한다. 이미 있는 username 은 건드리지 않고(비밀번호를 바꾸지 않는다),
 * 없는 것만 만든다. 그래서 5계정이 이미 있는 DB 에 검토 계정 5개만 덧붙일 수 있다.
 *
 * d74 (2026-09-27) — **사람 계정이 이미 있는 DB 에는 시드 사람 계정을 만들지 않는다.**
 *   관리자 아이디 바꾸기(d74)가 생겨, 운영 관리자가 시드 표의 이름(아래 표 — 문서·저장소에 이미 적힌 이름)에서 다른 이름으로
 *   옮겨 갈 수 있게 됐다. 그 뒤 이 스크립트를 `--claude-only` 없이 다시 돌리면(install.sh 7절이 부른다 — 재실행이 안전한 설치다)
 *   「없는 계정」으로 보고 **옛 이름의 새 Admin** 을 만든다 — 잠금 표적이 되살아나고, 모르는 관리자가 하나 는다.
 *   그래서 DB 에 사람 계정(검토 접두 `qa_`·`claude_` 가 아닌 것 — 판정 `Origin::isReviewAccount()` 한 곳)이 하나라도 있으면
 *   시드 사람 계정 5개는 **만들지 않고** 그 사실을 표준오류에 한 줄 알린다. 검토 계정(모드가 허락하는 것)은 전처럼 없는 것만 만든다.
 *   종료 코드는 0 이다 — install.sh 가 7절에서 멈추지 않게(재실행 설치는 계속 된다). 새 설치(빈 DB)는 전과 같다.
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

/**
 * 인자 — **DB 에 붙기 전에** 읽는다. 사용법 오류로 끝날 실행이 DB 연결·팀 조회를 먼저 하지 않게.
 * 받는 것은 셋 중 하나: 없음(새 설치) · `--no-qa` · `--claude-only`. 정확히 일치할 때만 인정한다.
 */
$seedArgs = array_slice($_SERVER['argv'] ?? [], 1);
$seedMode = 'all';
if ($seedArgs === ['--no-qa']) {
    $seedMode = 'no-qa';
} elseif ($seedArgs === ['--claude-only']) {
    $seedMode = 'claude-only';
} elseif ($seedArgs !== []) {
    // 받은 글자는 되찍지 않는다 — 사용법만 알린다(인자에 무엇이 섞여 들어왔든 화면에 남기지 않는다).
    fwrite(STDERR, "[사용법 오류] 모르는 인자입니다 (정확히 일치하는 인자 하나만 받습니다)\n");
    fwrite(STDERR, "사용법: sudo php scripts/seed_users.php                 시드 + 검토 계정(qa_·claude_)\n");
    fwrite(STDERR, "        sudo php scripts/seed_users.php --no-qa         시드만 (운영 배포 — 검토 계정 0)\n");
    fwrite(STDERR, "        sudo php scripts/seed_users.php --claude-only   claude_ 검토 계정 5개만\n");
    exit(2);
}

$repoDir = dirname(__DIR__);
require_once $repoDir . '/src/Env.php';
require_once $repoDir . '/src/Db.php';
require_once $repoDir . '/src/Origin.php';   // d74 — 검토 접두 판정 한 곳(isReviewAccount — 순수 함수, DB·파일에 닿지 않는다)

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

/**
 * ARCH 4.0 계정 표 그대로. 검토 계정은 같은 이름의 시드 계정과 역할·팀이 같다
 * (`qa_`·`claude_` 접두는 users.username CHECK `^[a-z0-9_.-]+$` 를 통과한다).
 * 역할 문자열은 이 표(코드 상수)에서만 온다 — 요청 값이 아니다.
 */
$accounts = [
    ['admin01',       'Admin',    null],
    ['manager01',     'Manager',  $devTeam],
    ['employee01',    'Employee', $devTeam],
    ['employee02',    'Employee', $salesTeam],
    ['guest01',       'Guest',    null],
];
$qaAccounts = [
    ['qa_admin01',    'Admin',    null],
    ['qa_manager01',  'Manager',  $devTeam],
    ['qa_employee01', 'Employee', $devTeam],
    ['qa_employee02', 'Employee', $salesTeam],
    ['qa_guest01',    'Guest',    null],
];
// F-71 (2026-09-24) — 새 시험의 검토 계정. qa_ 표와 **같은 역할·팀**(지시서 A 표).
// Guest 는 아래 $guestDays(90일) 만료가 만들 때부터 붙는다 — 그 뒤 시험이 깨지므로 연장은
// Admin 창구(F-10)에서 사유와 함께 한다(지시서 K-2).
$claudeAccounts = [
    ['claude_admin01',    'Admin',    null],
    ['claude_manager01',  'Manager',  $devTeam],
    ['claude_employee01', 'Employee', $devTeam],
    ['claude_employee02', 'Employee', $salesTeam],
    ['claude_guest01',    'Guest',    null],
];

/**
 * 10단계 (SECURITY R-12, PRD 3장 "Guest 생명주기") — **Guest 계정은 만료일이 필수**다.
 * DB CHECK(guest_requires_expires_at)가 없는 값을 거부하므로, 여기서 넣지 않으면
 * 시드 자체가 실패한다. 90일은 "협력사 프로젝트 한 분기" 를 기준으로 잡은 값이고,
 * 늘리고 줄이는 것은 계정 관리 화면(F-10)에서 사유와 함께 한다.
 */
$guestDays = 90;

/*
 * 무엇을 만들까 — 인자 셋(위 $seedMode)을 표 조합 하나로 옮긴다.
 *   all          시드 + qa + claude (새 설치 — 인자 없음)
 *   no-qa        시드만 (운영 배포 — 검토 계정 **전부** 안 만든다: qa 도 claude 도)
 *   claude-only  claude 5개만 — 시드·qa 는 이 표에 넣지 않으므로 존재 여부조차 묻지 않는다
 */
/*
 * d74 — 사람 계정이 이미 있는 DB 면 시드 사람 계정을 빼 둔다(머리글 「d74」). 판정은 이름 접두뿐이고(권한 판정 아님),
 * 사람 계정 수만 센다 — 이름을 찍지 않는다. `--claude-only` 는 시드 표를 보지 않으므로 물을 것도 없다.
 */
if ($seedMode !== 'claude-only') {
    $humans = 0;
    foreach ($db->run('SELECT username FROM users')->fetchAll() as $u) {
        if (!Origin::isReviewAccount((string) $u['username'])) {
            $humans++;
        }
    }
    if ($humans > 0) {
        fwrite(STDERR, "시드 사람 계정은 만들지 않습니다 — 이 DB 에 사람 계정이 이미 " . $humans
            . "개 있습니다(새 설치가 아닙니다 · d74). 검토 계정만 모드대로 확인합니다\n");
        $accounts = [];
    }
}

if ($seedMode === 'claude-only') {
    $accounts = $claudeAccounts;
} elseif ($seedMode === 'all') {
    $accounts = array_merge($accounts, $qaAccounts, $claudeAccounts);
}
// 'no-qa' 는 시드 표 그대로다(d74 — 사람 계정이 이미 있으면 빈 표).

$sqlExists = <<<'SQL'
    SELECT 1 AS one FROM users WHERE username = :username
    SQL;

/*
 * 11단계 (PRD F-16 ④) — **새로 만드는 계정**은 첫 로그인에 비밀번호 변경 → 2단계 인증 등록을
 * 마쳐야 한다. 설치 때 발급된 평문이 /etc/tbox-v2/seed-accounts.txt 로 남는 구조(SECURITY R-33)이므로,
 * 그 값의 수명을 첫 로그인에서 끝내는 것이 이 두 플래그다.
 * 이미 있는 계정은 위 "건너뜀" 분기에서 손대지 않는다 — 재실행이 남의 절차 상태를 되돌리지 않는다.
 */
$insert = <<<'SQL'
    INSERT INTO users (username, password_hash, role, team_id, expires_at,
                       must_change_password, mfa_setup_required)
    VALUES (:username, :password_hash, :role, :team_id,
            CASE WHEN :role_for_expiry = 'Guest'
                 THEN now() + make_interval(days => CAST(:guest_days AS integer))
                 ELSE NULL
            END,
            true, true)
    SQL;

$lines = [];
$db->begin();
try {
    foreach ($accounts as [$username, $role, $teamId]) {
        // 이미 있으면 손대지 않는다 — 재실행이 비밀번호를 바꾸지 않게 하는 것이 목적이다.
        if (is_array($db->run($sqlExists, [':username' => $username])->fetch())) {
            continue;
        }
        // 16자 hex = 64비트 무작위. PRD S-09-4 의 최소 10자를 넘고, 사람이 옮겨 적을 수 있는 길이.
        $plain = bin2hex(random_bytes(8));
        $hash  = password_hash($plain, PASSWORD_ARGON2ID);
        if (!is_string($hash)) {
            throw new RuntimeException('비밀번호 해시를 만들 수 없습니다');
        }
        $db->run($insert, [
            ':username'        => $username,
            ':password_hash'   => $hash,
            ':role'            => $role,
            ':team_id'         => $teamId,
            // Guest 만 만료일을 받는다 (10단계, SECURITY R-12). 값은 전부 바인딩이며
            // 역할 문자열은 위 $accounts 표(코드 상수)에서만 온다 — 요청 값이 아니다.
            ':role_for_expiry' => $role,
            ':guest_days'      => $guestDays,
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

// 새로 만든 계정이 없으면 계정 줄을 한 줄도 내지 않는다 — install.sh 가 이 출력으로
// "덧붙일 것이 있는지" 를 판단하므로, 없는데 빈 줄을 내면 파일이 더러워진다.
if ($lines === []) {
    fwrite(STDOUT, "건너뜀 — 새로 만들 계정이 없습니다\n");
    exit(0);
}

// 평문 비밀번호는 여기 한 번만 나온다. 화면에 남기지 말고 install.sh 가 만든
// /etc/tbox-v2/seed-accounts.txt (0600 root) 를 보라.
fwrite(STDOUT, implode("\n", $lines) . "\n");
exit(0);
