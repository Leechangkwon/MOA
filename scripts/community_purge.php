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
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

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

try {
    $owner = new PDO('pgsql:host=' . Env::get('DB_HOST') . ';dbname=' . Env::get('DB_NAME'), 'tbox_owner', null, [
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
if ($run && $ids !== []) {
    Audit::allow('page.purge', [
        'target_type' => 'system',
        'detail'      => ['count' => count($ids), 'page_ids' => array_slice($ids, 0, 500)],
    ]);
}
exit(TBOX_CLI_OK);
