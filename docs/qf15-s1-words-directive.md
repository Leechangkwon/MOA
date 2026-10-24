# 지시서 Q:F-15 S1 — 낱말 · 브랜드 맞추기 (2026-10-24)

사용자(2026-10-24): 「여러 기능이 섞여 정체성을 잃었다 — 사람들에게 '이게 뭐야?' 소리를 듣지 않게」 → 기획 `../docs/identity-plan-2026-10-24.md` v1.0 → 「기획안의 권고대로 D-0~D-12 모두 진행해. 1단계 낱말·브랜드 맞추기부터 지시서 써 줘」.
기준 문서(**정답지**): 사이트 `PRD.md` **Q:F-15** · 기획 7.3 「브랜드 규칙」 · 부록 A · `DESIGN.md` **5장 낱말 표**(Q:F-15 줄 일곱) · 5장 「브랜드 이름 3층」 문단 · 2.33(워드마크 D 갈래 색) · 6장 체크리스트 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1 — 수락 시험만).
한 줄로: **사람이 보는 글자만** 「모아」 하나로 맞춘다. 동작 · 창구 · 판정 · 저장 · 주소 · 기술 식별자는 하나도 바꾸지 않는다.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf15s1-20261024`**(브랜치 `feat/qf15-s1-words` — 메인이 만든다) · 결과 **`/home/changkwon/qaredi-qf15s1-results-20261024/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --port 8768 --suite brand_words_browser.py --output <결과>/accept` — 시작 전 `ss -ltn` 로 8768 · 8769 가 비었는지(쓰이면 8778) · 끊으면 내 것만 치움 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-15 S1: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0(메인이 한다) |
| 줄 번호 | 아래 표의 줄 번호는 2026-10-24 main(`5d8364a`) 기준 **참고**다. 고칠 자리는 **지금 글자**로 찾는다 |

```
목표        : 화면 · 창 제목 · 매니페스트 · OS 창(패스키 · OTP 앱) · 푸시 · 공개 페이지 · 공유 제목 · 서버 안내 문장에서
              옛 이름(Qaredi · t-box · 커뮤니티 · AI 비서 변형)과 섞인 슬로건 · 영어 머리글을 걷어 내고, 아래 「바꿀 글자 표」대로 맞춘다.
              파비콘 · 설치 아이콘을 「모아.」 보라 + 노란 점 한 벌로 바꾼다. 옛 사용 안내 글과 죽은 선택자 8 을 고친다.
요구사항    : 사이트 PRD Q:F-15 ① · DESIGN 5장 낱말 표(Q:F-15 줄) · 「브랜드 이름 3층」 · 2.33 D 갈래 · 6장 — 읽고 시작할 것.
              새 클래스 0 · 새 색값 0(아이콘 바탕 #6d28d9 · 점 #f59e0b 는 2.33 의 --wm-d · --wm-d-dot 값 그대로).
수정 파일   : 아래 「바꿀 글자 표」 A~H 에 적힌 파일만. 그 밖에 —
              새 파일: public/assets/moa-favicon.svg · scripts/brand_icons.php(아이콘 PNG 를 만드는 개발 도구 — 운영에서 돌지 않음) ·
                       tests/community/brand_words_browser.py(새 수락) · docs/qf15-s1-report.md(보고)
              바꾸는 자산: public/assets/qaredi-icon-192.png · qaredi-icon-512.png(파일 이름 그대로 — 기술 식별자)
              시험 등록: tests/community/memories_harness.py(choices 에 brand_words_browser.py) · tests/catalog.json(항목 하나)
              바뀐 글자를 단언하던 옛 시험: 「기대값만 바꿀 시험」 표의 그 줄만(다른 단언 0 · 그 시험은 돌리지 않는다 — 밤 회귀)
              보안 핵심 파일은 **src/community/Doc.php 한 줄(시작하기 문서 글자)** 만 — 로직 0.
금지 패턴   : 창구 · 판정 · SQL · 저장 · 라우트 · 쿠키 이름 · 헤더 이름 · 주소(/tbox · /community) · MCP 도구 이름 · 자산 파일 이름 바꾸기 ·
              src/Audit.php · public/index.php · db/** · deploy/** 손대기 · 법적 문서 본문(privacy-*.html · terms-*.html) 손대기 ·
              innerHTML · 인라인 style · 새 색값 · 새 클래스 · 「페이지」→「문서」 바꾸기(별도 단계 — 아래 「바꾸지 않는 것」) · git add -A.
완료 기준   : brand_words_browser.py(격리 · 아래 「수락 시험」) 전부 통과 ·
              정적: scripts/security_lint.sh 0 · php -l(바꾼 PHP 전부) · node --check(바꾼 JS 전부) ·
                    커뮤니티 JS(editor.js · editor-schema.js · templates.js · memories.js)를 바꿨으니 `php scripts/community_asset_version.php` 로 다시 찍은 뒤 `--check` 0 ·
                    php scripts/wiki_check.php 0 · git diff --check
보고 형식   : docs/qf15-s1-report.md — 변경 파일 / 수락 시험과 결과 / 기대값을 바꾼 옛 시험(파일 · 줄 · 전 → 후) / 표에 없는데 찾아서 고친 글자 /
              못 한 것 / DESIGN · 표와 다르게 한 것 / 아이콘 전후 캡처 경로. 짧게.
```

## 바꿀 글자 표

규칙 하나: **사람에게 보이는 자리**(화면 글자 · `title` · `aria-label` · `placeholder` · `<title>` · 매니페스트 · 푸시 · 서버가 그리는 안내·거부 문장 · 도움말)만 바꾼다. 주석 · 변수 이름 · 시험 이름 · 문서는 그대로 둔다.

### A. 이름 — 「모아」 (DESIGN 5장 「사이트·제품 이름」)

| 파일(참고 줄) | 지금 | 바꿀 글자 |
|---|---|---|
| `src/handlers/me_passkeys_options.php:120` (+ 머리 주석 :14) | `'rp' => [..., 'name' => 'Qaredi']` | `'name' => '모아'` — **rp id 는 그대로**(도메인 — 등록된 패스키 유효) |
| `src/Totp.php:35` | `ISSUER = 't-box'` | `ISSUER = '모아'`(URI 는 지금처럼 `rawurlencode` — 새 등록부터 적용) |
| `src/views/mfa_enroll.php:36` | 계정 이름은 `t-box:아이디` | `모아:아이디` |
| `public/assets/totp-generator.html` :8 · :12 · :116 · :119 · :137(placeholder) | t-box 코드 · t-box 2단계 코드 생성기 · t-box 「내 계정」 · `otpauth://totp/t-box:아이디` | 모아 코드 · 모아 2단계 코드 생성기 · 모아 「내 계정」 · `otpauth://totp/모아:아이디`(색 · 동작 그대로) |
| `public/assets/totp-generator.webmanifest` :2 · :3 | t-box 2단계 코드 · t-box 코드 | 모아 2단계 코드 · 모아 코드 |
| `src/handlers/push_manifest.php` :32 · :33 · :34 | Qaredi 경보 · Qaredi 경보 · 관리자 휴대폰으로 Qaredi 경보를 받습니다 | 모아 관리자 경보 · 모아 경보 · 관리자 휴대폰으로 모아 경보를 받습니다 |
| `src/push/alerts-sw.js` :37 · :86 | `'[경보] Qaredi'` · `'[시험] Qaredi 경보 알림이 이 기기에 옵니다'` | `'[경보] 모아'` · `'[시험] 모아 경보 알림이 이 기기에 옵니다'` |
| `src/handlers/push_app_page.php` :36 · :53 · :88 · `src/views/shell_top.php:391`(apple 제목) · `src/help/screens.php:183` | 「Qaredi 경보」 | 「모아 경보」 |
| `src/handlers/login_page.php:110` | `apple-mobile-web-app-title="Qaredi"` | `"모아"` |
| `src/help/screens.php:132` | 「Qaredi」 아이콘이 생깁니다 · 커뮤니티 「…」 | 「모아」 아이콘 · (커뮤니티 → 업무) |
| `src/Legal.php:32` | 모아(Qaredi) 개인정보 처리방침 · 모아(Qaredi) 이용약관 | 모아 개인정보 처리방침 · 모아 이용약관(TITLES 만 — 판 · 본문 · 동의 0 변화. 화면 `legal_page.php` 제목에만 쓰임) |
| `src/community/handlers/pub_view.php` :22 · :24 · `pub_form.php:22` | `· Qaredi` · Qaredi 에서 공개한 페이지 · Qaredi 공개 폼 | `· 모아` · 모아에서 공개한 페이지 · 모아 공개 폼 |
| `public/assets/community/memories.js:703` | 나의 여행 기록 · Qaredi | 나의 여행 기록 · 모아 |
| `src/site/not_found_page.php:62` | 찾을 수 없는 페이지 · Qaredi | 찾을 수 없는 페이지 · 모아 |
| `src/community/routes.php` :587 · :596 · :597(커뮤니티 준비 화면) | 커뮤니티 · Qaredi · t-box 로 가기 · Qaredi 홈 | 모아 업무 · 자료실로 가기 · 모아 홈 |
| `public/assets/community/editor.js` :1832 · :2976 · :6247(탭 제목) | `· 모아 커뮤니티` · `· 커뮤니티` · `· 커뮤니티` | `· 모아 업무` · `· 모아` · `· 모아` |
| `src/community/handlers/memories_page.php:42` | 공유 스레드 · 커뮤니티 | 공유 이야기 · 모아 |
| `src/handlers/member_welcome_page.php:43` | 커뮤니티로 가기 | 업무로 가기 |
| `public/assets/start.js:60` | kind `'커뮤니티'` | `'업무'` |
| `public/assets/community/templates.js:27` | 커뮤니티를 쓰는 모두가 봅니다 (관리자) | 모아를 쓰는 모두가 봅니다 (관리자) |
| `public/assets/member-ai.js:44` | 오늘 커뮤니티에 올릴 … | 오늘 우리 공간에 올릴 … |
| `src/help/screens.php:42` | … 커뮤니티 문서를 함께 쓰세요 | … 업무 문서를 함께 쓰세요 |
| 토큰 범위 글자 — `public/assets/app.js` :7447 · :7448 · :7462 · `public/assets/member.js:35` · `src/handlers/settings_page.php:388` | 커뮤니티 읽기 · 커뮤니티 글쓰기 · 커뮤니티 읽기·쓰기 · 「커뮤니티 글쓰기」를 선택하세요 · 커뮤니티 읽기도 함께 | 업무 문서 읽기 · 업무 문서 쓰기 · 업무 문서 읽기·쓰기 · 「업무 문서 쓰기」를 선택하세요 · 업무 문서 읽기도 함께(범위 **값** `community.read` · `community.write` 그대로) |

### B. 파일 앱 — 「자료실」 (DESIGN 5장 「파일 앱과 그 안의 것」)

| 파일(참고 줄) | 지금 | 바꿀 글자 |
|---|---|---|
| `src/community/handlers/page_view.php` :909 · :915 · `public/assets/community/editor.js` :4723 · :4726 | 내 t-box 파일에서 고르기 · t-box 에 올리기 | 내 자료실 파일에서 고르기 · 자료실에 올리기 |
| `public/assets/community/editor-schema.js` :1244 · :1250(블록 설명) | … t-box 에서 고른 … | … 자료실에서 고른 … |
| `src/help/community.php` :205 · :208 · :225 · :262 | t-box | 자료실(「t-box 에 올리기」 인용은 「자료실에 올리기」) |
| `public/assets/search.js:144` | 출처 `['t-box']` | `['자료실']` |
| `src/handlers/files_zip.php:202` · `public/assets/app.js:4846` | `'t-box-' . date(...)` · `'t-box.zip'` | `'moa-' . date(...)` · `'moa.zip'`(이름 규칙 · 감사 · 헤더 모양 그대로) |
| `src/community/routes.php:3499` · `src/community/handlers/pages_publish.php` :133 · :134 · `pages_visibility.php:243` | … t-box 의 「개인」 파일 … · t-box 에서 그 파일의 등급을 … | … 자료실의 「개인」 파일 … · 자료실에서 그 파일의 등급을 …(문장 나머지 · 코드 · 상태 값 그대로) |
| `public/assets/community/editor.js:7421` | … t-box 관리 화면에서 확인한 뒤 … | … 관리자 페이지에서 확인한 뒤 … |
| **`src/community/Doc.php:1312`(보안 핵심 — 이 문자열 한 줄만)** | 파일은 먼저 t-box 에 올립니다. … t-box 에 올린 파일을 … t-box 에서 자기 권한으로 받습니다 | 파일은 먼저 자료실에 올립니다. … 자료실에 올린 파일을 … 자료실에서 자기 권한으로 받습니다(새로 만드는 「시작하기」 문서에만 — 이미 있는 문서 0) |

### C. 한 줄 소개 — 「우리 회사의 일과 자료를 한곳에 모아.」 (DESIGN 5장 「사이트 한 줄 소개」)

| 파일(참고 줄) | 지금 | 바꿀 글자 |
|---|---|---|
| `src/views/launcher.php:45` `<title>` | 모아 — 업무, 자료, AI 비서를 한곳에서 | 모아 — 우리 회사의 일과 자료를 한곳에 |
| `launcher.php:45` meta description | 할 일을 정리하고, 자료를 공유하고, AI의 도움을 받는 공간. 업무와 일상을 한곳에서 연결하는 모아. | 우리 회사의 일과 자료를 한곳에 모아. 오늘 할 일 · 팀 파일 · 회의록 · 동료와 한마디를 초대받은 동료끼리 계정 하나로. |
| `launcher.php:55` 머리 note(두 곳) | 함께하는 일과 일상 | 우리 회사의 일과 자료를 한곳에 모아. |
| `launcher.php:58` 로그인 전 h1 | 우리 회사의 일과 자료를 한곳에서 | 우리 회사의 일과 자료를 한곳에 모아.(lead 문장 그대로) |
| `launcher.php:63` 도달 불가 갈래의 문구 | 함께 쓰는 도구, 더 간편한 하루 | 그 문구 줄 삭제(갈래 조건 · 마크업 구조 그대로 — 글자만) |
| `launcher.php:64` 동의 전 히어로 | kicker 「함께하는 모든 순간, 모아.」 · h1 「할 일부터 필요한 자료까지, 한곳에 모아.」 · 「회사의 일부터 모임의 활동까지, 자료를 나누고 이야기를 남겨요.」 | kicker 지움 · h1 「우리 회사의 일과 자료를 한곳에 모아.」 · 「오늘 할 일 · 팀 파일 · 회의록 · 한마디를 계정 하나로 해요.」(그 아래 「어디서 시작할지 고민이라면」 줄은 그대로 — 2단계) |
| `src/views/member_top.php:26` 머리 note | 일과 일상을 연결하는 공간 | 우리 회사의 일과 자료를 한곳에 모아. |
| `src/handlers/site_manifest.php:32` description | 모아 — 업무, 자료, AI 비서를 한곳에서 | 우리 회사의 일과 자료를 한곳에 모아. |
| `src/views/moa_footer.php` 한 줄 | 업무 · 자료 · AI 비서 · 이야기 | 우리 회사의 일과 자료를 한곳에 모아. |
| `src/handlers/login_page.php:87` 'home' sub | 업무 · 자료 · AI 비서 · 이야기 | 업무 · 자료실 · 메신저 · 우리 공간 |
| `login_page.php:88` 'tbox' 판 | sub 「사내 파일 공유」 · icon `shield` | sub 「파일 보관 · 찾기 · 공유」(config/apps.json 과 같은 글자) · icon `library`(DESIGN 2.11 「shield 는 보안 설정 뜻에만」) |
| `login_page.php:102` `<title>` | 로그인 · 모아 자료실 · 모아 | 판 이름이 「모아」로 시작하면 꼬리 「· 모아」를 붙이지 않는다 → 「로그인 · 모아 자료실」 · 「로그인 · 모아」 |
| `login_page.php:133` 왼쪽 판 | 할 일과 자료를 정리하고 AI의 도움을 받아 보세요. 모아에서 필요한 도구로 바로 시작하세요. | 우리 회사의 일과 자료를 한곳에 모아. 오늘 할 일 · 팀 파일 · 회의록 · 한마디를 계정 하나로 시작하세요. |

### D. AI 이름 둘 — 「모아 AI」 · 「PC 비서」 (DESIGN 5장 「사람이 쓰는 AI」)

| 파일(참고 줄) | 지금 | 바꿀 글자 |
|---|---|---|
| `src/views/shell_top.php:707` 자료실 사이드바 | AI 비서 업데이트 | PC 비서 받기(자리 · id `nav-programs` 그대로 — 숨기는 것은 2단계) |
| `src/handlers/programs_page.php` :10 · :13 · :24 · `src/handlers/client_diagnostics_admin.php:19` | AI 비서 배포함 · 기존 PC AI 비서 업데이트 · {판} | PC 비서 배포함 · 기존 PC 비서 업데이트 · {판} |
| `src/handlers/files_exec_download.php:149`(거부 문장) | AI 비서 배포함에서 내려받아 주세요. | PC 비서 배포함에서 받아 주세요.(상태 코드 그대로 — 낱말 표 「받기」) |
| `src/views/member_pc_buddy_guide.php:66` · `src/views/member_local_buddy.php:3` | AI 비서 다운로드 · 새 탭 · AI 비서 업데이트 받기 | PC 비서 받기 · 새 탭 · PC 비서 받기 |
| `src/views/launcher.php:24` 바로가기 틀 'assistant' 이름 · `:70` 서비스 카드 h3 | AI 비서 · AI 비서 | PC 비서 · PC 비서(**id `assistant` 그대로** — 저장된 바로가기 구성 · 시험이 id 에 기댄다 · 숨김은 2단계) |
| `src/handlers/settings_page.php:60` | 시작 안내 · OTP · AI 비서 연결 → | 시작 안내 · OTP · PC 비서 연결 → |
| `src/handlers/member_join_page.php:11` · `member_welcome_page.php` :17 · :18 · :30 · :36 | 03 비서 연결 (선택) · 계정 보호부터 비서 연결까지 · 앱과 AI 비서도 원하는 만큼 · 외부 비서 연결 토큰 | 03 PC 비서 연결 (선택) · 계정 보호부터 PC 비서 연결까지 · 앱 설치와 PC 비서도 원하는 만큼 · PC 비서 연결 토큰 |
| `public/assets/member.js` :63 · :94(토큰 용도 문자열) | … 로그인 방법과 비서 연결은 … · `purpose:'tbox-buddy 비서 연결'` | … 로그인 방법과 PC 비서 연결은 … · `purpose:'PC 비서 연결'`(새로 발급하는 토큰부터) |
| `src/views/messenger_window.php:39` 「모아 AI」 줄 부제 | 공용 Gemini · 나만의 대화 기록 | 회사 공용 AI · 내 대화는 나만 봐요 |
| `src/views/member_assist.php:13` 첫 공급자 단추 | Gemini 3.5 Flash · 기본 / 모아에서 바로 처리 | 모아 AI · 기본 / Gemini · 모아에서 바로 처리(지금 모델은 3.6 Flash 라 「3.5」 글자가 틀림 — 판 번호를 화면에 적지 않는다. GPT · ChatGPT 웹 단추 글자 그대로) |

### E. 영어 머리글 → 한국어 (DESIGN 5장 「머리글」)

| 파일(참고 줄) | 지금 | 바꿀 글자 |
|---|---|---|
| `src/views/design_controls.php:7` | YOUR SPACE, YOUR STYLE | 화면 꾸미기 |
| `src/handlers/consent_page.php:35` | TERMS & PRIVACY | 약관 · 개인정보 |
| `src/handlers/member_welcome_page.php:17` | MAKE YOURSELF AT HOME | 시작 안내 |
| `src/handlers/member_join_page.php` :9 · :12 | A PLACE FOR US · WELCOME TO MOA | 초대받은 동료만 · 모아 가입 |
| `src/views/member_pc_buddy_guide.php:2` | PC ASSISTANT | PC 비서 |
| `src/views/member_assist.php:10` | A LITTLE HELP, A BETTER MESSAGE | 모아 AI 로 다듬기 |
| `public/assets/member-ai.js:39` | YOUR LITTLE THINKING PARTNER | 회사 공용 AI |
| `src/handlers/home.php` :156 · :362 | FILE SPACE · FILE ACTIONS | 자료실 · 파일 |
| `src/community/handlers/memories_page.php` :86 · :99 · `travel_plans_panel.php:3` | KEEP THIS MOMENT · MEMORY STUDIO · MY JOURNEY | 새 이야기 · AI 로 꾸미기 · 여행 계획 |
| `src/views/launcher.php` :76 · :78~80 · :82 | A LITTLE INSPIRATION · SLOW MOMENTS · INTO THE GREEN · TAKE A BREATH · OUR CONVERSATIONS | 잠깐 쉬어 가기 · 잠깐의 쉼 · 초록 산책 · 쉬어 가기 · (지움 — 아래 h2 가 이름) |

표에 없더라도 사람 화면의 **영어 대문자 머리글**(`.moa-kicker` 등 머리글 자리에 A–Z 6자 이상)을 찾으면 같은 규칙으로 고치고 보고서 「표에 없는데 고친 글자」에 적는다(옛 디자인 스튜디오 `public/assets/design-preview/` 는 제외).

### F. 낱말 하나에 뜻 하나 — 「홈」 · 「이야기」 · 「우리 공간」 (DESIGN 5장)

| 파일(참고 줄) | 지금 | 바꿀 글자 |
|---|---|---|
| `src/views/shell_top.php:495` 업무 사이드바 `#nav-work-home` 글자 · `data-tip` | 홈 | 업무 홈 |
| `src/views/shell_top.php:467` 카페 사이드바 `#nav-cafe-home` 글자 · `data-tip` | 카페 홈 | 우리 공간 |
| `src/views/shell_top.php:139` 카페 셸 부제 | 카페 · 회사 · 모임 공간 | 자유 · 익명게시판 |
| `src/views/shell_tools.php:52` 앱 판 「모아 홈」 설명 | 이야기와 풍경이 모이는 곳 | 검색과 바로가기 |
| `src/views/launcher.php:55` 동의 전 머리 · `src/views/space_page.php:16` | 내 홈 · 내 홈으로 | 모아 홈 · 모아 홈으로 |
| `launcher.php:65` 검색 칸 placeholder | 문서 · 자료 · 작업 검색 | 작업 · 문서 · 파일 검색(DESIGN 2.31 ② 의 자리 글도 같이 고친다 — 문서 한 줄) |
| `launcher.php:75` 「연락과 시작 안내」 줄 | 동료에게 연락하기 / 메신저에서 대화를 이어가세요. · 오늘 챙길 일 / 내 작업에서 확인하세요. | 메신저 / 동료와 대화를 이어가세요. · 업무 홈 / 오늘 할 일을 확인하세요.(링크 · 동작 그대로) |
| `launcher.php:76` details 제목 | 함께 보는 이야기와 잠깐의 쉼 | 읽을거리 · 게시판 새 글 |
| `launcher.php:82` 피드 제목 · 링크 | 지금, 우리들의 이야기 · 이야기 전체 보기 ↗(→ /memories) | 게시판 새 글 · 우리 공간 열기 ↗(→ `Http::communityUrl('/cafe')`) — 피드가 게시판 글이므로 링크를 게시판 쪽으로 |
| `launcher.php:83` 피드 안내 · 불러오는 중 · 로그인 전 문장 | … 우리 이야기를 볼 수 있어요 · 이야기를 불러오고 있어요. · 우리끼리 나누는 이야기는 여기서. · … 지금 당신의 이야기를 시작해 보세요. | … 게시판 새 글을 볼 수 있어요 · 게시판 새 글을 불러오고 있어요. · 우리끼리 나누는 글은 여기서. · … 지금 모아를 시작해 보세요. |
| `launcher.php:87` 읽을거리 창 링크 | 나의 이야기도 나누기 ↗ | 이야기 앱에 남기기 ↗ |
| `src/views/moa_usage.php:13` | 01 · 이야기 나누기 | 01 · 게시판에 글 올리기 |

### G. 옛 사용 안내 글 · 죽은 선택자 (`public/assets/usage-guide.js`)

| 참고 줄 | 지금 | 바꿀 것 |
|---|---|---|
| :23 overview 「업무 홈」 | … 이야기와 자료실은 왼쪽 아이콘 줄이나 앱 이름 메뉴에서 옮겨 가요. | … 자료실 · 메신저 · 우리 공간은 오른쪽 위 「앱」에서 옮겨 가요. |
| :24 「내 작업 요약」 | 기한이 지난 작업 · 오늘 · 이번 주 작업을 세 칸에 모아요. … | 중요한 순서로 모은 내 작업이에요. 동그라미를 누르면 완료, 이름을 누르면 상세가 열려요. |
| :54 · :59 · :69 · :73 · :87 · :97 · :109 선택자 | `#community-page` · `#community-editor` · `#settings-passkeys` · `#welcome-install` · `#memory-detail-dialog` · `#post-detail-dialog` · `#travel-plans-dialog` · `#my-tasks-groups` | 없는 대안을 지운다(남는 대안: `.page-head` · `.ProseMirror` · `#welcome-security,#passkey-panel` · `#member-install` · `#detail-dialog,#studio-dialog .dialog-head` · `#travel-dialog` · `#my-tasks-list .task-row__title`) |
| :72 account 단계 제목 | AI 비서는 나중에 해도 좋아요 | PC 비서는 나중에 해도 좋아요 |
| :83 messenger 단계 | … 내 PC의 비서 연결도 전체 화면에서 … | … PC 비서 연결도 전체 화면에서 … |
| :117 · :118 `modalContexts` | `memory-detail-dialog` · `post-detail-dialog` · `travel-plans-dialog` | 세 줄 지움(없는 창) |
| :266 첫 제안 창 본문 | 일상 기록뿐 아니라 문서·게시판·자료실·메신저도 사용할 수 있어요. 화면의 버튼을 … | 할 일 · 파일 · 문서 · 메신저를 화면의 버튼을 강조하며 차근차근 안내해 드릴게요. 언제든 건너뛰거나 오른쪽 아래 「? 사용 안내」에서 다시 볼 수 있어요. |

투어 맥락 · 단계 수 · 저장 형식은 그대로다(맥락 이름 · `/api/me/guide` · 금지 규칙 C-3 불변).

### H. 아이콘 (DESIGN 5장 「브랜드 이름 3층」 · 2.33 D)

| 무엇 | 지금 | 바꿀 것 |
|---|---|---|
| 파비콘 | 셸만 남색 방패 data URI(`shell_top.php:345`) · 런처 · 로그인 · 가입 · 공개 · 없는 주소 화면은 없음 | 새 `public/assets/moa-favicon.svg`(24×24 보기 상자 · 둥근 네모 바탕 `#6d28d9` · 흰 「m」(serif 기울임 — 「m.」 상징 `.moa-symbol` 과 같은 모양) · 노란 점 `#f59e0b` · `<script>` · 외부 참조 0) 하나를 `<link rel="icon" type="image/svg+xml" href="<?= Http::url('/assets/moa-favicon.svg') ?>?v=…">` 로 — `shell_top.php`(방패 줄 대신) · `launcher.php` · `login_page.php` · `member_top.php` · `not_found_page.php` · `pub_view.php` · `pub_form.php` · `memories_page.php`(공유 화면 머리) · 관리자 경보 앱 화면(`shell_top.php:384~391` 갈래)은 지금 아이콘 그대로 |
| 설치 아이콘 `qaredi-icon-192.png` · `qaredi-icon-512.png` | 검정 바탕 흰 3×3 격자 | 보라 `#6d28d9` 꽉 찬 바탕 · 가운데 60% 안에 흰 「m」 + 노란 점(가장자리를 잘라도 남게 — 매니페스트의 `purpose` 값 그대로) · 같은 크기 · 같은 파일 이름 · RGB PNG |
| 만드는 법 | — | `scripts/brand_icons.php`(PHP GD · 글꼴 `/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf` 또는 같은 계열 · 외부 요청 0) 가 두 PNG 를 다시 만든다. 운영에서 돌지 않는 개발 도구 — 머리 주석에 「다시 만들 때 쓰는 법」 한 줄 |
| 「m.」 상징 바탕 | `public/assets/moa.css:17` `.moa-symbol { background: var(--primary); color: var(--primary-fg) }`(테마색) | `background: var(--brand-wordmark); color: var(--brand-wordmark-on)`(DESIGN 2.33 — 고대비는 2.33 고대비 규칙 그대로) |

관리자 경보 앱 아이콘(`alerts-icon-*.png` 방패)과 2단계 코드 생성기의 파랑은 그대로 둔다(DESIGN 5장 「브랜드 이름 3층」).

## 바꾸지 않는 것 (이 단계 밖 — 손대지 않는다)

- 「페이지 → 문서」: 업무 도움말에 「페이지」가 147번이고 화면 단추도 「＋ 페이지」 「하위 페이지 만들기」 「공유받은 페이지」 「최근 본 페이지」다. 단추 · 메뉴 · 도움말 · 시험을 함께 바꾸는 별도 단계라 **이 단계에서는 「페이지」를 한 글자도 바꾸지 않는다**(통합 검색 창 「찾기 — 페이지·댓글·파일」 · 결과 묶음 「페이지」 포함).
- 이야기 공개 범위 고르개의 「커뮤니티」(`memories.js:23` 등 — 이야기 앱 안의 뜻 낱말) · 「메신저 이용 안내」(손님 단추 — 뜻이 맞음).
- 감사 로그 문장(`src/Audit.php` — 보안 핵심) · 관리자 위키(`wiki/`) · 옛 디자인 스튜디오(`public/assets/design-preview/`) · 지난 발행 기록(`CHANGELOG.*`) · 법적 문서 본문.
- 기술 식별자: 쿠키 `qaredi_*` · 헤더 `X-Qaredi-*` · 전역 `QarediPasskey` · 경로 `/tbox` `/community` · MCP 서버 · 도구 이름(`qaredi-remote-mcp` · `qaredi_*`) · 자산 파일 이름 · DB · 감사 행동 코드 · 토큰 범위 값.
- 잠든 코드의 AI 지시문(`src/community/Messenger.php:68` — GPT 창구 휴면) · 구조(바로가기 수 · 순서 · 숨김 · 앱 판 · 홈 카드 — 2단계).

## 수락 시험 — `tests/community/brand_words_browser.py`(새 · 격리 · claude_ 계정 · UA `tbox-review/<날짜>`)

```
[static]  (파일만 읽음 · 서버 0)
  ① php -r 로 Totp::otpauthUri('claude_employee01', <시험 키>) — issuer 가 모아(%EB%AA%A8%EC%95%84) · 라벨 「모아:아이디」 · 아이디 32자로 Qr 생성 예외 0
  ② me_passkeys_options.php 의 rp name 「모아」 · rp id 줄 무변경(git diff 로 그 줄 0)
  ③ push_manifest · alerts-sw.js · totp-generator.html · .webmanifest · site_manifest · Legal::TITLES · search.js 출처 · files_zip 이름 · app.js 기본 이름에 Qaredi · t-box 0
  ④ usage-guide.js 의 모든 step 선택자 · modalContexts 키의 id 가 src · public(usage-guide.js 제외)에 하나 이상 있다(죽은 선택자 0) · 「왼쪽 아이콘 줄」 「세 칸에 모아요」 「일상 기록뿐 아니라」 0
  ⑤ qaredi-icon-192/512.png 크기 그대로 · 네 귀퉁이 픽셀 ≈ #6d28d9(±8) · moa-favicon.svg 에 script · href · 외부 주소 0
[pages]   (php -S 격리 · 1280 · 412 × 밝게 · 어둡게 · 직원 · 관리자 · 손님 · 로그인 전)
  화면: / (로그인 전 · 동의 전 · 로그인 뒤) · /tbox/login · /tbox/join(초대 없음 화면) · /tbox/request-invite · /tbox/consent · /tbox/privacy · /tbox/terms ·
        /tbox/ · /tbox/settings · /tbox/welcome · /tbox/welcome?section=pc-assistant · /tbox/programs · /tbox/messages · /tbox/alerts-app(관리자) · 없는 주소 ·
        /community/ · /community/my-tasks · /community/workspace · /community/cafe · 게시판 /community/b/{n} · 문서 /community/p/{id}(편집 모드 파일 블록 창 포함) · /community/memories
  각 화면의 document.title · body innerText · [aria-label] · [placeholder] · [data-tip] 에
    「Qaredi」 「t-box」 「티박스」 「AI 비서」 「공용 Gemini」 「함께하는 일과 일상」 「일과 일상을 연결」 「업무, 자료, AI 비서」 「함께하는 모든 순간」
    「오늘 챙길 일」 「카페 홈」 「내 홈」 「동료에게 연락하기」 「우리들의 이야기」 「함께 보는 이야기」 「Gemini 3.5」 0 ·
    「커뮤니티」 0(단 /community/memories 의 공개 범위 고르개 · 작성 창은 제외 — 이 단계 밖) ·
    머리글 자리(.moa-kicker · 머리글 클래스 · h1/h2 바로 앞 짧은 줄)에 영어 대문자 6자 이상 0
  있어야 할 것: 업무 사이드바 「업무 홈」 · 카페 사이드바 「우리 공간」 · 앱 판 「모아 홈 — 검색과 바로가기」 · 자료실 사이드바 「PC 비서 받기」 ·
    메신저 「모아 AI」 줄 부제 「회사 공용 AI · 내 대화는 나만 봐요」 · 로그인 화면 title 「로그인 · 모아 자료실」 · 로그인 전 h1 「우리 회사의 일과 자료를 한곳에 모아.」 ·
    모든 화면 link[rel=icon] → /tbox/assets/moa-favicon.svg(200 · image/svg+xml) · /tbox/app.webmanifest description 한 줄 소개 · 경보 매니페스트 이름 「모아 관리자 경보」
  넘침 0 · 콘솔 오류 0 · 대비 4.5(바뀐 글자 자리) · 스크린샷(로그인 전 1280 · 로그인 화면 412 다크 · 자료실 사이드바 1280 · 설치 아이콘 두 장 전후)
```

## 기대값만 바꿀 옛 시험 (그 줄만 · 돌리지 않는다 — 메인이 밤 회귀 대기열에 넣는다)

| 시험 | 줄(참고) | 전 → 후 |
|---|---|---|
| `tests/f46.sh` | :121 · :122 | t-box 2단계 코드 생성기 → 모아 2단계 코드 생성기 |
| `tests/f70.sh` | :1291 | `[경보] Qaredi` → `[경보] 모아` |
| `tests/qf3c_zip.sh` · `tests/browser/qf3c_zip.py` | :345 · :207 · :259 | `t-box-\d{8}-\d{6}\.zip` → `moa-\d{8}-\d{6}\.zip` |
| `tests/qf13_consent_http.py` | :711 | 모아(Qaredi) 개인정보 처리방침 → 모아 개인정보 처리방침 |
| `tests/community/home_h1_browser.py` | :233 | 문서 · 자료 · 작업 검색 → 작업 · 문서 · 파일 검색 |
| `tests/community/invite_request_browser.py` | :274 · :275 | 우리 회사의 일과 자료를 한곳에서 → 우리 회사의 일과 자료를 한곳에 모아. |
| `tests/community/qf12_shell_browser.py` | :607 | 업무 사이드바 top 의 '홈' → '업무 홈' |
| `tests/compact_home_ui.py`(카탈로그 밖) | :83 | 'AI 비서' → 'PC 비서' |
| `tests/dbg_s2_security.sh` | :360 | `>Qaredi</h1>` — 지금 main 에서도 그 글자를 그리는 화면이 없어 이미 어긋난 단언으로 보인다(추정). 고치지 말고 「원래 깨짐」으로 보고 |

이 밖에 grep 으로 찾은 단언이 있으면 같은 방식으로(그 줄만) 고치고 보고서에 더한다. 주석 · 설명 글 · `hr` · 시험 이름 속 옛 이름은 그대로 둔다.

## 되돌릴 수 없는 것 (보고서 「못 한 것」 옆에 그대로 적는다)

- 인증 앱에 이미 등록된 OTP 항목은 이름이 「t-box」로 남는다(새 등록부터 「모아」). 다시 등록할 필요는 없다.
- 이미 등록한 패스키는 휴대폰 화면에 「Qaredi」로 남을 수 있다(로그인은 그대로 된다 — rp id 불변).
- 이미 설치한 홈 화면 앱 · 경보 앱의 이름과 아이콘은 다시 설치해야 바뀐다(브라우저마다 다름).

## 발행 (메인)

minor 한 번 — 제목 「모아 이름과 말 정리」 · 변경 문장(사용자 말): ① 「화면 곳곳에 남아 있던 옛 이름을 「모아」 하나로 맞췄어요」 ② 「파일 앱은 어디서나 「자료실」, PC 프로그램은 「PC 비서」로 불러요」 ③ 「브라우저 탭과 홈 화면 아이콘이 보라색 모아 표시로 바뀌었어요 — 이미 설치한 앱은 다시 설치하면 바뀌어요」.
