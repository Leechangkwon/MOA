<?php
declare(strict_types=1);

/**
 * src/handlers/push_manifest.php — GET /alerts-app.webmanifest (정책: role:Admin + 재인증, PRD F-70 · 구현 기록 1)
 *
 * 「Qaredi 경보」 설치형 웹 앱의 매니페스트(홈 화면에 추가). 값은 전부 코드 상수이고 요청 값이 섞이지 않는다.
 *   start_url · scope = `/tbox/alerts-app`(지시서 D) · display standalone · 아이콘 PNG 192·512(아이폰은 PNG 필요)
 *   아이콘 주소에는 셸처럼 `?v=<파일 시각>` 을 붙인다 — `/tbox/assets/` 는 버전 붙은 주소에 1년 캐시다(U-4′).
 * `Content-Type: application/manifest+json` · `Cache-Control: no-cache`. 보안 머리는 bootstrap 이 붙인다.
 * 화면(push_app_page.php)은 `<link rel="manifest" … crossorigin="use-credentials">` 로 부른다 — 매니페스트 요청은
 * 기본이 쿠키 없이 가는데, 이 라우트는 로그인·재인증 뒤에만 열리기 때문이다.
 * 색값(theme_color·background_color)은 넣지 않는다 — 화면 규칙(새 색값 0). 브라우저 기본을 쓴다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$assetDir = __DIR__ . '/../../public/assets/';
$icon = static function (string $file, string $size) use ($assetDir): array {
    $version = is_file($assetDir . $file) ? (int) filemtime($assetDir . $file) : 0;
    return [
        'src'     => Http::url('/assets/' . $file) . '?v=' . $version,
        'sizes'   => $size,
        'type'    => 'image/png',
        'purpose' => 'any',
    ];
};

$manifest = [
    'id'          => Http::url('/alerts-app'),
    'name'        => '모아 관리자 경보',
    'short_name'  => '모아 경보',
    'description' => '관리자 휴대폰으로 모아 경보를 받습니다',
    'lang'        => 'ko',
    'dir'         => 'ltr',
    'start_url'   => Http::url('/alerts-app'),
    'scope'       => Http::url('/alerts-app'),
    'display'     => 'standalone',
    'icons'       => [
        $icon('alerts-icon-192.png', '192x192'),
        $icon('alerts-icon-512.png', '512x512'),
    ],
];

if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: application/manifest+json');
    header('Cache-Control: no-cache');
}
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
