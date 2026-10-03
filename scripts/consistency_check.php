<?php
declare(strict_types=1);

/**
 * scripts/consistency_check.php — DB ↔ 저장소 정합성 검사 (9단계)
 *   PRD 7.8 S-08 복구 절차 6단계 "DB 레코드 ↔ 실제 파일 존재 여부, SHA-256 일치"
 *   PRD 7.11 AL-6 "정합성 검사에서 sha256 불일치·고아 파일"
 *   ARCHITECTURE 11.1 (매주 일 02:00) · 11.2 6단계 (복구 직후)
 *
 * 사용법
 *   php scripts/consistency_check.php [--strict] [--quiet]
 *     --strict : 불일치가 하나라도 있으면 종료 코드 2 (복구 스크립트가 이 값을 본다)
 *     --quiet  : 목록을 표준출력에 찍지 않는다 (cron 용. 기록은 그대로 남는다)
 *
 * 무엇을 세 가지로 나눠 보나
 *   ① 누락 (missing)        DB 에 살아 있는 파일인데 저장소에 바이트가 없다   → 다운로드가 깨진다
 *   ② 불일치 (hash_mismatch) 바이트는 있는데 sha256 이 files.sha256 과 다르다 → **변조 의심** (R-13)
 *   ③ 고아 (orphan)          저장소에 있는데 DB 가 모르는 파일이다            → 침해·배치 실패 흔적
 *
 * 세지만 불일치로 세지 않는 것 — `trash_missing`
 *   논리 삭제(휴지통)됐지만 아직 purged_at 이 없는 행에 바이트가 없는 경우다. 30일 복구 여지가
 *   사라진 것이므로 **보고는 하되** 서비스 정합성(복구 성공 판정)에는 넣지 않는다. 이 저장소에는
 *   6~8단계 시험에서 디스크를 비우려고 사람이 지운 큰 시험 파일이 이 상태로 남아 있다(2026-09-11
 *   실측 20건). 이것을 불일치로 세면 `--strict` 가 영원히 실패해 복구 판정에 쓸 수 없다.
 *
 * 고아 판정에 purged_at 을 함께 본다: 행이 있어도 `purged_at` 이 찍혔으면 "지웠어야 할 바이트" 이므로
 * 저장소에 남아 있는 것은 고아다 (purge_trash 가 중간에 죽은 흔적).
 *
 * 격리된 감염 파일 — F-69 C (2026-09-24, PRD F-54 ②)
 *   scan_pending.php 는 감염 파일을 저장소에서 **격리 폴더**(cli_quarantine_dir() — 저장소의 형제
 *   `quarantine/`)로 **옮긴다**(삭제 아님). 전에는 이 검사가 저장소만 봐서, 살아 있는 `infected` 행을
 *   「저장소에 없음(missing)」으로 세어 매일 02:00 AL-6 오탐을 냈다(운영 3건). 이제 `scan_status` 를 함께 읽는다.
 *     | 행                     | 격리 폴더 | 저장소 | 결과 |
 *     | 살아 있음 + infected   | 있음      | 없음   | 정상 — `quarantined` 로 센다 + sha256 대조(읽기만, 실행 0) |
 *     | 살아 있음 + infected   | —         | 있음   | 불일치 `infected_in_storage` (옮기기 실패 — 감염 바이트가 서비스 폴더에 남음) |
 *     | 살아 있음 + infected   | 없음      | 없음   | 불일치 `quarantine_missing` |
 *     | 휴지통 + infected      | 없음      | —      | 보고만 `trash_quarantine_missing` (휴지통 규칙과 같이) |
 *     | pending · clean        | —         | —      | 지금 규칙 그대로 |
 *   격리 폴더의 바이트가 행의 sha256 과 다르면 `quarantine_hash_mismatch`(격리 중 변조 — 불일치로 센다).
 *   격리 폴더에만 있고 행이 없는 파일은 **보고만**(`quarantine_orphan` 개수) — 격리 폴더는 증거 보관소라 지우지 않는다.
 *   이 검사는 격리 폴더를 **읽기만** 한다(목록·sha256). 옮기거나 지우거나 실행하지 않는다.
 *
 * 기록 (ARCH 7.4 · 7.5)
 *   불일치 0 : `audit.integrity allow`  detail checked · mismatches(0) · quarantined · quarantine_orphan · trash_quarantine_missing
 *   불일치 有: `audit.integrity error mismatch` detail 같은 키 + mismatches(목록 ≤ 50) + `alert.raised AL-6`(origin `system`)
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';

/** detail 에 담을 불일치 목록의 최대 건수 (ARCH 7.5 "최대 50건") */
const MISMATCH_DETAIL_LIMIT = 50;

$argv   = $_SERVER['argv'];
$strict = cli_flag($argv, 'strict');
$quiet  = cli_flag($argv, 'quiet');

$db   = Db::instance();
$base = Storage::baseDir();
$quarantineDir = cli_quarantine_dir();

// ─── [1] DB 쪽: 아직 바이트가 있어야 하거나, 있으면 안 되는 행을 모두 읽는다 ───
//        F-69 C-1 — scan_status 를 함께 읽는다(감염 행은 바이트가 격리 폴더에 있어야 정상이다).
$rows = $db->run(
    'SELECT id, stored_name, original_name, access_level, sha256, size_bytes, scan_status,
            deleted_at IS NULL AS alive, purged_at IS NOT NULL AS purged
       FROM files'
)->fetchAll();

/** @var array<string,array<string,mixed>> stored_name => 행 */
$byStored = [];
foreach ($rows as $r) {
    $byStored[(string) $r['stored_name']] = $r;
}

// Travel media is backed up under storage/memories and must participate in integrity checks.
// UUID-only names cannot collide with the original extension-bearing t-box storage names.
$memoryReady = $db->run("SELECT to_regclass('community.memory_media') IS NOT NULL")->fetchColumn();
if ($memoryReady) {
    $memorySql = <<<'SQL'
        SELECT 0 AS id, m.id::text AS stored_name, m.sha256,
               (p.deleted_at IS NULL) AS alive, false AS purged, 'clean' AS scan_status
          FROM community.memory_media m LEFT JOIN community.memory_posts p ON p.id=m.post_id
        SQL;
    foreach ($db->run($memorySql)->fetchAll() as $memoryRow) {
        $byStored[$memoryRow['stored_name']] = $memoryRow;
    }
}

// ─── [2] 저장소 쪽: 실제 파일 이름을 모두 훑는다 ───
$onDisk = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $fileInfo) {
    /** @var SplFileInfo $fileInfo */
    // Community AI credentials are configuration, not uploaded file records.
    if (str_starts_with($fileInfo->getPathname(), $base . '/private-ai/')) {
        continue;
    }
    if (!$fileInfo->isFile()) {
        continue;
    }
    $onDisk[$fileInfo->getFilename()] = $fileInfo->getPathname();
}

// ─── [2-2] 격리 폴더: 파일 이름만 훑는다 (F-69 C — 읽기만, 한 층. scan_pending 은 `quarantine/<stored_name>` 로 둔다) ───
//          폴더가 없거나 못 읽으면 빈 것으로 본다 — 그러면 살아 있는 감염 행이 전부 `quarantine_missing` 이
//          되어 경보가 난다(조용히 넘어가지 않는다). 출력과 detail 에 그 사실을 함께 남긴다.
$inQuarantine = [];
$quarantineReadable = is_dir($quarantineDir) && is_readable($quarantineDir);
if ($quarantineReadable) {
    foreach (new FilesystemIterator($quarantineDir, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if ($fileInfo->isFile()) {
            $inQuarantine[$fileInfo->getFilename()] = $fileInfo->getPathname();
        }
    }
}

// ─── [3] 대조 ───
$mismatches   = [];      // ①②③ — 불일치로 센다
$trashMissing = [];      // 휴지통 바이트 없음 — 보고만 한다
$trashQuarantineMissing = [];   // 휴지통 + 감염인데 격리 폴더에 없음 — 보고만 한다 (F-69 C)
$quarantined  = 0;       // 살아 있는 감염 행 중 격리 폴더에 제대로 있는 것 — 정상 (F-69 C)
$checked      = 0;

foreach ($byStored as $stored => $r) {
    $alive  = ($r['alive'] === true || $r['alive'] === 't' || $r['alive'] === 1);
    $purged = ($r['purged'] === true || $r['purged'] === 't' || $r['purged'] === 1);
    $exists = isset($onDisk[$stored]);

    if ($purged) {
        // 바이트가 있으면 안 되는 행 — 있으면 아래 [4] 고아 판정에서 잡힌다.
        continue;
    }

    // ── 감염 행 (F-69 C-3) — 바이트가 있어야 할 곳은 저장소가 아니라 격리 폴더다 ──
    if ((string) ($r['scan_status'] ?? '') === 'infected') {
        $inQ = isset($inQuarantine[$stored]);
        if (!$alive) {
            // 휴지통 — 휴지통 규칙과 같이 **보고만** 한다.
            if (!$inQ) {
                $trashQuarantineMissing[] = $stored;
            }
            continue;
        }
        $checked++;
        if ($exists) {
            // 옮기기가 실패했거나 누가 되돌려 놓았다 — 감염 바이트가 서비스 폴더에 있다(격리 폴더에도 있든 없든).
            $mismatches[] = ['kind' => 'infected_in_storage', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
            continue;
        }
        if (!$inQ) {
            $mismatches[] = ['kind' => 'quarantine_missing', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
            continue;
        }
        // 정상 격리. 격리 중 변조가 없었나 sha256 만 대조한다 — 바이트를 **읽기만** 하고 열거나 실행하지 않는다.
        $actualQ = hash_file('sha256', $inQuarantine[$stored]);
        if ($actualQ === false) {
            $mismatches[] = ['kind' => 'quarantine_unreadable', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
            continue;
        }
        if (!hash_equals((string) $r['sha256'], $actualQ)) {
            $mismatches[] = ['kind' => 'quarantine_hash_mismatch', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
            continue;
        }
        $quarantined++;
        continue;
    }

    if ($alive) {
        $checked++;
        if (!$exists) {
            $mismatches[] = ['kind' => 'missing', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
            continue;
        }
        // sha256 대조 — 바이트가 기록과 같은지. 다르면 변조 의심이다 (R-13, AL-6).
        $actual = hash_file('sha256', $onDisk[$stored]);
        if ($actual === false) {
            $mismatches[] = ['kind' => 'unreadable', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
            continue;
        }
        if (!hash_equals((string) $r['sha256'], $actual)) {
            $mismatches[] = ['kind' => 'hash_mismatch', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
        }
        continue;
    }

    // 휴지통(논리 삭제, 아직 미정리) — 바이트가 있어야 정상이지만 불일치로 세지 않는다(위 주석).
    if (!$exists) {
        $trashMissing[] = $stored;
    }
}

// ─── [4] 고아 — 저장소에 있는데 DB 가 모르거나, 이미 purge 했다고 기록된 파일 ───
foreach ($onDisk as $stored => $path) {
    $checked++;
    if (!isset($byStored[$stored])) {
        $mismatches[] = ['kind' => 'orphan', 'stored_name' => $stored, 'file_id' => null];
        continue;
    }
    $r = $byStored[$stored];
    if ($r['purged'] === true || $r['purged'] === 't' || $r['purged'] === 1) {
        $mismatches[] = ['kind' => 'orphan_purged', 'stored_name' => $stored, 'file_id' => (int) $r['id']];
    }
}

// ─── [4-2] 격리 폴더의 고아 — 행이 없는 격리 파일은 **보고만** (F-69 C-4, 증거 보관소라 지우지 않는다) ───
$quarantineOrphan = 0;
foreach ($inQuarantine as $stored => $path) {
    if (!isset($byStored[$stored])) {
        $quarantineOrphan++;
    }
}

// ─── [5] 사람이 읽는 출력 ───
if (!$quiet) {
    cli_say(sprintf(
        '정합성 검사: DB 행 %d개 / 저장소 파일 %d개 / 검사 %d건',
        count($byStored),
        count($onDisk),
        $checked
    ));
    cli_say(sprintf('  불일치 %d건, 휴지통 바이트 없음 %d건(보고만)', count($mismatches), count($trashMissing)));
    cli_say(sprintf(
        '  격리 폴더: 정상 격리 %d건 · 휴지통 감염 격리 없음 %d건(보고만) · 행 없는 격리 파일 %d건(보고만)%s',
        $quarantined,
        count($trashQuarantineMissing),
        $quarantineOrphan,
        $quarantineReadable ? '' : ' — 격리 폴더를 읽지 못했다(없거나 권한 없음)'
    ));
    foreach (array_slice($mismatches, 0, MISMATCH_DETAIL_LIMIT) as $m) {
        cli_say(sprintf('  [%s] %s (file_id=%s)', $m['kind'], $m['stored_name'], $m['file_id'] ?? '-'));
    }
    if (count($mismatches) > MISMATCH_DETAIL_LIMIT) {
        cli_say(sprintf('  … 외 %d건', count($mismatches) - MISMATCH_DETAIL_LIMIT));
    }
}

// ─── [6] 기록 ───
$detail = [
    'checked'       => $checked,
    'mismatches'    => count($mismatches),
    'db_rows'       => count($byStored),
    'disk_files'    => count($onDisk),
    'trash_missing' => count($trashMissing),
    // F-69 C-5 — 격리 폴더 (ARCH 7.5 갱신 대상). 개수만 — 파일 이름은 불일치 목록에만 있다.
    'quarantined'              => $quarantined,
    'quarantine_orphan'        => $quarantineOrphan,
    'trash_quarantine_missing' => count($trashQuarantineMissing),
    'quarantine_readable'      => $quarantineReadable,
];

if ($mismatches === []) {
    Audit::allow('audit.integrity', [
        'target_type' => 'system',
        'target_name' => 'consistency',
        'detail'      => $detail,
    ]);
    cli_say('결과: 통과 (불일치 0건)');
    exit(TBOX_CLI_OK);
}

$detail['mismatches'] = array_slice($mismatches, 0, MISMATCH_DETAIL_LIMIT);
$detail['mismatch_count'] = count($mismatches);

Audit::error('audit.integrity', 'mismatch', [
    'target_type' => 'system',
    'target_name' => 'consistency',
    'detail'      => $detail,
]);

// AL-6 — "정합성 검사에서 sha256 불일치·고아 파일" (PRD 7.11) · origin `system`(행위자 없는 배치 — F-69 A-1)
cli_raise_alert('AL-6', [
    'rule_text'      => '정합성 검사에서 누락·sha256 불일치·고아 파일 발견',
    'source'         => 'consistency_check',
    'mismatch_count' => count($mismatches),
    'kinds'          => array_count_values(array_column($mismatches, 'kind')),
    'sample'         => array_slice(array_column($mismatches, 'stored_name'), 0, 10),
], Origin::SYSTEM);

cli_warn(sprintf('결과: 불일치 %d건 — 경보 AL-6 을 남겼다', count($mismatches)));
exit($strict ? TBOX_CLI_MISMATCH : TBOX_CLI_OK);
