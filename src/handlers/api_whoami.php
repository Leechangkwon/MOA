<?php
declare(strict_types=1);

/**
 * src/handlers/api_whoami.php — GET /api/whoami (정책: authenticated)
 *
 * 프로그램용 「나는 누구인가」 창구 (PRD F-56, ARCH 4.4). 사람이 아니라 **로컬 PC 의 프로그램**이
 * 부른다. 프로그램은 이 응답으로 `admin-only` 같은 등급을 「미확인 기본값」이 아니라
 * **계정 권한**으로 잡는다 (PRD F-56 ①~⑤).
 *
 * 인증은 이 파일에 없다. 헤더 `X-API-Token` 검사도, 귀속 계정(`API_TOKEN_USER`) 조회도
 * **라우터 [2-T] 의 `Auth::requireApiToken()` 한 곳에서 이미 끝났다** — 이 파일에 닿았다는 것은
 * 토큰이 맞았고 `$user` 가 그 귀속 계정이라는 뜻이다. 그래서 여기서 헤더·`.env` 를 다시 읽지
 * 않는다(인증 판단이 두 곳에 생기면 한쪽이 언젠가 어긋난다 — SECURITY R-04 와 같은 원칙).
 * 실패(401 헤더 없음·틀림 / 503 미설정·귀속 계정 무효)도 전부 그 함수가 이미 내며,
 * 이 파일에는 새 실패 경로도 새 문구도 없다 (PRD F-56 ③).
 *
 * 담는 값은 **넷 + Q:F-10 의 둘**(여섯)이고 순서도 이대로다 (PRD 8장 `/api/whoami` 행, T-68 (a) · F-76):
 *   · username / role / team_id — 누구의 권한으로 도는 토큰인가. 표시·판단 참고용이며
 *     허용 여부는 요청마다 서버 Authz 가 다시 판단한다 (PRD S-01).
 *   · upload_levels — 올릴 수 있는 접근 등급 목록. `GET /api/me` 가 쓰는 **바로 그 함수**
 *     `Authz::uploadableLevels($user)` 를 부른다. 역할 문자열을 보고 여기서 고르거나
 *     목록을 복사하지 않는다 — 그러면 판정이 두 벌이 되고, 언젠가 갈라진다
 *     (ARCH 4.1 · 4.4 「새 판정 코드가 없다」, PRD F-56 ②). 같은 계정이면 세션 `/api/me` 의
 *     값과 원소·순서까지 같아야 하며 그것이 중복 구현이 없다는 증거다 (T-68 (b)).
 *
 *   · token_scope / token_scopes_held — **Q:F-10** (사이트 PRD Q:F-10 · t-box F-76, ARCH 4.10, API.md 21장). 이 요청을 연
 *     토큰의 범위 — 정규형 문자열 그대로(`TBOX_API_TOKEN_SCOPE`, requireApiToken 이 DB 값으로 세운 상수)와 그 원자 배열
 *     (`Authz::scopeAtoms`). 비서가 「이 토큰으로 파일·PC 로그인·커뮤니티 검색이 되나」를 판단하는 **참고값**이다 —
 *     허용 여부는 요청마다 라우터 [2-S]·[2-T] 의 `Authz::tokenScopeAllows` 가 다시 판단한다(PRD S-01). 공용(.env) 토큰은
 *     범위 열이 없어 `read+upload` 로 보인다(ARCH 4.7 이행 규칙 그대로). `/api/me` 의 `token_scopes`(고를 수 있는 원자)와
 *     이름이 다르다 — 이쪽은 **쥔** 범위다.
 * 넣지 않는 것 (ARCH 4.4, SECURITY R-89): `csrf_token`(세션이 없으니 뜻이 없다) ·
 *   `can_*`·`next`·`acting*`·`team_name`(화면 편의 값) · 세션 정보 · 내부 id · **토큰 값**.
 *   프로그램이 「내가 무엇을 올릴 수 있나」를 알기 위한 최소한만 준다.
 *
 * 감사: 성공은 행을 남기지 않는다 — 대상 없는 조회이고 `/api/me` 와 같다.
 *   실패는 `auth.token deny`(missing_header·bad_token·not_configured·user_invalid) 네 조합
 *   그대로이며 `Audit.php` 문장표에 새 조합을 더하지 않았다 (ARCH 4.4).
 *
 * 응답은 `Cache-Control: no-store` 로 나간다 (Http::json).
 *
 * 요구사항: PRD F-56, S-01, 8장 API 표, 12.2 T-68 · ARCH 4.2 · 4.3 · 4.4 · SECURITY R-82 · R-83 · R-89
 *
 * @var array<string,mixed> $user   라우터 [2-T] 가 세운 **귀속 계정**. 이 파일은 이 값만 읽는다
 *                                  (요청 파라미터의 username·role 은 읽지 않는다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

Http::json([
    'username'      => (string) $user['username'],
    'role'          => (string) $user['role'],
    'team_id'       => $user['team_id'],
    'upload_levels' => Authz::uploadableLevels($user),
    // Q:F-10 — 이 토큰이 쥔 범위(문자열 · 원자 배열). 토큰 전용 창구라 상수는 언제나 서 있다(없으면 빈 값 — 닫힌 쪽).
    'token_scope'       => defined('TBOX_API_TOKEN_SCOPE') ? (string) constant('TBOX_API_TOKEN_SCOPE') : '',
    'token_scopes_held' => defined('TBOX_API_TOKEN_SCOPE') ? Authz::scopeAtoms((string) constant('TBOX_API_TOKEN_SCOPE')) : [],
]);
