<?php
declare(strict_types=1);
namespace QarediAssistant;

final class Files
{
    private const QUERY = <<<'SQL'
        SELECT f.public_id, f.original_name, f.description, f.access_level,
               f.size_bytes, f.created_at, f.exec_content
          FROM files f
         WHERE
        SQL;

    public static function search(array $user, array $args): array
    {
        [$visible, $bind] = \Authz::visibleFilesWhere($user);
        $q = '%' . strtr($args['query'], ['#' => '##', '%' => '#%', '_' => '#_']) . '%';
        $tail = <<<'SQL'
          AND (f.original_name ILIKE :q_name ESCAPE '#' OR f.description ILIKE :q_desc ESCAPE '#')
          ORDER BY f.created_at DESC, f.public_id DESC LIMIT CAST(:lim AS integer)
        SQL;
        return \Db::instance()->run(self::QUERY . $visible . $tail,
            $bind + [':q_name' => $q, ':q_desc' => $q, ':lim' => $args['limit']])->fetchAll();
    }

    public static function evidence(array $user, string $id): array
    {
        [$visible, $bind] = \Authz::visibleFilesWhere($user);
        $tail = ' AND f.public_id = CAST(:pid AS uuid)';
        $row = \Db::instance()->run(self::QUERY . $visible . $tail, $bind + [':pid' => $id])->fetch();
        if (!is_array($row)) throw new Error(404, 'file_not_found', '읽을 수 있는 파일을 찾지 못했습니다.');
        return $row;
    }

    public static function execute(array $user, array $step): array
    {
        if ($step['tool'] === 'files.search') return self::search($user, $step['arguments']);
        if ($step['tool'] === 'files.evidence') return [self::evidence($user, $step['arguments']['file_id'])];
        throw new Error(403, 'tool_not_permitted', '읽기 전용 도구만 실행할 수 있습니다.');
    }

    public static function publicFile(array $row): array
    {
        return ['id' => (string) $row['public_id'], 'name' => (string) $row['original_name'],
            'level' => (string) $row['access_level'], 'size' => (int) $row['size_bytes'],
            'uploaded_at' => Store::date($row['created_at']), 'exec_content' => $row['exec_content'] === true];
    }

    public static function artifact(array $user, array $ref): ?array
    {
        try { $row = self::evidence($user, $ref['file_id']); }
        catch (Error $e) { if ($e->kind === 'file_not_found') return null; throw $e; }
        return self::artifactFromRow($ref, $row);
    }

    private static function artifactFromRow(array $ref, array $row): array
    {
        return ['artifact_id' => $ref['artifact_id'], 'kind' => 'file_evidence', 'file' => self::publicFile($row),
            'evidence' => ['kind' => 'metadata', 'description' => (string) $row['description'], 'content_read' => false],
            'download_url' => \Http::url('/api/files/' . (string) $row['public_id'] . '/download')];
    }

    public static function rowsForRefs(array $user, array $refs): array
    {
        if ($refs === []) return [];
        $ids = array_values(array_unique(array_column($refs, 'file_id')));
        [$visible, $bind] = \Authz::visibleFilesWhere($user);
        $tail = <<<'SQL'
          AND f.public_id IN (SELECT CAST(value AS uuid) FROM jsonb_array_elements_text(CAST(:ids AS jsonb)))
        SQL;
        $rows = \Db::instance()->run(self::QUERY . $visible . $tail, $bind + [':ids'=>Request::json($ids)])->fetchAll();
        $out = [];
        foreach ($rows as $row) $out[(string) $row['public_id']] = $row;
        return $out;
    }

    public static function project(array $user, ?array $stored, ?array $rows = null): array
    {
        if ($stored === null) return [null, []];
        $artifacts = [];
        $rows = $rows ?? self::rowsForRefs($user, $stored['references']);
        foreach ($stored['references'] as $ref) {
            if (isset($rows[$ref['file_id']])) $artifacts[] = self::artifactFromRow($ref, $rows[$ref['file_id']]);
        }
        return [['kind' => $stored['kind'], 'files' => array_column($artifacts, 'file'),
            'count' => count($artifacts), 'content_read' => false], $artifacts];
    }
}
