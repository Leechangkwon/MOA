# PC 비서 받기와 GPT 선택 안내

- 상태: 서버 검증 완료·배포 대기
- 작업 브랜치: cloud/buddy-download-flow
- 기준 main 커밋: 0995cddaaaed60ed86854973553943ec5ff6e803
- 담당 환경: 서버의 격리 worktree, Codex 자체 구현·검토

## 목적과 범위

사용자 「진행해」: 검증된 AI 비서 다운로드·설치 안내와 모아 GPT 선택 흐름을 완성한다. 기존 메신저 문장 도구(다듬기·맞춤법·번역)의 Gemini 기본 선택과 GPT 명시 선택을 유지한다. 일반 AI 대화와 사진 글쓰기는 기존 Gemini 기능이다. PC 앱에 공용 Gemini 키를 배포하거나 유료 OpenAI API로 전환하지 않는다.

웹의 오래된 f7caeb4 링크/로그인 보류 문구를 갱신한다. PC 실행 코드는 Windows 실측 b86a602와 같고 안내만 갱신한 927f9198785c3fef1abaa90930713f4953d2dc9a ZIP/README를 고정한다. ZIP과 소스의 파일별 일치를 확인한다. 비공개 Git2 읽기 권한이 필요하며 공개 저장소로 바꾸지 않는다.

안내 순서: 새 폴더 압축 해제 → 최초 setup.cmd → 이후 run.cmd → 공식 ChatGPT 로그인과 짧은 연결 시험 → 같은 Windows PC의 모아 전체 메신저/동료 대화/AI 도구에서 GPT 선택 → 일회용 코드와 PC 승인 → 문장 처리/미리보기/작성란 적용/별도 전송. 휴대폰은 다른 PC의 loopback에 연결되지 않으므로 기존 Gemini/ChatGPT 웹 수동 복사를 안내한다.

수정 파일: src/views/member_local_buddy.php, src/views/member_assist.php, public/assets/member-assist.js, tests/moa_local_assist.py, 관련 DESIGN/STAGES/SECURITY/위키/인계 문서. PC 앱은 별도 AI-비서(로컬) 브랜치 README/DISTRIBUTION 문서만 변경한다. 권한·라우트·외부 요청·DB·서비스 설정 변경 없음.

## 검사

Windows 실측 b86a602: 2026-10-09 11:19 KST, 77개 중 76 통과/비Windows 전용1 제외, 실제 모델7·응답완료·DPAPI 로그인 복원. 사용자와 로컬 Codex 보고이며 이 서버의 실제 추론 검증은 아니다. 실제 Windows 웹→PC→웹 전체 연결은 미검증이다.

- `python3 tests/moa_local_assist.py`: 합성 브라우저 73/0. Gemini 기본/GPT 명시 선택, 초안 유지, 고정 ZIP, 360/412/820/1280px·명암, 페어링·만료·취소·미리보기·별도 전송, 로그인/모델/한도/프로토콜 실패 시 자동 우회·재시도 없음.
- `QF11B_ONLY=t17,c6 python3 tests/community/memories_harness.py --suite messenger_window_browser.py`: 격리 DB/브라우저 31/0. 떠 있는 창은 전체 화면 링크만 제공하고 PC 연결 요청 없음.
- `python3 tests/community/memories_harness.py --suite messenger_store_browser.py`: 순차 재실행 75/0. 최초 실행은 같은 8768/8769 포트를 쓰는 두 하네스를 동시에 시작해 다른 시험의 로그인 화면을 읽고 실패했다. 최초 로그를 보존하고 서버/합성 DB 정리 확인 후 단독 재실행했다. 운영 DB 사용·제품 코드 수정 없음.
- `python3 tests/stage89_cross_regression/run.py --output …/cross`: 공통 격리 HTTP/브라우저 164/0.
- PHP/JS 구문, `security_lint.sh`, `community_asset_version.php --check`, `wiki_check.php`, `git diff --check`: 통과.
- Git2 실제 인증 다운로드 HTTP200, ZIP 129720바이트·29파일. 모든 Git 파일 내용 일치(배치 3파일만 .gitattributes의 Windows CRLF 변환). SHA256 `43dfd390d78b71d57395752690f9fd4e8f1f4c69cbf866699dee66e07119884a`. 실행 코드는 Windows 검증 b86a602와 차이 없음.
- 증거: `/home/changkwon/qaredi-buddy-flow-results-20261009/`. 새 실제 공급자 요청은 실행하지 않았다. 실Windows 모아 브라우저↔PC 전체 연결과 실물 휴대폰은 미검증.

## 서버 적용

- DB/패키지/설정/cron: 변경 없음.
- 원본 /var/www/qaredi 커밋과 기존 PC 폴더/백업 보존.
- 후보 검증 → 백업/모의 파일 적용 → 서버/원격 드리프트 확인 → 웹 코드 fast-forward → PHP-FPM reload → HTTPS 확인. 기존 데이터 삭제/복원/재시드 없음.
- 실패 시 변경한 웹 코드만 이전 커밋으로 복귀할 수 있으며 DB 복원은 수행하지 않는다.

## 배포 결과

검증 후 기록한다.
