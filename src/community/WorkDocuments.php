<?php
declare(strict_types=1);

/** Bounded, non-destructive document operations shared by OAuth MCP and personal-token clients. */
final class WorkDocuments
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public static function id($id): string
    {
        if (!is_string($id) || !preg_match(self::UUID, $id)) { Http::error(400, '문서 주소를 확인해 주세요.'); }
        return $id;
    }

    private static function fields(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed)) { Http::error(400, '지원하지 않는 입력 항목입니다.'); }
    }

    private static function text($value, int $max, bool $empty = false): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || (!$empty && trim($value) === '')
            || iconv_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            Http::error(400, '문구가 비어 있거나 너무 깁니다.');
        }
        return $value;
    }

    /** Same discoverability as search: no administrative override, board or published-page access. */
    private static function visible(array $user): array
    {
        [$where, $bind] = community_search_pages_where($user);
        return ["p.deleted_at IS NULL AND s.is_active AND s.kind IN ('personal','team')
                 AND p.visibility <> 'published' AND ({$where})", $bind];
    }

    private static function row(array $user, string $id): array
    {
        self::id($id);
        [$where, $bind] = self::visible($user);
        $found = Db::instance()->run("SELECT p.id FROM community.pages p
            JOIN community.spaces s ON s.id=p.space_id LEFT JOIN public.users owner ON owner.id=s.owner_user_id
            WHERE p.public_id=CAST(:work_id AS uuid)
              AND
              {$where}",
            $bind + [':work_id' => $id])->fetchColumn();
        if (!$found) { Http::error(404, '볼 수 있는 업무 문서를 찾지 못했습니다.'); }
        $row = community_page_row($id, (int) $user['id']);
        if ($row === null || !CommunityAuthz::decidePageRead($user, $row)[0]) {
            Http::error(404, '볼 수 있는 업무 문서를 찾지 못했습니다.');
        }
        return $row;
    }

    public static function listing(array $user, array $args, bool $projects): array
    {
        self::fields($args, $projects ? ['limit', 'after_id'] : ['parent_id', 'limit', 'after_id']);
        $limit = $args['limit'] ?? 30;
        if (!is_int($limit) || $limit < 1 || $limit > 100) { Http::error(400, '한 번에 1~100개를 읽을 수 있습니다.'); }
        $after = isset($args['after_id']) ? self::id($args['after_id']) : '00000000-0000-0000-0000-000000000000';
        [$where, $bind] = self::visible($user);
        if ($projects) {
            $where .= " AND EXISTS(SELECT 1 FROM community.databases d WHERE d.page_id=p.id AND d.purpose='project')";
        } else {
            $parent = self::row($user, self::id($args['parent_id'] ?? null));
            $where .= ' AND p.parent_page_id=CAST(:work_parent AS bigint)';
            $bind[':work_parent'] = (int) $parent['id'];
        }
        $rows = Db::instance()->run("SELECT p.public_id AS id,p.title,p.doc_version,p.updated_at,
                EXISTS(SELECT 1 FROM community.databases d WHERE d.page_id=p.id AND d.purpose='project') AS is_project
            FROM community.pages p JOIN community.spaces s ON s.id=p.space_id
            LEFT JOIN public.users owner ON owner.id=s.owner_user_id
            WHERE
              {$where}
              AND p.public_id>CAST(:work_after AS uuid)
            ORDER BY p.public_id LIMIT CAST(:work_limit AS int)",
            $bind + [':work_after'=>$after, ':work_limit'=>$limit + 1])->fetchAll();
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            $row['doc_version'] = (int) $row['doc_version'];
            $row['url'] = '/community/p/' . $row['id'];
        }
        unset($row);
        return ['items'=>$rows, 'next_after_id'=>$more ? $rows[count($rows)-1]['id'] : null];
    }

    public static function read(array $user, string $id): array
    {
        $row = self::row($user, $id);
        return ['id'=>$id, 'url'=>'/community/p/' . $id, 'title'=>$row['title'],
            'doc_version'=>(int) $row['doc_version'], 'doc'=>json_decode($row['doc'], true, 64, JSON_THROW_ON_ERROR),
            'can_edit'=>Authz::canCommunityWrite($user) && CommunityAuthz::decidePageChange($user, $row)[0]];
    }

    /** Replaces exactly one occurrence in a named text block. Marks, sibling blocks and children survive. */
    private static function replace(array &$blocks, array $replacement): bool
    {
        foreach ($blocks as &$block) {
            if (($block['id'] ?? null) === $replacement['block_id']) {
                if (!in_array($block['type'], ['paragraph','heading','bulleted','numbered','quote','to_do','callout','toggle'], true)
                    || !is_array($block['text'] ?? null)) { Http::error(400, '이 블록은 문구 교체를 지원하지 않습니다.'); }
                $plain = implode('', array_column($block['text'], 't'));
                $old = $replacement['old_text'];
                $start = strpos($plain, $old);
                if ($start === false || strpos($plain, $old, $start + 1) !== false) {
                    Http::error(409, '기존 문구가 없거나 여러 번 나옵니다. 문서를 다시 읽고 교체할 문구를 구체적으로 지정하세요.');
                }
                $end = $start + strlen($old);
                $offset = 0; $inserted = false; $segments = [];
                foreach ($block['text'] as $segment) {
                    $next = $offset + strlen($segment['t']);
                    if ($offset < $end && $next > $start) {
                        if (isset($segment['m']['mention'])) { Http::error(400, '멘션은 문구 교체로 바꿀 수 없습니다.'); }
                        $before = substr($segment['t'], 0, max(0, $start - $offset));
                        $after = substr($segment['t'], max(0, $end - $offset));
                        $value = $before . (!$inserted ? $replacement['new_text'] : '') . $after;
                        $inserted = true;
                        // Respect the canonical per-segment limit without changing existing marks.
                        $chars = iconv_strlen($value, 'UTF-8');
                        for ($pos = 0; $pos < $chars; $pos += Doc::limit('segment_chars')) {
                            $piece = $segment;
                            $piece['t'] = iconv_substr($value, $pos, Doc::limit('segment_chars'), 'UTF-8');
                            $segments[] = $piece;
                        }
                    } else { $segments[] = $segment; }
                    $offset = $next;
                }
                $block['text'] = $segments;
                return true;
            }
            if (isset($block['children']) && self::replace($block['children'], $replacement)) { return true; }
        }
        return false;
    }

    public static function update(array $user, string $id, array $body): array
    {
        self::fields($body, ['doc_version', 'replacements', 'append']);
        if (!is_int($body['doc_version'] ?? null) || $body['doc_version'] < 1 || $body['doc_version'] > 2147483646) {
            Http::error(400, '먼저 읽은 문서의 판 번호가 필요합니다.');
        }
        $replacements = $body['replacements'] ?? [];
        $append = $body['append'] ?? [];
        foreach ([$replacements, $append] as $items) {
            if (!is_array($items) || !array_is_list($items) || count($items) > 100) { Http::error(400, '한 번에 최대 100개 항목을 바꿀 수 있습니다.'); }
        }
        if ($replacements === [] && $append === []) { Http::error(400, '수정할 문구나 추가할 기록을 지정해 주세요.'); }
        foreach ($replacements as &$item) {
            if (!is_array($item)) { Http::error(400, Http::MSG_BAD_REQUEST); }
            self::fields($item, ['block_id','old_text','new_text']);
            $item = ['block_id'=>self::id($item['block_id'] ?? null),
                'old_text'=>self::text($item['old_text'] ?? null, 10000),
                'new_text'=>self::text($item['new_text'] ?? null, 10000, true)];
        }
        unset($item);
        $newBlocks = [];
        foreach ($append as $item) {
            if (!is_array($item)) { Http::error(400, Http::MSG_BAD_REQUEST); }
            self::fields($item, ['type','text','level']);
            $type = $item['type'] ?? 'paragraph';
            if (!in_array($type, ['paragraph','heading','bulleted','numbered','quote'], true)
                || (isset($item['level']) && ($type !== 'heading' || !in_array($item['level'], [1,2,3], true)))) {
                Http::error(400, '문단·제목·목록·인용만 추가할 수 있습니다.');
            }
            $text = self::text($item['text'] ?? null, 10000);
            $block = ['id'=>tbox_uuid_v4(), 'type'=>$type, 'text'=>[]];
            for ($pos = 0; $pos < iconv_strlen($text, 'UTF-8'); $pos += Doc::limit('segment_chars')) {
                $block['text'][] = ['t'=>iconv_substr($text, $pos, Doc::limit('segment_chars'), 'UTF-8'), 'm'=>[]];
            }
            if ($type === 'heading') { $block['level'] = $item['level'] ?? 2; }
            $newBlocks[] = $block;
        }
        $db = Db::instance();
        $db->begin();
        try {
            $row = self::row($user, $id);
            $db->run(COMMUNITY_PAGE_LOCK_SQL, [':pid'=>(int) $row['id']]);
            $row = self::row($user, $id);
            if (!Authz::canCommunityWrite($user) || !CommunityAuthz::decidePageChange($user, $row)[0]) {
                Http::error(403, '이 문서를 수정할 권한이 없습니다.');
            }
            if ((int) $row['doc_version'] !== $body['doc_version']) {
                Http::error(409, '다른 곳에서 먼저 저장되었습니다. 문서를 다시 읽고 변경 내용을 확인하세요. 자동으로 덮어쓰지 않습니다.');
            }
            $doc = json_decode($row['doc'], true, 64, JSON_THROW_ON_ERROR);
            foreach ($replacements as $replacement) {
                if (!self::replace($doc['blocks'], $replacement)) { Http::error(409, '교체할 블록이 없습니다. 문서를 다시 읽어 주세요.'); }
            }
            array_push($doc['blocks'], ...$newBlocks);
            $saved = community_save_doc($user, $id, ['doc'=>$doc, 'doc_version'=>$body['doc_version']], ['force_snapshot'=>true]);
            $db->commit();
            return ['id'=>$id, 'url'=>'/community/p/' . $id, 'doc_version'=>$saved['doc_version'],
                'replaced'=>count($replacements), 'appended'=>count($append)];
        } catch (Throwable $e) { $db->rollback(); throw $e; }
    }
}
