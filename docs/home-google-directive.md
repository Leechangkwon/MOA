# 지시서 H1 — 모아 홈 구글 첫 화면 방식 (2026-10-14)

사용자(스크린샷 — 지금 「내 홈」과 구글 첫 화면): 「이 홈도 개선하자 — 대시보드는 펼치면 보이도록 하고, 구글 UI/UX 와 같은 방식으로 Qaredi · 검색(페이지 내 자료 같은 거 찾기) · 각 기능별 UI · 하단에 대시보드. 우측 상단에는 다른 페이지와 같이 메신저 · 앱 · ID 형태로 개선해 줘」.
기준 문서(**정답지**): `DESIGN.md` **2.31**(+ 2.30 오른쪽 위 셋 · 2.28 앱 단추 · 2.17 메신저 · 6장) · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 「한 기능에 한 번」 · 「밤 회귀」).
단위: 사용감 라운드 한 묶음. 시험은 **수락 시험만**(다른 회귀 0 — 밤 회귀).

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-home-20261014`**(브랜치 `feat/home-google` — 메인이 만든다). 결과 **`/home/changkwon/qaredi-home-results-20261014/`**. 운영 · 운영 DB · 다른 worktree 읽기 · 쓰기 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <폴더>` — 포트 8768 한 번에 하나 · 끊으면 합성 DB · 임시 폴더 · PHP 서버를 치우고 다음 실행 |
| 브라우저 | `. /home/changkwon/qaredi-home-results-20261014/env.sh` |
| 커밋 | 파일 지정(`git add -A` 금지) · 한글 · 첫 줄 `Qaredi 홈 H1: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : 로그인한 모아 홈을 「Qaredi」 글자 로고 · 큰 검색 칸 · 기능 바로가기 · 접힌 대시보드로 바꾸고, 오른쪽 위를 셸과 같은 메신저 · 앱 · 내 계정으로 한다.
요구사항    : DESIGN 2.31 전부 · 2.30(내 계정 판의 「테마」 타일 — 모든 화면) — 읽고 시작할 것. 새 색값 0 · 새 클래스는 2.31 의 다섯.
수정 파일   : src/views/shell_tools.php(새 — 지금 shell_top.php 의 div.shell-tools 마크업을 옮김 · 앱 판 현재 앱 인자) · src/views/shell_top.php(require 로 바꿈 · 다른 변화 0) ·
              src/views/launcher.php($launcherLive 갈래 — 머리 · 가운데 묶음 · details 대시보드 · 바로가기 template 의 메신저 열기) · src/handlers/home.php(런처에 필요한 셸 변수 · 테마 창 · 검색 창 · 메신저 자리 — 판정 0) ·
              public/assets/home-dashboard.js(접힘 · 펼칠 때 처음 부름 · 기억 · 「＋ 바로가기」 → 펼치고 바로가기 설정 열기 · 편집 중 접기 막음) · public/assets/search.js(다른 자리에서 글자를 주고 여는 함수 하나 — 부품 두 벌 0) ·
              public/assets/app.js(두 판 · 메신저 단추가 런처에서도 같은 함수로 돌게 — 필요한 최소) · public/assets/moa.css · public/assets/app.css(필요한 최소) ·
              런처가 싣는 JS · CSS 줄(버전 줄 포함) ·
              시험 — tests/community/home_h1_browser.py(새 수락) · memories_harness.py(choices) · tests/catalog.json ·
                 런처 머리 · 「내 홈」 제목 · 바로가기 자리를 재던 옛 단언이 깨지면 **그 단언만**(파일 · 단언 이름을 보고서에 — 그 시험은 돌리지 않는다) ·
              문서 — wiki 의 모아 홈 안내 글(있으면 그 한 단락) · docs/home-google-report.md(새)
              그 밖 0. 보안 핵심 파일 0 · 창구 · 판정 0(화면만).
금지 패턴   : innerHTML · 인라인 style · 새 색값 · 셸 도구 · 검색 · 판 · 메신저 여는 길 두 벌 · 접힌 대시보드가 위젯 창구를 부르기 · git add -A.
완료 기준   : home_h1_browser(격리 · 1440 · 1280 · 960 · 720 · 412 · 360 × 밝게 · 어둡게 · 고대비):
              ① 머리: 「전체 서비스」 · 「테마」 글자 단추 · 「m. 모아」 로고 0 · div.shell-tools 하나(메신저 · 앱 · 칩 순서 · 셸 화면과 같은 마크업 — 한 파일 require) · 앱 판 「모아 홈」 aria-current · 칩 → 내 계정 판(내 계정 · 내 활동 · 테마 · 로그아웃) · 「테마」 → 테마 창 · 셸 화면(/tbox/ · /community/)의 내 계정 판에도 「테마」
              ② 가운데: 「Qaredi」 64px(412 에서 44) · h1 화면 읽기 「모아 홈」 · 검색 칸 52 · 반경 26 · 폭 ≤ 720 · `/` 키 초점 · 글자 + Enter → 공통 검색 창이 그 글자로 열리고 결과(페이지 · 파일 묶음) · 자동 초점 0
              ③ 바로가기: 원 56 · 아이콘 24 · 이름 13 · 기본 다섯 · 홈 편집 설정 순서대로 · 「메신저」 누름 → 메신저 창(페이지 이동 0) · 「＋ 바로가기」 → 대시보드 펼침 + 바로가기 설정 열림 · 한 줄 최대 8 · 412 에서 4
              ④ 대시보드: 첫 화면 접힘 · 접힌 동안 위젯 창구 요청 0(네트워크 기록) · 펼침 → 지금 위젯 · 홈 편집 · 새로 고침 그대로 · 새로 고침 뒤 펼침 기억 · 편집 중 접기 막힘
              ⑤ 로그인 전 · 동의 전 런처는 전과 같음(머리 · 소개 화면) · 넘침 0 · 대비 4.5 · 콘솔 0 · 누름 자리 < 44 0 · 스크린샷(1440 밝게 · 어둡게 · 412 · 펼친 대시보드)
              정적: security_lint 0 · php -l · node --check · asset_version --check · wiki_check · git diff --check
보고 형식   : docs/home-google-report.md — 변경 파일 / 수락 시험과 결과 / 고친 옛 단언 / 못 한 것 / DESIGN 과 다르게 한 것. 짧게.
```
