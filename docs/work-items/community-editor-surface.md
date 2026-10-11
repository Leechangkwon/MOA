# 커뮤니티 읽기 본문 배경색

- 상태: 운영 배포·실제 읽기·원본 보존 검증 및 원격 동기화 완료
- 기준: main45a7b909fdbb0db381d402f2ec09022435fc9421, v2.57.0
- 브랜치: cloud/community-editor-fix
- 계약: docs/community-editor-surface-directive.md

사용자가 지목한 글을 정상 QA 관리자 읽기로 확인했다. 원문은 편집 불가 판정을 존중하고 쓰지 않았다. .doc의 전역 배경 덮어쓰기와 기본callout/header 바탕의 명시배경 무시를 수정한다. 사용자는 “글을 열었을 때 본문”이라고 확정하고 해당 캡처를 제공했다. 처음 조사한 용지 최소폭은 이번 범위에서 제외하고 초안을 원복한다. 원문 자료는 Git에 넣지 않으며 글자/UUID가 익명화된 합성구조만 재현에 쓴다. 본문/표/서식 데이터·DB/설정·권한 변경0. 검증·배포 결과는 실측 후 추가한다.

## 원인과 사전 검증

실제 정상 QA API 읽기에서 원문은17블록/표4개/명시배경59개였다. .doc 바탕이 Aurora dark rgb(9,9,11)로 panel 바탕과 분리됐고, .doc-callout.nt-blue-bg가 기본회색47,47,47로 표시됐다. root가 실제읽기페이지에 후보CSS2개만 브라우저에서 대체해 검정사각형 제거·파란강조 복원·JS0/쓰기0·원문JSON불변을 확인하고 캡처를 직접 검토했다. 이 미리보기는 배포 성공 판정이 아니다.

공통196/0은 초기 사본의 격리 PHP/DB/HTTP/브라우저 검사이며 운영용 옛shell8전체를 실행했다고 하지 않는다. root 보안lint금지0·자산버전검사(39파일/63상대import)·위키69편 구조/누락0.

암호화백업 /var/backups/tbox-v2/product-spaces-editor-20261011 3종checksum/전체복호화·원본975파일SHA·설정85 baseline, 기존14표/제품6표의모든원래행 baseline 완료. DBmigration/운영복원/재시드0. 사용자 원문은표시확인읽기만하며 어떤 QA본문도 운영에 새로 만들거나 수정할 필요가 없다.

후보고정→변경파일 exact모의→서버/원격drift→코드FF/patch릴리스→실제HTTPS읽기→원문/원래행·원본파일·ownerACL·설정보존비교→기록/push 순서. 실물휴대폰·Safari·스크린리더는미검증.

## 후보 검증 완료

읽기 합성 회귀 317/0: 6개 디자인의 밝음/어두움과 360·390·412·820·1280, 고대비 12조합, 실제 PHP/JS 렌더 동등성, DOM·JSON 보존, 9색과 팔레트, 지원 ProseMirror 왕복·부분 수정 및 print 확인. 별도 컨텍스트 독립 검토 123/0과 팔레트 경계 3/0, 격리 공통 196/0. CSS 최종 SHA는 design.css `926a1b246f3642503aff479d16b25fdd00f38318e304ac720099b1ef64a7f430`, community.css `5f1205d41ea74aa3380074c5ed7ba787db688ca0524048ebcc101e235b8ff2ce`로 동일하다.

삭제된 팔레트 배경은 정의가 없어 mixed header_col 머리칸이 부모 바탕을 따를 수 있다. 일반 데이터 칸에 새 바탕을 강제하지 않으며 굵기·테두리 구분을 유지한다. 이 경계는 root가 명시 수용했고 기본색/살아 있는 팔레트의 cell > row > header 우선순위는 검증했다. 기존 꾸민 표의 편집 보호 예외는 유지하며 원문 편집 성공을 주장하지 않는다.

증거: 작업 artifact outputs/community-editor-checks/{results.json,report.md}, outputs/community-editor-review/independent-read-results.json, outputs/community-editor-cross/results.json. 재실행: `EDITOR_SURFACE_RESULTS=/tmp/editor-surface-checks python3 tests/community/editor_surface.py`. Fixture는 글자와 UUID를 익명 합성값으로 바꾼 구조 표본이며 원문은 저장소에 넣지 않았다. 초기 실제 읽기에서 숨겨진 편집 버튼 클릭 대기는 QA 권한을 잘못 가정한 시험 오류로 종료됐고, 숨김을 존중하는 읽기 전용 검사로 바로잡았다. 운영 원문 쓰기는 없었다.

실패 대응은 원인 확인과 이전 CSS의 검토된 코드 패치로 제한한다. 운영 DB 복원·삭제·재시드는 수행하지 않는다.

## 운영 결과

운영 최종: v2.57.1 `3b9611168e38` / 2026-10-11T21:46:40+09:00. 고정 후보 `3ea11741296a`의 13파일 모의 적용·서버/원격 드리프트 통과, 코드 FF/patch 릴리스와 PHP-FPM reload 완료. 실제 지목한 글 HTTPS62/0·CSS2 정확 바이트·JS/5xx0·브라우저 쓰기0. PC/모바일 Aurora/Moa 및 Claude에서 본문 바탕과 지정 강조색·표 스크롤 정상, 원문 제목/본문/서식/버전1 동일. 기존14표·제품6표 모든 원래행(기존 QA 취향 포함, 제외0)·1042파일행·975원본 SHA·ownerACL·85설정 보존. 암호화3종 checksum/전체복호화 완료, worker cursor1은 운영 상태로 제외. DBmigration/설정/패키지 변경0. 실물휴대폰·Safari·스크린리더 미검증.

증거: outputs/community-editor-live/{results.json,aurora-dark-1280.png}, community-editor-live.log, community-editor-preservation.log, community-editor-product-preservation.log, community-editor-fix-rehearsal.json. root가 실제 운영 Aurora dark 캡처를 직접 확인했다. 원문을 다시 저장하거나 권한을 바꾸지 않았다. 검토 최종 보고는 outputs/community-editor-review/review.md.

원격 동기화: main/cloud/community-editor-fix와 v2.57.1·selftest/review 기준 태그를 atomic push 후 fetch하여 `61ad5f5185d560842374e0b6994dbcb6744360e7` 일치를 확인했다. 이 기록 보완도 같은 두 브랜치로 전달한다. 부모 문서는 현재 작업 절만 선택 병합하여 로컬 `86cad0f`에 기록했다.
