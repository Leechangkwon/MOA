# 개인 홈 구성 구현 지시서

- 목표: 모아 회사·모임 방향의 첫 단계로 기존 런처를 개인별로 구성한다.
- 기준: origin/main e1cece55b10d. Q:F-3, Q:F-12 / ARCHITECTURE 권한 및 읽기 API 절 / DESIGN.md 개인 홈 구성 절.
- 수정 파일: src/views/launcher.php, public/assets/start.js, public/assets/home-dashboard.js, public/assets/moa.css, DESIGN.md, docs/design-guide.html, tests/home_dashboard.py, tests/fixtures/service_home_render.php, docs/work-items/group-dashboard.md.
- 금지: 새 권한·역할 판단, 서버 DB 변경, SQL, HTML 저장/innerHTML, 외부 코드·요청, 운영 데이터 사용. 기존 동의·계정 관문·Claude 요청 제한·메신저·테마 보존.
- 완료: 계정별 브라우저 설정 저장/취소/복구, 위젯 표시/순서/폭/최근 소스, 새로고침 유지, 오류 저장 안내, 합성 브라우저 검사. 공유 공간·새 권한 모델은 후속 설계.
