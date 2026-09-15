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
 * 하나이며 웹과 같은 함수다 (PRD F-49 ⑤, ARCH 4.2 「업로드 로직은 하나」). 여기 있는 것은
 * 사유 코드 → 이유 문구 매핑표 하나뿐이고, 그 표에 없는 코드는 「잘못된 요청입니다」로 떨어진다.
 *
 * 요구사항: PRD F-49, S-01, S-06, S-09-7, S-09-11, 8장 API 표, ARCH 4.2 · 5.2, SECURITY R-82
 *
 * @var array<string,mixed> $user   라우터가 세운 **귀속 계정**. uploaded_by 는 이 값만 쓴다
 *                                  (요청 파라미터의 사용자 id·role 은 읽지 않는다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/_upload_common.php';

/**
 * 사유 코드 → [이유 문구, 상태 코드] (PRD F-49 ④).
 *
 * `const` 가 아니라 지역 배열이다 — 확장자 목록을 `Upload::ALLOWED_EXT` 에서 만들어 넣어야
 * 하는데(`implode`) PHP 의 `const` 표현식에는 함수 호출을 쓸 수 없기 때문이다.
 * 이 파일은 라우터가 요청당 한 번만 `require` 하므로 표는 요청당 한 번 만들어진다.
 *
 * ★ 2026-09-15 검토 재지시 — 아래 목록(주석)에는 있는데 **표에는 없던** 코드가 하나 있었다
 *   (`ext_not_allowed`). `.exe` 업로드가 감사에는 사유를 남기면서 응답은 통일 문구로 떨어져,
 *   「이유를 숨기지 않는다」는 계약을 어겼다. 주석과 표가 어긋날 수 있다는 것이 교훈이고,
 *   그래서 코드를 더할 때는 **표를 먼저 고치고** 주석을 맞춘다.
 *
 * 코드의 출처는 네 곳이고 **전부** 여기 들어 있다:
 *   · `_upload_common.php` 가 직접 내는 것 — no_file · bad_encoding · description_too_long ·
 *     bad_access_level · bad_team · bad_folder · sensitive_level_required
 *   · `Upload::validate()` — upload_error · not_uploaded_file · size_unreadable · empty_file ·
 *     too_large · hash_failed · ext_not_allowed · double_extension · mime_mismatch
 *   · `Upload::checkDailyQuota()` — quota_exceeded
 *   · `Authz::decideUploadAs()` / `Authz::fitsUnderFolder()` — role_not_permitted ·
 *     team_mismatch · parent_narrower
 *
 * 상태 코드는 **크기 두 가지만** 공통 함수가 준 400 에서 413 으로 올린다 — 강사 계약이
 * "크기 초과는 413" 이기 때문이다(nginx 의 413 과 같은 뜻으로 보이게 한다). 웹은 지금까지처럼
 * 둘 다 400 이고, 그 동작은 바뀌지 않는다(사유는 감사 로그에만).
 *
 * 문구에는 **대상·다른 사람·서버 내부에 대한 정보를 담지 않는다** — 담는 것은 "요청자가 방금
 * 보낸 값의 어디가 규칙에 맞지 않는가" 뿐이다 (PRD S-09-5 의 취지는 그대로다).
 */
$reasonTable = [
    // 요청 형식
    'no_file'                  => ['file 필드에 파일이 없습니다', 400],
    'upload_error'             => ['업로드 요청의 형식이 올바르지 않습니다 (multipart/form-data 로 file 하나를 보냅니다)', 400],
    'not_uploaded_file'        => ['정상적인 HTTP 업로드가 아닙니다', 400],
    'bad_encoding'             => ['파일 이름 또는 설명이 올바른 문자(UTF-8)가 아닙니다', 400],
    'description_too_long'     => ['설명은 500자까지입니다', 400],

    // 등급·팀·폴더
    'bad_access_level'         => ['level 값이 올바르지 않습니다 (public·internal·team·admin-only)', 400],
    'bad_team'                 => ['team 등급은 team_id(활성 팀 번호)가 필요합니다', 400],
    'bad_folder'               => ['folder_id 가 없거나 접근할 수 없는 폴더입니다', 400],
    'parent_narrower'          => ['파일 등급이 폴더 등급보다 넓을 수 없습니다', 400],
    'role_not_permitted'       => ['이 계정은 그 등급(팀)으로 올릴 권한이 없습니다', 403],
    'team_mismatch'            => ['이 계정은 그 등급(팀)으로 올릴 권한이 없습니다', 403],

    // 파일 내용·크기
    'empty_file'               => ['빈 파일(0바이트)은 올릴 수 없습니다', 400],
    'size_unreadable'          => ['파일 크기를 읽을 수 없습니다', 400],
    'hash_failed'              => ['파일의 무결성 값을 계산할 수 없습니다', 400],
    // 허용 목록은 **Upload::ALLOWED_EXT 에서 만든다** — 문구에 손으로 베껴 두면 목록이 바뀌는
    // 날 문서와 응답이 조용히 어긋난다(고치는 사람이 두 곳을 기억해야 하는 구조를 만들지 않는다).
    // 이 표가 `const` 가 아니라 지역 배열인 이유도 이것이다: const 안에서는 implode 를 못 쓴다.
    'ext_not_allowed'          => ['허용되지 않는 확장자입니다 (허용: ' . implode(' ', Upload::ALLOWED_EXT) . ')', 400],
    'double_extension'         => ['이중 확장자 파일은 올릴 수 없습니다', 400],
    'mime_mismatch'            => ['파일 내용이 확장자와 다릅니다', 400],
    // 크기 두 가지만 413 이다 (강사 계약)
    'too_large'                => ['파일이 100MB 를 넘습니다', 413],
    'quota_exceeded'           => ['오늘 업로드 총량(2GB)을 넘었습니다', 413],

    // 민감정보 (웹과 같은 문구를 쓴다 — 이미 이유를 알려 주는 400 이다)
    'sensitive_level_required' => [Http::MSG_SENSITIVE_LEVEL_REQUIRED, 400],
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
$reject = static function (string $reason, int $status, array $entry) use ($reasonTable): void {
    Audit::deny('file.upload', $reason, $entry);

    // 표에 없는 코드는 조용히 넘어가지 않고 **통일 문구**로 떨어진다 — 새 사유 코드가
    // 생겼는데 여기에 안 적힌 경우, 프로그램에는 「잘못된 요청입니다」가 가고
    // 진짜 사유는 감사 로그에 그대로 남는다(정보가 사라지지 않는다).
    // ★ 그 되돌림은 **그물이지 설계가 아니다** — 2026-09-15 검토에서 `ext_not_allowed` 가
    //   표에서 빠진 채 이 그물로 떨어져, 감사에는 사유가 남는데 응답은 「잘못된 요청입니다」
    //   하나였다(강사 계약 「이유를 숨기지 않는다」 위반). 사유 코드를 새로 만들면
    //   위 표에 **반드시** 한 줄을 더한다.
    [$message, $code] = $reasonTable[$reason] ?? [Http::MSG_BAD_REQUEST, $status];

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
