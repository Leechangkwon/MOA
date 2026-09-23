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
 * ★ 역할 비교는 여전히 이 파일에 없다. 팀 판정은 **역할이 아니라 id 비교**이고, 값은 전부
 *   세션(`$u['team_id']` — `Auth` 가 「역할로 보기」까지 반영해 만든 유효 팀)과 DB 행에서 온다.
 *   요청이 정하는 값은 한 칸도 없다 (PRD S-01).
 *
 * 돌려주는 값은 t-box `Authz::decide*()` 와 같은 모양 `[bool, 코드]` 다.
 * 허용 코드는 감사 `detail.granted_by` 로, 거부 코드는 `reason` 으로 그대로 기록된다.
 */
final class CommunityAuthz
{
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
    /** 거부 코드 — 팀 공간의 페이지다. 공개 범위·공유·인계는 공간 등급을 따른다 (5-A) */
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
     *   [1] 팀 공간                              → 그 팀원 `team_member` / Admin `admin_all` / 그 밖 `not_member`
     *   [2] 개인 공간이 아니면(게시판)           → `role_not_permitted` (5단계)
     *   [3] 내 페이지                            → `owner`
     *   [4] `published`                          → `role_not_permitted` (게시판 공간이 없다 — 닫힌 실패)
     *   [4b] 이 페이지나 조상에 나에게 준 공유    → `shared`   (5-A — 보기·편집 어느 쪽이든 읽기는 열린다)
     *   [5] `internal` + 쓰기 자격 있는 역할     → `matrix`   (Guest 는 여기서 끝난다)
     *   [6] `team` + 주인과 같은 팀              → `same_team`
     *   [7] Admin                                → `admin_all` (감사에 남는다)
     *   [8] 그 밖                                → `not_owner`
     *
     * ★ `internal` 을 `canCommunityWrite` 로 판정하는 이유: 「로그인 전원, Guest 제외」는
     *   t-box 권한 매트릭스의 **쓰기 자격과 같은 표**다(PRD 4장 각주). 표를 새로 만들지 않는다.
     * ★ 공유 갈래 [4b] 가 소유자 [3] 바로 뒤가 아니라 [4] 뒤에 있는 이유: `published`·모르는 범위는
     *   공유가 있어도 **닫힌 실패**로 남겨야 목록 SQL(세 범위만 받는다)과 한 줄씩 짝이 맞는다.
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

        $kind = (string) ($row['space_kind'] ?? '');

        /* [1] 팀 공간 — 페이지에 자기 범위가 없다. 공간 하나로 판정이 끝난다 (PRD 4.1). */
        if ($kind === 'team') {
            if (self::isTeamMember($u, $row['space_team_id'] ?? null)) {
                return [true, self::CODE_TEAM_MEMBER];
            }
            if (Authz::canAdmin($u)) {
                return [true, self::CODE_ADMIN_ALL];
            }
            return [false, self::CODE_NOT_MEMBER];
        }

        // [2] 게시판 공간(`board`)은 5단계다. 모르는 조합은 막는다.
        if ($kind !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }

        if (self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }

        $visibility = (string) ($row['visibility'] ?? '');

        /* [4] 게시는 5단계다. 게시판 공간이 없는데 「게시됨」을 열어 주면 아무 등급에도
           기대지 않은 열람이 되므로 **닫힌 실패**로 둔다 (지시서 「범위 밖」). */
        if ($visibility === 'published') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        // 셋 밖의 값이 DB 에 있다면 그것도 모르는 조합이다 — 모르면 막는다.
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }

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

        return [false, self::CODE_NOT_OWNER];
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
     * 팀 공간 — **그 팀원 누구나** 고친다(함께 쓰라고 만든 그릇이다).
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
            return self::isTeamMember($u, $row['space_team_id'] ?? null)
                ? [true, self::CODE_TEAM_MEMBER]
                : [false, self::CODE_NOT_MEMBER];
        }
        if ($kind !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }
        /* 편집 공유 (5-A). 읽기 판정과 같은 규칙으로 `published`·모르는 범위는 닫는다 —
           읽을 수 없는 페이지를 고칠 수 있는 조합이 생기지 않게 한다. */
        if (in_array((string) ($row['visibility'] ?? ''), self::VISIBILITIES, true)
            && self::shareLevelFor($u, $row) === 'edit'
            && Authz::canCommunityWrite($u)
        ) {
            return [true, self::CODE_SHARED_EDIT];
        }
        return [false, self::CODE_NOT_OWNER];
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
        if ($kind !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        return self::isOwner($u, $row)
            ? [true, self::CODE_OWNER]
            : [false, self::CODE_NOT_OWNER];
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
        if ($kind === 'team') {
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
            $widest = (string) ($page['subtree_widest'] ?? ($page['visibility'] ?? ''));
            if (!self::fitsUnderParent($widest, (string) ($parent['visibility'] ?? ''))) {
                return [false, self::CODE_WIDER_THAN_PARENT];
            }
        }

        return [true, $changeCode];
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
}
