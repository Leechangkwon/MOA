<?php
declare(strict_types=1);

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
     * 허용 호스트 목록 (.env `FETCH_ALLOWED_HOSTS`, PRD S-05 ③).
     *
     *   · `,` 로 나누고 trim·소문자. 빈 항목은 버린다.
     *   · **목록이 비면 빈 배열** = 전부 거부다 (Q-10 초기값). 비었다고 전부 허용하지 않는다.
     *   · `*` 나 공백이 섞인 항목은 **설정 오류**로 보고 예외를 던진다 — 와일드카드를 조금이라도
     *     해석하면 화이트리스트가 블랙리스트로 변한다 (SECURITY 1.6 "와일드카드 허용 금지").
     *     호출자는 이 예외를 host_not_allowed 로 기록한다 = 그 상태에서는 전부 거부.
     *
     * @return list<string>
     */
    public static function allowedHosts(): array
    {
        $raw  = Env::getOrEmpty('FETCH_ALLOWED_HOSTS');
        $out  = [];
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
     * URL 검사 (PRD S-05 ①②③④⑤ · ARCH 5.6 [3][4][5]). 실패는 FetchDenied(사유).
     *
     * 통과하면 `['host' => 정규화 호스트, 'ip' => 접속에 고정할 IP, 'url' => 다시 조립한 URL]`.
     *
     * `url` 을 함께 돌려주는 이유(설계 예시에서 더한 부분): 사용자가 보낸 문자열을 그대로 curl 에
     * 넘기면 `https://RAW.GITHUBUSERCONTENT.COM./x` 처럼 **검사한 host 와 접속 host 의 표기가
     * 달라질 수 있다.** 그러면 CURLOPT_RESOLVE 항목과 이름이 어긋나 curl 이 실제 DNS 를 다시 물어
     * ⑥(IP 고정)이 무력해진다. 그래서 검사를 통과한 host 로 URL 을 다시 조립해 그것만 쓴다.
     *
     * @return array{host:string,ip:string,url:string}
     */
    public static function check(string $url): array
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

        // ③ 화이트리스트 — 정확 일치만. 목록이 비면 어떤 호스트도 통과하지 못한다
        if (!in_array($host, self::allowedHosts(), true)) {
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
     * 검사한 IP 로 고정 접속해 임시파일에 내려받는다 (PRD S-05 ⑥⑦⑧, ARCH 5.6 [6][7]).
     *
     * 실패는 FetchDenied. 임시파일은 성공·실패 모두 **호출자**가 지운다.
     *
     * @return array{filename:?string}  Content-Disposition 에서 뽑은 이름(없으면 null)
     */
    public static function download(string $url, string $host, string $ip, string $tmpPath): array
    {
        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            throw new FetchDenied('fetch_failed', null, $host);
        }

        $disposition = '';
        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fh);
            throw new FetchDenied('fetch_failed', null, $host);
        }

        curl_setopt_array($ch, [
            // 프로토콜 이중 잠금 — URL 검사를 통과했어도 curl 자체가 https 외에는 말하지 않는다
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            // ⑦ 리다이렉트를 따라가지 않는다. 3xx 는 아래에서 실패로 처리한다
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
            // 헤더는 파일에 쓰지 않는다. Content-Disposition 한 줄만 따로 모아 둔다
            CURLOPT_HEADERFUNCTION  => static function ($handle, string $header) use (&$disposition): int {
                $len = strlen($header);
                $pos = strpos($header, ':');
                if ($pos !== false && strtolower(trim(substr($header, 0, $pos))) === 'content-disposition') {
                    $disposition = substr($header, $pos + 1);
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

        if ($status >= 300 && $status < 400) {
            throw new FetchDenied('redirect', $status, $host);
        }
        if ($status !== 200) {
            throw new FetchDenied('fetch_failed', $status, $host);
        }

        $size = filesize($tmpPath);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new FetchDenied('too_large', $status, $host);
        }

        return ['filename' => self::filenameFrom($disposition, $url)];
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
