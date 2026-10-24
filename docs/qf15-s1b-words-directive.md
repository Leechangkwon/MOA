# 지시서 Q:F-15 S1b — 낱말 · 브랜드 맞추기 보충: 남은 일곱 자리 (2026-10-25)

사용자(2026-10-25): 「남은 일곱 자리를 고치는 Q:F-15 1단계 보충 지시서를 써 줘(같은 규칙 · 보이는 글자만)」.
앞 단계: S1 `docs/qf15-s1-words-directive.md` → 운영 v2.74.0 `2afc533`(2026-10-24). S1 보고서 `docs/qf15-s1-report.md` 「못 한 것」에 남은 일곱 자리 — S1 지시서의 수정 파일 목록 밖이라 고치지 않았고, 수락 시험이 근거를 적어 제외했던 자리다.
기준 문서(**정답지**): S1 지시서 전부(규칙 · 금지 패턴 · 「바꾸지 않는 것」 그대로 이어 씀) · 사이트 `PRD.md` **Q:F-15** · `DESIGN.md` **5장 낱말 표**(Q:F-15 줄 일곱) · 「브랜드 이름 3층」 · 2.31(대시보드 — 2026-10-25 문서 먼저 고침) · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 수락 시험만).
한 줄로: S1 과 같은 규칙으로 **사람이 보는 글자만** 남은 일곱 자리에서 맞춘다. 동작 · 창구 · 판정 · 저장 · 주소 · 기술 식별자 · 상태 코드는 하나도 바꾸지 않는다.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf15s1b-20261025`**(브랜치 `feat/qf15-s1b-words` — 메인이 main 에서 만든다) · 결과 **`/home/changkwon/qaredi-qf15s1b-results-20261025/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --port 8768 --suite brand_words_browser.py --output <결과>/accept` — 시작 전 `ss -ltn` 로 8768 · 8769 가 비었는지(쓰이면 8778) · 끊으면 내 것만 치움 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-15 S1b: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0(메인이 한다) |
| 줄 번호 | 아래 표의 줄 번호는 2026-10-25 main(`257b1d4`) 기준 **참고**다. 고칠 자리는 **지금 글자**로 찾는다(2026-10-25 메인이 일곱 자리 모두 존재를 확인) |

```
목표        : S1 에서 수정 파일 목록 밖이라 남은 일곱 자리 — 전체 화면 메신저 · 모아 홈 대시보드 · 모아 홈 「게시판 새 글」 묶음과 읽을거리 ·
              메시지 AI 도구 · 관리자 AI 설정 · 개인정보/약관 화면 · 업무 준비 중 화면 — 의 사람이 보는 글자를 아래 「바꿀 글자 표」대로 맞추고,
              개인정보/약관 화면과 업무 준비 중 화면에 「모아.」 파비콘을 단다. S1 수락 시험의 제외 셋을 풀어 이 자리들도 시험한다.
요구사항    : S1 지시서(규칙 · 금지 패턴 · 「바꾸지 않는 것」) · 사이트 PRD Q:F-15 ① · DESIGN 5장 낱말 표(Q:F-15 줄) · 「브랜드 이름 3층」 · 2.31 · 6장 — 읽고 시작할 것.
              새 클래스 0 · 새 색값 0 · 새 자산 파일 0(파비콘은 S1 의 public/assets/moa-favicon.svg 그대로).
수정 파일   : src/handlers/member_messages_page.php · public/assets/home-dashboard.js · public/assets/moa.js · public/assets/member-assist.js ·
              src/views/member_invites.php · src/handlers/legal_page.php · src/community/routes.php(community_not_ready() 몸통의 문자열만) ·
              tests/community/brand_words_browser.py(제외 풀기 · 단언 더하기) · tests/catalog.json(brand_words_browser 항목의 covers · notes 만) ·
              docs/qf15-s1b-report.md(새 · 보고). 그 밖에 0.
              보안 핵심 파일 0(src/community/Doc.php · src/Audit.php · public/index.php · db/** · deploy/** 손대지 않음).
금지 패턴   : 창구 · 판정 · SQL · 저장 · 라우트 · 상태 코드 · 함수 이름 · 쿠키 이름 · 헤더 이름 · 주소 · MCP 도구 이름 · 자산 파일 이름 바꾸기 ·
              community_not_ready() 의 갈래(isApi) · exit · 헤더 줄 바꾸기 · 법적 문서 본문 · 판 · 동의 바꾸기 · innerHTML · 인라인 style ·
              새 색값 · 새 클래스 · 「페이지」→「문서」 바꾸기 · 이야기 공개 범위 낱말 「커뮤니티」 바꾸기 · git add -A ·
              수락 시험의 단언을 약하게 하기(S1 의 제외는 아래 「수락 시험」에 적힌 셋만 푼다 — 나머지 제외 · 단언 그대로).
완료 기준   : brand_words_browser.py(격리) 전부 통과 — S1 의 단언 전부 + 아래 「수락 시험」 더한 것 ·
              정적: scripts/security_lint.sh 0 · php -l(바꾼 PHP 전부) · node --check(바꾼 JS 전부) ·
                    php scripts/community_asset_version.php --check 0(커뮤니티 부품은 안 바꾸지만 확인만) ·
                    php scripts/wiki_check.php 0 · git diff --check
보고 형식   : docs/qf15-s1b-report.md — 변경 파일 / 수락 시험과 결과(수 · 출력 원문 줄) / 기대값을 바꾼 옛 시험(있으면 파일 · 줄 · 전 → 후) /
              표에 없는데 찾아서 고친 글자(있으면) / 못 한 것 / 지시서와 다르게 한 것. 짧게.
```

## 바꿀 글자 표

규칙은 S1 그대로: **사람에게 보이는 자리**(화면 글자 · `title` · `aria-label` · `placeholder` · `<title>` · 서버가 그리는 안내 · 거부 문장)만 바꾼다. 주석 · 변수 이름 · 시험 이름은 그대로 둔다.

| # | 파일(참고 줄) | 지금 | 바꿀 글자 | 근거 |
|---|---|---|---|---|
| 1 | `src/handlers/member_messages_page.php:25` (전체 화면 메신저 `/tbox/messages` 의 「모아 AI」 줄 부제 `<small>`) | 공용 Gemini · 나만의 대화 기록 | 회사 공용 AI · 내 대화는 나만 봐요 | S1 표 D — 떠 있는 창 `messenger_window.php:39` 와 같은 글자 · DESIGN 5장 「사람이 쓰는 AI」 |
| 2a | `public/assets/home-dashboard.js:51` (모아 홈 대시보드 시작 안내 `summary`, `pending` 갈래) | `'내 홈 시작 안내'` | `'모아 홈 시작 안내'` (다른 갈래 `'시작 안내'` 그대로) | DESIGN 5장 「홈은 모아 홈 · 업무 홈 둘뿐 — 「내 홈」 0」 · 2.31 |
| 2b | `public/assets/home-dashboard.js:40` (홈 편집 캔버스 `title`) | `'내 홈 대시보드 편집'` | `'내 대시보드 편집'` | 2.31 h2 「내 대시보드」와 같은 이름 |
| 3a | `public/assets/moa.js:4` (읽을거리 `forest` 본문 마지막 문장) | … 오늘 발견한 작은 초록을 커뮤니티에 남겨 보는 건 어떨까요? | … 오늘 발견한 작은 초록을 우리 공간에 남겨 보는 건 어떨까요? | 「커뮤니티」(앱 이름) 0 · 「우리 공간」= 카페 |
| 3b | `public/assets/moa.js:12` (`get()` 의 오류 문장 둘) | 계정 설정을 마치면 이야기를 볼 수 있어요. · 이야기를 불러오지 못했어요. 커뮤니티에서 다시 확인해 주세요. | 계정 설정을 마치면 게시판 새 글을 볼 수 있어요. · 게시판 새 글을 불러오지 못했어요. 우리 공간에서 다시 확인해 주세요. | DESIGN 5장 「이야기」= 사진 스레드 앱 이름만 · 홈의 게시판 글 묶음은 「게시판 새 글」(S1 표 F — launcher.php 머리 · 첫 글자와 같은 말) |
| 3c | `public/assets/moa.js:13` (`load()` 의 글자 넷) | 이야기를 불러오고 있어요. · 제목 없는 이야기 · 이야기를 펼쳐 보세요. · 아직 이야기가 없어요. 커뮤니티에서 첫 이야기를 남겨 보세요. | 게시판 새 글을 불러오고 있어요. · 제목 없는 글 · 글을 열어 보세요. · 아직 새 글이 없어요. 우리 공간에서 첫 글을 남겨 보세요. | 위와 같음(첫 글자는 launcher.php 의 서버 첫 글자 「게시판 새 글을 불러오고 있어요.」와 같게) |
| 4 | `public/assets/member-assist.js` :111 · :172 (메시지 AI 도구 창 — 결과 출처 줄 · 설정 확인 중 안내) | `'모아 공용 Gemini 결과'` · `'모아 공용 Gemini 설정을 확인하고 있어요…'` | `'모아 AI 결과'` · `'모아 AI 설정을 확인하고 있어요…'` | DESIGN 5장 「사람이 쓰는 AI」 — 「공용 Gemini」 0. **:101 「관리자가 Gemini 키를 등록하고 …」는 그대로**(관리자 설정 안내 — 공급자 이름 허용 자리) |
| 5 | `src/views/member_invites.php:34` (관리자 「AI 대화 사용」 칸 아래 `<small>` — `/tbox/users`) | 모델: Gemini 3.5 Flash | 공급자: Google Gemini | 지금 모델은 3.6 Flash(`MemberAi::MODEL`)라 「3.5」가 틀림 — S1 표 D 「판 번호를 화면에 적지 않는다」 · 설정 화면은 공급자 이름 허용 자리. **:39 이미지 모델 문장 그대로** |
| 6a | `src/handlers/legal_page.php:47` 앞 (개인정보 · 약관 화면 `<head>`) | 파비콘 없음 | `<link rel="icon" type="image/svg+xml" href="<?= Http::url('/assets/moa-favicon.svg') ?>?v=<?= (int) filemtime(__DIR__ . '/../../public/assets/moa-favicon.svg') ?>">` 한 줄(app.css 줄 바로 위 — `member_top.php` 와 같은 꼴) | S1 표 H — 모든 화면 파비콘 |
| 6b | `src/handlers/legal_page.php:52` (머리 `span.moa-logo-note`) | 우리의 이야기가 모이는 곳 | 우리 회사의 일과 자료를 한곳에 모아. | DESIGN 5장 「사이트 한 줄 소개」(S1 `member_top.php` 머리 note 와 같은 글자). `<title>` · h1 · 본문 · 판 · 동의 0 변화 |
| 7a | `src/community/routes.php:573` (`community_not_ready()` 의 창구 갈래 503 문장) | `'커뮤니티는 아직 준비 중입니다'` | `'업무는 아직 준비 중입니다'` (상태 코드 503 · 갈래 그대로) | 「커뮤니티」(앱 이름) 0 — 업무 앱 |
| 7b | `src/community/routes.php:591` · `:592` (같은 함수의 화면 갈래 머리) | h1 `커뮤니티` · 부제 `개인 업무 공간` | h1 `업무` · 부제 `작업 · 프로젝트 · 문서` | `<title>모아 업무</title>`(S1) · 셸 `$shellHereDefaults['community']` 의 이름 · 설명과 같은 글자 |
| 7c | `src/community/routes.php:587` 다음 (같은 함수의 `<head>`) | 파비콘 없음 | `. '<link rel="icon" type="image/svg+xml" href="' . htmlspecialchars(Http::url('/assets/moa-favicon.svg'), ENT_QUOTES, 'UTF-8') . '?v=' . (int) filemtime(__DIR__ . '/../../public/assets/moa-favicon.svg') . '">'` 한 줄(`src/site/not_found_page.php:63` 과 같은 꼴) | S1 표 H |

`moa.js:14` 「아직 열린 게시판이 없어요. 내 공간에서 기록을 시작해 보세요.」는 그대로다(「내 공간」은 업무의 개인 공간 — 낱말 표에 맞는 말).

## 바꾸지 않는 것

S1 「바꾸지 않는 것」 전부 그대로(「페이지」 0 변화 · 이야기 공개 범위 「커뮤니티」 · 감사 로그 문장 · 관리자 위키 · 옛 디자인 스튜디오 · 지난 발행 기록 · 법적 문서 본문 · 기술 식별자 · 잠든 AI 지시문). 더해서 —

- **이 지시서에 넣지 않은 남은 글자(2026-10-25 메인이 찾음 — 사용자 판단 대기)**: ① `src/community/Doc.php:1350` 손님 계정 「시작하기」 문서의 「… 커뮤니티에서 읽기만 할 수 있습니다」(보안 핵심 파일 · 새로 만드는 문서에만 쓰임) ② `src/MemberAi.php:233` 모아 AI 지시문 「당신은 모아 커뮤니티의 AI 대화 도우미입니다」(AI 답에 옛 이름이 섞일 수 있음 — 화면 글자가 아니라 AI 동작 문장) ③ `src/handlers/member_ai_settings.php:33` 감사 기록 대상 이름 `'Gemini 3.5 Flash'`(감사 데이터) ④ `public/assets/member-assist.js:101` 「관리자가 Gemini 키를 등록하고 …」(허용 자리 — 그대로가 맞음).
- `community_not_ready()` 는 이름 · 갈래 · 헤더 · `exit` 그대로 — 문자열 셋과 `<link>` 한 줄만.
- `home-dashboard.js` 의 저장 형식 · `saved.guide` 값 · 위젯 종류 · 편집 동작 0 변화.

## 수락 시험 — `tests/community/brand_words_browser.py` 고침(S1 의 것 · 격리 · claude_ 계정 · UA `tbox-review/<날짜>`)

```
[제외 풀기] S1 이 근거를 적어 뺀 셋을 푼다 —
  EXCLUDE['home'] (.dashboard-guide > summary — home-dashboard.js:51) 삭제 ·
  EXCLUDE['messages'] (#message-ai > small — member_messages_page.php:25) 삭제 ·
  LEGAL_NO_ICON 를 빈 집합으로(개인정보 · 약관 화면도 link[rel=icon] → /tbox/assets/moa-favicon.svg 단언).
  나머지 제외(이야기 작성 창 · 공개 범위 고르개 · 공개 범위 표시 · 법적 문서 본문)는 그대로.
[static] 더하기
  ⑥ moa.js · member-assist.js · home-dashboard.js · member_messages_page.php · member_invites.php · legal_page.php 의 문자열과
     routes.php 의 community_not_ready() 몸통에 「커뮤니티」 「공용 Gemini」 「Gemini 3.5」 「내 홈」 「이야기를 불러오」 「제목 없는 이야기」
     「첫 이야기」 「우리의 이야기가 모이는 곳」 「개인 업무 공간」 0(주석 제외) · 위 표의 「바꿀 글자」가 각 파일에 있다
  ⑦ community_not_ready() 몸통: 「'<h1 class="page-head__title">업무</h1>'」 · moa-favicon.svg <link> 한 줄 · http_response_code(503) ·
     Http::error(503, …) · exit 줄이 있다(갈래 무변경 — git diff 로 그 함수의 바뀐 줄이 문자열 셋 + <link> 한 줄뿐)
[pages] 더하기 (1280 · 412 × 밝게 · 어둡게 — S1 과 같은 틀)
  /tbox/users(관리자): 「공급자: Google Gemini」 있음 · 「Gemini 3.5」 0
  /tbox/messages: 「모아 AI」 줄 부제 「회사 공용 AI · 내 대화는 나만 봐요」 있음
  / (직원 · 대시보드 펼침): 시작 안내 summary 에 「내 홈」 0(시작 안내가 보이는 상태를 만들 수 있으면 「모아 홈 시작 안내」 있음) ·
     「홈 편집」을 눌러 편집 캔버스를 연 뒤 그 제목에 「내 대시보드 편집」 있음 · 「내 홈」 0 · 닫기(저장 0)
  / (직원 · 「게시판 새 글」 묶음 펼침 — 게시판 글이 있을 때 · 없을 때 둘): 상태 · 카드 글자에 「이야기」 「커뮤니티」 0
  /tbox/privacy · /tbox/terms: link[rel=icon] 단언(위 「제외 풀기」) · 머리 note 「우리 회사의 일과 자료를 한곳에 모아.」
  업무 준비 중 화면은 격리 틀에서 띄우기 어렵다 — [static] ⑦ 로 갈음하고 보고서에 그렇게 적는다
  넘침 0 · 콘솔 오류 0 · 대비 4.5(바뀐 글자 자리) — S1 과 같은 단언
catalog.json: community.brand_words_browser 의 covers 에 이번 파일 일곱을 더하고 notes 에 「S1b — 제외 셋 풂」 한 구절. 다른 항목 0.
```

## 기대값만 바꿀 옛 시험

2026-10-25 main(`257b1d4`)에서 이 일곱 자리의 지금 글자를 단언하는 옛 시험을 grep 으로 찾았더니 **없다**(`tests/dbg_s2_security.sh:354` · `tests/q_launcher.sh:188` 의 「개인 업무 공간」은 로그인 전 첫 화면에 **없음**을 보는 단언이라 그대로 맞다 · `tests/community/stage3.sh:49` 는 주석). 구현 중 grep 으로 더 찾으면 S1 처럼 **그 줄만** 고치고 보고서에 적는다 — 그 시험은 돌리지 않는다(밤 회귀).

## 되돌릴 수 없는 것

없음 — OS 에 등록되는 이름(OTP 발급자 · 패스키 표시 이름 · 설치 앱 이름)을 바꾸지 않는다. 바뀌는 것은 화면 글자와 파비콘뿐이다.

## 발행 (메인)

patch 한 번 — 제목 「모아 이름 정리 마무리」 · 변경 문장(사용자 말): ① 「메신저 전체 화면 · 홈 대시보드 · 약관 화면 등에 남아 있던 옛 이름과 말을 마저 정리했어요」.
반영 · 회귀는 S1 과 같은 차례(백업 DB + 코드만 · 모의 반영 · 드리프트 확인 · 운영 확인 한 번 · 비례 회귀는 밤 대기열 — 후보: `tbox.messenger_window_browser`(메신저 · 메시지 AI 도구) · `community.home_h4_browser`(법적 문서 머리 · 바닥 · 런처). `tests/home_dashboard.py` 계열은 카탈로그 밖 · `community.stage3`(업무 준비 중 갈래)는 known_fail · 운영 대상이라 밤 회귀에서 빠진다 — 업무 준비 중 화면은 수락 [static] ⑦ 이 유일한 증거).
