# 지시서 W1 — 달력의 작업 기간: 시작 예정 · 실제 시작 (2026-10-14)

사용자(스크린샷 — 내 작업 달력): 「디버깅 마감일을 16일로 해 놨는데, 언제부터 진행한 건인지 캘린더를 통해 알기가 어려움 — 시각적으로 표현됐으면 좋겠음」 · 결정 WK-29 「시작일 설정은 예정일이고, 진행 중으로 변경되어야 실제 시작한 날짜로 보자」.
기준 문서(**정답지**): 기획 `/var/www/qaredi/community/work-ux-plan.md` **19장** · `DESIGN.md` **2.34**(+ 2.21 작업 칸 · 2.20 · 6장) · `ARCHITECTURE.md` 13장 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 수락 시험만).

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-w1-20261014`**(브랜치 `feat/qf12-w1` — 메인이 만든다). 결과 **`/home/changkwon/qaredi-w1-results-20261014/`**. 운영 · 운영 DB · 다른 worktree 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <폴더>` — 포트 8768 **한 번에 하나 · 다른 에이전트(H2 · 다른 worktree)가 같은 틀을 쓴다**: 시작 전 `ss -ltn \| grep -E ':876[89]'` 가 비어 있을 때만 · 차 있으면 60초마다 다시 확인하며 기다린다(남의 프로세스를 끄지 않는다) · 새 마이그레이션은 `migrations` 목록 끝에 · 끊으면 내 합성 DB · 임시 폴더 · PHP 서버만 치움 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `Qaredi Q:F-12 W1: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : 작업에 「시작 예정」을 더하고, 상태를 처음 「진행 중」으로 바꾼 날을 실제 시작으로 계산해, 달력이 예정 · 실제 · 기한 넘김을 막대로 보여 주게 한다.
요구사항    : 기획 19.2 · DESIGN 2.34 — 읽고 시작할 것.
수정 파일   : DB — db/migrations/2026-10-14_task_start_date.sql(새 — `community.pages.start_date DATE` · 속성 사건 prop CHECK 에 'start'(지금 정의를 읽어 다시 만드는 누적 방식) · 두 번 같음 · 되돌리기 주석) · db/community/schema.sql(정본) ·
              서버 — src/community/handlers/pages_properties.php(`start` 키 — 날짜 · null · start ≤ due 검사 · 사건 'start' · 판정은 지금 due 와 같은 갈래) · src/community/handlers/pages_get.php(작업 응답에 start · started · finished) ·
                 src/community/routes.php(작업 목록 helper — my-tasks · 프로젝트 행 · 업무함에 같은 셋을 **한 질의**로 붙임 · page_row SELECT 에 start_date 읽기 열) ·
              화면 — public/assets/community/work.js(작업 칸 「시작 예정」 칸 · 실제 시작 · 끝난 날 줄 · 활동 문장 'start' · 내 작업 · 프로젝트 달력에 막대 자료) · public/assets/community/calendar.js(막대 · 층 · 주 줄 넘김 · 범례 · 깃발 — 지금 달력 보기와 한 함수) ·
                 public/assets/community/database.js(데이터베이스 달력 보기가 기한을 date_prop 으로 쓸 때 같은 막대 — 필요한 최소) · public/assets/community/community.css · app.css(2.34 클래스) · 커뮤니티 JS import 버전 줄 ·
              시험 — tests/community/qf12_w1_http.py · tests/community/qf12_w1_browser.py(새 수락) · memories_harness.py(두 자리) · tests/catalog.json · 달력 · 작업 칸 속성 줄을 재던 옛 단언이 깨지면 그 단언만(목록을 보고서에) ·
              문서 — wiki 업무 안내(guide-work.json — 달력 · 시작 예정 한 단락) · docs/qf12-w1-report.md(새)
              그 밖 0. 보안 핵심 파일은 db/**(둘)만. CommunityAuthz.php · Http.php · Doc.php 0.
금지 패턴   : SQL 문자열 결합 · innerHTML · 인라인 style(막대 위치 · 폭도 클래스 — 칸 격자 안 grid-column 칸 수는 클래스 `cal-span-1 ~ 7`) · 새 색값 · 판정 두 벌 · N+1(목록마다 사건 표 질의) · 달력 그리기 두 벌 · git add -A.
완료 기준   : HTTP(격리): start 저장 200 · 날짜 아님 400 · start > due 400(문구) · null 로 지움 · 사건 'start' 1행 · 못 고치는 사람 403(지금 due 와 같음) · 마이그레이션 두 번 ·
                 상태 todo → doing → review → doing → done: started = 첫 doing 날 · finished = 마지막 done 날 · done → doing 되돌리면 finished null · doing 없이 바로 done 이면 started null ·
                 my-tasks · 프로젝트 행 · pages_get 의 세 값이 같음 · 목록 질의 수 작업 3 · 30 같음 · 그 작업을 못 보는 사람의 목록에 0(지금 판정)
              브라우저(1440 · 412 × 밝게 · 어둡게 · 고대비): 작업 칸 「시작 예정」 칸 → 저장 → 다시 열어도 · 실제 시작 줄 문구 · 끝난 날 줄 ·
                 내 작업 달력 · 프로젝트 달력: 예정 막대(점선 · 시작 예정 ~ 기한) · 실제 막대(첫 doing ~ 오늘 또는 끝난 날) · 기한 넘긴 조각 경고 · 기한 깃발 · 주 줄 넘김 이어 그림 · 층 · +N · 범례 셋 ·
                 막대 누름 → 오른쪽 칸 · aria-label 문장 · 시작 예정 · 실제 없는 작업은 기한 하루(지금과 같음) · 412 막대 글자 0 · 넘침 0 · 대비 4.5 · 콘솔 0 · 스크린샷(달력 1440 밝게 · 어둡게 · 412)
              정적: security_lint 0 · php -l · node --check · asset_version --check · wiki_check · git diff --check
보고 형식   : docs/qf12-w1-report.md — 변경 파일 / 수락 시험과 결과 / 고친 옛 단언 / 못 한 것 / 기획 · DESIGN 과 다르게 한 것. 짧게.
```

## W1b — 작업 칸 「더보기」 메뉴가 창 밖으로 잘림 (사용자 지적 2026-10-14 · W1 뒤 · 같은 worktree · 같은 반영)

사용자(스크린샷 — 작업 칸 오른쪽 위 ≡ 메뉴가 「제목 고치기 · 본문 고치기 · 하위 작업 · 지우기」를 창 오른쪽 밖으로 펼치고 「더보기」 풍선이 겹침): 「우측 상단에 글씨가 짤려서 나가짐」.

```
목표        : 모든 `.menu`(2.15)가 어디서 열려도 창 안에 다 보이고, 열린 동안 그 단추의 풍선이 뜨지 않게 한다.
요구사항    : DESIGN 2.15 「창 안에 머묾」 줄(main 의 DESIGN.md — 2026-10-14).
수정 파일   : public/assets/app.css(.menu__list 자리 — 작업 칸 · 넓게 보기 안에서 앵커 · 고정 위치가 칸에 갇히지 않게(칸의 transform · contain · overflow 확인) · 폴백 · `.menu[open] > summary[data-tip]::after` 0) ·
              public/assets/community/work.js(칸 머리 메뉴 마크업이 2.15 와 다르면 그 부분만) · 시험 — tests/community/qf12_w1_browser.py 에 W1b 단언 · docs/qf12-w1-report.md(W1b 절)
금지 패턴   : JS 로 좌표 쓰기 · 인라인 style · 메뉴 부품 두 벌 · git add -A.
완료 기준   : 1440 · 1280 · 960 · 412 × 칸 · 넓게 보기 · 전체 화면 작업 · 프로젝트 머리 · 화면 머리의 「더보기」를 열었을 때 목록의 getBoundingClientRect 가 창 안(왼 ≥ 8 · 오른 ≤ innerWidth-8 · 아래 ≤ innerHeight-8 또는 위로 펼침) ·
              항목 글자 잘림 0 · 열린 동안 풍선 보임 0 · Esc · 밖 누름 닫힘 그대로 · 스크린샷(칸 메뉴 1440)
```

## W1c — 글쓰기 한 기준: 작업 전체 화면 모양 (사용자 지적 2026-10-14 · W1b 뒤 · 같은 worktree · 같은 반영)

사용자(스크린샷 셋): 「우리 공간에서의 글쓰기는 중간의 칸이 이상하게 생김 — 지 혼자 튐 · 업무의 전체 보기 화면처럼 글쓰기 칸을 개선해 · 업무에서의 글들도 전체 화면 보기 글쓰기처럼 · 공통으로 1개의 기준으로 사용한다」 · 「이렇게 이쁘잖아」(작업 전체 화면 「설명」 편집).

```
목표        : 문서 · 게시판 글(업무 · 카페 · 익명)의 본문을 작업 전체 화면과 같은 열 · 같은 읽기 흐름 · 같은 편집 칸(도구 줄 상자 + 큰 흰 편집 칸 + 「편집 마침」)으로 바꾼다.
요구사항    : DESIGN 2.35(main 의 DESIGN.md — 2026-10-14) · 2.21 · 2.24 · 2.25 · 2.27.
수정 파일   : src/community/handlers/page_view.php(본문 · 도구 줄 · 댓글 자리 마크업 — 판정 0) · public/assets/community/editor.js(문서 화면 편집 시작 · 마침 · 빈 문서 안내 · 편집기 마운트를 작업 칸과 한 함수로) ·
              public/assets/community/work.js(그 공용 함수를 꺼내 쓸 때 필요한 최소) · public/assets/community/community.css · app.css(한 열 · 읽기 흐름 · 편집 칸 — 옛 #page-editor 가운데 좁은 규칙 삭제 · 종이 모드 그대로) ·
              커뮤니티 JS import 버전 줄 · 시험 — tests/community/qf12_w1_browser.py 에 W1c 단언 · 문서 화면 본문 상자 · 편집 단추 자리를 재던 옛 단언은 그 단언만(목록을 보고서에) · docs/qf12-w1-report.md(W1c 절)
금지 패턴   : 편집기 · 도구 줄 · 댓글 부품 두 벌 · 저장 창구 · 판정 바꾸기 · innerHTML · 인라인 style · 새 색값 · 데이터베이스 · 프로젝트 화면 폭 바꾸기 · git add -A.
완료 기준   : 1440 · 1280 · 960 · 412: 문서 · 업무 게시판 글 · 카페 글 · 익명 글 · 작업 전체 화면 다섯이 같은 열(본문 왼끝 = 제목 왼끝 · 열 폭 ≤ 960 · 다섯의 열 x · 폭 같음) ·
              읽기 본문 상자 테두리 · 바탕 0 · 빈 문서(고칠 수 있음) 안내 누름 → 편집 · 편집 = 도구 줄 상자 + 편집 칸(최소 16rem · 열 폭) + 「편집 마침」(제목 줄 「편집」 숨김) · 마침 → 저장 · 읽기 흐름 ·
              보기만 하는 사람 · 손님: 편집 칸 0 · 안내 「아직 내용이 없어요」 · 종이 모드 문서 · 데이터베이스 · 프로젝트 화면은 전과 같은 폭 · 댓글 같은 열 · 멘션 부품 그대로 · 넘침 0 · 콘솔 0 · 스크린샷(카페 글 · 문서 읽기 · 편집 1440)
```
