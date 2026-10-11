"""Synthetic actual-renderer/CSS table density and selected-cell modal regression. No DB/network API."""
import hashlib
import json
import os
import subprocess
from pathlib import Path
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
OUT = Path(os.environ.get('TABLE_DENSITY_RESULTS', '/tmp/community-table-density'))
OUT.mkdir(parents=True, exist_ok=True)
checks = []
errors = []
requests = []


def check(ok, name):
    assert ok, name
    checks.append(name)
    print('PASS ' + name, flush=True)


def render(doc):
    return subprocess.check_output(
        ['php', '-r', 'require "src/community/Doc.php";require "src/community/DocRender.php";echo DocRender::html(json_decode(stream_get_contents(STDIN),true));'],
        cwd=ROOT, input=json.dumps(doc), text=True)


def cell(text):
    return {'text': [{'t': text, 'm': {}}]}


def table(cols, number):
    rows = [{'cells': [cell('품목' if c == 0 else str(6 + (c - 1) // 2) + '월 ' + ('수량' if c % 2 else '금액')) for c in range(cols)]}]
    names = ['가상품목 FLX 4㎠ 600개', '가상품목 FLX 4㎠ 300개', '가상품목 FLX 아이 450개', '합계']
    for y, name in enumerate(names):
        row = {'cells': [cell(name)] + [cell(str((y+c) % 10) if c % 2 else f'{(y+c)*431723:,}') for c in range(1, cols)]}
        if y == 1:
            row['color'] = 'yellow_bg'
            row['cells'][2]['color'] = 'green_bg'
        rows.append(row)
    return {'id': f'00000000-0000-4000-8000-{number:012d}', 'type': 'table', 'header_row': True, 'header_col': True, 'rows': rows}


tables = [table(9, 1), table(16, 2)]
doc = {'schema': 1, 'blocks': tables}
notes = {'schema': 1, 'blocks': [{'id': '00000000-0000-4000-8000-000000000003', 'type': 'table', 'header_row': True, 'header_col': False,
    'rows': [{'cells': [cell('담당'), cell('설명')]}, {'cells': [cell('김영업'), cell('품목 이름과 업무 설명은 어절을 유지해서 읽을 수 있어야 합니다. ' * 8)]}, {'cells': [cell('이기술'), cell('https://example.invalid/' + 'a' * 180)]}]}]}
merged = {'schema': 1, 'blocks': [{'id': '00000000-0000-4000-8000-000000000004', 'type': 'table', 'header_row': True, 'header_col': True,
    'rows': [{'cells': [dict(cell('합친 머리'), colspan=2), {'text': []}, cell('일반 머리')]}, {'cells': [dict(cell('합친 행'), rowspan=2), cell('2'), cell('3')]}, {'cells': [{'text': []}, cell('4'), cell('5')]}]}]}
css_names = ['app.css', 'community/community.css', 'design.css', 'moa.css']
css = {n: (ROOT / 'public/assets' / n).read_bytes() for n in css_names}
base_css = subprocess.check_output(['git', 'show', 'HEAD:public/assets/community/community.css'], cwd=ROOT)
links = ''.join('<link rel="stylesheet" href="/assets/' + n + '">' for n in css_names)
html = '<!doctype html><html lang="ko" data-design="moa" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' + links + '</head><body data-page="community-page"><div class="app"><nav class="app-rail"></nav><aside class="sidebar" hidden></aside><main id="main" class="main"><article id="page-doc" class="panel">' + render(doc) + '</article><article id="notes" class="panel">' + render(notes) + '</article><article id="merged" class="panel">' + render(merged) + '</article><article class="panel"><table class="doc-table db-table"><tbody><tr><td class="doc-table__cell">DB unchanged</td></tr></tbody></table></article><section id="page-editor" class="panel" data-paper-mode="a4" hidden><div class="doc editor" contenteditable="true">실제 입력 가능한 합성 문서</div></section></main></div><dialog id="atom-dialog" class="modal modal--form"><div class="modal__head"><h2 class="modal__title">표 고치기</h2></div><div class="modal__body form"><div id="atom-table" class="atom-pane"><div class="doc-table-wrap"><table class="doc-table atom-grid"><tbody id="atom-table-grid"></tbody></table></div></div></div><div class="modal__foot"><button class="btn btn--quiet">취소</button><button class="btn">적용</button></div></dialog></body></html>'
metrics_js = '''()=>({overflow:document.documentElement.scrollWidth>innerWidth,mainOverflow:document.querySelector('#main').scrollWidth>document.querySelector('#main').clientWidth,tables:[...document.querySelectorAll('#page-doc table')].map(t=>{const c=t.rows[1].cells[1],s=getComputedStyle(c);const lines=n=>{const r=document.createRange();r.selectNodeContents(n);return new Set([...r.getClientRects()].map(x=>Math.round(x.top))).size;};return {width:t.getBoundingClientRect().width,height:t.getBoundingClientRect().height,rowHeight:t.rows[1].getBoundingClientRect().height,firstWidth:t.rows[1].cells[0].getBoundingClientRect().width,numericWidth:c.getBoundingClientRect().width,font:s.fontSize,line:s.lineHeight,padding:s.padding,itemLines:Math.max(...[1,2,3].map(r=>lines(t.rows[r].cells[0]))),numberLines:Math.max(...[...t.querySelectorAll('td')].map(lines)),scroll:getComputedStyle(t.parentElement).overflowX};})})'''
baseline = {}
observations = []
with sync_playwright() as pw:
    browser = pw.chromium.launch()
    page = browser.new_page(viewport={'width': 1280, 'height': 960})
    old = True

    def route(r):
        path = urlsplit(r.request.url).path
        requests.append({'method': r.request.method, 'path': path})
        if path == '/':
            r.fulfill(body=html, content_type='text/html', headers={'Content-Security-Policy': "default-src 'self';style-src 'self';script-src 'self';connect-src 'none';img-src 'self' data:"})
        elif path.startswith('/assets/'):
            name = path[len('/assets/'):]
            f = ROOT / 'public/assets' / name
            body = base_css if old and name == 'community/community.css' else css.get(name, f.read_bytes())
            r.fulfill(body=body, content_type='text/javascript' if f.suffix == '.js' else 'text/css')
        else:
            errors.append('Unexpected request ' + path)
            r.abort()

    page.route('**/*', route)
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)
    page.goto('https://density.example/', wait_until='networkidle')
    for width in [360,390,412,820,1280]:
        page.set_viewport_size({'width': width, 'height': 960})
        baseline[width] = page.evaluate(metrics_js)
    old = False
    page.reload(wait_until='networkidle')
    initial_dom = page.locator('#page-doc').inner_html()
    for design in ['moa','astra','aurora','bloom','nova','claude']:
        for theme in ['light','dark']:
            for contrast in (['normal','high'] if design in ['moa','aurora'] else ['normal']):
                page.evaluate('v=>{document.documentElement.dataset.design=v[0];document.documentElement.dataset.theme=v[1];document.documentElement.dataset.contrast=v[2]}', [design,theme,contrast])
                for width in [360,390,412,820,1280]:
                    page.set_viewport_size({'width': width, 'height': 960})
                    tag = f'{design}-{theme}-{contrast}-{width}'
                    m = page.evaluate(metrics_js)
                    observations.append({'tag': tag, **m})
                    check(not m['overflow'] and not m['mainOverflow'], 'page and main stay inside viewport ' + tag)
                    check(all(t['font']=='14px' and t['line']=='21px' and t['scroll']=='auto' for t in m['tables']), '14px text with compact lines and local table scrolling ' + tag)
                    check(all(t['itemLines']<=2 and t['numberLines']==1 and t['firstWidth']>t['numericWidth'] for t in m['tables']), 'item at most two lines and numeric cells one line ' + tag)
                    check(page.locator('#page-doc').inner_html()==initial_dom, 'read document DOM unchanged ' + tag)
                    if design=='moa' and theme=='dark' and contrast=='normal':
                        check(all(t['rowHeight']<baseline[width]['tables'][i]['rowHeight']*.7 and t['width']<baseline[width]['tables'][i]['width']*.9 for i,t in enumerate(m['tables'])), 'measured density improves without smaller text ' + str(width))
                        if width in [390,1280]:page.locator('#page-doc').screenshot(path=str(OUT/f'read-{width}.png'))
    check(page.evaluate('''()=>getComputedStyle(document.querySelector('.db-table td')).minWidth==='96px'&&getComputedStyle(document.querySelector('.db-table td')).padding==='6px 10px' '''), 'database table density remains unchanged')
    check(page.evaluate('''()=>document.querySelector('#merged th[colspan="2"]')!==null&&document.querySelector('#merged th[rowspan="2"]')!==null'''), 'merged read cells retain spans')
    check(page.evaluate('''()=>[...document.querySelectorAll('#notes td')].every(n=>n.getBoundingClientRect().width<=385)&&getComputedStyle(document.querySelector('#notes td')).wordBreak==='keep-all' '''), 'long prose bounded while Korean words keep their boundaries')
    for width in [360,390,412,820,1280]:
        page.set_viewport_size({'width': width, 'height': 960})
        page.evaluate('()=>{document.documentElement.dataset.design="moa";document.documentElement.dataset.theme="dark";document.querySelector("#page-doc").hidden=true;document.querySelector("#page-editor").hidden=false;}')
        for mode in ['a4','a3','letter','wide']:
            page.evaluate('m=>document.querySelector("#page-editor").dataset.paperMode=m', mode)
            check(page.evaluate('''()=>document.documentElement.scrollWidth<=innerWidth&&document.querySelector('#main').scrollWidth<=document.querySelector('#main').clientWidth&&document.querySelector('#page-editor').getBoundingClientRect().right<=innerWidth'''), f'visible paper editing fits {mode}-{width}')
    page.evaluate('''async table=>{const m=await import('/assets/community/table-editor.js');window.tableUI=m.createTableEditor({pane:document.querySelector('#atom-table'),grid:document.querySelector('#atom-table-grid'),note:()=>{}});tableUI.open(m.tableAttrs(table));document.querySelector('#atom-dialog').showModal();}''', tables[1])
    for theme in ['light','dark']:
        page.evaluate('t=>document.documentElement.dataset.theme=t',theme)
        for width in [360,390,412,820,1280]:
            page.set_viewport_size({'width': width, 'height': 960})
            check(page.evaluate('''()=>{const d=document.querySelector('#atom-dialog'),b=d.querySelector('.modal__body');return d.getBoundingClientRect().width<=innerWidth-30&&b.scrollWidth<=b.clientWidth&&document.querySelectorAll('.table-cell-input .ProseMirror').length===1;}'''),f'actual rich cell modal fits with one editor {theme}-{width}')
            check(page.evaluate('''()=>[...document.querySelectorAll('.table-cell-preview')].every(n=>n.getBoundingClientRect().height>=44)&&getComputedStyle(document.querySelector('.table-cell-input')).fontSize==='16px' '''), f'preview touch size and readable cell input {theme}-{width}')
            check(page.evaluate('''()=>[...document.querySelectorAll('.table-cell-preview')].filter(n=>/^[\\d,]+$/.test(n.textContent)).every(n=>{const r=document.createRange();r.selectNodeContents(n);return new Set([...r.getClientRects()].map(x=>Math.round(x.top))).size===1;})'''), f'numeric preview remains one line {theme}-{width}')
            page.locator('.table-cell-preview').nth(17).click()
            check(page.evaluate('''()=>document.querySelector('.table-cell-input').contains(document.activeElement)&&document.querySelectorAll('.is-picked').length===1'''),f'select cell focuses input {theme}-{width}')
            if theme=='dark' and width in [390,1280]:page.locator('#atom-dialog').screenshot(path=str(OUT/f'editor-{width}.png'))
    page.evaluate('()=>{tableUI.close();document.querySelector("#atom-dialog").close();document.querySelector("#page-editor").dataset.paperMode="a4";}')
    page.emulate_media(media='print')
    check(page.evaluate('getComputedStyle(document.querySelector("#page-editor")).minWidth==="794px"'), 'screen fit does not change original print paper minimum')
    check(not errors, 'no JS or strict CSP errors')
    check(all(r['method']=='GET' and (r['path']=='/' or r['path'].startswith('/assets/')) for r in requests), 'only local synthetic resources; business requests zero')
    browser.close()
result={'passed':len(checks),'failed':0,'checks':checks,'baseline':baseline,'observations':observations,'errors':errors,'css_sha256':hashlib.sha256(css['community/community.css']).hexdigest()}
(OUT/'results.json').write_text(json.dumps(result,ensure_ascii=False,indent=2))
print(str(len(checks))+' checks passed',flush=True)
