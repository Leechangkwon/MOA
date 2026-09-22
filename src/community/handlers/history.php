<?php
declare(strict_types=1);

/**
 * src/community/handlers/history.php — GET /community/api/pages/{id}/history
 *                                      (정책: community.read)
 *
 * 요구사항: `C:F-19`·`C:F-20`(체크 이력)·`C:S-14`, `../../../../community/ARCHITECTURE.md` 16장,
 *           수락 기준 C:T-41 · C:T-42 · C:T-44.
 *
 * 「누가 언제 무엇을 바꿨나」를 **시간순 한 줄씩** 돌려준다. 줄의 종류는 둘이다.
 *   `version`  판이 하나 생겼다 — 판 번호 · 제목 · 사람 · 시각 · 블록 수 (되돌리기면 `restored_from`)
 *   `check`    체크 상자를 켜거나 껐다 — 블록 id · 켜짐/꺼짐 · 사람 · 시각
 *
 * ★ **감사 로그를 읽지 않는다** (지시서 「핸들러」). 이 창구가 보는 것은 우리 표 둘
 *   (`page_versions`·`checklist_events`)과 `pages` 의 지금 판뿐이다. 감사 로그는 관리자의
 *   기록이지 페이지 주인의 화면이 아니고, 그것을 여기서 열면 열람 통로가 하나 더 생긴다.
 *
 * ★ **본문 글자가 한 자도 나가지 않는다** (C:T-44). 질의가 `doc` 을 고르지 않고, 체크 줄에는
 *   할 일의 **글자 대신 블록 id** 만 싣는다 — 「어느 칸인가」는 id 로 충분하고, 「무엇이라고
 *   적혀 있나」는 본문을 볼 수 있는 사람이 본문에서 볼 일이다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$gate = community_history_gate($user, (string) $params[0]);
$row  = $gate['row'];

/** 한 번에 보이는 줄 수. 넘치면 오래된 것부터 잘린다(최근이 위다). */
$limit = 200;

/* 두 표를 **한 질의**로 모은다 — 시간순 정렬을 DB 가 한다. 칸 수를 맞추려고 쓰지 않는 칸에는
   타입을 적은 NULL 을 둔다(`UNION ALL` 은 두 갈래의 타입이 같아야 한다). */
$sql = <<<'SQL'
    SELECT 'version'                        AS kind,
           v.version                        AS version,
           v.title                          AS title,
           v.edited_at                      AS at,
           u.username                       AS by_name,
           NULL::uuid                       AS block_id,
           NULL::boolean                    AS checked,
           v.summary                        AS summary,
           CASE WHEN jsonb_typeof(v.doc -> 'blocks') = 'array'
                THEN jsonb_array_length(v.doc -> 'blocks') ELSE 0 END AS block_count
      FROM community.page_versions v
      JOIN public.users u ON u.id = v.edited_by
     WHERE v.page_id = CAST(:pid AS bigint)
    UNION ALL
    SELECT 'check', NULL::int, NULL::text, e.at, cu.username, e.block_id, e.checked, NULL::text, NULL::int
      FROM community.checklist_events e
      JOIN public.users cu ON cu.id = e.by_user_id
     WHERE e.page_id = CAST(:pid2 AS bigint)
     ORDER BY at DESC
     LIMIT CAST(:lim AS int)
    SQL;

$rows = Db::instance()->run($sql, [
    ':pid'  => (int) $row['id'],
    ':pid2' => (int) $row['id'],
    ':lim'  => $limit,
])->fetchAll();

/* 되돌리기 표시를 **다음 판**의 줄로 옮긴다 (`versions_list.php` 머리글 ★ 와 같은 규칙). */
$restoredInto = [];
foreach ($rows as $r) {
    if ((string) $r['kind'] !== 'version') {
        continue;
    }
    $from = community_restore_source($r['summary'] === null ? null : (string) $r['summary']);
    if ($from !== null) {
        $restoredInto[(int) $r['version'] + 1] = $from;
    }
}

$events = [];

// 지금 판 — `pages` 에 있고 `page_versions` 에는 없다(아직 교체되지 않았다).
$events[] = [
    'kind'          => 'version',
    'version'       => (int) $row['doc_version'],
    'is_current'    => true,
    'title'         => (string) $row['title'],
    'by'            => $row['last_edited_by_name'] === null ? '' : (string) $row['last_edited_by_name'],
    'at'            => (string) $row['updated_at'],
    'block_count'   => (int) $row['block_count'],
    'restored_from' => $restoredInto[(int) $row['doc_version']] ?? null,
];

foreach ($rows as $r) {
    if ((string) $r['kind'] === 'version') {
        $events[] = [
            'kind'          => 'version',
            'version'       => (int) $r['version'],
            'is_current'    => false,
            'title'         => (string) $r['title'],
            'by'            => (string) $r['by_name'],
            'at'            => (string) $r['at'],
            'block_count'   => (int) $r['block_count'],
            'restored_from' => $restoredInto[(int) $r['version']] ?? null,
        ];
        continue;
    }
    $events[] = [
        'kind'     => 'check',
        'block_id' => (string) $r['block_id'],
        'checked'  => $r['checked'] === true,
        'by'       => (string) $r['by_name'],
        'at'       => (string) $r['at'],
    ];
}

/* 지금 판 한 줄을 끼워 넣었으므로 시간순을 한 번 더 맞춘다. PHP 8 의 정렬은 **안정**이라
   같은 시각의 줄들은 위 순서(판 → 체크)를 그대로 지킨다. */
usort($events, static function (array $a, array $b): int {
    $ta = strtotime((string) $a['at']);
    $tb = strtotime((string) $b['at']);
    if ($ta === false || $tb === false || $ta === $tb) {
        return 0;
    }
    return $tb <=> $ta;
});

if (count($events) > $limit) {
    $events = array_slice($events, 0, $limit);
}

Http::json([
    'page' => [
        'id'          => (string) $row['public_id'],
        'title'       => (string) $row['title'],
        'doc_version' => (int) $row['doc_version'],
    ],
    'events' => $events,
    'total'  => count($events),
    'limit'  => $limit,
]);
