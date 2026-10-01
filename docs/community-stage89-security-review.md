# 커뮤니티 8~9단계 보안 검토 기록

기록일: 2026-10-01. 사용자 「반영해」 승인 후 **v2.41.0 운영 DB·권한·78파일을 반영했으며 5분 cron 등록·첫 자연 실행을 확인했고 공개 UI 최종 검증은 진행 중**이다. 기준 `stage89-8b-baseline-20261001`(7ef69bed), 최종 검토는 전체 diff와 마지막 수정까지 포함한다. 구현/검토 담당과 원시 실행 결과는 각 인계 JSON에 남긴다.

## ① 안전한 이유 — 실행 증거가 있는 범위

반영 전 동결본 최종 결과는 작업 부모 `final-results/final-functional-summary.json`에 실행 파일별 SHA256과 함께 보관한다.

| 범위 | 통과/실패 | 근거 |
|---|---|---|
| 문서 신뢰·자동화 기초 HTTP |97/0| `final-results/http/results.json` |
| 자동화 원본·DB/팀 템플릿·권한 |109/0| `final-results/backend/backend-results.json` |
| 배치200/500한도·다음 실행 이어가기 |39/0| `final-results/batch/batch-results.json` |
| 실제 자동 작업 UI |31/0| `final-results/ui/automation-ui-results.json` |
| 원본/실행 이력·마이그레이션 재적용·관리관문 |39/0| `final-results/integrity-03/root-integrity-results.json` |
| 내보내기·인쇄 |97/0(변환14+HTTP55+브라우저28)| `final-results/8b/` |
| 오늘 브리핑·런처 |55/0(HTTP26+브라우저29)| `final-results/9d/` |
| 파일명 변경 보존 |123/0| `final-results/rename/regression-results.json` |
| 공통 경로 실제HTTP/브라우저 |149/0| `root-cross-results-08/results.json` |
| 이전 커뮤니티 호환 |184/0| `legacy-compat-results-02/legacy-compat-results.json` |
| 파일 반영·거부·재실행 모의검사 |53/0| `deploy-package-results-01/results.json` |
| 운영 nginx/FPM/API·CSS후보 대체 브라우저 |82/0| `production-validation/production-smoke-results.json` |

별도 초기 보안41개는 알림 혼입·인계 전 대기 행·원본 참조 인계·201번째 규칙을 공격한 증거로 보존한다. 후속 동결본은 원본 출처를 실행 이력에 고정하고 현재 권한으로 표시하며, DB/팀 템플릿·검증 만료 후보·거부 알림·배치 상한을 보강했다. 전체 security_lint0, 부품 버전37/0, PHP250파일, wiki_check0도 확인했다. 정적 원문은 `final-results/static/`이다.

운영 적용 근거는 `production-deployment-latest.json`과 `production-backup-20261001-212134/`다. 적용 후78파일 새 해시를 확인했고, 적용 직후 기존 업무의 건수·본문/파일 식별자 집계는 반영 전과 같았다. cron은 기존73줄을 보존하고5분 tbox-php 항목1개를 추가했다. 수동 첫 실행은0run·0work·0created(`production-validation/cron-installed.json`). 21:30:01 첫 자연 실행은 journald CMD·cursor 갱신으로 확인했다(`reviewer-cron-first-tick.json`). 자연 종료코드는 직접 미수집이고 수동 실행exit0과 구분한다. 운영 스모크82개는 시험 fixture·토큰을 정리했으며 브라우저8화면은 CSS후보 대체 검사다. 파일 검증기의 `schema=not_checked`는 파일 검증에 한정되며 DB 적용 사실은 별도 `migration.log`로 확인한다.

## ② 불안전한 이유 — 남은 결함과 수용 위험

독립 검토에서 지적한 반복 원본의 불변 이력, DB/팀 템플릿, 배치200회·500페이지와 공정한 이어가기, 검증 만료 후보의 권한 필터, 사라진 원본의 거부 알림은 보강 후 위 동결본 시험으로 확인했다. 최종 기능 독립 검토의 차단 사항은0개다(`independent-functional-review-20261001.md`). 실행기 별도 독립 검토도 통과했다(`independent-deployment-runner-review-20261001.md`). 검토자가75개 old/payload 해시·F2보존5개·53/0증거와 운영 읽기전용 확인을 대조했다.

내보낸 사본은 공유 해제 뒤 회수할 수 없다(C:R-12, K8-11~18 수용). 자동화는 현재 권한으로 실행되더라도 사용자가 잘못 정한 일정·제목으로 반복 생성할 수 있다(C:R-13). 시험 실행·상한·끄기·기록·선택적 되돌리기가 통제 수단이다.

## ③ 미검증

- 적용 중 프로세스 강제종료·디스크 부족·비협조 동시 쓰기의 실제 장애 주입은 미실행이다. 파일별 원자 교체·진행 저널과 재개 경로는 검증했지만 전체 파일 트랜잭션으로 보장하지 않는다.
- CSS실제 적용 뒤 공개 HTTPS 브라우저 최종 검증은 진행 중이다. cron 첫 자연 실행은 CMD와 cursor 갱신으로 확인했으며 자연 실행의 종료코드는 직접 수집하지 않았다. 모바일 긴 제목의 가로 넘침 CSS3줄 수정은 후속2.41.1로 준비한다.
- 원본 common8 전체 행렬과 운영 TLS/속도제한 전체 경계는 미실행이다. 실제 nginx/FPM/API의 이번82개 스모크 범위와 합성 어댑터 검증을 구분한다.
- 사용자 자기 공격. 관문③을 AI 시험으로 대체하지 않는다.

## ④ 다음 조치

파일 패키지는 운영형태 a39ec15+F2코드5개의 격리 사본에서53/0으로 적용·재실행·부분재개·거부 경계를 확인했다. 운영 경로 읽기전용 검증은75파일 모두 이전 해시와 일치했다(`deploy-package-results-01/operating-readonly-verify.json`). 기능 커밋c431537·발행e44f3b9(v2.41.0)의 최종78파일 패키지는 사용자 승인 후 운영에 반영했다. 공개 HTTPS UI 검증과 후속2.41.1을 확인하고 결과를 이어 기록한다.

## 공통 회귀의 범위(2026-10-01 추가)

`tests/stage89_cross_regression/`는 빈 합성 DB로 인증·5역할·역할보기·관리관문·파일/폴더 토큰API·F66발급/회수·F68일부 문구·런처·도움말4화면을 실제 검증했다. 원본 common8 스크립트는 실행하지 않았다. 운영nginx/FPM/TLS/속도제한·옛 정적주소 리다이렉트, F66/F68전체행렬과 stage26원본108개 통과는 주장하지 않는다. 정확한 대응/제외는 시험 README를 따른다.
