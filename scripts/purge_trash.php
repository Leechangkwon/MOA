<?php
declare(strict_types=1);

/**
 * scripts/purge_trash.php — 30일 지난 휴지통 파일의 실제 바이트 삭제 (9단계)
 *   PRD 7.8 S-08 "논리 삭제 안전망: 사용자 삭제는 30일 휴지통", ARCHITECTURE 11.1 (매일 04:00)
 *
 * 사용법
 *   sudo -u tbox-php php scripts/purge_trash.php [--dry-run] [--days 30] [--limit 500]
 *
 * 무엇을 지우고 무엇을 남기나
 *   지운다  : 저장소의 **바이트** (/var/lib/tbox-v2/storage/…/UUID.ext)
 *   남긴다  : files 행 전부 — `stored_name` 도 그대로 두고 `purged_at` 만 찍는다
 *
 * 왜 행을 남기나 (9단계 결정 2026-09-11, ARCH 11.1)
 *   · 감사 로그가 `target_id` 로 이 행을 가리킨다. 행을 지우면 "누가 언제 무엇을 내려받았나" 를
 *     되짚을 때 파일 이름조차 남지 않는다 — 증거를 배치가 지우는 셈이다 (PRD S-07).
 *   · `stored_name` 이 있어야 저장소에 남은 파일이 "고아" 인지 "정상적으로 지워진 것" 인지
 *     구별된다 (scripts/consistency_check.php).
 *   · 앱 계정 tbox_app 에는 files DELETE 권한이 아예 없다 (ARCH 10장). UPDATE 만으로 끝나는
 *     설계라 배치에 더 센 계정을 줄 이유가 없다.
 *
 * 순서 (한 건마다)
 *   1) 바이트 삭제 → 2) purged_at UPDATE + 감사 기록(같은 트랜잭션) → 3) 커밋
 *   중간에 죽어도 다음 실행이 이어서 한다. 1) 뒤 2) 전에 죽으면 그 행은 purged_at 이 없는 채
 *   남고, 다음 실행의 Storage::purge() 는 "이미 없다" 를 성공으로 보므로 조용히 마무리된다.
 *   반대 순서(먼저 UPDATE)로 하면 바이트가 남았는데 "지웠다" 고 기록되는 상태가 생긴다.
 *
 * 한 건 실패는 전체를 멈추지 않는다 — `file.purge error purge_failed` 를 남기고 다음 건으로 간다.
 * 매일 도는 배치가 파일 하나 때문에 통째로 서면, 나머지는 영원히 정리되지 않는다.
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

$argv = $_SERVER['argv'];

$dryRun = cli_flag($argv, 'dry-run');

// 보관 일수 — 기본 30일 (PRD F-11 · S-08). 인자는 형태를 좁혀서만 받고 SQL 에는 바인딩으로 들어간다.
$daysRaw = (string) cli_option($argv, 'days', '30');
if (preg_match('/^[0-9]{1,4}$/', $daysRaw) !== 1 || (int) $daysRaw < 1) {
    cli_warn('[사용법 오류] --days 는 1 이상의 정수다');
    exit(TBOX_CLI_BAD_USAGE);
}
$days = (int) $daysRaw;

// 한 번에 처리할 최대 건수 — 배치가 디스크·DB 를 오래 붙들지 않게 한다.
$limitRaw = (string) cli_option($argv, 'limit', '500');
if (preg_match('/^[0-9]{1,5}$/', $limitRaw) !== 1 || (int) $limitRaw < 1) {
    cli_warn('[사용법 오류] --limit 은 1 이상의 정수다');
    exit(TBOX_CLI_BAD_USAGE);
}
$limit = (int) $limitRaw;

$db = Db::instance();

// 대상: 논리 삭제됐고 · 아직 바이트를 안 지웠고 · 삭제한 지 $days 일이 지난 행.
// interval 은 문자열 결합이 아니라 `make_interval(days => :days)` 로 바인딩한다 (PRD S-04).
$rows = $db->run(
    'SELECT id, stored_name, original_name, access_level, size_bytes, sha256, deleted_at
       FROM files
      WHERE deleted_at IS NOT NULL
        AND purged_at IS NULL
        AND deleted_at < now() - make_interval(days => :days)
      ORDER BY deleted_at
      LIMIT :limit',
    [':days' => $days, ':limit' => $limit]
)->fetchAll();

cli_say(sprintf(
    '휴지통 정리 대상: %d건 (기준 %d일 경과%s)',
    count($rows),
    $days,
    $dryRun ? ', --dry-run 이라 아무것도 지우지 않는다' : ''
));

if ($rows === []) {
    cli_say('지울 것이 없다.');
    exit(TBOX_CLI_OK);
}

$purged = 0;
$failed = 0;

foreach ($rows as $row) {
    $id         = (int) $row['id'];
    $stored     = (string) $row['stored_name'];
    $original   = (string) $row['original_name'];
    $level      = (string) $row['access_level'];
    $deletedAt  = (string) $row['deleted_at'];

    if ($dryRun) {
        cli_say(sprintf('  [예정] id=%d %s (삭제 %s, %s 등급)', $id, $stored, $deletedAt, $level));
        continue;
    }

    try {
        // 1) 바이트 삭제. 경로는 Storage::pathFor() 가 만든다 — DB 값이 어떻든 저장소 밖에 닿지 않는다.
        Storage::purge($stored);

        // 2) 표시 + 기록을 한 트랜잭션으로. 기록이 실패하면 purged_at 도 남지 않는다
        //    (PRD S-07 "기록 없는 민감 행동 금지" — 다음 실행이 같은 행을 다시 처리한다).
        $db->begin();
        $db->run(
            'UPDATE files SET purged_at = now() WHERE id = :id AND purged_at IS NULL',
            [':id' => $id]
        );
        Audit::allow('file.purge', [
            'target_type'  => 'file',
            'target_id'    => $id,
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => [
                'mode'       => 'purge',          // ARCH 7.5 mode
                'sha256'     => (string) $row['sha256'],
                'size_bytes' => (int) $row['size_bytes'],
            ],
        ]);
        $db->commit();

        $purged++;
        cli_say(sprintf('  [삭제] id=%d %s', $id, $stored));
    } catch (Throwable $e) {
        $db->rollback();
        $failed++;
        error_log('[tbox-cli] purge_trash id=' . $id . ' 실패: ' . $e->getMessage());
        cli_warn(sprintf('  [실패] id=%d %s — %s', $id, $stored, $e->getMessage()));

        // 실패도 기록한다. 이 기록마저 실패하면 (DB 가 죽은 경우) 예외가 그대로 올라가
        // cli_bootstrap 의 핸들러가 종료 코드 1 로 끝낸다 — 조용한 실패를 만들지 않는다.
        Audit::error('file.purge', 'purge_failed', [
            'target_type'  => 'file',
            'target_id'    => $id,
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => ['mode' => 'purge'],
        ]);
    }
}

cli_say(sprintf('완료: 삭제 %d건, 실패 %d건', $purged, $failed));
exit($failed > 0 ? TBOX_CLI_FAIL : TBOX_CLI_OK);
