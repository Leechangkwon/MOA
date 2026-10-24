# 보고 Q:F-15 S1b — 남은 일곱 자리 (2026-10-25 · 구현 Opus)

지시서 `docs/qf15-s1b-words-directive.md` 그대로. 자리: worktree `/home/changkwon/qaredi-qf15s1b-20261025`(브랜치 `feat/qf15-s1b-words` · main `8a3977b` 기준) · 결과 `/home/changkwon/qaredi-qf15s1b-results-20261025/accept/`. 운영 트리 · 운영 DB · 운영 HTTP 읽기 · 쓰기 0. 시험 계정은 fixture 의 `claude_` 합성 계정 · UA `tbox-review/2026-10-25`.

## 변경 파일

- 표 #1~#7c(보이는 글자만 · 로직 0): `src/handlers/member_messages_page.php`(#1) · `public/assets/home-dashboard.js`(#2a · #2b) · `public/assets/moa.js`(#3a~#3c · :14 그대로) · `public/assets/member-assist.js`(#4 · :101 그대로) · `src/views/member_invites.php`(#5 · :39 그대로) · `src/handlers/legal_page.php`(#6a 파비콘 한 줄 · #6b 머리 note) · `src/community/routes.php`(#7a~#7c — `community_not_ready()` 문자열 셋 + 파비콘 `<link>` 한 줄)
- 시험: `tests/community/brand_words_browser.py`(제외 셋 풂 · [static] ⑥ ⑦ · [pages] 더함) · `tests/catalog.json`(`community.brand_words_browser` 의 covers 여섯 · notes 한 구절)
- 보고: `docs/qf15-s1b-report.md`(이 글)

## 수락 시험과 결과

`python3 tests/community/memories_harness.py --port 8768 --suite brand_words_browser.py --output /home/changkwon/qaredi-qf15s1b-results-20261025/accept` (시작 전 `ss -ltn` 8768 · 8769 빔 · 최종 판에서 전체 한 번)
→ **`RESULT pass=1178 fail=0`** · `OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1` (원문 `accept/run.log` · 단언 `accept/brand-words-results.json` · 측정값 `accept/brand-words-metrics.json`). S1 은 1046.

| 묶음 | 수 | S1b 에서 더한 것 |
|---|---|---|
| 준비 | 9 | `claude_employee02` 홈 구성에 legacy_stories 위젯(PATCH 200) |
| [static] | 14 | ⑥ 일곱 자리 파일 · `community_not_ready()` 몸통에 옛 글자 아홉 0 · 바꿀 글자 있음 · 그대로 둘 셋(moa.js:14 · member-assist.js:101 · member_invites.php:39) 그대로 · 법적 문서 파비콘 줄이 app.css 줄 바로 위 ⑦ h1 「업무」 · 파비콘 `<link>` · `http_response_code(503)` · `Http::error(503, …)` · `exit` 있음 · `8a3977b` 와 견준 그 함수의 바뀐 줄 「−3 / +4」(문자열 셋 + `<link>` 한 줄) |
| [must] | 76 | /tbox/users 「공급자: Google Gemini」 보임 · 「Gemini 3.5」 0 · /tbox/messages 부제 「회사 공용 AI · 내 대화는 나만 봐요」 · 시작 안내 「모아 홈 시작 안내」 · 「홈 편집」 h2 · aria-label 「내 대시보드 편집」 · 법적 문서 머리 note 한 줄 소개 |
| [pages] | 926 | 화면 36(+ /tbox/users · 「게시판 새 글」 홈) × 1280 · 412 × 밝게 · 어둡게. 「홈 편집」 연 화면 금지 낱말 0 · 「취소」로 닫힘 · home-dashboard 저장 요청 0 · 「게시판 새 글」 게시판 넷(공지사항 · 익명게시판 · 질문과 답변 = 글 없음 「아직 새 글이 없어요. 우리 공간에서 첫 글을 남겨 보세요.」 · 자유게시판 = 카드 「Q:F-15 합성 게시판 글 · 글을 열어 보세요.」) 불러오는 중 · 상태 · 카드 · aria-label 「이야기」 「커뮤니티」 0 · 법적 문서 link[rel=icon] |
| [contrast] | 152 | 시작 안내 머리 · AI 설정 칸 아래 글 · 「게시판 새 글」 상태 · 카드 · 편집 창 제목 ≥ 4.5 |
| [shots] | 1 | (화면 캡처 더함: `accept/shots/home-edit-1280-light.png` · `home-feed-1280-light.png`) |

원문 줄(발췌): `PASS ⑦ community_not_ready() vs 8a3977b: changed lines = 3 strings + 1 favicon <link> only (−3 / +4 · branch · headers · exit unchanged)` · `PASS [must] users-1280-light: admin AI settings 「공급자: Google Gemini」 shown · 「Gemini 3.5」 0 ({'t': '공급자: Google Gemini', 'shown': True})` · `PASS [pages] home-1280-light: home edit closed with 「취소」 — home-dashboard save requests 0 (non-GET [])`.

남은 제외(그대로 · 근거는 시험 파일 `EXCLUDE`): 이야기 작성 창 · 공개 범위 고르개 · 공개 범위 표시 · 법적 문서 본문. 풀린 셋(`EXCLUDE['home']` · `EXCLUDE['messages']` · `LEGAL_NO_ICON`)은 metrics `excluded` 에 0. 표 밖 자리에서 금지 낱말이 나온 화면 0(metrics `hits` 빈 값) — 새 제외 0.

정적(최종 판): `scripts/security_lint.sh` 「결과: 통과 (금지 패턴 0건)」 rc 0 · `php -l` 바꾼 PHP 넷 「No syntax errors detected」 · `node --check` 바꾼 JS 셋 오류 0 · `php scripts/community_asset_version.php --check` rc 0(부품 버전 b76472e6847d) · `php scripts/wiki_check.php` 「통과 (구조 문제 0건 · 빠진 글 0건, 글 70편)」 · `git diff --check` 0.

## 기대값을 바꾼 옛 시험

없음 — grep(`tests/` · `review-scripts/` · `scripts/`)으로 바뀐 글자를 단언하는 줄을 다시 찾았고 지시서와 같이 0(`tests/dbg_s2_security.sh:354` · `tests/q_launcher.sh:188` 의 「개인 업무 공간」은 로그인 전 첫 화면에 「없음」 단언 · `tests/community/stage3.sh:49` 는 주석 · `tests/community/home_h1_browser.py` 의 「내 홈」은 h1 「없음」 단언 — 모두 그대로 맞음).

## 표에 없는데 찾아서 고친 글자

없음.

## 못 한 것

- 업무 준비 중 화면(`community_not_ready()` 화면 갈래)은 격리 틀에서 띄우지 않았다(스키마가 갖춰진 합성 DB 라 그 갈래로 가지 않음) — 지시서대로 [static] ⑦ 로 갈음.
- 옛 시험 · 회귀는 돌리지 않음(지침 3.1 — 밤 회귀 대기열 · 메인 몫).
- 지시서 「바꾸지 않는 것」의 넷(Doc.php:1350 · MemberAi.php:233 · member_ai_settings.php:33 · member-assist.js:101)은 그대로 — 사용자 판단 대기.

## 지시서와 다르게 한 것

- 「게시판 새 글」 묶음: 기본 홈 구성(v3)에는 그 위젯(legacy_stories)이 없어 `#home-stories` 가 숨겨져 있다(S1 은 숨은 채로 첫 게시판 글자만 읽었다). 그래서 **둘째 합성 직원 `claude_employee02`** 의 구성에만 그 위젯을 창구 `PATCH /tbox/api/me/home-dashboard`(합성 DB)로 더한 화면 `home-feed` 를 두고 거기서 게시판 탭을 눌러 본다. 첫째 직원(`home`)은 기본 구성 그대로 — 시작 안내 · 「홈 편집」 단언은 그쪽.
- `/tbox/users` 는 비밀번호 다시 확인 뒤 화면이라 `alerts-app` 처럼 `reauth` 세션으로 연다(아니면 확인 창만 보인다).
- [static] ⑦ 의 기준은 merge-base 가 아니라 지시서 기준 판 `8a3977b` 고정(합친 뒤에도 그 함수의 바뀐 줄을 잴 수 있게). S1 ② · [shots] 는 그대로(merge-base — 이제 설치 아이콘 전후가 같은 그림이 되지만 단언은 S1 그대로).
- catalog covers 는 지시서 「일곱」 중 `src/community/routes.php` 가 이미 있어 여섯을 더함.
- 대비 단언 자리를 더함(시작 안내 머리 · AI 설정 칸 아래 글 · 「게시판 새 글」 상태 · 카드 · 편집 창 제목) · 화면 캡처 둘을 더함.
