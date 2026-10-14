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
 * 7-D4 — 그 판 본문의 글 안 멘션 이름 지도 `mention_labels`(페이지 응답과 같은 함수 `community_mention_labels` — 볼 수 있는 것만).
 *   이름은 **지금** 보는 사람 기준으로 푼다(판에 저장된 것은 `{kind, id}` 뿐이다).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID) · [1] = 판 번호(숫자)
 */

$gate = community_history_gate($user, (string) $params[0]);
$row  = $gate['row'];

/**
 * 사이트 Q:F-12 카페 C1 (ARCH 25.4) — 익명게시판 글이면 판의 `by` 를 이름표로(편집자는 글쓴이뿐) · 끝에 안전망. 아니면 그대로.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function community_version_anon(array $row, array $user, int $byId, array $payload): array
{
    if (!community_page_is_anon($row)) {
        return $payload;
    }
    $label = community_anon_label_map((int) $row['id'], (int) $row['author_id'], [$byId])[$byId]
        ?? community_anon_label($byId === (int) $row['author_id'], null);
    $payload['version']['by']              = $label;
    $payload['version']['by_display_name'] = $label;
    $payload['version']['by_is_me']        = $byId === (int) $user['id'];
    return community_anon_scrub($payload);
}

// 라우터의 `{num}` 이 숫자 9자리까지만 통과시켰다 — 여기 오는 값은 이미 숫자 문자열이다.
$want = (int) $params[1];

// ─── 지금 판 ──────────────────────────────────────────────────
if ($want === (int) $row['doc_version']) {
    $doc = json_decode((string) $row['doc'], true);
    if (!is_array($doc)) {
        throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
    }
    community_form_json(community_version_anon($row, $user, (int) ($row['last_edited_by'] ?? 0), [
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
        'mention_labels' => ($ml = community_mention_labels(Doc::extract($doc)['mentions'], $user)) === [] ? new stdClass() : $ml,
    ]), $user, $row);
    exit;
}

// ─── 지난 판 ──────────────────────────────────────────────────
$sql = <<<'SQL'
    SELECT v.version, v.title, v.doc, v.edited_at, v.edited_by, u.username AS edited_by_name,
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

community_form_json(community_version_anon($row, $user, (int) $found['edited_by'], [
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
    'mention_labels' => ($ml = community_mention_labels(Doc::extract($doc)['mentions'], $user)) === [] ? new stdClass() : $ml,
]), $user, $row);
