"""Focused synthetic HTML clipboard acceptance; production schema/editor, no live API.

python tests/community/clipboard_html.py [--baseline] [--skip-php]
Without --skip-php the real PHP view and Doc::validate are required.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import sys
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
OUT = Path(os.environ.get('CLIPBOARD_RESULTS', str(Path(tempfile.gettempdir()) / 'clipboard-html-checks')))
OUT.mkdir(parents=True, exist_ok=True)
ARGS = argparse.ArgumentParser()
ARGS.add_argument('--baseline', action='store_true')
ARGS.add_argument('--skip-php', action='store_true')
ARGS.add_argument('--render-only', action='store_true')
args = ARGS.parse_args()
PID = '00000000-0000-4000-8000-000000000001'
checks = []
validated = []
failures = []
SAMPLE = '''<p>비교 <strong>123,456</strong> <span style="font-weight:700">77.5%</span> **문자 그대로**</p>
<ul><li>목록 <code>qty</code> <a href="https://example.org/help">근거</a></li></ul>
<table><thead><tr><th>항목</th><th>이전</th><th>현재</th></tr></thead><tbody>
<tr><td>비용</td><td><strong>100</strong></td><td><span style="font-weight:700">120</span></td></tr>
<tr><td><p>첫 문단</p><p>다음<br>줄</p></td><td><code>sum(x)</code></td><td><a href="https://example.org/a">근거</a></td></tr>
</tbody></table><p>표 다음</p>'''


def check(ok, name):
    if not ok:
        failures.append(name)
        print('FAIL ' + name, flush=True)
        if not args.baseline:
            raise AssertionError(name)
    else:
        checks.append(name)
        print('PASS ' + name, flush=True)


def php(code, value=None):
    return subprocess.check_output(['php', '-r', code], cwd=ROOT, text=True,
                                   encoding='utf-8', input=json.dumps(value) if value is not None else None)


def validate(doc):
    if args.skip_php:
        return True
    result = json.loads(php('require "src/community/Doc.php";echo json_encode(Doc::validate(json_decode(stream_get_contents(STDIN),true),11));', doc))
    assert result['ok'], result['errors']
    validated.append(doc)
    return True


source = (ROOT / 'src/community/handlers/page_view.php').read_text(encoding='utf-8')
if args.skip_php:
    # Only the fixed doc-view branches, not an emulation of the PHP validator.
    markup = source[source.index('?>') + 2:source.rindex('<?php')]
    markup = re.sub(r"<\?php if \(\$viewKind === 'task'\): \?>.*?<\?php endif; \?>", '', markup, flags=re.S)
    markup = re.sub(r"<\?php if \(\$viewKind === 'project'\): \?>.*?<\?php else: \?>(.*?)<\?php endif; \?>", r'\1', markup, flags=re.S)
    markup = markup.replace("<?= htmlspecialchars($viewPageId, ENT_QUOTES, 'UTF-8') ?>", PID)
    assert '<?' not in markup, 'view changed: refresh explicit synthetic branch handling'
    print('SKIP PHP view execution and Doc::validate (--skip-php)', flush=True)
else:
    markup = php('$s=file_get_contents("src/community/handlers/page_view.php");$s=substr($s,strpos($s,"?>")+2);$s=substr($s,0,strrpos($s,"<?php"));$viewKind="doc";$viewPageId="' + PID + '";eval("?>".$s);')
html = '<!doctype html><html lang="ko" data-design="aurora" data-theme="dark"><head><meta charset="utf-8">' + ''.join('<link rel="stylesheet" href="/tbox/assets/' + s + '">' for s in ['app.css', 'community/community.css', 'design.css', 'moa.css']) + '</head><body data-page="community-page" data-community-base="/community" data-base="/tbox"><main id="main">' + markup + '</main><script type="module" src="/tbox/assets/community/editor.js"></script></body></html>'
saved = {'schema': 1, 'blocks': [{'id': PID, 'type': 'paragraph', 'text': []}]}
revision = 1
writes = []

with sync_playwright() as pw:
    browser = pw.chromium.launch()
    page = browser.new_page(viewport={'width': 1280, 'height': 900})
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))

    def route(r):
        global saved, revision
        path = urlsplit(r.request.url).path
        if path.startswith('/tbox/assets/'):
            f = ROOT / 'public/assets' / path.removeprefix('/tbox/assets/')
            body = f.read_bytes()
            if f.name == 'editor.js':
                # Test-only export of the unchanged private production task editor.
                body += b'\nexport { mountPaneEditor as clipboardTestMount };\n'
            r.fulfill(body=body, content_type='text/javascript' if f.suffix == '.js' else 'text/css')
        elif path.startswith('/community/p/'):
            r.fulfill(body=html, content_type='text/html', headers={'Content-Security-Policy': "default-src 'self';script-src 'self';style-src 'self';img-src 'self' data:"})
        elif path == '/tbox/api/me':
            r.fulfill(json={'username': 'synthetic', 'csrf_token': 'synthetic-csrf', 'can_community_write': True})
        elif path == '/community/api/pages/' + PID:
            if r.request.method == 'PATCH':
                data = r.request.post_data_json
                validate(data['doc'])
                saved = data['doc']
                writes.append(data)
                revision += 1
                r.fulfill(json={'doc_version': revision, 'title': '합성 붙여넣기', 'buttons': {}, 'db_views': {}})
            else:
                r.fulfill(json={'page': {'id': PID, 'title': '합성 붙여넣기', 'doc': saved, 'doc_version': revision, 'visibility': 'private', 'owner': {'username': 'synthetic'}}, 'can_edit': True, 'can_manage': False, 'can_comment': False, 'mention_labels': {}, 'backlinks': []})
        else:
            r.fulfill(json={'items': [], 'pages': [], 'spaces': [], 'favorites': [], 'recent': [], 'palettes': [], 'comments': [], 'reads': [], 'versions': [], 'templates': [], 'procedures': [], 'integrations': [], 'buttons': []})

    page.route('**/*', route)
    page.add_init_script('''window.pasteHTML = ({html, text}) => {
      const data = new DataTransfer();
      if (html !== null) data.setData('text/html', html);
      data.setData('text/plain', text || '선택 복사');
      document.querySelector('#page-editor .ProseMirror').dispatchEvent(new ClipboardEvent('paste', {bubbles:true, cancelable:true, clipboardData:data}));
    };''')
    page.goto('https://clipboard.example/community/p/' + PID, wait_until='networkidle')
    page.wait_for_selector('#page-edit:visible')
    page.evaluate('''async () => {
      window.clip = await import('/tbox/assets/community/editor-schema.js');
      window.renderer = await import('/tbox/assets/community/doc-render.js');
      window.parseHTML = html => clip.toDoc(clip.clipboardDomParser.parse(new DOMParser().parseFromString(html, 'text/html').body).toJSON());
    }''')

    def parse(value):
        doc = page.evaluate('html => parseHTML(html)', value)
        validate(doc)
        return doc

    sample = parse(SAMPLE)
    if args.render_only:
        saved = sample
        page.reload(wait_until='networkidle')
        for width in [1280, 390]:
            page.set_viewport_size({'width': width, 'height': 900})
            cell = page.locator('#page-doc table tr').nth(2).locator('td').first
            check(cell.inner_text().splitlines() == ['첫 문단', '다음', '줄'], 'reading cell visibly retains three lines at ' + str(width))
            check(cell.evaluate('(n) => n.getBoundingClientRect().height >= parseFloat(getComputedStyle(n).lineHeight)*3'), 'reading cell has three-line height at ' + str(width))
            page.screenshot(path=str(OUT / ('clipboard-rendered-' + str(width) + '.png')))
        page.set_viewport_size({'width': 1280, 'height': 900})
        page.click('#page-edit')
        page.locator('#page-editor [data-atom=table]').dblclick()
        preview = page.locator('.table-cell-preview').filter(has_text='첫 문단').first
        check(preview.inner_text().splitlines() == ['첫 문단', '다음', '줄'], 'selected-cell preview preserves visible line breaks')
        check(not errors, 'focused render path has no JavaScript errors')
        (OUT / 'results.json').write_text(json.dumps({'passed': len(checks), 'checks': checks, 'php_validations': len(validated), 'php_skipped': args.skip_php}, ensure_ascii=False, indent=2), encoding='utf-8')
        print(str(len(checks)) + ' focused render checks passed; PHP validations ' + str(len(validated)), flush=True)
        browser.close()
        sys.exit(0)
    tables = [b for b in sample['blocks'] if b['type'] == 'table']
    check(len(tables) == 1, 'selected-answer HTML preserves one 3-column table')
    check(any(s['t'] == '77.5%' and s['m'].get('bold') for b in sample['blocks'] for s in b.get('text', [])), 'numeric font-weight 700 stays bold')
    if args.baseline:
        (OUT / 'baseline.json').write_text(json.dumps({'passed': checks, 'failures': failures}, ensure_ascii=False, indent=2), encoding='utf-8')
        browser.close()
        raise SystemExit(1 if failures else 0)

    table = tables[0]
    check(len(table['rows']) == 3 and all(len(r['cells']) == 3 for r in table['rows']) and table['header_row'], 'table shape and header retained')
    check(table['rows'][1]['cells'][1]['text'][0]['m'].get('bold') and table['rows'][1]['cells'][2]['text'][0]['m'].get('bold'), 'strong and numeric weight survive inside cells')
    check(parse('<table><tr><td style="font-weight:700">123</td></tr></table>')['blocks'][0]['rows'][0]['cells'][0]['text'][0]['m'].get('bold'), 'numeric font-weight on cell itself is retained')
    check(''.join(s['t'] for s in table['rows'][2]['cells'][0]['text']) == '첫 문단\n다음\n줄', 'cell paragraphs and explicit line breaks retained')
    check(table['rows'][2]['cells'][1]['text'][0]['m'].get('code') and table['rows'][2]['cells'][2]['text'][0]['m'].get('link'), 'inline code and safe cell link retained')
    check(any(b['type'] == 'bulleted' for b in sample['blocks']) and any('**문자 그대로**' in s['t'] and not s['m'].get('bold') for b in sample['blocks'] for s in b.get('text', [])), 'list and literal Markdown characters unchanged')
    outside_break = parse('<p>밖첫<br>밖둘</p>')
    print('OBSERVATION existing outside-table BR: ' + ''.join(s['t'] for b in outside_break['blocks'] for s in b.get('text', [])), flush=True)
    merged = parse('<table><tr><th rowspan="2">머리</th><td colspan="2">합침</td></tr><tr><td>아래1</td><td>아래2</td></tr></table>')['blocks'][0]
    check(merged['rows'][0]['cells'][0]['rowspan'] == 2 and merged['rows'][0]['cells'][1]['colspan'] == 2 and merged['rows'][0]['cells'][2]['text'] == [] and merged['rows'][1]['cells'][0]['text'] == [], 'merged cells use rectangular grid with empty covered cells')
    unicode_cell = parse('<table><tr><td>' + '😀' * 2001 + '</td></tr></table>')['blocks'][0]['rows'][0]['cells'][0]['text']
    check([len(s['t']) for s in unicode_cell] == [2000, 1], 'cell segments split at 2000 Unicode characters without splitting emoji')
    segments = ''.join(('<b>' if i % 2 else '<i>') + str(i) + ('</b>' if i % 2 else '</i>') for i in range(100))
    check(len(parse('<table><tr><td>' + segments + '</td></tr></table>')['blocks'][0]['rows'][0]['cells'][0]['text']) == 100, '100 rich segments are accepted at the cell boundary')
    hostile = parse('<table><tr><td onclick="alert(1)" style="color:red"><script>BAD_SCRIPT</script><iframe>BAD_IFRAME</iframe><img src="https://evil.example/a"><a href="javascript:alert(1)">안전글자</a><span class="doc-mention nt-red" data-mention-kind="date" data-mention-id="2026-10-16">@</span><div data-atom="file" data-file-ref="tbox:' + PID + '">파일글자</div></td></tr></table>')
    hostile_json = json.dumps(hostile, ensure_ascii=False)
    check(all(x not in hostile_json for x in ['BAD_SCRIPT', 'BAD_IFRAME', 'javascript:', 'evil.example', 'mention', 'file_ref', '"color"']), 'table strips active content, arbitrary styling, forged mentions and atom references')
    check('안전글자' in hostile_json and '파일글자' in hostile_json, 'safe text remains after unsupported markup stripping')
    long_link = parse('<table><tr><td><a href="https://example.org/' + '가' * 1000 + '">링크글자</a></td></tr></table>')
    check(not long_link['blocks'][0]['rows'][0]['cells'][0]['text'][0]['m'].get('link'), 'UTF-8 link byte limit matches PHP validation')
    for name, value, expected in [
        ('nested', '<table><tr><td>바깥<table><tr><td>안쪽</td></tr></table>끝</td></tr></table>', ['바깥', '안쪽', '끝']),
        ('ragged', '<table><tr><td>가</td><td>나</td></tr><tr><td>다</td></tr></table>', ['가', '나', '다']),
        ('out-of-range span', '<table><tr><td rowspan="2">범위밖</td></tr></table>', ['범위밖']),
        ('overlapping spans', '<table><tr><td>가</td><td rowspan="2">나</td></tr><tr><td colspan="2">다</td></tr></table>', ['가', '나', '다']),
        ('caption', '<table><caption>제목</caption><tr><td>본문</td></tr></table>', ['제목', '본문']),
        ('oversize segments', '<table><tr><td>' + segments + '<i>마지막</i></td></tr></table>', ['마지막']),
        ('oversize rows', '<table>' + ''.join('<tr><td>행' + str(i) + '</td></tr>' for i in range(201)) + '</table>', ['행0', '행200']),
        ('oversize columns', '<table><tr>' + ''.join('<td>열' + str(i) + '</td>' for i in range(21)) + '</tr></table>', ['열0', '열20']),
        ('oversize cell text', '<table><tr><td>' + '가' * 200001 + '</td></tr></table>', ['가' * 200001]),
    ]:
        fallback = parse(value)
        text = ''.join(s['t'] for b in fallback['blocks'] for s in b.get('text', []))
        check(not any(b['type'] == 'table' for b in fallback['blocks']) and all(x in text for x in expected), name + ' table safely falls back without losing text')
    shape = json.loads((ROOT / 'tests/community/fixtures/editor_surface.json').read_text(encoding='utf-8'))
    check(page.evaluate('(d) => JSON.stringify(clip.toDoc(clip.fromDoc(d,{}))) === JSON.stringify(d)', {'schema': 1, 'blocks': [b for b in shape['blocks'] if b['type'] == 'table']}), 'existing raw rich tables remain byte-equivalent JSON')
    check(page.evaluate('''() => { const n = new DOMParser().parseFromString('<p><span class="doc-mention" data-mention-kind="date" data-mention-id="2026-10-16">@</span></p>', 'text/html').body; return JSON.stringify(clip.editorDomParser.parse(n).toJSON()).includes('"mention"') && !JSON.stringify(clip.clipboardDomParser.parse(n).toJSON()).includes('"mention"'); }'''), 'own DOM retains mentions while clipboard rejects forged mentions')
    rendered = page.evaluate('d => {const n=document.createElement("div");n.append(renderer.renderDoc(d,{}));return {tables:n.querySelectorAll("table").length,bold:n.querySelectorAll("strong").length,text:n.textContent};}', sample)
    check(rendered['tables'] == 1 and rendered['bold'] >= 4 and '120' in rendered['text'], 'production renderer displays table and numeric bold')

    page.click('#page-edit')
    page.wait_for_selector('#page-editor .ProseMirror')
    page.locator('#page-editor .ProseMirror').click()
    page.evaluate('v => pasteHTML(v)', {'html': SAMPLE, 'text': '선택한 비교 답변'})
    page.wait_for_timeout(1800)
    check(bool(writes) and sum(b['type'] == 'table' for b in saved['blocks']) == 1, 'actual editor paste transaction auto-saves canonical table')
    pasted = json.loads(json.dumps(saved))
    page.keyboard.press('Control+z')
    page.wait_for_timeout(1800)
    check(not any(b['type'] == 'table' for b in saved['blocks']), 'single undo removes pasted table')
    page.keyboard.press('Control+Shift+z')
    page.wait_for_timeout(1800)
    check(saved == pasted, 'redo restores saved rich paste exactly')
    page.reload(wait_until='networkidle')
    check(page.locator('#page-doc table').count() == 1 and page.locator('#page-doc table tr').count() == 3, 'saved answer displays a real table in reading mode')
    page.screenshot(path=str(OUT / 'clipboard-rendered.png'))
    page.click('#page-edit')
    page.wait_for_selector('#page-editor [data-atom=table]')
    check(saved == pasted, 'saved rich table reopens after reload')
    for shifted in [False, True]:
        editor = page.locator('#page-editor .ProseMirror p').last
        editor.click()
        page.keyboard.press('End')
        if shifted:
            page.keyboard.down('Shift')
        page.evaluate('v => pasteHTML(v)', {'html': '<table><tr><td>사용안함</td></tr></table>' if shifted else None, 'text': '**평문** <table>문자</table>'})
        if shifted:
            page.keyboard.up('Shift')
        page.wait_for_timeout(1800)
        check(sum(b['type'] == 'table' for b in saved['blocks']) == 1 and any('**평문** <table>문자</table>' in s['t'] and not s['m'].get('bold') for b in saved['blocks'] for s in b.get('text', [])), ('Ctrl+Shift+V' if shifted else 'text/plain') + ' keeps literal text paste behavior')
    page.locator('#page-editor [data-atom=table]').dblclick()
    page.wait_for_selector('.table-cell-input .ProseMirror')
    cell_editor = page.locator('.table-cell-input .ProseMirror')
    cell_editor.click()
    page.keyboard.press('Control+a')
    cell_editor.evaluate('''n => { const data=new DataTransfer();data.setData('text/html','<table><tr><td><strong>111</strong></td><td>둘</td></tr><tr><td>셋</td><td><code>444</code></td></tr></table>');n.dispatchEvent(new ClipboardEvent('paste',{bubbles:true,cancelable:true,clipboardData:data})); }''')
    page.click('#atom-commit')
    page.wait_for_timeout(1800)
    cell = next(b for b in saved['blocks'] if b['type'] == 'table')['rows'][0]['cells'][0]['text']
    check(''.join(s['t'] for s in cell) == '111 | 둘\n셋 | 444' and any(s['m'].get('bold') and s['t'] == '111' for s in cell) and any(s['m'].get('code') and s['t'] == '444' for s in cell), 'table pasted into selected cell keeps reading order and rich text without nested atom')
    page.evaluate('''async config => {
      window.taskModule = await import('/tbox/assets/community/editor.js');
      window.taskMount = document.createElement('div');taskMount.id='synthetic-task-description';document.body.append(taskMount);
      window.taskErrors=[];window.taskSaved=null;
      window.taskOptions = {pageId:config.id,doc:{schema:1,blocks:[]},version:config.version,titles:{},onState:()=>{},onSaved:d=>{window.taskSaved=d;},onError:e=>taskErrors.push(e)};
      window.taskEditor=taskModule.clipboardTestMount(taskMount,taskOptions);
      taskEditor.focus();
    }''', {'id': PID, 'version': revision})
    page.locator('#synthetic-task-description .ProseMirror').evaluate('''(n,html) => {const data=new DataTransfer();data.setData('text/html',html);data.setData('text/plain','선택 답변');n.dispatchEvent(new ClipboardEvent('paste',{bubbles:true,cancelable:true,clipboardData:data}));}''', SAMPLE)
    page.wait_for_timeout(1800)
    task_saved = page.evaluate('taskSaved')
    check(task_saved == saved and sum(b['type'] == 'table' for b in task_saved['blocks']) == 1 and not page.evaluate('taskErrors'), 'production mountPaneEditor task description paste auto-saves table and rich text')
    page.evaluate('d => {taskEditor.destroy();taskOptions.doc=d;taskEditor=taskModule.clipboardTestMount(taskMount,taskOptions);}', task_saved)
    check(page.locator('#synthetic-task-description [data-atom=table]').count() == 1, 'task description reopens saved table through production editor')
    page.evaluate('taskEditor.destroy();taskMount.remove()')
    check(not errors, 'actual editor has no JavaScript errors')
    page.screenshot(path=str(OUT / 'clipboard-editor.png'))
    browser.close()

(OUT / 'results.json').write_text(json.dumps({'passed': len(checks), 'checks': checks, 'php_validations': len(validated), 'php_skipped': args.skip_php}, ensure_ascii=False, indent=2), encoding='utf-8')
print(str(len(checks)) + ' checks passed; PHP validations ' + str(len(validated)), flush=True)
