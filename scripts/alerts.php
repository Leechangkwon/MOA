<?php
declare(strict_types=1);

/**
 * scripts/alerts.php — 감사 로그를 5분마다 훑어 경보 규칙을 판정한다 (9단계)
 *   PRD 7.11 S-11-A (AL-1 · AL-2 · AL-5 · AL-7 · AL-10 · AL-12 · AL-13 · 서버 층 AL-3 · AL-9)
 *   ARCHITECTURE 11.1 (5분마다 cron) · 11.4 (경보 구성) · 7.4 `alert.raised|allow` · 7.5 `rule`·`evidence`
 *
 * 사용법
 *   sudo php scripts/alerts.php [--dry-run] [--rule AL-1] [--auth-log <경로>]
 *     --dry-run   : 판정만 하고 경보를 남기지 않는다 (규칙을 고친 뒤 확인용)
 *     --rule      : 한 규칙만 돌린다
 *     --auth-log  : /var/log/auth.log 대신 **그 파일**을 읽는다 (16-2 시험용).
 *                   이때는 읽기 오프셋을 상태 파일에 **쓰지 않는다** — 시험이 운영의
 *                   읽기 위치를 밀어 놓으면 그 사이의 진짜 줄을 영영 건너뛰기 때문이다.
 *
 * 왜 SQL 인가: 경보는 "이미 남은 기록" 을 읽어 판정한다. 앱 코드에 경보 조건을 흩어 두면
 * 새 핸들러를 만들 때마다 빠뜨리고, 무엇보다 **기록되지 않은 일은 경보도 못 한다**는 사실이
 * 흐려진다. 판정은 audit_logs 한 곳만 본다 (ARCH 11.4).
 *
 * 중복 억제 (PRD S-11-A "즉시 알림" 이 곧 "5분마다 같은 알림" 이 되면 아무도 안 본다)
 *   `/var/lib/tbox-v2/alerts-state.json` 에 `규칙|대상` 별 마지막 발동 시각을 적고,
 *   30분 안에는 같은 대상으로 다시 발동하지 않는다.
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

if (is_file(STATE_FILE)) {
    $raw = file_get_contents(STATE_FILE);
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
    $key  = $rule . '|' . $subject;
    $last = isset($state[$key]) ? strtotime($state[$key]) : false;
    if ($last !== false && ($now - $last) < DEDUP_MINUTES * 60) {
        cli_say(sprintf('  %s %s — %d분 안에 이미 발동해 건너뛴다', $rule, $subject, DEDUP_MINUTES));
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
    cli_write_private(STATE_FILE, $json . "\n");
}

cli_say(sprintf('경보 판정 끝 — 이번에 발동 %d건%s', $fired, $dryRun ? ' (dry-run)' : ''));
exit(TBOX_CLI_OK);
