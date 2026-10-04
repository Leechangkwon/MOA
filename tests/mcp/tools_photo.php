<?php
declare(strict_types=1);
/** Synthetic GD/file boundary tests. No production storage or network. */
require __DIR__ . '/../../src/community/Memories.php';
require __DIR__ . '/../../src/mcp/McpTools.php';
if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) { fwrite(STDERR, "SKIP: GD required\n"); exit(77); }
$n=0;
function check(bool $ok,string $name): void {global $n; if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);} $n++;}
function denied(array $row,string $dir,int $status,string $name): void {try{McpTools::photoContent($row,$dir);}catch(McpToolError $e){check($e->status===$status,$name);return;}check(false,$name);}
$directory=sys_get_temp_dir().'/mcp-photo-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
$id='11111111-1111-4111-8111-111111111111'; $path=$directory.'/'.$id;
try {
    $im=imagecreatetruecolor(2000,1000); imagefill($im,0,0,imagecolorallocate($im,45,130,205));
    ob_start();imagejpeg($im,null,90);$jpeg=ob_get_clean();imagedestroy($im);
    // JPEG COM metadata must disappear after a fresh raster re-encode.
    $secret='synthetic metadata must never survive';
    $jpeg=substr($jpeg,0,2)."\xff\xfe".pack('n',strlen($secret)+2).$secret.substr($jpeg,2);
    file_put_contents($path,$jpeg);
    $row=['id'=>$id,'mime'=>'image/jpeg','bytes'=>strlen($jpeg),'sha256'=>hash('sha256',$jpeg)];
    $result=McpTools::photoContent($row,$directory); $content=$result['content'];
    check($result['isError']===false && $content[1]['type']==='image' && $content[1]['mimeType']==='image/jpeg','MCP image content');
    $preview=base64_decode($content[1]['data'],true);$size=getimagesizefromstring($preview);
    check($size[0]===1280 && $size[1]===640,'bounded resize');
    check(strlen($preview)<=McpTools::MAX_IMAGE_BYTES && $preview!==$jpeg,'new bounded encoding');
    check(!str_contains($preview,$secret),'metadata stripped');
    check(!str_contains(json_encode($result),$directory),'no filesystem path in output');
    if (function_exists('exif_read_data')) {
        // Minimal little-endian TIFF IFD0 in an EXIF APP1 segment: orientation 6 rotates 90 degrees clockwise.
        $tiff="II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";
        $app1="Exif\x00\x00".$tiff;
        $oriented=substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($app1)+2).$app1.substr($jpeg,2);
        file_put_contents($path,$oriented);
        $rotated=McpTools::photoContent(array_replace($row,['bytes'=>strlen($oriented),'sha256'=>hash('sha256',$oriented)]),$directory);
        $pixels=base64_decode($rotated['content'][1]['data'],true);$rotatedSize=getimagesizefromstring($pixels);
        check($rotatedSize[0]===640 && $rotatedSize[1]===1280,'camera EXIF orientation applied before stripping');
        check(!str_contains($pixels,"Exif\x00\x00"),'orientation metadata not copied');
        file_put_contents($path,$jpeg);
    } else { fwrite(STDERR,"SKIP: EXIF orientation tests require the existing PHP EXIF extension\n"); }
    denied(array_replace($row,['sha256'=>str_repeat('0',64)]),$directory,422,'checksum mismatch blocked');
    denied(array_replace($row,['bytes'=>$row['bytes']+1]),$directory,422,'size mismatch blocked');
    denied(array_replace($row,['bytes'=>McpTools::MAX_PHOTO_BYTES+1]),$directory,422,'input byte bound');
    denied(array_replace($row,['mime'=>'image/png']),$directory,422,'declared MIME mismatch blocked');
    denied(array_replace($row,['mime'=>'video/mp4']),$directory,422,'no video bytes');
    denied(array_replace($row,['id'=>'../private']),$directory,422,'path injection blocked');
    unlink($path); symlink('/nonexistent-synthetic-target',$path);
    denied($row,$directory,404,'symlink rejected'); unlink($path);
    denied($row,$directory,404,'missing file rejected');
} finally {if(is_link($path)||is_file($path))unlink($path);rmdir($directory);}
echo "MCP_PHOTO_UNIT_PASS $n\n";
