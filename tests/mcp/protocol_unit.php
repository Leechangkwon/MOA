<?php
declare(strict_types=1);
/** Actual PHP dispatcher/schema tests with synthetic auth and no database/network. Run: php tests/mcp/protocol_unit.php */
final class Authz {
    public static function authorize(string $policy, array $user): bool { return $policy === 'community.read' ? ($user['read_allowed'] ?? $user['allowed'] ?? false) : ($user['allowed'] ?? false); }
}
final class Db {
    public static int $rollbacks = 0; public static int $depth = 0;
    public static function instance(): self { return new self(); }
    public function begin(): void { self::$depth++; }
    public function commit(): void { self::$depth--; }
    public function rollback(): void { self::$rollbacks++; self::$depth = 0; }
}
final class Audit {
    public static array $events = []; public static bool $fail = false;
    public static function allow(string $action, array $detail): void {
        if (self::$fail) { throw new RuntimeException('synthetic audit failure with secret-like text'); }
        self::$events[] = [$action,'allow',$detail];
    }
    public static function deny(string $action, string $reason, array $detail): void { self::$events[] = [$action,$reason,$detail]; }
}
require __DIR__ . '/../../src/Http.php';
require __DIR__ . '/../../src/mcp/McpTools.php';
require __DIR__ . '/../../src/mcp/RemoteMcp.php';
$scopes = ['memories.read', 'photos.read', 'memories.write', 'travel.read', 'travel.write'];
$context = ['user' => ['id' => 17, 'username' => 'synthetic_owner', 'display_name' => 'Synthetic', 'allowed' => true], 'scopes' => $scopes, 'grant_id' => 'fixture'];
$n = 0;
function check(bool $ok, string $label): void { global $n; if (!$ok) { fwrite(STDERR, "FAIL $label\n"); exit(1); } $n++; }
function rpc(string $method, $params = null, $id = 1, ?array $auth = null, ?string $protocol = null): array {
    global $context;
    $request = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
    if ($params !== null) { $request['params'] = $params; }
    return RemoteMcp::process(json_encode($request, JSON_THROW_ON_ERROR), $auth ?? $context, $protocol);
}
$init = rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'fixture', 'version' => '1']]);
if (($argv[1] ?? '') === '--fixtures') {
    echo json_encode(['tools' => McpTools::definitions($scopes), 'initialize' => $init['body'], 'ping' => rpc('ping')['body'],
        'whoami' => rpc('tools/call', ['name' => 'qaredi_whoami', 'arguments' => new stdClass()])['body']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}
check($init['body']['result']['protocolVersion'] === '2025-06-18', 'initialize supported version');
foreach (RemoteMcp::VERSIONS as $version) {
    $r = rpc('initialize', ['protocolVersion' => $version, 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'fixture', 'version' => '1']], 1, null, $version);
    check($r['body']['result']['protocolVersion'] === $version, 'version negotiation ' . $version);
}
$r = rpc('initialize', ['protocolVersion' => 'unknown', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'fixture', 'version' => '1']]);
check($r['body']['result']['protocolVersion'] === '2025-06-18', 'unsupported requested version negotiates latest supported');
check(rpc('ping', null, 0)['body']['id'] === 0, 'zero request id');
check(json_encode(rpc('ping')['body']['result']) === '{}', 'ping result is an object');
check(rpc('ping', null, 'request-1')['body']['id'] === 'request-1', 'string request id retained');
foreach ([null, true, 1.25, [], new stdClass(), str_repeat('a', 129), "bad\n", 9007199254740992] as $id) {
    check(rpc('ping', null, $id)['body']['error']['code'] === -32600, 'invalid request id');
}
foreach (['[1]', '[]', 'null', 'true', '"hello"', '{"jsonrpc":"2.0","method":"ping","params":[]}',
    '{"jsonrpc":"2.0","id":1,"method":"ping","result":{}}', '{"jsonrpc":"1.0","id":1,"method":"ping"}'] as $raw) {
    check(RemoteMcp::process($raw, $context)['body']['error']['code'] === -32600, 'invalid JSON RPC shape');
}
check(RemoteMcp::process('{', $context)['body']['error']['code'] === -32700, 'JSON parse error');
check(RemoteMcp::process(str_repeat(' ', RemoteMcp::MAX_BODY + 1), $context)['status'] === 413, 'request byte limit');
check(rpc('ping', null, 1, null, '2024-11-05')['status'] === 400, 'invalid HTTP protocol version');
check(rpc('unknown/method')['body']['error']['code'] === -32601, 'unknown method');
check(rpc('notifications/initialized')['body']['error']['code'] === -32601, 'notification cannot be a request');
foreach (['notifications/initialized', 'notifications/cancelled', 'notifications/unknown'] as $method) {
    $r = RemoteMcp::process(json_encode(['jsonrpc' => '2.0', 'method' => $method]), $context);
    check($r['status'] === 202 && $r['body'] === null, 'notification accepted without response');
}
check(RemoteMcp::process('{"jsonrpc":"2.0","method":"tools/call","params":{"name":"qaredi_whoami"}}', $context)['status'] === 400, 'notification cannot invoke a tool');
foreach ([[], ['capabilities' => []], ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'x', 'version' => '1']]] as $params) {
    check(isset(rpc('initialize', (object) $params)['body']['error']), 'initialize requires complete object parameters');
}
check(rpc('ping', ['extra' => true])['body']['error']['code'] === -32602, 'ping arguments bounded');
check(count(McpTools::definitions($scopes)) === 10, 'all ten tools defined');
check(count(McpTools::definitions(['memories.read'])) === 3, 'read-only default tools');
check(count(McpTools::definitions(['memories.write'])) === 0, 'write alone never implies read in dispatcher');
check(count(McpTools::definitions(['photos.read'])) === 0, 'photos alone insufficient');
check(count(McpTools::definitions(['travel.read'])) === 2, 'travel read tools');
foreach (McpTools::definitions($scopes) as $tool) {
    check($tool['inputSchema']['additionalProperties'] === false, 'fixed tool arguments');
    check($tool['inputSchema']['properties'] instanceof stdClass, 'properties object including empty schemas');
    check($tool['annotations']['openWorldHint'] === false, 'no outbound tool');
    if (!$tool['annotations']['readOnlyHint']) { check(str_contains($tool['description'], 'confirmation'), 'writes describe confirmation'); }
}
check(rpc('tools/list', ['cursor' => 'x'])['body']['error']['code'] === -32602, 'unknown pagination rejected');
check(rpc('tools/call', ['name' => 'system', 'arguments' => new stdClass()])['body']['error']['code'] === -32602, 'no arbitrary callable dispatch');
check(rpc('tools/call', ['name' => 'qaredi_whoami', 'arguments' => []])['body']['error']['code'] === -32602, 'arguments must be object');
$r = rpc('tools/call', ['name' => 'qaredi_whoami', 'arguments' => new stdClass()], 'who');
check($r['body']['id'] === 'who' && $r['body']['result']['isError'] === false, 'whoami succeeds');
$body = json_decode($r['body']['result']['content'][0]['text'], true);
check($body['username'] === 'synthetic_owner' && !array_intersect(['id', 'grant_id', 'csrf_token'], array_keys($body)), 'whoami minimal safe fields');
foreach ([['arguments' => ['owner_user_id' => 1]], ['scopes' => []], ['user' => ['id' => 17, 'allowed' => false]], ['user' => ['id' => 17, 'allowed' => true, 'acting' => true]]] as $change) {
    $r = rpc('tools/call', ['name' => 'qaredi_whoami', 'arguments' => (object) ($change['arguments'] ?? [])], 'error-id', array_replace($context, array_diff_key($change, ['arguments' => true])));
    check($r['body']['id'] === 'error-id' && $r['body']['result']['isError'] === true, 'tool denial preserves JSON RPC id');
}
check(!RemoteMcp::isToolCall(), 'tool error adapter reset in finally');
check(Db::$depth === 0 && count(Audit::$events) >= 5, 'tool calls close transaction and audit allow/deny');
foreach (Audit::$events as $event) {
    check(array_keys($event[2]['detail']) === ['tool'] && McpTools::known($event[2]['detail']['tool']), 'audit contains only a fixed tool name');
}
Audit::$fail = true;
$auditError = rpc('tools/call', ['name'=>'qaredi_whoami'], 'audit-failure');
check($auditError['body']['result']['isError'] === true && Db::$depth === 0 && !str_contains(json_encode($auditError), 'secret-like'), 'audit failure rolls back and returns sanitized tool error');
Audit::$fail = false;
$reader = array_replace($context, ['user' => ['id' => 17, 'username' => 'reader', 'allowed' => false, 'read_allowed' => true]]);
check(rpc('tools/call', ['name'=>'qaredi_whoami'], 1, $reader)['body']['result']['isError'] === false, 'read-only eligible account can use read tools');
$adapter = new ReflectionProperty(RemoteMcp::class, 'toolCall'); $adapter->setAccessible(true); $adapter->setValue(null, true);
try { Http::error(409, 'synthetic conflict'); check(false, 'Http must throw in MCP tool context'); }
catch (McpToolError $e) { check($e->status === 409, 'real Http error adapter preserves domain status'); }
finally { $adapter->setValue(null, false); }
echo "MCP_PROTOCOL_UNIT_PASS $n\n";
