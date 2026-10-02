<?php
declare(strict_types=1);

/** Travel records are separate from business documents and their file permissions. */
final class Memories
{
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime'];

    public static function ready(): bool
    {
        return (bool) Db::instance()->run("SELECT to_regclass('community.memory_links') IS NOT NULL AS ready")->fetchColumn();
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

    public static function body(): array
    {
        $body = Http::jsonBody(40000, 8);
        $allowed = ['caption', 'location', 'trip', 'taken_on', 'visibility', 'media_ids', 'revision', 'request_id'];
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
        if (!$date || (int) $date->format('Y') < 1 || $date->format('Y-m-d') !== $body['taken_on'] || !in_array($body['visibility'], ['private', 'community', 'shared'], true)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
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
        return $user !== null && ((int) $post['owner_user_id'] === (int) $user['id'] || $post['visibility'] !== 'private');
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

    public static function output(array $row, ?array $user, string $prefix): array
    {
        $owner = $user !== null && (int) $row['owner_user_id'] === (int) $user['id'];
        $sql = <<<'SQL'
            SELECT (SELECT count(*) FROM community.memory_likes WHERE post_id=CAST(:liked AS uuid)) AS likes,
                   (SELECT count(*) FROM community.memory_comments WHERE post_id=CAST(:commented AS uuid)) AS comments,
                   EXISTS(SELECT 1 FROM community.memory_likes WHERE post_id=CAST(:post AS uuid) AND user_id=:viewer) AS liked
            SQL;
        $counts = Db::instance()->run($sql, [':liked' => $row['id'], ':commented' => $row['id'], ':post' => $row['id'], ':viewer' => (int) ($user['id'] ?? 0)])->fetch();
        $out = array_intersect_key($row, array_flip(['id', 'caption', 'location', 'trip', 'taken_on', 'visibility', 'created_at', 'username', 'display_name']));
        $out += ['mine' => $owner, 'revision' => (int) $row['revision'], 'media' => self::mediaList(json_decode($row['media_ids'], true), $prefix),
            'likes' => (int) $counts['likes'], 'comments' => (int) $counts['comments'], 'liked' => (bool) $counts['liked']];
        if ($owner) {
            $out['original_caption'] = $row['original_caption'];
            $out['original_media'] = self::mediaList(json_decode($row['original_media_ids'], true), $prefix);
        }
        return $out;
    }

    public static function feed(?array $user, ?array $link = null, string $token = ''): array
    {
        $where = ['p.deleted_at IS NULL', 'u.is_active', '(u.expires_at IS NULL OR u.expires_at>now())'];
        $bind = [];
        if ($link) {
            $where[] = "p.visibility='shared' AND p.owner_user_id=:owner";
            $bind[':owner'] = (int) $link['owner_user_id'];
            if ($link['post_id'] !== null) { $where[] = 'p.id=CAST(:shared_post AS uuid)'; $bind[':shared_post'] = $link['post_id']; }
        } else {
            $where[] = "(p.owner_user_id=:viewer OR p.visibility IN ('community','shared'))";
            $bind[':viewer'] = (int) $user['id'];
            if (($_GET['view'] ?? '') === 'mine') { $where[] = 'p.owner_user_id=:mine'; $bind[':mine'] = (int) $user['id']; }
            $profile = $_GET['profile'] ?? '';
            if ($profile !== '') { $where[] = 'u.username=:profile'; $bind[':profile'] = self::text($profile, 64); }
        }
        $search = $_GET['q'] ?? '';
        if ($search !== '') {
            $where[] = "strpos(lower(p.caption || ' ' || p.location || ' ' || p.trip),lower(:search))>0";
            $bind[':search'] = self::text($search, 100);
        }
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0 || $offset > 10000) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $base = ' FROM community.memory_posts p JOIN public.users u ON u.id=p.owner_user_id WHERE ';
        $base .= implode(' AND ', $where);
        $statsSql = 'SELECT count(*) AS posts, count(DISTINCT NULLIF(p.trip,\'\')) AS trips, coalesce(sum(jsonb_array_length(p.media_ids)),0) AS moments';
        $stats = Db::instance()->run($statsSql . $base, $bind)->fetch();
        $query = 'SELECT p.*,u.username,u.display_name';
        $query .= $base . ' ORDER BY p.created_at DESC,p.id DESC LIMIT 21 OFFSET CAST(:offset AS integer)';
        $rows = Db::instance()->run($query, $bind + [':offset' => $offset])->fetchAll();
        $more = count($rows) > 20;
        $prefix = Http::communityUrl($link ? '/memories/s/' . $token . '/media/' : '/api/memories/media/');
        $out = ['posts' => array_map(static fn ($r) => self::output($r, $user, $prefix), array_slice($rows, 0, 20)),
            'next_offset' => $more ? $offset + 20 : null, 'stats' => array_map('intval', $stats)];
        if ($user) {
            $out['me'] = ['username' => $user['username'], 'display_name' => $user['display_name'] ?? null, 'can_write' => Authz::canCommunityWrite($user), 'csrf_token' => Auth::csrfToken()];
        } else {
            $out['profile'] = ['username' => $link['username'], 'display_name' => $link['display_name']];
        }
        return $out;
    }

    public static function save(array $user, ?string $id): array
    {
        $body = self::body();
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
        $bind = [':id' => $id, ':caption' => $body['caption'], ':location' => $body['location'], ':trip' => $body['trip'],
            ':taken' => $body['taken_on'], ':visibility' => $body['visibility'], ':media' => json_encode($body['media_ids'])];
        if ($old) {
            $sql = <<<'SQL'
                UPDATE community.memory_posts SET caption=:caption,location=:location,trip=:trip,taken_on=:taken,
                visibility=:visibility,media_ids=CAST(:media AS jsonb),revision=revision+1,updated_at=now() WHERE id=CAST(:id AS uuid)
                SQL;
        } else {
            $sql = <<<'SQL'
                INSERT INTO community.memory_posts(id,owner_user_id,caption,original_caption,location,trip,taken_on,visibility,media_ids,original_media_ids,request_id)
                VALUES(CAST(:id AS uuid),:owner,:caption,:original,:location,:trip,:taken,:visibility,CAST(:media AS jsonb),CAST(:original_media AS jsonb),CAST(:request AS uuid))
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
