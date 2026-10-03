# 휴가 중 클라우드 작업 인계 — 2026-10-03

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
