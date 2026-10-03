<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/src/Storage.php';
require_once dirname(__DIR__,2) . '/src/Authz.php';
require_once dirname(__DIR__,2) . '/src/assistant/Error.php';
require_once dirname(__DIR__,2) . '/src/assistant/Request.php';
use QarediAssistant\{Request,Error};
$count=0;
function check(bool $yes,string $name): void { global $count; if (!$yes) throw new RuntimeException($name); $count++; echo "PASS $name\n"; }
function reject(array $b,string $name): void { try { Request::normalize($b); } catch (Error $e) { check(in_array($e->http,[400,403],true),$name); return; } throw new RuntimeException($name); }
$search=['mode'=>'tool','tool'=>['name'=>'files.search','arguments'=>['query'=>'재료비']]];
$s=Request::normalize($search); check($s['tool']['arguments']['limit']===20,'search default limit');
check($s['message']==='' && $s['require_confirmation']===false,'tool defaults');
check(Request::json($s)===Request::json(Request::normalize($search+['message'=>'','allowed_tools'=>['files.evidence','files.search']])),'canonical optional defaults');
check(strlen(Request::key('buddy:valid-key'))===64,'idempotency stored hash');
foreach (['','a b',str_repeat('a',129),'한글','key\n'] as $k) { try { Request::key($k); throw new RuntimeException('accepted bad key'); } catch (Error $e) { check($e->http===400,'bad key rejected'); } }
foreach ([0,101,'20',20.0,true] as $lim) { $b=$search; $b['tool']['arguments']['limit']=$lim; reject($b,'strict search limit'); }
foreach (['',str_repeat('x',201),"x\0y"] as $q) { $b=$search; $b['tool']['arguments']['query']=$q; reject($b,'query bounds'); }
foreach (['upload','files.delete','ledger.correct','shell.exec','http.fetch'] as $tool) { $b=$search; $b['tool']['name']=$tool; reject($b,'write/arbitrary tool denied'); }
foreach (['role','user_id','provider','test_adapter','plan','approved','url'] as $key) reject($search+[$key=>'fake'],'unknown authority field');
reject($search+['require_confirmation'=>'true'],'boolean confirmation');
reject($search+['attachments'=>[['file_id'=>'not-uuid']]],'bad attachment');
reject($search+['allowed_tools'=>['files.evidence']],'explicit tool must be allowed');
reject(['mode'=>'chat','message'=>''],'empty chat');
reject(['mode'=>'chat','message'=>'hello','tool'=>$search['tool']],'chat cannot smuggle tool');
foreach (['read','read+upload','read+upload+delete','community.write','session.open'] as $scope)
    check(!Authz::tokenScopeAllows($scope,'assistant.run'),'existing scope cannot run assistant');
foreach (['file.read','file.upload','file.update','file.delete','community.read','community.write','session.open','role:Admin','unknown'] as $policy)
    check(!Authz::tokenScopeAllows('assistant.run',$policy),'assistant cannot expand existing routes');
check(Authz::tokenScopeAllows('assistant.run','assistant.run'),'assistant scope accepted');
check(Authz::tokenScopeAllows('assistant.run','authenticated'),'assistant whoami');
check(Authz::tokenScopeAllows('read','file.read') && !Authz::tokenScopeAllows('read','file.upload'),'existing file scope unchanged');
check(Authz::tokenScopeAllows('session.open','session.open') && !Authz::tokenScopeAllows('session.open','file.read'),'existing login scope unchanged');
echo "ASSISTANT_UNIT_CHECKS=$count\n";
