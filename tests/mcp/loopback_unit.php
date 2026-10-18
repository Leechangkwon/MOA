<?php
declare(strict_types=1);
require_once __DIR__ . '/../../src/Env.php';
require_once __DIR__ . '/../../src/mcp/McpOAuth.php';
$mode = $argv[1] ?? '0';
if (!in_array($mode, ['0', '1', 'true'], true)) { exit(2); }
$file = tempnam(sys_get_temp_dir(), 'mcp-loopback-');
file_put_contents($file, "MCP_OAUTH_REDIRECT_URIS=[\"https://chatgpt.test/callback\"]\nMCP_OAUTH_LOOPBACK_ENABLED=" . $mode . "\n");
Env::load($file); unlink($file);
$passed = 0;
function check(bool $ok, string $name): void {
    global $passed;
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    $passed++;
}
$enabled = $mode === '1';
$valid = ['http://127.0.0.1/callback', 'http://127.0.0.1:1/callback',
    'http://127.0.0.1:80/callback', 'http://127.0.0.1:49152/callback', 'http://127.0.0.1:65535/callback'];
foreach ($valid as $uri) {
    check(McpOAuth::loopbackRedirect($uri), 'literal native callback shape');
    check(McpOAuth::redirectAllowed($uri) === $enabled, 'explicit opt-in only');
}
$bad = ['http://localhost:49152/callback', 'http://127.0.0.2:49152/callback',
    'http://[::1]:49152/callback', 'http://127.1:49152/callback', 'http://2130706433/callback',
    'http://127.0.0.1.attacker.test/callback', 'http://127.0.0.1@attacker.test/callback',
    'http://attacker.test@127.0.0.1/callback', 'https://127.0.0.1/callback', 'HTTP://127.0.0.1/callback',
    'http://127.0.0.1:0/callback', 'http://127.0.0.1:65536/callback', 'http://127.0.0.1:049152/callback',
    'http://127.0.0.1:+49152/callback', 'http://127.0.0.1:/callback',
    'http://127.0.0.1/callback?state=overwrite', 'http://127.0.0.1/callback?',
    'http://127.0.0.1/callback#fragment', 'http://127.0.0.1/callback#',
    'http://127.0.0.1/callback/child', 'http://127.0.0.1/callback/',
    'http://127.0.0.1/callback/../callback', 'http://127.0.0.1/%63allback',
    "http://127.0.0.1/callback\n", "http://127.0.0.1/callback\0", '//127.0.0.1/callback'];
foreach ($bad as $uri) {
    check(!McpOAuth::loopbackRedirect($uri), 'reject callback confusion');
    check(!McpOAuth::redirectAllowed($uri), 'unsafe callback never allowed');
}
check(McpOAuth::redirectAllowed('https://chatgpt.test/callback'), 'existing exact HTTPS retained');
check(!McpOAuth::redirectAllowed('https://chatgpt.test/callback?changed=1'), 'HTTPS remains exact');
$validate = new ReflectionMethod(McpOAuth::class, 'validRedirect');
$validate->setAccessible(true);
$web = ['redirect_uris' => '["https://chatgpt.test/callback"]'];
$desktop = ['redirect_uris' => '["http://127.0.0.1:45123/callback"]'];
check($validate->invoke(null, $web, 'https://chatgpt.test/callback'), 'registered web client exact URI');
check(!$validate->invoke(null, $web, 'http://127.0.0.1:45123/callback'), 'web client cannot switch to loopback');
check(!$validate->invoke(null, $desktop, 'https://chatgpt.test/callback'), 'desktop client cannot switch to web');
check($validate->invoke(null, $desktop, 'http://127.0.0.1:45124/callback') === $enabled, 'registered native client port variance');
foreach ($bad as $uri) {
    check(!$validate->invoke(null, $desktop, $uri), 'client binding rejects unsafe variant');
}
echo "PASS: $passed loopback checks (opt-in=$mode)\n";
