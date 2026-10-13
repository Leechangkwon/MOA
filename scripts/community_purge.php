<?php
declare(strict_types=1);

/**
 * scripts/community_purge.php — 커뮤니티 휴지통 30일 정리 (7-A, C:F-29 · T-32)
 *
 * 사용법
 *   sudo php scripts/community_purge.php            ← 기본 **dry**: 무엇이 지워질지만 보인다
 *   sudo php scripts/community_purge.php --run      ← 실제 정리(cron 04:10)
 *   sudo php scripts/community_purge.php --run --max 500
 *
 * 하는 일
 *   정리는 DB 함수 `community.purge_trash(dry, max)` 가 한다(마이그레이션 2026-09-26_community_purge.sql — 무엇을 지우고
 *   무엇을 남기는지는 그 머리글). 그 함수는 **표 소유자(tbox_owner)만** 부를 수 있으므로 여기서만 소유자 계정으로
 *   붙는다(`/etc/tbox-v2/owner.pgpass`, root 0600 — libpq 가 PGPASSFILE 로 읽는다. 비밀번호를 코드·인자에 두지 않는다).
 *   결과는 앱 감사로 남긴다 — `page.purge allow`(건수·페이지 번호, 제목 없음) · 실패는 `page.purge error purge_failed`
 *   (DB 줄 + 감사 파일이라 audit_chain 해시 체인이 그대로 이어진다 — SQL 로 감사 표에 직접 쓰지 않는 이유).
 *
 * Q:F-12 U3-1 문서 전용 첨부 (커뮤니티 ARCH 24.6)
 *   `purge_trash` 는 영구 삭제한 페이지의 첨부 **행**을 지우며 디스크 이름을 `community.page_attachment_purge_queue` 에 먼저 적는다
 *   (같은 트랜잭션 — 「행을 지우기 전에 경로 목록을 받아 두는 순서」). 이 스크립트가 `--run` 때 그 대기열을 읽어 디스크 파일을
 *   `Storage::purge(이름, Storage::AREA_ATTACHMENTS)` 로 지우고(경로는 그 함수 한 곳 — 이름 정규식 · realpath 로 저장소 안),
 *   지운 뒤에야 대기열 줄을 지운다. 중간에 죽어도 다음 실행이 잇는다(이미 없는 파일은 성공으로 본다). dry 는 건수만 보인다.
 *   결과는 `page.purge allow` detail 의 `attachment_files`(지운 수) · 실패는 `page.purge error purge_failed`(실패 수 — 이름 · 경로 0).
 *   소유자 계정 이름은 기본 `tbox_owner` — 합성 시험 DB 처럼 소유자가 다른 곳에서만 `TBOX_OWNER_DB_USER` 로 바꾼다(소문자 역할 이름 형태만).
 *   U3-1b: 대기열을 비우는 일은 scripts/lib/attachment_purge.php 의 `attachment_purge_drain()` 한 함수 — 탈퇴 파기(privacy_purge.php)도
 *   같은 함수를 부른다(탈퇴 파기의 `community.privacy_purge_pages` 도 같은 대기열에 이름을 적는다).
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';
require_once __DIR__ . '/lib/attachment_purge.php';

$argv = $_SERVER['argv'];
$run  = cli_flag($argv, 'run');
$maxRaw = (string) cli_option($argv, 'max', '500');
if (preg_match('/^[0-9]{1,4}$/', $maxRaw) !== 1 || (int) $maxRaw < 1 || (int) $maxRaw > 5000) {
    cli_warn('[사용법 오류] --max 는 1~5000');
    exit(TBOX_CLI_BAD_USAGE);
}
$max = (int) $maxRaw;

$pgpass = getenv('TBOX_OWNER_PGPASS') ?: '/etc/tbox-v2/owner.pgpass';
if (!is_readable($pgpass)) {
    cli_warn('[설정 오류] 소유자 pgpass 를 읽을 수 없다: ' . $pgpass . ' (root 로 실행)');
    exit(TBOX_CLI_BAD_USAGE);
}
putenv('PGPASSFILE=' . $pgpass);
$ownerUser = getenv('TBOX_OWNER_DB_USER') ?: 'tbox_owner';
if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $ownerUser) !== 1) {
    cli_warn('[설정 오류] TBOX_OWNER_DB_USER 는 소문자 역할 이름 형태여야 한다');
    exit(TBOX_CLI_BAD_USAGE);
}

try {
    $owner = new PDO('pgsql:host=' . Env::get('DB_HOST') . ';dbname=' . Env::get('DB_NAME'), $ownerUser, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $st = $owner->prepare('SELECT page_id, page_public_id FROM community.purge_trash(CAST(:dry AS boolean), CAST(:max AS int))');
    $st->execute([':dry' => $run ? 'false' : 'true', ':max' => $max]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[tbox-cli] community_purge 실패: ' . $e->getMessage());
    cli_warn('[실패] ' . $e->getMessage());
    if ($run) {
        Audit::error('page.purge', 'purge_failed', ['target_type' => 'system', 'detail' => ['max' => $max]]);
    }
    exit(TBOX_CLI_FAIL);
}

$ids = array_map(static fn (array $r): int => (int) $r['page_id'], $rows);
cli_say(sprintf('%s: %d개%s', $run ? '영구 삭제' : '[dry] 지울 대상', count($ids), $ids === [] ? '' : ' — ' . implode(', ', array_slice($ids, 0, 50))));

/* ─── Q:F-12 U3-1 — 영구 삭제된 문서 첨부의 디스크 파일 (대기열 → 파일 지우기 → 대기열 줄 지우기) ───
   U3-1b: 탈퇴 파기(scripts/privacy_purge.php)와 **같은 함수**(scripts/lib/attachment_purge.php) — 출력 문장은 그대로다. */
$attRemoved = 0;
$attFailed  = 0;
try {
    $drain = attachment_purge_drain($owner, $run);
    $attRemoved = $drain['removed'];
    $attFailed  = $drain['failed'];
    if ($drain['ready']) {
        cli_say($run
            ? sprintf('첨부 파일: %d개 지움%s', $attRemoved, $attFailed > 0 ? ' · 실패 ' . $attFailed . '개(다음 실행이 다시)' : '')
            : sprintf('[dry] 지울 첨부 파일: %d개', $drain['queued']));
    }
} catch (Throwable $e) {
    $attFailed++;
    error_log('[tbox-cli] community_purge 첨부 대기열 실패: ' . get_class($e));
}

if ($run && ($ids !== [] || $attRemoved > 0)) {
    Audit::allow('page.purge', [
        'target_type' => 'system',
        'detail'      => ['count' => count($ids), 'page_ids' => array_slice($ids, 0, 500), 'attachment_files' => $attRemoved],
    ]);
}
if ($run && $attFailed > 0) {
    Audit::error('page.purge', 'purge_failed', ['target_type' => 'system', 'detail' => ['max' => $max, 'attachment_failures' => $attFailed]]);
    exit(TBOX_CLI_FAIL);
}
exit(TBOX_CLI_OK);
