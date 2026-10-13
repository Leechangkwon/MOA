<?php
declare(strict_types=1);

/**
 * scripts/lib/attachment_purge.php — 영구 삭제 대기열의 문서 첨부 디스크 파일 지우기 (사이트 Q:F-12 U3-1 · U3-1b, 커뮤니티 ARCH 24.6)
 *
 * 부르는 곳 둘 — **같은 함수**다(판정 · 경로 규칙이 두 벌이 되지 않게):
 *   scripts/community_purge.php   휴지통 30일 정리(`community.purge_trash`) 뒤
 *   scripts/privacy_purge.php     탈퇴 30일 뒤 파기(`public.privacy_purge_accounts` → `community.privacy_purge_pages`) 뒤
 * 두 DB 함수는 첨부 **행**을 지우며 디스크 이름을 `community.page_attachment_purge_queue` 에 먼저 적는다(같은 트랜잭션).
 * 이 함수는 그 대기열을 읽어 `Storage::purge(이름, Storage::AREA_ATTACHMENTS)` 로 파일을 지우고(경로는 그 함수 한 곳 — 이름 정규식 ·
 * realpath 로 저장소 안 · 이미 없으면 성공), **지운 뒤에야** 대기열 줄을 지운다 — 중간에 죽어도 다음 실행(어느 스크립트든)이 잇는다.
 *
 * $owner 는 표 소유자 연결이어야 한다(대기열의 앱 권한은 0). 이름 · 경로는 반환값에도 출력에도 싣지 않는다 — 건수만.
 * dry($run=false)는 대기열 건수만 센다(파일 · 줄 0 변경).
 *
 * @return array{ready:bool, queued:int, removed:int, failed:int}
 */
function attachment_purge_drain(PDO $owner, bool $run, int $limit = 5000): array
{
    $out = ['ready' => false, 'queued' => 0, 'removed' => 0, 'failed' => 0];
    $ready = $owner->query("SELECT to_regclass('community.page_attachment_purge_queue') IS NOT NULL")->fetchColumn();
    if ($ready !== true && $ready !== 't') {
        return $out;   // 마이그레이션 전 DB — 할 일 없음
    }
    $out['ready'] = true;
    $list = $owner->prepare('SELECT stored_name FROM community.page_attachment_purge_queue ORDER BY queued_at, stored_name LIMIT :lim');
    $list->bindValue(':lim', max(1, min($limit, 5000)), PDO::PARAM_INT);
    $list->execute();
    $queue = $list->fetchAll(PDO::FETCH_COLUMN);
    $out['queued'] = count($queue);
    if (!$run) {
        return $out;
    }
    $dequeue = $owner->prepare('DELETE FROM community.page_attachment_purge_queue WHERE stored_name = :n');
    foreach ($queue as $name) {
        try {
            Storage::purge((string) $name, Storage::AREA_ATTACHMENTS);
            $dequeue->execute([':n' => (string) $name]);
            $out['removed']++;
        } catch (Throwable $e) {
            $out['failed']++;   // 이름 · 경로는 찍지 않는다 — 다음 실행이 같은 줄을 다시 본다
        }
    }
    return $out;
}
