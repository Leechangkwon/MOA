<?php
declare(strict_types=1);

/**
 * scripts/review_account_setup.php — claude_ 검토 계정의 **첫 로그인 절차**를 창구로 마친다
 *                                     (F-71 B · PRD F-16 · F-52 · SECURITY R-33 · R-45 · R-48)
 *
 * 왜 있나
 *   seed_users.php 가 새로 만든 계정은 `must_change_password`·`mfa_setup_required` 가 켜져 있다(F-16).
 *   qa_ 계정 때는 사람이 손으로 마쳤다(STAGES 11단계). claude_ 5개는 이 명령이 **운영 창구를 그대로 타서**
 *   마친다 — DB 를 직접 고치지 않는다(F-71 ① 「psql 로 users 에 직접 INSERT/UPDATE 금지」).
 *
 * 한 계정의 순서 (지시서 B 표)
 *   1  POST /tbox/api/login          {username, password: 임시}          실패하면 그 자리에서 멈춘다(재시도 0)
 *   2  POST /tbox/api/me/password    {current: 임시, new: 무작위 16바이트 hex}
 *        → **곧바로** seed-accounts.txt 의 그 계정 줄을 새 비밀번호로 바꾼다(아래 ★ 순서)
 *   3  POST /tbox/api/me/mfa/enroll  {}  → 비밀(secret) — 응답 본문은 메모리에서만
 *   4  src/Totp.php 로 코드 계산(명령 인자로 넘기지 않는다) → POST /tbox/api/me/mfa/confirm {otp}
 *        → qa-totp.txt 에 `username secret` 한 줄(파일 이름은 그대로 — 시험 20여 개가 이 이름을 읽는다)
 *   5  (위 2·4 의 두 파일 쓰기가 B 표의 5행이다)
 *   6  POST /tbox/api/logout → **다음 30초 창까지 기다린 뒤** 파일에서 다시 읽은 비밀번호 + 새 코드로 로그인
 *        → GET /tbox/api/me 200 · 역할·팀이 표와 같은가 · 절차 플래그가 다 내려갔나
 *        · Guest 는 GET /tbox/api/files 의 행이 전부 public 인가(D 표 F71-T4) → 로그아웃
 *   같은 창의 코드를 다시 쓰면 `code_reused` 로 막히고(R-45) **실패 1회로 센다** — 그래서 기다린다.
 *   커뮤니티 첫 진입(개인 공간 자동 생성)은 하지 않는다 — 시험이 필요할 때 만든다(지시서 B).
 *
 * ★ 파일 쓰기 순서 — B 표는 5행에 모아 적었지만, 여기서는 **바뀐 값이 생긴 바로 그때** 쓴다.
 *   2 가 성공한 뒤 3·4 에서 멈추면 새 비밀번호는 이 프로세스 메모리에만 있다가 사라지고, 그 계정은
 *   Admin 재발급(F-10) 말고는 되살릴 길이 없다. 그래서 비밀번호는 2 직후, 2단계 비밀은 확정(4) 직후에 쓴다.
 *   확정 전(enroll 만 된 상태)의 비밀은 쓰지 않는다 — 켜지지 않은 비밀을 파일에 남기면 뒤 시험이 헛코드를 보낸다.
 *   다시 실행하면 이어서 한다: qa-totp.txt 에 줄이 있으면 **요청 없이** 건너뛰고, 비밀번호만 바뀐 계정은
 *   파일의 (새) 비밀번호로 들어가 2 를 건너뛴 채 3 부터 한다(`/api/me` 의 두 플래그로 고른다).
 *
 * ★ 지키는 것
 *   · CLI 만(`PHP_SAPI`), root 만(`posix_geteuid() === 0` — 두 파일이 0600 root 다).
 *   · 호스트·포트·경로·파일 경로는 **코드 상수**다. 인자로 받는 것은 계정 이름뿐이고, 그것도 아래
 *     표(claude_ 접두 5개) 안의 것만 — 표 밖이면 요청 하나 없이 사용법 오류로 끝난다.
 *   · 외부 요청 없음 — 127.0.0.1:8082 한 곳, 경로는 허용 목록 7개, 리다이렉트를 따라가지 않는다.
 *   · 비밀(임시·새 비밀번호, 2단계 비밀, 코드, 세션 토큰, CSRF 토큰)은 **표준출력·명령 인자·로그 0**.
 *     화면에 나가는 것은 계정 이름·단계·상태 코드·서버의 고정 오류 문구뿐이다. 비밀이 명령 인자에
 *     실리지 않으므로 `ps`·auth.log(`COMMAND=`)에도 남지 않는다(지시서 금지 패턴).
 *   · 틀린 비밀번호·OTP 를 되풀이해 보내지 않는다(R-48 — 실패 5회 = 10분 잠금). 어느 걸음이든 기대와
 *     다르면 **그 계정에서 곧바로 멈추고 실행 전체를 끝낸다**(종료 코드 1). 다음 계정으로 넘어가지 않는다.
 *     코드 확인 직전 창이 3초 안에 닫히면 다음 창을 기다린다(서버 허용 오차가 0 이라 경계에서 틀린다).
 *   · 파일은 같은 폴더의 임시 파일(0600)에 다 쓴 뒤 rename — 도중에 끊겨도 반쪽 파일이 남지 않는다.
 *     읽기 전에 두 파일이 일반 파일·root 소유·0600 인지 본다(심볼릭 링크 거부).
 *   · 같은 명령이 둘 동시에 돌지 않게 이 스크립트 파일에 flock 을 건다(새 파일을 만들지 않는다).
 *
 * HTTP 를 어떻게 보내나
 *   PHP http 스트림(`stream_context_create` + `fopen`)을 쓴다. cURL(`curl_*`)은 `src/Fetch.php` 한 파일에서만
 *   허용되고(PRD S-05 ⑬), 소켓을 직접 여는 함수·외부 명령 실행은 금지 패턴이다(scripts/security_lint.sh S-05·S-09 규칙).
 *   주소는 상수 `RAS_ORIGIN` + 허용 목록의 경로로만 만든다 — 요청·파일·인자 값이 주소에 섞이지 않는다
 *   (S-05 가 막으려는 「입력이 정한 목적지」가 없다). FPM 은 `allow_url_fopen=off` 라 앱 코드는 이 길을
 *   못 쓰고(deploy/php-fpm-pool.conf), 이 CLI 만 CLI 설정으로 돈다.
 *
 * 사용법
 *   sudo php scripts/review_account_setup.php <계정> [<계정> …]
 *   예) sudo php scripts/review_account_setup.php claude_admin01 claude_manager01 claude_employee01 claude_employee02 claude_guest01
 *   (보통은 적용 스크립트 apply-f71-claude-accounts-<날짜>.sh 가 `sudo -n bash` 한 번 안에서 부른다)
 *
 * 종료 코드
 *   0 전부 끝남(이미 끝난 계정은 건너뜀) · 1 어느 계정에서 멈춤 · 2 사용법 오류 · 3 준비 실패(권한·파일·잠금)
 *
 * 웹으로는 절대 실행되지 않는다 — public/ 밖에 있고(URL 에 없음), 아래에서 SAPI 도 확인한다.
 * src/bootstrap.php(웹 전용)·DB 는 쓰지 않는다 — Totp 한 클래스만 읽는다.
 */

if (PHP_SAPI !== 'cli') {
    // 웹에서 도달할 경로가 없지만, 만들어지더라도 실행되지 않게 한다 (심층 방어).
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Totp.php';

/** 운영 창구의 출발점. 코드 상수 — 인자·환경변수로 바꿀 수 없다 (지시서 B: 127.0.0.1:8082 만) */
const RAS_ORIGIN = 'http://127.0.0.1:8082';
/** t-box base-path (28단계, ARCH 5.31 `TBOX_BASE`) */
const RAS_BASE_PATH = '/tbox';

/** 부를 수 있는 경로 — 이 7개 밖의 경로로는 요청을 만들지 않는다 */
const RAS_PATHS = [
    '/api/login', '/api/me', '/api/me/password', '/api/me/mfa/enroll', '/api/me/mfa/confirm',
    '/api/logout', '/api/files',
];

/** 두 비밀 파일 (지시서 A 머리 — 둘 다 root 0600). 코드 상수 */
const RAS_SEED_FILE = '/etc/tbox-v2/seed-accounts.txt';
const RAS_TOTP_FILE = '/etc/tbox-v2/qa-totp.txt';
/**
 * 보류 파일 (독립 검토 F-71 L-1) — 서버에 새 비밀번호·2단계 비밀을 보내기 **전에** 여기(root 0600)에 먼저 적고, 두 파일에 옮겨
 * 적은 뒤 지운다. 요청은 서버에 닿았는데 응답을 잃어도(제한시간·연결 끊김) 비밀이 메모리와 함께 사라지지 않는다.
 * 이 파일이 남아 있으면 앞 실행이 ②·④ 도중 멈춘 것이다 — 시작하지 않고 손으로 확인하게 한다.
 */
const RAS_PENDING_FILE = '/etc/tbox-v2/.f71-pending';

/** 세션 쿠키 이름 (src/Auth.php `COOKIE_NAME` — v2.31 디버깅 묶음 S2 부터 `__Host-` 접두. 옛 이름은 서버가 읽지 않는다) */
const RAS_COOKIE = '__Host-tbox_session';

/**
 * 받을 수 있는 계정 — 지시서 A 표 그대로(역할 · 팀 이름 · 「public 만 보이나」 대조 여부).
 * **이 표 밖의 이름은 거부한다.** 역할·팀은 6단계 확인(`/api/me`)의 **기대값**이지 권한 판단이 아니다 —
 * 이 스크립트는 역할 문자열로 갈림길을 만들지 않고, Guest 대조도 셋째 칸(표의 상수)으로 고른다.
 *
 * @var array<string,array{0:string,1:?string,2:bool}>
 */
const RAS_ACCOUNTS = [
    'claude_admin01'    => ['Admin',    null,     false],
    'claude_manager01'  => ['Manager',  '개발팀', false],
    'claude_employee01' => ['Employee', '개발팀', false],
    'claude_employee02' => ['Employee', '영업팀', false],
    'claude_guest01'    => ['Guest',    null,     true],
];

/** 응답 본문 상한(바이트) — 이 창구들의 응답은 몇 KB 다 */
const RAS_MAX_BODY = 1048576;

/** 코드를 보내기 전 창이 이만큼(초) 안 남았으면 다음 창을 기다린다 */
const RAS_EDGE_SECONDS = 3;

// ─── 출력 (비밀을 담지 않는 문장만) ─────────────────────────────────

function ras_say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}

function ras_usage(string $why): void
{
    fwrite(STDERR, '[사용법 오류] ' . $why . "\n");
    fwrite(STDERR, "사용법: sudo php scripts/review_account_setup.php <계정> [<계정> …]\n");
    fwrite(STDERR, '  계정: ' . implode(' · ', array_keys(RAS_ACCOUNTS)) . "\n");
}

/** 준비 단계 실패 — 요청을 하나도 보내기 전 */
function ras_prepare_fail(string $why): never
{
    fwrite(STDERR, '[준비 실패] ' . $why . "\n");
    exit(3);
}

/** 계정 절차 중 멈춤 — 한 번 실패하면 되풀이하지 않는다(R-48) */
final class RasStop extends RuntimeException
{
}

// ─── 인자 ────────────────────────────────────────────────────────

$args = array_slice($_SERVER['argv'] ?? [], 1);
if ($args === []) {
    ras_usage('계정 이름을 하나 이상 주세요');
    exit(2);
}
$targets = [];
foreach ($args as $arg) {
    if (!is_string($arg) || !array_key_exists($arg, RAS_ACCOUNTS)) {
        // 받은 글자를 되찍지 않는다 — 인자 자리에 무엇이 들어왔든 화면에 남기지 않는다.
        ras_usage('표 밖의 이름이 있습니다 (claude_ 접두 5개만 받습니다)');
        exit(2);
    }
    if (in_array($arg, $targets, true)) {
        ras_usage('같은 계정을 두 번 주었습니다');
        exit(2);
    }
    $targets[] = $arg;
}

// ─── 준비 — 권한·설정·파일·잠금 (요청 0) ──────────────────────────

if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    ras_prepare_fail('root 로 실행해야 합니다 (두 비밀 파일이 0600 root)');
}
if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    ras_prepare_fail('CLI 의 allow_url_fopen 이 꺼져 있어 창구를 부를 수 없습니다');
}
umask(0077);

$lock = fopen(__FILE__, 'rb');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    ras_prepare_fail('같은 명령이 이미 돌고 있습니다');
}

/**
 * 비밀 파일이 일반 파일·root 소유·0600 인가 (심볼릭 링크 거부). 절차 도중(읽기·바꿔 끼운 뒤)에도
 * 부르므로 RasStop 을 던진다 — 준비 단계에서는 아래 「실행」이 받아 종료 코드 3 으로 끝낸다.
 */
function ras_check_secret_file(string $path): void
{
    clearstatcache(true, $path);
    if (is_link($path) || !is_file($path)) {
        throw new RasStop(basename($path) . ' 이 일반 파일이 아니다');
    }
    $st = stat($path);
    if ($st === false || $st['uid'] !== 0 || ($st['mode'] & 0777) !== 0600) {
        throw new RasStop(basename($path) . ' 이 root 소유 0600 이 아니다');
    }
}

/** 파일 전체를 읽는다 (비밀이 든 파일 — 내용은 어디에도 찍지 않는다) */
function ras_read(string $path): string
{
    ras_check_secret_file($path);
    $raw = file_get_contents($path);
    if (!is_string($raw)) {
        throw new RasStop(basename($path) . ' 을 읽지 못했다');
    }
    return $raw;
}

/**
 * 한 줄에 한 계정인 파일에서 그 계정의 칸들을 찾는다. 정확히 한 줄이어야 한다.
 *
 * @return list<string>|null  칸 배열(0 = 이름). 줄이 없으면 null
 */
function ras_find_line(string $raw, string $username, int $fields, string $fileLabel): ?array
{
    $found = null;
    foreach (explode("\n", $raw) as $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (!is_array($cols) || $cols[0] !== $username) {
            continue;
        }
        if ($found !== null) {
            throw new RasStop($fileLabel . ' 에 ' . $username . ' 줄이 둘 이상 있다 — 손으로 확인');
        }
        if (count($cols) !== $fields) {
            throw new RasStop($fileLabel . ' 의 ' . $username . ' 줄 칸 수가 ' . $fields . ' 가 아니다');
        }
        $found = $cols;
    }
    return $found;
}

/**
 * 같은 폴더의 임시 파일(0600)에 전부 쓴 뒤 rename 으로 바꿔 끼운다.
 * 도중에 끊기면 원래 파일이 그대로 남는다(반쪽 파일 없음).
 */
function ras_replace_file(string $path, string $content): void
{
    $dir = dirname($path);
    $tmp = tempnam($dir, '.f71-' . basename($path) . '.');
    if ($tmp === false || dirname($tmp) !== $dir) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RasStop(basename($path) . ' 의 임시 파일을 같은 폴더에 만들지 못했다');
    }
    $ok = false;
    try {
        if (!chmod($tmp, 0600)) {
            throw new RasStop('임시 파일 권한을 0600 으로 두지 못했다');
        }
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new RasStop('임시 파일을 열지 못했다');
        }
        $written = fwrite($fh, $content);
        $synced  = fflush($fh) && fsync($fh);
        fclose($fh);
        if ($written !== strlen($content) || !$synced) {
            throw new RasStop('임시 파일에 다 쓰지 못했다');
        }
        if (!rename($tmp, $path)) {
            throw new RasStop(basename($path) . ' 을 바꿔 끼우지 못했다');
        }
        $ok = true;
    } finally {
        if (!$ok) {
            @unlink($tmp);
        }
    }
    ras_check_secret_file($path);
}

/** 보류 파일에 한 줄(`username kind value`) — 요청을 보내기 전에 부른다 */
function ras_pending_put(string $username, string $kind, string $value): void
{
    ras_replace_file(RAS_PENDING_FILE, $username . ' ' . $kind . ' ' . $value . "\n");
}

/** 두 파일에 옮겨 적은 뒤 보류 파일을 지운다 */
function ras_pending_clear(): void
{
    if (is_file(RAS_PENDING_FILE) && !unlink(RAS_PENDING_FILE)) {
        throw new RasStop('보류 파일을 지우지 못했다 — 손으로 지운다');
    }
}

/** seed-accounts.txt 의 그 계정 줄 셋째 칸(비밀번호)을 바꾼다. 다른 줄은 한 글자도 건드리지 않는다 */
function ras_seed_set_password(string $username, string $role, string $newPassword): void
{
    $raw   = ras_read(RAS_SEED_FILE);
    $lines = explode("\n", $raw);
    $hits  = 0;
    foreach ($lines as $i => $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (is_array($cols) && $cols[0] === $username) {
            $lines[$i] = $username . ' ' . $role . ' ' . $newPassword;
            $hits++;
        }
    }
    if ($hits !== 1) {
        throw new RasStop('seed-accounts.txt 에서 ' . $username . ' 줄을 하나로 찾지 못했다');
    }
    ras_replace_file(RAS_SEED_FILE, implode("\n", $lines));
}

/** qa-totp.txt 끝에 `username secret` 한 줄을 붙인다(이미 있으면 멈춘다) */
function ras_totp_append(string $username, string $secret): void
{
    $raw = ras_read(RAS_TOTP_FILE);
    if (ras_find_line($raw, $username, 2, 'qa-totp.txt') !== null) {
        throw new RasStop('qa-totp.txt 에 ' . $username . ' 줄이 이미 있다 — 손으로 확인');
    }
    if ($raw !== '' && !str_ends_with($raw, "\n")) {
        $raw .= "\n";
    }
    ras_replace_file(RAS_TOTP_FILE, $raw . $username . ' ' . $secret . "\n");
}

// ─── HTTP (127.0.0.1:8082 한 곳, 경로 허용 목록, 리다이렉트 안 따라감) ─────────────

/**
 * 요청 한 번. 실패해도 **다시 보내지 않는다** — 되풀이 판단은 부르는 쪽도 하지 않는다.
 *
 * @param array<string,mixed>|null $json  보낼 JSON 본문(null 이면 본문 없음)
 * @param array{session:?string,csrf:?string,ua:string} $s  세션 상태(쿠키는 응답에서 갱신된다)
 * @return array{status:int, body:?array<string,mixed>}
 */
function ras_request(string $method, string $path, ?array $json, array &$s): array
{
    if (!in_array($path, RAS_PATHS, true) || !in_array($method, ['GET', 'POST'], true)) {
        throw new LogicException('허용 목록 밖의 요청');
    }
    $headers = ['Accept: application/json'];
    $content = '';
    if ($json !== null) {
        $content   = json_encode($json === [] ? new stdClass() : $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }
    if ($s['session'] !== null) {
        $headers[] = 'Cookie: ' . RAS_COOKIE . '=' . $s['session'];
    }
    if ($s['csrf'] !== null && $method === 'POST') {
        $headers[] = 'X-CSRF-Token: ' . $s['csrf'];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'          => $method,
            'header'          => implode("\r\n", $headers),
            'content'         => $content,
            'user_agent'      => $s['ua'],
            'ignore_errors'   => true,   // 4xx·5xx 도 본문을 읽는다(상태 코드로 판단)
            'follow_location' => 0,      // 리다이렉트를 따라가지 않는다 — 목적지는 늘 이 한 곳
            'timeout'         => 20,
        ],
    ]);
    $fh = @fopen(RAS_ORIGIN . RAS_BASE_PATH . $path, 'rb', false, $ctx);
    unset($content, $headers);
    if ($fh === false) {
        throw new RasStop($method . ' ' . RAS_BASE_PATH . $path . ' — 창구에 닿지 못했다');
    }
    $raw  = stream_get_contents($fh, RAS_MAX_BODY);
    $meta = stream_get_meta_data($fh);
    fclose($fh);

    $status = 0;
    $wrapper = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];
    foreach ($wrapper as $h) {
        if (!is_string($h)) {
            continue;
        }
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $h, $m) === 1) {
            $status = (int) $m[1];
        } elseif (preg_match('/^Set-Cookie:\s*' . preg_quote(RAS_COOKIE, '/') . '=([^;]*)/i', $h, $m) === 1) {
            // 로그인은 새 토큰, 로그아웃은 빈 값(지우기)이다.
            $s['session'] = preg_match('/^[0-9a-f]{64}$/', $m[1]) === 1 ? $m[1] : null;
        }
    }
    $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    unset($raw);
    return ['status' => $status, 'body' => is_array($body) ? $body : null];
}

/** 서버의 고정 오류 문구(비밀 없음)를 짧게 */
function ras_err(array $r): string
{
    $e = is_array($r['body']) && is_string($r['body']['error'] ?? null) ? (string) $r['body']['error'] : '';
    // mbstring 이 없는 서버다(STAGES 24단계) — UTF-8 80글자는 정규식으로 자른다.
    if ($e !== '' && preg_match('/^.{0,80}/us', $e, $m) === 1) {
        $e = $m[0];
    } else {
        $e = '';
    }
    return 'HTTP ' . $r['status'] . ($e !== '' ? ' 「' . $e . '」' : '');
}

/** GET /api/me — 200 이어야 하고 CSRF 토큰을 세션 상태에 담는다 */
function ras_me(array &$s, string $username): array
{
    $r = ras_request('GET', '/api/me', null, $s);
    if ($r['status'] !== 200 || !is_array($r['body']) || ($r['body']['username'] ?? null) !== $username) {
        throw new RasStop('GET /api/me 가 기대와 다르다 (' . ras_err($r) . ')');
    }
    $csrf = $r['body']['csrf_token'] ?? null;
    if (!is_string($csrf) || preg_match('/^[0-9a-f]{64}$/', $csrf) !== 1) {
        throw new RasStop('GET /api/me 에 CSRF 토큰이 없다');
    }
    $s['csrf'] = $csrf;
    return $r['body'];
}

/** 세션을 닫는다. 이 요청은 자격 증명 시도가 아니라 실패로 세지 않는다 — 실패해도 멈춤 사유로만 적는다 */
function ras_logout(array &$s): bool
{
    if ($s['session'] === null || $s['csrf'] === null) {
        return false;
    }
    try {
        $r = ras_request('POST', '/api/logout', [], $s);
    } catch (RasStop $e) {
        return false;
    }
    $s['session'] = null;
    $s['csrf']    = null;
    return $r['status'] === 200;
}

/** 지금 창이 RAS_EDGE_SECONDS 안에 닫히면 다음 창 1초 뒤까지 기다린다 */
function ras_avoid_window_edge(): void
{
    $left = Totp::PERIOD - (time() % Totp::PERIOD);
    if ($left <= RAS_EDGE_SECONDS) {
        ras_say('    · 2단계 창이 ' . $left . '초 남아 다음 창까지 기다림');
        sleep($left + 1);
    }
}

/** $usedStep 보다 뒤의 창이 될 때까지 기다린다(같은 창 코드 재사용 = code_reused, R-45) */
function ras_wait_after_step(int $usedStep): void
{
    $target = ($usedStep + 1) * Totp::PERIOD + 1;
    $wait   = $target - time();
    if ($wait > 0) {
        ras_say('    · 쓴 코드의 창이 닫힐 때까지 ' . $wait . '초 기다림 (R-45)');
        sleep($wait);
    }
    if (intdiv(time(), Totp::PERIOD) <= $usedStep) {
        throw new RasStop('시계가 기다린 만큼 가지 않았다 — 코드를 보내지 않는다');
    }
    ras_avoid_window_edge();
}

// ─── 한 계정 ──────────────────────────────────────────────────────

/**
 * @return string 'done' | 'skipped'
 */
function ras_setup_account(string $username, string $ua): string
{
    [$wantRole, $wantTeam, $publicOnly] = RAS_ACCOUNTS[$username];
    $s = ['session' => null, 'csrf' => null, 'ua' => $ua];

    // 0. 이미 끝났나 — qa-totp.txt 에 줄이 있으면 요청을 하나도 보내지 않는다.
    if (ras_find_line(ras_read(RAS_TOTP_FILE), $username, 2, 'qa-totp.txt') !== null) {
        ras_say('  ' . $username . ' — qa-totp.txt 에 이미 줄이 있다: 끝난 계정으로 보고 건너뜀 (요청 0)');
        return 'skipped';
    }
    $seed = ras_find_line(ras_read(RAS_SEED_FILE), $username, 3, 'seed-accounts.txt');
    if ($seed === null) {
        throw new RasStop('seed-accounts.txt 에 ' . $username . ' 줄이 없다 (seed_users.php --claude-only 먼저)');
    }
    if ($seed[1] !== $wantRole) {
        throw new RasStop('seed-accounts.txt 의 역할이 표와 다르다');
    }
    $password = $seed[2];
    unset($seed);

    try {
        // 1. 로그인 — 임시(또는 앞 실행이 바꿔 둔) 비밀번호. 코드 없이.
        $r = ras_request('POST', '/api/login', ['username' => $username, 'password' => $password], $s);
        if ($r['status'] !== 200 || ($r['body']['ok'] ?? null) !== true || $s['session'] === null) {
            // `mfa_required` 는 401 에서만 「코드가 필요하다」는 뜻이다(200 에서는 역할상 필수 — F-52, 독립 검토 F-71 L-12)
            if ($r['status'] === 401 && ($r['body']['mfa_required'] ?? null) === true) {
                throw new RasStop('이미 2단계가 켜져 있는데 qa-totp.txt 에 줄이 없다 — 손으로 확인 (재시도하지 않음)');
            }
            throw new RasStop('① 로그인 실패 (' . ras_err($r) . ') — 재시도하지 않음');
        }
        ras_say('  ' . $username . ' ① 로그인 200 · next=' . var_export($r['body']['next'] ?? null, true));
        $me = ras_me($s, $username);

        // 2. 비밀번호 바꾸기 — 강제 절차 중일 때만(앞 실행이 이미 바꿨으면 건너뛴다).
        if (($me['must_change_password'] ?? null) === true) {
            $new = bin2hex(random_bytes(16));   // 무작위 16바이트 hex = 32자 (S-09-4 최소 10자)
            if ($new === $password) {
                throw new RasStop('새 비밀번호가 옛것과 같다(있을 수 없는 일) — 보내지 않는다');
            }
            ras_pending_put($username, 'password', $new);   // 보내기 전에 — 응답을 잃어도 새 비밀번호가 남는다(L-1)
            $r = ras_request('POST', '/api/me/password', ['current' => $password, 'new' => $new], $s);
            if ($r['status'] !== 200 || ($r['body']['ok'] ?? null) !== true) {
                throw new RasStop('② 비밀번호 변경 실패 (' . ras_err($r) . ') — 재시도하지 않음 · 보류 파일에 새 비밀번호가 남았다 '
                    . '(서버에 반영됐는지 손으로 확인한 뒤 지운다)');
            }
            // ★ 곧바로 파일에 — 여기서 멈추면 새 비밀번호를 잃는다(머리 주석 「파일 쓰기 순서」).
            try {
                ras_seed_set_password($username, $wantRole, $new);
            } catch (RasStop $e) {
                throw new RasStop('② 서버의 비밀번호는 바뀌었는데 seed-accounts.txt 에 적지 못했다 — '
                    . 'Admin 재발급(F-10)이 필요하다 (' . $e->getMessage() . ')');
            }
            ras_pending_clear();
            $password = $new;
            unset($new);
            ras_say('  ' . $username . ' ② 비밀번호 변경 200 · seed-accounts.txt 의 그 줄을 바꿈 (임시 비밀번호 수명 끝, R-33)');
            $me = ras_me($s, $username);
        } else {
            ras_say('  ' . $username . ' ② 비밀번호는 이미 바뀌어 있다 — 건너뜀');
        }

        // 3·4. 2단계 등록 → 확정.
        if (($me['mfa_enabled'] ?? null) === true) {
            throw new RasStop('2단계가 이미 켜져 있는데 qa-totp.txt 에 줄이 없다 — 손으로 확인');
        }
        $r = ras_request('POST', '/api/me/mfa/enroll', [], $s);
        $secret = is_array($r['body']) ? ($r['body']['secret'] ?? null) : null;
        unset($r['body']);
        if ($r['status'] !== 200 || !is_string($secret) || preg_match(Totp::SECRET_RE, $secret) !== 1) {
            throw new RasStop('③ 2단계 등록 실패 (HTTP ' . $r['status'] . ') — 재시도하지 않음');
        }
        ras_say('  ' . $username . ' ③ 2단계 등록 200 (비밀은 메모리에만)');

        ras_avoid_window_edge();
        $now      = time();
        $usedStep = intdiv($now, Totp::PERIOD);
        $code     = Totp::code($secret, $now);
        ras_pending_put($username, 'totp', $secret);   // 확정을 보내기 전에(L-1)
        $r = ras_request('POST', '/api/me/mfa/confirm', ['otp' => $code], $s);
        unset($code);
        if ($r['status'] !== 200 || ($r['body']['ok'] ?? null) !== true) {
            throw new RasStop('④ 2단계 확정 실패 (' . ras_err($r) . ') — 재시도하지 않음(실패 1회로 세였을 수 있다)');
        }
        try {
            ras_totp_append($username, $secret);
        } catch (RasStop $e) {
            throw new RasStop('④ 2단계는 켜졌는데 qa-totp.txt 에 적지 못했다 — Admin 의 2단계 초기화(F-16)가 필요하다 ('
                . $e->getMessage() . ')');
        }
        ras_pending_clear();
        unset($secret);
        ras_say('  ' . $username . ' ④ 2단계 확정 200 · qa-totp.txt 에 한 줄 덧붙임');

        // 6. 로그아웃 → 다음 창 → 파일에서 **다시 읽은** 값으로 로그인(파일이 맞는지까지 확인한다).
        if (!ras_logout($s)) {
            throw new RasStop('⑥ 로그아웃이 200 이 아니다');
        }
        unset($password);
        ras_wait_after_step($usedStep);

        $seed = ras_find_line(ras_read(RAS_SEED_FILE), $username, 3, 'seed-accounts.txt');
        $totp = ras_find_line(ras_read(RAS_TOTP_FILE), $username, 2, 'qa-totp.txt');
        if ($seed === null || $totp === null || preg_match(Totp::SECRET_RE, $totp[1]) !== 1) {
            throw new RasStop('⑥ 두 파일에서 그 계정 줄을 다시 읽지 못했다');
        }
        $code = Totp::code($totp[1], time());
        $r = ras_request('POST', '/api/login', ['username' => $username, 'password' => $seed[2], 'otp' => $code], $s);
        unset($seed, $totp, $code);
        if ($r['status'] !== 200 || ($r['body']['ok'] ?? null) !== true || $s['session'] === null) {
            throw new RasStop('⑥ 새 비밀번호 + 새 코드 로그인 실패 (' . ras_err($r) . ') — 재시도하지 않음');
        }
        // `??` 는 null 값도 「없음」으로 보므로 키가 있고 값이 null 인지를 따로 본다.
        $next = is_array($r['body']) && array_key_exists('next', $r['body']) ? $r['body']['next'] : 'missing';
        if ($next !== null) {
            throw new RasStop('⑥ 로그인은 됐지만 절차가 남아 있다 (next=' . var_export($next, true) . ')');
        }
        $me = ras_me($s, $username);
        $gotTeam = $me['team_name'] ?? null;
        if (($me['role'] ?? null) !== $wantRole || $gotTeam !== $wantTeam) {
            throw new RasStop('⑥ 역할·팀이 표와 다르다 (역할 ' . var_export($me['role'] ?? null, true)
                . ' · 팀 ' . var_export($gotTeam, true) . ')');
        }
        if (($me['must_change_password'] ?? null) !== false || ($me['mfa_setup_required'] ?? null) !== false
            || ($me['mfa_enabled'] ?? null) !== true) {
            throw new RasStop('⑥ 절차 플래그가 다 내려가지 않았다');
        }
        ras_say('  ' . $username . ' ⑥ 다시 로그인 200 · GET /api/me 200 · 역할 ' . $wantRole
            . ' · 팀 ' . ($wantTeam ?? '없음') . ' · 2단계 켜짐 · 절차 끝');

        // F71-T4 — Guest 는 public 만 보여야 한다. 역할 판단이 아니라 **응답 대조**다(판정은 서버 Authz).
        if ($publicOnly) {
            $r = ras_request('GET', '/api/files', null, $s);
            $files = is_array($r['body']) && is_array($r['body']['files'] ?? null) ? $r['body']['files'] : null;
            if ($r['status'] !== 200 || $files === null) {
                throw new RasStop('⑥ Guest 목록 GET /api/files 가 200 이 아니다 (' . ras_err($r) . ')');
            }
            $levels = [];
            foreach ($files as $f) {
                $levels[] = is_array($f) && is_string($f['access_level'] ?? null) ? $f['access_level'] : '?';
            }
            $notPublic = count(array_filter($levels, static fn (string $l): bool => $l !== 'public'));
            ras_say('  ' . $username . ' ⑥ Guest 목록(루트 1쪽) ' . count($files) . '행 · 전체 '
                . (int) ($r['body']['total'] ?? -1) . '건 · 등급 ' . (implode(',', array_unique($levels)) ?: '없음')
                . ' · public 아닌 행 ' . $notPublic);
            if ($notPublic !== 0) {
                throw new RasStop('⑥ Guest 에게 public 이 아닌 행이 보인다 — 멈춘다');
            }
        }

        if (!ras_logout($s)) {
            throw new RasStop('⑥ 마지막 로그아웃이 200 이 아니다');
        }
        return 'done';
    } catch (RasStop $e) {
        // 열린 세션이 있으면 닫고(자격 증명 시도가 아니다) 그대로 멈춘다.
        ras_logout($s);
        throw $e;
    }
}

// ─── 실행 ────────────────────────────────────────────────────────

try {
    ras_check_secret_file(RAS_SEED_FILE);
    ras_check_secret_file(RAS_TOTP_FILE);
    // 보류 파일이 남아 있으면 앞 실행이 ②·④ 도중 멈춘 것이다 — 이어 하지 않는다(무엇이 서버에 들어갔는지 사람이 먼저 본다, L-1)
    if (file_exists(RAS_PENDING_FILE) || is_link(RAS_PENDING_FILE)) {
        throw new RasStop(RAS_PENDING_FILE . ' 가 남아 있다 — 앞 실행이 비밀번호 변경·2단계 확정 도중 멈췄다. '
            . '그 값이 서버에 들어갔는지 확인해 두 파일에 옮겨 적은 뒤 지우고 다시 돌린다');
    }
} catch (RasStop $e) {
    ras_prepare_fail($e->getMessage());
}

$ua = 'tbox-review/' . date('Y-m-d');
ras_say('== F-71 검토 계정 첫 로그인 절차 — ' . RAS_ORIGIN . RAS_BASE_PATH . ' · UA ' . $ua);
ras_say('   대상: ' . implode(' ', $targets));

$done = 0;
$skipped = 0;
foreach ($targets as $username) {
    try {
        $result = ras_setup_account($username, $ua);
    } catch (RasStop $e) {
        fwrite(STDERR, '[멈춤] ' . $username . ' — ' . $e->getMessage() . "\n");
        fwrite(STDERR, '       이 계정에서 멈추고 다음 계정으로 넘어가지 않는다 (R-48). 끝난 계정 ' . $done
            . ' · 건너뜀 ' . $skipped . "\n");
        exit(1);
    } catch (Throwable $e) {
        // 예외 문구에 무엇이 섞였을지 모르므로 우리 문장만 낸다.
        fwrite(STDERR, '[멈춤] ' . $username . ' — 예상하지 못한 오류 (' . get_class($e) . ')' . "\n");
        exit(1);
    }
    if ($result === 'done') {
        $done++;
    } else {
        $skipped++;
    }
}

ras_say('== 끝 — 절차를 마친 계정 ' . $done . ' · 이미 끝나 건너뜀 ' . $skipped);
exit(0);
