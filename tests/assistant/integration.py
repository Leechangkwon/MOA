#!/usr/bin/env python3
"""Real requests -> PHP router -> synthetic PostgreSQL -> CLI worker acceptance checks."""
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import hmac
import json
import os
from pathlib import Path
import struct
import subprocess
import time
import uuid

import requests

manifest = json.loads(Path(os.environ['ASSISTANT_TEST_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_assistant_test_')
root = Path(manifest['instance'])
output = Path(os.environ['ASSISTANT_TEST_OUTPUT'])
fixtures = json.loads(Path(manifest['credentials_file']).read_text())
users, files = fixtures['users'], fixtures['files']
base = manifest['base_url']
ua = 'tbox-review/' + time.strftime('%Y-%m-%d')
checks = []
secrets_seen = []
for user in users.values():
    secrets_seen += [user['password'], user['secret'], user['session'], user['csrf']]
    secrets_seen += [user[scope]['token'] for scope in ('assistant.run', 'read')]


def check(condition, name):
    checks.append((bool(condition), name))
    print(('PASS ' if condition else 'FAIL ') + name, flush=True)
    if not condition:
        raise AssertionError(name)


def call(method, path, body=None, user='employee', token=None, expected=None, key=None, headers=None):
    hdr = {'User-Agent': ua, 'X-API-Token': token or users[user]['assistant.run']['token']}
    if key is not None:
        hdr['Idempotency-Key'] = key
    hdr.update(headers or {})
    response = requests.request(method, base + path, headers=hdr, json=body, timeout=20)
    if expected is not None:
        check(response.status_code == expected,
              '{} {} HTTP {} (actual {})'.format(method, path, expected, response.status_code))
    try:
        payload = response.json()
    except ValueError:
        payload = None
    return response, payload


def sample(name, response):
    data = response.json()
    text = json.dumps(data, ensure_ascii=False, indent=2) + '\n'
    check(not any(secret in text for secret in secrets_seen), 'sample contains no credentials: ' + name)
    (output / name).write_text(text)


def fixture(command='mutate', **values):
    result = subprocess.run(['php', 'tests/assistant/fixture.php', command], cwd=root,
                            input=json.dumps(values).encode(), stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, check=True)
    if command == 'token' or values.get('action') == 'claim':
        value = json.loads(result.stdout)
        if command == 'token':
            secrets_seen.append(value['token'])
        return value
    return result.stdout.decode().strip()


def worker():
    result = subprocess.run(['php', 'scripts/assistant_worker.php', '--drain'], cwd=root,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    text = result.stdout.decode()
    check(not any(secret in text for secret in secrets_seen), 'worker output excludes credentials')
    with (output / 'worker.log').open('a') as log:
        log.write(text)
    return text


def create(name=None, confirm=False, user='employee', query='재료비', tool=None, **extra):
    body = {'mode': 'tool', 'tool': tool or {'name': 'files.search', 'arguments': {'query': query, 'limit': 20}}}
    if confirm:
        body['require_confirmation'] = True
    body.update(extra)
    response, value = call('POST', '/api/assistant/runs', body, user=user,
                           expected=202, key=name or str(uuid.uuid4()))
    check((value['status'] == 'queued' or value['idempotent_replay']) and value['inference_available'] is False, 'queued receipt is explicit, inference disabled')
    return value['run_id'], response


def get(rid, user='employee'):
    return call('GET', '/api/assistant/runs/' + rid, user=user, expected=200)[1]


def events(rid, after=0, user='employee'):
    response, _ = call('GET', '/api/assistant/runs/' + rid + '/events', user=user, expected=200,
                        headers={'Accept': 'text/event-stream', 'Last-Event-ID': str(after)})
    check(response.headers['Content-Type'].startswith('text/event-stream'), 'SSE content type')
    parsed = []
    for block in response.text.split('\n\n'):
        entry = {}
        for line in block.splitlines():
            if ': ' in line:
                name, value = line.split(': ', 1)
                entry[name] = json.loads(value) if name == 'data' else value
        if 'event' in entry:
            parsed.append(entry)
    return response, parsed


def confirm_body(rid, decision='approve'):
    approval = get(rid)['approval']
    return dict(approval_id=approval['approval_id'], plan_hash=approval['plan_hash'], decision=decision)


def config(name, value):
    path = root / '.env'
    lines = path.read_text().splitlines()
    path.write_text('\n'.join(name + '=' + value if line.startswith(name + '=') else line for line in lines) + '\n')
    path.chmod(0o600)


def sql(text):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    result = subprocess.run(['psql', '-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w',
                             '-X', '-At', '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1'],
                            env=env, input=text.encode(), stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    return result.stdout.decode()


def run():
    print('PYTHON_REQUESTS_VERSION=' + requests.__version__)
    # Every route requires a personal assistant.run token, even for final-state reads.
    response = requests.get(base + '/api/assistant/runs', headers={'User-Agent': ua}, timeout=10)
    check(response.status_code == 401 and isinstance(response.json()['error'], dict), 'missing token: structured 401')
    sample('auth-missing.json', response)
    call('GET', '/api/assistant/runs', token='invalid', expected=401)
    call('GET', '/api/whoami', expected=200)
    scopes = ('read', 'read+upload', 'read+upload+delete', 'community.write', 'session.open')
    scope_tokens = {}
    fake_id = str(uuid.uuid4())
    route_cases = [('POST', '/api/assistant/runs', {}), ('GET', '/api/assistant/runs', None),
                   ('GET', '/api/assistant/runs/' + fake_id, None),
                   ('GET', '/api/assistant/runs/' + fake_id + '/events', None),
                   ('POST', '/api/assistant/runs/' + fake_id + '/cancel', {}),
                   ('POST', '/api/assistant/runs/' + fake_id + '/confirm', {})]
    for scope in scopes:
        scope_tokens[scope] = fixture('token', user_id=users['other']['id'], scope=scope)['token']
        for method, path, body in route_cases:
            call(method, path, body, token=scope_tokens[scope], expected=403, key='scope-test')
    # Shared legacy token has no assistant permission.
    legacy = next(line.split('=', 1)[1] for line in (root / '.env').read_text().splitlines() if line.startswith('API_TOKEN='))
    secrets_seen.append(legacy)
    for method, path, body in route_cases:
        call(method, path, body, token=legacy, expected=403, key='scope-shared')
    for method, path, body in [('GET', '/api/files', None), ('GET', '/api/folders', None),
                             ('GET', '/api/files/' + files['public']['id'] + '/download', None),
                             ('POST', '/api/me/session-link', {}), ('DELETE', '/api/files/' + files['public']['id'], None)]:
        call(method, path, body, expected=403)
    response = requests.get(base.replace('/tbox', '/community') + '/api/me/space',
                            headers={'User-Agent': ua, 'X-API-Token': users['employee']['assistant.run']['token']}, timeout=10)
    check(response.status_code == 403, 'assistant token cannot initialize community space')
    # Existing read API shape and download still work with the dedicated file token.
    _, legacy_list = call('GET', '/api/files', token=users['employee']['read']['token'], expected=200)
    check(isinstance(legacy_list, list) and len(legacy_list) == 3, 'legacy file array response and permissions unchanged')
    check(all(set(item) == {'id', 'name', 'level', 'size', 'uploaded_at', 'exec_content'} for item in legacy_list), 'legacy six metadata fields unchanged')
    response, _ = call('GET', '/api/files/' + files['public']['id'] + '/download', token=users['employee']['read']['token'], expected=200)
    check(response.content == b'SYNTHETIC FIXTURE ONLY\n', 'legacy download reads only synthetic bytes')

    # Issue independent scope via real session/password/MFA/CSRF path, capture token only in memory.
    admin = users['admin']
    counter = int(time.time()) // 30
    digest = hmac.new(base64.b32decode(admin['secret'] + '=' * ((8 - len(admin['secret']) % 8) % 8)),
                      struct.pack('>Q', counter), hashlib.sha1).digest()
    offset = digest[-1] & 15
    otp = str((struct.unpack('>I', digest[offset:offset+4])[0] & 0x7fffffff) % 1000000).zfill(6)
    secrets_seen.append(otp)
    response = requests.post(base + '/api/me/api-tokens', json=dict(name='비서 검증', purpose='격리 환경의 읽기 전용 검증',
        scope='assistant.run', password=admin['password'], otp=otp), headers={
        'User-Agent': ua, 'Cookie': '__Host-tbox_session=' + admin['session'], 'X-CSRF-Token': admin['csrf']}, timeout=10)
    check(response.status_code == 201, 'real session + password + MFA issues assistant.run')
    issued = response.json()
    check('token' in issued and issued['scope'] == 'assistant.run', 'new token is independent assistant scope')
    secrets_seen.append(issued['token'])
    call('GET', '/api/assistant/runs', token=issued['token'], expected=200)
    call('GET', '/api/files', token=issued['token'], expected=403)
    (output / 'token-issue-sanitized.json').write_text(json.dumps({k: v for k, v in issued.items() if k not in ('token', 'hint')}, ensure_ascii=False, indent=2))

    # Exact object parser and strictly read-only tools.
    invalid = [[], {'mode': 'tool', 'tool': {'name': 'files.delete', 'arguments': {}}},
               {'mode': 'tool', 'tool': {'name': 'files.search', 'arguments': {'query': 'x', 'limit': 101}}},
               {'mode': 'tool', 'tool': {'name': 'files.search', 'arguments': {'query': 'x', 'limit': True}}},
               {'mode': 'chat', 'message': 'x', 'role': 'Admin'}, {'mode': 'chat', 'message': 'x', 'provider': 'test'}]
    for body in invalid:
        call('POST', '/api/assistant/runs', body, expected=400, key=str(uuid.uuid4()))
    body = {'mode': 'tool', 'tool': {'name': 'files.search', 'arguments': {'query': '재료비'}}}
    call('POST', '/api/assistant/runs', body, expected=400)
    response, error = call('POST', '/api/assistant/runs', {'mode': 'chat', 'message': '본문 분석'}, key='no-provider', expected=503)
    check(error['error']['code'] == 'provider_unavailable' and 'run_id' not in error['error'], 'provider absent: no fake run or inference')
    sample('provider-unavailable.json', response)
    call('POST', '/api/assistant/runs', {'mode': 'tool', 'tool': {'name': 'files.evidence', 'arguments': {'file_id': files['admin']['id']}}}, expected=404, key='forbidden-file')
    rid, response = create('idempotent-search')
    sample('create-search.json', response)
    queued = get(rid)
    check(queued['status'] == 'queued' and queued['result'] is None and queued['artifacts'] == [], 'pending has null result and no artifacts')
    (output / 'get-queued.json').write_text(json.dumps(queued, ensure_ascii=False, indent=2))
    _, replay = call('POST', '/api/assistant/runs', body, expected=202, key='idempotent-search')
    check(replay['run_id'] == rid and replay['idempotent_replay'] is True, 'normalized default body replays same run')
    body['message'] = 'different'
    call('POST', '/api/assistant/runs', body, expected=409, key='idempotent-search')
    for method, suffix, value in [('GET', '', None), ('GET', '/events', None), ('POST', '/cancel', {}), ('POST', '/confirm', {})]:
        call(method, '/api/assistant/runs/' + rid + suffix, value, user='other', expected=404 if suffix != '/confirm' else 400)
    for suffix in ('', '/events'):
        call('GET', '/api/assistant/runs/' + rid + suffix, user='admin', expected=404)
    call('POST', '/api/assistant/runs/' + rid + '/cancel', {}, user='admin', expected=404)
    worker()
    final = get(rid)
    check(final['status'] == 'succeeded' and final['result']['count'] == 3 and final['result']['content_read'] is False, 'native search succeeds with three visible metadata results')
    check({item['id'] for item in final['result']['files']} == {files[k]['id'] for k in ('public', 'internal', 'team_a')}, 'other team, admin and deleted files excluded')
    check(all(a['evidence']['content_read'] is False and a['evidence']['kind'] == 'metadata' for a in final['artifacts']), 'artifacts do not claim content analysis')
    (output / 'get-succeeded.json').write_text(json.dumps(final, ensure_ascii=False, indent=2))
    sse, entries = events(rid)
    (output / 'events-search.sse').write_text(sse.text)
    seq = [int(e['id']) for e in entries]
    check(seq == list(range(1, final['last_event_id'] + 1)) and entries[-1]['event'] == 'run.completed', 'SSE durable contiguous ordered sequence and final event')
    _, replay_events = events(rid, seq[-2])
    check([int(e['id']) for e in replay_events] == [seq[-1]], 'Last-Event-ID replays only later events')
    call('GET', '/api/assistant/runs/' + rid + '/events', expected=409, headers={'Last-Event-ID': '999999'})
    call('GET', '/api/assistant/runs/' + rid + '/events', expected=400, headers={'Last-Event-ID': '-1'})

    # Do not replay sensitive metadata after later rights changes.
    for action, file_key in [('file_private', 'internal'), ('file_deleted', 'public')]:
        fixture(action=action, file_id=files[file_key]['id'])
        updated = get(rid)
        _, ev = events(rid)
        text = json.dumps([updated, ev], ensure_ascii=False)
        check(files[file_key]['id'] not in text and '재료비_' + file_key + '.txt' not in text, 'GET/events remove inaccessible metadata: ' + action)
    fixture(action='user_guest', user_id=users['employee']['id'])
    check(get(rid)['result']['count'] == 0, 'role change immediately removes internal/team evidence')
    fixture(action='user_restore', user_id=users['employee']['id'], team_id=fixtures['teams']['b'])
    check(get(rid)['result']['count'] == 0, 'team change does not replay earlier team artifacts')
    for k in ('internal', 'public'):
        fixture(action='file_restore', file_id=files[k]['id'], level=files[k]['level'], team_id=files[k]['team_id'])
    fixture(action='user_restore', user_id=users['employee']['id'], team_id=fixtures['teams']['a'])

    evidence_id, response = create(tool={'name': 'files.evidence', 'arguments': {'file_id': files['public']['id']}})
    sample('create-evidence.json', response)
    worker()
    check(get(evidence_id)['result']['kind'] == 'file_evidence', 'explicit evidence lookup succeeds')
    # Idempotency race is serialized per owner; every reply refers to one run.
    concurrent_body = {'mode': 'tool', 'tool': {'name': 'files.search', 'arguments': {'query': '재料'}}}
    def concurrent(_):
        return call('POST', '/api/assistant/runs', concurrent_body, key='concurrent-create', expected=202)[1]
    with ThreadPoolExecutor(max_workers=4) as pool:
        replies = list(pool.map(concurrent, range(4)))
    check(len({r['run_id'] for r in replies}) == 1 and sum(not r['idempotent_replay'] for r in replies) == 1, 'four concurrent creates persist exactly one execution')
    worker()
    _, listing = call('GET', '/api/assistant/runs?limit=1', expected=200)
    check(len(listing['runs']) == 1 and listing['next_cursor'] is not None, 'bounded cursor list first page')
    cursor = listing['next_cursor']; seen = {listing['runs'][0]['run_id']}
    while cursor:
        _, page = call('GET', '/api/assistant/runs?limit=1&cursor=' + cursor, expected=200)
        check(all(r['run_id'] not in seen for r in page['runs']), 'cursor excludes duplicate rows')
        seen.update(r['run_id'] for r in page['runs']); cursor = page['next_cursor']
    check(rid in seen and get(rid)['run_id'] == rid, 'execution outside first page remains directly readable')
    call('GET', '/api/assistant/runs?cursor=invalid', expected=400)
    call('GET', '/api/assistant/runs?limit=101', expected=400)
    call('GET', '/api/assistant/runs?scope=other', expected=400)
    _, others = call('GET', '/api/assistant/runs', user='other', expected=200)
    check(others['runs'] == [], 'list never crosses ownership')
    other_id, _ = create('idempotent-search', user='other')
    check(other_id != rid, 'same idempotency key in another user namespace creates a different run')
    worker()

    cancelled, _ = create()
    response, out = call('POST', '/api/assistant/runs/' + cancelled + '/cancel', {}, expected=202)
    sample('cancel-requested.json', response)
    check(out['status'] == 'cancel_requested' and get(cancelled)['finished_at'] is None, 'cancel accepted is not terminal')
    call('POST', '/api/assistant/runs/' + cancelled + '/cancel', {}, expected=202)
    worker()
    check(get(cancelled)['status'] == 'cancelled', 'worker finalizes queued cancellation')
    call('POST', '/api/assistant/runs/' + cancelled + '/cancel', {}, expected=200)

    # Confirmation validates plan identity, owner, state, expiry and repeated decisions.
    for decision in ('approve', 'reject'):
        approved, _ = create(confirm=True)
        worker()
        waiting = get(approved)
        check(waiting['status'] == 'awaiting_confirmation' and waiting['result'] is None, 'server plan awaits confirmation')
        value = confirm_body(approved, decision)
        bad = dict(value, plan_hash='0' * 64)
        call('POST', '/api/assistant/runs/' + approved + '/confirm', bad, expected=409)
        call('POST', '/api/assistant/runs/' + approved + '/confirm', dict(value, approval_id=str(uuid.uuid4())), expected=409)
        call('POST', '/api/assistant/runs/' + approved + '/confirm', value, user='other', expected=404)
        response, _ = call('POST', '/api/assistant/runs/' + approved + '/confirm', value, expected=202 if decision == 'approve' else 200)
        if decision == 'approve':
            (output / 'get-awaiting-confirmation.json').write_text(json.dumps(waiting, ensure_ascii=False, indent=2))
            sample('confirm-approved.json', response)
        call('POST', '/api/assistant/runs/' + approved + '/confirm', value, expected=200)
        call('POST', '/api/assistant/runs/' + approved + '/confirm', dict(value, decision='reject' if decision == 'approve' else 'approve'), expected=409)
        worker()
        check(get(approved)['status'] == ('succeeded' if decision == 'approve' else 'cancelled'), 'confirmation final outcome: ' + decision)
        call('POST', '/api/assistant/runs/' + approved + '/confirm', value, expected=200)
    expired, _ = create(confirm=True); worker(); value = confirm_body(expired)
    fixture(action='approval_expire', run_id=expired)
    response, err = call('POST', '/api/assistant/runs/' + expired + '/confirm', value, expected=409)
    check(err['error']['code'] == 'approval_expired', 'expired approval cannot execute')
    worker(); check(get(expired)['error']['code'] == 'approval_expired', 'worker closes expired approval')
    waiting_cancel, _ = create(confirm=True); worker(); value = confirm_body(waiting_cancel)
    call('POST', '/api/assistant/runs/' + waiting_cancel + '/cancel', {}, expected=202)
    call('POST', '/api/assistant/runs/' + waiting_cancel + '/confirm', value, expected=409)
    worker(); check(get(waiting_cancel)['status'] == 'cancelled', 'cancelled waiting plan never starts')

    approved_cancel, _ = create(confirm=True); worker(); value = confirm_body(approved_cancel)
    call('POST', '/api/assistant/runs/' + approved_cancel + '/confirm', value, expected=202)
    call('POST', '/api/assistant/runs/' + approved_cancel + '/cancel', {}, expected=202)
    _, same = call('POST', '/api/assistant/runs/' + approved_cancel + '/confirm', value, expected=200)
    check(same['status'] == 'cancel_requested', 'old approval replay returns pending cancellation, never requeues')
    worker()
    _, same = call('POST', '/api/assistant/runs/' + approved_cancel + '/confirm', value, expected=200)
    check(same['status'] == 'cancelled', 'old approval replay after final cancellation cannot restart execution')

    # Lease recovery and cancelled planning prove restart behavior without fake progress.
    leased, _ = create(); claimed = fixture(action='claim')
    check(claimed['run_id'] == leased and get(leased)['status'] == 'planning', 'worker claim creates planning lease')
    check('"processed":0' in worker(), 'second worker cannot steal active lease')
    fixture(action='lease_expire', run_id=leased)
    fixture(action='execute_claim', run_id=leased, user_id=users['employee']['id'], worker_id=claimed['worker_id'])
    check(get(leased)['status'] == 'planning', 'expired lease holder cannot resume a tool or finalize')
    worker()
    fixture(action='execute_claim', run_id=leased, user_id=users['employee']['id'], worker_id=claimed['worker_id'])
    check(get(leased)['status'] == 'succeeded', 'expired worker lease recovers and finishes')
    exhausted, _ = create(); fixture(action='claim'); fixture(action='lease_exhaust', run_id=exhausted); worker()
    check(get(exhausted)['error']['code'] == 'worker_lease_exhausted', 'lease retry budget ends with explicit failure')
    active_cancel, _ = create(); fixture(action='claim')
    call('POST', '/api/assistant/runs/' + active_cancel + '/cancel', {}, expected=202)
    check('"processed":0' in worker(), 'active cancellation remains pending until lease can be finalized')
    fixture(action='lease_expire', run_id=active_cancel); worker()
    check(get(active_cancel)['status'] == 'cancelled', 'claimed cancellation finishes after stopped worker expires')

    # Account/token gates are revalidated by HTTP and original-job worker.
    for action in ('token_revoke', 'token_expire', 'user_disabled', 'user_expired', 'user_gate'):
        job, _ = create()
        fixture(action=action, token_id=users['employee']['assistant.run']['id'], user_id=users['employee']['id'])
        response, _ = call('GET', '/api/assistant/runs/' + job)
        check(response.status_code in (401, 403), 'HTTP observes gate change: ' + action)
        worker()
        fixture(action='token_restore', token_id=users['employee']['assistant.run']['id'])
        fixture(action='user_restore', user_id=users['employee']['id'], team_id=fixtures['teams']['a'])
        failed = get(job)
        check(failed['status'] == 'failed' and failed['error']['code'] == 'authorization_lost', 'worker stops original revoked permissions: ' + action)
    fixture(action='age_history')
    # Streaming also stops on revocation, instead of retaining the first request identity.
    stream_job, _ = create()
    response = requests.get(base + '/api/assistant/runs/' + stream_job + '/events', headers={
        'User-Agent': ua, 'X-API-Token': users['employee']['assistant.run']['token']}, stream=True, timeout=15)
    check(response.status_code == 200, 'pending streaming handshake')
    iterator = response.iter_lines(chunk_size=1, decode_unicode=True)
    while next(iterator) != 'event: run.created':
        pass
    fixture(action='token_revoke', token_id=users['employee']['assistant.run']['id'])
    remaining = '\n'.join(iterator)
    response.close()
    check('event: stream.error' in remaining and 'authorization_lost' in remaining, 'active SSE stops after token revocation')
    call('GET', '/api/assistant/runs/' + stream_job + '/events', expected=401)
    worker(); fixture(action='token_restore', token_id=users['employee']['assistant.run']['id'])

    # Test adapter is deliberately separate from real inference.
    config('ASSISTANT_TEST_ADAPTER', '1')
    response, out = call('POST', '/api/assistant/runs', {'mode': 'chat', 'message': '재료비'}, expected=202, key='mock-chat')
    check(out['provider'] == 'test' and out['inference_available'] is False, 'test provider remains visibly non-inference')
    worker(); check(get(out['run_id'])['status'] == 'succeeded', 'guarded test adapter deterministic result')
    response, out = call('POST', '/api/assistant/runs', {'mode': 'chat', 'message': 'test-error'}, expected=202, key='mock-error')
    worker(); check(get(out['run_id'])['error']['code'] == 'test_adapter_error', 'test adapter error is explicit')
    config('ASSISTANT_TEST_ADAPTER', '0')
    call('POST', '/api/assistant/runs', {'mode': 'chat', 'message': '재료비'}, expected=503, key='real-still-absent')
    # Check guard with a production-looking name without opening a database connection.
    guard = root / 'tests/assistant/guard.env'
    guard.write_text('ASSISTANT_TEST_ADAPTER=1\nDB_NAME=tbox_re\n'); guard.chmod(0o600)
    script = "require 'src/Env.php'; Env::load('tests/assistant/guard.env'); require 'src/assistant/Provider.php'; echo QarediAssistant\\Provider::testAvailable() ? 'unsafe' : 'disabled';"
    result = subprocess.run(['php', '-r', script], cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
    check(result.stdout == b'disabled', 'test adapter denied for production-looking DB name even when flag enabled')
    guard.unlink()
    config('ASSISTANT_ENABLED', '0')
    response, err = call('GET', '/api/assistant/runs', expected=503)
    check(err['error']['code'] == 'service_disabled', 'default disabled service is explicit')
    config('ASSISTANT_ENABLED', '1')

    # Quotas are checked after idempotency replay and never duplicate accepted work.
    fixture(action='age_history')
    active = [create('quota-' + str(i))[0] for i in range(10)]
    response, err = call('POST', '/api/assistant/runs', {'mode': 'tool', 'tool': {'name': 'files.search', 'arguments': {'query': '재료비', 'limit': 20}}}, key='quota-overflow', expected=429)
    check(err['error']['code'] == 'rate_limited' and response.headers['Retry-After'] == '60', 'active run limit is explicit retryable error')
    create('quota-0')
    worker()
    for i in range(20):
        create('minute-' + str(i)); worker()
    response, err = call('POST', '/api/assistant/runs', {'mode': 'tool', 'tool': {'name': 'files.search', 'arguments': {'query': '재료비', 'limit': 20}}}, key='minute-overflow', expected=429)
    check(err['error']['code'] == 'rate_limited', 'new run minute rate limit is explicit')
    create('quota-0')

    # Stored events/results contain IDs, never descriptions/names or credentials.
    content = sql('SELECT result::text FROM assistant_runs WHERE result IS NOT NULL; SELECT payload::text FROM assistant_events; SELECT detail::text FROM audit_logs;')
    check(not any(secret in content for secret in secrets_seen), 'run/event/audit database excludes every known credential')
    stored = sql('SELECT result::text FROM assistant_runs WHERE result IS NOT NULL; SELECT payload::text FROM assistant_events;')
    check('재료비_' not in stored and 'not executable' not in stored, 'stored artifacts retain only IDs, never stale metadata')
    audit = sql("SELECT action,summary,detail::text FROM audit_logs WHERE action LIKE 'assistant.%';")
    check(all(name in audit for name in ('assistant.create', 'assistant.cancel', 'assistant.confirm')) and 'run_status' in audit and 'run_id' in audit, 'three registered audit actions and safe detail keys written')
    log_path = root.parent / 'logs/audit.log'
    text = log_path.read_text() if log_path.exists() else ''
    check(not any(secret in text for secret in secrets_seen), 'audit file excludes all known credentials')
    privileges = sql("SELECT has_table_privilege('tbox_app','assistant_runs','DELETE'),has_table_privilege('tbox_app','assistant_events','UPDATE'),has_table_privilege('tbox_app','assistant_events','DELETE');")
    check(privileges.strip() == 'f|f|f', 'application cannot delete runs or update/delete append-only events')
    check(sql("SELECT count(*) FROM users WHERE username NOT LIKE 'claude\\_%';").strip() == '0', 'all records are synthetic review accounts')
    print('ASSISTANT_HTTP_CHECKS={}; FAILURES=0'.format(len(checks)), flush=True)


if __name__ == '__main__':
    try:
        run()
    except Exception as error:
        print('INTEGRATION_STOPPED={}; inspect named failed check, no request secrets printed'.format(type(error).__name__), flush=True)
        raise SystemExit(1)
    finally:
        (output / 'http-checks.json').write_text(json.dumps([{'pass': passed, 'check': name} for passed, name in checks], ensure_ascii=False, indent=2))
