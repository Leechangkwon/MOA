<?php
declare(strict_types=1);

/**
 * scripts/audit_chain.php — 감사 로그 파일의 일별 해시 체인 (9단계)
 *   ARCHITECTURE 7.1 "지워졌는지 알 수 있게: 일별 sha256 을 다음 날 기록(해시 체인)"
 *   ARCHITECTURE 11.1 (매일 00:05) · 7.5 detail 키 `chain_prev`·`chain_hash`
 *   SECURITY R-11 (원격 로그 서버가 없는 동안의 대체 증거)
 *
 * 사용법
 *   sudo php scripts/audit_chain.php                        ← **어제** 회전 파일을 봉인한다 (cron 00:05)
 *   sudo php scripts/audit_chain.php --yesterday            ← 위와 같다 (뜻을 드러내고 싶을 때)
 *   sudo php scripts/audit_chain.php --date YYYY-MM-DD      ← 그 날짜 파일을 체인에 적는다 (**어제 이전만**)
 *   sudo php scripts/audit_chain.php --verify               ← 체인 파일 전체를 재검산한다
 *   sudo php scripts/audit_chain.php --reseal YYYY-MM-DD    ← 닫힌 날을 **다시 봉인**한다 (일회성, 사람 판단)
 *
 * ★ 왜 기본이 "어제" 인가 (13단계 — SECURITY R-50, PRD T-29)
 *   전에는 인자가 없으면 **오늘** 날짜를 적었다. cron 은 00:05 에 도는데 logrotate 는 00:00 에
 *   회전하므로, 그 시각의 "오늘 파일" 은 방금 만들어진 **빈 파일**이다. 결과로 체인에는 0줄짜리
 *   빈 해시(sha256 `e3b0c4…`)가 적히고 **어제는 영영 봉인되지 않았다**. 오늘 파일은 하루 종일
 *   자라므로 다음 `--verify` 에서 반드시 불일치가 나고, 매일 AL-6 헛경보가 울렸다
 *   (2026-09-12 실측: 09-11·09-12 두 날 `hash_changed`, 변조가 아니라 설계 결함).
 *   "늘 나는 경보" 는 진짜 변조를 덮으므로, 봉인은 **다 자란 어제 파일**에만 한다.
 *   `--date` 로 **오늘·미래**를 주면 사용법 오류로 끝낸다 — 봉인은 "이 값으로 확정" 이라는 선언인데
 *   오늘 파일에는 확정할 값이 없고, 적어 두면 내일의 `--yesterday` 가 같은 헛경보를 내기 때문이다.
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
 * ★ `--verify` 와 "열린 날" (13단계 — SECURITY R-50)
 *   **오늘** 날짜 항목은 아직 자라는 중이라 해시가 확정값이 아니다. 검산에서 해시를 비교하지 않고
 *   「열린 날 — 검산 제외」로 표기하며 **실패로 세지 않는다**. 연결(prev)만 확인한다.
 *
 * ★ `--reseal YYYY-MM-DD` — 닫힌 날을 다시 봉인한다 (13단계, 일회성)
 *   닫힌 날의 해시가 달라지면 기본 동작은 지금처럼 **AL-6 경보**를 남기고 체인을 고치지 않는다
 *   (변조 신호를 조용히 덮지 않는다). 하지만 위 설계 결함으로 **잘못 적힌** 항목은 사람이
 *   한 번 바로잡아야 한다. 그 통로가 `--reseal` 이며, 다음을 함께 한다.
 *     · 옛 해시·줄수·바이트수를 `audit.integrity allow` 의 detail
 *       (`resealed_from_hash`·`resealed_from_lines`·`resealed_from_bytes`)에 남긴다 —
 *       "무엇이 무엇으로 바뀌었나" 가 감사 로그에 없으면 재봉인은 그냥 증거 조작과 구분되지 않는다.
 *     · 그 항목의 해시를 갱신하고, **뒤따르는 항목의 `prev` 를 다시 잇는다**.
 *   `--reseal` 은 날짜를 하나만 받는다. 사람이 그 날의 파일을 확인한 뒤 부르는 명령이다.
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
 * 빈 파일의 sha256 (`sha256sum /dev/null`). 옛 결함(R-50)이 남긴 **자리표시 항목**을 알아보는 표식이다.
 *
 * 2026-09-12 이전의 cron 은 00:05 에 **오늘** 날짜를 적었고, 그 시각의 오늘 파일은 logrotate 가
 * 00:00 에 막 회전시킨 **빈 파일**이었다. 그래서 체인에는 `<날짜> e3b0c4… <prev> 0 0` 이라는,
 * 아무것도 봉인하지 않은 줄이 남았다. 이 줄은 "그 날의 확정 해시" 가 아니라 **봉인된 적 없는
 * 자리표시**다 — 뒷날 그 날짜를 제대로 봉인할 때 변조로 볼 값이 아니다 (13단계 보정).
 */
const CHAIN_EMPTY_FILE_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

/**
 * 체인 항목이 위의 "봉인된 적 없는 자리표시" 인가.
 * 세 조건을 **함께** 본다(0줄 · 0바이트 · 빈 파일 해시) — 하나만 보면 진짜 변조로 0줄이 된 날을
 * 자리표시로 오인할 수 있다.
 *
 * @param array{date:string,hash:string,prev:string,lines:int,bytes:int} $entry
 */
function chain_is_placeholder(array $entry): bool
{
    return $entry['lines'] === 0
        && $entry['bytes'] === 0
        && hash_equals(CHAIN_EMPTY_FILE_HASH, $entry['hash']);
}

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
    $bad   = [];
    $prev  = CHAIN_GENESIS;
    $today = date('Y-m-d');
    $open  = false;          // 오늘(열린 날) 항목이 체인에 있었나 — 결과 줄에 함께 적는다
    foreach ($chain as $i => $e) {
        if (!hash_equals($prev, $e['prev'])) {
            $bad[] = ['date' => $e['date'], 'kind' => 'prev_broken'];
        }
        if ($e['date'] === $today) {
            // 오늘 항목은 아직 자라는 중이다 — 해시는 확정값이 아니므로 비교하지 않는다
            // (13단계, SECURITY R-50). 연결(prev)만 위에서 확인했고 실패로 세지 않는다.
            $open = true;
            cli_say(sprintf('  %s  … 열린 날 — 검산 제외 (기록 %s…, %d줄)',
                $e['date'], substr($e['hash'], 0, 12), $e['lines']));
            $prev = $e['hash'];
            continue;
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

    $checked = count($chain) - ($open ? 1 : 0);   // 열린 날은 검산 건수에서 뺀다

    if ($bad === []) {
        Audit::allow('audit.integrity', [
            'target_type' => 'system',
            'target_name' => 'audit_chain_verify',
            'detail'      => ['checked' => $checked, 'mismatches' => 0],
        ]);
        cli_say(sprintf('결과: 통과 (%d일치 연결·해시 일치%s)',
            $checked, $open ? ', 오늘은 열린 날이라 검산 제외' : ''));
        exit(TBOX_CLI_OK);
    }

    Audit::error('audit.integrity', 'mismatch', [
        'target_type' => 'system',
        'target_name' => 'audit_chain_verify',
        'detail'      => ['checked' => $checked, 'mismatches' => $bad],
    ]);
    // origin `system` — 행위자 없는 배치 판정 (F-69 A-1)
    cli_raise_alert('AL-6', [
        'rule_text' => '감사 로그 해시 체인 검산 실패 — 닫힌 날의 연결·해시가 어긋남',
        'source'    => 'audit_chain_verify',
        'broken'    => $bad,
    ], Origin::SYSTEM);
    cli_warn(sprintf('결과: 실패 — 어긋난 날 %d개. 경보 AL-6 을 남겼다', count($bad)));
    exit(TBOX_CLI_MISMATCH);
}

/** 날짜 인자의 형태 검사 — YYYY-MM-DD 이고 실제로 존재하는 날짜인가 */
function chain_valid_date(?string $date): bool
{
    return is_string($date)
        && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $date) === 1
        && strtotime($date) !== false;
}

// ─── --reseal YYYY-MM-DD : 닫힌 날을 다시 봉인한다 (13단계, SECURITY R-50, 일회성) ───
//
// 기본 동작은 닫힌 날의 해시가 달라지면 AL-6 를 남기고 **고치지 않는다** — 그것이 변조 신호이기
// 때문이다. 이 옵션은 사람이 그 날의 파일을 직접 확인한 뒤, 잘못 적힌 항목 하나를 바로잡는
// 통로다. 조용히 덮어쓰지 않는다: 옛 값(해시·줄수·바이트수)을 감사 로그에 남기고 갱신한다.
$reseal = cli_option($argv, 'reseal');
if ($reseal !== null) {
    if (!chain_valid_date($reseal)) {
        cli_warn('[사용법 오류] --reseal 은 YYYY-MM-DD 형식이다');
        exit(TBOX_CLI_BAD_USAGE);
    }
    if ($reseal >= date('Y-m-d')) {
        // 오늘·미래는 "닫힌 날" 이 아니다. 오늘 항목은 기본 동작이 매일 갱신한다.
        cli_warn(sprintf('[사용법 오류] --reseal 은 닫힌 날(어제 이전)에만 쓴다: %s', $reseal));
        exit(TBOX_CLI_BAD_USAGE);
    }

    $chain = chain_read();
    $at    = null;
    foreach ($chain as $i => $e) {
        if ($e['date'] === $reseal) {
            $at = $i;
            break;
        }
    }
    if ($at === null) {
        cli_warn(sprintf('[사용법 오류] 체인에 %s 항목이 없다 — 재봉인할 것이 없다', $reseal));
        exit(TBOX_CLI_BAD_USAGE);
    }

    $path = chain_file_for($reseal);
    if ($path === null) {
        cli_warn(sprintf('[실패] %s 의 감사 로그 파일을 찾을 수 없다 — 재봉인하지 않는다', $reseal));
        exit(TBOX_CLI_FAIL);
    }

    [$hash, $lines, $bytes] = chain_hash_of($path);
    $old = $chain[$at];

    if (hash_equals($old['hash'], $hash) && $old['lines'] === $lines && $old['bytes'] === $bytes) {
        cli_say(sprintf('%s 는 이미 지금 파일과 같은 값이다 (%s…, %d줄) — 바꾸지 않는다.',
            $reseal, substr($hash, 0, 12), $lines));
        exit(TBOX_CLI_OK);
    }

    // 항목을 갱신하고, **뒤따르는 항목의 prev 를 다시 잇는다**.
    // prev 를 고치지 않으면 다음 날부터 연결이 끊겨 --verify 가 계속 실패한다.
    $chain[$at] = ['date' => $reseal, 'hash' => $hash, 'prev' => $old['prev'],
                   'lines' => $lines, 'bytes' => $bytes];
    $relinked = 0;
    $prev     = $hash;
    for ($i = $at + 1, $n = count($chain); $i < $n; $i++) {
        if (!hash_equals($chain[$i]['prev'], $prev)) {
            $chain[$i]['prev'] = $prev;
            $relinked++;
        }
        $prev = $chain[$i]['hash'];
    }
    /*
     * 기록을 **먼저** 남기고 체인 파일을 바꾼다 (PRD S-07 "기록 없는 민감 행동 금지").
     * 파일 쓰기는 트랜잭션에 묶을 수 없으므로 둘 중 하나는 먼저다. 순서를 이렇게 두면
     * 최악의 경우가 "기록은 있는데 체인은 그대로"(운영자가 다시 돌리면 된다)이고,
     * 반대로 두면 "체인은 바뀌었는데 기록이 없다" — 재봉인과 증거 조작이 구분되지 않는다.
     */
    Audit::allow('audit.integrity', [
        'target_type' => 'system',
        'target_name' => 'audit_chain_reseal',
        'detail'      => [
            'checked'             => $lines,
            'mismatches'          => 0,
            'log_date'            => $reseal,
            'chain_prev'          => $old['prev'],
            'chain_hash'          => $hash,
            'bytes'               => $bytes,
            // 무엇이 무엇으로 바뀌었나 (ARCH 7.5) — 이 세 값이 없으면 재봉인은 증거 조작과 같다
            'resealed_from_hash'  => $old['hash'],
            'resealed_from_lines' => $old['lines'],
            'resealed_from_bytes' => $old['bytes'],
            'relinked'            => $relinked,
        ],
    ]);

    chain_write($chain);

    cli_say(sprintf('%s 재봉인: %s… → %s… (%d줄 %d바이트, 뒤따르는 %d개 항목 재연결)',
        $reseal, substr($old['hash'], 0, 12), substr($hash, 0, 12), $lines, $bytes, $relinked));
    exit(TBOX_CLI_OK);
}

// ─── 기본 동작 : 한 날짜를 체인에 적는다 ───
//
// 인자가 없으면(또는 --yesterday) **어제**다 (13단계, SECURITY R-50 — 위 ★ 주석).
// cron 00:05 은 logrotate 00:00 직후라 "오늘 파일" 은 빈 파일이고, 봉인해야 할 것은
// 다 자란 어제 회전 파일이다. `--date` 로 날짜를 직접 주면 그 날짜를 쓴다.
$dateOpt = cli_option($argv, 'date');
$date    = $dateOpt ?? date('Y-m-d', (int) strtotime('yesterday'));
if ($dateOpt !== null && cli_flag($argv, 'yesterday')) {
    cli_warn('[사용법 오류] --date 와 --yesterday 를 함께 쓸 수 없다');
    exit(TBOX_CLI_BAD_USAGE);
}
if (!chain_valid_date($date)) {
    cli_warn('[사용법 오류] --date 는 YYYY-MM-DD 형식이다');
    exit(TBOX_CLI_BAD_USAGE);
}

/*
 * ★ 오늘·미래는 **봉인하지 않는다** (13단계 보정 2026-09-12, SECURITY R-50).
 *
 * 봉인은 "그 날의 값은 이것으로 확정" 이라는 선언이다. 오늘 파일은 하루 종일 자라므로
 * 확정할 값이 없다. 그런데도 `--date $(date +%F)` 로 오늘을 적으면 체인에 **자리표시가 아닌**
 * 값이 남고, 내일 00:05 의 `--yesterday` 가 그 날을 만나 해시가 달라 `closed_day_changed`
 * + AL-6 헛경보를 낸다 — 13단계가 없애려던 바로 그 경보다.
 *
 * 그래서 여기서 끝낸다. 체인 파일도 감사 로그도 **건드리기 전**이다(파일을 열지도, 해시를 만들지도
 * 않는다). 오늘 상태를 보고 싶으면 `--verify` 를 쓴다 — 그쪽은 오늘 항목을 「열린 날 — 검산 제외」로
 * 표기만 하고 아무것도 쓰지 않는다.
 */
if ($date >= date('Y-m-d')) {
    cli_warn('[사용법 오류] 오늘(열린 날)과 미래 날짜는 봉인하지 않는다 — 어제 이전만');
    exit(TBOX_CLI_BAD_USAGE);
}

$path = chain_file_for($date);
if ($path === null) {
    cli_say(sprintf('%s 의 감사 로그 파일이 없다 — 체인에 적지 않는다.', $date));
    exit(TBOX_CLI_OK);
}

[$hash, $lines, $bytes] = chain_hash_of($path);

$chain = chain_read();

// 같은 날짜 줄이 이미 있을 때의 세 갈래 (위 가드 덕분에 $date 는 언제나 **닫힌 날**이다).
//   ① 값이 같다              → 아무것도 하지 않는다 (같은 날 두 번 돌려도 안전)
//   ② 빈 자리표시였다        → 처음 봉인하듯 갈아 끼운다 (경보 없음, 옛 값은 detail 에)
//   ③ 그 밖에 값이 달라졌다  → 변조 신호다. 경보 AL-6 를 남기고 체인은 고치지 않는다
$prev  = CHAIN_GENESIS;
$found = null;
foreach ($chain as $i => $e) {
    if ($e['date'] === $date) {
        $found = $i;
        break;
    }
    $prev = $e['hash'];
}

$placeholderFrom = null;   // 자리표시를 갈아 끼웠다면 옛 값 (감사 detail 로만 쓴다)
$relinked        = 0;

if ($found !== null) {
    $old = $chain[$found];
    if ($old['hash'] === $hash) {
        cli_say(sprintf('%s 이미 같은 값으로 기록돼 있다 (%s…, %d줄) — 바꾸지 않는다.',
            $date, substr($hash, 0, 12), $lines));
    } elseif (chain_is_placeholder($old)) {
        /*
         * 옛 결함(R-50)이 남긴 **자리표시** 항목이다 — 0줄 0바이트 빈 파일 해시.
         * 이것은 "그 날의 확정 해시가 달라졌다" 가 아니라 "그 날은 아직 봉인된 적이 없다" 는 뜻이므로
         * AL-6 를 내지 않고 **처음 봉인하듯** 갈아 끼운다. 이 분기가 없으면 내일 00:05 cron 이
         * 어제(=자리표시가 남은 날)를 봉인하려다 `closed_day_changed` 헛경보를 내고 봉인을 거부한다.
         *
         * 조용히 덮어쓰지는 않는다: 옛 값(해시·줄수·바이트수)과 `placeholder: true` 를
         * `audit.integrity allow` 의 detail 에 남겨 무엇이 무엇으로 바뀌었는지 추적되게 한다
         * (`--reseal` 과 같은 방식, ARCH 7.5).
         *
         * 뒤따르는 항목의 `prev` 는 **옛 자리표시 해시를 가리키던 것만** 다시 잇는다. 그 연결은
         * 애초에 같은 결함의 산물이라 증거가 아니고, 고치지 않으면 `--verify` 가 영영 실패한다.
         * 다른 값을 가리키는 연결은 건드리지 않는다 — 진짜 변조 신호를 함께 지우지 않기 위해서다.
         */
        $placeholderFrom = $old;
        $chain[$found] = ['date' => $date, 'hash' => $hash, 'prev' => $old['prev'],
                          'lines' => $lines, 'bytes' => $bytes];
        // 이 항목을 가리키는 것은 **바로 뒤 한 줄**뿐이다 (체인은 날짜 순 한 줄 연결).
        $next = $found + 1;
        if (isset($chain[$next]) && hash_equals($chain[$next]['prev'], $old['hash'])) {
            $chain[$next]['prev'] = $hash;
            $relinked++;
        }
        chain_write($chain);
        cli_say(sprintf('%s 봉인 (빈 자리표시를 갈아 끼웠다): %s… → %s… %d줄 %d바이트, 뒤 연결 %d개 재연결',
            $date, substr($old['hash'], 0, 12), substr($hash, 0, 12), $lines, $bytes, $relinked));
    } else {
        // 지난 날 파일이 바뀌었다 = 변조 의심.
        // (2026-09-12 이전에는 여기에 `$date < 오늘` 조건이 붙어 있었고, 오늘이면 아래에서
        //  "오늘 파일은 계속 자란다" 며 갱신하는 갈래로 빠졌다. 이제 오늘·미래는 위 가드가
        //  기록 경로에 들어오기 전에 막으므로 그 갈래는 도달할 수 없어 지웠다.)
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
        // origin `system` — 행위자 없는 배치 판정 (F-69 A-1)
        cli_raise_alert('AL-6', [
            'rule_text' => '이미 닫힌 날의 감사 로그 해시가 달라짐 — 변조 의심',
            'source'    => 'audit_chain',
            'date'      => $date,
            'kind'      => 'closed_day_changed',
        ], Origin::SYSTEM);
        cli_warn(sprintf('%s 는 이미 닫힌 날인데 해시가 달라졌다 — 경보 AL-6. 체인은 고치지 않는다.', $date));
        exit(TBOX_CLI_MISMATCH);
    }
    $prevForLog = $old['prev'];
} else {
    // 새 날짜 — **날짜 순으로 바로 앞** 항목의 해시를 prev 로 이어 붙인다.
    // 전에는 "파일의 마지막 줄" 을 prev 로 삼고 나서 정렬했다. 매일 어제를 봉인하는 정상 흐름에서는
    // 둘이 같지만, 뒤 날짜가 이미 있는 상태에서 앞 날짜를 끼워 넣으면 연결이 어긋났다
    // (13단계, SECURITY R-50). 뒤따르는 항목의 prev 는 **고치지 않는다** — 그 재연결은 사람이
    // 판단하는 `--reseal` 의 일이고, 여기서 조용히 맞춰 버리면 변조 신호까지 함께 지워진다.
    $prev = CHAIN_GENESIS;
    foreach ($chain as $e) {
        if ($e['date'] < $date) {
            $prev = $e['hash'];
        }
    }
    $chain[] = ['date' => $date, 'hash' => $hash, 'prev' => $prev, 'lines' => $lines, 'bytes' => $bytes];
    usort($chain, static fn(array $a, array $b): int => strcmp($a['date'], $b['date']));
    chain_write($chain);
    cli_say(sprintf('%s 기록: %s… (직전 %s…) %d줄 %d바이트',
        $date, substr($hash, 0, 12), substr($prev, 0, 12), $lines, $bytes));
    $prevForLog = $prev;
}

$detail = [
    'checked'    => $lines,
    'mismatches' => 0,
    'chain_prev' => $prevForLog,
    'chain_hash' => $hash,
    'log_date'   => $date,
    'bytes'      => $bytes,
];

// 빈 자리표시를 갈아 끼운 경우에만 옛 값을 함께 남긴다 (ARCH 7.5 `resealed_from_*`).
// `placeholder: true` 가 "사람이 판단한 --reseal" 과 "배치가 자동으로 메운 자리표시" 를 가른다.
if ($placeholderFrom !== null) {
    $detail['resealed_from_hash']  = $placeholderFrom['hash'];
    $detail['resealed_from_lines'] = $placeholderFrom['lines'];
    $detail['resealed_from_bytes'] = $placeholderFrom['bytes'];
    $detail['placeholder']         = true;
    $detail['relinked']            = $relinked;
}

Audit::allow('audit.integrity', [
    'target_type' => 'system',
    'target_name' => 'audit_chain',
    'detail'      => $detail,
]);

exit(TBOX_CLI_OK);
