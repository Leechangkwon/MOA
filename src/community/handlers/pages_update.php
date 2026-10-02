<?php
declare(strict_types=1);

// C:S-18: ordinary edits and accepted suggestions use exactly the same write path.
$body = Http::jsonBody(Doc::limit('doc_bytes') + 65536, 2 * (Doc::limit('depth') + 1) + 7);
Http::json(community_save_doc($user, (string) $params[0], $body));
