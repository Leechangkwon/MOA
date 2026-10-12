"""Empty synthetic DB actual HTTP checks; execute the affected common regression first."""
import runpy, json, os, subprocess, time
from urllib.parse import quote
from pathlib import Path
ctx=runpy.run_path('tests/stage89_cross_regression/suite.py')
globals().update({k:ctx[k] for k in ['call','api','sql','users','token','output','manifest','base','origin','root','ua']})
checks=[]
def check(ok,name,**ev):
 checks.append(dict(name=name,passed=bool(ok),**ev));print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
try:
 uid=users['employee']['id']
 board=int(sql("INSERT INTO community.spaces(kind,name,access_level,board_kind) VALUES('board','claude_scale_a','internal','discussion') RETURNING id" ).splitlines()[0])
 paths=['/community/api/search?q=','/tbox/api/files?q=',f'/community/api/boards/{board}/pages?q=']
 for path in paths:
  for term in ['회',' 회 ','회']:
   r=call('GET',path+quote(term));check(r.status_code==200 and r.json().get('search_status')=='short','minimum NFC/whitespace '+path,code=r.status_code)
  r=call('GET',path+quote('claude'));check(r.status_code==200 and 'search_status' not in r.json(),'normal query '+path,code=r.status_code)
 r=api('GET','/api/files?q=a',user=None,token=token());check(r.status_code==200 and r.json()==[] and r.headers.get('X-Search-Status')=='short','token array and short header')
 # Independent transaction holds only this synthetic user's advisory lock.
 env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(Path.home()/'.config/qaredi/pgpass-testdb')
 args=['psql','-X','-At','-h','127.0.0.1','-U','tbox_testdb','-w','-d',manifest['database'],'-v','ON_ERROR_STOP=1']
 def held(sqltext):
  p=subprocess.Popen(args,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,env=env)
  p.stdin.write('BEGIN; '+sqltext+"; SELECT 'READY';\n");p.stdin.flush()
  while p.stdout.readline().strip()!='READY':
   if p.poll() is not None:raise RuntimeError('synthetic lock failed')
  return p
 p=held(f"SELECT pg_advisory_xact_lock(hashtextextended('qaredi.search:{uid}',0))")
 try:
  for path in paths:
   t=time.monotonic();r=call('GET',path+'claude');elapsed=time.monotonic()-t
   check(r.status_code==429 and r.headers.get('Retry-After')=='1' and elapsed<1,'same user instant busy '+path,ms=round(elapsed*1000))
  r=call('GET',paths[0]+'claude',user='other');check(r.status_code==200,'other user independent')
 finally:p.communicate('ROLLBACK;\n',timeout=5)
 for path in paths:
  r=call('GET',path+'claude');check(r.status_code==200,'lock released '+path)
 # Relation lock proves the production handler turns an actual 57014 into its notice.
 p=held('LOCK TABLE community.pages IN ACCESS EXCLUSIVE MODE')
 try:
  t=time.monotonic();r=call('GET',paths[0]+'claude');elapsed=time.monotonic()-t
  check(r.status_code==200 and r.json().get('search_status')=='timeout' and 2.8<elapsed<4.2,'HTTP timeout notice within 3s',ms=round(elapsed*1000))
 finally:p.communicate('ROLLBACK;\n',timeout=5)
 check(call('GET',paths[0]+'claude').status_code==200,'normal search after timeout')
 p=held('LOCK TABLE public.files IN ACCESS EXCLUSIVE MODE')
 try:
  t=time.monotonic();r=api('GET','/api/files?q=claude',user=None,token=token());elapsed=time.monotonic()-t
  check(r.status_code==200 and r.json()==[] and r.headers.get('X-Search-Status')=='timeout' and 2.8<elapsed<4.2,'token HTTP timeout preserves array',ms=round(elapsed*1000))
 finally:p.communicate('ROLLBACK;\n',timeout=5)
 # Same deleted-parent/comment ordering before and after the concurrent index.
 created=call('POST',f'/community/api/boards/{board}/pages',body={'title':'claude_scale_comments'})
 check(created.status_code==201,'synthetic board post created',code=created.status_code)
 pid=created.json()['page']['id']
 key=int(sql("SELECT id FROM community.pages WHERE public_id='"+pid+"'"))
 parent=int(sql(f"INSERT INTO community.comments(page_id,body,author_id,deleted_at,deleted_by,created_at) VALUES({key},'removed',{uid},now(),{uid},now()-interval '1 minute') RETURNING id").splitlines()[0])
 sql(f"INSERT INTO community.comments(page_id,parent_comment_id,body,author_id) VALUES({key},{parent},'child',{uid})")
 before=call('GET',f'/community/api/pages/{pid}/comments').json()
 sql('CREATE INDEX CONCURRENTLY comments_page_created_all_idx ON community.comments(page_id,created_at,id)')
 after=call('GET',f'/community/api/pages/{pid}/comments').json()
 check(before==after and len(after['comments'])==2 and after['comments'][0]['deleted'] and after['comments'][1]['body']=='child','comments index preserves deleted parent, ordering and response')

finally:
 (output/'scale-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2)+'\n')
 print('SCALE_PASS='+str(sum(c['passed'] for c in checks)))
