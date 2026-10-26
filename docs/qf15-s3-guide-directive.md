# 지시서 Q:F-15 S3 — 과업 길잡이 1차 (2026-10-26)

사용자(2026-10-24): 「여러 기능이 섞여 정체성을 잃었다 — '이게 뭐야?' 없게, **질의응답처럼 기능을 보여 주며 다음 페이지 → 다음 페이지 → 완성**으로 안내하는 가이드」 · 「기획안의 권고대로 D-0~D-12 모두 진행해」 → 1단계 낱말 · 브랜드(v2.74.0 · v2.74.1) → 2단계 앞세우기 · 접기(v2.75.0 운영 2026-10-26) → (2026-10-26) 「**3단계 과업 길잡이 지시서를 써 줘.**」 → 「확인할 것」 답 「**S3-1 A · S3-2 A · S3-3 B · S3-4 권고대로. 지시서와 DESIGN 2.31 커밋해.**」
기준 문서(**정답지**): 기획 `../docs/identity-plan-2026-10-24.md` **8장**(8.1 정의 · 8.2 입구 셋 · 8.3 여정 1차 여섯 · 8.4 무엇을 만드나 · 8.6 코드 대조 36건 · **8.7 다시 대조(v2.75.0)**) · **9.1 D-10 · D-11** · **부록 B.3**(길잡이 작은 갈림길) · `DESIGN.md` **2.37**(길잡이) · 2.31(과업 카드) · 2.17(층) · 2.7 · 5장(「안내 셋」 · 「안내 카드 단추」) · 6장 · `../community/ARCHITECTURE.md` **26장**(표 · 상태 규칙 · 완성 사실 · 창구) · `ARCHITECTURE.md` 10장 끝 「마이그레이션 적용 계정」 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 수락 시험만).
보이는 모양의 기준: 1차 시안(비공개) https://claude.ai/artifact/6HTnUJCVQXd3bE1NQVhVnF — 「파일 올려서 동료에게 보여 주기」 · 「회의록 쓰기」를 눌러 볼 수 있는 모형(질문 카드 · 떠 있는 카드 · 창 안 한 줄 · 이어 하기 · 완성 카드). 시안과 DESIGN 이 다르면 **DESIGN 이 정답**이고 보고서에 적는다(시안의 단추 색 · 모형 화면은 예시).
한 줄로: **안내가 대신 누르지 않고, 사람이 진짜 단추를 눌러 서버에 결과가 생겨야 끝난다.** 새 표 하나 · 쓰기 창구 하나 · 옛 GET 확장 · `usage-guide.js` 여정 모드 · 홈 과업 카드 꼬리. 다른 앱 코드(`app.js` · `work.js` · `editor.js` · `templates.js` · `messenger.js` · `member.js`) 변경 0 · 새 id · 속성 0.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf15s3-<날짜>`**(브랜치 `feat/qf15-s3-guide` — 메인이 만든다 · 날짜는 만든 날) · 결과 **`/home/changkwon/qaredi-qf15s3-results-<날짜>/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --port 8768 --suite guide_journeys_http.py --output <결과>/http` · 같은 틀 `--suite guide_journey_browser.py --output <결과>/browser` · 단위 `php tests/community/guide_journey_unit.php` — 시작 전 `ss -ltn` 로 8768 · 8769 가 비었는지(쓰이면 8778) · 끊으면 내 것만 치움 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-15 S3: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0(메인이 한다) |
| 줄 번호 | 아래 줄 번호는 2026-10-26 main(`08ab62e` · 운영 v2.75.0) 기준 **참고**다. 고칠 자리는 **지금 글자 · 선택자**로 찾는다 |

```
목표        : 「무엇을 하고 싶으세요?」에서 과업 하나를 고르면 실제 화면을 넘어가며 진짜 단추에 테두리를 치고, 사람이 직접 눌러
              서버에 결과(작업 · 파일 · 문서 · 업무 게시판 글 · 메시지 · 초대 1건)가 생기면 완성 카드로 끝나는 길잡이 1차(여정 여섯)를 만든다.
              입구 셋(모아 홈 과업 카드 · 「? 사용 안내」 첫 카드 · 첫 로그인 제안)은 질문 하나로 묶고, 옛 「사용 안내」 투어는 「이 화면 사용 안내」로 그대로 둔다.
요구사항    : 기획 8장(8.1~8.7) · 9.1 D-10 · D-11 · 부록 B.3 · DESIGN 2.37 · 2.31 「과업 카드」 · 2.17 · 2.7 · 5장 · 6장 · community ARCH 26장 ·
              ARCHITECTURE 10장 끝 「마이그레이션 적용 계정」 · CLAUDE.md 4장(5대 방어) — 읽고 시작할 것.
              새 클래스는 DESIGN 2.37 「새 클래스 · 토큰」 줄의 것만 · 새 색값 0(대체 색값 포함) · 새 토큰 --z-guide 하나 · 아래 「확인할 것」 넷은 사용자 답(모두 권고) 그대로.
수정 파일   : 보안 핵심(db/**): db/migrations/2026-10-27_qf15_s3_guide_journeys.sql(새) ·
                                 db/community/grants.sql(usage_guides 블록이 든 DO 블록 안에 IF 블록 하나 더함 — 다른 줄 0)
              새 파일: src/community/GuideJourney.php · src/community/handlers/guide_journeys_api.php ·
                       tests/community/guide_journey_unit.php · tests/community/guide_journeys_http.py · tests/community/guide_journey_browser.py · docs/qf15-s3-report.md
              고치는 파일: src/community/routes.php(라우트 표 한 줄) · src/community/handlers/usage_guide_api.php(GET 확장) ·
                       public/assets/usage-guide.js · public/assets/usage-guide.css · public/assets/app.css(:root 토큰 한 줄) ·
                       src/views/usage_guide.php(aria-label 한 줄 · data-app-base 속성 하나) · src/views/launcher.php(과업 카드 줄 — 주소 꼬리 · 보이기 조건 · 맨 위 이어 하기 줄)
              시험 등록: tests/community/memories_harness.py(choices 에 둘 · migrations 목록 끝에 새 파일 · sql_file() 의 소유자 갈음 목록에 새 파일) · tests/catalog.json(셋) ·
                       tests/upgrade/flows.json(동선 한 줄 — 그 파일 형식에 맞을 때만)
              바뀐 동작을 단언하던 옛 시험: 「기대값만 바꿀 옛 시험」 표의 그 줄만(다른 단언 0 · 그 시험은 돌리지 않는다 — 밤 회귀)
금지 패턴   : CLAUDE.md 2장 공통(SQL 문자열 결합 · innerHTML · 사용자 입력이 든 파일 경로 · 외부 요청 · 역할 판단을 Authz 밖에서 · 요청의 role 값 · 브라우저 HTML 저장) ·
              역할 이름 비교(화면 · PHP 어디서도 'Admin' 'Guest' 문자열을 견주지 않는다 — Authz 함수 값만) · 요청 값으로 자격이나 user_id 정하기 ·
              안내가 앱 단추를 누르거나 폼을 제출하거나 창을 열고 닫기(예외 하나 — message-send 첫 단계의 메신저 창 열기) · 진행 카드를 <dialog> 로 ·
              진행 카드가 그릴 때 focus() · Tab 가둠 · 인라인 style · 새 색값 · 숫자 z-index · DESIGN 에 없는 클래스 ·
              UsageGuide.php · community.usage_guides 표 · 옛 PATCH /community/api/me/guide 의 동작 · 옛 투어 10맥락의 글과 선택자 바꾸기 ·
              src/Authz.php · src/community/CommunityAuthz.php · src/Audit.php · public/index.php(재인증 표) · deploy/** · db/schema.sql · db/community/schema.sql ·
              COMMUNITY_API_TOKEN_ROUTES · 다른 앱 JS(app.js · work.js · editor.js · templates.js · messenger.js · member.js · moa.js) · git add -A.
완료 기준   : 수락 시험 셋(guide_journey_unit.php · guide_journeys_http.py · guide_journey_browser.py — 아래) 전부 통과 ·
              tests/community/usage_guide_browser.py(옛 목업 — 고치지 않고) 통과 ·
              정적: scripts/security_lint.sh 0 · php -l(바꾼 PHP 전부) · node --check(바꾼 JS 전부) · php scripts/wiki_check.php 0 · git diff --check ·
                    마이그레이션을 빈 합성 DB 에 두 번(격리 틀이 두 번 돈다) 오류 0 · public/assets/community/ 변경 0(→ community_asset_version 해당 없음)
보고 형식   : docs/qf15-s3-report.md — 변경 파일 / 수락 시험과 결과(명령 · 출력 꼬리 원문) / 기대값을 바꾼 옛 시험(파일 · 줄 · 전 → 후) /
              여섯 여정 단계 표(실제로 쓴 선택자 · 글 · 넘김 신호 — 아래 E 와 다르면 왜) / 못 한 것 / DESIGN · 지시서와 다르게 한 것 / 스크린샷 경로. 짧게.
```

## 확인할 것 — **사용자 답 2026-10-26: 「S3-1 A · S3-2 A · S3-3 B · S3-4 권고대로」**(모두 권고 — 이 지시서는 그 값으로 썼다)

기획의 열린 질문 Q-A ~ Q-Q 가운데 D-10 · D-11 · 9.2(2단계 답) · 부록 B.3 · 기획 8.4(확정된 기획 v1.0 의 「무엇을 만드나」)로 풀린 것(Q-A 이름 · Q-B 범위 · Q-C 표 · Q-D 사람 확인 · Q-E 런처 안내 단추 · Q-F 어둡게 · Q-G 손님 · Q-H 층 · Q-J 「나중에」 · Q-K · Q-L 메신저 예외 · Q-N 초대 전 준비)은 뺐다. Q-Q(재인증 화면 과업의 경유지)는 업무 홈 경유로 썼다(자료실 경유와 사람이 보는 차이 0). 남은 것(Q-I → S3-1 · Q-M → S3-2 · Q-P → S3-3 · Q-O → S3-4)과 이번 대조(기획 8.7)에서 새로 드러난 것을 넷으로 묶었다.

| # | 묻는 것 | 권고 | 다른 길 | 바뀌는 곳 |
|---|---|---|---|---|
| **S3-1** | **「공지 올리기」 카드 · 과업을 누구에게** — 대조로 새로 드러남: 서버는 「공지」 종류 게시판 쓰기를 **관리자에게만** 연다(`CommunityAuthz::decideBoardWrite` — `board_kind = 'notice'` 이면 `Authz::canAdmin`). 지금(v2.75.0) 홈 카드와 설계는 쓰기 자격이 있는 직원에게도 이 카드를 보여, 직원은 「쓸 자격이 없어요」로 끝난다 | **A 공지 게시판에 쓸 수 있는 사람에게만**(지금 규칙상 관리자 — 판정은 서버 함수 `Authz::canAdmin`, 공지 쓰기 규칙과 같은 함수) — 직원 카드 넷(업무 홈 · 자료실 · 회의록 · 한마디) · 관리자 여섯 · 손님 둘. 공지 게시판이 여럿이면 창구 순서(공지 먼저 · 활성 먼저 · 번호 순) 첫 하나 | B 직원에게도 그대로 두고, 고르면 「이 게시판엔 글을 쓸 자격이 없어요 — 관리자에게 물어보세요」로 끝 · C 직원에게는 「게시판에 글 올리기」로 바꿔 쓸 수 있는 업무 게시판으로(없으면 우리 공간 — 2차 「우리 공간에 글 쓰기」와 겹치고 여정 하나 더) | `GuideJourney::allowed()` 의 notice-post 한 줄 · `launcher.php` ④ 줄 조건 · DESIGN 2.31 ④ · 수락 [http] ② · [browser] ② · 옛 시험 `home_front_browser` 상수 줄 |
| **S3-2** | **홈 「동료에게 한마디」 카드** — 2단계(Q2 A)에서 홈에서 메신저 창을 바로 연다. 홈에는 안내 스크립트가 없어(B.3 「홈 「? 사용 안내」 1차엔 없음」) 그 자리에서 길잡이를 시작할 수 없다 | **A 길잡이 시작** — 업무 홈 `/community/?guide=message-send` 로 가서 안내가 「다음」 한 번에 메신저 창을 열고 동료 고르기 → 한 줄 → 「보내기」 → 완성까지 | B 지금처럼 홈에서 창만 바로 연다(빠름 — 이 과업의 길잡이는 다른 화면 「? 사용 안내」에서만) | `launcher.php` ⑤ 줄(창 열기 속성 ↔ 링크) · DESIGN 2.31 ⑤ · 수락 [browser] ② · ⑨ · 옛 시험 `home_front_browser` :545 · :560-566 |
| **S3-3** | **길잡이 화면 시안을 구현 전에 한 번 더 볼지** — 1차 시안은 PC 모양(질문 카드 · 떠 있는 카드 · 창 안 한 줄)만 그렸다. 폰 자리(30dvh · 접기 칩) · 메신저 옆으로 비킨 카드는 시안이 없다 | **B 시안 다시 0** — DESIGN 2.37 규칙(폰은 지금 「사용 안내」 투어의 자리 규칙 그대로)으로 구현하고, 검토 때 스크린샷 여섯(PC 진행 카드 · 창 안 한 줄 · 메신저 옆 카드 · 질문 카드 · 폰 메신저 시트 · 폰 작업 칸)을 관문 ③ 전에 보인다 | A 구현 전에 시안 v2(위 여섯 컷 · 반나절) 승인 | 순서만(A 면 지시서를 시안 승인 뒤에 넘긴다) |
| **S3-4** | **완성 기록을 성공 지표로 쓸지 · 처리방침** — 새 표는 「누가 언제 어느 과업을 시작 · 완성했나」를 남긴다 | **1차는 지표로 쓰지 않는다**(D-12 지표 「동료 3명 첫 파일 · 2주 안 재방문」은 지금처럼 파일 · 로그인 기록으로) · 처리방침 그대로(제2조 「자동으로 생기는 것: 접속 · 이용 기록」 — 옛 사용 안내 표와 같은 취급 · 탈퇴 정리 대상 아님) | 지표로 쓴다(여정별 완성 수 · 멈춘 화면 — 집계 SQL 만 · 처리방침에 보존 기간 한 줄을 먼저 더함 · 새 판 동의) | 「바꾸지 않는 것」 한 줄 · B 가 바뀌면 처리방침 판 고침이 먼저 |

## 바꿀 것

### A. 표 — 마이그레이션 · 권한 블록 (보안 핵심 `db/**` — community ARCH 26.2)

새 파일 `db/migrations/2026-10-27_qf15_s3_guide_journeys.sql`(지금 마지막은 `2026-10-26_*` — 그 뒤 날짜):

- 머리 주석: 무엇을(표 하나 · 행 · 권한) · 왜(Q:F-15 ③ · 옛 `usage_guides` 는 사람당 한 줄이라 과업별 시작 시각 · 완성 이력을 담지 못함) · 적용 계정 · 되풀이 안전 · 되돌리는 길(코드를 되돌린 뒤 사용자가 정하면 `DROP TABLE community.guide_journeys` — 다른 표 영향 0 · 쌓인 진행 기록은 사라짐).
- 모양: `\set ON_ERROR_STOP on` · `BEGIN;` · **트랜잭션 첫 문장 `SET LOCAL ROLE tbox_owner;`**(새 표 소유자 tbox_owner — 옛 안내 표가 postgres 소유로 만들어져 R-120 을 낳은 것의 재발 방지 · 선례 `2026-10-11_product_spaces.sql`) · `SET LOCAL lock_timeout = '5s';` · `CREATE TABLE IF NOT EXISTS community.guide_journeys (…)` — 칸 · CHECK · 열쇠는 **community ARCH 26.2 표 그대로** · `CREATE UNIQUE INDEX IF NOT EXISTS guide_journeys_one_active ON community.guide_journeys (user_id) WHERE status = 'active';` · 권한 `DO $$ … $$`(역할이 있을 때만 — `tbox_app` SELECT · INSERT · UPDATE · `tbox_backup` SELECT · DELETE 는 누구에게도 0) · `COMMIT;`. 시퀀스 0 · 함수 0 · 다른 표 0 · 행 0.
- 두 번 돌려도 같다(`IF NOT EXISTS` · GRANT 는 되풀이 안전).
- `tests/community/memories_harness.py`: migrations 목록 **끝**에 이 파일 · `sql_file()` 의 소유자 갈음 목록(지금 `2026-10-01_community_suggestions.sql` 등 넷 — `SET LOCAL ROLE tbox_owner;` → `tbox_testdb`)에 이 파일을 더한다. 합성 DB 에는 `tbox_owner` 가 없다.

`db/community/grants.sql`: usage_guides 블록이 든 DO 블록(`IF to_regclass('community.usage_guides') …` 바로 아래, 같은 `END $$;` 안)에

```
 IF to_regclass('community.guide_journeys') IS NOT NULL THEN
  GRANT SELECT,INSERT,UPDATE ON community.guide_journeys TO tbox_app;
  GRANT SELECT ON community.guide_journeys TO tbox_backup;
 END IF;
```

하나만 더한다. 빠지면 grants.sql 을 다시 돌린 뒤에도 백업 계정 권한이 없고, `tbox_backup` 이 빠지면 pg_dump 가 통째로 실패한다(설계 5.2 경고).

### B. `src/community/GuideJourney.php` (새 — `UsageGuide.php` 꼴 · 머리 주석 「표시 선호 — 권한 · 내용에 영향 0」)

| 이름 | 하는 일 |
|---|---|
| `JOURNEYS` | `['task-add' => 1, 'file-share' => 1, 'minutes-write' => 2, 'notice-post' => 3, 'message-send' => 1, 'invite-colleague' => 1]` — 여정 id → 화면 수. 여기 없는 id 는 어디서도 받지 않는다. `usage-guide.js` 의 `journeys` 키와 같아야 한다(수락 [static]) |
| `ready(): bool` | `to_regclass('community.guide_journeys') IS NOT NULL` |
| `resume(array $u): ?string` | 홈 맨 위 「하던 일 이어 하기」 줄(G)용 — 내 `active`, 없으면 `updated_at` 가장 최근 `paused` 하나의 여정 id(그 여정이 `allowed` 일 때만) · Prepared · 내 행만 · LIMIT 1 |
| `allowed(array $u): array` | 여정 id → bool, **Authz 함수 값만**(역할 이름 0): `task-add` · `minutes-write` = `Authz::canCommunityWrite($u)` · `file-share` = `Authz::uploadableLevels($u) !== []` · `notice-post` = `canCommunityWrite && Authz::canAdmin($u)`(S3-1 A — 공지 쓰기 규칙 `decideBoardWrite` 와 같은 함수 · 주석에 그 자리를 적는다) · `message-send` = `canCommunityWrite && ($u['acting'] ?? false) !== true`(런처 `$launcherMessenger` · `messenger_window.php` 와 같은 판정) · `invite-colleague` = `Authz::canManageUsers($u)`. 런처와 GET 이 **이 함수 하나**를 쓴다 |
| `validate(array $body): array` | 키는 정확히 `{journey, status, page, step}` + 선택 `{restart}` — 그 밖 키(`user_id` · `confirm` 포함) 400 · `journey` 는 `JOURNEYS` 키 · `status` 는 `active` `paused` `completed` `dismissed` · `page` 는 int 이고 `0 ≤ page < 화면 수` · `step` 은 int 0~30 · `restart` 는 bool · 문자열 숫자 400. 실패는 `InvalidArgumentException` |
| `state(array $u): array` | `{journeys: {active, paused, completed}, rows: n}`(`paused` · `completed` 는 `updated_at` · `completed_at` 최근 순) — 내 행만 한 번 SELECT(`WHERE user_id = :uid` 바인딩 · `uid` 는 세션 사용자 id 만) · `active` 가 있으면 `fact()` 하나 더 · 모양은 community ARCH 26.5 |
| `fact(int $uid, string $journey, string $since): array` | `{ok: bool, ref: ?string}` — 여섯 질의(community ARCH 26.4 표 그대로) · 전부 Prepared · `created_at ≥ CAST(:since AS timestamptz)`(`sent_at` 은 메시지) · EXISTS 또는 `ORDER BY created_at DESC LIMIT 1` · 문서 · 업무 게시판 글은 가장 최근 `public_id` 를 `ref` 로 |
| `save(array $u, array $body): array` | 한 트랜잭션(`Db::begin` · `commit` · 예외 때 `rollback`): ① `validate` ② `allowed($u)[journey]` 거짓이면 403 ③ `SELECT … FROM community.guide_journeys WHERE user_id = :uid FOR UPDATE`(내 행 전부 — 많아야 여섯) ④ community ARCH 26.3 표대로 바꾼다 — **시작 시각은 새 행 · `completed`/`dismissed` → `active` · `restart: true` 일 때만 `now()`**(화면을 오가는 `active` 저장 · 홈 카드를 다시 누른 `active` 는 그대로) ⑤ `active` 로 바꾸면 같은 사람의 다른 `active` 를 `paused` 로 ⑥ `completed` 는 지금 행이 `active`/`paused` 이고 `fact(uid, journey, started_at).ok` 가 참일 때만 — 거짓 400(「아직 결과가 없어요 — 위 단계를 하고 다시 보기를 눌러 주세요」) ⑦ 부분 유니크 위반(`23505` — 두 탭이 동시에 시작)은 한 번 다시, 또 나면 409(「다른 창에서 안내를 시작했어요. 다시 열어 주세요.」) ⑧ 끝에 `state($u)` 를 돌려준다. `confirmed_by_user` 는 1차에서 늘 거짓 |

시각은 모두 DB `now()`(PHP 시계 0) · 감사 0(옛 안내 창구와 같음 — 표시 선호) · SQL 은 heredoc + 바인딩만.

### C. 창구 (community ARCH 26.5)

- `src/community/handlers/usage_guide_api.php` — GET 만 고친다(PATCH 갈래 · 503 문장 그대로): `GuideJourney::ready()` 면 응답에 `journeys`(state 의 `journeys`) · `can`(`allowed($user)`)을 더하고, `rows > 0` 이면 `guide.offer = false` 로 덮는다(`UsageGuide.php` 무변경). 표가 없으면 `journeys: null` · `can: null`. 요청 수 +0.
- 새 `src/community/handlers/guide_journeys_api.php` — 머리 주석 「Session-only community.read route. Dispatcher enforces CSRF on PATCH. Not a token route.」 · `require_once __DIR__ . '/../GuideJourney.php'` · `Cache-Control: private, no-store` · 표 없으면 503(「안내를 준비 중이에요. 잠시 뒤 다시 열어 주세요.」) · `GuideJourney::save($user, Http::jsonBody(1024, 3))` · 400 은 `Http::MSG_BAD_REQUEST`(완성 거짓만 위 문장) · 403 · 409 · 응답 `{journeys, can, csrf_token}`.
- `src/community/routes.php` 라우트 표에 한 줄 — `['PATCH', '/api/me/journeys', 'guide_journeys_api.php', 'community.read'],`(옛 `/api/me/guide` 두 줄 바로 아래). `COMMUNITY_API_TOKEN_ROUTES` 에 넣지 않는다 — 토큰 헤더만 오면 세션이 없어 401. GET · DELETE 줄 0.

### D. 화면 — `public/assets/usage-guide.js` 여정 모드 (DESIGN 2.37)

옛 투어(`tours` · `step()` · `start()` · `render()` · `makeDialog()` · `addModalHelp()` · `messengerWindow()`)는 **동작 그대로**다. 여정은 그 옆에 둔다.

1. **켜짐 조건** — GET 응답에 `journeys` 와 `can` 이 객체로 있을 때만 여정 기능이 켜진다. 없거나 `null`(표 없음 · 옛 응답을 흉내 내는 목업 셋 — `usage_guide_browser.py` · `messenger_resize_browser.py` · `moa_local_assist.py`)이면 **지금과 한 글자도 다르지 않게** 돈다(제안 창 글자 · 「? 사용 안내」 → 투어). 수락 [fallback] 이 그대로 통과해야 한다.
2. **로드 분기**(지금 `load().then` 자리) — ① `journeys.active` 이어 하기(7) ② `offer` → 질문 카드(자동 제안) ③ 옛 투어 `active` 이어 보기. 열린 showModal 창 · 입력 칸 초점 조건(지금 :282)은 ② 에 그대로.
3. **주소 꼬리 `?guide=`** — 스크립트 시작 때 `URLSearchParams(location.search).get('guide')` 를 한 번 읽는다. 값이 `journeys` 키이고 `can[값]` 이 참일 때만: 그 행이 `active`/`paused` 면 `{journey, status:'active', page:<저장 page>, step:<저장 step>}`(restart 없음 — 카드를 다시 눌러도 시작 시각 그대로), 없거나 `completed`/`dismissed` 면 `{journey, status:'active', page:0, step:0}` 을 PATCH. 어느 쪽이든 꼬리를 지운다 — `history.replaceState(history.state, '', location.pathname + location.hash)`(앱이 넣은 `pushState {task}` 보존). 허용 밖 값 · `can` 거짓이면 PATCH 0(꼬리만 지움).
4. **누름 갈래**(지금 :270 위임) — `origin.closest('.usage-guide-launch')` 이고 여정 기능이 켜졌으면 **질문 카드**. 창 안 「? 이 창 사용 안내」(`origin.closest('dialog')`)와 업무 홈 머리 「사용 방법」(`work_home_page.php` 의 `[data-usage-guide-open]`)은 지금처럼 그 투어(`start()`).
5. **질문 카드** — 지금 `consent()` 자리의 같은 틀: `makeDialog()` 의 `dialog.usage-guide-dialog`(showModal) · 가운데 자리 `.is-consent` 재사용 · 제목 `h2#usage-guide-title` 「무엇을 하고 싶으세요?」(옛 시험이 이 id 로 찾는다) · 선택지 `div.usage-guide-options` 안 `button.usage-guide-option`(위 `span` 가는 곳 · 아래 `strong` 글자 — 순서 · 글자는 E 표 머리, `can` 참인 것만) · 맨 위 「하던 일 이어 하기 — <과업 글자> (n / m)」(active 또는 가장 최근 paused 하나) + 그 줄 끝 「처음부터」(`restart: true`) · `completed` 에 든 과업은 줄 끝 「✓」(`aria-hidden` · 화면 읽기 글자 「끝냄」) — 누르면 `status: 'active'`(서버가 다시 하기로 시작 시각을 새로 찍음) · 바닥 왼쪽 「이 화면 사용 안내」(→ 지금 `start()`) · 「사용 방법 읽기」(그 화면에 `#help-dialog` 가 있을 때만 — 이 단추에 `data-help-open` 속성을 달아 **앱의 위임(`app.js` `initHelpDialog`)이 도움말 창을 열게** 하고, 단추 자신의 click 에서 질문 카드를 먼저 닫는다 — 창 위에 창 0 · 안내가 창을 여는 코드 0) · 오른쪽 끝 「닫기」. **자동 제안으로 열렸을 때**는 「닫기」 대신 지금 글자 「**나중에 볼게요**」(옛 시험 여섯이 이 단추로 제안을 닫는다) — 누르거나 과업을 고르면 옛 `PATCH /api/me/guide {status:'dismissed', context, step:0}` 도 함께 보낸다. 선택지가 0 이면 바닥만. 과업을 고르면 질문 카드를 닫고 3 과 같은 PATCH 뒤 그 과업의 첫 화면으로(지금 화면이 첫 화면이면 그 자리에서 첫 단계, 아니면 「이 화면으로 가기」 카드).
6. **진행 카드** — `section.usage-guide-float[role=region][aria-label="안내"]` 를 `body` 끝에 하나(dialog 아님) + 누름이 통과하는 `svg.usage-guide-ring[aria-hidden=true]`(rect 하나 · `pointer-events: none`) + 대상에 `.usage-guide-target`. 내용: `p.usage-guide-progress` 「n / m · <과업 제목> · 다음: <앱 이름>」(m = 그 여정 모든 화면의 단계 합 · n = 누적 번호) · `h2` 단계 제목 · `p[role=status]` 단계 글 · `div.usage-guide-actions`(DESIGN 2.37 「단추」 — 읽는 단계 「나중에」 · 「다음」 / 하는 단계 「나중에」 · 「건너뛰기」 / 다른 화면 「나중에」 · `a.usage-guide-primary[href]` 「이 화면으로 가기 → <앱 이름>」 / 완성 `div.usage-guide-chips` 칩 하나 · 「마치기」) · ✕ 0(「나중에」 · Esc 가 닫는 길 = `paused`) · 과업을 지우는 단추 0(`dismissed` 는 서버가 받기만 — 1차 화면에서 보내지 않는다). 글자는 전부 `textContent`.
7. **이어 하기** — 로드마다 `journeys.active` 를 본다. 지금 화면이 그 여정의 저장 page **이상**인 page 의 `here` 와 맞으면 그 page 를 연다(저장 page 와 같으면 저장 step, 크면 step 0 + PATCH `{page, step:0}`) — **앞으로만**. 저장 page 보다 앞 화면이거나 관계없는 화면이면 열지 않고 「? 사용 안내」 단추 글자를 「이어 하기 n/m」(`.is-paused` · <480 은 점 하나)으로 바꾼다 — 누르면 질문 카드(맨 위 이어 하기 줄). 이어 하기 줄을 눌렀는데 그 여정의 사실이 이미 참이면 「결과가 이미 있어요」 카드(「마치기」 → completed). `paused` 는 저절로 열지 않는다.
8. **화면 판정 `here`** — ① 고정 주소 화면은 선택자 **와** `location.pathname === <앱 base> + url` 둘 다(같은 `home_page.php` 를 `/suggestions` · `/automations` · `/procedures` 도 쓴다) ② 문서는 `body[data-page=community-page]:not([data-project-view])` + 경로 끝 `/p/<ref>`(ref = 사실의 `ref` — 관계없는 문서에서 뜨지 않게) — `#page-doc` 은 편집 모드에서 숨으므로 쓰지 않는다 ③ 게시판은 `body[data-page=community-board]:not([data-area=cafe])` + 경로의 게시판 번호 = find 의 id ④ message-send 만 `#messenger-window` 가 DOM 에 있으면 그 화면(닫힌 떠 있는 창이 기본이라 보임으로 판정하지 않음). 앱 base 는 `#usage-guide-root` 의 `data-base`(업무 — 지금) · `data-app-base`(자료실 — 새 속성, `usage_guide.php` 에서 `rtrim(Http::url('/'), '/')`) — 둘 다 `/` 로 시작하고 `//` 가 아닐 때만.
9. **넘김 신호 `until`** — 선택자 일치(관찰자 하나: attributes `open` · `hidden` · `data-view` · `data-page-editing` · `aria-pressed` + childList + characterData · 여정이 active 일 때만 붙이고 rAF 로 묶음) · `{event:'work:changed'}`(window 이벤트) · `{value:true}`(change, 또는 input 뒤 **0.8초 멈춤** + 빈 값 아님 — 첫 글자에 넘기지 않음 · contenteditable 은 그 칸 안 input 위임) · `{text:'#invite-status'}`(단계에 들어온 **뒤** 글자가 바뀜) · `{navigate:true}`(단계를 그릴 때 `{page:+1, step:0}` 을 미리 저장 — 다음 화면이 이어 받음) · `{fact:true}`(GET 한 번 + 2초 간격 다시 두 번 — 사실이 참이면 다음). `#page-save-state`(「저장됨」)는 편집에 들어갈 때 이미 켜지므로 신호로 쓰지 않는다.
10. **앞질러 가기** — 같은 화면의 뒤 단계 대상에 `pointerdown` · `focusin` 이 오면 그 단계로 건너뛴다(위임 하나 — 기본값이 이미 골라진 등급 고르개 · 복원된 메신저 대화에서 갇히지 않게).
11. **요소 대기 · 빈 상태** — 대상이 없으면 관찰자로 3초 기다린다. 그래도 없으면 「이 단추가 지금 안 보여요」 카드(「건너뛰기」 · 「이 화면으로 가기」 · 「나중에」 — n / m 그대로). `empty` 는 3초 대기가 끝난 **뒤에만** 판정한다(첫 그림의 빈 칸 · hidden 을 없음으로 오판 0). 옛 투어의 「소리 없이 버리고 알림」(:257-258)은 투어 모드에만.
12. **창 안 단계** — 대상이 열린 앱 `dialog` 안이면 진행 카드를 숨기고 그 창의 `.modal__head` 다음에(메신저는 `:scope > .modal__head`) `p.usage-guide-inset[role=status]`(「n / m」 `b` + 글 · 단추 0)를 끼운다 — `addModalHelp()` 와 같은 자리 규칙 · `modalContexts` 변경 0. 창 안 단계는 `until` · 앞질러 가기로만 넘어간다. 창이 닫히면 inset 을 지우고 진행 카드가 돌아온다(창 안 단계 도중이면 사실을 한 번 보고, 거짓이면 「아직 결과가 없어요」 + 창을 여는 단계로).
13. **find** — `findBoard('work', 'notice')`: `GET <업무 base>/api/boards?area=work` 결과를 그 화면 동안 기억 · `board_kind === 'notice' && is_active` 첫 하나 → `{id, name, can_write}`(`Number.isInteger(id)` 확인). 대상 선택자는 그 id 로 `#nav-community-boards a[href$="/b/<id>"]`. 안 보이면(폰 <960 · 접힌 사이드바 · 닫힌 묶음) 기다리지 않고 바로 「이 화면으로 가기 → 「<게시판 이름>」」(`/community/b/<id>`). 게시판이 없으면 빈 상태 카드 · `can_write` 거짓이면 자격 카드. 이름은 `textContent`.
14. **초점 · 키보드** — 진행 카드는 그릴 때 `focus()` 를 부르지 않고 Tab 을 가두지 않는다. 초점이 이미 카드 안이었을 때만 새 단계의 주 단추로. Esc 는 초점이 카드 안일 때 `keydown` 으로 받아 「나중에」(= paused). 여정 모드에서는 「이전」 · 방향키 넘김 0.
15. **완성** — `{fact:true}` 는 다음 단계로 넘기는 신호일 뿐이고, 완성 PATCH 는 **마지막 단계 뒤 한 번**(마지막이 읽기 단계면 「마치기」, 하기 단계면 그 `until` 뒤) — `PATCH {status:'completed'}` → 200 이면 완성 카드(E 표의 완성 글 · 칩 · 「마치기」), 400 이면 「아직 결과가 없어요」 카드(「다시 보기」 · 「나중에」). 「마치기」는 카드만 닫는다(질문 카드로 돌아가지 않음).
16. **메신저 예외** — message-send 첫 단계의 「다음」만 지금 `messengerWindow()` 를 부른다(창이 이미 열려 있으면 부르지 않음). 그 밖의 여정 · 단계에서 안내가 **앱 요소에** 부르는 `click()` · `submit()` · `showModal()` · `close()` 0(안내 자신의 질문 카드 · 옛 투어 창은 지금처럼).
17. **자리 · 숨김** — DESIGN 2.37 「층 · 자리」 · 「폰」 그대로: 기본 화면 아래 가운데 · 대상이 아래 반이면 `.is-top` · ≥720 에서 `#messenger-window[open]` 이면 `.is-beside`(카드 오른쪽 끝 ≤ 메신저 왼쪽 − 16 · 자리가 모자라면 `.is-top`) · <720 은 대상 반대쪽 반 · 최대 30dvh · 접기 칩 「안내 n/m ▾」(`.is-folded`) · 열린 showModal 앱 창이 있는데 대상이 그 안이 아니면(재인증 · 확인 창) 카드를 숨기고 기다린다 · 카드가 떠 있는 동안 `.usage-guide-launch` hidden.
18. **저장** — `request()` 를 창구 둘로 나눈다(같은 줄 세우기 · 같은 8초 · 같은 CSRF 갱신). 여정 PATCH 실패는 카드 안 오류 한 줄(지금 `notice()` 와 같은 문장 규칙).

### E. 여정 여섯 — 단계 표 (기획 8.3 · 8.6 · 8.7 반영 · 글은 DESIGN 2.37 「글」 규칙 · 화면 글자는 지금 코드에서 확인한 그대로)

질문 카드 · 홈 카드의 줄 = (가는 곳 / 글자): ① 업무 홈 / 맡은 일 보기 · 할 일 적기 ② 자료실 / 파일 올려서 동료에게 보여 주기 ③ 업무 홈 › 문서 / 회의록 쓰기 ④ 업무 홈 › 게시판 / 공지 올리기 ⑤ 메신저 / 동료에게 한마디 ⑥ 계정 관리 / 동료 초대하기. 과업 제목(진행 머리)은 그 글자 그대로. 아래 「종류」: 읽기 = 「다음」 · 하기 = 사람이 눌러야 넘어감 · 창 안 = inset.

**① `task-add`** — 화면 1: 업무 홈 `/community/`(here `#work-home` + 경로) · 사실 작업 하나

| # | 대상 | 종류 · 넘김 | 제목 / 글 |
|---|---|---|---|
| 1 | `#work-home-tasks` | 읽기 | 내 작업 / 중요한 순서로 모은 내 작업이에요. 비어 있으면 지금 하나 적어 봐요. |
| 2 | `#work-home-add` | 하기 · `#work-new-task-dialog[open]`(첫 누름 때 work.js 가 만드는 창) | 「작업 추가」 누르기 / 「작업 추가」를 누르세요. 「새 작업」 창이 열려요. |
| 3 | `#work-new-task-form input[type=text]`(첫 글자 칸 = 작업 이름) | 창 안 · `{event:'work:changed'}` → `{fact:true}` | 할 일 적기 / 할 일을 한 줄 적고 「만들기」를 누르세요 — 예 「내일까지 견적 확인」. 「프로젝트」가 「내 할 일」이면 나만 보는 할 일이 돼요. |
| 4 | `#work-home-all` | 읽기(「마치기」 → 완성) | 다시 찾기 / 업무 홈은 중요한 순서로 앞의 몇 줄만 보여요. 방금 적은 일이 안 보이면 「모두 보기」에서 찾아요. |
| 완성 | — | — | 할 일 하나를 적었어요. 업무 홈과 「내 작업」 화면에서 다시 보여요. (칩 0 — 「일 맡기기」는 2차) |

**② `file-share`** — 화면 1: 자료실 `/tbox/`(here `#moa-file-list` + 경로) · 사실 파일 하나

| # | 대상 | 종류 · 넘김 | 제목 / 글 |
|---|---|---|---|
| 1 | `#folder-pane` | 읽기 | 폴더 고르기 / 폴더에서 팀 폴더를 먼저 고르세요. 없으면 지금 자리에 올려도 돼요. |
| 2 | `#upload-toggle` | 하기 · `#upload-dialog[open]` | 「파일 올리기」 누르기 / 「파일 올리기」를 누르세요. 창이 열리면 파일 → 등급 → 「올리기」 순서예요. |
| 3 | `#upload-file` | 창 안 · `#upload-picked:not(:empty)`(「n개 선택됨」) | 파일 고르기 / 파일을 고르거나 끌어다 놓으세요. 여러 개도 한 번에 올라가요. |
| 4 | `#upload-level` | 창 안 · change 또는 앞질러 가기(`#upload-submit`) | 누가 볼 수 있나요 / 볼 사람을 등급으로 고르세요. 「팀 — 고른 팀만」이면 우리 팀, 「개인 — 나와 지정한 사람만」이면 고른 사람만 봐요. — 고르개에 `option[value=team]` 이 없으면 둘째 문장을 「「팀」이 없으면 「사내 — 사내 구성원 모두」로 — 관리자가 팀을 정하면 열려요.」로 |
| 5 | `#upload-submit` | 창 안 · `#upload-dialog:not([open])` → `{fact:true}`(실패 줄이 있으면 창이 열린 채라 그대로 기다림) | 「올리기」 누르기 / 「올리기」를 누르세요. 모두 올라가면 창이 저절로 닫혀요. |
| 6 | `#files-body` | 읽기(「마치기」) | 목록에서 확인 / 올린 파일이 목록에 있어요. 같은 등급의 동료는 자료실 목록과 검색에서 찾아 받아요. |
| 완성 | — | — | 파일을 올렸어요. 자료실 목록과 검색에서 다시 찾을 수 있어요. 알리려면 메신저로 한 줄 보내요. · 칩 「동료에게 한마디」(→ `message-send` · `can` 참일 때만) |

**③ `minutes-write`** — 화면 2 · 사실 문서 하나(ref) · 전제: 이름이 **정확히** 「회의록」인 템플릿(H)

| # | 화면 · 대상 | 종류 · 넘김 | 제목 / 글 |
|---|---|---|---|
| 1 | 내 문서 `/community/workspace`(here `#moa-my-documents` + 경로) · `#community-more > summary` | 하기 · `#community-more[open]` | 「더보기」 열기 / 「더보기」를 누르세요. 「템플릿에서 만들기」가 안에 있어요. |
| 2 | `#community-template-new` | 하기 · `#template-dialog[open]` | 「템플릿에서 만들기」 / 「템플릿에서 만들기」를 누르세요. 템플릿 목록 창이 열려요. |
| 3 | `#template-body button[data-template-use][aria-label="회의록 으로 새 페이지 만들기"]`(templates.js 가 만드는 글자 그대로) | 창 안 · `{navigate:true}`(템플릿 「만들기」는 `/p/<id>` 로 옮김 — 읽기 모양으로 열림) · 빈 상태(3초 뒤): `#template-empty:not([hidden])` 또는 그 단추 없음 → 「「회의록」 틀이 아직 없어요 — 관리자가 만들면 여기 보여요」 | 「회의록」 만들기 / 「회의록」 줄의 「만들기」를 누르세요. 그 틀이 든 새 문서가 열려요. |
| 4 | 문서 `/community/p/<ref>`(here 8 ②) · `#page-edit` | 하기 · `body[data-page-editing]` | 「편집」 누르기 / 「편집」을 누르세요. 제목 칸과 본문 칸이 열려요. |
| 5 | `#page-title-input` | 하기 · `{value:true}` · 앞질러 가기(`#page-editor .ProseMirror`) | 제목 적기 / 제목을 적으세요 — 예 「2026-10-27 주간 회의」. 검색은 제목부터 찾아요. |
| 6 | `#page-editor .ProseMirror` | 하기 · `{value:true}` 또는 「건너뛰기」 | 칸 채우기 / 칸을 채우세요. 저장은 저절로 되고 위 배지가 「저장됨」으로 알려 줘요. |
| 7 | `#page-done` | 하기 · `#page-done[hidden]` | 「편집 마침」 / 다 쓰면 「편집 마침」을 누르세요. 읽기 모양으로 바뀌어요. |
| 8 | `#page-visibility-edit`(**보일 때만** — 공개 범위를 바꿀 수 있는 사람) | 하기 · `#visibility-dialog[open]` | 팀과 같이 보기 / 공개 범위 옆 「바꾸기」를 누르세요. 같이 볼 사람을 고르는 창이 열려요. |
| 9 | `#visibility-choices` | 창 안 · change 또는 앞질러 가기(`#visibility-save`) | 같이 볼 사람 / 같이 볼 사람을 고르세요. 「팀 — 내 팀 사람들이 봅니다」면 우리 팀이 같이 봐요. |
| 10 | `#visibility-save` | 창 안 · `#visibility-dialog:not([open])` → `{fact:true}` | 「바꾸기」 누르기 / 「바꾸기」를 누르세요. 문서의 공개 범위가 바뀌어요. |
| 완성 | — | 8 이 안 보이면 7 뒤 바로 완성 판정 | 회의록을 만들었어요. 업무 홈 › 내 문서와 검색 칸에서 제목으로 다시 찾아요. 다음 회의 땐 같은 틀로 하나 더 만들어요. |

**④ `notice-post`** — 화면 3 · 사실 업무 게시판 글 하나(ref) · 보이는 사람 S3-1 A(`allowed`) · 전제: 업무 쪽 「공지」 게시판(H)

| # | 화면 · 대상 | 종류 · 넘김 | 제목 / 글 |
|---|---|---|---|
| 1 | 업무 홈 `/community/`(here `#work-home` + 경로 · find `('work','notice')`) · `#nav-community-boards a[href$="/b/<id>"]` | 하기 · `{navigate:true}` · 안 보이면 가기 카드 · 빈 상태 「공지 게시판이 아직 없어요 — 관리자가 만들면 여기 생겨요」 · `can_write` 거짓 「이 게시판엔 글을 쓸 자격이 없어요 — 관리자에게 물어보세요」 | 공지 게시판 열기 / 「<게시판 이름>」 게시판을 누르세요. 공지 게시판 화면이 열려요. |
| 2 | 게시판 `/community/b/<id>`(here 8 ③) · `#board-new` | 하기 · `#board-new-dialog[open]` | 「새 글」 누르기 / 「새 글」을 누르세요. 제목 창이 열려요. |
| 3 | `#board-new-input` | 창 안 · 앞질러 가기(`#board-new-save`) · `{navigate:true}`(「만들고 쓰기」는 `/p/<id>?edit=1` 로 옮김) | 제목 적기 / 제목을 적고 「만들고 쓰기」를 누르세요. 글이 바로 게시되고 본문 쓰는 화면이 열려요. |
| 4 | 문서 `/community/p/<ref>`(here 8 ②) · `#page-editor .ProseMirror` | 하기 · `{value:true}` 또는 「건너뛰기」 | 본문 적기 / 본문을 적으세요. 저장은 저절로 되고 글은 이미 게시돼 있어요. |
| 5 | `#page-done` | 하기 · `#page-done[hidden]` → `{fact:true}` | 「편집 마침」 / 「편집 마침」을 누르세요. 읽기 모양으로 바뀌어요. |
| 완성 | — | — | 공지를 올렸어요. 동료의 업무 홈 공지 줄에 보이고, 읽으면 사라져요. · 칩 「동료에게 한마디」(→ `message-send`) |

**⑤ `message-send`** — 화면 1: 셸 화면 어디서나(here `#messenger-window` 가 DOM 에 있음 · 홈 카드는 S3-2 A 로 `/community/?guide=message-send`) · 사실 동료에게 보낸 메시지 하나

| # | 대상 | 종류 · 넘김 | 제목 / 글 |
|---|---|---|---|
| 1 | 보이는 `[data-messenger-open]` | 읽기 — 「다음」이 `messengerWindow()`(예외 한 번 · 이미 열려 있으면 부르지 않음) · 사람이 직접 눌러도 `#messenger-window[open]` 으로 통과 | 메신저 열기 / 메신저는 이 단추로 열어요. 「다음」을 누르면 이번만 안내가 창을 열어 드려요. |
| 2 | `#messenger-back`(대화 보기일 때) · `#messenger-window #message-people .moa-person`(목록 보기일 때 — 보이는 첫 줄) | 창 안 · `#messenger-window[data-view=conversation]:has(#message-ai[aria-pressed=false])`(사람 대화로 복원돼 있으면 곧바로 통과 · 모아 AI 대화면 막힘) | 동료 고르기 / 동료를 고르세요 — 다른 대화가 열려 있으면 「‹ 목록」부터 눌러요. 맨 위 「모아 AI」는 회사 공용 AI 예요. |
| 3 | `#messenger-window #message-text` | 창 안 · `{value:true}` · 앞질러 가기(`#message-send`) | 한 줄 적기 / 한 줄 적으세요 — 예 「업무 홈에 하나 맡겼어요」. Enter 는 보내기, Shift+Enter 는 줄바꿈이에요. |
| 4 | `#messenger-window #message-send` | 창 안 · `#message-form` submit 뒤 `{fact:true}` — 거짓이면 「아직 결과가 없어요 — 동료를 골라 보내면 끝나요」 | 「보내기」 누르기 / 「보내기」를 누르세요. 상대는 어느 화면에 있든 메신저 단추의 숫자로 알아요. |
| 완성 | — | — | 보냈어요. 창을 닫아도 대화는 남고, 다시 열면 마지막 대화부터 이어져요. |

**⑥ `invite-colleague`** — 화면 1: 계정 관리 `/tbox/users`(here `#member-invites` + 경로) · 사실 초대 하나 · 홈 카드는 업무 홈 `/community/?guide=invite-colleague` 로 와서 「이 화면으로 가기 → 계정 관리」(재인증 15분이 지났으면 `/tbox/reauth` 를 거쳐 고정 경로 `/users` 로 돌아온 뒤 7 이 이어 받음 — 재인증이 꼬리를 버리기 때문 · `public/index.php` 0)

| # | 대상 | 종류 · 넘김 | 제목 / 글 |
|---|---|---|---|
| 1 | `#invite-form` | 하기 · `#invite-result:not([hidden])` → `{fact:true}` | 초대 링크 만들기 / 초대 이름과 「소속 팀」을 정하고 「초대 링크 만들기」를 누르세요. 팀을 정해 두면 가입하자마자 그 팀 파일을 봐요. |
| 2 | `#invite-copy` | 하기 · `{text:'#invite-status'}`(만들 때 이미 글자가 있으므로 단계에 들어온 뒤 바뀌어야 통과) 또는 「건너뛰기」 | 링크 보내기 / 「링크 복사」를 누르고 지금 쓰는 메신저나 메일로 보내세요. 링크 원문은 이번에만 보여요. |
| 3 | `#invite-list` | 읽기(「마치기」) | 초대 목록 / 보낸 초대와 쓰인 횟수가 여기 남아요. 가입하면 계정 목록에 이름이 생겨요. |
| 완성 | — | — | 초대 링크를 만들었어요. 가입하면 계정 관리 목록에 이름이 생겨요. (칩 0 — 「팀 만들고 사람 넣기」는 2차) |

여정 데이터 꼴(`usage-guide.js` `journeys` — `tours` 옆): `{ id, place, label, pages: [{ app: 'community'|'tbox'|'any', path?, here, find?, steps: [{ anchor, title, text, kind: 'read'|'do', until?, when?, empty?, alt? }] }], done: { text, chip? } }`. 가는 곳 · 글자 · 제목 · 글은 위 표 그대로(바꾸면 보고서에 · 금지 낱말 · 방향 말 0).

### F. 모양 — CSS · 토큰 · 단추 속성

- `public/assets/usage-guide.css` 끝에 DESIGN 2.37 「새 클래스 · 토큰」 줄의 클래스만: `.usage-guide-float`(고정 · `z-index: var(--z-guide)` · 폭 `min(540px, calc(100% - 32px))` · `--surface` · `--line` · `--r-card` · `--shadow-lg`) · `.usage-guide-ring`(고정 · inset 0 · `pointer-events: none` · rect 테두리 `--accent` · 채움 없음) · `.usage-guide-target`(`outline: 3px solid var(--accent); outline-offset: 3px`) · `.usage-guide-inset`(여백 8 12 · 13px · `--surface-2` · `--fg-2` · 반경 8) · `.usage-guide-options` / `.usage-guide-option`(누름 자리 44 · 줄 사이 `--line`) · `.usage-guide-chips` · `.usage-guide-float.is-top` · `.is-beside` · `.is-folded`(<720 — 최대 30dvh · 칩만) · `.usage-guide-launch.is-paused`(테두리 `--accent` · <480 점 하나) · `body:has(.appbar__apps[open], .shell-account[open]) .usage-guide-float, html.mobile-nav-open .usage-guide-float { display: none; }` · `@media (forced-colors: active) { .usage-guide-target { outline-color: Highlight; } }`. **새 줄에는 `var(--x)` 만**(대체 색값 · 새 색값 · 숫자 z-index 0). 옛 줄(투어 · 단추)은 손대지 않는다.
- `public/assets/app.css` `:root` — `--z-floating: 60;` 바로 아래 `--z-guide: calc(var(--z-floating) + 1);` 한 줄(주석 「길잡이 진행 카드 — DESIGN 2.37 · 2.17」).
- `src/views/usage_guide.php` — 단추 `aria-label="이 화면 사용 안내 열기"` → `"사용 안내 열기"`(질문 카드가 먼저 뜨므로) · `#usage-guide-root` 에 `data-app-base="<?= htmlspecialchars(rtrim(Http::url('/'), '/'), ENT_QUOTES, 'UTF-8') ?>"` 하나. 보이는 글자 「? 사용 안내」 그대로.

### G. 홈 과업 카드 — `src/views/launcher.php` (DESIGN 2.31 「과업 카드」 · S3-1 · S3-2)

- `$launcherLive` 블록에서 `require_once __DIR__ . '/../community/GuideJourney.php'` · `$homeGuide = GuideJourney::allowed($launcherUser)` 한 번(판정 함수 값만 — 홈에 더해지는 질의는 아래 「이어 하기」 줄의 `resume()` 하나뿐).
- 줄의 글자 · 순서 · 가는 곳 · `data-service` 는 **그대로**. 주소만: ① `$homeGuide['task-add']` 면 `Http::communityUrl('/') . '?guide=task-add'`(아니면 지금처럼 `/community/` · 「맡은 일 보기」) ② `file-share` 면 `Http::url('/') . '?guide=file-share'`(아니면 `/tbox/` · 「파일 찾기」) ③ `Http::communityUrl('/workspace') . '?guide=minutes-write'` ④ **S3-1 A**: 줄 보이기 조건을 `$homeGuide['notice-post']` 로 · 주소 `Http::communityUrl('/') . '?guide=notice-post'` ⑤ **S3-2 A**: 주소 `Http::communityUrl('/') . '?guide=message-send'` · 메신저 창 여는 속성(`data-messenger-open aria-haspopup aria-controls aria-expanded`)을 이 줄에서 뺀다(바로가기 「메신저」는 그대로) · 보이기 조건 `$homeGuide['message-send']`(= 지금 `$launcherMessenger`) ⑥ `Http::communityUrl('/') . '?guide=invite-colleague'`(재인증 관문 화면은 꼬리로 시작하지 않는다) · 보이기 조건 `$homeGuide['invite-colleague']`.
- 꼬리 값은 `GuideJourney::JOURNEYS` 키 상수뿐(요청 값 0) · 이스케이프는 지금처럼 그릴 때 한 번 · 꼬리는 `guide` 하나(다른 꼬리와 섞지 않는다).
- **맨 위 「하던 일 이어 하기」 줄**(DESIGN 2.31 — 2단계 때 「3단계에서 생긴다」로 승인된 줄): `GuideJourney::ready()` 이고 내 행 가운데 `active`, 없으면 가장 최근 `paused` 가 있고 그 여정이 `allowed` 면 목록 첫 줄로 — 가는 곳 「하던 일 이어 하기」 · 글자 = 그 과업 줄 글자 · 주소 = 그 과업의 같은 `?guide=<여정>`(이어 하기 — 시작 시각 그대로) · `data-service` 는 그 과업 줄과 같게. 「n / m」은 홈에서 쓰지 않는다(단계 수는 화면 정의에만 있다). 질의는 `GuideJourney` 의 함수 하나(Prepared · 내 행만 · LIMIT 1)로 — 홈 첫 로드 HTTP 요청 수는 그대로.
- 홈에는 안내 스크립트 · 새 HTTP 요청 0(B.3 — 홈 첫 로드 그대로).

### H. 초대 전 운영 준비 — **코드 0 · 운영 규칙**(기획 7.8 · 8.6 · B.3 「초대 전 시드」 — 소유자가 운영 화면에서 · 반영 뒤 메인이 읽기로만 확인해 빠진 것을 보고)

1. 이름이 **정확히 「회의록」**인 템플릿 하나 — 범위 「사이트 기본」(내 문서 › 더보기 › 템플릿에서 만들기 창 · 사이트 템플릿). 길잡이는 그 이름의 「만들기」 단추를 찾는다 — 없으면 회의록 과업은 「「회의록」 틀이 아직 없어요」에서 멈춘다.
2. 업무 쪽 게시판 가운데 종류가 **「공지」**인 것 하나(게시판 관리) — 없으면 공지 과업은 「공지 게시판이 아직 없어요」에서 멈춘다.
3. 동료를 팀에 넣기(계정 관리) — 팀이 없으면 「팀」 등급 · 공개 범위 「팀」을 고를 수 없다(파일 · 회의록 과업의 등급 단계 글이 그 경우를 말한다).
4. 관리자 「AI 대화 사용」 켬 + 키 — 꺼져 있으면 메신저의 모아 AI 가 「준비 중」(한마디 과업은 동료에게 보내므로 영향 0 · 첫인상).

## 바꾸지 않는 것 (이 단계 밖 — 손대지 않는다)

- 옛 「사용 안내」 투어 전부: `tours` 10맥락의 글 · 선택자 · 모양(showModal · 어둡게 · Tab 가둠 · 「이전 / 다음」) · `UsageGuide.php` · `community.usage_guides` · `PATCH /community/api/me/guide` · 창 안 「? 이 창 사용 안내」 · 업무 홈 머리 「사용 방법」 · 「사용 방법」 도움말(`src/help/*`).
- 2차(기획 8.3 「2차」): 맡은 일 끝내기 · 파일 찾아 받기(손님) · 일 맡기기 · 우리 공간 글 · 팀 만들기 · 모아 AI 켜기 · 둘째 질문 칩 줄 · 사람 확인(「했어요」) 완성 · 「다음에 해 볼 일」의 연쇄 · 런처 `/` 에 「? 사용 안내」 싣기(B.3) · 「회의록 틀로 만든 문서만」 판정(템플릿 출처 칸).
- 첫 로그인 제안의 대상 조건(가입이 안내 판 활성 뒤인 사람 — `UsageGuide::state` 그대로) · 완성 기록의 지표화(S3-4 A) · 처리방침 · 감사.
- 권한 · 판정 · 라우터 · 재인증 표(`public/index.php`) · 토큰 범위 · 공지 쓰기 규칙(`CommunityAuthz::decideBoardWrite`) · 앱 단추 · 창 · 주소 · 홈의 다른 줄(바로가기 · 대시보드 · 카드 접힘 쿠키 `qaredi_home_tasks`).
- 자동 프로젝트 「내 할 일」 이름(B.3 「프로젝트 없는 작업」 권고는 별도 단계 — 그때 ① 3단계 글도 같이 고친다).

## 수락 시험 — 사이트 PRD 9.1 Q:T-G1 ~ G18 (격리 · claude_ 계정 · UA `tbox-review/<날짜>` · 운영 0)

```
[unit]    tests/community/guide_journey_unit.php — Q:T-G1 (DB 0)
  ① 여섯 여정 × {active, page 0, step 0} · {…, restart:true} 통과 · 각 여정의 마지막 page 통과
  ② 400: 빈 본문 · user_id 키 · confirm 키 · 키 하나 빠짐 · journey 'x' · '../a' · 'Task-add' · status 'new' · page -1 · page = 화면 수 · step 31 · '0'(문자열) · restart 'yes'
  ③ JOURNEYS 키 = usage-guide.js journeys 키(파일을 읽어 셈) · 화면 수 = pages 길이

[http]    tests/community/guide_journeys_http.py — memories_harness suite (진짜 PHP · 합성 DB)
  [db] Q:T-G18
  ① 새 표: 틀이 갈음한 소유자 · has_table_privilege tbox_app SELECT · INSERT · UPDATE 참 · DELETE 거짓 · tbox_backup SELECT 참 · 부분 유니크 색인 있음 · 마이그레이션 두 번 오류 0(틀)
  [api] Q:T-G3 · Q:T-G11
  ② GET /community/api/me/guide 200 — journeys {active:null, paused:[], completed:[]} · can 여섯 키 · 직원 task-add · file-share · minutes-write · message-send 참 · notice-post · invite-colleague 거짓(S3-1 A) ·
     관리자 여섯 참 · 손님 여섯 거짓 · 관리자의 「역할로 보기」(직원) = 직원 값에서 message-send 거짓 · 응답에 csrf_token
  ③ PATCH /community/api/me/journeys: 올바른 본문 + CSRF 없음 403 · 세션 없음 401 · 개인 토큰 헤더만(합성 DB 에 발급) 401 · MCP 토큰 헤더만 401 · GET · DELETE 405 · 손님 task-add 403 · 직원 notice-post 403
  ④ 남의 행: 직원 A 의 행이 직원 B 의 GET 에 0 · B 의 PATCH 는 B 행만 바뀜(SQL)
  ⑤ 표 없는 경우: 합성 DB 에서 표 이름을 잠시 바꿈 → GET 200 · journeys null · can null · 옛 PATCH /api/me/guide 200 · 새 PATCH 503 → 이름 되돌림
  ⑥ 여정 행이 하나라도 생기면 guide.offer 가 거짓(활성 뒤 만든 새 합성 계정 — 전엔 참)
  [state] Q:T-G2
  ⑦ file-share active → message-send active: file-share paused · active 행 1(SQL)
  ⑧ minutes-write active(시각 T0) → 1초 뒤 {active, page 1, step 0} → started_at = T0 · paused → active 도 T0 · restart:true → T0 보다 뒤 · dismissed → active · completed → active 도 새 시각 · completed_at 비움
  ⑨ 행 없음에서 paused · dismissed · completed 400 · dismissed 행에서 completed 400
  ⑩ 사실 — file-share: 시작 전 파일만 있으면 completed 400 → 그 계정으로 파일 하나 올림 → 200 · completed_at · confirmed_by_user 거짓 · GET completed 목록 /
     task-add: 「내 할 일」 빠른 추가 하나 → 참 / minutes-write: 「내 할 일」 자동 프로젝트 · 새 프로젝트(데이터베이스 페이지) · 그 행만이면 거짓 · 보통 문서 하나 → 참 · ref = 그 public_id /
     notice-post: 관리자의 업무 공지 게시판 글 → 참 · ref / message-send: 모아 AI 에게만 보내면 거짓 · 동료에게 하나 → 참 / invite-colleague: 초대 하나 → 참
  ⑪ 동시: 서로 다른 여정 active 를 두 요청이 동시에 20쌍 — 응답 200 또는 409 뿐 · 500 0 · 매번 active 행 1
  ⑫ 감사 표에 이 창구 행 0

[browser] tests/community/guide_journey_browser.py — memories_harness suite (진짜 화면 · 합성 DB · Chromium)
  준비: 관리자 창구로 사이트 템플릿 「회의록」 · 업무 공지 게시판 하나(창구가 막히면 합성 DB 에 SQL) · 모든 합성 계정 usage_guides dismissed(제안이 덮지 않게 — ⑫ 는 따로)
  [static] Q:T-G14
  ① scripts/security_lint.sh 0 · 바꾼 PHP php -l · 바꾼 JS node --check · usage-guide.js · .css 에 innerHTML · style= · 외부 URL · #색값 · 숫자 z-index 0(새 줄) ·
     usage-guide.js 에 'Admin' 'Guest' 'role' 0 · 여정 글(title · text · done) 금지 낱말 0(AI 비서 · 업로드 · 다운로드 · 삭제 · 취소 · 쉼터 · 고급 · 가짜 · 「자료 」 「홈 」 단독 · Gemini · 왼쪽 · 오른쪽) ·
     글 문장 수 ≤ 2(완성 ≤ 3) · 단추 글자 집합 = DESIGN 5장 「안내 카드 단추」 · 모든 anchor 의 id 가 src/ · public/ 에 있음(런타임 work-new-task-* 는 work.js 의 modal('work-new-task' · aria-label 꼴은 templates.js 의 ' 으로 새 페이지 만들기') ·
     launcher.php 의 ?guide= 값 = JOURNEYS 키 · CommunityAuthz::decideBoardWrite 의 공지 갈래가 Authz::canAdmin 그대로(GuideJourney 의 공지 판정과 같은 함수 — 한쪽만 바뀌면 실패)
  [home] Q:T-G7 · Q:T-G11
  ② 홈 과업 카드(펼침 쿠키): 직원 넷 · 관리자 여섯 · 손님 둘 — 글자 · 순서 · data-service 는 2단계 그대로 · 주소: 쓰기 줄은 ?guide=<여정> 하나 · 손님 · 읽기 글자 줄은 꼬리 0 ·
     「동료에게 한마디」 줄에 메신저 창 여는 속성 0 · 바로가기 「메신저」는 그대로 창을 연다 · 홈 첫 로드 요청 목록에 새 주소 0 ·
     진행 중(또는 멈춘) 여정이 있는 계정은 맨 위 「하던 일 이어 하기」 줄(그 과업 글자 · 같은 ?guide=) · 없으면 0 · 그 줄을 누르면 저장 단계부터(시작 시각 그대로 — SQL)
  [file-share] Q:T-G4 · G5 · G7
  ③ /tbox/?guide=file-share 로 열면 PATCH 본문이 정확히 {journey:'file-share', status:'active', page:0, step:0} · 주소 꼬리가 사라지고 history.state 보존 · 진행 카드는 section.usage-guide-float(dialog 아님) ·
     카드가 떠 있는 동안 document.querySelectorAll('dialog').length 변동 0 · 어둡게 0 · .usage-guide-launch hidden · 2단계에서 elementFromPoint(#upload-toggle 가운데) 가 그 단추이고 사람이 누르면 창이 열림 ·
     창 안 .usage-guide-inset 이 .modal__head 다음에 하나 · inset 안 button 0 · 진행 카드 숨김 · 파일 고름 → 등급 단계 · 기본 등급 그대로 「올리기」에 초점 · 누름 → 앞질러 가기로 갇히지 않음 ·
     올라감 → 창 닫힘 → 목록 단계 → 「마치기」 → 완성 카드 글 · completed 행(SQL) · 같은 주소를 다시 열면(completed) 다시 하기 시작 시각 갱신
  ④ 진행 카드가 떠 있어도 작업 상세 칸 Esc 가 칸을 닫고(work.js) · 자료실 파일 동작 창을 닫으면 초점이 누른 단추로(app.js) — 카드 없을 때와 같음
  [task-add] Q:T-G8
  ⑤ /community/?guide=task-add → 「작업 추가」 → 런타임 #work-new-task-dialog 에 inset · 만들기 → work:changed + 사실 → 4단계 → 완성
  [minutes-write] Q:T-G6 · G9 · G17
  ⑥ /community/workspace?guide=minutes-write → 더보기 · 템플릿 · 「회의록」 「만들기」 단계에서 PATCH {page:1, step:0} 이 먼저 · /p/<id> 로드 직후 「편집」 단계(「안 보여요」 0) ·
     제목 칸에서 한 글자 치고 0.3초 안에는 단계 그대로 · activeElement 는 제목 칸 · 0.8초 뒤 다음 · 본문 단계는 「저장됨」 배지가 이미 보여도 곧바로 통과 0 · 편집 마침 · 공개 범위 창 inset · 「팀」 → 「바꾸기」 → 완성 ·
     카드 안에서 Tab 이 갇히지 않음(Tab 으로 카드 밖 요소에 감)
  ⑦ 이어 하기: minutes-write page 1 저장 상태로 업무 홈(앞 화면)을 열면 카드 0 · 단추 글자 「이어 하기 n/m」 · 누르면 질문 카드 맨 위 이어 하기 줄 · 사실이 참이면 「결과가 이미 있어요」 ·
     다른 문서 /p/<다른 id> 에서는 카드 0 · /community/procedures(같은 틀)에서는 0쪽으로 보지 않음 · 「회의록」 템플릿이 없는 문맥 → 3초 뒤 빈 상태 카드
  [notice-post] Q:T-G9
  ⑧ 관리자: ≥960 펼친 사이드바 → 공지 게시판 링크에 테두리 · 쿠키 qaredi_sidebar=collapsed · 412 → 기다리지 않고 「이 화면으로 가기 → 「<이름>」」 href /community/b/<id> ·
     「새 글」 → 「만들고 쓰기」 → /p/<id>?edit=1 → 본문 · 편집 마침 → 완성 · 공지 게시판을 보관(is_active 거짓)한 문맥 → 빈 상태 카드
  [message-send] Q:T-G12
  ⑨ /community/?guide=message-send → 첫 카드 「다음」에서만 안내가 [data-messenger-open] 을 한 번 누름(isTrusted 거짓 click 셈 1) · 창이 이미 열려 있으면 0 · 모아 AI 대화로 복원된 창이면 「‹ 목록」을 가리킴 ·
     동료 고름 · 한 줄(0.8초) · 「보내기」 → 완성 · 모아 AI 에게만 보낸 문맥 → 「아직 결과가 없어요」 · 다른 다섯 여정 전 과정에서 안내의 click() · submit() · showModal() · close() 0
  [invite] Q:T-G7
  ⑩ 관리자 /community/?guide=invite-colleague → 「이 화면으로 가기 → 계정 관리」 href /tbox/users → 재인증 창구가 시간이 지난 문맥이면 /tbox/reauth → 통과 → /tbox/users 에서 1단계가 이어 뜸 → 링크 만들기 → 링크 복사 → 완성
  [pause] Q:T-G10
  ⑪ 「나중에」 · Esc(초점 카드 안) = paused(page · step 보존 — SQL) · 새로 고침에 카드 0 · 질문 카드 맨 위 이어 하기 줄 → 저장 단계부터 · 「처음부터」 → PATCH restart:true · 다른 과업을 고르면 앞 과업 paused(행 남음)
  ⑫ 제안: usage_guides 행이 없는 활성 뒤 계정 → 자동 제안 = 질문 카드(#usage-guide-title 「무엇을 하고 싶으세요?」 · 「나중에 볼게요」) → 과업 고름 → 진행 카드 · 옛 PATCH dismissed 1 · 다른 화면에서 제안 0
  [guest] Q:T-G11
  ⑬ 손님: 「? 사용 안내」 → 질문 카드 선택지 0 · 바닥만 · /community/?guide=task-add → PATCH 0 · 화면 그대로
  [missing] Q:T-G8
  ⑭ 대상을 2초 뒤에 넣으면 이어 감 · 3초 안에 없으면 「이 단추가 지금 안 보여요」(건너뛰기 · 이 화면으로 가기 · 나중에) · n / m 그대로 · 첫 그림에 비었다가 1초 뒤 채워지는 칸에서 빈 상태 카드 0
  [layout] Q:T-G13
  ⑮ 360 · 412(터치 · 마우스) · 820 · 960 · 1024 · 1280 · 1440 × 밝게 · 어둡게 + 고대비 한 번: 메신저를 연 채 카드 ∩ 메신저 창 넓이 0(960 · 1024 · 1280 · 1440) · 앱 판 · 내 계정 판을 연 동안 카드 0 ·
     html.mobile-nav-open 동안 카드 0 · 재인증 · 확인 창 위에 카드 0 · 대상이 아래 반이면 카드 위 · <720 카드 높이 ≤ 30dvh · 접기 칩 · 가로 넘침 0 · 누름 자리 44 · 대비 4.5 · 콘솔 오류 0 ·
     스크린샷(PC 진행 카드 · 창 안 한 줄 · 메신저 옆 카드 · 질문 카드 · 폰 메신저 시트 · 폰 작업 칸 · 완성 카드)
[fallback] Q:T-G15 (부분 — 나머지는 밤 회귀)
  ⑯ python3 tests/community/usage_guide_browser.py — 고치지 않고 통과(목업이 {guide} 만 → 여정 기능 꺼짐 · 옛 제안 · 투어 그대로)
```

카탈로그(`tests/catalog.json`): `community.guide_journey_unit`(`php tests/community/guide_journey_unit.php` · static) · `community.guide_journeys_http` · `community.guide_journey_browser`(둘 다 `env: temp-instance` · `writes_prod: false` · `status: required` · `needs` 맞게 · `covers` 에 바꾼 파일). `tests/upgrade/flows.json` 에 동선 한 줄(「/tbox/?guide=file-share 로드 → PATCH /community/api/me/journeys」)이 그 파일 형식에 맞으면 더하고, 맞지 않으면 보고서에 적는다.

## 기대값만 바꿀 옛 시험 (그 줄만 · 돌리지 않는다 — 메인이 밤 회귀 대기열에 넣는다)

| 시험(카탈로그 id) | 줄(참고) | 전 → 후 | 닿는 것 |
|---|---|---|---|
| `tests/community/messenger_window_browser.py`(tbox.messenger_window_browser) | :663-664 | 「? 사용 안내」 누름 → 곧바로 「업무 홈」 투어 → 질문 카드가 뜬다 → 바닥 「이 화면 사용 안내」 누름 → 「업무 홈」 투어(:665 이후 단언 그대로) | D 4 · 5 |
| 〃 | :672 | 제안 제목 `'모아 사용 방법을 안내해 드릴까요?'` → `'무엇을 하고 싶으세요?'`(:674 「나중에 볼게요」 · :675 dismissed 그대로) | D 5 |
| `tests/community/home_front_browser.py`(community.home_front_browser) | :41-48 상수 | `EMP_ROWS` 주소 ① `/community/?guide=task-add` ② `/tbox/?guide=file-share` ③ `/community/workspace?guide=minutes-write` · ④ 공지 줄은 직원에서 빠짐(S3-1 A) · ⑤ `/community/?guide=message-send` · `ADMIN_ROWS` = 직원 넷 + ④ `/community/?guide=notice-post`(본래 자리) + ⑥ `/community/?guide=invite-colleague` · `ACTING_ROWS`(관리자의 직원 보기) = ①②③ · `GUEST_ROWS` 그대로 | G |
| 〃 | :469 | `'?guide=' not in r.text` → `?guide=` 는 쓰기 줄에만(손님 문맥 0) | G |
| 〃 | :545 | 메신저 창 여는 줄 `['메신저']` → `[]`(S3-2 A) | G |
| 〃 | :560-566 | 「동료에게 한마디」 누름 → 창이 열리고 주소 `/` → 주소 `/community/`(꼬리는 길잡이가 지움) · 그 화면에 진행 카드 | G |

**안 깨지는 것으로 확인(손대지 않는다)**: 제안 창을 「나중에 볼게요」로 닫는 시험 — `project_sections.py:42` · `memory_ai_http.py:91` · `memory_story_http.py:44` · `memories_runtime_browser.py:25` · `mcp/work_document_create_http.py:118` · `assistant/client_diagnostics_ui.py:19-22`(`dialog.usage-guide-dialog[open]` · 「나중에 볼게요」 · 닫은 뒤 `.usage-guide-dialog` 0 — D 5 가 지킨다) · 목업 `{guide}` 만 주는 `usage_guide_browser.py` · `messenger_resize_browser.py` · `moa_local_assist.py`(D 1) · `home_h1_browser.py`(홈 첫 로드 · 카드 꼬리 단언 0) · `qf13_owner_fix.py`(고정 목록 — 새 표 무관) · `dbg_u2_ui.py`(창 바닥 셈 — 진행 카드는 dialog 아님 · 질문 카드 바닥은 「닫기」 오른쪽 끝 하나 — 2.7 규칙 그대로인지 [layout] 에서 한 번 본다).

이 밖에 grep 으로 찾은 단언이 있으면 같은 방식으로(그 줄만) 고치고 보고서에 더한다.

## 되돌릴 수 없는 것 (보고서 「못 한 것」 옆에 그대로 적는다)

- 거의 없다 — 새 표 하나와 거기 쌓이는 진행 기록뿐이다. 되돌리면 코드를 되돌리고, 표를 지울지는 사용자가 정한다(지우면 진행 · 완성 기록이 사라진다).
- 여정을 한 번이라도 시작한 사람은 첫 로그인 제안을 다시 받지 않는다(옛 제안 기록 「dismissed」도 함께 남는다).
- S3-2 A: 홈 「동료에게 한마디」 줄이 홈에서 창을 바로 열지 않고 업무 홈으로 간다(코드로 되돌릴 수 있음).

## 반영 · 발행 (메인)

- **반영 전 백업**(반영 전 백업 규칙 — DB + 코드만 · 서버 최근 3개 · 나머지 외장하드): 백업이 끝났는지(크기 · 복호 시험 한 번) 확인하기 전에는 마이그레이션 0.
- **모의 적용**: 백업 덤프를 복원한 임시 DB(운영 아님 — 이름 `tbox_s3_mock_<날짜>`)에 마이그레이션 두 번 + `db/community/grants.sql` → 표 소유자 tbox_owner · 권한(앱 SIU · 백업 S · DELETE 0) · 두 번째 변화 0 · `tbox_backup` 으로 `pg_dump --schema-only -t community.guide_journeys` 성공 → 임시 DB 지움.
- **적용**: 코드 반영과 같은 창에서 — ① 마이그레이션(ARCH 10장 끝 계정 · 파일 안 `SET LOCAL ROLE tbox_owner`) ② `db/community/grants.sql` 다시(postgres) ③ 코드 · php-fpm reload ④ 운영 읽기로 소유자 · 권한 확인 ⑤ 운영 확인(claude_ 계정): GET 에 journeys · can · PATCH CSRF 없음 403 · 토큰 헤더만 401 · 홈 과업 카드 주소 · 「? 사용 안내」 질문 카드 · 자료실 `?guide=file-share` 로 진행 카드 2단계까지(완성을 만드는 올리기 · 글은 운영에서 하지 않는다 — 격리 수락이 잼) · 끝에 그 계정의 진행 행은 「나중에」로 둔다 ⑥ H 넷을 운영 읽기로 확인해 빠진 것을 사용자에게 알린다(만들지 않는다).
- **밤 회귀 대기열**(`~/.cache/qaredi-check/nightly/queue/<날짜>-qf15-s3.json`): 위 「옛 시험」 둘 + 「안 깨지는 것」 가운데 카탈로그에 있는 것(제안을 닫는 여섯 · `home_h1_browser` · `qf12_shell_browser` · `dbg_u2_ui` · `messenger_window_browser`) — 고른 목록 · 뺀 이유를 대기열 파일에.
- **기록**: SECURITY 7장 「Q:F-15 S3 — 새 표(표시 선호) · 새 PATCH 창구(세션 · CSRF · 토큰 창구 밖) · 사실 질의 여섯(내 행만 · 바인딩) · 회귀: 밤 회귀 대기(목록)」 · 사이트 STAGES · 위키 `wiki/qaredi` 단계 글 · `php scripts/wiki_check.php` 0.
- **발행** minor 한 번 — 제목 「하고 싶은 일 안내」 · 변경 문장(사용자 말 · 「길잡이」 낱말 0): ① 「모아 홈 「무엇을 하고 싶으세요?」에서 할 일을 고르면, 실제 화면을 넘어가며 눌러야 할 단추를 차례로 짚어 드려요 — 직접 눌러 끝까지 하면 완성돼요」 ② 「화면 오른쪽 아래 「? 사용 안내」에서도 같은 질문으로 시작하고, 하던 일은 「이어 하기」로 다시 이어요」 ③ 「안내가 떠 있어도 화면을 가리지 않아 뒤의 단추를 그대로 눌러 쓸 수 있어요」.
- **관문 ③(사용자 — 직접 해볼 공격 · 사람 수락)**: 다른 사람의 진행 행을 읽거나 바꾸기(0이어야 함) · 토큰 헤더만으로 PATCH(401) · 결과 없이 「마치기」 흉내(완성 0) · 주소 꼬리 장난(`?guide=../users` 등 — 아무 일 0) · Q:T-G16 동료 1명이 파일 올리기 · 한마디 · 회의록을 「이게 뭐야」 없이 끝냄(완성 행 3).
