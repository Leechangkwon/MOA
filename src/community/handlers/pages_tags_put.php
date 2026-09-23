<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_tags_put.php — PUT /community/api/pages/{id}/tags (정책: community.write)
 *
 * 요구사항: 커뮤니티 PRD F-26(태그), 사용자 결정 2026-09-23 ② 「태그는 자유 입력 + 자동완성」,
 *           지시서 5-C 「태그」.
 *
 * 받는 값 (JSON) — `{tags: [ … ]}` 하나. 허용 키 밖이면 400. **보낸 목록이 곧 새 태그 전부**다
 * (넣기·빼기를 따로 두지 않는다 — 두 창구가 겨루는 자리를 만들지 않는다).
 *   · 앞뒤 공백 제거 · 1~30자 · 제어·서식 문자 거부 · 한 페이지 **10개까지**(11개 400)
 *   · 대소문자만 다른 태그는 **한 번**만 남는다(DB `lower()` — `tag_norm` 기본키)
 *
 * 자격 — 이 페이지의 **내용을 고칠 수 있는 사람**(`decidePageChange` — 소유자 · 편집 공유자 · 팀원 ·
 * 게시판 글의 쓴 사람). 보기만 하는 사람·게시판 관리자는 403 + 감사 `page.tags deny not_owner`.
 * 바뀌지 않았으면 기록하지 않는다(200 `changed: false`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

$body = Http::jsonBody();
if (array_keys($body) !== ['tags']) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$want = community_parse_tags($body['tags']);
if ($want === null) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// ─── 행 + 볼 수 있나 (404·403·감사는 공통 관문 한 벌) ───────────
$gate      = community_history_gate($user, $pid);
$row       = $gate['row'];
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);

if (!$gate['may_change']) {
    Audit::deny('page.tags', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => ['page_title' => $pageTitle],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$before = community_page_tags($pageId);

$sqlDel = <<<'SQL'
    DELETE FROM community.page_tags WHERE page_id = CAST(:pid AS bigint)
    SQL;
$sqlIns = <<<'SQL'
    INSERT INTO community.page_tags (page_id, tag, tag_norm, created_by)
    VALUES (CAST(:pid AS bigint), :tag, lower(:tag2), CAST(:by AS bigint))
    ON CONFLICT (page_id, tag_norm) DO NOTHING
    SQL;

$db->begin();
try {
    $db->run($sqlDel, [':pid' => $pageId]);
    foreach ($want as $t) {
        $db->run($sqlIns, [':pid' => $pageId, ':tag' => $t, ':tag2' => $t, ':by' => $me]);
    }
    $after = community_page_tags($pageId);
    if ($after === $before) {
        // 같은 태그를 다시 보냈다 — 되돌려도 결과가 같다. 기록을 늘리지 않는다.
        $db->rollback();
        Http::json(['page' => ['id' => $pid, 'tags' => $before], 'changed' => false]);
        exit;
    }
    Audit::allow('page.tags', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by'  => $gate['change_code'],
            'page_title'  => $pageTitle,
            'tags_before' => $before,
            'tags_after'  => $after,
        ],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['page' => ['id' => $pid, 'tags' => $after], 'changed' => true]);
