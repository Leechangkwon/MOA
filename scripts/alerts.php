<?php
declare(strict_types=1);

/**
 * scripts/alerts.php — 감사 로그를 5분마다 훑어 경보 규칙을 판정한다 (9단계)
 *   PRD 7.11 S-11-A (AL-1 · AL-2 · AL-5 · AL-7 · AL-10 · AL-12 · AL-13 · 서버 층 AL-3 · AL-9)
 *   구매·재고 AL-14 ~ AL-17 (PRD F-32 · F-35 · F-36, ARCH 5.18 「감사·경보」 · 11.4)
 *   ARCHITECTURE 11.1 (5분마다 cron) · 11.4 (경보 구성) · 7.4 `alert.raised|allow` · 7.5 `rule`·`evidence`
 *
 * 사용법
 *   sudo php scripts/alerts.php [--dry-run] [--rule AL-1] [--auth-log <경로>] [--state <경로>]
 *     --dry-run   : 판정만 하고 경보를 남기지 않는다 (규칙을 고친 뒤 확인용)
 *     --rule      : 한 규칙만 돌린다
 *     --auth-log  : /var/log/auth.log 대신 **그 파일**을 읽는다 (16-2 시험용).
 *                   이때는 읽기 오프셋을 상태 파일에 **쓰지 않는다** — 시험이 운영의
 *                   읽기 위치를 밀어 놓으면 그 사이의 진짜 줄을 영영 건너뛰기 때문이다.
 *     --state     : 중복 억제 상태를 **그 파일**에서 읽고 그 파일에 쓴다 (23단계 시험용).
 *                   5분마다 도는 cron 이 이미 발동시켜 둔 대상은 운영 상태 파일에서
 *                   "억제됨" 이라, 「처음 발동 → 다시 억제」를 확인하려면 빈 상태가 필요하다.
 *                   운영 상태 파일을 시험이 건드리지 않게 하는 것이 이 인자의 목적이다.
 *
 * 왜 SQL 인가: 경보는 "이미 남은 기록" 을 읽어 판정한다. 앱 코드에 경보 조건을 흩어 두면
 * 새 핸들러를 만들 때마다 빠뜨리고, 무엇보다 **기록되지 않은 일은 경보도 못 한다**는 사실이
 * 흐려진다. 판정은 audit_logs 한 곳만 본다 (ARCH 11.4).
 *
 * 중복 억제 (PRD S-11-A "즉시 알림" 이 곧 "5분마다 같은 알림" 이 되면 아무도 안 본다)
 *   `/var/lib/tbox-v2/alerts-state.json` 에 `규칙|대상` 별 마지막 발동 시각을 적고,
 *   30분 안에는 같은 대상으로 다시 발동하지 않는다. **구매·재고 규칙(AL-14~17)은 24시간**이다
 *   (23단계) — 조건이 며칠씩 그대로 참이라 30분이면 같은 줄이 하루 48번 울린다.
 *
 * 알림 채널: 이 VM 에는 없다. 발동하면 `/var/log/tbox-v2/alerts.log` 한 줄 JSON +
 * `alert.raised allow` 감사 기록까지다 (cli_raise_alert, `.env` 의 ALERT_COMMAND 주석 참고).
 *
 * 서버 층 규칙 — 16-2 추가 (2026-09-11 사고 후속, SECURITY 8.4-5, ARCH 11.4, PRD T-37)
 *   앱의 감사 로그만 보면 **앱 밖에서 일어난 일**(SSH 접속·sudo 실행)은 보이지 않는다.
 *   2026-09-11 침투는 전부 앱 밖이었고 audit_logs 에는 흔적이 없었다. 그래서 두 규칙이
 *   `/var/log/auth.log`(와 회전된 `.1`)를 함께 읽는다.
 *     AL-3 (확장) : `sshd … Accepted (publickey|password) for <계정> from <IP>` 중
 *                   최근 30일 안에 본 적 없는 `계정@IP` → 경보
 *     AL-9        : `sudo: <계정> : … COMMAND=<명령>` 의 명령이 데이터·로그를 지우거나 통째로
 *                   읽는 종류(psql·pg_dump·pg_restore·dropdb·truncate·chattr·rm·find -delete)면 경보
 *   읽기 위치(inode·오프셋)는 상태 파일에 둔다 — 5분마다 전체를 다시 읽지 않고, 회전되면
 *   (inode 가 바뀌면) 처음부터 읽는다. auth.log 는 0640 syslog:adm 이라 **root 가 아니면 읽지
 *   못한다**; 그때는 두 규칙만 `[건너뜀]` 을 찍고 나머지 규칙은 그대로 판정한다.
 *
 * 이 스크립트가 판정하지 않는 규칙과 이유
 *   AL-4 (대량 다운로드) · AL-11 (일일 요약) — 9단계 범위 밖(지시서)
 *   AL-8 (백업 2회 연속 실패) — scripts/verify_backup.sh 가 직접 판정한다(실패 횟수 파일)
 *   AL-9 의 나머지 절반(새 리스닝 포트·`/var/www` 파일 변경, PRD 7.11) — 감사 로그도 auth.log 도
 *        아니라 OS 상태를 봐야 한다. scripts/check_baseline.sh 가 현재 상태만 보여 준다(미자동화)
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

const STATE_FILE     = '/var/lib/tbox-v2/alerts-state.json';
/** 같은 규칙·같은 대상은 이 시간 안에 다시 발동하지 않는다 (분) */
const DEDUP_MINUTES  = 30;

/** 서버 층 규칙이 읽는 인증 로그 (16-2). 회전된 직전 파일까지 본다 */
const AUTH_LOG       = '/var/log/auth.log';
const AUTH_LOG_PREV  = '/var/log/auth.log.1';
/** AL-3: `계정@IP` 를 처음 본 시각을 이 기간 동안 기억한다 (일) */
const SSH_SEEN_DAYS  = 30;
/** AL-9: 명령은 앞 이만큼만 근거에 남긴다 (인자에 비밀값이 섞여 있을 수 있다) */
const SUDO_CMD_CHARS = 200;
/** 한 번에 읽는 auth.log 의 최대 바이트 — 회전 직후의 큰 파일로 메모리를 쓰지 않게 */
const AUTH_LOG_MAX_BYTES = 4 * 1024 * 1024;

/* ─────────────────────────────────────────────────────────────
 * 구매·재고 경보의 **임계값 상수** — 23단계에 확정 (PRD F-36, ARCH 5.18 「감사·경보」)
 *
 * 21·22단계는 규칙 골격만 두고 "임계값은 23단계에서 정한다" 고 적어 두었다. 그 값이 여기다.
 * 숫자를 규칙 본문이 아니라 **여기 한 곳**에 두는 이유: 운영하며 조이거나 푸는 것은 숫자뿐이고,
 * 그때 고치는 자리가 하나여야 "지금 임계값이 얼마냐" 는 물음에 코드가 바로 답한다.
 * ───────────────────────────────────────────────────────────── */

/** AL-14~17 은 **하루 한 번**이다 — 같은 미입고 줄이 5분마다 울리면 아무도 보지 않는다 (분) */
const PURCHASE_DEDUP_MINUTES = 24 * 60;
/** 그 규칙들 (중복 억제 창이 다른 규칙 목록) */
const PURCHASE_RULES         = ['AL-14', 'AL-15', 'AL-16', 'AL-17'];

/** AL-15: 보낸 발주서가 이만큼 지나도록 입고가 없으면 미입고 경보 (일) */
const BACKORDER_DAYS         = 30;
/** AL-16: 단가가 이 비율을 **넘게** 벌어지면 급변 (0.20 = 20%) */
const PRICE_JUMP_RATIO       = 0.20;
/** AL-16: 입고 단가 견주기는 최근 이만큼 안에 만든 입고 전표만 본다 (일) */
const PRICE_SCAN_DAYS        = 7;
/** AL-17: 한 사람이 이 시간(시) 안에 이만큼 취소하면 대량 취소 */
const VOID_BURST_HOURS       = 1;
const VOID_BURST_COUNT       = 5;
/** 구매 규칙이 한 번에 올리는 최대 건수 — 한 배치가 알림 채널을 덮지 않게 */
const PURCHASE_ALERT_LIMIT   = 200;

/*
 * ★ 구매 규칙의 근거 값에는 **계정명·전표 번호·품목 코드를 싣지 않는다** (23단계 지시서).
 *   경보는 앱 밖(메신저·메일)으로 나가는 글이다. 무엇이 일어났는지와 어디를 보면 되는지
 *   (id) 만 있으면 되고, 누가·어느 전표·어느 품목인지는 **감사 로그를 열어** 확인한다.
 *   앞선 규칙(AL-1·AL-5·AL-13)이 이름을 싣는 것은 9~17단계의 결정이며 여기서 바꾸지 않는다 —
 *   바꾸려면 그 규칙들을 함께 보는 별도 판단이 필요하다.
 */

$argv    = $_SERVER['argv'];
$dryRun  = cli_flag($argv, 'dry-run');
$onlyRaw = cli_option($argv, 'rule');
if ($onlyRaw !== null && preg_match('/^AL-[0-9]{1,2}$/', $onlyRaw) !== 1) {
    cli_warn('[사용법 오류] --rule 은 AL-n 형식이다');
    exit(TBOX_CLI_BAD_USAGE);
}
$only = $onlyRaw;

/*
 * 16-2 — 시험용 인자. 사본 auth.log 를 읽어 AL-3·AL-9 를 확인한다 (PRD T-37).
 * 경로는 **운영자가 셸에서 준 값**이지 요청 값이 아니다. 그래도 파일이 실제로 읽히는지만
 * 확인하고(존재·읽기 권한), 이 값으로 무엇을 만들거나 실행하지 않는다.
 * 이 인자를 쓰면 상태 파일의 읽기 오프셋을 **갱신하지 않는다** — 아래 al_read_auth_log 참고.
 */
$authLogArg = cli_option($argv, 'auth-log');
if ($authLogArg !== null && ($authLogArg === '' || !is_file($authLogArg) || !is_readable($authLogArg))) {
    cli_warn('[사용법 오류] --auth-log 파일을 읽을 수 없다: ' . $authLogArg);
    exit(TBOX_CLI_BAD_USAGE);
}

/*
 * 23단계 — 시험용 상태 파일. `--auth-log` 와 같은 성격의 인자다: 운영자가 셸에서 준 경로이고,
 * 요청 값이 아니며, 이 값으로 무엇을 만들거나 실행하지 않는다. 파일이 없으면 빈 상태로 시작한다
 * (첫 실행이 곧 "처음 발동" 이다). 경로 꼴만 좁게 본다 — 디렉터리가 실제로 있어야 한다.
 */
$stateArg = cli_option($argv, 'state');
if ($stateArg !== null && ($stateArg === '' || !is_dir(dirname($stateArg)))) {
    cli_warn('[사용법 오류] --state 경로의 디렉터리가 없다: ' . $stateArg);
    exit(TBOX_CLI_BAD_USAGE);
}
$stateFile = $stateArg ?? STATE_FILE;

$db = Db::instance();

// ─── 중복 억제 상태 ─────────────────────────────────────────────
/** @var array<string,string> `규칙|대상` => 마지막 발동 시각(ISO) */
$state = [];
/**
 * 16-2 — 서버 층 규칙이 함께 쓰는 상태 (같은 파일 안의 **예약 키** 세 개).
 * 중복 억제 키는 언제나 `AL-n|대상` 형태라 이 세 이름과 부딪히지 않는다.
 *   ssh_seen        `계정@IP` => 처음 본 시각(ISO). 30일이 지난 항목은 저장할 때 버린다.
 *   auth_log_inode  마지막으로 읽은 auth.log 의 inode — 바뀌었으면 회전된 것이다.
 *   auth_log_offset 그 파일에서 어디까지 읽었나 (바이트).
 */
$sshSeen = [];
$authPos = ['inode' => 0, 'offset' => 0];

if (is_file($stateFile)) {
    $raw = file_get_contents($stateFile);
    if ($raw !== false && $raw !== '') {
        $parsed = json_decode($raw, true, 5);
        if (is_array($parsed)) {
            foreach ($parsed as $k => $v) {
                if ($k === 'ssh_seen') {
                    if (is_array($v)) {
                        foreach ($v as $seenKey => $seenAt) {
                            if (is_string($seenKey) && is_string($seenAt)) {
                                $sshSeen[$seenKey] = $seenAt;
                            }
                        }
                    }
                    continue;
                }
                if ($k === 'auth_log_inode' && is_int($v)) {
                    $authPos['inode'] = $v;
                    continue;
                }
                if ($k === 'auth_log_offset' && is_int($v)) {
                    $authPos['offset'] = $v;
                    continue;
                }
                if (is_string($k) && is_string($v)) {
                    $state[$k] = $v;
                }
            }
        }
    }
}
$stateChanged = false;
$now = time();
$fired = 0;

/**
 * 규칙 하나를 발동한다 — 중복 억제를 통과할 때만.
 *
 * @param array<string,mixed> $evidence
 */
$raise = function (string $rule, string $subject, array $evidence) use (
    &$state, &$stateChanged, &$fired, $now, $dryRun
): void {
    /*
     * 중복 억제 창은 규칙마다 다르다 (23단계). 인증·접근 규칙은 30분이지만, 구매·재고 규칙은
     * "일어나지 않은 일"(미입고)이나 "이미 남은 판정"(단가 차이)을 보므로 조건이 **며칠씩
     * 그대로 참**이다. 30분이면 같은 줄이 하루 48번 울린다 — 하루 한 번으로 둔다.
     */
    $windowMinutes = in_array($rule, PURCHASE_RULES, true) ? PURCHASE_DEDUP_MINUTES : DEDUP_MINUTES;

    $key  = $rule . '|' . $subject;
    $last = isset($state[$key]) ? strtotime($state[$key]) : false;
    if ($last !== false && ($now - $last) < $windowMinutes * 60) {
        cli_say(sprintf('  %s %s — %d분 안에 이미 발동해 건너뛴다', $rule, $subject, $windowMinutes));
        return;
    }
    if ($dryRun) {
        cli_say(sprintf('  [dry-run] %s %s %s', $rule, $subject, json_encode($evidence, JSON_UNESCAPED_UNICODE)));
        return;
    }
    cli_raise_alert($rule, ['subject' => $subject] + $evidence);
    $state[$key]  = date('c', $now);
    $stateChanged = true;
    $fired++;
    cli_say(sprintf('  ★ %s %s 발동', $rule, $subject));
};

$want = static fn(string $rule): bool => $only === null || $only === $rule;

/**
 * `auth.log` 의 **새로 늘어난 부분**을 읽어 줄 목록으로 돌려준다 (16-2, ARCH 11.4).
 *
 * 왜 오프셋인가: 5분마다 도는 배치가 매번 파일 전체를 읽으면 ① 같은 줄을 30일 내내 다시
 * 판정하고 ② 커진 파일에서 시간·메모리를 쓴다. 그래서 "어디까지 읽었나" 를 상태 파일에
 * 남긴다. 파일이 **회전**되면 inode 가 바뀌므로 그것으로 알아채고, 회전된 직전 파일
 * (`auth.log.1`)의 남은 꼬리를 먼저 읽은 뒤 새 파일을 처음부터 읽는다 — 회전 순간의 줄이
 * 사라지지 않게. 파일이 잘렸으면(크기 < 오프셋) 처음부터 읽는다.
 *
 * `$override`(= `--auth-log`)가 있으면 그 파일을 **통째로** 읽고 오프셋은 돌려주지 않는다
 * (`advance = false`). 시험이 운영의 읽기 위치를 밀어 놓으면 그 사이에 들어온 진짜 줄을
 * 영영 건너뛰기 때문이다.
 *
 * 읽지 못하면(권한 없음 = root 가 아님) `ok = false` 를 돌려주고, 부르는 쪽이 그 규칙만
 * 건너뛴다. 나머지 규칙(audit_logs SQL)은 그대로 돈다.
 *
 * @param array{inode:int,offset:int} $pos
 * @return array{ok:bool, reason:string, lines:list<string>, inode:int, offset:int, advance:bool, path:string}
 */
function al_read_auth_log(?string $override, array $pos): array
{
    $empty = [
        'ok'      => false,
        'reason'  => '',
        'lines'   => [],
        'inode'   => $pos['inode'],
        'offset'  => $pos['offset'],
        'advance' => false,
        'path'    => $override ?? AUTH_LOG,
    ];

    // ── 시험 모드: 사본 파일을 통째로 읽고 오프셋은 건드리지 않는다 ──
    if ($override !== null) {
        $text = file_get_contents($override, false, null, 0, AUTH_LOG_MAX_BYTES);
        if ($text === false) {
            return ['reason' => 'unreadable'] + $empty;
        }
        return [
            'ok'      => true,
            'reason'  => '',
            'lines'   => al_split_lines($text),
            'inode'   => $pos['inode'],
            'offset'  => $pos['offset'],
            'advance' => false,
            'path'    => $override,
        ];
    }

    if (!is_readable(AUTH_LOG)) {
        // 0640 syslog:adm — root 가 아니면 여기서 끝난다 (cron 은 root 로 돈다)
        return ['reason' => 'permission'] + $empty;
    }

    $stat = @stat(AUTH_LOG);
    if (!is_array($stat)) {
        return ['reason' => 'unreadable'] + $empty;
    }
    $inode = (int) $stat['ino'];
    $size  = (int) $stat['size'];

    $lines  = [];
    $offset = $pos['offset'];

    if ($inode !== $pos['inode']) {
        // 회전됐거나 첫 실행이다. 직전 파일의 남은 꼬리를 먼저 읽어 둔다(있으면).
        if ($pos['inode'] !== 0 && $pos['offset'] > 0 && is_readable(AUTH_LOG_PREV)) {
            $prevStat = @stat(AUTH_LOG_PREV);
            if (is_array($prevStat) && (int) $prevStat['ino'] === $pos['inode']
                && (int) $prevStat['size'] > $pos['offset']
            ) {
                $tail = file_get_contents(
                    AUTH_LOG_PREV,
                    false,
                    null,
                    $pos['offset'],
                    min(AUTH_LOG_MAX_BYTES, (int) $prevStat['size'] - $pos['offset'])
                );
                if ($tail !== false) {
                    $lines = al_split_lines($tail);
                }
            }
        }
        $offset = 0;
    } elseif ($size < $offset) {
        // 잘렸다(truncate). 처음부터 다시 읽는다.
        $offset = 0;
    }

    if ($size > $offset) {
        $take = min(AUTH_LOG_MAX_BYTES, $size - $offset);
        $text = file_get_contents(AUTH_LOG, false, null, $offset, $take);
        if ($text === false) {
            return ['reason' => 'unreadable'] + $empty;
        }
        $lines  = array_merge($lines, al_split_lines($text));
        $offset += $take;
    }

    return [
        'ok'      => true,
        'reason'  => '',
        'lines'   => $lines,
        'inode'   => $inode,
        'offset'  => $offset,
        'advance' => true,
        'path'    => AUTH_LOG,
    ];
}

/** 읽은 덩어리를 줄 단위로 자른다 (빈 줄은 버린다) */
function al_split_lines(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if (is_string($line) && $line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** 로그 줄 앞의 시각 표기만 떼어 낸다 (`Sep 12 16:32:01` 또는 RFC3339). 없으면 빈 문자열 */
function al_line_time(string $line): string
{
    if (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}|\d{4}-\d{2}-\d{2}T[0-9:.+\-]+)/', $line, $m) === 1) {
        return $m[1];
    }
    return '';
}

/**
 * sudo 명령에서 **비밀값 조각을 지우고** 앞 200자만 남긴다 (16-2).
 * `PGPASSWORD=…`·`password=…` 는 통째로 `[제거됨]` 으로 바꾼다 — 경보 근거는 감사 로그에도
 * 들어가므로 "무엇을 실행했나" 만 남고 "그 값" 은 남지 않아야 한다 (PRD 7.7).
 */
function al_scrub_command(string $cmd): string
{
    /* 가리는 형태 두 가지
     *   ① `PGPASSWORD=…` · `password=…`            (환경변수·인자)
     *   ② `PASSWORD '…'` · `PASSWORD "…"`          (SQL `CREATE ROLE … PASSWORD '…'`)
     * ②는 17단계 추가다 — 16단계 AL-9 첫 실행이 과거 이력을 재생하며 v1 설치 명령
     * `CREATE USER tbox WITH PASSWORD '…'` 를 **그대로** 경보 근거에 남긴 것을 관찰했다
     * (STAGES 2026-09-12, ARCH 11.4 「AL-13 · AL-2 보강」). 경보는 위험을 알리는 글이지
     * 비밀번호를 옮겨 적는 글이 아니다 (PRD S-07 민감정보 금지).
     */
    $clean = (string) preg_replace('/(?:PGPASSWORD|password)=\S+/i', '[제거됨]', $cmd);
    $clean = (string) preg_replace('/\bPASSWORD\s+\'[^\']*\'/i', 'PASSWORD [제거됨]', $clean);
    $clean = (string) preg_replace('/\bPASSWORD\s+"[^"]*"/i', 'PASSWORD [제거됨]', $clean);
    $utf8  = iconv('UTF-8', 'UTF-8//IGNORE', $clean);
    $clean = is_string($utf8) ? $utf8 : '';
    if (iconv_strlen($clean, 'UTF-8') > SUDO_CMD_CHARS) {
        $clean = (string) iconv_substr($clean, 0, SUDO_CMD_CHARS, 'UTF-8');
    }
    return $clean;
}

cli_say('경보 판정 시작 (' . date('c') . ')');

// ─────────────────────────────────────────────────────────────
// AL-1 — 권한 우회 시도 (PRD 7.11)
//   "한 사용자가 admin-only·team 파일 접근 deny 10분 내 5회 이상"
//   실제 남는 행의 모양(4·5·8단계)으로 풀어 쓰면:
//     · action  : file.download / file.delete / file.update / file.level_change / authz.route
//     · result  : deny
//     · 그리고  : 등급이 admin-only·team 이거나, 사유가 role_not_permitted·team_mismatch
//   등급과 사유를 OR 로 묶는 이유: authz.route(관리 기능 호출)에는 access_level 이 없고,
//   반대로 파일 행에는 reason 대신 등급만 의미 있는 경우가 있다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-1')) {
    $rows = $db->run(
        "SELECT actor_name, count(*) AS n,
                min(happened_at) AS first_at, max(happened_at) AS last_at
           FROM audit_logs
          WHERE result = 'deny'
            AND actor_name IS NOT NULL
            AND action IN ('file.download','file.delete','file.update','file.level_change','authz.route')
            AND (access_level IN ('admin-only','team')
                 OR reason IN ('role_not_permitted','team_mismatch'))
            AND happened_at > now() - interval '10 minutes'
          GROUP BY actor_name
         HAVING count(*) >= 5"
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-1', (string) $r['actor_name'], [
            'rule_text' => 'admin-only·team 접근 거부 10분 내 5회 이상',
            'count'     => (int) $r['n'],
            'window'    => '10 minutes',
            'first_at'  => (string) $r['first_at'],
            'last_at'   => (string) $r['last_at'],
        ]);
    }
    cli_say(sprintf('AL-1 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-2 — 브루트포스·잠금 DoS (PRD 7.11)
//   "로그인 실패 IP 단위 10분 내 20회 또는 계정 잠금 발생"
//   로그인 행은 세션 전이라 actor_* 가 비어 있다 — 대상 계정은 target_name 이다 (ARCH 7.3).
//   그래서 묶는 기준은 ip 다.
//
// ★ 13단계(2026-09-12, SECURITY R-45 · R-48)에서 집계 대상을 넓혔다.
//   전에는 `action = 'auth.login'` 만 셌다. 그런데 같은 "비밀번호·코드 추측" 은 **로그인 뒤**
//   창구에서도 일어난다 — 세션 쿠키를 훔친 사람은 `/api/me/password` 로 현재 비밀번호를,
//   `/api/me/mfa/disable` 로 비밀번호와 OTP 를 시험할 수 있다(R-48 실측). 13단계에서 이 실패들도
//   계정 잠금 카운터에 들어갔으므로, 경보도 같은 신호로 봐야 한다. 그러지 않으면 잠금은 걸리는데
//   **경보는 조용한** 상태가 된다.
//   포함하는 조합 (거부 사유는 전부 ARCH 7.4 등록부의 값이다)
//     · auth.login          deny  전부 (bad_credentials · locked · inactive — 기존 그대로)
//     · auth.mfa            deny  bad_code · code_reused
//     · auth.password_change deny bad_credentials
//     · auth.mfa_disable    deny  bad_credentials · bad_code · code_reused
//     · auth.mfa_enroll     deny  bad_code · code_reused
//     · auth.reauth          deny  bad_credentials   ← 17단계 추가 (ARCH 11.4 「AL-13 · AL-2 보강」)
//   17단계(2026-09-12)에 `auth.reauth deny bad_credentials` 를 더했다. 16단계에서 생긴
//   재인증 창구(`POST /api/me/reauth`)도 **세션만 있으면 닿으면서 현재 비밀번호를 받는** 곳이다 —
//   훔친 쿠키를 쥐 사람에게는 비밀번호를 시험할 창구이고, 그 실패는 이미 계정 잠금 카운터에
//   들어간다(Auth::countFailureAndRevoke). 세는 곳에서 빠져 있으면 "잠기는데 경보는 조용한"
//   상태가 다시 생긴다 (SECURITY R-59, 13단계와 같은 이유).
//   빼는 것: `auth.mfa deny code_required` (순차 2단계 로그인의 정상 1단계다 — 실패가 아니다.
//   Auth::login 도 이것은 실패 횟수에 세지 않는다, ARCH 4.0), `already_enabled`·`weak_password`·
//   `same_password` (추측 시도가 아니다).
//   임계값·창은 그대로다: 10분 20회 이상, 또는 그 창에 계정 잠금(`reason = 'locked'`)이 한 번이라도.
// ─────────────────────────────────────────────────────────────
if ($want('AL-2')) {
    $rows = $db->run(
        "SELECT host(ip) AS src, count(*) AS n,
                count(*) FILTER (WHERE reason = 'locked') AS locked,
                count(*) FILTER (WHERE action <> 'auth.login') AS after_login,
                array_to_string(array_agg(DISTINCT action ORDER BY action), ',') AS actions,
                max(happened_at) AS last_at
           FROM audit_logs
          WHERE result = 'deny' AND ip IS NOT NULL
            AND happened_at > now() - interval '10 minutes'
            AND (
                     action = 'auth.login'
                  OR (action = 'auth.mfa'             AND reason IN ('bad_code','code_reused'))
                  OR (action = 'auth.password_change' AND reason = 'bad_credentials')
                  OR (action = 'auth.mfa_disable'     AND reason IN ('bad_credentials','bad_code','code_reused'))
                  OR (action = 'auth.mfa_enroll'      AND reason IN ('bad_code','code_reused'))
                  OR (action = 'auth.reauth'          AND reason = 'bad_credentials')
                )
          GROUP BY host(ip)
         HAVING count(*) >= 20 OR count(*) FILTER (WHERE reason = 'locked') > 0"
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-2', (string) $r['src'], [
            'rule_text'   => '인증 실패(로그인·비밀번호 변경·2단계 인증·재인증) 10분 내 20회 이상 또는 계정 잠금 발생',
            'count'       => (int) $r['n'],
            'locked'      => (int) $r['locked'],
            // 로그인 창구가 아니라 **로그인 뒤** 창구에서 난 실패 수 — 세션 탈취 뒤의 추측과
            // 바깥에서의 무차별 대입을 한눈에 가른다 (R-48)
            'after_login' => (int) $r['after_login'],
            'actions'     => (string) $r['actions'],
            'window'      => '10 minutes',
            'last_at'     => (string) $r['last_at'],
        ]);
    }
    cli_say(sprintf('AL-2 대상 %d개 IP', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-5 — 접근 레벨 하향 (PRD 7.11 "admin-only → 그 외")
//   노출 범위가 넓어지는 변경이다. 정당한 변경이어도 사후 확인 대상이다.
//   등급 순서는 public(1) < internal(2) < team(3) < admin-only(4) — 숫자가 작아지면 하향.
//   detail.before/after 는 5단계 files_update 가 객체 두 개로 남긴다 (ARCH 7.5).
// ─────────────────────────────────────────────────────────────
if ($want('AL-5')) {
    $rows = $db->run(
        "SELECT id, actor_name, target_name,
                detail->'before'->>'access_level' AS before_level,
                detail->'after'->>'access_level'  AS after_level,
                happened_at
           FROM audit_logs
          WHERE action = 'file.level_change' AND result = 'allow'
            AND happened_at > now() - interval '10 minutes'
            AND CASE detail->'before'->>'access_level'
                     WHEN 'public' THEN 1 WHEN 'internal' THEN 2
                     WHEN 'team' THEN 3 WHEN 'admin-only' THEN 4 ELSE 0 END
              > CASE detail->'after'->>'access_level'
                     WHEN 'public' THEN 1 WHEN 'internal' THEN 2
                     WHEN 'team' THEN 3 WHEN 'admin-only' THEN 4 ELSE 0 END"
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-5', 'audit_log#' . (string) $r['id'], [
            'rule_text' => '접근 등급 하향 (노출 범위 확대)',
            'actor'     => (string) $r['actor_name'],
            'target'    => (string) $r['target_name'],
            'before'    => (string) $r['before_level'],
            'after'     => (string) $r['after_level'],
            'at'        => (string) $r['happened_at'],
        ]);
    }
    cli_say(sprintf('AL-5 대상 %d건', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-14 — 「보냄」 뒤 발주서 수정·취소 (20단계, PRD F-32 · Q-18④, ARCH 5.18 「감사·경보」)
//
//   발주서는 한 번 보내면 거래처 쪽에 **이미 나간 문서**다. 그 뒤의 수정·취소는 서버가
//   막지 않는다(현실에서 일어나는 일이라 막으면 장부 밖에서 처리된다) — 대신 **사유를 요구**하고
//   그 기록을 여기서 본다. 금액·수량을 조용히 바꿔 대사를 맞추는 일을 사후에 드러내는 규칙이다.
//
//   근거 값은 핸들러가 남기는 `detail.after_sent = true` 하나다
//   (purchase_po_update.php · purchase_po_void.php). 사유는 `detail.reason_text` 에 있다.
//   AL-5(접근 등급 하향)와 같은 모양이며, 이 파일에 새 골격을 만들지 않는다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-14')) {
    /*
     * 22단계 — 「**마감 뒤** 수정·취소」도 같은 규칙으로 본다 (ARCH 5.18 「감사·경보」의
     * AL-14 정의가 원래 그것이다). 근거 값은 핸들러가 남기는 `detail.after_close = true` 이고,
     * 그 행은 `purchase.close` 를 가진 사람이 **사유를 적어** 지나간 요청이다
     * (Doc::closeGuard). 막지 않고 드러내는 것이 이 규칙의 뜻이다.
     */
    $rows = $db->run(
        "SELECT id, actor_id, action,
                detail->>'reason_text' AS reason_text,
                detail->>'after_close' AS after_close,
                happened_at
           FROM audit_logs
          WHERE action IN ('purchase.order.update', 'purchase.order.void',
                           'purchase.request.update',
                           'purchase.receipt.void', 'purchase.move.void',
                           'purchase.use.void', 'purchase.count.void')
            AND result = 'allow'
            AND (detail->>'after_sent' = 'true' OR detail->>'after_close' = 'true')
            AND happened_at > now() - interval '10 minutes'"
    )->fetchAll();
    foreach ($rows as $r) {
        /*
         * 23단계 — 근거에 **계정명·전표 번호를 싣지 않는다**(지시서 23단계 금지 패턴).
         * 대신 감사 로그 행 id(= 대상 문자열)와 행위자 id 를 남긴다. 경보를 받은 사람은
         * `audit_logs` 의 그 한 줄을 열어 누가·무엇을·왜 였는지 **원본에서** 읽는다 —
         * 알림 채널(메신저·메일)은 앱 밖이고, 거기 남는 글에는 이름을 두지 않는다.
         * 사유(reason_text)도 사람이 적은 자유 문자열이라 여기서는 **적었는지 여부**만 싣는다.
         */
        $raise('AL-14', 'audit_log#' . (string) $r['id'], [
            'rule_text'   => ((string) ($r['after_close'] ?? '')) === 'true'
                ? '**월 마감된 달**의 전표를 고치거나 취소했다'
                : '거래처에 보낸 뒤 발주서를 고치거나 취소했다',
            'actor_id'    => $r['actor_id'] === null ? null : (int) $r['actor_id'],
            'action'      => (string) $r['action'],
            'has_reason'  => ((string) ($r['reason_text'] ?? '')) !== '',
            'at'          => (string) $r['happened_at'],
        ]);
    }
    cli_say(sprintf('AL-14 대상 %d건', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-15 — 미입고 30일 (21단계, PRD F-34 R6, ARCH 5.18 「감사·경보」)
//
//   보낸 발주서인데 **한 달이 지나도록 물건이 오지 않은 줄**을 올린다. 다른 규칙과 달리
//   근거가 감사 로그가 아니라 **뷰 `p_backorder`** 다 — "일어난 사건" 이 아니라 "일어나지
//   않은 일" 을 보는 규칙이기 때문이다(로그에는 아무것도 남지 않는다).
//
//   ★ 23단계 확정 — 임계값은 `BACKORDER_DAYS`(30일), 중복 억제는 **거래처마다 하루 한 번**
//     (`PURCHASE_DEDUP_MINUTES`). 알림 통로는 `cli_raise_alert` 하나다(alerts.log + 감사
//     `alert.raised`; `.env` 의 `ALERT_COMMAND` 가 비어 있으면 `dispatch: log_only`).
//
//   ★ 23단계 — **거래처별 한 문장**으로 묶는다 (지시서 보충 3). 전에는 발주서 줄마다 한 건씩
//     올려, 한 거래처가 30줄 밀리면 알림이 30개였다. 사람이 할 일은 "그 거래처에 전화하기"
//     하나이므로 알림도 하나여야 한다. 줄 수·전표 수·가장 오래된 일수를 근거로 싣는다.
//
//   ★ 근거에 **전표 번호·품목 코드를 싣지 않는다** (23단계 금지 패턴). 거래처 이름은
//     문장 자체가 「어느 거래처」를 가리켜야 쓸모가 있으므로 남긴다 — 회사 이름이지 개인정보가
//     아니다. 어느 줄인지는 화면(미입고 현황)에서 그 거래처로 걸러 본다.
//
//   지점 격리는 이 배치에 없다 — CLI 는 사람이 아니라 운영자(root)가 돌리고 경보는 구매실이 받는다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-15')) {
    $rows = $db->run(
        "SELECT bo.vendor_id,
                COALESCE(v.name, '(거래처 미지정)')          AS vendor_name,
                count(*)                                     AS line_count,
                count(DISTINCT bo.po_doc_id)                 AS doc_count,
                max((date_part('day', now() - bo.sent_at))::int) AS max_days,
                CAST(sum(bo.remaining_qty) AS text)          AS remaining_qty
           FROM p_backorder bo
           LEFT JOIN p_vendors v ON v.id = bo.vendor_id
          WHERE bo.sent_at < now() - (CAST(:days AS int) * interval '1 day')
          GROUP BY bo.vendor_id, v.name
          ORDER BY max_days DESC
          LIMIT :lim",
        [':days' => BACKORDER_DAYS, ':lim' => PURCHASE_ALERT_LIMIT]
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-15', 'purchase_vendor#' . (string) ($r['vendor_id'] ?? '0'), [
            // 지시서 보충 3 의 문장 — 「거래처 X 발주서 N건 미입고 30일 초과」
            'rule_text' => sprintf(
                '거래처 「%s」 발주서 %d건 미입고 %d일 초과',
                (string) $r['vendor_name'],
                (int) $r['doc_count'],
                BACKORDER_DAYS
            ),
            'vendor_id' => $r['vendor_id'] === null ? null : (int) $r['vendor_id'],
            'docs'      => (int) $r['doc_count'],
            'lines'     => (int) $r['line_count'],
            'remaining' => (string) $r['remaining_qty'],
            'max_days'  => (int) $r['max_days'],
            'threshold' => BACKORDER_DAYS,
        ]);
    }
    cli_say(sprintf('AL-15 대상 %d개 거래처', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-16 — 단가 급변 (22단계, PRD F-35, ARCH 5.18 「감사·경보」)
//
//   확정된 **월 대사**에서 「단가 차이」로 판정된 줄 가운데, 우리 단가 대비 명세서 단가가
//   ±20% 이상 벌어진 것을 올린다. 오타 한 자리(23,750 → 237,500)와 말없는 인상이 여기서
//   드러난다. 근거는 감사 로그가 아니라 **표 `p_recon_lines`** 다 — 판정 결과 자체가 증거다
//   (AL-15 가 뷰 `p_backorder` 를 보는 것과 같은 성격).
//
//   ★ 23단계 확정 — 임계값은 `PRICE_JUMP_RATIO`(20%)이고, **두 곳**을 본다:
//     ① 월 대사의 「단가 차이」 줄 (22단계부터 있던 것)
//     ② **입고 단가 vs 품목 기준 단가** — `p_doc_lines.unit_price`(입고 확정 때 서버가 읽어
//        넣은 값) 과 `p_items.price_in`(오늘의 기준 단가)이 20% 넘게 벌어진 입고 줄.
//        대사는 한 달에 한 번이라 그때까지 모르고 지나간다. ②는 **입고 다음 날** 드러난다.
//        값이 벌어지는 경로는 둘이다: 거래처가 올려 받았거나, 기준 단가를 누가 고쳤거나.
//        어느 쪽이든 사람이 확인할 일이다. 최근 `PRICE_SCAN_DAYS` 일 안의 입고만 본다.
//
//   ★ 근거에 **전표 번호·품목 코드를 싣지 않는다** (23단계 금지 패턴) — 줄 id 와 품목 id 로
//     되짚는다. 거래처 이름은 ①에서만 남긴다(어느 거래처와의 대사인지가 문장의 뜻이다).
//
//   지점 격리는 이 배치에 없다 — CLI 는 운영자(root)가 돌리고 경보는 구매실이 받는다
//   (AL-15 와 같은 판단).
// ─────────────────────────────────────────────────────────────
if ($want('AL-16')) {
    // ① 월 대사에서 갈린 「단가 차이」 줄
    $reconRows = $db->run(
        "SELECT r.id, l.item_id,
                CAST(r.unit_price AS text) AS their_price,
                CAST(l.unit_price AS text) AS our_price,
                s.month, v.name AS vendor_name
           FROM p_recon_lines      r
           JOIN p_recon_statements s ON s.id = r.statement_id
           JOIN p_vendors          v ON v.id = s.vendor_id
           JOIN p_doc_lines        l ON l.id = r.matched_line_id
          WHERE r.diff_type = 'price'
            AND r.unit_price IS NOT NULL
            AND l.unit_price IS NOT NULL
            AND l.unit_price > 0
            AND abs(r.unit_price - l.unit_price) / l.unit_price > CAST(:ratio AS numeric)
          ORDER BY r.id DESC
          LIMIT :lim",
        [':ratio' => PRICE_JUMP_RATIO, ':lim' => PURCHASE_ALERT_LIMIT]
    )->fetchAll();
    foreach ($reconRows as $r) {
        $raise('AL-16', 'purchase_recon_line#' . (string) $r['id'], [
            'rule_text' => sprintf(
                '월 대사에서 거래처 「%s」 단가가 우리 단가보다 %d%% 넘게 벌어졌다',
                (string) ($r['vendor_name'] ?? '?'),
                (int) round(PRICE_JUMP_RATIO * 100)
            ),
            'source'      => 'recon',
            'month'       => (string) $r['month'],
            'item_id'     => $r['item_id'] === null ? null : (int) $r['item_id'],
            'our_price'   => (string) $r['our_price'],
            'their_price' => (string) $r['their_price'],
            'threshold'   => PRICE_JUMP_RATIO,
        ]);
    }

    // ② 입고 단가 vs 품목 기준 단가 (23단계 추가)
    $grRows = $db->run(
        "SELECT l.id, l.item_id, d.branch_id, d.doc_date,
                CAST(l.unit_price AS text) AS in_price,
                CAST(i.price_in  AS text)  AS master_price,
                CAST(round(abs(l.unit_price - i.price_in) / i.price_in * 100, 1) AS text) AS gap_pct
           FROM p_doc_lines l
           JOIN p_docs  d ON d.id = l.doc_id
           JOIN p_items i ON i.id = l.item_id
          WHERE d.doc_type = 'gr'
            AND d.status <> 'void'
            AND l.status <> 'void'
            AND l.unit_price IS NOT NULL
            AND i.price_in IS NOT NULL
            AND i.price_in > 0
            AND abs(l.unit_price - i.price_in) / i.price_in > CAST(:ratio AS numeric)
            AND d.created_at > now() - (CAST(:days AS int) * interval '1 day')
          ORDER BY l.id DESC
          LIMIT :lim",
        [
            ':ratio' => PRICE_JUMP_RATIO,
            ':days'  => PRICE_SCAN_DAYS,
            ':lim'   => PURCHASE_ALERT_LIMIT,
        ]
    )->fetchAll();
    foreach ($grRows as $r) {
        $raise('AL-16', 'purchase_gr_line#' . (string) $r['id'], [
            'rule_text' => sprintf(
                '입고 단가가 품목 기준 단가보다 %d%% 넘게 벌어졌다 (실제 %s%%)',
                (int) round(PRICE_JUMP_RATIO * 100),
                (string) $r['gap_pct']
            ),
            'source'       => 'receipt',
            'item_id'      => (int) $r['item_id'],
            'branch_id'    => $r['branch_id'] === null ? null : (int) $r['branch_id'],
            'doc_date'     => (string) $r['doc_date'],
            'in_price'     => (string) $r['in_price'],
            'master_price' => (string) $r['master_price'],
            'threshold'    => PRICE_JUMP_RATIO,
        ]);
    }
    cli_say(sprintf(
        'AL-16 대상 %d건 (대사 %d · 입고 %d)',
        count($reconRows) + count($grRows),
        count($reconRows),
        count($grRows)
    ));
}

// ─────────────────────────────────────────────────────────────
// AL-17 — 대량 취소 (23단계, PRD F-36 · S-11-A, ARCH 5.18 「감사·경보」)
//
//   **한 사람이 1시간에 전표를 5건 이상 취소**하면 올린다. 구매·재고에는 삭제가 없고 취소만
//   있다(원장은 반대 부호 행, 전표는 `status='void'`). 그래서 "지운다" 에 해당하는 행동은
//   전부 취소이고, 한 사람이 짧은 시간에 여러 건을 취소하는 것은 ① 잘못 넣은 것을 치우는
//   정상 작업이거나 ② 흔적을 지우려는 시도다. 막지 않고 **드러낸다** — AL-14 와 같은 태도다.
//
//   근거는 감사 로그의 `allow` 행이다. 거부된 취소는 이미 AL-1·AL-2 가 보는 신호이고,
//   여기서 세고 싶은 것은 **성공한 취소**의 묶음이다.
//
//   임계값은 `VOID_BURST_COUNT`(5건) / `VOID_BURST_HOURS`(1시간), 중복 억제는 사람마다
//   하루 한 번. 근거에 **계정명을 싣지 않는다** — 행위자 id 만 남기고 이름은 감사 로그에서 본다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-17')) {
    $rows = $db->run(
        "SELECT actor_id, count(*) AS n,
                array_to_string(array_agg(DISTINCT action ORDER BY action), ',') AS actions,
                min(happened_at) AS first_at, max(happened_at) AS last_at
           FROM audit_logs
          WHERE result = 'allow'
            AND actor_id IS NOT NULL
            AND action IN ('purchase.request.void', 'purchase.order.void',
                           'purchase.receipt.void', 'purchase.move.void',
                           'purchase.use.void',     'purchase.count.void')
            AND happened_at > now() - (CAST(:hours AS int) * interval '1 hour')
          GROUP BY actor_id
         HAVING count(*) >= CAST(:n AS int)",
        [':hours' => VOID_BURST_HOURS, ':n' => VOID_BURST_COUNT]
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-17', 'user#' . (string) $r['actor_id'], [
            'rule_text' => sprintf(
                '한 사람이 %d시간 안에 구매·재고 전표를 %d건 이상 취소했다 (대량 취소)',
                VOID_BURST_HOURS,
                VOID_BURST_COUNT
            ),
            'actor_id' => (int) $r['actor_id'],
            'count'    => (int) $r['n'],
            'actions'  => (string) $r['actions'],
            'window'   => VOID_BURST_HOURS . ' hour',
            'first_at' => (string) $r['first_at'],
            'last_at'  => (string) $r['last_at'],
        ]);
    }
    cli_say(sprintf('AL-17 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-7 — 감사 로그가 끊겼다 (PRD 7.11 "업무시간에 30분간 0건")
//   로그가 끊긴 것 자체가 침해 신호다. 다만 **이 서버 안에서 하는 판정은 약한 판정**이다 —
//   서버가 통째로 털리면 이 배치도 돌지 않는다. 진짜 감시는 원격 로그 서버 쪽에서 해야 한다
//   (ARCH 11.4 "밖에서 감시"). 여기서는 "앱은 도는데 기록만 끊긴" 경우를 잡는다.
//   업무시간은 서버 로컬 시각 09:00~18:00. 2026-09-11 부터 VM·PostgreSQL·PHP 시간대를 Asia/Seoul(KST) 로
//   통일했으므로 이 판정이 실제 한국 업무시간과 맞는다 (README 「서버 시간대」).
// ─────────────────────────────────────────────────────────────
if ($want('AL-7')) {
    $hour = (int) date('G');
    if ($hour >= 9 && $hour < 18) {
        $n = (int) $db->run(
            "SELECT count(*) AS n FROM audit_logs WHERE happened_at > now() - interval '30 minutes'"
        )->fetch()['n'];
        if ($n === 0) {
            $raise('AL-7', 'audit_logs', [
                'rule_text' => '업무시간(09~18시)에 감사 로그 30분간 0건',
                'window'    => '30 minutes',
                'count'     => 0,
            ]);
        }
        cli_say(sprintf('AL-7 최근 30분 기록 %d건 (업무시간)', $n));
    } else {
        cli_say(sprintf('AL-7 건너뜀 (업무시간 아님, 현재 %d시)', $hour));
    }
}

// ─────────────────────────────────────────────────────────────
// AL-10 — 계정 공유·탈취 의심 (PRD 7.11)
//   "같은 계정에 서로 다른 detail.login_ref 가 동시에 3개 이상"
//   login_ref 는 로그인 1회당 고정되는 12자다 (ARCH 7.5). 세 개가 한 시간 안에 살아 있다면
//   한 사람이 세 번 로그인했거나(기기 3대) 계정을 나눠 쓰는 것이다 — S-07 "계정 공유 금지".
// ─────────────────────────────────────────────────────────────
if ($want('AL-10')) {
    // `detail ? 'login_ref'` (jsonb 키 존재 연산자)는 쓰지 않는다 — PDO 가 `?` 를 위치
    // 바인딩 자리로 읽어 구문 오류가 난다(2026-09-11 실측). `->> IS NOT NULL` 로 같은 뜻을 쓴다.
    $rows = $db->run(
        "SELECT actor_name, count(DISTINCT detail->>'login_ref') AS refs,
                count(DISTINCT host(ip)) AS ips
           FROM audit_logs
          WHERE actor_name IS NOT NULL
            AND detail->>'login_ref' IS NOT NULL
            AND happened_at > now() - interval '1 hour'
          GROUP BY actor_name
         HAVING count(DISTINCT detail->>'login_ref') >= 3"
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-10', (string) $r['actor_name'], [
            'rule_text'  => '같은 계정에 서로 다른 login_ref 가 1시간 내 3개 이상',
            'login_refs' => (int) $r['refs'],
            'ips'        => (int) $r['ips'],
            'window'     => '1 hour',
        ]);
    }
    cli_say(sprintf('AL-10 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-12 — SSRF 탐색 (PRD 7.11, 7단계 추가)
//   "한 사용자의 file.fetch 거부(scheme·port·host_not_allowed·private_ip·redirect)가 10분 내 5회 이상"
//   내부망 주소·포트를 서버에게 대신 찔러 보는 시도다. 다른 거부 사유(too_large·rate_limited 등)는
//   탐색이 아니라 정상 사용의 실패일 수 있어 세지 않는다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-12')) {
    $rows = $db->run(
        "SELECT actor_name, count(*) AS n, array_agg(DISTINCT reason) AS reasons,
                max(happened_at) AS last_at
           FROM audit_logs
          WHERE action = 'file.fetch' AND result = 'deny'
            AND actor_name IS NOT NULL
            AND reason IN ('scheme','port','host_not_allowed','private_ip','redirect')
            AND happened_at > now() - interval '10 minutes'
          GROUP BY actor_name
         HAVING count(*) >= 5"
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-12', (string) $r['actor_name'], [
            'rule_text' => 'SSRF 탐색 의심 — file.fetch 거부 10분 내 5회 이상',
            'count'     => (int) $r['n'],
            'reasons'   => (string) $r['reasons'],
            'window'    => '10 minutes',
            'last_at'   => (string) $r['last_at'],
        ]);
    }
    cli_say(sprintf('AL-12 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-13 — 권한 매트릭스 변경 (PRD 7.11 · F-25, 17-2 추가, ARCH 5.15 · 11.4)
//   "`permission.change allow` 가 **1건이라도** 있으면 즉시" — 건수 임계값이 없다.
//   다른 규칙은 "정상 사용과 구분되는 양" 을 보지만, 이 규칙은 **행동 자체**가 알릴 일이다:
//   권한 표 한 칸이 바뀌면 그 순간부터 누가 무엇을 볼 수 있는지가 달라진다(보안 정책 변경).
//   정상 작업이어도 운영 담당이 알아야 하고, Admin 세션을 훔친 사람이 한 짓이라면 더욱 그렇다.
//   창은 다른 audit_logs 규칙과 맞춰 5분(배치 주기)이다 — 중복 억제는 $raise 가 한다.
//   근거에는 **바뀐 칸 목록과 사유**를 담는다(감사 로그 detail 의 before/after·reason_text).
//   2단계 인증 코드·비밀번호는 감사 로그에 애초에 없으므로 여기에도 없다 (PRD S-07).
// ─────────────────────────────────────────────────────────────
if ($want('AL-13')) {
    $rows = $db->run(
        "SELECT actor_name, count(*) AS n,
                array_to_string(array_agg(target_name ORDER BY id), ',') AS cells,
                max(detail->>'reason_text') AS reason_text,
                max(happened_at) AS last_at
           FROM audit_logs
          WHERE action = 'permission.change' AND result = 'allow'
            AND happened_at > now() - interval '5 minutes'
          GROUP BY actor_name"
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-13', (string) ($r['actor_name'] ?? '?'), [
            'rule_text'   => '권한 매트릭스 변경 — 건수와 무관하게 즉시 알린다',
            'count'       => (int) $r['n'],
            // 바뀐 칸 이름 목록 (`역할|등급`). 값 자체는 before/after 로 감사 로그에 있다.
            'cells'       => (string) $r['cells'],
            'reason_text' => $r['reason_text'] === null ? '' : (string) $r['reason_text'],
            'window'      => '5 minutes',
            'last_at'     => (string) $r['last_at'],
        ]);
    }
    cli_say(sprintf('AL-13 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-3 (확장) · AL-9 — 서버 층 (16-2, SECURITY 8.4-5, ARCH 11.4, PRD T-37)
//   audit_logs 가 아니라 `/var/log/auth.log` 를 읽는다. 2026-09-11 침투는 전부 앱 **밖**에서
//   일어났고 앱 감사 로그에는 한 줄도 남지 않았다 — SSH 로 들어와 sudo 로 데이터를 지웠다.
//   그래서 두 규칙은 그 로그를 직접 본다.
//     AL-3 : 새 출처의 SSH 성공 — `Accepted <방식> for <계정> from <IP>` 중
//            상태 파일의 `ssh_seen[계정@IP]` 첫 관측이 30일 안에 없는 것
//     AL-9 : 위험한 sudo 실행 — `sudo: <계정> : … COMMAND=<명령>` 의 명령이
//            데이터·로그를 지우거나 통째로 읽는 종류
//   auth.log 를 읽지 못하면(root 가 아니면) 이 두 규칙만 건너뛴다.
// ─────────────────────────────────────────────────────────────
$sshSeenChanged = false;
$authPosChanged = false;

if ($want('AL-3') || $want('AL-9')) {
    $read = al_read_auth_log($authLogArg, $authPos);

    if (!$read['ok']) {
        cli_say(sprintf(
            'AL-3·AL-9 [건너뜀] %s 를 읽지 못했다 (%s) — root 로 실행해야 한다',
            $read['path'],
            $read['reason'] === 'permission' ? '권한 없음' : '읽기 실패'
        ));
    } else {
        $sshNew  = 0;
        $sudoHit = 0;
        $seenCut = $now - SSH_SEEN_DAYS * 24 * 3600;

        foreach ($read['lines'] as $line) {
            // ── AL-3: SSH 로그인 성공 ──
            if ($want('AL-3')
                && preg_match(
                    '/sshd\[\d+\]:\s+Accepted\s+(publickey|password|keyboard-interactive\/pam)\s+for\s+(\S+)\s+from\s+(\S+)/',
                    $line,
                    $m
                ) === 1
            ) {
                $method  = $m[1];
                $account = $m[2];
                $srcIp   = $m[3];
                $seenKey = $account . '@' . $srcIp;

                $firstAt = isset($sshSeen[$seenKey]) ? strtotime($sshSeen[$seenKey]) : false;
                if ($firstAt === false || $firstAt < $seenCut) {
                    // 30일 안에 본 적 없는 출처다 (계정 탈취·새 경로 — PRD 7.11 AL-3)
                    $sshNew++;
                    $raise('AL-3', $seenKey, [
                        'rule_text' => 'SSH 로그인 성공 — 최근 30일 안에 본 적 없는 계정@출처',
                        'user'      => $account,
                        'ip'        => $srcIp,
                        'method'    => $method,
                        'when'      => al_line_time($line),
                        'window'    => SSH_SEEN_DAYS . ' days',
                    ]);
                    /*
                     * 첫 관측 시각은 **처음 본 때**로 둔다(볼 때마다 갱신하지 않는다) —
                     * 30일이 지나면 저장 단계에서 버려지고, 그 뒤 다시 들어오면 새 출처로
                     * 한 번 더 알린다. 기억은 메모리에 바로 넣는다: 같은 실행 안에서 같은
                     * `계정@IP` 가 여러 줄 나와도 알림은 한 번이다.
                     * 파일에 남기는 것은 **운영 로그를 읽었고 dry-run 이 아닐 때만**이다 —
                     * 시험(`--auth-log`)이 만든 가짜 출처가 운영 상태에 섞이면 그 뒤 진짜
                     * 접속이 "이미 아는 출처" 로 조용해진다.
                     */
                    $sshSeen[$seenKey] = date('c', $now);
                    if (!$dryRun && $authLogArg === null) {
                        $sshSeenChanged = true;
                    }
                }
                continue;
            }

            // ── AL-9: 위험한 sudo 실행 ──
            if ($want('AL-9')
                && preg_match('/sudo:\s+(\S+) : .* COMMAND=(.*)$/', $line, $m) === 1
            ) {
                $account = $m[1];
                $command = $m[2];
                if (preg_match(
                        '/(^|\/|\s)(psql|pg_dump|pg_restore|dropdb|truncate|chattr)\b|(^|\s)rm\s|find\b.*-delete/',
                        $command
                    ) !== 1
                ) {
                    continue;   // 일상적인 sudo(예: systemctl status)는 알리지 않는다
                }
                $sudoHit++;
                $raise('AL-9', $account, [
                    'rule_text' => 'sudo 로 데이터·로그를 지우거나 통째로 읽는 명령 실행',
                    'user'      => $account,
                    // 비밀값 조각을 지우고 앞 200자만 (PRD 7.7)
                    'command'   => al_scrub_command($command),
                    'when'      => al_line_time($line),
                ]);
            }
        }

        if ($read['advance']
            && ($read['inode'] !== $authPos['inode'] || $read['offset'] !== $authPos['offset'])
        ) {
            $authPos['inode']  = $read['inode'];
            $authPos['offset'] = $read['offset'];
            $authPosChanged    = true;
        }

        cli_say(sprintf(
            'AL-3·AL-9 %s 새 줄 %d개 — 새 출처 SSH %d건, 위험한 sudo %d건%s',
            $read['path'],
            count($read['lines']),
            $sshNew,
            $sudoHit,
            $read['advance'] ? '' : ' (시험 모드: 오프셋을 저장하지 않는다)'
        ));
    }
}

// ─── 상태 저장 (오래된 항목은 버린다 — 파일이 무한히 자라지 않게) ───
if ($stateChanged || $sshSeenChanged || $authPosChanged) {
    foreach ($state as $k => $v) {
        $t = strtotime($v);
        if ($t === false || ($now - $t) > 7 * 24 * 3600) {
            unset($state[$k]);
        }
    }
    // 30일이 지난 `계정@IP` 는 버린다 — 그 뒤 다시 들어오면 "처음 보는 출처" 로 다시 알린다
    foreach ($sshSeen as $k => $v) {
        $t = strtotime($v);
        if ($t === false || ($now - $t) > SSH_SEEN_DAYS * 24 * 3600) {
            unset($sshSeen[$k]);
        }
    }
    $payload = $state + [
        'ssh_seen'        => $sshSeen,
        'auth_log_inode'  => $authPos['inode'],
        'auth_log_offset' => $authPos['offset'],
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('경보 상태를 직렬화할 수 없습니다');
    }
    cli_write_private($stateFile, $json . "\n");
}

cli_say(sprintf('경보 판정 끝 — 이번에 발동 %d건%s', $fired, $dryRun ? ' (dry-run)' : ''));
exit(TBOX_CLI_OK);
