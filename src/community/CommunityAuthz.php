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
 * 3단계의 범위 (ARCH 21.2)
 *   · 공간은 `personal` 하나뿐이고 페이지 범위는 `private` 하나뿐이다.
 *   · 읽기 = 소유자 또는 Admin(`admin_all`, 그 열람은 감사에 남는다).
 *   · 고치기·지우기 = **소유자만**. Manager 에게 특권이 없다(같은 팀이어도 못 본다).
 *   · 4단계가 팀 공간·게시판·공개 범위를 열 때, 여기에 갈래가 늘고 판정 코드가 붙는다.
 *     그때까지 모르는 조합은 **닫힌 실패**(`role_not_permitted`)다 — 모르면 막는다.
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
    /** 거부 코드 — 이 단계가 아직 모르는 조합(팀 공간·게시판·공개 범위) */
    public const CODE_ROLE_NOT_PERMITTED = 'role_not_permitted';

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
     * 이 사람이 이 페이지를 **볼 수 있나** (ARCH 13.2 `decidePageRead`).
     *
     * `$row` 에 있어야 하는 것 — 핸들러가 `pages` ⋈ `spaces` 로 한 번에 읽어 넘긴다:
     *   `deleted_at` · `visibility` · `space_kind` · `space_owner_user_id`
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

        // 3단계는 개인 공간뿐이다. 다른 종류는 아직 판정 규칙이 없으므로 막는다.
        if ((string) ($row['space_kind'] ?? '') !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        // 3단계 코드는 `private` 만 쓴다. 다른 값이 들어 있다면 4단계 데이터이고, 그때의
        // 판정 규칙이 아직 없으므로 역시 막는다 (모르면 막는다).
        if ((string) ($row['visibility'] ?? '') !== 'private') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }

        if (self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }

        /* 사용자 결정 2026-09-22(커뮤니티 PRD 4.1) — 「회사 자료라 관리자는 볼 수 있다」.
           대신 그 열람은 `page.read allow admin_all` 로 감사에 남는다. Manager 에게는 특권이
           없다 — 같은 팀이어도 남의 개인 페이지는 보이지 않는다. */
        if (Authz::canAdmin($u)) {
            return [true, self::CODE_ADMIN_ALL];
        }

        return [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 사람이 이 페이지를 **고칠 수 있나**(저장·삭제) (ARCH 13.2 `decidePageChange`).
     *
     * 개인 공간에서는 **소유자만**이다. Admin 도 남의 개인 페이지를 고치지 못한다 —
     * 「볼 수 있다」와 「고칠 수 있다」는 다른 물음이고, 관리자가 남의 기록을 소리 없이
     * 바꿀 수 있으면 그 기록은 더 이상 증거가 아니다 (PRD 4.1 표의 「누가 고치나」 칸).
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
        if ((string) ($row['space_kind'] ?? '') !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (self::isOwner($u, $row)) {
            return [true, self::CODE_OWNER];
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 페이지를 **저 부모 아래로 옮길 수 있나** (4-A `decidePageMove`).
     *
     * ★ 이 함수는 **DB 를 읽지 않는다.** 트리를 훑는 일은 질의의 몫이고(핸들러가 재귀 CTE
     *   한 번으로 모아 온다), 여기서는 그 결과를 놓고 **판단만** 한다. 판정과 질의를 섞으면
     *   같은 규칙이 핸들러마다 조금씩 다르게 다시 쓰인다 (t-box ARCH 4.1 과 같은 원칙).
     *
     * `$page` — 옮길 페이지. `community_page_row()` 의 행에 아래 두 칸을 더한 것:
     *   `subtree_height` 자기를 1 로 센 **자기 아래 가지의 높이**(잎이면 1)
     * `$parent` — 새 부모. `null` 이면 **뿌리로** 올린다. 있으면 같은 행 모양에 두 칸 더:
     *   `depth`          뿌리를 1 로 센 새 부모의 깊이
     *   `ancestor_ids`   새 부모 자신과 그 위 조상들의 `pages.id` 목록
     *
     * 판정 순서 (하나라도 걸리면 거기서 끝난다)
     *   [1] 옮기는 사람이 그 페이지의 주인인가        → 아니면 `not_owner`
     *   [2] 새 부모도 **같은 공간·같은 주인**인가      → 아니면 `not_owner`
     *       (다른 사람 공간의 페이지를 부모로 주면 여기서 막힌다. 존재 여부는 알려 주지 않는다)
     *   [3] 새 부모가 자기 자신이거나 자기 후손인가    → `cycle`
     *       후손 판정은 「내가 새 부모의 조상 목록에 있나」로 한다 — 같은 뜻이고 질의가 한 번이다.
     *   [4] 새 부모 깊이 + 내 가지 높이 > 8            → `depth_exceeded`
     *
     * @param  array<string,mixed>      $u
     * @param  array<string,mixed>      $page
     * @param  array<string,mixed>|null $parent
     * @return array{0:bool,1:string}
     */
    public static function decidePageMove(array $u, array $page, ?array $parent): array
    {
        if (($page['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        if ((string) ($page['space_kind'] ?? '') !== 'personal') {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if (!self::isOwner($u, $page)) {
            return [false, self::CODE_NOT_OWNER];
        }

        $height = (int) ($page['subtree_height'] ?? 1);
        if ($height < 1) {
            $height = 1;
        }

        // 뿌리로 올리기 — 부모가 없으므로 깊이는 내 가지의 높이 그대로다.
        if ($parent === null) {
            return $height > self::MAX_DEPTH
                ? [false, self::CODE_DEPTH_EXCEEDED]
                : [true, self::CODE_OWNER];
        }

        if (($parent['deleted_at'] ?? null) !== null) {
            return [false, self::CODE_NOT_FOUND];
        }
        // 같은 공간이어야 한다. 「같은 공간」은 개인 공간이므로 곧 「같은 주인」이다.
        if ((int) ($parent['space_id'] ?? 0) !== (int) ($page['space_id'] ?? -1)
            || !self::isOwner($u, $parent)
        ) {
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

        return [true, self::CODE_OWNER];
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
