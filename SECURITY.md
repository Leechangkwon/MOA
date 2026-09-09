# t-box 보안 재검토 (SECURITY.md)

| 항목 | 내용 |
|---|---|
| 검토 대상 | `PRD.md` v1.0, `ARCHITECTURE.md` v1.0 |
| 검토 일자 | 2026-09-08 |
| 검토 단계 | **설계 검토** (코드 없음). 아래 "막힘" 판정은 *"문서대로 구현되면 막힌다"* 는 뜻이며, 구현 후 5장 절차로 **실제 실행 검증**을 다시 해야 한다 |
| 검토 방식 | 문서 대조 + 기존 t-box(`/var/www/html`)의 실제 취약 코드와 비교 |
| 결론 요약 | 실습에서 뚫린 5가지는 설계상 모두 차단됨. 단, **감사 로그 조회 화면을 통한 정보 누출(R-01)** 등 설계에 빠진 항목 있음 → 2장. **애플리케이션 밖(서버·OS·공급망·제로데이·운영)** 은 2차 검토(2026-09-08, 6장)에서 보완 |

---

## 1. 실습에서 뚫린 5가지 — 하나씩 확인

### 1.1 한눈에 보기

| # | 공격 | 기존 t-box에서 뚫린 코드 | 새 설계의 방어 | 판정 | 구현 시 반드시 확인 |
|---|---|---|---|---|---|
| 1 | 프론트 권한 우회 | `assets/app.js` 버튼 `display:none` + `api/files.php` DELETE 무검사 | 단일 입구 → `Auth::requireLogin` → `Authz::canDelete` → CSRF | **막힘** | 라우트에 권한 정책 미등록 시 실행 불가하게 강제 (1.2-③) |
| 2 | 권한 없는 데이터 노출 | `api/files.php` GET 전체 반환 + JS `VISIBLE_BY_ROLE` 필터 | `Authz::visibleFilesWhere` 가 SQL WHERE 생성, COUNT도 동일, 단건은 403 동일 문구(정보 없음) + UUID 외부 ID | **막힘** | 목록형 엔드포인트 **전부**(휴지통 포함)에 같은 원칙 적용. 감사 로그는 Admin 전용으로 확정(Q-8) |
| 3 | XSS | `api/upload.php` 무검증 저장 + `tr.innerHTML` 출력 | 서버 `htmlspecialchars` + JS `textContent` + CSP(인라인 금지) + 다운로드 `attachment` + HTML/SVG 업로드 차단 | **막힘** | 감사 로그 화면의 `user_agent`·`target_name` 출력도 이스케이프 (공격자 통제 값) |
| 4 | SQL 인젝션 | `api/search.php` `"LIKE '%$q%'"` 문자열 결합 + 오류 메시지 노출 | PDO 값 바인딩만 허용, `ORDER BY` 허용 목록, `LIKE` 이스케이프, 오류 은닉, DB 최소 권한, Argon2id | **막힘** | `PDO::ATTR_EMULATE_PREPARES = false` 설정, `LIMIT/OFFSET` 정수 캐스팅 |
| 5 | SSRF | `api/fetch_url.php` `file_get_contents($url)` | 기능은 유지(F-14, Q-7 결정)하되 S-05 13개 조건: https·443 만, 호스트 화이트리스트, IP 검증 + `CURLOPT_RESOLVE` 고정, 리다이렉트 금지, `Upload::validate`, 응답 비노출, `allow_url_fopen=Off` | **막힘 (조건부)** | 13개 조건 중 하나라도 빠지면 무효. T-6 페이로드 전부 실행 (1.6) |

### 1.2 ① 프론트 권한 우회 — 왜 막히나

| 공격 단계 (실습 때) | 새 설계에서 벌어지는 일 | 근거 |
|---|---|---|
| 개발자도구로 삭제 버튼 되살리기 | 버튼은 되살릴 수 있다. 그러나 버튼은 **권한의 근거가 아님** | PRD 7.1, ARCH 2장 |
| 콘솔에서 `fetch('/api/files/1',{method:'DELETE'})` | ① CSRF 토큰 없음 → 403 ② 토큰을 붙여도 `Authz::canDelete(Employee, 남의 파일)` = false → 403 + 감사 로그 `deny` | ARCH 5.4 |
| `switch_role.php?role=Admin` 으로 역할 전환 | 파일 자체가 없다. 역할은 세션 `user_id` → DB `users.role` 에서만 읽음 | ARCH 4장 [1] |
| 다른 `.php` 를 직접 호출해 검사 우회 | nginx가 `index.php` 외 `.php` 요청을 404 처리 | ARCH 8장 |

**설계에 남아 있는 약점 → 보완 필요**

| 약점 | 위험 | 보완 |
|---|---|---|
| ③ `Authz` 호출은 "규칙"일 뿐, 핸들러 하나가 호출을 빠뜨리면 무효 | 높음 | 라우터의 라우트 테이블에 `policy` 를 **필수 항목**으로 두고, 정책이 없는 라우트는 등록 단계에서 예외를 던지게 한다. 예: `route('DELETE','/api/files/{id}', 'files_delete', policy: 'file.delete')` |
| 비활성화·역할 변경된 사용자의 기존 세션 | 중간 | ARCH 4장 [1]의 "요청마다 DB 재조회"를 **필수**로 명시하고, `is_active=false` 면 세션 즉시 파기 |

### 1.3 ② 권한 없는 데이터 노출 — 왜 막히나

| 공격 단계 (실습 때) | 새 설계에서 벌어지는 일 | 근거 |
|---|---|---|
| Network 탭에서 `/api/files` 응답 원문 열기 | 응답에는 SQL이 돌려준 행만 있고, WHERE절이 이미 `admin-only` 를 제외했으므로 **응답 원문에 존재하지 않음** | ARCH 5.1, PRD 7.2 |
| `total` 건수로 숨은 파일 개수 추측 | COUNT도 같은 WHERE → 내가 볼 수 있는 개수만 | ARCH 5.1 [5] |
| ID 바꿔가며 `/api/files/…` 조회 | 권한 없으면 403 + 동일 문구, 본문에 파일 정보 없음. ID 는 UUID 라 열거 자체가 어려움 (Q-6 결정으로 404 → 403; 존재 여부는 드러나지만 정보는 새지 않음) | PRD S-09-5 |
| `/uploads/…` 직접 URL 접근 | 경로가 웹 루트 밖. `/protected/` 는 `internal` | ARCH 3장, 8장 |

**설계에 남아 있는 약점 → 보완 필요**

| 약점 | 위험 | 보완 |
|---|---|---|
| ~~감사 로그 조회(Manager) 경유 파일명 누출~~ | ~~높음~~ | **해소** — Q-8 결정으로 감사 로그 열람을 Admin 전용으로 축소 (2026-09-08) |
| 403 선택으로 "파일이 존재한다"는 사실은 드러남 | 낮음 | 외부 노출 ID 를 UUID(`public_id`)로 → 열거가 불가능해 존재 여부 노출이 실질적 의미를 잃음. **반영됨**(ARCH 6.2) |
| `deleted_at IS NULL` 조건을 각 쿼리가 개별로 붙임 | 중간 | `Authz::visibleFilesWhere` 가 `deleted_at IS NULL` 을 **항상 포함**해 한 곳에서만 관리 |

### 1.4 ③ XSS — 왜 막히나

| 공격 단계 (실습 때) | 새 설계에서 벌어지는 일 | 근거 |
|---|---|---|
| 설명란에 `<img src=x onerror=alert(1)>` 업로드 | 저장은 된다(길이 제한 내). 출력 시 `htmlspecialchars` / `textContent` → **글자로만 표시** | PRD 7.3 |
| 혹시 어딘가 `innerHTML` 이 남았다면? | CSP `script-src 'self'` 가 인라인 이벤트 핸들러·인라인 스크립트 실행을 차단 (2차 방어) | ARCH 8장 |
| `evil.html` 을 업로드해 같은 도메인에서 열기 | ① `.html/.svg` 업로드 거부 ② 어떻게든 저장돼도 다운로드는 `application/octet-stream` + `attachment` + `nosniff` → 브라우저가 렌더링하지 않음 | PRD S-06, ARCH 5.3 [8] |
| XSS로 세션 쿠키 탈취 | 쿠키 `HttpOnly` → 스크립트가 읽을 수 없음 | ARCH 9장 |

**설계에 남아 있는 약점 → 보완 필요**

| 약점 | 위험 | 보완 |
|---|---|---|
| 감사 로그 화면이 `user_agent`, `target_name`, `reason` 을 표시 — 모두 공격자가 만들 수 있는 값 | 중간 | 감사 로그 화면도 5.1 목록 화면과 **같은 렌더링 규칙**(textContent) 적용을 PRD 10장에 명시 |
| 검색어를 화면에 되돌려 보여줄 때("'회의'로 검색한 결과") 반사형 XSS | 중간 | 검색어 표시도 이스케이프. 서버 렌더링이면 템플릿 함수 `e()` 강제 |
| CSP `style-src 'self'` 인데 인라인 `style=` 속성을 쓰면 화면이 깨져 개발자가 CSP를 완화할 유혹 | 낮음 | 개발 초기부터 CSP를 켜고 개발 (나중에 켜면 완화하게 됨) |

### 1.5 ④ SQL 인젝션 — 왜 막히나

| 공격 단계 (실습 때) | 새 설계에서 벌어지는 일 | 근거 |
|---|---|---|
| `' OR '1'='1` | 검색어가 값으로 바인딩되어 문자 그대로 `LIKE '%'' OR ''1''=''1%'` 를 찾음 → 0건 | PRD 7.4 |
| `' UNION SELECT username,password,… FROM users --` | 같은 이유로 0건. 설령 성공해도 `password_hash` 는 Argon2id → 즉시 악용 불가 | PRD S-09-4 |
| 문법을 깨서 오류 메시지로 구조 파악 | `display_errors=Off`, 화면엔 "요청을 처리할 수 없습니다" | ARCH 9장 |
| 정렬 파라미터 `sort=id;DROP TABLE files` | 허용 목록 밖 → 기본값으로 대체. 앱 계정에 `DROP` 권한 없음 | ARCH 5.1 [3], 10장 |

**설계에 남아 있는 약점 → 보완 필요**

| 약점 | 위험 | 보완 |
|---|---|---|
| PDO 기본값은 **에뮬레이트 prepared**(드라이버가 문자열을 조립) | 중간 | `Db.php` 에서 `PDO::ATTR_EMULATE_PREPARES => false` 고정 → PostgreSQL 서버측 바인딩 |
| `Db.php` 가 `query($rawSql)` 같은 함수를 노출하면 결국 결합 코드가 생김 | 중간 | `Db::run(string $sql, array $params)` 하나만 공개. 코드 검색(`security_lint.sh`)으로 `"… $` 패턴 자동 반려 |
| `LIMIT/OFFSET`, 감사 로그 필터(`action`, `actor_id`) 등 부수 파라미터 | 중간 | 정수는 `(int)` 캐스팅 후 바인딩, 문자열 필터는 허용 목록 |

### 1.6 ⑤ SSRF — 왜 막히나 (Q-7: 기능을 만들되 13개 조건)

| 공격 단계 (실습 때) | 새 설계에서 벌어지는 일 | 근거 |
|---|---|---|
| `file:///etc/passwd` | 스킴이 `https` 가 아니므로 파싱 단계에서 거부. cURL 도 `CURLPROTO_HTTPS` 만 허용 | S-05 ① |
| `http://127.0.0.1:3000` | `http` 거부. `https://127.0.0.1` 로 바꿔도 IP 리터럴 거부, 화이트리스트에 없음, 포트 3000 거부 — 세 겹 | S-05 ①②③④ |
| `http://169.254.169.254/latest/meta-data` | 같은 이유로 거부. 만약 허용 도메인이 이 IP 로 해석돼도 링크로컬 대역 검사에서 거부 | S-05 ⑤ |
| 허용 도메인에서 `302 → http://내부` | 리다이렉트를 따라가지 않고 실패 처리 | S-05 ⑦ |
| DNS 리바인딩(검사 땐 공인 IP, 접속 땐 내부 IP) | 검사한 IP 를 `CURLOPT_RESOLVE` 로 고정해 접속 | S-05 ⑥ |
| 가져온 응답 본문 읽어내기 | 본문·헤더·상태를 사용자에게 돌려주지 않음. 결과는 "저장됐다 / 가져올 수 없습니다" 둘 중 하나 | S-05 ⑩ |
| URL 로 웹셸 반입 | 수동 업로드와 같은 `Upload::validate` 통과 필요 | S-05 ⑨ |

**설계에 남아 있는 약점 → 보완 필요**

| 약점 | 위험 | 보완 |
|---|---|---|
| 조건 13개 중 하나만 빠져도 무효 — 특히 ⑥(IP 고정)과 ⑨(파일 검증)는 빠뜨리기 쉽다 | 높음 | T-6 를 13개 조건 각각에 대응하는 페이로드로 구성(PRD S-05 검증 표). 7단계 검토에서 **전부 실행** |
| 화이트리스트가 비어 있거나 `*` 같은 와일드카드를 허용하면 블랙리스트와 같아짐 | 높음 | 비어 있으면 전부 거부, 와일드카드 파싱 금지(정확 일치만). 초기 목록은 Q-10 으로 결정 |
| `isPrivate` 의 IPv6 처리 누락 (`::ffff:127.0.0.1`, `[::1]`, 축약 표기) | 중간 | `inet_pton` 기반 CIDR 비교, IPv4 매핑 주소를 IPv4 로 환원 후 재검사. 테스트 케이스에 포함 |
| 허용 도메인 자체가 침해되면 그곳이 내부로 리다이렉트/악성 파일 배포 | 중간 | 리다이렉트 금지(⑦) + 파일 검증(⑨) + ClamAV(R-02) 로 피해 한정. 허용 목록은 분기 재검토 |
| cURL 을 허용했으므로 `Fetch.php` 밖에 두 번째 요청 경로가 생길 수 있음 | 중간 | 린트가 `src/Fetch.php` 이외의 `curl_*` 를 반려. 아웃바운드 방화벽 443 만 |
| 사내 DNS 가 허용 도메인을 내부 IP 로 응답하는 split-horizon 환경 | 중간 | 그 경우 ⑤ 에서 거부되어 기능이 동작하지 않음 — **의도된 안전 실패**. 필요하면 해당 도메인을 목록에서 제외 |

---

## 2. 5가지 외의 위험 — 위험도 높은 순

위험도: **치명** > **높음** > **중간** > **낮음**  ·  설계 상태: ✅ 반영 / ◐ 부분 / ❌ 없음

| 순위 | ID | 위험 | 위험도 | 설계 상태 | 무엇이 문제인가 | 보완 (→ 반영할 문서) |
|---|---|---|---|---|---|---|
| 1 | R-01 | ~~감사 로그 경유 정보 누출~~ | ~~높음~~ | ✅ 해소 | Manager 열람 자체를 제거 (Q-8, 2026-09-08). Admin 전용 | — |
| 2 | R-02 | 악성 파일 유통 (매크로 docx, 위장 실행파일, zip 속 exe) | **높음** | ◐ 선택(2단계) | 파일 공유 서비스의 본질적 위험. 서버는 무사해도 **직원 PC가 감염** | ClamAV 스캔을 MVP 필수로. `pending` 상태는 다운로드 불가, `infected` 는 격리 + Admin 알림 (→ PRD S-06, F-05) |
| 3 | R-03 | SSRF 경로 통제 (F-14 구현으로 cURL 허용) | **높음** | ◐ 설계 | cURL 이 필요해졌으므로 "함수 차단" 대신 "한 파일에 격리 + 린트 + 방화벽" 으로 통제 (1.6) | `src/Fetch.php` 격리, 린트 반려, 소켓 함수 차단 유지, 아웃바운드 443 만 (→ ARCH 5.6, 9, 11.3) |
| 4 | R-04 | 권한 정책 누락 (핸들러가 Authz를 안 부름) | **높음** | ✅ 구현·확인 (1단계, 2026-09-09: 모르는 정책·없는 핸들러 → 전 요청 500, 로그에 사유) | 사람의 실수 하나로 S-01 전체 무효 | 라우트 테이블 `policy` 필수 + 린트 (→ ARCH 4장) |
| 5 | R-05 | Admin 계정 탈취 (비밀번호 단일 요소) | **높음** | ◐ | Admin 하나 털리면 급여·인사 자료 전부 노출. 잠금 5회는 브루트포스만 막음 | Admin·Manager **MFA(TOTP)** 필수, Admin 로그인은 사내 특정 IP 대역 제한 (→ PRD F-01, S-09-4) |
| 6 | R-06 | 계정 잠금을 이용한 서비스 거부 | 중간 | ◐ 구현·실측 (2단계) | 공격자가 `admin01` 에 틀린 비밀번호 5번 → 관리자 10분 잠금 반복 | nginx `limit_req` 30r/m·burst 10 → 실측 25회 연속 중 10 통과·15 429. 계정 잠금은 5회/10분. 잠금 시 관리자 알림(AL-2)은 8~9단계 |
| 7 | R-07 | PATCH 대량 할당 (mass assignment) | 중간 | ❌ | `/api/files/{id}` PATCH 가 요청 JSON을 그대로 UPDATE 하면 `uploaded_by`, `team_id`, `sha256` 까지 바뀔 수 있음 | 변경 허용 필드를 `description`, `access_level`(+`team_id`) 로 **명시적 화이트리스트** (→ PRD 8장, ARCH handlers) |
| 8 | R-08 | 백업 암호화 키 관리 | 중간 | ◐ | "키는 다른 장소에" 만 있음. 키 분실 = 백업 전체 무용, 키 유출 = 전체 데이터 유출 | 키 보관 위치·담당자 2인·회전 주기·복구 리허설에 키 복구 포함 (→ PRD 7.8, ARCH 11장) |
| 9 | R-09 | 다운로드 대역폭 DoS | 중간 | ◐ "속도 제한" 언급만 | 100MB 파일을 스크립트로 반복 다운로드 → 사내망 마비 | nginx `limit_req`(로그인 5r/m, 다운로드 30r/m), `limit_conn`, `limit_rate` 구체 수치 (→ ARCH 8장) |
| 10 | R-10 | 세션 수명 관리 | 중간 | ◐ 구현 (2단계) | 로그아웃·비밀번호 변경·비활성화 시 **다른 기기 세션**이 살아 있음 | DB 세션 + `Auth::revokeAllSessions()` 구현(F-10 계정 관리에서 호출 예정). 로그아웃은 세션 행 DELETE 실측(로그아웃 후 /api/me 401). 유휴·절대 만료 실측(DB 시각 조작 → 401) |
| 11 | R-11 | 감사 로그 무결성 (DB 관리자·root 는 지울 수 있음) | 중간 | ◐ | 앱 계정만 막았음. 내부자(DBA)·서버 침해엔 무력 | `audit.log` 를 **원격 syslog/SIEM 으로 실시간 전송**(추가 전용). 일별 로그 해시 체인 (→ ARCH 7장) |
| 12 | R-12 | Guest 계정 생명주기 | 중간 | ❌ | 협력사 계정에 만료가 없어 프로젝트 끝나도 영구 접근 | `users.expires_at` 필수(Guest), 만료 시 자동 비활성화, 90일 미접속 계정 비활성화 (→ PRD 3장, F-10) |
| 13 | R-13 | 파일 무결성 검증이 "선택" | 중간 | ◐ | 서버 침해 시 스토리지 파일을 바꿔치기(악성 문서로 교체)해도 감지 못함 | 다운로드 시 sha256 대조를 **필수**로(100MB 도 ~0.3초), 주간 정합성 검사 결과를 감사 로그에 (→ ARCH 5.3 [6]) |
| 14 | R-14 | 비밀 정보 실수 커밋 | 중간 | ◐ | `.gitignore` 만 믿음. 기존 t-box 저장소엔 실제로 `.env` 가 존재했음 | `pre-commit` 시크릿 스캔(gitleaks), `.env.example` 만 커밋 (→ PRD S-09-6) |
| 15 | R-15 | 패치 관리 | 중간 | ❌ | PHP·nginx·PostgreSQL 보안 업데이트 주기 미정 | 월 1회 정기 패치 + 치명 CVE 72시간 내, `unattended-upgrades` 보안 채널 (→ ARCH 11장) |
| 16 | R-16 | 파일명 시각 혼동 (RTL 오버라이드·동형 문자) | 낮음 | ◐ | `report‮fdp.exe` 가 `reportexe.pdf` 처럼 보임 — 단, 실행파일은 업로드 자체가 차단됨 | 화면의 확장자·형식 표시는 DB `ext`·`mime` 컬럼에서(원본명이 아니라) (→ PRD 10장) |
| 17 | R-17 | 로그인 후 리다이렉트(`?next=`) 오픈 리다이렉트 | 낮음 | ❌ | 피싱: 진짜 로그인 후 가짜 사이트로 이동 | `next` 는 `/` 로 시작하는 상대 경로만 허용 (→ PRD F-01) |
| 18 | R-18 | 서버 시간 불일치 | 낮음 | ❌ | 감사 로그 시각이 어긋나면 사고 조사가 불가 | NTP(chrony) 필수, DB `now()` 사용 통일 (→ ARCH 11장) |
| 19 | R-19 | 팀 모델 변경 위험 (Q-1) | 낮음 (설계 리스크) | ◐ | Manager 가 여러 팀을 맡게 되면 `team_id` 단일 컬럼 전제의 `Authz` 가 전면 수정 | 1단계 전에 Q-1 확정. 확장 시 `user_teams` 조인 테이블로 `visibleFilesWhere` 만 수정되도록 (→ PRD 14장) |
| 20 | R-20 | 레벨 하향·대량 삭제의 단독 실행 | 낮음 | ❌ | Admin 1인 실수/악의로 `admin-only → public` 즉시 반영 | 레벨 하향은 확인 단계 + 감사 로그 `detail` 에 이전값, 대량 삭제 API 미제공 (→ PRD 5.3) |

### 2.1 이미 잘 반영된 항목 (확인 완료, 추가 조치 불필요)

| 항목 | 근거 |
|---|---|
| 업로드 파일 실행(웹셸) | 스토리지 웹 루트 밖 + nginx `.php` 404 + UUID 이름 + `open_basedir` (ARCH 3, 6.3, 8, 9) |
| 경로 조작 | 저장 경로에 사용자 입력 0글자, `realpath` 검사 (ARCH 5.3 [5]) |
| CSRF | 토큰 + `SameSite=Lax` + 상태 변경은 POST/PATCH/DELETE 만 (ARCH 4장 [2]) |
| 세션 고정 | `session.use_strict_mode=1` + 로그인 시 재발급 (ARCH 9장, PRD S-09-2) |
| 클릭재킹 | `frame-ancestors 'none'`, `X-Frame-Options: DENY` (ARCH 8장) |
| 헤더 인젝션(파일명) | `filename*=UTF-8''` 인코딩, 원본명은 헤더에 직접 사용 안 함 (ARCH 6.3) |
| 오류 정보 노출 | `display_errors=Off`, 통일 메시지 (ARCH 9장) |
| 고아 파일 / 반쪽 저장 | 업로드를 트랜잭션으로, 실패 시 파일 삭제 (ARCH 5.2 [5]) |
| 실수 삭제 | 30일 논리 삭제 + Admin 복구 (PRD F-07, F-11) |
| 비밀번호 저장 | Argon2id (PRD S-09-4) |
| 소스·설정 노출 | `public/` 만 root, `.env` 는 public/ 밖 + 0600 + .gitignore (ARCH 3장) |

---

## 3. 판정 요약

| 질문 | 답 |
|---|---|
| 실습에서 뚫린 5가지가 이 설계대로 만들면 막히는가? | **예, 5가지 모두 설계상 차단된다.** 각각 1차 방어 + 2차 방어(CSP, `allow_url_fopen=Off`, DB 권한 등)가 있다 |
| 그대로 구현하면 끝인가? | **아니다.** 2장의 R-01 ~ R-05 (높음) 는 설계 문서에 빠져 있거나 약하다. 특히 **R-01(감사 로그 누출)** 은 "권한 없는 데이터 노출"이 다른 문으로 다시 들어오는 경우다 |
| 가장 먼저 고칠 것 | R-04 (라우트 정책 필수 — 문서 수정으로 해결) → R-02, R-05 (MVP 범위 결정 필요) → R-03 (F-14 구현 시 13개 조건 전부). R-01 은 Q-8 로 해소됨 |

### 3.1 문서 보완 액션 목록

| # | 문서 | 위치 | 보완 내용 | 관련 |
|---|---|---|---|---|
| A-1 | PRD | 7.7 감사 로그 | ~~Manager 마스킹~~ → Admin 전용으로 확정 (✅ 2026-09-08, Q-8) | R-01 |
| A-2 | ARCH | 4장 | 라우트 테이블에 `policy` 필수, 미등록 시 부팅 실패 | R-04 |
| A-3 | PRD | S-06, F-05 | 악성코드 스캔을 MVP 필수로 상향, `pending/infected` 동작 정의 | R-02 |
| A-4 | ARCH | 9장, 11장 | `disable_functions`, 아웃바운드 방화벽 기본 거부 규칙 | R-03 |
| A-5 | PRD | F-01, S-09-4 | Admin/Manager MFA, IP 단위 속도 제한, 잠금 알림 | R-05, R-06 |
| A-6 | PRD | 8장 PATCH | 변경 허용 필드 화이트리스트 명시 | R-07 |
| A-7 | PRD/ARCH | 7.8 / 11장 | 백업 키 관리(보관·2인·회전), 세션 전체 무효화, 원격 로그 전송, 패치 주기, NTP | R-08, R-10, R-11, R-15, R-18 |
| A-8 | PRD | 3장, F-10 | Guest `expires_at`, 휴면 계정 비활성화 | R-12 |
| A-9 | ARCH | 5.3 [6] | sha256 대조를 선택 → 필수 | R-13 |
| A-10 | ARCH | 5.1, 6.2 | `Authz::visibleFilesWhere` 에 `deleted_at IS NULL` 포함, `public_id UUID` 도입 (UUID ✅ 반영) | 1.3 |

---

## 4. 구현 후 재검토 절차 (반드시 실행으로 확인)

설계 검토는 "문서대로 만들면" 이라는 가정 위에 있다. 구현이 끝나면 **추정하지 않고** 아래를 실제로 실행해 증거를 남긴다.

| 단계 | 할 일 | 증거로 남길 것 |
|---|---|---|
| 1 | PRD 12장 T-1 ~ T-16 을 **전부 실행** (`curl`·브라우저·`psql`) | 실행한 명령과 **응답 원문**(상태 코드·본문) |
| 2 | 코드 정적 점검 `scripts/security_lint.sh` | `innerHTML`, SQL 문자열 결합(`"… $`), `curl_exec`, `file_get_contents('http`, `eval`, `exec` 검색 결과 = 0건 |
| 3 | 라우트 테이블 대조 | 모든 라우트에 `policy` 가 있고, `Authz` 함수와 PRD 5장 매트릭스가 1:1 로 맞는지 표로 대조 |
| 4 | 서버 설정 확인 | `php -i | grep -E 'allow_url_fopen|open_basedir|disable_functions|display_errors'`, `nginx -T` 에서 `internal`, `client_max_body_size`, 헤더 |
| 5 | DB 권한 확인 | `\dp audit_logs` 에서 `tbox_app` 에 UPDATE/DELETE 없음 |
| 6 | 감사 로그 확인 | T-1 ~ T-13 수행 후 `SELECT action,result,reason FROM audit_logs ORDER BY id DESC LIMIT 30` 에 각 시도가 있는지 |
| 7 | 결과 기록 | 아래 7장 검토 이력에 **통과/실패/미검증** 을 항목별로 기록. 실행 못 한 항목은 "미검증" 으로 남기고 "안전" 이라 쓰지 않는다 |

### 4.1 판정 용어

| 용어 | 뜻 |
|---|---|
| **통과** | 실행했고, 기대 결과와 응답 원문이 일치함 |
| **실패** | 실행했고, 공격이 성공했거나 기대와 다름 → 커밋 금지 |
| **미검증** | 실행하지 못함(환경 부재 등). "안전" 판정으로 취급하지 않음 |

---

## 6. 애플리케이션 밖의 위험 — 2차 검토 (강사 강조 사항 반영)

> "PRD 쓰고, 아키텍처 설계하고, AI한테 보안 점검받는 것은 기본이지 충분조건이 아니다."
> 1~5장은 **앱 코드**의 위험만 다뤘다. 이 장은 앱이 완벽해도 남는 위험을 점검하고, 문서에 반영한 결과를 기록한다.

### 6.1 다섯 영역 점검

| 영역 | 1차 검토 시 상태 | 무엇이 비어 있었나 | 보완 (반영 위치) | 보완 후 |
|---|---|---|---|---|
| 서버 자체 보안 | ❌ 아웃바운드 차단(R-03)만 | SSH·인바운드 방화벽·계정·포트·PostgreSQL 바인딩·파일시스템 옵션 전부 없음 | 서버 기준선 표 + 확인 명령 (PRD S-10-A, ARCH 11.3), T-17·T-18 | ◐ 문서 반영. 서버 구축 후 T-17~T-18 로 검증 |
| OS·미들웨어 취약점 | ◐ R-15 "월 1회 패치" | **실습 VM 설치본이 EOL**: PHP 8.1 은 2025-12-31 에 이미 종료, PostgreSQL 14 는 2026-11-12 종료 | 사용자 결정(Q-11): 참가자 동일 환경을 위해 8.1 유지 → **위험 수용**, 운영 전환 시 EOL 2년 이상 버전 필수(PRD 11장). 자동 보안 패치·패치 후 스모크 (S-10-B, T-19) | ◐ 수용된 위험 (R-21) |
| 언어·라이브러리(공급망) | ◐ 프레임워크·composer 없음이 장점이나 문서에 없음 | 의존성 목록 없음(ClamAV·restic·age·fail2ban 등 앱 밖 도구), 출처 검증 규칙 없음, AI 생성 코드 취급 기준 없음 | 의존성 0개 원칙, `deploy/DEPENDENCIES.md`(SBOM), 배포판 패키지만, AI 코드도 동일 리뷰 (PRD S-10-C, T-23) | ✅ 설계 반영 |
| 제로데이 | ❌ | 패치가 없을 때의 전략 부재 | 노출면 축소·**유출 통로 차단(아웃바운드 거부)**·최소 권한·탐지·대응 준비·WAF(선택) (PRD S-10-D, ARCH 11.3) | ◐ 구조적 완화. 제로데이는 "막는다"가 아니라 "피해를 줄이고 빨리 안다"가 목표 |
| 운영 보안 | ◐ 백업 암호화·감사 로그·Guest 만료(R-12)는 있음 | **로그를 보는 사람과 규칙이 없음**, 퇴사자 권한 회수 절차 없음, 비밀 관리 도구 미지정, 내부자(DBA·root) 통제 약함, 사고 대응 절차 없음 | 경보 AL-1~AL-9, 운영 캘린더, Offboarding 당일 회수, 비밀 관리 도구, 침해 대응 6단계 (PRD S-10-E, S-11, ARCH 11.4~11.6, T-20~T-22) | ◐ 문서 반영. **사람이 실제로 돌리는지**가 남은 위험 (6.3) |

### 6.2 원칙 3가지 → 문서의 어디에 어떻게

| 원칙 | 문서에 넣은 장치 | 위치 |
|---|---|---|
| ① AI로 공격도 폭발. 작년의 안전이 올해의 안전이 아님 | 분기마다 `SECURITY.md` 전면 재검토, T-테스트에 **새 우회 기법 반영** + 출처 기록. 릴리스마다 검토 이력 추가. AI 생성 코드도 동일 리뷰 | PRD S-11-C, S-10-C, CLAUDE.md 3장 |
| ② 대부분 "털린 줄도 모름" → 감사 로그·모니터링이 기능보다 중요 | 기록(S-07)에 **감시**를 붙임: 경보 9개 규칙, 원격 추가전용 로그 서버, **로그가 끊긴 것(AL-7)도 경보**, 앱 서버 밖에서 감시. 침해 대응 3단계 "범위 파악"에서 다운로드 로그가 곧 증거 | PRD S-11-A, ARCH 11.4, 11.6 |
| ③ 만들고 땡이 아님. 점검·업데이트는 운영 그 자체 | 일/주/월/분기/연 캘린더 + 담당 + 산출물. 캘린더 미실행 3개월 시 서비스 축소 검토. 5단계 개발 범위에 S-10·S-11 포함 | PRD S-11-B, 13장, ARCH 11.5 |

### 6.3 새로 추가된 위험 (2장 목록에 이어서)

| 순위 | ID | 위험 | 위험도 | 상태 | 보완 |
|---|---|---|---|---|---|
| — | R-21 | **보안 지원 종료 버전 사용** (PHP 8.1 — 2025-12-31 종료, PostgreSQL 14 — 2026-11-12 종료) | **높음** | ◐ **위험 수용** (Q-11, 2026-09-08) | 사유: 교육 참가자 전원 동일 VM. 완화: 사내망 전용(NG-4), 아웃바운드 차단, Ubuntu 배포판 보안 패치는 계속 적용됨(8.1.2-1ubuntu2.x — Canonical 이 백포트). **실서비스 전환 조건**: PHP 8.4·PostgreSQL 16 으로 업그레이드 후 T-1~T-23 재실행 |
| — | R-22 | 서버 인바운드·SSH 무방비 | **높음** | ◐ 문서 | ARCH 11.3 적용 후 T-17·T-18 |
| — | R-23 | **운영 절차의 실행 여부** — 문서는 있으나 사람이 안 돌리면 없는 것 | **높음** | ◐ | 캘린더 실행을 월간 보고, 자동화 가능한 것(패치·백업·경보·린트)은 전부 자동화해 사람 의존 최소화 |
| — | R-24 | 앱 서버 안의 경보는 서버 침해 시 신뢰 불가 | 중간 | ✅ 설계 | 로그 끊김·경보 판정은 **원격 로그 서버에서** (ARCH 11.4) |
| — | R-25 | 침해 시 증거 소멸 (재부팅·삭제) | 중간 | ✅ 설계 | 침해 대응 1·2단계 "하지 말 것" 명시, 연 1회 훈련 |
| — | R-26 | 개인정보(급여) 유출 시 법적 통지 기한 미준수 | 중간 | ✅ 설계 | 침해 대응 5단계에 72시간 통지 명시. 법무 연락망 |
| — | R-27 | 외부 도구(ClamAV·restic) 자체의 취약점 | 낮음 | ◐ | `DEPENDENCIES.md` 에 포함, 배포판 패키지 + 자동 패치 대상 |
| — | R-28 | ~~실습 VM 에서 HSTS·80→443 리다이렉트 생략~~ → 구조 재확인: 강사 리버스 프록시가 TLS 종단·http→https 301, VM 80 으로 전달 (ARCH 3장 배포 메모) | 낮음 | ✅ **해소** (2026-09-09) | v2 를 80 공개로 전환(`deploy/switch-site.sh v2`). 실측 `curl -D - https://changkwon.mso.so/api/health` → `strict-transport-security: max-age=31536000; includeSubDomains`, `http://` → 301 |
| — | R-29 | 새 저장소가 **옛 취약 사이트의 웹 루트 안**(`/var/www/html/T-Box_Re`)에 있었음 | 낮음 (해소) | ✅ 해소 (2026-09-09, 저장소를 `/var/www/tbox-v2` 로 이동 — 옛 웹 루트 밖) | 1단계: 옛 사이트에 `deploy/old-site-deny.conf` include (`/T-Box_Re/` → 404), 실측 `http://호스트/T-Box_Re/src/Db.php`·`/.env`·`/db/schema.sql` 전부 404, 옛 사이트 `/index.php` 200 유지. 이후 저장소를 옛 웹 루트 밖으로 이동해 경유 경로 자체가 사라짐 → **차단 include 제거** |
| — | R-30 | **프록시→VM 내부 구간 평문 HTTP** | 중간 | ◐ **위험 수용** (사내망) | 감사 로그 IP 우려는 실측으로 해소: 프록시가 `X-Forwarded-For`·`X-Forwarded-Proto` 를 보내고 `set_real_ip_from 192.168.104.100` 으로 `$remote_addr` 가 실제 클라이언트 IP(로그 원문 `1.222.243.244 "192.168.104.100" "1.222.243.244" "https"`). 내부 구간 암호화는 운영 전환 시(VM 직접 443) |
| — | R-31 | **VM 80 포트에 사내망 어디서나 직접 접속 가능** (프록시 우회) | 중간 | ◐ | IP 위조는 불가 — 실측: 127.0.0.1 에서 `X-Forwarded-For: 8.8.8.8` 보내도 `$remote_addr` = 127.0.0.1. 접속 자체 차단은 9단계 방화벽(80 은 192.168.104.100 만 허용) |
| — | R-32 | **잠금 응답(423)으로 계정 존재 여부 추론 가능** — 없는 아이디는 잠기지 않으므로 6회 시도 후 401/423 차이로 유효 아이디를 알 수 있음 | 낮음 | ◐ **위험 수용** | 사내 서비스라 아이디(사번·이름)는 이미 공개 정보. 완화: IP 속도 제한(30r/m)으로 열거 속도 제한, AL-2. 대안(존재하지 않는 아이디도 IP 단위로 가짜 잠금)은 10단계 이후 검토 |
| — | R-33 | **시드 비밀번호 평문 파일의 수명 미정** — `/etc/tbox-v2/seed-accounts.txt`(root 0600) 를 언제 지우는지 규칙이 없음. root 를 얻은 침해자에겐 5계정 평문이 그대로 노출 | 중간 | ◐ (2026-09-09 도출) | 규칙: 첫 로그인 확인 후 삭제, 또는 10단계 F-10(비밀번호 변경) 적용 직후 삭제. README 운영 절차에 명시. 실습 기간엔 관문 ③ 편의로 유지(위험 인지) |
| — | R-34 | **같은 VM 의 v1(취약판, `www-data`)과 v2 스토리지 교차 노출** — `/var/lib/tbox-v2/storage` 가 `tbox-php:www-data 0750` 이라 nginx(X-Accel) 용 그룹 읽기가 곧 v1 웹셸(www-data)의 읽기 권한이 됨 | 중간 (현재 v1 은 `127.0.0.1:8081` 로컬 전용이라 경로 닫힘. v1 을 80 에 꽂으면 열림) | ◐ (2026-09-09 실측 도출) | 실측: `id www-data` = groups 33 만, `.env`(changkwon:tbox-php 0640)·`seed-accounts.txt`·`owner.pgpass`(root 0600) 는 www-data 불가, DB 는 해시만. **6단계 Storage 구현 시 결정**: (a) v2 전용 nginx 그룹 분리 또는 (b) X-Accel 대신 PHP `readfile` 로 내주고 그룹 읽기 회수. 결정 전까지 v1 을 공개 80 에 꽂는 동안은 위험 인지 **3단계 결정(2026-09-09): 다운로드는 PHP `readfile()` — nginx 그룹 읽기가 아직 필요 없음(하위 폴더 실측 `tbox-php:tbox-php 0750`, 파일 0640). X-Accel 전환 여부는 6단계에서 (b) 유지 시 상위 폴더 그룹 읽기 회수까지 검토** |
| — | R-35 | **3단계 "권한 검사 없음" 상태가 공개 도메인에 그대로 노출됨** — 과제 설계상 의도된 단계(PRD 13장)지만, 4단계 전까지 로그인만 하면 admin-only 포함 전 파일 열람·다운로드·삭제가 가능 | 중간 | ◐ **위험 수용** (3→4단계 사이, 2026-09-09) | 완화: 계정은 시드 5개뿐(외부 가입 없음)·실습 데이터만 올림·인증·CSRF·감사 로그는 동작. 4단계 커밋 시 R-35 ✅ 해소로 변경. 그동안 실데이터 업로드 금지 |

### 6.4 2차 검토 후에도 남는 한계 — 솔직하게

| 한계 | 왜 남나 | 그래서 |
|---|---|---|
| 제로데이는 문서로 못 막는다 | 정의상 패치가 없다 | 피해 축소(유출 통로 차단·최소 권한) + 탐지 속도가 방어다. "막힘" 이라고 쓰지 않는다 |
| 이 검토도 AI 가 했다 | AI 는 알려진 패턴에 강하고, 이 환경 고유의 맥락(사내망 구조·조직·법규)은 모른다 | 연 1회 **외부 사람의 모의해킹**(S-11-B)을 뺄 수 없는 항목으로 둔다 |
| 운영은 사람이 한다 | 캘린더는 실행될 때만 의미가 있다 | R-23. 자동화 우선, 실행 기록을 `SECURITY.md` 5장에 남겨 "안 한 달"이 눈에 보이게 한다 |
| 문서와 실제 서버는 다를 수 있다 | 설정은 시간이 지나며 어긋난다(drift) | T-17~T-23 을 매월 실행. AIDE 로 설정 변경 감시(AL-9) |

### 6.5 문서 보완 액션 (3.1 에 이어서)

| # | 문서 | 내용 | 상태 |
|---|---|---|---|
| A-11 | PRD 11장, ARCH 1·9장 | ~~PHP 8.4 · PostgreSQL 16~~ → 사용자 결정으로 실습 VM 설치본(PHP 8.1 · PostgreSQL 14) 유지, 운영 전환 기준만 EOL 2년 | ✅ 반영(수정, Q-11) |
| A-12 | PRD 7.10 (S-10) | 서버·OS·공급망·제로데이·운영 요구사항 | ✅ 반영 |
| A-13 | PRD 7.11 (S-11) | 경보 규칙 AL-1~9, 점검 캘린더, 재검토 원칙 | ✅ 반영 |
| A-14 | PRD 12.3 | T-17 ~ T-23 | ✅ 반영 |
| A-15 | ARCH 11.3 ~ 11.6 | 서버 기준선, 감시·경보, 운영 캘린더, 침해 대응 | ✅ 반영 |
| A-16 | ARCH 9장 | `disable_functions` — F-14 결정으로 cURL 은 허용, 소켓·프로세스 함수만 차단 + Fetch.php 격리 | ✅ 반영(수정) |
| A-19 | PRD 7.5, ARCH 5.6 | F-14 "URL로 가져오기" 13개 안전 조건 + 처리 흐름 + T-6 페이로드 | ✅ 반영 (2026-09-08) |
| A-20 | PRD 5·7·8장, ARCH 5장 | Q-6 403 통일, Q-8 Admin 전용 반영 | ✅ 반영 (2026-09-08) |
| A-17 | `deploy/DEPENDENCIES.md` | 의존성 목록 파일 생성 | ✅ 1단계에서 생성 (실측 버전·EOL 표). php-curl·mbstring 은 7단계 전 추가 |
| A-18 | `/etc/tbox/CONTACTS.md` | 사고 연락망 (저장소 밖) | ⬜ 운영 착수 시 |

---

## 7. 검토 이력

| 일자 | 단계 | 검토자 | 범위 | 결과 | 비고 |
|---|---|---|---|---|---|
| 2026-09-08 | 설계 검토 (1차) | Fable (AI) | PRD v1.0, ARCHITECTURE v1.0 | 5대 공격: 설계상 차단 · 추가 위험 20건 도출(높음 5) | 코드 없음 → 구현 후 4장 절차 재실행 필요. 액션 A-1 ~ A-10 미반영 |
| 2026-09-08 | 결정 반영 (Q-10·Q-11) | Fable (AI) | PRD·ARCH | F-14 허용 목록 초기값 비움·Manager 이상 확정. **설치 환경 실측: PHP 8.1.2 / PostgreSQL 14.24 / nginx 1.18 / Ubuntu 22.04** — 사용자 결정으로 8.1 유지, R-21 위험 수용으로 상태 변경 | php-curl·mbstring 미설치 → 7단계 전 설치 필요 |
| 2026-09-08 | 결정 반영 (Q-6·Q-7·Q-8) | Fable (AI) | PRD·ARCH | 403 통일(정보 미포함 + UUID), 감사 로그 Admin 전용(R-01 해소), F-14 URL 가져오기 **구현 확정** + S-05 13개 조건 재작성 | Q-10(허용 도메인 초기값) 대기. 7단계 검토 시 T-6 전부 실행 |
| 2026-09-09 | **1단계 구현 검토** (뼈대·DB) | Fable (AI) | `public/index.php`·`src/bootstrap·Audit·Storage·Upload(골격)`·`db/*.sql`·`deploy/*`·`.env.example` | **통과**: URL 차단(`/.env` `/src/Db.php` `/db/schema.sql` `/foo.php` `/protected/x` `/.git/config` `/PRD.md` 전부 404, 옛 사이트 경유 `/T-Box_Re/*` 404), `/api/health` 200, 404/405 JSON, CSP 등 헤더 4개, `\dt` 6 표, tbox_app 권한 = users/teams/files SIU · sessions SIUD · permissions S · audit_logs SI, 스키마 CREATE 없음(`permission denied for schema public`), DB CONNECT 는 tbox_app·tbox_owner 만, 정책 미등록 라우트 → 전 요청 500(CLI 재현 + app.log), 린트 0건, `.env` 미추적. **실패 후 수정**: 1회차 재지시로 스키마 CREATE·PUBLIC CONNECT 회수, storage 그룹 www-data. **미검증**: 401/403+Audit::deny 실제 경로(라우트 없음, 2단계), CSRF 토큰 비교(2단계), 옛 역할 `tbox` 접속 거부 응답 원문(권한 값 f 로만 확인), T-17~T-19 서버 기준선 | R-04 ✅, R-28·R-29 추가, A-17 ✅. 사용자 자기 공격(관문 ③) 후 커밋 |
| 2026-09-09 | **1단계 보완 검토** (80 포트 공개 전환·버전 스위치) | Fable (AI) | `deploy/sites/*.conf`, `deploy/switch-site.sh`, `deploy/install.sh` | **통과**: 도메인 경유 `/api/health` 200 + HSTS·CSP 등 헤더, `/.env` `/src/Db.php` `/db/*.sql` `/foo.php` `/index.php` `/protected/x` `/.git/config` `/PRD.md` `/deploy/install.sh` `/T-Box_Re/*` 전부 404, `http://` 301. 로컬 8081(v1)·8082(v2) 200. XFF 위조 무시 실측. v1↔v2 전환·복귀, `nginx -t` 실패 시 자동 복구(Opus 보고). 린트 0건, `.env` 미추적. **미검증**: 프록시의 업로드 크기 제한(6단계 T-9 때 실측) | R-28 ✅, R-30·R-31 실측 반영. 자기 공격(관문 ③) 대기 |
| 2026-09-09 | **1단계 자기 공격 (관문 ③)** | 사용자 | 도메인 `https://changkwon.mso.so` | `/` → 1단계 화면, `/api/health` → `{"ok":true}`, `/.env` 404, `/src/Db.php` 404, `/db/schema.sql` 404, `/T-Box_Re/src/Db.php` 404, `psql \dt` 6 rows (audit_logs·files·permissions·sessions·teams·users) | **통과** → 1단계 커밋 |
| 2026-09-09 | **2단계 구현 검토** (인증/세션) | Fable (AI) | `src/Auth.php`·`Http.php`·`public/index.php`·`handlers/login*·logout·me`·`scripts/seed_users.php`·`deploy/sites/*`·`install.sh` | **통과** (도메인 경유 직접 실행): 미로그인 `/api/me` 401·`/` 302 /login·`/admin` `/files` 404; 틀린 비번·없는 아이디 **동일 문구** 401, 폼 인코딩 400; 로그인 200 + `Set-Cookie … path=/; secure; HttpOnly; SameSite=Lax`(Max-Age 없음); 가짜 쿠키로 로그인 → 새 토큰 발급(재발급); 쿠키 1자 변조·63자 → 401; CSRF 없음/오류 → 403, 정상 → 200 후 `/api/me` 401; employee02 6회 → 5×401 + **423**, `failed_count=5, locked_until>now()`; `expires_at` 과거 → 401, `last_seen_at` 31분 전 → 401 + 화면 302; 속도 제한 25회 → 10 통과·15 429; 감사 로그 `auth.login allow/deny(bad_credentials·locked)`·`auth.logout`·`auth.required`·`csrf` 기록, ip 는 실제 클라이언트(프록시 IP 0건), detail 에 비밀값 0건; 비밀번호 해시 5계정 전부 `$argon2id$` (T-16). 린트 0건, `.env` 미추적. **미검증**: 브라우저 화면 동작(app.js 폼·로그아웃 버튼) → 관문 ③에서 사용자 확인 | R-06·R-10 ◐ 구현, R-32 추가, AL-1 에 csrf 추가. 자기 공격(관문 ③) 대기 |
| 2026-09-09 | **저장소 이동 반영** (`/var/www/html/T-Box_Re` → `/var/www/tbox-v2`) | Fable (AI) | `deploy/sites/*`, `php-fpm-pool.conf`, `install.sh`, `switch-site.sh`, 문서 | 이동 직후 nginx `root`·include·`open_basedir` 가 옛 경로 → **도메인 503, `nginx -t` 실패** 확인 → 경로 반영·재설치 후 **통과**: 도메인 `/api/health` 200, `/` 302, `/login` 200, `/.env` `/src/Db.php` `/db/schema.sql` `/foo.php` `/protected/x` `/admin` 404, 로컬 8081·8082 200. php-fpm 실효값 `open_basedir=/var/www/tbox-v2:…`, `allow_url_fopen=0`, `display_errors=0`, `disable_functions` 유지(CLAUDE 7장 서버 설정 변경 시 확인). `old-site-deny.conf` 삭제. 린트 0건 | R-29 ✅ 해소(웹 루트 밖). 교훈: 저장소 이동은 install.sh·switch-site.sh 재실행이 필수 — README 에 명시 |
| 2026-09-09 | **환경 분리 (방향 A)** — 저장소 밖 폴더 `-v2` 접미사 | Fable (AI) | `/etc/tbox`→`/etc/tbox-v2`, `/var/lib/tbox`→`/var/lib/tbox-v2`, `/var/log/tbox`→`/var/log/tbox-v2`; `deploy/*`·`.env`·문서 | v1(실습)과 저장소 밖 경로까지 완전 분리. **통과**: 세 폴더 mv·옛 경로 소멸, `.env` STORAGE_DIR·AUDIT_LOG_FILE 및 `open_basedir` 모두 `-v2`, 도메인 로그인 왕복 200 + `/var/log/tbox-v2/audit.log` 에 `auth.login allow` 실기록(경로 일치 실증), `/.env`·`/src/Db.php`·`/protected/x` 404, v1 8081 영향 없음, 린트 0건. `.env` 는 저장소 루트로 되돌림(선생님 방식) | 환경 격리 강화. 자기 공격(관문 ③) 대기 |
| 2026-09-09 | **2단계 자기 공격 (관문 ③)** | 사용자 | 도메인 `https://changkwon.mso.so`, 브라우저 | 사용자 확인: guest01·employee01 로그인·홈 화면 동작, `/api/me` 응답(`role`·`team_id`·`csrf_token`) 확인, DevTools Elements 로 역할 글자 변조 → 새로고침 시 원복·서버 역할 불변(DB 실측 guest01=Guest), Network 탭에 CSRF 토큰 노출 질의 → 설계상 정상(세션 결속 HMAC, 교차 출처 읽기 불가: `Origin` 위조 요청에 `Access-Control-Allow-Origin` 없음·`no-store` 실측). 쿠키 변조·세션 고정·CSRF 403·잠금 423·유휴 만료는 **사용자 세부 결과 미보고** — 사용자 "진행" 결정으로 관문 통과 처리(같은 항목은 위 "2단계 구현 검토" 행에서 Fable 이 curl 로 실측 통과). 유휴 만료의 브라우저 31분 대기는 미수행(psql 검증으로 대체) | **통과(사용자 결정)** → 2단계 커밋. R-33·R-34 추가 |
| 2026-09-09 | **3단계 구현 검토** (기본 기능 — 권한 검사 없음) | Fable (AI) | `public/index.php`(라우트 5줄)·`src/Storage.php`·`src/Upload.php`·`src/Http.php`(query* 추가만)·`handlers/files_list·files_upload·files_download·files_delete·teams`·`home.php`·`app.js`·`app.css` | **통과** (로컬 8082 직접 실행): 업로드 201 + DB 행 + `storage/ab/cd/UUID.ext` 0640; 목록 검색(리터럴 `#` `%` `_` 각 1건, `%` 단독 1건, 없는말 0건)·정렬 주입 `sort=size);DROP…` → 200 기본 정렬·`page=-1` → 1·`q[]` 배열 200; 다운로드 헤더 `octet-stream`/`attachment; filename=…; filename*=UTF-8''…`/`nosniff`/`no-store` + sha256 원본=수신(24B·30MB 0.25s); 진짜 CR/LF·따옴표 든 파일명(multipart 수작업) → original_name `ev`·헤더 주입 0건; 삭제 200 → 재삭제 404·다운로드 404·목록 소멸·바이트 잔존; 파일 유실 유도(mv) → 500 + `file.download error file_missing`, allow 기록 없음, 복구 후 200. **3단계 뚫림 기준선(의도)**: admin01 admin-only → employee01 목록 노출·다운로드 200(본문 확인)·삭제 200, guest01 admin-only 업로드 201, employee01 남의 팀(team=2) 업로드 201. 방어: 미로그인 files/teams/download 401, CSRF 없음·타 세션 토큰 403, not-a-uuid·UUIDv1형·없는 UUID 404, file 없음·level 오류·team_id 없음/99·설명 501·0바이트·file[] 2개 → 400, `../../etc/passwd.txt` → `passwd.txt`+UUID 저장, 101MB 413, `/protected/x` 404, PUT/미등록 GET 405, public+team_id=2 → team_id NULL. 감사 로그: file.upload/download/delete allow·deny(사유)·error, 목록 미기록, detail 비밀값 0건. 린트 0건, `php -l` 통과, `.env` 미추적. Opus 지시 이탈 3건(ESCAPE `#`·iconv·bad_encoding/Audit::error) 검토 후 수용. **미검증**: 브라우저 화면(app.js — node 없음, 코드 읽기만: 전역 UUID_RE/toLogin/formatSize 정의 확인) → 관문 ③. 관찰: CSP·nosniff 헤더가 nginx add_header + PHP header 로 **2회 중복** — 1단계부터 있던 것, 무해, 정리는 별도 | R-34 3단계 결정 기록, R-35 추가(위험 수용). 자기 공격(관문 ③: Employee 로 뚫림 캡처) 대기 |
| 2026-09-09 | **3단계 자기 공격 (관문 ③)** | 사용자 | 도메인 `https://changkwon.mso.so`, 브라우저 (employee01) | **뚫림 기준선 캡처 완료**(4단계 전후 비교용): employee01 목록에 admin01 의 admin-only `secret.txt`·manager01 의 영업팀 `secret.txt` 노출, admin-only 다운로드 성공, 삭제 버튼 노출, 업로드·검색·정렬 동작, Network 탭 `/api/files` 응답 원문에 admin-only 행 포함. 브라우저 화면(app.js) 동작으로 "미검증" 항목 해소 | **통과(의도된 뚫림 확인)** → 3단계 커밋. 4단계에서 같은 화면을 다시 캡처해 T-1·T-2·T-3 비교 |
| 2026-09-08 | 설계 검토 (2차, 앱 밖) | Fable (AI) | 강사 강조 5영역 + 원칙 3가지 | R-21 ~ R-27 추가(높음 3). **EOL 직전 버전(R-21) 발견·수정**. S-10·S-11·T-17~23·ARCH 11.3~11.6 반영 | A-11 ~ A-16 반영 완료, A-17·A-18 은 서버 구축 시. A-1 ~ A-10 은 여전히 미반영 |
