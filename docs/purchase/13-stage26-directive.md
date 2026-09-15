# 26단계 구현 지시서 (Fable → Opus) — 관리자 「역할로 보기」 · 관리 내비 · 계정 편집 폼 · 폴더 삭제 규칙

작성 2026-09-15. 근거: 사용자 결정 2026-09-15 — ① 「역할로 보기」 A안(세션 안 임시 역할, 아래로만) ② 구매 모듈 역할·지점까지 고름 ③ 만료는 **활동 없는 30분**만(쓰는 중엔 끊기지 않음). 같은 날 지적 4건: 관리자 페이지에 들어가면 사이드바의 「관리자 페이지」가 사라짐 / 계정·조직 화면에서 뒤로가기로만 돌아옴 / 계정 변경이 문답(prompt) 텍스트 입력이라 불편 · 「변경했는데 로그에 없다」 / 빈 폴더(qa-18r-T) 삭제가 거부됨.
PRD F-39·F-40·F-41 · 12.2 T-52·T-53·T-54 · Q-21 ; ARCH 5.20 ; SECURITY R-74·R-75 ; STAGES 26단계.

## 0. 검토자가 확인한 사실

| 지적 | 실측 | 원인 |
|---|---|---|
| 「권한 설정을 변경했는데 로그에 없다」 | 오늘 admin01 의 감사 기록은 audit.view 3 · auth.reauth 1 · auth.gate 1 · folder.delete deny 1 뿐. `user.role_change`·`permission.change` 0건 | **변경이 저장되지 않았다.** 계정 변경은 `window.prompt` 6번(역할→팀 번호→만료일→지점 번호→모듈 역할→사유)을 차례로 묻고, 하나라도 취소하면 조용히 `return` 한다(`app.js:3549-3590`). 화면에 아무 알림이 없어 「바꿨다」고 느끼기 쉽다 |
| 관리자 페이지에서 사이드바 「관리자 페이지」 사라짐 | `app.js:5513` `inGate = me.next !== ''` 가 참이면 모든 내비를 닫고 관리 그룹도 닫는다(`5540`). `/admin` 은 재인증 관문이라 첫 응답의 `next` 가 `reauth` 인 순간 내비가 닫히고, 재인증 뒤 `/api/me` 를 다시 받기 전까지 닫힌 채다 | `inGate` 는 최초 로그인 강제 절차(`password_change`·`mfa_setup`, `Auth::nextStep`)용인데 `reauth` 까지 같은 취급 |
| 계정·조직 화면에서 뒤로가기로만 | 사이드바 「관리」 묶음에 링크가 「관리자 페이지」 하나(`shell_top.php:247-253`). 하위 6화면은 허브 카드로만 진입 | 설계(12단계)가 그랬음 |
| 빈 폴더 삭제 거부 | qa-18r-T: 하위 0 · 살아 있는 파일 0 · **휴지통 파일 있음**. `folders_delete.php:92-102` 는 `purged_at IS NULL` 인 파일(휴지통 포함)을 세어 `folder_not_empty` 400. 문구 「폴더가 비어 있지 않습니다 (휴지통의 파일도 포함)」 | 규칙대로 동작했지만, 휴지통 파일은 30일 뒤 배치가 지울 때까지 사용자가 없앨 방법이 없어 폴더도 30일간 못 지운다. qa-18-3 은 하위 폴더 1개가 진짜 원인 |

```
목표        : (F-39) 실제 역할이 Admin 인 사람이 「역할로 보기」를 켜면, 그 세션의 권한 판정·목록·구매 격리가 고른 역할(t-box 역할+팀, 구매 모듈 역할+지점) 그대로 동작한다 — 화면 흉내가 아니라 서버 판정이다.
              아래로만, 재인증 뒤, 활동 없는 30분이면 자동 해제, 감사에 실제 계정과 보는 역할이 함께 남는다.
              (F-40) 관리 내비: 사이드바 「관리」 묶음에 하위 6화면 링크 · 재인증(next=reauth)은 내비를 닫지 않음 · 계정 변경은 prompt 대신 모달 폼(셀렉트·날짜·사유) 하나.
              (F-41) 폴더 삭제: 남은 것이 휴지통 파일뿐이면 확인 뒤 지울 수 있고(그 파일은 루트로 옮겨진 채 휴지통에 남음), 거부 문구는 무엇이 막는지 말한다.
요구사항    : PRD F-39·F-40·F-41 · 5장 권한 매트릭스 · S-01(서버 판정)·S-03·S-07(감사) · 12.2 T-52·T-53·T-54 ; ARCH 4.0(요청 흐름)·4.1·5.20(신설)·7.4(감사 문장)·10장 ; SECURITY R-74·R-75(신설)·R-05(재인증)·R-63(폴더 삭제 응답) ;
              기존 코드: src/Auth.php(currentUser 세션 SQL·reauth·nextStep) · src/Authz.php(authorize·can*·purchaseAuthorize·purchaseScopeWhere — 전부 `$u['role']`·`$u['team_id']`·`$u['purchase_role']`·`$u['branch_id']` 를 읽는다) · src/Audit.php(actor()/autoDetail) ·
              src/handlers/me.php · reauth.php · users_update.php(허용 키·decideUserChange) · folders_delete.php · files_trash.php · public/assets/app.js(initShell 5505~·계정 변경 3540~·폴더 삭제 2116) · src/views/shell_top.php(관리 묶음 245~) · src/handlers/admin_page.php(카드).
수정 파일   :
  [새로 만듦]
    db/migrations/2026-09-16_sessions_act_as.sql     — sessions 에 act_role text NULL · act_team_id bigint NULL · act_purchase_role text NULL · act_branch_id bigint NULL · act_started_at timestamptz NULL · act_last_seen_at timestamptz NULL
                                                        + CHECK (act_role IS NULL OR act_role IN ('Manager','Employee','Guest')) · CHECK (act_purchase_role IS NULL OR act_purchase_role IN ('requester','buyer','stock','viewer')). db/schema.sql 동기화. grants 변경 없음(sessions 는 이미 앱 계정이 UPDATE).
    src/handlers/me_act_start.php                    — POST /api/me/act  body {role, team_id?, purchase_role?, branch_id?}. 정책 `role:Admin`(REAUTH_POLICIES 에 넣어 재인증 관문 자동). **실제 역할**(아래 `real_role`)이 Admin 이 아니면 403(보는 중에 다시 켜기 금지 — 먼저 끄게 한다). 값 검사: role ∈ Manager|Employee|Guest, team_id 는 활성 팀 또는 null, purchase_role ∈ 4값 또는 null, branch_id 는 활성 지점 또는 null(purchase_role 이 requester|stock 이면 branch_id 필수 — 그 역할은 지점 없이는 목록이 1=0 이라 시험 의미가 없다). sessions 갱신(act_* 6열, started·last_seen=now()). 감사 `act.start allow` detail {role, team_id, purchase_role, branch_id}. 응답 = /api/me 와 같은 모양.
    src/handlers/me_act_stop.php                     — DELETE /api/me/act. 로그인만 있으면 됨(정책 authenticated — 보는 중인 세션의 유효 역할은 Admin 이 아니므로 role:Admin 이면 끌 수 없다). act_* 6열 NULL. 감사 `act.stop allow`. 응답 = /api/me.
    tests/stage26.sh                                 — T-52·T-53·T-54. qa_admin01·qa_employee01, UA tbox-review, 데이터는 세션·폴더(접두 `S26-`)만, 정리는 qa created_by.
  [고침 — 보안 핵심 파일 포함, 아래 범위만]
    src/Auth.php          — currentUser(): 세션 SQL 에 act_* 6열 추가. 반환 배열에 `real_role`(u.role) · `real_team_id` · `real_purchase_role` · `real_branch_id` · `acting`(bool) · `acting_since` 추가하고,
                            **act_role 이 NULL 이 아니고 u.role='Admin' 이고 act_last_seen_at > now() - 30분** 이면 `role`·`team_id`·`purchase_role`·`branch_id` 를 act_* 값으로 바꿔 돌려준다(Authz 는 손대지 않아도 그대로 따라온다).
                            같은 요청에서 `UPDATE sessions SET act_last_seen_at = now()`(활동 갱신 — 기존 last_seen_at 갱신문에 한 열 추가, 쿼리 수 증가 0).
                            만료(30분 지남) 또는 u.role 이 Admin 이 아니게 됨(강등)이면 act_* 를 NULL 로 되돌리고 감사 `act.expire allow`(사유 idle|role_lost) 한 줄 — 이때만 UPDATE 1회 추가.
                            reauth(): 보는 중에도 실제 비밀번호로 재인증되어야 한다(변경 없음이면 확인만).
    src/Authz.php         — KNOWN_POLICIES 변경 없음. REAUTH_POLICIES 에 영향 없음(정책 `role:Admin` 이미 재인증 대상인지 확인 — 아니면 `/api/me/act` 라우트에 쓸 정책 하나를 재인증 목록에 넣는다). **역할 판정 코드 변경 0** — 유효 역할이 이미 $u 에 들어온다.
    src/Audit.php         — autoDetail(): `acting` 이 참이면 detail 에 `acting_as: {role, team_id, purchase_role, branch_id}` 와 `real_role` 을 자동으로 붙인다. `actor_role` 열은 **실제 역할**(real_role)을 기록한다(감사의 「누가」는 사람이다). 문장 틀에 act.start / act.stop / act.expire 추가.
    src/handlers/me.php   — 응답에 `acting`, `acting_as {role, team_name, purchase_role, branch_name}`, `real_role`, `acting_idle_minutes`(30) 추가. can_* 값은 유효 역할 기준 그대로(그래야 화면이 그 역할로 보인다). 단 **`can_stop_acting`**(acting 참) 추가.
    public/index.php      — 라우트 2줄: POST /api/me/act(role:Admin) · DELETE /api/me/act(authenticated). 그 밖 변경 없음.
    src/views/shell_top.php — (a) 「관리」 묶음에 링크 7개: 관리자 페이지 · 계정 관리(/users) · 조직·권한(/org) · 권한 매트릭스(/admin/permissions) · 감사 로그(/audit) · 휴지통(/trash) · 위키(/wiki). 기본 hidden, app.js 가 can_admin 으로 함께 연다(역할 비교 없음 유지).
                              (b) 서버가 `$user['acting']` 이면 `.page-head` 위(=main 첫 자식)에 띠 `<div class="act-banner" role="status">지금 <b>{role} · {team_name} · {purchase_role}({branch_name})</b> 으로 보는 중 — 실제 계정 {username}(Admin) <button data-act-stop class="btn btn--quiet btn--small">원래대로</button></div>` — 값은 htmlspecialchars. 보는 중에는 사이드바 「관리」 묶음이 닫히므로 이 띠가 유일한 복귀 경로다(그래서 서버가 그린다).
                              (c) 사용자 칩의 역할 표시는 유효 역할 + 「(보기)」.
    src/handlers/admin_page.php — 카드 「역할로 보기」 추가: 폼(역할 셀렉트 Manager/Employee/Guest · 팀 셀렉트 · 구매 모듈 역할 셀렉트 · 지점 셀렉트 · 「이 역할로 보기」). 셀렉트 값은 기존 API(/api/teams 또는 org, /api/purchase/branches)로 채운다. 안내 한 줄: 「활동이 없으면 30분 뒤 자동으로 원래 역할로 돌아옵니다.」
    public/assets/app.js  — (1) `inGate` 를 `me.next === 'password_change' || me.next === 'mfa_setup'` 로(reauth 는 내비를 닫지 않음) ; (2) 관리 하위 링크 7개를 can_admin 으로 열기 ; (3) `[data-act-stop]` → DELETE /api/me/act → 새로고침 ; 역할로 보기 폼 submit → POST → 새로고침 ;
                            (4) **계정 변경 모달**: prompt 6개를 `<dialog id="user-edit">` 하나로 — 역할 셀렉트(Admin/Manager/Employee/Guest, 현재값 선택) · 팀 셀렉트(이름, 「팀 없음」) · 만료일 date · 구매 모듈 지점 셀렉트(이름) · 구매 모듈 역할 셀렉트(요청/구매/재고/검토/없음) · 사유 textarea(필수·500자) · 「저장」「취소」. 바뀐 칸만 payload 에 넣고, 바뀐 칸이 없으면 「바뀐 내용이 없습니다」 를 #msg 에, 취소·실패도 #msg 에 알린다(조용한 return 금지). 임시 비밀번호 재발급·2단계 초기화·비활성화의 사유 입력도 같은 모달 형식(`<dialog id="reason-dialog">`)으로 — `window.prompt` 는 계정 화면에서 0개.
                            (5) 폴더 삭제: 400 `folder_has_trashed` 를 받으면 확인 창 「이 폴더에는 휴지통에 있는 파일만 남았습니다. 폴더를 지우면 그 파일은 루트로 옮겨진 채 휴지통에 남습니다(복구 가능). 지울까요?」 → 예면 `?move_trashed=1` 로 다시 DELETE. 400 `folder_has_children` 은 「하위 폴더를 먼저 지우세요」.
    src/views/users.php(있으면) / src/handlers/users_page.php — 모달 마크업 2개(user-edit·reason-dialog). textContent 만.
    src/handlers/folders_delete.php — [2] 를 세 갈래로: 하위 폴더 있음 → 400 `folder_has_children` / 살아 있는 파일(deleted_at IS NULL) 있음 → 400 `folder_not_empty` / 휴지통 파일만 있음 → `move_trashed=1` 이 없으면 400 `folder_has_trashed`, 있으면 같은 트랜잭션에서 `UPDATE files SET folder_id = NULL WHERE folder_id=:id AND deleted_at IS NOT NULL AND purged_at IS NULL` 뒤 폴더 삭제, 감사 detail `moved_trashed: N`(개수만 — R-63). 거부 응답은 개수·이름 없이 코드만(R-63 유지).
    src/Http.php          — 문구 상수 3개: MSG_FOLDER_HAS_CHILDREN 「하위 폴더가 있습니다 — 먼저 지우세요」 · MSG_FOLDER_NOT_EMPTY 「파일이 있는 폴더는 지울 수 없습니다」 · MSG_FOLDER_HAS_TRASHED 「휴지통에 있는 파일만 남았습니다 — 확인하면 파일을 루트로 옮기고 폴더를 지웁니다」.
    src/help/screens.php  — admin 도움말에 「역할로 보기」 절, files 도움말의 삭제 항목 한 줄 갱신, users 도움말 「바꾸기」를 모달 기준으로.
    scripts/security_lint.sh — 새 규칙 1개: `act_role|real_role` 문자열 비교가 src/Auth.php·src/Audit.php·src/handlers/me_act_*.php 밖에 있으면 반려(역할로 보기 판정이 흩어지지 않게).
    PRD.md · ARCHITECTURE.md · SECURITY.md · STAGES.md — 지시서와 같은 내용(F-39~41 · T-52~54 · Q-21 · 5.20 · R-74·R-75 · 26단계 개발 사항 체크). README 「관리자」 절에 역할로 보기 한 문단.
금지 패턴   : 요청 파라미터의 role 값을 판정에 쓰는 코드(보는 역할은 **세션 열**에서만 읽는다) · 역할 비교를 Authz·Auth 밖에서 하는 코드 · 위로 올라가는 조합(Admin 으로 보기, 보는 중 다시 켜기) · 실제 역할(users.role) 변경 · 만료를 「시작 뒤 30분」으로 구현(활동 없는 30분이어야 함) · 감사 actor_role 에 보는 역할을 적는 것 · innerHTML · 인라인 style · window.prompt(계정 화면) · 폴더 삭제에서 살아 있는 파일을 옮기거나 지우는 코드 · 휴지통 파일을 폴더와 함께 purge 하는 코드 · 시드 계정 사용 · 운영 표 DELETE/TRUNCATE.
완료 기준   : tests/stage26.sh 로 실행(원문 첨부) —
             **T-52 역할로 보기** (a) qa_employee01 이 POST /api/me/act → 403, 감사 deny · (b) qa_admin01 재인증 없이 → 403 next:reauth · 재인증 뒤 {role:Employee, team_id:영업팀} → 200, /api/me 가 role Employee·acting true·real_role Admin·can_admin false·can_stop_acting true · (c) 그 세션으로 GET /api/files → admin-only 파일이 목록에 없고(Employee 판정), DELETE 남의 파일 403, GET /users 302/403(Admin 화면 막힘) · (d) {role:Employee, purchase_role:requester, branch_id:S26 지점} → /api/purchase/pr 목록이 그 지점만(다른 지점 픽스처 0건), /purchase/masters 편집 API 403 · (e) 보는 중 POST /api/me/act 다시 → 403 · (f) DELETE /api/me/act → 200, /api/me role Admin·acting false, 감사 act.stop · (g) 만료: psql 로 act_last_seen_at 을 31분 전으로 돌린 뒤 GET /api/me → role Admin·acting false, 감사 `act.expire` reason idle · 반대로 29분 전이면 유지 · 요청마다 act_last_seen_at 이 갱신됨(psql 두 번 비교) · (h) 보는 중 admin01 이 아닌 다른 Admin 이 qa_admin01 을 Manager 로 강등하면(시험은 psql 로 users.role 변경 뒤 복원) 다음 요청에서 acting 해제 · (i) 보는 중 한 행동(파일 목록·업로드)의 감사 행 actor_role='Admin' 이고 detail.acting_as.role='Employee' · (j) {role:'Admin'}·{purchase_role:'requester'}(지점 없음)·{branch_id:존재하지 않음} → 400 · (k) 화면: 띠 「지금 Employee … 으로 보는 중 — 원래대로」 가 보이고(ui_snapshot 으로 /purchase/pr 캡처 후 텍스트 확인) 사이드바 관리 묶음은 닫힘 · 「원래대로」 뒤 열림.
             **T-53 관리 내비·계정 폼** (a) 재인증 만료 상태에서 /admin 을 열어도 사이드바 항목이 닫히지 않음(ui_snapshot: `#nav-admin` hidden=false 인지 DOM 검사 — 스크립트를 고치지 않고 별도 Playwright 조각) · (b) 사이드바 관리 링크 7개가 can_admin 으로 함께 열림·qa_employee01 에게는 닫힘 · (c) 계정 화면에 `window.prompt` 0(정적 grep) · 모달에서 역할만 바꾸고 저장 → PATCH 본문에 role·reason_text 만 · 바뀐 칸 없이 저장 → 요청 0·#msg 문구 · 취소 → #msg 문구 · (d) T-26 회귀.
             **T-54 폴더 삭제** (a) 하위 폴더 있는 폴더 → 400 folder_has_children · (b) 살아 있는 파일 있는 폴더 → 400 folder_not_empty · (c) 휴지통 파일만 있는 폴더 → 400 folder_has_trashed(개수·이름 없음) · `?move_trashed=1` → 200, 폴더 삭제, 그 파일 folder_id NULL·deleted_at 유지·휴지통 목록에 그대로, 감사 folder.delete detail moved_trashed=N · (d) 복구 → 루트로 복구됨 · (e) 응답 본문에 파일 이름·개수 0(R-63).
             회귀: tests/purchase_stage19.sh · purchase_stage25.sh (a) 1440 · help_button.sh · T-1·T-22·T-26·T-36 관련 기존 스크립트 ; scripts/security_lint.sh 0건(새 규칙 포함) ; php -l ; `\d sessions` 로 열 6개.
보고 형식   : 변경 파일 / 실행 명령·출력 원문(T-52~54 응답, psql, 회귀 수치, 린트) / 스크린샷(띠·모달·관리 내비) 경로 / 못 한 것과 이유 / ARCH 13장 체크리스트.
```

## 1. 왜 이렇게 설계했나 (구현자가 흔들리지 않게)

- **세션 열에 둔다**: 보는 역할은 「이 브라우저의 이 로그인」에만 붙는다. users 표를 건드리면 실제 권한이 바뀌고 되돌릴 사람이 없어진다(B안 기각 이유).
- **Auth::currentUser 에서 바꾼다**: 그 한 곳이 모든 핸들러·Authz·purchaseScopeWhere 가 읽는 `$u` 를 만든다. 거기서 유효 역할로 바꿔 두면 판정 코드는 한 줄도 안 고치고 그 역할이 된다 — 흉내가 아니라 진짜 판정이다.
- **아래로만**: 시작 조건이 `real_role = Admin` 이고 고를 수 있는 값이 Manager·Employee·Guest 뿐이므로 권한이 늘어나는 조합이 없다. 보는 중에는 유효 역할이 Admin 이 아니라 `/api/me/act` POST 자체가 403 — 겹쳐 켜기 불가.
- **활동 없는 30분**: `act_last_seen_at` 을 요청마다 갱신하고 그 값으로 만료를 본다. 「시작 뒤 30분」이면 쓰는 중에 끊긴다(사용자 지적).
- **감사**: `actor_role` 은 실제 역할, `detail.acting_as` 가 보는 역할. 「누가」를 흐리지 않으면서 「어떤 역할로 보였나」가 남는다.
- **끄기는 authenticated**: 보는 중 유효 역할이 Employee 라 role:Admin 정책으론 끌 수 없다. 끄기는 권한을 **줄이는** 동작이 아니라 원래로 돌아가는 동작이지만, 세션에 act_* 가 있는 사람만 의미가 있으므로 핸들러가 `acting` 이 아니면 204/그대로.
- **폴더 삭제**: 휴지통은 「30일 안에 되살릴 수 있다」는 약속이다. 폴더를 지우면서 파일을 루트로 옮기면 약속이 지켜지고(복구 가능), 폴더는 지울 수 있다. 살아 있는 파일은 절대 옮기지 않는다 — 사용자가 모르는 채 위치가 바뀌면 안 된다.

## 2. 화면 문구 (그대로)

- 관리자 페이지 카드: 제목 「역할로 보기」 / 설명 「다른 역할·팀·지점의 눈으로 화면과 권한을 확인합니다. 활동이 없으면 30분 뒤 자동으로 원래 역할로 돌아옵니다.」 / 버튼 「이 역할로 보기」
- 띠: 「지금 **Employee · 영업팀 · 요청(부평점)** 으로 보는 중 — 실제 계정 admin01 」 + 「원래대로」. 값이 없는 칸은 생략(팀 없음이면 「Employee · 요청(부평점)」).
- 계정 변경 모달 제목 「계정 「{username}」 변경」, 저장 뒤 #msg 「저장했습니다」, 바뀐 칸 없으면 「바뀐 내용이 없습니다」, 취소 「취소했습니다」.
- 폴더: 위 Http 상수 3개 그대로.
