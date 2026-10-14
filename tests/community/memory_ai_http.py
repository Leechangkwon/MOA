#!/usr/bin/env python3
"""Actual PHP/DB and 390px append/save; only provider transport is replaced in the disposable copy."""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
from playwright.sync_api import sync_playwright,expect
from urllib.parse import urlsplit
import struct,zlib
assert str(root).startswith('/tmp/qaredi-stage13-')
# Q:F-13 G-1 — 서버는 Gemini 로 보내는 사진을 크기와 상관없이 늘 디코딩해 다시 인코딩한다. memories_http.py 의 1×1 PNG 는
# IDAT CRC 가 틀려(libpng 가 거부) 머리만 읽히는 사진이라, 이 파일과 이 머리를 빌려 쓰는 memory_story_http.py 의 기본 사진은
# 같은 1×1 픽셀을 CRC 가 맞게 다시 묶은 PNG 로 쓴다. 옛 PNG 는 「디코딩되지 않는 사진은 원본째 보내지 않는다」 시험에 쓴다.
corrupt_png=png
def png_chunk(kind,data):return struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data)&0xffffffff)
png=b'\x89PNG\r\n\x1a\n'+png_chunk(b'IHDR',struct.pack('>IIBBBBB',1,1,8,4,0,0,0))+png_chunk(b'IDAT',zlib.compress(b'\x01\xff\xff'))+png_chunk(b'IEND',b'')
def upload(user='employee',data=None,name='합성사진.png',expected=201):return call('POST','/api/memories/media',user=user,expected=expected,files={'file':(name,png if data is None else data,'application/octet-stream')})
transport=root/'src/Fetch.php'
original=transport.read_text()
needle="        $host = 'generativelanguage.googleapis.com';"
assert original.count(needle)==1
transport.write_text(original.replace(needle,"        return require __DIR__ . '/memory-ai-test-transport.php';\n"+needle))
(root/'src/memory-ai-test-transport.php').write_bytes((root/'tests/community/memory_ai_transport.php.fixture').read_bytes())
storage=root.parent/'storage';capture=storage/'transport-capture.jsonl'
def mode(**kw): (storage/'transport-mode.json').write_text(json.dumps(kw))
def reset_rate(): sql("UPDATE member_rate_limits SET window_start=now()-interval '2 minutes'")
def captures():return [json.loads(x) for x in capture.read_text().splitlines()] if capture.exists() else []
def body(post):return dict(consent=True,request_id=str(uuid.uuid4()),revision=post['revision'],media_ids=[x['id'] for x in post['media']],tone='담백하고 따뜻한',wish='사진을 참고한 추가 문장')
def post(pid):return call('GET','/api/memories/'+pid)['post']
def generate(pid,b,expected=200):reset_rate();return call('POST','/api/memories/'+pid+'/caption',b,expected=expected)
try:
 for u in users:call('GET','/api/me/space',user=u)
 media=upload();pid=save(dict(payload(),media_ids=[media['id']]))
 before=post(pid);request=body(before)
 check(call('GET','/api/memories/ai')['ready'] is False,'missing Gemini setting is not ready')
 generate(pid,request,503);check(not captures(),'disabled provider makes no outbound request')
 keydir=root.parent/'private-messenger-ai';keydir.mkdir(mode=0o700)
 (keydir/'gemini.key').write_text('synthetic-key-for-disposable-test-only');(keydir/'gemini.key').chmod(0o600)
 sql('UPDATE member_ai_settings SET enabled=true');mode()
 check(call('GET','/api/memories/ai')['ready'] is True,'existing Gemini settings and key enable caption route')
 got=generate(pid,request)
 check(got['state']=='complete' and got['photo_count']==1,'real PHP ledger completes photo preview')
 sent=captures()[0]['contents'][0]['parts']
 # Q:F-13 G-1 — 글 형식도 저장된 원본이 아니라 다시 인코딩한 참조 사진(JPEG)이 나간다
 ref=base64.b64decode(sent[1]['inlineData']['data'])
 check(sent[1]['inlineData']['mimeType']=='image/jpeg' and ref[:2]==b'\xff\xd8' and ref!=png and json.loads(sent[0]['text'])['현재 메모']==before['caption'],'verified photo re-encoded (not the stored original) and current caption reach transport')
 check(post(pid)==before,'generation preserves all post fields and original media')
 count=len(captures());check(generate(pid,request)['text']==got['text'] and len(captures())==count,'same request returns existing answer without a second provider call')
 generate(pid,dict(request,wish='changed'),409)
 for error in (429,504):
  mode(error=error);failed=generate(pid,dict(request,request_id=str(uuid.uuid4())))
  check(failed['state']=='failed' and failed['text']=='' and bool(failed['error']),'provider error '+str(error)+' becomes recoverable failed preview')
  check(post(pid)==before,'provider failure preserves original '+str(error))
 mode();sql('UPDATE member_ai_settings SET user_daily=1')
 generate(pid,dict(request,request_id=str(uuid.uuid4())),429)
 check(len(captures())==3,'shared daily budget blocks extra provider use')
 sql('UPDATE member_ai_settings SET user_daily=40')
 # Race on a second PHP worker: provider has started, then record changes.
 for mutation in ('revision','deleted_at'):
  fresh=post(pid);b=body(fresh);mode(delay=True);reset_rate();count=len(captures())
  with ThreadPoolExecutor(max_workers=1) as pool:
   future=pool.submit(raw,'POST','/api/memories/'+pid+'/caption',b)
   for _ in range(100):
    if len(captures())>count:break
    time.sleep(.03)
   check(len(captures())>count,'provider started before '+mutation+' race')
   sql("UPDATE community.memory_posts SET "+('revision=revision+1' if mutation=='revision' else 'deleted_at=now()')+" WHERE id='"+pid+"'")
   response=future.result()
  check(response.status_code==(409 if mutation=='revision' else 404),'late answer rejected after '+mutation)
  if mutation=='deleted_at':sql("UPDATE community.memory_posts SET deleted_at=NULL WHERE id='"+pid+"'")
 mode();reset_rate()
 # Ordinary AI chat keeps the text-only route and shared ledger.
 headers={'User-Agent':'tbox-review/'+time.strftime('%Y-%m-%d'),'Cookie':'__Host-tbox_session='+users['employee']['session'],'X-CSRF-Token':users['employee']['csrf']}
 r=requests.post(site+'/tbox/api/messages/ai',headers=headers,json={'thread_id':None,'text':'일반 대화 시험','request_id':str(uuid.uuid4())},timeout=10)
 check(r.status_code==200 and r.json()['turn']['state']=='complete','ordinary Gemini chat still completes')
 # R1b — 대화 기록의 모델 = 실제로 부른 모델(MemberAi::MODEL · 열 기본값 3.5 에 맡기지 않음)
 check(sql("SELECT model FROM member_ai_turns ORDER BY id DESC LIMIT 1")=='gemini-3.6-flash','R1b chat turn records model gemini-3.6-flash')
 check(all('inlineData' not in p for p in captures()[-1]['contents'][-1]['parts']),'ordinary Gemini chat remains text only')
 for error in (None,429):
  mode(**({'error':error} if error else {}));reset_rate()
  r=requests.post(site+'/tbox/api/messages/assist',headers=headers,json={'action':'polish','aspect':'1:1','language':'ko','peer_id':int(users['other']['id']),'request_id':str(uuid.uuid4()),'source_id':None,'text':'기존 문장 도구 시험'},timeout=10)
  check(r.status_code==200 and r.json()['job']['state']==('failed' if error else 'complete'),'ordinary Gemini writing tool '+('provider failure' if error else 'success'))
  # R1b — 문장 도구 줄의 모델 = gemini-3.6-flash(DB CHECK 가 3.6 을 받는다 — 2026-10-15_member_ai_model_36.sql)
  check(sql("SELECT model FROM member_ai_assists WHERE action='polish' ORDER BY created_at DESC LIMIT 1")=='gemini-3.6-flash','R1b writing tool row records model gemini-3.6-flash'+(' (provider failure)' if error else ''))
 check(sql("SELECT count(*) FROM audit_logs WHERE action IN ('member.ai.send','member.ai.assist') AND result='deny' AND reason IN ('provider_limit','provider_unavailable')")=='3','provider failures retain reason-coded audit records')
 mode()
 reset_rate();before=post(pid)
 with sync_playwright() as pw:
  browser=pw.chromium.launch();ctx=browser.new_context(viewport={'width':390,'height':844},user_agent=headers['User-Agent'])
  ctx.add_cookies([{'name':'__Host-tbox_session','value':users['employee']['session'],'domain':urlsplit(site).hostname,'path':'/','secure':True,'httpOnly':True}])
  tab=ctx.new_page();tab.goto(base+'/memories',wait_until='networkidle')
  # 새로 만든 fixture 회원에게는 첫 방문 안내가 뜬다(memory_story_http.py 와 같게 화면에서 닫는다)
  tab.get_by_role('button',name='나중에 볼게요',exact=True).click();expect(tab.locator('.usage-guide-dialog')).to_have_count(0)
  tab.get_by_role('button',name='AI로 꾸미기',exact=True).click();expect(tab.locator('#gemini-generate')).to_be_enabled()
  tab.locator('#gemini-photos input').uncheck();tab.locator('#gemini-generate').click();expect(tab.locator('#studio-result')).to_have_value('합성 사진을 참고한 추가 초안입니다.')
  check(post(pid)==before,'390px actual PHP preview never autosaves')
  tab.locator('#studio-append').click();expect(tab.locator('#caption')).to_have_value(before['caption']+'\n\n합성 사진을 참고한 추가 초안입니다.')
  expect(tab.locator('#media-preview img')).to_have_count(1)
  tab.locator('#publish').click();expect(tab.locator('#compose-dialog')).not_to_be_visible()
  after=post(pid)
  check(after['caption']==before['caption']+'\n\n합성 사진을 참고한 추가 초안입니다.','390px append and explicit save persist combined caption')
  check(after['media']==before['media'] and after['original_media']==before['original_media'] and after['original_caption']==before['original_caption'] and after['visibility']==before['visibility'],'photos, original caption, original media and visibility survive actual save')
  tab.screenshot(path=str(out/'gemini-saved-mobile.png'));browser.close()
 # Q:F-13 G-1 — 위치(GPS)·카메라 정보가 든 JPEG(작은 것 ≤1280px·≤600KB / 큰 것)를 사진 기록에 올리고 Gemini 글쓰기
 # (사진 이야기 = 화면이 보내는 형식 · 글 형식 = API 로만) → 합성 provider 가 받은 사진에 EXIF(APP1)·XMP·GPS·카메라 표식 0,
 # 다시 인코딩한 JPEG · 해상도 상한 그대로 · 저장된 원본 파일·내려받는 바이트·글은 그대로(sha256 같음).
 import io
 from PIL import Image
 MARK=b'QF13H-SyntheticCam'
 XMP=b'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="37,33.99N"/></rdf:RDF></x:xmpmeta>'
 def exif_jpeg(w,h,quality):
  img=Image.merge('RGB',(Image.linear_gradient('L').resize((w,h)),Image.radial_gradient('L').resize((w,h)),Image.effect_noise((w,h),40)))
  ex=Image.Exif();ex[0x010F]=MARK.decode();ex[0x0110]='QF13-H GPS';gps=ex.get_ifd(0x8825);gps[1]='N';gps[2]=(37.0,33.0,59.5);gps[3]='E';gps[4]=(126.0,58.0,40.2)
  buf=io.BytesIO();img.save(buf,'JPEG',quality=quality,exif=ex.tobytes(),xmp=XMP);return buf.getvalue()
 def app_markers(data):
  # SOS 앞 머리 조각의 APPn 표식(0xE0~0xEF) 목록
  i=2;found=[]
  while i+4<=len(data) and data[i]==0xFF and data[i+1]!=0xDA:
   length=int.from_bytes(data[i+2:i+4],'big')
   if 0xE0<=data[i+1]<=0xEF:found.append(data[i+1])
   i+=2+length
  return found
 def clean(data):
  img=Image.open(io.BytesIO(data));e=img.getexif()
  return (data[:2]==b'\xff\xd8' and img.format=='JPEG' and set(app_markers(data))<={0xE0} and b'Exif\x00\x00' not in data and MARK not in data
          and b'adobe:ns:meta' not in data and b'ns.adobe.com' not in data and len(e)==0 and not dict(e.get_ifd(0x8825))),img.size
 small,large=exif_jpeg(900,600,85),exif_jpeg(2400,1600,95)
 check(len(small)<=600000 and len(large)>600000,'G-1 fixtures: small JPEG 900x600 <=600KB, large JPEG 2400x1600 >600KB')
 for label,data in (('small',small),('large',large)):
  e=Image.open(io.BytesIO(data)).getexif()
  check(0xE1 in app_markers(data) and b'Exif\x00\x00' in data and MARK in data and XMP in data and dict(e.get_ifd(0x8825)).get(1)=='N','G-1 control: '+label+' original carries EXIF camera mark, XMP and GPS')
  m=upload(data=data,name='qf13h-'+label+'.jpg');gid=save(dict(payload(),media_ids=[m['id']]));gpost=post(gid)
  # Q:F-13 P2 G-2 — 사진 기록은 이제 **올릴 때** 메타데이터를 뗀 판을 저장한다(H 때의 「저장 원본 그대로」 규칙을 바꾼다).
  # 그래서 저장 지문은 올린 바이트의 지문이 아니라 저장된 파일의 지문이고, 그 파일에는 EXIF·GPS·XMP·카메라 표식이 없다.
  stored0=(root.parent/'storage/memories'/m['id']).read_bytes();digest=hashlib.sha256(stored0).hexdigest()
  check(m['mime']=='image/jpeg' and sql("SELECT sha256 FROM community.memory_media WHERE id='"+m['id']+"'")==digest and digest!=hashlib.sha256(data).hexdigest()
        and b'Exif\x00\x00' not in stored0 and MARK not in stored0 and b'ns.adobe.com' not in stored0 and not dict(Image.open(io.BytesIO(stored0)).getexif().get_ifd(0x8825)),
        'G-1 '+label+': stored as the metadata-stripped photo (G-2: no EXIF/GPS/XMP/camera mark) with that file sha256')
  for fmt in ('story','text'):
   b=body(gpost)
   if fmt=='story':b['format']='story'
   count=len(captures());got=generate(gid,b)
   check(got['state']=='complete' and len(captures())==count+1,'G-1 '+label+' '+fmt+': generation completes with one provider call')
   images=[p['inlineData'] for p in captures()[-1]['contents'][0]['parts'] if 'inlineData' in p]
   sent=base64.b64decode(images[0]['data']);ok,size=clean(sent)
   check(len(images)==1 and images[0]['mimeType']=='image/jpeg' and sent!=data and ok,'G-1 '+label+' '+fmt+': outbound photo is a re-encoded JPEG with no EXIF/XMP/GPS/camera mark')
   check(size==((900,600) if label=='small' else (1280,853)),'G-1 '+label+' '+fmt+': resolution cap kept (small unchanged, large longest side 1280)')
  stored=(root.parent/'storage/memories'/m['id']).read_bytes();served=raw('GET','/api/memories/media/'+m['id'])
  check(hashlib.sha256(stored).hexdigest()==digest and served.status_code==200 and hashlib.sha256(served.content).hexdigest()==digest and post(gid)==gpost,'G-1 '+label+': stored file, served bytes and post unchanged by generation (sha256 same as at upload)')
 # 디코딩되지 않는 사진(머리만 맞는 PNG)은 원본째 보내지 않는다 — 제공자 요청 0, 실패한 미리보기로 끝난다
 m=upload(data=corrupt_png,name='qf13h-corrupt.png');gid=save(dict(payload(),media_ids=[m['id']]));gpost=post(gid)
 for fmt in ('story','text'):
  b=body(gpost)
  if fmt=='story':b['format']='story'
  count=len(captures());got=generate(gid,b)
  check(got['state']=='failed' and got['text']=='' and len(captures())==count,'G-1 undecodable photo ('+fmt+') is never sent: no provider request, failed preview')
 check(post(gid)==gpost,'G-1 undecodable photo request leaves the post unchanged')
 # 다른 길: 이 시험에서 합성 provider 에 닿은 사진 전부(글 형식 PNG 포함)가 메타데이터 없는 JPEG · 메신저 대화·글 도구 요청에는 사진 0
 every=[base64.b64decode(p['inlineData']['data']) for c in captures() for x in c.get('contents',[]) for p in x.get('parts',[]) if 'inlineData' in p]
 check(len(every)>=5 and all(clean(x)[0] for x in every),'G-1 every image that reached the synthetic provider in this suite is a metadata-free JPEG')
 assist=[c for c in captures() if '메신저 문장 도우미' in json.dumps(c.get('systemInstruction',{}),ensure_ascii=False)]
 check(len(assist)==2 and all('inlineData' not in json.dumps(c) for c in assist),'G-1 messenger writing tool requests carry no image bytes')
finally:
 (out/'results.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2));print('RESULT pass='+str(sum(x['pass'] for x in checks))+' fail='+str(sum(not x['pass'] for x in checks)),flush=True)
