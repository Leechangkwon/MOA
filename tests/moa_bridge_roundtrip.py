"""Real Chromium -> JS -> loopback HTTP; synthetic PC approval/provider only.
BUDDY_SOURCE must point to the candidate desktop source. No real OAuth or inference.
"""
import os,sys,json
from pathlib import Path
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1]
sys.path[:0]=[os.environ['BUDDY_SOURCE'],os.environ['BUDDY_SOURCE']+'/tests']
from tbox.moa_bridge import Bridge
from tbox.moa_trust import BrowserTrust
from memory_store import MemoryStore
store=MemoryStore();store.data.update(active='fixture',profiles={'fixture':{'tokens':{'access_token':'never-exported'}}})
accepted=[];jobs=[]
def approve(rid,origin,generation):
 accepted.append(origin);bridge.approve_pair(rid,True,generation)
def process(rid,payload,generation):
 jobs.append(payload);bridge.begin_job(rid,'synthetic-model',generation);bridge.finish(rid,'합성 응답',generation=generation)
bridge=Bridge(approve,process,trust=BrowserTrust(store));bridge.start()
checks=[]
def check(name,ok):
 checks.append({'name':name,'passed':bool(ok)});print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
try:
 with sync_playwright() as p:
  browser=p.chromium.launch(headless=True)
  ctx=browser.new_context();page=ctx.new_page()
  # Grant Chromium's local-network permission; this does not test the real Windows permission prompt.
  ctx.grant_permissions(['local-network-access'],origin='https://changkwon.mso.so')
  source=(ROOT/'public/assets/moa-local-bridge.js').read_text()
  ctx.route('https://changkwon.mso.so/**',lambda route:route.fulfill(content_type='text/html',body='<title>synthetic</title>'))
  page.goto('https://changkwon.mso.so/tbox/messages');page.add_script_tag(content=source)
  page.evaluate("window.b=MoaLocalBridge('1001')")
  check('no request or generation before action',not accepted and not jobs)
  page.evaluate('b.pair()');check('first approval and memory session',len(accepted)==1 and page.evaluate('b.connected()'))
  r=page.evaluate("b.run({action:'polish',text:'원문',target_language:null})")
  check('actual HTTP job and response',r['text']=='합성 응답' and len(jobs)==1)
  page.reload();page.add_script_tag(content=source);page.evaluate("window.b=MoaLocalBridge('1001')")
  page.evaluate('b.resume()');check('reload resumes without another approval or generation',len(accepted)==1 and len(jobs)==1)
  other=ctx.new_page();other.goto('https://changkwon.mso.so/tbox/messages');other.add_script_tag(content=source)
  check('other Moa account cannot discover grant',not other.evaluate("MoaLocalBridge('1002').remembered()"))
  page.evaluate('b.disconnect()');check('revocation removes PC trust and browser grant',not store.snapshot().get('moa_browser') and not page.evaluate('b.remembered()'))
  # Actual Origin guard on unauthenticated v2 entry.
  import requests
  r=requests.post('http://127.0.0.1:43821/v2/connect',json={'request_id':'a','user':'1001'},headers={'Origin':'https://evil.example'},timeout=3)
  check('foreign origin denied with no CORS disclosure',r.status_code==403 and 'Access-Control-Allow-Origin' not in r.headers)
  browser.close()
finally:
 bridge.stop()
 out=Path(os.environ.get('MOA_TEST_ARTIFACTS','/tmp/moa-roundtrip'));out.mkdir(parents=True,exist_ok=True)
 (out/'checks.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
