# 보고 Q:F-15 S1c — `home_h1_browser` ⑤ 기준 판을 v2.74.0 으로 (2026-10-25 · Opus)

지시서: `docs/qf15-s1c-home-h1-snapshot-directive.md`. 자리: worktree `/home/changkwon/qaredi-qf15s1c-20261025`(브랜치 `fix/qf15-s1c-home-h1` · main `ede6c1f` 기준) · 결과 `/home/changkwon/qaredi-qf15s1c-results-20261025/`(`accept/` · `break/`). 운영 트리 · 운영 DB · 운영 HTTP 0.

## 바꾼 줄 (전 → 후) — `tests/community/home_h1_browser.py` 만

| 자리 | 전 | 후 |
|---|---|---|
| :18 docstring [before] | 「서버 HTML 이 H1 직전 launcher.php(34cfd3d)와 글자 그대로 같음」 | 「서버 HTML 이 이 갈래가 마지막으로 의도해 바뀐 launcher.php(2afc533 · v2.74.0 — 상수 LAUNCHER_SNAPSHOT_REF)와 글자 그대로 같음」 |
| :515 · :516 | 주석 「바뀌기 전 launcher.php(34cfd3d — H1 직전 main …)」 · `git show '34cfd3d:src/views/launcher.php'` | 주석 한 줄(지시서 문장) · `LAUNCHER_SNAPSHOT_REF = '2afc533'` · `git show LAUNCHER_SNAPSHOT_REF + ':src/views/launcher.php'` |
| :535~:542 | R1 주석 두 줄 · `def h4_back(x)` · `nb = h4_back(b)` | 지움(기준 판이 H4 뒤 — 정규화 0) |
| :543 | `{'base', 'new', 'new_h4_back', 'sha_equal': sha(a) == sha(nb)}` | `{'base': len(a), 'new': len(b), 'ref': LAUNCHER_SNAPSHOT_REF, 'sha_equal': sha(a) == sha(b)}` |
| :544 · :545 | `len(a) > 1000 and a == nb and b.count(moa-wordmark) − a.count(…) == 2` · 「… identical to the pre-H1 launcher (34cfd3d) except the H4 wordmark (logo · footer = 2) …」 | `len(a) > 1000 and a == b` · 「⑤ consent-needed launcher: server HTML byte-identical to the last intended launcher (2afc533 · v2.74.0 Q:F-15 S1) — %d bytes」 |

`launcher_h1_baseline.php` 이름 · stub · 갈래 `for who in ('consent',)` · 「consent-needed」 를 고르는 `%s` 식 · 뒤의 실제 서버 단언은 그대로. 상수는 ⑤ 블록 안(`before()` 첫머리)에 둔다 — 수정 범위가 ⑤ 블록뿐이라. 다른 파일 0(이 보고 제외).

## 실행한 명령과 출력 원문

**수락**(`ss -ltn` 로 8768 · 8769 · 8778 비어 있음 확인 뒤):

```
$ cd /home/changkwon/qaredi-qf15s1c-20261025 && python3 tests/community/memories_harness.py --port 8768 --suite home_h1_browser.py --output /home/changkwon/qaredi-qf15s1c-results-20261025/accept
(2026-10-25 22:55:32 → 22:57:17)
PASS ⑤ consent-needed launcher: server HTML byte-identical to the last intended launcher (2afc533 · v2.74.0 Q:F-15 S1) — 15479 bytes
RESULT pass=380 fail=0
OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1
EXIT=0
metrics before.html-consent = {"base": 15479, "new": 15479, "ref": "2afc533", "sha_equal": true}
```

380 단언 — 지시서 기준과 같은 수(⑤ 스냅숏 단언 하나를 하나로 바꿈).

**파비콘 `?v=`**: 두 판 같음 — `moa-favicon.svg?v=1792936448`(a · b 모두, 아래 깨 보기 출력). 정규화 0.

**일부러 깨 보기**(일회성 · 시험 파일에 남기지 않음 · `break/break_check.py` — ⑤ 와 같은 stub · 같은 `git show` 로 임시 복사본에서 두 판을 그림 · DB · HTTP 0 · 끝나면 임시 폴더 지움):

```
$ cd /home/changkwon/qaredi-qf15s1c-results-20261025/break && TMPDIR=<scratchpad> python3 -I break_check.py /home/changkwon/qaredi-qf15s1c-20261025
ref                 : 2afc533
len(a) base         : 15479
len(b) new          : 15479
favicon a           : <link rel="icon" type="image/svg+xml" href="/tbox/assets/moa-favicon.svg?v=1792936448">
favicon b           : <link rel="icon" type="image/svg+xml" href="/tbox/assets/moa-favicon.svg?v=1792936448">
sha256(a)           : dd88d5c7fe0cf233f22d48ffb056f06d25c4f27ca10d1c2c938399fe2a5f99f4
sha256(b)           : dd88d5c7fe0cf233f22d48ffb056f06d25c4f27ca10d1c2c938399fe2a5f99f4
a == b              : True
check(a, b)         : True
tampered offset     : [2564] len 15479
a[i:i+9] before     : 동의하
a[i:i+9] after      : 돘의하
a_tampered == b     : False
check(a_tampered, b): False
ascii tamper        : <title> -> <Title> same length True
check(a_ascii, b)   : False
cleanup             : True
```

`check(x, y)` = ⑤ 의 단언 식 `len(x) > 1000 and x == y` 그대로. 길이가 같은 한 바이트 차이(한글 한 글자 · 아스키 한 글자) 둘 다 거짓.

**정적**:

```
$ python3 -m py_compile tests/community/home_h1_browser.py          → exit 0
$ scripts/security_lint.sh                                           → 결과: 통과 (금지 패턴 0건) · exit 0
$ git diff --check                                                   → 출력 없음 · exit 0
```

## 못 한 것

- 없음. main 에서의 통과는 지시서대로 다음 밤 회귀(`community.home_h1_browser`)에서 메인이 본다.
