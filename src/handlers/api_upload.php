<?php
declare(strict_types=1);

/**
 * src/handlers/api_upload.php — POST /api/upload (multipart/form-data) (정책: file.upload)
 *
 * 프로그램용 업로드 창구 (PRD F-49, ARCH 4.2). 사람이 아니라 **로컬 PC 의 프로그램**이 부른다.
 *
 * 무엇이 다른가 — 웹 업로드(`POST /api/files`)와 비교해서 세 가지뿐이다:
 *   ① 인증   세션·CSRF 대신 헤더 `X-API-Token` 하나 (라우터 [2] 의 Auth::requireApiToken).
 *            그 검사는 **라우터에서 이미 끝났고**, 이 파일에 닿았다는 것은 토큰이 맞았고
 *            귀속 계정(`API_TOKEN_USER`)으로 `[4] file.upload` 인가까지 통과했다는 뜻이다.
 *   ② 등급   필드 이름이 `level` 이고, 없으면 `public` 이다 (PRD F-49 ③).
 *   ③ 문구   400/401/403/413 은 **사람이 읽을 수 있는 이유**를 돌려준다 (PRD F-49 ④).
 *            부르는 쪽이 토큰을 쥔 우리 프로그램이고, 이유를 모르면 고칠 수 없기 때문이다.
 *            **500 은 예외다** — 기존 일반 문구 `{"error":"서버 오류"}` 를 그대로 둔다
 *            (PRD S-09-7: 예외·DB 오류 원문은 서버 로그의 request_id 로만).
 *
 * 검증·저장은 **한 글자도 따로 구현하지 않는다** — `_upload_common.php` 의 `tbox_upload_run()`
 * 하나이며 웹과 같은 함수다 (PRD F-49 ⑤, ARCH 4.2 「업로드 로직은 하나」). 문구도 F-68 부터
 * 웹과 같은 표(`Http::reasonMessage`, ARCH 5.30)에서 오고, 여기 남는 것은 상태 코드를 바꾸는
 * 네 줄과 403 두 문장뿐이다.
 *
 * 요구사항: PRD F-49, S-01, S-06, S-09-7, S-09-11, 8장 API 표, ARCH 4.2 · 5.2, SECURITY R-82
 *
 * @var array<string,mixed> $user   라우터가 세운 **귀속 계정**. uploaded_by 는 이 값만 쓴다
 *                                  (요청 파라미터의 사용자 id·role 은 읽지 않는다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/_upload_common.php';

/**
 * 사유 코드 → **상태 코드**만 (PRD F-49 ④, F-68 · ARCH 5.30).
 *
 * ★ F-68 (2026-09-22) — 이 표에 있던 **문장 열을 지웠다.** 문장은 `Http::reasonMessage()`
 *   한 표(ARCH 5.30)에서 온다 — 새 사유 코드를 만들면 그 표에 한 줄을 더한다. 웹 입구
 *   (`POST /api/files`)와 이 입구가 같은 문장을 쓰게 되어, 표가 두 벌로 갈라져 한쪽만
 *   갱신되는 자리가 없어졌다(2026-09-15 `ext_not_allowed` 누락 사고가 그 자리였다).
 *   확장자 허용 목록도 그 함수가 `Upload::ALLOWED_EXT` 에서 만든다.
 *
 * 여기 남는 것은 **공통 함수가 준 상태 코드를 바꾸는 네 줄**뿐이다:
 *   · 크기 둘(`too_large`·`quota_exceeded`)은 400 → **413** — 강사 계약이 「크기 초과는 413」
 *     이기 때문이다(nginx 의 413 과 같은 뜻으로 보이게 한다). 웹은 지금까지처럼 둘 다 400 이다.
 *   · 권한 둘(`role_not_permitted`·`team_mismatch`)은 **403** 이고 문장도 여기서 정한다 —
 *     403 은 사유를 말하지 않는 자리이고(S-09-5), 프로그램 입구의 이 두 문장은 T-61 계약이다
 *     (ARCH 5.30 「403 두 줄은 api_upload.php 에 남는다」).
 *   표에 없는 코드는 공통 함수가 준 `$status` 그대로다.
 */
$statusTable = [
    'too_large'          => 413,
    'quota_exceeded'     => 413,
    'role_not_permitted' => 403,
    'team_mismatch'      => 403,
];

/**
 * 감사 deny 를 남기고 **이유가 담긴** JSON 으로 끝낸다.
 *
 * 감사에 남는 사유 코드·엔트리는 웹과 **같다** — 로그를 읽는 사람이 입구마다 다른 낱말을
 * 외우지 않아도 되게 하려는 것이다. 갈라지는 것은 응답 문구뿐이고, 이 요청이 토큰으로
 * 들어왔다는 사실은 `detail.auth=api_token` 으로 자동으로 붙는다 (Audit::autoDetail).
 *
 * @param array<string,mixed> $entry 감사 엔트리 (공통 함수가 만든 것을 그대로 남긴다)
 */
$reject = static function (string $reason, int $status, array $entry) use ($statusTable): void {
    Audit::deny('file.upload', $reason, $entry);

    // 문장은 `Http::reasonMessage` 한 표(ARCH 5.30)에서 온다 — 새 사유 코드를 만들면 그
    // 표에 한 줄을 더한다. 표에 없는 코드·`REASON_UNIFORM` 의 코드는 「잘못된 요청입니다」로
    // 떨어지고, 진짜 사유는 감사 로그에 그대로 남는다(정보가 사라지지 않는다).
    // 403 두 문장만 여기 있다 — 그 자리는 사유를 말하지 않는다(S-09-5, T-61 계약).
    $code    = $statusTable[$reason] ?? $status;
    $message = $code === 403 ? '이 계정은 그 등급(팀)으로 올릴 권한이 없습니다' : Http::reasonMessage($reason);

    Http::error($code, $message);
};

// 등급 필드는 `level` 이고 비어 있으면 `public` 이다 (PRD F-49 ③).
$result = tbox_upload_run($user, 'level', 'public', $reject);

// 성공 응답은 계약 그대로 세 칸이다 — 프로그램이 "어느 등급으로 올라갔는지" 를
// 되돌려 받아야 기본값(public)이 적용됐는지 스스로 확인할 수 있다 (PRD F-49 ④).
Http::json([
    'id'    => $result['id'],
    'name'  => $result['name'],
    'level' => $result['level'],
], 201);
