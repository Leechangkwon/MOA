<?php
declare(strict_types=1);

/**
 * src/community/anon_helpers.php — 익명게시판의 「화면에서만 익명」 (사이트 Q:F-12 카페 C1 · 커뮤니티 ARCHITECTURE 25.4 · 25.6)
 *
 * 원칙(ARCH 25.1)
 *   · DB · 감사 표에는 실제 행위자(author_id · actor_id · actor_name)가 그대로 남는다. 가림은 **응답을 만드는 자리**에서만 한다.
 *   · 판정 · 감사 기록 · 총량 계산에는 가린 값을 쓰지 않는다 — 이 파일의 함수는 응답 모양만 바꾸고 판정을 하지 않는다
 *     (설문 익명 `forms_helpers.php` 의 `community_form_redact_metadata` 와 같은 규칙).
 *   · 익명 여부는 `community_page_is_anon()` **하나**가 정한다(목록 SQL 은 같은 뜻의 조각 `community_anon_page_sql()` — 바로 아래 한 자리).
 *   · 관리자 · 게시판 관리자도 평소엔 별칭만 본다. 실제 이름은 신고 → 작성자 확인(report_reveal.php) 한 길로만 나온다.
 *
 * 이름표 규칙 하나(`community_anon_label`): 글쓴이(page.author_id) → 「글쓴이」 · 별칭 번호 N>0 → 「익명N」 · 그 밖 → 「익명」.
 * 사람 객체는 `{username:null, display_name:<이름표>, anon:true, is_me:bool}` 로 **바꿔 끼운다**(키 모양 그대로 — 화면의
 * `display_name || username` 이 그대로 돈다). 사용자 id 는 싣지 않는다 — 본인도 `is_me` 만.
 */

/** 익명게시판 글인가 — 이 페이지가 걸린 게시판(게시판 글이면 그 공간, 게시된 개인 페이지면 게시한 게시판)의 종류가 anonymous. */
function community_page_is_anon(array $pageRow): bool
{
    $board = CommunityAuthz::boardOfRow($pageRow);
    return $board !== null && (string) ($board['board_kind'] ?? '') === 'anonymous';
}

/**
 * 같은 뜻의 SQL 조각(목록 `WHERE` 용 — 목록은 SQL 로 거른다). 고정 문자열 — 페이지 별칭 `p` · 그 공간 별칭 `s` 를 쓰는 질의에만 붙인다.
 * `community_page_is_anon` 과 짝: 게시판 글 → 그 공간 종류 · 게시 중인 개인 페이지 → 게시한 게시판 종류.
 */
function community_anon_page_sql(): string
{
    return <<<'SQL'
        ((s.kind = 'board' AND s.board_kind = 'anonymous') OR (p.visibility = 'published' AND EXISTS (
            SELECT 1 FROM community.spaces anon_pb
             WHERE anon_pb.id = p.published_board_id AND anon_pb.kind = 'board' AND anon_pb.board_kind = 'anonymous')))
        SQL;
}

/** 이름표 규칙 하나 — 글쓴이 · 익명N · 익명 */
function community_anon_label(bool $isWriter, ?int $aliasNo): string
{
    if ($isWriter) {
        return '글쓴이';
    }
    return $aliasNo !== null && $aliasNo > 0 ? '익명' . (string) $aliasNo : '익명';
}

/**
 * 한 글의 이름표 지도 — 목록 하나에 **질의 하나**(N+1 0). 키는 사용자 번호(서버 안에서만 — 응답에 싣지 않는다).
 *
 * @param  list<int> $userIds
 * @return array<int,string>
 */
function community_anon_label_map(int $pageId, int $authorId, array $userIds): array
{
    $ids = array_values(array_unique(array_map('intval', $userIds)));
    $map = [];
    foreach ($ids as $id) {
        $map[$id] = community_anon_label($id === $authorId, null);
    }
    $others = array_values(array_filter($ids, static fn (int $id): bool => $id !== $authorId));
    if ($others === []) {
        return $map;
    }
    $sql = <<<'SQL'
        SELECT user_id, alias_no FROM community.anon_aliases
         WHERE page_id = CAST(:pid AS bigint)
           AND user_id = ANY (string_to_array(CAST(:ids AS text), ',')::bigint[])
        SQL;
    foreach (Db::instance()->run($sql, [':pid' => $pageId, ':ids' => implode(',', $others)])->fetchAll() as $r) {
        $map[(int) $r['user_id']] = community_anon_label(false, (int) $r['alias_no']);
    }
    return $map;
}

/**
 * 사람 객체를 이름표로 — `{username:null, display_name:'익명2', anon:true, is_me}`. `$userId` 가 null 이면(탈퇴 · 숨김 자리) null.
 * `$map` 을 주면 그 지도로(질의 0), 없으면 이 한 사람만 묻는다.
 *
 * @param array<int,string>|null $map community_anon_label_map 의 결과
 */
function community_anon_person(int $pageId, int $authorId, ?int $userId, int $viewerId, ?array $map = null): ?array
{
    if ($userId === null) {
        return null;
    }
    $map ??= community_anon_label_map($pageId, $authorId, [$userId]);
    return [
        'username'     => null,
        'display_name' => $map[$userId] ?? community_anon_label($userId === $authorId, null),
        'anon'         => true,
        'is_me'        => $userId === $viewerId,
    ];
}

/**
 * 별칭 번호 주기 — 글쓴이는 0, 그 글에 처음 댓글을 쓰는 사람마다 다음 번호. **부르는 쪽의 트랜잭션 안**에서 부른다.
 * 동시에 써도 겹치지 않게 글 행을 잠근 뒤(`COMMUNITY_PAGE_LOCK_SQL`) 다음 번호를 넣는다. 이미 있으면 그대로(번호는 바뀌지 않는다).
 */
function community_anon_assign_alias(int $pageId, int $authorId, int $userId): void
{
    $db = Db::instance();
    if ($userId === $authorId) {
        $db->run(
            'INSERT INTO community.anon_aliases (page_id, user_id, alias_no) VALUES (CAST(:pid AS bigint), CAST(:uid AS bigint), 0)'
            . ' ON CONFLICT DO NOTHING',
            [':pid' => $pageId, ':uid' => $userId]
        );
        return;
    }
    $db->run(COMMUNITY_PAGE_LOCK_SQL, [':pid' => $pageId]);
    $sql = <<<'SQL'
        INSERT INTO community.anon_aliases (page_id, user_id, alias_no)
        SELECT CAST(:pid AS bigint), CAST(:uid AS bigint), coalesce(max(a.alias_no), 0) + 1
          FROM community.anon_aliases a
         WHERE a.page_id = CAST(:pid2 AS bigint)
        ON CONFLICT (page_id, user_id) DO NOTHING
        SQL;
    $db->run($sql, [':pid' => $pageId, ':uid' => $userId, ':pid2' => $pageId]);
}

/** 안전망이 지우지 않는 이름표 글자(위에서 바꿔 끼운 값) */
function community_anon_is_label(mixed $value): bool
{
    return is_string($value) && preg_match('/^(글쓴이|익명[0-9]*|관리자|익명게시판|익명\(익명게시판\))$/u', $value) === 1;
}

/**
 * **안전망** — 위에서 바꿔 끼운 뒤 단일 글 응답 전체를 재귀로 훑는다. `username` 값은 null · 주인 · 검증 · 편집 중 ·
 * 공간 주인 칸은 null/[] · 사람 칸(설문 익명의 키 목록)은 이름표가 아니면 null. `doc` · `values` · `properties` 하위는
 * 건너뛴다(본문은 글쓴이가 쓴 것 — ARCH 25.4).
 *
 * @param  array<mixed> $payload
 * @return array<mixed>
 */
function community_anon_scrub(array $payload): array
{
    static $personKeys = ['by', 'by_display_name', 'author', 'author_id', 'author_name', 'author_display_name',
        'last_edited_by', 'last_edited_by_name', 'last_edited_by_display_name', 'writer', 'accepted_by', 'decided_by',
        'actor', 'actor_id', 'actor_name', 'actor_display_name', 'from_display_name', 'to_display_name'];
    foreach ($payload as $key => $value) {
        if (!is_string($key)) {
            if (is_array($value)) {
                $payload[$key] = community_anon_scrub($value);
            }
            continue;
        }
        if ($key === 'username') {
            $payload[$key] = null;
        } elseif ($key === 'editors') {
            $payload[$key] = [];
        } elseif (in_array($key, ['owner', 'verify', 'granted_by', 'verified_by'], true) || str_starts_with($key, 'space_owner')) {
            $payload[$key] = null;
        } elseif (in_array($key, $personKeys, true)) {
            if (is_array($value) && ($value['anon'] ?? false) === true) {
                $payload[$key] = community_anon_scrub($value);
            } elseif (!community_anon_is_label($value)) {
                $payload[$key] = null;
            }
        } elseif (is_array($value) && !in_array($key, ['doc', 'values', 'properties'], true)) {
            $payload[$key] = community_anon_scrub($value);
        }
    }
    return $payload;
}

/**
 * 감사 detail 표식(ARCH 25.6) — 익명게시판 글 · 댓글이 대상인 커뮤니티 감사 호출은 `anon_board: true` 를 싣는다.
 * 감사 화면(`src/handlers/audit_list.php`)이 이 표식으로 행위자 · IP · 로그인 회차를 가리고, 누가 · 어디서 필터에서 뺀다.
 *
 * @param  array<string,mixed> $pageRow `community_page_row()` 모양(게시판 글이면 space_kind · space_board_kind 만으로도 된다)
 * @param  array<string,mixed> $detail
 * @return array<string,mixed>
 */
function community_anon_audit_detail(array $pageRow, array $detail): array
{
    return community_page_is_anon($pageRow) ? $detail + ['anon_board' => true] : $detail;
}
