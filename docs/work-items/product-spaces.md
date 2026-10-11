# 회사·모임 공간과 공동 대시보드

- 상태: 배포 완료
- 작업 브랜치: cloud/group-dashboard
- 기준 main: e1cece55b10dd6b241ca7d41201e6ca8438c9962
- 담당 환경: 서버의 격리 worktree. 구현 백엔드/프런트 에이전트, 별도 보안 검토 에이전트, root 운영 반영.

## 목적과 범위
모아를 회사와 모임의 자료·활동·기록을 연결하는 서비스로 구성한다. 내 홈의 계정별 동기화와 위젯 편집, 회사/모임 다중 가입, 초대·멤버 역할·보관/복구·소유권 이전, 공동 홈 편집, 공지/할 일/일정/일반 텍스트 이야기, 권한 있는 원본 파일 연결·다운로드를 제공한다. 참여 공간 최근 활동은 내 홈에서 모아 본다.
기존 팀·공유 문서·파일·사진 이야기는 그대로 보존하고 새 공간에 자동 공개하지 않는다. 실제 Google Drive 연동은 이번 기능이 아니다. 기존 문서/사진 이야기의 자동 이전이나 공간 AI 요청은 제공하지 않는다. 새 이야기 본문은 일반 텍스트이며 기존 문서 블록 모델은 변경하지 않는다.
주요 파일: db/{schema,grants}.sql, db/migrations/2026-10-11_product_spaces.sql, src/site/{ProductSpaceAuthz,ProductSpaces}.php, src/handlers/product_spaces.php, src/views/{launcher,space_page}.php, public/index.php, src/Audit.php, public/assets/{home-dashboard,space-page,product-home}.js, space-page.css, moa.css, start.js, tests/관련 합성 및 HTTP 검사, 제품/위키 문서.

## 검사
최종 구현 검사: `python3 tests/product_spaces_backend.py` 51/0, `python3 tests/community/memories_harness.py --suite product_spaces_http.py --port 8768 --output <outputs/product-spaces-http-final>` 72/0, 실제 페이지 라우트 검증 추가 최종75/0. 독립 검토 서비스54/0·경합3/0·실제HTTP65/0. `python3 tests/stage89_cross_regression/run.py --port 8548 --output <outputs/product-spaces-cross-final>` 196/0. 프런트 공간25/0·동기화15/0·홈57/0·기존서비스70/0·늦은 응답12/0 통과. 독립 프런트 UI25/0·동기화15/0·지연 응답3개 시나리오 실행 통과. PHP/JS 문법·보안린트0·자산버전·위키69편 검사 통과. 원래 tbox_owner 역할로 빈 DB에 변경 전 스키마 적용→실제 migration 2회→기존owner/ACL 및 신규6표 최소권한 검증 통과. 초기 테스트 DB 접속은 owner.pgpass DB 한정 및 peer 권한 문제로 중단했고 새 빈 DB에서 postgres의 SET ROLE tbox_owner로 반복검증을 완료했다. 서비스 단위 검사만으로 인증/CSRF/Audit 검증을 주장하지 않는다. 독립 검토는 정확한 시드 권한 매트릭스의 새 합성 DB를 사용하며 운영 사용자 자료를 복제하지 않는다. 기존 파일 권한 취소·다른 공간 UUID·마지막 소유자·초대 횟수·동시 revision·탈퇴 후 접근을 포함한다. 실제 HTTP 및 운영 QA는 별도 증거다.

## 서버 적용
- DB: 6개 public.product_* 표와 필요한 인덱스만 추가. migration transaction, tbox_owner 소유, PUBLIC 권한0, tbox_app SELECT/INSERT/UPDATE, tbox_backup SELECT. 기존 owner/ACL 변경0. 신규·반복 적용을 새 합성 DB에서 검증.
- 패키지/외부 연결/nginx/cron/PC 앱 변경 없음. PHP-FPM reload.
- 암호화 DB/코드/설정/storage 백업·복호화 확인 → 고정 후보 코드 모의 적용 → 원격/운영 드리프트 확인 → additive migration → main FF·발행·PHP reload → 실제 HTTPS 및 원본 SHA/행/owner/ACL 비교 → 배포 기록/push.
- 실패 대응: 기존 코드로 복귀 여부를 판단하고 새 스키마/공간 기록은 보존한다. 운영 DB 삭제/복원/재시드 자동 실행 금지.
- 실물 휴대폰·사용자 자기 공격은 별도 미검증.

## 배포 결과
v2.54.1 `31848a82c4b5c79341e388b3aa09bd05f35ba7cc` / 2026-10-11T13:19:38+09:00. v2.54.0 스키마/API 반영 후 실제 `/spaces` 페이지 라우트 누락을 발견하여 인증된 페이지 라우트/고정 핸들러를 추가했다. 최종 실제HTTP75/0에 페이지200·익명로그인·동의이동 검사를 포함했고 별도 정책 검토/PHP·린트도 통과했다.
암호화 DB(원래owner/ACL 포함)·코드·설정/storage 백업과 전체 복호화·목록 확인: /var/backups/tbox-v2/product-spaces-20261011. 첫 복호화 목록 검사는 pg_restore의 조기종료로 파이프가 끊겼고 남은 입력 소비를 추가해 전체 검증했다. 빈 DB 원래 tbox_owner 역할로 실제 migration2회/기존ownerACL/신규6표 권한 검증. 후보43파일 모의 적용·삭제0 및 페이지 수정4파일 별도 모의 적용, 운영/원격 드리프트 확인, main FF·migration1·PHP-FPM reload 완료. nginx/cron/패키지/PC 앱 변경0.
운영 실제HTTPS56/0: 예약 claude_ 검토 계정의 정상비밀번호/OTP 세션, 다중멤버십/초대/공지권한/기록/날짜부분수정/공동revision409/비회원404/탈퇴후404, 390·1280px 화면/공지 실제렌더/넘침0, 브라우저 공동홈 저장, 새 브라우저 개인홈 동기화·활동집계. JS오류0·5xx0. 초기 운영 검사는 동의가 남은 검토 계정의 기존403을 공간404로 예상해 실패했고 QA 계정만 정상 동의 API를 통해 완료했다. 운영/실제 사용자 동의는 바꾸지 않았다. 원래 공간 모델에 없던 새 QA 공간만 작성하고 최종 보관했다. 검토 계정의 개인 홈은 기본값으로 복구했다.
기존 파일1042행·14개 기존 표의 모든 행/ownerACL·저장 원본975개 SHA 보존. storage 총976개 중 기존 cron이 갱신하는 community-automations.cursor.json 1개는 업로드 원본이 아닌 운영 상태로 명시 제외했다. 작업 전후 nginx/PHP/cron/비밀 설정 SHA 동일. 데이터 삭제/복원/재시드0. 증거 outputs/product-spaces-preservation.json, product-spaces-live/results.json, product-spaces-code-rehearsal/results.json, product-spaces-migration-rehearsal/results.json, product-spaces-page-fix/product-spaces-checks.json.
실물 휴대폰·사용자 자기 공격·Google Drive 실제 연동·공간 AI/기존사진자동이전은 미검증/별도 기능이며 완료라고 주장하지 않는다.
