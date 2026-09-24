<?php
declare(strict_types=1);

/**
 * scripts/lib/cli_bootstrap.php — 운영 배치(9단계)의 공통 시작점 (ARCHITECTURE 11.1, PRD S-08 · S-11)
 *
 * 웹 요청의 시작점은 src/bootstrap.php 다. 배치도 같은 파일을 쓴다 — 설정 읽기(.env)·DB 연결·
 * request_id 발급·오류 은닉이 웹과 한 글자도 다르면, 배치가 남기는 감사 로그의 성질이 달라진다.
 * 이 파일이 더 하는 일은 네 가지뿐이다.
 *
 *   1) CLI 로만 실행되게 막는다. 이 디렉터리는 웹 루트(public/) 밖이지만, 언젠가 잘못된 nginx
 *      설정으로 노출되더라도 PHP_SAPI 검사에서 먼저 끝난다 (심층 방어).
 *   2) 웹용 예외 핸들러(JSON 500 을 echo 하는)를 CLI 용으로 바꾼다 — 배치는 표준오류에 쓰고
 *      **0 이 아닌 종료 코드**로 끝나야 cron 과 호출한 셸 스크립트가 실패를 알아챈다.
 *   3) 감사 로그의 "어디서" 를 고정한다. Audit::write() 는 TBOX_ROUTE 가 없으면 스스로
 *      `cli:스크립트명` 을 넣지만(ARCH 7.5 route), 그 값을 여기서 명시해 두면
 *      `php -r` 이나 다른 진입점으로 우회 실행돼도 기록이 흔들리지 않는다.
 *   4) 배치가 쓰는 작은 도우미(표준출력·종료 코드·인자 파싱)를 모은다.
 *
 * 하지 않는 일
 *   · 인자를 받아 SQL·경로를 만드는 일. 값은 각 스크립트가 바인딩으로만 쓴다 (PRD S-04).
 *   · 역할 판단. 배치에는 로그인 사용자가 없고(actor_* = NULL), 권한은 DB 계정 권한이 전부다.
 *
 * 실행 계정: cron 의 root (백업 키·pgpass 를 읽어야 한다). DB 는 .env 의 tbox_app 으로 붙는다 —
 * files 에 UPDATE, audit_logs 에 INSERT 만 있으면 되고 그 이상은 필요 없다 (ARCH 10장).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
// F-69 — 경보 한 줄마다 붙이는 「누가 일으켰나(origin)」 판정 한 곳. 웹 bootstrap 은 이 파일을 싣지 않는다
// (읽는 곳이 감사 화면 핸들러뿐이라 그쪽이 직접 싣는다) — 경보 배치는 모두 여기를 거치므로 여기서 싣는다.
require_once dirname(__DIR__, 2) . '/src/Origin.php';

/** 배치 종료 코드 — 셸 스크립트·cron 이 이 값으로 성공·실패를 가른다 */
const TBOX_CLI_OK        = 0;   // 정상
const TBOX_CLI_FAIL      = 1;   // 실행 중 오류
const TBOX_CLI_MISMATCH  = 2;   // 점검에서 불일치 발견 (--strict)
const TBOX_CLI_BAD_USAGE = 3;   // 인자·조합이 잘못됨 (문장 표에 없는 action 등)

/**
 * 감사 로그 detail.route 를 `cli:스크립트명` 으로 고정한다 (ARCH 7.5).
 * 스크립트 이름은 서버 파일명이며 사용자 입력이 아니다. 그래도 basename 으로 경로를 떼고
 * 형태를 좁혀 둔다 — 로그에 경로가 통째로 들어가지 않게 하는 것이 목적이다 (R-36 과 같은 이유).
 */
if (!defined('TBOX_ROUTE')) {
    $tboxCliScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['argv'][0] ?? 'cli')));
    $tboxCliScript = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $tboxCliScript);
    define('TBOX_ROUTE', 'cli:' . ($tboxCliScript === '' ? 'cli' : substr($tboxCliScript, 0, 100)));
    unset($tboxCliScript);
}

// 웹용 핸들러(JSON 500 을 echo)는 CLI 에서 쓸모가 없다. 배치용으로 바꾼다.
// 상세는 여전히 error_log 로만 나간다 — 표준오류에는 한 줄 요약만 쓴다
// (cron 메일·로그에 스택 트레이스·DB 문구가 통째로 남지 않게. PRD S-09-7).
set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[tbox-cli] request_id=%s %s: %s @ %s:%d',
        TBOX_REQUEST_ID,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    try {
        Db::instance()->rollback();
    } catch (Throwable $ignored) {
        error_log('[tbox-cli] request_id=' . TBOX_REQUEST_ID . ' rollback failed');
    }
    fwrite(STDERR, '[실패] ' . TBOX_ROUTE . ': ' . $e->getMessage() . "\n");
    exit(TBOX_CLI_FAIL);
});

/** 표준출력 한 줄 (사람이 보는 진행 표시. 기계가 읽는 값은 감사 로그·detail 에 있다) */
function cli_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

/** 표준오류 한 줄 */
function cli_warn(string $line): void
{
    fwrite(STDERR, $line . "\n");
}

/**
 * `--이름` 플래그가 인자에 있나.
 *
 * @param list<string> $argv
 */
function cli_flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

/**
 * `--이름 값` 또는 `--이름=값` 을 읽는다. 없으면 $default.
 * 값 자체는 여기서 검증하지 않는다 — 쓰는 쪽이 형태(정규식·허용 목록)를 확인한다.
 *
 * @param list<string> $argv
 */
function cli_option(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name;
    $count  = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === $prefix) {
            return $argv[$i + 1] ?? $default;
        }
        if (strpos($argv[$i], $prefix . '=') === 0) {
            return substr($argv[$i], strlen($prefix) + 1);
        }
    }
    return $default;
}

/**
 * 배치가 만드는 상태 파일(경보 중복 억제·체인·실패 횟수)의 공통 쓰기.
 * 같은 디렉터리에 임시 파일을 만들고 rename 한다 — 읽는 쪽이 반쪽 파일을 보지 않게.
 * 권한은 0600(root) — 경보 근거·해시 체인은 침입자에게 보여 줄 값이 아니다.
 */
function cli_write_private(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        throw new RuntimeException('상태 디렉터리가 없습니다: ' . $dir);
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        throw new RuntimeException('상태 파일을 쓸 수 없습니다: ' . $path);
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        unlink($tmp);
        throw new RuntimeException('상태 파일을 바꿔 놓을 수 없습니다: ' . $path);
    }
}

/**
 * 경보 한 건을 남긴다 (PRD S-11-A, ARCHITECTURE 11.4). 두 곳에 남는다.
 *   · /var/log/tbox-v2/alerts.log  — 한 줄 JSON (사람·원격 수집기용)
 *   · audit_logs                   — `alert.raised allow`, target_name = AL-n, detail rule·evidence
 *
 * 알림 채널(메일·메신저)은 이 VM 에 없다. `.env` 의 `ALERT_COMMAND` 는 운영 전환 때 채울
 * **자리**이며, 이 구현은 값이 있어도 **실행하지 않는다** — 설정 파일 값이 곧 실행 명령이 되는
 * 경로(proc_open·shell_exec 류)를 앱 코드에 만들지 않기 위해서다 (PRD S-09, 보안 린트 금지 패턴).
 * 값이 채워져 있으면 "보낼 곳이 설정돼 있으나 이 버전은 보내지 않는다" 를 로그에 함께 남긴다.
 *
 * F-69 (PRD F-69 ①~④, 지시서 A-3) — 줄마다 **origin**·**title** 을 더한다.
 *   `{raised_at, rule, evidence, dispatch, request_id, origin, title}` — 앞 다섯 키는 그대로다(옛 줄과 같은 파서).
 *   origin  Origin::VALUES 넷 중 하나. 부르는 쪽이 규칙별 판정(src/Origin.php)을 거쳐 넘긴다 — 이 함수는
 *           값을 **고르지 않고** 모양만 본다(넷 밖이면 예외 — 조용히 `human` 으로 바꾸지 않는다).
 *   title   `claude-*` 면 `[Claude] ` + evidence.rule_text, 아니면 rule_text (Origin::title 한 곳).
 *           rule_text 가 없으면 규칙 번호를 쓴다.
 *   origin_hint(`partial` = 계정·표식 중 하나만 맞음)는 **evidence 안**에 넣는다(화면 「확인 필요」).
 *   감사 `alert.raised` detail 에도 같은 origin 을 넣는다.
 * ★ origin 은 보여 주기와 분류(F-70 즉시/하루 요약)에만 쓴다. 경보를 남길지·감사를 남길지는 origin 과
 *   무관하다 — 어떤 값이든 두 곳에 똑같이 남는다 (F-69 금지 패턴).
 *
 * @param string               $rule       AL-1 … AL-21
 * @param array<string,mixed>  $evidence   근거 (대상 계정·건수·기간 등). ARCH 7.5 evidence 키
 * @param string               $origin     Origin::VALUES 중 하나
 * @param string|null          $originHint Origin::HINT_PARTIAL 또는 null
 */
function cli_raise_alert(string $rule, array $evidence, string $origin, ?string $originHint = null): void
{
    if (preg_match('/^AL-[0-9]{1,2}$/', $rule) !== 1) {
        throw new RuntimeException('경보 규칙 번호 형태가 아닙니다: ' . $rule);
    }
    if (!Origin::isValue($origin)) {
        throw new RuntimeException('경보 origin 값이 아닙니다: ' . $origin);
    }
    if ($originHint !== null && $originHint !== Origin::HINT_PARTIAL) {
        throw new RuntimeException('경보 origin_hint 값이 아닙니다: ' . $originHint);
    }
    if ($originHint !== null) {
        $evidence['origin_hint'] = $originHint;
    }
    $ruleText = isset($evidence['rule_text']) && is_string($evidence['rule_text']) && $evidence['rule_text'] !== ''
        ? $evidence['rule_text']
        : $rule;
    $title = Origin::title($origin, $ruleText);

    $dispatch = Env::getOrEmpty('ALERT_COMMAND') === '' ? 'log_only' : 'configured_not_sent';

    $line = json_encode([
        'raised_at'  => date('c'),
        'rule'       => $rule,
        'evidence'   => $evidence,
        'dispatch'   => $dispatch,
        'request_id' => TBOX_REQUEST_ID,
        'origin'     => $origin,
        'title'      => $title,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        throw new RuntimeException('경보를 직렬화할 수 없습니다');
    }

    // 경보 로그는 감사 로그와 같은 디렉터리(0750 tbox-php:adm)에 둔다. 파일이 없으면 만든다.
    $path = dirname(Env::get('AUDIT_LOG_FILE')) . '/alerts.log';
    if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('경보 로그에 쓸 수 없습니다: ' . $path);
    }

    Audit::allow('alert.raised', [
        'target_type' => 'system',
        'target_name' => $rule,
        'detail'      => ['rule' => $rule, 'evidence' => $evidence, 'origin' => $origin],
    ]);
}

/**
 * 감염 파일을 옮겨 두는 격리 폴더 — **한 곳** (PRD F-54 ②, F-69 C-2).
 * 저장소(`STORAGE_DIR`)의 **형제** `quarantine` 이다 — Storage::tmpDir() 와 같은 규칙.
 * 운영에서는 `/var/lib/tbox-v2/storage` 의 부모라 옛 상수 `/var/lib/tbox-v2/quarantine` 과 같은 경로이고,
 * 임시 인스턴스(STORAGE_DIR=임시 폴더)에서는 그 옆을 가리켜 격리·정합성 흐름을 운영 밖에서 시험할 수 있다.
 * 쓰는 곳: scan_pending.php(옮기기) · consistency_check.php(대조 — 읽기만).
 * realpath 를 하지 않는다 — 폴더가 없으면 부르는 쪽이 is_dir() 로 알아채야 한다(scan_pending 은 `quarantine_missing`).
 * 경로에 요청 값이 섞이지 않는다(설정값 + 고정 이름).
 */
function cli_quarantine_dir(): string
{
    return dirname(Storage::baseDir()) . '/quarantine';
}
