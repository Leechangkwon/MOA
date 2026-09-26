<?php
declare(strict_types=1);

/**
 * src/handlers/share_revoke.php — DELETE /api/shares/{num} (정책: authenticated)
 *
 * ★ 15-2: 공유 링크 회수 (PRD F-13, 8장 API 표, ARCH 5.12 「회수·목록」).
 *
 * ★ 자격은 **Authz::decideShareRevoke** 하나가 판단한다 — 만든 사람 또는 Admin, 그 밖은
 *   `not_owner`. 핸들러가 `created_by === $user['id']` 를 직접 비교하지 않는다: 소유자·역할
 *   판단이 핸들러로 흩어지는 순간 한 곳만 고쳐지는 사고가 생긴다 (ARCH 4.1, PRD S-01).
 *   그래서 라우트 정책은 `authenticated` 이고(로그인한 사람이면 창구까지는 온다), 행 단위
 *   판단은 행을 읽은 뒤 여기서 한다 — 파일 삭제·수정과 같은 구조다.
 *
 * ★ **행을 지우지 않는다.** `revoked_at`·`revoked_by` 를 채우는 UPDATE 뿐이며, 앱 DB 계정에는
 *   share_links 의 DELETE 권한이 아예 없다 (ARCH 10장, db/grants.sql). 행이 남아야
 *   "어떤 링크로 얼마나 나갔나" 를 감사 로그의 detail.share_id 로 되짚을 수 있다.
 *
 * 회수된 링크로 다시 오는 요청은 share_download 가 `revoked` 로 막고 404 를 준다.
 *
 * ★ 디버깅 묶음 S-1 (2026-09-26 보안 점검 중간 1) — **남의 링크 = 없는 링크**.
 *   링크 번호는 순차 정수라, 전에는 남의 링크(403)·없는 링크(404)가 갈려 `DELETE /api/shares/1..N` 으로
 *   번호를 훑을 수 있었고, 거부 감사에 **파일 이름·등급**이 실려 「내 활동」(F-12)으로 되돌아 나왔다
 *   (Guest 가 admin-only 파일 이름을 얻는 길). 그래서 만든 사람도 Admin 도 아니면
 *   ① 응답은 없는 링크와 **같은 404 · 같은 문구**(Http::error 한 줄 — 머리도 같다)
 *   ② 감사는 `share.revoke deny not_owner` 그대로 남기되 **대상 없는 감사** — 링크 번호(`share_id`)만,
 *      파일 이름·등급·파일 번호는 넣지 않는다(누가 무엇을 훑었나는 행위자·링크 번호로 충분하다).
 *   만든 사람·Admin 의 갈래(이미 회수됨 400 · 회수 200)는 그대로다 — 그 사람들은 이미 그 파일을 안다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 값이 아니다 — PRD S-01)
 * @var list<string>        $params $params[0] = share_links.id (순차 정수)
 */

$rawId = $params[0] ?? '';
if (!is_string($rawId) || preg_match('/^[1-9][0-9]{0,8}$/', $rawId) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$shareId = (int) $rawId;

$db = Db::instance();

// ─── [1] 링크 행 ──────────────────────────────────────────────
// token_hash 는 읽지 않는다 — 회수에 필요 없는 값이다 (필요 없는 것은 꺼내지 않는다).
$sqlFind = <<<'SQL'
    SELECT s.id,
           s.created_by,
           s.revoked_at,
           s.max_downloads,
           s.download_count,
           f.id           AS file_id,
           f.original_name,
           f.access_level
      FROM share_links s
      JOIN files f ON f.id = s.file_id
     WHERE s.id = :id
    SQL;

/**
 * S-1 — 남의 링크와 없는 링크를 **한 모양**으로 끝낸다: 대상 없는 감사(`share.revoke deny not_owner` · target_type `share` ·
 * target_id = 요청한 번호 · detail `share_id`) → 404 통일 문구. 검토 L-1: 전에는 없는 링크는 감사가 없어서, 「내 활동」의
 * 전체 건수(total)가 늘었나로 번호가 있는지를 알 수 있었다(응답 시간도 갈렸다). 사유 코드는 Authz 의 `not_owner` 하나 —
 * 「그 번호의 링크를 회수할 자격이 없다」는 뜻이고, 없는 번호에도 참이다.
 */
$rejectAsForeign = static function () use ($shareId): void {
    Audit::deny('share.revoke', Authz::CODE_NOT_OWNER, [
        'target_type' => 'share',
        'target_id'   => $shareId,
        'target_name' => null,
        'detail'      => ['share_id' => $shareId],
    ]);
    Http::error(404, Http::MSG_NOT_FOUND);
};

$link = $db->run($sqlFind, [':id' => $shareId])->fetch();
if (!is_array($link)) {
    // 없는 링크 — 남의 링크와 같은 감사·같은 404 (위 머리 주석).
    $rejectAsForeign();
}

// ─── [권한 ✔] 만든 사람 또는 Admin (Authz 안에서만 판단) ──────
// S-1 — 파일 이름·등급을 감사 대상에 싣기 **전에** 판정한다. 남의 링크는 없는 링크와 같은 404 이고,
// 감사에는 링크 번호만 남는다(대상 이름·등급 없음 — 「내 활동」으로 이름이 되돌아 나가지 않게).
[$mayRevoke, $revokeCode] = Authz::decideShareRevoke($user, $link);
if (!$mayRevoke) {
    $rejectAsForeign();
}

$target = [
    'target_type'  => 'file',
    'target_id'    => (int) $link['file_id'],
    'target_name'  => (string) $link['original_name'],
    'access_level' => (string) $link['access_level'],
];
$detail = [
    'share_id'      => $shareId,
    'max_downloads' => (int) $link['max_downloads'],
];

// ─── [2] 이미 회수된 링크 ─────────────────────────────────────
if ($link['revoked_at'] !== null) {
    Audit::deny('share.revoke', 'bad_request', $target + ['detail' => $detail]);
    Http::error(400, Http::MSG_BAD_REQUEST);
}

$sqlRevoke = <<<'SQL'
    UPDATE share_links
       SET revoked_at = now(),
           revoked_by = :me
     WHERE id = :id
       AND revoked_at IS NULL
    SQL;

$db->begin();
try {
    $affected = $db->run($sqlRevoke, [':me' => (int) $user['id'], ':id' => $shareId])->rowCount();
    if ($affected !== 1) {
        // 조회와 회수 사이에 다른 요청이 먼저 회수한 경우 — 이미 회수된 것과 같이 다룬다.
        $db->rollback();
        Audit::deny('share.revoke', 'bad_request', $target + ['detail' => $detail]);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }

    Audit::allow('share.revoke', $target + [
        'detail' => array_merge(
            ['granted_by' => $revokeCode, 'remaining' => 0],
            $detail
        ),
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json(['ok' => true]);
