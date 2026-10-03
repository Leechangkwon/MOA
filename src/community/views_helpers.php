<?php
declare(strict_types=1);
function community_dependency_ok(int $databaseId,string $key,?string $rowId=null,?array $replacement=null): bool
{
    $sql=<<<'SQL'
        WITH RECURSIVE edges AS (
          SELECT p.public_id::text AS source, value AS target
            FROM community.pages p
            CROSS JOIN LATERAL jsonb_array_elements_text(CASE WHEN p.public_id=CAST(:row AS uuid)
                THEN CAST(:replacement AS jsonb) ELSE coalesce(p.properties->CAST(:key AS text),'[]'::jsonb) END) AS value
           WHERE p.database_id=CAST(:db AS bigint) AND p.deleted_at IS NULL
        ), walk AS (
          SELECT source AS origin,target,ARRAY[source,target] AS path,source=target AS cycle,1 AS depth FROM edges
          UNION ALL
          SELECT w.origin,e.target,w.path||e.target,e.target=ANY(w.path),w.depth+1
            FROM walk w JOIN edges e ON e.source=w.target WHERE NOT w.cycle AND w.depth<50
        ) SELECT NOT EXISTS(SELECT 1 FROM walk WHERE cycle OR depth>=50)
        SQL;
    return Db::instance()->run($sql,[':db'=>$databaseId,':key'=>$key,':row'=>$rowId,
        ':replacement'=>json_encode($replacement ?? [])])->fetchColumn() === true;
}

/** Group/aggregate only inside the SQL visibility boundary. No hidden rows enter PHP. */
function community_chart_data(array $user,array $dbRow,array $schema,array $view,string $from='',string $to='',string $owner=''): array
{
    [$visible,$bind]=community_visible_pages_where($user);
    [$filter,$filterBind]=DbProps::compileFilter($view['filter'] ?? null,$schema);
    $bind=array_merge($bind,$filterBind,[':db'=>(int)$dbRow['id']]);
    $range='TRUE'; $applied=true;
    $key=$view['group_by'] ?? null; $kind=$key===null ? null : DbViews::groupKind($key,$schema);
    $dateKey=$kind==='date' ? $key : null;
    foreach ($schema as $prop) { if ($dateKey===null && $prop['type']==='date') { $dateKey=$prop['key']; } }
    $personKey=null; foreach ($schema as $prop) { if ($personKey===null && $prop['type']==='person') { $personKey=$prop['key']; } }
    if ($from!=='' || $to!=='') {
        if ($dateKey!==null) { [$range,$rangeBind]=DbViews::dateRange($dateKey,$schema,$from,$to); $bind=array_merge($bind,$rangeBind); }
        else { if (DbViews::day($from)===null || DbViews::day($to)===null || $from>$to) { throw new InvalidArgumentException('bad_date_range'); } $applied=false; }
    }
    $ownerWhere='TRUE';
    if ($owner!=='') {
        if (preg_match('/^[a-z0-9_.-]{3,64}$/',$owner)!==1) { throw new InvalidArgumentException('bad_owner'); }
        if ($personKey===null) { $ownerWhere='owner.username=CAST(:chart_owner AS text)'; $bind[':chart_owner']=$owner; }
        else { $ownerWhere='p.properties->>CAST(:owner_prop AS text)=CAST(:chart_owner AS text)'; $bind[':owner_prop']=$personKey; $bind[':chart_owner']=$owner; }
    }
    $lateral=''; $label="'전체'::text";
    if ($key!==null) {
        if ($kind==='status') { $label="coalesce(p.status,'미정')"; }
        elseif ($kind==='owner') { $label="coalesce(owner.username,'미정')"; }
        elseif ($kind==='verified') { $label="CASE WHEN p.verified_at IS NULL THEN '미검증' WHEN p.verify_expires_at<now() THEN '만료' ELSE '검증됨' END"; }
        elseif ($kind==='date') {
            $date=$key==='due' ? 'p.due_date' : "CAST(left(p.properties->CAST(:group_key AS text)->>'start',10) AS date)";
            if ($key!=='due') { $bind[':group_key']=$key; }
            $label="coalesce(to_char(date_trunc(CAST(:bucket AS text)," . $date . "),'YYYY-MM-DD'),'미정')";
            $bind[':bucket']=$view['bucket'] ?? 'month';
        } elseif ($kind==='multi_select') {
            $lateral=" LEFT JOIN LATERAL jsonb_array_elements_text(coalesce(p.properties->CAST(:group_key AS text),'[]'::jsonb)) AS choice(label) ON TRUE ";
            $label="coalesce(choice.label,'미정')"; $bind[':group_key']=$key;
        } elseif ($kind==='checkbox') {
            $label="CASE WHEN p.properties->>CAST(:group_key AS text)='true' THEN '체크' ELSE '미체크' END"; $bind[':group_key']=$key;
        } else { $label="coalesce(p.properties->>CAST(:group_key AS text),'미정')"; $bind[':group_key']=$key; }
    }
    $amount='1::numeric';
    if ($view['aggregate']!=='count') { $amount='CAST(p.properties->>CAST(:amount_key AS text) AS numeric)'; $bind[':amount_key']=$view['on_prop']; }
    $sql=<<<SQL
        WITH grouped AS (
          SELECT
            {$label} AS label,count({$amount}) AS samples,sum({$amount}) AS total
            FROM community.pages p JOIN community.spaces s ON s.id=p.space_id
            LEFT JOIN users owner ON owner.id=s.owner_user_id {$lateral}
           WHERE p.database_id=CAST(:db AS bigint) AND p.deleted_at IS NULL
             AND {$visible} AND {$filter} AND {$range} AND {$ownerWhere}
           GROUP BY 1
        ), ranked AS (SELECT label,samples,total,row_number() OVER(ORDER BY label) AS rank FROM grouped)
        SELECT CASE WHEN min(rank)>50 THEN '그 밖' ELSE min(label) END AS label,sum(samples) AS samples,sum(total) AS total
          FROM ranked GROUP BY CASE WHEN rank>50 THEN 0 ELSE rank END ORDER BY min(rank)
        SQL;
    $out=[];
    foreach (Db::instance()->run($sql,$bind)->fetchAll() as $row) {
        $value=$view['aggregate']==='count' ? (int)$row['samples'] : ($view['aggregate']==='avg'
            ? ((int)$row['samples']===0 ? null : (float)$row['total']/(int)$row['samples']) : ($row['total']===null ? null : (float)$row['total']));
        $out[]=['label'=>(string)$row['label'],'value'=>$value];
    }
    if ($key===null && $out===[]) { $out[]=['label'=>'전체','value'=>$view['aggregate']==='count'?0:null]; }
    return ['chart'=>['kind'=>$view['kind'],'aggregate'=>$view['aggregate'],'groups'=>$out],'filter_applied'=>$applied];
}

/** Same unreadable card for missing database/view or denied source. No count/title is returned. */
function community_db_widget_context(array $doc,array $user): array
{
    $out=[]; $walk=static function(array $blocks) use (&$walk,&$out,$user): void {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '')==='db_view') {
                $id=(string)($block['id'] ?? ''); $db=community_db_row((string)($block['database_id'] ?? ''));
                $page=$db===null ? null : community_page_row((string)$db['page_public_id'],(int)$user['id']);
                $out[$id]=null;
                if ($page!==null && CommunityAuthz::decidePageRead($user,$page)[0]) {
                    foreach (community_db_views_of($db['views'],community_db_schema_of($db['schema'])) as $view) {
                        if ($view['id']===($block['view_id'] ?? null) && $view['type']!=='form') {
                            $out[$id]=['title'=>(string)$page['title'],'name'=>$view['name'],'type'=>$view['type'],'view'=>$view,'schema'=>community_db_schema_of($db['schema'])]; break;
                        }
                    }
                }
            }
            if (is_array($block['children'] ?? null)) { $walk($block['children']); }
        }
    };
    $walk($doc['blocks'] ?? []); return $out;
}
