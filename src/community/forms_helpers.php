<?php
declare(strict_types=1);

/** Internal insertion primitive shared by the normal page/row route and forms.
 * Caller owns authorization, value validation and transaction. No request data bypasses those gates.
 * A nullable actor is reserved for separately authenticated public-form integration (stage 12).
 */
function community_insert_page(array $data, array $extract, ?array $actor, string $grantCode, array $auditExtra = []): array
{
    $db = Db::instance();
    if (($data['database_id'] ?? null) !== null) {
        $lock = <<<'SQL'
            SELECT id FROM community.databases WHERE id = CAST(:db AS bigint) FOR UPDATE
            SQL;
        $db->run($lock, [':db' => $data['database_id']]);
        if (community_db_row_count((int) $data['database_id']) >= DbProps::MAX_ROWS) {
            throw new DomainException('too_many_rows');
        }
    }
    $sql = <<<'SQL'
        INSERT INTO community.pages
            (space_id,parent_page_id,title,doc,doc_version,plain_text,search_tsv,block_count,
             author_id,last_edited_by,visibility,position,database_id,properties,icon,form_view_id,form_anonymous,public_form_response)
        VALUES (CAST(:space AS bigint),CAST(:parent AS bigint),:title,CAST(:doc AS jsonb),1,:plain,
                strip(to_tsvector('simple',:tsv)),CAST(:blocks AS int),CAST(:author AS bigint),CAST(:author AS bigint),
                :vis,CAST(:position AS int),CAST(:db AS bigint),CAST(:properties AS jsonb),:icon,:form,CAST(:anonymous AS boolean),CAST(:public_form AS boolean))
        RETURNING id,public_id,created_at,updated_at
        SQL;
    $row = $db->run($sql, [
        ':space' => $data['space_id'], ':parent' => $data['parent_id'] ?? null, ':title' => $data['title'],
        ':doc' => $data['doc_json'], ':plain' => $extract['plain_text'],
        ':tsv' => community_search_text($data['title'], $extract['plain_text']), ':blocks' => count($extract['blocks']),
        ':author' => $actor['id'] ?? null, ':vis' => $data['visibility'], ':position' => $data['position'],
        ':db' => $data['database_id'] ?? null, ':properties' => ($data['properties'] ?? null) === null ? null
            : json_encode($data['properties'] === [] ? new stdClass() : $data['properties'], JSON_UNESCAPED_UNICODE),
        ':icon' => $data['icon'] ?? null, ':form' => $data['form_view_id'] ?? null,
        ':public_form' => ($data['public_form_response'] ?? false) ? 'true' : 'false',
        ':anonymous' => isset($data['form_anonymous']) ? ($data['form_anonymous'] ? 'true' : 'false') : null,
    ])->fetch();
    if (!is_array($row)) { throw new RuntimeException('Page insertion failed'); }
    community_rewrite_derived((int) $row['id'], $extract);
    if ($actor !== null) { Audit::allow('page.create', [
        'target_type' => 'page', 'target_id' => (int) $row['id'], 'target_name' => community_target_title($data['title']),
        'detail' => ['granted_by' => $grantCode, 'page_title' => community_target_title($data['title']),
            'doc_version' => 1, 'block_count' => count($extract['blocks'])] + $auditExtra,
    ]);
    }
    return $row;
}

/** Validate only the advertised questions; discard hidden answers before examining their values. */
function community_form_values(array $view, array $schema, array $values): array
{
    $questions = $view['questions'];
    if (($values !== [] && array_is_list($values)) || array_diff(array_keys($values), array_column($questions, 'prop')) !== []) {
        throw new InvalidArgumentException('unknown_prop');
    }
    $clean = [];
    $title = null;
    foreach ($questions as $q) {
        $key = $q['prop'];
        if (isset($q['show_if']) && ($clean[$q['show_if']['prop']] ?? null) !== $q['show_if']['equals']) { continue; }
        $prop = $key === 'title' ? ['key'=>'title','name'=>'제목','type'=>'text'] : DbProps::find($schema, $key);
        if ($prop === null || !in_array($prop['type'], DbViews::FORM_TYPES, true)) { throw new InvalidArgumentException('bad_question'); }
        [$ok, $answer] = DbProps::cleanValue($prop, $values[$key] ?? null);
        if (!$ok || ($q['required'] && $answer === null)) { throw new InvalidArgumentException('bad_value'); }
        if ($answer === null) { continue; }
        if ($key === 'title') {
            if (!is_string($answer) || iconv_strlen(trim($answer),'UTF-8') > 200 || preg_match('/\p{C}/u',$answer) === 1) {
                throw new InvalidArgumentException('bad_title');
            }
            $title = trim($answer);
        } else { $clean[$key] = $answer; }
    }
    return ['title' => $title ?? '응답 – ' . date('Y-m-d H:i'), 'properties' => $clean];
}

/** Caller holds database row lock and has rechecked the current form audience/open state. */
function community_form_create_response(array $dbRow, array $dbPage, array $view, array $clean, ?array $actor, string $grantCode): array
{
    $db = Db::instance();
    $limits = <<<'SQL'
        SELECT count(*) FILTER(WHERE author_id = CAST(:author AS bigint) AND created_at > now()-interval '1 hour') AS personal,
               count(*) FILTER(WHERE created_at >= date_trunc('day',now() AT TIME ZONE 'Asia/Seoul') AT TIME ZONE 'Asia/Seoul') AS daily
          FROM community.pages WHERE database_id = CAST(:db AS bigint) AND form_view_id = :view
        SQL;
    $rate = $db->run($limits, [':author'=>$actor['id'] ?? null, ':db'=>(int)$dbRow['id'], ':view'=>$view['id']])->fetch();
    if ((int)$rate['personal'] >= 30 || (int)$rate['daily'] >= 1000) { throw new OverflowException('form_rate_limit'); }
    $doc = ['schema'=>1,'blocks'=>[['id'=>Doc::uuid4(),'type'=>'paragraph','text'=>[]]]];
    $data = ['space_id'=>(int)$dbPage['space_id'],'parent_id'=>(int)$dbPage['id'],
        'title'=>$clean['title'],'doc_json'=>json_encode($doc),'visibility'=>'private',
        'position'=>community_next_position((int)$dbPage['space_id'],(int)$dbPage['id']),
        'database_id'=>(int)$dbRow['id'],'properties'=>$clean['properties'],
        'form_view_id'=>$view['id'],'form_anonymous'=>$view['anonymous'],'public_form_response'=>$actor===null];
    $row = community_insert_page($data, Doc::extract($doc), $actor, $grantCode);
    $page = community_page_row((string)$row['public_id'], (int)($actor['id'] ?? 0));
    $shared = false;
    if ($actor !== null && !$view['anonymous'] && !CommunityAuthz::decidePageRead($actor, $page)[0]) {
        $share = <<<'SQL'
            INSERT INTO community.page_shares(page_id,user_id,level,granted_by)
            VALUES(CAST(:page AS bigint),CAST(:user AS bigint),'view',CAST(:user AS bigint))
            ON CONFLICT(page_id,user_id) DO NOTHING
            SQL;
        $db->run($share, [':page'=>(int)$row['id'], ':user'=>(int)$actor['id']]);
        Audit::allow('page.share', ['target_type'=>'page','target_id'=>(int)$row['id'],
            'target_name'=>community_target_title($clean['title']),
            'detail'=>['page_title'=>community_target_title($clean['title']),'user_name'=>(string)$actor['username'],'permission'=>'view','granted_by'=>$grantCode]]);
        $shared = true;
    }
    if ($view['notify']) {
        $recipient = $dbPage['assignee_id'] ?? (($dbPage['space_kind'] ?? '') === 'personal' ? $dbPage['space_owner_user_id'] : $dbPage['author_id']);
        if ($recipient !== null) { community_notify((int)$recipient, 'form_response', $page, null, isset($actor['id']) ? (int)$actor['id'] : null); }
    }
    $canRead = $actor !== null && !$view['anonymous'] && ($shared || CommunityAuthz::decidePageRead($actor,$page)[0]);
    return ['received'=>true,'page_id'=>$canRead ? (string)$row['public_id'] : null,
        'url'=>$canRead ? Http::communityUrl('/p/' . (string)$row['public_id']) : null];
}

/** All form gates fail identically. Internal forms do not disclose the database title or rows. */
function community_form_gate(array $user, string $databaseId, string $viewId): array
{
    $dbRow = community_db_row($databaseId);
    $page = $dbRow === null ? null : community_page_row((string)$dbRow['page_public_id'],(int)$user['id']);
    $schema = $dbRow === null ? [] : community_db_schema_of($dbRow['schema']);
    $view = null;
    foreach ($dbRow === null ? [] : community_db_views_of($dbRow['views'],$schema) as $candidate) {
        if ($candidate['id'] === $viewId && $candidate['type'] === 'form') { $view = $candidate; break; }
    }
    if ($page === null || $view === null || !CommunityAuthz::decideFormSubmit($user,$page,$view)[0]) {
        Http::error(404,Http::MSG_NOT_FOUND);
    }
    return ['database'=>$dbRow,'page'=>$page,'schema'=>$schema,'view'=>$view];
}
function community_form_public_definition(array $gate): array
{
    $view = $gate['view']; $questions = [];
    foreach ($view['questions'] as $q) {
        $p = $q['prop'] === 'title' ? ['key'=>'title','name'=>'제목','type'=>'text'] : DbProps::find($gate['schema'],$q['prop']);
        $questions[] = $q + ['name'=>$p['name'],'type'=>$p['type'],'options'=>$p['options'] ?? []];
    }
    return ['id'=>$view['id'],'name'=>$view['name'],'intro'=>$view['intro'],'anonymous'=>$view['anonymous'],'questions'=>$questions];
}
/** Identity gate for output only. Never use a redacted row for authorization or audit. */
function community_form_identity_hidden(array $user,array $row): bool
{
    if (($row['form_anonymous'] ?? false) !== true) { return false; }
    $sql = <<<'SQL'
        SELECT p.public_id FROM community.databases d JOIN community.pages p ON p.id=d.page_id
         WHERE d.id=CAST(:id AS bigint)
        SQL;
    $parent = Db::instance()->run($sql,[':id'=>$row['database_id'] ?? null])->fetch();
    $dbPage = is_array($parent) ? community_page_row((string)$parent['public_id'],(int)$user['id']) : null;
    return $dbPage === null || !CommunityAuthz::canSeeFormIdentity($user,$dbPage);
}
/** Metadata fields only: user-written content and values are never silently rewritten. */
function community_form_redact_metadata(array $payload): array
{
    foreach ($payload as $key => $value) {
        if (in_array($key,['by','by_display_name','author','author_id','author_name','author_display_name',
            'last_edited_by','last_edited_by_name','last_edited_by_display_name','writer','accepted_by','decided_by','actor','actor_id','actor_name','actor_display_name','editors'],true)) {
            $payload[$key] = $key === 'editors' ? [] : ($key==='by' ? '익명 응답' : null);
        } elseif (is_array($value) && !in_array($key,['doc','values','properties','mention_labels'],true)) {
            $payload[$key] = community_form_redact_metadata($value);
        }
    }
    return $payload;
}
function community_form_json(array $payload,array $user,array $row,int $status=200): void
{
    Http::json(community_form_identity_hidden($user,$row) ? community_form_redact_metadata($payload) : $payload,$status);
}

function community_form_response_identity(array $user,array $row): ?array
{
    if (($row['form_view_id'] ?? null)===null) { return null; }
    if (($row['public_form_response']??false)===true) { return ['anonymous'=>true,'hidden'=>true,'username'=>null,'display_name'=>'공개 응답']; }
    $hidden=community_form_identity_hidden($user,$row);
    return ['anonymous'=>$row['form_anonymous']===true,'hidden'=>$hidden,
        'username'=>$hidden ? null : ($row['author_name'] ?? null),
        'display_name'=>$hidden ? '익명 응답' : ($row['author_display_name'] ?? null)];
}
