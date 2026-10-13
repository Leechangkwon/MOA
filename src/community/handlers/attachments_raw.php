<?php
declare(strict_types=1);

/**
 * src/community/handlers/attachments_raw.php — GET /community/api/pages/{id}/attachments/{att}/raw (정책: community.read)
 *
 * 요구사항: 사이트 Q:F-12 U3-1, 커뮤니티 ARCHITECTURE 24.3 · 24.4 · 24.7, 지시서 docs/qf12-u3-directive.md ③.
 *
 * 문서 본문의 그림 블록(`file_ref = att:<uuid>`)이 쓰는 inline 창구다.
 *   · 관문은 `community_attachment_gate()` 한 벌(download 와 같은 순서 · 같은 감사) — 판정은 `CommunityAuthz::decideAttachmentRead`
 *     (첨부의 page_id = 주소의 문서 + 그 문서의 읽기 판정, 관리자 특권 갈래 끔). 거부는 404 · 검사 중 423 · 감염 403.
 *   · 그림 셋(image/png · jpeg · gif)이고 검사가 끝난(clean) 것만 200. PDF · 글 파일은 raw 로 내보내지 않는다(404 — 받기 창구로).
 *   · 머리는 자료실 미리보기와 **같은 함수**(`Storage::streamInline`) — CSP `sandbox; default-src 'none'` · nosniff · no-store ·
 *     no-referrer. 형식은 DB 의 mime(업로드 때 finfo 로 확정한 값)이고, 그 함수가 그림 · PDF 정규식으로 한 번 더 막는다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params [0] = 문서 public_id · [1] = 첨부 public_id
 */

[, $att] = community_attachment_gate($user, (string) $params[0], (string) $params[1], 'raw');

Storage::streamInline((string) $att['stored_name'], (string) $att['original_name'], (string) $att['mime'], Storage::AREA_ATTACHMENTS);
