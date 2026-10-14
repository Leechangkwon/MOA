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
