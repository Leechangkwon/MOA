<?php
declare(strict_types=1);

/**
 * src/community/handlers/share_candidates.php — GET /community/api/share-candidates
 *                                              (정책: community.read)
 *
 * 요구사항: 5-A 지시서 「창구」 — 「Manage — 활성 사용자, 계정명·역할만, 최대 20」.
 *
 * 쿼리
 *   page  어느 페이지의 후보인가 (UUID, **필수**)
 *   q     아이디 **또는 이름** 조각 (≤ 64자). 5-A″ — 앞뒤 공백을 뺀 뒤 **한 글자도 없으면 빈 목록**이다
 *         (200, `candidates: []` — 입력 전에 사람 목록을 통째로 보이지 않는다. 사용자 결정 2026-09-23)
 *   for   `share`(기본) · `handover` — 무엇을 하려고 찾나. 모르는 값은 `share` 로 좁힌다
 *
 * 자격 — 후보 목록은 **그 일을 할 수 있는 사람**에게만 열린다
 *   `share`     `decidePageManage`(소유자·팀 공간 팀원). 편집 공유자는 남에게 다시 열어 주지 못하므로
 *               사람 목록도 받지 않는다. 5-A″ — 팀 공간 페이지도 공유 대상이다(팀원이 찾는다).
 *   `handover`  `decidePageHandover`(소유자·자기 팀원 것의 Manager·Admin). Manager 는 그 페이지를
 *               **볼 수 없어도**(비열람) 넘길 수 있으므로 읽기 판정을 요구하지 않는다.
 *   아니면 403(감사 없음 — 목록 조회다. 실제로 주거나 넘기려 하면 그 창구가 감사를 남긴다).
 *
 * 후보
 *   · 활성 계정만 · 그 페이지의 **주인은 뺀다**(자기에게 공유·인계는 뜻이 없다)
 *   · `handover` 면 **쓰기 자격 있는 역할만**(Guest 제외 — `community_role_sets()` 가 `Authz` 에 물은 목록)
 *   · 응답에는 **계정명·이름·역할**뿐이다(팀·활성 여부·만료일 같은 것은 고르는 데 필요 없다).
 *     이름은 `display_name` 으로 따로 나간다 — 「이름 (아이디)」로 잇는 일은 화면이 한다(5-A″)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$limit = 20;

$pageRaw = Http::queryText('page', 36);
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $pageRaw) !== 1) {
    // 형태가 아닌 값은 질의에 넣지 않는다 — `uuid` 칸에 닿으면 DB 가 예외를 던진다.
    Http::error(404, Http::MSG_NOT_FOUND);
}
$purpose = Http::queryText('for', 12) === 'handover' ? 'handover' : 'share';

$row = community_page_row($pageRaw, (int) $user['id']);
if ($row === null || $row['deleted_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

[$may, $code] = $purpose === 'handover'
    ? CommunityAuthz::decidePageHandover($user, $row)
    : CommunityAuthz::decidePageManage($user, $row);
/* 5-A″ — 팀 공간도 공유 후보를 찾는다(관리 자격 = 그 팀원). 인계는 여전히 개인 공간만이다 —
   `decidePageHandover` 가 팀 공간을 `space_governed` 로 거짓을 주므로 위 판정이 이미 막는다. */
if (!$may) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community share-candidates deny for=' . $purpose . ' code=' . $code);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// 5-A″ — 앞뒤 공백을 뺀 뒤 비었으면 **빈 목록**(찾기를 하지 않는다). 공백 판정은 유니코드 공백까지.
$q        = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', Http::queryText('q', 64));
$ownerId  = $row['space_owner_user_id'] === null ? null : (int) $row['space_owner_user_id'];
$people   = community_share_candidates($q, $limit, $ownerId, $purpose === 'handover');

Http::json([
    'page'       => ['id' => (string) $row['public_id']],
    'for'        => $purpose,
    'candidates' => $people,
    'total'      => count($people),
    'limit'      => $limit,
    'q'          => $q,
]);
