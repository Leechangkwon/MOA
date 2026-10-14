<?php
declare(strict_types=1);

/**
 * src/community/handlers/history.php — GET /community/api/pages/{id}/history
 *                                      (정책: community.read)
 *
 * 요구사항: `C:F-19`·`C:F-20`(체크 이력)·`C:S-14`, `../../../../community/ARCHITECTURE.md` 16장,
 *           수락 기준 C:T-41 · C:T-42 · C:T-44.
 *
 * 「누가 언제 무엇을 바꿨나」를 **시간순 한 줄씩** 돌려준다. 줄의 종류는 셋이다.
 *   `version`  판이 하나 생겼다 — 판 번호 · 제목 · 사람 · 시각 · 블록 수 (되돌리기면 `restored_from`)
 *   `check`    체크 상자를 켜거나 껐다 — 블록 id · 켜짐/꺼짐 · 사람 · 시각
 *   `property` 상태·담당자를 바꿨다 (4-D) — 어느 칸 · 이전값 → 새값 · 사람 · 시각
 *   `handover` 이 페이지(와 하위)를 넘겼다 (5-A) — 누구에서 누구에게 · 몇 쪽 · 옛 주인에게 남긴 권한
 *
 * ★ **감사 로그를 읽지 않는다** (지시서 「핸들러」). 이 창구가 보는 것은 우리 표 셋
 *   (`page_versions`·`checklist_events`·`page_property_events`·`handovers`)과 `pages` 의 지금 판뿐이다. 감사 로그는 관리자의
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

/* 세 표를 **한 질의**로 모은다 — 시간순 정렬을 DB 가 한다. 칸 수를 맞추려고 쓰지 않는 칸에는
   타입을 적은 NULL 을 둔다(`UNION ALL` 은 갈래들의 타입이 같아야 한다). */
$sql = <<<'SQL'
    SELECT 'version'                        AS kind,
           v.version                        AS version,
           v.title                          AS title,
           v.edited_at                      AS at,
           u.username                       AS by_name,
           u.display_name                   AS by_display,
           NULL::uuid                       AS block_id,
           NULL::boolean                    AS checked,
           v.summary                        AS summary,
           CASE WHEN jsonb_typeof(v.doc -> 'blocks') = 'array'
                THEN jsonb_array_length(v.doc -> 'blocks') ELSE 0 END AS block_count,
           NULL::text                       AS prop,
           NULL::text                       AS from_value,
           NULL::text                       AS to_value,
           NULL::text                       AS from_display,
           NULL::text                       AS to_display,
           v.edited_by                      AS by_id
      FROM community.page_versions v
      JOIN public.users u ON u.id = v.edited_by
     WHERE v.page_id = CAST(:pid AS bigint)
       AND v.edited_at >= coalesce(CAST(:since1 AS timestamptz), '-infinity'::timestamptz)
    UNION ALL
    SELECT 'check', NULL::int, NULL::text, e.at, cu.username, cu.display_name, e.block_id, e.checked, NULL::text, NULL::int,
           NULL::text, NULL::text, NULL::text, NULL::text, NULL::text, e.by_user_id
      FROM community.checklist_events e
      JOIN public.users cu ON cu.id = e.by_user_id
     WHERE e.page_id = CAST(:pid2 AS bigint)
       AND e.at >= coalesce(CAST(:since2 AS timestamptz), '-infinity'::timestamptz)
    UNION ALL
    SELECT 'property', NULL::int, NULL::text, pe.at, pu.username, pu.display_name, NULL::uuid, NULL::boolean, NULL::text, NULL::int,
           pe.prop, pe.from_value, pe.to_value, NULL::text, NULL::text, pe.by_user_id
      FROM community.page_property_events pe
      JOIN public.users pu ON pu.id = pe.by_user_id
     WHERE pe.page_id = CAST(:pid3 AS bigint)
       AND pe.at >= coalesce(CAST(:since3 AS timestamptz), '-infinity'::timestamptz)
    UNION ALL
    -- 5-A 인계 — 이 페이지를 뿌리로 넘긴 일. 누가·누구에서 누구에게·몇 쪽·남긴 권한(`summary` 칸).
    SELECT 'handover', NULL::int, NULL::text, h.at, hb.username, hb.display_name, NULL::uuid, NULL::boolean, h.kept_level,
           h.page_count, NULL::text, hf.username, ht.username, hf.display_name, ht.display_name, h.by_user_id
      FROM community.handovers h
      JOIN public.users hb ON hb.id = h.by_user_id
      JOIN public.users hf ON hf.id = h.from_user_id
      JOIN public.users ht ON ht.id = h.to_user_id
     WHERE h.root_page_id = CAST(:pid4 AS bigint)
       AND h.at >= coalesce(CAST(:since4 AS timestamptz), '-infinity'::timestamptz)
     ORDER BY at DESC
     LIMIT CAST(:lim AS int)
    SQL;

$rows = Db::instance()->run($sql, [
    ':pid'  => (int) $row['id'],
    ':pid2' => (int) $row['id'],
    ':pid3' => (int) $row['id'],
    ':pid4' => (int) $row['id'],
    // 5-C — 게시판 등급으로만 보는 사람에게는 게시 뒤 기록만(`community_history_gate` 의 since, USER-CHECKS 5-C #9)
    ':since1' => $gate['since'], ':since2' => $gate['since'], ':since3' => $gate['since'], ':since4' => $gate['since'],
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

/** 5-A″ — 이름 칸(없으면 null). 계정명(`by`·`from`·`to`)과 **따로** 나간다 — 잇는 일은 화면이 한다. */
$dn = static function ($v): ?string {
    return $v === null ? null : (string) $v;
};

// 지금 판 — `pages` 에 있고 `page_versions` 에는 없다(아직 교체되지 않았다).
$events[] = [
    'kind'          => 'version',
    'version'       => (int) $row['doc_version'],
    'is_current'    => true,
    'title'         => (string) $row['title'],
    'by'            => $row['last_edited_by_name'] === null ? '' : (string) $row['last_edited_by_name'],
    // 5-A″ — 이름은 따로(없으면 null). 「이름 (아이디)」는 화면이 만든다.
    'by_display_name' => $row['last_edited_by_display_name'] === null ? null : (string) $row['last_edited_by_display_name'],
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
            'by_display_name' => $dn($r['by_display']),
            'at'            => (string) $r['at'],
            'block_count'   => (int) $r['block_count'],
            'restored_from' => $restoredInto[(int) $r['version']] ?? null,
        ];
        continue;
    }
    if ((string) $r['kind'] === 'property') {
        /* 4-D — 상태·담당자가 바뀐 줄. 값은 **기록에 적힌 글자 그대로**다(상태는 코드값,
           담당자는 계정명). 화면이 한글 라벨로 바꾼다 — 서버가 글자를 지어내지 않는다.
           본문은 여기에도 없다 (C:T-44 와 같은 규칙). */
        $events[] = [
            'kind'  => 'property',
            'prop'  => (string) $r['prop'],
            'from'  => $r['from_value'] === null ? null : (string) $r['from_value'],
            'to'    => $r['to_value'] === null ? null : (string) $r['to_value'],
            'by'    => (string) $r['by_name'],
            'by_display_name' => $dn($r['by_display']),
            'at'    => (string) $r['at'],
        ];
        continue;
    }
    if ((string) $r['kind'] === 'handover') {
        /* 5-A — 인계 한 번. 계정명은 기록에 적힌 그대로이고, 남긴 권한은 `view`·`edit`·null(없음). */
        $events[] = [
            'kind'       => 'handover',
            'from'       => (string) $r['from_value'],
            'to'         => (string) $r['to_value'],
            'from_display_name' => $dn($r['from_display']),
            'to_display_name'   => $dn($r['to_display']),
            'kept'       => $r['summary'] === null ? null : (string) $r['summary'],
            'page_count' => (int) $r['block_count'],
            'by'         => (string) $r['by_name'],
            'by_display_name' => $dn($r['by_display']),
            'at'         => (string) $r['at'],
        ];
        continue;
    }
    $events[] = [
        'kind'     => 'check',
        'block_id' => (string) $r['block_id'],
        'checked'  => $r['checked'] === true,
        'by'       => (string) $r['by_name'],
        'by_display_name' => $dn($r['by_display']),
        'at'       => (string) $r['at'],
    ];
}

/* 카페 C1 — 줄마다 사람 번호를 서버 안에서만 붙여 둔다(익명게시판 이름표용 · 응답 전에 뗀다). $events[0] 은 지금 판,
   $events[i+1] 은 $rows[i] 에서 만든 줄이다(위 반복이 한 줄에 한 사건). */
$events[0]['_by_id'] = (int) ($row['last_edited_by'] ?? 0);
foreach ($rows as $i => $r) {
    $events[$i + 1]['_by_id'] = (int) $r['by_id'];
}

/* 지금 판 한 줄을 끼워 넣었으므로 시간순을 한 번 더 맞춘다. PHP 8 의 정렬은 **안정**이라
   같은 시각의 줄들은 위 순서(판 → 체크 → 속성)를 그대로 지킨다.

   ★ **초 아래까지 본다** (4-D 에서 고침). `strtotime()` 은 초 단위라, 같은 1초 안에 일어난
     일들(페이지를 만들자마자 상태를 고르는 것 — 실측 2026-09-23)이 전부 「같은 시각」이 되어
     DB 가 맞춰 놓은 순서를 PHP 의 안정 정렬이 도로 흐트러뜨렸다. `page_versions`·
     `checklist_events`·`page_property_events` 는 모두 `timestamptz` 를 마이크로초까지
     들고 있으므로, 그 값을 그대로 견준다. 읽을 수 없는 값은 **맨 아래**로 보낸다
     (지어낸 시각으로 끼워 넣지 않는다). */
$stamp = static function (string $at): float {
    try {
        return (float) (new DateTimeImmutable($at))->format('U.u');
    } catch (Throwable $e) {
        return -INF;
    }
};

usort($events, static function (array $a, array $b) use ($stamp): int {
    return $stamp((string) $b['at']) <=> $stamp((string) $a['at']);
});

if (count($events) > $limit) {
    $events = array_slice($events, 0, $limit);
}

$suggestionVersions = community_suggestion_versions((int) $row['id'], $gate['since']);
$payload = [
    'suggestions' => array_values($suggestionVersions),
    'page' => [
        'id'          => (string) $row['public_id'],
        'title'       => (string) $row['title'],
        'doc_version' => (int) $row['doc_version'],
    ],
    'events' => $events,
    'total'  => count($events),
    'limit'  => $limit,
];

/* 사이트 Q:F-12 카페 C1 (ARCH 25.4) — 익명게시판 글이면 `by` 를 이름표로(편집자는 글쓴이뿐 · 숨김 사건은 「관리자」) ·
   담당 · 인계의 사람 값은 null · `suggestions` → []. 이름표 지도는 질의 하나(위 목록의 사람 번호 전부). 끝에 안전망. */
if (community_page_is_anon($row)) {
    $labels = community_anon_label_map((int) $row['id'], (int) $row['author_id'],
        array_map(static fn (array $ev): int => (int) $ev['_by_id'], $payload['events']));
    foreach ($payload['events'] as &$ev) {
        $byId = (int) $ev['_by_id'];
        $label = ($ev['kind'] === 'property' && ($ev['prop'] ?? '') === 'hide')
            ? '관리자'
            : ($labels[$byId] ?? community_anon_label($byId === (int) $row['author_id'], null));
        $ev['by']              = $label;
        $ev['by_display_name'] = $label;
        $ev['by_is_me']        = $label !== '관리자' && $byId === (int) $user['id'];
        if ($ev['kind'] === 'property' && ($ev['prop'] ?? '') === 'assignee') {
            $ev['from'] = null;
            $ev['to']   = null;
        }
        if ($ev['kind'] === 'handover') {
            $ev['from'] = null;
            $ev['to']   = null;
            $ev['from_display_name'] = null;
            $ev['to_display_name']   = null;
        }
    }
    unset($ev);
    $payload['suggestions'] = [];
    $payload = community_anon_scrub($payload);
}
foreach ($payload['events'] as &$ev) {
    unset($ev['_by_id']);
}
unset($ev);
community_form_json($payload, $user, $row);
