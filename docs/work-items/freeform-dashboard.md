# 대시보드 위젯 자유 이동·크기 조절

- 상태: 운영 v2.55.0 반영 및 HTTPS·원본 보존 검증 완료
- 작업 브랜치: cloud/freeform-dashboard
- 기준 main: 3a8145187bc7db0c02c16c8490749b3b9f7f565d
- 담당 환경: 서버의 격리 worktree

## 목적과 범위

개인 홈 4종/공간 홈 5종 기존 위젯에 임의 위치 드래그와 가로·세로 resize를 적용한다. 편집 화면에서 숨김/복원·초안 저장/취소, desktop/mobile 별도 배치, 계정 동기화와 revision 충돌을 제공한다. 신규 위젯의 업무기능 및 단계별 가입안내는 별도 후속 범위. 계약/허용 파일은 docs/freeform-dashboard-directive.md.

## 검사

진행 중. 직접/독립 backend 새 격리 Postgres333/0·실제HTTP214/0 및 별도 독립 타입·경계186/0. GET 행/수정시각/감사 무변경, v1/v2 양 홈 저장, 거부된 저장의 무변경, 역할/acting/archive/revision·원본파일 권한 확인. frontend 최종 동결 및 운영검증 완료. 직접 strict-CSP 실제 마우스/터치142/0, 기존 홈57/0·서비스홈70/0·동기화15/0·공간26/0·지연14/0·메뉴96/0(합278/0). 최종 동결본 독립 브라우저142/0·추가55/0·권한 회수20/0 통과, 검토한 runtime7 SHA 동일. root 격리 공통 교차회귀196/0(새 합성 DB만, 운영원본 복제0, 정리완료), 자산버전·위키69편·diff 검사 통과. 새 PHP/JS 문법과 backend 보안lint 통과. 실물 Android/Safari는 별도로 구분한다. 증거 outputs/freeform-review/backend/test.log.

## 서버 적용

- DB migration, 패키지/설정/cron 변경 없음. 기존 JSONB에 검증된 v2 좌표만 저장한다.
- 기존 v1 데이터 유지, GET 쓰기0. API revision·권한 불변.
- 백업 /var/backups/tbox-v2/product-spaces-freeform-20261011: 암호화 DB/코드/설정·원본저장소 SHA 및 전체 복호화 확인 완료, 백업 안975 원본SHA 일치·설정85 baseline·14기존표+제품6표 보존기준 기록. 허용되는 운영검증 변경은 검토계정 claude_admin01 본인 홈 저장/복구와 신규 합성QA공간뿐. 그 외 기존행 유지.
- 백업 검증 → 고정후보 모의적용 → drift 확인 → main fast-forward/발행/PHP reload → 실제 HTTPS 및 원본 보존 검사.
- 실패 시 이전 코드 복귀, 새 v2 layout 데이터 보존. 운영 DB 삭제/복원/재시드 금지.

## 배포 결과

후보5c4e894a242b → main FF, v2.55.0 `586a8eacb27ac1abdcba9229d2fc13e4eca00aa2` / 2026-10-11T18:30:46+09:00. 25파일 고정후보 모의적용 정확바이트·삭제0, runtime7 검토SHA 일치, 운영/원격3a8145 clean drift 확인 후 PHP-FPM reload. 실제 HTTPS84/0: 개인/공간 각각PC1280 및모바일390 touch CDP로 실제 이동/양방향resize/저장/reload/취소/다음저장·반대viewport보존 확인. JS오류/5xx0·서비스active·정확자산바이트확인. 기존14표/파일1042행/975원본SHA/ownerACL/85설정 보존. 제품6표 기존행보존(검토계정 개인홈만 API저장 후 원래설정복구; 신규합성QA공간 최종보관). DB migration/설정/패키지/cron/PC변경0, DB복원/재시드0. 운영증거 outputs/freeform-live/results.json, outputs/freeform-live-run.log. 실물기기/WebKit/스크린리더 미검증. Git2동기화 후속기록.

## 검토 중 수정

드래그 뒤 숨김의 오래된 객체 참조, 연속 저장 revision, 임시 서비스 표시의 겹침, 최소폭의 최근 버튼 넘침을 수정했다. v2 공간에서403 뒤 canvas 정리가 콘텐츠를 다시 붙이는 순서 결함을 독립 재현하고 destroy→데이터/DOM purge 순서로 수정했다. 저장403·새로고침403·전체보기403의 콘텐츠/멤버/편집기 제거20/0으로 재검증했다. 표시용 좌표 snapshot이 자료 종류 설정을 되돌리지 않게 현재 설정과 현재 화면의 좌표만 결합한다.

최종 증거: outputs/freeform-dashboard-checks/acceptance-summary.json(자체 UI420/0), outputs/freeform-review/final-integration.log(142/0), final-probes.log(55/0), revocation.log(20/0), reviewed-hashes.json(runtime7), outputs/freeform-cross/results.json(196/0). 실제 실물 휴대폰/WebKit·사용자 자기공격 미검증.

Git2: main·cloud/freeform-dashboard·v2.55.0 및 기준 검토태그 atomic push/fetch 완료, 두브랜치 cee2958da31d8353f819ee7035eb80814e092fa7 일치. 태그는 검증런타임586a8ea를 가리킨다. 이 메타데이터 후속도 같은 두브랜치로 전달한다.
