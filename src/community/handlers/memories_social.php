<?php
declare(strict_types=1);
/** Community dispatcher checks session, read/write policy and CSRF before this handler. */
Memories::requireReady();
if (TBOX_ROUTE === 'GET /community/api/memories/social') {
    Http::json(MemorySocial::directory($user)); exit;
}
if (TBOX_ROUTE === 'PATCH /community/api/memories/preferences') {
    Http::json(MemorySocial::preferences($user)); exit;
}
if (in_array(TBOX_ROUTE, ['POST /community/api/memories/follow', 'DELETE /community/api/memories/follow'], true)) {
    Http::json(MemorySocial::change($user, $_SERVER['REQUEST_METHOD'] === 'POST')); exit;
}
Http::error(404, Http::MSG_NOT_FOUND);
