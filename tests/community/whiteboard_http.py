#!/usr/bin/env python3
"""Q:F-17 acceptance: real PHP/Postgres plus real Excalidraw/Chromium in owned empty harness."""
import base64
import copy
import hashlib
import getpass
import json
import os
from pathlib import Path
import secrets
import subprocess
import socket
import sys
import time
import uuid
import urllib.request
import struct
import zlib

sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'mcp'))
from remote_http import Acceptance, guard

manifest=json.loads(Path(os.environ['FILE_RENAME_MANIFEST']).read_text())
instance=guard(manifest,os.environ['FILE_RENAME_FIXTURE_CONFIG'],os.environ.get('REMOTE_MCP_HARNESS'))
out=Path(os.environ['FILE_RENAME_OUTPUT'])
a=Acceptance(manifest,instance,out)
owned_processes=[]

def shape(id='shape1',**more):
    return dict(id=id,type='rectangle',x=0,y=0,width=200,height=100,angle=0,strokeColor='#1e1e1e',
        backgroundColor='transparent',fillStyle='solid',strokeWidth=1,strokeStyle='solid',roughness=1,
        opacity=100,groupIds=[],frameId=None,index='a0',roundness=None,seed=1,version=1,versionNonce=500,
        isDeleted=False,boundElements=None,updated=1,link=None,locked=False,**more)

def push(pid,bid,e,base=0,expected=200,user='employee'):
    time.sleep(0.26)
    return a.api('POST','/pages/'+pid+'/whiteboard/elements',dict(whiteboard_id=bid,base_seq=base,elements=e),expected=expected,user=user)

try:
    for user in a.users:
        a.api('GET','/me/space',user=user)
    a.sql("UPDATE users SET team_id=NULL WHERE id=:'uid'",uid=a.users['admin']['id'])
    project=a.api('POST','/pages',dict(title='회의 프로젝트',database=True,preset='project'),expected=201)['page']['id']
    pid=a.api('POST','/work/documents',dict(project_id=project,request_id=str(uuid.uuid4()),title='회의 작업',
        blocks=[dict(text='사람이 작성한 원문')]),expected=201)['id']
    path='/pages/'+pid+'/whiteboard'
    if os.environ.get('WHITEBOARD_TEST_BROWSER_ONLY') == '1':
        current=a.api('POST',path,{},expected=201)
        push(pid,current['board']['id'],[shape()])
        a.sql("INSERT INTO community.page_shares(page_id,user_id,level,granted_by) SELECT id,:'uid','edit',:'by' FROM community.pages WHERE public_id=CAST(:'pid' AS uuid)",uid=a.users['manager']['id'],by=a.users['employee']['id'],pid=project)
    else:
        normal=a.api('POST','/pages',dict(title='일반 문서'),expected=201)['page']['id']
        a.api('GET','/pages/'+normal+'/whiteboard',expected=404)
        empty=a.api('GET',path); a.check(empty['board'] is None and empty['can']['create'],'no board created on read')
        a.api('POST',path,{},expected=201); current=a.api('GET',path); bid=current['board']['id']
        a.check(current['board']['version_no']==1 and current['can']['draw'],'project task can open board')
        for other in ['other','admin','guest']:
            a.api('GET',path,user=other,expected=404)
        a.request('GET','/community/api'+path,expected=401)
        a.request('POST','/community/api'+path+'/elements',user='employee',csrf=False,json={},expected=403)
        e=shape(); e['customData']={'actor_id':a.users['other']['id']}; e['actor_id']=a.users['other']['id']
        result=push(pid,bid,[e]); a.check(result['last_seq']==1 and len(result['accepted'])==1,'first shape committed')
        saved=a.api('GET',path)['elements'][0]
        a.check('customData' not in saved and 'actor_id' not in saved,'client provenance fields discarded')
        a.check(push(pid,bid,[e])['last_seq']==1,'identical retry adds no event')
        winner=copy.deepcopy(saved); winner.update(version=2,versionNonce=300,x=40)
        a.check(push(pid,bid,[winner],1)['last_seq']==2,'newer element wins')
        loser=copy.deepcopy(winner); loser.update(versionNonce=400,x=60)
        a.check(push(pid,bid,[loser],1)['rejected'][0]['reason']=='conflict','equal version larger nonce loses')
        tie=copy.deepcopy(winner); tie.update(versionNonce=200,x=70)
        a.check(push(pid,bid,[tie],1)['last_seq']==3,'equal version smaller nonce wins')
        for bad in [dict(e,type='embeddable'),dict(e,type='image'),dict(e,id='../x'),dict(e,link='javascript:alert(1)'),dict(e,x='oops')]:
            a.check(bool(push(pid,bid,[bad])['rejected']),'unsupported or malformed element rejected')
        push(pid,str(uuid.uuid4()),[e],expected=409)
        a.api('POST',path+'/elements',dict(whiteboard_id=bid,base_seq=True,elements=[e]),expected=400)
        # Read shares see the board, cannot write; edit shares preserve original authorship.
        a.sql("INSERT INTO community.page_shares(page_id,user_id,level,granted_by) SELECT id,:'uid','view',:'by' FROM community.pages WHERE public_id=CAST(:'pid' AS uuid)",
            uid=a.users['manager']['id'],by=a.users['employee']['id'],pid=project)
        a.check(not a.api('GET',path,user='manager')['can']['draw'],'inherited view share is read-only')
        push(pid,bid,[winner],expected=403,user='manager')
        a.check(int(a.sql("SELECT count(*) FROM audit_logs WHERE action='whiteboard.change' AND result='deny'"))>0,'read-only write refusal is audited')
        a.sql("UPDATE community.page_shares SET level='edit' WHERE user_id=:'uid'",uid=a.users['manager']['id'])
        changed=dict(tie,version=3,versionNonce=100,x=100)
        push(pid,bid,[changed],3,user='manager')
        created=a.sql("SELECT e.created_by||':'||e.updated_by FROM community.whiteboard_elements e JOIN community.whiteboards b ON b.id=e.whiteboard_id WHERE b.public_id=CAST(:'bid' AS uuid)",bid=bid)
        a.check(created==str(a.users['employee']['id'])+':'+str(a.users['manager']['id']),'original author retained after collaborator edit')
        # Text and arrow relationships are extracted as inert data.
        text=shape('text1');text.update(type='text',text='결정 <script>미실행</script>',originalText='결정',containerId='shape1',fontSize=20,fontFamily=1,lineHeight=1.25,textAlign='left',verticalAlign='top',autoResize=True,index='a1')
        push(pid,bid,[text],4)
        temporary=shape('delete_me');temporary.update(x=400,y=300,index='a2')
        push(pid,bid,[temporary],5)
        push(pid,bid,[dict(temporary,isDeleted=True,version=2,versionNonce=100)],6)
        enormous=shape('oversize');enormous.update(type='freedraw',points=[[1000000,1000000]]*5000)
        push(pid,bid,[enormous],expected=413)
        history=a.api('GET',path+'/events?limit=2');a.check(len(history['events'])==2 and history['has_more'],'event pagination is bounded')
        whole=a.api('GET',path+'/events?limit=500')['events']
        a.check([x['seq'] for x in whole]==list(range(1,len(whole)+1)),'server sequence contiguous')
        a.check(whole[0]['actor_id']==a.users['employee']['id'],'event actor comes from session')
        a.check(whole[0]['prev_hash']=='0'*64 and all(whole[i]['prev_hash']==whole[i-1]['hash'] for i in range(1,len(whole))),'event chain links every record')
        chunk=lambda kind,data: struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data)&0xffffffff)
        png=b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>2I5B',4,4,8,2,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+b'\xff\xff\xff'*4)*4))+chunk(b'IEND',b'')
        seq=a.api('GET',path)['board']['last_seq']
        a.request('POST','/community/api'+path+'/confirm',user='employee',data={'whiteboard_id':bid,'last_seq':str(seq-1)},files={'png':('meeting.png',png,'image/png')},expected=409)
        locked=a.request('POST','/community/api'+path+'/confirm',user='employee',data={'whiteboard_id':bid,'last_seq':str(seq)},files={'png':('meeting.png',png,'image/png')}).json()
        a.check(locked['board']['state']=='locked' and len(locked['board']['snapshot']['scene_sha256'])==64,'confirmation creates immutable evidence and locks')
        push(pid,bid,[dict(e,version=7)],seq,expected=409)
        described=a.api('GET','/work/whiteboards/'+pid)
        a.check(described['nodes'][0]['text'].startswith('결정') and described['nodes'][0]['author']['created_name'],'MCP gets text and server author')
        a.check('x' not in described['nodes'][0] and '<script>' not in described['mermaid'],'Mermaid escapes drawing labels')
        a.check(a.api('GET','/work/whiteboards')['items'][0]['page_id']==pid,'board listing includes only visible tasks')
        a.check(a.api('GET','/work/whiteboards',user='other')['items']==[],'listing SQL omits private boards')
        a.check(a.api('GET','/work/whiteboards',user='admin')['items']==[],'listing has no admin override')
        a.sql("UPDATE community.page_attachments SET scan_status='pending' WHERE id=(SELECT png_attachment_id FROM community.whiteboard_snapshots WHERE whiteboard_id=(SELECT id FROM community.whiteboards WHERE public_id=CAST(:'bid' AS uuid)))",bid=bid)
        a.api('GET','/work/whiteboards/'+pid+'/image',expected=423)
        a.sql("UPDATE community.page_attachments SET scan_status='clean' WHERE id=(SELECT png_attachment_id FROM community.whiteboard_snapshots WHERE whiteboard_id=(SELECT id FROM community.whiteboards WHERE public_id=CAST(:'bid' AS uuid)))",bid=bid)
        image=a.api('GET','/work/whiteboards/'+pid+'/image')
        a.check(image['mime_type']=='image/jpeg' and len(base64.b64decode(image['data']))<786432,'image reference is bounded and metadata-free')
        # Compare-and-swap writes, real citation validation, idempotency, human block preservation.
        doc=a.api('GET','/work/documents/'+pid)
        minutes={'summary':'회의 요약','decisions':[dict(text='진행한다',refs=[1,seq])],'actions':[dict(text='확인하기',refs=[seq],assignee='담당자',due='금요일')]}
        body=dict(whiteboard_version=1,doc_version=doc['doc_version'],request_id=str(uuid.uuid4()),minutes=minutes,evidence={'text':'위조한 근거'})
        invalid=copy.deepcopy(body);invalid['minutes']['decisions'][0]['refs']=[seq+1]
        a.api('POST','/work/whiteboards/'+pid+'/minutes',invalid,expected=400)
        first=a.api('POST','/work/whiteboards/'+pid+'/minutes',body)
        a.check(first['saved'],'meeting minutes saved')
        a.check(int(a.sql("SELECT count(*) FROM audit_logs WHERE action='mcp.tool' AND detail->>'tool'='qaredi_save_meeting_minutes'"))>0,'local MCP calls retain tool audit without content')
        a.check(not a.api('POST','/work/whiteboards/'+pid+'/minutes',body)['saved'],'minutes retry is idempotent')
        now=a.api('GET','/work/documents/'+pid)
        a.check(now['doc']['blocks'][0]==doc['doc']['blocks'][0],'human-authored block preserved exactly')
        a.check(any(b['type']=='image' for b in now['doc']['blocks']),'server adds evidence image')
        a.check('위조한 근거' not in json.dumps(now['doc'],ensure_ascii=False),'caller cannot replace server-generated evidence')
        stale=dict(body,request_id=str(uuid.uuid4()))
        a.api('POST','/work/whiteboards/'+pid+'/minutes',stale,expected=409)
        second=dict(body,request_id=str(uuid.uuid4()),doc_version=now['doc_version'])
        a.api('POST','/work/whiteboards/'+pid+'/minutes',second)
        twice=a.api('GET','/work/documents/'+pid)
        a.check(len(twice['doc']['blocks'])==len(now['doc']['blocks']),'resaving replaces managed sections only')
        new=a.api('POST',path+'/versions',{'whiteboard_id':bid})
        a.check(new['board']['version_no']==2 and new['board']['state']=='open' and len(new['elements'])==2,'new version copies scene without changing locked evidence')
        a.check(a.api('GET','/work/whiteboards/'+pid)['board']['version_no']==1,'MCP defaults to last confirmed board')
        push(pid,bid,[e],expected=409)
        a.check(a.api('GET',path+'?version_no=1')['board']['snapshot']==locked['board']['snapshot'],'old evidence remains unchanged')
        a.check(a.sql("SELECT has_table_privilege('tbox_app','community.whiteboard_events','UPDATE')::int||':'||has_table_privilege('tbox_app','community.whiteboard_events','DELETE')::int")=='0:0','application cannot rewrite or delete drawing history')
        a.check(a.sql("SELECT has_table_privilege('tbox_backup','community.whiteboards','SELECT')::int")=='1','backup role can read new tables')
        token=secrets.token_hex(24)
        a.sql("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES(:'uid','whiteboard fixture',:'digest','fixtur',:'uid','community.read',now()+interval '1 hour')",uid=a.users['employee']['id'],digest=hashlib.sha256(token.encode()).hexdigest())
        a.request('GET','/community/api/work/whiteboards/'+pid,headers={'X-API-Token':token})
        a.request('POST','/community/api/work/whiteboards/'+pid+'/minutes',headers={'X-API-Token':token},json=second,expected=403)
        a.request('GET','/community/api'+path,headers={'X-API-Token':token},expected=401)
        a.sql("DELETE FROM api_tokens WHERE token_hash=:'digest'",digest=hashlib.sha256(token.encode()).hexdigest())
        a.request('GET','/community/api/work/whiteboards/'+pid,headers={'X-API-Token':token},expected=401)
    if os.environ.get('WHITEBOARD_TEST_BROWSER_ONLY') != '1':
        changed_only=a.api('GET',path+'/changes?after=0')
        a.check(changed_only['whiteboard_id']==new['board']['id'] and not changed_only['has_more'],'incremental changes identify the current board')
        a.check(a.api('GET',path+'/changes?after='+str(new['board']['last_seq']))['elements']==[],'incremental changes do not repeat unchanged elements')
        chain=subprocess.run(['php','scripts/whiteboard_chain.php','--verify','--strict'],cwd=instance,capture_output=True,text=True)
        (out/'chain-clean.log').write_text(chain.stdout+chain.stderr)
        a.check(chain.returncode==0,'real CLI recomputes event and snapshot hashes from DB')
    # Real editor, no mocks: two browser sessions; intercepted external requests fail the test.
    # Route actual browser HTTP and WebSockets through one owned loopback origin.
    for port in (18093,18868):
        with socket.socket() as check_socket:
            assert check_socket.connect_ex(('127.0.0.1',port)) != 0, 'Owned test port must be free'
    fixture_config=instance/'.env'
    config={line.split('=',1)[0]:line.split('=',1)[1] for line in fixture_config.read_text().splitlines() if '=' in line and not line.startswith('#')}
    config['APP_URL']='http://127.0.0.1:18868'
    fixture_config.write_text(''.join(k+'='+v+'\n' for k,v in config.items()))
    relay_env=dict(os.environ,WHITEBOARD_RELAY_SECRET=config['WHITEBOARD_RELAY_SECRET'],WHITEBOARD_ORIGIN=config['APP_URL'],WHITEBOARD_PORT='18093')
    owned_processes.append(subprocess.Popen(['node','relay/whiteboard-runtime.mjs'],cwd=instance,env=relay_env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
    load_seconds=int(os.environ.get('WHITEBOARD_TEST_LOAD_SECONDS','0'))
    if load_seconds:
        # An owned FPM pool, not PHP's development-server worker emulation.
        # No installed service/configuration or production listener is changed.
        pool=instance/'whiteboard-test-fpm.conf';nginx=instance/'whiteboard-test-nginx.conf'
        sock=instance/'whiteboard-test-fpm.sock';login=getpass.getuser()
        pool.write_text(f'''[global]
pid={instance}/whiteboard-fpm.pid
error_log={instance}/whiteboard-fpm.log
daemonize=no
[whiteboard_test]
user={login}
group={login}
listen={sock}
pm=static
pm.max_children=8
pm.status_path=/fpm-status
catch_workers_output=yes
php_admin_value[upload_max_filesize]=100M
php_admin_value[post_max_size]=105M
''')
        nginx.write_text(f'''worker_processes 1;
pid {instance}/whiteboard-nginx.pid;
error_log {instance}/whiteboard-nginx.log;
events {{ worker_connections 256; }}
http {{
include /etc/nginx/mime.types;
default_type application/octet-stream;
access_log off;
client_body_temp_path {instance}/nginx-body;
fastcgi_temp_path {instance}/nginx-fastcgi;
proxy_temp_path {instance}/nginx-proxy;
server {{
listen 127.0.0.1:18868;
root {instance}/public;
client_max_body_size 12m;
location /tbox/assets/ {{ alias {instance}/public/assets/; }}
location = /community/whiteboard-relay {{
proxy_pass http://127.0.0.1:18093;
proxy_http_version 1.1;
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_set_header Origin $http_origin;
proxy_read_timeout 75s;
}}
location / {{
include /etc/nginx/fastcgi_params;
fastcgi_param SCRIPT_FILENAME {instance}/public/index.php;
fastcgi_pass unix:{sock};
}}
}}
}}
''')
        owned_processes.append(subprocess.Popen(['/usr/sbin/php-fpm8.1','-y',str(pool),'-F'],cwd=instance,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
        for attempt in range(50):
            if sock.exists():break
            time.sleep(0.1)
        assert sock.exists(),'Owned eight-worker FPM pool started'
        owned_processes.append(subprocess.Popen(['nginx','-p',str(instance)+'/', '-c',str(nginx),'-g','daemon off;'],cwd=instance,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
    else:
        owned_processes.append(subprocess.Popen(['node','tests/community/whiteboard_proxy.mjs'],cwd=instance,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
    time.sleep(0.5)
    browser_site=config['APP_URL']
    from playwright.sync_api import sync_playwright
    errors=[]; external=[]; violations=[]; fonts=[]
    with sync_playwright() as pw:
        browser=pw.chromium.launch(headless=True,args=['--no-sandbox'])
        contexts=[]
        for user in ('employee','manager'):
            ctx=browser.new_context(viewport={'width':1280,'height':800})
            ctx.add_cookies([{'name':'__Host-tbox_session','value':a.users[user]['session'],'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True}])
            contexts.append(ctx)
        frames=[]
        tab=contexts[0].new_page()
        tab.on('websocket',lambda ws:ws.on('framereceived',lambda value:frames.append(json.loads(value))))
        tab.on('pageerror',lambda err: errors.append(str(err)))
        tab.on('response',lambda response:fonts.append(response.status) if '.woff' in response.url else None)
        tab.on('request',lambda req: external.append(req.url) if req.url.startswith(('http:','https:')) and not req.url.startswith(browser_site) else None)
        tab.add_init_script("window.__csp=[]; document.addEventListener('securitypolicyviolation',e=>window.__csp.push(e.violatedDirective+':'+e.blockedURI));")
        tab.goto(browser_site+'/community/p/'+pid+'/whiteboard')
        tab.wait_for_selector('.excalidraw canvas',timeout=45000)
        a.check(tab.locator('.excalidraw canvas').count()>0,'real Excalidraw renders under strict CSP')
        a.check(tab.locator('#whiteboard-records').is_hidden(),'history is closed by default')
        canvas_width=tab.locator('#whiteboard-canvas').bounding_box()['width']
        tab.click('#whiteboard-history');tab.wait_for_selector('#whiteboard-event-list li')
        a.check(tab.locator('#whiteboard-canvas').bounding_box()['width']==canvas_width,'history overlays without shrinking the canvas')
        tab.locator('#whiteboard-replay').fill('0');tab.locator('#whiteboard-replay').dispatch_event('input')
        a.check('보기 전용' in tab.locator('#whiteboard-replay-label').inner_text(),'history slider enters read-only replay')
        tab.click('#whiteboard-live')
        tab.click('#whiteboard-history')
        peer=contexts[1].new_page()
        peer_frames=[]
        peer.on('websocket',lambda ws:ws.on('framereceived',lambda value:peer_frames.append(json.loads(value))))
        peer.goto(browser_site+'/community/p/'+pid+'/whiteboard')
        peer.wait_for_selector('.excalidraw canvas',timeout=45000)
        for _ in range(80):
            if any(f.get('type')=='ready' for f in frames) and any(f.get('type')=='ready' for f in peer_frames): break
            tab.wait_for_timeout(100)
        a.check(any(f.get('type')=='ready' for f in frames) and any(f.get('type')=='ready' for f in peer_frames),'real PHP tickets authenticate both browser WebSockets')
        blocked=tab.evaluate("""() => {
            const data=new DataTransfer();data.items.add(new File(['synthetic image'], 'capture.png', {type:'image/png'}));
            return !window.dispatchEvent(new ClipboardEvent('paste',{clipboardData:data,bubbles:true,cancelable:true}));
        }""")
        a.check(blocked and '아직 지원하지' in tab.locator('#whiteboard-status').inner_text(),'image clipboard files are blocked before the editor consumes them')
        if load_seconds:
            assert load_seconds in (60,600), 'Release uses 600 seconds; 60 seconds is a diagnostic smoke check'
            # Six isolated browser sessions use the existing dedicated review identities.
            instrument="""window.__wbLatencies=[];window.__wbElementAges=[];performance.setResourceTimingBufferSize(10000);
            const OriginalWebSocket=window.WebSocket;
            window.WebSocket=class extends OriginalWebSocket {
              constructor(...args) {super(...args);this.addEventListener('message',event=>{
                try {const m=JSON.parse(event.data);if(m.type==='preview' && m.elements?.length) {
                  const updated=Math.max(...m.elements.map(e=>Number(e.updated)||0));
                  const age=Date.now()-updated;if(age>=0 && age<30000) window.__wbElementAges.push(age);
                  if(Number.isSafeInteger(m.sent_at)) requestAnimationFrame(()=>requestAnimationFrame(()=>{
                    const elapsed=Date.now()-m.sent_at;
                    if(elapsed>=0 && elapsed<30000) window.__wbLatencies.push(elapsed);
                  }));
                }} catch {}
              });}
            };"""
            pages=[tab,peer]
            for existing in pages:
                existing.context.add_init_script(instrument)
                existing.reload();existing.wait_for_selector('.excalidraw canvas',timeout=45000)
            for number in range(4):
                who='employee' if number%2==0 else 'manager'
                context=browser.new_context(viewport={'width':1280,'height':800})
                context.add_cookies([{'name':'__Host-tbox_session','value':a.users[who]['session'],'domain':'127.0.0.1','path':'/','secure':True,'httpOnly':True}])
                context.add_init_script(instrument);contexts.append(context)
                client=context.new_page();client.goto(browser_site+'/community/p/'+pid+'/whiteboard')
                client.wait_for_selector('.excalidraw canvas',timeout=45000);pages.append(client)
            for client in pages:client.evaluate('performance.clearResourceTimings()')
            started=time.monotonic();ticks=0;peak_relay_rss=0;pool_status={}
            while time.monotonic()-started<load_seconds:
                for number,client in enumerate(pages):
                    x=350+(ticks%12)*45+number*2;y=260+((ticks//12)%10)*32+number*2
                    client.keyboard.press('v');client.mouse.click(400,230);client.keyboard.press('r')
                    client.mouse.move(x,y);client.mouse.down();client.mouse.move(x+25,y+16,steps=2);client.mouse.up()
                ticks+=1
                with urllib.request.urlopen(browser_site+'/fpm-status?json',timeout=3) as response:
                    pool_status=json.load(response)
                try:
                    rss=int(Path('/proc/'+str(owned_processes[0].pid)+'/statm').read_text().split()[1])*os.sysconf('SC_PAGE_SIZE')
                    peak_relay_rss=max(peak_relay_rss,rss)
                except OSError:pass
                pause=min(5,max(0,started+ticks*5-time.monotonic()))
                if pause:pages[0].wait_for_timeout(pause*1000)
                if ticks%12==0:
                    interim=[]
                    for client in pages:interim.extend(client.evaluate('() => window.__wbLatencies'))
                    progress={'elapsed_seconds':round(time.monotonic()-started),'sessions':6,'strokes':ticks*6,'preview_samples':len(interim),
                        'preview_p95_ms':sorted(interim)[min(len(interim)-1,int(len(interim)*0.95))] if interim else None}
                    (out/'load-progress.json').write_text(json.dumps(progress))
                    print('LOAD '+json.dumps(progress),flush=True)
            drain_deadline=time.monotonic()+30
            while time.monotonic()<drain_deadline:
                if all('저장됨' in client.locator('#whiteboard-status').inner_text() for client in pages): break
                pages[0].wait_for_timeout(200)
            ages=[];durations=[];element_ages=[]
            for client in pages:
                ages.extend(client.evaluate('window.__wbLatencies'))
                element_ages.extend(client.evaluate('window.__wbElementAges'))
                durations.extend(client.evaluate("performance.getEntriesByType('resource').filter(e=>e.name.includes('/api/pages/') && e.name.includes('/whiteboard')).map(e=>e.duration)"))
            percentile=lambda values:sorted(values)[min(len(values)-1,int(len(values)*0.95))] if values else None
            load_result={'seconds':round(time.monotonic()-started,2),'release_load':load_seconds==600,'browser_contexts':6,'review_identities':2,'strokes':ticks*6,
                'preview_samples':len(ages),'preview_p95_ms':percentile(ages),'http_samples':len(durations),'http_p95_ms':percentile(durations),
                'element_age_p95_ms':percentile(element_ages),'preview_measure':'sender timestamp to receiver two animation frames; same host clock',
                'relay_peak_rss_bytes':peak_relay_rss,'fixture_php_workers':8,'php_sapi':'fpm-fcgi','php_opcache':True,
                'php_max_active_workers':pool_status.get('max active processes'),'php_max_listen_queue':pool_status.get('max listen queue'),
                'php_max_children_reached':pool_status.get('max children reached')}
            (out/'load-results.json').write_text(json.dumps(load_result,indent=2))
            print('LOAD_RESULT '+json.dumps(load_result),flush=True)
            a.check(len(ages)>100 and percentile(ages)<=300,'six-session ten-minute preview p95 meets 300ms target')
            a.check(len(durations)>100 and percentile(durations)<1000,'six-session HTTP p95 stays below one second')
            a.check(pool_status.get('total processes')==8 and pool_status.get('max listen queue')==0,'eight real FPM workers retain request headroom')
            # Extra sessions are complete; keep two sessions for the final confirmation checks.
            for client in pages[2:]:client.close()
            tab.wait_for_timeout(1200)
        initial=a.api('GET',path)['board']['last_seq']
        tab.mouse.click(400,300)
        tab.keyboard.press('r')
        tab.mouse.move(420,350);tab.mouse.down();tab.mouse.move(610,470,steps=8);tab.mouse.up()
        for _ in range(80):
            latest=a.api('GET',path)
            if latest['board']['last_seq']>initial: break
            tab.wait_for_timeout(100)
        a.check(latest['board']['last_seq']>initial,'drawing with real pointer events saves an element: '+tab.locator('#whiteboard-status').inner_text())
        tab.wait_for_timeout(800)
        fresh=a.api('GET',path+'/events?after_seq='+str(initial))['events']
        a.check(fresh and all(e['actor_id']==a.users['employee']['id'] for e in fresh),'peer previews never get re-saved as the recipient')
        a.check(any(f.get('type')=='pointer' for f in peer_frames) and any(f.get('type')=='committed' for f in peer_frames),'collaborator receives live pointer and committed-record notification')
        peer.route('**/whiteboard/elements',lambda route:route.abort())
        peer.mouse.click(650,330);peer.keyboard.press('r')
        peer.mouse.move(680,350);peer.mouse.down();peer.mouse.move(760,410,steps=2);peer.mouse.up()
        peer.wait_for_selector('#whiteboard-recover',state='visible',timeout=15000)
        with peer.expect_download() as pending_download:peer.click('#whiteboard-recover')
        recovery=pending_download.value;recovery.save_as(str(out/'unsaved.excalidraw'))
        recovered=json.loads((out/'unsaved.excalidraw').read_text())
        a.check(recovered['type']=='excalidraw' and len(recovered['elements'])>=2,'unsaved drawings can be recovered locally after a network failure')
        peer.close()
        owned_processes[0].terminate();owned_processes[0].wait(timeout=5)
        tab.wait_for_timeout(300)
        fallback=a.api('GET',path)
        arrived=push(pid,fallback['board']['id'],[shape('fallback_shape')],fallback['board']['last_seq'])['last_seq']
        fallback_started=time.monotonic()
        while time.monotonic()-fallback_started<3:
            if str(arrived)+'번째 기록' in tab.locator('#whiteboard-status').inner_text(): break
            tab.wait_for_timeout(100)
        fallback_ms=round((time.monotonic()-fallback_started)*1000)
        a.check(str(arrived)+'번째 기록' in tab.locator('#whiteboard-status').inner_text() and fallback_ms<2200,
                'relay outage switches to one-second polling: '+str(fallback_ms)+'ms')
        tab.click('#whiteboard-confirm')
        tab.locator('#whiteboard-confirm-dialog button[value="confirm"]').click()
        tab.wait_for_selector('#whiteboard-new',state='visible',timeout=45000)
        a.check(a.api('GET',path)['board']['state']=='locked','real browser PNG export and multipart confirmation lock the board')
        tab.click('#whiteboard-history')
        a.check('원본 장면:' in tab.locator('#whiteboard-fingerprints').inner_text(),'locked scene, PNG and chain fingerprints are visible in history')
        tab.click('#whiteboard-history')
        for theme in ('light','dark'):
            contexts[0].add_cookies([{'name':'tbox_theme','value':theme,'domain':'127.0.0.1','path':'/'}])
            tab.reload();tab.wait_for_selector('.excalidraw canvas',timeout=45000)
            for width in (1280,820,412,360):
                tab.set_viewport_size({'width':width,'height':800});tab.wait_for_timeout(200)
                a.check(tab.evaluate('document.documentElement.scrollWidth<=window.innerWidth'),'canvas fits '+theme+' '+str(width))
        violations=tab.evaluate('window.__csp')
        tab.screenshot(path=str(out/'whiteboard-mobile.png'))
        a.check(not errors,'browser has no uncaught JavaScript errors')
        a.check(not external,'editor sends no requests to outside origins')
        a.check(fonts and all(code==200 for code in fonts),'self-hosted drawing fonts load successfully')
        a.check(not violations,'strict CSP has no violations')
        browser.close()
    if os.environ.get('WHITEBOARD_TEST_BROWSER_ONLY') != '1':
        a.sql("UPDATE community.whiteboard_events SET data=jsonb_set(data,'{x}','777'::jsonb) WHERE whiteboard_id=(SELECT id FROM community.whiteboards WHERE public_id=CAST(:'bid' AS uuid)) AND seq=1",bid=bid)
        tampered=subprocess.run(['php','scripts/whiteboard_chain.php','--verify','--strict'],cwd=instance,capture_output=True,text=True)
        (out/'chain-tampered.log').write_text(tampered.stdout+tampered.stderr)
        a.check(tampered.returncode==2,'owner-side record tampering is detected without repairing history')
finally:
    for child in reversed(owned_processes):
        child.terminate()
        try: child.wait(timeout=5)
        except subprocess.TimeoutExpired: child.kill(); child.wait()
    for name in ('whiteboard-fpm.log','whiteboard-nginx.log'):
        source=instance/name
        if source.exists():
            value=source.read_text()
            for key in ('DB_PASS','APP_SECRET','API_TOKEN','WHITEBOARD_RELAY_SECRET'):
                secret=locals().get('config',{}).get(key)
                if secret:value=value.replace(secret,'[PRIVATE]')
            (out/name).write_text(value)
    (out/'whiteboard-results.json').write_text(json.dumps({'checks':a.checks,'external_requests':locals().get('external',[]),'csp':locals().get('violations',[]),'browser_errors':locals().get('errors',[]),'font_statuses':locals().get('fonts',[])},ensure_ascii=False,indent=2))
    print('RESULT pass='+str(sum(c['pass'] for c in a.checks))+' fail='+str(sum(not c['pass'] for c in a.checks)),flush=True)
