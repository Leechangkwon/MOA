# U4 구현 보고 (2026-10-13 · 브랜치 feat/qf12-u4)

지시서 `docs/qf12-u4-directive.md` · 기획 16.4 · DESIGN 2.25 ~ 2.27. 결과 폴더 `/home/changkwon/qaredi-qf12u4-results-20261013/`.

## 변경 파일
- 서버: `src/community/handlers/page_view.php`(읽기 판정 통과 때만 `<body data-page-kind>` · 작업이면 빈 `section#task-full` · 옛 `ul#comment-mentions` 뺌) · `src/community/routes.php`(page row SELECT 에 `dbself.purpose` · `dbrow.purpose` 두 열) · `src/community/handlers/mention_candidates.php`(빈 q = 최근 이 문서에 쓴 사람 → 아이디 순 · 판정 50 · 통과 10 · q 가 있으면 정확한 아이디 맨 앞) · `public/assets/community/doc-schema.json`(`image.width_pct: "pct5?"`) · `src/community/Doc.php`(`pct5` 검사) · `src/community/DocRender.php`(`imageWidthClass` — 19 클래스) · `public/assets/community/doc-export.css`(19줄)
- 화면: `public/assets/community/work.js`(createPane `mode:'full'` · pageLoaded → `#task-full` · 「문서로 보기」 · 칸 댓글 멘션 부품) · `editor.js`(그림 NodeView `imageViews` · 손잡이 · Alt+←/→ · 그림 창 「끌어서 맞춘 폭」 · 전체 화면 댓글 → 멘션 부품 · 작업 화면에서 `?edit=1` 무시) · `editor-schema.js`(`width_pct` attrs · toDoc/fromDoc · 그림 항목 설명) · `doc-render.js`(`imageWidthClass` · `renderFileBlock` export) · **새** `mention-input.js`(`createMentionInput` 한 벌) · `app.css`(`body[data-page-kind]` · `.task-pane--full`) · `community.css`(19 클래스 · 편집 중 그림 · 멘션 클래스) · import 버전 줄(dashboard · database · suggestions · table-editor · templates · `../messenger.js`)
- 시험: 새 `tests/community/qf12_u4_http.py` · `qf12_u4_browser.py` · `memories_harness.py`(choices) · `tests/catalog.json`(두 항목)
- 옛 시험 단언: `browser_5d.py` — 후보 목록이 「칸 안 static」에서 떠 있는 목록(DESIGN 2.27)으로 바뀌어 위치 단언만 `fixed|absolute` 로. `qf12_work_browser.py` U3 두 곳 — 작업(프로젝트 행) 전체 화면이 칸 부품이 되어 전체 편집기로 가려면 더보기 「문서로 보기」를 먼저 누르는 단계만 더함(단언 그대로). 둘 다 **돌리지 않음(미검증)** — 메인 최종 판에서.
- 문서: `wiki/community/guide-work.json`(세 단락) · 이 보고서

## 수락 시험 (격리 틀 · 포트 8768)
- ① `qf12_u4_http` **77/0** — `http/`
- ② `qf12_u4_browser` **284/0** — `browser/`(shots 포함) · 그 뒤 멘션 입력 칸 `spellcheck=false` 한 줄을 더하고 mention 묶음만 다시 **77/0** — `browser-mention-recheck/`
- ③ 정적 — `static.log`: security_lint 0 · php -l · node --check · asset_version --check · wiki_check · git diff --check 통과

## 못 한 것
- 원본 `/var/www/qaredi/community/doc-schema.json`(worktree 밖)은 고치지 않음 — 합칠 때 사본(`public/assets/community/doc-schema.json`)과 같게 맞춰야 함(카탈로그의 diff 시험).
- 다른 회귀(qf12_work_browser · shell · floor · server · stage* · browser_5d)는 지시대로 돌리지 않음.
- 작업 화면의 「문서로 보기」 뒤 「작업으로 보기」 되돌리기는 없음(다시 열면 작업 모양).
- 지나며 본 옛 문제(손대지 않음): 작업 칸 활동 탭의 「오래된 순」(`btn btn--plain`)이 밝은 테마에서 글자가 거의 안 보임 · 1440 편집 상태에서 사이드바 `.document-select-hit` 체크 상자에 빈 `style=""` 속성(내 코드 아님 — 시험은 빈 속성을 셈에서 뺌).

## DESIGN 과 다르게 한 것
- `<body data-page-kind>`: `shell_top.php` 가 허용 목록 밖이라 `page_view.php` 가 셸 출력을 버퍼로 받아 `<body data-service=` 한 곳에 고정 글자 속성을 더함.
- 멘션 표시: 바탕(`--accent-soft` / `--danger-soft`)만으로는 밝은 테마에서 거의 안 보여 같은 층에 2px 밑줄(`box-shadow inset` — `--accent` / `--danger`, 글자 자리 불변)을 더함. 후보 목록은 `.menu` 틀 없이 `.menu__list`(빈 목록이면 `.menu` 가 통째로 숨는 규칙 때문). 이름이 없으면 아이디 한 번만.
- HTML 내보내기(받는 파일)는 U3-1b 대로 그림을 링크로 둬서 `doc-image--p55` 는 서버 렌더 · 인쇄 화면(`/p/{id}/print`)에서 확인 · 내보내기 CSS 에는 19줄이 따라감. Markdown 은 폭 표현이 없어 `DocMarkdown.php` · `export_helpers.php` 무변경.
- 손잡이는 기획(16×16)이 아니라 DESIGN 2.26(보이는 8×32 · 누름 자리 44).
- 작업 전체 화면 머리 순서 「‹ 프로젝트」 · 완료 표시 · 링크 복사 · 더보기 · 하위 작업 › 는 그 작업의 전체 화면 주소(`?task=` 0).
- 빈 q 「최근 쓴 사람」 = 그 문서의 지워지지 · 숨기지 않은 댓글 쓴 사람 + 판 기록 고친 사람(순서에만 씀).
