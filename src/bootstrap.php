<?php
declare(strict_types=1);

/**
 * src/bootstrap.php — 모든 요청의 [0] 단계 (ARCHITECTURE 4장)
 *
 * 하는 일 네 가지
 *   1) 오류를 화면에 절대 내보내지 않는다 (PRD S-09-7). 상세는 error_log 로만.
 *   2) 저장소 루트 .env 를 읽어 DB 에 연결한다 (PRD S-09-6). 값이 없으면 부팅 실패 — 조용한 기본값은 없다.
 *   3) 요청 하나를 식별하는 request_id(UUIDv4) 를 만든다 (PRD S-07: 감사 로그 ↔ 서버 로그 연결).
 *   4) 보안 헤더를 출력한다 (PRD S-09-9). nginx 와 이중으로 나가도 무해하다 —
 *      nginx 설정이 실수로 빠져도 앱이 스스로 붙이는 편이 안전하다.
 *
 * 오토로더(spl_autoload_register)를 쓰지 않는다. 클래스 이름이 파일 경로로 변환되는 통로가
 * 존재하지 않으므로, 클래스 이름을 통한 임의 파일 로드가 원리적으로 불가능하다.
 * 대신 아래 require 목록에 적힌 파일만 로드된다.
 */

// ─── 1) 오류 표시 차단 (PRD S-09-7) ───────────────────────────
// php-fpm 풀(deploy/php-fpm-pool.conf)의 php_admin_value 로도 강제되지만,
// CLI 실행·설정 누락 상황까지 덮기 위해 코드에서도 한 번 더 끈다.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ─── 2) 클래스 로드 (명시 목록) ────────────────────────────────
require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Authz.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Upload.php';
require_once __DIR__ . '/Preview.php';  // F-18 미리보기 추출 (12-2, ARCH 5.8)
require_once __DIR__ . '/Fetch.php';   // F-14 외부 요청 (7단계, PRD S-05 ⑬)
require_once __DIR__ . '/Totp.php';    // 2단계 인증 TOTP (10단계, SECURITY R-05)
require_once __DIR__ . '/Qr.php';      // 2단계 인증 등록 QR (F-51, ARCH 5.25)

/**
 * 요청 식별자 (UUIDv4). random_bytes 로 만든다 — 예측 가능한 값이면
 * 로그를 골라 지우거나 끼워 넣는 데 쓰일 수 있다.
 */
function tbox_uuid_v4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);   // version 4
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);   // variant RFC 4122
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
         . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * 5-A″ — 계정 **이름**(`users.display_name`)으로 받은 값을 좁힌다. 계정 만들기·바꾸기 두 창구가
 * 이 함수 하나를 쓴다(규칙이 두 벌이 되지 않게).
 *
 * 돌려주는 것: `[true, 이름]` · `[true, null]`(비우기 — null 이나 공백뿐인 글자) · `[false, null]`(거부).
 *   · 문자열이 아니거나 UTF-8 이 아니면 거부
 *   · 앞뒤 공백(유니코드 공백 포함)은 **지운다**
 *   · 제어 문자(`\p{Cc}`)·**서식 문자**(`\p{Cf}` — 방향 바꾸기 U+202E·폭 없는 공백 U+200B 같은 것)·
 *     줄·문단 구분(`\p{Zl}`·`\p{Zp}`)이 하나라도 있으면 거부. 서식 문자를 막는 이유: 「이름 (아이디)」
 *     로 나란히 보일 때 방향 바꾸기 글자 하나로 **옆의 아이디를 거꾸로 보이게** 할 수 있다.
 *   · 지운 뒤 1~40자(코드 포인트)가 아니면 거부
 * DB CHECK `users_display_name_shape` 이 같은 모양을 한 번 더 막는다(뒷받침).
 *
 * @param mixed $raw
 * @return array{0:bool,1:?string}
 */
function tbox_clean_display_name($raw): array
{
    if ($raw === null) {
        return [true, null];
    }
    if (!is_string($raw) || preg_match('//u', $raw) !== 1) {
        return [false, null];
    }
    $v = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw);
    if (!is_string($v)) {
        return [false, null];
    }
    if ($v === '') {
        return [true, null];
    }
    if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $v) === 1) {
        return [false, null];
    }
    $len = iconv_strlen($v, 'UTF-8');
    if ($len === false || $len < 1 || $len > 40) {
        return [false, null];
    }
    return [true, $v];
}

/**
 * 5-A″ — 사람을 보이는 모양 **한 벌**: 「이름 (아이디)」, 이름이 없으면 「아이디」.
 *
 * 서버가 그리는 화면(셸의 사용자 칸·앱 메뉴·런처·「내 계정」)만 이 함수를 쓴다. **창구(JSON)는
 * 이 문자열을 주지 않는다** — `display_name` 과 `username` 을 따로 주고 화면 스크립트가 같은 모양을
 * `personLabel()`(app.js) 한 함수로 만든다. 돌려주는 값은 **이스케이프 전 글자**다 — 부르는 쪽이
 * `htmlspecialchars` 를 거친다(PRD S-03).
 */
function tbox_person_label(?string $displayName, string $username): string
{
    $name = $displayName === null ? '' : trim($displayName);
    return $name === '' ? $username : $name . ' (' . $username . ')';
}

if (!defined('TBOX_REQUEST_ID')) {
    define('TBOX_REQUEST_ID', tbox_uuid_v4());
}

/**
 * t-box 모듈의 주소 접두 (Qaredi C — ../docs/launcher/ARCHITECTURE.md 2장).
 *
 * `/` 에는 Qaredi 런처가 서고, t-box 의 모든 주소(화면·API·공유 링크·정적 자산)는
 * 이 접두 아래로 간다. **코드 상수**이며 `.env` 값이 아니다 — 로컬(8082)과 공개(80)가
 * 같은 주소 구조여야 시험이 곧 운영 검증이 된다. 요청 값이 섞이는 통로는 없다.
 */
const TBOX_BASE = '/tbox';

/**
 * 커뮤니티 모듈의 주소 접두 (커뮤니티 ARCHITECTURE.md 21.1).
 *
 * `/community` 아래가 커뮤니티 모듈이다. `TBOX_BASE` 와 같은 성격의 **코드 상수**이며
 * `.env` 값이 아니다 — 로컬(8082)과 공개(80)가 같은 주소 구조여야 시험이 곧 운영 검증이 된다.
 * 요청 값이 섞이는 통로는 없다. 주소를 만드는 자리는 `Http::communityUrl()` 하나다.
 */
const COMMUNITY_BASE = '/community';

/**
 * 지금 열려 있는 문서 블록의 단계 (커뮤니티 ARCHITECTURE.md 12.2 `stage` 표).
 *
 * **코드 상수 4** 이다(4-A 에서 3 → 4) — `.env` 로 빼지 않는다. 이 값이 설정으로 움직이면
 * 「이 서버에서만 저장되는 블록」이 생기고, 저장된 문서를 다른 서버가 못 읽게 된다. 단계를
 * 올리는 것은 마이그레이션·화면·에디터가 함께 바뀌는 일이라 코드 변경이 맞다.
 *
 * 4단계가 여는 블록은 `child_page` **하나**다(`community/doc-schema.json` 의 `stage` 표).
 * `toggle`·`table` 은 5단계로 옮겼다 — 단계 표가 곧 활성 목록이어야 하므로, 화면·에디터가
 * 아직 그리지 못하는 블록을 단계 안에 두지 않는다.
 * 5-E(2026-09-23)에 **5** 로 올렸다 — `toggle`·`table`·`image`·`file`·`link_to_page` 다섯이 열린다.
 * `column_list`·`column`·`mention` 마크는 같은 까닭으로 6단계로 옮겼다(편집기 중첩 구조가 먼저 — 결정 E-1).
 * 6-C(2026-09-23)에 **6** 으로 올렸다 — `column_list`·`column`(단 나누기)과 표 칸 병합이 열린다.
 * `mention` 마크는 렌더러·검증기가 아직 그리지 못하므로 **7단계로** 옮겼다(결정 I-5, 좁은 쪽).
 */
const COMMUNITY_DOC_STAGE = 6;

// ─── 3) 예외·치명적 오류 은닉 (PRD S-09-7, SECURITY 1.7) ───────
// 어떤 예외도 스택 트레이스·DB 오류 문구·파일 경로를 응답에 넣지 않는다.
// 응답은 언제나 500 + {"error":"서버 오류"} 한 가지다.
set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[tbox] request_id=%s uncaught %s: %s @ %s:%d',
        TBOX_REQUEST_ID,
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    try {
        // Db::ready() — 부팅에서 연결이 실패했으면 부르지 않는다 (F-48, ARCH 5.24).
        // 없는 연결에 rollback 을 걸어 로그에 「rollback failed」 잡음을 남기지 않기 위해서다.
        if (class_exists('Db', false) && Db::ready()) {
            Db::instance()->rollback();
        }
    } catch (Throwable $ignored) {
        // 롤백 실패는 로그만 남기고 응답 형태를 바꾸지 않는다
        error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' rollback failed');
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '{"error":"서버 오류"}';
});

// PHP 경고·통지도 예외로 바꿔 위 핸들러가 잡게 한다
// (경고 문구가 본문에 섞여 나가는 경로를 없앤다)
set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
    if ((error_reporting() & $no) === 0) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

// 메모리 초과 같은 치명적 오류는 위 두 핸들러로 잡히지 않는다 — 마지막 그물
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if ((($err['type'] ?? 0) & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) === 0) {
        return;
    }
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' fatal: ' . ($err['message'] ?? ''));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"error":"서버 오류"}';
    }
});

// ─── 4) 보안 헤더 (PRD S-09-9, ARCH 8장) ───────────────────────
// HSTS 는 넣지 않는다 — 같은 호스트 80 포트에 옛 t-box(공격 실습 대상)가 있어
// HSTS 가 그 접근을 막아 버린다. 운영 전환 시 필수 (ARCH 8장 주석, SECURITY 6장).
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

// ─── 5) 설정 로드 + DB 연결 ────────────────────────────────────
// 설정 파일은 저장소 루트 .env 한 곳뿐이다 (PRD S-09-6. ARCH 3장).
// 왜 여기인가
//   · public/ 밖이라 URL 로 보이지 않고, .gitignore 로 Git 에도 들어가지 않는다.
//   · 소유가 changkwon:tbox-php 0640 이므로 웹 앱(tbox-php)은 **읽기만** 할 수 있다 —
//     앱이 스스로 자기 설정(DB 계정·APP_SECRET)을 고쳐 쓸 수 없다. 수정은 소유자·sudo 로만.
// 경로는 코드 상수다. 환경변수·요청 값으로 바꿀 수 있게 하지 않는다(설정 파일 바꿔치기 방지).
// (운영 전환 시 /etc/tbox/tbox.env 로 이전 검토 — 심층 방어. PRD S-09-6)
Env::load(dirname(__DIR__) . '/.env');

Db::boot(
    Env::get('DB_HOST'),
    Env::get('DB_PORT'),
    Env::get('DB_NAME'),
    Env::get('DB_USER'),
    Env::get('DB_PASS')
);
