<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/Programs.php';
// Deliberately absent from all API-token route lists.
if (Http::method()==='GET') {
    Http::json(['programs'=>Programs::listing($user)]);
} elseif ((string)TBOX_ROUTE==='POST /api/programs') {
    Programs::publish($user,Http::jsonBody(4096));
    Http::json(['ok'=>true],201);
} else {
    $body=Http::jsonBody(256);
    if (array_keys($body)!==['ack'] || $body['ack']!==true) Http::error(400,Http::MSG_BAD_REQUEST);
    Programs::download($user,(string)($params[0] ?? ''));
}
