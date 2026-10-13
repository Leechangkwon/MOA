#!/usr/bin/env python3
"""Q:F-12 U4 — 서버 수락 시험: 첫 화면 종류 · 빈 q 멘션 후보 · 그림 width_pct (실제 PHP · PostgreSQL)

  python3 tests/community/memories_harness.py --suite qf12_u4_http.py --output <결과 폴더>

memories_harness.py 만 부른다(합성 DB `tbox_memories_test_*` · 임시 복사본 · 로컬 php -S, 끝나면 지운다).
운영 DB · 운영 8082 · 시드 계정 0. 요청은 fixture 의 claude_ 계정과 UA tbox-review/<날짜> 뿐이다(판정 상한 시험의 합성 사용자는 이 합성 DB 안에서만 만들고 끈다).
결과 파일에는 단언 이름 · 참/거짓 + 확정한 값(속성 값 · 후보 아이디 · 사유 코드 · 클래스)만 — 세션 · CSRF · 본문 원문 0.

fixture: A=claude_employee01(팀 X) · B=claude_manager01(팀 X) · C=claude_employee02(팀 Y) · G=claude_guest01 · M=claude_admin01(팀 X)

  ① 화면 종류  GET /community/p/{id} — 프로젝트 · 작업 · 문서의 `<body data-page-kind>` = project · task · doc · 작업만 `section#task-full` ·
               「내 할 일」(my_tasks) 행도 task · 그 문서를 못 보는 사람(다른 팀 · 손님) · 없는 id · 휴지통 = 속성 0 · 칸 0 · HTML 같은 모양 ·
               보기 공유받은 손님 = project(읽기 판정 통과) · 인라인 style · on* · 인라인 script 0
  ② 빈 q 후보  GET /api/mention-candidates?page=&q= — 그 문서를 볼 수 있는 쓰기 자격 사람만(손님 · 다른 팀 0 · 보기 공유 직원은 들어옴) · 부르는 사람 제외 ·
               최근 이 문서에 쓴 사람 먼저 → 아이디 순 · 최대 10 · 판정 상한 50(합성 55명 앞자리 → 통과할 사람이 51번째 뒤면 0) · 못 보는 사람 403 ·
               손님 403 · 없는 문서 404 · 응답 키 그대로({candidates:[{id,username,display_name}]}) · 정확한 아이디 맨 앞(비슷한 아이디 20명 넘어도)
  ③ width_pct  그림 블록 width_pct 10 · 55 · 100 저장 200 · 9 · 101 · 57 · "55" · 55.5 · true 400 · 옛 width 만 = 그대로 200(값 그대로 돌려줌) ·
               서버 렌더(DocRender::html — tbox: · att:) · 인쇄 화면(/p/{id}/print — doc-export.css) `doc-image doc-image--p55` · 옛 = doc-image--wide ·
               인라인 style 0 · 모양 밖 값(검증기를 건너 DB 에 넣은 57)은 옛 width 로(클래스 p57 0) · doc-export.css · community.css 에 19 클래스
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import re
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')

NAMES = {'A': 'employee', 'B': 'manager', 'C': 'other', 'G': 'guest', 'M': 'admin'}
UNAME = {'A': 'claude_employee01', 'B': 'claude_manager01', 'C': 'claude_employee02', 'G': 'claude_guest01', 'M': 'claude_admin01'}
codes = {}


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def req(method, path, body=None, user='A', **kw): return raw(method, path, body, NAMES[user] if user else None, **kw)


def expect(method, path, body, user, status, name, **kw):
    r = req(method, path, body, user, **kw)
    check(r.status_code == status, name + ' → ' + str(status) + ' (got ' + str(r.status_code) + ')')
    return r


def lit(v): return "'" + str(v).replace("'", "''") + "'"


def page(title, user='A', **extra):
    r = expect('POST', '/api/pages', dict(title=title, **extra), user, 201, 'create ' + title)
    return r.json()['page']['id'] if r.status_code == 201 else str(uuid.uuid4())


def html(pid, user='A'):
    return req('GET', '/p/' + pid, None, user)


def kind_of(text):
    m = re.search(r'<body\b[^>]*>', text)
    tag = m.group(0) if m else ''
    k = re.search(r'\sdata-page-kind="([^"]*)"', tag)
    return k.group(1) if k else None


def cands(pid, q='', user='A'):
    return req('GET', '/api/mention-candidates?page=' + pid + '&q=' + q, None, user)


php_prelude = ("require 'src/Env.php'; Env::load(getcwd().'/.env'); require 'src/bootstrap.php';"
               "require_once 'src/community/routes.php';")


def php(code):
    done = subprocess.run(['php', '-r', php_prelude + code], cwd=root, capture_output=True, text=True, timeout=60)
    return done.stdout.strip()


PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')


def tbox_upload(user, name, data, level='internal'):
    h = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d'), 'Cookie': '__Host-tbox_session=' + users[NAMES[user]]['session'],
         'X-CSRF-Token': users[NAMES[user]]['csrf']}
    return requests.post(site + '/tbox/api/files', headers=h, data={'access_level': level},
                         files={'file': (name, data, 'application/octet-stream')}, timeout=60)


def image_doc(ref, **extra):
    b = {'id': str(uuid.uuid4()), 'type': 'image', 'file_ref': ref, 'width': 'wide'}
    b.update(extra)
    return {'schema': 1, 'blocks': [b, {'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': [{'t': '그림 아래 글', 'm': {}}]}]}


def save_doc(pid, doc, user='A'):
    cur = req('GET', '/api/pages/' + pid, None, user).json()['page']['doc_version']
    return req('PATCH', '/api/pages/' + pid, {'doc': doc, 'doc_version': cur}, user)


try:
    for k in NAMES:
        expect('GET', '/api/me/space', None, k, 200, 'personal space ' + k)
    space_x = next(s['id'] for s in req('GET', '/api/spaces', None, 'A').json()['spaces'] if s['kind'] == 'team')
    team_y = sql("SELECT team_id FROM users WHERE username='claude_employee02'")
    team_x = sql("SELECT team_id FROM users WHERE username='claude_employee01'")

    # ════════ ① 첫 화면 종류 ═══════════════════════════════════════════
    proj = req('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': 'U4 개인 프로젝트'}, 'A').json()['page']
    check(proj.get('database', {}).get('purpose') == 'project', '① fixture: personal project purpose=project')
    task = req('POST', '/api/pages', {'parent_id': proj['id'], 'title': 'U4 작업', 'properties': {'section': '01. 진행'}}, 'A').json()['page']
    plain = page('U4 일반 문서')
    child_of_task = page('U4 하위 작업', parent_id=task['id'])
    r = html(proj['id'])
    codes['kind_project'] = kind_of(r.text)
    check(r.status_code == 200 and kind_of(r.text) == 'project' and 'id="task-full"' not in r.text, '① project page → <body data-page-kind="project"> · #task-full 0')
    r = html(task['id'])
    codes['kind_task'] = kind_of(r.text)
    check(r.status_code == 200 and kind_of(r.text) == 'task'
          and '<section id="task-full" class="task-pane task-pane--full" aria-label="작업"></section>' in r.text, '① task (project row) → data-page-kind="task" · empty section#task-full.task-pane.task-pane--full')
    check(len(re.findall(r'\sdata-page-kind="', r.text)) == 1, '① data-page-kind attribute appears once (body only)')
    r = html(plain)
    codes['kind_doc'] = kind_of(r.text)
    check(r.status_code == 200 and kind_of(r.text) == 'doc' and 'id="task-full"' not in r.text, '① plain doc → data-page-kind="doc" · #task-full 0')
    check(kind_of(html(child_of_task).text) == 'doc', '① subtask (child page of a task, not a DB row) → doc (server reads purpose only)')
    # 「내 할 일」 — 빠른 추가(my_tasks) 행도 작업
    mt = req('POST', '/api/pages', {'my_tasks': True, 'title': 'U4 내 할 일 작업'}, 'A')
    if mt.status_code == 201:
        mt_id = mt.json()['page']['id']
        check(kind_of(html(mt_id).text) == 'task', '① 「내 할 일」(purpose=my_tasks) row → task')
        mt_db = sql("SELECT p.public_id FROM community.pages p JOIN community.databases d ON d.page_id=p.id WHERE d.purpose='my_tasks' LIMIT 1")
        check(kind_of(html(mt_db).text) == 'project', '① 「내 할 일」 database page → project')
    else:
        check(False, '① my_tasks quick add 201 (got ' + str(mt.status_code) + ')')
    # 못 보는 사람 · 없는 id · 휴지통 — 속성 0 · 칸 0 · 같은 모양
    unknown = str(uuid.uuid4())
    hc_task = html(task['id'], 'C')
    hc_unknown = html(unknown, 'C')
    check(hc_task.status_code == 200 and kind_of(hc_task.text) is None and 'id="task-full"' not in hc_task.text, '① other-team employee (cannot read the private task) → attribute 0 · #task-full 0')
    check(kind_of(hc_unknown.text) is None and 'id="task-full"' not in hc_unknown.text, '① unknown id → attribute 0 · #task-full 0')
    same = hc_task.text.replace(task['id'], 'ID') == hc_unknown.text.replace(unknown, 'ID')
    if not same:
        import difflib
        (out / 'u4-shape-diff.txt').write_text('\n'.join(list(difflib.unified_diff(hc_task.text.replace(task['id'], 'ID').splitlines(), hc_unknown.text.replace(unknown, 'ID').splitlines(), lineterm=''))[:80]))
    check(same, '① HTML for an unreadable task == HTML for an unknown id (after replacing the id) — existence · kind not revealed')
    check(kind_of(html(proj['id'], 'G').text) is None and kind_of(html(proj['id'], 'C').text) is None, '① guest · other team on private project → attribute 0')
    expect('PUT', '/api/pages/' + proj['id'] + '/shares/' + UNAME['G'], {'level': 'view'}, 'A', 200, '① share project view → guest')
    check(kind_of(html(proj['id'], 'G').text) == 'project', '① guest with view share (decidePageRead passes) → project')
    check(kind_of(html(task['id'], 'G').text) == 'task', '① guest with share on the project (inherited) → task')
    trash = page('U4 휴지통 문서')
    expect('DELETE', '/api/pages/' + trash, None, 'A', 200, '① trash a doc')
    ht = html(trash)
    check(kind_of(ht.text) is None and ht.text.replace(trash, 'ID') == html(unknown, 'A').text.replace(unknown, 'ID'), '① trashed doc → attribute 0 · same shape as unknown id (owner)')
    for pid in (proj['id'], task['id'], plain):
        body_ = html(pid).text
        check(re.search(r'\sstyle=', body_) is None and re.search(r'\son[a-z]+=', body_) is None and re.search(r'<script(?![^>]*\ssrc=)', body_) is None,
              '① inline style · on* · inline script 0 (' + kind_of(body_) + ')')
    # 판정은 창구와 같은 함수 — 그 페이지를 읽는 GET /api/pages/{id} 와 속성 유무가 같다
    agree = []
    for who in ('A', 'B', 'C', 'G', 'M'):
        for pid in (proj['id'], task['id'], plain):
            api_ok = req('GET', '/api/pages/' + pid, None, who).status_code == 200
            agree.append((who, api_ok, kind_of(html(pid, who).text) is not None))
    codes['kind_vs_api'] = agree
    check(all(a == b for _, a, b in agree), '① attribute present ⇔ GET /api/pages/{id} 200 for every account × page (same decidePageRead) ' + json.dumps(agree))

    # ════════ ② 빈 q 멘션 후보 ═════════════════════════════════════════
    sql("UPDATE users SET team_id=" + team_x + " WHERE username='claude_admin01'")
    tdoc = page('U4 팀 문서', 'B', space_id=space_x)
    r = cands(tdoc, '', 'A')
    body = r.json() if r.status_code == 200 else {}
    names = [c['username'] for c in body.get('candidates', [])]
    codes['empty_q_team'] = names
    check(r.status_code == 200 and sorted(body) == ['candidates'] and all(sorted(c) == ['display_name', 'id', 'username'] for c in body['candidates']),
          '② empty q 200 · response keys unchanged ({candidates:[{id,username,display_name}]})')
    check(UNAME['A'] not in names, '② caller excluded')
    check(set(names) == {UNAME['B'], UNAME['M']}, '② team doc: team members with write rights only (manager · admin as team member) — other team · guest 0 ' + json.dumps(names))
    expect('POST', '/api/pages/' + tdoc + '/comments', {'body': 'U4 댓글 — 최근 쓴 사람'}, 'M', 201, '② admin comments on the team doc')
    names2 = [c['username'] for c in cands(tdoc, '', 'A').json()['candidates']]
    check(names2[:1] == [UNAME['M']], '② recent writer first (admin commented → first, before username order) ' + json.dumps(names2))
    expect('GET', '/api/mention-candidates?page=' + tdoc + '&q=', None, 'C', 403, '② cannot read the doc (other team) → 403')
    expect('GET', '/api/mention-candidates?page=' + tdoc + '&q=', None, 'G', 403, '② guest → 403')
    expect('GET', '/api/mention-candidates?page=' + str(uuid.uuid4()) + '&q=', None, 'A', 404, '② unknown doc → 404')
    expect('GET', '/api/mention-candidates?page=' + tdoc + '&q=', None, None, 401, '② anonymous → 401')
    # 개인 문서 + 보기 공유: 직원(C) 은 후보 · 손님(G) 은 아님(쓰기 자격) · Admin 은 admin_all 뿐이라 아님
    pshare = page('U4 공유 문서')
    for who in ('C', 'G'):
        expect('PUT', '/api/pages/' + pshare + '/shares/' + UNAME[who], {'level': 'view'}, 'A', 200, '② share personal doc view → ' + who)
    n3 = [c['username'] for c in cands(pshare, '', 'A').json()['candidates']]
    codes['empty_q_personal'] = n3
    check(n3 == [UNAME['C']], '② personal doc: view-shared employee only (guest 0 — no write rights · admin_all 0 · same team without share 0) ' + json.dumps(n3))
    # 판정 상한 50 — 합성 55명(아이디가 앞서는 'aa_u4cap_*', 다른 팀 — 그 문서를 못 본다). 최근에 쓴 admin 은 맨 앞이라 남고, 통과할 manager 는 51번째 뒤라 0
    sql("INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at) SELECT 'aa_u4cap_'||lpad(g::text,2,'0'),'synthetic-not-a-hash','Employee',"
        + team_y + ",'SYNTHETICSECRET',true,clock_timestamp()+interval '1 day' FROM generate_series(1,55) g")
    n4 = [c['username'] for c in cands(tdoc, '', 'A').json()['candidates']]
    codes['empty_q_cap'] = n4
    check(n4 == [UNAME['M']], '② judgement cap 50: 55 synthetic users ahead in username order (cannot read) → only the recent writer survives; manager (51st+) not judged ' + json.dumps(n4))
    n4q = [c['username'] for c in cands(tdoc, 'claude_manager01', 'A').json()['candidates']]
    check(n4q == [UNAME['B']], '② (control) q=claude_manager01 still finds the manager (search path unchanged)')
    sql("UPDATE users SET is_active=false WHERE username LIKE 'aa_u4cap_%'")
    # 최대 10 — 같은 팀 합성 12명(볼 수 있다)
    sql("INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at) SELECT 'ab_u4ten_'||lpad(g::text,2,'0'),'synthetic-not-a-hash','Employee',"
        + team_x + ",'SYNTHETICSECRET',true,clock_timestamp()+interval '1 day' FROM generate_series(1,12) g")
    n5 = [c['username'] for c in cands(tdoc, '', 'A').json()['candidates']]
    codes['empty_q_ten'] = n5
    check(len(n5) == 10 and n5[0] == UNAME['M'] and n5[1:] == ['ab_u4ten_%02d' % i for i in range(1, 10)] and UNAME['A'] not in n5,
          '② at most 10 (recent writer · then username order) ' + json.dumps(n5))
    # 정확한 아이디 맨 앞 — 비슷한 아이디 25명(ab_u4ten_ + 13 … 이름에 'claude_manager01' 을 품는 아이디)
    sql("INSERT INTO users(username,password_hash,role,team_id,mfa_secret,mfa_enabled,expires_at) SELECT 'a'||lpad(g::text,2,'0')||'claude_manager01','synthetic-not-a-hash','Employee',"
        + team_y + ",'SYNTHETICSECRET',true,clock_timestamp()+interval '1 day' FROM generate_series(1,25) g")
    n6 = [c['username'] for c in cands(tdoc, 'claude_manager01', 'A').json()['candidates']]
    check(n6[:1] == [UNAME['B']], '② exact username first even with 25 similar usernames ahead (search judges 20) ' + json.dumps(n6))
    sql("UPDATE users SET is_active=false WHERE username LIKE 'ab_u4ten_%' OR username LIKE 'a__claude_manager01'")
    check(sql("SELECT count(*) FROM users WHERE is_active AND (username LIKE 'aa_u4cap_%' OR username LIKE 'ab_u4ten_%' OR username LIKE 'a__claude_manager01')") == '0',
          '② synthetic users deactivated after the cap tests')
    # 서버 멘션 해석은 그대로(정확한 아이디) — 부분 아이디는 알림 0, 정확한 아이디는 1
    before = int(sql("SELECT count(*) FROM community.notifications WHERE user_id=(SELECT id FROM users WHERE username='claude_manager01')") or 0)
    expect('POST', '/api/pages/' + tdoc + '/comments', {'body': '@claude_manag 부분 아이디'}, 'A', 201, '② comment with a partial id')
    mid = int(sql("SELECT count(*) FROM community.notifications WHERE user_id=(SELECT id FROM users WHERE username='claude_manager01')") or 0)
    expect('POST', '/api/pages/' + tdoc + '/comments', {'body': '@claude_manager01 정확한 아이디'}, 'A', 201, '② comment with the exact id')
    after = int(sql("SELECT count(*) FROM community.notifications WHERE user_id=(SELECT id FROM users WHERE username='claude_manager01')") or 0)
    check(mid == before and after == before + 1, '② server mention resolution unchanged: partial id 0 notifications · exact id 1 (%d → %d → %d)' % (before, mid, after))

    # ════════ ③ width_pct ═══════════════════════════════════════════════
    pimg = page('U4 그림 문서')
    up = req('POST', '/api/pages/' + pimg + '/attachments', None, 'A', files={'file': ('u4.png', PNG, 'application/octet-stream')})
    att = up.json()['id'] if up.status_code == 201 else str(uuid.uuid4())
    check(up.status_code == 201 and up.json().get('image') is True, '③ fixture: png attachment 201 (image · clean)')
    ref = 'att:' + att
    for v in (10, 55, 100):
        r = save_doc(pimg, image_doc(ref, width_pct=v))
        got = req('GET', '/api/pages/' + pimg, None, 'A').json()['page']['doc']['blocks'][0] if r.status_code == 200 else {}
        check(r.status_code == 200 and got.get('width_pct') == v and got.get('width') == 'wide', '③ width_pct %d → 200 · stored as is (width kept)' % v)
    for v in (9, 101, 57, 0, -5, '55', 55.5, True, None):
        r = save_doc(pimg, image_doc(ref, width_pct=v))
        j = r.json() if r.headers.get('Content-Type', '').startswith('application/json') else {}
        codes['bad_' + json.dumps(v)] = [r.status_code, j.get('reason'), (j.get('errors') or [{}])[0] if isinstance(j.get('errors'), list) else None]
        check(r.status_code == 400, '③ width_pct %s → 400 (got %d)' % (json.dumps(v), r.status_code))
    r = save_doc(pimg, image_doc(ref))
    got = req('GET', '/api/pages/' + pimg, None, 'A').json()['page']['doc']['blocks'][0]
    check(r.status_code == 200 and 'width_pct' not in got and got.get('width') == 'wide', '③ old shape (width only) → 200 · unchanged (no width_pct)')
    # 서버 렌더러(DocRender::html) — 첨부 그림 · 자료실 그림 · 옛 폭 · 모양 밖 값
    tf = tbox_upload('A', 'u4-tbox.png', PNG)
    tfile = tf.json().get('file', tf.json()).get('id') if tf.status_code in (200, 201) else None
    if not tfile and tf.status_code in (200, 201):
        tfile = tf.json().get('public_id')
    check(tf.status_code in (200, 201) and isinstance(tfile, str), '③ fixture: t-box png upload (%d)' % tf.status_code)
    render = php(
        "$a=['" + att + "'=>['name'=>'u4.png','size'=>1,'image'=>true,'scan_status'=>'clean']];"
        "$f=['" + str(tfile) + "'=>['name'=>'t.png','size'=>1,'image'=>true]];"
        "$b=function($ref,$x){return ['schema'=>1,'blocks'=>[array_merge(['id'=>'" + str(uuid.uuid4()) + "','type'=>'image','file_ref'=>$ref,'width'=>'wide'],$x)]];};"
        "$ctx=['attachment_titles'=>$a,'attachment_page'=>'" + pimg + "','file_titles'=>$f];"
        "echo json_encode([DocRender::html($b('att:" + att + "',['width_pct'=>55]),$ctx),DocRender::html($b('tbox:" + str(tfile) + "',['width_pct'=>55]),$ctx),"
        "DocRender::html($b('tbox:" + str(tfile) + "',[]),$ctx),DocRender::html($b('att:" + att + "',['width_pct'=>57]),$ctx),DocRender::html($b('att:" + att + "',['width_pct'=>'55']),$ctx)]);")
    try:
        rs = json.loads(render)
    except ValueError:
        rs = ['', '', '', '', '']
        (out / 'u4-render-error.txt').write_text(render[:2000])
    codes['render_classes'] = [re.findall(r'class="(doc-image[^"]*)"', x) for x in rs]
    check('<figure class="doc-image doc-image--p55"' in rs[0] and '<figure class="doc-image doc-image--p55"' in rs[1], '③ DocRender: att: · tbox: image width_pct 55 → figure.doc-image.doc-image--p55')
    check('<figure class="doc-image doc-image--wide"' in rs[2], '③ DocRender: old width only → doc-image--wide (unchanged)')
    check('doc-image--p57' not in rs[3] and 'doc-image--wide' in rs[3] and 'doc-image--p55' not in rs[4] and 'doc-image--wide' in rs[4],
          '③ DocRender: out-of-shape width_pct (57 · "55" — bypassing the validator) → old width class (no free-form class)')
    check(all(re.search(r'\sstyle=', x) is None for x in rs), '③ DocRender output: inline style 0')
    # 인쇄 화면(내보내기 CSS) — 자료실 그림은 그림으로 그린다(첨부는 U3-1b 대로 로그인 필요한 링크)
    ptb = page('U4 인쇄 문서')
    r = save_doc(ptb, image_doc('tbox:' + str(tfile), width_pct=55))
    check(r.status_code == 200, '③ save tbox: image width_pct 55 (%d)' % r.status_code)
    pr = req('GET', '/p/' + ptb + '/print', None, 'A')
    codes['print_classes'] = re.findall(r'class="(doc-image[^"]*)"', pr.text)
    check(pr.status_code == 200 and '<figure class="doc-image doc-image--p55"' in pr.text and re.search(r'\sstyle=', pr.text) is None,
          '③ print view (/p/{id}/print · doc-export.css) → figure.doc-image--p55 · inline style 0')
    ex = req('GET', '/api/pages/' + ptb + '/export?format=html', None, 'A')
    check(ex.status_code == 200 and re.search(r'\sstyle=', ex.text) is None and '.doc-image--p55 { max-width:55%; }' in ex.text,
          '③ HTML export: inline style attribute 0 · the 19-class stylesheet travels with it (images stay links — U3-1b)')
    css_e = (root / 'public/assets/community/doc-export.css').read_text()
    css_c = (root / 'public/assets/community/community.css').read_text()
    want = ['.doc-image--p%d' % n for n in range(10, 101, 5)]
    check(len(want) == 19 and all((w + ' {') in css_e for w in want) and all((w + ' {') in css_c for w in want)
          and re.search(r'doc-image--p(?!10\b|15\b|20\b|25\b|30\b|35\b|40\b|45\b|50\b|55\b|60\b|65\b|70\b|75\b|80\b|85\b|90\b|95\b|100\b)\d+', css_c + css_e) is None,
          '③ doc-export.css · community.css carry exactly the 19 width classes p10 … p100')
    # 읽기 판정은 그대로 — 남의 문서 그림 크기를 바꿀 수 없다
    r = save_doc(pimg, image_doc(ref, width_pct=30), 'A')
    rc = req('PATCH', '/api/pages/' + pimg, {'doc': image_doc(ref, width_pct=40), 'doc_version': 99}, 'C')
    check(r.status_code == 200 and rc.status_code in (403, 404), '③ other team cannot save width (%d)' % rc.status_code)
finally:
    (out / 'qf12-u4-http-results.json').write_text(json.dumps({'checks': checks, 'confirmed_values': codes}, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
    if any(not x['pass'] for x in checks):
        raise SystemExit(1)
