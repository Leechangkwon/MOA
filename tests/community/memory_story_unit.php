<?php
declare(strict_types=1);
require __DIR__.'/../../src/community/Memories.php';
require __DIR__.'/../../src/community/MemoryAi.php';
$n=0;function checkStory(bool $ok,string $name):void{global $n;if(!$ok)throw new RuntimeException($name);$n++;echo "PASS $name\n";}
$ids=[];for($i=1;$i<=10;$i++)$ids[]=sprintf('%08x-1111-4111-8111-111111111111',$i);
$scenes=[];foreach($ids as $i=>$id)$scenes[]=['photos'=>[$i+1],'title'=>'장면 '.$i,'text'=>'사진에서 보이는 모습.'];
$raw=['title'=>'산책의 기록','intro'=>'길과 나무.','scenes'=>$scenes,'suggested_scene_order'=>array_reverse(range(1,10)),'order_reason'=>'먼 풍경에서 가까운 장면으로.'];
$parsed=MemoryAi::parseStory(json_encode($raw),$ids);
checkStory(count($parsed['story']['scenes'])===10,'ten ordered scenes accepted');
checkStory($parsed['suggested_story']['scenes'][0]['media_ids']===[$ids[9]],'proposal is separate exact permutation');
foreach(['missing','duplicate','foreign','float','order','long','bad_suggestion','html_field'] as $bad){
 $v=$raw;
 switch($bad){case 'missing':array_pop($v['scenes']);break;case 'duplicate':$v['scenes'][0]['photos'][]=1;break;case 'foreign':$v['scenes'][0]['photos']=[11];break;case 'float':$v['scenes'][0]['photos']=['1'];break;case 'order':$v['scenes']=array_reverse($v['scenes']);break;case 'long':$v['scenes'][0]['text']=str_repeat('가',601);break;case 'bad_suggestion':$v['suggested_scene_order']=[1,1];break;case 'html_field':$v['title']=['html'=>'x'];break;}
 $denied=false;try{MemoryAi::parseStory(json_encode($v),$ids);}catch(Throwable $e){$denied=true;}
 checkStory($denied,'malformed '.$bad.' refused');
}
$dir=sys_get_temp_dir().'/story-ref-'.bin2hex(random_bytes(5));mkdir($dir,0700);
try{
 $im=imagecreatetruecolor(2400,1600);imagefill($im,0,0,imagecolorallocate($im,60,110,90));imagejpeg($im,$dir.'/'.$ids[0],95);imagedestroy($im);
 $before=hash_file('sha256',$dir.'/'.$ids[0]);$row=['id'=>$ids[0],'mime'=>'image/jpeg','bytes'=>filesize($dir.'/'.$ids[0]),'sha256'=>$before];
 $ref=MemoryAi::referencePart($row,$dir);$bytes=base64_decode($ref['inlineData']['data']);$size=getimagesizefromstring($bytes);
 checkStory($size[0]===1280 && $size[1]===853 && strlen($bytes)<1000000,'large reference resized to bounded image');
 checkStory(hash_file('sha256',$dir.'/'.$ids[0])===$before,'reference processing never modifies original');
 $payload=MemoryAi::storyPayload(['post'=>['caption'=>'기존 AI의 과장된 감상문','original_caption'=>'산책','location'=>'공원','trip'=>'','taken_on'=>'2026-10-03'],'photos'=>[$row]],['tone'=>'간결한 사진 에세이','wish'=>'짧게'],$dir);
 $context=$payload['contents'][0]['parts'][0]['text'];checkStory(str_contains($context,'산책')&&!str_contains($context,'과장된'),'story starts from original memo instead of prior generated prose');
}finally{unlink($dir.'/'.$ids[0]);rmdir($dir);}
echo "$n story checks passed\n";
