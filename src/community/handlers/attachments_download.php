<?php
declare(strict_types=1);

/**
 * src/community/handlers/attachments_download.php — GET /community/api/pages/{id}/attachments/{att}/download (정책: community.read)
 *
 * 요구사항: 사이트 Q:F-12 U3-1, 커뮤니티 ARCHITECTURE 24.3, 지시서 docs/qf12-u3-directive.md ③.
 *
 *   · 관문은 `community_attachment_gate()` 한 벌(raw 와 같은 순서 · 같은 감사). 거부 404 · 검사 중 423 · 감염 403.
 *   · 검사가 끝난(clean) 첨부만 200 — 자료실 내려받기와 **같은 함수**(`Storage::stream`): 언제나
 *     `application/octet-stream` + `Content-Disposition: attachment; filename*=UTF-8''…` + `no-store`.
 *     DB 의 mime 은 응답 형식으로 쓰지 않는다(업로드된 HTML · SVG 가 같은 출처에서 그려지지 않게).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params [0] = 문서 public_id · [1] = 첨부 public_id
 */

[, $att] = community_attachment_gate($user, (string) $params[0], (string) $params[1], 'download');

Storage::stream((string) $att['stored_name'], (string) $att['original_name'], (string) $att['mime'], Storage::AREA_ATTACHMENTS);
