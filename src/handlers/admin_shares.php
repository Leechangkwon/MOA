<?php
declare(strict_types=1);

/**
 * src/handlers/admin_shares.php — GET /api/admin/shares
 *                                 (정책: role:Admin + 재인증, PRD F-24)
 *
 * 요구사항: PRD F-24 · 8장 `GET /api/admin/shares` 행 · 10장 「관리자 허브」, ARCH 5.14 · 6.2-3
 *
 * **전체** 공유 링크 목록이다. 「내 공유 링크」(`GET /api/me/shares`)가 세션 본인 것만
 * 보여 주는 것과 달리, 이 창구는 관리자가 "지금 어떤 링크가 밖으로 나가 있나" 를 한눈에
 * 보기 위한 것이다 (PRD F-24 허브 카드 「공유 링크 전체」).
 *
 * ★ `token_hash` 는 SELECT 자체에 넣지 않는다. 토큰 원문은 DB 에 아예 없고(ARCH 5.12),
 *   해시도 내보내지 않는다 — 응답에 없으면 새지 않는다. 내려 주는 것은 "무엇을·누가·언제까지·
 *   몇 번·지금 상태" 뿐이다.
 * ★ 상태(`status`)는 **서버가 판정해서** 내려 준다 — 화면이 시각을 직접 비교해 "만료됐다"
 *   를 스스로 정하지 않게 한다 (shares_list.php 의 `is_expired` 와 같은 판단).
 * ★ 목록 조회는 감사 로그를 남기지 않는다 (파일 목록·내 링크 목록과 같은 원칙, ARCH 5.1).
 *   이 창구에 닿았다는 사실 자체가 `role:Admin` 정책과 재인증 관문을 통과했다는 뜻이다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/*
 * 최근 200건까지만 내려 준다 — 허브의 "지금 밖에 나가 있는 링크" 를 보는 화면이라 페이지가
 * 필요 없고, 상한이 없으면 응답 크기를 요청자가 정하게 된다(내 링크 목록과 같은 판단).
 * 발급자 계정이 지워지는 일은 없으므로(계정도 논리 비활성화) JOIN 은 INNER 로 충분하다.
 */
$sqlShares = <<<'SQL'
    SELECT s.id,
           s.expires_at,
           s.max_downloads,
           s.download_count,
           s.revoked_at,
           s.created_at,
           s.reason_text,
           s.expires_at <= now() AS is_expired,
           f.original_name,
           u.username AS issued_by
      FROM share_links s
      JOIN files f ON f.id = s.file_id
      JOIN users u ON u.id = s.created_by
     ORDER BY s.id DESC
     LIMIT 200
    SQL;

$rows  = Db::instance()->run($sqlShares)->fetchAll();
$links = [];
foreach ($rows as $row) {
    $revoked   = $row['revoked_at'] !== null;
    $expired   = $row['is_expired'] === true;
    $exhausted = (int) $row['download_count'] >= (int) $row['max_downloads'];

    // 판정 순서는 share_download.php 의 거부 순서와 같다: 회수 → 만료 → 횟수 소진.
    if ($revoked) {
        $status = 'revoked';
    } elseif ($expired) {
        $status = 'expired';
    } elseif ($exhausted) {
        $status = 'exhausted';
    } else {
        $status = 'active';
    }

    $links[] = [
        'id'             => (int) $row['id'],
        'file_name'      => (string) $row['original_name'],
        'issued_by'      => (string) $row['issued_by'],
        'expires_at'     => (string) $row['expires_at'],
        'max_downloads'  => (int) $row['max_downloads'],
        'download_count' => (int) $row['download_count'],
        'revoked_at'     => $revoked ? (string) $row['revoked_at'] : null,
        'created_at'     => (string) $row['created_at'],
        'reason_text'    => $row['reason_text'] === null ? null : (string) $row['reason_text'],
        'status'         => $status,
    ];
}

Http::json(['links' => $links]);
