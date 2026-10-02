<?php
declare(strict_types=1);
$dbRow=community_db_row((string)$params[0]);
if ($dbRow===null) { Http::error(404,Http::MSG_NOT_FOUND); }
$gate=community_history_gate($user,(string)$dbRow['page_public_id']);
$schema=community_db_schema_of($dbRow['schema']); $view=null;
foreach (community_db_views_of($dbRow['views'],$schema) as $candidate) {
    if ($candidate['id']===Http::queryText('view',20) && $candidate['type']==='chart') { $view=$candidate; break; }
}
if ($view===null) { Http::json(['error'=>'차트 뷰를 찾을 수 없습니다','reason'=>'bad_view'],400); exit; }
try { $result=community_chart_data($user,$dbRow,$schema,$view,Http::queryText('from',20),Http::queryText('to',20),Http::queryText('owner',64)); }
catch (InvalidArgumentException $e) { Http::json(['error'=>'차트 거르개가 올바르지 않습니다','reason'=>$e->getMessage()],400); exit; }
Http::json($result);
