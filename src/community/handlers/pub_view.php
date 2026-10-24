<?php
declare(strict_types=1);
$pub=community_publication($params[0]);$pages=community_public_pages($pub);$page=null;
foreach($pages as $p) { if(isset($params[1]) ? $p['public_id']===$params[1] : (int)$p['id']===(int)$pub['page_id']) { $page=$p;break; } }
if($page===null) { community_public_missing(); }
if(!community_public_rate('view:'.Http::clientIp(),60,60)) { Http::error(429,'잠시 후 다시 시도해 주세요'); }
$db=Db::instance();$db->begin();
try {
    $db->run('UPDATE community.web_publications SET view_count=view_count+1,last_viewed_at=now() WHERE id=:id',
        [':id'=>(int)$pub['id']]);
    if(community_public_rate('audit:'.$pub['id'].':'.Http::clientIp(),3600,1)) { community_integration_audit('pub.view',(int)$pub['id']); }
    $db->commit();
}catch(Throwable $e){$db->rollback();throw $e;}
if($pub['allow_index']) { header_remove('X-Robots-Tag'); }
$esc=static fn(string $s): string=>htmlspecialchars($s,ENT_QUOTES,'UTF-8');
$links=[];$ids=[];foreach($pages as $p) { $links[$p['public_id']]=['title'=>$p['title'],'url'=>rtrim(Env::get('APP_URL'),'/').Http::communityUrl('/pub/'.$params[0].'/p/'.$p['public_id'])];$ids[]=(int)$p['id']; }
$doc=json_decode($page['doc'],true);
$ctx=['public'=>true,'public_links'=>$links,'synced'=>community_sync_map($doc,null,$ids),'embed_domains'=>community_embed_hosts()];
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
if(!$pub['allow_index']) { echo '<meta name="robots" content="noindex, nofollow">'; }
echo '<title>'.$esc($page['title']).' · 모아</title><link rel="icon" type="image/svg+xml" href="'.$esc(Http::url('/assets/moa-favicon.svg')).'?v='.(int) filemtime(__DIR__.'/../../../public/assets/moa-favicon.svg').'"><link rel="stylesheet" href="'.$esc(Http::url('/assets/app.css')).'"><link rel="stylesheet" href="'.$esc(Http::url('/assets/community/community.css')).'"></head><body><main class="main">';
if(count($pages)>1) { echo '<nav aria-label="공개 페이지"><ul>';foreach($links as $l) { echo '<li><a href="'.$esc($l['url']).'">'.$esc($l['title']).'</a></li>'; }echo '</ul></nav>'; }
echo '<article><h1>'.$esc($page['title']).'</h1><p class="muted">'.$esc($page['display_name']??'').'</p>'.DocRender::html($doc,$ctx).'</article><footer class="muted">모아에서 공개한 페이지</footer></main></body></html>';
