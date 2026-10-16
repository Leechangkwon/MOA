<?php
declare(strict_types=1);

/** OAuth 2.1 code + PKCE S256, RFC 7591/8414/8707/9728. Public clients only.
 * No fetch, sessions-as-bearers, plaintext token storage or request-content logs.
 */
final class McpOAuth
{
    public const SCOPES = ['memories.read', 'photos.read', 'memories.write', 'travel.read', 'travel.write', 'work.read', 'work.write'];
    public const ACCESS_SECONDS = 600;
    private const HANDLE_RE = '/^[A-Za-z0-9_-]{43}$/D';
    private static bool $auditProtocolFailures = false;

    public static function issuer(): string
    {
        $value = rtrim(Env::getOrEmpty('APP_URL'), '/');
        if (!self::httpsUrl($value)) { return ''; }
        $p = parse_url($value);
        return is_array($p) && !isset($p['query']) && ($p['path'] ?? '') === '' ? $value : '';
    }

    public static function resource(): string
    {
        $issuer = self::issuer();
        return $issuer === '' ? '' : $issuer . '/mcp';
    }

    public static function httpsUrl(string $uri): bool
    {
        if (strlen($uri) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)
            || filter_var($uri, FILTER_VALIDATE_URL) === false) { return false; }
        $p = parse_url($uri);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && isset($p['host'])
            && !isset($p['user']) && !isset($p['pass'])
            && !isset($p['fragment']);
    }

    /** Operator configuration is an exact JSON array, never wildcard/prefix matching. */
    public static function redirectAllowlist(): array
    {
        $list = json_decode(Env::getOrEmpty('MCP_OAUTH_REDIRECT_URIS'), true);
        if (!is_array($list) || !array_is_list($list) || count($list) < 1 || count($list) > 20) { return []; }
        foreach ($list as $uri) {
            if (!is_string($uri) || !self::httpsUrl($uri)) { return []; }
            $query = parse_url($uri, PHP_URL_QUERY);
            if (is_string($query)) {
                foreach (explode('&', $query) as $pair) {
                    $key = urldecode(explode('=', $pair, 2)[0]);
                    if (in_array($key, ['code', 'state', 'error', 'iss'], true)) { return []; }
                }
            }
        }
        return array_values(array_unique($list));
    }

    public static function status(): string
    {
        if (Env::getOrEmpty('MCP_REMOTE_ENABLED') !== '1') { return 'disabled'; }
        if (self::issuer() === '' || self::redirectAllowlist() === []) { return 'unconfigured'; }
        $row = Db::instance()->run("SELECT to_regclass('public.mcp_clients') IS NOT NULL
            AND to_regclass('public.mcp_pending') IS NOT NULL AND to_regclass('public.mcp_grants') IS NOT NULL
            AND to_regclass('public.mcp_codes') IS NOT NULL AND to_regclass('public.mcp_tokens') IS NOT NULL
            AND EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema='public'
                       AND table_name='users' AND column_name='mcp_security_epoch') AS ready")->fetch();
        return is_array($row) && $row['ready'] === true ? 'ready' : 'unconfigured';
    }

    public static function enabled(): bool { return self::status() === 'ready'; }
    public static function requireEnabled(): void
    {
        if (!self::enabled()) { self::fail('temporarily_unavailable', 503); }
    }

    public static function checkOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && (!is_string($origin) || self::issuer() === '' || $origin !== self::issuer())) {
            self::fail('invalid_request', 403);
        }
    }

    public static function fail(string $error, int $status = 400): void
    {
        if (self::$auditProtocolFailures) {
            self::$auditProtocolFailures = false;
            Audit::deny('mcp.oauth', 'rejected', ['target_type' => 'system', 'target_name' => 'remote_mcp']);
        }
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        Http::json(['error' => $error], $status);
        exit;
    }

    public static function reply(array $data, int $status = 200): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        Http::json($data, $status);
        exit;
    }

    private static function unauthorized(): void
    {
        Audit::deny('mcp.auth', 'invalid_token', ['target_type' => 'system', 'target_name' => 'remote_mcp']);
        header('WWW-Authenticate: Bearer resource_metadata="' . self::issuer()
            . '/.well-known/oauth-protected-resource/mcp", error="invalid_token"');
        self::fail('invalid_token', 401);
    }

    public static function metadata(bool $authorization): array
    {
        self::requireEnabled();
        $issuer = self::issuer();
        if (!$authorization) {
            return ['resource' => self::resource(), 'authorization_servers' => [$issuer],
                'scopes_supported' => self::SCOPES, 'bearer_methods_supported' => ['header'],
                'resource_name' => '모아 내 글·사진·여행'];
        }
        return ['issuer' => $issuer, 'authorization_response_iss_parameter_supported' => true,
            'authorization_endpoint' => $issuer . '/tbox/oauth/authorize',
            'token_endpoint' => $issuer . '/tbox/oauth/token', 'registration_endpoint' => $issuer . '/tbox/oauth/register',
            'revocation_endpoint' => $issuer . '/tbox/oauth/revoke', 'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'], 'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'], 'scopes_supported' => self::SCOPES];
    }

    /** Strict bounded x-www-form-urlencoded parser (reject duplicate/array keys). */
    public static function parseForm(string $raw): ?array
    {
        if ($raw === '' || strlen($raw) > 16384) { return null; }
        $out = [];
        foreach (explode('&', $raw) as $pair) {
            if (strpos($pair, '=') === false) { return null; }
            [$key, $val] = explode('=', $pair, 2);
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $pair)) { return null; }
            $key = urldecode($key); $val = urldecode($val);
            if (preg_match('/^[a-z_]+$/D', $key) !== 1 || array_key_exists($key, $out)
                || preg_match('/[\x00-\x1f\x7f]/', $val) || preg_match('//u', $val) !== 1) { return null; }
            $out[$key] = $val;
        }
        return $out;
    }

    public static function formBody(): array
    {
        if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/x-www-form-urlencoded') {
            self::fail('invalid_request');
        }
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        $data = is_string($raw) ? self::parseForm($raw) : null;
        if ($data === null) { self::fail('invalid_request'); }
        return $data;
    }

    public static function scopes(string $raw): ?array
    {
        if (strlen($raw) > 200 || $raw === '' || trim($raw) !== $raw) { return null; }
        $parts = explode(' ', $raw);
        if (count($parts) !== count(array_unique($parts)) || array_diff($parts, self::SCOPES)
            || !in_array('memories.read', $parts, true)
            || (in_array('work.write', $parts, true) && !in_array('work.read', $parts, true))
            || (in_array('travel.write', $parts, true) && !in_array('travel.read', $parts, true))) { return null; }
        return array_values(array_intersect(self::SCOPES, $parts));
    }

    public static function pkceMatches(string $verifier, string $challenge): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) === 1
            && preg_match(self::HANDLE_RE, $challenge) === 1
            && hash_equals($challenge, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='));
    }

    private static function random(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    private static function digest(string $value): string { return hash('sha256', $value); }

    public static function availableScopes(array $user): array
    {
        $map = ['memories.read' => 'community.read', 'photos.read' => 'community.read',
            'work.read' => 'community.read', 'work.write' => 'community.write',
            'memories.write' => 'community.write', 'travel.read' => 'travel.read', 'travel.write' => 'travel.write'];
        $out = [];
        foreach ($map as $scope => $policy) { if (Authz::authorize($policy, $user)) { $out[] = $scope; } }
        return $out;
    }

    public static function requireSession(bool $page = false): array
    {
        self::checkOrigin();
        if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['HTTP_X_API_TOKEN'])) { self::fail('access_denied', 403); }
        $user = Auth::currentUser();
        if ($user === null && $page) {
            $to = Http::returnPath((string) ($_SERVER['REQUEST_URI'] ?? '')) ?? '/tbox/mcp-connect';
            Http::redirect(Http::url('/login') . '?to=' . rawurlencode($to));
        }
        if ($user === null) { self::fail('login_required', 401); }
        if (($user['acting'] ?? false) === true || ($user['session_origin'] ?? '') !== 'login'
            || ($user['mfa_recovery_required'] ?? false) === true
            || Auth::nextStep($user) !== null) { self::fail('access_denied', 403); }
        Http::requireCsrf();
        return $user;
    }

    /** RFC 7591 arrays are sets. A code-only public client is also valid. */
    public static function registrationGrants($value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 2) { return null; }
        foreach ($value as $grant) { if (!is_string($grant)) { return null; } }
        if (count($value) !== count(array_unique($value))
            || !in_array('authorization_code', $value, true)
            || array_diff($value, ['authorization_code', 'refresh_token'])) { return null; }
        return array_values(array_intersect(['authorization_code', 'refresh_token'], $value));
    }

    public static function register(array $input): array
    {
        self::requireEnabled(); self::checkOrigin();
        $uris = $input['redirect_uris'] ?? null;
        $name = $input['client_name'] ?? 'ChatGPT';
        $grants = self::registrationGrants($input['grant_types'] ?? ['authorization_code', 'refresh_token']);
        if (!is_array($uris) || !array_is_list($uris) || count($uris) < 1 || count($uris) > 10
            || !is_string($name) || preg_match('/^[^\p{C}]{1,80}$/uD', $name) !== 1
            || ($input['token_endpoint_auth_method'] ?? 'none') !== 'none'
            || $grants === null
            || ($input['response_types'] ?? ['code']) !== ['code']) { self::fail('invalid_client_metadata'); }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !in_array($uri, self::redirectAllowlist(), true)) { self::fail('invalid_redirect_uri'); }
        }
        // Ignore inert display metadata, but never fetch URLs or support CIMD/private secret auth.
        $db = Db::instance(); $db->begin();
        $db->run('SELECT pg_advisory_xact_lock(724016, 1)');
        self::cleanup();
        $n = $db->run('SELECT count(*) AS n FROM public.mcp_clients')->fetch();
        if ((int) $n['n'] >= 2000) { $db->rollback(); self::fail('temporarily_unavailable', 503); }
        $id = 'mcp_client_' . self::random();
        $db->run('INSERT INTO public.mcp_clients(id,name,redirect_uris,grant_types) VALUES(:id,:name,CAST(:uris AS jsonb),CAST(:grants AS jsonb))',
            [':id' => $id, ':name' => $name, ':uris' => json_encode(array_values(array_unique($uris))), ':grants' => json_encode($grants)]);
        $db->commit();
        return ['client_id' => $id, 'client_id_issued_at' => time(), 'client_name' => $name,
            'redirect_uris' => array_values(array_unique($uris)), 'token_endpoint_auth_method' => 'none',
            'grant_types' => $grants, 'response_types' => ['code']];
    }

    private static function client(string $id): ?array
    {
        if (preg_match('/^mcp_client_[A-Za-z0-9_-]{43}$/D', $id) !== 1) { return null; }
        $row = Db::instance()->run('SELECT id,name,redirect_uris,grant_types FROM public.mcp_clients WHERE id=:id AND expires_at>now()',
            [':id' => $id])->fetch();
        return is_array($row) ? $row : null;
    }

    private static function validRedirect(array $client, string $uri): bool
    {
        $uris = json_decode((string) $client['redirect_uris'], true);
        return is_array($uris) && in_array($uri, $uris, true) && in_array($uri, self::redirectAllowlist(), true);
    }

    public static function startAuthorization(array $query): void
    {
        self::requireEnabled(); self::checkOrigin();
        $client = self::client($query['client_id'] ?? '');
        $scopes = self::scopes($query['scope'] ?? 'memories.read');
        if ($client === null || !self::validRedirect($client, $query['redirect_uri'] ?? '')
            || ($query['response_type'] ?? '') !== 'code' || ($query['resource'] ?? '') !== self::resource()
            || ($query['code_challenge_method'] ?? '') !== 'S256'
            || preg_match(self::HANDLE_RE, $query['code_challenge'] ?? '') !== 1
            || !isset($query['state']) || strlen($query['state']) < 1 || strlen($query['state']) > 512
            || $scopes === null) { self::fail('invalid_request'); }
        $request = ['redirect_uri' => $query['redirect_uri'], 'scope' => implode(' ', $scopes),
            'resource' => self::resource(), 'challenge' => $query['code_challenge'], 'state' => $query['state']];
        $handle = self::random();
        $db = Db::instance(); $db->begin();
        $db->run('SELECT pg_advisory_xact_lock(724016, 2)');
        $db->run('DELETE FROM public.mcp_pending WHERE expires_at<=now()');
        $n = $db->run('SELECT count(*) AS n FROM public.mcp_pending')->fetch();
        if ((int) $n['n'] >= 5000) { $db->rollback(); self::fail('temporarily_unavailable', 503); }
        $db->run('INSERT INTO public.mcp_pending(handle_hash,client_id,request) VALUES(:hash,:client,CAST(:request AS jsonb))',
            [':hash' => self::digest($handle), ':client' => $client['id'], ':request' => json_encode($request)]);
        $db->commit();
        Http::redirectReturn('/tbox/mcp-connect?pending=' . $handle);
    }

    private static function pending(string $handle, array $user): ?array
    {
        if (preg_match(self::HANDLE_RE, $handle) !== 1) { return null; }
        $db = Db::instance();
        $db->run('UPDATE public.mcp_pending SET session_id=:session,user_id=:user
             WHERE handle_hash=:hash AND expires_at>now() AND session_id IS NULL',
            [':session' => $user['session_id'], ':user' => $user['id'], ':hash' => self::digest($handle)]);
        $row = $db->run('SELECT p.*,c.name AS client_name,c.redirect_uris FROM public.mcp_pending p
             JOIN public.mcp_clients c ON c.id=p.client_id WHERE p.handle_hash=:hash AND p.session_id=:session
             AND p.user_id=:user AND p.expires_at>now() AND c.expires_at>now() FOR UPDATE OF p',
            [':hash' => self::digest($handle), ':session' => $user['session_id'], ':user' => $user['id']])->fetch();
        return is_array($row) ? $row : null;
    }

    public static function connectionPayload(array $user, ?string $handle): array
    {
        $status = self::status(); $issuer = self::issuer();
        $data = ['enabled' => $status === 'ready', 'status' => $status, 'issuer' => $issuer, 'resource' => self::resource(),
            'user' => ['username' => $user['username'], 'display_name' => $user['display_name'] ?? null],
            'csrf_token' => Auth::csrfToken(), 'available_scopes' => self::availableScopes($user),
            'connections' => [], 'pending' => null,
            'metadata' => ['protected_resource_url' => $issuer === '' ? '' : $issuer . '/.well-known/oauth-protected-resource/mcp',
                'authorization_server_url' => $issuer === '' ? '' : $issuer . '/.well-known/oauth-authorization-server']];
        if ($status !== 'ready') { return $data; }
        $db = Db::instance();
        $rows = $db->run('SELECT g.id,c.name AS client_name,g.scope,g.created_at,g.expires_at,g.last_used_at
             FROM public.mcp_grants g JOIN public.mcp_clients c ON c.id=g.client_id
             JOIN public.users u ON u.id=g.user_id WHERE g.user_id=:user AND g.revoked_at IS NULL
             AND g.expires_at>now() AND g.security_epoch=u.mcp_security_epoch ORDER BY g.created_at DESC LIMIT 100',
            [':user' => $user['id']])->fetchAll();
        foreach ($rows as $row) { $row['scopes'] = explode(' ', $row['scope']); unset($row['scope']); $data['connections'][] = $row; }
        if ($handle !== null) {
            $db->begin(); $pending = self::pending($handle, $user); $db->commit();
            if ($pending === null) { self::fail('invalid_request'); }
            $request = json_decode((string) $pending['request'], true);
            $data['pending'] = ['id' => $handle, 'client_name' => $pending['client_name'],
                'requested_scopes' => explode(' ', $request['scope']), 'expires_at' => $pending['expires_at']];
        }
        return $data;
    }

    public static function consent(array $user, array $input): array
    {
        self::requireEnabled();
        $handle = $input['pending'] ?? null; $approve = $input['approve'] ?? null;
        if (!is_string($handle) || !is_bool($approve)) { self::fail('invalid_request'); }
        $db = Db::instance(); $db->begin();
        $pending = self::pending($handle, $user);
        if ($pending === null) { $db->rollback(); self::fail('invalid_request'); }
        $request = json_decode((string) $pending['request'], true);
        if (!self::validRedirect($pending, $request['redirect_uri']) || $request['resource'] !== self::resource()) {
            $db->rollback(); self::fail('invalid_request');
        }
        $scopes = $input['scopes'] ?? [];
        if (!is_array($scopes) || !array_is_list($scopes)) { $db->rollback(); self::fail('invalid_scope'); }
        foreach ($scopes as $s) { if (!is_string($s)) { $db->rollback(); self::fail('invalid_scope'); } }
        $chosen = self::scopes(implode(' ', $scopes));
        // Account changes and parallel approvals serialize before reading the epoch.
        $db->run('SELECT id FROM public.users WHERE id=:id FOR UPDATE',
            [':id' => $user['id']]);
        $current = self::user((int) $user['id']);
        if ($current === null) { $db->rollback(); self::fail('access_denied', 403); }
        if ($approve && ($chosen === null || array_diff($chosen, explode(' ', $request['scope']))
            || array_diff($chosen, self::availableScopes($current)))) { $db->rollback(); self::fail('invalid_scope'); }
        $result = ['state' => $request['state'], 'iss' => self::issuer()];
        if ($approve) {
            // Old credentials cannot occupy a hidden, unrevokable slot in the live grant cap.
            $db->run('UPDATE public.mcp_grants SET revoked_at=now() WHERE user_id=:user
                AND revoked_at IS NULL AND security_epoch<>:epoch',
                [':user' => $user['id'], ':epoch' => $current['mcp_security_epoch']]);
            $n = $db->run('SELECT count(*) AS n FROM public.mcp_grants WHERE user_id=:user AND revoked_at IS NULL AND expires_at>now()',
                [':user' => $user['id']])->fetch();
            if ((int) $n['n'] >= 100) { $db->rollback(); self::fail('temporarily_unavailable', 503); }
            $grant = tbox_uuid_v4(); $code = self::random();
            $db->run('INSERT INTO public.mcp_grants(id,user_id,client_id,security_epoch,scope,resource,expires_at)
                SELECT :id,:user,id,:epoch,:scope,:resource,LEAST(expires_at,now()+interval \'30 days\')
                FROM public.mcp_clients WHERE id=:client',
            [':id' => $grant, ':user' => $user['id'],
                ':client' => $pending['client_id'], ':epoch' => $current['mcp_security_epoch'],
                ':scope' => implode(' ', $chosen), ':resource' => self::resource()]);
            $db->run('INSERT INTO public.mcp_codes(code_hash,grant_id,redirect_uri,challenge) VALUES(:hash,:grant,:uri,:challenge)',
                [':hash' => self::digest($code), ':grant' => $grant, ':uri' => $request['redirect_uri'], ':challenge' => $request['challenge']]);
            $db->run('UPDATE public.mcp_clients SET authorized_at=COALESCE(authorized_at,now()) WHERE id=:client',
                [':client' => $pending['client_id']]);
            $result['code'] = $code;
            Audit::allow('mcp.connect', ['target_type' => 'system', 'target_name' => 'remote_mcp']);
        } else { $result['error'] = 'access_denied'; }
        $db->run('DELETE FROM public.mcp_pending WHERE handle_hash=:hash',
            [':hash' => self::digest($handle)]);
        $db->commit();
        return ['redirect_url' => $request['redirect_uri'] . (strpos($request['redirect_uri'], '?') === false ? '?' : '&')
            . http_build_query($result, '', '&', PHP_QUERY_RFC3986)];
    }

    /** Current account state only, with no password/MFA secrets in the returned user. */
    private static function user(int $id): ?array
    {
        $row = Db::instance()->run('SELECT id,username,display_name,role,team_id,is_active,mfa_enabled,
            must_change_password,mfa_setup_required,mfa_recovery_required,invited_account,mcp_security_epoch,
            (expires_at IS NOT NULL AND expires_at<=now()) AS is_expired,
            (locked_until IS NOT NULL AND locked_until>now()) AS is_locked FROM public.users WHERE id=:id',
            [':id' => $id])->fetch();
        if (!is_array($row) || $row['is_active'] !== true || $row['is_expired'] === true
            || $row['is_locked'] === true || $row['mfa_recovery_required'] === true
            || Auth::nextStep($row) !== null) { return null; }
        $row['id'] = (int) $row['id']; $row['team_id'] = $row['team_id'] === null ? null : (int) $row['team_id'];
        return $row;
    }

    private static function grantOk(array $grant): ?array
    {
        $user = self::user((int) $grant['user_id']);
        $scopes = self::scopes((string) $grant['scope']);
        if ($user === null || $grant['revoked_at'] !== null || $grant['is_expired'] === true
            || (string) $user['mcp_security_epoch'] !== (string) $grant['security_epoch']
            || $grant['resource'] !== self::resource() || $scopes === null
            || array_diff($scopes, self::availableScopes($user))) { return null; }
        return $user;
    }

    /** All exchange paths bind client+resource before consuming anything. */
    public static function token(array $input): array
    {
        self::requireEnabled(); self::checkOrigin();
        self::$auditProtocolFailures = true;
        if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($input['client_secret'])) { self::fail('invalid_client', 401); }
        $client = self::client($input['client_id'] ?? '');
        if ($client === null) { self::fail('invalid_client', 401); }
        if (($input['resource'] ?? '') !== self::resource()) { self::fail('invalid_target'); }
        $kind = $input['grant_type'] ?? '';
        if (!in_array($kind, ['authorization_code', 'refresh_token'], true)) { self::fail('unsupported_grant_type'); }
        $clientGrants = json_decode((string) $client['grant_types'], true);
        if (!is_array($clientGrants) || !in_array($kind, $clientGrants, true)) { self::fail('unauthorized_client'); }
        $db = Db::instance(); $db->begin();
        if ($kind === 'authorization_code') {
            $code = $input['code'] ?? '';
            if (preg_match(self::HANDLE_RE, $code) !== 1) { $db->rollback(); self::fail('invalid_grant'); }
            $row = $db->run('SELECT c.*,g.user_id,g.client_id,g.security_epoch,g.scope,g.resource,g.revoked_at,
                (c.expires_at<=now() OR g.expires_at<=now()) AS is_expired FROM public.mcp_codes c
                JOIN public.mcp_grants g ON g.id=c.grant_id WHERE c.code_hash=:hash FOR UPDATE OF c,g',
                [':hash' => self::digest($code)])->fetch();
            if (!is_array($row) || $row['client_id'] !== $client['id'] || $row['used_at'] !== null
                || ($input['redirect_uri'] ?? '') !== $row['redirect_uri'] || !self::validRedirect($client, $row['redirect_uri'])
                || !self::pkceMatches($input['code_verifier'] ?? '', $row['challenge']) || self::grantOk($row) === null) {
                $db->rollback(); self::fail('invalid_grant');
            }
            $db->run('UPDATE public.mcp_codes SET used_at=now() WHERE code_hash=:hash',
            [':hash' => self::digest($code)]);
        } else {
            $refresh = $input['refresh_token'] ?? '';
            if (preg_match('/^mcp_rt_[A-Za-z0-9_-]{43}$/D', $refresh) !== 1) { $db->rollback(); self::fail('invalid_grant'); }
            // Lock grant first; all refresh/revoke operations serialize at this one row.
            $row = $db->run('SELECT g.*,g.id AS grant_id,(g.expires_at<=now()) AS is_expired
                FROM public.mcp_grants g JOIN public.mcp_tokens t ON t.grant_id=g.id
                WHERE t.token_hash=:hash AND t.kind=\'refresh\' FOR UPDATE OF g',
            [':hash' => self::digest($refresh)])->fetch();
            if (!is_array($row) || $row['client_id'] !== $client['id']) { $db->rollback(); self::fail('invalid_grant'); }
            $token = $db->run('SELECT used_at,(expires_at<=now()) AS is_expired FROM public.mcp_tokens
                WHERE token_hash=:hash FOR UPDATE',
            [':hash' => self::digest($refresh)])->fetch();
            if ($token['used_at'] !== null) {
                self::revokeGrant($row['grant_id']);
                Audit::remoteMcpActor(null, $row['grant_id']);
                Audit::deny('mcp.refresh', 'reuse', ['target_type' => 'system', 'target_name' => 'remote_mcp']);
                self::$auditProtocolFailures = false;
                $db->commit(); self::fail('invalid_grant');
            }
            if ($token['is_expired'] === true || self::grantOk($row) === null) { $db->rollback(); self::fail('invalid_grant'); }
            if (isset($input['scope']) && $input['scope'] !== $row['scope']) { $db->rollback(); self::fail('invalid_scope'); }
            $db->run('UPDATE public.mcp_tokens SET used_at=now() WHERE token_hash=:hash',
            [':hash' => self::digest($refresh)]);
        }
        $result = self::issuePair($row['grant_id'], $row['scope'], in_array('refresh_token', $clientGrants, true));
        $db->commit();
        return $result;
    }

    private static function issuePair(string $grant, string $scope, bool $withRefresh): array
    {
        $access = 'mcp_at_' . self::random();
        $db = Db::instance();
        $issued = $db->run("INSERT INTO public.mcp_tokens(token_hash,grant_id,kind,expires_at)
            SELECT :hash,id,'access',LEAST(expires_at,now()+interval '10 minutes') FROM public.mcp_grants WHERE id=:grant
            RETURNING GREATEST(0,FLOOR(EXTRACT(EPOCH FROM (expires_at-now()))))::integer AS lifetime",
            [':hash' => self::digest($access), ':grant' => $grant])->fetch();
        $result = ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => (int) $issued['lifetime'], 'scope' => $scope];
        if ($withRefresh) {
            $refresh = 'mcp_rt_' . self::random();
            $db->run("INSERT INTO public.mcp_tokens(token_hash,grant_id,kind,expires_at)
                SELECT :hash,id,'refresh',expires_at FROM public.mcp_grants WHERE id=:grant",
                [':hash' => self::digest($refresh), ':grant' => $grant]);
            $result['refresh_token'] = $refresh;
        }
        // Expired access tokens need no reuse evidence. Refresh hashes survive until grant expiry.
        $db->run("DELETE FROM public.mcp_tokens WHERE grant_id=:grant AND kind='access' AND expires_at<=now()",
            [':grant' => $grant]);
        return $result;
    }

    public static function authenticate(): array
    {
        self::requireEnabled(); self::checkOrigin();
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (isset($_SERVER['HTTP_X_API_TOKEN']) || !is_string($authorization)
            || preg_match('/^Bearer (mcp_at_[A-Za-z0-9_-]{43})$/iD', $authorization, $m) !== 1) { self::unauthorized(); }
        $row = Db::instance()->run("SELECT g.*,g.id AS grant_id,
            (g.expires_at<=now() OR t.expires_at<=now() OR c.expires_at<=now()) AS is_expired
            FROM public.mcp_tokens t JOIN public.mcp_grants g ON g.id=t.grant_id
            JOIN public.mcp_clients c ON c.id=g.client_id WHERE t.token_hash=:hash AND t.kind='access'",
            [':hash' => self::digest($m[1])])->fetch();
        $user = is_array($row) ? self::grantOk($row) : null;
        if ($user === null) { self::unauthorized(); }
        Db::instance()->run('UPDATE public.mcp_grants SET last_used_at=now() WHERE id=:id
            AND (last_used_at IS NULL OR last_used_at<now()-interval \'1 minute\')',
            [':id' => $row['grant_id']]);
        Audit::remoteMcpActor($user, $row['grant_id']);
        return ['user' => $user, 'scopes' => explode(' ', $row['scope']), 'grant_id' => $row['grant_id']];
    }

    private static function revokeGrant(string $id): void
    {
        Db::instance()->run('UPDATE public.mcp_grants SET revoked_at=COALESCE(revoked_at,now()) WHERE id=:id',
            [':id' => $id]);
    }

    public static function revokeToken(array $input): void
    {
        self::requireEnabled(); self::checkOrigin();
        self::$auditProtocolFailures = true;
        if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($input['client_secret'])) { self::fail('invalid_client', 401); }
        $client = self::client($input['client_id'] ?? '');
        if ($client === null) { self::fail('invalid_client', 401); }
        $token = $input['token'] ?? '';
        if (preg_match('/^mcp_(at|rt)_[A-Za-z0-9_-]{43}$/D', $token) !== 1) { return; }
        $db = Db::instance(); $db->begin();
        $row = $db->run('SELECT g.id,g.user_id FROM public.mcp_grants g JOIN public.mcp_tokens t ON t.grant_id=g.id
            WHERE t.token_hash=:hash AND g.client_id=:client',
            [':hash' => self::digest($token), ':client' => $client['id']])->fetch();
        if (is_array($row)) {
            self::revokeGrant($row['id']);
            Audit::remoteMcpActor(self::user((int) $row['user_id']), $row['id']);
            Audit::allow('mcp.revoke', ['target_type' => 'system', 'target_name' => 'remote_mcp']);
        }
        $db->commit();
    }

    public static function revokeConnection(array $user, string $id): void
    {
        self::requireEnabled();
        $db = Db::instance(); $db->begin();
        $row = $db->run('UPDATE public.mcp_grants SET revoked_at=COALESCE(revoked_at,now())
            WHERE id=:id AND user_id=:user RETURNING id',
            [':id' => $id, ':user' => $user['id']])->fetch();
        if (!is_array($row)) { $db->rollback(); self::fail('not_found', 404); }
        Audit::allow('mcp.disconnect', ['target_type' => 'system', 'target_name' => 'remote_mcp']);
        $db->commit();
    }

    private static function cleanup(): void
    {
        $db = Db::instance();
        $db->run('DELETE FROM public.mcp_pending WHERE expires_at<=now()');
        $db->run('DELETE FROM public.mcp_grants WHERE expires_at<=now()');
        $db->run('DELETE FROM public.mcp_clients c WHERE (c.expires_at<=now()
            OR (c.authorized_at IS NULL AND c.created_at<now()-interval \'10 minutes\'))
            AND NOT EXISTS(SELECT 1 FROM public.mcp_grants g WHERE g.client_id=c.id)
            AND NOT EXISTS(SELECT 1 FROM public.mcp_pending p WHERE p.client_id=c.id)');
    }
}
