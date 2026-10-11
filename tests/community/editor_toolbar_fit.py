"""Actual PHP parent editor toolbar + production JS; synthetic API, no live writes."""
import hashlib
import json
import os
import re
import subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright

ROOT=Path(__file__).resolve().parents[2]
OUT=Path(os.environ.get('EDITOR_TOOLBAR_RESULTS','/tmp/community-editor-toolbar'))
OUT.mkdir(parents=True,exist_ok=True)
PID='00000000-0000-4000-8000-000000000001'
version=re.search(r'table-editor.js\?v=([a-f0-9]+)',(ROOT/'public/assets/community/editor.js').read_text()).group(1)
doc={'schema':1,'blocks':[{'id':'00000000-0000-4000-8000-000000000002','type':'paragraph','text':[{'t':'합성 도구줄 본문','m':{}}]},{'id':'00000000-0000-4000-8000-000000000003','type':'table','header_row':True,'header_col':True,'rows':[{'color':'yellow_bg','cells':[{'text':[{'t':'품목','m':{}}]},{'text':[{'t':'수량','m':{}}]}]},{'cells':[{'text':[{'t':'합성 품목','m':{}}]},{'text':[{'t':'12','m':{}}]}]}]}]}
markup=subprocess.check_output(['php','-r','$s=file_get_contents("src/community/handlers/page_view.php");$s=substr($s,strpos($s,"?>")+2);$s=substr($s,0,strrpos($s,"<?php"));$viewPageId="'+PID+'";eval("?>".$s);'],cwd=ROOT,text=True)
html='<!doctype html><html lang="ko" data-design="moa" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+''.join('<link rel="stylesheet" href="/tbox/assets/'+s+'">'for s in ['app.css','community/community.css','design.css','moa.css'])+'</head><body data-page="community-page" data-community-base="/community" data-base="/tbox"><div class="app"><nav class="app-rail"></nav><aside class="sidebar" hidden></aside><main id="main" class="main">'+markup+'</main></div><script type="module" src="/tbox/assets/community/editor.js?v='+version+'"></script></body></html>'
checks=[];errors=[];writes=[];heartbeats=[];observations=[]
def check(ok,name):
 assert ok,name
 checks.append(name);print('PASS '+name,flush=True)
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page(viewport={'width':390,'height':900})
 page.on('pageerror',lambda e:errors.append(str(e)))
 page.on('console',lambda m:errors.append(m.text)if m.type=='error'else None)
 def route(r):
  path=urlsplit(r.request.url).path
  if path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/path.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if path.startswith('/community/p/'):
   r.fulfill(body=html,content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"});return
  if path=='/community/api/pages/'+PID+'/heartbeat' and r.request.method=='POST':
   heartbeats.append(path);r.fulfill(json={'editors':[]});return
  if r.request.method not in ['GET','HEAD']:
   writes.append({'path':path,'method':r.request.method});(OUT/'intercepted-writes.json').write_text(json.dumps(writes,indent=2));r.fulfill(status=403,json={'error':'synthetic_write_blocked'});return
  if path=='/tbox/api/me':r.fulfill(json={'username':'synthetic','csrf_token':'synthetic-csrf','can_community_write':True});return
  if path=='/community/api/pages/'+PID:
   r.fulfill(json={'page':{'id':PID,'title':'합성 도구줄','doc':doc,'doc_version':1,'visibility':'private','owner':{'username':'synthetic'}},'can_edit':True,'can_manage':False,'can_comment':False,'backlinks':[]});return
  r.fulfill(json={'items':[],'pages':[],'spaces':[],'favorites':[],'recent':[],'palettes':[],'comments':[],'reads':[],'versions':[],'templates':[],'procedures':[],'integrations':[],'buttons':[]})
 page.route('**/*',route);page.goto('https://toolbar.example/community/p/'+PID,wait_until='networkidle');page.click('#page-edit');page.wait_for_selector('#page-editor .ProseMirror')
 def geometry(label):
  g=page.evaluate('''()=>{const m=document.querySelector('#main'),t=document.querySelector('.editor-toolbar'),p=document.querySelector('#page-editor'),row=t.querySelector('.editor-toolbar__row');const controls=[...document.querySelectorAll('.editor-toolbar__row button,.editor-toolbar__row select')];return {viewport:innerWidth,page:document.documentElement.scrollWidth,mainClient:m.clientWidth,mainScroll:m.scrollWidth,mainLeft:m.scrollLeft,toolbarClient:t.clientWidth,toolbarScroll:t.scrollWidth,rowClient:row.clientWidth,rowScroll:row.scrollWidth,paperClient:p.clientWidth,paperScroll:p.scrollWidth,controls:controls.map(n=>({id:n.id||n.className,left:n.getBoundingClientRect().left,right:n.getBoundingClientRect().right,visible:n.checkVisibility()}))};}''')
  observations.append({'state':label,**g})
  (OUT/'latest-geometry.json').write_text(json.dumps(observations,ensure_ascii=False,indent=2))
  check(g['page']<=g['viewport'] and g['mainScroll']<=g['mainClient']+1 and g['mainLeft']==0,'page/main fit '+label)
  check(all(c['visible'] and c['left']>=0 and c['right']<=g['viewport'] for c in g['controls']),'all toolbar controls remain visible '+label)
 quick=os.environ.get('EDITOR_TOOLBAR_QUICK')=='1'
 for design in (['moa'] if quick else ['moa','aurora','claude']):
  for theme in (['dark'] if quick else ['light','dark']):
   page.evaluate('v=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1]}',[design,theme])
   for width in ([390] if quick else [320,360,390,412,820,1280]):
    page.set_viewport_size({'width':width,'height':900});tag=f'{design}-{theme}-{width}'
    geometry('editing-'+tag)
    page.locator('#page-editor .ProseMirror p').first.click();page.keyboard.press('Home');page.keyboard.press('Shift+End')
    for tool in ['#tool-color','#tool-bg','#tool-link','#tool-mention']:
     page.locator(tool).click();geometry(tool+'-'+tag);page.keyboard.press('Escape')
    if design=='moa' and theme=='dark' and width in [390,1280]:page.locator('#inline-toolbar').screenshot(path=str(OUT/f'toolbar-{width}.png'))
 # Prove real keyboard movement through wrapped controls without sideways scrolling.
 page.set_viewport_size({'width':390,'height':900});page.locator('.editor-paper-mode__select').focus()
 for step in range(5):
  page.keyboard.press('Tab');check(page.evaluate('()=>{const b=document.activeElement.getBoundingClientRect();return document.querySelector("#inline-toolbar").contains(document.activeElement)&&b.left>=0&&b.right<=innerWidth&&document.querySelector("#main").scrollLeft===0;}'),'keyboard reaches wrapped control '+str(step))
 page.wait_for_timeout(550);page.locator('#page-editor [data-atom=table]').dblclick();page.wait_for_selector('#atom-dialog[open]');geometry('modal-390')
 page.locator('#atom-table-grid td[data-r="1"][data-c="0"] button').click();geometry('selected-cell-390')
 page.locator('.table-cell-input .ProseMirror').scroll_into_view_if_needed()
 check(page.locator('.table-cell-input .ProseMirror').evaluate('n=>{const b=n.getBoundingClientRect(),body=n.closest(".modal__body").getBoundingClientRect();return b.top>=body.top&&b.bottom<=body.bottom;}'),'cell input accessible through modal body vertical scroll')
 page.screenshot(path=str(OUT/'actual-modal-cell-visible-390.png'))
 page.locator('[data-atom-close]').first.click();geometry('modal-closed-390')
 check(not writes,'no document save or business writes')
 check(not errors,'no JavaScript or strict CSP errors')
 browser.close()
result={'passed':len(checks),'failed':0,'checks':checks,'observations':observations,'errors':errors,'writes':writes,'synthetic_presence_heartbeats':len(heartbeats),'css_sha256':hashlib.sha256((ROOT/'public/assets/community/community.css').read_bytes()).hexdigest()}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
print(str(len(checks))+' checks passed',flush=True)
