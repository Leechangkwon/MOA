<?php
declare(strict_types=1);

/**
 * scripts/audit_cli.php — 셸 스크립트가 감사 로그를 남기는 얇은 CLI (9단계, ARCHITECTURE 11.1)
 *
 * 사용법
 *   php scripts/audit_cli.php <action> <allow|deny|error> [reason] \
 *        [--target NAME] [--target-type file|user|system] [--level LEVEL] [--detail '<JSON 객체>']
 *
 * 예
 *   php scripts/audit_cli.php backup.run allow --target db \
 *        --detail '{"artifact":"db-20260911-0500.dump.gpg","checksum":"…","bytes":123}'
 *   php scripts/audit_cli.php backup.run error backup_failed --target db --detail '{"step":"pg_dump"}'
 *
 * 왜 PHP 인가: 감사 기록의 규칙(요약 문장 표·detail 씻기·DB+파일 이중 기록)은 src/Audit.php
 * 한 곳에만 있어야 한다 (ARCH 7.4). 셸에서 psql 로 직접 INSERT 하면 그 규칙 밖에 두 번째
 * 기록 경로가 생기고, 문장 표에 없는 행이 조용히 쌓인다.
 *
 * 안전 장치 세 가지
 *   1) **배치가 남길 수 있는 action 은 아래 목록뿐이다.** 셸 스크립트가 `auth.login allow` 같은
 *      사람 행동을 위조하지 못하게 한다 — 이 파일은 root cron 이 부르므로, 여기가 열려 있으면
 *      "감사 로그를 원하는 대로 적을 수 있는 입구" 가 된다.
 *   2) 문장 표(ARCH 7.4)에 없는 (action|result|reason) 조합은 **종료 코드 3**. 조용히 통과하지
 *      않는다 — 새 행동을 만든 사람이 문장 틀을 빠뜨리면 첫 실행에서 드러나야 한다.
 *   3) actor 는 인자로 받지 않는다. 배치에는 로그인 사용자가 없으므로 actor_* 는 항상 NULL 이고,
 *      "누가 실행했나" 는 target_name(복구 스크립트의 $SUDO_USER)·detail 로만 남는다 (PRD S-01).
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

/**
 * 배치가 남길 수 있는 행동 (ARCH 7.4 9단계 행 · 11.1 표).
 * 여기 없는 action 은 이 입구로 기록되지 않는다 — 사람 행동은 앱이 직접 남긴다.
 *
 * @var array<string,list<string>> action => 허용 result 목록
 */
const CLI_ALLOWED_ACTIONS = [
    'backup.run'      => ['allow', 'error'],
    // 16-2 (SECURITY 8.4-10): `error` 를 더했다. 복구가 중간에 멈추면 그 사실이 남을 곳이
    // 있어야 한다 — 없던 동안 2026-09-11 의 실패한 복구는 롤백된 `allow` 한 줄로만 남았다.
    'backup.restore'  => ['allow', 'error'],
    'audit.integrity' => ['allow', 'error'],
    'alert.raised'    => ['allow'],
    'file.purge'      => ['allow', 'error'],
];

/** target_type 은 ARCH 7.2 의 세 값만 */
const CLI_TARGET_TYPES = ['file', 'user', 'system'];

/** 접근 등급 (PRD 4장) */
const CLI_LEVELS = ['public', 'internal', 'team', 'admin-only'];

$argv = $_SERVER['argv'];

function usage(string $why): never
{
    cli_warn('[사용법 오류] ' . $why);
    cli_warn('  php scripts/audit_cli.php <action> <allow|deny|error> [reason]'
        . ' [--target NAME] [--target-type file|user|system] [--level LEVEL] [--detail JSON]');
    cli_warn('  허용 action: ' . implode(', ', array_keys(CLI_ALLOWED_ACTIONS)));
    exit(TBOX_CLI_BAD_USAGE);
}

// ─── 위치 인자 (옵션이 아닌 것만) ───────────────────────────────
$positional = [];
$count = count($argv);
for ($i = 1; $i < $count; $i++) {
    $a = $argv[$i];
    if (strpos($a, '--') === 0) {
        // `--이름 값` 형태면 다음 하나를 건너뛴다 (`--이름=값` 은 건너뛸 것이 없다)
        if (strpos($a, '=') === false && isset($argv[$i + 1]) && strpos($argv[$i + 1], '--') !== 0) {
            $i++;
        }
        continue;
    }
    $positional[] = $a;
}

$action = $positional[0] ?? '';
$result = $positional[1] ?? '';
$reason = $positional[2] ?? null;

if ($action === '' || $result === '') {
    usage('action 과 result 는 필수다');
}
if (!isset(CLI_ALLOWED_ACTIONS[$action])) {
    usage('배치가 남길 수 없는 action 이다: ' . $action);
}
if (!in_array($result, CLI_ALLOWED_ACTIONS[$action], true)) {
    usage($action . ' 에 허용되지 않는 result 다: ' . $result);
}
if ($result === 'allow') {
    if ($reason !== null && $reason !== '') {
        usage('allow 에는 reason 을 붙이지 않는다 (ARCH 7.4 키는 action|allow 두 칸)');
    }
    $reason = null;
} else {
    if ($reason === null || $reason === '') {
        usage($result . ' 에는 reason 이 필요하다');
    }
    if (preg_match('/^[a-z_]{1,40}$/', $reason) !== 1) {
        usage('reason 형태가 아니다 (소문자·밑줄 40자 이내): ' . $reason);
    }
}

// ─── 옵션 ──────────────────────────────────────────────────────
$target     = cli_option($argv, 'target');
$targetType = cli_option($argv, 'target-type', 'system');
$level      = cli_option($argv, 'level');
$detailRaw  = cli_option($argv, 'detail');

if ($targetType !== null && !in_array($targetType, CLI_TARGET_TYPES, true)) {
    usage('target-type 은 file/user/system 중 하나다: ' . $targetType);
}
if ($level !== null && !in_array($level, CLI_LEVELS, true)) {
    usage('level 은 public/internal/team/admin-only 중 하나다: ' . $level);
}

$detail = [];
if ($detailRaw !== null && $detailRaw !== '') {
    if (strlen($detailRaw) > 8192) {
        usage('detail JSON 이 8KB 를 넘는다');
    }
    $parsed = json_decode($detailRaw, true, 6, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($parsed)) {
        usage('detail 이 JSON 객체가 아니다');
    }
    $detail = $parsed;
}

$ctx = [
    'target_type' => $targetType,
    'target_name' => $target,
    'access_level' => $level,
];
if ($detail !== []) {
    $ctx['detail'] = $detail;      // Audit::scrub() 이 비밀 키를 지운다 (ARCH 7.5)
}

// ─── 기록 ──────────────────────────────────────────────────────
// 문장 표에 없는 조합이면 Audit 이 예외를 던진다. 그 한 가지만 종료 코드 3(사용법 오류)으로
// 돌려주고, 다른 오류(DB 다운·파일 권한)는 실패 1 로 둔다 — 둘을 섞으면 "문장 틀이 없다" 와
// "DB 가 죽었다" 를 호출한 셸이 구별할 수 없다.
try {
    if ($result === 'allow') {
        Audit::allow($action, $ctx);
    } elseif ($result === 'deny') {
        Audit::deny($action, (string) $reason, $ctx);
    } else {
        Audit::error($action, (string) $reason, $ctx);
    }
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), '요약 문장 틀이 없습니다') !== false) {
        cli_warn('[사용법 오류] ' . $e->getMessage() . ' — ARCHITECTURE 7.4 표와 src/Audit.php 에 먼저 추가한다');
        exit(TBOX_CLI_BAD_USAGE);
    }
    throw $e;
}

cli_say(sprintf('감사 기록: %s %s%s', $action, $result, $reason === null ? '' : ' ' . $reason));
exit(TBOX_CLI_OK);
