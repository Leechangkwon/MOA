"""DB protection contract against the explicitly designated Unix-only synthetic cluster."""
import json,os,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[2]
checks=[]
def check(name,ok):
 checks.append(dict(name=name,passed=bool(ok)));print(('PASS ' if ok else 'FAIL ')+name,flush=True);assert ok,name
args=['php',str(root/'tests/scale/guard.php')]
env=dict(os.environ,SCALE_TEST_SOCKET='/home/changkwon/qaredi-scale-results-20261012/cluster')
def call(mode='normal',uid=7):
 return json.loads(subprocess.check_output(args+[mode,str(uid)],env=env,text=True).splitlines()[-1])
for mode,want in [('normal','ok'),('timeout','SearchTimeout'),('budget','SearchTimeout'),('error','PDOException')]:
 r=call(mode);check(mode+' status',r['status']==want)
 check(mode+' releases transaction and timeout',r['transaction'] is False and r['timeout_reset']=='0' and r['next_works'])
 if want=='SearchTimeout':check(mode+' three second budget',2800<=r['ms']<3800)
p=subprocess.Popen(args+['hold','7'],env=env,stdout=subprocess.PIPE,text=True)
try:
 assert p.stdout.readline().strip()=='READY'
 # The independent request sees busy; its cleanup probe must use a different UID to avoid throwing again.
 program="require 'src/Db.php';require 'src/Http.php';require 'src/SearchText.php';require 'src/SearchGuard.php';Db::boot(getenv('SCALE_TEST_SOCKET'),'55432','tbox_re','perf','');$t=microtime(true);try{SearchGuard::execute(7,fn($r)=>$r('SELECT 1'));echo 'unexpected';}catch(SearchBusy $e){echo json_encode(['ms'=>(microtime(true)-$t)*1000,'transaction'=>Db::instance()->inTransaction()]);}"
 r=json.loads(subprocess.check_output(['php','-r',program],cwd=root,env=env,text=True))
 check('same user immediate rejection',r['ms']<300 and r['transaction'] is False)
 check('different user proceeds',call('normal',8)['status']=='ok')
 check('first request completes',json.loads(p.communicate(timeout=4)[0].splitlines()[-1])['status']=='ok')
finally:
 if p.poll() is None:p.terminate();p.wait()
check('same user works after release',call()['status']=='ok')
program="require 'src/SearchText.php';require 'src/SearchGuard.php';echo json_encode(array_map([SearchGuard::class,'longEnough'],['','회',' 회 ','회 의','ab','a b',\"회\",'%%']));"
r=json.loads(subprocess.check_output(['php','-r',program],cwd=root,text=True));check('NFC and whitespace minimum',r==[False,False,False,True,True,True,False,True])
print('PASS_COUNT='+str(len(checks)))
