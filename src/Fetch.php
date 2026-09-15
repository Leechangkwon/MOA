<?php
declare(strict_types=1);

/* F-43 — 공개 링크 → 내려받기 주소 변환 (순수 함수·네트워크 0). 리다이렉트 루프가 부른다.
   bootstrap.php 의 명시 목록에 새 줄을 더하지 않고 여기서 한 번만 읽는다 (Sensitive.php 와 같은 방식). */
require_once __DIR__ . '/CloudLink.php';

/**
 * src/Fetch.php — URL 로 파일 가져오기의 외부 요청 코드 (PRD F-14 · S-05 ①~⑬, ARCHITECTURE 5.6)
 *
 * ★ 이 파일이 프로젝트에서 **외부로 나가는 유일한 통로**다 (PRD S-05 ⑬ "코드 격리").
 *   `curl_*` 는 이 파일 밖에 한 줄도 없어야 하고, scripts/security_lint.sh 가 그것을 확인한다.
 *   php-fpm 풀은 `allow_url_fopen=Off` 와 소켓 함수 차단으로 두 번째 통로 자체를 막는다 (ARCH 9장).
 *
 * SSRF 방어의 뼈대는 셋이다 (PRD 7.5 머리말).
 *   ① 갈 수 있는 곳을 미리 정해 둔다      → allowedHosts() + check() 의 정확 일치
 *   ② 검사한 곳과 실제 가는 곳이 같다      → check() 가 돌려준 IP 를 CURLOPT_RESOLVE 로 고정하고,
 *                                          접속에 쓰는 URL 도 **검사한 host 로 다시 조립한 것**만 쓴다
 *   ③ 가져온 것을 업로드와 똑같이 검사한다 → 호출자(files_fetch.php)가 Upload::validatePath()
 *
 * F-43 (2026-09-15) 로 달라진 것 두 가지 — 뼈대 셋은 그대로다.
 *   · 허용 목록이 `.env` 한 줄에서 **표 `fetch_hosts`** 로 옮겨졌다(allowedHosts). 값이 어디서
 *     오느냐가 바뀌었을 뿐, 정확 일치·와일드카드 금지는 그대로다.
 *   · 3xx 를 전면 거부하는 대신 **통제된 리다이렉트**(최대 3번)를 둔다. 단계마다
 *     redirectStep() + check() 를 처음부터 다시 통과해야 하고, CURLOPT_FOLLOWLOCATION 은
 *     여전히 false 다 — curl 이 스스로 따라가면 ①②가 무력해진다 (SECURITY R-77).
 *
 * F-46 (2026-09-15) 이 더한 것 둘 — 뼈대 셋은 그대로다.
 *   · **같은 호스트로 가는 다음 단계에만** 직전 응답의 쿠키를 싣는다(cookieHeader). SharePoint 의
 *     공개 링크가 302 뒤 같은 호스트에서 쿠키를 요구하기 때문이다. 다른 호스트로 넘어가면 버린다.
 *   · 공급자의 **내려받기 전용 호스트**(회전 도메인)를 코드 상수로 둔다(PROVIDER_DOWNLOAD_SUFFIXES).
 *     구글 시트의 `export` 가 매번 다른 `doc-xx-yy-sheets.googleusercontent.com` 으로 넘기기 때문이며,
 *     **리다이렉트 다음 단계에서만** 열린다. 첫 요청은 언제나 관리자 허용 목록의 정확 일치다.
 *
 * 이 파일이 하지 않는 것
 *   · 권한·속도 제한·감사 로그 — 핸들러(src/handlers/files_fetch.php)의 몫이다.
 *   · 사용자에게 무엇을 보여 줄지 결정하기 — 실패는 예외(FetchDenied)로만 알리고,
 *     응답 본문·헤더·상태코드는 **한 글자도 밖으로 나가지 않는다** (PRD S-05 ⑩).
 *     curl 의 오류 문구는 error_log 로만 남긴다.
 */

/**
 * 가져오기 거부 — `$reason` 이 곧 감사 로그의 `reason` 이다 (ARCH 7.4 file.fetch 행).
 *
 * 지시서 설계 예시의 이름은 `$code` 였지만 `Exception::$code`(정수)와 이름이 겹쳐
 * PHP 8.1 이 `Cannot redeclare non-readonly property` 로 거부한다 → `$reason` 으로 둔다.
 *
 *   scheme · port · host_not_allowed · private_ip · redirect · too_large · fetch_failed
 *   needs_login (F-43 — 공개 링크가 아니라 로그인 화면(HTML)이 돌아왔다)
 *
 * `$httpStatus` 는 외부 응답 상태(ARCH 7.5 `http_status`), `$host` 는 정규화된 호스트
 * (ARCH 7.5 `source_host`)다. 둘 다 감사 로그 전용이며 사용자 응답에는 들어가지 않는다.
 * **URL 전체는 어디에도 담지 않는다** — 경로에 토큰·개인정보가 있을 수 있다 (ARCH 7.5).
 */
final class FetchDenied extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        public readonly ?string $host = null
    ) {
        parent::__construct($reason);
    }
}

final class Fetch
{
    /** 받을 수 있는 최대 바이트 — 업로드와 같은 100MB (PRD S-05 ⑧ · S-06) */
    public const MAX_BYTES = 104857600;

    /** 연결 3초 · 전체 10초 (PRD S-05 ⑧) */
    public const CONNECT_TIMEOUT = 3;
    public const TIMEOUT         = 10;

    /** URL 길이 상한 — 이보다 길면 파싱하지 않고 거부한다 */
    private const MAX_URL_BYTES = 2048;

    /** 허용 스킴·포트는 하나씩뿐이다 (PRD S-05 ①②) */
    private const SCHEME = 'https';
    private const PORT   = 443;

    /** 외부에 알리는 클라이언트 이름 (상대 서버의 로그에 남는다) */
    private const USER_AGENT = 'tbox-fetch/1';

    /** 파일명을 못 정했을 때 (확장자가 없으므로 Upload 가 ext_not_allowed 로 거부한다) */
    private const FALLBACK_NAME = 'download';

    /**
     * 내부망·특수 목적 대역 (PRD S-05 ⑤, ARCH 5.6 목록 그대로).
     * `::ffff:0:0/96` 은 IPv4 매핑 IPv6(`::ffff:127.0.0.1`)를 통째로 막는다 —
     * 그래도 isPrivate() 은 매핑 주소를 IPv4 로 환원해 한 번 더 검사한다 (SECURITY 1.6 보완 항목).
     */
    private const PRIVATE_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::1/128', '::/128', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96',
    ];

    /** 호스트 표기 — 소문자 영숫자·점·붙임표만 (PRD S-05 ④) */
    private const HOST_RE = '/^[a-z0-9.-]{1,253}$/';

    /**
     * 공급자의 **내려받기 전용 호스트** (F-46, 사용자 실측 2026-09-15).
     *
     * 왜 필요한가 — 구글 시트의 `…/export?format=xlsx` 는 307 로
     * `doc-08-9g-sheets.googleusercontent.com` 처럼 **매번 바뀌는 이름**으로 넘긴다.
     * 정확 일치 허용 목록으로는 담을 수 없는 회전 도메인이라 2단계에서 `host_not_allowed` 가 났다.
     *
     * 규칙은 좁다. 이 표는 **세 조건이 모두 맞을 때만** 허용 목록을 대신한다.
     *   ① 리다이렉트 **다음 단계**일 것 — 첫 요청은 언제나 관리자 허용 목록의 정확 일치다
     *   ② 첫 주소의 공급자(CloudLink 판정)가 그 칸의 공급자일 것 — provider 가 null 이면 적용 0
     *   ③ 다음 호스트가 이 값에 맞을 것 — `.` 로 시작하면 **접미**(앞에 라벨이 하나 이상 있어야 한다),
     *      아니면 정확 일치. 그래서 `evil.googleusercontent.com.attacker.tld` 는 맞지 않는다
     *
     * 그 밖의 검사(https · 443 · 호스트 표기 · DNS 뒤 사설 IP · 최대 3단계)는 하나도 빠지지 않는다.
     * **이 값은 코드 상수다** — 화면에서 관리자가 넣는 허용 목록의 「와일드카드 금지」 규칙은 그대로이고,
     * 여기에 값을 더하려면 코드를 고쳐 검토를 받아야 한다. 지나온 호스트는 감사 `hops` 에 그대로 남는다.
     *
     * @var array<string,list<string>>
     */
    private const PROVIDER_DOWNLOAD_SUFFIXES = [
        CloudLink::PROVIDER_GOOGLE    => ['.googleusercontent.com', 'drive.usercontent.google.com'],
        CloudLink::PROVIDER_MICROSOFT => ['.files.1drv.com', '.sharepoint.com'],
    ];

    /**
     * 리다이렉트 단계에 실어 보내는 쿠키의 상한 (F-46).
     * 20개·합계 8KB 를 넘으면 **자르지 않고 쿠키 없이** 보낸다 — 반쪽짜리 쿠키는
     * 보내 봐야 의미가 없고, 상대 서버에 무엇이 갈지 예측할 수 없는 상태를 만들지 않는다.
     */
    public const COOKIE_MAX_PAIRS = 20;
    public const COOKIE_MAX_BYTES = 8192;

    /**
     * 리다이렉트를 따라갈 수 있는 **최대 횟수** (F-43, PRD F-43, SECURITY R-77).
     *
     * 7단계에는 3xx 를 무조건 거부했다. 드라이브·OneDrive 의 공개 파일이 내려받기 호스트로
     * 한두 번 넘기기 때문에 그 규칙으로는 둘 다 가져올 수 없다(지시서 0장 실측).
     * 그래서 **따라가되 통제한다**: `CURLOPT_FOLLOWLOCATION` 은 여전히 false 이고,
     * 단계마다 우리 코드가 Location 을 읽어 `check()` 를 **처음부터** 다시 통과시킨다
     * (https · 443 · 허용 호스트 · DNS 뒤 사설 IP). curl 이 스스로 따라가면 그 검사가
     * 한 번도 일어나지 않는다 — 그것이 7단계에 전면 거부를 택한 이유였다.
     */
    public const MAX_REDIRECTS = 3;

    /**
     * 허용 호스트 목록 (PRD S-05 ③ · F-43, ARCH 5.22).
     *
     * 두 곳을 합친다.
     *   ① **표 `fetch_hosts` 의 활성 행** — F-43 이 만든 자리. 관리자가 화면에서 넣고 빼며,
     *      바꾼 사실이 `fetch_host.change` 로 감사 로그에 남는다. 이것이 평소의 통로다.
     *   ② `.env` 의 `FETCH_ALLOWED_HOSTS` — 7단계부터 있던 자리. 마이그레이션이 값을 표로
     *      옮겼고 지금은 비어 있지만, **비상 통로**로 남겨 둔다: DB 가 이상해 화면으로 못
     *      고치는 상황에서도 서버에 들어갈 수 있는 사람은 열 수 있어야 한다.
     *
     * 규칙은 두 곳 모두 같다.
     *   · trim·소문자. 빈 항목은 버린다.
     *   · **둘 다 비면 빈 배열** = 전부 거부다 (Q-10 초기값). 비었다고 전부 허용하지 않는다.
     *   · `*` 나 공백이 섞인 `.env` 항목은 **설정 오류**로 보고 예외를 던진다 — 와일드카드를
     *     조금이라도 해석하면 화이트리스트가 블랙리스트로 변한다 (SECURITY 1.6).
     *     호출자는 이 예외를 host_not_allowed 로 기록한다 = 그 상태에서는 전부 거부.
     *   · 표 쪽은 같은 모양을 **DB CHECK 와 창구(fetch_hosts_update.php)가 이미** 강제하지만,
     *     읽는 자리에서도 한 번 더 거른다 — 표를 직접 고친 행이 있어도 통과하지 않게.
     *
     * ★ 요청 값에서 읽는 자리가 한 군데도 없다. 목록은 DB 와 설정 파일에서만 온다 (PRD S-01).
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $out = [];

        // ① 표 — 활성 행만
        foreach (self::dbHosts() as $host) {
            $out[] = $host;
        }

        // ② .env (비상 통로)
        $raw = Env::getOrEmpty('FETCH_ALLOWED_HOSTS');
        foreach (explode(',', $raw) as $item) {
            $host = strtolower(trim($item));
            if ($host === '') {
                continue;
            }
            if (strpos($host, '*') !== false || preg_match('/\s/', $host) === 1) {
                throw new RuntimeException('FETCH_ALLOWED_HOSTS 에 와일드카드 또는 공백이 있습니다');
            }
            $out[] = $host;
        }

        return array_values(array_unique($out));
    }

    /**
     * 표 `fetch_hosts` 의 **활성 호스트**. 조회는 값 바인딩이 없는 고정 문장 하나다 (PRD S-04).
     *
     * 읽은 값도 `HOST_RE` 와 와일드카드 검사를 다시 통과해야 목록에 들어간다 — DB 를 직접
     * 고친 행이 있어도 `*` 가 허용 목록에 들어가는 길을 만들지 않는다(닫힌 방향으로 실패).
     *
     * @return list<string>
     */
    private static function dbHosts(): array
    {
        $sql = <<<'SQL'
            SELECT host
              FROM fetch_hosts
             WHERE is_active
             ORDER BY host
            SQL;

        $out = [];
        foreach (Db::instance()->run($sql)->fetchAll() as $row) {
            $host = strtolower(trim((string) ($row['host'] ?? '')));
            if ($host === '' || strpos($host, '*') !== false || preg_match(self::HOST_RE, $host) !== 1) {
                continue;
            }
            $out[] = $host;
        }
        return $out;
    }

    /**
     * 화면에 보여 줄 허용 목록 (F-43 — `/api/me` 의 `fetch_hosts_public`, 도움말 모달).
     *
     * `allowedHosts()` 와 같은 값이되 **설정 오류로 터지지 않는다**: `.env` 에 와일드카드가
     * 섞여 있으면 그 상태는 "아무 호스트도 허용되지 않음" 이므로 빈 배열을 돌려준다.
     * 화면 편의 값이며 권한이 아니다 — 여기 없는 주소를 보내도, 여기 있는 주소를 보내도
     * 허용 여부는 요청마다 `check()` 가 다시 판단한다 (PRD S-01).
     *
     * @return list<string>
     */
    public static function publicHosts(): array
    {
        try {
            $hosts = self::allowedHosts();
        } catch (RuntimeException $e) {
            error_log('[tbox] fetch 허용 목록 설정 오류: ' . $e->getMessage());
            return [];
        }
        sort($hosts);
        return array_values($hosts);
    }

    /**
     * URL 검사 (PRD S-05 ①②③④⑤ · ARCH 5.6 [3][4][5]). 실패는 FetchDenied(사유).
     *
     * 통과하면 `['host' => 정규화 호스트, 'ip' => 접속에 고정할 IP, 'url' => 다시 조립한 URL]`.
     *
     * `url` 을 함께 돌려주는 이유(설계 예시에서 더한 부분): 사용자가 보낸 문자열을 그대로 curl 에
     * 넘기면 `https://RAW.GITHUBUSERCONTENT.COM./x` 처럼 **검사한 host 와 접속 host 의 표기가
     * 달라질 수 있다.** 그러면 CURLOPT_RESOLVE 항목과 이름이 어긋나 curl 이 실제 DNS 를 다시 물어
     * ⑥(IP 고정)이 무력해진다. 그래서 검사를 통과한 host 로 URL 을 다시 조립해 그것만 쓴다.
     *
     * @param ?string $downloadProvider **리다이렉트 다음 단계에서만** 넘어오는 공급자 이름
     *                (F-46). 이 값이 있으면 허용 목록 정확 일치 대신 PROVIDER_DOWNLOAD_SUFFIXES
     *                의 내려받기 호스트도 통과시킨다. 첫 요청(핸들러 [4])은 **언제나 null** 로
     *                부르므로, 사람이 보낸 주소는 관리자 허용 목록에 정확히 있어야만 나간다.
     *
     * @return array{host:string,ip:string,url:string}
     */
    public static function check(string $url, ?string $downloadProvider = null): array
    {
        if (strlen($url) > self::MAX_URL_BYTES) {
            throw new FetchDenied('bad_request');
        }
        // 제어문자·공백이 섞인 주소는 파싱 결과가 구현마다 달라진다 → 파싱 전에 거부한다
        if (preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new FetchDenied('bad_request');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new FetchDenied('host_not_allowed');
        }

        // ① 스킴 — https 만. file·ftp·gopher·dict·data·jar 은 여기서 끝난다
        if (strtolower((string) ($parts['scheme'] ?? '')) !== self::SCHEME) {
            throw new FetchDenied('scheme');
        }
        // ② 포트 — 없거나 443 만
        if (isset($parts['port']) && (int) $parts['port'] !== self::PORT) {
            throw new FetchDenied('port');
        }
        // ④ 사용자정보(user:pw@host) — 파서 혼동을 노린 표기
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchDenied('host_not_allowed');
        }

        // ④ 호스트 정규화: 소문자 + 끝의 '.' 제거
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (preg_match(self::HOST_RE, $host) !== 1) {
            // '[' 를 포함하는 IPv6 리터럴(`[::1]`), 퓨니코드 밖 문자, 빈 호스트가 여기서 걸린다.
            // 정규화에 **실패**한 값이므로 감사 로그에는 host 를 남기지 않는다 → '?' (ARCH 7.5).
            // 검사에 걸린 문자열을 그대로 로그에 넣으면 사용자 입력이 로그로 흘러드는 통로가 된다.
            throw new FetchDenied('host_not_allowed');
        }
        if ($host === 'localhost'
            || strpos($host, '[') !== false
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ctype_digit(str_replace('.', '', $host))          // 10진 IP(2130706433), 8진 표기
            || strpos($host, '0x') === 0                          // 16진 표기(0x7f000001)
        ) {
            throw new FetchDenied('host_not_allowed', null, $host);
        }

        // ③ 화이트리스트 — 정확 일치만. 목록이 비면 어떤 호스트도 통과하지 못한다.
        //   F-46: 리다이렉트 다음 단계에 한해 공급자의 내려받기 호스트도 통과시킨다
        //   (첫 요청은 $downloadProvider 가 null 이라 이 곁길이 열리지 않는다).
        if (!in_array($host, self::allowedHosts(), true)
            && !self::providerHostAllowed($downloadProvider, $host)
        ) {
            throw new FetchDenied('host_not_allowed', null, $host);
        }

        // ⑤ DNS 해석 — A/AAAA 가 하나도 없거나, 하나라도 내부망이면 거부
        $ips = self::resolve($host);
        if ($ips === []) {
            throw new FetchDenied('private_ip', null, $host);
        }
        foreach ($ips as $ip) {
            if (self::isPrivate($ip)) {
                throw new FetchDenied('private_ip', null, $host);
            }
        }

        return [
            'host' => $host,
            'ip'   => $ips[0],
            'url'  => self::rebuild($host, $parts),
        ];
    }

    /**
     * 검사한 IP 로 고정 접속해 임시파일에 내려받는다 (PRD S-05 ⑥⑦⑧, ARCH 5.6 [6][7] · 5.22).
     *
     * ★ F-43 — **통제된 리다이렉트** (SECURITY R-77)
     *   `CURLOPT_FOLLOWLOCATION` 은 여전히 `false` 다. 3xx 를 만나면 curl 이 아니라 **우리 코드**가
     *   `Location` 을 읽어 다음 단계를 정한다. 그리고 다음 단계로 가기 전에 반드시
     *     redirectStep()  — 횟수 · https · 443 · 허용 호스트 (네트워크 없이 판단)
     *     check()         — **처음부터 다시** (표기 정규화 · 허용 목록 · DNS 뒤 사설 IP)
     *   둘을 통과해야 한다. 최대 self::MAX_REDIRECTS 번이고 넘으면 `redirect` 로 거부한다.
     *   curl 이 스스로 따라가면 이 검사가 **한 번도** 일어나지 않는다 — 그래서 옵션은 false 로 둔다.
     *
     * ★ 응답이 HTML 이면 (`Content-Type: text/html`) 공급자 링크에 한해 `needs_login` 으로 거부한다.
     *   드라이브·OneDrive 는 권한이 없으면 파일 대신 **로그인 화면**을 200 으로 준다.
     *   그것을 파일로 저장하면 "로그인 페이지가 첨부된 문서" 가 쌓인다.
     *
     * 실패는 FetchDenied. 임시파일은 성공·실패 모두 **호출자**가 지운다.
     *
     * ★ F-46 — **같은 호스트로 가는 다음 단계에만** 직전 응답의 쿠키를 싣는다 (SECURITY R-77 보충)
     *   SharePoint 의 공개 링크는 `download=1` 로 302 한 뒤 **같은 호스트**의 파일 주소로 보내면서
     *   `Set-Cookie: FedAuth=…` 를 준다. 그 쿠키 없이 따라가면 403 이다(사용자 실측 2026-09-15).
     *   그래서 직전 응답의 Set-Cookie 를 **이름=값만** 모아 다음 요청에 싣되,
     *   **다음 단계 호스트가 직전 호스트와 다르면 버린다** — 쿠키가 다른 사이트로 새지 않게.
     *   값은 어디에도 기록하지 않는다(감사에는 실은 **단계 수** `cookie_hops` 만).
     *
     * @param ?string $provider CloudLink 가 판정한 공급자(google·microsoft). 변환하지 않은
     *                          보통 주소면 null 이고, 그때는 HTML 검사와 followUp 이 적용되지 않는다.
     * @return array{filename:?string,hops:list<string>,host:string,ip:string,cookie_hops:int}
     */
    public static function download(
        string $url,
        string $host,
        string $ip,
        string $tmpPath,
        ?string $provider = null
    ): array {
        /** 지나온 호스트 — 감사 로그 detail.hops (첫 칸이 처음 주소의 호스트다) */
        $hops = [$host];

        /** 이번 요청에 실을 쿠키 (`이름=값; …`). 첫 요청은 언제나 빈 문자열이다 */
        $cookie = '';
        /** 쿠키를 실은 단계 수 — 감사 로그 detail.cookie_hops (값은 남기지 않는다) */
        $cookieHops = 0;

        for ($step = 0; $step <= self::MAX_REDIRECTS; $step++) {
            if ($cookie !== '') {
                $cookieHops++;
            }
            $one = self::request($url, $host, $ip, $tmpPath, $cookie);

            // ── 3xx : 다음 단계를 **우리가** 정한다 ────────────────────────
            if ($one['status'] >= 300 && $one['status'] < 400) {
                if ($one['location'] === '') {
                    // Location 없는 3xx — 갈 곳을 모른다. 추측하지 않는다.
                    throw new FetchDenied('redirect', $one['status'], $host);
                }
                // 1drv.ms → onedrive.live.com 처럼 다음 주소에 깃발이 필요한 경우에만 손본다.
                // 호스트는 바뀌지 않고, 고친 주소도 아래 두 검사를 그대로 지난다.
                $next = CloudLink::followUp(
                    self::absolute($one['location'], $url),
                    $provider
                );

                $decided = self::redirectStep($next, $step + 1, self::allowedHosts(), $provider);
                if (!$decided['ok']) {
                    throw new FetchDenied((string) $decided['reason'], $one['status'], $host);
                }

                // ★ 여기서 check() 를 **처음부터** 다시 부른다 — 호스트 표기 정규화, 허용 목록
                //   재확인, DNS 해석 뒤 사설 IP 차단까지 첫 주소와 똑같은 검사를 받는다.
                $checked = self::check((string) $decided['url'], $provider);
                $prevHost = $host;
                $url     = $checked['url'];
                $host    = $checked['host'];
                $ip      = $checked['ip'];
                $hops[]  = $host;
                // F-46 — 다음 단계가 **같은 호스트**일 때만 직전 응답의 쿠키를 싣는다.
                // 호스트가 바뀌면 cookieHeader() 가 빈 문자열을 돌려주어 쿠키가 버려진다.
                $cookie  = self::cookieHeader($one['set_cookie'], $prevHost, $host);
                continue;
            }

            if ($one['status'] !== 200) {
                throw new FetchDenied('fetch_failed', $one['status'], $host);
            }

            // ── 200 : 받은 것이 파일인가 ──────────────────────────────────
            if ($provider !== null && self::isHtml($one['content_type'])) {
                // 공개(링크가 있는 모든 사용자)가 아닌 문서다 — 로그인 화면을 파일로 저장하지 않는다.
                throw new FetchDenied('needs_login', $one['status'], $host);
            }

            $size = filesize($tmpPath);
            if ($size === false || $size > self::MAX_BYTES) {
                throw new FetchDenied('too_large', $one['status'], $host);
            }

            return [
                'filename'    => self::filenameFrom($one['disposition'], $url),
                'hops'        => $hops,
                'host'        => $host,
                'ip'          => $ip,
                'cookie_hops' => $cookieHops,
            ];
        }

        // 루프를 다 돌았다 = MAX_REDIRECTS 를 넘게 넘겼다
        throw new FetchDenied('redirect', null, $host);
    }

    /**
     * 리다이렉트 한 단계를 **따라가도 되는가** — 네트워크·DNS 가 한 줄도 없는 순수 판정이다.
     *
     * 왜 따로 떼어 두나 (지시서 완료 기준 (f))
     *   시험용 https 서버를 세우지 않고도 「허용 → 허용 → 통과」·「허용 → 비허용 → 거부」·
     *   「4번째 → 거부」·「Location 이 http → 거부」를 `php -r` 로 확인할 수 있어야 한다.
     *   판단이 curl 호출 안에 섞여 있으면 그 확인이 불가능하다.
     *
     * 여기서 보는 것은 **횟수 · 스킴 · 포트 · 호스트 표기 · 허용 목록**까지다.
     * DNS 해석과 사설 IP 차단은 호출자가 이어서 부르는 `check()` 가 한다(같은 검사를 두 번
     * 하는 셈이지만, 한쪽만 고쳐지는 날에도 다른 한쪽이 남는다).
     *
     * @param list<string> $allowed  허용 호스트 목록 (호출자가 allowedHosts() 로 받아 넘긴다)
     * @param ?string      $provider 첫 주소의 공급자 (F-46). 있으면 그 공급자의 내려받기 호스트
     *                     (PROVIDER_DOWNLOAD_SUFFIXES)도 통과시킨다. 이 함수는 **리다이렉트
     *                     단계에서만** 불리므로 첫 요청에는 이 곁길이 없다.
     * @return array{ok:bool,reason:?string,url:?string,host:?string}
     */
    public static function redirectStep(
        string $location,
        int $hop,
        array $allowed,
        ?string $provider = null
    ): array {
        $no = static function (string $reason): array {
            return ['ok' => false, 'reason' => $reason, 'url' => null, 'host' => null];
        };

        if ($hop > self::MAX_REDIRECTS) {
            return $no('redirect');
        }
        if ($location === '' || strlen($location) > self::MAX_URL_BYTES) {
            return $no('redirect');
        }
        if (preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
            return $no('redirect');
        }

        $parts = parse_url($location);
        if (!is_array($parts)) {
            return $no('redirect');
        }
        // 스킴 — https 만. `http://` 로 내려보내는 응답은 여기서 끝난다
        if (strtolower((string) ($parts['scheme'] ?? '')) !== self::SCHEME) {
            return $no('scheme');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== self::PORT) {
            return $no('port');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return $no('host_not_allowed');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (preg_match(self::HOST_RE, $host) !== 1) {
            return $no('host_not_allowed');
        }
        if ($host === 'localhost'
            || strpos($host, '[') !== false
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || ctype_digit(str_replace('.', '', $host))
            || strpos($host, '0x') === 0
        ) {
            return $no('host_not_allowed');
        }
        // 허용 목록 — 첫 주소와 **같은 목록, 같은 정확 일치**다. 넘긴 곳이라고 봐주지 않는다.
        // F-46 의 예외는 하나뿐이다: 공급자의 내려받기 호스트(회전 도메인). 아래 함수가 판단한다.
        if (!in_array($host, $allowed, true) && !self::providerHostAllowed($provider, $host)) {
            return $no('host_not_allowed');
        }

        return [
            'ok'     => true,
            'reason' => null,
            'url'    => self::rebuild($host, $parts),
            'host'   => $host,
        ];
    }

    /**
     * 이 호스트가 **그 공급자의 내려받기 호스트**인가 (F-46). 네트워크가 한 줄도 없는 순수 판정이다.
     *
     * `provider` 가 null 이면 언제나 false 다 — 보통 주소(변환하지 않은 링크)에는 이 곁길이 없다.
     * 표의 값이 `.` 로 시작하면 **접미**로 보되 앞에 라벨이 하나 이상 있어야 하고
     * (`.googleusercontent.com` 은 `doc-08-9g-sheets.googleusercontent.com` 에 맞지만
     *  `googleusercontent.com` 자체나 `evil.googleusercontent.com.attacker.tld` 에는 맞지 않는다),
     * `.` 로 시작하지 않으면 정확 일치다.
     *
     * 호출자는 **리다이렉트 단계에서만** 이 함수를 부른다(첫 요청은 관리자 허용 목록 정확 일치).
     */
    public static function providerHostAllowed(?string $provider, string $host): bool
    {
        if ($provider === null || $provider === '' || $host === '') {
            return false;
        }
        $suffixes = self::PROVIDER_DOWNLOAD_SUFFIXES[$provider] ?? [];
        foreach ($suffixes as $suffix) {
            if ($suffix[0] === '.') {
                $len = strlen($suffix);
                if (strlen($host) > $len && substr($host, -$len) === $suffix) {
                    return true;
                }
                continue;
            }
            if ($host === $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * 직전 응답의 `Set-Cookie` 들 → 다음 요청에 실을 `Cookie` 헤더 값 (F-46).
     *
     * 규칙 (SECURITY R-77 보충)
     *   · **호스트가 같을 때만.** 다음 단계가 다른 호스트면 빈 문자열이다 — 쿠키를 다른 사이트로
     *     보내지 않는다. 이것이 이 함수의 첫 줄인 이유다.
     *   · **이름=값만.** `Path`·`Domain`·`Secure`·`HttpOnly`·`Expires` 같은 속성은 버린다
     *     (첫 `;` 앞까지만 읽는다). 속성은 브라우저가 저장할 때 쓰는 값이지 보낼 때 쓰는 값이 아니다.
     *   · 최대 COOKIE_MAX_PAIRS(20)개까지만 모으고, 합계가 COOKIE_MAX_BYTES(8KB)를 넘으면
     *     **잘라내지 않고 빈 문자열**을 돌려준다(쿠키 없이 진행 → cookie_hops 0).
     *   · 이름·값에 제어문자·공백·`;`·`,` 가 섞인 줄은 버린다(헤더 주입 방지).
     *
     * 돌려준 값은 CURLOPT_COOKIE 로만 쓰이며 **로그·감사에 한 글자도 남지 않는다**.
     *
     * @param list<string> $setCookies 직전 응답의 Set-Cookie 헤더 값들(헤더 이름은 빠진 상태)
     */
    public static function cookieHeader(array $setCookies, string $prevHost, string $nextHost): string
    {
        $prev = strtolower(rtrim(trim($prevHost), '.'));
        $next = strtolower(rtrim(trim($nextHost), '.'));
        if ($prev === '' || $prev !== $next) {
            return '';
        }

        $pairs = [];
        foreach ($setCookies as $line) {
            if (count($pairs) >= self::COOKIE_MAX_PAIRS) {
                break;
            }
            if (!is_string($line)) {
                continue;
            }
            $semi = strpos($line, ';');
            $pair = trim($semi === false ? $line : substr($line, 0, $semi));
            $eq   = strpos($pair, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $name  = trim(substr($pair, 0, $eq));
            $value = trim(substr($pair, $eq + 1));
            if ($name === ''
                || preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name) !== 1
                || preg_match('/[\x00-\x20\x7f;,]/', $value) === 1
            ) {
                continue;
            }
            $pairs[] = $name . '=' . $value;
        }

        if ($pairs === []) {
            return '';
        }
        $header = implode('; ', $pairs);
        // 상한을 넘으면 **자르지 않고** 쿠키 없이 진행한다 (반쪽짜리 쿠키를 보내지 않는다).
        return strlen($header) > self::COOKIE_MAX_BYTES ? '' : $header;
    }

    /**
     * 상대 Location(`/a/b`·`b.txt`)을 지금 주소 기준의 절대 주소로 만든다.
     *
     * 스킴·호스트는 **지금 주소의 것**을 그대로 쓴다(상대 주소는 호스트를 바꿀 수 없다).
     * `//host/path` 처럼 스킴만 없는 표기는 https 를 붙인다 — 붙인 뒤에도 redirectStep 의
     * 허용 목록 검사를 지나야 하므로 여기서 관문이 느슨해지지 않는다.
     */
    private static function absolute(string $location, string $base): string
    {
        $loc = trim($location);
        if ($loc === '') {
            return '';
        }
        if (strpos($loc, '//') === 0) {
            return self::SCHEME . ':' . $loc;
        }
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $loc) === 1) {
            return $loc;    // 절대 주소 (스킴은 redirectStep 이 본다)
        }

        $parts  = parse_url($base);
        $scheme = strtolower((string) ($parts['scheme'] ?? self::SCHEME));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '';
        }
        if ($loc[0] === '/') {
            return $scheme . '://' . $host . $loc;
        }
        $dir = (string) ($parts['path'] ?? '/');
        $cut = strrpos($dir, '/');
        $dir = $cut === false ? '/' : substr($dir, 0, $cut + 1);
        return $scheme . '://' . $host . $dir . $loc;
    }

    /** 응답이 HTML 인가 (`text/html`·`application/xhtml+xml`). 매개변수(charset)는 무시한다. */
    private static function isHtml(string $contentType): bool
    {
        $type = strtolower(trim($contentType));
        $semi = strpos($type, ';');
        if ($semi !== false) {
            $type = trim(substr($type, 0, $semi));
        }
        return $type === 'text/html' || $type === 'application/xhtml+xml';
    }

    /**
     * 한 번의 HTTP 요청 (리다이렉트를 따라가지 않는다). download() 의 루프가 단계마다 부른다.
     *
     * 임시파일은 단계마다 **처음부터 다시 쓴다**(`wb`) — 3xx 본문이 파일에 남아 다음 단계
     * 내용 앞에 붙는 일이 없어야 한다.
     *
     * @param ?string $cookie 이 요청에 실을 `Cookie` 헤더 값 (F-46). 빈 문자열·null 이면 붙이지
     *                않는다. 호출자(download)가 **같은 호스트로 가는 다음 단계에만** 채워 준다.
     *
     * @return array{status:int,location:string,disposition:string,content_type:string,set_cookie:list<string>}
     */
    private static function request(
        string $url,
        string $host,
        string $ip,
        string $tmpPath,
        ?string $cookie = null
    ): array {
        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            throw new FetchDenied('fetch_failed', null, $host);
        }

        $disposition = '';
        $location    = '';
        $contentType = '';
        /** 이 응답이 준 Set-Cookie 들 — 다음 단계가 같은 호스트일 때만 쓰인다 @var list<string> */
        $setCookies  = [];

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            throw new FetchDenied('fetch_failed', null, $host);
        }

        curl_setopt_array($ch, [
            // 프로토콜 이중 잠금 — URL 검사를 통과했어도 curl 자체가 https 외에는 말하지 않는다
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            // ⑦ 리다이렉트를 curl 이 따라가지 않는다. 3xx 는 **호출자**가 검사하고 다시 부른다
            CURLOPT_FOLLOWLOCATION  => false,
            // ⑥ DNS 리바인딩 방지 — 검사한 IP 로만 접속한다
            CURLOPT_RESOLVE         => [$host . ':' . self::PORT . ':' . $ip],
            CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT         => self::TIMEOUT,
            // ⑧ Content-Length 로 미리 거르고(아래 progress 로 실제 수신 바이트도 본다)
            CURLOPT_MAXFILESIZE     => self::MAX_BYTES,
            CURLOPT_FILE            => $fh,          // 메모리가 아니라 임시파일로 스트리밍
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_USERAGENT       => self::USER_AGENT,
            CURLOPT_HTTPGET         => true,
            CURLOPT_FORBID_REUSE    => true,
            // 헤더는 파일에 쓰지 않는다. 필요한 세 줄만 따로 모아 둔다
            CURLOPT_HEADERFUNCTION  => static function ($handle, string $header)
                use (&$disposition, &$location, &$contentType, &$setCookies): int {
                $len = strlen($header);
                $pos = strpos($header, ':');
                if ($pos === false) {
                    return $len;
                }
                $name  = strtolower(trim(substr($header, 0, $pos)));
                $value = substr($header, $pos + 1);
                if ($name === 'content-disposition') {
                    $disposition = $value;
                } elseif ($name === 'location') {
                    $location = trim($value);
                } elseif ($name === 'content-type') {
                    $contentType = trim($value);
                } elseif ($name === 'set-cookie') {
                    // 값은 여기서 **읽기만** 한다 — 로그에 남기지 않고, 다음 단계가 같은 호스트일
                    // 때만 cookieHeader() 를 거쳐 쓰인다. 줄 수 자체에도 상한을 둔다.
                    if (count($setCookies) < 64) {
                        $setCookies[] = trim($value);
                    }
                }
                return $len;
            },
            // Content-Length 가 없는(chunked) 응답까지 잡으려면 실제 수신 바이트를 봐야 한다.
            // 0 이 아닌 값을 돌려주면 curl 이 전송을 끊는다(errno 42 = ABORTED_BY_CALLBACK).
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => static function ($handle, $dlTotal, $dlNow, $ulTotal, $ulNow): int {
                return ((int) $dlTotal > self::MAX_BYTES || (int) $dlNow > self::MAX_BYTES) ? 1 : 0;
            },
        ]);

        // F-46 — 같은 호스트로 가는 다음 단계에만 실린다(호출자가 정한다). 값은 로그에 없다.
        if (is_string($cookie) && $cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        $ok     = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            // curl 오류 문구는 사용자에게 나가지 않는다 — 서버 로그에만 (PRD S-05 ⑩)
            error_log(sprintf(
                '[tbox] request_id=%s file.fetch curl errno=%d host=%s: %s',
                TBOX_REQUEST_ID,
                $errno,
                $host,
                $errmsg
            ));
            if ($errno === CURLE_FILESIZE_EXCEEDED || $errno === CURLE_ABORTED_BY_CALLBACK) {
                throw new FetchDenied('too_large', $status !== 0 ? $status : null, $host);
            }
            throw new FetchDenied('fetch_failed', $status !== 0 ? $status : null, $host);
        }

        return [
            'status'       => $status,
            'location'     => $location,
            'disposition'  => $disposition,
            'content_type' => $contentType,
            'set_cookie'   => $setCookies,
        ];
    }

    /**
     * 저장에 쓸 원본 파일명 — Content-Disposition → URL 경로 마지막 → 'download' (PRD S-05 ⑨).
     * 여기서는 경로 문자·제어문자만 떼고, 확장자·내용 판정은 Upload 이 한다 (검증 함수는 하나다).
     */
    private static function filenameFrom(string $disposition, string $url): ?string
    {
        $name = self::filenameFromDisposition($disposition);
        if ($name !== null) {
            return $name;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if ($path !== '') {
            $name = self::cleanName(basename(rawurldecode($path)));
            if ($name !== null) {
                return $name;
            }
        }
        return self::FALLBACK_NAME;
    }

    /** `filename*=UTF-8''…` 를 먼저 보고, 없으면 `filename="…"` / `filename=…` */
    private static function filenameFromDisposition(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (preg_match("/filename\*\s*=\s*([A-Za-z0-9!#$%&+^_`{}~.-]+)'[^']*'([^;\r\n]+)/i", $value, $m) === 1) {
            if (strcasecmp(trim($m[1]), 'UTF-8') === 0) {
                $name = self::cleanName(rawurldecode(trim($m[2])));
                if ($name !== null) {
                    return $name;
                }
            }
        }
        if (preg_match('/filename\s*=\s*"([^"\r\n]*)"/i', $value, $m) === 1) {
            return self::cleanName($m[1]);
        }
        if (preg_match('/filename\s*=\s*([^;\r\n]+)/i', $value, $m) === 1) {
            return self::cleanName(trim($m[1]));
        }
        return null;
    }

    /** 경로 문자·제어문자 제거. 남는 것이 없거나 UTF-8 이 아니면 null (호출자가 다음 후보로 간다) */
    private static function cleanName(string $name): ?string
    {
        $clean = basename(str_replace('\\', '/', $name));
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]+/', '', $clean);
        $clean = trim($clean);
        if ($clean === '' || $clean === '.' || $clean === '..') {
            return null;
        }
        if (preg_match('//u', $clean) !== 1) {
            return null;   // 잘못된 UTF-8 은 DB·JSON 에서 문제를 만든다
        }
        if (strlen($clean) > 255) {
            $clean = substr($clean, 0, 255);
            if (preg_match('//u', $clean) !== 1) {
                return null;
            }
        }
        return $clean;
    }

    /**
     * 검사를 통과한 host 로 URL 을 다시 조립한다 (위 check() 주석의 이유).
     * 포트·사용자정보·프래그먼트는 넣지 않는다 — 앞의 검사에서 이미 걸러졌거나 서버로 가지 않는다.
     *
     * @param array<string,mixed> $parts parse_url 결과
     */
    private static function rebuild(string $host, array $parts): string
    {
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        return self::SCHEME . '://' . $host . $path . $query;
    }

    /**
     * A · AAAA 를 모두 모은다 (A 먼저). 조회 자체가 실패하면 빈 배열 = private_ip 로 거부된다.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $ips = [];
        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $key) {
            $records = @dns_get_record($host, $type);
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $r) {
                $value = $r[$key] ?? null;
                if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $value;
                }
            }
        }
        return array_values(array_unique($ips));
    }

    /**
     * 내부망·특수 대역인가 (PRD S-05 ⑤, SECURITY 1.6 "IPv6 처리 누락" 보완).
     *
     *   · `inet_pton` 으로 바이트로 바꿔 CIDR 과 비트 단위로 비교한다(문자열 비교 아님).
     *   · IPv4 매핑 IPv6(`::ffff:127.0.0.1`)·구형 IPv4 호환(`::127.0.0.1`)은
     *     **IPv4 로 환원해 다시 검사**한다.
     *   · 해석할 수 없는 값은 **내부망으로 본다**(닫힌 방향으로 실패).
     */
    public static function isPrivate(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true;
        }
        if (strlen($bin) === 16) {
            $prefix12 = substr($bin, 0, 12);
            if ($prefix12 === str_repeat("\x00", 10) . "\xff\xff"     // ::ffff:a.b.c.d
                || $prefix12 === str_repeat("\x00", 12)) {            // ::a.b.c.d (구형 호환 표기)
                if (self::inPrivateCidr(substr($bin, 12))) {
                    return true;
                }
            }
        }
        return self::inPrivateCidr($bin);
    }

    /** 주어진 바이트 주소가 PRIVATE_CIDRS 중 하나에 드는가 */
    private static function inPrivateCidr(string $bin): bool
    {
        foreach (self::PRIVATE_CIDRS as $cidr) {
            $slash = strpos($cidr, '/');
            if ($slash === false) {
                continue;
            }
            $network = @inet_pton(substr($cidr, 0, $slash));
            $bits    = (int) substr($cidr, $slash + 1);
            if ($network === false || strlen($network) !== strlen($bin)) {
                continue;   // 주소 계열이 다르면 비교 대상이 아니다
            }
            if (self::samePrefix($bin, $network, $bits)) {
                return true;
            }
        }
        return false;
    }

    /** 앞 $bits 비트가 같은가 */
    private static function samePrefix(string $a, string $b, int $bits): bool
    {
        $whole = intdiv($bits, 8);
        $rest  = $bits % 8;
        if ($whole > 0 && strncmp($a, $b, $whole) !== 0) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        $mask = 0xff << (8 - $rest) & 0xff;
        return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
    }
}
