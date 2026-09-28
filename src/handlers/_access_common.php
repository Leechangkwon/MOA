<?php
declare(strict_types=1);

/**
 * src/handlers/_access_common.php — G-0 사람 지정 권한 · 권한 요청의 공통 코드 (t-box PRD F-73, ARCH 5.36)
 *
 * 이 파일은 라우트 표에 없다(이름이 `_` 로 시작한다) — 권한 창구 넷(`access_grants.php` · `access_requests.php` ·
 * `access_people.php` · `access_mine.php`)과 목록 창구가 `require_once` 한다.
 *
 * ★ 판정은 여기 없다. 「누가 무엇을」은 전부 `Authz`(decideRead · decideGrantManage · decidePrivateOwner · isItemCreator ·
 *   mayReceiveWriteGrant)가 하고, 이 파일은 행을 읽고·쓰고·모양을 만드는 일만 한다(ARCH 4.1).
 * ★ SQL 은 전부 nowdoc + 바인딩(PRD S-04). 요청 값이 식별자 자리에 들어가는 곳이 없다.
 */

/** 한 사람이 하루(24시간)에 보낼 수 있는 권한 요청 수 — 저장하지 않은 요청(없는 항목·중복)도 센다(존재가 갈리지 않게) */
const TBOX_ACCESS_REQUEST_DAILY_MAX = 20;
/** 요청 메모 글자 수 상한 (DB CHECK access_requests_message_len 과 같은 값) */
const TBOX_ACCESS_MESSAGE_MAX = 200;

const TBOX_ACCESS_MSG_SENT       = '요청을 보냈습니다 — 주인이 허락하면 알림으로 알려 드립니다';
const TBOX_ACCESS_MSG_ALREADY    = '이미 이 항목에 권한이 있습니다';
const TBOX_ACCESS_MSG_LIMIT      = '권한 요청은 하루 20건까지 보낼 수 있습니다 — 내일 다시 보내세요';
const TBOX_ACCESS_MSG_GUEST      = '손님 계정은 보기 권한만 요청할 수 있습니다';
const TBOX_ACCESS_MSG_NOT_PRIVATE = '「개인」 등급 항목에만 사람을 지정해 권한을 줄 수 있습니다';
const TBOX_ACCESS_MSG_BAD_USER   = '받을 사람을 찾지 못했습니다 — 켜져 있는 계정의 아이디나 이름을 적으세요(주인 자신에게는 줄 수 없습니다)';
const TBOX_ACCESS_MSG_AMBIGUOUS  = '같은 이름이 여럿입니다 — 아이디로 적으세요';
const TBOX_ACCESS_MSG_NO_WRITE   = '손님 계정에는 편집 권한을 줄 수 없습니다 — 보기로 주세요';
const TBOX_ACCESS_MSG_GONE       = '이 항목은 지워졌거나 이제 「개인」 등급이 아닙니다';

/**
 * 권한 창구의 대상 항목 한 줄 — 종류 표식(`item_kind`)·번호·이름·등급·주인·위치까지. 없으면(지운 파일 포함) null.
 * 파일은 UUID(public_id), 폴더는 번호로 찾는다 — 부르는 쪽이 형태를 이미 좁혔다.
 *
 * @return array<string,mixed>|null
 */
function tbox_access_item(string $kind, string $key): ?array
{
    if ($kind === 'file') {
        if (!Storage::isValidUuid($key)) {
            return null;
        }
        $sql = <<<'SQL'
            SELECT f.id, f.public_id, f.original_name AS name, f.access_level, f.team_id,
                   f.uploaded_by, f.folder_id, 'file' AS item_kind, f.uploaded_by AS owner_id,
                   u.is_active AND (u.expires_at IS NULL OR u.expires_at > now()) AS owner_active
              FROM files f
              JOIN users u ON u.id = f.uploaded_by
             WHERE f.public_id = CAST(:key AS uuid)
               AND f.deleted_at IS NULL
            SQL;
        $row = Db::instance()->run($sql, [':key' => $key])->fetch();
        return is_array($row) ? $row : null;
    }
    if ($kind === 'folder') {
        if (preg_match('/^[1-9][0-9]{0,8}$/', $key) !== 1) {
            return null;
        }
        $sql = <<<'SQL'
            SELECT d.id, d.name, d.access_level, d.team_id, d.created_by, d.parent_id,
                   'folder' AS item_kind, d.created_by AS owner_id,
                   u.is_active AND (u.expires_at IS NULL OR u.expires_at > now()) AS owner_active
              FROM folders d
              JOIN users u ON u.id = d.created_by
             WHERE d.id = CAST(:key AS bigint)
            SQL;
        $row = Db::instance()->run($sql, [':key' => (int) $key])->fetch();
        return is_array($row) ? $row : null;
    }
    return null;
}

/** 항목 번호 → 권한 창구의 대상(요청 줄에서 거꾸로 찾을 때) */
function tbox_access_item_by_id(string $kind, int $id): ?array
{
    if ($kind === 'file') {
        $sql = <<<'SQL'
            SELECT f.public_id FROM files f WHERE f.id = CAST(:id AS bigint)
            SQL;
        $r = Db::instance()->run($sql, [':id' => $id])->fetch();
        return is_array($r) ? tbox_access_item('file', (string) $r['public_id']) : null;
    }
    return tbox_access_item('folder', (string) $id);
}

/** 개인 항목에 **직접** 준 권한 수 (「개인 · 2명」 — 위 폴더에서 이어받은 것은 세지 않는다) */
function tbox_direct_grant_count(string $kind, int $id): int
{
    $sql = $kind === 'file'
        ? 'SELECT count(*) AS n FROM access_grants WHERE file_id = CAST(:id AS bigint)'
        : 'SELECT count(*) AS n FROM access_grants WHERE folder_id = CAST(:id AS bigint)';
    $r = Db::instance()->run($sql, [':id' => $id])->fetch();
    return is_array($r) ? (int) $r['n'] : 0;
}

/**
 * 한 항목의 권한 줄 — **직접 준 것**(뺄 수 있다)과 **위 개인 폴더에서 이어받은 것**(여기서는 못 뺀다 — 그 폴더에서 뺀다).
 * 이어받는 길은 판정과 같다(Authz::privateChainSql — 위로 올라가며 개인 등급인 동안만). 위 폴더의 이름은 **보는 사람이 그
 * 폴더를 볼 수 있을 때만** 싣는다(`$visibleFolder` — 목록 조건절 지도). 비활성 계정의 줄도 싣는다(화면이 「꺼진 계정」 으로
 * 보이고 뺄 수 있게 — 판정은 그 사람이 로그인할 수 없으니 이미 무효다).
 *
 * @param array<string,mixed>                          $item   tbox_access_item() 의 행
 * @param array<int,array{vis:bool,name:string}>       $folderMap tbox_folder_map() — 위 폴더 이름을 보일지
 * @return list<array<string,mixed>>
 */
function tbox_access_grant_rows(array $item, array $folderMap): array
{
    $kind = (string) $item['item_kind'];
    $id   = (int) $item['id'];
    // 위로 올라가는 개인 폴더 줄 (판정과 같은 규칙 — 개인 등급인 동안만, 깊이 ≤ 5)
    $chain = [];
    $cur   = $kind === 'file' ? ($item['folder_id'] === null ? null : (int) $item['folder_id'])
                              : ($item['parent_id'] === null ? null : (int) $item['parent_id']);
    for ($i = 0; $i < 6 && $cur !== null && isset($folderMap[$cur]); $i++) {
        if ($folderMap[$cur]['level'] !== Authz::LEVEL_PRIVATE) {
            break;
        }
        $chain[] = $cur;
        $cur = $folderMap[$cur]['parent'];
    }
    $sql = <<<'SQL'
        SELECT g.id, g.folder_id, g.file_id, g.can_write, g.granted_at,
               u.id AS user_id, u.username, u.display_name, u.role,
               u.is_active AND (u.expires_at IS NULL OR u.expires_at > now()) AS active,
               gb.username AS granted_by
          FROM access_grants g
          JOIN users u  ON u.id = g.user_id
          JOIN users gb ON gb.id = g.granted_by
         WHERE (CAST(:kind AS text) = 'file' AND g.file_id = CAST(:id AS bigint))
            OR (CAST(:kind2 AS text) = 'folder' AND g.folder_id = CAST(:id2 AS bigint))
            OR g.folder_id = ANY(CAST(:chain AS bigint[]))
         ORDER BY g.granted_at, g.id
        SQL;
    $rows = Db::instance()->run($sql, [
        ':kind'  => $kind, ':id'  => $id,
        ':kind2' => $kind, ':id2' => $id,
        ':chain' => '{' . implode(',', $chain) . '}',
    ])->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $direct = $kind === 'file'
            ? ($r['file_id'] !== null && (int) $r['file_id'] === $id)
            : ($r['folder_id'] !== null && (int) $r['folder_id'] === $id);
        $fromId = $direct ? null : (int) $r['folder_id'];
        $out[] = [
            'user'         => (int) $r['user_id'],
            'username'     => (string) $r['username'],
            'display_name' => $r['display_name'] === null ? null : (string) $r['display_name'],
            'write'        => $r['can_write'] === true,
            'active'       => $r['active'] === true,
            'granted_at'   => (string) $r['granted_at'],
            'granted_by'   => (string) $r['granted_by'],
            'inherited'    => !$direct,
            // 이어받은 줄의 폴더 이름 — 그 폴더를 볼 수 있을 때만
            'from'         => $fromId !== null && isset($folderMap[$fromId]) && $folderMap[$fromId]['vis']
                ? ['id' => $fromId, 'name' => $folderMap[$fromId]['name']]
                : null,
        ];
    }
    return $out;
}

/**
 * 받는 사람 찾기 — 아이디(정확히) 먼저, 아니면 이름(정확히). 켜져 있고 만료 전인 계정만.
 * [상태, 행]: 'ok' · 'none'(없음·꺼짐·만료 — 같은 답) · 'ambiguous'(같은 이름이 여럿).
 *
 * @return array{0:string,1:?array<string,mixed>}
 */
function tbox_access_find_user(string $who): array
{
    $who = trim($who);
    if ($who === '' || iconv_strlen($who, 'UTF-8') > 64 || preg_match('/\p{C}/u', $who) === 1) {
        return ['none', null];
    }
    $sqlName = <<<'SQL'
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active
          FROM users u
         WHERE u.username = :who
           AND u.is_active AND (u.expires_at IS NULL OR u.expires_at > now())
        SQL;
    $row = Db::instance()->run($sqlName, [':who' => strtolower($who)])->fetch();
    if (is_array($row)) {
        return ['ok', $row];
    }
    $sqlDisplay = <<<'SQL'
        SELECT u.id, u.username, u.display_name, u.role, u.team_id, u.is_active
          FROM users u
         WHERE u.display_name = :who
           AND u.is_active AND (u.expires_at IS NULL OR u.expires_at > now())
         LIMIT 2
        SQL;
    $rows = Db::instance()->run($sqlDisplay, [':who' => $who])->fetchAll();
    if (count($rows) === 1) {
        return ['ok', $rows[0]];
    }
    return [count($rows) > 1 ? 'ambiguous' : 'none', null];
}

/**
 * 사람 후보 — 커뮤니티 5-A″ 「공유 후보」(`community_share_candidates`)와 **같은 판정·같은 모양**:
 * 한 글자부터(빈 검색어는 빈 목록) · 활성 계정만 · 아이디·이름 ILIKE · 계정명·이름·역할만 · 최대 20 · 아이디 순.
 * (그 함수는 커뮤니티 라우트 파일 안에 있어 t-box 요청에서 읽히지 않는다 — 같은 SQL 을 여기 둔다. 시험 g0 가 두 창구의
 * 답이 같은지 견준다.) 역할 목록(쓰기 자격)은 Authz 에 묻는다.
 *
 * @return list<array{username:string,display_name:?string,role:string}>
 */
function tbox_people_candidates(string $q, int $limit, ?int $excludeId): array
{
    if ($q === '') {
        return [];
    }
    $like = '%' . str_replace(['#', '%', '_'], ['##', '#%', '#_'], $q) . '%';
    $sql = <<<'SQL'
        SELECT u.username, u.display_name, u.role
          FROM users u
         WHERE u.is_active = true
           AND (u.username ILIKE :like ESCAPE '#' OR u.display_name ILIKE :like_name ESCAPE '#')
           AND u.id IS DISTINCT FROM CAST(:exclude AS bigint)
         ORDER BY u.username
         LIMIT CAST(:lim AS int)
        SQL;
    $out = [];
    foreach (Db::instance()->run($sql, [
        ':like' => $like, ':like_name' => $like, ':exclude' => $excludeId, ':lim' => $limit,
    ])->fetchAll() as $r) {
        $out[] = [
            'username'     => (string) $r['username'],
            'display_name' => $r['display_name'] === null ? null : (string) $r['display_name'],
            'role'         => (string) $r['role'],
        ];
    }
    return $out;
}

/**
 * 알림 한 줄 — **알림을 만드는 곳은 이 함수 하나**다(지시서 4장). 커뮤니티 알림 종(사용자가 가장 자주 본다)에 넣는다.
 *   kind 'access_request'  주인(호스트)에게 — 누가 권한을 요청했다
 *   kind 'access_decided'  요청한 사람에게 — 허락·거절
 * 페이지가 없다(page_id NULL) — 알림 창이 요청 줄 번호(access_request_id)로 t-box 요청 목록·그 항목을 가리킨다.
 * 자기 자신에게는 만들지 않는다. 부르는 쪽의 트랜잭션 안에서 부른다.
 */
function tbox_access_notify(int $userId, string $kind, int $requestId, int $actorId): void
{
    if ($userId <= 0 || $userId === $actorId || !in_array($kind, ['access_request', 'access_decided'], true)) {
        return;
    }
    $sql = <<<'SQL'
        INSERT INTO community.notifications (user_id, kind, actor_id, access_request_id)
        VALUES (CAST(:uid AS bigint), :kind, CAST(:aid AS bigint), CAST(:rid AS bigint))
        SQL;
    Db::instance()->run($sql, [':uid' => $userId, ':kind' => $kind, ':aid' => $actorId, ':rid' => $requestId]);
}

/**
 * 요청을 받을 사람들(호스트) — 항목 주인(파일 = 올린 사람 · 폴더 = 만든 사람)이 켜져 있으면 그 사람 하나,
 * 꺼졌거나 만료됐으면 켜져 있는 Admin 전원(지시서 ★ 「주인이 비활성이면 Admin 이 받는다」). 역할 판단은 Authz 에 묻는다.
 *
 * @param array<string,mixed> $item
 * @return list<int>
 */
function tbox_access_hosts(array $item): array
{
    if (($item['owner_active'] ?? false) === true) {
        return [(int) $item['owner_id']];
    }
    $sql = <<<'SQL'
        SELECT u.id, u.role FROM users u
         WHERE u.is_active AND (u.expires_at IS NULL OR u.expires_at > now())
         ORDER BY u.id
        SQL;
    $out = [];
    foreach (Db::instance()->run($sql)->fetchAll() as $r) {
        if (Authz::canAdmin(['role' => (string) $r['role']])) {
            $out[] = (int) $r['id'];
        }
    }
    return $out;
}

/**
 * 이 사람이 이 요청의 **호스트**인가 — 항목 주인이거나(켜져 있을 때), 주인이 꺼졌을 때의 Admin.
 *
 * @param array<string,mixed> $user
 * @param array<string,mixed> $item tbox_access_item() 의 행
 */
function tbox_access_is_host(array $user, array $item): bool
{
    if (($item['owner_active'] ?? false) === true) {
        return Authz::isItemCreator($user, $item);
    }
    return Authz::canAdmin($user);
}

/** 내가 호스트인 기다리는 요청 수 — 사이드바 「권한 요청 n」(`/api/me` 의 access_requests_pending) */
function tbox_access_pending_count(array $user): int
{
    $sql = <<<'SQL'
        SELECT count(*) AS n
          FROM access_requests r
          LEFT JOIN files f   ON f.id = r.file_id
          LEFT JOIN folders d ON d.id = r.folder_id
          JOIN users o ON o.id = coalesce(f.uploaded_by, d.created_by)
         WHERE r.status = 'pending'
           AND (f.id IS NULL OR f.deleted_at IS NULL)
           AND (   (o.id = CAST(:me AS bigint) AND o.is_active AND (o.expires_at IS NULL OR o.expires_at > now()))
                OR (CAST(:admin AS boolean) AND NOT (o.is_active AND (o.expires_at IS NULL OR o.expires_at > now()))))
        SQL;
    $r = Db::instance()->run($sql, [
        ':me'    => (int) $user['id'],
        ':admin' => Authz::canAdmin($user) ? 'true' : 'false',
    ])->fetch();
    return is_array($r) ? (int) $r['n'] : 0;
}

/** 메모 정리 — 제어 문자(줄바꿈 포함)를 빈칸으로, 앞뒤 공백을 뗀다. 200자를 넘으면 null(거부) */
function tbox_access_clean_message($raw): ?string
{
    if ($raw === null) {
        return '';
    }
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        return null;
    }
    $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $raw));
    return iconv_strlen($clean, 'UTF-8') > TBOX_ACCESS_MESSAGE_MAX ? null : $clean;
}
