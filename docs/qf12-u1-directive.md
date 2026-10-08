# 지시서 U1 — 사용감 라운드: 담당 후보 열자마자 · 누르면 편집 · 새 프로젝트 기본 공간 (2026-10-08)

사용자 요청(2026-10-07 사용 소감 → 2026-10-08 「담당자 선택 빈 목록이랑 누르면 편집 진행해」) · 결정 WK-17 A(팀 공간 먼저) · WK-18 A(하위 작업 제목 그 자리 고치기).
기준 문서: 기획 `../../community/work-ux-plan.md` **13장(정답지)** · `../../community/ARCHITECTURE.md` 23.3 「창구」 빈 `q` 줄 · `DESIGN.md` 2.21 「담당 고르기」·「누르면 편집」 줄 · 2.18 「새 프로젝트 창」 문장 · `ARCHITECTURE.md` 13장 · `../../CLAUDE.md` 2·3·4장.
단위: `../../CLAUDE.md` 3.1 「따로 쓰이는 것」 — 사용감 라운드 한 묶음. 조각 하나(U1-a~d 한 지시서).

## 공통

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf12u1-20261008`**(브랜치 `feat/qf12-u1` — 메인 세션이 만들었다). 결과 폴더 **`/home/changkwon/qaredi-qf12u1-results-20261008/U1/`**. 운영 `/var/www/qaredi/tbox-v2` · 운영 DB · 다른 worktree 는 **읽기도 쓰기도 하지 않는다**(코드 비교는 이 worktree 의 git 으로) |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <결과 폴더>` — 합성 DB · 로컬 PHP(포트 8768 고정 — 한 번에 하나만) |
| 브라우저 | `. /home/changkwon/qaredi-qf12u1-results-20261008/env.sh` 로 `CHROMIUM_EXECUTABLE` · `PYTHONPATH` · `PATH` 만 받는다. 다른 결과 폴더의 env.sh 금지 · `rm -rf` 는 자기 결과 폴더 안 절대 경로만 |
| 교차 회귀 | `python3 tests/stage89_cross_regression/run.py --output <결과 폴더>/stage89` — 끝에 한 번 |
| 계정 | 격리 틀의 fixture 계정만. 운영을 치는 시험 없음. 시드 계정 금지 |
| 커밋 | worktree 브랜치에 파일을 지정해 커밋(`git add -A` 금지 · 한글 · 끝에 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`). push · 발행 · main 합치기는 하지 않는다(메인 몫) |

```
목표        : 담당 고르기를 열자마자 후보가 보이고, 설명과 하위 작업 제목을 누르면 그 자리에서 고치며, 새 프로젝트는 팀 공간이 기본이 되게 한다.
요구사항    : 기획 ../../community/work-ux-plan.md 13장 U1-a~d(13.3 표 그대로 · 13.4 보안 영향) · ../../community/ARCHITECTURE.md 23.3 「창구」 빈 q 줄 ·
              DESIGN.md 2.21 「담당 고르기」·「누르면 편집」 · 2.18 「새 프로젝트 창」 + 6장 체크리스트(새 색값 0 · 인라인 style 0 ·
              새 클래스는 DESIGN 에 적힌 `.task-pane__doc--editable` 하나만 — 하위 작업 제목 단추·입력은 지금 클래스(.task-row__title · .task-add__input · .btn--icon …) 재사용) — 해당 절을 읽고 시작할 것.
수정 파일   : src/community/handlers/assignee_candidates.php(빈 q 갈래만) ·
              public/assets/community/work.js(assignPicker · draw 의 설명·하위 작업 · openNewProject 만) ·
              public/assets/app.css(`.task-pane__doc--editable` 과 하위 작업 제목 단추·입력 자리 — 작업 상세 칸 블록 안) ·
              커뮤니티 JS 의 import 버전 표시 줄(`php scripts/community_asset_version.php --write` 가 바꾸는 줄만 — 손으로 고치지 않는다) ·
              tests/community/qf12_floor_http.py(Q:T-25 빈 q 단언 고침 + 새 단언) · tests/community/qf12_work_browser.py(U1 단언 더하기) · tests/catalog.json(covers·notes 갱신이 필요할 때만)
              여기 없는 파일 금지. ★ 보안 핵심 파일(CommunityAuthz.php · routes.php 등)은 고치지 않는다 — 필요해 보이면 멈추고 보고.
              editor.js 의 칸 안 편집기(mountPaneEditor)는 고치지 않고 지금 「본문 고치기」와 같은 방식으로 부른다 — 부족하면 멈추고 보고.
금지 패턴   : SQL 문자열 결합 · innerHTML(insertAdjacentHTML · outerHTML 포함) · 사용자 입력이 든 파일 경로 · 외부 요청 코드 ·
              역할 판단을 CommunityAuthz 밖에서 · 요청 파라미터의 role 값 · 브라우저 HTML 저장 ·
              후보 규칙을 SQL 로 한 벌 더 적기(빈 q 도 한 명씩 decideAssignTarget — 지금 틀 그대로) · 화면이 후보를 거르거나 더하기 ·
              고치기 단추를 can_edit 밖의 조건(역할 글자 등)으로 숨기기 · 새 창구 · 응답 키 추가.
세부(13.3 그대로, 정해 둔 값):
  U1-a 서버: q 가 비면 — 활성 사용자를 「부르는 사람 먼저, 그다음 아이디 순」으로 LIMIT 50 읽어 지금과 같은 반복(대상 기준 행 → decideAssignTarget)으로 통과 10명.
       q 가 있으면 지금 그대로(20 · 10). 응답 {candidates:[{username,display_name}]} 그대로. 호출자 관문(history gate · decidePageAssign) 그대로.
       화면: 메뉴가 열리면(toggle open) 곧바로 빈 검색어로 부른다 · 입력 200ms 뒤 좁힘(지금 그대로) · 늦게 온 답은 버린다(지금 seq 그대로) ·
       빈 검색어 답에서 후보가 0명이거나 부르는 사람 한 명뿐이면 후보 아래 .help 「이 작업을 고칠 수 있는 사람이 나뿐이에요. 「공유」에서 편집 권한을 주거나 팀 공간 프로젝트에 두면 맡길 수 있어요.」
       (지금의 「이 작업을 고칠 수 있는 사람만 나와요.」 안내는 그대로 둔다). 부르는 사람이 누구인지는 화면이 이미 가진 /api/me 값으로만 비교한다.
  U1-b 설명: can_edit 일 때만 docBox 에 .task-pane__doc--editable · tabindex=0 · aria-label 「설명 — Enter 로 고치기」. 누름(click) — 대상이 a · button · input · textarea ·
       select · iframe · label · [contenteditable] 안이면 무시, 그 밖이면 「본문 고치기」와 같은 함수 호출(한 함수로 묶어 두 길이 같은 일을 하게). Enter(초점이 docBox 자신일 때) 도 같다.
       편집 중에는 editable 클래스·tabindex 를 떼고, 「편집 마침」 뒤 다시 단다. 빈 설명 문구: 고칠 수 있으면 「누르면 설명을 적을 수 있어요.」, 아니면 지금 그대로.
       선택(드래그로 글자 고르기)한 뒤의 click 은 편집으로 들어가지 않는다(window.getSelection 이 비어 있지 않으면 무시).
  U1-c 하위 작업(상세 칸 kind 'pane' 줄만 — 프로젝트 목록 줄은 바꾸지 않는다): 고칠 수 있음 = 부모 can_edit && 트리(byId)에 그 하위 작업이 있음 → 제목이 button(type=button, 글자 모양).
       누름·Enter → 같은 자리 input.task-add__input(maxLength 는 페이지 제목 상한과 같게 — 서버 상수 확인) 초점 · Enter 저장 · Esc 되돌림 · blur 저장 · 빈 값·같은 값은 저장 없이 되돌림.
       저장 = 지금 페이지 제목 고치는 창구(상세 칸 큰 제목이 쓰는 것과 같은 요청). 실패면 상세 칸 msg 에 failMessage 문구 + 옛 제목으로 되돌림. 성공이면 changed(부모) 로 목록 다시 그림.
       고칠 수 없으면 제목은 span. 줄 끝 › = a.btn--icon(aria-label 「하위 작업 열기」, href 는 지금 상세 칸 주소 규칙 ?task=) — 누르면 지금 onOpen 과 같이 그 하위 작업을 칸에 연다(주소 push).
       목록 끝(고칠 수 있을 때) button.btn--quiet.btn--small 「＋ 하위 작업 추가」 → 지금 addSub 와 같은 그 자리 입력 줄. 머리의 ＋ 아이콘·더보기 「하위 작업 더하기」는 그대로 둔다.
  U1-d 새 프로젝트 창: 공간 고르개 순서 = can_write 인 팀 공간(이름 가나다 순) → 내 공간. 첫 값 = 첫 팀 공간(없으면 내 공간). 안내 .help 첫 문장 「팀 공간에 만들면 팀원이 보고 맡을 수 있어요. 내 공간에 만들면 나만 보고 나만 맡을 수 있어요.」
       뒤에 지금 섹션·보기 안내 문장(「개인 공간에 만들면 처음에는 나만 봐요.」는 뺀다 — 앞 문장이 대신한다).
완료 기준   : ① qf12_floor_http — Q:T-25 고침: 빈 q 단언을 「P1(비공개, 소유자 A) → [A] · T1 → {A, B} · P2 에 B 없음 · A 가 부르면 A 가 첫 줄 · 응답 키 둘 · 부를 수 없는 사람 403」으로,
              + 판정 상한: 활성 합성 사용자를 60명 넘게 만든 상태에서 빈 q 로 불렀을 때 판정이 50명에서 멈춤을 보일 방법이 있으면 단언(없으면 「미검증」으로 보고) — 그 밖 단언 전부 통과.
              ② qf12_work_browser — 지금 단언 전부 + U1 단언: 「바꾸기」를 열자마자(입력 없이) 후보가 그려지고 화면 후보 = 창구 빈 q 답 · 비공개 작업(소유자만)에서 안내 문구 ·
              설명 누름 → 편집기(편집기 요소가 칸 안에 생김) · 설명 안 링크 누름 → 편집기 안 염 · 키보드 Tab 으로 설명 도착 → Enter → 편집기 · 「편집 마침」 뒤 다시 누름 자리 ·
              하위 작업 제목 누름 → 입력 → 새 제목 Enter → 서버 값 바뀜(창구로 확인) · Esc → 옛 제목 · › → 그 하위 작업이 칸에 열리고 주소 ?task= · 「＋ 하위 작업 추가」 → 입력 → Enter → 하위 작업 하나 늘어남 ·
              보기만 공유받은 사람 · 손님: 설명 editable 0 · 제목 button 0 · 「＋ 하위 작업 추가」 0 · 새 프로젝트 창 첫 값 = 팀 공간(팀이 있는 계정) · 팀 공간이 없는 계정은 내 공간 하나 ·
              폭 여섯(360 · 412 · 820 · 1199 · 1280 · 1440) × 밝게 · 어둡게 · 고대비: 가로 넘침 0 · 콘솔 0 · 누름 자리 < 44 0(폰·터치) · 인라인 style 0.
              ③ qf12_server_http · qf12_shell_browser 지금 수 그대로 통과 ④ stage89 교차 회귀 ⑤ bash scripts/security_lint.sh 0 · php -l · node --check work.js ·
              php scripts/community_asset_version.php --check 0 · git diff --check.
              스크린샷(설명 편집 전·후 · 하위 작업 제목 입력 중 · 담당 후보 열림 · 새 프로젝트 창 — 1440 · 412)을 결과 폴더에 남기고 **직접 열어 본 뒤** 통과라고 쓴다.
보고 형식   : 「공통」 — 변경 파일 목록 / 실행한 명령과 출력 원문(결과 폴더 경로) / 못 한 것과 이유 / 확정한 값(빈 q 에서 실제로 판정한 사람 수의 상한 · 하위 작업 제목 최대 길이 값과 그 출처 줄 · 커밋 해시).
```
