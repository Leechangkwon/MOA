<?php
declare(strict_types=1);

/**
 * src/community/handlers/templates_get.php — GET /community/api/templates/{id} (정책: community.read)
 *
 * 요구사항: `C:F-31` · 결정 J-3. 미리보기용 — 본문(`doc`)과 DB 행 템플릿이면 속성 값까지 준다.
 *   없는 id·지운 템플릿 → 404, 있지만 볼 수 없다(남의 개인 템플릿·다른 팀) → **403 + 감사** `template.read deny`.
 *   「있다」를 알려 주는 403 인 이유: id 는 UUID 라 떠볼 수 없고(열거 방지), 남의 템플릿에 닿으려 한 일은
 *   `pages_get.php` 처럼 기록으로 남아야 한다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] 템플릿 public_id
 */

$tpl = community_template_row((string) $params[0]);
if ($tpl === null || $tpl['deleted_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$dbPage = community_template_db_page($tpl, (int) $user['id']);
[$may, $code] = CommunityAuthz::decideTemplateRead($user, $tpl, $dbPage);
if (!$may) {
    if ($code === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    community_template_refuse($tpl, $code);
}

Http::json(['template' => community_template_out($tpl, $user, $dbPage, true)]);
