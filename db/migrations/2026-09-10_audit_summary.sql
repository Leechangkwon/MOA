-- ─────────────────────────────────────────────────────────────
-- 2026-09-10_audit_summary.sql
--
-- 목적: audit_logs 에 `summary` 컬럼(초보자용 한국어 요약 한 문장)을 추가한다.
--       PRD 7.7 (S-07) "요약 문장 필수", ARCHITECTURE 7.2 (컬럼) · 7.4 (문장 표).
--
--       새로 설치하는 DB 는 db/schema.sql 에 이미 컬럼이 있으므로 이 파일이 필요 없다.
--       deploy/install.sh 는 표가 이미 있으면 schema.sql 을 건너뛰기 때문에,
--       **이미 돌고 있는 DB** 에만 이 마이그레이션을 한 번 적용한다 (ARCH 7.4 "기존 DB 반영").
--
-- 실행: sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-10_audit_summary.sql
--       (표 소유자 tbox_owner 권한이 필요하다. 앱 계정 tbox_app 으로는 실행되지 않는다.)
--
-- 재실행 안전성: ADD COLUMN IF NOT EXISTS + WHERE summary IS NULL + SET NOT NULL 은 모두 멱등이다.
--       이미 적용된 DB 에 다시 돌려도 아무 행도 바뀌지 않고 성공으로 끝난다.
--
-- 권한: 이 마이그레이션은 tbox_app 의 권한을 바꾸지 않는다 (GRANT/REVOKE 없음).
--       컬럼 추가는 기존 표 권한(SELECT, INSERT)에 자동으로 포함되므로 Append-only 성질이 그대로다.
-- ─────────────────────────────────────────────────────────────

\set ON_ERROR_STOP on

BEGIN;

-- [1] 컬럼 추가 — 기존 행이 있으므로 아직 NULL 을 허용한다.
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS summary TEXT;

-- [2] 기존 행 채우기 — 문장은 ARCHITECTURE 7.4 표와 **같은 문장**이다.
--     자리표시자 대응: {actor} → coalesce(actor_name,'(로그인 전)')
--                      {target} → coalesce(target_name,'?')
--                      {level}  → coalesce(access_level,'?')
--
--     ★ ELSE 절을 두지 않는다 — 표에 없는 조합이 DB 에 있으면 summary 가 NULL 로 남고
--       아래 [3] SET NOT NULL 에서 실패해 드러나야 한다. 조용한 기본 문장으로 덮지 않는다
--       (PRD 7.7 "문장 틀이 없으면 기록 실패 = 요청 실패" 와 같은 원칙).
UPDATE audit_logs SET summary = CASE

    -- ── 인증 ──
    WHEN action = 'auth.login' AND result = 'allow'
        THEN coalesce(target_name,'?') || ' 이(가) 로그인했습니다'
    WHEN action = 'auth.login' AND result = 'deny' AND reason = 'bad_credentials'
        THEN coalesce(target_name,'?') || ' 계정으로 로그인을 시도했지만 아이디 또는 비밀번호가 틀렸습니다'
    WHEN action = 'auth.login' AND result = 'deny' AND reason = 'locked'
        THEN coalesce(target_name,'?') || ' 계정으로 로그인을 시도했지만 실패 5회로 10분 잠긴 계정입니다'
    WHEN action = 'auth.logout' AND result = 'allow'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 로그아웃했습니다'
    WHEN action = 'auth.required' AND result = 'deny' AND reason = 'not_authenticated'
        THEN '로그인하지 않은 채 ' || coalesce(target_name,'?') || ' 에 접근하려 해서 막았습니다'

    -- ── 공통 관문 ──
    WHEN action = 'csrf' AND result = 'deny' AND reason = 'token_mismatch'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) ' || coalesce(target_name,'?')
             || ' 요청을 보냈지만 CSRF 토큰이 없거나 달라서 막았습니다 (다른 사이트에서 몰래 보낸 요청일 수 있음)'

    -- ── 업로드 ──
    WHEN action = 'file.upload' AND result = 'allow'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 파일 「' || coalesce(target_name,'?')
             || '」 을(를) ' || coalesce(access_level,'?') || ' 등급으로 올렸습니다'
    WHEN action = 'file.upload' AND result = 'deny' AND reason = 'no_file'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 파일 없이 업로드를 요청해서 거부했습니다'
    WHEN action = 'file.upload' AND result = 'deny' AND reason = 'empty_file'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 0바이트 파일 「' || coalesce(target_name,'?')
             || '」 을(를) 올리려 해서 거부했습니다'
    WHEN action = 'file.upload' AND result = 'deny' AND reason = 'bad_access_level'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 파일 「' || coalesce(target_name,'?')
             || '」 을(를) 존재하지 않는 접근 등급으로 올리려 해서 거부했습니다'
    WHEN action = 'file.upload' AND result = 'deny' AND reason = 'bad_team'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 파일 「' || coalesce(target_name,'?')
             || '」 을(를) 없는 팀 또는 팀 지정 없이 team 등급으로 올리려 해서 거부했습니다'
    WHEN action = 'file.upload' AND result = 'deny' AND reason = 'description_too_long'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 올린 파일 「' || coalesce(target_name,'?')
             || '」 의 설명이 500자를 넘어 거부했습니다'
    WHEN action = 'file.upload' AND result = 'deny' AND reason = 'upload_error'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 보낸 업로드 요청의 형식이 잘못되어(파일 여러 개 등) 거부했습니다'
    WHEN action = 'file.upload' AND result = 'error' AND reason = 'upload_failed'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 파일 「' || coalesce(target_name,'?')
             || '」 을(를) 올리는 중 서버 오류가 나서 되돌렸습니다'

    -- ── 다운로드 ──
    WHEN action = 'file.download' AND result = 'allow'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) ' || coalesce(access_level,'?')
             || ' 등급 파일 「' || coalesce(target_name,'?') || '」 을(를) 내려받았습니다'
    WHEN action = 'file.download' AND result = 'error' AND reason = 'file_missing'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) 파일 「' || coalesce(target_name,'?')
             || '」 을(를) 내려받으려 했지만 저장소에 실제 파일이 없습니다 (점검 필요)'

    -- ── 삭제 ──
    WHEN action = 'file.delete' AND result = 'allow'
        THEN coalesce(actor_name,'(로그인 전)') || ' 이(가) ' || coalesce(access_level,'?')
             || ' 등급 파일 「' || coalesce(target_name,'?') || '」 을(를) 삭제했습니다 (30일 휴지통)'

    -- ELSE 없음 (의도) — 모르는 조합은 NULL 로 남아 [3] 에서 실패한다.
END
WHERE summary IS NULL;

-- [3] NOT NULL 확정 — 위 CASE 가 놓친 조합이 하나라도 있으면 여기서 실패하고 BEGIN 이 통째로 롤백된다.
--     실패하면 그 조합을 ARCH 7.4 표와 이 파일에 추가한 뒤 다시 실행한다.
ALTER TABLE audit_logs ALTER COLUMN summary SET NOT NULL;

COMMIT;
