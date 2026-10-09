<?php
declare(strict_types=1);

/** T:F-67 MVP: release the existing, scanned F-65 ZIP; never weaken ordinary uploads. */
final class Programs
{
    public static function mayPublish(array $user): bool
    {
        return Authz::canUploadExec($user, 'admin-only');
    }

    public static function listing(array $user): array
    {
        [$where, $bindings] = Authz::visibleFilesWhere($user);
        $sql = <<<'SQL'
            SELECT f.public_id AS id,f.original_name AS name,f.program_version AS version,
                   f.sha256,f.size_bytes,f.access_level,f.program_published_at AS published_at,u.username AS uploaded_by
              FROM files f JOIN users u ON u.id=f.uploaded_by
             WHERE f.program AND f.program_current AND f.scan_status='clean' AND
            SQL;
        return Db::instance()->run($sql . $where . ' LIMIT 1', $bindings)->fetchAll();
    }

    public static function candidates(array $user): array
    {
        if (!self::mayPublish($user)) return [];
        return Db::instance()->run(<<<'SQL'
            SELECT public_id AS id,original_name AS name,sha256,size_bytes
              FROM files WHERE uploaded_by=:uid AND deleted_at IS NULL AND NOT program
               AND exec_content AND ext='zip' AND access_level='admin-only' AND folder_id IS NULL
               AND scan_status='clean' AND cardinality(sensitive_flags)=0
             ORDER BY id DESC LIMIT 50
            SQL, [':uid'=>(int)$user['id']])->fetchAll();
    }

    public static function publish(array $user, array $body): void
    {
        if (!self::mayPublish($user)) Http::error(403, Http::MSG_FORBIDDEN);
        $keys=array_keys($body); sort($keys);
        if ($keys!==['ack','file_id','level','otp','password','sha256','team_id','version']
            || $body['ack']!==true || !is_string($body['file_id']) || !Storage::isValidUuid($body['file_id'])
            || !is_string($body['version']) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/', $body['version'])!==1
            || !is_string($body['sha256']) || preg_match('/\A[a-f0-9]{64}\z/', $body['sha256'])!==1
            || !in_array($body['level'],['public','internal','team','admin-only'],true)
            || !is_string($body['password']) || !is_string($body['otp'])
            || ($body['team_id']!==null && (!is_int($body['team_id']) || $body['team_id']<1))) Http::error(400,Http::MSG_BAD_REQUEST);
        if (($body['level']==='team')!==($body['team_id']!==null)) Http::error(400,Http::MSG_BAD_REQUEST);
        [$allowed] = Authz::decideUploadAs($user, $body['level'], $body['team_id']);
        if (!$allowed) Http::error(403,Http::MSG_FORBIDDEN);
        $teamSql='SELECT id FROM teams WHERE id=:id AND is_active';
        if ($body['team_id']!==null && !Db::instance()->run($teamSql, [':id'=>$body['team_id']])->fetch()) Http::error(400,Http::MSG_BAD_REQUEST);
        // Refuse a clean-by-default upload from a scanner-disabled installation.
        if (Env::getOrEmpty('SCAN_ENABLED')!=='1') Http::error(503,'파일 검사가 켜져 있어야 게시할 수 있습니다.');
        if (!Auth::verifyCurrentPassword($user,$body['password'])) {
            unset($body['password'],$body['otp']);
            Audit::deny('program.publish','bad_credentials',['target_type'=>'system']);
            Auth::countFailureAndRevoke((int)$user['id']); Http::error(400,Http::MSG_BAD_REQUEST);
        }
        $otp = preg_match(Totp::CODE_RE,$body['otp'])===1 ? Auth::verifyOtpOnce($user,$body['otp']) : 'bad_code';
        unset($body['password'],$body['otp']);
        if ($otp!=='ok') {
            Audit::deny('program.publish',$otp,['target_type'=>'system']);
            if ($otp!=='mfa_not_enabled') Auth::countFailureAndRevoke((int)$user['id']);
            Http::error($otp==='mfa_not_enabled' ? 403:400,Http::MSG_BAD_REQUEST);
        }
        $db=Db::instance(); $db->begin();
        try {
            // Serialize publication for this single product, including the first release.
            $db->run("SELECT pg_advisory_xact_lock(74626701)");
            $file=$db->run(<<<'SQL'
                SELECT * FROM files WHERE public_id=CAST(:id AS uuid) AND uploaded_by=:uid
                 AND deleted_at IS NULL AND NOT program AND exec_content AND ext='zip'
                 AND access_level='admin-only' AND folder_id IS NULL AND scan_status='clean'
                 AND cardinality(sensitive_flags)=0 FOR UPDATE
                SQL, [':id'=>$body['file_id'],':uid'=>(int)$user['id']])->fetch();
            if (!$file || !hash_equals($file['sha256'],$body['sha256'])) {
                $db->rollback(); Http::error(409,'선택한 파일의 상태가 바뀌었습니다. 다시 확인해 주세요.');
            }
            $scanned=$db->run("SELECT id FROM audit_logs WHERE action='file.scan' AND result='allow' AND target_type='file' AND target_id=:id AND detail->>'sha256'=:sha LIMIT 1",
                [':id'=>(int)$file['id'],':sha'=>$file['sha256']])->fetch();
            if (!$scanned) { $db->rollback(); Http::error(409,'이 파일의 악성코드 검사 완료 기록이 없습니다. 다시 검사해 주세요.'); }
            Storage::assertExists($file['stored_name']);
            if (!hash_equals($file['sha256'],Storage::sha256($file['stored_name']))) throw new RuntimeException('program integrity');
            $db->run('UPDATE files SET program_current=false WHERE program AND program_current');
            $db->run(<<<'SQL'
                UPDATE files SET program=true,program_current=true,program_version=:version,
                  program_published_at=clock_timestamp(),access_level=:level,team_id=:team WHERE id=:id
                SQL,[':id'=>$file['id'],':version'=>$body['version'],':level'=>$body['level'],':team'=>$body['team_id']]);
            Audit::allow('program.publish',['target_type'=>'file','target_id'=>(int)$file['id'],
                'target_name'=>$file['original_name'],'access_level'=>$body['level'],
                'detail'=>['sha256'=>$file['sha256'],'version'=>$body['version'],'otp_verified'=>true]]);
            $db->commit();
        } catch (Throwable $e) { $db->rollback(); throw $e; }
    }

    public static function download(array $user, string $id): never
    {
        if (!Storage::isValidUuid($id)) Http::error(404,Http::MSG_NOT_FOUND);
        $file=Db::instance()->run("SELECT *, 'file' AS item_kind FROM files WHERE public_id=CAST(:id AS uuid) AND program AND program_current AND deleted_at IS NULL",
            [':id'=>$id])->fetch();
        if (!$file) Http::error(404,Http::MSG_NOT_FOUND);
        [$allowed,$reason]=Authz::decideRead($user,$file);
        if (!$allowed) {
            Audit::deny('program.download',$reason,['target_type'=>'file','target_id'=>(int)$file['id']]);
            Http::error(403,Http::MSG_FORBIDDEN);
        }
        if ($file['scan_status']!=='clean') Http::error(423,'파일 검사가 끝나지 않아 받을 수 없습니다.');
        Storage::assertExists($file['stored_name']);
        if (!hash_equals($file['sha256'],Storage::sha256($file['stored_name']))) {
            Audit::error('program.download','hash_mismatch',['target_type'=>'file','target_id'=>(int)$file['id']]);
            Http::error(500,'파일을 확인할 수 없습니다.');
        }
        Audit::allow('program.download',['target_type'=>'file','target_id'=>(int)$file['id'],'target_name'=>$file['original_name'],
            'access_level'=>$file['access_level'],'detail'=>['sha256'=>$file['sha256'],'version'=>$file['program_version'],'granted_by'=>$reason]]);
        Storage::stream($file['stored_name'],$file['original_name'],$file['mime']);
    }
}
