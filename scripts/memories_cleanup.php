<?php
declare(strict_types=1);
/** Remove only unattached uploads older than seven days. Default is dry run. */
require_once __DIR__ . '/lib/cli_bootstrap.php';
require_once __DIR__ . '/../src/community/Memories.php';
if (!Memories::ready()) { fwrite(STDERR, "Travel schema is not installed.\n"); exit(1); }
$apply = in_array('--apply', $argv, true);
$db = Db::instance();
$sql = "SELECT id,owner_user_id FROM community.memory_media WHERE post_id IS NULL AND created_at<now()-interval '7 days' ORDER BY created_at LIMIT 500";
$candidates = $db->run($sql)->fetchAll();
$removed = 0;
foreach ($candidates as $candidate) {
    if (!$apply) { continue; }
    $db->begin();
    // Same lock order as save/upload. A concurrently attached photo is never removed.
    $lock = 'SELECT id FROM public.users WHERE id=:owner FOR UPDATE';
    $db->run($lock, [':owner' => (int) $candidate['owner_user_id']]);
    $recheck = "SELECT id FROM community.memory_media WHERE id=CAST(:id AS uuid) AND post_id IS NULL AND created_at<now()-interval '7 days' FOR UPDATE";
    if (!$db->run($recheck, [':id' => $candidate['id']])->fetch()) { $db->rollback(); continue; }
    $path = Memories::directory() . '/' . $candidate['id'];
    if (is_link($path) || (is_file($path) && !unlink($path))) { $db->rollback(); throw new RuntimeException('Cannot remove unattached upload'); }
    $remove = 'DELETE FROM community.memory_media WHERE id=CAST(:id AS uuid)';
    $db->run($remove, [':id' => $candidate['id']]);
    $db->commit(); $removed++;
}
echo ($apply ? "Removed: $removed" : 'Dry run, unattached uploads older than seven days: ' . count($candidates)) . "\n";
