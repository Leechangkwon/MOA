# t-box 아키텍처 문서 (초보자용)

> 기준 문서: `PRD.md` v1.0 — 요구사항 ID(S-01 ~ S-09, F-01 ~ F-13)는 PRD의 것을 그대로 쓴다.
> 기술: **순수 PHP 8.4 (프레임워크 없음) + PostgreSQL 16 + nginx · Ubuntu 24.04 LTS**

| 항목 | 내용 |
|---|---|
| 문서 버전 | v1.0 |
| 작성일 | 2026-09-08 |
| 읽는 순서 | 1장(전체 그림) → 3장(공통 관문) → 5장(4가지 흐름) → 나머지는 필요할 때 |

---

## 1. 전체 그림 — 한 장으로 보기

```
 [사용자 브라우저]                          사내망 / VPN 안
      │  HTTPS
      ▼
 ┌──────────────────────────────────────────────────────────────┐
 │  nginx  (문지기)                                              │
 │   · HTTPS 종단, 보안 헤더 부착                                  │
 │   · client_max_body_size 100m  ← 큰 업로드는 여기서 먼저 차단     │
 │   · /public 폴더만 서비스. 그 밖의 폴더는 URL로 절대 못 닿음        │
 └──────────────┬───────────────────────────────────────────────┘
                │ FastCGI (유닉스 소켓)
                ▼
 ┌──────────────────────────────────────────────────────────────┐
 │  PHP-FPM  (일꾼)   public/index.php  ← 모든 요청의 단일 입구     │
 │                                                              │
 │   ① 세션 확인(로그인했나?)  ② 권한 판단(해도 되나?)               │
 │   ③ 실제 처리              ④ 감사 로그 기록                     │
 └──────┬──────────────────────┬──────────────────────┬─────────┘
        │ PDO(값 바인딩)        │ 파일 읽기/쓰기          │ 파일 로그
        ▼                      ▼                      ▼
 ┌──────────────┐   ┌────────────────────────┐   ┌────────────────────┐
 │ PostgreSQL   │   │ /var/lib/tbox/storage/ │   │ /var/log/tbox/     │
 │  users       │   │  (실제 파일, 웹 루트 밖) │   │  audit.log         │
 │  teams       │   │  UUID 이름으로 저장      │   │  (감사 로그 사본)    │
 │  files  ←──── 파일 정보(메타데이터)만        │   │  app.log           │
 │  audit_logs ←─ 감사 로그(원본)              │   └────────────────────┘
 └──────────────┘   └────────────────────────┘
```

**초보자용 요약 세 줄**
1. 브라우저는 **요청만** 보내고, 결정은 **전부 PHP가** 한다. (PRD S-01)
2. 파일의 **정보**는 PostgreSQL에, **실제 파일**은 웹 루트 밖 디스크에 따로 둔다.
3. 무슨 일이 있었는지는 **DB(`audit_logs`) + 파일(`audit.log`)** 두 곳에 남긴다. (PRD S-07)

---

## 2. 구성요소 역할표

| 구성요소 | 비유 | 하는 일 | 하지 않는 일 |
|---|---|---|---|
| 브라우저(HTML/JS) | 창구 안내판 | 화면 그리기, 요청 보내기 | **권한 판단** (버튼 숨김은 편의일 뿐) |
| nginx | 건물 문지기 | HTTPS, 크기 제한, 보안 헤더, `public/`만 노출 | 비즈니스 로직 |
| PHP (index.php + src/) | 창구 직원 | 세션·권한·처리·로그 | SQL 문자열 조립, 사용자 URL 요청(SSRF) |
| PostgreSQL | 장부 | 사용자·팀·파일정보·감사 로그 저장 | 실제 파일 내용 저장 |
| 스토리지 디렉터리 | 금고 | 실제 파일 보관 (UUID 이름) | URL로 직접 노출 |
| 파일 로그 | 장부 사본 | 감사 로그 이중 기록 | — |

---

## 3. 디렉터리 구조 — "무엇이 URL로 보이고, 무엇이 안 보이나"

```
/var/www/tbox/                     ← 애플리케이션 (Git 관리)
├── public/                        ★ nginx root. 여기만 URL로 접근 가능
│   ├── index.php                    모든 요청의 단일 입구 (라우터)
│   └── assets/                      css, js (정적 파일)
├── src/                           ✖ URL로 접근 불가
│   ├── bootstrap.php                설정 로드, DB 연결, 보안 헤더
│   ├── Auth.php                     로그인/세션 (누구인가)
│   ├── Authz.php                    권한 판단 (해도 되는가)  ★ 가장 중요한 파일
│   ├── Audit.php                    감사 로그 기록
│   ├── Db.php                       PDO 래퍼 (prepared statement만 허용)
│   ├── Storage.php                  파일 저장·읽기 (경로 조작 방지)
│   ├── Upload.php                   업로드 검증 (크기·확장자·MIME)
│   ├── Http.php                     JSON 응답, 오류 응답 통일
│   └── handlers/                    엔드포인트별 처리
│       ├── files_list.php
│       ├── files_upload.php
│       ├── files_download.php
│       ├── files_delete.php
│       ├── audit_list.php
│       └── ...
├── views/                         ✖ HTML 템플릿 (출력 시 전부 이스케이프)
├── db/
│   ├── schema.sql                   테이블 정의
│   └── grants.sql                   DB 계정 권한 (최소 권한)
├── scripts/                       운영 스크립트 (백업·정합성 검사·휴지통 비우기)
└── config/
    └── config.php.example           예시만 Git에. 실제 config.php는 아래 위치

/etc/tbox/config.php               ✖ 실제 설정 (DB 비밀번호 등). 권한 0600, Git 제외
/var/lib/tbox/storage/             ✖ 실제 업로드 파일. 권한 0750, PHP 실행 불가
/var/lib/tbox/quarantine/          ✖ 악성코드 검사 대기 파일 (선택)
/var/log/tbox/                     ✖ audit.log, app.log
/var/backups/tbox/                 ✖ 백업 (암호화, 0700)
```

| 왜 이렇게 나누나 | 막히는 공격 |
|---|---|
| `public/` 만 nginx root → `src/`, `config/`가 URL로 안 보임 | 설정 파일·소스 노출 |
| 업로드 파일이 웹 루트 밖 → `/uploads/shell.php` 같은 URL이 존재하지 않음 | 업로드 파일 실행(웹셸) |
| 실제 설정은 `/etc/tbox/` → 저장소에 비밀번호가 없음 | Git 유출 (PRD S-09-6) |

---

## 4. 모든 요청이 거치는 공통 관문

어떤 API를 부르든 **같은 순서**를 거친다. 권한 검사를 "빠뜨릴 수 없게" 구조로 강제하는 것이 목적이다.

```
요청 도착 (public/index.php)
   │
   ├─ [0] bootstrap.php     설정 로드, DB 연결, 보안 헤더(CSP 등) 출력
   │
   ├─ [1] Auth::requireLogin()        ─── 세션에 user_id 없음 → 401, 끝
   │        세션의 user_id로 DB에서 role, team_id를 **다시 읽음**
   │        (요청에 실려온 role 값은 아예 읽지 않음)                     ← PRD S-01
   │
   ├─ [2] Http::requireCsrf()         ─── POST/PATCH/DELETE 인데 토큰 불일치 → 403
   │
   ├─ [3] 라우팅                       ─── 경로+메서드 → handlers/xxx.php
   │
   ├─ [4] Authz::…()  ★ 권한 판단      ─── 실패 → 404(존재 숨김)/403 + Audit::deny()
   │
   ├─ [5] 실제 처리 (DB는 값 바인딩만, 파일은 Storage 통해서만)
   │
   ├─ [6] Audit::allow()  감사 로그    ─── 기록 실패 → 처리도 롤백
   │
   └─ [7] 응답 (JSON 또는 파일 스트림). HTML이면 전부 htmlspecialchars   ← PRD S-03
```

### 4.1 Authz.php — 권한 판단은 이 파일 하나에 모은다

PRD 5장의 권한 매트릭스를 **코드로 그대로 옮긴 곳**이다. 다른 파일에서 `if ($role === 'Admin')` 같은 판단을 직접 쓰는 것을 금지하고, 항상 여기 함수를 부른다.

```php
<?php
// src/Authz.php — PRD 5장 권한 매트릭스의 유일한 구현
final class Authz
{
    /** 5.1 읽기: 목록·검색 SQL에 붙일 WHERE 조건과 바인딩 값 (PRD S-02) */
    public static function visibleFilesWhere(array $u): array
    {
        return match ($u['role']) {
            'Admin'    => ['TRUE', []],
            'Manager', 'Employee' => [
                "(access_level IN ('public','internal')
                  OR (access_level = 'team' AND team_id = :team_id))",
                [':team_id' => $u['team_id']],
            ],
            'Guest'    => ["access_level = 'public'", []],
        };
    }

    /** 5.1 읽기: 파일 1건을 이 사용자가 볼 수 있나 (다운로드·상세) */
    public static function canRead(array $u, array $file): bool
    {
        return match ($file['access_level']) {
            'public'     => true,
            'internal'   => $u['role'] !== 'Guest',
            'team'       => $u['role'] === 'Admin'
                            || ($u['role'] !== 'Guest' && $u['team_id'] === $file['team_id']),
            'admin-only' => $u['role'] === 'Admin',
        };
    }

    /** 5.2 업로드: 이 레벨(+팀)로 올릴 수 있나 */
    public static function canUploadAs(array $u, string $level, ?int $teamId): bool
    {
        if ($u['role'] === 'Guest') return false;
        if ($level === 'admin-only') return $u['role'] === 'Admin';
        if ($level === 'team') {
            if ($teamId === null) return false;
            return $u['role'] === 'Admin' || $u['team_id'] === $teamId;
        }
        return in_array($level, ['public', 'internal'], true);
    }

    /** 5.3 삭제 */
    public static function canDelete(array $u, array $file): bool
    {
        return match ($u['role']) {
            'Admin'    => true,
            'Manager'  => $file['uploaded_by'] === $u['id'] || $file['team_id'] === $u['team_id'],
            'Employee' => $file['uploaded_by'] === $u['id'],
            'Guest'    => false,
        };
    }
}
```

> **초보자 메모**: "권한이 이상하다" 싶으면 볼 파일은 이거 하나다. 매트릭스(PRD 5장)와 이 파일이 1:1로 맞는지 코드 리뷰 때 항상 비교한다.

---

## 5. 네 가지 흐름 — 순서대로, 권한 검사 위치 표시

표기: **[권한 ✔]** = 여기서 권한 검사 · **[DB]** = PostgreSQL · **[FS]** = 스토리지 디스크 · **[LOG]** = 감사 로그

### 5.1 파일 목록 / 검색 — `GET /api/files?q=&sort=&page=`

```
 브라우저                nginx              PHP (index.php → files_list.php)            PostgreSQL
 ───────                ─────              ──────────────────────────────────            ──────────
 GET /api/files?q=회의  ──▶  통과  ──▶  [1] 세션 → user_id → DB에서 role, team_id 조회 ─▶ SELECT users
                                        [권한 ✔] Authz::visibleFilesWhere($u)
                                              → "WHERE ... access_level IN (...)"
                                              ※ 권한 없는 행은 SQL 결과에 아예 안 나옴 (S-02)
                                        [2] 검색어: LIKE 값 바인딩, %·_ 이스케이프 (S-04)
                                        [3] sort: 허용 목록 ['name','created_at','size'] 에
                                              없으면 기본값으로 대체 (S-04)
                                        [4] SELECT ... WHERE deleted_at IS NULL
                                                 AND (권한 조건) AND (검색 조건)
                                                 ORDER BY <허용된 컬럼> LIMIT 20 OFFSET ? ─▶ [DB] files
                                        [5] COUNT(*) 도 같은 WHERE → 전체 건수 노출 안 함
                                        [6] JSON 응답 (Content-Type: application/json)
 ◀── {files:[...20건], total: n, page: 1}
 화면은 받은 것만 그림 (textContent 사용, innerHTML 금지) (S-03)
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [1] | PHP | 요청에 실린 역할값 무시 → 프론트 권한 우회 |
| [권한 ✔] | **SQL WHERE** | 권한 없는 데이터 노출 (응답에 없음) |
| [2][3] | PHP | SQL 인젝션 (값 바인딩 + 허용 목록) |
| 화면 | JS | XSS (textContent) |

> 목록 조회는 양이 많아 감사 로그를 남기지 않는다(PRD S-07 참고). **다운로드는 반드시 남긴다.**

### 5.2 파일 업로드 — `POST /api/files` (multipart)

```
 브라우저              nginx                    PHP (files_upload.php)                    저장소
 ───────              ─────                    ──────────────────────                    ──────
 POST 파일+설명+레벨 ─▶ [크기 ✔] 100MB 초과 → 413 즉시 거부 (앱까지 안 옴)
                       통과 ─▶  [1] 세션 → user 조회
                                [2] CSRF 토큰 확인
                                [권한 ✔ A] Authz::canUploadAs($u, $level, $teamId)
                                      Guest → 403 / Employee가 admin-only → 403
                                      → [LOG] deny 기록 후 끝
                                [3] Upload::validate()  (S-06)
                                      · 크기 ≤ 100MB (PHP도 한 번 더)
                                      · 확장자 화이트리스트 (pdf, docx, ...)
                                      · finfo 로 실제 MIME 판별 → 확장자와 불일치 시 거부
                                      · 이중 확장자·경로문자·제어문자 제거
                                      · 일일 총량 2GB 초과 → 거부
                                [4] stored_name = UUIDv4 . '.' . 안전한확장자
                                    sha256 = hash_file(...)
                                [5] BEGIN
                                     INSERT INTO files (...) VALUES (:바인딩...)     ─▶ [DB] files
                                     Storage::put(tmp, stored_name)                  ─▶ [FS] /var/lib/tbox/storage/ab/cd/UUID.pdf
                                     Audit::allow('file.upload', file)               ─▶ [DB] audit_logs + [LOG] audit.log
                                    COMMIT   (하나라도 실패 → ROLLBACK + 파일 삭제)
 ◀── 201 {id, name}
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [크기 ✔] | nginx | 대용량 DoS |
| [권한 ✔ A] | PHP Authz | 권한 없는 레벨로 올리기 (프론트에서 select 값 조작) |
| [3] | PHP Upload | 웹셸 업로드, HTML/SVG 저장형 XSS, 위장 파일 |
| [4] | PHP | 경로 조작, 파일명 충돌, 원본명으로 인한 헤더 인젝션 |
| [5] | 트랜잭션 | DB엔 있는데 파일 없음 / 파일은 있는데 DB엔 없음(고아 파일) |

### 5.3 파일 다운로드 — `GET /api/files/{id}/download`

```
 브라우저                  nginx           PHP (files_download.php)                   저장소
 ───────                  ─────           ────────────────────────                   ──────
 GET /api/files/57/download ─▶ 통과 ─▶ [1] 세션 → user 조회
                                       [2] id 정수 검증 (아니면 404)
                                       [3] SELECT * FROM files
                                             WHERE id = :id AND deleted_at IS NULL  ─▶ [DB] files
                                           없으면 → 404
                                       [권한 ✔] Authz::canRead($u, $file)
                                             false → 404 (403 아님! 존재 자체를 숨김)
                                                   → [LOG] deny 'file.download' 기록
                                       [4] scan_status = 'pending'/'infected' → 404 (선택)
                                       [5] 경로 = Storage::pathOf($file['stored_name'])
                                             · basename() 처리, UUID 형식 정규식 검사
                                             · realpath()가 storage 디렉터리 안인지 확인
                                       [6] (선택) 파일 sha256 재계산 ≠ DB 값 → 500 + 경보
                                       [7] Audit::allow('file.download', file)        ─▶ [DB]+[LOG]
                                       [8] 헤더:
                                             Content-Type: application/octet-stream
                                             Content-Disposition: attachment; filename*=UTF-8''인코딩된원본명
                                             X-Content-Type-Options: nosniff
                                           readfile() 또는 X-Accel-Redirect (nginx 내부 경로)   ─▶ [FS]
 ◀── 파일 바이트 스트림 (브라우저는 항상 "저장"으로 처리)
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [권한 ✔] | PHP Authz, **스토리지 접근 전** | ID만 알면 받는 문제(기존 t-box 취약점) |
| 404 통일 | PHP | "파일은 있는데 권한이 없다"는 힌트 노출 |
| [5] | PHP Storage | 경로 조작(`../../etc/passwd`) |
| [8] | 응답 헤더 | 업로드된 HTML이 같은 도메인에서 실행되는 저장형 XSS |

> **X-Accel-Redirect란?** PHP가 "이 파일 내려줘"라고 nginx에 **내부적으로만** 알려주면 nginx가 전송한다.
> 큰 파일도 PHP 메모리를 안 쓰고, 그 내부 경로(`/protected/`)는 외부 URL로는 절대 열리지 않게 `internal;`로 잠근다.

### 5.4 파일 삭제 — `DELETE /api/files/{id}`

```
 브라우저                    nginx        PHP (files_delete.php)                         저장소
 ───────                    ─────        ──────────────────────                         ──────
 DELETE /api/files/57  ────▶ 통과 ─▶ [1] 세션 → user 조회
 (X-CSRF-Token 헤더 포함)              [2] CSRF 토큰 확인 → 불일치 403
                                      [3] SELECT * FROM files WHERE id=:id AND deleted_at IS NULL ─▶ [DB]
                                          없으면 404
                                      [권한 ✔ 1] Authz::canRead($u, $file)
                                          false → 404 (볼 수도 없는 파일은 존재도 모른 척)
                                      [권한 ✔ 2] Authz::canDelete($u, $file)
                                          false → 403 → [LOG] deny 'file.delete'
                                      [4] BEGIN
                                            UPDATE files SET deleted_at = now(), deleted_by = :uid
                                              WHERE id = :id                           ─▶ [DB] (논리 삭제)
                                            Audit::allow('file.delete', file)          ─▶ [DB]+[LOG]
                                          COMMIT
                                          ※ 실제 파일은 지우지 않음 → 30일 휴지통 (F-07, F-11)
 ◀── 200 {ok:true}

 (별도) 매일 04:00  scripts/purge_trash.php
     SELECT ... WHERE deleted_at < now() - interval '30 days'
     → Storage::remove(stored_name)  ─▶ [FS] 실제 삭제
     → Audit::system('file.purge')   ─▶ [DB]+[LOG]
```

| 단계 | 어디서 | 무엇을 막나 |
|---|---|---|
| [2] | PHP | 다른 사이트에서 몰래 삭제 요청 보내기(CSRF) |
| [권한 ✔ 1·2] | PHP Authz | **기존 t-box 취약점 A**: 버튼만 숨기고 API는 누구나 |
| 논리 삭제 | DB | 실수·악의 삭제의 즉시 복구 불가 |

### 5.5 네 흐름 한눈에 비교

| 흐름 | 권한 검사 위치 | 판단 함수 | 실패 응답 | 감사 로그 |
|---|---|---|---|---|
| 목록/검색 | **SQL WHERE절** (조회 자체에 포함) | `visibleFilesWhere` | 401만 존재 | 기본 미기록 |
| 업로드 | DB에 쓰기 **전** | `canUploadAs` + `Upload::validate` | 403 / 400 / 413 | 성공·거부 모두 |
| 다운로드 | 파일 시스템 접근 **전** | `canRead` | 404 (통일) | 성공·거부 모두 |
| 삭제 | UPDATE **전** | `canRead` → `canDelete` | 404 → 403 | 성공·거부 모두 |

---

## 6. 저장 위치 — 파일 정보 vs 실제 파일

### 6.1 두 곳에 나눠 저장하는 이유

| 저장 대상 | 어디에 | 왜 |
|---|---|---|
| 파일 **정보** (이름, 크기, 레벨, 올린 사람, 해시) | PostgreSQL `files` 테이블 | 검색·권한 필터를 SQL로 하려면 DB에 있어야 함 |
| 파일 **내용** (바이트) | `/var/lib/tbox/storage/` | DB에 큰 바이너리를 넣으면 백업·성능이 나빠짐. 웹 루트 밖이라 URL 노출 없음 |

### 6.2 `files` 테이블

```sql
CREATE TABLE files (
    id            BIGSERIAL PRIMARY KEY,
    original_name TEXT        NOT NULL,                 -- 사용자가 올린 이름 (화면 표시용, 출력 시 이스케이프)
    stored_name   TEXT        NOT NULL UNIQUE,          -- 서버가 만든 이름: UUID.ext  (경로 없음)
    ext           TEXT        NOT NULL,                 -- 화이트리스트 통과한 확장자
    mime          TEXT        NOT NULL,                 -- finfo 로 판별한 실제 MIME
    size_bytes    BIGINT      NOT NULL CHECK (size_bytes BETWEEN 1 AND 104857600),
    sha256        CHAR(64)    NOT NULL,                 -- 무결성 검사용
    description   TEXT        NOT NULL DEFAULT '' CHECK (length(description) <= 500),
    access_level  TEXT        NOT NULL CHECK (access_level IN ('public','internal','team','admin-only')),
    team_id       BIGINT      REFERENCES teams(id),
    uploaded_by   BIGINT      NOT NULL REFERENCES users(id),
    scan_status   TEXT        NOT NULL DEFAULT 'clean' CHECK (scan_status IN ('pending','clean','infected')),
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    deleted_at    TIMESTAMPTZ,                          -- NULL 이면 살아있는 파일
    deleted_by    BIGINT      REFERENCES users(id),
    -- team 레벨이면 team_id 필수 (PRD 4장 규칙을 DB가 강제)
    CONSTRAINT team_requires_team_id
        CHECK (access_level <> 'team' OR team_id IS NOT NULL)
);
CREATE INDEX files_visible_idx ON files (access_level, team_id) WHERE deleted_at IS NULL;
CREATE INDEX files_name_idx    ON files (lower(original_name)) WHERE deleted_at IS NULL;
```

### 6.3 실제 파일의 위치와 이름

```
/var/lib/tbox/storage/
└── 3f/
    └── 9a/
        └── 3f9a7c1e-2b4d-4f0e-9b1a-7c8d2e5f6a01.pdf     ← stored_name
```

| 규칙 | 이유 |
|---|---|
| 이름은 서버가 만든 **UUID**만 사용 | 사용자 파일명(`../../x`)이 경로에 절대 들어가지 않음 |
| 앞 2글자/다음 2글자로 하위 폴더 분산 | 한 폴더에 수십만 개가 쌓이는 것 방지 |
| 디렉터리 `0750`, 파일 `0640`, 소유자 `tbox-php` | 다른 계정·웹 서버가 직접 읽지 못함 |
| 디렉터리에 실행 권한·PHP 핸들러 없음 | 어떤 파일이 들어와도 실행되지 않음 |
| 원본 파일명은 DB에만, 다운로드 헤더에 인코딩해서 사용 | 헤더 인젝션(CR/LF) 방지 |

---

## 7. 감사 로그 — 어디에, 어떻게 남기나

### 7.1 두 곳에 동시에 기록

```
 Audit::allow() / Audit::deny()
        │
        ├──▶ [DB] audit_logs  INSERT  (같은 트랜잭션 안. 실패하면 본 작업도 ROLLBACK)
        │
        └──▶ [파일] /var/log/tbox/audit.log  한 줄 JSON 추가 (append)
                    → logrotate 로 일 단위 회전, 1년 보존, 원격으로 복사
```

| 저장소 | 장점 | 단점 | 역할 |
|---|---|---|---|
| DB `audit_logs` | 검색·필터 쉬움, 관리 화면에서 조회 | DB가 침해되면 함께 위험 | **원본** (조회용) |
| 파일 `audit.log` | DB와 독립, 외부 SIEM으로 전송 쉬움 | 검색 불편 | **사본** (증거 보전용) |

### 7.2 `audit_logs` 테이블과 "지울 수 없게" 만드는 방법

```sql
CREATE TABLE audit_logs (
    id           BIGSERIAL PRIMARY KEY,
    happened_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    request_id   UUID        NOT NULL,
    actor_id     BIGINT,                 -- NULL = 시스템(배치)
    actor_name   TEXT,
    actor_role   TEXT,                   -- 행동 당시 역할 (나중에 바뀌어도 남김)
    action       TEXT        NOT NULL,   -- auth.login, file.download, file.delete, user.role_change ...
    target_type  TEXT,                   -- file / user / system
    target_id    BIGINT,
    target_name  TEXT,
    access_level TEXT,
    result       TEXT        NOT NULL CHECK (result IN ('allow','deny','error')),
    reason       TEXT,                   -- role_not_permitted / not_owner / mime_mismatch ...
    ip           INET,
    user_agent   TEXT,
    detail       JSONB                   -- 이전값→새값 등. 비밀번호·토큰·파일내용 금지
);
CREATE INDEX audit_time_idx  ON audit_logs (happened_at DESC);
CREATE INDEX audit_actor_idx ON audit_logs (actor_id, happened_at DESC);
```

```sql
-- db/grants.sql : 앱 계정은 감사 로그를 "추가"만 할 수 있다 (PRD S-07 Append-only)
REVOKE ALL ON audit_logs FROM tbox_app;
GRANT  SELECT, INSERT ON audit_logs TO tbox_app;
GRANT  USAGE, SELECT  ON SEQUENCE audit_logs_id_seq TO tbox_app;
-- UPDATE / DELETE / TRUNCATE 권한 없음 → 코드에 버그가 있어도, 침입자가 앱 계정을 얻어도 못 지움
```

### 7.3 기록 예시 (audit.log 한 줄)

```json
{"happened_at":"2026-09-08T14:03:11+09:00","request_id":"8f3a...","actor_id":12,"actor_name":"employee01",
 "actor_role":"Employee","action":"file.download","target_type":"file","target_id":57,
 "target_name":"급여명세_2026.pdf","access_level":"admin-only","result":"deny",
 "reason":"role_not_permitted","ip":"10.0.1.23","user_agent":"Mozilla/5.0 ..."}
```

> 위 한 줄이 바로 "실습에서 뚫렸던 시도"가 이번엔 **막히고 기록된** 모습이다.

---

## 8. nginx 설정 (핵심만)

```nginx
server {
    listen 443 ssl http2;
    server_name tbox.internal;
    ssl_certificate     /etc/ssl/tbox/fullchain.pem;
    ssl_certificate_key /etc/ssl/tbox/privkey.pem;

    root  /var/www/tbox/public;          # ★ public/ 만 노출
    index index.php;

    client_max_body_size 100m;           # PRD S-06: 100MB 초과는 앱에 닿기 전 413

    # 보안 헤더 (PRD S-03, S-09-9)
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # 정적 파일
    location /assets/ { try_files $uri =404; expires 7d; }

    # 나머지는 전부 index.php 로 (단일 입구)
    location / { try_files $uri /index.php$is_args$args; }

    # PHP 는 index.php 하나만 실행 (다른 .php 요청은 404)
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm-tbox.sock;
    }
    location ~ \.php$ { return 404; }

    # 다운로드 가속: PHP가 권한 확인 후 X-Accel-Redirect 로 넘기는 내부 경로
    location /protected/ {
        internal;                        # ★ 외부 URL로는 절대 접근 불가
        alias /var/lib/tbox/storage/;
    }

    # 숨김 파일·백업 파일 차단
    location ~ /\. { deny all; }
}
server { listen 80; server_name tbox.internal; return 301 https://$host$request_uri; }
```

| 설정 | 어떤 요구사항 |
|---|---|
| `root … /public` | 소스·설정 노출 방지 |
| `client_max_body_size 100m` | S-06 크기 제한 (1차) |
| `location ~ \.php$ { return 404; }` | 단일 입구 강제 → 검사 우회 경로 없음 |
| `internal;` | 실제 파일 URL 직접 접근 차단 |
| CSP 등 헤더 | S-03 XSS 완화 |

---

## 9. PHP 설정 (php-fpm 풀: `tbox`)

> 버전: **PHP 8.4** (8.2는 2026-12-31 보안 지원 종료 — PRD 11장 버전 선택 기준). 설치 후 `php -v` 로 확인.

```ini
; /etc/php/8.4/fpm/pool.d/tbox.conf
[tbox]
user  = tbox-php
group = tbox-php
listen = /run/php/php8.4-fpm-tbox.sock
php_admin_value[upload_max_filesize] = 100M
php_admin_value[post_max_size]       = 105M
php_admin_value[max_file_uploads]    = 1          ; 요청당 파일 1개 (PRD S-06)
php_admin_value[display_errors]      = Off        ; PRD S-09-7
php_admin_value[log_errors]          = On
php_admin_value[error_log]           = /var/log/tbox/app.log
php_admin_value[session.save_path]   = /var/lib/tbox/sessions   ; 웹 루트 밖
php_admin_value[session.cookie_httponly] = 1
php_admin_value[session.cookie_secure]   = 1
php_admin_value[session.cookie_samesite] = Lax
php_admin_value[session.use_strict_mode] = 1      ; 세션 고정 공격 방지
php_admin_value[allow_url_fopen]     = Off        ; ★ file_get_contents('http://...') 자체를 막음 (SSRF 예방, S-05)
php_admin_value[open_basedir]        = /var/www/tbox:/var/lib/tbox:/etc/tbox:/tmp
; cURL·소켓으로 SSRF 가 재발하지 않게 (SECURITY.md R-03). php-curl 은 설치하지 않는다
php_admin_value[disable_functions]   = curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,system,shell_exec,passthru,popen,proc_open
```

> `allow_url_fopen = Off` 와 `open_basedir` 는 "실수로 SSRF·경로 탈출 코드를 짜도" 실행 단계에서 한 번 더 막아주는 안전망이다.

---

## 10. DB 계정 권한 (최소 권한 원칙)

| 계정 | 용도 | 권한 |
|---|---|---|
| `tbox_owner` | 스키마 생성·마이그레이션 (배포 시에만) | 테이블 소유 |
| `tbox_app` | PHP 앱 런타임 | `users teams files`: SELECT/INSERT/UPDATE · `audit_logs`: SELECT/INSERT 만 · DELETE/DROP 없음 |
| `tbox_backup` | 백업 스크립트 | 전체 SELECT (읽기 전용) |

> 파일 삭제가 논리 삭제(UPDATE)이므로 앱 계정에 `DELETE` 권한 자체가 필요 없다. 30일 실제 정리는 `tbox_owner` 권한의 배치 스크립트가 수행한다.

---

## 11. 운영 — 백업과 복구 (PRD S-08)

### 11.1 백업 일정 (cron, 계정 `tbox-backup`)

| 시각 | 스크립트 | 내용 | 산출물 |
|---|---|---|---|
| 매 15분 | (PostgreSQL 설정) `archive_command` | WAL 아카이브 | `/var/backups/tbox/wal/` → 원격 |
| 03:00 | `scripts/backup_db.sh` | `pg_dump -Fc` → `age`로 암호화 → sha256 | `db-YYYYMMDD.dump.age` + `.sha256` |
| 03:30 | `scripts/backup_files.sh` | `restic backup /var/lib/tbox/storage` (증분, 암호화 내장) | restic 저장소 (로컬 + 원격) |
| 04:00 | `scripts/purge_trash.php` | 30일 지난 휴지통 파일 실제 삭제 | audit `file.purge` |
| 04:30 | `scripts/verify_backup.sh` | 체크섬 검증, 파일 수·크기 대조, 결과 알림 | 알림 (2회 연속 실패 시 경보) |
| 매주 일 02:00 | `scripts/consistency_check.php` | DB ↔ 스토리지 정합성 (고아 파일, 누락 파일, sha256) | 보고서 |

### 11.2 복구 순서 (PRD 7.8 절차의 명령 대응)

```
 1. 서비스 중단     sudo systemctl stop php8.4-fpm ; nginx 점검 페이지 전환
 2. 범위 판단       "DB만" / "파일만" / "둘 다", 목표 시점 T 결정
 3. 무결성 확인     sha256sum -c db-YYYYMMDD.dump.sha256
 4. DB 복구         age -d db-YYYYMMDD.dump.age | pg_restore -d tbox --clean
                    (시점 복구 필요 시: 베이스 백업 + WAL 로 recovery_target_time = T)
 5. 파일 복구       restic restore <snapshot@T> --target /var/lib/tbox/storage
 6. 정합성 검사     php scripts/consistency_check.php --strict
 7. 스모크 테스트   로그인 → 목록 → 업로드 → 다운로드 → 권한 매트릭스(PRD 5장) 확인
 8. 재개 + 기록     systemctl start php8.4-fpm ; Audit system 'ops.restore' ; 사고 보고서
```

> 복구 리허설은 **분기 1회**, 운영 서버가 아닌 **별도 서버**에서 한다. 소요 시간을 재서 RTO 4시간 안에 드는지 확인한다.

### 11.3 서버 보안 기준선 (Hardening) — PRD S-10-A

앱 코드와 무관하게 **서버를 세운 날** 적용하고, 매월 T-17~T-19 로 재확인한다.

```
 인터넷 ─✖─┐                                  (사내망 밖에서는 아예 닿지 않음)
             │
 사내망/VPN ──┼──▶ 443/tcp  nginx            ← 허용 (사내 대역만)
             ├──▶ 22/tcp   sshd  (키 인증만)  ← 허용 (관리망 대역만)
             └──✖ 그 외 전부 거부

 서버 ──▶ 밖으로 나가는 연결: 기본 거부
          허용: DNS(53) · NTP(123) · 패키지 미러(443) · 백업 저장소 · ClamAV 시그니처 · 원격 로그 서버
          → SSRF·데이터 유출·C2 통신 통로를 처음부터 막는다
```

| 영역 | 설정 | 확인 |
|---|---|---|
| 방화벽 | `ufw default deny incoming; ufw default deny outgoing;` 그 뒤 위 허용 규칙만 | `ufw status verbose` |
| SSH | `/etc/ssh/sshd_config.d/tbox.conf`: `PasswordAuthentication no`, `PermitRootLogin no`, `AllowGroups tbox-admin`, `MaxAuthTries 3` + `fail2ban` sshd jail | `sshd -T \| grep -iE 'passwordauth\|permitroot'` |
| 계정 | 관리자는 개인 계정 + `sudo`(그룹 `tbox-admin`). `tbox-php`, `tbox-backup`, `postgres` 는 `nologin` | `getent passwd \| grep -v nologin` |
| PostgreSQL | `listen_addresses = 'localhost'`, `pg_hba.conf` 는 `scram-sha-256` + 로컬 소켓만 | `ss -tlnp \| grep 5432` → 127.0.0.1 만 |
| 불필요 서비스 | `avahi`, `cups`, `rpcbind` 등 제거. 리스닝 포트는 443·22·5432(로컬) 만 | `ss -tlnp` |
| 파일 시스템 | `/var/lib/tbox/storage` 를 `noexec,nosuid,nodev` 옵션의 별도 마운트로 | `findmnt /var/lib/tbox/storage` |
| 시간 | `chrony` 활성, 사내 NTP | `chronyc tracking` |
| 자동 패치 | `unattended-upgrades` 보안 채널, 재시작은 일요일 02:00 점검창 | `/var/log/unattended-upgrades/` |
| 무결성 감시 | `/var/www/tbox`, `/etc/nginx`, `/etc/php`, `/etc/tbox` 에 AIDE(또는 `debsums`) 일일 검사 → 변경 시 AL-9 | AIDE 보고서 |
| 웹 방화벽 (선택) | `libnginx-mod-security` + OWASP CRS, 1개월 탐지 모드 후 차단 모드 | nginx error log |

> **왜 아웃바운드까지 막나**: 제로데이로 앱이 뚫리는 것은 막을 수 없을 수 있다. 하지만 뚫린 뒤 공격자가 데이터를 **밖으로 보내거나** 추가 도구를 **내려받는** 연결을 막으면 피해가 "침입"에서 그친다.

### 11.4 감시와 경보 — PRD S-11-A

```
 PHP Audit ──▶ audit_logs (DB) ──▶ 관리 화면 (조회)
     │
     └──▶ /var/log/tbox/audit.log ──▶ rsyslog(TLS) ──▶ [원격 로그 서버]  ← 추가 전용, 서버 침해 시에도 남음
 nginx access/error ─────────────────┘                     │
 sshd / sudo / unattended-upgrades ──┘                     ▼
                                                    경보 규칙 AL-1 ~ AL-9  ──▶ 알림 채널(메신저/메일) ──▶ 운영 담당
```

| 구성 | 도구 (예) | 메모 |
|---|---|---|
| 전송 | `rsyslog` (omfwd, TLS) 또는 `vector`/`fluent-bit` | `audit.log` 는 한 줄 JSON 이라 파싱이 쉽다 |
| 저장·검색 | 원격 서버의 Loki / OpenSearch / 단순 파일 + `logrotate` (규모에 맞게) | **앱 서버와 다른 계정·다른 호스트**에 둘 것 |
| 경보 | Loki ruler / Grafana alert / `cron` + SQL 쿼리 스크립트 | 규모가 작으면 `scripts/alerts.php` 가 5분마다 `audit_logs` 를 SQL 로 훑어 조건 충족 시 알림을 보내도 충분 |
| "로그 끊김" 감시 | 원격 서버 쪽에서 30분 무수신 시 경보 (AL-7) | 앱 서버가 침해되면 앱 서버의 경보는 믿을 수 없으므로 **밖에서** 감시 |

경보 규칙 AL-1 ~ AL-9 의 조건은 PRD 7.11 표를 그대로 쓴다. 예: AL-1 을 SQL 로 쓰면

```sql
SELECT actor_id, count(*) FROM audit_logs
 WHERE result = 'deny' AND action IN ('file.download','file.read')
   AND happened_at > now() - interval '10 minutes'
 GROUP BY actor_id HAVING count(*) >= 5;
```

### 11.5 운영 캘린더 — PRD S-11-B

| 주기 | 자동/수동 | 작업 | 산출물 |
|---|---|---|---|
| 매일 | 자동 | 보안 패치 · 백업 + 검증(11.1) · ClamAV 시그니처 · AIDE · 경보 | 알림 채널 |
| 매주 (월) | 수동 30분 | 감사 로그 리뷰, 정합성 검사 결과, 실패 로그인 추이, 디스크 사용량 | 주간 점검표 |
| 매월 (첫 주) | 수동 2시간 | 전체 패치 + 재시작, 계정·권한 대사(퇴사자·Guest 만료), `security_lint.sh` + T-1~T-23 | `SECURITY.md` 5장 한 줄 |
| 분기 | 수동 반나절 | 복구 리허설(11.2), `SECURITY.md` 전면 재검토, EOL 잔여 확인, 의존성 목록 갱신 | `SECURITY.md` 개정 |
| 연 1회 | 외부 포함 | 모의해킹, 침해 대응 훈련(11.6), 백업 키·SSH 키·DB 비밀번호 회전 | 보고서 |

### 11.6 침해 대응 절차 (Incident Response) — PRD S-10-E

사고 당일에 절차를 만들 수는 없다. 아래를 인쇄해 두고 연 1회 연습한다.

| 단계 | 시한 | 할 일 | 하지 말 것 |
|---|---|---|---|
| 0. 인지 | — | 경보(AL-x)·사용자 신고·외부 통보. 사고 담당자 1인 지정, 시각 기록 시작 | 혼자 판단해 무시 |
| 1. 격리 | 1시간 내 | 사내망 스위치/방화벽에서 서버 격리(전원은 끄지 않음), 의심 계정 비활성화·세션 무효화 | **서버 재부팅·삭제** (증거 소멸) |
| 2. 증거 보전 | 격리 직후 | 디스크 스냅샷, 메모리 덤프(가능하면), 원격 로그 서버 사본 잠금, 타임라인 작성 | 원본에서 직접 조사 |
| 3. 범위 파악 | 24시간 내 | 감사 로그로 **어떤 파일이 누구에게 나갔나** 확정 (다운로드 로그가 여기서 쓰인다), 침입 경로 추정 | 추정을 확정처럼 보고 |
| 4. 근절·복구 | RTO 4시간 (격리 후) | 깨끗한 서버 재구축 + 11.2 절차로 백업 복구(침입 **이전** 시점), 비밀 전체 교체 | 침해된 서버를 "치료"해서 재사용 |
| 5. 통보 | 법·규정 시한 | 개인정보(급여 등) 유출 시 개인정보보호법상 통지·신고 기한(인지 후 72시간) 준수, 영향 사용자 안내 | 축소 보고 |
| 6. 사후 | 2주 내 | 원인·대응 보고서, `SECURITY.md` 에 R-xx 추가, 재발 방지 T-xx 추가, 훈련 시나리오 갱신 | 문서 없이 종결 |

연락망(`/etc/tbox/CONTACTS.md`, 저장소 밖): 사고 담당 · 개발 · 인프라 · 정보보호 책임자 · 법무.

---

## 12. 기존 t-box와 무엇이 달라졌나 (대응표)

| 기존 t-box (뚫린 버전) | 새 t-box | 관련 절 |
|---|---|---|
| `switch_role.php` 로 역할 자유 전환 | 삭제. 로그인 + 세션, 역할은 DB에서만 | 4장 |
| `api/files.php` GET 이 전체 파일 반환, JS 에서 필터 | `Authz::visibleFilesWhere` 가 SQL WHERE 생성 | 5.1 |
| DELETE 에 권한 검사 없음 | `canRead` → `canDelete` 2단계 + CSRF | 5.4 |
| `download.php` 권한 검사 없음 | `canRead` 통과 전엔 스토리지에 닿지 않음, 실패는 404 | 5.3 |
| `search.php` SQL 문자열 결합 | 값 바인딩 + 정렬 허용 목록 | 5.1 |
| `tr.innerHTML = ...` | `textContent` + 서버 이스케이프 + CSP | 4장 [7], 8장 |
| `fetch_url.php` (SSRF) | 기능 없음 + `allow_url_fopen=Off` | 9장 |
| 업로드 무검증, `uploads/` 가 웹 루트 안, `chmod 777` | 화이트리스트·MIME 검사, `/var/lib/tbox/storage` 0750 | 5.2, 6.3 |
| `audit_logs` 테이블만 있고 미기록 | 모든 민감 행동 DB+파일 이중 기록, Append-only | 7장 |
| 비밀번호 평문 | Argon2id 해시 | 10장 |
| 백업 없음 | 일일 DB/파일 백업 + WAL + 분기 리허설 | 11장 |
| 서버 설정 언급 없음 (`chmod 777`, 역할 전환 URL) | 방화벽 인·아웃바운드 기본 거부, SSH 키 인증, 서비스 계정 nologin | 11.3 |
| 로그를 보는 사람 없음 | 원격 로그 서버 + 경보 AL-1~AL-9 + 운영 캘린더 | 11.4, 11.5 |
| 사고 나면? (계획 없음) | 침해 대응 6단계 절차 | 11.6 |

---

## 13. 초보 개발자용 체크리스트 — 새 기능을 추가할 때

| ☐ | 확인 질문 |
|---|---|
| ☐ | 핸들러 첫 줄에서 `Auth::requireLogin()` 을 부르나? |
| ☐ | 권한 판단을 `Authz` 함수로 하나? (`if ($role == 'Admin')` 을 직접 쓰지 않았나?) |
| ☐ | 목록형 응답이면 권한 조건이 **SQL WHERE** 에 들어가 있나? |
| ☐ | SQL 에 `$변수` 가 문자열로 붙어 있는 곳이 있나? → 있으면 반려 |
| ☐ | `ORDER BY`·컬럼명 같이 바인딩 불가한 곳은 허용 목록으로 처리했나? |
| ☐ | HTML 출력에 `htmlspecialchars` 를 빠뜨린 변수가 있나? JS 에 `innerHTML` 이 있나? |
| ☐ | 파일 경로를 만들 때 사용자 입력이 한 글자라도 들어가나? → 들어가면 반려 |
| ☐ | 상태를 바꾸는 요청(POST/PATCH/DELETE)에 CSRF 검사가 있나? |
| ☐ | 성공·거부 모두 `Audit` 에 기록하나? |
| ☐ | 외부 URL 로 요청하는 코드(`curl`, `file_get_contents('http…')`)가 새로 생겼나? → PRD S-05 심사 |
| ☐ | 오류 메시지에 DB 오류·경로·스택 트레이스가 섞여 나가나? |
| ☐ | 새 패키지·라이브러리·외부 도구를 추가했나? → `deploy/DEPENDENCIES.md` 갱신 + EOL 확인 (S-10-C) |
| ☐ | 새 기능이 만드는 이벤트가 경보 규칙(AL-x)에 필요하진 않나? (S-11-A) |
