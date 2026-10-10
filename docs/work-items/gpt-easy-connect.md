# GPT 간편 연결

- 상태: 운영 웹 반영·업데이트 게시 완료 / Git2 push 대기 / 실제 Windows 확인 대기
- 작업 브랜치: cloud/gpt-easy-connect (웹), cloud/buddy-easy-connect (PC)
- 기준 main: 5187bb3
- 담당: 서버 Codex, 동일 세션 구현/검토 (독립 검토 아님)

## 목적과 범위
사용자 승인 2026-10-10: 코드 복사 없이 처음 PC 승인 후 웹 GPT 선택으로 재연결. 기존 트레이 비서에 통합한다.
허용 파일: 웹 public/assets/{moa-local-bridge,member-assist,app}.js, src/views/member_{assist,local_buddy}.php, tests/moa_local_assist.py 및 새 연결 시험, DESIGN/STAGES/SECURITY/API, wiki/qaredi/guide-site.json/index.json, docs/work-items와 CLOUD-HANDOFF, VERSION/CHANGELOG, deploy/production-state.json(운영 검증 후만). PC는 별도 브랜치의 기존 update.2 소스·테스트·설치 도구·문서.
기존 계약의 일회용 연결만 허용하던 부분은 이번 사용자 요청으로 변경한다. 첫 PC 승인은 모아 출처·브라우저 기억·웹 요청 직접 처리에 대한 명시적 동의다. 기억은 최대 30일, 브라우저/Moa 계정 별도. PC에는 연결 토큰 해시만 DPAPI 저장, 웹에는 OpenAI 자격이 아닌 제한된 연결 토큰만 저장한다. 세션 토큰은 메모리에만 30분. 계정 전환/로그아웃/연결 해제는 PC 신뢰 철회. 앱 종료는 실행 세션만 취소. 초기 연결은 정확한 Host/Origin/JSON/CORS 제한과 PC 승인 필수. 웹 클릭 전 네트워크 탐색·생성 없음. 이미지/자동 메시지 전송/유료 API 전환 없음.

## 검사
기존 PC 단위·실제 loopback HTTP, 웹 Playwright 합성 및 PC 연결 왕복, 공통 격리 회귀, 정적 검사. Windows DPAPI/프로토콜 등록/실제 ChatGPT 응답은 Linux 시험과 구분한다.

## 서버 적용
DB/서비스 설정 변경 없음. 격리 검증 → 암호화 백업/복호화 → 파일 모의 적용 → 드리프트 → fast-forward → HTTPS/데이터 보존 확인. 기존 ZIP/DB/파일 삭제·복원 없음. 새 PC ZIP은 해시 검증/운영 검사와 정상 관리자 게시 경로 사용.

## 구현/검증 기록
- 웹 합성 Playwright 79/0, Chromium→실제 HTTP bridge 왕복 7/0(PC 승인/응답은 합성), 공통 격리 PHP/DB/브라우저 164/0.
- 문장 처리 전 사용자 클릭 필수, 재연결은 본문/대화 이력을 보내지 않는다. 로컬 브라우저 네트워크 허용은 시험에서 grant 했으며 실제 Windows 승인창과 구분한다.
- PC 원본은 기존 게시 update.2 SHA256 65bff9f6fd5e3c1579b10eef962aad2fcefcc740f9e0e3a687245ba15ad2f009. 별도 PC 브랜치에 기존 트레이 소스를 보존하여 통합했다.
- 웹 세션 토큰은 메모리 30분, 재연결용 bearer grant는 user ID별 localStorage 30일. PC에는 연결 grant SHA256+account+user+만료만 DPAPI vault 저장. PC 계정 변경/로그아웃은 trust 삭제, 웹 로그아웃은 모든 로컬 grant 삭제, 다른 탭 storage 이벤트도 세션 취소.
- `tbox-buddy://connect`는 HKCU에 현재 설치의 pythonw/app.py --web-connect 고정 명령만 등록한다. URL 입력을 명령으로 전달하지 않는다. 기존 단일 인스턴스에 전달하며 이미 승인한 앱은 창을 숨겨도 트레이 대기한다.
- 원격 Git2 HTTPS는 2026-10-10 연결 시간 초과. 로컬 HEAD/트리/배포 해시를 확인하며 Git push 완료로 표시하지 않는다.

- PC 검증 최종: 113개 중 110 통과·Windows 전용 3 skip + URI/단일 인스턴스 2/2. 초기 서버 PyJWT2.3과 43821 고정 포트 경합을 확인하여 테스트 전용 PyJWT2.10.1 및 격리 포트 사용. 설치 의존성 변경0.
- PC 소스 커밋: 07af253 (cloud/buddy-easy-connect). update.3 ZIP 266615 bytes, SHA256 2e38f86b552b9ee8cbdfe38df685efae1fa79e0f81e08fb753a4f2dc9974be44. 84 manifest 파일 CRC/크기/해시 일치. 실제 Windows 미검증을 USAGE/검증 문서에 명시.

## 운영 결과 — 2026-10-10
- v2.53.5 `13cea0c5032f8f586ec339d977f22967e1add46b`, 기능 `fd3b389`, PC 소스 `07af253` + README 안내 `a3798a3`.
- 암호화 DB/코드/설정/스토리지 백업 `/var/backups/tbox-v2/gpt-easy-connect-20261010` 복호화 확인. 파일 모의 적용17개·삭제0·DB/시스템 설정 변경0. 로컬 운영 HEAD·파일 해시 드리프트0, fast-forward 후 PHP-FPM reload.
- Git2 HTTPS 반복 시간 초과. 원격 최신 HEAD는 실측하지 못했다. 로컬 마지막 fetch 기준 5187bb3 및 운영 HEAD/트리 일치를 확인해 사용자 승인한 파일 반영만 수행했다. 원격 ref 변경/강제 push 없음. 복구 후 원격 fetch→차이 검토→main/tag/PC 브랜치 push 필요.
- 실제 HTTPS22/0 (390/1280px, 신규 버튼·코드 접기·기기 범위·자산 SHA·오류0·공급자 요청0). 운영 QA 동료가 검색에 나타나지 않아 최초 대화 선택 검사는 타임아웃; 최종 운영 검사는 인증된 렌더링 대화창을 직접 열어 UI를 검사했다. 실제 동료 대화→도구 전체 흐름은 합성 브라우저79/0 증거로 분리한다.
- 게시: 정상 관리자 비밀번호/일회용 OTP → ZIP 업로드201 → 운영 ClamAV clean → 게시201 → 일반 구성원 다운로드 SHA 일치,23/0. 현재 update.3, 이전 update.2 파일 삭제0(현재 게시 표시만 정상 변경). 파일 UUID c7bd84a0-ebf5-4fff-976f-cee3bfe0a731.
- 원본 파일 레코드1040(이전 program_current 표시 제외 필드 불변), 작업 시작 기준 스토리지995 SHA 보존, 게시 후996. 커뮤니티5테이블/owner/ACL/시스템파일 유지.
- 84 manifest 파일을 합성 기존 설치 폴더에 실제 updater로 적용하여 해시 일치, config/queue/activity3개 보존과 백업 생성을 확인했다.
- 최종 증거: `/home/changkwon/qaredi-gpt-easy-results-20261010/` (browser-final,roundtrip,pc/tests-final.log,cross,deploy,publication,post,package).
- 남은 검증: 실제 Windows URL 프로토콜 등록·DPAPI·트레이 재시작·ChatGPT 실응답. Linux 시험을 Windows 성공으로 표시하지 않음. 휴대폰→다른 PC 연결은 별도 작업.
