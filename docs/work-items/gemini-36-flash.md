# Gemini 3.6 Flash 직접 호출 전환

- 상태: 배포 완료
- 작업 브랜치: `codex/gemini-36-flash`
- 기준 main: 최초 `fa9e3ab9d037`, 서버의 병행 문서 변경 `b66e275f5d4a`를 fast-forward로 보존.
- 담당 환경: Windows `C:\vibe`의 격리 clone, Qaredi SSH 서버, ERP2 SSH 서버.

## 목적과 조사 결과

2026-10-10 Google AI Studio 이메일을 Gmail에서 확인했고 Google 공식 모델 종료·가격·이행 문서와 대조했다. 기존 3.5 요청의 실제 `modelVersion`도 `gemini-3.6-flash`였다. 원래 3.5의 독립 응답은 이제 비교할 수 없다.

| 위치 | 발견과 조치 |
|---|---|
| 연결 GitHub 15개 저장소의 기본 브랜치 | UTF-8 텍스트 1,346개 조사. 실제 Gemini 호출 없음. `new_ERP`의 `.cursorrules`·AI 작업 이력에는 명칭만 있음. `THE-O`, `New_ERP2`에도 호출 없음. 수정하지 않음. 바이너리·5MB 초과 10개·다른 브랜치는 조사 범위 밖. |
| `C:\vibe\tbox-buddy\tbox\gemini_client.py` | 개인 `assistant.run` 토큰으로 `/tbox/api/assistant/chat` 호출. Google 키/모델을 하드코딩하지 않고 서버 모델을 조회한다. 11개 기존 시험 통과. PC 코드 변경 없음. |
| `/var/www/qaredi/tbox-v2` | `MemberAi` 사용 활성, 키 설정됨. 기존 대화 22건 중 완료 21건을 내용 조회 없이 집계. `Ai.php` 문서 AI 전용 키는 미설정. 공용 대화·사진 글쓰기 `Fetch`와 문서 생성·녹음 `Ai`의 고정 3.5 호출을 3.6으로 변경. 이미지·임베딩 모델 유지. |
| `/home/changkwon/tbox-buddy-local` | 별도 로컬 ChatGPT 인계 clone; Gemini API 직접 호출 없음. 변경 없음. |
| `/opt/erp2/backend/src/services/gemini.js` | 실행 중인 backend가 사용하는 미추적 파일. 첫 모델 3.5, 기존 fallback은 2.5→2.0→1.5. 첫 모델만 3.6으로 바꾸고 해당 모델의 deprecated `temperature` 제거. fallback 순서/설정 보존. 다른 미커밋 파일은 그대로 보존, 기존 미추적 기능 전체를 GitHub에 임의로 커밋하지 않음. |

## 호환성 및 검사

- Qaredi: `src/Ai.php`, `src/Fetch.php`, `src/MemberAi.php`; 기존 `generateContent` 요청/응답, 대화 이력, 동의, 한도, 토큰 분리, 인증서/DNS/IP고정/리다이렉트 금지 유지. Ai generation의 `candidateCount=1`은 공식 이행 지침에 따라 제거.
- 변경 시험: `tests/community/stage13_https.py`에서 정확 3.6 health/generate/transcribe URL·기존 embedding URL·candidateCount 없음 검증. `tests/assistant/chat.py`에서 새 모델 계약 검증.
- Linux 격리 후보 `/home/changkwon/gemini36-candidate-20261014`: PHP 구문 3파일 통과, 원본 Ai transport TLS 10개 통과(실제 Google 호출 없음), security lint 0, 자산 버전 검사 통과, 위키 구조/누락 0.
- PC 비서 기존 Python Gemini 시험 11/0. ERP2 격리 계약 3/0(주 모델·대화 정규화·기존 fallback).
- 실제 Google API: ERP2 이전/새 모델 각 단문·대화 4/4 HTTP200/STOP, 요청 모델 3.5도 실제 모델3.6. 설치된 기존 SDK의 후보 코드로 새 모델 단문·대화 2/2 완료. 운영 파일 반영 후 대화 이력도 실제 새 모델에서 완료.
- Qaredi 공용 키: 이전 이름의 단문·이력·요약 완료, 직접3.6 이력/단문/요약 완료. 빠른 연속 요청 일부429. 간격을 둔 3.6 단문/요약은 완료. 실제 무음 WAV 전사는55초 시간초과; 전사 품질/실제 사용자 녹음 미검증. 문서 전용 키 미설정이므로 그 기능의 운영 실사용 성공으로 보고하지 않음.
- 합성 DB assistant 재실행 integration376/0→chat38/0, 기준 fa9e3ab도376/0→38/0. 최초 실행의 완료 단언 실패 원인은 미확정이고 재현되지 않았다. 진단 증거 `/tmp/qaredi-gemini36-chatdiag-uh883tho/diagnostic-summary.json`. 모든 자기 시험 HTTP/DB 정리 완료.
- 기존 회귀는 Qaredi의 이미 승인된 밤 회귀 정책에 따라 기존 대기열에 추가한다. 새 예약 자동화는 만들지 않는다.

## 비용

Standard, USD/백만 토큰 기준. 입력은 기본1.50으로 동일, 출력 기본9.00→7.50(16.7% 인하). 3.6은 2026-12-31까지 입력0.75·출력3.75, 2027-01-01부터1.50·7.50. 출력 단가는 thinking 토큰을 포함한다. 실제 청구는 프로젝트의 무료/유료 등급, 토큰 수, 캐시/도구 사용에 따라 다르며 청구서·과거 일별 사용량을 조회하지 않았다.

3.5 별칭이 이미3.6으로 전달되므로 모델명을 직접 바꾸는 행위 자체가 추가 할인이나 별도 절감을 발생시키는 것은 아니다. 자동 전달 의존과 표시 불일치를 제거하는 조치다.

## 서버 적용 및 원본 보존

- Qaredi: 고정 후보 diff·원본 파일 백업/바이트 검증·fresh HEAD/파일hash 검사 후 본 작업 변경만 반영. 기존 배포 기록과 현재 checkout의 오래된 차이를 본 작업 검증으로 소급 인정하지 않는다. DB/키/패키지/cron/WAF 변경 없음. PHP-FPM reload 필요.
- ERP2: 원본 SHA256 `d564aa46e9a34789a55d5431577c976f070272b89a79840abd903bed1a0221a6`, 변경 후 `e8e3234492ee0ea9a10cbf2bdec235f606bdb2232bb5297b6dd9cd9e0cb14692`. `/var/backups/erp2-gemini36-20261014-145607/gemini-before.js` 백업과 바이트 검증 후 원자 교체, backend 재시작/active 확인. 로컬 health200/server up/DB up. 서버에서 공용 URL 확인은 Cloudflare challenge403이므로 외부 페이지 정상까지 입증하지 않음.
- 실패 시 이전 코드 파일만 복귀 가능. 운영 DB·기존 사용자 대화·키를 복구하거나 삭제하지 않는다.
- PC 비서의 이미 열린 창은 모델 조회를 다시 눌러3.6 정보를 갱신한다. 새로운 창은 서버에서 자동 조회한다.

## 검증 한계와 재검증 방법

1. 전사: 정상 quota를 확보한 뒤 문서 AI 전용 키를 기존 관리자 경로에서 설정하고, 허용·동의된 짧은 합성 음성을 기존 받아쓰기 화면에서 요청. HTTP상태/완료본문/`modelVersion`만 확인하고 키·실사용자 음성은 기록하지 않는다.
2. ERP2 공용 URL: 일반 로그인 브라우저에서 Cloudflare 검증을 통과해 AI 비서의 새 대화와 같은 이력의 후속 질문 확인. 서버의 로컬 health와 공급자 검증은 공용 브라우저 로그인/응답의 증거를 대신하지 않는다.
3. 설정 저장소 밖의 다른 서버·GitHub 비기본 브랜치·외부 SaaS 설정·현재 실행 중인 Windows 앱이 이 경로를 실제로 사용하는지는 이번 정적/서버 조사 범위 밖이다.

공식 근거: [모델 종료/자동 전달](https://ai.google.dev/gemini-api/docs/deprecations), [3.6 이행/기본 가격](https://ai.google.dev/gemini-api/docs/generate-content/whats-new-gemini-3.6), [현재 가격/할인 기간](https://ai.google.dev/gemini-api/docs/pricing).

## 배포 결과 (서버 검증 기록)

- Qaredi 기능 커밋 `2f5e99ae3649`, 발행 `3dd482a3500b` / v2.67.1. 적용 직전 기준 `0a33f08f89f0`. 본 작업14파일 백업·바이트검증·git apply --check·fresh HEAD/원본hash 후 FF, PHP3구문/FPM reload 통과. 백업 `/home/changkwon/.cache/qaredi-check/gemini36-backup-20261014-145942`.
- 운영 원본 Fetch로3.6 실제 요청: model/modelVersion3.6, STOP, 합성답변 「검증 완료」. 기존 대화 행22개 보존. 실제 공개/로컬 health200, 무토큰 assistant창구401. security lint/자산/위키 재확인 통과.
- ERP2 파일 원자 교체·backend 재시작, local health200/server·DB up. 실제 설치 SDK의 운영 파일로3.6 응답 완료. 공용 HTTPS는 Cloudflare challenge403; 이 경계는 미검증으로 유지한다.
- 기존 배포 스냅샷은 전체111개 이전차이를 검증한 자료가 아니므로 전역 verified_snapshot 값을 덮어쓰지 않고 production-state에 이번 targeted_hotfixes 기록만 추가한다.
