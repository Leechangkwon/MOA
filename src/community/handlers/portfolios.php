<?php
declare(strict_types=1);

/**
 * src/community/handlers/portfolios.php — 포트폴리오 창구 일곱 (사이트 Q:F-12 WK-12 ③ · WK-13 ②, 커뮤니티 ARCHITECTURE 23.7)
 *
 *   GET    /community/api/spaces/{num}/portfolios        한 팀 공간의 포트폴리오 목록        (community.read)
 *   POST   /community/api/portfolios {space, name}       만들기                                (community.write)
 *   GET    /community/api/portfolios/{id}                한 포트폴리오 — 항목·총수·상태별 수·진행률 (community.read)
 *   PATCH  /community/api/portfolios/{id} {name}         이름 바꾸기                           (community.write)
 *   DELETE /community/api/portfolios/{id}                지우기(= deleted_at, 줄은 남는다)      (community.write)
 *   POST   /community/api/portfolios/{id}/items {project} 프로젝트 넣기                        (community.write)
 *   DELETE /community/api/portfolios/{id}/items/{id}     프로젝트 빼기                          (community.write)
 *
 * 판정은 `CommunityAuthz::decidePortfolioRead` · `decidePortfolioManage` · `decidePortfolioAdd` 뿐이다 — 이 파일에는 팀·역할·
 * 소유를 견주는 줄이 없다(공간이 `team` 인지도 판정 함수 안에서 본다). 포트폴리오는 프로젝트를 볼 권한을 주지 않는다(C:R-16):
 *   · 항목·총수·상태별 수·진행률은 **보는 사람의 목록 조건절 안에서만**(`community_portfolio_items`) — 「n개 숨김」 같은 수 0.
 *   · 빼기는 빼는 사람이 그 프로젝트를 (관리자 특권 없이) 볼 수 있을 때만 줄을 찾는다(`community_search_pages_where`) —
 *     볼 수 없는 프로젝트가 들어 있는지를 200/404 의 차이로 떠보지 못하게.
 *   · 못 보는 포트폴리오 · 지운 포트폴리오 · 없는 포트폴리오는 **같은 404 문구**(있는지도 알리지 않는다).
 *   · 볼 수는 있으나 관리할 수 없는 사람(Admin 의 열람 — 읽기만)은 403.
 *
 * 한도: 팀 공간당 살아 있는 포트폴리오 50 · 포트폴리오당 프로젝트 100(넘으면 400). 변경마다 `portfolio_events`(지울 수 없는
 * 기록 — 앱 SELECT · INSERT 만) 한 줄 + 감사 `portfolio.create|rename|delete|add|remove`. 거부 감사는 트랜잭션을 되돌린 뒤 쓴다.
 * 읽기 감사는 `portfolio.read` 의 관리자 특권 열람(`admin_all`)과 거부(`not_member`)만(`page.read` 관례).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 라우트 자리표시자 값(공간 번호 또는 포트폴리오 UUID[, 프로젝트 UUID])
 */

$db       = Db::instance();
$me       = (int) $user['id'];
$method   = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
// 어느 창구인가 — 라우터가 매칭한 **라우트 패턴**(요청 값이 아니다)에서 메서드·모듈 접두를 뗀 것.
$pattern  = substr((string) TBOX_ROUTE, strlen($method . ' ' . COMMUNITY_BASE));
$notFound = static function (): void {
    Http::error(404, Http::MSG_NOT_FOUND);
};

/** 응답의 포트폴리오 한 건 — 공간 번호·이름은 보는 사람이 이미 그 공간의 포트폴리오를 볼 수 있을 때만 나간다. */
$pfOut = static function (array $pf): array {
    return [
        'id'         => (string) $pf['public_id'],
        'name'       => (string) $pf['name'],
        'space'      => ['id' => (int) $pf['space_id'], 'name' => (string) $pf['space_name']],
        'created_at' => (string) $pf['created_at'],
    ];
};

/** 감사 문맥 — 대상은 포트폴리오, 문장의 {target} 은 이름 30자. */
$audit = static function (array $pf, array $extra = []): array {
    return [
        'target_type' => 'portfolio',
        'target_id'   => isset($pf['id']) ? (int) $pf['id'] : null,
        'target_name' => community_target_title((string) $pf['name']),
        'detail'      => ['portfolio_name' => community_target_title((string) $pf['name']),
                          'space_id' => (int) $pf['space_id']] + $extra,
    ];
};

/** 이름 — 앞뒤 공백을 떼고 1~80자, 제어·서식 문자 0. 아니면 null(400). */
$cleanName = static function ($raw): ?string {
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        return null;
    }
    $name = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw);
    if ($name === '' || preg_match('/\p{C}/u', $name) === 1 || iconv_strlen($name, 'UTF-8') > COMMUNITY_PORTFOLIO_NAME_MAX) {
        return null;
    }
    return $name;
};

/** 공간 행 → 판정이 받는 포트폴리오 모양(아직 행이 없는 만들기·목록에서). 값은 전부 DB 의 공간 행이다. */
$spaceAsPortfolio = static function (array $space): array {
    return ['deleted_at' => null, 'space_kind' => (string) $space['kind'], 'space_team_id' => $space['team_id'] ?? null];
};

/**
 * 포트폴리오를 읽어(잠금 선택) 볼 수 있는지 판정한다. 못 보면 되돌리고(쓰기 창구) 감사 뒤 같은 404 로 끝난다.
 * 돌려주는 것은 [행, 읽기 코드].
 */
$gateRead = static function (string $publicId, bool $lock, ?string $denyAction) use ($db, $user, $audit, $notFound): array {
    $pf = community_portfolio_row($publicId, $lock);
    if ($pf === null) {
        $db->rollback();
        $notFound();
    }
    [$mayRead, $readCode] = CommunityAuthz::decidePortfolioRead($user, $pf);
    if (!$mayRead) {
        $db->rollback();
        if ($readCode === CommunityAuthz::CODE_NOT_MEMBER) {
            Audit::deny($denyAction ?? 'portfolio.read', CommunityAuthz::CODE_NOT_MEMBER, $audit($pf));
        }
        $notFound();
    }
    return [$pf, $readCode];
};

/** 관리 판정 — 거부면 되돌리고 감사 뒤 403(볼 수는 있는 사람이다 — 404 는 $gateRead 가 이미 냈다). */
$gateManage = static function (array $pf, string $action) use ($db, $user, $audit): string {
    [$mayManage, $manageCode] = CommunityAuthz::decidePortfolioManage($user, $pf);
    if (!$mayManage) {
        $db->rollback();
        Audit::deny($action, $manageCode, $audit($pf));
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    return $manageCode;
};

// ═══ GET /api/spaces/{num}/portfolios — 한 공간의 목록 ══════════════════════════
if ($method === 'GET' && $pattern === '/api/spaces/{num}/portfolios') {
    $space = community_space_row((int) $params[0]);
    if ($space === null) {
        $notFound();
    }
    $asPf = $spaceAsPortfolio($space);
    [$mayRead, $readCode] = CommunityAuthz::decidePortfolioRead($user, $asPf);
    $spaceCtx = static function (array $detail) use ($space): array {
        return ['target_type' => 'space', 'target_id' => (int) $space['id'],
            'target_name' => community_target_title((string) $space['name']),
            'detail' => ['space_id' => (int) $space['id'], 'scope' => 'list'] + $detail];
    };
    if (!$mayRead) {
        if ($readCode === CommunityAuthz::CODE_NOT_MEMBER) {
            Audit::deny('portfolio.read', CommunityAuthz::CODE_NOT_MEMBER, $spaceCtx([]));
        }
        $notFound();
    }
    if ($readCode === CommunityAuthz::CODE_ADMIN_ALL) {
        Audit::allow('portfolio.read', $spaceCtx(['granted_by' => $readCode]), $readCode);
    }
    $sqlList = <<<'SQL'
        SELECT pf.public_id, pf.name, pf.created_at
          FROM community.portfolios pf
         WHERE pf.space_id = CAST(:sid AS bigint) AND pf.deleted_at IS NULL
         ORDER BY lower(pf.name), pf.id
         LIMIT 50
        SQL;
    $list = [];
    foreach ($db->run($sqlList, [':sid' => (int) $space['id']])->fetchAll() as $r) {
        $list[] = ['id' => (string) $r['public_id'], 'name' => (string) $r['name'], 'created_at' => (string) $r['created_at']];
    }
    Http::json([
        'space'      => ['id' => (int) $space['id'], 'name' => (string) $space['name'], 'kind' => (string) $space['kind']],
        'portfolios' => $list,
        'can_create' => CommunityAuthz::decidePortfolioManage($user, $asPf)[0],
    ]);
    exit;
}

// ═══ POST /api/portfolios {space, name} — 만들기 ═══════════════════════════════
if ($method === 'POST' && $pattern === '/api/portfolios') {
    $body = Http::jsonBody();
    if (array_diff(array_keys($body), ['space', 'name']) !== [] || !array_key_exists('space', $body) || !array_key_exists('name', $body)) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $spaceRaw = $body['space'];
    if (is_int($spaceRaw) && $spaceRaw > 0) {
        $spaceId = $spaceRaw;
    } elseif (is_string($spaceRaw) && preg_match('/^[1-9][0-9]{0,17}$/', $spaceRaw) === 1) {
        $spaceId = (int) $spaceRaw;
    } else {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $name = $cleanName($body['name']);
    if ($name === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }

    $db->begin();
    // 그 공간의 만들기·이름 바꾸기를 한 줄로 세운다(한도 50 · 살아 있는 이름 유일). NO KEY — 페이지 쓰기의 KEY SHARE 와 부딪히지 않는다.
    $sqlLockSpace = <<<'SQL'
        SELECT id FROM community.spaces WHERE id = CAST(:sid AS bigint) FOR NO KEY UPDATE
        SQL;
    if (!is_array($db->run($sqlLockSpace, [':sid' => $spaceId])->fetch())) {
        $db->rollback();
        $notFound();
    }
    $space = community_space_row($spaceId);
    $asPf  = $spaceAsPortfolio($space);
    $draft = ['name' => $name, 'space_id' => $spaceId];
    [$mayManage, $manageCode] = CommunityAuthz::decidePortfolioManage($user, $asPf);
    if (!$mayManage) {
        $db->rollback();
        $visible = CommunityAuthz::decidePortfolioRead($user, $asPf)[0];
        Audit::deny('portfolio.create', $manageCode, $audit($draft, ['space_name' => community_target_title((string) $space['name'])]));
        if ($visible) {
            Http::error(403, Http::MSG_FORBIDDEN);
        }
        $notFound();
    }
    $sqlCount = <<<'SQL'
        SELECT count(*) FROM community.portfolios WHERE space_id = CAST(:sid AS bigint) AND deleted_at IS NULL
        SQL;
    if ((int) $db->run($sqlCount, [':sid' => $spaceId])->fetchColumn() >= COMMUNITY_PORTFOLIO_MAX_PER_SPACE) {
        $db->rollback();
        Http::json(['error' => '팀 공간 하나에 포트폴리오는 50개까지 만들 수 있습니다'], 400);
        exit;
    }
    $sqlSame = <<<'SQL'
        SELECT 1 FROM community.portfolios
         WHERE space_id = CAST(:sid AS bigint) AND deleted_at IS NULL AND lower(name) = lower(:name)
        SQL;
    if (is_array($db->run($sqlSame, [':sid' => $spaceId, ':name' => $name])->fetch())) {
        $db->rollback();
        Http::json(['error' => '같은 이름의 포트폴리오가 이미 있습니다'], 409);
        exit;
    }
    try {
        $sqlInsert = <<<'SQL'
            INSERT INTO community.portfolios (space_id, name, created_by)
            VALUES (CAST(:sid AS bigint), :name, CAST(:by AS bigint))
            RETURNING id, public_id, space_id, name, created_at
            SQL;
        $pf = $db->run($sqlInsert, [':sid' => $spaceId, ':name' => $name, ':by' => $me])->fetch();
        $pf['space_name'] = (string) $space['name'];
        community_portfolio_event((int) $pf['id'], 'create', null, null, $name, $me);
        Audit::allow('portfolio.create', $audit($pf, ['granted_by' => $manageCode,
            'space_name' => community_target_title((string) $space['name'])]));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    Http::json(['portfolio' => $pfOut($pf), 'can_manage' => true], 201);
    exit;
}

// ═══ GET /api/portfolios/{id} — 한 포트폴리오 ═════════════════════════════════
if ($method === 'GET' && $pattern === '/api/portfolios/{id}') {
    [$pf, $readCode] = $gateRead((string) $params[0], false, null);
    if ($readCode === CommunityAuthz::CODE_ADMIN_ALL) {
        Audit::allow('portfolio.read', $audit($pf, ['scope' => 'portfolio', 'granted_by' => $readCode]), $readCode);
    }
    $data = community_portfolio_items((int) $pf['id'], $user);
    Http::json([
        'portfolio'  => $pfOut($pf),
        'can_manage' => CommunityAuthz::decidePortfolioManage($user, $pf)[0],
        // 아래 셋은 전부 **보는 사람이 볼 수 있는 프로젝트만**으로 센 값이다(진행률도 볼 수 있는 행만 — 사람마다 다를 수 있다).
        'total'      => $data['total'],
        'by_status'  => $data['by_status'],
        'items'      => $data['items'],
    ]);
    exit;
}

// ═══ PATCH /api/portfolios/{id} {name} — 이름 바꾸기 ═══════════════════════════
if ($method === 'PATCH' && $pattern === '/api/portfolios/{id}') {
    $body = Http::jsonBody();
    if (array_keys($body) !== ['name']) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $name = $cleanName($body['name']);
    if ($name === null) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $db->begin();
    [$pf] = $gateRead((string) $params[0], true, 'portfolio.rename');
    $manageCode = $gateManage($pf, 'portfolio.rename');
    if ($name === (string) $pf['name']) {
        $db->rollback();
        Http::json(['portfolio' => $pfOut($pf), 'changed' => false]);
        exit;
    }
    $sqlLockSpace = <<<'SQL'
        SELECT id FROM community.spaces WHERE id = CAST(:sid AS bigint) FOR NO KEY UPDATE
        SQL;
    $db->run($sqlLockSpace, [':sid' => (int) $pf['space_id']]);
    $sqlSame = <<<'SQL'
        SELECT 1 FROM community.portfolios
         WHERE space_id = CAST(:sid AS bigint) AND deleted_at IS NULL AND lower(name) = lower(:name) AND id <> CAST(:id AS bigint)
        SQL;
    if (is_array($db->run($sqlSame, [':sid' => (int) $pf['space_id'], ':name' => $name, ':id' => (int) $pf['id']])->fetch())) {
        $db->rollback();
        Http::json(['error' => '같은 이름의 포트폴리오가 이미 있습니다'], 409);
        exit;
    }
    try {
        $sqlRename = <<<'SQL'
            UPDATE community.portfolios SET name = :name WHERE id = CAST(:id AS bigint) AND deleted_at IS NULL
            SQL;
        $db->run($sqlRename, [':name' => $name, ':id' => (int) $pf['id']]);
        community_portfolio_event((int) $pf['id'], 'rename', null, (string) $pf['name'], $name, $me);
        Audit::allow('portfolio.rename', $audit(['name' => $name] + $pf, ['granted_by' => $manageCode,
            'from_value' => community_target_title((string) $pf['name']), 'to_value' => community_target_title($name)]));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $pf['name'] = $name;
    Http::json(['portfolio' => $pfOut($pf), 'changed' => true]);
    exit;
}

// ═══ DELETE /api/portfolios/{id} — 지우기(deleted_at) ═══════════════════════════
if ($method === 'DELETE' && $pattern === '/api/portfolios/{id}') {
    $db->begin();
    [$pf] = $gateRead((string) $params[0], true, 'portfolio.delete');
    $manageCode = $gateManage($pf, 'portfolio.delete');
    try {
        $sqlDelete = <<<'SQL'
            UPDATE community.portfolios SET deleted_at = now() WHERE id = CAST(:id AS bigint) AND deleted_at IS NULL
            SQL;
        $db->run($sqlDelete, [':id' => (int) $pf['id']]);
        community_portfolio_event((int) $pf['id'], 'delete', null, (string) $pf['name'], null, $me);
        Audit::allow('portfolio.delete', $audit($pf, ['granted_by' => $manageCode]));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    Http::json(['deleted' => true]);
    exit;
}

// ═══ POST /api/portfolios/{id}/items {project} — 프로젝트 넣기 ═════════════════
if ($method === 'POST' && $pattern === '/api/portfolios/{id}/items') {
    $body = Http::jsonBody();
    if (array_keys($body) !== ['project'] || !is_string($body['project'])
        || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $body['project']) !== 1) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $projectId = $body['project'];
    $db->begin();
    [$pf] = $gateRead((string) $params[0], true, 'portfolio.add');
    $project = community_project_row($projectId, $me);
    if ($project === null) {
        $db->rollback();
        $notFound();
    }
    [$mayAdd, $addCode] = CommunityAuthz::decidePortfolioAdd($user, $pf, $project);
    if (!$mayAdd) {
        $db->rollback();
        if ($addCode === CommunityAuthz::CODE_NOT_PROJECT) {
            // 넣는 사람이 볼 수 있는 페이지이지만 프로젝트가 아니다 — 형식 쪽 거부(감사 없음)
            Http::json(['error' => '프로젝트만 포트폴리오에 넣을 수 있습니다'], 400);
            exit;
        }
        Audit::deny('portfolio.add', $addCode, $audit($pf));
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    $projectPk = (int) $project['id'];
    $sqlHas = <<<'SQL'
        SELECT 1 FROM community.portfolio_items WHERE portfolio_id = CAST(:pf AS bigint) AND page_id = CAST(:page AS bigint)
        SQL;
    if (is_array($db->run($sqlHas, [':pf' => (int) $pf['id'], ':page' => $projectPk])->fetch())) {
        $db->rollback();
        Http::json(['added' => false]);
        exit;
    }
    $sqlCount = <<<'SQL'
        SELECT count(*) FROM community.portfolio_items WHERE portfolio_id = CAST(:pf AS bigint)
        SQL;
    if ((int) $db->run($sqlCount, [':pf' => (int) $pf['id']])->fetchColumn() >= COMMUNITY_PORTFOLIO_MAX_ITEMS) {
        $db->rollback();
        Http::json(['error' => '포트폴리오 하나에 프로젝트는 100개까지 넣을 수 있습니다'], 400);
        exit;
    }
    try {
        $sqlAdd = <<<'SQL'
            INSERT INTO community.portfolio_items (portfolio_id, page_id, position, added_by)
            SELECT CAST(:pf AS bigint), CAST(:page AS bigint), coalesce(max(position), 0) + 1, CAST(:by AS bigint)
              FROM community.portfolio_items WHERE portfolio_id = CAST(:pf2 AS bigint)
            SQL;
        $db->run($sqlAdd, [':pf' => (int) $pf['id'], ':page' => $projectPk, ':by' => $me, ':pf2' => (int) $pf['id']]);
        community_portfolio_event((int) $pf['id'], 'add', $projectPk, null, null, $me);
        Audit::allow('portfolio.add', $audit($pf, ['granted_by' => $addCode,
            'page_title' => community_target_title((string) $project['title'])]));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    Http::json(['added' => true], 201);
    exit;
}

// ═══ DELETE /api/portfolios/{id}/items/{id} — 프로젝트 빼기 ═════════════════════
if ($method === 'DELETE' && $pattern === '/api/portfolios/{id}/items/{id}') {
    $projectId = (string) $params[1];
    $db->begin();
    [$pf] = $gateRead((string) $params[0], true, 'portfolio.remove');
    $manageCode = $gateManage($pf, 'portfolio.remove');
    /* 빼는 사람이 그 프로젝트를 볼 수 있을 때만 줄을 찾는다(관리자 특권 갈래를 끈 조건절) — 볼 수 없는 프로젝트가
       들어 있는지를 응답의 차이로 알 수 없게. 지운(휴지통) 프로젝트의 줄도 찾지 않는다(되살리면 다시 보인다). */
    [$where, $whereBind] = community_search_pages_where($user);
    $sqlFind = 'SELECT p.id, p.title'
        . ' FROM community.portfolio_items i'
        . ' JOIN community.pages p ON p.id = i.page_id'
        . ' JOIN community.spaces s ON s.id = p.space_id'
        . ' LEFT JOIN public.users owner ON owner.id = s.owner_user_id'
        . ' WHERE i.portfolio_id = CAST(:pf AS bigint) AND p.public_id = CAST(:proj AS uuid)'
        . ' AND p.deleted_at IS NULL AND ' . $where;
    $item = $db->run($sqlFind, array_merge([':pf' => (int) $pf['id'], ':proj' => $projectId], $whereBind))->fetch();
    if (!is_array($item)) {
        $db->rollback();
        $notFound();
    }
    try {
        $sqlRemove = <<<'SQL'
            DELETE FROM community.portfolio_items WHERE portfolio_id = CAST(:pf AS bigint) AND page_id = CAST(:page AS bigint)
            SQL;
        $db->run($sqlRemove, [':pf' => (int) $pf['id'], ':page' => (int) $item['id']]);
        community_portfolio_event((int) $pf['id'], 'remove', (int) $item['id'], null, null, $me);
        Audit::allow('portfolio.remove', $audit($pf, ['granted_by' => $manageCode,
            'page_title' => community_target_title((string) $item['title'])]));
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    Http::json(['removed' => true]);
    exit;
}

// 라우트 표에 이 파일로 오는 다른 조합은 없다 — 오면 없는 것과 같다.
$notFound();
