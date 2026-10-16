<?php
declare(strict_types=1);

/** C:F-42. Read-only projections; every category is bounded and filtered in SQL. */
final class Briefing
{
    private const OPEN = "COALESCE(p.status, '') NOT IN ('done', 'hold')";
    /* 규모 D2a A8 — 「내 것」(옛 한 식 `CASE WHEN s.kind = 'personal' THEN s.owner_user_id ELSE p.author_id END = 나`)을 겹치지 않는
     * 두 갈래로 나눈다: ① 내 개인 공간의 글(공간마다 pages_space_updated_idx 순서) ② 개인 공간 밖에서 내가 쓴 글
     * (pages_author_updated_idx 순서). 공간 종류(personal · 아님)로 갈려 한 글이 두 갈래에 들지 않는다 — 합친 행은 옛 식과 같다.
     * 갈래 = [몰고 가는 표(없으면 ''), 글 칸 조건, 공간 칸 조건, 몰고 가는 표 조건, 내 개인 공간 증명]. 모두 코드 리터럴이다(요청 값 0).
     * 첫 갈래는 bs.kind='personal' · bs.owner_user_id=:bf_me · p.space_id=bs.id 로 공간과 소유자가 이미 정해진다.
     * :bf_me 와 community_search_pages_where 의 :vis_me 는 같은 user.id 이므로, 현재 visible 의 own-personal OR 와
     * AND로 붙는 formScope 의 own-personal OR 가 모두 TRUE 이다. 공통 hidden/owner-active 조건은 없고 deleted_at 은 아래에서
     * 계속 거른다. 따라서 이 갈래만 중복 공간/owner EXISTS 를 생략한다. 공통 권한식이 바뀌면 이 증명도 함께 검토해야 한다. */
    private const MINE = [
        ['community.spaces bs', 'p.space_id = bs.id', 'TRUE', "bs.kind = 'personal' AND bs.owner_user_id = CAST(:bf_me AS bigint)", true],
        ['', 'p.author_id = CAST(:bf_me AS bigint)', "s.kind <> 'personal'", '', false],
    ];
    /* 규모 D2a A8 ① — 안 읽은 공지(옛 `s.board_kind = 'notice' OR 공지 게시판에 게시됨`)도 겹치지 않는 두 갈래: 공지 게시판마다
     * ① 그 게시판 글(pages_space_updated_idx) ② 공지 게시판 밖 공간의 글 중 그 게시판에 게시된 것. 안 읽음은 갈래 안 anti-join. */
    private const NOTICE = [
        ['community.spaces nb', 'p.space_id = nb.id', 'TRUE', "nb.board_kind = 'notice'", false],
        ['community.spaces nb', 'p.published_board_id = nb.id', "s.board_kind IS DISTINCT FROM 'notice'", "nb.board_kind = 'notice'", false],
    ];
    private const UNREAD = 'NOT EXISTS (SELECT 1 FROM community.page_reads pr WHERE pr.page_id = p.id AND pr.user_id = CAST(:bf_me AS bigint))';

    public static function collect(array $user, DateTimeImmutable $now, ?BriefingText $text = null): array
    {
        /* 규모 D2a A8 — 읽기 문장들을 JIT 없이 한 읽기 트랜잭션에서(routes.php COMMUNITY_LIST_PLAN_NO_JIT 머리글). 행 조건절이 큰 문장은
         * 계획기가 비용을 크게 어림해 JIT 컴파일(합성 L 의 공지 문장 — 374ms/427ms)을 붙였다. 결과는 같다. */
        return community_run_without_jit(static fn (): array => self::collectRows($user, $now, $text));
    }

    private static function collectRows(array $user, DateTimeImmutable $now, ?BriefingText $text): array
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
        // 종류 → [갈래(없으면 null = 한 질의), 조건]. 조건 · 갈래는 코드 리터럴이고 값은 바인딩한다.
        foreach ([
            'overdue' => [self::MINE, self::OPEN . ' AND p.due_date < CAST(:bf_today AS date)'],
            'missed_due' => [self::MINE, self::OPEN . ' AND p.due_date = CAST(:bf_prevdate AS date)'],
            'due_today' => [self::MINE, self::OPEN . ' AND p.due_date = CAST(:bf_today AS date)'],
            'review' => [self::MINE, "p.status = 'review'"],
            'verify_expired' => [self::MINE, 'p.verify_expires_at <= CAST(:bf_now AS timestamptz)'],
            'question' => [null, "p.author_id = CAST(:bf_me AS bigint) AND NOT p.resolved AND (s.board_kind = 'qa' OR EXISTS (SELECT 1 FROM community.spaces qb WHERE qb.id = p.published_board_id AND qb.board_kind = 'qa'))"],
            // 규모 D2a — 옛 「EXISTS (… p.id = ANY(ar.created_page_ids) …)」(글마다 오늘 실행을 훑음)와 같은 뜻을 오늘 실행에서 출발하는 묶음으로
            'recurring' => [self::MINE, "p.id IN (SELECT unnest(ar.created_page_ids) FROM community.automation_runs ar WHERE NOT ar.dry_run AND ar.status IN ('ok','partial') AND ar.started_at >= CAST(:bf_start AS timestamptz) AND ar.started_at < CAST(:bf_end AS timestamptz))"],
            'notice' => [self::NOTICE, self::UNREAD],
        ] as $kind => [$branches, $condition]) {
            $found = $branches === null ? self::pages($user, $condition, $dates) : self::pagesBranches($user, $branches, $condition, $dates);
            foreach ($found as $row) {
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
        foreach (self::pagesBranches($user, self::MINE, 'TRUE', $dates,
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

    /**
     * 규모 D2a A8 — 겹치지 않는 갈래마다 같은 모양의 질의(조건절 · 조건 · 정렬 · LIMIT 20 그대로)를 돌려 합친 뒤 같은 정렬로 20개.
     * 갈래 안 질의는 `pages()` 와 같은 행 조건(조건절 `$visible` · 조건 · 삭제 제외)에 갈래 조건을 더한 것이다 — 합친 행 · 순서는 옛 한
     * 질의(갈래 조건의 OR)와 같다. 몰고 가는 표가 있으면 그 행마다 LATERAL 로 묻는다(게시판 · 개인 공간마다 앞의 20개까지만 읽는다).
     * 공간 · 주인 조인(1:1)과 조건절은 EXISTS 안에 둔다 — 바깥이 글 표 하나라 색인 순서(고친 시각)가 정렬까지 이어져 20개에서 멈춘다
     * (조인을 밖에 두면 해시 조인이 순서를 깨 갈래의 글 전부를 정렬했다 — D2a L 1차 측정). 글 칸만 보는 조건은 밖에 둬 색인을 고른다.
     *
     * @param list<array{0:string,1:string,2:string,3:string,4:bool}> $branches 코드 리터럴만(MINE · NOTICE)
     */
    private static function pagesBranches(array $user, array $branches, string $condition, array $dates, string $columns = '', string $joins = ''): array
    {
        [$visible, $bind] = community_search_pages_where($user);
        $parts = [];
        foreach ($branches as [$drive, $inner, $spaceCond, $outer, $ownedPersonal]) {
            $scope = $ownedPersonal ? 'TRUE' : <<<SQL
                EXISTS (SELECT 1 FROM community.spaces s LEFT JOIN public.users owner ON owner.id = s.owner_user_id
                         WHERE s.id = p.space_id
                           AND {$spaceCond} AND {$visible})
                SQL;
            $sub = <<<SQL
                SELECT p.id AS bf_id, p.public_id, p.title, p.updated_at, p.created_at, p.due_date
                       {$columns}
                  FROM community.pages p
                  {$joins}
                 WHERE p.deleted_at IS NULL
                   AND {$inner}
                   AND ({$condition})
                   AND {$scope}
                 ORDER BY p.updated_at DESC, p.id DESC
                 LIMIT 20
                SQL;
            $wrap = $drive === '' ? <<<'SQL'
                SELECT b.*
                  FROM (@SUB) b
                SQL : <<<'SQL'
                SELECT b.*
                  FROM @DRIVE CROSS JOIN LATERAL (@SUB) b
                 WHERE @OUTER
                SQL;
            $parts[] = strtr($wrap, ['@SUB' => $sub, '@DRIVE' => $drive, '@OUTER' => $outer]);
        }
        $tpl = <<<'SQL'
            SELECT u.*
              FROM (@PARTS) u
             ORDER BY u.updated_at DESC, u.bf_id DESC
             LIMIT 20
            SQL;
        $sql = strtr($tpl, ['@PARTS' => implode("\nUNION ALL\n", $parts)]);
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
        return ['key' => 'page:' . $row['public_id'], 'page_id' => (string) $row['public_id'], 'kind' => $kind,
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
            $reason = array_intersect_key($item, array_fill_keys(['kind', 'summary', 'at', 'due', 'missed', 'check', 'expires_at'], true));
            if (isset($unique[$item['key']])) {
                $unique[$item['key']]['missed'] = $unique[$item['key']]['missed'] || $item['missed'];
                $found = false;
                foreach ($unique[$item['key']]['reasons'] as &$existing) {
                    if ($existing['kind'] === $reason['kind']) {
                        $existing['missed'] = $existing['missed'] || $reason['missed'];
                        $found = true;
                        break;
                    }
                }
                unset($existing);
                if (!$found) $unique[$item['key']]['reasons'][] = $reason;
            } else {
                $item['reasons'] = [$reason];
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
