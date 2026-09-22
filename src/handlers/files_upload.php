<?php
declare(strict_types=1);

/**
 * src/handlers/files_upload.php — POST /api/files (multipart/form-data) (정책: file.upload)
 *
 * ★ F-49 (2026-09-15) — 이 파일의 본문은 `_upload_common.php` 의 `tbox_upload_run()` 으로 옮겼다.
 *   프로그램용 업로드(`POST /api/upload`)가 **같은 함수**를 부르기 때문이다 (ARCH 4.2 「업로드
 *   로직은 하나」, PRD F-49 ⑤). 두 입구가 각자 검증을 구현하면 언젠가 한쪽에만 규칙이
 *   추가되고, 규칙이 빠진 쪽이 곧 우회 경로가 된다.
 *   여기 남은 것은 **웹의 문구와 응답 모양**뿐이고, 판정·감사·트랜잭션은 옮기기 전과 같다.
 *
 * ★ 4단계: 레벨·팀을 읽은 **직후**, 파일 검증·저장 **전에**
 *   `Authz::decideUploadAs($user, $level, $teamId)` 로 판정한다 (ARCH 5.2 "[권한 ✔ A]").
 *   화면의 <select> 를 조작해 admin-only 나 남의 팀을 보내도 여기서 403 + 감사 로그 deny 다.
 *   Guest 는 라우터의 `file.upload` 정책에서 이미 끝난다(authz.route deny).
 *
 * ★ 15-3: 형식 검사(Upload::validate)와 일일 총량 **뒤**, 저장 트랜잭션 **앞**에
 *   `Sensitive::scan()` 을 한 번 부른다 (PRD F-22, ARCH 5.13). URL 가져오기(files_fetch)도
 *   **같은 함수**를 같은 자리에서 부르므로 한쪽 입구만 검사가 빠지는 일이 없다.
 *
 * 요구사항: PRD F-05 · F-22 · F-49 ⑤, S-01, 7.6(S-06), 8장 API 표, ARCH 5.2 · 5.13 · 4.2
 *
 * 그 밖에 지키는 것
 *   · CSRF: 라우터 [3] 의 Http::requireCsrf() 가 X-CSRF-Token 헤더를 검사한다 (PRD S-09-3)
 *   · 값은 전부 바인딩 (PRD S-04)
 *   · 저장 이름은 서버가 만든 UUID 뿐. **사용자 파일명은 경로에 한 글자도 들어가지 않는다** (PRD S-06)
 *   · 원본 파일명은 basename → 제어문자 제거 → 길이 제한을 거쳐 DB 에만 들어간다
 *   · 업로드 성공·검증 실패 모두 감사 로그 (PRD S-07)
 *   · DB INSERT · 파일 이동 · 감사 로그를 한 트랜잭션으로 묶는다 (ARCH 5.2 [5]) —
 *     하나라도 실패하면 롤백하고 옮겨진 파일도 지운다(고아 파일·유령 레코드 방지)
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자. uploaded_by 는 이 값만 쓴다
 *                                  (요청 파라미터의 사용자 id·role 은 읽지 않는다 — PRD S-01)
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/_upload_common.php';

/**
 * 웹의 거부 문구 — 감사 사유 코드는 그대로 남기고, 400 의 문장은 한 표에서 가져온다.
 *
 * 세 가지뿐이다:
 *   403          「권한이 없습니다」          (등급·팀 권한 — 대상 정보 미포함, PRD S-09-5)
 *   400          `Http::reasonMessage($reason)` — 사유 코드마다의 사람 문장
 *                (F-68, PRD S-09-12, ARCH 5.30 표가 원본). `sensitive_level_required` 도
 *                그 표가 같은 상수(`MSG_SENSITIVE_LEVEL_REQUIRED`)를 가리키므로 문장은 불변이고,
 *                표에 없는 코드·`REASON_UNIFORM` 의 코드는 「잘못된 요청입니다」로 떨어진다.
 *   400 exec_ack_required  `next:"exec_ack"` + 차단 항목 이름 (아래 갈래)
 *
 * ★ F-65 (2026-09-21, ARCH 5.29 「올리기」 표) — `$exec['action']` 이 있으면 감사 action 이
 *   `file.upload_exec` 이고, 그중 `exec_ack_required` 하나만 **응답 모양이 다른** 400 이다
 *   (`next`·`entries` — 화면이 이 응답으로 확인 블록을 편다).
 *   `bad_credentials`·`bad_code`·`code_reused` 는 `Http::REASON_UNIFORM` 이 지켜 400 통일
 *   문구 그대로이고, `mfa_not_enabled` 는 403 통일 문구다 — 「비밀번호가 틀렸나 코드가
 *   틀렸나」를 응답으로 갈라 주지 않는다 (R-48·R-45, ARCH 5.30 예외 목록).
 *
 * @param array<string,mixed> $entry 감사 엔트리 (공통 함수가 만든 것을 그대로 남긴다)
 * @param array{action?:string,entries?:list<string>} $exec F-65 갈래에서만 채워진다
 */
$reject = static function (string $reason, int $status, array $entry, array $exec = []): void {
    Audit::deny(isset($exec['action']) ? (string) $exec['action'] : 'file.upload', $reason, $entry);

    if ($reason === 'exec_ack_required') {
        // 이름은 서버가 zip 중앙 디렉터리에서 읽은 **문자열 그대로**다. 화면은 textContent
        // 로만 넣으므로 어떤 문자가 들어 있어도 글자로만 보인다 (PRD S-03).
        Http::json([
            'error'   => Http::MSG_EXEC_ACK_REQUIRED,
            'next'    => 'exec_ack',
            'entries' => $exec['entries'] ?? [],
        ], 400);
        exit;
    }

    if ($status === 403) {
        Http::error(403, Http::MSG_FORBIDDEN);
    }
    Http::error($status, $status === 400 ? Http::reasonMessage($reason) : Http::MSG_FORBIDDEN);
};

// 웹은 등급 필드가 `access_level` 이고 **기본값이 없다** — 비어 있으면 지금까지처럼
// `bad_access_level` 400 이다 (기본값 `public` 은 프로그램용 입구에만 있다, PRD F-49 ③).
$result = tbox_upload_run($user, 'access_level', '', $reject);

// 응답 모양도 그대로다 — 등급은 웹 화면이 이미 알고 있으므로 담지 않는다 (PRD 8장 API 표).
Http::json(['id' => $result['id'], 'name' => $result['name']], 201);
