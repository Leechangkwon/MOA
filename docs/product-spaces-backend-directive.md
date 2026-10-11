# 모아 다중 공간 백엔드 구현 지시서

목표: 회사·모임 멤버십, 공간/개인 홈 동기화, 실제 공간 기록과 자료 연결 API를 기존 데이터 공개 없이 구현한다.
기준: docs/space-dashboard-plan.md, ARCHITECTURE.md 3/4/13장, PRD 권한 매트릭스, SECURITY.md, 기존 src/Authz.php 읽기·전역 쓰기 자격.
수정 허용: src/site/ProductSpaceAuthz.php, src/site/ProductSpaces.php, src/handlers/product_spaces.php, src/Audit.php(새 요약 등록부만), public/index.php(API 라우트), db/migrations/2026-10-11_product_spaces.sql, db/grants.sql(신규 표만), db/schema.sql(동일 신규 표), tests/community/memories_http.py(동적 시험 포트), tests/community/memories_harness.py(격리 신규 suite/migration), tests/product_spaces_backend.php, tests/product_spaces_backend.py, docs/product-spaces-api.md, docs/work-items/product-spaces-backend.md.
금지: 운영 데이터/키/기존 공유 범위 변경, 전역 Admin 우회, HTML 저장/임의 URL 실행, 문자열 SQL 값 결합, 외부 요청·패키지 추가, 프런트 자산 편집.
검사: 독립 새 합성 DB에서 권한/멤버/초대/보관/마지막 소유자/동시 revision/파일 원본 권한/잘못된 자료/다중 공간 격리·반복 migration·ACL 검사. 변경 PHP 문법/전체 보안 린트.
보고: API 계약/변경 파일/검사 원문·미검증. 운영 반영은 root 수행.
