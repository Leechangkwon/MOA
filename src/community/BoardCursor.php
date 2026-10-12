<?php
declare(strict_types=1);

/** 위치 정보만 담는다. 커서를 받아도 게시판/각 행 권한은 매번 원래 조건절로 판정한다. */
final class BoardCursor
{
    private const AAD = 'qaredi.board.cursor.v1';

    private static function filter(string $q, string $tag): string
    {
        return hash('sha256', json_encode([$q, $tag], JSON_THROW_ON_ERROR));
    }

    public static function encode(int $board, string $sort, string $q, string $tag, string $direction, array $row): string
    {
        $keys = $sort === 'views'
            ? [(int) $row['views'], self::date((string) $row['posted_at']), (string) $row['id']]
            : [self::date((string) $row[$sort === 'new' ? 'posted_at' : 'updated_at']), (string) $row['id']];
        $data = ['board' => $board, 'sort' => $sort, 'filter' => self::filter($q, $tag), 'direction' => $direction, 'keys' => $keys];
        $iv = random_bytes(12);
        $key = hash('sha256', self::AAD . Env::get('APP_SECRET'), true);
        $cipher = openssl_encrypt(json_encode($data, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $authTag, self::AAD, 16);
        if ($cipher === false) { throw new RuntimeException('커서 생성 실패'); }
        return 'v1.' . rtrim(strtr(base64_encode($iv . $authTag . $cipher), '+/', '-_'), '=');
    }

    private static function date(string $value): string
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function decode(mixed $encoded, int $board, string $sort, string $q, string $tag): ?array
    {
        if ($encoded === null) { return null; }
        if (!is_string($encoded) || strlen($encoded) > 768 || preg_match('/^v1\.[A-Za-z0-9_-]{40,765}$/D', $encoded) !== 1) { self::invalid(); }
        $payload = substr($encoded, 3);
        $bytes = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($bytes === false || strlen($bytes) < 29 || rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') !== $payload) { self::invalid(); }
        $key = hash('sha256', self::AAD . Env::get('APP_SECRET'), true);
        $json = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), self::AAD);
        if ($json === false) { self::invalid(); }
        $data = json_decode($json, true, 8);
        if (!is_array($data) || array_keys($data) !== ['board','sort','filter','direction','keys']
            || $data['board'] !== $board || $data['sort'] !== $sort || $data['filter'] !== self::filter($q, $tag)
            || !in_array($data['direction'], ['next','prev'], true) || !is_array($data['keys'])
            || array_keys($data['keys']) !== ($sort === 'views' ? [0,1,2] : [0,1])) { self::invalid(); }
        $keys = $data['keys'];
        if ($sort === 'views' && (!is_int($keys[0]) || $keys[0] < 0 || $keys[0] > 2147483647)) { self::invalid(); }
        $time = $keys[$sort === 'views' ? 1 : 0]; $id = $keys[$sort === 'views' ? 2 : 1];
        if (!is_string($id) || preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1 || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false
            || !is_string($time) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\+00:00$/D', $time) !== 1) { self::invalid(); }
        try { $dt = new DateTimeImmutable($time); $errors = DateTimeImmutable::getLastErrors(); }
        catch (Exception $e) { self::invalid(); }
        if (($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || (int) $dt->format('Y') < 1 || self::date($time) !== $time) { self::invalid(); }
        return $data;
    }

    private static function invalid(): never
    {
        Http::error(400, Http::MSG_BAD_REQUEST);
        exit;
    }

    /** 코드의 허용 목록만 SQL 조각을 정한다. 값은 전부 바인딩한다. */
    public static function sql(array $user, int $board, string $sort, string $q, string $tag, ?array $cursor, int $limit = 20): array
    {
        if (!in_array($sort, ['new','updated','views'], true) || $limit < 1 || $limit > 20) { throw new LogicException('게시판 정렬/크기 오류'); }
        [$visible, $bind] = community_visible_pages_where($user);
        $direction = ($cursor['direction'] ?? 'next') === 'prev' ? 'ASC' : 'DESC';
        $op = $direction === 'ASC' ? '>' : '<';
        $branches = [];
        foreach ([1,2] as $branch) {
            $posted = $branch === 1 ? 'p.created_at' : 'p.published_at';
            $field = $sort === 'updated' ? 'p.updated_at' : $posted;
            $cols = $sort === 'views' ? ['p.view_count', $posted, 'p.id'] : [$field, 'p.id'];
            $bind[':board' . $branch] = $board;
            $scope = $branch === 1 ? 'p.space_id = CAST(:board1 AS bigint)'
                : "p.published_board_id = CAST(:board2 AS bigint) AND p.visibility = 'published' AND p.space_id <> CAST(:different AS bigint)";
            if ($branch === 2) { $bind[':different'] = $board; }
            $filter = '';
            if ($q !== '') {
                $like = '%' . str_replace(['#','%','_'], ['##','#%','#_'], $q) . '%';
                $filter .= " AND (p.title ILIKE :title{$branch} ESCAPE '#' OR p.plain_text ILIKE :text{$branch} ESCAPE '#')";
                $bind[':title'.$branch] = $like; $bind[':text'.$branch] = $like;
            }
            if ($tag !== '') {
                $filter .= " AND EXISTS (SELECT 1 FROM community.page_tags tf WHERE tf.page_id=p.id AND tf.tag_norm=lower(CAST(
:tag{$branch} AS text)))";
                $bind[':tag'.$branch] = $tag;
            }
            if ($cursor !== null) {
                $slots = [];
                foreach ($cursor['keys'] as $i => $value) {
                    $name = ':key'.$branch.'_'.$i;
                    $type = $i === count($cols)-1 || ($sort === 'views' && $i === 0) ? 'bigint' : 'timestamptz';
                    $slots[] = 'CAST('.$name.' AS '.$type.')'; $bind[$name] = $value;
                }
                $filter .= ' AND ('.implode(',',$cols).') '.$op.' ('.implode(',',$slots).')';
            }
            $order = implode(', ', array_map(static fn(string $col): string => $col.' '.$direction, $cols));
            // 후보 단계는 정렬 색인에 들어 있는 값만 읽는다. 본문/다른 정렬 값은 권한 판정 뒤 가져온다.
            $projection = $sort === 'updated' ? 'p.updated_at' : $posted . ' AS posted_at';
            if ($sort === 'views') { $projection .= ', p.view_count AS views'; }
            $branches[] = "(SELECT p.id,
{$projection}
FROM community.pages p WHERE
{$scope} AND p.deleted_at IS NULL AND p.hidden_at IS NULL
{$filter}
ORDER BY
{$order})";
        }
        $union = implode(' UNION ALL ', $branches);
        $outerCols = $sort === 'views' ? ['h.views','h.posted_at','h.id'] : [$sort === 'updated' ? 'h.updated_at' : 'h.posted_at','h.id'];
        $outerOrder = implode(', ', array_map(static fn(string $col): string => $col.' '.$direction, $outerCols));
        $select = $sort === 'updated' ? 'h.id, h.updated_at' : 'h.id, h.posted_at';
        if ($sort === 'views') { $select .= ', h.views'; }
        $details = $sort === 'views' ? 'p.updated_at,' : 'p.view_count AS views,';
        if ($sort === 'new') { $details .= 'p.updated_at,'; }
        if ($sort === 'updated') {
            $details .= 'CASE WHEN p.space_id=CAST(:return_board AS bigint) THEN p.created_at ELSE p.published_at END AS posted_at,';
            $bind[':return_board'] = $board;
        }
        $sql = <<<SQL
            SELECT
                   {$select}, v.*,
                   (SELECT json_agg(t.tag ORDER BY t.tag_norm) FROM community.page_tags t WHERE t.page_id=h.id) AS tags
              FROM ({$union}) h
              CROSS JOIN LATERAL (
                SELECT
                       {$details}
                       p.public_id,p.title,p.icon,p.visibility,p.created_at,p.resolved,s.kind AS space_kind,
                       CASE WHEN s.kind='board' THEN au.username ELSE owner.username END AS writer_name,
                       CASE WHEN s.kind='board' THEN au.display_name ELSE owner.display_name END AS writer_display_name,
                       left(p.plain_text,160) AS excerpt
                  FROM community.pages p
                  JOIN community.spaces s ON s.id=p.space_id
                  LEFT JOIN public.users owner ON owner.id=s.owner_user_id
                  LEFT JOIN public.users au ON au.id=p.author_id
                 WHERE p.id=h.id AND
                 {$visible}
                 OFFSET 0
              ) v
             ORDER BY
             {$outerOrder}
             LIMIT CAST(:limit AS int)
            SQL;
        $bind[':limit'] = $limit + 1;
        return [$sql, $bind];
    }
}
