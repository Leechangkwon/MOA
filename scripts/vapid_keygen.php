<?php
declare(strict_types=1);

/**
 * scripts/vapid_keygen.php — VAPID(웹 푸시 서명) P-256 키 한 쌍 만들기 · 짝 확인 (PRD F-70 · 지시서 B · F장 1, 구현 기록 2)
 *
 * 사용
 *   php scripts/vapid_keygen.php --private-out <경로> --public-out <경로>
 *       새 키 한 쌍. 비밀 키는 PEM(PKCS#8)으로 **0600** 파일에, 공개 키는 base64url 한 줄(65바이트 점)로 0644 파일에 쓴다.
 *       두 경로 중 하나라도 이미 있으면 **쓰지 않고 멈춘다**(키를 바꾸면 모든 기기를 다시 등록해야 한다 — 덮어쓰기 0).
 *   php scripts/vapid_keygen.php --check --private <경로> --public <경로>
 *       두 파일이 한 쌍인지 본다(쓰기 0). 출력은 「짝 맞음」과 공개 키 sha256 앞 16자뿐이다.
 *
 * ★ 비밀 키는 표준출력·표준오류·명령 인자 어디에도 나가지 않는다 — 파일에만 쓴다(umask 077 · 배타적 만들기 `x`).
 *   출력하는 것은 경로·공개 키 지문(비밀 아님)뿐이다.
 * ★ 운영 반영(review-scripts/apply-f70-host-2026-09-24.sh)은 이 스크립트를 root 로 부르지 **않는다** — 저장소의 PHP 를
 *   root 로 돌리지 않으려고 키는 반영 스크립트 안에서 시스템 openssl 로 만들고(umask 077 · 임시 폴더 0700), 짝 확인만
 *   이 스크립트의 `--check` 를 보내는 계정(tbox-push)으로 돌린다(구현 기록 2). 이 스크립트의 만들기 쪽은 새로 설치하는
 *   서버·시험(임시 폴더)용이다.
 * ★ 네트워크 0 · DB 0 · `.env` 0 (src/WebPush.php 하나만 싣는다).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/src/WebPush.php';

$argv = $_SERVER['argv'];
$opt = static function (string $name) use ($argv): ?string {
    $n = count($argv);
    for ($i = 1; $i < $n; $i++) {
        if ($argv[$i] === '--' . $name) {
            return $argv[$i + 1] ?? null;
        }
    }
    return null;
};
$usage = static function (): never {
    fwrite(STDERR, "사용법: php scripts/vapid_keygen.php --private-out <경로> --public-out <경로>\n"
        . "        php scripts/vapid_keygen.php --check --private <경로> --public <경로>\n");
    exit(3);
};
$absOk = static fn(?string $p): bool => is_string($p) && $p !== '' && $p[0] === '/' && strpos($p, '..') === false
    && preg_match('/[\x00-\x1f]/', $p) !== 1;

// ─── 짝 확인 ────────────────────────────────────────────────────
if (in_array('--check', $argv, true)) {
    $priv = $opt('private');
    $pub  = $opt('public');
    if (!$absOk($priv) || !$absOk($pub)) {
        $usage();
    }
    try {
        $key = WebPush::loadPrivateKey((string) $priv);
        $raw = WebPush::rawPublicOf($key);
    } catch (WebPushError $e) {
        fwrite(STDERR, "✖ 비밀 키를 읽지 못했다 (없음·권한·모양)\n");
        exit(1);
    }
    $file = WebPush::readPublicKeyFile((string) $pub);
    if ($file === null) {
        fwrite(STDERR, "✖ 공개 키 파일을 읽지 못했거나 65바이트 P-256 점이 아니다\n");
        exit(1);
    }
    if ($file !== WebPush::b64uEncode($raw)) {
        fwrite(STDERR, "✖ 두 파일이 한 쌍이 아니다\n");
        exit(1);
    }
    fwrite(STDOUT, '짝 맞음 · 공개 키 sha256 ' . substr(hash('sha256', $raw), 0, 16) . "\n");
    exit(0);
}

// ─── 만들기 ─────────────────────────────────────────────────────
$privOut = $opt('private-out');
$pubOut  = $opt('public-out');
if (!$absOk($privOut) || !$absOk($pubOut) || $privOut === $pubOut) {
    $usage();
}
if (file_exists((string) $privOut) || file_exists((string) $pubOut)) {
    fwrite(STDERR, "✖ 키 파일이 이미 있다 — 덮어쓰지 않는다(키를 바꾸면 모든 기기를 다시 등록해야 한다)\n");
    exit(1);
}
if (!is_dir(dirname((string) $privOut)) || !is_dir(dirname((string) $pubOut))) {
    fwrite(STDERR, "✖ 쓸 폴더가 없다\n");
    exit(1);
}

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($key === false) {
    fwrite(STDERR, "✖ 키를 만들지 못했다(openssl)\n");
    exit(1);
}
$pem = '';
if (!openssl_pkey_export($key, $pem)) {
    fwrite(STDERR, "✖ 비밀 키를 PEM 으로 옮기지 못했다\n");
    exit(1);
}
$raw = WebPush::rawPublicOf($key);

$oldMask = umask(0077);
$fh = fopen((string) $privOut, 'x');   // 배타적 만들기 — 있으면 실패(위 검사와 경쟁하지 않게 한 번 더)
if ($fh === false || fwrite($fh, $pem) !== strlen($pem) || !fclose($fh)) {
    umask($oldMask);
    fwrite(STDERR, "✖ 비밀 키 파일을 쓰지 못했다\n");
    exit(1);
}
chmod((string) $privOut, 0600);
$pem = str_repeat("\0", strlen($pem));
unset($pem);
umask(0022);
$line = WebPush::b64uEncode($raw) . "\n";
$fh = fopen((string) $pubOut, 'x');
if ($fh === false || fwrite($fh, $line) !== strlen($line) || !fclose($fh)) {
    umask($oldMask);
    fwrite(STDERR, "✖ 공개 키 파일을 쓰지 못했다\n");
    exit(1);
}
chmod((string) $pubOut, 0644);
umask($oldMask);

fwrite(STDOUT, "만듦 — 비밀 키 0600 · 공개 키 0644 · 공개 키 sha256 " . substr(hash('sha256', $raw), 0, 16) . "\n");
exit(0);
