<?php
declare(strict_types=1);

/**
 * src/community/handlers/attachments_list.php — GET /community/api/pages/{id}/attachments (정책: community.read)
 *
 * 요구사항: 카페 묶음 C4(사용자 지적 2026-10-14 「이미지 삽입 시 계속 검사 중」) · DESIGN 2.26 「검사 중 미리보기」 ② ·
 *           커뮤니티 ARCHITECTURE 24.1 · 24.3(첨부 판정 그대로 — 새 판정 함수 0).
 *
 * 편집기 · 읽기 화면이 「검사 중」 첨부가 끝났는지 다시 묻는 **읽기 창구**다. 응답은 `pages_get` 의 `attachment_titles` 와 **같은 지도
 * 한 칸뿐**(`{attachment_titles: {첨부 id: {name, size, image, scan_status}}}` — 같은 함수 `community_attachment_titles`)이고 본문 · 제목 ·
 * 다른 문서의 첨부는 싣지 않는다(행 읽기가 page_id 로 거른다). 첨부 바이트는 내보내지 않는다 — 검사 전 서버 사본은 raw · download 가
 * 지금처럼 423 으로 막는다.
 *
 *   · 판정 = `CommunityAuthz::decideAttachmentRead`(그 문서의 읽기 판정 · 관리자 특권 갈래 끔) — pages_get 이 지도를 줄지 정하는 것과 같은 호출.
 *     못 보는 사람 · 없는 문서 · 특권으로만 보는 사람은 모두 같은 404(존재 노출 0).
 *   · 감사 0 — 5초마다 되풀이되는 상태 조회라(pages_get 의 지도와 같은 값 · 새로 드러나는 것 0) 감사 행을 쌓지 않는다. 거부는 서버 로그 한 줄.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 문서 public_id (UUID)
 */

$row = community_page_row((string) $params[0], (int) $user['id']);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

[$mayRead, $code] = CommunityAuthz::decideAttachmentRead($user, $row, ['page_id' => (int) $row['id']]);
if (!$mayRead) {
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community attachments-list deny code=' . $code);
    Http::error(404, Http::MSG_NOT_FOUND);
}

$titles = community_attachment_titles((int) $row['id']);

Http::json([
    'attachment_titles' => $titles === [] ? new stdClass() : $titles,
]);
