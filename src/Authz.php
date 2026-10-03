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
        /*
         * 커뮤니티 3단계 (C:S-01, 커뮤니티 ARCHITECTURE.md 21.2).
         *
         * 커뮤니티 라우트 표(`src/community/routes.php`)가 쓰는 정책은 이 둘뿐이다. 여기 적는
         * 이유는 t-box 와 같다 — 라우트의 정책이 `KNOWN_POLICIES` 밖이면 **부팅이 실패**하므로
         * 「정책을 빠뜨린 라우트」가 코드 리뷰가 아니라 첫 요청에서 드러난다 (SECURITY R-04).
         *
         *   community.read   로그인한 사람이면 통과 (= authenticated). 행 단위 판정이 아니다 —
         *                    그 페이지를 볼 수 있는지는 핸들러가 `CommunityAuthz::decidePageRead`
         *                    로 다시 판단한다 (file.read 와 같은 구조).
         *   community.write  쓰는 종류의 행위를 할 수 있는 역할인가 (Guest 는 여기서 끝난다).
         *                    그 페이지를 고칠 수 있는지는 `CommunityAuthz::decidePageChange` 가 본다.
         */
        'community.read',
        'community.write',
        'travel.read',
        'travel.write',
        /*
         * Q:F-9 (t-box F-75, ARCH 4.9) — 「이 PC 로그인 전용」 토큰이 1회용 로그인 링크를 받는 창구 하나
         * (`POST /api/me/session-link`)의 정책. 토큰 범위 `session.open` 만 이 정책을 연다(tokenScopeAllows) —
         * 이름을 따로 두는 까닭은 `authenticated` 를 재사용하면 **다른 범위의 토큰**도 이 창구를 열게 되기 때문이다.
         * 계정 자격은 아래 authorize() 가 `canOpenSessionByToken`(2단계 켜짐 · 쓰기 자격 = Guest 0)으로 본다.
         */
        'session.open',
        'assistant.run', // 별도 개인 비서 범위. 파일·로그인 범위와 독립이다.
    ];

    /**
     * 관리 기능 재인증(sudo 모드)이 필요한 **라우트 정책** (16-1, PRD F-23, ARCH 4.0).
     *
     * 여기 든 정책이 붙은 라우트는 세션이 있어도 **최근 15분 안에 비밀번호를 다시 확인한
     * 세션**만 쓸 수 있다. 관리 영역이 곧 이 네 정책이다:
     *   user.manage  계정 관리 (`/users`, `/api/users*`)
     *   org.manage   조직·권한 (`/org`, `/api/org/*`)
     *   audit.read   감사 로그 (`/audit`, `/api/audit`)
     *   role:Admin   위키·관리자 영역 등 (`/wiki*`, `/admin*`, 커뮤니티 관리 창구)
     *   (휴지통 셋 `/trash`·`/api/files/trash`·`/api/files/{id}/restore` 는 Q:F-4 U-2b 부터 정책이
     *    `authenticated` 다 — Admin 의 **전체** 휴지통 재인증은 핸들러가 `restorableFilesWhere` 의
     *    범위 코드 `admin_all` 을 보고 같은 모양으로 묻는다. 본인 휴지통은 묻지 않는다.)
     *
     * 목록이 **정책 이름**인 이유: 경로 목록으로 두면 라우트를 하나 더할 때마다 두 곳을
     * 고쳐야 하고 언젠가 한쪽을 빠뜨린다. 정책은 라우트 표에 이미 하나씩 붙어 있으므로,
     * 새 관리 라우트를 만들면 재인증이 **자동으로** 따라붙는다 (SECURITY R-04 와 같은 원칙).
     *
     * 여기 **없는** 것: `authenticated`(본인 계정 창구 `/api/me/*` 는 이미 현재 비밀번호·코드를
     * 묻는다), `file.*`(일상 업무를 15분마다 끊지 않는다 — PRD F-23 "본인 계정 기능은 대상이
     * 아니다", T-36 의 `/api/me/password`·`/api/files` 행).
     * 다만 `file.*` 라우트 안의 **Admin 결정 둘** — 파일 등급 내리기(노출 넓히기)·공유 링크 만들기 — 은
     * 핸들러가 행을 읽은 뒤 같은 15분 창을 본다(디버깅 묶음 S-8 · PRD F-23 「파일 창구의 Admin 결정」 ·
     * ARCH 4.5 「등급·팀 변경을 토큰에 열지 않는 이유」의 전제). 판정은 아래 levelChangeNeedsReauth ·
     * shareCreateNeedsReauth 두 함수이고 라우트 정책 목록은 그대로다(일상 업무 PATCH·목록은 묻지 않는다).
     *
     * @var list<string>
     */
    public const REAUTH_POLICIES = [
        'user.manage',
        'org.manage',
        'audit.read',
        'role:Admin',
    ];

    /**
     * Q:F-9 — **토큰 출신 세션**(`sessions.origin = 'token'`)만 더 묻는 정책 (t-box F-75 ⑤, ARCH 4.9 「재인증 집합 둘」).
     *
     * 「이 PC 로그인 전용」 토큰의 1회용 링크로 만든 세션은 비밀번호도 2단계 코드도 치지 않고 생긴다(F-49 ① 의 예외 —
     * SECURITY R-113). 그래서 일상 업무(보기·올리기·고치기)는 그대로 두되, **되돌리기 어려운 일**은 15분 창(비밀번호 재확인)을
     * 먼저 요구한다. 일반 로그인 세션(`origin = 'login'`)은 이 목록을 **보지 않는다** — REAUTH_POLICIES 하나 그대로다.
     *
     *   REAUTH_POLICIES 전부(관리 영역 — 계정·조직·감사·role:Admin = `admin.*` 전부) + `file.delete`(파일 삭제 · 폴더 삭제 둘 다 이 정책)
     *
     * 라우트 정책 하나로 가를 수 없는 것(정책이 `authenticated`·`file.update`·`community.*` 처럼 일상 창구와 같은 것)은
     * 아래 TOKEN_SESSION_REAUTH_ROUTES 의 **라우트 패턴**으로 가른다.
     *
     * @var list<string>
     */
    public const TOKEN_SESSION_REAUTH_POLICIES = [
        'user.manage',
        'org.manage',
        'audit.read',
        'role:Admin',
        'file.delete',
    ];

    /**
     * Q:F-9 — 토큰 출신 세션만 더 묻는 **라우트** (`TBOX_ROUTE` 표기 — `메서드 라우트패턴`, 커뮤니티는 `/community` 접두까지).
     *
     * 값은 두 라우트 표(`public/index.php` TBOX_ROUTES · `src/community/routes.php` COMMUNITY_ROUTES)에 **실제로 있는 줄**만 적는다
     * (시험 `tests/qf9_pc_login.sh` T-7 이 한 줄씩 대조한다 — 오타로 죽은 줄이 되면 그 창구가 조용히 열린다).
     * 목록(지시서 5 의 좁은 쪽 기본안 — 구현 기록 docs/qf9-implementation.md 에 확정 목록과 까닭):
     *   · 내 계정 보안(바꾸는 창구만) — 비밀번호 변경 · 2단계 인증 켜기/확정/끄기 · 프로그램 토큰 발급/회수 · 지문·얼굴 기기 등록/해제
     *     (메인 판단 2026-09-29: 토큰 목록·패스키 목록 **조회**와 화면 설정(고대비)은 민감한 「변경」이 아니라 뺐다)
     *   · 파일 — 공유 링크 만들기(`file.update` 정책이라 라우트로)
     *   · 커뮤니티 — 지우기(페이지·템플릿·댓글) · 옮기기 · 공개 범위(visibility·게시·게시 내리기·숨기기·댓글 숨기기) · 공유(넣기·빼기) · 인계
     * 여기 **없는** 것(일부러): 로그아웃 · 재인증 창구 셋(비밀번호·지문 — 묻는 창구가 묻는 창구를 막으면 들어갈 길이 없다) ·
     *   역할로 보기 끄기(권한을 줄이는 쪽) · 공유 링크 회수(닫는 쪽) · 휴지통에서 되살리기 · URL 가져오기 · 일상 읽기·쓰기.
     *
     * @var list<string>
     */
    public const TOKEN_SESSION_REAUTH_ROUTES = [
        // 내 계정 보안
        'POST /api/me/password',
        'POST /api/me/mfa/enroll',
        'POST /api/me/mfa/confirm',
        'POST /api/me/mfa/disable',
        'POST /api/me/api-tokens',
        'DELETE /api/me/api-tokens/{num}',
        'POST /api/me/passkeys/options',
        'POST /api/me/passkeys',
        'DELETE /api/me/passkeys/{num}',
        // 파일 — 공유 링크 만들기
        'POST /api/files/{id}/share',
        // Sharing private items changes who can read them, including approval of a request.
        'POST /api/files/{id}/grants',
        'DELETE /api/files/{id}/grants/{num}',
        'POST /api/folders/{num}/grants',
        'DELETE /api/folders/{num}/grants/{num}',
        'POST /api/access-requests/{num}',
        // 커뮤니티 — 지우기
        'DELETE /community/api/pages/{id}',
        'DELETE /community/api/templates/{id}',
        'DELETE /community/api/comments/{id}',
        // 커뮤니티 — 옮기기
        'PATCH /community/api/pages/{id}/move',
        // 커뮤니티 — 공개 범위
        'PATCH /community/api/pages/{id}/visibility',
        'POST /community/api/pages/{id}/publish',
        'DELETE /community/api/pages/{id}/publish',
        'POST /community/api/pages/{id}/hide',
        'DELETE /community/api/pages/{id}/hide',
        'POST /community/api/comments/{id}/hide',
        'DELETE /community/api/comments/{id}/hide',
        // 커뮤니티 — 공유 · 인계
        'PUT /community/api/pages/{id}/shares/{username}',
        'DELETE /community/api/pages/{id}/shares/{username}',
        'POST /community/api/pages/{id}/handover',
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
    /**
     * 18-1 — 폴더 불변 규칙 ①: 하위(파일·폴더)의 등급이 부모 폴더보다 **넓다**
     * (PRD F-26, ARCH 5.16). `fitsUnderFolder` 가 돌려주는 유일한 거부 코드다.
     */
    public const CODE_PARENT_NARROWER = 'parent_narrower';
    /** 10단계 — 계정 관리(F-10) 거부 코드 (ARCH 7.4 `user.*|deny|…` 행) */
    public const CODE_SELF_CHANGE = 'self_change';   // 자기 역할 변경·자기 비활성화
    public const CODE_LAST_ADMIN  = 'last_admin';    // 마지막 활성 Admin 을 낮추거나 끄기
    /**
     * Q:F-4 U-2b — 휴지통 복구의 **유일한** 거부 코드 (ARCH 7.4 `file.restore|deny|not_found_in_trash`).
     * 남이 올린 파일 · 남이 지운 내 파일 · 올릴 수 없는 역할 — 전부 「휴지통에 없는 파일」과 같은 코드다.
     * 거부 사유를 가르면 「내 활동」(F-12)에서 그 UUID 의 파일이 휴지통에 **있다**는 신호가 된다.
     */
    public const CODE_NOT_FOUND_IN_TRASH = 'not_found_in_trash';

    /*
     * G-0 (PRD F-73, ARCH 4.1 「개인 등급」) — **허용 코드 셋**. 거부 코드는 새로 만들지 않는다:
     *   읽기 거부 = `role_not_permitted`(표의 읽기 칸이 전부 거짓이다 — 사람으로도 닿지 않았다) ·
     *   고치기·지우기·주인 몫 거부 = `not_owner`. 그래서 ARCH 7.4 문장 표의 기존 행이 그대로 성립한다.
     *   허용 코드는 감사 `detail.granted_by` 로 남는다(ARCH 7.5).
     */
    /** 개인 항목 — 받은 권한(이 항목이나 위 개인 폴더에 준 보기·편집)으로 읽기 허용 */
    public const CODE_GRANT          = 'grant';
    /** 개인 항목 — 받은 「편집」 권한으로 올리기·이름·설명 고치기 허용 */
    public const CODE_GRANT_WRITE    = 'grant_write';
    /** 개인 항목 — 주인도 받은 사람도 아닌 Admin 의 특권 열람(주소로 열 때만 · 목록에는 없다 · 감사에 남는다) */
    public const CODE_ADMIN_OVERRIDE = 'admin_override';

    /** G-0 — 「개인」 등급 이름. 이 등급은 권한 표가 아니라 사람(주인·받은 권한)으로 판정한다. */
    public const LEVEL_PRIVATE = 'private';

    /**
     * 10단계 — 계정에 줄 수 있는 역할 (PRD 3장 4종, db/schema.sql users_role_check 와 같은 값).
     *
     * 요청 본문의 역할 문자열을 **그대로** 컬럼에 넣지 않는다. 이 목록에 정확히 있는 값만
     * 통과시키고(`isAssignableRole`), 그 검사도 역할 판단이므로 이 파일 안에서만 한다.
     */
    private const ROLES = ['Admin', 'Manager', 'Employee', 'Guest'];

    /** 화면 선택지(`/api/me` 의 upload_levels)에 쓰는 레벨 표시 순서 (PRD 4장 순서 그대로) */
    private const LEVEL_ORDER = ['public', 'internal', 'team', 'admin-only', 'private'];

    /**
     * 레벨의 "노출 범위" 순서 (PRD 4장). 숫자가 작을수록 더 많은 사람이 본다.
     * 값을 낮추는 변경(`admin-only` → `public`)이 곧 노출 범위를 넓히는 변경이다 (SECURITY R-20).
     */
    public const LEVEL_RANK = [
        'public'     => 0,
        'internal'   => 1,
        'team'       => 2,
        'admin-only' => 3,
        // G-0 (PRD F-73) — 가장 좁다. 개인 폴더 아래에는 개인만 들어간다(fitsUnderFolder 가 rank 로 그대로 막는다) ·
        // 개인 항목은 어느 폴더 아래든 들어간다. 개인 → 다른 등급은 언제나 「넓히기」다(R-20 — Admin 만 · 사유 · 감사).
        'private'    => 4,
    ];

    private const SQL_MATRIX = <<<'SQL'
        SELECT role, access_level, can_read, can_upload FROM permissions
        SQL;

    /** @var array<string,array{read:bool,upload:bool}>|null  "역할|레벨" => 플래그 */
    private static ?array $matrix = null;

    /** 오래 사는 비서 워커/SSE는 단계마다 권한 표를 다시 읽는다. 판정 규칙은 그대로다. */
    public static function refreshPermissions(): void
    {
        self::$matrix = null;
    }

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
        return self::visibleWhere($u, 'f');
    }

    /**
     * 18-1 — 같은 조건을 **다른 표에도** 붙이기 위해 별칭을 받는 일반형 (PRD F-26, ARCH 5.16).
     *
     * 폴더도 파일과 같은 등급 체계를 갖게 되면서(사용자 결정 2026-09-13) 트리 조회
     * (`folders_list.php`)도 똑같은 조건을 `folders d` 에 붙여야 한다. 조건 문자열을 그쪽에
     * 복사해 두면 언젠가 한쪽만 고쳐져 어긋난다 — 그래서 **판정 조건은 이 함수 하나**이고
     * `visibleFilesWhere` 는 별칭 `f` 를 넣어 이것을 부르는 얇은 껍데기다(기존 호출부 무변경).
     *
     * 별칭은 **SQL 식별자 자리**라 바인딩할 수 없다. 그래서 코드에 적힌 허용 목록
     * (`f` = files · `d` = folders) 밖의 값은 예외로 끝낸다 — 요청 값이 이 자리에 닿는 경로는
     * 어디에도 없지만(부르는 쪽이 리터럴을 넘긴다), 허용 목록 없이 이어 붙이기 시작하면
     * 언젠가 값이 섞인다 (PRD S-04, ARCH 5.1 [3] ORDER BY 와 같은 원칙).
     *
     * @param array<string,mixed> $u
     * @param string              $alias 코드에 적힌 리터럴만 (`f` 또는 `d`)
     * @return array{0:string,1:array<string,mixed>}
     */
    public static function visibleWhere(array $u, string $alias): array
    {
        if (!in_array($alias, ['f', 'd'], true)) {
            throw new RuntimeException('허용되지 않는 테이블 별칭입니다');
        }
        // `folders` 에는 논리 삭제가 없다 — 빈 폴더는 실제로 지운다(ARCH 5.16 "삭제").
        // 조건의 모양을 하나로 유지하려고 그쪽에는 언제나 참인 조각을 둔다.
        $alive = $alias === 'f' ? $alias . '.deleted_at IS NULL' : 'TRUE';

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
            $clauses[] = $alias . '.access_level IN (' . implode(', ', $ph) . ')';
        }

        if (in_array('team', $levels, true)) {
            if (self::seesAllTeams($role)) {
                $clauses[] = $alias . ".access_level = 'team'";
            } elseif ($teamId !== null) {
                $clauses[]        = '(' . $alias . ".access_level = 'team' AND " . $alias . '.team_id = :own_team)';
                $params[':own_team'] = $teamId;
            }
        }

        /* G-0 (PRD F-73) — 「개인」 갈래. **decideRead 의 [P] 갈래와 짝**이다(privateChainSql 한 벌을 둘이 함께 쓴다):
         *   (x.access_level = 'private' AND 이 항목·위 개인 폴더의 주인이 나 · 또는 거기 나에게 준 권한)
         * 역할을 보지 않는다 — 표의 읽기 칸은 전부 거짓이고(DB CHECK permissions_private_by_people), 사람으로만 열린다.
         * ★ Admin 특권(`admin_override`)은 여기 **없다** — 목록·검색·즐겨찾기·최근·개수에는 내 것·받은 것만 나오고(지시서 ★),
         *   Admin 이 남의 개인 항목을 보는 일은 주소로 하나씩 열 때만이며 그때마다 감사에 남는다(decideRead). */
        $me = isset($u['id']) ? (int) $u['id'] : 0;
        if ($me > 0) {
            [$pvSql, $pvBind] = self::privateVisibleSql($alias, $me);
            $clauses[] = $pvSql;
            $params    = array_merge($params, $pvBind);
        }

        if ($clauses === []) {
            // 볼 수 있는 것이 하나도 없는 경우에도 삭제 조건을 함께 둔다(형태를 하나로 유지).
            return ['(' . $alive . ' AND FALSE)', []];
        }
        return ['(' . $alive . ' AND (' . implode(' OR ', $clauses) . '))', $params];
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

        /* [P] G-0 (PRD F-73) — 「개인」 은 표가 아니라 사람으로 판정한다. **visibleWhere 의 개인 갈래와 짝**:
         *   주인·받은 권한(privateReach — 목록 조건절과 같은 SQL 조각) → 허용 `owner`·`grant` / 그 밖 Admin → `admin_override`
         *   (목록에는 없는 갈래 — 주소로 열 때만 · 감사에 남는다) / 그 밖 → `role_not_permitted`(표의 읽기 칸이 거짓이다).
         * 행에 종류 표식(`item_kind`)·번호가 없으면 닫힌 실패다 — 표식을 빠뜨린 호출부는 개인 항목을 **못 보는** 쪽으로 틀린다. */
        if ($level === self::LEVEL_PRIVATE) {
            $reach = self::privateReachOf($u, $file);
            if ($reach === 'owner') {
                return [true, self::CODE_OWNER];
            }
            if ($reach === 'write' || $reach === 'view') {
                return [true, self::CODE_GRANT];
            }
            if (self::canAdmin($u)) {
                return [true, self::CODE_ADMIN_OVERRIDE];
            }
            return [false, self::CODE_ROLE_NOT_PERMITTED];
        }

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
        if ($level === self::LEVEL_PRIVATE && $teamId !== null) {
            // G-0 — 「개인」 은 팀이 없다(DB CHECK files_private_no_team · folders_private_no_team 과 같은 규칙)
            return [false, self::CODE_TEAM_MISMATCH];
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
        // G-0 — 개인 항목의 지우기는 **주인만**(지시서 ★ · 편집 권한자는 지우지 못한다 · Admin 특권 없음)
        if ((string) ($file['access_level'] ?? '') === self::LEVEL_PRIVATE) {
            return self::decidePrivateOwner($u, $file);
        }
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
        /* G-0 — 개인 항목은 주인(`owner`) · 「편집」 권한을 받은 사람(`grant_write`)이 고친다 — 이름·설명까지.
         * 등급·팀·위치·공유 링크·권한 주기는 이것을 지난 **뒤** decidePrivateOwner 가 주인만으로 다시 좁힌다(지시서 ★).
         * Admin 특권은 없다(읽기 특권만 — decideRead). */
        if ((string) ($file['access_level'] ?? '') === self::LEVEL_PRIVATE) {
            $reach = self::privateReachOf($u, $file);
            if ($reach === 'owner') {
                return [true, self::CODE_OWNER];
            }
            if ($reach === 'write') {
                return [true, self::CODE_GRANT_WRITE];
            }
            return [false, self::CODE_NOT_OWNER];
        }
        return self::decideOwnership($u, $file);
    }

    /** Personal rename: existing read/edit rules plus exact uploader ownership. */
    public static function decideRename(array $u, array $file): array
    {
        [$mayRead, $readCode] = self::decideRead($u, $file);
        if (!$mayRead) {
            return [false, $readCode];
        }
        if ((int) $file['uploaded_by'] !== (int) $u['id']) {
            return [false, self::CODE_NOT_OWNER];
        }
        return self::decideEdit($u, $file);
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

        /* G-0 — 「개인」 이 걸린 등급 바꾸기는 **주인만**이다(지시서 ★).
         *   개인 → 다른 등급(넓히기): 주인(privateReach 'owner') — 그다음 아래 ① R-20(Admin 만 넓힌다)을 그대로 지난다.
         *   다른 등급 → 개인(좁히기): 그 항목을 **올린·만든 사람**만 — 개인의 「나」 는 그 사람이다. 팀장이 팀원 파일을
         *   개인으로 돌리면 팀장도 팀원도 못 보는 파일이 된다(주인 한 사람만 남는다) — 그 길을 만들지 않는다. */
        if ($current === self::LEVEL_PRIVATE && $newLevel !== self::LEVEL_PRIVATE) {
            [$isOwner, $ownerCode] = self::decidePrivateOwner($u, $file);
            if (!$isOwner) {
                return [false, $ownerCode];
            }
        } elseif ($current !== self::LEVEL_PRIVATE && $newLevel === self::LEVEL_PRIVATE) {
            if (!self::isItemCreator($u, $file)) {
                return [false, self::CODE_NOT_OWNER];
            }
        }

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
     * 디버깅 묶음 S-8 — 이 등급 변경에 **15분 재인증**(PRD F-23 창)을 물어야 하나.
     *
     * 등급을 **낮추는**(노출 범위를 넓히는) 변경은 Admin 만 할 수 있고(mayDowngrade, R-20), ARCH 4.5 는
     * 「그 결정은 재인증 관문 대상이라 토큰 경로에 열지 않는다」를 전제로 쓴다. 그런데 파일 창구의 라우트 정책
     * `file.update` 는 REAUTH_POLICIES 밖이라, 훔친 Admin 세션(자리 비움)만으로 admin-only → public 이 됐다
     * (2026-09-26 보안 점검 낮음 10). 그래서 **낮추는 변경을 실제로 하는 경우에만** 참이다 — 같은 등급·올리기·
     * 팀만 바꾸기·설명·폴더 이동은 묻지 않는다(일상 업무를 15분마다 끊지 않는다는 F-23 원칙 그대로).
     * decideLevelChange 가 **허용한 뒤**에 부른다(권한이 없는 사람에게는 재인증이 아니라 403 이 먼저다 —
     * 재인증은 권한을 만들지 않는다).
     *
     * @param array<string,mixed> $u
     */
    public static function levelChangeNeedsReauth(array $u, string $currentLevel, string $newLevel): bool
    {
        return isset(self::LEVEL_RANK[$newLevel], self::LEVEL_RANK[$currentLevel])
            && self::LEVEL_RANK[$newLevel] < self::LEVEL_RANK[$currentLevel]
            && self::mayDowngrade(self::roleOf($u));
    }

    /**
     * 디버깅 묶음 S-8 — 이 공유 링크 만들기에 **15분 재인증**을 물어야 하나.
     *
     * 공유 링크는 로그인하지 않은 사람에게 파일을 내보내는 길이고, 세션이 끊긴 뒤에도 남는다(만료까지).
     * Admin 은 **모든** 파일(admin-only 포함)의 링크를 만들 수 있다 — decideEdit 의 허용 코드가 `admin_all` 인
     * 경우다. 그 경우만 묻는다(본인·팀 규칙으로 허용된 링크는 묻지 않는다). 핸들러는 역할을 보지 않고 이 함수에
     * decideEdit 가 돌려준 **허용 코드**만 넘긴다(휴지통의 `admin_all` 재인증과 같은 모양 — restorableFilesWhere).
     */
    public static function shareCreateNeedsReauth(string $editCode): bool
    {
        return $editCode === self::CODE_ADMIN_ALL;
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
     * G-0 — **목록과 같은 뜻**의 한 건 판정: decideRead 가 허용하되 Admin 특권(`admin_override`)으로만 열린 것은 뺀다.
     * visibleWhere(목록 조건절)와 한 줄씩 짝이다 — 목록 대신 한 건씩 거르는 자리(폴더 지우기의 휴지통 목록 등)가 쓴다.
     * 시험 g0_private_grants.sh 의 짝 대조가 「이 값 = 목록 조건절 포함 여부」를 전수로 본다.
     *
     * @param array<string,mixed> $u
     * @param array<string,mixed> $row
     */
    public static function canSeeInList(array $u, array $row): bool
    {
        [$ok, $code] = self::decideRead($u, $row);
        return $ok && $code !== self::CODE_ADMIN_OVERRIDE;
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

    /**
     * PRD F-24 — 관리자 영역(`/admin`·`/admin/permissions`·`/api/admin/*`)은 Admin 만
     * (17-1, ARCH 5.14, SECURITY R-61).
     *
     * 라우트 정책은 새로 만들지 않고 기존 `role:Admin` 을 쓴다 — 자격이 휴지통·위키와 같고,
     * 그 정책은 이미 `REAUTH_POLICIES` 에 들어 있어 **재인증 관문이 자동으로 따라붙는다**
     * (16-1, ARCH 4.0). 그래서 KNOWN_POLICIES 도 그대로다.
     *
     * 이 함수는 `/api/me` 의 화면 편의 값 `can_admin` 을 만드는 데 쓴다 —
     * 일반 셸에서 「관리자 페이지」 한 항목을 열지 말지 고르는 값이며 **권한이 아니다**.
     * 항목을 개발자도구로 되살려 `/admin` 이나 `/api/admin/*` 를 직접 불러도 라우터 정책
     * `role:Admin` 과 재인증 관문이 다시 막는다 (PRD S-01, T-38).
     */
    public static function canAdmin(array $u): bool
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

    /**
     * PRD F-13 — 공유 링크를 **회수**할 수 있나 + 근거 코드 (15-2, ARCH 5.12 「회수·목록」).
     *
     * 규칙은 한 줄이다: **만든 사람 또는 Admin**, 그 밖은 `not_owner`.
     *
     * 왜 여기 있나 — "만든 사람 = 나인가" 는 소유자 판단이고, 소유자·역할 판단은 이 파일
     * 한 곳에만 둔다 (ARCH 4.1, PRD S-01). 핸들러가 `$link['created_by'] === $user['id']` 를
     * 직접 비교하면 그 순간 권한 판단이 핸들러로 흩어진다 — 파일 삭제·수정(decideOwnership)과
     * 같은 이유로 막는다.
     *
     * 파일의 소유자·팀 규칙(decideEdit)을 재사용하지 않는 이유: 회수의 대상은 **링크**이지
     * 파일이 아니다. 링크를 만든 뒤 그 파일이 남에게 넘어가도 링크를 거둘 사람은 발급자와
     * Admin 이며, 반대로 파일을 고칠 수 있게 된 사람이 남이 만든 링크까지 거둘 이유는 없다.
     *
     * 허용 코드는 기존 것을 그대로 쓴다 — `admin_all`(Admin) · `owner`(만든 사람).
     * 새 코드를 만들지 않으므로 7.4 사유 등록부·7.5 granted_by 표가 그대로 성립한다.
     *
     * @param array<string,mixed> $actor 요청을 보낸 사용자 (세션에서 읽은 값)
     * @param array<string,mixed> $link  share_links 행 (created_by 필수)
     * @return array{0:bool,1:string} [허용 여부, 코드]
     */
    public static function decideShareRevoke(array $actor, array $link): array
    {
        if (self::roleOf($actor) === 'Admin') {
            return [true, self::CODE_ADMIN_ALL];
        }
        if ((int) $link['created_by'] === (int) $actor['id']) {
            return [true, self::CODE_OWNER];
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /* ─── Q:F-4 U-2b: 휴지통 복구 — 본인이 지운 본인 파일 (PRD 5.4 각주 ¹ · F-11 · 8장) ─── */

    /**
     * 휴지통의 **모든** 파일을 보고 되살리는 역할인가 (PRD 5.4 「Admin ✅ 모든 파일」).
     * 휴지통 판정의 역할 비교는 이 한 줄뿐이다 — decideRestore·restorableFilesWhere 가 함께 쓴다.
     */
    private static function restoresAll(string $role): bool
    {
        return $role === 'Admin';
    }

    /**
     * 휴지통 목록의 SQL 조건 + 복구 범위 코드 (PRD 5.4 각주 ¹ · 8장 `GET /api/files/trash` 행, S-02).
     *
     * `decideRestore` 와 **같은 규칙**을 목록 SQL 로 옮긴 것이다 — 목록은 애플리케이션에서 나중에
     * 거르지 않고 DB 가 애초에 남의 행을 돌려주지 않는다(visibleFilesWhere 와 같은 원칙).
     *
     *   Admin                  → `TRUE`                                              · `admin_all`
     *   올릴 수 있는 역할       → `(f.uploaded_by = :rs_me_up AND f.deleted_by = :rs_me_del)` · `owner`
     *   그 밖(Guest)           → `FALSE`                                             · `not_found_in_trash`
     *
     * 셋째 값(범위 코드)은 핸들러가 **재인증**을 물을지 고르는 데 쓴다: `admin_all` 이면 전과 같이
     * 15분 재인증(PRD F-23), `owner` 면 묻지 않는다(본인 자료 — 커뮤니티 휴지통과 같다).
     * 핸들러는 역할을 보지 않고 이 코드만 견준다(커뮤니티 인계의 `admin_all` 재인증과 같은 모양).
     *
     * 휴지통 조건(`deleted_at IS NOT NULL AND purged_at IS NULL`)은 부르는 SQL 이 따로 적는다 —
     * 이 조각은 「누구의 것인가」만 본다. 별칭 `f` 를 전제로 하고(`FROM files f`), 요청 값이 들어갈
     * 자리는 없다(바인딩 값은 세션 사용자의 id 뿐). 같은 이름의 자리표시자를 두 번 쓰지 않는다.
     *
     * 「올릴 수 있는 역할」을 역할 이름이 아니라 권한 표로 본다(canUploadAtAll) — Guest 는 표에서
     * 올리기가 전부 거짓이다. 예전에 Employee 로 올리고 지운 뒤 Guest 로 바뀐 계정도 여기서 0건이다
     * (PRD 5.4 「Guest ❌」).
     *
     * @param array<string,mixed> $u 현재 사용자 (세션에서 읽은 값 — 요청 값이 아니다)
     * @return array{0:string,1:array<string,mixed>,2:string} [조건절, 바인딩, 범위 코드]
     */
    public static function restorableFilesWhere(array $u): array
    {
        if (self::restoresAll(self::roleOf($u))) {
            return ['TRUE', [], self::CODE_ADMIN_ALL];
        }
        if (!self::canUploadAtAll($u)) {
            return ['FALSE', [], self::CODE_NOT_FOUND_IN_TRASH];
        }
        $me = (int) $u['id'];
        return [
            '(f.uploaded_by = :rs_me_up AND f.deleted_by = :rs_me_del)',
            [':rs_me_up' => $me, ':rs_me_del' => $me],
            self::CODE_OWNER,
        ];
    }

    /**
     * 휴지통의 파일 한 건을 되살릴 수 있나 + 근거 코드 (PRD 5.4 각주 ¹ · F-11, ARCH 7.4).
     *
     * 규칙은 **좁은 쪽**이다(사용자 확정 2026-09-24):
     *   Admin                                           → 허용 `admin_all` (모든 파일)
     *   올릴 수 없는 역할(Guest)                          → 거부 `not_found_in_trash`
     *   `uploaded_by = 나` **그리고** `deleted_by = 나`   → 허용 `owner`
     *   그 밖(남이 올린 파일 · 남이 지운 내 파일)          → 거부 `not_found_in_trash`
     * Manager 의 팀원 파일 복구는 없다 — 남이 지운 것은 누가 왜 지웠는지 판단이 필요해 Admin 만 한다.
     *
     * **휴지통에 있는 행이라는 전제**다(`deleted_at IS NOT NULL AND purged_at IS NULL` 는 부르는 SQL 이
     * 본다). 그래도 `deleted_by` 가 비어 있으면(살아 있는 행이 잘못 넘어온 경우) 본인 복구로 치지 않는다.
     * 거부 코드는 하나뿐이다 — 없는 파일과 갈리지 않게(CODE_NOT_FOUND_IN_TRASH 주석).
     *
     * @param array<string,mixed> $u    현재 사용자 (세션에서 읽은 값)
     * @param array<string,mixed> $file files 행 (uploaded_by · deleted_by 필수)
     * @return array{0:bool,1:string} [허용 여부, 코드]
     */
    public static function decideRestore(array $u, array $file): array
    {
        if (self::restoresAll(self::roleOf($u))) {
            return [true, self::CODE_ADMIN_ALL];
        }
        if (!self::canUploadAtAll($u)) {
            return [false, self::CODE_NOT_FOUND_IN_TRASH];
        }
        $me        = (int) $u['id'];
        $owner     = (int) $file['uploaded_by'];
        $deletedBy = ($file['deleted_by'] ?? null) === null ? null : (int) $file['deleted_by'];

        if ($owner === $me && $deletedBy !== null && $deletedBy === $me) {
            return [true, self::CODE_OWNER];
        }
        return [false, self::CODE_NOT_FOUND_IN_TRASH];
    }

    /**
     * 18-1 폴더 이름 변경·이동·삭제 (PRD F-26, ARCH 5.16 "권한 판정", 4.1 표).
     *
     * 규칙과 코드는 **파일의 소유자·팀 규칙(decideOwnership)과 한 글자도 다르지 않다**:
     *   Admin    모두                      `admin_all`
     *   Manager  본인이 만든 폴더          `owner`
     *            폴더 team_id = 자기 팀    `team_scope`
     *            남의 팀 폴더              `team_mismatch`  / 팀 없는 남의 폴더 `not_owner`
     *   Employee 본인이 만든 폴더          `owner`  / 그 밖 `not_owner`
     *   Guest                              `role_not_permitted`
     *
     * 구현을 복사하지 않고 decideOwnership 을 **그대로 부른다** — 두 규칙이 같아야 하는데
     * 코드가 두 벌이면 언젠가 한쪽만 고쳐져 어긋난다(decideDelete·decideEdit 과 같은 이유).
     * 그래서 폴더 행의 `created_by`·`team_id` 를 파일 행이 쓰는 이름으로 옮겨 넘긴다.
     * 새 판정 코드를 만들지 않으므로 7.4 사유 등록부·7.5 granted_by 표가 그대로 성립한다.
     *
     * 이 함수는 **"이 폴더를 고칠 자격이 있나"** 만 판단한다. 읽을 수 있나는 파일과 같은
     * `decideRead`(폴더 행에도 access_level·team_id 가 있다)가, 등급을 바꿀 수 있나는 파일과
     * 같은 `decideLevelChange` 가, 무엇을 안에 둘 수 있나는 `fitsUnderFolder` 가 본다 —
     * 넷 다 판정이 한 곳에 모여 있고 폴더라고 해서 다른 표를 쓰지 않는다 (ARCH 5.16).
     *
     * @param array<string,mixed> $actor  요청을 보낸 사용자 (세션에서 읽은 값)
     * @param array<string,mixed> $folder folders 행 (created_by 필수, team_id 는 NULL 가능)
     * @return array{0:bool,1:string} [허용 여부, 코드]
     */
    public static function decideFolderChange(array $actor, array $folder): array
    {
        /* G-0 — 개인 폴더의 옮기기·등급·지우기(이 함수의 나머지 쓰임)는 **주인만**이다. 이름만 바꾸는 요청은
         * decideFolderRename 이 「편집」 권한자까지 연다(지시서 ★ — 편집 권한자는 올리기·이름까지). */
        if ((string) ($folder['access_level'] ?? '') === self::LEVEL_PRIVATE) {
            return self::decidePrivateOwner($actor, $folder + ['item_kind' => 'folder']);
        }
        return self::decideOwnership($actor, [
            'uploaded_by' => $folder['created_by'],
            'team_id'     => $folder['team_id'] ?? null,
        ]);
    }

    /**
     * 18-1 불변 규칙 ① — "하위는 부모 폴더보다 넓을 수 없다" (PRD F-26, ARCH 5.16).
     *
     * **무엇을 보장하나.** 사용자 결정은 "폴더에 접근해도 권한이 없으면 파일을 못 본다" 였다.
     * 그 두 겹(폴더 등급 + 파일 등급)을 **판정마다 두 번 검사하지 않고** 쓰기 시점에 한 번
     * 강제한다: 폴더 안에 들어가는 것(파일이든 하위 폴더든)의 등급이 폴더보다 넓지 않으면,
     * 매트릭스가 등급 순서에 대해 단조인 한 **"안의 것이 보이면 그 폴더도 보인다"** 가
     * 성립한다. 그래서 목록·검색 SQL 은 지금의 `visibleFilesWhere`(파일 조건만) 그대로다.
     * (단조성 자체는 F-25 불변 규칙 (f) 가 지킨다 — 매트릭스를 편집해도 깨지지 않는다.)
     *
     * **규칙 두 줄.**
     *   ㉠ `LEVEL_RANK[하위 등급] >= LEVEL_RANK[폴더 등급]` — 좁거나 같아야 한다.
     *   ㉡ 폴더가 `team` 이고 **하위도 `team` 이면** 두 `team_id` 가 같아야 한다.
     *      (`team` 은 "같은 팀이면" 이라는 전제가 붙은 등급이라, 팀이 다르면 rank 가 같아도
     *       서로 못 보는 사이다 — 그 경우는 rank 비교만으로 걸러지지 않는다.)
     *      하위가 `admin-only` 면 팀을 보지 않는다: `admin-only` 는 `team` 보다 **좁고**,
     *      그것을 읽을 수 있는 역할(Admin)은 어느 팀의 `team` 폴더든 읽을 수 있어
     *      "안의 것이 보이면 그 폴더도 보인다" 가 그대로 성립하기 때문이다
     *      (2026-09-13 검토 결정 — 그전에는 팀 없는 `admin-only` 를 `team` 폴더에 둘 수 없었다).
     *
     * **부르는 곳은 7경로**다 (ARCH 5.16, SECURITY R-63): 업로드 · URL 가져오기 · 파일 이동 ·
     * 파일 등급 변경 · 폴더 생성 · 폴더 이동 · 폴더 등급 변경. 한 경로라도 빠지면 규칙이
     * 무너지므로 판정은 이 함수 하나에만 둔다.
     *
     * 허용일 때 돌려주는 코드는 **빈 문자열**이다 — 이 함수는 "누가 해도 되나"(인가)가 아니라
     * "무엇이 어디에 들어갈 수 있나"(제약)를 보는 것이라 `granted_by` 에 적을 근거가 없다.
     * 부르는 쪽은 거부일 때의 코드(`parent_narrower`)만 기록에 쓴다.
     *
     * @param string              $level   하위의 접근 등급 (4종 중 하나. 그 밖은 거부)
     * @param int|null            $teamId  하위의 team_id (team 등급이 아니면 null)
     * @param array<string,mixed> $folder  folders 행 (access_level·team_id 필수)
     * @return array{0:bool,1:string} [들어갈 수 있나, 거부 코드]
     */
    public static function fitsUnderFolder(string $level, ?int $teamId, array $folder): array
    {
        $folderLevel = (string) $folder['access_level'];
        $folderTeam  = $folder['team_id'] === null ? null : (int) $folder['team_id'];

        if (!isset(self::LEVEL_RANK[$level], self::LEVEL_RANK[$folderLevel])) {
            // 알 수 없는 등급 문자열 — 기본 거부. (부르는 쪽이 이미 4종으로 좁히지만 다시 본다)
            return [false, self::CODE_PARENT_NARROWER];
        }
        if (self::LEVEL_RANK[$level] < self::LEVEL_RANK[$folderLevel]) {
            return [false, self::CODE_PARENT_NARROWER];
        }
        // 팀 일치는 **둘 다 `team` 일 때만** 본다 (위 ㉡). 하위가 `admin-only` 면 rank 로 충분하다.
        if ($folderLevel === 'team' && $level === 'team'
            && ($teamId === null || $teamId !== $folderTeam)
        ) {
            return [false, self::CODE_PARENT_NARROWER];
        }
        return [true, ''];
    }

    /* ─── G-0: 「개인」 등급 · 사람 지정 권한 (PRD F-73, ARCH 4.1 「개인 등급」 · 5.36) ─────────────────────────────
     *
     * 개인 항목(파일·폴더)은 권한 표가 아니라 **사람**으로 판정한다. 닿는 사람(= reach):
     *   'owner' — 이 항목의 주인(파일 = 올린 사람 · 폴더 = 만든 사람) **또는 위 개인 폴더 어디든의 주인**(호스트).
     *             위 폴더 주인을 넣는 까닭: 편집 권한을 받은 사람이 내 개인 폴더에 올린 파일은 그 사람 것이다 —
     *             폴더 주인이 그것을 못 보면 내 폴더에 내가 못 보는 것이 생긴다(G-1 의 페이지 폴더가 바로 그 모양이다).
     *   'write' — 이 항목이나 위 개인 폴더 어디든에 **편집** 권한을 받은 사람
     *   'view'  — 같은 자리에 **보기** 권한을 받은 사람
     *   null    — 그 밖(Admin 이면 decideRead 가 `admin_override` 로 연다 — 여기 값은 null 그대로다)
     * 「위 개인 폴더」 는 이 항목에서 위로 올라가며 **개인 등급인 동안만** 센다(첫 개인 아닌 조상에서 멈춘다) — 개인 아닌
     * 폴더의 주인·권한 줄은 개인 항목을 열지 않는다(권한은 개인 항목에만 뜻이 있다 · 개인 폴더 안에는 개인만 있으므로 개인
     * 조상은 언제나 항목 바로 위부터 이어진 한 토막이다). 깊이 ≤ 5 라 조상 조인 다섯 번(파일)·네 번(폴더)으로 끝난다.
     *
     * ★ **판정 ↔ 목록 짝** — 아래 privateChainSql 이 만드는 조인·주인 식·권한 식 **한 벌**을 두 곳이 함께 쓴다:
     *   목록 조건절 visibleWhere 의 개인 갈래 = EXISTS(… WHERE 주인 식 OR 권한 식(아무거나))           — privateVisibleSql
     *   판정 decideRead [P] 의 privateReach  = CASE WHEN 주인 식 … WHEN 권한 식(편집) … WHEN 권한 식(아무거나) … END — privateReach
     *   둘의 「null 아님」 ⇔ 「EXISTS 참」 은 같은 두 식의 OR 이다. 시험 tests/g0_private_grants.sh 가 계정 × 항목 × 깊이 ×
     *   권한 자리 전부에서 둘을 대조한다(C:T-56 방식). 한쪽만 고치면 그 시험이 깨진다.
     * ★ 캐시 0 — 권한을 주고 빼면 다음 요청부터 바로 반영된다(요청마다 DB 에 묻는다).
     */

    /**
     * 개인 판정의 SQL 조각 한 벌 — 조인(`pv_c1`…) · 주인 후보 식 목록 · 권한 식(편집만/아무거나).
     * 별칭은 코드 리터럴 둘(`f` 파일 · `d` 폴더)만 받는다(visibleWhere 와 같은 허용 목록). 자리표시자 이름은 부르는 쪽이
     * 넘기는 접두(`$ph`)로 가른다 — 같은 이름을 한 문장에 두 번 쓰지 않는다(서버측 prepare — Db.php).
     *
     * @return array{joins:string,owner:string,grant:string,grantWrite:string,bind:array<string,int>}
     */
    private static function privateChainSql(string $alias, string $ph, int $me): array
    {
        if (!in_array($alias, ['f', 'd'], true) || preg_match('/^[a-z0-9_]{1,20}$/', $ph) !== 1) {
            throw new RuntimeException('허용되지 않는 별칭·자리표시자입니다');
        }
        // 파일: 든 폴더부터 위로 다섯 · 폴더: 부모부터 위로 넷 (깊이 ≤ 5 — folders_depth_range)
        $first = $alias === 'f' ? 'f.folder_id' : 'd.parent_id';
        $hops  = $alias === 'f' ? 5 : 4;
        $joins = '';
        $prev  = $first;
        $owners = [$alias === 'f' ? 'f.uploaded_by' : 'd.created_by'];
        $nodes  = $alias === 'f' ? [] : ['d.id'];
        for ($i = 1; $i <= $hops; $i++) {
            $c = 'pv_c' . $i;
            // 개인 등급인 동안만 잇는다 — 앞 조상이 비면(NULL) 뒤도 모두 빈다
            $joins   .= " LEFT JOIN public.folders {$c} ON {$c}.id = {$prev} AND {$c}.access_level = 'private'";
            $owners[] = $c . '.created_by';
            $nodes[]  = $c . '.id';
            $prev     = $c . '.parent_id';
        }
        $me1 = ':' . $ph . '_me1';
        $me2 = ':' . $ph . '_me2';
        $me3 = ':' . $ph . '_me3';
        $target = ($alias === 'f' ? 'pv_g.file_id = f.id OR ' : '') . 'pv_g.folder_id IN (' . implode(', ', $nodes) . ')';
        // 권한 식 — 고정 틀에 자리표시자 이름·대상 식(코드가 만든 별칭 조각)만 끼운다(요청 값 0 · strtr 한 번 치환)
        $grantTpl = <<<'SQL'
            (EXISTS (SELECT 1 FROM public.access_grants pv_g
                      WHERE pv_g.user_id = CAST(@ME AS bigint)@WRITE AND (@TARGET)))
            SQL;
        return [
            'joins'      => $joins,
            // 주인 후보에 NULL 이 섞여도(빈 조상) `x IN (…, NULL)` 은 맞는 값이 있을 때만 참이다
            'owner'      => 'CAST(' . $me1 . ' AS bigint) IN (' . implode(', ', $owners) . ')',
            'grantWrite' => strtr($grantTpl, ['@ME' => $me2, '@WRITE' => ' AND pv_g.can_write', '@TARGET' => $target]),
            'grant'      => strtr($grantTpl, ['@ME' => $me3, '@WRITE' => '', '@TARGET' => $target]),
            'bind'       => [$me1 => $me, $me2 => $me, $me3 => $me],
        ];
    }

    /**
     * 목록 조건절의 개인 갈래 — visibleWhere 가 붙인다. **privateReach 와 짝**(같은 조각 · 주인 식 OR 권한 식).
     *
     * @return array{0:string,1:array<string,int>}
     */
    private static function privateVisibleSql(string $alias, int $me): array
    {
        $c = self::privateChainSql($alias, 'pv' . $alias, $me);
        $bind = $c['bind'];
        unset($bind[':pv' . $alias . '_me2']);   // 목록은 편집 식을 쓰지 않는다 — 쓰지 않는 자리표시자를 넘기지 않는다
        $tpl = <<<'SQL'
            (@A.access_level = 'private' AND EXISTS (SELECT 1 FROM (SELECT 1) AS pv_z@JOINS
              WHERE @OWNER OR @GRANT))
            SQL;
        $sql = strtr($tpl, ['@A' => $alias, '@JOINS' => $c['joins'], '@OWNER' => $c['owner'], '@GRANT' => $c['grant']]);
        return [$sql, $bind];
    }

    /**
     * 이 사람이 이 개인 항목에 **어떻게 닿나** — 'owner'·'write'·'view'·null. **privateVisibleSql 과 짝**.
     * 항목은 DB 에서 다시 읽는다(번호로) — 행에 실린 주인·폴더 값을 믿지 않는다. 개인이 아니면(그사이 넓혔으면) null.
     */
    public static function privateReach(array $u, string $kind, int $id): ?string
    {
        $me = isset($u['id']) ? (int) $u['id'] : 0;
        if ($me <= 0 || $id <= 0 || !in_array($kind, ['file', 'folder'], true)) {
            return null;
        }
        $alias = $kind === 'file' ? 'f' : 'd';
        $table = $kind === 'file' ? 'public.files f' : 'public.folders d';
        $c = self::privateChainSql($alias, 'pr', $me);
        $tpl = <<<'SQL'
            SELECT CASE WHEN @OWNER THEN 'owner' WHEN @GRANTW THEN 'write' WHEN @GRANT THEN 'view' ELSE NULL END AS reach
              FROM @TABLE@JOINS
             WHERE @A.id = CAST(:pr_id AS bigint) AND @A.access_level = 'private'
            SQL;
        $sql = strtr($tpl, [
            '@OWNER' => $c['owner'], '@GRANTW' => $c['grantWrite'], '@GRANT' => $c['grant'],
            '@TABLE' => $table, '@JOINS' => $c['joins'], '@A' => $alias,
        ]);
        $row = Db::instance()->run($sql, $c['bind'] + [':pr_id' => $id])->fetch();
        if (!is_array($row) || !is_string($row['reach'] ?? null)) {
            return null;
        }
        return in_array($row['reach'], ['owner', 'write', 'view'], true) ? $row['reach'] : null;
    }

    /**
     * 행에서 종류·번호를 꺼내 privateReach 를 부른다. 종류 표식(`item_kind` — SELECT 가 리터럴로 싣는다)이 없거나
     * 번호가 없으면 null — **닫힌 실패**(개인 항목을 못 보는 쪽으로 틀린다). 요청 값이 들어갈 자리가 없다(행은 DB 에서 왔다).
     *
     * @param array<string,mixed> $row
     */
    private static function privateReachOf(array $u, array $row): ?string
    {
        $kind = $row['item_kind'] ?? null;
        $id   = $row['id'] ?? null;
        if (!is_string($kind) || $id === null || !is_numeric($id)) {
            return null;
        }
        return self::privateReach($u, $kind, (int) $id);
    }

    /**
     * 개인 항목의 **주인 몫**(등급 바꾸기·위치 옮기기·지우기·공유 링크·권한 주고 빼기)을 할 수 있나 (지시서 ★ 주인만).
     * 개인이 아닌 행이면 이 함수는 판단하지 않는다(허용·빈 코드) — 그 행은 원래 판정(decideOwnership 등)이 본다.
     *
     * @param array<string,mixed> $row item_kind · id 가 있는 행
     * @return array{0:bool,1:string}
     */
    public static function decidePrivateOwner(array $u, array $row): array
    {
        if ((string) ($row['access_level'] ?? '') !== self::LEVEL_PRIVATE) {
            return [true, ''];
        }
        return self::privateReachOf($u, $row) === 'owner'
            ? [true, self::CODE_OWNER]
            : [false, self::CODE_NOT_OWNER];
    }

    /**
     * 이 항목을 **올린·만든 사람**인가 (다른 등급 → 개인으로 좁히기 · 요청을 받는 호스트). 폴더는 created_by, 파일은 uploaded_by.
     *
     * @param array<string,mixed> $row item_kind 가 있는 행
     */
    public static function isItemCreator(array $u, array $row): bool
    {
        $kind  = $row['item_kind'] ?? null;
        $owner = $kind === 'folder' ? ($row['created_by'] ?? null) : ($kind === 'file' ? ($row['uploaded_by'] ?? null) : null);
        return $owner !== null && isset($u['id']) && (int) $owner === (int) $u['id'] && (int) $owner > 0;
    }

    /**
     * 폴더 **이름만** 바꾸기 (G-0). 개인 폴더는 주인 · 「편집」 권한자, 그 밖은 decideFolderChange 그대로.
     *
     * @param array<string,mixed> $folder
     * @return array{0:bool,1:string}
     */
    public static function decideFolderRename(array $actor, array $folder): array
    {
        if ((string) ($folder['access_level'] ?? '') === self::LEVEL_PRIVATE) {
            return self::decideEdit($actor, $folder + ['item_kind' => 'folder']);
        }
        return self::decideFolderChange($actor, $folder);
    }

    /**
     * 이 폴더 **안에 무언가를 넣을** 수 있나 — 올리기 · URL 가져오기 · 하위 폴더 만들기 · 파일·폴더를 옮겨 넣기 (G-0).
     *   개인 아닌 폴더: 지금까지와 같다 — 읽을 수 있으면 된다(decideRead).
     *   개인 폴더: 주인 · 「편집」 권한자만(보기 권한자·Admin 특권은 넣지 못한다 — 남의 개인 폴더에 밀어 넣는 길을 닫는다).
     * 들어가는 것의 등급은 따로 fitsUnderFolder 가 본다(개인 폴더 안은 개인만).
     *
     * @param array<string,mixed> $folder folders 행
     * @return array{0:bool,1:string}
     */
    public static function decideFolderPut(array $u, array $folder): array
    {
        if ((string) ($folder['access_level'] ?? '') === self::LEVEL_PRIVATE) {
            $reach = self::privateReachOf($u, $folder + ['item_kind' => 'folder']);
            if ($reach === 'owner') {
                return [true, self::CODE_OWNER];
            }
            if ($reach === 'write') {
                return [true, self::CODE_GRANT_WRITE];
            }
            return [false, self::CODE_NOT_OWNER];
        }
        return self::decideRead($u, $folder);
    }

    /**
     * 개인 항목의 **권한 주기·빼기**를 할 수 있나 (G-0 창구 `…/grants`).
     *   주인(privateReach 'owner') → `owner` · 그 밖 Admin → `admin_override`(★ 특권 — 창구가 15분 재인증을 묻고 감사에 남긴다) ·
     *   그 밖 → `not_owner`. 개인이 아닌 항목은 `not_owner` 가 아니라 창구가 먼저 400 으로 끝낸다(권한은 개인에만 뜻이 있다).
     *
     * @param array<string,mixed> $row item_kind · id 가 있는 행
     * @return array{0:bool,1:string}
     */
    public static function decideGrantManage(array $u, array $row): array
    {
        if (self::privateReachOf($u, $row) === 'owner') {
            return [true, self::CODE_OWNER];
        }
        if (self::canAdmin($u)) {
            return [true, self::CODE_ADMIN_OVERRIDE];
        }
        return [false, self::CODE_NOT_OWNER];
    }

    /** 이 권한 창구 결정에 15분 재인증을 물어야 하나 — Admin 특권으로 남의 개인 항목을 고칠 때만 (shareCreateNeedsReauth 와 같은 모양) */
    public static function grantManageNeedsReauth(string $code): bool
    {
        return $code === self::CODE_ADMIN_OVERRIDE;
    }

    /**
     * 이 계정이 **편집** 권한을 받을 수 있나 — 쓰기 자격 있는 역할만(손님은 보기만 · 커뮤니티 5-A 규칙과 같다).
     *
     * @param array<string,mixed> $target users 행(role 필수)
     */
    public static function mayReceiveWriteGrant(array $target): bool
    {
        return self::canUploadAtAll($target);
    }

    /**
     * F-65 — **실행 파일이 든 압축본의 확인 업로드 자격**이 있는 요청인가 (ARCH 5.29 「올리기」 ②).
     *
     * 왜 Authz 에 두나: 이 판정은 「누가(실제 역할) · 어떤 상태로(보는 중인가) · 어떤 등급으로 ·
     * 어떤 입구로」 네 가지를 한꺼번에 견주는 **역할 판단**이다. 핸들러에 두면 그것이 곧
     * "Authz 밖의 역할 비교"이고(CLAUDE.md 4장 금지 패턴, `scripts/security_lint.sh` (f)),
     * 언젠가 두 벌이 되는 날 한쪽만 고쳐진다 (R-70 과 같은 걱정).
     *
     * 네 조건 — **전부** 참일 때만 참이다.
     *   ① 실제 역할이 Admin — `real_role` 을 본다. 「역할로 보기」 중의 유효 역할(`role`)이
     *      아니라 진짜 역할이다 (Auth::currentUser 가 언제나 채운다).
     *   ② 「역할로 보기」 중이 **아니다**(`acting` 이 참이 아니다) — 보는 중에는 Admin 이
     *      Employee 를 흉내 내고 있고, 그 상태에서 admin-only 예외를 여는 것은 F-39 의
     *      「보는 동안은 그 역할로만 행동한다」 와 어긋난다.
     *   ③ 올리려는 등급이 `admin-only` — 받을 사람이 언제나 Admin(= OTP 필수)이어야 한다.
     *   ④ **웹 세션**이다 (`TBOX_AUTH_METHOD` 가 정의되지 않았다) — 토큰 입구는 OTP 를
     *      칠 수 없으므로 예외가 열리지 않는다 (ARCH 5.29 ①, PRD F-65 ①).
     *
     * 거짓이면 지금까지처럼 400 통일 문구이고, 응답에 `next` 를 넣지 않는다 — Employee 에게
     * 「실행 파일이 들어 있다」는 힌트를 주지 않기 위해서다 (ARCH 5.29 ②).
     *
     * @param array<string,mixed> $u 현재 사용자 (요청 값이 아니다 — PRD S-01)
     */
    public static function canUploadExec(array $u, string $level): bool
    {
        // 값을 먼저 변수로 꺼낸다 — 배열 첨자와 리터럴을 한 줄에 붙이지 않는 것은
        // security_lint 의 역할 비교 검사가 Authz 도 훑기 때문이다(예외 파일이 없다).
        $realRole = (string) ($u['real_role'] ?? $u['role'] ?? '');
        $acting   = ($u['acting'] ?? false) === true;

        return $realRole === 'Admin'
            && !$acting
            && $level === 'admin-only'
            && !defined('TBOX_AUTH_METHOD');
    }

    /**
     * Q:F-10 — 개인 프로그램 토큰 범위의 **원자** 여섯 (사이트 PRD Q:F-10 · t-box PRD F-76, ARCH 4.10).
     *
     * 토큰 하나의 범위는 이 원자들의 **집합**이고, DB(`api_tokens.scope`)에는 이 표의 순서로 정렬해 `+` 로 이은
     * 문자열 하나로 저장한다(`normalizeScope` 가 만든다). 표의 순서가 곧 정규화 순서다.
     *
     *   read             파일 읽기·목록·내려받기 — 파일 원자의 바닥
     *   upload           파일 올리기·이름 바꾸기·옮기기(`file.upload`·`file.update`) — `read` 필요
     *   delete           파일 지우기(`file.delete`) — `read`·`upload` 필요
     *   community.read   커뮤니티 검색·읽기(`community.read` 정책의 토큰 창구) — 독립
     *   community.write  커뮤니티 쓰기(`community.write` 정책) — 독립. 정규화가 `community.read` 를 **덧붙인다**
     *   session.open     1회용 로그인 링크(`session.open` 정책) — 독립. 붙으면 `bound_ip` 필수 · 사람당 살아 있는 것 하나 ·
     *                    그 토큰의 **모든 창구**가 묶인 IP 밖에서는 401(`Auth::requireApiToken`, ARCH 4.9 · 4.10)
     *
     * 옛 다섯 값(`read` · `read+upload` · `read+upload+delete` · `community.write` · `session.open`)은 이 모델에서 그대로 뜻이
     * 통한다 — 넷은 정규화 결과와 글자까지 같고, `community.write` 한 원자로 저장된 옛 행은 마이그레이션
     * (`2026-10-13_api_tokens_multi_scope.sql`)이 `community.read+community.write` 로 바꾼다. 판정(`tokenScopeAllows`)은
     * 옛 문자열이 그대로 와도 `community.write` 를 읽기로 인정한다(이중 안전).
     * 이 표를 늘리려면 DB CHECK(`db/schema.sql` api_tokens_scope_check)·`tokenScopeAllows`·`tokenScopesFor`·화면 라벨
     * (`app.js` TOKEN_SCOPE_LABELS)을 함께 고쳐야 한다.
     */
    public const TOKEN_SCOPE_ATOMS = ['read', 'upload', 'delete', 'community.read', 'community.write', 'session.open', 'assistant.run', 'travel.read', 'travel.write'];

    /** Q:F-10 — 원자 → 함께 있어야 하는 원자(없으면 정규화가 거부한다 — 「올리기만」「지우기만」 토큰은 없다). */
    private const TOKEN_SCOPE_REQUIRES = [
        'upload' => ['read'],
        'delete' => ['read', 'upload'],
    ];

    /** Q:F-10 — 원자 → 정규화가 **덧붙이는** 원자(쓰기는 읽기를 품는다 — 저장 문자열에 둘 다 있어 판정이 단순해진다). */
    private const TOKEN_SCOPE_IMPLIES = [
        'community.write' => ['community.read'],
        'travel.write' => ['travel.read'],
    ];

    /** 요청 한 번에 받는 범위 원소 수의 상한 — 원자는 여섯이고, 그보다 많이 온 요청은 모양부터 틀렸다. */
    private const TOKEN_SCOPE_MAX_ITEMS = 16;

    /**
     * Q:F-10 — 범위(원자 배열 또는 `+` 로 이은 문자열)를 **정규형 문자열**로 (ARCH 4.10).
     *
     * 규칙(순서대로):
     *   ① 배열이면 원소가 전부 문자열이어야 하고(16개 이하), 문자열이면 `+` 로 쪼갠다(옛 값 `read+upload` 호환)
     *   ② 원소마다 `TOKEN_SCOPE_ATOMS` 안의 글자여야 한다 — 모르는 원자(빈 글자·대소문자 다른 글자 포함)는 거부
     *   ③ 겹친 원소는 하나로 · 빈 집합은 거부
     *   ④ `TOKEN_SCOPE_IMPLIES` 로 덧붙인다(`community.write` → `community.read`)
     *   ⑤ `TOKEN_SCOPE_REQUIRES` 를 본다(`upload` 는 `read` · `delete` 는 `read`+`upload`) — 모자라면 거부(자동으로 채우지 않는다:
     *      「지우기」를 골랐는데 「올리기」까지 조용히 열리면 사용자가 고른 것보다 넓은 토큰이 된다. 채우는 것은 화면의 몫 — 체크 연동)
     *   ⑥ `TOKEN_SCOPE_ATOMS` 순서로 정렬해 `+` 로 잇는다
     *
     * 거부는 `InvalidArgumentException` 하나다(발급 창구가 400 으로 바꾼다). 역할을 보지 않는다 — 「이 사람이 고를 수
     * 있나」는 `tokenScopesFor` 가 따로 본다.
     *
     * @param list<mixed>|string $scope
     * @throws InvalidArgumentException
     */
    public static function normalizeScope(array|string $scope): string
    {
        if (is_string($scope)) {
            $items = explode('+', $scope);
        } else {
            if (!array_is_list($scope) || count($scope) > self::TOKEN_SCOPE_MAX_ITEMS) {
                throw new InvalidArgumentException('범위 모양이 올바르지 않다');
            }
            $items = $scope;
        }

        $set = [];
        foreach ($items as $item) {
            if (!is_string($item) || !in_array($item, self::TOKEN_SCOPE_ATOMS, true)) {
                throw new InvalidArgumentException('모르는 범위 원자');
            }
            $set[$item] = true;
        }
        if ($set === []) {
            throw new InvalidArgumentException('빈 범위');
        }
        if (isset($set['assistant.run']) && count($set) !== 1) {
            throw new InvalidArgumentException('비서 실행 토큰은 별도로 발급합니다');
        }
        foreach (self::TOKEN_SCOPE_IMPLIES as $atom => $adds) {
            if (isset($set[$atom])) {
                foreach ($adds as $add) {
                    $set[$add] = true;
                }
            }
        }
        foreach (self::TOKEN_SCOPE_REQUIRES as $atom => $needs) {
            if (isset($set[$atom])) {
                foreach ($needs as $need) {
                    if (!isset($set[$need])) {
                        throw new InvalidArgumentException('범위 의존 조건 위반');
                    }
                }
            }
        }

        $out = [];
        foreach (self::TOKEN_SCOPE_ATOMS as $atom) {
            if (isset($set[$atom])) {
                $out[] = $atom;
            }
        }
        return implode('+', $out);
    }

    /**
     * Q:F-10 — 저장된 범위 문자열 → 원자 배열(표의 순서). 목록·발급 응답의 `scopes` 칸과 `GET /api/whoami` 의
     * `token_scopes_held` 가 쓴다 — 화면·비서가 문자열을 스스로 쪼개지 않게 한다.
     * 정규형이 아닌 옛 문자열(`community.write` 한 원자)도 그대로 원자로 돌려준다 — **판정에는 쓰지 않는다**
     * (판정은 `tokenScopeAllows` 한 곳). 모르는 조각은 버린다(DB CHECK 가 있어 생기지 않는다).
     *
     * @return list<string>
     */
    public static function scopeAtoms(string $scope): array
    {
        $parts = explode('+', $scope);
        $out   = [];
        foreach (self::TOKEN_SCOPE_ATOMS as $atom) {
            if (in_array($atom, $parts, true)) {
                $out[] = $atom;
            }
        }
        return $out;
    }

    /**
     * Q:F-10 — 이 범위에 「이 PC 로그인 전용」 원자가 있나 (IP 묶음 · 사람당 하나 · 상한 셈의 갈림 — ARCH 4.9 · 4.10).
     * SQL 쪽 같은 판정은 `'session.open' = ANY (string_to_array(scope, '+'))` 한 모양이다.
     */
    public static function scopeHasSessionOpen(string $scope): bool
    {
        return in_array('session.open', explode('+', $scope), true);
    }

    /**
     * F-66 · Q:F-8 · Q:F-9 · **Q:F-10** — 이 계정이 **고를 수 있는 원자** 목록 (ARCH 4.7 · 4.10, `GET /api/me` 의 `token_scopes`).
     *
     * Q:F-10 부터 값은 **원자** 배열이다(발급 폼의 체크박스 하나가 원자 하나). 판단은 역할 문자열이 아니라 판정 함수로 한다:
     *   read             언제나
     *   upload · delete  `uploadableLevels()` 가 비어 있지 않을 때(옛 사다리 규칙 그대로 — 올릴 수 있으면 셋 다 골랐다.
     *                    지우기를 고르는 조건이 올리기와 달랐던 적이 없다)
     *   community.read   `canCommunityWrite` — 커뮤니티 읽기 전용 판정 함수가 따로 없다(`authorize('community.read')` 는
     *                    「로그인했으면 참」이라 Guest 도 참이다). 지시서 S-1 대로 **쓰기 자격과 같은 판정**(Guest 0)을 쓴다
     *   community.write  `canCommunityWrite`
     *   session.open     `canOpenSessionByToken`(2단계 켜짐 · Guest 0)
     * 순서는 `TOKEN_SCOPE_ATOMS` 와 같다.
     *
     * ★ `upload_levels` 와 같은 성격의 **선택지**이며 권한이 아니다: 화면이 목록에 없는 원자를 보내도 발급 핸들러가
     *   이 함수로 다시 판단하고(`bad_request`), 범위 안이라도 파일·페이지 한 건의 판정은 요청마다 `decide*()` 가 다시 한다.
     *
     * @param array<string,mixed> $u
     * @return list<string>
     */
    public static function tokenScopesFor(array $u): array
    {
        $atoms = ['read'];
        // 올릴 수 있는 등급이 하나도 없으면 「읽기만」 이다 (Guest 가 그 경우다).
        if (self::uploadableLevels($u) !== []) {
            $atoms[] = 'upload';
            $atoms[] = 'delete';
        }
        // 커뮤니티 읽기·쓰기 — 둘 다 canCommunityWrite 한 곳(Guest 0).
        if (self::canCommunityWrite($u)) {
            $atoms[] = 'community.read';
            $atoms[] = 'community.write';
            $atoms[] = 'travel.read';
            $atoms[] = 'travel.write';
        }
        // Q:F-9 — 「이 PC 로그인 전용」은 2단계 인증이 켜진 계정 · Guest 가 아닌 계정만(판정은 canOpenSessionByToken 한 곳).
        if (self::canOpenSessionByToken($u)) {
            $atoms[] = 'session.open';
        }
        if (Env::getOrEmpty('ASSISTANT_ENABLED') === '1') $atoms[] = 'assistant.run';
        return array_values(array_intersect(self::TOKEN_SCOPE_ATOMS, $atoms));
    }

    /**
     * Q:F-9 — 이 계정이 「이 PC 로그인 전용」 토큰을 **가질 수 있나** (t-box F-75 ①, ARCH 4.9).
     *
     * 둘 다 참일 때만: ① 2단계 인증이 켜져 있다(`mfa_enabled` — 매 요청 DB 값) ② 쓰기 자격이 있다(`canUploadAtAll` —
     * 권한 매트릭스에서 올릴 수 있는 등급이 하나라도 있는 역할, Guest 0). 역할 문자열을 보지 않는다.
     * 쓰는 곳 셋: tokenScopesFor(고를 수 있나) · authorize('session.open')(링크를 받을 수 있나 — 토큰을 가진 뒤 2단계를 껐으면
     * 여기서 끝) · Auth::openSessionFromLink(링크를 열 때 다시 — 그 사이 바뀐 계정 상태).
     *
     * @param array<string,mixed> $u
     */
    public static function canOpenSessionByToken(array $u): bool
    {
        return ($u['mfa_enabled'] ?? false) === true && self::canUploadAtAll($u);
    }

    /**
     * F-66 · **Q:F-10** — **라우트 정책**이 요구하는 원자를 이 토큰이 갖고 있나 (ARCH 4.7 · 4.10 표).
     *
     * 범위 문자열을 `+` 로 쪼갠 **원자 집합**으로 본다. 창구 이름을 하나하나 적지 않고 **정책 문자열**로 정한다 —
     * 새 토큰 창구가 늘어도 이 표를 고칠 일이 없고, 창구와 범위가 어긋날 여지가 없다.
     *
     *   (먼저) 모르는 조각이 하나라도 있으면 **모든 정책에서 거짓**(닫힌 실패 — 빈 글자도 모르는 조각이다)
     *   `authenticated`                → 참(원자가 전부 아는 것 — `GET /api/whoami` 는 토큰 확인용이다)
     *   `file.read`                    → `read`
     *   `file.upload` · `file.update`  → `upload`
     *   `file.delete`                  → `delete`
     *   `community.read`               → `community.read` 또는 `community.write`(옛 행 `community.write` 한 원자 — 이중 안전)
     *   `community.write`              → `community.write`
     *   `session.open`                 → `session.open`
     *   그 밖(모르는 정책)             → **거부**(닫힌 실패). 토큰 집합이 넓어지는 날 그 창구는 범위를 정할 때까지 열리지 않는다.
     *
     * 원자 사이의 의존(`upload` 는 `read` 필요 등)은 **발급 때** `normalizeScope` 가 지킨다. 여기서는 정책이 요구하는 원자
     * 하나만 본다 — DB CHECK 는 원자 포함 여부만 보므로(ARCH 6.2-5 「DB 는 마지막 그물」) 의존이 깨진 행이 DB 에 직접 들어와도
     * 그 행은 자기가 가진 원자만큼만 연다(넓어지지 않는다).
     *
     * ★ 이 함수는 「이 **토큰**이 무엇을 할 수 있나」만 본다. 「이 **계정**이 무엇을 할 수 있나」는 그 뒤의 `authorize()` 이고,
     *   파일 한 건의 판정은 핸들러의 `decide*()` 다. 셋이 모두 통과해야 요청이 산다 — 토큰이 계정보다 커질 수 없다(ARCH 4.7).
     * ★ 역할을 보지 않는다. 인자는 문자열 둘뿐이다(서명 불변 — Q:F-10).
     */
    public static function tokenScopeAllows(string $scope, string $policy): bool
    {
        $held = explode('+', $scope);
        // 모르는 조각(빈 글자 포함)이 하나라도 있으면 그 범위는 **아무 창구도** 열지 않는다(닫힌 실패) — DB CHECK 가 있어
        // 생기지 않는 값이지만, 생겼다면 「아는 원자만 골라 쓰기」보다 전부 거부가 안전하다.
        foreach ($held as $atom) {
            if (!in_array($atom, self::TOKEN_SCOPE_ATOMS, true)) {
                return false;
            }
        }

        if (in_array('assistant.run', $held, true) && count($held) !== 1) return false;
        switch ($policy) {
            case self::POLICY_AUTHENTICATED:
                return true;     // 위에서 원자가 전부 아는 것임을 봤다(explode 는 빈 배열을 돌려주지 않는다)

            case 'file.read':
                return in_array('read', $held, true);

            case 'file.upload':
            case 'file.update':
                return in_array('upload', $held, true);

            case 'file.delete':
                return in_array('delete', $held, true);

            // Q:F-8 · Q:F-10 — 커뮤니티 읽기 창구(검색·목록·공간·한 페이지 — ARCH 4.8 · 4.10). 쓰기는 읽기를 품는다.
            case 'community.read':
                return in_array('community.read', $held, true) || in_array('community.write', $held, true);

            case 'community.write':
                return in_array('community.write', $held, true);

            case 'travel.read':
                return in_array('travel.read', $held, true) || in_array('travel.write', $held, true);

            case 'travel.write':
                return in_array('travel.write', $held, true);

            // Q:F-9 — 1회용 로그인 링크 창구 하나(ARCH 4.9).
            case 'session.open':
                return in_array('session.open', $held, true);

            case 'assistant.run':
                return $scope === 'assistant.run';

            default:
                // 모르는 정책은 통과시키지 않는다 (authorize() 의 default 와 같은 원칙).
                return false;
        }
    }

    /**
     * 커뮤니티에 **쓸 수 있는 역할**인가 (커뮤니티 PRD 4장 「쓰기 자격」, ARCHITECTURE.md 21.2).
     *
     * 판정은 t-box 파일 업로드 자격과 **같은 표**다 — 권한 매트릭스의 `can_upload` 가
     * 한 등급이라도 참이면 쓸 수 있다(Guest 는 전부 거짓이라 여기서 끝난다).
     * 표를 새로 만들지 않는 이유: 매트릭스가 바뀌면(t-box F-25) 커뮤니티도 그대로 따라가야
     * 하고, 표가 두 벌이면 언젠가 한쪽만 고쳐진다.
     *
     * 이 함수는 **라우트 자격**만 본다. 그 페이지를 고칠 수 있는지는 행을 읽은 뒤
     * `CommunityAuthz::decidePageChange` 가 판단한다.
     */
    public static function canCommunityWrite(array $u): bool
    {
        return self::canUploadAtAll($u);
    }

    /**
     * **쓰기 자격 있는 모든 역할**(`canUploadAtAll` — Guest 를 뺀 사내 사람들)이 읽는 등급 — 팀 등급 제외 (커뮤니티 7-D1).
     * 「사내 전체에 열린」 게시판 등급을 코드에 박지 않고 매트릭스에 묻는다(F-25 로 표가 바뀌면 따라간다 — 독립 검토 7-D1 L-2).
     * 역할 이름은 이 파일 안에서만 다룬다(CLAUDE.md 4장).
     *
     * @return list<string>
     */
    public static function levelsReadByAllWriters(): array
    {
        $writers = [];
        foreach (self::ROLES as $role) {
            foreach (['public', 'internal', 'team', 'admin-only'] as $level) {
                if (self::flag($role, $level, 'upload')) {
                    $writers[] = $role;
                    break;
                }
            }
        }
        $out = [];
        foreach (['public', 'internal', 'admin-only'] as $level) {
            $all = $writers !== [];
            foreach ($writers as $role) {
                if (!self::flag($role, $level, 'read')) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $out[] = $level;
            }
        }
        return $out;
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
     * 이 라우트 정책이 관리 기능 재인증 대상인가 (16-1, PRD F-23, ARCH 4.0 재인증 행).
     *
     * 판단은 이 파일 하나에서만 한다 — 라우터가 `in_array($policy, [...])` 를 직접 쓰면
     * 권한·정책 판단이 Authz 밖으로 새어 나간다 (ARCH 4.1, CLAUDE.md 4장 금지 패턴).
     * 이 함수는 **권한을 판단하지 않는다**: "이 정책은 다시 물어야 하는 영역인가" 뿐이고,
     * 그 사람이 무엇을 할 수 있는지는 authorize() 가 이미 판단한 뒤다(관문 순서: 인가 → 재인증).
     *
     * Q:F-9 — 인자 둘이 늘었다: `$origin`(세션 출신 `login`·`token` — Auth::currentUser 의 session_origin)과
     * `$route`(TBOX_ROUTE 표기). `login` 이면 답은 전과 같다(REAUTH_POLICIES 하나). `token` 이면
     * TOKEN_SESSION_REAUTH_POLICIES ∪ TOKEN_SESSION_REAUTH_ROUTES 를 본다(ARCH 4.9 「재인증 집합 둘」).
     * 인자를 주지 않으면(커뮤니티 라우터) 현재 사용자와 TBOX_ROUTE 를 읽는다.
     */
    public static function requiresReauth(string $policy, ?string $origin = null, ?string $route = null): bool
    {
        // 일반 로그인 세션의 답은 이 한 줄이 전부다 — 전과 같은 목록·같은 결과(origin='login' 불변, Q:F-9 T-6).
        if (in_array($policy, self::REAUTH_POLICIES, true)) {
            return true;
        }

        /*
         * Q:F-9 (t-box F-75 ⑤, ARCH 4.9) — **토큰 출신 세션**이면 집합이 넓어진다(TOKEN_SESSION_REAUTH_POLICIES ∪ 라우트 표).
         *
         * 세션이 어디서 났나(`origin`)는 요청 값이 아니라 `sessions.origin` 을 Auth::currentUser() 가 읽어 실어 준 값이다.
         * t-box 라우터는 origin·라우트를 **인자로** 넘긴다. 커뮤니티 라우터(`src/community/routes.php`)는 정책 하나만 넘기므로
         * (이 조각은 그 파일을 고치지 않는다) 인자가 없으면 이미 해석된 현재 사용자(requireLogin 뒤라 캐시 — DB 조회 0)와
         * 라우터가 매칭 직후 세운 `TBOX_ROUTE` 를 읽는다. 둘 다 서버가 정한 값이다.
         * 이 함수는 여전히 **권한을 판단하지 않는다** — 인가(authorize) 뒤에 「다시 물어야 하나」만 답한다.
         */
        if ($origin === null) {
            $current = Auth::currentUser();
            $origin  = is_array($current) ? (string) ($current['session_origin'] ?? 'login') : 'login';
        }
        if ($origin !== 'token') {
            return false;
        }
        if ($route === null && defined('TBOX_ROUTE')) {
            $route = (string) constant('TBOX_ROUTE');
        }
        return in_array($policy, self::TOKEN_SESSION_REAUTH_POLICIES, true)
            || ($route !== null && in_array($route, self::TOKEN_SESSION_REAUTH_ROUTES, true));
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

            /* 커뮤니티 3단계 (커뮤니티 ARCHITECTURE.md 21.2). t-box 의 file.* 와 같은 구조다:
               라우터는 「그 종류의 행위를 할 수 있는 역할인가」까지만 보고, 특정 페이지에 대한
               판단은 핸들러가 행을 읽은 뒤 CommunityAuthz::decidePage* 로 다시 한다. */
            case 'community.read':
                return true;     // 라우터가 이미 requireLogin() 을 통과시켰다 (authenticated 와 같다)

            case 'community.write':
            case 'travel.read':
            case 'travel.write':
                return self::canCommunityWrite($u);

            /* Q:F-9 (ARCH 4.9) — 1회용 로그인 링크 창구. 토큰 범위(`session.open`)는 [2-S] 가 이미 봤고, 여기서는 **계정**이
               지금도 그 토큰을 가질 자격이 있나(2단계 켜짐 · Guest 아님)를 다시 본다 — 토큰을 받은 뒤 2단계를 끈 계정은 여기서 끝. */
            case 'session.open':
                return self::canOpenSessionByToken($u);

            case 'assistant.run':
                return true; // 토큰/활성 사용자 관문은 앞에서, 본인/파일 판정은 실행 서비스에서.

            default:
                // 알 수 없는 정책 이름은 통과시키지 않는다 (기본 거부)
                return false;
        }
    }
}
