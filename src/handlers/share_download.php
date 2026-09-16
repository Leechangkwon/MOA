<?php
declare(strict_types=1);

/**
 * src/handlers/share_download.php — GET /s/{token} (정책: public)
 *
 * ★ 15-2: 공유 링크로 받기 (PRD F-13 · Q-5 기본안, 8장 API 표, ARCH 5.12 「받기」).
 *
 * ★ 이 라우트는 **로그인이 없다.** 라우터는 정책이 `public` 이면 Auth::requireLogin() 도
 *   CSRF 검사도 강제 절차 관문도 부르지 않는다(로그인 API 와 함께 public 인 둘째 라우트).
 *   그러므로 이 파일은 `$user` 를 쓰지 않는다 — 자격을 대신하는 것은 **토큰 자체**다.
 *
 * ★ 접근 등급 판정(decideRead)을 **하지 않는다.** 링크는 발급자의 권한을 빌려 주는 것이고
 *   그 판단은 만들 때(share_create 의 decideRead → decideEdit) 이미 끝났다 (ARCH 5.12).
 *   대신 `admin-only` 파일의 링크는 Admin 만 만들 수 있다 — decideEdit 가 그렇게 귀결된다.
 *
 * 순서 (ARCH 5.12 ①~⑦) — 한 트랜잭션 안에서
 *   ① sha256 로 행 조회 (`FOR UPDATE`: 같은 링크의 동시 요청을 줄 세운다)
 *   ② revoked_at IS NULL          아니면 revoked
 *   ③ expires_at > now()          아니면 expired
 *   ④ download_count < max        아니면 exhausted
 *   ⑤ 파일 deleted_at·purged_at IS NULL   아니면 file_gone
 *   ⑤-2 저장소에 실제 파일이 있고 sha256 이 기록과 같은가 (다운로드와 같은 코드 경로, R-13)
 *   ⑥ 조건부 UPDATE `… WHERE id = :id AND download_count < max_downloads` 가 **1행**일 때만 진행
 *      (동시 요청 둘이 같은 마지막 한 번을 쓰지 못하게 한다)
 *   ⑦ COMMIT 뒤 Storage::stream — 항상 attachment (F-06 과 같은 함수)
 *
 * ★ ②~⑤ 어디서 막혀도 **응답은 404 「찾을 수 없습니다」 하나**다 (PRD F-13).
 *   "만료됐다"·"이미 다 썼다"·"그런 링크는 없다" 를 구분해 주면 그 자체가 토큰 탐색의 신호가 된다.
 *   사유는 감사 로그에만 남는다 (PRD S-09-5 와 같은 원칙).
 *
 * ★ 감사 행에는 actor 가 없다(로그인한 사람이 없으므로). "누가 열어 줬나" 는
 *   detail 의 `issued_by`(발급자 계정명)·`share_id` 로 남는다 (ARCH 5.12 · 7.5).
 *
 * @var array<string,mixed>|null $user   public 라우트라 언제나 null 이다 (쓰지 않는다)
 * @var list<string>             $params $params[0] = 토큰 (base64url 43자, 라우터가 형태를 좁혔다)
 */

$shareToken = $params[0] ?? '';

// 라우터 자리표시자가 이미 `[A-Za-z0-9_-]{43}` 로 좁혔다. 형태 재검사는 여기서도 한 번 더 한다
// (라우트 표가 바뀌어도 이 파일이 스스로 성립하게 — files_download 의 UUID 재검사와 같은 이유).
if (!is_string($shareToken) || preg_match('/^[A-Za-z0-9_-]{43}$/', $shareToken) !== 1) {
    Http::error(404, Http::MSG_NOT_FOUND);
}

$db = Db::instance();

/**
 * 실패는 전부 같은 404 다. 사유는 감사 로그에만 남긴다.
 *
 * @param array<string,mixed> $target  target_type/target_id/target_name/access_level
 * @param array<string,mixed> $detail
 */
$rejectShare = static function (string $reason, array $target, array $detail) use ($db): void {
    if ($db->inTransaction()) {
        $db->rollback();   // 조회 잠금을 풀고 나간다 (기록은 별개 트랜잭션으로 남는다)
    }
    Audit::deny('share.download', $reason, $target + ['detail' => $detail]);
    Http::error(404, Http::MSG_NOT_FOUND);
};

/** 대상 파일을 알 수 없을 때 쓰는 target (문장 틀에 {target} 이 없는 조합) */
$systemTarget = ['target_type' => 'system', 'target_name' => null];

/*
 * ① 토큰의 sha256 으로 행을 찾는다. 원문은 어디에도 저장돼 있지 않으므로 이 대조가 유일한 길이다.
 *
 * `FOR UPDATE OF s` — 잠그는 것은 share_links 행뿐이다. files·users 까지 잠그면
 * 같은 파일의 다른 요청(다운로드·수정)이 불필요하게 기다린다.
 */
$sqlFind = <<<'SQL'
    SELECT s.id            AS share_id,
           s.created_by,
           s.expires_at,
           s.max_downloads,
           s.download_count,
           s.revoked_at,
           s.expires_at <= now() AS is_expired,
           u.username      AS issued_by,
           f.id            AS file_id,
           f.original_name,
           f.stored_name,
           f.mime,
           f.access_level,
           f.sha256,
           f.scan_status,
           f.deleted_at,
           f.purged_at
      FROM share_links s
      JOIN files f ON f.id = s.file_id
      JOIN users u ON u.id = s.created_by
     WHERE s.token_hash = :token_hash
       FOR UPDATE OF s
    SQL;

$db->begin();

$link = $db->run($sqlFind, [':token_hash' => hash('sha256', $shareToken)])->fetch();

// ─── ① 없는 링크 ──────────────────────────────────────────────
// 대상 파일을 모르므로 target 은 system 이다. 토큰은 기록하지 않는다 —
// 로그가 "시도된 토큰 목록" 이 되면 로그를 읽을 수 있는 사람에게 재료를 주는 셈이다.
if (!is_array($link)) {
    $rejectShare('not_found', $systemTarget, []);
}

$shareId = (int) $link['share_id'];
$target  = [
    'target_type'  => 'file',
    'target_id'    => (int) $link['file_id'],
    'target_name'  => (string) $link['original_name'],
    'access_level' => (string) $link['access_level'],
];
$detail = [
    'share_id'      => $shareId,
    'issued_by'     => (string) $link['issued_by'],
    'max_downloads' => (int) $link['max_downloads'],
];

// ─── ② 회수됐나 ───────────────────────────────────────────────
if ($link['revoked_at'] !== null) {
    $rejectShare('revoked', $target, $detail);
}

// ─── ③ 만료됐나 (판단은 DB 시계로 — 앱 시계가 어긋나도 결과가 흔들리지 않게) ───
if ($link['is_expired'] === true) {
    $rejectShare('expired', $target, $detail + ['expires_at' => (string) $link['expires_at']]);
}

// ─── ④ 횟수를 다 썼나 ─────────────────────────────────────────
$used = (int) $link['download_count'];
$max  = (int) $link['max_downloads'];
if ($used >= $max) {
    $rejectShare('exhausted', $target, $detail + ['remaining' => 0]);
}

// ─── ⑤ 파일이 아직 살아 있나 (논리 삭제·영구 삭제) ────────────
if ($link['deleted_at'] !== null || $link['purged_at'] !== null) {
    $rejectShare('file_gone', $target, $detail);
}

/* ─── ⑤-1 F-54 검사 상태 관문 (ARCH 5.28, PRD F-54 ③) ──────────
 *
 * 검사 전(`pending`)·격리(`infected`) 파일은 **링크가 유효해도** 나가지 않는다.
 * 자리는 **링크 검증을 전부 통과한 뒤**다(권한을 대신하는 것이 토큰이므로, 이 창구에서
 * "권한 판정 뒤" 란 곧 "링크 판정 뒤" 다 — ARCH 5.3 [4] 와 같은 순서).
 *
 * ★ 이 하나만 404 가 아니라 **403 + 사유 문구**다(PRD F-54 ③). 다른 실패를 404 로 통일한
 *   이유는 토큰 탐색에 재료를 주지 않기 위해서인데, 여기까지 온 요청은 **이미 맞는 토큰**을
 *   가진 요청이라 감출 것이 없다. 반대로 「검사 중」과 「격리」를 구분해 주지 않으면 받는
 *   사람은 링크가 망가진 줄 알고 발급자에게 다시 요청하게 된다.
 * ★ 횟수(`download_count`)는 **아직 올리지 않았다** — 보내지도 못할 파일로 남은 한 번을
 *   태우지 않는다(⑤-2 sha256 대조와 같은 판단).
 */
$scan = (string) ($link['scan_status'] ?? 'clean');
if ($scan !== 'clean') {
    $db->rollback();
    $scanReason = $scan === 'infected' ? 'scan_infected' : 'scan_pending';
    Audit::deny('share.download', $scanReason, $target + [
        'detail' => $detail + ['scan_status' => $scan],
    ]);
    Http::error(403, $scan === 'infected' ? Http::MSG_SCAN_INFECTED : Http::MSG_SCAN_PENDING);
}

$storedName = (string) $link['stored_name'];

/*
 * ⑤-2 실제 바이트 확인 + sha256 대조 — **횟수를 올리기 전에** 한다.
 * 보내지도 못할 파일로 남은 한 번을 태워 버리지 않게 하려는 것이고,
 * 검사 자체는 files_download.php 와 같은 코드 경로다 (ARCH 5.3 [6], SECURITY R-13).
 * 두 실패 모두 응답은 다른 실패와 같은 404 이며, 기록만 error 로 남아 점검 대상이 된다(AL-6).
 */
try {
    Storage::assertExists($storedName);
} catch (Throwable $e) {
    $db->rollback();
    Audit::error('share.download', 'file_missing', $target + ['detail' => $detail]);
    Http::error(404, Http::MSG_NOT_FOUND);
}

if (!hash_equals((string) $link['sha256'], Storage::sha256($storedName))) {
    $db->rollback();
    Audit::error('share.download', 'hash_mismatch', $target + ['detail' => $detail]);
    Http::error(404, Http::MSG_NOT_FOUND);
}

/*
 * ⑥ 조건부 UPDATE — 이 문장이 **1행을 바꿨을 때만** 진행한다.
 * `download_count < max_downloads` 를 WHERE 에 두었으므로, 마지막 한 번을 두 요청이
 * 동시에 노려도 하나만 성공한다(앞의 ④ 검사는 빠른 거부일 뿐이고, 진짜 관문은 여기다).
 */
$sqlConsume = <<<'SQL'
    UPDATE share_links
       SET download_count = download_count + 1
     WHERE id = :id
       AND download_count < max_downloads
       AND revoked_at IS NULL
       AND expires_at > now()
    SQL;

$affected = $db->run($sqlConsume, [':id' => $shareId])->rowCount();
if ($affected !== 1) {
    $rejectShare('exhausted', $target, $detail + ['remaining' => 0]);
}

$remaining = $max - ($used + 1);

Audit::allow('share.download', $target + [
    'detail' => [
        'share_id'      => $shareId,
        'issued_by'     => (string) $link['issued_by'],
        'remaining'     => $remaining,
        'max_downloads' => $max,
        'sha256'        => (string) $link['sha256'],
    ],
]);

$db->commit();

// ⑦ 전송 — 다운로드(F-06)와 **같은 함수**다. 항상 application/octet-stream + attachment 이므로
//    업로드된 HTML·SVG 가 이 경로로 실행되는 일이 없다 (PRD S-03).
Storage::stream($storedName, (string) $link['original_name'], (string) $link['mime']);
