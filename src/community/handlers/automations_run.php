<?php
declare(strict_types=1);
$r=community_automation_row($params[0]); $body=Http::jsonBody();
if ($r===null || $r['deleted_at']!==null || !$r['is_enabled'] || $r['kind']!=='button') { Http::error(404,Http::MSG_NOT_FOUND); }
if (array_keys($body)!==['page_id'] || $body['page_id']!==$r['scope_page_uuid']) { Http::error(400,Http::MSG_BAD_REQUEST); }
$page=community_page_row($r['scope_page_uuid'],(int)$user['id']);
if ($page===null || !CommunityAuthz::decidePageRead($user,$page)[0]) { Http::error(404,Http::MSG_NOT_FOUND); }
$can=$r['press_scope']==='owner' ? (int)$r['owner_user_id']===(int)$user['id'] : CommunityAuthz::decidePageChange($user,$page)[0];
if (!$can) { Http::error(403,Http::MSG_FORBIDDEN); }
try { Http::json(community_automation_execute($r,(int)$user['id'],false,null,null,null,$user,null,null,'button')); }
catch (InvalidArgumentException $e) { Http::json(['error'=>'실행하지 못했습니다.','reason'=>$e->getMessage()],$e->getMessage()==='duplicate_press'?409:404); }
