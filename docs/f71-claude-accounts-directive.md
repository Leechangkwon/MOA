# F-71 지시서 — 검토·시험 계정을 `claude_` 이름으로 (2026-09-24)

> **상태: 확정(2026-09-24)** — 결정 표(E장) 전부 답함. 개발 대기열 `/var/www/qaredi/docs/dev-queue.md` 순서 1 — 담당은 대기열 규칙 2(개발 세션이 가져감). 이 지시서는 공통 `CLAUDE.md` 2장 형식이다. 문서만 — 이 문서를 쓰는 동안 코드·운영 DB·`/etc` 는 바뀌지 않았다.
> 사이트 PRD Q:F-4(속도·편의 작은 수정 묶음)의 **선행**이다 — 그 묶음의 새 시험은 `claude_` 계정을 쓴다.
> 실측(2026-09-24): 운영 DB 에 `claude_` 계정 **0개**. `qa_` 계정 5개(+ 시험 잔여 `qa_f64_1789961456`), `/etc/tbox-v2/seed-accounts.txt` 10줄(시드 5 + qa 5, `username role password`), `/etc/tbox-v2/qa-totp.txt` 5줄(`username secret`), 둘 다 root 0600.

```
목표        : 검토 계정 5개를 claude_ 이름으로 새로 만들어 첫 로그인 절차까지 마치고, 「검토 계정」 판정을 qa_ 또는 claude_ 로 넓힌다.
              새로 쓰거나 고치는 시험은 claude_ 를 쓴다. qa_ 계정은 지우지 않는다(옛 기록 확인용).

요구사항    : t-box PRD F-71(①~④) · ARCH 4.0(계정 세 갈래 표) · PRD S-07(검토 요청 표식) · S-09-4(Argon2id) · S-09-6(비밀 미커밋)
              · F-16(최초 로그인 강제 절차) · F-52(Admin 2단계 필수) · SECURITY R-33(seed-accounts 평문 수명) · R-48(틀린 OTP 5회 = 10분 잠금)
              · R-73(시험 사전 검사 「최근 10분 비-검토 감사 행」) — 해당 절을 읽고 시작할 것
              화면이 바뀌면 DESIGN.md 2장 배지(.badge) + 6장 체크리스트 (새 클래스·색값을 만들지 않는다)

수정 파일   : (허용 목록. 여기 없는 파일은 만들거나 고치지 않는다)
  계정 만들기
    scripts/seed_users.php                      $claudeAccounts 표 + 정확 일치 인자 `--claude-only`, `--no-qa` 는 claude 도 뺀다, 머리 주석
    /var/www/qaredi/scripts/apply-f71-claude-accounts-<날짜>.sh   (새 파일 — 운영 적용 스크립트, --dry-run 필수)
    scripts/review_account_setup.php            (새 파일 — 첫 로그인 절차를 창구로 마치는 CLI, 아래 B)
  판정 넓히기
    tests/lib/review_accounts.sh                (새 파일 — 판정 SQL 조각·bash 함수 한 벌)
    tests/api_folders.sh  tests/api_read.sh  tests/api_tokens.sh  tests/api_upload.sh  tests/api_whoami.sh
    tests/f46.sh  tests/f51.sh  tests/f52.sh  tests/f53.sh  tests/f54.sh  tests/f65.sh  tests/f66.sh  tests/f68.sh
    tests/fetch_hosts.sh  tests/q_launcher.sh  tests/stage26.sh  tests/wiki_books.sh
    tests/community/stage3.sh  tests/community/stage4a.sh
      → **판정 줄만** 바꾼다(아래 C 표). 계정 이름(qa_employee01 등)은 이번에 바꾸지 않는다
    src/Origin.php                              (새 파일 — 「누가 일으켰나」 판정 한 곳, F-69 와 공유. F-69 지시서가 먼저 들어가면 그 파일을 쓴다)
    src/handlers/audit_list.php                 행마다 `origin` 칸(서버가 판정)
    public/assets/app.js                        감사 목록에서 origin 이 claude-* 인 행에 배지 「Claude」
    src/handlers/audit_page.php                 입력칸 placeholder 예시(qa_employee01 → claude_employee01)
  문서
    ARCHITECTURE.md 4.0 · PRD S-07 문구 · SECURITY.md R-73 문구 + 7장 한 줄 · README.md(설치 7절 계정 설명) · STAGES.md · wiki/tbox/ 글

금지 패턴   : SQL 문자열 결합 · innerHTML · 사용자 입력이 든 파일 경로 · curl_exec/file_get_contents('http…')
              · 역할 판단을 Authz 밖에서 · 요청 파라미터의 role 값 사용
              · **psql 로 users 에 직접 INSERT/UPDATE**(F-71 ①) · 비밀번호·TOTP 비밀을 표준출력·명령 인자·로그·감사 detail 에 남기는 것
              · 안쪽 명령을 sudo 로 다시 부르는 것(auth.log 의 `COMMAND=` 에 인자가 남는다 — 스크립트는 `sudo -n bash <스크립트>` 한 번으로)
              · 틀린 비밀번호·OTP 를 되풀이해 보내는 것(R-48) — 실패하면 그 계정에서 곧바로 멈춘다

완료 기준   : 아래 D 표 F71-T1 ~ F71-T9 전부

보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문(비밀값은 「(가림)」) / 못 한 것과 이유
```

---

## A. 계정 만들기 — `seed_users.php` (운영 DB 쓰기: 사용자 승인 뒤, HANDOFF 7장 방식)

| 계정 | 역할 | 팀 | 만료 |
|---|---|---|---|
| `claude_admin01` | Admin | 없음 | 없음 |
| `claude_manager01` | Manager | 개발팀 | 없음 |
| `claude_employee01` | Employee | 개발팀 | 없음 |
| `claude_employee02` | Employee | 영업팀 | 없음 |
| `claude_guest01` | Guest | 없음 | 만들 때부터 90일(DB CHECK `guest_requires_expires_at`, 기존 `$guestDays`) — **만료일을 보고서에 적는다**(그 뒤 시험이 깨진다. 연장은 Admin 창구 F-10, 사유와 함께) |

- `qa_` 표와 **같은 역할·팀**(ARCH 4.0). 이름은 `users.username` CHECK `^[a-z0-9_.-]+$` 통과.
- 인자: `--claude-only` = 이 5개만(시드·qa 는 보지도 않는다). **정확히 일치할 때만** 인정(기존 `--no-qa` 와 같은 규칙). `--no-qa`(운영 배포) = 검토 계정 전부 안 만듦 — qa·claude 둘 다. 인자 없는 기본 동작(새 설치)은 시드 + qa + claude.
- 멱등: 이미 있는 username 은 건드리지 않는다(기존 규칙). 새로 만든 것만 `username role password` 한 줄씩 표준출력 — **이 출력은 적용 스크립트가 바로 root 0600 파일로 받는다**(화면에 찍지 않는다).
- 대안(사용자가 고르면): Admin 창구 `POST /api/users`(재인증·사유 필수) — `qa_admin01` 이 5개를 만들고 응답의 `temp_password` 를 받는 방식. 감사에 「누가 만들었나」가 남는 장점, 재인증·TOTP 흐름을 스크립트가 따라야 하는 단점. **기본안은 seed_users.php**.

### 적용 스크립트 `apply-f71-claude-accounts-<날짜>.sh` (5-A·6-A 적용 스크립트가 본보기)
1. 사전 확인: `claude_` 계정 0개 · 팀 「개발팀」「영업팀」 활성 · `/etc/tbox-v2/seed-accounts.txt`·`qa-totp.txt` 가 root 0600 · 사이트 사용 중이 아님(최근 10분 비-검토 감사 행 0 — C 의 넓힌 판정으로).
2. `--dry-run`: 할 일만 찍고 끝(DB·파일 무변경).
3. 백업: 두 비밀 파일을 `…​.bak.<시각>`(0600)로 복사. DB 는 `users` 구조만(`pg_dump -s -t public.users`) — 데이터는 비밀이 있어 뜨지 않는다(HANDOFF 7장 3).
4. `umask 077` 에서 `php scripts/seed_users.php --claude-only > <임시 0600 파일>` → 5줄인지 확인 → `seed-accounts.txt` 에 덧붙임 → 임시 파일 `shred -u`.
5. B 절차(`review_account_setup.php`) → 5계정 모두 `must_change_password=false`·`mfa_setup_required=false`·`mfa_enabled=true` 확인(SELECT 만).
6. 사후 확인: D 표 F71-T1~T4. 하나라도 어긋나면 멈추고 보고(계정을 지우지 않는다 — 비활성은 사용자 판단).

## B. 첫 로그인 절차 — `scripts/review_account_setup.php`

새 계정은 `must_change_password`·`mfa_setup_required` 가 켜져 있다(F-16). `qa_` 때는 사람이 손으로 마쳤다(STAGES 11단계 기록). 이번에는 CLI 하나로 **창구를 그대로 타서** 마친다 — DB 를 직접 고치지 않는다.

| 순서 | 창구(운영 `127.0.0.1:8082`, UA `tbox-review/<날짜>`) | 비고 |
|---|---|---|
| 1 | `POST /tbox/api/login` `{username, password: 임시}` | 실패하면 그 계정 멈춤(재시도 0) |
| 2 | `POST /tbox/api/me/password` → 새 비밀번호(무작위 16바이트 hex) | 같은 값 400 규칙 |
| 3 | `POST /tbox/api/me/mfa/enroll` → 비밀 | 응답 본문을 메모리에서만 |
| 4 | `src/Totp.php` 로 코드 계산(명령 인자로 넘기지 않는다) → `POST /tbox/api/me/mfa/confirm` | |
| 5 | `seed-accounts.txt` 의 그 계정 줄을 새 비밀번호로 **바꾼다**(임시 비밀번호 수명 끝 — R-33) · `qa-totp.txt` 에 `username secret` 한 줄 | 파일 이름 `qa-totp.txt` 는 그대로 둔다(시험 20여 개가 이 이름을 읽는다) |
| 6 | 로그아웃 → **다음 30초 창까지 기다린 뒤** 새 비밀번호 + 새 코드로 다시 로그인해 `GET /tbox/api/me` 200 | 같은 창의 코드 재사용은 `code_reused` 로 막힌다(설계대로) |

- CLI 만(`PHP_SAPI` 검사, `seed_users.php` 와 같은 머리), 외부 요청 없음(127.0.0.1 만 — 호스트는 코드 상수), 비밀은 표준출력·인자·로그 0.
- 커뮤니티 첫 진입(개인 공간 자동 생성)은 하지 않는다 — 시험이 필요할 때 만든다.

## C. 「검토 계정」 판정을 넓히는 곳

**판정은 한 번에 전부 넓힌다.** 계정 이름 바꾸기는 파일을 고칠 때마다 조금씩이지만, 판정은 그렇게 하면 안 된다 — `claude_` 시험이 도는 동안 옛 시험의 R-73 사전 검사가 그 행을 「사람이 쓰는 중」으로 세어 멈추고, 거꾸로 사람 행동을 검토로 잘못 세면 안전장치가 풀린다.

| # | 자리 | 지금 | 바꾼 뒤 |
|---|---|---|---|
| C-1 | 시험 사전 검사(R-73) 「최근 10분 비-검토 감사 행」 — 19개 파일(수정 파일 목록의 `tests/…` 전부, `grep -rl "NOT LIKE 'qa" tests` 로 다시 확인) | `actor_name NOT LIKE 'qa\_%'`(파일에 따라 `'qa\\_%'` 로 이스케이프가 다르다) | `tests/lib/review_accounts.sh` 의 `REVIEW_NOT_SQL` 한 조각: `actor_name NOT LIKE 'qa\_%' AND actor_name NOT LIKE 'claude\_%'` — 각 파일은 `source` 해서 쓴다. **파일마다 바꾸기 전후 같은 쿼리의 결과 수를 대조**(이스케이프 실수 방지) |
| C-2 | 시험 뒤 「사용자 행동 0」 대조(`NONQA_BEFORE/AFTER`) — f46·f51·f52·f65·fetch_hosts | 같음 | 같은 조각 |
| C-3 | 토큰을 쓰는 계정 고르기 `case … qa_*)` — api_folders·api_read·api_tokens·api_whoami·f66 | `qa_*)` | `qa_*|claude_*)` |
| C-4 | 시험 찌꺼기 정리·집계 — stage26.sh(`QA_IDS`)·f65.sh(`u.username LIKE 'qa\_%'`)·community/stage3.sh·stage4a.sh(「남은 qa 페이지」)·wiki_books.sh(라우트 집계) | `LIKE 'qa\_%'` | `(… LIKE 'qa\_%' OR … LIKE 'claude\_%')` — **정리 SQL 은 여전히 시험 접두(F65- 등) + 검토 계정 둘 다**를 조건으로(R-73 — 표 통째 삭제 금지) |
| C-5 | 감사 화면 「누가」 | 이름만 | 서버가 `origin`(아래 표)을 붙이고 `claude-*` 면 배지 「Claude」(F-71 ④) — 판정은 `src/Origin.php` 한 곳(F-69 와 같은 함수) |
| C-6 | `seed_users.php` 머리 주석 「`actor_name LIKE 'qa\_%'` 이면 검토 행동」 | qa 만 | qa 또는 claude |
| C-7 | 문서: ARCH 4.0 · PRD S-07 · SECURITY R-73 · README 설치 7절 · 점검 봇 PRD(시험 계정) | qa 만 | qa(옛 기록) + claude(새 시험) |
| C-8 | **공통 `CLAUDE.md` 3장**(「검토 계정(`qa_admin01` 등 `qa_` 접두 5개)으로만」) · t-box `CLAUDE.md` · `HANDOFF.md` 7장 8 · 커뮤니티 PRD 10장 | qa 만 | 「`claude_`(새 시험) 또는 `qa_`(옛 시험)」 — **지침 파일은 사용자 몫**: 자동 모드가 세션의 자기 지침 수정을 막고, HANDOFF 2장 4 도 금지한다. 구현자는 바꿀 문구를 보고서에 적어 사용자에게 넘긴다 |

`src/Origin.php` 판정(두 지시서 공통 — 먼저 들어가는 쪽이 만든다):

| 입력 | origin |
|---|---|
| 앱 행동: `actor_name` 이 `qa_` 또는 `claude_` 로 시작 **그리고** `user_agent` 가 `tbox-review/` 로 시작 | `claude-review` |
| 둘 중 하나만 맞음 | `human` + `origin_hint: "partial"`(화면에 「확인 필요」) |
| 그 밖의 앱 행동 | `human` |
| (서버 층·배치 경보는 F-69 지시서 A-1 표) | — |

「그리고」인 이유: UA 표식은 누구나 흉내 낼 수 있다(`Http.php` 457행 주석 — 「표식이지 인증이 아니다」). 「또는」이면 공격자가 UA 만 바꿔 자기 행동을 「Claude」로 보이게 하고, F-70 이 들어오면 그 경보가 즉시 알림에서 빠진다(F-69 지시서 A-2 · 사용자 확인 K-1 — PRD F-69 ② 의 「또는」을 고치는 안). 검토 요청은 원래 UA 표식이 필수라(`CLAUDE.md` 3장) 정상 시험은 늘 둘 다 맞는다. 이 판정은 **보여 주기와 알림 분류에만** 쓰고, 권한·차단·감사 기록 여부에는 절대 쓰지 않는다. R-73 사전 검사(C-1)는 **계정 이름만** 본다 — 거기서는 「사람이 쓰는 중이면 멈춘다」 쪽이 안전하고, UA 는 감사 행마다 다르지 않아도 되기 때문이다.

## D. 완료 기준

| # | 시험 | 기대 |
|---|---|---|
| F71-T1 | `SELECT username, role, team_id, expires_at IS NOT NULL, must_change_password, mfa_setup_required, mfa_enabled FROM users WHERE username LIKE 'claude\_%' ORDER BY 1` | 5행 — 역할·팀이 A 표와 같음, guest 만 만료 있음, 절차 플래그 false, mfa true |
| F71-T2 | 감사 로그 | 계정 생성·비밀번호 변경·2단계 등록 행이 각 계정에 있고 detail 에 비밀값 0(`grep` 으로 새 비밀번호·비밀 원문 0건) |
| F71-T3 | 비밀 파일 | `seed-accounts.txt` 15줄(claude 5줄은 **바뀐** 비밀번호), `qa-totp.txt` 10줄, 둘 다 root 0600 · 백업 파일 0600 · 임시 파일 없음 · `sudo grep -c` 로 `auth.log` 에 비밀 원문 0건 |
| F71-T4 | 각 계정으로 로그인(파일의 비밀번호 + 코드) → `GET /tbox/api/me` | 200, 역할·팀 맞음 · Guest 는 public 만(`stage26` 식 목록 원문 대조 한 번) |
| F71-T5 | `seed_users.php` 인자 | `--claude-only` 두 번째 실행 → 새 줄 0 · `--claude` (정확하지 않은 인자) → 사용법 오류 · `--no-qa` → qa·claude 둘 다 안 만듦(임시 DB) |
| F71-T6 | 판정 넓힘 | 임시 DB 에 `claude_employee01` 행을 만든 뒤 C-1 조각 → 0(검토로 셈), 가짜 사람 계정 행 → 1 · 19개 파일 `bash -n` · 바꾸기 전후 같은 쿼리 결과 수 동일(운영 사본) |
| F71-T7 | 회귀(판정만 바뀌었으므로 통과 수 **전과 같아야**) | `stage26.sh` · `help_button.sh` · `f68.sh` · `api_*.sh` · `q_launcher.sh` · `wiki_books.sh` · `tests/community/stage3.sh`·`stage4a.sh` |
| F71-T8 | 감사 화면(C-5) | 헤드리스로 `/tbox/audit` — claude_·qa_·UA 표식 행에 배지 「Claude」, 사람 행에 없음 · `origin` 은 서버 응답에 있고 화면은 `textContent` 로만 · 라이트·다크·390px 스크린샷을 직접 본다 |
| F71-T9 | 정적 | `security_lint.sh` 0 · 바뀐 `.php` `php -l` · `wiki_check.php` 0 |

## E. 사용자에게 물을 것 (구현 전) — 결정 반영 2026-09-24(조율 세션 전달)

| # | 질문 | 기본안 | 답 |
|---|---|---|---|
| K-1 | 계정을 seed_users.php 로 만들까, Admin 창구로 만들까 | seed_users.php(A) | **수락** — seed_users.php |
| K-2 | `claude_guest01` 만료(90일)를 어떻게 | 90일 그대로, 만료 2주 전 보고서·STAGES 에 알림 — 연장은 사용자가 Admin 창구로 | **수락** — 90일 · 만료 알림 |
| K-3 | 남은 시험 잔여 계정 `qa_f64_1789961456`(Employee, 활성 여부 확인 필요) | 이번에 건드리지 않는다 — 보고만 | **수락** — 보고만 |
| K-4 | C-8 지침 문구(공통 CLAUDE.md 3장 등) | 사용자가 직접 고친다(자동 모드 제약) | **이 지시서 범위 밖** — 지침 파일은 고치지 않고, 구현자가 바꿀 문구를 보고서에 적어 넘긴다(C-8). 문구 결정은 사용자(현황판 결정 6번) — 구현을 막지 않는다 |
