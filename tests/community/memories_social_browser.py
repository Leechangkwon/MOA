#!/usr/bin/env python3
"""Synthetic Chromium acceptance checks; mocked HTTP, not server authorization coverage."""
import copy
import json
import os
import re
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright
from memories_browser import Mock, ORIGIN, POST, ROOT, record, page_html


def story_record():
    p = record()
    p['visibility'] = 'private'
    p['caption'] = '처음 쓴 메모 <script>그대로 보존</script>'
    p['media'] = [dict(p['media'][0], id=f'22222222-2222-4222-8222-{i:012}', filename=f'사진 {i}.png') for i in range(10)]
    p['story'] = {'title': '함께 걸은 행복한 오늘', 'intro': '오래 간직하고 싶은 하루. ' * 80,
                  'scenes': [{'title': f'장면 {i+1}', 'text': '좋은 순간을 기록해요. ' * 30, 'media_ids': [m['id']]} for i,m in enumerate(p['media'])]}
    p['discoverable'] = False
    return p


class SocialMock(Mock):
    def __init__(self):
        super().__init__()
        self.posts = [story_record()]
        self.following = False
        self.follow_writes = []
        self.preferences = []
        self.me['default_visibility'] = 'private'
    def route(self, route):
        req=route.request; parsed=urlparse(req.url); path=parsed.path; query=parse_qs(parsed.query)
        def send(data): route.fulfill(content_type='application/json',body=json.dumps(data,ensure_ascii=False))
        if path == '/community/api/memories/follow':
            assert req.headers['x-csrf-token']=='fixture-csrf'
            assert req.post_data_json == {'username':'friend'}
            self.follow_writes.append(req.method); self.following=req.method=='POST'; send({'ok':True}); return
        if path == '/community/api/memories/preferences':
            assert req.headers['x-csrf-token']=='fixture-csrf'
            self.preferences.append(req.post_data_json); self.me.update(req.post_data_json); send({'ok':True}); return
        if path == '/community/api/memories/social':
            send({'members':[{'username':'friend','display_name':'여행 친구','mine':False,'following':self.following}], 'next_offset':None});return
        if path == '/community/api/memories' and req.method=='GET':
            username=query.get('profile',['traveler'])[0]
            send({'posts':copy.deepcopy(self.posts),'me':self.me,'next_offset':None,'next_cursor':None,
                  'profile':{'username':username,'display_name':'여행 친구' if username=='friend' else '여행자','mine':username=='traveler','following':self.following,'followers_count':2,'following_count':int(self.following)},
                  'stats':{'posts':len(self.posts),'moments':sum(len(p['media']) for p in self.posts),'trips':1}}); return
        if path=='/community/memories/u/friend':
            route.fulfill(content_type='text/html',body=page_html(profile='friend'));return
        super().route(route)


def contrast(page, selector, background):
    return page.evaluate('''([selector, background]) => {
      const rgb = (value) => value.match(/[\\d.]+/g).slice(0,3).map(Number).map(v => {v /= 255; return v <= .04045 ? v/12.92 : ((v+.055)/1.055)**2.4;});
      const luminance = (v) => .2126*v[0]+.7152*v[1]+.0722*v[2];
      const fg=luminance(rgb(getComputedStyle(document.querySelector(selector)).color));
      const bg=luminance(rgb(getComputedStyle(document.querySelector(background)).backgroundColor));
      return (Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05);
    }''', [selector, background])


def main():
    checks=[]; errors=[]; mock=SocialMock(); output=Path('/tmp/qaredi-story-social');output.mkdir(exist_ok=True)
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path=os.getenv('CHROMIUM_EXECUTABLE','/usr/bin/chromium'),headless=True,args=['--no-sandbox'])
        ctx=browser.new_context(viewport={'width':390,'height':844},reduced_motion='reduce')
        ctx.route('**/*',mock.route)
        page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
        page.goto(ORIGIN+'/community/memories?view=mine');page.locator('#feed .post').wait_for()
        for width in [360,390,768,1440]:
            page.set_viewport_size({'width':width,'height':900})
            for theme in ['light','dark']:
                for design in ['claude','astra','aurora','bloom','nova']:
                    page.evaluate('([theme, design]) => {document.documentElement.dataset.theme=theme;document.documentElement.dataset.design=design}',[theme,design])
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),(width,theme,design)
                    assert contrast(page,'#feed .post-caption','#feed .post')>=4.5,(width,theme,design,'caption')
                    assert contrast(page,'#feed .post-author','#feed .post')>=4.5,(width,theme,design,'author')
                    assert contrast(page,'#feed .post-actions button','#feed .post')>=3,(width,theme,design,'action')
                    assert page.locator('#feed .post').bounding_box()['height']<820,(width,theme,design)
                    assert page.locator('#feed img').count()==1
        checks.append('40 viewport/theme combinations: no overflow, bounded ten-photo preview, text contrast >=4.5 and icons >=3')
        page.set_viewport_size({'width':390,'height':844})
        page.evaluate("document.documentElement.dataset.design='aurora'")
        page.screenshot(path=str(output/'dark-story-preview.png'),full_page=True)
        opener=page.locator('#feed .read-more');opener.click();page.locator('#detail-dialog').wait_for()
        assert page.locator('#detail-content .story-photos img').count()==10
        assert page.locator('#detail-content .story-scene').count()==10
        page.locator('#detail-content .story-original summary').click()
        assert '<script>' in page.locator('#detail-content .post-caption').text_content()
        page.keyboard.press('Escape');page.locator('#detail-dialog').wait_for(state='hidden')
        assert opener.evaluate('(el)=>el===document.activeElement')
        checks.append('Detail contains ten photos, all scene text, escaped original memo; Escape restores opener focus')
        opener.click();page.locator('#detail-content [aria-label="좋아요"]').click()
        page.wait_for_function("() => document.querySelector('#feed .likes-label').textContent.includes('4')")
        assert page.locator('#detail-content .likes-label').text_content()=='좋아요 4개'
        page.locator('#detail-content [aria-label^="댓글"]').click()
        assert page.locator('#detail-dialog').is_hidden()
        assert page.locator('dialog[open]').count()==1
        page.locator('#comment-body').fill('다음 여행도 함께해요');page.locator('#comment-form button').click();page.locator('.comment').wait_for()
        page.keyboard.press('Escape')
        assert opener.evaluate('(el)=>el===document.activeElement')
        checks.append('Detail likes synchronize preview; comment modal opens without stacked stale detail and restores feed focus')
        page.locator('#feed .share-post').click();page.locator('#share-dialog').wait_for()
        assert page.locator('#share-link-field').is_hidden() and page.locator('#share-edit-privacy').is_visible()
        page.locator('#share-edit-privacy').click();page.locator('#compose-dialog').wait_for()
        page.locator('#visibility').select_option('community');page.locator('#discoverable').check()
        page.locator('#visibility').select_option('followers')
        assert not page.locator('#discoverable').is_checked() and page.locator('#discoverable').is_disabled()
        page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden')
        assert mock.saves[-1]['visibility']=='followers' and mock.saves[-1]['discoverable'] is False
        checks.append('Private share opens explanation and edit; followers cannot accidentally stay opted into discovery')
        page.locator('#profile-settings').click();page.locator('#default-visibility').select_option('followers');page.locator('#privacy-form button').click();page.locator('#privacy-dialog').wait_for(state='hidden')
        page.locator('.threads-write').click();assert page.locator('#visibility').input_value()=='followers';page.keyboard.press('Escape')
        assert mock.preferences[-1]=={'default_visibility':'followers'}
        checks.append('Owner default visibility saved with CSRF and used for next post')
        page.locator('#find-people').click();page.locator('.social-person button').click()
        page.wait_for_function("() => document.querySelector('.social-person button')?.textContent==='팔로잉'")
        page.locator('.social-person button').click();page.wait_for_function("() => document.querySelector('.social-person button')?.textContent==='팔로우'")
        assert mock.follow_writes==['POST','DELETE'];page.keyboard.press('Escape')
        checks.append('Member search modal follows/unfollows with CSRF and updates relationship')
        page.goto(ORIGIN+'/community/memories/u/friend');page.locator('#follow-profile').wait_for()
        assert page.locator('#profile-settings').is_hidden()
        page.locator('#follow-profile').click();page.wait_for_function("() => document.querySelector('#follow-profile').textContent==='팔로잉'")
        page.locator('#profile-stats button').first.click();page.locator('#social-dialog').wait_for();page.keyboard.press('Escape')
        checks.append('Other member profile exposes follow/follower list without owner controls')
        page.locator('[data-view="discover"]').click();page.wait_for_function("() => document.querySelector('#feed-title').textContent==='새로운 이야기 발견'")
        assert page.url==ORIGIN+'/community/memories?view=discover'
        page.reload();page.locator('#feed .post').wait_for();assert page.locator('#feed-title').text_content()=='새로운 이야기 발견'
        page.locator('[data-view=feed]').click();assert page.url==ORIGIN+'/community/memories'
        page.reload();page.locator('#feed .post').wait_for();assert page.locator('#feed-title').text_content()=='팔로잉의 이야기'
        page.locator('#grid-view').click();page.locator('.grid-tile').click();page.locator('#detail-dialog').wait_for();page.keyboard.press('Escape')
        checks.append('Discovery tab and album tile open bounded feed/detail without navigating to full long page')
        mock.posts=[dict(record(),media=[],story=None,caption='긴 글입니다. '*700)]
        page.goto(ORIGIN+'/community/memories');page.locator('#feed .post').wait_for();assert page.locator('#feed .post').bounding_box()['height']<400
        page.locator('#feed .read-more').click();assert len(page.locator('#detail-content .post-caption').text_content())>4000
        checks.append('Text-only long posts remain compact and full text opens in modal')
        assert not errors,errors
        browser.close()
    (output/'results.json').write_text(json.dumps({'coverage':'Chromium mocked HTTP; server privacy tested separately','checks':checks},ensure_ascii=False,indent=2)+'\n')
    for check in checks: print('PASS',check)
    print(f'{len(checks)} story/social browser checks passed')

if __name__=='__main__':main()
