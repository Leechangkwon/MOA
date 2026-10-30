# 작업글 본문에 펼치기(토글) 블록

- 상태: 운영 반영 완료 v2.77.0 (2026-10-30)
- 작업 브랜치: `claude/workdoc-toggle`
- 기준 main: `10d8b99` (v2.76.0)
- 환경: 서버의 격리 작업본 `/home/changkwon/qaredi-workdoc-toggle-20261030`

## 목적과 범위

사용자 요청(2026-10-30): 매일 아침 모아 프로젝트 「AI 동향 리서치」에 자동으로 올라오는 작업글이 너무 길고 읽기 힘들다 — 「전문은 펼치면 볼 수 있도록, 건별 핵심은 초보자가 이해하게」. 모아 문서에는 토글 블록(`doc-schema.json` `toggle {text, children}`, 화면 `<details>`)이 이미 있지만, 개인 토큰·OAuth 의 작업글 창구(`WorkDocuments::create`·`update` append)는 평문 문단/제목/목록/인용만 받아 프로그램이 토글을 만들 길이 없다(일반 페이지 PATCH 는 세션 전용으로 유지).

이번 변경은 그 창구의 입력 종류에 `toggle` 하나만 더한다.

- `{type:"toggle", text, children:[{type?, text}...]}` — `text` 는 평문(10000자 이하), `children` 은 1개 이상, 종류는 문단·글머리 목록·번호 목록·인용만(제목·토글 없음 → 한 단계).
- `level` 은 제목에만, `children` 은 토글에만. 그 밖의 키는 지금처럼 400.
- 블록 수 상한 100 은 하위 블록까지 센다(만들기 1~100 · 추가 최대 100). 새 상한 숫자를 만들지 않는다.
- 만들기의 하위 블록 ID 도 요청 UUID·위치에서 안정적으로 도출 → 같은 요청 재전송은 `created:false`, 하위 본문이 다르면 409(기존 비교가 그대로 잡는다).
- 링크·멘션·마크·그림·파일·표는 여전히 받지 않는다. 저장 전 `Doc::validate` 는 그대로.
- 문구 교체(`replacements`)는 이미 토글과 하위 블록을 지원한다 — 바꾸지 않는다.
- OAuth MCP(`src/mcp/McpTools.php`)와 로컬 MCP(`clients/mcp`) 도구 입력 스키마·설명을 같은 계약으로.

바꾸지 않는 것: 권한 판정·행 잠금·감사·부모 상속·상태/섹션 규칙, 일반 페이지 창구, DB·설정·패키지.

수정 허용: `src/community/WorkDocuments.php`, `src/mcp/McpTools.php`, `clients/mcp/qaredi_mcp/server.py`·`client.py`(설명·검증이 필요할 때만)·`pyproject.toml`(버전)·`README.md`·`tests/`, `tests/mcp/work_document_create_http.py`·`work_documents_http.py`, 문서(API·ARCHITECTURE·SECURITY·STAGES·이 파일·`docs/work-document-mcp.md`), 발행(VERSION·CHANGELOG).

## 검사

- 수락(격리 PHP/PG 하네스): 토글이 든 작업글 만들기 → 다시 읽어 하위 블록·ID 확인 · 같은 요청 재전송 `created:false` · 하위 본문만 다른 재전송 409 · 추가(append)로 토글 · 하위 블록 문구 교체 · 거부 400(하위 없음 · 하위에 제목/토글 · 토글에 level · 문단에 children · 하위 포함 101개 · 하위에 모르는 키) · 기존 평문 계약 보존 · OAuth 도구로 토글 만들기.
- 정적: `php -l`, `scripts/security_lint.sh` 0, `clients/mcp` unittest.
- 회귀: 밤 대기열.

## 서버 적용

예전 작업글 창구 반영과 같은 순서 — 고정 후보·드리프트 확인 → 암호화 DB/코드/설정 백업 전체 복호화·파싱 → 파일 고정 모의 적용 → FF · php-fpm reload → HTTPS·원래 행·파일·owner/ACL 보존 확인. 운영 확인은 사용자 토큰으로 「AI 동향 리서치」 실행기가 토글 형식 글을 올리고 다시 읽는 것으로 한다.

## 구현·검증 결과

- 구현 `5091655`(Opus 에이전트): `WorkDocuments::plainBlock` 으로 만들기·추가 검증 통합, 토글 한 단계 하위, 하위 포함 100, 하위 안정 ID `요청UUID:i:j`, OAuth 스키마 `$workBlock`, 로컬 MCP 0.4.0. 오류 문구 둘이 바뀜(상태 코드 400 그대로): 「문단·제목·목록·인용·펼치기만 추가할 수 있습니다.」 · 문단에 children → 「하위 블록(children)은 펼치기에만 넣을 수 있습니다.」. 갱신 응답 `appended` 는 최상위 개수.
- 최종 판 한 번(메인): 만들기 수락 304/0 · 갱신 수락 279/0(격리 하네스, 합성 DB, 운영 기록 복사 0) · `clients/mcp` unittest 115 OK · php -l 2 · security_lint 0 · wiki_check 0. 원문 `/home/changkwon/qaredi-workdoc-toggle-results-20261030/final/`.
- 발견: 만들기 재전송 비교가 느슨해(10-17 부터) 숫자로 읽히는 문구만 다른 재전송을 같은 요청으로 본다 — SECURITY C:R-19, 이번에 고치지 않음.

## 운영 결과 (2026-10-30)

- v2.77.0 `bb8c7e0` 반영 10:25:21 KST — 암호화 백업 `/var/backups/tbox-v2/workdoc-toggle-20261030`(20MB, 전체 복호화 · DB 파싱 · tar 2,374 파일) → 고정 후보 모의 적용(17파일 · php -l · security_lint 0) → ff. 표 104 원래 행 · owner/ACL · 저장 파일 · 설정 10 보존, 파일 소유·권한 보존, 서비스 재시작·설정·DB 직접 변경 0(opcache 2초 재검사). 증거 `/home/changkwon/qaredi-workdoc-toggle-results-20261030/deploy/`.
- 운영 확인: `/tbox/api/health` 200 · 사용자 토큰으로 잘못된 토글 둘(하위에 제목 · 하위 없음)이 새 문구로 400, 작업글 수 불변(2) · 리서치 실행기를 토글 형식으로 돌려 「AI 동향 리서치」에 「AI 동향 2026-10-30 (금) · 펼치기 판」(최상위 28 · 토글 14 · 하위 28) 생성 → 다시 읽어 종류·하위 확인.
- 미검증: 화면에서 접고 펴기(브라우저 로그아웃 상태 — 비밀번호를 대신 넣지 않음, 사용자 확인 대기) · 회귀(밤 대기열 `2026-10-30-workdoc-toggle.json` — work_document_create · work_document_mcp · remote_mcp_http).
- 반영 전 백업 3개 규칙: 가장 오래된 `moa-qf15-s1b-20261025`(3파일 17,657,323B)를 `~/offsite-backups-20261030/` 로 옮김 — 사용자 내려받기·대조 뒤 서버에서 지움.
