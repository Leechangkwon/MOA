<?php
declare(strict_types=1);

require_once __DIR__ . '/McpOAuth.php';
require_once __DIR__ . '/routes.php';

/** This entry has its own explicit authentication policy. No global CSRF exceptions. */
function mcp_dispatch(string $path, string $method): void
{
    try {
        if ($path === '/mcp') {
            define('TBOX_ROUTE', $method . ' /mcp');
            define('TBOX_AUTH_METHOD', 'mcp_oauth');
            // Even failed Bearer auth must never attribute a request to an incidental cookie.
            Audit::remoteMcpActor(null);
            require_once __DIR__ . '/../community/Memories.php';
            require_once __DIR__ . '/../community/TravelPlans.php';
            require_once __DIR__ . '/McpTools.php';
            require_once __DIR__ . '/RemoteMcp.php';
            RemoteMcp::handle();
            exit;
        }
        $matched = null; $allowed = [];
        foreach (MCP_ROUTES as $route) {
            if (!in_array($route[3], ['session', 'public'], true)) { throw new LogicException('Invalid MCP policy'); }
            $pattern = $route[1] === '/tbox/api/mcp/connections/{id}'
                ? '#^/tbox/api/mcp/connections/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$#D'
                : '#^' . preg_quote($route[1], '#') . '$#D';
            if (preg_match($pattern, $path, $params) !== 1) { continue; }
            $allowed[] = $route[0];
            if ($method === $route[0]) { $matched = [$route, $params]; break; }
        }
        if ($matched === null) {
            if ($allowed !== []) { header('Allow: ' . implode(', ', $allowed)); McpOAuth::fail('method_not_allowed', 405); }
            McpOAuth::fail('not_found', 404);
        }
        [$route, $params] = $matched;
        define('TBOX_ROUTE', $method . ' ' . $route[1]);
        $action = $route[2]; $user = null;
        if ($route[3] === 'session') { $user = McpOAuth::requireSession($action === 'connect_page'); }
        else { Audit::remoteMcpActor(null); }
        if ($action === 'connect_page') {
            require __DIR__ . '/handlers/connect_page.php';
        } elseif ($action === 'connections' || $action === 'disconnect') {
            require __DIR__ . '/handlers/connections.php';
        } else {
            require __DIR__ . '/handlers/oauth.php';
        }
        exit;
    } catch (Throwable $e) {
        // The shared exception handler includes exception text; PDO text can contain input values.
        // Here log only class and request identifier, never code, state, token, photo or body.
        if (Db::connected()) { Db::instance()->rollback(); }
        error_log('[tbox] remote_mcp request_id=' . TBOX_REQUEST_ID . ' exception=' . get_class($e));
        McpOAuth::fail('server_error', 500);
    }
}
