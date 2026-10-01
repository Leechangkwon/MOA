#!/usr/bin/env python3
"""C:T-314/315/318/320: parse the actual PHP output, check normal-renderer parity."""
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import uuid
from html.parser import HTMLParser
import yaml
from markdown_it import MarkdownIt

ROOT = Path(__file__).resolve().parents[2]
CHECKS = []


def check(ok, name):
    CHECKS.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    if not ok:
        raise AssertionError(name)


class Inspect(HTMLParser):
    def __init__(self):
        super().__init__(); self.unsafe = []; self.text = []; self.links = []

    def handle_starttag(self, tag, attrs):
        if tag in ('script', 'img', 'iframe', 'object', 'embed', 'link', 'base', 'form'):
            self.unsafe.append(tag)
        for key, value in attrs:
            if key.startswith('on') or (key in ('src', 'href') and re.match(r'\s*(javascript|data|vbscript):', value or '', re.I)):
                self.unsafe.append(key)
            if key == 'href': self.links.append(value)

    def handle_data(self, data): self.text.append(data)


def seg(text, **marks): return {'t': text, 'm': marks}


def block(kind, text=None, **attrs):
    result = {'type': kind, 'id': str(uuid.uuid4()), **attrs}
    if text is not None: result['text'] = text if isinstance(text, list) else [seg(text)]
    return result


def run():
    poison = '<script>alert(1)</script><img src=x onerror=alert(1)> [x](javascript:alert(1)) ` `` ```` \\|\n---\n# title\n1. list\n&copy;'
    hidden = str(uuid.uuid4()); shown = str(uuid.uuid4()); fhidden = str(uuid.uuid4()); fshown = str(uuid.uuid4())
    blocks = [block('paragraph', poison), block('heading', '제목', level=2), block('bulleted', '글머리'),
              block('numbered', '번호'), block('to_do', '할 일', checked=True), block('quote', '인용'),
              block('callout', '안내', icon='✓'), block('divider'), block('code', code='```\n<script>x</script>\n````', language='plain'),
              block('toc'), block('toggle', '접기', children=[block('paragraph', '펼친 내용')]),
              block('column_list', children=[block('column', children=[block('paragraph', '왼쪽')]), block('column', children=[block('paragraph', '오른쪽')])]),
              block('table', header_row=True, rows=[{'cells': [{'text': [seg('a|b')], 'colspan': 2}, {'text': [seg('덮인 셀은 없음')]}]},
                                                   {'cells': [{'text': [seg('1')]}, {'text': [seg('2')]}]}]),
              block('image', file_ref='tbox:' + fhidden, caption=[seg('SECRET_FILE_CAPTION')]),
              block('file', file_ref='tbox:' + fshown), block('child_page', page_id=hidden), block('link_to_page', page_id=shown),
              block('embed', url='https://secret.invalid/private', caption=[seg('SECRET_EMBED_CAPTION')]),
              block('embed', url='https://allowed.example/watch'),
              block('paragraph', **{'text': [seg('SECRET_LINK_LABEL', link={'href': 'page:' + hidden}), seg('@', mention={'kind':'page','id':hidden}),
                  seg('@', mention={'kind':'date','id':'2026-10-01'}), seg('<b>code ` fence</b>', code=True), seg('bold',bold=True), seg('italic',italic=True)]}),
              block('heading', level=1, **{'text': [seg('SECRET_TOC_LABEL', link={'href':'page:' + hidden})]})]
    # Include a normal image and mention to exercise unchanged renderer and export links.
    blocks += [block('image', file_ref='tbox:' + fshown), block('paragraph', **{'text':[seg('@',mention={'kind':'page','id':shown})]})]
    ctx = {'child_titles': {}, 'link_titles': {shown: {'title':'연결 문서','icon':None}},
           'page_href': {shown: '../02-page.md'}, 'file_titles': {fshown:{'name':'공유파일.png','size':1200,'image':True}},
           'file_href': {fshown:'https://site.example/tbox/api/files/'+fshown+'/download'},
           'mention_labels': {'page:'+shown:{'label':'연결 문서'}}, 'embeds': {'hosts':['allowed.example'],'frames':False}}
    meta = {'title': '"\n---\nkind: x\n' + poison + '\x01', 'source': 'https://site.example/community/p/' + shown,
            'kind':'page','exported_at':'2026-10-01T00:00:00Z','owner':'검증 담당','tags':['한글','quote"tag']}
    input_data = {'doc': {'schema':1,'blocks':blocks}, 'ctx':ctx,'meta':meta,
                  'names':['../../etc/passwd','a/b','', '같은 제목','같은 제목','한'*150,'CON','NUL.txt','x\r\nInjected:\x01']}
    # Fixed tag is the baseline named in the coordination handoff.
    original = subprocess.check_output(['git','show','stage89-8b-baseline-20261001:src/community/DocRender.php'], cwd=ROOT).decode()
    with tempfile.TemporaryDirectory(prefix='export-renderer-') as tmp:
        baseline = Path(tmp)/'baseline.php'
        baseline.write_text(original.replace('final class DocRender','final class BaselineDocRender'),encoding='utf-8')
        result = subprocess.run(['php',str(ROOT/'tests/community/export_convert.php')], cwd=ROOT,
                    env=dict(os.environ, EXPORT_BASELINE_RENDERER=str(baseline)), input=json.dumps(input_data,ensure_ascii=False).encode(),
                    stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if result.returncode: raise RuntimeError(result.stderr.decode())
    out = json.loads(result.stdout)
    check(out['parity'], 'normal renderer exactly matches baseline with all supported blocks')
    front = out['md'].split('---\n',2)[1]
    decoded = yaml.safe_load(front)
    check(decoded['title'] == meta['title'] and decoded['kind']=='page', 'YAML injection preserves one frontmatter and exact title')
    parser = MarkdownIt('commonmark', {'html':True}).enable('table')
    rendered = parser.render(out['md'])
    for name, source in [('Markdown',rendered),('HTML',out['html'])]:
        dom = Inspect(); dom.feed(source)
        check(not dom.unsafe, name+' has no executable tags/attributes/URLs after parsing')
        check(all(s not in source for s in [hidden,fhidden,'SECRET_LINK_LABEL','SECRET_TOC_LABEL','SECRET_FILE_CAPTION','SECRET_EMBED_CAPTION','secret.invalid']), name+' removes unreadable resource identities and cached labels')
        check('../02-page.md' in dom.links and any(fshown in h for h in dom.links),name+' keeps approved relative and file links')
    check('![정상' not in out['md'] and '![' not in out['md'].replace('\\![',''), 'Markdown has no auto-loading image syntax')
    check('default-src &#039;none&#039;' in out['html'], 'standalone HTML has restrictive CSP')
    check('합친 칸 있음' in out['md'] and '덮인 셀은 없음' not in out['md'], 'merged table preserves anchors and blanks covered cells')
    check(all('..' not in n and '/' not in n and '\\' not in n and len(n)<=80 and not re.search(r'[\x00-\x1f\x7f]',n) for n in out['names']), 'all file names bounded and traversal-free')
    check(out['names'][4]=='같은 제목-2.md' and len(set(out['names']))==len(out['names']), 'duplicate names have stable unique suffix')
    # Even when a frontmatter-aware reader strips YAML, prose remains literal.
    plain = Inspect(); plain.feed(parser.render(out['md'].split('---\n',2)[2]))
    check('<script>alert(1)</script><img src=x onerror=alert(1)>' in ''.join(plain.text), 'HTML-looking original prose preserved as visible text')
    print('CONVERTER_CHECKS=' + str(len(CHECKS)))


if __name__ == '__main__': run()
