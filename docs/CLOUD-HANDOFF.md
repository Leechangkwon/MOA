# 휴가 중 클라우드 작업 인계 — 2026-10-03

> 2026-10-11 운영 **v2.54.1 `31848a8`**: 회사·모임 다중 공간/역할·초대·보관·소유권 이전, 공지·할 일·일정·자료·이야기, 공동 홈 편집·개인 홈 계정 동기화·공간 최근 활동. 실제HTTP75/0·공통196/0·운영HTTPS56/0, 파일1042행/원본975 SHA·기존ownerACL 보존. additive migration1(새표6개), 설정/PC 변경0. `docs/work-items/product-spaces.md`.

> 2026-10-11 운영 **v2.53.8 `97cc6d8`**: 홈 PC 비서 연결에서 기본 Gemini 토큰 발급·OTP 최초 등록·정확한 PC 붙여넣기 메뉴를 한 화면에 안내. 파일은 별도 선택(읽기 기본), 개인 GPT 로그인 안내는 별도 선택. 신규41/0·안내27/0·AI79/0·격리196/0·실제HTTPS28/0. 저장소975파일/1042행 보존. Windows 토큰 등록/응답은 미검증, update.4 재설치 불필요. `docs/work-items/buddy-token-onboarding.md`.


> 2026-10-11 PC 비서 **update.4 사내 게시 완료**: Windows WinError10013(50573 사용 거부)을 기존 실행으로 오판하던 문제 수정. 지정 예비 포트·연결 실패 표시·시작 오류 창·run-diagnose·setup 종료 pause. PC `7a0ff3f`, 16/0·87파일 업데이트 및 설정 보존·게시/다운로드 23/0. Windows 최종 트레이는 사용자 확인 대기. 웹 런타임 v2.53.7 유지. `docs/work-items/buddy-startup-fix.md`.

> 2026-10-10 운영: **v2.53.7 `77ed769`** 일반 로그인→모아 홈. 업무·자료실·AI 비서·이야기 목적별 안내, 최근 작업 우선, 이야기 접어서 보존. 기본 모아 테마 서비스별 색상·중립 본문. 명시 목적지/PC 안내 복귀 유지. 홈70/0·격리196/0·AI79/0·운영HTTPS37/0. 996파일/1041행 보존, Git2 main/tag/작업 브랜치 반영 완료. `docs/work-items/service-home.md`.

> 2026-10-10 운영: **v2.53.6 `65d85b6`** 홈 PC 비서 연결을 전용 단계별 안내로 변경(`/welcome?section=pc-assistant`). 처음 설치/기존 업데이트·실제 트레이 메뉴·ChatGPT 로그인·웹 GPT 위치/성공 표시. 안내27/0·기존AI79/0·공통164/0·운영HTTPS25/0. 원본996파일/1041행 보존, PC update.3 변경0. Git2 main/tag/작업 브랜치 반영 완료. `docs/work-items/pc-buddy-guide.md`.

> 2026-10-10 운영: **v2.53.5 `13cea0c`** GPT 간편 연결. 웹 GPT 연결→최초 PC 승인→기억한 브라우저 재연결. update.3 사내 게시 완료/회원 다운로드 SHA 일치. 웹79·실제 loopback7·공통164·운영 HTTPS22·게시23 통과. PC110+URI2 통과, Windows 전용3 skip; **이번 실제 Windows 동작/응답은 미검증**. Git2 연결 복구 후 main/tag/PC·웹 브랜치 atomic push 완료(2026-10-10 재확인). `docs/work-items/gpt-easy-connect.md` 참조.

> 2026-10-09 최신 운영: **v2.53.4 `6bf9816`** 모바일 메뉴를 본문 위의 native modal로 변경. 본문 위치/스크롤 불변, 배경 차단·바깥 클릭/닫기/Escape·모달 안 메뉴 전환·PC복원. 셸173/0·공통164/0·실제HTTPS79/0, 기존1031파일/1040행 보존. `docs/work-items/mobile-menu-modal.md`. 아래 v2.53.3 인라인 메뉴 구조를 대체한다.

> 2026-10-09 최신 운영: **v2.53.3 `12e8dce`** 모바일 사이드바 정리. ☰=현재 앱 내비, 앱 이름=앱·계정 메뉴 한 판씩. 최근/프로젝트/문서 기본 접기·하나씩 열기, PC 탐색/즐겨찾기 취향 유지. 최종 셸137/0·공통164/0·실제HTTPS55/0, 원본1031파일/1040파일행 보존. AI 비서 update.2 사내 게시 유지. `docs/work-items/mobile-sidebar.md` 참조.

> 2026-10-09 최신 운영: v2.53.2 `739445c`, 기존 비서 update.cmd·파일 팔레트 AI(Ctrl+J) 안내. Gemini 실응답 대기. **preview.1/2와 update.1 게시 금지**. 최종 update.2는 사용자 직접 게시 지시에 따라 **사내 게시 완료**(17:14 KST). 정상 관리자 인증/운영 ClamAV/일반 구성원 다운로드 SHA 일치, HTTPS24·브라우저10 통과. `docs/work-items/buddy-update2-prepublish.md`가 현재 파일/해시/게시 절차다. HTTPS30/0·원본1030파일 보존. `docs/work-items/buddy-update1-prepublish.md` 참조.

> 2026-10-09 최신 운영: v2.53.1 `1ebd78d`, 기존 트레이 비서 **업데이트**로 안내 정정. 기존 바로가기·파일 팔레트·단일 트레이 유지. preview.1/2 **게시 금지 유지**, 프로그램0건. Gemini 계약은 v2.53.0과 같다. 실제 HTTPS30/0·원본1030파일 보존. `docs/work-items/buddy-existing-app-update.md` 참조.

> 2026-10-09 최신 운영: v2.53.0 `a17a5e9`, 개인 assistant.run Gemini 창구와 T-box AI 비서 배포함. 실제 HTTPS30/0·기존 파일1030 보존. **통합 설치 ZIP은 아직 미게시**. 실Windows Gemini 및 단축키 충돌 처리는 로컬 세션 후속. `docs/work-items/buddy-server-contract.md`가 현재 계약/게시 절차다. 아래 기록은 이전 판이다.

> 2026-10-09 최신 운영: v2.52.3 `ce9a486`, PC 비서 받기·설치·GPT 선택 안내 갱신. 실제 HTTPS44/0, DB/설정 변경0, 원본1030파일 보존. Windows 앱 b86a602의 실제 로그인/짧은 응답은 확인했고 웹 링크는 실행 코드가 같은 927f919 ZIP이다. 실Windows 웹↔PC 전체 페어링은 별도 확인 대상. `docs/work-items/buddy-download-flow.md`, `deploy/production-state.json`을 따른다. 아래는 이전 기록이다.

> 2026-10-06 최신 운영: Q:F-11 v2.50.0 `03f77ac` 배포 완료(어느 화면에서나 이어지는 떠 있는 메신저 · 커뮤니티 옛 대화 창 대체). migration1(`member_messages.request_id`)·nginx0, 운영 HTTPS78, 데이터·원본1029파일 보존. ChatGPT 원격 MCP(v2.49.0)는 여전히 **기본 비활성**. 지시서 `docs/qf11-floating-messenger-directive.md`와 `deploy/production-state.json` 참조. 아래 기록은 이전 배포다.
> 2026-10-06 이전 운영: PR #4 v2.49.0 `302d415` 배포 완료(ChatGPT 원격 MCP, **기본 비활성**). migration1·nginx3, 운영 HTTPS71, 데이터·원본1028파일 보존. 켜기 전 실제 ChatGPT·프록시 로그·휴대폰 확인 필요. `docs/work-items/chatgpt-remote-mcp.md`와 `deploy/production-state.json` 참조. 아래 기록은 이전 배포다.


> 2026-10-04 최신 운영: PR #6 v2.48.0 `941cb84` 배포 완료. 요약 카드 사진 스와이프·이전/다음·번호 표시. gallery8/social12/격리PHP19/공통162/운영HTTPS128, 데이터·원본1028파일 보존. `docs/work-items/story-preview-gallery.md`와 `deploy/production-state.json` 참조. 아래 기록은 이전 배포다.


> 2026-10-04 최신 핫픽스: PR #5 v2.47.1 `6c5b9df` 운영 배포 완료. 기존 글 전체를 요약 카드로 표시하고 상세 모달로 연다. HTTPS105/105, 데이터/사진 보존. `docs/work-items/story-preview-fix.md`와 `deploy/production-state.json` 참조. 아래 v2.47.0 기록은 앞선 배포다.

> 2026-10-04 최신 운영: 회원 경험 PR #3의 v2.47.0 `d75a5d5` 배포 완료. OTP/패스키 선택, 커뮤니티 둘러보기/가이드, 팔로우/공개 범위/발견, 여행 계획/MCP 여행 도구, 이야기 요약/상세가 반영됐다. 공통 교차 회귀162·운영HTTPS70 통과, 기존 데이터/권한/저장 원본1018파일 보존. 최신 상태는 `deploy/production-state.json`, 상세는 `docs/work-items/community-member-experience.md`를 따른다. 아래 v2.46.0 표와 미구현 범위는 2026-10-03 당시 기록이다. 실제 기기·Windows 일반 설치물과 이 채팅 MCP 연결은 여전히 별도다.

**Git2가 코드 전달 창구다. 서버에 접속하지 못하는 동안은 개발·검증·push까지 진행하고, 배포 대기 상태를 남긴다. 서버는 마지막 검증 버전으로 계속 운영한다.** 자동 pull/배포는 구성되어 있지 않다. main의 최신 커밋과 실제 배포 커밋은 서로 다른 개념이다.

## 지금 확인한 기준

| 항목 | 확인 결과 |
|---|---|
| 웹 저장소 | `https://git2.tla.so/changkwon/T-Box_Re` |
| 정리 시작 시 서버와 origin/main | `dd9611ac7f9e83a7ab707e96029cfd4b63d1dd49`, 일치·미커밋 변경 없음 |
| 운영 기능 버전 | v2.46.0, 발행 커밋 `56a4ae177865e3bbce44f67856f87553c8d36b53`. 최종 검증 커밋은 배포 기록 참조 |
| 배포 판정 기록 | `deploy/production-state.json` — 서버 검증 후에만 갱신 |
| 사진 이야기 | 최대10장, 등록순 기본, 선택형 재배치, 사진+짧은 글 구성·장면 편집·기존 메모/원본 보존 |
| 서버 상태 | nginx/PHP-FPM/PostgreSQL active, story JSONB 및 GD 적용, 운영 HTTPS53/53. 기존 Gemini로 남한산성9장 실제 생성 확인 |
| PR #2 / insta | 병합 완료. `insta`와 `feat/travel-memories`는 main에 포함됨. 새 작업 기준으로 쓰지 않는다 |
| 사용자 데이터 | DB/사진은 서버에 있다. Git clone으로 복제되지 않는다. 실제 글을 테스트 데이터로 커밋하지 않는다 |

상세 증거: [사진 이야기 배포](thread-story-2026-10-03.md), [앞선 통합 배포](completion-release-2026-10-03.md). 미통합 개인 권한·토큰·모바일 개선까지 v2.46.0에 반영했다. [이번 검증·배포 기록](work-items/vacation-integration-2026-10-03.md)을 함께 본다.

## 매 작업의 순서

깨끗한 새 작업본에서:

```bash
git fetch origin
git switch main
git pull --ff-only origin main
python3 scripts/work_status.py
git switch -c cloud/photo-editor
```

`cloud/photo-editor`는 예시다. 기능마다 명확한 브랜치 이름을 정하고 기존 작업은 같은 브랜치에서 이어 간다. 작업 중인 변경이 있으면 먼저 `git status`로 확인하고 보존한다. 동시에 여러 작업은 별도 checkout/worktree를 쓰며 같은 파일을 여러 세션이 동시에 고치지 않는다.

작업 종료 시 파일을 지정해 commit/push하고 `docs/work-items/TEMPLATE.md` 형식의 인계를 함께 올린다. PR을 만들 수 있으면 main을 대상으로 만든다. PR 권한이 없으면 브랜치와 커밋을 인계에 적으면 된다. 클라우드에서는 기능 버전 발행과 배포 성공 기록을 선행하지 않는다. 서버에서 검증한 후보를 main에 통합하고 `scripts/release.sh`로 발행한다.

다음 세션에는 아래 문장을 붙이면 된다:

> T-Box_Re의 AGENTS.md, docs/CLOUD-HANDOFF.md, deploy/production-state.json을 먼저 읽어. origin/main 기준을 확인하고 cloud/<작업명>에서 이어 작업해. 서버에 접속할 수 없으면 합성 데이터로 가능한 검증까지 하고 Git2에 push한 뒤, 서버 검증·배포 대기라고 기록해. 운영 키·DB·사진은 저장소에 넣지 마. 기존 사용자 데이터와 다른 작업 변경을 보존해.

## Git으로 전달되지 않던 문서

서버의 부모 `/var/www/qaredi`에는 별도 로컬 저장소가 있고 원격이 없다. 부모에만 적은 지침·기획·진척은 클라우드 clone에 없었다. 필요한 공통/커뮤니티/런처 문서를 `docs/server-context/`에 넣었다. 이후 클라우드 변경도 저장소 안에 기록하고 서버 복귀 시 부모 원본과 병합한다.

`php scripts/wiki_check.php`는 부모 STAGES가 없을 때 이 사본으로 같은 누락 검사를 수행한다. 사본을 썼다는 안내가 출력된다. 없는 파일을 빈 목록으로 취급하지 않는다.

## 가능한 검사와 서버에서 해야 할 검사

```bash
python3 scripts/work_status.py
bash scripts/security_lint.sh
php scripts/community_asset_version.php --check
php scripts/wiki_check.php
```

그 외 변경 파일에 맞는 PHP/JS 단위·브라우저·격리 DB 시험을 실행한다. `tests/community/memories_harness.py`는 전용 테스트 DB 역할과 `~/.config/qaredi/pgpass-testdb`가 필요하므로 clone만으로 실행되는 시험이 아니다. 없으면 클라우드 전용 합성 DB 환경을 준비하거나 미검증으로 남긴다. 서버의 인증 파일을 Git으로 옮기지 않는다. `deploy/install.sh`를 기존 운영 업데이트나 클라우드 검증 목적으로 실행하지 않는다.

서버 복귀 시 후보 커밋을 고정하고 변경 파일·migration·패키지/설정 목록을 검토한다. 백업/복호화·모의 적용·드리프트 확인 뒤 DB/코드의 정해진 순서로 반영한다. 실제 HTTPS와 데이터 보존을 확인한 다음 배포 기록을 갱신하여 main과 함께 push한다. `production-state.json`은 기록 시점의 증거다. 클라우드에서 이 파일만 읽고 서버의 현재 상태를 실측했다고 말하지 않는다.

## 남은 작업 — 미배포와 신규 기능 구분

| 항목 | 현재 상태 / 다음 조치 |
|---|---|
| 현재 main의 사진·Gemini 수정 | 배포 완료. 추가 서버 배포 대기 없음 |
| 실제 휴대폰 남한산성 저장 | 사용자 최종 확인 미보고. 서버의390px 브라우저 검증은 통과 |
| 앱 안 사진·영상 직접 편집 | 신규 기능, 아직 미구현. 별도 cloud 브랜치에서 진행 |
| 이 채팅의 Qaredi 게시글 읽기·수정 MCP | 아직 연결 안 됨. 기존 `clients/mcp`는 문서 생성·검색·목록·읽기용이며 현재 스레드 읽기/수정 연결 완료를 뜻하지 않음 |
| GPT/문서 AI/비서 자연어 추론 | 기존 GPT 키 없음·문서 AI 비활성·비서 실제 추론 미연결. Gemini 사진 글쓰기와 별개 |
| Windows PC 비서 | `AI-비서(로컬)`의 `f7caeb4`는 별도 제품 브랜치. 서버 main에 통째로 병합하지 않는다. 실Windows 설치/자격/배포물 갱신 확인 필요 |
| `wip/g0` 개인별 권한 개편 | v2.46.0 통합·검증·운영 반영 완료.252항목과 교차 재인증 검사 통과 |
| `wip/qf10` 토큰·MCP 검색 확장 | v2.46.0 통합·검증·운영 반영 완료.116항목/MCP105 통과, assistant.run 독립 범위 유지 |
| `wip/qf8` / `wip/qf9` | 커밋 계보는 미병합이나 기능은 각각 main의537f4af / b33e7fc·1223715로 반영됨. 브랜치 이름만 보고 재배포하지 않는다 |
| `wip/ux-r1` 사용감 개선 | v2.46.0 통합·검증·운영 반영 완료.모바일·태블릿·PC305항목 통과, 최신 모아/편집 제안 유지 |

옛 브랜치는 삭제하지 않았다. `wip/g0`, `wip/qf10`, `wip/ux-r1`은 충돌을 최신 기능에 맞게 해결하여 main에 통합했고 검증·운영 반영까지 마쳤다. 과거 STAGES의 미체크 기록 전체를 완료로 바꾸지는 않는다. 실제 휴대폰·Windows 확인과 위 신규 기능은 다음 작업이다.

운영 DB의 일부 표는 postgres, 다른 표는 tbox_owner 소유다. 소유권을 바꾸지 않는다. 격리 DB 모의 적용에서도 원래 owner/ACL을 유지해야 한다. 첫 적용은 이 차이로 트랜잭션에서 중단됐으며 기존 코드/데이터 변경 없이, 실제 소유권을 보존한 사본에서 다시 검증한 뒤 적용했다. 백업 복원이나 재시드는 실행하지 않았다.
