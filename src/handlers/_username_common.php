<?php
declare(strict_types=1);

/**
 * src/handlers/_username_common.php — 로그인 아이디 규칙 한 벌 (t-box d74 · PRD F-10 · F-71 ③ · SECURITY R-06)
 *
 * ★ 왜 이 파일이 생겼나 (d74, 2026-09-27 — 사용자 결정 「관리자 계정의 로그인 아이디를 남이 모르는 값으로 바꾼다」)
 *   아이디를 정하는 입구가 둘이 됐다: 계정 만들기(`POST /api/users` — users_create.php)와
 *   관리자 아이디 바꾸기(`PATCH /api/users/{uid}` 의 `username` 칸 — users_update.php).
 *   두 입구가 각자 규칙을 적으면 언젠가 한쪽만 느슨해지고, 느슨한 쪽이 곧 우회 경로다
 *   (`_upload_common.php` 가 생긴 까닭과 같다). 그래서 **형식 · 이미 있음 · 검토 접두 예약** 셋을
 *   이 파일 한 곳에 두고 두 핸들러가 `require_once` 해서 부른다. 감사 행(action)과 응답은
 *   부르는 쪽이 정한다 — 문장 표의 키가 핸들러마다 다르기 때문이다(`user.create …`·`user.update …`).
 *
 *   이 파일은 라우트 표에 없다(이름이 `_` 로 시작한다) — 직접 호출되는 핸들러가 아니다.
 *
 * 규칙 (계정 만들기와 **같다** — 이 파일이 그 규칙의 자리다)
 *   ① 형식   `Auth::USERNAME_RE`(소문자·숫자·`_ . -`, 3~64자 — db/schema.sql CHECK 와 같다).
 *            대문자는 형식 오류다. 그래서 「대소문자만 다른 중복」은 형식 단계에서 400 으로 끝난다
 *            (DB 에도 소문자 이름만 있다 — CHECK `users_username_chars`).
 *   ② 이미 있음 `already_exists` — 그래도 비교는 `lower()` 로 한다(오늘 규칙에서는 결과가 같다. 규칙이 풀리는
 *            날에도 대소문자만 다른 두 계정이 생기지 않게). 마지막 그물은 DB UNIQUE(23505)다.
 *   ③ 검토 접두 `reserved_prefix` — `qa_`·`claude_` 는 검토·시험 계정 전용(F-71 ③). 판정은
 *            `Origin::isReviewAccount()` 한 곳(접두 목록을 두 벌로 두지 않는다). ② **뒤**에 본다 —
 *            이미 있는 아이디(검토 계정 포함)는 전처럼 `already_exists` 다(tests/f68.sh (a)8).
 *
 * 아이디 바꾸기에만 있는 것 (users_update.php 가 부른다)
 *   ④ 검토 계정은 바꾸지 않는다 `review_account` — 지금 이름이 검토 접두면 거부한다. 검토 계정의 이름이
 *            검토 요청과 사람 행동을 가르는 표식이라(감사·경보 origin · 시험 R-73), 이름이 바뀌면 그 표식이 깨진다.
 *   ⑤ 공용 프로그램 토큰에 묶인 계정 `shared_token_user` — 공용 토큰이 켜져 있으면(`.env` `API_TOKEN` 32자 이상 —
 *            Auth::requireApiToken 과 같은 문턱) 그 귀속 아이디(`API_TOKEN_USER`)는 **글자**로 계정을 찾는다
 *            (Auth::requireApiToken ③ — `SQL_LOAD_USER … WHERE username`). 그래서 **지금 이름**이 귀속 아이디면
 *            바꾸는 순간 공용 토큰이 503 으로 끊긴다 → 거부(닫힌 실패).
 *            새 이름이 귀속 아이디인 것은 막지 않는다 — 사용자 결정 d76 ②(2026-09-27 「공용 토큰은 두고 귀속 줄만 새 아이디로」)의 순서가
 *            그 갈래를 쓴다: ① 도우미(`apply-d74-rename-conf` --env)로 `.env` 귀속 줄을 새 아이디로(이 순간부터 공용 토큰 잠시 503) →
 *            ② 이 창구로 아이디 바꾸기(지금 이름 ≠ 귀속 → 통과) → ③ 공용 토큰이 새 아이디의 계정으로 다시 이어진다.
 *            (귀속 줄이 없는 계정을 가리키는 채 누군가를 그 이름으로 바꾸면 공용 토큰이 그 계정에 붙는다 — 계정 만들기와 같은 한계 ·
 *            도우미는 이미 쓰는 아이디로는 귀속 줄을 바꾸지 않는다.)
 *            토큰 값은 길이만 본다(값을 들고 다니지 않는다). `.env` 는 읽기만 한다 — Env 한 곳(요청마다 다시 읽는다 — php-fpm reload 불필요).
 *
 * 이 함수들은 **이름 규칙**이다 — 권한 판정이 아니다(누가 바꿀 수 있나는 라우트 정책 `user.manage` + 재인증
 * 관문 + Authz::decideUserChange). 이 판정으로 역할·접근을 정하지 않는다.
 *
 * 요구사항: PRD F-10 · F-71 ③ · F-59 ⑥, ARCH 7.4 `user.create …`·`user.update …`, SECURITY R-06 · R-82
 */

require_once dirname(__DIR__) . '/Origin.php';   // 검토 접두 판정 한 곳(isReviewAccount)

/**
 * ① 형식 — 문자열이고 `Auth::USERNAME_RE` 에 맞나. 앞뒤 공백을 걷지 않는다(계정 만들기와 같다).
 * 줄바꿈·제어 문자는 규칙식보다 **먼저** 거부한다(독립 검토 RV-D74-1, 2026-09-27): PCRE 의 `$` 는 끝 줄바꿈 **앞**에서도 맞아
 * `"abc\n"` 이 규칙식을 통과했고, DB CHECK `users_username_chars`(POSIX — 줄바꿈 불허)에서 23514 로 터져 500 · 감사 0 이 됐다.
 * `Auth::USERNAME_RE` 는 보안 핵심 파일(src/Auth.php)이라 그대로 두고 여기서 막는다 — 계정 만들기도 이 함수를 부르므로 함께 막힌다.
 * 판정이 실패(false)해도 거부다(`=== 0` 만 통과 — 닫힌 실패).
 */
function tbox_username_format_ok($raw): bool
{
    return is_string($raw)
        && preg_match('/[\x00-\x1F\x7F]/', $raw) === 0   // 줄바꿈·제어 문자 거부(끝 줄바꿈 "abc\n" 포함 — RV-D74-1)
        && preg_match(Auth::USERNAME_RE, $raw) === 1;
}

/**
 * ②③ 이 이름을 새로 쓸 수 있나 — 계정 만들기·아이디 바꾸기 **공통**.
 * 형식(①)은 부르는 쪽이 먼저 봤다고 가정한다.
 *
 * @return string|null null = 쓸 수 있다 · 'already_exists' · 'reserved_prefix'
 */
function tbox_username_unavailable(Db $db, string $name): ?string
{
    $sqlExists = <<<'SQL'
        SELECT 1 AS ok FROM users WHERE lower(username) = lower(:username)
        SQL;
    if (is_array($db->run($sqlExists, [':username' => $name])->fetch())) {
        return 'already_exists';
    }
    if (Origin::isReviewAccount($name)) {
        return 'reserved_prefix';
    }
    return null;
}

/** ④ 지금 이름이 검토 계정인가 — 그 계정의 아이디는 바꾸지 않는다 */
function tbox_username_locked_review(string $currentName): bool
{
    return Origin::isReviewAccount($currentName);
}

/**
 * ⑤ 공용 프로그램 토큰의 귀속 계정인가 — 지금 이름이 `API_TOKEN_USER` 와 같고 공용 토큰이 켜져 있으면 참(바꾸면 그 토큰이 끊긴다).
 * 공용 토큰이 꺼져 있으면(`API_TOKEN` 없음·32자 미만 — 그 경로는 비교조차 하지 않는다) 늘 거짓이다.
 * 새 이름은 보지 않는다 — 머리글 ⑤(결정 d76 ② 의 순서).
 */
function tbox_username_touches_shared_token(string $currentName): bool
{
    if (strlen(Env::getOrEmpty('API_TOKEN')) < Auth::API_TOKEN_MIN_CHARS) {
        return false;
    }
    $bound = Env::getOrEmpty('API_TOKEN_USER');
    return $bound !== '' && $bound === $currentName;
}

/**
 * 거부 사유 코드 → 응답 문구. 요청 값(아이디)을 문구에 넣지 않는다 — 코드 상수로만 만든다.
 * `already_exists` 는 Http 의 공통 문구(ARCH 5.30 표) 그대로다 — 문구를 두 벌로 만들지 않는다.
 */
function tbox_username_message(string $code): string
{
    switch ($code) {
        case 'bad_format':
            return '아이디 형식이 맞지 않습니다 — 소문자·숫자·_ . - 로 3~64자입니다';
        case 'reserved_prefix':
            return '검토·시험 계정 전용 접두(' . implode('·', Origin::reviewAccountPrefixes())
                . ')로 시작하는 아이디는 만들 수 없습니다 — 사람 계정은 다른 이름으로 만드세요';
        case 'review_account':
            return '검토·시험 계정의 아이디는 바꿀 수 없습니다 — 검토 요청과 사람 행동을 가르는 표식입니다';
        case 'shared_token_user':
            return '이 계정은 공용 프로그램 토큰에 묶여 있어 아이디를 바꾸면 그 토큰이 끊깁니다 — 공용 토큰을 먼저 정리하세요';
        default:
            return Http::reasonMessage($code);
    }
}
