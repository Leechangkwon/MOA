# Gemini 3.6 Flash 직접 호출 전환

- 사용자 요청: 실제 3.5 Flash 사용 위치 조사, 사용 중인 호출만 3.6 직접 호출로 전환, 호환성/비용/실제 응답 검증.
- 기준: 서버 `/var/www/qaredi/tbox-v2` main `fa9e3ab9d0374cd44ec119eb81dc546fffe1350d`; 로컬 격리 clone `C:\vibe\worktrees\qaredi-gemini36-20261014`, `codex/gemini-36-flash`.
- 발견: Ai::MODEL 및 health/generate/transcribe 고정 경로, MemberAi::MODEL, Fetch::geminiRequest 텍스트 경로가 3.5를 명시한다. PC 비서는 서버 응답의 모델을 사용하므로 PC 모델 하드코딩은 없다.
- 수정 허용 파일: `src/Ai.php`, `src/Fetch.php`, `src/MemberAi.php`, `tests/community/stage13_https.py`, `tests/assistant/chat.py`.
- 모델 문자열을 `gemini-3.6-flash`로 바꾼다. Ai.php의 `candidateCount=1`은 Gemini 3 이후 지원하지 않는 매개변수라는 공식 이행 안내에 따라 제거한다. 나머지 요청/응답/권한/대화/한도 계약은 유지한다. 이미지/임베딩 모델은 이번 요청 범위 밖이다.
- 검증: 격리 namespace의 원본 Ai.php HTTPS transport 시험(정확 모델 URL, health, generate, transcribe, embedding, 인증서, 리다이렉트, DNS 방어), PC assistant HTTP 합성 DB 시험, security lint, 위키/자산 버전 검사. 기존 regression 목록은 서버 지침의 기존 밤 회귀 대기열에 등록한다.
- 실제 검증: 서버 보관 키를 서버 프로세스 안에서만 읽어 합성 텍스트로 이전 이름/새 이름을 요청. 키/실사용자 내용은 출력·로컬복사하지 않는다. 이전 이름도 이미 3.6으로 전달되므로 독립적인 원래 3.5 품질 비교는 불가능하다.
- 메인은 문서/검토/서버 반영과 ERP2의 별도 미커밋 파일 보존을 담당한다. 구현 담당은 허용 파일만 수정하며 다른 변경을 되돌리지 않는다.
- 운영 반영: 격리 후보·정확 diff·원본 백업·적용 모의 검사·fresh HEAD와 파일 hash 확인 후 필요한 파일만 적용. DB/키/패키지 변경 없음. 운영 checkout의 다른 기능 111개 차이를 본 작업 배포로 취급하지 않는다.

공식 근거: https://ai.google.dev/gemini-api/docs/deprecations ; https://ai.google.dev/gemini-api/docs/generate-content/whats-new-gemini-3.6 ; https://ai.google.dev/gemini-api/docs/pricing
