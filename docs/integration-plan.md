> **상태(2026-09-22)**: G 절 확인 4건 결정됨(사이트 `PRD.md` 8장 Q-D~Q-G — 지금 진행 · C-2 는 이 세션 · 308 리다이렉트(가정) · v2.0.0). **A 완료.** 다음 B → C.

# Q-1 통합 계획 — T-Box_Re 저장소가 Qaredi 가 된다 (v0.2, 2026-09-22)

> 전제(사용자 결정): 저장소 유지(Q-A) — 새 저장소 없음. `/var/www/tbox-v2`(= 원격 `T-Box_Re`)가 Qaredi 의 작업 트리가 되고, `/var/www/qaredi` 의 문서가 그쪽으로 들어간다. 기존 커뮤니티 코드(`/var/www/community`)는 쓰지 않는다.
> 현재 t-box 상태(2026-09-22 15:xx): v1.17.1, 다른 두 세션이 작업 중(「사이트 디자인」 D-10 모달 지시서 진행, `changkwon-ae` 유휴). 작업 트리에 미커밋 변경 5개 파일.

## 0. 원칙
1. **t-box 기능은 한 글자도 바뀌지 않는다.** 통합은 문서 위치·경로 접두·런처 추가·모듈 폴더 신설이다. 회귀 시험(`stage26.sh` 108/0 등)이 증거.
2. **한 번에 하나.** 아래 A→E 를 각각 커밋 하나로, 사이에 다른 세션의 작업이 끼어들 수 있게.
3. **다른 세션과 조율.** 문서 경로가 바뀌는 A 는 그 세션들이 읽는 파일을 옮기므로, 그들이 커밋을 마친 **정지 시점**에 하고 즉시 알린다.

## A. 문서 재배치 (위험 0, 코드 변경 0) — Fable
```
/var/www/tbox-v2/
  CLAUDE.md          ← qaredi/CLAUDE.md(공통) + 기존 t-box CLAUDE.md 의 모듈 고유 절(관문·일괄 모드·위키 규칙)을 「t-box 모듈 규칙」 장으로 합침
  PRD.md             ← qaredi/PRD.md (사이트 v1.0)
  STAGES.md          ← 사이트 공통 기록(qaredi/STAGES.md) + 「모듈 기록은 docs/<모듈>/STAGES.md」
  SECURITY.md · DESIGN.md · README.md · VERSION · CHANGELOG.*  ← 그대로(사이트 공통으로 승격), README 만 Qaredi 로 다시 씀
  docs/tbox/         ← git mv: PRD.md ARCHITECTURE.md SECURITY.md(→ 모듈 이력) STAGES.md API.md 와 docs/*directive*.md
  docs/community/    ← qaredi/docs/community/* (PRD v0.3 · ARCHITECTURE · notion 분석 · SECURITY · STAGES)
  docs/launcher/     ← (C 에서 채움)
```
- t-box 문서 안의 상호 참조(`ARCHITECTURE.md` → `docs/tbox/ARCHITECTURE.md`)는 **상대 경로만** 고친다. 내용 무변경. `wiki/*.json` 의 「원문 위치」 문자열도 같이(`scripts/wiki_check.php` 0건 확인).
- `SECURITY.md`(t-box 것)는 사이트 공통으로 남기고 R-xx 번호를 유지한다 — 커뮤니티 R 은 `C:R-xx` 접두로 합류.
- 커밋 「Qaredi A: 문서 재배치 — 공통 지침 한 벌, 모듈 문서 docs/<모듈>/」.

## B. 코드 모듈 구조 (t-box 는 자리 이동 없음)
- t-box 코드는 **지금 자리 그대로**(`src/*.php`·`src/handlers/*`·`public/assets/app.js`). 옮기면 diff 만 커지고 얻는 것이 없다. 문서상 「t-box 모듈 = 기존 레이아웃」으로 정의.
- 커뮤니티는 **새 폴더**: `src/community/`(핸들러·`Doc.php`·`DocRender.php`·Authz 확장 클래스 `CommunityAuthz`)·`public/assets/community/`(`editor.js`·`doc-render.js`·`community.css`)·`db/community/`(schema·grants·migrations)·`tests/community/`·`src/help/community.php`. 라우터 표에 「커뮤니티 구역」 — 정책 이름 `community.*`.
- 공통 코드(`Auth`·`Authz` 핵심·`Audit`·`Db`·`Http`·셸)는 그대로 공유. `Authz` 의 커뮤니티 판정은 별 파일에 두고 `Authz::authorize` 가 `community.*` 정책을 그 파일로 넘긴다(한 파일이 무한히 커지지 않게).
- `bootstrap.php` 의 `require` 는 모듈별 조건 로드(커뮤니티 라우트일 때만 `src/community/*` 로드).

## C. 런처와 경로 (t-box 28단계 = base-path) — 가장 큰 항목, t-box 세션과 함께
- `GET /` → 런처(정책 `public`, 데이터 0, 타일 = `config/apps.json`). 로그인 상태면 사용자 칩·로그아웃 표시(세션 읽기만).
- t-box 전체를 `/tbox/*` 아래로: 라우트 표 접두 · `app.js` 의 경로 상수 한 곳(`APP_BASE`) · 화면 링크 · 재인증 복귀 경로 · 공유 링크 `/s/{token}` → `/tbox/s/{token}` · `API.md`(프로그램용 창구 주소 변경 — 사용자 PC 프로그램·비서 설정도 바뀐다 ★) · nginx `location /tbox/` · 쿠키 `path=/`(한 앱이라 그대로). 이것이 t-box PRD 13장 **28단계**다.
- 커뮤니티는 처음부터 `/community/*`.
- 셸 머리에 그리드 단추(⋮⋮⋮) → `<details>` 팝업(같은 `apps.json`).
- 로그인 화면은 하나(`/login`), 로그인 뒤 `?to=` 는 허용 목록(`tbox`·`community`)으로만 복귀.

## D. DB·서버 정리
- 스키마 `community` 는 유지(1·2단계 표는 버리고 커뮤니티 3단계 스키마로 새로 만든다 — 기획이 바뀌었으므로 `DROP SCHEMA community CASCADE` 후 재생성, 데이터 없음 확인 뒤 **사용자 실행**).
- `tbox_app` 에 `community` 스키마 권한(grants 에 절 추가). `community_app`·`community_owner` 역할 · nginx `community-local`(8083) · php-fpm 풀 `community` · `/var/log/community` 제거 — `deploy/uninstall_community_app.sh`(사용자 실행).
- `tbox_backup` 의 `community` 스키마 읽기 권한은 그대로(백업 범위).

## E. 시험·발행
- `tests/` → `tests/tbox/`(기존 이동)·`tests/community/`. `scripts/security_lint.sh` 는 저장소 전체.
- 통합 뒤 회귀: `stage26.sh`·`help_button.sh`·`f5x.sh` 전부 + `/tbox/` 접두로 URL 바꾼 뒤 재실행.
- 발행: 런처가 뜨는 커밋을 **v2.0.0 「Qaredi」**(대규모 — 사용자가 말했을 때만 major 이므로 **확인 필요**) 또는 v1.18.0.

## F. 순서와 조율
| 순서 | 무엇 | 누가 | 조건 |
|---|---|---|---|
| 1 | 다른 세션 D-10 커밋 완료 확인 → **정지 신호** | Fable → 두 세션에 메시지 | 미커밋 0 |
| 2 | A 문서 재배치 커밋 | Fable | 즉시 알림(경로 바뀜) |
| 3 | C-1 런처(정적) + 셸 그리드 단추 | Opus(t-box 세션 또는 이 세션) | t-box 회귀 불변 |
| 4 | C-2 base-path `/tbox` (28단계) | t-box 담당 세션 권장(그쪽 문맥) | API.md·PC 프로그램 안내 |
| 5 | D 서버 정리 스크립트 → 사용자 실행 | Fable 작성 | |
| 6 | B 폴더 신설 + 커뮤니티 3단계 착수(블록 본문·에디터) | 이 세션 | 3단계 지시서 |

## G. 확인이 필요한 것
- 다른 세션에 정지 신호를 보내고 A 를 진행해도 되는지.
- C-2(t-box `/tbox` 이동)를 **어느 세션**이 할지 — t-box 문맥이 있는 세션이 낫다.
- 발행 번호: v2.0.0(Qaredi) 인지 1.x 계속인지.
- `API.md` 창구 주소 변경(사용자 PC 프로그램·AI 비서가 `/api/...` 를 부른다) — `/tbox/api/...` 로 바뀌면 그쪽 설정도 바꿔야 한다. 대안: `/api/*` 를 `/tbox/api/*` 로 **영구 리다이렉트**(308) 해 옛 주소를 살려 둔다.
