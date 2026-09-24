<?php
declare(strict_types=1);

/**
 * scripts/totp_code.php — 지금 이 순간의 2단계 인증 코드 1개를 찍는다 (10단계 · SECURITY R-05)
 *
 * 왜 있나
 *   화면은 QR(F-51)과 함께 비밀 키·`otpauth://` 주소를 글자로도 보여 준다.
 *   스마트폰 인증 앱이 없는 사람 — 특히 서버에서 검토·시험하는 관리자 — 은 그 상태로는
 *   "확인하고 켜기" 에 넣을 6자리를 만들 수 없다. 이 명령이 `src/Totp.php` 를 그대로 써서
 *   같은 코드를 한 번 계산해 준다. 앱을 대신하는 **시험용 도구**이지 운영 절차가 아니다.
 *
 * 사용법
 *   sudo php scripts/totp_code.php <base32 비밀>
 *   예) sudo php scripts/totp_code.php JBSWY3DPEHPK3PXP
 *   printf '%s\n' "$sec" | php scripts/totp_code.php -     (인자 `-` = 표준입력에서 비밀 한 줄)
 *     — 시험·스크립트는 이쪽을 쓴다. 명령 인자는 ps·/proc/<pid>/cmdline 으로 같은 호스트의 다른 계정도
 *       읽고, sudo 로 부르면 auth.log 의 COMMAND= 에 남는다(SECURITY R-62 · 점검 봇 R15 · tests/lib/secrets.sh).
 *       읽는 것은 첫 줄 하나뿐이고(최대 256바이트), 끝의 줄바꿈만 뗀다. 나머지 다듬기·검사는 인자 방식과 같다.
 *
 * 종료 코드
 *   0 정상 · 2 사용법·비밀 형식 오류
 *
 * ★ 주의
 *   · 비밀은 **화면에서 복사한 값**이다. 파일·스크립트·셸 히스토리에 남기지 않는다
 *     (`HISTCONTROL=ignorespace` 라면 명령 앞에 공백 한 칸을 두고 실행한다).
 *   · 이 스크립트는 감사 로그를 쓰지 않고 DB 에도 붙지 않는다 — 비밀도 코드도
 *     어디에도 기록하지 않기 위해서다 (PRD 7.7 민감정보 금지). 그래서 웹 부트스트랩
 *     (src/bootstrap.php)·cli_bootstrap 도 쓰지 않고 Totp 한 클래스만 읽어 온다.
 *   · 인증 앱을 쓸 수 있으면 앱을 쓴다. 이 도구는 앱을 못 쓰는 경우의 대안이다.
 *
 * 웹으로는 절대 실행되지 않는다 — public/ 밖에 있고(URL 에 없음), 아래에서 SAPI 도 확인한다.
 */

if (PHP_SAPI !== 'cli') {
    // 웹에서 도달할 경로가 없지만, 만들어지더라도 실행되지 않게 한다 (심층 방어).
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Totp.php';

/** 사용법 안내. 인자를 잘못 줬을 때만 부른다. */
function totp_usage(string $why): void
{
    fwrite(STDERR, '[사용법 오류] ' . $why . "\n");
    fwrite(STDERR, "사용법: sudo php scripts/totp_code.php <base32 비밀>\n");
    fwrite(STDERR, "  예)  sudo php scripts/totp_code.php JBSWY3DPEHPK3PXP\n");
    fwrite(STDERR, "  표준입력으로:  printf '%s\\n' \"\$sec\" | php scripts/totp_code.php -\n");
    fwrite(STDERR, "  비밀은 홈 화면 「내 계정 → 2단계 인증 켜기」 가 보여 준 값입니다.\n");
    fwrite(STDERR, "  주의: 비밀을 파일·셸 히스토리에 남기지 마세요.\n");
}

$args = $_SERVER['argv'] ?? [];
if (count($args) < 2) {
    totp_usage('비밀을 인자로 하나 주세요');
    exit(2);
}
if (count($args) > 2) {
    totp_usage('인자는 비밀 하나뿐입니다');
    exit(2);
}

$raw = (string) $args[1];
if ($raw === '-') {
    // 표준입력에서 비밀 한 줄. 빈 입력·읽기 실패는 형식 오류와 같은 길로 보낸다(비밀 글자를 찍지 않는다).
    $line = fgets(STDIN, 257);
    $raw  = $line === false ? '' : rtrim($line, "\r\n");
}

// 사람이 옮겨 적는 값이라 소문자·공백·하이픈이 섞이기 쉽다. 그것만 다듬고,
// 형태 검사는 Totp 가 쓰는 것과 **같은 정규식**으로 한다 (여기서 규칙을 새로 만들지 않는다).
$secret = strtoupper((string) preg_replace('/[\s-]+/', '', $raw));
if (preg_match(Totp::SECRET_RE, $secret) !== 1) {
    totp_usage('비밀은 base32 대문자(A-Z, 2-7) 16~64자입니다');
    exit(2);
}

$now  = time();
$left = Totp::PERIOD - ($now % Totp::PERIOD);

try {
    $code = Totp::code($secret, $now);
} catch (Throwable $e) {
    // 예외 문구에 비밀이 섞이지 않도록 우리가 적은 한 문장만 낸다.
    fwrite(STDERR, "[실패] 코드를 계산할 수 없습니다 — 비밀 형식을 확인하세요\n");
    exit(2);
}

fwrite(STDOUT, $code . "\n");
fwrite(STDOUT, '남은 시간: ' . $left . "초 (30초마다 바뀝니다)\n");
exit(0);
