# 규모 개선 A — 운영 보호

- 상태: 개발 중
- 작업 브랜치: cloud/scale-a
- 기준 main 커밋: e618320c6297 (운영 v2.60.0)
- 담당 환경: 운영 서버의 격리 작업본, Codex 직접 구현. 완료 뒤 별도 컨텍스트 독립 검토.

## 목적과 범위

사용자 지정 프롬프트 `/var/www/qaredi/docs/scale-fix-codex-prompt-2026-10-12.md` ①②③.
- [ ] DB 통계/느린 문장/임시 파일 보호 및 nginx 시간·검색 빈도 제한
- [ ] 최소 2자·입력/닫기 취소·사람당 검색 1개·총 SQL 실행 예산 3초·429 마지막 요청 1회 재시도
- [ ] 삭제 여부와 무관한 댓글(page_id, created_at, id) 동시 인덱스
- [ ] 동일 합성 DB 130만 페이지 전후 측정, 기능/회귀, 독립 검토, 운영 반영
허용 파일: src/SearchGuard.php(신규), src/community/handlers/search.php, src/handlers/files_list.php, src/community/handlers/board_pages_list.php, public/assets/search.js, public/assets/app.js(같은 전역 파일 검색 호출자), public/assets/community/editor.js, src/views/search_dialog.php(필요한 문구), db/migrations/2026-10-24_scale_comments.sql, deploy/postgresql/*(신규 설정 예시), deploy/sites/tbox-common.conf·tbox-v2.conf·tbox-v2-local.conf, deploy/logrotate.d/*·cron.d/*(관련 로그 회전), deploy/DEPENDENCIES.md, tests/scale/*, tests/stage89_cross_regression/run.py(격리 실행기 추가 suite 선택), 자산 생성기가 갱신하는 import 해시, 관련 기존 검색 시험의 새 계약 기대값, API.md·ARCHITECTURE.md·DESIGN.md·SECURITY.md·STAGES.md·위키 검색/운영 안내 및 차례, 본 기록·인계·production-state.json(배포 후)·발행 파일. 근거 원본 3개와 root/community STAGES 기록은 루트 저장소에 별도 보존한다.
권한 SQL·정렬·영문 색인·SR-5 게시판 글 수·캐시·자동저장은 그대로. 검색 429는 보호 동작이며 권한 실패가 아니므로 계정 잠금/인증 실패에 넣지 않는다. 검색어는 응답에만 있고 진단에는 유형/시간만 남긴다.

## 검사

근거 스크립트 `/home/changkwon/qaredi-perf-2026-10-12/`. 새 schema-only + 합성 데이터, Unix socket 전용 임시 PG. 운영 실측은 shared_buffers128MB/effective_cache_size4GB(보고서160MB/5GB와 차이); 실제 설정을 양쪽 측정에 동일 적용. 디스크 3GB 미만 즉시 중단.
루트 docs/work-items/TEMPLATE.md는 없어서 웹 저장소 TEMPLATE.md를 사용했다. 리서치 실제 경로는 루트 docs/improvement-research-2026-09-24.md.

## 서버 적용

신규 댓글 인덱스는 CONCURRENTLY/트랜잭션 밖·기존 부분 인덱스 유지. pg_stat_statements는 설치된 postgresql-14 패키지 제공, preload 변경 시 재시작1회. 서버 설정 원본과 diff·백업/복호화·모의 적용·실제 HTTPS·표/원본 hash 보존을 완료한 뒤 성공 기록. 업무 DB 삭제/복원 금지.
A는 기존 검색의 보호/안내 변경으로 patch 발행. B는 이후 별도 minor, C는 독립 검토 승인 전 운영 미반영.

## 배포 결과

미배포.

## 후속

SR-5·SR-12 등 사용자 범위 밖은 후속으로만 남긴다. PG14 업그레이드는 이 작업에서 수행하지 않는다.

측정 자료의 gen3.sql은 앞 세션에서 수동 생성한 perf.saved_idx를 참조했다. 이번에는 인덱스를 제거하지 않고 처음부터 유지했으므로 임시 DB에 빈 목록을 만들었다. 운영 표/설정과 무관한 시험 복구이며 전/후가 같은 DB라는 조건은 유지한다.

독립 검토에서 전역 파일 검색을 쓰는 자료실 화면도 발견해 같은 보호 안내/취소를 적용한다. 토큰 API 200은 기존 배열을 보존하고 X-Search-Status: short|timeout 헤더로 보호 상태를 알린다. nginx는 정규화 URI 전용 location으로 제한하고 파일/게시판 목록도 같은 8r/s 여유를 공유한다. 검색 경로의 원문 URI 오류 로그는 끄되 가린 access 로그의 상태·시간으로 추적한다.

## 중간 검사 증거

증거 기준 폴더 `/home/changkwon/qaredi-scale-results-20261012/`. `evidence/a-guard.log`15/0, `evidence/http-a2/results.json`공통196/0, `scale-results.json`새HTTP22/0. 첫 실행은 시험 파일명 http.py가 Python표준라이브러리 http를 가려 시작 실패했고 acceptance.py로 이름을 고쳐 통과(운영 영향0). 데이터생성 마지막 perf.saved_idx 조회만 실패해 빈 목록 작성 후 VACUUM ANALYZE만 재개했다. 재생성/운영행복사0.

같은DB SQL Execution Time: SR10 댓글2972개1234.561→12.702ms, 거의없는댓글679.686→0.143ms. SR6 합성회의 이전페이지2448.959+댓글446.750ms, 이후총3154.9ms에timeout. SR7 희귀영문 이전38952.251+2723.215ms, 이후3059.4ms에timeout. 후자는 검색 가속이 아니라 SQL3초예산 후 정리/응답 시간이다. SR5 측정4503.5/2098.4ms는 캐시변동이며 개선하지 않음. SR11은B/C전 그대로. 출력원문 a-before/after-*.log.

T17~19 기준선 사전 읽기전용 `sudo bash scripts/check_baseline.sh`: OK24·주의3·미적용6·확인불가0, 새실패를 없던 것으로 쓰지 않는다. 독립 검토 증거 `review/`: nginx8주소변형 모두35회중42918회, 원문검색어0; 토큰배열보존; 서로다른컨텍스트 PG3초/잠금/복구/바인드비밀미기록. 함수 EXECUTE 회수와 자료실 화면까지 보정. 최종 검토는 이후 기록한다.

설정: 현재pages live966/dead157/updates7239·blocks1315/dead217·views206/insert216·notifications20. pages/blocks/views/notifications vacuum.02/analyze.01, views insert.02, 최소50행 문턱 유지. FPM8+max_parallel_workers8을 고려해 temp_file_limit64MB(약1GB 합계)로 정함. 기존1GB/프로세스는 작은 디스크에 부적합.

운영 통계 읽기 명령(입력/문장노출0): `sudo -u postgres psql -X -d tbox_re -c "SELECT queryid,calls,round(total_exec_time::numeric,1) AS total_ms,round(mean_exec_time::numeric,1) AS mean_ms,rows,temp_blks_written FROM public.pg_stat_statements ORDER BY total_exec_time DESC LIMIT 20"`


최종 격리 HTTP: 공통196/0 + 규모25/0(`evidence/http-a-complete`), 토큰 실제 timeout200[]/헤더3014ms, 댓글 삭제부모/정렬/응답 전후동일 포함. 추가 댓글 시험의 첫 준비는 개인 페이지 POST로 게시판을 지정해 계약대로400이었고 게시판 전용 POST로 정정해 통과했다. 기존 qf2_search/stage5c/stage5d/stage26 원본 wrapper는 운영 데이터/자격에 의존해 실행하지 않았고 빈 합성DB의 실제창구 의미회귀로 대체했다. 기존 스크립트 전체 통과 횟수를 주장하지 않는다.

암호화 백업 `/var/backups/tbox-v2/scale-a-20261012`: DB·코드/설정·storage·역할globals4종 sha256/전체복호화통과, storage960개 양쪽일치,146MB. 백업 중 디스크최저약4.8GB로3GB이상. nginx전체설정 리허설은 임시 위치의 상대 include 경로를 두 차례 보정한 뒤 통과(운영설정 변경0), logrotate debug통과. 최종 runtime해시는 독립검토와 일치하며 temp64MB 수치만 재검토 요청. 신규 위험이 아니라 기존자원소진R의 보호, 보안핵심판정변경0. A는 검색 안내/장애보호 patch 발행.

PG 리허설 첫 적용은 임시 perf 사용자 기본 search_path에 perf 스키마가 있어 확장이 public 밖에 생성돼 REVOKE에서 멈췄다. `WITH SCHEMA public`과 기존namespace 검사로 보정하고 임시 확장만 제거 후 재실행 통과(`pg-rehearsal-final.log`). 운영은 아직 확장이 없어 영향0. 예상치 못한 기존 확장스키마가 있으면 자동이동하지 않고 멈춘다.

2026-10-12 A 최종 독립 검토 통과: review/review-A.md·reviewed-hashes.json, 런타임/설정 해시 차이0·남은 치명/주요0. HTTP25/0·공통196/0·보호15/0·위키69/0·금지패턴0. 확장 public 명시와 기존namespace불일치중단, nginx429 error 계약 보정까지 검토했다. 검토용 운영 HTTPS로그인200. 배포·관측·데이터보존은 아직 후속 검증 대상이다.

발행후 운영전 도구 호환성 확인에서 설치 logrotate3.19는 `--wait-for-state-lock`을 지원하지 않음을 확인했다. cron을 기본 state lock 방식으로 보정한다(동시실행은기본잠금으로거절,다음5분재시도). v2.60.1은 운영/원격미반영 검증후보이며 최종 patch번호를 새로 발행해 기존태그를 덮어쓰지 않는다.
