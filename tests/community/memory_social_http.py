#!/usr/bin/env python3
"""Real HTTP/SQL social acceptance. Only run through the synthetic memories harness."""
import base64
import hashlib
import json
import os
from pathlib import Path
import subprocess
import time
from urllib.parse import urlencode
import uuid

import requests

manifest = json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
assert manifest['database'].startswith('tbox_memories_test_')
base = manifest['base_url'].removesuffix('/tbox') + '/community'
out = Path(os.environ['FILE_RENAME_OUTPUT'])
users = json.loads(Path(manifest['credentials_file']).read_text())['users']
checks = []


def check(ok, label):
    checks.append({'pass': bool(ok), 'name': label})
    print(('PASS ' if ok else 'FAIL ') + label, flush=True)
    if not ok:
        raise AssertionError(label)


def sql(statement):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    result = subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-At',
                             '-d', manifest['database'], '-v', 'ON_ERROR_STOP=1'],
                            env=env, input=statement.encode(), capture_output=True, check=True)
    return result.stdout.decode().strip()


def call(method, path, body=None, user='employee', expected=200, csrf=True, files=None):
    headers = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}
    if user:
        headers['Cookie'] = '__Host-tbox_session=' + users[user]['session']
        if csrf:
            headers['X-CSRF-Token'] = users[user]['csrf']
    response = requests.request(method, base + path, json=body, headers=headers, files=files, timeout=35)
    # Do not echo a capability or credentials in test output.
    check(response.status_code == expected, f'{method} social fixture request {response.status_code}/{expected}')
    return response.json() if 'application/json' in response.headers.get('Content-Type', '') else response


def body(visibility='private', **changes):
    return dict(caption='합성 소셜 테스트 기록', location='합성 장소', trip='여행', taken_on='2026-10-03',
                visibility=visibility, media_ids=[], request_id=str(uuid.uuid4())) | changes


def create(value):
    return call('POST', '/api/memories', value, expected=201)['id']


def feed(user='other', **query):
    return call('GET', '/api/memories?' + urlencode(query), user=user)


try:
    for user, data in users.items():
        data['username'] = sql('SELECT username FROM users WHERE id=' + str(int(data['id'])))
    owner = users['employee']['username']
    follower = users['other']['username']
    following_body = {'username': owner}
    call('GET', '/api/memories/social', user=None, expected=401)
    for method, path, value in [('POST', '/api/memories/follow', following_body),
                                 ('DELETE', '/api/memories/follow', following_body),
                                 ('PATCH', '/api/memories/preferences', {'default_visibility': 'followers'})]:
        call(method, path, value, user='guest', expected=403)
        call(method, path, value, user='other', csrf=False, expected=403)
    call('POST', '/api/memories/follow', {'username': owner, 'follower_user_id': users['other']['id']}, expected=400)
    call('POST', '/api/memories/follow', following_body, expected=400)
    call('POST', '/api/memories/follow', {'username': 'missing_synthetic_member'}, user='other', expected=404)
    for query in ['profile[]=bad', 'q[]=bad', 'view[]=bad', 'offset=-1', 'cursor=bad', 'view=arbitrary']:
        call('GET', '/api/memories?' + query, expected=400)
    for query in ['profile[]=bad', 'q[]=bad', 'list=arbitrary', 'offset=-1']:
        call('GET', '/api/memories/social?' + query, expected=400)
    call('GET', '/api/memories?profile=missing_synthetic_member', expected=404)

    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
    media = call('POST', '/api/memories/media', expected=201, files={'file': ('synthetic.png', png, 'image/png')})
    values = {scope: body(scope, media_ids=[media['id']] if scope == 'followers' else [])
              for scope in ['private', 'followers', 'community', 'shared']}
    posts = {scope: create(value) for scope, value in values.items()}
    followers_path = '/api/memories/' + posts['followers']
    check(feed()['posts'] == [] and feed()['stats']['posts'] == 0, 'default feed and totals exclude unfollowed owners')
    visible = feed(profile=owner)
    check({p['id'] for p in visible['posts']} == {posts['community'], posts['shared']}, 'profile preserves broad access but excludes private/followers')
    check(visible['stats']['posts'] == 2 and visible['stats']['moments'] == 0, 'profile totals do not disclose hidden media or posts')
    check(feed(view='discover')['posts'] == [], 'existing broad visibility does not imply discovery consent')
    for path in [followers_path, followers_path + '/comments', '/api/memories/media/' + media['id']]:
        call('GET', path, user='other', expected=404)
    for path, value in [(followers_path + '/like', {}), (followers_path + '/comments', {'body': 'blocked'})]:
        call('POST', path, value, user='other', expected=404)
    call('POST', '/api/memories/links', {'post_id': posts['followers']}, expected=409)
    for visibility in ['private', 'followers']:
        call('POST', '/api/memories', body(visibility, discoverable=True), expected=400)

    relation = call('POST', '/api/memories/follow', following_body, user='other')['profile']
    check(relation['following'] and relation['followers_count'] == 1, 'follow returns current relationship and count')
    call('POST', '/api/memories/follow', following_body, user='other')
    directory = call('GET', '/api/memories/social?' + urlencode({'profile': owner, 'list': 'followers'}))
    check([m['username'] for m in directory['members']] == [follower], 'retries do not duplicate follower list')
    followed_feed = feed()
    check({p['id'] for p in followed_feed['posts']} == {posts['followers'], posts['community'], posts['shared']}, 'following feed includes permitted follower posts')
    check(followed_feed['stats']['posts'] == 3 and followed_feed['stats']['moments'] == 1, 'authorized totals include exactly visible records')
    call('GET', '/api/memories/' + posts['private'], user='other', expected=404)
    call('GET', followers_path, user='other')
    check(call('GET', '/api/memories/media/' + media['id'], user='other').content == png, 'follower can stream current attachment')
    call('POST', followers_path + '/like', {}, user='other')
    call('POST', followers_path + '/comments', {'body': '허용된 합성 댓글'}, user='other')
    call('GET', followers_path, user='manager', expected=404)
    call('DELETE', '/api/memories/follow', following_body, user='other')
    for path in [followers_path, followers_path + '/comments', '/api/memories/media/' + media['id']]:
        call('GET', path, user='other', expected=404)
    call('DELETE', followers_path + '/like', {}, user='other', expected=404)
    check(feed()['stats']['posts'] == 0, 'unfollow removes records and totals immediately')

    call('PATCH', '/api/memories/preferences', {'default_visibility': 'followers'})
    check(feed(user='employee')['me']['default_visibility'] == 'followers', 'new-post audience preference is saved')
    check(feed()['me']['default_visibility'] == 'private', 'preferences never change another user')
    check(call('GET', '/api/memories/' + posts['private'])['post']['visibility'] == 'private', 'preference does not reclassify old records')

    # Explicit discovery opens no new direct access beyond the selected audience.
    public_id = posts['community']
    call('PATCH', '/api/memories/' + public_id, values['community'] | {'revision': 1, 'discoverable': True})
    check([p['id'] for p in feed(user='manager', view='discover')['posts']] == [public_id], 'non-follower discovers explicit community publication')
    check(feed(user='manager')['posts'] == [], 'discovery does not silently follow its author')
    # An old client omitting the discovery field preserves consent on a broad edit.
    call('PATCH', '/api/memories/' + public_id, values['community'] | {'revision': 2, 'caption': '변경된 합성 기록'})
    check(call('GET', '/api/memories/' + public_id)['post']['discoverable'], 'legacy broad edit retains explicit publication')
    call('PATCH', '/api/memories/' + public_id, values['community'] | {'revision': 3, 'visibility': 'private'})
    check(not call('GET', '/api/memories/' + public_id)['post']['discoverable'], 'legacy visibility narrowing withdraws discovery')
    check(feed(user='manager', view='discover')['posts'] == [], 'withdrawn record is absent from discovery and totals')

    # A preexisting share URL is denied as soon as the owner selects followers.
    shared_path = call('POST', '/api/memories/links', {'post_id': posts['shared']}, expected=201)['path'].removeprefix('/community')
    call('GET', shared_path + '/feed', user=None)
    call('PATCH', '/api/memories/' + posts['shared'], values['shared'] | {'revision': 1, 'visibility': 'followers'})
    call('GET', shared_path + '/feed', user=None, expected=404)
    call('PATCH', '/api/memories/' + posts['shared'], values['shared'] | {'revision': 2})
    call('GET', shared_path + '/feed', user=None, expected=404)

    # Keyset pages retain their ranking when a newer post or likes arrive.
    ranking = [create(body('community', discoverable=True, caption='페이지 ' + str(i))) for i in range(23)]
    first = feed(user='manager', view='discover')
    check(len(first['posts']) == 20 and bool(first['next_cursor']), 'discovery emits bounded page and cursor')
    later = create(body('community', discoverable=True, caption='첫 페이지 이후 새 기록'))
    call('POST', '/api/memories/' + ranking[-1] + '/like', {}, user='other')
    second = feed(user='manager', view='discover', cursor=first['next_cursor'])
    first_ids = {p['id'] for p in first['posts']}; second_ids = {p['id'] for p in second['posts']}
    check(not first_ids & second_ids and first_ids | second_ids == set(ranking), 'pagination has no duplicates or gaps after insert and engagement')
    check(later not in second_ids, 'snapshot excludes records added during scrolling')
    call('GET', '/api/memories?' + urlencode({'view': 'mine', 'cursor': first['next_cursor']}), user='manager', expected=400)
    # Permission checks are always live, even for a previously issued cursor.
    victim = next(iter(second_ids))
    current = call('GET', '/api/memories/' + victim)['post']
    call('PATCH', '/api/memories/' + victim, body('private', revision=current['revision']))
    check(victim not in {p['id'] for p in feed(user='manager', view='discover', cursor=first['next_cursor'])['posts']}, 'snapshot cursor cannot resurrect narrowed visibility')

    sql('UPDATE community.memory_preferences SET follow_window_count=60,follow_window_started_at=now() WHERE user_id=' + str(int(users['other']['id'])))
    call('POST', '/api/memories/follow', following_body, user='other', expected=429)
    check(not call('GET', '/api/memories/social?profile=' + owner, user='other')['profile']['following'], 'rate rejection does not mutate relationships')
    sql("UPDATE community.memory_preferences SET follow_window_started_at=now()-interval '11 minutes' WHERE user_id=" + str(int(users['other']['id'])))
    call('POST', '/api/memories/follow', following_body, user='other')
    sql('UPDATE users SET is_active=false WHERE id=' + str(int(users['employee']['id'])))
    check(feed()['posts'] == [], 'inactive owner records disappear from followed feed')
    call('GET', followers_path, user='other', expected=404)
    call('POST', '/api/memories/follow', following_body, user='other', expected=404)
    sql('UPDATE users SET is_active=true WHERE id=' + str(int(users['employee']['id'])))
    check(sql("SELECT has_table_privilege('tbox_backup','community.memory_follows','SELECT') AND has_table_privilege('tbox_backup','community.memory_preferences','SELECT')") == 't', 'backup account can preserve graph/preferences')
finally:
    (out / 'results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
