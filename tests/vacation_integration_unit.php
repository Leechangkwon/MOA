<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/Authz.php';
$count=0;
function check(bool $ok,string $name): void {global $count; if (!$ok) throw new RuntimeException($name); $count++; echo "PASS $name\n";}
check(Authz::normalizeScope('assistant.run')==='assistant.run','standalone assistant scope accepted');
foreach (['assistant.run+read','read+assistant.run','assistant.run+session.open'] as $scope) {
 try { Authz::normalizeScope($scope); throw new RuntimeException('accepted mixed assistant'); } catch (InvalidArgumentException $e) {check(true,'mixed assistant issuance rejected');}
 foreach (['authenticated','assistant.run','file.read','session.open'] as $policy) check(!Authz::tokenScopeAllows($scope,$policy),'mixed assistant row fails closed');
}
foreach (['POST /api/files/{id}/grants','DELETE /api/files/{id}/grants/{num}','POST /api/folders/{num}/grants','DELETE /api/folders/{num}/grants/{num}','POST /api/access-requests/{num}'] as $route) {
 check(Authz::requiresReauth('authenticated','token',$route),'token sharing needs reauthentication');
 check(!Authz::requiresReauth('authenticated','login',$route),'normal sharing keeps login behavior');
}
echo "VACATION_UNIT_CHECKS=$count\n";
