#!/usr/bin/env python3
"""Synthetic Chromium acceptance checks; mocked HTTP, not server authorization coverage."""
import copy
import json
import os
import re
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import expect, sync_playwright
from memories_browser import Mock, ORIGIN, POST, ROOT, TOKEN, record, page_html


def story_record():
    p = record()
    p['visibility'] = 'private'
    p['caption'] = '처음 쓴 메모 <script>그대로 보존</script>'
    p['media'] = [dict(p['media'][0], id=f'22222222-2222-4222-8222-{i:012}', filename=f'사진 {i}.png') for i in range(10)]
    p['story'] = {'title': '함께 걸은 행복한 오늘', 'intro': '오래 간직하고 싶은 하루. ' * 80,
                  'scenes': [{'title': f'장면 {i+1}', 'text': '좋은 순간을 기록해요. ' * 30, 'media_ids': [m['id']]} for i,m in enumerate(p['media'])]}
    p['discoverable'] = False
    return p


def mixed_records():
    posts = [story_record(), dict(record(), media=[], story=None, caption='긴 일반 글의 고유한 기록. ' * 400), story_record()]
    for index, post in enumerate(posts):
        post['id'] = f'11111111-1111-4111-8111-{index + 1:012}'
        post['created_at'] = f'{2024 + index}-10-03T05:00:00Z'
        post['taken_on'] = f'{2024 + index}-10-03'
        post['visibility'] = 'shared'
        post['discoverable'] = True
        if post['story']:
            if index == 2:
                post['media'] = post['media'][:3]
                post['story']['scenes'] = post['story']['scenes'][:3]
            post['story']['title'] = f'서로 다른 이야기 {index + 1}'
            post['story']['intro'] = f'이야기 {index + 1}의 긴 도입. ' * 80
            for number, (media, scene) in enumerate(zip(post['media'], post['story']['scenes'])):
                media['id'] = f'22222222-2222-4222-8222-{index * 100 + number:012}'
                media['url'] = '/community/api/memories/media/' + media['id']
                scene['media_ids'] = [media['id']]
                scene['text'] = f'이야기 {index + 1}, 장면 {number + 1}의 전체 내용. ' * 30
    return posts


class SocialMock(Mock):
    def __init__(self):
        super().__init__()
        self.posts = [story_record()]
        self.following = False
        self.follow_writes = []
        self.preferences = []
        self.page_size = None
        self.feed_queries = []
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
            self.feed_queries.append(query)
            offset = int(query.get('offset', ['0'])[0])
            if offset:
                assert query.get('cursor') == [f'fixture-cursor-{offset}']
            rows = self.posts[offset:offset + self.page_size] if self.page_size else self.posts
            next_offset = offset + len(rows) if offset + len(rows) < len(self.posts) else None
            username=query.get('profile',['traveler'])[0]
            send({'posts':copy.deepcopy(rows),'me':self.me,'next_offset':next_offset,'next_cursor':f'fixture-cursor-{next_offset}' if next_offset else None,
                  'profile':{'username':username,'display_name':'여행 친구' if username=='friend' else '여행자','mine':username=='traveler','following':self.following,'followers_count':2,'following_count':int(self.following)},
                  'stats':{'posts':len(self.posts),'moments':sum(len(p['media']) for p in self.posts),'trips':1}}); return
        if path=='/community/memories/u/friend':
            route.fulfill(content_type='text/html',body=page_html(profile='friend'));return
        super().route(route)


def compact_previews(page, posts, label):
    cards = page.locator('#feed .post')
    expect(cards).to_have_count(len(posts))
    snapshot = cards.evaluate_all('''cards => cards.map(card => ({
      id: card.id, classes: card.className, photos: card.querySelectorAll('img').length,
      readMore: card.querySelectorAll('.read-more').length, height: Math.round(card.getBoundingClientRect().height)
    }))''')
    assert page.locator('#feed .post-preview').count() == len(posts), (label, snapshot)
    assert page.locator('#feed .post-detail, #feed .story-scene, #feed .expanded').count() == 0, label
    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'), label
    for index, post in enumerate(posts):
        card = cards.nth(index)
        assert card.get_attribute('id') == 'memory-' + post['id'], (label, index)
        assert card.locator('img').count() == min(1, len(post['media'])), (label, index)
        assert card.locator('.read-more').count() == 1, (label, index)
        assert card.bounding_box()['height'] < (820 if post['media'] else 400), (label, snapshot)
        assert card.locator('.post-caption').bounding_box()['height'] < 120, (label, index)
        summary = post['story']['intro'] if post['story'] else post['caption']
        assert card.locator('.post-caption').text_content() == summary, (label, index)


def full_detail(page, post, opener, close_with_escape):
    opener.click()
    detail = page.locator('#detail-content')
    expect(page.locator('#detail-dialog')).to_be_visible()
    assert detail.locator('.post-detail').count() == 1
    assert detail.locator('img').evaluate_all('(images) => images.map(image => image.getAttribute("src"))') == [media['url'] for media in post['media']]
    assert detail.locator('.post-caption').text_content() == post['caption']
    if post['story']:
        assert detail.locator('.story-title').text_content() == post['story']['title']
        assert detail.locator('.story-intro').text_content() == post['story']['intro']
        assert detail.locator('.story-copy').all_text_contents() == [scene['text'] for scene in post['story']['scenes']]
        detail.locator('.story-original summary').click()
        expect(detail.locator('.post-caption')).to_be_visible()
    else:
        assert detail.locator('.story-scene').count() == 0
        expect(detail.locator('.post-caption')).to_be_visible()
    assert detail.locator('script').count() == 0
    if close_with_escape:
        page.keyboard.press('Escape')
    else:
        page.locator('[data-close="detail-dialog"]').click()
    expect(page.locator('#detail-dialog')).to_be_hidden()
    expect(opener).to_be_focused()


def mixed_feed_regression(page, mock, checks):
    posts = mixed_records()
    routes = [('mine', '/community/memories?view=mine'), ('following', '/community/memories'),
              ('discover', '/community/memories?view=discover'), ('member', '/community/memories/u/friend'),
              ('anonymous shared', '/community/memories/s/' + TOKEN)]
    for width in [390, 1440]:
        page.set_viewport_size({'width': width, 'height': 900})
        for route_index, (name, path) in enumerate(routes):
            shift = route_index % len(posts)
            mock.posts = copy.deepcopy(posts[shift:] + posts[:shift])
            page.goto(ORIGIN + path)
            label = f'{width}px {name}'
            compact_previews(page, mock.posts, label)
            for index, post in enumerate(mock.posts):
                full_detail(page, post, page.locator('#feed .read-more').nth(index), index % 2 == 0)
                compact_previews(page, mock.posts, label + ' after detail close')
            page.locator('#grid-view').click()
            expect(page.locator('#feed .grid-tile')).to_have_count(3)
            for index, post in enumerate(mock.posts):
                full_detail(page, post, page.locator('#feed .grid-tile').nth(index), index % 2 == 0)
            page.locator('#list-view').click()
            compact_previews(page, mock.posts, label + ' after album/list toggle')
        mock.posts = copy.deepcopy(posts)
        mock.page_size = 2
        mock.feed_queries.clear()
        page.goto(ORIGIN + '/community/memories?view=mine')
        compact_previews(page, posts[:2], f'{width}px first page')
        page.locator('#load-more').click()
        compact_previews(page, posts, f'{width}px appended page')
        assert mock.feed_queries[-1]['cursor'] == ['fixture-cursor-2']
        expect(page.locator('#load-more')).to_be_hidden()
        full_detail(page, posts[-1], page.locator('#feed .read-more').last, True)
        compact_previews(page, posts, f'{width}px after appended detail close')
        mock.posts.reverse()
        mock.page_size = None
        page.locator('#refresh').click()
        expect(page.locator('#feed .post').first).to_have_id('memory-' + mock.posts[0]['id'])
        compact_previews(page, mock.posts, f'{width}px refreshed reverse order')
    checks.append('Three distinct old/new story/plain posts: compact in all positions on mine/following/discovery/member/shared feeds at 390/1440px')
    checks.append('Every mixed-feed preview and album tile opens its own complete text/photos; Escape/close restores focus and list stays compact')
    checks.append('Cursor load-more appends compact records; refresh reorder and album/list switches preserve previews')
    mock.posts = [story_record()]


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
        mixed_feed_regression(page, mock, checks)
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
