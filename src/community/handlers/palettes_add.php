<?php
declare(strict_types=1);

/**
 * src/community/handlers/palettes_add.php — POST /community/api/palettes (정책: role:Admin — 재인증 관문이 따라붙는다)
 *
 * 요구사항: `C:F-33` · 지시서 7-D2 P-1~P-4 · P-13 · P-16 (7-C `embed_domains_add.php` 와 같은 모양).
 *   받는 값 `{name, light, dark}` — 키는 **정확히** 이 셋(다른 키·빠진 키·글자가 아닌 값은 400 `Http::MSG_BAD_REQUEST`).
 *   이름: 바꾸지 않고 본다 — `[a-z0-9-]{1,20}` · 끝 `-bg` 아님(`Doc::isPaletteName`) 아니면 400 `bad_name` ·
 *         기본 9색 이름·`none` 은 400 `reserved`(`Palette::reserved` — 목록은 doc-schema.json 에서 읽는다).
 *   값: 앞뒤 빈칸을 떼고 소문자로 맞춘 뒤 `#rrggbb`(`Palette::normColor`) 아니면 400 `bad_color` — `#0055AA` 는 `#0055aa` 로 저장.
 *   살아 있는 줄 12개까지(400 `too_many_palettes`) · 같은 이름이 살아 있으면 400 `exists` — 둘 다 advisory lock 트랜잭션 안에서 센다(P-16).
 *   뺀 뒤 같은 이름은 **새 줄**(옛 줄의 removed_at 은 남는다 — P-1). 감사 `palette.add allow`(detail name·light·dark).
 *   응답 201 `{palette:{id, name, light, dark, added_at}, version}` — 관리 창이 `version` 으로 CSS 를 다시 읽는다(P-7).
 *   표가 없거나 앱 계정이 못 읽는 창(반영 순서가 어긋남)은 500 이 아니라 503 `palette_unavailable`(감사 0 — `community_palette_unavailable`).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$body = Http::jsonBody();
$keys = array_keys($body);
sort($keys);
if ($keys !== ['dark', 'light', 'name']
    || !is_string($body['name']) || !is_string($body['light']) || !is_string($body['dark'])) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$name    = $body['name'];
$problem = Palette::nameProblem($name);
if ($problem === 'bad_name') {
    Http::json(['error' => '이름은 영어 소문자·숫자·붙임표(-)로 1~20자입니다 — 예: brand-blue (끝을 -bg 로 하지 않습니다)', 'reason' => 'bad_name'], 400);
    exit;
}
if ($problem === 'reserved') {
    Http::json(['error' => '기본 색 이름(' . implode(' · ', array_slice(Palette::reserved(), 0, -1)) . ')과 none 은 쓸 수 없습니다', 'reason' => 'reserved'], 400);
    exit;
}
$light = Palette::normColor($body['light']);
$dark  = Palette::normColor($body['dark']);
if ($light === null || $dark === null) {
    Http::json(['error' => '색은 #rrggbb 모양으로 넣습니다 — 예: #0055aa', 'reason' => 'bad_color'], 400);
    exit;
}

$sqlLock = <<<'SQL'
    SELECT pg_advisory_xact_lock(hashtext('community.palettes'))
    SQL;
$sqlCount = <<<'SQL'
    SELECT count(*) AS n, count(*) FILTER (WHERE p.name = :name) AS same
      FROM community.palettes p WHERE p.removed_at IS NULL
    SQL;
$sqlInsert = <<<'SQL'
    INSERT INTO community.palettes (name, light, dark, added_by)
    VALUES (:name, :light, :dark, CAST(:me AS bigint))
    RETURNING id, added_at
    SQL;

$db = Db::instance();
$auditing = false;   // 감사 쓰기 전 문장의 예외만 503 후보(아래 catch)
$db->begin();
try {
    $db->run($sqlLock);
    $c = $db->run($sqlCount, [':name' => $name])->fetch();
    if ((int) ($c['same'] ?? 0) > 0) {
        $db->rollback();
        Http::json(['error' => '같은 이름의 색이 이미 있습니다 — 값을 바꾸려면 그 줄의 「고치기」를 씁니다', 'reason' => 'exists'], 400);
        exit;
    }
    if ((int) ($c['n'] ?? 0) >= Palette::MAX_LIVE) {
        $db->rollback();
        Http::json(['error' => '팔레트는 ' . Palette::MAX_LIVE . '개까지입니다 — 하나를 뺀 뒤 더합니다', 'reason' => 'too_many_palettes'], 400);
        exit;
    }
    $new = $db->run($sqlInsert, [':name' => $name, ':light' => $light, ':dark' => $dark, ':me' => (int) $user['id']])->fetch();
    $auditing = true;
    Audit::allow('palette.add', [
        'target_type' => 'palette',
        'target_id'   => (int) $new['id'],
        'target_name' => $name,
        'detail'      => ['name' => $name, 'light' => $light, 'dark' => $dark],
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
    'palette' => ['id' => (int) $new['id'], 'name' => $name, 'light' => $light, 'dark' => $dark, 'added_at' => (string) $new['added_at']],
    'version' => community_palette_version(true),
], 201);
