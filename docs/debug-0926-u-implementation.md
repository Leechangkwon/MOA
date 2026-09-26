# 디버깅 묶음 U — 구현 기록 (디자인 점검 2026-09-26 12:13 의 높음 다섯 + 중간 일부)

| 항목 | 내용 |
|---|---|
| 근거 | 취합 세션(changkwon-32) 지시 「디버깅 수정 묶음 U」 · 사용자 지시 「디버깅 집중 — UI/UX 가 깔끔하고 알아보기 쉽고 정리된 모습인지」(2026-09-26 09:2x) · 디자인 점검 스크린샷 238장 `~/.claude/ui-shots/debug-design-0926/` |
| 작업 자리 | 복제본 `~/.local/share/qaredi-dev/d1uw` — 운영 t-box `a57cf6b`(v2.29.0)에서 복제, push 막음, 작성자 claude |
| 커밋 · 태그 | 아래 9장 |
| 운영 반영 | **하지 않았다** — 취합 세션이 한다(`review-scripts/apply-dbg-u-repo-2026-09-26.sh`) |
| 새 기능 | 없다 — 디버깅(화면 결함)만. 새 라우트 0 · 새 판정 0 · 새 색값 0 |

규칙 그대로: 운영 트리·운영 DB(`tbox_re`)·`/etc`·운영 포트(80·8082·8081)에 쓰지 않았다(읽기·`pg_dump` 파이프만). 시험은 임시 DB `tbox_d1u_*` · 임시 인스턴스 `php -S` 8221~8223 에서만.
검토 계정 `claude_` 셋(employee01·admin01·guest01) · UA `tbox-review/<날짜>` · 비밀은 표준입력으로만. 다른 에이전트가 같은 시각에 고치는 `src/handlers/*`(share·activity·folders·fetch·users)·`src/Upload.php`·`src/Authz.php`·`scripts/alerts.php` 는 건드리지 않았다(계정 관리 화면 `users_page.php` 도 — 역할 고르개 글자는 app.js 가 바꾼다).

---

## 1. H1 좁은 폭에서 주요 동작이 화면 밖 — 행을 쌓는다

| 무엇 | 어떻게 |
|---|---|
| 규칙 하나 | `app.css` — 720px 미만에서 `table.files:has(> tbody > tr > td.actions)` 의 행을 flex 로 쌓는다: **이름 줄**(첫 칸) · **값 줄**(나머지, 13px, 칸 앞 이름표) · **동작 줄**(`td.actions`). 머리 줄은 숨고 「모두 고르기」 체크가 든 머리 칸만 남는다. 빈 칸은 숨는다. 표 모양일 때의 열 최소 폭(`#files-body td:nth-child(n)` 등 — id 라 무겁다)은 쌓이면 0 으로 |
| 고르는 법 | 화면마다 클래스를 달지 않는다 — 행 동작 칸이 있는 표면 저절로(파일 · 내 휴지통 · 전체 휴지통 · 내 공유 링크 · 내 토큰 · 계정 · 조직 구성원 · 공유 링크 전체 · 커뮤니티 휴지통·템플릿·이력·임베드 창). **창 안의 표도 같은 규칙**이라 `.list` 와 같은 모양(구분선·여백 10px 14px·hover) |
| 이름표 | `app.js` `labelStackTables`/`watchStackTable` — 표 머리(th) 글자를 칸의 `data-label` 로 옮기고(숨은 머리 칸 건너뜀 · 체크 든 칸은 `span[id]` 글자) tbody·thead 의 바뀜을 지켜본다. CSS 가 `attr(data-label)` 로 그린다 — 인라인 style 0 · 서버 값 0 |
| 계정 표 | 열 아홉 + 글자 단추 다섯이라 폴드 펼친 820 에서도 잘렸다 → **960px 미만**부터 같은 모양(같은 선언을 계정 표 선택자로 한 번 더) |
| 첫 칸이 번호인 표 | 계정 표(id) · 이력 표(판 번호)는 번호와 이름이 한 줄 |
| 토큰 창(계정 관리) | 스크립트가 만드는 표라 `table.table.token-box__table`(머리 굵은 가운데 글자) → 다른 화면과 같은 `table.files` + `.table-wrap` + `watchStackTable` |
| 빈 표 | 머리만 있는 표(내 공유 링크 · 임베드 허용 목록)가 좁은 폭에서 머리 줄만 가로로 밀렸다 → `.table-wrap:has(> table.files > tbody:empty)` 숨김(DESIGN 2.4 D-6 을 CSS 가 지킴) |

**지시와 다른 점(결정 거리)** — 지시는 「창 안의 표는 `.list`」였다. 마크업을 `.list` 로 바꾸지 않고 **같은 쌓기 규칙**을 창 안 표에도 걸었다. 까닭: ① 규칙이 하나로 남는다(표 → `.list` 변환 코드가 창마다 생기지 않는다) ② 넓은 폭(1280 의 960 창)에서는 표가 더 읽기 쉽다 ③ 기존 시험이 보는 DOM(`#template-body tr`·`#trash-body tr td.actions button`)이 그대로다. 좁은 폭에서 보이는 모양은 `.list` 와 같다(구분선·여백 토큰 동일).

## 2. H2 브라우저 기본 prompt/confirm

| 자리 | 전 | 후 |
|---|---|---|
| 파일 「수정」 | `window.prompt` 세 번(설명 → 등급을 영어 값으로 직접 입력 → 팀 **번호**) | (팀 고르개는 독립 검토 M-1 로 고침 — 11장) 창 하나 `#file-edit-dialog`(`.modal--form`, `home.php`): 설명 칸 · 등급 고르개 · 팀 고르개(이름). 선택지는 **서버가 준 것만** — 등급은 `/api/me` 의 `upload_levels`(올리기·등급 바꾸기 창과 같은 목록) + 이 파일의 지금 등급, 팀은 `/api/teams` 의 이름(loadTeams 가 채움). 실행 파일 압축본은 등급 칸 대신 안내 한 줄(F-65 — 서버가 access_level 키를 400). 보내는 것은 **바뀐 키만**(전과 같음) · 거부 문구는 창 아래 줄에 서버 글자 그대로 · 저장하면 창 닫고 `.ok-note` 「…을(를) 고쳤습니다.」 · 닫기·✕·Esc·바깥은 요청 0 · 처음 초점 설명 칸 · 닫히면 초점은 「수정」 |
| 내 계정 토큰 회수 | `window.confirm` | 공용 확인 창 `openConfirm`(`#confirm-dialog` — 파일 지우기와 같은 창) · 「회수하기」(빨강)를 눌러야 DELETE · 재인증 뒤 다시 보내기는 확인 없이(`sendRevoke`) |
| 계정 관리의 토큰 창 회수 | `window.confirm` | 이미 창 안이라 확인 창을 또 띄우지 않는다(DESIGN 2.7 모달 안에 모달 금지) → **창 안 확인 줄**(`.notice` + 「그만두기」·「회수하기」, 처음 초점 「그만두기」, 닫으면 초점 「회수」) — 이력 창의 되돌리기 확인과 같은 모양 |
| 휴대폰 경보 기기 해지(`alerts-app.js`) | 확인 창(`#push-confirm`)이 이미 있었고, `<dialog>` 가 없는 브라우저의 **대체 경로**만 `window.confirm` | 대체 경로에서 해지하지 않고 알림 한 줄(「이 브라우저에서는 확인 창을 열 수 없어 해지하지 않았습니다 …」) — 확인 방식은 창 하나 |

정적: `public/assets/**/*.js` 의 `window.prompt(`·`window.confirm(` 호출(주석 줄 제외) **0**(수락 시험 U-S).

## 3. H3 손님(Guest)에게 쓰기 단추

| 무엇 | 어떻게 |
|---|---|
| `/api/me` | 새 값 `can_community_write` = `Authz::canCommunityWrite($user)` — 라우트 정책 `community.write` 가 부르는 **그 함수**를 그대로(보안 핵심 파일 무변경 · 새 판정 0). API.md 3장 |
| 서버가 그리는 단추 | `home_page.php` 새 페이지 · 템플릿에서 만들기 · 선택하기, `shell_top.php` 사이드바 「새 페이지」 — 같은 함수 결과로 처음부터 `hidden`(손님 화면에 잠깐 보였다 사라지지 않는다) |
| 페이지 화면(`page_view.php` + `editor.js`) | 창구의 페이지별 `can_*`(편집·공개 범위·속성·관리·공유·넘기기·태그·게시·게시 해제·숨기기·해결됨·댓글·이력 되돌리기) **와** `me.can_community_write` 가 둘 다 참일 때만. 값이 없으면(옛 창구) 페이지별 값만 |
| 서버 거부 | 그대로 — 손님 `POST /community/api/pages`·`/api/templates` → 403(수락 시험 U-A). 숨김은 편의 |

**발견(보고만)**: 페이지별 창구 값(`pages_get.php` 의 `can_edit`·`can_handover`·`can_set_visibility`·`can_tag` …)이 손님 **자기 공간의 페이지**(시작하기)에서 참이다 — `decidePageChange`·`decidePageManage` 가 개인 공간 주인을 역할과 상관없이 참으로 본다. 쓰는 창구는 라우트 정책 `community.write` 가 먼저 403 으로 막으므로 **권한 구멍은 아니다**(실측 403). 창구 값 자체를 맞추려면 `pages_get.php` 에서 `Authz::canCommunityWrite` 와 AND 하는 한 줄이 필요하다 — 이번 범위(화면) 밖이라 고치지 않았다.

## 4. H4 문서 안 표가 폰에서 한 글자씩 세로

`community.css` — `.doc-table:not(.atom-grid) .doc-table__cell { word-break: keep-all; overflow-wrap: normal; min-width: 6rem; }`. 원인은 문서 본문(`.doc`)의 `overflow-wrap: anywhere` 가 칸의 최소 폭을 한 글자로 계산하게 해 표가 화면 폭에 맞추려고 칸을 짓누른 것. 이제 칸은 낱말 단위 · 최소 6rem, 넓으면 **표만** 감싸개(`.doc-table-wrap`, 이미 `overflow-x: auto`) 안에서 가로로 밀린다 — 문서 가로 넘침 0(수락 시험 H4). 편집기 안 표 칸 입력(`.atom-grid`)은 제외.

## 5. H5 편집 모드 머리 동작 다섯

`page_view.php` — 상태 배지(`#page-editors` 「○○ 님도 보는 중」 · `#page-save-state` 저장 중/저장됨/저장 못 함 · 그 옆에 붙는 「임시저장 못 함」)를 머리 **동작 줄에서 빼** 제목 아래 한 줄 `#page-state`(`.page-head__sub.toolbar` — 새 클래스 0)로. 보이는 배지가 없으면 줄째 숨는다(`editor.js` `syncPageState`). 머리 동작은 사용 방법 · 이력 · 더보기 · 편집(끝) 넷 — 360·412 에서 한 줄(수락 시험 H5). 읽기 모드에서 할 일 상자를 눌렀을 때의 「저장됨」도 같은 줄이라 머리가 두 줄로 꺾이지 않는다.
도구줄(sticky)로 옮기는 안은 뺐다 — 읽기 모드의 할 일 저장 배지가 도구줄 없이도 보여야 하고, 배지가 나타났다 사라질 때 도구줄 높이가 바뀌면 본문이 밀린다(DESIGN 2.14 「툴바는 늘 같은 자리」).

## 6. 중간 · 낮음

| # | 고친 것 |
|---|---|
| M1 | `formatSize` 숫자와 단위 사이 U+00A0(보이는 모양 「68 B」 그대로) — 좁은 칸에서 「68」/「B」 두 줄 0 |
| M2 | `addWhenCell`(t-box)·`setWhenParts`(커뮤니티 휴지통·게시판·이력) — 날짜·시각 두 조각(`span.nowrap` 둘 + 빈칸). 글자(textContent)는 같다. 많아야 두 줄 |
| M4 | 720px 미만 모달 머리 — 오른쪽에 ✕ 자리 56px 을 비우고 ✕ 를 첫 줄 오른끝에 붙임(`dialog.modal > .modal__head` · 520 규칙 뒤에 둬 `.modal--stack` 의 좁은 여백보다 무겁게) · 마크 + 세로 묶음 머리는 묶음이 마크 옆 남은 폭을 씀(마크만 한 줄 차지하던 것) |
| M7 | 역할 글자 한글 — **Admin 관리자 · Manager 팀장 · Employee 직원 · Guest 손님**(title 에 원래 값). 셸 사용자 칸·앱 메뉴·「역할로 보기」 띠(PHP 파일 안 배열 — 새 PHP 함수 0, DESIGN 2.12 opcache) · 런처 · 내 계정 · 계정 표 · 조직 구성원 · 권한 매트릭스 행 머리·바꿀 내용 문장 · 구성원 후보 · 커뮤니티 공유·넘기기 후보(`window.qarediRoleLabel`) · 역할 고르개 글자 「직원 (Employee)」(값 그대로 — `relabelRoleOptions`). 값(세션·창구·요청의 role)은 그대로. 기존 시험 기대값을 한글로: `stage26.sh` 두 줄 · `community/stage5a2.sh` 한 줄 · `community/browser_5a2.py` 세 줄 |
| M11 | 트리·카드 줄의 공개 범위 배지 — **기본값(비공개)은 그리지 않음**(팀·사내만). 페이지 화면 속성 줄의 「공개 범위」 배지는 그대로 |
| M13 | 로그인 2단계 — 비밀번호 칸을 비우지 않는다 · 2단계에서 받은 401 은 「2단계 코드가 맞지 않거나 시간이 지났습니다 …」. 서버 문구를 먼저 확인: `Auth::login` 은 코드가 틀려도 1단계와 같은 `MSG_LOGIN_FAILED` 로 답하고 구분하지 않는다(보안 핵심 파일 — 고치지 않음). 2단계에 닿은 것은 서버가 이미 「비밀번호 맞음」을 알려 준 뒤(mfa_required)라 이 문장이 새로 새는 것은 없다 |
| M17 | 미리보기 정보 판 「sha256」 → 「파일 지문 (sha256)」 · 「권한 근거: 서버 Authz 가 허용한 파일입니다」 → 「볼 수 있는 까닭: 내가 볼 수 있는 등급의 파일입니다. 열 때마다 다시 확인합니다.」 · 관리자 페이지 배지 「재인증 확인됨 (15분 창)」 → 「비밀번호 확인됨 (15분 동안)」·「비밀번호 다시 확인 필요」 |
| L4 | 올려서 보는 툴팁은 `@media (hover: hover) and (pointer: fine)` 에서만 — 터치 뒤 `:hover` 가 남아 「zip 으로 받기」 툴팁이 안내 문장을 가리던 것. 키보드 초점 툴팁은 그대로 |

하지 않은 것(지시대로 다음 묶음): M3 모달 단추 위계 통일 · M6 낱말 통일 · M8 계정 관리 「관리」 하나로 · 그 밖의 낮음(줄 체크 상자 13px · 올리기 창 끌어 놓기 칸 넘침 등).

## 7. 시험

(8장에 회차별 숫자)

- 새 수락 시험 `tests/dbg_u_ui.sh` + `tests/browser/dbg_u_ui.py` — 임시 DB `tbox_d1u_<포트>_test` · 임시 인스턴스(기본 8223). U-S 정적 7 · U-A 창구 5 · U-B 화면(360 · 412 터치 · 820 · 1280 × 라이트 · 다크) · U-L 서버 로그 · 운영 대조(운영 감사 max(id)·경보 로그 줄 수 전후 · 이 시험 UA 의 운영 감사 행 0). `REGRESS=1` 이면 같은 인스턴스에서 회귀.
- 전·후 스크린샷: `~/.claude/ui-shots/dbg-u-0926/before/`(a57cf6b 인스턴스 8221 — 같은 시험 스크립트를 `before` 로) · `…/after/`(수락 시험).

## 8. 시험 결과

### 8.1 수락 `tests/dbg_u_ui.sh` 회차 (첫 회차 직전 WIP 커밋 `14664ef` = `selftest-base/dbg-U`)

| 회차 | 결과 | 까닭 · 고친 것 |
|---|---|---|
| 1 | 멈춤(25분 한도 안에 끝나지 못함) | 화면 시험 출력이 끝날 때만 보였다 → `python3 -u` + `tee` 로 줄마다(시험 틀) |
| 2 | 틀 17/2 · 화면 **377/8** | 모두 시험 쪽: 받기는 `<a aria-label="다운로드">`(button 선택자 밖) · 미리보기 이름 「미리보기 — 갈래」 · 첫 조합이 시험 토큰을 실제로 회수한 뒤 다음 조합에서 단추 없음(클릭 30초 대기) · 로그인 2단계의 **설계된** 401(mfa_required · 틀린 코드)이 콘솔 「리소스 401」로 셈 → 시험 고침 `29f9cb4`(코드 변경 0) |
| 3 | 틀 17/2 · 화면 350/9 | 토큰 발급 400 한 번(통일 문구) → 다음 코드 창에서 한 번 더(시험) |
| 4 | 로그인 실패로 멈춤 | **다른 세션(d1sw)이 13:47~13:52 `/etc/tbox-v2/qa-totp.txt` 를 `sudo install` 로 세 번 바꿈** → claude_admin01·claude_employee01 의 비밀이 운영 DB 와 어긋남(파일·DB 비밀 md5 앞 8자 비교 — 읽기만. manager01·employee02·guest01 은 같음). 운영 DB 사본을 쓰는 모든 시험의 로그인이 막힌다 → 시험 틀에 `DBGU_SYNC_TOTP=1`(임시 DB 의 세 계정 비밀만 파일 값에 맞춤 — 운영 DB·파일 무접촉 · 값은 표준입력 SQL) `16d0cf3` |
| **5** | **틀 19/0 · 화면 392/0**(8 조합 · 브라우저 기본 창 0 · 콘솔 0) | 운영 감사 max(id) 23920 → 23922 · 경보 로그 1035 → 1037 — 늘어난 것은 전부 cron `alerts.php` 의 `alert.raised`(AL-7 「감사 30분 0건」·AL-9 — AL-9 표본은 다른 에이전트의 `sudo psql -d tbox_d1s_8212_test`), 이 시험 UA 의 운영 감사 행 **0** |

고치기 전 판(a57cf6b 인스턴스 8221)에 **같은 화면 시험**(`before`)을 돌린 결과 **164 ✔ / 170 ✖** · 브라우저 기본 창 5건(prompt 「설명 (500자 이내)」·confirm 토큰 회수) — 시험이 고친 결함을 실제로 잡는다(되돌리면 실패한다). 항목별: H2 창·확인 16 · H3 손님 16 · H5 배지 8 · M1 8 · M7 24 · M11 8 · H1(360·412) 30여 · H4 4 · M13 4 · M17 6 …

### 8.2 정적

| 무엇 | 결과 |
|---|---|
| 보안 린트(`scripts/security_lint.sh`) | 0건(첫 판에서 커뮤니티 `RegExp.exec` 1건 → `String#match` 로 — S-09 이름 규칙) |
| `php -l`(바뀐 PHP 7) · `bash -n` · `py_compile` | 0 실패 |
| 커뮤니티 부품 import 버전(`--check`) | 0 — editor.js·templates.js·database.js 다시 찍음(부품 버전 e6afcdc98665) |
| 기본 창 호출(`window.prompt(`·`window.confirm(` — 주석 제외) | 기준 판 6(app.js prompt 3 · confirm 2 · alerts-app.js confirm 1) → **0** |
| 라우트·판정 파일(`public/index.php`·`src/Authz.php`·`src/community/CommunityAuthz.php`·`src/community/routes.php`) diff | 0 줄 |
| 더한 줄의 인라인 style·script·on* | 0 |
| 점검 봇 정적 규칙 21(`tests/static/r*.py`, r6 는 지시서 인자가 필요해 뺌) | 기준 판 · 이 판 발견 수 **같음**(r11 21=21 · r15 4=4 · r19 4=4 · r1 1=1 · r27 1=1 · r2 11=11 · r3 9=9 · r5 1=1 · r9 3=3 · 나머지 0=0) — 새 발견 0 |

### 8.3 회귀

커밋 판을 0700 임시 폴더로 `git clone`(복제본 무접촉 — checkout 은 그 임시 clone 안에서만) → 포트(8221~8229)와 임시 DB 이름(`tbox_d1u_*`)만 바꾼 사본으로 돌렸다.
검토 계정 비밀 파일이 운영 DB 와 어긋나 있어(8.1 4회차) 사본마다 「pg_dump 확인」 줄 뒤에 **임시 DB 만** 맞추는 한 줄을 끼웠다. 운영 포트·운영 DB 0.

| 시험 | 결과 | 비고 |
|---|---|---|
| `stage26.sh` | **113/0** | 틀의 REGRESS(8223) — 역할 칩 「관리자」·띠 「claude_admin01(관리자)」 기대값을 한글로 고친 판 |
| `help_button.sh` | **16/0** | 〃 |
| `q_launcher.sh` | 86/4/1 | **기준 판 a57cf6b 에 같은 틀로 돌려도 같은 넷**(생성기 CSP · 옛 `/assets/app.css` 308·Location — nginx 몫 · 새 주소 공유 파일 받기 404 — 임시 저장소에 그 파일 바이트가 없음) — 이 묶음과 무관 |
| `f66.sh` | **103/0/5** | 〃 |
| `f68.sh` | **109/0/2** | 첫 돌림은 17/0/19 — 틀의 로그인과 같은 30초 창에서 같은 계정으로 로그인해 한 번만 쓰는 코드(R-45)에 걸림 → 틀이 스크립트마다 다음 창을 기다리게(`c24be57`) 다시 |
| `qf3b_tbox.sh` | **50/0** | 첫 돌림 47/1 — `git archive` 사본엔 기준 판 기록이 없어서(시험 전제) → 임시 clone 으로 다시 |
| `qf3c_zip.sh` | **117/0** | 〃 |
| `community/stage3.sh`(browser_smoke) | **89/0** | f68 과 같은 까닭으로 첫 돌림 17/20/5 → 다시 |
| `community/stage4c.sh` | **107/0** | 〃(첫 돌림 로그인 실패 4) |
| `community/stage5a2.sh`(browser_5a2 — 공유·넘기기 후보 「· 직원」) | **139/0** | 포트 8221 · DB `tbox_d1u_5a2_test` |
| `community/stage7b.sh`(browser_7b — 템플릿 창) | **141/0** | 포트 8224 · DB `tbox_d1u_7b_test` |


## 9. 커밋 · 태그 · 산출물

| 커밋 | 내용 |
|---|---|
| `14664ef` = `selftest-base/dbg-U` | 코드 전부 + 수락 시험 첫 판(첫 수락 시험 직전 WIP) |
| `29f9cb4` · `16d0cf3` · `c24be57` | 시험·틀만(코드 변경 0) — 8.1·8.3 |
| 문서 커밋 | DESIGN(2.4 좁은 폭 · 2.7 기본 창 금지 · 2.8 · 2.9 · 2.14 · 4장 터치 대상 · 5장 · 6장 셋 · 8장 이력) · `docs/design-guide.html`(360 틀 안의 좁은 폭 표 · 창 안 확인 줄 · 체크 셋) · API.md 3장 · STAGES 디자인 절 한 줄 · SECURITY 7장 한 행 · 이 기록 |
| 반영 스크립트 커밋 | `review-scripts/apply-dbg-u-repo-2026-09-26.sh`(고정 값 블록 = `pins-dbg-u-repo.sh` 가 찍음) · `review-scripts/pins-dbg-u-repo.sh` — 연습 결과는 10장 |
| `review-base/dbg-U` | 독립 검토 판(옮기지 않는다). 번들 `~/.local/share/qaredi-dev/review-base-dbg-U.bundle`(600) · 패치 `dbg-U-repo.patch` = `git diff a57cf6b review-base/dbg-U -- . ':!review-scripts'` · sha256 목록 `dbg-U-SHA256SUMS` |

## 10. 반영 스크립트 연습

`review-scripts/apply-dbg-u-repo-2026-09-26.sh` — 본보기 `scripts/apply-qf3c-repo-2026-09-26.sh` 그대로(PATCH_WANT 고정 · 0700 사본 · git 이 읽은 경로·요약 · rename/copy/지우기/링크 거부 · 빈·모르는 인자 거부 · 되돌림 함수 + ERR·INT·TERM·HUP 덫(set -E 없이) · pipefail 아래 `|grep -q`·`|head`·`|awk exit` 0 · 확인 요청은 화면 302·로그인 200·런처 200·자산 200·`php -r` 만 — **미로그인 /api 0**). 이번 묶음에 맞춘 다른 점: 새 라우트 0(라우트 줄 수 그대로) · 라우트·판정 파일(`public/index.php`·`Authz`·`CommunityAuthz`·커뮤니티 routes)을 건드리는 패치는 글자로도 거부 · 화면 JS 의 기본 창 호출 0 · `/api/me` 새 값 한 줄이 판정 함수 그대로(`php -r` 로 파일만 읽음) · 커뮤니티 부품 버전 --check(이번에 다시 찍음). 고정 값은 `pins-dbg-u-repo.sh`(본보기 pins-qf3c 그대로 + 라우트·판정 파일 diff 가 0 이 아니면 찍지 않음).

연습(경로만 바꾼 사본 — `APP` = a57cf6b 로 둔 임시 git clone · `L8082` = 임시 인스턴스 8222 · `DB` = 임시 DB `tbox_d1u_8221_test` · 운영 트리·운영 포트·운영 DB 0 · 운영 감사 max(id) 연습 전후 23928 그대로):

| 경우 | 결과 |
|---|---|
| ① 빈 인자 · ② 인자 모자람 · ③ 모르는 인자(`--dryrun`) | 사용법 · 종료 1 · 트리 그대로 |
| ④ 틀린 sha | 「패치 sha256 이 알려 준 값과 다르다」 · 1 · 그대로 |
| ⑤ `--dry-run` | 사전 확인까지(고칠 파일 22 = 기준 판 · 라우트 89줄 · apply --check · 사람 감사 0) · 0 · 그대로 |
| ⑥ 정상 | 확인 16 ✔(바뀐·새 파일 25 = 검토 판 · php -l 7 · bash -n 3 · py_compile 2 · 부품 버전 · 라우트 89 그대로 · 토큰 창구 · 커뮤니티 라우트 · 기본 창 호출 0 · `php -r` · HTTP 여섯) · 0 · 25개 바뀜 |
| ⑦ 이어서 다시 | 「커밋 안 된 변경이 있다」 · 2(커밋한 뒤라면 「기준 판이 아니다」 · 3) |
| ⑧ 확인 실패(HTTP 를 없는 포트로) | HTTP 여섯 ✖ → 되돌림 → 고친 파일 = 기준 판 · 새 파일 3 없음 · 라우트 그대로 · 4 |
| ⑨ 패치 직후 TERM | 신호 덫 → 되돌림 → 기준 판 · 4 |
| ⑩ curl 이 실패하는 PATH | `code()` 가 `|| true` 라 ERR 가 아니라 확인 실패로 잡힘 → 되돌림 · 4 |

이 기록을 넣으면 패치 sha 가 바뀌므로, 마지막 판에서 고정 값을 다시 찍고 ④·⑥·⑧ 을 한 번 더 돌렸다(결과 같음 — 보고서의 sha 가 최종).

## 11. 독립 검토 반영 (「반영 가능」 — 반영 전 넷, 새 커밋 `7ee5fc5` · 태그 `review-fix/dbg-U`)

| # | 지적 | 고친 것 | 시험 |
|---|---|---|---|
| M-1 | 파일 수정 창 팀 고르개 — 파일의 팀이 선택지에 없거나(쓰지 않는 팀) 목록이 늦게 와 「내 팀」이 골라지면 이전 값이 남아 **설명만 고쳐도 team_id 가 실림**(관리자는 파일이 다른 팀으로 조용히 옮겨지고 팀장·직원은 403 — `files_update.php` 「설명만 고치기」가 깨짐) | 창을 열 때 고르개를 파일의 팀으로 맞추고 값을 기억(`editTeamInitial`) · team_id 는 **등급을 team 으로 바꿀 때 · 사용자가 팀을 바꿨을 때만** · 목록에 없는 팀은 「(팀 이름 —) 지금 팀(쓰지 않는 팀)」 자리 · `loadTeams` 는 이 고르개에 「내 팀」을 미리 고르지 않고, 같은 값의 자리가 있으면 진짜 선택지로 바꿈 | 관리자 문맥: ⓐ `/api/teams` 를 붙잡은 채 창을 열고 설명만 고친 뒤 목록을 풀고 저장 → 고르개 = 파일의 팀 그대로 · 본문 `{description}` 하나 ⓑ 목록에서 파일의 팀을 뺌 → 「지금 팀(쓰지 않는 팀)」 자리 선택 · 본문 `{description}` 하나 ⓒ 등급을 team 으로 → `access_level`·`team_id` 실림 |
| L-3 | 툴팁 조건이 `(hover: hover) and (pointer: fine)` 뿐 — 폴드 삼성 인터넷처럼 hover 를 알리는 터치 기기 | `@media (hover: none), (any-pointer: coarse) { .btn--icon[aria-label]:hover:not(:focus-visible)::after { display: none; } }`(트리 규칙과 같은 조건) | CDP 로 hover·fine·any-pointer coarse 를 흉내 내 올려도 안 뜸 · 마우스만은 그대로 뜸 |
| L-1 | 트리 「하위 추가」가 손님에게도 만들어짐 | `meCanWrite` 가 거짓이면 만들지 않음 · 커뮤니티 부품 import 버전 다시 찍음 | 손님 트리 0개 · 직원 트리 >0 |
| L-4 | 2단계에서 비밀번호 칸을 비우지 않는데 문장은 코드만 가리킴 | 「비밀번호나 인증 코드가 맞지 않습니다. 비밀번호를 확인하고, 인증 앱에 지금 보이는 6자리 코드를 넣으세요.」 — 계정 있음은 말하지 않음 | 문장에 「비밀번호」·「인증 코드」 · 「아이디 또는 비밀번호」 없음 |

하지 않은 것(검토 지시대로 다음 묶음): `pages_get.php` 의 can_* 에 canCommunityWrite 조건 · database.js L-2. 반영 스크립트 40행 주석(고정 값의 커밋 이름)은 핀을 다시 찍으며 태그와 같은 내용이라고 적게 고침.
검토 계정 비밀 파일은 16:57 에 운영 DB 와 다시 같아졌다(지문 대조) — 이번 시험은 `DBGU_SYNC_TOTP` 없이 돌았다.


## 12. 운영 HEAD e88f690 위로 옮김 (태그 `review-rebase/dbg-U`)

운영에 디버깅 묶음 S(`f333fc9` — src·scripts·tests·문서 30파일) · `01ab30d`(SECURITY 한 줄) · 묶음 T(`e88f690` — tests·catalog·run.sh·CLAUDE.md·STAGES 한 줄)가 들어가 운영 HEAD 가 `e88f690` 이 됐다.
운영 저장소에서 복제본으로 **fetch 만**(운영 쪽 읽기만) 하고 `review-fix/dbg-U` 에 합쳤다(`b1d359c` — 보안 넷 때와 같은 방식). 겹친 파일 여섯 중 충돌 둘:

| 파일 | 풀이 |
|---|---|
| `public/assets/app.js` 파일 「수정」 보내기 | S-8(관리자 등급 내리기는 15분 재인증 창 안에서만 — `403 next:"reauth"` 면 재인증 창 뒤 **같은 값**을 다시 보냄)과 U 의 창 흐름을 합침: `sendEdit()` 안에서 `handleReauth(…, sendEdit)` · 성공이면 창을 닫고 결과 한 줄 · 거부 문구는 창 아래 줄 · 재인증을 그만두면 저장 단추가 다시 살아남 |
| `SECURITY.md` 7장 끝 | 두 쪽 행 모두 — 운영 행(S 구현 · d66 · d67 · S 검토 반영 · S 운영 반영) 다음에 U 행 |

자동 합침: `API.md` · `STAGES.md` · `src/handlers/settings_page.php`(U 역할 한글 줄만) · `tests/stage26.sh`(T 의 prod_guard + U 의 역할 기대값 둘 다 살림).
더한 것: T 의 시험 카탈로그 `tests/catalog.json` 에 `tbox.dbg_u_ui`(temp-instance · required · writes_prod false) · `tbox.browser_dbg_u_ui`(helper) · 수락 시험의 정적 대조 기준 판을 `e88f690` 으로 · 화면 시험에 S-8 합친 길 한 줄(첫 PATCH 를 `403 next:reauth` 로 흉내 → 재인증 창 → 같은 본문으로 다시 → 창 닫힘 — 이 절이 일부러 낸 403 콘솔 줄은 세지 않음).

옮긴 판 시험(고친 곳만 — 운영 쓰기 시험 0 · 포트 8223·8225 · 임시 DB `tbox_d1u_*`):

| 시험 | 결과 |
|---|---|
| `tests/dbg_u_ui.sh` 화면 대표 세 조합(360 라이트 · 820 다크 · 1280 라이트) | 첫 돌림 틀 18/3(시험 쪽 둘 — 정적 대조 기준이 아직 a57cf6b · 일부러 낸 403 을 콘솔 오류로 셈) → `2074411` 뒤 **틀 19/0 · 화면 174/0**(M-1ⓐⓑⓒ · S-8 합침 둘 포함) |
| `stage26.sh`(겹친 시험 — T prod_guard + U 기대값) | **113/0**(같은 임시 인스턴스 REGRESS — 운영 파일 쓰기 갈래는 prod_guard 가 막음) |
| `help_button.sh` | **16/0** |
| S 수락 `tests/dbg_s_security.sh`(DBGS_BROWSER=1 — 겹친 app.js·settings_page 화면 포함) | **156/0 · 화면 0 실패** — 포트 범위(8211~8219 → 8225)·임시 DB 접두(`tbox_d1s_` → `tbox_d1u_s`)만 바꾼 사본 |
| 보안 린트 · 부품 버전 --check · php -l · JS 문법 | 0 · 0 · 0 · 0 |

반영 스크립트 고정 값은 `e88f690` 파일로 다시 찍었고(패치 = `git diff e88f690 review-rebase/dbg-U -- . ':!review-scripts'`), 연습은 가짜 운영 트리를 `e88f690` 으로 두고 틀린 sha · 정상 · 확인 실패 되돌림을 다시 했다.
