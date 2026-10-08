#!/usr/bin/env python3
"""Compare the fixed baseline with the candidate using synthetic rows, not production data.
Run only through memories_harness.py. Local single-client timings are not a capacity claim.
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
import statistics
assert str(root).startswith('/tmp/qaredi-stage13-')
source=Path(__file__).resolve().parents[2]
model=root/'src/community/Memories.php';candidate=model.read_text()
baseline=subprocess.run(['git','show','663d50f:src/community/Memories.php'],cwd=source,capture_output=True,check=True).stdout.decode()
# Instrument only this disposable server copy. No query parameters are logged.
db=root/'src/Db.php';s=db.read_text();needle='        $st = $this->pdo()->prepare($sql);';assert needle in s
s=s.replace(needle,"        $GLOBALS['feedback_queries'][] = [$sql, $params];\n"+needle);db.write_text(s)
handler=root/'src/community/handlers/memories_api.php';s=handler.read_text();needle='    Http::json(Memories::feed($user)); exit;';assert needle in s
s=s.replace(needle,'''    $result = Memories::feed($user);
    $queries = $GLOBALS['feedback_queries'] ?? [];
    $result['_query_count'] = count($queries);
    $result['_batch_code'] = method_exists(Memories::class, 'outputMany');
    if (isset($_GET['explain'])) {
        foreach ($queries as [$statement, $values]) {
            if (str_contains($statement, 'SELECT p.*,u.username,u.display_name,')) {
                $plan = Db::instance()->run('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) ' . $statement, $values)->fetchColumn();
                $result['_plan'] = json_decode($plan, true); break;
            }
        }
    }
    Http::json($result); exit;''');handler.write_text(s)
rows=[]
try:
 for u in users:call('GET','/api/me/space',user=u)
 emp=int(users['employee']['id']);oth=int(users['other']['id'])
 sql(f'INSERT INTO community.memory_follows(follower_user_id,followed_user_id) VALUES({emp},{oth}) ON CONFLICT DO NOTHING')
 previous=0
 for size in ((1000000,) if os.environ.get('FEEDBACK_PERF_FINAL')=='1' else (10000,100000,1000000)):
  sql(f"SET statement_timeout='120s'; INSERT INTO community.memory_posts(id,owner_user_id,caption,original_caption,location,trip,taken_on,visibility,media_ids,original_media_ids,request_id,created_at,discoverable) SELECT md5('feedback-perf-'||g)::uuid,CASE WHEN g%2=0 THEN {emp} ELSE {oth} END,'합성 게시글 '||g,'원본 합성 '||g,'서울','여행 '||(g%100),DATE '2026-10-01','community','[]'::jsonb,'[]'::jsonb,md5('request-perf-'||g)::uuid,TIMESTAMPTZ '2026-10-01 12:00:00+00'-g*interval '1 second',true FROM generate_series({previous+1},{size}) g; ANALYZE community.memory_posts;")
  previous=size;print('SYNTHETIC_ROWS='+str(size),flush=True)
  versions=[('after',candidate)] if os.environ.get('FEEDBACK_PERF_FINAL')=='1' else [('before',baseline),('after',candidate)]
  for version,code in versions:
   model.write_text(code)
   first=raw('GET','/api/memories?include_stats=0').json()
   deep=raw('GET','/api/memories?include_stats=0&offset='+str(min(9900,size-100))).json()['next_cursor']
   cases={'feed':'/api/memories?include_stats=0','deep':'/api/memories?include_stats=0&cursor='+deep,'mine':'/api/memories?view=mine&include_stats=0','discover':'/api/memories?view=discover&include_stats=0','search':'/api/memories?view=mine&include_stats=0&q=%ED%95%A9%EC%84%B1'}
   if os.environ.get('FEEDBACK_PERF_FINAL')=='1': cases={'discover':cases['discover'],'discover_deep':'/api/memories?view=discover&include_stats=0&cursor='+raw('GET','/api/memories?view=discover&include_stats=0&offset=9900').json()['next_cursor'],'search_miss':'/api/memories?view=mine&include_stats=0&q=feedback-missing-term'}
   for name,path in cases.items():
    samples=[];counts=[]
    for _ in range(5):
     start=time.perf_counter();res=raw('GET',path);samples.append((time.perf_counter()-start)*1000)
     assert res.status_code==200,(name,res.status_code)
     data=res.json();assert data['_batch_code'] == (version=='after'), 'Code cache invalidates comparison';counts.append(data['_query_count']);assert len(data['posts'])==(0 if name=='search_miss' else 20)
    record={'rows':size,'version':version,'case':name,'samples':len(samples),'median_ms':round(statistics.median(samples),2),'max_ms':round(max(samples),2),'sql_calls':max(counts)}
    rows.append(record);print(json.dumps(record),flush=True)
    if size==1000000:
     res=raw('GET',path+'&explain=1');assert res.status_code==200
     (out/f'plan-{version}-{name}.json').write_text(json.dumps(res.json().get('_plan'),indent=2))
finally:
 model.write_text(candidate)
 (out/'measurements.json').write_text(json.dumps(rows,indent=2))
 print('Synthetic single-client benchmark; no 20-user or 285-million-row capacity claim.',flush=True)
