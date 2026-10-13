# 지시서 U5 — 프로젝트 첫 화면 남은 깜빡임 · 오른쪽 위 도구 · 모아 홈 바로가기 (2026-10-13)

사용자 요청(2026-10-13 v2.65.0 뒤 캡처 셋) — 기획 `/var/www/qaredi/community/work-ux-plan.md` **17장**(17.1 사용자 말 · 17.2 원인 · 고칠 것)이 정답지. 화면 규칙 `DESIGN.md` **2.28**(+ 2.18 · 2.25 그대로).
**시험은 「한 기능에 한 번」** — 구현 에이전트는 수락 시험만. 다른 회귀 · 기준 판 · 반복 0(메인이 최종 판에서 한 번).

| 항목 | 내용 |
|---|---|
| 구현 자리 | worktree **`/home/changkwon/qaredi-qf12u5-20261013`**(브랜치 `feat/qf12-u5`) · 결과 **`/home/changkwon/qaredi-qf12u5-results-20261013/`** · 운영 · 다른 worktree 0 · 포트 8768 한 번에 하나 · 브라우저 `. <결과>/env.sh` |
| 커밋 | 파일 지정 · 한글 · 첫 줄 `Qaredi Q:F-12 U5: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : 프로젝트 · 작업을 열 때 어떤 중간 화면도 보이지 않고, 오른쪽 위 도구가 글자로 무엇인지 보이며, 모아 홈 바로가기가 가운데 · 하나로 정리되게 한다.
요구사항    : 기획 17.2 U5-1 ~ U5-3 · DESIGN 2.28 · 6장(새 색값 0 · 인라인 style 0 · innerHTML 0 · 새 클래스는 `.skeleton` 과 그 하위 · `shell-tools__me` 안 글자 span 만).
수정 파일   : public/assets/app.css · public/assets/community/work.js(뼈대 걷기 — 프로젝트 화면 · 작업 전체 화면이 선 뒤) · src/community/handlers/page_view.php(뼈대 마크업 — data-page-kind 가 project · task 일 때만) ·
              src/views/shell_top.php(shell-tools 의 앱 단추 글자 · 사용자 칩 — 마크업만, 판정 0) · src/views/launcher.php($homeShortcuts · 설명 · 가운데 정렬 클래스) ·
              src/Apps.php(ICON_PATHS 에 'spaces' 고정 path 하나 — 그 밖 0) · public/assets/moa.css 또는 홈 CSS(바로가기 가운데 정렬이 거기 있으면) ·
              커뮤니티 JS import 버전 줄(인자 없이 `php scripts/community_asset_version.php`) · tests/community/qf12_u5_browser.py(새 수락) · tests/community/memories_harness.py(choices 한 줄) · tests/catalog.json ·
              바뀐 화면을 재던 옛 단언이 깨지면 그 단언만(보고서에 이유 — 예: q_launcher 의 타일 수 · 셸의 shell-tools 크기) · docs/qf12-u5-report.md(짧게)
금지 패턴   : SQL 결합 · innerHTML · 인라인 style · 새 색값 · 판정 · 라우트 변경 · data-page-kind 규칙(U4) 바꾸기 · git add -A.
완료 기준   : qf12_u5_browser(격리 · 1440 · 412 · 밝게/어둡게): ① 프로젝트 · 작업 페이지를 열 때 load 직후부터 화면이 설 때까지 **매 프레임**(requestAnimationFrame 기록) `#db-add-view` · 일반 표 · 일반 문서 부품이 보이는 프레임 0 · 뼈대가 보였다 사라짐 · 일반 문서 페이지는 뼈대 0
              ② shell-tools: 앱 단추 글자 「앱」 보임 · 높이 40 · 사용자 칩에 표시 이름 + 「님」 · 아바타 32 · 누르면 /tbox/settings · 긴 이름 말줄임 · 넘침 0 · 폰(412)은 지금 그대로
              ③ 모아 홈(/): 바로가기 줄이 가운데(왼쪽 · 오른쪽 여백 차 ≤ 2px) · 「내 작업」 타일 0 · 「업무 홈」 하나 + work 아이콘 · 「우리 공간」 spaces 아이콘 · 넘침 0
              ④ 정적: security_lint 0 · php -l · node --check · asset_version --check · git diff --check
보고 형식   : docs/qf12-u5-report.md — 변경 파일 / 수락 결과 / 고친 옛 단언과 이유 / 못 한 것.
```
