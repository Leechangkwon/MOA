<?php
declare(strict_types=1);
require __DIR__.'/../../src/community/Doc.php';
require __DIR__.'/../../src/community/DocRender.php';
require __DIR__.'/../../src/community/DocMarkdown.php';
$checks=[];$check=static function(bool $ok,string $name) use (&$checks):void { $checks[]=['pass'=>$ok,'name'=>$name];if(!$ok){throw new RuntimeException($name);} };
$b=['type'=>'db_view','id'=>'11111111-1111-4111-8111-111111111111','database_id'=>'22222222-2222-4222-8222-222222222222','view_id'=>'v12345678'];$doc=['schema'=>1,'blocks'=>[$b]];
$check(!Doc::validate($doc,8)['ok'],'stage8 rejects db_view');$check(Doc::validate($doc,9)['ok'],'stage9 accepts db_view');
$bad=$doc;$bad['blocks'][0]['view_id']='../../etc/passwd';$check(!Doc::validate($bad,9)['ok'],'view id path rejected');
$bad=$doc;$bad['blocks'][0]['database_id']='1;DROP';$check(!Doc::validate($bad,9)['ok'],'database uuid rejected');
$kids=[];for($i=0;$i<13;$i++){$one=$b;$one['id']=Doc::uuid4();$kids[]=$one;}$nested=['schema'=>1,'blocks'=>[['type'=>'toggle','id'=>Doc::uuid4(),'text'=>[],'children'=>$kids]]];$check(!Doc::validate($nested,9)['ok'],'nested widgets share 12 total cap');
$ctx=['db_views'=>[$b['id']=>['title'=>'검토 <script>','name'=>'합계']]];
$html=DocRender::html($doc,$ctx);$check(!str_contains($html,'<script>'),'server card escapes source title');
$md=DocMarkdown::render($doc,$ctx);$check(str_contains($md,'데이터베이스 보기'),'readable export names view');
$unreadable=DocRender::html($doc,[]);$check(str_contains($unreadable,'볼 수 없는 데이터베이스')&&!str_contains($unreadable,'22222222'),'unreadable card discloses no source');
echo json_encode(['passed'=>count($checks),'failed'=>0,'checks'=>$checks,'card_html'=>$html,'unreadable_html'=>$unreadable],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
