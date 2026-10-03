#!/usr/bin/env python3
"""Real Chromium UI tests, mocked HTTP only. Does NOT claim PHP/PostgreSQL integration coverage."""
import argparse
import copy
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
from urllib.parse import urlparse, parse_qs
from PIL import Image, ImageDraw
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[2]
ORIGIN = 'http://127.0.0.1:8766'
POST = '11111111-1111-4111-8111-111111111111'
MEDIA = '22222222-2222-4222-8222-222222222222'
TOKEN = 'A' * 43


def render_expressions(source, shared='', profile='', post=''):
    source = re.sub(r"<\?= Http::url\('([^']*)'\) \?>", lambda m: '/tbox' + m[1], source)
    source = re.sub(r"<\?= Http::communityUrl\('([^']*)'\) \?>", lambda m: '/community' + m[1], source)
    for k, v in {'$cssVersion':'test', '$jsVersion':'test', '$e($sharedToken)':shared, '$e($profileName)':profile, '$e($postId)':post}.items():
        source = source.replace('<?= ' + k + ' ?>', v)
    source = source.replace("<?= $e(rtrim(Http::communityUrl('/'), '/')) ?>", '/community')
    source = source.replace("<?= htmlspecialchars(rtrim(Http::communityUrl('/'), '/'), ENT_QUOTES, 'UTF-8') ?>", '/community')
    source = re.sub(r"<\?= \(int\) filemtime\(.*?\) \?>", 'test', source)
    return source


def page_html(shared='', profile='', post=''):
    # Actual thread + modal markup/styles/scripts, with a reduced native-shell fixture.
    # PHP rendering is checked separately when PHP is installed; no server test is claimed here.
    source = (ROOT / 'src/community/handlers/memories_page.php').read_text()
    component = source[source.index('<section id="community-threads"'):source.index("<?php if ($isShared): ?></body>")]
    travel = (ROOT / 'src/community/handlers/travel_plans_panel.php').read_text().replace('<?php declare(strict_types=1); ?>', '')
    component = component.replace("<?php if (!$isShared): require __DIR__ . '/travel_plans_panel.php'; endif; ?>", '' if shared else travel)
    component = re.sub(r'<\?php if \(!\$isShared\): \?>(.*?)<\?php endif; \?>', lambda m: '' if shared else m[1], component, flags=re.S)
    component = render_expressions(component, shared, profile, post)
    css = '<link rel="stylesheet" href="/tbox/assets/app.css"><link rel="stylesheet" href="/tbox/assets/community/community.css"><link rel="stylesheet" href="/tbox/assets/community/memories.css"><link rel="stylesheet" href="/tbox/assets/community/messenger.css"><link rel="stylesheet" href="/tbox/assets/design.css"><link rel="stylesheet" href="/tbox/assets/community/travel-plans.css">'
    head = '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+css+'</head>'
    if shared:
        return head+'<body class="threads-public">'+component+'</body></html>'
    shell = (ROOT / 'src/views/shell_top.php').read_text()
    nav = shell[shell.index('    <p class="nav-title">우리의 이야기'):shell.index('    <p class="nav-title">내 공간')]
    nav = render_expressions(nav)
    wrapper = '<body data-page="community-threads" data-base="/tbox" data-community-base="/community"><div class="app"><header class="appbar"><span class="appbar__brand">커뮤니티</span><button class="btn btn--quiet" data-messenger-open>메신저</button></header><aside class="sidebar"><div class="brand"><span class="brand__name">커뮤니티</span></div><p class="brand__sub">일상 · 이야기 · 함께하는 공간</p>'+nav+'<p class="nav-title">내 공간</p><nav class="nav"><a class="nav__item" href="/community/workspace">내 공간</a><a class="nav__item" href="/community/today">오늘</a><a class="nav__item" href="/community/workspace?space=team">팀 공간</a><a class="nav__item" href="/community/workspace">게시판</a></nav></aside><main id="main" class="main">'
    messenger = render_expressions((ROOT / 'src/views/community_messenger.php').read_text())
    messenger = messenger.replace('<?php declare(strict_types=1); ?>', '')
    result = head+wrapper+component+'</main></div>'+messenger+'</body></html>'
    assert '<?' not in result
    return result


def photo():
    im = Image.new('RGB', (800, 900), '#b4d3cf')
    d = ImageDraw.Draw(im)
    d.ellipse((490, 65, 580, 155), fill='#f9e5b9')
    d.polygon([(0,400),(100,270),(310,370),(550,240),(800,340),(800,900),(0,900)], fill='#819b81')
    d.polygon([(0,610),(200,590),(320,640),(550,570),(800,610),(800,900),(0,900)], fill='#c7dec9')
    d.polygon([(0,720),(400,680),(800,720),(800,900),(0,900)], fill='#7ba4a0')
    out = io.BytesIO(); im.save(out, format='PNG'); return out.getvalue()


def record():
    media = {'id': MEDIA, 'mime': 'image/png', 'filename': '여행-견본.png', 'bytes': len(photo()), 'url': '/community/api/memories/media/' + MEDIA}
    return {'id': POST, 'caption': '낯선 바람이 불어오는 곳에서, 잠시 걸음을 멈췄다.\n오늘의 작은 순간을 오래 기억하고 싶어서.', 'original_caption': '바닷가에 왔다. 바람이 좋다.', 'location': '제주, 세화 해변', 'trip': '가을의 제주', 'taken_on': '2026-10-03', 'visibility': 'shared', 'created_at': '2026-10-03T05:00:00Z', 'username': 'traveler', 'display_name': '여행자', 'revision': 1, 'mine': True, 'likes': 3, 'comments': 0, 'liked': False, 'media': [media], 'original_media': [media]}


class Mock:
    def __init__(self):
        self.posts = []
        self.me = {'username': 'traveler', 'display_name': '여행자', 'can_write': True, 'csrf_token': 'fixture-csrf'}
        self.save_fail = False
        self.upload_fail = False
        self.comments = []
        self.saves = []
        self.uploads = 0
        self.revoked = False
    def route(self, route):
        req = route.request; parsed = urlparse(req.url); path = parsed.path; query = parse_qs(parsed.query)
        def send(data, status=200): route.fulfill(status=status, content_type='application/json', body=json.dumps(data, ensure_ascii=False))
        if path.startswith('/tbox/assets/'):
            file = ROOT / 'public/assets' / path.removeprefix('/tbox/assets/')
            if file.is_file(): route.fulfill(path=str(file))
            else: route.fulfill(status=404)
            return
        if path.endswith('/app.webmanifest'): send({'name': 'Qaredi fixture', 'start_url': '/community/'}); return
        if req.method not in ('GET', 'HEAD'):
            assert req.headers.get('x-csrf-token') == 'fixture-csrf', 'missing CSRF header'
        if path == '/community/api/memories/media' and req.method == 'POST':
            self.uploads += 1
            if self.upload_fail: send({'error': '시험: 업로드 연결 끊김'}, 503)
            else: send(record()['media'][0], 201)
            return
        if '/media/' in path: route.fulfill(content_type='image/png', body=photo()); return
        if path.endswith('/feed') or path == '/community/api/memories' and req.method == 'GET':
            rows = copy.deepcopy(self.posts)
            shared = '/memories/s/' in path
            if shared:
                rows = [p for p in rows if p['visibility'] == 'shared']
                for p in rows:
                    p['mine'] = False; p.pop('original_caption', None); p.pop('original_media', None)
            data = {'posts': rows, 'next_offset': None, 'stats': {'posts': len(rows), 'moments': sum(len(p['media']) for p in rows), 'trips': 1 if rows else 0}}
            data['profile' if shared else 'me'] = {'username': 'traveler', 'display_name': '여행자'} if shared else self.me
            send(data); return
        if path == '/community/api/memories/links':
            if req.method == 'DELETE': self.revoked = True; send({'ok': True})
            else: send({'path': '/community/memories/s/' + TOKEN, 'expires_at': '2026-11-02T05:00:00Z'}, 201)
            return
        if path.endswith('/like'): send({'ok': True}); return
        if path.endswith('/comments'):
            if req.method == 'POST': self.comments.append({'body': req.post_data_json['body'], 'username': 'traveler', 'display_name': '여행자', 'created_at': '2026-10-03T05:00:00Z'})
            send({'comments': self.comments}); return
        if path == '/community/api/memories' and req.method == 'POST' or path == '/community/api/memories/' + POST and req.method == 'PATCH':
            payload = req.post_data_json; self.saves.append(payload)
            if self.save_fail: send({'error': '시험: 저장 실패'}, 503); return
            p = record(); p.update({k:v for k,v in payload.items() if k not in ('media_ids', 'request_id')}); p['media'] = record()['media'] if payload['media_ids'] else []
            self.posts = [p]; send({'id': POST}, 201); return
        if path == '/community/api/memories/' + POST:
            if req.method == 'DELETE': self.posts = []; send({'ok': True})
            else: send({'post': self.posts[0]})
            return
        if path.startswith('/community'):
            html = page_html(TOKEN if '/memories/s/' in path else '', 'traveler' if '/memories/u/' in path else '', POST if '/memories/p/' in path else '')
            route.fulfill(content_type='text/html', body=html, headers={'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; object-src 'none'; base-uri 'none'"}); return
        route.fulfill(status=404)


def main():
    parser = argparse.ArgumentParser(); parser.add_argument('--output', type=Path, default=Path('/tmp/qaredi-memories-preview')); args = parser.parse_args(); args.output.mkdir(parents=True, exist_ok=True)
    checks=[]
    def ok(label): checks.append(label); print('PASS', label, flush=True)
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'), headless=True, args=['--no-sandbox'])
        ctx = browser.new_context(viewport={'width':1440,'height':1100}, device_scale_factor=1)
        mock=Mock(); ctx.route(ORIGIN+'/**', mock.route)
        page=ctx.new_page(); errors=[]; page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(ORIGIN+'/community/memories'); page.locator('#empty').wait_for(state='visible')
        assert not errors, errors
        page.screenshot(path=str(args.output/'desktop-empty.png'), full_page=True); ok('Desktop empty state and no JavaScript errors')
        page.set_viewport_size({'width':390,'height':844})
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), 'mobile overflow'
        page.screenshot(path=str(args.output/'mobile-empty.png'), full_page=True); ok('390px mobile has no horizontal overflow')
        page.locator('.threads-write').click(); page.locator('#compose-dialog').wait_for(state='visible')
        assert page.locator('#camera-input').get_attribute('capture')=='environment'
        assert page.locator('#video-input').get_attribute('capture')=='environment'
        page.locator('#caption').fill('내일의 여행을 기다리는 마음 <script>bad()</script>')
        page.locator('#location').fill('서울'); page.locator('#trip').fill('연휴의 기록')
        page.locator('#media-input').set_input_files({'name':'test.png','mimeType':'image/png','buffer':photo()})
        page.wait_for_function("() => document.querySelector('#draft-status').textContent.includes('임시 저장했어요')")
        assert page.locator('#media-preview img').count()==1
        page.screenshot(path=str(args.output/'mobile-compose.png'), full_page=True)
        page.reload(); page.locator('#resume-draft').wait_for(state='visible'); page.locator('#resume-draft').click()
        assert '<script>' in page.locator('#caption').input_value()
        assert page.locator('#media-preview img').count()==1
        ok('Camera/gallery controls and IndexedDB photo + text draft recovery')
        mock.upload_fail=True; page.locator('#publish').click(); page.wait_for_function("() => document.querySelector('#compose-error').textContent.includes('연결 끊김')")
        assert page.locator('#caption').input_value().startswith('내일')
        assert page.locator('#media-preview img').count()==1
        mock.upload_fail=False; mock.save_fail=True; page.locator('#publish').click(); page.wait_for_function("() => document.querySelector('#compose-error').textContent.includes('저장 실패')")
        first_request=mock.saves[-1]['request_id']; uploaded_count=mock.uploads
        mock.save_fail=False; page.locator('#publish').click(); page.locator('#compose-dialog').wait_for(state='hidden')
        assert mock.saves[-1]['request_id']==first_request
        assert mock.uploads==uploaded_count
        assert mock.posts[0]['visibility']=='private'
        assert '<script>' in page.locator('.post-caption').text_content()
        assert not errors, errors
        ok('Upload failure retains draft; save retry reuses request/media IDs; default private; XSS stays text')
        mock.posts=[record()]; page.goto(ORIGIN+'/community/memories'); page.locator('.post').wait_for()
        page.locator('.post-actions button[aria-label="좋아요"]').click(); page.wait_for_function("() => document.querySelector('.likes-label').textContent.includes('4')")
        page.locator('.post-actions button[aria-label^="댓글"]').click(); page.locator('#comment-body').fill('오래 기억하고 싶은 풍경이네요.'); page.locator('#comment-form button[type="submit"]').click(); page.locator('.comment').wait_for()
        assert mock.comments[-1]['body'].startswith('오래')
        page.locator('[data-close="comments-dialog"]').click(); ok('Like and comment actions use CSRF-protected requests')
        page.locator('button',has_text='AI로 꾸미기').click(); page.locator('#studio-external summary').click()
        assert '바닷가에 왔다' in page.locator('#studio-prompt').input_value()
        page.locator('#studio-kind').select_option('video'); assert '타임라인' in page.locator('#studio-prompt').input_value()
        assert page.locator('#studio-originals a').get_attribute('href').endswith('?download=1')
        page.locator('#studio-result').fill('푸른 물결 사이로, 오늘의 마음을 조용히 남겼다.')
        page.screenshot(path=str(args.output/'mobile-studio.png'), full_page=True)
        page.locator('#apply-studio').click(); page.locator('#compose-dialog').wait_for()
        assert page.locator('#caption').input_value().startswith('푸른 물결')
        page.locator('#visibility').select_option('private'); page.locator('#publish').click(); page.locator('#compose-dialog').wait_for(state='hidden')
        assert mock.saves[-1]['revision']==1
        ok('ChatGPT prompt uses original; video capability limits clear; imported text goes through revision-aware edit')
        mock.posts=[record()]; page.goto(ORIGIN+'/community/memories'); page.locator('.post').wait_for()
        page.locator('.share-post').click(); page.locator('#share-dialog').wait_for()
        assert TOKEN in page.locator('#share-url').input_value()
        page.locator('#revoke-links').click(); page.locator('#confirm-ok').click(); page.locator('#confirm-dialog').wait_for(state='hidden')
        assert mock.revoked
        ok('Share link and revoke-all flow')
        page.goto(ORIGIN+'/community/memories?view=mine'); page.locator('.post').wait_for(); page.locator('#grid-view').click(); assert page.locator('.grid-tile').count()==1
        page.locator('.grid-tile').click(); assert page.locator('.post').count()==1
        page.screenshot(path=str(args.output/'mobile-profile.png'), full_page=True); ok('Personal profile, album grid, back to feed')
        page.set_viewport_size({'width':1440,'height':1100}); page.goto(ORIGIN+'/community/memories'); page.locator('.post').wait_for(); page.screenshot(path=str(args.output/'desktop-feed.png'),full_page=True)
        for width in (320,390,768,1024):
            page.set_viewport_size({'width':width,'height':900}); assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'overflow {width}'
        ok('320 / 390 / 768 / 1024px responsive layouts')
        page.goto(ORIGIN+'/community/memories/s/'+TOKEN); page.locator('.post').wait_for()
        assert page.locator('[data-compose]:visible').count()==0
        assert page.locator('.owner-actions').count()==0
        assert page.locator('.post-actions').count()==0
        assert page.locator('#profile-title').text_content()=='여행자의 스레드'
        ok('Anonymous shared-page UI is read-only and has no originals')
        mock.me['username']='another-user'; page.goto(ORIGIN+'/community/memories'); page.locator('.post').wait_for(); assert page.locator('#resume-draft').is_hidden(); ok('Drafts are scoped to the signed-in account')
        mock.me['can_write']=False; page.reload(); page.locator('.post').wait_for(); assert page.locator('[data-compose]:visible').count()==0; ok('Read-only accounts have no composer')
        assert not errors, errors
        browser.close()
    (args.output/'ui-results.json').write_text(json.dumps({'coverage':'mocked HTTP, actual components with native shell fixture, real Chromium; PHP/PostgreSQL not executed','checks':checks},ensure_ascii=False,indent=2)+'\n')
    print(f'{len(checks)} browser checks passed. Screenshots: {args.output}')

if __name__=='__main__': main()
