# 정리 묶음 D1 — 기록·점검 목록 정리 (2026-09-27)

| 항목 | 내용 |
|---|---|
| 근거 | 취합 지시 2026-09-27 02:3x(1~3항) — 메인 세션(Fable)이 구현 작업자(claude)에게 맡김. 4항(CHANGELOG 바로잡기 두 줄)은 메인이 다음 발행 문장에 직접 넣으므로 하지 않았다 |
| 작업 자리 | 복제본 `~/.local/share/qaredi-dev/d1w` — 기준 판 운영 t-box HEAD **f106e30**(확인: `git -C /var/www/qaredi/tbox-v2 rev-parse HEAD`). push 막음 · 작성자 claude |
| 범위 | 시험 목록·보안 문서·설정 틀만. **웹 앱이 읽는 파일 변경 0**(`src/`·`public/`·`db/` 0 · `deploy/` 는 `push.conf` 한 줄 + 주석 한 줄) · 시험 스크립트 본문은 `tests/dbg_s2_security.sh` B-5 기대값 한 줄(회귀 기대값을 운영 판에 맞춤 — 5장) · 카탈로그 기존 항목 변경 0 |
| 운영 반영 | 하지 않았다 — 메인이 기존 틀로 한다(반영 스크립트 만들지 않음). 패스키·U3 묶음 뒤에 들어가므로 SECURITY 7장·STAGES 는 표 끝에 새 행만 더했다 |

규칙 그대로: 운영 트리·`/etc` 에 쓰지 않았다. 시험은 돌리지 않았다(카탈로그 읽힘만 확인 — 임시 DB·임시 인스턴스 0). `sudo` 는 `/etc/tbox-v2/push.conf` 의 `DIGEST_RULES` 한 줄 읽기에만.

## 1. `tests/catalog.json` — 카탈로그 밖 수락 시험 셋

| id | cmd | env · status · needs | 어디서 확인했나 |
|---|---|---|---|
| `tbox.dbg_s_security` | `bash tests/dbg_s_security.sh` | temp-instance · required · sudo | 머리 주석 1~32행(S-1~S-10 · 화면 선택 · 회귀 · 운영 규칙) · 정적 절 `php -l` 열아홉 이름 · 창구는 몸통(파이썬)의 `req(…, '메서드', '/tbox/…')` 를 `public/index.php` TBOX_ROUTES 에 대조 |
| `tbox.browser_dbg_s_screens` | `python3 tests/browser/dbg_s_screens.py` | temp-instance · helper · browser | 머리 주석(부르는 쪽 = `dbg_s_security.sh` 의 `DBGS_BROWSER=1`) · 화면 둘(`/tbox/activity` · `/tbox/settings`) |
| `tbox.dbg_s2_security` | `bash tests/dbg_s2_security.sh` | temp-instance · required · sudo, browser, nginx, totp_wait | 머리 주석 1~38행 · 화면 절은 기본 켬(`DBGS2_BROWSER:-1`) · 다음 TOTP 창 대기(`sleep $(( 31 - … % 30 ))` 두 곳) · B-5 가 `/etc/nginx` 설치본 셋과 `deploy/sites` 를 cmp(선례 `tbox.api_upload` 의 nginx) · 창구는 `api()`/`req()` 를 TBOX_ROUTES·COMMUNITY_ROUTES 에 대조 |

- 모양은 `tbox.dbg_u_ui` 그대로(필드 열두 개 · 순서 같음). 표기는 카탈로그 `fields` 와 점검 봇 규칙(`../docs/checkbot/stage1-directive.md` A-5 — 저장소 안 `docs/checkbot/` 은 없다)을 따랐다.
- 화면 시험은 지시의 「부르는 셸이 있으면 그 셸을 cmd 로」 대신 **기존 도우미 표기**(cmd = 파이썬 파일 · status helper · notes 에 부르는 셸)로 실었다. 까닭: `tests/run.sh list` 의 「카탈로그 밖」 판정(`results.py outside_catalog`)은 어느 항목의 **cmd 가 그 파일을 부르는지**로 보므로, cmd 를 셸로 하면 `dbg_s_screens.py` 가 계속 밖으로 남는다. 셸 쪽 항목의 covers 에 화면 파일을 넣고 notes 에 `DBGS_BROWSER=1` 을 적었다(`tbox.qf3c_zip` ↔ `tbox.qf3c_zip_browser` 와 같은 모양).
- `requirements` 는 셋 다 비움(머리 주석에 T-·C:T-·Q:T- 번호 없음) · `risks` 는 머리 주석의 R-62 만 — SECURITY 의 R-105·R-106(S) · R-98·R-108·R-110(S2)은 notes 에 「번호가 머리 주석에 없어 risks 밖」으로 적었다.
- `covers` 에서 뺀 것(기존 항목들의 관례): 공용 틀 `tests/lib/*` · 따라잡기 마이그레이션 루프 · 회귀 때만 고치는 `scripts/ui_snapshot.sh` · 폴더 통째 `git grep`.
- 자리: `core.prod_guard` 바로 뒤(`tbox.dbg_u_ui` 앞) — d74·패스키 묶음이 카탈로그 **끝**에 더하므로 합칠 때 글자 겹침이 없게.
- 확인: `python3 -m json.tool` 통과 · `tests/run.sh list` rc 0 · 전체 100 → 103 · 「카탈로그 밖 시험」 26 → **23**(남은 것 = `tests/static/*.py` 22 + `selftest.sh`).
- 묶음 T 구현 기록 4장 끝 문장(「남은 카탈로그 밖 …」)을 위 사실로 바로잡았다 — T 가 합쳐진 판(e88f690)에도 S 의 두 파일이 밖이었다(`git ls-tree e88f690`).

## 2. `SECURITY.md`

사실 근거는 `git log`(각 커밋의 시각)와 7장의 운영 반영 행(01ab30d · be8a8cf · f106e30 이 더한 행)이다.

| 곳 | 전 | 후 |
|---|---|---|
| R-105 S-9 · ⑥ · ⑦ | 복제본, 검토 전 | 운영 반영 2026-09-26 17:39 — f333fc9(S) · ⑥⑦ 은 22:38 — 081af21(S2) · v2.31.0 fd96aca. 상태 ◐ 그대로(⑤ 폴더 주인 root 는 결정 필요) |
| R-106 ① | 복제본·검토 전 | 운영 반영 f333fc9. ◐ 그대로(②③ 안 고침) |
| R-108 | ◐ 구현·실측(복제본, 검토 전) | **✅** 운영 반영 081af21 · v2.31.0(재확인 「반영 가능」 · 반영 확인 16/16 · 운영 브라우저 19/0 — 7장 be8a8cf 행) |
| 6.7 R-98 줄 · 쿠키 줄 | 복제본, 검토 전 | 운영 반영 081af21 · v2.31.0 |
| **같은 부류(지시 목록 밖)** 2장 토큰 회수(S-5) · 2장 등급 내리기(S-8) · R-57(S-1·S-2·S-8) · R-91 ⑦(S-5) | 복제본, 검토 전 | 운영 반영 f333fc9 |
| **같은 부류(지시 목록 밖)** 6.7 R-06·AL-2 줄 | nginx 제안안 (반영 안 함 — 결정) | d75 ① 로 로그인 뒤 비밀 창구 넷 zone `tbox_secret` 운영 반영 2026-09-27 02:29 — f106e30 |
| 새 **R-110** | — | 손님이 커뮤니티 사람 찾기 창구(공유·인계 후보 · 멘션 후보 · 공유 목록 이름)로 활성 사용자 목록을 받던 기존 구멍 — S2 독립 검토 M-1. ✅ 운영 반영 081af21. 근거 `git show 081af21`(`share_candidates.php` · `mention_candidates.php` · `pages_shares_list.php`) · S2 구현 기록 11장 · 7장 be8a8cf 행. 번호: R-109 는 패스키 묶음 몫이라 건너뜀 |
| 7장 끝 새 행 둘 | — | ① `deploy/hardening.sh` ufw 나가는 규칙 고침(41c4b24 — DHCP 67/udp 허용 · `80/tcp`·`443/tcp` 문법) 기록 — **운영에 적용된 적 없음**(방화벽 결정 d58 보류 · `/etc/ufw/ufw.conf` 의 `ENABLED=no` 읽기만 확인) ② 이 묶음 행 |

**고치지 않은 것**: 2장 R-06 줄의 「디버깅 묶음 S2(2026-09-26, 복제본 · 검토 전 …)」 — 같은 표기지만 d74 묶음(복제본 d74w)이 **같은 줄**을 고치므로 겹치지 않게 두었다(메인이 d74 위로 옮길 때 081af21 로).

## 3. `deploy/push.conf` 틀

`DIGEST_RULES=AL-9` → **`AL-9,AL-7`** + 주석 한 줄. 서버 값은 `sudo -n grep '^DIGEST_RULES=' /etc/tbox-v2/push.conf` 한 번으로 읽어 `AL-9,AL-7` 확인(다른 줄은 읽지 않음). `deploy/install.sh` 601행은 `/etc/tbox-v2/push.conf` 가 없을 때만 틀을 복사하므로, 틀이 옛 값이면 새로 설치할 때 AL-7 요약이 빠진다. 형식은 `push_bootstrap.php` 의 `^AL-[0-9]{1,2}(,AL-[0-9]{1,2})*$` 에 맞는다.

## 4. 확인

`python3 -m json.tool tests/catalog.json` · `tests/run.sh list` · `bash scripts/security_lint.sh` · `bash tests/static/selftest.sh` · `php scripts/wiki_check.php` · `git diff f106e30 HEAD --stat` — 숫자는 SECURITY 7장 이 묶음 행과 STAGES 0장 「정리 D1」 줄.

## 5. 회귀 기대값을 운영 판에 맞춤

`tests/dbg_s2_security.sh` B-5 의 「비밀번호·코드를 묻는 나머지 창구」 한 줄 — 기대값 0 → **7**(f106e30 판 `deploy/sites/tbox-v2.conf` 에서 다시 잼 · 일곱 자리 모두 `limit_req zone=tbox_secret`) · 문장 「d75(2026-09-27 f106e30)로 비밀 창구 넷에 tbox_secret 제한 — 자리 7」. 같은 절의 다른 줄(로그인 zone · 자리 12 · 설치본 cmp)은 그대로.
