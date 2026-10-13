<?php
declare(strict_types=1);

/**
 * src/community/CommunityAuthz.php — 커뮤니티의 **행 단위** 권한 판정
 *
 * 요구사항: 커뮤니티 `C:S-01`·`C:S-13`, `../../../community/ARCHITECTURE.md` 13.2 · 21.2,
 *           수락 기준 C:T-17(개인 공간 격리).
 *
 * 자리 — 왜 `Authz` 본체가 아니라 여기인가
 *   `Authz.php` 는 t-box 의 권한 매트릭스를 판단하는 파일이고, 그 파일은 이 단계에서 세 줄만
 *   늘었다(정책 이름 둘 + `canCommunityWrite`). 페이지·공간의 소유 규칙은 커뮤니티 것이므로
 *   모듈 폴더 안에 둔다. 그래도 **역할을 판단하는 코드는 여기에 없다** — 역할이 필요한 자리는
 *   전부 `Authz::canAdmin()`·`Authz::canCommunityWrite()` 를 부른다. 역할 문자열을 이 파일에서
 *   직접 비교하면 판정이 두 벌이 되고, 그날부터 한쪽만 고쳐진다 (t-box ARCH 4.1 과 같은 원칙).
 *
 * 지금까지 열린 범위 (3단계 → 4-A → **4-B**)
 *   · 공간 종류 둘: `personal`(사람마다 하나) · `team`(t-box 활성 팀마다 하나). `board` 는 5단계다.
 *   · 개인 공간 페이지의 **공개 범위** 셋 (PRD 4.1):
 *       `private`  소유자 + Admin(`admin_all`, 그 열람은 감사에 남는다)
 *       `team`     소유자의 t-box 팀과 같은 팀(`same_team`, 감사에 남는다)
 *       `internal` 로그인 전원 — **Guest 제외**(`Authz::canCommunityWrite`, 코드 `matrix`)
 *     넷째 값 `published` 는 DB CHECK 에만 남아 있고 **판정은 닫힌 실패**다(`role_not_permitted`) —
 *     게시판 공간(`kind='board'`)이 아직 없기 때문이고, 5단계에서 게시판과 함께 열린다.
 *   · 팀 공간은 **그 팀원이면 읽기·쓰기**(`team_member`) · Admin 읽기(`admin_all`) · 그 밖 `not_member`.
 *     팀 공간 페이지에는 자기 범위가 없다 — 공간 등급을 따른다(PRD 4.1 「범위 없음」).
 *   · 고치기는 개인 공간에서 **범위와 무관하게 소유자만**이다. 「보이는 것」과 「고치는 것」은
 *     다른 물음이다 (C:T-32).
 *   · 트리 불변 규칙: 자식의 범위는 부모보다 **넓을 수 없다**(`fitsUnderParent`).
 *
 * 5-A (2026-09-23 사용자 결정 — PRD NG-8 해제) — **물음이 셋으로 갈린다**
 *   · `decidePageRead`   볼 수 있나. 개인 공간에 **공유 갈래**(`shared`)가 하나 더 붙었다 —
 *                        이 페이지나 그 조상에 **나에게 준** 공유 줄이 있고, 내 계정이 활성이면 참.
 *   · `decidePageChange` 내용을 고칠 수 있나(저장·할 일 체크·상태·하위 페이지 만들기·되돌리기).
 *                        소유자 · **편집 공유**(`shared_edit`, 쓰기 자격 있는 역할만) · 팀 공간 팀원.
 *   · `decidePageManage` 관리할 수 있나(삭제·옮기기·되살리기·공개 범위·공유·인계). 소유자 ·
 *                        팀 공간 팀원뿐이다 — **편집 공유로는 안 된다**. 공유받은 사람이 페이지를
 *                        지우거나 남에게 다시 열어 주면 주인은 자기 글이 어디로 갔는지 모른다.
 *   · `decidePageHandover` 인계를 시작할 수 있나 — 소유자 · 자기 팀원 것의 Manager · Admin(재인증).
 *   공유 등급은 판정 함수가 DB 를 읽어 찾지 않는다. `community_page_row()` 가 재귀 CTE 로
 *   **그 사람 몫의 가장 강한 등급**을 `share_level` 에, **누구 몫인지**를 `share_user_id` 에
 *   실어 오고, 판정은 두 값이 지금 묻는 사람과 맞을 때만 쓴다(다른 사람을 사용자 자리에 놓고
 *   물어도 남의 공유가 새지 않는다). 목록 SQL `community_visible_pages_where()` 는 **같은 SQL
 *   조각**(`community_share_rank_sql()`)으로 같은 갈래를 한 줄 더한다 (C:T-37 방식).
 *
 * 5-A″ (2026-09-23 사용자 결정 「팀 공간 페이지도 공유 — ㅇㅇ」) — **팀 공간에도 공유 갈래**
 *   · 팀 공간 페이지의 사람 지정 공유가 열린다. 관리(주기·빼기) 자격은 `decidePageManage` 그대로 —
 *     그 팀원이다. 읽기·고치기의 공유 갈래는 개인 공간과 **같은 `shareLevelFor`** 이고, 팀 공간 [1]
 *     갈래 안에서 **팀원 판정 뒤**에 온다(팀원이면 공유와 무관하게 이미 읽고 쓴다).
 *   · 공개 범위는 팀 공간에 여전히 없다(공간 등급을 따른다) — 그래서 개인 공간의 「세 범위만」
 *     조건이 팀 공간 갈래에는 없다. 목록 조건절도 같은 모양이다(`community_visible_pages_where`).
 *   · 인계는 여전히 팀 공간 대상이 아니다(`decidePageHandover` [1] `space_governed`).
 *
 * 5-C (2026-09-23 사용자 결정 — 게시판에서 바로 쓰기 + 개인 페이지 게시, 지시서 `stage5c-board-publish-directive.md`)
 *   · 게시판 공간(`kind='board'`)이 열렸다. 등급은 t-box 파일·폴더와 **같은 네 값**이고 읽기 판정은
 *     t-box `Authz::decideRead` 를 **그대로** 부른다(`decideBoardRead`) — 표를 새로 만들지 않는다.
 *   · 게시판 글(게시판 공간의 페이지): 읽기 = 게시판 등급(`board`) · 쓴 사람(`owner`) ·
 *     숨긴 글은 쓴 사람과 게시판 관리자(`board_moderator`)만. 고치기·지우기 = **쓴 사람만**
 *     (게시판 Manager·Admin 도 내용은 못 고친다 — 사용자 결정, PRD F-18). 비활성 게시판은 읽기 전용.
 *   · 게시된 개인 페이지(`visibility='published'`): 게시판 등급(`board`)과 **원래 범위**
 *     (`published_from`) 중 넓은 쪽 — 게시는 여는 일이다. 숨기면 게시판 갈래만 꺼진다.
 *     원래 범위를 「기준 범위」(`baseVisibility`)라 부르고, 트리 불변 규칙도 이 값으로 견준다.
 *   · 새 물음 넷: `decideBoardRead` · `decideBoardWrite`(새 글·게시) · `decideBoardModerate`
 *     (게시 해제·숨김 — 팀 게시판의 그 팀 Manager · Admin) · `decideUnpublish`(쓴 사람 또는 관리자).
 *
 * ★ 역할 비교는 여전히 이 파일에 없다. 팀 판정은 **역할이 아니라 id 비교**이고, 값은 전부
 *   세션(`$u['team_id']` — `Auth` 가 「역할로 보기」까지 반영해 만든 유효 팀)과 DB 행에서 온다.
 *   요청이 정하는 값은 한 칸도 없다 (PRD S-01).
 *
 * 돌려주는 값은 t-box `Authz::decide*()` 와 같은 모양 `[bool, 코드]` 다.
 * 허용 코드는 감사 `detail.granted_by` 로, 거부 코드는 `reason` 으로 그대로 기록된다.
 */
final class CommunityAuthz
{

    /** Stage 12: group visibility and delegation are centralized, no role checks in handlers. */
    public static function decideGroupRead(array $u,array $g): bool
    {
        return ($g['deleted_at'] ?? null)===null && (Authz::authorize('role:Admin',$u)
            || (int)$g['created_by']===(int)$u['id'] || ($g['is_member'] ?? false)===true);
    }
    public static function decideGroupManage(array $u,array $g): bool
    {
        return ($g['deleted_at'] ?? null)===null && (Authz::authorize('role:Admin',$u) || (int)$g['created_by']===(int)$u['id']);
    }
    public static function decideGroupMember(array $u,array $g,array $target): bool
    {
        if (!self::decideGroupManage($u,$g) || !($target['is_active'] ?? false)) { return false; }
        if (Authz::authorize('role:Admin',$u)) { return true; }
        return Authz::authorize('role:Manager+',$u) && ((int)$target['id']===(int)$u['id']
            || ($u['team_id']!==null && (int)$target['team_id']===(int)$u['team_id'] && (int)$g['manager_team_id']===(int)$u['team_id']));
    }
    public static function decideWebPublish(array $u,array $row): array
    {
        if (!($row['web_publish_allowed'] ?? false)) { return [false,'not_allowed']; }
        if (($row['space_kind'] ?? '')==='board' || ($row['database_id'] ?? null)!==null) { return [false,'invalid_target']; }
        return self::decidePageManage($u,$row);
    }

    /** Export never turns exceptional administrator visibility into a bulk data grant. */
    public static function decidePageExport(array $u, array $row): array
    {
        [$allowed, $reason] = self::decidePageRead($u, $row);
        if (!$allowed) {
            return [false, $reason];
        }
        if (!Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (in_array($reason, [self::CODE_ADMIN_ALL, self::CODE_BOARD_MODERATOR], true)) {
            return [false, 'privileged_only'];
        }
        return [true, $reason];
    }

    /** Stage 11: internal forms expose questions only; privileged-only readers cannot submit. */
    public static function decideFormSubmit(array $u, array $dbPage, array $view): array
    {
        if (($view['type'] ?? null) !== 'form' || ($view['open'] ?? false) !== true
            || ($dbPage['deleted_at'] ?? null) !== null || !Authz::canCommunityWrite($u)) {
            return [false, self::CODE_NOT_FOUND];
        }
        if (($view['audience'] ?? 'readers') === 'internal') {
            return [true, self::CODE_MATRIX];
        }
        [$read, $reason] = self::decidePageRead($u, $dbPage);
        return $read && !in_array($reason, [self::CODE_ADMIN_ALL, self::CODE_BOARD_MODERATOR], true)
            ? [true, $reason] : [false, self::CODE_NOT_FOUND];
    }

    /**
     * Anonymity is recorded on each response, not taken from the current form settings.
     *
     * Q:F-12 (C:R-15 · ARCH 23.3) — 책임자는 **소유자(개인 공간)/작성자(그 밖)만**이다. 전에는 `assignee_id ??` 가 앞에
     * 있어서, 담당을 다시 쓰는 순간 「맡기기」가 양식 응답자 신원 보기 권한이 되었다. 담당은 표시일 뿐 권한이 아니다.
     */
    public static function canSeeFormIdentity(array $u, array $dbPage): bool
    {
        $responsible = ($dbPage['space_kind'] ?? '') === 'personal'
            ? ($dbPage['space_owner_user_id'] ?? null) : ($dbPage['author_id'] ?? null);
        return Authz::canAdmin($u) || ($responsible !== null && (int)$u['id'] === (int)$responsible);
    }

    /** 허용 코드 — 페이지 주인이다 */
    public const CODE_OWNER = 'owner';
    /** 허용 코드 — Admin 이라서 열린다(개인 페이지 열람. 감사에 남는다) */
    public const CODE_ADMIN_ALL = 'admin_all';

    /** 거부 코드 — 남의 개인 페이지다 */
    public const CODE_NOT_OWNER = 'not_owner';
    /** 거부 코드 — 지워졌거나 없는 페이지다 */
    public const CODE_NOT_FOUND = 'not_found';
    /** 거부 코드 — 이 단계가 아직 모르는 조합(게시판 공간·`published` 범위) */
    public const CODE_ROLE_NOT_PERMITTED = 'role_not_permitted';

    /** 허용 코드 — 개인 페이지가 `team` 이고 내가 **소유자와 같은 팀**이다 (4-B, 감사에 남는다) */
    public const CODE_SAME_TEAM = 'same_team';
    /** 허용 코드 — 개인 페이지가 `internal` 이고 내가 **쓰기 자격이 있는 역할**이다(Guest 제외) */
    public const CODE_MATRIX = 'matrix';
    /** 허용 코드 — 팀 공간이고 내가 **그 팀원**이다 (4-B) */
    public const CODE_TEAM_MEMBER = 'team_member';
    /** 거부 코드 — 그 팀 공간의 팀원이 아니다 (4-B) */
    public const CODE_NOT_MEMBER = 'not_member';

    /** 허용 코드 — 이 페이지나 조상에 **나에게 준 공유**가 있다 (5-A, 보기·편집 어느 쪽이든) */
    public const CODE_SHARED = 'shared';
    /** 허용 코드 — **편집 공유**로 내용을 고친다 (5-A). 관리(삭제·옮기기·공유·인계)는 열리지 않는다 */
    public const CODE_SHARED_EDIT = 'shared_edit';
    /** 허용 코드 — Manager 가 **자기 팀원**의 페이지를 인계한다 (5-A, t-box `team_scope` 와 같은 뜻) */
    public const CODE_TEAM_SCOPE = 'team_scope';
    /** 거부 코드 — 팀 공간의 페이지다. 공개 범위·인계는 공간 등급을 따른다 (5-A. 공유는 5-A″ 에서 열렸다) */
    public const CODE_SPACE_GOVERNED = 'space_governed';
    /** 거부 코드 — 공유·인계 대상으로 고를 수 없는 사람이다(없음·비활성·Guest·지금 주인) (5-A) */
    public const CODE_TARGET_NOT_PERMITTED = 'target_not_permitted';

    /**
     * 공유 등급 둘 (5-A). DB CHECK 과 같은 값이고, **넓이 순서**는 view < edit 이다.
     * 모르는 값은 등급이 아니다 — 판정은 이 목록에 정확히 있는 값만 쓴다.
     *
     * @var list<string>
     */
    public const SHARE_LEVELS = ['view', 'edit'];

    /** 허용 코드 — **게시판 등급**으로 읽는다(게시판 글 · 게시된 개인 페이지, 5-C). 감사에 남기지 않는다 */
    public const CODE_BOARD = 'board';
    /** 허용 코드 — 숨긴 글을 **게시판 관리자**(그 팀 Manager · Admin)로서 읽는다 (5-C) */
    public const CODE_BOARD_MODERATOR = 'board_moderator';
    /** 거부 코드 — 게시판 글을 **쓴 사람이 아니다**(고치기·지우기, 5-C). 게시판 관리자도 여기서 막힌다 */
    public const CODE_NOT_AUTHOR = 'not_author';
    /** 거부 코드 — 비활성(보관) 게시판이다. 새 글·게시·고치기가 막힌다 (5-C, 400) */
    public const CODE_BOARD_INACTIVE = 'board_inactive';

    /**
     * 게시판 종류 셋 (5-C, PRD 11-1 ④). DB CHECK(`spaces_board_kind_check`)과 같은 값이다.
     *
     * @var list<string>
     */
    public const BOARD_KINDS = ['notice', 'discussion', 'qa'];

    /** 거부 코드 — 새 범위가 **부모보다 넓다** (4-B, 트리 불변 규칙) */
    public const CODE_WIDER_THAN_PARENT = 'wider_than_parent';
    /** 거부 코드 — 하위에 **더 넓은 페이지**가 있다 (4-B, `cascade` 없이 좁히려 했다) */
    public const CODE_CHILDREN_WIDER = 'children_wider';

    /**
     * 화면·API 가 받는 공개 범위 **셋** (4-B 범위 밖: `published`).
     *
     * DB CHECK 에는 네 값이 그대로 있고 이 목록에만 셋이 있다 — 게시판 공간이 없는 동안
     * `published` 를 받아 두면 「지금은 아무도 볼 수 없는 페이지」가 만들어진다.
     * 5단계에서 게시판과 함께 이 목록에 더한다.
     *
     * @var list<string>
     */
    public const VISIBILITIES = ['private', 'team', 'internal'];

    /**
     * 범위의 **넓이 순서** — 작을수록 좁다 (PRD 4.1 「private < team < internal < published」).
     * `published` 도 자리를 적어 둔다: 값이 DB 에 있을 수 있고, 비교가 그 행 앞에서 멈추면
     * 「모르는 값이라 통과」가 되기 때문이다.
     *
     * @var array<string,int>
     */
    private const VISIBILITY_RANK = [
        'private'   => 0,
        'team'      => 1,
        'internal'  => 2,
        'published' => 3,
    ];

    /** 거부 코드 — 자기 자신 또는 자기 후손을 부모로 삼으려 했다 (4-A) */
    public const CODE_CYCLE = 'cycle';
    /** 거부 코드 — 옮기면 트리가 8 단계보다 깊어진다 (4-A) */
    public const CODE_DEPTH_EXCEEDED = 'depth_exceeded';

    /**
     * 페이지 트리의 최대 깊이 — **뿌리를 1** 로 세어 8 까지다 (4-A 지시서 「규칙」).
     *
     * 왜 한도가 있나: 깊이는 경로(breadcrumb)·트리 화면·재귀 질의가 전부 따라가는 값이다.
     * 한도가 없으면 한 사람이 만든 수천 겹짜리 가지를 모든 화면이 끌고 다닌다. 9 가 되면
     * 400 `depth_exceeded` 로 **거절**한다 — 조용히 잘라 붙이지 않는다.
     */
    public const MAX_DEPTH = 8;

    /**
     * 이 사람이 이 페이지를 **볼 수 있나** (ARCH 13.2 `decidePageRead`, PRD 4.1).
     *
     * `$row` 에 있어야 하는 것 — 핸들러가 `pages` ⋈ `spaces` ⋈ `users`(공간 주인) 로 한 번에
     * 읽어 넘긴다 (`community_page_row()`):
     *   `deleted_at` · `visibility` · `space_kind` · `space_owner_user_id` ·
     *   `space_team_id`(팀 공간의 팀) · `space_owner_team_id`(개인 공간 주인의 t-box 팀)
     *
     * 판정 순서 (하나라도 맞으면 거기서 끝난다)
     *   [0] 지워진 페이지                        → `not_found`
     *   [1] 팀 공간                              → 그 팀원 `team_member` / **나에게 준 공유 `shared`**(5-A″) /
     *                                               Admin `admin_all` / 그 밖 `not_member`
     *   [2] 게시판 글 (5-C)                      → 쓴 사람 `owner` / 숨긴 글은 관리자 `board_moderator` /
     *                                               게시판 등급 `board`(t-box `decideRead`) / 그 밖 t-box 코드
     *   [2b] 개인 공간도 게시판도 아니면          → `role_not_permitted`
     *   [3] 내 페이지                            → `owner`
     *   [4] 기준 범위(게시 중이면 게시 전 범위)가 셋 밖 → `role_not_permitted` (모르면 막는다)
     *   [4a] 게시 중 + 숨기지 않음 + 게시판을 읽을 수 있다 → `board` (5-C — 원래 범위와 넓은 쪽)
     *   [4b] 이 페이지나 조상에 나에게 준 공유    → `shared`   (5-A — 보기·편집 어느 쪽이든 읽기는 열린다)
     *   [5] 기준 범위 `internal` + 쓰기 자격 있는 역할 → `matrix`   (Guest 는 여기서 끝난다)
     *   [6] 기준 범위 `team` + 주인과 같은 팀    → `same_team`
     *   [7] Admin                                → `admin_all` (감사에 남는다)
     *   [7b] 게시 중 + 숨김 + 그 게시판의 관리자 → `board_moderator` (5-C — 다른 갈래로 못 볼 때만)
     *   [8] 그 밖                                → `not_owner`
     *
     * ★ `internal` 을 `canCommunityWrite` 로 판정하는 이유: 「로그인 전원, Guest 제외」는
     *   t-box 권한 매트릭스의 **쓰기 자격과 같은 표**다(PRD 4장 각주). 표를 새로 만들지 않는다.
     * ★ 공유 갈래 [4b] 가 소유자 [3] 바로 뒤가 아니라 [4] 뒤에 있는 이유: 모르는 범위는 공유가 있어도
     *   **닫힌 실패**로 남겨야 목록 SQL(기준 범위 셋만 받는다)과 한 줄씩 짝이 맞는다.
     *   Guest 도 공유를 받으면 **읽기는** 열린다 — 쓰기는 `decidePageChange` 가 다시 막는다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decidePageRead(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }

        // Form responses do not inherit a database's view-only sharing grant.
        if (($row['form_view_id'] ?? null) !== null) {
            [$edit, $reason] = self::decidePageChange($u, $row);
            if ($edit) { return [true, $reason]; }
            if (($row['form_direct_share'] ?? false) === true && (int)($row['share_user_id'] ?? 0) === (int)$u['id']) {
                return [true, self::CODE_SHARED];
            }
            return Authz::canAdmin($u) ? [true, self::CODE_ADMIN_ALL] : [false, self::CODE_NOT_OWNER];
        }

        $kind = (string) ($row['space_kind'] ?? '');

        /* [1] 팀 공간 — 페이지에 자기 범위가 없다. 공간 하나로 판정이 끝난다 (PRD 4.1). */
        if ($kind === 'team') {
            if (self::isTeamMember($u, $row['space_team_id'] ?? null)) {
                return [true, self::CODE_TEAM_MEMBER];
            }
            /* 5-A″ — 팀 밖 사람에게 준 공유(이 페이지나 조상). 팀원 판정 **뒤**, Admin 앞이다 —
               개인 공간 [4b] 가 Admin [7] 앞인 것과 같은 순서라, 공유받은 관리자의 열람은
               `admin_all` 이 아니라 `shared` 로 남는다. 팀 공간에는 범위가 없으므로 범위 조건이 없다. */
            if (self::shareLevelFor($u, $row) !== null) {
                return [true, self::CODE_SHARED];
            }
            if (Authz::canAdmin($u)) {
                return [true, self::CODE_ADMIN_ALL];
            }
            return [false, self::CODE_NOT_MEMBER];
        }

        // [2] 게시판 공간의 글 (5-C) — 게시판 등급 · 쓴 사람 · 숨긴 글.
        if ($kind === 'board') {
            return self::decideBoardPostRead($u, $row);
        }
        // 모르는 공간 종류는 막는다.
        if ($kind !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }

        if (self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }

        /* [4] 기준 범위 (5-C). 게시 중이면 **게시 전 범위**(`published_from`)이고, 아니면 범위 그대로다.
           셋 밖의 값(게시판 없는 `published` 포함)은 모르는 조합이다 — 모르면 막는다. */
        $base = self::baseVisibility($row);
        if ($base === null) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }

        /* [4a] 게시판 갈래 (5-C). 게시된 페이지는 **게시판 등급대로** 열린다 — 원래 범위보다 넓으면
           그만큼 넓어진다(게시는 여는 일이다). 숨기면 이 갈래가 꺼지고 원래 범위만 남는다 —
           그 게시판의 관리자가 숨긴 것을 계속 보는 갈래는 **맨 뒤 [7b]** 다(아래 ★). */
        $pub = (string) ($row['visibility'] ?? '') === 'published' ? self::publishedBoardOf($row) : null;
        if ($pub !== null && ($row['hidden_at'] ?? null) === null && self::decideBoardRead($u, $pub)[0]) {
            return [true, self::CODE_BOARD];
        }
        $visibility = $base;

        /* [4b] 사람 지정 공유 (5-A). 등급은 `community_page_row()` 가 **이 사람 몫으로** 읽어 온
           값이다 — 행에 실린 `share_user_id` 가 지금 묻는 사람과 다르면 그 등급은 없는 것이다. */
        if (self::shareLevelFor($u, $row) !== null) {
            return [true, self::CODE_SHARED];
        }

        if ($visibility === 'internal' && Authz::canCommunityWrite($u)) {
            return [true, self::CODE_MATRIX];
        }
        if ($visibility === 'team' && self::isTeamMember($u, $row['space_owner_team_id'] ?? null)) {
            return [true, self::CODE_SAME_TEAM];
        }

        /* [7] 사용자 결정 2026-09-22(PRD 4.1) — 「회사 자료라 관리자는 볼 수 있다」.
           `private` 을 볼 수 있는 역할이 그보다 넓은 `team` 을 못 본다면 앞뒤가 맞지 않으므로
           세 범위 모두에 같은 갈래를 둔다. 대신 그 열람은 `page.read allow admin_all` 로
           감사에 남는다. Manager 에게는 특권이 없다 — 같은 팀이어도 `private` 은 보이지 않는다. */
        if (Authz::canAdmin($u)) {
            return [true, self::CODE_ADMIN_ALL];
        }

        /* [7b] 숨긴 게시 페이지 + 그 게시판의 관리자(팀 게시판의 그 팀 Manager) → `board_moderator` (5-C).
           ★ 맨 뒤에 두는 이유: 이 갈래가 앞에 있으면 같은 팀·사내로 이미 열리는 사람의 근거가
             `same_team` 대신 이것으로 남고(같은 팀 열람 감사가 빠진다), 관리자가 남의 비공개 원본을 여는
             일이 `admin_all` 감사 없이 지나간다. 다른 갈래로 못 볼 때만 여기까지 온다. */
        if ($pub !== null && ($row['hidden_at'] ?? null) !== null && self::decideBoardModerate($u, $pub)[0]) {
            return [true, self::CODE_BOARD_MODERATOR];
        }

        return [false, self::CODE_NOT_OWNER];
    }

    /** C:F-46: proposal visibility is narrower than ordinary privileged reading. */
    public static function decideSuggestionCreate(array $u, array $row): array
    {
        [$read, $code] = self::decidePageRead($u, $row);
        if (!$read) { return [false, $code]; }
        if (!Authz::canCommunityWrite($u) || in_array($code, [self::CODE_ADMIN_ALL, self::CODE_BOARD_MODERATOR], true)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (($row['space_kind'] ?? '') === 'board' && ($row['space_is_active'] ?? false) !== true) {
            return [false, self::CODE_BOARD_INACTIVE];
        }
        if (self::decidePageChange($u, $row)[0]) { return [false, 'can_edit']; }
        return [true, $code];
    }

    public static function decideSuggestionDecide(array $u, array $row): array
    {
        if (!Authz::canCommunityWrite($u)) { return [false, self::CODE_ROLE_NOT_PERMITTED]; }
        [$read, $code] = self::decidePageRead($u, $row);
        return $read ? self::decidePageChange($u, $row) : [false, $code];
    }

    public static function decideSuggestionView(array $u, array $row, array $suggestion): array
    {
        if (!self::decidePageRead($u, $row)[0]) { return [false, self::CODE_NOT_FOUND]; }
        if (self::decideSuggestionDecide($u, $row)[0]) { return [true, 'editor']; }
        if ((int) ($suggestion['author_id'] ?? 0) === (int) ($u['id'] ?? 0)) { return [true, 'author']; }
        return [false, self::CODE_NOT_FOUND];
    }

    /**
     * 이 사람이 이 페이지의 **내용을 고칠 수 있나** (ARCH 13.2 `decidePageChange`, 5-A 로 좁혀졌다).
     *
     * 「내용」의 범위 (5-A 지시서 표): 본문·제목 저장 · 할 일 체크 · 상태 · **하위 페이지 만들기** ·
     * 지난 판으로 되돌리기 · 「편집 중」 신호. 삭제·옮기기·되살리기·공개 범위·공유·인계는 여기가
     * 아니라 `decidePageManage` 다 — 편집 공유가 생긴 뒤로 둘은 **다른 물음**이다.
     *
     * 개인 공간
     *   소유자                                        → `owner`
     *   이 페이지나 조상에 나에게 준 **편집** 공유    → `shared_edit` — 단 **쓰기 자격 있는 역할만**
     *     (Guest 는 편집 공유를 받아도 보기만이다. 판단은 `Authz::canCommunityWrite` — 역할 문자열 0)
     *   그 밖 (보기 공유·팀·사내로 보고 있는 사람·Admin) → `not_owner`
     * 팀 공간 — **그 팀원 누구나** 고친다(함께 쓰라고 만든 그릇이다). 5-A″ — 팀 밖 사람은
     *   **편집** 공유를 받았고 쓰기 자격 있는 역할이면 `shared_edit`(개인 공간과 같은 규칙).
     *
     * 「볼 수 있다」와 「고칠 수 있다」는 여전히 다른 물음이다 (C:T-32). 팀·사내로 열어 둔 페이지를
     * 관리자나 팀원이 소리 없이 바꿀 수 있으면 그 기록은 더 이상 증거가 아니다 — 고칠 사람은 주인이
     * **이름을 대고 고른 사람**(편집 공유)까지다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decidePageChange(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }

        $kind = (string) ($row['space_kind'] ?? '');

        if ($kind === 'team') {
            if (self::isTeamMember($u, $row['space_team_id'] ?? null)) {
                return [true, self::CODE_TEAM_MEMBER];
            }
            // 5-A″ — 팀원 판정 뒤의 편집 공유 갈래. 읽기 판정 [1] 과 같은 자리·같은 함수다.
            if (self::shareLevelFor($u, $row) === 'edit' && Authz::canCommunityWrite($u)) {
                return [true, self::CODE_SHARED_EDIT];
            }
            return [false, self::CODE_NOT_MEMBER];
        }
        /* 5-C 게시판 글 — 고치는 사람은 **쓴 사람 하나**다. 게시판 Manager·Admin 은 게시 해제·숨김만
           할 수 있고 내용은 고치지 못한다(사용자 결정, PRD F-18). 비활성(보관) 게시판은 읽기 전용이다. */
        if ($kind === 'board') {
            if (!self::isAuthor($u, $row)) {
                return [false, self::CODE_NOT_AUTHOR];
            }
            if (!Authz::canCommunityWrite($u)) {
                return [false, self::CODE_ROLE_NOT_PERMITTED];
            }
            // 보관 중인 게시판은 읽기 전용이다 — 칸이 없으면(모르면) 막는다.
            if (($row['space_is_active'] ?? false) !== true) {
                return [false, self::CODE_BOARD_INACTIVE];
            }
            /* 게시판을 더는 읽을 수 없게 된 쓴 사람(팀을 옮겼다·등급 밖 역할)은 **읽기만** 한다 —
               자기 글이라 열어 보는 것은 되지만(`decidePageRead` [2]), 볼 수 없는 게시판에 글을 고쳐 싣지 않는다. */
            $board = self::boardOfRow($row);
            if ($board === null || !self::decideBoardRead($u, $board)[0]) {
                return [false, self::CODE_ROLE_NOT_PERMITTED];
            }
            return [true, self::CODE_OWNER];
        }
        if ($kind !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }
        /* 편집 공유 (5-A). 읽기 판정과 같은 규칙으로 모르는 범위는 닫는다 — 읽을 수 없는 페이지를
           고칠 수 있는 조합이 생기지 않게 한다. 5-C — 게시 중이면 기준 범위(게시 전 범위)로 본다. */
        if (self::baseVisibility($row) !== null
            && self::shareLevelFor($u, $row) === 'edit'
            && Authz::canCommunityWrite($u)
        ) {
            return [true, self::CODE_SHARED_EDIT];
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /**
     * Q:F-12 U3-1 문서 전용 첨부 — **받기**(커뮤니티 ARCH 24.1). 새 판정이 아니라 두 줄짜리 갈래다:
     *   ① 첨부 행의 `page_id` 가 주소의 문서 행 `id` 와 같은가 — 아니면 `not_found`(남의 문서 첨부를 내 문서 주소로 끌어오기 0)
     *   ② 그 문서의 읽기 판정 `decidePageRead` 를 **그대로** 부른다(공유 · 인계 · 범위 · 팀 이동 · 휴지통은 거기서 끝난다)
     * 관리자 특권 갈래(`admin_all` · `board_moderator`)는 첨부에 열지 않는다 — 지시서 U3-1 ① 「Admin(관리 갈래 꺼짐) 404」 ·
     * 기획 15.5 「비공개 문서 첨부를 Admin 도 못 받음」. 내보내기(`decidePageExport`)와 같은 코드 `privileged_only` 로 닫는다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $pageRow `community_page_row()` 의 행
     * @param  array<string,mixed> $attRow  첨부 행(`page_id` 만 본다)
     * @return array{0:bool,1:string}
     */
    public static function decideAttachmentRead(array $u, array $pageRow, array $attRow): array
    {
        if ((int) ($attRow['page_id'] ?? 0) !== (int) ($pageRow['id'] ?? -1)) {
            return [false, self::CODE_NOT_FOUND];
        }
        [$read, $code] = self::decidePageRead($u, $pageRow);
        if ($read && in_array($code, [self::CODE_ADMIN_ALL, self::CODE_BOARD_MODERATOR], true)) {
            return [false, 'privileged_only'];
        }
        return [$read, $code];
    }

    /**
     * Q:F-12 U3-1 문서 전용 첨부 — **올리기**(커뮤니티 ARCH 24.1). 그 문서의 고치기 판정 `decidePageChange` 그대로 +
     * 쓰기 자격 있는 역할(`Authz::canCommunityWrite` — 라우트 정책 `community.write` 와 같은 표. 손님 자기 공간의 주인 갈래가
     * 참이어도 여기서 닫힌다 — pages_get 의 `can_edit` 와 같은 AND).
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $pageRow
     * @return array{0:bool,1:string}
     */
    public static function decideAttachmentUpload(array $u, array $pageRow): array
    {
        [$change, $code] = self::decidePageChange($u, $pageRow);
        if ($change && !Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        return [$change, $code];
    }

    /**
     * 이 사람이 이 페이지를 **관리할 수 있나** (5-A 새 함수).
     *
     * 관리 = 삭제 · 옮기기 · 되살리기 · 공개 범위 · 공유 추가·바꾸기·빼기 · 인계.
     * 자격은 **소유자**와 **팀 공간 팀원**뿐이다. 편집 공유는 여기서 **거짓**이다 —
     * 내용을 함께 고치라고 연 문을 「지우기·옮기기·남에게 다시 열어 주기」까지 넓히면, 주인은
     * 자기 글이 어디로 갔는지·누가 보는지 알 수 없게 된다(지시서 「판정 — 한 곳에서」 ★).
     *
     * 규칙을 `decidePageChange` 에서 빌리지 않고 **따로 적는다**: 두 물음이 같은 함수의 참·거짓을
     * 나눠 쓰면 편집 공유 갈래가 Change 에 붙는 순간 Manage 에도 새어 들어간다(이번 라운드가 막는
     * 바로 그 사고). 둘은 공유 갈래 하나만 다르다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decidePageManage(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }

        $kind = (string) ($row['space_kind'] ?? '');

        if ($kind === 'team') {
            return self::isTeamMember($u, $row['space_team_id'] ?? null)
                ? [true, self::CODE_TEAM_MEMBER]
                : [false, self::CODE_NOT_MEMBER];
        }
        /* 5-C 게시판 글 — 지우기·되살리기는 **쓴 사람만**(지시서 「삭제는 쓴 사람만」). 옮기기·공개 범위·
           공유·인계는 게시판 글에 뜻이 없다 — 창구들이 공간 종류로 따로 막는다(`space_governed`). */
        if ($kind === 'board') {
            if (!self::isAuthor($u, $row)) {
                return [false, self::CODE_NOT_AUTHOR];
            }
            // 보관 중인 게시판은 읽기 전용 — 지우기·되살리기·해결됨도 막는다(독립 검토 2026-09-23).
            if (($row['space_is_active'] ?? false) !== true) {
                return [false, self::CODE_BOARD_INACTIVE];
            }
            return [true, self::CODE_OWNER];
        }
        if ($kind !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        return self::isOwner($u, $row)
            ? [true, self::CODE_OWNER]
            : [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 사람이 이 페이지의 **사람 지정 공유를 바꿀 수 있나** (5-C 에서 `decidePageManage` 와 갈랐다).
     *
     * 자격은 관리와 같다(소유자 · 팀 공간 팀원). 다른 것은 **게시판 글**뿐이다 — 게시판 글은 게시판
     * 등급이 곧 범위라, 쓴 사람이 사람을 골라 더 열어 주는 갈래가 없다(`space_governed`). 판정·목록
     * SQL 어디에도 게시판 글의 공유 갈래가 없으므로, 줄을 받아 두면 효력 없는 줄만 쌓인다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decidePageShare(array $u, array $row): array
    {
        if ((string) ($row['space_kind'] ?? '') === 'board') {
            return [false, self::CODE_SPACE_GOVERNED];
        }
        return self::decidePageManage($u, $row);
    }

    /**
     * 이 사람이 이 페이지의 **인계를 시작할 수 있나** (5-A, PRD 4.1 「인계(F-28)」 · 11-1 ⑦).
     *
     * 판정 순서 (하나라도 맞으면 거기서 끝난다)
     *   [0] 지워진 페이지                              → `not_found`
     *   [1] 팀 공간의 페이지                           → `space_governed` (소유자가 「팀」이다 — 인계 대상 아님)
     *   [2] 개인 공간이 아니면                         → `role_not_permitted`
     *   [3] 공간의 주인                                → `owner`
     *   [4] Admin                                      → `admin_all` — **재인증**은 부르는 쪽이 세션 값으로 본다
     *   [5] Manager 이고 주인이 **내 팀원**            → `team_scope` (내용은 보지 않는다 — 비열람)
     *   [6] 그 밖(편집 공유자 포함)                    → `not_owner`
     *
     * 「Manager 인가」는 역할 문자열을 비교하지 않고 **라우트 정책의 답**을 빌린다 —
     * `Authz::authorize('role:Manager+', $u)` 는 「Manager 이상인가」이고, Admin 은 [4] 에서 이미
     * 끝났으므로 여기 닿는 참은 Manager 뿐이다 (ARCH 4.1 「역할 판단은 Authz 한 곳」).
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decidePageHandover(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        $kind = (string) ($row['space_kind'] ?? '');
        // 5-C — 게시판 글도 공간이 주인이다(쓴 사람이 바뀌는 일이 아니다).
        if ($kind === 'team' || $kind === 'board') {
            return [false, self::CODE_SPACE_GOVERNED];
        }
        if ($kind !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }
        if (Authz::canAdmin($u)) {
            return [true, self::CODE_ADMIN_ALL];
        }
        if (Authz::authorize('role:Manager+', $u)
            && self::isTeamMember($u, $row['space_owner_team_id'] ?? null)
        ) {
            return [true, self::CODE_TEAM_SCOPE];
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 사람에게 이 페이지를 **넘길 수 있나** — 받는 사람 쪽 조건 (5-A 인계 규칙 표).
     *
     * `$target` 은 `public.users` 한 줄(`id`·`role`·`team_id`·`is_active`)이고, 없으면 `null` 이다.
     *   · 없는 계정 · 비활성 계정 · **쓰기 자격 없는 역할**(Guest) → `target_not_permitted`
     *   · 지금의 주인 자신                                           → `target_not_permitted`
     * 같은 팀일 필요는 없다(사용자 결정 1 — 팀이 해체되면 다른 팀·상급자에게도 넘긴다).
     *
     * @param  array<string,mixed>|null $target
     * @param  array<string,mixed>      $row
     * @return array{0:bool,1:string}
     */
    public static function decideHandoverTarget(?array $target, array $row): array
    {
        if ($target === null || ($target['is_active'] ?? false) !== true) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        if (!Authz::canCommunityWrite(['role' => (string) ($target['role'] ?? '')])) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        $owner = $row['space_owner_user_id'] ?? null;
        if ($owner !== null && (int) $owner === (int) ($target['id'] ?? 0)) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        return [true, self::CODE_OWNER];
    }

    /**
     * 이 사람을 **공유 대상으로 고를 수 있나** (5-A `PUT …/shares/{username}`).
     *
     * 활성 계정이면 누구나 된다 — Guest 도 받을 수 있다(편집을 주어도 판정이 보기로만 연다,
     * `decidePageChange`). 주인 자신에게 주는 공유는 뜻이 없으므로 거부한다.
     * 팀 공간(5-A″)에는 사람 주인이 없다(`space_owner_user_id` 가 NULL) — 그 팀원에게 준 공유는
     * 판정에서 팀원 갈래가 먼저 맞아 **효력이 없을 뿐** 해가 없으므로 따로 막지 않는다
     * (그 사람이 팀을 떠나면 그때부터 공유가 효력을 갖는다 — 준 사람이 고른 그대로다).
     *
     * @param  array<string,mixed>|null $target
     * @param  array<string,mixed>      $row
     * @return array{0:bool,1:string}
     */
    public static function decideShareTarget(?array $target, array $row): array
    {
        if ($target === null || ($target['is_active'] ?? false) !== true) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        $owner = $row['space_owner_user_id'] ?? null;
        if ($owner !== null && (int) $owner === (int) ($target['id'] ?? 0)) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        return [true, self::CODE_OWNER];
    }

    /**
     * 이 사람이 **지워진 페이지를 되살릴 수 있나** (4-C, C:F-29 휴지통).
     *
     * 자격은 `decidePageManage` 와 **같다** (5-A — 되살리기는 관리다. 전에는 `decidePageChange`
     * 를 불렀는데, 편집 공유가 생긴 뒤 그대로 두면 편집 공유자가 휴지통을 뒤질 수 있게 된다).
     * 지운 사람과 되살릴 수 있는 사람이 다르면 「내가 지운 것을 남이 도로 꺼낸다」가 된다.
     * 다른 점은 **딱 하나**: 판정의 대상이 `deleted_at` 이 놓인 행이라 그 갈래를 건너뛴다.
     * 그래서 규칙을 다시 적지 않고 같은 함수를 부른다 — 두 벌이 되면 한쪽만 고쳐진다.
     *
     * 「지워진 상태인가」는 **판정이 아니라 사실 확인**이므로 부르는 쪽(`page_restore.php`)이
     * 본다: 지워지지 않은 페이지에 되살리기를 부르면 404 다(되살릴 것이 없다).
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decidePageRestore(array $u, array $row): array
    {
        $row['deleted_at'] = null;
        return self::decidePageManage($u, $row);
    }

    /**
     * 자식의 범위가 **부모 아래에 들어맞나** (4-B 트리 불변 규칙, PRD 4.1).
     *
     * 규칙 한 줄: `private(0) < team(1) < internal(2) < published(3)` 에서 **자식 ≤ 부모**.
     * 부모가 없으면(뿌리) 비교할 상대가 없으므로 언제나 참이다.
     *
     * ★ 모르는 값은 **거짓**이다. 「모르니까 통과」가 되면 CHECK 에 값이 하나 늘어난 날
     *   트리 불변 규칙이 조용히 풀린다.
     *
     * @param string      $child  자식(또는 옮길 가지에서 가장 넓은) 범위
     * @param string|null $parent 부모의 범위. `null` 이면 뿌리
     */
    public static function fitsUnderParent(string $child, ?string $parent): bool
    {
        if ($parent === null) {
            return true;
        }
        $c = self::VISIBILITY_RANK[$child]  ?? null;
        $p = self::VISIBILITY_RANK[$parent] ?? null;
        if ($c === null || $p === null) {
            return false;
        }
        return $c <= $p;
    }

    /**
     * 범위의 넓이 번호. 모르는 값이면 `null` — 부르는 쪽이 「모른다」를 통과로 바꾸지 못하게
     * 0 을 돌려주지 않는다.
     */
    public static function visibilityRank(string $visibility): ?int
    {
        return self::VISIBILITY_RANK[$visibility] ?? null;
    }

    /**
     * 이 페이지를 **저 부모 아래로 옮길 수 있나** (4-A `decidePageMove`).
     *
     * ★ 이 함수는 **DB 를 읽지 않는다.** 트리를 훑는 일은 질의의 몫이고(핸들러가 재귀 CTE
     *   한 번으로 모아 온다), 여기서는 그 결과를 놓고 **판단만** 한다. 판정과 질의를 섞으면
     *   같은 규칙이 핸들러마다 조금씩 다르게 다시 쓰인다 (t-box ARCH 4.1 과 같은 원칙).
     *
     * `$page` — 옮길 페이지. `community_page_row()` 의 행에 아래 두 칸을 더한 것:
     *   `subtree_height`  자기를 1 로 센 **자기 아래 가지의 높이**(잎이면 1)
     *   `subtree_widest`  옮겨 가는 가지 전체에서 **가장 넓은 범위**(4-B). 자기만이면 자기 범위다
     * `$parent` — 새 부모. `null` 이면 **뿌리로** 올린다. 있으면 같은 행 모양에 두 칸 더:
     *   `depth`          뿌리를 1 로 센 새 부모의 깊이
     *   `ancestor_ids`   새 부모 자신과 그 위 조상들의 `pages.id` 목록
     *
     * 판정 순서 (하나라도 걸리면 거기서 끝난다)
     *   [1] 옮기는 사람이 그 페이지를 **관리**할 수 있나 → `decidePageManage` 그대로 (5-A)
     *       (개인 공간이면 주인만, 팀 공간이면 그 팀원 누구나 — **편집 공유로는 옮기지 못한다**)
     *   [2] 새 부모가 **같은 공간**인가                → 아니면 `not_owner`
     *       (다른 사람 공간의 페이지를 부모로 주면 여기서 막힌다. 존재 여부는 알려 주지 않는다)
     *   [3] 새 부모가 자기 자신이거나 자기 후손인가    → `cycle`
     *       후손 판정은 「내가 새 부모의 조상 목록에 있나」로 한다 — 같은 뜻이고 질의가 한 번이다.
     *   [4] 새 부모 깊이 + 내 가지 높이 > 8            → `depth_exceeded`
     *   [5] 옮겨 가는 가지가 **새 부모보다 넓나**      → `wider_than_parent` (4-B 트리 불변 규칙)
     *       가지 전체의 가장 넓은 값을 본다 — 자기만 맞고 자식이 더 넓으면 옮긴 자리에서 규칙이 깨진다.
     *
     * @param  array<string,mixed>      $u
     * @param  array<string,mixed>      $page
     * @param  array<string,mixed>|null $parent
     * @return array{0:bool,1:string}
     */
    public static function decidePageMove(array $u, array $page, ?array $parent): array
    {
        [$mayChange, $changeCode] = self::decidePageManage($u, $page);
        if (!$mayChange) {
            return [false, $changeCode];
        }
        // 5-C — 게시판 글은 트리가 없다(한 줄 목록). 옮길 자리가 없는 공간이다.
        if ((string) ($page['space_kind'] ?? '') === 'board') {
            return [false, self::CODE_SPACE_GOVERNED];
        }

        $height = (int) ($page['subtree_height'] ?? 1);
        if ($height < 1) {
            $height = 1;
        }

        // 뿌리로 올리기 — 부모가 없으므로 깊이는 내 가지의 높이 그대로이고, 넓이를 견줄 상대도 없다.
        if ($parent === null) {
            return $height > self::MAX_DEPTH
                ? [false, self::CODE_DEPTH_EXCEEDED]
                : [true, $changeCode];
        }

        if (($parent['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        /* 같은 공간이어야 한다. 개인 공간이면 「같은 공간」이 곧 「같은 주인」이고, 팀 공간이면
           「같은 팀」이다 — 위 [1] 이 이미 그 공간을 고칠 수 있는 사람인지 물었으므로 여기서는
           공간이 같은지만 보면 된다. */
        if ((int) ($parent['space_id'] ?? 0) !== (int) ($page['space_id'] ?? -1)) {
            return [false, self::CODE_NOT_OWNER];
        }

        $pageId    = (int) ($page['id'] ?? 0);
        $ancestors = is_array($parent['ancestor_ids'] ?? null) ? $parent['ancestor_ids'] : [];
        foreach ($ancestors as $ancestor) {
            if ((int) $ancestor === $pageId) {
                return [false, self::CODE_CYCLE];
            }
        }

        $parentDepth = (int) ($parent['depth'] ?? 1);
        if ($parentDepth + $height > self::MAX_DEPTH) {
            return [false, self::CODE_DEPTH_EXCEEDED];
        }

        /* [5] 트리 불변 규칙 (4-B). 팀 공간은 페이지에 자기 범위가 없으므로(공간 등급을
           따른다) 이 비교를 하지 않는다 — 같은 공간 안의 이동이라 넓이가 바뀌지 않는다. */
        if ((string) ($page['space_kind'] ?? '') === 'personal') {
            // 5-C — 게시 중인 페이지는 **기준 범위**(게시 전 범위)로 견준다. 가지의 넓이도 SQL 이 같은 값으로 센다.
            $widest = (string) ($page['subtree_widest'] ?? (self::baseVisibility($page) ?? 'published'));
            if (!self::fitsUnderParent($widest, (string) (self::baseVisibility($parent) ?? ''))) {
                return [false, self::CODE_WIDER_THAN_PARENT];
            }
        }

        return [true, $changeCode];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 게시판 (5-C) — 등급은 t-box 파일·폴더와 같은 네 값이고 읽기 판정은 t-box 함수 그대로다
     *
     * 「게시판 행」 모양 — `id`·`kind`·`access_level`·`team_id`·`is_active`·`board_kind`.
     * 게시판 글의 행에서는 `space_*` 칸으로(`boardOfSpace`), 게시된 개인 페이지의 행에서는
     * `pub_*` 칸으로(`publishedBoardOf`) 만든다 — 둘 다 `community_page_row()` 가 싣는 값이다.
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * 이 사람이 **그 게시판을 읽을 수 있나** (5-C, PRD 4장 「게시판 목록 · 글 읽기 · 검색」).
     *
     * 판정은 t-box `Authz::decideRead(user, 게시판 등급)` **그대로**다 — 역할 × 등급 표(`permissions`)
     * 와 팀 규칙을 새로 적지 않는다. 표가 바뀌면(t-box F-25) 게시판도 그대로 따라간다.
     * 비활성(보관) 게시판도 **읽기는 된다**(PRD F-03 「읽기 전용」). 게시판이 아니면 닫는다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $board
     * @return array{0:bool,1:string} 허용이면 `board`, 거부면 t-box 코드(`role_not_permitted`·`team_mismatch`)
     */
    public static function decideBoardRead(array $u, array $board): array
    {
        if ((string) ($board['kind'] ?? '') !== 'board') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        [$ok, $code] = Authz::decideRead($u, [
            'access_level' => (string) ($board['access_level'] ?? ''),
            'team_id'      => $board['team_id'] ?? null,
        ]);
        return $ok ? [true, self::CODE_BOARD] : [false, $code];
    }

    /**
     * 이 사람이 **그 게시판에 새 글을 쓰거나 페이지를 게시할 수 있나** (5-C `decideBoardWrite`).
     *
     * 판정 순서 (하나라도 걸리면 거기서 끝난다)
     *   [1] 읽을 수 있나                 → 아니면 t-box 코드(403) — 못 읽는 게시판이 있다는 것도 알려 주지 않는다
     *   [2] 쓰기 자격 있는 역할인가      → 아니면 `role_not_permitted` (Guest — PRD 4장)
     *   [3] 공지 게시판이면 Admin 인가   → 아니면 `role_not_permitted` (PRD 4장 「공지 게시판 Admin 만」)
     *   [4] 활성 게시판인가              → 아니면 `board_inactive` (창구가 **400** 으로 답한다)
     * 비활성 검사를 맨 뒤에 두는 이유: 쓸 자격이 없는 사람에게는 「보관 중」이라는 사실보다 「권한 없음」이
     * 먼저다(400 과 403 이 섞이면 자격 없는 사람이 게시판 상태를 떠볼 수 있다).
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $board
     * @return array{0:bool,1:string}
     */
    public static function decideBoardWrite(array $u, array $board): array
    {
        [$mayRead, $readCode] = self::decideBoardRead($u, $board);
        if (!$mayRead) {
            return [false, $readCode];
        }
        if (!Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if ((string) ($board['board_kind'] ?? '') === 'notice' && !Authz::canAdmin($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (($board['is_active'] ?? false) !== true) {
            return [false, self::CODE_BOARD_INACTIVE];
        }
        return [true, self::CODE_BOARD];
    }

    /**
     * 이 사람이 **그 게시판의 관리자**인가 — 게시 해제 · 숨김 · 숨김 풀기 (5-C `decideBoardModerate`, PRD F-18).
     *
     *   Admin                                         → `admin_all`
     *   Manager 이고 **팀 등급** 게시판의 그 팀원     → `team_scope` (t-box 파일 5.3 과 같은 뜻)
     *   그 밖                                         → `role_not_permitted`
     * 관리자도 **내용은 고치지 못한다** — 이 함수는 목록에서 내리는 일만 연다(`decidePageChange` 는 따로다).
     * 「Manager 인가」는 역할 문자열이 아니라 라우트 정책의 답을 빌린다(`decidePageHandover` 와 같은 방식).
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $board
     * @return array{0:bool,1:string}
     */
    public static function decideBoardModerate(array $u, array $board): array
    {
        if ((string) ($board['kind'] ?? '') !== 'board') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (Authz::canAdmin($u)) {
            return [true, self::CODE_ADMIN_ALL];
        }
        if (Authz::authorize('role:Manager+', $u)
            && (string) ($board['access_level'] ?? '') === 'team'
            && self::isTeamMember($u, $board['team_id'] ?? null)
        ) {
            return [true, self::CODE_TEAM_SCOPE];
        }
        return [false, self::CODE_ROLE_NOT_PERMITTED];
    }

    /**
     * 이 사람이 이 페이지를 **게시판에 게시할 수 있나** (5-C `POST …/publish`) — 페이지 쪽 물음.
     *
     * 개인 공간의 **주인**만이다(`decidePageManage` 의 `owner`) — 편집 공유자는 못 한다(게시는 여는 일이고,
     * 여는 일은 5-A 부터 관리다). 팀 공간·게시판 글은 공간이 주인이라 `space_governed`.
     * 게시판 쪽 물음(`decideBoardWrite`)은 창구가 따로 묻는다 — 둘 다 참이어야 게시된다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decidePublish(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        $kind = (string) ($row['space_kind'] ?? '');
        if ($kind === 'team' || $kind === 'board') {
            return [false, self::CODE_SPACE_GOVERNED];
        }
        [$may, $code] = self::decidePageManage($u, $row);
        if (!$may || $code !== self::CODE_OWNER) {
            return [false, $may ? self::CODE_NOT_OWNER : $code];
        }
        return [true, self::CODE_OWNER];
    }

    /**
     * 이 사람이 이 페이지의 **게시를 풀 수 있나** (5-C `DELETE …/publish`).
     *
     *   개인 공간의 주인(쓴 사람)            → `owner`
     *   게시한 게시판의 관리자                → `admin_all` · `team_scope` (`decideBoardModerate`)
     *   그 밖                                 → `not_owner`
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decideUnpublish(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        if ((string) ($row['space_kind'] ?? '') === 'personal' && self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }
        $pub = self::publishedBoardOf($row);
        if ($pub !== null) {
            [$ok, $code] = self::decideBoardModerate($u, $pub);
            if ($ok) {
                return [true, $code];
            }
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 사람이 이 글을 **게시판 목록에서 숨기거나 되돌릴 수 있나** (5-C `POST/DELETE …/hide`).
     *
     * 게시판 글이면 그 게시판의, 게시된 개인 페이지면 게시한 게시판의 **관리자**만이다
     * (쓴 사람이 아닌 Employee 는 403 — 지시서 완료 기준). 쓴 사람은 숨기지 않고 지우거나 게시를 푼다.
     * 게시판에 걸려 있지 않은 페이지는 숨길 목록이 없다 — `space_governed`.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decideHide(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        $board = self::boardOfRow($row);
        if ($board === null) {
            return [false, self::CODE_SPACE_GOVERNED];
        }
        [$ok, $code] = self::decideBoardModerate($u, $board);
        return $ok ? [true, $code] : [false, self::CODE_NOT_OWNER];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 댓글 · 멘션 · 공지 읽음 (5-D) — 댓글은 **페이지를 따른다**
     *
     * 읽기는 따로 없다: 그 페이지를 볼 수 있으면(`decidePageRead`) 댓글도 본다. 부르는 쪽은 언제나
     * 페이지 관문(`community_history_gate`)을 먼저 지난 뒤에 아래 함수를 부른다.
     * 「댓글 행」 모양 — `author_id`·`deleted_at`·`parent_comment_id` (DB 한 줄 그대로).
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * 이 페이지에 **댓글을 쓸 수 있나** (5-D, PRD F-08 전례 — 공지 게시판도 댓글은 된다, 결정 D-2).
     *   페이지를 읽을 수 있다(부르는 쪽이 이미 확인) + 쓰기 자격(Guest 제외) + 보관 중인 게시판이 아니다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row 페이지 행
     * @return array{0:bool,1:string}
     */
    public static function decideCommentCreate(array $u, array $row): array
    {
        if (($row['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        if (!Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        $board = self::boardOfRow($row);
        if ($board !== null && ($board['is_active'] ?? false) !== true) {
            return [false, self::CODE_BOARD_INACTIVE];
        }
        return [true, self::CODE_MATRIX];
    }

    /**
     * 이 댓글을 **고치거나 지울 수 있나** — 쓴 사람만(결정 D-4 기본안: 관리자는 숨김만). 지운 댓글은 없는 것.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $comment
     * @return array{0:bool,1:string}
     */
    public static function decideCommentChange(array $u, array $comment): array
    {
        if (($comment['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        if (!self::isAuthor($u, $comment)) {
            return [false, self::CODE_NOT_AUTHOR];
        }
        if (!Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        return [true, self::CODE_OWNER];
    }

    /**
     * 이 페이지의 댓글을 **숨길 수 있나** (결정 D-4 기본안 — Admin + 그 게시판의 관리자). 보존·감사.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row 페이지 행
     * @return array{0:bool,1:string}
     */
    public static function decideCommentHide(array $u, array $row): array
    {
        if (Authz::canAdmin($u)) {
            return [true, self::CODE_ADMIN_ALL];
        }
        $board = self::boardOfRow($row);
        if ($board !== null) {
            [$ok, $code] = self::decideBoardModerate($u, $board);
            if ($ok) {
                return [true, $code];
            }
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 스레드에 **「해결됨」을 켜고 끌 수 있나** (결정 D-5 기본안) — 스레드 첫 댓글을 쓴 사람 ·
     * 페이지를 관리하는 사람(개인 공간 주인 · 팀 공간 팀원 · 게시판 글의 쓴 사람).
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row  페이지 행
     * @param  array<string,mixed> $root 스레드 첫 댓글
     * @return array{0:bool,1:string}
     */
    public static function decideCommentResolve(array $u, array $row, array $root): array
    {
        if (($root['deleted_at'] ?? null) !== null || ($root['parent_comment_id'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        if (!Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (self::isAuthor($u, $root)) {
            return [true, self::CODE_OWNER];
        }
        [$may, $code] = self::decidePageManage($u, $row);
        return $may ? [true, $code] : [false, self::CODE_NOT_OWNER];
    }

    /**
     * 공지의 **「읽은 사람 n/N」을 볼 수 있나** (C:F-30) — 공지 게시판에 걸린 글의 쓴 사람(게시판 글이면 작성자,
     * 게시된 개인 페이지면 주인) · Admin. 공지가 아니면 `space_governed`.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    public static function decideReadsView(array $u, array $row): array
    {
        $board = self::boardOfRow($row);
        if ($board === null || (string) ($board['board_kind'] ?? '') !== 'notice') {
            return [false, self::CODE_SPACE_GOVERNED];
        }
        if (Authz::canAdmin($u)) {
            return [true, self::CODE_ADMIN_ALL];
        }
        $kind = (string) ($row['space_kind'] ?? '');
        if (($kind === 'board' && self::isAuthor($u, $row)) || ($kind === 'personal' && self::isOwner($u, $row))) {
            return [true, self::CODE_OWNER];
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 사람을 **멘션·알림 대상으로 삼을 수 있나** (C:F-22, 11-1 ⑥ 확정 — 그 페이지를 볼 수 있는 활성 사용자 전체,
     * Guest 제외). `$rowForTarget` 은 **그 사람 몫으로** 읽은 페이지 행이다(`community_page_row($id, 대상 id)`) —
     * 공유 등급이 그 사람 몫이어야 판정이 맞는다. 볼 수 없는 사람에게는 알림을 만들지 않는다(제목이 새지 않게).
     *
     * @param  array<string,mixed>|null $target `public.users` 한 줄(`id`·`role`·`team_id`·`is_active`)
     * @param  array<string,mixed>      $rowForTarget
     * @return array{0:bool,1:string}
     */
    public static function decideMentionTarget(?array $target, array $rowForTarget): array
    {
        if ($target === null || ($target['is_active'] ?? false) !== true) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        $tu = [
            'id'      => (int) $target['id'],
            'role'    => (string) $target['role'],
            'team_id' => $target['team_id'] === null ? null : (int) $target['team_id'],
        ];
        if (!Authz::canCommunityWrite($tu)) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        [$ok, $code] = self::decidePageRead($tu, $rowForTarget);
        /* 관리자 특권 갈래(`admin_all`)·숨긴 글 관리자 갈래(`board_moderator`)로만 볼 수 있는 사람은 **대상이 아니다**
           — 검색(5-B)과 같은 좁은 쪽. 남의 비공개 페이지 제목이 멘션 한 번으로 관리자 알림함에 쌓이지 않게. */
        if (!$ok || $code === self::CODE_ADMIN_ALL || $code === self::CODE_BOARD_MODERATOR) {
            return [false, self::CODE_TARGET_NOT_PERMITTED];
        }
        return [true, $code];
    }

    /* ══════════════════════════════════════════════════════════════════
     * Q:F-12 업무 화면 — 담당자 · 포트폴리오 (커뮤니티 ARCHITECTURE 23.3 · 23.7, SECURITY C:R-15 · C:R-16)
     *
     * ★ 새 권한을 만들지 않는다(ARCH 23.1). 담당은 「맡은 사람」 표시일 뿐 읽기·고치기·관리 권한을 주지 않고,
     *   포트폴리오는 프로젝트를 볼 권한을 주지 않는다. 그래서 아래 다섯 함수 어디에도 `assignee_id` 나 포트폴리오
     *   소속이 「허용」의 이유로 나오지 않는다 — 판정은 전부 이미 있는 `decidePageChange` · `decidePageRead` ·
     *   `isTeamMember` · `Authz::canCommunityWrite` 를 빌린다(판정 두 벌 금지).
     * ══════════════════════════════════════════════════════════════════ */

    /** 허용 코드 — 담당이 될 사람이 그 작업을 **고칠 수 있다** (WK-14 A) */
    public const CODE_CAN_EDIT = 'can_edit';
    /** 거부 코드 — 담당 대상이 없거나 비활성 계정이다 */
    public const CODE_INACTIVE = 'inactive';
    /** 거부 코드 — 담당 대상이 쓰기 자격 없는 역할(Guest)이다 */
    public const CODE_NOT_WRITER = 'not_writer';
    /** 거부 코드 — 담당 대상이 **그 사람 몫으로 다시 읽은 행**에서 그 작업을 고칠 수 없다 */
    public const CODE_ASSIGNEE_CANNOT_EDIT = 'assignee_cannot_edit';
    /** 거부 코드 — 포트폴리오에 넣는 사람이 그 프로젝트를 (관리자 특권 말고는) 볼 수 없다 */
    public const CODE_PROJECT_NOT_READABLE = 'project_not_readable';
    /** 거부 코드 — 프로젝트(`databases.purpose='project'` 인 데이터베이스 페이지)가 아니다 */
    public const CODE_NOT_PROJECT = 'not_project';

    /**
     * 이 사람이 이 작업의 **담당을 정할 수 있나** (ARCH 23.3 `decidePageAssign`).
     *
     *   게시판 글                → `space_governed` (게시판 글에는 담당이 없다)
     *   그 밖                    → `decidePageChange` 결과 **그대로** — 고칠 수 있는 사람이 정한다.
     *                              Admin 은 change 가 없으므로 남의 작업 담당을 못 정한다(`not_owner`).
     *
     * 담당이 지금 누구인지(`assignee_id`)는 이 판정에 들어가지 않는다 — 맡은 사람이라서 다시 맡길 수 있는 갈래 0.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row 요청자 몫으로 읽은 페이지 행(`community_page_row($id, 요청자)`)
     * @return array{0:bool,1:string}
     */
    public static function decidePageAssign(array $u, array $row): array
    {
        if ((string) ($row['space_kind'] ?? '') === 'board') {
            return [false, self::CODE_SPACE_GOVERNED];
        }
        return self::decidePageChange($u, $row);
    }

    /**
     * 이 사람이 이 작업의 **담당이 될 수 있나** (ARCH 23.3 `decideAssignTarget` · WK-14 A 「고칠 수 있는 사람만」).
     *
     *   없음 · 비활성                      → `inactive`
     *   쓰기 자격 없는 역할(Guest)         → `not_writer`   (`Authz::canCommunityWrite` — 역할 문자열 비교 0)
     *   대상 몫으로 다시 읽은 행에서 `decidePageChange` 거부 → `assignee_cannot_edit`
     *   그 밖                              → `can_edit`
     *
     * `$rowForTarget` 은 **대상 몫으로** 읽은 행이다(`community_page_row($id, 대상 id)`) — 공유 등급이 그 사람 몫이어야
     * 판정이 맞는다(`decideMentionTarget` 과 같은 틀, 「읽기」 대신 「고치기」). 대상의 팀은 세션이 아니라 DB 의 값이다.
     *
     * @param  array<string,mixed>|null $target `public.users` 한 줄(`id`·`role`·`team_id`·`is_active`)
     * @param  array<string,mixed>      $rowForTarget
     * @return array{0:bool,1:string}
     */
    public static function decideAssignTarget(?array $target, array $rowForTarget): array
    {
        if ($target === null || ($target['is_active'] ?? false) !== true) {
            return [false, self::CODE_INACTIVE];
        }
        $tu = [
            'id'      => (int) $target['id'],
            'role'    => (string) ($target['role'] ?? ''),
            'team_id' => ($target['team_id'] ?? null) === null ? null : (int) $target['team_id'],
        ];
        if (!Authz::canCommunityWrite($tu)) {
            return [false, self::CODE_NOT_WRITER];
        }
        if (!self::decidePageChange($tu, $rowForTarget)[0]) {
            return [false, self::CODE_ASSIGNEE_CANNOT_EDIT];
        }
        return [true, self::CODE_CAN_EDIT];
    }

    /**
     * 이 사람이 이 **포트폴리오를 볼 수 있나** (ARCH 23.7 `decidePortfolioRead` · WK-13 ②).
     *
     *   지워짐                                    → `not_found`
     *   공간이 `team` 이고 그 팀 구성원            → `team_member` (`isTeamMember` — 팀 없음은 맞지 않는다)
     *   Admin                                     → `admin_all`   (읽기만 — 부르는 쪽이 감사 `portfolio.read|allow|admin_all`)
     *   그 밖                                     → `not_member`
     *
     * 팀 공간 페이지의 「사람 지정 공유」 갈래는 포트폴리오에 없다(공유는 이번 범위 밖).
     * `$pf` 모양 — `deleted_at` · `space_kind` · `space_team_id`(공간 행에서 읽은 값). 공간 종류가 `team` 인지는
     * 여기서만 본다(핸들러가 비교하지 않는다).
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $pf
     * @return array{0:bool,1:string}
     */
    public static function decidePortfolioRead(array $u, array $pf): array
    {
        if (($pf['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        if ((string) ($pf['space_kind'] ?? '') === 'team' && self::isTeamMember($u, $pf['space_team_id'] ?? null)) {
            return [true, self::CODE_TEAM_MEMBER];
        }
        if (Authz::canAdmin($u)) {
            return [true, self::CODE_ADMIN_ALL];
        }
        return [false, self::CODE_NOT_MEMBER];
    }

    /**
     * 이 사람이 이 **포트폴리오를 만들고·고치고·지울 수 있나** (ARCH 23.7 `decidePortfolioManage`).
     *
     *   `team_member` + 쓰기 자격(`Authz::canCommunityWrite`) → `team_member`
     *   볼 수 없음                                            → 읽기 판정의 코드(`not_found`·`not_member`)
     *   Admin 의 열람(`admin_all`)                            → `not_member` (Admin 은 읽기만 — 관리 불가)
     *   구성원이지만 쓰기 자격 없음(Guest)                    → `role_not_permitted`
     *
     * 팀 공간 페이지의 관리 결(「구성원만」 — `decidePageManage`)과 같다. 만들기는 아직 행이 없으므로 부르는 쪽이
     * 그 공간의 값으로 같은 모양(`deleted_at` null · `space_kind` · `space_team_id`)을 만들어 묻는다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $pf
     * @return array{0:bool,1:string}
     */
    public static function decidePortfolioManage(array $u, array $pf): array
    {
        [$mayRead, $code] = self::decidePortfolioRead($u, $pf);
        if (!$mayRead) {
            return [false, $code];
        }
        if ($code !== self::CODE_TEAM_MEMBER) {
            return [false, self::CODE_NOT_MEMBER];
        }
        if (!Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        return [true, self::CODE_TEAM_MEMBER];
    }

    /**
     * 이 사람이 이 **프로젝트를 이 포트폴리오에 넣을 수 있나** (ARCH 23.7 `decidePortfolioAdd`).
     *
     *   [1] `decidePortfolioManage` 거부                       → 그 코드
     *   [2] 넣는 사람이 그 프로젝트를 볼 수 없다 · **관리자 특권(`admin_all`)·숨긴 글 관리자(`board_moderator`)로만**
     *       본다                                               → `project_not_readable`
     *       (`decideMentionTarget` 과 같은 까닭 — 관리자 열람으로 남의 비공개 프로젝트를 팀 화면에 걸지 못하게)
     *   [3] `databases.purpose='project'` 인 데이터베이스 페이지가 아니다 → `not_project`
     *   그 밖                                                  → manage 의 코드(`team_member`)
     *
     * [2] 를 [3] 앞에 두는 까닭: 볼 수 없는 페이지가 프로젝트인지 아닌지(400 과 403 의 차이)가 새지 않게.
     * 넣은 뒤에도 그 프로젝트는 보는 사람마다 목록 SQL 로 다시 걸러진다 — 포트폴리오는 볼 권한을 주지 않는다.
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $pf
     * @param  array<string,mixed> $projectRow 넣는 사람 몫으로 읽은 페이지 행 + `dbself_purpose`(`community_project_row`)
     * @return array{0:bool,1:string}
     */
    public static function decidePortfolioAdd(array $u, array $pf, array $projectRow): array
    {
        [$mayManage, $code] = self::decidePortfolioManage($u, $pf);
        if (!$mayManage) {
            return [false, $code];
        }
        [$mayRead, $readCode] = self::decidePageRead($u, $projectRow);
        if (!$mayRead || $readCode === self::CODE_ADMIN_ALL || $readCode === self::CODE_BOARD_MODERATOR) {
            return [false, self::CODE_PROJECT_NOT_READABLE];
        }
        if (($projectRow['dbself_public_id'] ?? null) === null || ($projectRow['dbself_purpose'] ?? null) !== 'project') {
            return [false, self::CODE_NOT_PROJECT];
        }
        return [true, $code];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 첨부 (5-E, PRD F-24 · ARCH 13.2 `fitsFileRef`) — 붙인 t-box 파일은 **페이지를 읽는 사람이 모두 볼 수 있어야** 한다
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * 이 페이지를 **읽는 범위들** (5-E) — 첨부 파일이 들어맞아야 할 t-box 등급 모양(`level`·`team_id`) 목록.
     * 여럿이면 **모두**에 들어맞아야 한다(게시 중이면 원래 범위 + 게시판 등급). 사람 지정 공유는 넣지 않는다 — 파일은 t-box 가
     * 사람마다 다시 판정한다(E-3).
     * G-0 — 비공개 개인 페이지(「주인(과 관리자)만」)는 전에는 빈 목록이었다(어떤 파일이든 된다). 이제 `private` 범위 하나를
     * 싣는다(`owner_id` = 그 공간의 주인) — 개인 등급이 아닌 파일은 전과 같이 무엇이든 들어맞고(가장 좁은 범위라서), **t-box 개인
     * 파일은 파일 주인이 페이지 주인과 같을 때만** 들어맞는다(fitsFileRef). 그 밖의 페이지에는 개인 파일이 붙지 않는다
     * (페이지 독자 전원이 권한을 받았는지 계산하는 것은 G-1 몫).
     *
     * @param  array<string,mixed> $row
     * @param  array<string,mixed>|null $extraBoard 게시하려는 게시판(게시 창구가 미리 견줄 때)
     * @return list<array{level:string,team_id:?int}>
     */
    public static function pageAudiences(array $row, ?array $extraBoard = null): array
    {
        $out  = [];
        $kind = (string) ($row['space_kind'] ?? '');
        if ($kind === 'team') {
            $out[] = ['level' => 'team', 'team_id' => $row['space_team_id'] === null ? null : (int) $row['space_team_id']];
        } elseif ($kind === 'board') {
            $b = self::boardOfRow($row);
            if ($b !== null) {
                $out[] = ['level' => (string) $b['access_level'], 'team_id' => $b['team_id'] === null ? null : (int) $b['team_id']];
            }
        } else {
            $base = self::baseVisibility($row);
            if ($base === 'team') {
                $out[] = ['level' => 'team', 'team_id' => ($row['space_owner_team_id'] ?? null) === null ? null : (int) $row['space_owner_team_id']];
            } elseif ($base === 'internal') {
                $out[] = ['level' => 'internal', 'team_id' => null];
            } elseif ($base === 'private') {
                // G-0 — 「주인만」 범위. owner_id 가 없으면(모르는 행) 개인 파일은 들어맞지 않는다(fitsFileRef)
                $owner = $row['space_owner_user_id'] ?? null;
                $out[] = ['level' => 'private', 'team_id' => null, 'owner_id' => $owner === null ? null : (int) $owner];
            }
            $pub = (string) ($row['visibility'] ?? '') === 'published' ? self::publishedBoardOf($row) : null;
            foreach ([$pub, $extraBoard] as $b) {
                if ($b !== null) {
                    $out[] = ['level' => (string) $b['access_level'], 'team_id' => ($b['team_id'] ?? null) === null ? null : (int) $b['team_id']];
                }
            }
        }
        return $out;
    }

    /**
     * 이 파일이 이 **읽는 범위**에 들어맞나 (5-E `fitsFileRef`) — 파일이 범위보다 **좁지 않아야** 한다.
     *   t-box `LEVEL_RANK`(public 0 < internal 1 < team 2 < admin-only 3)에서 파일 ≤ 범위.
     *   파일이 팀 등급이면 범위도 **같은 팀** 이어야 한다(관리자 범위는 모든 팀을 보므로 된다). 모르는 등급은 거짓.
     *
     * @param array{access_level:string,team_id:mixed} $file
     * @param array{level:string,team_id:?int}          $aud
     */
    public static function fitsFileRef(array $file, array $aud): bool
    {
        $f = Authz::LEVEL_RANK[(string) ($file['access_level'] ?? '')] ?? null;
        $a = Authz::LEVEL_RANK[(string) ($aud['level'] ?? '')] ?? null;
        if ($f === null || $a === null || $f > $a) {
            // G-0 — t-box 개인 파일(rank 4)은 여기서 `private` 범위 말고는 전부 거짓이다(가장 좁다)
            return false;
        }
        /* G-0 — 개인 파일은 비공개 개인 페이지(`private` 범위)에만, 그리고 **파일 주인 = 페이지 주인**일 때만 들어맞는다
           (지시서 ★ — 그 밖의 페이지의 독자 권한 계산은 G-1). 주인 번호가 없으면(모르는 행) 거짓. */
        if ((string) $file['access_level'] === Authz::LEVEL_PRIVATE) {
            $fileOwner = $file['uploaded_by'] ?? null;
            $pageOwner = $aud['owner_id'] ?? null;
            return (string) $aud['level'] === Authz::LEVEL_PRIVATE
                && $fileOwner !== null && $pageOwner !== null && (int) $fileOwner === (int) $pageOwner;
        }
        if ((string) $file['access_level'] === 'team' && (string) $aud['level'] === 'team') {
            return $file['team_id'] !== null && $aud['team_id'] !== null && (int) $file['team_id'] === (int) $aud['team_id'];
        }
        return true;
    }

    /**
     * 이 페이지가 **걸린 게시판** — 게시판 글이면 그 공간, 게시된 개인 페이지면 게시한 게시판, 아니면 `null` (5-C).
     *
     * @param  array<string,mixed> $row `community_page_row()` 의 행
     * @return array<string,mixed>|null 「게시판 행」 모양
     */
    public static function boardOfRow(array $row): ?array
    {
        if ((string) ($row['space_kind'] ?? '') === 'board') {
            return [
                'id'           => $row['space_id'] ?? null,
                'kind'         => 'board',
                'access_level' => (string) ($row['space_access_level'] ?? ''),
                'team_id'      => $row['space_team_id'] ?? null,
                'is_active'    => ($row['space_is_active'] ?? false) === true,
                'board_kind'   => (string) ($row['space_board_kind'] ?? ''),
            ];
        }
        if ((string) ($row['visibility'] ?? '') === 'published') {
            return self::publishedBoardOf($row);
        }
        return null;
    }

    /**
     * 게시된 개인 페이지가 걸린 게시판 (`pub_*` 칸). 게시판이 아니거나 없으면 `null` — 그러면 게시판 갈래는
     * 통째로 꺼진다(모르면 막는다).
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function publishedBoardOf(array $row): ?array
    {
        if (($row['published_board_id'] ?? null) === null || (string) ($row['pub_kind'] ?? '') !== 'board') {
            return null;
        }
        return [
            'id'           => $row['published_board_id'],
            'kind'         => 'board',
            'access_level' => (string) ($row['pub_access_level'] ?? ''),
            'team_id'      => $row['pub_team_id'] ?? null,
            'is_active'    => ($row['pub_is_active'] ?? false) === true,
            'board_kind'   => (string) ($row['pub_board_kind'] ?? ''),
        ];
    }

    /**
     * 이 페이지의 **기준 범위** (5-C) — 게시 중이면 게시 전 범위(`published_from`), 아니면 범위 그대로.
     *
     * 트리 불변 규칙(자식 ≤ 부모)·새 하위 페이지의 범위·편집 공유·원래 범위 갈래가 전부 이 값을 본다.
     * 게시는 트리 위의 자리가 아니라 **게시판에 거는 일**이라서, 게시한다고 하위의 규칙이 넓어지지 않는다
     * (지시서 「published 는 가장 넓다 — 하위는 따라가지 않는다」). 셋 밖의 값이면 `null` — 모르면 막는다.
     *
     * @param array<string,mixed>|null $row
     */
    public static function baseVisibility(?array $row): ?string
    {
        if ($row === null) {
            return null;
        }
        $v = (string) ($row['visibility'] ?? '');
        if ($v === 'published') {
            $v = (string) ($row['published_from'] ?? '');
        }
        return in_array($v, self::VISIBILITIES, true) ? $v : null;
    }

    /* ─── 7-B 템플릿 (C:F-31 · 결정 J-3) ─────────────────────────────
     *
     * 템플릿은 **범위 하나**를 가진다(표의 CHECK 가 칸 하나만 채우게 한다):
     *   personal  만든 사람만 — Admin 도 남의 개인 템플릿은 못 본다(J-3 「남의 개인 템플릿은 안 보임」)
     *   team      그 팀원
     *   site      커뮤니티에 쓸 수 있는 사람 모두(시드 · Admin 이 만든 것)
     *   database  그 DB 에 **행을 더할 수 있는 사람**(DB 페이지의 `decidePageChange`) — DB 행 템플릿
     * 템플릿을 「볼 수 있다」와 「쓸 수 있다(그것으로 페이지를 만든다)」는 같은 물음이다. 만든 페이지가
     * **어디에 어떤 범위로** 생기는지는 템플릿이 아니라 `POST /api/pages` 의 기존 규칙(내 공간 · 비공개,
     * 부모가 있으면 부모 판정)이 정한다 — 템플릿은 본문을 채울 뿐 자리를 넓히지 않는다.
     */

    /** 템플릿 범위 넷 — 표의 CHECK 와 같은 목록이다 */
    public const TEMPLATE_SCOPES = ['personal', 'team', 'site', 'database'];

    /**
     * 이 템플릿을 볼(쓸) 수 있나 (7-B).
     *
     * @param  array<string,mixed>      $u
     * @param  array<string,mixed>      $tpl    `community_template_row()` 의 한 줄
     * @param  array<string,mixed>|null $dbPage DB 행 템플릿이면 그 DB 페이지 행(`community_page_row` — 보는 사람 몫)
     * @return array{0:bool,1:string}
     */
    public static function decideTemplateRead(array $u, array $tpl, ?array $dbPage = null): array
    {
        if (($tpl['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        switch ((string) ($tpl['scope'] ?? '')) {
            case 'personal':
                $owner = $tpl['owner_user_id'] ?? null;
                return $owner !== null && isset($u['id']) && (int) $owner === (int) $u['id']
                    ? [true, self::CODE_OWNER] : [false, self::CODE_NOT_OWNER];
            case 'team':
                return self::isTeamMember($u, $tpl['team_id'] ?? null)
                    ? [true, self::CODE_TEAM_MEMBER] : [false, self::CODE_NOT_MEMBER];
            case 'site':
                // 템플릿은 페이지를 **만드는** 데 쓴다 — 쓰기 자격이 없는 역할(Guest)에게는 쓸 곳이 없다
                return Authz::canCommunityWrite($u)
                    ? [true, self::CODE_MATRIX] : [false, self::CODE_ROLE_NOT_PERMITTED];
            case 'database':
                if ($dbPage === null) {
                    return [false, self::CODE_NOT_FOUND];
                }
                return self::decidePageChange($u, $dbPage);
        }
        return [false, self::CODE_ROLE_NOT_PERMITTED];   // 모르는 범위는 막는다
    }

    /**
     * 이 템플릿을 지울 수 있나 (7-B). 볼 수 있어도 지우는 것은 좁다.
     *   personal  만든 사람(= 볼 수 있는 사람)
     *   team      **그 팀원 누구나**(팀 공간의 페이지를 팀원이 함께 관리하는 것과 같다 — 만든 사람이 팀을 옮겨도
     *             남은 팀원이 정리할 수 있다) · Admin(`admin_all` — 감사에 남는다)
     *   site      Admin 만
     *   database  행을 더할 수 있으면서 만든 사람 · 그 DB 페이지의 관리 자격(`decidePageManage`)
     *
     * @param  array<string,mixed>      $u
     * @param  array<string,mixed>      $tpl
     * @param  array<string,mixed>|null $dbPage
     * @return array{0:bool,1:string}
     */
    public static function decideTemplateDelete(array $u, array $tpl, ?array $dbPage = null): array
    {
        if (($tpl['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        $mine = isset($u['id'], $tpl['created_by']) && $tpl['created_by'] !== null
            && (int) $tpl['created_by'] === (int) $u['id'];
        switch ((string) ($tpl['scope'] ?? '')) {
            case 'personal':
                return self::decideTemplateRead($u, $tpl);
            case 'team':
                if (self::isTeamMember($u, $tpl['team_id'] ?? null)) {
                    return [true, $mine ? self::CODE_OWNER : self::CODE_TEAM_MEMBER];
                }
                return Authz::canAdmin($u) ? [true, self::CODE_ADMIN_ALL] : [false, self::CODE_NOT_OWNER];
            case 'site':
                return Authz::canAdmin($u) ? [true, self::CODE_ADMIN_ALL] : [false, self::CODE_ROLE_NOT_PERMITTED];
            case 'database':
                if ($dbPage === null) {
                    return [false, self::CODE_NOT_FOUND];
                }
                if ($mine && self::decidePageChange($u, $dbPage)[0]) {
                    return [true, self::CODE_OWNER];
                }
                return self::decidePageManage($u, $dbPage);
        }
        return [false, self::CODE_ROLE_NOT_PERMITTED];
    }

    /**
     * 이 페이지를 이 범위의 템플릿으로 저장할 수 있나 (7-B).
     *
     * 원칙: **템플릿을 볼 사람 ⊆ 원본 페이지를 볼 수 있는 사람**. 템플릿이 원본보다 넓게 퍼지면
     * 템플릿이 곧 「범위를 넓히는 뒷문」이 된다(비공개 페이지를 팀 템플릿으로 저장해 팀에 보이기).
     *   [0] 원본을 볼 수 없다                          → `not_owner` (403 — `page.read deny`)
     *   [0b] 쓰기 자격이 없는 역할(Guest)               → `role_not_permitted` (템플릿은 페이지를 만드는 데 쓴다)
     *   [1] DB 페이지(행을 품은 쪽)                    → `db_page` (400 — DB 는 템플릿이 되지 않는다)
     *   personal  볼 수 있으면 된다                    → 읽기 근거 코드(나만 보는 사본 — 복사와 같다)
     *   team      원본이 **내 팀의 팀 공간** 페이지     → `team_member` · 다른 공간이면 `team_scope` (400)
     *             팀 공간은 팀원 모두가 보는 그릇이라 템플릿의 범위(그 팀원)와 같다
     *   site      Admin 만(`role_not_permitted` 403) · 원본이 사내 전체에 열려 있어야(`too_narrow` 400) —
     *             **숨긴 글**(게시판 등급이 꺼진다)과 **특권으로만 읽는 원본**(`admin_all`·`board_moderator`)은 좁다
     *             (독립 검토 M-1: `pageAudiences` 는 숨김을 보지 않아, 숨긴 게시 글이 사이트 템플릿으로 새어 나갈 뻔했다)
     *   database  원본이 **그 DB 의 행**(`not_row` 400) · DB 에 행을 더할 수 있는 사람(`not_owner` 403) ·
     *             행의 기준 범위가 DB 페이지와 같다(`too_narrow` 400 — 좁혀 둔 행은 범위로 읽는 DB 편집자가 보지 못한다.
     *             편집 공유자는 상속으로 읽지만, 팀·사내로 DB 를 고치는 사람은 아니다 — 안전한 쪽으로 엄격하다)
     *
     * @param  array<string,mixed>      $u
     * @param  array<string,mixed>      $row    원본 페이지(`community_page_row` — 보는 사람 몫)
     * @param  array<string,mixed>|null $dbPage database 범위일 때 그 행이 속한 DB 페이지
     * @return array{0:bool,1:string}
     */
    public static function decideTemplateSave(array $u, array $row, string $scope, ?array $dbPage = null): array
    {
        [$mayRead, $readCode] = self::decidePageRead($u, $row);
        if (!$mayRead) {
            return [false, $readCode === self::CODE_NOT_FOUND ? self::CODE_NOT_FOUND : self::CODE_NOT_OWNER];
        }
        if (!Authz::canCommunityWrite($u)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (($row['dbself_public_id'] ?? null) !== null) {
            return [false, 'db_page'];
        }
        switch ($scope) {
            case 'personal':
                return [true, $readCode];
            case 'team':
                if ((string) ($row['space_kind'] ?? '') !== 'team') {
                    return [false, self::CODE_TEAM_SCOPE];
                }
                return self::isTeamMember($u, $row['space_team_id'] ?? null)
                    ? [true, self::CODE_TEAM_MEMBER] : [false, self::CODE_NOT_MEMBER];
            case 'site':
                if (!Authz::canAdmin($u)) {
                    return [false, self::CODE_ROLE_NOT_PERMITTED];
                }
                if (($row['hidden_at'] ?? null) !== null
                    || in_array($readCode, [self::CODE_ADMIN_ALL, self::CODE_BOARD_MODERATOR], true)) {
                    return [false, 'too_narrow'];
                }
                foreach (self::pageAudiences($row) as $aud) {
                    if (in_array($aud['level'], ['internal', 'public'], true)) {
                        return [true, self::CODE_ADMIN_ALL];
                    }
                }
                return [false, 'too_narrow'];
            case 'database':
                if (($row['database_id'] ?? null) === null || $dbPage === null) {
                    return [false, 'not_row'];
                }
                if (!self::decidePageChange($u, $dbPage)[0]) {
                    return [false, self::CODE_NOT_OWNER];
                }
                if ((string) ($row['space_kind'] ?? '') !== 'team'
                    && self::baseVisibility($row) !== self::baseVisibility($dbPage)) {
                    return [false, 'too_narrow'];
                }
                return [true, self::decidePageChange($u, $dbPage)[1]];
        }
        return [false, self::CODE_ROLE_NOT_PERMITTED];
    }

    /**
     * 게시판 글을 **볼 수 있나** (5-C, `decidePageRead` [2]).
     *
     *   쓴 사람                                   → `owner` (게시판 등급이 바뀌어도·팀을 옮겨도 자기 글은 본다)
     *   숨긴 글 + 그 게시판의 관리자              → `board_moderator`
     *   숨긴 글 + 그 밖                           → `role_not_permitted`
     *   게시판을 읽을 수 있다                     → `board`
     *   그 밖                                     → t-box 코드
     *
     * @param  array<string,mixed> $u
     * @param  array<string,mixed> $row
     * @return array{0:bool,1:string}
     */
    private static function decideBoardPostRead(array $u, array $row): array
    {
        if (self::isAuthor($u, $row)) {
            return [true, self::CODE_OWNER];
        }
        $board = self::boardOfRow($row);
        if ($board === null) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (($row['hidden_at'] ?? null) !== null) {
            return self::decideBoardModerate($u, $board)[0]
                ? [true, self::CODE_BOARD_MODERATOR]
                : [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        return self::decideBoardRead($u, $board);
    }

    /**
     * 이 사람이 그 페이지를 **쓴 사람**인가 (5-C 게시판 글). 값은 세션 계정 id 와 DB 의 `author_id` 뿐이다.
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $row
     */
    private static function isAuthor(array $u, array $row): bool
    {
        $author = $row['author_id'] ?? null;
        if ($author === null || !isset($u['id'])) {
            return false;
        }
        return (int) $author === (int) $u['id'];
    }

    /**
     * 이 행에 실린 공유 등급이 **이 사람 몫**이면 그 등급(`view`·`edit`), 아니면 `null` (5-A).
     *
     * `community_page_row($id, $viewerId)` 는 등급과 함께 **누구 몫으로 센 값인지**
     * (`share_user_id`)를 싣는다. 둘이 어긋나면 — 다른 사람을 사용자 자리에 놓고 같은 행으로
     * 물을 때 — 등급은 없는 것으로 본다. 모르는 등급 글자도 없는 것이다(모르면 막는다).
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $row
     */
    public static function shareLevelFor(array $u, array $row): ?string
    {
        $level = $row['share_level'] ?? null;
        $for   = $row['share_user_id'] ?? null;
        if (!is_string($level) || !in_array($level, self::SHARE_LEVELS, true)) {
            return null;
        }
        if ($for === null || !isset($u['id']) || (int) $for !== (int) $u['id'] || (int) $for <= 0) {
            return null;
        }
        return $level;
    }

    /**
     * 이 사람이 **그 팀**의 팀원인가 (4-B).
     *
     * 값 둘뿐이다: 세션이 들고 있는 유효 팀(`$u['team_id']` — `Auth` 가 「역할로 보기」까지
     * 반영해 만든 값)과 DB 에서 읽은 팀 id. 요청이 정하는 값이 없다 (PRD S-01).
     * 팀이 없는 사람(Admin·Guest)은 **어느 팀원도 아니다** — `null` 끼리 같다고 보면
     * 팀 없는 두 사람이 서로의 「팀 페이지」를 보게 된다.
     *
     * @param array<string,mixed> $u
     * @param mixed               $teamId
     */
    private static function isTeamMember(array $u, $teamId): bool
    {
        $mine = $u['team_id'] ?? null;
        if ($teamId === null || $mine === null) {
            return false;
        }
        return (int) $teamId === (int) $mine;
    }

    /**
     * 이 사람이 그 공간의 주인인가. 값은 세션이 들고 있는 계정 id 와 DB 에서 읽은 공간의
     * 소유자 id 뿐이고, 요청이 정하지 않는다 (PRD S-01).
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $row
     */
    private static function isOwner(array $u, array $row): bool
    {
        $owner = $row['space_owner_user_id'] ?? null;
        if ($owner === null || !isset($u['id'])) {
            return false;
        }
        return (int) $owner === (int) $u['id'];
    }
    /** Automation objects never grant the administrator a second user's execution privileges. */
    public static function decideAutomationManage(array $u, array $rule): array
    {
        if (($rule['deleted_at'] ?? null) !== null) { return [false, self::CODE_NOT_FOUND]; }
        return (int)($u['id'] ?? 0) === (int)$rule['owner_user_id']
            ? [true, self::CODE_OWNER] : [false, self::CODE_NOT_FOUND];
    }
    public static function decideAutomationUndo(array $u, array $run): array
    {
        return (int)($u['id'] ?? 0) === (int)$run['run_by']
            ? [true, self::CODE_OWNER] : [false, self::CODE_NOT_FOUND];
    }

    /** C:T-802 / AI-2: ordinary read only; canonical AI context is loaded by AiService. */
    public static function decideAiInput(array $u, array $row): array
    {
        [$ok,$reason]=self::decidePageRead($u,$row);
        if (!$ok || in_array($reason,[self::CODE_ADMIN_ALL,self::CODE_BOARD_MODERATOR],true)) {
            return [false,'not_readable'];
        }
        if (($row['ai_excluded'] ?? true) || ($row['purged_at'] ?? null)!==null) {
            return [false,'excluded'];
        }
        if (($row['ai_allowed_version'] ?? 0)!==(int)$row['doc_version']) { return [false,'permission_required']; }
        return [true,$reason];
    }

}
