#!/usr/bin/env python3
"""Owned nginx process: unchanged deployment public rules, synthetic HTTP upstream only."""
from pathlib import Path
exec(compile(Path(__file__).with_name('stage12_http.py').read_text().split('try:\n for u in users:')[0],__file__,'exec'))
import tempfile,shutil,signal,socket
work=Path(tempfile.mkdtemp(prefix='stage12-nginx-'));server=None
try:
 port=8679
 with socket.socket() as sock:assert sock.connect_ex(('127.0.0.1',port))!=0
 conf=(root/'deploy/sites/tbox-v2-local.conf').read_text()
 conf=conf.replace('127.0.0.1:8082','127.0.0.1:'+str(port)).replace('/var/www/qaredi/tbox-v2/public',str(root/'public'))
 conf=conf.replace('/var/log/nginx/tbox-v2-local.access.log',str(work/'access.log')).replace('/var/log/nginx/tbox-v2-local.error.log',str(work/'error.log'))
 conf=re.sub(r'include\s+fastcgi_params;', 'include /etc/nginx/fastcgi_params;',conf)
 conf=re.sub(r'fastcgi_pass\s+unix:[^;]+;', 'proxy_pass http://127.0.0.1:8678;',conf)
 # Transport alone is replaced by the owned test HTTP server. Limits, headers, log maps and routing are original.
 full='pid '+str(work/'nginx.pid')+';\nerror_log '+str(work/'master.log')+' crit;\nevents {}\nhttp {\nclient_body_temp_path '+str(work/'body')+';\nproxy_temp_path '+str(work/'proxy')+';\n'+(root/'deploy/sites/tbox-common.conf').read_text()+'\n'+conf+'\n}\n'
 (work/'nginx.conf').write_text(full)
 test=subprocess.run(['nginx','-t','-p',str(work),' -c'.strip(),str(work/'nginx.conf')],capture_output=True)
 check(test.returncode==0,'deployment nginx public rules syntax check')
 server=subprocess.Popen(['nginx','-p',str(work),'-c',str(work/'nginx.conf'),'-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.PIPE,start_new_session=True)
 for _ in range(50):
  with socket.socket() as sock:
   if sock.connect_ex(('127.0.0.1',port))==0:break
  time.sleep(.05)
 target='http://127.0.0.1:'+str(port);token='n'*43;path='/community/pub/'+token
 statuses=[requests.post(target+path+'/form/v12345678/submit',json={},timeout=10).status_code for _ in range(6)]
 check(statuses[:5]==[404]*5 and statuses[5]==429,'nginx submit sixth request returns 429')
 status=requests.get(target+path,timeout=10);check(status.status_code==404 and 'no-store' in status.headers.get('Cache-Control',''),'nginx public 404 no-store')
 r=requests.post(target+path,data=b'x'*65537,timeout=10);check(r.status_code==413 and r.headers.get('Cache-Control')=='no-store','nginx public body limit 64KB with no-store')
 statuses=[requests.get(target+path,timeout=10).status_code for _ in range(61)]
 check(429 in statuses,'nginx public GET limit is active')
 raw=socket.create_connection(('127.0.0.1',port));raw.sendall(('GET /community/%70ub/'+token+' HTTP/1.1\r\nHost: localhost\r\nUser-Agent: '+token+'\r\nConnection: close\r\n\r\n').encode());raw.recv(4096);raw.close()
 # Malformed memory URLs must not disclose even a valid-looking capability in logs.
 for path in ['/community/memories/s/'+token+'/invalid', '/community/%6demories/s/'+token+'/invalid']:
  r=requests.get(target+path,headers={'User-Agent':token},timeout=10)
  check(r.status_code==404,'malformed memory capability route rejected')
 server.terminate();server.wait(timeout=5);server=None
 access=(work/'access.log').read_text();error=(work/'error.log').read_text() if (work/'error.log').exists() else ''
 check(token not in access+error,'nginx logs redact raw token including encoded pub path and user-agent')
 check('/community/pub/[REDACTED]' in access,'nginx access keeps sanitized public request pattern')
 check('/community/memories/s/[REDACTED]' in access,'nginx memory capability redacted on malformed route')
 (output/'nginx-access.log').write_text(access);(output/'nginx-error.log').write_text(error)
 (output/'nginx-response.txt').write_text('HTTP '+str(status.status_code)+'\n'+'\n'.join(k+': '+v for k,v in status.headers.items())+'\n\n'+status.text)
 print('NGINX_ACCEPTANCE_COUNT='+str(len(checks)))
finally:
 if server is not None:
  os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
 shutil.rmtree(work)
 (output/'nginx.json').write_text(json.dumps(checks,ensure_ascii=False,indent=2))
