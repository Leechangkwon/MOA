<?php
declare(strict_types=1);

/**
 * src/community/handlers/templates_delete.php — DELETE /community/api/templates/{id} (정책: community.write)
 *
 * 요구사항: `C:F-31` · 결정 J-3. 지우기는 **논리 삭제**(`deleted_at`)다 — 이미 그 템플릿으로 만든 페이지는
 * 그대로다(템플릿은 사본을 찍는 틀이지 연결이 아니다). 누가 지울 수 있나는 `decideTemplateDelete`:
 * 개인은 만든 사람, 팀은 만든 사람 · Admin, 사이트 기본은 Admin, DB 행 템플릿은 만든 사람 · 그 DB 의 관리 자격.
 *   볼 수도 없는 템플릿 → 403 `template.read deny` · 보이지만 지울 수 없다 → 403 `template.delete deny`.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] 템플릿 public_id
 */

$tpl = community_template_row((string) $params[0]);
if ($tpl === null || $tpl['deleted_at'] !== null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$me     = (int) $user['id'];
$dbPage = community_template_db_page($tpl, $me);

[$mayDel, $delCode] = CommunityAuthz::decideTemplateDelete($user, $tpl, $dbPage);
if (!$mayDel) {
    [$mayRead, $readCode] = CommunityAuthz::decideTemplateRead($user, $tpl, $dbPage);
    if (!$mayRead) {
        if ($readCode === CommunityAuthz::CODE_NOT_FOUND) {
            Http::error(404, Http::MSG_NOT_FOUND);
        }
        community_template_refuse($tpl, $readCode);
    }
    community_template_refuse($tpl, $delCode, 'template.delete');
}

$sql = <<<'SQL'
    UPDATE community.templates
       SET deleted_at = now(), deleted_by = CAST(:me AS bigint)
     WHERE id = CAST(:id AS bigint) AND deleted_at IS NULL
    SQL;
$db = Db::instance();
$db->begin();
try {
    $n = $db->run($sql, [':me' => $me, ':id' => (int) $tpl['id']])->rowCount();
    if ($n !== 1) {
        $db->rollback();
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    $name = community_target_title((string) $tpl['name']);
    Audit::allow('template.delete', [
        'target_type' => 'template',
        'target_id'   => (int) $tpl['id'],
        'target_name' => $name,
        'detail'      => ['template_name' => $name, 'scope' => (string) $tpl['scope'], 'granted_by' => $delCode],
    ]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}
Http::json(['deleted' => true]);
