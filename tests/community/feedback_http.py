#!/usr/bin/env python3
"""FB-3/6: real PHP/PostgreSQL, only the harness-owned synthetic database."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
try:
 for u in users: call('GET','/api/me/space',user=u)
 media=upload();pid=save(dict(payload(),media_ids=[media['id']],visibility='community'))
 # Same timestamp deliberately exercises the UUID tie breaker, including 100+ comments.
 sql("INSERT INTO community.memory_comments(id,post_id,user_id,body,created_at) SELECT md5('feedback-comment-'||g)::uuid,'"+pid+"',"+str(users['employee']['id'])+",'comment-'||g,'2026-10-01 12:00:00+00' FROM generate_series(1,105) g")
 call('POST','/api/memories/'+pid+'/like',{},user='other')
 page=call('GET','/api/memories?view=mine');detail=call('GET','/api/memories/'+pid)['post']
 check(page['posts'][0]==detail,'batch output equals detail including originals, media order, counts and liked state')
 othername=sql('SELECT username FROM users WHERE id='+str(users['employee']['id']))
 profile=call('GET','/api/memories?profile='+othername,user='other')['posts'][0]
 check('original_media' not in profile and profile['comments']==105 and profile['liked'],'batch excludes originals for nonowner and keeps viewer like')
 hidden=save();check(all(p['id']!=hidden for p in call('GET','/api/memories?profile='+othername,user='other')['posts']),'batch never widens visible post set')
 check(call('GET','/api/memories?include_stats=0')['stats'] is None,'explicit stats omission')
 call('GET','/api/memories?include_stats[]=1',expected=400)
 ids=[];cursor=None
 for number in range(4):
  result=call('GET','/api/memories/'+pid+'/comments'+('?before='+cursor if cursor else ''))
  check(len(result['comments'])<=30,'comment page bounded '+str(number));ids += [c['id'] for c in result['comments']];cursor=result['next_cursor']
 check(len(ids)==105 and len(set(ids))==105 and cursor is None,'105 equal-time comments traversed once without omission')
 first=call('GET','/api/memories/'+pid+'/comments')
 call('GET','/api/memories/'+hidden+'/comments?before='+first['next_cursor'],expected=400)
 call('GET','/api/memories/'+pid+'/comments?before[]=x',expected=400)
 call('GET','/api/memories/'+hidden+'/comments',user='other',expected=404)
 call('POST','/api/memories/'+pid+'/comments',{'body':'guest'},user='guest',expected=403)
 sql("UPDATE community.memory_comments SET created_at=created_at-interval '1 day' WHERE post_id='"+pid+"'")
 sent=call('POST','/api/memories/'+pid+'/comments',{'body':'new comment'})
 check(sent['comments'][-1]['body']=='new comment' and sent['next_cursor'],'newest comment returned with previous-page cursor')
 h={'Cookie':'__Host-tbox_session='+users['guest']['session'],'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d')}
 direct=requests.get(site+'/tbox/messages',headers=h)
 check(direct.status_code==403 and '메신저 이용 안내' in direct.text and 'id="message-text"' not in direct.text,'direct denied URL has HTML guidance without messenger data')
 api=requests.get(site+'/tbox/api/messages',headers=h)
 check(api.status_code==403,'messenger API authorization unchanged')
 check(sql("SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relname IN ('memory_comments_post_cursor','memory_comments_author_rate','memory_posts_owner_cursor') AND i.indisvalid")=='3','all new indexes valid after repeated migration')
 # Exact ranking comparison against an unbounded reference query, multiple authors/times.
 sql("INSERT INTO community.memory_posts(id,owner_user_id,caption,original_caption,taken_on,visibility,request_id,created_at,discoverable) SELECT md5('rank-test-'||g)::uuid,CASE WHEN g%2=0 THEN "+str(users['employee']['id'])+" ELSE "+str(users['other']['id'])+" END,'rank-fixture-'||g,'rank-original',DATE '2026-10-01','community',md5('rank-request-'||g)::uuid,TIMESTAMPTZ '2026-10-01 12:00:00+00'-(g/3)*interval '1 hour',true FROM generate_series(1,240) g")
 ranked=call('GET','/api/memories?view=discover&include_stats=0',user='manager')
 meta=json.loads(base64.urlsafe_b64decode(ranked['next_cursor']+'==='));day=meta['snapshot'][:10];viewer=str(users['manager']['id'])
 reference=sql("SELECT p.id FROM community.memory_posts p WHERE discoverable AND deleted_at IS NULL ORDER BY floor(extract(epoch FROM p.created_at))::bigint + ((('x'||substr(md5('"+viewer+"'||':'||p.owner_user_id::text||':'||'"+day+"'),1,8))::bit(32)::bigint)%21600) DESC,p.created_at DESC,p.id DESC").splitlines()
 actual=[p['id'] for p in ranked['posts']]
 while ranked['next_cursor']:
  ranked=call('GET','/api/memories?view=discover&include_stats=0&cursor='+ranked['next_cursor'],user='manager');actual += [p['id'] for p in ranked['posts']]
 check(actual==reference,'bounded discovery exactly matches full ranking across 240 posts, authors and timestamp ties')
 legacy=call('GET','/api/memories?view=discover&include_stats=0&offset=60',user='manager')
 check([p['id'] for p in legacy['posts']]==reference[60:80],'bounded ranking also preserves legacy offset ordering')
finally:
 (out/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
 print('RESULT pass='+str(sum(c['pass'] for c in checks))+' fail='+str(sum(not c['pass'] for c in checks)))
