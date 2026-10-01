<?php
declare(strict_types=1);

/** Personal, owner-only display-name mutation. No byte or storage path changes. */
final class FileRenameFailure extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        public readonly array $auditTarget = [],
    ) {
        parent::__construct($reason);
    }
}

final class FileRename
{
    public static function validateName(string $name): void
    {
        if ($name === '' || preg_match('//u', $name) !== 1
            || iconv_strlen($name, 'UTF-8') > 255
            || preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u', $name) === 1
            || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1
            || strpbrk($name, "/\\:<>|?*\"") !== false
            || $name === '.' || $name === '..'
        ) {
            throw new FileRenameFailure(400, 'invalid_name');
        }
        try {
            Upload::checkExtension($name);
        } catch (RuntimeException $e) {
            throw new FileRenameFailure(400, 'invalid_name');
        }
    }

    /** @return array{id:string,name:string} */
    public static function apply(array $user, string $publicId, string $name, string $expected): array
    {
        self::validateName($name);
        if ($expected === '' || preg_match('//u', $expected) !== 1) {
            throw new FileRenameFailure(400, 'bad_request');
        }
        $db = Db::instance();
        $db->begin();
        try {
            // Fresh role/account state, held until mutation commits.
            $sqlUser = <<<'SQL'
                SELECT id, username, role, team_id, is_active,
                       (expires_at IS NOT NULL AND expires_at <= clock_timestamp()) AS is_expired
                  FROM users WHERE id = :id FOR SHARE
                SQL;
            $freshUser = $db->run($sqlUser, [':id' => (int) $user['id']])->fetch();
            if (!is_array($freshUser) || $freshUser['is_active'] !== true || $freshUser['is_expired'] === true) {
                throw new FileRenameFailure(403, 'user_disabled');
            }
            $sqlFile = <<<'SQL'
                SELECT id, public_id, original_name, ext, description, access_level,
                       team_id, uploaded_by, sha256, size_bytes
                  FROM files
                 WHERE public_id = CAST(:pid AS uuid) AND deleted_at IS NULL
                 FOR UPDATE
                SQL;
            $file = $db->run($sqlFile, [':pid' => $publicId])->fetch();
            if (!is_array($file)) {
                throw new FileRenameFailure(404, 'not_found');
            }
            $target = [
                'target_type' => 'file', 'target_id' => (int) $file['id'],
                'target_name' => (string) $file['original_name'],
                'access_level' => (string) $file['access_level'],
            ];
            [$allowed, $reason] = Authz::decideRename($freshUser, $file);
            if (!$allowed) {
                throw new FileRenameFailure(403, $reason, $target);
            }
            // Recheck the personal token after any wait for the file row lock.
            $sqlToken = <<<'SQL'
                SELECT id, scope, revoked_at,
                       (expires_at <= clock_timestamp()) AS is_expired
                  FROM api_tokens
                 WHERE id = :id AND user_id = :owner
                 FOR SHARE
                SQL;
            $token = $db->run($sqlToken, [
                ':id' => (int) constant('TBOX_API_TOKEN_ID'), ':owner' => (int) $freshUser['id'],
            ])->fetch();
            if (!is_array($token) || $token['revoked_at'] !== null || $token['is_expired'] === true) {
                throw new FileRenameFailure(401, 'authentication_required', $target);
            }
            if (!Authz::tokenScopeAllows((string) $token['scope'], 'file.update')) {
                throw new FileRenameFailure(403, 'token_scope_not_permitted', $target);
            }
            if ((string) $file['original_name'] !== $expected) {
                throw new FileRenameFailure(409, 'name_conflict', $target);
            }
            if (Upload::checkExtension($name) !== (string) $file['ext']) {
                throw new FileRenameFailure(400, 'extension_changed', $target);
            }
            if ($name !== $expected) {
                $sqlRename = <<<'SQL'
                    UPDATE files
                       SET original_name = :name,
                           search_tsv = strip(to_tsvector('simple', :tsv))
                     WHERE id = :id AND uploaded_by = :owner
                       AND original_name = :expected AND deleted_at IS NULL
                    SQL;
                $changed = $db->run($sqlRename, [
                    ':name' => $name, ':tsv' => SearchText::indexTextFile([$name, (string) $file['description']], 'file ' . $file['id']),
                    ':id' => (int) $file['id'], ':owner' => (int) $freshUser['id'], ':expected' => $expected,
                ])->rowCount();
                if ($changed !== 1) {
                    throw new FileRenameFailure(409, 'name_conflict', $target);
                }
                Audit::allow('file.rename', [
                    'target_type' => 'file', 'target_id' => (int) $file['id'],
                    'target_name' => $name, 'access_level' => (string) $file['access_level'],
                    'detail' => [
                        'before' => ['name' => $expected], 'after' => ['name' => $name],
                        'changed' => ['original_name'], 'owner_id' => (int) $freshUser['id'],
                        'sha256' => (string) $file['sha256'], 'size_bytes' => (int) $file['size_bytes'],
                    ],
                ]);
            }
            $db->commit();
            return ['id' => (string) $file['public_id'], 'name' => $name];
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }
}
