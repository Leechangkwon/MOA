# 지시서 D1 — 규모 측정(코드 변경 0) (2026-10-14)

받은 피드백: 「인덱스 설계 최적화 · 페이징 최적화 · 캐싱 · DB 부하 관리가 잘 되는지 확인 필요 — 열 개 · 천 개와 2억 8500만 개는 하늘과 땅 · 리스팅 · 검색 때 DB 가 죽을 수 있음」 → 사용자 「진행해」.
기준 문서: 기획 **`/var/www/qaredi/docs/scale-d-plan.md`**(2장 방법 · 기준 · 3장 데이터 단계) · `docs/work-items/scale-a.md` · `scale-b.md`(이미 한 것) · `tests/scale/*`(합성 DB · 측정 도구 선례) · `/var/www/qaredi/CLAUDE.md` 2 · 4장.

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-scale-d-20261014`**(브랜치 `feat/scale-d1` — 메인이 만든다) · 결과 **`/home/changkwon/qaredi-scale-d-results-20261014/`** · 운영 · 운영 DB 0 |
| 합성 DB | 지금 시험 틀의 합성 DB 방식(tbox_testdb · `~/.config/qaredi/pgpass-testdb`) · 이름 `tbox_scale_d_<pid>` · **시작 전 `df -h /` 남은 공간 ≥ 6GB 확인 — 모자라면 L 을 줄이고 기록 · DB 크기 3GB 상한** · 끝나면 DROP · 데이터는 SQL `generate_series` 로(파일 0 · 개인정보 0 · 운영 사본 0) |
| 커밋 | 파일 지정 · 첫 줄 `Qaredi 규모 D1: …` · 끝 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` · push · 발행 · 합치기 0 |

```
목표        : 사용자가 부르는 모든 목록 · 검색 · 홈 · 개수 창구를 데이터 S · M · L 에서 재서, 기준을 넘거나 데이터와 함께 크게 느려지는 곳과 그 원인(쿼리 계획)을 보고한다.
요구사항    : 기획 2 · 3장. 코드 변경 0 — 앱 파일(src · public · db)을 고치지 않는다.
수정 파일   : tests/scale/d1_seed.sql(새 — 단계별 합성 데이터 · 매개변수 S/M/L) · tests/scale/d1_measure.py(새 — 라우트 표에서 GET 창구 목록을 뽑아 분류 → 실제 HTTP(합성 계정 · 커서 따라 3쪽 · 깊은 쪽) + 그 창구 SQL 의
                 EXPLAIN (ANALYZE, BUFFERS) — pg_stat_statements 가 없으면 PDO 질의를 로그로 잡는 틀의 방식 · 질의 수 · 시간 · 계획 노드) · tests/catalog.json(항목 하나 — manual) · docs/scale-d1-report.md(새)
금지 패턴   : 앱 코드 · 스키마 · 운영 설정 바꾸기 · 운영 DB 접속 · 디스크 상한 넘기기 · git add -A.
완료 기준   : 보고서 docs/scale-d1-report.md —
              ① 창구 목록(분류: 목록 · 커서 목록 · OFFSET 목록 · 검색 · 홈 · 개수 · 상세) 전부와 각 S · M · L 서버 시간(p50 · p95 · 3번 이상) · 질의 수 · L 깊은 쪽 시간 · S → L 비율
              ② 기준 넘음 · 비율 3배 넘음 창구마다: 느린 질의 원문(값 자리표시) · EXPLAIN 요지(Seq Scan 표 · Sort 넘침 · Nested Loop 행 수) · 원인 한 줄 · 고칠 방안 후보(색인 정의 · 커서 · 미리 센 개수 · 캐시)와 예상 효과
              ③ 2억 8500만 행 외삽 — 비율로 「먼저 무너지는 다섯」
              ④ 측정 조건(DB 크기 · 행 수 · PostgreSQL 설정 · 디스크 남은 공간 · 걸린 시간) · 원문 결과(JSON) 폴더
              짧고 표 위주. 고치기는 하지 않는다(D2 에서 메인이 정한다).
```
