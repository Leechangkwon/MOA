<?php
declare(strict_types=1);

/** Relationship edges never make private posts readable and never grant link access. */
final class MemorySocial
{
    public const VISIBILITIES = ['private', 'followers', 'community', 'shared'];

    public static function follows(int $viewer, int $owner): bool
    {
        if ($viewer === $owner || $viewer <= 0) { return false; }
        $sql = 'SELECT EXISTS(SELECT 1 FROM community.memory_follows WHERE follower_user_id=:viewer AND followed_user_id=:owner)';
        return (bool) Db::instance()->run($sql, [':viewer' => $viewer, ':owner' => $owner])->fetchColumn();
    }

    public static function member(string $username): array
    {
        $sql = 'SELECT id,username,display_name FROM public.users WHERE username=:name AND is_active AND (expires_at IS NULL OR expires_at>now())';
        $row = Db::instance()->run($sql, [':name' => $username])->fetch();
        if (!$row) { Http::error(404, Http::MSG_NOT_FOUND); }
        return $row;
    }

    public static function profile(array $member, array $viewer): array
    {
        $sql = <<<'SQL'
            SELECT (SELECT count(*) FROM community.memory_follows f JOIN public.users u ON u.id=f.follower_user_id
                    WHERE f.followed_user_id=:incoming AND u.is_active AND (u.expires_at IS NULL OR u.expires_at>now())) AS followers_count,
                   (SELECT count(*) FROM community.memory_follows f JOIN public.users u ON u.id=f.followed_user_id
                    WHERE f.follower_user_id=:outgoing AND u.is_active AND (u.expires_at IS NULL OR u.expires_at>now())) AS following_count
            SQL;
        $counts = Db::instance()->run($sql, [':incoming' => (int) $member['id'], ':outgoing' => (int) $member['id']])->fetch();
        return ['username' => $member['username'], 'display_name' => $member['display_name'] ?? null,
            'mine' => (int) $member['id'] === (int) $viewer['id'],
            'following' => self::follows((int) $viewer['id'], (int) $member['id']),
            'followers_count' => (int) $counts['followers_count'], 'following_count' => (int) $counts['following_count']];
    }

    public static function defaultVisibility(int $user): string
    {
        $sql = 'SELECT default_visibility FROM community.memory_preferences WHERE user_id=:user';
        return Db::instance()->run($sql, [':user' => $user])->fetchColumn() ?: 'private';
    }

    public static function preferences(array $user): array
    {
        $body = Http::jsonBody(2000, 3);
        if (array_keys($body) !== ['default_visibility'] || !in_array($body['default_visibility'], self::VISIBILITIES, true)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $db = Db::instance(); $db->begin();
        $sql = <<<'SQL'
            INSERT INTO community.memory_preferences(user_id,default_visibility) VALUES(:user,:visibility)
            ON CONFLICT(user_id) DO UPDATE SET default_visibility=excluded.default_visibility,updated_at=now()
            SQL;
        $db->run($sql, [':user' => (int) $user['id'], ':visibility' => $body['default_visibility']]);
        Memories::audit('preferences'); $db->commit();
        return ['default_visibility' => $body['default_visibility']];
    }

    public static function change(array $user, bool $follow): array
    {
        $body = Http::jsonBody(2000, 3);
        if (array_keys($body) !== ['username']) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $member = self::member(Memories::text($body['username'], 64));
        if ((int) $member['id'] === (int) $user['id']) { Http::error(400, '내 계정은 팔로우할 필요가 없어요.'); }
        $db = Db::instance(); $db->begin();
        // Bound the graph and serialize idempotent follow/unfollow for this actor.
        $lock = 'SELECT id FROM public.users WHERE id=:actor FOR UPDATE';
        $db->run($lock, [':actor' => (int) $user['id']]);
        $rate = <<<'SQL'
            INSERT INTO community.memory_preferences(user_id,follow_window_count) VALUES(:actor,1)
            ON CONFLICT(user_id) DO UPDATE SET
              follow_window_count=CASE WHEN community.memory_preferences.follow_window_started_at<now()-interval '10 minutes'
                                      THEN 1 ELSE community.memory_preferences.follow_window_count+1 END,
              follow_window_started_at=CASE WHEN community.memory_preferences.follow_window_started_at<now()-interval '10 minutes'
                                           THEN now() ELSE community.memory_preferences.follow_window_started_at END
            WHERE community.memory_preferences.follow_window_count<60
               OR community.memory_preferences.follow_window_started_at<now()-interval '10 minutes'
            RETURNING user_id
            SQL;
        if (!$db->run($rate, [':actor' => (int) $user['id']])->fetchColumn()) {
            $db->rollback(); Http::error(429, '팔로우 변경이 많아요. 잠시 후 다시 시도해 주세요.');
        }
        if ($follow && !self::follows((int) $user['id'], (int) $member['id'])) {
            $count = 'SELECT count(*) FROM community.memory_follows WHERE follower_user_id=:actor';
            if ((int) $db->run($count, [':actor' => (int) $user['id']])->fetchColumn() >= 5000) {
                $db->rollback(); Http::error(409, '팔로우는 5,000명까지 할 수 있어요. 목록을 정리한 뒤 다시 시도해 주세요.');
            }
        }
        if ($follow) {
            $sql = 'INSERT INTO community.memory_follows(follower_user_id,followed_user_id) VALUES(:actor,:target) ON CONFLICT DO NOTHING';
        } else {
            $sql = 'DELETE FROM community.memory_follows WHERE follower_user_id=:actor AND followed_user_id=:target';
        }
        $db->run($sql, [':actor' => (int) $user['id'], ':target' => (int) $member['id']]);
        Memories::audit($follow ? 'follow' : 'unfollow'); $db->commit();
        return ['ok' => true, 'profile' => self::profile($member, $user)];
    }

    public static function directory(array $user): array
    {
        $name = Memories::text($_GET['profile'] ?? $user['username'], 64);
        $member = self::member($name);
        $list = $_GET['list'] ?? 'following';
        if (!in_array($list, ['following', 'followers', 'members'], true)) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0 || $offset > 10000) { Http::error(400, Http::MSG_BAD_REQUEST); }
        $search = Memories::text($_GET['q'] ?? '', 100);
        $where = ['u.is_active', '(u.expires_at IS NULL OR u.expires_at>now())'];
        $bind = [':actor' => (int) $user['id']];
        if ($list === 'following') {
            $where[] = 'EXISTS(SELECT 1 FROM community.memory_follows f WHERE f.follower_user_id=:profile AND f.followed_user_id=u.id)';
            $bind[':profile'] = (int) $member['id'];
        } elseif ($list === 'followers') {
            $where[] = 'EXISTS(SELECT 1 FROM community.memory_follows f WHERE f.followed_user_id=:profile AND f.follower_user_id=u.id)';
            $bind[':profile'] = (int) $member['id'];
        } else {
            // Member discovery follows the existing write permission matrix, never a role-name copy.
            $where[] = 'EXISTS(SELECT 1 FROM public.permissions permission WHERE permission.role=u.role AND permission.can_upload)';
        }
        if ($search !== '') {
            $where[] = "strpos(lower(u.username || ' ' || coalesce(u.display_name,'')), lower(:search))>0";
            $bind[':search'] = $search;
        }
        $sql = <<<'SQL'
            SELECT u.id,u.username,u.display_name,
                   EXISTS(SELECT 1 FROM community.memory_follows f WHERE f.follower_user_id=:actor AND f.followed_user_id=u.id) AS following
            FROM public.users u WHERE
            SQL;
        $sql .= ' ' . implode(' AND ', $where);
        $sql .= ' ORDER BY lower(u.username),u.id LIMIT 31 OFFSET CAST(:offset AS integer)';
        $rows = Db::instance()->run($sql, $bind + [':offset' => $offset])->fetchAll();
        $more = count($rows) > 30;
        $members = array_map(static fn ($row) => ['username' => $row['username'], 'display_name' => $row['display_name'],
            'mine' => (int) $row['id'] === (int) $user['id'], 'following' => (bool) $row['following']], array_slice($rows, 0, 30));
        return ['profile' => self::profile($member, $user), 'members' => $members, 'next_offset' => $more ? $offset + 30 : null];
    }

    /** An untrusted cursor only positions an already authorization-filtered query. */
    public static function cursor($encoded, string $scope): ?array
    {
        if ($encoded === null || $encoded === '') { return null; }
        if (!is_string($encoded) || strlen($encoded) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);
        $value = $bytes === false ? null : json_decode($bytes, true);
        if (!is_array($value) || array_keys($value) !== ['scope', 'snapshot', 'rank', 'created', 'id']
            || $value['scope'] !== $scope || !self::timestamp($value['snapshot']) || !self::timestamp($value['created'])
            || !is_string($value['rank']) || preg_match('/^[0-9]{1,12}$/D', $value['rank']) !== 1 || !Memories::uuid($value['id'])) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        return $value;
    }

    public static function timestamp($value): bool
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9:.+Z-]{8,32}$/D', $value) !== 1) { return false; }
        try {
            $date = new DateTimeImmutable($value); $errors = DateTimeImmutable::getLastErrors();
            return ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && (int) $date->format('Y') >= 1970 && (int) $date->format('Y') <= 9999;
        }
        catch (Exception $e) { return false; }
    }

    public static function encodeCursor(string $scope, string $snapshot, array $row): string
    {
        $json = json_encode(['scope' => $scope, 'snapshot' => $snapshot, 'rank' => (string) $row['feed_rank'],
            'created' => $row['created_at'], 'id' => $row['id']], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
