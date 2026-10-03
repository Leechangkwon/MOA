<?php
declare(strict_types=1);

/**
 * src/handlers/site_manifest.php — GET /app.webmanifest (정책: public) · T:F-72 라운드 C · 결정 PK-D5 (사용자 d71 「앱 + 즐겨찾기로 접근」)
 *
 * 홈 화면 「Qaredi」 앱(모든 사람)의 매니페스트. **데이터 0** — 값은 전부 코드 상수이고 요청 값·계정 정보가 섞이지 않는다.
 *   시작 `/community/`(커뮤니티 「내 공간」 — 좁은 폭 첫 화면에 즐겨찾기가 먼저 보인다) · 범위 `/`(로그인 화면 `/tbox/login` 이
 *   범위 안이어야 지문 창이 앱 안에서 뜬다) · `standalone` · 아이콘 PNG 192·512(`?v=<파일 시각>` — 경보 앱 매니페스트와 같은 방식).
 * **바로가기(shortcuts) 없음**(PK-D5) · **서비스 워커 없음**(이 앱은 알림을 받지 않는다 — 경보는 「Qaredi 경보」 앱) · 색값 없음(새 색값 0).
 * 로그인 화면·런처·셸이 `<link rel="manifest">` 로 부른다(쿠키 없이 받아도 같은 답 — public). 보안 머리는 bootstrap 이 붙인다.
 *
 * @var array<string,mixed>|null $user
 * @var list<string>             $params
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
    'id'          => Http::communityUrl('/'),
    'name'        => '모아',
    'short_name'  => '모아',
    'description' => '모아 — 우리의 이야기와 함께하는 공간',
    'lang'        => 'ko',
    'dir'         => 'ltr',
    'start_url'   => Http::communityUrl('/'),
    'scope'       => '/',
    'display'     => 'standalone',
    'icons'       => [
        $icon('qaredi-icon-192.png', '192x192'),
        $icon('qaredi-icon-512.png', '512x512'),
    ],
];

if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: application/manifest+json');
    header('Cache-Control: no-cache');
}
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
