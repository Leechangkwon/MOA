<?php
declare(strict_types=1);

/**
 * scripts/scan_pending.php — 검사 대기(`pending`) 파일을 ClamAV 로 훑어 `clean`/`infected` 로 바꾼다
 *   PRD F-54 · 7.6 S-06 · 12.2 T-66 · SECURITY R-02 · R-86 · R-87
 *   ARCHITECTURE 5.28 (파이프라인) · 5.3 [4] (다운로드 관문) · 11.1 (매분 cron) · 7.4 `file.scan`
 *
 * 사용법
 *   sudo -u tbox-php php scripts/scan_pending.php [--dry-run] [--limit 200]
 *                                                 [--clamscan <경로>] [--force-enabled]
 *     --dry-run        검사만 하고 상태·바이트를 바꾸지 않는다 (규칙을 고친 뒤 확인용)
 *     --limit N        한 번에 볼 최대 건수 (기본 200). 한 배치가 디스크·메모리를 오래 쥐지 않게.
 *     --clamscan 경로  스캐너 실행 파일 (기본 /usr/bin/clamscan). 운영자가 셸에서 주는 값이며
 *                      절대 경로·실행 가능·셸 특수문자 없음을 확인한 뒤에만 쓴다.
 *     --force-enabled  `.env` 의 SCAN_ENABLED 와 무관하게 돈다 (시험용 — tests/f54.sh).
 *
 * ── 왜 앱이 아니라 여기인가 (ARCH 5.28 「왜 앱 밖 배치인가」)
 *   ① 앱 코드(src/·public/)에는 `exec`·`proc_open`·소켓을 두지 않는다. CLAUDE.md 금지 패턴이고
 *      php-fpm 풀의 `disable_functions` 로도 막혀 있다. `scripts/` 는 CLI 이고 보안 린트의 검사
 *      대상 밖이다 — purge_trash.php·alerts.php 와 같은 자리다.
 *   ② ClamAV 를 부르는 곳은 **이 파일 하나**다. 스캐너가 바뀌면(운영 전환 시 `clamdscan`)
 *      고칠 자리도 여기 [3] 하나다.
 *
 * ── 왜 `clamd`(상주 데몬)가 아닌가
 *   `clamd` 는 시그니처를 메모리에 올려 두고 1GB 이상을 계속 쥔다. 이 VM 은 3.8GB(가용 1.1GB)이고
 *   PostgreSQL 과 다툰다. 그래서 `clamav-daemon` 을 **설치하지 않고**, 대기 파일이 있을 때만
 *   `clamscan` 을 띄운다(실측 1회 최대 RSS 991MB · 22초, SECURITY R-86). 대기가 0건이면 이 배치는
 *   DB 를 한 번 보고 끝난다 — 1분마다 돌아도 평소에는 아무 비용이 없다.
 *
 * ── 잠금
 *   `clamscan` 한 번이 22초라 1분 주기와 겹칠 수 있다. `/var/lib/tbox-v2/scan.lock` 을
 *   `flock(LOCK_EX|LOCK_NB)` 로 잡고, 못 잡으면 「이미 실행 중」 한 줄을 찍고 **정상 종료**한다
 *   (cron 실패로 보이지 않게). 잠금 파일은 지우지 않는다 — 지우면 두 프로세스가 서로 다른
 *   inode 를 잠그는 창이 생긴다.
 *
 * ── 격리는 삭제가 아니다 (PRD F-54 ②)
 *   감염 파일은 `/var/lib/tbox-v2/quarantine/<stored_name>` 으로 **옮긴다**. 같은 파일시스템이라
 *   `rename` 은 원자적이고 바이트를 복사하지 않는다. 행은 그대로 남고 `deleted_at` 도 건드리지
 *   않는다 — 누가 언제 무엇을 올렸는지가 증거다(PRD S-07). 되살리는 창구는 없다(수동).
 *
 * ── 실패는 조용하지 않다
 *   한 건이 실패해도 배치를 멈추지 않는다. `file.scan error scan_failed` 를 남기고 상태는
 *   `pending` 그대로 두므로 **다음 실행이 다시 시도**한다. 반대로 상태만 먼저 바꾸면 검사되지
 *   않은 파일이 `clean` 으로 남는다 — 그래서 순서는 언제나 **바이트 이동 먼저, 그 다음 UPDATE+감사**다
 *   (purge_trash.php 와 같은 원칙).
 *   그 순서의 **대가**가 하나 있다: 이동은 됐는데 기록 전에 죽으면 저장소에 바이트가 없는 채
 *   `pending` 인 행이 남는다. 다음 실행은 [3] 에서 **격리 폴더에 같은 이름이 있는지** 보고,
 *   있으면 옮기지 않고 **기록만 마무리**한다(`signature: quarantined_earlier`). 이 갈래가 없으면
 *   그 행은 영영 `pending`(화면엔 「검사 중」 붙박이)이고 악성 한 건이 로그에도 경보에도 안 남는다.
 *
 * ── 출력에 무엇을 적지 않나
 *   파일 이름·저장 경로·시그니처 이름은 표준출력에 적지 않는다(건수 요약만). cron 메일·로그로
 *   새지 않게 하려는 것이고, 그 값들은 전부 감사 로그 `detail` 에 있다 (ARCH 7.5).
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

/** 동시 실행 방지 잠금 (ARCH 5.28 [1]) */
const SCAN_LOCK_FILE = '/var/lib/tbox-v2/scan.lock';
/** 감염 파일을 옮겨 두는 곳 — 0750 tbox-php, 웹 루트 밖, open_basedir 안 (deploy/install.sh) */
const QUARANTINE_DIR = '/var/lib/tbox-v2/quarantine';
/** 기본 스캐너 (배포판 패키지 clamav. clamav-daemon 은 설치하지 않는다 — R-86) */
const CLAMSCAN_DEFAULT = '/usr/bin/clamscan';
/** 격리 파일 권한 — 저장소 파일과 같다(0640 tbox-php). rename 은 권한을 보존하지만 못 박아 둔다 */
const QUARANTINE_MODE = 0640;

$argv = $_SERVER['argv'];

$dryRun       = cli_flag($argv, 'dry-run');
$forceEnabled = cli_flag($argv, 'force-enabled');

// ─── [0] 켜져 있나 ───────────────────────────────────────────────
// 꺼져 있으면 업로드가 애초에 'clean' 으로 저장되므로 대기 행이 생기지 않는다.
// 그래도 명시적으로 끝낸다 — "왜 아무 일도 안 일어나나" 에 배치가 스스로 답하게.
if (!$forceEnabled && Env::getOrEmpty('SCAN_ENABLED') !== '1') {
    cli_say('악성코드 검사가 꺼져 있다 (SCAN_ENABLED 가 1 이 아니다) — 아무것도 하지 않는다.');
    exit(TBOX_CLI_OK);
}

// ─── 인자 좁히기 ─────────────────────────────────────────────────
$limitRaw = (string) cli_option($argv, 'limit', '200');
if (preg_match('/^[0-9]{1,4}$/', $limitRaw) !== 1 || (int) $limitRaw < 1) {
    cli_warn('[사용법 오류] --limit 은 1 이상의 정수다');
    exit(TBOX_CLI_BAD_USAGE);
}
$limit = (int) $limitRaw;

/*
 * 스캐너 경로. 운영자가 셸에서 주는 값이지 요청 값이 아니다. 그래도 형태를 좁힌다 —
 * 이 값은 곧 **실행되는 프로그램**이므로, 상대 경로(PATH 탐색)·셸 특수문자·존재하지 않는
 * 파일을 여기서 끊는다. 실행은 셸을 거치지 않는 인자 배열이라 치환·파이프는 애초에 없다.
 */
$clamscan = (string) cli_option($argv, 'clamscan', CLAMSCAN_DEFAULT);
if (preg_match('#^/[A-Za-z0-9._/-]+$#', $clamscan) !== 1) {
    cli_warn('[사용법 오류] --clamscan 은 절대 경로여야 한다');
    exit(TBOX_CLI_BAD_USAGE);
}
if (!is_file($clamscan) || !is_executable($clamscan)) {
    cli_warn('[사용법 오류] clamscan 을 실행할 수 없다: ' . $clamscan);
    exit(TBOX_CLI_BAD_USAGE);
}

/**
 * 셸 없이 프로그램을 돌린다 — **인자 배열**만 쓴다 (문자열 결합 금지).
 * 배열 형태의 proc_open 은 셸을 거치지 않으므로 경로에 어떤 문자가 있어도 인자 하나로 전달된다.
 *
 * @param list<string> $cmd
 * @return array{code:int,out:string,err:string}
 */
function scan_run(array $cmd): array
{
    $spec = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc  = proc_open($cmd, $spec, $pipes, sys_get_temp_dir(), null);
    if (!is_resource($proc)) {
        throw new RuntimeException('스캐너를 실행할 수 없습니다');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    return ['code' => $code, 'out' => $out, 'err' => $err];
}

// 시그니처 판(main/daily) — 감사 detail 에 남긴다. `sigtool` 없이 `clamscan --version` 한 줄이면
// 된다: "ClamAV 1.5.3/28124/Tue Sep 15 ..." 의 가운데가 daily 번호다.
$version = '';
try {
    $v = scan_run([$clamscan, '--version']);
    $version = trim(strtok($v['out'], "\n") ?: '');
} catch (Throwable $e) {
    $version = '';
}

// ─── [1] 잠금 ────────────────────────────────────────────────────
$lock = @fopen(SCAN_LOCK_FILE, 'c');
if ($lock === false) {
    cli_warn('잠금 파일을 열 수 없다: ' . SCAN_LOCK_FILE . ' (실행 계정이 tbox-php 인지 확인)');
    exit(TBOX_CLI_FAIL);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    cli_say('이미 실행 중이다 — 이번 차례는 건너뛴다.');
    fclose($lock);
    exit(TBOX_CLI_OK);
}

// ─── [2] 대기 목록 (있을 때만 스캐너를 띄운다) ───────────────────
$db = Db::instance();

$rows = $db->run(
    "SELECT id, public_id, stored_name, original_name, access_level,
            sha256, size_bytes, uploaded_by
       FROM files
      WHERE scan_status = 'pending'
        AND purged_at IS NULL
      ORDER BY created_at
      LIMIT :limit",
    [':limit' => $limit]
)->fetchAll();

if ($rows === []) {
    cli_say('검사 대기 없음 — 스캐너를 실행하지 않는다.');
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(TBOX_CLI_OK);
}

cli_say(sprintf('검사 대기: %d건%s', count($rows), $dryRun ? ' (--dry-run)' : ''));

// ─── [3] 경로 만들기 — Storage::pathFor 만 (사용자 입력이 경로에 닿지 않는다) ───
/** @var array<string,array<string,mixed>> 절대 경로 → files 행 */
$byPath  = [];
/** @var list<string> */
$targets  = [];
$failed   = 0;
$clean    = 0;
$infected = 0;

/**
 * 한 건 실패 기록 — 상태는 `pending` 그대로 두어 다음 실행이 다시 시도하게 한다.
 *
 * @param array<string,mixed> $row
 */
function scan_fail(array $row, string $why): void
{
    Audit::error('file.scan', 'scan_failed', [
        'target_type'  => 'file',
        'target_id'    => (int) $row['id'],
        'target_name'  => (string) $row['original_name'],
        'access_level' => (string) $row['access_level'],
        'detail'       => ['mode' => 'scan', 'reason' => $why],
    ]);
}

foreach ($rows as $row) {
    try {
        $path = Storage::pathFor((string) $row['stored_name']);
    } catch (Throwable $e) {
        // stored_name 이 UUID 형태가 아니거나 저장소 밖을 가리킨다 — 검사 대상이 아니다.
        scan_fail($row, 'bad_stored_name');
        $failed++;
        continue;
    }
    if (!is_file($path) || !is_readable($path)) {
        /*
         * 저장소에 바이트가 없다. 두 가지 경우가 섞여 있으므로 갈라야 한다.
         *
         * ① **앞선 실행이 격리까지는 했는데 기록을 못 남긴 것.** [4] 의 순서가
         *    「바이트 이동 먼저 → UPDATE+감사」이기 때문에 생기는 대가다(그 반대 순서로 하면
         *    "격리했다" 고 기록됐는데 파일은 저장소에 그대로인, 훨씬 나쁜 상태가 된다).
         *    이 상태를 그냥 `file_missing` 으로만 남기면 **다음 실행도 같은 판단을 반복**해
         *    그 행은 영영 `pending` 이고 — 즉 화면에는 「검사 중」으로 붙박이가 되고 —
         *    악성 파일 한 건이 감사 로그에도 경보에도 끝내 남지 않는다.
         *    그래서 격리 폴더에 같은 이름이 있으면 **기록만 마무리**한다. 바이트는 이미
         *    제자리(격리)에 있으므로 여기서 옮기거나 지울 것이 없다.
         * ② 그 밖(사람이 손으로 지웠다·저장소 사고) — 지금처럼 `file_missing` 으로 남긴다.
         *   상태는 `pending` 그대로이며 정합성 검사(consistency_check)가 볼 몫이다.
         */
        $q = QUARANTINE_DIR . '/' . basename($path);
        if (is_file($q)) {
            try {
                $db->begin();
                $db->run(
                    "UPDATE files SET scan_status = 'infected' WHERE id = :id AND scan_status = 'pending'",
                    [':id' => (int) $row['id']]
                );
                Audit::deny('file.scan', 'infected', [
                    'target_type'  => 'file',
                    'target_id'    => (int) $row['id'],
                    'target_name'  => (string) $row['original_name'],
                    'access_level' => (string) $row['access_level'],
                    'detail'       => [
                        'mode'        => 'scan',
                        // 어느 시그니처였는지는 앞선 실행이 남기지 못했다. 지어내지 않고
                        // "이미 격리된 것을 뒤늦게 기록했다" 는 사실을 그대로 적는다.
                        'signature'   => 'quarantined_earlier',
                        'sha256'      => (string) $row['sha256'],
                        'size_bytes'  => (int) $row['size_bytes'],
                        'uploader_id' => (int) $row['uploaded_by'],
                        'signatures'  => $version,
                    ],
                ]);
                $db->commit();
                $infected++;
                cli_say(sprintf('  [복구] id=%d 이미 격리돼 있어 기록만 마무리했다', (int) $row['id']));
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                $failed++;
                error_log('[tbox-cli] scan_pending 복구 id=' . (int) $row['id'] . ' 실패: ' . $e->getMessage());
                scan_fail($row, 'quarantine_record_failed');
            }
            continue;
        }
        scan_fail($row, 'file_missing');
        $failed++;
        continue;
    }
    $byPath[$path] = $row;
    $targets[]     = $path;
}

if ($targets === []) {
    // 스캐너를 띄울 것이 없다. 위에서 「이미 격리돼 있던 것」을 기록으로 마무리했을 수 있다.
    cli_say(sprintf('완료: 깨끗 %d건, 격리 %d건, 실패 %d건', $clean, $infected, $failed));
    flock($lock, LOCK_UN);
    fclose($lock);
    exit($failed > 0 ? TBOX_CLI_FAIL : TBOX_CLI_OK);
}

/*
 * clamscan 인자 — 전부 **배열**이다. `--` 뒤에 경로를 두어 파일 이름이 옵션으로 읽히지 않게 한다
 * (경로는 UUID.확장자 뿐이라 `-` 로 시작할 수 없지만, 규칙은 값이 아니라 형태로 지킨다).
 *   --no-summary  요약 줄을 빼 파싱을 단순하게
 *   --infected    감염된 것만 출력
 *   --stdout      결과를 표준출력으로 (기본은 일부가 stderr)
 *   --max-filesize / --max-scansize / --max-recursion  압축 폭탄으로 메모리를 태우지 않게 (R-86)
 */
$cmd = array_merge([
    $clamscan,
    '--no-summary',
    '--infected',
    '--stdout',
    '--max-filesize=100M',
    '--max-scansize=1G',
    '--max-recursion=8',
    '--',
], $targets);

$started = microtime(true);
$result  = scan_run($cmd);
$elapsed = microtime(true) - $started;

cli_say(sprintf('스캐너 종료 코드 %d · %.1f초', $result['code'], $elapsed));

/*
 * 종료 코드 (clamscan(1))
 *   0 = 전부 깨끗 · 1 = 감염 있음 · 그 밖 = 오류
 * 오류면 결과를 믿을 수 없으므로 **전부** scan_failed 로 남기고 상태를 그대로 둔다 —
 * 다음 실행이 같은 목록을 다시 본다. "검사하지 못했다" 를 "깨끗하다" 로 바꾸지 않는다.
 */
if ($result['code'] !== 0 && $result['code'] !== 1) {
    if ($result['err'] !== '') {
        error_log('[tbox-cli] scan_pending clamscan stderr: ' . substr($result['err'], 0, 500));
    }
    foreach ($byPath as $row) {
        scan_fail($row, 'scanner_error');
        $failed++;
    }
    cli_say(sprintf('스캐너 오류 — %d건을 다음 실행으로 미룬다.', count($byPath)));
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(TBOX_CLI_FAIL);
}

// 출력 한 줄 = `<경로>: <시그니처> FOUND`. 경로에 ': ' 가 들어갈 수 없으므로(UUID.확장자)
// 마지막 ': ' 를 기준으로 가른다.
$found = [];
foreach (explode("\n", $result['out']) as $line) {
    $line = rtrim($line, "\r\n ");
    if ($line === '' || substr($line, -6) !== ' FOUND') {
        continue;
    }
    $body = substr($line, 0, -6);
    $cut  = strrpos($body, ': ');
    if ($cut === false) {
        continue;
    }
    $path = substr($body, 0, $cut);
    $sig  = substr($body, $cut + 2);
    if (isset($byPath[$path])) {
        $found[$path] = $sig;
    }
}

// ─── [4] 건마다: 바이트 이동 먼저 → UPDATE + 감사 ────────────────
foreach ($byPath as $path => $row) {
    $id         = (int) $row['id'];
    $storedName = (string) $row['stored_name'];
    $original   = (string) $row['original_name'];
    $level      = (string) $row['access_level'];
    $isInfected = isset($found[$path]);

    if ($dryRun) {
        cli_say(sprintf('  [예정] id=%d → %s', $id, $isInfected ? 'infected' : 'clean'));
        if ($isInfected) {
            $infected++;
        } else {
            $clean++;
        }
        continue;
    }

    try {
        if ($isInfected) {
            // 1) 바이트를 먼저 옮긴다. 실패하면 상태를 바꾸지 않는다 —
            //    "격리됨" 이라고 기록됐는데 파일은 저장소에 그대로인 상태를 만들지 않는다.
            // 격리 경로는 **Storage::pathFor 가 이미 통과시킨 경로의 basename** 으로만 만든다.
            // $path 는 위 [3] 에서 pathFor() 를 통과한 값이라 이름이 `UUID.확장자` 형태임이
            // 보장돼 있고(STORED_RE), basename 을 쓰면 DB 값이 어떻든 격리 폴더 밖으로 나갈 수
            // 없다 — 사용자 입력이 경로에 닿지 않는다 (ARCH 13장 체크리스트).
            $dest = QUARANTINE_DIR . '/' . basename($path);
            if (!is_dir(QUARANTINE_DIR)) {
                throw new RuntimeException('quarantine_missing');
            }
            if (!@rename($path, $dest)) {
                throw new RuntimeException('quarantine_move_failed');
            }
            @chmod($dest, QUARANTINE_MODE);

            // 2) 상태 + 감사를 한 트랜잭션으로. 기록이 실패하면 상태도 남지 않고
            //    다음 실행이 같은 행을 다시 본다(그때 파일은 이미 격리 폴더에 있으므로
            //    file_missing 으로 남는다 — 조용한 성공보다 시끄러운 실패가 낫다).
            $db->begin();
            $db->run(
                "UPDATE files SET scan_status = 'infected' WHERE id = :id AND scan_status = 'pending'",
                [':id' => $id]
            );
            Audit::deny('file.scan', 'infected', [
                'target_type'  => 'file',
                'target_id'    => $id,
                'target_name'  => $original,
                'access_level' => $level,
                'detail'       => [
                    'mode'        => 'scan',
                    'signature'   => $found[$path],
                    'sha256'      => (string) $row['sha256'],
                    'size_bytes'  => (int) $row['size_bytes'],
                    'uploader_id' => (int) $row['uploaded_by'],
                    'signatures'  => $version,
                ],
            ]);
            $db->commit();
            $infected++;
        } else {
            $db->begin();
            $db->run(
                "UPDATE files SET scan_status = 'clean' WHERE id = :id AND scan_status = 'pending'",
                [':id' => $id]
            );
            Audit::allow('file.scan', [
                'target_type'  => 'file',
                'target_id'    => $id,
                'target_name'  => $original,
                'access_level' => $level,
                'detail'       => [
                    'mode'       => 'scan',
                    'sha256'     => (string) $row['sha256'],
                    'size_bytes' => (int) $row['size_bytes'],
                    'signatures' => $version,
                ],
            ]);
            $db->commit();
            $clean++;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $failed++;
        error_log('[tbox-cli] scan_pending id=' . $id . ' 실패: ' . $e->getMessage());
        scan_fail($row, $e->getMessage() === '' ? 'scan_failed' : substr($e->getMessage(), 0, 40));
    }
}

cli_say(sprintf('완료: 깨끗 %d건, 격리 %d건, 실패 %d건', $clean, $infected, $failed));

flock($lock, LOCK_UN);
fclose($lock);
exit($failed > 0 ? TBOX_CLI_FAIL : TBOX_CLI_OK);
