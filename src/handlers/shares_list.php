<?php
declare(strict_types=1);

/**
 * src/handlers/shares_list.php — GET /api/me/shares (정책: authenticated)
 *
 * ★ 15-2: 내가 만든 공유 링크 목록 (PRD F-13, 8장 API 표, ARCH 5.12 「회수·목록」).
 *
 * 지키는 것
 *   · **대상은 언제나 세션 본인**이다 — 조건절의 `:me` 에 라우터가 넘긴 $user 의 id 만 들어간다.
 *     요청에서 대상을 받지 않으므로 주소에 무엇을 붙여도 남의 링크가 나오지 않는다 (PRD S-02).
 *     Admin 이라도 이 창구에서는 **자기 링크만** 본다 — "남의 링크를 훑는" 화면이 아니다
 *     (Admin 의 회수 권한은 링크 id 를 이미 아는 경우에만 쓰인다, decideShareRevoke).
 *   · `token_hash` 는 SELECT 자체에 넣지 않는다. 토큰 원문은 DB 에 아예 없다 (ARCH 5.12).
 *     응답에 없으면 새지 않는다 — 내려 주는 것은 "무엇을·언제까지·몇 번" 뿐이다.
 *   · 목록 조회는 감사 로그를 남기지 않는다 (파일 목록과 같은 원칙, ARCH 5.1).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (요청 파라미터가 아니다)
 * @var list<string>        $params 경로 변수 없음
 */

/*
 * 최근 100건까지만 내려 준다 — 「내 공유 링크」는 회수하려고 보는 화면이라 페이지가 필요 없고,
 * 상한이 없으면 응답 크기를 요청자가 정하게 된다.
 */
$sqlMine = <<<'SQL'
    SELECT s.id,
           s.expires_at,
           s.max_downloads,
           s.download_count,
           s.revoked_at,
           s.created_at,
           s.reason_text,
           s.expires_at <= now() AS is_expired,
           f.original_name
      FROM share_links s
      JOIN files f ON f.id = s.file_id
     WHERE s.created_by = :me
     ORDER BY s.id DESC
     LIMIT 100
    SQL;

$rows = Db::instance()->run($sqlMine, [':me' => (int) $user['id']])->fetchAll();

$links = [];
foreach ($rows as $row) {
    $links[] = [
        'id'             => (int) $row['id'],
        'file_name'      => (string) $row['original_name'],
        'expires_at'     => (string) $row['expires_at'],
        'max_downloads'  => (int) $row['max_downloads'],
        'download_count' => (int) $row['download_count'],
        'revoked_at'     => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
        'created_at'     => (string) $row['created_at'],
        'reason_text'    => $row['reason_text'] === null ? null : (string) $row['reason_text'],
        // 화면이 시각을 직접 비교하지 않게 서버가 판정해 준다(표시용 값이며 권한이 아니다).
        'is_expired'     => $row['is_expired'] === true,
    ];
}

Http::json(['links' => $links]);
