# 보고 — 이야기 S1 · 스레드 화면 정리 (2026-10-14)

지시서 `docs/story-s1-directive.md` · 기준 DESIGN 2.36. 격리 worktree `/home/changkwon/qaredi-s1-20261014`(브랜치 `feat/story-s1`) · 결과 `/home/changkwon/qaredi-s1-results-20261014/` · 운영 0 · push · 발행 · 합치기 0.

## S1 — 배너 · 오른쪽 칸 없앰 · 한 열

### 변경 파일

| 파일 | 내용 |
|---|---|
| `src/community/handlers/memories_page.php` | `.profile-cover` 띠 · `aside.journal-aside`(A NOTE TO MYSELF · MEMORY STUDIO · YOUR DAYS) 삭제 · 창 밖 영문 eyebrow 넷(OUR COMMUNITY · MY THREADS · 피드 머리 `#feed-kicker` · 빈 화면 YOUR STORY STARTS HERE) 삭제. 판정 · 창구 0 |
| `public/assets/community/memories.js` | `#feed-kicker` 를 채우던 한 줄 삭제(그 밖 0) |
| `public/assets/community/memories.css` | 죽은 규칙(journal · studio-card · card-label · aside-footer · profile-cover · eyebrow 등) 삭제 · 흩어진 프로필 · `.main-columns` 규칙을 한 자리로 · 프로필 한 줄 머리(격자 48 · 1fr · auto — `.profile-content` 는 `display:contents`) · 한 열(`max-width:680px` 가운데) · 피드 제목 18/600 · `.hero-sub` 색 `var(--muted)` · 공개 화면 `--muted` 값 `#5d675f`(같은 파일에 있던 값) |
| 커뮤니티 JS import 버전 줄 | `php scripts/community_asset_version.php` 로 다시 찍음(dashboard · database · editor · suggestions · table-editor · templates · work · ../messenger.js) |
| `tests/community/story_s1_browser.py`(새) · `memories_harness.py`(choices) · `tests/catalog.json`(항목 하나) | 수락 시험 |

### 수락 시험과 결과

`python3 tests/community/memories_harness.py --port 8768 --suite story_s1_browser.py --output …/s1-final` → **RESULT pass=557 fail=0**(179초 · 로그 `s1-final.log` · 측정 `s1-final/story-s1-metrics.json` · 스크린샷 `s1-final/shots/mine-1440-light.png` · `mine-1440-dark.png` · `mine-412-light.png`).

- [grid] 5 폭 × 3(밝게 · 어둡게 · 고대비) × 5 화면(팔로잉 · 나의 스레드 · 발견 · 남의 프로필 · 공유 공개) = 75 화면: `.profile-cover` 0 · `.journal-aside` 0 · 세 문구 0(보이는 글 + 창 밖 마크업) · eyebrow 0(창 밖) · 머리 · 탭 · 글쓰기 칸 · 프로필 · 피드 머리 · 도구 · 첫 카드 왼끝 · 폭 같음(±1) · 열 ≤ 680 · 가운데 · 숫자 한 줄 · 단추(내 것 「공개 범위 설정」「여행 계획」 · 남의 것 「팔로우」 · 공개 화면 0) · 프로필 높이 1440 = 88.9 · 넘침 0 · 대비 ≥ 4.5 · 콘솔 0.
- [act] 글쓰기 · 사진 이야기(사진 → 미리보기 → 저장 · 미디어 행) · AI로 꾸미기 창 · 좋아요(행 · 수) · 댓글(행) · 공유(링크) · 공개 범위 설정 · 여행 계획 · 팔로우(1→0→1) 그대로.
- 첫 실행(`s1-run1`)은 대비 35건 실패 — 기존 `.hero-sub`(#7d877b, 3.25) · 공개 화면 `--muted`(#78817a, 3.72). 위 두 값으로 고쳐 재실행 0 실패.

정적: `scripts/security_lint.sh` 금지 패턴 0 · `php -l` · `node --check`(바뀐 js 전부) · `community_asset_version.php --check` 0 · `git diff --check` 0. 다른 시험 묶음은 돌리지 않음(3.1).

### 고친 옛 단언

없음 — 옛 시험에 배너 · 오른쪽 칸 · eyebrow · `#feed-kicker` 를 재는 단언이 없었다(`grep` 확인).

### 못 한 것

- 공유 공개 화면의 숫자는 지금처럼 **셋**(기록 · 사진·영상 · 여행) — 팔로워 · 팔로잉은 회원 화면에서만 그리던 그대로(기능 0 변화). 시험도 셋 한 줄로 잼.
- 공유 공개 화면에는 셸 테마가 없어 어둡게 · 고대비는 같은 화면(넘침 · 대비만 잼).

### DESIGN 과 다르게 한 것

- 프로필 단추는 `.btn--quiet.btn--small` 클래스를 붙이지 않고 지금 `.secondary` 의 값을 그 모양(높이 32 · 13px · `--r-sm` · 테두리 `--line`)으로 바꿨다 — 이 화면의 `.community-threads button` 초기화가 `.btn` 테두리 · 바탕을 덮어 그대로 붙이면 테두리 없는 단추가 된다(새 클래스 0 규칙 안에서 같은 모양).
- 숫자 줄 누름 자리: 1440 ~ 701px 은 높이 24(프로필 ≤ 96 을 맞추려고) · 700px 이하(폰)는 44.
- 꾸미기 창(`#studio-dialog`)의 머리 eyebrow 「MEMORY STUDIO」는 남겼다 — 「AI로 꾸미기(그대로)」 창이라 손대지 않음. 화면(창 밖)에는 0.

## S1b — 댓글을 창 없이 카드 안에서

### 변경 파일

| 파일 | 내용 |
|---|---|
| `src/community/handlers/memories_page.php` | `dialog#comments-dialog` 마크업 삭제 |
| `public/assets/community/memories.js` | `openComments` · `loadComments` · `renderComments` · 창 제출 처리 → 카드마다 `commentThread(post, detailed)`(카드 끝 `section.post-comments` · 펼침 상태는 카드마다 — 피드 카드와 자세히 보기 카드가 따로). 최근 3 + 「이전 댓글 N개 더 보기」(먼저 불러 둔 것 → 다음은 같은 `?before=` 커서 창구) · 한 줄 textarea 「댓글 달기…」(줄 수만큼 `rows` 1~6 + CSS `field-sizing:content`) · 「게시」(빈 칸 · 공백이면 흐림) · Enter / Ctrl·⌘+Enter 게시 · Shift+Enter 줄바꿈 · 한글 조합 중(`isComposing` · 229) Enter 무시 · 게시하면 끝에 붙이고 입력 비움 · 데스크톱은 초점 유지 · 댓글 수(아이콘 `aria-label` · 「댓글 N개」) 갱신 · 실패는 줄 아래 `.msg` · 읽기 전용 계정은 입력 줄 대신 지금 안내 문장 · 펼칠 때 입력 칸 초점(폰 폭 ≤ 700 또는 `pointer:coarse` 는 초점 0) · 아이콘 · 「댓글 N개」 단추에 `aria-expanded` · `aria-controls` |
| `public/assets/community/memories.css` | 창 전용 규칙(`#comments-dialog` · `.comments-scroll` · 옛 `.comment-compose`) 삭제 · `.post-comments`(이름 13/600 · 시각 12 `--muted` · 글 14 · 입력 줄 · 폰 누름 자리 44) |
| 커뮤니티 JS import 버전 줄 | 다시 찍음 |
| `tests/community/story_s1_browser.py` | [s1b] 단언 · [grid] 75 화면 모두 `dialog#comments-dialog` 0 · [act] 댓글을 카드 안 방식으로 |

창구 · 판정 · 저장 0 변화(`GET`/`POST /api/memories/{id}/comments` 그대로).

### 수락 시험과 결과

같은 묶음 한 번: `… --suite story_s1_browser.py --output …/s1b-run1` → **RESULT pass=583 fail=0**(196초 · `s1b-run1.log` · 스크린샷 `s1b-run1/shots/s1b-comments-1440.png` · `s1b-comments-412.png`). [s1b] 24 단언: 처음 접힘 · 아이콘 → 그 카드만 펼침 · 창 0 · 최근 3 순서 + 「이전 댓글 2개 더 보기」 · 초점 · 빈 · 공백 「게시」 흐림 · 빈 Enter 0 · 게시 → 끝 · 비움 · 수 6 · 조합 중 Enter 0 · Ctrl+Enter 게시 · Shift+Enter 두 줄 · 실패(가로막은 429) `.msg` · 글 남음 · 다시 → 접힘 · 35개 3 → 30 → 커서로 35 · 1440 · 412 넘침 0 · 412 초점 0 · 탭으로 게시 · 손님 안내 · 공유 화면 창 0 · 콘솔 0. 정적 검사 다시 — lint 0 · php -l · node --check · asset_version --check · diff --check.

### 고친 옛 단언 (돌리지 않음 — 3.1)

- `tests/community/memories_browser.py` 180–182: `#comment-body` · `#comment-form` · `[data-close="comments-dialog"]` → `.post-comments textarea` · 제출 · 아이콘 다시 눌러 접힘.
- `tests/community/memories_social_browser.py` 239–245: 자세히 보기에서 댓글 → 「자세히 보기 닫히고 창 하나」 대신 「자세히 보기 그대로 · 열린 창 하나 · 그 카드 안에 펼침」 · 문구 바꿈.
- `tests/community/feedback_browser.py` 34–46: 창의 `#comments-list` 30 → 카드의 최근 3 → 30 → 60 · 「창 안 입력 줄 고정(높이 844 · 440)」 단언은 창이 없어져 뺌 · 초점 단언은 폭 > 700 일 때만(폰은 초점 0이 규칙).

### 못 한 것

- 공유 스레드 공개 화면: 공개 댓글 창구가 없고(공개 GET 셋뿐) 지금도 카드에 좋아요 · 댓글 · 공유가 없어 「같음」 = 창 0 · 댓글 칸 0 으로 했다. 공개 화면에 댓글을 읽게 하려면 창구가 필요해(이번 금지) 따로 정할 일.
- `public/assets/usage-guide.js`(허용 목록 밖)에 옛 창 이름 `#comment-body` 단계 · `comments-dialog` 맥락이 남아 있다 — 요소가 없으면 그 단계만 건너뛰는 것으로 보이나 정리는 다음에.

### DESIGN 과 다르게 한 것

- Enter 도 게시로 했다(「Ctrl/⌘+Enter 로도 보냄」의 「로도」 · 메신저와 같은 손버릇) — 여러 줄은 Shift+Enter.
- 「댓글 N개」 글자 단추는 댓글이 1개 이상일 때만 보인다(0개면 아이콘만).
