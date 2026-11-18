<?php
declare(strict_types=1);

/** Shared attachment persistence; callers own the page lock and surrounding transaction. */
final class WhiteboardAttachments
{
    public static function prepare(array $user, array $row, array $upload, bool $background = false): array
    {
        try { $checked = Upload::validate($upload); }
        catch (RuntimeException $e) { Http::error(400, 'PNG 또는 JPEG 그림 파일을 확인해 주세요.'); }
        $allowed = $background ? ['image/png','image/jpeg'] : ['image/png'];
        if (!in_array($checked['mime'],$allowed,true) || $checked['size']>10485760) { Http::error(413, '그림은 10MB 이하로 올려 주세요.'); }
        $size = @getimagesize((string)$upload['tmp_name']);
        $side = $background ? 4096 : 16000;
        if (!$size || $size[0]<1 || $size[1]<1 || $size[0]>$side || $size[1]>$side || $size[0]*$size[1]>16000000) {
            Http::error(413, '그림의 가로·세로 크기를 줄여 주세요.');
        }
        $memoryLimit=trim((string)ini_get('memory_limit'));
        if (preg_match('/^(\d+)([KMG]?)$/iD',$memoryLimit,$parts)) {
            $max=(int)$parts[1]*(1024**([''=>0,'K'=>1,'M'=>2,'G'=>3][strtoupper($parts[2])]));
            if (memory_get_usage(true)+$size[0]*$size[1]*6+16777216>$max) { Http::error(413,'그림 크기를 줄여 주세요.'); }
        }
        $decoded=$checked['mime']==='image/png' ? @imagecreatefrompng((string)$upload['tmp_name']) : @imagecreatefromjpeg((string)$upload['tmp_name']);
        if ($decoded===false) { Http::error(400,'손상된 그림 파일입니다.'); }
        imagedestroy($decoded);
        if ($background) { Memories::stripPhotoMetadata((string)$upload['tmp_name'],$checked['mime']); }
        clearstatcache(true,(string)$upload['tmp_name']);
        $checked['size']=filesize((string)$upload['tmp_name']);
        $checked['sha256']=hash_file('sha256',(string)$upload['tmp_name']);
        try { Upload::checkDailyQuota((int)$user['id'],(int)$checked['size']); }
        catch (RuntimeException $e) { Http::error(429, '오늘 올릴 수 있는 파일 용량을 넘었습니다.'); }
        require_once __DIR__.'/../Sensitive.php';
        $sensitive=Sensitive::scan((string)$upload['tmp_name'],$checked['ext']);
        if ($sensitive!==[] && community_attachment_wide($row)) { Http::error(400,'가릴 내용이 있는지 그림을 확인해 주세요.'); }
        return [$checked,$sensitive,(int)$size[0],(int)$size[1]];
    }

    public static function store(array $user, array $row, array $checked, string $tmpPath,
        string $original, array $sensitive, string $storedName): array
    {
        $sql = 'INSERT INTO community.page_attachments
            (page_id,uploaded_by,original_name,ext,mime,size_bytes,sha256,stored_name,scan_status,sensitive_flags)
            VALUES(CAST(:page AS bigint),CAST(:by AS bigint),:name,:ext,:mime,CAST(:size AS bigint),:sha,:stored,:scan,CAST(:flags AS text[]))
            RETURNING id,public_id';
        $scan=Upload::initialScanStatus();
        $ins=Db::instance()->run($sql,[':page'=>(int)$row['id'],':by'=>(int)$user['id'],':name'=>$original,
            ':ext'=>$checked['ext'],':mime'=>$checked['mime'],':size'=>$checked['size'],':sha'=>$checked['sha256'],
            ':stored'=>$storedName,':scan'=>$scan,':flags'=>'{'.implode(',',array_keys($sensitive)).'}'])->fetch();
        if (!is_array($ins)) { throw new RuntimeException('Attachment insert failed'); }
        Storage::put($tmpPath,$storedName,Storage::AREA_ATTACHMENTS);
        try {
            Audit::allow('attachment.upload',['target_type'=>'page_attachment','target_id'=>(int)$ins['id'],
                'target_name'=>$original,'detail'=>community_anon_audit_detail($row,
                    ['page_id'=>(int)$row['id'],'page_title'=>community_target_title((string)$row['title']),
                    'granted_by'=>CommunityAuthz::decideAttachmentUpload($user,$row)[1],
                    'ext'=>$checked['ext'],'mime'=>$checked['mime'],'size_bytes'=>$checked['size'],
                    'sha256'=>$checked['sha256'],'scan_status'=>$scan]+($sensitive===[] ? [] : ['sensitive'=>$sensitive]))]);
        } catch (Throwable $e) { Storage::removeUploaded($storedName,Storage::AREA_ATTACHMENTS); throw $e; }
        return $ins+['stored_name'=>$storedName,'scan_status'=>$scan];
    }
}
