<?php
declare(strict_types=1);

/**
 * scripts/alerts.php — 감사 로그를 5분마다 훑어 경보 규칙을 판정한다 (9단계)
 *   PRD 7.11 S-11-A (AL-1 · AL-2 · AL-5 · AL-7 · AL-10 · AL-12 · AL-13 · AL-18 · AL-19 · AL-20 · AL-21 · 서버 층 AL-3 · AL-9)
 *   휴대폰 경보 전달 실패 AL-22 (PRD F-70 · 지시서 docs/f70-push-alerts-directive.md E-3, ARCH 11.4 — 받기 표의 fail_since, origin system)
 *   실행 파일이 든 압축본 AL-21 (PRD F-65 ⑤ · 7.11, ARCH 5.29 · 11.4 — `file.upload_exec allow` 1건이라도 즉시)
 *   개인 프로그램 토큰 AL-20 (PRD F-59 ⑦, ARCH 4.6 · 11.4 — `api_token.issue allow` 1건이라도 즉시)
 *   지문·얼굴 로그인 AL-23 (T:F-72, ARCH 11.4 — `auth.passkey_register allow` 1건 · `auth.login deny passkey_counter` 1건이라도 즉시)
 *   악성코드 AL-19 (PRD F-54 ④, ARCH 5.28 · 11.4 — `file.scan deny infected` 1건이라도 즉시)
 *   ARCHITECTURE 11.1 (5분마다 cron) · 11.4 (경보 구성) · 7.4 `alert.raised|allow` · 7.5 `rule`·`evidence`
 *
 * 사용법
 *   sudo php scripts/alerts.php [--dry-run] [--rule AL-1] [--auth-log <경로>] [--state <경로>]
 *     --dry-run   : 판정만 — 경보도 상태 파일(중복 억제·ssh_seen·auth.log 읽기 위치)도 남기지 않는다 (규칙을 고친 뒤 확인용 · 대기열 2c)
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
 *   30분 안에는 같은 대상으로 다시 발동하지 않는다.
 *   ★ AL-9 만 키가 `AL-9|계정|<명령 요약>` 이다(디버깅 묶음 S-3, 2026-09-26) — 계정만 키로 두면 가벼운 `sudo psql`
 *     한 번이 울린 뒤 30분 동안 같은 계정의 `pg_dump`·`rm -rf` 가 alerts.log·감사에 한 줄도 남지 않았다. 지금은
 *     **서로 다른 명령은 각각 한 줄**, 같은 명령의 되풀이만 30분 억제한다(요약 = 첫 낱말 + 가린 명령 sha256 앞 12자 —
 *     scripts/lib/alerts_lib.php al_sudo_command_key). 줄이 늘어도 **휴대폰은 폭주하지 않는다**: 운영 push.conf 의
 *     `DIGEST_RULES=AL-9,…` 라 AL-9 는 휴대폰에 18:00 하루 요약(개수)으로만 간다(F-70 · ARCH 11.4 · 5.32).
 *   ★ 상태 파일의 **미래 시각**(지금+5분 초과)은 읽을 때 버리고 경고 한 줄(S-9) — 그 폴더에 쓸 수 있는 누군가가
 *     억제 시각을 먼 미래로 적어 경보를 영영 끄지 못하게. 상태 파일이 심볼릭 링크면 읽지도 쓰지도 않는다.
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
 * 「누가 일으켰나(origin)」— F-69 (PRD F-69 ①~④, 지시서 `docs/f69-alert-origin-directive.md` A)
 *   발동하는 모든 경보에 origin(human · system · claude-review · claude-session)을 붙인다. 판정은
 *   src/Origin.php 한 곳이고, 이 파일은 규칙마다 **재료**(대상 계정·UA 표식 집계·sudo 명령)를 SELECT·파싱에
 *   더해 $raise 로 넘길 뿐이다. 경보를 끄거나 문턱을 바꾸지 않는다 — 발동 조건·중복 억제는 전과 같다.
 *   dry-run 출력은 옛 줄 끝에 ` origin=…`(한쪽만 맞으면 ` hint=partial`)만 붙는다.
 *
 * 이 스크립트가 판정하지 않는 규칙과 이유
 *   AL-4 (대량 다운로드) · AL-11 (일일 요약) — 9단계 범위 밖(지시서)
 *   AL-8 (백업 2회 연속 실패) — scripts/verify_backup.sh 가 직접 판정한다(실패 횟수 파일)
 *   AL-9 의 나머지 절반(새 리스닝 포트·`/var/www` 파일 변경, PRD 7.11) — 감사 로그도 auth.log 도
 *        아니라 OS 상태를 봐야 한다. scripts/check_baseline.sh 가 현재 상태만 보여 준다(미자동화)
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';
// F-70 — 줄 로그를 오프셋으로 이어 읽는 한 벌(회전·잘림 규칙). 보내는 배치 alert_push.php 도 같은 파일을 쓴다.
require_once __DIR__ . '/lib/log_tail.php';
// 디버깅 묶음 S — 시각·명령 판정의 순수 함수(업무시간 S-4 · AL-9 명령 요약 S-3 · 상태 파일 시각 S-9). php -r 로 시험한다.
require_once __DIR__ . '/lib/alerts_lib.php';

const STATE_FILE     = '/var/lib/tbox-v2/alerts-state.json';
/** 같은 규칙·같은 대상은 이 시간 안에 다시 발동하지 않는다 (분) */
const DEDUP_MINUTES  = 30;
/** AL-22 만 더 길게 (분) — 휴대폰 경보가 막힌 동안 같은 줄이 하루에 몇 번씩 쌓이지 않게 */
const AL22_DEDUP_MINUTES = 360;
/** F-70 보내는 배치의 설정 — 이 파일이 있으면 휴대폰 경보가 켜진 것이다(없으면 AL-22 의 배치 확인은 조용) */
const AL22_PUSH_CONF = '/etc/tbox-v2/push.conf';
/** 보내는 배치가 매 실행 다시 쓰는 상태 파일 이름(설정의 STATE_DIR 아래 — scripts/alert_push.php 와 같은 이름) */
const AL22_PUSH_STATE_NAME = 'alert-push-state.json';
/** 상태 파일이 이만큼 그대로면 배치가 돌지 못하는 것으로 본다 (분 — cron 은 매분) */
const AL22_STALE_MINUTES = 15;

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

/*
 * S-9 — 상태 파일은 tbox-php 소유 폴더(0750)에 있다. 심볼릭 링크면 **읽지 않는다**(root 가 링크를 따라 다른 파일을
 * 상태로 읽지 않게 — 쓰기는 cli_write_private 가 따로 거부한다). 미래 시각(지금+5분 초과)의 칸은 버리고 경고 한 줄.
 */
$loadNow      = time();
$futureDrops  = 0;
/*
 * 검토 M-3(b) — 읽기 전에 **주인·종류·권한**을 본다: 보통 파일 · 주인 = 이 배치의 계정(운영 root) · 그룹·다른 사람 쓰기 없음 ·
 * 연 뒤 fstat 이 lstat 과 같은 파일. 하나라도 아니면 **빈 상태로 판정**(억제 없음 — 경보가 더 울리는 쪽)하고 경고 한 줄.
 * 폴더 주인(tbox-php)은 파일을 지우고 제 파일로 바꿔 놓을 수 있다 — 미래 시각 검사(5분)만으로는 「지금+4분」을 되풀이해 적어
 * 억제를 이어 가거나 `ssh_seen` 에 제 출처를 심어 AL-3 를 막을 수 있었다. 주인이 다른 파일은 믿지 않는다(R-105).
 */
$stateRaw = null;
clearstatcache();
$stateL = @lstat($stateFile);
if ($stateL !== false) {
    /* 디버깅 묶음 S2 (검토 낮음) — posix 가 없으면 이 배치를 돌리는 계정을 알 수 없다. 전에는 getmyuid()(= **이 스크립트
       파일의 주인**, 운영 changkwon)로 대신해, root 로 도는데도 changkwon 이 주인인 상태 파일을 믿을 수 있었다. 이제는 믿지 않는다. */
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if ($euid === null) {
        cli_warn('[경고] posix 확장이 없어 이 배치를 돌리는 계정을 알 수 없다 — 경보 상태 파일을 믿지 않고 빈 상태로 판정한다: ' . $stateFile);
    } elseif (($stateL['mode'] & 0170000) !== 0100000) {
        cli_warn('[경고] 경보 상태 파일이 보통 파일이 아니다(심볼릭 링크 등) — 읽지 않고 빈 상태로 판정한다(저장도 거부된다): ' . $stateFile);
    } elseif ($stateL['uid'] !== $euid || ($stateL['mode'] & 0022) !== 0 || $stateL['nlink'] !== 1) {
        cli_warn('[경고] 경보 상태 파일의 주인·권한이 맞지 않다(주인 = 이 배치 계정 · 그룹/다른 사람 쓰기 없음 · 하드 링크 하나) — 믿지 않고 빈 상태로 판정한다: ' . $stateFile);
    } else {
        $sfh = @fopen($stateFile, 'rb');
        if ($sfh !== false) {
            $sfs = fstat($sfh);
            if (is_array($sfs) && $sfs['dev'] === $stateL['dev'] && $sfs['ino'] === $stateL['ino']) {
                $got = stream_get_contents($sfh, 1048576);
                $stateRaw = is_string($got) ? $got : null;
            } else {
                cli_warn('[경고] 경보 상태 파일이 읽는 사이 바뀌었다 — 빈 상태로 판정한다: ' . $stateFile);
            }
            fclose($sfh);
        }
    }
}
if ($stateRaw !== null) {
    $raw = $stateRaw;
    if ($raw !== false && $raw !== '') {
        $parsed = json_decode($raw, true, 5);
        if (is_array($parsed)) {
            foreach ($parsed as $k => $v) {
                if ($k === 'ssh_seen') {
                    if (is_array($v)) {
                        foreach ($v as $seenKey => $seenAt) {
                            if (is_string($seenKey) && is_string($seenAt)) {
                                // 미래 시각의 첫 관측은 버린다 — 그 `계정@IP` 가 「이미 아는 출처」로 영영 조용해지지 않게
                                if (strtotime($seenAt) !== false && al_state_time($seenAt, $loadNow) === null) {
                                    $futureDrops++;
                                    continue;
                                }
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
                    // 미래 시각의 억제 칸은 버린다 — `(지금 - 그 시각) < 30분` 이 영원히 참이 되어 그 경보가 꺼지지 않게
                    if (strtotime($v) !== false && al_state_time($v, $loadNow) === null) {
                        $futureDrops++;
                        continue;
                    }
                    $state[$k] = $v;
                }
            }
        }
    }
}
if ($futureDrops > 0) {
    cli_warn(sprintf('[경고] 경보 상태 파일에 지금보다 %d초 넘게 앞선 시각 %d개 — 믿지 않고 버렸다(억제 없음으로 판정): %s',
        AL_STATE_FUTURE_SKEW_SECONDS, $futureDrops, $stateFile));
}
$stateChanged = false;
$now = time();
$fired = 0;

/**
 * 규칙 하나를 발동한다 — 중복 억제를 통과할 때만.
 *
 * F-69 — 넷째 인자 `$origin` 은 규칙마다 src/Origin.php 로 판정한 `{origin, origin_hint}` 다.
 * 중복 억제·발동 여부는 origin 과 **무관하다**(키도 `규칙|대상` 그대로) — origin 은 보여 주기와 분류에만 쓴다.
 * S-3 — 다섯째 인자 `$dedupExtra` 가 있으면 억제 키가 `규칙|대상|그 값` 이 된다(지금은 AL-9 의 명령 요약만 쓴다).
 * 경보 줄의 `subject` 는 그대로 대상이다(계정) — 요약은 근거의 `command_key` 로 보인다.
 *
 * @param array<string,mixed>                          $evidence
 * @param array{origin: string, origin_hint: ?string}  $origin
 */
$raise = function (string $rule, string $subject, array $evidence, array $origin, ?string $dedupExtra = null) use (
    &$state, &$stateChanged, &$fired, $now, $dryRun
): void {
    $windowMinutes = $rule === 'AL-22' ? AL22_DEDUP_MINUTES : DEDUP_MINUTES;
    $originNote = ' origin=' . $origin['origin']
        . ($origin['origin_hint'] === null ? '' : ' hint=' . $origin['origin_hint']);

    $key  = $rule . '|' . $subject . ($dedupExtra === null ? '' : '|' . $dedupExtra);
    $last = isset($state[$key]) ? strtotime($state[$key]) : false;
    if ($last !== false && ($now - $last) < $windowMinutes * 60) {
        cli_say(sprintf('  %s %s — %s 안에 이미 발동해 건너뛴다', $rule, $subject . ($dedupExtra === null ? '' : ' ' . $dedupExtra),
            $windowMinutes % 60 === 0 && $windowMinutes >= 120 ? intdiv($windowMinutes, 60) . '시간' : $windowMinutes . '분'));
        return;
    }
    if ($dryRun) {
        // 옛 출력 그대로 + 끝에 origin (T-10 「발동 목록이 전과 같고 origin 만 붙음」)
        cli_say(sprintf('  [dry-run] %s %s %s%s', $rule, $subject,
            json_encode($evidence, JSON_UNESCAPED_UNICODE), $originNote));
        return;
    }
    cli_raise_alert($rule, ['subject' => $subject] + $evidence, $origin['origin'], $origin['origin_hint']);
    $state[$key]  = date('c', $now);
    $stateChanged = true;
    $fired++;
    cli_say(sprintf('  ★ %s %s 발동%s', $rule, $subject, $originNote));
};

/**
 * PDO(pgsql) 가 돌려준 불리언을 PHP bool 로 — 드라이버·설정에 따라 true/'t'/1 로 온다
 * (consistency_check 의 alive 판정과 같은 방어). NULL(행 0개의 bool_and)은 false 다.
 */
function al_bool(mixed $v): bool
{
    return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
}

/**
 * 집계 SQL 이 `chr(10)` 으로 이어 붙인 계정 이름들을 목록으로 (F-69 묶음 판정 재료).
 * 계정 이름에 줄바꿈이 섞여 있어도(실패 로그인의 대상 이름은 공격자가 정한다) 조각의 **첫 조각**은
 * 원래 이름의 앞부분이라, 쪼개기가 사람 이름을 검토 계정처럼 보이게 만들 수는 없다.
 *
 * @return list<string>
 */
function al_accounts(mixed $joined): array
{
    if (!is_string($joined)) {
        return [''];   // 계정 없음 — 검토 계정이 아니다
    }
    return explode("\n", $joined);
}

/*
 * 묶음 판정에 쓰는 UA 표식 집계 (F-69 A-1) — 아래 규칙들의 SELECT 에 같은 식이 들어간다:
 *     bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
 *   · 「묶음의 **모든** 행이 표식을 달았나」. user_agent 가 NULL 인 행은 **표식 없음**으로 센다 —
 *     `bool_and` 는 NULL 을 건너뛰므로 COALESCE 가 없으면 표식 없는 행이 판정에서 빠진다.
 *   · 접두 글자는 SQL 에 베끼지 않고 Origin::reviewMarkLikePattern() 을 **바인딩**한다 — 표식 접두가
 *     src/Origin.php 한 곳에만 있게(지시서의 「고정 문자열」과 같은 값이고, 요청 값이 아니다).
 *     LIKE 는 대소문자를 가린다 — Origin::hasReviewMark(str_starts_with) 와 같은 판정이다.
 */
$reviewUa = [':review_ua' => Origin::reviewMarkLikePattern()];

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

    // 회전·잘림 규칙은 scripts/lib/log_tail.php 한 벌이다(F-70 — 보내는 배치가 alerts.log 에 같은 함수를 쓴다).
    // 옛 동작을 바꾸지 않도록 줄 단위로 끊지 않는다(wholeLines=false — 16-2 부터의 모양 그대로).
    $tail = log_tail_read(AUTH_LOG, AUTH_LOG_PREV, $pos, AUTH_LOG_MAX_BYTES, false);
    if (!$tail['ok']) {
        // 0640 syslog:adm — root 가 아니면 여기서 끝난다 (cron 은 root 로 돈다)
        return ['reason' => $tail['reason']] + $empty;
    }

    return [
        'ok'      => true,
        'reason'  => '',
        'lines'   => $tail['lines'],
        'inode'   => $tail['inode'],
        'offset'  => $tail['offset'],
        'advance' => true,
        'path'    => AUTH_LOG,
    ];
}

/** 읽은 덩어리를 줄 단위로 자른다 (빈 줄은 버린다) — scripts/lib/log_tail.php 와 한 벌 */
function al_split_lines(string $text): array
{
    return log_tail_split($text);
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
    // 가리는 규칙은 scripts/lib/alerts_lib.php al_scrub_secrets 한 벌(AL-9 명령 요약도 같은 것을 쓴다 — 검토 L-4)
    $clean = al_scrub_secrets($cmd);
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
                min(happened_at) AS first_at, max(happened_at) AS last_at,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs
          WHERE result = 'deny'
            AND actor_name IS NOT NULL
            AND action IN ('file.download','file.delete','file.update','file.level_change','authz.route')
            AND (access_level IN ('admin-only','team')
                 OR reason IN ('role_not_permitted','team_mismatch'))
            AND happened_at > now() - interval '10 minutes'
          GROUP BY actor_name
         HAVING count(*) >= 5",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-1', (string) $r['actor_name'], [
            'rule_text' => 'admin-only·team 접근 거부 10분 내 5회 이상',
            'count'     => (int) $r['n'],
            'window'    => '10 minutes',
            'first_at'  => (string) $r['first_at'],
            'last_at'   => (string) $r['last_at'],
        ], Origin::ofGroup([(string) $r['actor_name']], al_bool($r['all_marked'])));
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
//   T:F-72(2026-09-27) — 지문·얼굴 로그인: 재인증 지문 실패(`auth.reauth deny passkey_*`)와 기기 등록의 비밀번호·코드 실패
//   (`auth.passkey_register deny bad_credentials·bad_code·code_reused`)를 더했다 — 둘 다 세션만 있으면 닿고 계정 잠금 카운터에 든다.
//   로그인 지문 실패(`auth.login deny passkey_*`)는 위 `action = 'auth.login'` 에 저절로 든다(IP 집계).
//   빼는 것: `auth.mfa deny code_required` (순차 2단계 로그인의 정상 1단계다 — 실패가 아니다.
//   Auth::login 도 이것은 실패 횟수에 세지 않는다, ARCH 4.0), `already_enabled`·`weak_password`·
//   `same_password` (추측 시도가 아니다).
//   임계값·창은 그대로다: 10분 20회 이상, 또는 그 창에 계정 잠금(`reason = 'locked'`)이 한 번이라도.
//
// ★ 디버깅 묶음 S2(2026-09-26, 로그인 시도 제한 점검 · R-06) — 「계정 잠금 발생」을 **잠긴 순간**으로도 본다.
//   전에는 잠긴 계정으로 **다시** 로그인을 시도한 행(`auth.login deny locked`)만 잠금으로 셌다. 그래서 정확히 5번 틀리고
//   10분 쉬기를 되풀이하는 잠금 DoS(한 IP 10분 5행 — 20회 기준 밑)와, 로그인 뒤 창구의 코드 추측으로 잠겨 세션이 끊긴 경우는
//   경보가 조용했다. 이제 Auth::countFailure 가 카운터가 한도에 막 닿은 실패마다 `auth.lock deny failed_limit` 한 행을 남기고,
//   여기서 그 행을 잠금 발생으로 센다. 검토 L-3 — 잠금 행은 **실패가 아니다**(실패 뒤의 결과) → 20회 기준의 수(`count`)·
//   `locked`(잠긴 계정으로 다시 로그인한 행)·`after_login` 어디에도 넣지 않고 근거에 `lock_events` 로 따로 적는다. 발동 조건에만 들어간다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-2')) {
    $rows = $db->run(
        "SELECT host(ip) AS src, count(*) FILTER (WHERE action <> 'auth.lock') AS n,
                count(*) FILTER (WHERE reason = 'locked' AND action <> 'auth.lock') AS locked,
                count(*) FILTER (WHERE action = 'auth.lock') AS lock_events,
                count(*) FILTER (WHERE action NOT IN ('auth.login', 'auth.lock')) AS after_login,
                array_to_string(array_agg(DISTINCT action ORDER BY action), ',') AS actions,
                max(happened_at) AS last_at,
                string_agg(DISTINCT COALESCE(NULLIF(actor_name, ''), target_name, ''), chr(10)) AS accounts,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
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
                  OR (action = 'auth.reauth'          AND reason IN ('passkey_invalid','passkey_challenge','passkey_counter'))
                  OR (action = 'auth.passkey_register' AND reason IN ('bad_credentials','bad_code','code_reused'))
                  OR (action = 'auth.lock'            AND reason = 'failed_limit')
                )
          GROUP BY host(ip)
         HAVING count(*) FILTER (WHERE action <> 'auth.lock') >= 20
             OR count(*) FILTER (WHERE reason = 'locked' AND action <> 'auth.lock') > 0
             OR count(*) FILTER (WHERE action = 'auth.lock') > 0",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        /* 출처 판정은 좁은 쪽(구현 보고 결정 1 — 사용자 확인 대기): 실패 로그인의 **대상 이름**(target_name)과 UA 는 둘 다
           바깥의 공격자가 정한다. UA 를 흉내 내며 claude_admin01 을 겨누는 무차별 대입이 「Claude」로 낮아지지 않게,
           출발지가 **이 서버 자신(루프백)** 일 때만 표식으로 센다 — 검토 시험은 늘 127.0.0.1:8082 로 들어온다. */
        $fromHere = in_array((string) $r['src'], ['127.0.0.1', '::1'], true);
        $raise('AL-2', (string) $r['src'], [
            'rule_text'   => '인증 실패(로그인·비밀번호 변경·2단계 인증·재인증) 10분 내 20회 이상 또는 계정 잠금 발생(잠긴 순간·잠긴 계정 로그인 시도)',
            'count'       => (int) $r['n'],
            'locked'      => (int) $r['locked'],
            // S2 검토 L-3 — 잠금이 **걸린** 횟수(auth.lock 행). 위 count·locked 에는 들어가지 않는다
            'lock_events' => (int) $r['lock_events'],
            // 로그인 창구가 아니라 **로그인 뒤** 창구에서 난 실패 수 — 세션 탈취 뒤의 추측과
            // 바깥에서의 무차별 대입을 한눈에 가른다 (R-48)
            'after_login' => (int) $r['after_login'],
            'actions'     => (string) $r['actions'],
            'window'      => '10 minutes',
            'last_at'     => (string) $r['last_at'],
        ], Origin::ofGroup(al_accounts($r['accounts']), $fromHere && al_bool($r['all_marked'])));
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
        "SELECT id, actor_name, target_name, user_agent,
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
        ], Origin::ofAppAction(
            $r['actor_name'] === null ? null : (string) $r['actor_name'],
            $r['user_agent'] === null ? null : (string) $r['user_agent']
        ));
    }
    cli_say(sprintf('AL-5 대상 %d건', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-7 — 감사 로그가 끊겼다 (PRD 7.11 "업무시간에 30분간 0건")
//   로그가 끊긴 것 자체가 침해 신호다. 다만 **이 서버 안에서 하는 판정은 약한 판정**이다 —
//   서버가 통째로 털리면 이 배치도 돌지 않는다. 진짜 감시는 원격 로그 서버 쪽에서 해야 한다
//   (ARCH 11.4 "밖에서 감시"). 여기서는 "앱은 도는데 기록만 끊긴" 경우를 잡는다.
//   업무시간은 서버 로컬 시각 **평일(월~금)** 09:00~18:00(디버깅 묶음 S-4, 2026-09-26 — 전에는 요일을 안 봐서
//   토·일 낮에도 판정했고, 사람이 없는 주말의 30분 0건이 경보로 되풀이됐다). 판정은 scripts/lib/alerts_lib.php
//   al_is_business_hours 한 곳. 공휴일은 모른다(한계 — ARCH 11.4). 2026-09-11 부터 VM·PostgreSQL·PHP 시간대를
//   Asia/Seoul(KST) 로 통일했으므로 이 판정이 실제 한국 업무시간과 맞는다 (README 「서버 시간대」).
// ─────────────────────────────────────────────────────────────
if ($want('AL-7')) {
    $hour = (int) date('G', $now);
    if (al_is_business_hours($now)) {
        $n = (int) $db->run(
            "SELECT count(*) AS n FROM audit_logs WHERE happened_at > now() - interval '30 minutes'"
        )->fetch()['n'];
        if ($n === 0) {
            $raise('AL-7', 'audit_logs', [
                'rule_text' => '업무시간(평일 09~18시)에 감사 로그 30분간 0건',
                'window'    => '30 minutes',
                'count'     => 0,
            ], Origin::ofFixedRule('AL-7'));
        }
        cli_say(sprintf('AL-7 최근 30분 기록 %d건 (업무시간)', $n));
    } else {
        cli_say(sprintf('AL-7 건너뜀 (%s, 현재 %s %d시)', al_off_hours_reason($now), date('D', $now), $hour));
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
                count(DISTINCT host(ip)) AS ips,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs
          WHERE actor_name IS NOT NULL
            AND detail->>'login_ref' IS NOT NULL
            AND happened_at > now() - interval '1 hour'
          GROUP BY actor_name
         HAVING count(DISTINCT detail->>'login_ref') >= 3",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-10', (string) $r['actor_name'], [
            'rule_text'  => '같은 계정에 서로 다른 login_ref 가 1시간 내 3개 이상',
            'login_refs' => (int) $r['refs'],
            'ips'        => (int) $r['ips'],
            'window'     => '1 hour',
        ], Origin::ofGroup([(string) $r['actor_name']], al_bool($r['all_marked'])));
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
                max(happened_at) AS last_at,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs
          WHERE action = 'file.fetch' AND result = 'deny'
            AND actor_name IS NOT NULL
            AND reason IN ('scheme','port','host_not_allowed','private_ip','redirect')
            AND happened_at > now() - interval '10 minutes'
          GROUP BY actor_name
         HAVING count(*) >= 5",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-12', (string) $r['actor_name'], [
            'rule_text' => 'SSRF 탐색 의심 — file.fetch 거부 10분 내 5회 이상',
            'count'     => (int) $r['n'],
            'reasons'   => (string) $r['reasons'],
            'window'    => '10 minutes',
            'last_at'   => (string) $r['last_at'],
        ], Origin::ofGroup([(string) $r['actor_name']], al_bool($r['all_marked'])));
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
                max(happened_at) AS last_at,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs
          WHERE action = 'permission.change' AND result = 'allow'
            AND happened_at > now() - interval '5 minutes'
          GROUP BY actor_name",
        $reviewUa
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
        ], Origin::ofGroup([$r['actor_name'] === null ? null : (string) $r['actor_name']], al_bool($r['all_marked'])));
    }
    cli_say(sprintf('AL-13 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-18 — URL 가져오기 허용 사이트 변경 (PRD 7.11 · F-43, ARCH 5.22 · 11.4, SECURITY R-76)
//   AL-13 과 **같은 형태**다: `fetch_host.change allow` 가 **1건이라도** 있으면 즉시.
//   건수 임계값이 없는 이유도 같다 — 허용 호스트 한 줄이 바뀌면 그 순간부터 **서버가 나갈 수
//   있는 곳**이 달라진다(PRD S-05). 정상 작업이어도 운영 담당이 알아야 하고, Admin 세션을
//   훔친 사람이 한 짓이라면 그것이 곧 SSRF 의 첫 단추다.
//   창은 다른 audit_logs 규칙과 맞춰 5분(배치 주기)이고, 중복 억제는 $raise 가 한다.
//   근거에 **호스트 이름**을 담는다 — 개인정보가 아니고, 무엇이 열렸는지가 곧 알림의 내용이다
//   (AL-13 이 바뀐 칸 이름을 담는 것과 같다). 2단계 인증 코드는 감사 로그에 애초에 없다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-18')) {
    $rows = $db->run(
        "SELECT actor_name, count(*) AS n,
                array_to_string(array_agg(target_name ORDER BY id), ',') AS hosts,
                max(detail->>'reason_text') AS reason_text,
                max(happened_at) AS last_at,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs
          WHERE action = 'fetch_host.change' AND result = 'allow'
            AND happened_at > now() - interval '5 minutes'
          GROUP BY actor_name",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-18', (string) ($r['actor_name'] ?? '?'), [
            'rule_text'   => 'URL 가져오기 허용 사이트 변경 — 건수와 무관하게 즉시 알린다',
            'count'       => (int) $r['n'],
            // 바뀐 호스트 이름 목록. 켠 것인지 끈 것인지는 before/after 로 감사 로그에 있다.
            'hosts'       => (string) $r['hosts'],
            'reason_text' => $r['reason_text'] === null ? '' : (string) $r['reason_text'],
            'window'      => '5 minutes',
            'last_at'     => (string) $r['last_at'],
        ], Origin::ofGroup([$r['actor_name'] === null ? null : (string) $r['actor_name']], al_bool($r['all_marked'])));
    }
    cli_say(sprintf('AL-18 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-19 — 악성코드가 들어왔다 (PRD 7.11 · F-54 ④, ARCH 5.28 · 11.4, SECURITY R-02 · R-86)
//   AL-18·AL-13 과 **같은 형태**다: `file.scan deny infected` 가 **1건이라도** 있으면 즉시.
//   건수 임계값이 없는 이유: 악성 파일 하나가 들어온 것 자체가 사건이다. 서버는 격리로 막았어도
//   **올린 사람의 PC 는 이미 감염돼 있을 수 있고**, 그쪽은 이 서버가 못 막는다 — 알림의 목적이
//   "서버를 지켜라" 가 아니라 "그 PC 를 점검하라" 인 규칙이다.
//   묶는 단위는 **파일 하나**(target_name) 다. 배치가 한 번에 여러 건을 격리해도 파일마다
//   따로 알린다 — 사람이 할 일(그 파일을 올린 사람에게 연락)이 파일마다 다르기 때문이다.
//   근거에 파일 이름·올린 사람 id·시그니처 이름을 담는다 (PRD 7.11 AL-19 evidence 칸).
//   올린 사람은 **id** 로만 담는다 — 계정명은 감사 로그를 열면 나온다(23단계 규칙과 같은 선).
//   창은 다른 audit_logs 규칙과 맞춰 5분(배치 주기)이고, 중복 억제는 $raise 가 한다.
//   F-69 — origin 은 **그 파일을 올린 감사 행**(file.upload·file.upload_exec·file.fetch allow, 같은
//   target_id 의 첫 행)의 actor·UA 로 판정한다. 검사 행은 배치가 남겨 행위자가 없기 때문이다.
//   올린 행을 못 찾으면(옛 파일) 계정·표식이 없는 것으로 세어 `human` 이다. 격리 건수는 5분에 몇 건이라
//   파일마다 한 번 찾는 LATERAL 이 무겁지 않다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-19')) {
    $rows = $db->run(
        "SELECT s.target_name, count(*) AS n,
                max(s.detail->>'signature')   AS signature,
                max(s.detail->>'uploader_id') AS uploader,
                max(s.happened_at)            AS last_at,
                string_agg(DISTINCT COALESCE(up.actor_name, ''), chr(10)) AS accounts,
                bool_and(COALESCE(up.user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs s
           LEFT JOIN LATERAL (
                SELECT a.actor_name, a.user_agent
                  FROM audit_logs a
                 WHERE a.target_type = 'file' AND a.target_id = s.target_id
                   AND a.result = 'allow'
                   AND a.action IN ('file.upload','file.upload_exec','file.fetch')
                 ORDER BY a.id
                 LIMIT 1
           ) up ON true
          WHERE s.action = 'file.scan' AND s.result = 'deny' AND s.reason = 'infected'
            AND s.happened_at > now() - interval '5 minutes'
          GROUP BY s.target_name",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-19', (string) ($r['target_name'] ?? '?'), [
            'rule_text' => '업로드 파일에서 악성코드가 발견돼 격리했다 — 건수와 무관하게 즉시 알린다',
            'count'     => (int) $r['n'],
            'file_name' => (string) ($r['target_name'] ?? ''),
            'signature' => $r['signature'] === null ? '' : (string) $r['signature'],
            'uploader'  => $r['uploader'] === null ? '' : (string) $r['uploader'],
            'window'    => '5 minutes',
            'last_at'   => (string) $r['last_at'],
        ], Origin::ofGroup(al_accounts($r['accounts']), al_bool($r['all_marked'])));
    }
    cli_say(sprintf('AL-19 대상 %d건', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-20 — 개인 프로그램 토큰 발급 (PRD 7.11 · F-59 ⑦, ARCH 4.6 · 11.4, SECURITY R-91)
//   AL-18·AL-13 과 **같은 형태**다: `api_token.issue allow` 가 **1건이라도** 있으면 즉시.
//   건수 임계값이 없는 이유: 개인 토큰은 2단계 인증 없이 그 계정 권한으로 파일을 올리고
//   **지우는** 자격 증명이다. 관리자 세션을 훔친 사람이 토큰을 발급해 두면 세션이 끊긴
//   뒤에도 자격이 남는다 — 발급은 드문 일이라 한 건도 알린다.
//   근거에 담는 것: 발급한 사람(묶는 단위)·대상 계정 목록·토큰 **이름**·**범위**·사유.
//   F-66(2026-09-22)부터 발급자 = 대상 = 본인이고 범위(token_scope)가 근거에 함께 들어간다.
//   ★ 토큰 값도 hint 도 해시도 담지 않는다 — 감사 로그에 애초에 없다(ARCH 4.6, PRD S-09-11).
//   창은 다른 audit_logs 규칙과 맞춰 5분(배치 주기)이고, 중복 억제는 $raise 가 한다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-20')) {
    $rows = $db->run(
        "SELECT actor_name, count(*) AS n,
                array_to_string(array_agg(target_name ORDER BY id), ',') AS targets,
                max(detail->>'token_name')  AS token_name,
                max(detail->>'token_scope') AS token_scope,
                max(detail->>'reason_text') AS reason_text,
                max(happened_at) AS last_at,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs
          WHERE action = 'api_token.issue' AND result = 'allow'
            AND happened_at > now() - interval '5 minutes'
          GROUP BY actor_name",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-20', (string) ($r['actor_name'] ?? '?'), [
            'rule_text'   => '개인 프로그램 토큰 발급 — 건수와 무관하게 즉시 알린다',
            'count'       => (int) $r['n'],
            // 토큰을 받은 계정 목록. 어느 토큰인지는 감사 로그 detail.token_id 에 있다.
            'targets'     => (string) $r['targets'],
            'token_name'  => $r['token_name']  === null ? '' : (string) $r['token_name'],
            // F-66 (PRD 7.11 AL-20 evidence 칸, ARCH 4.7) — 그 토큰이 무엇을 할 수 있나.
            // 발급자 = 대상 = 본인이 된 뒤에도 규칙은 같다(한 건이라도 즉시).
            'token_scope' => $r['token_scope'] === null ? '' : (string) $r['token_scope'],
            'reason_text' => $r['reason_text'] === null ? '' : (string) $r['reason_text'],
            'window'      => '5 minutes',
            'last_at'     => (string) $r['last_at'],
        ], Origin::ofGroup([$r['actor_name'] === null ? null : (string) $r['actor_name']], al_bool($r['all_marked'])));
    }
    cli_say(sprintf('AL-20 대상 %d명', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-21 — 실행 파일이 든 압축본이 들어왔다 (PRD 7.11 · F-65 ⑤, ARCH 5.29 · 11.4, SECURITY R-94)
//   AL-19·AL-20 과 **같은 형태**다: `file.upload_exec allow` 가 **1건이라도** 있으면 즉시.
//   건수 임계값이 없는 이유: 이 업로드는 F-53 의 「실행 파일이 든 zip 은 거부」 를 사람의
//   신원 보증(Admin · admin-only · 요청마다 비밀번호+OTP · 확인 문구)으로 바꾼 갈래다.
//   확인을 거쳤더라도 **관리자 전원이 알아야** 한다 — 계정을 탈취당했거나 게시자가 속은
//   경우의 마지막 그물이 이 알림이기 때문이다(R-94 「막지 못하는 것」 ①②).
//   묶는 단위는 **파일 하나**(target_name) 다 — AL-19 와 같은 이유로, 사람이 할 일
//   (그 묶음이 정말 그 사람이 만든 것인지 확인)이 파일마다 다르다.
//   근거에 담는 것: 파일 이름 · 올린 사람 · 차단 항목 수 (PRD 7.11 AL-21 evidence 칸).
//   ★ 비밀번호도 2단계 코드도 담지 않는다 — 감사 로그에 애초에 없다 (PRD 7.7, ARCH 5.29).
//   창은 다른 audit_logs 규칙과 맞춰 5분(배치 주기)이고, 중복 억제는 $raise 가 한다.
// ─────────────────────────────────────────────────────────────
if ($want('AL-21')) {
    $rows = $db->run(
        "SELECT target_name, count(*) AS n,
                max(actor_name)                      AS actor_name,
                max(detail->>'exec_entry_count')     AS entry_count,
                max(happened_at)                     AS last_at,
                string_agg(DISTINCT COALESCE(actor_name, ''), chr(10)) AS accounts,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua)     AS all_marked
           FROM audit_logs
          WHERE action = 'file.upload_exec' AND result = 'allow'
            AND happened_at > now() - interval '5 minutes'
          GROUP BY target_name",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $raise('AL-21', (string) ($r['target_name'] ?? '?'), [
            'rule_text'        => '실행 파일이 든 압축본이 관리자 확인으로 올라왔다 — 건수와 무관하게 즉시 알린다',
            'count'            => (int) $r['n'],
            'file_name'        => (string) ($r['target_name'] ?? ''),
            'actor_name'       => $r['actor_name'] === null ? '' : (string) $r['actor_name'],
            'exec_entry_count' => $r['entry_count'] === null ? '' : (string) $r['entry_count'],
            'window'           => '5 minutes',
            'last_at'          => (string) $r['last_at'],
        ], Origin::ofGroup(al_accounts($r['accounts']), al_bool($r['all_marked'])));
    }
    cli_say(sprintf('AL-21 대상 %d건', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-23 — 지문·얼굴 로그인 기기 등록 · 열쇠 복제 의심 (T:F-72 지시서 1.6, PRD 7.11, ARCH 11.4, SECURITY R-109)
//   AL-20 과 **같은 형태**다: `auth.passkey_register allow` 가 **1건이라도** 있으면 즉시 — 새 로그인 수단이다. 세션을 훔친 사람이
//   (비밀번호·코드까지 쥐고) 열쇠를 심으면 세션이 끊겨도 남는다(AL-20 토큰 발급과 같은 까닭). 또는 `auth.login deny passkey_counter`
//   1건(열쇠 서명 횟수 역행 — 복제 의심). 대상(subject)은 등록은 한 사람(actor), 역행은 그 계정(target).
//   근거: 등록한 사람 · 기기 요약 · 열쇠 행 번호 · 이름(열쇠 id 원문·공개 키 없음 — 감사에 애초에 없다).
//   origin 은 F-69 판정 그대로(사람 → 휴대폰 즉시 · `claude_`+검토 UA → 18시 요약). 창 5분 · 중복 억제는 $raise.
// ─────────────────────────────────────────────────────────────
if ($want('AL-23')) {
    $rows = $db->run(
        "SELECT CASE WHEN action = 'auth.passkey_register' THEN COALESCE(actor_name, target_name) ELSE target_name END AS who,
                action, count(*) AS n,
                max(detail->>'credential_no')    AS credential_no,
                max(detail->>'credential_label') AS credential_label,
                max(detail->>'device_hint')      AS device_hint,
                max(happened_at) AS last_at,
                bool_and(COALESCE(user_agent, '') LIKE :review_ua) AS all_marked
           FROM audit_logs
          WHERE happened_at > now() - interval '5 minutes'
            AND ((action = 'auth.passkey_register' AND result = 'allow')
              OR (action = 'auth.login' AND result = 'deny' AND reason = 'passkey_counter'))
          GROUP BY 1, action",
        $reviewUa
    )->fetchAll();
    foreach ($rows as $r) {
        $isRegister = (string) $r['action'] === 'auth.passkey_register';
        $raise('AL-23', (string) ($r['who'] ?? '?'), [
            'rule_text'        => $isRegister
                ? '지문·얼굴 로그인 기기 등록 — 건수와 무관하게 즉시 알린다(새 로그인 수단)'
                : '지문·얼굴 로그인 열쇠의 서명 횟수가 뒤로 갔다 — 열쇠 복제 의심',
            'kind'             => $isRegister ? 'register' : 'counter',
            'count'            => (int) $r['n'],
            'account'          => (string) ($r['who'] ?? ''),
            'device_hint'      => $r['device_hint'] === null ? '' : (string) $r['device_hint'],
            'credential_no'    => $r['credential_no'] === null ? '' : (string) $r['credential_no'],
            'credential_label' => $r['credential_label'] === null ? '' : (string) $r['credential_label'],
            'window'           => '5 minutes',
            'last_at'          => (string) $r['last_at'],
        ], Origin::ofGroup([$r['who'] === null ? null : (string) $r['who']], al_bool($r['all_marked'])),
           $isRegister ? 'register' : 'counter');
    }
    cli_say(sprintf('AL-23 대상 %d건', count($rows)));
}

// ─────────────────────────────────────────────────────────────
// AL-22 — 휴대폰 경보가 막혔다 (F-70 지시서 E-3 · L-5, ARCH 11.4 · 5.32, SECURITY R-99). origin `system`. 대상(subject) 셋:
//   push      살아 있는 받기 **전부**에 24시간 동안 전달 성공(2xx)이 한 번도 없다. 재료는 받기 표 `alert_push_subscriptions`
//             의 `fail_since`(연속 실패가 시작된 시각 — 성공하면 NULL, 보내는 배치가 적는다)뿐이다. 조용한 날(보낼 경보가
//             없어 시도 자체가 없는 날)은 `fail_since` 가 비어 있으므로 경보가 나지 않는다 — 「보내 봤는데 안 됐다」만 센다.
//   stale     보내는 배치가 돌지 못한다 — 설정(push.conf)이 있는데 배치의 상태 파일이 15분 넘게 그대로다(배치는 매 실행
//             그 파일을 다시 쓴다). 설정 오류·DB 비밀번호·치명 오류로 배치가 시작조차 못 하면 받기 표에 흔적이 없어
//             위 push 로는 보이지 않는다. 상태 파일이 아직 없으면 설정 파일의 시각부터 센다. 설정 전(파일 없음)이면 조용하다.
//   recovered 앞 실행까지 push·stale 가운데 하나라도 참이었는데 지금 둘 다 아니면 **한 줄만**(상태 키 `AL-22|down`).
//   ★ 왜 보내는 배치가 아니라 여기서 판정하나: 경보 줄은 `alerts.log`(root 0644)에 쓰는데, 보내는 계정 tbox-push 는
//     그 파일을 **읽기만** 한다(L-3 — 폴더 통과 ACL 만). 판정은 늘 경보를 쓰던 이 배치(root)에 둔다. 앱 계정 tbox_app 은
//     그 표에 SELECT 가 있다(D 표). 이 줄은 다음 실행의 보내는 배치가 다시 보내 본다.
//   중복 억제는 이 규칙만 6시간(AL22_DEDUP_MINUTES — 키 `AL-22|push` · `AL-22|stale` · `AL-22|recovered`).
//   표가 아직 없거나(마이그레이션 전) 읽을 권한이 없으면 push 만 건너뛴다 — 나머지 규칙은 그대로 돈다.
//   근거에는 받기 **개수**·시각·분만 담는다 — 주소(endpoint)·키·기기 이름·파일 경로는 담지 않는다(F-70 금지 패턴).
// ─────────────────────────────────────────────────────────────

/**
 * 보내는 배치 설정에서 상태 폴더(STATE_DIR) 한 줄만 읽는다 — 설정 전체를 Env 로 싣지 않는다(이 배치의 .env 값과 섞이지 않게).
 * 파일이 없거나 읽을 수 없거나 줄 모양이 아니면 null (= 휴대폰 경보가 아직 설정되지 않았다).
 */
function al_push_state_dir(string $confPath): ?string
{
    if (!is_file($confPath) || !is_readable($confPath)) {
        return null;
    }
    $text = file_get_contents($confPath, false, null, 0, 65536);
    if (!is_string($text) || preg_match('/^STATE_DIR=(\/[A-Za-z0-9._\/-]{1,255})$/m', $text, $m) !== 1 || strpos($m[1], '..') !== false) {
        return null;
    }
    return $m[1];
}

if ($want('AL-22')) {
    $al22Down = false;

    // stale — 보내는 배치가 돌고 있나
    $pushStateDir = al_push_state_dir(AL22_PUSH_CONF);
    if ($pushStateDir === null) {
        cli_say('AL-22 보내는 배치 — 설정 전(push.conf 없음) · 확인하지 않는다');
    } else {
        clearstatcache();
        $pushState = $pushStateDir . '/' . AL22_PUSH_STATE_NAME;
        $hasState = is_file($pushState);
        $ref = $hasState ? filemtime($pushState) : filemtime(AL22_PUSH_CONF);
        $age = $ref === false ? PHP_INT_MAX : max(0, $now - $ref);
        if ($age > AL22_STALE_MINUTES * 60) {
            $al22Down = true;
            $raise('AL-22', 'stale', [
                'rule_text'  => '휴대폰 경보를 보내는 배치가 15분 넘게 돌지 못함 — 상태 파일이 그대로',
                'state_file' => $hasState ? 'unchanged' : 'missing',
                'minutes'    => $ref === false ? null : intdiv($age, 60),
                'window'     => AL22_STALE_MINUTES . ' minutes',
            ], Origin::ofFixedRule('AL-22'));
        }
        cli_say(sprintf('AL-22 보내는 배치 상태 파일 — %s · %s', $hasState ? '있음' : '없음(설정 파일 시각부터)',
            $ref === false ? '시각을 읽지 못함' : intdiv($age, 60) . '분 전'));
    }

    // push — 살아 있는 받기 전부가 24시간 실패
    $pushTable = $db->run(
        "SELECT to_regclass('public.alert_push_subscriptions') IS NOT NULL AS has_table"
    )->fetch();
    $pushReadable = false;
    if (is_array($pushTable) && al_bool($pushTable['has_table'])) {
        $pushPriv = $db->run(
            "SELECT has_table_privilege('public.alert_push_subscriptions', 'SELECT') AS can_read"
        )->fetch();
        $pushReadable = is_array($pushPriv) && al_bool($pushPriv['can_read']);
    }
    if (!$pushReadable) {
        cli_say('AL-22 건너뜀 (휴대폰 경보 받기 표가 없거나 읽을 수 없다 — F-70 반영 전)');
    } else {
        $r = $db->run(
            "SELECT count(*) AS alive,
                    count(*) FILTER (WHERE fail_since IS NOT NULL
                                       AND fail_since <= now() - interval '24 hours') AS failing,
                    min(fail_since) AS since
               FROM alert_push_subscriptions
              WHERE revoked_at IS NULL"
        )->fetch();
        $alive   = is_array($r) ? (int) $r['alive'] : 0;
        $failing = is_array($r) ? (int) $r['failing'] : 0;
        if ($alive > 0 && $failing === $alive) {
            $al22Down = true;
            $raise('AL-22', 'push', [
                'rule_text'     => '경보를 휴대폰으로 보내는 일이 24시간 동안 실패 — 살아 있는 받기 전부',
                'subscriptions' => $alive,
                'failing_since' => (string) ($r['since'] ?? ''),
                'window'        => '24 hours',
            ], Origin::ofFixedRule('AL-22'));
        }
        cli_say(sprintf('AL-22 살아 있는 받기 %d개 · 24시간 넘게 실패 중 %d개', $alive, $failing));
    }

    // recovered — 막혀 있다가 풀렸으면 한 줄. 막힌 동안은 표시를 매 실행 새로 적는다(7일 지난 상태 키를 버리는 규칙에 걸리지 않게)
    $downKey = 'AL-22|down';
    if ($al22Down) {
        if (!$dryRun) {
            $state[$downKey] = date('c', $now);
            $stateChanged = true;
        }
    } elseif (isset($state[$downKey])) {
        $raise('AL-22', 'recovered', [
            'rule_text' => '휴대폰 경보 전달 복구 — 보내는 배치가 다시 돌고 받기 전부가 24시간 실패 상태가 아님',
        ], Origin::ofFixedRule('AL-22'));
        if (!$dryRun) {
            unset($state[$downKey]);
            $stateChanged = true;
        }
    }
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
                    ], Origin::ofFixedRule('AL-3'));   // 늘 human — SSH 로 들어오는 것은 사람이다
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
                /*
                 * rm 은 앞이 줄 처음·공백·`/` 이고 뒤가 공백일 때만 — sudo 기록의 COMMAND 는 늘 절대 경로
                 * (`/usr/bin/rm -rf …`)라 옛 식 `(^|\s)rm\s` 는 앞이 `/` 인 rm 을 못 잡았다(R-100 남은 틈, 보안 넷 2026-09-26).
                 * 뒤의 공백 조건은 그대로 — `/usr/bin/rmdir`·`/usr/bin/firmware` 처럼 rm 이 이름 속에 든 명령은 울리지 않는다.
                 * 다른 도구(psql·dropdb …)는 이미 `(^|\/|\s)` 였다. 발동 조건만 넓힌 것이고 origin 판정(Origin::ofSudoCommand)은 그대로다.
                 */
                if (preg_match(
                        '/(^|\/|\s)(psql|pg_dump|pg_restore|dropdb|truncate|chattr)\b|(^|\/|\s)rm\s|find\b.*-delete/',
                        $command
                    ) !== 1
                ) {
                    continue;   // 일상적인 sudo(예: systemctl status)는 알리지 않는다
                }
                $sudoHit++;
                // 비밀값 조각을 지우고 앞 200자만 (PRD 7.7). origin 판정도 **이 가린 문자열**로만 한다
                // (F-69 A-1) — 200자에 닿았으면 뒤가 잘렸을 수 있으므로 겨눈 DB 를 다 봤다고 할 수 없다(→ human).
                $scrubbed = al_scrub_command($command);
                $complete = iconv_strlen($scrubbed, 'UTF-8') < SUDO_CMD_CHARS;
                // S-3 — 억제 키에 명령 요약을 넣는다: 다른 명령은 각각 한 줄, 같은 명령의 되풀이만 30분 억제.
                //        (휴대폰은 push.conf DIGEST_RULES=AL-9 로 18:00 요약 — 줄이 늘어도 푸시 폭주는 없다.)
                // 요약은 자르기 **전** 전체(비밀만 가린 것)로 — 앞 200자가 같은 다른 명령이 한 키로 묶이지 않게(검토 L-4)
                $commandKey = al_sudo_command_key(al_scrub_secrets($command));
                $raise('AL-9', $account, [
                    'rule_text'   => 'sudo 로 데이터·로그를 지우거나 통째로 읽는 명령 실행',
                    'user'        => $account,
                    'command'     => $scrubbed,
                    'command_key' => $commandKey,
                    'when'        => al_line_time($line),
                ], Origin::ofSudoCommand($scrubbed, $complete), $commandKey);
            }
        }

        // 읽기 위치는 **실제로 판정을 남기는 실행에서만** 앞으로 민다. dry-run 이 밀면 그 사이 줄을 다음 cron 이
        // 건너뛴다(AL-3·AL-9 누락 — 개발 대기열 2c, 점검 봇 발견 2026-09-24). ssh_seen 의 !$dryRun 과 같은 규칙.
        if (!$dryRun && $read['advance']
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
            !$read['advance'] ? ' (시험 모드: 오프셋을 저장하지 않는다)' : ($dryRun ? ' (dry-run: 오프셋을 저장하지 않는다)' : '')
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
