<?php
declare(strict_types=1);

/**
 * scripts/lib/alerts_lib.php — 경보 배치(scripts/alerts.php)의 판정 조각 가운데 **순수 함수**만 (디버깅 묶음 S, 2026-09-26)
 *
 * 왜 따로 두나: alerts.php 는 require 하는 순간 DB 에 붙어 규칙을 돌리는 스크립트라, 시각·명령에 따른 판정만
 * 떼어 시험할 수가 없었다. 여기 함수는 **DB·파일·네트워크에 닿지 않고**(인자와 PHP 내장 함수만) 부작용이 없다 —
 * `php -r 'require "scripts/lib/alerts_lib.php"; var_dump(al_is_business_hours(…));'` 로 시험한다
 * (tests/dbg_s_security.sh S-3·S-4·S-9). 운영 동작은 alerts.php 가 이 함수를 부르는 것 말고는 같다.
 *
 *   al_is_business_hours   S-4 — AL-7 의 「업무시간」 = **월~금** 09:00~18:00 (서버 로컬 시각 — Asia/Seoul)
 *   al_sudo_command_key    S-3 — AL-9 중복 억제 키에 넣는 명령 요약(첫 낱말 + 가린 명령 sha256 앞 12자)
 *   al_state_time          S-9 — 상태 파일의 시각 한 칸을 믿어도 되나(읽을 수 있고 **미래가 아닌가**)
 *
 * 이 파일은 cli_bootstrap.php 를 싣지 않는다(설정·DB 없이 시험되게). 부르는 쪽(alerts.php)이 먼저 싣는다.
 */

/** S-9 — 상태 파일 시각이 「지금」보다 이만큼(초)까지 앞서는 것은 시계 차이로 보고 받아 준다. 그 너머는 무시한다 */
const AL_STATE_FUTURE_SKEW_SECONDS = 300;

/**
 * S-4 — AL-7(감사 로그 30분 0건)을 판정하는 「업무시간」인가.
 *
 * **월~금 09:00~18:00**(18시 정각부터는 아니다). 요일은 ISO-8601 `N`(1=월 … 7=일)로 본다.
 * 전에는 시각만 봐서 토·일 낮에도 판정했고, 사람이 없는 주말에는 30분 0건이 당연해 경보가 되풀이됐다
 * (2026-09-26 토요일 휴대폰 알림 4번 — 보안 점검 중간). 공휴일은 모른다(범위 밖 — ARCH 11.4 한계).
 * 시각대는 PHP 의 기본 시간대(운영 Asia/Seoul — README 「서버 시간대」)를 그대로 쓴다.
 */
function al_is_business_hours(int $ts): bool
{
    $dow  = (int) date('N', $ts);
    $hour = (int) date('G', $ts);
    return $dow <= 5 && $hour >= 9 && $hour < 18;
}

/**
 * S-4 — 업무시간이 아닐 때 cli_say 에 붙일 까닭 한 마디(「주말」 또는 「업무시간 아님」).
 */
function al_off_hours_reason(int $ts): string
{
    return (int) date('N', $ts) >= 6 ? '주말' : '업무시간 아님';
}

/**
 * S-3 — AL-9(위험한 sudo) 중복 억제 키에 붙이는 **명령 요약**.
 *
 * 전에는 키가 `AL-9|계정` 이라, 가벼운 `sudo psql` 한 번이 울리면 30분 동안 같은 계정의 `pg_dump`·`rm -rf` 가
 * alerts.log·감사에 한 줄도 남지 않았다(2026-09-26 보안 점검 중간 3). 지금은 `AL-9|계정|<요약>` 이다 —
 * **다른 명령은 각각 한 줄**, 같은 명령을 되풀이한 것만 30분 억제한다.
 *
 * 요약 = 첫 낱말의 basename(영숫자·`._+-` 만, 32자) + `#` + 명령 sha256 앞 12자.
 * 넣는 명령은 **비밀값 조각만 가리고 자르지 않은 전체**(al_scrub_secrets)다 — 비밀번호만 다른 같은 명령은 같은 키가 되고,
 * 상태 파일(0600 root)에 비밀값의 해시가 남지 않는다. 검토 L-4: 전에는 앞 200자로 자른 문자열을 해시해, 앞 200자가 같은
 * 서로 다른 긴 명령이 한 키로 묶였다(근거에 남는 글자는 여전히 앞 200자 — al_scrub_command).
 */
function al_sudo_command_key(string $secretsScrubbedCommand): string
{
    $parts = preg_split('/\s+/', ltrim($secretsScrubbedCommand), 2);
    $first = is_array($parts) && isset($parts[0]) ? (string) $parts[0] : '';
    $base  = basename(str_replace('\\', '/', $first));
    $base  = (string) preg_replace('/[^A-Za-z0-9._+-]/', '', $base);
    if (strlen($base) > 32) {
        $base = substr($base, 0, 32);
    }
    return ($base === '' ? '?' : $base) . '#' . substr(hash('sha256', $secretsScrubbedCommand), 0, 12);
}

/**
 * sudo 명령에서 **비밀값 조각만** 가린다(자르지 않는다) — alerts.php 의 al_scrub_command(앞 200자로 자름)와 AL-9 명령 요약이
 * 함께 쓰는 한 벌. 가리는 형태: `PGPASSWORD=…`·`password=…`(환경변수·인자) · `PASSWORD '…'`·`PASSWORD "…"`(SQL). 잘못된 UTF-8 은 버린다.
 */
function al_scrub_secrets(string $cmd): string
{
    $clean = (string) preg_replace('/(?:PGPASSWORD|password)=\S+/i', '[제거됨]', $cmd);
    $clean = (string) preg_replace('/\bPASSWORD\s+\'[^\']*\'/i', 'PASSWORD [제거됨]', $clean);
    $clean = (string) preg_replace('/\bPASSWORD\s+"[^"]*"/i', 'PASSWORD [제거됨]', $clean);
    $utf8  = iconv('UTF-8', 'UTF-8//IGNORE', $clean);
    return is_string($utf8) ? $utf8 : '';
}

/**
 * S-9 — 상태 파일의 시각 한 칸(ISO 문자열)을 믿어도 되면 그 유닉스 시각, 아니면 null.
 *
 * 경보 상태 파일은 tbox-php 소유 폴더(`/var/lib/tbox-v2`, 0750)에 있다. 그 폴더에 쓸 수 있는 누군가가 억제 시각을
 * **먼 미래**로 적어 두면 `(지금 - 그 시각) < 30분` 이 영원히 참이라 그 규칙·대상의 경보가 영영 꺼지고, 7일 정리
 * 규칙에도 걸리지 않았다(보안 점검 중간 4). 그래서 지금보다 AL_STATE_FUTURE_SKEW_SECONDS(5분) 넘게 앞선 시각은
 * **없는 것으로 본다**(부르는 쪽이 그 칸을 버리고 경고 한 줄을 남긴다). 문자열이 아니거나 읽을 수 없는 시각도 null.
 */
function al_state_time(mixed $value, int $now): ?int
{
    if (!is_string($value) || $value === '') {
        return null;
    }
    $t = strtotime($value);
    if ($t === false) {
        return null;
    }
    if ($t > $now + AL_STATE_FUTURE_SKEW_SECONDS) {
        return null;
    }
    return $t;
}
