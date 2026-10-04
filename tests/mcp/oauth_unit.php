<?php
declare(strict_types=1);
// Pure validation boundaries; run with PHP 8.1+. No database or real credentials.
require_once __DIR__ . '/../../src/Env.php';
require_once __DIR__ . '/../../src/Http.php';
require_once __DIR__ . '/../../src/mcp/McpOAuth.php';
$passed = 0;
function check(bool $ok, string $name): void {
    global $passed;
    if (!$ok) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    $passed++;
}
$verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
check(McpOAuth::pkceMatches($verifier, $challenge), 'RFC 7636 S256 vector');
foreach (['', str_repeat('a',42), str_repeat('a',129), str_repeat('a',42).'!', $verifier."\n", str_repeat('x',43)] as $bad) {
    check(!McpOAuth::pkceMatches($bad,$challenge), 'PKCE verifier boundary');
}
check(!McpOAuth::pkceMatches($verifier, $verifier), 'plain PKCE rejected');
check(!McpOAuth::pkceMatches($verifier,$challenge.'='), 'padded challenge rejected');
check(McpOAuth::registrationGrants(['authorization_code']) === ['authorization_code'], 'code-only registration');
check(McpOAuth::registrationGrants(['refresh_token','authorization_code']) === ['authorization_code','refresh_token'], 'registration order independent');
foreach ([[],['refresh_token'],['authorization_code','password'],['authorization_code','authorization_code'],['authorization_code',[]],'authorization_code'] as $bad) {
    check(McpOAuth::registrationGrants($bad) === null, 'registration grant rejection');
}
check(McpOAuth::scopes('memories.read') === ['memories.read'], 'read default');
check(McpOAuth::scopes('travel.read memories.read travel.write') === ['memories.read','travel.read','travel.write'], 'canonical scope set');
foreach (['','memories.write','photos.read','memories.read travel.write','memories.read unknown','memories.read memories.read',' memories.read','memories.read ','memories.read  photos.read','memories.read\tphotos.read'] as $bad) {
    check(McpOAuth::scopes($bad) === null, 'scope rejection');
}
check(McpOAuth::scopes(implode(' ',McpOAuth::SCOPES)) === McpOAuth::SCOPES, 'full scopes valid');
check(McpOAuth::parseForm('state=a%2Bb&scope=memories.read+photos.read') === ['state'=>'a+b','scope'=>'memories.read photos.read'], 'OAuth form decoding');
check(McpOAuth::parseForm('code=a&code=b') === null, 'duplicate code');
check(McpOAuth::parseForm('code=a&%63ode=b') === null, 'encoded duplicate code');
foreach (['','code','code=a&','code[]=a','code=a%0D%0A','code=%FF','code=%Z0','code=%','x.y=a',str_repeat('a',16385)] as $bad) {
    check(McpOAuth::parseForm($bad) === null, 'form shape rejection');
}
check(McpOAuth::httpsUrl('https://chatgpt.test/callback?fixed=1'), 'HTTPS callback');
foreach (['http://chatgpt.test/callback','https://user@chatgpt.test/callback','https://user:pass@chatgpt.test/callback','//chatgpt.test/callback','https://chatgpt.test/callback#code','https://chatgpt.test/\\evil','https://chatgpt.test/ bad',"https://chatgpt.test/a\n"] as $bad) {
    check(!McpOAuth::httpsUrl($bad), 'unsafe callback');
}
// Isolated temporary env, no production path or secret. Env's one-shot load is intentional.
$file = tempnam(sys_get_temp_dir(), 'mcp-oauth-unit-');
file_put_contents($file,"APP_URL=https://moa.test/\nMCP_REMOTE_ENABLED=0\nMCP_OAUTH_REDIRECT_URIS=[\"https://chatgpt.test/callback\"]\n");
Env::load($file); unlink($file);
check(McpOAuth::issuer() === 'https://moa.test', 'issuer from APP_URL');
$_SERVER['HTTP_HOST'] = 'attacker.test';
check(McpOAuth::resource() === 'https://moa.test/mcp', 'Host header ignored');
check(McpOAuth::redirectAllowlist() === ['https://chatgpt.test/callback'], 'exact configured redirect');
check(!McpOAuth::enabled() && McpOAuth::status() === 'disabled', 'disabled before database access');
foreach (file(__DIR__.'/../lib/return_path_vectors.txt', FILE_IGNORE_NEW_LINES) as $line) {
    if ($line === '' || $line[0] === '#') { continue; }
    [$raw,$expected] = explode("\t",$line,2);
    check(Http::returnPath($raw) === ($expected === '-' ? null : $expected), 'PHP canonical return path');
}
echo "PASS: $passed OAuth validation and return-path checks\n";
