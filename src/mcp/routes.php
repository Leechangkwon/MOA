<?php
declare(strict_types=1);

// Fixed site paths, independent of both legacy session/API-token route allowlists.
const MCP_ROUTES = [
    ['GET', '/.well-known/oauth-protected-resource/mcp', 'resource', 'public'],
    ['GET', '/.well-known/oauth-protected-resource', 'resource', 'public'],
    ['GET', '/.well-known/oauth-authorization-server', 'authorization_server', 'public'],
    ['POST', '/tbox/oauth/register', 'register', 'public'],
    ['GET', '/tbox/oauth/authorize', 'authorize_start', 'public'],
    ['POST', '/tbox/oauth/authorize', 'consent', 'session'],
    ['POST', '/tbox/oauth/token', 'token', 'public'],
    ['POST', '/tbox/oauth/revoke', 'revoke', 'public'],
    ['GET', '/tbox/mcp-connect', 'connect_page', 'session'],
    ['GET', '/tbox/api/mcp/connections', 'connections', 'session'],
    ['DELETE', '/tbox/api/mcp/connections/{id}', 'disconnect', 'session'],
];
