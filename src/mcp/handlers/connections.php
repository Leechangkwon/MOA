<?php
declare(strict_types=1);

if ($action === 'disconnect') {
    McpOAuth::revokeConnection($user, $params[1]);
    McpOAuth::reply(['ok' => true]);
}
$raw = (string) ($_SERVER['QUERY_STRING'] ?? '');
$query = $raw === '' ? [] : McpOAuth::parseForm($raw);
if ($query === null || array_diff(array_keys($query), ['pending'])) { McpOAuth::fail('invalid_request'); }
McpOAuth::reply(McpOAuth::connectionPayload($user, $query['pending'] ?? null));
