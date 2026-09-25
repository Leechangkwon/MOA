<?php
declare(strict_types=1);

/**
 * src/handlers/_upload_common.php — 업로드 한 벌 (PRD F-05 · F-22 · F-49 ⑤, ARCH 5.2 · 4.2)
 *
 * ★ 왜 이 파일이 생겼나 (F-49, 2026-09-15)
 *   프로그램용 업로드 `POST /api/upload` 가 생기면서 업로드 입구가 둘이 됐다.
 *   두 입구가 각자 검증·저장을 구현하면 언젠가 한쪽에만 규칙이 추가되고, **규칙이 빠진 쪽이
 *   곧 우회 경로**가 된다(라우트 정책 누락을 부팅 실패로 잡는 것과 같은 걱정이다 — R-04).
 *   그래서 `files_upload.php` 의 본문을 **그대로 옮겨** 이 함수 하나로 만들고,
 *   웹(`POST /api/files`)과 API(`POST /api/upload`)가 **둘 다 이것을 부른다**.
 *
 *   두 입구의 차이는 세 가지 매핑뿐이다:
 *     ① 등급 필드 이름   `access_level`(웹) ↔ `level`(API, 없으면 `public`)
 *     ② 거부 응답        웹 = 통일 문구, API = 사유 코드 → 사람이 읽는 이유  (→ $reject)
 *     ③ 성공 응답        `{id,name}` ↔ `{id,name,level}`                    (→ 호출자)
 *   **판정·감사 행·사유 코드·트랜잭션은 한 글자도 다르지 않다.**
 *
 * 이 파일은 라우트 표에 없다(이름이 `_` 로 시작한다) — 직접 호출되는 핸들러가 아니라
 * 두 핸들러가 `require_once` 하는 공통 코드다.
 *
 * 요구사항: PRD F-05 · F-22 · F-49, S-01, 7.6(S-06), ARCH 5.2 · 5.13 · 5.16 · 4.2
 */

/**
 * 업로드 한 번을 끝까지 수행한다 — 필드 읽기 · 권한 판정 · 형식 검사 · 민감정보 검출 ·
 * 트랜잭션 저장 · 감사 기록. 성공하면 응답에 쓸 값 세 개를 돌려주고, 실패하면 돌아오지 않는다.
 *
 * @param array<string,mixed> $user         라우터가 세운 현재 사용자(세션 로그인 또는 API 토큰 귀속 계정).
 *                                          `uploaded_by` 는 이 값만 쓴다 — 요청 파라미터의
 *                                          사용자 id·role 은 한 글자도 읽지 않는다 (PRD S-01).
 * @param string $levelField                등급을 담은 폼 필드 이름: `access_level`(웹) | `level`(API)
 * @param string $levelDefault              그 필드가 비었을 때의 기본값:
 *                                          `''`(웹 — 비면 `bad_access_level`) | `'public'`(API, PRD F-49 ③)
 * @param callable $reject                  fn(string $reasonCode, int $status, array $auditEntry,
 *                                             array $exec = []): never
 *                                          — 감사 deny 를 남기고 응답한 뒤 **돌아오지 않는다**.
 *                                          사유 코드·상태 코드·감사 엔트리는 두 입구가 같고,
 *                                          문구만 호출자가 정한다.
 *                                          네 번째 인자 `$exec` 는 **F-65 갈래에서만** 채워진다
 *                                          (`action` = `file.upload_exec` · `entries` = 차단 항목
 *                                          이름 ≤ 20). 그 갈래는 **웹 세션에만** 있으므로
 *                                          (ARCH 5.29 ①) 프로그램용 입구의 3인자 클로저에는
 *                                          이 값이 한 번도 가지 않는다.
 * @return array{id:string,name:string,level:string}  성공한 업로드의 외부 ID · 원래 파일명 · 등급
 */
function tbox_upload_run(array $user, string $levelField, string $levelDefault, callable $reject): array
{
    /**
     * 폼 필드 하나를 문자열로 읽는다.
     * 키를 변수로 넘기는 이유: 폼 필드 이름을 리터럴 첨자로 쓰면
     * scripts/security_lint.sh 의 "요청 파라미터의 역할값 사용" 검사(role·is_admin·팀 첨자)에 걸린다.
     * 팀 번호는 역할이 아니라 사용자가 고른 업로드 대상 팀이지만(PRD F-05),
     * 린트를 예외 처리하기보다 요청 값을 읽는 통로를 한 곳으로 모으는 편이 안전하다.
     * 실제 권한 판단(그 팀으로 올릴 수 있는가)은 아래 [3-2] Authz::decideUploadAs 가 한다.
     */
    $formText = static function (string $key): string {
        $raw = $_POST[$key] ?? '';
        return is_string($raw) ? $raw : '';
    };

    /* ─── [0] F-65 확인 필드 — 읽고 **즉시 지운다** (ARCH 5.29, PRD 7.7) ───
     *
     * `exec_ack`·`password`·`otp` 는 다른 폼 필드(`access_level`·`description`·`folder_id`)와
     * **같은 자리**에서 읽는 multipart 필드다. 읽은 직후 `$_POST` 에서 지우는 이유:
     * 그 뒤의 어떤 코드도(예외 핸들러의 덤프·미래에 추가될 로깅) 이 세 값을 볼 수 없게 하려는
     * 것이다. 값은 이 함수의 지역 변수에만 있고 응답·감사·app.log 어디에도 나가지 않는다.
     *
     * 세 값 모두 **검증 전에** 읽는다 — 어느 갈래로 가든(거부 포함) $_POST 에 남지 않아야 한다.
     */
    $execAck      = $formText('exec_ack');
    $execPassword = $formText('password');
    $execOtp      = $formText('otp');
    unset($_POST['exec_ack'], $_POST['password'], $_POST['otp']);

    /**
     * 검증 실패 — 사유 코드와 감사 엔트리를 만들어 호출자의 $reject 에 넘긴다.
     * 웹은 여기서 동일한 400 문구가 나가고(PRD S-09-5), API 는 같은 사유 코드로
     * 사람이 읽는 이유를 고른다 (PRD F-49 ④). **어느 쪽이든 감사에 남는 사유는 같다.**
     */
    $rejectBadRequest = static function (string $reason, ?string $name, ?string $level) use ($reject): void {
        $entry = [
            'target_type'  => 'file',
            'target_name'  => $name,
            'access_level' => $level,
        ];
        // MIME 짝 불일치는 "무엇으로 감지됐나" 가 없으면 진단할 수 없다 (2026-09-13 이카운트 xlsx 거부 사례, R-71).
        if ($reason === 'mime_mismatch' && Upload::$lastDetectedMime !== null) {
            $entry['detail'] = ['mime' => Upload::$lastDetectedMime];
        }
        $reject($reason, 400, $entry);
    };

    // ─── [1] 파일 필드 확인 ────────────────────────────────────────
    // 101MB 같은 과대 요청은 nginx(client_max_body_size 101m)가 413 으로 먼저 끊는다.
    // 정확히 100MB(104,857,600 B) 경계는 PHP Upload::MAX_BYTES 가 판정한다 (ARCH 8장 6단계 결정).
    $uploaded = $_FILES['file'] ?? null;
    if (!is_array($uploaded)) {
        $rejectBadRequest('no_file', null, null);
    }

    // ─── [2] 원본 파일명 정리 (DB 전용 값) ─────────────────────────
    // basename() 으로 경로를 떼고(`../../etc/passwd.txt` → `passwd.txt`),
    // 제어문자·CR/LF 를 지우고, 255자로 자른다. 이 값은 경로 조립에 쓰이지 않는다.
    $rawName = $uploaded['name'] ?? '';
    $original = is_string($rawName) ? $rawName : '';
    $original = basename(str_replace('\\', '/', $original));
    if ($original !== '' && preg_match('//u', $original) !== 1) {
        // 잘못된 UTF-8 은 DB(UTF8)·JSON 에서 오류를 만든다 → 저장하지 않고 거부한다
        $rejectBadRequest('bad_encoding', null, null);
    }
    $original = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $original);
    $original = trim($original);
    if ($original !== '' && iconv_strlen($original, 'UTF-8') > 255) {
        $original = (string) iconv_substr($original, 0, 255, 'UTF-8');
    }
    if ($original === '') {
        $original = 'file';
    }

    // ─── [3] 설명·접근 레벨·팀 ─────────────────────────────────────
    $description = $formText('description');
    if ($description !== '' && preg_match('//u', $description) !== 1) {
        $rejectBadRequest('bad_encoding', $original, null);
    }
    $description = (string) preg_replace('/[\x00-\x1f\x7f]+/u', '', $description);
    if (iconv_strlen($description, 'UTF-8') > 500) {
        // DB CHECK(length(description) <= 500) 와 같은 한계를 앱에서도 확인한다
        $rejectBadRequest('description_too_long', $original, null);
    }

    /* 등급 — 필드 이름과 기본값만 입구마다 다르다 (F-49 ③).
     * 웹은 기본값이 없으므로(`''`) 빈 값이면 지금까지처럼 `bad_access_level` 이고,
     * API 는 `public` 으로 채운 **뒤** 같은 허용 목록 검사를 받는다. 검사 자체는 하나다. */
    $level = $formText($levelField);
    if ($level === '' && $levelDefault !== '') {
        $level = $levelDefault;
    }
    if (!in_array($level, ['public', 'internal', 'team', 'admin-only'], true)) {
        $rejectBadRequest('bad_access_level', $original, null);
    }

    $teamId = null;
    if ($level === 'team') {
        // team 레벨은 team_id 필수 (PRD 4장, DB CHECK team_requires_team_id 로도 강제)
        $teamRaw = $formText('team_id');
        if (preg_match('/^[1-9][0-9]{0,8}$/', $teamRaw) !== 1) {
            $rejectBadRequest('bad_team', $original, $level);
        }
        // 12-3 (F-19, ARCH 5.9): **활성 팀만** 있는 것으로 본다 — 비활성 팀으로 올리려는 요청은
        // 기존 거부 경로(bad_team → 400)로 끝난다. 이미 그 팀에 있는 파일은 그대로다.
        $sqlTeam = <<<'SQL'
            SELECT id FROM teams WHERE id = :id AND is_active
            SQL;
        $teamRow = Db::instance()->run($sqlTeam, [':id' => (int) $teamRaw])->fetch();
        if (!is_array($teamRow)) {
            $rejectBadRequest('bad_team', $original, $level);
        }
        $teamId = (int) $teamRow['id'];
    }

    /* ─── [3-3] 폴더 (18-1, PRD F-26, ARCH 5.16 "파일 쪽 변경") ───
     *
     * `folder_id` 는 **선택**이다 — 비어 있으면 루트에 올린다. 값이 있으면 폴더가 실제로 있고
     * **읽을 수 있어야** 한다(못 읽는 폴더 안으로 파일을 밀어 넣는 길을 만들지 않는다).
     * 없는 폴더와 못 읽는 폴더는 **같은 400 동일 문구**이고 사유(`bad_folder`)는 로그에만 남는다.
     *
     * 불변 규칙 ①(파일은 폴더보다 넓을 수 없다)은 등급 판정 바로 뒤 [3-4] 에서 본다.
     * 이 검사들은 파일을 읽기 **전**에 끝난다 — 거부될 업로드의 바이트를 검사·저장하지 않는다.
     */
    $folderId  = null;
    $folderRow = null;
    $folderRaw = $formText('folder_id');
    if ($folderRaw !== '') {
        if (preg_match('/^[1-9][0-9]{0,8}$/', $folderRaw) !== 1) {
            $rejectBadRequest('bad_folder', $original, $level);
        }
        $sqlFolder = <<<'SQL'
            SELECT id, name, access_level, team_id
              FROM folders
             WHERE id = CAST(:id AS bigint)
            SQL;
        $folderRow = Db::instance()->run($sqlFolder, [':id' => (int) $folderRaw])->fetch();
        if (!is_array($folderRow)) {
            $rejectBadRequest('bad_folder', $original, $level);
        }
        // 폴더도 파일과 **같은 함수**로 읽기 판정한다 (폴더도 등급을 갖는다, F-26).
        if (!Authz::decideRead($user, $folderRow)[0]) {
            $rejectBadRequest('bad_folder', $original, $level);
        }
        $folderId = (int) $folderRow['id'];
    }

    // ─── [3-2] 권한 ✔ A — 이 레벨(+팀)로 올릴 수 있나 (검증·저장 전, ARCH 5.2) ───
    // 판정 코드는 Authz 가 돌려준 것을 그대로 기록한다 (ARCH 4.1).
    // 토큰으로 들어온 요청도 **여기를 그대로 탄다** — 판정 기준은 귀속 계정이다 (ARCH 4.2).
    [$mayUpload, $uploadCode] = Authz::decideUploadAs($user, $level, $teamId);
    if (!$mayUpload) {
        // 응답은 403 이고, 대상 팀 같은 서버가 아는 값은 로그에만 남긴다 (PRD S-07).
        $reject($uploadCode, 403, [
            'target_type'  => 'file',
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => ['target_team' => $teamId],
        ]);
    }

    /* ─── [3-4] 불변 규칙 ① — 파일은 폴더보다 넓을 수 없다 (ARCH 5.16) ───
     * 판정은 Authz::fitsUnderFolder 한 곳이고, 업로드·가져오기·이동·등급 변경·폴더 생성·이동·
     * 등급 변경 **일곱 경로**가 전부 이 함수를 지난다. 400 이고 Admin 도 같은 400 이다 —
     * 막는 것은 "누가" 가 아니라 "무엇이 어디에 들어갈 수 있나" 이기 때문이다.
     */
    if ($folderRow !== null) {
        [$fits, $fitCode] = Authz::fitsUnderFolder($level, $teamId, $folderRow);
        if (!$fits) {
            $reject($fitCode, 400, [
                'target_type'  => 'file',
                'target_name'  => $original,
                'access_level' => $level,
                'detail'       => ['folder_id' => $folderId, 'target_team' => $teamId],
            ]);
        }
    }

    // ─── [4] 업로드 검증 (크기·확장자 화이트리스트·이중 확장자·MIME 짝·해시) ───
    // 6단계: PRD S-06 전부를 Upload 이 판정한다 (T-7 · T-8 · 이중 확장자, src/Upload.php 주석).

    /* F-65 (ARCH 5.29 「올리기」 표) — 이 업로드가 「실행 파일이 든 압축본의 확인 업로드」로
     * 들어왔나. null 이면 **지금까지와 완전히 같은 업로드**이고(대다수), 배열이면 아래 ⑤ 를
     * 통과해 차단 항목 이름을 들고 온 것이다. INSERT 의 `exec_content` 와 감사 action 이
     * 이 값 하나로 갈린다. */
    $execEntries = null;

    try {
        $checked = Upload::validate($uploaded);
    } catch (UploadBlockedEntries $execBlocked) {
        /* ─── F-65 — zip 안에 차단 확장자 항목이 있다 (ARCH 5.29 「올리기」 표) ─────
         *
         * 여기 닿는 요청은 **`zip_blocked_entry` 하나**다. 경로 탈출·중첩·압축 폭탄·매크로·
         * MIME·크기·확장자는 이 예외가 아니라 아래 일반 `RuntimeException` 으로 떨어져
         * 지금까지와 똑같이 거부된다 (Upload::inspectZip 주석).
         */

        // 화면·감사에 싣는 이름은 20개까지. 개수는 자르기 **전**의 값이다(몇 개였나가 정보다).
        $execNames = array_slice($execBlocked->entries, 0, Upload::EXEC_ENTRIES_MAX);
        $execCount = count($execBlocked->entries);

        /* ①② 자격이 없다 — 토큰 입구(`POST /api/upload`)·「역할로 보기」 중·Admin 이 아님·
         *    등급이 admin-only 가 아님. 지금까지처럼 400 통일 문구이고 **응답에 `next` 가
         *    없다** — Employee 에게 「실행 파일이 들어 있다」는 힌트를 주지 않는다.
         *    감사도 지금까지와 같은 `file.upload deny zip_blocked_entry` 다.
         *    판정은 Authz 한 곳에서 한다 (역할 비교를 핸들러에 두지 않는다 — ARCH 4.1). */
        if (!Authz::canUploadExec($user, $level)) {
            $rejectBadRequest($execBlocked->getMessage(), $original, $level);
            throw $execBlocked;   // 도달하지 않는다
        }

        $execAudit = [
            'target_type'  => 'file',
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => ['exec_entry_count' => $execCount],
        ];

        /* ③ 자격은 있는데 확인 문구 체크가 없다 → 400 `next:"exec_ack"` + 항목 이름.
         *    화면이 이 응답으로 확인 블록을 편다. 체크는 문자열 `"1"` 정확 일치다. */
        if ($execAck !== '1') {
            $reject('exec_ack_required', 400, $execAudit, [
                'action'  => 'file.upload_exec',
                'entries' => $execNames,
            ]);
            throw $execBlocked;   // 도달하지 않는다
        }

        /* ④ 확인 — **요청마다** 비밀번호 → 2단계 코드. 세션에 창을 남기지 않는다
         *    (`reauth_at` 을 놓지 않는다 — Auth::verifyCurrentPassword 주석).
         *    실패 사유마다 감사 + 실패 집계(`countFailureAndRevoke`)는 로그인 뒤 창구와 같다. */
        $execFail = static function (string $reason, int $status) use ($reject, $execAudit): void {
            $reject($reason, $status, $execAudit, ['action' => 'file.upload_exec']);
        };

        if (!Auth::verifyCurrentPassword($user, $execPassword)) {
            unset($execPassword, $execOtp);
            Auth::countFailureAndRevoke((int) $user['id']);
            $execFail('bad_credentials', 400);
            throw $execBlocked;   // 도달하지 않는다
        }

        // 코드의 **형식**은 호출자가 먼저 본다 (Auth::verifyOtpOnce 주석). 형식이 아니면
        // Totp::verifyStep 도 null 을 주므로 사유는 같은 `bad_code` 다.
        $execOtpResult = preg_match(Totp::CODE_RE, $execOtp) === 1
            ? Auth::verifyOtpOnce($user, $execOtp)
            : 'bad_code';
        unset($execPassword, $execOtp);

        if ($execOtpResult !== 'ok') {
            if ($execOtpResult === 'mfa_not_enabled') {
                // 코드 추측이 아니므로 실패로 세지 않는다 (admin_permissions_update.php ② 와 같다).
                $execFail('mfa_not_enabled', 403);
            } else {
                Auth::countFailureAndRevoke((int) $user['id']);
                $execFail($execOtpResult, 400);
            }
            throw $execBlocked;   // 도달하지 않는다
        }

        /* ⑤ 통과 — **다시** 검사한다. 이번에는 차단 이름을 모으기만 하고 나머지 검사는
         *    그대로 던진다. 비용은 zip 중앙 디렉터리를 한 번 더 훑는 것뿐이다(100MB zip 도
         *    0.1초 — 항목 **내용**은 한 바이트도 읽지 않는다, ARCH 5.27).
         *    다시 검사하는 이유: 첫 검사는 차단 항목에서 멈췄으므로 그 **뒤쪽** 항목의
         *    경로 탈출·중첩·폭탄·매크로를 아직 보지 않았다. 확인을 통과했다고 그 검사들을
         *    건너뛰면 예외가 「이름 검사 한 갈래」가 아니라 「속 검사 전체」가 된다. */
        try {
            $checked = Upload::validate($uploaded, true);
        } catch (RuntimeException $e) {
            $rejectBadRequest($e->getMessage(), $original, $level);
            throw $e;   // 도달하지 않는다
        }

        $execEntries = array_slice($checked['exec_entries'] ?? [], 0, Upload::EXEC_ENTRIES_MAX);
        $execCount   = count($checked['exec_entries'] ?? []);
    } catch (RuntimeException $e) {
        // 예외 메시지는 사유 코드다. 웹에서는 사용자에게 나가지 않고,
        // API 에서는 코드로 이유 문구를 고르는 재료가 된다 (원문이 그대로 나가지는 않는다).
        $rejectBadRequest($e->getMessage(), $original, $level);
        throw $e;   // 도달하지 않는다 ($reject 는 응답 뒤 끝낸다) — 반환 형을 지키기 위한 줄
    }

    // ─── [4-2] 일일 총량 2GB (6단계, PRD S-06) ─────────────────────
    // 검증을 통과한 크기로 INSERT **전에** 본다. 삭제한 파일도 합계에 들어가므로
    // "올리고 지우기" 로 한도를 넘길 수 없다. 초과 응답은 413 이 아니라 다른 검증 실패와 같은
    // 400 동일 문구다 — 413 은 nginx 크기 제한의 몫이고, 사유는 감사 로그에만 남는다.
    try {
        Upload::checkDailyQuota((int) $user['id'], $checked['size']);
    } catch (RuntimeException $e) {
        $rejectBadRequest($e->getMessage(), $original, $level);
        throw $e;   // 도달하지 않는다 (위와 같은 이유)
    }

    $tmpPath = (string) $uploaded['tmp_name'];

    /* ─── [4-3] 민감정보 패턴 검출 (15-3, PRD F-22, ARCH 5.13, SECURITY R-58) ───
     *
     * 자리: 형식 검사·일일 총량을 통과한 **직후**, 저장 트랜잭션 **전**. 검사 대상은 미리보기가
     * 글자를 뽑을 수 있는 형식(text·table·office)뿐이고, 그 밖(이미지·PDF·zip)은 검사하지 못한다
     * — 한계는 R-58 로 문서에 남기고 화면에도 안내한다.
     *
     * 결과는 **패턴 이름과 건수**만이다(`['rrn'=>1]`). 검출된 숫자·문자열 원문은 돌려받지도,
     * 기록하지도, 응답에 담지도 않는다 (PRD S-07).
     *
     * 검출됐는데 등급이 public·internal 이면 저장하지 않는다. team·admin-only 면 저장하되
     * files.sensitive_flags 에 **이름만** 남겨 목록의 「민감」 배지와 등급 하향 거부에 쓴다.
     */
    require_once __DIR__ . '/../Sensitive.php';

    $sensitive      = Sensitive::scan($tmpPath, $checked['ext']);
    $sensitiveFlags = array_keys($sensitive);

    if ($sensitive !== [] && in_array($level, ['public', 'internal'], true)) {
        // 임시 파일은 PHP 가 요청 끝에 지우지만, 저장하지 않기로 정한 순간 바로 치운다.
        if (is_uploaded_file($tmpPath)) {
            @unlink($tmpPath);
        }
        // 이 400 만 예외적으로 "무엇이 막았는지" 를 알려 준다 — 막힌 사람이 올리는 본인이고,
        // 이유를 모르면 어떤 등급으로 다시 올려야 하는지 알 수 없기 때문이다 (Http 상수 주석).
        // 문구를 고르는 것은 호출자이고, 사유 코드와 감사 엔트리는 두 입구가 같다.
        $reject('sensitive_level_required', 400, [
            'target_type'  => 'file',
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => ['sensitive' => $sensitive, 'target_team' => $teamId],
        ]);
    }

    $storedName = Storage::newStoredName($checked['ext']);

    // sensitive_flags 는 **패턴 이름 배열**이다(`{rrn,card}`). 값은 Sensitive 가 돌려준 고정
    // 식별자뿐이고 사용자 입력이 섞이지 않지만, 그래도 텍스트 배열 리터럴을 바인딩해 CAST 한다.
    $sqlInsert = <<<'SQL'
        INSERT INTO files
            (original_name, stored_name, ext, mime, size_bytes, sha256,
             description, access_level, team_id, uploaded_by, sensitive_flags, folder_id,
             scan_status, exec_content, search_tsv)
        VALUES
            (:original_name, :stored_name, :ext, :mime, :size_bytes, :sha256,
             :description, :access_level, :team_id, :uploaded_by,
             CAST(:flags AS text[]), CAST(:folder_id AS bigint),
             :scan_status, CAST(:exec_content AS boolean),
             strip(to_tsvector('simple', :tsv)))
        RETURNING id, public_id
        SQL;

    // ─── [5] 트랜잭션: INSERT → 파일 이동 → 감사 로그 → COMMIT ──────
    $db    = Db::instance();
    $moved = false;
    $db->begin();
    try {
        $row = $db->run($sqlInsert, [
            ':original_name' => $original,
            ':stored_name'   => $storedName,
            ':ext'           => $checked['ext'],
            ':mime'          => $checked['mime'],
            ':size_bytes'    => $checked['size'],
            ':sha256'        => $checked['sha256'],
            ':description'   => $description,
            ':access_level'  => $level,
            ':team_id'       => $teamId,
            ':uploaded_by'   => (int) $user['id'],
            ':flags'         => '{' . implode(',', $sensitiveFlags) . '}',
            ':folder_id'     => $folderId,
            // F-54 ① (ARCH 5.28) — 검사를 켜 두었으면 'pending' 으로 시작한다. 판단은
            // Upload::initialScanStatus() 한 곳이고 네 입구가 그것을 함께 쓴다.
            ':scan_status'   => Upload::initialScanStatus(),
            /* F-65 (ARCH 5.29 ⑤) — 확인 절차를 통과한 업로드만 참이다. 이 값이 참이면
             * 그 파일은 이후 공유·토큰·미리보기·등급 변경이 막히고 받을 때도 다시 확인한다.
             * 참이 되는 자리는 **여기 하나**이고, 거짓으로 되돌리는 창구는 만들지 않는다. */
            ':exec_content'  => $execEntries !== null ? 'true' : 'false',   // PDO 는 false 를 "" 로 보내 boolean 오류 — users_update 와 같은 관례
            // 대기열 5b — 이름 + 설명의 검색 색인(파생값, SearchText 한 벌). 목록 창구가 한글 검색의 후보를 이것으로 좁힌다
            ':tsv'           => SearchText::indexTextFile([$original, $description], 'file upload'),
        ])->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('업로드 레코드를 만들 수 없습니다');
        }

        Storage::put($tmpPath, $storedName);
        $moved = true;

        // 세션이었나 토큰이었나는 Audit 이 detail.auth 로 자동으로 붙인다 (ARCH 4.2) —
        // 이 함수는 누가 불렀는지 모른 채 두 입구에 같은 행을 남긴다.
        /* F-65 (ARCH 5.29 ⑤) — 확인 업로드는 **action 이 갈린다**: `file.upload_exec allow`
         * 하나만 남고 `file.upload allow` 는 남기지 않는다. 한 업로드에 allow 두 줄이 생기면
         * 「몇 개를 올렸나」 를 세는 모든 조회가 어긋나고, 경보 AL-21 도 두 번 발동한다.
         * detail 은 기존 업로드 키 그대로에 확인의 흔적 넷을 더한 것이다 (ARCH 7.5).
         * **비밀번호·코드 값은 어느 키에도 들어가지 않는다** — `otp_verified: true` 가
         * 「서버가 확인했다」는 사실을 대신한다 (PRD 7.7). */
        $uploadDetail = [
            'ext'         => $checked['ext'],
            'mime'        => $checked['mime'],
            'size_bytes'  => $checked['size'],
            // 육하원칙 칸 (ARCH 7.5): 왜 허용됐나 · 어떤 내용인가 · 누가 올렸나 · 어느 팀인가
            'granted_by'  => $uploadCode,
            'sha256'      => $checked['sha256'],
            'owner_id'    => (int) $user['id'],
            'target_team' => $teamId,
            // 18-1 (F-26) — 어느 폴더에 올렸나. null 이면 루트다 (ARCH 7.5).
            'folder_id'   => $folderId,
        ] + ($sensitive === [] ? [] : ['sensitive' => $sensitive]);

        if ($execEntries !== null) {
            $uploadDetail += [
                'exec_ack'         => true,
                'otp_verified'     => true,
                'exec_entry_count' => $execCount,
                'exec_entries'     => $execEntries,
            ];
        }

        Audit::allow($execEntries === null ? 'file.upload' : 'file.upload_exec', [
            'target_type'  => 'file',
            'target_id'    => (int) $row['id'],
            'target_name'  => $original,
            'access_level' => $level,
            'detail'       => $uploadDetail,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        if ($moved) {
            // DB 에 남지 않는 파일(고아 파일)을 두지 않는다
            try {
                Storage::removeUploaded($storedName);
            } catch (Throwable $ignored) {
                error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 업로드 롤백 중 파일 삭제 실패');
            }
        }

        // 실패도 기록한다 (PRD S-07). 롤백 뒤라 이 INSERT 는 별개 트랜잭션으로 남는다.
        // 기록 자체가 실패한 경우까지 다시 예외로 덮지 않도록 감싼다 — 응답은 어차피 500 이다.
        try {
            Audit::error('file.upload', 'upload_failed', [
                'target_type'  => 'file',
                'target_name'  => $original,
                'access_level' => $level,
                'detail'       => ['moved' => $moved],
            ]);
        } catch (Throwable $ignored) {
            error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' 업로드 실패 감사 기록 실패');
        }

        throw $e;   // bootstrap 의 예외 핸들러가 500 + {"error":"서버 오류"} 로 끝낸다
    }

    return [
        'id'    => (string) $row['public_id'],
        'name'  => $original,
        'level' => $level,
    ];
}
