<?php
declare(strict_types=1);

/**
 * scripts/alerts.php — 감사 로그를 5분마다 훑어 경보 규칙을 판정한다 (9단계)
 *   PRD 7.11 S-11-A (AL-1 · AL-2 · AL-5 · AL-7 · AL-10 · AL-12)
 *   ARCHITECTURE 11.1 (5분마다 cron) · 11.4 (경보 구성) · 7.4 `alert.raised|allow` · 7.5 `rule`·`evidence`
 *
 * 사용법
 *   sudo php scripts/alerts.php [--dry-run] [--rule AL-1]
 *     --dry-run : 판정만 하고 경보를 남기지 않는다 (규칙을 고친 뒤 확인용)
 *     --rule    : 한 규칙만 돌린다
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
 * 이 스크립트가 판정하지 않는 규칙과 이유
 *   AL-3 (처음 보는 IP) · AL-4 (대량 다운로드) · AL-11 (일일 요약) — 9단계 범위 밖(지시서)
 *   AL-8 (백업 2회 연속 실패) — scripts/verify_backup.sh 가 직접 판정한다(실패 횟수 파일)
 *   AL-9 (새 포트·새 sudo 사용자·파일 변경) — 감사 로그가 아니라 OS 를 봐야 한다.
 *                                            scripts/check_baseline.sh 가 현재 상태만 보여 준다(미자동화)
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

const STATE_FILE     = '/var/lib/tbox-v2/alerts-state.json';
/** 같은 규칙·같은 대상은 이 시간 안에 다시 발동하지 않는다 (분) */
const DEDUP_MINUTES  = 30;

$argv    = $_SERVER['argv'];
$dryRun  = cli_flag($argv, 'dry-run');
$onlyRaw = cli_option($argv, 'rule');
if ($onlyRaw !== null && preg_match('/^AL-[0-9]{1,2}$/', $onlyRaw) !== 1) {
    cli_warn('[사용법 오류] --rule 은 AL-n 형식이다');
    exit(TBOX_CLI_BAD_USAGE);
}
$only = $onlyRaw;

$db = Db::instance();

// ─── 중복 억제 상태 ─────────────────────────────────────────────
/** @var array<string,string> `규칙|대상` => 마지막 발동 시각(ISO) */
$state = [];
if (is_file(STATE_FILE)) {
    $raw = file_get_contents(STATE_FILE);
    if ($raw !== false && $raw !== '') {
        $parsed = json_decode($raw, true, 4);
        if (is_array($parsed)) {
            foreach ($parsed as $k => $v) {
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
// ─────────────────────────────────────────────────────────────
if ($want('AL-2')) {
    $rows = $db->run(
        "SELECT host(ip) AS src, count(*) AS n,
                count(*) FILTER (WHERE reason = 'locked') AS locked,
                max(happened_at) AS last_at
           FROM audit_logs
          WHERE action = 'auth.login' AND result = 'deny' AND ip IS NOT NULL
            AND happened_at > now() - interval '10 minutes'
          GROUP BY host(ip)
         HAVING count(*) >= 20 OR count(*) FILTER (WHERE reason = 'locked') > 0"
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-2', (string) $r['src'], [
            'rule_text' => '로그인 실패 10분 내 20회 이상 또는 계정 잠금 발생',
            'count'     => (int) $r['n'],
            'locked'    => (int) $r['locked'],
            'window'    => '10 minutes',
            'last_at'   => (string) $r['last_at'],
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
//   업무시간은 서버 로컬 시각 09:00~18:00 (현재 VM 은 UTC — ARCH 11.3 시간 동기화 항목).
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

// ─── 상태 저장 (오래된 항목은 버린다 — 파일이 무한히 자라지 않게) ───
if ($stateChanged) {
    foreach ($state as $k => $v) {
        $t = strtotime($v);
        if ($t === false || ($now - $t) > 7 * 24 * 3600) {
            unset($state[$k]);
        }
    }
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('경보 상태를 직렬화할 수 없습니다');
    }
    cli_write_private(STATE_FILE, $json . "\n");
}

cli_say(sprintf('경보 판정 끝 — 이번에 발동 %d건%s', $fired, $dryRun ? ' (dry-run)' : ''));
exit(TBOX_CLI_OK);
