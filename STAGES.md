# 단계별 개발·점검표와 학습 기록 (STAGES.md)

> 이 문서는 **과제 9단계를 단계마다 점검하기 위한 장부**다. 무엇을 만들었고(개발 사항), 무엇을 확인해야 하며(체크사항),
> 그 과정에서 **사용자가 어려워한 부분·궁금해한 점·해소된 점**(학습 기록)을 단계별로 쌓는다.
> 기록은 의무다 — 규칙은 `CLAUDE.md` 9장. 보안 판정의 근거는 언제나 `SECURITY.md` 7장 이력이고, 이 문서는 그것을 초보자 눈높이로 옮긴 점검표다.

| 항목 | 내용 |
|---|---|
| 기준 문서 | `PRD.md` 13장(9단계 로드맵), `CLAUDE.md` 6장(관문 4개), `SECURITY.md` 4장(검토 절차)·7장(이력) |
| 상태 표기 | ✅ 완료(커밋됨) · 🔄 진행 중 · ⬜ 미착수 · ⚠ 사용자 확인 대기(관문 ③) |
| 관문 | ① 구현(Opus) → ② 검토(Fable, 실행 증거) → ③ 자기 공격(**사용자**) → ④ 커밋(Fable) |
| 일괄 진행 모드 | **2026-09-11 사용자 결정 "10단계까지 한번에"** — 4~10단계는 ①→②→④ 로 이어 가고, 관문 ③은 10단계 뒤 **일괄** 수행 (CLAUDE.md 6장). 각 단계 "자기 공격 체크" 가 그때의 점검표다 |
| 갱신 시점 | 질문에 답한 직후 · 관문 ②/③ 결과가 나온 직후 · 커밋 직전 (이 문서 갱신 없이는 커밋하지 않는다) |
| 작업 저장소 | `/var/www/tbox-v2` (공개 `https://changkwon.mso.so`, 로컬 `http://127.0.0.1:8082`). `~/git2/T-Box_Re` 는 문서만 있는 옛 클론이다 — 거기서 작업하지 않는다 |

---

## 0. 진행 현황 한눈에

| 단계 | 만드는 것 | 상태 | ① 구현 | ② 검토 | ③ 자기 공격 | ④ 커밋 |
|---|---|---|---|---|---|---|
| 1 | 뼈대와 DB, 라우터(정책 필수), nginx·php-fpm, `.env` 분리 | ✅ | ✅ | ✅ 2026-09-09 | ✅ 2026-09-09 | `5c0608c` |
| 2 | 로그인·로그아웃, DB 세션, 잠금, CSRF, IP 속도 제한, 시드 계정 | ✅ | ✅ | ✅ 2026-09-09 | ✅ 2026-09-09(사용자 결정) | `ee3e527` |
| 3 | 업로드·목록·다운로드·삭제 (**권한 검사 없음, 의도**) | ✅ | ✅ | ✅ 2026-09-09 · 재검토 09-10 | ✅ 2026-09-09(뚫림 캡처) | `754d756` |
| 3+ | 감사 로그 요약 문장(`summary`) · 육하원칙 문서 | ✅ | ✅ | ✅ 2026-09-10 | ⚠ `audit.log` 직접 읽기 — 일괄 수행 시 | 4단계 커밋에 포함 |
| 4 | 서버 인가 — `Authz` 판정, 목록 SQL WHERE, 다운로드·삭제·업로드 권한, 403 동일 문구, `granted_by`·`route`·`login_ref`, 검토 계정 `qa_`, `audit.log` 보호 (+ 감사 로그 요약 문장 `summary` 포함) | ✅ | ✅ 2026-09-10 | ✅ 2026-09-10 (T-1·T-2·T-3 통과) | ⚠ 일괄 수행 대기 | 4단계 커밋 (2026-09-11) |
| 5 | 입력/출력 안전 — 이스케이프·CSP·CSRF 재확인·바인딩 전수·정렬 허용 목록·오류 은닉, PATCH(설명·레벨 변경), R-36 | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-4·T-5·T-14 통과) | ⚠ 일괄 수행 대기 | 5단계 커밋 (2026-09-11) |
| 6 | 업로드 하드닝 — 확장자 화이트리스트·MIME 짝·이중 확장자·일일 총량·100MB 경계 확정·sha256 대조·저장소 그룹 회수(R-34) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-7~T-11 통과) | ⚠ 일괄 수행 대기 | 6단계 커밋 (2026-09-11) |
| 7 | SSRF 방어 — `POST /api/files/fetch` + `src/Fetch.php` (S-05 13개 조건), `php8.1-curl` 설치 | ⬜ | | | | |
| 8 | 감사 로그 조회 화면(Admin) — `audit.view` 기록, 육하원칙 6필터 | ⬜ | | | | |
| 9 | 운영 — 백업·복구·정합성 검사·경보(AL-1)·서버 기준선·원격 로그·해시 체인, README 완성 | ⬜ | | | | |
| 10 (여유) | 계정 관리·휴지통 복구·Guest 만료·MFA | ⬜ | | | | |

---

## 1. 매 단계 공통 — 보안 점검은 이렇게 한다 (초보자용)

"검토했다"는 말이 아니라 **명령과 응답 원문**이 증거다 (`CLAUDE.md` 3장). 매 단계 관문 ②에서 Fable 이 하는 일과, 관문 ③에서 사용자가 따라 할 최소 명령을 적는다.

### 1.1 Fable 의 검토 순서 (관문 ②)

| 순서 | 할 일 | 왜 |
|---|---|---|
| 1 | `PRD.md` 12장에서 이 단계에 걸리는 `T-xx` 를 고른다 | "무엇을 확인해야 완료인가"가 문서에 있다 |
| 2 | **검토 계정만** 쓴다: `qa_admin01`·`qa_manager01`·`qa_employee01`·`qa_employee02`·`qa_guest01` + 요청마다 `-A "tbox-review/날짜"` | 감사 로그에서 사용자의 행동과 검토 행동이 갈라져야 한다 (3단계 학습 기록 참고) |
| 3 | `curl` 로 로컬 창구(`http://127.0.0.1:8082`)에 **실제 요청**을 보내고 상태 코드·본문을 그대로 적는다 | 코드를 읽고 "될 것 같다"는 증거가 아니다 |
| 4 | `psql` 로 DB 를 본다 — 감사 로그 행, 권한(`\dp`), 실제 데이터 | 응답만으로는 "기록됐는지"를 알 수 없다 |
| 5 | `bash scripts/security_lint.sh` → 금지 패턴 0건 | 사람 눈이 놓치는 `innerHTML`·SQL 결합·`curl_*` 를 기계가 찾는다 |
| 6 | 서버 설정이 바뀌었으면 `php -i`·`nginx -T`·`lsattr`·`\dp` 로 실효값 확인 | 문서에 "있다"고 적힌 것과 서버 상태는 다를 수 있다 (2026-09-10 교훈) |
| 7 | 네 부분으로 보고: ① 안전한 이유(증거) ② 불안전한 이유 ③ 미검증 ④ 다음 조치 | "미검증"을 "안전"이라 쓰지 않는다 |
| 8 | `SECURITY.md` 7장에 한 줄, 이 문서의 해당 단계 갱신 | 이력이 곧 자체점검 문서다 |

판정 용어: **통과**(실행했고 기대와 일치) · **실패**(공격이 성공했거나 기대와 다름 → 커밋 금지) · **미검증**(실행 못 함, 안전 판정 아님).

### 1.2 사용자가 따라 하는 최소 명령 (관문 ③ 공통 도구)

```bash
# 0) 시드 계정 비밀번호 보기 (root 만 읽을 수 있는 파일)
sudo cat /etc/tbox-v2/seed-accounts.txt

# 1) 로그인해서 쿠키를 파일에 저장 (employee01 예시)
curl -s -c /tmp/emp.jar -H 'Content-Type: application/json' \
  -d '{"username":"employee01","password":"비밀번호"}' https://changkwon.mso.so/api/login

# 2) 저장한 쿠키로 API 호출 — -i 를 붙이면 상태 코드와 헤더까지 보인다
curl -s -i -b /tmp/emp.jar https://changkwon.mso.so/api/files

# 3) CSRF 토큰 받기 (삭제·업로드 같은 상태 변경 요청에 필요)
curl -s -b /tmp/emp.jar https://changkwon.mso.so/api/me

# 4) 감사 로그 마지막 10줄 (사람 문장 summary 가 줄 끝에 있다)
sudo tail -n 10 /var/log/tbox-v2/audit.log
sudo -u postgres psql -d tbox_re -c "SELECT happened_at, actor_name, action, result, reason, summary FROM audit_logs ORDER BY id DESC LIMIT 10;"
```

> 브라우저로 할 때는 F12 → **Network** 탭에서 응답 원문(Response)을, **Console** 탭에서 `fetch(...)` 직접 호출을 본다.

---

## 2. 단계별 점검표

각 단계는 같은 틀이다: **개발 사항**(무엇을 만들었나) → **검토 체크**(Fable ②, 실측 결과 요약) → **자기 공격 체크**(사용자 ③, 따라 할 명령) → **커밋** → **학습 기록**.

### 1단계 — 뼈대와 DB ✅ (`5c0608c`, 2026-09-09)

**개발 사항**
- [x] 스키마 6표 `teams·users·permissions·files·audit_logs·sessions` (`db/schema.sql`), 권한 매트릭스 16행 시드 (`db/seed.sql`)
- [x] 최소 권한 `db/grants.sql` — `tbox_app`: `permissions` SELECT 만, `audit_logs` SELECT·INSERT 만, 스키마 CREATE·PUBLIC CONNECT 회수
- [x] 단일 입구 `public/index.php` — 라우트마다 정책 필수, 정책 미등록·핸들러 없음 → **부팅 실패(전 요청 500)** (R-04)
- [x] `.env` 분리(저장소 루트, `changkwon:tbox-php 0640`, `.gitignore`) + `.env.example`
- [x] nginx 사이트 3종(`tbox-v1-local`·`tbox-v2`·`tbox-v2-local`) + 버전 스위치 `deploy/switch-site.sh`, php-fpm 전용 풀 `tbox`(`tbox-php` 계정, `allow_url_fopen=Off`, `open_basedir`, `disable_functions`)
- [x] 저장소를 옛 취약 사이트 웹 루트 밖(`/var/www/tbox-v2`)으로 이동, 저장소 밖 폴더 `-v2` 접미사로 v1 과 분리

**검토 체크 (Fable ②)** — `SECURITY.md` 7장 2026-09-09 세 행
- [x] `/.env` `/src/Db.php` `/db/schema.sql` `/foo.php` `/protected/x` `/.git/config` `/PRD.md` → 전부 404
- [x] `/api/health` 200, 404/405 JSON, 보안 헤더 4개(CSP·nosniff·DENY·no-referrer) + 공개 도메인 HSTS
- [x] `\dt` 6표, `\dp` 앱 계정 권한 최소, `tbox_app` 으로 `CREATE TABLE` 실패
- [x] 정책 미등록 라우트 → 500 (CLI 재현 + app.log)
- [x] 린트 0건, `.env` 미추적

**자기 공격 체크 (사용자 ③)** — 2026-09-09 통과
- [x] `https://changkwon.mso.so/.env` → 404
- [x] `/src/Db.php` → 404, `/db/schema.sql` → 404, `/T-Box_Re/src/Db.php` → 404
- [x] `sudo -u postgres psql -d tbox_re -c '\dt'` → 6 rows

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-09 | 어려움 | 저장소가 옛 취약 사이트의 웹 루트 **안**(`/var/www/html/T-Box_Re`)에 있어 옛 사이트 경유로 소스·`.env` 에 닿을 위험(R-29) | 저장소를 `/var/www/tbox-v2` 로 이동 → 경유 경로 자체가 사라짐. 이동 직후 nginx `root`·`open_basedir` 가 옛 경로라 503 → **저장소를 옮기면 `install.sh`·`switch-site.sh` 재실행이 필수** (README 에 명시) |
| 2026-09-09 | 결정 | 실습 VM 의 PHP 8.1·PostgreSQL 14 는 지원 종료(EOL) 버전인데 그대로 써도 되나 | 참가자 전원 같은 VM 이므로 유지(Q-11), 위험 수용 R-21. 운영 전환 시 업그레이드 필수 |
| — | 궁금 | (이 단계의 사용자 질문은 이전 세션에 별도 기록이 없다) | 이후 발견되면 여기에 추가 |

### 2단계 — 인증/세션 ✅ (`ee3e527`, 2026-09-09)

**개발 사항**
- [x] `POST /api/login`(JSON 만) · `POST /api/logout` · `GET /api/me`(CSRF 토큰 전달), 로그인 화면
- [x] DB 세션 — 쿠키엔 난수 토큰만, DB 엔 SHA-256; 유휴 30분·절대 8시간; 로그인 시 재발급(세션 고정 차단); 비활성 계정 즉시 파기
- [x] 실패 5회 → 10분 잠금(423), 실패 문구 통일(401), 없는 아이디도 더미 해시 검증(시간차 방지)
- [x] CSRF 토큰(HMAC, 저장 없음) — `X-CSRF-Token` 헤더, 5단계 검증을 앞당겨 구현
- [x] nginx 로그인 IP 속도 제한 30r/m burst 10 (R-06)
- [x] 시드 계정 5개 — 비밀번호는 `/etc/tbox-v2/seed-accounts.txt`(root 0600) 에만

**검토 체크 (Fable ②)** — `SECURITY.md` 7장 2026-09-09 "2단계 구현 검토"
- [x] 미로그인 `/api/me` 401 · `/` 302 · 미등록 경로 404
- [x] 쿠키 1자 변조·63자 → 401, 가짜 쿠키로 로그인 → 새 토큰 발급
- [x] CSRF 없음/틀림 → 403, employee02 6회 → 5×401 + 423 + DB `locked_until`
- [x] 만료 시각 조작 → 401, 속도 제한 25회 → 10 통과·15 429
- [x] 해시 5계정 `$argon2id$` (T-16), 감사 로그에 비밀값 0건

**자기 공격 체크 (사용자 ③)** — 2026-09-09 통과(사용자 결정)
- [x] guest01·employee01 로그인·홈 화면 동작, `/api/me` 응답 확인
- [x] DevTools 로 역할 글자 변조 → 새로고침 시 원복(서버 역할 불변)
- [ ] 쿠키 변조·세션 고정·CSRF 403·잠금 423·유휴 만료 — 세부 결과 미보고(Fable curl 실측으로 대체). 원하면 1.2 의 명령으로 언제든 재확인 가능

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-09 | 궁금 | Network 탭에 CSRF 토큰이 그대로 보이는데 위험하지 않나? | 정상이다. 토큰은 **세션에 결속된 HMAC** 이라 다른 세션에서 쓸 수 없고, 다른 사이트는 이 응답을 읽을 수 없다(교차 출처 읽기 불가 실측: `Origin` 위조 요청에 `Access-Control-Allow-Origin` 없음, `no-store`). 진짜 비밀인 세션 쿠키는 `HttpOnly` 라 JS 가 읽지 못한다 (ARCH 4.0) |
| 2026-09-09 | 궁금 | 개발자도구로 화면의 역할 글자를 바꾸면 권한이 바뀌나? | 바뀌지 않는다. 화면 글자는 표시일 뿐이고 역할은 매 요청 서버가 DB 에서 다시 읽는다 (PRD S-01). 새로고침하면 원래대로 돌아온 것이 그 증거 |
| 2026-09-09 | 어려움 | 관문 ③ 항목이 많아(8가지) 일부는 세부 결과를 보고하지 못한 채 "진행" 결정 | 같은 항목을 Fable 이 curl 로 실측해 통과했음을 이력에 남기고 진행. 이후 단계부터는 **자기 공격 항목을 3~4개로 압축**해 이 문서에 명령까지 적어 둔다 |

### 3단계 — 기본 기능, 권한 검사 없음(의도) ✅ (`754d756`, 2026-09-09)

**개발 사항**
- [x] `GET /api/files`(검색·정렬·페이지) · `POST /api/files`(업로드) · `GET /api/files/{id}/download` · `DELETE /api/files/{id}`(논리 삭제) · `GET /api/teams`, 홈 화면(app.js `textContent` 만)
- [x] 라우트 정책은 전부 `authenticated` — **로그인만 하면 admin-only 포함 전부 보이고 받고 지워진다(뚫려야 정상)**
- [x] 3단계에도 지킨 것: 값 바인딩·정렬 허용 목록·LIKE 이스케이프, UUID 저장명 + 웹 루트 밖 저장, 첨부 다운로드(`octet-stream`+`attachment`), CSRF, 업로드 트랜잭션(고아 파일 방지), 감사 로그

**검토 체크 (Fable ②)** — 2026-09-09 검토 + 2026-09-10 재검토(60여 건)
- [x] 뚫림 기준선 확인(의도): employee01 목록에 admin-only·team=2 노출, admin-only 다운로드 200·삭제 200, guest01 admin-only 업로드 201
- [x] 미로그인 401, CSRF 403, SQL 인젝션 페이로드 전부 0건/기본값(T-5 선행), 경로 조작 `../../etc/passwd.txt` → `passwd.txt`(T-11 선행), 101MB 413(T-9), `/protected/x` 404(T-10)
- [x] 헤더 인젹션(CR/LF 파일명) 0건, 파일 유실 시 500 + `error file_missing`(성공 기록 없음)
- [x] 린트 0건, 감사 로그 DB 건수 = 파일 건수

**자기 공격 체크 (사용자 ③)** — 2026-09-09 뚫림 캡처 완료
- [x] employee01 로 admin01 의 admin-only `secret.txt` 와 manager01 의 영업팀 `secret.txt` 가 목록에 보임 → **캡처**
- [x] admin-only 다운로드 성공, 삭제 버튼 보임 → **캡처**
- [x] Network 탭 `/api/files` 응답 원문에 admin-only 행 포함 → **캡처** (4단계 T-2 비교용)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-10 | 어려움 | `audit.log` 가 `action=file.delete result=allow` 처럼 "컴퓨터 언어"라 무슨 일인지 읽기 어렵다 | 모든 행에 한국어 요약 한 문장 `summary` 를 **기록 시점에** 저장(ARCH 7.4 문장 표 33행). `tail -f audit.log` 만으로 읽힌다. 문장 틀이 없는 조합은 기록 실패 = 요청 실패로 드러나게 했다 |
| 2026-09-10 | 궁금 | 로그에 `employee01` 이 파일을 지운 기록이 있는데 나는 지운 적이 없다. 누가 지웠나? | 실측 결과 **Fable 의 검토 curl(127.0.0.1)** 이었다. 계정만으로는 사람을 특정할 수 없다는 사례 → ① 검토는 `qa_` 계정 + `User-Agent: tbox-review/날짜` 로만 ② 모든 행에 `login_ref`(로그인 회차)·`route`·`granted_by`(허용 근거) 추가 ③ 감사 로그 육하원칙 표 (PRD S-07, ARCH 7.5). 4단계에서 구현 |
| 2026-09-10 | 궁금 | 육하원칙 밖의 "추가 개선 필요사항"(영구삭제·비밀번호 변경·정합성·로그 무결성)은 왜 안 넣었나? | 누락이 맞아 PRD 7.7 행동 표에 4행 추가. 함께 서버를 실측하니 문서에 "있다"던 `audit.log` 추가 전용(`chattr +a`)·`logrotate` 가 **실제로는 없었다** → 4단계 운영 보강으로 설치. 교훈: 문서와 서버 상태를 대조하지 않으면 누락이 남는다 |
| 2026-09-10 | 해소 | 요약 문장에서 파일명 `{level}{target}{actor}.txt` 가 `internal{target}{actor}.txt` 로 왜곡 기록됨(id 316~319) | 순차 치환(`str_replace`)이 앞 단계에서 넣은 값을 다시 치환한 것. **한 번에 치환하는 `strtr`** 로 재지시 후 원형 유지 확인. 파일명은 문장을 흉내 낼 수 있으니 `summary` 는 보조 필드, 판단 근거는 구조화 컬럼 |

> ⚠ **관문 ③ 대기**: 감사 로그 요약 문장은 사용자가 `sudo tail -n 20 /var/log/tbox-v2/audit.log` 로 직접 읽고 "읽힌다"고 확인하면 통과다. 4단계 커밋 앞에 별도 커밋으로 넣는다.

### 4단계 — 서버 인가 ⚠ 사용자 확인 대기 (S-01, S-02 · T-1, T-2, T-3)

**개발 사항** — 2026-09-10 Opus 구현 완료 (관문 ①)
- [x] `Authz::decideRead / decideUploadAs / decideDelete` — 판정 + **근거 코드**(`matrix`·`same_team`·`all_teams`·`owner`·`team_scope`·`admin_all` / `role_not_permitted`·`team_mismatch`·`not_owner`) 반환, `can*` 은 그 껍데기. `visibleFilesWhere` 가 `deleted_at IS NULL` 을 항상 포함
- [x] 라우트 정책 전환: `GET /api/files`·`download` → `file.read`, `POST /api/files` → `file.upload`, `DELETE` → `file.delete` (Guest 는 라우터에서 403 + `authz.route` deny)
- [x] 목록·건수: `Authz::visibleFilesWhere` 를 **SQL WHERE** 에 AND — 응답에 권한 없는 행이 존재하지 않음(S-02), `total` 도 같은 조건
- [x] 다운로드: 파일 행 읽은 직후 **저장소 접근 전** `decideRead` → 실패 403 `{"error":"권한이 없습니다"}` + `Audit::deny` (Q-6)
- [x] 삭제: `decideRead` → `decideDelete` 두 단계, 실패 403 동일 문구 + deny (사유 `role_not_permitted`/`team_mismatch`/`not_owner`)
- [x] 업로드: `decideUploadAs` — Guest·Employee 의 admin-only, 남의 팀 team → 403 + deny
- [x] 감사 로그 육하원칙 채우기: `Audit::write()` 가 `detail.route`(라우트 **패턴**)·`actor_team`·`login_ref` 자동 추가, 핸들러가 `granted_by`·`sha256`·`size_bytes`·`owner_id`·`target_team` 추가
- [x] 화면 편의: `/api/files` 행에 `can_delete`, `/api/me` 에 `upload_levels` → 버튼·선택지는 서버 값으로만 (권한 근거 아님)
- [x] 검토 계정 `qa_*` 5개 생성(`scripts/seed_users.php` 계정별 멱등, `--no-qa`), `install.sh` 는 비밀번호 파일에 **덧붙이기**
- [x] 운영 보강: `audit.log` `chattr +a` + `deploy/logrotate.d/tbox-v2`(일 단위·365일·압축, pre/post 에서 속성 해제·재설정)

**검토 체크 (Fable ②)** — 2026-09-10 **통과** (`SECURITY.md` 7장 "4단계 구현 검토" 행에 원문 요약)
- [x] T-2: `qa_employee01` 목록에 `admin-only` 문자열 0·`team_id:2` 0·비교용 4건 0건·`total`=행 수, `q=secret` 0건; `qa_guest01` public 만; `qa_admin01` 전부(8건)
- [x] T-3: `qa_employee01` admin-only·team2 다운로드 → `403 {"error":"권한이 없습니다"}` (파일 정보 0) + deny `role_not_permitted`/`team_mismatch`; public → 200 첨부 헤더; `qa_guest01` internal → 403
- [x] T-1: 남의 파일 DELETE 403(`role_not_permitted`/`team_mismatch`/`not_owner`), 본인 200 `owner`; `qa_manager01` 자기 팀 200 `team_scope`·남의 public 403·team2 403; `qa_guest01` 403(라우터 `authz.route`); `qa_admin01` 200 `admin_all`. 비교용 6건 무손상
- [x] 업로드 권한: Guest 403(`authz.route`), Employee admin-only 403·team2 403·team1 201 `same_team`·public 201 `matrix`, Admin team2 201 `all_teams`
- [x] 감사 로그 qa 행 전부 `route`(패턴 3종)·`login_ref` 12자·`actor_team`, 인가 거친 allow 행 `granted_by` 누락 0, 비밀값 0건, 403 본문 전부 동일 문구
- [x] `lsattr` 에 `a` 걸린 뒤에도 기록 계속(DB 389 = 파일 390 − 1단계 스모크 1줄), tbox-php 덮어쓰기·삭제 `Operation not permitted`, `logrotate -d` 오류 0
- [x] 린트 0건, `php -l` 통과, `\dp` `tbox_app=ar` 불변, `.env` 미추적, 회귀(401·CSRF 403·404·`sort` 주입) 그대로
- [ ] **미검증** ① 브라우저 화면(`app.js`: 레벨 선택지·Guest 업로드 패널 숨김·`can_delete` 버튼) — 서버 응답만 확인, 사용자 관문 ③에서 눈으로 확인 ② logrotate 실제 회전(살아 있는 증거 파일을 자르므로 미실행)
- 관찰: `csrf`·`auth.required` 거부 행의 `target_name`·`summary` 에 실제 UUID 경로가 남음 → R-36, 5단계에서 패턴화

**자기 공격 체크 (사용자 ③)** — 3단계 캡처와 **같은 화면**을 employee01 로 다시 캡처
- [ ] **T-2** 로그인 후 목록에 `secret.txt`(admin-only)·영업팀 `secret.txt`·`guest-adm.txt`·`emp-team2.txt` 가 **보이지 않음**. F12 → Network → `/api/files` 응답 원문(Response)에 `admin-only` 글자가 **한 글자도 없음**
- [ ] **T-3** 3단계에 받아 둔 admin-only 파일 주소로 직접 접근: `curl -s -i -b /tmp/emp.jar https://changkwon.mso.so/api/files/454e8009-2f19-4bcd-a6ce-a74dd58fd340/download` → `403` + `{"error":"권한이 없습니다"}` (파일명·레벨 없음)
- [ ] **T-1** F12 → Console 에서 `fetch('/api/files/454e8009-2f19-4bcd-a6ce-a74dd58fd340',{method:'DELETE',headers:{'X-CSRF-Token':'(/api/me 의 csrf_token)'}}).then(r=>r.status)` → `403`. 삭제 버튼을 Elements 에서 되살려 눌러도 같다
- [ ] 감사 로그 확인: `sudo tail -n 5 /var/log/tbox-v2/audit.log` 에 위 시도가 `deny` + 한국어 문장으로 남아 있음. 이때 `actor_name` 은 `employee01`(본인), `qa_` 행은 Fable 검토
- [ ] 위 결과를 3단계 캡처와 나란히 놓고 "뚫림 → 막힘" 비교 저장

**커밋** — 2026-09-11 일괄 진행 모드로 관문 ② 통과 직후 커밋 `4단계: 서버 인가 — …` (S-01, S-02, S-07). 감사 로그 요약 문장·육하원칙 문서는 코드가 한 파일(`src/Audit.php`)에 섞여 있어 같은 커밋에 포함했다. 관문 ③은 10단계 뒤 일괄.

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-10 | 궁금 | `ls` 와 `ll` 의 차이, `ls -l` 과 `ll` 결과가 왜 다른가 | `ll` 은 이 서버 `.bashrc` 91행의 별칭 `ls -alF` 다 — `-a`(숨김 파일 포함)·`-F`(종류 기호) 가 더 붙는다. 자세한 내용은 3장 기초 기록 |
| 2026-09-10 | 궁금 | `type ls -l` 이 `-l: not found` 를 내는 이유, `-a` 와 `-A` 차이, `.` 과 `..` 은 무엇인가 | 3장 기초 기록에 정리 |
| 2026-09-10 | 요청 | 단계별로 점검할 수 있게 개발 사항·체크사항을 별도 문서로, 사용자의 어려움·궁금·해소를 단계별로 기록 의무화 | 이 문서(`STAGES.md`) 신설 + `CLAUDE.md` 9장 기록 의무 추가 |
| 2026-09-10 | 해소 | (검토 중 발견) 화면이 레벨 목록·삭제 버튼을 스스로 정하면 "화면에 심은 권한 판단"이 된다 | 선택지(`upload_levels`)와 버튼(`can_delete`)을 **서버가 내려 주고** 화면은 그대로 그린다. 그래도 요청은 서버가 다시 검사 — 버튼을 되살려도 403 (PRD 10장, ARCH 5.1 메모). 관문 ③ T-1 이 바로 이 확인 |

### 5단계 — 입력/출력 안전 + 설명·레벨 변경 ✅ (S-03, S-04, S-09-3, F-08 · T-4, T-5, T-14) — 2026-09-11 커밋

**개발 사항**
- [x] 출력 이스케이프 전수 점검: 서버 HTML 동적 값 2곳(`username`·`role`) 모두 `htmlspecialchars`, app.js 는 `textContent`·UUID 검사 뒤 href 만. 고칠 곳 0건
- [x] SQL 전수: 25개 전부 고정 문자열 + 바인딩, `ORDER BY` 허용 목록, `LIMIT/OFFSET` 정수 바인딩, `PDO::query/exec` 0
- [x] CSRF: 상태 변경 라우트 4개(logout·upload·PATCH·DELETE) 전부 라우터에서 검사, 로그인은 JSON 전용 + SameSite
- [x] `PATCH /api/files/{id}` — 허용 필드 `description`·`access_level`·`team_id` 만(R-07), `decideRead → decideEdit → decideLevelChange`, **등급 하향은 Admin 만**, `before/after`·`changed` 기록, UPDATE 는 고정 조각 3개
- [x] 화면: 서버 `can_edit` 가 참일 때만 "수정" 버튼(prompt 3단계). 판단은 서버가 다시 한다
- [x] R-36: `csrf`·`auth.required` 거부 기록의 대상을 실제 UUID 경로 → 라우트 패턴으로

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "5단계 구현 검토")
- [x] T-4 설명 `<img src=x onerror=alert(1)>`·`<svg onload>`·파일명 `"><script>` → JSON 문자열 그대로, 홈 HTML 0회, 다운로드 첨부 헤더
- [x] T-5 `' OR '1'='1`·UNION·`;DROP`·`sort/dir/page` 주입 → 200 + 0건/기본값, 오류 문구 0. PATCH 설명 SQL 조각은 글자 그대로 저장
- [x] T-14 토큰 없이 PATCH/DELETE/POST → 403, 기록 대상이 `PATCH /api/files/{id}` 패턴
- [x] PATCH 권한 22종(본인·남·읽기 불가·Manager·Admin 하향·Guest·형식 오류·불변 컬럼) 전부 기대값
- [x] 오류 은닉: 저장 파일을 잠시 옮겨 500 유도 → 본문 `{"error":"서버 오류"}` 만
- [ ] **미검증** 브라우저 "수정" 버튼 흐름(prompt) → 관문 ③

**자기 공격 체크 (사용자 ③)** — employee01 로
- [ ] **T-4** 업로드 설명란에 `<img src=x onerror=alert(1)>` 를 넣어 올리기 → 목록에 **글자 그대로** 보이고 알림창이 뜨지 않음. F12 → Network → `/api/files` 응답에서 그 문자열이 JSON 문자열 안에 있음
- [ ] **T-5** 검색창에 `' OR '1'='1` → 0건, 오류 문구 없음. 주소창에서 `/api/files?sort=id;DROP TABLE files` → 정상 목록(기본 정렬)
- [ ] **T-14** F12 → Console 에서 토큰 없이 `fetch('/api/files/<본인 파일 UUID>',{method:'DELETE'}).then(r=>r.status)` → `403`
- [ ] **F-08** 본인 파일의 "수정" 버튼으로 등급을 `internal` 로 올리기 → 성공. 다시 `public` 으로 **내리기** → "권한이 없습니다"(하향은 Admin 만). `sudo tail -n 3 /var/log/tbox-v2/audit.log` 에 `downgrade_not_permitted` 와 한국어 문장 확인

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중 발견) 검토 스크립트가 따옴표 든 파일명을 로컬 파일 경로로 쓰다 실패 → PATCH 시험이 빈 ID 로 나가 전부 405 | 앱이 아니라 스크립트 문제. curl 은 `-F "file=@경로;filename=표시이름"` 으로 **로컬 경로와 서버에 보내는 이름을 분리**해야 한다. 같은 이유로 `-F 'description=<…'` 은 `<` 를 파일 읽기로 해석하므로 `--form-string` 을 쓴다 |
| 2026-09-11 | 결정 | 등급 하향(`admin-only`→`public` 방향)은 누가 할 수 있나 | PRD 4장·5.3: **Admin 만**. 코드 `Authz::decideLevelChange` 의 `LEVEL_RANK` 비교. 실측: Employee 하향 403 `downgrade_not_permitted`, Admin 하향 200 + `before/after` 기록 |

### 6단계 — 업로드 하드닝 ✅ (S-06, R-13, R-34 · T-7~T-11) — 2026-09-11 커밋

**개발 사항**
- [x] 확장자 화이트리스트 14종(PRD 7.6), 없는 확장자·목록 밖 → `ext_not_allowed`
- [x] finfo 실제 내용(MIME) ↔ 확장자 짝 표 — 어긋나면 `mime_mismatch`. `application/octet-stream` 은 어디에도 허용하지 않음
- [x] 이중 확장자(`shell.php.txt`, `.htaccess.txt`) → `double_extension`; 제어문자로 세그먼트 쪼개기 방어
- [x] 정확히 100MB 는 통과, +1바이트 거부(PHP), 101MB 는 nginx 413 (`client_max_body_size 101m`)
- [x] 사용자별 24시간 2GB(`quota_exceeded`, 삭제 파일 포함)
- [x] 다운로드 전 sha256 대조 필수 — 불일치 500 + `hash_mismatch` 기록, 전송 안 함 (R-13)
- [x] 다운로드는 PHP `readfile()` 확정, 저장소 그룹 `www-data` → `tbox-php` 회수 (R-34 해소)
- [x] ClamAV 는 과제 범위 밖으로 결정(Q-3), `scan_status` 자리만 유지

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "6단계 구현 검토")
- [x] T-7 `shell.php` → 400, 저장소 파일 수 불변 · T-8 PHP 내용 `doc.pdf`·텍스트 `img.png` → 400 `mime_mismatch`, `evil.html` → 400
- [x] 이중 확장자 3종 400, `report.final.txt` 201 · T-9 100MB 201 / +1B 400 / 101MB 413 · T-10 세 경로 404 · T-11 `passwd.txt` + UUID 저장
- [x] sha256 변조 → 500 `{"error":"서버 오류"}` + `hash_mismatch`, 원복 200 · 그룹 회수 후 `www-data` 읽기 거부
- [x] 일일 총량 SQL 부풀림 → 400 `quota_exceeded` → 실제 크기로 원복(DB↔FS 전수 대조 불일치 0)
- [ ] **미검증** 진짜 HWP 5.x 파일(스텁만), 브라우저 업로드 폼 → 관문 ③

**자기 공격 체크 (사용자 ③)** — employee01 로
- [ ] **T-7** 메모장에 `<?php echo 1;` 을 적어 `shell.php` 로 저장 → 업로드 → "잘못된 요청입니다"
- [ ] **T-8** 같은 파일 이름만 `doc.pdf` 로 바꿔 업로드 → 거부. `sudo tail -n 2 /var/log/tbox-v2/audit.log` 에 `mime_mismatch` 문장
- [ ] **T-10** 정상 파일 하나 올린 뒤 `sudo -u postgres psql -d tbox_re -c "select stored_name from files order by id desc limit 1"` 로 저장 이름을 보고 `https://changkwon.mso.so/protected/앞2/다음2/저장이름` 접속 → 404
- [ ] 이중 확장자 `x.php.txt` 업로드 → 거부

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중 발견) `/dev/zero` 로 만든 100MB `.txt` 는 업로드가 거부된다 — 왜? | 내용이 NUL 바이트라 finfo 가 `application/octet-stream` 으로 판별하고 `txt` 짝 표에 없어 `mime_mismatch`. **크기가 아니라 내용 검사에 걸린 것**. 줄바꿈 있는 텍스트로 만들어야 `text/plain` 이 된다. 위장 파일 검사가 의도대로 작동한 사례 |
| 2026-09-11 | 결정 | 100MB 경계: 정확히 100MB 파일은 올라가야 하나? | PRD S-06 "100MB" 를 포함 경계로 확정 — nginx 는 101m 으로 요청 전체를 받고 PHP `Upload::MAX_BYTES`(104,857,600) 초과만 거부. 3단계 재검토 관찰 ① 해소 |
| 2026-09-11 | 결정 | HWP 파일의 실제 MIME 은 무엇인가? 이 VM 은 `application/cdfv2`(OLE 컨테이너) | `hwp` 칸에만 허용. 부작용: 구형 MS Office OLE 문서를 `.hwp` 로 위장하면 통과 — ClamAV(Q-3) 없는 한 남는 위험, R-02 비고에 기록 |

### 7단계 — SSRF 방어 ⬜ (S-05, F-14 · T-6)

**개발 사항(예정)**: `php8.1-curl` 설치(`DEPENDENCIES.md` 갱신), `src/Fetch.php` 한 파일에 격리, https·443 만, 호스트 화이트리스트(`.env` `FETCH_ALLOWED_HOSTS`, 초기 비움), IP 검증 + `CURLOPT_RESOLVE` 고정, 리다이렉트 금지, 크기·시간 상한, `Upload::validate` 재사용, 응답 비노출, Manager 이상 + 시간당 10회, 문장 틀 `file.fetch` 선행 추가
**검토 체크(예정)**: S-05 검증 표 페이로드 **전부**(`file://`·`http://`·`127.0.0.1`·`169.254.169.254`·`localhost`·`2130706433`·`[::1]`·`user@host`·`:8443`·허용 외 도메인·내부 IP 해석·302·`shell.php`) → 전부 "가져올 수 없습니다" + 감사 로그 사유 · `grep -rn curl_ src public` → `Fetch.php` 만
**자기 공격 체크(예정)**: 위 페이로드 중 5개 골라 브라우저·curl 로 → 전부 같은 문구 · 감사 로그에 사유 확인
**학습 기록**: (진행하면서 기록)

### 8단계 — 감사 로그 조회 ⬜ (S-07 · T-12, T-13)

**개발 사항(예정)**: `GET /api/audit`(정책 `audit.read`, Admin 만) + 화면, 조회 자체 기록 `audit.view`, 육하원칙 6필터(누가·언제·어디서·무엇을·어떻게·왜), `summary`·`user_agent`·`target_name` 이스케이프 출력, 404·405 기록 여부 결정
**검토 체크(예정)**: T-12 1~7단계 공격을 재수행 → 전부 `deny` 로 존재 · T-13 Employee `/api/audit` → 403 + 그 시도 기록 · 화면 XSS(`target_name` 페이로드) 없음
**자기 공격 체크(예정)**: employee01 로 `/api/audit` → 403 · admin01 로 화면에서 자기 시도를 필터로 찾기
**학습 기록**: (진행하면서 기록)

### 9단계 — 운영 ⬜ (S-08, S-10, S-11 · T-15, T-17~T-20)

**개발 사항(예정)**: `backup_db.sh`(pg_dump+age+sha256)·`backup_files.sh`·`verify_backup.sh`·`purge_trash.php`·`consistency_check.php`·`audit_chain.php`·`alerts.php`(AL-1), cron, `tbox_backup` 역할, 복구 절차 README, 서버 기준선(ufw·sshd·chrony, 가능한 범위), rsyslog 원격 전송, `DEPENDENCIES.md` 최종
**검토 체크(예정)**: T-15 백업 → 삭제 → 복구 → 정합성 통과(시간 측정) · T-17 `sshd -T`·`ufw status` · T-18 `ss -tlnp` · T-19 버전 · T-20 AL-1 경보 도착
**자기 공격 체크(예정)**: 파일 1개 지우고 백업에서 복구해 sha256 일치 확인 · employee01 로 admin-only 6회 요청 → 경보
**학습 기록**: (진행하면서 기록)

### 10단계 (여유 시) — 계정 관리·복구·Guest 만료·MFA ⬜ (F-10, F-11, R-05, R-12 · T-22)

**개발 사항(예정)**: 계정 생성·비활성화·역할 변경(사유 필수 `reason_text`), 비밀번호 변경 + 전 기기 세션 종료(`Auth::revokeAllSessions`), 휴지통 복구(`file.restore`), Guest `expires_at`, MFA(TOTP)
**검토 체크(예정)**: T-22 비활성화 즉시 다른 기기 세션 401 · 역할 변경 `before/after` 기록
**학습 기록**: (진행하면서 기록)

---

## 3. 학습 기록 — 단계와 무관한 기초 (터미널·리눅스)

과제 도중 나온 기초 질문도 여기에 쌓는다. "몰라서 막힌 것"이 다음 사람의 첫 장애물이기 때문이다.

| 날짜 | 질문 | 답 | 확인 명령 |
|---|---|---|---|
| 2026-09-10 | `ls` 와 `ll` 의 차이는? | `ls` 는 실제 명령, `ll` 은 셸이 미리 정해 둔 **별칭(alias)**. 이 서버에서는 `~/.bashrc` 91행 `alias ll='ls -alF'` | `type ll` → `ll is aliased to 'ls -alF'` |
| 2026-09-10 | `ls -l` 과 `ll` 결과가 다른데? | `ll` = `ls -alF` 이므로 `-a`(`.`·`..`·숨김 파일 포함)와 `-F`(디렉터리 `/`, 실행 파일 `*`, 링크 `@` 기호) 가 더 붙는다. 이 서버는 `ls` 자체도 `ls --color=auto` 별칭 | `ls -l` vs `ls -alF` 를 나란히 |
| 2026-09-10 | `type ls -l` 하면 `-l: not found` 가 나오는 이유? | `type` 은 인자 **하나하나를 명령 이름**으로 조회한다. `ls` 는 별칭이라고 알려 주고, `-l` 이라는 이름의 명령은 없으니 not found. 별칭은 첫 단어(`ls`)만 치환되고 `-l` 은 뒤에 그대로 붙어 실행된다 | `type ls` · `alias` (별칭 전체 목록) |
| 2026-09-10 | `-a` 와 `-A` 가 달라? | 둘 다 숨김 파일을 보이지만 `-a`(`--all`)는 `.`(현재)·`..`(상위)까지 포함, `-A`(`--almost-all`)는 그 둘만 뺀다. 스크립트에서 디렉터리를 순회할 땐 `.`·`..` 이 섞이면 위험하므로 `-A` 가 안전 | `ls -a` vs `ls -A` |
| 2026-09-10 | `.` 과 `..` 을 누르면 앞뒤 페이지 이동인가? | "누르는 버튼"이 아니라 **디렉터리를 가리키는 이름**이다. `..` = 상위 디렉터리(`cd ..` 로 한 단계 위), `.` = 현재 디렉터리(`./script.sh`, `cp ../a.txt .`). "직전 위치로 되돌아가기"는 `cd -` | `cd ..` · `cd -` · `pwd` |
| 2026-09-10 | 스크립트 안에서 `ll` 을 쓰면? | 별칭은 대화형 셸에서만 살아 있고 스크립트에서는 적용되지 않는다. 스크립트에는 `ls -l` 처럼 실제 옵션을 적는다 | `bash -c 'll'` → command not found |

---

## 4. 이 문서를 채우는 규칙 (요약 — 원문은 `CLAUDE.md` 9장)

1. **언제**: 사용자의 질문에 답한 직후, 관문 ②·③ 결과가 나온 직후, 커밋 직전. 커밋 직전 갱신이 없으면 커밋하지 않는다.
2. **무엇을**: 그 단계의 개발 체크 상태 · 검토 체크 결과(통과/실패/미검증) · 자기 공격 체크 결과 · 커밋 해시 · 학습 기록(어려움 / 궁금 / 해소 / 결정 / 요청).
3. **어떻게**: 학습 기록은 `날짜 | 구분 | 내용(사용자 말의 요지) | 해소·근거(답과 문서 위치)` 한 줄. 답을 다시 쓰지 않고 **어디에 근거가 있는지** 가리킨다.
4. **기초 질문**(터미널·리눅스·git 등)은 3장에. 단계와 무관해도 버리지 않는다.
5. 보안 점검 **방식**이 바뀌면 1장을 함께 고친다. 판정 근거는 언제나 `SECURITY.md` 7장 이력이며 이 문서는 그 요약이다.
