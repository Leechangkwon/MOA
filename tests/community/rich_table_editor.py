"""Synthetic actual PHP markup and production editor UI under strict CSP; no live API."""
import json,os,re,subprocess,hashlib
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
ROOT=Path(__file__).resolve().parents[2]
OUT=Path(os.environ.get('RICH_TABLE_RESULTS','/tmp/rich-table-checks'));OUT.mkdir(parents=True,exist_ok=True)
VERSION=re.search(r'table-editor.js\?v=([a-f0-9]+)',(ROOT/'public/assets/community/editor.js').read_text()).group(1)
PID='00000000-0000-4000-8000-000000000001'
checks=[]
def check(ok,name):
 assert ok,name
 checks.append(name);print('PASS '+name,flush=True)
def php(code,value=None):
 return subprocess.check_output(['php','-r',code],cwd=ROOT,text=True,input=json.dumps(value) if value is not None else None)
shape=json.loads((ROOT/'tests/community/fixtures/editor_surface.json').read_text())
raw={'id':'00000000-0000-4000-8000-000000000200','type':'table','color':'blue_bg','align':'center','size':'small','header_row':True,'header_col':True,'col_widths':[100,160,80], 'rows':[{'color':'yellow_bg','cells':[{'color':'orange_bg','text':[{'t':'합성 ','m':{'bold':True}},{'t':'자료','m':{'link':{'href':'https://example.org/item'}}}]},{'text':[{'t':'@','m':{'mention':{'kind':'date','id':'2026-10-11'}}}]},{'text':[]}]},{'color':'green_bg','cells':[{'text':[{'t':'수량 22','m':{'bold':True,'color':'red'}}]},{'text':[{'t':'메모','m':{'italic':True}}]},{'text':[]}]}]}
doc={'schema':1,'blocks':[{'id':'00000000-0000-4000-8000-000000000201','type':'paragraph','text':[{'t':'원래 본문 굵기','m':{'bold':True}}]},raw]}
# Execute the actual PHP view body; shell dependencies are replaced by a synthetic shell.
markup=php('''$s=file_get_contents("src/community/handlers/page_view.php");$s=substr($s,strpos($s,"?>")+2);$s=substr($s,0,strrpos($s,"<?php"));$viewPageId="'''+PID+'''";eval("?>".$s);''')
html='<!doctype html><html lang="ko" data-design="aurora" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+''.join('<link rel="stylesheet" href="/tbox/assets/'+s+'">'for s in ['app.css','community/community.css','design.css','moa.css'])+'</head><body data-page="community-page" data-community-base="/community" data-base="/tbox"><main id="main">'+markup+'</main><script type="module" src="/tbox/assets/community/editor.js?v='+VERSION+'"></script></body></html>'
def validate(d):
 errors=json.loads(php('require "src/community/Doc.php";echo json_encode(Doc::validate(json_decode(stream_get_contents(STDIN),true),11));',d))
 return errors['ok'],errors['errors']
with sync_playwright()as pw:
 browser=pw.chromium.launch();page=browser.new_page(viewport={'width':1280,'height':900});errors=[];page.on('pageerror',lambda e:(errors.append(str(e)),print('PAGEERROR',str(e),flush=True)))
 saved=json.loads(json.dumps(doc));revision=1;writes=[];status=200;can_edit=True
 def route(r):
  global saved,revision,status
  path=urlsplit(r.request.url).path
  if path.startswith('/tbox/assets/'):
   f=ROOT/'public/assets'/path.removeprefix('/tbox/assets/');r.fulfill(body=f.read_bytes(),content_type='text/javascript'if f.suffix=='.js'else'text/css');return
  if path.startswith('/community/p/'):
   r.fulfill(body=html,content_type='text/html',headers={'Content-Security-Policy':"default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"});return
  if path=='/tbox/api/me':r.fulfill(json={'username':'synthetic','csrf_token':'synthetic-csrf','can_community_write':True});return
  if path=='/community/api/pages/'+PID:
   if r.request.method=='PATCH':
    data=r.request.post_data_json;writes.append(data)
    if status==200:
     ok,errs=validate(data['doc'])
     if not ok:print('VALIDATION',errs,flush=True);r.fulfill(status=400,json={'error':str(errs)});return
     saved=data['doc'];revision+=1;r.fulfill(json={'doc_version':revision,'title':data.get('title','합성 서식 표'),'buttons':{},'db_views':{}});return
    r.fulfill(status=status,json={'error':'합성 거부'});return
   r.fulfill(json={'page':{'id':PID,'title':'합성 서식 표','doc':saved,'doc_version':revision,'visibility':'private','owner':{'username':'synthetic'}},'can_edit':can_edit,'can_manage':False,'can_comment':False,'mention_labels':{'date:2026-10-11':'2026.10.11'},'backlinks':[]});return
  r.fulfill(json={'items':[],'pages':[],'spaces':[],'favorites':[],'recent':[],'palettes':[],'comments':[],'reads':[],'versions':[],'templates':[],'procedures':[],'integrations':[],'buttons':[]})
 html=html.replace('ASSET_VERSION',VERSION)
 page.add_init_script('window.ASSET_VERSION='+json.dumps(VERSION))
 page.route('**/*',route);page.goto('https://rich.example/community/p/'+PID,wait_until='networkidle');page.wait_for_selector('#page-edit:visible')
 page.evaluate('''async()=>{window.richSchema=await import('/tbox/assets/community/editor-schema.js?v='+ASSET_VERSION);window.richTable=await import('/tbox/assets/community/table-editor.js?v='+ASSET_VERSION);}''')
 check(page.evaluate('(d)=>JSON.stringify(richSchema.toDoc(richSchema.fromDoc(d,{})))===JSON.stringify(d)',doc),'lossless rich table and ordinary marks JSON roundtrip')
 converted=page.evaluate('(d)=>richSchema.toDoc(richSchema.fromDoc(d,{}))',shape)
 check([b for b in converted['blocks']if b['type']=='table']==[b for b in shape['blocks']if b['type']=='table'],'anonymized original-shape rich tables exact roundtrip')
 def rich_marks(d):return [(b['type'],[(s['t'],s.get('m')or{})for s in b.get('text',[])])for b in d['blocks']if b['type']!='table']
 check(rich_marks(converted)==rich_marks(shape),'anonymized original-shape other block text and marks preserved')
 page.click('#page-edit');page.wait_for_selector('#page-editor .ProseMirror');check(page.locator('#page-editor [data-atom=table]').count()==1,'actual edit entry opens rich-table document')
 def open_table():
  page.wait_for_timeout(550);page.locator('#page-editor [data-atom=table]').dblclick();page.wait_for_selector('#atom-dialog[open]');page.wait_for_selector('.table-cell-input .ProseMirror')
 def cell(y,x):page.locator(f'#atom-table-grid td[data-r="{y}"][data-c="{x}"] button').click()
 open_table();check(page.locator('.table-cell-input .ProseMirror').count()==1,'exactly one selected-cell EditorView')
 check(page.locator('#atom-table-grid a').count()==1,'preview preserves safe link')
 check(page.locator('#atom-table-grid a[href]').count()==0,'preview link cannot navigate or open context URL')
 page.locator('.table-cell-input .ProseMirror').dispatch_event('compositionstart',{'data':'한'})
 page.locator('.table-cell-input .ProseMirror').dispatch_event('keydown',{'key':'Enter','code':'Enter','isComposing':True,'keyCode':229})
 check(page.locator('#atom-dialog').evaluate('(n)=>n.open'),'IME composing Enter does not apply or close modal')
 page.locator('.table-cell-input .ProseMirror').dispatch_event('compositionend',{'data':'한'});page.wait_for_timeout(100)
 page.click('#atom-commit');page.wait_for_timeout(1600);check(saved==doc,'no-op modal apply preserves exact document')
 before_cancel=len(writes);open_table();cell(1,0);page.locator('.table-cell-input .ProseMirror').click();page.keyboard.press('End');page.keyboard.insert_text(' 고침');page.locator('[data-atom-close]').first.click();page.wait_for_timeout(1600);check(len(writes)==before_cancel,'cancel discards cell draft without parent save')
 open_table();cell(1,0);page.locator('.table-cell-input .ProseMirror').click();page.keyboard.press('End');page.keyboard.insert_text(' 고침');page.wait_for_timeout(100);page.click('#atom-commit');page.wait_for_timeout(1600)
 check(bool(writes),'actual parent transaction auto-saves cell modification')
 check(saved['blocks'][0]==doc['blocks'][0],'ordinary bold block remains exact')
 expected=json.loads(json.dumps(raw));expected['rows'][1]['cells'][0]['text']=saved['blocks'][1]['rows'][1]['cells'][0]['text'];check(saved['blocks'][1]==expected,'all untouched table keys/cells/colors/widths/link/mention remain exact')
 check('고침' in ''.join(s['t']for s in expected['rows'][1]['cells'][0]['text']),'changed Korean text persisted')
 check(validate(saved)[0],'real PHP Doc validation accepts saved draft')
 page.locator('#page-editor .ProseMirror').click();page.keyboard.press('Control+z');page.wait_for_timeout(1600)
 check(saved['blocks'][1]==raw,'parent undo restores exact original table')
 page.keyboard.press('Control+Shift+z');page.wait_for_timeout(1600)
 check('고침' in ''.join(t['t']for t in saved['blocks'][1]['rows'][1]['cells'][0]['text']),'parent redo restores actual cell edit')
 page.reload(wait_until='networkidle');page.click('#page-edit');open_table();cell(1,0);check('고침'in page.locator('.table-cell-input .ProseMirror').inner_text(),'saved rich table reopens')
 page.screenshot(path=str(OUT/'rich-table-editor-desktop.png'))
 for width in [360,390]:
  page.set_viewport_size({'width':width,'height':900});page.wait_for_timeout(100)
  check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),'actual editor page has no horizontal overflow '+str(width))
  page.screenshot(path=str(OUT/('rich-table-editor-mobile-'+str(width)+'.png')))
 page.set_viewport_size({'width':1280,'height':900})
 page.locator('[data-atom-close]').first.click();page.locator('#page-editor .ProseMirror').click();page.keyboard.press('Control+z');page.wait_for_timeout(200)
 check(not errors,'actual editor no JavaScript errors')

 page.evaluate("async()=>{window.richSchema=await import('/tbox/assets/community/editor-schema.js?v='+ASSET_VERSION);window.richTable=await import('/tbox/assets/community/table-editor.js?v='+ASSET_VERSION);const section=document.createElement('section');document.body.append(section);const grid=document.createElement('tbody');const table=document.createElement('table');table.append(grid);section.append(table);window.modelNotes=[];window.cellEditor=richTable.createTableEditor({pane:section,grid,note:t=>modelNotes.push(t)});window.editorSection=section;}")
 check(page.evaluate('richTable.tableFromAttrs({}).rows.length===2&&richTable.tableFromAttrs({}).rows[0].cells.length===2'),'new table retains default2x2')
 page.evaluate('(r)=>cellEditor.open(richTable.tableAttrs(r))',raw)
 check(page.evaluate('JSON.stringify(cellEditor.collect(true,true).raw)===JSON.stringify(cellEditor.raw)'),'collect raw does not mutate or normalize unchanged cells')
 check(page.evaluate('(r)=>richTable.tableFromAttrs({...richTable.tableAttrs(r),id:"00000000-0000-4000-8000-000000000299"}).id==="00000000-0000-4000-8000-000000000299"',raw),'cloned atom ID overrides raw old ID')
 page.evaluate("cellEditor.changeShape('row_add');cellEditor.changeShape('col_add')")
 expanded=page.evaluate('cellEditor.collect(true,true).raw')
 check(expanded['rows'][0]['cells'][:3]==raw['rows'][0]['cells'] and expanded['rows'][1]['cells'][:3]==raw['rows'][1]['cells'],'row/column add preserves original cell metadata')
 check(expanded['col_widths']==[100,160,80,120],'column addition extends existing widths only')
 check(validate({'schema':1,'blocks':[expanded]})[0],'expanded rich table passes PHP validation')
 page.evaluate("cellEditor.changeShape('row_del')");check(page.locator('.table-delete-confirm:not([hidden])').count()==1,'row deletion asks inline confirmation')
 page.locator('.table-delete-confirm button',has_text='취소').click();check(page.evaluate('cellEditor.raw.rows.length')==3,'deletion cancellation keeps draft rows')
 page.evaluate("cellEditor.changeShape('row_del')");page.locator('.table-delete-confirm button',has_text='삭제하기').click()
 check(page.evaluate('cellEditor.raw.rows.length')==2,'ordinary end row deletion is allowed after confirmation')
 page.evaluate("cellEditor.changeShape('col_del')");page.locator('.table-delete-confirm button',has_text='삭제하기').click()
 check(page.evaluate('cellEditor.raw.col_widths')==[100,160,80],'column deletion synchronizes widths')
 check(page.evaluate('()=>{const r=structuredClone(cellEditor.raw);r.rows[0].cells[0].rowspan=2;r.rows[1].cells[0].text=[];return richTable.outsideSpan(r,1,3)&&!richTable.outsideSpan(r,2,2);}'),'only surviving crossing spans block structure deletion')
 check(page.evaluate("()=>{const r=structuredClone(cellEditor.raw);const before=JSON.stringify(r);return richTable.mergeCells(r,0,0,1,2)!==''&&JSON.stringify(r)===before;}"),'merge refuses target mention or metadata without mutation')
 check(page.evaluate("()=>{const r=structuredClone(cellEditor.raw);return richTable.mergeCells(r,0,0,21,1)!=='';}"),'merge has server-compatible span maximum20')
 plain={'type':'table','id':'00000000-0000-4000-8000-000000000300','header_row':True,'header_col':False,'rows':[{'cells':[{'text':[{'t':'시작','m':{}}]},{'text':[]}]},{'cells':[{'text':[]},{'text':[]}]}]}
 page.evaluate('(r)=>cellEditor.open(richTable.tableAttrs(r))',plain)
 check(page.evaluate("()=>{const r=structuredClone(cellEditor.raw);return richTable.mergeCells(r,0,0,2,2)===''&&r.rows[0].cells[0].rowspan===2&&r.rows[0].cells[0].colspan===2&&richTable.coveredCells(r).size===3;}"),'empty cell merge preserves anchor and covered mapping')
 page.evaluate('()=>{const e=cellEditor.view;const data=new DataTransfer();data.setData(\'text/html\',\'<p><a href="javascript:alert(1)">위험링크</a><span data-mention-kind="date" data-mention-id="2026-10-11">@</span><strong>안전굵기</strong></p>\');e.dom.dispatchEvent(new ClipboardEvent(\'paste\',{bubbles:true,cancelable:true,clipboardData:data}));}');page.wait_for_timeout(100)
 pasted=page.evaluate('cellEditor.collect(true,false).raw.rows[0].cells[0].text')
 check(any('안전굵기'in t['t']for t in pasted),'actual clipboard inserts safe rich content')
 check(not any(t.get('m',{}).get('mention')or t.get('m',{}).get('link',{}).get('href','').startswith('javascript:')for t in pasted),'clipboard strips forged mentions and unsafe links')
 max_table={**plain,'rows':[{'cells':[{'text':[]}for _ in range(20)]}for _ in range(200)]}
 page.evaluate('(r)=>cellEditor.open(richTable.tableAttrs(r))',max_table)
 check(page.evaluate('editorSection.querySelectorAll(".table-cell-input .ProseMirror").length')==1,'200x20 boundary still owns one EditorView')
 page.evaluate("cellEditor.changeShape('row_add');cellEditor.changeShape('col_add')")
 check(page.evaluate('cellEditor.raw.rows.length===200&&cellEditor.raw.rows[0].cells.length===20'),'200x20 additions stay bounded')
 page.evaluate('cellEditor.close();editorSection.remove()')
 for refused in [409,403]:
  status=refused;page.reload(wait_until='networkidle');page.click('#page-edit');open_table();cell(1,0)
  baseline=json.loads(json.dumps(saved));page.locator('.table-cell-input .ProseMirror').click();page.keyboard.press('End');page.keyboard.insert_text(' 거부시험');page.click('#atom-commit');page.wait_for_timeout(1600)
  check(saved==baseline,'PATCH'+str(refused)+' keeps server document unchanged')
  check('저장 못 함' in page.locator('#page-save-state').inner_text() and (page.locator('#conflict-dialog').evaluate('(n)=>n.open') if refused==409 else bool(page.locator('#msg').inner_text().strip())),'PATCH'+str(refused)+' shows failure rather than success')
 status=200
 can_edit=False;readonly_writes=[];readonly=browser.new_page()
 def readonly_route(r):
  if r.request.method=='PATCH':readonly_writes.append(r.request.url)
  route(r)
 readonly.route('**/*',readonly_route);readonly.goto('https://rich.example/community/p/'+PID,wait_until='networkidle');readonly.wait_for_timeout(1600)
 check(readonly.locator('#page-edit').is_hidden()and not readonly_writes,'fresh can_edit false hides edit entry and performs no write')
 readonly.close()
 check(not errors,'expanded actual UI and model checks have no JavaScript errors')
 print('ERRORS',errors)
 browser.close()
(OUT/'results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},ensure_ascii=False,indent=2));print(len(checks),'checks passed')
