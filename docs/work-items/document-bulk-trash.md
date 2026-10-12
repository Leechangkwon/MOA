# 내 문서·업무 사이드바 선택 삭제

- 상태: 운영 v2.61.0 검증·Git2 동기화 완료
- 기준 main: `e618320c6297843dd9367971849593b4e49c5db8` / v2.60.0
- 작업 브랜치: `cloud/document-bulk-trash`
- 담당 환경: 서버의 격리 worktree. Codex root, 백엔드·프런트 구현 담당, 별도 컨텍스트 독립 검토 담당.

## 목적과 범위

업무 사이드바의 최근·프로젝트·문서를 기본으로 접고 직접 여닫은 PC 상태를 사용자별로 기억한다. 내 문서의 일반 목록·검색 결과와 사이드바의 문서 행에서 개별·부분·현재 목록 전체선택 휴지통 이동을 제공한다. 선택 상한은 기존 100개이며, 중복 UUID는 한 번만 처리한다. 부모의 선택하지 않은 하위 문서도 함께 이동하는 점을 확인창에 표시한다.

기존 순차 실행기와 DELETE/restore API, 항목별 실패·재인증·10초 되돌리기·30일 휴지통 복원을 재사용한다. 작성 중인 글은 저장 실패나 충돌 상태에서 삭제하지 않는다. 목록의 `can_delete`는 편집 공유와 구분되는 실제 관리 권한이다. 프로젝트 목록 갱신을 위해 기존 GET `/api/pages?view=projects` 읽기 갈래만 추가한다. 새 라우트·DB migration·패키지·운영 설정 변경은 없다.

변경 범위는 목록 읽기 handlers 세 곳과 프로젝트 helper, editor/sidebar/home UI, 공통 확인·결과 view, CSS, 자산 버전, 문서·위키다. 자세한 동작과 파일 소유권은 `docs/document-bulk-trash-directive.md`를 따른다.

## 검사

- 테스트 전 기준 태그 `selftest-base/document-bulk-trash`, `review-base/document-bulk-trash`와 별도 bundle 생성.
- 빈 합성 DB 초기 공통 회귀: 196/0. 출력 `outputs/document-trash-review/cross/`.
- 실제 PHP/SQL 백엔드 자체 검사: 307/0. `python3 tests/community/memories_harness.py --port 8818 --suite document_trash_api.py`를 전용 출력 경로와 함께 실행. 보고서 `outputs/document-trash-backend.md`, 결과 `outputs/document-trash-api/document-trash-api-results.json`.
- 독립 API 검사: 최초 80/0, 후속 78/0. 일반·트리 목록의 게시판 작성자 `can_delete` 불일치 1건을 별도 재현한 뒤 보정 검증했다. 최종 PHP 네 파일 해시 `outputs/document-trash-review/backend-final-sha256.json`. 최초 fixture 오류와 외부 테스트 DB 연결 종료는 앱 결함과 구분해 백엔드 보고서에 기록했다.
- 기존 `qf3b.sh`는 운영 DB dump를 사용하고 옛 stage4 wrappers는 운영 포트를 대상으로 하므로 실행하지 않았다. 필요한 권한·연쇄삭제·복원·감사·원문 보존을 빈 합성 DB에서 검사했다.
- UI, 최종 린트·자산·위키, 실제 HTTPS 검증은 완료 후 아래에 기록한다. 실물 휴대폰·Safari·스크린리더·실물 IME는 미검증이다.

## 서버 적용

- 별도 백업: `/var/backups/tbox-v2/product-spaces-document-trash-20261012`. DB·코드·설정/저장소 암호화 세 묶음의 checksum과 전체 복호화, 원본 저장 파일 959개 SHA 대조 완료.
- 기존 14개 표와 제품 6개·메신저 5개 표의 모든 원래 행, 파일 1042행, owner/ACL, 설정 85개의 보존 기준을 기록했다. 기존 QA 행도 제외하지 않는다. 운영 worker cursor 한 파일만 일시 상태로 제외한다.
- 고정 후보 → 파일 적용 모의 → 운영/원격/부모 문서 drift 확인 → FF/minor 릴리스/FPM reload → 실제 HTTPS → 원본 보존 → 배포 기록·원격 동기화 순서.
- 운영 시험은 새 예약 QA 문서만 생성·삭제·복원한다. 원래 사용자 글은 삭제하거나 수정하지 않는다. 시험 후 새 QA 문서만 정상 API로 휴지통 처리한다.
- 실패 시 원인을 보정한다. 사용자 최신 데이터나 다른 작업을 덮어쓰지 않고 운영 DB를 복원·재시드하지 않는다. 코드 복귀가 필요하면 이번 변경에는 스키마 변경이 없음을 확인하고 이전 코드를 사용한다.

화면 자체 검증: 실제 PHP view와 운영용 JS를 읽고 합성 API 응답으로 브라우저 최종 53/0 통과. 검색 유지·필터의 부모 없는 자식·100개 한도·중복 UUID·연쇄 처리·권한 실패·되돌리기·401 후 닫기·복원 중 세션 만료·재인증·dirty 저장 선행/409 차단·현재 조상 삭제 시 본문 정리와 되돌리기·모바일 native 메뉴 위 확인을 검사했다. `tests/community/document_trash_browser.py`, `outputs/document-bulk-trash/frontend/results.json`. 실제 DB API와는 구분한다.

최종 정적 검사: 보안 린트 금지 패턴 0, 변경 PHP/JS 문법·diff 검사 통과, 자산 40파일/상대 import 71개 정합, 위키 69편/문제0. community 모듈 버전 `496fe6f9282c`, ProseMirror `5823578fa61a`. editor를 제외한 dashboard/database/suggestions/table-editor/templates/work/messenger JS 변경은 생성된 import 버전만이다. 최종 독립 UI는 이 생성 결과로 실행한다.

독립 UI 최종: 실제 격리 PHP·PostgreSQL·브라우저의 의미 검사를 중복 제거하고 최신 증거로 합쳐 80/0. 같은 계정 재개, 현재 문서 조상 삭제 전 저장·삭제 후 본문 정리·되돌리기, 무관한 삭제의 동일 편집 DOM·URL·입력 유지, 프로젝트 10개/더보기/생성 진입·복원 갱신을 확인했다. `outputs/document-trash-review/final-review.json`에 실행별 결과와 미검증 범위를 기록했다. UI 시험의 CSP 문자열 평가·편집 URL 정리·중단 문구의 완료 오인 세 종류는 하네스만 고쳐 해당 경우를 다시 확인했고 런타임은 변경하지 않았다. 첫 API의 게시판 capability 실제 결함은 보정 후 검증했다. 미해결 제품 결함 0.

최종 런타임은 19파일이며 네 번의 독립 UI 시험과 현재 SHA가 같다. 그중 7파일은 import 버전 생성 변경뿐이다. root의 `outputs/document-trash-source-freeze.json`과 독립 `runtime-final.sha256`를 함께 확인했다. 모바일 360/390px 화면 및 44px 터치 영역을 Chromium에서 확인했으며 실물 기기 검증으로 표현하지 않는다.

## 최신 검색 개선과 통합

최초 후보 `1b19bc522f036ac097f8d2b4e343e648a8b7e1d0`의 운영 직전 drift 검사에서 먼저 배포된 규모 A·검색 안정성 변경을 발견했다. 기존 후보와 증거는 보존하고 최신 `e1d0ce80f454d2d048b2cda60e9f51e1d94a4f48` / v2.60.2를 작업 브랜치로 병합한다. editor.js·app.js의 정규화한 diff는 양쪽 의미 변경의 정확한 합집합이며 중복·겹침이 없다. 해당 증거는 `outputs/document-bulk-trash/frontend/merge-semantic-proof.json`이다.

문서 삭제 PHP 4개를 포함한 PHP 8개·CSS 2개는 기존 검토 SHA와 같아 API 307/0·독립 158/0 결과가 그대로 적용된다. 생성된 import 7파일은 upstream과 버전 표기만 다르다. 신규 기준에 DB·설정·패키지 변경을 추가하지 않으며, 규모 A의 이미 적용된 검색 보호·인덱스·관측 설정을 그대로 보존한다.

통합 자산 `b6ea4f321402` / ProseMirror `5823578fa61a`, 40파일/71import. 통합 UI 자체검사 53/0, 보안 린트 0·PHP/JS 문법·위키 69/0 통과. `outputs/document-bulk-trash/integrated-frontend/`, `outputs/document-trash-integrated-source-freeze.json`. 독립 영향 검사 48/0, 아래 최종 기록 참조.

최신 운영 별도 백업 `/var/backups/tbox-v2/product-spaces-document-trash-integrated-20261012`: DB·코드·설정/저장소 암호화 3종 checksum/전체 복호화 확인. tar의 파일변경 경고는 959개 안정 원본 파일 SHA 전체 대조로 검증했고 worker cursor 1개만 제외했다. 원래 파일 1042행·14+6+5표 모든 기존 행·owner/ACL·PostgreSQL 포함 설정 93개를 새 보존 기준으로 기록했다. 기존 백업·실패한 drift 증거는 덮어쓰지 않았다.

통합 독립 영향 UI: 실제 격리 PHP/PostgreSQL/브라우저 고유 의미 검사 48/0(반복 fixture HTTP 제외). 늦은 검색 응답이 최신 목록·선택을 덮지 않음, 실제 삭제/복원·401 중지와 같은 계정 재개, 사이드바, upstream 게시판 요청 취소·429 후 재시도·통합 검색 닫기를 확인했다. 기존 테스트의 hidden tree 중복 selector/개수 산정과 선별 fixture의 개인 공간 초기화 3건은 하네스만 보정하고 실패 로그를 보존했다. 미해결 제품 결함 0. `outputs/document-trash-review/integrated-final.json`; 최종 runtime 19가 root 동결과 네 번의 검토 snapshot 모두 같음(manifest SHA `25822a29bf57560958e94438904489835368b11a003381ae522fbd528f6330c4`). 운영 HTTPS·실물 기기는 별도다.

## 운영 최종 결과

운영 최종: v2.61.0 `35569143809d` / 2026-10-12T13:16:23+09:00. 내 문서 검색·트리와 업무 사이드바에서 개별·부분·현재 목록 전체선택 휴지통 이동을 제공한다. 최근·프로젝트·문서는 기본 접기와 사용자별 PC 펼침 기억, UUID 중복 제거·100개 한도·하위 동반 이동 확인·실패/재인증·10초 되돌리기·30일 복원을 유지한다. 저장 실패·충돌 문서를 보호하고 실제 삭제 권한을 표시한다. 고정 후보 36파일/runtime 19 모의 적용·fresh drift·FF/minor/FPM reload, 실제 HTTPS 46/0·4자산 정확 바이트·JS/5xx0. 새 예약 QA 문서 8개만 생성·삭제·복원 후 휴지통 정리, 기존 원문 쓰기0. 기존 14표+제품6표+메신저5표 모든 원래 행(제외0), 1042파일행·959원본SHA·ownerACL·93설정 보존. 암호화3종 checksum/전체복호화 확인, DBmigration/설정/패키지변경0. 실물휴대폰/Safari/스크린리더 미검증. 상세 docs/work-items/document-bulk-trash.md.

고정 후보 `4178ecf5dd3294ce5dd68fc1275c6376121266d3`, 릴리스 `35569143809dd7c5c46998fe1ff5a3fa54828471`. 증거는 outputs/document-trash-integrated-rehearsal.json, document-trash-integrated-drift.json, document-trash-live/results.json·cleanup.json, document-trash-integrated-preservation.log·document-trash-integrated-product-preservation.log다. 실제 HTTPS의 모든 브라우저 쓰기를 새 QA UUID 허용목록으로 제한했고 원래 문서·파일·개인 설정은 변경하지 않았다.

첫 HTTPS 시도는 숨겨진 tree와 표시 목록의 동일 UUID 버튼을 시험 selector가 동시에 잡아 strict-mode로 중단했다. 실제 앞선 삭제/복원은 통과했고 신규 QA 4개 전부 정상 휴지통 정리했다. selector를 #community-list로 한정한 재시험을 완료했으며 앱 런타임 수정은 없었다. 첫 로그·정리는 outputs/document-trash-live-first.log 및 document-trash-live-first/cleanup.json에 보존했다. 두 실행의 신규 QA를 모두 보존 검사 기준 이후에 생성했고 기존 문서 쓰기0이다.

Git2 동기화: 2026-10-12T13:17:33+09:00 main·작업 브랜치·v2.61.0·최초 기준 태그 2개를 atomic push하고 원격 ref가 로컬과 같은지 확인했다. 서버 부모 문서 커밋 `a4e006c`는 원격 없는 부모 저장소에 기록했으며 최신 규모 A 문서를 보존했다. 실제 검증 runtime 19의 바이트는 후속 문서 커밋에서 바뀌지 않는다.
