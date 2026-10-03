#!/usr/bin/env python3
"""Execute the application's bound visibility SQL predicates against synthetic rows.

SQLite can execute this SQL subset, so the access matrix is checkable without PHP/PG.
This is NOT a substitute for memory_social_http.py (routing, CSRF, actual PostgreSQL).
"""
import json
from pathlib import Path
import re
import sqlite3

source = (Path(__file__).resolve().parents[2] / 'src/community/Memories.php').read_text()
feed_source = source.split('public static function feed(', 1)[1].split('public static function save(', 1)[0]
clauses = re.findall(r'\$where\[\] = ([\'\"])(.*?)\1;', feed_source)
clauses = [value for _, value in clauses]


def clause(prefix):
    values = [value for value in clauses if value.startswith(prefix)]
    assert len(values) == 1, (prefix, values)
    return values[0]


readable = clause('(p.owner_user_id=:viewer OR p.visibility')
following = clause('(p.owner_user_id=:own_feed OR EXISTS')
discoverable = clause('p.discoverable AND')
mine = clause('p.owner_user_id=:mine')
profile = clause('u.username=:profile')
capability = clause("p.visibility='shared' AND p.owner_user_id=:owner")
exact_capability = clause('p.id=CAST(:shared_post AS uuid)').replace('CAST(:shared_post AS uuid)', ':shared_post')
db = sqlite3.connect(':memory:')
db.execute("ATTACH DATABASE ':memory:' AS public")
db.execute("ATTACH DATABASE ':memory:' AS community")
db.create_function('now', 0, lambda: '2026-10-03T00:00:00')
db.create_function('jsonb_array_length', 1, lambda text: len(json.loads(text)))
db.execute('CREATE TABLE public.users(id INTEGER PRIMARY KEY,username TEXT,is_active BOOLEAN,expires_at TEXT)')
db.execute('CREATE TABLE community.memory_posts(id TEXT PRIMARY KEY,owner_user_id INTEGER,visibility TEXT,discoverable BOOLEAN,deleted_at TEXT,trip TEXT,media_ids TEXT)')
db.execute('CREATE TABLE community.memory_follows(follower_user_id INTEGER,followed_user_id INTEGER)')
for uid in [10, 11, 12, 13, 14]:
    db.execute('INSERT INTO public.users VALUES(?,?,?,?)', (uid, 'synthetic' + str(uid), uid != 13, '2020-01-01' if uid == 14 else None))
rows = []
for owner in [10, 11, 12, 13, 14]:
    for visibility in ['private', 'followers', 'community', 'shared']:
        for discovery in [False, True]:
            # Include invalid combinations as defense-in-depth, despite the PostgreSQL CHECK.
            record = (f'{owner}_{visibility}_{int(discovery)}', owner, visibility, discovery, None, 'synthetic', '[1,2]')
            rows.append(record)
            db.execute('INSERT INTO community.memory_posts VALUES(?,?,?,?,?,?,?)', record)
db.execute('INSERT INTO community.memory_posts VALUES(?,?,?,?,?,?,?)', ('deleted', 10, 'community', 1, '2026-01-01', '', '[1]'))
base = ' FROM community.memory_posts p JOIN public.users u ON u.id=p.owner_user_id WHERE p.deleted_at IS NULL AND u.is_active AND (u.expires_at IS NULL OR u.expires_at>now()) AND '
checks = 0


def verify(actual, expected, label):
    global checks
    assert actual == expected, (label, actual, expected)
    checks += 1


def execute(predicates, binds):
    query = base + ' AND '.join('(' + value + ')' for value in predicates)
    ids = {row[0] for row in db.execute('SELECT p.id' + query, binds)}
    posts, moments = db.execute('SELECT count(*),coalesce(sum(jsonb_array_length(p.media_ids)),0)' + query, binds).fetchone()
    verify((posts, moments), (len(ids), len(ids) * 2), 'totals use exact visible set')
    return ids


for edges in [[], [(11, 10)], [(10, 11)], [(11, 10), (12, 10), (10, 12)]]:
    db.execute('DELETE FROM community.memory_follows')
    db.executemany('INSERT INTO community.memory_follows VALUES(?,?)', edges)
    for viewer in [10, 11, 12]:
        binds = dict(viewer=viewer, follower=viewer, own_feed=viewer, feed_follower=viewer, mine=viewer)
        permitted = [r for r in rows if r[1] < 13 and (r[1] == viewer or r[2] in ['community', 'shared'] or (r[2] == 'followers' and (viewer, r[1]) in edges))]
        verify(execute([readable], binds), {r[0] for r in permitted}, 'directly readable records')
        verify(execute([readable, following], binds), {r[0] for r in permitted if r[1] == viewer or (viewer, r[1]) in edges}, 'following feed author filter')
        verify(execute([readable, discoverable], binds), {r[0] for r in permitted if r[3] and r[2] in ['community', 'shared']}, 'explicit discovery never widens private/follower records')
        verify(execute([readable, mine], binds), {r[0] for r in permitted if r[1] == viewer}, 'personal page includes only owner')
        for owner in [10, 11, 12, 13, 14]:
            verify(execute([readable, profile], binds | {'profile': 'synthetic' + str(owner)}),
                   {r[0] for r in permitted if r[1] == owner}, 'profile totals respect visibility, expiry and owner state')

for owner in [10, 11, 13, 14]:
    expected = {r[0] for r in rows if r[1] == owner and owner < 13 and r[2] == 'shared'}
    verify(execute([capability], {'owner': owner}), expected, 'profile capability never grants follower/private access')
    for identifier in ['10_shared_0', '10_followers_0', '11_shared_0']:
        verify(execute([capability, exact_capability], {'owner': owner, 'shared_post': identifier}),
               expected & {identifier}, 'post capability stays on exact owner/post')

print(f'{checks} bound SQL predicate checks passed (SQLite subset; PHP/PostgreSQL HTTP unverified)')
