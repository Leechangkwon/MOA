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
| 7 | SSRF 방어 — `POST /api/files/fetch` + `src/Fetch.php` (S-05 13개 조건), `php8.1-curl` 설치 | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-6 통과) | ⚠ 일괄 수행 대기 | 7단계 커밋 (2026-09-11) |
| 8 | 감사 로그 조회 화면(Admin) — `audit.view` 기록, 육하원칙 6필터 | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-12·T-13 통과) | ⚠ 일괄 수행 대기 | 8단계 커밋 (2026-09-11) |
| 9 | 운영 — 백업·복구·정합성 검사·경보(AL-1 외 5종)·서버 기준선 점검·해시 체인, README 완성 (원격 로그·WAL·방화벽 적용은 미적용) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-15·T-17~T-20 통과, 기준선 주의 6) | ⚠ 일괄 수행 대기 | 9단계 커밋 (2026-09-11) |
| 10 | 계정 관리(F-10)·휴지통 복구(F-11)·Guest 만료(R-12)·비밀번호 변경·2단계 인증 TOTP(R-05) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-22 통과, 재지시 1회) | ⚠ 일괄 수행 대기 | 10단계 커밋 (2026-09-11) |
| 11 | 최초 로그인 강제 절차 — 임시 비밀번호·최초 로그인 비밀번호 변경 강제·2단계 인증 필수(F-16, 사용자 결정 2026-09-11) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (관문 우회 10종 403, 검토 계정 5개 이행 완료) | ⚠ 대기 — 시드 계정 첫 로그인 | 11단계 커밋 |
| **12** | **12-1** 공통 셸·기능별 화면 분리·디자인(F-20) · **12-2** 미리보기(F-18) · **12-3** 조직·권한 관리(F-19) — 사용자 요청 2026-09-12 | 🔧 진행 | ⏳ | ⏳ | ⏳ | — |

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

### 7단계 — SSRF 방어 ✅ (S-05, F-14 · T-6) — 2026-09-11 커밋

**개발 사항**
- [x] `php8.1-curl`(배포판 패키지) 설치, `DEPENDENCIES.md` 갱신
- [x] 외부 요청 코드는 `src/Fetch.php` **한 파일**(린트가 다른 곳의 `curl_*` 반려)
- [x] S-05 13개 조건: https·443 만, userinfo·IP 표기·`localhost`·퓌니코드 거부, 허용 목록 **정확 일치**(비면 전부 거부, 와일드카드 금지), DNS 해석 결과 전부 공인 IP(IPv6 매핑·호환 표기 환원), 검사한 IP 로 `CURLOPT_RESOLVE` 고정 + host 를 정규화 값으로 URL 재조립, 리다이렉트 미추적(3xx = 실패), 3s/10s·100MB(헤더+실수신 바이트+사후 크기 3중), 가져온 파일은 업로드와 **같은** `Upload::validatePath`, 응답 비노출(문구 하나), Manager 이상 + 시간당 10회, 임시파일 항상 정리
- [x] 감사 로그: 사유 코드 14종, `source_host`·`source_ip`·`http_status` (URL 전체는 기록 안 함)
- [x] 화면: 서버 `can_fetch` 가 참일 때만 "URL 로 가져오기" 패널

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "7단계 구현 검토")
- [x] 단위 69건(`isPrivate` 28 + `check` 41): 스킴·포트·IP 표기 9종·userinfo 3종·유사 도메인·`%2f@`·`#@`·퓌니코드·와일드카드·공백·길이 → 전부 거부, 끝 점·대문자·`:443` → 정규화 통과
- [x] e2e 11건(임시 계정 `qa_manager02`): 성공 2(internal `matrix`, team1 `same_team`, sha256 일치), 302 `redirect`, 404 `fetch_failed`, `.php` `invalid_file`, 표기 우회 3종 `host_not_allowed`, admin-only 403, team2 403, 11회째 429
- [x] Employee·Guest 403(라우터), CSRF 없음 403, 폼 400, 실패 본문 전부 `{"error":"가져올 수 없습니다"}`, URL 전체 로그 0, 임시 디렉터리 잔여 0
- [x] 검토 뒤 `.env` 허용 목록을 **비움** → 현재 서비스는 전부 거부(Q-10 운영 결정)
- [ ] **미검증** 내부 IP 로 해석되는 도메인(DNS 조작 불가 → 단위로 대체), 100MB 초과 e2e(CLI 로 대체), 브라우저 패널 → 관문 ③

**자기 공격 체크 (사용자 ③)** — manager01 로 (허용 목록이 비어 있으므로 **전부** 거부돼야 정상)
- [ ] 화면 "URL 로 가져오기" 에 `file:///etc/passwd` → "가져올 수 없습니다"
- [ ] `http://raw.githubusercontent.com/git/git/master/README.md`(http) → 같은 문구
- [ ] `https://127.0.0.1/`, `https://169.254.169.254/latest/meta-data`, `https://localhost/`, `https://2130706433/` → 같은 문구
- [ ] `https://raw.githubusercontent.com/git/git/master/README.md`(허용 목록에 없음) → 같은 문구
- [ ] `sudo tail -n 8 /var/log/tbox-v2/audit.log` 에 사유가 `scheme`·`host_not_allowed` 로 구분돼 남고 문장에 "SSRF"·"내부망" 설명이 있음. 화면 문구는 전부 같았음을 확인
- [ ] employee01 로는 패널 자체가 안 보이고, F12 Console 로 `fetch('/api/files/fetch',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':'…'},body:'{"url":"https://a/b.txt","access_level":"internal"}'}).then(r=>r.status)` → `403`

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중) 검토 계정 두 개가 시간당 10회 한도를 자체 시험으로 다 써서 Fable 이 e2e 를 못 돌림 | 속도 제한은 허용·거부를 **모두** 세도록 설계돼 있어(거부 반복으로도 막힘) 의도된 동작. 임시 Manager 계정 `qa_manager02` 를 만들어 시험하고 파일·세션·계정을 삭제(감사 행은 남김). 교훈: 한도가 있는 기능은 구현자와 검토자가 **다른 계정**을 써야 한다 |
| 2026-09-11 | 결정 | 시험 때 넣은 허용 호스트(`raw.githubusercontent.com`, `github.com`)를 남길까? | PRD Q-10 대로 **비움**. 운영자가 필요할 때 정적 파일을 직접 내려주는 호스트만 `.env` 에 추가하고 `php-fpm reload`. Google Drive 류는 리다이렉트를 쓰므로 원리적으로 불가 |
| 2026-09-11 | 해소 | 왜 URL 전체를 감사 로그에 남기지 않나? | URL 경로에 토큰·개인정보가 들어갈 수 있어 host·ip·상태 코드만 남긴다(ARCH 7.5). 조사에 필요한 "어디서 가져왔나"는 host 로 충분 |

### 8단계 — 감사 로그 조회 ✅ (S-07, F-09 · T-12, T-13) — 2026-09-11 커밋

**개발 사항**
- [x] `GET /api/audit`·`GET /audit` — 정책 `audit.read`(Admin 만, Q-8). 다른 역할은 라우터에서 403 + 그 시도가 `authz.route` 로 기록
- [x] 조회 자체를 기록: 핸들러 첫 줄 `audit.view allow` + 적용한 필터(`detail.filters`)
- [x] 육하원칙 필터: 누가(`actor` 이름 또는 `login_ref` 12자), 언제(`from`·`to`), 어디서(`ip`), 무엇을(`target` 부분 일치·`level`), 어떻게(`action` 허용 목록), 왜(`result`·`reason`·`granted_by`). 전부 바인딩, 형식 밖 값은 무시
- [x] 응답은 JSON, 화면은 `textContent` 로만 그림(`summary`·`target_name`·`user_agent` 포함). 20건 페이지, 최신순 고정
- [x] 결정: 404·405 는 기록하지 않음(3단계 관찰 ④ 종결 — 이유는 SECURITY 7장)

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "8단계 구현 검토")
- [x] T-13 Manager·Employee·Guest → 403 + `authz.route` 기록 3건, 미로그인 401, 화면 Employee 403·미로그인 302
- [x] T-12 `result=deny` 조회에 4~7단계 거부 행동 10종 존재, 조회마다 `audit.view` 행 + 필터 기록
- [x] 필터 10종 각 200·첫 행 조건 일치, 잘못된 값 9종 전부 200·오류 문구 0·표 불변, `%`·`_` 는 문자 그대로
- [x] XSS 페이로드 파일명이 JSON 문자열로만, `/audit` HTML 0회, CSP 유지
- [ ] **미검증** 브라우저 표 렌더링·`<details>` 상세 → 관문 ③

**자기 공격 체크 (사용자 ③)**
- [ ] **T-13** employee01 로 `https://changkwon.mso.so/api/audit` → `{"error":"권한이 없습니다"}`(403). 주소창에 `/audit` → 같은 문구
- [ ] **T-12** admin01 로 로그인 → 홈 상단 "감사 로그" 링크(Admin 에게만 보임) → 필터 "결과 = deny" 로 조회 → 방금 employee01 의 시도가 맨 위에 `authz.route … GET /api/audit` 로 보임. 4~7단계에서 본인이 했던 공격(`secret.txt` 다운로드 403, 삭제 403, `.php` 업로드, URL 가져오기)이 전부 `deny` 로 보이는지 확인
- [ ] 필터 "누가 = employee01" 로 좁혀 보고, 표의 "요약" 이 한국어 문장인지, 파일명에 `<img src=x onerror=alert(1)>` 가 있어도 **글자 그대로** 보이고 알림창이 없는지 확인
- [ ] 조회를 마친 뒤 `sudo tail -n 3 /var/log/tbox-v2/audit.log` 에 `audit.view` 행(내가 로그를 본 기록)이 남았는지 확인

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | 로그인 실패 기록은 "누가" 필터(`actor=계정명`)로 왜 안 나오나? | 로그인은 세션이 생기기 **전**이라 `actor_*` 가 비어 있고 대상 계정이 `target_name` 에 있다(ARCH 7.3). 로그인 기록은 "무엇을 = 계정명" 필터로 찾는다. README 조회 요령에 적음 |
| 2026-09-11 | 결정 | 없는 주소(404)·잘못된 메서드(405) 요청도 감사 로그에 남길까? | 남기지 않는다. 외부 ID 가 UUID 라 열거가 비현실적이고, 경로 스캔은 nginx access log 에 90일 남는다. 라우트 매칭 전이라 패턴이 아닌 실제 경로만 남길 수 있어 S-07 "요청 경로" 규칙과 충돌 |

### 9단계 — 운영 ✅ (S-08, S-10, S-11 · T-15, T-17~T-20) — 2026-09-11 커밋

**개발 사항**
- [x] 백업: `backup_db.sh`(pg_dump → 파이프로 gpg AES256, 평문 미저장, 보관 일 7·주 4·월 6)·`backup_files.sh`(tar|gpg + rsync 미러)·`verify_backup.sh`(sha256·복호화 시험, 2회 연속 실패 → AL-8). 읽기 전용 역할 `tbox_backup`, 키 `/etc/tbox-v2/backup.key`(0600 root — **분실 = 백업 무용**, 사본 별도 보관)
- [x] 복구: `restore.sh`(ARCH 11.2 순서, 운영 DB 덮기는 `--yes-production` 필수, 리허설은 별도 DB) + 복구 뒤 `grants.sql` 재적용 절차
- [x] 배치: `purge_trash.php`(30일 휴지통 바이트 삭제, 행은 `purged_at` 로 유지)·`consistency_check.php`(누락·고아·sha256 → AL-6)·`audit_chain.php`(감사 로그 파일 일별 해시 체인)·`alerts.php`(AL-1·2·5·7·10·12, 30분 중복 억제) — `/etc/cron.d/tbox-v2` 7줄
- [x] `check_baseline.sh`(T-17~T-19 읽기 전용 점검)·`deploy/hardening.sh`(ufw·sshd 적용 계획, **기본은 출력만**)
- [x] README: 설치·운영(백업·복구·감시)·감사 로그 보는 법·검토 계정 절
- [ ] **미적용(결정)**: WAL 아카이브(RPO 15분 → 실제 일 1회), 원격 로그 서버·3-2-1 원격 사본(Q-4 미결), 방화벽·SSH 강화 적용(원격 세션 차단 위험 — 사용자가 콘솔에서 `sudo bash deploy/hardening.sh --apply`), 알림 채널(메일·메신저 없음, 로그만)

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "9단계 구현 검토")
- [x] T-15 백업 → 검증 → 파일 바이트 삭제(정합성 exit 2 + AL-6) → `restore.sh --files` → sha256 원본=복구=DB, strict 통과, 다운로드 200. DB 는 별도 DB 로 리허설(표 6·행 수 일치). 소요 5분 21초(Opus)·13초(Fable 재실행)
- [x] purge: 31일 전으로 조작한 시험 행 1건만 삭제·`purged_at`·`file.purge` 기록, 최근 삭제 파일은 유지
- [x] 해시 체인 2회 같은 값·`--verify` 통과·값 변조 시 실패, `audit_cli` 로 `auth.login` 위조 → 거부
- [x] T-20 AL-1 발동 + 30분 중복 억제, AL-2(잠금 423 + 429)·AL-5·AL-8·AL-10 실측, cron 5분 실행 로그
- [x] T-17~T-19 점검 출력: OK 15 · 주의 6(SSH 비밀번호 로그인 허용·root 로그인·MaxAuthTries 6·chrony 없음·PHP EOL·postgres 셸) · 미적용 6(ufw·fail2ban·원격 로그·WAL)
- [ ] **미검증** hardening 적용 후 상태, logrotate 회전 뒤 체인, `--stop-service` 복구

**자기 공격 체크 (사용자 ③)** — 서버 콘솔에서
- [ ] **T-15** `sudo bash scripts/backup_db.sh && sudo bash scripts/backup_files.sh` → `sudo ls -l /var/backups/tbox-v2/db /var/backups/tbox-v2/files` 에 `.gpg`·`.sha256` 생김 → `sudo bash scripts/verify_backup.sh` 통과
- [ ] 파일 하나를 올린 뒤 그 저장 파일을 `sudo rm` 으로 지우고 `sudo php scripts/consistency_check.php` → "불일치 1건" + `alerts.log` 에 AL-6 → `sudo bash scripts/restore.sh --files <최신 파일 이름>` → 다시 검사 → 불일치 0, 다운로드 정상
- [ ] **T-17~T-19** `sudo bash scripts/check_baseline.sh` 를 읽고 "주의" 항목이 왜 남았는지(비밀번호 SSH·ufw 미적용) 이해. 적용할지 결정 — 적용하려면 **콘솔(원격 아님)에서** `sudo bash deploy/hardening.sh --apply`
- [ ] **T-20** employee01 로 admin-only 파일 다운로드를 6회 시도(403) → 5분 안에 `sudo tail -n 2 /var/log/tbox-v2/alerts.log` 에 AL-1 (cron 이 5분마다 돈다. 바로 보려면 `sudo php scripts/alerts.php`)
- [ ] `sudo cat /etc/cron.d/tbox-v2` 로 일정 7줄 확인, `sudo cat /etc/tbox-v2/backup.key` 를 **다른 곳에 사본 보관**(R-08)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 결정 | 문서의 `age`·`restic`·`chrony`·`fail2ban` 이 VM 에 없다 — 설치할까? | 새 패키지 0 원칙(S-10-C)대로 설치하지 않고 있는 도구로 대체: `age`→`gpg`, `restic`→`tar`+`rsync`, `chrony`→`systemd-timesyncd`(동기화 확인됨), `fail2ban`→없음(SSH 차단 불가, 주의로 남김). `DEPENDENCIES.md` 5.3 "쓰지 않기로 한 도구" |
| 2026-09-11 | 결정 | 방화벽(ufw)·SSH 강화를 스크립트가 자동 적용해도 되나? | **하지 않는다.** 원격 SSH 세션에서 규칙을 잘못 걸면 접속이 끊긴다. `hardening.sh` 는 계획만 출력하고 `--apply` 는 사람이 콘솔에서 공개키 로그인을 확인한 뒤 실행 |
| 2026-09-11 | 해소 | (검토 중) `restore.sh` 에 경로를 넘겼더니 "파일 이름으로 지정" 이라며 거부됨 | 산출물은 `/var/backups/tbox-v2/{db,files}/` 안의 **파일 이름만** 받는다(경로 조작 방지). 운영 DB 를 덮는 `--db` 는 `--yes-production` 이 없으면 거부하고 리허설 명령을 알려 준다 |
| 2026-09-11 | 해소 | 복구한 DB 에 앱 계정 권한이 비어 있다 | `pg_restore --no-privileges` 로 뜨기 때문. 운영 DB 를 복구한 뒤에는 `db/grants.sql` 을 다시 실행해야 `tbox_app=ar` 등 최소 권한이 돌아온다 — README 복구 절차에 단계로 추가 |
| 2026-09-11 | 해소 | 경보 AL-1 시험을 다시 했는데 왜 안 뜨나? | 같은 대상에 30분 안에 이미 발동했으면 **중복 억제**로 건너뛴다(`alerts-state.json`). `--dry-run` 출력에 "이미 발동해 건너뛴다" 가 보이면 정상 |

### 10단계 — 계정 관리·휴지통 복구·Guest 만료·비밀번호 변경·2단계 인증 ✅ (F-10, F-11, R-05, R-10, R-12 · T-22) — 2026-09-11 커밋

**개발 사항**
- [x] Admin 전용 `GET/POST /api/users`·`PATCH /api/users/{uid}`·화면 `/users` — 역할 4종 허용 목록, Guest 는 만료일 필수(DB CHECK), **사유(`reason_text`) 필수**, 자기 자신 변경 금지(`self_change`), 마지막 활성 Admin 강등·비활성화 금지(`last_admin`), 역할·팀 변경·비활성화 시 대상의 **모든 세션 즉시 종료**(T-22)
- [x] Guest 만료: 로그인 차단(`user.expired`) + **살아 있던 세션도 다음 요청에서 즉시 끊음**(재지시로 보강)
- [x] 내 비밀번호 변경 `POST /api/me/password` — 현재 비밀번호 확인, 12자 이상, 다른 기기 세션 종료(현재 세션 유지)
- [x] 2단계 인증 TOTP(RFC 6238 자체 구현, 외부 라이브러리 0) — 등록→확정→로그인 시 6자리 코드, ±30초 허용, 틀린 코드도 잠금 카운트, 해제는 비밀번호+코드. 켜는 것은 사용자 선택(Admin 강제는 운영 결정 필요)
- [x] 휴지통 목록·복구 `GET /api/files/trash`·`POST /api/files/{id}/restore` (Admin, 영구 삭제된 파일은 복구 불가)
- [x] 감사 로그: `user.*`·`auth.password_change`·`auth.mfa*`·`file.restore` 문장 틀 29행, 실패 기록(형식 오류·틀린 코드·이미 켜짐) 포함. secret·비밀번호·otp 는 기록 금지

**검토 체크 (Fable ②)** — 2026-09-11 통과, 재지시 1회 (`SECURITY.md` 7장 "10단계 구현 검토")
- [x] 생성·중복·사유 없음·약한 비밀번호·모르는 역할·모르는 키 → 201/400 정확, 비-Admin 3계정 403
- [x] **T-22** 세션 2개 → 비활성화 → 둘 다 즉시 401, sessions 0행, 재로그인 401, 재활성 후 200
- [x] Guest 만료: 로그인 401 + 기록. **1차 실패** 만료 전 세션 200 유지 → 재지시 후 401 + `session_expired` 기록
- [x] 비밀번호 변경: 틀림 400·약함 400·성공 200, 다른 세션 401·현재 유지, 옛 비번 401
- [x] TOTP 전 과정(등록·확정·코드 없이·틀린 코드·±30초·해제·잠금) + 비밀값 로그 유출 0
- [x] 휴지통 복구 200 → 다운로드 200 → 재복구 404, Manager 403. `{num}` 형식 밖 404. 린트 0건
- [ ] **미검증** 브라우저 화면(계정 관리·MFA 폼·휴지통 버튼·로그인 코드 칸), `last_admin` HTTP 왕복(코드 경로만)

**자기 공격 체크 (사용자 ③)**
- [ ] **T-22** admin01 로 `/users` 화면에서 시험 계정(예 `test_emp`, Employee, 개발팀, 비밀번호 10자 이상) 생성 → 다른 브라우저(또는 시크릿 창)에서 그 계정으로 로그인 → admin01 이 "비활성화"(사유 입력) → 다른 브라우저를 새로고침 → 로그인 화면으로 튕김(401). 사유 없이 비활성화를 누르면 거부되는지도 확인
- [ ] admin01 이 자기 자신의 역할을 바꾸려 하면 거부("잘못된 요청입니다")
- [ ] employee01 로 `/users` → "권한이 없습니다"
- [ ] employee01 로 「내 계정」에서 비밀번호 변경 → 다른 브라우저의 employee01 세션이 끊김. 옛 비밀번호로 로그인 불가
- [ ] employee01 로 2단계 인증 켜기: 화면의 secret 을 인증 앱(Google Authenticator 등)에 수동 입력 → 코드로 확정 → 로그아웃 → 코드 없이 로그인 시도 → 실패 → 코드 넣고 성공. 끝나면 해제해도 됨
- [ ] employee01 로 파일 하나 삭제 → admin01 의 휴지통에서 복구 → employee01 목록에 다시 보임
- [ ] `sudo tail -n 20 /var/log/tbox-v2/audit.log` 에서 위 행동들이 한국어 문장으로 남았는지, 비밀번호·secret 이 **어디에도 없는지** 확인
- [ ] 자기 공격을 모두 마친 뒤: 시드 계정 비밀번호를 각자 바꾸고 `sudo rm /etc/tbox-v2/seed-accounts.txt` (R-33). 검토 계정 `qa_*` 5개는 admin01 이 비활성화

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토에서 발견) Guest 계정이 만료돼도 그 전에 로그인한 세션은 계속 쓸 수 있었다 | 로그인 시점만 검사했기 때문. 비활성화와 같은 자리(`Auth::currentUser()`)에서 만료도 검사해 세션을 즉시 지우고 기록(`user.expired|session_expired`)하도록 재지시. "만료·비활성·잠금 같은 계정 상태는 **매 요청** 다시 읽어야 한다"는 S-01 원칙의 연장 |
| 2026-09-11 | 해소 | 검토 스크립트가 두 번 연속 Opus 시험 계정과 이름이 겹쳐 실패(`t10_emp`·`t10c_*`) | 앱 문제가 아님. 구현자와 검토자가 **서로 다른 접두**의 시험 계정을 쓰기로 함(검토는 `t10d_*`). 7단계 속도 제한 충돌과 같은 교훈 |
| 2026-09-11 | 결정 | 2단계 인증을 Admin·Manager 에게 강제할까? | 이번 단계는 **선택 기능**으로 구현. 강제하려면 PRD F-01·S-09-4 개정 + 시드 admin01 의 MFA 등록 절차가 먼저 필요(등록 없이 강제하면 관리자가 잠긴다). R-05 는 ◐ 유지 |
| 2026-09-11 | 결정 | 시험 계정 `t10*` 10개를 지울까? | 계정 삭제 API 는 없다(감사 로그가 `actor_id` 로 가리키므로 행을 지우면 "누가"가 사라짐). **비활성 상태로 둔다** — 운영 배포에는 이 DB 를 쓰지 않는다 |

---

### 11단계 — 최초 로그인 강제 절차: 임시 비밀번호·비밀번호 변경 강제·2단계 인증 필수 ✅ (F-16, R-05, R-33, R-39) — 사용자 결정·구현·검토 2026-09-11

> 사용자 요구(원문 요지): "2차 인증 여부는 1차 비밀번호 뒤에 설정을 요구하고 사용자가 지정하게 한다. 관리자가 만든 아이디는 임시 비밀번호로 주고 최초 로그인 때 비밀번호 변경과 2차 인증 설정을 요구한다. 패치 이후 시점부터 최초 로그인으로 적용(과거 데이터 삭제 아님)."
> 해석(Fable, 보고 후 확정): "2차 비밀번호" 는 **현재의 TOTP 2단계 인증**으로 구현한다(사용자가 자기 인증 앱에 직접 등록 = "사용자가 지정"). 사람이 외우는 두 번째 비밀번호는 첫 번째와 같은 종류(아는 것)라 보안상 한 겹이 아니어서 채택하지 않았다. "패치 이후 최초 로그인" 은 **기존 활성 계정 전부의 다음 로그인**으로 해석해 마이그레이션이 플래그를 켠다.

**개발 사항**
- [x] DB: `users.must_change_password`·`users.mfa_setup_required`(BOOLEAN NOT NULL DEFAULT false)·`users.password_changed_at`. 마이그레이션이 기존 활성 계정 전부에 `must_change_password=true`, `mfa_setup_required = NOT mfa_enabled`
- [x] 계정 생성: Admin 은 비밀번호를 보내지 않는다. 서버가 임시 비밀번호(16자, `0O1lI` 제외)를 만들어 201 에 **한 번만** 돌려준다. 감사에는 발급 사실만
- [x] 로그인 200 응답·`/api/me` 에 `next`(`password_change`·`mfa_setup`·null)
- [x] 라우터 관문: 플래그가 켜진 세션은 허용 목록 밖 요청 **403 + next** (`auth.gate deny …` 기록)
- [x] 비밀번호 변경 성공 → `must_change_password` 해제(같은 값 400). TOTP 확정 → `mfa_setup_required` 해제. TOTP 해제 → 다시 켜짐(필수)
- [x] Admin: `POST /api/users/{uid}/password/reset`·`/mfa/reset` (사유 필수, 자기 자신 금지, 대상 세션 전부 종료)
- [x] 화면: 홈이 `next` 를 보고 강제 화면만 표시(비밀번호 변경 → 2단계 인증 등록 → 정상 홈). 계정 관리 화면에서 임시 비밀번호 1회 표시·재발급·2단계 초기화 버튼
- [x] 정적 자원 캐시: `app.js`·`app.css` 에 버전 쿼리(`?v=파일수정시각`) — 보정이 브라우저 캐시(7일)에 갇히지 않게
- [x] (부록, F-17) 오프라인 2단계 코드 생성기 `public/assets/totp-generator.html` — 외부 스크립트·네트워크 0, base32/`otpauth://` 입력, RFC 6238 자체 점검 버튼, 저장 선택·지우기, R-40 경고. 로컬 검토 사이트 `http://localhost:8082/assets/totp-generator.html` 로 내려받아 PC 에 저장
- [x] (부록, F-17-2) 생성기 저장을 **문구로 잠근 암호문**으로(PBKDF2-SHA256·AES-GCM, 평문 저장 제거, 잠금·해제 왕복 자체 점검) + 비밀 키 **4자 묶음 표시**(t-box 등록 화면·생성기)

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 「11단계 구현 검토」)
- [x] 마이그레이션 뒤 기존 계정 `must_change_password=true`; 검토 계정으로 로그인 → `next=password_change`, `/api/files` 403 + `auth.gate deny`
- [x] 비밀번호 변경(같은 값 400 → 새 값 200) → `next=mfa_setup`, `/api/files` 여전히 403, `enroll`·`confirm` 은 200 → `next=null` → `/api/files` 200
- [x] Admin 생성: `password` 키 → 400, 없이 → 201 + `temp_password` 16자, 감사 detail 에 값 0건, 새 계정 로그인 → 절차 강제
- [x] 재발급·초기화: 대상 세션 2개 모두 401, 자기 자신 400 `self_change`, 사유 없음 400, Manager 403
- [x] TOTP 해제 → 다음 요청 403 `mfa_setup`; 린트 0, `\dp` 불변
- [x] 검토 계정 5개는 절차를 마치고 새 비밀번호를 `/etc/tbox-v2/seed-accounts.txt`(qa 줄)에, TOTP 비밀을 `/etc/tbox-v2/qa-totp.txt`(root 0600)에 기록

**자기 공격 체크 (사용자 ③)**
- [ ] (F-17) `http://localhost:8082/assets/totp-generator.html` 을 열어 「다른 이름으로 저장」 → PC 에서 파일로 열기 → 「자체 점검 실행」 이 **6/6 통과** 인지 → 홈 「2단계 인증 켜기」의 비밀 키를 붙여 나온 6자리로 확정이 되는지
- [ ] (F-17-2) 생성기 강력 새로고침(Ctrl+F5) → 「자체 점검 실행」 **9/9 통과** → 비밀 키 붙이고 4자 묶음이 t-box 화면과 같은지 대조 → 문구를 **두 번 같게** 넣어 「잠가 저장」(다르게 넣으면 빨간 안내로 거부되는지도) → 창을 닫고 다시 열어 **문구만으로** 코드가 나오는지 → 틀린 문구는 거부되는지 → 개발자도구(F12) Application → Local Storage 에 base32 평문이 **없는지**
- [ ] 시드 계정(`admin01` 등)으로 로그인 → 비밀번호 변경 화면만 보이는지 → 변경 → 2단계 인증 등록 화면 → 인증 앱 등록·확정 → 정상 홈
- [ ] 절차 중에 주소창으로 `/api/files` 를 열면 403 과 안내 문구
- [ ] `admin01` 로 계정 하나 생성 → 임시 비밀번호가 **한 번만** 보이는지 → 그 계정으로 다른 브라우저 로그인 → 절차 강제
- [ ] 임시 비밀번호 재발급 → 대상 브라우저 즉시 로그아웃
- [ ] 감사 로그에 임시 비밀번호·secret 이 **어디에도 없는지**

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중 Fable 실수) 검토 스크립트가 비밀번호 변경 API 필드 이름을 잘못 써(`current_password`/`new_password`, 실제는 `current`/`new`) 변경이 전부 400 인데, 스크립트 끝의 "새 비밀번호 파일 설치" 는 그대로 실행돼 `seed-accounts.txt` 의 검토 계정 5줄이 **DB 와 다른 값**으로 덮였다 | 원래 값은 하위 에이전트(Opus) 기록에 남아 있어 복원 → 5계정 로그인 200 으로 검증 → 스크립트를 "절차 완료(files 200)를 확인한 계정만 갱신, 5개 모두 아니면 설치 중단" 으로 고쳐 재실행. 교훈은 8.4-10 과 같다: **설치·기록은 성공을 확인한 뒤에만.** 시드 계정(admin01 등) 줄은 건드리지 않았다 |
| 2026-09-11 | 결정 | 10단계의 "2단계 인증은 선택" 을 뒤집어 **필수**로 | 사용자 결정. 서버 관문(403)이 막으므로 화면 우회 불가 |
| 2026-09-11 | 결정 | 비밀번호 최소 길이 12자 → **10자** (사용자 요청) | PRD S-09-4·8장, ARCH 7.4 문장 틀, `Auth::MIN_PASSWORD_CHARS`, 화면 `minlength`·라벨, README 를 함께 바꿈(Opus 구현·Fable 검증). 근거: 2단계 인증이 전 역할 필수가 되어 비밀번호 단독 강도 요구를 낮출 여지. 참고 기준 NIST SP 800-63B 는 최소 8자 + 길이 상한 완화 + 흔한 비밀번호 차단을 권고 — "흔한 비밀번호 목록 차단" 은 미구현(후속 후보) |
| 2026-09-11 | 궁금 | "2차 인증 앱, 공짜는 없어? 아니면 우리가 따로 만들던가" | 안내문에 유료 제품(1Password)이 섞여 오해를 줌. **무료 앱이 표준**: Google Authenticator·Microsoft Authenticator(무료, 가입 불필요), Aegis(Android)·FreeOTP(iOS·Android, 둘 다 오픈소스). TOTP 는 공개 표준(RFC 6238)이라 어떤 앱이든 같은 코드가 나오고, 등록 뒤에는 인터넷이 필요 없다. 우리가 만드는 것도 가능(서버의 `scripts/totp_code.php` 가 이미 같은 계산을 함)하지만 **로그인하는 기기와 다른 기기**에 있어야 두 번째 겹이 되므로, 스마트폰 앱을 권장. 스마트폰이 없을 때의 대안(PC 에 두는 오프라인 생성기)은 사용자 결정 사항 | 안내문에서 1Password 제거, 무료 앱·오프라인 동작 명시(README·홈 화면) |
| 2026-09-11 | 결정 | "우리가 만들자" — 오프라인 코드 생성기 자체 제작 | PRD F-17·SECURITY R-40 으로 선반영. 스마트폰 앱이 더 강하다는 점(기기 분리)은 그대로 안내하고, 생성기는 **대안**으로 위치. 서버에 JS 실행 환경이 없어 자동 시험 불가 → 파일 안 자체 점검 버튼(RFC 6238 부록 B 시험값)으로 사용자가 브라우저에서 PASS 확인 |
| 2026-09-11 | 해소 | (사용자 관찰) "생성기가 HTML 문서처럼만 남고 작동 안 함" | 서버 전역 CSP(`script-src 'self'`)가 `http://localhost:8082/assets/…` 로 열었을 때 파일 안 인라인 스크립트를 막았다(헤더 CSP 와 meta CSP 중 더 엄격한 쪽이 이긴다). PC 에 저장해 파일로 열면 헤더가 없어 동작했겠지만, 미리 보기가 죽어 있으면 자체 점검을 못 한다. 조치: 두 사이트 설정에 이 파일 **하나만** 인라인 허용하는 `location =` 블록(외부 참조는 `default-src 'none'` 으로 더 좁힘, 보안 헤더 전부 재선언) → `nginx -t`·reload → 다른 자원의 CSP 는 그대로 확인 | 교훈: 정적 파일도 서버 헤더의 영향을 받는다 — "파일 하나로 완결" 이라도 배포 경로의 정책을 같이 봐야 한다 |
| 2026-09-11 | 어려움 | "admin01 비밀번호가 안 먹혀 — 아까 바꾼 게 적용 안 됐거나 내가 까먹었거나" | DB 실측: admin01 은 20:02:47 사용자 IP 로 로그인 성공 → 20:02:55 비밀번호 변경 **정상 적용**(`password_changed_at` 기록, 변경 대기 해제). 그 뒤 시드 파일의 옛 값·기억이 다른 값으로 5회 실패 → 21:13 까지 잠금. 즉 앱 결함이 아니라 새 비밀번호 유실. Admin 이 admin01 하나뿐이라 앱 안 재발급 불가 → 서버에서 임시 비밀번호 재발급 스크립트(`reset_admin01.sh`: 해시 갱신·변경 대기 on·잠금 해제·시드 파일 admin01 줄 갱신)를 **사용자가 실행**(운영 데이터는 AI 가 손대지 않음). 앱 감사 로그에는 남지 않는 예외 경로임을 SECURITY 8.4 에 기록 | 교훈: 첫 로그인에서 정한 비밀번호는 그 자리에서 비밀번호 관리 도구·종이에 적는다. 운영에서는 **Admin 을 2명 이상** 두어 한 명이 잠겨도 앱 안에서 재발급할 수 있게 한다(후속 결정 후보) |
| 2026-09-11 | 제안 | "비밀 키가 너무 길어 번거롭다. `qaredi` 같은 짧은 문구를 넣으면 SHA-256 으로 해시해 키를 만드는 방식은 어떤가" | **문구에서 비밀 키를 만드는 방식은 채택 불가.** ① SHA-256 은 엔트로피를 늘리지 못한다 — `qaredi`(소문자 6자 ≈ 28비트)에서 나온 키의 강도는 그 문구의 강도다 ② 코드를 한 번만 훔쳐 보면(어깨너머·요청 가로채기) 문구를 **오프라인 전수 대입**으로 찾아 영구히 코드를 만들 수 있다(5회 잠금이 소용없음) ③ "아는 것" 하나가 더 생길 뿐 "가진 것" 겹이 사라져 2단계 인증의 의미가 없어진다 ④ 같은 문구를 다른 서비스에도 쓰면 한 곳의 유출이 전체로 번진다. **대신 번거로움을 줄이는 길**: ⓐ 생성기에 **문구로 잠근 저장** — 무작위 비밀 키는 그대로 두고, 그것을 문구(`qaredi`)에서 PBKDF2-SHA256 으로 만든 키로 AES-GCM 암호화해 PC 에 저장, 쓸 때마다 문구만 입력(사용자 문장의 "암호화" 해석에 부합, WebCrypto 만으로 가능) ⓑ 비밀 키를 4자씩 띄어 표시(앱은 공백 허용) ⓒ 스마트폰용 QR 코드(외부 라이브러리 없이 자체 인코더 — 분량 큼, 후속) ⓓ PC 생성기는 원래 복사·붙이기라 타이핑은 등록 때 한 번뿐 | Fable 권고: ⓐ+ⓑ 즉시, ⓒ 후속. 결정 대기 |
| 2026-09-11 | 궁금 | "로그인 창에 2차 비밀번호 입력이 있다" | 서버가 보내는 HTML 은 칸이 `hidden` 이고 공개 호스트에서도 확인됨. 브라우저가 **7일 캐시된 옛 app.js** 를 쓰고 있을 가능성 → Ctrl+F5(강력 새로고침). 재발 방지로 자원 버전 쿼리 추가(개발 사항) |
| 2026-09-11 | 궁금 | "페이지가 안 열린다 — 아직 작업 중이라서?" (Opus 구현 도중) | 두 가지가 겹쳤다. ① **10초 자체 장애(19:21:58~19:22:08 KST)**: Opus 가 라우트 표에 새 핸들러 2개를 먼저 적고 파일은 뒤에 만들어, 그 사이 라우터의 "핸들러 파일 없음" 검사가 모든 요청을 거부(`app.log` 34건). 이 서버는 **작업 트리를 그대로 서비스**하므로 편집 = 배포다. ② 그 뒤로는 서버 안(nginx·PHP·DB)·프록시→백엔드 경로(`https://192.168.104.100`, Host 헤더) 모두 200 인데, 사용자 브라우저의 요청은 18:47:47 이후 **한 건도 도착하지 않았고** 공개 IP `112.220.89.250` 은 80·443 모두 연결 불가 → 공개 입구(강사 프록시의 바깥쪽·DNS·방화벽) 문제로 판단. 같은 IP 를 쓰는 다른 수강생 주소로 교차 확인 가능 | `sudo tail /var/log/tbox-v2/app.log` · `sudo tail /var/log/nginx/tbox-v2.access.log`(내 IP 가 있나) · `curl -sk --resolve changkwon.mso.so:443:192.168.104.100 https://changkwon.mso.so/login -o /dev/null -w "%{http_code}"`. 재발 방지 후보: 구현은 별도 체크아웃에서 하고 검토 뒤 전환(`deploy/switch-site.sh` 활용) — 다음 결정 |

---

### 12단계 — 화면 재구성·디자인 · 미리보기 · 조직·권한 관리 🔧 (F-18, F-19, F-20 · T-24~T-27) — 사용자 요청 2026-09-12

> 사용자 요구(원문 요지, 2026-09-12): "push 해줘. 파일 목록에 다운로드/삭제/수정이 있는데 **미리보기**도 만들어 줘 — 파일 형식에 따라 해당 파일을 조회하여 어떤 내용을 가진 파일인지 확인이 필요해. **클로드 디자인**을 활용해서 페이지의 디자인 UI/UX 도 모던하게 개선해 줘. 하단에 내 계정, 휴지통 삭제된 파일 복구 등이 있는데 **각 기능별로 정리해서 접근 방식**을 정리해 줘 — 현재는 1페이지에 모든 기능이 다 나오고 있는데 파일 관련이면 파일로, 계정 관련이면 환경 설정으로. 또한 **조직 권한 관리 기능**도 추가해 줘."
> 해석(Fable): 세 묶음으로 나눠 차례로 간다 — **12-1** 공통 셸 + 기능별 페이지 분리 + 디자인(F-20) → **12-2** 미리보기(F-18) → **12-3** 조직·권한 관리(F-19). 각 묶음마다 ① Opus 구현 → ② Fable 실행 검토 → ④ 커밋, 관문 ③(사용자)은 12-3 뒤 일괄. **"조직 권한 관리"** 는 팀(조직) 생성·변경·비활성화 + 구성원 배정 + 권한 매트릭스 **조회**로 해석했다. 매트릭스 **편집**은 PRD 9장·ARCH 6.2-1 의 "앱 계정 SELECT 만" 결정과 충돌하므로 넣지 않고 PRD **Q-12** 로 남겼다(SECURITY R-43). 사용자가 편집을 원하면 Q-12 결정 뒤 별도 단계.
> 원격 push: 사용자 지시("push 해줘")로 2026-09-12 `6f4d8fe..75c03b8` 49 커밋을 `origin/main` 에 올렸다(Q-9 의 "push 는 결정 전 보류" 는 이 지시로 해소).

**개발 사항 — 12-1 공통 셸·화면 분리·디자인 (F-20)**
- [ ] 디자인 캔버스(Claude Design): 로그인 · 파일(목록+미리보기 모달) · 설정 · 조직·권한 4 아트보드. 토큰(색·간격·서체 스택·모서리)을 `app.css` `:root` 로 옮긴다. 외부 폰트·CDN 0 (CSP `self`)
- [ ] `src/views/shell_top.php`·`shell_bottom.php`: 좌측 내비(파일·휴지통·설정·관리[계정 관리·조직·권한·감사 로그]) + 사용자 칩 + 로그아웃. 동적 값은 사용자명·역할·활성 메뉴 키 3개, 전부 `htmlspecialchars`. 내비 항목은 기본 `hidden`, `/api/me` 의 `can_*` 로만 표시
- [ ] 페이지 분리: `/`(파일 — 목록·검색·올리기 패널·URL 가져오기 패널·강제 절차), `/trash`(`role:Admin`), `/settings`(`authenticated` — 내 정보·비밀번호·2단계 인증), 기존 `/users`·`/audit` 는 셸만 적용
- [ ] `public/index.php`: 라우트 `/trash`·`/settings` 추가, 강제 절차(F-16) 중 `/` 외 **화면** 요청은 302 → `/` (API 는 기존 403+next)
- [ ] `me.php`: `can_view_trash`(Admin)·`team_name` 추가 (`can_manage_org` 는 12-3)
- [ ] `app.js`: `<body data-page>` 로 페이지별 init, 공통 `initShell()`(내비 표시·로그아웃·강제 절차 리다이렉트). 기능 코드는 이동만, 규칙 변경 0. innerHTML 0
- [ ] `app.css` 전면 교체(토큰·레이아웃·표·모달·폼·버튼·상태 배지), 반응형(≥960px 사이드바 / 미만 상단 바+접이식)
- [ ] README 「화면」절 갱신

**개발 사항 — 12-2 미리보기 (F-18)**
- [ ] `php8.1-zip` 설치 + `deploy/DEPENDENCIES.md` 3장 실측 행 (S-10-C: 배포판 패키지)
- [ ] `src/Preview.php`: `kindFor(ext)`, 갈래별 추출(`text`·`table`·`archive`·`office`), 상한 상수(ARCH 5.8 표), XML 파서 미사용
- [ ] `handlers/files_preview.php`(JSON) · `files_preview_raw.php`(image·pdf inline + `CSP: sandbox` + `nosniff` + `no-store`), 라우트 2개(정책 `file.read`)
- [ ] `files_list.php` 행에 `preview_kind`
- [ ] `Audit.php` 문장 틀 `file.preview|…` 7개, detail 키 `preview_kind`·`part` 등록
- [ ] 화면: 행 「미리보기」 버튼 → `<dialog>` 모달(머리: 이름·크기·등급 / 본문: 갈래별 / 발: 다운로드·새 탭에서 열기(pdf)·닫기). `src` 는 `/api/files/` 접두 검사
- [ ] `scripts/security_lint.sh` 0건

**개발 사항 — 12-3 조직·권한 관리 (F-19)**
- [ ] 마이그레이션 `db/migrations/2026-09-12_teams_active.sql`(`is_active`·`created_at`·`updated_at`·이름 길이 CHECK, 멱등) + `schema.sql` 동기화
- [ ] `Authz.php`: 정책 `org.manage` + `canManageOrg`(Admin) — `KNOWN_POLICIES`·`authorize()`
- [ ] 핸들러: `org_page.php`(`/org`), `org_teams.php`(GET), `org_team_create.php`(POST), `org_team_update.php`(PATCH), `org_team_members.php`(GET), `org_permissions.php`(GET, 읽기 전용) — 라우트 6개
- [ ] 비활성 팀 효과: `teams.php` 활성만, `users_create`·`users_update`·`files_upload`·`files_fetch`·`files_update` 에서 `team_inactive` 400
- [ ] `Audit.php` 문장 틀 `team.create|…`·`team.update|…` 8개
- [ ] `me.php`: `can_manage_org`; 셸 내비 「조직·권한」 표시
- [ ] 화면 `/org`: 팀 카드 · 팀 생성/이름 변경/비활성화(사유 필수) · 구성원 표 + 배정(기존 `PATCH /api/users/{uid}` 호출, 사유 필수) · 권한 매트릭스 표(읽기 전용 + "편집 불가 — 이유")
- [ ] `grants.sql` 불변 확인(`permissions` SELECT 만), `\dp` 대조

**검토 체크 (Fable ②)** — 묶음마다
- [ ] 12-1: T-26 (Employee 세션 `/trash`·`/users`·`/org`·`/audit` 403, `/settings` 200; 강제 절차 세션 `/settings` 302), 기존 T-1·T-4·T-14 재확인(기능 이동 뒤 회귀), 린트 0
- [ ] 12-2: T-24 (권한 = 읽기), T-25 (`<img onerror>` txt/csv/md 글자 그대로, raw 헤더 `sandbox`·`nosniff`, txt raw 400), zip/docx 추출 정상·손상 파일 `parse_failed`, 감사 `file.preview` 행·detail 에 내용 0
- [ ] 12-3: T-27 (Manager 403+감사, 사유 없음 400, 중복 409, 구성원 있는 팀 비활성화 400, 비활성 팀 업로드·배정 400, before/after 기록), `\dp` 로 `permissions` SELECT-only 불변

**자기 공격 체크 (사용자 ③)** — 12-3 커밋 뒤
- [ ] employee01 로 로그인 → 개발자도구로 내비의 `hidden` 을 지워 「휴지통」「조직·권한」「감사 로그」를 눌러 본다 → 전부 403 화면, 감사 로그에 `deny`
- [ ] employee01 로 다른 팀 team 파일의 `/api/files/{id}/preview` 를 주소창에 → 403 (목록에 없는 파일의 id 는 admin01 화면에서 복사)
- [ ] `<img src=x onerror=alert(1)>` 한 줄을 넣은 `.txt`·`.csv`·`.md` 를 올려 미리보기 → 글자 그대로, 알림 없음
- [ ] PDF 미리보기가 모달 안에서 보이는지(브라우저 실측). 안 보이면 「새 탭에서 열기」로 열리는지 — 결과를 학습 기록에 적는다(R-41 실측)
- [ ] 개발자도구 Network 에서 `/preview/raw` 응답 헤더에 `Content-Security-Policy: sandbox` 와 `X-Content-Type-Options: nosniff` 가 있는지
- [ ] admin01 로 팀 만들기(사유 없이 → 거부) → 이름 변경 → 구성원 있는 채 비활성화(거부) → 재배정 후 비활성화 → 그 팀으로 업로드 시도(거부) → 감사 로그에서 `team.update` 의 이전값→새값 확인
- [ ] manager01 로 `POST /api/org/teams` (curl) → 403, 감사 로그에 남는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-12 | 궁금 | "현재 tbox2 의 파일 목록의 출처에 대해 조사해 줘" → "아하, 우리 개발할 때 쓴 파일들이구나?" | 87개 파일 전부가 이 저장소 커밋 이력 안에서 생겼다: 54 커밋 모두 author=committer=사용자 1명, 작업 트리=HEAD(mtime 일치), 미추적은 `.env` 뿐, v1(`/var/www/html`)과 이름이 겹치는 5개 파일도 내용은 전부 다름(복사 0). 확인 명령: `git log --diff-filter=A --format=%h -- <파일>`, `git status --porcelain --ignored`, `git hash-object` 비교 |
| 2026-09-12 | 결정 | "그럼 push 해줘" — 원격에 49 커밋이 밀려 있어 서버 디스크가 유일한 사본이던 상태 | `git push origin main` → `6f4d8fe..75c03b8`. 이후 단계 커밋마다 push (CLAUDE.md 6장의 "Q-9 결정 전 보류" 는 이 지시로 해소) |
| 2026-09-12 | 요청 | 미리보기 · Claude Design 으로 디자인 개선 · 기능별 화면 분리(파일/설정) · 조직 권한 관리 | PRD F-18·F-19·F-20, ARCH 5.8·5.9, SECURITY R-41~R-44, 이 절. 순서 12-1 → 12-2 → 12-3 |
| 2026-09-12 | 결정(가정) | "조직 권한 관리" 에서 **권한 매트릭스 편집은 넣지 않음** — 기존 "앱 계정은 `permissions` SELECT 만" 결정과 충돌 | Fable 가정으로 진행, PRD Q-12 로 남김. 사용자가 편집을 원하면 Q-12 결정 후 별도 설계(R-43) |
| 2026-09-12 | 관찰 | 서버 디스크 사용 96% (남은 공간 약 530MB) — 백업·덤프가 쌓이는 중 | `df -h /`. 미리보기용 `php8.1-zip` 은 수백 KB 라 문제없지만, 백업 보존 주기·`/var/backups/tbox-v2` 정리는 사용자 결정 필요(운영 항목) |

---

## 2-1. 10단계 뒤 일괄 자기 공격 — 진행 순서 (관문 ③, 사용자)

일괄 진행 모드에서는 4~10단계의 관문 ③을 한 번에 수행한다. **각 단계 절의 "자기 공격 체크" 를 위에서부터 차례로** 하되, 아래 순서가 효율적이다(계정 전환 최소화). 시드 계정 비밀번호: `sudo cat /etc/tbox-v2/seed-accounts.txt`.

| 순서 | 계정 | 할 일 (단계 절 참조) | 걸리는 시간 |
|---|---|---|---|
| 1 | employee01 | 4단계 T-2·T-3·T-1 (목록에 admin-only 없음, 직접 다운로드 403, 콘솔 삭제 403) → 3단계 캡처와 비교 | 10분 |
| 2 | employee01 | 5단계 T-4 XSS 업로드·T-5 검색 인젝션·T-14 토큰 없는 삭제·F-08 등급 하향 거부 | 10분 |
| 3 | employee01 | 6단계 T-7 `.php`·T-8 위장 `.pdf`·이중 확장자·T-10 저장 경로 URL | 10분 |
| 4 | manager01 | 7단계 URL 가져오기 페이로드 6종(허용 목록이 비어 전부 거부) | 5분 |
| 5 | admin01 | 8단계 감사 로그 화면에서 위 1~4 의 시도를 필터로 찾기, `audit.view` 기록 확인 | 10분 |
| 6 | (서버) | 9단계 백업 → 파일 삭제 → 복구 → 정합성, `check_baseline.sh`, 경보 로그 확인 | 15분 |
| 7 | admin01 + 새 계정 | 10단계 계정 생성·비활성화 즉시 401(T-22), 비밀번호 변경, 2단계 인증 켜기·로그인, 휴지통 복구 | 15분 |
| 8 | — | 각 단계 절의 체크박스를 채우고, 막히지 않은 항목이 있으면 그 단계 "학습 기록" 에 적어 보고 → Fable 이 `SECURITY.md` 7장에 결과 행 추가(실패 시 보정 커밋) | — |

> 결과를 알릴 때는 "N단계 통과" 또는 "N단계 X 항목이 안 막힘 — 응답 원문 …" 처럼 **단계 번호와 원문**을 함께 적으면 기록이 정확해진다.

### 2-2. 관문 ③ 진행 기록 (사용자 피드백과 조치)

| 날짜 | 사용자 관찰 (요지) | 판정 | 조치·근거 |
|---|---|---|---|
| 2026-09-11 | ① "2단계(로그인) — 로그인하지 않아도 다음 페이지로 접근됨" | **재현 안 됨(서버)** — 쿠키 없이 `/`·`/audit`·`/users` → 302 로그인 화면, `/api/*` → 401 실측. 같은 시각 사용자 브라우저에 `employee01` 세션(05:59 로그인, 유휴 30분·최대 8시간)이 살아 있었음 | 브라우저가 **이미 로그인된 세션 쿠키**를 갖고 있으면 로그인 화면을 건너뛰는 것이 정상. 확인 방법: 로그아웃 버튼을 누르거나 **시크릿 창**에서 `https://changkwon.mso.so/` 접속 → 로그인 화면이어야 함. 그래도 통과되면 Network 탭의 요청 헤더에 `Cookie: tbox_session=` 이 있는지 보고 알려 주기 |
| 2026-09-11 | ② "페이지 제목이 '재구축 — 10단계' 이고 6단계·7단계 설명이 나옴" | **화면 문구 문제(맞음)** — 단계별 안내문이 누적돼 서비스 화면이 개발 일지처럼 보임 | 제목에서 단계 번호 제거, 안내문을 사용자용 한 상자로 통합(보정 지시 → Opus) |
| 2026-09-11 | ③ "설명·접근 레벨·팀을 정하지 않아도 올리기가 진행됨" | **부분 맞음** — 설명은 PRD 상 선택 항목(정상). 접근 레벨은 선택 상자 첫 값(`public`)이 자동 선택돼 "고르지 않아도" 보내지고 있었음 → **PRD 4장 "반드시 고른다, 기본값은 team" 과 어긋남**. 팀은 레벨이 team 일 때만 필요(정상)하나 기본 선택이 본인 팀이 아니었음 | 레벨 상자에 "선택하세요" 자리표시 + 기본값 team(팀이 있는 사용자) + `required`, 팀 상자는 본인 팀 기본 선택, 설명 라벨에 "(선택)" 표시(보정 지시 → Opus). 서버 쪽은 이미 레벨 없으면 400, 남의 팀이면 403 |
| 2026-09-11 | ④ "내 계정 비밀번호를 바꾸면 다른 기기의 세션이 모두 끊김" | **의도된 동작** (PRD S-09-2, SECURITY R-10) | 비밀번호를 바꾸는 이유는 대개 "유출 의심"이므로, 훔친 세션이 살아 있으면 비밀번호 변경이 무의미함. 지금 쓰는 기기(현재 세션)만 유지하고 나머지는 끊는다. 화면 안내문에 이 설명이 있는지 확인(보정 지시에 포함) |
| 2026-09-11 | ⑤ "2단계 인증 상태를 확인한다고 하나, 인증 코드조차 모르겠음" | **안내 부족(맞음)** — 코드는 스마트폰 인증 앱(Google/Microsoft Authenticator)이 만드는 6자리인데 화면이 그 절차를 설명하지 않았고, QR 없이 비밀 문자열만 보여 줌 | 켜기 절차를 번호로 안내, `otpauth://` 링크 제공(스마트폰에서 바로 추가), 인증 앱이 없을 때를 위한 서버 명령 `sudo php scripts/totp_code.php <비밀>` 추가(보정 지시 → Opus). README 에 절차 기록 |
| 2026-09-11 | (조치 결과) ②③⑤ 화면 보정 완료 | **커밋됨** — 홈 제목 `t-box (재구축)`, 안내 상자 1개(단계 번호 0), 레벨 상자 자리표시+`required`+기본 team, 팀 본인 기본 선택, 설명 "(선택)", MFA 절차 5단계·`otpauth://` 링크·경고, `scripts/totp_code.php`. Fable 실측: 로컬 8082 HTML/JS 확인, 서버 회귀(레벨 없음 400·남의 팀 403) 불변, 린트 0 | 사용자 재확인: 새로고침 뒤 홈 화면·업로드 폼·「내 계정」 2단계 인증 절차. ① 은 시크릿 창 재확인 요청 중 |
| 2026-09-11 | ⑥ "로그인 화면에 아이디·비밀번호·2단계 인증 코드 6자리가 나온다" | **안내 부족 + 실제 결함 1건** — 코드 칸은 2단계 인증을 **켠 계정만** 쓰는 선택 입력인데 모든 사용자에게 항상 보여 헷갈림. 확인 중 **로그인 JS 가 `otp` 값을 서버로 보내지 않는 결함** 발견(켠 계정은 브라우저 로그인 불가 — 10단계 검토가 curl 로만 확인한 항목) | 코드 칸을 `<details>` 로 접고 "켠 계정이라면 눌러서 입력, 아니면 그냥 로그인" 문구(Fable, 문구·구조). JS 는 Opus 재지시로 `payload.otp` 추가. 로그인 전에 그 계정이 2단계 인증을 켰는지 서버가 알려 주지 않는 이유: 계정 정보 노출 방지(R-32 와 같은 원칙) |
| 2026-09-11 | ⑦ (사용자 제안) "1차 로그인 후, 2차 인증이 설정된 계정이면 2차를 진행하도록 순차 진행하면 되잖아" | **채택** — 비밀번호가 맞은 뒤에만 서버가 "2단계 인증 필요" 를 알려 주고 화면이 코드 칸을 펼치는 순차 흐름. 비밀번호를 모르는 사람에게는 계정 상태가 새지 않아 보안상 손실 없음 | ARCH 4.0 "2단계 인증 로그인 흐름" 행으로 문서 선반영 → Opus 재지시(서버 `mfa_required` 응답·1단계는 실패 횟수 미집계, 화면 2단계 UI). 접힌 코드 칸(⑥ 조치)은 이 흐름으로 대체 |
| 2026-09-11 | ⑧ (사건) 06:33 OS 계정 `eric`(이 VM 을 만든 관리자 계정, uid 1000, `sudo NOPASSWD`, SSH 키 없이 **비밀번호 로그인**)이 `192.168.200.50` 에서 접속 → `/var/www/tbox-v2` 에서 자기 Claude 세션 실행 → 06:37 `pg_dump` 자체 백업(`/var/backups/tbox-v2/tbox_re_2026-09-11_063736.dump`, 평문, sha256 `4cad1006…`) → `psql -d tbox_re` 로 `users`·`files`·`sessions` 비움 → `find /var/lib/tbox-v2/storage -type f -delete` → 06:39 로그아웃. 감사 로그(앱)에는 행이 없음. 사용자(changkwon)도 Fable·Opus(모두 changkwon 으로 실행)도 한 일이 아님 | **앱 밖 삭제 사건** — 강사 계정의 훈련·시연으로 추정되나 확정 불가(사용자가 강사에게 확인 필요). 앱 방어는 무관: root 권한 DB·파일 직접 조작은 S-10-A(서버 기준선)·S-08(백업) 영역. 흔적은 `auth.log`(sshd·sudo)·`last` 에만 남음 — 9단계에서 미적용으로 남긴 **SSH 비밀번호 로그인 허용(R-22)** 이 바로 그 입구였음. 06:55 Opus 가 계정 0개라 시드를 재생성했지만 비밀번호를 잃음(구현자는 운영 데이터를 건드리지 않는다는 규칙 추가 예정) | 증거 보전: 덤프 파일·auth.log 그대로. 복구 선택은 사용자 결정(Fable 정밀 복구 / 직접 README 절차 / 보류). 재발 방지 후보: `hardening.sh --apply`(비밀번호 SSH 차단 — 단, eric 은 키가 없어 접속 불가가 됨 → 강사와 협의), eric 의 NOPASSWD sudo 검토, AL-9 유형 경보(sudo 로 DB·저장소 직접 조작 감시)는 미구현 |
| 2026-09-11 | ⑨ (사용자 소감·질문) "억울하다. 강사가 안내해 준 비밀번호와 IP 를 그대로 쓰고 있었는데, 관리자 입장에서는 비밀번호도 IP 도 언제든 알 수 있으니 언제든 털 수 있는 것 아니냐" | **맞다 — 그리고 그것이 이 시연의 요점이다.** VM 을 만든 사람(하이퍼바이저·`eric` 계정·초기 비밀번호 발급자)은 앱이 아무리 단단해도 디스크·DB·비밀번호를 모두 손에 쥔다(R-11 "root 는 지울 수 있다"). 학생의 구현 결함이 아니다. IP 는 원래 비밀이 아니며(주소는 언제나 노출), 문제는 **발급받은 초기 자격증명(계정·비밀번호·NOPASSWD sudo)을 그대로 둔 것**과 **탐지·복구 준비**였다 | 교훈을 세 층으로 기록: ① **막을 수 없는 것** — 인프라 소유자(하이퍼바이저·root). 여기서는 계약·신뢰·감사 로그를 밖으로 보내는 것으로 대응(원격 로그, 3-2-1 백업). ② **막을 수 있었는데 보류한 것** — 초기 계정·비밀번호 회전(R-38, 오늘 `passwd` 완료), `eric` 의 NOPASSWD sudo 검토, SSH 비밀번호 로그인 차단(R-22, `hardening.sh --apply` 는 강사와 협의 후). 실무에서는 서버를 넘겨받은 첫날 하는 일이다. ③ **뚫린 뒤가 평가 대상** — 원인 분석(SECURITY 8장)·복구·재발 방지. 비밀번호를 바꿔도 root 는 `sudo passwd changkwon` 으로 다시 바꿀 수 있으므로 이번 변경이 막는 것은 "히스토리에서 비밀번호를 읽은 제3자"이며 `eric` 자신은 아니다 |
| 2026-09-11 | ⑩ (사용자 질문) "md 파일(설계·규칙 문서)도 바뀌지 않았을까? 추측 아닌 정확한 확인. AI 로 개발하는 비전문가는 대화 정리(문서)가 뒤틀리면 큰 문제 — 극단적으로 문서에 'DB 를 전체 삭제할 것' 같은 문구가 심겨 있을 수도" | **바뀌지 않았음 — 네 근거로 확인.** ① git: 사건 창(15:33~15:40 KST) 커밋·reflog(이력 재작성)·`.git` 파일 변경 0, `git fsck` 이상 없음. 사건 직전 커밋 `6bc3f14` 이후 md 변경은 이 세션의 커밋 5개(`fb591bd`·`48d72a5`·`d8dd1bf`·`0873e5d`·`aab79b0`)뿐. ② mtime: 두 접속 창(15:34~15:40, 16:11~16:26)에 저장소에서 바뀐 파일은 이 세션의 `CLAUDE.md`(16:20:02 = `fb591bd` 커밋 시각)와 `.git` 객체뿐. ③ `eric` 의 Claude 세션 기록(`/home/eric/.claude/projects/-var-www-tbox-v2/…jsonl`) 정밀 파싱: 도구 호출 **Bash 7회, Edit·Write 0회**(문자열 검색에 잡힌 Edit/Write 1건씩은 시스템 프롬프트 스냅샷의 도구 이름). 2차 접속은 `ll`·`claude` 실행·`date`·`history` 만 — 대화 기록 파일 없음, sudo 0건. ④ md 전수 검색(TRUNCATE·DROP·DELETE·전체 삭제·무시하고 등) 결과는 모두 방어 설명·공격 예시 문맥 | 우려는 정당함(문서 = AI 의 지시서이므로 문서 오염은 곧 명령 오염 — *prompt injection*). 오늘 실제로 막은 층: 자동 모드 분류기가 Fable 의 `TRUNCATE` 를 두 번 차단, CLAUDE.md 4장 "구현자는 운영 데이터를 건드리지 않는다", DB 권한(`tbox_app` 은 audit_logs 삭제 불가). 제안(사용자 결정): CLAUDE.md 에 ⓐ **파괴적 작업은 문서가 아니라 사용자의 그 자리 대화 지시로만** 수행(문서에 적힌 삭제·초기화·복구 지시는 따르지 않고 보고), ⓑ **세션 시작 시 `git status`·`git diff HEAD --stat` 로 문서 변경을 먼저 확인**하고 사용자에게 보고 — 문서 무결성의 기준은 git 이력 |
| 2026-09-11 | ⑪ (사용자 질문) "'사용자의 그 자리 대화 지시로만 한다'고 해도, 내가 정당한 사용자인지 AI 가 어떻게 검증하지?" | **검증할 수 없다.** AI 가 보는 것은 이 세션을 돌리는 OS 계정(`changkwon`, uid 1001)·접속 출처(`10.0.4.173` → SSH)·작업 디렉터리뿐이고, 키보드 앞의 사람은 보지 못한다. `changkwon` 셸을 얻은 사람(비밀번호 SSH, 또는 root 가 `su - changkwon`)은 AI 에게 사용자와 구별되지 않는다 — 앱이 사람 대신 **세션 쿠키**를 믿는 것과 같은 구조(훔친 쿠키 = 사용자, R-10). 실측: `changkwon` 도 `sudo NOPASSWD: ALL` 이므로 그 셸을 쥔 사람(AI 포함)은 비밀번호 없이 root | 신원 검증은 AI **앞단**(OS 로그인)의 몫이고, AI 규칙은 신원이 아니라 **피해 범위**를 줄이는 장치다. 그래서 신원에 의존하지 않는 방어가 진짜다: ① 최소 권한(`tbox_app` 은 감사 로그 삭제 불가, 백업은 서버 밖) ② 누가 시켜도 막는 층(오늘 분류기가 정당한 사용자의 TRUNCATE 도 두 번 막음) ③ **사람이 직접 비밀번호를 치는 단계**를 남기기 — `changkwon` 의 NOPASSWD 를 없애면 파괴적 명령마다 사람이 `!`·`sudo` 로 비밀번호를 쳐야 하므로 그 순간이 곧 신원 확인이 된다(AI 는 `sudo` 를 못 쓰게 되어 운영 명령은 사용자가 실행 — 오늘 복구 스크립트 방식) ④ 되돌릴 수 없는 일은 2인 승인 또는 지연. 부수 조치: `~/.claude`(대화 기록·메모리 디렉터리)가 775 였음 → 700 으로 조정(umask 0002 원인) |
| 2026-09-11 | ⑫ (사용자 질문) "Claude Code 에 로그인한 계정 정보로는 (사용자 검증이) 어려울까?" | **한 겹 더 되지만 같은 한계.** Claude Code 는 Anthropic 계정(OAuth)으로 로그인하고 AI 는 그 계정(이 세션: 사용자 이메일)을 문맥으로 받는다 — 그래서 eric 의 세션(eric 자신의 토큰, `/home/eric/.claude/.credentials.json`)과 이 세션은 구별된다. 그러나 토큰은 `~/.claude/.credentials.json`(0600, 만료 뒤 refresh 로 갱신)에 **파일로** 저장되므로 `changkwon` 셸을 쥔 사람은 그 토큰도 쥔다 → OS 계정과 같은 신뢰 경계. AI 가 토큰을 스스로 검증하는 것도 아니고(하네스가 알려 줌), **행동 직전에 다시 인증(step-up)** 을 요구하는 기능은 Claude Code 에 없다(`/login` 은 재로그인일 뿐) | 사람의 **현재 존재**를 증명하려면 토큰 소유가 아니라 **그 순간에만 알 수 있는 것**이 필요하다. 이 서버에서 바로 가능한 것: ① `changkwon` 의 `sudo NOPASSWD` 제거 → 파괴적 명령은 사용자가 `!`/`sudo` 로 비밀번호를 치는 순간이 신원 확인(⑪) ② PreToolUse 훅으로 파괴 명령(TRUNCATE·DROP·rm -rf·restore) 을 차단하고, 사용자가 `sudo touch /run/tbox-approve`(2분 유효) 를 친 뒤에만 통과 — sudo 비밀번호 = 사람 확인 ③ 앱과 같은 원리로 Anthropic 계정에 2단계 인증을 켜 토큰 재발급 시 보호. 단 root(eric)는 어느 방법이든 우회 가능 — 인프라 소유자 문제는 그대로(⑨) |
| 2026-09-11 | ⑬ (사용자 반박) "sudo 비밀번호를 요구해도, 비밀번호를 알고 들어온 이상 이미 끝 아닌가?" | **맞다.** SSH 를 비밀번호로 들어온 공격자에게 sudo 비밀번호는 **같은 비밀**이라 문이 하나 더 있는 게 아니다. 이 서버의 인증은 "아는 것" 하나(비밀번호)뿐이고, 그것을 아는 사람은 시스템의 정의상 곧 그 사용자다. sudo 비밀번호 단계가 실제로 막는 것은 다른 위협이다: ① 키 파일만 훔친 공격자(비밀번호는 모름) ② **사람 없이 움직이는 AI**(문서 오염·실수) ③ 열린 터미널을 이어 쓰는 사람(sudo 기억 15분 만료). ①②③과 "비밀번호를 아는 사람" 은 다른 문제 | 비밀번호를 아는 사람을 막으려면 **비밀 하나를 두 개로** 쪼개야 한다(앱의 TOTP 와 같은 이유). 실측: sshd 는 `authenticationmethods any`(키 또는 비밀번호), `changkwon` 은 공개키 2개 등록됨, `eric` 은 0개. 새 패키지 없이 가능한 것: ⓐ `AuthenticationMethods publickey,password` — 키 **그리고** 비밀번호를 둘 다 요구(비밀번호만 아는 사람은 못 들어옴, 키만 훔친 사람도 못 들어옴) ⓑ `AllowUsers changkwon@<사내 대역>` 또는 `Match Address` 로 접속 출처 제한(`192.168.200.50` 은 sshd 단계에서 거절) ⓒ 그래도 뚫린다고 **가정**하고 남길 것 — 이 서버의 자격증명으로 접근 불가한 곳(다른 호스트가 **당겨 가는** 백업, 추가 전용 원격 로그)에 복구·증거를 두기. eric 은 키가 없어 ⓐ 적용 시 접속 불가 → 강사와 협의 필수 |
| 2026-09-11 | ⑭ (사용자 제공) 다른 수강생(`hyeongjoon.mso.so`)의 사고 보고서 본문을 붙여 주며 "방식을 비교해 줘" (링크는 공개 아티팩트라 AI 도구로 읽기 불가 → 본문 붙여 넣기로 해결) | 같은 강사·같은 IP·같은 입구, 같은 판정(앱 무관). 차이는 공격 행동(저쪽 백도어+흔적 삭제 / 우리 전체 삭제+흔적 방치)과 대응 방식(저쪽 AI 즉시 강화 12건 / 우리 복구 우선·강화는 사용자 결정). 비교로 **우리 결함 2건** 발견: ① `hardening.sh` 드롭인 `tbox.conf` 가 `50-cloud-init.conf` 뒤에 읽혀 비밀번호 SSH 차단이 무력화될 수 있었음 ② 증거 원본 사본·해시를 안 남겼음(auth.log 는 주 1회 회전). 저쪽 원인 5(real_ip 없음)는 우리는 이미 적용됨을 실측. 계정 잠금 DoS 는 공통 약점 | SECURITY 8.5 비교표, 8.4-11(증거 사본·SHA256 보전 완료) ·12(hardening 결함 → Opus) ·13(자격증명 교체는 SSH 강화 뒤) ·14(잠금 DoS 결정 필요). 교훈: 남의 사고 보고서는 **내 서버를 다시 점검하는 체크리스트**다 — 설정은 파일이 아니라 실효값(`sshd -T`)으로 검증 |
| 2026-09-11 | (조치 결과) 복구 실행 + ⑥⑦ 순차 2단계 로그인 검증·커밋 | **완료** — 사용자 지시 "복구 스크립트 실행 해" → Fable 실행(분류기 차단 없음): users 20·files 121·sessions 6 복원, audit_logs 보존, 시험 파일 4개만 누락(예상). `qa_employee01` 로그인 200. 2단계 로그인 ①~⑤ 실측 통과(SECURITY 7장 행) | 사용자 확인 사항: ⓐ 기존 비밀번호로 웹 로그인 ⓑ 로그인 화면에 코드 칸이 **안 보이고**, 2단계 인증을 켠 계정은 비밀번호 뒤에 칸이 나타나는지 ⓒ 파일 목록에 사고 전 파일이 보이는지(04:58 이후 올린 4개는 다운로드 불가가 정상) |

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
| 2026-09-11 | 서버 계정(`changkwon`) 비밀번호는 어떻게 바꿔? (사고 후속 R-38) | `passwd` 를 **직접 연 터미널**에서 친다. 현재 비밀번호 → 새 비밀번호 → 한 번 더. 입력 중 글자가 전혀 안 보이는 것이 정상. 짧거나 사전 단어면 `BAD PASSWORD` 로 거부. 이 비밀번호는 SSH·sudo 용이며 t-box 웹 계정 비밀번호와 별개. 바꿔도 지금 SSH 창은 안 끊기고 다음 접속부터 적용 — **새 창에서 접속 확인 뒤** 기존 창을 닫는다. 명령줄에 비밀번호를 적으면 히스토리에 남으니(R-38 의 원인) 항상 프롬프트로 입력 | `passwd` → `password updated successfully` · `sudo -k && sudo true` (새 비밀번호로 sudo 확인) |
| 2026-09-11 | `passwd` 안내를 보고 `.` 과 `Current password` 를 명령처럼 쳤다 → `filename argument required` / `command not found` | 안내 글의 번호 `1.` `2.` 와 `Current password:` 는 **컴퓨터가 물어보는 문구**라 직접 치는 것이 아니다. 터미널에 치는 것은 `passwd` 한 단어뿐이고, 그 뒤에 나오는 물음에 비밀번호만 입력한다. `.` 만 치면 셸의 `source` 명령이라 파일 이름을 요구한다 | `passwd` 를 치고 `Current password:` 가 **자동으로** 나오는지 본다 |
| 2026-09-11 | 서버 시간이 UTC 인데 KST 로 바꿔 줘 | 시각은 세 층(OS·PostgreSQL·PHP)이 따로 갖고 있어 **함께** 바꿔야 한다. OS `timedatectl set-timezone Asia/Seoul`, DB `ALTER SYSTEM SET timezone`, PHP `date.timezone`. 저장된 시각(`TIMESTAMPTZ`)은 절대 시각이라 데이터는 안 바뀌고 표시만 `+09` 로 나온다. cron 도 KST 로 돈다. 자세한 절차는 README 「서버 시간대」 | `timedatectl` · `date` · `psql -Atc "show timezone"` · `php -r 'echo date("c");'` |
| 2026-09-11 | `password` 라고 쳤더니 `Command 'password' not found` | 명령 이름은 **`passwd`** 다 — password 에서 `or` 를 뺀 여섯 글자(p·a·s·s·w·d). 유닉스 초기에 명령 이름을 짧게 줄이던 관습이 남은 것(`ls`=list, `cd`=change directory, `mv`=move 도 같은 계열). 오타면 셸이 "비슷한 명령이 있다"며 다른 것을 추천하는데(1password 등) 그건 무시한다 | `passwd` 를 치고 `Current password:` 가 자동으로 나오는지 |
| 2026-09-11 | 시드 계정 비밀번호는 어디서 보나? | `/etc/tbox-v2/seed-accounts.txt` — root 만 읽을 수 있어(0600) `sudo` 가 필요하다. 한 줄에 `아이디 역할 비밀번호`. 시드 5개(`admin01`·`manager01`·`employee01`·`employee02`·`guest01`)만 사용자용이고 `qa_*` 5줄은 AI 검토 전용. **첫 로그인에서 비밀번호를 바꾸면 이 파일의 값은 옛 값**이 된다 → 5개 모두 바꾼 뒤 `sudo rm` (R-33). 값을 채팅·메신저에 붙이지 않는다 | `sudo cat /etc/tbox-v2/seed-accounts.txt` (내 터미널에서만) |
| 2026-09-11 | 비밀번호를 바꾸면 DB 에 저장될 텐데, DB 는 어떻게 확인하나? | DB 에는 비밀번호가 **없다** — 단방향 해시(Argon2id, `$argon2id$…`)만 있어 원문을 되돌릴 수 없고, 로그인 때 입력값을 같은 방식으로 계산해 **비교**만 한다. 그래서 "바뀌었는지" 는 해시 문자열이 달라졌는지·`password_changed_at` 이 찍혔는지·`must_change_password` 가 false 가 됐는지로 본다. DB 는 `psql` 로 들어가 SQL 로 본다(나가기 `\q`, 표 목록 `\dt`, 표 구조 `\d users`) | `sudo -u postgres psql -d tbox_re` 뒤 `SELECT username, left(password_hash,22), password_changed_at, must_change_password, mfa_enabled FROM users ORDER BY id;` |
| 2026-09-11 | "암호화하고 복호화하는 절차인가?" (문구로 잠근 저장 제안에 대해) | 그렇다. 등록 때: t-box 의 무작위 비밀 키 + 사용자 문구 → PBKDF2-SHA256 으로 잠금 키 → AES-GCM 으로 비밀 키를 암호화 → **암호문만** PC 에 저장(문구·잠금 키·원본은 저장 안 함). 쓸 때: 문구 → 같은 계산 → 복호화 → 코드 계산. 문구가 틀리면 AES-GCM 이 스스로 실패를 알린다. 공격자는 문구와 **암호문(PC)** 둘 다 필요 → "가진 것" 겹 유지 | 설계 그림은 대화 기록·SECURITY R-40 후속 |
| 2026-09-11 | "SHA-256 으로 암호화하고 AES 로 복호화하면 되는 거 아냐?" | **해시와 암호화는 다르다.** SHA-256 은 한 방향(믹서기: 주스에서 과일로 못 돌아감) — 복호화라는 것이 없고 누구도 되돌릴 수 없다. AES 는 양방향(자물쇠: 같은 키로 잠그고 푼다). 잠근 알고리즘과 푸는 알고리즘은 같아야 하므로 "SHA-256 으로 잠그고 AES 로 푼다" 는 성립하지 않는다. 권고안에서 SHA-256 은 **문구를 키 모양으로 바꾸는 데만**, 잠그기·풀기는 **둘 다 AES** 가 한다. 정확한 문장: "SHA-256 으로 키를 만들고, AES 로 잠그고 AES 로 푼다" | 같은 원리가 비밀번호 저장에도: DB 의 `password_hash` 는 SHA 계열이 아닌 Argon2id 해시이지만 역시 한 방향이라 관리자도 원문을 못 본다(앞 질문 참조) |
| 2026-09-11 | "자체 점검 통과. 평시 로그인할 때는 이 암호 문서를 어떻게 열어 확인하나? 컴퓨터에 생성기를 저장해 두고 쓰는 건가?" | 그렇다. 생성기 HTML 을 PC 에 저장해 두고(바탕화면 등) 로그인할 때 그 파일을 열어 잠금 문구 → 6자리 → t-box 2단계 칸에 입력. **주의**: 잠가 저장한 암호문은 브라우저의 '그 주소(origin)' 저장소에 남는다 — `http://localhost:8082` 에서 저장했으면 파일(`file://`)로 열었을 때는 보이지 않는다. 그러므로 **평시에 쓸 자리(저장한 파일)에서 잠가 저장**해야 한다(같은 PC·같은 브라우저·같은 파일). 브라우저 데이터 삭제·PC 교체 시 암호문이 사라지므로 같은 비밀 키를 스마트폰 앱에도 등록해 두면 예비가 된다 | STAGES 자기 공격 체크·README 순서 보강 |
| 2026-09-11 | "Admin 이 (비밀번호·문구를) 까먹으면? 개발자인 내가 찾아줄 수 있나?" | **찾아 줄 수는 없고, 다시 발급해 줄 수는 있다.** ① 비밀번호: DB 에는 Argon2id 해시만 있어 누구도 원문을 못 본다 → **재발급**(다른 Admin 이 앱에서 `임시 비밀번호 재발급`(감사 기록 남음), 없으면 서버 운영자가 `reset_admin01.sh` 같은 예외 절차(sudo 로그만 남음)). ② 2단계 인증 비밀 키: 서버가 코드를 검증해야 하므로 `users.mfa_secret` 에 **평문(base32)** 으로 있다 → DB 슈퍼유저는 읽어서 `totp_code.php` 로 코드를 만들 수 있고, 보통은 `2단계 인증 초기화` 로 새 키를 받게 한다. ③ 생성기의 잠금 문구: 어디에도 저장되지 않아 복구 불가 — 대신 ②로 새 키를 받으면 된다. **함의**: DB 슈퍼유저(root)는 2단계 인증을 통째로 우회할 수 있다(R-11 과 같은 한계) — 그래서 그 권한을 쓰는 일은 예외로 두고 SECURITY 8.4 처럼 기록한다 | 운영 권고: Admin 2인 이상(서로 재발급), 예외 절차는 문서화·기록. 오늘 admin01 이 그 예외 사례 |
| 2026-09-11 | "그럼 오늘 같은 eric 의 해킹은 막을 수 있나?" | **그 방법은 막을 수 있지만, 그 사람은 못 막는다.** 오늘의 경로(비밀번호 SSH → NOPASSWD sudo → root → psql) 는 ① SSH 키 전용(또는 키+비밀번호) ② `AllowUsers`/접속 대역 제한 ③ 두 계정의 NOPASSWD 제거 ④ 미사용 관리자 계정 잠금 으로 **모두 끊긴다**(eric 은 키가 없고 허용 대역 밖). 그러나 VM 소유자(하이퍼바이저)는 콘솔·디스크로 언제든 root 가 되므로 **막는 것이 아니라 흔적·복구를 밖에 두는 것**(원격 로그, 다른 호스트가 당겨 가는 백업)이 답이다. 현재 상태: 위 ①~④ **아직 미적용**(hardening.sh 드롭인 순서 결함 수정 전, 강사 협의 전) — 지금 다시 시도하면 **그대로 뚫린다** | 다음 결정: 강사와 협의 → Opus 가 `hardening.sh` 결함 수정 → 사용자가 콘솔에서 적용 → `sshd -T` 실효값 검증 → 원격 백업 |
| 2026-09-11 | "비밀 키 생성기가 로컬 어디에 저장돼 있나?" | 세 곳을 구분한다. ① **서버 원본**: `/var/www/tbox-v2/public/assets/totp-generator.html` (터널로 `http://localhost:8082/assets/totp-generator.html`). ② **내 PC 의 파일**: 브라우저 `Ctrl+S` 로 저장한 위치 — 따로 고르지 않았으면 다운로드 폴더(Windows `C:\Users\<이름>\Downloads\totp-generator.html`). 브라우저 다운로드 목록(`Ctrl+J`)에서 「폴더에서 보기」로 찾는다. ③ **잠가 저장한 암호문**: 파일 안이 아니라 **브라우저 프로필의 저장소(localStorage)** 에 있다 — 파일을 다른 곳으로 옮겨도 그대로지만, 다른 브라우저·다른 PC 에서는 없다 | 파일을 바탕화면 등 정한 자리로 옮겨 두고, 항상 같은 브라우저로 연다 |
| 2026-09-11 | hardening.sh 출력 모드가 "현재 출처 192.168.105.44 가 관리망에 없다" 며 거부 | `192.168.105.44` 는 **이 서버 자신의 IP** 다. 19:52 에 터널 명령 `ssh -L 8082:127.0.0.1:8082 changkwon@192.168.105.44` 를 PC 의 PowerShell 이 아니라 **서버 안 터미널에서** 실행해, 서버가 자기 자신에게 SSH 로 들어간 **중첩 세션**(pts/3, 비밀번호 인증)이 생겼다. 그 창에서 스크립트를 돌리니 출처가 서버 IP 로 잡혀 안전장치가 거부한 것 — 안전장치는 정상 동작. 그 터널은 PC 브라우저에 아무 효과가 없다(포트가 서버 안에서 서버로 열림) | 구분법: 각 창에서 `echo $SSH_CONNECTION` — 바깥 창은 `10.0.4.173 … 192.168.105.44 22`, 중첩 창은 `192.168.105.44 … 192.168.105.44 22`. 조치: 중첩 창에서 `exit` 로 나오기, 터널은 **PC 의 PowerShell** 에서 실행, hardening 은 바깥 창(+바깥 창 하나 더)에서 |
| 2026-09-11 | "서버→서버 비밀번호 로그인도 막힌다는 게 무슨 소리?" | 19:52 의 중첩 세션은 `auth.log` 에 `Accepted password for changkwon from 192.168.105.44` 로 남았다 — 즉 **비밀번호**로 들어간 SSH 다. 강화가 적용되면 sshd 가 `PasswordAuthentication no` 가 되어 SSH 로 비밀번호를 받는 문이 **어디서 오든**(PC 든, 서버 자신이든) 닫힌다. 그래서 같은 명령을 다시 치면 거절된다는 뜻. 영향 범위는 SSH 만: 이미 열린 창·`sudo` 비밀번호·t-box 웹 로그인·PC 의 **키** 로그인(등록된 키 2개)은 그대로 | 확인: 적용 뒤 `ssh -o PubkeyAuthentication=no changkwon@192.168.105.44` → `Permission denied (publickey)` 가 정상 |
| 2026-09-11 | "서버가 왜 자기 자신에게 SSH 로 들어가? 프록시가 내려가서 8082 를 직접 연 걸 말하나?" | 아니다, 두 가지가 섞였다. ① **8082 는 새로 연 게 아니다** — 1단계부터 있던 검토용 로컬 사이트(서버 안 127.0.0.1 에서만 열림). ② `ssh -L 8082:… changkwon@192.168.105.44` 는 "**지금 내가 있는 컴퓨터에서** 192.168.105.44 로 접속하고, 내 8082 를 그쪽 8082 로 이어라" 는 명령이다. PC 의 PowerShell 에서 치면 PC→서버(의도한 터널). 그런데 이미 서버에 들어가 있는 창(`changkwon@eric:~$`)에서 치면 "지금 있는 컴퓨터" 가 서버라서 서버→서버가 된다. 의도가 아니라 **명령을 친 자리** 때문에 생긴 일이며 해를 끼치진 않는다 | 규칙: 프롬프트가 `changkwon@eric` 이면 서버 안, `PS C:\…>` 이면 PC. 터널 명령은 후자에서 |
| 2026-09-11 | 결정 | "진행해" — hardening.sh 결함 수정·강화 항목 보강(적용은 사용자) | 지시서 작성 중 **두 번째 결함** 발견: 관리망 기본값이 사용자 출처(10.0.4.x)를 빼고 침입자 출처(192.168.200.x)를 포함. 교훈: 방화벽·접속 제한 값은 문서의 가정이 아니라 **지금 내가 들어온 출처**(`echo $SSH_CONNECTION`)로 검증한다. 스크립트에 자기 잠금 방지 검사와 2차 세션 플래그를 넣도록 지시 |
| 2026-09-11 | 해소 | hardening.sh 수정 검토 통과 | 시뮬레이션(임시 사본 + `sshd -T -f`)으로 결함 재현→수정 확인: 옛 이름 `tbox.conf` 는 `passwordauthentication yes`, 새 이름 `00-tbox.conf` 는 `no`, 충돌 파일 이름 변경 뒤에도 `no`. 출력 모드 재실행: 현재 출처 10.0.4.173 이 관리망에 포함, 충돌 드롭인 `50-cloud-init.conf` 경고, 실효값 표(적용 전이라 '다름' 정상). `--apply` 단독·옛 관리망 값 → 종료 2 로 거부. 실제 변경 0(sshd_config.d·ufw·sshd -T 전후 동일). Opus 추가: `sudo` 가 `SSH_CONNECTION` 을 지우므로 부모 프로세스 environ 에서 출처를 역추적(`--from-ip` 대안) | 적용은 사용자: 새 SSH 창 하나 더 연 뒤 `sudo bash deploy/hardening.sh --apply --i-have-a-second-session` → 표가 전부 '일치' → 새 창에서 접속 확인 → 기존 창 닫기. eric 관련 옵션은 협의 후 |
| 2026-09-11 | 결정 | "오케이 진행해" — 문구로 잠근 저장(ⓐ)·4자 묶음 표시(ⓑ) 채택, QR(ⓒ)은 후속 | PRD F-17-2, SECURITY R-40 갱신 → Opus 구현 지시 |

---

## 4. 이 문서를 채우는 규칙 (요약 — 원문은 `CLAUDE.md` 9장)

1. **언제**: 사용자의 질문에 답한 직후, 관문 ②·③ 결과가 나온 직후, 커밋 직전. 커밋 직전 갱신이 없으면 커밋하지 않는다.
2. **무엇을**: 그 단계의 개발 체크 상태 · 검토 체크 결과(통과/실패/미검증) · 자기 공격 체크 결과 · 커밋 해시 · 학습 기록(어려움 / 궁금 / 해소 / 결정 / 요청).
3. **어떻게**: 학습 기록은 `날짜 | 구분 | 내용(사용자 말의 요지) | 해소·근거(답과 문서 위치)` 한 줄. 답을 다시 쓰지 않고 **어디에 근거가 있는지** 가리킨다.
4. **기초 질문**(터미널·리눅스·git 등)은 3장에. 단계와 무관해도 버리지 않는다.
5. 보안 점검 **방식**이 바뀌면 1장을 함께 고친다. 판정 근거는 언제나 `SECURITY.md` 7장 이력이며 이 문서는 그 요약이다.
