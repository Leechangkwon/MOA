# Q:F-13 — 개인정보 처리 빈틈 빠른 고침 (지시서 H · 핫픽스, 2026-10-07)

사용자 결정(2026-10-07, 기획 `../../docs/privacy-terms-plan.md` v0.2 8장 PT-10 「추천대로」): 조사에서 드러난 빈틈 중 **G-1 · G-3 · G-6 은 바로** 고친다(작고 위험이 큼). 처리방침·약관 게시 · 동의 · 보관 기간 파기 · 탈퇴는 지시서 P(Q:F-12 묶음 뒤)다.

기준 문서(읽고 시작): 기획 `../../docs/privacy-terms-plan.md` 2.3 · 6장 G-1 · G-3 · G-6 · `ARCHITECTURE.md` 13장(5대 방어) · `../CLAUDE.md` 2·3·4장 · CLAUDE.md 3.1 「따로 쓰이는 것(핫픽스) — 그 자체로 한 묶음」.

## 공통

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf13h-20261007`**(브랜치 `fix/qf13-privacy-gaps`, 이 지시서가 든 main 커밋에서 — 메인 세션이 만든다) · 결과 `/home/changkwon/qaredi-qf13h-results-20261007/`. 운영 경로·DB·8082 는 건드리지 않는다(읽기도) |
| 시험 틀 | `tests/community/memories_harness.py`(포트 8768 고정 — **Q:F-12 작업과 동시에 돌지 않는다.** 포트가 쓰이고 있으면 기다렸다 다시, 남의 프로세스를 멈추지 않는다) · 브라우저 `. /home/changkwon/qaredi-qf13h-results-20261007/env.sh` 만 |
| 계정 | 격리 틀 fixture 만. 운영을 치는 시험 없음 |
| 금지 패턴 | CLAUDE.md 2장 그대로 + 사진 원본(저장된 파일)을 고치지 않는다(G-2 는 P 몫) · 외부 요청 코드 새로 만들지 않는다 · 콘솔 출력 0 |
| 보고 형식 | 변경 파일 목록 / 실행한 명령과 출력 원문(결과 폴더) / 못 한 것과 이유 / 확정한 값(아래) |

## 지시서 H

```
목표        : ① Gemini 로 보내는 사용자 사진은 크기와 상관없이 늘 다시 인코딩해 메타데이터(EXIF·GPS)를 지운다(화면 안내 「축소본」과 실제를 맞춘다)
              ② 로그아웃하면 여행 임시 글·사진(IndexedDB)도 지운다 ③ 붙지 않은 사진 정리 스크립트를 매일 돌린다.
요구사항    : Q:F-13 G-1 · G-3 · G-6 — 기획 2.3(Gemini 표) · 6장 — 해당 절을 읽고 시작할 것. 화면 변경 없음.
수정 파일   : src/community/MemoryAi.php(referencePart 의 「작은 사진은 원본 그대로」 갈래를 없애 늘 다시 인코딩 — 같은 해상도 상한·메모리 검사 유지) ·
              (조사 뒤 필요하면) src/MemberAi.php · src/MemberAssist.php — Gemini 로 사용자 이미지 바이트를 보내는 다른 길이 원본을 그대로 보내면 같은 방식으로 ·
              public/assets/app.js(로그아웃 처리 한 곳 — clearMyDrafts 옆에서 IndexedDB `qaredi-travel-drafts` 삭제) ·
              public/assets/community/memories.js(필요할 때만 — 지우는 함수를 내보내는 최소 변경) ·
              ★ deploy/cron.d/tbox-v2(한 줄: 매일 04:20 `php /var/www/qaredi/tbox-v2/scripts/memories_cleanup.php --apply`, 다른 정리와 같은 실행 계정) ·
              tests/community/memory_ai_http.py(시험 추가) · tests/community/qf13h_privacy_browser.py(새) · tests/community/memories_harness.py(--suite choices 한 줄) · tests/catalog.json
              여기 없는 파일 금지. 운영 cron 적용은 메인 세션 몫(파일만 고친다).
금지 패턴   : 「공통」 표.
완료 기준   : H 시험 전부 + 기존 memory_ai_http.py · memory_story_http.py · memories_http.py 전과 같은 수 + stage89 교차 회귀 + security_lint 0 + php -l + node --check + git diff --check.
보고 형식   : 「공통」. 확정한 값: Gemini 로 이미지를 보내는 길 전부의 목록(파일:줄)과 각각 메타데이터를 지우는지 · 지운 IndexedDB 이름 · cron 줄 원문.
```

| 시험 | 확인 |
|---|---|
| G-1 | GPS·카메라 정보가 든 작은 JPEG(≤1280px · ≤600KB)와 큰 JPEG 를 사진 기록에 올리고 「Gemini로 글쓰기」 → 합성 provider 가 받은 요청의 이미지에 EXIF(APP1 `Exif`) · GPS 0 · 큰 사진도 같음 · 저장된 원본 파일은 바이트 그대로(sha256 같음) · 메신저 AI 이미지 첨부 등 다른 길도(있으면) 같은 확인 |
| G-3 | 격리 틀 + Chromium: 여행 임시 글에 글·사진을 남긴 뒤 셸 로그아웃 → IndexedDB `qaredi-travel-drafts` 에 그 사람 항목 0 · `qaredi:logout` 이벤트 길(모아 머리 화면)도 같음 · 다시 로그인한 다른 사람에게 이전 임시 글 0 · 콘솔 0 |
| G-6 | cron 파일 문법(필드 수 · 경로) 정적 검사 · `memories_cleanup.php` 를 격리 틀 DB 에 dry run 뒤 `--apply` — 7일 지난 붙지 않은 사진만 지우고 붙은 사진·7일 안 된 것은 남음 |
| 회귀 · 정적 | 위 「완료 기준」 |

H 가 끝나면 멈춘다 — 메인 세션이 독립 검토 · 발행(patch) · 운영 반영(cron 포함)을 한다.

### H-1 검토 반영 — 재지시 1 (2026-10-07 · 메인 독립 검토 `SECURITY.md` 7장)

```
목표        : 깨진 시험 그림 때문에 실패하는 단위 시험을 고친다(코드 변경 없음).
요구사항    : 독립 검토 보정 H-1 — tests/community/memory_ai_unit.php 의 1×1 PNG 고정값을 CRC 가 맞는 같은 픽셀의 PNG 로.
수정 파일   : tests/community/memory_ai_unit.php 만.
금지 패턴   : 「공통」 표. 코드·다른 시험 변경 0.
완료 기준   : php tests/community/memory_ai_unit.php 통과(출력 원문) + tests/community/memories_check.sh 가 돌릴 수 있으면 그 결과 + git diff --check.
보고 형식   : 「공통」. 확정한 값: 바꾼 줄 · 커밋 해시.
```

## H 운영 반영 결과 (2026-10-07 02:40 KST · 메인 세션)

v2.50.1 `073ff04`(태그 `v2.50.1`, 기준 `selftest-base/qf13h`=d23db4b · 검토 `review-base/qf13h`=043627e — 검토 뒤 H-1 시험 고정값 한 줄 · main 위로 다시 쌓음). 순서: 독립 검토(메인 재실행 `memory_ai_http` 91/0 · `qf13h_privacy_browser` 38/0 · `memory_ai_unit` 24 통과 · lint 0) → 운영 점검(작업 트리 깨끗 · `313844b` 에서 바로 이어짐 · 설치된 cron = 저장소 · 정리 dry run `unattached uploads older than seven days: 0` — 켜도 지금 지워질 것 0) → 코드 ff-merge · `php8.1-fpm` reload → `/etc/cron.d/tbox-v2` 설치(644 root, 저장소와 같음) → 운영 HTTPS(UA `tbox-review/2026-10-07`): `/` 200 · `/tbox/login` 200 · `/community/memories` 302(로그인) · 운영 `app.js?v=1791308373` 에 `clearTravelDraft` · 서버 `MemoryAi.php` 에 원본 그대로 갈래 0 · `php -l` → push(main · v2.50.1 · 검토 태그 둘). DB 변경 0 · nginx 변경 0.
남은 것(따로): `tests/community/memories_render.php` 의 `Authz` 스텁에 `canAdmin` 이 없어 `memories_check.sh` 가 그 단계에서 멈춤(Q:F-11 B 부터의 기존 실패) · 같은 깨진 PNG 고정값이 `memories_http.py:29` · `memory_social_http.py:85` · `moa_local_assist.py:84` 에도 있음(지금 다시 인코딩 길을 타지 않아 통과) · `memories_cleanup.php` 감사 기록 없음(P2 파기와 함께) · 실제 root cron 첫 실행(04:20)은 다음 날 확인.
---

# 지시서 P — 처리방침·약관 게시 · 동의 · 보관 기간 정리 · 탈퇴 (2026-10-07, Q:F-12 묶음 운영 반영 뒤 시작)

사용자 결정(2026-10-07, 기획 v0.4 8장): PT-1 운영자 개인 이창권 · PT-2 보호책임자 이창권 `changkwon.lee@metaht.kr` · PT-3 시행일 = **게시하는 날**(P 운영 반영일 — 그날 날짜를 문서 파일 이름·본문에 넣는다) · PT-4 약관 동의(필수) + 처리방침 확인 + AI 국외 전송만 따로 동의, 기존 회원은 다음 로그인 때 한 번 · PT-5 보관 기간(기획 5.1 표) · PT-6 탈퇴 요청 → 관리자 처리 · PT-7 중계 서버 고지 · PT-8 AI 첫 사용 동의 · PT-9 만 14세 이상 확인 · PT-10 G-2 · G-4 · G-5 · G-8 은 P.

기준 문서: 기획 `../../docs/privacy-terms-plan.md` **5.1 · 5.2(정답지)** · 부록 A·B(문서 본문 — 그대로 옮기고 바꾸지 않는다, 바꿀 곳이 보이면 멈추고 보고) · `ARCHITECTURE.md` 13장 · `DESIGN.md`(새 화면 6장 체크리스트) · `../CLAUDE.md` 2·3·4장.

시작 조건: Q:F-12 묶음과 H 가 운영에 반영되고 main 에 합쳐진 뒤, 그 main 에서 새 worktree(메인 세션이 만든다). 묶음 = P1 → P2, 검토·발행(minor)·운영 반영은 묶음 끝 한 번. **P1 의 동의 기록 표·가입 강제·AI 동의 판정은 보안 바닥 → 먼저 검토만**(CLAUDE.md 3.1 예외).

## 지시서 P1 — 문서 게시 · 링크 · 동의 기록 · 가입 · 기존 회원 · AI 국외 전송 동의

```
목표        : /privacy · /terms 를 로그인 없이 보이게 하고, 모든 바닥에 링크를 두며, 가입·기존 회원·AI 첫 사용의 동의를 서버가 강제하고 지울 수 없게 기록한다.
요구사항    : Q:F-13 PT-1~4 · PT-7~9 — 기획 5.2 표의 「문서 원본」~「AI 국외 전송」 행, 부록 A·B — 해당 절을 읽고 시작할 것. 새 화면: DESIGN 6장 체크리스트.
수정 파일   : content/legal/privacy-<게시일>.html · content/legal/terms-<게시일>.html(새 — 부록 본문 그대로, 시행일 = 게시일) · config/legal.json(새) · src/Legal.php(새 — 목록·파일 읽기 한 곳) ·
              ★ public/index.php(라우트 /privacy · /terms 공개 · /tbox/consent · /api/me/consents) · src/handlers/legal_page.php(새) · src/handlers/consent_page.php(새) · src/handlers/me_consents.php(새) ·
              ★ db/migrations/<날짜>_user_consents.sql(새) · ★ db/schema.sql · ★ db/grants.sql ·
              src/handlers/member_join_page.php · src/handlers/member_register.php(동의 강제·기록) ·
              ★ src/Auth.php 또는 지금 「계정 절차」 넘김이 있는 자리(동의 없는 기존 회원 → /tbox/consent · 세션 API 403 consent_required — 어느 파일인지 찾아 보고하고, 그 파일만) ·
              src/MemberAi.php · src/MemberAssist.php · src/community/MemoryAi.php · src/community/Ai.php(외부 호출 앞 ai_overseas 확인 — 409 ai_consent_required) ·
              src/views/launcher.php · src/handlers/login_page.php · src/views/member_top.php · src/views/shell_top.php(바닥 링크) · public/assets/app.css(바닥 링크·동의 화면 — 구성 요소 조합) ·
              public/assets/member.js · public/assets/messenger.js · public/assets/community/memories.js · public/assets/community/editor.js(409 ai_consent_required → 동의 창, 최소 변경) ·
              ★ src/Audit.php(새 감사 이름 등록만 — consent.agree · consent.withdraw) · API.md · tests/qf13_consent_http.py(새) · tests/catalog.json
              여기 없는 파일 금지.
금지 패턴   : CLAUDE.md 2장 + 문서 파일 경로를 요청 값으로 만들지 않는다(목록의 값만) · 동의 줄을 고치거나 지우는 코드 0(앱 권한 SELECT·INSERT 만) · 화면의 체크만 믿지 않는다(서버 강제) ·
              AI 동의 확인 전에 외부 호출 0 · 공개 화면에서 세션·쿠키 발행 0.
완료 기준   : P1 시험 전부 + 회원 가입·메신저 AI·사진 이야기 기존 시험 전과 같은 수(동의 줄을 시험 준비에 넣은 것만 바뀜 — 이유 보고) + stage89 교차 회귀 + security_lint 0 + php -l + node --check + git diff --check.
보고 형식   : 지시서 H 「공통」과 같다. 확정한 값: 문서 파일 이름 · 라우트 · 동의 화면 문구 원문 · 409/403 본문 원문 · 감사 이름.
```

| 시험 | 확인 |
|---|---|
| 공개 | 로그인 없이 `/privacy` · `/terms` 200 · `Set-Cookie` 0 · 본문에 운영자·책임자 이메일·시행일 · `?v=없는값` 404 · `?v=../x` 404 |
| 가입 | 체크 둘 중 하나라도 빠진 요청 → 400 · 계정 0 / 둘 다 → 201 · `user_consents` 세 줄(`terms`·`privacy`·`age14`) |
| 기존 회원 | 동의 줄 없는 회원 로그인 → 웹 화면 `/tbox/consent` 로 · 세션 API 403 `consent_required`(동의·로그아웃·`/api/me` 제외) · 개인 토큰 창구는 그대로 · 동의 뒤 정상 |
| AI | `ai_overseas` 없는 회원의 모아 AI · 메신저 AI 도구 · 사진 이야기 · 커뮤니티 AI → 409 · 합성 provider 호출 0 / 동의 뒤 200 / 철회 뒤 다시 409 |
| 기록 | 앱 계정으로 `user_consents` UPDATE/DELETE → permission denied · 감사 `consent.agree`·`consent.withdraw` |
| 화면 | 바닥 링크 다섯 자리 · 동의 화면 360·412·820·1440 가로 넘침 0 · 콘솔 0 · 고대비 |

**P1 결과(2026-10-07 · `ac36b5c`·`d2be3c8` · 메인 독립 검토 통과 — `SECURITY.md` 7장)와 P2 에 더하는 것**
- P1 확정 값: 라우트 `/tbox/privacy` · `/tbox/terms`(공개, `/privacy`·`/terms` 308) · `/tbox/consent` · `GET|POST /tbox/api/me/consents` · 문서 파일은 시행일 자리 `0000-00-00`(발행 날 메인이 `sed` + `git mv`) · 관문 `Auth::requireConsent`.
- **P2-a**(경미 ①): `Auth::requireConsent` 의 거부 감사가 배경 폴링(`X-Qaredi-Background: 1`)마다 쌓이지 않게 — 배경 요청은 감사 없이 403(사람이 누른 요청만 감사). `src/Auth.php` 를 P2 수정 파일에 더한다(그 함수만).
- **P2-b**(경미 ②): `ai_overseas` 에 판을 둔다 — `config/legal.json` 에 `ai_overseas` 판(예: `gemini-2026-10`)을 두고 `Legal::aiAgreed` 는 **지금 판과 같은 agree** 만 유효. 받는 곳·등급이 바뀌면 판을 올려 다시 묻는다. `src/Legal.php` · `config/legal.json` 을 더한다.
- **P2-c**(경미 ③): 「내 계정」(`settings_page.php` — 이미 P2 목록)에 「약관 · 개인정보 처리방침 · AI 국외 전송 동의」 줄(→ `/tbox/consent`).
- **P2-d**: 동의 줄 없이 세션을 만드는 시험 준비(stage10~13 · export · file_rename · assistant 등 — 찾아서 목록 보고)에 「이미 동의한 회원」 줄만 더한다(시험 준비 파일만).
- **P2-e**: 처리방침 제4조의 「[법령에 따라 보존해야 하는 경우 그 근거와 기간]」 → 「다른 법령이 보존을 요구하는 경우에는 그 법령이 정한 기간 동안 다른 정보와 분리해 보관합니다.」(`content/legal/privacy-0000-00-00.html` 그 문장만). 제7조의 두 표시(나라 · 보유)는 사용자 답(Gemini 키 등급) 뒤 메인이 고친다 — **건드리지 않는다**.

## 지시서 P2 — 보관 기간 정리 · 탈퇴 · 사진 위치 정보(G-2) · 감사 3년(G-8)

```
목표        : 기획 5.1 보관 기간을 매일 자동으로 지키고, 탈퇴 요청·처리·30일 뒤 익명화를 만들며, 사진 기록에 올리는 사진의 위치 정보를 지우고, 감사 기록을 3년으로 맞춘다.
요구사항    : Q:F-13 PT-5 · PT-6 · G-2 · G-4 · G-5 · G-8 — 기획 5.1 표 · 5.2 「보관 기간 파기」 「감사 3년」 「탈퇴」 「G-2」 행 — 해당 절을 읽고 시작할 것.
수정 파일   : scripts/privacy_purge.php(새) · ★ db/migrations/<날짜>_privacy_retention.sql(새 — account_deletion_requests 표 · 소유자만 부르는 파기 함수) · ★ db/schema.sql · ★ db/grants.sql · ★ db/community/schema.sql · ★ db/community/grants.sql(필요할 때만) ·
              ★ deploy/cron.d/tbox-v2(한 줄) · ★ deploy/logrotate.d/tbox-v2(감사 파일 3년) · scripts/audit_chain.php(검증 시작점) ·
              src/handlers/me_delete_request.php(새) · src/handlers/admin_deletion_requests.php(새) · ★ public/index.php(그 창구 라우트) · src/handlers/settings_page.php · src/handlers/admin_page.php(목록 자리) ·
              public/assets/app.js(탈퇴 요청 · 관리자 처리 화면 — 지금 재인증·확인 창 재사용) · src/community/Memories.php(올릴 때 메타데이터 지운 판 저장) ·
              ★ src/Audit.php(새 감사 이름 등록만) · API.md · tests/qf13_retention_http.py(새) · tests/community/memories_harness.py(choices) · tests/catalog.json ·
              (P2-a~e) ★ src/Auth.php(requireConsent 만) · src/Legal.php · config/legal.json · content/legal/privacy-0000-00-00.html(제4조 한 문장) · 동의 줄이 필요한 시험 준비 파일
              여기 없는 파일 금지.
금지 패턴   : CLAUDE.md 2장 + 파기를 앱 계정 권한을 넓혀 하지 않는다(소유자 함수) · 운영 데이터를 시험하지 않는다 · 이미 저장된 옛 사진은 고치지 않는다 · 감사 기록 파기는 3년 지난 줄만.
완료 기준   : P2 시험 전부 + stage89 교차 회귀 + 사진 기록·메신저·AI 기존 시험 전과 같은 수 + security_lint 0 + php -l + node --check + git diff --check.
보고 형식   : 「공통」. 확정한 값: cron·logrotate 줄 원문 · 파기 함수 이름·권한 · 탈퇴 화면 문구 · 익명화 뒤 값 모양.
```

| 시험 | 확인 |
|---|---|
| 파기 | 시험 DB 에 시각을 당긴 줄(366일 전 메시지·AI 대화 · 31일 전 지운 사진 기록·여행 계획 · 3년+1일 전 감사)과 경계 안 줄 → 파기 뒤 지난 것만 0 · 사진 파일도 0 · dry run 은 수만 |
| 권한 | 앱 계정으로 파기 함수 호출 → permission denied · `audit_logs` DELETE → denied(그대로) |
| 탈퇴 | 요청(재인증 없으면 거부) → 관리자 처리(재인증) → 즉시 로그인 불가 · 세션·패스키·토큰·OTP 0 → 30일 당긴 뒤 파기: 아이디 `deleted-<번호>` · 표시 이름 「탈퇴한 사용자 #번호」 · 개인 공간 문서·사진·여행 0 · 팀 공간·게시판 글·자료실 파일 남음(작성자 표시 바뀜) · 감사 셋 |
| G-2 | GPS 든 JPEG·PNG 를 사진 기록에 올림 → 저장된 파일에 EXIF/GPS 0 · 화면 표시 정상 |
| 감사 사슬 | 파기 뒤 `audit_chain.php` 검증 통과(시작점부터) |

### P2-R1 검토 반영 — 재지시 1 (2026-10-07 · 메인 독립 검토 `SECURITY.md` 7장)

```
목표        : 독립 검토 보정 셋 — 동의하지 않은 사람의 탈퇴 길, `deleted-` 아이디 예약, 탈퇴 재인증 문구.
요구사항    : ARCHITECTURE 4.11 「탈퇴」 행(고친 판) · DESIGN 2.23 「탈퇴」 · 약관 제3조(「바뀐 약관에 동의하지 않는 회원은 … 탈퇴를 요청할 수 있습니다」).
수정 파일   : ★ src/Auth.php(CONSENT_GATE_ALLOW 에 `GET /api/me/delete-request` · `POST /api/me/delete-request` 두 줄만) ·
              src/handlers/consent_page.php(「동의하지 않으면 … 로그아웃」 옆 「탈퇴 요청」 길 — 지금 재인증·확인 창 재사용) · public/assets/member.js(그 단추 연결, 최소) ·
              src/handlers/users_create.php · src/handlers/member_register.php · src/handlers/users_update.php(아이디가 `deleted-` 로 시작하면 400 — 같은 문구) ·
              public/assets/app.js(탈퇴 요청의 재인증 창 문구만 — 「탈퇴를 요청하려면 비밀번호를 다시 확인해 주세요」) ·
              tests/qf13_retention_http.py · tests/qf13_consent_http.py(시험 추가) · (탈퇴 경로가 member.js 를 거치면) tests 의 해당 브라우저 시험
              여기 없는 파일 금지.
금지 패턴   : 「공통」 표. 동의 관문은 두 줄 말고 열지 않는다.
완료 기준   : ① 동의하지 않은 회원이 동의 화면에서 탈퇴 요청(재인증 → 201)·관리자 처리까지 · 그 밖 창구는 여전히 403/302 ② `deleted-` 접두 아이디 만들기·가입·이름 바꾸기 400 ③ 재인증 창 문구 원문 · `qf13_retention_http` · `qf13_consent_http` 전부 + lint 0 + php -l + node --check + git diff --check.
보고 형식   : 「공통」. 확정한 값: 바꾼 줄 · 문구 원문 · 커밋 해시.
```

### P2-R2 — 재지시 2 (2026-10-07 · P2-R1 보고의 빈틈: 동의 전에는 재인증 창구도 막힘)

메인 결정(선택지 A): `POST /api/me/reauth` 를 동의 관문의 열린 목록에 더한다 — 재인증은 권한을 주지 않는다(ARCH 4.11 「순서」 고친 판). 패스키 재인증 창구는 열지 않는다(동의 화면은 비밀번호 재인증만). 핸들러가 비밀번호를 따로 확인하는 선택지 B 는 인증 판정이 두 벌이 되어 쓰지 않는다.

```
목표        : 동의하지 않은 회원이 동의 화면에서 비밀번호 재인증 → 탈퇴 요청까지 실제 창구로 끝까지 간다.
수정 파일   : ★ src/Auth.php(CONSENT_GATE_ALLOW 에 `POST /api/me/reauth` 한 줄) · ★ public/index.php(90줄 근처 낡은 주석만) ·
              tests/qf13_retention_http.py · tests/qf13_consent_http.py(GAP 검사를 실제 흐름 통과로 뒤집기 — 시험 DB 에 reauth_at 직접 넣던 준비 제거)
금지 패턴   : 「공통」 표. 열린 목록에 그 한 줄 말고 더하지 않는다.
완료 기준   : 동의하지 않은 회원: 비밀번호 재인증 200 → 탈퇴 요청 201(화면에서도) · 재인증 뒤에도 다른 창구 403/302 그대로 · 두 시험 전부 + lint 0 + php -l + node --check + git diff --check.
```

### P2-R3 — 재지시 3 (2026-10-07 · 메인 운영 읽기로 찾은 R-120 소유자 어긋남)

```
목표        : 운영에서 `postgres` 소유로 어긋난 표·시퀀스·함수를 `tbox_owner` 로 바로잡는 마이그레이션을 더해, privacy_purge(소유자 계정)가 운영에서도 정리 대상을 다루게 한다.
요구사항    : SECURITY R-120 · ARCHITECTURE 10장 끝 「마이그레이션 적용 계정」 — 해당 절을 읽고 시작할 것.
수정 파일   : ★ db/migrations/2026-10-22_owner_fix.sql(새) · tests/qf13_owner_fix.sh 또는 .py(새 — 스크래치 DB 시험) · tests/catalog.json · tests/community/memories_harness.py(migrations 목록 끝 한 줄 — 틀이 이 파일도 두 번 적용)
              여기 없는 파일 금지.
대상(운영 실측 2026-10-07 — 이 목록만): 표 community.memory_follows · community.memory_preferences · community.travel_plan_records · community.travel_plans · community.usage_guide_versions ·
              community.usage_guides · public.api_tokens · public.fetch_hosts · public.folders · public.mcp_clients · public.mcp_codes · public.mcp_grants · public.mcp_pending ·
              public.mcp_tokens · public.share_links / 시퀀스 public.api_tokens_id_seq · public.fetch_hosts_id_seq · public.folders_id_seq · public.share_links_id_seq / 함수 public.mcp_bump_security_epoch()
금지 패턴   : 「공통」 표 + 목록 밖 객체를 바꾸지 않는다 · 지금 소유자가 `postgres` 일 때만 바꾼다(있고·그 소유자일 때 — DO 블록) · 권한(GRANT/REVOKE)을 새로 주거나 빼지 않는다 · 행을 건드리지 않는다.
완료 기준   : 스크래치 DB(`sudo -n -u postgres createdb …` — 운영 tbox_re 아님, 끝나면 dropdb)에 지금 운영 모양을 흉내(그 객체들을 postgres 소유 + tbox_app·tbox_backup 권한)한 뒤 마이그레이션 2회:
              ① 목록 객체 소유자 = tbox_owner ② tbox_app·tbox_backup 권한(relacl 에서 소유자 항목을 뺀 나머지)이 전과 같음 ③ has_table_privilege('tbox_owner', 그 표, 'DELETE') = true
              ④ 목록 밖 postgres 소유 객체(시험이 하나 만들어 둠)는 그대로 ⑤ 두 번째 적용 변화 0 · 기존 qf13_retention_http · qf13_consent_http 는 이 파일이 들어가도 통과 · lint 0 · git diff --check.
보고 형식   : 「공통」. 확정한 값: 마이그레이션 원문 요약 · 시험 출력 원문 · 커밋 해시.
```
