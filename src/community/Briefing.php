<?php
declare(strict_types=1);

/** C:F-42. Read-only projections; every category is bounded and filtered in SQL. */
final class Briefing
{
    private const MINE = "CASE WHEN s.kind = 'personal' THEN s.owner_user_id ELSE p.author_id END = CAST(:bf_me AS bigint)";
    private const OPEN = "COALESCE(p.status, '') NOT IN ('done', 'hold')";

    public static function collect(array $user, DateTimeImmutable $now, ?BriefingText $text = null): array
    {
        $text ??= new DefaultBriefingText();
        $now = $now->setTimezone(new DateTimeZone('Asia/Seoul'));
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $start = $now->setTime(0, 0);
        $dates = [':bf_today' => $today, ':bf_prevdate' => $yesterday, ':bf_start' => $start->format(DATE_ATOM),
            ':bf_yesterday' => $start->modify('-1 day')->format(DATE_ATOM),
            ':bf_end' => $start->modify('+1 day')->format(DATE_ATOM), ':bf_now' => $now->format(DATE_ATOM)];
        $items = [];
        foreach ([
            'overdue' => self::MINE . ' AND ' . self::OPEN . ' AND p.due_date < CAST(:bf_today AS date)',
            'missed_due' => self::MINE . ' AND ' . self::OPEN . ' AND p.due_date = CAST(:bf_prevdate AS date)',
            'due_today' => self::MINE . ' AND ' . self::OPEN . ' AND p.due_date = CAST(:bf_today AS date)',
            'review' => self::MINE . " AND p.status = 'review'",
            'verify_expired' => self::MINE . ' AND p.verify_expires_at <= CAST(:bf_now AS timestamptz)',
            'question' => "p.author_id = CAST(:bf_me AS bigint) AND NOT p.resolved AND (s.board_kind = 'qa' OR EXISTS (SELECT 1 FROM community.spaces qb WHERE qb.id = p.published_board_id AND qb.board_kind = 'qa'))",
            'recurring' => self::MINE . " AND EXISTS (SELECT 1 FROM community.automation_runs ar WHERE p.id = ANY(ar.created_page_ids) AND NOT ar.dry_run AND ar.status IN ('ok','partial') AND ar.started_at >= CAST(:bf_start AS timestamptz) AND ar.started_at < CAST(:bf_end AS timestamptz))",
            'notice' => "(s.board_kind = 'notice' OR EXISTS (SELECT 1 FROM community.spaces nb WHERE nb.id = p.published_board_id AND nb.board_kind = 'notice')) AND NOT EXISTS (SELECT 1 FROM community.page_reads pr WHERE pr.page_id = p.id AND pr.user_id = CAST(:bf_me AS bigint))",
        ] as $kind => $condition) {
            foreach (self::pages($user, $condition, $dates) as $row) {
                $item = self::pageItem($row, $kind === 'missed_due' ? 'overdue' : $kind, $text);
                $createdDate = (new DateTimeImmutable((string) $row['created_at']))->setTimezone($now->getTimezone())->format('Y-m-d');
                $item['missed'] = (in_array($kind, ['overdue', 'missed_due'], true) && $row['due_date'] === $yesterday)
                    || ($kind === 'notice' && $createdDate === $yesterday);
                $items[] = $item;
            }
        }

        // Notifications are only evidence of a mention/reply. Current page and thread
        // visibility and an actual later response determine whether it remains actionable.
        $commentJoin = <<<'SQL'
            JOIN community.comments c ON c.page_id = p.id
            JOIN community.comments thread ON thread.id = COALESCE(c.parent_comment_id, c.id)
            SQL;
        $unanswered = <<<'SQL'
            c.deleted_at IS NULL AND c.hidden_at IS NULL AND c.author_id <> CAST(:bf_me AS bigint)
            AND thread.deleted_at IS NULL AND thread.hidden_at IS NULL AND thread.resolved_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM community.comments reply
                WHERE reply.parent_comment_id = thread.id AND reply.author_id = CAST(:bf_me AS bigint)
                  AND reply.created_at > c.created_at AND reply.deleted_at IS NULL AND reply.hidden_at IS NULL)
            SQL;
        $mention = <<<'SQL'
            EXISTS (SELECT 1 FROM community.notifications n WHERE n.comment_id = c.id
                AND n.user_id = CAST(:bf_me AS bigint) AND n.kind = 'mention' AND n.retracted_at IS NULL)
            SQL;
        $missed = <<<'SQL'
            EXISTS (SELECT 1 FROM community.notifications n WHERE n.comment_id = c.id
                AND n.user_id = CAST(:bf_me AS bigint) AND n.kind IN ('mention','reply','comment_on_my_page')
                AND n.retracted_at IS NULL AND n.created_at >= CAST(:bf_yesterday AS timestamptz)
                AND n.created_at < CAST(:bf_start AS timestamptz))
            SQL;
        foreach (['mention' => $mention, 'missed_comment' => $missed] as $kind => $condition) {
            foreach (self::pages($user, $unanswered . ' AND ' . $condition, $dates,
                ', c.public_id AS comment_public_id, c.created_at AS comment_at', $commentJoin,
                'c.created_at DESC, c.id DESC') as $row) {
                $item = self::pageItem($row, $kind, $text);
                $item['key'] = 'comment:' . $row['comment_public_id'];
                $item['url'] .= '#comment-' . $row['comment_public_id'];
                $item['at'] = (string) $row['comment_at'];
                $item['missed'] = $kind === 'missed_comment';
                $items[] = $item;
            }
        }

        // PostgreSQL walks the JSON structure; no full documents are transferred or
        // rewritten just to display the briefing. DISTINCT removes lax JSONPath duplicates.
        $todoJoin = <<<'SQL'
            CROSS JOIN LATERAL (
                SELECT DISTINCT value AS block FROM jsonb_path_query(p.doc,
                    '$.blocks.** ? (@.type == "to_do" && @.checked == false)') AS candidate(value)
            ) todo
            SQL;
        foreach (self::pages($user, self::MINE, $dates,
            ", todo.block->>'id' AS block_id, todo.block->'text' AS block_text", $todoJoin) as $row) {
            if (!is_string($row['block_id']) || preg_match('/^[0-9a-f-]{36}$/D', $row['block_id']) !== 1) {
                continue;
            }
            $item = self::pageItem($row, 'unchecked', $text);
            $item['key'] = 'check:' . $row['public_id'] . ':' . $row['block_id'];
            $item['url'] .= '#block-' . $row['block_id'];
            $segments = json_decode((string) $row['block_text'], true);
            $item['check'] = ['page_id' => (string) $row['public_id'], 'block_id' => (string) $row['block_id'],
                'label' => (string) iconv_substr(Doc::plain(is_array($segments) ? $segments : []), 0, 180, 'UTF-8'),
                'can_attempt' => Authz::canCommunityWrite($user)];
            $items[] = $item;
        }
        foreach (self::files($user, $now, $text) as $item) {
            $items[] = $item;
        }
        return self::group($items, $now);
    }

    /** SQL fragments are code literals/private callers; request values are bound only. */
    private static function pages(array $user, string $condition, array $dates, string $columns = '', string $joins = '', string $order = 'p.updated_at DESC, p.id DESC'): array
    {
        [$visible, $bind] = community_search_pages_where($user);
        $sql = <<<SQL
            SELECT p.public_id, p.title, p.updated_at, p.created_at, p.due_date
                   {$columns}
              FROM community.pages p
              JOIN community.spaces s ON s.id = p.space_id
              LEFT JOIN public.users owner ON owner.id = s.owner_user_id
              {$joins}
             WHERE p.deleted_at IS NULL
               AND {$visible} AND ({$condition})
             ORDER BY
                   {$order}
             LIMIT 20
            SQL;
        return self::query($sql, $bind + $dates + [':bf_me' => (int) $user['id']]);
    }

    private static function query(string $sql, array $bind): array
    {
        preg_match_all('/(?<!:):[a-z][a-z0-9_]*/i', $sql, $matches);
        $bind = array_intersect_key($bind, array_fill_keys($matches[0], true));
        return Db::instance()->run($sql, $bind)->fetchAll();
    }

    private static function pageItem(array $row, string $kind, BriefingText $text): array
    {
        return ['key' => 'page:' . $row['public_id'], 'kind' => $kind,
            'title' => (string) $row['title'], 'summary' => $text->summary($kind),
            'url' => Http::communityUrl('/p/' . $row['public_id']), 'at' => (string) $row['updated_at'],
            'due' => $row['due_date'] === null ? null : (string) $row['due_date'], 'missed' => false];
    }

    private static function files(array $user, DateTimeImmutable $now, BriefingText $text): array
    {
        [$visible, $bind] = Authz::visibleFilesWhere($user);
        $sql = <<<SQL
            SELECT f.public_id, f.original_name, f.created_at
              FROM files f
             WHERE
                   {$visible} AND f.uploaded_by = CAST(:bf_me AS bigint)
               AND f.scan_status = 'pending'
             ORDER BY f.created_at DESC, f.id DESC LIMIT 20
            SQL;
        $out = [];
        foreach (self::query($sql, $bind + [':bf_me' => (int) $user['id']]) as $row) {
            $out[] = ['key' => 'file:' . $row['public_id'], 'kind' => 'pending_file',
                'title' => (string) $row['original_name'], 'summary' => $text->summary('pending_file'),
                'url' => Http::url('/') . '?q=' . rawurlencode((string) $row['original_name']),
                'at' => (string) $row['created_at'], 'missed' => false];
        }
        $sql = <<<SQL
            SELECT f.public_id, f.original_name, min(sl.expires_at) AS expires_at, max(sl.created_at) AS created_at
              FROM share_links sl JOIN files f ON f.id = sl.file_id
             WHERE
                   {$visible} AND sl.created_by = CAST(:bf_me AS bigint) AND sl.revoked_at IS NULL
               AND sl.download_count < sl.max_downloads
               AND sl.expires_at > CAST(:bf_now AS timestamptz)
               AND sl.expires_at <= CAST(:bf_now AS timestamptz) + interval '24 hours'
             GROUP BY f.public_id, f.original_name ORDER BY min(sl.expires_at), f.public_id LIMIT 20
            SQL;
        foreach (self::query($sql, $bind + [':bf_me' => (int) $user['id'], ':bf_now' => $now->format(DATE_ATOM)]) as $row) {
            $out[] = ['key' => 'share:' . $row['public_id'], 'kind' => 'expiring_share',
                'title' => (string) $row['original_name'], 'summary' => $text->summary('expiring_share'),
                'url' => Http::url('/settings') . '#shares-body', 'at' => (string) $row['created_at'],
                'expires_at' => (string) $row['expires_at'], 'missed' => false];
        }
        return $out;
    }

    /**
     * Pure ordering/deduplication. One source action never appears in two groups.
     * Q:F-12 (WK-8 ② · ARCH 23.4) — 공지(`notice`)는 `top`·`missed` 를 차지하지 않는다(작업 칸에서 뺀다). 공지는 홈의 공지 한 줄과
     * 받은 알림으로 본다 — 줄 자체는 `others` 에 남아(읽지 않은 공지 · 게시판 읽기 판정 그대로) 홈이 새 창구 없이 쓸 수 있다.
     * `count`(런처의 「오늘 n건」)는 `top`+`missed` 라 공지를 세지 않는다.
     */
    public static function group(array $items, DateTimeImmutable $now): array
    {
        $rank = static fn(array $i): int => ['overdue' => 0, 'due_today' => 1, 'review' => 2, 'mention' => 3][$i['kind']] ?? 4;
        usort($items, static fn(array $a, array $b): int => ($rank($a) <=> $rank($b))
            ?: (strtotime($b['at']) <=> strtotime($a['at'])) ?: strcmp($a['key'], $b['key']));
        $unique = [];
        foreach ($items as $item) {
            if (isset($unique[$item['key']])) {
                $unique[$item['key']]['missed'] = $unique[$item['key']]['missed'] || $item['missed'];
            } else {
                $unique[$item['key']] = $item;
            }
        }
        $items = array_values($unique);
        $top = [];
        $missed = [];
        $others = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'notice') { $others[] = $item; }
            elseif (count($top) < 3) { $top[] = $item; }
            elseif ($item['missed'] && count($missed) < 2) { $missed[] = $item; }
            else { $others[] = $item; }
        }
        return ['generated_at' => $now->format(DATE_ATOM), 'top' => $top, 'missed' => $missed,
            'others' => $others, 'count' => count($top) + count($missed), 'total' => count($unique)];
    }
}
