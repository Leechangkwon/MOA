<?php
declare(strict_types=1);

/**
 * src/community/handlers/boards_create.php — POST /community/api/boards (정책: role:Admin + 재인증)
 *
 * 요구사항: 커뮤니티 PRD 4장 「게시판 만들기 — Admin(재인증)」 · F-09 · F-35 · 11-1 ④,
 *           `../../../../community/stage5c-board-publish-directive.md` 「창구」.
 *
 * 받는 값 (JSON, **허용 키 밖이 하나라도 있으면 400**)
 *   name          이름 (필수) — 앞뒤 공백을 뗀 1~50자, 제어·서식 문자 없음. 대소문자 무시 **중복 불가**(409)
 *   description   설명 (선택) — 200자까지, 제어·서식 문자 없음
 *   access_level  등급 (필수) — t-box 파일·폴더와 같은 네 값 `public`·`internal`·`team`·`admin-only`
 *   team_id       팀 번호 — `team` 등급이면 **필수**(활성 팀), 아니면 **주면 400**
 *   board_kind    종류 (필수) — `notice`(공지)·`discussion`(토론)·`qa`(질문답변)
 *
 * 관문은 라우터가 먼저 끝낸다: 라우트 정책 `role:Admin`(아니면 403 + `authz.route deny`) →
 * `Authz::REAUTH_POLICIES` 에 든 정책이라 **15분 안에 비밀번호를 다시 확인한 세션**만(아니면
 * 403 `{next:"reauth"}`). 이 파일에는 역할을 따지는 코드가 없다.
 *
 * ★ 등급·팀·종류는 **만든 뒤 바꾸지 않는다**(PRD 4장 각주 Q-1 ③ — 바꾸면 이미 쓴 글의 노출 범위가
 *   글쓴이 모르게 바뀐다). 그래서 이 창구에서 한 번만 받고, `PATCH` 는 이 셋을 허용 키에 두지 않는다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$db = Db::instance();
$me = (int) $user['id'];

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['name', 'description', 'access_level', 'team_id', 'board_kind']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

/** 한 줄 글자 칸 검사 — 앞뒤 공백 제거 뒤 길이·제어 문자. 어긋나면 null. */
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

$name = $cleanText($body['name'] ?? null, 1, 50);
if ($name === null) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$description = '';
if (array_key_exists('description', $body) && $body['description'] !== null) {
    $description = $cleanText($body['description'], 0, 200);
    if ($description === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
}

$level = $body['access_level'] ?? null;
// G-0 — t-box 의 「개인」 등급은 게시판 등급이 아니다(사람으로 판정하는 등급 — 게시판은 표로 판정한다 · spaces CHECK 도 네 값).
if (!is_string($level) || !array_key_exists($level, Authz::LEVEL_RANK) || $level === Authz::LEVEL_PRIVATE) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$kind = $body['board_kind'] ?? null;
if (!is_string($kind) || !in_array($kind, CommunityAuthz::BOARD_KINDS, true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

// 팀 — `team` 등급이면 활성 팀 하나가 필수이고, 다른 등급이면 줄 수 없다(뜻 없는 값이 남지 않게).
$teamId  = null;
$rawTeam = $body['team_id'] ?? null;
if ($level === 'team') {
    if (!is_int($rawTeam) || $rawTeam < 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $sqlTeam = <<<'SQL'
        SELECT id FROM public.teams WHERE id = CAST(:tid AS bigint) AND is_active = true
        SQL;
    if (!is_array($db->run($sqlTeam, [':tid' => $rawTeam])->fetch())) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $teamId = $rawTeam;
} elseif ($rawTeam !== null) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$sqlInsert = <<<'SQL'
    INSERT INTO community.spaces (kind, name, description, access_level, team_id, notice, board_kind, created_by)
    VALUES ('board', :name, :description, :level, CAST(:team AS bigint), CAST(:notice AS boolean), :kind,
            CAST(:by AS bigint))
    RETURNING id
    SQL;

$db->begin();
try {
    $row = $db->run($sqlInsert, [
        ':name'        => $name,
        ':description' => $description,
        ':level'       => $level,
        ':team'        => $teamId,
        // 공지 여부는 종류와 **같은 값**이다(DB CHECK `spaces_board_shape`) — 요청이 따로 정하지 않는다.
        ':notice'      => $kind === 'notice' ? 'true' : 'false',
        ':kind'        => $kind,
        ':by'          => $me,
    ])->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('게시판을 만들지 못했습니다');
    }
    $boardId = (int) $row['id'];

    Audit::allow('board.create', [
        'target_type'  => 'space',
        'target_id'    => $boardId,
        'target_name'  => $name,
        'access_level' => $level,
        'detail'       => ['granted_by' => CommunityAuthz::CODE_ADMIN_ALL, 'board_kind' => $kind, 'team_id' => $teamId],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    // 23505 = unique_violation — 같은 이름(대소문자 무시)의 게시판이 이미 있다(`spaces_board_name_uidx`).
    if ($e->getCode() === '23505') {
        Audit::deny('board.create', 'conflict', [
            'target_type'  => 'space',
            'target_name'  => $name,
            'access_level' => $level,
        ]);
        Http::error(409, Http::MSG_CONFLICT);
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

$board = community_board_row($boardId);
Http::json(['board' => $board === null ? ['id' => $boardId] : community_board_summary($board, $user)], 201);
