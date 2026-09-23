<?php
declare(strict_types=1);

/**
 * src/community/handlers/page_reads.php — GET /community/api/pages/{id}/reads (정책: community.read)
 *
 * 요구사항: 커뮤니티 PRD F-30 「공지 읽음 확인」 — 공지 게시판에 걸린 글의 「읽은 사람 n/N」.
 * 결정 D-8 기본안(좁은 쪽): **수만** 돌려준다 — 누가 읽었는지 이름 목록은 없다(감시 성격, 사용자 확인 대기).
 *
 * 자격: `CommunityAuthz::decideReadsView` — 쓴 사람(게시판 글 작성자·게시한 주인) · Admin. 공지가 아니면 400.
 *   n = 그 글을 연 활성 사용자 수(`page_reads`) · N = 그 공지 게시판을 **볼 수 있는** 활성 사용자 수
 *   (사람마다 `decideBoardRead` — 게시판 판정 한 벌). 쓴 사람 자신은 둘 다에서 뺀다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$gate = community_history_gate($user, (string) $params[0]);
$row  = $gate['row'];

[$may, $code] = CommunityAuthz::decideReadsView($user, $row);
if (!$may) {
    if ($code === CommunityAuthz::CODE_SPACE_GOVERNED) {
        Http::json(['error' => '공지 게시판의 글이 아닙니다', 'reason' => $code], 400);
        exit;
    }
    Http::error(403, Http::MSG_FORBIDDEN);
}
$board  = CommunityAuthz::boardOfRow($row);
$writer = (string) $row['space_kind'] === 'board' ? (int) $row['author_id'] : (int) $row['space_owner_user_id'];

$db    = Db::instance();
$users = $db->run('SELECT id, role, team_id FROM public.users WHERE is_active = true ORDER BY id')->fetchAll();
$audience = [];
foreach ($users as $u) {
    $uu = ['id' => (int) $u['id'], 'role' => (string) $u['role'], 'team_id' => $u['team_id'] === null ? null : (int) $u['team_id']];
    if ($uu['id'] !== $writer && CommunityAuthz::decideBoardRead($uu, $board)[0]) {
        $audience[$uu['id']] = true;
    }
}
$sqlRead = <<<'SQL'
    SELECT r.user_id FROM community.page_reads r WHERE r.page_id = CAST(:pid AS bigint)
    SQL;
$read = 0;
foreach ($db->run($sqlRead, [':pid' => (int) $row['id']])->fetchAll() as $r) {
    if (isset($audience[(int) $r['user_id']])) {
        $read++;
    }
}
Http::json(['reads' => ['read' => $read, 'total' => count($audience)]]);
