# 커뮤니티 외부 답변 붙여넣기 보존

- 상태: 서버 검증 완료·배포 대기
- 작업 브랜치: `cloud/community-paste-20261016`
- 기준 main: `352b2e2765c6936f0c3edf18f1ec17695232332f` / v2.68.2
- 담당 환경: Windows 로컬 구현·합성 브라우저 시험, SSH 서버 독립 검토·최종 수락·배포

## 목적과 범위

ChatGPT 답변을 마우스로 선택한 뒤 Ctrl+C/Ctrl+V 하면 작업 설명의 비교표가 연속 문자열로 풀리는 문제를 고친다. 문서 전체 편집기와 작업 설명은 같은 clipboard parser를 사용하므로 함께 적용한다. 기존 table atom에 외부 HTML table 해석 규칙이 없고, 굵기 규칙은 `font-weight:700`을 받지 않는 것을 확인했다.

구현 대상은 `public/assets/community/editor-schema.js`와 필요한 순수 변환 보조 파일, 수락 시험 `tests/community/clipboard_html.py`다. `table-editor.js`의 셀 붙여넣기는 새 외부 표 atom에서 글을 꺼내 행·셀 구분을 유지하도록 최소 연계 수정한다. 구현 계획은 `docs/plan-community-paste.md`. 기존 표 JSON과 스키마를 재사용하고 문단·목록·강조·링크·셀 줄바꿈·유효한 병합을 유지한다. HTML 안에 문자로 들어온 `**`는 임의 변환하지 않는다. 이미 평문으로 저장한 글의 표 경계는 자동 추측·복원하지 않는다.

## 검사

- 변경 전 원인: table 노드의 외부 HTML parseDOM 부재. 동일 합성 표로 전후 수락 시험을 실행한다.
- 로컬: Python 3.11·Node·Playwright 사용 가능, PHP 실행 파일 없음. 브라우저 검사와 PHP 검증 제외 결과를 구분해 기록한다.
- 서버: 동일 후보에서 PHP `Doc::validate` 포함 수락, 보안 린트·자산 버전·위키 검사, 이후 운영 HTTPS 확인을 수행한다. 넓은 회귀는 최신 공통 CLAUDE 3.1의 밤 대기열로 넘긴다.
- 사용자 글: 연결된 Chrome은 로그인 페이지이고 MCP 페이지 읽기는 404였다. 사용자 원문을 읽거나 변경하지 않았으며 합성 시험을 사용자 계정 검증으로 주장하지 않는다.
- 로컬 work_status는 얕은 복제에 과거 배포 커밋이 없어 완료 판정하지 못했다. 실제 운영 기준은 서버 checkout/main 및 공개 자산 대조로 다시 확인한다.

## 로컬 구현자 수락과 메인 검토

변경 전 같은 수락에서 표 보존·숫자 굵기 두 실패를 재현했다. 최종 로컬 `python -X utf8 tests/community/clipboard_html.py --skip-php`는 36/0, PHP 검증은 0건(skip)이다. 단순 HTML 표, 셀 문단/줄바꿈/링크/코드, 병합, 2,000 Unicode 문자/100세그먼트 경계, 중첩·깨진 표·초과 fallback, XSS·위조멘션, 기존 raw 왕복, 전체 편집/작업 설명 자동 저장·재열기, Undo/Redo, 평문 붙여넣기를 검사했다. 실제 운영 API 대신 모의 API를 사용한다. 메인은 diff에서 임의 HTML·속성 저장이나 참조/인가 확장이 없음을 확인했고, 서버 최종 수락에 읽기 화면의 실제 표 캡처를 더했다. 하네스 reload 뒤 helper 재등록 오류는 시험에서만 수정했다.

표 밖 `<br>`의 기존 공백 변환은 이 수정에 포함하지 않는다. 중첩/깨진/상한초과 표는 안전한 텍스트로 전환되며, 전체 문서 제한을 넘으면 기존 저장 검증기가 거부한다. 사용자 OS의 실제 선택 클립보드와 해당 글 저장은 미검증이다.

## 서버 적용 절차

- DB·패키지·인증·설정 변경 없음. 기존 표 스키마와 저장 API를 그대로 사용한다.
- 기존 자료·권한 보존: 사용자의 글을 고치지 않는다. 운영 저장 시험이 필요하면 지정 QA의 새 합성 글만 사용한다.
- 고정 후보 독립 검토 → 백업·복호화 확인 → 파일 목록 고정·모의 적용 → fresh main/운영 드리프트 확인 → 패치 릴리스·자산 반영 → HTTPS·기존 데이터 보존 확인 → 기록·push 순서다.
- 실패 시: 이전 코드와 현재 후보를 보존하고 충돌 또는 실패 원인을 조사한다. DB 삭제·복원·재시드 및 사용자 글 덮어쓰기를 하지 않는다.

## 배포 결과 (서버에서만 작성)

후보 검증과 배포 후 실제 버전·시각·근거·미검증 항목을 기록한다.


## 서버 최종 수락 — 2026-10-16

격리 후보에서 `python3 tests/community/clipboard_html.py`: 37/0, 실제 PHP view 실행 및 `Doc::validate` 24건 통과. 작업 설명은 production의 비공개 mountPaneEditor를 시험 응답에만 export하여 실행했다. 운영 소스에 test hook을 넣지 않았고 API는 합성 응답이다. `clipboard-rendered.png`에서 저장 후 읽기 표를 확인한다. 보안 린트0, 자산42파일/75import, 위키70편/문제0. 첫 서버 diff 검사는 Windows 전송본의 CRLF로 실패했으며, 전송한 파일의 CRLF→LF만 정규화한 동등성 기록을 남겼다. 문서나 코드 내용을 변경한 실패가 아니다. 이후 자산 해시를 다시 생성하고 최종 정적 검사를 수행한다.

근거: `/home/changkwon/qaredi-clipboard-results-20261016/`의 results.json·security.log·line-ending-normalization.json·clipboard-rendered.png. 실제 사용자 계정/OS 클립보드와 기존 글 복원은 미검증이다.


### 읽기 화면의 셀 줄바꿈 보정

메인이 실제 저장 후 읽기 PNG를 확인하여 셀의 LF가 화면에서 공백으로 접히는 기존 CSS를 발견했다. 일반 문서 표 셀과 표 편집기의 셀 미리보기 두 규칙에 `white-space: pre-line`을 적용했다. DB 표·다른 화면은 제외한다. 이에 닿는 `--render-only` 수락만 추가 실행: 1280/390px에서 세 줄의 실제 표시·높이, 셀 미리보기, JS 오류0 등 6/0, PHP 검증1건. JS 최종 37/0·PHP24건 결과와 분리한다. 최종 자산 묶음 `ccbf1ba61555`; CSS 변경은 JS import 해시에 영향을 주지 않는다. 보안/자산/위키/diff/JS 문법 최종 통과. 증거 `final/`, `render-focused/`.

독립 검토 판정: 메인은 구현자와 별도 문맥에서 runtime diff, 스키마 상한, 위조 멘션·능동 콘텐츠 제거, 새ID/기존raw보존, 실제 PHP 검증과 합성 UI 저장 결과를 대조했다. 차단 이슈0. 자체 HTML table 중첩·깨짐·상한초과는 평문 fallback이며 이미 저장한 평문 복원이나 원본 사이트 CSS 보존을 주장하지 않는다. 사용자 실제 원문은 미수정.
