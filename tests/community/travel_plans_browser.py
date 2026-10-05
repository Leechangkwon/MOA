#!/usr/bin/env python3
"""Real mobile Chromium, actual community components, mocked HTTP. No PHP/DB claims."""
import copy
import json
import os
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright
from memories_browser import Mock, ORIGIN, POST

PLAN = '44444444-4444-4444-8444-444444444444'


class TravelMock(Mock):
    def __init__(self):
        super().__init__()
        self.plans = {}
        self.travel_saves = []
        self.links = []
        self.conflict = False
        self.link_fail = False
        self.delay_next = None
        self.pending = None

    def route(self, route):
        req = route.request
        path = urlparse(req.url).path
        prefix = '/community/api/travel-plans'
        if not path.startswith(prefix):
            return super().route(route)
        def send(value, status=200):
            route.fulfill(status=status, content_type='application/json', body=json.dumps(value))
        if req.method != 'GET':
            assert req.headers.get('x-csrf-token') == 'fixture-csrf'
        if self.delay_next == req.method:
            self.delay_next = None; self.pending = route; return
        if path == prefix and req.method == 'GET':
            summaries = []
            for p in self.plans.values():
                row = {k:v for k,v in p.items() if k not in ('steps', 'notes', 'records')}
                row.update(step_count=len(p['steps']), done_count=sum(s['status']=='done' for s in p['steps']))
                summaries.append(row)
            return send({'plans': summaries, 'csrf_token': 'fixture-csrf'})
        if path.endswith('/records'):
            if self.link_fail:
                return send({'error': '시험 연결 실패'}, 503)
            body = req.post_data_json; self.links.append(body)
            if req.method == 'POST':
                self.plans[PLAN]['records'] = [dict(body, caption='여행 기록', taken_on='2026-10-03', location='제주')]
            else:
                self.plans[PLAN]['records'] = []
            self.plans[PLAN]['revision'] += 1
            return send({'plan': self.plans[PLAN]})
        if req.method in ('POST', 'PATCH'):
            body = req.post_data_json; self.travel_saves.append(copy.deepcopy(body))
            if self.conflict or (req.method == 'PATCH' and body.get('revision') != self.plans[PLAN]['revision']):
                return send({'error': '다른 곳에서 계획이 바뀌었어요. 현재 입력을 복사해 둔 뒤 계획을 새로 열어 주세요.'}, 409)
            p = copy.deepcopy(body)
            p.update(id=PLAN, revision=body.get('revision', 0)+1, visibility='private', records=self.plans.get(PLAN, {}).get('records', []))
            self.plans[PLAN] = p
            return send({'plan': p})
        if req.method == 'DELETE':
            self.plans.pop(PLAN, None)
            return send({'ok': True})
        if path == prefix + '/' + PLAN:
            return send({'plan': self.plans[PLAN]})
        return send({'error':'not found'}, 404)


def main():
    checks = []
    def ok(label): checks.append(label); print('PASS', label, flush=True)
    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE', '/usr/bin/chromium'), headless=True, args=['--no-sandbox'])
        ctx = browser.new_context(viewport={'width':390, 'height':844})
        mock = TravelMock(); ctx.route(ORIGIN + '/**', mock.route)
        page = ctx.new_page(); errors = []; page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(ORIGIN + '/community/memories?view=mine&travel=1')
        page.locator('#travel-dialog').wait_for(state='visible')
        page.locator('#travel-status', has_text='첫 여행').wait_for()
        page.locator('#travel-new').click(); page.locator('#travel-title').fill('가을 제주 <script>bad()</script>')
        page.locator('#travel-destination').fill('제주'); page.locator('#travel-start').fill('2026-10-04'); page.locator('#travel-end').fill('2026-10-05')
        page.locator('#travel-add-step').click(); page.locator('.travel-step input[type=text]').fill('해변 산책')
        page.locator('.travel-step input[type=date]').fill('2026-10-09')
        page.locator('#travel-add-step').click(); page.locator('.travel-step input[type=text]').nth(1).fill('계획 밖 카페')
        page.locator('.travel-step').nth(1).get_by_role('button', name='↑ 위로').click()
        page.locator('.travel-step select').first.select_option('done')
        page.locator('#travel-save').click(); page.wait_for_function("() => document.querySelector('#travel-list button') && !document.querySelector('#travel-save').disabled")
        assert mock.travel_saves[-1]['steps'][0]['title'] == '계획 밖 카페'
        assert mock.travel_saves[-1]['steps'][0]['status'] == 'done'
        assert mock.travel_saves[-1]['steps'][1]['date'] == '2026-10-09'
        assert mock.travel_saves[-1]['steps'][0]['date'] is None
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth')
        ok('Private plan, optional dates, rescheduling outside trip, reorder and completion at 390px')
        assert '<script>' in page.locator('#travel-list').text_content()
        assert not errors, errors
        ok('Plan and activity text safely rendered without HTML interpretation')
        page.locator('#travel-title').fill('변경한 계획'); mock.conflict = True; page.locator('#travel-save').click()
        page.locator('#travel-error', has_text='다른 곳').wait_for()
        assert page.locator('#travel-title').input_value() == '변경한 계획'
        assert mock.plans[PLAN]['title'] != '변경한 계획'
        ok('Revision conflict retains user edits and does not overwrite')
        mock.conflict = False; page.locator('#travel-save').click(); page.wait_for_function("() => document.querySelector('#travel-error').textContent === '' && !document.querySelector('#travel-save').disabled")
        page.locator('#travel-record').click(); page.locator('#compose-dialog').wait_for(state='visible')
        page.wait_for_function("() => window.MemoryTravel.getSelection()?.plan_id === '" + PLAN + "'")
        page.locator('#caption').fill('예정에 없던 행복한 순간'); page.locator('#publish').click(); page.locator('#compose-dialog').wait_for(state='hidden')
        page.locator('#travel-notice', has_text='기록을 여행 계획에 연결').wait_for()
        assert mock.links[-1] == {'post_id': POST, 'step_id': None}
        assert mock.saves[-1]['visibility'] == 'private'
        ok('Compose saves original post then links the trip without changing publication')
        # Failure after successful post save must be explained, not repost the memory.
        mock.link_fail = True
        page.evaluate("window.dispatchEvent(new CustomEvent('memory:saved', {detail:{postId:'" + POST + "',selection:{plan_id:'" + PLAN + "',step_id:null}}}))")
        page.locator('#travel-notice', has_text='게시글은 저장됐지만').wait_for()
        assert len(mock.saves) == 1
        ok('Link failure preserves saved memory and clearly explains recovery')
        mock.link_fail = False
        page.locator('[data-travel-open]').first.click(); page.locator('#travel-list button').first.click()
        page.locator('#travel-records a').wait_for()
        assert page.locator('#travel-records a').get_attribute('href').endswith('/memories/p/' + POST)
        stable_revision = mock.plans[PLAN]['revision']
        page.locator('#travel-notes').fill('연결 해제 후에도 보존할 작성 중 메모')
        page.locator('#travel-records button').click()
        page.wait_for_function("() => document.querySelector('#travel-records').children.length === 0 && !document.querySelector('#travel-save').disabled")
        page.locator('#travel-save').click()
        page.wait_for_function("() => document.querySelector('#travel-error').textContent === '' && !document.querySelector('#travel-save').disabled")
        assert mock.travel_saves[-1]['revision'] == stable_revision + 1
        assert mock.plans[PLAN]['notes'] == '연결 해제 후에도 보존할 작성 중 메모'
        ok('Own unlink adopts its single new revision while preserving dirty form text')
        page.locator('#travel-load-posts').click(); page.locator('#travel-existing option[value="' + POST + '"]').wait_for(state='attached')
        page.locator('#travel-existing').select_option(POST)
        stable_revision = mock.plans[PLAN]['revision']
        page.locator('#travel-notes').fill('연결 후에도 보존할 작성 중 메모')
        page.locator('#travel-link').click(); page.locator('#travel-records a').wait_for()
        page.locator('#travel-save').click()
        page.wait_for_function("() => document.querySelector('#travel-error').textContent === '' && !document.querySelector('#travel-save').disabled")
        assert mock.travel_saves[-1]['revision'] == stable_revision + 1
        assert mock.plans[PLAN]['notes'] == '연결 후에도 보존할 작성 중 메모'
        ok('Own link adopts its single new revision without reloading or discarding dirty text')
        stale_revision = mock.plans[PLAN]['revision']
        mock.plans[PLAN]['revision'] += 1
        mock.plans[PLAN]['notes'] = '다른 기기에서 바꾼 메모'
        page.locator('#travel-records button').click(); page.wait_for_function("() => document.querySelector('#travel-records').children.length === 0")
        assert len(mock.posts) == 1
        ok('Unlink removes only the itinerary association, retaining post')
        page.locator('#travel-notes').fill('현재 창의 미저장 메모'); page.locator('#travel-save').click()
        page.locator('#travel-error', has_text='다른 곳').wait_for()
        assert mock.travel_saves[-1]['revision'] == stale_revision
        assert mock.plans[PLAN]['notes'] == '다른 기기에서 바꾼 메모'
        ok('Unlink response does not upgrade editor revision or overwrite concurrent edits')
        page.locator('#travel-list button').first.click(); page.locator('#travel-confirm-accept').click()
        page.wait_for_function("() => document.querySelector('#travel-notes').value === '다른 기기에서 바꾼 메모'")
        stale_revision = mock.plans[PLAN]['revision']; mock.plans[PLAN]['revision'] += 1
        mock.plans[PLAN]['notes'] = '다른 기기의 두 번째 수정'
        page.locator('#travel-load-posts').click(); page.locator('#travel-existing option[value="' + POST + '"]').wait_for(state='attached')
        page.locator('#travel-existing').select_option(POST); page.locator('#travel-link').click(); page.locator('#travel-records a').wait_for()
        page.locator('#travel-notes').fill('링크 후의 미저장 메모'); page.locator('#travel-save').click()
        page.locator('#travel-error', has_text='다른 곳').wait_for()
        assert mock.travel_saves[-1]['revision'] == stale_revision
        assert mock.plans[PLAN]['notes'] == '다른 기기의 두 번째 수정'
        ok('Link response also preserves editor revision across concurrent edits')
        # Read and save requests hold the editor so a slower response cannot discard new input.
        page.locator('#travel-list button').first.click(); page.locator('#travel-confirm-accept').click()
        page.wait_for_function("() => document.querySelector('#travel-notes').value === '다른 기기의 두 번째 수정'")
        page.locator('#travel-notes').fill('느린 저장 중에도 유지할 입력')
        mock.delay_next = 'PATCH'; page.locator('#travel-save').click()
        assert page.locator('#travel-title').is_disabled()
        assert page.locator('#travel-notes').is_disabled()
        assert page.locator('#travel-new').is_disabled()
        assert page.locator('#travel-add-step').is_disabled()
        assert page.locator('#travel-list button').first.is_disabled()
        pending = mock.pending; assert pending is not None; mock.pending = None; mock.route(pending)
        page.wait_for_function("() => !document.querySelector('#travel-save').disabled")
        assert page.locator('#travel-notes').input_value() == '느린 저장 중에도 유지할 입력'
        assert mock.plans[PLAN]['notes'] == '느린 저장 중에도 유지할 입력'
        ok('Delayed saves lock editable fields and plan navigation, preserving submitted input')
        mock.delay_next = 'GET'; page.locator('#travel-list button').first.click()
        assert page.locator('#travel-new').is_disabled()
        assert page.locator('#travel-title').is_disabled()
        pending = mock.pending; assert pending is not None; mock.pending = None; mock.route(pending)
        page.wait_for_function("() => !document.querySelector('#travel-title').disabled")
        ok('Delayed plan reads cannot race with another plan or unsaved typing')
        page.clock.install()
        page.locator('#travel-notes').fill('통신이 멈춰도 잃지 않을 메모')
        mock.delay_next = 'PATCH'; page.locator('#travel-save').click()
        assert page.locator('#travel-save').is_disabled()
        page.clock.run_for(30001)
        page.locator('#travel-error', has_text='응답이 늦어').wait_for()
        assert page.locator('#travel-notes').input_value() == '통신이 멈춰도 잃지 않을 메모'
        assert not page.locator('#travel-new').is_disabled()
        assert not page.locator('#travel-notes').is_disabled()
        if mock.pending is not None: mock.pending.abort()
        mock.pending = None
        ok('30-second network timeout releases controls and preserves unsaved input')
        # Unsaved edits require a user choice before closing the modal.
        page.locator('#travel-notes').fill('아직 저장하지 않은 메모')
        page.locator('[data-travel-close]').click()
        page.locator('#travel-confirm-cancel').click()
        assert page.locator('#travel-dialog').is_visible()
        assert page.locator('#travel-notes').input_value().endswith('메모')
        ok('Unsaved changes protected when closing the modal')
        page.screenshot(path='/tmp/qaredi-travel-mobile.png', full_page=True)
        assert not errors, errors
        page.locator('[data-travel-close]').click()
        page.locator('#travel-confirm-accept').click()
        page.locator('[data-travel-open]').first.click(); page.locator('#travel-list button').first.click()
        page.locator('#travel-delete').click(); page.locator('#travel-confirm-cancel').click()
        assert PLAN in mock.plans
        page.locator('#travel-delete').click(); page.locator('#travel-confirm-accept').click()
        page.wait_for_function("() => document.querySelector('#travel-form').hidden && !document.querySelector('#travel-new').disabled")
        assert PLAN not in mock.plans and len(mock.posts) == 1
        ok('Inline delete confirmation can cancel; confirmed deletion preserves memory')
        browser.close()
    print(f'{len(checks)} travel browser checks passed; mocked HTTP only')


if __name__ == '__main__': main()
