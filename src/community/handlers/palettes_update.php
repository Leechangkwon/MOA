<?php
declare(strict_types=1);

/**
 * src/community/handlers/palettes_update.php — PATCH /community/api/palettes/{num} (정책: role:Admin — 재인증)
 *
 * 요구사항: `C:F-33` · 지시서 7-D2 1.3 · P-3 · P-13.
 *   받는 값 `{light?, dark?}` — 키는 이 둘 중 하나 이상, 그 밖의 키는 400 `Http::MSG_BAD_REQUEST`.
 *   ★ 이름은 고칠 수 없다(`name` 이 오면 400) — 이름을 바꾸면 그 이름을 쓴 옛 글의 색이 소리 없이 사라진다.
 *   값은 등록과 같은 규칙(`Palette::normColor` — 아니면 400 `bad_color`). 없는 줄·이미 뺀 줄은 **같은 404**(`Http::MSG_NOT_FOUND`).
 *   감사 `palette.update allow`(detail name·light·dark + before{light, dark}). 응답 `{palette:{id, name, light, dark, updated_at}, version}`.
 *   표가 없거나 앱 계정이 못 읽는 창(반영 순서가 어긋남)은 500 이 아니라 503 `palette_unavailable`(감사 0 — `community_palette_unavailable`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] 줄 번호
 */

$body = Http::jsonBody();
if ($body === [] || array_diff(array_keys($body), ['light', 'dark']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$next = [];
foreach (['light', 'dark'] as $k) {
    if (!array_key_exists($k, $body)) {
        continue;
    }
    if (!is_string($body[$k])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $v = Palette::normColor($body[$k]);
    if ($v === null) {
        Http::json(['error' => '색은 #rrggbb 모양으로 넣습니다 — 예: #0055aa', 'reason' => 'bad_color'], 400);
        exit;
    }
    $next[$k] = $v;
}

$sqlRow = <<<'SQL'
    SELECT p.id, p.name, p.light, p.dark
      FROM community.palettes p
     WHERE p.id = CAST(:id AS bigint) AND p.removed_at IS NULL
       FOR UPDATE
    SQL;
$sqlUpdate = <<<'SQL'
    UPDATE community.palettes
       SET light = :light, dark = :dark, updated_at = now(), updated_by = CAST(:me AS bigint)
     WHERE id = CAST(:id AS bigint) AND removed_at IS NULL
    RETURNING updated_at
    SQL;

$db = Db::instance();
$auditing = false;   // 감사 쓰기 전 문장의 예외만 503 후보(아래 catch)
$db->begin();
try {
    $row = $db->run($sqlRow, [':id' => (int) $params[0]])->fetch();
    if (!is_array($row)) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $light = $next['light'] ?? (string) $row['light'];
    $dark  = $next['dark'] ?? (string) $row['dark'];
    $done  = $db->run($sqlUpdate, [':light' => $light, ':dark' => $dark, ':me' => (int) $user['id'], ':id' => (int) $row['id']])->fetch();
    $auditing = true;
    Audit::allow('palette.update', [
        'target_type' => 'palette',
        'target_id'   => (int) $row['id'],
        'target_name' => (string) $row['name'],
        'detail'      => [
            'name'   => (string) $row['name'],
            'light'  => $light,
            'dark'   => $dark,
            'before' => ['light' => (string) $row['light'], 'dark' => (string) $row['dark']],
        ],
    ]);
    $db->commit();
} catch (PDOException $e) {
    $db->rollback();
    if (!$auditing) {
        community_palette_unavailable($e);   // 표 없음·권한 없음이면 503 으로 닫고 끝난다 — 그 밖은 아래에서 그대로 던진다
    }
    throw $e;
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json([
    'palette' => ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'light' => $light, 'dark' => $dark,
                  'updated_at' => (string) $done['updated_at']],
    'version' => community_palette_version(true),
]);
