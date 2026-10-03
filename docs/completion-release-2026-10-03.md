# 2026-10-03 통합 발행 검증

사용자 요청: 이전 「깃 연결 상태를 점검해」 세션의 미배포·예정 작업 전부 진행 및 `insta` f78b0186539a2c8c1fb4c6fcd47d01d6b0d6fdeb / PR #2 운영 검증·배포. 서버 OpenAI 키가 없으므로 GPT 비활성 유지.

## 범위

- 운영에 먼저 반영된 모아 홈·초대 가입·OTP 안내·회원 메신저·Gemini 문장 도구와 수동 PC 전달을 보존하고 Git 기록에 포함.
- 커뮤니티 12단계 모음·그룹 공유·동기화 블록·웹 공개·공개 폼·외부 연동, 13단계 문서 AI 화면·설정·동의·권한 판정 통합.
- 비서 실행 API의 독립 assistant.run 범위, 명시적 파일 검색/근거 조회와 취소·승인·멱등 처리. 운영 ASSISTANT_ENABLED=1, TEST_ADAPTER=0, 매분 tbox-php 워커. 자연어 AI 추론은 연결되지 않았으며 503을 반환한다.
- 기존 커뮤니티 공통 셸에 스레드·사진/영상·친구 공유 링크·회원/GPT 메신저 모달 통합. 기존 /workspace 및 ?space 링크 보존.
- PC 비서 연결은 웹 페어링 화면·별도 자격·탭 메모리·미리보기/명시 적용. PC 소스는 별도 AI-비서(로컬) 브랜치이며 배포 자격 unconfirmed 보존.

## 실행 증거

격리 작업본 `/home/changkwon/qaredi-completion-20261003/tbox-v2`, 증거는 같은 부모의 `results/`. 운영 업무 데이터 없이 임시 DB와 합성 계정으로 검증했다.

|검사|결과|증거|
|---|---|---|
|스레드·메신저 실제 PHP/DB/HTTP|127/127|memories-http-final/results.json|
|실제 PHP 화면 PC·390px 브라우저|13/13|memories-runtime-browser-03/browser-results.json|
|기존 스레드·모달 합성 브라우저|12+9 통과|memories-check-04.log|
|로그인·TOTP·역할·파일·토큰·도움말 교차 회귀|149/149|cross-final-03/results.json|
|웹 PC 연결 및 수동 전달 브라우저|62/62|moa-assist-final.log|
|PC loopback 연결 실제 소켓/상태 검사|13/13|buddy-bridge-final.log|
|12단계 확대 HTTP·브라우저|145/145·32/32|stage12-extended-02/acceptance.json, stage12-browser/browser.json|
|13단계 HTTP·브라우저|128/128·20/20|stage13-http-08/stage13-results.json, stage13-browser-04/browser-results.json|
|nginx 제한·캐시·공유 토큰 로그 보호|10/10|nginx-final/nginx.json|
|운영 구조만 복제한 DB 업데이트 8개를 두 번 적용|16/16|upgrade/results.json|
|비서 실행 API (앞 세션)|단위52·HTTP376 통과|/tmp/qaredi-assistant-results-4H7Ymg/|
|PHP/JS 구문|375개 통과|이번 세션 명령 출력|
|보안 린트·부품 버전·위키|0건|security-final.log, wiki-final.log|

원본 common8 스크립트 전체 통과로 해석하지 않는다. 교차 회귀의 정확한 범위는 tests/stage89_cross_regression/README.md 참조. 외부 공급자는 합성 응답/격리 HTTPS로 검사했으며 실제 과금 요청은 하지 않았다.

## 통합 과정에서 수정한 사항

스레드 첫 화면의 도움말 버튼 누락을 복구했다. 환경에 고정된 Chromium 경로·이미지 지연 로딩·숨겨진 모바일 버튼을 고르는 시험 오류를 수정했다. 오래된 익명 런처 시험은 사용자가 승인한 모아 홈의 공개 읽을거리와 비공개 회원 피드 계약으로 갱신했다. 스레드 미디어 정합성을 검사하면서 private-ai 설정 파일을 업로드로 오인하지 않도록 했고, 실제 미디어 변조는 계속 실패함을 실행 확인했다. 잘못된 공유 주소·인코딩 경로·User-Agent에도 토큰이 nginx 로그에 남지 않도록 보완했다.

## 배포와 한계

운영 기준은 production-baseline.json의 파일별 SHA-256이며 기존 변경 누락 0건을 확인했다. 암호화 코드·설정·DB·storage 백업은 /var/backups/tbox-v2/completion-20261003에 있고 체크섬과 전체 복호화를 검사했다. deployment-plan.json은 커밋·파일 해시·마이그레이션 목록을 고정한다. 배포 실행 결과·실제 커밋은 results/deployment.json과 PR #2에 후속 기록한다. 기존 계정·문서·파일을 재시드하거나 삭제/복원하지 않는다.

미검증/미연결: 서버 GPT 키 없음, 문서 AI 공급자 키/동의 설정 없음, 외부 메신저·데이터 연결 대상 없음. 실제 Windows 설치·OAuth·구독 추론·휴대폰 카메라·영상 코덱·운영 앞단 프록시 로그는 이 서버에서 확인하지 않았다. Linux에서 Windows 전용 전체 시험을 시도했으나 msvcrt/tkinter 부재로 실행 불가였으며, 독립 연결 시험 13개만 통과로 기록한다. 사용자 자기 공격 결과는 미보고다. 기존 Windows ZIP은 이 서버에 없고 알려준 PC 경로를 다운로드 URL로 가장하지 않는다.
