<?php
declare(strict_types=1);

/**
 * src/community/handlers/version_get.php — GET /community/api/pages/{id}/versions/{num}
 *                                          (정책: community.read)
 *
 * 요구사항: `C:F-19`, `C:S-14`, `../../../../community/ARCHITECTURE.md` 16장,
 *           수락 기준 C:T-42(없는 판 번호는 404) · C:T-44(본문은 **여기서만**).
 *
 * 돌려주는 것 — 그 판의 `doc` 원문. **본문이 나가는 유일한 이력 창구**이고, 그 앞에
 * `decidePageRead` 가 반드시 선다(목록·처리 이력에는 본문이 한 자도 없다).
 *
 * ★ 본문은 **JSON 원문 그대로** 나간다. 서버가 HTML 로 바꾸지 않는다 — 그리는 일은 화면의
 *   읽기 렌더러(`doc-render.js`)가 하고, 그 렌더러는 `createElement`/`textContent` 만 쓴다
 *   (C:T-15 와 같은 규칙).
 * ★ 지금 판(`doc_version`)을 물으면 `pages` 의 내용을 준다 — 지금 판은 `page_versions` 에
 *   없기 때문이다(아직 교체되지 않았다). 없는 번호·5분 합치기로 스냅샷이 남지 않은 번호는
 *   **404** 다: 우리가 갖고 있지 않은 것을 지어내지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID) · [1] = 판 번호(숫자)
 */

$gate = community_history_gate($user, (string) $params[0]);
$row  = $gate['row'];

// 라우터의 `{num}` 이 숫자 9자리까지만 통과시켰다 — 여기 오는 값은 이미 숫자 문자열이다.
$want = (int) $params[1];

// ─── 지금 판 ──────────────────────────────────────────────────
if ($want === (int) $row['doc_version']) {
    $doc = json_decode((string) $row['doc'], true);
    if (!is_array($doc)) {
        throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
    }
    Http::json([
        'version' => [
            'version'     => $want,
            'is_current'  => true,
            'title'       => (string) $row['title'],
            'by'          => $row['last_edited_by_name'] === null ? '' : (string) $row['last_edited_by_name'],
            'by_display_name' => $row['last_edited_by_display_name'] === null ? null : (string) $row['last_edited_by_display_name'],
            'at'          => (string) $row['updated_at'],
            'doc'         => $doc,
            'block_count' => (int) $row['block_count'],
        ],
    ]);
    exit;
}

// ─── 지난 판 ──────────────────────────────────────────────────
$sql = <<<'SQL'
    SELECT v.version, v.title, v.doc, v.edited_at, u.username AS edited_by_name,
           u.display_name AS edited_by_display_name
      FROM community.page_versions v
      JOIN public.users u ON u.id = v.edited_by
     WHERE v.page_id = CAST(:pid AS bigint)
       AND v.version = CAST(:ver AS int)
       -- 5-C: 게시 전 판은 게시판 등급으로만 보는 사람에게 「없다」(404) — 목록과 같은 자르기
       AND v.edited_at >= coalesce(CAST(:since AS timestamptz), '-infinity'::timestamptz)
    SQL;

$found = Db::instance()->run($sql, [':pid' => (int) $row['id'], ':ver' => $want, ':since' => $gate['since']])->fetch();
if (!is_array($found)) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$doc = json_decode((string) $found['doc'], true);
if (!is_array($doc)) {
    throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
}

Http::json([
    'version' => [
        'version'     => (int) $found['version'],
        'is_current'  => false,
        'title'       => (string) $found['title'],
        'by'          => (string) $found['edited_by_name'],
        'by_display_name' => $found['edited_by_display_name'] === null ? null : (string) $found['edited_by_display_name'],
        'at'          => (string) $found['edited_at'],
        'doc'         => $doc,
        'block_count' => is_array($doc['blocks'] ?? null) ? count($doc['blocks']) : 0,
    ],
]);
