<?php
declare(strict_types=1);

/**
 * src/handlers/files_zip.php — POST /api/files/zip (정책: file.read · CSRF · 세션만)
 *
 * 개발 대기열 6 · Q:F-3a 라운드 C — 고른 파일 여럿을 zip 하나로 받는다
 * (지시서 ../docs/qf3a-favorites-recent-bulk-directive.md 1.4 · 3장 QF3A-T8 · 결정 M-5, ARCH 5.34).
 *
 * ★ 새 판정 0 — 파일마다 **한 건 받기와 같은 함수**(`_download_common.php`)를 부른다:
 *   행 읽기 → [권한 ✔] `Authz::decideRead` → [3-5] F-65 실행 파일이 든 압축본 → [4] F-54 검사 중·격리 → 무결성(바이트·sha256).
 *   한 건 받기가 그 자리에서 403·423 으로 끝내는 파일을 여기서는 **그 파일만 뺀다**. 거부 행(`file.download deny <사유>`)은
 *   한 건 받기와 같은 모양으로 파일마다 남고(detail `via: "zip"` 한 칸만 더), 응답에는 **뺀 개수만** 간다(`skipped`) —
 *   이름·사유·존재 여부는 응답에 한 글자도 없다(PRD S-02 · S-09-5). 실행 파일 압축본은 받을 때마다 비밀번호·2단계 코드가
 *   필요한 파일이라(F-65) zip 에는 넣지 않는다 — 관리자도 한 건씩 「확인하고 받기」로.
 *
 * 순서 (바꾸지 않는다)
 *   [1] 본문 `{ids:[UUID…]}` — 모르는 키·형식 오류 400 · 101개 이상 400(이유 문장) · 같은 id 는 하나로
 *   [2] 사람마다 한 번에 하나 — 잠금(`flock`, 기다리지 않음). 이미 만드는 중이면 429 + Retry-After
 *   [3] 파일마다 행 → 세 관문(거부 행은 관문이 남긴다) — 없는 id·지운 파일은 한 건 받기처럼 감사 없이 뺀다
 *   [4] 남은 게 없으면 403(뺀 개수) · 합계(남은 파일의 DB 크기)가 500MB 를 넘으면 400(이유 문장)
 *       ★ 합계는 **통과한 파일만** 더한다 — 못 보는 파일의 크기가 400/200 갈림으로 새지 않게
 *   [5] 사이트 전체 동시 zip 두 개(슬롯 잠금) · 남은 공간 = 2 × 합계 + **다른 슬롯이 잡아 둔 몫** + 1GB 이상(zip 사본 + nginx 가
 *       응답을 받아 두는 임시 파일, 그 뒤에도 1GB — SECURITY R-80 디스크 고갈 → DB PANIC) · 오래된 zip 임시 파일 치우기.
 *       ★ 검토 L-C1: 잡은 슬롯 잠금 파일에 **내 최악 크기(2 × 합계)** 를 적고, 쥐어져 있는 다른 슬롯의 값을 더한다(비었거나 못 읽으면
 *         최악 2 × 500MB) — 동시에 도는 zip 둘이 같은 여유를 두 번 쓰지 않게. 쥐어져 있지 않은 슬롯 파일의 옛 값은 보지 않는다
 *   [6] 무결성 — 파일마다 바이트·sha256(실패는 `file.download error` 행 + 그 파일만 뺌 · AL-6)
 *   [7] 임시 파일(앱 임시 폴더 `Storage::tmpDir()` — 0700 tbox-php · 파일 0600)에 `ZipArchive` 로 묶는다(압축 없이 담기 —
 *       시간·CPU 가 크기에 비례하고 예측된다). 안 이름은 **파일 이름만**(F-05 정리 규칙 + zip 한 겹 — 아래 함수), 같은 이름은 `이름 (2).확장자`
 *   [8] 감사 — 파일마다 `file.download allow`(detail `via: "zip"` · `granted_by` 등 한 건 받기와 같은 칸) + 묶음 한 줄 `file.zip allow`.
 *       ★ 검토 L-C2: 그 직전에 요청 시작부터 걸린 시간을 본다 — `TBOX_ZIP_TIME_BUDGET`(50초)을 넘었으면 nginx 가 60초에 504 를 줬을
 *         수 있으니 allow 행 없이 `file.zip error zip_failed`(why `time`)로 끝낸다(받지 못했는데 「받음」이 남지 않게)
 *   [9] 전송 — attachment(이름은 서버가 만든 ASCII · RFC 5987 모양은 한 건 받기와 같다) · 개수 머리 `X-Zip-Count`·`X-Zip-Skipped` ·
 *       readfile 뒤 임시 파일을 지운다. 어디서 끝나든(오류·예외·손님이 끊음) shutdown 함수가 한 번 더 지운다.
 *       FPM 이 워커를 죽이면(90초) shutdown 도 돌지 않는다 — 다음 zip 요청이 10분 지난 `zip-*` 를 치운다([5]).
 *
 * 토큰(프로그램) 창구가 아니다(결정 M-5) — `TBOX_API_TOKEN_ROUTES` 에 없으므로 `X-API-Token` 은 읽히지도 않고 세션 경로다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자(세션)
 * @var list<string>        $params (없음)
 */

require_once __DIR__ . '/_download_common.php';

/** 한 번에 받을 수 있는 파일 수 (결정 M-5 · M-4 의 고르기 한도와 같다) */
const TBOX_ZIP_MAX_FILES = 100;
/** 합계 상한 500MB — 업로드 한도(Upload::MAX_BYTES = 100MB = 104,857,600 B)와 같은 단위(MiB) */
const TBOX_ZIP_MAX_BYTES = 524288000;
/** 사이트 전체에서 동시에 만들 수 있는 zip 수 — FPM 워커 8 중 둘까지만 오래 붙잡는다 */
const TBOX_ZIP_SLOTS = 2;
/** zip 을 다 만들고 nginx 가 응답을 받아 둔 뒤에도 남아야 하는 공간 (R-80 — 1GB 미만이면 시험도 시작하지 않는다) */
const TBOX_ZIP_DISK_RESERVE = 1073741824;
/** 이보다 오래된 `zip-*` 임시 파일은 주인 요청이 죽은 것이다(FPM request_terminate_timeout 90s) */
const TBOX_ZIP_STALE_SECONDS = 600;
/** 429 뒤 다시 해 볼 때까지(초) */
const TBOX_ZIP_RETRY_AFTER = 10;
/**
 * [8] 감사 직전 시간 한도(초) — 요청 시작부터. nginx `fastcgi_read_timeout 60s`(첫 바이트까지) 앞에서 멈춘다: 넘었으면 손님은 이미 504 를
 * 받았거나 곧 받는다 — 파일마다 「받음」 행을 남기지 않는다(검토 L-C2). FPM `request_terminate_timeout 90s` 보다도 앞이다.
 * 시험은 이 줄을 낮춘 **사본 인스턴스**로 이 갈래를 본다(tests/qf3c_zip.sh — 코드에 시험용 갈래를 두지 않는다).
 */
const TBOX_ZIP_TIME_BUDGET = 50;

const TBOX_ZIP_MSG_TOO_MANY  = '한 번에 100개까지 zip 으로 받을 수 있습니다 — 나눠서 받으세요';
const TBOX_ZIP_MSG_TOO_LARGE = '고른 파일의 합계가 500MB 를 넘습니다 — 나눠서 받으세요';
const TBOX_ZIP_MSG_NOTHING   = 'zip 에 넣을 수 있는 파일이 없습니다 — 권한이 없거나 검사 중이거나 실행 파일이 든 압축본은 넣지 않습니다';
const TBOX_ZIP_MSG_BUSY      = '다른 zip 을 만드는 중입니다 — 잠시 뒤 다시 하세요';
const TBOX_ZIP_MSG_NO_SPACE  = '지금은 서버의 임시 공간이 모자라 zip 을 만들 수 없습니다 — 나눠서 받거나 잠시 뒤 다시 하세요';
const TBOX_ZIP_MSG_TOO_SLOW  = 'zip 을 만드는 데 시간이 너무 걸려 멈췄습니다 — 나눠서 받거나 잠시 뒤 다시 하세요';

/**
 * zip 안 이름 — **파일 이름만**. 경로·상위 폴더·절대 경로·드라이브·제어 문자가 풀 때 경로로 읽히지 않게 한다.
 *
 * 앞 넷은 업로드 [2] 의 F-05 정리 규칙과 같은 순서다(`_upload_common.php`): `\` → `/` 로 바꿔 마지막 조각만 · 제어 문자·CR/LF 지우기 ·
 * 앞뒤 공백 · 255자. `basename()` 대신 마지막 `/` 뒤를 자른다 — 결과는 같고, `basename()` 은 로캘에 따라 여러 바이트 글자를
 * 다르게 자를 수 있다(여기는 바이트 `/` 만 본다 — UTF-8 안에 0x2F 바이트는 글자 일부로 나오지 않는다).
 * zip 만의 한 겹: `:` → `_`(Windows 가 풀 때 `C:이름`·`이름:흐름` 을 드라이브·대체 데이터 흐름으로 읽는다) · 빈 이름·`.`·`..` → `file`.
 * DB 의 이름은 이미 업로드 때 이 규칙을 지났지만, 가져오기·옛 자료·DB 를 직접 고친 행까지 여기서 한 번 더 막는다.
 */
function tbox_zip_entry_name(string $originalName): string
{
    $name = rtrim(str_replace('\\', '/', $originalName), '/');
    $cut = strrpos($name, '/');
    if ($cut !== false) {
        $name = substr($name, $cut + 1);
    }
    if (preg_match('//u', $name) !== 1) {
        return 'file';   // 올바른 UTF-8 이 아니다(DB 는 UTF8 이라 닿을 수 없는 갈래 — 그래도 닫힌 쪽으로)
    }
    $name = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $name);
    $name = trim($name);
    if ($name !== '' && iconv_strlen($name, 'UTF-8') > 255) {
        $name = (string) iconv_substr($name, 0, 255, 'UTF-8');
    }
    $name = str_replace(':', '_', $name);
    if ($name === '' || $name === '.' || $name === '..') {
        return 'file';
    }
    return $name;
}

/**
 * 같은 이름이면 `이름 (2).확장자` · `이름 (3).확장자` … — 대소문자만 다른 것도 같은 이름으로 본다(Windows·macOS 가 풀 때 겹친다).
 * 확장자는 마지막 `.` 뒤(맨 앞 `.` 은 확장자가 아니다 — `.bashrc (2)`). 255자를 넘지 않게 이름 쪽을 줄인다.
 *
 * @param array<string,true> $taken  이미 쓴 이름(소문자 열쇠) — 이 함수가 채운다
 */
function tbox_zip_unique_name(string $name, array &$taken): string
{
    $key = strtolower($name);
    if (!isset($taken[$key])) {
        $taken[$key] = true;
        return $name;
    }
    $dot  = strrpos($name, '.');
    $stem = ($dot === false || $dot === 0) ? $name : substr($name, 0, $dot);
    $ext  = ($dot === false || $dot === 0) ? '' : substr($name, $dot);
    for ($n = 2; ; $n++) {
        $suffix = ' (' . $n . ')';
        $room   = 255 - iconv_strlen($suffix . $ext, 'UTF-8');
        $base   = iconv_strlen($stem, 'UTF-8') > $room ? (string) iconv_substr($stem, 0, max(1, $room), 'UTF-8') : $stem;
        $try    = $base . $suffix . $ext;
        if (!isset($taken[strtolower($try)])) {
            $taken[strtolower($try)] = true;
            return $try;
        }
    }
}

/** 기다리지 않는 배타 잠금 — 얻으면 핸들(요청이 끝나면 PHP 가 닫고 잠금이 풀린다), 못 얻으면 null */
function tbox_zip_try_lock(string $path)
{
    $h = @fopen($path, 'c');
    if ($h === false) {
        throw new RuntimeException('zip 잠금 파일을 열 수 없습니다');
    }
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        return null;
    }
    return $h;
}

/**
 * 다른 슬롯이 잡아 둔 공간(바이트) — 검토 L-C1. $mine 은 내가 쥔 슬롯 번호(보지 않는다).
 * 슬롯 파일을 따로 열어 **공유 잠금을 기다리지 않고** 걸어 본다: 걸리면 아무도 쥐지 않은 슬롯이다(옛 값은 무시하고 바로 푼다).
 * 안 걸리면 누가 쥔 슬롯이다 — 그 파일의 숫자(그 요청의 2 × 합계)를 더한다. 비었거나 숫자가 아니거나 최악보다 크면(쥔 직후 적기 전 ·
 * 손상) **최악 2 × 500MB** 로 센다(모자라게 세지 않는 쪽). 파일이 없으면 0(한 번도 쓰이지 않은 슬롯).
 */
function tbox_zip_reserved_by_others(string $tmpDir, int $mine): int
{
    $worst = 2 * TBOX_ZIP_MAX_BYTES;
    $sum = 0;
    for ($i = 0; $i < TBOX_ZIP_SLOTS; $i++) {
        if ($i === $mine) {
            continue;
        }
        $path = $tmpDir . '/.zip-slot-' . $i . '.lock';
        if (!is_file($path)) {
            continue;
        }
        $h = @fopen($path, 'r');
        if ($h === false) {
            $sum += $worst;   // 있는데 못 연다 — 모르면 최악
            continue;
        }
        if (flock($h, LOCK_SH | LOCK_NB)) {
            flock($h, LOCK_UN);   // 아무도 쥐지 않았다 — 옛 값은 보지 않는다
            fclose($h);
            continue;
        }
        $raw = trim((string) stream_get_contents($h, 32));
        fclose($h);
        $sum += (preg_match('/^[0-9]{1,12}$/', $raw) === 1 && (int) $raw <= $worst) ? (int) $raw : $worst;
    }
    return $sum;
}

// 요청 시작 시각 — [8] 시간 한도(L-C2)의 기준. SAPI 가 준 값이 없으면 핸들러 시작
$zipStarted = isset($_SERVER['REQUEST_TIME_FLOAT']) && is_float($_SERVER['REQUEST_TIME_FLOAT'])
    ? $_SERVER['REQUEST_TIME_FLOAT']
    : microtime(true);

// ─── [1] 본문 ─────────────────────────────────────────────────
$body = Http::jsonBody();
$rawIds = $body['ids'] ?? null;
if (array_keys($body) !== ['ids'] || !is_array($rawIds) || $rawIds === [] || !array_is_list($rawIds)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (count($rawIds) > TBOX_ZIP_MAX_FILES) {
    Http::error(400, TBOX_ZIP_MSG_TOO_MANY);
}
$ids = [];
foreach ($rawIds as $one) {
    if (!is_string($one) || !Storage::isValidUuid($one)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $ids[$one] = true;   // 같은 id 는 하나로(보낸 순서 유지)
}
$ids = array_keys($ids);

$zipName = 'moa-' . date('Ymd-His') . '.zip';   // 서버가 만든 이름 — 요청 글자가 한 자도 들어가지 않는다
$bundle = static function (array $detail) use ($zipName): array {
    return [
        'target_type' => 'file',
        'target_name' => $zipName,
        'detail'      => ['via' => 'zip'] + $detail,
    ];
};

$tmpDir = Storage::tmpDir();

// ─── [2] 사람마다 한 번에 하나 ─────────────────────────────────
$userLock = tbox_zip_try_lock($tmpDir . '/.zip-user-' . (int) $user['id'] . '.lock');
if ($userLock === null) {
    Audit::deny('file.zip', 'busy', $bundle(['kind' => 'user', 'requested' => count($ids)]));
    if (!headers_sent()) {
        header('Retry-After: ' . TBOX_ZIP_RETRY_AFTER);
    }
    Http::json(['error' => TBOX_ZIP_MSG_BUSY, 'retry_after' => TBOX_ZIP_RETRY_AFTER], 429);
    exit;
}

// ─── [3] 파일마다 행 → 세 관문 ──────────────────────────────────
$via     = ['via' => 'zip'];
$passing = [];      // [[file 행, decideRead 근거 코드], …] — 보낸 순서
$skipped = 0;
$missing = 0;
$total   = 0;
foreach ($ids as $publicId) {
    $file = tbox_download_find($publicId);
    if ($file === null) {
        $skipped++;
        $missing++;
        continue;
    }
    [$denyReason, $readCode] = tbox_download_gate($user, $file, $via);
    if ($denyReason !== null) {
        $skipped++;
        continue;
    }
    $passing[] = [$file, $readCode];
    $total += (int) $file['size_bytes'];
}

// ─── [4] 남은 것 · 합계 ─────────────────────────────────────────
if ($passing === []) {
    Audit::deny('file.zip', 'nothing_allowed', $bundle(['requested' => count($ids), 'skipped' => $skipped]));
    Http::json(['error' => TBOX_ZIP_MSG_NOTHING, 'skipped' => $skipped], 403);
    exit;
}
if ($total > TBOX_ZIP_MAX_BYTES) {
    Audit::deny('file.zip', 'too_large', $bundle([
        'requested' => count($ids), 'skipped' => $skipped, 'size_bytes' => $total,
    ]));
    Http::json(['error' => TBOX_ZIP_MSG_TOO_LARGE, 'skipped' => $skipped], 400);
    exit;
}

// ─── [5] 사이트 전체 슬롯 · 남은 공간 · 오래된 임시 파일 ─────────────────
$slotLock = null;
$slotNo = -1;
for ($i = 0; $i < TBOX_ZIP_SLOTS && $slotLock === null; $i++) {
    $slotLock = tbox_zip_try_lock($tmpDir . '/.zip-slot-' . $i . '.lock');
    $slotNo = $i;
}
if ($slotLock === null) {
    Audit::deny('file.zip', 'busy', $bundle(['kind' => 'site', 'requested' => count($ids)]));
    if (!headers_sent()) {
        header('Retry-After: ' . TBOX_ZIP_RETRY_AFTER);
    }
    Http::json(['error' => TBOX_ZIP_MSG_BUSY, 'retry_after' => TBOX_ZIP_RETRY_AFTER], 429);
    exit;
}
$stale = glob($tmpDir . '/zip-*');
foreach (is_array($stale) ? $stale : [] as $old) {
    $mtime = @filemtime($old);
    if (is_file($old) && !is_link($old) && $mtime !== false && $mtime < time() - TBOX_ZIP_STALE_SECONDS) {
        @unlink($old);
    }
}
// 내 몫(최악 2 × 합계)을 내 슬롯 파일에 먼저 적는다 — 그다음 다른 슬롯 몫을 읽는다(둘이 동시에 와도 서로의 몫을 보거나, 적기 전이면 최악으로 센다)
if (!ftruncate($slotLock, 0) || fwrite($slotLock, (string) (2 * $total)) === false || !fflush($slotLock)) {
    throw new RuntimeException('zip 슬롯 파일에 적을 수 없습니다');
}
$reservedByOthers = tbox_zip_reserved_by_others($tmpDir, $slotNo);
$free = @disk_free_space($tmpDir);
if ($free === false || $free < 2 * $total + $reservedByOthers + TBOX_ZIP_DISK_RESERVE) {
    Audit::error('file.zip', 'no_space', $bundle([
        'requested' => count($ids), 'size_bytes' => $total, 'free_bytes' => $free === false ? null : (int) $free,
        'reserved_bytes' => $reservedByOthers,
    ]));
    Http::error(503, TBOX_ZIP_MSG_NO_SPACE);
}

// ─── [6] 무결성 — 파일마다 바이트 · sha256 ─────────────────────────
$ready = [];
$total = 0;
foreach ($passing as [$file, $readCode]) {
    [$integrityFail] = tbox_download_integrity($file, $via);
    if ($integrityFail !== null) {
        $skipped++;
        continue;
    }
    $ready[] = [$file, $readCode];
    $total += (int) $file['size_bytes'];
}
if ($ready === []) {
    Audit::deny('file.zip', 'nothing_allowed', $bundle(['requested' => count($ids), 'skipped' => $skipped]));
    Http::json(['error' => TBOX_ZIP_MSG_NOTHING, 'skipped' => $skipped], 403);
    exit;
}

// ─── [7] 임시 파일에 묶기 ──────────────────────────────────────
$tmp = tempnam($tmpDir, 'zip-');
if ($tmp === false || dirname($tmp) !== $tmpDir) {
    // tempnam 은 폴더를 못 쓰면 조용히 시스템 임시 폴더로 간다 — 그 파일은 쓰지 않는다
    if (is_string($tmp)) {
        @unlink($tmp);
    }
    Audit::error('file.zip', 'zip_failed', $bundle(['requested' => count($ids), 'why' => 'temp']));
    Http::error(500, '서버 오류');
}
register_shutdown_function(static function () use ($tmp): void {
    if (is_file($tmp)) {
        @unlink($tmp);
    }
});
@chmod($tmp, 0600);

$fail = static function (string $why) use ($bundle, $ids, $tmp): void {
    if (is_file($tmp)) {
        @unlink($tmp);
    }
    Audit::error('file.zip', 'zip_failed', $bundle(['requested' => count($ids), 'why' => $why]));
    Http::error(500, '서버 오류');
};

$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $fail('open');
}
$taken = [];
foreach ($ready as [$file]) {
    $entry = tbox_zip_unique_name(tbox_zip_entry_name((string) $file['original_name']), $taken);
    // 원본 경로는 Storage 가 stored_name(UUID) 으로만 만든다 — 요청 글자가 경로에 들어가지 않는다 (PRD S-06)
    if (!$zip->addFile(Storage::pathFor((string) $file['stored_name']), $entry, 0, 0, ZipArchive::FL_OVERWRITE | ZipArchive::FL_ENC_UTF_8)
        || !$zip->setCompressionName($entry, ZipArchive::CM_STORE)
    ) {
        $zip->close();
        $fail('add');
    }
}
if (!$zip->close()) {
    $fail('close');
}
clearstatcache(true, $tmp);
@chmod($tmp, 0600);   // libzip 은 닫을 때 새 임시 파일을 만들어 이름을 바꾼다 — 권한을 다시 좁힌다
$check = new ZipArchive();
if ($check->open($tmp, ZipArchive::RDONLY) !== true || $check->numFiles !== count($ready)) {
    $fail('count');
}
$check->close();
$zipSize = filesize($tmp);
if ($zipSize === false) {
    $fail('size');
}

// ─── [8] 감사 — 먼저 시간 한도(L-C2) · 파일마다 한 줄(한 건 받기와 같은 칸 + via) · 묶음 한 줄 ─────────
$elapsed = microtime(true) - $zipStarted;
if ($elapsed > TBOX_ZIP_TIME_BUDGET) {
    @unlink($tmp);
    Audit::error('file.zip', 'zip_failed', $bundle([
        'requested' => count($ids), 'why' => 'time', 'elapsed_ms' => (int) round($elapsed * 1000), 'size_bytes' => $total,
    ]));
    Http::error(503, TBOX_ZIP_MSG_TOO_SLOW);
}
foreach ($ready as [$file, $readCode]) {
    Audit::allow('file.download', [
        'target_type'  => 'file',
        'target_id'    => (int) $file['id'],
        'target_name'  => (string) $file['original_name'],
        'access_level' => (string) $file['access_level'],
        'detail'       => array_merge(['granted_by' => $readCode], tbox_download_audit_what($file), $via),
    ]);
}
Audit::allow('file.zip', $bundle([
    'requested' => count($ids), 'added' => count($ready), 'skipped' => $skipped, 'size_bytes' => $total,
]));

// ─── [9] 전송 ─────────────────────────────────────────────────
if (!headers_sent()) {
    header('Content-Type: application/zip');
    header('Content-Length: ' . $zipSize);
    // 한 건 받기(Storage::stream)와 같은 모양 — ASCII filename + RFC 5987 filename*. 이름은 서버가 만든 ASCII 라 둘이 같다
    header('Content-Disposition: attachment; filename="' . $zipName . '"; filename*=UTF-8\'\'' . rawurlencode($zipName));
    header('Cache-Control: no-store');
    header('X-Zip-Count: ' . count($ready));
    header('X-Zip-Skipped: ' . $skipped);
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($tmp);
@unlink($tmp);
exit;
