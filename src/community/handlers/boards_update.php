<?php
declare(strict_types=1);

/**
 * src/community/handlers/boards_update.php — PATCH /community/api/boards/{num} (정책: role:Admin + 재인증)
 *
 * 요구사항: 커뮤니티 PRD 4장 · F-09(「고치기 — 이름·설명·공지·활성」), 지시서 5-C 「관리자: 게시판 관리」.
 *
 * 받는 값 (JSON, 하나 이상 · **허용 키 밖이 하나라도 있으면 400**)
 *   name         1~50자(대소문자 무시 중복 불가 — 409)
 *   description  200자까지
 *   is_active    `true`·`false` — 비활성은 **읽기 전용 보관**이다(새 글·게시·고치기 400/403, 읽기는 그대로)
 *
 * ★ `access_level`·`team_id`·`board_kind` 는 **허용 키가 아니다**(오면 400) — PRD 4장 각주(Q-1 ③):
 *   게시판 등급을 바꾸면 이미 쓴 글의 노출 범위가 글쓴이 모르게 바뀐다. 필요하면 새 게시판을 만들고
 *   옛것을 비활성으로 둔다. (지시서 화면 목록의 「등급·팀·종류」는 **만들 때만** 고른다 — 보고서 ⑧ 결정 대기)
 * ★ 값이 그대로면 기록하지 않는다(200 `changed: []`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 게시판 번호
 */

$db      = Db::instance();
$boardId = (int) $params[0];

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), ['name', 'description', 'is_active']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$board = community_board_row($boardId);
if ($board === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

/** 한 줄 글자 칸 검사 — `boards_create.php` 와 같은 규칙. 어긋나면 null. */
$cleanText = static function ($raw, int $min, int $max): ?string {
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        return null;
    }
    $t = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw);
    $n = iconv_strlen($t, 'UTF-8');
    if ($n === false || $n < $min || $n > $max || preg_match('/\p{C}/u', $t) === 1) {
        return null;
    }
    return $t;
};

$before = [
    'name'        => (string) $board['name'],
    'description' => (string) $board['description'],
    'is_active'   => $board['is_active'] === true,
];
$after = $before;

if (array_key_exists('name', $body)) {
    $v = $cleanText($body['name'], 1, 50);
    if ($v === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $after['name'] = $v;
}
if (array_key_exists('description', $body)) {
    $v = $body['description'] === null ? '' : $cleanText($body['description'], 0, 200);
    if ($v === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $after['description'] = $v;
}
if (array_key_exists('is_active', $body)) {
    if (!is_bool($body['is_active'])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $after['is_active'] = $body['is_active'];
}

$changed = [];
foreach ($after as $k => $v) {
    if ($before[$k] !== $v) {
        $changed[] = $k;
    }
}
if ($changed === []) {
    Http::json(['board' => community_board_summary($board, $user), 'changed' => []]);
    exit;
}

$sqlSet = <<<'SQL'
    UPDATE community.spaces
       SET name = :name, description = :description, is_active = CAST(:active AS boolean), updated_at = now()
     WHERE id = CAST(:bid AS bigint) AND kind = 'board'
    SQL;

$db->begin();
try {
    $db->run($sqlSet, [
        ':name'        => $after['name'],
        ':description' => $after['description'],
        ':active'      => $after['is_active'] ? 'true' : 'false',
        ':bid'         => $boardId,
    ]);
    $diffBefore = [];
    $diffAfter  = [];
    foreach ($changed as $k) {
        $diffBefore[$k] = $before[$k];
        $diffAfter[$k]  = $after[$k];
    }
    Audit::allow('board.update', [
        'target_type'  => 'space',
        'target_id'    => $boardId,
        'target_name'  => $after['name'],
        'access_level' => (string) $board['access_level'],
        'detail'       => [
            'granted_by'   => CommunityAuthz::CODE_ADMIN_ALL,
            'changed'      => $changed,
            'board_before' => $diffBefore,
            'board_after'  => $diffAfter,
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if ($e->getCode() === '23505') {
        Audit::deny('board.update', 'conflict', [
            'target_type'  => 'space',
            'target_id'    => $boardId,
            'target_name'  => $after['name'],
            'access_level' => (string) $board['access_level'],
        ]);
        Http::error(409, Http::MSG_CONFLICT);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

$fresh = community_board_row($boardId);
Http::json(['board' => community_board_summary($fresh ?? $board, $user), 'changed' => $changed]);
