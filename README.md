# Qaredi — 사내 사이트 (앱 런처 · t-box · 커뮤니티)

사내 구성원이 **계정 하나**로 로그인해 **앱 런처**에서 기능을 골라 들어가는 사이트입니다.
이름 Qaredi 는 사용자 이름을 암호화했을 때 나온 문자열입니다.

| 모듈 | 무엇 | 상태 |
|---|---|---|
| **t-box** | 사내 파일 공유 — 실습에서 뚫린 5가지 취약점이 없는 형태로 재구축 | 완성·운영 중(`https://changkwon.mso.so`, v1.17.x). 통합 C 단계에서 `/tbox/` 로 이동 |
| **커뮤니티** | 노션처럼 블록으로 쓰는 개인 업무 공간 · 게시판 · 인계 | 기획 완료(PRD v0.3), 구현 전 |
| **런처** | `/` — 로그인 전에도 보이는 앱 타일(데이터 0) | 통합 C 단계 |

이 저장소는 원래 t-box 과제 저장소(`T-Box_Re`)였고, 2026-09-22 사용자 결정으로 **같은 저장소 안에서 Qaredi 로 자라는 중**입니다(통합 계획 `docs/integration-plan.md`).

## 문서

| 문서 | 무엇 |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | 사이트 공통 AI 작업 지침 한 벌 — Fable(기획·검토) / Opus(구현), 검토 증거 규칙, 커밋·발행 규칙 |
| [`PRD.md`](PRD.md) | Qaredi 사이트 — 이름·구조·런처·계정·기획 결정·로드맵(Q-n) |
| [`docs/tbox/`](docs/tbox/README.md) | t-box 모듈 — `README.md`(설치·운영) · `PRD.md` · `ARCHITECTURE.md` · `API.md` · `STAGES.md` · 단계별 지시서 |
| [`docs/community/`](docs/community/PRD.md) | 커뮤니티 모듈 — `PRD.md` v0.3 · `ARCHITECTURE.md` · `SECURITY.md` · `STAGES.md` · 노션 구조 분석과 결정(N-1~N-11) |
| [`SECURITY.md`](SECURITY.md) | 사이트 공통 보안 자체점검 — 잔여 위험 R-xx, 앱 밖 위험, 검토 이력(7장) |
| [`STAGES.md`](STAGES.md) | 사이트 공통 단계(Q-n)·학습 기록. 모듈 기록은 `docs/<모듈>/STAGES.md` |
| [`DESIGN.md`](DESIGN.md) | 화면 규칙(모듈 공통) — 토큰·구성 요소·배치·문구. 살아 있는 견본 `docs/design-guide.html` |
| [`CHANGELOG.md`](CHANGELOG.md) | 변경 이력(`CHANGELOG.json` 에서 생성) |

## 주소 (통합 뒤)

| 주소 | 무엇 |
|---|---|
| `/` | Qaredi 런처 |
| `/tbox/` | t-box (t-box 28단계 base-path) |
| `/community/` | 커뮤니티 |

설치·운영·시드 계정·백업·복구는 t-box 모듈 README(`docs/tbox/README.md`)에 있습니다. 스택은 순수 PHP 8.1 · PostgreSQL 14 · nginx 1.18 · Ubuntu 22.04 로 모듈 공통입니다.
