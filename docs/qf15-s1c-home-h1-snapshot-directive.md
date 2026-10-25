# 지시서 Q:F-15 S1c — `home_h1_browser` ⑤ 옛 런처 스냅숏 기대값 보정 (2026-10-25)

사용자(2026-10-25): 「home_h1_browser ⑤(옛 런처 스냅숏 비교) 시험 기대값 보정을 지시서 → Opus → 검토 순서로 진행해」.
근거: 밤 회귀 `~/.cache/qaredi-check/nightly/report-2026-10-24.md`(S1 묶음) · `report-2026-10-25.md`(S1b 묶음) — 두 번 모두 `community.home_h1_browser` 가 **⑤ 하나만** 실패(run #46 원문 `RESULT pass=379 fail=1` · 뒤의 Traceback 은 격리 틀이 실패를 알리는 `RuntimeError('Integration failed …')` 일 뿐). 기준 판 d9998c4(S1 전)에서는 통과.
원인(메인이 2026-10-25 같은 틀로 두 판을 그려 대조): ⑤ 는 「동의 전 런처」 서버 HTML 을 **H1 직전 판 `34cfd3d` 의 launcher.php** 로 그린 것과 바이트 비교하고 H4 워드마크 셋만 정규화(`h4_back`)한다. Q:F-15 S1(v2.74.0 `2afc533`)이 이 갈래의 글자를 **일부러** 바꿨다 — meta description · `<title>` · 파비콘 `<link>` · 머리 note · 「모아 홈」 · 동의 전 히어로(kicker 지움 · h1 · 설명) · 「동의하고 모아 홈 열기」 · 「PC 비서」 · 「메신저」 줄 · 읽을거리 머리글 넷 · 「게시판 새 글」 · 바닥 한 줄 · 「이야기 앱에 남기기」. **이 밖의 차이는 0**(S1 지시서 표 A · C · D · E · F · H 와 하나하나 맞음). 앱 결함이 아니라 S1 지시서 · 구현 · 검토가 놓친 기대값이다.
기준 문서: `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장(3.1) · S1 지시서 `docs/qf15-s1-words-directive.md` 「기대값만 바꿀 옛 시험」.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf15s1c-20261025`**(브랜치 `fix/qf15-s1c-home-h1` — 메인이 main 에서 만든다) · 결과 **`/home/changkwon/qaredi-qf15s1c-results-20261025/`** · 운영 · 운영 DB 읽기 · 쓰기 0 |
| 격리 틀 | `python3 tests/community/memories_harness.py --port 8768 --suite home_h1_browser.py --output <결과>/accept` — 시작 전 `ss -ltn` 로 8768 · 8769(쓰이면 8778) · 끊으면 내 것만 치움 |
| 커밋 | 파일 지정(`git add -A` 금지) · 첫 줄 `모아 Q:F-15 S1c: home_h1 ⑤ 기준 판을 v2.74.0 으로 — …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0(메인이 한다) |

```
목표        : tests/community/home_h1_browser.py 의 ⑤(동의 전 런처 서버 HTML 스냅숏)가 비교하는 기준 판을
              「이 갈래가 마지막으로 의도해 바뀐 발행」 2afc533(v2.74.0 · Q:F-15 S1)으로 옮겨, S1 의 의도한 글자 변경으로는 실패하지 않고
              그 밖의 어떤 바이트 차이에는 여전히 실패하게 한다.
요구사항    : CLAUDE.md 3.1(시험 기대값만 — 앱 0) · S1 지시서 「기대값만 바꿀 옛 시험」 방식(그 단언만).
수정 파일   : tests/community/home_h1_browser.py — ⑤ 블록(지금 참고 줄 :512~:547)과 머리 docstring 의 [before] 한 줄(:18)만.
              docs/qf15-s1c-report.md(새 · 보고). 그 밖에 0(앱 코드 · 다른 시험 · catalog · harness 0).
금지 패턴   : ⑤ 밖 단언 · 화면 · 단계 손대기 · 기준 판을 main · HEAD · merge-base 같은 움직이는 ref 로 두기(스냅숏 뜻이 사라짐) ·
              비교를 「길이만」 · 「일부만」으로 약하게 하기 · 정규화로 S1 이 바꾸지 않은 글자를 흡수하기 · git add -A.
완료 기준   : 격리 틀에서 home_h1_browser 전부 통과(지금 380 단언 기준 — 수 바뀌면 이유) ·
              정적: python3 -m py_compile tests/community/home_h1_browser.py · scripts/security_lint.sh 0 · git diff --check ·
              「일부러 깨 보기」 한 번: 기준 판을 그린 HTML 에 한 바이트 다른 글자를 넣은 경우 ⑤ 가 실패함을 보인다
              (시험 파일에 남기지 않는 일회성 확인 — 명령과 출력 원문을 보고서에)
보고 형식   : docs/qf15-s1c-report.md — 바꾼 줄(전 → 후) / 실행한 명령과 출력 원문(통과 수 · 일부러 깨 보기) / 못 한 것. 짧게.
```

## 바꿀 것 (⑤ 블록만)

| 참고 줄 | 지금 | 바꿀 것 |
|---|---|---|
| :515 · :516 | 주석 「바뀌기 전 launcher.php(34cfd3d — H1 직전 main · v2.66.0 + 문서)」 · `git show '34cfd3d:src/views/launcher.php'` | 기준 판을 한 곳의 상수로: `LAUNCHER_SNAPSHOT_REF = '2afc533'` + 주석 한 줄 「이 갈래가 마지막으로 **의도해** 바뀐 발행(v2.74.0 · Q:F-15 S1 — 그 전 기준 34cfd3d · H4 정규화는 이 판이 이미 H4 뒤라 필요 없음). 앞으로 이 갈래 글자를 일부러 바꾸는 발행이 나오면 이 값을 그 발행 커밋으로 옮긴다」 · `git show LAUNCHER_SNAPSHOT_REF + ':src/views/launcher.php'` |
| :537~:542 | `h4_back()` 정규화와 `nb = h4_back(b)` | 지운다 — 기준 판이 H4 뒤라 정규화 0. 비교는 `a == b`(정규화 없는 바이트 그대로) |
| :543 | `summary['html-…'] = {'base', 'new', 'new_h4_back', 'sha_equal'}` | `{'base': len(a), 'new': len(b), 'ref': LAUNCHER_SNAPSHOT_REF, 'sha_equal': …(a, b)}` |
| :544 · :545 | `a == nb and b.count(moa-wordmark) − a.count(…) == 2` · 문장 「… identical to the pre-H1 launcher (34cfd3d) except the H4 wordmark (logo · footer = 2)」 | `len(a) > 1000 and a == b` · 문장 「⑤ consent-needed launcher: server HTML byte-identical to the last intended launcher (2afc533 · v2.74.0 Q:F-15 S1) — %d bytes」 |
| :18 (docstring) | 「… H1 직전 launcher.php(34cfd3d)와 글자 그대로 같음 …」 | 「… 이 갈래가 마지막으로 의도해 바뀐 launcher.php(2afc533 · v2.74.0 — 상수 LAUNCHER_SNAPSHOT_REF)와 글자 그대로 같음 …」 |

`launcher_h1_baseline.php` 파일 이름 · 그리는 틀(stub) · 갈래(`for who in ('consent',)`) · 뒤의 실제 서버 단언은 그대로다.

**파비콘 `?v=`**: 두 판 모두 같은 틀의 `public/assets/moa-favicon.svg` 를 `filemtime` 으로 읽으니 값이 같다(메인이 2026-10-25 두 판을 그려 확인 — 같은 갈래 15,479B 대 15,479B 를 기대). 다르게 나오면 정규화하지 말고 보고서에 적고 멈춘다.

## 바꾸지 않는 것

- 앱 코드 · 다른 시험 · `tests/catalog.json` · 격리 틀 · `invite_request_browser.py` 의 [same](「main 의 launcher.php」와 비교 — main 에서 늘 깨지는 브랜치 비교 단언. 이번 범위 밖 — 따로 판단).
- `messenger_window_browser` Q:T-19(전부터 깨짐 — 밤 회귀 SAME).

## 반영 (메인)

시험 파일 · 문서만이라 **발행 0 · 백업 0**(앱 코드 · DB · 설정 변경 0 — 되돌림은 git). 메인이 diff 경로가 `tests/community/home_h1_browser.py` · `docs/` 뿐인지, 운영 트리가 깨끗한지 확인한 뒤 main 에 ff 합치기 · push. 다음 밤 회귀에 `community.home_h1_browser` 하나를 넣어 main 에서 통과를 본다.
