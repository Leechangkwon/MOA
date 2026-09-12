<?php
declare(strict_types=1);

/**
 * src/Authz.php — "해도 되는가" (PRD 5장 권한 매트릭스의 유일한 구현, ARCH 4.1)
 *
 * 이 파일이 프로젝트에서 역할 문자열을 판단하는 **단 하나의 장소**다.
 * 다른 파일에 역할 비교가 나타나면 린트(scripts/security_lint.sh)가 반려한다.
 *
 * 역할 × 레벨 표(PRD 5.1·5.2)는 코드가 아니라 DB `permissions` 16행에서 읽는다(ARCH 6.2-1).
 *   → 표와 코드가 어긋날 여지를 없애고, 앱 계정은 그 표에 SELECT 만 가지므로
 *     화면·API 어디로도 권한 표를 바꿀 수 없다(권한 상승 경로 차단).
 * 소유자·팀 규칙(PRD 5.3)만 아래 코드로 판단한다.
 */
final class Authz
{
    /** 라우터가 라우트마다 반드시 하나 붙여야 하는 정책 이름 (SECURITY 1.2-③, R-04) */
    public const POLICY_PUBLIC        = 'public';
    public const POLICY_AUTHENTICATED = 'authenticated';

    /**
     * 라우터가 쓸 수 있는 정책 이름의 전체 목록 (아래 authorize() 의 case 목록과 1:1).
     * public/index.php 는 부팅 시 모든 라우트의 policy 가 이 배열에 있는지 검사하고,
     * 하나라도 없으면 어떤 요청도 처리하지 않는다 (SECURITY R-04 "정책 미등록 라우트는 부팅 실패").
     * 새 정책을 만들면 여기와 authorize() 를 함께 고쳐야 한다.
     */
    public const KNOWN_POLICIES = [
        self::POLICY_PUBLIC,
        self::POLICY_AUTHENTICATED,
        'role:Admin',
        'role:Manager+',
        'file.read',
        'file.upload',
        'file.update',
        'file.delete',
        'file.fetch',
        'audit.read',
        'user.manage',
        // 12-3 — 조직·권한 관리 (PRD F-19 · 5.4, ARCH 5.9). user.manage 를 재사용하지 않는 이유는
        // 감사 로그와 라우트 표에서 "무엇을 관리했나" 가 이름만으로 갈리게 하기 위해서다.
        'org.manage',
    ];

    /**
     * 판정 코드 (ARCH 4.1 표 · 7.5 `detail` 등록부 · 7.4 문장 표의 reason 과 같은 값).
     * 허용 코드는 `detail.granted_by` 로, 거부 코드는 `reason` 으로 그대로 기록된다.
     * 핸들러는 이 코드를 지어내거나 바꾸지 않고 decide*() 가 돌려준 값을 그대로 넘긴다.
     */
    public const CODE_MATRIX             = 'matrix';        // 역할 × 레벨 표가 허용
    public const CODE_SAME_TEAM          = 'same_team';     // team 레벨, 내 팀
    public const CODE_ALL_TEAMS          = 'all_teams';     // team 레벨, 모든 팀을 보는 역할
    public const CODE_OWNER              = 'owner';         // 삭제: 본인이 올린 파일
    public const CODE_TEAM_SCOPE         = 'team_scope';    // 삭제: Manager 의 자기 팀 파일
    public const CODE_ADMIN_ALL          = 'admin_all';     // 삭제: Admin 은 모든 파일
    public const CODE_ROLE_NOT_PERMITTED = 'role_not_permitted';
    public const CODE_TEAM_MISMATCH      = 'team_mismatch';
    public const CODE_NOT_OWNER          = 'not_owner';
    /** 5단계 — 등급을 낮추는 변경은 Admin 만 (PRD 4장·5.3, ARCH 4.1 표) */
    public const CODE_DOWNGRADE_NOT_PERMITTED = 'downgrade_not_permitted';
    /** 10단계 — 계정 관리(F-10) 거부 코드 (ARCH 7.4 `user.*|deny|…` 행) */
    public const CODE_SELF_CHANGE = 'self_change';   // 자기 역할 변경·자기 비활성화
    public const CODE_LAST_ADMIN  = 'last_admin';    // 마지막 활성 Admin 을 낮추거나 끄기

    /**
     * 10단계 — 계정에 줄 수 있는 역할 (PRD 3장 4종, db/schema.sql users_role_check 와 같은 값).
     *
     * 요청 본문의 역할 문자열을 **그대로** 컬럼에 넣지 않는다. 이 목록에 정확히 있는 값만
     * 통과시키고(`isAssignableRole`), 그 검사도 역할 판단이므로 이 파일 안에서만 한다.
     */
    private const ROLES = ['Admin', 'Manager', 'Employee', 'Guest'];

    /** 화면 선택지(`/api/me` 의 upload_levels)에 쓰는 레벨 표시 순서 (PRD 4장 순서 그대로) */
    private const LEVEL_ORDER = ['public', 'internal', 'team', 'admin-only'];

    /**
     * 레벨의 "노출 범위" 순서 (PRD 4장). 숫자가 작을수록 더 많은 사람이 본다.
     * 값을 낮추는 변경(`admin-only` → `public`)이 곧 노출 범위를 넓히는 변경이다 (SECURITY R-20).
     */
    public const LEVEL_RANK = [
        'public'     => 0,
        'internal'   => 1,
        'team'       => 2,
        'admin-only' => 3,
    ];

    private const SQL_MATRIX = <<<'SQL'
        SELECT role, access_level, can_read, can_upload FROM permissions
        SQL;

    /** @var array<string,array{read:bool,upload:bool}>|null  "역할|레벨" => 플래그 */
    private static ?array $matrix = null;

    /** 요청 1회당 permissions 조회 1회 */
    private static function matrix(): array
    {
        if (self::$matrix !== null) {
            return self::$matrix;
        }
        $rows = Db::instance()->run(self::SQL_MATRIX)->fetchAll();
        $m = [];
        foreach ($rows as $r) {
            $m[$r['role'] . '|' . $r['access_level']] = [
                'read'   => $r['can_read'] === true,
                'upload' => $r['can_upload'] === true,
            ];
        }
        if ($m === []) {
            // 표가 비어 있으면 "전부 허용"이 되어 버리므로, 차라리 실패한다.
            throw new RuntimeException('권한 표(permissions)가 비어 있습니다');
        }
        self::$matrix = $m;
        return self::$matrix;
    }

    private static function flag(string $role, string $level, string $which): bool
    {
        $m = self::matrix();
        $key = $role . '|' . $level;
        if (!isset($m[$key])) {
            return false;   // 표에 없는 조합은 거부 (기본 거부)
        }
        return $m[$key][$which];
    }

    /** 표에서 이 역할이 읽을 수 있는 레벨 목록 */
    private static function readableLevels(string $role): array
    {
        $out = [];
        foreach (self::matrix() as $key => $flags) {
            if (!$flags['read']) {
                continue;
            }
            $parts = explode('|', $key, 2);
            if ($parts[0] === $role) {
                $out[] = $parts[1];
            }
        }
        sort($out);
        return $out;
    }

    /** 사용자 배열에서 역할 문자열을 꺼낸다 (역할 판단은 이 파일 안에서만) */
    private static function roleOf(array $u): string
    {
        return (string) $u['role'];
    }

    /** 모든 팀의 team 레벨을 볼 수 있는 역할인가 (PRD 5.1 "Admin: 모든 팀") */
    private static function seesAllTeams(string $role): bool
    {
        return $role === 'Admin';
    }

    /**
     * PRD 5.1 읽기 — 목록·검색 SQL 에 붙일 조건절과 바인딩 값 (PRD S-02).
     * 애플리케이션에서 나중에 걸러내지 않는다. DB 가 애초에 안 돌려준다.
     *
     * 조건절에는 **논리 삭제 제외(`f.deleted_at IS NULL`)가 항상 함께 들어간다**
     * (SECURITY 1.3 보완: 쿼리마다 따로 붙이면 언젠가 한 곳이 빠진다 → 한 곳에서만 관리).
     * 돌려주는 문자열은 테이블 별칭 `f` 를 전제로 한다 — 호출하는 SQL 은 `FROM files f` 여야 한다.
     *
     * @param array<string,mixed> $u
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function visibleFilesWhere(array $u): array
    {
        $role   = (string) $u['role'];
        $teamId = $u['team_id'] === null ? null : (int) $u['team_id'];
        $levels = self::readableLevels($role);

        $clauses = [];
        $params  = [];

        // team 은 "같은 팀이면" 이라는 전제가 붙은 레벨이므로 따로 다룬다.
        $plain = array_values(array_filter($levels, static fn (string $l): bool => $l !== 'team'));
        if ($plain !== []) {
            $ph = [];
            foreach ($plain as $i => $lvl) {
                $name        = ':lvl' . $i;
                $ph[]        = $name;
                $params[$name] = $lvl;
            }
            $clauses[] = 'f.access_level IN (' . implode(', ', $ph) . ')';
        }

        if (in_array('team', $levels, true)) {
            if (self::seesAllTeams($role)) {
                $clauses[] = "f.access_level = 'team'";
            } elseif ($teamId !== null) {
                $clauses[]        = "(f.access_level = 'team' AND f.team_id = :own_team)";
                $params[':own_team'] = $teamId;
            }
        }

        if ($clauses === []) {
            // 볼 수 있는 것이 하나도 없는 경우에도 삭제 조건을 함께 둔다(형태를 하나로 유지).
            return ['(f.deleted_at IS NULL AND FALSE)', []];
        }
        return ['(f.deleted_at IS NULL AND (' . implode(' OR ', $clauses) . '))', $params];
    }

    /**
     * PRD 5.1 읽기 — 파일 1건 판정 + 근거 코드 (ARCH 4.1 "판정 코드").
     *
     * 순서: 표(permissions) → team 여부 → 모든 팀을 보는 역할 → 같은 팀.
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     * @return array{0:bool,1:string} [허용 여부, 코드]
     */
    public static function decideRead(array $u, array $file): array
    {
        $role  = self::roleOf($u);
        $level = (string) $file['access_level'];

        if (!self::flag($role, $level, 'read')) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if ($level !== 'team') {
            return [true, self::CODE_MATRIX];
        }
        if (self::seesAllTeams($role)) {
            return [true, self::CODE_ALL_TEAMS];
        }
        $mine   = $u['team_id']    === null ? null : (int) $u['team_id'];
        $theirs = $file['team_id'] === null ? null : (int) $file['team_id'];
        if ($mine !== null && $mine === $theirs) {
            return [true, self::CODE_SAME_TEAM];
        }
        return [false, self::CODE_TEAM_MISMATCH];
    }

    /**
     * PRD 5.2 업로드 — 이 레벨(+팀)로 올릴 수 있나 + 근거 코드 (ARCH 4.1).
     *
     * @param array<string,mixed> $u
     * @return array{0:bool,1:string}
     */
    public static function decideUploadAs(array $u, string $level, ?int $teamId): array
    {
        $role = self::roleOf($u);

        if (!self::flag($role, $level, 'upload')) {
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }
        if ($level !== 'team') {
            return [true, self::CODE_MATRIX];
        }
        if ($teamId === null) {
            // team 레벨은 team_id 필수 (PRD 4장, DB CHECK team_requires_team_id 로도 강제)
            return [false, self::CODE_TEAM_MISMATCH];
        }
        if (self::seesAllTeams($role)) {
            return [true, self::CODE_ALL_TEAMS];
        }
        $mine = $u['team_id'] === null ? null : (int) $u['team_id'];
        if ($mine !== null && $mine === $teamId) {
            return [true, self::CODE_SAME_TEAM];
        }
        return [false, self::CODE_TEAM_MISMATCH];
    }

    /**
     * PRD 5.3 삭제 — 소유자·팀 규칙 + 근거 코드 (ARCH 4.1).
     * **canRead 를 먼저 통과했다는 전제**다(볼 수 없는 파일은 삭제 판정 전에 이미 막힌다).
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     * @return array{0:bool,1:string}
     */
    public static function decideDelete(array $u, array $file): array
    {
        return self::decideOwnership($u, $file);
    }

    /**
     * PRD 5.3 수정 — 설명·레벨을 고칠 수 있는 기본 자격 + 근거 코드 (ARCH 4.1 "5단계 추가").
     * **decideRead 를 먼저 통과했다는 전제**이며, 규칙과 코드는 삭제(decideDelete)와 **같다**:
     * Admin 은 모두(`admin_all`), Manager 는 본인(`owner`)·자기 팀(`team_scope`),
     * Employee 는 본인(`owner`), Guest 는 불가(`role_not_permitted`).
     * 둘이 같은 규칙이므로 구현도 한 곳(decideOwnership)만 둔다 — 한쪽만 고쳐져 어긋나는 일을 막는다.
     *
     * 목록의 `can_edit` 도 이 함수로 계산한다 (ARCH 4.1, 5.7 화면 메모).
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     * @return array{0:bool,1:string}
     */
    public static function decideEdit(array $u, array $file): array
    {
        return self::decideOwnership($u, $file);
    }

    /**
     * PRD 5.3 레벨 변경 — `decideEdit` 통과가 전제 (ARCH 4.1 "5단계 추가", 5.7 [권한 ✔ 3]).
     *
     *   ① 새 등급이 지금보다 **낮으면**(노출 범위가 넓어지면) Admin 이 아닌 한 거부
     *      (PRD 4장 "레벨을 낮추는 변경은 Admin 만", SECURITY R-20)
     *   ② 그 뒤 "그 등급·그 팀을 지정할 자격" 은 업로드와 완전히 같다 → decideUploadAs 재사용.
     *      허용 코드도 그쪽 것(`matrix`·`same_team`·`all_teams`)을 그대로 쓴다.
     *
     * 알 수 없는 레벨 문자열은 ①을 건너뛰고 ②에서 표(permissions)에 없는 조합으로 거부된다.
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     * @return array{0:bool,1:string}
     */
    public static function decideLevelChange(array $u, array $file, string $newLevel, ?int $newTeamId): array
    {
        $current = (string) $file['access_level'];

        if (isset(self::LEVEL_RANK[$newLevel], self::LEVEL_RANK[$current])
            && self::LEVEL_RANK[$newLevel] < self::LEVEL_RANK[$current]
            && !self::mayDowngrade(self::roleOf($u))
        ) {
            return [false, self::CODE_DOWNGRADE_NOT_PERMITTED];
        }

        return self::decideUploadAs($u, $newLevel, $newTeamId);
    }

    /** 등급을 낮출 수 있는 역할인가 (PRD 5.3 "Admin: 낮추는 변경 포함") */
    private static function mayDowngrade(string $role): bool
    {
        return $role === 'Admin';
    }

    /**
     * 소유자·팀 규칙 (PRD 5.3). 삭제(decideDelete)와 수정(decideEdit)이 함께 쓴다.
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     * @return array{0:bool,1:string}
     */
    private static function decideOwnership(array $u, array $file): array
    {
        $role     = self::roleOf($u);
        $me       = (int) $u['id'];
        $owner    = (int) $file['uploaded_by'];
        $myTeam   = $u['team_id']    === null ? null : (int) $u['team_id'];
        $fileTeam = $file['team_id'] === null ? null : (int) $file['team_id'];

        if ($role === 'Admin') {
            return [true, self::CODE_ADMIN_ALL];
        }
        if ($role === 'Manager') {
            if ($owner === $me) {
                return [true, self::CODE_OWNER];
            }
            if ($fileTeam !== null && $myTeam !== null && $fileTeam === $myTeam) {
                return [true, self::CODE_TEAM_SCOPE];
            }
            if ($fileTeam !== null) {
                return [false, self::CODE_TEAM_MISMATCH];   // 남의 팀 파일 (ARCH 4.1 표)
            }
            return [false, self::CODE_NOT_OWNER];           // 팀이 없는 파일인데 남이 올린 것
        }
        if ($role === 'Employee') {
            return $owner === $me
                ? [true, self::CODE_OWNER]
                : [false, self::CODE_NOT_OWNER];
        }
        return [false, self::CODE_ROLE_NOT_PERMITTED];      // Guest 및 알 수 없는 역할
    }

    /**
     * PRD 5.2 — 이 사용자가 고를 수 있는 업로드 레벨 목록 (`GET /api/me` 의 upload_levels).
     *
     * 화면의 선택지를 채우기 위한 **편의 값**이다. 화면이 무엇을 보여 주든 실제 업로드는
     * decideUploadAs 가 다시 판단한다 (PRD 10장 "버튼 표시는 권한의 근거가 아님", S-01).
     *
     * @param array<string,mixed> $u
     * @return list<string>
     */
    public static function uploadableLevels(array $u): array
    {
        $role = self::roleOf($u);
        $out  = [];
        foreach (self::LEVEL_ORDER as $level) {
            if (self::flag($role, $level, 'upload')) {
                $out[] = $level;
            }
        }
        return $out;
    }

    /**
     * PRD 5.1 읽기 — 파일 1건 (다운로드·상세). 판정은 decideRead 한 곳에만 있다.
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     */
    public static function canRead(array $u, array $file): bool
    {
        return self::decideRead($u, $file)[0];
    }

    /**
     * PRD 5.2 업로드 — 이 레벨(+팀)로 올릴 수 있나. 판정은 decideUploadAs 한 곳에만 있다.
     *
     * @param array<string,mixed> $u
     */
    public static function canUploadAs(array $u, string $level, ?int $teamId): bool
    {
        return self::decideUploadAs($u, $level, $teamId)[0];
    }

    /**
     * PRD 5.3 삭제. 판정은 decideDelete 한 곳에만 있다 (canRead 통과가 전제).
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     */
    public static function canDelete(array $u, array $file): bool
    {
        return self::decideDelete($u, $file)[0];
    }

    /**
     * PRD 5.3 수정. 판정은 decideEdit 한 곳에만 있다 (decideRead 통과가 전제).
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $file
     */
    public static function canEdit(array $u, array $file): bool
    {
        return self::decideEdit($u, $file)[0];
    }

    /** PRD 5.4 — URL 로 파일 가져오기(F-14)는 Manager 이상 */
    public static function canFetch(array $u): bool
    {
        return in_array(self::roleOf($u), ['Admin', 'Manager'], true);
    }

    /** PRD 5.4 — 감사 로그 조회는 Admin 만 (Q-8) */
    public static function canReadAudit(array $u): bool
    {
        return self::roleOf($u) === 'Admin';
    }

    /** PRD 5.4 — 계정 생성·역할 변경은 Admin 만 */
    public static function canManageUsers(array $u): bool
    {
        return self::roleOf($u) === 'Admin';
    }

    /**
     * PRD 5.4 — 팀(조직) 관리와 권한 매트릭스 **조회**는 Admin 만 (12-3, F-19, ARCH 5.9).
     *
     * 계정 관리(canManageUsers)와 같은 자격이지만 **판정 함수를 따로 둔다** — 라우트 정책
     * 이름(`org.manage`)이 감사 로그(`authz.route deny` 의 detail.policy)와 라우트 표에서
     * "계정을 관리하려 했나, 조직을 관리하려 했나" 를 이름만으로 가르게 하기 위해서다.
     * 한쪽의 자격이 바뀌어도 다른 쪽이 따라 바뀌지 않는다.
     *
     * 매트릭스는 **읽기 전용**이다. 편집 경로는 이 자격으로도 열리지 않는다 —
     * 앱 DB 계정이 `permissions` 에 SELECT 만 갖는 것이 권한 상승 방어의 핵심이다
     * (ARCH 6.2-1, SECURITY R-43, PRD Q-12).
     */
    public static function canManageOrg(array $u): bool
    {
        return self::roleOf($u) === 'Admin';
    }

    /**
     * PRD F-21 — 위키(`/wiki`·`/wiki/{slug}`) 열람은 Admin 만 (14단계, SECURITY R-56).
     *
     * 왜 Admin 만인가: 위키의 SECURITY·STAGES 해설에는 남은 위험(R-xx)·서버 설정·사고 경위가
     * 초보자도 읽을 수 있게 풀려 있다. Guest(협력사)·Employee 가 읽으면 그것이 곧 공격 지도다
     * (SECURITY R-56). 범위를 넓히려면 라우트 정책과 함께 R-56 을 다시 판단한다.
     *
     * 라우트 정책은 새로 만들지 않고 기존 `role:Admin` 을 쓴다 — 자격이 휴지통·계정 관리와
     * 같고, 위키에는 "관리하는 행위" 가 없어서 감사 로그에서 갈라 볼 이유가 없기 때문이다
     * (조직 관리에 `org.manage` 를 따로 둔 것과 반대 경우다). 그래서 KNOWN_POLICIES 도
     * 그대로다. 이 함수는 `/api/me` 의 화면 편의 값 `can_view_wiki` 를 만드는 데 쓴다.
     */
    public static function canReadWiki(array $u): bool
    {
        return self::roleOf($u) === 'Admin';
    }

    /* ─── 10단계: 계정 관리 (F-10) — 역할에 관한 모든 판단은 여기 모은다 ───────── */

    /**
     * 요청이 보낸 역할 문자열을 계정에 넣어도 되나 (허용 목록 정확 일치).
     * 핸들러는 이 함수를 통과한 값만 컬럼에 쓴다 — 요청 값이 그대로 role 이 되지 않게 하는 자리다.
     */
    public static function isAssignableRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /** 이 역할의 계정은 만료일(expires_at)이 반드시 있어야 하나 (SECURITY R-12, Guest) */
    public static function requiresExpiry(string $role): bool
    {
        return $role === 'Guest';
    }

    /** 이 역할의 계정이 팀에 속할 수 있나 (PRD 3장 "Guest 는 팀 없음") */
    public static function allowsTeam(string $role): bool
    {
        return $role !== 'Guest';
    }

    /** 이 계정이 "활성 관리자" 인가 — 마지막 Admin 보호 판정에 쓴다 */
    private static function isActiveAdmin(array $u): bool
    {
        return self::roleOf($u) === 'Admin' && ($u['is_active'] ?? false) === true;
    }

    /** 새 역할이 관리자 자리를 내려놓는 변경인가 */
    private static function losesAdmin(string $newRole): bool
    {
        return $newRole !== 'Admin';
    }

    private const SQL_ACTIVE_ADMINS = <<<'SQL'
        SELECT count(*) AS n FROM users WHERE role = 'Admin' AND is_active = true
        SQL;

    /** 지금 활성 상태인 Admin 계정 수 */
    private static function activeAdminCount(): int
    {
        $row = Db::instance()->run(self::SQL_ACTIVE_ADMINS)->fetch();
        return is_array($row) ? (int) $row['n'] : 0;
    }

    /**
     * PRD 5.4 / F-10 — Admin 이 **다른 계정**을 고칠 수 있나 + 거부 코드 (10단계).
     *
     * 라우트 정책 `user.manage` 가 "Admin 인가" 를 이미 봤다. 여기서 보는 것은 두 가지 금지다.
     *
     *   ① `self_change` — 자기 자신을 고치는 요청은 통째로 막는다.
     *      자기 역할 올리기·내리기, 자기 비활성화가 전부 여기서 끝난다. 계정 관리는 "남의 계정"
     *      에 대한 행위이고, 자기 계정의 비밀번호·2단계 인증은 `/api/me/*` 에 따로 있다.
     *   ② `last_admin` — 마지막 활성 Admin 을 강등하거나 비활성화하면 아무도 계정을 관리할 수
     *      없게 된다(잠김). 활성 Admin 수를 세어 1명일 때 그 사람에 대한 강등·비활성화를 막는다.
     *
     * 이 두 판단은 전부 역할을 보는 판단이므로 Authz 안에 있다 (ARCH 4.1).
     *
     * @param array<string,mixed> $actor   요청을 보낸 Admin
     * @param array<string,mixed> $target  고치려는 계정의 **현재** DB 행 (role·is_active 필수)
     * @param array<string,mixed> $changes 적용하려는 값 (role·is_active 만 본다)
     * @return array{0:bool,1:string} [허용 여부, 코드]
     */
    public static function decideUserChange(array $actor, array $target, array $changes): array
    {
        if ((int) $actor['id'] === (int) $target['id']) {
            return [false, self::CODE_SELF_CHANGE];
        }

        $losesRole   = isset($changes['role']) && self::losesAdmin((string) $changes['role']);
        $turnedOff   = array_key_exists('is_active', $changes) && $changes['is_active'] === false;

        if (self::isActiveAdmin($target) && ($losesRole || $turnedOff) && self::activeAdminCount() <= 1) {
            return [false, self::CODE_LAST_ADMIN];
        }

        return [true, self::CODE_ADMIN_ALL];
    }

    /** 업로드 자체가 가능한 역할인가 (Guest 는 불가 — PRD 5.2) */
    public static function canUploadAtAll(array $u): bool
    {
        $role = self::roleOf($u);
        foreach (['public', 'internal', 'team', 'admin-only'] as $level) {
            if (self::flag($role, $level, 'upload')) {
                return true;
            }
        }
        return false;
    }

    /**
     * 라우트 정책 이름 → 검사 함수 매핑 (ARCH 4장 [4], SECURITY R-04)
     *
     * 라우터가 핸들러를 실행하기 전에 부른다. 여기서 false 면 핸들러 코드는 아예 실행되지 않는다.
     *
     * 주의 — 행(row) 단위 검사와 라우트 단위 검사의 구분:
     *   라우터 단계에는 아직 대상 파일을 읽지 않았으므로 file.* 정책은
     *   "이 역할이 그 종류의 행위를 할 수 있는가"까지만 본다.
     *   **행(row) 단위 판정은 핸들러의 decide\*()** 가 한다 — 핸들러가 파일 행을 읽은 뒤
     *   decideRead/decideDelete/decideUploadAs 를 불러 판정과 근거 코드를 함께 받는다(4단계).
     *   $context 에 'file' 이 들어오면 그 행 검사까지 여기서 함께 수행한다.
     *
     * @param array<string,mixed>      $u
     * @param array<string,mixed>|null $context
     */
    public static function authorize(string $policy, array $u, ?array $context = null): bool
    {
        $file = is_array($context) && isset($context['file']) && is_array($context['file'])
            ? $context['file']
            : null;

        switch ($policy) {
            case self::POLICY_PUBLIC:
                return true;

            case self::POLICY_AUTHENTICATED:
                return true;     // 라우터가 이미 requireLogin() 을 통과시켰다

            case 'role:Admin':
                return self::roleOf($u) === 'Admin';

            case 'role:Manager+':
                return in_array(self::roleOf($u), ['Admin', 'Manager'], true);

            case 'file.read':
                return $file === null ? true : self::canRead($u, $file);

            case 'file.upload':
                return self::canUploadAtAll($u);

            case 'file.update':
                // 라우트 단계는 "고칠 수 있는 종류의 역할인가" 까지만 본다 (Guest 는 여기서 끝).
                // 특정 파일에 대한 판단은 핸들러의 decideRead → decideEdit → decideLevelChange 다 (ARCH 5.7).
                return self::canUploadAtAll($u);

            case 'file.delete':
                return $file === null ? self::canUploadAtAll($u) : self::canDelete($u, $file);

            case 'file.fetch':
                return self::canFetch($u);

            case 'audit.read':
                return self::canReadAudit($u);

            case 'user.manage':
                return self::canManageUsers($u);

            case 'org.manage':
                return self::canManageOrg($u);

            default:
                // 알 수 없는 정책 이름은 통과시키지 않는다 (기본 거부)
                return false;
        }
    }
}
