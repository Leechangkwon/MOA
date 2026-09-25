<?php
declare(strict_types=1);

/**
 * scripts/qf2b_backfill_files.php — 파일 검색 색인(files.search_tsv)을 채운다 (개발 대기열 5b · Q:F-2 J-2 후속)
 *
 * 사용법
 *   php scripts/qf2b_backfill_files.php --dry-run    ← 바꿀 행 수만 센다(쓰기 0)
 *   php scripts/qf2b_backfill_files.php              ← 실제로 채운다
 *
 * 하는 일
 *   모든 파일 행(살아 있는 것·휴지통 것 모두 — 되살리면 곧바로 찾혀야 한다)의 `search_tsv` 를 앱이 저장할 때와
 *   **같은 규칙**(`SearchText::indexTextFile([이름, 설명])` — 업로드·URL 가져오기·설명 고치기가 부르는 그 한 줄)으로 만든
 *   값과 견주어 **다른 행만** 고친다. 묶음 500행(id 순), 묶음마다 트랜잭션 하나. scripts/qf2_backfill_search.php 와 같은 틀.
 *   · 견줄 때 읽은 이름·설명이 그 사이 바뀐 행(누가 설명을 고쳤다)은 건너뛴다 — 그 저장이 이미 새 규칙으로 썼다.
 *     읽은 값의 md5 와 읽은 **벡터**의 md5 를 UPDATE 조건에 다시 건다(운영 중에 돌려도 새 값을 옛 값으로 덮지 않는다).
 *   · 다른 칸(`created_at` 등)은 건드리지 않는다. 감사 기록 없음(파생값 — 사람의 행동이 아니다).
 *   · 값은 전부 바인딩(S-04). 묶음의 새 값은 JSON 한 덩어리(`jsonb_to_recordset`)로 보낸다 — SQL 을 잇지 않는다.
 *   · 목록 창구는 채우기 전 행(NULL)도 `OR search_tsv IS NULL` 로 찾으므로, 이 배치가 끝나기 전에도 검색에서 빠지는
 *     파일은 없다 — 이 배치는 속도(색인으로 좁히기)를 되찾는 일이다.
 *
 * 실행 계정: 앱과 같은 DB 계정(.env 의 tbox_app — files 에 UPDATE 가 있다). root 가 아니어도 된다(.env 를 읽을 수 있으면).
 * 끝나면 한 줄: `files: 본 N · 바꿀(바꾼) M · 건너뜀 K`. 종료 코드 0(정상) · 1(오류) · 3(사용법).
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';
require_once dirname(__DIR__) . '/src/SearchText.php';

$argv = $_SERVER['argv'];
$dry  = cli_flag($argv, 'dry-run');
foreach (array_slice($argv, 1) as $a) {
    if ($a !== '--dry-run') {
        cli_warn('[사용법 오류] 인자는 --dry-run 하나뿐');
        exit(TBOX_CLI_BAD_USAGE);
    }
}
const QF2B_BATCH = 500;

$db  = Db::instance();
$sig = "md5(f.original_name || E'\\x01' || f.description)";

$select = <<<SQL
    SELECT f.id, f.original_name, f.description, {$sig} AS sig, md5(coalesce(f.search_tsv::text, '')) AS vsig
      FROM files f
     WHERE f.id > CAST(:after AS bigint)
     ORDER BY f.id
     LIMIT CAST(:lim AS int)
    SQL;
$count = <<<SQL
    SELECT count(*) FROM files f
      JOIN jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text) ON v.id = f.id
     WHERE {$sig} = v.sig
       AND f.search_tsv IS DISTINCT FROM strip(to_tsvector('simple', v.t))
    SQL;
$stale = <<<SQL
    SELECT count(*) FROM files f
      JOIN jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text) ON v.id = f.id
     WHERE {$sig} <> v.sig
    SQL;
$update = <<<SQL
    UPDATE files f
       SET search_tsv = strip(to_tsvector('simple', v.t))
      FROM jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text)
     WHERE f.id = v.id
       AND {$sig} = v.sig
       AND md5(coalesce(f.search_tsv::text, '')) = v.vsig
       AND f.search_tsv IS DISTINCT FROM strip(to_tsvector('simple', v.t))
    SQL;

$seen = 0; $changed = 0; $skipped = 0; $after = 0;
for (;;) {
    $rows = $db->run($select, [':after' => $after, ':lim' => QF2B_BATCH])->fetchAll();
    if ($rows === []) {
        break;
    }
    $pack = [];
    foreach ($rows as $r) {
        $pack[] = [
            'id'   => (int) $r['id'],
            'sig'  => (string) $r['sig'],
            'vsig' => (string) $r['vsig'],
            // 앱이 저장할 때와 같은 한 줄(_upload_common · files_fetch · files_update)
            't'    => SearchText::indexTextFile([(string) $r['original_name'], (string) $r['description']], 'file ' . (int) $r['id']),
        ];
        $after = (int) $r['id'];
    }
    $json = json_encode($pack, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('묶음을 JSON 으로 만들지 못했다');
    }
    $seen += count($rows);
    if ($dry) {
        $changed += (int) $db->run($count, [':pack' => $json])->fetchColumn();
        $skipped += (int) $db->run($stale, [':pack' => $json])->fetchColumn();
        continue;
    }
    $db->begin();
    try {
        $n = $db->run($update, [':pack' => $json])->rowCount();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $changed += $n;
    $skipped += (int) $db->run($stale, [':pack' => $json])->fetchColumn();
}

cli_say(sprintf('%sfiles: 본 %d · %s %d · 건너뜀 %d', $dry ? '[dry] ' : '', $seen, $dry ? '바꿀' : '바꾼', $changed, $skipped));
exit(TBOX_CLI_OK);
