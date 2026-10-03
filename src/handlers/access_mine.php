<?php
declare(strict_types=1);

/**
 * src/handlers/access_mine.php — GET /api/grants/mine (정책: file.read)
 *
 * G-0 (t-box PRD F-73 ②) — **나에게 준 권한**으로 여는 개인 폴더·파일 목록(「공유받음」 보기). 사람 지정 권한은 개인 폴더
 * 하나에만 들어온 사람을 만든다 — 그 위 폴더는 못 보니 트리를 내려가서는 닿지 못한다. 그래서 받은 자리를 한곳에 모은다
 * (커뮤니티 「공유받은 페이지」와 같은 까닭).
 *
 * 싣는 것 — **나에게 직접 준** 줄의 항목 중 지금도 개인 등급이고 살아 있는 것(넓혔거나 지운 것은 빠진다 — 줄은 남는다).
 *   폴더: 번호·이름·보기/편집·주인 이름 · 파일: UUID·이름·크기·보기/편집·주인 이름. 이름은 목록 조건절을 지난 것만
 *   (Authz::canSeeInList — 이 목록도 목록이다: 판정 ↔ 조건절 짝의 같은 쪽). 위 폴더에서 이어받은 하위는 싣지 않는다(열면 보인다).
 * 최대 200 줄 · 받은 때 최근 순.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params (없음)
 */

$db = Db::instance();
$sql = <<<'SQL'
    SELECT g.can_write, g.granted_at,
           g.folder_id, d.name AS folder_name, d.access_level AS folder_level, d.created_by,
           g.file_id, f.public_id, f.original_name, f.size_bytes, f.access_level AS file_level, f.uploaded_by,
           f.scan_status, f.exec_content,
           ou.username AS owner_username, ou.display_name AS owner_display_name
      FROM access_grants g
      LEFT JOIN folders d ON d.id = g.folder_id
      LEFT JOIN files f   ON f.id = g.file_id AND f.deleted_at IS NULL
      LEFT JOIN users ou  ON ou.id = coalesce(d.created_by, f.uploaded_by)
     WHERE g.user_id = CAST(:me AS bigint)
       AND (   (g.folder_id IS NOT NULL AND d.access_level = 'private')
            OR (g.file_id   IS NOT NULL AND f.access_level = 'private'))
     ORDER BY g.granted_at DESC, g.id DESC
     LIMIT 200
    SQL;

$folders = [];
$files   = [];
foreach ($db->run($sql, [':me' => (int) $user['id']])->fetchAll() as $r) {
    $owner = [
        'username'     => (string) $r['owner_username'],
        'display_name' => $r['owner_display_name'] === null ? null : (string) $r['owner_display_name'],
    ];
    if ($r['folder_id'] !== null) {
        $row = ['id' => (int) $r['folder_id'], 'item_kind' => 'folder', 'access_level' => (string) $r['folder_level'],
                'team_id' => null, 'created_by' => (int) $r['created_by']];
        if (!Authz::canSeeInList($user, $row)) {
            continue;
        }
        $folders[] = [
            'id'         => (int) $r['folder_id'],
            'name'       => (string) $r['folder_name'],
            'write'      => $r['can_write'] === true,
            'owner'      => $owner,
            'granted_at' => (string) $r['granted_at'],
        ];
        continue;
    }
    $row = ['id' => (int) $r['file_id'], 'item_kind' => 'file', 'access_level' => (string) $r['file_level'],
            'team_id' => null, 'uploaded_by' => (int) $r['uploaded_by']];
    if (!Authz::canSeeInList($user, $row)) {
        continue;
    }
    $files[] = [
        'id'           => (string) $r['public_id'],
        'name'         => (string) $r['original_name'],
        'size_bytes'   => (int) $r['size_bytes'],
        'scan_status'  => (string) $r['scan_status'],
        'exec_content' => $r['exec_content'] === true,
        'write'        => $r['can_write'] === true,
        'owner'        => $owner,
        'granted_at'   => (string) $r['granted_at'],
    ];
}

Http::json(['folders' => $folders, 'files' => $files]);
