<?php
declare(strict_types=1);

/**
 * src/Http.php — 요청 파싱과 응답 통일 (PRD S-01 실패 응답, S-09-3, S-09-5, S-09-7)
 *
 * 오류 본문은 언제나 {"error":"..."} 한 가지 모양이다.
 * 특히 401/403/404 는 대상에 대한 어떤 정보도 담지 않는다
 * (파일이 있는데 권한이 없는 것인지, 아예 없는 것인지 구분되면 그것만으로 정보 유출이다).
 * 문구는 아래 상수뿐이다 — 예외 메시지·DB 오류·경로가 본문에 섞여 나가는 경로를 만들지 않는다.
 * 400 의 사유 문장은 reasonMessage() 표(ARCH 5.30)에서만 나온다.
 */
final class Http
{
    /** 권한 관련 실패에 쓰는 단일 문구 (PRD 7.1 / Q-6 결정) */
    public const MSG_FORBIDDEN    = '권한이 없습니다';
    public const MSG_UNAUTHORIZED = '로그인이 필요합니다';
    public const MSG_NOT_FOUND    = '찾을 수 없습니다';
    public const MSG_BAD_REQUEST  = '잘못된 요청입니다';
    /**
     * 이미 있는 이름과 부딪힌 요청 (12-3 팀 생성·이름 변경, PRD 8장 `/api/org/teams` 행의 409).
     * 대상에 대한 정보가 아니라 **요청한 사람이 방금 적은 이름**이 겹쳤다는 사실만 알린다 —
     * 팀 이름은 이 화면(Admin 전용)에서 이미 전부 보이는 값이라 새어 나가는 정보가 없다.
     */
    public const MSG_CONFLICT     = '이미 있는 이름입니다';

    /**
     * 비어 있지 않은 폴더를 지우려 한 요청 (18-1 folders_delete, PRD F-26, ARCH 5.16).
     *
     * 이 문구들도 예외적으로 "무엇이 막았는지" 를 알려 준다 — 여기까지 온 사람은 **그 폴더를
     * 지울 수 있는 사람**(만든 사람·Manager·Admin, `decideFolderChange` 를 이미 통과)이고,
     * 이유를 모르면 무엇을 먼저 치워야 하는지 알 수 없기 때문이다(MSG_SENSITIVE_LEVEL_REQUIRED
     * 와 같은 성격). **몇 개가 남았는지·어떤 파일인지는 담지 않는다** — 그 사람이 볼 수 없는
     * 파일이 남아 있을 수도 있고, 개수 자체가 정보가 된다 (SECURITY R-63).
     *
     * ★ 26단계 (PRD F-41, 사용자 지적 2026-09-15 「빈 폴더인데 안 지워진다」) — 한 문구였던 것을
     *   **막는 것별로** 셋으로 갈랐다. 전에는 하위 폴더·살아 있는 파일·휴지통 파일이 전부 같은
     *   문구라, 무엇을 치워야 지워지는지 알 수 없었다. 특히 휴지통 파일은 사용자가 없앨 방법이
     *   없어(30일 배치가 지울 때까지) 폴더가 한 달간 잠겼다.
     */
    public const MSG_FOLDER_HAS_CHILDREN = '하위 폴더가 있습니다 — 먼저 지우세요';
    public const MSG_FOLDER_NOT_EMPTY    = '파일이 있는 폴더는 지울 수 없습니다';
    public const MSG_FOLDER_HAS_TRASHED  = '휴지통에 있는 파일만 남았습니다 — 확인하면 파일을 루트로 옮기고 폴더를 지웁니다';

    /**
     * URL 로 가져오기(F-14) 실패 — 스킴·포트·호스트·리다이렉트·크기·검증·속도 제한이
     * **전부 이 문구 하나**다 (PRD S-05 ⑩ "응답 비노출"). 사유는 감사 로그에만 남고,
     * 상태 코드만 400 / 403 / 429 로 갈린다. 본문으로 사유가 갈라지면 그 자체가
     * "내부에 무엇이 있는지" 를 알려 주는 신호가 된다.
     */
    public const MSG_FETCH_FAILED = '가져올 수 없습니다';

    /** 로그인 실패 — 아이디 없음·비밀번호 틀림·비활성 계정이 모두 이 문구 하나다 (PRD 10장) */
    public const MSG_LOGIN_FAILED = '아이디 또는 비밀번호가 올바르지 않습니다';
    /** 계정 잠금 (PRD F-01, S-09-4, ARCH 4.0) */
    public const MSG_LOGIN_LOCKED = '로그인 시도가 너무 많습니다. 잠시 후 다시 시도하세요';
    /**
     * 순차 2단계 로그인의 1단계 응답 (ARCH 4.0 "2단계 인증 로그인 흐름").
     * **비밀번호가 맞은 사람에게만** 나간다 — 비밀번호가 틀리면 MSG_LOGIN_FAILED 이고
     * 그 계정이 2단계 인증을 쓰는지 알 수 없다(계정 정보 노출 방지).
     */
    public const MSG_MFA_REQUIRED = '2단계 인증 코드가 필요합니다';

    /**
     * T:F-72 지문·얼굴 로그인(패스키) 문구 (지시서 1.9).
     *   MSG_PASSKEY_REVOKED     — 실패 응답 가운데 **유일하게** 문장이 다른 것. 서명이 맞은 사람(그 휴대폰을 쥔 사람)에게만 간다
     *   MSG_PASSKEY_UNAVAILABLE — 설정(APP_URL)이 https 이름·시험용 localhost 가 아니거나 표가 없을 때(503 — 닫힌 쪽)
     *   MSG_PASSKEY_BUSY        — 살아 있는 로그인 챌린지가 상한(5,000)을 넘었을 때(503)
     *   MSG_PASSKEY_LIMIT       — 한 사람 5개 · MSG_PASSKEY_DUPLICATE — 같은 휴대폰 두 번 · MSG_PASSKEY_REGISTER_FAILED — 그 밖의 등록 실패
     */
    public const MSG_PASSKEY_REVOKED         = '이 휴대폰의 지문·얼굴 로그인은 꺼져 있습니다(해제했거나 90일 동안 쓰지 않음). 비밀번호로 로그인한 뒤 「내 계정」에서 다시 등록하세요.';
    public const MSG_PASSKEY_UNAVAILABLE     = '지금은 지문·얼굴 로그인을 쓸 수 없습니다. 비밀번호로 로그인하세요.';
    public const MSG_PASSKEY_BUSY            = '잠시 뒤 다시 시도하세요';
    public const MSG_PASSKEY_LIMIT           = '지문·얼굴 로그인 기기는 한 사람 5개까지입니다 — 먼저 하나를 해제하세요';
    public const MSG_PASSKEY_DUPLICATE       = '이 휴대폰은 이미 등록되어 있습니다';
    public const MSG_PASSKEY_REGISTER_FAILED = '이 휴대폰을 등록하지 못했습니다. 창을 닫고 처음부터 다시 해 보세요';

    /**
     * 최초 로그인 강제 절차의 관문 문구 (11단계, PRD F-16, ARCH 4.0 「최초 로그인 강제 절차」).
     *
     * 이 둘만 예외적으로 "무엇이 막았는지" 를 알려 준다 — 막힌 사람이 **본인**이고,
     * 본인이 다음에 무엇을 해야 하는지(`next`)를 모르면 절차를 끝낼 수 없기 때문이다.
     * 다른 403 과 달리 대상에 대한 정보가 아니라 **자기 계정의 상태**만 담는다.
     */
    public const MSG_PASSWORD_CHANGE_REQUIRED = '비밀번호를 먼저 변경해야 합니다';
    public const MSG_MFA_SETUP_REQUIRED       = '2단계 인증을 먼저 설정해야 합니다';

    /**
     * 민감정보 검출 문구 (15-3, PRD F-22, ARCH 5.13, SECURITY R-58).
     *
     * 이 문구도 "무엇이 막았는지" 를 알려 준다 — 막힌 사람이 **그 파일을 올리는 본인**이고,
     * 이유를 모르면 어떤 등급으로 다시 올려야 하는지 알 수 없기 때문이다. 대상·다른 사람에
     * 대한 정보는 한 글자도 담지 않고, **검출된 값(숫자 원문)도 담지 않는다** — 응답에도
     * 감사 로그에도 나가는 것은 패턴 이름과 건수뿐이다 (PRD S-07).
     */
    public const MSG_SENSITIVE_LEVEL_REQUIRED = '민감정보가 보여 team 또는 admin-only 로만 올릴 수 있습니다';

    /**
     * 악성코드 검사 상태로 막힌 읽기 (F-54, PRD F-54 ③, ARCH 5.28 · 5.3 [4]).
     * 이 둘도 "무엇이 막았는지" 를 알려 준다 — 여기까지 온 사람은 **그 파일을 읽을 자격이
     * 이미 있는 사람**(권한 판정을 통과했다)이고, 「잠시 뒤 다시」인지 「관리자에게 문의」인지를
     * 모르면 다음에 무엇을 할지 정할 수 없기 때문이다. 파일 이름·시그니처는 한 글자도 담지
     * 않는다 — 그 값들은 감사 로그에만 있다 (PRD S-07).
     */
    public const MSG_SCAN_PENDING  = '검사 중입니다. 잠시 뒤 다시 시도하세요';
    public const MSG_SCAN_INFECTED = '악성코드가 발견되어 격리된 파일입니다';

    /**
     * 「검사 중」 거부에 실어 보내는 재시도 간격(초) — F-57, ARCH 5.28, SECURITY R-86.
     *
     * 감사 로그 실측(업로드 → `file.scan`)이 21~76초, 중앙값 25초다 — 배치가 매분 돌고
     * `clamscan` 이 약 22초 걸리기 때문이다. 그래서 30초로 잡았다(30초 간격 4번이면 2분).
     * 이 값은 `Http::locked()` 로만 나가고, `infected`·권한 없음(403)에는 붙지 않는다.
     */
    public const SCAN_RETRY_AFTER_SECONDS = 30;

    /**
     * 관리 기능 재인증 관문의 문구 (16-1, PRD F-23, ARCH 4.0 「관리 기능 재인증 — sudo 모드」).
     *
     * 강제 절차 관문의 두 문구와 같은 성격이다 — 막힌 사람이 **본인**이고, 다음에 무엇을
     * 해야 하는지(`next: "reauth"`)를 모르면 관리 화면을 열 수 없다. 대상·권한에 대한
     * 정보는 담지 않는다(권한이 없어서 막힌 경우는 이 관문보다 **앞**의 authz.route 403 이고
     * 거기서는 언제나 MSG_FORBIDDEN 동일 문구다 — 재인증은 권한을 만들지 않는다).
     */
    public const MSG_REAUTH_REQUIRED = '관리 기능을 쓰려면 비밀번호를 다시 확인해야 합니다';

    /**
     * 개인 토큰 전용 창구의 문구 (F-59, PRD F-59 ⑤, ARCH 4.6 판정 순서 표 마지막 줄).
     *
     * 삭제 창구 둘(`DELETE /api/files/{id}`·`DELETE /api/folders/{num}`)은 **개인 토큰만**
     * 통한다. 공용 토큰(`.env`)으로 부르면 이 문구의 403 이다 — 권한이 모자란 것이 아니라
     * **자격의 종류**가 맞지 않는 것이라, 통일 문구 「권한이 없습니다」 대신 부르는 쪽이
     * 고칠 수 있는 말을 준다(부르는 쪽은 토큰을 쥔 우리 프로그램이다 — ARCH 4.2 「오류 문구 정책」).
     * 어느 계정·어느 토큰인지는 담지 않는다.
     */
    public const MSG_PERSONAL_TOKEN_REQUIRED = '이 창구는 개인 토큰으로만 쓸 수 있습니다';

    /**
     * F-66 — **만료된** 개인 토큰의 문구 (PRD F-66 ④, ARCH 4.7 「만료」 행).
     *
     * 틀린 토큰의 401 「API 토큰이 올바르지 않습니다」 와 **일부러 다르다**. 회수는
     * 관리자·본인의 결정이라 숨기지만(회수된 토큰은 틀린 토큰과 같은 답이다), 만료는
     * 시간의 결과라 숨길 이유가 없다 — 그리고 이 문구를 받는 쪽은 토큰을 쥔 우리
     * 프로그램이라, 무엇을 하면 되는지(새로 발급)를 알려 주는 것이 맞다
     * (ARCH 4.2 「오류 문구 정책」, MSG_PERSONAL_TOKEN_REQUIRED 와 같은 자리).
     * 어느 계정·어느 토큰인지, 언제 만료됐는지는 담지 않는다.
     */
    public const MSG_TOKEN_EXPIRED = '만료된 API 토큰입니다 — 내 계정에서 새로 발급하세요';

    /**
     * F-66 — 토큰의 **범위 밖 창구**의 문구 (PRD F-66 ③, ARCH 4.7 「창구 → 필요한 범위」 표).
     *
     * 위 두 문구와 같은 성격이다: 권한이 모자란 것이 아니라 **그 토큰의 범위**가 좁은
     * 것이고, 부르는 쪽이 고칠 수 있는 말을 준다(더 넓은 범위로 새로 발급). 계정 권한이
     * 모자라 막히는 것은 이보다 **뒤**의 `authz.route` 403 이고 거기서는 언제나 통일 문구
     * 「권한이 없습니다」 다 — 범위는 권한을 만들지 않는다.
     * 어느 범위였는지·무엇이 필요한지는 담지 않는다(요청자는 자기 토큰의 범위를
     * 「내 계정」 목록에서 볼 수 있다).
     */
    public const MSG_TOKEN_SCOPE = '이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요';

    /**
     * 실행 파일이 든 압축본의 **확인 관문** 문구 (F-65, PRD F-65 ②③, ARCH 5.29).
     *
     * 통일 문구(`MSG_BAD_REQUEST`·`MSG_FORBIDDEN`)를 쓰지 않는 자리다. 이유는 강제 절차
     * 관문(`MSG_PASSWORD_CHANGE_REQUIRED`)·재인증 관문(`MSG_REAUTH_REQUIRED`)과 **같다**:
     * 막힌 사람이 그 파일을 올리는 본인 또는 그 파일을 받을 자격이 이미 있는 Admin 이고,
     * 「다음에 무엇을 해야 하나」(`next: "exec_ack"`)를 모르면 아무것도 할 수 없다.
     *
     * ★ 이 문구가 나가는 자리는 **자격을 이미 통과한 요청**뿐이다 (ARCH 5.29 「올리기」 ③ ·
     *   「받기」 표). Admin 이 아니거나 등급이 admin-only 가 아니면 그보다 **앞**에서
     *   통일 문구 400 으로 끝난다 — 「실행 파일이 들어 있다」는 힌트조차 주지 않는다 (②).
     */
    public const MSG_EXEC_ACK_REQUIRED = '실행 파일이 든 압축본입니다 — 비밀번호와 2단계 인증 코드로 확인해야 합니다';

    /**
     * 어떤 사유 코드에도 절대 문장을 붙이지 않는다 — 오라클 차단 (ARCH 5.30 예외 목록, R-48·R-45).
     * REASON_MESSAGES 에 누가 넣어도 이 목록이 이긴다.
     */
    public const REASON_UNIFORM = ['bad_request', 'bad_credentials', 'bad_code', 'code_reused', 'same_password', 'weak_password'];

    /**
     * 400 거부의 사유 코드 → 사람 문장 (PRD F-68 · S-09-12, ARCH 5.30 표가 원본).
     *
     * 표에 없는 코드는 MSG_BAD_REQUEST 로 떨어진다. 403·404·423·429 자리에서는 부르지 않는다
     * (그 자리는 MSG_FORBIDDEN·MSG_NOT_FOUND·MSG_SCAN_* 동일 문구다 — S-09-5).
     * 문장 규칙(S-09-12): 「요청자가 방금 보낸 값의 어디가 규칙에 맞지 않나」만 담고
     * 대상 이름·다른 사람·개수·서버 경로·SQL·예외 문구는 담지 않는다 (R-63).
     */
    public static function reasonMessage(string $reason): string
    {
        if (in_array($reason, self::REASON_UNIFORM, true)) {
            return self::MSG_BAD_REQUEST;
        }
        if ($reason === 'ext_not_allowed') {
            // 허용 목록은 코드가 만든다 — 손으로 베끼면 목록이 바뀌는 날 어긋난다
            return '허용되지 않는 확장자입니다 (허용: ' . implode(' ', Upload::ALLOWED_EXT) . ')';
        }
        return self::REASON_MESSAGES[$reason] ?? self::MSG_BAD_REQUEST;
    }

    /**
     * ARCH 5.30 표 — 글자 그대로 옮긴 것이다. 원본은 그 표이고, 둘이 다르면 표를 먼저 고친다
     * (T-81 (a) 가 이 표와 ARCH 5.30 을 글자 대조한다).
     * 마지막 다섯 줄은 이미 있던 상수를 가리킨다 — 문구를 두 벌로 만들지 않는다.
     *
     * @var array<string,string>
     */
    private const REASON_MESSAGES = [
        'team_has_members'     => '활성 구성원이 있는 팀은 비활성화할 수 없습니다 — 먼저 구성원을 다른 팀으로 옮기세요',
        'already_exists'       => '이미 있는 아이디입니다',
        'self_change'          => '자기 자신에게는 쓸 수 없습니다 — 내 계정 화면을 이용하세요',
        'last_admin'           => '마지막 활성 관리자는 강등하거나 비활성화할 수 없습니다',
        'depth_exceeded'       => '폴더는 5단계까지만 만들 수 있습니다',
        'cycle'                => '폴더를 자기 자신이나 그 하위 폴더 안으로 옮길 수 없습니다',
        'parent_narrower'      => '파일이나 폴더의 등급이 상위 폴더의 등급보다 넓을 수 없습니다',
        'children_exceed'      => '하위에 이 등급보다 넓은 폴더나 파일이 있습니다 — 「하위 항목도 함께」를 켜거나 먼저 정리하세요',
        'not_owner'            => '하위에 내가 바꿀 수 없는 폴더나 파일이 있어 함께 좁힐 수 없습니다',
        'bad_encoding'         => '파일 이름 또는 설명이 올바른 문자(UTF-8)가 아닙니다',
        'description_too_long' => '설명은 500자까지입니다',
        'bad_access_level'     => 'level 값이 올바르지 않습니다 (public·internal·team·admin-only)',
        'bad_team'             => 'team 등급은 team_id(활성 팀 번호)가 필요합니다',
        'bad_folder'           => 'folder_id 가 없거나 접근할 수 없는 폴더입니다',
        'no_change'            => '바뀐 내용이 없습니다',
        'exec_content'         => '실행 파일이 든 압축본은 공유 링크를 만들거나 등급을 바꿀 수 없습니다',
        'no_file'              => 'file 필드에 파일이 없습니다',
        'upload_error'         => '업로드 요청의 형식이 올바르지 않습니다 (multipart/form-data 로 file 하나를 보냅니다)',
        'not_uploaded_file'    => '정상적인 HTTP 업로드가 아닙니다',
        'empty_file'           => '빈 파일(0바이트)은 올릴 수 없습니다',
        'size_unreadable'      => '파일 크기를 읽을 수 없습니다',
        'hash_failed'          => '파일의 무결성 값을 계산할 수 없습니다',
        // 'ext_not_allowed' 는 reasonMessage() 안에서 Upload::ALLOWED_EXT 로 만든다 (ARCH 5.30 문장 규칙 ③)
        'double_extension'     => '이중 확장자 파일은 올릴 수 없습니다',
        'mime_mismatch'        => '파일 내용이 확장자와 다릅니다',
        'too_large'            => '파일이 100MB 를 넘습니다',
        'quota_exceeded'       => '오늘 업로드 총량(2GB)을 넘었습니다',
        'zip_blocked_entry'    => '압축본 안에 실행 파일이나 스크립트가 있어 올릴 수 없습니다',
        'zip_bad_path'         => '압축본 안에 올바르지 않은 경로(상위 폴더로 나가는 경로·절대 경로)가 있습니다',
        'zip_bomb'             => '압축본을 풀었을 때의 크기나 항목 수가 한도를 넘습니다',
        'zip_unreadable'       => '압축본을 읽을 수 없습니다 — 손상됐거나 지원하지 않는 형식입니다',
        'nested_zip'           => '압축본 안에 다른 압축본이 있어 올릴 수 없습니다',
        'macro_found'          => '문서에 매크로가 들어 있어 올릴 수 없습니다',
        'pdf_active_content'   => 'PDF 에 실행되는 내용(자바스크립트·자동 실행)이 있어 올릴 수 없습니다',
        'hwp_not_hwp'          => 'HWP 형식이 아닌 파일입니다',
        // F-66 (2026-09-22) — `target_inactive` 는 여기서 **빠졌다**: 발급이 본인 창구로
        // 옮겨져 닿을 수 없는 코드가 되었다(본인은 세션이 있으니 활성이다). ARCH 5.30 표에도
        // 없다. 7.4 의 문장 틀은 옛 감사 행이 그대로 보이도록 Audit 에 남아 있다.
        'limit_exceeded'       => '살아 있는 토큰이 상한에 이르렀습니다 — 먼저 하나를 회수하세요',
        'invariant'            => '바꿀 수 없는 항목이거나 규칙에 어긋나는 값입니다',
        'already_enabled'      => '2단계 인증이 이미 켜져 있습니다',
        'sensitive_level_required' => self::MSG_SENSITIVE_LEVEL_REQUIRED,
        'folder_has_children'      => self::MSG_FOLDER_HAS_CHILDREN,
        'folder_not_empty'         => self::MSG_FOLDER_NOT_EMPTY,
        'folder_has_trashed'       => self::MSG_FOLDER_HAS_TRASHED,
        'exec_ack_required'        => self::MSG_EXEC_ACK_REQUIRED,
    ];

    /** JSON 본문 상한 — 로그인·삭제 같은 작은 요청만 JSON 으로 받는다 */
    private const MAX_JSON_BYTES = 65536;

    /**
     * JSON 본문의 **중첩 깊이** 상한 (기본값 — 지금까지와 같은 8).
     *
     * `json_decode` 의 깊이는 「가장 깊은 값까지의 칸 수 + 1」로 센다(실측 2026-09-22):
     * 커뮤니티 본문 `{doc:{blocks:[{text:[{m:{link:{href}}}]}]}}` 은 8칸이라 **9** 가 필요하고,
     * 8 로 두면 **링크 마크가 들어간 문서는 한 건도 저장되지 않는다**(관문 ② 실측 — 스키마는
     * 허용하는데 입구가 막는 두 번째 자리였다). 그래서 상한을 인자로 받는다.
     */
    private const MAX_JSON_DEPTH = 8;

    /**
     * 프로그램용 업로드 토큰 헤더의 **상한**(F-49, ARCH 4.2).
     * 토큰은 48자(scripts/api_token_rotate.sh)이고 `.env` 값도 32자 이상이면 된다.
     * 256자를 넘는 값은 비교조차 하지 않는다 — 긴 문자열을 hash_equals 에 계속 던져
     * CPU 를 태우는 경로를 만들지 않는다. 길이만 보고 버리므로 값의 내용은 읽지 않는다.
     */
    private const MAX_API_TOKEN_BYTES = 256;

    /**
     * 요청 헤더 `X-API-Token` — 프로그램용 업로드 `POST /api/upload` 전용 (PRD F-49, ARCH 4.2).
     *
     * ★ 이 함수를 부르는 곳은 `Auth::requireApiToken()` **하나**이고, 그 함수를 부르는 곳은
     *   라우터 [2] 의 `TBOX_ROUTE === 'POST /api/upload'` 가지 **하나**다. 그래서 다른 라우트는
     *   이 헤더를 **읽지도 않는다** — 토큰으로는 그 창구 밖의 어떤 기능도 열리지 않는다.
     *
     * 값은 여기서 **판단하지 않는다**(같다·다르다는 hash_equals 의 몫이다). 하는 일은
     * 공백을 떼고, 없거나 빈 값이면 null 을 돌려주고, 터무니없이 긴 값을 버리는 것뿐이다.
     * 토큰 값은 이 함수에서도 호출자에서도 **로그·감사·응답 어디에도 나가지 않는다**.
     */
    public static function apiTokenHeader(): ?string
    {
        $raw = $_SERVER['HTTP_X_API_TOKEN'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $token = trim($raw);
        if ($token === '' || strlen($token) > self::MAX_API_TOKEN_BYTES) {
            return null;
        }
        return $token;
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $code = 200): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** 오류 응답 후 즉시 종료한다. 호출한 쪽이 실수로 계속 진행하는 것을 막는다. */
    public static function error(int $code, string $message): void
    {
        self::json(['error' => $message], $code);
        exit;
    }

    /**
     * **일시 상태**로 막힌 요청 — 423 Locked + `Retry-After` (F-57, PRD F-57 ①, ARCH 5.28).
     *
     * `error()` 와 같은 자리에 두는 이유는 둘이 같은 갈래이기 때문이다 — 다른 것은 「지금은
     * 안 되지만 곧 된다」와 「앞으로도 안 된다」뿐이다. 403 은 「인증됐지만 허용되지 않음」이라
     * 재시도 신호가 없어서, 클라이언트가 검사 창(`scan_status='pending'`) 안의 거부를 영구
     * 실패로 처리했다(사용자 프로그램 실측 보고 2026-09-16). 상태 코드와 헤더로 갈라 두면
     * 한국어 문구가 바뀌어도 조용히 깨지지 않는다.
     *
     * `Retry-After` 는 `json()`(상태 코드·`Content-Type`·`Cache-Control: no-store`) **앞**에
     * 보낸다 — 본문을 내보낸 뒤에는 헤더를 더할 수 없다. 본문에도 `retry_after` 를 같은 값으로
     * 담아 헤더를 못 읽는 클라이언트도 대기 시간을 알 수 있게 한다. 대상에 대한 정보는 한
     * 글자도 담지 않는다(다른 오류 응답과 같다).
     */
    public static function locked(int $retryAfterSeconds, string $message): void
    {
        if (!headers_sent()) {
            header('Retry-After: ' . $retryAfterSeconds);
        }
        self::json(['error' => $message, 'retry_after' => $retryAfterSeconds], 423);
        exit;
    }

    public static function method(): string
    {
        $m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return preg_match('/^[A-Z]{3,7}$/', $m) === 1 ? $m : 'GET';
    }

    /**
     * t-box 모듈의 주소 접두 (Qaredi C, ARCH-launcher 2·3장).
     *
     * 값은 `src/bootstrap.php` 의 코드 상수 `TBOX_BASE` 하나다 — `.env` 도 요청 값도 아니다.
     */
    public static function base(): string
    {
        return TBOX_BASE;
    }

    /**
     * 모듈 경로(`/settings`)를 **내보낼 주소**(`/tbox/settings`)로 만든다 (ARCH-launcher 3장).
     *
     * 주소를 만드는 자리는 이 함수 하나다. 인자는 언제나 코드에 적힌 모듈 경로이며
     * `/` 로 시작해야 한다 — 시작하지 않으면(상대 경로·`http://…`·`//evil`) 예외다.
     * 접두를 붙이는 것 말고는 아무것도 하지 않는다: 요청 값으로 호스트·스킴을 만들지 않는다.
     */
    public static function url(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new RuntimeException('주소는 / 로 시작해야 합니다');
        }
        return TBOX_BASE . $path;
    }

    /**
     * 커뮤니티 모듈 경로(`/p/<uuid>`)를 **내보낼 주소**(`/community/p/<uuid>`)로 만든다
     * (커뮤니티 ARCHITECTURE.md 21.1).
     *
     * `url()` 과 같은 규칙의 짝이다 — 접두만 다르다. 커뮤니티 화면·핸들러가 만드는 절대
     * 주소는 전부 이 함수를 지난다. 인자는 언제나 코드에 적힌 모듈 경로이고 `/` 로 시작해야
     * 한다. 요청 값으로 호스트·스킴을 만들지 않는다.
     */
    public static function communityUrl(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new RuntimeException('주소는 / 로 시작해야 합니다');
        }
        return COMMUNITY_BASE . $path;
    }

    /**
     * 옛 t-box 주소 → 새 주소 **308 Permanent Redirect** (ARCH-launcher 1·2장).
     *
     * 왜 308 인가: 301·302 는 클라이언트가 POST 를 GET 으로 바꿀 수 있다. 308 은
     * **메서드와 본문을 보존**하므로 PC 업로드 프로그램이 옛 `/api/upload` 로 보내도
     * 리다이렉트를 따르는 클라이언트면 그대로 동작한다 (PRD Q:T-10).
     *
     * $location 은 **고정 접두 + 요청 경로·쿼리 원문**만이다. 요청 헤더(Host·Referer)로
     * 호스트·스킴을 만들지 않으므로 열린 리다이렉트가 아니고, 여기서 형태를 한 번 더 본다:
     * `/` 로 시작해야 하고 `//`(스킴 상대 주소)로 시작할 수 없으며 제어 문자(헤더 주입)가
     * 들어 있으면 예외다.
     *
     * `Cache-Control: no-store` 를 붙이지 않는다 — 영구 이동은 캐시돼도 되는 사실이고,
     * 리다이렉트 응답에는 본문도 세션도 없다. (302 를 보내는 redirect() 와 다른 점이다.)
     */
    public static function redirectPermanent(string $location): void
    {
        if ($location === '' || $location[0] !== '/'
            || strncmp($location, '//', 2) === 0
            || strlen($location) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $location) === 1
        ) {
            throw new RuntimeException('허용되지 않는 리다이렉트 대상입니다');
        }
        if (!headers_sent()) {
            http_response_code(308);
            header('Location: ' . $location);
        }
        exit;
    }

    /**
     * `<html>` 에 붙일 화면 밝기 속성 (DESIGN.md 2.12).
     *
     * 셸·로그인·런처 세 곳이 같은 규칙을 쓰므로 한 벌로 모은다. 쿠키 값은 허용 목록
     * 두 개(`dark`·`light`)만 통과하고 그 밖은 속성 자체를 그리지 않는다 — 그러면 CSS 가
     * OS 설정(prefers-color-scheme)을 따른다. 값은 상수 둘뿐이지만 규칙대로
     * htmlspecialchars 를 거친다 (PRD S-03).
     */
    public static function themeAttr(): string
    {
        $cookie = $_COOKIE['tbox_theme'] ?? '';
        return in_array($cookie, ['dark', 'light'], true)
            ? ' data-theme="' . htmlspecialchars((string) $cookie, ENT_QUOTES, 'UTF-8') . '"'
            : '';
    }

    /**
     * 요청 경로. 쿼리스트링을 떼고, 퍼센트 디코딩은 하지 않는다.
     * (디코딩하면 %2e%2e 같은 값이 경로 매칭을 통과할 여지가 생긴다)
     */
    public static function path(): string
    {
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        return $path;
    }

    public static function clientIp(): ?string
    {
        // 프록시 헤더(X-Forwarded-For)는 위조 가능하므로 읽지 않는다.
        // 실제 IP 복원은 nginx 가 신뢰하는 프록시 IP 에 대해서만 한다 (SECURITY R-30 · R-31).
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $ip;
    }

    /**
     * 요청을 보낸 도구 이름. **요청이 준 값이므로 언제나 위조 가능한 참고값**이다
     * (검토 행동 표식 `tbox-review/…` 도 그래서 "표식" 이지 인증이 아니다 — CLAUDE.md 3장).
     *
     * CLI(9단계 운영 배치)에는 HTTP 헤더가 아예 없다. null 로 두면 감사 로그에서 배치 행과
     * "UA 를 안 보낸 브라우저" 가 구별되지 않으므로, CLI 일 때만 `cli:스크립트명` 을 넣는다
     * (ARCH 7.5 route 키의 `cli:스크립트명` 과 같은 표기). 스크립트 이름은 서버 파일명이지
     * 사용자 입력이 아니고, 그래도 basename 으로 경로를 떼고 형태를 좁혀 쓴다.
     */
    public static function userAgent(): ?string
    {
        if (PHP_SAPI === 'cli') {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['argv'][0] ?? '')));
            $script = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $script);
            return $script === '' ? 'cli' : 'cli:' . substr($script, 0, 100);
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (!is_string($ua) || $ua === '') {
            return null;
        }
        return substr($ua, 0, 500);
    }

    /**
     * 화면 경로용 리다이렉트 (ARCH 4.0 "미로그인 처리": /api/* 는 401, 화면은 302 /login).
     *
     * $path 는 **코드에 적힌 고정 문자열만** 넘긴다. 사용자 입력을 넣을 수 없도록
     * 허용 형태(슬래시로 시작하는 소문자 경로)를 여기서 한 번 더 검사한다 —
     * 오픈 리다이렉트(`//evil.example`, `http://…`)가 생길 통로를 없앤다.
     *
     * 16-1 (PRD F-23 · T-36): 재인증 관문이 보내는 `/reauth?to=<키>` 때문에 허용 형태를
     * **최소로** 넓혔다 — 꼬리표는 `?to=` 뒤에 영문 소문자 1~16자 하나뿐이다(`&`·`=`·`%`·
     * 숫자·슬래시가 들어갈 자리가 없다). 그 자리에 들어가는 값은 요청에서 온 문자열이 아니라
     * 라우터의 고정 표(`TBOX_REAUTH_RETURN`)에 적힌 키(`users`·`org`·`audit`·`trash`·`wiki`)
     * 뿐이며, 표에 없으면 꼬리표 없이 `/reauth` 로 보낸다 — 요청 값은 Location 에 닿지 않는다.
     */
    public static function redirect(string $path): void
    {
        /* Q:F-7 B1-1 (T:F-72 라운드 A) — 로그인 화면으로 보낼 때 「보던 곳」을 꼬리표로 싣는다. 넓힌 모양은 **하나**다:
           `?to=` 뒤에 `returnPath()` 가 만든 값을 rawurlencode 한 글자(영숫자·`%`·`-_.~` — `&`·`=`·`/`·`?`·`:` 는 %XX 로만)이고,
           그 값은 아래 `redirect` 안에서 **한 번 더** 디코딩해 `returnPath()` 에 넣었을 때 **같은 값**이 나와야 한다(허용 목록 밖은 예외).
           옛 모양(`?to=` + 영문 소문자 1~16 — 재인증 키·로그인 키)은 그대로다. */
        $ok = preg_match('#^/[a-z0-9/_.-]*(\?to=[a-z]{1,16})?$#', $path) === 1;
        if (!$ok && preg_match('#^(/[a-z0-9/_.-]*)\?to=([A-Za-z0-9%._~-]{1,1800})$#', $path, $m) === 1) {
            $inner = rawurldecode($m[2]);
            $ok = self::returnPath($inner) === $inner;
        }
        if (!$ok || strpos($path, '//') !== false) {
            throw new RuntimeException('허용되지 않는 리다이렉트 경로입니다');
        }
        if (!headers_sent()) {
            http_response_code(302);
            header('Location: ' . $path);
            header('Cache-Control: no-store');
        }
        exit;
    }

    /**
     * Q:F-7 B1-1 — 이미 로그인한 사람을 `returnPath()` 가 만든 「보던 곳」으로 302 (login_page.php 한 곳이 부른다).
     * 값을 **다시** `returnPath()` 에 넣어 같은 값이 나올 때만 보낸다 — 허용 목록 밖이면 예외(부르는 쪽이 이미 거른 값이다).
     */
    public static function redirectReturn(string $canonical): void
    {
        if ($canonical === '' || self::returnPath($canonical) !== $canonical || strlen($canonical) > 600) {
            throw new RuntimeException('허용되지 않는 리다이렉트 경로입니다');
        }
        if (!headers_sent()) {
            http_response_code(302);
            header('Location: ' . $canonical);
            header('Cache-Control: no-store');
        }
        exit;
    }

    /** returnPath 의 길이 상한(바이트) — 디코딩 전 값 */
    public const RETURN_PATH_MAX = 600;

    /**
     * Q:F-7 B1-1 — 「로그인하면 보던 곳으로」의 **허용 목록**(`docs/qf7-small-conveniences-directive.md` 라운드 C 설계 1·2,
     * T:F-72 라운드 A). 화면 짝은 `public/assets/app.js` 의 같은 이름 함수이고, 두 쪽은 **같은 벡터 목록**
     * (`tests/lib/return_path_vectors.txt`)으로 시험한다 — 한쪽만 바뀌면 시험이 알린다.
     *
     * 받는 값: 주소 모양 문자열(경로 + 선택 쿼리, 쿼리 값은 퍼센트 인코딩 가능). 로그인 화면의 `$_GET['to']`(PHP 가 이미 한 번
     * 디코딩한 값)와 미로그인 화면 요청의 `REQUEST_URI` 원문이 들어온다.
     * 돌려주는 값: 허용 목록 안이면 **정규형**(경로 + 고정 순서의 허용 쿼리, 값은 rawurlencode) — 그 밖은 null(부르는 쪽이 첫 화면으로).
     *
     * 규칙(지시서 그대로)
     *   · 길이 ≤ 600 · 퍼센트 디코딩은 **한 번만**(쿼리 값을 풀 때 한 번 — 경로에는 `%` 자체가 허용되지 않는다)
     *   · 한 번 디코딩한 전체에 제어 문자(\x00-\x1f\x7f)·`\`·`//`·`..`·`@`·스킴(`이름:`)이 있으면 null · UTF-8 이 아니면 null
     *   · 경로는 아래 정규식 **정확 일치**만(startsWith 없음):
     *       /tbox/                    쿼리 folder(정수) · q(1~200자) · sort(name|created_at|size) · dir(asc|desc) · page(정수)
     *       /tbox/settings · /tbox/activity             쿼리 없음
     *       /community/               쿼리 space(정수 — 편집기 currentSpaceId 와 같은 모양)
     *       /community/p/<uuid 소문자>                   쿼리 edit=1 만
     *       /community/b/<정수>                          쿼리 없음
     *   · 관리 화면(/tbox/admin·users·audit·org·trash·wiki…)·로그인·로그아웃·재인증·/tbox/s/…(공유 받기)·…/api/… 는 목록에 **없다**
     *   · 허용 밖 쿼리 키·같은 키 두 번·빈 값·`&` 로 빈 조각 → null (조용히 떼지 않는다 — 모양이 어긋난 주소는 첫 화면)
     * 정규형은 영숫자·`/?&=%-_.~` 만 담는다(쿼리 값은 rawurlencode — 대문자 16진) — HTML 속성·Location 에 그대로 넣어도 되는 글자다.
     */
    public static function returnPath(?string $raw): ?string
    {
        if ($raw === null || $raw === '' || strlen($raw) > self::RETURN_PATH_MAX) {
            return null;
        }
        // 한 번 디코딩한 전체 — 여기서 거르는 것은 모양 전체의 위험 글자다(쿼리 값 안에 숨긴 것도 이 한 번으로 드러난다)
        $dec = rawurldecode($raw);
        if (preg_match('//u', $dec) !== 1
            || preg_match('/[\x00-\x1f\x7f\\\\@]/', $dec) === 1
            || strpos($dec, '//') !== false
            || strpos($dec, '..') !== false
            || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $dec) === 1
            || preg_match('#[/?&=][A-Za-z][A-Za-z0-9+.-]*:#', $dec) === 1
        ) {
            return null;
        }
        $q     = strpos($raw, '?');
        $path  = $q === false ? $raw : substr($raw, 0, $q);
        $query = $q === false ? null : substr($raw, $q + 1);

        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        $rules = [
            '#^/tbox/$#'                 => ['folder' => '/^[1-9][0-9]{0,8}$/', 'q' => 'text200', 'sort' => '/^(name|created_at|size)$/',
                                             'dir' => '/^(asc|desc)$/', 'page' => '/^[1-9][0-9]{0,5}$/'],
            '#^/tbox/(settings|activity)$#' => [],
            '#^/community/$#'            => ['space' => '/^[1-9][0-9]{0,17}$/', 'view' => '/^(mine|shared)$/'],
            '#^/community/workspace$#' => ['space' => '/^[1-9][0-9]{0,17}$/', 'trash' => '/^1$/'],
            '#^/community/memories$#' => ['view' => '/^(mine|shared)$/'],
            '#^/community/memories/u/[a-z0-9_.-]{3,64}$#' => [],
            '#^/community/memories/p/' . $uuid . '$#' => [],
            '#^/community/p/' . $uuid . '$#' => ['edit' => '/^1$/'],
            '#^/community/b/[1-9][0-9]{0,8}$#' => [],
        ];
        $allowed = null;
        foreach ($rules as $re => $keys) {
            if (preg_match($re, $path) === 1) {
                $allowed = $keys;
                break;
            }
        }
        if ($allowed === null) {
            return null;
        }
        if ($query === null) {
            return $path;
        }
        if ($query === '' || $allowed === []) {
            return null;
        }
        $got = [];
        foreach (explode('&', $query) as $pair) {
            $eq = strpos($pair, '=');
            if ($eq === false) {
                return null;
            }
            $key = substr($pair, 0, $eq);
            $val = rawurldecode(substr($pair, $eq + 1));   // 쿼리 값 — 여기서 **한 번** 푼다(위의 전체 디코딩과 같은 한 번)
            if (!array_key_exists($key, $allowed) || array_key_exists($key, $got) || $val === '') {
                return null;
            }
            $rule = $allowed[$key];
            if ($rule === 'text200') {
                if (preg_match('//u', $val) !== 1 || preg_match('/^.{1,200}$/su', $val) !== 1 || trim($val) === '') {
                    return null;
                }
            } elseif (preg_match($rule, $val) !== 1) {
                return null;
            }
            $got[$key] = $val;
        }
        $parts = [];
        foreach (array_keys($allowed) as $key) {   // 정규형 — 키 순서는 허용 목록 순서(서버·화면이 같은 순서)
            if (array_key_exists($key, $got)) {
                $parts[] = $key . '=' . rawurlencode($got[$key]);
            }
        }
        return $path . '?' . implode('&', $parts);
    }

    /**
     * JSON 본문 파싱. 폼 인코딩(application/x-www-form-urlencoded, multipart)은 받지 않는다 —
     * 크로스사이트에서 자동 전송할 수 있는 형식을 상태 변경 API 가 받지 않게 하는 것이 목적이다
     * (ARCH 4.0 "로그인 CSRF"). 형식이 어긋나면 전부 400 동일 문구다.
     *
     * $maxBytes — 받아들일 본문의 **바이트 상한**. 기본값은 지금까지와 같은 64KB 이고,
     *   t-box 창구는 인자를 넘기지 않으므로 **한 글자도 달라지지 않는다**.
     *   커뮤니티의 본문 저장 창구(`pages_create`·`pages_update`) 둘만 더 큰 값을 넘긴다:
     *   문서 스키마가 허용하는 크기(`doc-schema.json` 의 `doc_bytes` = 1MB)보다 여기가 작으면
     *   **스키마는 받아 주는데 입구가 막는** 모순이 생기고, 긴 노트 하나에 저장이 멈춘다
     *   (2026-09-22 관문 ② 실측). 상한을 없애지는 않는다 — 없으면 메모리가 요청 크기를 따라간다.
     *
     * $maxDepth — 중첩 깊이 상한. 기본값 8 도 지금까지와 같고, 같은 창구 둘만 문서 스키마가
     *   허용하는 깊이에 맞춰 넓힌다. 넓혀도 **검증기가 다시 본다** — `Doc::validate` 의
     *   `depth` 상한(10)을 넘는 문서는 `too_deep` 으로 거부되고, 그 거부는 감사에 남는다.
     *   여기서 막으면 「왜 안 되는지 알 수 없는 400」이 되고, 여기서 통과시키면 「어디가
     *   얼마나 깊은지」가 응답에 적힌다.
     *
     * @return array<string,mixed>
     */
    public static function jsonBody(int $maxBytes = self::MAX_JSON_BYTES, int $maxDepth = self::MAX_JSON_DEPTH): array
    {
        if ($maxBytes < 1) {
            $maxBytes = self::MAX_JSON_BYTES;
        }
        if ($maxDepth < 1) {
            $maxDepth = self::MAX_JSON_DEPTH;
        }
        $ctype = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($ctype, 'application/json') !== 0) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        if (strlen($raw) > $maxBytes) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        $data = json_decode($raw, true, $maxDepth, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_array($data)) {
            self::error(400, self::MSG_BAD_REQUEST);
        }
        return $data;
    }

    /**
     * 세션 쿠키 발급 (PRD S-09-2, ARCH 4.0).
     *   이름      — `__Host-tbox_session`(Auth::COOKIE_NAME, 디버깅 묶음 S2). 접두가 아래 셋을 브라우저에 강제한다
     *   HttpOnly  — JS 가 읽지 못한다 (XSS 로 토큰을 훔쳐 갈 수 없다)
     *   Secure    — 평문 http 로는 전송되지 않는다
     *   SameSite=Lax — 크로스사이트 POST 에 쿠키가 실리지 않는다
     *   Path=/    — 도메인 안 모든 경로
     *   Domain 없음 — 발급한 호스트 한 곳에만(하위 도메인과 나누지 않는다). `__Host-` 는 Domain 이 있으면 거부된다
     *   만료 없음 — 브라우저 세션 쿠키. 수명 판단은 언제나 DB(sessions)가 한다
     */
    public static function setSessionCookie(string $token): void
    {
        self::sendCookie(Auth::COOKIE_NAME, $token, false);
    }

    /** 쿠키 즉시 만료 (Max-Age=0). 속성은 발급 때와 같아야 브라우저가 같은 쿠키로 인식한다. */
    public static function clearSessionCookie(): void
    {
        self::sendCookie(Auth::COOKIE_NAME, '', true);
    }

    /**
     * v2.30 까지의 옛 이름 쿠키(`tbox_session`)를 치운다 (디버깅 묶음 S2 — Auth::dropLegacyCookie 가 부른다).
     * 옛 쿠키도 같은 속성(Path=/ · Secure · HttpOnly · SameSite=Lax · Domain 없음)으로 발급됐으므로 같은 속성의 만료 머리로 지워진다.
     */
    public static function clearLegacySessionCookie(): void
    {
        self::sendCookie(Auth::LEGACY_COOKIE_NAME, '', true);
    }

    /**
     * T:F-72 — 지문·얼굴 로그인의 **로그인 전 쿠키**(`__Host-tbox_pk`, 32바이트 hex) 발급 (지시서 1.3).
     * 세션 쿠키와 다른 점 둘: **SameSite=Strict**(남의 사이트에서 시작된 요청에는 실리지 않는다 — 로그인 끼워 넣기 한 겹 더)와
     * **Max-Age=600**(10분 — 로그인 챌린지 5분 + 여유). 나머지(Secure · HttpOnly · Path=/ · Domain 없음)는 같다.
     * 값의 sha256 만 챌린지 표에 묶이고, 값 자체는 감사·로그·응답 본문에 나가지 않는다. 로그인 성공 때 지운다.
     */
    public static function setPasskeyPreCookie(string $value): void
    {
        if (headers_sent()) {
            throw new RuntimeException('헤더가 이미 전송되어 쿠키를 설정할 수 없습니다');
        }
        setcookie(Passkey::PRE_COOKIE, $value, [
            'expires'  => time() + Passkey::PRE_COOKIE_MAX_AGE,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** 로그인 전 쿠키 지우기 — 발급과 같은 속성의 만료 머리 */
    public static function clearPasskeyPreCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(Passkey::PRE_COOKIE, '', [
            'expires'  => 1,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    private static function sendCookie(string $name, string $value, bool $expire): void
    {
        if (headers_sent()) {
            throw new RuntimeException('헤더가 이미 전송되어 쿠키를 설정할 수 없습니다');
        }
        // `domain` 을 넣지 않는다 — 호스트 한정 쿠키(`__Host-` 의 조건). 넣으면 브라우저가 쿠키를 버린다.
        $options = [
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if ($expire) {
            $options['expires'] = 1;   // 1970-01-01 → 브라우저가 즉시 버린다
        }
        setcookie($name, $value, $options);
    }

    /**
     * 상태를 바꾸는 요청(POST/PUT/PATCH/DELETE)의 CSRF 토큰 검사 (PRD S-09-3, ARCH 4.0).
     *
     * 토큰은 저장하지 않고 세션 해시 + APP_SECRET 으로 계산한다(Auth::csrfToken).
     * 클라이언트는 GET /api/me 로 받아 X-CSRF-Token 헤더에 실어 보낸다.
     * 누락·불일치는 대상 정보를 담지 않는 403 동일 문구다.
     */
    public static function requireCsrf(): void
    {
        $method = self::method();
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        if (Auth::currentUser() === null) {
            self::error(401, self::MSG_UNAUTHORIZED);
        }

        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        // hash_equals 는 길이가 다르면 false 를 낸다. 시간차 비교(==)를 쓰지 않는다.
        if (!is_string($sent) || !hash_equals(Auth::csrfToken(), $sent)) {
            Audit::deny('csrf', 'token_mismatch', [
                'target_type' => 'system',
                // 실제 경로가 아니라 라우트 패턴을 남긴다 (SECURITY R-36, ARCH 7.5 route).
                // 문장 틀(ARCH 7.4 `csrf|deny|token_mismatch`)은 그대로이고 값만 패턴으로 바뀐다.
                'target_name' => defined('TBOX_ROUTE')
                    ? (string) constant('TBOX_ROUTE')
                    : $method . ' ' . self::path(),
            ]);
            self::error(403, self::MSG_FORBIDDEN);
        }
    }

    /* ─── 쿼리스트링 읽기 (3단계, 목록·검색용) ─────────────────────
     * 세 함수의 공통 원칙: **거부하지 않고 안전한 값으로 좁힌다.**
     *   검색·정렬·페이지 파라미터는 사용자가 URL 을 손으로 고치기 쉬운 자리다.
     *   여기서 400 을 내면 화면이 깨질 뿐이고 막아지는 것은 없다. 대신
     *   길이를 자르고, 허용 목록 밖 값은 기본값으로 바꾸고, 정수 형태만 인정한다
     *   (ARCH 5.1 [2][3], PRD S-04 "ORDER BY 는 허용 목록").
     */

    /** 문자열 쿼리 — 문자열이 아니면 '', 제어문자 제거, $maxChars 자로 자른다 */
    public static function queryText(string $key, int $maxChars): string
    {
        $raw = $_GET[$key] ?? '';
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        // 잘못된 UTF-8 은 DB(UTF8)·JSON 모두에서 문제를 만든다 → 값을 버린다
        if (preg_match('//u', $raw) !== 1) {
            return '';
        }
        $clean = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $raw);
        $clean = trim($clean);
        if ($clean === '') {
            return '';
        }
        if (iconv_strlen($clean, 'UTF-8') > $maxChars) {
            $clean = (string) iconv_substr($clean, 0, $maxChars, 'UTF-8');
        }
        return $clean;
    }

    /**
     * 허용 목록 안의 값만 통과시킨다. 밖의 값·누락은 $default (400 이 아니다).
     *
     * @param list<string> $allowed 코드에 적힌 고정 목록
     */
    public static function queryChoice(string $key, array $allowed, string $default): string
    {
        $raw = $_GET[$key] ?? null;
        if (is_string($raw) && in_array($raw, $allowed, true)) {
            return $raw;
        }
        return $default;
    }

    /** 정수 쿼리 — 숫자 형태가 아니거나 범위를 벗어나면 $default */
    public static function queryInt(string $key, int $default, int $min, int $max): int
    {
        $raw = $_GET[$key] ?? null;
        if (!is_string($raw) || preg_match('/^[0-9]{1,9}$/', $raw) !== 1) {
            return $default;
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            return $default;
        }
        return $value;
    }
}
