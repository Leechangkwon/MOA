<?php
declare(strict_types=1);

/**
 * src/handlers/me_settings.php — PATCH /api/me/settings (정책: authenticated + CSRF) · 커뮤니티 7-D3 고대비 화면
 *
 * 요구사항: 커뮤니티 stage7d-palette-contrast-mention-directive.md 2장 H-1 · H-2 · 2.3 (결정 J-5 · L-3),
 *           t-box PRD F-20(내 계정) · S-01 · S-03 · S-07, DESIGN.md 2.16
 *
 * 받는 값: `{ "high_contrast": true|false }` — **이 한 칸뿐**이다.
 *   다른 키 · 둘 이상의 키 · 빈 본문 · JSON 불린이 아닌 값(`"true"`·`1`·null) → 400 동일 문구(조용한 값 변환 0).
 * 돌려주는 값: `{ "high_contrast": bool }` — 저장된 값(UPDATE … RETURNING)이다. 화면은 200 을 받은 뒤에만
 *   `<html>` 에 `data-contrast` 를 놓거나 뗀다(app.js initSettings).
 *
 * ★ 대상은 언제나 **세션의 본인**이다 — 조건 `WHERE id = 세션 사용자` 가 UPDATE 문 안에 있고, 본문에는 대상 계정 칸이
 *   없다(있으면 모르는 키라 400). 남의 값을 바꾸는 모양의 요청이 생길 자리가 없다(PRD S-01 · 8장 `/api/me/*` 행).
 *   「역할로 보기」 중에도 `$user['id']` 는 실제 사람이다(Auth::currentUser 는 역할·팀만 바꾼다).
 * ★ **권한이 아니다** — 화면 취향 한 칸이고, 어떤 판정(Authz)도 이 값을 읽지 않는다. 그래서 새 정책 이름도 없다.
 * ★ 토큰 창구 집합 밖이다(public/index.php) — 프로그램 토큰으로 부르면 세션이 없어 401.
 * ★ 감사: 성공 한 행 `me.settings|allow` detail `{high_contrast: bool}`(S-07 — 계정 행을 바꾸는 쓰기는 남긴다).
 *   형식이 틀린 400 은 기록하지 않는다 — 아무것도 바뀌지 않았고 대상이 본인뿐이다(문장 표에 행 하나 — 지시서 H-2).
 *   `updated_at` 은 밀지 않는다 — 그 칸은 역할·활성·비밀번호·2단계가 마지막으로 바뀐 시각이다(db/schema.sql 머리글).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (대상은 언제나 본인)
 * @var list<string>        $params 경로 변수 없음
 */

$body = Http::jsonBody();
if (array_keys($body) !== ['high_contrast'] || !is_bool($body['high_contrast'])) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$want = $body['high_contrast'];
unset($body);

$userId = (int) $user['id'];
$sql = <<<'SQL'
    UPDATE users
       SET high_contrast = CAST(:v AS boolean)
     WHERE id = :id
    RETURNING high_contrast
    SQL;

$db = Db::instance();
$db->begin();
try {
    // 값은 글자 'true'|'false' 로 넘기고 SQL 이 CAST 한다(PDO 는 PHP false 를 빈 글자로 보낸다 — fetch_hosts_update 와 같은 방식)
    $row = $db->run($sql, [':v' => $want ? 'true' : 'false', ':id' => $userId])->fetch();
    if (!is_array($row)) {
        // 세션이 살아 있는데 계정 행이 없다 — 일어나지 않아야 하는 일이다. 기록 없이 성공하지 않는다.
        throw new RuntimeException('me.settings: 세션 사용자의 계정 행이 없다');
    }
    $saved = $row['high_contrast'] === true;
    Audit::allow('me.settings', [
        'target_type' => 'user',
        'target_id'   => $userId,
        'target_name' => (string) $user['username'],
        'detail'      => ['high_contrast' => $saved],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

Http::json(['high_contrast' => $saved]);
