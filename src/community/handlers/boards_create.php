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
 *   board_kind    종류 (필수) — `notice`(공지)·`discussion`(토론)·`qa`(질문답변)·`anonymous`(익명 — 카페 C1, `area=cafe` 일 때만)
 *   area          자리 (선택) — `work`(업무 앱, 기본) · `cafe`(「우리 공간」 카페) — 카페 C1 · ARCH 25.2
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

$body = Http::jsonBody();
// 카페 C1 (ARCH 25.2) — `area`(work · cafe, 없으면 work)를 더 받는다. `board_kind` 에 anonymous(카페에만 — 아니면 400).
if (array_diff(array_keys($body), ['name', 'description', 'access_level', 'team_id', 'board_kind', 'area']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}

/* 검사 · 쓰기 · 감사(`board.create` allow · 같은 이름 409 + deny conflict)는 공용 함수 한 벌이다(`community_board_create`, routes.php) —
   게시판 요청의 「만들기」(board_request_decide.php)가 같은 검사로 만든다. */
$db->begin();
try {
    $boardId = community_board_create($user, $body);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

$board = community_board_row($boardId);
Http::json(['board' => $board === null ? ['id' => $boardId] : community_board_summary($board, $user)], 201);
