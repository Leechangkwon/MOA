# 디버깅 수정 묶음 T — 기능 점검의 「시험 결함」 고치기 (2026-09-26)

| 항목 | 내용 |
|---|---|
| 근거 | 취합 세션(changkwon-32) 지시 — 기능 점검(2026-09-26 09:27~13:16) 결론 「운영 코드의 진짜 결함 0 · 시험 결함 8 · 환경 4 · 흔들리는 시험 1」 중 시험 결함·환경·흔들림을 고친다 |
| 작업 자리 | 복제본 `~/.local/share/qaredi-dev/d1tw` — 기준 운영 t-box `a57cf6b`(v2.29.0). 작성자 claude |
| 범위 | **`tests/` 와 시험 카탈로그만**(+ 이 기록 · STAGES 0장 한 줄). 운영 코드(`src/`·`public/`·`scripts/`)·`deploy/`·`db/` 변경 0 |
| 운영 반영 | 하지 않았다 — 취합이 기존 틀로 한다(반영 스크립트 만들지 않음). 시험 파일만이라 웹 앱이 읽는 파일 변화 0 |

규칙 그대로: 운영 트리·운영 DB(`tbox_re`)·운영 포트(80·8082·8081)·`/etc` 에 쓰지 않았다(`pg_dump` 읽기 파이프·설정 파일 읽기만).
시험은 임시 DB `tbox_d1t_*`(시험 틀이 만들고 지움) · 포트 8231~8239 의 임시 인스턴스(`php -S`·비root php-fpm·nginx)에서만.
임시 `.env` 원본은 mktemp 폴더(0700)의 0600 사본 — `DB_NAME`·`STORAGE_DIR`·`AUDIT_LOG_FILE` 을 먼저 임시 값으로 바꿔 둔 것(끝에 shred).
운영을 치는 시험(env=prod · writes_prod · manual_only)은 돌리지 않았다.

---

## 1. 나란히 도는 복제본을 위한 시험 틀 손잡이 (공통 — 기본값은 전과 같다)

이번 규칙(임시 DB 접두 `tbox_d1t_` · 포트 8231~8239 · 복제본에는 `.env` 없음) 안에서 고친 시험을 돌리려면 손잡이가 있어야 했다.
**기본값은 한 글자도 바뀌지 않는다** — 손잡이를 주지 않으면 전과 같은 이름·포트·`.env` 자리를 쓴다.

| 손잡이 | 쓰는 시험 | 뜻 |
|---|---|---|
| `QTEST_DB_PREFIX`(기본 `tbox_`) | `community/qf3a.sh` · `qf4b1.sh` · `f70.sh` · `qf4_p7_timeouts.sh` · `sec4.sh` | 임시 DB 이름 앞 `tbox_` 자리. 모양 `^tbox_([a-z0-9]+_)*$` 만 받는다(아니면 rc 3) — `tbox_re` 가 될 수 없고, SQL 에 끼워도 따옴표·세미콜론이 없다 |
| `QF4B1_PORT` · `F70_PORT`·`F70_RPORT`·`F70_PUSH_PORT` · `P7_P80`·`P7_P82`·`P7_P84` | 고정 포트였던 셋 | 운영 포트(80·443·8081·8082)·빈 값·서로 같은 값은 rc 3 |
| `QF2_ENV_SRC` | `qf4b1.sh` · `f70.sh` · `qf4_p7_timeouts.sh`(다른 수락 시험은 이미 있었다) | 임시 `.env` 를 만들 원본 |
| `QF3A_SMOKE_RUNS`(기본 1) | `community/qf3a.sh` | 화면 시험(T9)을 같은 인스턴스에서 N 번 잇달아 — 끝에 「실패한 회수 0/N」 한 줄(흔들림 확인) |
| `P7_SCRIPTS_DIR` | `qf4_p7_timeouts.sh` | ⑦ 반영 스크립트 자리(4장) |
| `QAREDI_SITE_ROOT` · 인자 | 새 `tests/wiki_check.sh` · `f70.sh` T10 | 위키 검사의 사이트 루트(환경 ① — 5장) |

## 2. 시험 결함 일곱 — 고친 것

| # | 시험 · 자리(93b3111 기준 줄) | 원인 | 고친 것 |
|---|---|---|---|
| 1 | `tests/browser/qf3a_favorites.py:143 · :182` | `.all()` 로 그 순간 줄 n 개의 nth 로케이터를 잡은 사이 목록이 적은 줄로 다시 그려지면, 사라진 nth 줄의 `get_attribute` 가 Playwright 기본 **30초**를 기다려 `wait_until` 8초 창을 넘겼다(10번에 3번) | `wait_rows()` — **`wait_for_function` 한 번(8초)** 이 페이지 안에서 쪽 글(「<보기> n건」)과 줄의 `data-fav-file` 을 **같은 평가**로 본다. 목록은 `drawPlainList` 가 한 JS 작업 안에서 줄과 쪽 글을 함께 바꾸므로 반쯤 그려진 목록·앞 보기의 줄을 읽지 않는다. 줄 수가 바뀌어도 멈추는 호출이 없다 |
| 2 | `tests/qf4b1.sh:438` 「쿠키 런처 HTML 이 전과 같다」 | 기준 판 `9dcd2e7` 뒤에 `1bf8964`(브라우저 강제 다크 막기)가 `<meta name="color-scheme" content="light dark">` 를 **의도적으로** 넣음 | 기준 판은 그대로 두고 **의도적 변경 목록**(한 줄 통째 — 지금은 그 한 줄, 커밋 이름과 함께)만 양쪽에서 빼고 지문을 견준다. 그 줄이 후(이 트리)에 **실제로 있는지**도 단언한다(목록이 차이를 감추기만 하는 헛돎 방지). 목록 밖 차이는 그대로 실패 |
| 3 | `tests/qf4b1.sh:647` 「세션에 상태를 남기는 SQL 0」 | 식이 `SET LOCAL` 까지 잡음 — `src/community/handlers/search.php:66-74` 의 `SET LOCAL enable_seqscan`(routes.php 상수)은 `$db->begin()` 안이라 트랜잭션 끝에 풀린다 | 식에서 `SET LOCAL` 을 뺐다. 대신 옛 식이 **못 잡던** 트랜잭션 밖 **맨 SET**(SQL 문자열 첫머리의 `SET 설정 = 값`·`SET 설정 TO 값`)을 새로 잡는다 — UPDATE 의 `SET` 은 문자열 첫머리가 아니라 걸리지 않는다(저장소 전체 실측 0). **음성 시험 한 줄**: 탐침 파일 다섯 줄 중 맨 SET 둘 · SET SESSION 은 잡고(2·3·4번 줄) SET LOCAL · UPDATE 의 SET 은 넘긴다. 옛 식은 같은 탐침에서 4·5번(SESSION·LOCAL)을 잡고 맨 SET 둘을 놓쳤다 |
| 4 | `tests/f70.sh` 실패 9 — B1 두 건(784~788) · T11·AL-22 일곱 건(1259~1316) | 운영에 F-70 이 반영된 뒤라 운영 사본에 받기 표 둘 · **실제 받기**(운영 휴대폰 주소) · 대기표 줄 · 운영 역할 `tbox_push` 권한이 딸려 옴 → B1 보낸 수 7(기대 6) · 「마이그레이션 전 사본」(T11) 전제가 깨짐 | ① 준비에서 **임시 DB 에서만** 마이그레이션이 더한 것을 걷어 「마이그레이션 전」을 시험이 직접 만든다 — `DROP TABLE alert_push_outbox, alert_push_subscriptions`(받기·대기표 줄과 그 권한이 함께 사라짐) · `tbox_push` 의 users·audit_logs·순서·스키마 권한 REVOKE. 단언: 표 0 · `tbox_push` 권한 0. T11 사본(`createdb -T`)은 그 **뒤에** 떠서 같은 전 상태. 걷은 받기 주소는 읽지도 적지도 않는다(개수만 note). **끝에 새 절 「운영 받기로 간 요청 0」** — 가짜 푸시 서버가 받은 요청 중 시험 주소(`/f70/…`) 밖 0 · 대조가 헛돌지 않았다(요청 30건 이상) · 임시 DB 받기 표 줄이 모두 시험 주소(시험 사본의 이름 풀이가 fcm·apple 을 가짜 서버로 보내므로, 운영 받기가 남아 있었다면 여기에 `/f70/` 밖 경로로 찍힌다) |
| 5 | `tests/qf4_p7_timeouts.sh:495` | `runf` 가 `if [ "$FPM_HARNESS" = 1 ]` 절 안에서만 정의돼, FPM 사본 시험을 건너뛰면(운영 /etc 가 이미 새 판이고 반영 전 판 해시를 저장소 이력에서 못 찾음 — 지금 운영이 그렇다) DB 절(588행~)이 `runf: command not found` 9건 | `runf` 를 맨 위 도우미 자리로 옮겼다(FPM·DB 절이 함께 씀) |
| 6 | `tests/sec4_r31_nginx.sh:104-105 · :210` | `grep -c 'if ($tbox_via_proxy = 0)'` 가 주석 줄까지 셈 — 임베드 `260d036` 이 `deploy/sites/tbox-v2.conf:393` 에 그 글자가 든 설명 주석을 더함 → 1 이 2 로 | `count_directive()` — `#` 로 시작하는 줄을 뺀 지시어 줄만 센다(N2 사본의 map·if · N3 관문 한 곳). **자기 확인 한 줄**: 주석 줄 하나 + 지시어 줄 하나 탐침 → 지시어 셈 1 · 옛 글자 셈 2 |
| 7 | `tests/sec4.sh:145` 「마이그레이션 전 칸 없음」 | 운영에 `users.temp_password_issued_at` 이 이미 있어 사본 전제가 낡음 | 4번과 같은 방식 — 사본에 칸이 있으면 **임시 DB 에서만** `ALTER TABLE users DROP COLUMN temp_password_issued_at`(마이그레이션이 더한 것은 그 칸 하나뿐)으로 전 상태를 만들고 note 에 「운영 사본 1칸 → 시험이 만든 전 상태」. 뒤의 「기존 행 전부 NULL」·「다시 돌려도 오류 없음」은 그대로 참이 된다 |
| 8 | `perf.qf2_search` | 결함 아님 — d45(2026-09-25 「영문 검색 속도는 DB 업그레이드 뒤로」)로 받아들인 영문·숫자 파일 검색 p95 가 부하 중 경계값 | **돌리지 않았다.** 카탈로그에 `accepted_limit {decision: d45, since, text}` 와 notes 머리 「known 한계 — d45」만. 단언·status(advisory)는 그대로. `fields` 에 `accepted_limit` 뜻을 한 줄 더함(요약기는 읽지 않는다 — 사람·취합이 판정할 때 본다) |

## 3. 환경 넷

| # | 무엇 | 고친 것 |
|---|---|---|
| ① | `core.wiki_check` 가 저장소 밖 `/var/www/qaredi/STAGES.md`·`community/STAGES.md` 를 요구 — 복제본에선 「파일이 없다」 2건 실패 | 운영 스크립트 `scripts/wiki_check.php` 는 그대로 두고 새 틀 **`tests/wiki_check.sh [사이트 루트]`** — 사이트 루트 = 인자 → `QAREDI_SITE_ROOT` → 저장소 부모(두 파일이 있을 때만). 부모면 그대로, 다른 자리면 임시 폴더(0700)에 저장소 사본을 두고 형제 자리에 **읽기 링크** 둘(`f69.sh`·`sec4.sh`·`qf2_search.sh` 와 같은 방식). 못 찾으면 **「환경 없음 — 건너뜀(실패 아님)」 · `RESULT pass=0 fail=0 skip=1` · rc 0**(요약기 ③ 미검증). 운영 사이트 폴더로 **저절로** 넘어가지 않는다. 카탈로그 `core.wiki_check` cmd 를 이 틀로 · `f70.sh` T10 도 이 틀(건너뜀이면 skip 으로 셈) |
| ② | `qf4_p7_timeouts.sh` 가 형제 폴더 `../scripts/apply-*-p7` 을 요구 — 없으면 시작 자체를 거부(rc 3) | 자리 = `P7_SCRIPTS_DIR` → `review-scripts/` → 트리 형제 `scripts/`. 둘이 없으면 **⑦ 만** 「환경 없음 — 건너뜀(실패 아님)」, ①~⑥ 은 그대로 돈다(`HAVE_SH`) |
| ③ | `sec4_r31_nginx.sh` 가 긴 TMPDIR 에서 nginx 소켓 경로 초과(`too long path in the unix domain socket` — 없는 FastCGI 소켓도 설정을 읽을 때 108바이트를 따진다) | 임시 폴더를 `mktemp -d /tmp/r31.XXXXXX`(짧은 자리)로 · 소켓 경로 100바이트 넘으면 rc 3 |
| ④ | 정적 규칙 R29 부하 중 시간 초과 | `tests/static/fixtures/R29/expect.txt` 다섯 견본의 `max_s=3` → `max_s=10`(selftest 는 그 두 배 + 2초에서 끊음). 한가할 때 0.1초대 · 고치기 전 되돌이 폭발은 30초 넘게 멈췄으므로 10초도 여전히 갈린다. 규칙 코드 변경 0 |

## 4. 카탈로그 밖 수락 시험 → `tests/catalog.json`

| id | cmd | env · status · needs | 비고 |
|---|---|---|---|
| `tbox.qf3b` | `bash tests/qf3b_tbox.sh` | temp-instance · required · sudo | 라운드 B t-box 쪽 |
| `community.qf3b` | `bash tests/community/qf3b.sh` | temp-instance · required · sudo, browser | 라운드 B 커뮤니티 + 두 앱 화면 |
| `community.qf3a_bulk` | `python3 tests/browser/qf3a_bulk.py` | temp-instance · helper · browser | 부르는 셸 community.qf3b |
| `tbox.sec4` | `bash tests/sec4.sh` | temp-instance · required · sudo, browser | 보안 넷 수락 |
| `tbox.sec4_r31_nginx` | `bash tests/sec4_r31_nginx.sh` | temp-instance · required · nginx | sudo·DB 없음 — 임시 nginx(이 사용자) |
| `tbox.qf3c_zip` | `bash tests/qf3c_zip.sh` | temp-instance · required · sudo | 라운드 C zip (QF3C_BROWSER=1 일 때만 화면) |
| `tbox.qf3c_zip_browser` | `python3 tests/browser/qf3c_zip.py` | temp-instance · helper · browser | 부르는 셸 tbox.qf3c_zip |

모두 `writes_prod: false`(임시 DB·임시 인스턴스만 — 운영에 닿는 것은 `pg_dump` 읽기와 `/etc/tbox-v2` 비밀 awk). covers 는 기존 규칙대로 스크립트가 이름으로 부르는 저장소 파일 + 부르는 창구의 핸들러(메서드가 분명한 것만).
`requirements` 는 머리 주석에 T-·C:T-·Q:T- 번호가 없어 비움(QF3A-T·S4 번호 체계). 고친 항목(community.qf3a · tbox.qf4b1 · tbox.qf4_p7_timeouts · tbox.f70)의 notes 끝에 이번에 고친 것·손잡이를 한 줄씩.
남은 카탈로그 밖: `tests/static/*.py`·`selftest.sh` 23개(점검 봇 정적 규칙 — 봇이 `tests/static/selftest.sh` 로 돌린다. 이번 범위 밖).

## 5. 9번(추가 — 오늘 사고) 운영 파일에 쓰는 시험의 가드

**사고**: 13:52 다른 묶음의 회귀 틀이 `tests/f51.sh`·`tests/f52.sh` 의 BASE·DB 만 임시로 바꿔 임시 인스턴스를 상대로 돌렸다.
두 시험은 2단계를 다시 등록한 뒤 운영 `/etc/tbox-v2/qa-totp.txt` 를 `sudo install` 로 새 비밀(임시 DB 의 것)로 덮어써 운영 검토 계정
(claude_employee01 · claude_admin01) 로그인이 깨졌다(취합이 16:57 복구). 이 묶음의 첫 p7 실행(13:55) 로그인 실패 5건도 이것 때문이었다(14:00 확인 —
운영 DB 의 두 계정 비밀 해시 ≠ 파일 해시, 값은 찍지 않음).

**같은 부류 grep**(`tests/` 전체 — `sudo install|tee|cp|mv|rm|chmod|touch|mkdir…` · `/etc/tbox-v2` · `/var/lib/tbox-v2` · `/var/log/tbox-v2` 에 쓰는 곳):
운영 자리에 **쓰는** 곳은 셋 — `f51.sh` · `f52.sh`(qa-totp.txt `sudo install`) · `stage26.sh` 정리(`sudo rm /var/lib/tbox-v2/storage/…` — S26- 파일 바이트).
나머지(verify_backup_fresh·f69·alerts_dryrun_offset 의 `sudo rm/mkdir/touch`)는 모두 `mktemp` 자리 · `/etc/tbox-v2`·`/var/log/tbox-v2` 는 읽기(awk·cat·wc·head·grep)뿐.

| 고친 것 | 내용 |
|---|---|
| 새 `tests/lib/prod_guard.sh` | `pg_target_kind` — prod(BASE 가 운영 검토 주소 **그리고** DB `tbox_re`) · temp(둘 다 아님) · unknown(운영 검토 포트를 못 읽음 · 하나만 운영). 운영 검토 포트는 **저장소 `deploy/sites/tbox-v2-local.conf` 의 listen 에서 읽는다** — 숫자를 글자로 적지 않아 회귀 틀의 글자 치환(`s#127.0.0.1:<운영 포트>#…#g`)이 BASE 와 함께 이 기준까지 바꾸는 일이 없다. `pg_is_prod_path`(링크를 푼 자리도) · `prod_write_guard <경로>` — 운영 자리면 운영 대상 **그리고** 운영 앱 `.env`(`/var/www/qaredi/tbox-v2/.env`)의 `DB_NAME` = 시험 DB 일 때만 통과 · 운영 저장소 경로 상수 `PG_PROD_STORAGE`(시험 본문에 글자로 두지 않음 — 회귀 틀의 「운영 저장 경로가 남았나」 검사 오탐 방지) |
| `f51.sh` · `f52.sh` | 비밀 파일 자리를 변수로 — 읽는 자리 `QA_TOTP_FILE`(주면) 아니면 운영 파일(읽기). **쓰는** 자리: 운영 대상 → 운영 파일 · 임시 대상 → `QA_TOTP_FILE` 을 주었으면 그 자리, 아니면 **쓰지 않고 이 실행의 메모리에만**(`qa_secret` 이 그 값을 돌려줌) · unknown → 시작하지 않음(rc 3 — 「운영이 아니다」로 잘못 보고 운영 계정 2단계만 바꾸고 파일은 옛 값으로 두는 반대 방향 사고 방지). `save_secret` 은 쓰기 **직전에** `prod_write_guard` — 어긋나면 rc 3 으로 멈춘다. 운영 자리가 아닐 때의 install 은 sudo 없이 |
| `stage26.sh` | 정리 때 바이트를 지우는 저장소 자리 — 운영 대상이면 운영 저장소 · 임시 대상이면 `S26_STORAGE_DIR` 을 준 때만(아니면 지우지 않음 — 임시 저장소는 부른 틀이 지운다) · unknown 이면 시작하지 않음 · 지우기 직전 `prod_write_guard`. `sec4.sh` 회귀 틀은 `S26_STORAGE_DIR="$INST/storage"` 를 넘긴다(옛 sed 치환과 같은 효과) |
| `tests/run.sh`(`tests/lib/results.py`) | (첫 판 — 아래 「취합 결정 반영」에서 바뀜) **writes_prod 항목을 늘 돌리지 않는다** — 새 ③ 코드 `writes_prod`. `--select`·`--include-manual`·`--catalog <사용자 지정 목록>` 으로도 풀리지 않는다. 사용자 지정 목록이 `writes_prod` 표시를 빼거나 다른 id·경로 모양(`./tests/../tests/f52.sh`)으로 불러도, cmd 가 **저장소 카탈로그의 writes_prod 시험 파일**을 부르면 거부. `list` 도 따로 한 묶음으로 보인다. 전에는 표시 `⚠ 운영 인스턴스를 침` 경고만 하고 돌렸다(f51·f52 는 `--include-manual` 이면 돌았다 · stage26·f66·fetch_hosts 는 기본으로 돌았다) |
| 카탈로그 | f51·f52(manual_only · writes_prod true) · stage26(required · writes_prod true) — 표시는 사실과 맞다(운영 대상이면 여전히 운영에 쓴다). notes 에 가드 한 줄 · covers 에 `tests/lib/prod_guard.sh` · `fields.writes_prod` 에 run.sh 거부 한 줄 · 새 항목 `core.prod_guard`(static · required) |
| 새 `tests/prod_guard_selftest.sh` | **가짜 경로 단위 확인** — 가드 라이브러리 + f51·f52 의 결정 절·`save_secret`·`qa_secret` 과 stage26 결정 절을 sed 로 떼어 가짜 뿌리(mktemp)에서 돌린다. sudo 는 셸 함수로 바꿔 끼워 가짜 파일에만 install. 운영 경로 읽기·쓰기 0 |

**두 시험(f51·f52)과 stage26 은 돌리지 않았다**(지시 — 운영 계정 2단계를 바꾸는 시험).

**취합 결정 반영(review-fix/dbg-T)**
- `tests/run.sh` — writes_prod 는 **기본 거부** 그대로. `--allow-writes-prod`(run·list)를 **명시**하고, 그 시험 스크립트의 첫 `BASE="…"`·`DB="…"` 줄을
  `tests/lib/prod_guard.sh` `pg_target_kind` 에 넣은 결과가 「prod」일 때만 돈다 — temp·unknown(두 줄이 없음 · `$` 가 든 값 · 섞임 · 스크립트 못 찾음)은
  이 인자가 있어도 거부. 그래서 stage26·f66·fetch_hosts(BASE 운영 검토 주소 · DB tbox_re)는 이 인자로 요약기에서 계속 돈다. 도움말 · 카탈로그
  `fields.writes_prod` · `CLAUDE.md` 1장 「시험」 줄에 한 줄씩. 확인(가짜 스크립트 사용자 목록, 운영 쓰기 0): 인자 없음 → 다섯 모두 ③ writes_prod ·
  인자 있음 → 대상 prod 둘만 돎(가짜 prod 스크립트 · `bash -n tests/f51.sh` — 문법 검사뿐) · temp · 섞임 · BASE 줄 없음은 ③ 그대로.
- **REGRESS_LIST 루프** — 같이 쓰는 도우미가 없다(루프가 시험마다 따로: `community/qf2_search·qf3a·qf3b·stage5a2·5c·5d·5e·6a·6b·6c·7a·7b·7c·7d1` ·
  `qf3c_zip` · `sec4` 16곳). 결정대로 **이번에는 고치지 않았다**. 루프는 시험 사본의 `BASE`(8082 → 임시 주소)·`DB="tbox_re"`(→ 임시 DB)를 글자로
  바꿔 돌리므로, 그 사본 안에서 `pg_target_kind` 는 temp 가 된다 — f51·f52 는 새 비밀을 메모리에만 두고 운영 `qa-totp.txt` 에 쓰지 않으며(쓰려 하면
  `prod_write_guard` rc 3), stage26 은 `S26_STORAGE_DIR` 이 없으면 바이트를 지우지 않는다. 가드의 운영 검토 포트는 사본의 시험 글자가 아니라
  `deploy/sites/tbox-v2-local.conf` 에서 읽으므로 치환에 같이 바뀌지 않는다. `tests/` 안에서 운영 자리(`/etc/tbox-v2`·`/var/lib/tbox-v2`·
  `/var/log/tbox-v2`)에 **쓰는** 곳은 이 셋뿐이었다(grep — 나머지 writes_prod 시험 f54·f65·f66·fetch_hosts 의 운영 쓰기는 psql `DB` 변수로, 루프 사본에선 임시 DB 로 간다).
- **봇 오탐 신고** — R20 `tests/prod_guard_selftest.sh:135`(무작위 가짜 비밀 파일 사본) · R22 참고 3건(새 파일 주석 · `tests/static/r15_secret_args.py:1123`
  이름 예시)은 취합이 봇 오탐 목록에 올린다. 코드·표식(qcheck-allow)으로 달래지 않았다.
- **9번의 `selftest-base` 태그는 없다** — 가드 단위 확인(`tests/prod_guard_selftest.sh`)을 **커밋하기 전 작업 트리**에서 먼저 돌렸다(첫 실행 29/8 — 시험
  틀 자신의 형식 결함 둘: 조각 셈이 가짜 뿌리 글자를 운영 경로로 셈 · 결과 모양 줄바꿈 — 고친 뒤 37/0). 그래서 첫 시험 직전 판에 붙일 커밋이 없다.
  묶음 T 1~8번의 `selftest-base/dbg-T`(27c539f)는 9번보다 앞 판이다.

**독립 검토 「고친 뒤 반영」 반영(review-fix2/dbg-T — 높음 0)**

| # | 지적 | 고친 것 |
|---|---|---|
| 중간 1 | 운영 대상일 때 `.env` DB_NAME 확인이 쓰기 직전에만 있어, f51 이 운영 계정 2단계를 이미 바꾼 뒤 멈출 수 있다(파일은 옛 값 — 13:52 의 반대 방향) · 운영 대상 + `QA_TOTP_FILE` 이면 운영 파일이 옛 값 · stage26 운영 대상 + `S26_STORAGE_DIR` 이면 운영 저장소에 S26- 바이트가 고아 | f51·f52·stage26 결정 절의 `prod)` 갈래에서 ① `QA_TOTP_FILE`·`S26_STORAGE_DIR` 이 있으면 rc 3 ② `prod_write_guard "$PROD_TOTP_FILE"`(stage26 은 운영 저장소)를 **운영 상태를 바꾸기 전에** 한 번 — 어긋나면 rc 3. 쓰기 직전 확인은 그대로 |
| L1 | run.sh 의 writes_prod 파일 대조가 글자 비교 | cmd 토큰을 저장소 뿌리 기준 **realpath** 로 대조(`./tests/../tests/f52.sh`·링크) · `-c` 문자열에 writes_prod 파일의 basename 이 있으면 거부. **한계**: 다른 스크립트가 안에서 부르는 것(감싸는 스크립트)·이름 바꾼 사본은 못 잡는다 — 시험 안 가드가 막는다 |
| L2 | 포트 파싱이 `listen 127.0.0.1:8082;` 만 읽음 | `:([0-9]+)[[:space:];]` — `… default_server;` 도 읽는다 |
| L3 | 운영 쓰기 조건에 트리 확인이 없음 | `prod_write_guard` 가 `realpath $ROOT` = `/var/www/qaredi/tbox-v2`(`PG_PROD_TREE`)일 때만 통과 — 복제본에서 운영 대상으로 f51·f52·stage26 을 부르면 결정 절에서 rc 3 |
| L4 | 주소 별칭(`localhost:8082`·끝 `/`) | `pg_norm_base` — 스킴·호스트 소문자 · localhost·`[::1]` → 127.0.0.1 · 끝 `/` 뗌. 옛 판은 「운영 별칭 + 임시 DB」를 temp 로 가려 새 비밀을 메모리에만 두는 길이 있었다(이제 unknown → 시작 거부) |
| L5 | `tests/wiki_check.sh` 가 사이트 문서가 없으면 통째로 건너뜀 | 저장소에서 그대로 돌리고 사이트·커뮤니티 책 ⑧ 두 줄(「파일이 없다」)만 환경 몫으로 건너뜀 — ①~⑦·t-box 책 ⑧ 은 본다(통과면 `RESULT pass=1 skip=1`, 다른 문제가 있으면 실패 — 깨진 JSON 하나를 넣은 사본에서 rc 1 확인) |
| L6 | `qf4_p7_timeouts.sh` 사본 정리 검사가 「left」여도 계속 돌림 | FPM·DB 두 절 모두 「left」면 그 절의 사본을 돌리지 않고 skip(떼어 낸 절을 가짜 함수로 돌려 확인: left → runf 0번 · ok → 19번) |

확인: 가드 단위 `tests/prod_guard_selftest.sh` **50/0**(늘어난 것 — 별칭 넷 · 운영 별칭+임시 DB → unknown · default_server · 트리 아님 → 멈춤 · f51·f52 운영 대상 + QA_TOTP_FILE / .env 어긋남 / 트리 아님 / 별칭+임시 DB → 결정 절에서 rc 3 이고 떼어 낸 조각 뒤 「상태 바꿈」 표식 0 · stage26 운영 대상 + S26_STORAGE_DIR / 트리 아님 / .env 어긋남 → rc 3).
f51·f52·stage26 은 돌리지 않았다.

**재확인 「반영 가능」 뒤(review-rebase/dbg-T)**
- 운영 새 HEAD `01ab30d`(묶음 S `f333fc9` + SECURITY 한 행) 위로 옮겼다 — `review-fix2/dbg-T` 의 `git diff a57cf6b` 를 3-way 로 얹어 충돌 없음 · `01ab30d` 대비 차이가
  재확인 패치와 글자까지 같다(STAGES.md 는 0장 줄 위치만 다름 — 바뀐 줄 글자 같음).
- 재확인 남은 낮음 하나 고침: `PG_PROD_TREE` 덮어쓰기는 `PG_GUARD_SELFTEST=1` 일 때만 받는다(아니면 `/var/www/qaredi/tbox-v2` 고정) — 가드 단위 확인이 그 표식을 켠다.
- 한계(재확인 남은 낮음 둘 — 고치지 않고 적음):
  - **글롭 우회** — run.sh 의 writes_prod 파일 대조는 글자·realpath·`-c` 속 이름으로만 본다. cmd 에 글롭(`bash tests/f5[1].sh`)·변수 확장을 쓰면 대조를 비켜 간다.
    그 길은 시험 안 가드(prod_guard — 대상·트리·운영 .env 확인)가 막는다.
  - **복제본 stage26** — 운영 파일 쓰기가 운영 트리에서만 되므로(L3) stage26·f51·f52 는 복제본에서 운영 대상으로 돌릴 수 없다(시작 거부 rc 3 → ③ refused).
    복제본의 임시 대상 실행은 `S26_STORAGE_DIR` 이 없으면 S26- 바이트를 지우지 않는다(임시 저장소는 부른 틀이 통째로 지운다).
- 반영 스크립트 `review-scripts/apply-dbg-t-repo-2026-09-26.sh`(+ 고정 값 찍기 `review-scripts/pins-dbg-t-repo.sh`) — 본보기 `apply-dbg-s-repo-2026-09-26.sh`.
  웹 앱이 읽는 파일 변경 0 이라 확인은 bash -n · py_compile · 카탈로그 JSON · `tests/run.sh list` 의 writes_prod 거부 일곱 · 가드 단위 확인(가짜 경로) ·
  커뮤니티 부품 버전 · 라우트 표 그대로 · 런처·로그인 화면 200(미로그인 /api 0 — 감사 행 0).

**반영 뒤 알아 둘 것**
- `tests/run.sh run` 기본 실행에서 stage26·f66·fetch_hosts(required · writes_prod)가 ③ writes_prod 로 빠진다 — 돌리려면 `--allow-writes-prod`(대상이 prod 일 때만).
  또 L3 때문에 f51·f52·stage26 은 **운영 트리에서 부를 때만** 운영 대상으로 돈다(복제본에서 부르면 시작 거부 rc 3 → ③ refused).
- `qf4b1.sh` 의 새 「맨 SET」 식은 SQL 문자열 **첫머리**만 본다 — heredoc·이어 붙인 문자열 안에서 줄 첫머리에 오는 `SET` 은 UPDATE 의 SET 과 가를 수 없어 못 잡는다.
  옛 식(BEGIN·COMMIT 검사)과 같은 한계이고 식은 그대로 둔다(독립 검토 권고 — 기록만).

## 6. 시험 숫자 (임시 인스턴스 · 포트 8231~8239 · 임시 DB `tbox_d1t_*`)

| 시험 | 전(점검 보고 · 또는 기준 판 실측) | 후(이 판) |
|---|---|---|
| `browser/qf3a_favorites.py`(`community/qf3a.sh` 안, `QF3A_SMOKE_RUNS=10`) | 10번에 3번 실패(점검 보고) | **0/10**(매번 ✔ 55 · SMOKE_FAIL 0) · qf3a.sh 전체 104/0 |
| `qf4b1.sh` | 실패 2(438 · 647 — 점검 보고) | **88/0/0** — 의도적 변경 줄 후 1줄(전 9dcd2e7 0줄) · 지문 같음 · 음성 시험 「2 3 4」 |
| `f70.sh` | 실패 9(점검 보고) | **292/0 · 건너뜀 3**(T7 OS 계정 없음 · T9 실제 기기 · ⑨ 반영 스크립트 없음 — 모두 전과 같은 환경 몫). 운영 사본에서 받기 2줄(해지 안 된 것 1) · 대기표 1줄을 걷음 → 「마이그레이션 전」 0\|0 · 가짜 푸시 서버 요청 중 `/f70/` 밖 **0** · T10 위키 검사(tests/wiki_check.sh, 사이트 루트 /var/www/qaredi 읽기 링크) 통과 |
| `qf4_p7_timeouts.sh` | runf 없음 9(점검 보고) · 복제본에선 시작 거부 rc 3 | `P7_SCRIPTS_DIR=/var/www/qaredi/scripts`(읽기 · 사본만 실행): **80/0 · 건너뜀 1**(FPM 사본 — 운영 /etc 가 이미 새 판이고 반영 전 판을 이력에서 못 찾음 = 점검 때와 같은 갈래) · DB 절 runf 로 돎(「command not found」 0) · 반영 스크립트 자리 없음: **60/0 · 건너뜀 1**(⑦ 「환경 없음」) |
| `sec4_r31_nginx.sh` | a57cf6b 실측: 짧은 TMPDIR 40/2 · 긴 TMPDIR(161바이트) rc 4(nginx 못 뜸) | 짧은 · 긴 TMPDIR 둘 다 **43/0** |
| `sec4.sh` | 실패 1(점검 보고) | **67/0** — 운영 사본 1칸 → 시험이 만든 전 상태 |
| `tests/wiki_check.sh` | 복제본 실패(문제 2 「파일이 없다」) | 복제본 인자 없음: 건너뜀 rc 0 `RESULT skip=1` · `QAREDI_SITE_ROOT=/var/www/qaredi`: 통과(글 57편) · 없는 루트: rc 2 |
| `prod_guard_selftest.sh`(9번) | 기준 판 코드에 같은 단위 확인: 18/19 실패 | **37/0** |
| `tests/run.sh run --catalog <사용자 지정>` | writes_prod 경고만 하고 실행 | 넷 중 셋 ③ writes_prod(표시 없이 `bash -n tests/f51.sh` · 경로 모양 바꾼 f52 · writes_prod true) · 한 개만 실행 |

p7·f70·sec4·qf3a 의 위 숫자는 **실행용 사본**(커밋 안 함)으로 돈 것이다 — 사본은 원본과 한 줄만 다르다: 임시 DB 를 만든 직후
`claude_admin01`·`claude_employee01` 의 2단계 비밀을 운영 `/etc/tbox-v2/qa-totp.txt` 값으로 **임시 DB 에서만** 맞춘다(13:52 사고로
파일과 운영 DB 가 어긋나 있던 동안의 우회 — 비밀은 표준입력으로만). qf4b1(13:41~13:54)은 원본 그대로 돌았다(시작 때 로그인이 끝남).
첫 p7 실행(원본, 13:55)은 75/5/1 — 실패 다섯은 모두 그 로그인(claude_employee01 bad_code) 뒤따름이었다.

## 7. 운영 대조

| 항목 | 시작 | 끝 |
|---|---|---|
| 운영 `SELECT max(id) FROM audit_logs` | 23918 (13:19) | (보고에 적음) |
| `/var/log/tbox-v2/alerts.log` 줄 수 | 1033 (13:19) | (보고에 적음) |
| `/etc/tbox-v2/qa-totp.txt` mtime | 16:57:23 (9번 시작 — 취합 복구 뒤) | 16:57:23 (가드 단위 확인 뒤 그대로) |

sudo psql(임시 DB · 운영 DB 읽기 pg_dump)은 운영 alerts.php AL-9 에 걸린다(임시 DB = claude-session, 운영 DB 읽기 = human — 전과 같은 성질).
