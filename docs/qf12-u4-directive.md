# 지시서 U4 — 작업 전체 화면 · 첫 화면 깜빡임 · 편집 중 그림 · 댓글 멘션 (2026-10-13)

사용자 요청(2026-10-13 v2.64.0 써 본 뒤): 영상 「전체 화면이 뜨면 안 될 것 같은 곳에서 뜨고 다음 페이지로 전환」 · 「이미지는 삽입 즉시 보이지 않고, 검사 완료 뒤 편집 화면에서도 안 보임 · 드래그로 크기 조정 · 이미지를 참조하면서 작성」 · 「댓글 @ 후보 목록이 안 보이고 정확한 아이디를 쳐야만 · 됐는지 모름 · 확인되면 파랑, 못 찾으면 빨강 · 누르면 수정」 · 「U4 진행하면서 같이 개선해」. 결정 WK-28(그림 자유 크기 — 5% 간격).
기준 문서(**정답지**): 기획 `/var/www/qaredi/community/work-ux-plan.md` **16장**(16.1 사용자 말 · 16.2 범위 · 16.4 설계 · 16.5 보안 영향) · `DESIGN.md` **2.25 · 2.26 · 2.27**(+ 2.21 · 2.24 그대로) · 6장 · `ARCHITECTURE.md` 13장 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장.
단위: 사용감 라운드 한 묶음(CLAUDE.md 3.1). **시험은 「한 기능에 한 번」** — 구현 에이전트는 **수락 시험만**(아래) 돌린다. 기준 판 시험 · 다른 회귀 · 같은 묶음 반복은 하지 않는다(메인이 최종 판에서 한 번).

## 공통

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf12u4-20261013`**(브랜치 `feat/qf12-u4` — 메인이 만든다). 결과 **`/home/changkwon/qaredi-qf12u4-results-20261013/`**. 운영 · 운영 DB · 다른 worktree 읽기 · 쓰기 0(기준 문서만 읽기) |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <폴더>` — 포트 8768 한 번에 하나(시작 전 `ss -ltn | grep 8768`) |
| 브라우저 | `. /home/changkwon/qaredi-qf12u4-results-20261013/env.sh` |
| 커밋 | 파일 지정(`git add -A` 금지) · 한글 · 첫 줄 `Qaredi Q:F-12 U4: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |
| 디스크 | 스크린샷은 필요한 것만 |

```
목표        : 프로젝트 · 작업을 열 때 일반 문서 화면이 깜빡이지 않고, 작업 전체 화면이 오른쪽 칸과 같은 모양이며,
              편집 중에도 그림이 보이고 끌어서 크기를 바꾸며, 댓글 @ 멘션이 후보 목록 · 확인 색 · 누르면 고치기를 갖게 한다.
요구사항    : 기획 16.4 U4-1 ~ U4-4 · 16.5 · DESIGN 2.25 · 2.26 · 2.27 — 읽고 시작할 것.
수정 파일   : 서버 — src/community/handlers/page_view.php(data-page-kind · section#task-full 빈 칸 · 판정 decidePageRead 통과 때만) ·
                 src/community/routes.php(community_page_row SELECT 에 자기 · 부모 데이터베이스 purpose 두 열 — 읽기 열만) ·
                 src/community/handlers/mention_candidates.php(빈 q — 최근 이 문서에 쓴 사람 → 아이디 순 · 판정 상한 50 · 통과 10 · 응답 모양 그대로) ·
                 public/assets/community/doc-schema.json(image.width_pct — 정수 10~100 · 5의 배수 · 선택) · src/community/Doc.php(그 검사 — 필요한 최소) ·
                 src/community/DocRender.php · DocMarkdown.php · src/community/export_helpers.php · 내보내기 CSS(doc-export.css)(width_pct → doc-image--p{N}) ·
              화면 — public/assets/community/work.js(createPane 의 mode 'full' · pageLoaded 에서 task 이면 #task-full 에 · 칸 댓글에 멘션 부품) ·
                 public/assets/community/editor.js(그림 NodeView · 크기 손잡이 · 전체 화면 댓글을 멘션 부품으로 · data-page-kind 와 「문서로 보기」) ·
                 public/assets/community/editor-schema.js(image attrs width_pct · toDoc/fromDoc) · public/assets/community/doc-render.js(width_pct 클래스) ·
                 public/assets/community/mention-input.js(새 — createMentionInput 한 벌) · public/assets/app.css · public/assets/community/community.css(2.25 ~ 2.27 클래스 · doc-image--p10~p100) ·
                 커뮤니티 JS import 버전 줄(`php scripts/community_asset_version.php` 인자 없이) ·
              시험 — tests/community/qf12_u4_browser.py(새 수락 — 브라우저) · tests/community/qf12_u4_http.py(새 수락 — 서버: 화면 종류 · 빈 q 후보 · width_pct 검증) ·
                 tests/community/memories_harness.py(choices 두 줄) · tests/catalog.json · 바뀐 화면을 재던 옛 단언이 깨지면 그 시험 파일의 그 단언만(이유를 보고서에) ·
              문서 — wiki/community/guide-work.json(작업 전체 화면 · 그림 크기 · 멘션 한 단락씩) · docs/qf12-u4-report.md(새)
              그 밖 0. 보안 핵심 파일은 Doc.php 의 width_pct 검사와 routes.php 의 SELECT 두 열만. CommunityAuthz.php · Http.php · Upload.php 0.
금지 패턴   : SQL 문자열 결합 · innerHTML · 인라인 style(그림 폭은 클래스 19개 · 칸 폭은 지금 CSS 변수 하나만) · 새 색값 · 판정 두 벌 ·
              data-page-kind 를 못 보는 사람에게 싣기 · 편집기 · 칸 부품 · 멘션 부품 두 벌 · 서버 멘션 해석(정확한 아이디 — comments_create.php) 바꾸기 · git add -A.
완료 기준   : ① qf12_u4_http(격리): 프로젝트 · 작업 · 문서 페이지 HTML 의 data-page-kind 가 각각 project · task · doc · 그 문서를 못 보는 사람 · 없는 id 는 속성 0(HTML 이 같은 모양) ·
                 빈 q 후보 — 그 문서를 볼 수 있는 쓰기 자격 사람만 · 부르는 사람 제외 · 최대 10 · 판정 상한 50 · 못 보는 사람 · 손님 403 그대로 · 응답 키 그대로 ·
                 width_pct: 10 · 55 · 100 저장 200 · 9 · 101 · 57 · 문자열 400 · 옛 width 만 있는 문서 그대로 · 서버 렌더 · 내보내기가 doc-image--p55 클래스(인라인 style 0)
              ② qf12_u4_browser(격리 · 1440 · 412): 프로젝트 · 작업 첫 프레임에 일반 문서 부품 0(load 직후 스크린샷 + DOM) · 작업 전체 화면 = 칸 부품(속성 · 설명 · 하위 작업 · 댓글 · 모든 활동) ·
                 「‹ 프로젝트」 · 「문서로 보기」 → 옛 모양 · 편집 중 그림 블록이 img(tbox: · att: 둘 다) · 검사 중 상자 · 손잡이 끌기 → 폭 클래스 · 저장 뒤 다시 열어도 같은 폭 · Alt+→ 5% ·
                 댓글(전체 화면 · 칸 둘 다): @ → 후보 목록 · 글자로 좁힘 · ↑↓ Enter · 확인된 아이디 파랑 · 없는 아이디 빨강 · 아래 줄 글자 · 토큰 안 캐럿 → 목록 다시 · 고르면 바꿈 ·
                 보기만 하는 사람 · 손님: 손잡이 0 · 댓글 칸 0(지금 규칙) · 폭 여섯 × 밝게 · 어둡게 · 고대비 넘침 0 · 콘솔 0 · 누름 자리 < 44 0
              ③ 정적: security_lint 0 · php -l(바뀐 PHP) · node --check(바뀐 JS) · asset_version --check · wiki_check · git diff --check
              그 밖 시험(qf12_work_browser · shell · floor · server · stage* 등)은 **돌리지 않는다** — 메인이 최종 판에서.
보고 형식   : docs/qf12-u4-report.md — 변경 파일 / 돌린 수락 시험과 결과(결과 폴더) / 못 한 것 / DESIGN 과 다르게 한 것. 짧게.
```
