<?php
declare(strict_types=1);
require_once __DIR__ . '/../Memories.php';
require_once __DIR__ . '/../TravelPlans.php';
TravelPlans::requireReady();
// Sessions have the dispatcher community policy + CSRF guard. Tokens must explicitly opt into travel.
if (defined('TBOX_API_TOKEN_SCOPE')) {
    $scope = $_SERVER['REQUEST_METHOD']==='GET' ? 'travel.read':'travel.write';
    if (!Authz::tokenScopeAllows(TBOX_API_TOKEN_SCOPE,$scope)) { Http::error(403,Http::MSG_TOKEN_SCOPE); }
}
header('Cache-Control: private, no-store');
$route=TBOX_ROUTE;
if ($route==='GET /community/api/travel-plans') { Http::json(TravelPlans::listing($user)); exit; }
if ($route==='POST /community/api/travel-plans') { Http::json(TravelPlans::save($user,null,Http::jsonBody(250000,8)),201); exit; }
$id=$params[0];
if ($route==='GET /community/api/travel-plans/{id}') { Http::json(['plan'=>TravelPlans::output(TravelPlans::row($id,$user),$user)]); exit; }
if ($route==='PATCH /community/api/travel-plans/{id}') { Http::json(TravelPlans::save($user,$id,Http::jsonBody(250000,8))); exit; }
if ($route==='DELETE /community/api/travel-plans/{id}') { TravelPlans::remove($user,$id); Http::json(['ok'=>true]); exit; }
if (str_ends_with($route,'/records')) { Http::json(TravelPlans::record($user,$id,Http::jsonBody(1000),$_SERVER['REQUEST_METHOD']==='DELETE')); exit; }
Http::error(404,Http::MSG_NOT_FOUND);
