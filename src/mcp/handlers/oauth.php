<?php
declare(strict_types=1);

if ($action === 'resource' || $action === 'authorization_server') {
    McpOAuth::reply(McpOAuth::metadata($action === 'authorization_server'));
}
McpOAuth::requireEnabled();
McpOAuth::checkOrigin();
if ($action === 'register') {
    McpOAuth::reply(McpOAuth::register(Http::jsonBody(16384)), 201);
}
if ($action === 'authorize_start') {
    $query = McpOAuth::parseForm((string) ($_SERVER['QUERY_STRING'] ?? ''));
    if ($query === null) { McpOAuth::fail('invalid_request'); }
    McpOAuth::startAuthorization($query);
}
if ($action === 'consent') {
    McpOAuth::reply(McpOAuth::consent($user, Http::jsonBody(4096)));
}
if ($action === 'token') { McpOAuth::reply(McpOAuth::token(McpOAuth::formBody())); }
if ($action === 'revoke') {
    McpOAuth::revokeToken(McpOAuth::formBody());
    McpOAuth::reply([]);
}
McpOAuth::fail('not_found', 404);
