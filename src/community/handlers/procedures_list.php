<?php
declare(strict_types=1);
// C:F-39: ordinary search visibility, including hidden-post exclusions; no administrator bypass.
[$visible, $bind] = community_search_pages_where($user);
$verify = Http::queryText('verify', 20);
$q = trim(Http::queryText('q', 100));
$page = Http::queryInt('page', 1, 1, 100000);
$state = "CASE WHEN p.verified_at IS NULL THEN 'none' WHEN p.verify_expires_at <= now() THEN 'expired' ELSE 'verified' END";
$filter = '';
if (in_array($verify, ['verified','expired','none'], true)) {
    $filter = " AND ({$state})=:verify";
    $bind[':verify'] = $verify;
}
$bind[':qoff'] = $q === '' ? 'true' : 'false';
$bind[':q'] = '%' . str_replace(['#','%','_'], ['##','#%','#_'], $q) . '%';
$bind[':off'] = ($page - 1) * 20;
$sql = "SELECT p.public_id,p.title,p.icon,p.updated_at,s.kind AS space_kind,s.name AS space_name,
        coalesce(owner.display_name,owner.username,author.display_name,author.username,'') AS owner_name,
        {$state} AS verify_state
        FROM community.pages p JOIN community.spaces s ON s.id=p.space_id
        LEFT JOIN public.users owner ON owner.id=s.owner_user_id
        LEFT JOIN public.users author ON author.id=p.author_id
        WHERE p.deleted_at IS NULL AND p.page_kind='procedure' AND
        {$visible}
        {$filter}
        AND (CAST(:qoff AS boolean) OR p.title ILIKE :q ESCAPE '#')
        ORDER BY p.updated_at DESC,p.id DESC LIMIT 20 OFFSET CAST(:off AS integer)";
$items = [];
foreach (Db::instance()->run($sql, $bind)->fetchAll() as $r) {
    $items[] = ['id' => $r['public_id'], 'title' => $r['title'], 'icon' => $r['icon'],
        'space' => ['kind' => $r['space_kind'], 'name' => $r['space_name']],
        'owner' => ['name' => $r['owner_name']], 'verify_state' => $r['verify_state'],
        'page_kind' => 'procedure', 'updated_at' => $r['updated_at']];
}
Http::json(['pages' => $items, 'page' => $page]);
