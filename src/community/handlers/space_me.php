<?php
declare(strict_types=1);

/**
 * src/community/handlers/space_me.php — GET /community/api/me/space (정책: community.read)
 *
 * 요구사항: `C:F-17` 최소(개인 공간 자동 생성), 기획 ③(비공개 시작 · 「시작하기」 페이지),
 *           `../../../../community/ARCHITECTURE.md` 13.3 · 21.3.
 *
 * 하는 일
 *   내 개인 공간을 돌려준다. **없으면 만든다** — 공간 하나 + 「시작하기」 페이지 하나를
 *   한 트랜잭션 안에서 만들고 감사 `space.create` 를 남긴다.
 *
 * 왜 GET 이 만드나 (ARCH 13.3 의 설계 그대로)
 *   개인 공간은 사람마다 정확히 하나이고, 내용이 없는 그릇이다. 「만들기」 단추를 따로 두면
 *   누구나 첫 방문에서 그 단추를 눌러야 하고, 그 단추가 하는 일은 「내 공간을 열어라」뿐이다.
 *   두 번 불러도 결과가 같고(멱등), 같은 창을 둘 열어도 공간이 두 벌이 되지 않는다 —
 *   그것을 지키는 것은 앱의 순서가 아니라 부분 UNIQUE 인덱스
 *   `spaces_personal_owner_uidx` 다(동시 요청은 23505 로 걸리고 아래에서 다시 읽는다).
 *
 * 대상은 언제나 **세션 본인**이다. 요청 본문도 쿼리도 읽지 않는다 (PRD S-01).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$db = Db::instance();
$me = (int) $user['id'];

$sqlFind = <<<'SQL'
    SELECT id, name, kind, created_at
      FROM community.spaces
     WHERE kind = 'personal' AND owner_user_id = CAST(:uid AS bigint)
    SQL;

$sqlInsertPage = <<<'SQL'
    INSERT INTO community.pages
        (space_id, title, doc, doc_version, plain_text, search_tsv, block_count,
         author_id, last_edited_by, visibility, position)
    VALUES
        (CAST(:space AS bigint), :title, CAST(:doc AS jsonb), 1, :plain,
         to_tsvector('simple', :tsv), CAST(:blocks AS int),
         CAST(:author AS bigint), CAST(:editor AS bigint), 'private', 0)
    RETURNING id, public_id
    SQL;

$row     = $db->run($sqlFind, [':uid' => $me])->fetch();
$created = false;
$startPage = null;

if (!is_array($row)) {
    /* 공간 그릇은 `community_create_personal_space()` 가 만든다 (5-A 로 뽑아냈다 — 인계가 받는
       사람의 공간을 만들 때 **같은 문장**을 쓴다). 이름 규칙(계정명 40자 + 「 의 공간」)도 거기 있다. */

    // 본문은 코드에 적힌 고정 문서다(요청 값 0). 저장 전에 **우리 검증기를 그대로** 지난다 —
    // 서버가 만든 문서라고 검사를 건너뛰면, 검증기와 생성기가 어긋나는 날을 알아채지 못한다.
    // 대기열 10번 A.5(2026-09-24) — 쓸 수 없는 계정(Guest)에는 읽는 방법만 적은 판을 준다.
    $doc = Authz::canCommunityWrite($user) ? Doc::welcomeDoc() : Doc::welcomeDocReadOnly();
    $check = Doc::validate($doc, COMMUNITY_DOC_STAGE);
    if (!$check['ok']) {
        throw new RuntimeException('「시작하기」 문서가 스키마를 통과하지 못했습니다');
    }
    $extract = Doc::extract($doc);
    $pageTitle = '시작하기';

    $docJson = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($docJson === false) {
        throw new RuntimeException('「시작하기」 문서를 저장할 수 없습니다');
    }

    $db->begin();
    try {
        $spaceRow = community_create_personal_space($me, (string) $user['username'], $me);
        if ($spaceRow === null) {
            /* 같은 사람이 창 둘을 거의 동시에 열었다(또는 그 사이 인계가 공간을 먼저 만들었다) —
               먼저 만든 쪽이 이겼을 뿐이므로, 되돌리고 다시 읽어서 그 공간을 돌려준다. */
            $db->rollback();
            $row = $db->run($sqlFind, [':uid' => $me])->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('개인 공간을 만들지 못했습니다');
            }
        } else {
            $spaceId   = (int) $spaceRow['id'];
            $spaceName = (string) $spaceRow['name'];

            $pageRow = $db->run($sqlInsertPage, [
                ':space'  => $spaceId,
                ':title'  => $pageTitle,
                ':doc'    => $docJson,
                ':plain'  => $extract['plain_text'],
                ':tsv'    => $pageTitle . ' ' . $extract['plain_text'],
                ':blocks' => count($extract['blocks']),
                ':author' => $me,
                ':editor' => $me,
            ])->fetch();
            if (!is_array($pageRow)) {
                throw new RuntimeException('「시작하기」 페이지를 만들지 못했습니다');
            }

            community_rewrite_derived((int) $pageRow['id'], $extract);

            // 기록이 실패하면 공간도 페이지도 남지 않는다 (PRD S-07, 같은 트랜잭션).
            Audit::allow('space.create', [
                'target_type' => 'space',
                'target_id'   => $spaceId,
                'target_name' => $spaceName,
                'detail'      => [
                    'granted_by' => CommunityAuthz::CODE_OWNER,
                    'page_title' => $pageTitle,
                ],
            ]);

            $db->commit();

            $row       = $spaceRow;
            $created   = true;
            $startPage = ['id' => (string) $pageRow['public_id'], 'title' => $pageTitle];
        }
    } catch (PDOException $e) {
        $db->rollback();
        // 23505 = unique_violation — 같은 사람이 창 둘을 거의 동시에 열었다.
        // 먼저 만든 쪽이 이겼을 뿐이므로, 다시 읽어서 그 공간을 돌려준다.
        if ($e->getCode() === '23505') {
            $row = $db->run($sqlFind, [':uid' => $me])->fetch();
            if (!is_array($row)) {
                throw $e;
            }
        } else {
            throw $e;
        }
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;   // bootstrap 예외 핸들러 → 500 + {"error":"서버 오류"}
    }
}

$sqlCount = <<<'SQL'
    SELECT count(*) AS n
      FROM community.pages
     WHERE space_id = CAST(:space AS bigint) AND deleted_at IS NULL
    SQL;

$countRow = $db->run($sqlCount, [':space' => (int) $row['id']])->fetch();

Http::json([
    'space' => [
        'id'         => (int) $row['id'],
        'name'       => (string) $row['name'],
        'kind'       => (string) $row['kind'],
        'created_at' => (string) $row['created_at'],
        'page_count' => is_array($countRow) ? (int) $countRow['n'] : 0,
    ],
    // 처음 만들어졌나 — 화면이 「시작하기」 페이지를 바로 열어 줄지 고르는 값이다.
    'created'    => $created,
    'start_page' => $startPage,
]);
