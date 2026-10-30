#!/usr/bin/env python3
"""사이트 Q:F-16 1차 — 업무 프로젝트에서 템플릿 골라 쓰기 · 창구 쪽 수락 (실제 PHP · PostgreSQL · 격리 틀 memories_harness.py 만)

  python3 tests/community/memories_harness.py --port 8768 --suite qf16_templates_http.py --output <결과>/http

합성 DB · 임시 복사본 · 로컬 php -S 만 친다(운영 · 시드 계정 0). 계정은 fixture 의 claude_ 다섯 · UA tbox-review/<날짜>.
서버 코드 변경 0 인 기능이라, 화면이 기대는 지금 판정(목록 · 만들기 · 반복)이 그대로인지를 본다. 결과 파일에는 단언 이름 · 참거짓만.

  [list]   Q:T-45 행을 더할 수 있는 사람만 프로젝트 행 템플릿 목록 · 보기 공유 403 + 감사 · 손님 · 세션 없음
  [make]   Q:T-46 남의 개인 템플릿 403 + 감사 / Q:T-47 일반 템플릿은 본문만 · 누른 자리 섹션이 이김 · 만든 뒤 「할 일」 → 내 작업
  [repeat] Q:T-49 템플릿 반복(매주) → 규칙 주인의 「내 작업」 · 같은 슬롯 두 번 0 · 일반 템플릿 + 「만들 자리」 = 프로젝트
  [static] Q:T-50 일부 — security_lint · php -l · node --check · 부품 버전 · 새 줄의 금지 패턴 · 새 클래스 일곱 · 「양식」 0
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import datetime
import re
from zoneinfo import ZoneInfo
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
SEOUL = ZoneInfo('Asia/Seoul')
SRC = Path(__file__).resolve().parents[2]   # 작업 트리(정적 검사 — 운영 체크아웃이면 거부)
assert not str(SRC).startswith('/var/www')


def check(ok, name):
    """단언 — 실패해도 끝까지 돈다(마지막에 실패 수로 끝낸다)."""
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def lit(v): return "'" + str(v).replace("'", "''") + "'"
def n(text): return int((sql(text).splitlines() or ['0'])[0] or 0)
def T(text): return [{'t': text, 'm': {}}]
def bid(): return str(uuid.uuid4())


def doc_of(*blocks): return {'schema': 1, 'blocks': list(blocks)}
def para(text): return {'id': bid(), 'type': 'paragraph', 'text': T(text)}
def todo(text): return {'id': bid(), 'type': 'to_do', 'text': T(text), 'checked': False}
def head2(text): return {'id': bid(), 'type': 'heading', 'level': 2, 'text': T(text)}


def table(*rows):
    return {'id': bid(), 'type': 'table', 'header_row': True, 'header_col': False,
            'rows': [{'color': None, 'cells': [{'text': T(c), 'color': None} for c in r]} for r in rows]}


def audit_deny(action): return n("SELECT count(*) FROM audit_logs WHERE action=" + lit(action) + " AND result='deny'")
def rows_of(dbid, user='employee'): return call('GET', '/api/databases/' + dbid + '/rows', user=user)
def my_task_ids(user='employee'):
    data = call('GET', '/api/my-tasks', user=user)
    return {it['public_id'] for b in data.get('buckets', []) for it in b.get('items', [])}


def prepare():
    """두 시험 공통 준비 — 창구로 만들고, 창구가 없는 것(사이트 기본 시드 · 안내 창 닫기)만 합성 DB SQL."""
    F = {}
    for u in users:
        call('GET', '/api/me/space', user=u)
    sql("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed','overview' FROM users ON CONFLICT DO NOTHING")
    # 사이트 기본 셋(회의록 · 인수인계서 · 업무 체크리스트) — 운영과 같은 마이그레이션 시드 문장 그대로(합성 틀은 이 마이그레이션을 돌리지 않는다)
    seed = (root / 'db/migrations/2026-09-27_community_templates.sql').read_text()
    start = seed.index('INSERT INTO community.templates (seed_key')
    end = seed.index('ON CONFLICT (seed_key) DO NOTHING;', start) + len('ON CONFLICT (seed_key) DO NOTHING;')
    sql(seed[start:end])
    spaces = call('GET', '/api/spaces')['spaces']
    team = next(s for s in spaces if s['kind'] == 'team')
    F['team_space'] = team['id']
    p = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': '주간 업무', 'space_id': team['id']}, expected=201)['page']
    F['P'] = p['id']
    F['Pdb'] = p['database']['id']
    p2 = call('POST', '/api/pages', {'database': True, 'preset': 'project', 'title': '개인 프로젝트'}, expected=201)['page']
    F['P2'] = p2['id']
    F['P2db'] = p2['database']['id']
    # 행 템플릿 T-row — 섹션 「01. 진행」 + 할 일 · 표 블록
    row_doc = doc_of(head2('이번 주 순서'), todo('자료 수집'), todo('자동 대사'), todo('예외 확인'),
                     table(['항목', '장부', '명세서', '차이'], ['재료 A', '0', '0', '0']), para('빈 고리: 계산식 · 받는 사람'))
    src_row = call('POST', '/api/pages', {'parent_id': F['P'], 'title': '주간재료비 보고', 'properties': {'section': '01. 진행'}, 'doc': row_doc}, expected=201)['page']
    F['row_src'] = src_row['id']
    F['row_blocks'] = len(row_doc['blocks'])
    F['T_row'] = call('POST', '/api/templates', {'page_id': src_row['id'], 'name': '주간재료비 보고', 'scope': 'database'}, expected=201)['template']['id']
    # 팀 템플릿 T-team (P 의 작업 하나에서)
    src_team = call('POST', '/api/pages', {'parent_id': F['P'], 'title': '팀 점검표', 'doc': doc_of(todo('팀 점검 하나'), todo('팀 점검 둘'))}, expected=201)['page']
    F['T_team'] = call('POST', '/api/templates', {'page_id': src_team['id'], 'name': '팀 점검표', 'scope': 'team'}, expected=201)['template']['id']
    # 내 템플릿 T-me (개인 공간 문서에서)
    src_me = call('POST', '/api/pages', {'title': '흐름 지도', 'doc': doc_of(head2('흐름'), para('자료 수집 → 자동 대사 → 주간재료비'), todo('빈 고리 채우기'))}, expected=201)['page']
    F['T_me'] = call('POST', '/api/templates', {'page_id': src_me['id'], 'name': '흐름 지도', 'scope': 'personal'}, expected=201)['template']['id']
    F['me_blocks'] = 3
    # 남의 개인 템플릿 T-other (other 의 개인 공간 문서에서)
    src_other = call('POST', '/api/pages', {'title': '남의 메모', 'doc': doc_of(para('남의 본문'))}, user='other', expected=201)['page']
    F['T_other'] = call('POST', '/api/templates', {'page_id': src_other['id'], 'name': '남의 메모', 'scope': 'personal'}, user='other', expected=201)['template']['id']
    # 다른 팀(b) 템플릿 — other 의 팀 공간 문서에서
    other_team = next((s for s in call('GET', '/api/spaces', user='other')['spaces'] if s['kind'] == 'team'), None)
    F['T_other_team'] = None
    if other_team is not None:
        src_ot = call('POST', '/api/pages', {'title': 'B팀 틀', 'space_id': other_team['id'], 'doc': doc_of(para('B팀 본문'))}, user='other', expected=201)['page']
        F['T_other_team'] = call('POST', '/api/templates', {'page_id': src_ot['id'], 'name': 'B팀 틀', 'scope': 'team'}, user='other', expected=201)['template']['id']
    site = [t for t in call('GET', '/api/templates')['templates'] if t['scope'] == 'site']
    meeting = next(t for t in site if t['name'] == '회의록')
    F['T_site'] = meeting['id']
    F['site_doc'] = call('GET', '/api/templates/' + meeting['id'])['template']['doc']
    # 보기 공유 — P2(개인 프로젝트)를 other 에게 보기로
    call('PUT', '/api/pages/' + F['P2'] + '/shares/claude_employee02', {'level': 'view'})
    return F


# ═══ HTTP 단언 ═══
if __name__ == '__main__':
    try:
        F = prepare()

        # ── [list] Q:T-45 ──
        a = call('GET', '/api/templates?database=' + F['Pdb'])
        ids = [t['id'] for t in a.get('templates', [])]
        check(ids == [F['T_row']] and a['templates'][0]['scope'] == 'database' and a['templates'][0]['database'] == F['Pdb'],
              'Q:T-45 ① employee 프로젝트 행 템플릿 목록 = T-row 하나(scope database · 그 DB)')
        g = call('GET', '/api/templates')
        gids = {t['id'] for t in g.get('templates', [])}
        check({F['T_me'], F['T_team'], F['T_site']} <= gids, 'Q:T-45 ① 일반 목록에 내 템플릿 · 팀 · 사이트 기본(회의록)')
        check(F['T_other'] not in gids and F['T_row'] not in gids, 'Q:T-45 ① 일반 목록에 남의 개인 템플릿 0 · 행 템플릿 0')
        view = call('GET', '/api/pages/' + F['P2'], user='other')
        check(view.get('database', {}).get('can_add_rows') is False, 'Q:T-45 ② 보기 공유: 프로젝트는 열리고 can_add_rows 거짓')
        before = audit_deny('template.list')
        r = raw('GET', '/api/templates?database=' + F['P2db'], user='other')
        check(r.status_code == 403, 'Q:T-45 ② 보기 공유: 행 템플릿 목록 403 (%d)' % r.status_code)
        check(audit_deny('template.list') == before + 1, 'Q:T-45 ② 감사 template.list deny +1')
        r = raw('GET', '/api/templates?database=' + F['Pdb'], user='guest')
        check(r.status_code in (403, 404), 'Q:T-45 ③ 손님: 행 템플릿 목록 막힘 (%d — 지금 판정 그대로)' % r.status_code)
        r = raw('GET', '/api/templates?database=' + F['Pdb'], user=None)
        check(r.status_code == 401, 'Q:T-45 ③ 세션 없음 401 (%d)' % r.status_code)

        # ── [make] Q:T-46 · Q:T-47 ──
        made = call('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F['T_row'], 'properties': {'section': '02. 마무리'}}, expected=201)['page']
        rows = {x['id']: x for x in rows_of(F['Pdb'])['rows']}
        m = rows.get(made['id'], {})
        check(m.get('values', {}).get('section') == '02. 마무리', 'Q:T-47 ④ 행 템플릿 + 누른 자리 섹션 = 02. 마무리 (WT-3)')
        check(m.get('title') == '주간재료비 보고', 'Q:T-47 ④ 제목 = 템플릿 제목')
        check(m.get('status') in (None, '') and m.get('assignee') in (None, {}), 'Q:T-47 ④ 만든 직후 상태 없음 · 담당 없음 (WT-8)')
        got = call('GET', '/api/pages/' + made['id'])
        check(len(got['page']['doc']['blocks']) == F['row_blocks'], 'Q:T-47 ④ 본문 블록 수 = T-row 본문')
        call('PATCH', '/api/pages/' + made['id'] + '/properties', {'status': 'todo'})
        check(made['id'] in my_task_ids(), 'Q:T-47 ④ 「할 일」로 바꾼 뒤 「내 작업」에 뜬다(작성자 = 나 · 담당 없음)')

        meet = call('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F['T_site'], 'properties': {'section': '01. 진행'}}, expected=201)['page']
        rows = {x['id']: x for x in rows_of(F['Pdb'])['rows']}
        mv = rows.get(meet['id'], {}).get('values', {})
        check(mv.get('section') == '01. 진행' and set(k for k, v in mv.items() if v not in (None, '', [])) <= {'section'},
              'Q:T-47 ⑤ 사이트 기본(회의록) → 섹션만 · 다른 칸은 비어 있음(본문만)')
        mdoc = call('GET', '/api/pages/' + meet['id'])['page']['doc']['blocks']
        check(len(mdoc) == len(F['site_doc']['blocks']) and mdoc[0]['type'] == F['site_doc']['blocks'][0]['type'],
              'Q:T-47 ⑤ 본문 = 회의록 본문(블록 수 · 첫 블록 종류)')
        for key, name in (('T_me', '내 템플릿'), ('T_team', '팀')):
            x = call('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F[key]}, expected=201)['page']
            xv = {r['id']: r for r in rows_of(F['Pdb'])['rows']}.get(x['id'], {}).get('values', {})
            check(all(v in (None, '', []) for v in xv.values()), 'Q:T-47 ⑥ %s → 프로젝트 작업 · 칸 값 0(본문만)' % name)
        count_before = len(rows_of(F['Pdb'])['rows'])
        if F['T_other_team']:
            r = raw('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F['T_other_team']})
            check(r.status_code in (400, 403, 404), 'Q:T-47 ⑥ 다른 팀 템플릿 → 막힘 (%d — 지금 판정 그대로)' % r.status_code)
        before = audit_deny('template.read')
        r = raw('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F['T_other']})
        check(r.status_code == 403, 'Q:T-46 ⑦ 남의 개인 템플릿으로 만들기 403 (%d)' % r.status_code)
        check(audit_deny('template.read') >= before + 1, 'Q:T-46 ⑦ 감사 template.read deny +1')
        check(len(rows_of(F['Pdb'])['rows']) == count_before, 'Q:T-46 ⑦ 프로젝트 행 수 변동 0')
        r = raw('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F['T_row'], 'doc': doc_of(para('x'))})
        check(r.status_code == 400, 'Q:T-46 ⑧ template_id + doc 400 (%d)' % r.status_code)
        r = raw('POST', '/api/pages', {'my_tasks': True, 'title': 'x', 'template_id': F['T_row']})
        check(r.status_code == 400, 'Q:T-46 ⑧ 「내 할 일」 빠른 추가 + template_id 400 (%d)' % r.status_code)
        r = raw('POST', '/api/pages', {'parent_id': F['P2'], 'template_id': F['T_row']})
        check(r.status_code == 400 and (r.json() or {}).get('reason') == 'template_db_mismatch', 'Q:T-46 ⑧ 다른 프로젝트에 행 템플릿 → 400 template_db_mismatch')
        r = raw('POST', '/api/pages', {'parent_id': F['P'], 'template_id': F['T_row']}, csrf=False)
        check(r.status_code == 403, 'Q:T-46 ⑧ CSRF 없음 403 (%d)' % r.status_code)

        # ── [repeat] Q:T-49 ──
        now = datetime.datetime.now(SEOUL)
        slot = (now - datetime.timedelta(minutes=10)).replace(second=0, microsecond=0)
        slot = slot.replace(minute=slot.minute - slot.minute % 10)
        week = '%02d' % slot.isocalendar()[1]
        rule = {'kind': 'recurring', 'name': '주간재료비 보고 반복', 'scope_page_id': None,
                'trigger': {'type': 'schedule', 'freq': 'weekly', 'weekday': slot.isoweekday(), 'time': slot.strftime('%H:%M')},
                'actions': [{'type': 'create_page', 'source': {'template': F['T_row']}, 'parent': None,
                             'title_pattern': '주간재료비 보고 — {YYYY}년 {주}주', 'status': 'todo', 'due_in_days': 0},
                            {'type': 'notify', 'to': 'owner', 'text_key': 'recurring_created'}]}
        aid = call('POST', '/api/automations', rule, expected=201)['automation']['id']
        dry = call('POST', '/api/automations/' + aid + '/dry-run', {})
        want_title = '주간재료비 보고 — %d년 %s주' % (slot.year, week)
        wc = (dry.get('would_create') or [{}])[0]
        check(dry.get('status') == 'ok' and wc.get('title') == want_title, 'Q:T-49 ⑨ 시험 실행 ok · 제목 틀 {YYYY}년 {주}주 = ' + want_title)
        check(wc.get('parent_title') == '주간 업무', 'Q:T-49 ⑨ 행 템플릿 반복의 자리 = 그 프로젝트')
        call('PATCH', '/api/automations/' + aid, {'is_enabled': True})
        # 실행기는 「규칙을 만든 뒤의 슬롯」만 잡는다(`slot >= created_at`) — 방금 지난 슬롯을 쓰려고 합성 DB 에서만 만든 시각을 1시간 앞으로
        sql("UPDATE community.automations SET created_at = now() - interval '1 hour' WHERE public_id=" + lit(aid))
        before_rows = {x['id'] for x in rows_of(F['Pdb'])['rows']}
        run = subprocess.run(['php', 'scripts/community_automations.php'], cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        check(run.returncode == 0, 'Q:T-49 ⑨ 실행기 rc 0 (%d)' % run.returncode)
        after = rows_of(F['Pdb'])['rows']
        new = [x for x in after if x['id'] not in before_rows]
        check(len(new) == 1, 'Q:T-49 ⑨ 프로젝트 행 +1 (%d)' % len(new))
        if new:
            nv = new[0]
            check(nv.get('title') == want_title and nv.get('values', {}).get('section') == '01. 진행', 'Q:T-49 ⑨ 새 작업 제목 · 섹션 = 템플릿 값')
            check(nv.get('status') == 'todo' and nv.get('due') == slot.strftime('%Y-%m-%d') and nv.get('assignee') in (None, {}),
                  'Q:T-49 ⑨ 상태 할 일 · 기한 오늘 · 담당 없음 (WT-7 B · WT-8 A)')
            author = sql("SELECT author_id FROM community.pages WHERE public_id=" + lit(nv['id']))
            check(author == str(users['employee']['id']), 'Q:T-49 ⑨ 작성자 = 규칙 주인')
            check(nv['id'] in my_task_ids(), 'Q:T-49 ⑨ 규칙 주인의 「내 작업」에 뜬다')
        run2 = subprocess.run(['php', 'scripts/community_automations.php'], cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        check(run2.returncode == 0 and len(rows_of(F['Pdb'])['rows']) == len(after), 'Q:T-49 ⑨ 같은 슬롯 두 번째 실행 +0')
        rule2 = json.loads(json.dumps(rule))
        rule2['name'] = '흐름 지도 반복'
        rule2['actions'][0]['source'] = {'template': F['T_me']}
        rule2['actions'][0]['parent'] = F['P']
        rule2['actions'][0]['title_pattern'] = '{제목} {YYYY-MM}'
        aid2 = call('POST', '/api/automations', rule2, expected=201)['automation']['id']
        dry2 = call('POST', '/api/automations/' + aid2 + '/dry-run', {})
        check(dry2.get('status') == 'ok' and (dry2.get('would_create') or [{}])[0].get('parent_title') == '주간 업무',
              'Q:T-49 ⑩ 일반 템플릿 + 「만들 자리」 = 프로젝트 → 시험 실행 ok · 자리 = 주간 업무')
        call('PATCH', '/api/automations/' + aid2, {'is_enabled': True})
        sql("UPDATE community.automations SET created_at = now() - interval '1 hour' WHERE public_id=" + lit(aid2))
        before_rows = {x['id'] for x in rows_of(F['Pdb'])['rows']}
        subprocess.run(['php', 'scripts/community_automations.php'], cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        new2 = [x for x in rows_of(F['Pdb'])['rows'] if x['id'] not in before_rows]
        check(len(new2) == 1 and all(v in (None, '', []) for v in new2[0].get('values', {}).values()),
              'Q:T-49 ⑩ 일반 템플릿 반복 → 프로젝트 행 +1 · 칸 값 0(본문만)')

        # ── [static] Q:T-50 일부 ──
        def sh(cmd, cwd=SRC):
            return subprocess.run(cmd, cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        lint = sh(['bash', 'scripts/security_lint.sh'])
        check(lint.returncode == 0, 'Q:T-50 ⑪ security_lint 0 (rc %d)' % lint.returncode)
        check(sh(['php', '-l', 'src/community/handlers/page_view.php']).returncode == 0, 'Q:T-50 ⑪ php -l page_view.php')
        js = ['public/assets/community/work.js', 'public/assets/community/templates.js', 'public/assets/community/automations.js', 'public/assets/community/editor.js']
        check(all(sh(['node', '--check', f]).returncode == 0 for f in js), 'Q:T-50 ⑪ node --check 바꾼 JS 넷')
        check(sh(['php', 'scripts/community_asset_version.php', '--check']).returncode == 0, 'Q:T-50 ⑪ 부품 버전 --check 0')
        check(sh(['bash', 'tests/community/asset_version.sh']).returncode == 0, 'Q:T-50 ⑪ asset_version.sh 통과')
        base_rev = sh(['git', 'merge-base', 'HEAD', 'main']).stdout.decode().strip()

        def added(path):
            d = sh(['git', 'diff', '-U0', base_rev, '--', path]).stdout.decode()
            return [ln[1:] for ln in d.splitlines() if ln.startswith('+') and not ln.startswith('+++')]
        bad_js = re.compile(r"innerHTML|outerHTML|insertAdjacentHTML|'Admin'|'Guest'|\.role\s*[=!]==|style=|/community/")
        hits = [ln.strip()[:80] for f in js for ln in added(f) if bad_js.search(ln) and not ln.lstrip().startswith(('//', '*', '/*'))]
        hits = [h for h in hits if '?v=' not in h]
        check(not hits, 'Q:T-50 ⑪ 바꾼 JS 새 줄에 innerHTML · 역할 비교 · style= · /community/ 0 %s' % json.dumps(hits[:3], ensure_ascii=False))
        css_new = [ln for ln in added('public/assets/app.css') if not ln.lstrip().startswith(('/*', '*'))]
        check(not [ln for ln in css_new if re.search(r'#[0-9a-fA-F]{3,8}\b|rgb\(|z-index:\s*\d|style=', ln)],
              'Q:T-50 ⑪ app.css 새 줄에 색값 · rgb( · 숫자 z-index 0')
        # 기준 판에 이미 있던 이름(기준 판의 모든 CSS 파일)은 새 클래스로 세지 않는다 — 다른 화면의 기존 클래스를 고르는 규칙은 새 클래스가 아니다
        css_files = [f for f in sh(['git', 'ls-tree', '-r', '--name-only', base_rev, 'public']).stdout.decode().splitlines() if f.endswith('.css')]
        old_cls = set()
        for f in css_files:
            old_cls |= set(re.findall(r'\.([a-z][\w-]*)', sh(['git', 'show', base_rev + ':' + f]).stdout.decode()))
        new_cls = {c for ln in css_new for c in re.findall(r'\.([a-z][\w-]*)', ln.split('{')[0])} - old_cls
        seven = {'menu--picker', 'menu__row', 'menu__scope', 'menu__look', 'menu__hint', 'menu__preview', 'menu__sheet-head'}
        check(new_cls <= seven, 'Q:T-50 ⑪ 새 클래스는 DESIGN 2.38 일곱 안 %s' % sorted(new_cls))
        yang = [ln for f in js for ln in added(f) if '양식' in ln]
        check(not yang, 'Q:T-50 ⑪ 바꾼 JS 새 줄에 「양식」 0 (WT-1 A)')
    except Exception as error:   # noqa: BLE001
        check(False, 'suite interrupted: ' + type(error).__name__ + ': ' + str(error).splitlines()[0][:200] if str(error) else 'suite interrupted: ' + type(error).__name__)
    finally:
        (out / 'qf16-templates-http-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
        fails = sum(not x['pass'] for x in checks)
        print('QF16_TEMPLATES_HTTP pass=%d fail=%d' % (sum(x['pass'] for x in checks), fails), flush=True)
    raise SystemExit(1 if fails else 0)
