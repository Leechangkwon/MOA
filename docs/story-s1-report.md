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
