#!/usr/bin/env python3
"""Actual Chromium, mocked Gemini/HTTP. No provider calls or production access."""
import json
import os
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright, expect
from memories_browser import Mock, ORIGIN, POST, record


class GeminiMock(Mock):
    def __init__(self):
        super().__init__(); self.posts=[record()]; self.ready=True; self.requests=[]; self.fail=False
        self.answer='남한산성에서 남긴 오늘의 한 장. 오래 꺼내 보고 싶은 행복한 기억이 하나 더 생겼다.'

    def route(self, route):
        req=route.request; path=urlparse(req.url).path
        if path=='/community/api/memories/ai':
            route.fulfill(json={'ready':self.ready,'notice':'Gemini 연결을 준비 중입니다.'});return
        if path.endswith('/caption'):
            assert req.headers.get('x-csrf-token')=='fixture-csrf'
            self.requests.append(req.post_data_json)
            route.fulfill(status=503 if self.fail else 200,json={'error':'Gemini 응답을 받지 못했어요.'} if self.fail else {'state':'complete','text':self.answer,'photo_count':len(req.post_data_json['media_ids'])});return
        super().route(route)


def main():
    checks=[]
    def ok(label):checks.append(label);print('PASS',label,flush=True)
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'),headless=True,args=['--no-sandbox'])
        errors=[]
        def setup(mock=None):
            ctx=browser.new_context(viewport={'width':390,'height':844});mock=mock or GeminiMock();ctx.route(ORIGIN+'/**',mock.route)
            page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)));page.goto(ORIGIN+'/community/')
            page.locator('button',has_text='AI로 꾸미기').click();expect(page.locator('#gemini-status')).not_to_contain_text('확인하고')
            return ctx,page,mock
        ctx,page,mock=setup();assert not mock.requests and not mock.saves
        out=Path('/tmp/qaredi-memories-preview');out.mkdir(parents=True,exist_ok=True)
        page.screenshot(path=str(out/'gemini-compose-mobile.png'))
        assert page.locator('#gemini-photos input:checked').count()==1
        page.locator('#gemini-generate').click();expect(page.locator('#studio-result')).to_have_value(mock.answer)
        b=mock.requests[-1];assert b['consent'] is True and b['media_ids']==[record()['media'][0]['id']] and b['revision']==1
        assert not mock.saves and page.locator('#studio-dialog').is_visible()
        ok('Explicit request sends selected photo IDs/consent/CSRF; preview does not publish')
        page.locator('#studio-append').click();expect(page.locator('#caption')).to_have_value(record()['caption']+'\n\n'+mock.answer)
        assert page.locator('#media-preview img').count()==1 and not mock.saves
        page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden')
        assert mock.saves[-1]['caption']==record()['caption']+'\n\n'+mock.answer and len(mock.saves[-1]['media_ids'])==1
        ok('Append preserves existing caption and photo; saving needs a separate click');ctx.close()
        mock=GeminiMock();mock.posts[0]['media']=[];ctx,page,mock=setup(mock)
        page.locator('#gemini-generate').click();expect(page.locator('#studio-result')).to_have_value(mock.answer)
        assert mock.requests[-1]['media_ids']==[]
        ok('Text-only record can request a draft without claiming a photo was selected');ctx.close()
        mock=GeminiMock();mock.ready=False;ctx,page,mock=setup(mock)
        expect(page.locator('#gemini-generate')).to_be_disabled();assert not mock.requests
        ok('Unconfigured Gemini is explained and generation stays disabled');ctx.close()
        ctx,page,mock=setup();mock.fail=True;page.locator('#gemini-generate').click();expect(page.locator('#studio-error')).to_contain_text('응답')
        first=mock.requests[-1]['request_id'];mock.fail=False;page.locator('#gemini-generate').click();expect(page.locator('#studio-result')).to_have_value(mock.answer)
        assert mock.requests[-1]['request_id']==first
        page.locator('#gemini-generate').click();expect(page.locator('#gemini-generate')).to_be_enabled()
        assert mock.requests[-1]['request_id']!=first
        ok('Transport retry keeps request ID; explicit new generation gets a new ID')
        page.locator('#studio-result').fill('x'*5000);page.locator('#studio-append').click();expect(page.locator('#studio-error')).to_contain_text('5,000자')
        assert page.locator('#studio-dialog').is_visible() and not mock.saves
        ok('Append length check preserves the original instead of truncating it');ctx.close()
        mock=GeminiMock();mock.posts[0]['media']=[dict(record()['media'][0],id=f'{i}2222222-2222-4222-8222-222222222222') for i in range(5)]
        ctx,page,mock=setup(mock);assert page.locator('#gemini-photos input:checked').count()==4
        page.locator('#gemini-photos input').last.check();page.locator('#gemini-generate').click();expect(page.locator('#studio-error')).to_contain_text('최대 4장')
        assert not mock.requests
        ok('Photo limit is visible and excessive selection never calls the provider');ctx.close()
        ctx,page,mock=setup()
        page.evaluate('''() => { const fetchOriginal=window.fetch;window.fetch=(url,options)=>String(url).endsWith('/caption') ? new Promise(resolve=>{window.finishCaption=()=>resolve(new Response(JSON.stringify({state:'complete',text:'old post result'}),{status:200,headers:{'Content-Type':'application/json'}}));}) : fetchOriginal(url,options); }''')
        page.locator('#gemini-generate').click();page.wait_for_function('typeof window.finishCaption === "function"')
        page.locator('[data-close="studio-dialog"]').click();mock.posts=[dict(record(),id='33333333-3333-4333-8333-333333333333',caption='다른 기록')]
        page.locator('#refresh').click();expect(page.locator('.post-caption')).to_have_text('다른 기록');page.locator('button',has_text='AI로 꾸미기').click()
        expect(page.locator('#gemini-generate')).to_be_enabled();page.evaluate('window.finishCaption()');page.wait_for_timeout(100)
        expect(page.locator('#studio-result')).to_have_value('');expect(page.locator('#gemini-wish')).to_be_enabled()
        assert page.locator('#studio-dialog').evaluate('(e)=>e.scrollWidth<=e.clientWidth+1')
        ok('Late response cannot overwrite another post; mobile controls remain usable');ctx.close()
        assert not errors,errors;browser.close()
    out=Path('/tmp/qaredi-memories-preview');out.mkdir(parents=True,exist_ok=True)
    (out/'gemini-results.json').write_text(json.dumps({'coverage':'Chromium + mocked HTTP; Gemini/PHP/DB not executed','checks':checks},ensure_ascii=False,indent=2)+'\n')
    print(f'{len(checks)} Gemini browser checks passed')

if __name__=='__main__':main()
