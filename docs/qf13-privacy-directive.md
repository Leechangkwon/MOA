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
