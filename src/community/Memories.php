<?php
declare(strict_types=1);
require_once __DIR__ . '/MemoryStory.php';
require_once __DIR__ . '/MemorySocial.php';

/** Photo and text threads within the community; document permissions stay on their existing routes. */
final class Memories
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime'];

    public static function ready(): bool
    {
        $sql = "SELECT to_regclass('community.memory_links') IS NOT NULL AND to_regclass('community.memory_follows') IS NOT NULL AND to_regclass('community.memory_preferences') IS NOT NULL AS ready";
        return (bool) Db::instance()->run($sql)->fetchColumn();
    }

    public static function requireReady(): void
    {
        if (!self::ready()) {
            Http::json(['error' => '여행 기록을 준비하고 있어요. 서버에 여행 기록 업데이트를 적용한 뒤 다시 열어 주세요.'], 503);
            exit;
        }
    }

    public static function uuid($value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    public static function text($value, int $max): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || iconv_strlen($value, 'UTF-8') > $max
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            Http::error(400, '글의 길이와 입력 내용을 확인해 주세요.');
        }
        return trim($value);
    }

    public static function body(?array $input = null): array
    {
        $body = $input ?? Http::jsonBody(40000, 8);
        $allowed = ['caption', 'location', 'trip', 'taken_on', 'visibility', 'media_ids', 'revision', 'request_id', 'story', 'discoverable'];
        if (array_diff(array_keys($body), $allowed)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        foreach (['caption', 'location', 'trip', 'taken_on', 'visibility', 'media_ids'] as $key) {
            if (!array_key_exists($key, $body)) {
                Http::error(400, Http::MSG_BAD_REQUEST);
            }
        }
        $body['caption'] = self::text($body['caption'], 5000);
        $body['location'] = self::text($body['location'], 100);
        $body['trip'] = self::text($body['trip'], 80);
        $date = is_string($body['taken_on']) ? DateTimeImmutable::createFromFormat('!Y-m-d', $body['taken_on']) : false;
        if (!$date || (int) $date->format('Y') < 1 || $date->format('Y-m-d') !== $body['taken_on'] || !in_array($body['visibility'], MemorySocial::VISIBILITIES, true)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        if (array_key_exists('discoverable', $body) && !is_bool($body['discoverable'])) { Http::error(400, Http::MSG_BAD_REQUEST); }
        if (($body['discoverable'] ?? false) && !in_array($body['visibility'], ['community', 'shared'], true)) {
            Http::error(400, '발견에 게시하려면 공개 범위를 커뮤니티 또는 링크 공유로 선택해 주세요.');
        }
        $ids = $body['media_ids'];
        if (!is_array($ids) || array_values($ids) !== $ids || count($ids) > 10 || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            Http::error(400, '사진과 영상은 한 기록에 최대 10개까지 넣을 수 있어요.');
        }
        foreach ($ids as $id) {
            if (!self::uuid($id)) { Http::error(400, Http::MSG_BAD_REQUEST); }
        }
        if ($body['caption'] === '' && !$ids) { Http::error(400, '사진이나 짧은 글을 하나 남겨 주세요.'); }
        return $body;
    }

    public static function link(string $token): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) { Http::error(404, Http::MSG_NOT_FOUND); }
        $sql = <<<'SQL'
            SELECT l.*, u.username, u.display_name FROM community.memory_links l
            JOIN public.users u ON u.id=l.owner_user_id
            WHERE l.token_hash=:hash AND l.revoked_at IS NULL AND l.expires_at>now()
              AND u.is_active AND (u.expires_at IS NULL OR u.expires_at>now())
            SQL;
        $row = Db::instance()->run($sql, [':hash' => hash('sha256', $token)])->fetch();
        if (!$row) { Http::error(404, '공유가 끝났거나 찾을 수 없는 여행 기록이에요.'); }
        return $row;
    }

    public static function row(string $id, ?array $user, ?array $link = null, bool $write = false): array
    {
        if (!self::uuid($id)) { Http::error(404, Http::MSG_NOT_FOUND); }
        $sql = <<<'SQL'
            SELECT p.*, u.username, u.display_name FROM community.memory_posts p
            JOIN public.users u ON u.id=p.owner_user_id
            WHERE p.id=CAST(:id AS uuid) AND p.deleted_at IS NULL AND u.is_active
              AND (u.expires_at IS NULL OR u.expires_at>now())
            SQL;
        $row = Db::instance()->run($sql, [':id' => $id])->fetch();
        if (!$row || !self::canRead($row, $user, $link) || ($write && (int) $row['owner_user_id'] !== (int) ($user['id'] ?? 0))) {
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        return $row;
    }

    public static function canRead(array $post, ?array $user, ?array $link = null): bool
    {
        if ($link !== null) {
            return $post['visibility'] === 'shared' && (int) $post['owner_user_id'] === (int) $link['owner_user_id']
                && ($link['post_id'] === null || $link['post_id'] === $post['id']);
        }
        if ($user === null) { return false; }
        if ((int) $post['owner_user_id'] === (int) $user['id']) { return true; }
        if (in_array($post['visibility'], ['community', 'shared'], true)) { return true; }
        return $post['visibility'] === 'followers'
            && MemorySocial::follows((int) $user['id'], (int) $post['owner_user_id']);
    }

    public static function mediaList(array $ids, string $prefix): array
    {
        if (!$ids) { return []; }
        $sql = <<<'SQL'
            SELECT id, mime, filename, bytes FROM community.memory_media
             WHERE id IN (SELECT value::uuid FROM jsonb_array_elements_text(CAST(:ids AS jsonb)))
            SQL;
        $rows = Db::instance()->run($sql, [':ids' => json_encode($ids)])->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $row['url'] = $prefix . $row['id'];
            $row['bytes'] = (int) $row['bytes'];
            $map[$row['id']] = $row;
        }
        return array_values(array_filter(array_map(static fn ($id) => $map[$id] ?? null, $ids)));
    }

    /** Only call after the feed has filtered every post by the current reader's access. */
    public static function outputMany(array $rows, ?array $user, string $prefix): array
    {
        if (!$rows) { return []; }
        $db = Db::instance();
        $ids = json_encode(array_column($rows, 'id'));
        $counts = [];
        $sql = <<<'SQL'
            SELECT post_id,count(*) AS total,bool_or(user_id=:viewer) AS liked
            FROM community.memory_likes
            WHERE post_id IN (SELECT value::uuid FROM jsonb_array_elements_text(CAST(:ids AS jsonb)))
            GROUP BY post_id
            SQL;
        foreach ($db->run($sql, [':viewer' => (int) ($user['id'] ?? 0), ':ids' => $ids])->fetchAll() as $row) {
            $counts[$row['post_id']] = ['likes' => (int) $row['total'], 'liked' => (bool) $row['liked']];
        }
        $sql = <<<'SQL'
            SELECT post_id,count(*) AS total FROM community.memory_comments
            WHERE post_id IN (SELECT value::uuid FROM jsonb_array_elements_text(CAST(:ids AS jsonb)))
            GROUP BY post_id
            SQL;
        foreach ($db->run($sql, [':ids' => $ids])->fetchAll() as $row) {
            $counts[$row['post_id']]['comments'] = (int) $row['total'];
        }
        $following = [];
        if ($user !== null) {
            $sql = <<<'SQL'
                SELECT followed_user_id FROM community.memory_follows WHERE follower_user_id=:viewer
                AND followed_user_id IN (SELECT value::bigint FROM jsonb_array_elements_text(CAST(:owners AS jsonb)))
                SQL;
            foreach ($db->run($sql, [':viewer' => (int) $user['id'], ':owners' => json_encode(array_values(array_unique(array_column($rows, 'owner_user_id'))))])->fetchAll() as $row) {
                $following[(int) $row['followed_user_id']] = true;
            }
        }
        $mediaIds = [];
        foreach ($rows as $row) {
            $mediaIds = array_merge($mediaIds, json_decode($row['media_ids'], true));
            if ($user !== null && (int) $row['owner_user_id'] === (int) $user['id']) {
                $mediaIds = array_merge($mediaIds, json_decode($row['original_media_ids'], true));
            }
        }
        $media = array_column(self::mediaList(array_values(array_unique($mediaIds)), $prefix), null, 'id');
        $batch = ['counts' => $counts, 'following' => $following, 'media' => $media];
        return array_map(static fn ($row) => self::output($row, $user, $prefix, $batch), $rows);
    }

    public static function output(array $row, ?array $user, string $prefix, ?array $batch = null): array
    {
        $owner = $user !== null && (int) $row['owner_user_id'] === (int) $user['id'];
        $sql = <<<'SQL'
            SELECT (SELECT count(*) FROM community.memory_likes WHERE post_id=CAST(:liked AS uuid)) AS likes,
                   (SELECT count(*) FROM community.memory_comments WHERE post_id=CAST(:commented AS uuid)) AS comments,
                   EXISTS(SELECT 1 FROM community.memory_likes WHERE post_id=CAST(:post AS uuid) AND user_id=:viewer) AS liked
            SQL;
        $counts = $batch === null
            ? Db::instance()->run($sql, [':liked' => $row['id'], ':commented' => $row['id'], ':post' => $row['id'], ':viewer' => (int) ($user['id'] ?? 0)])->fetch()
            : (($batch['counts'][$row['id']] ?? []) + ['likes' => 0, 'comments' => 0, 'liked' => false]);
        $mediaFor = static fn (array $ids): array => $batch === null ? self::mediaList($ids, $prefix)
            : array_values(array_filter(array_map(static fn ($id) => $batch['media'][$id] ?? null, $ids)));
        $out = array_intersect_key($row, array_flip(['id', 'caption', 'location', 'trip', 'taken_on', 'visibility', 'created_at', 'username', 'display_name']));
        $out['story'] = isset($row['story']) ? json_decode($row['story'], true) : null;
        $out['discoverable'] = (bool) ($row['discoverable'] ?? false);
        $out['following'] = $user !== null && ($batch === null ? MemorySocial::follows((int) $user['id'], (int) $row['owner_user_id']) : isset($batch['following'][(int) $row['owner_user_id']]));
        $out += ['mine' => $owner, 'revision' => (int) $row['revision'], 'media' => $mediaFor(json_decode($row['media_ids'], true)),
            'likes' => (int) $counts['likes'], 'comments' => (int) $counts['comments'], 'liked' => (bool) $counts['liked']];
        if ($owner) {
            $out['original_caption'] = $row['original_caption'];
            $out['original_media'] = $mediaFor(json_decode($row['original_media_ids'], true));
        }
        return $out;
    }

    public static function feed(?array $user, ?array $link = null, string $token = ''): array
    {
        $where = ['p.deleted_at IS NULL', 'u.is_active', '(u.expires_at IS NULL OR u.expires_at>now())'];
        $bind = [];
        $view = $_GET['view'] ?? 'feed';
        $profile = Memories::text($_GET['profile'] ?? '', 64);
        $search = Memories::text($_GET['q'] ?? '', 100);
        if (!is_string($view) || !in_array($view, ['feed', 'following', 'mine', 'shared', 'discover'], true)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        if ($link) {
            $where[] = "p.visibility='shared' AND p.owner_user_id=:owner";
            $bind[':owner'] = (int) $link['owner_user_id'];
            if ($link['post_id'] !== null) { $where[] = 'p.id=CAST(:shared_post AS uuid)'; $bind[':shared_post'] = $link['post_id']; }
        } else {
            if ($user === null) { Http::error(401, '로그인해 주세요.'); }
            // Keep this predicate in step with canRead: the graph only opens followers posts.
            $where[] = "(p.owner_user_id=:viewer OR p.visibility IN ('community','shared') OR (p.visibility='followers' AND EXISTS(SELECT 1 FROM community.memory_follows f WHERE f.follower_user_id=:follower AND f.followed_user_id=p.owner_user_id)))";
            $bind[':viewer'] = (int) $user['id']; $bind[':follower'] = (int) $user['id'];
            if ($view === 'mine') {
                $where[] = 'p.owner_user_id=:mine'; $bind[':mine'] = (int) $user['id'];
            } elseif ($view === 'discover') {
                $where[] = "p.discoverable AND p.visibility IN ('community','shared')";
            } elseif ($profile === '') {
                $where[] = '(p.owner_user_id=:own_feed OR EXISTS(SELECT 1 FROM community.memory_follows f WHERE f.follower_user_id=:feed_follower AND f.followed_user_id=p.owner_user_id))';
                $bind[':own_feed'] = (int) $user['id']; $bind[':feed_follower'] = (int) $user['id'];
            }
            if ($profile !== '') { $where[] = 'u.username=:profile'; $bind[':profile'] = $profile; }
        }
        if ($search !== '') {
            $where[] = "strpos(lower(p.caption || ' ' || COALESCE(p.story::text,'') || ' ' || p.location || ' ' || p.trip),lower(:search))>0";
            $bind[':search'] = $search;
        }
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0 || $offset > 10000) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $scope = hash('sha256', json_encode([$view, $profile, $search, (int) ($user['id'] ?? 0), $link['token_hash'] ?? '']));
        $cursor = MemorySocial::cursor($_GET['cursor'] ?? null, $scope);
        $snapshot = $cursor['snapshot'] ?? (string) Db::instance()->run('SELECT clock_timestamp()::text')->fetchColumn();
        $where[] = 'p.created_at<=CAST(:snapshot AS timestamptz)'; $bind[':snapshot'] = $snapshot;
        $base = ' FROM community.memory_posts p JOIN public.users u ON u.id=p.owner_user_id WHERE ';
        $base .= implode(' AND ', $where);
        $statsSql = "SELECT count(*) AS posts, count(DISTINCT NULLIF(p.trip,'')) AS trips, coalesce(sum(jsonb_array_length(p.media_ids)),0) AS moments";
        $includeStats = $_GET['include_stats'] ?? '1';
        if (!in_array($includeStats, ['0', '1'], true)) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $stats = $includeStats === '1' ? Db::instance()->run($statsSql . $base, $bind)->fetch() : null;

        // Freshness plus a small deterministic author/viewer/day rotation, not inferred interests.
        // Freeze the day and creation cutoff in the cursor. Likes/comments do not reorder later pages.
        $rank = 'floor(extract(epoch FROM p.created_at))::bigint';
        if (!$link && $view === 'discover') {
            $rank .= " + ((('x' || substr(md5(CAST(:rank_viewer AS text) || ':' || p.owner_user_id::text || ':' || :rank_day),1,8))::bit(32)::bigint) % 21600)";
            $bind[':rank_viewer'] = (int) $user['id'];
            $bind[':rank_day'] = (new DateTimeImmutable($snapshot))->format('Y-m-d');
        }
        $ranked = !$link && $view === 'discover';
        if ($ranked) {
            // Boost is always 0..21599 seconds. Once we have N eligible recent rows,
            // anything >21600 seconds older than their oldest cannot enter the top N.
            // Both seed and final query keep the same live visibility predicates.
            $seed = 'SELECT p.created_at'; $seed .= $base;
            $seedBind = $bind;
            if ($cursor !== null) {
                $seed .= ' AND p.created_at<to_timestamp(CAST(:seed_rank AS bigint)+1)';
                $seed .= ' AND (' . $rank . ',p.created_at,p.id)<(CAST(:seed_rank AS bigint),CAST(:seed_created AS timestamptz),CAST(:seed_id AS uuid))';
                $seedBind += [':seed_rank' => $cursor['rank'], ':seed_created' => $cursor['created'], ':seed_id' => $cursor['id']];
            } else {
                unset($seedBind[':rank_viewer'], $seedBind[':rank_day']);
            }
            $seed .= ' ORDER BY p.created_at DESC,p.id DESC LIMIT CAST(:seed_limit AS integer)';
            $seedBind[':seed_limit'] = $cursor === null ? $offset + 21 : 21;
            $recent = Db::instance()->run($seed, $seedBind)->fetchAll();
            if ($recent) {
                $base .= " AND p.created_at>=CAST(:rank_floor AS timestamptz)-interval '21600 seconds'";
                $bind[':rank_floor'] = $recent[count($recent) - 1]['created_at'];
            } else {
                $base .= ' AND FALSE';
            }
        }
        $select = 'SELECT p.*,u.username,u.display_name,';
        $query = $select . $rank . ' AS feed_rank' . $base;
        if ($ranked) {
            $tail = ') SELECT * FROM visible';
            $query = 'WITH visible AS (' . $query . $tail;
        }
        if ($cursor !== null) {
            if ($ranked) {
                $query .= ' WHERE (feed_rank,created_at,id)<(CAST(:last_rank AS bigint),CAST(:last_created AS timestamptz),CAST(:last_id AS uuid))';
                $bind[':last_rank'] = $cursor['rank'];
            } else {
                $query .= ' AND (p.created_at,p.id)<(CAST(:last_created AS timestamptz),CAST(:last_id AS uuid))';
            }
            $bind[':last_created'] = $cursor['created']; $bind[':last_id'] = $cursor['id'];
            $offset = 0;
        }
        $query .= $ranked ? ' ORDER BY feed_rank DESC,created_at DESC,id DESC' : ' ORDER BY p.created_at DESC,p.id DESC';
        $query .= ' LIMIT 21 OFFSET CAST(:offset AS integer)';
        $rows = Db::instance()->run($query, $bind + [':offset' => $offset])->fetchAll();
        $more = count($rows) > 20;
        $page = array_slice($rows, 0, 20);
        $prefix = Http::communityUrl($link ? '/memories/s/' . $token . '/media/' : '/api/memories/media/');
        $out = ['posts' => self::outputMany($page, $user, $prefix),
            'next_offset' => $more ? $offset + 20 : null,
            'next_cursor' => $more ? MemorySocial::encodeCursor($scope, $snapshot, $page[19]) : null,
            'stats' => $stats === null ? null : array_map('intval', $stats)];
        if ($user) {
            $out['me'] = ['username' => $user['username'], 'display_name' => $user['display_name'] ?? null,
                'can_write' => Authz::canCommunityWrite($user), 'csrf_token' => Auth::csrfToken(),
                'default_visibility' => MemorySocial::defaultVisibility((int) $user['id'])];
            $out['profile'] = MemorySocial::profile($profile !== '' ? MemorySocial::member($profile) : $user, $user);
        } else {
            $out['profile'] = ['username' => $link['username'], 'display_name' => $link['display_name']];
        }
        return $out;
    }

    public static function save(array $user, ?string $id, ?array $input = null): array
    {
        $body = self::body($input);
        $db = Db::instance();
        $db->begin();
        // One owner lock serializes saves/uploads, caps quota and makes retries idempotent.
        $db->run('SELECT id FROM public.users WHERE id=:owner FOR UPDATE',
                [':owner' => (int) $user['id']]);
        if ($id !== null) {
            if (!self::uuid($id)) { $db->rollback(); Http::error(404, Http::MSG_NOT_FOUND); }
            $lockSql = 'SELECT id FROM community.memory_posts WHERE id=CAST(:id AS uuid) FOR UPDATE';
            $db->run($lockSql, [':id' => $id]);
        }
        $old = $id ? self::row($id, $user, null, true) : null;
        if ($old && (!is_int($body['revision'] ?? null) || $body['revision'] !== (int) $old['revision'])) {
            $db->rollback(); Http::error(409, '다른 화면에서 바뀐 기록이에요. 새로 열어 내용을 확인해 주세요.');
        }
        if (!$old) {
            if (!self::uuid($body['request_id'] ?? null)) { $db->rollback(); Http::error(400, Http::MSG_BAD_REQUEST); }
            $prior = $db->run('SELECT id FROM community.memory_posts WHERE owner_user_id=:owner AND request_id=CAST(:request AS uuid)',
                [':owner' => (int) $user['id'], ':request' => $body['request_id']])->fetchColumn();
            if ($prior) { $db->commit(); return ['id' => $prior, 'replayed' => true]; }
        }
        $id = $id ?? tbox_uuid_v4();
        foreach ($body['media_ids'] as $mediaId) {
            $media = $db->run('SELECT owner_user_id,post_id FROM community.memory_media WHERE id=CAST(:id AS uuid) FOR UPDATE',
                [':id' => $mediaId])->fetch();
            if (!$media || (int) $media['owner_user_id'] !== (int) $user['id'] || ($media['post_id'] !== null && $media['post_id'] !== $id)) {
                $db->rollback(); Http::error(400, '이 기록에 사용할 수 없는 첨부파일이에요. 다시 골라 주세요.');
            }
        }
        try {
            $story = MemoryStory::validate(array_key_exists('story',$body) ? $body['story'] : ($old && isset($old['story']) ? json_decode($old['story'],true) : null), $body['media_ids']);
        } catch (InvalidArgumentException $e) {
            $db->rollback(); Http::error(409, '사진 순서와 이야기 구성을 확인해 주세요. 구성을 해제한 뒤 사진을 바꿀 수 있어요.');
        }
        // Narrowing a post also removes discovery, including edits from older clients.
        $discoverable = in_array($body['visibility'], ['community', 'shared'], true)
            && ($body['discoverable'] ?? (bool) ($old['discoverable'] ?? false));
        $bind = [':id' => $id, ':caption' => $body['caption'], ':location' => $body['location'], ':trip' => $body['trip'],
            ':taken' => $body['taken_on'], ':visibility' => $body['visibility'], ':discoverable' => $discoverable ? 'true' : 'false',
            ':media' => json_encode($body['media_ids']), ':story' => $story===null ? null : json_encode($story,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
        if ($old) {
            $sql = <<<'SQL'
                UPDATE community.memory_posts SET caption=:caption,location=:location,trip=:trip,taken_on=:taken,
                visibility=:visibility,discoverable=CAST(:discoverable AS boolean),media_ids=CAST(:media AS jsonb),story=CAST(:story AS jsonb),revision=revision+1,updated_at=now() WHERE id=CAST(:id AS uuid)
                SQL;
        } else {
            $sql = <<<'SQL'
                INSERT INTO community.memory_posts(id,owner_user_id,caption,original_caption,location,trip,taken_on,visibility,discoverable,media_ids,original_media_ids,request_id,story)
                VALUES(CAST(:id AS uuid),:owner,:caption,:original,:location,:trip,:taken,:visibility,CAST(:discoverable AS boolean),CAST(:media AS jsonb),CAST(:original_media AS jsonb),CAST(:request AS uuid),CAST(:story AS jsonb))
                SQL;
            $bind += [':owner' => (int) $user['id'], ':original' => $body['caption'], ':original_media' => json_encode($body['media_ids']), ':request' => $body['request_id']];
        }
        $db->run($sql, $bind);
        if ($old && $old['visibility'] === 'shared' && $body['visibility'] !== 'shared') {
            $revokeSql = 'UPDATE community.memory_links SET revoked_at=now() WHERE post_id=CAST(:post AS uuid) AND revoked_at IS NULL';
            $db->run($revokeSql, [':post' => $id]);
        }
        foreach ($body['media_ids'] as $mediaId) {
            $db->run('UPDATE community.memory_media SET post_id=CAST(:post AS uuid) WHERE id=CAST(:id AS uuid)',
                [':post' => $id, ':id' => $mediaId]);
        }
        self::audit($old ? 'update' : 'create');
        $db->commit();
        return ['id' => $id];
    }

    public static function directory(): string
    {
        $dir = Storage::baseDir() . '/memories';
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) { throw new RuntimeException('Cannot create memory storage'); }
        if (is_link($dir) || realpath($dir) !== $dir) { throw new RuntimeException('Invalid memory storage'); }
        return $dir;
    }

    public static function upload(array $user): array
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || !is_string($file['name'] ?? null)
            || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            Http::error(400, '파일을 받지 못했어요. 파일 크기와 인터넷 연결을 확인해 주세요.');
        }
        $bytes = filesize($file['tmp_name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!in_array($mime, self::TYPES, true)) { Http::error(400, 'JPG, PNG, WebP, GIF 사진이나 MP4, MOV, WebM 영상을 골라 주세요. HEIC 사진은 JPG로 내보내 주세요.'); }
        $isPhoto = str_starts_with($mime, 'image/');
        if (!$bytes || $bytes > ($isPhoto ? 20971520 : 104857600)) { Http::error(413, '사진은 20MB, 영상은 100MB까지 올릴 수 있어요.'); }
        if ($isPhoto) {
            $size = getimagesize($file['tmp_name']);
            if (!$size || $size[0] * $size[1] > 50000000) { Http::error(400, '이 사진을 읽을 수 없거나 해상도가 너무 높아요. 작게 내보낸 사진을 골라 주세요.'); }
        }
        $db = Db::instance();
        $db->begin();
        $db->run('SELECT id FROM public.users WHERE id=:owner FOR UPDATE',
                [':owner' => (int) $user['id']]);
        $used = (int) $db->run('SELECT coalesce(sum(bytes),0) FROM community.memory_media WHERE owner_user_id=:owner',
                [':owner' => (int) $user['id']])->fetchColumn();
        if ($used + $bytes > 2147483648) { $db->rollback(); Http::error(413, '여행 앨범의 저장 공간(2GB)이 가득 찼어요. 관리자에게 문의해 주세요.'); }
        $id = tbox_uuid_v4();
        $path = self::directory() . '/' . $id;
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', basename(str_replace('\\', '/', $file['name'])));
        if (!is_string($name) || preg_match('//u', $name) !== 1) { $name = 'memory'; }
        $name = iconv_substr($name, 0, 200, 'UTF-8') ?: 'memory';
        if (!move_uploaded_file($file['tmp_name'], $path)) { $db->rollback(); throw new RuntimeException('Memory upload failed'); }
        chmod($path, 0600);
        if ($isPhoto) {
            // Q:F-13 G-2 — 저장하는 판에서 위치·촬영 정보(EXIF·GPS·XMP·IPTC·주석)를 뗀다. 크기·지문은 뗀 판으로 다시 잰다.
            try {
                self::stripPhotoMetadata($path, $mime);
            } catch (Throwable $e) {
                $db->rollback();
                if (is_file($path)) { unlink($path); }
                Http::error(400, '이 사진을 읽을 수 없어요. 다른 사진을 골라 주세요.');
            }
            clearstatcache(true, $path);
            $bytes = (int) filesize($path);
        }
        $digest = hash_file('sha256', $path);
        if ($digest === false) { $db->rollback(); unlink($path); throw new RuntimeException('Cannot hash memory media'); }
        try {
            $sql = <<<'SQL'
                INSERT INTO community.memory_media(id,owner_user_id,mime,filename,bytes,sha256) VALUES(CAST(:id AS uuid),:owner,:mime,:filename,:bytes,:sha256)
                SQL;
            $db->run($sql, [':id' => $id, ':owner' => (int) $user['id'], ':mime' => $mime, ':filename' => $name, ':bytes' => $bytes, ':sha256' => $digest]);
            self::audit('upload');
            $db->commit();
        } catch (Throwable $e) { $db->rollback(); unlink($path); throw $e; }
        return ['id' => $id, 'mime' => $mime, 'filename' => $name, 'bytes' => $bytes, 'url' => Http::communityUrl('/api/memories/media/' . $id)];
    }

    /**
     * Q:F-13 G-2 (기획 ../docs/privacy-terms-plan.md 5.2 「G-2 사진 위치 정보」) — 사진 기록에 **올리는** 사진의 메타데이터를 뗀 판을 저장한다.
     *
     *   JPEG  조각 단위로 **무손실**: APP1(Exif · XMP — GPS 가 여기 든다) · APP13(IPTC·Photoshop) · COM(주석) · APP2 MPF(여러 장 색인) ·
     *         그 밖 APPn(APP0 JFIF · APP2 ICC 색 프로필 · APP14 Adobe 색 변환만 남긴다)을 빼고, 영상 데이터(스캔)는 바이트 그대로 옮긴다.
     *         첫 그림의 EOI 뒤(휴대폰이 붙이는 둘째 그림·썸네일 — 그 안에 또 Exif 가 있다)는 버린다. 방향(Orientation)만은 화면이
     *         옆으로 누워 보이지 않게 **그 한 칸만 든 Exif** 로 다시 넣는다(위치·기기·시각 0).
     *   PNG   덩어리 단위로 무손실: tEXt · zTXt · iTXt(XMP) · eXIf · tIME 를 빼고 IEND 뒤를 버린다(그림 덩어리 IDAT 는 바이트 그대로).
     *   WebP  RIFF 덩어리 단위로 무손실: EXIF · XMP 를 빼고 VP8X 의 두 표시 비트를 끈다.
     *   GIF   표준 위치 칸이 없어 손대지 않는다(움직이는 GIF 를 다시 인코딩하면 움직임이 사라진다).
     *   위 해석이 실패한 깨진 파일은 GD 로 **다시 인코딩**해 메타데이터를 남기지 않는다(화질은 조금 달라진다) — 그것도 안 되면 예외(→ 400).
     * 영상(MP4·MOV·WebM)은 이번 범위 밖이다(사용자 데이터 변경 · 따로 묻는다). 이미 저장된 옛 사진은 고치지 않는다(새로 올리는 것만).
     */
    public static function stripPhotoMetadata(string $path, string $mime): void
    {
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return;
        }
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Memory photo is not a regular file');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Cannot read memory photo');
        }
        $clean = match ($mime) {
            'image/jpeg' => self::stripJpeg($bytes),
            'image/png'  => self::stripPng($bytes),
            'image/webp' => self::stripWebp($bytes),
        };
        if ($clean === null) {
            $clean = self::reencodePhoto($bytes, $mime);
        }
        if ($clean === $bytes) {
            return;
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.part';
        $old = umask(0077);
        try {
            $fh = fopen($tmp, 'x');
        } finally {
            umask($old);
        }
        if ($fh === false) {
            throw new RuntimeException('Cannot write memory photo');
        }
        $ok = fwrite($fh, $clean) === strlen($clean) && fflush($fh);
        fclose($fh);
        if (!$ok || !rename($tmp, $path)) {
            if (is_file($tmp)) { unlink($tmp); }
            throw new RuntimeException('Cannot replace memory photo');
        }
    }

    /** JPEG 조각 거르기 — 해석할 수 없으면 null(부르는 쪽이 다시 인코딩) */
    private static function stripJpeg(string $b): ?string
    {
        $len = strlen($b);
        if ($len < 4 || substr($b, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $head = "\xFF\xD8";   // SOI + APP0(JFIF) — 방향 Exif 는 이 뒤에 넣는다
        $body = '';
        $orientation = null;
        $pos = 2;
        $seenOther = false;
        while ($pos < $len) {
            if (ord($b[$pos]) !== 0xFF) {
                return null;
            }
            while ($pos < $len && ord($b[$pos]) === 0xFF) {
                $pos++;
            }
            if ($pos >= $len) {
                return null;
            }
            $marker = ord($b[$pos]);
            $pos++;
            if ($marker === 0xD9) {                       // EOI — 첫 그림의 끝. 그 뒤(둘째 그림·덧붙인 자료)는 버린다
                $exif = ($orientation !== null && $orientation >= 2 && $orientation <= 8) ? self::orientationExif($orientation) : '';
                return $head . $exif . $body . "\xFF\xD9";
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $body .= "\xFF" . chr($marker);
                continue;
            }
            if ($marker === 0xD8 || $pos + 2 > $len) {
                return null;
            }
            $segLen = unpack('n', substr($b, $pos, 2))[1];
            if ($segLen < 2 || $pos + $segLen > $len) {
                return null;
            }
            $payload = substr($b, $pos + 2, $segLen - 2);
            $segment = "\xFF" . chr($marker) . substr($b, $pos, $segLen);
            $pos += $segLen;
            if ($marker >= 0xE0 && $marker <= 0xEF) {     // APPn
                if ($marker === 0xE1 && strncmp($payload, "Exif\0\0", 6) === 0 && $orientation === null) {
                    $orientation = self::exifOrientation(substr($payload, 6));
                }
                $keep = ($marker === 0xE0) || ($marker === 0xEE)
                    || ($marker === 0xE2 && strncmp($payload, "ICC_PROFILE\0", 12) === 0);
                if (!$keep) {
                    continue;                              // Exif · XMP · IPTC · MPF · 그 밖 제조사 조각
                }
                if ($marker === 0xE0 && !$seenOther) {
                    $head .= $segment;
                    continue;
                }
                $body .= $segment;
                continue;
            }
            if ($marker === 0xFE) {                        // COM — 주석
                continue;
            }
            $seenOther = true;
            $body .= $segment;
            if ($marker === 0xDA) {                        // SOS — 스캔 데이터는 다음 진짜 표지까지 바이트 그대로
                $start = $pos;
                while (true) {
                    $ff = strpos($b, "\xFF", $pos);
                    if ($ff === false || $ff + 1 >= $len) {
                        return null;
                    }
                    $next = ord($b[$ff + 1]);
                    if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                        $pos = $ff + 2;
                        continue;
                    }
                    if ($next === 0xFF) {
                        $pos = $ff + 1;
                        continue;
                    }
                    $body .= substr($b, $start, $ff - $start);
                    $pos = $ff;
                    break;
                }
            }
        }
        return null;
    }

    /** Exif(TIFF) 의 IFD0 에서 Orientation(0x0112) 한 칸만 읽는다 — 없거나 모양이 틀리면 null */
    private static function exifOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $u16 = static fn (int $at): int => unpack($order === 'II' ? 'v' : 'n', substr($tiff, $at, 2))[1];
        $u32 = static fn (int $at): int => unpack($order === 'II' ? 'V' : 'N', substr($tiff, $at, 4))[1];
        if ($u16(2) !== 42) {
            return null;
        }
        $ifd = $u32(4);
        if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
            return null;
        }
        $count = $u16($ifd);
        for ($i = 0; $i < $count && $i < 512; $i++) {
            $at = $ifd + 2 + $i * 12;
            if ($at + 12 > strlen($tiff)) {
                return null;
            }
            if ($u16($at) === 0x0112 && $u16($at + 2) === 3 && $u32($at + 4) === 1) {
                $value = $u16($at + 8);
                return ($value >= 1 && $value <= 8) ? $value : null;
            }
        }
        return null;
    }

    /** 방향 한 칸만 든 최소 Exif APP1 조각(빅 엔디언 TIFF · IFD0 한 칸 · 다음 IFD 없음) */
    private static function orientationExif(int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $payload = "Exif\0\0" . $tiff;
        return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    }

    /** PNG 덩어리 거르기 — 해석할 수 없으면 null */
    private static function stripPng(string $b): ?string
    {
        $sig = "\x89PNG\r\n\x1a\n";
        $len = strlen($b);
        if ($len < 8 || substr($b, 0, 8) !== $sig) {
            return null;
        }
        $out = $sig;
        $pos = 8;
        while ($pos + 12 <= $len) {
            $size = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            if ($size > $len - $pos - 12 || preg_match('/\A[A-Za-z]{4}\z/', $type) !== 1) {
                return null;
            }
            $chunk = substr($b, $pos, 12 + $size);
            $pos += 12 + $size;
            if (in_array($type, ['tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME'], true)) {
                continue;
            }
            $out .= $chunk;
            if ($type === 'IEND') {
                return $out;
            }
        }
        return null;
    }

    /** WebP(RIFF) 덩어리 거르기 — 해석할 수 없으면 null */
    private static function stripWebp(string $b): ?string
    {
        $len = strlen($b);
        if ($len < 20 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
            return null;
        }
        $end = min($len, 8 + unpack('V', substr($b, 4, 4))[1]);
        $out = '';
        $pos = 12;
        while ($pos + 8 <= $end) {
            $fourcc = substr($b, $pos, 4);
            $size = unpack('V', substr($b, $pos + 4, 4))[1];
            if ($size > $end - $pos - 8) {
                return null;
            }
            $data = substr($b, $pos + 8, $size);
            $pos += 8 + $size + ($size & 1);
            if ($fourcc === 'EXIF' || $fourcc === 'XMP ') {
                continue;
            }
            if ($fourcc === 'VP8X' && $size >= 10) {
                $data[0] = chr(ord($data[0]) & ~0x0C);     // EXIF(0x08) · XMP(0x04) 표시 끄기
            }
            $out .= $fourcc . pack('V', $size) . $data . (($size & 1) === 1 ? "\0" : '');
        }
        if ($out === '') {
            return null;
        }
        return 'RIFF' . pack('V', 4 + strlen($out)) . 'WEBP' . $out;
    }

    /** 해석이 안 되는 사진 — GD 로 다시 인코딩(메타데이터는 따라오지 않는다). 그것도 안 되면 예외 */
    private static function reencodePhoto(string $bytes, string $mime): string
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Image library unavailable');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new RuntimeException('Cannot decode memory photo');
        }
        if ($mime !== 'image/jpeg') {
            imagesavealpha($image, true);
        }
        ob_start();
        try {
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 92),
                'image/png'  => imagepng($image),
                'image/webp' => imagewebp($image, null, 90),
            };
            $out = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (!$ok || $out === '') {
            throw new RuntimeException('Cannot encode memory photo');
        }
        return $out;
    }

    public static function stream(string $id, ?array $user, ?array $link = null): void
    {
        if (!self::uuid($id)) { Http::error(404, Http::MSG_NOT_FOUND); }
        $media = Db::instance()->run('SELECT * FROM community.memory_media WHERE id=CAST(:id AS uuid)',
                [':id' => $id])->fetch();
        if (!$media) { Http::error(404, Http::MSG_NOT_FOUND); }
        $owner = $link === null && $user !== null && (int) $media['owner_user_id'] === (int) $user['id'];
        if ($media['post_id'] !== null) {
            $post = self::row($media['post_id'], $user, $link);
            if (!$owner && !in_array($id, json_decode($post['media_ids'], true), true)) { Http::error(404, Http::MSG_NOT_FOUND); }
        } elseif (!$owner) { Http::error(404, Http::MSG_NOT_FOUND); }
        $path = self::directory() . '/' . $id;
        if (!is_file($path) || is_link($path)) { Http::error(404, Http::MSG_NOT_FOUND); }
        $size = filesize($path);
        $start = 0; $end = $size - 1;
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range !== '') {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $m) || ($m[1] === '' && $m[2] === '')) {
                header('Content-Range: bytes */' . $size); http_response_code(416); exit;
            }
            if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
            else { $start = (int) $m[1]; if ($m[2] !== '') { $end = min($end, (int) $m[2]); } }
            if ($start > $end || $start >= $size) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Content-Type: ' . $media['mime']);
        header('Content-Length: ' . ($end - $start + 1));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('X-Content-Type-Options: nosniff');
        $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
        header('Content-Disposition: ' . $disposition . '; filename="memory"; filename*=UTF-8\'\'' . rawurlencode($media['filename']));
        $handle = fopen($path, 'rb');
        if (!$handle) { throw new RuntimeException('Cannot open memory media'); }
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') { break; }
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($handle);
        exit;
    }

    public static function audit(string $operation): void
    {
        Audit::allow('memory.change', ['target_type' => 'system', 'target_name' => '여행 기록', 'detail' => ['operation' => $operation]]);
    }
}
