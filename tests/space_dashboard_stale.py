"""Delayed synthetic responses must not restore another space's data or dialogs."""
import asyncio,json,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[1]
A='11111111-1111-4111-8111-111111111111';B='22222222-2222-4222-8222-222222222222'
spaces=[dict(id=i,name=n,kind='company',status='active',role='owner',can_manage=True,can_edit_home=True,can_write=True) for i,n in [(A,'공간 A'),(B,'공간 B')]]
layout={'version':1,'source':'all','view':'recent','widgets':[{'id':i,'visible':True,'size':'wide'} for i in ['notice','task','event','file','story']]}
checks=0
async def main():
 global checks
 gates={};errors=[]
 def check(value,label):
  global checks
  assert value,label;checks+=1;print('PASS '+label,flush=True)
 async with async_playwright() as pw:
  browser=await pw.chromium.launch();page=await browser.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
  async def route(r):
   p=urlsplit(r.request.url).path
   gate=gates.get(p)
   if gate:
    gates.pop(p);gate['started'].set();await gate['release'].wait()
    if gate.get('error'):await r.fulfill(status=403,json={'error':'old forbidden'});return
   if p=='/tbox/spaces':await r.fulfill(body=subprocess.check_output(['php',str(ROOT/'tests/fixtures/space_home_render.php')],text=True),content_type='text/html');return
   if p.startswith('/tbox/assets/'):
    f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');await r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
   if p=='/tbox/api/me':await r.fulfill(json={'member_id':1001,'csrf_token':'synthetic','can_community_write':True});return
   if p=='/tbox/api/product-spaces':await r.fulfill(json={'spaces':spaces});return
   if p.endswith('/home'):
    s=next(s for s in spaces if s['id']==p.split('/')[-2]);await r.fulfill(json={'space':s,'layout':layout,'revision':1,'widgets':{'notice':[{'id':'n1','title':s['name']+' 공지'}],'task':[{'id':'t1','title':'준비 작업','status':'todo','can_edit':True,'revision':1}]}});return
   if p.endswith('/members'):await r.fulfill(json={'me':1001,'members':[]});return
   if p.endswith('/invites'):await r.fulfill(json={'invites':[{'id':'i1','role':'member','expires_at':'2026-10-12','uses':0,'max_uses':1}]});return
   await r.fulfill(json={'items':[]})
  await page.route('**/*',route);await page.goto('https://moa.example/tbox/spaces',wait_until='networkidle')
  def gate(path,error=False):
   value={'started':asyncio.Event(),'release':asyncio.Event(),'error':error};gates[path]=value;return value
  async def switch(id):
   await page.evaluate('(id)=>{const n=document.getElementById("space-picker");n.value=id;n.dispatchEvent(new Event("change"));}',id);await page.wait_for_function('(name)=>document.getElementById("space-title").textContent===name',arg='공간 A'if id==A else'공간 B')
  old=gate('/tbox/api/product-spaces/'+A+'/home');await page.locator('#space-picker').select_option(A);await old['started'].wait()
  refresh=gate('/tbox/api/product-spaces');await page.locator('#space-menu').click();await page.get_by_role('button',name='새로 불러오기',exact=True).click();await refresh['started'].wait();old['release'].set();await page.wait_for_timeout(100)
  check(await page.locator('#space-cards').inner_text()=='','old home cannot repopulate while refresh pending');refresh['release'].set();await page.wait_for_function('document.getElementById("space-title").textContent==="공간 A"')
  invites=gate('/tbox/api/product-spaces/'+A+'/invites');await page.locator('#space-menu').click();await page.get_by_role('button',name='초대 관리하기',exact=True).click();await invites['started'].wait();await switch(B);invites['release'].set();await page.wait_for_timeout(100)
  check(not await page.locator('#space-dialog').evaluate('(n)=>n.open'),'old invite response cannot open dialog in new space');check('공간 B 공지'in await page.locator('#space-cards').inner_text(),'new space content retained after old invites')
  await switch(A);cancelled=gate('/tbox/api/product-spaces/'+A+'/invites');await page.locator('#space-menu').click();await page.get_by_role('button',name='초대 관리하기',exact=True).click();await cancelled['started'].wait();await page.keyboard.press('Escape');cancelled['release'].set();await page.wait_for_timeout(100)
  check(not await page.locator('#space-dialog').evaluate('(n)=>n.open'),'cancelled invite menu cannot reopen after late response');check(await page.locator('#space-menu').evaluate('(n)=>n===document.activeElement'),'cancelled invite request keeps stable trigger focus')
  await switch(A);items=gate('/tbox/api/product-spaces/'+A+'/items',error=True);await page.get_by_role('button',name='전체 보기',exact=True).first.click();await items['started'].wait();await switch(B);items['release'].set();await page.wait_for_timeout(100)
  check('공간 B 공지'in await page.locator('#space-cards').inner_text(),'old item 403 cannot clear new space');check(await page.locator('#space-picker option').count()==2,'old error cannot clear membership selector')
  await switch(A);task=gate('/tbox/api/product-spaces/'+A+'/items/t1',error=True);await page.get_by_role('button',name='완료하기',exact=True).click();await task['started'].wait();await switch(B);task['release'].set();await page.wait_for_timeout(100)
  check('공간 B 공지'in await page.locator('#space-cards').inner_text(),'old task PATCH 403 cannot clear new space');check(await page.locator('#space-title').inner_text()=='공간 B','task error cannot change selected space')
  await switch(A);task=gate('/tbox/api/product-spaces/'+A+'/items/t1');await page.get_by_role('button',name='완료하기',exact=True).click();await task['started'].wait();await switch(B);task['release'].set();await page.wait_for_timeout(100)
  check(await page.locator('#space-title').inner_text()=='공간 B','old task PATCH success cannot reload previous space')
  await page.locator('#space-menu').click();await page.get_by_role('button',name='멤버 보기',exact=True).click();check(await page.locator('#space-dialog').evaluate('(n)=>n.open'),'current members dialog opens');await page.evaluate('(id)=>{const picker=document.getElementById("space-picker");picker.value=id;picker.dispatchEvent(new Event("change"));}',A);await page.wait_for_function('document.getElementById("space-title").textContent==="공간 A"')
  check(not await page.locator('#space-dialog').evaluate('(n)=>n.open'),'space switch closes dialog');check(await page.locator('#space-dialog').inner_text()=='','space switch clears dialog content');check(not errors,'no browser errors');await browser.close()
 print(str(checks)+' checks passed')
asyncio.run(main())
