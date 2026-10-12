"""합성 DB의 실제 HTTP와 실제 게시판 화면. 운영 데이터/계정 사용 금지."""
import runpy, json, time
from urllib.parse import urlencode
from playwright.sync_api import sync_playwright, expect
ctx=runpy.run_path('tests/scale/acceptance.py')
globals().update({k:ctx[k] for k in ['call','sql','users','output','origin','ua']})
checks=[]
def check(ok,name,**ev):
 checks.append(dict(name=name,passed=bool(ok),**ev));print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
try:
 uid=users['employee']['id']
 board=int(sql("INSERT INTO community.spaces(kind,name,access_level,board_kind) VALUES('board','커서 시험 게시판','internal','discussion') RETURNING id").splitlines()[0])
 sql(f"INSERT INTO community.pages(space_id,title,doc,author_id,last_edited_by,created_at,updated_at) SELECT {board}, '게시판 시험 글 '||n,'{{}}'::jsonb,{uid},{uid},'2026-01-01'::timestamptz+(n/3)*interval '1 minute','2026-01-01'::timestamptz+(n%7)*interval '1 minute' FROM generate_series(1,45)n")
 sql(f"INSERT INTO community.page_tags(page_id,tag,tag_norm,created_by) SELECT id,'시험','시험',{uid} FROM community.pages WHERE space_id={board} AND id%2=0")
 path=f'/community/api/boards/{board}/pages'
 def get(**params):
  r=call('GET',path+'?'+urlencode(params));check(r.status_code==200,'board HTTP',status=r.status_code);return r.json()
 first={}
 for sort,order in [('new','created_at DESC,id DESC'),('updated','updated_at DESC,id DESC'),('views','view_count DESC,created_at DESC,id DESC')]:
  expected=sql(f'SELECT public_id FROM community.pages WHERE space_id={board} ORDER BY {order}').splitlines()
  a=get(sort=sort);first[sort]=a;seen=[p['id'] for p in a['pages']];b=get(sort=sort,cursor=a['next_cursor']);seen.extend(p['id'] for p in b['pages']);c=get(sort=sort,cursor=b['next_cursor']);seen.extend(p['id'] for p in c['pages'])
  check(seen==expected and len(set(seen))==45,'all pages stable order '+sort)
  back=get(sort=sort,cursor=b['prev_cursor']);check([p['id'] for p in back['pages']]==expected[:20],'previous restores first '+sort)
  check(a['prev_cursor'] is None and c['next_cursor'] is None and not any(k in a for k in ['total','page']),'boundary and no totals '+sort)
  check(get(sort=sort,page=2)['pages']==b['pages'],'legacy page2 same '+sort)
  check(get(sort=sort,page=20)['pages']==[],'legacy page20 bounded '+sort)
 for query in ['page=21','page=100000','page=-1','page[]=2','cursor=x','cursor[]=x','cursor='+('a'*800),urlencode({'sort':'updated','cursor':first['new']['next_cursor']}),urlencode({'tag':'시험','cursor':first['new']['next_cursor']}),urlencode({'q':'게시판','cursor':first['new']['next_cursor']}),urlencode({'page':1,'cursor':first['new']['next_cursor']})]:
  r=call('GET',path+'?'+query);check(r.status_code==400,'bad position rejected',status=r.status_code)
 a=get(q='게시판',tag='시험');check(all('시험' in p['tags'] for p in a['pages']),'query and tag intersect')
 anchor=first['new']['pages'][-1]['id'];sql("UPDATE community.pages SET deleted_at=now() WHERE public_id='"+anchor+"'")
 a=get(cursor=first['new']['next_cursor']);check(len(a['pages'])==20 and all(p['id']!=anchor for p in a['pages']),'deleted anchor still navigates')
 pid=first['new']['pages'][0]['id'];key=int(sql("SELECT id FROM community.pages WHERE public_id='"+pid+"'"))
 for who in ['employee','employee','other']:
  r=call('GET','/community/api/pages/'+pid,user=who);check(r.status_code==200,'actual page read '+who)
 counts=sql(f'SELECT view_count,(SELECT count(*) FROM community.page_views WHERE page_id={key}) FROM community.pages WHERE id={key}')
 check(counts=='2|2','daily dedup and second user count',counts=counts)
 with sync_playwright() as pw:
  browser=pw.chromium.launch()
  context=browser.new_context(viewport={'width':390,'height':844},user_agent=ua)
  context.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True,'sameSite':'Lax'}])
  page=context.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  response=page.goto(origin+'/community/b/'+str(board));check(response.status==200,'real board page')
  expect(page.locator('#board-body tr')).to_have_count(20)
  firstTitle=page.locator('#board-body tr').first.inner_text()
  for theme in ['light','dark']:
   page.evaluate('(theme)=>document.documentElement.setAttribute("data-theme",theme)',theme)
   with page.expect_response(lambda r:'/api/boards/' in r.url and '/pages?' in r.url):page.locator('#board-next').click()
   expect(page.locator('#board-body tr')).to_have_count(20)
   check(page.locator('#board-body tr').first.inner_text()!=firstTitle,'browser next '+theme)
   page.screenshot(path=str(output/('board-390-'+theme+'.png')),full_page=True)
   with page.expect_response(lambda r:'/api/boards/' in r.url and '/pages?' in r.url):page.locator('#board-prev').click()
   expect(page.locator('#board-prev')).to_be_disabled()
   check(page.locator('#board-body tr').first.inner_text()==firstTitle,'browser previous '+theme)
  with page.expect_response(lambda r:'/api/boards/' in r.url and '/pages?' in r.url):page.select_option('#board-sort','views')
  expect(page.locator('#board-prev')).to_be_disabled()
  with page.expect_response(lambda r:'/api/boards/' in r.url and '/pages?' in r.url):page.fill('#board-search','시험 글 4')
  expect(page.locator('#board-body tr')).to_have_count(7)
  check(page.locator('#board-count').count()==0,'browser total removed')
  page.set_viewport_size({'width':1280,'height':900});page.screenshot(path=str(output/'board-desktop.png'),full_page=True)
  check(not errors,'no browser errors',errors=errors);browser.close()
finally:
 (output/'board-results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2)+'\n')
 print('BOARD_PASS='+str(sum(c['passed'] for c in checks)))
