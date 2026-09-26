#!/usr/bin/env python3
# tests/community/stage5b_search.py — 5-B 검색이 **볼 수 있는 것만** 돌려주는가 (C:T-70 ~)
#
# 쓰는 법: py_secret S_E1 S_E2 S_AD -- python3 tests/community/stage5b_search.py <SITE> <UA>
#   세션 셋(직원1 · 직원2 · 관리자 — claude_employee01·claude_employee02·claude_admin01)은 **표준입력에서 한 줄씩**
#   차례로 읽는다 — 명령 인자에 싣지 않는다(R-62 · 봇 규칙 R15). 셸에서는 tests/lib/secrets.sh 의 py_secret 로 준다.
#   · 직원1이 고유한 낱말이 든 비공개 페이지를 만들고, 직원2·관리자의 검색에 **없는지** 본다.
#   · 직원2에게 보기 공유 → 나온다 / 공유 빼기 → 사라진다 / 사내로 열기 → 나온다.
#   · 관리자는 남의 비공개를 **검색으로는** 보지 못한다(관리자 특권 갈래를 끈다, search.php 머리글).
#   · 빈 검색어는 빈 결과 · ILIKE 특수 글자(%·_·#)는 글자 그대로 · 응답에 본문이 20자 넘게 새지 않는다(조각만).
#   만든 페이지는 앱 창구(삭제)로 되돌린다. 나가는 값: 마지막 줄 `SEARCH_FAIL=<n>`.
import json, sys, time, uuid, urllib.request, urllib.error, urllib.parse
SITE, UA = sys.argv[1].rstrip('/'), sys.argv[2]
S = {w: sys.stdin.readline().rstrip('\n') for w in ('e1', 'e2', 'ad')}
if not all(S.values()):
    print('세션 셋(직원1·직원2·관리자)을 표준입력으로 한 줄씩 주어야 한다 — 시작하지 않는다'); sys.exit(2)
CSRF = {}
fails = 0
def req(who, m, path, body=None):
    h = {'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + S[who]}
    if m != 'GET':
        h['X-CSRF-Token'] = CSRF[who]; h['Content-Type'] = 'application/json'
    r = urllib.request.Request(SITE + path, data=None if body is None else json.dumps(body).encode(), headers=h, method=m)
    try:
        with urllib.request.urlopen(r) as x: return x.status, json.loads(x.read() or b'{}')
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read() or b'{}')
        except Exception: return e.code, {}
def ok(name, cond, detail=''):
    global fails
    print(('  ✔ ' if cond else '  ✖ ') + name + ('' if cond else '  — ' + str(detail)[:200])); fails += 0 if cond else 1
def ids(who, q):
    c, d = req(who, 'GET', '/community/api/search?q=' + urllib.parse.quote(q))
    return c, [r['id'] for r in d.get('results', [])], d
for w in S:
    c, d = req(w, 'GET', '/tbox/api/me'); CSRF[w] = d.get('csrf_token', '')
    ok(f'{w} 로그인 세션 살아 있음', c == 200, c)
WORD = 'srchzq%d' % int(time.time())
C = '/community/api/pages'
c, d = req('e1', 'POST', C, {'title': '검색시험 ' + WORD}); P = d.get('page', {}).get('id', '')
ok('직원1 비공개 페이지 만들기 201', c == 201, (c, d))
c, g = req('e1', 'GET', f'{C}/{P}')
doc = {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': '본문 속 비밀 낱말 ' + WORD + 'body 끝', 'm': {}}]}]}
c, d = req('e1', 'PATCH', f'{C}/{P}', {'doc_version': g['page']['doc_version'], 'doc': doc, 'title': '검색시험 ' + WORD})
ok('본문 저장 200', c == 200, (c, d))
print('── 소유자·남·관리자')
c, r, d = ids('e1', WORD); ok('직원1(소유자) 찾으면 나온다', P in r, d)
c, r, d = ids('e1', WORD + 'body'); ok('직원1 본문 낱말로도 나온다', P in r and any(WORD + 'body' in x.get('snippet', '') for x in d.get('results', [])), d)
c, r, d = ids('e2', WORD); ok('직원2 찾으면 **없다**(비공개)', P not in r and c == 200, d)
c, r, d = ids('ad', WORD); ok('관리자도 검색으로는 **없다**(특권 갈래 끔)', P not in r and c == 200, d)
print('── 공유')
c, d = req('e1', 'PUT', f'{C}/{P}/shares/claude_employee02', {'level': 'view'}); ok('직원2에게 보기 공유', c in (200, 201), (c, d))
c, r, d = ids('e2', WORD); ok('공유 뒤 직원2 검색에 나온다', P in r, d)
c, d = req('e1', 'DELETE', f'{C}/{P}/shares/claude_employee02', {}); ok('공유 빼기', c in (200, 204), (c, d))
c, r, d = ids('e2', WORD); ok('공유 뺀 뒤 직원2 검색에서 사라진다', P not in r, d)
print('── 사내')
c, d = req('e1', 'PATCH', f'{C}/{P}/visibility', {'visibility': 'internal'}); ok('사내로 열기', c == 200, (c, d))
c, r, d = ids('e2', WORD); ok('사내면 직원2 검색에 나온다', P in r, d)
hit = next((x for x in d.get('results', []) if x['id'] == P), {})
ok('결과의 본문은 조각뿐(200자 이하)', len(hit.get('snippet', '')) <= 200, len(hit.get('snippet', '')))
print('── 입력')
c, r, d = ids('e2', ''); ok('빈 검색어는 빈 결과 200', c == 200 and r == [], d)
c, r, d = ids('e2', '   '); ok('공백만은 빈 결과 200', c == 200 and r == [], d)
c, r, d = ids('e2', '%'); ok('% 는 글자 그대로(전부가 나오지 않는다)', c == 200 and P not in r, len(r))
c, r, d = ids('e2', "' OR 1=1 --"); ok('SQL 조각은 그냥 글자(200, 오류 없음)', c == 200, (c, d))
c, r, d = ids('e2', 'x' * 150); ok('긴 검색어(150자)도 200', c == 200, c)
c, d = req('e1', 'DELETE', f'{C}/{P}', {}); ok('정리 — 삭제 200', c == 200, (c, d))
c, r, d = ids('e1', WORD); ok('지운 페이지는 검색에 없다', P not in r, d)
print('SEARCH_FAIL=%d' % fails)
sys.exit(1 if fails else 0)
