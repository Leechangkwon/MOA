<?php
declare(strict_types=1);

/** 8-B HTTP/DB boundary. Converters themselves have no request, DB, clock or filesystem access. */
function community_export_gate(string $id, array $user): array
{
    $row = community_page_row_nodoc($id, (int) $user['id']);
    [$ok, $reason] = $row === null ? [false, 'not_found'] : CommunityAuthz::decidePageExport($user, $row);
    if (!$ok) {
        Audit::deny('page.export', $reason, ['target_type' => 'page',
            'target_id' => $row === null ? null : (int) $row['id']]);
        Http::error($reason === 'not_found' ? 404 : 403,
            $reason === 'not_found' ? Http::MSG_NOT_FOUND : Http::MSG_FORBIDDEN);
    }
    return $row;
}

/** Trusted configuration only, never Host/Origin/Forwarded headers. */
function community_export_url(string $path): string
{
    $base = rtrim(Env::getOrEmpty('APP_URL'), '/');
    if ($base === '') {
        return $path;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $base) === 1) {
        throw new RuntimeException('내보내기 사이트 주소 설정을 확인해 주세요');
    }
    return $base . $path;
}

/** Never descend into a forbidden branch. Count its root only; no descendant query. */
function community_export_collect(array $root, array $user, bool $tree, string $format): array
{
    $entries = [];
    $skipped = 0;
    $seen = [];
    $used = [];
    $visit = static function (array $row, string $directory) use (&$visit, &$entries, &$skipped, &$seen, &$used, $user, $tree, $format): void {
        $id = (string) $row['public_id'];
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        if (count($entries) >= DocExport::MAX_PAGES) {
            throw new LengthException('too_large');
        }
        $used[$directory] ??= [];
        $name = DocExport::uniqueName((string) $row['title'], $format, $used[$directory],
            str_pad((string) (count($entries) + 1), 2, '0', STR_PAD_LEFT) . '-');
        $path = $directory . $name;
        $entries[] = ['row' => $row, 'path' => $path, 'title' => (string) $row['title']];
        if (!$tree) {
            return;
        }
        // parent_page_id is indexed. Read identifiers only; unauthorized document bodies never load.
        $sql = <<<'SQL'
            SELECT public_id FROM community.pages
             WHERE parent_page_id = CAST(:parent AS bigint)
               AND deleted_at IS NULL AND database_id IS NULL
             ORDER BY position, id
            SQL;
        $stmt = Db::instance()->run($sql, [':parent' => (int) $row['id']]);
        while ($child = $stmt->fetch()) {
            $next = community_page_row_nodoc((string) $child['public_id'], (int) $user['id']);
            if ($next === null || !CommunityAuthz::decidePageExport($user, $next)[0]) {
                ++$skipped;
                continue;
            }
            $visit($next, $directory . substr($name, 0, -strlen($format) - 1) . '/');
        }
    };
    $visit($root, '');
    return [$entries, $skipped];
}

/**
 * 내보내기 · 인쇄 ctx. Q:F-12 U3-1b — `$page` 는 이 본문의 문서 행(`community_export_document` 가 막 다시 읽고 판정한 행)이다.
 * 문서 전용 첨부(`att:`)는 그 문서의 `decideAttachmentRead` 가 열릴 때만 지도(`attachment_titles` — 본문이 가리키는 것만)와
 * 로그인 필요한 창구 주소(`attachment_href` — 그림 형식은 `/raw`, 그 밖은 `/download`)를 싣는다 — 자료실 `file_href` 와 같은 방식.
 * 받는 순간에도 창구가 문서 판정을 다시 한다. 공개 링크(`/pub/`)는 이 함수를 쓰지 않는다(그쪽은 그대로 「볼 수 없는 파일」).
 */
function community_export_context(array $doc, array $user, array $paths, string $from, ?array $page = null): array
{
    // This boundary needs references, not search text or excerpts. Extracting a large
    // code-only document's complete plain text is costly and supplies no permission data.
    $extract = Doc::extract(community_export_reference_doc($doc));
    $ids = $extract['child_pages'];
    foreach ($extract['links'] as $link) {
        $ids[] = $link['to_page_id'];
    }
    foreach ($extract['mentions'] as $mention) {
        if ($mention['kind'] === 'page') {
            $ids[] = (string) $mention['id'];
        }
    }
    $titles = [];
    $hrefs = [];
    foreach (array_chunk(array_values(array_unique($ids)), 200) as $chunk) {
        foreach (community_link_titles($chunk, $user) as $id => $entry) {
            $target = community_page_row_nodoc($id, (int) $user['id']);
            if ($target === null || !CommunityAuthz::decidePageExport($user, $target)[0]) {
                continue;
            }
            $titles[$id] = $entry;
            $hrefs[$id] = isset($paths[$id]) ? DocExport::relativeHref($from, $paths[$id])
                : community_export_url(Http::communityUrl('/p/' . $id));
        }
    }
    $files = [];
    $fileHrefs = [];
    foreach (array_chunk($extract['files'], 200) as $chunk) {
        foreach (community_file_titles($chunk, $user) as $id => $entry) {
            $files[$id] = $entry;
            $fileHrefs[$id] = community_export_url(Http::url('/api/files/' . $id . '/download'));
        }
    }
    // Q:F-12 U3-1b — 문서 전용 첨부(그 문서 판정 · 관리자 특권 갈래는 닫힘 — pages_get 의 attachment_titles 와 같은 조건)
    $attTitles = [];
    $attHrefs = [];
    if ($page !== null && $extract['atts'] !== []
        && CommunityAuthz::decideAttachmentRead($user, $page, ['page_id' => (int) $page['id']])[0]) {
        $wanted = array_flip($extract['atts']);
        foreach (community_attachment_titles((int) $page['id']) as $aid => $entry) {
            if (!isset($wanted[$aid])) {
                continue;
            }
            $attTitles[$aid] = $entry;
            $attHrefs[$aid] = community_export_url(Http::communityUrl('/api/pages/' . (string) $page['public_id']
                . '/attachments/' . $aid . ($entry['image'] ? '/raw' : '/download')));
        }
    }
    $mentions = community_mention_labels($extract['mentions'], $user);
    foreach (array_keys($mentions) as $key) {
        if (str_starts_with($key, 'page:') && !isset($hrefs[substr($key, 5)])) {
            unset($mentions[$key]);
        }
    }
    return ['synced'=>community_sync_map($doc,$user), 'child_titles' => $titles, 'link_titles' => $titles, 'page_href' => $hrefs,
        'file_titles' => $files, 'file_href' => $fileHrefs, 'mention_labels' => $mentions,
        'attachment_titles' => $attTitles, 'attachment_href' => $attHrefs,
        'db_views'=>community_db_widget_context($doc,$user), 'embeds' => community_embed_ctx()];
}

function community_export_reference_doc(array $value): array
{
    foreach ($value as $key => $child) {
        if (($key === 't' || $key === 'code') && is_string($child)) {
            $value[$key] = '';
        } elseif (is_array($child)) {
            $value[$key] = community_export_reference_doc($child);
        }
    }
    return $value;
}

function community_export_meta(array $row, string $now, ?array $user=null): array
{
    $owner = $row['space_owner_name'] ?? $row['author_name'] ?? '';
    $display = $row['space_owner_display_name'] ?? $row['author_display_name'] ?? null;
    if ($user!==null && community_form_identity_hidden($user,$row) && ($row['space_kind'] ?? '')!=='personal') { $owner=''; $display=null; }
    $meta = ['title' => (string) $row['title'], 'kind' => $row['page_kind'] ?? 'page',
        'source' => community_export_url(Http::communityUrl('/p/' . $row['public_id'])),
        'exported_at' => $now, 'owner' => tbox_person_label($display, (string) $owner),
        'status' => $row['status'] ?? '', 'tags' => community_page_tags((int) $row['id'])];
    // Same state calculation as the page UI; never export the verifier's numeric account ID.
    if (function_exists('community_verify_info')) {
        $verify = community_verify_info($row);
        $meta += ['verify_state' => $verify['state'], 'verified_by' => $verify['by']['name'] ?? null,
            'verified_at' => $verify['at'], 'verify_expires' => $verify['expires_at']];
    }
    return $meta;
}

function community_export_document(array $row, array $user): array
{
    // Re-read and authorize immediately before fetching/rendering the body.
    $current = community_page_row((string) $row['public_id'], (int) $user['id']);
    if ($current === null || !CommunityAuthz::decidePageExport($user, $current)[0]) {
        throw new RuntimeException('내보내는 동안 권한이 변경되었습니다. 다시 시도해 주세요');
    }
    $doc = json_decode((string) $current['doc'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($doc)) {
        throw new RuntimeException('저장된 본문을 읽을 수 없습니다');
    }
    if (($current['dbself_public_id'] ?? null) !== null) {
        [$visible, $bind] = community_search_pages_where($user);
        $sql = <<<SQL
            SELECT count(*) FROM community.pages p
              JOIN community.spaces s ON s.id = p.space_id
              LEFT JOIN public.users owner ON owner.id = s.owner_user_id
             WHERE p.database_id = (SELECT id FROM community.databases WHERE page_id = CAST(:export_parent AS bigint))
               AND p.deleted_at IS NULL AND {$visible}
            SQL;
        $bind[':export_parent'] = (int) $row['id'];
        $count = (int) Db::instance()->run($sql, $bind)->fetchColumn();
        $doc['blocks'][] = ['type' => 'paragraph', 'text' => [['t' => '행 ' . $count . '개는 이 내보내기에 들지 않습니다']]];
    }
    return [$current, $doc];
}

function community_export_allow(array $root, string $format, bool $tree, array $ids, int $skipped, int $bytes): void
{
    Audit::allow('page.export', ['target_type' => 'page', 'target_id' => (int) $root['id'],
        'target_name' => community_target_title((string) $root['title']),
        'detail' => ['format' => $format, 'tree' => $tree, 'pages' => count($ids),
            'skipped' => $skipped, 'bytes' => $bytes, 'page_ids' => array_values($ids)]]);
}

function community_export_too_large(array $root): void
{
    Audit::deny('page.export', 'too_large', ['target_type' => 'page', 'target_id' => (int) $root['id']]);
    Http::json(['error' => '한 번에 200개 문서, 20MB까지 내보낼 수 있습니다. 하위 페이지를 나누어 내보내 주세요.',
        'code' => 'too_large'], 400);
}

function community_export_headers(string $name, string $format): void
{
    $types = ['md' => 'text/markdown; charset=utf-8', 'html' => 'text/html; charset=utf-8', 'zip' => 'application/zip'];
    header('Content-Type: ' . $types[$format]);
    header('Content-Disposition: attachment; filename="page.' . $format . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
}
