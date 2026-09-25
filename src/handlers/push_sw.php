<?php
declare(strict_types=1);

/**
 * src/handlers/push_sw.php — GET /alerts-sw.js (정책: role:Admin + 재인증, PRD F-70 · 구현 기록 1)
 *
 * 「Qaredi 경보」 서비스 워커를 낸다. 원본은 public/ 밖의 `src/push/alerts-sw.js` 이고, 이 라우트로만 나간다 —
 *   · 주소가 `/tbox/alerts-sw.js` 라 등록 범위 `/tbox/alerts-app` 가 Service-Worker-Allowed 머리 없이 된다(스크립트
 *     자리의 폴더 `/tbox/` 아래) → **nginx 설정 변경 0**(지시서의 deploy/sites location 대신 — 구현 기록 1)
 *   · 보안 머리(CSP · nosniff · X-Frame-Options · Referrer-Policy)는 bootstrap 이 다른 PHP 응답과 **똑같이** 붙인다
 *   · `Content-Type: application/javascript; charset=utf-8` · `Cache-Control: no-cache`(브라우저가 매번 새 판인지 묻는다)
 * 원본에서 바꾸는 글자는 아이콘 버전 자리 하나(`__ICON_VERSION__` → 파일 시각 정수)뿐이다. 요청 값이 한 글자도 섞이지 않는다.
 *
 * ★ 역할 판단·데이터 조회 없음 · 외부 요청 0 · 감사 없음(코드 파일 읽기).
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params
 */

$swFile   = __DIR__ . '/../push/alerts-sw.js';
$iconFile = __DIR__ . '/../../public/assets/alerts-icon-192.png';

$js = is_file($swFile) ? file_get_contents($swFile, false, null, 0, 65536) : false;
if (!is_string($js) || $js === '') {
    Http::error(404, Http::MSG_NOT_FOUND);
}
$iconVersion = is_file($iconFile) ? (int) filemtime($iconFile) : 0;
$js = str_replace('__ICON_VERSION__', (string) $iconVersion, $js);

if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
}
echo $js;
