#!/usr/bin/env python3
"""Real application HTTP/Postgres acceptance; only memories_harness may launch it.

No OAuth/MCP server doubles. --guard-selftest exercises only the local target guard
and makes no network, PHP or database calls. Responses and credentials are never
printed; result artifacts contain assertion names and booleans only.
"""
import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import struct
import subprocess
import sys
import tempfile
from threading import Barrier
import time
from urllib.parse import parse_qs, urlencode, urlsplit
import uuid
import zlib


CALLBACK = 'https://mcp-fixture.invalid/oauth/callback'
ISSUER = 'https://mcp-fixture.invalid'
ALL_SCOPES = 'memories.read photos.read memories.write travel.read travel.write'
VERSION = '2025-06-18'


def race_ports(second_port):
    if second_port != '8769':
        raise ValueError('Only the second owned loopback listener is allowed')
    return (8768, 8769)


def read_env(path):
    result = {}
    for line in path.read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            key, value = line.strip().split('=', 1)
            result[key] = value.strip().strip('\"\'')
    return result


def guard(manifest, fixture_config, marker):
    """Fail before credentials, HTTP or SQL if this is not our disposable target."""
    if marker != '1' or manifest.get('base_url') != 'http://127.0.0.1:8768/tbox':
        raise ValueError('Only the owned loopback harness is allowed')
    if re.fullmatch(r'tbox_memories_test_8768_[1-9][0-9]*', manifest.get('database', '')) is None:
        raise ValueError('Synthetic database name required')
    instance = Path(manifest.get('instance', '')).resolve()
    work = instance.parent
    if instance.name != 'tbox-v2' or work.parent != Path('/tmp') or not work.name.startswith('qaredi-stage13-'):
        raise ValueError('Disposable instance path required')
    if Path(fixture_config).resolve() != instance / '.stage13-fixture.env':
        raise ValueError('Fixture config must belong to this instance')
    if Path(manifest.get('credentials_file', '')).resolve() != work / 'credentials.json':
        raise ValueError('Credentials must belong to this instance')
    for config_path in (instance / '.env', Path(fixture_config)):
        config = read_env(config_path)
        if config.get('DB_NAME') != manifest['database'] or config.get('DB_HOST') != '127.0.0.1':
            raise ValueError('Configuration does not target the isolated loopback DB')
        if config.get('DB_PORT') != '5432' or config.get('APP_URL') != ISSUER:
            raise ValueError('Unexpected fixture connection')
        if Path(config.get('STORAGE_DIR', '')).resolve() != work / 'storage':
            raise ValueError('Storage is not disposable')
        if config.get('MCP_REMOTE_ENABLED') != '1' or json.loads(config.get('MCP_OAUTH_REDIRECT_URIS', 'null')) != [CALLBACK]:
            raise ValueError('Explicit isolated MCP configuration required')
        if config.get('MCP_OAUTH_LOOPBACK_ENABLED') != '1':
            raise ValueError('Explicit isolated native callback setting required')
    if read_env(Path(fixture_config)).get('DB_USER') != 'tbox_testdb':
        raise ValueError('Dedicated fixture DB role required')
    return instance


def guard_selftest():
    with tempfile.TemporaryDirectory(prefix='qaredi-stage13-', dir='/tmp') as directory:
        work = Path(directory)
        instance = work / 'tbox-v2'
        instance.mkdir()
        config = instance / '.stage13-fixture.env'
        values = dict(DB_NAME='tbox_memories_test_8768_1234', DB_HOST='127.0.0.1', DB_PORT='5432',
                      DB_USER='tbox_testdb', APP_URL=ISSUER, STORAGE_DIR=str(work / 'storage'),
                      MCP_REMOTE_ENABLED='1', MCP_OAUTH_REDIRECT_URIS=json.dumps([CALLBACK]), MCP_OAUTH_LOOPBACK_ENABLED='1')
        def save():
            text = ''.join(key + '=' + value + '\n' for key, value in values.items())
            config.write_text(text)
            (instance / '.env').write_text(text)
        save()
        manifest = dict(database=values['DB_NAME'], instance=str(instance),
                        base_url='http://127.0.0.1:8768/tbox', credentials_file=str(work / 'credentials.json'))
        guard(manifest, str(config), '1')
        rejects = [dict(manifest, base_url=value) for value in (
            'https://changkwon.mso.so/tbox', 'http://localhost:8768/tbox',
            'http://127.0.0.1:8082/tbox', 'http://127.0.0.1:8768/tbox?target=prod')]
        rejects += [dict(manifest, database=value) for value in ('tbox_re', 'tbox_memories_test_prod', '')]
        rejects += [dict(manifest, instance='/var/www/qaredi/tbox-v2'),
                    dict(manifest, credentials_file='/etc/tbox-v2/seed-accounts.txt')]
        count = 1
        for candidate in rejects:
            try:
                guard(candidate, str(config), '1')
            except ValueError:
                count += 1
            else:
                raise AssertionError('Unsafe target accepted')
        for key, bad_value in (('DB_NAME', 'tbox_re'), ('DB_HOST', 'db.internal'),
                               ('DB_PORT', '5433'), ('DB_USER', 'postgres'),
                               ('STORAGE_DIR', '/var/lib/tbox-v2'), ('MCP_OAUTH_REDIRECT_URIS', '[]'),
                               ('MCP_OAUTH_LOOPBACK_ENABLED', '0')):
            original = values[key]
            values[key] = bad_value
            save()
            try:
                guard(manifest, str(config), '1')
            except ValueError:
                count += 1
            else:
                raise AssertionError('Unsafe config accepted')
            values[key] = original
            save()
        for actual_config, marker in ((str(config), ''), ('/etc/tbox-v2/.env', '1')):
            try:
                guard(manifest, actual_config, marker)
            except ValueError:
                count += 1
            else:
                raise AssertionError('Unowned invocation accepted')
        if race_ports('8769') != (8768, 8769):
            raise AssertionError('Unexpected owned listener pair')
        count += 1
        for unsafe in ('', '8082', '443', '8769/../../production'):
            try:
                race_ports(unsafe)
            except ValueError:
                count += 1
            else:
                raise AssertionError('Unowned race listener accepted')
    print('REMOTE_MCP_GUARD pass=' + str(count) + ' fail=0; NO_HTTP_OR_DATABASE_CALLS=1')


class Acceptance:
    def __init__(self, manifest, instance, output):
        # Import only after the target guard; --guard-selftest needs stdlib only.
        import requests
        self.http = requests.Session()
        self.http.trust_env = False
        self.manifest = manifest
        self.instance = instance
        self.output = output
        self.site = manifest['base_url'].removesuffix('/tbox')
        self.resource = ISSUER + '/mcp'
        self.users = json.loads(Path(manifest['credentials_file']).read_text())['users']
        self.checks = []
        self.sequence = 0

    def check(self, condition, label):
        self.checks.append({'pass': bool(condition), 'name': label})
        print(('PASS ' if condition else 'FAIL ') + label, flush=True)
        if not condition:
            raise AssertionError(label)

    def request(self, method, path, *, expected=200, user=None, csrf=True, token=None, headers=None, **kwargs):
        if not path.startswith('/') or path.startswith('//'):
            raise ValueError('Only same-origin paths allowed')
        actual_headers = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}
        if user is not None:
            actual_headers['Cookie'] = '__Host-tbox_session=' + self.users[user]['session']
            if csrf:
                actual_headers['X-CSRF-Token'] = self.users[user]['csrf']
        if token is not None:
            actual_headers['Authorization'] = 'Bearer ' + token
        actual_headers.update(headers or {})
        # Explicit Cookie headers only; a previous response cannot authorize a later request.
        self.http.cookies.clear()
        response = self.http.request(method, self.site + path, headers=actual_headers,
                                     timeout=35, allow_redirects=False, **kwargs)
        allowed = (expected,) if isinstance(expected, int) else expected
        self.check(response.status_code in allowed,
                   method + ' ' + urlsplit(path).path + ' HTTP ' + str(response.status_code) + '/' + str(expected))
        return response

    def sql(self, statement, **bindings):
        env = {key: value for key, value in os.environ.items() if not key.startswith('PG')}
        env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
        command = ['psql', '-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w', '-X', '-At',
                   '-d', self.manifest['database'], '-v', 'ON_ERROR_STOP=1']
        for key, value in bindings.items():
            if re.fullmatch('[a-z_]+', key) is None:
                raise ValueError('Invalid binding name')
            command += ['-v', key + '=' + str(value)]
        result = subprocess.run(command, input=statement.encode(), capture_output=True, env=env)
        if result.returncode:
            raise RuntimeError('Isolated SQL assertion failed; SQL output suppressed')
        return result.stdout.decode().strip()

    def rpc(self, token, method, params=None, *, expected=200, error=None, headers=None, user=None):
        self.sequence += 1
        message = dict(jsonrpc='2.0', id=self.sequence, method=method, params=params or {})
        response = self.request('POST', '/mcp', token=token, expected=expected, json=message, user=user,
                                headers=dict({'Accept': 'application/json, text/event-stream',
                                              'MCP-Protocol-Version': VERSION}, **(headers or {})))
        data = response.json()
        if expected == 200:
            self.check(data.get('jsonrpc') == '2.0' and data.get('id') == self.sequence, 'JSON-RPC response id and version')
            if error is not None:
                self.check(data.get('error', {}).get('code') == error, 'JSON-RPC error ' + str(error))
            else:
                self.check('result' in data and 'error' not in data, method + ' returns result')
        return data

    def tool(self, token, name, arguments=None, *, error=False, user=None):
        result = self.rpc(token, 'tools/call', dict(name=name, arguments=arguments or {}), user=user)['result']
        self.check(bool(result.get('isError', False)) == bool(error), name + (' denied as tool error' if error else ' succeeds'))
        self.check(isinstance(result.get('content'), list) and bool(result['content']), name + ' content exists')
        if error:
            if type(error) is int:
                self.check(json.loads(result['content'][0]['text']).get('status') == error, name + ' domain status ' + str(error))
            return result
        if any(item['type'] == 'image' for item in result['content']):
            return result
        return json.loads(result['content'][0]['text'])

    def api(self, method, path, body=None, *, user='employee', expected=200, **kwargs):
        response = self.request(method, '/community/api' + path, user=user, expected=expected,
                                **({'json': body} if body is not None else {}), **kwargs)
        return response.json()

    def register(self, **changes):
        data = dict(client_name='Synthetic remote MCP acceptance', redirect_uris=[CALLBACK],
                    token_endpoint_auth_method='none', grant_types=['authorization_code', 'refresh_token'],
                    response_types=['code'])
        data.update(changes)
        return self.request('POST', '/tbox/oauth/register', expected=201, json=data).json()['client_id']

    def pending(self, client, scope=ALL_SCOPES, **changes):
        verifier = secrets.token_urlsafe(48)
        query = dict(client_id=client, response_type='code', redirect_uri=CALLBACK, scope=scope,
                     resource=self.resource, code_challenge_method='S256', state=secrets.token_urlsafe(24),
                     code_challenge=base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('='))
        query.update(changes)
        response = self.request('GET', '/tbox/oauth/authorize?' + urlencode(query), expected=302)
        location = urlsplit(response.headers['Location'])
        self.check(location.path == '/tbox/mcp-connect' and location.netloc in ('', urlsplit(ISSUER).netloc),
                   'authorization redirects only to local consent UI')
        handle = parse_qs(location.query)['pending'][0]
        return dict(handle=handle, verifier=verifier, query=query)

    def bind(self, pending, user='employee', expected=200):
        result = self.request('GET', '/tbox/api/mcp/connections?' + urlencode({'pending': pending['handle']}),
                              user=user, expected=expected).json()
        if expected == 200:
            self.check(result['pending']['id'] == pending['handle'], 'pending consent belongs to the login session')
            self.check(set(result['pending']['requested_scopes']) == set(pending['query']['scope'].split()),
                       'consent shows only requested scopes')
        return result

    def consent(self, pending, user='employee', scopes=None, approve=True, expected=200, csrf=True):
        data = dict(pending=pending['handle'], approve=approve,
                    scopes=scopes if scopes is not None else pending['query']['scope'].split())
        result = self.request('POST', '/tbox/oauth/authorize', user=user, csrf=csrf, json=data, expected=expected).json()
        if expected != 200:
            return result
        redirect = urlsplit(result['redirect_url'])
        self.check(redirect._replace(query='').geturl() == pending['query']['redirect_uri'], 'approved redirect matches requested callback exactly')
        params = parse_qs(redirect.query)
        self.check(params['state'] == [pending['query']['state']], 'OAuth state preserved')
        self.check(params.get('iss') == [ISSUER], 'authorization success/denial identifies the configured issuer')
        if approve:
            pending['code'] = params['code'][0]
        else:
            self.check(params.get('error') == ['access_denied'] and 'code' not in params, 'consent rejection issues no code')
        return params

    def exchange(self, pending, expected=200, **changes):
        data = dict(grant_type='authorization_code', code=pending['code'], code_verifier=pending['verifier'],
                    client_id=pending['query']['client_id'], redirect_uri=pending['query']['redirect_uri'], resource=self.resource)
        data.update(changes)
        response = self.request('POST', '/tbox/oauth/token', data=data, expected=expected)
        self.check('no-store' in response.headers.get('Cache-Control', ''), 'token responses forbid caching')
        return response.json()

    def grant(self, client, user='employee', scope=ALL_SCOPES):
        pending = self.pending(client, scope)
        self.bind(pending, user)
        self.consent(pending, user)
        pair = self.exchange(pending)
        self.check(pair.get('token_type') == 'Bearer' and 0 < pair.get('expires_in', 0) <= 600,
                   'short-lived Bearer access token issued')
        self.check(set(pair['scope'].split()) == set(scope.split()), 'token contains exactly approved scopes')
        return pair

    def refresh(self, client, pair, expected=200, **changes):
        data = dict(grant_type='refresh_token', client_id=client, resource=self.resource, refresh_token=pair['refresh_token'])
        data.update(changes)
        return self.request('POST', '/tbox/oauth/token', data=data, expected=expected).json()

    def race_token_requests(self, form):
        """Rendezvous two real requests against independent PHP processes/one DB.

        Each worker owns a separate HTTP session. No shared Requests Session,
        redirects, proxy environment or caller-controlled host/port is permitted.
        """
        ports = race_ports(os.environ.get('STAGE89_SECOND_PORT', ''))
        barrier = Barrier(2, timeout=10)

        def worker(port):
            with type(self.http)() as session:
                session.trust_env = False
                barrier.wait()
                response = session.post('http://127.0.0.1:' + str(port) + '/tbox/oauth/token',
                                        data=form, timeout=35, allow_redirects=False,
                                        headers={'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')})
                return port, response

        with ThreadPoolExecutor(max_workers=2) as pool:
            responses = list(pool.map(worker, ports))
        self.check({port for port, _ in responses} == {8768, 8769}, 'race uses both owned PHP listener processes')
        self.check(sorted(response.status_code for _, response in responses) == [200, 400],
                   'simultaneous token requests have exactly one success and one rejection')
        for _, response in responses:
            self.check('no-store' in response.headers.get('Cache-Control', ''), 'concurrent token response forbids caching')
            if response.status_code == 400:
                self.check(response.json().get('error') == 'invalid_grant', 'concurrent token loser receives invalid_grant')
        return next(response.json() for _, response in responses if response.status_code == 200)

    def report(self):
        self.output.mkdir(parents=True, exist_ok=True)
        (self.output / 'remote-mcp-results.json').write_text(json.dumps(self.checks, ensure_ascii=False, indent=2))
        print('REMOTE_MCP_HTTP pass=' + str(sum(item['pass'] for item in self.checks)) +
              ' fail=' + str(sum(not item['pass'] for item in self.checks)), flush=True)


def synthetic_png(private_metadata=True):
    """A real decodable RGB image with a private text chunk; no image dependency."""
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 4, 4, 8, 2, 0, 0, 0)) +
            (chunk(b'tEXt', b'Comment\x00SYNTHETIC_PRIVATE_METADATA') if private_metadata else b'') +
            chunk(b'IDAT', zlib.compress((b'\x00' + b'\x70\x90\xb0' * 4) * 4)) + chunk(b'IEND', b''))


def loopback_acceptance(t):
    registered = 'http://127.0.0.1:45123/callback'
    active = 'http://127.0.0.1:45124/callback'
    for bad in ('http://localhost:45123/callback', 'http://127.0.0.2:45123/callback',
                registered + '?state=overwrite', registered + '#fragment', registered + '/child',
                'http://127.0.0.1:0/callback', 'http://127.0.0.1:65536/callback'):
        r = t.request('POST', '/tbox/oauth/register', expected=400, json={'redirect_uris': [bad]})
        t.check(r.json().get('error') == 'invalid_redirect_uri', 'native DCR rejects host/path/port confusion')
    client = t.register(redirect_uris=[registered])
    web_client = t.register()
    pending = t.pending(client, 'memories.read work.read', redirect_uri=active)
    for changes in ({'client_id': web_client}, {'redirect_uri': CALLBACK},
                    {'redirect_uri': active + '/child'}, {'redirect_uri': active + '?x=1'}):
        query = dict(pending['query'], **changes)
        r = t.request('GET', '/tbox/oauth/authorize?' + urlencode(query), expected=400)
        t.check('Location' not in r.headers, 'native authorization retains client and exact path binding')
    t.bind(pending)
    t.consent(pending)
    for changes in ({'redirect_uri': registered}, {'code_verifier': secrets.token_urlsafe(48)}, {'client_id': web_client}):
        t.check(t.exchange(pending, expected=400, **changes).get('error') == 'invalid_grant',
                'native code binds actual port, PKCE and client')
    pair = t.exchange(pending)
    t.check(set(pair['scope'].split()) == {'memories.read', 'work.read'}, 'native token contains only approved read scopes')
    who = t.tool(pair['access_token'], 'qaredi_whoami')
    t.check(who['username'] == t.sql("SELECT username FROM users WHERE id=CAST(:'id' AS bigint)", id=t.users['employee']['id']),
            'native grant identity matches consenting account')
    t.tool(pair['access_token'], 'qaredi_list_work_projects', {'limit': 1})
    t.request('POST', '/tbox/oauth/revoke', data={'client_id': client, 'token': pair['refresh_token']})
    t.rpc(pair['access_token'], 'ping', expected=401)
    denied = t.pending(client, 'memories.read', redirect_uri=active)
    t.bind(denied)
    t.consent(denied, approve=False)


def run_acceptance(t):
    loopback_acceptance(t)
    # Discovery is bound to configured APP_URL, regardless of Host supplied by a client.
    for path in ('/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-protected-resource'):
        metadata = t.request('GET', path, headers={'Host': 'host-header.invalid'}).json()
        t.check(metadata['resource'] == t.resource and metadata['authorization_servers'] == [ISSUER],
                'protected-resource metadata ignores Host')
    metadata = t.request('GET', '/.well-known/oauth-authorization-server', headers={'Host': 'host-header.invalid'}).json()
    t.check(metadata['issuer'] == ISSUER and metadata['code_challenge_methods_supported'] == ['S256'], 'issuer and S256 discovery')
    t.check(metadata.get('authorization_response_iss_parameter_supported') is True, 'RFC9207 issuer response support advertised')
    t.check(metadata['token_endpoint'] == ISSUER + '/tbox/oauth/token', 'configured token endpoint discovery')
    for uri in ('http://127.0.0.2/callback', CALLBACK + '/child', CALLBACK + '?extra=1',
                'https://attacker.invalid/callback', CALLBACK + '#fragment'):
        result = t.request('POST', '/tbox/oauth/register', expected=400,
                           json={'redirect_uris': [uri]}).json()
        t.check(result.get('error') == 'invalid_redirect_uri', 'DCR rejects non-exact redirect URI')
    client = t.register()
    other_client = t.register(client_name='Different synthetic client')
    # Validate all authorization bindings before redirecting or issuing a code.
    good = t.pending(client, 'memories.read')
    for field, value in (('resource', ISSUER + '/community'), ('redirect_uri', CALLBACK + '/child'),
                         ('code_challenge_method', 'plain'), ('code_challenge', 'short'),
                         ('scope', 'memories.read admin'), ('scope', 'memories.write'),
                         ('scope', 'memories.read travel.write'), ('response_type', 'token'), ('state', '')):
        query = dict(good['query'], **{field: value})
        response = t.request('GET', '/tbox/oauth/authorize?' + urlencode(query), expected=400)
        t.check('Location' not in response.headers, 'invalid authorization never redirects')
    t.request('GET', '/tbox/api/mcp/connections?' + urlencode({'pending': good['handle']}), expected=401)
    t.bind(good)
    t.bind(good, 'other', expected=400)
    t.consent(good, expected=403, csrf=False)
    t.consent(good, expected=400, scopes=['memories.read', 'memories.write'])
    t.consent(good)
    t.consent(good, expected=400)
    for changes, error in (({'code_verifier': secrets.token_urlsafe(48)}, 'invalid_grant'),
                           ({'resource': ISSUER + '/elsewhere'}, 'invalid_target'),
                           ({'redirect_uri': CALLBACK + '?changed=1'}, 'invalid_grant'),
                           ({'client_id': other_client}, 'invalid_grant')):
        t.check(t.exchange(good, expected=400, **changes).get('error') == error, 'code binding rejects ' + next(iter(changes)))
    read_pair = t.exchange(good)
    t.check(t.sql("SELECT count(*) FROM public.mcp_codes WHERE code_hash=:'hash' AND used_at IS NOT NULL",
                  hash=hashlib.sha256(good['code'].encode()).hexdigest()) == '1', 'used authorization code stored by hash')
    for key in ('access_token', 'refresh_token'):
        t.check(t.sql("SELECT count(*) FROM public.mcp_tokens WHERE token_hash=:'hash'",
                      hash=hashlib.sha256(read_pair[key].encode()).hexdigest()) == '1', key + ' stored by hash')
    t.check(t.exchange(good, expected=400).get('error') == 'invalid_grant', 'authorization code is single use')
    denied = t.pending(client)
    t.bind(denied)
    t.consent(denied, approve=False)
    narrowed = t.pending(client)
    t.bind(narrowed)
    t.consent(narrowed, scopes=['memories.read'])
    t.check(t.exchange(narrowed)['scope'] == 'memories.read', 'consent may narrow requested scopes')
    expired_code = t.pending(client)
    t.bind(expired_code)
    t.consent(expired_code)
    t.sql("UPDATE public.mcp_codes SET expires_at=now()-interval '1 second' WHERE code_hash=:'hash'",
          hash=hashlib.sha256(expired_code['code'].encode()).hexdigest())
    t.check(t.exchange(expired_code, expected=400)['error'] == 'invalid_grant', 'expired code cannot exchange')

    # Consent rejects program tokens, role-view sessions, and forced account gates.
    restricted = t.pending(client)
    t.request('GET', '/tbox/api/mcp/connections', user='employee', expected=403,
              headers={'X-API-Token': t.users['employee']['read']['token']})
    session_hash = hashlib.sha256(t.users['manager']['session'].encode()).hexdigest()
    t.sql("UPDATE sessions SET origin='token',token_id=CAST(:'token_id' AS bigint) WHERE id=:'hash'",
          hash=session_hash, token_id=t.users['manager']['session.open']['id'])
    t.bind(restricted, 'manager', expected=403)
    t.sql("UPDATE sessions SET origin='login',token_id=NULL WHERE id=:'hash'", hash=session_hash)
    admin_hash = hashlib.sha256(t.users['admin']['session'].encode()).hexdigest()
    t.sql("UPDATE sessions SET act_role='Employee',act_team_id=CAST(:'team' AS bigint),act_started_at=now(),act_last_seen_at=now() WHERE id=:'hash'",
          hash=admin_hash, team=t.sql("SELECT team_id FROM users WHERE id=CAST(:'id' AS bigint)", id=t.users['admin']['id']))
    t.bind(restricted, 'admin', expected=403)
    t.sql("UPDATE sessions SET act_role=NULL,act_team_id=NULL,act_started_at=NULL,act_last_seen_at=NULL WHERE id=:'hash'", hash=admin_hash)
    t.sql("UPDATE users SET must_change_password=true WHERE id=CAST(:'id' AS bigint)", id=t.users['manager']['id'])
    t.bind(restricted, 'manager', expected=403)
    t.sql("UPDATE users SET must_change_password=false WHERE id=CAST(:'id' AS bigint)", id=t.users['manager']['id'])

    owner_a = t.grant(client)
    owner_b = t.grant(client, 'other')
    concurrent_oauth_acceptance(t, client)
    access = owner_a['access_token']
    read = read_pair['access_token']
    # Cookie sessions and legacy API credentials are never bearer fallbacks.
    ping = dict(jsonrpc='2.0', id=1, method='ping')
    for kwargs in ({}, {'user': 'employee'}, {'token': 'invalid', 'user': 'employee'},
                   {'headers': {'X-API-Token': t.users['employee']['read']['token']}},
                   {'token': access, 'headers': {'X-API-Token': t.users['employee']['read']['token']}}):
        response = t.request('POST', '/mcp', expected=401, json=ping, **kwargs)
        t.check('resource_metadata="' + ISSUER in response.headers.get('WWW-Authenticate', ''), '401 advertises fixed resource metadata')
    for method in ('GET', 'OPTIONS'):
        t.request(method, '/mcp', expected=401)
        t.request(method, '/mcp', token=access, expected=405)
    t.request('POST', '/mcp', token=access, json=ping, expected=403, headers={'Origin': 'https://attacker.invalid'})
    t.rpc(access, 'ping', headers={'Origin': ISSUER})
    for version in ('2025-03-26', VERSION):
        result = t.rpc(access, 'initialize', {'protocolVersion': version, 'capabilities': {},
                                            'clientInfo': {'name': 'real-php-fixture', 'version': '1'}},
                       headers={'MCP-Protocol-Version': version})['result']
        t.check(result['protocolVersion'] == version and 'tools' in result['capabilities'], 'supported MCP version negotiates')
    negotiated = t.rpc(access, 'initialize', {'protocolVersion': '2099-01-01', 'capabilities': {},
                                             'clientInfo': {'name': 'real-php-fixture', 'version': '1'}})['result']
    t.check(negotiated['protocolVersion'] == VERSION, 'unknown initialize version receives supported negotiation')
    response = t.request('POST', '/mcp', token=access, expected=202,
                         json={'jsonrpc': '2.0', 'method': 'notifications/initialized'},
                         headers={'Accept': 'application/json, text/event-stream', 'MCP-Protocol-Version': VERSION})
    t.check(not response.content, 'notification returns empty 202')
    t.rpc(access, 'unknown_method', error=-32601)
    t.rpc(access, 'tools/call', {'name': 'missing', 'arguments': {}}, error=-32602)
    wire_headers = {'Accept': 'application/json, text/event-stream', 'Content-Type': 'application/json'}
    for raw, status, code in (('{', 400, -32700), ('[]', 400, -32600),
                               ('[{"jsonrpc":"2.0","id":1,"method":"ping"}]', 400, -32600),
                               ('{"jsonrpc":"2.0","id":null,"method":"ping"}', 400, -32600),
                               ('{"jsonrpc":"2.0","id":1,"method":"ping","params":[]}', 400, -32600)):
        result = t.request('POST', '/mcp', token=access, data=raw, expected=status, headers=wire_headers).json()
        t.check(result['error']['code'] == code, 'malformed MCP wire rejected')
    t.request('POST', '/mcp', token=access, json=ping, expected=406, headers={'Accept': 'application/json'})
    t.request('POST', '/mcp', token=access, data='{}', expected=415, headers={'Content-Type': 'text/plain'})
    t.request('POST', '/mcp', token=access, json=ping, expected=400,
              headers=dict(wire_headers, **{'MCP-Protocol-Version': '2099-01-01'}))
    t.request('POST', '/mcp', token=access, data=' ' * 1048577, expected=413, headers=wire_headers)
    definitions = t.rpc(access, 'tools/list')['result']['tools']
    names = {tool['name'] for tool in definitions}
    read_names = {tool['name'] for tool in t.rpc(read, 'tools/list')['result']['tools']}
    t.check(read_names == {'qaredi_whoami', 'qaredi_list_memories', 'qaredi_read_memory'}, 'read-only grant exposes only read tools')
    t.check(len(names) == 10 and all(tool['inputSchema']['additionalProperties'] is False for tool in definitions),
            'explicit ten-tool catalog has closed input schemas')
    t.check(t.tool(access, 'qaredi_whoami')['username'] != t.tool(owner_b['access_token'], 'qaredi_whoami')['username'],
            'two OAuth accounts retain distinct principals')
    cookie_conflict = t.request('POST', '/mcp', token=access, user='other', headers=wire_headers,
                                json={'jsonrpc': '2.0', 'id': 'principal', 'method': 'tools/call',
                                      'params': {'name': 'qaredi_whoami', 'arguments': {}}}).json()
    identity = json.loads(cookie_conflict['result']['content'][0]['text'])
    t.check(identity['username'] == t.tool(access, 'qaredi_whoami')['username'], 'incidental other-account cookie cannot replace OAuth principal')
    t.tool(read, 'qaredi_create_memory', {'caption': 'denied', 'taken_on': '2026-10-04', 'request_id': str(uuid.uuid4())}, error=403)
    memory_acceptance(t, access, owner_b['access_token'], read)
    lifecycle_acceptance(t, client, other_client, owner_a, owner_b)


def concurrent_oauth_acceptance(t, client):
    pending = t.pending(client)
    t.bind(pending)
    t.consent(pending)
    code_hash = hashlib.sha256(pending['code'].encode()).hexdigest()
    exchanged = t.race_token_requests(dict(grant_type='authorization_code', code=pending['code'],
                                         code_verifier=pending['verifier'], client_id=client,
                                         redirect_uri=CALLBACK, resource=t.resource))
    code_state = json.loads(t.sql("""
        SELECT json_build_object('used',c.used_at IS NOT NULL,'grant_id',c.grant_id,
            'tokens',(SELECT count(*) FROM public.mcp_tokens t WHERE t.grant_id=c.grant_id))::text
        FROM public.mcp_codes c WHERE c.code_hash=:'hash'
        """, hash=code_hash))
    t.check(code_state['used'] and code_state['tokens'] == 2,
            'simultaneous code exchange consumes once and creates exactly one token pair')
    t.rpc(exchanged['access_token'], 'ping')
    rotated = t.race_token_requests(dict(grant_type='refresh_token', client_id=client,
                                       resource=t.resource, refresh_token=exchanged['refresh_token']))
    t.check(rotated['access_token'] != exchanged['access_token'] and rotated['refresh_token'] != exchanged['refresh_token'],
            'winning simultaneous refresh rotates its pair')
    family = json.loads(t.sql("""
        SELECT json_build_object('revoked',g.revoked_at IS NOT NULL,
            'tokens',(SELECT count(*) FROM public.mcp_tokens t WHERE t.grant_id=g.id),
            'consumed_refreshes',(SELECT count(*) FROM public.mcp_tokens t
                                  WHERE t.grant_id=g.id AND t.kind='refresh' AND t.used_at IS NOT NULL))::text
        FROM public.mcp_grants g WHERE g.id=CAST(:'id' AS uuid)
        """, id=code_state['grant_id']))
    t.check(family['revoked'] and family['tokens'] == 4 and family['consumed_refreshes'] == 1,
            'simultaneous refresh replay revokes whole family after only one rotation')
    for pair in (exchanged, rotated):
        t.rpc(pair['access_token'], 'ping', expected=401)
        t.check(t.refresh(client, pair, expected=400).get('error') == 'invalid_grant',
                'neither original nor race-winning refresh survives family revocation')


def memory_acceptance(t, access, other, read):
    png = synthetic_png()
    media = t.api('POST', '/memories/media', expected=201, files={'file': ('synthetic.png', png, 'image/png')})['id']
    foreign_media = t.api('POST', '/memories/media', user='other', expected=201,
                          files={'file': ('synthetic.png', png, 'image/png')})['id']
    original = dict(caption='합성 원문 보존', location='합성 장소', trip='시험 여행', taken_on='2026-10-04',
                    visibility='community', discoverable=True, media_ids=[media], request_id=str(uuid.uuid4()),
                    story={'title': '보존할 사진 이야기', 'intro': '합성 소개',
                           'scenes': [{'media_ids': [media], 'title': '한 장면', 'text': '보존할 장면 본문'}]})
    post = t.api('POST', '/memories', original, expected=201)['id']
    foreign = t.api('POST', '/memories', dict(original, caption='다른 계정 합성 원문', media_ids=[foreign_media],
                                            story=None, request_id=str(uuid.uuid4())), user='other', expected=201)['id']
    snapshot_sql = "SELECT row_to_json(p)::text FROM community.memory_posts p WHERE id=CAST(:'id' AS uuid)"
    before = json.loads(t.sql(snapshot_sql, id=post))
    original_bytes = (t.instance.parent / 'storage/memories' / media).read_bytes()
    t.check(original_bytes == synthetic_png(private_metadata=False),
            'upload removes private metadata and preserves original image chunks')
    listing = t.tool(access, 'qaredi_list_memories')['memories']
    t.check(post in [row['id'] for row in listing] and foreign not in [row['id'] for row in listing],
            'owner list omits other account even for community-visible posts')
    t.check(t.tool(access, 'qaredi_list_memories', {'query': 'no-such-synthetic-caption'})['memories'] == [], 'memory search filters')
    t.tool(access, 'qaredi_read_memory', {'post_id': foreign}, error=404)
    t.tool(other, 'qaredi_read_memory', {'post_id': post}, error=404)
    current = t.tool(access, 'qaredi_read_memory', {'post_id': post})['memory']
    t.check(current['revision'] == 1 and current['original_caption'] == original['caption'], 'read supplies revision and preserved original')
    photo_args = dict(post_id=post, media_id=media, revision=1)
    t.tool(read, 'qaredi_read_memory_photo', photo_args, error=403)
    t.tool(other, 'qaredi_read_memory_photo', photo_args, error=404)
    t.tool(access, 'qaredi_read_memory_photo', dict(photo_args, media_id=foreign_media), error=404)
    t.tool(access, 'qaredi_read_memory_photo', dict(photo_args, revision=2), error=409)
    photo = t.tool(access, 'qaredi_read_memory_photo', photo_args)
    images = [item for item in photo['content'] if item['type'] == 'image']
    decoded = base64.b64decode(images[0]['data'], validate=True)
    t.check(len(images) == 1 and images[0]['mimeType'] == 'image/jpeg' and decoded.startswith(b'\xff\xd8'), 'photo is reencoded JPEG MCP image')
    t.check(len(decoded) <= 786432 and decoded != png and b'SYNTHETIC_PRIVATE_METADATA' not in decoded,
            'photo output bounded and original metadata stripped')
    serialized = json.dumps(photo)
    t.check('https://' not in serialized and '/storage/' not in serialized and str(t.instance.parent) not in serialized,
            'photo response exposes no URL, capability or server path')
    for injected in ({'visibility': 'shared'}, {'discoverable': False}, {'owner_user_id': t.users['other']['id']},
                     {'media_ids': []}, {'original_caption': 'overwrite original'}, {'delete': True}):
        t.tool(access, 'qaredi_update_memory', dict(post_id=post, revision=1, **injected), error=400)
    t.tool(other, 'qaredi_update_memory', dict(post_id=post, revision=1, caption='foreign edit'), error=404)
    t.tool(access, 'qaredi_update_memory', dict(post_id=post, revision=1,
           story={'title': '잘못된 참조', 'intro': '', 'scenes': [{'media_ids': [foreign_media], 'title': '금지', 'text': '타인 사진'}]}), error=409)
    audit_cursor = t.sql('SELECT COALESCE(max(id),0) FROM audit_logs')
    grant_id = t.sql("SELECT grant_id FROM public.mcp_tokens WHERE token_hash=:'hash'",
                     hash=hashlib.sha256(access.encode()).hexdigest())
    # Bearer owner A writes with incidental owner B session/CSRF headers present.
    changed = t.tool(access, 'qaredi_update_memory', dict(post_id=post, revision=1, caption='수정한 합성 본문'), user='other')['memory']
    t.check(changed['revision'] == 2 and changed['caption'] == '수정한 합성 본문', 'memory update advances revision')
    audit_rows = json.loads(t.sql("""
        SELECT COALESCE(json_agg(json_build_object('actor_id',actor_id,'actor_name',actor_name,
            'request_id',request_id,'action',action,'result',result,'detail',detail) ORDER BY id),'[]'::json)::text
        FROM audit_logs WHERE id>CAST(:'cursor' AS bigint)
        """, cursor=audit_cursor))
    username = t.sql("SELECT username FROM users WHERE id=CAST(:'id' AS bigint)", id=t.users['employee']['id'])
    t.check(any(row['action'] == 'memory.change' and row['detail'].get('operation') == 'update' for row in audit_rows)
            and any(row['action'] == 'mcp.tool' and row['detail'].get('tool') == 'qaredi_update_memory' for row in audit_rows),
            'MCP write records both domain change and tool outcome audits')
    t.check(all(row['actor_id'] == t.users['employee']['id'] and row['actor_id'] != t.users['other']['id']
                and row['actor_name'] == username and row['result'] == 'allow'
                and row['detail'].get('auth') == 'mcp_oauth' and row['detail'].get('grant_id') == grant_id
                for row in audit_rows), 'every incidental-cookie write audit belongs only to Bearer owner and grant')
    t.check(len({row['request_id'] for row in audit_rows}) == 1, 'domain and MCP tool audit rows share the actual HTTP request')
    t.tool(access, 'qaredi_update_memory', dict(post_id=post, revision=1, caption='stale overwrite'), error=409)
    after = json.loads(t.sql(snapshot_sql, id=post))
    for field in ('original_caption', 'original_media_ids', 'media_ids', 'visibility', 'discoverable', 'location', 'trip', 'taken_on', 'story'):
        t.check(after[field] == before[field], 'memory update preserves ' + field)
    t.check((t.instance.parent / 'storage/memories' / media).read_bytes() == original_bytes, 'MCP text edits preserve original media bytes')
    create = dict(caption='MCP 비공개 새 합성 글', taken_on='2026-10-04', request_id=str(uuid.uuid4()))
    created = t.tool(access, 'qaredi_create_memory', create)['memory']
    retry = t.tool(access, 'qaredi_create_memory', create)['memory']
    t.check(created['id'] == retry['id'] and created['visibility'] == 'private' and not created['discoverable'],
            'MCP creation is idempotent and private')
    t.tool(access, 'qaredi_create_memory', dict(create, visibility='shared'), error=400)
    # Existing authenticated UI detaches a photo; MCP may no longer read the original.
    t.api('PATCH', '/memories/' + post, dict(original, revision=2, caption=changed['caption'], media_ids=[], story=None))
    t.tool(access, 'qaredi_read_memory_photo', dict(photo_args, revision=3), error=404)
    t.check((t.instance.parent / 'storage/memories' / media).read_bytes() == original_bytes, 'detaching current photo retains original storage')
    travel_acceptance(t, access, other, read, created['id'], foreign)
    t.api('DELETE', '/memories/' + created['id'], {})
    t.tool(access, 'qaredi_read_memory', {'post_id': created['id']}, error=404)
    t.check(created['id'] not in [row['id'] for row in t.tool(access, 'qaredi_list_memories')['memories']], 'deleted memory omitted from MCP listing')


def travel_acceptance(t, access, other, read, post, foreign):
    step_id = str(uuid.uuid4())
    plan = dict(title='합성 제주 여행', destination='제주', starts_on='2026-10-04', ends_on='2026-10-05',
                notes='보존할 계획', steps=[dict(id=step_id, title='해변 산책', date=None, status='planned', notes='')])
    args = dict(plan=plan, request_id=str(uuid.uuid4()))
    t.tool(read, 'qaredi_save_travel_plan', args, error=403)
    created = t.tool(access, 'qaredi_save_travel_plan', args)['plan']
    plan_id = created['id']
    t.check(t.tool(access, 'qaredi_save_travel_plan', args)['plan']['id'] == plan_id, 'travel create retry is idempotent')
    t.check(created['visibility'] == 'private', 'travel plan remains private')
    t.check(plan_id in [row['id'] for row in t.tool(access, 'qaredi_list_travel_plans')['plans']], 'own travel plan listed')
    t.check(plan_id not in [row['id'] for row in t.tool(other, 'qaredi_list_travel_plans')['plans']], 'other account travel plan omitted')
    t.tool(other, 'qaredi_read_travel_plan', {'plan_id': plan_id}, error=404)
    t.tool(other, 'qaredi_save_travel_plan', dict(plan=plan, plan_id=plan_id, revision=1), error=404)
    updated = t.tool(access, 'qaredi_save_travel_plan', dict(plan=dict(plan, notes='수정 계획'), plan_id=plan_id, revision=1))['plan']
    t.check(updated['revision'] == 2, 'travel save advances revision')
    t.tool(access, 'qaredi_save_travel_plan', dict(plan=plan, plan_id=plan_id, revision=1), error=409)
    t.tool(access, 'qaredi_link_travel_record', dict(plan_id=plan_id, post_id=foreign, revision=2), error=404)
    t.tool(other, 'qaredi_link_travel_record', dict(plan_id=plan_id, post_id=post, revision=2), error=404)
    before = t.sql("SELECT row_to_json(p)::text FROM community.memory_posts p WHERE id=CAST(:'id' AS uuid)", id=post)
    linked = t.tool(access, 'qaredi_link_travel_record', dict(plan_id=plan_id, post_id=post, step_id=step_id, revision=2))['plan']
    t.check(linked['revision'] == 3 and linked['records'][0]['post_id'] == post, 'link advances plan revision and records owned memory')
    t.tool(access, 'qaredi_link_travel_record', dict(plan_id=plan_id, post_id=post, revision=2), error=409)
    t.check(t.sql("SELECT row_to_json(p)::text FROM community.memory_posts p WHERE id=CAST(:'id' AS uuid)", id=post) == before,
            'travel linking preserves complete memory row')
    t.check(t.tool(access, 'qaredi_read_travel_plan', {'plan_id': plan_id})['plan']['notes'] == '수정 계획', 'stale travel save preserves current notes')
    # Existing browser/session API changes the same plan between MCP read and write.
    browser_link = t.api('POST', '/travel-plans/' + plan_id + '/records', {'post_id': post, 'step_id': None})['plan']
    t.check(browser_link['revision'] == linked['revision'] + 1, 'browser record edit advances shared plan revision')
    t.tool(access, 'qaredi_link_travel_record', dict(plan_id=plan_id, post_id=post, step_id=step_id,
                                                  revision=linked['revision']), error=409)
    reread = t.tool(access, 'qaredi_read_travel_plan', {'plan_id': plan_id})['plan']
    t.check(reread['revision'] == browser_link['revision'] and len(reread['records']) == 1
            and reread['records'][0]['post_id'] == post and reread['records'][0]['step_id'] is None,
            'stale MCP link preserves newer browser record placement')
    browser_unlink = t.api('DELETE', '/travel-plans/' + plan_id + '/records', {'post_id': post})['plan']
    t.check(browser_unlink['revision'] == reread['revision'] + 1 and browser_unlink['records'] == [],
            'browser record removal advances shared plan revision')
    t.tool(access, 'qaredi_link_travel_record', dict(plan_id=plan_id, post_id=post, revision=reread['revision']), error=409)
    after_unlink = t.tool(access, 'qaredi_read_travel_plan', {'plan_id': plan_id})['plan']
    t.check(after_unlink['records'] == [] and after_unlink['revision'] == browser_unlink['revision'],
            'stale MCP link cannot restore a record removed in the browser')
    local_token = secrets.token_hex(24)
    t.sql("""
        INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at)
        VALUES(CAST(:'id' AS bigint),'synthetic local travel client',:'hash',:'hint',CAST(:'id' AS bigint),
               'travel.read+travel.write',now()+interval '1 hour')
        """, id=t.users['employee']['id'], hash=hashlib.sha256(local_token.encode()).hexdigest(), hint=local_token[:6])
    local_link = t.api('POST', '/travel-plans/' + plan_id + '/records', {'post_id': post, 'step_id': step_id},
                       user=None, headers={'X-API-Token': local_token})['plan']
    t.check(local_link['revision'] == after_unlink['revision'] + 1, 'existing local-client API record change advances shared revision')
    t.tool(access, 'qaredi_link_travel_record', dict(plan_id=plan_id, post_id=post, step_id=None,
                                                  revision=after_unlink['revision']), error=409)
    after_local = t.tool(access, 'qaredi_read_travel_plan', {'plan_id': plan_id})['plan']
    t.check(after_local['revision'] == local_link['revision'] and len(after_local['records']) == 1
            and after_local['records'][0]['step_id'] == step_id, 'stale MCP link preserves newer local-client record placement')
    t.check(t.sql("SELECT row_to_json(p)::text FROM community.memory_posts p WHERE id=CAST(:'id' AS uuid)", id=post) == before,
            'browser, local-client and rejected MCP record changes preserve complete memory row')


def lifecycle_acceptance(t, client, other_client, owner_a, owner_b):
    access = owner_a['access_token']
    t.check(t.refresh(client, owner_a, expected=400, resource=ISSUER + '/wrong')['error'] == 'invalid_target', 'refresh binds resource')
    t.check(t.refresh(other_client, owner_a, expected=400)['error'] == 'invalid_grant', 'refresh binds client')
    t.check(t.refresh(client, owner_a, expected=400, scope='memories.read')['error'] == 'invalid_scope', 'refresh cannot change approved scope')
    rotated = t.refresh(client, owner_a)
    t.check(rotated['access_token'] != access and rotated['refresh_token'] != owner_a['refresh_token'], 'refresh rotates both opaque tokens')
    t.rpc(rotated['access_token'], 'ping')
    t.check(t.refresh(client, owner_a, expected=400)['error'] == 'invalid_grant', 'refresh replay rejected')
    for token in (access, rotated['access_token']):
        t.rpc(token, 'ping', expected=401)
    t.refresh(client, rotated, expected=400)
    t.rpc(owner_b['access_token'], 'ping')
    revoked = t.grant(client)
    t.request('POST', '/tbox/oauth/revoke', data={'client_id': other_client, 'token': revoked['refresh_token']})
    t.rpc(revoked['access_token'], 'ping')
    t.request('POST', '/tbox/oauth/revoke', data={'client_id': client, 'token': revoked['refresh_token']})
    t.rpc(revoked['access_token'], 'ping', expected=401)
    t.refresh(client, revoked, expected=400)
    t.request('POST', '/tbox/oauth/revoke', data={'client_id': client, 'token': revoked['refresh_token']})
    disconnected = t.grant(client)
    grant_id = t.sql("SELECT grant_id FROM public.mcp_tokens WHERE token_hash=:'hash'",
                     hash=hashlib.sha256(disconnected['access_token'].encode()).hexdigest())
    connections = t.request('GET', '/tbox/api/mcp/connections', user='employee').json()['connections']
    t.check(grant_id in [row['id'] for row in connections], 'account UI lists owned OAuth grant')
    t.request('DELETE', '/tbox/api/mcp/connections/' + grant_id, user='other', expected=404)
    t.request('DELETE', '/tbox/api/mcp/connections/' + grant_id, user='employee', csrf=False, expected=403)
    t.rpc(disconnected['access_token'], 'ping')
    t.request('DELETE', '/tbox/api/mcp/connections/' + grant_id, user='employee')
    t.rpc(disconnected['access_token'], 'ping', expected=401)
    t.refresh(client, disconnected, expected=400)
    expired = t.grant(client)
    t.sql("UPDATE public.mcp_tokens SET expires_at=now()-interval '1 second' WHERE token_hash=:'hash'",
          hash=hashlib.sha256(expired['access_token'].encode()).hexdigest())
    t.rpc(expired['access_token'], 'ping', expected=401)
    t.rpc(t.refresh(client, expired)['access_token'], 'ping')
    # Session logout is independent of an approved grant. No cookie is sent by RPC.
    manager = t.grant(client, 'manager')
    t.request('POST', '/tbox/api/logout', user='manager')
    t.rpc(manager['access_token'], 'ping')
    t.request('GET', '/tbox/api/mcp/connections', user='manager', expected=401)
    # Cosmetic preferences/TOTP replay counter must not increment security epoch.
    before = t.sql("SELECT mcp_security_epoch FROM users WHERE id=CAST(:'id' AS bigint)", id=t.users['other']['id'])
    t.sql("UPDATE users SET high_contrast=NOT high_contrast,mfa_last_step=COALESCE(mfa_last_step,0)+1 WHERE id=CAST(:'id' AS bigint)", id=t.users['other']['id'])
    t.check(t.sql("SELECT mcp_security_epoch FROM users WHERE id=CAST(:'id' AS bigint)", id=t.users['other']['id']) == before,
            'cosmetic settings and OTP counter preserve security epoch')
    t.rpc(owner_b['access_token'], 'ping')
    # Change each real protected account field; restore only fixture values and
    # require a new OAuth grant after each change. Prior tokens never revive.
    mutations = [
        ("password_hash=password_hash || 'synthetic-change'", 'password_hash', 'password change'),
        ("mfa_recovery_required=true", 'mfa_recovery_required', 'MFA recovery'),
        ("mfa_enabled=false", 'mfa_enabled', 'MFA state'),
        ("is_active=false", 'is_active', 'account deactivation'),
        ("expires_at=now()-interval '1 second'", 'expires_at', 'account expiration'),
    ]
    for assignment, column, label in mutations:
        # assignment/column are static code, never derived from HTTP/user input.
        pair = t.grant(client)
        uid = t.users['employee']['id']
        saved = json.loads(t.sql("SELECT json_build_object('value'," + column + ",'epoch',mcp_security_epoch)::text FROM users WHERE id=CAST(:'id' AS bigint)", id=uid))
        t.sql('UPDATE users SET ' + assignment + " WHERE id=CAST(:'id' AS bigint)", id=uid)
        t.rpc(pair['access_token'], 'ping', expected=401)
        t.refresh(client, pair, expected=400)
        epoch = int(t.sql("SELECT mcp_security_epoch FROM users WHERE id=CAST(:'id' AS bigint)", id=uid))
        t.check(epoch > saved['epoch'], label + ' advances security epoch')
        # json_populate_record preserves null/boolean/timestamp types using a bound JSON value.
        t.sql('UPDATE users SET ' + column + '=(json_populate_record(NULL::users,CAST(:\'data\' AS json))).' + column +
              " WHERE id=CAST(:'id' AS bigint)", data=json.dumps({column: saved['value']}), id=uid)
        t.rpc(pair['access_token'], 'ping', expected=401)
        t.check(True, label + ' does not revive old grant when restored')
    t.rpc(owner_b['access_token'], 'ping')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--guard-selftest', action='store_true')
    args = parser.parse_args()
    if args.guard_selftest:
        guard_selftest()
        return
    manifest_path = os.environ.get('FILE_RENAME_MANIFEST')
    if not manifest_path:
        raise SystemExit('REFUSED: launch through tests/community/memories_harness.py --suite remote_http.py')
    manifest = json.loads(Path(manifest_path).read_text())
    instance = guard(manifest, os.environ.get('FILE_RENAME_FIXTURE_CONFIG', ''), os.environ.get('REMOTE_MCP_HARNESS', ''))
    runner = Acceptance(manifest, instance, Path(os.environ['FILE_RENAME_OUTPUT']))
    try:
        run_acceptance(runner)
    except Exception as error:
        # Never print exception URLs/response bodies: they can contain OAuth credentials.
        if not runner.checks or runner.checks[-1]['pass']:
            runner.checks.append({'pass': False, 'name': 'Unexpected integration failure: ' + type(error).__name__})
        print('REMOTE_MCP_FAILURE=' + type(error).__name__ + '; response bodies suppressed', flush=True)
        raise SystemExit(1) from None
    finally:
        runner.report()


if __name__ == '__main__':
    main()
