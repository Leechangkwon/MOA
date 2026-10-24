# 보고 Q:F-15 S1 — 낱말 · 브랜드 맞추기 (2026-10-24 · 구현 Opus)

지시서 `docs/qf15-s1-words-directive.md` 그대로. 자리: worktree `/home/changkwon/qaredi-qf15s1-20261024`(브랜치 `feat/qf15-s1-words` · main `d9998c4` 기준) · 결과 `/home/changkwon/qaredi-qf15s1-results-20261024/`. 운영 트리 · 운영 DB · 운영 HTTP 읽기 · 쓰기 0. 시험 계정은 fixture 의 `claude_` 다섯 · UA `tbox-review/2026-10-24`.

## 변경 파일

- **표 A~H 파일**(글자만 · 로직 0): `src/Totp.php`(ISSUER 한 줄) · `src/Legal.php`(TITLES) · `src/community/Doc.php`(**1312 한 줄만**) · `src/community/routes.php`(587 · 596 · 597 · 3499 문자열만) · `src/push/alerts-sw.js` · `src/help/screens.php` · `src/help/community.php` · `src/site/not_found_page.php` ·
  `src/handlers/` me_passkeys_options(rp `name` + 머리 주석 · rp id 줄 0) · push_manifest · push_app_page · login_page · site_manifest · files_zip · files_exec_download · settings_page · programs_page · client_diagnostics_admin · member_join_page · member_welcome_page · consent_page · home ·
  `src/views/` launcher · shell_top · shell_tools · member_top · moa_footer · mfa_enroll · messenger_window · member_assist · member_pc_buddy_guide · member_local_buddy · design_controls · space_page · moa_usage ·
  `src/community/handlers/` page_view · pages_publish · pages_visibility · pub_view · pub_form · memories_page · travel_plans_panel ·
  `public/assets/` app.js · member.js · member-ai.js · start.js · search.js · usage-guide.js · moa.css · totp-generator.html · totp-generator.webmanifest · community/editor.js · editor-schema.js · templates.js · memories.js
- **새 파일 넷**: `public/assets/moa-favicon.svg` · `scripts/brand_icons.php` · `tests/community/brand_words_browser.py` · `docs/qf15-s1-report.md`
- **같은 이름으로 덮어쓴 자산**: `public/assets/qaredi-icon-192.png` · `qaredi-icon-512.png`(`php scripts/brand_icons.php` 로 만듦 · RGB · 크기 그대로)
- **부품 버전 다시 찍기**(`php scripts/community_asset_version.php`)로 import 의 `?v=` 만 바뀐 파일: community/dashboard.js · database.js · suggestions.js · table-editor.js · work.js · `messenger.js`
- **시험 등록**: `tests/community/memories_harness.py`(`--suite` choices) · `tests/catalog.json`(`community.brand_words_browser` 하나)
- **표 E 끝 문장으로 고친 표 밖 파일 하나**: `src/views/member_invites.php`(영어 머리글 한 줄)

## 수락 시험과 결과

`python3 tests/community/memories_harness.py --port 8768 --suite brand_words_browser.py --output /home/changkwon/qaredi-qf15s1-results-20261024/accept` (시작 전 `ss -ltn` 8768 · 8769 빔)
→ **`RESULT pass=1046 fail=0`** · `OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1` (원문 `accept/run.log` · 단언 `accept/brand-words-results.json` · 측정값 `accept/brand-words-metrics.json`)

| 묶음 | 수 | 내용 |
|---|---|---|
| 준비 | 8 | 합성 계정 공간 · 카페 이주 · 게시판 글 · 문서 |
| [static] | 11 | ① issuer `%EB%AA%A8%EC%95%84` · 라벨 「모아:claude_employee01」 · 32자 아이디 QR(판 9) ② rp name 「모아」 · rp id 식 무변경(git diff · name 값만 2−/2+) ③ 매니페스트 · SW · 코드 생성기 · TITLES · 검색 출처 · zip 이름 Qaredi · t-box 0 ④ 사용 안내 id 76 · 죽은 선택자 0 · 옛 문장 0 ⑤ PNG 둘 RGB · 귀퉁이 #6d28d9 · 파비콘 script · href · 외부 주소 0 · 색 셋(#6d28d9 · #f59e0b · #ffffff) |
| [must] | 52 | 파비콘 200 image/svg+xml · app.webmanifest 한 줄 소개 · 경보 매니페스트 「모아 관리자 경보」 · 「업무 홈」 · 「우리 공간」 · 「모아 홈 — 검색과 바로가기」 · 「PC 비서 받기」 · 메신저 부제 · 로그인 title 둘 · 「모아 자료실」 판 library 아이콘 · 로그인 전 h1 |
| [pages] | 838 | 화면 34(직원 · 관리자 · 손님 · 로그인 전 · 동의 전) × 1280 · 412 × 밝게 · 어둡게 — 금지 낱말 0 · 영어 대문자 머리글 0 · 파비콘 링크 · 넘침 0 · 콘솔 0 · HTTP 상태(런처는 `<details>` 를 모두 펼쳐 한 번 더 · 문서는 편집 모드 「파일」 블록 창) |
| [contrast] | 136 | 바뀐 글자 자리 ≥ 4.5(로그인 왼쪽 판 사진 바탕 등은 값만 기록) |
| [shots] | 1 | 설치 아이콘 전후 · 파비콘 |

시험에서 뺀 자리(근거는 시험 파일 `EXCLUDE` · `LEGAL_NO_ICON`): 이야기 작성 창 · 공개 범위 고르개 · 공개 범위 표시(지시서 「바꾸지 않는 것」) · 법적 문서 본문 · `/tbox/messages` 의 「모아 AI」 부제(member_messages_page.php — 수정 파일 밖) · 모아 홈 대시보드 「내 홈 시작 안내」(home-dashboard.js — 수정 파일 밖) · 법적 문서 화면의 파비콘(legal_page.php — 표 H 목록 밖).

정적: `scripts/security_lint.sh` 결과 「통과 (금지 패턴 0건)」 · `php -l` 바꾼 · 새 PHP 43개 오류 0 · `node --check` 바꾼 JS 17개 오류 0 · `community_asset_version.php --check` rc 0 · `wiki_check.php` 「통과 (구조 문제 0건 · 빠진 글 0건)」 · `git diff --check` 0.

## 기대값을 바꾼 옛 시험 (그 줄만 · 돌리지 않음 — 밤 회귀)

| 파일 · 줄 | 전 → 후 |
|---|---|
| tests/f46.sh:121 · :122 | 기대값 `t-box 2단계 코드 생성기` → `모아 2단계 코드 생성기`(시험 이름 속 옛 이름은 그대로) |
| tests/f70.sh:1291 | `[경보] Qaredi` → `[경보] 모아` |
| tests/qf3c_zip.sh:345 · tests/browser/qf3c_zip.py:207 · :259 | `t-box-\d{8}-\d{6}\.zip` → `moa-\d{8}-\d{6}\.zip`(정규식만) |
| tests/qf13_consent_http.py:711 | `모아(Qaredi) 개인정보 처리방침` → `모아 개인정보 처리방침` |
| tests/community/home_h1_browser.py:233 | `문서 · 자료 · 작업 검색` → `작업 · 문서 · 파일 검색` |
| tests/community/invite_request_browser.py:274 · :275 | `우리 회사의 일과 자료를 한곳에서` → `…한곳에 모아.` |
| tests/community/qf12_shell_browser.py:607 | 업무 사이드바 `'홈'` → `'업무 홈'` |
| tests/compact_home_ui.py:83 | `'AI 비서'` → `'PC 비서'` |
| (grep 으로 더) tests/browser/dbg_u3_ui.py:224 · :225 | 서버 거부 문장 `t-box 에서 그 파일의 등급을` → `자료실에서 그 파일의 등급을` |
| (grep) tests/neutral_home_entry.py:76 · tests/community/home_h1_browser.py:567 | `동의하고 내 홈 열기` → `동의하고 모아 홈 열기` |
| (grep) tests/community/invite_request_browser.py:286 | 셋째 걸음 `내 홈에서 시작` → `모아 홈에서 시작` |
| (grep) tests/ux_browser.py:116 | `['커뮤니티','t-box']` → `['업무','자료실']` — `'t-box'` 쪽은 이 단계 전부터 어긋나 있던 값(start.js 가 이미 「자료실」) · 같은 줄이라 함께 |
| (독립 검토 1회차) tests/community/cafe_c4_browser.py:727 | 업무 사이드바 `st['home'] == '홈'` → `st['home'] == '업무 홈'`(표 F) |
| (독립 검토 1회차) tests/community/qf4_onboarding.sh:84 · :87 · :88 | 「시작하기」 본문 good 목록 `"t-box"` → `"자료실"` · 단언 설명과 grep 의 `t-box` → `자료실`(「붙」 검사 그대로 · 표 B Doc.php) |
| tests/dbg_s2_security.sh:360 | **고치지 않음 — 원래 깨짐**(`>Qaredi</h1>` 를 그리는 화면이 main 에도 없음) |

## 표에 없는데 찾아서 고친 글자 (표에 적힌 파일 안 · DESIGN 5장 Q:F-15 낱말 표 · 수락 시험 금지 낱말)

- launcher.php — 로그인 전 「이야기(사진 · 여행 기록) · AI 비서(PC 연결)도 함께 써요」 → 「… · PC 비서도 함께 써요」 · 셋째 걸음 「내 홈에서 시작」 → 「모아 홈에서 시작」 · 동의 전 단추 「동의하고 내 홈 열기」 → 「동의하고 모아 홈 열기」 · 검색 칸의 aria-label 둘(form · input)도 placeholder 와 같은 「작업 · 문서 · 파일 검색」
- member_assist.php:37 「모아 공용 Gemini 또는 ChatGPT 웹」 → 「모아 AI 또는 ChatGPT 웹」
- member-ai.js 말풍선 머리 「✦ 모아 AI · Gemini 3.5」 → 「✦ 모아 AI」 · 대화 머리 부제 「Gemini 3.5 Flash · 내 계정의 AI 대화」 → 「회사 공용 AI · 내 대화는 나만 봐요」(표 D 「판 번호를 화면에 적지 않는다」)
- usage-guide.js 메신저 단계 「모아 AI는 공용 Gemini로 답하고」 → 「회사 공용 AI로 답하고」(Google 전송 고지 문장 그대로) · help/community.php 같은 문장
- memories_page.php aria-label 「커뮤니티 스레드」 둘 → 「이야기 스레드」
- totp-generator.html 칸 이름 「t-box 주소(예: …)」 · 오류 문장 「… t-box 주소여야 합니다」 → 「모아 주소」(수락 ③)
- usage-guide.js 죽은 대안 id 다섯(표의 8 밖): `#compose-travel-plan` · `#travel-plan-select` · `#travel-plan-new` · `#travel-plan-title` · `#travel-plan-save` 지움(수락 ④ 「모든 step 선택자」)
- (표 E 끝 문장) src/views/member_invites.php:31 관리자 AI 설정 머리글 「MOA × GEMINI」 → 「모아 AI 설정」

## 못 한 것

- **수정 파일 밖이라 남긴 사람 글자**(고치지 않음 — 다음 단계 지시서에 넣을 것): `src/handlers/member_messages_page.php:25` 「공용 Gemini · 나만의 대화 기록」(/tbox/messages 전체 화면 — 표 D 는 떠 있는 창만) · `public/assets/home-dashboard.js:51` 「내 홈 시작 안내」 · :40 「내 홈 대시보드 편집」 · `public/assets/moa.js:4 · :12 · :13` 「… 커뮤니티에 남겨 보는 건」 「이야기를 불러오지 못했어요. 커뮤니티에서 다시 확인해 주세요」 「아직 이야기가 없어요. 커뮤니티에서 첫 이야기를 남겨 보세요」 「이야기를 불러오고 있어요」(게시판 새 글 위젯 — 기본 대시보드에는 안 보임) · `public/assets/member-assist.js:172` 「모아 공용 Gemini 설정을 확인하고 있어요…」 · `src/views/member_invites.php:34` 「모델: Gemini 3.5 Flash」 · `src/handlers/legal_page.php` 파비콘 없음 · 머리 note 「우리의 이야기가 모이는 곳」 · `src/community/routes.php:573 · :591`(준비 중 503 문장 · h1 「커뮤니티」 — 「routes.php 는 표의 문자열만」)
- DESIGN.md 의 옛 문장(2.32 ② 「AI 비서(PC 연결)」 · ③ 「내 홈에서 시작」 · 2.31 「내 홈 시작 안내」)은 문서라 고치지 않음 — 메인 몫
- 옛 시험 · 회귀는 돌리지 않음(지침 3.1 — 밤 회귀 대기열)
- 되돌릴 수 없는 것(지시서 그대로):
  - 인증 앱에 이미 등록된 OTP 항목은 이름이 「t-box」로 남는다(새 등록부터 「모아」). 다시 등록할 필요는 없다.
  - 이미 등록한 패스키는 휴대폰 화면에 「Qaredi」로 남을 수 있다(로그인은 그대로 된다 — rp id 불변).
  - 이미 설치한 홈 화면 앱 · 경보 앱의 이름과 아이콘은 다시 설치해야 바뀐다(브라우저마다 다름).

## DESIGN · 표와 다르게 한 것

- 아이콘 글꼴: 이 서버의 DejaVu Serif 에는 기울임 꼴이 없어 「serif 기울임」을 맞추려고 **Liberation Serif Bold Italic**(없으면 DejaVuSerif-Bold) — 지시서 「또는 같은 계열」
- 파비콘: 바탕은 「m.」 상징과 같은 모양(왼쪽 아래만 작게 둥근 네모 · path) · 흰 글자 `#ffffff`(= 2.33 `--wm-on`) · `<text>` 로 그린 serif 기울임 「m」 · `?v=` 는 저장소에 이미 쓰는 방식 `(int) filemtime(…)`
- login_page.php: 「모아 자료실」 판 아이콘을 `library` 로 바꾸면 그 화면 아이콘 모음에 그림이 없어 빈칸이 되므로 `<template id="tpl-icon-library">`(moa_icon.php 한 벌 · 새 클래스 0) 한 줄을 더함 · title 꼬리 규칙은 `strpos(이름, '모아') === 0` 한 식
- launcher.php:64 h1 은 `<br><span>` 구조를 남겨 「우리 회사의 일과 자료를<br><span>한곳에 모아.</span>」 · 설명은 표의 첫 문장만 바꾸고 둘째 문장 그대로
- moa.css:17 은 표대로 바꿨지만 moa.css:654 에 같은 선언이 이미 있어 화면 결과는 전과 같다
- shell_top.php 파비콘 줄 위 주석을 새 파일 방식에 맞게 고침(옛 「data URI · 파일을 새로 만들지 않고」 설명)

## 아이콘 전후 캡처

- `/home/changkwon/qaredi-qf15s1-results-20261024/accept/shots/icons-before-after.png`(설치 아이콘 192 · 512 전후 + 파비콘 64 · 32 · 16)
- `/home/changkwon/qaredi-qf15s1-results-20261024/icons/` — before-qaredi-icon-192/512.png · after-qaredi-icon-192/512.png · before-favicon-shield.png · after-moa-favicon.png
- 화면: `accept/shots/` landing-1280-light · login-412-dark · login-tbox-1280-light · files-1280-light(자료실 사이드바) · home · work-home · cafe · doc-file-block-1280
