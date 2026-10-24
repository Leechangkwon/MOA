<?php
declare(strict_types=1);

/**
 * src/community/handlers/pages_visibility.php — PATCH /community/api/pages/{id}/visibility
 *                                               (정책: community.write)
 *
 * 요구사항: `C:F-18`(공개 범위), `C:S-13`, 커뮤니티 `PRD.md` 4.1(표·트리 불변 규칙),
 *           `../../../../community/ARCHITECTURE.md` 13.2 · 13.3, 수락 기준 C:T-30 ~ C:T-34.
 *
 * 받는 값 (JSON, **허용 키 밖이 하나라도 있으면 400**)
 *   visibility  `private` · `team` · `internal` **셋뿐**. `published` 를 보내면 400 이다 —
 *               게시판 공간(`kind='board'`)이 아직 없어 「게시」가 갈 곳이 없다(5단계).
 *   cascade     선택. 참이면 **하위 전부**를 같은 값으로 맞춘다 — 넓힐 때도 좁힐 때도(항목마다 감사).
 *               2026-09-23 정정: 전에는 좁힐 때만 적용돼 넓힐 때 켜도 하위가 그대로였다.
 *
 * 검사 순서
 *   [1] 허용 키·값 → [2] 행+공간 → 404
 *   [권한 ✔ A] `decidePageRead` — 못 보는 페이지는 여기서 끝난다(403·404)
 *   [권한 ✔ B] **소유자만** — `decidePageManage`(5-A). 볼 수 있어도, **편집 공유를 받았어도**
 *              남의 페이지 범위는 바꾸지 못한다 (C:T-32)
 *   [3] 팀 공간의 페이지는 자기 범위가 없다(공간 등급을 따른다) → 400
 *   [4] **넓히기** — 부모보다 넓으면 400 `wider_than_parent` + 감사 + DB 불변 (C:T-33)
 *   [5] **좁히기** — 하위에 더 넓은 것이 있으면
 *         `cascade` 없이   → 400 `children_wider` + 감사 + DB 불변
 *         `cascade: true`  → 하위 전부를 새 값으로 내리고 **항목마다** 감사 (C:T-34)
 *       넓힐 때 `cascade: true` → 하위 전부를 새 값으로 올린다 (C:T-34b)
 *   [6] 트랜잭션: 자기 갱신 → (필요하면) 하위 갱신 → 감사 `page.visibility allow` → 200
 *
 * ★ 값이 그대로여도 400 이 아니다 — 같은 값으로 다시 누르면 아무것도 바뀌지 않고 200 이다
 *   (감사에는 `visibility_from == visibility_to` 인 행이 남는다).
 * ★ 형식 거부(허용 키 밖·모르는 값·팀 공간)에는 감사가 없다. 감사는 **권한·규칙으로 막은
 *   일**에 남긴다 (`pages_create.php` 와 같은 원칙).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params [0] = 페이지 public_id (UUID)
 */

$db  = Db::instance();
$me  = (int) $user['id'];
$pid = (string) $params[0];

// ─── [1] 허용 키·값 ───────────────────────────────────────────
$body = Http::jsonBody();
if (array_diff(array_keys($body), ['visibility', 'cascade']) !== []) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
if (!array_key_exists('visibility', $body) || !is_string($body['visibility'])) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$want = $body['visibility'];
/* 허용 목록 **정확 일치**. `published` 는 이 목록에 없으므로 여기서 400 이다 —
   DB CHECK 에는 네 값이 그대로 있지만 화면·API 는 셋만 받는다 (지시서 「범위 밖」). */
if (!in_array($want, CommunityAuthz::VISIBILITIES, true)) {
    Http::error(400, Http::MSG_BAD_REQUEST);
}
$cascade = false;
if (array_key_exists('cascade', $body)) {
    if (!is_bool($body['cascade'])) {
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    $cascade = $body['cascade'];
}

// ─── [2] 행 + 공간 ────────────────────────────────────────────
$row = community_page_row($pid, (int) $user['id']);
if ($row === null) {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$pageId    = (int) $row['id'];
$pageTitle = community_target_title((string) $row['title']);
$from      = (string) $row['visibility'];

// ─── [권한 ✔ A] 볼 수 있나 ────────────────────────────────────
[$mayRead, $readCode] = CommunityAuthz::decidePageRead($user, $row);
if (!$mayRead) {
    if ($readCode === CommunityAuthz::CODE_NOT_FOUND) {
        Http::error(404, Http::MSG_NOT_FOUND);
    }
    if ($readCode === CommunityAuthz::CODE_NOT_OWNER) {
        Audit::deny('page.read', CommunityAuthz::CODE_NOT_OWNER, [
            'target_type' => 'page',
            'target_id'   => $pageId,
            'target_name' => $pageTitle,
            'detail'      => ['page_title' => $pageTitle],
        ]);
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID
        . ' community page.read deny code=' . $readCode . ' (4-B 에 없는 조합)');
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [권한 ✔ B] 소유자만 ──────────────────────────────────────
/* 범위를 바꾸는 일은 **고치기**이고, 개인 공간에서 고치는 사람은 주인 하나다.
   팀·사내로 열어 둔 페이지를 본 사람이 그 범위를 더 넓히거나 닫을 수 있으면, 주인은
   자기 글이 어디까지 퍼졌는지 알 수 없게 된다 (C:T-32). */
[$mayChange, $changeCode] = CommunityAuthz::decidePageManage($user, $row);
$isOwnerOfPersonal = $mayChange && $changeCode === CommunityAuthz::CODE_OWNER;
if (!$isOwnerOfPersonal) {
    Audit::deny('page.visibility', CommunityAuthz::CODE_NOT_OWNER, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'page_title'     => $pageTitle,
            'visibility_from' => $from,
            'visibility_to'  => $want,
        ],
    ]);
    Http::error(403, Http::MSG_FORBIDDEN);
}

// ─── [3] 팀 공간의 페이지는 자기 범위가 없다 ──────────────────
if ((string) $row['space_kind'] !== 'personal') {
    Http::json([
        'error'  => '팀 공간의 페이지는 공간 등급을 따릅니다',
        'reason' => 'space_governed',
    ], 400);
    exit;
}

/**
 * 규칙 위반 기록 후 끝낸다 — 상태는 언제나 400 이고 DB 는 한 글자도 바뀌지 않았다.
 *
 * `$extra` 는 **응답에만** 싣는다(감사 `detail` 에는 넣지 않는다). 감사 등록부(ARCH 7.5)에
 * 없는 칸을 즉흥으로 늘리지 않으려는 것이고, 남아야 할 사실 — 누가·어떤 페이지를·어디서
 * 어디로 바꾸려 했나 — 는 아래 세 칸으로 이미 남는다.
 */
$refuse = static function (string $reason, string $message, array $extra) use ($pageId, $pageTitle, $from, $want): void {
    Audit::deny('page.visibility', $reason, [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'page_title'      => $pageTitle,
            'visibility_from' => $from,
            'visibility_to'   => $want,
        ],
    ]);
    Http::json(array_merge(['error' => $message, 'reason' => $reason], $extra), 400);
    exit;
};

/* ─── [3-1] 게시 중인 페이지 (5-C) ──────────────────────────────
   게시 중에는 범위를 바꾸지 않는다 — 게시를 먼저 푼다. 게시판에 걸린 채로 원래 범위가 바뀌면
   「게시판에서 이 글을 누가 보나」와 「풀면 누가 보나」가 동시에 바뀌어 주인이 따라가기 어렵다. */
if ((string) $row['visibility'] === 'published') {
    Http::json([
        'error'  => '게시 중인 페이지입니다. 공개 범위를 바꾸려면 먼저 게시를 해제하세요',
        'reason' => 'published',
    ], 400);
    exit;
}

// ─── [4] 넓히기 — 부모보다 넓을 수 없다 (C:T-33) ──────────────
$parentVisibility = null;
if ($row['parent_page_id'] !== null) {
    $sqlParent = <<<'SQL'
        SELECT p.visibility, p.published_from
          FROM community.pages p
         WHERE p.id = CAST(:pid AS bigint)
           AND p.space_id = CAST(:space AS bigint)
           AND p.deleted_at IS NULL
        SQL;
    $parentRow = $db->run($sqlParent, [
        ':pid'   => (int) $row['parent_page_id'],
        ':space' => (int) $row['space_id'],
    ])->fetch();
    // 부모가 지워졌으면 견줄 상대가 없다 — 뿌리와 같이 다룬다(경로도 거기서 끊긴다).
    if (is_array($parentRow)) {
        // 5-C — 게시 중인 부모는 **기준 범위**(게시 전 범위)로 견준다. 게시는 하위를 넓히지 않는다.
        $parentVisibility = CommunityAuthz::baseVisibility($parentRow) ?? 'private';
    }
}

if (!CommunityAuthz::fitsUnderParent($want, $parentVisibility)) {
    $refuse(
        CommunityAuthz::CODE_WIDER_THAN_PARENT,
        '상위 페이지보다 넓게 열 수 없습니다. 상위 페이지를 먼저 넓히세요',
        ['parent_visibility' => (string) $parentVisibility]
    );
}

// ─── [5] 좁히기 — 하위에 더 넓은 것이 있나 (C:T-34) ───────────
$wider = community_descendants_wider($pageId, $want);
if ($wider !== [] && !$cascade) {
    $refuse(
        CommunityAuthz::CODE_CHILDREN_WIDER,
        '하위 페이지가 더 넓게 열려 있습니다. 하위까지 함께 바꾸려면 「하위 페이지까지 함께」를 켜세요',
        ['children_wider' => count($wider)]
    );
}

/* ─── [5-1] 첨부 (5-E) — 넓히면 붙인 파일이 새 범위보다 좁아질 수 있다. 이 페이지와, 「하위까지 함께」로
   **넓어지는** 하위 페이지의 파일을 함께 견준다(하위는 같은 공간이라 새 범위의 읽는 사람이 같다 —
   게시 중인 하위의 게시판 쪽은 게시할 때 이미 견주었다). 좁히는 쪽은 언제나 안전하다. ─── */
$visFiles  = Doc::extract(json_decode((string) $row['doc'], true) ?: [])['files'];
// 6-B — 이 페이지가 DB 의 행이면 파일 속성 값도 같은 규칙으로 본다
if ($row['database_id'] !== null) {
    $visFiles = array_merge($visFiles, community_db_row_files($row['properties'], $row['db_schema']));
}
if ($cascade) {
    $wantRank = CommunityAuthz::visibilityRank($want);
    $widened  = [];
    foreach (community_descendants_differ($pageId, $want) as $t) {
        $r = CommunityAuthz::visibilityRank((string) $t['visibility']);
        if ($wantRank !== null && $r !== null && $r < $wantRank) {
            $widened[] = (int) $t['id'];
        }
    }
    if ($widened !== []) {
        $sqlKidDocs = <<<'SQL'
            SELECT p.doc, p.properties, d.schema AS db_schema FROM community.pages p
              LEFT JOIN community.databases d ON d.id = p.database_id
             WHERE p.id = ANY (CAST(:ids AS bigint[])) AND p.deleted_at IS NULL
            SQL;
        foreach ($db->run($sqlKidDocs, [':ids' => '{' . implode(',', $widened) . '}'])->fetchAll() as $k) {
            $visFiles = array_merge($visFiles, Doc::extract(json_decode((string) $k['doc'], true) ?: [])['files']);
            // 6-B — 넓어지는 하위가 DB 의 행이면 파일 속성도
            if ($k['db_schema'] !== null) {
                $visFiles = array_merge($visFiles, community_db_row_files($k['properties'], $k['db_schema']));
            }
        }
        $visFiles = array_values(array_unique($visFiles));
    }
}
$visFiles  = array_values(array_unique($visFiles));
$visRow    = array_merge($row, ['visibility' => $want]);
/* 파일이 많으면(DB 페이지를 행 수천 개와 함께 넓히는 경우) 200개씩 나눠 본다 — 5,000 을 넘으면 따로 알린다(독립 검토 6-B M-3) */
if (count($visFiles) > 5000) {
    $refuse('too_many_files', '붙은 파일이 너무 많아 한 번에 넓힐 수 없습니다. 하위 페이지를 나눠서 넓히세요', []);
}
$visOk = true;
foreach (array_chunk($visFiles, 200) as $chunk) {
    if (!community_file_refs_ok($chunk, CommunityAuthz::pageAudiences($visRow), $user)) {
        $visOk = false;
        break;
    }
}
if (!$visOk) {
    // 디버깅 묶음 U3 — 게시 거부와 같은 말투의 고정 문장(무엇을 하면 되는지를 먼저). 거부·사유 코드·감사는 그대로
    $refuse('file_narrower', '이 페이지(하위 포함)에 새 공개 범위의 사람이 볼 수 없는 그림이나 파일이 들어 있습니다(파일 등급이 더 좁음). 자료실에서 그 파일의 등급을 올리거나, 페이지에서 빼고 다시 바꾸세요', []);
}

// ─── [6] 저장 ─────────────────────────────────────────────────
$sqlSet = <<<'SQL'
    UPDATE community.pages
       SET visibility = :vis,
           updated_at = now(),
           last_edited_by = CAST(:me AS bigint)
     WHERE id = CAST(:pid AS bigint)
       AND deleted_at IS NULL
    SQL;
// 이 페이지 자신은 **첨부를 견준 그 판**일 때만 바꾼다 — 사이에 본문이 저장됐으면 409 (5-E 독립 검토 L1)
$sqlSetSelf = <<<'SQL'
    UPDATE community.pages
       SET visibility = :vis,
           updated_at = now(),
           last_edited_by = CAST(:me AS bigint)
     WHERE id = CAST(:pid AS bigint)
       AND deleted_at IS NULL
       AND doc_version = CAST(:dv AS int)
    SQL;

// 켜져 있으면 넓히든 좁히든 **값이 다른 하위 전부**를 같은 값으로 (더 넓은 것 ⊂ 다른 것).
$targets  = $cascade ? community_descendants_differ($pageId, $want) : [];
$cascaded = count($targets);

$db->begin();
try {
    /* ─── [5-2] Q:F-12 U3-1 (커뮤니티 ARCH 24.4) — 민감정보 표시가 붙은 문서 첨부가 있으면 「사내」로 넓히지 않는다(자료실 규칙과 같은 문장).
       이 페이지와 「하위까지 함께」로 바뀌는 하위를 **먼저 잠그고** 본다 — 첨부 올리기도 같은 행을 잠근 뒤 넓이를 다시 보므로
       「넓히기 검사 뒤에 민감 첨부가 끼어드는」 겹침이 없다. 감사 사유는 파일 갈래와 같은 `file_narrower`(detail.cause 로 구분). ─── */
    if ($want === 'internal') {
        $sensIds = array_values(array_unique(array_merge([$pageId], array_map(static fn (array $t): int => (int) $t['id'], $targets))));
        sort($sensIds);
        $db->run('SELECT 1 FROM community.pages WHERE id = ANY (CAST(:ids AS bigint[])) ORDER BY id FOR NO KEY UPDATE',
            [':ids' => '{' . implode(',', $sensIds) . '}']);
        if (community_attachments_sensitive($sensIds)) {
            $db->rollback();
            Audit::deny('page.visibility', 'file_narrower', [
                'target_type' => 'page',
                'target_id'   => $pageId,
                'target_name' => $pageTitle,
                'detail'      => [
                    'page_title'      => $pageTitle,
                    'visibility_from' => $from,
                    'visibility_to'   => $want,
                    'cause'           => 'sensitive_level_required',
                ],
            ]);
            Http::json(['error' => Http::MSG_SENSITIVE_LEVEL_REQUIRED, 'reason' => 'sensitive_level_required'], 400);
            exit;
        }
    }

    $changed = $db->run($sqlSetSelf, [':vis' => $want, ':me' => $me, ':pid' => $pageId, ':dv' => (int) $row['doc_version']])->rowCount();
    if ($changed < 1) {
        // 읽고 나서 여기 오기까지 사이에 다른 요청이 먼저 지웠거나(→ 없는 것과 같다) 본문을 저장했다(→ 409 다시 시도).
        $db->rollback();
        if (community_page_row((string) $row['public_id'], $me) !== null) {
            Http::json(['error' => '그 사이 이 페이지가 바뀌었습니다. 새로 고친 뒤 다시 바꾸세요', 'reason' => 'changed'], 409);
            exit;
        }
        Http::error(404, Http::MSG_NOT_FOUND);
    }

    /* 하위까지 함께 (cascade). **항목마다 감사**다 — 「다섯 개가 함께 닫혔다」가 아니라
       「무엇과 무엇이 무엇에서 무엇으로 바뀌었나」가 남아야 나중에 되짚을 수 있다.
       `cascaded` 는 이 한 번의 작업에서 함께 바뀐 하위 수이고, 부모 행과 자식 행이 같은
       값을 갖는다 — 같은 작업에서 나온 행들을 그 수로 묶어 볼 수 있다. */
    if ($cascade) {
        /* 5-C — 게시 중인 하위는 게시를 그대로 두고 **기준 범위**(게시 전 범위)만 맞춘다.
           게시판에 걸린 글을 부모 범위를 바꾸다가 말없이 내리지 않는다(내리는 일은 게시 해제 한 곳). */
        $sqlSetBase = <<<'SQL'
            UPDATE community.pages
               SET published_from = :vis,
                   updated_at = now(),
                   last_edited_by = CAST(:me AS bigint)
             WHERE id = CAST(:pid AS bigint)
               AND deleted_at IS NULL
               AND visibility = 'published'
            SQL;
        foreach ($targets as $child) {
            $db->run(($child['is_published'] ?? false) ? $sqlSetBase : $sqlSet,
                [':vis' => $want, ':me' => $me, ':pid' => $child['id']]);

            $childTitle = community_target_title($child['title']);
            Audit::allow('page.visibility', [
                'target_type' => 'page',
                'target_id'   => $child['id'],
                'target_name' => $childTitle,
                'detail'      => [
                    'granted_by'      => $changeCode,
                    'page_title'      => $childTitle,
                    'visibility_from' => $child['visibility'],
                    'visibility_to'   => $want,
                    'cascaded'        => $cascaded,
                ],
            ]);
        }
    }

    Audit::allow('page.visibility', [
        'target_type' => 'page',
        'target_id'   => $pageId,
        'target_name' => $pageTitle,
        'detail'      => [
            'granted_by'      => $changeCode,
            'page_title'      => $pageTitle,
            'visibility_from' => $from,
            'visibility_to'   => $want,
            'cascaded'        => $cascaded,
        ],
    ]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollback();
    throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
}

Http::json([
    'page' => [
        'id'         => $pid,
        'visibility' => $want,
    ],
    'cascaded' => $cascaded,
]);
