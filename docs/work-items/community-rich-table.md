# 커뮤니티 표 밀도와 서식 보존 편집

- 상태: 운영 검증 완료, Git 동기화 준비
- 작업 브랜치: cloud/community-rich-table
- 기준 main: f3bec3509f271fb549731bf52780781e7baa68c3, v2.57.1
- 담당 환경: 서버 접근 가능한 Codex, 구현/독립 검토 분리

## 목적과 범위

사용자는 표가 글씨에 비해 크고, 편집을 누르면 꾸밈이 든 표/토글/설명 제한이 뜬다고 지적했다. 원문은 표4개, 꾸민 표가 문서 전체 편집을 막는다. 표 CSS의 6rem 최소폭·상속 line-height1.8을 개선하고, 표의 원본 JSON을 보존하는 단일 선택 셀 ProseMirror 편집을 구현한다. 자세한 계약 docs/community-rich-table-directive.md. 다른 atom의 보호 제한은 유지한다.

## 검사

구현·실행 후 실제 결과를 기록한다. 원문은 정상 QA 읽기만, 실사용자 문서 쓰기0. 실제 저장 시험은 예약 QA의 신규 합성 문서만 사용한다. 실물/Safari/스크린리더 미검증.

## 서버 적용

DB/패키지/설정 변경 없음. 후보 검증 후 암호화 백업3종 전체복호화·기존행/원본/설정 baseline, 후보 고정/정확 파일 모의 적용/드리프트→코드/릴리스→실제 읽기·QA 편집/저장→원본 보존 검증→기록/push. 실패 시 원인 확인과 검토한 코드 보정, DB 삭제/복원/재시드는 실행하지 않는다.

## 중간 검증·보존 준비

초기 격리 공통 196/0, 소유 합성DB/서버 정리 확인. CSS/밀도 391/0: 6테마 밝음/어두움+주요 고대비, 360/390/412/820/1280, 실제 모달 EditorView1개, 44px 셀 선택, 긴 문장/병합/DB기존밀도, 화면용 용지4종과 인쇄A4 유지. 합성 표 14px 유지, 행88.56→51px, 16열1537→1176.80px, 9열865→710.06px. 원문 읽기 페이지에 후보 CSS만 대체한 preview에서도 root가 개선을 직접 확인했고 원문 JSON 불변·JS0·쓰기0. 이는 운영 배포 성공 판정이 아니다.

암호화 백업 /var/backups/tbox-v2/product-spaces-rich-table-20261011 3종 checksum/전체복호화, 원본975 SHA 확인. 기존14표/파일1042행·제품6표 모든 원래행(기존QA취향 포함)과 ownerACL, 설정85 baseline 완료. 운영DB복원/재시드0. 증거 outputs/community-rich-table-backup*.log, -product-baseline.log.

## 독립 검토

최종 cell35/0 + 실제 PHP화면/Docvalidator·mock API 통합25/0. 같은 생산 import URL c481218d6d2c/PM5823578fa61a로 raw복제ID/clipboard/ownDOM mention/즉시한글flush/부모undo/redo/합성IME를 검증했다. CSS391/0와 공통196/0는 별도 실행 범위. 403/409은 합성 응답에 대한 UI/초안보존 검사이며 실제 endpoint인가를 직접 실행했다고 주장하지 않는다. 최종 opened blocker0, runtime11 SHA root와독립동일. source-sha256.json/review.md는 outputs/community-rich-table-review/.

초기 시험 실패 중 PHP Doc::validate의 {ok,errors}를 잘못 해석한 mock400, reload후 테스트전역 재등록 누락, merge의 보존anchor/제거target 혼동은 하네스 오류로 바로잡고 핵심 assertion은 유지했다. 구현 중 발견된 외부HTML clipboard parser 혼용, span없는 끝행삭제 거부, 복제표rawID, 합침21 상한은 코드에서 수정해 재검증했다.

## 최종 자체 검증

production과 동일한 import URL에서 편집 UI45/45, 읽기 회귀317/317 통과. 실제 PHP markup·PM·Doc::validate와 합성 API를 사용하여 한글 셀 수정/저장 payload/reload·undo/redo·무수정 정확 보존·취소·403/409 실패 UI·fresh readonly 진입차단을 확인했다. 거부된 페이지의 pagehide 재저장이 readonly 관측과 섞이는 하네스 문제를 새 context로 분리해 해결했다. browser_5e.py는 격자 selector/표 기대값 수정과 compile만 수행했으며 기존 운영 쓰기 wrapper 전체 실행으로 주장하지 않는다. 실물 IME/Safari/스크린리더 미검증. 보안 lint금지0, 자산40파일/71import, 위키69편, PHP/JS 문법·diff check 통과.

## 운영 1차와 경계 보정

고정후보1b7993c20a86의25파일/runtime11 모의적용·fresh main/origin f3bec350 drift통과, v2.58.0 0847dcc4a05d472dd6ac7cfd32ceea8b7167b332 FF/reload. 신규QA 한셀100→1005 실제PATCH200·API문서정확보존·reload통과. 390px #main은 client390/scroll447로 검사 실패: page-editor350/PM316/모달358 자체는 맞고 기존 inline-toolbar 링크/멘션 버튼의 행이 넘친다. 합성 화면에 없는 실제 toolbar 폭 문제라서 assertion을 없애지 않고 최소CSS와 회귀를 보충한다. 아직 전체 운영 검증 완료로 판정하지 않는다.

## 도구줄 보정 검증

부모 editor-toolbar__row의 flex-wrap 한 속성만 추가했다. 독립 actual PHP/production JS 4테마×5폭과 색·배경·링크·멘션105/0, 390px main390/390 확인. 자체 전체 geometry·Tab·모달 접근성 검사도 통과했으나, 30초 편집상태 heartbeat POST를 문서쓰기로 오인한 하네스 마지막 assertion이 실패했다. heartbeat만 합성200 응답으로 처리하고 실제 문서 PATCH 및 그 밖 쓰기 금지는 유지하여 최종 재실행한다. 앱/인가/저장 코드 변경0.

최종 보정 검증: 독립105/0, 자체 전체 matrix의 geometry/키보드/모달372항목 통과(마지막 heartbeat 오분류 하네스 실패 로그 보존), heartbeat만 합성 정상응답으로 보정한 좁은390px 최종24/0·JS/CSP0·문서쓰기0. CSS a807b324256d51d35d0f2df59f36205e284b7839fc709a4f0530cf82c0216372, 나머지 runtime10 바이트 불변. 전체 기존45/317/391 재실행으로 과장하지 않으며 변경된 한 속성에 대한 별도 검증이다.

## 운영 최종 검증

운영 최종: v2.58.1 `ac847cec7dcb` / 2026-10-11T22:29:11+09:00. 서식 표 원본 JSON 보존·선택 셀 편집과 읽기 밀도 개선. 초기25파일/runtime11 고정 모의 적용 후 실제 모바일 toolbar 넘침을 발견해 CSS1 경계 보정·별도 고정 모의/드리프트·patch/FPM reload까지 완료. 실제 HTTPS 최초 저장18항목·후속36항목 통과: 예약QA 신규 글 한셀100→1005 실제 저장/reload, 나머지 모든 블록·색·링크·열폭 정확 보존, 390/820/1280 편집·모달·취소/내부폭 확인. 원래 사용자 글은 읽기만 했고 제목/본문/서식/버전 동일. JS/5xx0·라이브자산5 바이트 일치·최종runtime11 검토hash 동일. 기존14표·제품6표 모든 원래행·1042파일행·975원본SHA·ownerACL·85설정 보존. QA 신규 문서2개만 정상 API로 soft-delete, 기존 자료 수정0. 암호화3종 checksum/전체복호화·DBmigration/설정/패키지변경0. 실물IME/휴대폰·Safari·스크린리더 미검증. 상세 docs/work-items/community-rich-table.md.

증거: outputs/community-rich-table-live/results.json 및 실제 화면 캡처, community-rich-table-preservation.log, community-rich-table-product-preservation.log, community-rich-table-rehearsal.json 및 community-rich-table-toolbar-rehearsal.json. 첫 실패 로그와 QA 정리 로그도 별도 보존했다.

실제 HTTPS 최종 검증의 첫 세션은 저장·정확서식보존·reload·390 내부폭까지18항목 통과한 뒤 Escape 한 번으로 모달이 닫힌다고 가정한 하네스가 뒤의 완료 버튼 클릭에서 timeout했다. 모달의 명시적 닫기 버튼으로 취소를 확인하도록 하네스만 수정하고 이미 저장된 같은 QA 문서에서 이어 검사했다. 추가 문서 수정 없이 원래 기대JSON exact equality부터 확인하고 390/820/1280 모달열기/닫기·원문읽기·5자산·JS/5xx·soft-delete36항목 통과. 두 세션은 동일 최종 runtime이며 Escape 닫힘 검증으로 주장하지 않는다. 첫 실패 로그 community-rich-table-live-second.log와 후속 community-rich-table-live-resume.log를 함께 보존한다.
