<?php
declare(strict_types=1);

/**
 * src/Legal.php — 개인정보 처리방침 · 이용약관 원본 목록과 동의 기록 한 곳
 *   (사이트 Q:F-13 지시서 P1 · 기획 ../docs/privacy-terms-plan.md 5.1 · 5.2 · 결정 PT-1~PT-4 · PT-7~PT-9)
 *
 * ① 문서 원본 — 저장소가 추적하는 `content/legal/<문서>-<판>.html`(정적 HTML 조각 · 사용자 입력 0)과 목록 `config/legal.json`
 *    (`{"privacy":[{"version":"YYYY-MM-DD","file":"privacy-YYYY-MM-DD.html"}],"terms":[…]}` · 최신이 끝). 화면은 **목록에 있는 파일만**
 *    그린다. 요청 값(`?v=`)은 목록의 판 글자와 **같은지 견주는 데만** 쓰고, 읽는 경로는 언제나 목록이 준 파일 이름이다(PRD S-06).
 *    파일 이름은 `<문서>-<판>.html` 와 정확히 같아야 한다 — 어긋나면 예외(→ 500 · 조용히 넘기지 않는다, Apps.php 와 같은 태도).
 *    ★ 판(시행일) 자리 — 결정 PT-3 「게시하는 날」. 저장소의 자리 값은 `0000-00-00` 이다(날짜를 지어내지 않는다). 발행하는 날
 *      메인 세션이 목록·파일 이름·본문 시행일 글자를 한 번에 그날로 바꾼다(`0000-00-00` → 실제 날짜 · 두 파일 `git mv`).
 * ② 동의 기록 — 표 `public.user_consents`(사람 · 문서 · 판 · 행동 · 때 · IP). 앱 계정은 **SELECT · INSERT 만**(db/grants.sql) —
 *    고치거나 지우는 코드가 이 저장소에 없다. 지금 상태 = 문서별 **마지막 줄**.
 *      terms · privacy  가입 · 기존 회원 안내 화면에서 agree(처리방침은 「확인」). 필요 조건 = 마지막 줄이 agree 이고 판이 **지금 판**
 *      age14            가입 때만 agree (약관 판을 적는다 — 약관 제4조)
 *      ai_overseas      AI 를 처음 쓸 때 agree · 「약관·동의」 화면에서 withdraw. 판은 목록의 **따로 둔 판**(`ai_overseas.version` —
 *                       Q:F-13 P2-b). 필요 조건 = 마지막 줄이 agree 이고 판이 **지금 AI 판**. 받는 곳·나라·등급(처리방침 제7조)이
 *                       바뀌면 이 판을 올린다 → 옛 판의 agree 는 「동의 없음」으로 보여(state() 가 null 로 접는다) 다시 묻는다.
 *                       처리방침 판이 바뀌는 것만으로는 다시 묻지 않는다(두 판은 따로 움직인다).
 * ③ 관문 판정 값만 낸다. 막는 자리는 Auth::requireLogin(세션 관문) · AI 서버 길 넷(MemberAi · MemberAssist · MemoryAi · CommunityAi)이다.
 *
 * 이 파일은 HTTP 요청을 만들지 않는다(외부 요청 0). 부트스트랩 require 목록에 새 줄을 더하지 않고 쓰는 곳에서 읽어 온다(Apps.php 와 같다).
 */
final class Legal
{
    /** 게시하는 문서 — 목록 파일의 키와 같다 */
    public const DOCS = ['privacy', 'terms'];

    /** 문서 제목(기획 부록 A·B 머리 글자 그대로) */
    public const TITLES = ['privacy' => '모아(Qaredi) 개인정보 처리방침', 'terms' => '모아(Qaredi) 이용약관'];

    /** 링크·화면에 쓰는 짧은 이름 */
    public const LABELS = ['privacy' => '개인정보 처리방침', 'terms' => '이용약관'];

    /** 동의 기록 문서 이름 — DB CHECK(user_consents.doc)와 같다 */
    public const CONSENT_DOCS = ['terms', 'privacy', 'age14', 'ai_overseas'];

    /** 기존 회원이 다음 로그인 때 한 번 하는 것(PT-4) — 둘 다 지금 판에 agree 여야 관문이 열린다 */
    public const REQUIRED_DOCS = ['terms', 'privacy'];

    /** 동의 줄의 판은 어느 문서의 판을 적나 — ai_overseas 는 목록의 따로 둔 판(aiVersion, P2-b) */
    private const VERSION_OF = ['terms' => 'terms', 'privacy' => 'privacy', 'age14' => 'terms'];

    /** 목록 파일의 최상위 키 — 게시 문서 둘 + AI 국외 전송 판(P2-b) */
    private const LIST_KEYS = ['privacy', 'terms', 'ai_overseas'];

    /** 세션 관문(Auth::requireLogin)이 막을 때의 문장 — 403 `{error, next:"consent", code:"consent_required"}` */
    public const MSG_CONSENT_REQUIRED = '이용약관 동의와 개인정보 처리방침 확인을 먼저 마쳐 주세요';

    /** AI 서버 길이 막을 때의 문장 — 409 `{error, code:"ai_consent_required", notice}` */
    public const MSG_AI_CONSENT_REQUIRED = 'AI 기능을 쓰려면 먼저 Google 로 보내는 국외 전송에 동의해 주세요';

    /**
     * AI 국외 전송 동의 창의 글자 — 처리방침 제7조를 줄로 나눈 것(서버 한 곳 · 화면 셋이 이 값을 그대로 그린다).
     * 「[… — 확인]」 표시가 붙은 두 값(국가 · 보유)은 문서 원문이 아직 확인 대기라 창에는 표시 없이 본문 글자만 옮긴다.
     */
    private const AI_NOTICE = [
        'title' => 'AI 기능 — 국외 전송 동의',
        'rows'  => [
            ['받는 곳', 'Google LLC (Gemini API)'],
            ['나라', '미국 등'],
            ['보내는 것', '질문·앞 대화(최대 10턴)·다듬을 글(상대 메시지 포함 가능)·사진(위치 정보 제거)·장소·날짜'],
            ['언제·어떻게', 'AI 기능을 실행할 때 암호화된 통신으로'],
            ['보유', 'Google 의 정책에 따름'],
            ['거부하면', 'AI 기능만 쓸 수 없고, 서비스의 다른 기능은 그대로 쓸 수 있습니다'],
        ],
        'note'  => '동의는 「약관·동의」 화면에서 언제든 철회할 수 있습니다.',
    ];

    private const LIST_FILE   = __DIR__ . '/../config/legal.json';
    private const CONTENT_DIR = __DIR__ . '/../content/legal';
    private const VERSION_RE  = '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/';
    /** 문서 조각 크기 상한(바이트) — 정적 파일이라 넉넉히, 그래도 무한은 아니다 */
    private const MAX_BYTES = 262144;

    /** 한 요청 안에서 목록을 두 번 읽지 않는다 */
    private static ?array $catalog = null;
    /** AI 국외 전송 동의의 지금 판(목록의 `ai_overseas.version` — DB CHECK 와 같은 YYYY-MM-DD 모양) */
    private static ?string $aiVersion = null;
    /** 한 요청 안의 동의 상태 캐시(사람 번호 → 상태). 기록하면 그 사람 것을 지운다 */
    private static array $state = [];

    /**
     * 목록 — 문서마다 판 목록(오래된 것 → 최신). 모양이 어긋나면 예외.
     *
     * @return array<string, list<array{version:string,file:string}>>
     */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        $raw = @file_get_contents(self::LIST_FILE);
        if (!is_string($raw)) {
            throw new RuntimeException('약관 목록 파일을 읽을 수 없습니다');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || array_keys($data) !== self::LIST_KEYS) {
            throw new RuntimeException('약관 목록의 문서 키가 올바르지 않습니다');
        }
        $ai = $data['ai_overseas'];
        if (!is_array($ai) || array_keys($ai) !== ['version'] || !is_string($ai['version'])
            || preg_match(self::VERSION_RE, $ai['version']) !== 1) {
            throw new RuntimeException('약관 목록의 AI 국외 전송 판이 올바르지 않습니다');
        }
        $out = [];
        foreach (self::DOCS as $doc) {
            $list = $data[$doc];
            if (!is_array($list) || $list === [] || array_keys($list) !== range(0, count($list) - 1)) {
                throw new RuntimeException('약관 목록의 판 목록이 올바르지 않습니다');
            }
            $previous = '';
            foreach ($list as $item) {
                if (!is_array($item) || array_keys($item) !== ['version', 'file']
                    || !is_string($item['version']) || preg_match(self::VERSION_RE, $item['version']) !== 1
                    || $item['file'] !== $doc . '-' . $item['version'] . '.html'
                    || strcmp($item['version'], $previous) <= 0
                ) {
                    throw new RuntimeException('약관 목록의 판 항목이 올바르지 않습니다');
                }
                $previous = $item['version'];
                $out[$doc][] = ['version' => $item['version'], 'file' => $item['file']];
            }
        }
        self::$aiVersion = $ai['version'];
        return self::$catalog = $out;
    }

    /**
     * 판 하나. `$version` 이 null 이면 지금 판(목록 끝), 아니면 목록에 **글자 그대로 있는** 판만 — 없으면 null(호출자가 404).
     *
     * @return array{version:string,file:string}|null
     */
    public static function entry(string $doc, ?string $version = null): ?array
    {
        if (!in_array($doc, self::DOCS, true)) {
            return null;
        }
        $list = self::catalog()[$doc];
        if ($version === null) {
            return $list[count($list) - 1];
        }
        foreach ($list as $item) {
            if (hash_equals($item['version'], $version)) {
                return $item;
            }
        }
        return null;
    }

    /** 동의 문서(terms·privacy·age14·ai_overseas)의 지금 판 글자 */
    public static function current(string $consentDoc): string
    {
        if ($consentDoc === 'ai_overseas') {
            self::catalog();
            return (string) self::$aiVersion;
        }
        $doc = self::VERSION_OF[$consentDoc] ?? null;
        if ($doc === null) {
            throw new InvalidArgumentException('알 수 없는 동의 문서');
        }
        return self::entry($doc)['version'];
    }

    /**
     * 판 하나의 HTML 조각. 경로는 목록이 준 파일 이름뿐이고(catalog 가 `<문서>-<판>.html` 모양을 이미 확인), realpath 로
     * `content/legal/` 안인지 한 번 더 본다.
     *
     * @param array{version:string,file:string} $entry entry() 가 돌려준 값
     */
    public static function html(array $entry): string
    {
        $dir = realpath(self::CONTENT_DIR);
        $path = realpath(self::CONTENT_DIR . '/' . basename((string) $entry['file']));
        if ($dir === false || $path === false || dirname($path) !== $dir || !is_file($path) || is_link(self::CONTENT_DIR . '/' . basename((string) $entry['file']))) {
            throw new RuntimeException('약관 원본 파일이 없습니다');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('약관 원본 파일 크기가 올바르지 않습니다');
        }
        $html = file_get_contents($path);
        if (!is_string($html) || preg_match('//u', $html) !== 1) {
            throw new RuntimeException('약관 원본 파일을 읽을 수 없습니다');
        }
        return $html;
    }

    /**
     * 그 사람의 지금 동의 상태 — 문서별 마지막 줄(없으면 null). `$fresh` 면 요청 캐시를 건너뛰고 다시 읽는다(동의 창구가 잠금 뒤에).
     *
     * @return array<string, array{version:string,action:string,at:string}|null>
     */
    public static function state(int $userId, bool $fresh = false): array
    {
        if (!$fresh && isset(self::$state[$userId])) {
            return self::$state[$userId];
        }
        $rows = Db::instance()->run(<<<'SQL'
            SELECT DISTINCT ON (doc) doc, version, action, at
              FROM user_consents
             WHERE user_id = :uid
             ORDER BY doc, id DESC
            SQL,
            [':uid' => $userId])->fetchAll();
        $out = array_fill_keys(self::CONSENT_DOCS, null);
        foreach ($rows as $row) {
            if (array_key_exists((string) $row['doc'], $out)) {
                $out[(string) $row['doc']] = ['version' => (string) $row['version'], 'action' => (string) $row['action'], 'at' => (string) $row['at']];
            }
        }
        // P2-b — AI 국외 전송은 **지금 AI 판**의 줄만 지금 상태다. 옛 판의 줄(동의든 철회든)은 「아직 이 판에 답하지 않음」(null)으로 접는다 —
        // 그래야 관문(aiAgreed)·동의 창구(me_consents 의 「이미 그 상태인가」)·「약관·동의」 화면이 모두 다시 묻는다. 기록 줄은 그대로다.
        if ($out['ai_overseas'] !== null && !hash_equals(self::current('ai_overseas'), $out['ai_overseas']['version'])) {
            $out['ai_overseas'] = null;
        }
        return self::$state[$userId] = $out;
    }

    /** 약관 동의 · 처리방침 확인이 지금 판에 없는가(세션 관문이 막아야 하나) */
    public static function needsConsent(int $userId): bool
    {
        $state = self::state($userId);
        foreach (self::REQUIRED_DOCS as $doc) {
            $last = $state[$doc];
            if ($last === null || $last['action'] !== 'agree' || !hash_equals(self::current($doc), $last['version'])) {
                return true;
            }
        }
        return false;
    }

    /** AI 국외 전송의 지금 상태가 동의인가 — 지금 AI 판의 마지막 줄이 agree(옛 판은 state() 가 이미 null 로 접었다) */
    public static function aiAgreed(int $userId): bool
    {
        $last = self::state($userId)['ai_overseas'];
        return $last !== null && $last['action'] === 'agree' && hash_equals(self::current('ai_overseas'), $last['version']);
    }

    /**
     * 본인에게 보여 주는 지금 상태 — `GET /api/me/consents` 와 「약관·동의」 화면이 같은 값을 쓴다(IP 는 내보내지 않는다).
     *
     * @return array{current:array{terms:string,privacy:string},required:bool,ai_overseas:bool,consents:array<string,mixed>}
     */
    public static function summary(int $userId, bool $fresh = false): array
    {
        $state = self::state($userId, $fresh);
        return [
            'current'     => ['terms' => self::current('terms'), 'privacy' => self::current('privacy')],
            'required'    => self::needsConsent($userId),
            'ai_overseas' => self::aiAgreed($userId),
            'consents'    => $state,
        ];
    }

    /** 동의 창이 그릴 글자 — 409 본문 `notice` 와 「약관·동의」 화면이 같은 값을 쓴다 */
    public static function aiNotice(): array
    {
        return self::AI_NOTICE + ['policy_url' => Http::url('/privacy') . '#privacy-7'];
    }

    /**
     * AI 서버 길(외부 호출 **전**)이 부른다. 동의가 지금 상태가 아니면 409 `{error, code:"ai_consent_required", notice}` 로 끝낸다 —
     * 이 줄 뒤의 어떤 코드(예약 · 기록 · 외부 호출)도 돌지 않는다. 부르는 자리는 DB 트랜잭션 **밖**이어야 한다.
     * 웹 요청 전용 — 배치(CLI)는 aiAgreed() 로 보고 스스로 거부한다(CommunityAi::perform 의 색인 갈래).
     */
    public static function requireAiConsent(int $userId): void
    {
        if (self::aiAgreed($userId)) {
            return;
        }
        Http::json([
            'error'  => self::MSG_AI_CONSENT_REQUIRED,
            'code'   => 'ai_consent_required',
            'notice' => self::aiNotice(),
        ], 409);
        exit;
    }

    /**
     * 동의 줄을 **더한다**(고치거나 지우지 않는다) + 감사 한 줄. 트랜잭션은 부르는 쪽이 연다(가입은 계정 만들기와 한 트랜잭션).
     *
     * @param array{id:int,username:string} $target 동의한 사람(가입은 막 만든 계정 · 그 밖은 세션의 본인)
     * @param list<string> $docs   CONSENT_DOCS 의 값만
     * @param string       $action 'agree' | 'withdraw'(ai_overseas 만 — DB CHECK 도 같다)
     * @param array<string,mixed> $extra 감사 detail 에 더할 값(코드가 정한 분류어만)
     */
    public static function record(array $target, array $docs, string $action, array $extra = []): void
    {
        if ($docs === [] || array_diff($docs, self::CONSENT_DOCS) !== [] || count(array_unique($docs)) !== count($docs)
            || !in_array($action, ['agree', 'withdraw'], true)
            || ($action === 'withdraw' && $docs !== ['ai_overseas'])
        ) {
            throw new InvalidArgumentException('동의 기록 인자가 올바르지 않습니다');
        }
        $db = Db::instance();
        if (!$db->inTransaction()) {
            throw new LogicException('동의 기록은 트랜잭션 안에서만');
        }
        $uid = (int) $target['id'];
        $written = [];
        foreach ($docs as $doc) {
            $version = self::current($doc);
            $db->run(<<<'SQL'
                INSERT INTO user_consents (user_id, doc, version, action, ip)
                VALUES (:uid, :doc, :version, :action, CAST(:ip AS inet))
                SQL,
                [':uid' => $uid, ':doc' => $doc, ':version' => $version, ':action' => $action, ':ip' => Http::clientIp()]);
            $written[] = ['doc' => $doc, 'version' => $version];
        }
        unset(self::$state[$uid]);
        Audit::allow($action === 'agree' ? 'consent.agree' : 'consent.withdraw', [
            'target_type' => 'user',
            'target_id'   => $uid,
            'target_name' => (string) $target['username'],
            'detail'      => ['consents' => $written] + $extra,
        ]);
    }
}
