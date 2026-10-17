# 티박스 AI 검토 오류·안전한 재개·진단·MCP 인계

- 환경: 서버. 사용자2026-10-17 인계 원문을 실제 모아 MCP로 MOA-023 작업글 끝에 저장하고 판2→3/원래22블록 보존/4블록 추가를 재조회했다.
- 웹 기준: main75815d4, 런타임 v2.72.0/2edc504 유지.
- PC 기준: 2026.10.17.1/1a703d8. 별도 브랜치 codex/closing-review-recovery.
- 기존 사용자의 집 PC 임시 수정은 인계된 사실이며 집 PC 파일이나 실제 운영 원본을 읽은 것으로 주장하지 않는다.

## 승인 범위와 순서

1. 누락 수치 검산·보고서·업데이트 보존을 합성/오프라인 통합으로 검증하고 새 PC 버전 게시.
2. 단계별 일반 진단·원인 안내·완료 응답의 보호된 로컬 체크포인트·검산부터 재개·반복 호출 방지.
3. 명시적 선택 중앙 진단, 관리자 집계·삭제·전송 중단, 현재/최신 버전과 사용자 선택 업데이트.
4. 기존 운영 체계를 활용한 설정 가능한 일별/주간 개선 후보·릴리스 검증 흐름. 자동 코드 수정·자동 업무 승인·유료 API 전환 없음.

MCP는 현재 호스트의 실제 도구 이름/연결 방식/계정/권한/조회/쓰기/재조회를 구분한다. 다른 호스트의 로그나 OAuth 자격은 추출하지 않는다.

## 1차 구현과 검사

PC weekly-macro/closing_ai_review.py의 _group_checks는 qty/supply/tax/tot별로 누락을 기록하고 제공된 값은 모두 기존 엄격 _number로 검사한다. 누락 필드 차액/equal_amount는 null, 실제0·차액·단독 후보 부호는 유지한다. numeric_complete/identity_verified/후보 상태를 분리한다. 프롬프트·VERSION과 입력 지문은 불변이다.

annotate와 monthly_workflow 보고서에서 미확인·필드 보완 안내, Excel AI후보검산을 제공한다. AI 설명 완료와 원본 근거·사용자 최종 확인을 분리한다. 후속 경계 closing_exceptions.candidates도 확인했다. 수치가 부족한 후보는 질문/검토 목록에 남기되 금액 예외 승인을 만들지 않아서 추가 Decimal(None) 실패와 잘못된 승인 진입을 막는다. 원본/거래 연결/답변 보존을 반영 전에 검사한다.

tools/update_closing_existing.py는 원래 백업/실패 복구/개인 파일 보존에 파일별 원자 교체와8개 실행 포트 거부를 더했다. 계정·환경·자료·기존 캐시·사용자 확인을 지우지 않는다. tools/package_release.py는 기존 검토된 경로만 포함한 결정적 ZIP·매니페스트·code_id·새 버전 변경 기록을 만든다. 같은 출력 버전에 다른 바이트가 생기면 거부한다.

- 합성8범주8/0. 원래 Decimal(None) 오류 재현과 새 동작, 잘못된 숫자·참조·거래처 범위·중복·이미 연결된 행 거부 확인.
- 실제 로컬 엔진/보고서/Excel의9묶음 오프라인 통합1/0: 기존1/9 캐시 원문 재사용·2/9~9/9 합성 AI8회·JSON/Excel/요약/HTML 저장과 재열람. 원본 rows/totals/details·답변·미해결324항목·원본/초안/기존 캐시/사용자 이력 바이트 보존.
- 업데이트 원자 교체/중간 실패 복구/예비 포트 거부3/0.
- 최종 ZIP 검사12/0: 전체 SHA/크기·새 설치본 비밀/업무/로그0·원래 경로 전부 보존·변경10경로 한정·모든 Python 파싱·버전/code_id·기존 설치의 설정/원본/초안/캐시/확인 이력 보존·코드 백업/실제 설치 내용. PC 커밋 본문의 ‘13검사’는 집계 오기이며 실제 원문12개를 기준으로 한다.
- 증거: /home/changkwon/qaredi-closing-review-results-20261017/phase1-tests.log, phase1-updater.log, package-phase1/verified.json. 실제 집 PC AI9/9·Windows 신규 설치/업데이트는 미검증이다.

## 1차 배포

PC2026.10.17.2, 소스1b9cde2/Git2 push. TBox-Closing-2026.10.17.2-update.zip/998827bytes/SHA256 ae47faf7079d7c65ee6a645fdd710dcc0cc7a2ed32a333ca8daa24bccad0e58b. 정상 관리자 재인증·OTP·실행파일 업로드→운영 백신→사내 게시→구성원 실제 다운로드25/0. 사내 배포함 https://changkwon.mso.so/tbox/programs. 기존2026.10.17.1은 보존. 실제 결과는 publication/phase1/result.json.

백업 /var/backups/tbox-v2/closing-review-20261017의 암호화 DB·코드/설정 전체 복호화/파싱, 기존 파일 행·저장 원본·설정·ownerACL 보존 확인. 웹 실행 코드/DB migration/설정/패키지/서비스 재시작0. 구성원 다운로드 확인은 실제 Windows 업무 소수 사용자 확인과 구분한다.

## MCP 초기 진단

이 대화는 config.toml의 qaredi 로컬 stdio/5개 enabled_tools로 실행된다. 실제 native whoami→프로젝트 모아 단일 일치→직계 MOA-023→원본 판/can_edit→끝 추가→재조회 모두 성공했다. 설치 캐시에는 portable mcp.json(streamable-http), 호환 .mcp.json, .codex-plugin/plugin.json과 스킬이 있다. URL·OAuth 선언은 같은 서비스로 일치한다. 이 사실은 다른 ChatGPT 호스트에서 moa 이름이 등록됐거나 OAuth 승인이 끝났다는 증거가 아니다.

공식 https://learn.chatgpt.com/docs/extend/mcp 는 서버 키/호스트 구성과 hosted plugin 연결을 구분하며, https://learn.chatgpt.com/docs/enterprise/plugin-management 는 MCP 설정을 담아 가져온 플러그인의 Desktop only 제한과 기존 app 참조 방식을 설명한다. 따라서 remote HTTPS만으로 모바일 호환이라고 단정하지 않는다. private 플러그인 참조의 public dependency 조회는 plugin_not_found였으며 설치 여부 실패로 해석하지 않는다. 실제 사용자 모바일/OAuth/도구 노출은 미검증이다.

## 다음 구현 상태

2~4차는 아직 구현·시험·배포 완료가 아니다. 일반 진단에는 코드/버전·무작위 식별자·단계·오류코드·건수·경과만 허용한다. 인증/원문/응답/사용자 경로는 전송하지 않으며 로컬 기본·사용자 선택 전송·범위/기간/용량/삭제/중단을 명시한다. 완료 응답은 보호된 업무 체크포인트로 일반 진단과 분리하고 현재 입력/모델/프롬프트/무결성/규칙으로 재검증한다. 이후 변경과 확인 결과를 여기에 이어 기록한다.
