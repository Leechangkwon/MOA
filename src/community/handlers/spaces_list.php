<?php
declare(strict_types=1);

/**
 * src/community/handlers/spaces_list.php — GET /community/api/spaces (정책: community.read)
 *
 * 요구사항: `C:F-17`(팀 공간은 t-box 활성 팀마다 자동), `C:S-02`,
 *           `../../../../community/ARCHITECTURE.md` 13.1 · 13.3 · 21.2, 수락 기준 C:T-36.
 *
 * 하는 일
 *   내가 쓸 수 있는 공간을 돌려준다 — **내 개인 공간 + 내 팀 공간**.
 *   팀 공간이 아직 없으면 **그 자리에서 만든다**(내 팀에 한해). 이름은 t-box 팀 이름 그대로다.
 *
 * 왜 GET 이 만드나 (`space_me.php` 와 같은 판단, ARCH 13.3)
 *   팀 공간은 팀마다 정확히 하나이고 내용이 없는 그릇이다. 「팀 공간 만들기」 단추를 두면
 *   그 단추가 하는 일은 「우리 팀 공간을 열어라」뿐이고, 누가 먼저 눌렀느냐가 뜻을 갖는다.
 *   두 번 불러도 결과가 같고(멱등 — C:T-36 이 행 수 불변을 본다), 같은 팀의 두 사람이 같은
 *   순간에 열어도 공간이 두 벌이 되지 않는다. 그것을 지키는 것은 앱의 순서가 아니라 부분
 *   UNIQUE 인덱스 `spaces_team_uidx`(`UNIQUE(team_id) WHERE kind='team'`)다 —
 *   동시 요청은 23505 로 걸리고 아래에서 다시 읽는다.
 *
 * ★ 대상 팀은 **세션이 들고 있는 유효 팀**(`$user['team_id']`)뿐이다. 요청 본문도 쿼리도
 *   읽지 않는다 — 「어느 팀 공간을 만들까」를 요청이 고르는 길이 없다 (PRD S-01).
 * ★ 비활성 팀(`teams.is_active = false`)에는 공간을 만들지 않는다. 쓰지 않기로 한 팀에
 *   새 그릇을 여는 것은 정리가 아니라 되살리기다.
 * ★ 팀이 없는 사람(Admin·Guest)에게는 개인 공간만 돌려준다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$db     = Db::instance();
$me     = (int) $user['id'];
$myTeam = $user['team_id'] === null ? null : (int) $user['team_id'];

$spaces  = [];
$created = false;

/* ─── [1] 내 개인 공간 ─────────────────────────────────────────
 * 여기서는 **만들지 않는다** — 개인 공간을 만드는 자리는 `GET /api/me/space` 하나다.
 * 같은 일을 두 곳에서 하면 「시작하기」 페이지가 한쪽에만 붙는 날이 온다. */
$personal = community_my_personal_space($me);
if ($personal !== null) {
    $spaces[] = [
        'id'        => (int) $personal['id'],
        'kind'      => 'personal',
        'name'      => (string) $personal['name'],
        'team_id'   => null,
        'can_write' => Authz::canCommunityWrite($user),   // S2 — 손님은 쓰기 창구에 닿지 못한다(전에는 늘 true)
    ];
}

/* ─── [2] 내 팀 공간 — 없으면 만든다 ────────────────────────── */
if ($myTeam !== null) {
    $sqlFind = <<<'SQL'
        SELECT s.id, s.name, s.team_id
          FROM community.spaces s
         WHERE s.kind = 'team' AND s.team_id = CAST(:tid AS bigint)
        SQL;

    $teamSpace = $db->run($sqlFind, [':tid' => $myTeam])->fetch();

    if (!is_array($teamSpace)) {
        // 팀 이름은 t-box 표에서 그대로 읽는다(커뮤니티가 팀 이름을 따로 갖지 않는다).
        $sqlTeam = <<<'SQL'
            SELECT id, name, is_active FROM public.teams WHERE id = CAST(:tid AS bigint)
            SQL;
        $team = $db->run($sqlTeam, [':tid' => $myTeam])->fetch();

        if (is_array($team) && $team['is_active'] === true) {
            $teamName = (string) $team['name'];
            // `spaces.name` 은 1~50자다. 팀 이름이 그보다 길면 앞에서 자른다 —
            // 여기서 자르지 않으면 CHECK 에 걸려 500 이 된다 (`space_me.php` 와 같은 이유).
            if (iconv_strlen($teamName, 'UTF-8') > 50) {
                $teamName = (string) iconv_substr($teamName, 0, 50, 'UTF-8');
            }

            $sqlInsert = <<<'SQL'
                INSERT INTO community.spaces
                    (kind, team_id, name, description, access_level, created_by)
                VALUES
                    ('team', CAST(:tid AS bigint), :name, :description, 'team', CAST(:by AS bigint))
                RETURNING id, name, team_id
                SQL;

            $db->begin();
            try {
                $teamSpace = $db->run($sqlInsert, [
                    ':tid'         => $myTeam,
                    ':name'        => $teamName,
                    ':description' => '팀이 함께 쓰는 공간입니다.',
                    ':by'          => $me,
                ])->fetch();
                if (!is_array($teamSpace)) {
                    throw new RuntimeException('팀 공간을 만들지 못했습니다');
                }

                // 기록이 실패하면 공간도 남지 않는다 (PRD S-07, 같은 트랜잭션).
                Audit::allow('space.create', [
                    'target_type' => 'space',
                    'target_id'   => (int) $teamSpace['id'],
                    'target_name' => (string) $teamSpace['name'],
                    'detail'      => ['granted_by' => CommunityAuthz::CODE_TEAM_MEMBER],
                ], CommunityAuthz::CODE_TEAM_MEMBER);

                $db->commit();
                $created = true;
            } catch (PDOException $e) {
                $db->rollback();
                // 23505 = unique_violation — 같은 팀의 다른 사람이 아주 조금 먼저 열었다.
                // 먼저 만든 쪽이 이겼을 뿐이므로 다시 읽어서 그 공간을 돌려준다.
                if ($e->getCode() === '23505') {
                    $teamSpace = $db->run($sqlFind, [':tid' => $myTeam])->fetch();
                    if (!is_array($teamSpace)) {
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
    }

    if (is_array($teamSpace)) {
        $spaces[] = [
            'id'        => (int) $teamSpace['id'],
            'kind'      => 'team',
            'name'      => (string) $teamSpace['name'],
            'team_id'   => (int) $teamSpace['team_id'],
            // 팀 공간은 그 팀원이면 함께 고친다 (PRD 4.1 「팀 공간의 페이지는 공간 등급을 따른다」).
            'can_write' => Authz::canCommunityWrite($user),   // S2 — 같은 까닭
        ];
    }
}

/* ─── [3] 페이지 수 — 공간마다 한 번이 아니라 **한 질의로** ───
 * 공간이 둘뿐이라도 N+1 을 만들지 않는다(4-A 트리 질의와 같은 규칙).
 * 자리표시자 이름은 개수로 만든 코드 문자열이고 값은 전부 바인딩된다 (PRD S-04). */
if ($spaces !== []) {
    $names = [];
    $bind  = [];
    foreach ($spaces as $i => $space) {
        $name        = ':s' . (string) $i;
        $names[]     = 'CAST(' . $name . ' AS bigint)';
        $bind[$name] = $space['id'];
    }
    $sqlCount = 'SELECT space_id, count(*) AS n FROM community.pages'
        . ' WHERE deleted_at IS NULL'
        . ' AND space_id IN ('
        . implode(', ', $names)
        . ') GROUP BY space_id';

    $counts = [];
    foreach ($db->run($sqlCount, $bind)->fetchAll() as $r) {
        $counts[(int) $r['space_id']] = (int) $r['n'];
    }
    foreach ($spaces as $i => $space) {
        $spaces[$i]['page_count'] = $counts[$space['id']] ?? 0;
    }
}

Http::json([
    'spaces' => $spaces,
    // 이번 호출에서 팀 공간을 **새로 만들었나** (C:T-36 은 두 번째 호출이 거짓임을 본다).
    'created' => $created,
]);
