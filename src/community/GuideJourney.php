<?php
declare(strict_types=1);

/**
 * 과업 길잡이 — 사람 × 여정 진행 상태 (사이트 Q:F-15 ③ · community ARCHITECTURE 26장 · DESIGN 2.37 · 지시서 docs/qf15-s3-guide-directive.md B)
 *
 * 표시 선호 — 권한 · 내용에 영향 0. 이 표는 「이 사람이 어느 과업을 어디까지 해 봤나」만 담는다(결과 내용 0).
 * 완성은 **서버 사실**로만 — 시작 뒤 내가 만든 결과 1건 이상(`fact()` 여섯 질의). 사람 확인(「했어요」)으로 끝내는 길은 1차에 없다.
 *
 * ★ 자격은 Authz 함수 값만 — 역할 이름을 견주는 코드가 이 파일에 없다(`allowed()`). 런처(모아 홈 과업 카드)와 GET 창구가 이 함수 하나를 쓴다.
 * ★ 사용자 id 는 언제나 세션 사용자(`$u['id']`) — 요청 본문에서 사람을 받지 않는다(`validate()` 가 user_id 키를 400 으로 막는다).
 * ★ SQL 은 heredoc + 바인딩만 · 시각은 모두 DB `now()`(PHP 시계 0) · 감사 0(옛 사용 안내 창구와 같은 표시 선호).
 */
final class GuideJourney
{
    /** 여정 id → 화면 수. 여기 없는 id 는 어디서도 받지 않는다 — public/assets/usage-guide.js 의 `journeys` 키와 같아야 한다(수락 [static]). */
    public const JOURNEYS = [
        'task-add'         => 1,
        'file-share'       => 1,
        'minutes-write'    => 2,
        'notice-post'      => 3,
        'message-send'     => 1,
        'invite-colleague' => 1,
    ];
    public const STATUSES = ['active', 'paused', 'completed', 'dismissed'];
    public const STEP_MAX = 30;
    /** 완성 요청인데 서버 사실이 거짓일 때(400) — 화면은 이 글자를 그대로 「아직 결과가 없어요」 카드에 쓴다 */
    public const MSG_NO_RESULT = '아직 결과가 없어요 — 위 단계를 하고 다시 보기를 눌러 주세요';
    /** 두 탭이 동시에 시작해 「사람당 진행 중 하나」에 두 번 걸렸을 때(409) */
    public const MSG_BUSY = '다른 창에서 안내를 시작했어요. 다시 열어 주세요.';

    public static function ready(): bool
    {
        return (bool) Db::instance()->run("SELECT to_regclass('community.guide_journeys') IS NOT NULL")->fetchColumn();
    }

    /**
     * 여정 id → 이 사람이 그 과업을 시작할 수 있나. **Authz 함수 값만**(역할 이름 0).
     *
     * @param array<string,mixed> $u 세션 사용자(라우터 · 런처가 DB 에서 읽은 값)
     * @return array<string,bool>
     */
    public static function allowed(array $u): array
    {
        $write = Authz::canCommunityWrite($u);
        return [
            'task-add'         => $write,
            'file-share'       => Authz::uploadableLevels($u) !== [],
            'minutes-write'    => $write,
            // S3-1 A — 공지 게시판에 쓸 수 있는 사람에게만. 공지 쓰기 규칙 CommunityAuthz::decideBoardWrite 의
            // `board_kind = 'notice'` 갈래가 Authz::canAdmin 이다 — 같은 함수를 쓴다(한쪽만 바뀌면 수락 [static] 이 실패한다).
            'notice-post'      => $write && Authz::canAdmin($u),
            // 런처 $launcherMessenger · messenger_window.php 와 같은 판정(쓰기 자격 + 「역할로 보기」가 아님)
            'message-send'     => $write && ($u['acting'] ?? false) !== true,
            'invite-colleague' => Authz::canManageUsers($u),
        ];
    }

    /**
     * 홈 맨 위 「하던 일 이어 하기」 줄 — 내 진행 중 여정, 없으면 가장 최근에 멈춘 여정 하나(그 여정을 지금 할 수 있을 때만).
     *
     * @param array<string,mixed> $u
     */
    public static function resume(array $u): ?string
    {
        $sql = <<<'SQL'
            SELECT journey
              FROM community.guide_journeys
             WHERE user_id = :uid AND status IN ('active', 'paused')
             ORDER BY (status = 'active') DESC, updated_at DESC
             LIMIT 1
            SQL;
        $journey = Db::instance()->run($sql, [':uid' => (int) $u['id']])->fetchColumn();
        if (!is_string($journey) || !array_key_exists($journey, self::JOURNEYS)) {
            return null;
        }
        return (self::allowed($u)[$journey] ?? false) === true ? $journey : null;
    }

    /**
     * 본문 검사 — 키는 정확히 {journey, status, page, step} + 선택 {restart}. 실패는 InvalidArgumentException(창구 400).
     *
     * @param array<mixed> $body
     * @return array{journey:string,status:string,page:int,step:int,restart:bool}
     */
    public static function validate(array $body): array
    {
        $keys = array_keys($body);
        $required = ['journey', 'status', 'page', 'step'];
        foreach ($keys as $key) {
            if (!is_string($key) || !in_array($key, ['journey', 'status', 'page', 'step', 'restart'], true)) {
                throw new InvalidArgumentException('guide_journey_keys');
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $body)) {
                throw new InvalidArgumentException('guide_journey_keys');
            }
        }
        $journey = $body['journey'];
        if (!is_string($journey) || !array_key_exists($journey, self::JOURNEYS)) {
            throw new InvalidArgumentException('guide_journey_id');
        }
        if (!in_array($body['status'], self::STATUSES, true)) {
            throw new InvalidArgumentException('guide_journey_status');
        }
        $page = $body['page'];
        $step = $body['step'];
        if (!is_int($page) || $page < 0 || $page >= self::JOURNEYS[$journey]) {
            throw new InvalidArgumentException('guide_journey_page');
        }
        if (!is_int($step) || $step < 0 || $step > self::STEP_MAX) {
            throw new InvalidArgumentException('guide_journey_step');
        }
        if (array_key_exists('restart', $body) && !is_bool($body['restart'])) {
            throw new InvalidArgumentException('guide_journey_restart');
        }
        return ['journey' => $journey, 'status' => (string) $body['status'], 'page' => $page, 'step' => $step,
            'restart' => ($body['restart'] ?? false) === true];
    }

    /**
     * 내 여정 상태 — `{journeys: {active, paused, completed}, rows}`(community ARCH 26.5). 내 행만 한 번 읽고,
     * 진행 중이 있으면 그 여정의 사실을 한 번 더 잰다.
     *
     * @param array<string,mixed> $u
     * @return array{journeys:array{active:?array<string,mixed>,paused:list<array<string,mixed>>,completed:list<array<string,mixed>>},rows:int}
     */
    public static function state(array $u): array
    {
        $uid = (int) $u['id'];
        $sql = <<<'SQL'
            SELECT journey, status, page, step, started_at, completed_at
              FROM community.guide_journeys
             WHERE user_id = :uid
             ORDER BY coalesce(completed_at, updated_at) DESC, journey
            SQL;
        $rows = Db::instance()->run($sql, [':uid' => $uid])->fetchAll();
        $active = null;
        $paused = [];
        $completed = [];
        foreach ($rows as $r) {
            $journey = (string) $r['journey'];
            if (!array_key_exists($journey, self::JOURNEYS)) {
                continue;
            }
            $status = (string) $r['status'];
            if ($status === 'active') {
                $active = ['journey' => $journey, 'status' => 'active', 'page' => (int) $r['page'], 'step' => (int) $r['step'],
                    'started_at' => (string) $r['started_at']];
            } elseif ($status === 'paused') {
                $paused[] = ['journey' => $journey, 'page' => (int) $r['page'], 'step' => (int) $r['step']];
            } elseif ($status === 'completed') {
                $completed[] = ['journey' => $journey, 'completed_at' => (string) $r['completed_at']];
            }
        }
        if ($active !== null) {
            $active['fact'] = self::fact($uid, $active['journey'], $active['started_at']);
        }
        return ['journeys' => ['active' => $active, 'paused' => $paused, 'completed' => $completed], 'rows' => count($rows)];
    }

    /**
     * 완성 사실 — 시작 시각 뒤 내가 만든 결과 1건 이상(community ARCH 26.4 표 그대로 · 전부 바인딩 · 내 행만).
     * 문서 · 업무 게시판 글은 가장 최근 public_id 를 ref 로(「이 화면으로 가기」 링크 · 화면 판정 /p/<ref>).
     *
     * @return array{ok:bool,ref:?string}
     */
    public static function fact(int $uid, string $journey, string $since): array
    {
        $db = Db::instance();
        $bind = [':uid' => $uid, ':since' => $since];
        switch ($journey) {
            case 'task-add':
                $sql = <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM community.pages
                         WHERE author_id = :uid AND status IS NOT NULL AND deleted_at IS NULL
                           AND created_at >= CAST(:since AS timestamptz))
                    SQL;
                return ['ok' => $db->run($sql, $bind)->fetchColumn() === true, 'ref' => null];
            case 'file-share':
                $sql = <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM public.files
                         WHERE uploaded_by = :uid AND deleted_at IS NULL
                           AND created_at >= CAST(:since AS timestamptz))
                    SQL;
                return ['ok' => $db->run($sql, $bind)->fetchColumn() === true, 'ref' => null];
            case 'minutes-write':
                // 「내 할 일」 자동 프로젝트 · 새 프로젝트(데이터베이스 페이지)와 그 행(작업) · 게시판 글은 빠진다
                $sql = <<<'SQL'
                    SELECT p.public_id
                      FROM community.pages p
                      JOIN community.spaces s ON s.id = p.space_id
                     WHERE p.author_id = :uid AND p.status IS NULL AND p.database_id IS NULL
                       AND NOT EXISTS (SELECT 1 FROM community.databases d WHERE d.page_id = p.id)
                       AND s.kind <> 'board' AND p.deleted_at IS NULL
                       AND p.created_at >= CAST(:since AS timestamptz)
                     ORDER BY p.created_at DESC, p.id DESC
                     LIMIT 1
                    SQL;
                $ref = $db->run($sql, $bind)->fetchColumn();
                return ['ok' => is_string($ref), 'ref' => is_string($ref) ? $ref : null];
            case 'notice-post':
                $sql = <<<'SQL'
                    SELECT p.public_id
                      FROM community.pages p
                      JOIN community.spaces s ON s.id = p.space_id
                     WHERE p.author_id = :uid AND s.kind = 'board' AND s.area = 'work' AND p.deleted_at IS NULL
                       AND p.created_at >= CAST(:since AS timestamptz)
                     ORDER BY p.created_at DESC, p.id DESC
                     LIMIT 1
                    SQL;
                $ref = $db->run($sql, $bind)->fetchColumn();
                return ['ok' => is_string($ref), 'ref' => is_string($ref) ? $ref : null];
            case 'message-send':
                // 모아 AI 대화는 다른 표(member_ai_*)라 세지 않는다 — 동료에게 보낸 메시지만
                $sql = <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM public.member_messages
                         WHERE sender_id = :uid AND sent_at >= CAST(:since AS timestamptz))
                    SQL;
                return ['ok' => $db->run($sql, $bind)->fetchColumn() === true, 'ref' => null];
            case 'invite-colleague':
                $sql = <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1 FROM public.member_invites
                         WHERE created_by = :uid AND created_at >= CAST(:since AS timestamptz))
                    SQL;
                return ['ok' => $db->run($sql, $bind)->fetchColumn() === true, 'ref' => null];
        }
        return ['ok' => false, 'ref' => null];
    }

    /**
     * 진행 상태를 바꾼다 — 한 트랜잭션(community ARCH 26.3 표). 끝에 `state()` 를 돌려준다.
     *
     * @param array<string,mixed> $u
     * @param array<mixed>        $body
     * @return array{journeys:array<string,mixed>,rows:int}
     */
    public static function save(array $u, array $body): array
    {
        $b = self::validate($body);
        if ((self::allowed($u)[$b['journey']] ?? false) !== true) {
            throw new GuideJourneyRefused(Http::MSG_FORBIDDEN, 403);
        }
        $uid = (int) $u['id'];
        $db = Db::instance();
        for ($attempt = 0; ; $attempt++) {
            $db->begin();
            try {
                self::apply($uid, $b);
                $db->commit();
                break;
            } catch (PDOException $e) {
                $db->rollback();
                // 부분 유니크(사람당 진행 중 하나) · 열쇠(사람 × 여정) 위반 — 두 탭이 동시에 시작했다. 한 번 다시, 또 나면 409
                if ((string) $e->getCode() === '23505') {
                    if ($attempt === 0) {
                        continue;
                    }
                    throw new GuideJourneyRefused(self::MSG_BUSY, 409);
                }
                throw $e;
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
        }
        return self::state($u);
    }

    /** @param array{journey:string,status:string,page:int,step:int,restart:bool} $b */
    private static function apply(int $uid, array $b): void
    {
        $db = Db::instance();
        $lock = <<<'SQL'
            SELECT journey, status, started_at
              FROM community.guide_journeys
             WHERE user_id = :uid
               FOR UPDATE
            SQL;
        $row = null;
        foreach ($db->run($lock, [':uid' => $uid])->fetchAll() as $r) {
            if ((string) $r['journey'] === $b['journey']) {
                $row = $r;
            }
        }
        $now = $row === null ? null : (string) $row['status'];
        $target = $b['status'];
        $bind = [':uid' => $uid, ':journey' => $b['journey'], ':page' => $b['page'], ':step' => $b['step']];

        if ($target === 'active') {
            // 같은 사람의 다른 진행 중은 멈춤으로(사람당 진행 중 하나) — 새 문장이라 다른 탭이 방금 넣은 행도 본다
            $pause = <<<'SQL'
                UPDATE community.guide_journeys
                   SET status = 'paused', updated_at = now()
                 WHERE user_id = :uid AND status = 'active' AND journey <> :journey
                SQL;
            $db->run($pause, [':uid' => $uid, ':journey' => $b['journey']]);
            if ($now === null) {
                $insert = <<<'SQL'
                    INSERT INTO community.guide_journeys (user_id, journey, status, page, step)
                    VALUES (:uid, :journey, 'active', :page, :step)
                    SQL;
                $db->run($insert, $bind);
                return;
            }
            if ($now === 'active' || $now === 'paused') {
                if ($b['restart']) {
                    // 「처음부터」 — 시작 시각을 새로 찍고 0쪽 0단계부터
                    $sql = <<<'SQL'
                        UPDATE community.guide_journeys
                           SET status = 'active', page = 0, step = 0, started_at = now(), updated_at = now(), completed_at = NULL
                         WHERE user_id = :uid AND journey = :journey
                        SQL;
                    $db->run($sql, [':uid' => $uid, ':journey' => $b['journey']]);
                    return;
                }
                // 화면을 오가거나 홈 카드를 다시 눌러도 시작 시각은 그대로(바뀌면 완성이 영영 안 된다)
                $sql = <<<'SQL'
                    UPDATE community.guide_journeys
                       SET status = 'active', page = :page, step = :step, updated_at = now()
                     WHERE user_id = :uid AND journey = :journey
                    SQL;
                $db->run($sql, $bind);
                return;
            }
            // completed · dismissed → 다시 하기: 시작 시각을 새로 · 완성 시각 비움
            $restartBind = $b['restart'] ? [':uid' => $uid, ':journey' => $b['journey'], ':page' => 0, ':step' => 0] : $bind;
            $sql = <<<'SQL'
                UPDATE community.guide_journeys
                   SET status = 'active', page = :page, step = :step, started_at = now(), updated_at = now(), completed_at = NULL
                 WHERE user_id = :uid AND journey = :journey
                SQL;
            $db->run($sql, $restartBind);
            return;
        }

        // 행이 없거나 끝낸 · 그만둔 행에서 멈춤 · 그만둠 · 완성은 받지 않는다
        if ($now !== 'active' && $now !== 'paused') {
            throw new InvalidArgumentException('guide_journey_state');
        }
        if ($target === 'completed') {
            $fact = self::fact($uid, $b['journey'], (string) $row['started_at']);
            if ($fact['ok'] !== true) {
                throw new GuideJourneyRefused(self::MSG_NO_RESULT, 400);
            }
            $sql = <<<'SQL'
                UPDATE community.guide_journeys
                   SET status = 'completed', page = :page, step = :step, completed_at = now(), updated_at = now()
                 WHERE user_id = :uid AND journey = :journey
                SQL;
            $db->run($sql, $bind);
            return;
        }
        // paused · dismissed — 화면 · 단계를 남긴다
        $sql = <<<'SQL'
            UPDATE community.guide_journeys
               SET status = :status, page = :page, step = :step, updated_at = now()
             WHERE user_id = :uid AND journey = :journey
            SQL;
        $db->run($sql, $bind + [':status' => $target]);
    }
}

/** 길잡이 창구가 상태 코드와 함께 돌려줄 거절(403 자격 · 400 결과 없음 · 409 동시 시작) — 메시지는 이 파일의 고정 글자만 */
final class GuideJourneyRefused extends RuntimeException
{
}
