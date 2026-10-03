#!/usr/bin/env python3
"""Real HTTPS Fetch calls in a route-less network namespace. No live destination can be reached."""
import argparse,http.server,json,os,shutil,socket,ssl,struct,subprocess,tempfile,threading,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def inside(work):
 subprocess.run(['mount','--make-rprivate','/'],check=True)
 subprocess.run(['mount','--bind',str(work/'resolv.conf'),'/etc/resolv.conf'],check=True)
 subprocess.run(['ip','link','set','lo','up'],check=True)
 subprocess.run(['ip','addr','add','93.184.216.34/32','dev','lo'],check=True)
 assert not subprocess.check_output(['ip','route','show','default']).strip()
 stop=threading.Event();captured=[]
 def dns():
  s=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);s.bind(('127.0.0.1',53));s.settimeout(.2)
  while not stop.is_set():
   try:d,peer=s.recvfrom(4096)
   except socket.timeout:continue
   n=12;labels=[]
   while d[n]:labels.append(d[n+1:n+1+d[n]].decode());n+=d[n]+1
   n+=1;qtype=struct.unpack('!H',d[n:n+2])[0];end=n+4;name='.'.join(labels)
   ip='127.0.0.1' if name.startswith('private.') else '93.184.216.34';answer=qtype==1
   response=d[:2]+b'\x81\x80'+struct.pack('!HHHH',1,int(answer),0,0)+d[12:end]
   if answer:response+=b'\xc0\x0c'+struct.pack('!HHIH',1,1,0,4)+socket.inet_aton(ip)
   s.sendto(response,peer)
  s.close()
 class Handler(http.server.BaseHTTPRequestHandler):
  def log_message(self,*args):pass
  def do_POST(self):
   body=self.rfile.read(int(self.headers.get('Content-Length','0')));captured.append({'method':'POST','path':self.path,'body':body.decode(),'authorization':self.headers.get('Authorization')})
   self.send_response(204);self.end_headers()
  def do_GET(self):
   captured.append({'method':'GET','path':self.path,'authorization':self.headers.get('Authorization')})
   if self.path=='/redirect':self.send_response(302);self.send_header('Location','https://receiver.example.test/should-not-follow');self.end_headers();return
   body=b'x'*(5*1024*1024+1) if self.path=='/large' else b'[{"external_key":"one","value":"<script>literal</script>"}]'
   self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers()
   try:self.wfile.write(body)
   except (BrokenPipeError,ConnectionResetError,ssl.SSLError):pass
 threading.Thread(target=dns,daemon=True).start()
 server=http.server.ThreadingHTTPServer(('93.184.216.34',443),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(work/'cert.pem',work/'key.pem');server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start()
 result=subprocess.run(['php','-d','curl.cainfo='+str(work/'cert.pem'),str(work/'transport.php')],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 server.shutdown();stop.set()
 received=[r for r in captured if r['path']=='/ok'];assert received and json.loads(received[0]['body'])=={'text':'comment · (제목 숨김)\nhttps://qaredi.example/community/p/synthetic'}
 assert not any(r['path']=='/should-not-follow' for r in captured)
 assert any(r.get('authorization')=='Bearer fixture-only-key' for r in captured)
 for r in captured:
  if r.get('authorization'):r['authorization']='[synthetic fixture credential redacted]'
 (work/'received.json').write_text(json.dumps(captured,ensure_ascii=False,indent=2));(work/'result.txt').write_bytes(result.stdout+result.stderr)
 print(result.stdout.decode(),end='');assert result.returncode==0
 print('NETWORK_NAMESPACE_NO_DEFAULT_ROUTE=1; REAL_HTTPS_FAKE_RECEIVER=1; LIVE_DESTINATIONS_CONTACTED=0')
def main():
 parser=argparse.ArgumentParser();parser.add_argument('--inside',type=Path);parser.add_argument('--output',type=Path);args=parser.parse_args()
 if args.inside:inside(args.inside);return
 work=Path(tempfile.mkdtemp(prefix='qaredi-stage12-transport-'));work.chmod(0o700)
 try:
  (work/'resolv.conf').write_text('nameserver 127.0.0.1\noptions timeout:1 attempts:1\n')
  subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(work/'key.pem'),'-out',str(work/'cert.pem'),'-days','1','-subj','/CN=receiver.example.test','-addext','subjectAltName=DNS:receiver.example.test'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  php='''<?php
declare(strict_types=1);
class Env { static function get($key,$default='') { return $key==='FETCH_ALLOWED_HOSTS'?'files.example.test':$default; } static function getOrEmpty($key){return self::get($key);} }
class Db { static function instance(){return new self();} function run($sql,$params=[]){return new class($sql,$params){function __construct(public $sql,public $params){} function fetchColumn(){return in_array($this->params[':host']??'', ['receiver.example.test','private.example.test'],true);} function fetchAll(){return [];}};} }
require %s;
function denied($fn,$reason){try{$fn();throw new RuntimeException('expected rejection '.$reason);}catch(FetchDenied $e){if($e->reason!==$reason)throw $e;echo 'PASS '.$reason."\\n";}}
denied(fn()=>Fetch::post('https://outside.example.test/x',[]),'host_not_allowed');
denied(fn()=>Fetch::post('https://private.example.test/x',[]),'private_ip');
denied(fn()=>Fetch::post('http://receiver.example.test/x',[]),'scheme');
denied(fn()=>Fetch::post('https://receiver.example.test:444/x',[]),'port');
$r=Fetch::post('https://receiver.example.test/ok',['text'=>"comment · (제목 숨김)\\nhttps://qaredi.example/community/p/synthetic"]);if($r['status']!==204)throw new RuntimeException('post');echo "PASS POST real TLS fixture\\n";
$r=Fetch::getJson('https://receiver.example.test/json','fixture-only-key');if($r[0]['value']!=='<script>literal</script>')throw new RuntimeException('json');echo "PASS GET bounded literal JSON\\n";
denied(fn()=>Fetch::getJson('https://receiver.example.test/redirect'),'redirect');
denied(fn()=>Fetch::getJson('https://receiver.example.test/large'),'too_large');
denied(fn()=>Fetch::check('https://receiver.example.test/ok'),'host_not_allowed');
'''
  # Python repr gives a PHP single-quoted path; repository path contains no quote.
  (work/'transport.php').write_text(php % repr(str(ROOT/'src/Fetch.php')))
  r=subprocess.run(['sudo','-n','unshare','--net','--mount','python3',str(Path(__file__).resolve()),'--inside',str(work)])
  if args.output:
   args.output.mkdir(parents=True,exist_ok=True)
   for name in ['received.json','result.txt']:
    if (work/name).exists():shutil.copyfile(work/name,args.output/name)
  raise SystemExit(r.returncode)
 finally:shutil.rmtree(work)
if __name__=='__main__':main()
