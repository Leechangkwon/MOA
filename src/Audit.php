<?php
declare(strict_types=1);

/**
 * src/Audit.php — 감사 로그 (PRD S-07, ARCHITECTURE 7.1 · 7.2)
 *
 * 두 곳에 동시에 기록한다.
 *   · DB  audit_logs      — 원본(조회용). 앱 계정에는 SELECT·INSERT 만 있어 지울 수 없다(Append-only).
 *   · 파일 AUDIT_LOG_FILE — 사본(증거 보전용). DB 가 손상돼도 흔적이 남는다.
 *
 * 기록에 실패하면 예외를 던진다 (PRD S-07 "기록 없는 민감 행동 금지").
 * 호출자(라우터·핸들러)는 트랜잭션을 롤백하고 500 으로 끝난다 — 기록 없이 성공하는 경로를 만들지 않는다.
 *
 * 절대 기록하지 않는 것: 비밀번호, 세션 토큰·쿠키, 파일 본문 (PRD 7.7 "민감정보 금지").
 * detail 배열에 그런 키가 섞여 들어오면 값을 지우고 '[제거됨]' 으로 바꾼다 — 실수로 새는 경로를 막는다.
 *
 * ── 요약 문장 (summary) — PRD 7.7 "요약 문장 필수", ARCHITECTURE 7.4 ──
 * 모든 행에 초보자용 한국어 한 문장을 **기록 시점에** 함께 저장한다. 조회 시점에 번역하지 않는 이유는
 * ① `tail -f audit.log` 만으로 읽히고 ② 나중에 문장 틀을 바꿔도 과거 기록은 당시 문장 그대로 남아
 * 증거로서 흔들리지 않기 때문이다 (ARCH 7.4 "왜").
 *
 * 문장 틀은 아래 SUMMARY_TEMPLATES 상수 표 하나에만 둔다 — ARCH 7.4 표를 글자 그대로 옮긴 것이다.
 * **표에 없는 (action, result, reason) 조합은 예외 → 기록 실패 → 요청 실패(500)** 이다.
 * 조용한 "기본 문장" 으로 넘어가지 않는다: 새 행동·새 사유를 추가하는 사람이 ARCH 7.4 에 문장 틀을
 * 빠뜨리면 첫 요청에서 바로 드러나야 한다. 이 표가 곧 사유 코드 등록부다.
 *
 * 문장에 넣는 값은 세 개뿐이다 — {actor}=actor_name, {target}=target_name, {level}=access_level.
 * 요청 값·detail·user_agent·ip 는 문장에 넣지 않는다 (PRD 7.7 "비밀값 금지 규칙은 그대로").
 */
final class Audit
{
    /** detail 에서 값을 지울 키 (부분 일치, 대소문자 무시) */
    private const FORBIDDEN_DETAIL_KEYS = [
        'password', 'passwd', 'pass', 'token', 'cookie', 'secret',
        'authorization', 'session', 'hash', 'csrf',
        // 10단계 (SECURITY R-05) — 2단계 인증의 비밀·코드는 어떤 이름으로도 남지 않는다.
        // 'secret' 은 이미 위에 있고, 여기 둘은 `otp`·`mfa_secret`·`mfa_code` 같은 이름을 막는다.
        'otp', 'mfa',
    ];

    /**
     * 위 목록은 **부분 일치**라 안전한 키까지 함께 걸린다. 그 예외를 여기에 **정확히 일치**로 적는다.
     *
     * `sessions_revoked` 는 ARCH 7.5 등록부에 있는 키로 값이 "끊은 세션 **수**"(정수)다.
     * 토큰도 세션 id 도 아니어서 남겨도 되는 값인데, 이름에 `session` 이 들어가 있어
     * 부분 일치에 걸린다(2026-09-11 10단계 구현에서 실측). 목록을 느슨하게 푸는 대신
     * 이 키 하나만 정확 일치로 통과시킨다 — 새 예외를 더할 때는 ARCH 7.5 를 먼저 고친다.
     *
     * @var list<string>
     */
    private const ALLOWED_DETAIL_KEYS = [
        'sessions_revoked',
        /*
         * 11단계 (PRD F-16, ARCH 7.5). 셋 다 등록부에 있는 키이고 값에 비밀이 없다.
         *   temp_password_issued — 임시 비밀번호를 **발급했다는 사실**(true)만. 값은 절대 남기지 않는다.
         *                          이름에 `password` 가 들어가 부분 일치에 걸리므로 예외로 등록한다.
         *   forced               — 최초 로그인 강제 절차 중에 한 변경인가 (true)
         *   next                 — 관문이 요구한 다음 단계 (`password_change`·`mfa_setup`)
         */
        'temp_password_issued', 'forced', 'next',
        /*
         * 12-2 미리보기 (PRD F-18, ARCH 7.5 등록부 `preview_kind`·`part`).
         *   preview_kind — 서버가 DB `ext` 로 판정한 갈래 (image·pdf·text·table·archive·office·none)
         *   part         — meta(JSON 본문) / raw(inline 원본). 한 미리보기가 두 행으로 남는 것을 가른다
         * 둘 다 오늘의 FORBIDDEN_DETAIL_KEYS 부분 일치에는 걸리지 않지만, 등록부에 있는 키임을
         * 코드에서도 드러내려고 여기 적는다. **파일 내용은 어느 쪽에도 들어가지 않는다**.
         */
        'preview_kind', 'part',
        /*
         * 16-1 관리 기능 재인증 (PRD F-23, ARCH 7.5 등록부 `window_minutes`).
         *   window_minutes — 재인증 창의 길이(분, 정수 15). 코드 상수 Auth::REAUTH_MINUTES 를
         *                    그대로 남긴다 — 나중에 창을 줄이거나 늘렸을 때 "그때는 몇 분이었나" 를
         *                    로그만으로 알 수 있게 하는 값이다. 비밀번호·시각·세션 값이 아니다.
         * FORBIDDEN_DETAIL_KEYS 의 부분 일치에는 걸리지 않지만, 등록부에 있는 키임을 코드에서도
         * 드러내려고 여기 적는다 (preview_kind·part 와 같은 이유).
         */
        'window_minutes',
        /*
         * 9단계 해시 체인 + 13단계 재봉인 (ARCH 7.1 · 7.5, SECURITY R-11 · R-50).
         *   chain_hash          — 그날 감사 로그 파일의 sha256. 이름에 `hash` 가 들어가 부분 일치에
         *                         걸려 2026-09-12 까지 `[제거됨]` 으로 남고 있었다(실측: 09-12 00:05 행).
         *                         공개 값이어야 의미가 있는 무결성 증거이므로 예외로 등록한다.
         *   resealed_from_hash  — `--reseal` 이 덮어쓰기 **전**의 해시·줄수·바이트수.
         *   resealed_from_lines   재봉인은 "체인을 사람이 고친" 사건이므로, 무엇이 무엇으로 바뀌었는지가
         *   resealed_from_bytes   감사 로그에 남아야 나중에 그 판단을 되짚을 수 있다.
         * 어느 값도 비밀이 아니다 — 파일 내용이 아니라 그 요약(해시·개수)이다.
         */
        'chain_hash', 'resealed_from_hash', 'resealed_from_lines', 'resealed_from_bytes',
        /*
         * F-46 (2026-09-15, ARCH 5.22 · 7.5 등록부).
         *   cookie_hops — URL 가져오기의 리다이렉트 중 **직전 응답의 쿠키를 같은 호스트로 실어 보낸
         *                 단계 수**(정수, 보통 0 또는 1). 이름에 `cookie` 가 들어가 부분 일치에 걸려
         *                 `[제거됨]` 으로 남았다(2026-09-15 실측). **쿠키 값도 이름도 아니고 개수다** —
         *                 `sessions_revoked`(세션 수)·`chain_hash` 와 같은 예외다. 조사에 필요한 것은
         *                 「쿠키를 썼나·몇 번 썼나」이고, 값은 `Fetch` 가 어디에도 남기지 않는다.
         */
        'cookie_hops',
        /*
         * 15단계 (PRD F-13 · F-22, ARCH 7.5 등록부).
         *   share_id      — 공유 링크 행의 id. 링크 원문도 그 해시도 아니다(그 둘은 어디에도 남지 않는다)
         *   issued_by     — 링크를 만든 사람의 계정명. 받는 쪽에는 actor 가 없으므로 "누가 열어 줬나" 가 여기 남는다
         *   remaining     — 이번 다운로드 뒤 남은 횟수 (정수)
         *   expires_at    — 만료 시각 (계정 만료와 같은 키를 공유 링크에서도 쓴다)
         *   max_downloads — 허용 횟수 (정수)
         *   sensitive     — 검출된 **패턴 이름과 건수만** (`{"rrn":1}`). 검출된 숫자·문자열
         *                   원문은 어떤 형태로도 들어가지 않는다 (PRD S-07, SECURITY R-58)
         * 여섯 키 모두 오늘의 FORBIDDEN_DETAIL_KEYS 부분 일치에는 걸리지 않지만, 등록부에 있는
         * 키임을 코드에서도 드러내려고 여기 적는다(preview_kind·part 와 같은 이유).
         */
        'share_id', 'issued_by', 'remaining', 'expires_at', 'max_downloads', 'sensitive',
        /*
         * 17-2 권한 매트릭스 편집 (PRD F-25, ARCH 5.15 · 7.5 등록부).
         *   otp_verified — "요청마다 받은 2단계 인증 코드를 **서버가 확인했다**" 는 사실(true)만.
         *                  코드 6자리도, 비밀도, 스텝 번호도 넣지 않는다. 이름에 `otp` 가 들어가
         *                  FORBIDDEN_DETAIL_KEYS 부분 일치에 걸리므로 예외로 등록한다
         *                  (`temp_password_issued` 와 **같은 성격**: 값이 아니라 사실만 남긴다).
         * `before`·`after`(바뀐 칸의 이전값→새값)·`reason_text`·`rule`(어긴 불변 규칙 글자)은
         * 이미 등록부에 있고 금지 목록에 걸리지 않아 따로 적지 않는다.
         */
        'otp_verified',
        /*
         * F-65 실행 파일이 든 압축본 (PRD F-65, ARCH 5.29 · 7.5 등록부).
         *   exec_ack         — 확인 문구를 받았다는 **사실**(true)만. 문구 원문도 체크 시각도 아니다
         *   exec_entry_count — zip 안에서 이름 검사에 걸린 항목의 **개수**(정수)
         *   exec_entries     — 그 항목들의 **이름**(≤ 20). 이름뿐이고 내용은 한 바이트도 들어가지 않는다
         *                      (ARCH 5.27 — 검사 자체가 중앙 디렉터리의 이름만 본다)
         * 셋 다 오늘의 FORBIDDEN_DETAIL_KEYS 부분 일치에는 걸리지 않지만, 등록부에 있는 키임을
         * 코드에서도 드러내려고 여기 적는다(preview_kind·part 와 같은 이유).
         * ★ 같은 요청에 실린 **비밀번호·2단계 코드 값은 어떤 키로도 들어가지 않는다** —
         *   `otp_verified: true`(바로 위 17-2 키)가 「확인했다」는 사실을 대신한다 (PRD 7.7).
         */
        'exec_ack', 'exec_entry_count', 'exec_entries',
        /*
         * 19~25단계의 모듈이 넣었던 키 묶음 — 2026-09-21 (F-64) 에 그 모듈을 지우면서
         * **남는 코드가 더는 쓰지 않는 이름만** 함께 지웠다. 아래 열 개는 t-box 본체가 계속 쓰므로
         * 남긴다 (등록부 = ARCH 7.5).
         *   kind      — 갈래를 가리키는 고정 분류어 (무결성 점검의 `missing`·`orphan` 등)
         *   target_id — 대상 행의 id. 감사 표의 target_id 칼럼과 같은 값을 detail 에도 남긴다
         *   file_id   — 어느 파일 행인가 (`files.id`)
         *   sha256    — 파일·로그의 해시. 이름에 `hash` 가 들어가지 않아 부분 일치에 걸리지
         *               않지만, 등록부에 있는 키임을 코드에서도 드러내려고 적는다
         *   rows · updated — 한 번의 처리가 다룬 **수치**. 행 내용은 남기지 않는다
         *   why       — 문장 표에 아직 없는 세부 사유를 남기는 자리
         *   lines · added — 줄 수·더한 줄 수 (수치)
         *   shared    — 공용 경로였나 (토큰 종류 분류어와 짝을 이루는 true/false)
         * `before`·`after`·`reason_text` 는 이미 등록부에 있고 금지 목록에 걸리지 않는다.
         */
        'kind', 'target_id', 'file_id', 'sha256', 'rows', 'updated',
        'why', 'lines', 'added', 'shared',
        /*
         * F-43 URL 가져오기 허용 사이트 · 공개 링크 변환 (PRD F-43, ARCH 5.22 · 7.5 등록부).
         *   hops        리다이렉트로 **지나온 호스트 목록**. 통제된 리다이렉트가 정말 단계마다
         *               검사를 받았는지를 로그만으로 되짚는 값이다. 호스트 이름뿐이고 경로는 없다
         *   source_url  사람이 넣은 주소와 서버가 바꾼 주소(`원 → 변환`, 512바이트로 자름).
         *               ★ 7단계에는 "URL 전체를 남기지 않는다" 였는데 F-43 이 이 키를 열었다:
         *                 변환이 맞았는지·어떤 링크로 나갔는지는 그 문자열이 없으면 알 수 없고,
         *                 이 값은 **요청자가 직접 적어 넣은 공개 문서 주소**다. 감사 로그를 볼 수
         *                 있는 사람은 Admin 뿐이다 (PRD 5장). 비밀번호·토큰이 섞일 수 있는
         *                 헤더·쿠키는 여전히 한 글자도 남기지 않는다
         *   provider    드라이브·OneDrive 링크였나 (`google`·`microsoft`·null)
         *   needs_login 공개 링크가 아니어서 거부했나 (true). 값이 아니라 판정 사실이다
         */
        'hops', 'source_url', 'provider', 'needs_login',
        /*
         * F-49 프로그램용 업로드 (PRD F-49 ⑥, ARCH 4.2 「감사의 구분」·7.5 등록부).
         *   auth — 이 요청이 **어떤 방법으로 인증됐나** 를 가리키는 고정 분류어(`api_token`).
         *          값도 이름도 토큰이 아니다. 아래 autoDetail() 이 요청 스코프의
         *          `TBOX_AUTH_METHOD` 를 보고 모든 행에 자동으로 붙이므로, 업로드 공통 함수는
         *          누가 불렀는지 모른 채 같은 코드를 실행하면서도 `file.upload allow` 에
         *          「프로그램이 올린 것」 이 남는다.
         *          이름 자체는 FORBIDDEN_DETAIL_KEYS 부분 일치에 걸리지 않지만(`authorization`
         *          쪽이 아니다), 등록부에 있는 키임을 코드에서도 드러내려고 여기 적는다
         *          (preview_kind·part 와 같은 이유).
         */
        'auth',
        /*
         * F-59 사용자별 개인 토큰 (PRD F-59 ④ ⑦, ARCH 4.6 「감사」 · 7.5 등록부).
         * 세 키 모두 이름에 `token` 이 들어 FORBIDDEN_DETAIL_KEYS 의 **부분 일치**에 걸린다.
         * 값은 토큰이 아니라 분류어·번호·라벨이므로 **정확 일치 예외**로만 통과시킨다
         * (목록을 느슨하게 푸는 것이 아니다 — `sessions_revoked` 와 같은 방식).
         *   token_kind — 토큰 경로의 종류 분류어(`personal`·`shared`). 코드에 적힌 고정값이고
         *                요청이 정하지 않는다. autoDetail() 이 모든 행에 붙인다
         *   token_id   — 개인 토큰의 **행 번호**(정수, api_tokens.id). 토큰 값·hint 가 아니다 —
         *                「어느 토큰으로」 를 관리자가 목록에서 되짚는 열쇠
         *   token_name — 발급·회수한 토큰의 **라벨**(「사무실 PC」). 사람이 붙인 이름이고 비밀이 아니다
         * ★ 토큰 원문·hint·해시는 어떤 키로도 남지 않는다 (PRD S-09-11, SECURITY R-91).
         */
        'token_kind', 'token_id', 'token_name',
        /*
         * F-66 범위 (PRD F-66 ③, ARCH 4.7 · 7.5 등록부).
         *   token_scope — 토큰의 **범위** 분류어(`read`·`read+upload`·`read+upload+delete`).
         *                 코드가 정한 세 값 중 하나이고 요청이 정하지 않는다.
         *                 autoDetail() 이 토큰 경로의 모든 행에, 발급·범위 거부 행에는
         *                 핸들러·Auth 가 붙인다. 위 세 키와 같은 **정확 일치 예외**다.
         */
        'token_scope',
    ];

    private const RESULT_ALLOW = 'allow';
    private const RESULT_DENY  = 'deny';
    private const RESULT_ERROR = 'error';

    /**
     * 요약 문장 틀 — ARCHITECTURE 7.4 표를 글자 그대로 옮긴 것 (2026-09-10 결정, PRD S-07.
     * 9단계 운영 배치 8행 추가 2026-09-11).
     * 키는 `action|result|reason`. allow 는 reason 이 없으므로 `action|allow` 두 칸이다.
     * 표에 행을 추가할 때는 ARCH 7.4 를 먼저 고치고 여기에 같은 문장을 옮긴다 — 두 곳이 어긋나면 안 된다.
     *
     * @var array<string,string>
     */
    private const SUMMARY_TEMPLATES = [
        // ── 인증 (Auth) ──
        'auth.login|allow'                        => '{target} 이(가) 로그인했습니다',
        'auth.login|deny|bad_credentials'         => '{target} 계정으로 로그인을 시도했지만 아이디 또는 비밀번호가 틀렸습니다',
        'auth.login|deny|locked'                  => '{target} 계정으로 로그인을 시도했지만 실패 5회로 10분 잠긴 계정입니다',
        'auth.login|deny|inactive'                => '{target} 계정으로 로그인을 시도했지만 비활성화된 계정입니다',
        'auth.logout|allow'                       => '{actor} 이(가) 로그아웃했습니다',
        'auth.required|deny|not_authenticated'    => '로그인하지 않은 채 {target} 에 접근하려 해서 막았습니다',

        /*
         * F-49 프로그램용 업로드 토큰 (ARCH 4.2, PRD S-09-11, SECURITY R-82).
         * 네 행 모두 {target} 은 **라우트 패턴**(`POST /api/upload`)이다 — 계정 이름도
         * 토큰 값도 들어가지 않는다. 성공은 여기 없다: 토큰이 맞으면 그 요청은 곧바로
         * 업로드 판정으로 넘어가고, 남는 것은 `file.upload` 행에 붙는 `detail.auth=api_token` 이다.
         */
        'auth.token|deny|not_configured'          => '{target} 을(를) 불렀지만 이 서버에 프로그램용 API 토큰이 설정되어 있지 않아 막았습니다 (설정 전에는 아무도 쓸 수 없습니다)',
        'auth.token|deny|missing_header'          => 'API 토큰 없이 {target} 을(를) 부르려 해서 막았습니다',
        'auth.token|deny|bad_token'               => '틀린 API 토큰으로 {target} 을(를) 부르려 해서 막았습니다',
        'auth.token|deny|user_invalid'            => '{target} 의 API 토큰은 맞았지만 그 토큰에 연결된 계정이 없거나 쓸 수 없는 상태여서 막았습니다 (서버 설정 점검 필요)',

        /*
         * F-59 사용자별 개인 토큰 (ARCH 4.6 판정 순서 표 · 7.4, SECURITY R-91).
         *   user_disabled              개인 토큰은 맞았지만 그 계정이 비활성·만료·강제 절차 중이다 → 403.
         *                              계정을 비활성화하면 그 계정의 토큰이 자동으로 막히는 경로다.
         *   shared_token_not_permitted 공용 토큰(.env)으로 **개인 토큰 전용 창구**(삭제 둘)를 불렀다 → 403.
         * 둘 다 {target} 은 라우트 패턴이다 — 계정 이름도 토큰 값도 들어가지 않는다.
         */
        'auth.token|deny|user_disabled'           => '{target} 의 개인 토큰은 맞았지만 그 계정이 비활성·만료·절차 중이어서 막았습니다',
        'auth.token|deny|shared_token_not_permitted' => '공용 토큰으로 개인 토큰 전용 창구 {target} 을(를) 부르려 해서 막았습니다',

        /*
         * F-59 개인 토큰의 발급·회수 (PRD F-59 ②, ARCH 4.6 · 7.4, API.md 13장).
         * 일곱 행 모두 {target} 은 **대상 계정의 아이디**다 — 토큰 원문도 hint 도 어느 문장에도
         * 들어가지 않는다(남는 것은 detail 의 token_id·token_name·reason_text 뿐, ARCH 7.5).
         */
        'api_token.issue|allow'                   => '{actor} 이(가) 계정 「{target}」 의 프로그램 토큰을 발급했습니다 (이름·사유는 상세 기록, 토큰 값은 남기지 않습니다)',
        'api_token.issue|deny|bad_request'        => '{actor} 이(가) 계정 「{target}」 의 프로그램 토큰 발급을 허용되지 않는 형식(모르는 항목·이름 규칙·사유 없음)으로 요청해서 거부했습니다',
        'api_token.issue|deny|limit_exceeded'     => '{actor} 이(가) 계정 「{target}」 에 프로그램 토큰을 더 발급하려 했지만 살아 있는 토큰이 이미 5개여서 거부했습니다',
        'api_token.issue|deny|target_inactive'    => '{actor} 이(가) 비활성·만료된 계정 「{target}」 에 프로그램 토큰을 발급하려 해서 거부했습니다',
        'api_token.revoke|allow'                  => '{actor} 이(가) 계정 「{target}」 의 프로그램 토큰을 회수했습니다 (이후 그 토큰은 쓸 수 없습니다)',
        'api_token.revoke|deny|not_found'         => '{actor} 이(가) 계정 「{target}」 에 없는 프로그램 토큰을 회수하려 해서 거부했습니다',
        'api_token.revoke|deny|bad_request'       => '{actor} 이(가) 이미 회수된 프로그램 토큰을 다시 회수하려 해서 거부했습니다',

        /*
         * F-66 본인 발급의 인증 실패와 토큰의 만료·범위 (PRD F-66 ① ② ③ ④, ARCH 4.7 · 7.4).
         * 앞 넷은 {target} 이 **본인의 아이디**다(발급자 = 대상 = 본인). 뒤 둘은 {target} 이
         * **라우트 패턴**이다 — 계정 이름도 토큰 값도 어느 문장에도 들어가지 않는다.
         * 비밀번호·코드는 사유 코드로만 남는다 (PRD 7.7, S-09-11).
         */
        'api_token.issue|deny|mfa_not_enabled'    => '{actor} 이(가) 2단계 인증이 꺼진 채 프로그램 토큰을 발급하려 해서 거부했습니다 (먼저 2단계 인증을 켜야 합니다)',
        'api_token.issue|deny|bad_credentials'    => '{actor} 이(가) 프로그램 토큰을 발급하려 했지만 비밀번호가 틀려서 거부했습니다',
        'api_token.issue|deny|bad_code'           => '{actor} 이(가) 프로그램 토큰을 발급하려 했지만 2단계 인증 코드가 틀려서 거부했습니다',
        'api_token.issue|deny|code_reused'        => '{actor} 이(가) 프로그램 토큰을 발급하려 했지만 이미 쓴 2단계 인증 코드여서 거부했습니다',
        'auth.token|deny|token_expired'           => '만료된 개인 토큰으로 {target} 을(를) 부르려 해서 막았습니다 (내 계정에서 새로 발급해야 합니다)',
        'auth.token|deny|scope_not_permitted'     => '개인 토큰의 범위에 없는 창구 {target} 을(를) 부르려 해서 막았습니다',

        // ── 공통 관문 (Http::requireCsrf, index.php 라우트 정책) ──
        'csrf|deny|token_mismatch'                => '{actor} 이(가) {target} 요청을 보냈지만 CSRF 토큰이 없거나 달라서 막았습니다 (다른 사이트에서 몰래 보낸 요청일 수 있음)',
        'authz.route|deny|role_not_permitted'     => '{actor} 이(가) 권한이 없는 기능({target})을 쓰려 해서 막았습니다',

        // ── 업로드 (files_upload, Upload) ──
        'file.upload|allow'                       => '{actor} 이(가) 파일 「{target}」 을(를) {level} 등급으로 올렸습니다',
        'file.upload|deny|role_not_permitted'     => '{actor} 이(가) 파일 「{target}」 을(를) {level} 등급으로 올리려 했지만 그 등급으로 올릴 권한이 없어 막았습니다',
        'file.upload|deny|team_mismatch'          => '{actor} 이(가) 파일 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 올리려 해서 막았습니다',
        'file.upload|deny|no_file'                => '{actor} 이(가) 파일 없이 업로드를 요청해서 거부했습니다',
        'file.upload|deny|empty_file'             => '{actor} 이(가) 0바이트 파일 「{target}」 을(를) 올리려 해서 거부했습니다',
        'file.upload|deny|too_large'              => '{actor} 이(가) 100MB 를 넘는 파일 「{target}」 을(를) 올리려 해서 거부했습니다',
        'file.upload|deny|bad_access_level'       => '{actor} 이(가) 파일 「{target}」 을(를) 존재하지 않는 접근 등급으로 올리려 해서 거부했습니다',
        'file.upload|deny|bad_team'               => '{actor} 이(가) 파일 「{target}」 을(를) 없는 팀 또는 팀 지정 없이 team 등급으로 올리려 해서 거부했습니다',
        'file.upload|deny|description_too_long'   => '{actor} 이(가) 올린 파일 「{target}」 의 설명이 500자를 넘어 거부했습니다',
        'file.upload|deny|bad_encoding'           => '{actor} 이(가) 올린 파일의 이름 또는 설명이 올바른 문자(UTF-8)가 아니어서 거부했습니다',
        'file.upload|deny|upload_error'           => '{actor} 이(가) 보낸 업로드 요청의 형식이 잘못되어(파일 여러 개 등) 거부했습니다',
        'file.upload|deny|not_uploaded_file'      => '{actor} 이(가) 보낸 업로드가 정상적인 HTTP 업로드가 아니어서 거부했습니다',
        'file.upload|deny|size_unreadable'        => '{actor} 이(가) 올린 파일 「{target}」 의 크기를 읽을 수 없어 거부했습니다',
        'file.upload|deny|hash_failed'            => '{actor} 이(가) 올린 파일 「{target}」 의 무결성 값을 계산할 수 없어 거부했습니다',
        'file.upload|deny|ext_not_allowed'        => '{actor} 이(가) 허용되지 않는 종류의 파일 「{target}」 을(를) 올리려 해서 거부했습니다',
        'file.upload|deny|mime_mismatch'          => '{actor} 이(가) 올린 파일 「{target}」 의 실제 내용이 확장자와 달라(위장 파일 의심) 거부했습니다',
        'file.upload|deny|zip_unreadable'         => '{actor} 이(가) 올린 「{target}」 의 압축 파일 구조를 읽을 수 없어 거부했습니다',
        'file.upload|deny|zip_bad_path'           => '{actor} 이(가) 올린 압축 파일 「{target}」 안에 경로를 벗어나는 항목이 있어 거부했습니다',
        'file.upload|deny|zip_blocked_entry'      => '{actor} 이(가) 올린 압축 파일 「{target}」 안에 실행 파일이 들어 있어 거부했습니다',

        /* F-65 실행 파일이 든 압축본 — ARCH 7.4 표의 16행을 **글자 그대로** 옮긴 것이다
         * (PRD F-65, ARCH 5.29). 새 action 둘(`file.upload_exec`·`file.download_exec`)과
         * 기존 action 의 새 사유 넷(`file.download` 둘 · `share.create` · `file.update`). */
        'file.upload_exec|allow'                  => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 내용을 확인했다고 밝히고 비밀번호·2단계 코드를 다시 확인한 뒤 admin-only 등급으로 올렸습니다 (항목 수는 상세 기록)',
        'file.upload_exec|deny|exec_ack_required' => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 확인 절차 없이 올리려 해서 확인을 요청했습니다',
        'file.upload_exec|deny|bad_credentials'   => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 올리며 비밀번호를 틀려 거부했습니다',
        'file.upload_exec|deny|bad_code'          => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 올리며 2단계 인증 코드를 틀려 거부했습니다',
        'file.upload_exec|deny|code_reused'       => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 올리며 이미 쓴 2단계 인증 코드를 다시 보내 거부했습니다',
        'file.upload_exec|deny|mfa_not_enabled'   => '{actor} 이(가) 2단계 인증이 꺼진 상태로 실행 파일이 든 압축본 「{target}」 을(를) 올리려 해서 거부했습니다',
        'file.download_exec|allow'                => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 비밀번호·2단계 코드를 다시 확인한 뒤 내려받았습니다',
        'file.download_exec|deny|bad_credentials' => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 받으며 비밀번호를 틀려 거부했습니다',
        'file.download_exec|deny|bad_code'        => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 받으며 2단계 인증 코드를 틀려 거부했습니다',
        'file.download_exec|deny|code_reused'     => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 받으며 이미 쓴 2단계 인증 코드를 다시 보내 거부했습니다',
        'file.download_exec|deny|mfa_not_enabled' => '{actor} 이(가) 2단계 인증이 꺼진 상태로 실행 파일이 든 압축본 「{target}」 을(를) 받으려 해서 거부했습니다',
        'file.download_exec|deny|bad_request'     => '{actor} 이(가) 실행 파일 확인 절차를 허용되지 않는 형식으로 요청하거나 실행 파일이 아닌 파일에 요청해서 거부했습니다',
        /* 미리보기 두 창구(`/preview`·`/preview/raw`)도 이 **같은 문장**을 쓴다 — ARCH 7.4 의
         * F-65 표에는 `file.preview|deny|exec_ack_required` 행이 없고 「미리보기 두 창구도 같은
         * 문장」이라고만 적혀 있다. 그래서 두 핸들러의 F-65 관문만 action 을 `file.download` 로
         * 남긴다(그 파일들의 다른 거부는 지금처럼 `file.preview` 그대로다). */
        'file.download|deny|exec_ack_required'    => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 을(를) 확인 절차 없이 받으려 해서 확인을 요청했습니다',
        'file.download|deny|exec_content_token'   => '{actor} 이(가) 프로그램 토큰으로 실행 파일이 든 압축본 「{target}」 을(를) 받으려 해서 거부했습니다 (이 파일은 사람이 2단계 코드를 넣어야 받을 수 있습니다)',
        'share.create|deny|exec_content'          => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 의 공유 링크를 만들려 해서 거부했습니다 (이 파일은 링크로 나갈 수 없습니다)',
        /* 겹 방어 — 링크는 만들 수 없어 보통 닿지 않지만, 닿으면 404 와 함께 기록한다.
         * 받는 쪽에는 로그인한 사람이 없어 문장에 {actor} 가 없다(share.download 의 다른 행과 같다). */
        'share.download|deny|exec_content'        => '실행 파일이 든 압축본 「{target}」 이(가) 공유 링크로 요청되어 거부했습니다 (이 파일은 링크로 나갈 수 없습니다)',
        'file.update|deny|exec_content'           => '{actor} 이(가) 실행 파일이 든 압축본 「{target}」 의 접근 등급을 바꾸려 해서 거부했습니다 (admin-only 로 고정됩니다)',
        'file.upload|deny|nested_zip'             => '{actor} 이(가) 올린 「{target}」 안에 압축 파일이 여러 겹 겹쳐 있어 거부했습니다',
        'file.upload|deny|zip_bomb'               => '{actor} 이(가) 올린 「{target}」 은(는) 압축을 풀면 지나치게 커지거나 항목이 너무 많아(압축 폭탄 의심) 거부했습니다',
        'file.upload|deny|macro_found'            => '{actor} 이(가) 올린 문서 「{target}」 에 매크로가 들어 있어 거부했습니다',
        'file.upload|deny|pdf_active_content'     => '{actor} 이(가) 올린 PDF 「{target}」 에 열리면 실행되는 내용(스크립트·자동 실행)이 들어 있어 거부했습니다',
        'file.upload|deny|hwp_not_hwp'            => '{actor} 이(가) 올린 파일 「{target}」 이(가) 한글 문서 형식이 아닌데 .hwp 이름이라(위장 의심) 거부했습니다',
        'file.upload|deny|double_extension'       => '{actor} 이(가) 이중 확장자 파일 「{target}」 을(를) 올리려 해서 거부했습니다 (실행 파일 위장 의심)',
        'file.upload|deny|quota_exceeded'         => '{actor} 이(가) 파일 「{target}」 을(를) 올리려 했지만 하루 업로드 한도(2GB)를 넘어 거부했습니다',
        'file.upload|error|upload_failed'         => '{actor} 이(가) 파일 「{target}」 을(를) 올리는 중 서버 오류가 나서 되돌렸습니다',

        // ── URL 로 가져오기 (files_fetch, Fetch — 7단계, ARCH 7.4 file.fetch 14행) ──
        'file.fetch|allow'                        => '{actor} 이(가) 허용된 외부 주소에서 파일 「{target}」 을(를) 가져와 {level} 등급으로 저장했습니다',
        'file.fetch|deny|scheme'                  => '{actor} 이(가) https 가 아닌 주소로 파일 가져오기를 요청해서 막았습니다',
        'file.fetch|deny|port'                    => '{actor} 이(가) 443 이 아닌 포트로 파일 가져오기를 요청해서 막았습니다 (내부 포트 탐색 의심)',
        'file.fetch|deny|host_not_allowed'        => '{actor} 이(가) 허용 목록에 없는 주소(또는 IP·localhost·잘못된 표기)로 파일 가져오기를 요청해서 막았습니다',
        'file.fetch|deny|private_ip'              => '{actor} 이(가) 요청한 주소가 내부망 IP 로 해석되어 파일 가져오기를 막았습니다 (SSRF 의심)',
        'file.fetch|deny|redirect'                => '{actor} 이(가) 요청한 주소가 다른 곳으로 넘기는(리다이렉트) 응답을 보내 파일 가져오기를 막았습니다',
        'file.fetch|deny|too_large'               => '{actor} 이(가) 요청한 주소의 파일이 100MB 를 넘어 가져오기를 막았습니다',
        'file.fetch|deny|fetch_failed'            => '{actor} 이(가) 요청한 주소에서 파일을 받을 수 없었습니다 (연결 실패·시간 초과·200 이 아닌 응답)',
        'file.fetch|deny|invalid_file'            => '{actor} 이(가) 외부 주소에서 가져온 파일 「{target}」 이(가) 업로드 규칙(확장자·내용·크기)에 맞지 않아 저장하지 않았습니다',
        'file.fetch|deny|zip_unreadable'          => '{actor} 이(가) 가져온 「{target}」 의 압축 파일 구조를 읽을 수 없어 저장하지 않았습니다',
        'file.fetch|deny|zip_bad_path'            => '{actor} 이(가) 가져온 압축 파일 「{target}」 안에 경로를 벗어나는 항목이 있어 저장하지 않았습니다',
        'file.fetch|deny|zip_blocked_entry'       => '{actor} 이(가) 가져온 압축 파일 「{target}」 안에 실행 파일이 들어 있어 저장하지 않았습니다',
        'file.fetch|deny|nested_zip'              => '{actor} 이(가) 가져온 「{target}」 안에 압축 파일이 여러 겹 겹쳐 있어 저장하지 않았습니다',
        'file.fetch|deny|zip_bomb'                => '{actor} 이(가) 가져온 「{target}」 은(는) 압축을 풀면 지나치게 커지거나 항목이 너무 많아(압축 폭탄 의심) 저장하지 않았습니다',
        'file.fetch|deny|macro_found'             => '{actor} 이(가) 가져온 문서 「{target}」 에 매크로가 들어 있어 저장하지 않았습니다',
        'file.fetch|deny|pdf_active_content'      => '{actor} 이(가) 가져온 PDF 「{target}」 에 열리면 실행되는 내용(스크립트·자동 실행)이 들어 있어 저장하지 않았습니다',
        'file.fetch|deny|hwp_not_hwp'             => '{actor} 이(가) 가져온 파일 「{target}」 이(가) 한글 문서 형식이 아닌데 .hwp 이름이라(위장 의심) 저장하지 않았습니다',
        'file.fetch|deny|rate_limited'            => '{actor} 이(가) 한 시간에 10회를 넘게 파일 가져오기를 요청해서 막았습니다',
        // F-43 — 공개(「링크가 있는 모든 사용자」)가 아닌 문서. 서버는 로그인할 수 없으므로
        // 로그인 화면(HTML)을 파일로 저장하지 않고 거부한다 (ARCH 5.22).
        'file.fetch|deny|needs_login'             => '{actor} 이(가) 로그인이 필요한 클라우드 문서를 가져오려 해서 막았습니다 (공개 링크만 가져올 수 있습니다)',
        'file.fetch|deny|bad_request'             => '{actor} 이(가) 파일 가져오기 요청을 잘못된 형식(URL 없음·등급 오류·팀 오류)으로 보내 거부했습니다',
        'file.fetch|deny|role_not_permitted'      => '{actor} 이(가) 파일 「{target}」 을(를) {level} 등급으로 가져오려 했지만 그 등급으로 올릴 권한이 없어 막았습니다',
        'file.fetch|deny|team_mismatch'           => '{actor} 이(가) 파일 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 가져오려 해서 막았습니다',
        'file.fetch|error|fetch_failed'           => '{actor} 이(가) 외부 주소에서 가져온 파일 「{target}」 을(를) 저장하는 중 서버 오류가 나서 되돌렸습니다',

        // ── 다운로드 (files_download) ──
        'file.download|allow'                     => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 내려받았습니다',
        'file.download|deny|role_not_permitted'   => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 내려받으려 했지만 권한이 없어 막았습니다',
        'file.download|deny|team_mismatch'        => '{actor} 이(가) 다른 팀의 team 등급 파일 「{target}」 을(를) 내려받으려 해서 막았습니다',
        'file.download|error|file_missing'        => '{actor} 이(가) 파일 「{target}」 을(를) 내려받으려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)',
        'file.download|error|hash_mismatch'       => '{actor} 이(가) 파일 「{target}」 을(를) 내려받으려 했지만 저장된 파일의 내용이 기록과 달라 전송을 막았습니다 (변조 의심, 점검 필요)',
        // F-54 (ARCH 5.28) — 악성코드 검사 상태로 막힌 다운로드. 권한 판정을 통과한 뒤의
        // 거부라 사유가 권한과 섞이지 않는다 (ARCH 5.3 [4]).
        'file.download|deny|scan_pending'         => '{actor} 이(가) 아직 검사 중인 파일 「{target}」 을(를) 내려받으려 해서 막았습니다',
        'file.download|deny|scan_infected'        => '{actor} 이(가) 악성코드로 격리된 파일 「{target}」 을(를) 내려받으려 해서 막았습니다',

        // ── 미리보기 (files_preview · files_preview_raw — 12-2, ARCH 7.4 file.preview 7행) ──
        'file.preview|allow'                      => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 미리보기로 열었습니다',
        'file.preview|deny|role_not_permitted'    => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 미리보기로 열려 했지만 권한이 없어 막았습니다',
        'file.preview|deny|team_mismatch'         => '{actor} 이(가) 다른 팀의 team 등급 파일 「{target}」 을(를) 미리보기로 열려 해서 막았습니다',
        'file.preview|deny|preview_not_available' => '{actor} 이(가) 미리보기를 지원하지 않는 형식의 파일 「{target}」 을(를) 원본 표시로 열려 해서 거부했습니다',
        'file.preview|error|file_missing'         => '{actor} 이(가) 파일 「{target}」 을(를) 미리보기로 열려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)',
        'file.preview|error|hash_mismatch'        => '{actor} 이(가) 파일 「{target}」 을(를) 미리보기로 열려 했지만 저장된 파일의 내용이 기록과 달라 표시를 막았습니다 (변조 의심, 점검 필요)',
        'file.preview|error|parse_failed'         => '{actor} 이(가) 파일 「{target}」 의 미리보기를 요청했지만 내용을 읽어 내지 못했습니다 (손상 또는 지원 범위 밖)',
        // F-54 (ARCH 5.28) — 미리보기 두 창구(JSON·raw)가 같은 자리에서 같은 사유로 막는다.
        'file.preview|deny|scan_pending'          => '{actor} 이(가) 아직 검사 중인 파일 「{target}」 을(를) 미리 보려 해서 막았습니다',
        'file.preview|deny|scan_infected'         => '{actor} 이(가) 악성코드로 격리된 파일 「{target}」 을(를) 미리 보려 해서 막았습니다',

        // ── 삭제 (files_delete) ──
        'file.delete|allow'                       => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 삭제했습니다 (30일 휴지통)',
        'file.delete|deny|role_not_permitted'     => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 삭제하려 했지만 볼 권한조차 없어 막았습니다',
        'file.delete|deny|not_owner'              => '{actor} 이(가) 다른 사람이 올린 파일 「{target}」 을(를) 삭제하려 해서 막았습니다',
        'file.delete|deny|team_mismatch'          => '{actor} 이(가) 다른 팀의 파일 「{target}」 을(를) 삭제하려 해서 막았습니다',

        // ── 수정 · 레벨 변경 (files_update — 5단계, ARCH 7.4) ──
        'file.update|allow'                       => '{actor} 이(가) 파일 「{target}」 을(를) 수정했습니다 (바뀐 항목은 상세 기록: description·access_level·team_id·folder_id)',
        'file.update|deny|role_not_permitted'     => '{actor} 이(가) 파일 「{target}」 을(를) 수정하려 했지만 볼 권한조차 없어 막았습니다',
        'file.update|deny|not_owner'              => '{actor} 이(가) 다른 사람이 올린 파일 「{target}」 을(를) 수정하려 해서 막았습니다',
        'file.update|deny|team_mismatch'          => '{actor} 이(가) 다른 팀의 파일 「{target}」 을(를) 수정하려 해서 막았습니다',
        'file.update|deny|bad_request'            => '{actor} 이(가) 파일 「{target}」 수정 요청을 허용되지 않는 형식(모르는 항목·JSON 아님)으로 보내 거부했습니다',
        'file.update|deny|bad_access_level'       => '{actor} 이(가) 파일 「{target}」 을(를) 존재하지 않는 접근 등급으로 바꾸려 해서 거부했습니다',
        'file.update|deny|bad_team'               => '{actor} 이(가) 파일 「{target}」 을(를) 없는 팀 또는 팀 지정 없이 team 등급으로 바꾸려 해서 거부했습니다',
        'file.update|deny|description_too_long'   => '{actor} 이(가) 파일 「{target}」 의 설명을 500자 넘게 바꾸려 해서 거부했습니다',
        'file.update|deny|bad_encoding'           => '{actor} 이(가) 파일 「{target}」 의 설명을 올바르지 않은 문자(UTF-8)로 바꾸려 해서 거부했습니다',
        'file.update|deny|no_change'              => '{actor} 이(가) 파일 「{target}」 수정을 요청했지만 바뀌는 값이 없어 거부했습니다',
        'file.level_change|allow'                 => '{actor} 이(가) 파일 「{target}」 의 접근 등급을 {level} 등급으로 바꿨습니다 (이전 등급은 상세 기록)',
        'file.level_change|deny|downgrade_not_permitted' => '{actor} 이(가) 파일 「{target}」 의 접근 등급을 {level} 등급으로 낮추려 했지만 등급을 낮추는 것은 관리자만 할 수 있어 막았습니다',
        'file.level_change|deny|role_not_permitted'      => '{actor} 이(가) 파일 「{target}」 의 접근 등급을 {level} 등급으로 바꾸려 했지만 그 등급을 지정할 권한이 없어 막았습니다',
        'file.level_change|deny|team_mismatch'           => '{actor} 이(가) 파일 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 바꾸려 해서 막았습니다',

        // ── 감사 로그 조회 (audit_list — 8단계, ARCH 7.4 `audit.view|allow`) ──
        // 로그를 본 것도 로그다. 적용한 필터는 detail.filters 에 남는다 (ARCH 7.5).
        'audit.view|allow'                        => '{actor} 이(가) 감사 로그를 조회했습니다 (필터는 상세 기록)',

        // ── 운영 배치 (9단계, ARCH 7.4 · 11.1) ──
        // 여기 8행은 사람이 아니라 cron 이 남긴다 — actor_* 가 전부 NULL 이므로 {actor} 는
        // '(로그인 전)' 이 된다. 그래서 문장에 {actor} 를 쓰는 것은 backup.restore 한 행뿐이고
        // (사람이 손으로 실행하는 복구), 나머지는 주어 없이 "무엇이 일어났나" 만 적는다.
        // 근거 수치(산출물·검증값·검사 건수·불일치 목록·경보 근거)는 전부 detail 로 간다 (ARCH 7.5).
        'file.purge|allow'                        => '휴지통에 30일 넘게 있던 {level} 등급 파일 「{target}」 을(를) 배치가 영구 삭제했습니다',
        'file.purge|error|purge_failed'           => '휴지통 파일 「{target}」 영구 삭제 중 오류가 나서 건너뛰었습니다 (점검 필요)',
        // F-54 배치 (scripts/scan_pending.php — ARCH 5.28 · 11.1 「매분」). cron 이 남기므로
        // actor_* 는 NULL 이고 문장에 {actor} 를 쓰지 않는다(file.purge 와 같은 성질).
        // 시그니처 이름·해시·올린 사람은 문장이 아니라 detail 로 간다 (ARCH 7.5).
        'file.scan|allow'                         => '배치가 파일 「{target}」 을(를) 검사했습니다 — 이상 없음',
        'file.scan|deny|infected'                 => '배치가 파일 「{target}」 에서 악성코드를 발견해 격리했습니다',
        'file.scan|error|scan_failed'             => '파일 「{target}」 검사 중 오류가 나서 다음 실행에 다시 시도합니다',
        'backup.run|allow'                        => '백업이 완료되었습니다 (대상·산출물·검증값은 상세 기록)',
        'backup.run|error|backup_failed'          => '백업이 실패했습니다 (사유는 상세 기록·app.log) — 2회 연속이면 경보 AL-8',
        'backup.restore|allow'                    => '{actor} 이(가) 백업에서 복구를 실행했습니다 (대상 시점·범위는 상세 기록)',
        // 16-2 (SECURITY 8.4-10): 복구가 **실패**한 경우의 자리. 이 행이 없던 동안 실패한 복구는
        // 기록될 곳이 없어(문장 표에 allow 뿐) 로그가 조용했고, 그 자리에 남은 것은 롤백된
        // 트랜잭션의 `allow` 한 줄(audit_logs id 1177)이었다. 복구 스크립트는 성공을 확인한
        // 뒤에만 allow 를 남기고, 중단된 경로에서는 이 error 를 남긴다(어느 단계에서 멈췄는지는 detail.step).
        'backup.restore|error|restore_failed'     => '백업 복구가 실패해 되돌렸습니다 (단계는 상세 기록)',
        'audit.integrity|allow'                   => '정합성·무결성 점검을 마쳤습니다 (검사 건수·불일치 0)',
        'audit.integrity|error|mismatch'          => '정합성·무결성 점검에서 불일치가 발견되었습니다 (목록은 상세 기록) — 경보 AL-6',
        'alert.raised|allow'                      => '경보 {target} 조건이 충족되어 알림을 보냈습니다 (근거는 상세 기록)',

        // ── 휴지통 복구 (10단계 files_restore — F-11, Admin 만) ──
        'file.restore|allow'                      => '{actor} 이(가) 휴지통의 {level} 등급 파일 「{target}」 을(를) 복구했습니다',
        'file.restore|deny|not_found_in_trash'    => '{actor} 이(가) 휴지통에 없는 파일을 복구하려 해서 거부했습니다',

        // ── 계정 관리 (10단계 users_* — F-10, Admin 만. PRD S-07 "관리자 행동 사유") ──
        // 사유(detail.reason_text)·이전값/새값(detail.before/after)·끊은 세션 수는 전부 상세에 남는다.
        'user.create|allow'                       => '{actor} 이(가) 계정 「{target}」 을(를) 만들었습니다 (역할·팀·사유는 상세 기록)',
        'user.create|deny|bad_request'            => '{actor} 이(가) 계정 생성 요청을 잘못된 형식(아이디·비밀번호·역할·팀·사유)으로 보내 거부했습니다',
        'user.create|deny|already_exists'         => '{actor} 이(가) 이미 있는 아이디 「{target}」 로 계정을 만들려 해서 거부했습니다',
        'user.role_change|allow'                  => '{actor} 이(가) 계정 「{target}」 의 역할을 바꿨습니다 (이전 → 새 역할·사유는 상세 기록)',
        'user.role_change|deny|bad_request'       => '{actor} 이(가) 계정 「{target}」 의 역할 변경을 잘못된 형식(역할·팀·사유 없음)으로 요청해서 거부했습니다',
        'user.role_change|deny|self_change'       => '{actor} 이(가) 자기 자신의 역할을 바꾸려 해서 거부했습니다 (관리자 권한 자기 부여·자기 강등 방지)',
        'user.role_change|deny|last_admin'        => '{actor} 이(가) 마지막 활성 관리자 계정 「{target}」 의 역할을 낮추려 해서 거부했습니다 (관리자 0명 방지)',
        'user.deactivate|allow'                   => '{actor} 이(가) 계정 「{target}」 을(를) 비활성화하고 모든 세션을 끊었습니다 (사유는 상세 기록)',
        'user.deactivate|deny|bad_request'        => '{actor} 이(가) 계정 「{target}」 비활성화를 사유 없이 요청해서 거부했습니다',
        'user.deactivate|deny|self_change'        => '{actor} 이(가) 자기 자신을 비활성화하려 해서 거부했습니다',
        'user.deactivate|deny|last_admin'         => '{actor} 이(가) 마지막 활성 관리자 계정 「{target}」 을(를) 비활성화하려 해서 거부했습니다 (관리자 0명 방지)',
        'user.activate|allow'                     => '{actor} 이(가) 계정 「{target}」 을(를) 다시 활성화했습니다 (사유는 상세 기록)',
        'user.activate|deny|bad_request'          => '{actor} 이(가) 계정 「{target}」 재활성화를 잘못된 형식(사유 없음 등)으로 요청해서 거부했습니다',
        'user.expiry_change|allow'                => '{actor} 이(가) 계정 「{target}」 의 만료일을 바꿨습니다 (이전 → 새 값·사유는 상세 기록)',

        // ── Guest 만료 (10단계 — SECURITY R-12) ──
        // 두 자리에서 막는다: 로그인 시도(guest_expired)와 **이미 살아 있던 세션**(session_expired).
        // 뒤엣것이 없으면 만료 직전에 로그인한 Guest 가 최대 8시간 더 돌아다닌다 (10단계 검토 실측).
        'user.expired|deny|guest_expired'         => '{target} 계정으로 로그인을 시도했지만 만료된 Guest 계정입니다',
        'user.expired|deny|session_expired'       => '{actor} 의 Guest 계정이 만료되어 살아 있던 세션을 끊었습니다',

        // ── 비밀번호 변경 · 2단계 인증 (10단계 — PRD S-09-4, SECURITY R-05 · R-10) ──
        // 비밀번호·TOTP 비밀·코드는 어떤 형태로도 이 표의 문장에 들어가지 않는다.
        'auth.password_change|allow'                    => '{actor} 이(가) 비밀번호를 바꾸고 다른 기기의 세션을 모두 끊었습니다',
        'auth.password_change|deny|bad_credentials'     => '{actor} 이(가) 비밀번호를 바꾸려 했지만 현재 비밀번호가 틀렸습니다',
        'auth.password_change|deny|weak_password'       => '{actor} 이(가) 비밀번호를 바꾸려 했지만 새 비밀번호가 규칙(10자 이상)에 맞지 않습니다',
        'auth.mfa_enroll|allow'                         => '{actor} 이(가) 2단계 인증(TOTP)을 켰습니다',
        'auth.mfa_enroll|deny|bad_code'                 => '{actor} 이(가) 2단계 인증을 켜려 했지만 확인 코드가 틀렸습니다',
        'auth.mfa_enroll|deny|already_enabled'          => '{actor} 이(가) 이미 켜져 있는 2단계 인증을 다시 등록하려 해서 거부했습니다',
        'auth.mfa_disable|allow'                        => '{actor} 이(가) 2단계 인증(TOTP)을 껐습니다',
        'auth.mfa_disable|deny|bad_code'                => '{actor} 이(가) 2단계 인증을 끄려 했지만 인증 코드가 틀렸습니다',
        'auth.mfa_disable|deny|bad_credentials'         => '{actor} 이(가) 2단계 인증을 끄려 했지만 현재 비밀번호가 틀렸습니다',
        'auth.mfa|deny|bad_code'                        => '{target} 계정으로 로그인하면서 2단계 인증 코드를 틀렸습니다',
        'auth.mfa|deny|code_required'                   => '{target} 계정은 2단계 인증이 켜져 있는데 코드 없이 로그인을 시도해 막았습니다',

        // ── 2단계 인증 코드 1회성 (13단계 — SECURITY R-45, PRD T-31, ARCH 4.0 · 7.4) ──
        // RFC 6238 §5.2 "한 번 쓴 OTP 는 거부". 맞은 코드라도 `users.mfa_last_step` 이하의
        // 스텝이면 **이미 쓴 코드**이므로 막는다 — 어깨너머로 본 코드의 수명을 90초에서 0 으로 줄인다.
        'auth.mfa|deny|code_reused'                     => '{target} 계정으로 로그인하면서 이미 한 번 쓴 2단계 인증 코드를 다시 냈습니다',
        'auth.mfa_disable|deny|code_reused'             => '{actor} 이(가) 2단계 인증을 끄려 했지만 이미 한 번 쓴 인증 코드를 다시 냈습니다',
        'auth.mfa_enroll|deny|code_reused'              => '{actor} 이(가) 2단계 인증을 켜려 했지만 이미 한 번 쓴 확인 코드를 다시 냈습니다',

        // ── 최초 로그인 강제 절차 (11단계 — PRD F-16, ARCH 4.0, SECURITY R-05 · R-33 · R-39) ──
        // 관문(auth.gate)은 라우터 한 곳에서만 기록한다. 막힌 사람이 곧 대상이므로 {target} 을 쓴다.
        // 임시 비밀번호 값은 어느 문장에도 들어가지 않는다 — detail 에도 사실(true)만 남는다.
        'auth.gate|deny|password_change_required'       => '{target} 이(가) 비밀번호를 아직 바꾸지 않은 상태로 다른 기능을 요청해서 거부했습니다',
        'auth.gate|deny|mfa_setup_required'             => '{target} 이(가) 2단계 인증을 아직 설정하지 않은 상태로 다른 기능을 요청해서 거부했습니다',

        // ── 관리 기능 재인증 — sudo 모드 (16-1 — PRD F-23, ARCH 4.0 · 7.4, SECURITY R-59) ──
        // 관문(auth.gate)은 라우터 한 곳에서만 기록한다. 막힌 사람이 곧 대상이므로 {target} 을 쓴다
        // (강제 절차 관문의 두 행과 같은 모양). 재인증 창구의 성공·실패는 auth.reauth 로 남고,
        // 비밀번호는 어느 문장에도 들어가지 않는다.
        'auth.gate|deny|reauth_required'                => '{target} 이(가) 비밀번호를 다시 확인하지 않은 채 관리 기능을 요청해서 거부했습니다',
        'auth.reauth|allow'                             => '{actor} 이(가) 관리 기능을 쓰려고 비밀번호를 다시 확인했습니다',
        'auth.reauth|deny|bad_credentials'              => '{actor} 이(가) 관리 기능을 쓰려고 비밀번호를 다시 확인했지만 비밀번호가 틀렸습니다',
        'auth.password_change|deny|same_password'       => '{actor} 이(가) 비밀번호를 현재와 같은 값으로 바꾸려 해서 거부했습니다',

        // ── 「역할로 보기」 (26단계 — PRD F-39, ARCH 5.20 · 7.4, SECURITY R-74) ──────────
        // 실제 계정은 {actor}(= actor_role 열의 실제 역할)이고, 고른 역할·팀·지점은 detail 에 남는다.
        // 자동 해제(act.expire)는 사용자가 누른 행동이 아니라 **서버가 판정한 사실**이라
        // allow 로 남기고 사유(idle·role_lost)를 detail 에 적는다 — 거부가 아니기 때문이다.
        'act.start|allow'                               => '{actor} 이(가) 다른 역할로 보기를 시작했습니다 (역할·팀은 상세 기록)',
        'act.start|deny|bad_request'                    => '{actor} 이(가) 역할로 보기를 허용되지 않는 형식(모르는 항목·역할·팀)으로 요청해서 거부했습니다',
        'act.start|deny|role_not_permitted'             => '{actor} 이(가) 역할로 보기를 시작할 자격 없이 요청해서 막았습니다',
        'act.stop|allow'                                => '{actor} 이(가) 역할로 보기를 끝내고 원래 역할로 돌아왔습니다',
        'act.expire|allow'                              => '{actor} 의 역할로 보기가 자동으로 풀렸습니다 (사유는 상세 기록)',
        'user.password_reset|allow'                     => '{actor} 이(가) 계정 「{target}」 의 임시 비밀번호를 재발급하고 모든 세션을 끊었습니다 (사유는 상세 기록)',
        'user.password_reset|deny|self_change'          => '{actor} 이(가) 자기 자신의 임시 비밀번호를 재발급하려 해서 거부했습니다',
        'user.password_reset|deny|bad_request'          => '{actor} 이(가) 계정 「{target}」 임시 비밀번호 재발급을 사유 없이 요청해서 거부했습니다',
        'user.mfa_reset|allow'                          => '{actor} 이(가) 계정 「{target}」 의 2단계 인증을 초기화하고 모든 세션을 끊었습니다 (사유는 상세 기록)',
        'user.mfa_reset|deny|self_change'               => '{actor} 이(가) 자기 자신의 2단계 인증을 초기화하려 해서 거부했습니다',
        'user.mfa_reset|deny|bad_request'               => '{actor} 이(가) 계정 「{target}」 2단계 인증 초기화를 사유 없이 요청해서 거부했습니다',

        // ── 조직(팀) 관리 (12-3 org_team_* — F-19, ARCH 5.9 · 7.4, Admin 만) ──
        // 사유(detail.reason_text)와 이전값→새값(detail.before/after)은 전부 상세에 남는다.
        // 팀을 지우는 행동은 없다 — 비활성화도 team.update 의 is_active 변경으로 기록된다.
        'team.create|allow'                             => '{actor} 이(가) 팀 「{target}」 을(를) 만들었습니다 (사유는 상세 기록)',
        'team.create|deny|bad_request'                  => '{actor} 이(가) 팀 생성 요청을 잘못된 형식(이름·사유)으로 보내 거부했습니다',
        'team.create|deny|already_exists'               => '{actor} 이(가) 이미 있는 이름 「{target}」 으로 팀을 만들려 해서 거부했습니다',
        'team.update|allow'                             => '{actor} 이(가) 팀 「{target}」 의 정보를 바꿨습니다 (이전값→새값·사유는 상세 기록)',
        'team.update|deny|bad_request'                  => '{actor} 이(가) 팀 「{target}」 변경 요청을 잘못된 형식으로 보내 거부했습니다',
        'team.update|deny|not_found'                    => '{actor} 이(가) 없는 팀을 바꾸려 해서 거부했습니다',
        'team.update|deny|team_has_members'             => '{actor} 이(가) 구성원이 남아 있는 팀 「{target}」 을(를) 비활성화하려 해서 거부했습니다 (먼저 재배정)',
        'team.update|deny|already_exists'               => '{actor} 이(가) 팀 「{target}」 의 이름을 이미 있는 이름으로 바꾸려 해서 거부했습니다',

        // ── 공유 링크 (15-2 share_* — F-13, ARCH 5.12 · 7.4) ──
        // 토큰 원문·그 해시는 어느 문장에도, 어느 detail 에도 들어가지 않는다.
        // 받는 쪽(share.download)에는 **로그인한 사람이 없다** — 그래서 문장에 {actor} 를 쓰지
        // 않고, "누가 열어 줬나" 는 detail.issued_by(발급자)·detail.share_id 로 남는다.
        'share.create|allow'                            => '{actor} 이(가) 파일 「{target}」 의 공유 링크를 만들었습니다 (만료·횟수·사유는 상세 기록)',
        'share.create|deny|role_not_permitted'          => '{actor} 이(가) 파일 「{target}」 의 공유 링크를 만들려 했지만 볼 권한조차 없어 막았습니다',
        'share.create|deny|team_mismatch'               => '{actor} 이(가) 다른 팀의 파일 「{target}」 의 공유 링크를 만들려 해서 막았습니다',
        'share.create|deny|not_owner'                   => '{actor} 이(가) 다른 사람이 올린 파일 「{target}」 의 공유 링크를 만들려 해서 막았습니다',
        'share.create|deny|bad_request'                 => '{actor} 이(가) 공유 링크 만들기를 허용되지 않는 형식(모르는 항목·만료·횟수·사유)으로 요청해서 거부했습니다',
        'share.revoke|allow'                            => '{actor} 이(가) 파일 「{target}」 의 공유 링크를 회수했습니다 (이후 그 링크로는 받을 수 없습니다)',
        'share.revoke|deny|not_owner'                   => '{actor} 이(가) 자기가 만들지 않은 공유 링크를 회수하려 해서 막았습니다',
        'share.revoke|deny|bad_request'                 => '{actor} 이(가) 이미 회수된 공유 링크를 다시 회수하려 해서 거부했습니다',
        'share.download|allow'                          => '공유 링크로 파일 「{target}」 을(를) 내려받았습니다 (발급자·링크 id·남은 횟수는 상세 기록)',
        'share.download|deny|not_found'                 => '없는 공유 링크로 파일을 받으려는 요청이 있어 막았습니다',
        'share.download|deny|revoked'                   => '회수된 공유 링크로 파일 「{target}」 을(를) 받으려 해서 막았습니다',
        'share.download|deny|expired'                   => '만료된 공유 링크로 파일 「{target}」 을(를) 받으려 해서 막았습니다',
        'share.download|deny|exhausted'                 => '허용 횟수를 다 쓴 공유 링크로 파일 「{target}」 을(를) 받으려 해서 막았습니다',
        'share.download|deny|file_gone'                 => '이미 삭제된 파일 「{target}」 을(를) 가리키는 공유 링크로 받으려 해서 막았습니다',
        'share.download|error|file_missing'             => '공유 링크로 파일 「{target}」 을(를) 보내려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)',
        'share.download|error|hash_mismatch'            => '공유 링크로 파일 「{target}」 을(를) 보내려 했지만 저장된 파일의 내용이 기록과 달라 전송을 막았습니다 (변조 의심, 점검 필요)',
        // F-54 (ARCH 5.28) — 링크가 유효해도 검사 전·격리 파일은 나가지 않는다.
        // 이 창구에는 로그인한 사람이 없으므로 다른 share 행처럼 {actor} 를 쓰지 않는다.
        'share.download|deny|scan_pending'              => '공유 링크로 아직 검사 중인 파일 「{target}」 을(를) 내려받으려 해서 막았습니다',
        'share.download|deny|scan_infected'             => '공유 링크로 악성코드가 격리된 파일 「{target}」 을(를) 내려받으려 해서 막았습니다',

        // ── 민감정보 패턴 검출 (15-3 — F-22, ARCH 5.13 · 7.4, SECURITY R-58) ──
        // 문장에도 detail 에도 **패턴 이름과 건수만** 남는다 (검출된 숫자 원문은 금지, PRD S-07).
        'file.upload|deny|sensitive_level_required'     => '{actor} 이(가) 민감정보로 보이는 내용이 든 파일 「{target}」 을(를) {level} 등급으로 올리려 해서 거부했습니다 (검출된 패턴 이름·건수는 상세 기록)',
        'file.level_change|deny|sensitive_level_required' => '{actor} 이(가) 민감정보로 보이는 내용이 든 파일 「{target}」 의 접근 등급을 {level} 등급으로 낮추려 해서 거부했습니다',

        // ── 권한 매트릭스 편집 (17-2 admin_permissions_update — F-25, ARCH 5.15 · 7.4, SECURITY R-60) ──
        // {target} 은 바뀐 **칸 이름** `역할|등급`(예 `Guest|internal`)이다 — 파일명도 계정명도 아니다.
        // 허용 행은 **칸 하나에 한 줄**이고, 이전값 → 새값·사유·코드 확인 사실은 detail 에 남는다
        // (`before`·`after`·`reason_text`·`otp_verified`). 2단계 인증 코드 자체는 어디에도 남지 않는다.
        // 거부 사유 넷은 ARCH 5.15 의 순서와 같다: 형식 → 코드 → 코드 재사용 → 불변 규칙.
        'permission.change|allow'                       => '{actor} 이(가) 권한 매트릭스의 {target} 칸을 바꿨습니다 (이전값→새값·사유·코드 확인은 상세 기록)',
        'permission.change|deny|bad_request'            => '{actor} 이(가) 권한 매트릭스 변경을 허용되지 않는 형식(모르는 항목·사유·코드)으로 요청해서 거부했습니다',
        'permission.change|deny|bad_code'               => '{actor} 이(가) 권한 매트릭스를 바꾸려 했지만 2단계 인증 코드가 맞지 않아 거부했습니다',
        'permission.change|deny|code_reused'            => '{actor} 이(가) 권한 매트릭스를 바꾸려 했지만 이미 쓴 2단계 인증 코드라 거부했습니다',
        'permission.change|deny|invariant'              => '{actor} 이(가) 권한 매트릭스의 바꿀 수 없는 규칙을 어기는 변경을 요청해서 거부했습니다 (어긴 규칙은 상세 기록)',

        // ── F-43 URL 가져오기 허용 사이트 (PRD F-43 · 7.11 AL-18, ARCH 5.22 · 7.4) ──
        //   허용 호스트 한 줄은 "서버가 어디로 나갈 수 있나" 를 바꾸는 값이라(PRD S-05),
        //   권한 매트릭스와 **같은 급**의 기록을 남긴다: 이전값→새값·사유·코드 확인 사실.
        //   행을 지우는 갈래가 없으므로 `deny|…` 에도 삭제 관련 사유가 없다.
        'fetch_host.change|allow'                       => '{actor} 이(가) URL 가져오기 허용 사이트 {target} 을(를) 바꿨습니다 (이전값→새값·사유·코드 확인은 상세 기록)',
        'fetch_host.change|deny|bad_request'            => '{actor} 이(가) 허용 사이트 변경을 허용되지 않는 형식(모르는 항목·사유·코드)으로 요청해서 거부했습니다',
        'fetch_host.change|deny|bad_code'               => '{actor} 이(가) 허용 사이트를 바꾸려 했지만 2단계 인증 코드가 맞지 않아 거부했습니다',
        'fetch_host.change|deny|code_reused'            => '{actor} 이(가) 허용 사이트를 바꾸려 했지만 이미 쓴 2단계 인증 코드라 거부했습니다',
        'fetch_host.change|deny|invariant'              => '{actor} 이(가) 허용 사이트에 넣을 수 없는 주소(와일드카드·IP·사내망 이름·형식 오류)를 요청하거나 바뀌는 것이 없어 거부했습니다 (어긴 규칙은 상세 기록)',

        // ── 폴더 (18-1 folders_* — F-26, ARCH 5.16 · 7.4) ──
        // {target} 은 **폴더 이름**이다 — 사용자가 정한 자유 텍스트이며(파일명과 같은 성격),
        // 조회 화면은 다른 입력과 똑같이 textContent 로 그린다 (ARCH 7.4 마지막 규칙).
        // {level} 은 폴더의 접근 등급이다 — 폴더도 파일과 같은 등급 체계를 갖는다(F-26).
        // 폴더 id·부모 id·깊이·이전값→새값은 detail(folder_id·parent_id·depth·
        // folder_before·folder_after)에만 남는다.
        //
        // `folder.read` 는 **거부만** 남긴다 — 트리·목록 조회는 양이 많아 허용을 남기지 않는
        // 기존 원칙 그대로이고(ARCH 5.1), 못 읽는 폴더를 열어 보려 한 시도는 남긴다.
        'folder.read|deny|role_not_permitted'           => '{actor} 이(가) 볼 권한이 없는 {level} 등급 폴더를 열어 보려 해서 막았습니다',
        'folder.read|deny|team_mismatch'                => '{actor} 이(가) 다른 팀의 폴더를 열어 보려 해서 막았습니다',
        'folder.read|deny|not_found'                    => '{actor} 이(가) 없는 폴더를 열어 보려 해서 막았습니다',

        'folder.create|allow'                           => '{actor} 이(가) 폴더 「{target}」 을(를) {level} 등급으로 만들었습니다 (부모·깊이는 상세 기록)',
        'folder.create|deny|bad_request'                => '{actor} 이(가) 폴더 만들기를 허용되지 않는 형식(모르는 항목·이름 규칙·등급·팀·없는 부모)으로 요청해서 거부했습니다',
        'folder.create|deny|conflict'                   => '{actor} 이(가) 같은 위치에 이미 있는 이름 「{target}」 으로 폴더를 만들려 해서 거부했습니다',
        'folder.create|deny|depth_exceeded'             => '{actor} 이(가) 폴더 「{target}」 을(를) 최대 깊이(5단계)보다 깊은 곳에 만들려 해서 거부했습니다',
        'folder.create|deny|role_not_permitted'         => '{actor} 이(가) 폴더 「{target}」 을(를) {level} 등급으로 만들려 했지만 그 등급을 지정할 권한이 없어 막았습니다',
        'folder.create|deny|team_mismatch'              => '{actor} 이(가) 폴더 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 만들려 해서 막았습니다',
        'folder.create|deny|parent_narrower'            => '{actor} 이(가) 폴더 「{target}」 을(를) 부모 폴더보다 넓은 {level} 등급으로 만들려 해서 거부했습니다',

        'folder.update|allow'                           => '{actor} 이(가) 폴더 「{target}」 의 이름 또는 위치를 바꿨습니다 (이전값→새값·사유는 상세 기록)',
        'folder.update|deny|not_found'                  => '{actor} 이(가) 없는 폴더를 바꾸려 해서 거부했습니다',
        'folder.update|deny|bad_request'                => '{actor} 이(가) 폴더 「{target}」 변경을 허용되지 않는 형식(모르는 항목·이름 규칙·없는 부모·바뀌는 값 없음)으로 요청해서 거부했습니다',
        'folder.update|deny|conflict'                   => '{actor} 이(가) 폴더 「{target}」 의 이름을 같은 위치에 이미 있는 이름으로 바꾸려 해서 거부했습니다',
        'folder.update|deny|cycle'                      => '{actor} 이(가) 폴더 「{target}」 을(를) 자기 자신 또는 자기 하위 폴더 아래로 옮기려 해서 거부했습니다',
        'folder.update|deny|depth_exceeded'             => '{actor} 이(가) 폴더 「{target}」 을(를) 옮기면 하위 폴더가 최대 깊이(5단계)를 넘게 되어 거부했습니다',
        'folder.update|deny|parent_narrower'            => '{actor} 이(가) 폴더 「{target}」 을(를) 자기보다 좁은 등급의 폴더 아래로 옮기려 해서 거부했습니다',
        'folder.update|deny|not_owner'                  => '{actor} 이(가) 다른 사람이 만든 폴더 「{target}」 을(를) 바꾸려 해서 막았습니다',
        'folder.update|deny|team_mismatch'              => '{actor} 이(가) 다른 팀의 폴더 「{target}」 을(를) 바꾸려 해서 막았습니다',
        'folder.update|deny|role_not_permitted'         => '{actor} 이(가) 폴더 「{target}」 을(를) 바꾸려 했지만 볼 권한조차 없어 막았습니다',

        // 등급 변경은 따로 남긴다 — 파일의 file.update / file.level_change 와 같은 가름이다.
        // 「하위에도 적용」(cascade)으로 함께 바뀐 항목은 항목마다 자기 행(file.level_change ·
        // folder.level_change)을 남기고 detail.cascade_from 에 시작한 폴더 id 를 적는다.
        'folder.level_change|allow'                     => '{actor} 이(가) 폴더 「{target}」 의 접근 등급을 {level} 등급으로 바꿨습니다 (이전 등급·하위 적용 여부는 상세 기록)',
        'folder.level_change|deny|downgrade_not_permitted' => '{actor} 이(가) 폴더 「{target}」 의 접근 등급을 {level} 등급으로 넓히려 했지만 등급을 넓히는 것은 관리자만 할 수 있어 막았습니다',
        'folder.level_change|deny|role_not_permitted'   => '{actor} 이(가) 폴더 「{target}」 의 접근 등급을 {level} 등급으로 바꾸려 했지만 그 등급을 지정할 권한이 없어 막았습니다',
        'folder.level_change|deny|team_mismatch'        => '{actor} 이(가) 폴더 「{target}」 을(를) 자기 팀이 아닌 팀의 team 등급으로 바꾸려 해서 막았습니다',
        'folder.level_change|deny|parent_narrower'      => '{actor} 이(가) 폴더 「{target}」 의 등급을 부모 폴더보다 넓은 {level} 등급으로 바꾸려 해서 거부했습니다',
        'folder.level_change|deny|children_exceed'      => '{actor} 이(가) 폴더 「{target}」 을(를) {level} 등급으로 좁히려 했지만 그보다 넓은 하위 폴더·파일이 남아 있어 거부했습니다 (개수는 상세 기록)',
        'folder.level_change|deny|not_owner'            => '{actor} 이(가) 폴더 「{target}」 의 등급을 하위까지 함께 좁히려 했지만 자기가 고칠 수 없는 항목이 있어 전부 되돌렸습니다 (개수는 상세 기록)',

        'folder.delete|allow'                           => '{actor} 이(가) 빈 폴더 「{target}」 을(를) 지웠습니다 (부모·깊이는 상세 기록)',
        'folder.delete|deny|not_found'                  => '{actor} 이(가) 없는 폴더를 지우려 해서 거부했습니다',
        // 26단계 (PRD F-41) — 「무엇이 막았나」 를 셋으로 갈랐다. 전에는 한 줄이라 로그만 보고는
        // 하위 폴더인지·살아 있는 파일인지·휴지통 파일인지 알 수 없었다.
        // `folder_has_trashed` 는 확인 한 번이면 지울 수 있는 상태다(거부이자 안내).
        'folder.delete|deny|folder_not_empty'           => '{actor} 이(가) 살아 있는 파일이 남아 있는 폴더 「{target}」 을(를) 지우려 해서 거부했습니다',
        'folder.delete|deny|folder_has_children'        => '{actor} 이(가) 하위 폴더가 남아 있는 폴더 「{target}」 을(를) 지우려 해서 거부했습니다',
        'folder.delete|deny|folder_has_trashed'         => '{actor} 이(가) 휴지통 파일만 남은 폴더 「{target}」 을(를) 확인 없이 지우려 해서 되물었습니다',
        'folder.delete|deny|not_owner'                  => '{actor} 이(가) 다른 사람이 만든 폴더 「{target}」 을(를) 지우려 해서 막았습니다',
        'folder.delete|deny|team_mismatch'              => '{actor} 이(가) 다른 팀의 폴더 「{target}」 을(를) 지우려 해서 막았습니다',
        'folder.delete|deny|role_not_permitted'         => '{actor} 이(가) 폴더 「{target}」 을(를) 지우려 했지만 볼 권한조차 없어 막았습니다',

        // 18-1 — 파일 쪽에 늘어난 거부 사유 (F-26 불변 규칙 ①).
        // 파일을 폴더로 옮기는 것 자체는 기존 file.update allow 로 남는다
        // (detail.folder_before / folder_after) — 새 action 을 만들지 않는다.
        'file.upload|deny|bad_folder'                   => '{actor} 이(가) 파일 「{target}」 을(를) 없는 폴더 또는 볼 수 없는 폴더에 올리려 해서 거부했습니다',
        'file.upload|deny|parent_narrower'              => '{actor} 이(가) 파일 「{target}」 을(를) 폴더보다 넓은 {level} 등급으로 올리려 해서 거부했습니다',
        'file.fetch|deny|bad_folder'                    => '{actor} 이(가) 가져온 파일을 없는 폴더 또는 볼 수 없는 폴더에 저장하려 해서 거부했습니다',
        'file.fetch|deny|parent_narrower'               => '{actor} 이(가) 가져온 파일을 폴더보다 넓은 {level} 등급으로 저장하려 해서 거부했습니다',
        'file.update|deny|bad_folder'                   => '{actor} 이(가) 파일 「{target}」 을(를) 없는 폴더 또는 볼 수 없는 폴더로 옮기려 해서 거부했습니다',
        'file.update|deny|parent_narrower'              => '{actor} 이(가) 파일 「{target}」 을(를) 자기보다 좁은 등급의 폴더로 옮기려 해서 거부했습니다',
        'file.level_change|deny|parent_narrower'        => '{actor} 이(가) 파일 「{target}」 의 접근 등급을 그 파일이 든 폴더보다 넓은 {level} 등급으로 바꾸려 해서 거부했습니다',
    ];

    /** 자리표시자에 넣을 값이 없을 때 (ARCH 7.4) */
    private const ACTOR_ANONYMOUS = '(로그인 전)';
    private const VALUE_UNKNOWN   = '?';

    /** 요약 문장 최대 길이 (ARCH 7.4 "문장 길이는 1,000자에서 자른다") */
    private const SUMMARY_MAX_CHARS = 1000;

    /**
     * 값 하나의 최대 길이 — target_name·access_level·user_agent·detail 문자열
     * (ARCH 7.5 "문자열은 500자에서 잘린다").
     *
     * ★ 단위는 **글자 수**다 (13단계, SECURITY R-47, PRD T-28).
     *   2026-09-12 전면 점검 이전에는 `substr(…, 0, 500)` 으로 **바이트**를 잘랐다.
     *   한글은 한 자가 3바이트라 167자를 넘으면 글자 중간에서 잘리고, 그렇게 만들어진
     *   깨진 UTF-8 이 뒤의 `iconv_strlen()` 에 닿아 경고 → 예외 → 500 이 되었다.
     *   감사 기록이 실패하면 요청도 실패하므로(ARCH 4.0 [6]) **거부가 기록 없이 사라진다** —
     *   공격자는 긴 다국어 이름만 붙이면 시도를 남기지 않을 수 있었다(PRD S-07 위반).
     *   그래서 자르기는 UTF-8 경계(`iconv_substr`)로 하고, 들어온 값이 애초에 잘못된
     *   UTF-8 이어도 기록이 **실패하지 않게** 대체 문자로 바꾼 뒤 자른다.
     */
    private const VALUE_MAX_CHARS = 500;

    /** 잘못된 바이트를 대신할 글자 — U+FFFD REPLACEMENT CHARACTER */
    private const UTF8_REPLACEMENT = "\u{FFFD}";

    /**
     * 올바른 UTF-8 한 글자를 그대로 두고(`(*SKIP)(*FAIL)`), 그 밖의 바이트 한 개만 잡는 규칙.
     * mbstring 확장이 이 서버에 없으므로(php -m: iconv 만 있다) 표준 확장 없이 PCRE 로 처리한다.
     * 대안인 `iconv('UTF-8','UTF-8//IGNORE',…)` 는 구현에 따라 경고를 내고 false 를 돌려주는데,
     * bootstrap 이 경고를 예외로 바꾸므로(= 기록 실패) 이 경로에서는 쓸 수 없다.
     */
    private const UTF8_VALID_RE = '/(?:
          [\x00-\x7F]
        | [\xC2-\xDF][\x80-\xBF]
        | \xE0[\xA0-\xBF][\x80-\xBF]
        | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}
        | \xED[\x80-\x9F][\x80-\xBF]
        | \xF0[\x90-\xBF][\x80-\xBF]{2}
        | [\xF1-\xF3][\x80-\xBF]{3}
        | \xF4[\x80-\x8F][\x80-\xBF]{2}
        )(*SKIP)(*FAIL)|./sx';

    private const SQL_INSERT = <<<'SQL'
        INSERT INTO audit_logs
            (request_id, actor_id, actor_name, actor_role, action,
             target_type, target_id, target_name, access_level,
             result, reason, ip, user_agent, detail, summary)
        VALUES
            (CAST(:request_id AS uuid), :actor_id, :actor_name, :actor_role, :action,
             :target_type, :target_id, :target_name, :access_level,
             :result, :reason, CAST(:ip AS inet), :user_agent, CAST(:detail AS jsonb), :summary)
        SQL;

    /** 성공한 행동 (PRD 7.7 표의 "성공 시") */
    public static function allow(string $action, array $ctx = []): void
    {
        self::write(self::RESULT_ALLOW, $action, null, $ctx);
    }

    /** 거부된 행동 — 401/403 과 권한 사유 404 (PRD 7.7 "실패·거부 시") */
    public static function deny(string $action, string $reason, array $ctx = []): void
    {
        self::write(self::RESULT_DENY, $action, $reason, $ctx);
    }

    /** 처리 중 오류 (검증 실패·저장 실패 등) */
    public static function error(string $action, string $reason, array $ctx = []): void
    {
        self::write(self::RESULT_ERROR, $action, $reason, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     *        target_type / target_id / target_name / access_level / detail(배열) 를 받는다.
     *        actor 는 요청에서 읽지 않는다 — 항상 Auth::currentUser() 에서 가져온다(PRD S-01).
     */
    private static function write(string $result, string $action, ?string $reason, array $ctx): void
    {
        $user = self::actor();

        // 호출자가 준 detail 을 먼저 씻고(scrub), 그 위에 자동 키를 덮어쓴다 (ARCH 7.5).
        // 자동 값이 우선인 이유: route·actor_team·login_ref 는 "요청이 무엇이었나·누구였나" 이고
        // 핸들러가 바꿔 적을 수 있으면 기록의 근거가 흔들린다 (PRD S-07 기록 원칙 "누가·어디서").
        $detail = isset($ctx['detail']) && is_array($ctx['detail'])
            ? self::scrub($ctx['detail'])
            : [];
        $detail = array_merge($detail, self::autoDetail($user));
        $detail = $detail === [] ? null : $detail;

        // JSON_INVALID_UTF8_SUBSTITUTE — scrub()/cut() 이 이미 씻지만, 마지막 그물을 한 겹 더 둔다.
        // 이 플래그가 없으면 깨진 바이트 하나에 json_encode 가 false 를 돌려주고 기록이 통째로
        // 실패한다(= 기록 없는 거부, SECURITY R-47). 남는 값은 U+FFFD 로 바뀐 문자열이다.
        $detailJson = $detail === null || $detail === []
            ? null
            : json_encode(
                $detail,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
        if ($detailJson === false) {
            throw new RuntimeException('감사 로그 detail 을 직렬화할 수 없습니다');
        }

        $row = [
            'happened_at'  => date('c'),
            'request_id'   => self::requestId(),
            'actor_id'     => $user === null ? null : (int) $user['id'],
            'actor_name'   => $user === null ? null : (string) $user['username'],
            /*
             * 26단계 (PRD F-39, ARCH 7.5) — 「역할로 보기」 중에도 `actor_role` 은 **실제 역할**이다.
             * 감사의 「누가」 는 사람이고, 관리자가 Employee 로 보는 동안 한 행동을 Employee 가 한
             * 것처럼 남기면 그 순간 로그가 사람을 가린다. 어떤 역할로 **보였는지**는 지우지 않고
             * detail.acting_as 에 남는다 (autoDetail). real_role 은 Auth::currentUser 가 언제나
             * 넣어 주지만, 옛 호출 경로를 위해 없으면 role 로 되돌린다.
             */
            'actor_role'   => $user === null
                ? null
                : (string) ($user['real_role'] ?? $user['role']),
            'action'       => $action,
            'target_type'  => self::str($ctx, 'target_type'),
            'target_id'    => self::int($ctx, 'target_id'),
            'target_name'  => self::str($ctx, 'target_name'),
            'access_level' => self::str($ctx, 'access_level'),
            'result'       => $result,
            'reason'       => $reason,
            'ip'           => Http::clientIp(),
            // User-Agent 는 공격자가 마음대로 정하는 값이다. Http::userAgent() 는 바이트로 자르므로
            // 깨진 UTF-8 이 그대로 올 수 있고, 그 값이 DB(UTF8 인코딩)에 닿으면 INSERT 가 거부되어
            // 기록이 통째로 사라진다 — 여기서 한 번 더 씻는다 (SECURITY R-47 과 같은 이유).
            'user_agent'   => self::cutNullable(Http::userAgent(), self::VALUE_MAX_CHARS),
        ];

        // 요약 문장을 두 저장소보다 **먼저** 만든다 (ARCH 7.4).
        // 문장 틀이 없으면 여기서 예외가 나므로 DB·파일 어느 쪽에도 반쪽 기록이 남지 않는다.
        $row['summary'] = self::summary($action, $result, $reason, $row);

        self::toDatabase($row, $detailJson);
        self::toFile($row, $detail);
    }

    /**
     * 모든 행에 자동으로 들어가는 detail 키 세 개 (ARCH 7.5 등록부, PRD S-07 육하원칙).
     *
     *   route      — 어디서·어떻게. **라우트 패턴**(`DELETE /api/files/{id}`)이며 실제 UUID 가 아니다.
     *                로그가 파일 ID 목록이 되지 않게 하려는 것이다. CLI 배치는 `cli:스크립트명`.
     *   actor_team — 누가. 행동 **당시**의 팀 id (나중에 팀을 옮겨도 판정 근거가 남는다).
     *   login_ref  — 누가. 로그인 1회당 고정되는 12자. 세션 id(이미 토큰의 해시)에서 한 번 더
     *                단방향 파생하므로 이 값으로 쿠키도 세션 행도 찾을 수 없다.
     *                **세션 토큰 원문·그 해시 자체는 어디에도 넣지 않는다** (PRD 7.7 민감정보 금지).
     *
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    private static function autoDetail(?array $user): array
    {
        $out = [];

        $route = null;
        if (defined('TBOX_ROUTE')) {
            $route = (string) constant('TBOX_ROUTE');
        } elseif (PHP_SAPI === 'cli') {
            $route = 'cli:' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        }
        if ($route !== null && $route !== '') {
            $out['route'] = $route;
        }

        /*
         * F-49 (PRD F-49 ⑥, ARCH 4.2 「감사의 구분」) — 이 요청이 세션이 아니라 **프로그램용
         * API 토큰**으로 들어왔나. 라우터 [2] 의 토큰 가지가 `TBOX_AUTH_METHOD` 를 세우고,
         * 여기서 모든 행에 붙는다. 자리를 여기로 잡은 이유는 route·actor_team 과 같다:
         * 핸들러가 적어 넣는 값이면 언젠가 한 곳이 빠지고, 빠진 그 행이 곧 "누가 올렸는지
         * 모르는 업로드" 가 된다. 값은 코드에 적힌 고정 분류어뿐이고 요청이 정하지 않는다.
         */
        if (defined('TBOX_AUTH_METHOD')) {
            $out['auth'] = (string) constant('TBOX_AUTH_METHOD');
        }

        /*
         * F-59 (PRD F-59 ④, ARCH 4.6 「감사 — 누가·어느 토큰으로」) — 토큰 경로의 **종류**와
         * **어느 토큰**인가. `Auth::requireApiToken` 이 판정한 뒤 세우는 상수를 그대로 읽는다.
         * 여기 두는 이유는 `auth` 와 같다: 핸들러가 적어 넣는 값이면 언젠가 한 곳이 빠지고,
         * 빠진 그 행이 곧 「누가 지웠는지 모르는 삭제」가 된다. 값은 코드가 정한 분류어와
         * 행 번호뿐이고 **토큰 원문·hint 는 어느 쪽에도 들어가지 않는다**.
         * 공용 토큰에는 TBOX_API_TOKEN_ID 가 없다 → `token_id` 키 자체가 생기지 않는다.
         */
        if (defined('TBOX_API_TOKEN_KIND')) {
            $out['token_kind'] = (string) constant('TBOX_API_TOKEN_KIND');
        }
        if (defined('TBOX_API_TOKEN_ID')) {
            $out['token_id'] = (int) constant('TBOX_API_TOKEN_ID');
        }
        // F-66 (ARCH 4.7 「감사」) — 범위도 같은 자리에서. 공용 토큰은 `read+upload` 다.
        if (defined('TBOX_API_TOKEN_SCOPE')) {
            $out['token_scope'] = (string) constant('TBOX_API_TOKEN_SCOPE');
        }

        // 미로그인 행(auth.login·auth.required)에는 누가에 해당하는 값이 없다 → 키를 넣지 않는다.
        if ($user !== null) {
            $out['actor_team'] = $user['team_id'] === null ? null : (int) $user['team_id'];
            $sessionId = isset($user['session_id']) ? (string) $user['session_id'] : '';
            if ($sessionId !== '') {
                $out['login_ref'] = substr(hash('sha256', 'audit-login-ref:' . $sessionId), 0, 12);
            }

            /*
             * 26단계 「역할로 보기」 (PRD F-39, ARCH 5.20 · 7.5 등록부).
             *
             * 보는 중의 행동에는 **두 가지**를 함께 남긴다:
             *   actor_role (열)   실제 역할 — 「누가」. 위 write() 가 real_role 로 적는다.
             *   acting_as (detail) 그때 무슨 역할로 보였나 — 「어떤 권한으로 판정됐나」.
             *
             * 핸들러가 이것을 적어 넣지 않는다. 자동 키(route·actor_team·login_ref)와 같은 자리에서
             * 붙이므로 **모든 행**에 빠짐없이 들어가고, 호출자가 덮어쓸 수도 없다(자동 값이 뒤에 온다).
             * 보는 중이 아니면 키 자체가 없다 — 평소 행이 커지지 않는다.
             */
            if (($user['acting'] ?? false) === true) {
                $out['acting_as'] = [
                    'role'          => isset($user['role']) ? (string) $user['role'] : null,
                    'team_id'       => $user['team_id'] === null ? null : (int) $user['team_id'],
                ];
                $out['real_role'] = (string) ($user['real_role'] ?? '');
            }
        }

        return $out;
    }

    /**
     * 요약 문장 한 줄을 만든다 — 문장은 SUMMARY_TEMPLATES(= ARCH 7.4 표) 에서만 나온다.
     *
     * 표에 없는 조합이면 예외를 던진다. 기본 문장으로 조용히 넘어가지 않는다
     * (PRD 7.7 "요약 문장 필수": 조합에 문장 틀이 없으면 기록 실패 = 요청 실패).
     *
     * @param array<string,mixed> $row write() 가 만든 행. actor_name / target_name / access_level 만 읽는다.
     */
    private static function summary(string $action, string $result, ?string $reason, array $row): string
    {
        $key = $reason === null
            ? $action . '|' . $result
            : $action . '|' . $result . '|' . $reason;

        if (!isset(self::SUMMARY_TEMPLATES[$key])) {
            throw new RuntimeException('감사 로그 요약 문장 틀이 없습니다: ' . $key);
        }

        // 치환하는 값은 이 셋뿐이다. detail·user_agent·ip·요청 파라미터는 문장에 넣지 않는다 (ARCH 7.4).
        //
        // ★ strtr 배열 방식으로 **한 번에** 치환한다 (ARCH 7.4 규칙). str_replace 배열은 순차 치환이라
        //   앞 단계에서 넣은 값 안의 `{level}` 같은 문자열을 뒤 단계가 다시 치환해 기록이 왜곡된다
        //   (파일명 `{level}{target}{actor}.txt` 가 `internal{target}{actor}.txt` 로 남은 실측 사례).
        //   strtr 은 원문을 한 번만 훑고 치환된 값은 다시 검사하지 않는다 —
        //   값 안의 문자는 어떤 경우에도 자리표시자로 해석되지 않는다.
        $text = strtr(self::SUMMARY_TEMPLATES[$key], [
            '{actor}'  => $row['actor_name']   === null ? self::ACTOR_ANONYMOUS : (string) $row['actor_name'],
            '{target}' => $row['target_name']  === null ? self::VALUE_UNKNOWN   : (string) $row['target_name'],
            '{level}'  => $row['access_level'] === null ? self::VALUE_UNKNOWN   : (string) $row['access_level'],
        ]);

        // 문장 길이는 1,000자에서 자른다 (ARCH 7.4). 자르기는 글자 단위이며, 값이 어떤 바이트열이든
        // 여기서 예외가 나지 않는다 — 문장을 만들다 실패하면 기록 자체가 사라지기 때문이다 (R-47).
        return self::cut($text, self::SUMMARY_MAX_CHARS);
    }

    /** @param array<string,mixed> $row */
    private static function toDatabase(array $row, ?string $detailJson): void
    {
        Db::instance()->run(self::SQL_INSERT, [
            ':request_id'   => $row['request_id'],
            ':actor_id'     => $row['actor_id'],
            ':actor_name'   => $row['actor_name'],
            ':actor_role'   => $row['actor_role'],
            ':action'       => $row['action'],
            ':target_type'  => $row['target_type'],
            ':target_id'    => $row['target_id'],
            ':target_name'  => $row['target_name'],
            ':access_level' => $row['access_level'],
            ':result'       => $row['result'],
            ':reason'       => $row['reason'],
            ':ip'           => $row['ip'],
            ':user_agent'   => $row['user_agent'],
            ':detail'       => $detailJson,
            ':summary'      => $row['summary'],
        ]);
    }

    /**
     * 파일 사본 — 한 줄 JSON append. 경로는 .env 의 고정 값이며 사용자 입력이 섞이지 않는다.
     *
     * summary 는 한 줄의 **마지막 키**가 되게 넣는다 — 사람이 `tail` 로 볼 때 끝만 읽으면 되도록
     * (ARCH 7.3 예시, PRD 7.7 "사람이 tail 로 파일만 봐도 무슨 일이 있었는지 알 수 있게 한다").
     *
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $detail
     */
    private static function toFile(array $row, ?array $detail): void
    {
        $path = Env::get('AUDIT_LOG_FILE');
        $summary = $row['summary'];
        unset($row['summary']);
        if ($detail !== null && $detail !== []) {
            $row['detail'] = $detail;
        }
        $row['summary'] = $summary;
        // 파일 사본도 같은 이유로 JSON_INVALID_UTF8_SUBSTITUTE 를 쓴다 (SECURITY R-47).
        $line = json_encode(
            $row,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($line === false) {
            throw new RuntimeException('감사 로그를 직렬화할 수 없습니다');
        }
        $written = file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('감사 로그 파일에 기록할 수 없습니다');
        }
    }

    /**
     * 행동 당시의 사용자. 요청 파라미터의 role 값은 읽지 않는다 (PRD S-01).
     *
     * @return array<string,mixed>|null
     */
    private static function actor(): ?array
    {
        return Auth::currentUser();
    }

    private static function requestId(): string
    {
        if (defined('TBOX_REQUEST_ID')) {
            return (string) constant('TBOX_REQUEST_ID');
        }
        throw new RuntimeException('request_id 가 준비되지 않았습니다');
    }

    /**
     * 비밀 값이 로그에 남지 않게 한다. 중첩 배열까지 훑는다.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private static function scrub(array $in, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['_' => '[깊이 초과]'];
        }
        $out = [];
        foreach ($in as $k => $v) {
            $key = is_string($k) ? $k : (string) $k;
            if (self::isForbiddenKey($key)) {
                $out[$key] = '[제거됨]';
                continue;
            }
            if (is_array($v)) {
                $out[$key] = self::scrub($v, $depth + 1);
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$key] = is_string($v) ? self::cut($v, self::VALUE_MAX_CHARS) : $v;
                continue;
            }
            $out[$key] = '[기록 불가]';
        }
        return $out;
    }

    private static function isForbiddenKey(string $key): bool
    {
        $lower = strtolower($key);
        if (in_array($lower, self::ALLOWED_DETAIL_KEYS, true)) {
            return false;   // 정확히 일치하는 예외만 통과 (위 상수 주석 참고)
        }
        foreach (self::FORBIDDEN_DETAIL_KEYS as $bad) {
            if (strpos($lower, $bad) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function str(array $ctx, string $key): ?string
    {
        $v = $ctx[$key] ?? null;
        if ($v === null) {
            return null;
        }
        return self::cut((string) $v, self::VALUE_MAX_CHARS);
    }

    /**
     * 문자열 값을 **글자 수**로 자른다 (13단계, SECURITY R-47, PRD T-28).
     *
     * 이 함수는 **어떤 입력에도 예외를 던지지 않는다**. 감사 기록이 실패하면 그 요청도 실패하고
     * (ARCH 4.0 [6] "기록 실패 → 처리도 롤백"), 그 순간 "기록 없는 거부" 가 만들어진다 —
     * 파일명·사유는 사용자가 정하는 자유 텍스트이므로 여기서 막지 못하면 감사 로그 자체를
     * 지울 수 있는 통로가 된다 (PRD S-07 "모든 거부를 기록한다").
     *
     * 순서
     *   ① 올바른 UTF-8 이 아니면 잘못된 바이트만 U+FFFD 로 바꾼다(내용은 최대한 남긴다).
     *   ② 그래도 올바르지 않으면 값을 통째로 표식 문자열로 바꾼다 (기록은 남는다).
     *   ③ 글자 수가 넘치면 UTF-8 경계에서 자른다 — 글자 중간을 자르지 않는다.
     */
    private static function cut(string $v, int $maxChars): string
    {
        if (preg_match('//u', $v) !== 1) {
            $v = (string) preg_replace(self::UTF8_VALID_RE, self::UTF8_REPLACEMENT, $v);
            if (preg_match('//u', $v) !== 1) {
                $v = '[기록 불가: 문자 인코딩 오류]';
            }
        }
        if (iconv_strlen($v, 'UTF-8') > $maxChars) {
            $v = (string) iconv_substr($v, 0, $maxChars, 'UTF-8');
        }
        return $v;
    }

    /** cut() 의 null 허용판 — user_agent 처럼 없을 수 있는 값에 쓴다 */
    private static function cutNullable(?string $v, int $maxChars): ?string
    {
        return $v === null ? null : self::cut($v, $maxChars);
    }

    /** @param array<string,mixed> $ctx */
    private static function int(array $ctx, string $key): ?int
    {
        $v = $ctx[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }
}
