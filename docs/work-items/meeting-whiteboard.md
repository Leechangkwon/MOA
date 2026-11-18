# 업무 프로젝트 회의 그림판 (Q:F-17)

- 상태: 기본 기능 수락 완료 · 운영 반영 준비. Q:T-62 화면 지연 목표 미달은 아래 제한으로 남김.
- 작업 브랜치: cloud/meeting-whiteboard
- 기준 main: 2112e8dbcc9d (v2.78.1)
- 담당 환경: 서버 격리 worktree `/home/changkwon/qaredi-whiteboard-20261118`

## 목적과 범위

사용자 2026-11-18 「화이트보드 진행 해」. 현재 묶음은 기획 v1.2의 **1차 a**(공동 그리기·기록·되감기·확정·MCP)다. **1차 b** 배경 캡처는 MB-14의 기본 판 수락 뒤 별도 파일 보안 묶음이다. 지시서: `docs/qf17-whiteboard-directive.md`.

업무 프로젝트 작업의 설명 다음 「회의 그림판」에서 만들기/열기. 실제 로컬 Excalidraw와 글꼴, Node의 임시 미리보기/커서/새 순번 알림, PHP/Postgres의 작성자·순번·시각·version/nonce 승자와 SHA-256 사슬, 되감기, 장면 원본/참조 PNG 확정과 새 판을 제공한다. 사람 본문은 보존하며 로컬 moa MCP 5개 도구로 확정 판을 읽고 회의록·근거 절을 저장한다. 서버 AI 호출은 없다. 관리자 우회·일반 문서·내 작업은 허용하지 않는다.

## 실행한 검사와 근거

결과 디렉터리: `/home/changkwon/qaredi-whiteboard-results-20261118/` (비공개, 운영 원문 복사 0).

- `python3 tests/community/memories_harness.py --suite whiteboard_http.py --output .../acceptance` → 실제 PHP/Postgres·두 Chromium·동일 출처 WebSocket **143/0**. 권한·관리자 우회·CSRF·토큰 범위/철회·작성자 위조·LWW/중복·크기 413·확정 CAS/잠금·이전 판·회의록 CAS/refs/멱등성/사람 문단·검사 대기 이미지·작성자 보존·사슬 변조 탐지 종료 2를 확인했다. 미저장 로컬 보관, 이미지 파일 붙여넣기 차단, 중계기 종료 시 1초 폴링(관측 244ms), 실제 PNG 내보내기, 지문 표시, 밝음/어둠 360/412/820/1280, 로컬 글꼴 200·외부 요청/JS/CSP 위반 0도 포함한다.
- `node tests/community/whiteboard_relay.mjs` → **13/0**. 만료·서명·Origin·일회 입장권·읽기 전용·인원 제한과 거절 후 생존.
- 기존 첨부 공통 저장 추출의 영향: `... --suite qf12_u3_attach_http.py --output .../attachments` → **249/0**.
- 실제 설치된 MCP SDK 1.12.4, Pydantic 2.11.7, Python 3.10.12에서 새 소스 unittest **115/0**, 버전 보정 후 클라이언트 **61/0**. 도구 18개(회의 5개) 및 실제 image content 형식 확인.
- `php tests/community/whiteboard_graph.php` → 글 상자 둘/화살표 라벨/마크업 이스케이프 통과.
- PHP 16파일 구문·JS/JSON/Python 구문, `security_lint.sh` 금지 0, 부품 버전 일치, 위키 71편/구조 문제 0, `git diff --check` 통과. `bash scripts/vendor_excalidraw.sh --verify` → 고정 lockfile 재빌드 바이트 일치.

## Q:T-62 성능 제한 — 통과로 기록하지 않음

`WHITEBOARD_TEST_LOAD_SECONDS=600 python3 tests/community/memories_harness.py --suite whiteboard_http.py --output .../release` → 기능 앞부분 114통과 뒤 화면 지연 단언 1실패. 621.58초, 6개 독립 Chromium 세션/검토 계정 2개, 실제 포인터 획 330개, 미리보기 표본 4,810개. 임시 nginx + **실제 FPM 작업자 8개**, OPcache 활성화.

- PHP 응답 p95 **356.5ms**, 최대 활성 작업자 **6/8**, 최대 대기열 **0**, max_children 도달 **0**.
- 중계기 최고 RSS **72,658,944bytes**(약 69.3MiB), 128MiB 제한 안.
- 전송 직전 시각 → 수신 창 두 animation frame 뒤(같은 호스트 시계) 화면 반영 p95 **1,333ms**. 목표 **300ms 미달**. 별도로 요소 수정 시각 → 수신 p95는 910ms.

브라우저 6개까지 2코어/3.9GB 운영 호스트에서 함께 렌더한 측정이다. 클라이언트 자원과 서버 자원의 영향을 분리하지 못했으므로 원인을 확정하지 않는다. 실제 사용자 기기에 분산한 조건의 목표 달성은 미검증이다. 기본 기능은 선택해서 여는 화면으로 제공하되 이 지연을 출시 제한으로 공개하며, 목표 달성·실기기 성능은 후속이다. 성능 실패를 없애려고 기준을 완화하거나 시험을 통과로 바꾸지 않았다. 최종 기본 기능 수락 143/0은 성능 시험과 별도다.

수정한 동시 편집 결함: 수신 렌더의 자동 정렬 변경과 이미 전송 대기 중인 요소를 다시 전송하지 않도록 불변 stamp/로컬 입력/엔진 reconcile을 구분했다. 원격 프레임이 자동 저장 타이머를 밀지 않게 하고 커서 중복을 줄였다.

## 서버 적용 순서와 원본 보존

1. 후보 커밋/변경 파일 고정, 공식 Node v24.22.3 SHA-256·중계기/로컬 자산 산출물 대조.
2. 기존 운영 스크립트 DB·파일 암호화 백업, checksum/목록 확인 및 두 아카이브 **전체 복호화 종료 0**. 평문 디스크 저장/운영 복원 0.
3. nginx 모의 설정 `nginx -t` 통과, 설치 도구 읽기 전용 preflight 통과. Git2 조회 15초 timeout(124), 최신 원격 main 확인 불가. 서버 HEAD와 기존 dirty 지문은 따로 재확인.
4. 추가 전용 `2026-11-18_community_whiteboards.sql` 6표 및 앱/백업 권한, 코드 main 앞으로 통합. 기존 SECURITY/STAGES/app.css/미추적 작업 문서는 보존.
5. `/opt/moa-whiteboard` 공식 Node·중계기, root600 별도 서명 키, loopback8093, DynamicUser/파일·DB 접근/바깥 connect 금지 systemd, nginx 두 location과 연결 제한. 기존 nvm·다른 서비스 설정은 그대로 유지. 새 서비스 사실은 이 기록과 SECURITY에 남김(다른 사람에게 메시지 전송 0).
6. 실제 HTTPS/브라우저/WSS 및 기존 업무 104표 원래 행·저장 원본 2개·설정 10개 지문을 검증. 새 QA 자료만 정상 API로 만들고 휴지통으로 이동.
7. 설치된 로컬 moa 소스 패키지 갱신 전 비공개 백업, 토큰/연결 설정 보존, 새 프로세스 도구 확인. 기존 Claude 세션은 사용자가 재연결해야 새 도구 목록을 받는다.

실패 시 이전 코드/설정으로 복귀하고 신규 표·회의 기록은 보존한다. DB 삭제·복원·재시드는 자동 실행하지 않는다.

회귀는 기존 밤 대기열에 `community.qf12_u4_http`, `community.qf12_u4_browser`, `community.qf16_templates_http`, `community.qf16_templates_browser`, `community.work_document_mcp`, `community.work_document_create`를 넣는다. 작업 상세/페이지 메타·공통 저장/템플릿·MCP 판정에 닿기 때문이다. 일반 자료실 업로드·다른 앱 전체 매트릭스는 변경 창구가 아니므로 선택하지 않는다. 첨부 영향은 249/0으로 따로 확인했다.

## 미검증

- 같은 Codex 문맥에서 구현·검토했으며 독립 문맥 검토가 아니다.
- 실물 휴대폰·Safari·스크린리더·실제 여러 사용자 기기와 사용자 회의/Claude 재연결.
- Q:T-62 300ms 목표 달성. 배경 캡처·녹음·원격 OAuth MCP 회의 도구는 이번 묶음에 없다.

## 배포 결과

아직 운영 미반영. 실제 확인 뒤 커밋·버전·보존 결과를 추가한다.
