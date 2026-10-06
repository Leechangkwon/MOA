<?php
declare(strict_types=1);
/** Offline input and binary integrity checks; no DB, credentials or provider calls. */
final class Http
{
    public const MSG_BAD_REQUEST = 'bad request';
    public static function error(int $status, string $text): void { throw new RuntimeException($text, $status); }
}
require __DIR__ . '/../../src/community/Memories.php';
require __DIR__ . '/../../src/community/MemoryAi.php';
$count = 0;
function check(bool $value, string $label): void {
    global $count; if (!$value) throw new RuntimeException('FAIL ' . $label); $count++; echo "PASS $label\n";
}
$id = '11111111-1111-4111-8111-111111111111';
$valid = ['consent' => true, 'request_id' => $id, 'revision' => 1, 'media_ids' => [$id], 'tone' => '담백하고 따뜻한', 'wish' => '행복한 오늘의 기록을 덧붙여 줘'];
check(MemoryAi::input($valid) === $valid, 'explicit photo request accepted');
foreach ([['consent'=>false],['revision'=>0],['revision'=>'1'],['media_ids'=>[$id,$id]],['media_ids'=>['../file']],
    ['media_ids'=>[$id,$id,$id,$id,$id]],['media_ids'=>['name'=>$id]],['tone'=>'invented'],['wish'=>''],['wish'=>str_repeat('a',501)],
    ['url'=>'https://example.invalid/secret'],['owner_user_id'=>1]] as $patch) {
    $denied=false; try { MemoryAi::input(array_replace($valid,$patch)); } catch (RuntimeException $e) { $denied=$e->getCode()===400; }
    check($denied,'invalid consent/body/selection rejected');
}
$dir = sys_get_temp_dir() . '/memory-ai-unit-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
// Complete tiny PNG, also used by the real HTTP fixture.
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGP8/x8AAwMCAHwG0LwAAAAASUVORK5CYII=');
$row = ['id'=>$id,'mime'=>'image/png','bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
try {
    file_put_contents($dir.'/'.$id,$bytes);
    $part = MemoryAi::photoPart($row,$dir);
    check(base64_decode($part['inlineData']['data'],true)===$bytes && $part['inlineData']['mimeType']==='image/png','verified photo becomes actual inline image bytes');
    foreach ([['sha256'=>str_repeat('0',64)],['bytes'=>strlen($bytes)+1],['mime'=>'image/jpeg'],['mime'=>'image/gif'],['id'=>'../secret'],['bytes'=>MemoryAi::MAX_PHOTO_BYTES+1]] as $patch) {
        $denied=false; try { MemoryAi::photoPart(array_replace($row,$patch),$dir); } catch (MemberAiDenied $e) { $denied=true; }
        check($denied,'wrong hash/size/type/path rejected');
    }
    $post=['caption'=>'원래 메모','location'=>'남한산성','trip'=>'연휴','taken_on'=>'2026-10-03'];
    $payload=MemoryAi::payload(['post'=>$post,'photos'=>[$row]],$valid,$dir);
    $parts=$payload['contents'][0]['parts'];
    check(count($parts)===2 && isset($parts[1]['inlineData']) && !isset($parts[1]['fileData']),'text plus image supplied without remote URL');
    check(json_decode($parts[0]['text'],true)['현재 메모']===$post['caption'],'current caption supplied as data');
    $plain=MemoryAi::payload(['post'=>$post,'photos'=>[]],$valid,$dir);
    check(count($plain['contents'][0]['parts'])===1,'text-only preview contains no invented image');
    unlink($dir.'/'.$id);symlink(__FILE__,$dir.'/'.$id);
    $denied=false;try { MemoryAi::photoPart($row,$dir); } catch (MemberAiDenied $e) { $denied=true; }
    check($denied,'symlink photo refused');
} finally { if (file_exists($dir.'/'.$id) || is_link($dir.'/'.$id)) unlink($dir.'/'.$id); rmdir($dir); }
echo "$count checks passed\n";
