#!/usr/bin/env python3
"""Focused shipped-asset DOM checks with synthetic HTTP; no PHP/DB integration claim."""
import base64
import copy
import json
import os
from pathlib import Path
import re
import subprocess
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ORIGIN = 'http://127.0.0.1:8766'
PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jK1sAAAAASUVORK5CYII=')


def story_fixture():
    source = (ROOT / 'src/community/handlers/memories_page.php').read_text()
    source = source[source.index('<section id="community-threads"'):source.index("<?php if ($isShared): ?></body>")]
    source = source.replace("<?= $e(rtrim(Http::communityUrl('/'), '/')) ?>", '/community')
    source = re.sub(r"<\?= Http::url\('([^']*)'\) \?>", lambda m: '/tbox' + m[1], source)
    source = re.sub(r'<\?(?:php|=).*?\?>', '', source, flags=re.S)
    css = ''.join('<link rel="stylesheet" href="/tbox/assets/' + name + '">' for name in ['app.css', 'moa.css', 'community/community.css', 'community/memories.css', 'design.css'])
    return '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">' + css + '</head><body data-service="story" data-page="community-threads"><main class="main">' + source + '</main></body></html>'


def record():
    return {'id': '11111111-1111-4111-8111-111111111111', 'caption': '합성 사진 기록', 'location': '합성 장소', 'trip': '', 'taken_on': '2026-11-19', 'visibility': 'private', 'created_at': '2026-11-19T05:00:00Z', 'username': 'fixture', 'display_name': '합성 사용자', 'revision': 1, 'mine': True, 'likes': 0, 'comments': 0, 'liked': False, 'media': [{'id': '22222222-2222-4222-8222-222222222222', 'mime': 'image/png', 'filename': 'fixture.png', 'bytes': len(PNG), 'url': '/community/api/memories/media/fixture'}]}


class Mock:
    def __init__(self):
        self.posts = []
        self.next_offset = None

    def route(self, route):
        path = urlparse(route.request.url).path
        if path.startswith('/tbox/assets/'):
            file = ROOT / 'public/assets' / path.removeprefix('/tbox/assets/')
            return route.fulfill(path=str(file)) if file.is_file() else route.fulfill(status=404)
        if '/media/' in path:
            return route.fulfill(content_type='image/png', body=PNG)
        if path == '/community/api/memories':
            return route.fulfill(json={'posts': self.posts, 'next_offset': self.next_offset, 'stats': {}, 'me': {'username': 'fixture', 'display_name': '합성 사용자', 'can_write': True, 'csrf_token': 'synthetic'}})
        if path == '/community/memories':
            return route.fulfill(body=story_fixture(), content_type='text/html', headers={'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'"})
        return route.fulfill(status=404, json={})


def php_fragment(source, prefix=''):
    return subprocess.check_output(['php', '-r', prefix + '?>' + source], text=True)


def document_fixture():
    source = (ROOT / 'src/community/handlers/home_page.php').read_text()
    head = source[source.index('    <header class="page-head">'):source.index('    </header>') + len('    </header>')]
    head = php_fragment(head, "$homeWriteHidden='';")
    usage = subprocess.check_output([
        'php', '-r', "class Authz { public static function canCommunityWrite($u) { return true; } } class Http { public static function communityUrl($p) { return '/community'.$p; } public static function url($p) { return '/tbox'.$p; } } $user=[]; $moaGuide='community'; include 'src/views/moa_usage.php';"
    ], cwd=ROOT, text=True)
    return '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">' + ''.join(
        '<link rel="stylesheet" href="/tbox/assets/' + name + '">' for name in ['app.css', 'moa.css', 'community/community.css', 'design.css']
    ) + '</head><body data-page="community-home"><main class="main">' + head + usage + '<section id="following-content" class="panel">문서 목록</section></main></body></html>'


CONTRAST = """() => {
  const n = document.querySelector('#empty-copy'), box = n.closest('.empty');
  const rgb = value => [...value.matchAll(/[\\d.]+/g)].slice(0,3).map(x => Number(x[0])/255);
  const luminance = color => rgb(color).map(v => v <= .04045 ? v/12.92 : ((v+.055)/1.055)**2.4).reduce((s,v,i) => s+v*[.2126,.7152,.0722][i],0);
  const fg=luminance(getComputedStyle(n).color), bg=luminance(getComputedStyle(box).backgroundColor);
  return (Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05);
}"""


def main():
    count = 0
    results = []

    def check(ok, label):
        nonlocal count
        assert ok, label
        count += 1

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'), args=['--no-sandbox'])
        for width in [390, 1280]:
            context = browser.new_context(viewport={'width': width, 'height': 900}, reduced_motion='reduce')
            page = context.new_page()
            errors, held = [], []
            page.on('pageerror', lambda err: errors.append(str(err)))
            mock = Mock()

            def route_request(route):
                path = urlparse(route.request.url).path
                if path == '/documents':
                    return route.fulfill(body=document_fixture(), content_type='text/html', headers={'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'"})
                if path == '/community/api/memories' and route.request.method == 'GET':
                    held.append(route)
                    return
                return mock.route(route)

            context.route(ORIGIN + '/**', route_request)
            page.goto(ORIGIN + '/documents')
            actions = page.locator('.page-head__actions').bounding_box()
            content = page.locator('#following-content').bounding_box()
            page.evaluate("document.querySelector('#community-space-sub').textContent='메모·업무 방법·인수인계를 문서로 남기세요. 개인 문서는 비공개로 시작하고, 문서마다 공개 범위를 고를 수 있어요.'")
            check(page.locator('.page-head__actions').bounding_box() == actions, f'{width}: final document subtitle keeps action geometry')
            check(page.locator('#following-content').bounding_box() == content, f'{width}: final document subtitle keeps following content geometry')
            check(not page.locator('details.moa-purpose').evaluate('(n) => n.open'), f'{width}: onboarding starts compact')
            page.locator('details.moa-purpose > summary').click()
            check(page.locator('[data-moa-action="community-new"]').is_visible(), f'{width}: expanded onboarding retains document action')
            page.locator('details.moa-purpose > summary').focus()
            page.keyboard.press('Enter')
            check(not page.locator('details.moa-purpose').evaluate('(n) => n.open'), f'{width}: native keyboard collapse')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'{width}: compact document header fits')

            # Use the shipped renderer, including transitive imports, under the same CSP.
            image_settings = page.evaluate("""async () => {
              const {renderDoc} = await import('/tbox/assets/community/doc-render.js');
              const id='20000000-0000-4000-8000-000000000000';
              const image=key=>({id:key,type:'image',file_ref:'tbox:'+id});
              const fragment=renderDoc({schema:1,blocks:[{id:'intro',type:'paragraph',text:[{t:'합성 소개'}]},image('lead'),image('later')]}, {tbox_base:'/tbox',file_titles:{[id]:{name:'fixture.png',image:true}}});
              return [...fragment.querySelectorAll('img')].map(n=>({loading:n.loading,priority:n.fetchPriority,decode:n.decoding,width:n.hasAttribute('width'),height:n.hasAttribute('height')}));
            }""")
            check(image_settings[0]['loading'] == 'eager' and image_settings[0]['priority'] == 'high', f'{width}: leading document image prioritized')
            check(image_settings[1]['loading'] == 'lazy' and image_settings[1]['decode'] == 'async', f'{width}: later document image lazy/async')
            check(not any(n['width'] or n['height'] for n in image_settings), f'{width}: absent dimension metadata is not invented')

            pane_results = page.evaluate("""async () => {
              const {createWork}=await import('/tbox/assets/community/work.js');
              document.body.dataset.pageKind='task';
              const requests=[], results=[], long='긴 작업 제목 '.repeat(30);
              for (const wb of [null,{exists:false,can_create:false},{exists:false,can_create:true,url:'/permitted-create'},{exists:true,can_create:false,url:'/permitted-board',version_no:7}]) {
                document.querySelector('#task-full')?.remove();
                const host=document.createElement('section');host.id='task-full';host.className='task-pane task-pane--full';document.querySelector('main').append(host);
                const work=createWork({api:async(method,path)=>{requests.push(method);return {status:200,data:{comments:[],events:[],pages:[]}};},base:'/community',tboxBase:'/tbox',canWrite:()=>false,setText:(n,t)=>{if(n)n.textContent=t;},show:(n,v)=>{if(n)n.hidden=!v;},clear:n=>n.replaceChildren(),errorMessage:()=>'',genericError:'합성 오류',toLogin:()=>{},statusLabels:{},personLabel:()=>'',shortWhen:()=>'',makeIcon:()=>document.createElement('span')});
                work.pageLoaded('10000000-0000-4000-8000-000000000000',{page:{title:long,doc:{schema:1,blocks:[]}},children:[{id:'10000000-0000-4000-8000-000000000001',title:long}],whiteboard:wb});
                const link=host.querySelector('[data-task-whiteboard]');
                const title=host.querySelector('.task-row__title');
                results.push({links:host.querySelectorAll('[data-task-whiteboard]').length,inHead:Boolean(link?.closest('.task-pane__head')),href:link?.getAttribute('href'),name:link?.getAttribute('aria-label'),fullTitle:title?.textContent===long && title?.title===long,nowrap:title?getComputedStyle(title).whiteSpace:null,overflow:[...host.querySelectorAll('.task-pane__head,.task-pane__title,.task-row')].some(n=>n.getBoundingClientRect().right>host.getBoundingClientRect().right+1)});
              }
              document.querySelector('#task-full').remove();
              const host=document.createElement('section');host.id='task-full';host.className='task-pane task-pane--full';document.querySelector('main').append(host);
              const patches=[];
              const edit=createWork({api:async(method,path,payload)=>{if(method==='PATCH')patches.push(payload);return {status:method==='PATCH'?409:200,data:{comments:[],events:[],pages:[]}};},base:'/community',tboxBase:'/tbox',canWrite:()=>true,setText:(n,t)=>{if(n)n.textContent=t;},show:(n,v)=>{if(n)n.hidden=!v;},clear:n=>n.replaceChildren(),errorMessage:()=>'',genericError:'합성 충돌',toLogin:()=>{},statusLabels:Object.fromEntries(['todo','doing','review','done','hold'].map(k=>[k,{text:k,badge:''}])),personLabel:()=>'',shortWhen:()=>'',makeIcon:()=>document.createElement('span')});
              edit.pageLoaded('10000000-0000-4000-8000-000000000000',{page:{title:'합성 작업',doc_version:1,doc:{schema:1,blocks:[]}},children:[],can_edit:true});
              host.querySelector('.task-pane__title').click();
              const input=host.querySelector('.task-pane__title-edit');input.value='한글 조합을 마친 제목';
              const composing=input.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',isComposing:true,bubbles:true,cancelable:true}));
              const legacy=input.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',keyCode:229,bubbles:true,cancelable:true}));
              const escape=input.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',keyCode:229,bubbles:true,cancelable:true}));
              const untouched=patches.length===0 && input.isConnected && input.value==='한글 조합을 마친 제목';
              input.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter',keyCode:13,bubbles:true,cancelable:true}));
              await Promise.resolve();
              return {results,writes:requests.filter(m=>m!=='GET').length,ime:{composing,legacy,escape,untouched,patches:patches.length,title:patches[0]?.title}};
            }""")
            check([n['links'] for n in pane_results['results']] == [0, 0, 1, 1], f'{width}: whiteboard summary gating preserved')
            check(all(n['inHead'] for n in pane_results['results'][2:]), f'{width}: permitted board entry in task header')
            check(pane_results['results'][2]['href'] == '/permitted-create' and pane_results['results'][3]['href'] == '/permitted-board', f'{width}: server action URLs retained')
            check('판 7' in pane_results['results'][3]['name'], f'{width}: board version accessible')
            check(all(n['fullTitle'] and n['nowrap'] == 'nowrap' for n in pane_results['results']), f'{width}: one-line titles retain full DOM/title')
            check(not any(n['overflow'] for n in pane_results['results']), f'{width}: long task title and board entry fit')
            check(pane_results['writes'] == 0, f'{width}: opening board entry never creates or writes')
            check(all(pane_results['ime'][k] for k in ['composing', 'legacy', 'escape', 'untouched']), f'{width}: IME Enter/Escape preserves text and default behavior')
            check(pane_results['ime']['patches'] == 1 and pane_results['ime']['title'] == '한글 조합을 마친 제목', f'{width}: ordinary Enter still saves title once')

            page.goto(ORIGIN + '/community/memories', wait_until='domcontentloaded')
            page.wait_for_function("document.querySelector('#feed-status').textContent === '게시글을 불러오는 중…'")
            before = page.locator('.main-columns').bounding_box()
            messenger = page.locator('.threads-quick > [data-messenger-open]').bounding_box()
            check(bool(held), 'synthetic initial response held')
            mock.route(held.pop())
            page.locator('#empty').wait_for(state='visible')
            after = page.locator('.main-columns').bounding_box()
            messenger_after = page.locator('.threads-quick > [data-messenger-open]').bounding_box()
            check(before['y'] == after['y'], f'{width}: async writer controls keep feed start {before} -> {after}')
            check(messenger == messenger_after, f'{width}: async writer controls keep messenger geometry')
            ratios = []
            for design in ['claude', 'moa', 'astra', 'aurora', 'bloom', 'nova']:
                for theme, contrast in [('light', 'normal'), ('dark', 'normal'), ('light', 'high'), ('dark', 'high')]:
                    page.evaluate("([design,theme,contrast])=>{document.documentElement.dataset.design=design;document.documentElement.dataset.theme=theme;document.documentElement.dataset.contrast=contrast;}", [design, theme, contrast])
                    ratio = page.evaluate(CONTRAST)
                    ratios.append({'design': design, 'theme': theme, 'contrast': contrast, 'ratio': round(ratio, 2)})
                    check(ratio >= 4.5, f'{width}: empty copy contrast {design}/{theme}/{contrast}: {ratio}')

            mock.posts = [copy.deepcopy(record()) for _ in range(6)]
            for index, post in enumerate(mock.posts):
                post['id'] = f'10000000-0000-4000-8000-{index:012d}'
            mock.next_offset = 6
            page.locator('#refresh').click()
            page.wait_for_function("document.querySelector('#feed-status').textContent === '게시글을 불러오는 중…'")
            for _ in range(20):
                if held:
                    break
                page.wait_for_timeout(50)
            check(bool(held), f'{width}: requested next synthetic response, status={page.locator("#feed-status").text_content()}, clicked={page.evaluate("window.__loadMoreClicked")}, errors={errors}')
            mock.route(held.pop())
            page.locator('#feed > .post').nth(5).wait_for(state='attached')
            check(page.locator('#feed > .post').nth(0).locator('img').get_attribute('loading') == 'eager', f'{width}: first preview eager')
            check(page.locator('#feed > .post').nth(0).locator('img').get_attribute('fetchpriority') == 'high', f'{width}: first preview prioritized')
            check(page.locator('#feed > .post').nth(1).locator('img').get_attribute('loading') == 'lazy', f'{width}: later preview lazy')
            check(page.locator('#feed > .post').nth(0).evaluate("n => getComputedStyle(n).contentVisibility") == 'visible', f'{width}: first card always rendered')
            fifth = page.locator('#feed > .post').nth(4)
            check(fifth.evaluate("n => getComputedStyle(n).contentVisibility") == 'auto', f'{width}: independent later card containment')
            fifth.locator('.read-more').focus()
            check(fifth.evaluate("n => getComputedStyle(n).contentVisibility") == 'visible', f'{width}: focused card always rendered')
            page.evaluate("window.__firstPost=document.querySelector('#feed > .post');window.__focusedPost=document.activeElement;")
            mock.posts = [copy.deepcopy(record()) for _ in range(2)]
            for index, post in enumerate(mock.posts):
                post['id'] = f'10000000-0000-4000-8000-{index + 6:012d}'
            mock.next_offset = None
            page.evaluate("document.querySelector('#load-more').addEventListener('click',()=>window.__loadMoreClicked=true);")
            page.locator('#load-more').click()
            page.wait_for_function("document.querySelector('#feed-status').textContent === '게시글을 불러오는 중…'")
            for _ in range(20):
                if held:
                    break
                page.wait_for_timeout(50)
            check(bool(held), f'{width}: requested next synthetic response, status={page.locator("#feed-status").text_content()}, clicked={page.evaluate("window.__loadMoreClicked")}, errors={errors}')
            mock.route(held.pop())
            page.wait_for_function("document.querySelector('#feed').children.length===8")
            check(page.evaluate("window.__firstPost===document.querySelector('#feed > .post') && window.__focusedPost.isConnected"), f'{width}: append preserves existing cards and their measured sizes')
            check(page.locator('#feed > .post').nth(7).locator('img').get_attribute('loading') == 'lazy', f'{width}: appended media stays lazy')
            check(not errors, f'{width}: no shipped JavaScript errors: {errors}')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'{width}: story fits')
            results.append({'width': width, 'feed_y_before': before['y'], 'feed_y_after': after['y'], 'contrast': ratios})
            context.close()
        browser.close()
    print(json.dumps({'pass': count, 'failed': 0, 'synthetic_geometry': results}, ensure_ascii=False))


if __name__ == '__main__':
    main()
