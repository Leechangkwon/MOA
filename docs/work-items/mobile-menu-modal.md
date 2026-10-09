# 모바일 메뉴를 본문 위의 모달로 변경

- 상태: 운영 배포 완료
- 기준: main 222bf35, v2.53.3
- 브랜치: cloud/mobile-menu-modal
- 사용자 요구: 분리한 메뉴가 본문을 밀지 않도록 모달로 표시.

960px 미만에서 기존 사이드바 DOM 한 벌을 native dialog에 넣어 showModal로 연다. 본문 위치/스크롤을 유지하고 배경 클릭·포커스·스크롤을 차단한다. 메뉴 안의 메뉴/앱·계정 전환, 닫기, 바깥 클릭, Escape를 제공한다. 항목을 실행하면 메뉴를 먼저 닫아 검색/메신저/새 문서 창이 정상적으로 열린다. 넓은 폭으로 전환하면 원래 사이드바 자리로 되돌린다. 권한·목록·문서·API·DB 변경0.

허용 파일: src/views/shell_top.php, public/assets/app.js·app.css, tests/community/qf12_shell_browser.py, tests/stage89_cross_regression/suite.py(기존 타임스탬프 가변 소수 자리 Python3.10 시험 오류 보정), DESIGN.md·STAGES.md·SECURITY.md, wiki/qaredi/guide-site.json·index.json, 이 문서와 발행/배포 기록.

검사: 격리 셸(본문 좌표/스크롤 불변, 배경 차단, Tab/닫기/바깥 클릭/Escape, 내부 전환·검색·메신저·새 문서, 화면 회전/PC 복원), 공통 회귀와 정적 검사. 백업복호화·모의 반영·드리프트 확인 뒤 운영 HTTPS/데이터 보존 검증. 실물 휴대폰과 브라우저 모의 검증을 구분한다. 실패 시 사용자 데이터 삭제·복원 없이 원인을 확인한다.

## 검증 결과

- 격리 mobile/c2 셸173/0. 360/412/820 × 자료실/업무/이야기, 본문 좌표·폭·스크롤 불변, native modal·배경 휠 잠금·Tab 초점·바깥 클릭·닫기·Escape·검색으로 전환, 메신저가 열린 상태, PC1280 복원, 기존1440 새 문서·이야기·여행 흐름. 다크412 포함.
- 공통164/0. 첫 실행은131개 통과 후 PostgreSQL의5자리 소수 타임스탬프를 Python3.10 fromisoformat이 처리하지 못해 시험 코드에서 중단. 소수 자리를6개로 채우는 호환 처리 뒤164/0. 1자리/5자리/소수 없음 단독3/3. 서비스 날짜 처리/DB/API 변경0.
- PHP·JS문법, 보안린트, 부품버전, 위키, diff 검사 통과. 실물 휴대폰은 미검증. 동일 Codex 세션 검사이며 독립 에이전트 검토 아님.
- 증거: /home/changkwon/qaredi-mobile-menu-modal-results-20261009/{shell,cross,cross-final}; 초기 실패도 보존.

## 운영 결과

- v2.53.4 6bf9816441880368645f7e68707502f050409215,14파일 변경·삭제0·DB/설정/패키지변경0. 백업 /var/backups/tbox-v2/mobile-menu-modal-20261009 암호화/복호화/파일 수 검증, 모의14파일·드리프트0 뒤 FF 반영·PHP-FPM reload.
- 실제 HTTPS79/0: 모바일390/820 × 자료실/업무/이야기, 본문 좌표·폭·스크롤 불변(열기/닫기), native modal, 내부 메뉴 전환, 바깥 클릭/Escape, PC1280 메뉴 복원, JS/5xx0. 운영 자산 바이트 일치.
- 저장 원본1031개 SHA256·files1040행·이야기5표·DB 소유자/ACL·nginx/cron 설정 보존. AI 비서 update.2 배포 유지.
- 증거 /home/changkwon/qaredi-mobile-menu-modal-results-20261009/{deploy,post}. 실물 휴대폰은 새로고침 후 사용자 확인이 남는다.
