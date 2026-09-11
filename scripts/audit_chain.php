<?php
declare(strict_types=1);

/**
 * scripts/audit_chain.php — 감사 로그 파일의 일별 해시 체인 (9단계)
 *   ARCHITECTURE 7.1 "지워졌는지 알 수 있게: 일별 sha256 을 다음 날 기록(해시 체인)"
 *   ARCHITECTURE 11.1 (매일 00:05) · 7.5 detail 키 `chain_prev`·`chain_hash`
 *   SECURITY R-11 (원격 로그 서버가 없는 동안의 대체 증거)
 *
 * 사용법
 *   sudo php scripts/audit_chain.php [--date YYYY-MM-DD]   ← 그 날짜 파일을 체인에 적는다(기본: 오늘)
 *   sudo php scripts/audit_chain.php --verify               ← 체인 파일 전체를 재검산한다
 *
 * 무엇을 막나
 *   `audit.log` 는 `chattr +a`(추가 전용)로 잠겨 있지만, root 는 그 속성을 풀 수 있다.
 *   서버가 털려 root 를 잃으면 "불리한 줄만 지운 로그" 가 만들어질 수 있다. 그래서 날마다
 *   그날 파일의 sha256 을 **직전 날의 값과 엮어** 따로 적어 둔다. 한 줄이라도 지우면 그날의
 *   해시가 달라지고, 그 뒤 모든 날의 연결이 어긋난다 — 한 군데만 고쳐서는 맞출 수 없다.
 *   (완전한 방어는 아니다. 체인 파일도 같은 서버에 있으므로 root 는 둘 다 고쳐 쓸 수 있다.
 *    진짜 방어는 원격 로그 서버이고, 그건 대상 서버가 생길 때까지 미결이다 — R-11, ARCH 11.4.)
 *
 * ★ 자기 자신이 남긴 줄은 해시에서 뺀다
 *   이 스크립트도 `audit.integrity` 를 남기고, 그 줄은 같은 `audit.log` 에 붙는다. 파일 전체를
 *   그대로 해시하면 "해시를 적는 행위" 가 해시를 바꿔 버려 같은 날 두 번 돌릴 때마다 값이 달라지고,
 *   나중에 재검산해도 절대 같은 값이 나오지 않는다(자기 참조). 그래서 `"route":"cli:audit_chain.php"`
 *   가 든 줄만 빼고 해시한다. 빠지는 줄은 이 배치가 남긴 점검 기록뿐이며, 사람·앱의 행동 줄은
 *   한 줄도 빠지지 않는다. 남는 빈틈(공격자가 그 표식을 흉내 낸 줄을 끼워 넣는 것)을 줄이려고
 *   해시 대상의 **줄 수와 바이트 수**를 체인에 함께 적는다.
 *
 * 체인 파일 `/var/lib/tbox-v2/audit-chain.txt` (0600 root)
 *   한 줄에 `날짜 sha256 직전해시 줄수 바이트수` — 공백 구분. 첫 줄의 직전해시는 0 64개(GENESIS).
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

const CHAIN_FILE   = '/var/lib/tbox-v2/audit-chain.txt';
const CHAIN_GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
/** 해시에서 빼는 줄의 표식 — 이 배치 자신이 남긴 감사 기록 (위 주석 ★) */
const CHAIN_SELF_MARK = '"route":"cli:audit_chain.php"';

/**
 * 감사 로그 파일 하나의 "체인용" 해시를 구한다.
 * 반환: [sha256, 해시에 넣은 줄 수, 해시에 넣은 바이트 수]
 *
 * @return array{0:string,1:int,2:int}
 */
function chain_hash_of(string $path): array
{
    $isGz = substr($path, -3) === '.gz';
    $fh   = $isGz ? gzopen($path, 'rb') : fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException('감사 로그 파일을 열 수 없습니다: ' . $path);
    }
    $ctx   = hash_init('sha256');
    $lines = 0;
    $bytes = 0;
    while (true) {
        $line = $isGz ? gzgets($fh) : fgets($fh);
        if ($line === false) {
            break;
        }
        if (strpos($line, CHAIN_SELF_MARK) !== false) {
            continue;                     // 이 배치가 남긴 줄 (★)
        }
        hash_update($ctx, $line);
        $lines++;
        $bytes += strlen($line);
    }
    if ($isGz) {
        gzclose($fh);
    } else {
        fclose($fh);
    }
    return [hash_final($ctx), $lines, $bytes];
}

/**
 * 날짜에 해당하는 감사 로그 파일 경로를 찾는다.
 * 오늘이면 살아 있는 파일, 과거면 logrotate 가 만든 `audit.log-YYYYMMDD`(그리고 `.gz`)다.
 * logrotate 의 `dateext` 는 **회전한 날**을 붙이므로 그 다음 날짜 이름도 함께 본다.
 */
function chain_file_for(string $date): ?string
{
    $live = Env::get('AUDIT_LOG_FILE');
    if ($date === date('Y-m-d')) {
        return is_file($live) ? $live : null;
    }
    $stamp = str_replace('-', '', $date);
    $next  = date('Ymd', (int) strtotime($date . ' +1 day'));
    foreach ([$stamp, $next] as $suffix) {
        foreach (['', '.gz'] as $ext) {
            $candidate = $live . '-' . $suffix . $ext;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }
    return null;
}

/**
 * 체인 파일을 읽는다.
 *
 * @return list<array{date:string,hash:string,prev:string,lines:int,bytes:int}>
 */
function chain_read(): array
{
    if (!is_file(CHAIN_FILE)) {
        return [];
    }
    $raw = file(CHAIN_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($raw === false) {
        throw new RuntimeException('체인 파일을 읽을 수 없습니다: ' . CHAIN_FILE);
    }
    $out = [];
    foreach ($raw as $n => $line) {
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $p = preg_split('/\s+/', trim($line));
        if ($p === false || count($p) < 3) {
            throw new RuntimeException('체인 파일 ' . ($n + 1) . '번째 줄의 형식이 잘못되었습니다');
        }
        $out[] = [
            'date'  => $p[0],
            'hash'  => $p[1],
            'prev'  => $p[2],
            'lines' => isset($p[3]) ? (int) $p[3] : -1,
            'bytes' => isset($p[4]) ? (int) $p[4] : -1,
        ];
    }
    return $out;
}

/** @param list<array{date:string,hash:string,prev:string,lines:int,bytes:int}> $chain */
function chain_write(array $chain): void
{
    $body = "# t-box 감사 로그 해시 체인 (scripts/audit_chain.php, ARCH 7.1 · R-11)\n"
          . "# 날짜 sha256 직전해시 줄수 바이트수\n";
    foreach ($chain as $e) {
        $body .= sprintf("%s %s %s %d %d\n", $e['date'], $e['hash'], $e['prev'], $e['lines'], $e['bytes']);
    }
    cli_write_private(CHAIN_FILE, $body);
}

// ─────────────────────────────────────────────────────────────
$argv = $_SERVER['argv'];

// ─── --verify : 체인 전체 재검산 ───
if (cli_flag($argv, 'verify')) {
    $chain = chain_read();
    if ($chain === []) {
        cli_say('체인 파일이 비어 있다 — 검산할 것이 없다.');
        exit(TBOX_CLI_OK);
    }
    $bad  = [];
    $prev = CHAIN_GENESIS;
    foreach ($chain as $i => $e) {
        if (!hash_equals($prev, $e['prev'])) {
            $bad[] = ['date' => $e['date'], 'kind' => 'prev_broken'];
        }
        $path = chain_file_for($e['date']);
        if ($path === null) {
            cli_say(sprintf('  %s  파일 없음(회전·보존기간 경과) — 연결만 확인', $e['date']));
        } else {
            [$h, $lines, $bytes] = chain_hash_of($path);
            if (!hash_equals($e['hash'], $h)) {
                $bad[] = ['date' => $e['date'], 'kind' => 'hash_changed'];
                cli_warn(sprintf('  %s  ✖ 해시 불일치 (기록 %s… / 지금 %s…)',
                    $e['date'], substr($e['hash'], 0, 12), substr($h, 0, 12)));
            } elseif ($e['lines'] >= 0 && ($e['lines'] !== $lines || $e['bytes'] !== $bytes)) {
                $bad[] = ['date' => $e['date'], 'kind' => 'count_changed'];
                cli_warn(sprintf('  %s  ✖ 줄 수·바이트 수 불일치', $e['date']));
            } else {
                cli_say(sprintf('  %s  ✔ %d줄 %d바이트', $e['date'], $lines, $bytes));
            }
        }
        $prev = $e['hash'];
        unset($i);
    }

    if ($bad === []) {
        Audit::allow('audit.integrity', [
            'target_type' => 'system',
            'target_name' => 'audit_chain_verify',
            'detail'      => ['checked' => count($chain), 'mismatches' => 0],
        ]);
        cli_say(sprintf('결과: 통과 (%d일치 연결·해시 일치)', count($chain)));
        exit(TBOX_CLI_OK);
    }

    Audit::error('audit.integrity', 'mismatch', [
        'target_type' => 'system',
        'target_name' => 'audit_chain_verify',
        'detail'      => ['checked' => count($chain), 'mismatches' => $bad],
    ]);
    cli_raise_alert('AL-6', ['source' => 'audit_chain_verify', 'broken' => $bad]);
    cli_warn(sprintf('결과: 실패 — 어긋난 날 %d개. 경보 AL-6 을 남겼다', count($bad)));
    exit(TBOX_CLI_MISMATCH);
}

// ─── 기본 동작 : 한 날짜를 체인에 적는다 ───
$date = (string) cli_option($argv, 'date', date('Y-m-d'));
if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) !== 1 || strtotime($date) === false) {
    cli_warn('[사용법 오류] --date 는 YYYY-MM-DD 형식이다');
    exit(TBOX_CLI_BAD_USAGE);
}

$path = chain_file_for($date);
if ($path === null) {
    cli_say(sprintf('%s 의 감사 로그 파일이 없다 — 체인에 적지 않는다.', $date));
    exit(TBOX_CLI_OK);
}

[$hash, $lines, $bytes] = chain_hash_of($path);

$chain = chain_read();

// 같은 날짜 줄이 이미 있으면 **덮어쓴다**. 오늘 줄은 하루 동안 계속 자라므로 확정값이 아니다.
// 과거 날짜 줄이 바뀌면 그건 변조 신호다 — 값이 달라지면 경보를 남기고 종료 코드 2 로 끝낸다.
$prev  = CHAIN_GENESIS;
$found = null;
foreach ($chain as $i => $e) {
    if ($e['date'] === $date) {
        $found = $i;
        break;
    }
    $prev = $e['hash'];
}

if ($found !== null) {
    $old = $chain[$found];
    if ($old['hash'] === $hash) {
        cli_say(sprintf('%s 이미 같은 값으로 기록돼 있다 (%s…, %d줄) — 바꾸지 않는다.',
            $date, substr($hash, 0, 12), $lines));
    } elseif ($date < date('Y-m-d')) {
        // 지난 날 파일이 바뀌었다 = 변조 의심
        Audit::error('audit.integrity', 'mismatch', [
            'target_type' => 'system',
            'target_name' => 'audit_chain',
            'detail'      => [
                'checked'    => 1,
                'mismatches' => [['date' => $date, 'kind' => 'closed_day_changed']],
                'chain_prev' => $old['hash'],
                'chain_hash' => $hash,
            ],
        ]);
        cli_raise_alert('AL-6', ['source' => 'audit_chain', 'date' => $date, 'kind' => 'closed_day_changed']);
        cli_warn(sprintf('%s 는 이미 닫힌 날인데 해시가 달라졌다 — 경보 AL-6. 체인은 고치지 않는다.', $date));
        exit(TBOX_CLI_MISMATCH);
    } else {
        $chain[$found] = ['date' => $date, 'hash' => $hash, 'prev' => $old['prev'],
                          'lines' => $lines, 'bytes' => $bytes];
        chain_write($chain);
        cli_say(sprintf('%s 갱신 (오늘 파일은 계속 자란다): %s… %d줄 %d바이트',
            $date, substr($hash, 0, 12), $lines, $bytes));
    }
    $prevForLog = $old['prev'];
} else {
    // 새 날짜 — 마지막 줄의 해시를 prev 로 이어 붙인다
    $prev = $chain === [] ? CHAIN_GENESIS : $chain[count($chain) - 1]['hash'];
    $chain[] = ['date' => $date, 'hash' => $hash, 'prev' => $prev, 'lines' => $lines, 'bytes' => $bytes];
    usort($chain, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
    chain_write($chain);
    cli_say(sprintf('%s 기록: %s… (직전 %s…) %d줄 %d바이트',
        $date, substr($hash, 0, 12), substr($prev, 0, 12), $lines, $bytes));
    $prevForLog = $prev;
}

Audit::allow('audit.integrity', [
    'target_type' => 'system',
    'target_name' => 'audit_chain',
    'detail'      => [
        'checked'    => $lines,
        'mismatches' => 0,
        'chain_prev' => $prevForLog,
        'chain_hash' => $hash,
        'log_date'   => $date,
        'bytes'      => $bytes,
    ],
]);

exit(TBOX_CLI_OK);
