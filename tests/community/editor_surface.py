"""Readonly synthetic document surface regression: real PHP/JS renderers, CSS, strict CSP."""
import json, os, subprocess, hashlib
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(os.environ.get('EDITOR_SURFACE_RESULTS','/tmp/editor-surface-checks'));OUT.mkdir(parents=True,exist_ok=True)
SAMPLE=Path(os.environ.get('EDITOR_SURFACE_SAMPLE',str(ROOT/'tests/community/fixtures/editor_surface.json')))
doc=json.loads(SAMPLE.read_text());original=json.dumps(doc,sort_keys=True,ensure_ascii=False);checks=[]
def check(ok,name):
 assert ok,name;checks.append(name);print('PASS '+name,flush=True)
def php(code,value):
 return subprocess.check_output(['php','-r',code],cwd=ROOT,input=json.dumps(value,ensure_ascii=False),text=True)
def render(value):
 return php('require "src/community/Doc.php";require "src/community/DocRender.php";echo DocRender::html(json_decode(stream_get_contents(STDIN),true));',value)
colors=['gray','brown','orange','yellow','green','blue','purple','pink','red']
variants=[]
for j,color in enumerate(colors+['pal:review','pal:bg','pal:deleted']):
 bg=color+'_bg';variants.extend([{'id':f'00000000-0000-4000-8000-{j*2+1:012d}','type':'callout','icon':'','color':bg,'text':[{'t':'합성 강조 '+color,'m':{'bold':True}}]}, {'id':f'00000000-0000-4000-8000-{j*2+2:012d}','type':'table','header_row':True,'header_col':True,'rows':[{'color':bg,'cells':[{'color':'green_bg','text':[{'t':'셀 우선','m':{}}]},{'color':'pal:bg','text':[{'t':'행 바탕·글자색','m':{}}]},{'text':[{'t':'행 우선','m':{}}]}]},{'color':'pal:bg','cells':[{'text':[{'t':'기본 열 머리','m':{}}]},{'text':[{'t':'일반 칸','m':{}}]},{'text':[{'t':'일반 칸','m':{}}]}]},{'color':bg,'cells':[{'text':[{'t':'색 있는 열 머리','m':{}}]},{'text':[{'t':'색 있는 행','m':{}}]},{'text':[{'t':'색 있는 행','m':{}}]}]}]}])
variants.extend([{'id':'00000000-0000-4000-8000-999999999990','type':'callout','text':[{'t':'기본 강조','m':{}}]}, {'id':'00000000-0000-4000-8000-999999999991','type':'callout','color':'pal:bg','text':[{'t':'팔레트 글자색','m':{}}]}, {'id':'00000000-0000-4000-8000-999999999992','type':'paragraph','text':[{'t':c,'m':{'color':c+'_bg'}} for c in colors]}])
probe={'schema':1,'blocks':variants};palette=php('require "src/community/Doc.php";require "src/community/Palette.php";echo Palette::css(json_decode(stream_get_contents(STDIN),true),"123456789abc");',[{'name':'review','light':'#2859a8','dark':'#a0c2ff'},{'name':'bg','light':'#14675f','dark':'#91d5c4'}])
links=['app.css','community/community.css','design.css','moa.css']
html='<!doctype html><html lang="ko" data-theme="dark" data-design="aurora"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+''.join('<link rel="stylesheet" href="/tbox/assets/'+n+'">' for n in links[:2])+'<link rel="stylesheet" href="/community/palette.css">'+''.join('<link rel="stylesheet" href="/tbox/assets/'+n+'">'for n in links[2:])+'</head><body data-page="community-page"><main id="main"><section id="page-doc" class="panel">'+render(doc)+'</section><section id="page-editor" class="panel" hidden data-paper-mode="a4"><div class="doc editor"></div></section><section id="probe" class="panel">'+render(probe)+'</section><section id="nested" class="panel"><div class="doc"><div class="doc"><p>중첩 합성 미리보기</p></div></div></section></main></body></html>'
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 def route(r):
  path=urlsplit(r.request.url).path
  if path=='/':r.fulfill(body=html,content_type='text/html',headers={'Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:"});return
  if path=='/community/palette.css':r.fulfill(body=palette,content_type='text/css');return
  if path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/path.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  r.abort()
 page.route('**/*',route);page.goto('https://surface.example/',wait_until='networkidle')
 page.evaluate('''async(d)=>{const r=await import('/tbox/assets/community/doc-render.js');const e=await import('/tbox/assets/community/editor-schema.js');window.surfaceRenderer=r;window.surfaceSchema=e;window.sourceDoc=structuredClone(d);window.beforeDoc=JSON.stringify(d);const js=r.renderDoc(d,{}).firstChild;window.parity={text:js.textContent===document.querySelector('#page-doc>.doc').textContent,tags:[...js.querySelectorAll('*')].map(n=>[n.tagName,n.className,n.getAttribute('rowspan'),n.getAttribute('colspan')])};window.ssrTags=[...document.querySelectorAll('#page-doc>.doc *')].map(n=>[n.tagName,n.className,n.getAttribute('rowspan'),n.getAttribute('colspan')]);window.beforeDom=document.querySelector('#page-doc').innerHTML;}''',doc)
 check(page.evaluate('parity.text&&JSON.stringify(parity.tags)===JSON.stringify(ssrTags)'),'actual PHP/JS render parity of anonymized structure and marks')
 check(page.evaluate('''()=>{try{surfaceSchema.fromDoc(sourceDoc,{});return false;}catch(e){return e.message.includes('꾸밈')&&JSON.stringify(sourceDoc)===beforeDoc;}}'''),'rich tables remain protected and JSON unchanged')
 for design in ['moa','astra','aurora','bloom','nova','claude']:
  for theme in ['light','dark']:
   for width in [360,390,412,820,1280]:
    page.set_viewport_size({'width':width,'height':900});page.evaluate('(v)=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1];document.documentElement.dataset.contrast=""}',[design,theme]);page.wait_for_timeout(100)
    tag=f'{design}-{theme}-{width}'
    check(page.evaluate('''()=>[...document.querySelectorAll('#page-doc .doc,#nested .doc')].every(n=>getComputedStyle(n).backgroundColor==='rgba(0, 0, 0, 0)')'''),'document inherits panel surface '+tag)
    check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'no page overflow with hidden legacy A4 editor '+tag)
    check(page.evaluate('''()=>[...document.querySelectorAll('#probe .doc-callout')].every(n=>{const c=getComputedStyle(n);const cls=[...n.classList].find(c=>c.endsWith('-bg')&&c!=='pal-bg');const name=cls?cls.replace(/^nt-/,'').replace(/^pal-/,'pal-'):null;const expected=name&&name!=='pal-deleted-bg'?c.getPropertyValue('--'+(name.startsWith('pal-')?name:'nt-'+name)).trim():c.getPropertyValue('--nt-gray-bg').trim();const sw=document.createElement('span');sw.className=cls&&name!=='pal-deleted-bg'?cls:'nt-gray-bg';document.querySelector('#probe').append(sw);const ok=c.backgroundColor===getComputedStyle(sw).backgroundColor;sw.remove();return ok;})'''),'explicit nine/palette callouts and default/foreground/deleted fallback '+tag)
    check(page.evaluate('''()=>[...document.querySelectorAll('#probe .doc-table')].every(t=>{const rows=t.rows,head=rows[0].cells;return getComputedStyle(head[0]).backgroundColor===getComputedStyle(document.querySelector('#probe .nt-green-bg')).backgroundColor&&getComputedStyle(head[1]).backgroundColor===getComputedStyle(rows[0]).backgroundColor&&getComputedStyle(head[2]).backgroundColor===getComputedStyle(rows[0]).backgroundColor&&getComputedStyle(rows[1].cells[0]).backgroundColor!=='rgba(0, 0, 0, 0)'&&(rows[2].classList.contains('pal-deleted-bg')?getComputedStyle(rows[2].cells[0]).backgroundColor==='rgba(0, 0, 0, 0)':getComputedStyle(rows[2].cells[0]).backgroundColor!=='rgba(0, 0, 0, 0)')&&getComputedStyle(rows[1].cells[1]).backgroundColor==='rgba(0, 0, 0, 0)';})'''),'table cell then row then default header including foreground palette bg '+tag)
    check(page.evaluate('document.querySelector("#page-doc").innerHTML===beforeDom&&JSON.stringify(sourceDoc)===beforeDoc'),'readonly original DOM/JSON unchanged '+tag)
    if design in ['moa','aurora']and width in [390,1280]:page.locator('#page-doc').screenshot(path=str(OUT/(tag+'-read.png')))
 # Supported editor content remains on the existing schema and paper dimensions.
 supported={'schema':1,'blocks':[{'id':'00000000-0000-4000-8000-000000000100','type':'paragraph','text':[{'t':'합성 굵은 문장','m':{'bold':True}}]},{'id':'00000000-0000-4000-8000-000000000101','type':'callout','icon':'💡','color':'blue_bg','text':[{'t':'합성 편집 색상','m':{}}]}]}
 check(page.evaluate("async(d)=>{const {EditorState}=await import('/tbox/assets/community/vendor/pm/prosemirror-state.js');const {EditorView}=await import('/tbox/assets/community/vendor/pm/prosemirror-view.js');const node=surfaceSchema.schema.nodeFromJSON(surfaceSchema.fromDoc(d,{}));const back=surfaceSchema.toDoc(node.toJSON());const host=document.createElement('div');host.className='doc editor';document.querySelector('#probe').append(host);const view=new EditorView(host,{state:EditorState.create({schema:surfaceSchema.schema,doc:node})});const callout=host.querySelector('.doc-callout');const sw=document.createElement('span');sw.className='nt-blue-bg';host.append(sw);const color=getComputedStyle(callout).backgroundColor===getComputedStyle(sw).backgroundColor;view.dispatch(view.state.tr.insertText(' 추가',2));const changed=surfaceSchema.toDoc(view.state.doc.toJSON());const ok=back.blocks[0].text[0].m.bold===true&&back.blocks[1].color==='blue_bg'&&changed.blocks[1].color==='blue_bg'&&changed.blocks[0].text.some(t=>t.m.bold)&&color;view.destroy();host.remove();return ok;}",supported),'supported real ProseMirror bold/background roundtrip and partial transaction preserved')
 for design in ['moa','astra','aurora','bloom','nova','claude']:
  for theme in ['light','dark']:
   page.evaluate('(v)=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1];document.documentElement.dataset.contrast="high"}',[design,theme]);page.wait_for_timeout(80)
   check(page.evaluate("getComputedStyle(document.querySelector('#page-doc>.doc')).backgroundColor==='rgba(0, 0, 0, 0)'&&document.querySelector('#page-doc').innerHTML===beforeDom"),'high contrast surface/readonly preserved '+design+'-'+theme)
 page.emulate_media(media='print')
 check(page.evaluate("document.querySelector('#page-doc').innerHTML===beforeDom&&getComputedStyle(document.querySelector('#page-doc>.doc')).backgroundColor==='rgba(0, 0, 0, 0)'"),'print preserves content and transparent document surface')
 check(not errors,'no JavaScript errors under strict CSP');browser.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'sample_sha256':hashlib.sha256(original.encode()).hexdigest()},ensure_ascii=False,indent=2));print(str(len(checks))+' checks passed')
