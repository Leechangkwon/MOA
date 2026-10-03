#!/usr/bin/env python3
"""Photo selection regression in Chromium with mocked HTTP; no production access."""
import argparse
import json
import os
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from memories_browser import Mock, ORIGIN, photo


def choose(page, name, mime, size=None):
    # File.type may legally be empty; set_input_files infers a type from extension,
    # so use a real browser File/DataTransfer to preserve the device-supplied metadata.
    page.evaluate('''({name,mime,bytes,size}) => {
      const input=document.querySelector('#media-input'), transfer=new DataTransfer();
      transfer.items.add(new File([size ? new Uint8Array(size) : new Uint8Array(bytes)], name, {type:mime}));
      input.files=transfer.files;
      input.dispatchEvent(new Event('change', {bubbles:true}));
    }''', {'name':name,'mime':mime,'bytes':list(photo()),'size':size})


def main():
    parser=argparse.ArgumentParser();parser.add_argument('--output',type=Path,default=Path('/tmp/qaredi-memories-preview'));args=parser.parse_args()
    checks=[]
    def ok(label): checks.append(label);print('PASS',label,flush=True)
    with sync_playwright() as p:
        browser=p.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'),headless=True,args=['--no-sandbox'])
        errors=[]
        def draft():
            ctx=browser.new_context(viewport={'width':390,'height':844});mock=Mock();ctx.route(ORIGIN+'/**',mock.route)
            page=ctx.new_page();page.on('pageerror',lambda e:errors.append(str(e)))
            page.goto(ORIGIN+'/community/');page.locator('.threads-write').wait_for(state='visible');page.locator('.threads-write').click();page.locator('#caption').fill('남한산성 여행 기록')
            return ctx,page,mock
        for name,mime in [('IMG_20261003.JPG',''),('IMG_20261003.jpg','application/octet-stream'),('camera.jpg','image/jpg')]:
            ctx,page,mock=draft();choose(page,name,mime)
            assert page.locator('#media-preview img').count()==1
            assert '1개 선택됨' in page.locator('#media-selection-status').text_content()
            page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden')
            assert mock.uploads==1 and len(mock.saves[-1]['media_ids'])==1
            expect(page.locator('.post-media img')).to_have_count(1)
            page.locator('[data-view=mine]').click();page.wait_for_function("() => document.querySelector('#profile-stats').textContent.includes('1사진·영상')")
            ok(f'{mime or "empty MIME"} photo previews, uploads, saves and appears in feed/count');ctx.close()
        ctx,page,mock=draft();choose(page,'unsupported.HEIC','image/heic')
        assert page.locator('#media-selection-error').is_visible()
        page.locator('#publish').click()
        assert not mock.saves and not mock.uploads and page.locator('#compose-dialog').is_visible()
        page.wait_for_function("() => document.querySelector('#draft-status').textContent.includes('임시 저장했어요')")
        page.reload();page.locator('#resume-draft').wait_for(state='visible');page.locator('#resume-draft').click()
        assert page.locator('#media-selection-error').is_visible()
        page.locator('#publish').click();assert not mock.saves
        ok('Rejected selection cannot silently become a text-only post, including after draft reload')
        # A cancelled picker must leave the unresolved attachment error intact.
        page.locator('#media-input').set_input_files([])
        assert page.locator('#media-selection-error').is_visible()
        choose(page,'replacement.jpg','')
        assert page.locator('#media-selection-error').is_hidden()
        page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden');assert mock.uploads==1
        ok('Cancel retains the failure; selecting a supported replacement resolves it');ctx.close()
        ctx,page,mock=draft();choose(page,'large.jpg','image/jpeg',21*1024*1024)
        assert '20MB' in page.locator('#media-selection-error').text_content()
        page.locator('#publish').click();assert not mock.saves
        page.locator('#skip-rejected-media').click();page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden')
        assert mock.saves[-1]['media_ids']==[] and mock.uploads==0
        ok('Oversized photo blocks save until the user explicitly excludes it');ctx.close()
        ctx,page,mock=draft();choose(page,'keep.png','image/png');choose(page,'unknown-file','')
        assert page.locator('#media-preview img').count()==1
        page.locator('#publish').click();assert not mock.saves
        page.locator('#skip-rejected-media').click();page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden')
        assert len(mock.saves[-1]['media_ids'])==1
        ok('Excluding a rejected file preserves already selected photos');ctx.close()
        ctx,page,mock=draft();page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden')
        assert mock.saves[-1]['media_ids']==[] and mock.saves[-1]['visibility']=='private'
        ok('Intentional text-only private post still works without an attachment warning')
        page.locator('.owner-actions button',has_text='수정').click();page.locator('#compose-dialog').wait_for(state='visible')
        choose(page,'reattach.JPG','');page.wait_for_function("() => document.querySelector('#media-preview img')?.naturalWidth > 0")
        page.locator('#publish').click();page.locator('#compose-dialog').wait_for(state='hidden')
        assert mock.saves[-1]['caption']=='남한산성 여행 기록' and mock.saves[-1]['revision']==1 and len(mock.saves[-1]['media_ids'])==1
        ok('Editing an existing text-only post attaches a photo and preserves the caption');ctx.close()
        assert not errors,errors
        browser.close()
    output=args.output;output.mkdir(parents=True,exist_ok=True)
    (output/'attachments-results.json').write_text(json.dumps({'coverage':'Chromium + mocked HTTP; no physical phone or live DB','checks':checks},ensure_ascii=False,indent=2)+'\n')
    print(f'{len(checks)} attachment checks passed')

if __name__=='__main__':main()
