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
         * 19단계 구매·재고 모듈 (PRD F-28 · F-29, ARCH 5.18 · 7.5 등록부).
         *   kind      — 기초정보 종류 또는 가져오기 종류 (`locations`·`items` …)
         *   target_id — 만든·고친 행의 id (target_id 칼럼과 같은 값을 detail 에도 남긴다)
         *   file_id   — 가져오기 원본 `files.id`
         *   sha256    — 그 파일의 해시. 이름에 `hash` 가 들어가지 않아 부분 일치에 걸리지 않지만,
         *               등록부에 있는 키임을 코드에서도 드러내려고 적는다(chain_hash 와 같은 성격)
         *   rows · inserted · updated — 확정 1건의 요약 **수치**. 행 내용은 남기지 않는다
         *   branch_id — 어느 지점의 기초정보인가 (지점 격리를 되짚는 값)
         *   mapping · branches — 엑셀 열 이름 목록 / 품목에 이어 붙인 지점 id 목록.
         *               둘 다 **이름·번호 목록**이고 파일 내용이 아니다.
         * `before`·`after` 는 이미 등록부에 있고 금지 목록에 걸리지 않아 따로 적지 않는다.
         */
        'kind', 'target_id', 'file_id', 'sha256', 'rows', 'inserted', 'updated',
        'branch_id', 'mapping', 'branches',
        /*
         * 19단계 보정 (2026-09-13).
         *   header_row — 어느 줄을 머리행으로 보고 판정했나 (같은 파일을 다시 볼 때의 근거)
         *   why        — 문장 표에 아직 없는 세부 사유를 남기는 자리 (보정 1)
         *   constraint — 확정이 DB 제약에 부딪혔을 때 **그 제약 이름**. 값이 아니라 규칙 이름이라
         *                비밀이 없고, "어느 규칙이 막았나" 를 로그만으로 알 수 있게 한다 (보정 2)
         *   derive     — 창고명에서 지점을 뽑아 등록했나 (`location_name`)
         */
        'header_row', 'why', 'constraint', 'derive',
        /*
         * 19단계 5차 보정 (2026-09-13, 사용자 결정 「여러 지점이 한 번에 올라간다」).
         *   brands_created · branches_created — 창고 가져오기 한 번이 **덤으로 만든** 기초정보 수.
         *     브랜드·지점은 손으로 만들 때 건건이 감사에 남는 값이라, 가져오기로 한꺼번에
         *     생길 때도 "언제 몇 개가 늘었나" 가 남아야 한다.
         *   locations_inserted — 그 확정으로 들어간 창고 수(`inserted` 와 같은 값이지만,
         *     종류가 섞여도 로그만 보고 갈라 읽을 수 있게 이름을 붙여 남긴다).
         * 셋 다 **수치**이고 이름·내용이 아니다.
         */
        'brands_created', 'branches_created', 'locations_inserted',
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
        'file.fetch|deny|rate_limited'            => '{actor} 이(가) 한 시간에 10회를 넘게 파일 가져오기를 요청해서 막았습니다',
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

        // ── 미리보기 (files_preview · files_preview_raw — 12-2, ARCH 7.4 file.preview 7행) ──
        'file.preview|allow'                      => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 미리보기로 열었습니다',
        'file.preview|deny|role_not_permitted'    => '{actor} 이(가) {level} 등급 파일 「{target}」 을(를) 미리보기로 열려 했지만 권한이 없어 막았습니다',
        'file.preview|deny|team_mismatch'         => '{actor} 이(가) 다른 팀의 team 등급 파일 「{target}」 을(를) 미리보기로 열려 해서 막았습니다',
        'file.preview|deny|preview_not_available' => '{actor} 이(가) 미리보기를 지원하지 않는 형식의 파일 「{target}」 을(를) 원본 표시로 열려 해서 거부했습니다',
        'file.preview|error|file_missing'         => '{actor} 이(가) 파일 「{target}」 을(를) 미리보기로 열려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)',
        'file.preview|error|hash_mismatch'        => '{actor} 이(가) 파일 「{target}」 을(를) 미리보기로 열려 했지만 저장된 파일의 내용이 기록과 달라 표시를 막았습니다 (변조 의심, 점검 필요)',
        'file.preview|error|parse_failed'         => '{actor} 이(가) 파일 「{target}」 의 미리보기를 요청했지만 내용을 읽어 내지 못했습니다 (손상 또는 지원 범위 밖)',

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
        'folder.delete|deny|folder_not_empty'           => '{actor} 이(가) 파일 또는 하위 폴더가 남아 있는 폴더 「{target}」 을(를) 지우려 해서 거부했습니다 (휴지통의 파일도 포함)',
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

        // ── 19단계 구매·재고 모듈 (PRD F-28 · F-29, ARCH 5.18 「감사·경보」, SECURITY R-65 · R-67) ──
        //
        // {target} 에는 `종류:이름`(예: `locations:B1F 의약품창고`) 또는 가져오기 파일 이름이 들어간다.
        // 품목 찾기(`/api/purchase/items`)는 **조회라서 기록하지 않는다** — 1.35만 건을 훑는 검색을
        // 행마다 남기면 감사 로그가 검색 기록이 되고, 정작 중요한 변경이 그 속에 묻힌다
        // (파일 목록·폴더 트리를 기록하지 않는 것과 같은 판단, ARCH 5.1 마지막 줄).
        // 가져오기 확정도 **한 줄**이다: 1.3만 행을 행마다 남기면 그 자체가 로그를 밀어내는 공격이 된다.
        'purchase.master.create|allow'                  => '{actor} 이(가) 구매 기초정보 「{target}」 을(를) 새로 만들었습니다',
        'purchase.master.create|deny|bad_request'       => '{actor} 이(가) 구매 기초정보를 만들려 했지만 보낸 값의 형식이 잘못되어 거부했습니다',
        'purchase.master.create|deny|bad_reference'     => '{actor} 이(가) 없는(또는 중단된) 브랜드·지점·장소·품목을 가리키는 구매 기초정보를 만들려 해서 거부했습니다',
        'purchase.master.create|deny|conflict'          => '{actor} 이(가) 이미 있는 이름·코드로 구매 기초정보를 만들려 해서 거부했습니다',
        'purchase.master.update|allow'                  => '{actor} 이(가) 구매 기초정보 「{target}」 을(를) 고쳤습니다 (바뀐 값은 상세 기록의 before/after)',
        'purchase.master.update|deny|bad_request'       => '{actor} 이(가) 구매 기초정보 「{target}」 을(를) 고치려 했지만 보낸 값의 형식이 잘못되어 거부했습니다',
        'purchase.master.update|deny|bad_reference'     => '{actor} 이(가) 구매 기초정보 「{target}」 을(를) 없는(또는 중단된) 값에 이어 붙이려 해서 거부했습니다',
        'purchase.master.update|deny|conflict'          => '{actor} 이(가) 구매 기초정보 「{target}」 을(를) 이미 있는 이름·코드로 바꾸려 해서 거부했습니다',
        'purchase.master.update|deny|no_change'         => '{actor} 이(가) 구매 기초정보 「{target}」 에 바뀌는 값 없이 수정을 요청해서 거부했습니다',
        'purchase.master.update|deny|bad_kind'          => '{actor} 이(가) 알 수 없는 종류의 구매 기초정보를 고치려 해서 거부했습니다',
        'purchase.master.update|deny|bad_mapping'       => '{actor} 이(가) 형식에 맞지 않는 엑셀 열 매핑을 저장하려 해서 거부했습니다',
        'purchase.master.deactivate|allow'              => '{actor} 이(가) 구매 기초정보 「{target}」 을(를) 중단했습니다 (삭제가 아니라 중단이며 지난 전표에는 그대로 남습니다)',
        'purchase.master.deactivate|deny|bad_request'   => '{actor} 이(가) 구매 기초정보 「{target}」 을(를) 중단하려 했지만 보낸 값의 형식이 잘못되어 거부했습니다',

        'purchase.import.upload|allow'                  => '{actor} 이(가) 구매 기초정보 가져오기 파일 「{target}」 을(를) 올렸습니다 (관리자 전용 폴더 「구매/가져오기」)',
        'purchase.import.upload|deny|no_file'           => '{actor} 이(가) 파일 없이 구매 가져오기를 요청해서 거부했습니다',
        'purchase.import.upload|deny|empty_file'        => '{actor} 이(가) 0바이트 파일 「{target}」 로 구매 가져오기를 요청해서 거부했습니다',
        'purchase.import.upload|deny|too_large'         => '{actor} 이(가) 100MB 를 넘는 파일 「{target}」 로 구매 가져오기를 요청해서 거부했습니다',
        'purchase.import.upload|deny|upload_error'      => '{actor} 이(가) 보낸 구매 가져오기 업로드의 형식이 잘못되어 거부했습니다',
        'purchase.import.upload|deny|not_uploaded_file' => '{actor} 이(가) 보낸 구매 가져오기 업로드가 정상적인 HTTP 업로드가 아니어서 거부했습니다',
        'purchase.import.upload|deny|size_unreadable'   => '{actor} 이(가) 올린 파일 「{target}」 의 크기를 읽을 수 없어 구매 가져오기를 거부했습니다',
        'purchase.import.upload|deny|hash_failed'       => '{actor} 이(가) 올린 파일 「{target}」 의 무결성 값을 계산할 수 없어 구매 가져오기를 거부했습니다',
        'purchase.import.upload|deny|ext_not_allowed'   => '{actor} 이(가) 가져오기가 읽을 수 없는 종류의 파일 「{target}」 을(를) 올려 거부했습니다 (xlsx·csv 만 — xlsm·xls·매크로 문서는 받지 않습니다)',
        'purchase.import.upload|deny|mime_mismatch'     => '{actor} 이(가) 올린 파일 「{target}」 의 실제 내용이 확장자와 달라(위장 파일 의심) 구매 가져오기를 거부했습니다',
        'purchase.import.upload|deny|double_extension'  => '{actor} 이(가) 이중 확장자 파일 「{target}」 로 구매 가져오기를 요청해서 거부했습니다 (실행 파일 위장 의심)',
        'purchase.import.upload|deny|bad_encoding'      => '{actor} 이(가) 올린 구매 가져오기 파일의 이름이 올바른 문자(UTF-8)가 아니어서 거부했습니다',
        'purchase.import.upload|deny|quota_exceeded'    => '{actor} 이(가) 구매 가져오기 파일을 올리려 했지만 하루 업로드 한도(2GB)를 넘어 거부했습니다',
        'purchase.import.upload|error|parse_failed'     => '{actor} 이(가) 올린 구매 가져오기 파일 「{target}」 에서 표를 읽어 내지 못했습니다 (손상 또는 지원 범위 밖)',

        'purchase.import.preview|deny|bad_request'      => '{actor} 이(가) 구매 가져오기 미리보기를 잘못된 형식으로 요청해서 거부했습니다',
        'purchase.import.preview|deny|bad_kind'         => '{actor} 이(가) 알 수 없는 종류로 구매 가져오기 미리보기를 요청해서 거부했습니다',
        'purchase.import.preview|deny|bad_mapping'      => '{actor} 이(가) 파일의 열과 맞지 않는 매핑으로 구매 가져오기 미리보기를 요청해서 거부했습니다',
        'purchase.import.preview|deny|mapping_incomplete' => '{actor} 이(가) 필수 칸이 빠진 매핑으로 구매 가져오기 미리보기를 요청해서 거부했습니다',
        'purchase.import.preview|deny|too_many_rows'    => '{actor} 이(가) 상한을 넘는 행 수의 파일로 구매 가져오기 미리보기를 요청해서 거부했습니다',
        'purchase.import.preview|deny|parse_failed'     => '{actor} 이(가) 읽을 수 없는 파일로 구매 가져오기 미리보기를 요청해서 거부했습니다',
        'purchase.import.preview|error|truncated'       => '{actor} 이(가) 미리보기한 구매 가져오기 파일 「{target}」 이(가) 상한에서 끊겼습니다 (확정은 막힙니다)',

        'purchase.import.commit|allow'                  => '{actor} 이(가) 구매 기초정보 가져오기를 확정했습니다 (파일 「{target}」 · 행 수·등록·갱신 건수는 상세 기록)',
        'purchase.import.commit|deny|bad_request'       => '{actor} 이(가) 구매 가져오기 확정을 잘못된 형식으로 요청해서 거부했습니다',
        'purchase.import.commit|deny|bad_kind'          => '{actor} 이(가) 알 수 없는 종류로 구매 가져오기 확정을 요청해서 거부했습니다',
        'purchase.import.commit|deny|bad_mapping'       => '{actor} 이(가) 파일의 열과 맞지 않는 매핑으로 구매 가져오기 확정을 요청해서 거부했습니다',
        'purchase.import.commit|deny|mapping_incomplete' => '{actor} 이(가) 필수 칸이 빠진 매핑으로 구매 가져오기 확정을 요청해서 거부했습니다',
        'purchase.import.commit|deny|too_many_rows'     => '{actor} 이(가) 상한을 넘는 행 수의 파일로 구매 가져오기 확정을 요청해서 거부했습니다',
        'purchase.import.commit|deny|parse_failed'      => '{actor} 이(가) 읽을 수 없는 파일로 구매 가져오기 확정을 요청해서 거부했습니다',
        'purchase.import.commit|deny|truncated'         => '{actor} 이(가) 상한에서 끊긴 파일 「{target}」 로 구매 가져오기 확정을 요청해서 거부했습니다 (반쯤 들어간 기초정보를 만들지 않습니다)',
        'purchase.import.commit|deny|conflict'          => '{actor} 이(가) 확정한 구매 가져오기에 이미 있는 거래처·코드가 있어 **전부 되돌렸습니다**',
        'purchase.import.commit|deny|bad_reference'     => '{actor} 이(가) 확정한 구매 가져오기가 없는 기초정보를 가리켜 **전부 되돌렸습니다**',

        // 19단계 보정(2026-09-13) — 실제 이카운트 파일에서 드러난 세 가지 거부 사유.
        //   bad_header_row          머리행 번호가 0~19 밖이다 (보정 1)
        //   default_branch_required 창고 파일에 지점 열이 없는데 「기본 지점」도 고르지 않았다 (보정 2)
        //   default_brand_required  지점 파일에 브랜드 열이 없는데 「기본 브랜드」도 고르지 않았다 (보정 2)
        // 행마다 오류를 내지 않고 **한 번에** 말하는 사유들이라, 문장도 "무엇을 고르면 되는지" 를 담는다.
        'purchase.import.preview|deny|bad_header_row'          => '{actor} 이(가) 있을 수 없는 머리행 번호로 구매 가져오기 미리보기를 요청해서 거부했습니다 (앞 20줄 안에서만 고를 수 있습니다)',
        'purchase.import.preview|deny|default_branch_required' => '{actor} 이(가) 지점 열도 「기본 지점」도 없이 창고 가져오기 미리보기를 요청해서 거부했습니다 (어느 지점의 창고인지 알 수 없습니다)',
        'purchase.import.preview|deny|default_brand_required'  => '{actor} 이(가) 브랜드 열도 「기본 브랜드」도 없이 지점 가져오기 미리보기를 요청해서 거부했습니다',
        'purchase.import.commit|deny|bad_header_row'           => '{actor} 이(가) 있을 수 없는 머리행 번호로 구매 가져오기 확정을 요청해서 거부했습니다',
        'purchase.import.commit|deny|default_branch_required'  => '{actor} 이(가) 지점 열도 「기본 지점」도 없이 창고 가져오기 확정을 요청해서 거부했습니다',
        'purchase.import.commit|deny|default_brand_required'   => '{actor} 이(가) 브랜드 열도 「기본 브랜드」도 없이 지점 가져오기 확정을 요청해서 거부했습니다',
        'purchase.master.update|deny|bad_header_row'           => '{actor} 이(가) 있을 수 없는 머리행 번호가 든 엑셀 열 매핑을 저장하려 해서 거부했습니다',
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
            'actor_role'   => $user === null ? null : (string) $user['role'],
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

        // 미로그인 행(auth.login·auth.required)에는 누가에 해당하는 값이 없다 → 키를 넣지 않는다.
        if ($user !== null) {
            $out['actor_team'] = $user['team_id'] === null ? null : (int) $user['team_id'];
            $sessionId = isset($user['session_id']) ? (string) $user['session_id'] : '';
            if ($sessionId !== '') {
                $out['login_ref'] = substr(hash('sha256', 'audit-login-ref:' . $sessionId), 0, 12);
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
