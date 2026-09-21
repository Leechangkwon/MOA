# F-64 지시서 (Fable → Opus) — 구매·재고 모듈을 t-box 에서 떼어내 지운다 (27단계)

작성 2026-09-21 · Fable. 근거: PRD F-64 · Q-22 · T-76, ARCH 5.18(→ 묘비), SECURITY R-93, STAGES 27단계.

## 0. 배경 — 왜 지우나 (사용자 말 그대로)

- 강사 지시(2026-09-21, 사용자 전달): 「티박스랑 구매팀 솔루션이랑 분리 — 도메인.mso.so 로 접속시 기존솔루션, 도메인.mso.so/tbox 접속시 t-box」
- 사용자: 「현재 과제는 T박스만이었는데, 구매 모듈까지로 내가 확장한 것이 원인」 → 「구매 쪽을 t-box 에서 떼어내 지운다」 → 「t-box 에 파일로 올려서 백업」 → DB 는 「덤프 받고 내린다」
- 백업은 **이미 끝났다**(Fable, 2026-09-21 11:45): git 태그 `v1.13.0-with-purchase`(origin 에 push 됨) · `~/tbox-v2-purchase-backup-2026-09-21.zip`(git bundle + `p_*` 덤프 19표·3뷰·16시퀀스 + users 두 열 CSV + purchase.* 감사 5,931행 CSV, sha256 `2e56ce19…7fb01`). 사용자가 t-box 에 올린다.
- 이 단계 뒤에 **28단계 = t-box 를 `/tbox` 경로로 옮기는 nginx·base-path 작업**이 따로 온다. 이번 지시서에서는 **경로를 옮기지 않는다.**

```
목표        : 구매·재고 모듈(라우트·핸들러·화면·JS/CSS·표·정책·감사 문장·경보·린트·시험·문서·위키)을
              t-box 저장소에서 완전히 들어내고, t-box 본체(파일 공유·계정·감사·역할로 보기·위키…)는
              **한 기능도 달라지지 않게** 한다. DB 는 마이그레이션 파일만 만들고 실행은 사용자가 한다.
요구사항    : PRD F-64 · Q-22 · T-76, ARCHITECTURE 5.18(지운 뒤 묘비 3줄) · 13장 체크리스트, SECURITY R-93,
              CLAUDE.md 4장(운영 데이터 불변 — DROP·DELETE·UPDATE 를 운영 DB 에 실행하지 않는다. 예외 없음)
수정 파일   : 아래 1장 목록이 전부다. 목록 밖 파일은 만들거나 고치지 않는다.
금지 패턴   : 운영 DB(tbox_re)에 DDL/DML 실행 · `git add -A` · 커밋(커밋은 Fable 검토 뒤) · 시드 계정(admin01 등) 사용 ·
              경로 이동(/tbox) 착수 · 남는 t-box 기능의 동작 변경 · 문서 이력 행 삭제(STAGES 2장·SECURITY 7장은 지우지 않는다)
완료 기준   : PRD T-76 (a)~(g) — 3장
보고 형식   : 4장
```

## 1. 수정 파일 — 허용 목록

### 1-1. 삭제 (git rm) — 저장소 추적 파일 169개 + 아래 추가분
- `git ls-files | grep -i purchase` 가 내는 169개 **전부**: `src/purchase/`(11) · `src/handlers/purchase_*.php`(82) · `src/views/purchase_*.php`(13) · `public/assets/purchase*.{js,css}`(8) · `db/migrations/*_purchase_*.sql`(8) · `tests/purchase_*.sh`(13) · `docs/purchase/`(1-2. 이동분 제외 전부, `design/` 포함)
- `wiki/stage-19.json` ~ `wiki/stage-25.json` (7개)

### 1-2. 이동 (git mv) — `docs/purchase/` 아래 잘못 놓인 **t-box 지시서** 7개. 번호 접두를 떼고 `docs/` 로
| 지금 | 옮길 곳 |
|---|---|
| `docs/purchase/12-help-button-directive.md` | `docs/help-button-directive.md` |
| `docs/purchase/13-stage26-directive.md` | `docs/stage26-directive.md` |
| `docs/purchase/14-totp-time-sync-directive.md` | `docs/f42-totp-time-sync-directive.md` |
| `docs/purchase/15-f43-fetch-allowlist-directive.md` | `docs/f43-fetch-allowlist-directive.md` |
| `docs/purchase/16-f44-oauth-design.md` | `docs/f44-oauth-design.md` |
| `docs/purchase/18-f46-directive.md` | `docs/f46-directive.md` |
| `docs/purchase/19-f47-versioning-directive.md` | `docs/f47-versioning-directive.md` |
옮긴 뒤 `STAGES.md`·`PRD.md` 의 옛 경로 참조(현재 14곳)를 새 경로로 고친다. `11-`·`17-`·`20-`~`23-` 은 구매 것이라 삭제.

### 1-3. 새로 만드는 파일 (2개)
- `db/migrations/2026-09-21_purchase_removed.sql` — 2-6 참조
- `wiki/stage-27.json` — 2-9 참조

### 1-4. 편집 — 보안 핵심 파일 (CLAUDE.md 4장 목록 중 이번에 허용하는 것)
`src/Authz.php` · `src/Auth.php` · `src/Audit.php` · `src/Upload.php`(주석 1줄만) · `db/schema.sql` · `db/grants.sql` · `public/index.php`
**허용하지 않는 것**: `src/Db.php` · `src/Storage.php` · `deploy/**` 전부 · `.env*`

### 1-5. 편집 — 그 밖의 코드·스크립트·시험
`src/Preview.php`(주석) · `src/Wiki.php`(STAGE_MAX) · `src/help/screens.php` · `src/views/shell_top.php` ·
`src/handlers/{admin_page,me,me_act_start,me_act_stop,users_create,users_update,users_list,users_page}.php` ·
`public/assets/app.js` · `public/assets/app.css` ·
`scripts/alerts.php` · `scripts/security_lint.sh` · `scripts/ui_snapshot.sh` · `scripts/ui_snapshot.py` ·
`tests/{stage26,help_button,api_whoami,changelog,f54}.sh` ·
`wiki/index.json` · `wiki/guide-{what,roles,risks,review,docs-map}.json` · `wiki/security-tests.json` · `wiki/stage-17.json` · `wiki/stage-18.json`

### 1-6. 편집 — 문서 (규칙은 2-10)
`PRD.md` · `ARCHITECTURE.md` · `SECURITY.md` · `STAGES.md` · `README.md`
손대지 않는 것: `CHANGELOG.json`·`CHANGELOG.md`(발행이 만든다) · `API.md`(구매 언급 0) · `CLAUDE.md` · `VERSION`

## 2. 무엇을 어떻게

### 2-1. 라우터 `public/index.php`
- `TBOX_ROUTES` 의 19단계 구매 블록(주석 포함, 현재 285행 부근 `/* ── 19단계 — 구매·재고 모듈` 부터 그 블록 끝까지)과 그 뒤 20~24단계·F-45·F-60~F-63 이 더한 `/purchase/*`·`/api/purchase/*` 항목 **전부** 삭제.
- `TBOX_REAUTH_PATHS` 의 `purchasemasters`·`purchaseimport`·`purchaserecon`·`purchaseclose`·`purchasestruct` 키와 그 설명 주석 삭제. **쌍둥이 표** `app.js` `REAUTH_PATHS` 도 같은 키를 지운다(2-5).
- `TBOX_API_TOKEN_*` 세 상수에는 구매 라우트가 없다(확인만).

### 2-2. `src/Authz.php`
- `KNOWN_POLICIES` 의 `purchase.*` 7개와 주석 · `REAUTH_POLICIES` 의 `purchase.master.write`·`purchase.close` 와 주석 삭제.
- 「19단계 — 구매·재고 모듈의 권한 두 축」 절 전체(현재 823~1100행 부근: 상수 `PURCHASE_*`·`$purchaseFields`·`purchaseFields`·`purchaseAuthorize`·`purchaseScopeWhere`·`canUsePurchase`·`canEditPurchaseMaster`·`purchaseProfile`·`isAssignablePurchaseRole`) 삭제.
- `authorize()` 안의 `PURCHASE_PREFIX` 위임 분기 삭제. **그 밖의 판정 코드는 한 글자도 바꾸지 않는다.**

### 2-3. `src/Auth.php`
- `SQL_LOOKUP` 에서 `u.purchase_role`·`u.branch_id`·`s.act_purchase_role`·`s.act_branch_id` 네 열과 그 주석 삭제.
- `SQL_CLEAR_ACT` 에서 `act_purchase_role`·`act_branch_id` 두 줄 삭제.
- `self::$user` 에서 `purchase_role`·`branch_id`·`real_purchase_role`·`real_branch_id` 네 키와 주석 삭제. 「역할로 보기」의 나머지(role·team_id·real_*·acting*)는 그대로.

### 2-4. `src/Audit.php`
- `ALLOWED_DETAIL_KEYS` 의 19단계 구매 키 묶음 — **남는 코드가 쓰지 않는 키만** 지운다. 판단은 `grep -rn "'<키>'" src scripts` 로 하고, 보고서에 키별로 「지움/남김(어디서 씀)」을 적는다. (`file_id`·`sha256`·`rows` 같은 이름은 다른 곳에서 쓸 수 있다.)
- `SUMMARY_TEMPLATES` 의 `purchase.*` 행 전부 삭제(현재 637~972행 부근). 감사 표의 `summary` 열은 **기록 시점에 저장**되므로 옛 행의 표시는 깨지지 않는다 — 이 사실을 시험 (c)-⑦ 로 확인한다.
- `autoDetail()` 의 `acting_as` 배열에서 `purchase_role`·`branch_id` 두 키 삭제.

### 2-5. 화면·JS·CSS·도움말
- `shell_top.php`: `$shellKeys` 의 `purchase*` 12개 · `$shellActRoleLabels`~`p_branches` 조회 블록(역할로 보기 띠의 구매 부분) · `$shellCssAllowed` 를 `[]` 로(허용 목록 구조는 유지) · `#nav-purchase-group` 전체 삭제.
- `app.js`: `REAUTH_PATHS` 구매 5키 · `PURCHASE_ROLE_LABEL` · 계정 생성/편집 폼의 `u-branch`·`u-purchase-role`·`ue-branch`·`ue-purchase-role` 처리와 `/api/purchase/branches` fetch · 목록의 구매 역할 셀 · 「역할로 보기」 카드의 모듈 역할·지점 셀렉트 처리 · `can_purchase*` 로 내비를 여는 코드 — 전부 삭제. `node --check` 통과.
- `app.css`: 25단계 「공통 조각」 절(현재 1408행 부근 이후)의 클래스 중 **남는 뷰·JS 에서 참조가 0 인 것만** 삭제. 참조가 있는 것(배지 색 등 t-box 화면이 쓰는 것)은 남기고 주석의 「구매」 문구만 고친다. 판단 근거(클래스별 grep 결과)를 보고에 적는다.
- `src/help/screens.php`: `purchase-*` 11키 삭제 → 남는 키 수를 세어 PRD F-38 의 「20개」·`tests/help_button.sh` 의 기대값을 그 수로 고친다.
- `users_page.php`·`admin_page.php`: 지점·모듈 역할 `<select>` 와 설명 주석 삭제. `users_create/update/list.php`·`me.php`·`me_act_start/stop.php`: `branch_id`·`purchase_role` 입력 검증·SQL 열·응답 키·`p_branches` 조회 삭제. `me.php` 응답에서 `branch_id`·`purchase_role`·`can_purchase`·`can_purchase_master`·`acting_as.purchase_role`·`acting_as.branch_name` 제거 → `tests/api_whoami.sh` 의 키 목록 갱신.

### 2-6. DB — 파일만 만든다. **운영 DB 에 실행하지 않는다.**
`db/migrations/2026-09-21_purchase_removed.sql` (기존 마이그레이션 파일의 머리 주석 형식 그대로):
```sql
BEGIN;
DROP VIEW IF EXISTS p_backorder, p_stock_balance, p_line_chain;
DROP TABLE IF EXISTS p_recon_lines, p_recon_statements, p_month_close, p_location_inbox,
  p_list_items, p_lists, p_doc_lines, p_docs, p_doc_seq, p_stock_moves,
  p_par_levels, p_item_branches, p_import_mappings, p_items, p_categories, p_vendors,
  p_locations, p_branches, p_brands CASCADE;
ALTER TABLE users    DROP COLUMN IF EXISTS branch_id, DROP COLUMN IF EXISTS purchase_role;
ALTER TABLE sessions DROP COLUMN IF EXISTS act_branch_id, DROP COLUMN IF EXISTS act_purchase_role;
COMMIT;
```
(순서·CASCADE 는 FK 를 보고 네가 확정한다. 시퀀스는 표와 함께 사라진다.)
- `db/schema.sql`: `users` 의 두 열·`users_branch_id_fkey`·`users_purchase_role_idx`, `sessions` 의 `act_purchase_role`·`act_branch_id`·`sessions_act_purchase_role_check`, 그리고 `p_*` 절 전부(323행 부근 `-- ═══ 19단계` 부터 파일 끝의 구매 절까지) 삭제. `api_tokens`·`fetch_hosts` 등 **구매가 아닌 절이 그 사이에 있으면 남긴다** — 절 머리로 판단.
- `db/grants.sql`: `p_*` 관련 REVOKE/GRANT 와 `tbox_perm`·백업 역할 목록의 `p_*` 이름 삭제.
- **검증은 임시 DB 로**: `sudo -n -u postgres createdb tbox_f64_check` → `git show v1.13.0-with-purchase:db/schema.sql | sudo -n -u postgres psql -d tbox_f64_check -v ON_ERROR_STOP=1 -q` → 새 마이그레이션 적용 → `\dt p_*` 0 · `\d users`·`\d sessions` 에 네 열 없음 → 이어서 **새** `db/schema.sql` 을 또 다른 임시 DB `tbox_f64_fresh` 에 적용해 오류 0 · `\dt` 에 `p_*` 0 → 두 임시 DB 는 `dropdb` 로 지운다(임시 DB 는 운영 데이터가 아니다).

### 2-7. 경보·린트·스냅샷
- `scripts/alerts.php`: AL-14~AL-17 블록 · `PURCHASE_RULES`·구매 전용 상수·`$windowMinutes` 의 분기 · 머리 주석 삭제. `php -l` 과 `php scripts/alerts.php --dry-run`(기존 옵션 이름 확인) 이 오류 없이 돈다.
- `scripts/security_lint.sh`: (a)(c)(d)(e)·재고 절·(b) `purchase_role` 절 삭제. 남는 절 번호는 **그대로**(다른 문서가 (f)(g) 로 부른다). 실행 결과 0건·exit 0.
- `scripts/ui_snapshot.py`: 기본 `PAGES` 를 t-box 화면 9개(`/`, `/settings`, `/activity`, `/admin`, `/users`, `/org`, `/admin/permissions`, `/audit`, `/trash`)로. `ui_snapshot.sh` 22행 주석 수정.

### 2-8. 시험 스크립트
- `tests/stage26.sh`: 구매 픽스처(브랜드·지점·장소·거래처·품목·PR)와 그것을 쓰는 항목·정리 SQL·`purchase_role`/`branch_id` 원복 삭제. 「역할로 보기」의 역할·팀 항목은 그대로. 통과해야 한다.
- `tests/help_button.sh`: 구매 11화면 항목·`D_PAGES` 의 `/purchase/*`·「회귀 — 구매 11화면」 절 삭제, 기대 개수 갱신.
- `tests/changelog.sh` 155~156행: `/purchase/pr` → `/activity`. `tests/f54.sh` 500행: 구매 두 파일 참조 삭제. `tests/api_whoami.sh` 246행 키 목록 갱신.

### 2-9. 위키
- `wiki/stage-19~25.json` 삭제, `wiki/index.json` 의 그 항목과 안내 글 요약의 구매 문구 삭제. `guide-what`·`guide-roles`·`guide-risks`·`guide-review`·`guide-docs-map`·`security-tests`·`stage-17`·`stage-18` 의 구매 문장을 지우거나 「(구매 모듈은 2026-09-21 제거)」 로 바꾼다.
- `wiki/stage-27.json` 새로: 제목 「27단계 — 구매·재고 모듈을 떼어냈다」, `stage: 27`. 절 3개 — 왜(0장 사용자 말) · 무엇을 지웠나(라우트·표·정책·화면·문서, 숫자) · 어디에 남았나(태그·zip). 형식은 `stage-18.json` 과 같게, 글은 초보자 말로.
- `src/Wiki.php` `STAGE_MAX = 27`. `php scripts/wiki_check.php` 0건.

### 2-10. 문서 규칙 — **이력은 지우지 않고, 요구사항은 지운다**
- `PRD.md`: F-28~F-37·F-45·F-60~F-63 행, 8장 `/purchase/*` 행, 9장 `p_*` 행, 10장 구매 화면 행, 12장 T-42~T-50·T-57·T-72~T-75 행, 7.11 AL-14~17 행 **삭제**. 13장 19~25단계 행은 **한 행**으로 합쳐 「19~25 — 구매·재고 모듈 (2026-09-21 제거, F-64 · 태그 v1.13.0-with-purchase)」. 14장 Q-17~Q-20 의 상태 칸에 「철회 2026-09-21 (F-64)」. F-38·F-39·F-40·F-53·F-54·26단계 행의 구매 문구 삭제(F-38 은 화면 수 갱신). F-64·Q-22·T-76 은 Fable 이 이미 넣었다 — 손대지 않는다.
- `ARCHITECTURE.md`: 5.18 절 본문을 지우고 제목 아래 묘비 3줄 — 「2026-09-21 F-64 로 제거. 설계는 태그 v1.13.0-with-purchase 의 이 절과 docs/purchase/ 에 있다. 절 번호는 5.19 이후가 흔들리지 않게 남긴다」. 그 밖의 구매 언급(현재 1360~1390 · 1762 · 1906~2000 · 2053~2072 · 2220 · 2319~2330행 부근)은 문장 단위로 지우거나 「제거됨」 으로.
- `SECURITY.md`: R-65~R-73·R-78·R-92 행은 **지우지 않고** 설계 상태 칸을 「✅ 해소 — 모듈 제거 2026-09-21 (F-64)」 로. 7장 이력 행은 그대로. R-93 은 Fable 이 넣었다.
- `STAGES.md`: 0장 19~25·F-45·F-60·F-61 행의 상태 칸을 「🗑 제거 2026-09-21 (27단계)」 로, 행은 남긴다. 2장 19~25단계·F-45·F-60~F-63 절은 **제목 앞에** 「(제거됨 2026-09-21 → 27단계) 」 만 붙이고 본문은 그대로. 26단계 절의 옛 지시서 경로만 고친다. 27단계 절은 Fable 이 넣었다 — 「① 구현」 체크 표만 네가 채운다.
- `README.md`: 구매 사용 안내 절(현재 699~903 · 937~1144행 부근, 절 제목으로 경계 확인)을 삭제하고 294~297행 부근 문구를 고친다.

## 3. 완료 기준 — PRD T-76
(a) 잔재 0 — `grep -rniE "purchase|branch_id|\bp_(docs|items|branches|brands|vendors|locations|stock_moves|categories|lists|recon|par_levels|item_branches|import_mappings|location_inbox|doc_seq|doc_lines|list_items|month_close)\b" src public db scripts tests wiki deploy` 가 **새 마이그레이션 파일 한 곳** 말고 0건 · `grep -rn "구매" src public scripts tests wiki` 0건 · `PRD.md`·`ARCHITECTURE.md`·`README.md` 의 `purchase|구매` 는 F-64·Q-22·T-76·13장 합친 행·5.18 묘비·「제거됨」 표기에만 남음(줄 번호를 보고에 적는다)
(b) 정적 — 바뀐 `.php` 전부 `php -l` · `node --check public/assets/app.js` · `scripts/security_lint.sh` 0건 exit 0 · `php scripts/wiki_check.php` 0건 · `bash -n` 바뀐 `.sh`
(c) 살아 있음(운영 DB 는 아직 열이 남은 상태에서, 검토 계정 `qa_admin01`·`-A "tbox-review/2026-09-21"`) — ① 로그인 200 ② `/`·`/settings`·`/activity`·`/admin`·`/users`·`/org`·`/admin/permissions`·`/audit`·`/trash`·`/wiki` 전부 200, 본문에 `purchase`·「구매관리」 0 ③ `/api/me` 응답 키에 `purchase_role`·`branch_id`·`can_purchase`·`can_purchase_master` 없음 ④ `/purchase/pr`·`/api/purchase/items`·`/api/purchase/branches` → 404 ⑤ 계정 만들기(팀만) 201 · PATCH(팀 변경) 200 · 본문에 `branch_id` 를 넣으면 400(허용 키 밖) ⑥ 「역할로 보기」 시작 `{role:Employee}` 200 → `/api/me` `acting:true` → 해제 200 ⑦ `/api/audit?action=purchase.master.create` 로 옛 행이 **summary 문장과 함께** 200 (문장 표를 지워도 표시가 깨지지 않음) ⑧ `/s/…` 공유·업로드 한 건(txt) 201 — t-box 본체 회귀
(d) 회귀 — `tests/stage26.sh`·`tests/help_button.sh`·`tests/api_whoami.sh`·`tests/changelog.sh`·`tests/f54.sh`·`tests/api_tokens.sh` 전부 통과(각각 ✔/✘ 수)
(e) 화면 — `scripts/ui_snapshot.sh <out> 1440` t-box 9화면 결함 합계 ≤ 7(T-51 (d) 기준선) · 콘솔 오류 0
(f) DB — 2-6 의 임시 DB 두 개 검증 결과 원문(`\dt p_*` 0, `\d users`·`\d sessions`, 새 schema.sql 오류 0), 그리고 **운영 DB 에는 `\dt p_*` 가 여전히 19개**(네가 손대지 않았다는 증거)
(g) 수 — 삭제 파일 수(추적 169 + wiki 7 + 이동 7 = `git status` 로 셈), 편집 파일 목록

## 4. 보고 형식
변경 파일 목록(삭제/이동/신규/편집 넷으로 나눠) / 실행한 명령과 출력 원문(3장 (a)~(g) 순서, 항목마다) / 2-4 키별 판단표 · 2-5 CSS 클래스별 판단표 / 못 한 것과 이유(추정으로 「됐다」 쓰지 않는다) / 사용자가 실행해야 할 것: `sudo -u postgres psql -d tbox_re -v ON_ERROR_STOP=1 -f db/migrations/2026-09-21_purchase_removed.sql`
