<?php
declare(strict_types=1);

/**
 * src/community/handlers/board_requests_create.php — POST /community/api/board-requests (정책: authenticated)
 *
 * 요구사항: 사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.7 「게시판 만들어 주세요」 · cafe-plan CF-4.
 *
 * 받는 값 `{name, purpose, anonymous?}` — 이름 1~40자(한 줄 · 제어 · 서식 문자 400) · 쓰임 1~500자(평문) · 익명 여부(참/거짓, 기본 거짓).
 * 판정 `CommunityAuthz::decideBoardRequest`(쓰기 자격 — 손님 403). 사람마다 **열린 요청 3** · **하루(24시간) 3** — 넘으면 429 + 감사 deny.
 * 관리자 전원에게 알림 `board_request`(actor = 요청자 — 관리자는 봐도 된다). 관리자 판단은 `Authz::canAdmin` 한 곳(SQL 에 역할 글자 0).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

const COMMUNITY_BOARD_REQUEST_OPEN_MAX  = 3;
const COMMUNITY_BOARD_REQUEST_DAILY_MAX = 3;
if (!defined('COMMUNITY_ADVISORY_LOCK_SQL')) {
    define('COMMUNITY_ADVISORY_LOCK_SQL', <<<'SQL'
        SELECT pg_advisory_xact_lock(CAST(:ns AS integer), CAST(:uid AS integer))
        SQL);
}

$db = Db::instance();
$me = (int) $user['id'];

[$may] = CommunityAuthz::decideBoardRequest($user);
if (!$may) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community board_request.create deny code=' . CommunityAuthz::CODE_ROLE_NOT_PERMITTED);
    Http::error(403, Http::MSG_FORBIDDEN);
}

$body = Http::jsonBody();
if (array_diff(array_keys($body), ['name', 'purpose', 'anonymous']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$rawName = $body['name'] ?? null;
$name = is_string($rawName) && preg_match('//u', $rawName) === 1
    ? (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $rawName) : '';
$nameLen = iconv_strlen($name, 'UTF-8');
if ($name === '' || $nameLen === false || $nameLen > 40 || preg_match('/\p{C}/u', $name) === 1) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$purpose = community_clean_plain($body['purpose'] ?? null, 500);
if ($purpose === null) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$anonymous = $body['anonymous'] ?? false;
if (!is_bool($anonymous)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$db->begin();
try {
    // 같은 사람의 요청을 한 줄로(상한 확인과 INSERT 사이 끼어들기 0)
    $db->run(COMMUNITY_ADVISORY_LOCK_SQL, [':ns' => 25007, ':uid' => $me]);
    $sqlCount = <<<'SQL'
        SELECT count(*) FILTER (WHERE status = 'open') AS open_n,
               count(*) FILTER (WHERE created_at > now() - interval '24 hours') AS day_n
          FROM community.board_requests WHERE requester_id = CAST(:me AS bigint)
        SQL;
    $counts = $db->run($sqlCount, [':me' => $me])->fetch();
    if ((int) $counts['open_n'] >= COMMUNITY_BOARD_REQUEST_OPEN_MAX || (int) $counts['day_n'] >= COMMUNITY_BOARD_REQUEST_DAILY_MAX) {
        $db->rollback();
        Audit::deny('board_request.create', 'rate_limited', [
            'target_type' => 'space',
            'target_name' => $name,
            'detail'      => ['open' => (int) $counts['open_n'], 'today' => (int) $counts['day_n']],
        ]);
        Http::json(['error' => '요청이 많아요. 관리자가 앞의 요청을 처리한 뒤 다시 보내 주세요', 'reason' => 'rate_limited'], 429);
        exit;
    }
    $sqlInsert = <<<'SQL'
        INSERT INTO community.board_requests (requester_id, name, purpose, anonymous)
        VALUES (CAST(:me AS bigint), :name, :purpose, CAST(:anon AS boolean))
        RETURNING id, public_id
        SQL;
    $ins = $db->run($sqlInsert, [':me' => $me, ':name' => $name, ':purpose' => $purpose, ':anon' => $anonymous ? 'true' : 'false'])->fetch();
    if (!is_array($ins)) {
        throw new RuntimeException('요청을 남기지 못했습니다');
    }
    // 관리자 전원에게 알림 — 누가 관리자인가는 Authz::canAdmin 한 곳(활성 계정만 · 자기 자신 제외)
    $sqlNotify = <<<'SQL'
        INSERT INTO community.notifications (user_id, kind, actor_id)
        VALUES (CAST(:uid AS bigint), 'board_request', CAST(:actor AS bigint))
        SQL;
    $sqlActive = <<<'SQL'
        SELECT id, role, team_id FROM public.users WHERE is_active = true
        SQL;
    foreach ($db->run($sqlActive)->fetchAll() as $u) {
        if ((int) $u['id'] !== $me && Authz::canAdmin(['id' => (int) $u['id'], 'role' => (string) $u['role'], 'team_id' => $u['team_id']])) {
            $db->run($sqlNotify, [':uid' => (int) $u['id'], ':actor' => $me]);
        }
    }
    Audit::allow('board_request.create', [
        'target_type' => 'space',
        'target_name' => $name,
        'detail'      => ['granted_by' => CommunityAuthz::CODE_MATRIX, 'request_id' => (int) $ins['id'], 'anonymous' => $anonymous],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['request' => ['id' => (string) $ins['public_id'], 'status' => 'open']], 201);
