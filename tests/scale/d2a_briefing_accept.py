#!/usr/bin/env python3
"""D2a A8 좁은 보정 수락: 새 합성 M 한 개, 브리핑 7회 한 번, 그 뒤 같은 DB에서 baseline과 24명 응답 비교.

운영 설정 fallback을 거부한다. d1_measure의 seed/guard/측정/계획/정리 및 기존 예산 판정을 재사용한다.
추가 fixture는 성능 측정 뒤에만 넣으며 개인 공간 숨김/폼/삭제와 비개인 공간 권한, 익명 글을 명시적으로 확인한다.
"""
import argparse
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tarfile
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
ROUTE = 'GET /community/api/briefing'
UIDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 20, 21, 27, 30, 40, 57, 100, 101, 155]


def module(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'tests/scale' / (name + '.py'))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


eqmod = module('d2a_equivalence')
d1 = eqmod.d1
budget = module('d2a_budget')


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def dump(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


class TimingClient(d1.Client):
    """원래 Client의 결과를 그대로 반환하면서 직결 원시 시간을 보존한다."""
    def __init__(self, port, sessions):
        super().__init__(port, sessions)
        self.samples = []

    def get(self, path, user):
        response, elapsed = super().get(path, user)
        self.samples.append(elapsed)
        return response, elapsed


def fixture_sql():
    users = ','.join(map(str, UIDS))
    return f"""
DO $$ BEGIN IF current_database() NOT LIKE 'tbox\\_scale\\_d\\_%' THEN RAISE EXCEPTION 'synthetic guard'; END IF; END $$;
UPDATE public.users SET role='Guest',team_id=NULL WHERE id IN (7,27);
UPDATE public.users SET is_active=false WHERE id=57;
-- 소유자와 글쓴이를 달리해 MINE 첫 갈래를 실제로 거친다. 최근 고유 시각으로 LIMIT 탈락/정렬 동률을 피한다.
INSERT INTO community.pages(public_id,space_id,title,doc,author_id,last_edited_by,status,due_date,created_at,updated_at,
 hidden_at,hidden_by,form_view_id,form_anonymous,deleted_at,deleted_by,visibility,published_from,published_board_id,published_at,published_by,
 verified_at,verified_by,verify_expires_at)
SELECT md5('brief-own-'||u||'-'||k)::uuid,u,'brief fixture '||u||'-'||k,
 jsonb_build_object('schema',1,'blocks',jsonb_build_array(jsonb_build_object('id',md5('brief-todo-'||u||'-'||k)::uuid,
 'type','to_do','checked',false,'text',jsonb_build_array(jsonb_build_object('t','합성 할 일','m','{{}}'::jsonb))))),
 CASE WHEN u=1 THEN 2 ELSE 1 END,u,'review',(now() AT TIME ZONE 'Asia/Seoul')::date-(k%3),
 now()-interval '1 hour',now()-k*interval '1 second',
 CASE WHEN k IN (1,4) THEN now() END,CASE WHEN k IN (1,4) THEN 1 END,
 CASE WHEN k IN (2,3,5) THEN 'v1234abcd' END,CASE WHEN k IN (2,3,5) THEN false END,
 CASE WHEN k=5 THEN now() END,CASE WHEN k=5 THEN u END,
 CASE WHEN k=4 THEN 'published' ELSE 'private' END,CASE WHEN k=4 THEN 'private' END,
 CASE WHEN k=4 THEN 1034 END,CASE WHEN k=4 THEN now() END,CASE WHEN k=4 THEN u END,
 now()-interval '2 days',u,now()-interval '1 day'
FROM unnest(ARRAY[{users}]) u,generate_series(1,6) k;
-- 폼 직접 공유가 있는 소유자 페이지도 같은 결과여야 한다.
INSERT INTO community.page_shares(page_id,user_id,level,granted_by)
SELECT id,CASE WHEN space_id=4 THEN 6 ELSE 4 END,'view',space_id FROM community.pages
 WHERE public_id=md5('brief-own-'||space_id||'-3')::uuid;
INSERT INTO community.automations(public_id,owner_user_id,kind,name,scope_space_id,trigger,actions)
VALUES(md5('brief-rule')::uuid,2,'recurring','합성 반복',2,'{{}}','[{{}}]');
INSERT INTO community.automation_runs(public_id,automation_id,run_by,trigger_kind,dry_run,status,steps,created_page_ids)
SELECT md5('brief-run')::uuid,a.id,2,'recurring',false,'ok','[]',
 ARRAY(SELECT p.id FROM community.pages p WHERE p.title LIKE 'brief fixture %')
FROM community.automations a WHERE a.public_id=md5('brief-rule')::uuid;
-- 비개인 갈래: 직원2가 쓴 자기 팀 폼은 보인다. 다른 팀 폼은 직접 공유가 있을 때만 보인다.
INSERT INTO community.pages(public_id,space_id,title,doc,author_id,last_edited_by,status,form_view_id,form_anonymous,created_at,updated_at)
SELECT md5('brief-team-form-'||k)::uuid,CASE WHEN k IN (3,4) THEN 1002 ELSE 1001 END,'brief team form '||k,
 '{{"schema":1,"blocks":[]}}',2,2,'review','v1234abcd',false,now()-interval '1 hour',now()-k*interval '1 second'
FROM generate_series(1,4) k;
INSERT INTO community.page_shares(page_id,user_id,level,granted_by)
SELECT id,2,'view',1 FROM community.pages WHERE public_id IN (md5('brief-team-form-2')::uuid,md5('brief-team-form-4')::uuid);
-- 검색에서 관리자라는 이유만으로 다른 팀의 폼을 볼 수 없다. 공지 hidden도 원래 조건을 유지한다.
INSERT INTO community.pages(public_id,space_id,title,doc,author_id,last_edited_by,status,hidden_at,hidden_by,created_at,updated_at)
VALUES(md5('brief-anonymous')::uuid,1034,'brief anonymous fixture','{{"schema":1,"blocks":[]}}',2,2,'review',NULL,NULL,now(),now()),
 (md5('brief-notice-hidden')::uuid,1032,'brief hidden notice fixture','{{"schema":1,"blocks":[]}}',155,155,NULL,now(),1,now(),now());
ANALYZE public.users,community.pages,community.page_shares,community.automations,community.automation_runs;
"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--output', type=Path, required=True)
    ap.add_argument('--base', default='63aba86')
    ap.add_argument('--port', type=int, default=8894)
    ap.add_argument('--proxy-port', type=int, default=8896)
    args = ap.parse_args()
    out = args.output.resolve()
    out.mkdir(parents=True, exist_ok=True)
    if (out / 'result.json').exists() or str(out).startswith(str(ROOT)):
        raise SystemExit('refusing existing result or repository output')
    conn = os.environ.get('QCHECK_ENV_SRC')
    if not conn or Path(conn).resolve().is_relative_to('/var/www'):
        raise SystemExit('explicit isolated connection source required; production fallback refused')
    if d1.free_gb() < 3:
        raise SystemExit('free space below 3GB floor')
    result = dict(started=time.strftime('%Y-%m-%dT%H:%M:%S%z'), checks=[], limits=dict(floor_gb=3,cap_gb=3,reps=7,p95_ms=300,queries=37),
                  scope='briefing only; one unchanged M measurement, then same-DB semantic fixtures', operations=dict(production=0,full_suite_reruns=0))
    def check(ok, name, **evidence):
        result['checks'].append(dict(name=name,passed=bool(ok),**evidence))
        print(('PASS ' if ok else 'FAIL ')+name,flush=True)
    def guard():
        free = d1.free_gb()
        size = int(h.pg.sql('SELECT pg_database_size(current_database())',timeout=30))/1024**3
        result['min_free_gb'] = min(result.get('min_free_gb',free),free)
        result['max_db_gb'] = max(result.get('max_db_gb',size),size)
        if free < 3 or size > 3:
            raise RuntimeError('synthetic size/free-space guard')
    originals = {}
    for dirname in ('budget-final','accept-final','counter-final','synthetic-upgrade'):
        for p in (out.parent/dirname).rglob('*'):
            if p.is_file(): originals[str(p)]=sha(p)
    source_before = json.loads((out/'source-before.json').read_text())
    sources = {f:sha(ROOT/f) for f in source_before}
    sources['tests/scale/d2a_briefing_accept.py']=sha(Path(__file__))
    dump(out/'source-tested.json',sources)
    h = d1.Harness(args.port,args.proxy_port)
    e = None
    result['database'] = dict(name=h.db,work=str(h.work))
    try:
        print('SETUP new synthetic database',flush=True)
        h.setup_db()
        result['database']['owner']=h.pg.sql("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname=current_database()")
        check(result['database']['owner']=='tbox_testdb','new DB owned by tbox_testdb')
        result['unlogged']=d1.make_unlogged(h.pg).splitlines()[-1]
        h.write_instances()
        consents={d:subprocess.check_output(['php','-r',"require 'src/Legal.php'; echo Legal::current($argv[1]);",d],cwd=h.work/'direct',text=True).strip()
                  for d in ('terms','privacy','age14','ai_overseas')}
        targets=dict(d1.TIERS['M'])
        print('SEED unchanged M targets',flush=True)
        seed=d1.seed(h.pg,targets,consents,3,3)
        result['seed']=seed
        guard()
        check(all(sha(h.work/'direct'/f)==v for f,v in sources.items()),'candidate copied source matches tested SHA')
        sessions=d1.make_sessions(h.pg)
        h.secrets+=list(sessions.values())
        h.start()
        print('MEASURE one briefing run: warmup + original seven repetitions + all plans',flush=True)
        clients=[TimingClient(h.port,sessions),d1.Client(h.port+1,sessions)]
        load0=os.getloadavg()
        measured=d1.measure_endpoint(ROUTE,d1.SPEC[ROUTE],{},clients[0],clients[1],h.proxy,7,all_plans=True)
        measured['ms_raw']=clients[0].samples[1:]  # 첫 호출은 기존 measure_endpoint의 데우기
        plans=[]
        (out/'plans').mkdir(exist_ok=True)
        for i,s in enumerate(d1.plan_candidates(measured,'M',True),1):
            guard()
            plan=d1.explain(h.pg,s)
            record=dict(sql=s['sql'],params=s['params'],sets=s.get('sets',[]),plan=plan)
            dump(out/'plans'/('M-GET_community_api_briefing-%d.json'%i),record)
            plans.append(dict(sql=s['sql'],params=s['params'],sets=s.get('sets',[]),ms=s['ms'],deep=False,gist=d1.plan_gist(plan)))
        measured['plans']=plans
        tier=dict(targets=targets,seed=seed,results=[measured],loadavg=[load0,os.getloadavg()])
        dump(out/'tier-M.json',tier)
        # 기존 판정기를 같은 상한으로 브리핑에만 적용한다. 전체 M을 다시 실행하지 않는다.
        original_select=budget.SELECT
        budget.SELECT={ROUTE:original_select[ROUTE]}
        try: checks=budget.evaluate_results(tier)
        finally: budget.SELECT=original_select
        result['checks']+=checks
        dump(out/'budget.json',checks)
        check(len(measured['ms'])==len(measured['ms_raw'])==7 and len(plans)==len(d1.plan_candidates(measured,'M',True)),
              'seven raw samples and complete unique read plans')
        result['measurement']=dict(tier_file=str(out/'tier-M.json'),sha256=sha(out/'tier-M.json'),p50=measured['p50'],p95=measured['p95'],queries=measured['queries'],plans=len(plans))
        print('MEASURED '+json.dumps(result['measurement']),flush=True)
        for c in clients: c.http.close()
        h.stop()
        guard()
        print('SEMANTICS add fixtures after measurement',flush=True)
        fixture=fixture_sql()
        (out/'fixture.sql').write_text(fixture)
        h.pg.sql(fixture,timeout=120)
        old=h.work/'proxied'
        archive=subprocess.check_output(['git','-C',str(ROOT),'archive',args.base,'src','public'])
        for dirname in ('src','public'): shutil.rmtree(old/dirname)
        with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
            for member in tar.getmembers():
                target=(old/member.name).resolve()
                if old.resolve() not in target.parents or not (member.isdir() or member.isfile()):
                    raise RuntimeError('baseline archive guard')
                if member.isdir(): target.mkdir(parents=True,exist_ok=True)
                else:
                    target.parent.mkdir(parents=True,exist_ok=True)
                    target.write_bytes(tar.extractfile(member).read())
        cfg=dict(h.config,APP_URL='http://127.0.0.1:%d'%(args.port+1),DB_HOST='127.0.0.1',DB_PORT=h.config['DB_PORT'])
        d1.private_write(old/'.env',''.join('%s=%s\n'%kv for kv in cfg.items()))
        base_revision=subprocess.check_output(['git','-C',str(ROOT),'rev-parse',args.base],text=True).strip()
        e=eqmod.Equiv.__new__(eqmod.Equiv)
        e.h,e.args,e.servers,e.checks=h,args,[],[]
        e.pairs=dict(total=0,same=0,by_route={})
        e.diffs,e.server_errors=[],[]
        e.http=eqmod.requests.Session()
        e.http.trust_env=False
        e.tokens=e.sessions(UIDS)
        people=[dict(id=int(a),role=b,active=c=='t') for a,b,c in (line.split('|') for line in h.pg.sql(
            'SELECT id,role,is_active FROM public.users WHERE id IN (%s) ORDER BY id'%','.join(map(str,UIDS))).splitlines())]
        e.start()
        response_pairs=[]
        seen_anon=0
        violations=[]
        own_checks=[]
        items_by_user={}
        (out/'responses').mkdir(exist_ok=True)
        for person in people:
            guard()
            uid=person['id']
            sn,new,so,oldbody=e.compare(uid,'briefing','/community/api/briefing','briefing')
            dump(out/'responses'/('%d.json'%uid),dict(uid=uid,old_status=so,new_status=sn,old=oldbody,new=new))
            response_pairs.append(dict(uid=uid,active=person['active'],status=sn,same=so==sn and eqmod.Equiv.expected(oldbody,'briefing')==new))
            items=[item for key in ('top','missed','others') for item in new.get(key,[])] if isinstance(new,dict) else []
            items_by_user[uid]=items
            if person['active'] and sn==200:
                by_id={item.get('page_id'):item for item in items}
                pid=lambda k:str(__import__('uuid').UUID(hashlib.md5(('brief-own-%d-%d'%(uid,k)).encode()).hexdigest()))
                own_checks.append(dict(uid=uid,present=all(pid(k) in by_id for k in (1,2,3,4,6)),deleted_absent=pid(5) not in by_id))
                raw=json.dumps(new,ensure_ascii=False)
                if any(key in raw for key in ('"username"','"author','"writer','"actor')): violations.append(uid)
                seen_anon+=sum(item.get('title')=='brief anonymous fixture' for item in items)
            print('COMPARE uid=%d status=%d same=%s'%(uid,sn,response_pairs[-1]['same']),flush=True)
        check(len(people)==24 and sum(p['active'] for p in people)==23,'24 people: 23 active and one inactive')
        check(e.pairs['same']==24 and not e.diffs and not e.server_errors,'baseline63aba86 full briefing response equality except generated_at',pairs=e.pairs)
        check(all(p['status']==(200 if p['active'] else 401) for p in response_pairs),'active success and inactive denial preserved')
        check(len(own_checks)==23 and all(c['present'] and c['deleted_absent'] for c in own_checks),
              'all active owners retain hidden/form/shared-form/published-hidden/private and exclude deleted',cases=own_checks)
        check(seen_anon>0 and not violations,'anonymous board fixture reached and no author identity fields',seen=seen_anon,violations=violations)
        own2=items_by_user[2]
        titles={i.get('title') for i in own2}
        check({'brief team form 1','brief team form 2','brief team form 4'}<=titles and 'brief team form 3' not in titles,
              'nonpersonal own-team form and direct-share remain visible; other-team unshared form excluded')
        check('brief hidden notice fixture' not in titles,'other author hidden notice still excluded')
        reasons={r['kind'] for i in own2 if str(i.get('title','')).startswith('brief fixture 2-') for r in i.get('reasons',[])}
        check({'overdue','due_today','review','verify_expired','recurring','unchecked'}<=reasons,'owned-personal categories exercised',reasons=sorted(reasons))
        result['equivalence']=dict(base=base_revision,people=people,pairs=e.pairs,response_pairs=response_pairs,differences=e.diffs,server_errors=e.server_errors)
    except BaseException as exc:
        check(False,'run completed',error=h.scrub(type(exc).__name__+': '+str(exc))[-1500:])
    finally:
        cleanup_errors=[]
        if e:
            try: e.stop(); e.http.close()
            except Exception as exc: cleanup_errors.append(type(exc).__name__)
        try: h.teardown()
        except Exception as exc: cleanup_errors.append(type(exc).__name__)
        dropped=False
        try: dropped=d1.Pg('postgres').sql("SELECT count(*) FROM pg_database WHERE datname='%s'"%h.db,timeout=30)=='0'
        except Exception as exc: cleanup_errors.append(type(exc).__name__)
        ports_closed=True
        for port in (args.port,args.port+1,args.proxy_port):
            with socket.socket() as sock: ports_closed &= sock.connect_ex(('127.0.0.1',port))!=0
        result['cleanup']=dict(db=h.db,owner=result['database'].get('owner'),dropped=dropped,work_removed=not h.work.exists(),ports_closed=ports_closed,errors=cleanup_errors)
        check(dropped and not h.work.exists() and ports_closed and not cleanup_errors,'owned DB/processes/work directory cleaned')
        after={f:sha(ROOT/f) if (ROOT/f).is_file() else None for f in sources}
        source_changes=[f for f,v in source_before.items() if after[f]!=v]
        evidence_changed=[f for f,v in originals.items() if not Path(f).is_file() or sha(Path(f))!=v]
        check(source_changes==['src/community/Briefing.php'],'only permitted production source changed',changed=source_changes)
        check(sources==after,'tested source unchanged through acceptance')
        check(not evidence_changed,'all prior evidence files unchanged',changed=evidence_changed)
        result['source_binding']=dict(before=source_before,after=after,changed_existing=source_changes,new_test='tests/scale/d2a_briefing_accept.py',
            unchanged_evidence=originals,evidence_changed=evidence_changed)
        result['finished']=time.strftime('%Y-%m-%dT%H:%M:%S%z')
        result['pass']=sum(c['passed'] for c in result['checks'])
        result['fail']=len(result['checks'])-result['pass']
        dump(out/'result.json',result)
        print('BRIEFING_ACCEPT pass=%d fail=%d cleanup=%s'%(result['pass'],result['fail'],json.dumps(result['cleanup'])),flush=True)
    return 1 if result['fail'] else 0


if __name__=='__main__':
    raise SystemExit(main())
