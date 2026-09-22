<?php
declare(strict_types=1);

/**
 * src/community/handlers/versions_list.php — GET /community/api/pages/{id}/versions
 *                                            (정책: community.read)
 *
 * 요구사항: `C:F-19`(버전 이력)·`C:S-14`(기록 불변), 커뮤니티 `PRD.md` F-19,
 *           `../../../../community/ARCHITECTURE.md` 16장, 수락 기준 C:T-38 · C:T-42 · C:T-44.
 *
 * 돌려주는 것 — **판 번호 · 제목 · 바꾼 사람 · 시각 · 블록 수**뿐이다. 최근 50판.
 *
 * ★ **본문 글자가 한 자도 나가지 않는다** (C:T-44). 질의가 `doc` 을 고르지 않고, 블록 수는
 *   DB 가 `jsonb_array_length(doc->'blocks')` 로 **세어서** 숫자만 준다. 본문은 오직
 *   `version_get.php` 로만 나가고, 그것도 읽기 판정을 지난 뒤다.
 *   (4단계 문서는 평평하다 — 블록 안에 블록을 두는 `toggle`·`column` 은 5단계다. 그래서
 *    맨 윗줄을 센 값이 `pages.block_count` 와 같은 뜻이다.)
 *
 * ★ 목록에는 **지금 판**이 맨 위에 함께 온다(`is_current: true`). 지금 판은 `pages` 에 있고
 *   `page_versions` 에는 없다 — 아직 교체되지 않았기 때문이다. 화면이 「판 7 (지금)」을
 *   보이려면 그 한 줄이 필요하고, 그 줄을 화면이 지어내게 두지 않는다.
 *
 * ★ 되돌리기 표시(`restored_from`)가 붙는 자리 — 스냅샷의 `summary` 는 「이 판이 어떻게
 *   끝났나」다(`restore from v3` = 이 판 다음에 판 3 의 내용으로 되돌렸다). 그래서 화면에
 *   보일 때는 **그 다음 판**의 줄에 붙는다. 되돌리기로 만들어진 것은 다음 판이기 때문이다.
 *
 * 검사 순서
 *   [1] 행+공간 → 404  →  [권한 ✔] `decidePageRead`  (둘 다 `community_history_gate`)  →  [2] 목록
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$gate = community_history_gate($user, (string) $params[0]);
$row  = $gate['row'];

/** 최근 몇 판까지 보이나 (지시서 「versions_list: … 최근 50」). */
$limit = 50;

$sql = <<<'SQL'
    SELECT v.version,
           v.title,
           v.edited_at,
           v.summary,
           u.username AS edited_by_name,
           CASE WHEN jsonb_typeof(v.doc -> 'blocks') = 'array'
                THEN jsonb_array_length(v.doc -> 'blocks') ELSE 0 END AS block_count
      FROM community.page_versions v
      JOIN public.users u ON u.id = v.edited_by
     WHERE v.page_id = CAST(:pid AS bigint)
     ORDER BY v.version DESC
     LIMIT CAST(:lim AS int)
    SQL;

$rows = Db::instance()->run($sql, [':pid' => (int) $row['id'], ':lim' => $limit])->fetchAll();

/* `summary` 를 **다음 판**으로 옮겨 붙인다 (머리글 ★). 판 번호를 열쇠로 쓰므로 5분 합치기로
   스냅샷이 없는 번호가 있어도 엉뚱한 줄에 붙지 않는다 — 없으면 그냥 붙지 않는다. */
$restoredInto = [];
foreach ($rows as $r) {
    $from = community_restore_source($r['summary'] === null ? null : (string) $r['summary']);
    if ($from !== null) {
        $restoredInto[(int) $r['version'] + 1] = $from;
    }
}

// 지금 판 — `pages` 에서 온다. 맨 윗줄이고 되돌릴 대상이 아니다(이미 지금 내용이다).
$versions = [[
    'version'       => (int) $row['doc_version'],
    'is_current'    => true,
    'title'         => (string) $row['title'],
    'by'            => $row['last_edited_by_name'] === null ? '' : (string) $row['last_edited_by_name'],
    'at'            => (string) $row['updated_at'],
    'block_count'   => (int) $row['block_count'],
    'restored_from' => $restoredInto[(int) $row['doc_version']] ?? null,
]];

foreach ($rows as $r) {
    $versions[] = [
        'version'       => (int) $r['version'],
        'is_current'    => false,
        'title'         => (string) $r['title'],
        'by'            => (string) $r['edited_by_name'],
        'at'            => (string) $r['edited_at'],
        'block_count'   => (int) $r['block_count'],
        'restored_from' => $restoredInto[(int) $r['version']] ?? null,
    ];
}

Http::json([
    'page' => [
        'id'          => (string) $row['public_id'],
        'title'       => (string) $row['title'],
        'doc_version' => (int) $row['doc_version'],
    ],
    'versions' => $versions,
    // 목록에 담긴 줄 수다(스냅샷 총수가 아니라 「보여 준 것」 — 화면의 「n판」이 이 값이다).
    'total'    => count($versions),
    'limit'    => $limit,
    // 화면의 「되돌리기」 단추를 열지 말지 고르는 값이다 — 판단 근거가 아니다 (DESIGN 원칙 1).
    // 단추를 되살려 창구를 불러도 `version_restore.php` 가 같은 함수로 다시 막는다.
    'can_edit' => $gate['may_change'],
]);
