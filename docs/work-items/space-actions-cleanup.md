# 공간 홈 버튼 정리
- 상태: 후보/독립 검증 완료 · 서버 적용 준비
- 작업 브랜치: cloud/space-actions-cleanup
- 기준 main: 7708d36b929497ca4066353f1af1ae1247000896
- 담당: 서버의 격리 worktree 구현, 별도 독립 검토, root 운영.

## 목적과 범위
13053.jpg의 등록/관리 버튼 밀집을 등록하기·공간 메뉴로 묶고 카드 보조동작을 접는다. 지시서 docs/space-actions-cleanup-directive.md. 기능/권한/데이터 보존, backend/API/DB/설정 변경0.

## 검사
직접 신규메뉴96/0·기존UI26/0·지연응답14/0. 독립 역할/메뉴122/0+6디자인/밝기/고대비48/0(합170/0)·UI26/0·지연14/0·추가동일공간늦은응답18/0. root 실제PHP/격리DB 공통196/0·syntax·securitylint금지0·asset·wiki69·diff통과. 대비최소10.61:1·44px, 상시버튼14→2, 모바일첫카드576.6→294.8px/PC364.6→254.8px, overflow0. 새 HTML출력/SQL/API변경0. token/password/OTP닫기삭제·내부modal접근성이름·menu→form/Escape초점복귀 확인. 실물Android/Safari 미검증; Chromium360/390/1280 viewport와 구분. 증거 outputs/space-actions-checks,space-actions-review,space-actions-cross.

## 서버 적용
암호화백업/전체복호화·원본SHA·고정커밋·모의파일적용·드리프트/main FF·release/PHP reload·실제HTTPS 및 원본보존 후 기록. DB복원/재시드/삭제0. 실패시 코드복귀만 별도 판단.

## 배포 결과
실제 검증 후 기록.
