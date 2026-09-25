<?php
declare(strict_types=1);

/**
 * scripts/alert_push.php — 경보를 관리자 휴대폰으로 보내는 배치 (PRD F-70 · 지시서 docs/f70-push-alerts-directive.md C장,
 *   구현 기록 docs/f70-push-alerts-implementation.md, ARCH 5.32 · 11.4, SECURITY R-99)
 *
 * 사용법 (cron 매분, OS 계정 tbox-push — deploy/cron.d/tbox-v2)
 *   php scripts/alert_push.php [--dry-run] [--once] [--conf <경로>]
 *     --dry-run  보낼 대상 수·호스트·내용 크기만 찍는다. **아무것도 쓰지 않는다** — alerts.log 읽기 위치(오프셋)·상태 파일·
 *                받기 표·대기표·감사 0, Fetch 를 부르지 않는다(대기열 2c 와 같은 규칙 · 금지 패턴). 잠금 파일도 만들지 않는다.
 *     --once     한 번 돌고 끝(시험·손 실행 표식). 기본도 한 번이다 — cron 이 매분 부른다(구현 기록 8).
 *     --conf     설정 파일(기본 /etc/tbox-v2/push.conf — 시험이 임시 설정을 줄 때만). 셸 인자이지 요청 값이 아니다.
 *
 * 한 번 도는 순서 (C장 1~8)
 *   1 읽기    alerts.log 를 저장한 위치부터(scripts/lib/log_tail.php — alerts.php 와 한 벌, 줄 단위). **처음 켤 때는 위치 = 파일 끝**
 *             (옛 줄은 보내지 않는다). 대기표(alert_push_outbox)의 시험 요청도 읽는다.
 *   2 가르기  origin claude-review·claude-session → 하루 요약 칸(개수만) · push.conf DIGEST_RULES 의 규칙 → 하루 요약 칸
 *             · 그 밖(human · system · origin 없는 줄 = 옛 형식 → human, 좁은 쪽 = 알린다) → 즉시
 *   3 받는 사람 살아 있는 받기마다 그 사용자가 **지금** `role='Admin' AND is_active` 인지 SQL 로 다시 본다 — 아니면
 *             보내지 않고 해지(`not_admin`) + 감사 `alert.push_revoke`
 *   4 만들기  내용 JSON ≤ 1KB `{v:1, kind, rule, name, origin, at, n}` — name 은 아래 코드 표의 고정 짧은 이름이다
 *             (evidence 의 글자·계정명·IP·파일명·명령을 옮기지 않는다 — L-1). → RFC 8291 암호화 → VAPID JWT(aud = 스킴+호스트,
 *             exp 12시간, sub = 사이트 주소)
 *   5 보내기  Fetch::pushSend — S-05 ①~⑧ 그대로. TTL(즉시 3600 · 요약 86400) · Urgency high(즉시) · Content-Encoding aes128gcm
 *   6 결과    2xx → last_ok_at · 404/410 → 해지(gone) · 413 → 기록만 · 429/5xx·연결 실패 → fail_count+1, 다음 실행에 다시
 *             (같은 알림 최대 3회 · 푸시 서버가 Retry-After 를 주면 그만큼 뒤 — 상한 1시간, Fetch::PUSH_MAX_RETRY_AFTER) · 그 밖 4xx·3xx → fail_count+1(다시 보내지 않음). 연속 실패가 시작된 시각 fail_since —
 *             살아 있는 받기 전부가 24시간 넘게 실패면 alerts.php(root)가 AL-22(system)를 올린다(구현 기록 6)
 *   7 줄이기  받기 하나에 즉시 알림 **시간당 10건**(L-7) — 넘으면 그 시간의 나머지는 개수만 세어 다음 정시에 「외 n건」 한 건
 *             · 하루 요약은 18시(L-2) 첫 실행에 한 번, 0건이면 보내지 않는다
 *   8 기록    감사 `alert.push_send allow|error` (행위자 없음 = 시스템) detail `{host, status, rule, kind}` 만 — 주소·키·내용 원문 없음
 *             DB 행은 역할 tbox_push 의 audit_logs INSERT, 파일 사본은 push.conf 의 AUDIT_LOG_FILE(/var/log/tbox-push/audit.log)
 *
 * ★ 실패해도 exit 0 이다(cron 메일 없음 — 대신 감사·AL-22). 1 은 시작 실패(설정·DB)뿐, 3 은 인자·설정 모양 오류.
 * ★ 상태 파일(STATE_DIR/alert-push-state.json, 0600)에는 받기 **번호**·개수·시각만 있다 — 주소·키는 DB 에만.
 * ★ 표준출력에도 주소·키·내용 원문을 찍지 않는다(개수·호스트 이름·바이트 수만).
 * ★ 트랜잭션을 연 채 밖으로 나가지 않는다 — 문장마다 자동 커밋(역할 idle_in_transaction 60초에 걸릴 길이 없다).
 *   예외 하나: 받기 해지(push_revoke)는 해지 + 감사 한 줄을 한 트랜잭션으로 — 감사가 실패하면 되돌린다(짧다, 문장 둘).
 */

require_once __DIR__ . '/lib/push_bootstrap.php';

/** 규칙의 고정 짧은 이름 (C장 4 · 알림 제목 「[사람] AL-1 권한 우회 시도」). 없는 규칙은 이름 없이 번호만 */
const PUSH_RULE_NAMES = [
    'AL-1'  => '권한 우회 시도',
    'AL-2'  => '로그인 무차별 대입',
    'AL-3'  => '새 출처 SSH 로그인',
    'AL-5'  => '접근 등급 하향',
    'AL-6'  => '정합성 불일치',
    'AL-7'  => '감사 로그 끊김',
    'AL-8'  => '백업 실패',
    'AL-9'  => '위험한 sudo 명령',
    'AL-10' => '계정 공유 의심',
    'AL-12' => 'SSRF 탐색 의심',
    'AL-13' => '권한 매트릭스 변경',
    'AL-18' => '가져오기 허용 사이트 변경',
    'AL-19' => '악성코드 발견',
    'AL-20' => '개인 토큰 발급',
    'AL-21' => '실행 파일이 든 압축본',
    'AL-22' => '휴대폰 경보 전달 실패',
];
/**
 * 같은 규칙 안의 갈래 이름 — alerts.php 가 붙인 대상(evidence.subject)이 이 표에 있을 때만(코드 표의 고정 이름 — 근거 글자를 옮기지 않는다).
 * AL-22 recovered = 막혔던 휴대폰 경보가 다시 닿는다는 한 줄.
 */
const PUSH_VARIANT_NAMES = [
    'AL-22' => ['recovered' => '휴대폰 경보 전달 복구'],
];

/** 받기 하나의 즉시 알림 시간당 상한 (L-7) */
const PUSH_HOURLY_CAP = 10;
/** 하루 요약 시각 (L-2 — 18시, 0건이면 보내지 않는다) */
const PUSH_DIGEST_HOUR = 18;
/** 같은 알림 최대 시도 (C장 6) */
const PUSH_MAX_TRIES = 3;
/** 다시 보내기는 다음 실행(cron 1분)부터 */
const PUSH_RETRY_SECONDS = 50;
/** TTL — 즉시 1시간 · 요약 하루 (C장 5) */
const PUSH_TTL_NOW    = 3600;
const PUSH_TTL_DIGEST = 86400;
/** alerts.log 를 한 번에 읽는 양 — 남은 것은 다음 실행에 */
const PUSH_READ_MAX_BYTES = 1048576;
/** 대기 상한 — 넘치면 오래된 것부터 버린다(푸시 회사가 오래 멈춘 경우 상태 파일이 자라지 않게) */
const PUSH_MAX_PENDING = 300;
/** 한 번에 읽는 시험 요청 수 */
const PUSH_OUTBOX_BATCH = 20;
/** 상태 파일 이름 · 잠금 파일 이름 (STATE_DIR 아래) */
const PUSH_STATE_NAME = 'alert-push-state.json';
const PUSH_LOCK_NAME  = 'alert-push.lock';

// ─── 인자 ────────────────────────────────────────────────────────
$argv = $_SERVER['argv'];
$known = ['--dry-run', '--once', '--conf'];
for ($i = 1, $n = count($argv); $i < $n; $i++) {
    $a = $argv[$i];
    if ($a === '--conf') {
        $i++;
        continue;
    }
    if (strpos($a, '--conf=') === 0 || in_array($a, $known, true)) {
        continue;
    }
    push_warn('[사용법 오류] 알 수 없는 인자 — --dry-run · --once · --conf <경로> 만');
    exit(PUSH_CLI_BAD_USAGE);
}
$dryRun   = push_flag($argv, 'dry-run');
$confPath = push_option($argv, 'conf') ?? PUSH_CONF_FILE;

$conf = push_load_conf($confPath);
push_boot_db($conf);
$db  = Db::instance();
$now = time();

$stateFile = $conf['STATE_DIR'] . '/' . PUSH_STATE_NAME;
$digestRules = $conf['DIGEST_RULES'] === '' ? [] : explode(',', $conf['DIGEST_RULES']);

push_say('휴대폰 경보 배치 시작 (' . date('c', $now) . ')' . ($dryRun ? ' [dry-run — 아무것도 쓰지 않는다]' : ''));

// ─── 겹쳐 돌지 않게 (dry-run 은 파일을 만들지 않으므로 잠그지 않는다) ─────────
$lock = null;
if (!$dryRun) {
    if (!is_dir($conf['STATE_DIR'])) {
        push_warn('[설정 오류] 상태 폴더(STATE_DIR)가 없다');
        exit(PUSH_CLI_BAD_USAGE);
    }
    $lock = fopen($conf['STATE_DIR'] . '/' . PUSH_LOCK_NAME, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        push_say('앞 실행이 아직 도는 중 — 이번은 건너뛴다');
        exit(PUSH_CLI_OK);
    }
}

// ─── 상태 ────────────────────────────────────────────────────────
/**
 * 상태 파일을 읽는다. 파일이 **있는데** 비었거나 JSON 이 아니면 `corrupt` = true — 부르는 쪽이 한 줄 남긴다(조용히 「처음 켬」으로 가지 않는다).
 * 방향은 처음 켬과 같다: 읽기 위치를 잃었으니 alerts.log 의 지금 끝부터(그 사이 줄을 몰아 보내지 않는다).
 *
 * @return array{log:?array{inode:int,offset:int}, pending:list<array<string,mixed>>, hour:array<string,array{hour:string,sent:int,overflow:int}>, digest:array{claude:int,other:int,last_date:?string}, corrupt:bool}
 */
function push_state_load(string $path): array
{
    $state = ['log' => null, 'pending' => [], 'hour' => [], 'digest' => ['claude' => 0, 'other' => 0, 'last_date' => null], 'corrupt' => false];
    if (!is_file($path)) {
        return $state;
    }
    $raw = file_get_contents($path, false, null, 0, 4 * 1048576);
    $d = is_string($raw) && $raw !== '' ? json_decode($raw, true, 8) : null;
    if (!is_array($d)) {
        $state['corrupt'] = true;
        return $state;
    }
    if (is_array($d['log'] ?? null)) {
        $state['log'] = log_tail_pos($d['log']);
    }
    foreach ((array) ($d['pending'] ?? []) as $p) {
        $item = push_item_clean(is_array($p) ? $p : []);
        if ($item !== null) {
            $state['pending'][] = $item;
        }
    }
    foreach ((array) ($d['hour'] ?? []) as $sid => $h) {
        if (is_array($h) && preg_match('/^[0-9]{1,18}$/', (string) $sid) === 1
            && is_string($h['hour'] ?? null) && preg_match('/^[0-9]{10}$/', $h['hour']) === 1
        ) {
            $state['hour'][(string) $sid] = [
                'hour'     => $h['hour'],
                'sent'     => max(0, (int) ($h['sent'] ?? 0)),
                'overflow' => max(0, (int) ($h['overflow'] ?? 0)),
            ];
        }
    }
    $dg = is_array($d['digest'] ?? null) ? $d['digest'] : [];
    $state['digest'] = [
        'claude'    => max(0, (int) ($dg['claude'] ?? 0)),
        'other'     => max(0, (int) ($dg['other'] ?? 0)),
        'last_date' => is_string($dg['last_date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dg['last_date']) === 1
            ? $dg['last_date'] : null,
    ];
    return $state;
}

/** 대기 항목 하나의 모양을 좁힌다 — 틀리면 null (버린다) */
function push_item_clean(array $p): ?array
{
    $kind = $p['kind'] ?? null;
    if (!in_array($kind, ['alert', 'digest', 'test', 'more'], true)) {
        return null;
    }
    $sub = $p['sub'] ?? null;
    if (!is_int($sub) || $sub < 1) {
        return null;
    }
    $rule = $p['rule'] ?? null;
    if ($rule !== null && (!is_string($rule) || preg_match('/^AL-[0-9]{1,2}$/', $rule) !== 1)) {
        return null;
    }
    $origin = $p['origin'] ?? null;
    if ($origin !== null && !Origin::isValue((string) $origin)) {
        return null;
    }
    $variant = $p['variant'] ?? null;
    if ($variant !== null && ($rule === null || !isset(PUSH_VARIANT_NAMES[$rule][$variant]))) {
        return null;
    }
    $at = is_string($p['at'] ?? null) ? $p['at'] : '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/', $at) !== 1) {
        return null;
    }
    return [
        'sub'     => $sub,
        'kind'    => $kind,
        'rule'    => $rule,
        'origin'  => $origin,
        'variant' => $variant,
        'at'      => $at,
        'n'       => max(0, min(100000, (int) ($p['n'] ?? 1))),
        'tries'   => max(0, (int) ($p['tries'] ?? 0)),
        'next_at' => max(0, (int) ($p['next_at'] ?? 0)),
    ];
}

$state    = push_state_load($stateFile);
$firstRun = $state['log'] === null;
if ($state['corrupt']) {
    push_warn('[상태 파일 깨짐] 읽기 위치를 잃었다 — alerts.log 의 지금 끝부터 다시 읽는다(그 사이 경보는 휴대폰으로 보내지 않는다)');
    if (!$dryRun) {
        push_audit('error', 'alert.push_send', 'state_corrupt', ['target_type' => 'push_state', 'detail' => ['kind' => 'state']]);
    }
}
unset($state['corrupt']);

// ─── 1. 읽기 — alerts.log 새 줄 ───────────────────────────────────
$lines  = [];
$newPos = $state['log'];
if ($firstRun) {
    // 처음 켤 때 위치 = 파일 끝 (C장 1 — 옛 줄은 보내지 않는다). 파일이 아직 없으면 0 에서 시작한다
    $end = log_tail_end($conf['ALERTS_LOG']);
    $newPos = ['inode' => $end['inode'], 'offset' => $end['offset']];
    push_say(sprintf('처음 켬 — alerts.log 의 지금 끝(%d바이트)부터 읽는다. 옛 줄은 보내지 않는다', $end['offset']));
} else {
    $read = log_tail_read($conf['ALERTS_LOG'], $conf['ALERTS_LOG'] . '.1', $state['log'], PUSH_READ_MAX_BYTES, true);
    if (!$read['ok']) {
        push_say('alerts.log [건너뜀] 읽지 못했다 (' . ($read['reason'] === 'permission' ? '권한 없음' : '읽기 실패') . ')');
    } else {
        $lines  = $read['lines'];
        $newPos = ['inode' => $read['inode'], 'offset' => $read['offset']];
    }
}

// ─── 2. 가르기 ───────────────────────────────────────────────────
$immediate = [];
$toDigestClaude = 0;
$toDigestOther  = 0;
$badLines = 0;
foreach ($lines as $line) {
    $d = json_decode($line, true, 16);
    $rule = is_array($d) ? ($d['rule'] ?? null) : null;
    if (!is_string($rule) || preg_match('/^AL-[0-9]{1,2}$/', $rule) !== 1) {
        $badLines++;
        continue;
    }
    // origin 이 없는 줄(F-69 전 형식)·모르는 값 → human (좁은 쪽 = 알린다)
    $origin = is_string($d['origin'] ?? null) && Origin::isValue($d['origin']) ? $d['origin'] : Origin::HUMAN;
    $at = is_string($d['raised_at'] ?? null)
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/', $d['raised_at']) === 1
        ? $d['raised_at'] : date('c', $now);
    if ($origin === Origin::CLAUDE_REVIEW || $origin === Origin::CLAUDE_SESSION) {
        $toDigestClaude++;
        continue;
    }
    if (in_array($rule, $digestRules, true)) {
        $toDigestOther++;
        continue;
    }
    // 갈래 이름 — 대상이 코드 표에 있는 값일 때만(그 밖의 글자는 버린다)
    $subject = is_array($d['evidence'] ?? null) && is_string($d['evidence']['subject'] ?? null) ? $d['evidence']['subject'] : null;
    $variant = $subject !== null && isset(PUSH_VARIANT_NAMES[$rule][$subject]) ? $subject : null;
    $immediate[] = ['rule' => $rule, 'origin' => $origin, 'variant' => $variant, 'at' => $at];
}
$state['digest']['claude'] += $toDigestClaude;
$state['digest']['other']  += $toDigestOther;
push_say(sprintf(
    '새 줄 %d개 — 즉시 %d · 하루 요약 칸 +%d (Claude %d · 요약 규칙 %d)%s',
    count($lines),
    count($immediate),
    $toDigestClaude + $toDigestOther,
    $toDigestClaude,
    $toDigestOther,
    $badLines > 0 ? ' · 읽지 못한 줄 ' . $badLines : ''
));

// ─── 3. 받는 사람 — 지금 활성 Admin 인가 (SQL 로 다시) ──────────────
$rows = $db->run(
    "SELECT s.id, s.user_id, s.endpoint, s.host,
            encode(s.p256dh, 'hex') AS p256dh_hex, encode(s.auth, 'hex') AS auth_hex,
            (u.role = 'Admin' AND u.is_active) AS still_admin
       FROM alert_push_subscriptions s
       JOIN users u ON u.id = s.user_id
      WHERE s.revoked_at IS NULL
      ORDER BY s.id"
)->fetchAll();

/** @var array<int,array{id:int,user_id:int,endpoint:string,host:string,p256dh:string,auth:string}> $alive */
$alive = [];
$notAdmin = [];
foreach ($rows as $r) {
    $ok = $r['still_admin'] === true || $r['still_admin'] === 't' || $r['still_admin'] === 1 || $r['still_admin'] === '1';
    $sid = (int) $r['id'];
    if (!$ok) {
        $notAdmin[] = ['id' => $sid, 'host' => (string) $r['host']];
        continue;
    }
    $alive[$sid] = [
        'id'       => $sid,
        'user_id'  => (int) $r['user_id'],
        'endpoint' => (string) $r['endpoint'],
        'host'     => (string) $r['host'],
        'p256dh'   => (string) hex2bin((string) $r['p256dh_hex']),
        'auth'     => (string) hex2bin((string) $r['auth_hex']),
    ];
}
unset($rows);

/** 감사 한 줄 — 실패해도 배치는 계속한다(보내기는 민감 행동이 아니다 · 실패 문구에 값 없음) */
function push_audit(string $result, string $action, ?string $reason, array $ctx): void
{
    try {
        if ($result === 'allow') {
            Audit::allow($action, $ctx);
        } else {
            Audit::error($action, (string) $reason, $ctx);
        }
    } catch (Throwable $e) {
        error_log('[tbox-push] request_id=' . TBOX_REQUEST_ID . ' audit write failed: ' . get_class($e));
        push_warn('  ! 감사 한 줄을 남기지 못했다 (' . $action . ')');
    }
}

/**
 * 받기 해지 — DB 상태를 바꾸는 일이라 해지와 감사 한 줄을 **한 트랜잭션**으로 묶는다(Audit 은 같은 연결 Db::instance() 에 쓴다).
 * 감사(DB 행 또는 파일 사본)가 실패하면 되돌리고 false — 받기는 살아 있고 다음 실행이 다시 판정한다(기록 없는 해지를 남기지 않는다).
 * 보내기 감사(push_send)는 push_audit 처럼 실패를 삼킨다 — 상태를 바꾸지 않는 기록이라서다. 트랜잭션을 연 채 나가지 않는다.
 */
function push_revoke(Db $db, int $sid, string $host, string $reason): bool
{
    $db->begin();
    try {
        $done = $db->run(
            'UPDATE alert_push_subscriptions SET revoked_at = now(), revoked_reason = :reason
              WHERE id = :id AND revoked_at IS NULL',
            [':reason' => $reason, ':id' => $sid]
        )->rowCount();
        if ($done === 1) {
            Audit::allow('alert.push_revoke', [
                'target_type' => 'push_subscription',
                'target_id'   => $sid,
                'target_name' => $host,
                'detail'      => ['host' => $host, 'revoked_reason' => $reason],
            ]);
        }
        $db->commit();
        return $done === 1;
    } catch (Throwable $e) {
        $db->rollback();
        push_warn('  ! 받기 해지를 되돌렸다 — 감사를 남기지 못함(' . get_class($e) . ') · 다음 실행에 다시 판정한다');
        return false;
    }
}

if ($notAdmin !== []) {
    push_say(sprintf('받는 사람 다시 확인 — 활성 Admin 이 아닌 사람의 받기 %d개%s', count($notAdmin), $dryRun ? ' (dry-run: 해지하지 않는다)' : ' → 해지(not_admin)'));
    if (!$dryRun) {
        foreach ($notAdmin as $na) {
            push_revoke($db, $na['id'], $na['host'], 'not_admin');
        }
    }
}

// ─── 4~7. 대기표에 올리기 (시간당 상한 · 외 n건 · 하루 요약 · 시험) ────────
$atNow   = date('c', $now);
$curHour = date('YmdH', $now);
$today   = date('Y-m-d', $now);
$queued  = ['alert' => 0, 'digest' => 0, 'test' => 0, 'more' => 0];
$overflowed = 0;

/** 받기 하나의 이번 시간 칸 */
$hourOf = static function (int $sid) use (&$state, $curHour): array {
    $h = $state['hour'][(string) $sid] ?? null;
    if (!is_array($h) || $h['hour'] !== $curHour) {
        $h = ['hour' => $curHour, 'sent' => 0, 'overflow' => 0];
    }
    return $h;
};
$enqueue = static function (int $sid, string $kind, ?string $rule, ?string $origin, string $at, int $n, ?string $variant = null) use (&$state, &$queued): void {
    $state['pending'][] = [
        'sub' => $sid, 'kind' => $kind, 'rule' => $rule, 'origin' => $origin, 'variant' => $variant,
        'at' => $at, 'n' => $n, 'tries' => 0, 'next_at' => 0,
    ];
    $queued[$kind]++;
};

// 7 — 지난 시간에 넘친 개수는 새 시간의 첫 실행에 「외 n건」 한 건으로 (받기마다)
foreach ($state['hour'] as $sidKey => $h) {
    $sid = (int) $sidKey;
    if (!isset($alive[$sid])) {
        unset($state['hour'][$sidKey]);   // 해지된 받기의 칸은 버린다
        continue;
    }
    if ($h['hour'] !== $curHour) {
        if ($h['overflow'] > 0) {
            $enqueue($sid, 'more', null, null, $atNow, $h['overflow']);
        }
        unset($state['hour'][$sidKey]);
    }
}

// 즉시 — 받기마다 시간당 10건, 넘으면 개수만
foreach ($immediate as $ev) {
    foreach ($alive as $sid => $s) {
        $h = $hourOf($sid);
        if ($h['sent'] < PUSH_HOURLY_CAP) {
            $h['sent']++;
            $enqueue($sid, 'alert', $ev['rule'], $ev['origin'], $ev['at'], 1, $ev['variant']);
        } else {
            $h['overflow']++;
            $overflowed++;
        }
        $state['hour'][(string) $sid] = $h;
    }
}

// 하루 요약 — 18시 첫 실행에 한 번 (0건이면 보내지 않는다)
$digestSent = false;
if ((int) date('G', $now) >= PUSH_DIGEST_HOUR && $state['digest']['last_date'] !== $today) {
    $total = $state['digest']['claude'] + $state['digest']['other'];
    if ($total > 0) {
        // 전부 Claude 경보면 「[Claude] 오늘 경보 n건」, 요약 규칙(사람 AL-9 등)이 섞였으면 좁은 쪽(사람)으로 — 「[요약] …」
        $digestOrigin = $state['digest']['other'] === 0 ? Origin::CLAUDE_REVIEW : Origin::HUMAN;
        foreach ($alive as $sid => $s) {
            $enqueue($sid, 'digest', null, $digestOrigin, $atNow, $total);
        }
        $digestSent = true;
    }
    push_say(sprintf('하루 요약 차례(%d시) — %d건%s', PUSH_DIGEST_HOUR, $total, $total > 0 ? ' 보냄 예정' : ' — 0건이라 보내지 않는다'));
    $state['digest'] = ['claude' => 0, 'other' => 0, 'last_date' => $today];
}

// 시험 요청 — 그 사람의 살아 있는 받기 전부
$tests = $db->run(
    'SELECT id, user_id FROM alert_push_outbox WHERE sent_at IS NULL ORDER BY id LIMIT 20'   // PUSH_OUTBOX_BATCH
)->fetchAll();
foreach ($tests as $t) {
    $uid = (int) $t['user_id'];
    foreach ($alive as $sid => $s) {
        if ($s['user_id'] === $uid) {
            $enqueue($sid, 'test', null, null, $atNow, 1);
        }
    }
    if (!$dryRun) {
        // 대기표 행은 올렸으면 끝난 것으로 적는다 — 다시 보내기는 대기(pending)가 맡는다
        $db->run('UPDATE alert_push_outbox SET sent_at = now() WHERE id = :id AND sent_at IS NULL', [':id' => (int) $t['id']]);
    }
}

// 해지된 받기로 가던 대기는 버리고, 넘치면 오래된 것부터 버린다
$state['pending'] = array_values(array_filter($state['pending'], static function (array $p) use ($alive): bool {
    return isset($alive[$p['sub']]);
}));
$droppedOld = 0;
if (count($state['pending']) > PUSH_MAX_PENDING) {
    $droppedOld = count($state['pending']) - PUSH_MAX_PENDING;
    $state['pending'] = array_slice($state['pending'], $droppedOld);
}

$hostCount = [];
foreach ($alive as $s) {
    $hostCount[$s['host']] = ($hostCount[$s['host']] ?? 0) + 1;
}
ksort($hostCount);
$hostText = [];
foreach ($hostCount as $h => $c) {
    $hostText[] = $h . ' ' . $c;
}
push_say(sprintf(
    '대상 받기 %d개(%s) · 이번에 올림 즉시 %d · 외 n건 %d · 하루 요약 %d · 시험 %d(요청 %d) · 시간당 상한으로 미룸 %d%s',
    count($alive),
    $hostText === [] ? '없음' : implode(' · ', $hostText),
    $queued['alert'],
    $queued['more'],
    $queued['digest'],
    $queued['test'],
    count($tests),
    $overflowed,
    $droppedOld > 0 ? ' · 대기 상한으로 버림 ' . $droppedOld : ''
));

/** 내용 JSON (C장 4) — 코드 표의 이름만 */
function push_payload(array $p): string
{
    $rule = $p['rule'];
    $variant = $p['variant'] ?? null;
    $name = $rule !== null && $variant !== null && isset(PUSH_VARIANT_NAMES[$rule][$variant])
        ? PUSH_VARIANT_NAMES[$rule][$variant]
        : ($rule !== null && isset(PUSH_RULE_NAMES[$rule]) ? PUSH_RULE_NAMES[$rule] : null);
    return WebPush::payload([
        'kind'   => $p['kind'],
        'rule'   => $rule,
        'name'   => $name,
        'origin' => $p['origin'],
        'at'     => $p['at'],
        'n'      => $p['n'],
    ]);
}

// ─── dry-run 은 여기서 끝 — 크기만 찍고 아무것도 쓰지 않는다 ────────────
if ($dryRun) {
    $maxPlain = 0;
    $due = 0;
    foreach ($state['pending'] as $p) {
        if ($p['next_at'] <= $now) {
            $due++;
        }
        $maxPlain = max($maxPlain, strlen(push_payload($p)));
    }
    push_say(sprintf(
        '[dry-run] 보낼 알림 %d건(대기 %d) · 내용 최대 %d바이트 · 암호화 본문 최대 %d바이트 · Fetch 를 부르지 않는다 · 오프셋·상태·표를 저장하지 않는다',
        $due,
        count($state['pending']),
        $maxPlain,
        $maxPlain === 0 ? 0 : $maxPlain + 1 + 16 + 86
    ));
    push_say('휴대폰 경보 배치 끝 (dry-run)');
    exit(PUSH_CLI_OK);
}

// ─── 보내기 전에 위치·대기를 먼저 저장한다 (중간에 죽어도 줄을 잃지 않는다 — 다시 보낼 수는 있다) ───
$state['log'] = $newPos;
$saveState = static function () use (&$state, $stateFile): void {
    $json = json_encode([
        'v'       => 1,
        'log'     => $state['log'],
        'pending' => $state['pending'],
        'hour'    => (object) $state['hour'],
        'digest'  => $state['digest'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        throw new RuntimeException('상태를 직렬화할 수 없습니다');
    }
    push_write_state($stateFile, $json . "\n");
};
$saveState();

// ─── 5·6. 보내기 ─────────────────────────────────────────────────
$due = array_values(array_filter($state['pending'], static fn(array $p): bool => $p['next_at'] <= $now));
$sent = 0;
$failed = 0;
$result = [];   // 결과별 개수(출력용)

if ($due !== []) {
    // VAPID 키 — 비밀 키(tbox-push 0600)와 공개 키 파일(root 0644)이 짝인지 먼저 본다
    $signKey = null;
    $signPublic = null;
    try {
        $signKey = WebPush::loadPrivateKey($conf['VAPID_PRIVATE_KEY_FILE']);
        $signPublic = WebPush::rawPublicOf($signKey);
        $filePublic = WebPush::readPublicKeyFile($conf['VAPID_PUBLIC_KEY_FILE']);
        if ($filePublic === null || $filePublic !== WebPush::b64uEncode($signPublic)) {
            throw new WebPushError('bad_key');
        }
    } catch (WebPushError $e) {
        $signKey = null;
        push_say('VAPID 키를 읽지 못했거나 공개 키 파일과 짝이 아니다 — 이번 실행은 보내지 않는다(no_key)');
    }

    $jwtCache = [];
    $keep = [];
    foreach ($state['pending'] as $p) {
        if ($p['next_at'] > $now) {
            $keep[] = $p;
            continue;
        }
        if (!isset($alive[$p['sub']])) {
            continue;   // 이번 실행 안에서 해지된(gone) 받기로 가던 것 — 버린다
        }
        $s = $alive[$p['sub']];
        $host = $s['host'];
        $ctx = ['target_type' => 'push_subscription', 'target_id' => $s['id'], 'target_name' => $host];
        $detail = static fn(?int $status): array => [
            'host' => $host, 'status' => $status, 'rule' => $p['rule'], 'kind' => $p['kind'],
        ];

        $outcome = null;   // ok · gone · drop · retry · fail
        $status = null;
        $reason = null;
        $retryAfter = null;   // 푸시 서버가 준 Retry-After(초, 상한 3600) — 다시 보낼 때만 쓴다
        try {
            if ($signKey === null) {
                throw new WebPushError('no_key');
            }
            $body = WebPush::encrypt(push_payload($p), $s['p256dh'], $s['auth']);
            $aud = WebPush::audienceOf($s['endpoint']);
            if (!isset($jwtCache[$aud])) {
                $jwtCache[$aud] = WebPush::vapidJwt($aud, $now + WebPush::JWT_LIFETIME, $signKey);
            }
            $headers = [
                'TTL'              => (string) ($p['kind'] === 'digest' ? PUSH_TTL_DIGEST : PUSH_TTL_NOW),
                'Content-Encoding' => 'aes128gcm',
                'Authorization'    => WebPush::vapidAuthorization($jwtCache[$aud], (string) $signPublic),
            ];
            if ($p['kind'] === 'alert' || $p['kind'] === 'more' || $p['kind'] === 'test') {
                $headers['Urgency'] = 'high';
            }
            $res = Fetch::pushSend($s['endpoint'], $headers, $body);
            $status = $res['status'];
            $retryAfter = $res['retry_after'];
            if ($status >= 200 && $status < 300) {
                $outcome = 'ok';
            } elseif ($status === 404 || $status === 410) {
                $outcome = 'gone';
                $reason = 'http_status';
            } elseif ($status === 413) {
                $outcome = 'drop';
                $reason = 'http_status';
            } elseif ($status === 429 || $status >= 500) {
                $outcome = 'retry';
                $reason = 'http_status';
            } else {
                $outcome = 'fail';
                $reason = 'http_status';
            }
        } catch (FetchDenied $e) {
            $status = $e->httpStatus;
            $reason = match ($e->reason) {
                'private_ip', 'redirect', 'too_large', 'fetch_failed', 'host_not_allowed' => $e->reason,
                default => 'bad_endpoint',
            };
            $outcome = in_array($reason, ['private_ip', 'too_large', 'fetch_failed'], true) ? 'retry' : 'fail';
        } catch (WebPushError $e) {
            $reason = $e->reason === 'no_key' ? 'no_key' : 'bad_key';
            $outcome = $reason === 'no_key' ? 'retry' : 'fail';
        }

        $result[$outcome . ($reason === null ? '' : ':' . $reason)] = ($result[$outcome . ($reason === null ? '' : ':' . $reason)] ?? 0) + 1;

        if ($outcome === 'ok') {
            $sent++;
            $db->run(
                'UPDATE alert_push_subscriptions SET last_ok_at = now(), fail_count = 0, fail_since = NULL WHERE id = :id',
                [':id' => $s['id']]
            );
            push_audit('allow', 'alert.push_send', null, $ctx + ['detail' => $detail($status)]);
            continue;
        }

        $failed++;
        push_audit('error', 'alert.push_send', $reason, $ctx + ['detail' => $detail($status)]);
        if ($outcome === 'gone') {
            push_revoke($db, $s['id'], $host, 'gone');
            unset($alive[$s['id']]);
            continue;
        }
        if ($outcome === 'drop') {
            continue;   // 413 — 내용 크기 문제(버그)라 다시 보내지 않는다. 받기 실패로 세지 않는다
        }
        $db->run(
            'UPDATE alert_push_subscriptions
                SET fail_count = fail_count + 1, fail_since = COALESCE(fail_since, now())
              WHERE id = :id',
            [':id' => $s['id']]
        );
        if ($outcome === 'retry' && $p['tries'] + 1 < PUSH_MAX_TRIES) {
            $p['tries']++;
            $p['next_at'] = $now + max(PUSH_RETRY_SECONDS, $retryAfter ?? 0);
            $keep[] = $p;
        }
    }
    // 이번에 해지된(gone) 받기로 가던 나머지 대기도 버린다
    $state['pending'] = array_values(array_filter($keep, static fn(array $p): bool => isset($alive[$p['sub']])));
    $saveState();
}

ksort($result);
$resultText = [];
foreach ($result as $k => $c) {
    $resultText[] = $k . ' ' . $c;
}
push_say(sprintf(
    '보냄 %d · 실패 %d%s · 남은 대기 %d',
    $sent,
    $failed,
    $resultText === [] ? '' : ' (' . implode(' · ', $resultText) . ')',
    count($state['pending'])
));
push_say('휴대폰 경보 배치 끝');
exit(PUSH_CLI_OK);
