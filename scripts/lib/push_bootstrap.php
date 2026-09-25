<?php
declare(strict_types=1);

/**
 * scripts/lib/push_bootstrap.php — 휴대폰 경보 **보내는 배치**의 시작점 (PRD F-70 · 지시서 C장 · L-3, 구현 기록 3·4)
 *
 * 왜 cli_bootstrap.php 를 쓰지 않나
 *   cli_bootstrap → src/bootstrap.php 는 저장소 루트 `.env` 를 읽는다. 거기에는 앱 계정 tbox_app 의 비밀번호와
 *   APP_SECRET(CSRF·세션 파생 값의 열쇠)이 있다. 보내는 배치는 **밖으로 나가는 유일한 배치**라 전용 OS 계정
 *   tbox-push 로 돌리고(L-3), 그 계정이 `.env` 를 읽지 못하게 둔다. 그래서 이 파일은 자기 설정 한 벌만 읽는다:
 *     /etc/tbox-v2/push.conf   (비밀 아님, root 0644) — DB 호스트·포트·이름 · 역할 tbox_push · pgpass 경로 ·
 *                              키 경로 · 상태 폴더 · 자기 감사 사본 경로(AUDIT_LOG_FILE) · alerts.log 경로 · DIGEST_RULES
 *     /etc/tbox-v2/push.pgpass (tbox-push 0600) — DB 비밀번호. PHP 는 이 파일을 **열지 않는다** — libpq 가
 *                              PGPASSFILE 로 직접 읽는다(비밀번호가 PHP 변수·명령 인자·출력 어디에도 없다)
 *   형식은 `.env` 와 같다(KEY=VALUE — src/Env.php 파서를 그대로 쓴다. 설정 파일이 코드가 되지 않게 좁은 형식).
 *
 * 감사 (구현 기록 4 — 사용자 결정 대기): 기존 Audit 클래스를 **그대로** 쓴다. Audit 은 DB 행(tbox_push 의 audit_logs
 *   INSERT — append-only 규칙 그대로)과 파일 사본(`Env::get('AUDIT_LOG_FILE')`)에 쓴다. 이 배치에서는 AUDIT_LOG_FILE 이
 *   push.conf 의 값(/var/log/tbox-push/audit.log, tbox-push 0640)이라 앱 감사 사본(/var/log/tbox-v2/audit.log, tbox-php:adm)에
 *   쓸 권한이 필요 없다 — Audit.php 의 쓰기 코드는 한 줄도 바뀌지 않았다(설정으로 경로만 바뀐다, 더 작은 변경).
 *
 * 하는 일
 *   1) CLI 로만 실행 · 오류 화면 표시 끔(src/bootstrap.php 와 같은 값)
 *   2) 쓰는 클래스만 싣는다(명시 목록 — 오토로더 없음): Env · Db · Http · Auth · Audit · Fetch · WebPush · Origin · log_tail
 *   3) request_id · 감사 route(`cli:alert_push.php`) 고정
 *   4) 설정을 읽고 값 모양을 본다(경로는 절대 경로 · 역할·DB 이름은 식별자 모양). 요청 값이 없는 배치라 셸 인자
 *      `--conf <경로>` 만 설정 파일을 바꾼다(시험용 — 운영 cron 은 인자 없이 /etc/tbox-v2/push.conf)
 *   5) Db::boot — 비밀번호 칸은 비워 두고 PGPASSFILE 을 push.conf 의 경로로 둔다
 *   6) 오류 기록 자리 — 기본 /var/log/tbox-push/error.log(설정을 읽기 **전에** 건다 — 설정 오류·치명 오류도 흔적이 남게),
 *      push.conf 의 ERROR_LOG 로 바꿀 수 있다. cron 은 출력을 버린다(MAILTO="") — 표준오류만으로는 아무도 못 본다.
 *      dry-run 은 이 파일에도 쓰지 않는다(표준오류에만).
 * 하지 않는 것: `.env` 읽기 · 웹용 보안 머리 · 세션.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/** 오류 기록 기본 자리 (tbox-push 0640 — 반영 스크립트 apply-f70-host 가 만든다). push.conf 의 ERROR_LOG 가 있으면 그 값 */
const PUSH_ERROR_LOG_DEFAULT = '/var/log/tbox-push/error.log';
ini_set('error_log', PUSH_ERROR_LOG_DEFAULT);
/** dry-run 이면 오류 기록 파일에도 쓰지 않는다(표준오류에만) — 인자는 아래에서 다시 엄격히 본다 */
define('PUSH_DRY_RUN', in_array('--dry-run', (array) ($_SERVER['argv'] ?? []), true));

$pushSrc = dirname(__DIR__, 2) . '/src';
require_once $pushSrc . '/Env.php';
require_once $pushSrc . '/Db.php';
require_once $pushSrc . '/Http.php';
require_once $pushSrc . '/Auth.php';
require_once $pushSrc . '/Audit.php';
require_once $pushSrc . '/Fetch.php';
require_once $pushSrc . '/WebPush.php';
require_once $pushSrc . '/Origin.php';
require_once __DIR__ . '/log_tail.php';
unset($pushSrc);

/** 운영 설정 파일 (구현 기록 3). 코드 상수 — 환경 변수로 바꾸는 길은 없다 */
const PUSH_CONF_FILE = '/etc/tbox-v2/push.conf';

/** 종료 코드 — cli_bootstrap 과 같은 뜻 (전달 실패는 0 — 지시서 C 「실패해도 exit 0」, 설정·DB 시작 실패만 1) */
const PUSH_CLI_OK        = 0;
const PUSH_CLI_FAIL      = 1;
const PUSH_CLI_BAD_USAGE = 3;

if (!defined('TBOX_REQUEST_ID')) {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    define('TBOX_REQUEST_ID', substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
        . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12));
    unset($b, $h);
}
if (!defined('TBOX_ROUTE')) {
    define('TBOX_ROUTE', 'cli:alert_push.php');
}
// Http::url 을 부르는 곳은 없지만 클래스가 상수를 가리키므로 같은 값으로 둔다(src/bootstrap.php 와 같다)
if (!defined('TBOX_BASE')) {
    define('TBOX_BASE', '/tbox');
}

// 경고·통지도 예외로 (src/bootstrap.php 와 같은 규칙 — 경고 문구가 출력에 섞이지 않게)
set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $no) === 0) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

// 잡히지 않은 예외 — 표준오류에 한 줄 요약만(값·경로 없이) · 상세(클래스·파일:줄)는 오류 기록 파일 · 종료 코드 1
set_exception_handler(static function (Throwable $e): void {
    $where = sprintf('%s @ %s:%d', get_class($e), $e->getFile(), $e->getLine());
    if (!PUSH_DRY_RUN) {
        error_log(sprintf('[tbox-push] request_id=%s %s', TBOX_REQUEST_ID, $where));
    }
    fwrite(STDERR, '[실패] alert_push: ' . get_class($e) . "\n");
    exit(PUSH_CLI_FAIL);
});

/** 표준출력 한 줄 */
function push_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

/** 표준오류 한 줄 + 오류 기록 파일 한 줄(dry-run 이면 표준오류만). 문구에 값을 넣지 않는다(키 이름·사유만) */
function push_warn(string $line): void
{
    fwrite(STDERR, $line . "\n");
    if (!PUSH_DRY_RUN) {
        error_log('[tbox-push] request_id=' . TBOX_REQUEST_ID . ' ' . $line);
    }
}

/** `--이름` 플래그 */
function push_flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

/** `--이름 값` · `--이름=값` */
function push_option(array $argv, string $name): ?string
{
    $prefix = '--' . $name;
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        if ($argv[$i] === $prefix) {
            return $argv[$i + 1] ?? null;
        }
        if (strpos($argv[$i], $prefix . '=') === 0) {
            return substr($argv[$i], strlen($prefix) + 1);
        }
    }
    return null;
}

/**
 * push.conf 를 읽고 모양을 본다. 틀리면 표준오류에 **키 이름만** 찍고 종료 코드 3.
 *
 * @return array<string,string>
 */
function push_load_conf(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        push_warn('[설정 오류] 보내는 배치 설정 파일을 읽을 수 없다');
        exit(PUSH_CLI_BAD_USAGE);
    }
    Env::load($path);

    $conf = [];
    // 오류 기록 자리(있을 때만 — 없으면 기본값 그대로). 다른 경로 키보다 먼저 건다(아래 설정 오류도 그 파일에 남게)
    $errLog = Env::getOrEmpty('ERROR_LOG');
    if ($errLog !== '') {
        if ($errLog[0] !== '/' || strpos($errLog, '..') !== false || preg_match('/[\x00-\x1f]/', $errLog) === 1) {
            push_warn('[설정 오류] ERROR_LOG 는 절대 경로여야 한다');
            exit(PUSH_CLI_BAD_USAGE);
        }
        ini_set('error_log', $errLog);
    }
    $conf['ERROR_LOG'] = $errLog === '' ? PUSH_ERROR_LOG_DEFAULT : $errLog;
    $absPaths = ['PGPASSFILE', 'VAPID_PRIVATE_KEY_FILE', 'VAPID_PUBLIC_KEY_FILE', 'STATE_DIR', 'AUDIT_LOG_FILE', 'ALERTS_LOG'];
    foreach ($absPaths as $k) {
        $v = Env::getOrEmpty($k);
        if ($v === '' || $v[0] !== '/' || strpos($v, '..') !== false || preg_match('/[\x00-\x1f]/', $v) === 1) {
            push_warn('[설정 오류] ' . $k . ' 는 절대 경로여야 한다');
            exit(PUSH_CLI_BAD_USAGE);
        }
        $conf[$k] = $v;
    }
    foreach (['DB_NAME', 'DB_USER'] as $k) {
        $v = Env::getOrEmpty($k);
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $v) !== 1) {
            push_warn('[설정 오류] ' . $k . ' 의 모양이 아니다');
            exit(PUSH_CLI_BAD_USAGE);
        }
        $conf[$k] = $v;
    }
    $host = Env::getOrEmpty('DB_HOST');
    $port = Env::getOrEmpty('DB_PORT');
    if (preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host) !== 1 || preg_match('/^[0-9]{1,5}$/', $port) !== 1) {
        push_warn('[설정 오류] DB_HOST·DB_PORT 의 모양이 아니다');
        exit(PUSH_CLI_BAD_USAGE);
    }
    $conf['DB_HOST'] = $host;
    $conf['DB_PORT'] = $port;
    // 하루 요약으로 보낼 규칙(구현 기록 5) — 비우면 사람·시스템 경보는 전부 즉시(지시서 그대로)
    $digest = Env::getOrEmpty('DIGEST_RULES');
    if ($digest !== '' && preg_match('/^AL-[0-9]{1,2}(,AL-[0-9]{1,2})*$/', str_replace(' ', '', $digest)) !== 1) {
        push_warn('[설정 오류] DIGEST_RULES 는 AL-n 을 쉼표로 이은 것이어야 한다');
        exit(PUSH_CLI_BAD_USAGE);
    }
    $conf['DIGEST_RULES'] = str_replace(' ', '', $digest);
    return $conf;
}

/**
 * DB 연결 정보만 넘긴다(연결은 첫 질의 때). 비밀번호는 비워 두고 libpq 가 PGPASSFILE 을 읽는다.
 *
 * @param array<string,string> $conf
 */
function push_boot_db(array $conf): void
{
    if (!is_file($conf['PGPASSFILE']) || !is_readable($conf['PGPASSFILE'])) {
        push_warn('[설정 오류] DB 비밀번호 파일(PGPASSFILE)을 읽을 수 없다');
        exit(PUSH_CLI_BAD_USAGE);
    }
    putenv('PGPASSFILE=' . $conf['PGPASSFILE']);
    Db::boot($conf['DB_HOST'], $conf['DB_PORT'], $conf['DB_NAME'], $conf['DB_USER'], '');
}

/**
 * 상태 파일 쓰기 — 같은 폴더의 임시 파일에 쓰고 rename(반쪽 파일을 읽지 않게) · 0600.
 * dry-run 에서는 부르지 않는다(부르는 쪽이 막는다).
 */
function push_write_state(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException('상태 폴더가 없습니다');
    }
    $tmp = $path . '.tmp';
    $old = umask(0077);
    try {
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('상태 파일을 쓸 수 없습니다');
        }
    } finally {
        umask($old);
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('상태 파일을 바꿔 놓을 수 없습니다');
    }
}
