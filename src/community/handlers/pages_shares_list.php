<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_shares_list.php — GET /community/api/pages/{id}/shares
 *                                                (정책: community.read)
 *
 * 요구사항: 5-A 지시서 「창구」 첫 줄, 커뮤니티 `PRD.md` 4.1 · NG-8(해제), `C:S-02`.
 *
 * 이 페이지에 **누가 공유로** 들어와 있나. 이 페이지에 직접 준 줄과 **조상에게서 물려받은 줄**
 * (`inherited: true`, 어느 페이지에서 왔나)을 함께 돌려준다 — 공유는 하위에 미치므로, 하위
 * 페이지를 연 사람이 「왜 이 사람이 볼 수 있나」를 알 수 있어야 한다.
 *
 * 검사 순서
 *   [권한 ✔ A] `community_history_gate` — 행+공간 404 · `decidePageRead`(못 보면 403 + 감사)
 *   [권한 ✔ B] **이름까지 보이나** — 내용을 고칠 수 있는 사람(`decidePageChange`: 소유자 ·
 *              편집 공유자 · 팀 공간 팀원)에게만 사람 이름이 나간다. 보기 공유자·팀·사내로
 *              보고 있는 사람·Admin 에게는 **수만** 나간다(지시서 「보기 공유자에게는 수만」).
 *              「누구에게 열려 있나」도 그 페이지에 딸린 정보이고, 보기만 허락받은 사람에게
 *              다른 사람의 계정명을 모아 줄 이유가 없다.
 *
 * 응답
 *   count     공유 줄 수(직접 + 물려받은 것)
 *   shares    이름이 보이는 사람에게만 목록, 아니면 `null`
 *   my_level  **이 페이지에 직접** 나에게 준 등급(없으면 null) — 화면의 「내 공유 빼기」가 쓴다
 *   share_level 조상까지 따진 나의 가장 강한 등급(판정이 쓰는 값)
 *   can_manage  「사람 추가」·등급 바꾸기·빼기를 보일지(`decidePageManage` — 편집 공유로는 거짓)
 *
 * 목록 조회는 감사에 남기지 않는다(t-box 파일 목록과 같은 취급). 남의 개인 페이지를 관리자·같은
 * 팀이라서 연 일은 게이트가 `page.read allow` 로 남긴다(본문을 여는 것과 같은 무게).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$gate = community_history_gate($user, (string) $params[0]);
$row  = $gate['row'];

[$mayManage] = CommunityAuthz::decidePageManage($user, $row);
$mayNames    = $gate['may_change'] === true;

$all = community_page_shares((int) $row['id']);

$myLevel = null;
foreach ($all as $share) {
    if (!$share['inherited'] && $share['username'] === (string) $user['username']) {
        $myLevel = $share['level'];
        break;
    }
}

Http::json([
    'page' => [
        'id'    => (string) $row['public_id'],
        'title' => (string) $row['title'],
    ],
    'count'       => count($all),
    'shares'      => $mayNames ? $all : null,
    'my_level'    => $myLevel,
    'share_level' => CommunityAuthz::shareLevelFor($user, $row),
    // 5-A″ — 팀 공간도 관리 자격(그 팀원)이면 사람을 더하고 뺀다.
    'can_manage'  => $mayManage,
    // 「내 공유 빼기」가 보낼 계정명 — 화면이 내가 누구인지 따로 알고 있지 않다.
    'me'          => (string) $user['username'],
]);
