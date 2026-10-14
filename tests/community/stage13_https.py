#!/usr/bin/env python3
"""Original Ai.php over real TLS in a network namespace with no external route."""
import argparse,http.server,json,os,shutil,socket,ssl,struct,subprocess,tempfile,threading
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
HOST='generativelanguage.googleapis.com'

def inside(work):
    subprocess.run(['mount','--make-rprivate','/'],check=True)
    subprocess.run(['mount','--bind',str(work/'resolv.conf'),'/etc/resolv.conf'],check=True)
    subprocess.run(['ip','link','set','lo','up'],check=True)
    subprocess.run(['ip','addr','add','93.184.216.34/32','dev','lo'],check=True)
    assert not subprocess.check_output(['ip','route','show','default']).strip()
    stop=threading.Event();captured=[]
    def dns():
        sock=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);sock.bind(('127.0.0.1',53));sock.settimeout(.2)
        while not stop.is_set():
            try:data,peer=sock.recvfrom(4096)
            except socket.timeout:continue
            index=12
            while data[index]:index+=data[index]+1
            index+=1;kind=struct.unpack('!H',data[index:index+2])[0];end=index+4
            mode=(work/'dns-mode').read_text().strip()
            has_answer=kind==1 or (kind==28 and mode=='private6')
            response=data[:2]+b'\x81\x80'+struct.pack('!HHHH',1,int(has_answer),0,0)+data[12:end]
            if kind==1:
                ip='127.0.0.1' if mode=='private4' else '93.184.216.34'
                response+=b'\xc0\x0c'+struct.pack('!HHIH',1,1,0,4)+socket.inet_aton(ip)
            elif has_answer:response+=b'\xc0\x0c'+struct.pack('!HHIH',28,1,0,16)+socket.inet_pton(socket.AF_INET6,'::1')
            sock.sendto(response,peer)
        sock.close()
    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self,*args):pass
        def respond(self,status,body):
            self.send_response(status)
            if status==302:self.send_header('Location','https://'+HOST+'/should-not-follow')
            raw=body if isinstance(body,bytes) else json.dumps(body,ensure_ascii=False).encode()
            self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers()
            try:self.wfile.write(raw)
            except (BrokenPipeError,ConnectionResetError,ssl.SSLError):pass
        def do_GET(self):
            captured.append({'method':'GET','path':self.path,'key_present':bool(self.headers.get('x-goog-api-key'))})
            self.respond(200,{'name':'models/gemini-3.6-flash'})
        def do_POST(self):
            body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))))
            assert self.headers.get('x-goog-api-key')=='A'*39
            assert not any(key in body for key in ('tools','toolConfig','cachedContent'))
            captured.append({'method':'POST','path':self.path,'body':body,'key_present':True})
            if self.path.endswith(':embedContent'):
                self.respond(200,{'embedding':{'values':[.25]*768}});return
            text=body['contents'][0]['parts'][0]['text']
            if text=='fixture-redirect':self.respond(302,{});return
            if text=='fixture-quota':self.respond(429,{});return
            if text=='fixture-large':self.respond(200,b'x'*(2097153));return
            self.respond(200,{'candidates':[{'finishReason':'STOP','content':{'parts':[{'text':'검증된 합성 응답'}]}}]})
    threading.Thread(target=dns,daemon=True).start()
    server=http.server.ThreadingHTTPServer(('93.184.216.34',443),Handler)
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(work/'cert.pem',work/'key.pem')
    server.socket=context.wrap_socket(server.socket,server_side=True)
    threading.Thread(target=server.serve_forever,daemon=True).start()
    result=subprocess.run(['php','-d','curl.cainfo='+str(work/'cert.pem'),str(work/'fixture.php')],capture_output=True)
    before=len(captured)
    untrusted=subprocess.run(['php',str(work/'untrusted.php')],capture_output=True)
    assert len(captured)==before,'untrusted certificate must fail before HTTP'
    server.shutdown();stop.set()
    generate_path='/v1beta/models/gemini-3.6-flash:generateContent'
    assert [(item['method'],item['path']) for item in captured[:4]]==[
        ('GET','/v1beta/models/gemini-3.6-flash'),
        ('POST',generate_path),
        ('POST','/v1beta/models/gemini-embedding-2:embedContent'),
        ('POST',generate_path),
    ],'exact health, generate, embed and transcribe model URLs'
    assert all(item['method']=='POST' and item['path']==generate_path for item in captured[4:]),'all generation calls use Gemini 3.6 Flash'
    assert all('candidateCount' not in item['body']['generationConfig'] for item in captured if item['method']=='POST' and item['path']==generate_path),'Gemini 3 generation requests omit candidateCount'
    assert not any(item['path']=='/should-not-follow' for item in captured)
    assert captured and all(item['key_present'] for item in captured)
    (work/'received.json').write_text(json.dumps(captured,ensure_ascii=False,indent=2))
    output=result.stdout+result.stderr+untrusted.stdout+untrusted.stderr
    (work/'result.txt').write_bytes(output);print(output.decode(),end='')
    assert result.returncode==0 and untrusted.returncode==0
    print('NETWORK_NAMESPACE_NO_DEFAULT_ROUTE=1; REAL_TLS_ORIGINAL_AI_TRANSPORT=1; LIVE_DESTINATIONS_CONTACTED=0')

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--inside',type=Path);parser.add_argument('--output',type=Path);args=parser.parse_args()
    if args.inside:inside(args.inside);return
    work=Path(tempfile.mkdtemp(prefix='qaredi-stage13-https-'));work.chmod(0o700)
    try:
        (work/'storage').mkdir();(work/'dns-mode').write_text('public')
        (work/'resolv.conf').write_text('nameserver 127.0.0.1\noptions timeout:1 attempts:1\n')
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(work/'key.pem'),'-out',str(work/'cert.pem'),'-days','1','-subj','/CN='+HOST,'-addext','subjectAltName=DNS:'+HOST],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        preamble="<?php\ndeclare(strict_types=1);\nclass Env { static function get(string $key): string { return "+repr(str(work/'storage'))+"; }}\nrequire "+repr(str(ROOT/'src/Fetch.php'))+";\nrequire "+repr(str(ROOT/'src/Ai.php'))+";\n"
        php=r'''
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function deny(callable $fn,string $reason):void {try{$fn();throw new RuntimeException('expected denial');}catch(AiUnavailable $e){check($e->reason===$reason,'denied '.$reason);}}
Ai::setApiKey(str_repeat('A',39));
check(Ai::health()['available'],'actual TLS metadata GET');
check(Ai::generate('공개 합성 입력','summary')['text']==='검증된 합성 응답','actual TLS Korean summary');
check(count(Ai::embed(['공개 검색'],null,'query')['vectors'][0])===768,'actual TLS embedding');
check(Ai::transcribe('RIFF'.str_repeat('A',100),'ko','audio/wav')['bytes']===104,'actual TLS inline audio');
deny(fn()=>Ai::generate('fixture-redirect','summary'),'unavailable');
deny(fn()=>Ai::generate('fixture-quota','summary'),'provider_limit');
deny(fn()=>Ai::generate('fixture-large','summary'),'unavailable');
file_put_contents(DNS_MODE,'private4');deny(fn()=>Ai::generate('must not send','summary'),'network');
file_put_contents(DNS_MODE,'private6');deny(fn()=>Ai::generate('must not send','summary'),'network');
file_put_contents(DNS_MODE,'public');
'''
        (work/'fixture.php').write_text(preamble+'define("DNS_MODE",'+repr(str(work/'dns-mode'))+');\n'+php)
        (work/'untrusted.php').write_text(preamble+'try { Ai::generate("must not send", "summary"); exit(1); } catch (AiUnavailable $e) { echo "PASS untrusted TLS certificate rejected before HTTP\\n"; }')
        result=subprocess.run(['sudo','-n','unshare','--net','--mount','python3',str(Path(__file__).resolve()),'--inside',str(work)])
        if args.output:
            args.output.mkdir(parents=True,exist_ok=True)
            for name in ('received.json','result.txt'):
                if (work/name).exists():shutil.copyfile(work/name,args.output/name)
        raise SystemExit(result.returncode)
    finally:
        assert work.resolve().parent==Path(tempfile.gettempdir()).resolve() and work.name.startswith('qaredi-stage13-https-')
        subprocess.run(['sudo','-n','chown','-R',str(os.getuid())+':'+str(os.getgid()),str(work)],check=True)
        shutil.rmtree(work)
if __name__=='__main__':main()
