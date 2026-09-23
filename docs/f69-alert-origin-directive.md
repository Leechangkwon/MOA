# F-69 지시서 — 경보에 「누가 일으켰나」 표기 + AL-6 격리 파일 오탐 수정 (2026-09-24)

> 담당: **결정 대기**(사용자). 공통 `CLAUDE.md` 2장 형식. 문서만 — 이 문서를 쓰는 동안 코드·운영 DB·`/etc`·`alerts.log` 는 바뀌지 않았다.
> 선후: **F-71(claude_ 계정)과 같은 판정 파일 `src/Origin.php` 를 쓴다** — 먼저 들어가는 쪽이 만들고 뒤쪽은 그것을 쓴다. F-70(휴대폰 경보)의 선행이다(F-70 은 `origin` 으로 즉시/하루 요약을 가른다).
> 실측(2026-09-24, `alerts.log` 889줄 읽기만): AL-10 381 · AL-9 135 · AL-7 105 · AL-14 100(옛 구매 모듈 규칙 — F-64 로 없어짐) · AL-1 74 · AL-17 27 · AL-6 13 · AL-20 11 · AL-18 12 · …
> - 앱 규칙(AL-1·10·12·13·18·20)의 대상 계정은 거의 전부 `qa_`(AL-10 370/382, AL-1 73/74).
> - AL-9(위험한 sudo)의 대상 OS 계정은 `changkwon` 121 · `root` 13 · `eric` 1 — **AI 세션과 사람이 같은 OS 계정 `changkwon` 을 쓴다.** 그래서 서버 층 경보는 계정으로 가를 수 없고 **명령이 겨눈 DB** 로만 가른다.
> - AL-6(정합성)은 09-17·09-22·09-23 에 `{"missing": 1~3}` — 운영 `files` 에 **살아 있고 `scan_status='infected'` 인 행 3개**(격리 폴더로 옮겨진 것)를 「저장소에 없음」으로 센 것. 매일 02:00 오탐.

```
목표        : 모든 경보에 origin(human · system · claude-review · claude-session)을 붙이고 Claude 가 일으킨 것은 [Claude] 로 보이게 한다.
              경보를 지우거나 끄지 않는다. AL-6 이 격리된 감염 파일을 분실로 세지 않게 고친다.

요구사항    : t-box PRD F-69(①~⑤) · F-70(⑤ 의 분류만 — 이번엔 보내지 않는다) · PRD 7.11 S-11-A(경보 규칙 AL-n) · F-54(격리) · F-71 ③④
              · ARCH 11.4(경보) · 5.14(경보 화면) · 7.5(evidence 키) · SECURITY R-73 · R-86 · CLAUDE.md 3장(검토 표식 UA `tbox-review/<날짜>`)
              화면이 바뀌면 DESIGN.md 배지(.badge) + 6장 체크리스트 (새 클래스·색값을 만들지 않는다)

수정 파일   : (허용 목록)
    src/Origin.php                    (새 파일 — 판정 표 한 곳. F-71 이 먼저 만들었으면 거기에 서버 층 갈래를 더한다)
    scripts/lib/cli_bootstrap.php     cli_raise_alert(): origin·title 을 받아 alerts.log 한 줄과 alert.raised 감사 detail 에 넣는다 · 격리 폴더 경로 함수 하나
    scripts/alerts.php                규칙마다 origin 판정 재료(대상 계정·UA·명령)를 SELECT/파싱에 더하고 $raise 로 넘긴다
    scripts/consistency_check.php     AL-6 격리 파일 갈래 (아래 C) · origin=system
    scripts/audit_chain.php           AL-6 두 곳 origin=system
    scripts/scan_pending.php          격리 폴더 상수를 cli_bootstrap 의 함수로 바꿈(경로 값은 운영에서 그대로)
    scripts/verify_backup.sh          AL-8 을 직접 쓰는 JSON 한 줄에 "origin":"system" · "title"
    src/handlers/admin_alerts.php     origin·title 을 그대로 내려 줌(없으면 null — 옛 줄)
    public/assets/app.js              경보 목록에 origin 배지(「Claude」·「시스템」), 옛 줄은 「출처 표기 전」
    tests/f69.sh                      (새 파일)  tests/f54.sh (격리 뒤 consistency_check 한 번 — 기존 흐름에 한 단계)
    ARCHITECTURE.md 11.4·5.14 · PRD F-69 행(구현 반영) · SECURITY.md(R 새 항목: origin 흉내로 경보 낮추기 — 아래 A-2) + 7장 · STAGES.md · wiki/tbox/ 글

금지 패턴   : SQL 문자열 결합 · innerHTML · 사용자 입력이 든 파일 경로 · curl_exec/file_get_contents('http…') · proc_open/shell_exec 류
              · alerts.log 의 기존 줄을 고치거나 지우는 것(증거 — append-only) · 경보 규칙을 끄거나 문턱을 올리는 것(F-69 ①)
              · origin 을 권한·차단·감사 기록 여부에 쓰는 것(보여 주기와 분류에만)

완료 기준   : 아래 D 표 F69-T1 ~ F69-T10 전부

보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유
```

---

## A. origin 판정 표 — `src/Origin.php` 한 곳

| 값 | 뜻 | F-70(나중) |
|---|---|---|
| `human` | 사람(또는 가려낼 수 없는 것) | 즉시 |
| `system` | 행위자가 없는 배치 판정(정합성·백업·감사 끊김) | 즉시 |
| `claude-review` | 검토 계정 + 검토 표식으로 앱에서 한 행동 | 하루 요약 |
| `claude-session` | AI 세션이 서버에서 임시·시험 DB 를 다룬 명령 | 하루 요약 |

### A-1 규칙별 판정

| 규칙 | 재료 | origin |
|---|---|---|
| AL-1 권한 우회 · AL-10 계정 공유 · AL-12 SSRF · AL-13 매트릭스 변경 · AL-18 허용 사이트 변경 · AL-20 토큰 발급 | 묶음의 `actor_name` + 그 행들의 `user_agent` — 집계 SQL 에 `bool_and(user_agent LIKE 'tbox-review/%')` 를 더한다(바인딩 없는 고정 문자열) | 계정이 `qa_`·`claude_` **이고** 모든 행의 UA 가 표식 → `claude-review`, 아니면 `human` |
| AL-2 브루트포스(대상 = 출발 IP) | 그 IP 의 실패 행들이 겨눈 계정·UA | 전부 검토 계정 **이고** 전부 표식 UA → `claude-review`, 아니면 `human` |
| AL-5 등급 하향(대상 = 감사 행 하나) | 그 행의 actor·UA | 위와 같은 AND |
| AL-19 악성코드 · AL-21 실행 파일 압축본(대상 = 파일) | 그 파일을 올린 감사 행의 actor·UA | 위와 같은 AND(f54 의 EICAR 시험 → `claude-review`) |
| AL-7 감사 끊김 · AL-6 정합성·해시 체인 · AL-8 백업 실패 | 행위자 없음 | `system` |
| AL-3 새 출처 SSH | OS 계정@IP | **늘 `human`**(SSH 로 들어오는 것은 사람이다 — AI 세션은 이 VM 안에서 돈다) |
| AL-9 위험한 sudo | 명령 문자열(이미 `al_scrub_command` 로 가려진 것) | 명령이 겨눈 DB 가 **전부 임시·시험 DB 이름**일 때만 `claude-session` — 이름 규칙(정규식 한 줄, 코드 상수): `_test`·`_test2`·`_scratch` 로 끝나거나, `qcheck_` 로 시작하거나, `comm_check`. 예: `dropdb --if-exists tbox_4d_test` → `claude-session`. **`tbox_re`(운영)를 겨누면 읽기 쿼리라도 `human`** · DB 이름을 가려낼 수 없으면 `human` |
| 옛 규칙 번호(AL-14·16·17 등 — 이제 발동하지 않음) | — | 판정하지 않는다 |

### A-2 왜 「그리고(AND)」인가 — PRD F-69 ② 문구와 다른 점 (사용자 확인 K-1)

PRD F-69 ② 는 「대상 계정이 `qa_` 접두**이거나** UA 가 `tbox-review/` 로 시작」이다. 그대로 짜면 **공격자가 UA 만 `tbox-review/…` 로 바꿔 자기 행동의 경보를 「Claude」로 낮출 수 있다** — F-70 이 들어오면 그 경보는 즉시 알림이 아니라 하루 요약으로 밀린다. UA 는 누구나 흉내 내는 표식이다(`src/Http.php` 457행 주석). 그래서 기본안은 **둘 다 맞을 때만** `claude-review` 다. 검토 계정의 비밀번호·TOTP 는 root 0600 파일에만 있으므로 둘 다 맞추려면 이미 서버가 뚫린 상태다(그때는 AL-3·AL-9 가 사람 경보로 뜬다). 한쪽만 맞는 경우(표식 없는 `claude_` 계정, 표식 달린 사람 계정)는 `human` 으로 두고 evidence 에 `origin_hint: "partial"` 을 남겨 화면에 「확인 필요」로 보인다.

같은 이유로 AL-9 는 운영 DB(`tbox_re`)를 읽는 명령을 사람 경보로 둔다 — 시험의 R-73 사전 검사(`sudo -u postgres psql -d tbox_re -c 'SELECT count(*) FROM audit_logs …'`)가 AL-9 소음의 큰 몫이지만, 같은 명령을 침입자가 치면 구별할 수 없다. 소음을 줄이려면 판정을 넓히는 대신 **시험이 sudo 없이 읽기 전용 역할로 읽게** 하는 쪽이 맞다(이 지시서 범위 밖 — K-3).

### A-3 표기

- `alerts.log` 한 줄: 기존 `{raised_at, rule, evidence, dispatch, request_id}` 에 **`origin`**·**`title`** 을 더한다. `title` = `claude-*` 면 `"[Claude] " + evidence.rule_text`, 아니면 `rule_text`. `origin_hint` 는 evidence 안.
- 감사 `alert.raised` detail 에 `origin`(같은 값).
- `verify_backup.sh` 가 직접 쓰는 AL-8 줄도 같은 모양(`"origin":"system"`, `title`).
- **옛 889줄은 고치지 않는다.** 화면은 origin 없는 줄을 「출처 표기 전」으로 보인다. 필요하면 `alerts.php --classify-history`(읽기만, 표준출력으로 분류 집계만 — 파일 무변경)를 둘 수 있다(선택, K-2).

## B. 화면 — 관리자 경보 목록(`GET /api/admin/alerts`, F-24)

- 행마다 origin 배지: `claude-review`·`claude-session` → 「Claude」, `system` → 「시스템」, `human` → 없음, 옛 줄 → 「출처 표기 전」(흐린 배지). `origin_hint: partial` → 「확인 필요」.
- 거르개(선택): 「사람·시스템만」 — 화면 안에서만(서버는 지금처럼 마지막 50줄).
- 전부 `textContent` · 기존 `.badge` 변형만.

## C. AL-6 — 격리된 감염 파일을 분실로 세지 않는다 (`consistency_check.php`)

**지금**: [1] 이 `files` 의 `stored_name`·`alive`·`purged` 만 읽고, [2] 가 저장소(`Storage::baseDir()`)만 훑는다. `scan_pending.php` 는 감염 파일을 `/var/lib/tbox-v2/quarantine/<stored_name>` 으로 **옮긴다**(삭제 아님, F-54 ②). 그래서 살아 있는 감염 행은 [3] 에서 `missing` 이 된다(운영 3건).

**고친 뒤**
1. [1] SELECT 에 `scan_status` 를 더한다.
2. 격리 폴더 경로는 **한 곳**: `cli_bootstrap.php` 의 함수(예: `cli_quarantine_dir()` = `dirname(Storage::baseDir()) . '/quarantine'`). 운영에서는 지금 상수와 같은 경로이고, 임시 인스턴스에서는 임시 저장소 옆을 가리켜 시험할 수 있다. `scan_pending.php` 도 이 함수를 쓴다.
3. 판정:

   | 행 | 격리 폴더에 있음 | 저장소에 있음 | 결과 |
   |---|---|---|---|
   | 살아 있음 + `infected` | ✅ | ❌ | **정상** — `quarantined` 로 센다, sha256 대조(격리 중 변조 확인 — 읽기만, 실행 0) |
   | 살아 있음 + `infected` | ❌ | ✅ | 불일치 `infected_in_storage`(옮기기 실패 — 감염 바이트가 서비스 폴더에 남음) |
   | 살아 있음 + `infected` | ❌ | ❌ | 불일치 `quarantine_missing` |
   | 휴지통 + `infected` | ✅/❌ | — | 휴지통 규칙과 같이 **보고만**(`trash_quarantine_missing` 개수) |
   | `pending`·`clean` | — | — | 지금 규칙 그대로 |
4. [4] 고아 판정은 지금처럼 저장소만. 격리 폴더에 행이 없는 파일은 **보고만**(`quarantine_orphan` 개수 — 격리 폴더는 증거 보관소라 지우지 않는다).
5. 감사 `audit.integrity` detail 에 `quarantined`·`quarantine_orphan` 개수를 더한다(문장 표 ARCH 7.5 갱신). AL-6 은 origin `system`.

## D. 완료 기준

| # | 시험 | 기대 |
|---|---|---|
| F69-T1 | `Origin` 단위 시험(표 A-1 의 칸마다 한 줄 — PHP CLI) | 모든 칸이 표대로 · 표식 UA + 사람 계정 → `human` + `partial` · `qa_` 계정 + 표식 없음 → `human` + `partial` |
| F69-T2 | 임시 DB 에 규칙별 가짜 감사 행을 넣고(검토 계정·사람 계정 두 벌) `alerts.php --state <임시>` 실행 — `alerts.log` 경로는 임시 인스턴스의 `AUDIT_LOG_FILE` 디렉터리 | AL-1·2·5·10·12·13·18·19·20·21 마다 두 벌의 origin 이 다르게 · 줄에 `origin`·`title` · Claude 쪽 title 이 `[Claude] ` 로 시작 |
| F69-T3 | 사본 auth.log(`--auth-log`)에 SSH 1줄 · `sudo … dropdb --if-exists tbox_4d_test` · `sudo … psql -d tbox_re -c 'SELECT 1'` · `sudo … psql -d qcheck_17 …` · DB 이름 없는 `sudo rm -rf /var/lib/x` | AL-3 `human` · `claude-session` · **`human`** · `claude-session` · `human` |
| F69-T4 | 감사 `alert.raised` detail | `origin` 이 줄과 같음 · 비밀값 0(`al_scrub_command` 그대로) |
| F69-T5 | `verify_backup.sh` 실패 경로(시험 인자·임시 백업 폴더) | AL-8 줄에 `origin:"system"` · 형식이 PHP 쪽과 같음(같은 파서로 읽힘) |
| F69-T6 | **옛 줄 불변** | 시험 전후 운영 `alerts.log` 의 첫 889줄 sha256 동일(시험은 임시 경로에만 쓴다) |
| F69-T7 | AL-6 — 임시 저장소·임시 격리 폴더로 표 C-3 의 다섯 경우를 만든 뒤 `consistency_check.php` | 격리된 살아 있는 감염 파일 → 불일치 0 · `infected_in_storage`·`quarantine_missing` 은 불일치 + AL-6(`system`) · 휴지통·고아는 보고만 |
| F69-T8 | AL-6 — 운영 확인(쓰기 없는 방법) | 운영 DB 를 **읽어** 같은 판정을 SQL + `ls` 로 재현: 살아 있는 `infected` 3행이 전부 격리 폴더에 있음 → 고친 코드로는 불일치 0 이 될 것을 보고. 실제 확인은 적용 다음 날 02:00 배치의 `audit.integrity` 행(읽기) |
| F69-T9 | 화면 | 헤드리스로 관리자 경보 목록(재인증 모달 포함) — 배지 네 종류 + 「출처 표기 전」 · 라이트·다크·390px 스크린샷을 직접 본다 · 문자열은 `textContent` 로만 |
| F69-T10 | 회귀·정적 | `f54.sh`(격리 흐름 + 새 한 단계) · `stage26.sh` · `help_button.sh` · `f68.sh` · `alerts.php --dry-run`(운영, 읽기만 — 발동 목록이 전과 같고 origin 만 붙음) · `security_lint.sh` 0 · `php -l` · `bash -n` · `wiki_check.php` 0 |

## E. 사용자에게 물을 것 (구현 전) — 결정 반영 2026-09-24(조율 세션 전달)

| # | 질문 | 기본안 | 결정 |
|---|---|---|---|
| K-1 | 검토 판정을 PRD 문구대로 「계정 **또는** UA」로 할지, 「계정 **그리고** UA」로 할지(A-2) | **그리고** — UA 흉내로 경보를 낮추지 못하게. PRD F-69 ② 를 고친다 | **수락** — 「계정 그리고 UA」. PRD F-69 ② 를 이 문구로 고친다(구현 세션 첫 커밋) |
| K-2 | origin 값에 `system` 을 더할지(PRD 는 셋) | 더한다 — 백업 실패·정합성·감사 끊김은 사람도 Claude 도 아니고, 즉시 알려야 한다 | **수락** — `system` 추가 |
| K-3 | AL-9 소음(시험의 운영 DB 읽기 sudo)을 어떻게 줄일지 | 판정은 넓히지 않는다. 대신 별도 과제로 「시험이 sudo 없이 읽기 전용 DB 역할(`.pgpass`, 비밀 파일 0600)로 사전 검사」 — 역할 추가는 운영 DB 변경이라 따로 승인 | **수락** — 별도 과제. 점검 봇 1단계(시험 규격화) 지시서로 넘김 |
| K-4 | 옛 889줄 분류 보고(`--classify-history`, 읽기만)를 넣을지 | 넣지 않는다(필요할 때) | **수락** — 넣지 않음 |
| K-5 | DB 역할 `tbox`(로그인 가능, 09-07 생성, AL-9 기록상 `CREATE USER tbox WITH PASSWORD 'tbox'`)와 DB `tbox` — 교육용 옛 앱(v1) 것으로 보인다(추정) | 이 지시서에서 건드리지 않는다. 쓰지 않으면 정리할지 사용자가 정한다(127.0.0.1·scram 이라 밖에서는 닿지 않음) | **그대로 둔다**(아래 사실 확인) |

> K-5 사실 확인(조율 세션 2026-09-24): 역할 `tbox` 는 교육용 취약판 v1(`/var/www/html/db.php`, `127.0.0.1:8081` 전용)이 실제로 쓴다. 운영 DB `tbox_re` 에는 CONNECT 권한이 없다(`has_database_privilege('tbox','tbox_re','CONNECT') = f`). 일부러 취약하게 둔 교육용 앱이라 **그대로 둔다**(사용자 결정). 이 역할의 sudo 기록이 AL-9 에 나오면 A-1 표대로 `human` 이다.
