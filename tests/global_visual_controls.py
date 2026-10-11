"""Native select Korean glyph fitting and default-theme surface/semantic regressions."""
import json,os,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[1];OUT=Path(os.environ.get('VISUAL_RESULTS','/tmp/global-visual-controls'));OUT.mkdir(parents=True,exist_ok=True)
checks=[];skips=[]
def check(value,name):
 checks.append({'name':name,'passed':bool(value)});assert value,name
METRICS="""n=>{const s=getComputedStyle(n),c=document.createElement('canvas').getContext('2d');c.font=s.font;const text=n.tagName==='SELECT'?n.selectedOptions[0].textContent:n.value,m=c.measureText(text);return {height:n.getBoundingClientRect().height,padding:parseFloat(s.paddingTop)+parseFloat(s.paddingBottom),content:n.clientHeight-parseFloat(s.paddingTop)-parseFloat(s.paddingBottom),glyph:m.actualBoundingBoxAscent+m.actualBoundingBoxDescent,fontSize:parseFloat(s.fontSize),lineHeight:s.lineHeight,appearance:s.appearance,font:s.font};}"""
with sync_playwright() as pw:
 for name in ['chromium','webkit']:
  try:browser=getattr(pw,name).launch()
  except Exception as e:skips.append({'browser':name,'reason':str(e).splitlines()[0]});continue
  page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
  mode='home';design='moa';theme='light'
  def route(r):
   p=urlsplit(r.request.url).path
   if p=='/':r.fulfill(body=subprocess.check_output(['php',str(ROOT/'tests/fixtures/global_visual_controls.php'),mode,design,theme],text=True),content_type='text/html');return
   if p.startswith('/tbox/assets/'):
    f=ROOT/'public/assets'/p.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/css');return
   r.abort()
  page.route('**/*',route)
  for design in ['moa','claude','astra','aurora','bloom','nova']:
   for theme in ['light','dark']:
    for width in [360,390,1280]:
     page.set_viewport_size({'width':width,'height':900});page.goto('https://moa.example/',wait_until='networkidle')
     for modal in [False,True]:
      if modal:page.locator('#editor').evaluate('(n)=>n.showModal()')
      selectors=['#modal-select','#modal-view','#password-control','#plain-select']if modal else['#toolbar-select','#field-select','#compact-select','#text-control']
      for selector in selectors:
       m=page.locator(selector).evaluate(METRICS);label=f'{name} {design} {theme} {width} {selector}'
       check(m['height']>=44,label+' touch height');check(m['content']>=m['glyph']+2,label+' Korean glyph fits');check(m['lineHeight']=='normal',label+' native line height')
       if page.locator(selector).evaluate('(n)=>n.tagName')=='SELECT':check(m['appearance']not in['none'],label+' native arrow retained')
       if width<720:check(m['fontSize']>=16,label+' mobile text size')
      if modal:check(page.locator('#checkbox-row').evaluate('(n)=>getComputedStyle(n).flexDirection')=='row',f'{name} {design} {theme} {width} checkbox label row')
      check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{name} {design} {theme} {width} no overflow')
      if design=='moa'and width in[390,1280]:page.screenshot(path=str(OUT/(f'{name}-home-{width}-{theme}'+('-editor.png'if modal else'.png'))),full_page=True)
     if design=='moa':
      colors=page.evaluate("""()=>{const bg=n=>getComputedStyle(n).backgroundColor;return [bg(document.body),bg(document.querySelector('.space-card')),bg(document.getElementById('editor')),bg(document.getElementById('modal-select'))];}""")
      check(len(set(colors))==4,f'{name} {theme} {width} four distinct surface levels')
      check(page.locator('#editor button.btn').evaluate('(n)=>getComputedStyle(n).boxShadow')=='none',f'{name} {theme} {width} primary glow absent')
      services=page.locator('.moa-service-card').evaluate_all('(ns)=>ns.map(n=>getComputedStyle(n).borderTopColor)');check(len(set(services))==4,f'{name} {theme} {width} service color identities')
     page.locator('#editor').evaluate('(n)=>n.close()')
  design='moa'
  for mode in ['library','space']:
   for theme in ['light','dark']:
    page.set_viewport_size({'width':390,'height':900});page.goto('https://moa.example/',wait_until='networkidle');page.locator('#editor').evaluate('(n)=>n.showModal()')
    check(page.locator('#modal-select').evaluate(METRICS)['content']>=page.locator('#modal-select').evaluate(METRICS)['glyph']+2,f'{name} {mode} {theme} modal glyphs')
    page.screenshot(path=str(OUT/f'{name}-{mode}-390-{theme}.png'),full_page=True)
  for theme in ['light','dark']:
   page.evaluate('(theme)=>{document.documentElement.dataset.theme=theme;document.documentElement.dataset.contrast="high"}',theme)
   check(page.locator('#modal-select').evaluate(METRICS)['content']>=page.locator('#modal-select').evaluate(METRICS)['glyph']+2,f'{name} {theme} high contrast glyphs')
   check(page.locator('.space-card').first.evaluate('(n)=>getComputedStyle(n).boxShadow')=='none',f'{name} high contrast shadow absent')
  if name=='chromium':
   page.emulate_media(forced_colors='active');check(page.locator('#modal-select').evaluate(METRICS)['content']>=page.locator('#modal-select').evaluate(METRICS)['glyph']+2,'forced colors glyphs fit');page.screenshot(path=str(OUT/'chromium-forced-colors.png'),full_page=True)
  check(not errors,name+' no browser errors');browser.close()
(OUT/'results.json').write_text(json.dumps({'checks':checks,'skips':skips},ensure_ascii=False,indent=2));print(f'{len(checks)} checks passed; skips {skips}')
