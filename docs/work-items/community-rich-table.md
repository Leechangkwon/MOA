# 커뮤니티 표 밀도와 서식 보존 편집

- 상태: 후보 검증 완료, 운영 적용 준비
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
