#!/usr/bin/env python3
"""Compact galleries in real Chromium with synthetic HTTP/media; no server coverage."""
import copy
import io
import json
import os
import subprocess
from pathlib import Path
from urllib.parse import urlparse

from PIL import Image, ImageDraw
from playwright.sync_api import expect, sync_playwright
from memories_browser import ORIGIN, ROOT, TOKEN, record
from memories_social_browser import SocialMock, compact_previews, preview_window, story_record


OUTPUT = Path('/tmp/qaredi-memories-gallery')
BASELINE = '6dc6ec2'


def gallery_record(count=10, number=1, story=True):
    post = story_record() if story else dict(record(), story=None)
    post['id'] = f'11111111-1111-4111-8111-{number:012}'
    post['visibility'] = 'shared'
    post['media'] = [dict(record()['media'][0],
                          id=f'22222222-2222-4222-8222-{number * 100 + i:012}',
                          filename=f'합성 사진 {i + 1}.png',
                          url=f'/community/api/memories/media/gallery-{number}-{i + 1}.png')
                     for i in range(count)]
    if post['story']:
        post['story']['scenes'] = [dict(scene, media_ids=[post['media'][i]['id']])
                                 for i, scene in enumerate(post['story']['scenes'][:count])]
    return post


class GalleryMock(SocialMock):
    def __init__(self, baseline=False):
        super().__init__()
        self.assets = {}
        self.requests = []
        if baseline:
            for extension in ['js', 'css']:
                path = f'public/assets/community/memories.{extension}'
                self.assets[f'/tbox/assets/community/memories.{extension}'] = subprocess.check_output(
                    ['git', 'show', f'{BASELINE}:{path}'], cwd=ROOT)

    def route(self, route):
        path = urlparse(route.request.url).path
        if path in self.assets:
            route.fulfill(body=self.assets[path], content_type='text/css' if path.endswith('.css') else 'text/javascript')
            return
        if path.startswith('/community/api/memories/media/gallery-'):
            self.requests.append(path)
            if path.endswith('.webm'):
                route.fulfill(path=str(OUTPUT / 'synthetic-video.webm'), content_type='video/webm')
                return
            number = int(path.rsplit('-', 1)[1].removesuffix('.png'))
            image = Image.new('RGB', (720, 480), (110 + number * 9, 165, 180 - number * 7))
            draw = ImageDraw.Draw(image)
            draw.ellipse((450, 40, 550, 140), fill='#f6dfac')
            draw.polygon([(0, 300), (160, 160), (350, 340), (510, 210), (720, 360), (720, 480), (0, 480)], fill='#648978')
            draw.text((35, 35), f'PHOTO {number}', fill='white', font_size=40)
            body = io.BytesIO(); image.save(body, format='PNG')
            route.fulfill(body=body.getvalue(), content_type='image/png')
            return
        super().route(route)


def card_at(page, index=0):
    return page.locator('#feed .post-preview').nth(index)


def expect_current(card, index, total):
    expect(card.locator('[data-preview-count]')).to_have_text(f'{index + 1} / {total}')
    expect(card.locator('[data-preview-previous]')).to_be_disabled() if index == 0 else expect(card.locator('[data-preview-previous]')).to_be_enabled()
    expect(card.locator('[data-preview-next]')).to_be_disabled() if index == total - 1 else expect(card.locator('[data-preview-next]')).to_be_enabled()
    preview_window(card, total, f'attachment {index + 1}/{total}')


def loaded_image(card, index):
    image = card.locator('.post-cover img').nth(index)
    expect(image).to_have_js_property('complete', True)
    assert image.evaluate('(image) => image.naturalWidth') == 720


def touch_drag(page, locator, dx=0, dy=0):
    locator.scroll_into_view_if_needed()
    box = locator.bounding_box()
    x = box['x'] + box['width'] * (.8 if dx < 0 else .3)
    y = box['y'] + box['height'] * (.8 if dy < 0 else .35)
    cdp = page.context.new_cdp_session(page)
    cdp.send('Input.dispatchTouchEvent', {'type': 'touchStart', 'touchPoints': [{'x': x, 'y': y}]})
    for step in range(1, 13):
        cdp.send('Input.dispatchTouchEvent', {'type': 'touchMove', 'touchPoints': [{'x': x + dx * step / 12, 'y': y + dy * step / 12}]})
        page.wait_for_timeout(20)
    cdp.send('Input.dispatchTouchEvent', {'type': 'touchEnd', 'touchPoints': []})
    cdp.detach()
    page.wait_for_timeout(400)
    page.wait_for_function('''track => Math.abs(track.scrollLeft -
      Math.round(track.scrollLeft / track.clientWidth) * track.clientWidth) < .5''', arg=locator.element_handle())


def detail_snapshot(page, index):
    opener = card_at(page, index).locator('.read-more')
    opener.click()
    detail = page.locator('#detail-content')
    expect(page.locator('#detail-dialog')).to_be_visible()
    snapshot = detail.evaluate('''root => {
      const tree = node => node.nodeType === Node.TEXT_NODE ? node.textContent : {
        tag: node.tagName, attrs: [...node.attributes].map(a => [a.name, a.value]),
        children: [...node.childNodes].map(tree)
      };
      return tree(root);
    }''')
    assert detail.locator('[data-preview-gallery]').count() == 0
    page.keyboard.press('Escape')
    expect(opener).to_be_focused()
    return snapshot


def main():
    OUTPUT.mkdir(exist_ok=True)
    subprocess.run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi', '-i',
                    'color=c=teal:s=240x160:d=12', '-c:v', 'libvpx', '-an', '-y',
                    str(OUTPUT / 'synthetic-video.webm')], check=True)
    checks = []; errors = []; dimensions = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.getenv('CHROMIUM_EXECUTABLE', '/usr/bin/chromium'),
                                     headless=True, args=['--no-sandbox'])
        context = browser.new_context(viewport={'width': 1440, 'height': 1000}, reduced_motion='reduce')
        mock = GalleryMock(); context.route('**/*', mock.route)
        page = context.new_page(); page.on('pageerror', lambda error: errors.append(str(error)))
        posts = [gallery_record(10), gallery_record(0, 2, False), gallery_record(1, 3, False), gallery_record(3, 4)]
        routes = ['/community/memories?view=mine', '/community/memories', '/community/memories?view=discover',
                  '/community/memories/u/friend', '/community/memories/s/' + TOKEN]
        for width in [320, 390, 1440]:
            page.set_viewport_size({'width': width, 'height': 1000})
            for route in routes:
                mock.posts = copy.deepcopy(posts)
                page.goto(ORIGIN + route)
                compact_previews(page, posts, f'{width}px {route}')
                assert card_at(page, 1).locator('[data-preview-gallery]').count() == 0
                assert card_at(page, 2).locator('[data-preview-next], [data-preview-previous], [data-preview-count]').count() == 0
                assert card_at(page, 2).locator('[data-preview-track]').get_attribute('tabindex') is None
                card = card_at(page)
                height = card.bounding_box()['height']
                card.locator('[data-preview-next]').click()
                expect_current(card, 1, 10); loaded_image(card, 1)
                expect_current(card_at(page, 3), 0, 3)
                assert card.bounding_box()['height'] == height
                dimensions.append({'width': width, 'route': route, 'card_height': height,
                                   'media_height': card.locator('[data-preview-track]').bounding_box()['height']})
        checks.append('15 route/viewport combinations: 0/1/3/10 attachments, independent cards, unchanged summary, bounded height and no page overflow')

        page.set_viewport_size({'width': 1440, 'height': 1000})
        mock.posts = [gallery_record(10)]
        page.goto(ORIGIN + '/community/memories?view=mine')
        card = card_at(page); track = card.locator('[data-preview-track]')
        expect_current(card, 0, 10)
        expected_urls = [item['url'] for item in mock.posts[0]['media']]
        assert card.locator('.post-cover img').evaluate_all('(items) => items.map(item => item.getAttribute("src"))') == expected_urls
        assert card.locator('.post-cover img').evaluate_all('(items) => items.every(item => item.loading === "lazy")')
        for index in range(1, 10):
            card.locator('[data-preview-next]').click(); expect_current(card, index, 10); loaded_image(card, index)
        for selector in ['[data-preview-previous]', '[data-preview-next]', '.slide-count']:
            box = card.locator(selector).bounding_box()
            assert box['width'] >= 44 and box['height'] >= 44, (selector, box)
        for index in range(8, -1, -1):
            card.locator('[data-preview-previous]').click(); expect_current(card, index, 10)
        assert set(expected_urls) <= set(mock.requests)
        checks.append('Desktop previous/next traverses every ordered lazy image; first/last disabled boundaries and 44px controls')

        track.focus()
        for key, index in [('ArrowRight', 1), ('End', 9), ('ArrowRight', 9), ('Home', 0), ('ArrowLeft', 0)]:
            page.keyboard.press(key); expect_current(card, index, 10)
        page.keyboard.press('Tab')
        expect(card.locator('.slide-count')).to_be_focused()
        page.keyboard.press('Tab')
        expect(card.locator('[data-preview-next]')).to_be_focused()
        page.keyboard.press('Enter'); expect_current(card, 1, 10)
        track.evaluate('(track) => track.scrollLeft = 4 * track.clientWidth')
        expect_current(card, 4, 10)
        before = track.evaluate('(track) => track.scrollLeft')
        page.wait_for_timeout(1000)
        assert track.evaluate('(track) => track.scrollLeft') == before
        card.locator('.slide-count').click(); expect(page.locator('#detail-dialog')).to_be_visible()
        assert page.locator('#detail-content .story-photos img').count() == 10
        page.keyboard.press('Escape'); expect(card.locator('.slide-count')).to_be_focused()
        checks.append('Arrow/Home/End keys, Tab/Enter, native horizontal scroll, current status, no automatic movement and full-view entry')

        for width in [390, 1440]:
            page.set_viewport_size({'width': width, 'height': 1000})
            expect_current(card, 4, 10)
            assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        card.scroll_into_view_if_needed(); loaded_image(card, 4)
        card.screenshot(path=str(OUTPUT / 'desktop-gallery-fifth.png'))
        checks.append('Resizing desktop/mobile preserves current slide and bounded media dimensions')

        mock.posts = copy.deepcopy(posts); mock.page_size = 2
        page.goto(ORIGIN + '/community/memories?view=mine')
        page.locator('#load-more').click(); compact_previews(page, posts, 'appended page')
        card_at(page, 3).locator('[data-preview-next]').click(); expect_current(card_at(page, 3), 1, 3)
        mock.posts.reverse(); mock.page_size = None
        page.locator('#refresh').click()
        expect(card_at(page)).to_have_id('memory-' + mock.posts[0]['id'])
        card_at(page).locator('[data-preview-next]').click(); expect_current(card_at(page), 1, 3)
        checks.append('Cursor load-more and reordered refresh create working independent galleries')

        touch_context = browser.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True,
                                            has_touch=True, reduced_motion='reduce')
        touch_mock = GalleryMock(); touch_mock.posts = [gallery_record(10), gallery_record(3, 2)]
        touch_context.route('**/*', touch_mock.route)
        touch_page = touch_context.new_page(); touch_page.on('pageerror', lambda error: errors.append(str(error)))
        touch_page.goto(ORIGIN + '/community/memories?view=mine')
        touch_card = card_at(touch_page); touch_track = touch_card.locator('[data-preview-track]')
        expect_current(touch_card, 0, 10)
        touch_drag(touch_page, touch_track, dx=-230)
        expect_current(touch_card, 1, 10); loaded_image(touch_card, 1)
        # Full-page capture temporarily resizes a mobile viewport and can reset
        # Chromium's native snap position. Capture the actual compact card.
        touch_card.screenshot(path=str(OUTPUT / 'mobile-gallery-second.png'))
        expect_current(touch_card, 1, 10)
        touch_drag(touch_page, touch_track, dx=230)
        expect_current(touch_card, 0, 10)
        touch_track.scroll_into_view_if_needed()
        before = touch_page.evaluate('scrollY')
        touch_drag(touch_page, touch_track, dy=-130)
        assert touch_page.evaluate('scrollY') > before + 30
        expect_current(touch_card, 0, 10)
        checks.append('390px mobile CDP touch swipes forward/back with snapping; vertical swipe still scrolls the page')

        mixed = gallery_record(3, story=False)
        mixed['media'][0].update(mime='video/webm', filename='합성 영상.webm', url='/community/api/memories/media/gallery-video.webm')
        mock.posts = [mixed]
        page.goto(ORIGIN + '/community/memories?view=mine')
        card = card_at(page); track = card.locator('[data-preview-track]'); video = card.locator('video')
        assert video.evaluate('(video) => video.controls && video.playsInline && !video.autoplay && video.preload === "metadata" && video.paused')
        video.evaluate('(video) => {video.muted = true; return video.play();}')
        expect(video).to_have_js_property('paused', False)
        video.focus(); page.keyboard.press('ArrowRight')
        expect_current(card, 0, 3)
        assert track.evaluate('''track => {
          const event = new KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true, cancelable: true});
          track.querySelector('video').dispatchEvent(event); return !event.defaultPrevented;
        }''')
        card.locator('[data-preview-next]').click(); expect_current(card, 1, 3)
        expect(video).to_have_js_property('paused', True)
        assert video.locator('..').evaluate('(slide) => slide.inert')
        track.focus(); page.keyboard.press('Home'); expect_current(card, 0, 3)
        expect(video).to_have_js_property('paused', True)
        assert not video.locator('..').evaluate('(slide) => slide.inert')
        checks.append('Real synthetic video starts only on request; video keyboard events remain native; leaving slide pauses it and return does not autoplay')

        comparison_posts = [gallery_record(10), gallery_record(3, 2, False), gallery_record(0, 3, False)]
        baseline_context = browser.new_context(viewport={'width': 1440, 'height': 1000}, reduced_motion='reduce')
        baseline_mock = GalleryMock(baseline=True); baseline_mock.posts = copy.deepcopy(comparison_posts)
        baseline_context.route('**/*', baseline_mock.route)
        baseline_page = baseline_context.new_page()
        mock.posts = copy.deepcopy(comparison_posts)
        for width in [390, 1440]:
            for target in [page, baseline_page]:
                target.set_viewport_size({'width': width, 'height': 1000})
                target.goto(ORIGIN + '/community/memories?view=mine')
                expect(target.locator('#feed .post-preview')).to_have_count(3)
            for index in range(3):
                assert card_at(page, index).bounding_box()['height'] == card_at(baseline_page, index).bounding_box()['height']
                assert detail_snapshot(page, index) == detail_snapshot(baseline_page, index), (width, index)
        checks.append(f'Against {BASELINE} JS/CSS: exact compact-card heights and complete detailed DOM unchanged for story/plain/text at 390/1440px')
        assert not errors, errors
        browser.close()
    (OUTPUT / 'results.json').write_text(json.dumps({'coverage': 'Chromium mocked HTTP and synthetic media; not live PHP/server or physical handset',
                                                    'baseline': BASELINE, 'checks': checks, 'dimensions': dimensions,
                                                    'javascript_errors': errors}, ensure_ascii=False, indent=2) + '\n')
    for check in checks:
        print('PASS', check)
    print(f'{len(checks)} compact gallery browser checks passed')


if __name__ == '__main__':
    main()
