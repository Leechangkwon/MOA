# 지시서 S1 — 이야기(스레드) 화면 정리 (2026-10-14)

받은 피드백(스크린샷 — 「나의 스레드」에 빨간 표시 셋): 사이드바 머리 글자 「줄바꿈」(v2.68.0 H5 로 해결됨) · 위 큰 배너 + 프로필 카드 「죽은 공간」 · 오른쪽 카드 「쓰레기 영역」.
기준 문서(**정답지**): `DESIGN.md` **2.36**(+ 6장) · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 수락 시험만).

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-s1-20261014`**(브랜치 `feat/story-s1` — 메인이 만든다) · 결과 **`/home/changkwon/qaredi-s1-results-20261014/`** · 운영 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --port 8768 --suite <파일> --output <폴더>` — 시작 전 `ss -ltn` 로 8768/8769 비었는지 · 끊으면 내 것만 치움 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `Qaredi 이야기 S1: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : 이야기 화면에서 배너 띠와 오른쪽 카드를 없애고, 프로필을 한 줄 머리로 줄이며, 글쓰기 칸 · 탭 · 프로필 · 피드를 가운데 한 열(최대 680)에 맞춘다.
요구사항    : DESIGN 2.36 — 읽고 시작할 것.
수정 파일   : src/community/handlers/memories_page.php(배너 · 오른쪽 칸 · eyebrow 마크업 · 판정 0) · public/assets/community/memories.css(한 열 · 프로필 한 줄) · public/assets/community/memories.js(오른쪽 칸 · 배너를 채우던 코드 정리 — 기능 0 변화) ·
              커뮤니티 JS import 버전 줄 · 시험 — tests/community/story_s1_browser.py(새 수락) · memories_harness.py(choices) · tests/catalog.json ·
              배너 · 오른쪽 칸을 재던 옛 단언(memories_runtime_browser 등)은 그 단언만(목록을 보고서에) · docs/story-s1-report.md(새)
금지 패턴   : 창구 · 판정 · 저장 바꾸기 · innerHTML · 인라인 style · 새 색값 · git add -A.
완료 기준   : 1440 · 1280 · 960 · 412 · 360 × 밝게 · 어둡게 · 고대비 · 팔로잉 · 나의 스레드 · 발견 · 남의 프로필 · 공유 스레드 공개 화면:
              .profile-cover 0 · .journal-aside 0 · 「A NOTE TO MYSELF」 「MEMORY STUDIO」 「YOUR DAYS」 글자 0 · 프로필 높이 ≤ 96(1440) · 숫자 다섯 한 줄 · 내 것 단추 둘 · 남의 것 팔로우 ·
              글쓰기 칸 · 탭 · 프로필 · 피드 머리 · 피드 카드의 왼끝 · 폭이 같은 열(≤ 680 · 가운데) · 글쓰기 · 사진 이야기 · AI로 꾸미기 · 좋아요 · 댓글 · 공유 동작 그대로 · 넘침 0 · 대비 4.5 · 콘솔 0 ·
              스크린샷(나의 스레드 1440 밝게 · 어둡게 · 412)
              정적: security_lint 0 · php -l · node --check · asset_version --check · git diff --check
보고 형식   : docs/story-s1-report.md — 변경 파일 / 수락 시험과 결과 / 고친 옛 단언 / 못 한 것 / DESIGN 과 다르게 한 것. 짧게.
```
