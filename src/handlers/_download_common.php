<?php
declare(strict_types=1);

/**
 * src/handlers/_download_common.php — 받기 관문 한 벌 (PRD F-06 · F-54 · F-65, ARCH 5.3 · 5.28 · 5.29 · 5.34)
 *
 * ★ 왜 이 파일이 생겼나 (개발 대기열 6 · Q:F-3a 라운드 C, 2026-09-26)
 *   「여러 파일을 zip 으로 받기」(`POST /api/files/zip`)가 생기면서 **파일을 내주는 입구가 둘**이 됐다.
 *   두 입구가 각자 권한·추가 거부를 적으면 언젠가 한쪽에만 규칙이 더해지고, 규칙이 빠진 쪽이 곧 우회 경로다
 *   (업로드 입구 둘을 `_upload_common.php` 로 묶은 것과 같은 걱정 — R-04).
 *   그래서 `files_download.php` 안에 있던 세 관문(권한 → F-65 실행 파일 압축본 → F-54 검사 상태)과
 *   무결성 두 단계(실제 바이트 · sha256)를 **글자 그대로 옮겨** 함수로 만들고, 한 건 받기와 zip 이 **둘 다 이것을 부른다**.
 *   새 판정은 0 이다 — 판정은 여전히 `Authz::decideRead` 하나이고, 추가 거부 셋의 순서·사유 코드·감사 행 모양도 전과 같다.
 *
 *   두 입구의 차이는 두 가지뿐이다:
 *     ① 거부되면 — 한 건 받기는 그 자리에서 응답(403·423·exec_ack)하고 끝, zip 은 그 파일만 **빼고** 다음 파일로
 *     ② 감사 detail 에 `via: "zip"` 한 칸(zip 이 넘기는 $extra) — 한 건 받기는 아무것도 넘기지 않아 행이 전과 글자까지 같다
 *
 * 이 파일은 라우트 표에 없다(이름이 `_` 로 시작한다) — 두 핸들러가 `require_once` 하는 공통 코드다.
 *
 * 요구사항: PRD F-06 · F-54 · F-65 · S-01 · S-02 · S-07, ARCH 5.3 [권한 ✔]·[3-5]·[4]·[5]·[6] · 5.34
 */

/**
 * 받기 대상 파일 행 — 한 건 받기와 zip 이 **같은 SELECT** 를 쓴다(없는 것·지운 것은 null).
 * $publicId 는 부르는 쪽이 `Storage::isValidUuid` 로 거른 값이다.
 *
 * @return array<string,mixed>|null
 */
function tbox_download_find(string $publicId): ?array
{
    $sqlFind = <<<'SQL'
        SELECT f.id,
               f.original_name,
               f.stored_name,
               f.mime,
               f.access_level,
               f.size_bytes,
               f.team_id,
               f.uploaded_by,
               f.sha256,
               f.scan_status,
               f.exec_content
          FROM files f
         WHERE f.public_id = CAST(:pid AS uuid)
           AND f.deleted_at IS NULL
        SQL;

    $file = Db::instance()->run($sqlFind, [':pid' => $publicId])->fetch();
    return is_array($file) ? $file : null;
}

/**
 * 감사 로그의 "무엇을" 칸 — 어떤 내용의 파일이었나(sha256·크기), 누구 것이었나, 어느 팀 것이었나.
 * 사용자 응답에는 한 글자도 나가지 않고 로그에만 남는다 (PRD S-07 기록 원칙).
 *
 * @param array<string,mixed> $file
 * @return array<string,mixed>
 */
function tbox_download_audit_what(array $file): array
{
    return [
        'owner_id'    => (int) $file['uploaded_by'],
        'target_team' => $file['team_id'] === null ? null : (int) $file['team_id'],
        'sha256'      => (string) $file['sha256'],
        'size_bytes'  => (int) $file['size_bytes'],
    ];
}

/**
 * 받기의 세 관문 — **순서를 바꾸지 않는다**(ARCH 5.3 · 5.28 · 5.29).
 *
 *   [권한 ✔] `Authz::decideRead` — 저장소에 손대기 전. 판정과 근거 코드를 함께 받고, 코드는 지어내지 않고 그대로 기록한다(ARCH 4.1)
 *   [3-5]   F-65 실행 파일이 든 압축본 — 권한 **뒤**(볼 자격이 없는 사람에게는 「그런 파일이 있다」조차 알리지 않는다) ·
 *           검사 상태 **앞**. 토큰(`TBOX_AUTH_METHOD`)이면 `exec_content_token`, 세션이면 `exec_ack_required`
 *   [4]     F-54 검사 상태 — `pending`·`infected` 는 **아무도** 받지 못한다. 사유 `scan_pending`·`scan_infected`
 *
 * 거부면 `file.download deny <사유>` 한 행을 남기고 사유 코드를 돌려준다. 응답은 부르는 쪽이 정한다(한 건 받기 = 그 자리 응답,
 * zip = 그 파일만 빼기). $extra 는 detail 끝에 덧붙는 칸이다(zip 의 `via` — 한 건 받기는 비워 둬 행이 전과 같다).
 *
 * @param array<string,mixed> $user
 * @param array<string,mixed> $file   tbox_download_find() 의 행
 * @param array<string,mixed> $extra
 * @return array{0:?string,1:string}  [거부 사유 코드 | null(세 관문 통과), decideRead 의 근거 코드]
 */
function tbox_download_gate(array $user, array $file, array $extra = []): array
{
    [$mayRead, $readCode] = Authz::decideRead($user, $file);
    $auditWhat = tbox_download_audit_what($file);

    if (!$mayRead) {
        Audit::deny('file.download', $readCode, [
            'target_type'  => 'file',
            'target_id'    => (int) $file['id'],
            'target_name'  => (string) $file['original_name'],
            'access_level' => (string) $file['access_level'],
            'detail'       => $auditWhat + $extra,
        ]);
        return [$readCode, $readCode];
    }

    if ($file['exec_content'] === true) {
        $execReason = defined('TBOX_AUTH_METHOD') ? 'exec_content_token' : 'exec_ack_required';
        Audit::deny('file.download', $execReason, [
            'target_type'  => 'file',
            'target_id'    => (int) $file['id'],
            'target_name'  => (string) $file['original_name'],
            'access_level' => (string) $file['access_level'],
            'detail'       => $auditWhat + $extra,
        ]);
        return [$execReason, $readCode];
    }

    $scan = (string) ($file['scan_status'] ?? 'clean');
    if ($scan !== 'clean') {
        $scanReason = $scan === 'infected' ? 'scan_infected' : 'scan_pending';
        Audit::deny('file.download', $scanReason, [
            'target_type'  => 'file',
            'target_id'    => (int) $file['id'],
            'target_name'  => (string) $file['original_name'],
            'access_level' => (string) $file['access_level'],
            'detail'       => $auditWhat + ['scan_status' => $scan] + $extra,
        ]);
        return [$scanReason, $readCode];
    }

    return [null, $readCode];
}

/**
 * 무결성 두 단계 — 실제 바이트가 있나(ARCH 5.3 [5]) → 저장된 바이트의 sha256 이 DB 값과 같나([6], SECURITY R-13).
 * 순서를 바꾸지 않는다: 둘 다 **allow 기록·전송 전**이다(「받지 못했는데 성공으로 기록」·변조된 바이트 전송을 막는다).
 *
 * 실패하면 `file.download error <사유>` 한 행을 남기고 [사유, 잡은 예외] 를 돌려준다.
 *   file_missing  — `Storage::assertExists` 가 던진 예외를 함께 돌려준다. 한 건 받기는 전처럼 그 예외를 **다시 던진다**
 *                   (bootstrap 예외 처리 → 500 `서버 오류`), zip 은 그 파일만 뺀다
 *   hash_mismatch — 예외 없음(null). 한 건 받기는 500 `서버 오류`, zip 은 그 파일만 뺀다. 어느 쪽이든 AL-6 대상 행이 남는다
 * 통과면 [null, null].
 *
 * @param array<string,mixed> $file
 * @param array<string,mixed> $extra
 * @return array{0:?string,1:?Throwable}
 */
function tbox_download_integrity(array $file, array $extra = []): array
{
    $storedName = (string) $file['stored_name'];

    try {
        Storage::assertExists($storedName);
    } catch (Throwable $e) {
        $ctx = [
            'target_type'  => 'file',
            'target_id'    => (int) $file['id'],
            'target_name'  => (string) $file['original_name'],
            'access_level' => (string) $file['access_level'],
        ];
        if ($extra !== []) {
            $ctx['detail'] = $extra;   // 한 건 받기는 전처럼 detail 없음(자동 칸만)
        }
        Audit::error('file.download', 'file_missing', $ctx);
        return ['file_missing', $e];
    }

    // 저장된 바이트가 업로드 당시의 기록과 같은지 **전송 전에** 확인한다. 서버가 뚫려 저장소 파일이
    // 악성 문서로 바뀌어도 여기서 멈춘다. 다르면 allow 를 남기지 않고 error(hash_mismatch) 만 남긴다
    // (AL-6 경보 대상) — "해시가 달라서 막았다" 는 사실은 응답으로 알려 주지 않는다.
    $actualHash = Storage::sha256($storedName);
    if (!hash_equals((string) $file['sha256'], $actualHash)) {
        Audit::error('file.download', 'hash_mismatch', [
            'target_type'  => 'file',
            'target_id'    => (int) $file['id'],
            'target_name'  => (string) $file['original_name'],
            'access_level' => (string) $file['access_level'],
            'detail'       => tbox_download_audit_what($file) + $extra,
        ]);
        return ['hash_mismatch', null];
    }

    return [null, null];
}
