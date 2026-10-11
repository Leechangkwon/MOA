# 공간 홈 버튼 정리
- 상태: 운영 v2.54.3 반영/HTTPS·원본 보존 완료
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
후보a5931cd→main FF·v2.54.3 `0e49267d3df03e30b00ce8deab7bbe5d8e9625e1` / 2026-10-11T13:58:16+09:00. 고정11파일모의정확바이트/삭제0·DBmigration0. PHP-FPM reload 후 실제HTTPS95/0(밝음/어두움390/1280·기존archivedQA공간읽기/메뉴/회원/새공간·참여취소만). live메뉴와서브창Escape초점복귀·복구기능보존·보관된쓰기숨김·overflow/JS/5xx0·정확CSS/JS바이트확인. live제품쓰기0. 백업3종 SHA/전체복호화/원본975 SHA 검증,14기존표+6제품표 원래행/파일1042행/975원본SHA·ownerACL 보존 및85설정 SHA 동일. 패키지/nginx/cron/PC변경0·DB복원/재시드0. 실물/Safari미검증 유지. Git2동기화는후속기록.

Git2: main·cloud/space-actions-cleanup·v2.54.3 atomic push/fetch 완료, 두브랜치5964fba79ca9 일치확인. 이동기화후속은같은두브랜치로전달, 태그는 검증런타임0e49267 유지.
