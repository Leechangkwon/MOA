<?php
declare(strict_types=1);

// C:S-18: ordinary edits and accepted suggestions use exactly the same write path.
$body = Http::jsonBody(Doc::limit('doc_bytes') + 65536, 2 * (Doc::limit('depth') + 1) + 7);

/* 사이트 Q:F-12 카페 C1 (ARCH 25.3) — 익명게시판 글의 본문에는 자료실 파일 블록(`tbox:` 그림 · 파일)을 넣지 않는다:
   자료실 창구가 올린 사람(`uploaded_by`)을 보여 준다. 이 문서에 올리기(`att:`)는 그대로 된다(그림 메타데이터는 지운다).
   볼 수 있는 사람의 원래 행으로만 묻는다(못 보는 글은 아래 저장 길이 404 · 403 으로 끝낸다 — 「익명 글인가」가 새지 않게).
   모양이 틀린 본문은 여기서 판단하지 않고 아래 저장 길의 검증(400)에 맡긴다. Doc.php 는 그대로 — 저장 창구 쪽 검사다. */
if (is_array($body['doc'] ?? null)) {
    $anonRow = community_page_row_nodoc((string) $params[0], (int) $user['id']);
    if ($anonRow !== null && CommunityAuthz::decidePageRead($user, $anonRow)[0] && community_page_is_anon($anonRow)) {
        try {
            $anonFiles = Doc::extract($body['doc'])['files'];
        } catch (Throwable $e) {
            $anonFiles = [];
        }
        if ($anonFiles !== []) {
            Http::json(['error' => '익명게시판에는 자료실 파일을 넣을 수 없어요 — 이 문서에 올리기를 쓰세요',
                'reason' => CommunityAuthz::CODE_ANON_BOARD], 400);
            exit;
        }
    }
}
Http::json(community_save_doc($user, (string) $params[0], $body));
