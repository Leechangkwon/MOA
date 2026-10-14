# 지시서 H2 — 로그인 전 첫 화면 · 초대 요청 (2026-10-14)

사용자(스크린샷 — 로그인 전 첫 화면): 「핵심이 없는 것 같음 — 가입을 하면 무엇을 할 수 있고 내가 어떻게 활용해야 하는지, 회원가입을 어떻게 해야 하는지, 초대 요청을 한다던지 하는 등 핵심 설명이 부족함」. 결정 IR-1 ~ IR-4(초대 요청 폼 · 사내 동료 · 이메일 받지 않기 · 처리방침 바로 시행).
기준 문서(**정답지**): 기획 · 보안 바닥 **`/var/www/qaredi/docs/invite-request-plan.md`**(2장 흐름 · 3장 판정 · 저장 · 창구) · `DESIGN.md` **2.32**(+ 2.31 글자 로고 · 2.3 · 2.4 · 2.6 · 2.7 · 6장) · `ARCHITECTURE.md` 13장 · 「모아 초대 가입」 절 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장.
단위: 한 기능 묶음(서버 · 화면 한 번에 — 크기가 작아 조각을 나누지 않는다). 시험은 **수락 시험만**(회귀 0 — 밤 회귀).

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-invite-20261014`**(브랜치 `feat/invite-request` — 메인이 H1 반영 뒤 만든다). 결과 **`/home/changkwon/qaredi-invite-results-20261014/`**. 운영 · 운영 DB · 다른 worktree 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <폴더>` — 포트 8768 한 번에 하나 · 새 마이그레이션은 `migrations` 목록 끝에 · 끊으면 합성 DB · 임시 폴더 · PHP 서버를 치움 |
| 계정 · 커밋 | 틀의 합성 계정 · UA `tbox-review/<날짜>` · 시드 계정 0 · 파일 지정(`git add -A` 금지) · 첫 줄 `Qaredi H2: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : 로그인 전 첫 화면이 「가입하면 할 수 있는 일 · 시작하는 법 · 안전 · 질문」을 말하고, 초대 없는 사람이 초대 요청을 남기면 관리자가 승인해
              요청한 사람이 확인 주소에서 초대 링크를 받게 한다(이메일 · 메일 발송 0).
요구사항    : 기획 2 · 3장 전부 · DESIGN 2.32 — 읽고 시작할 것.
수정 파일   : DB — db/migrations/2026-10-14_invite_requests.sql(새 — 표 · CHECK · 색인 · GRANT(tbox_app SELECT · INSERT · UPDATE(상태 · 결정 · 발급 열) · DELETE 0 · tbox_backup SELECT) · 두 번 돌려도 같음 · 되돌리기 주석) · db/schema.sql · db/grants.sql(정본 — 같은 내용) ·
              라우트 — public/index.php(창구 여섯 줄 — 화면 둘 public · 요청 · 확인 public · 목록 · 결정 user.manage) ·
              서버 — src/handlers/invite_request_page.php · invite_request_status_page.php · invite_requests_create.php · invite_requests_status.php · invite_requests_list.php · invite_request_decide.php(새) ·
                 src/Member.php(초대 발급을 지금 member_invites.php 와 **같은 함수**로 쓰도록 꺼냄 — 판정 · 검사 그대로) · src/handlers/member_invites.php(그 함수를 부름 — 응답 · 감사 그대로) ·
                 src/Audit.php(SUMMARY_TEMPLATES 에 새 동작 셋의 문장만) · scripts/privacy_purge.php(보관 기한 삭제 한 갈래 — 지금 방식 · 소유자 계정) · scripts/alerts.php(AL-25 규칙 하나) ·
                 content/legal/privacy-2026-10-14.html(새 판 — 아래 「처리방침 고침」) · config/legal.json(새 판 줄을 끝에) ·
              화면 — src/views/launcher.php(`!$signed` 갈래만 — 2.32 ①~⑥ · 동의 전 · 로그인 뒤 갈래 0 변화) · public/assets/moa.css · public/assets/invite-request.js(새 — 폼 · 확인 화면 · 복사) ·
                 사용자 관리 화면의 초대 절(지금 member_invites 를 그리는 뷰 · JS — 「초대 요청」 표 · 승인 · 반려 모달) ·
              시험 — tests/invite_request_http.py · tests/invite_request_browser.py(새 수락 — 또는 하나로) · memories_harness.py(두 자리) · tests/catalog.json ·
                 바뀐 로그인 전 화면 · 처리방침 판을 재던 옛 단언이 깨지면 그 단언만(목록을 보고서에 — 그 시험은 돌리지 않는다) ·
              문서 — ARCHITECTURE.md 「모아 초대 가입 · 메신저」 절에 초대 요청 한 단락(기획 3장을 가리킴) · 7.4 감사 문장 표에 새 줄 · SECURITY.md R 한 줄(공개 폼 남용 · 확인 코드 소지자 권한 — 다음 번호) ·
                 docs/invite-request-report.md(새)
              그 밖 0. 보안 핵심 파일은 위에 적은 자리만: db/**(셋) · public/index.php(라우트 여섯 줄) · src/Audit.php(문장만). Authz.php · Auth.php · Http.php · Db.php · Upload.php 0.
처리방침 고침: privacy-2026-10-08.html 을 복사해 2026-10-14 판으로 — 바꾸는 곳 넷만:
              제1조 목록에 「초대 요청 심사와 결과 안내」 · 제2조 목록에 「초대를 요청할 때: 이름, 소속(회사 · 팀), 쓰려는 이유, 접속 IP 주소」(「이메일 · 전화번호 · 생년월일 · 주민등록번호를 받지 않습니다」 문장은 그대로) ·
              제3조 표에 줄 「초대 요청(이름 · 소속 · 이유 · 접속 IP) | 처리한 날부터 30일(처리하지 않은 요청은 접수한 날부터 30일) 뒤 삭제」 · 제14조 첫 문장 「이 방침은 2026-10-14부터 적용됩니다.」(나머지 그대로).
              새 판이 끝 줄이 되므로 지금 장치대로 회원은 다음 접속 때 동의 화면을 한 번 거친다(사용자 결정 IR-4 — 장치 바꾸지 않음).
금지 패턴   : SQL 문자열 결합 · innerHTML · 인라인 style · 새 색값 · 확인 코드 · 초대 코드를 저장 · 주소 쿼리 · 로그 · 감사에 싣기(코드는 `#` 뒤 · POST 본문 · 해시만) ·
              감사 · 초대 이름표에 요청자 이름 · 소속 · 이유 싣기 · 메일 · 외부 요청 코드 · 초대 발급 두 벌 · 미끼 칸에 걸린 요청을 다른 응답 모양으로 알리기 · git add -A.
완료 기준   : HTTP(격리): 요청 201(모양 `{status_path}` · 코드 64 16진 · DB 엔 해시만 — 평문 0) · 키 · 길이 · 제어 문자 · agree 거짓 400 · CSRF 없음 403 · 미끼 칸 → 201 같은 모양 + DB 행 0 + deny 감사 ·
                 IP 1시간 4번째 429 · 24시간 6번째 429 · 열린 200 넘음 503 · 로그인한 사람 화면 → `/` · 확인: 틀린 코드 404 · 대기 · 반려(메모 0) · 승인 → 초대 발급(member_invites 1회 · 7일 · 팀 · 이름표에 이름 0) →
                 그 초대로 가입 성공 · 다시 확인 → 앞 초대 거둠 + 새 발급 · 4번째 409 · 가입 뒤 상태 · 1분 21번째 확인 429 · 목록 · 결정 직원 403 · 관리자 재인증 없으면 403 · 이미 결정 409 · 없는 팀 400 ·
                 감사 세 동작 문장(500 0) · 감사 행에 요청자 이름 · 소속 · 이유 · 코드 0 · access log 에 코드 0 · 보관 삭제(시각을 당긴 행이 privacy_purge 한 번에 지워짐 · 아직 아닌 행은 남음) · AL-25(dry-run 에서 21건 → 경보 1) ·
                 처리방침: /tbox/privacy 가 새 판 · ?v=2026-10-08 옛 판 · 회원 다음 요청에서 동의 관문 → 동의 뒤 통과
              브라우저(1440 · 1200 · 720 · 412 · 360 × 밝게 · 어둡게 · 고대비): 로그인 전 첫 화면 ①~⑥(옛 블록 0 · 동의 전 · 로그인 뒤 화면 바이트 같음) · 초대 요청 폼 → 완료 패널 · 복사 · 확인 화면 넷 상태 ·
                 관리자 표 · 승인 모달(팀) · 반려 모달 · 넘침 0 · 대비 4.5 · 콘솔 0 · 누름 자리 < 44 0 · 스크린샷(첫 화면 1440 · 412 · 폼 · 완료 · 확인)
              정적: security_lint 0 · php -l · node --check · wiki_check · git diff --check · 마이그레이션 두 번(틀)
보고 형식   : docs/invite-request-report.md — 변경 파일 / 수락 시험과 결과 / 고친 옛 단언 / 못 한 것 / 기획 · DESIGN 과 다르게 한 것. 짧게.
```

## H3 — 「우리 공간」 = 카페 (사용자 지적 2026-10-14 · H2 뒤 · 같은 worktree · 같은 반영)

사용자(스크린샷 — 「우리 공간」 화면): 「여기 내 공간은 비활성화하고, 바로 카페로 넘어가지게 하자」.

```
목표        : 「우리 공간」을 누르면 카페 셸 안의 카페 홈(`/community/cafe`)이 바로 열리고, 「내 공간」(회사 · 모임 공간) 묶음은 화면에서 빠지되 옛 공간 링크는 살아 있게 한다.
요구사항    : DESIGN 2.29 「「우리 공간」 = 카페」 줄(2026-10-14) · 2.29 카페 홈 ①② · 카페 셸.
수정 파일   : src/community/routes.php(`GET /cafe` 화면 한 줄 · community.read) · src/community/handlers/cafe_page.php(새 — 카페 셸 · 카페 홈 뼈대 · 판정 0 — 자료는 지금 /api/cafe) ·
              src/handlers/product_spaces_page.php(쿼리 없으면 302 /community/cafe · `?space=` 면 지금 화면) · src/views/space_page.php(카페 묶음 · 「내 공간」 머리 빼고 공간 화면만) ·
              public/assets/space-page.js(cafeHome 부분을 카페 화면 JS 로 옮김 — 두 벌 0) · public/assets/community/cafe.js(카페 홈 그리기 — 옮겨 온 것) ·
              src/Apps.php 또는 앱 목록 원본(우리 공간 주소) · src/views/launcher.php(바로가기 `spaces` 주소 한 줄) · src/views/shell_top.php · shell_tools.php(카페 사이드바 「카페 홈」 · 셸 브랜드 주소) ·
              public/assets/space-page.css · community.css(옮긴 클래스) · 위키에서 /tbox/spaces 를 가리키는 카페 안내 글의 링크 ·
              시험 — tests/community/cafe_h3_browser.py(새 수락) · memories_harness.py(choices) · tests/catalog.json · 카페 홈 자리를 /tbox/spaces 로 재던 옛 단언(cafe_c2_browser 등)은 그 단언만 · docs/invite-request-report.md(H3 절)
금지 패턴   : 공간 창구 · 판정 · 자료 바꾸기 · 카페 홈 그리기 두 벌 · innerHTML · 인라인 style · 새 색값 · git add -A.
완료 기준   : 앱 판 · 홈 바로가기 · 카페 사이드바 · 셸 브랜드의 「우리 공간」 → /community/cafe(카페 셸 · 사이드바 카페 모드 · 카페 홈 카드 · 새 글 · 인기 · 「게시판 만들어 주세요」) · 「내 공간」 0 ·
              /tbox/spaces → 302 /community/cafe · /tbox/spaces?space=<읽을 수 있는 공간> → 그 공간 화면(카페 묶음 0) · 못 보는 공간은 지금과 같은 거부 ·
              홈 대시보드 공간 위젯 「전체 보기」 링크가 그 공간을 엶 · 손님 · 직원 · 관리자 · 1440 · 412 × 밝게 · 어둡게 · 고대비 넘침 0 · 콘솔 0
```

## H4 — 「모아.」 글자 로고(시안 D) · 바닥 붙이기 (사용자 지적 · 결정 2026-10-14 · H3 뒤 · 같은 worktree · 같은 반영)

사용자(스크린샷 — 새 모아 홈): 「최하단에 밑에 깔려 있어야 할 글이 붕 떠 있는 느낌 · Qaredi 도 그냥 모아로 바꾸고 · 모아 글씨를 조금 비비드하게 꾸며 봐 — 그 비비드한 색상이 맘에 들면 전역에 모아 글씨를 개선하자」 → 시안 「D(진한 보라 단색 + 노란 점)가 좋아 보인다」 · 「저런 형태로 해서, 테마 설정하면 색상 맘에 드는 걸로 선택해 가면서 변경 가능하도록 하자」(시안 A ~ D 를 테마 창에서 고름 · 기본 D).

```
목표        : 사이트의 「모아」 글자를 한 부품(기본 D 보라 + 노란 점 · 테마 창에서 A ~ D 고르기)으로 바꾸고, 「Qaredi」 글자를 「모아.」로, 짧은 화면의 바닥 글을 화면 맨 아래로 붙인다.
요구사항    : DESIGN 2.33 전부(main 의 DESIGN.md — 2026-10-14) · 2.31 · 2.32 의 「Qaredi」 자리 · 2.16 고대비.
수정 파일   : public/assets/app.css(새 토큰 2.33 의 값만 — :root · 다크 · 고대비 블록 · `[data-wordmark=a|b|c]` 갈래) · src/Design.php(쿠키 `qaredi_wordmark` 허용 목록 · attrs 에 data-wordmark) ·
              public/assets/design.js(테마 창에 「모아 글씨 색」 라디오 넷 · 미리보기 · 쿠키 쓰기 — 지금 테마와 같은 길) · 테마 창 마크업(`design_controls.php` 등 — 그 창이 있는 곳) · public/assets/moa.css(.moa-wordmark · __dot · 상징 · 바닥 붙이기) ·
              src/views/moa_wordmark.php(새) · src/views/launcher.php · src/views/member_top.php · src/handlers/legal_page.php · 로그인 화면 뷰 · src/views/shell_top.php(셸 브랜드 「모아」 칠 — 마크업 한 span) ·
              바닥 붙이기에 필요한 그 화면들의 body · main 클래스 · 시험 — tests/community/home_h4_browser.py(새 수락) · memories_harness.py(choices) · tests/catalog.json ·
              「Qaredi」 글자 · 바닥 위치를 재던 옛 단언(home_h1_browser · H2 시험 등)은 그 단언만 · docs/invite-request-report.md(H4 절)
금지 패턴   : 2.33 에 없는 새 색값 · 인라인 style · 글자 로고 마크업 두 벌(전부 moa_wordmark.php) · 테마(Astra 등) 토큰을 바꾸기 · git add -A.
완료 기준   : 모아 홈 · 로그인 전 첫 화면 · 머리 로고 · 바닥 · 로그인 · 가입 · 동의 · 초대 요청 · 법적 문서: 「모아.」 글자(계산된 색 = --brand-wordmark · 점 = --brand-wordmark-dot) · 「Qaredi」 글자 0 ·
              셸 브랜드 앞 「모아」 보라 · 점 0 · 테마 다섯을 바꿔도 글자 로고 색 그대로 · 고대비에서 --fg · 대비 4.5 이상(밝게 · 어둡게 · 네 갈래 첫 색) ·
              테마 창 「모아 글씨 색」 넷 · 고르면 즉시 바뀜 · 새로 고침 · 다른 화면에서 첫 HTML 의 data-wordmark 가 고른 값 · 엉뚱한 쿠키 값 → d · 쿠키 없음 → d · C 에서 「모」 「아」 두 색 · A · B 그라데이션 · 점은 D 만 ·
              바닥: 1440×900 · 1920×1080 · 412×900 에서 내용이 짧은 화면(모아 홈 대시보드 접힘 · 가입 · 초대 요청) 바닥 아래 끝 = 창 아래 끝(±1) · 내용이 긴 화면은 내용 뒤 · 넘침 0 · 콘솔 0 · 스크린샷(홈 밝게 · 어둡게 · 1920 · 412)
```

## H5 — 사이드바 머리 글자 없앰 (사용자 지적 2026-10-14 · H4 뒤 · 같은 worktree · 같은 반영)

사용자(스크린샷 — 자료실): 「좌측 상단에 모아 자료실, 그 옆에 또 자료실이 있음 — 사이드바에서는 굳이 보여 줄 필요가 없는 것 같고 디자인을 해치는 요소로 느껴짐 · 아니면 자료실 텍스트를 삭제하던가 일관되게 전역에 적용하여 깔끔하게」.

```
목표        : 모든 셸의 사이드바 머리를 ☰ + 앱 아이콘만으로 줄여 본문 제목과 겹치는 「모아 자료실 · 파일 보관 · 찾기 · 공유」 같은 글자를 없애고,
              앱 판(셸 · 런처 공용 shell_tools.php)의 「검색」 타일을 뺀다(사용자 2026-10-14 「단축키로도 모든 페이지에서 열리는데 앱에 굳이 검색을 넣을 필요가 없음 — 제거해」).
요구사항    : DESIGN 2.19 ⑩ · 2.30 「앱 판」 줄 · 2.33 「셸 브랜드」 줄(main 의 DESIGN.md — 2026-10-14) · 2.8 풍선.
수정 파일   : src/views/shell_top.php(머리 마크업 — brand__text 를 그리지 않음 · 링크 aria-label · 풍선) · src/views/shell_tools.php(검색 타일 줄 삭제) · public/assets/app.js(검색 타일 갈래 삭제 — `qarediSearchOpen` · 사이드바 · Ctrl K 는 그대로) · public/assets/app.css(머리 줄 높이 · 정렬 · 남는 규칙 정리) ·
              시험 — tests/community/home_h4_browser.py 에 H5 단언 · 브랜드 글자 「모아 업무」 등을 재던 옛 단언(qf12_shell_browser · qf12_u5 · cafe_c2 등)은 그 단언만(목록을 보고서에) · docs/invite-request-report.md(H5 절)
금지 패턴   : 셸마다 다른 머리 · 폰 윗줄 앱 이름 ▾ 바꾸기 · 인라인 style · 새 색값 · git add -A.
완료 기준   : 1440 · 1280 · 960: 업무 · 이야기 · 자료실 · 카페 · 관리 화면 사이드바 머리에 보이는 글자 0 · ☰ + 아이콘 36 · 아이콘 링크 = 그 앱 첫 화면 · aria-label 「… 첫 화면으로」 · 올리면 풍선 ·
              접힌 레일 그대로 · 412 폰 윗줄 앱 이름 ▾ 그대로 · 앱 판(셸 · 런처 · 폰) 검색 타일 0 · 사이드바 「검색」 · Ctrl K · 폰 돋보기 · 모아 홈 검색 칸은 그대로 열림 · 넘침 0 · 콘솔 0 · 스크린샷(자료실 · 업무 1440)
```
