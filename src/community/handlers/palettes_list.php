<?php
declare(strict_types=1);

/**
 * src/community/handlers/palettes_list.php — GET /community/api/palettes (정책: community.read)
 *
 * 요구사항: `C:F-33` · 지시서 7-D2 P-11 · P-14 · P-17. 팔레트는 **비밀이 아니다** — 편집기가 고를 수 있는 이름을 알아야 하고,
 * 값은 CSS 창구(`/palette.css`)가 이미 로그인한 누구에게나 준다(7-C 임베드 목록과 같은 까닭). 누가·언제 넣었는지는 관리자에게만.
 *   응답 `{palettes:[{name, light, dark}], limit, version, can_manage}` — 관리자면 줄마다 `id` · `added_at` · `added_by` · `updated_at` 을 더한다.
 *   `version` 은 셸의 `<link id="palette-css" … ?v=>` 와 같은 값이다 — 관리 창이 이 값으로 CSS 를 다시 읽는다(P-7).
 * 표를 읽지 못하면 빈 목록(P-17 — 편집기는 기본 9색만).
 *
 * ★ 역할 비교 없음 — `can_manage` 는 `Authz::canAdmin`(화면 편의 값 · 막는 것은 라우트 정책 `role:Admin`). 감사 없음(목록 조회).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 */

$canManage = Authz::canAdmin($user);
$rows      = community_palette_rows();

$meta = [];
if ($canManage && $rows !== []) {
    $sql = <<<'SQL'
        SELECT p.id, p.name, p.added_at, p.updated_at, u.username AS added_by_name, u.display_name AS added_by_display_name
          FROM community.palettes p
          JOIN public.users u ON u.id = p.added_by
         WHERE p.removed_at IS NULL
        SQL;
    try {
        foreach (Db::instance()->run($sql)->fetchAll() as $r) {
            $meta[(string) $r['name']] = $r;
        }
    } catch (Throwable $e) {
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' community palettes unreadable (관리 칸) — 관리 칸 없이');
        $meta = [];
    }
}

$out = [];
foreach ($rows as $r) {
    $one = ['name' => $r['name'], 'light' => $r['light'], 'dark' => $r['dark']];
    if ($canManage && isset($meta[$r['name']])) {
        $m = $meta[$r['name']];
        $one['id']         = (int) $m['id'];
        $one['added_at']   = (string) $m['added_at'];
        $one['added_by']   = ['username' => (string) $m['added_by_name'],
                              'display_name' => $m['added_by_display_name'] === null ? null : (string) $m['added_by_display_name']];
        $one['updated_at'] = $m['updated_at'] === null ? null : (string) $m['updated_at'];
    }
    $out[] = $one;
}

Http::json([
    'palettes'   => $out,
    'limit'      => Palette::MAX_LIVE,
    'version'    => Palette::version($rows),
    'can_manage' => $canManage,
]);
