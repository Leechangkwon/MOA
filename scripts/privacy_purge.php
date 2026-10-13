<?php
declare(strict_types=1);

/**
 * scripts/privacy_purge.php — 보관 기간 정리 · 탈퇴 30일 뒤 익명화 (사이트 Q:F-13 지시서 P2 · 기획 ../docs/privacy-terms-plan.md 5.1 · 5.2)
 *
 * 사용법
 *   sudo php scripts/privacy_purge.php                    ← 기본 **dry**: 무엇이 지워질지 종류별 **건수만** 보인다(아무것도 바꾸지 않는다)
 *   sudo php scripts/privacy_purge.php --run              ← 실제 정리(cron 04:40)
 *   sudo php scripts/privacy_purge.php --run --max 5000 --accounts 20
 *
 * 하는 일 — 5.1 표대로(무엇을 지우고 무엇을 남기는지는 마이그레이션 2026-10-21_privacy_retention.sql 머리글)
 *   ① public.privacy_purge_expired(dry, max)   1:1 메시지 · AI 대화 · AI 도구 · 커뮤니티 AI 입력·출력 1년 ·
 *                                              지운 사진 기록 · 여행 계획 30일(사진 파일까지) · 감사 기록 3년
 *   ② public.privacy_purge_accounts(dry, max)  탈퇴 처리 30일 뒤 — 아이디 deleted-<번호> · 표시 이름 「탈퇴한 사용자 #<번호>」 ·
 *                                              개인 공간 문서 · 사진 기록(파일까지) · 여행 계획 삭제 · 로그인 수단의 남은 행 삭제
 *   두 함수는 **표 소유자(tbox_owner)만** 부를 수 있으므로 여기서만 소유자 계정으로 붙는다(`/etc/tbox-v2/owner.pgpass`, root 0600 —
 *   libpq 가 PGPASSFILE 로 읽는다. 비밀번호를 코드·인자에 두지 않는다 — community_purge.php 와 같은 방식). 앱 계정 권한은 넓히지 않는다.
 *
 * 파일(사진 기록 원본 · 모아 AI 그림)
 *   함수가 지운 줄의 파일 이름을 돌려주면, **소유자 트랜잭션이 열린 동안** 파일을 먼저 지우고 커밋한다(purge_trash.php 와 같은 순서 —
 *   「파일만 남는 일」을 만들지 않는다). 파일을 지우다 실패하면 ROLLBACK — 줄도 파일 일부도 다음 실행이 이어서 지운다(없는 파일 = 목표 달성).
 *   파일 이름은 DB 가 준 값이지만 그대로 믿지 않는다 — 사진은 UUID 모양 · AI 그림은 Storage::isValidStoredName 을 지난 것만 경로가 된다.
 *
 * 문서 전용 첨부 (사이트 Q:F-12 U3-1b · 커뮤니티 ARCH 24.6)
 *   ② 가 부르는 `community.privacy_purge_pages` 는 지우는 개인 공간 문서의 첨부 **행**을 지우며 디스크 이름을 영구 삭제 대기열
 *   (`community.page_attachment_purge_queue`)에 먼저 적는다(같은 트랜잭션). ② 를 커밋한 **뒤** 이 스크립트가 그 대기열을 비운다 —
 *   휴지통 정리(community_purge.php)와 **같은 함수** `attachment_purge_drain()`(scripts/lib/attachment_purge.php — 경로는 Storage::purge 한 곳).
 *   파일을 지운 뒤에야 대기열 줄을 지우므로 실패해도 다음 실행(이 스크립트든 04:10 community_purge 든)이 잇는다. 사진 · AI 그림처럼
 *   「커밋 전에 파일 먼저」가 아닌 까닭: 대기열이 이름을 붙들고 있어 행 · 파일 어느 쪽도 흔적 없이 남지 않는다.
 *   남는 것: 그 사람이 **팀 공간 · 남의 문서**에 올린 첨부(그 문서 것이다 — 기획 5.1 「팀 공간·게시판 글은 남긴다」와 같다).
 *   기록: privacy.purge allow detail.attachment_files(지운 수) · 실패는 privacy.purge error purge_failed detail.attachment_failures(수 — 이름 · 경로 0).
 *
 * 기록 — 내용 0, 건수만
 *   표준출력은 종류별 건수 한 줄씩(글·이름·파일 이름 0). 감사(앱 계정 — DB 줄 + 감사 파일이라 audit_chain 해시 체인이 이어진다):
 *     privacy.purge allow                       --run 마다 한 줄 · detail.counts = 종류별 건수 · detail.files = 지운 파일 수
 *     account.purge allow                       익명화한 계정마다 한 줄(대상 = deleted-<번호>) · detail.counts
 *     account.purge error still_active|name_conflict   건너뛴 계정(점검 필요)
 *     privacy.purge error purge_failed          실패(트랜잭션은 ROLLBACK)
 *
 * 실행 계정: cron 의 root. 환경 변수(시험용 — cron 은 쓰지 않는다): TBOX_OWNER_PGPASS(기본 /etc/tbox-v2/owner.pgpass) ·
 *   TBOX_OWNER_ROLE(기본 tbox_owner — 소문자·숫자·밑줄만).
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';
require_once __DIR__ . '/../src/community/Memories.php';
require_once __DIR__ . '/lib/attachment_purge.php';

$argv = $_SERVER['argv'];
$run  = cli_flag($argv, 'run');

$maxRaw = (string) cli_option($argv, 'max', '5000');
if (preg_match('/^[0-9]{1,5}$/', $maxRaw) !== 1 || (int) $maxRaw < 1 || (int) $maxRaw > 50000) {
    cli_warn('[사용법 오류] --max 는 1~50000');
    exit(TBOX_CLI_BAD_USAGE);
}
$accountsRaw = (string) cli_option($argv, 'accounts', '20');
if (preg_match('/^[0-9]{1,3}$/', $accountsRaw) !== 1 || (int) $accountsRaw < 1 || (int) $accountsRaw > 500) {
    cli_warn('[사용법 오류] --accounts 는 1~500');
    exit(TBOX_CLI_BAD_USAGE);
}
$max      = (int) $maxRaw;
$accounts = (int) $accountsRaw;

$pgpass = getenv('TBOX_OWNER_PGPASS') ?: '/etc/tbox-v2/owner.pgpass';
if (!is_readable($pgpass)) {
    cli_warn('[설정 오류] 소유자 pgpass 를 읽을 수 없다: ' . $pgpass . ' (root 로 실행)');
    exit(TBOX_CLI_BAD_USAGE);
}
$ownerRole = getenv('TBOX_OWNER_ROLE') ?: 'tbox_owner';
if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $ownerRole) !== 1) {
    cli_warn('[설정 오류] TBOX_OWNER_ROLE 형식이 올바르지 않다');
    exit(TBOX_CLI_BAD_USAGE);
}
putenv('PGPASSFILE=' . $pgpass);

/** 지운 줄의 파일 하나를 지운다 — 경로는 검증한 이름으로만 만든다(사용자 입력 0) */
function privacy_purge_unlink(string $kind, string $name): void
{
    if ($kind === 'memory_file') {
        if (!Memories::uuid($name)) {
            throw new RuntimeException('사진 파일 이름이 UUID 가 아니다');
        }
        $path = Memories::directory() . '/' . $name;
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('사진 파일 자리가 링크다');
        }
        if (file_exists($path) && (!is_file($path) || !unlink($path))) {
            throw new RuntimeException('사진 파일을 지울 수 없다');
        }
        return;
    }
    if ($kind === 'assist_file') {
        if (!Storage::isValidStoredName($name)) {
            throw new RuntimeException('AI 그림 파일 이름이 저장 이름 모양이 아니다');
        }
        Storage::purge($name);   // 없으면 목표 달성 · 일반 파일이 아니면 예외
        return;
    }
    throw new RuntimeException('알 수 없는 파일 종류');
}

/** 종류별 건수 한 줄 (내용·이름 0) */
function privacy_purge_line(string $label, array $counts): string
{
    $parts = [];
    foreach ($counts as $kind => $n) {
        $parts[] = $kind . '=' . (int) $n;
    }
    return $label . ($parts === [] ? ' (없음)' : ' ' . implode(' ', $parts));
}

$prefix = $run ? '' : '[dry] ';
$expiredCounts = [];
$expiredFiles  = 0;
$accountRows   = [];   // account_id => ['counts'=>[], 'files'=>int, 'skip'=>?string]

try {
    $port = Env::getOrEmpty('DB_PORT');
    $dsn  = 'pgsql:host=' . Env::get('DB_HOST') . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . Env::get('DB_NAME');
    $owner = new PDO($dsn, $ownerRole, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // ① 보관 기간이 지난 줄
    $owner->beginTransaction();
    $st = $owner->prepare('SELECT item, n, ref FROM public.privacy_purge_expired(CAST(:dry AS boolean), CAST(:max AS int))');
    $st->execute([':dry' => $run ? 'false' : 'true', ':max' => $max]);
    $files = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $kind = (string) $row['item'];
        if ($kind === 'assist_file' || $kind === 'memory_file') {
            $files[] = [$kind, (string) $row['ref']];
            continue;
        }
        $expiredCounts[$kind] = (int) $row['n'];
    }
    $expiredFiles = count($files);
    if ($run) {
        foreach ($files as [$kind, $name]) {
            privacy_purge_unlink($kind, $name);   // 실패하면 예외 → 아래 catch 가 ROLLBACK
        }
        $owner->commit();
    } else {
        $owner->rollBack();
    }

    // ② 탈퇴 처리 30일이 지난 계정
    $owner->beginTransaction();
    $st = $owner->prepare('SELECT account_id, item, n, ref FROM public.privacy_purge_accounts(CAST(:dry AS boolean), CAST(:max AS int))');
    $st->execute([':dry' => $run ? 'false' : 'true', ':max' => $accounts]);
    $files = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $uid  = (int) $row['account_id'];
        $kind = (string) $row['item'];
        $accountRows[$uid] ??= ['counts' => [], 'files' => 0, 'skip' => null];
        if ($kind === 'memory_file') {
            $files[] = [$kind, (string) $row['ref']];
            $accountRows[$uid]['files']++;
            continue;
        }
        if ($kind === 'still_active' || $kind === 'name_conflict') {
            $accountRows[$uid]['skip'] = $kind;
            continue;
        }
        $accountRows[$uid]['counts'][$kind] = (int) $row['n'];
    }
    if ($run) {
        foreach ($files as [$kind, $name]) {
            privacy_purge_unlink($kind, $name);
        }
        $owner->commit();
    } else {
        $owner->rollBack();
    }
} catch (Throwable $e) {
    if (isset($owner) && $owner instanceof PDO && $owner->inTransaction()) {
        $owner->rollBack();
    }
    error_log('[tbox-cli] privacy_purge 실패: ' . $e->getMessage());
    cli_warn('[실패] 보관 기간 정리를 멈췄다 — 바뀐 것은 이 단계 앞까지만(상세는 앱 로그)');
    if ($run) {
        Audit::error('privacy.purge', 'purge_failed', ['target_type' => 'system', 'detail' => ['max' => $max, 'accounts' => $accounts]]);
    }
    exit(TBOX_CLI_FAIL);
}

// ③ U3-1b — 탈퇴 파기로 지운 문서 첨부의 디스크 파일(영구 삭제 대기열). ② 커밋 **뒤** — 실패는 건수만 남기고 다음 실행이 잇는다.
$attDrain = ['ready' => false, 'queued' => 0, 'removed' => 0, 'failed' => 0];
try {
    $attDrain = attachment_purge_drain($owner, $run);
} catch (Throwable $e) {
    $attDrain['failed']++;
    error_log('[tbox-cli] privacy_purge 첨부 대기열 실패: ' . get_class($e));
}

cli_say(privacy_purge_line($prefix . '보관 기간 정리:', $expiredCounts) . ' 파일=' . $expiredFiles);
$anonymized = 0;
foreach ($accountRows as $uid => $info) {
    if ($info['skip'] !== null) {
        cli_say(sprintf('%s탈퇴 계정 #%d: 건너뜀(%s)', $prefix, $uid, $info['skip']));
        continue;
    }
    $anonymized++;
    cli_say(privacy_purge_line(sprintf('%s탈퇴 계정 #%d:', $prefix, $uid), $info['counts']) . ' 파일=' . $info['files']);
}
if ($accountRows === []) {
    cli_say($prefix . '탈퇴 계정: 대상 없음');
}
if ($attDrain['ready']) {
    cli_say($run
        ? sprintf('문서 첨부 파일: %d개 지움%s', $attDrain['removed'], $attDrain['failed'] > 0 ? ' · 실패 ' . $attDrain['failed'] . '개(다음 실행이 다시)' : '')
        : sprintf('[dry] 대기열의 문서 첨부 파일: %d개(이번 탈퇴 문서의 첨부는 --run 때 대기열에 들어간다)', $attDrain['queued']));
}

if ($run) {
    Audit::allow('privacy.purge', [
        'target_type' => 'system',
        'target_name' => '보관 기간 정리',
        'detail'      => ['counts' => $expiredCounts, 'files' => $expiredFiles, 'accounts' => $anonymized,
                          'attachment_files' => $attDrain['removed']],
    ]);
    foreach ($accountRows as $uid => $info) {
        $target = ['target_type' => 'user', 'target_id' => $uid, 'target_name' => 'deleted-' . $uid];
        if ($info['skip'] !== null) {
            // 건너뛴 계정은 이름이 바뀌지 않았다 — 지금 아이디로 남긴다(관리자가 찾아 점검한다)
            $nameSql = 'SELECT username FROM users WHERE id = :id';
            $target['target_name'] = (string) (Db::instance()->run($nameSql, [':id' => $uid])->fetchColumn() ?: '?');
            Audit::error('account.purge', $info['skip'], $target);
            continue;
        }
        Audit::allow('account.purge', $target + ['detail' => ['counts' => $info['counts'], 'files' => $info['files']]]);
    }
    if ($attDrain['failed'] > 0) {
        Audit::error('privacy.purge', 'purge_failed', ['target_type' => 'system',
            'detail' => ['max' => $max, 'accounts' => $accounts, 'attachment_failures' => $attDrain['failed']]]);
        exit(TBOX_CLI_FAIL);
    }
}
exit(TBOX_CLI_OK);
