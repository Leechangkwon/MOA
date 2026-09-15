<?php
declare(strict_types=1);

/**
 * src/CloudLink.php — 클라우드 문서의 **공개 링크**를 내려받기 주소로 바꾼다
 *                      (F-43, PRD F-43 · F-14 · S-05, ARCHITECTURE 5.22)
 *
 * 왜 필요한가
 *   사람이 손에 쥐는 주소는 **보는 주소**다. 드라이브의 `…/file/d/<ID>/view` 나 구글 문서의
 *   `…/document/d/<ID>/edit` 는 HTML 화면이고, 그대로 가져오면 파일이 아니라 웹 페이지가
 *   저장된다. 공급자마다 정해 둔 **내려받기 주소**로 바꿔 줘야 파일이 온다.
 *
 * 이 파일이 하는 일 / 하지 않는 일
 *   · 한다   — 주소 문자열을 보고 규칙표에 맞으면 다른 주소 문자열을 돌려준다. **순수 함수**다.
 *   · 안 한다 — 네트워크 요청(cURL·DNS)이 **한 줄도 없다**. 허용 여부 판단도 하지 않는다.
 *               바꾼 주소도 `Fetch::check()` 의 https·포트·허용 목록·사설 IP 검사를 똑같이
 *               지난다 — 변환은 "주소를 고쳐 주는 편의" 일 뿐 **관문이 아니다** (PRD S-01 과 같은 원칙).
 *               그래서 관리자가 `drive.google.com` 을 허용 목록에 넣지 않았다면 변환해도 거부된다.
 *
 * 판별 규칙 (호스트는 **정확 일치**, 경로·ID 는 정규식)
 *   drive.google.com/file/d/<ID>/…              → https://drive.google.com/uc?export=download&id=<ID>
 *   drive.google.com/open?id=<ID>               → 같음
 *   docs.google.com/document/d/<ID>/…           → https://docs.google.com/document/d/<ID>/export?format=docx
 *   docs.google.com/spreadsheets/d/<ID>/…       → …/export?format=xlsx
 *   docs.google.com/presentation/d/<ID>/…       → …/export?format=pptx
 *   onedrive.live.com/…                          → 원 주소에 download=1 을 더한다
 *   1drv.ms/…                                    → 그대로 두고 provider 만 표시한다.
 *                                                  이 주소는 onedrive.live.com 으로 302 하므로
 *                                                  download=1 은 **그 다음 단계**에 붙인다
 *                                                  (followUp(), Fetch 의 리다이렉트 루프에서 호출)
 *   <tenant>.sharepoint.com / <tenant>-my.sharepoint.com → 변환하지 않고 needs_login = true
 *                                                  (회사 테넌트 문서. 내려받기 주소 규칙이
 *                                                  테넌트마다 다르고 대개 로그인을 요구한다)
 *   그 밖                                         → 그대로. provider = null
 *
 * 파일 ID 는 base64url 글자(`A-Za-z0-9_-`)만 받는다. 그 밖의 글자가 섞이면 **변환하지 않는다** —
 * 애매한 문자열을 억지로 해석해 만든 주소로 서버가 나가지 않게 하려는 것이다(닫힌 방향으로 실패).
 *
 * ★ 이 파일에 토큰·로그인·OAuth 코드가 **한 줄도 없다** (사용자 결정 2026-09-15
 *   「OAuth 는 빼자 — 「링크가 있는 모든 사용자」로 공개된 링크만 지원한다」).
 *   로그인이 필요한 문서는 가져오지 않고 `needs_login` 으로 되돌려, 사람이 공유 설정을
 *   바꾸거나 직접 내려받아 올리게 한다.
 */
final class CloudLink
{
    /** 구글 파일 ID 로 인정하는 글자와 길이 (base64url) */
    private const ID_RE = '/^[A-Za-z0-9_-]{10,200}$/';

    /** 구글 문서 3종 → 내려받을 형식 */
    private const DOC_EXPORT = [
        'document'     => 'docx',
        'spreadsheets' => 'xlsx',
        'presentation' => 'pptx',
    ];

    /** 공급자 이름 (감사 로그 detail.provider 값) */
    public const PROVIDER_GOOGLE    = 'google';
    public const PROVIDER_MICROSOFT = 'microsoft';

    /**
     * 공개 링크 → 내려받기 주소.
     *
     * 실패하지 않는다. 무엇을 모르겠으면 **원 주소를 그대로** 돌려준다(변환 안 함).
     *
     * @return array{url:string,provider:?string,needs_login:bool,note:string}
     */
    public static function normalize(string $url): array
    {
        $plain = [
            'url'         => $url,
            'provider'    => null,
            'needs_login' => false,
            'note'        => '',
        ];

        // 제어문자·공백이 섞인 주소는 파싱 결과가 구현마다 다르다 — 손대지 않고 넘긴다.
        // (Fetch::check 가 같은 이유로 bad_request 로 거부한다.)
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return $plain;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return $plain;
        }
        $host  = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path  = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        // ─── 마이크로소프트: 회사(SharePoint) 문서는 공개 링크가 아니다 ───
        // 테넌트 이름이 앞에 붙으므로 정확 일치가 불가능하다. 대신 **호스트 전체를 묶는**
        // 정규식으로 좁게 본다 — 점이 더 들어간 이름(`a.b.sharepoint.com.evil.tld`)은 맞지 않는다.
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}(-my|-admin)?\.sharepoint\.com$/', $host) === 1) {
            return [
                'url'         => $url,
                'provider'    => self::PROVIDER_MICROSOFT,
                'needs_login' => true,
                'note'        => 'sharepoint',
            ];
        }

        if ($host === 'onedrive.live.com') {
            return [
                'url'         => self::withDownloadFlag($url),
                'provider'    => self::PROVIDER_MICROSOFT,
                'needs_login' => false,
                'note'        => 'onedrive',
            ];
        }

        if ($host === '1drv.ms') {
            // 짧은 주소는 onedrive.live.com 으로 넘긴다(302). download=1 은 그 단계에서 붙인다.
            return [
                'url'         => $url,
                'provider'    => self::PROVIDER_MICROSOFT,
                'needs_login' => false,
                'note'        => '1drv',
            ];
        }

        // ─── 구글 드라이브 ───
        if ($host === 'drive.google.com') {
            // /file/d/<ID>/view · /file/d/<ID>/edit · /file/d/<ID>
            if (preg_match('#^/file/d/([^/]+)(?:/.*)?$#', $path, $m) === 1
                && preg_match(self::ID_RE, $m[1]) === 1
            ) {
                return [
                    'url'         => 'https://drive.google.com/uc?export=download&id=' . $m[1],
                    'provider'    => self::PROVIDER_GOOGLE,
                    'needs_login' => false,
                    'note'        => 'drive_file',
                ];
            }
            // /open?id=<ID>
            if ($path === '/open') {
                $id = self::queryValue($query, 'id');
                if ($id !== null && preg_match(self::ID_RE, $id) === 1) {
                    return [
                        'url'         => 'https://drive.google.com/uc?export=download&id=' . $id,
                        'provider'    => self::PROVIDER_GOOGLE,
                        'needs_login' => false,
                        'note'        => 'drive_open',
                    ];
                }
            }
            return $plain;
        }

        // ─── 구글 문서·시트·슬라이드 → 오피스 형식으로 내보내기 ───
        if ($host === 'docs.google.com') {
            if (preg_match('#^/([a-z]+)/d/([^/]+)(?:/.*)?$#', $path, $m) === 1
                && array_key_exists($m[1], self::DOC_EXPORT)
                && preg_match(self::ID_RE, $m[2]) === 1
            ) {
                return [
                    'url'         => 'https://docs.google.com/' . $m[1] . '/d/' . $m[2]
                                     . '/export?format=' . self::DOC_EXPORT[$m[1]],
                    'provider'    => self::PROVIDER_GOOGLE,
                    'needs_login' => false,
                    'note'        => 'docs_' . $m[1],
                ];
            }
            return $plain;
        }

        return $plain;
    }

    /**
     * 리다이렉트 **다음 단계**의 주소를 손봐야 하는가 (Fetch 의 리다이렉트 루프가 부른다).
     *
     * 지금은 한 가지뿐이다: `1drv.ms` 의 짧은 주소는 `onedrive.live.com` 으로 넘어가는데,
     * 그 주소에 `download=1` 이 없으면 파일이 아니라 **보기 화면(HTML)** 이 온다.
     * 그래서 마이크로소프트 링크를 따라가는 중이고 넘어간 곳이 `onedrive.live.com` 이면
     * 그때 깃발을 붙인다.
     *
     * ★ 이 함수는 주소를 **고치기만** 한다. 고친 주소는 호출자가 `Fetch::check()` 를
     *   **처음부터 다시** 통과시켜야 다음 단계로 간다 — 검사를 건너뛰는 자리가 아니다.
     * ★ 호스트를 바꾸지 않는다(질의 문자열만 더한다). 그래서 이 함수가 "허용되지 않은 곳으로
     *   보내는" 일은 원리적으로 일어나지 않는다.
     */
    public static function followUp(string $url, ?string $provider): string
    {
        if ($provider !== self::PROVIDER_MICROSOFT) {
            return $url;
        }
        $host = strtolower(rtrim((string) (parse_url($url, PHP_URL_HOST) ?? ''), '.'));
        if ($host !== 'onedrive.live.com') {
            return $url;
        }
        return self::withDownloadFlag($url);
    }

    /** `download=1` 이 없으면 더한다 (이미 있으면 그대로). 호스트·경로는 건드리지 않는다. */
    private static function withDownloadFlag(string $url): string
    {
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if (self::queryValue($query, 'download') !== null) {
            return $url;
        }
        // 프래그먼트(#…)는 서버로 가지 않으므로 떼고 붙인다.
        $hash = strpos($url, '#');
        $base = $hash === false ? $url : substr($url, 0, $hash);
        return $base . ($query === '' ? '?' : '&') . 'download=1';
    }

    /**
     * 질의 문자열에서 키 하나의 값을 읽는다. 없으면 null.
     * `parse_str` 을 쓰지 않는다 — 그 함수는 `a[b]` 같은 표기를 배열로 바꾸고 변수 이름을
     * 만들어 내는 성질이 있어, 여기서 필요한 "값 하나 읽기" 보다 하는 일이 많다.
     */
    private static function queryValue(string $query, string $key): ?string
    {
        if ($query === '') {
            return null;
        }
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq   = strpos($pair, '=');
            $name = $eq === false ? $pair : substr($pair, 0, $eq);
            if (rawurldecode($name) !== $key) {
                continue;
            }
            return $eq === false ? '' : rawurldecode(substr($pair, $eq + 1));
        }
        return null;
    }
}
