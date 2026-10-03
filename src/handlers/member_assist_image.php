<?php
declare(strict_types=1);
require_once __DIR__ . '/../Member.php';
Member::requireMessenger($user);
$row = Db::instance()->run(<<<'SQL'
    SELECT a.stored_name,a.image_mime,a.image_sha256 FROM member_ai_assists a
    WHERE a.id=CAST(:id AS uuid) AND a.state='complete' AND a.action='image' AND a.stored_name IS NOT NULL
      AND (a.user_id=:owner OR EXISTS (
        SELECT 1 FROM member_messages m WHERE m.ai_attachment_id=a.id
          AND (m.sender_id=:sender OR m.recipient_id=:recipient)
      ))
    SQL,
    [':id' => $params[0], ':owner' => $user['id'], ':sender' => $user['id'], ':recipient' => $user['id']])->fetch();
if (!$row) Http::error(404, '이미지를 찾을 수 없습니다.');
try {
    Storage::assertExists($row['stored_name']);
    if (!hash_equals($row['image_sha256'], Storage::sha256($row['stored_name']))) Http::error(404, '이미지를 불러올 수 없습니다.');
    Audit::allow('member.ai.image.view', ['target_type' => 'system', 'target_name' => '메신저 이미지']);
    $extension = match ($row['image_mime']) { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp' };
    Storage::streamInline($row['stored_name'], 'moa-ai-image.' . $extension, $row['image_mime']);
} catch (Throwable $e) {
    error_log('[moa] member.ai.image failed: ' . get_class($e));
    Http::error(404, '이미지를 불러올 수 없습니다.');
}
