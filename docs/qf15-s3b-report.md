# 보고 Q:F-15 S3b — 밤 회귀(10-26) 시험 깨짐 보정 둘 · 게시판 글 360 원인 조사 (2026-10-28 · Opus)

지시서: `docs/qf15-s3b-regression-directive.md`. 자리: worktree `/home/changkwon/qaredi-qf15s3b-20261028`(가지 `fix/qf15-s3b-tests` · main `184273c` + `bbecada`) · 결과 `/home/changkwon/qaredi-qf15s3b-results-20261028/`. 운영 트리 · 운영 DB · 운영 주소 0(읽기 포함) · 격리 틀은 `QCHECK_ENV_SRC=/home/changkwon/qaredi-regress-results-20261013/env-src` · 계정은 합성 `claude_` · UA `tbox-review/2026-10-28`. 앱 코드 변경 0.

## 바꾼 줄 (전 → 후)

| 파일 · 자리 | 전 | 후 |
|---|---|---|
| `tests/design_fixture.php` :4 (머리 주석) | 없음 | 「2단계 · 3단계 런처가 부르는 판정(Authz::uploadableLevels — GuideJourney::allowed) · 표 확인(GuideJourney::ready → Db) — 화면 그리기만. Db 는 그 한 질의에 「표 없음」만, 다른 질의는 예외(500)로 멈춘다.」 |
| 같은 파일 `final class Authz` | `canCommunityWrite` · `canAdmin` · `canManageUsers` 셋 | + `uploadableLevels(array $user): array` — `($_GET['guest'] ?? '') === '1' ? [] : ['internal', 'team', 'private']`(`canCommunityWrite` 흉내와 같은 갈래) |
| 같은 파일 (새) `final class Db` | 없음 | `instance()` · `run($sql, $params)` — SQL 에 `to_regclass('community.guide_journeys')` 가 있을 때만 `fetchColumn()` 이 `false` 인 객체(→ `ready()` 거짓 · `resume()` 안 부름 · 이어 하기 줄 0), **그 밖 질의는 `LogicException`** |
| `tests/community/home_front_browser.py` :300 | `check(names[-1:] == ['2026-10-26_qf15_s2_shortcuts.sql'], '③ harness: the new migration is last in the twice-applied list')` | `check('2026-10-26_qf15_s2_shortcuts.sql' in names and '2026-10-26_scale_d2a_indexes.sql' in names and names.index('2026-10-26_qf15_s2_shortcuts.sql') > names.index('2026-10-26_scale_d2a_indexes.sql'), '③ harness: the S2 migration is in the twice-applied list (after scale_d2a)')` |

`Db` 흉내가 다른 화면을 조용히 통과시키지 않는가: 이 라우터의 다른 화면(login · files · settings · community · community-home)은 지금 `Db` 를 부르지 않는다(아래 A 실행의 php 서버 로그에 `design_fixture: no database` 0). 나중에 부르게 되면 그 질의는 `to_regclass('community.guide_journeys')` 가 아니므로 예외 → 500 → 시험의 `HTTP` 단언이 실패한다(통과로 숨지 않음).

## 돌린 명령과 출력 꼬리 원문

**A 고치기 전 재현**(같은 틀 · 8768 · curl `-A tbox-review/2026-10-28`): `?screen=launcher` → `500` · `PHP Fatal error:  Uncaught Error: Call to undefined method Authz::uploadableLevels() in …/src/community/GuideJourney.php:48`(`a-before-phpserver.log`).

**A 뒤**: launcher · anonymous · login · files · settings · community · community-home · `launcher&guest=1` 모두 200(손님은 `?guide=` 0 · 「맡은 일 보기」 「파일 찾기」).

```
$ cd /home/changkwon/qaredi-qf15s3b-20261028 && DESIGN_RESULTS=<결과>/design python3 tests/design_themes_browser.py      (1분 37초 · rc=0)
{"checks": 1355, "failures": [], "console": [], "scope": "isolated rendered production views; original auth/API scripts excluded; live regression separate"}
RESULT pass=1355 fail=0 skip=0
```
1355 — 기준 판(run #49)과 같은 수.

**B**(8768 · 8769 비어 있음 확인 뒤):

```
$ QCHECK_ENV_SRC=/home/changkwon/qaredi-regress-results-20261013/env-src python3 tests/community/memories_harness.py --port 8768 --suite home_front_browser.py --output <결과>/home-front
PASS ③ harness: the S2 migration is in the twice-applied list (after scale_d2a)
…
FAIL ⑬ route table · community routes · ai_page.php unchanged since 310bbc3 (the /community/ai answer is the same as before)
RESULT pass=285 fail=1
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
RuntimeError: Integration failed; see sanitized results
rc=1
```

③ 은 고쳐졌다(밤 회귀 284/2 → 285/1). **⑬ 이 남아 B 는 통과가 아니다** — 밤 회귀 원문(run #48 :236)에도 같은 FAIL 이 있었는데 지시서 표에는 ③ 만 적혀 있었다. 원인: S3 `440252f` 가 `src/community/routes.php` 에 `['PATCH', '/api/me/journeys', 'guide_journeys_api.php', 'community.read']` 한 줄을 더했고, ⑬ :823 은 `git diff --quiet 310bbc3 -- public/index.php src/community/routes.php src/community/handlers/ai_page.php` 가 0 이기를 요구한다(3단계가 놓친 기대값 — B 와 같은 꼴). 지시서 「그 밖 단언 0」이라 **고치지 않았다**. 보정안(한 줄 · 뜻 유지): `public/index.php` · `ai_page.php` 는 그대로 `--quiet`, `routes.php` 는 「310bbc3 대비 더하기 · 빼기 줄 가운데 `/ai` 또는 `ai_page.php` 를 담은 줄 0」으로 — 「/community/ai 응답이 전과 같다」는 뜻은 그대로, 다른 묶음의 라우트 추가에는 깨지지 않음.

**정적**(`static.log`): `php -l tests/design_fixture.php` → No syntax errors · `python3 -m py_compile tests/community/home_front_browser.py` → OK · `bash scripts/security_lint.sh` → 「결과: 통과 (금지 패턴 0건)」 rc=0 · `git diff --check` → 0.

## C. `tbox.dbg_u3_ui` 360 게시판 글 첫 줄 — 결론 ③ 데이터 · 우연(제목 꼬리)

**원인 한 줄**: 시험이 게시판 글 제목을 `"DBGU3 게시판 글 " + TAG`(TAG = `uuid4().hex[:6]` — 실행마다 다름)로 만드는데, 360 폭에서 제목(26px · 700 · 칸 폭 320)이 **꼬리 여섯 글자의 폭에 따라 한 줄 또는 두 줄**이 된다. 두 줄이면 본문 첫 줄 bottom 420(> 390 실패), 한 줄이면 385(통과). 앱 판과 무관 — 기준 판 ede6c1f 에서도 같은 꼬리면 같은 값.

방법: 그 판의 `memories_harness.py` 를 그대로 돌리되 마지막 묶음 시험 한 번만 측정 스크립트로 바꿔 부르는 감싸개(`c_run.py` — 어느 worktree 파일도 고치지 않음 · 합성 DB 에 시드 게시판 셋을 `2026-09-24_community_boards.sql` 값으로 넣음). 측정은 `dbg_u3_ui.py` 의 `FIRST_LINE` · `ctx()`(360 × 780 · 터치 · 모바일 · `qaredi_sidebar=open` · `tbox_theme`) · `go()`(networkidle + 400ms) · 글 만들기(제목 · 태그 질문/폰 · 본문 한 문단)를 그대로 옮겼다. 옛 판은 임시 detached worktree(끝나고 `git worktree remove`). `bash tests/dbg_u3_ui.sh` 전체는 돌리지 않았다(아래 「못 한 것」).

**같은 자료 · 세 판**(360 × 780 · 밝게 · 자유게시판 · 꼬리 `85e300` — 어둡게 · 질문과 답변 게시판도 같은 값):

| 판 | 머리(appbar) | 경로 줄 | 머리 칸(.page-head) · 제목 | 속성 요약 | `#page-doc` top | 첫 줄 top / bottom | 판정(≤ 390) |
|---|---|---|---|---|---|---|---|
| ede6c1f(기준 · S2 전) | 0 · 52 | 76 · 20 | 124 · 139 · 제목 70(2줄) | 283 · 63(3줄) | 398 | 402 / 420 | 실패 |
| 10443d9(S2) | 같음 | 같음 | 같음 | 같음 | 398 | 402 / 420 | 실패 |
| 184273c(지금 main · S3) | 같음 | 같음 | 같음 | 같음 | 398 | 402 / 420 | 실패 |

→ 밤 회귀 후보 값 `{'top': 402, 'bottom': 420}` 을 그대로 재현했고 **세 판이 화소까지 같다** — 2단계 · 3단계의 셸 · CSS 변경은 이 화면의 높이를 바꾸지 않았다. (지시서의 「18px」 은 본문 글자 한 줄 높이(402 → 420)이고, 통과 · 실패를 가른 것은 제목 한 줄 35px 이다.)

**같은 판 · 꼬리만 바꿈**(실제 글을 만들어 잼):

| 판 | 꼬리 | 제목 줄(높이) | 360 첫 줄 top / bottom | 360 판정 | 412 |
|---|---|---|---|---|---|
| ede6c1f | `85e300` | 2 (70) | 402 / 420 | 실패 | 1줄 · 367 / 385 통과 |
| ede6c1f | `4b0a00` | 2 (70) | 402 / 420 | 실패 | 통과 |
| ede6c1f | `4b0a0a` | 1 (35) | 367 / 385 | **통과** | 통과 |
| 184273c | `85e300` | 2 (70) | 402 / 420 | 실패 | 통과 |
| 184273c | `4b0a00` | 2 (70) | 402 / 420 | 실패 | 통과 |
| 184273c | `4b0a0a` | 1 (35) | 367 / 385 | **통과** | 통과 |

밝게 · 어둡게 같음. 제목 글자만 시험 브라우저 안에서 바꿔 잰 쓸기(서버 0): `4b0a??` 256개 중 한 줄 84 · `85e3??` 중 104 · 무작위 uuid4 꼬리 3000개 중 한 줄(통과) 1865 · 두 줄(실패) 1135 — **약 38% 확률로 실패하는 단언**. 두 판(ede6c1f · 184273c)에서 한 줄이 되는 꼬리 집합이 똑같다. 밤 회귀 후보의 꼬리는 `85e3??`, 기준은 `4b0a??`(원문의 파일 이름 `DBGU3-85e3…` · `DBGU3-4b0a…`) — 기준 실행이 한 줄 꼬리를 뽑아 통과했다고 보면 맞는다(뒤 두 글자는 원문에 없어 그 실행의 꼬리를 정확히 다시 만들 수는 없다).

**화면 사진**(`<결과>/c/`): `tag-ede6c1f/ede6c1f-real-360-light-85e300.png`(두 줄 제목) · `tag-ede6c1f/ede6c1f-real-360-light-4b0a0a.png`(한 줄 제목) · `tag-184273c/184273c-real-360-light-85e300.png` · `tag-184273c/184273c-real-360-light-4b0a0a.png` · 412 · 어둡게도 같은 폴더. 사진 가운데의 「모아 사용 방법을 안내해 드릴까요?」 첫 방문 창은 옛 기능(ede6c1f 에도 있음 · 맨 위 층) — 레이아웃 값에는 영향 0. 요소 표 원자료 `c/<판>/measure-<판>.json` · 꼬리 쓸기 `c/tag-<판>/measure2-<판>.json`.

**고칠 곳 제안(고치지 않음)** — 고칠 자리가 단언 줄이 아니라 자료 줄이라 지시서 C-3 ③ 대로 보고만: `tests/browser/dbg_u3_ui.py:179` 제목 `"DBGU3 게시판 글 " + TAG` → `"DBGU3 글 " + TAG`. 같은 틀에서 잰 최악 꼬리(같은 글자 여섯 `000000`~`ffffff`) 한 줄 폭: 지금 318/320(두 줄 생김) · `"DBGU3 글 "` 241/320(늘 한 줄) · `"DBGU3 게시판 "` 291/320. 단언(:502 `bottom <= 0.5 * h`)은 그대로 — 「속성 여섯이 본문을 밀어내지 않는다」는 뜻을 결정적으로 보게 된다. 제목이 두 줄인 글까지 반쪽 안에 들어야 한다는 것이 디자인 의도라면 그건 앱 쪽 판단(제목 글자 크기 · 머리 칸)이라 이 지시서 밖이다.

## 못 한 것 · 미검증

- **B 완료 기준 「home_front_browser 통과」 미달** — ⑬ 실패 1(위 원인 · 보정안). 지시서가 :300 한 줄만 허락해 손대지 않음. 메인 판단 필요. → **아래 「보충」에서 해소(286/0)**.
- `bash tests/dbg_u3_ui.sh` 전체 — 돌리지 않음: 이 스크립트는 운영 DB 를 `pg_dump` 로 **읽어** 임시 DB 를 만드는데 이번 자리 규칙이 운영 DB 읽기 0 이고, 운영 자료가 184273c(10-27 초기화)로 바뀌어 10-26 의 자료를 재현할 수도 없다. 대신 같은 측정을 합성 DB 에서 세 판으로 재현(후보 값 402/420 일치). 운영 자료의 「쓴 사람」 표시 이름 · 게시판 이름은 합성 값(`claude_employee01` · 시드 게시판)이라 그 둘이 달랐을 경우의 값은 미검증.
- `design_fixture.php` 의 `?screen=community-page`(design 시험은 부르지 않음 · `tests/ux_browser.py` 만)는 500 — `Call to undefined function community_page_row_nodoc()`(page_view.php:52). 이번 흉내(Authz · Db)와 무관한 다른 함수 빠짐 · 이 지시서 밖 · ux_browser 미검증.
- 밤 회귀의 나머지(SAME · 전부터 깨진 것)는 지시서대로 손대지 않음.

## 보충 (재지시 2026-10-28 — 두 보정안 받아들임 · 시험만 · 앱 코드 0)

### 바꾼 줄 (전 → 후)

| 파일 · 자리 | 전 | 후 |
|---|---|---|
| `tests/community/home_front_browser.py` :823 · :824 (⑬ 한 단언) | `git diff --quiet 310bbc3 -- public/index.php src/community/routes.php src/community/handlers/ai_page.php` 가 0 · 「⑬ route table · community routes · ai_page.php unchanged since %s (the /community/ai answer is the same as before)」 | `public/index.php` · `ai_page.php` 는 그대로 `--quiet` 0, `routes.php` 는 `git diff 310bbc3` 의 더한 · 뺀 줄(`+++`/`---` 머리 제외) 가운데 `/ai` 또는 `ai_page.php` 를 담은 줄 0 · 「⑬ route table · ai_page.php unchanged since %s · community routes: added/removed lines with /ai or ai_page.php 0 (the /community/ai answer is the same as before) %s」(걸린 줄을 꼬리에 보임) — :823~:826 네 줄 |
| `tests/browser/dbg_u3_ui.py` :179 | `{"title": "DBGU3 게시판 글 " + TAG, …}` | `{"title": "DBGU3 글 " + TAG, …}` — :502 단언 · 다른 자료 · 다른 단계 0 |

이 제목 글자에 기대는 다른 단언: `grep -n 'DBGU3' tests/browser/dbg_u3_ui.py tests/dbg_u3_ui.sh` — 제목을 찾거나 견주는 단언 0(다른 DBGU3 줄은 다른 자료 제목 · 파일 이름 · 손잡이 이름뿐). 내 글 제목 `"DBGU3 글 화면 " + TAG`(:145)와도 겹치지 않는다.

⑬ 새 판정이 실제로 가르는지(일회성 · 파일에 남기지 않음): 지금 판 → `same rc 0 · ai_lines []`(바뀐 줄은 `+ ['PATCH', '/api/me/journeys', 'guide_journeys_api.php', 'community.read'],` 하나) · `/ai` 줄의 정책을 바꾼 가짜 diff → 두 줄이 걸림.

### 돌린 명령과 결과 꼬리 원문

**B**(8768 · 8769 비어 있음 확인 뒤 · `home-front-2.log`):

```
$ QCHECK_ENV_SRC=/home/changkwon/qaredi-regress-results-20261013/env-src python3 tests/community/memories_harness.py --port 8768 --suite home_front_browser.py --output <결과>/home-front-2
PASS ③ harness: the S2 migration is in the twice-applied list (after scale_d2a)
PASS ⑬ route table · ai_page.php unchanged since 310bbc3 · community routes: added/removed lines with /ai or ai_page.php 0 (the /community/ai answer is the same as before) []
RESULT pass=286 fail=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
rc=0
```

**② 새 제목 측정**(같은 감싸개 `c_run.py` + `c_measure4.py` — 제목 앞말은 손으로 옮기지 않고 `dbg_u3_ui.py` 의 `/community/api/boards/%s/pages` 줄에서 읽음 · 지금 판 = 4fa6da1 + 이 보충 · 자유게시판 · 360 · 412 × 밝게 · 꼬리 셋 + 무작위 uuid4 꼬리 300 = 303 글을 실제로 만들어 `FIRST_LINE` · `go()` 그대로 · `run4-newtitle.log`):

```
PREFIX from dbg_u3_ui.py: 'DBGU3 글 '
NAMED 4fa6da1p 360 85e300 {'bottom': 385, 'top': 367, 'h': 780, 'text': '게시판 글 본문 첫 줄'} title [1, 35, 'DBGU3 글 85e300'] PASS
NAMED 4fa6da1p 360 4b0a00 {'bottom': 385, 'top': 367, 'h': 780, 'text': '게시판 글 본문 첫 줄'} title [1, 35, 'DBGU3 글 4b0a00'] PASS
NAMED 4fa6da1p 360 4b0a0a {'bottom': 385, 'top': 367, 'h': 780, 'text': '게시판 글 본문 첫 줄'} title [1, 35, 'DBGU3 글 4b0a0a'] PASS
NAMED 4fa6da1p 412 85e300 {'bottom': 385, 'top': 367, 'h': 780, 'text': '게시판 글 본문 첫 줄'} title [1, 35, 'DBGU3 글 85e300'] PASS
NAMED 4fa6da1p 412 4b0a00 {'bottom': 385, 'top': 367, 'h': 780, 'text': '게시판 글 본문 첫 줄'} title [1, 35, 'DBGU3 글 4b0a00'] PASS
NAMED 4fa6da1p 412 4b0a0a {'bottom': 385, 'top': 367, 'h': 780, 'text': '게시판 글 본문 첫 줄'} title [1, 35, 'DBGU3 글 4b0a0a'] PASS
SUMMARY w=360 pages=303 pass=303 fail=0 two_line_titles=0 bottoms=[385] title_heights=[35]
SUMMARY w=412 pages=303 pass=303 fail=0 two_line_titles=0 bottoms=[385] title_heights=[35]
C_MEASURE4_DONE
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
rc=0
```

→ 360 · 412 모두 첫 줄 bottom 385(≤ 390) · 두 줄 제목 0 · 이전 판의 한 줄 제목 값(385)과 같다 — 단언(:502)은 그대로 「속성 여섯이 본문을 밀어내지 않는다」를 잰다. 화면 사진 `<결과>/c/newtitle/4fa6da1p-newtitle-{360,412}-light-{85e300,4b0a00,4b0a0a}.png`(가운데 창은 S3 첫 방문 「무엇을 하고 싶으세요?」 — 맨 위 층이라 레이아웃 값 영향 0) · 원자료 `c/newtitle/measure4-4fa6da1p.json`.

**정적**(`static-2.log`): `python3 -m py_compile tests/community/home_front_browser.py tests/browser/dbg_u3_ui.py` → OK · `bash scripts/security_lint.sh` → 「결과: 통과 (금지 패턴 0건)」 rc=0 · `git diff --check` → 0.

### 못 한 것 · 미검증 (보충)

- `bash tests/dbg_u3_ui.sh` 전체 — 여전히 돌리지 않음(운영 DB `pg_dump` 읽기 규칙). 새 제목의 효과는 위 격리 측정으로만 보였다 — dbg_u3 의 나머지 단언(경로 줄 · 터치 · 보드 등 밤 회귀에서 SAME 으로 깨져 있던 것)은 이 보충과 무관 · 미검증. 운영 「쓴 사람」 표시 이름 · 게시판 이름이 합성 값과 다를 때의 속성 요약 줄 수는 미검증(제목 줄 우연은 없앴음).
- 어둡게는 이번 측정에서 빼었다(재지시가 밝게만 · 앞 조사에서 밝게 · 어둡게 값이 늘 같았음).
