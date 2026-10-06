# Q:F-12 — 업무 · 이야기 앱 나누기와 아사나식 업무 화면 (지시서 A~D, 2026-10-07)

사용자 결정(2026-10-06~07, 원문 요지는 `../../community/work-ux-plan.md` v0.5 6장): 커뮤니티를 아사나 구조로 **전면 개편**(WK-1 A) · 스레드·여행은 새 앱 **이야기**로(WK-2 ①) · **담당자를 소유자와 따로**(WK-3 ②) · 새 작업은 프로젝트 행 + 사람마다 「내 할 일」, 「내 작업」엔 상태·기한 있는 옛 문서도(WK-4) · 섹션 직접 만들기(WK-5) · 하위 페이지 = 하위 작업(WK-6) · 첫 화면 홈(WK-7) · 공지는 작업 칸에서 뺌(WK-8) · 동시 소속·목표는 나중(WK-9) · 앱 이름 **업무 · 이야기**(WK-10) · **왼쪽 아이콘 줄**(WK-11) · 프로젝트 상태 + **포트폴리오**(WK-12) · 포트폴리오는 **팀이 함께 봄**(WK-13) · 담당은 **그 작업을 고칠 수 있는 사람만**(WK-14 A) · 이야기 주소는 **지금 그대로**(WK-15 A) · 지금 데이터베이스는 **「프로젝트로 쓰기」로 골라 바꿈**(WK-16 A). 시안 v1 승인(claude.ai 비공개 캔버스 — 기획 5.3).

기준 문서(읽고 시작): 기획 `../../community/work-ux-plan.md`(5장 구조 · 6장 결정 · 10장 보안 · 11장 Q:T-24~43) · **`../../community/ARCHITECTURE.md` 23장**(판정·표·창구·질의 — 이 지시서의 정답지) · `DESIGN.md` **2.18~2.22** + 2.9 · 2.17 · 6장 체크리스트 · `SECURITY.md` R-119 · C:R-15~17 · `ARCHITECTURE.md` 13장(5대 방어) · `../CLAUDE.md` 2·3·4장.

기능 묶음 = **A(보안 바닥) → B(서버) → C(셸·앱 나누기) → D(업무 화면)**. CLAUDE.md 3.1 대로 검토·회귀·운영 반영은 묶음 끝에 한 번이되, **A 는 판정 함수·표 모양이라 먼저 독립 검토만 받는다**(B·D 가 기댄다). 조각마다 그 조각의 수락 시험 + 바뀐 파일에 닿는 시험만. 운영 반영은 D 까지 끝난 뒤 메인 세션이 한다. 발행은 minor(새 화면).

## 공통 — 구현 자리 · 시험 수단 · 금지

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf12-20261007`**(브랜치 `feat/qf12-work-ux`, 이 지시서가 든 main 커밋에서 시작 — 메인 세션이 만든다). 결과 폴더 **`/home/changkwon/qaredi-qf12-results-20261007/<조각>/`**. 운영 `/var/www/qaredi/tbox-v2` · 운영 DB(`tbox_re`) · 운영 8082 는 **건드리지 않는다**(읽기도 하지 않는다). `tests/stage89_cross_regression/run.py` 와 `tests/community/memories_harness.py` 는 worktree 에서만 돈다 |
| 격리 PHP+PostgreSQL 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <결과 폴더>` — 합성 DB·임시 복사본·로컬 PHP(포트 8768 고정 — **한 번에 하나만** 돈다). 새 스위트는 `memories_harness.py:37` 의 `--suite` choices 에 이름을 더하고, 새 마이그레이션은 `:121-132` 의 `migrations` 목록 **끝**에 더한다(그 두 줄만 고친다 — 마이그레이션이 두 번 적용되어 멱등이 함께 시험된다). 스위트 머리 모양은 `tests/community/messenger_http.py`(공유 fixture · `call` · `check` · `sql` 헬퍼, 결과 파일엔 단언 이름·참/거짓만 — 응답 본문·자격 출력 0) |
| 브라우저 | `. /home/changkwon/qaredi-qf12-results-20261007/env.sh` 로 `CHROMIUM_EXECUTABLE` · `PYTHONPATH`(Playwright) · `PATH` **만** 받는다. 다른 결과 폴더의 `env.sh` 금지 · 셸 변수는 고유한 이름 · `rm -rf` 는 자기 결과 폴더 안 절대 경로만(2026-10-06 사고). 실제 PHP 위 브라우저 시험은 틀 안에서(`messenger_window_browser.py` 방식) |
| 교차 회귀 | `python3 tests/stage89_cross_regression/run.py --output <폴더>` — 조각마다 끝에 한 번 |
| 계정·표식 | 격리 틀의 fixture 계정만. 운영을 치는 시험은 이 지시서에 **없다**. `claude_` 검토 계정 5개 + UA `tbox-review/<날짜>` 는 메인 세션 검토 몫. 시드 계정 사용 금지 |
| 금지 패턴 | CLAUDE.md 2장 그대로: SQL 문자열 결합 · `innerHTML`(및 `insertAdjacentHTML`·`outerHTML`) · 사용자 입력이 든 파일 경로 · `curl_exec`/`file_get_contents('http…')` · 역할 판단을 `Authz`/`CommunityAuthz` 밖에서 · 요청의 role 값 · 브라우저 HTML 저장. 더해서: **담당·포트폴리오가 권한의 근거가 되는 코드 0**(판정 함수에서 `assignee_id` 나 포트폴리오 소속을 「허용」 이유로 쓰지 않는다) · **판정 두 벌 0**(핸들러에서 팀·소유 비교 직접 금지 — `CommunityAuthz` 함수만) · 목록·수는 가시성 WHERE 안에서만 · 「n개 숨김」 같은 숨은 수 응답 0 · 인라인 `style`/`<script>`/`on*` 속성 0 · 새 색값 0(DESIGN 2.18 토큰은 기존 토큰에서 끌어옴) · `alert/prompt/confirm` 0 · 콘솔 출력 0 · 외부 URL 0 · 새 외부 요청 코드 0 |
| 보고 형식 | 변경 파일 목록 / 실행한 명령과 출력 원문(결과 폴더 경로 포함) / 못 한 것과 이유 / **확정한 값**(조각마다 아래에 적은 것) |

---

## 지시서 A — 보안 바닥: 표 · 판정 · 담당 · 프로젝트 상태 · 포트폴리오 (서버만, 화면 변경 0)

```
목표        : Q:F-12 가 기대는 표 한 벌과 판정 함수 다섯, 담당자·프로젝트 상태·포트폴리오 창구를 서버에 넣고,
              「맡기기」가 양식 응답자 신원 권한이 되던 옛 읽기 두 곳을 소유자/작성자만으로 고친다. 화면은 바뀌지 않는다.
요구사항    : Q:F-12 WK-3 · WK-12 · WK-13 · WK-14 — ../../community/ARCHITECTURE.md 23.1 · 23.2 · 23.3 · 23.6 · 23.7 · 23.9,
              SECURITY C:R-15 · C:R-16, 기획 11장 Q:T-24~28 · Q:T-33~37, ARCHITECTURE.md 13장 — 해당 절을 읽고 시작할 것.
              화면 변경 없음(DESIGN 체크 대상 아님).
수정 파일   : ★ db/migrations/2026-10-19_qf12_work.sql(새 · 추가 전용 · 되풀이 안전 — 이름의 날짜는 정렬용, 지금 마지막 2026-10-18 다음) ·
              ★ db/community/schema.sql(새 설치용 같은 모양) · ★ db/community/grants.sql ·
              ★ src/community/CommunityAuthz.php(새 함수 다섯 + canSeeFormIdentity 고침) · src/community/forms_helpers.php(:111 한 곳) ·
              ★ src/Audit.php(SUMMARY_TEMPLATES 에 새 이름 등록만) ·
              ★ src/community/routes.php(COMMUNITY_ROUTES 에 아래 창구 추가 + 그 창구가 쓰는 헬퍼) ·
              src/community/handlers/pages_properties.php(assignee 키) · src/community/handlers/pages_get.php(can_assign · assignee_can_edit) ·
              src/community/handlers/assignee_candidates.php(새) · src/community/handlers/project_status.php(새) · src/community/handlers/portfolios.php(새) ·
              API.md(새 절 「업무 화면 창구 (Q:F-12)」) ·
              tests/community/qf12_floor_http.py(새) · tests/community/memories_harness.py(choices · migrations 두 줄) · tests/catalog.json(새 시험 등록)
              여기 없는 파일은 만들거나 고치지 않는다. 특히 public/index.php · src/Authz.php · src/Auth*.php · src/Http.php · src/Db.php · deploy/** 는 손대지 않는다.
금지 패턴   : 위 「공통」 표. 더해서: page_values 로 담당을 쓰지 않는다(담당 쓰기는 properties 창구 한 곳) · 마이그레이션에서 기존 행을 지우거나 고치지 않는다
              (옛 assignee_id 값 그대로) · 포트폴리오 공간이 team 인지 핸들러에서 확인하되 비교는 CommunityAuthz 판정 안에서.
완료 기준   : 아래 A-7 시험 전부 + tests/stage89_cross_regression/run.py 통과 + 양식 응답 기존 시험(tests/community 에서 form 을 다루는 것 — 이름을 찾아 보고)
              전과 같은 수 + scripts/security_lint.sh 0건 + php -l(바뀐 PHP 전부) + git diff --check +
              마이그레이션 2회 적용 멱등 + 새 설치(schema.sql)와 마이그레이션 뒤 모양 SAME(지금 관례대로 비교한 명령과 결과).
보고 형식   : 「공통」 표의 보고 형식. 확정한 값: 감사 이름·거부 코드 원문 · 창구 경로와 응답 JSON 모양 · 알림 kind · 옛 assignee 읽기를 고친 줄.
```

### A-1 표 — ARCH 23.2 그대로
- `databases.purpose`(`project`|`my_tasks`) · `databases.color`(`peach`|`lilac`|`mint`|`sand`) · `my_tasks_projects` · `project_status_updates` · `portfolios` · `portfolio_items` · `portfolio_events` · `notifications.kind` 에 `assigned`(누적 CHECK 의 다음 판 — 지금 CHECK 정의를 찾아 같은 방식으로).
- 권한: `project_status_updates` · `portfolio_events` = **SELECT, INSERT 만** · `portfolios` = SELECT, INSERT, UPDATE(DELETE 없음) · `portfolio_items` = SIUD · `my_tasks_projects` = SELECT, INSERT, DELETE. 시퀀스 USAGE 는 지금 관례대로.
- `my_tasks_projects` 는 A 에서 표만 만든다(채우는 것은 B).

### A-2 판정 — `CommunityAuthz` 새 함수 다섯(ARCH 23.3 · 23.7 표 그대로)
- `decidePageAssign($u,$row)` — 게시판 글 `space_governed` · 그 밖 `decidePageChange` 결과 그대로.
- `decideAssignTarget(?$target,$rowForTarget)` — 활성 · 쓰기 자격(`Authz::canCommunityWrite`) · 대상 기준 다시 읽은 행에 `decidePageChange` 허용. 거부 코드 `inactive` · `not_writer` · `assignee_cannot_edit`. 허용 코드 `can_edit`.
- `decidePortfolioRead` · `decidePortfolioManage` · `decidePortfolioAdd` — 23.7 표. `isTeamMember`(:1508) 재사용. Admin 은 읽기만(`admin_all`).
- `canSeeFormIdentity`(:134-136)와 `forms_helpers.php:111` 의 `assignee_id ??` 를 **소유자(개인 공간)/작성자(그 밖)만**으로. 이 두 곳 말고 `assignee_id` 를 판정에 쓰는 곳이 있는지 `grep` 한 명령과 결과를 보고에.

### A-3 담당 창구 — ARCH 23.3 「창구」 그대로
- `PATCH /api/pages/{id}/properties` 의 허용 키(`pages_properties.php:49`)에 `assignee`(문자열 아이디 또는 `null`). 순서·한 트랜잭션·사건(`community_insert_property_event` — `prop='assignee'`, 값은 아이디)·감사 `page.assign`·알림(`community_notify($target,'assigned',…)`, 커밋 뒤) — 23.3 대로. 없는 아이디·형식 틀림은 400(같은 문구), 판정 거부는 403(`decidePageAssign`) 또는 400(`decideAssignTarget` — 「이 사람에게는 맡길 수 없어요」 같은 문구 하나, 이유 코드는 감사에만).
- `GET /api/pages/{id}/assignee-candidates?q=` — `mention_candidates.php` 틀(활성 20 → 판정 통과 10). 호출자 `decidePageAssign` 거부면 403. 정책 `community.write`.
- `pages_get.php` 응답에 `can_assign`(요청자) · `assignee_can_edit`(지금 담당이 있을 때만, 담당 기준 `decideAssignTarget` 통과 여부). 기존 `assignee` 객체 모양은 그대로.

### A-4 프로젝트 상태 창구 — ARCH 23.6
- `POST /api/projects/{id}/status {status, note}`(정책 `community.write`) · `GET /api/projects/{id}/status?limit=`(정책 `community.read`, 최대 50). `{id}` = 데이터베이스 페이지 `public_id`, `databases.purpose='project'` 가 아니면 400. 감사 `project.status`. 고치기·지우기 창구 없음.
- A 에는 프로젝트를 만드는 길이 없으므로 시험은 fixture 가 `databases.purpose='project'` 를 SQL 로 준비한다(시험 준비에서만).

### A-5 포트폴리오 창구 — ARCH 23.7
- 창구 8개(23.7 「창구」, 화면 `GET /portfolios/{id}` 는 **D** 몫이라 A 에서는 넣지 않는다). 공간 식별자는 지금 공간 창구(`community_space_gate`, `routes.php:1101-1132`)가 받는 것과 같은 것.
- 항목 질의 = `community_visible_pages_where(보는 사람)` 안에서만 · 총수 · 상태별 수 · 진행률(그 DB 행 중 보는 사람이 볼 수 있는 것의 done/전체) · 최근 상태(`project_status_updates` 마지막 줄) · 프로젝트 담당(소유자/작성자 표시 이름).
- 못 보는 포트폴리오·없는 포트폴리오는 **같은 404 문구**. 한도: 팀 공간당 50 · 포트폴리오당 100(넘으면 400). 변경마다 `portfolio_events` + 감사 `portfolio.*`.

### A-6 감사 · 알림
- `Audit::SUMMARY_TEMPLATES` 에 23.9 표의 이름 · 결과 · 이유 조합을 **먼저** 등록(빠지면 500). `portfolio.read` 는 `admin_all` 허용과 거부만 기록.
- 알림 kind `assigned` — 알림 목록 창구(`notifications_list.php`)가 모르는 kind 를 걸러 내는지 확인하고, 거르면 `assigned` 를 허용 목록에 더하는 것은 **D** 몫으로 보고에 적는다(A 에서 그 파일을 고치지 않는다).

### A-7 시험(완료 기준) — `tests/community/qf12_floor_http.py`(새 — 격리 틀)

fixture: 팀 X 구성원 A·B(쓰기) · 팀 Y 구성원 C · 손님 G · Admin M · 비활성 I. A 의 개인 공간 비공개 페이지 P1 · A 가 B 에게 「보기」 공유한 P2 · 「편집」 공유한 P3 · 팀 X 공간 페이지 T1 · 양식 데이터베이스 F(소유 A, 응답 1건).

| 시험 | 확인 |
|---|---|
| Q:T-24 | A 가 T1 을 B 에게 맡김 → 200 · 사건 1행(`assignee`, 아이디) · 알림 `assigned` 1 · 감사 `page.assign|allow` 1 / B(보기 공유)가 P2 맡기기 → 403 / G → 403 / M 이 P1 맡기기 → 403 · 감사 deny 코드 원문 |
| Q:T-25 | A 가 P2 를 B 에게(보기만) → 400 · P1 을 C 에게(못 봄) → 400 · G · I 에게 → 400 / P3 를 B 에게(편집) → 200 / 후보 창구 `?q=` 에 B(P2)·C·G·I 0, B(P3) 1 |
| Q:T-26 | P3 를 B 에게 맡긴 뒤 A 가 B 의 공유 회수 → B 의 `GET /api/pages/P3` 403/404 · A 의 `GET` 에 `assignee_can_edit=false` · B 알림 목록 재판정으로 0(지금 `notifications_list.php:88-99` 동작 그대로인지) |
| Q:T-27 | F 의 행 담당을 B(편집 공유)로 SQL 준비 → B 가 응답자 신원 보기 → 전처럼 불가 · 새 응답 → `form_response` 알림은 A 에게만 |
| Q:T-28 | 앱 계정으로 `page_property_events` · `project_status_updates` · `portfolio_events` UPDATE/DELETE → permission denied 원문 3 · `portfolios` DELETE → denied |
| Q:T-33 | 프로젝트 DB(팀 X) 상태: A 200 / G 403 / 값 밖 400 / 281자 400 / 프로젝트 아닌 DB 400 / `GET` 은 마지막 줄이 지금 상태 |
| Q:T-34 | 팀 X 포트폴리오 PF: A·B 200 · C 404 · G 404 · M 200 + 감사 `portfolio.read|allow|admin_all` 1 · 없는 id 404 문구 동일 |
| Q:T-35 | A 가 자기 **비공개** 프로젝트 DB(개인 공간)를 PF 에 넣음 → A 응답엔 있음 · B 응답에 그 줄 0 · 총수·상태별 수에 안 셈 · B 응답 원문에 그 제목·`public_id` 0 |
| Q:T-36 | M 이 C 의 팀 Y 프로젝트를 PF 에 넣기 → 403(Admin 은 관리 불가) · A 가 관리자 열람으로만 보이는 것을 넣는 경우가 fixture 로 안 되면 그 이유를 보고 · 프로젝트 아닌 표 400 · C 가 PF 에 넣기 404 · 넣기/빼기/이름/지우기 → `portfolio_events` 4종 + 감사 |
| Q:T-37 | 프로젝트 DB 행 5개 중 2개가 B 에게 안 보이게(행을 비공개 하위로 — fixture 가 가능한 방식) → B 의 진행률 분모·분자가 볼 수 있는 행만 · 안 되면 그 이유를 보고 |
| 마이그레이션 | 2회 적용 멱등 · 기존 `assignee_id` 값 보존(fixture 에 옛 값 1건 넣고 확인) · 새 설치 = 마이그레이션 모양 SAME |
| 회귀 | `stage89_cross_regression` · 양식·알림·멘션 기존 시험(찾아서 이름 보고) 전과 같은 수 |
| 정적 | `scripts/security_lint.sh` · `php -l` · `git diff --check` 0건 |

A 가 끝나면 **멈춘다** — 메인 세션이 독립 검토(CLAUDE.md 3장, 보안 바닥 — 3.1 예외)를 한 뒤 B 를 시작한다.

### A-8 검토 반영 — 재지시 1 (2026-10-07 · 메인 독립 검토 `SECURITY.md` 7장)

```
목표        : 독립 검토의 보정 둘을 고친다 — RV-A1 포트폴리오가 관리자 열람 길이 되지 않게, RV-A2 속성 창구의 거부 감사가 DB 에 남게.
요구사항    : ../../community/ARCHITECTURE.md 23.7(고친 판 — 항목·진행률 = community_search_pages_where), SECURITY C:R-16 · S-07(감사) — 해당 절을 읽고 시작할 것.
수정 파일   : ★ src/community/routes.php(community_portfolio_items 의 조건절 두 곳만) · src/community/handlers/pages_properties.php(거부 두 갈래만) ·
              tests/community/qf12_floor_http.py(시험 추가) · API.md(포트폴리오 절 한 문장 — 「관리자도 자기 몫으로 볼 수 있는 프로젝트만」)
              여기 없는 파일 금지.
금지 패턴   : 「공통」 표. 판정 함수·마이그레이션·권한은 고치지 않는다.
완료 기준   : 아래 시험이 **고치기 전 판(b9e3c2c)에서 실패하고 고친 판에서 통과**함을 둘 다 보인다 + qf12_floor_http.py 전부 + security_lint 0 + php -l + git diff --check.
보고 형식   : 「공통」. 확정한 값: 고친 줄 · 고치기 전/뒤 시험 출력 원문.
```

| # | 고칠 것 | 시험(qf12_floor_http.py 에 추가) |
|---|---|---|
| RV-A1 | `community_portfolio_items` 의 항목·진행률 질의 조건절을 `community_visible_pages_where` → `community_search_pages_where`(바인드 이름이 다르면 맞춤) | A 가 자기 **비공개** 프로젝트를 PF 에 넣은 뒤: ① Admin M(팀 X 아님 — `admin_all` 로 PF 를 읽는 사람) ② 팀 X 구성원이기도 한 Admin(fixture 에 하나 추가 — 시험 준비로만) 두 사람의 `GET /api/portfolios/{PF}` 응답에 그 프로젝트 줄 0 · 총수·상태별 수에 안 셈 · 응답 원문에 제목·`public_id` 0. 진행률: 그 사람이 볼 수 없는 행이 분모에 안 들어감 |
| RV-A2 | `pages_properties.php` 의 `page.read` 거부(:130 근처)와 `page.property` 거부(:205 근처) — `Audit::deny` **앞에** `$db->rollback()` (다른 거부 갈래와 같은 모양) | ① 볼 수 없는 사람이 `PATCH …/properties {status}` → 403 뒤 `audit_logs` 에 `page.read|deny|not_owner` **DB 행 +1** ② 볼 수는 있으나 고칠 수 없는 사람(사내 공개 페이지의 다른 사람) → 403 뒤 `page.property|deny|not_owner` DB 행 +1 · 페이지 값 불변 |

A-8 이 끝나면 멈추지 않고 **지시서 B** 로 이어 간다(A-8 은 커밋 하나로 따로).

---

## 지시서 B — 서버: 내 작업 · 「오늘」 · 프로젝트 만들기 · 「내 할 일」 · 섹션 묶기 · 담당 칸

```
목표        : 「내 작업」 창구, 「오늘」에서 공지를 빼고 옛 주소 넘김, 프로젝트 만들기(preset)·「프로젝트로 쓰기」, 「내 할 일」 자동 생성,
              보드·목록의 섹션 묶기(group_by), 데이터베이스 내장 담당 칸을 넣는다. 화면 변경은 데이터베이스 보드의 묶기 따르기(database.js)뿐.
요구사항    : Q:F-12 WK-4 · WK-5 · WK-8 · WK-16 — ../../community/ARCHITECTURE.md 23.4 · 23.5 · 23.8(옛 주소 한 줄), SECURITY C:R-17,
              기획 11장 Q:T-29~32, A 의 판정·표(검토 반영본) — 해당 절을 읽고 시작할 것.
              database.js 보드가 바뀌므로 DESIGN 2.20 「보드」 행 + 6장 체크(보드 화면 360·412·820·1440 가로 넘침 0).
수정 파일   : ★ src/community/routes.php(창구 추가 · /today 넘김) · src/community/handlers/my_tasks.php(새) · src/community/Briefing.php ·
              src/community/handlers/today_page.php(308 넘김으로) · src/community/handlers/pages_create.php(preset:"project" · 「내 할 일」) ·
              src/community/handlers/database_schema.php(「프로젝트로 쓰기/표로 쓰기」 동작) · src/community/DbProps.php(BUILTINS assignee · cleanView group_by) ·
              src/community/DbViews.php · public/assets/community/database.js(drawBoard·moveCard 가 group_by 를 따름 — 최소 변경) ·
              API.md(A 절에 이어서) · tests/community/qf12_server_http.py(새) · tests/community/memories_harness.py(choices 한 줄) · tests/catalog.json
              여기 없는 파일 금지. A 의 ★ 파일(CommunityAuthz · Audit · 마이그레이션 · schema · grants)은 고치지 않는다 — 필요하면 멈추고 보고.
금지 패턴   : 「공통」 표. 더해서: 내 작업 질의는 community_search_pages_where 를 반드시 AND · 프로젝트 이름·하위 수도 볼 수 있는 것만 ·
              preset 값은 서버 상수에서만(요청에서 칸·보기 모양을 받지 않는다) · group_by 는 status 와 그 DB 의 select 칸 키만.
완료 기준   : B-5 시험 전부 + stage89 교차 회귀 + 데이터베이스 기존 시험(보드·보기·칸 — tests/community 에서 찾아 이름 보고) 전과 같은 수 +
              Briefing 기존 시험(tests/community/briefing_integration.py 등) 공지 기대값 변경 외 전과 같은 수 + security_lint 0 + php -l + node --check(database.js) + git diff --check.
보고 형식   : 「공통」. 확정한 값: /api/my-tasks 응답 모양 · 묶음 이름 · preset 의 칸·보기 원문 · group_by 저장 모양 · 308 대상.
```

- **B-1 내 작업** — `GET /api/my-tasks?done=0|1&bucket=&cursor=` · ARCH 23.4 대상·묶음·응답 그대로. 「오늘」 날짜 = Asia/Seoul.
- **B-2 「오늘」** — `Briefing.php` 의 `notice` 를 `top`·`missed` 계산에서 뺀다(`:179-195`). 홈의 공지 한 줄은 D 가 그린다 — B 는 `GET /api/my-tasks` 응답 옆이 아니라 기존 게시판 읽기 창구로 충분한지 확인하고, 모자라면 **새 창구 없이** 무엇이 모자란지 보고. `GET /today` → 308 `/community/my-tasks`(화면 `/my-tasks` 는 D 가 만든다 — B 시험은 Location 머리만 본다).
- **B-3 프로젝트 만들기** — `POST /api/pages {database:true, preset:"project", …}` · 서버 상수: 섹션 select(`section`, 「00. 준비」·「01. 진행」·「02. 마무리」) · 보기 넷(목록·보드 `group_by:"section"`, 타임라인·달력 `due`) · 색(그 공간의 프로젝트 수 차례로 네 가지 순환) · `purpose='project'`. 판정은 지금 페이지 만들기 그대로. 「프로젝트로 쓰기」 = `database_schema.php` 의 새 동작(`decidePageManage`): `purpose='project'` + `section` 칸이 없으면 추가 + 기본 보기 중 없는 것 추가 · 「표로 쓰기」 = `purpose=NULL` 만.
- **B-4 「내 할 일」 · 섹션 · 담당 칸** — 빠른 추가(`POST /api/pages {my_tasks:true, title, due?}`)가 처음이면 개인 공간 최상위 비공개 프로젝트(`purpose='my_tasks'`, 제목 「내 할 일」)를 만들고 `my_tasks_projects` 에 적는다(PK 충돌 = 다시 읽기, 같은 프로젝트에 행 추가). `DbProps::cleanView` 가 board·list 보기의 `group_by`(status | select 키)를 받고, `DbViews` 가 그 값을 응답에 낸다. `database.js` 보드는 `group_by` 의 옵션 순서로 열을 그리고, 카드 옮기기는 그 select 값을 `page_values` 로 쓴다(빈 값 열 「섹션 없음」). `DbProps::BUILTINS` 에 `assignee`(읽기·거르기·정렬만 — 쓰기는 A 창구).
- **B-5 시험** — `tests/community/qf12_server_http.py`: Q:T-29(남의 비공개 작업에 `assignee_id=나` SQL 준비 → 0 · Admin 의 내 작업에 남의 비공개 0 · 날짜 경계 넷 · 옛 문서(상태만 있는 내 페이지)가 「문서 · 공간」으로) · Q:T-30(공지 3·작업 0 → `top` 공지 0 · `/today` 308) · Q:T-31(preset 결과 원문 · 편집 공유자 섹션 추가 403 · board group_by 섹션 200 / 글 칸 400 / 없는 키 400 · 카드 옮기기 = 섹션 값 + 사건 `db:section`) · Q:T-32(두 요청 동시 → `my_tasks_projects` 1행 · 프로젝트 1 · 비공개 · B 가 읽기 403) · 「프로젝트로 쓰기」 두 번 = 멱등(칸·보기 중복 0). 보드 화면은 격리 틀 브라우저로 360 · 412 · 820 · 1440 가로 넘침 0 · 콘솔 0 · 카드 끌어 옮기기(또는 키보드 옮기기 — 지금 있는 방식) 1회.

B 가 끝나면 C 로 이어 간다(검토는 묶음 끝).

**B 결과(2026-10-07 · A-8 `58e4d9f` · B `650ab5c`)와 뒤 조각에 넘기는 것** — 수락 `qf12_server_http.py` 204/0 · `qf12_floor_http.py` 250/0(메인 재실행 250/0) · 회귀 직전 판과 같은 수.
- B 가 정한 동작(메인 수락 — ARCH 23.4·23.5 에 적음): 빠른 추가 행은 상태 `todo` 로 시작 · 「내 할 일」은 「표로 쓰기」 불가(400) · 「프로젝트로 쓰기」 때 같은 이름 「섹션」 칸이 있으면 400 `name_taken` · 공지는 `top`·`missed` 에서만 빠지고 `others` 에 남음(홈 공지 한 줄은 여기서).
- **C 로**: `/community/today` 308 로 실패하는 기존 시험 4 — `tests/community/briefing_integration.py:155` · `tests/stage89_cross_regression/suite.py:225` · `tests/community/messenger_window_browser.py:170·238` · `tests/community/browser_9_automation_ui.py:41` — 기대값을 `/community/my-tasks`(C 단계에선 308 `Location` 만, 화면은 D). 이미 C 의 수정 파일(기획 11장 목록)에 든다.
- **D 로**: 행 목록 응답(`database_rows.php`)에 담당 값이 없다 → D 에서 추가 · 부품 버전(`community_asset_version.php --check`)이 `database.js` 변경으로 실패 — D 끝에 한 번 다시 찍는다.

---

## 지시서 C — 셸 · 앱 나누기: 아이콘 줄 · 업무/이야기 사이드바 · 앱 목록 · 런처

```
목표        : 세 앱 공통 왼쪽 아이콘 줄을 넣고, 커뮤니티 셸을 「업무」 와 「이야기」 두 사이드바로 나누며, 앱 목록·런처·도움말·사용 안내 글을
              업무·이야기·자료실로 바꾼다. 업무 화면 본문(홈·내 작업·프로젝트·포트폴리오)은 D 몫이다.
요구사항    : Q:F-12 WK-2 · WK-10 · WK-11 · WK-15 — ../../community/ARCHITECTURE.md 23.8, DESIGN 2.18 · 2.19 · 2.9 · 2.17(메신저 단추 자리 불변) + 6장 체크리스트,
              SECURITY R-119, 기획 11장 Q:T-38 · 39 · 40 · 43 + 「기대값을 바꿔야 할 시험」 목록 — 해당 절을 읽고 시작할 것.
수정 파일   : config/apps.json · src/Apps.php(ICON_PATHS 에 work 하나) · src/views/shell_top.php · src/views/shell_bottom.php(tpl-icon 목록) ·
              src/views/moa_icon.php(필요할 때만) · public/assets/app.css(아이콘 줄 · 토큰 --rail-w/--rail-bg/--rail-fg · 960 경계) · public/assets/moa.css(.moa-space-nav 규칙 제거) ·
              public/assets/app.js(앱 전환 초점 — 목록이 셋이 되는 것만) · src/views/launcher.php · src/community/handlers/memories_page.php($shellModule='story') ·
              src/help/community.php · public/assets/usage-guide.js · src/views/moa_usage.php · wiki/community/*.json · wiki/qaredi/*.json(글만 — index.json 포함) ·
              DESIGN.md(2.18~2.19 「확인 결과」 줄만) · docs/design-guide.html(아이콘 줄 견본) ·
              기획 11장 「기대값을 바꿔야 할 시험」에 적힌 시험 파일(기대값만 — 바꾼 이유를 보고에) · tests/community/qf12_shell_browser.py(새) · tests/community/memories_harness.py(choices) · tests/catalog.json
              여기 없는 파일 금지. 라우트 표(public/index.php · src/community/routes.php)와 src/Http.php 는 손대지 않는다(WK-15 A — 주소 그대로).
금지 패턴   : 「공통」 표. 더해서: 아이콘 줄 마크업은 shell_top.php 한 곳(앱마다 따로 금지) · 새 SVG 를 화면 파일에 직접 쓰지 않는다(Apps::ICON_PATHS) ·
              앱 목록 판단을 화면에서 하지 않는다(Apps::all()) · 메신저 여는 단추 자리 변경 0.
완료 기준   : C-3 시험 전부 + 기대값을 바꾼 시험 전부 통과 + tests/community/messenger_window_browser.py(메신저 단추 화면마다 1개 — 이야기·업무 모두) +
              stage89 교차 회귀 + 카탈로그에서 covers 에 shell_top.php 가 든 시험 전부 + scripts/ui_snapshot.sh 1440·1280(격리 틀) 결함 0 + security_lint 0 + php -l + node --check + git diff --check.
보고 형식   : 「공통」. 확정한 값: apps.json 원문 · 아이콘 줄의 aria-label·글자 · 업무/이야기 사이드바 항목 순서 원문 · 바꾼 시험 기대값 목록(「원래 어긋나 있던 것」 따로).
```

- **C-1 앱 목록·런처** — `config/apps.json`: `community` → 「업무」 · 「작업 · 프로젝트 · 문서」 · 아이콘 `work`(새 — 체크 네모, DESIGN 2.18) · 새 `story` → 「이야기」 · 「스레드 · 여행 — 일상 나누기」 · `/community/memories` · 아이콘 `community` · 순서 자료실 · 업무 · 이야기. `launcher.php` 하드코딩 타일·머리 링크를 같은 이름·설명으로.
- **C-2 셸** — 아이콘 줄(DESIGN 2.18 표 그대로) · `nav.moa-space-nav` 제거 · `$shellModule` 허용 목록에 `story` · 업무/이야기 사이드바(DESIGN 2.19 — 업무의 「홈」=`/community/`, 「내 작업」=`/community/my-tasks`, 「포트폴리오」 묶음은 팀 공간 포트폴리오 목록 창구(A)로 채움 — 채우는 JS 는 D 몫이면 C 는 빈 묶음 숨김). 브랜드 글자 「모아 업무」/「모아 이야기」 · 앱 메뉴 `aria-label` 「모아 업무 — 앱 전환」 등. 「오늘 할 일」 항목 제거. 업무의 「내 휴지통」은 「더보기」 안(DESIGN 2.19 ⑧).
- **C-3 시험** — `tests/community/qf12_shell_browser.py`(격리 틀 + Chromium): Q:T-38(런처·앱 메뉴·아이콘 줄 순서·링크) · Q:T-39(`/community/?view=mine` · 공개 공유 `/community/memories/s/{token}` 로그인 없이 · 돌아갈 주소 `/community/memories…` 전과 같음) · Q:T-40(자료실 `/tbox/` · 업무 `/community/workspace` · 이야기 `/community/memories` × 1440·1280·960 줄 1개 + `aria-current` / 959·820·412·360 줄 0 · 가로 넘침 0 · 테마 여섯 × 밝게·어둡게·고대비에서 아이콘 줄 글자 대비 4.5:1 계산값 원문).

C 가 끝나면 D 로 이어 간다.

**C 결과(2026-10-07 · `57ff801`)와 D 로 넘기는 것** — `qf12_shell_browser.py` 430/0 · `messenger_window_browser` 456/0 · 교차 회귀 164/0 · 아이콘 줄 글자 대비 7.78 이상(테마 여섯 × 밝게·어둡게·고대비). 메인이 받아들인 차이(DESIGN 먼저 고침): 아이콘 줄 초점 고리 `--rail-fg`(2.18) · 「＋ 새 이야기」 연결은 `app.js` 의 `[data-story-compose]` → `window.MemoryComposer.open()` · 문서 묶음에 페이지 트리·게시판 목록(2.19) · 셸의 `briefing.js` 갈래 제거 · 이야기 셸에 알림 종·`editor.js` 없음(2.19).
- **D 가 쓸 자리**: 사이드바 「내 작업」 = 셸 키 `community-today` · 「홈」 = `community-overview` · 숨겨 둔 묶음 `#nav-work-projects`·`#nav-work-portfolios`(제목 포함, 채우는 JS 는 D) · 새로 만들기 메뉴의 `#nav-new-task`·`#nav-new-project`(지금 `hidden`).
- **D 수정 파일에 더한다**: `src/community/handlers/home_page.php`(화면 제목 「커뮤니티」 → 「내 문서」) · `src/handlers/login_page.php`(「커뮤니티로」 → 업무 이름) · `src/views/launcher.php`(본문 문구 — 「커뮤니티 둘러보기」 등을 업무·이야기로).
- **묶음 끝 검토로**: 운영 8082·운영 DB 사본이 필요해 C 가 돌리지 못한 카탈로그 시험(changelog · fetch_hosts · help_button · stage4b · design.live · q_launcher · stage7d2 · stage7d3 · qf2_search · qf3a · qf3b · dbg_u_ui · dbg_u2_ui · dbg_u3_ui · passkey · changelog_admin_only · ux_r1_ui) — 기대값을 고친 것 포함.

---

## 지시서 D — 업무 화면: 홈 · 내 작업 · 프로젝트(작업 목록 + 상세 칸) · 포트폴리오 · 새로 만들기

```
목표        : 시안 v1 대로 업무 홈(첫 화면) · 내 작업 · 프로젝트 화면(작업 목록 + 오른쪽 작업 상세 칸 · 프로젝트 머리·상태) · 포트폴리오 화면 ·
              「새로 만들기」 메뉴를 만들고, 커뮤니티 둘러보기(overview)를 없앤다.
요구사항    : Q:F-12 WK-1 · 4~8 · 12 · 13 — 기획 5.3(시안 v1 — 캔버스 링크) · DESIGN 2.19~2.22 + 6장 체크리스트 전부, ../../community/ARCHITECTURE.md 23.3~23.8,
              A·B 창구(API.md 「업무 화면 창구」), 기획 11장 Q:T-41 · 42 · 43 — 해당 절을 읽고 시작할 것.
수정 파일   : ★ src/community/routes.php(화면 라우트: GET / → 업무 홈 · GET /my-tasks · GET /portfolios/{id} — 정책 community.read) ·
              src/community/handlers/work_home_page.php(새 — overview_page.php 의 ?view= 넘김을 이어받음) · src/community/handlers/overview_page.php(삭제) ·
              src/community/handlers/my_tasks_page.php(새) · src/community/handlers/portfolio_page.php(새) · src/community/handlers/page_view.php(프로젝트 화면 · ?task=) ·
              src/community/handlers/notifications_list.php(바꿀 것 없음 — A 보고: kind 를 거르지 않는다. 화면 문구만 work.js/app.js 쪽) ·
              src/community/handlers/database_rows.php(행 응답에 담당 — `{username, display_name}` 만, 가시성 그대로) ·
              public/assets/community/work.js(새 — 홈·내 작업·작업 목록·상세 칸·포트폴리오 · type="module") · public/assets/community/database.js(프로젝트 목록 보기를 work.js 작업 목록으로 넘기는 최소 변경) ·
              public/assets/community/editor.js(상세 칸 안 본문 편집 재사용에 필요한 최소 변경 — 바꾼 함수 목록을 보고) ·
              public/assets/app.css(DESIGN 2.20~2.22 구성 요소) · src/views/shell_top.php(업무 사이드바의 프로젝트·포트폴리오 묶음 채우기 자리만) ·
              src/help/community.php · public/assets/usage-guide.js(업무 홈·내 작업 투어) · wiki/community/*.json(새 화면 안내) ·
              src/community/handlers/home_page.php(제목) · src/handlers/login_page.php(문구) · src/views/launcher.php(본문 문구) ·
              DESIGN.md(2.20~2.22 「확인 결과」 줄만) · docs/design-guide.html(작업 목록·상세 칸·진행률 견본) ·
              tests/community/qf12_work_browser.py(새) · tests/community/memories_harness.py(choices) · tests/catalog.json ·
              기대값이 바뀌는 시험(overview 를 보는 것 — usage_guide_browser.py · stage89 suite.py:225-236 · passkey.py:284-296 등, 바꾼 이유를 보고) ·
              부품 버전 다시 찍기 — `php scripts/community_asset_version.php`(찍는 명령은 그 파일 머리에서 확인) 로 import 줄이 바뀌는 `public/assets/community/*.js`(dashboard · editor · suggestions · templates · messenger · database · work)와 그 목록 파일
              여기 없는 파일 금지.
금지 패턴   : 「공통」 표. 더해서: 화면은 창구가 준 can_* 로만 단추를 숨긴다(역할 비교 0) · 진행률 폭은 CSSOM(DESIGN 2.22) ·
              상세 칸은 dialog 가 아니다(aside) · 떠 있는 단추 0(휴대폰 「작업 추가」는 목록 위) · 시안의 예시 이름을 코드·시험에 넣지 않는다.
완료 기준   : D-2 시험 전부 + `php scripts/community_asset_version.php --check` 0 + DESIGN 6장 체크리스트 전부(화면 넷 + 이야기 셸) + scripts/ui_snapshot.sh 1440·1280 결함 0 · 콘솔 0 +
              messenger_window_browser · stage89 교차 회귀 · 카탈로그에서 covers 가 이 조각 파일과 겹치는 시험 전부 + security_lint 0 + php -l + node --check + git diff --check.
보고 형식   : 「공통」. 확정한 값: 화면 주소 · 단추·안내 문구 원문(맡김 안내 · 포트폴리오 안내 · 빈 화면) · ?task= 동작 · 좁은 폭 동작 · 화면 사본 경로(1440·1199·412).
```

- **D-1 화면** — 홈(인사 · 내 작업 요약 세 칸 · 내 프로젝트 · 공지 한 줄) · 내 작업(목록·보드·달력 탭 · 묶음 · 그 자리 추가 → 「내 할 일」) · 프로젝트(머리 · 상태 쓰기 창 · 보기 탭 · 작업 목록 · 섹션 추가 · 하위 작업 펼침 · 오른쪽 상세 칸: 담당 고르기 · 맡김 안내 「작업을 맡겨도 소유자와 볼 수 있는 곳은 바뀌지 않아요. 담당자는 이 작업을 고칠 수 있는 사람 중에서 고를 수 있어요.」 · 활동 한 줄기) · 포트폴리오(안내 · 상태별 수 · 표 · 진행률 · 넣기) · 「새로 만들기」 메뉴(작업 · 프로젝트 · 문서) · 데이터베이스 메뉴 「프로젝트로 쓰기/표로 쓰기」 · 알림 문구 「○○ 님이 작업을 맡겼어요」.
- **D-2 시험** — `tests/community/qf12_work_browser.py`: Q:T-41(1440 줄 누르기 → 목록 그대로·칸만 바뀜·`?task=` · 뒤로 가기 닫힘 · 1199 목록 대신 + 「‹ 목록」 · 412 전체 화면 · 손님·보기 공유 문맥에서 쓰는 단추 0) · Q:T-42(화면 넷 × 360 · 412(터치·마우스) · 820 · 1280 · 1440 × 밝게·어둡게·고대비 가로 넘침 0 · 콘솔 0 · 누름 자리 44) · 내 작업 그 자리 추가 → 「내 할 일」 생성·목록 반영 · 담당 바꾸기 → 후보는 창구 결과만 · 포트폴리오 숨은 프로젝트 줄 0(화면에서도) · `/community/` = 업무 홈 · overview 의 `#overview-*` 0.

D 가 끝나면 **멈춘다** — 메인 세션이 묶음 독립 검토(A 검토 반영본 포함) · 회귀 · 발행(minor) · 운영 반영(반영 전 운영 옛 `assignee_id` 건수 읽기 전용 확인 → 사용자에게 보고)을 한다.
