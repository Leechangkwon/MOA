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
 * ★ 링크는 **만든 사람의 권한을 빌려 주는 것**이다. 전에는 그 판단을 만들 때(share_create 의
 *   decideRead → decideEdit) 한 번만 했고, 그 뒤 파일 등급을 올리거나 만든 사람을 끄더라도 링크가
 *   200 이었다(2026-09-26 보안 점검 중간 2). 디버깅 묶음 S-2 부터는 **받을 때마다** ⑤-A 에서
 *   ① 만든 사람이 지금도 활성(`is_active` · 만료 전)인가 ② `Authz::decideRead(만든 사람, 파일)` ③ `Authz::decideEdit(만든 사람, 파일)`
 *   (만들 때와 같은 두 판정 — 검토 L-2) — 판정 코드는 새로 쓰지 않고 기존 함수를 부른다 — 를 다시 본다. 안 되면 다른 실패와
 *   **같은 404** + 감사 `share.download deny issuer_inactive|role_not_permitted|team_mismatch|not_owner`. 되돌리면(등급을 내리거나
 *   계정을 다시 켜면) 그 링크는 다시 된다 — 링크 행을 바꾸지 않는다(회수가 아니다).
 *   `admin-only` 파일의 링크는 여전히 Admin 만 만들 수 있다 — decideEdit 가 그렇게 귀결된다.
 *
 * 순서 (ARCH 5.12 ①~⑦) — 한 트랜잭션 안에서
 *   ① sha256 로 행 조회 (`FOR UPDATE`: 같은 링크의 동시 요청을 줄 세운다)
 *   ② revoked_at IS NULL          아니면 revoked
 *   ③ expires_at > now()          아니면 expired
 *   ④ download_count < max        아니면 exhausted
 *   ⑤ 파일 deleted_at·purged_at IS NULL   아니면 file_gone
 *   ⑤-A 만든 사람이 지금도 활성이고 그 파일을 볼 수 있나 (S-2)   아니면 issuer_inactive · role_not_permitted · team_mismatch
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
           -- S-2 — 만든 사람의 **지금** 역할·팀·활성(만료 판정은 DB 시계로 — Auth::SQL_LOAD_USER 와 같은 식)
           u.id            AS issuer_id,
           u.role          AS issuer_role,
           u.team_id       AS issuer_team_id,
           u.is_active     AS issuer_active,
           (u.expires_at IS NOT NULL AND u.expires_at < now()) AS issuer_expired,
           f.id            AS file_id,
           f.original_name,
           f.stored_name,
           f.mime,
           f.access_level,
           f.team_id,
           f.uploaded_by,
           f.sha256,
           f.scan_status,
           f.exec_content,
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

/* ─── ⑤-A 만든 사람의 권한을 **지금** 다시 본다 (디버깅 묶음 S-2, ARCH 5.12 「받기」 ⑤-A) ───
 *
 * 링크는 만든 사람의 권한을 빌려 주는 것이다. 그 권한이 사라졌으면 링크도 더는 빌려 줄 것이 없다:
 *   ① 만든 사람이 꺼졌거나(`is_active=false`) 만료됐으면(`expires_at < now()`, DB 시계) → issuer_inactive
 *   ② `Authz::decideRead(만든 사람, 파일)` 이 거부하면(등급을 올렸거나 팀이 바뀜) → 그 판정 코드 그대로
 * 판정은 **기존 함수**다 — 역할·등급·팀 비교를 이 파일에 새로 쓰지 않는다(PRD S-01, ARCH 4.1). 배열에는
 * decideRead 가 읽는 칸(`role`·`team_id` / `access_level`·`team_id`)만 담는다.
 * 응답은 다른 실패와 같은 **404 통일 문구**다 — 받는 사람은 로그인하지 않은 사람이고, 「만든 사람이 권한을
 * 잃었다」는 사실도 그 사람에게 줄 정보가 아니다. 사유는 감사에만 남는다.
 * 자리: 링크 판정(②~⑤) 뒤 · 횟수 소비(⑥) 앞 — 못 보낼 파일로 남은 한 번을 태우지 않는다.
 * 링크 행은 바꾸지 않는다(회수가 아니다) — 등급을 되돌리거나 계정을 다시 켜면 같은 링크가 다시 된다.
 */
if ($link['issuer_active'] !== true || $link['issuer_expired'] === true) {
    $rejectShare('issuer_inactive', $target, $detail);
}
[$issuerMayRead, $issuerReadCode] = Authz::decideRead(
    [
        'role'    => (string) $link['issuer_role'],
        'team_id' => $link['issuer_team_id'] === null ? null : (int) $link['issuer_team_id'],
    ],
    [
        'access_level' => (string) $link['access_level'],
        'team_id'      => $link['team_id'] === null ? null : (int) $link['team_id'],
    ]
);
if (!$issuerMayRead) {
    $rejectShare($issuerReadCode, $target, $detail);
}
// 검토 L-2 — 만들 때의 조건(share_create 의 decideRead → **decideEdit**)과 같게, 고칠 수 있는 사람인지도 다시 본다.
// 만든 사람의 역할·팀이 바뀌어 그 파일을 더는 고칠 수 없으면(예: Manager 가 다른 팀으로) 링크도 끝난다. 코드는 decideEdit 그대로
// (`not_owner`·`team_mismatch`·`role_not_permitted`).
[$issuerMayEdit, $issuerEditCode] = Authz::decideEdit(
    [
        'id'      => (int) $link['issuer_id'],
        'role'    => (string) $link['issuer_role'],
        'team_id' => $link['issuer_team_id'] === null ? null : (int) $link['issuer_team_id'],
    ],
    [
        'uploaded_by' => (int) $link['uploaded_by'],
        'team_id'     => $link['team_id'] === null ? null : (int) $link['team_id'],
    ]
);
if (!$issuerMayEdit) {
    $rejectShare($issuerEditCode, $target, $detail);
}

/* ─── ⑤-0 F-65 실행 파일이 든 압축본 (ARCH 5.29 「받기」 표, **겹 방어**) ─────
 *
 * 이 갈래에는 닿을 일이 없다 — `share_create.php` 가 그런 파일의 링크를 만들지 못하게
 * 막고, `exec_content` 는 업로드 트랜잭션에서만 참이 되며 다시 거짓이 되지 않는다.
 * 그래도 검사 순서에 한 줄을 둔다: 링크를 만드는 쪽의 규칙이 언젠가 바뀌더라도
 * **받는 쪽이 스스로 성립**해야 한다(다운로드·미리보기 핸들러가 라우트 표와 무관하게
 * UUID 형태를 다시 보는 것과 같은 이유).
 *
 * 응답은 다른 실패와 같은 **404 통일 문구**다 (ARCH 5.29 「받기」 표).
 *
 * 감사는 `share.download deny exec_content` 로 남는다 (ARCH 7.4, 2026-09-21 Fable 추가).
 * 다른 실패와 **같은 `$rejectShare`** 를 쓰므로 「조회 잠금 해제(rollback) → 기록 → 404」
 * 순서가 한 곳에서 지켜진다. 받는 쪽에는 로그인한 사람이 없어 actor 가 비고(문장 틀에
 * {actor} 가 없는 이유), 「누가 열어 줬나」는 detail 의 `issued_by`·`share_id` 가 답한다
 * (ARCH 5.12 · 7.5).
 */
if ($link['exec_content'] === true) {
    $rejectShare('exec_content', $target, $detail);
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
    // F-57: 격리(`infected`)는 **영구** 거부라 403, 검사 중(`pending`)은 곧 풀리는
    // **일시** 상태라 423 + `Retry-After`. 다섯 창구(F-65 exec-download 포함)가 이 두 줄로 똑같다 (ARCH 5.28).
    if ($scan === 'infected') {
        Http::error(403, Http::MSG_SCAN_INFECTED);
    }
    Http::locked(Http::SCAN_RETRY_AFTER_SECONDS, Http::MSG_SCAN_PENDING);
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
