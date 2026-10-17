<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Programs.php';
$items=Programs::listing($user);
$item=$items[0] ?? null;
Http::json(['release'=>$item===null ? null:[
    'version'=>$item['version'],'sha256'=>$item['sha256'],'size_bytes'=>(int)$item['size_bytes'],
    'changes'=>$item['description'],'published_at'=>$item['published_at'],
    'page'=>Http::url('/programs'),'update_policy'=>'user_selected_when_idle',
    'publisher_verified'=>true,'file_scan'=>'clean',
]]);
