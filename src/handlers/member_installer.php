<?php
declare(strict_types=1);
require_once __DIR__.'/../Member.php';
$body=Http::jsonBody(4096); $url=$body['url']??null;
if (array_keys($body)!==['url']||!is_string($url)||strlen($url)>2000) Http::error(400,Http::MSG_BAD_REQUEST);
$url=trim($url);
$parts=parse_url($url);
if ($url!=='' && (!is_array($parts)||($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||preg_match('/[\s\x00-\x1f\x7f]/',$url))) Http::error(400,'HTTPS 다운로드 주소를 입력해 주세요.');
Db::instance()->run("INSERT INTO member_settings(key,value) VALUES('buddy_download_url',:url) ON CONFLICT(key) DO UPDATE SET value=EXCLUDED.value,updated_at=now()",
        [':url'=>$url]);
Audit::allow('member.installer.update',
        ['target_type'=>'system','target_name'=>'tbox-buddy']);
Http::json(['ok'=>true]);
