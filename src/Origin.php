<?php
declare(strict_types=1);

/**
 * src/Origin.php — 「누가 일으켰나(origin)」 판정 한 곳 (F-71 C-5 · F-69 A — 두 지시서 공통)
 *
 * 요구사항: PRD F-71 ④ · F-69 ② · S-07 「검토 요청 표식」, 지시서 `docs/f71-claude-accounts-directive.md` C-5 표,
 *           `docs/f69-alert-origin-directive.md` A-1·A-2 (사용자 확인 K-1 — 「계정 **그리고** UA」)
 *
 * 값 (F-69 A 표의 넷 — 사용자 확인 K-2 로 `system` 추가)
 *   human           사람(또는 가려낼 수 없는 것)                         F-70: 즉시
 *   system          행위자가 없는 배치 판정(정합성·백업·감사 끊김)        F-70: 즉시
 *   claude-review   검토 계정 + 검토 표식으로 앱에서 한 행동               F-70: 하루 요약
 *   claude-session  AI 세션이 서버에서 임시·시험 DB 를 다룬 명령(AL-9)     F-70: 하루 요약
 *
 * 앱 행동 판정 (F-71 C-5 표 그대로)
 *   | 계정이 `qa_`·`claude_` 로 시작 | UA 가 `tbox-review/` 로 시작 | origin          | origin_hint |
 *   |---|---|---|---|
 *   | 예                              | 예                            | `claude-review` | null        |
 *   | 둘 중 하나만                    |                               | `human`         | `partial`(화면 「확인 필요」) |
 *   | 아니오                          | 아니오                        | `human`         | null        |
 *
 * 왜 「그리고」인가 (F-69 A-2): UA 는 요청이 정하는 값이라 누구나 흉내 낸다(`Http::userAgent()` 주석 —
 * 「표식이지 인증이 아니다」). 「또는」이면 공격자가 UA 만 바꿔 자기 행동을 「Claude」로 보이게 하고,
 * F-70 이 들어오면 그 경보가 즉시 알림에서 빠진다. 검토 계정의 비밀번호·TOTP 는 root 0600 파일에만
 * 있으므로 둘 다 맞추려면 이미 서버가 뚫린 상태다. 정상 검토 요청은 늘 둘 다 맞는다(CLAUDE.md 3장).
 *
 * ★ 이 판정은 **보여 주기와 알림 분류에만** 쓴다. 권한·차단·감사 기록 여부에는 절대 쓰지 않는다
 *   (F-69 금지 패턴). 그래서 Authz·Auth·Audit 은 이 파일을 읽지 않는다 — origin 판정을 읽는 곳은
 *   화면에 내려 줄 값을 만드는 핸들러(`audit_list.php`)와 경보 배치(`scripts/alerts.php` ·
 *   `consistency_check.php` · `audit_chain.php`, `cli_raise_alert()` 경유)뿐이다.
 *   예외 하나 — `users_create.php` 는 **isReviewAccount() 이름 판정만** 읽어 사람 계정 이름에 검토 접두를
 *   못 쓰게 막는다(대기열 2b · PRD F-71 ③). origin 판정(UA 포함)이 아니라 「이름 예약」 규칙이고,
 *   접두 목록을 두 벌로 두지 않으려고 같은 함수를 쓴다. 이 이름 판정으로 권한을 주거나 빼지 않는다.
 * ★ 시험의 R-73 사전 검사(`tests/lib/review_accounts.sh`)는 **계정 이름만** 본다 — 그쪽은 「사람이 쓰는
 *   중이면 멈춘다」가 안전한 쪽이라 일부러 다르다(F-71 C 끝 문단). 검토 계정 접두 둘은 두 파일에 같다.
 *
 * 경보 규칙별 판정 (F-69 A-1 표 — 구현한 대로)
 *   | 규칙 | 재료 | origin |
 *   |---|---|---|
 *   | AL-1·10·12·13·18·20 (묶음 = 행위자) | 묶음의 actor_name + 집계 SQL `bool_and(COALESCE(user_agent,'') LIKE :review_ua)` (바인딩 = reviewMarkLikePattern()) | ofGroup() → classify() |
 *   | AL-2 (묶음 = 출발 IP)                | 실패 행들이 겨눈 계정(행위자, 없으면 대상) 전부 + 모든 행 UA | ofGroup() — 전부 검토 계정 **그리고** 전부 표식 |
 *   | AL-5 (감사 행 하나)                  | 그 행의 actor_name·user_agent | ofAppAction() |
 *   | AL-19·21 (파일 하나)                 | 그 파일을 올린 감사 행(file.upload·file.upload_exec·file.fetch allow)의 actor·UA | ofGroup() |
 *   | AL-6·7·8                             | 행위자 없음 | `system` (ofFixedRule) |
 *   | AL-3                                 | OS 계정@IP | 늘 `human` (ofFixedRule — SSH 로 들어오는 것은 사람이다) |
 *   | AL-9                                 | al_scrub_command() 를 거친 sudo 명령 | ofSudoCommand() — 겨눈 DB 가 **전부** 임시·시험 DB 이름일 때만 `claude-session` |
 *   | 옛 규칙(AL-14·16·17 …)               | — | 판정하지 않는다(발동하지 않는다) |
 *
 * 입력은 감사 행의 **저장된 값**(actor_name·user_agent)과 배치가 이미 가린 명령 문자열이다. 요청 파라미터를
 * 읽지 않고 DB·파일·네트워크에 닿지 않는다 — 순수 함수라 `php -r` 로 단위 확인할 수 있다.
 */
final class Origin
{
    /** 사람(또는 가려낼 수 없는 것) */
    public const HUMAN = 'human';

    /** 검토 계정 + 검토 표식으로 앱에서 한 행동 */
    public const CLAUDE_REVIEW = 'claude-review';

    /** 둘 중 하나만 맞음 — 화면은 「확인 필요」 */
    public const HINT_PARTIAL = 'partial';

    /**
     * 검토 계정 이름 접두 (ARCH 4.0 · PRD S-07). `qa_` = 옛 시험, `claude_` = 새 시험(F-71).
     * tests/lib/review_accounts.sh 의 SQL 조각과 **같은 둘**이다 — 늘리거나 줄이면 두 곳을 함께.
     */
    private const REVIEW_ACCOUNT_PREFIXES = ['qa_', 'claude_'];

    /** 검토 요청 표식 — `User-Agent: tbox-review/<날짜>` (CLAUDE.md 3장) */
    private const REVIEW_UA_PREFIX = 'tbox-review/';

    /** 행위자가 없는 배치 판정 — 정합성(AL-6)·감사 끊김(AL-7)·백업 실패(AL-8) (F-69 A · K-2) */
    public const SYSTEM = 'system';

    /** AI 세션이 서버에서 임시·시험 DB 를 다룬 명령 — AL-9 에서만 나온다 (F-69 A-1) */
    public const CLAUDE_SESSION = 'claude-session';

    /** 경보 한 줄의 origin 이 될 수 있는 값 넷. cli_raise_alert() 가 이 밖의 값을 받지 않는다 */
    public const VALUES = [self::HUMAN, self::SYSTEM, self::CLAUDE_REVIEW, self::CLAUDE_SESSION];

    /** 경보 제목 앞머리 — origin 이 `claude-*` 인 줄만 (F-69 A-3) */
    private const CLAUDE_TITLE_PREFIX = '[Claude] ';

    /**
     * 재료 없이 값이 정해진 규칙 (F-69 A-1).
     *   AL-3 — SSH 로 들어오는 것은 사람이다. AI 세션은 이 VM 안에서 돈다(SSH 를 타지 않는다).
     *          그래서 계정·출처가 무엇이든 늘 `human` 이다 — 서버 침입의 첫 신호가 요약으로 밀리지 않게.
     *   AL-6·7·8 — 행위자가 없는 배치 판정.
     */
    private const FIXED_BY_RULE = [
        'AL-3' => self::HUMAN,
        'AL-6' => self::SYSTEM,
        'AL-7' => self::SYSTEM,
        'AL-8' => self::SYSTEM,
    ];

    /**
     * AL-9 — 임시·시험 DB 이름 규칙 (정규식 한 줄, F-69 A-1).
     * `_test`·`_test2`·`_scratch` 로 끝나거나, `qcheck_` 로 시작하거나, 정확히 `comm_check`.
     * 소문자·숫자·밑줄만 — 따옴표·세미콜론·`=` 등이 붙은 값(접속 문자열 포함)은 이름으로 보지 않는다
     * (= 가려낼 수 없음 → `human`). 운영 DB `tbox_re` 는 어느 갈래에도 맞지 않는다.
     */
    private const SCRATCH_DB_RE = '/^(?:[a-z0-9_]+_(?:test|test2|scratch)|qcheck_[a-z0-9_]+|comm_check)$/';

    /** PostgreSQL 식별자 최대 길이(바이트) — 이보다 길면 이름으로 보지 않는다 */
    private const DB_NAME_MAX = 63;

    /** AL-9 에서 DB 를 겨누는 것으로 읽을 수 있는 PostgreSQL 도구 (sudo 가 실행한 첫 낱말의 basename) */
    private const PG_TOOLS = ['psql', 'pg_dump', 'pg_restore', 'dropdb', 'createdb'];

    /**
     * 첫 낱말이 경로면 PostgreSQL 이 설치되는 자리여야 도구로 본다(`/tmp/psql` 같은 가짜는 모름 → human, 독립 검토 F-69 L-1).
     * 경로 없는 이름(auth.log 에는 보통 절대 경로가 남는다)은 그대로 이름으로 본다.
     */
    private const PG_TOOL_PATH_RE = '#^(?:/usr)?/bin/[a-z_]+$|^/usr/lib/postgresql/[0-9.]+/bin/[a-z_]+$#';

    /**
     * psql 에서 **파일을 읽거나 쓰는** 짧은 옵션 글자 — 짧은 옵션 낱말(`-f`·`-qf`·`-ofile`) 안에 하나라도 있으면 모름.
     * f = --file(스크립트 내용이 안 보인다) · o = --output · L = --log-file(postgres 권한으로 서버 파일에 쓴다, 독립 검토 F-69 L-2).
     * 붙은 값(`-dcomm_foo_test`) 안의 글자까지 넓게 걸리지만, 넓게 걸리면 human 쪽이다.
     */
    private const PSQL_FILE_SHORT_RE = '/^-[^-]*[foL]/';

    /** psql 에서 파일을 읽거나 쓰는 긴 옵션 — 줄임(`--fi`·`--out`)까지 본다 */
    private const PSQL_FILE_LONG = ['file', 'output', 'log-file'];

    /**
     * 위치 인자에서 DB 이름을 찾는 도구가 **값을 받는다고 확실히 아는** 옵션 (따로 떨어진 낱말일 때만).
     * 이 목록에 있는 옵션 바로 뒤 낱말은 DB 후보에서 뺀다. 목록에 없는 옵션 뒤 낱말은 후보로 **남긴다** —
     * 모르는 것을 후보로 남기면 판정이 `human` 쪽으로만 기운다(안전한 쪽). 거꾸로 이 목록에 값을 받지 않는
     * 옵션이 잘못 들어가면 진짜 DB 이름을 놓칠 수 있으므로, 각 도구의 getopt 문자열에서 확실한 것만 적었다.
     * `-d`/`--dbname`(pg_dump) · `-T`/`--template`(createdb) 은 여기가 아니라 DB 로 따로 모은다.
     * `--maintenance-db` 는 dropdb·createdb 가 **접속만** 하는 DB 라 대상이 아니다(여기서 뺀다).
     */
    private const PG_ARG_OPTIONS = [
        'pg_dump'  => ['-f', '-F', '-j', '-n', '-N', '-t', '-T', '-Z', '-E', '-e', '-S', '-h', '-p', '-U',
                       '--file', '--format', '--jobs', '--schema', '--exclude-schema', '--table',
                       '--exclude-table', '--exclude-table-data', '--compress', '--encoding', '--extension',
                       '--superuser', '--host', '--port', '--username', '--role', '--section',
                       '--snapshot', '--lock-wait-timeout'],
        'dropdb'   => ['-h', '-p', '-U', '--host', '--port', '--username', '--maintenance-db'],
        'createdb' => ['-D', '-E', '-l', '-O', '-S', '-h', '-p', '-U',
                       '--tablespace', '--encoding', '--locale', '--owner', '--strategy',
                       '--host', '--port', '--username', '--maintenance-db'],
    ];

    /**
     * psql 명령에서 **접속한 DB 밖**(다른 DB·역할·서버 파일·셸)에 닿을 수 있는 낱말 — 보이면 `human`.
     * `psql -d qcheck_1 -c 'DROP DATABASE tbox_re'` 처럼 접속한 DB 는 시험 DB 여도 겨눈 DB 는 운영일 수
     * 있기 때문이다(「겨눈 DB 가 **전부** 시험 DB」의 뜻). 역슬래시(메타 명령 `\c`·`\!`·`\copy` …)는
     * 따로 막는다. 문자열 이어 붙이기 같은 우회까지 다 막는 목록은 아니다 — 그래서 `DO`·`EXECUTE`·
     * `LANGUAGE` 처럼 동적 실행의 입구가 되는 낱말도 넣었다. `-f 파일`·표준입력의 **내용은 보지 못한다**
     * (auth.log 에 없다) — 그래서 `-f`/`--file` 이 있거나 `-c`/`--command` 가 **없으면**(= 표준입력 스크립트)
     * 겨눈 DB 를 모르는 것으로 본다(sudoDbTargets → null → human, 독립 검토 F-69 M-1). 이 판정은 알림 분류일 뿐 차단이 아니다.
     */
    private const PSQL_CROSS_BOUNDARY_RE = '/\b(?:DATABASE|ROLE|USER|PROGRAM|TABLESPACE|SYSTEM|SERVER|SUBSCRIPTION|PUBLICATION|LANGUAGE|EXECUTE|DO|COPY|dblink\w*|lo_\w+|pg_read_\w+|pg_ls_\w+|pg_file_\w+|pg_stat_file|pg_terminate_backend|pg_cancel_backend|pg_reload_conf|pg_rotate_logfile)\b/i';

    /**
     * 앱 행동 한 건(감사 행 하나)의 origin.
     *
     * @return array{origin: string, origin_hint: ?string}
     */
    public static function ofAppAction(?string $actorName, ?string $userAgent): array
    {
        return self::classify(self::isReviewAccount($actorName), self::hasReviewMark($userAgent));
    }

    /**
     * 감사 행 하나의 origin — 저장된 칸만 본다. `ofAppAction` 과 같되, **로그인 성공** 행 하나만 예외다:
     * `auth.login allow` 는 세션이 생기기 전에 기록되어 `actor_name` 이 비어 있고 로그인한 계정은 `target_name` 에 있다.
     * 성공한 로그인의 대상은 비밀번호(와 2단계)를 맞춘 계정이라 흉내 낼 수 없으므로 그 행에서만 대상을 계정으로 본다
     * (독립 검토 F-71 L-3 — 안 그러면 Claude 의 정상 로그인이 전부 「확인 필요」가 된다). **실패** 로그인은 그대로 행위자만 본다
     * — 남의 이름을 대고 틀린 비밀번호를 보내는 것은 누구나 할 수 있다.
     *
     * @return array{origin: string, origin_hint: ?string}
     */
    public static function ofAuditRow(?string $actorName, ?string $userAgent, string $action, ?string $result, ?string $targetName): array
    {
        $who = $actorName;
        if (($who === null || $who === '') && $action === 'auth.login' && $result === 'allow') {
            $who = $targetName;
        }
        return self::ofAppAction($who, $userAgent);
    }

    /**
     * 판정 표 **한 곳** — 「계정이 검토 계정인가」와 「표식이 달렸나」 두 불리언에서 origin 을 고른다.
     * F-69 의 묶음 규칙은 두 번째 인자로 「묶음의 모든 행이 표식인가」를 넘겨 같은 표를 쓴다.
     *
     * @return array{origin: string, origin_hint: ?string}
     */
    public static function classify(bool $reviewAccount, bool $reviewMark): array
    {
        if ($reviewAccount && $reviewMark) {
            return ['origin' => self::CLAUDE_REVIEW, 'origin_hint' => null];
        }
        if ($reviewAccount || $reviewMark) {
            // 표식 없는 검토 계정 · 표식 달린 사람 계정(또는 계정 없는 행) — 사람으로 세고 표시만 남긴다.
            return ['origin' => self::HUMAN, 'origin_hint' => self::HINT_PARTIAL];
        }
        return ['origin' => self::HUMAN, 'origin_hint' => null];
    }

    /** 계정 이름이 검토 계정 접두로 시작하나 (null·빈 값은 아니다) */
    public static function isReviewAccount(?string $actorName): bool
    {
        if ($actorName === null || $actorName === '') {
            return false;
        }
        foreach (self::REVIEW_ACCOUNT_PREFIXES as $prefix) {
            if (str_starts_with($actorName, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** User-Agent 가 검토 표식으로 시작하나 (null·빈 값은 아니다). 표식이지 인증이 아니다 */
    public static function hasReviewMark(?string $userAgent): bool
    {
        if ($userAgent === null || $userAgent === '') {
            return false;
        }
        return str_starts_with($userAgent, self::REVIEW_UA_PREFIX);
    }

    // ─── F-69 — 경보 갈래 (서버 층·배치) ─────────────────────────────────────

    /** 검토 계정 접두 목록 — 문구를 만들 때만 쓴다(판정은 isReviewAccount 한 곳). @return list<string> */
    public static function reviewAccountPrefixes(): array
    {
        return self::REVIEW_ACCOUNT_PREFIXES;
    }

    /**
     * 검토 표식의 SQL LIKE 패턴(`tbox-review/%`) — 경보 집계 SQL 이 **바인딩**해 쓴다(접두를 SQL 에 베끼지 않게).
     * LIKE 의 특수 글자(`\`·`%`·`_`)는 이스케이프한다 — 오늘 접두에는 없지만, 생기는 날 「아무 글자」로 읽히지 않게.
     */
    public static function reviewMarkLikePattern(): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::REVIEW_UA_PREFIX) . '%';
    }

    /** 경보 줄에 들어갈 수 있는 origin 값인가 (F-69 A 표의 넷) */
    public static function isValue(string $origin): bool
    {
        return in_array($origin, self::VALUES, true);
    }

    /**
     * 묶음 규칙의 origin — 묶음이 겨눈 계정 **전부**가 검토 계정이고, 묶음의 **모든 행**이 표식을 달았을 때만
     * `claude-review`. 판정 표는 classify() 한 곳 그대로다(한쪽만 맞으면 `human` + partial).
     *
     * @param list<?string> $accounts  묶음의 계정 이름들 (AL-1·10·12·13·18·20 은 행위자 하나,
     *                                 AL-2 는 실패 행들이 겨눈 계정들, AL-19·21 은 올린 사람들). 비면 검토 계정 아님
     * @param bool          $allMarked 집계 SQL `bool_and(COALESCE(user_agent,'') LIKE :review_ua)` 결과
     *                                 (:review_ua = reviewMarkLikePattern() — 접두를 SQL 에 베끼지 않는다)
     * @return array{origin: string, origin_hint: ?string}
     */
    public static function ofGroup(array $accounts, bool $allMarked): array
    {
        $allReview = $accounts !== [];
        foreach ($accounts as $account) {
            if (!is_string($account) || !self::isReviewAccount($account)) {
                $allReview = false;
                break;
            }
        }
        return self::classify($allReview, $allMarked);
    }

    /**
     * 재료 없이 값이 정해진 규칙이면 그 값, 아니면 null (AL-3 → human · AL-6·7·8 → system).
     *
     * @return array{origin: string, origin_hint: ?string}|null
     */
    public static function ofFixedRule(string $rule): ?array
    {
        if (!isset(self::FIXED_BY_RULE[$rule])) {
            return null;
        }
        return ['origin' => self::FIXED_BY_RULE[$rule], 'origin_hint' => null];
    }

    /**
     * 경보 제목 — `claude-*` 면 `[Claude] ` + 규칙 문장, 아니면 규칙 문장 그대로 (F-69 A-3).
     * 보여 주기 전용 글자다. 화면·alerts.log 가 같은 값을 쓰도록 여기 한 곳에서 만든다.
     */
    public static function title(string $origin, string $ruleText): string
    {
        return str_starts_with($origin, 'claude-') ? self::CLAUDE_TITLE_PREFIX . $ruleText : $ruleText;
    }

    /** AL-9 — 임시·시험 DB 이름인가 (SCRATCH_DB_RE · 63바이트 이하) */
    public static function isScratchDatabase(string $name): bool
    {
        return $name !== '' && strlen($name) <= self::DB_NAME_MAX && preg_match(self::SCRATCH_DB_RE, $name) === 1;
    }

    /**
     * AL-9 sudo 명령 하나의 origin — 명령이 겨눈 DB 가 **전부** 임시·시험 DB 이름일 때만 `claude-session`.
     *
     * 이 VM 에서는 사람과 AI 세션이 같은 OS 계정(`changkwon`)으로 sudo 를 친다. 계정으로는 못 가르므로
     * **명령이 겨눈 DB** 로만 가른다(F-69 머리 실측). 그리고 조금이라도 모호하면 `human` 이다 —
     * `claude-session` 은 F-70 에서 하루 요약으로 밀리므로, 잘못 붙으면 사람 경보가 늦어진다.
     *
     * @param string $command  `al_scrub_command()` 를 거친 명령(비밀값이 가려지고 앞 200자만 남은 것)
     * @param bool   $complete 자르기 전 명령이 통째로 남았나. 잘렸으면 뒤에 무엇이 있었는지 모르므로 `human`
     * @return array{origin: string, origin_hint: ?string}
     */
    public static function ofSudoCommand(string $command, bool $complete = true): array
    {
        $human = ['origin' => self::HUMAN, 'origin_hint' => null];
        if (!$complete) {
            return $human;
        }
        $targets = self::sudoDbTargets($command);
        if ($targets === null || $targets === []) {
            return $human;   // DB 이름을 가려낼 수 없다(rm·chattr·truncate·find -delete 포함)
        }
        foreach ($targets as $db) {
            if (!self::isScratchDatabase($db)) {
                return $human;   // 운영 DB(tbox_re)를 겨누면 읽기 쿼리라도 사람 경보
            }
        }
        return ['origin' => self::CLAUDE_SESSION, 'origin_hint' => null];
    }

    /**
     * sudo 명령이 겨눈 DB 이름 목록. **가려낼 수 없으면 null**(→ ofSudoCommand 가 human).
     *
     * 원칙: 모르는 것은 「후보로 남기거나」「가려낼 수 없음」으로 떨어뜨린다 — 어느 쪽이든 human 쪽이다.
     *   · 첫 낱말(sudo 가 실행한 프로그램)의 basename 이 PG_TOOLS 가 아니면 null — 셸(`sh -c`)·`env`·`timeout`
     *     같은 감싸개 뒤에 무엇이 오는지는 보지 않는다(파일 삭제류 rm·chattr·truncate·find 도 여기서 null).
     *   · auth.log 의 `COMMAND=` 는 따옴표가 벗겨져 있다 — SQL 글자가 여러 낱말로 쪼개져 보인다. 그래서
     *     psql 은 위치 인자를 보지 않고 `-d`/`--dbname` 값만 본다(psql 은 -d 가 있으면 위치 인자를 DB 로
     *     쓰지 않는다). -d 가 없으면 null.
     *   · `-d` 꼴은 옵션 해석과 무관하게 **탐욕스럽게** 모은다: 따로 떨어진 `-d 값`, 붙은 `-d값`, 짧은 옵션
     *     묶음 안의 d(`-qd 값`), `--dbname=값`·`--dbname 값`(줄임 `--db` 도). 필요 이상 모으면 human 쪽이다.
     *
     * @return list<string>|null
     */
    public static function sudoDbTargets(string $command): ?array
    {
        $tokens = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return null;
        }
        $tool = basename($tokens[0]);
        if (!in_array($tool, self::PG_TOOLS, true)) {
            return null;
        }
        if (strpos($tokens[0], '/') !== false && preg_match(self::PG_TOOL_PATH_RE, $tokens[0]) !== 1) {
            return null;   // 설치 자리 밖의 같은 이름 — 무엇인지 모른다
        }
        $args = array_slice($tokens, 1);

        if ($tool === 'psql') {
            // 메타 명령(`\c 다른DB` · `\! 셸` · `\copy` …)은 역슬래시로 시작한다 — 하나라도 있으면 모른다.
            if (strpos($command, '\\') !== false || preg_match(self::PSQL_CROSS_BOUNDARY_RE, $command) === 1) {
                return null;
            }
            // 스크립트 파일(-f)·출력 파일(-o·-L) — 내용이나 쓰는 자리가 명령에 보이지 않는다.
            // -c/--command 가 없으면 표준입력으로 스크립트를 읽는다 — 역시 내용이 보이지 않는다(M-1).
            $hasCommand = false;
            foreach ($args as $a) {
                if (str_starts_with($a, '--')) {
                    $name = explode('=', substr($a, 2), 2)[0];
                    foreach (self::PSQL_FILE_LONG as $long) {
                        if ($name !== '' && str_starts_with($long, $name)) {
                            return null;
                        }
                    }
                    if (strlen($name) >= 3 && str_starts_with('command', $name)) {
                        $hasCommand = true;
                    }
                    continue;
                }
                if (preg_match(self::PSQL_FILE_SHORT_RE, $a) === 1) {
                    return null;
                }
                if ($a === '-c' || preg_match('/^-c\S/', $a) === 1) {
                    $hasCommand = true;   // `-c` 가 첫 글자인 낱말만 — 묶음 안의 c(`-dqcheck_1`)는 세지 않는다(모르면 없는 쪽)
                }
            }
            if (!$hasCommand) {
                return null;
            }
            $dbs = self::collectDbOption($args, 'dbname');
            return $dbs === [] ? null : $dbs;
        }

        if ($tool === 'pg_restore') {
            // -C/--create 는 아카이브 안의 DB 이름으로 새로 만들고 접속한다 — 그 이름은 명령에 보이지 않는다.
            foreach ($args as $a) {
                if (str_starts_with($a, '--')) {
                    $name = explode('=', substr($a, 2), 2)[0];
                    if (strlen($name) >= 2 && str_starts_with('create', $name)) {
                        return null;   // --create · 줄임 --cr …
                    }
                    continue;
                }
                if (preg_match('/^-[A-Za-z0-9?]/', $a) === 1 && strpos($a, 'C', 1) !== false) {
                    return null;       // -C · 묶음 안의 C
                }
            }
            // 위치 인자는 아카이브 **파일**이다. 겨누는 DB 는 -d 뿐이고, -d 가 없으면 DB 에 닿지 않는다(표준출력).
            $dbs = self::collectDbOption($args, 'dbname');
            return $dbs === [] ? null : $dbs;
        }

        // pg_dump · dropdb · createdb — -d(또는 -T)로 준 값 + **모든** 위치 인자가 후보다.
        $dbs = $tool === 'pg_dump' ? self::collectDbOption($args, 'dbname') : [];
        if ($tool === 'createdb') {
            // -T/--template 로 복사해 올 DB 도 겨눈 DB 다(`createdb -T tbox_re x_test` 는 운영 사본을 만든다).
            $dbs = self::collectDbOption($args, 'template', 'T');
        }
        $argOptions = self::PG_ARG_OPTIONS[$tool];
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $a = $args[$i];
            if (str_starts_with($a, '-')) {
                if (in_array($a, $argOptions, true)) {
                    $i++;   // 값을 받는다고 확실히 아는 옵션 — 다음 낱말은 그 값이다
                }
                continue;
            }
            $dbs[] = $a;   // 위치 인자 — 이름 모양이 아니면 ofSudoCommand 에서 human 이 된다
        }
        return $dbs === [] ? null : array_values(array_unique($dbs));
    }

    /**
     * `-<짧은 글자>`·`--<긴 이름>` 옵션의 값을 **탐욕스럽게** 모은다(옵션 뜻을 해석하지 않는다).
     * 값이 없으면(마지막 낱말) 빈 글자를 넣어 이름 판정에서 떨어지게 한다.
     *
     * @param list<string> $args
     * @return list<string>
     */
    private static function collectDbOption(array $args, string $long, string $short = 'd'): array
    {
        $out = [];
        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            $a = $args[$i];
            if (str_starts_with($a, '--')) {
                $body = substr($a, 2);
                $eq   = strpos($body, '=');
                $name = $eq === false ? $body : substr($body, 0, $eq);
                // getopt_long 은 모호하지 않은 줄임을 받는다(`--db` = `--dbname`) — 앞부분이 같으면 같은 옵션으로 본다.
                if ($name !== '' && str_starts_with($long, $name)) {
                    $out[] = $eq === false ? (string) ($args[$i + 1] ?? '') : substr($body, $eq + 1);
                }
                continue;
            }
            // 짧은 옵션(묶음 포함). 붙은 값에는 `_` 등 아무 글자나 올 수 있으므로 첫 글자만 본다(`-dqcheck_9`).
            if (preg_match('/^-[A-Za-z0-9?]/', $a) !== 1) {
                continue;   // `-`(표준입력)·일반 낱말
            }
            $pos = strpos($a, $short, 1);
            if ($pos === false) {
                continue;
            }
            $rest  = substr($a, $pos + 1);
            $out[] = $rest !== '' ? $rest : (string) ($args[$i + 1] ?? '');
        }
        return $out;
    }
}
