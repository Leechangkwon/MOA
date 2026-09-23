<?php
declare(strict_types=1);

/**
 * src/Origin.php — 「누가 일으켰나(origin)」 판정 한 곳 (F-71 C-5 · F-69 A — 두 지시서 공통)
 *
 * 요구사항: PRD F-71 ④ · F-69 ② · S-07 「검토 요청 표식」, 지시서 `docs/f71-claude-accounts-directive.md` C-5 표,
 *           `docs/f69-alert-origin-directive.md` A-1·A-2 (사용자 확인 K-1 — 「계정 **그리고** UA」)
 *
 * 값 (F-69 A 표의 넷 중 이 파일이 지금 내는 것은 둘)
 *   human          사람(또는 가려낼 수 없는 것)
 *   claude-review  검토 계정 + 검토 표식으로 앱에서 한 행동
 *   (system · claude-session 은 F-69 가 서버 층·배치 경보 갈래와 함께 이 파일에 더한다 — 아래 「F-69 가 더할 자리」)
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
 *   (F-69 금지 패턴). 그래서 Authz·Auth·Audit 은 이 파일을 읽지 않는다 — 읽는 곳은 화면에 내려 줄
 *   값을 만드는 핸들러(`audit_list.php`)뿐이다.
 * ★ 시험의 R-73 사전 검사(`tests/lib/review_accounts.sh`)는 **계정 이름만** 본다 — 그쪽은 「사람이 쓰는
 *   중이면 멈춘다」가 안전한 쪽이라 일부러 다르다(F-71 C 끝 문단). 검토 계정 접두 둘은 두 파일에 같다.
 *
 * F-69 가 더할 자리 (이번에 만들지 않는다 — F-71 지시: 「앱 행동 판정만, 나중에 더하기 쉽게 함수 하나로」)
 *   · 값 상수 `system`·`claude-session`
 *   · 묶음 규칙(AL-1·10·12·13·18·20): 집계 SQL 의 `bool_and(user_agent LIKE 'tbox-review/%')` 를 받아
 *     `classify(self::isReviewAccount($actor), $allMarked)` — 표는 classify() 한 곳 그대로 쓴다
 *   · AL-9 sudo 명령의 DB 이름 규칙 · AL-3·6·7·8 의 고정 값
 *
 * 입력은 감사 행의 **저장된 값**(actor_name·user_agent)이다. 요청 파라미터를 읽지 않고 DB·파일·네트워크에
 * 닿지 않는다 — 순수 함수라 `php -r` 로 단위 확인할 수 있다.
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
}
