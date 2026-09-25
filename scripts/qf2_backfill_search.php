<?php
declare(strict_types=1);

/**
 * scripts/qf2_backfill_search.php — 검색 색인을 새 규칙으로 다시 채운다 (사이트 PRD Q:F-2, 지시서 6장 · QF2-T7)
 *
 * 사용법
 *   php scripts/qf2_backfill_search.php --dry-run    ← 바꿀 행 수만 센다(쓰기 0)
 *   php scripts/qf2_backfill_search.php              ← 실제로 다시 채운다
 *   php scripts/qf2_backfill_search.php --only pages|comments
 *
 * 하는 일
 *   페이지(살아 있는 것·휴지통 것 모두 — 되살리면 곧바로 찾혀야 한다)와 댓글의 `search_tsv` 를 `SearchText` 규칙
 *   (한글 두 글자 조각 + 한 글자, 태그 이름 포함 — 앱이 저장할 때 쓰는 **같은 함수** `community_search_text`)으로
 *   만든 값과 견주어 **다른 행만** 고친다. 묶음 500행(id 순), 묶음마다 트랜잭션 하나.
 *   · 견줄 때 읽은 제목·본문·태그가 그 사이 바뀐 행(누가 저장했다)은 건너뛴다 — 그 저장이 이미 새 규칙으로 썼다.
 *     그래서 운영 중에 돌려도 새 글을 옛 글로 덮지 않는다(행마다 읽은 값의 md5 를 UPDATE 조건에 다시 건다).
 *     읽은 **벡터**의 md5 도 함께 건다 — 태그 고치기가 커밋되기 직전에 이 UPDATE 가 행 잠금을 기다렸다 들어가면,
 *     다시 보는 조건의 태그 하위 질의는 옛 태그를 보지만 벡터는 이미 새 값이라 여기서 걸러진다(옛 태그로 덮지 않는다).
 *   · `updated_at` 은 건드리지 않는다(「최근 고친 순」이 흔들리지 않게). 감사 기록 없음(파생값 — 사람의 행동이 아니다).
 *   · 값은 전부 바인딩(S-04). 묶음의 새 값은 JSON 한 덩어리(`jsonb_to_recordset`)로 보낸다 — SQL 을 잇지 않는다.
 *
 * 실행 계정: 앱과 같은 DB 계정(.env 의 tbox_app — pages·comments 에 UPDATE 가 있다). root 가 아니어도 된다(.env 를 읽을 수 있으면).
 * 끝나면 한 줄: `pages: 본 N · 바꿀(바꾼) M · 건너뜀 K` · `comments: …`. 종료 코드 0(정상) · 1(오류) · 3(사용법).
 */

require_once __DIR__ . '/lib/cli_bootstrap.php';
require_once dirname(__DIR__) . '/src/community/routes.php';

$argv = $_SERVER['argv'];
$dry  = cli_flag($argv, 'dry-run');
$only = (string) cli_option($argv, 'only', 'all');
if (!in_array($only, ['all', 'pages', 'comments'], true)) {
    cli_warn('[사용법 오류] --only 는 pages · comments');
    exit(TBOX_CLI_BAD_USAGE);
}
const QF2_BATCH = 500;

$db = Db::instance();

/**
 * 한 종류를 다시 채운다. $select 는 `id > :after` 부터 500행, 각 행에 id · sig(md5) · 원료 칸.
 * $make 는 행 → 색인 글자. 나머지 세 문장은 JSON 묶음(:pack)을 받는다 —
 *   $count  sig 그대로 + 벡터가 다른 행 수(dry-run 의 「바꿀 행」)
 *   $stale  sig 가 달라진 행 수(그 사이 누가 저장했다 — 「건너뜀」)
 *   $update sig 그대로 + 벡터가 다른 행만 고친다
 *
 * @return array{seen:int,changed:int,skipped:int}
 */
$run = static function (string $select, string $count, string $stale, string $update, callable $make) use ($db, $dry): array {
    $seen = 0; $changed = 0; $skipped = 0; $after = 0;
    for (;;) {
        $rows = $db->run($select, [':after' => $after, ':lim' => QF2_BATCH])->fetchAll();
        if ($rows === []) {
            break;
        }
        $pack = [];
        foreach ($rows as $r) {
            $pack[] = ['id' => (int) $r['id'], 'sig' => (string) $r['sig'], 'vsig' => (string) $r['vsig'], 't' => $make($r)];
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
    return ['seen' => $seen, 'changed' => $changed, 'skipped' => $skipped];
};

$out = [];

if ($only === 'all' || $only === 'pages') {
    $sig = "md5(p.title || E'\\x01' || p.plain_text || E'\\x01' || coalesce((SELECT string_agg(t.tag, E'\\x01' ORDER BY t.tag_norm) FROM community.page_tags t WHERE t.page_id = p.id), ''))";
    $select = <<<SQL
        SELECT p.id, p.title, p.plain_text,
               coalesce((SELECT json_agg(t.tag ORDER BY t.tag_norm) FROM community.page_tags t WHERE t.page_id = p.id), '[]') AS tags,
               {$sig} AS sig, md5(coalesce(p.search_tsv::text, '')) AS vsig
          FROM community.pages p
         WHERE p.id > CAST(:after AS bigint)
         ORDER BY p.id
         LIMIT CAST(:lim AS int)
        SQL;
    $count = <<<SQL
        SELECT count(*) FROM community.pages p
          JOIN jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text) ON v.id = p.id
         WHERE {$sig} = v.sig
           AND p.search_tsv IS DISTINCT FROM strip(to_tsvector('simple', v.t))
        SQL;
    $stale = <<<SQL
        SELECT count(*) FROM community.pages p
          JOIN jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text) ON v.id = p.id
         WHERE {$sig} <> v.sig
        SQL;
    $update = <<<SQL
        UPDATE community.pages p
           SET search_tsv = strip(to_tsvector('simple', v.t))
          FROM jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text)
         WHERE p.id = v.id
           AND {$sig} = v.sig
           AND md5(coalesce(p.search_tsv::text, '')) = v.vsig
           AND p.search_tsv IS DISTINCT FROM strip(to_tsvector('simple', v.t))
        SQL;
    $out['pages'] = $run($select, $count, $stale, $update, static function (array $r): string {
        $tags = json_decode((string) $r['tags'], true);
        return community_search_text((string) $r['title'], (string) $r['plain_text'],
            is_array($tags) ? array_map('strval', $tags) : [], 'page ' . (int) $r['id']);
    });
}

if ($only === 'all' || $only === 'comments') {
    $sig = 'md5(c.body)';
    $select = <<<SQL
        SELECT c.id, c.body, {$sig} AS sig, md5(coalesce(c.search_tsv::text, '')) AS vsig
          FROM community.comments c
         WHERE c.id > CAST(:after AS bigint)
         ORDER BY c.id
         LIMIT CAST(:lim AS int)
        SQL;
    $count = <<<SQL
        SELECT count(*) FROM community.comments c
          JOIN jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text) ON v.id = c.id
         WHERE {$sig} = v.sig
           AND c.search_tsv IS DISTINCT FROM strip(to_tsvector('simple', v.t))
        SQL;
    $stale = <<<SQL
        SELECT count(*) FROM community.comments c
          JOIN jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text) ON v.id = c.id
         WHERE {$sig} <> v.sig
        SQL;
    $update = <<<SQL
        UPDATE community.comments c
           SET search_tsv = strip(to_tsvector('simple', v.t))
          FROM jsonb_to_recordset(CAST(:pack AS jsonb)) AS v(id bigint, sig text, vsig text, t text)
         WHERE c.id = v.id
           AND {$sig} = v.sig
           AND md5(coalesce(c.search_tsv::text, '')) = v.vsig
           AND c.search_tsv IS DISTINCT FROM strip(to_tsvector('simple', v.t))
        SQL;
    $out['comments'] = $run($select, $count, $stale, $update, static function (array $r): string {
        return SearchText::indexText([(string) $r['body']], 'comment ' . (int) $r['id']);
    });
}

foreach ($out as $what => $r) {
    cli_say(sprintf('%s%s: 본 %d · %s %d · 건너뜀 %d',
        $dry ? '[dry] ' : '', $what, $r['seen'], $dry ? '바꿀' : '바꾼', $r['changed'], $r['skipped']));
}
exit(TBOX_CLI_OK);
