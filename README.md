# T-Box_Re — 사내 파일 공유 서비스 t-box 재구축

교육용으로 일부러 취약하게 만든 t-box(해킹 실습 대상)를, **실습에서 뚫린 5가지 취약점이 없는 형태**로 처음부터 다시 만드는 과제 저장소입니다.

| 항목 | 내용 |
|---|---|
| 스택 | 순수 PHP 8.1 (프레임워크 없음) · PostgreSQL 14 · nginx 1.18 · Ubuntu 22.04 — 실습 VM 설치본 그대로 (운영 전환 시 업그레이드, SECURITY R-21) |
| 진행 방식 | AI와 기능 하나씩 → **내가 직접 공격해 검증** → 한글 커밋 |
| 상태 | 설계 완료, 구현 전 (1단계 착수 예정) |

## 문서

| 문서 | 무엇이 들어 있나 | 언제 읽나 |
|---|---|---|
| [`PRD.md`](PRD.md) | 역할 4종 × 접근 레벨 4종 권한 매트릭스, 보안 요구사항 S-01~S-11, 수락 기준 T-1~T-23, **9단계 로드맵(13장)** | 기능·정책이 궁금할 때 |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | 요청 처리 순서, 업로드/목록/다운로드/삭제 흐름과 **권한 검사 위치**, 저장 위치, 감사 로그, nginx/php/DB 설정, 서버 기준선, 백업·복구·침해 대응 | 코드를 짜거나 서버를 세울 때 |
| [`SECURITY.md`](SECURITY.md) | **보안 자체점검 문서**. 5대 취약점 대조, 잔여 위험 R-xx, 앱 밖 위험, 단계별 공격 결과 이력(7장) | 매 단계 끝, 제출 전 |
| [`CLAUDE.md`](CLAUDE.md) | AI 작업 지침 — Fable(설계·검토) / Opus(구현) 역할, 검토 증거 규칙, 커밋 규칙 | AI 와 작업할 때 |

## 실습에서 뚫린 5가지 → 이번 설계의 방어 (요약)

| 공격 | 방어 | 검증 |
|---|---|---|
| 프론트 권한 우회 | 모든 요청을 서버 `Authz` 가 판단, 라우트에 권한 정책 필수, CSRF | T-1 |
| 권한 없는 데이터 노출 | SQL `WHERE` 에서 제외 — 응답에 아예 없음 | T-2, T-3 |
| XSS | 서버 이스케이프 + `textContent` + CSP + 다운로드 `attachment` | T-4 |
| SQL 인젝션 | Prepared Statement 만, 정렬 허용 목록, 오류 은닉 | T-5 |
| SSRF | "URL로 가져오기"는 `src/Fetch.php` 한 곳에서만: https·443 만, 도메인 화이트리스트, IP 검증 + 고정 접속, 리다이렉트 금지, 업로드와 동일 검증 | T-6 |

자세한 근거와 남은 위험은 `SECURITY.md` 를 보세요.

## 저장소 구조 (구현 후)

```
public/      nginx root — index.php(단일 입구), assets/
src/         Auth · Authz · Audit · Db · Storage · Upload · handlers/
db/          schema.sql · grants.sql · seed.sql
deploy/      nginx.conf · php-fpm-pool.conf · DEPENDENCIES.md
scripts/     security_lint.sh · backup_*.sh · consistency_check.php
.env.example 설정 키 예시 (실제 .env 는 커밋하지 않음)
```

## 설치 · 실행

> 1단계 구현 후 채움. (`ARCHITECTURE.md` 8·9·10장 설정을 그대로 사용)

## 백업 · 복구 절차

> 9단계에서 채움. 절차 초안은 `ARCHITECTURE.md` 11.1~11.2, `PRD.md` 7.8.

## 보안 자체점검

각 단계가 끝나면 `PRD.md` 13장의 "직접 해볼 공격"을 수행하고, 결과를 `SECURITY.md` 7장 검토 이력에 기록합니다.
정적 점검: `bash scripts/security_lint.sh`
