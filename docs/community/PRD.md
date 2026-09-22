> **2026-09-22 통합 결정(사용자)**: 커뮤니티는 별도 앱이 아니라 **Qaredi 한 앱 안의 모듈**이다(사이트 `PRD.md` 3장). 이 문서의 「별개 앱」·`/var/www/community`·8083·`community_app` 언급은 v0.1~v0.2 이력이며, 통합 계획(`docs/integration-plan.md`) B·D 단계에서 설계를 갱신할 때 고친다.

# 커뮤니티 PRD — 개인 업무 공간과 커뮤니티 (t-box 계정으로)

> **한 줄 요약**: 사내 구성원이 **t-box 계정 그대로** 로그인해, **노션처럼 블록으로 쓰는 개인 업무 공간**에서 업무 체크리스트·처리 절차·기록을 만들고,
> 그것을 **커뮤니티(게시판)에 게시**해 도움을 받거나 공유하며, 퇴사·이동 때 **업무 페이지와 이력을 통째로 인계**하는 **별도 앱**.
> "누가 무엇을 볼 수 있는지"는 t-box 와 **같은 표**(권한 매트릭스)로 **서버가 판단**하고, 볼 수 없는 것은 **응답에 아예 담지 않는다.**
> 본문은 **HTML 이 아니라 블록 JSON** 으로만 저장한다 — 꾸밈은 노션 수준으로 넣되 저장형 XSS 표면은 만들지 않는다.
> 입구는 **구글식 앱 런처**(루트 도메인)다 — t-box·커뮤니티를 타일로 골라 들어간다(F-36).

| 항목 | 내용 |
|---|---|
| 제품명 | 커뮤니티 (저장소 `/var/www/community`, t-box 와 **별개 앱**) |
| 문서 버전 | **v0.3 (기획 결정 ③~⑧ 확정, 2026-09-22)** — v0.2 제품 방향 · v0.1(2026-09-21) 1·2단계 게시판 |
| 작성일 | 2026-09-21 |
| 작성자 | changkwon.lee@metaht.kr (설계·문서 Fable) |
| 상태 | **커뮤니티 기획 확정**(③~⑧). 사이트 공통 질문(Q-A~Q-C, 루트 PRD 8장) 뒤 Qaredi 통합(Q-1) → 커뮤니티 3단계 |
| 관련 문서 | `ARCHITECTURE.md`(설계, 2부 v0.2) · `SECURITY.md` · `STAGES.md` · `CLAUDE.md` · `DESIGN.md` · **`docs/community/notion-structure-and-design.md`**(노션 구조 분석·판단 기준·결정 N-1~N-11·D-1~D-6 — 이 PRD 의 근거) |
| 원본(참조만) | t-box 저장소 `/var/www/tbox-v2` — PRD 3·4·5·7장(역할·등급·매트릭스·보안 요구사항), ARCHITECTURE 4장(공통 관문)·7장(감사 로그). **이 앱은 그 문서를 읽되 그 저장소를 고치지 않는다.** |

---

## 0. 먼저 알아야 할 용어

t-box PRD 0장과 같다(인증·인가·이스케이프·XSS·SQL 인젝션·CSRF·감사 로그·화이트리스트). 이 문서에서만 쓰는 말:

| 용어 | 뜻 |
|---|---|
| 게시판(board) | 글을 담는 칸. **파일·폴더와 같은 접근 등급**(public·internal·team·admin-only)을 갖는다 |
| 글(post) · 댓글(comment) | 게시판 안의 내용. **자기 등급이 없고 게시판 등급을 따른다** |
| 공지 게시판 | `notice=true` 인 게시판. 글은 Admin 만 쓰고, 읽기·댓글은 등급대로 |
| 계정 공유 | 커뮤니티가 **자기 계정 표를 갖지 않고** t-box 의 `users`·`teams`·`permissions` 를 읽어 로그인·권한을 판단하는 것 |
| 강제 절차 | t-box 의 최초 로그인 절차(임시 비밀번호 변경·Admin 2단계 인증 설정). 절차가 남은 계정은 커뮤니티를 쓸 수 없다 |
| 공간(space) | 페이지를 담는 그릇. **개인 공간**(사용자마다 1개) · **팀 공간**(t-box 팀마다) · **게시판**(공유). v0.1 의 게시판이 공간의 한 종류가 됐다 |
| 페이지(page) | 공간 안의 글 하나. 하위 페이지를 가질 수 있다(트리). 게시판의 글도 페이지다 |
| 블록(block) | 페이지 본문의 단위(문단·제목·목록·체크·표·코드·이미지…). 본문은 블록의 트리이고 **JSON 으로만 저장**된다 |
| 리치 텍스트 | 블록 안의 글. 세그먼트 배열 — 굵게·기울임·밑줄·취소선·코드·색·링크·멘션 |
| 공개 범위(visibility) | 개인 공간 페이지가 누구에게 보이나: 비공개·팀·사내·게시 |
| 게시(publish) | 개인 페이지 **원본**을 게시판에 걸어 게시판 목록에 보이게 하는 것. 복사가 아니다 |
| 인계(handover) | 페이지·공간의 **소유권**을 다른 사람에게 넘기는 것. 이력·작성자 이름은 그대로 남는다 |
| 앱 런처(launcher) | 루트 도메인의 첫 화면. 구글 앱 그리드처럼 **t-box·커뮤니티**(뒤에 다른 사내 도구)를 타일로 고른다. 각 앱 셸에도 같은 그리드 단추 |

---

## 1. 배경

**v0.2 방향 전환(2026-09-22, 사용자)** — 1·2단계로 게시판(평문 글·댓글)이 돌아간 뒤, 사용자가 방향을 넓혔다: ① 사용자마다 **노션처럼 블록으로 쓰는 업무 체크리스트·처리 페이지** ② 그것을 커뮤니티에 게시해 도움·공유 ③ 퇴사 시 업무 상세와 히스토리를 **신규 담당자에게 인계** ④ **꾸밈(색·크기·정렬)은 글쓰기에 꼭 필요하므로 넣는다** ⑤ 판단 기준은 「노션에 있나」가 아니라 **「우리가 쓰기에 더 좋은가」**이며 필요·불필요의 판단은 사용자가 한다(Fable 은 보조) ⑥ 입구는 **구글처럼 기능별로 골라 들어가는 앱 런처**. 노션의 실제 구조는 공개 API 문서와 사용자 워크스페이스 실측으로 분석해 `docs/community/notion-structure-and-design.md` 에 두었다. 강사의 「커뮤니티는 몇 주 걸린다」는 이 규모(9~12주)와 맞다.


- 강사 과제 2026-09-21: 「커뮤니티를 추가」. 사용자 결정(Q-1, 2026-09-21): ① t-box 와 **별개 앱** — 27단계 「t-box 는 t-box 만」 분리 지시와 같은 방향 ② 계정은 **t-box 것을 공유** ③ 골격은 **t-box 를 복사**(같은 5대 방어를 처음부터).
- 모양은 **게시판**이다. 게시판은 t-box 실습에서 뚫린 5가지가 전부 자연스럽게 나오는 표본이라 과제 취지에 맞다.

| 실습에서 뚫린 것 | 게시판에서 나오는 자리 | 이번 방어 |
|---|---|---|
| 프론트 권한 우회 | 남의 글 「수정」「삭제」 단추만 숨기고 API 는 열림 | 라우트 정책 + 행 단위 `Authz` 판정 (S-01) |
| 권한 없는 데이터 노출 | 팀 게시판·관리자 게시판을 내려주고 화면에서만 숨김 | SQL `WHERE` 에서 제외 (S-02) |
| XSS | 글 본문·댓글을 innerHTML 로 출력, 마크다운 에디터 | 평문만 + `textContent` + CSP (S-03) |
| SQL 인젝션 | 글 검색어·정렬 파라미터 | 바인딩 + 허용 목록 (S-04) |
| CSRF | 댓글 작성·글 삭제 POST | 세션 + `X-CSRF-Token` (S-06) |

---

## 2. 목표와 비목표

### 2.1 목표

| ID | 목표 | 성공 판단 기준 |
|---|---|---|
| G-1 | t-box 계정·역할·권한 표 **그대로** | 4장 표대로 100% 동작. 커뮤니티에 계정 표·역할 표가 **없다** |
| G-2 | 5대 공격 재현 불가 | 10장 수락 기준 전부 통과 |
| G-3 | 사고 추적 가능 | 글·댓글·게시판의 허용·거부 전부 감사 로그(자체 표 + 파일) |
| G-4 | **t-box 에 영향 0** | t-box 코드·표·동작을 한 글자도 바꾸지 않는다. DB 에는 스키마 `community` 와 역할 둘만 더한다. t-box 회귀 시험(`stage26.sh`·`help_button.sh`) 결과 불변 |
| G-5 | 초보 개발자가 유지보수 가능 | t-box 와 같은 구조·같은 이름·같은 규칙(프레임워크 없음) |

### 2.2 비목표 (Non-Goals) — 만들지 않거나 보류 (v0.2)

| ID | 비목표 | 이유 · 대신 |
|---|---|---|
| NG-1 | 브라우저 HTML 을 그대로 저장하는 에디터 | 저장형 XSS 의 정문. **블록 JSON**(F-15)으로 같은 꾸밈을 낸다 |
| NG-2 | 임의 hex 색 직접 입력 | 다크 모드·일관성. **관리자 등록 팔레트**(F-33)로 브랜드 색을 받는다 |
| NG-3 | 실시간 공동 편집(CRDT) | 가장 큰 기술 항목. 낙관적 잠금 + 「편집 중」 표시(F-16)로 시작, 수요 확인 뒤 재검토 |
| NG-4 | 계정 관리(생성·비밀번호·2단계·역할) | **t-box 에서 한다** |
| NG-5 | 프로그램 토큰 API | 세션 + CSRF 만 |
| NG-6 | 사내망 밖 공개 | t-box 와 같은 프록시 뒤 |
| NG-7 | 자유 수식 언어·동기화 블록·AI 요약/전사 | 파서·평가기·외부 호출. 고정 계산 4종(F-27)으로 대신 |
| NG-8 | 페이지별 개인 초대(노션식 공유) | 판정이 두 벌이 된다. **조직(t-box 팀) 기반 공개 범위**(F-18) |
| NG-9 | 외부 URL 이미지 직접 삽입 | 브라우저가 외부를 부른다. 붙여넣은 이미지는 **t-box 로 자동 업로드**(F-25) |

---

## 3. 계정과 역할 — t-box 와 같다

- 계정은 t-box `users` 표 그대로(역할 4종 Admin·Manager·Employee·Guest, 팀 `teams`, 활성·만료). 커뮤니티는 이 표를 **읽기만** 한다 — 계정을 만들거나 고치는 화면·API 가 없다(NG-4).
- 로그인 규칙도 같다: Argon2id 비밀번호 확인 → 2단계 인증(TOTP, 켠 계정만) → 세션(DB, 무작위 토큰, HttpOnly 쿠키 `comm_session`). 실패 5회 10분 잠금.
- **잠금 카운터(`failed_count`·`locked_until`)와 TOTP 스텝(`mfa_last_step`)은 두 앱이 공유한다** — t-box `users` 의 그 3열만 커뮤니티가 갱신한다. 공유하지 않으면 커뮤니티가 t-box 비밀번호 무차별 대입과 코드 재사용의 **우회 입구**가 된다(S-08, SECURITY R-01).
- **강제 절차가 남은 계정**(`must_change_password` 또는 Admin 인데 `mfa_enabled=false`)은 커뮤니티에 로그인은 되지만 **모든 화면·API 가 막히고** 「t-box 에서 먼저 마치세요」 안내와 t-box 링크만 보인다(F-02). 커뮤니티에는 그 절차를 밟을 화면이 없기 때문이다.
- 「역할로 보기」(t-box F-39)·프로그램 토큰(F-49~F-59)은 커뮤니티에 **없다**.

---

## 4. 권한 표 ★ 이 표가 정답지다

게시판은 **t-box 파일·폴더와 같은 등급 체계**(`access_level` 4종 + `team_id`)를 갖고, 글과 댓글은 **게시판의 등급을 따른다**. 읽기 판정은 t-box PRD 5.1 표·`permissions` 16행 **그대로**(`Authz::decideRead(user, board)`)이고, 못 읽는 게시판·글은 **응답에 없다**(S-02).

| 행위 | Admin | Manager | Employee | Guest |
|---|---|---|---|---|
| 게시판 목록 · 글 읽기 · 검색 | 매트릭스대로 (모든 팀) | 매트릭스대로 (자기 팀만) | 매트릭스대로 (자기 팀만) | public 게시판만 |
| 글 쓰기 — 일반 게시판 | ✅ 읽을 수 있는 게시판 | ✅ 읽을 수 있는 게시판 | ✅ 읽을 수 있는 게시판 | ❌ |
| 글 쓰기 — 공지 게시판 (`notice=true`) | ✅ | ❌ | ❌ | ❌ |
| 댓글 쓰기 | ✅ 읽을 수 있는 글 | ✅ 읽을 수 있는 글 | ✅ 읽을 수 있는 글 | ❌ |
| 글 수정·삭제 · 댓글 삭제 | 모두 (`admin_all`) | 본인 것(`owner`) + **자기 팀 게시판**의 것(`team_scope`) | 본인 것만(`owner`) | ❌ |
| 게시판 만들기 · 이름·설명·공지 여부·활성 (`board.manage`) | ✅ (**재인증**) | ❌ | ❌ | ❌ |

> - 쓰기 자격(Guest 제외)은 t-box 파일 업로드 자격과 **같은 표**(매트릭스의 `can_upload` 가 하나라도 참 — `Authz::canWrite`). 매트릭스가 바뀌면(t-box F-25) 커뮤니티도 그대로 따라간다.
> - 수정·삭제 규칙과 근거 코드는 t-box 파일 5.3 과 **같은 함수**(`Authz::decideOwnership`)다 — 게시판이 `team` 등급일 때만 Manager 의 `team_scope` 가 성립하고, public·internal 게시판의 남의 글은 `not_owner` 다.
> - 게시판의 **등급·팀은 만든 뒤 바꾸지 않는다**(Q-1 ③) — 바꾸면 이미 쓴 글의 노출 범위가 글쓴이 모르게 바뀐다. 필요하면 새 게시판을 만들고 옛것을 비활성(읽기 전용 보관)한다.
> - ❌ 는 "화면에서 숨김"이 아니라 **목록 응답에 존재하지 않음**, 쓰기·수정·삭제 시 **403 동일 문구**를 뜻한다(t-box Q-6 과 같은 결정).

### 4.1 개인 공간 페이지의 공개 범위 (F-18)

| 범위 | 누가 보나 | 누가 고치나 | 게시판 목록 |
|---|---|---|---|
| 비공개 `private` | 소유자 + **Admin**(회사 자료라 관리자는 볼 수 있다 — 사용자 결정 2026-09-22. Admin 열람은 감사 `page.read allow admin_all` 로 남는다). Manager 는 못 본다 | 소유자 | 없음 |
| 팀 `team` | 소유자의 t-box 팀 | 소유자 | 없음 |
| 사내 `internal` | 로그인한 전원(Guest 제외) | 소유자 | 없음 |
| 게시 `published` | 고른 게시판의 등급대로(4장 표) | 소유자 (+ 게시판 규칙의 Manager·Admin 은 **게시 해제·숨김**만) | 그 게시판에 보임 |

**트리 불변 규칙**: 하위 페이지의 범위는 부모보다 **넓을 수 없다**(private < team < internal < published). 부모를 좁히면 하위도 함께 좁힌다(cascade, 항목마다 감사). 팀 공간·게시판의 페이지는 공간 등급을 따른다(범위 없음).

**인계(F-28)**: 소유자 → 팀원(본인이) · Manager → 자기 팀원 것을 팀원에게(내용 비열람) · Admin → 누구에게나(재인증, 내용 열람 가능). 인계 = **페이지 주인만 바뀐다**. 작성자 이름·이력·체크 기록·댓글은 그대로. 예: 김대리(퇴사) → 박사원 — 박사원이 6개월 체크 기록까지 통째로 받는다.

---

## 5. 기능 요구사항

`필수` = 1·2단계 / `선택` = 그 뒤

| ID | 기능 | 설명 | 우선도 |
|---|---|---|---|
| F-01 | 로그인·로그아웃 | t-box 계정(3장). `POST /api/login {username,password,otp?}` → 순차 2단계(코드 없으면 401 `mfa_required`) → 세션. 잠금·TOTP 스텝은 t-box 와 공유. 로그아웃은 커뮤니티 세션만 지운다(t-box 세션은 그대로 — 별개 앱) | 필수(1) |
| F-02 | 강제 절차 관문 | `must_change_password` 또는 (Admin 이고 `mfa_enabled=false`) 인 세션은 `GET /`·`GET /api/me`·`POST /api/logout` 외 전부 **403 `{next:"tbox"}`**(화면 요청은 302 `/`). 홈은 안내 문구 + t-box 링크(`.env` `TBOX_URL`)만 그린다. 감사 `auth.gate deny tbox_required` | 필수(1) |
| F-03 | 게시판 목록 | 볼 수 있는 게시판만 — SQL `WHERE`(`Authz::visibleWhere(u,'b')`). 항목: 이름·설명·등급·팀 이름·공지 여부·활성·글 수·`can_post`. 비활성 게시판은 `is_active:false` 로 보이고 읽기 전용 | 필수(2) |
| F-04 | 글 목록·검색 | `GET /api/boards/{num}/posts?q&sort&page`. 게시판 행 `decideRead` 실패·없는 게시판은 **403 동일 문구**(id 열거 방지, t-box 폴더와 같은 취급). `q`(≤200자)는 제목·본문 `ILIKE` **바인딩**(`#` 이스케이프), `sort` 는 허용 목록 `new`·`old`, 20건 페이지. 항목: id(UUID)·제목·글쓴이·시각·댓글 수·`can_edit` | 필수(2) |
| F-05 | 글 쓰기 | `POST /api/boards/{num}/posts {title, body}` — 허용 키 둘만. 자격은 `Authz::decidePostCreate(u, board)`: 읽을 수 있는 게시판 + 쓰기 자격(4장) + 공지면 Admin. 비활성 게시판 400 `board_inactive`. **본문 규칙(S-03)**: 평문만, 제목 1~100자·본문 1~10,000자(UTF-8 글자 수), 제어문자는 줄바꿈(`\n`)만 허용(`\r` 은 제거), 앞뒤 공백 제거 뒤 비면 400 | 필수(2) |
| F-06 | 글 읽기 + 댓글 | `GET /api/posts/{id}` — 글(게시판 이름·등급 포함)과 살아 있는 댓글 목록. 지워진 글·없는 글은 **404 동일 문구**. 화면은 본문·댓글을 `textContent` + `white-space: pre-wrap` 으로만 그린다. 링크 자동 변환 없음 | 필수(2) |
| F-07 | 글 수정·삭제 | `PATCH /api/posts/{id} {title?, body?}`(1개 이상) · `DELETE /api/posts/{id}`. 자격은 `decideRead(board)` → `Authz::decidePostChange(u, post)`(4장 소유 규칙). 삭제는 **논리 삭제**(`deleted_at`·`deleted_by`) — 복구 화면 없음, 감사 행이 이력. 지워진 글의 댓글은 함께 보이지 않는다 | 필수(2) |
| F-08 | 댓글 쓰기·삭제 | `POST /api/posts/{id}/comments {body}`(1~2,000자, F-05 와 같은 평문 규칙) · `DELETE /api/comments/{id}`. 자격: 쓰기는 `Authz::decideCommentCreate(u, board)`(읽기 + 쓰기 자격, 공지 게시판도 댓글은 허용), 삭제는 `decidePostChange`(댓글 작성자 기준). 지워진 글 404·비활성 게시판 400 | 필수(2) |
| F-09 | 게시판 관리 | Admin 만, 라우트 정책 `board.manage`(`REAUTH_POLICIES` 에 넣어 **재인증 자동**, t-box F-23 과 같은 15분 창 · `POST /api/me/reauth`). 만들기 `{name(1~50, 대소문자 무시 중복 불가), description?(≤200), access_level, team_id?(team 이면 필수·활성 팀), notice?, reason_text(필수 ≤500)}`. 고치기 `{name?, description?, notice?, is_active?, reason_text}` — **`access_level`·`team_id` 는 허용 키 밖(400)**. 시드 게시판 둘: 「공지사항」(public·notice) · 「자유게시판」(internal) | 필수(2) |
| F-10 | 감사 로그 | t-box 와 같은 열 구조의 **자체 표** `community.audit_logs`(append-only) + 파일 `/var/log/community/audit.log`. 문장 표(ARCH 8.2)에 없는 조합은 기록 실패 = 요청 실패. 조회 화면은 1차에 없다(`psql`·`tail` 로 본다, Q-2) | 필수(1·2) |
| F-11 | 공통 셸·도움말·다크 모드 | t-box `DESIGN.md` 규칙 그대로(같은 `app.css`). 내비: 「게시판」 · (Admin) 「게시판 관리」 단추 · 「t-box 로 가기」 링크(`TBOX_URL`). 「사용 방법」 도움말 한 벌(`src/help/screens.php`), 해/달 단추(쿠키 `tbox_theme` 공유) | 필수(2) |
| F-12 | 상태 확인 | `GET /api/health` — DB `SELECT 1` 확인, 200 `{status,db,time}` / 503. 버전·경로 비노출 | 필수(1) |
| F-13 | 내 활동 | 본인 감사 행 보기 | 선택(Q-2) |
| F-14 | (→ F-24 로 대체) | | |
| **F-15** | **블록 본문(문서 JSON)** | 페이지 본문은 `docs/doc-schema.json` 의 허용 목록을 통과한 **블록 트리 JSON** 만 저장(HTML 0). 블록: 3단계 `paragraph`·`heading(1~3)`·`bulleted`·`numbered`·`to_do`·`quote`·`callout`·`divider`·`code`·`toc` → 4단계 `toggle`·`table`·`child_page` → 5단계 `image`·`file`·`column`·`link_to_page` → 7단계 `embed`(허용 도메인). 블록 속성 `color`(팔레트 이름)·**`align`**·**`size` 4단계**·`icon`·`language`. 리치 텍스트 세그먼트 `marks`: `bold`·`italic`·`underline`·`strike`·`code`·`color`(글자 9·배경 9 + 관리자 팔레트)·`link`·`mention`. 상한: 블록 ≤ 3,000 · 깊이 ≤ 10 · 세그먼트 ≤ 2,000자 · 문서 ≤ 1MB. 링크는 `https?`·`mailto`·`page:UUID` 만. **모르는 키·타입·값은 400** (S-11). 기존 평문 글은 문단 블록 하나로 이관(되돌림 스크립트 동봉) | 필수(3) |
| **F-16** | **블록 에디터** | ProseMirror 엔진(D-1, 의존성 0 원칙의 첫 예외 — S-12) 위에 노션식 UX: `/` 슬래시 메뉴(삽입·전환) · 블록 손잡이(드래그·메뉴) · 마크다운 단축키 · 선택 시 인라인 툴바(서식·색·링크·댓글) · `@` 멘션 · Ctrl+Z · 붙여넣기 HTML → 스키마 변환(허용 밖은 평문화). 자동 저장(입력 멎은 뒤 1.5초) + **낙관적 잠금**(`doc_version`, 어긋나면 409 + 안내). 편집 중 표시(30초 heartbeat) | 필수(3) |
| **F-17** | **개인 공간·페이지 트리** | 사용자마다 개인 공간 1개(첫 로그인 때 자동). 페이지는 하위 페이지를 갖는다(깊이 ≤ 8). 아이콘·표지(t-box 이미지)·글꼴 3종·작은 글씨·전체 너비. 이동·순서. 팀 공간은 t-box 활성 팀마다 자동. 게시판은 v0.1 그대로(공간 종류 `board`) | 필수(4) |
| **F-18** | **공개 범위와 게시** | 4.1 표. 게시는 원본을 게시판에 걸기 — 게시·해제 시점이 이력에 남고, 게시판 목록·검색에 보인다. 게시판의 Manager(자기 팀)·Admin 은 게시 해제·숨김 가능(내용 편집 불가) | 필수(4) |
| **F-19** | **버전 이력** | 저장마다 스냅샷(`page_versions`, 5분 안 연속 저장은 합침). 목록 · 버전 보기 · **되돌리기 = 새 버전으로 복사**. append-only(S-14) | 필수(4) |
| **F-20** | **체크리스트 이력** | `to_do` 체크·해제마다 **누가·언제**(`checklist_events`, append-only). 「처리 이력」 탭에 시간순. 노션에는 없는 것 | 필수(4) |
| **F-21** | **댓글 2.0** | 페이지 댓글 + **블록 앵커 댓글**(블록 id + 선택 문구 인용, N-9) · 답글 1단 · 해결됨 · 「도움됐어요」 · Admin 「숨김」(보존·감사) | 필수(5) |
| **F-22** | **멘션·알림** | `@사용자`(후보 = **그 페이지를 볼 수 있는 사람 전체**, Guest 제외 — 사용자 결정 ⑥) · `@페이지` · `@날짜`. 멘션·답글·내 페이지 댓글·인계 → 알림(종 아이콘, 30초 폴링). 메일·메신저는 연동 지점만 | 필수(5) |
| **F-23** | **링크 카드·역참조** | `link_to_page` 카드. 저장 시 `page_links` → 대상 페이지 하단 「이 페이지를 참조한 곳」(볼 수 있는 것만) | 필수(5) |
| **F-24** | **첨부(t-box 파일)** | `image`·`file` 은 **t-box 파일 참조**(`tbox:UUID`)만. **첨부 파일 등급 ≤ 페이지 공간 등급**(아니면 400). 다운로드·미리보기는 t-box 가 자기 권한으로. 같은 도메인(F-36) 전제 | 필수(5) |
| **F-25** | **이미지 자동 업로드** | 붙여넣기·끌어 놓기 이미지를 **t-box 에 자동 업로드**(사용자 세션, 페이지 범위 등급) 뒤 `image` 블록으로. 외부 URL 직접 삽입 없음 | 필수(5) |
| **F-26** | **태그·조회수·검색** | 태그, 조회수(세션당 1일 1회), 전체 검색(제목·본문·댓글 — `pg_trgm` + tsvector, `visibleWhere` 안) | 필수(3) |
| **F-27** | **데이터베이스** | 속성 12종 · 행 = 페이지 · 뷰 표/보드/리스트 · 필터·정렬(허용 목록 → SQL) · 템플릿 · **고정 계산 4종**(합계·개수·진행률·D-day) · **표 셀 병합** | 필수(6) |
| **F-28** | **인계** | 4.1. 본인(→같은 팀) · Manager(→자기 팀원 사이, 내용 비열람) · Admin(→누구에게나, 재인증). `handovers` 기록 + 양쪽 알림. t-box 비활성 계정의 개인 공간 = Admin 「인계 대기」 목록, 90일 경고만(자동 삭제 없음) | 필수(7) |
| **F-29** | **휴지통** | 30일 보관, 소유자·Admin 복구, 하위 함께, 배치 정리 | 필수(7) |
| **F-30** | **공지 읽음 확인** | 공지 게시판만: `page_reads` → 작성자·Admin 에게 「읽은 사람 n/N」 | 필수(5) |
| **F-31** | **템플릿** | 갤러리 + **부서 기본 양식**(인수인계서·업무 체크리스트) | 필수(7) |
| **F-32** | **외부 임베드(허용 목록)** | 관리자 등록 도메인만, CSP `frame-src` 도 그 목록으로만 | 필수(7) |
| **F-33** | **팔레트 확장** | 관리자가 회사 색을 이름·라이트·다크 값으로 등록(≤ 12). 저장은 이름 | 필수(3 저장·표시 / 7 관리 화면) |
| **F-34** | **홈 대시보드** | 내 알림 · 최근 페이지 · 내 체크 미완료 · 미해결 질문 · 부서 공지 읽음 현황 | 선택(7) |
| **F-35** | **게시판 종류** | 토론 · 질문답변(해결됨) · 사례·기록(상태 라벨·담당자) · 문서(트리) · 공지(읽음 확인). 켜지는 기능의 묶음 | 필수(5) |
| **F-37** | **상태·담당자 칸** (사용자 결정 ⑤ 「상태·담당자 먼저」) | 모든 페이지에 붙일 수 있는 고정 속성 2개: **상태**(할 일·진행·검토·완료·보류 — 사이트 공통 5값, 색 고정) · **담당자**(t-box 사용자 1명, 페이지 열람자 중에서). 페이지 머리에 표시, 목록에 배지, 「내 공간」에 「내가 담당인 페이지」 묶음. 변경마다 이력(누가·언제·이전→새값, `page_property_events` append-only). 데이터베이스(F-27)가 들어오면 이 두 칸은 그 속성 체계 안으로 흡수된다(값 이관) | 필수(4) |
| **F-36** | **앱 런처 (루트 도메인)** — 사용자 요청 2026-09-22 「구글처럼 기능별로 선택하여 접근」 | `https://<도메인>/` = **런처**: 로그인 없이 보이는 정적 화면(HTML·CSS 만, 스크립트 0). 타일: **t-box**(`/tbox/`) · **커뮤니티**(`/community/`) · 뒤에 사내 도구 추가. 타일마다 이름·한 줄 설명·아이콘(인라인 SVG). 각 앱 셸 머리에 **같은 그리드 단추(⋮⋮⋮)** → 앱 전환 팝업(런처와 같은 목록, 정적). 로그인은 앱마다(세션 분리 유지) — 계정이 같으니 아이디·비밀번호는 하나. 런처 목록은 `deploy/launcher/apps.json` 한 파일에서 nginx 가 정적으로 서빙하는 HTML 을 **빌드 스크립트**가 만든다(요청 값 0). 공개 주소는 이로써 **루트 = 런처, `/tbox` = t-box(28단계 base-path), `/community` = 커뮤니티** 로 정해진다(Q-3 확정안) | 필수(3) |

---

## 6. 보안 요구사항 ★

t-box PRD 7장의 S-01~S-04·S-07·S-09 를 **그대로 상속**한다(같은 코드·같은 린트). 이 앱에서 달라지거나 더해지는 것만 적는다.

| ID | 요구사항 | 이 앱에서의 뜻 |
|---|---|---|
| S-01 | 모든 요청을 서버가 판단 | 라우트 표에 정책 필수(`Authz::KNOWN_POLICIES` 밖이면 부팅 실패). 정책은 `public`·`authenticated`·`post.write`·`board.manage` 넷. 행 단위 판정은 핸들러의 `Authz::decide*` 만. 역할 문자열 비교는 `Authz.php` 한 파일에서만(린트) |
| S-02 | 못 보는 것은 응답에 없다 | 게시판 목록은 `visibleWhere` 로 SQL 에서 제외. 글·댓글은 게시판 행을 먼저 읽어 `decideRead`. 없는 게시판 = 못 보는 게시판(403 동일 문구) |
| S-03 | 출력 안전 | **본문은 평문만**(에디터 없음, NG-1). 서버 HTML 에 사용자 내용이 들어가는 자리 0 — 글·댓글은 전부 JSON → `textContent`. CSP `default-src 'self'; script-src 'self'`(nginx + PHP 헤더 이중). 제어문자는 `\n` 만 |
| S-04 | SQL 인젝션 | Prepared Statement 만(`Db::run`), `ILIKE` 는 `ESCAPE '#'` + 바인딩, `ORDER BY` 는 허용 목록 → 고정 SQL 조각, 페이지·정렬은 거부하지 않고 안전한 값으로 좁힘(`Http::query*`) |
| S-05 | 외부 요청 0 | 이 앱에는 URL 가져오기가 없다. `curl_*`·소켓·`file_get_contents('http…')` 가 한 줄도 없어야 한다(린트 — t-box 의 「Fetch.php 만 허용」 예외조차 없다) |
| S-06 | 세션·CSRF | DB 세션(`community.sessions`, 토큰 sha256 만 저장), 쿠키 `comm_session`(HttpOnly·Secure·SameSite=Lax) — **t-box 쿠키 `tbox_session` 과 이름이 다르다**(같은 도메인에 놓여도 섞이지 않는다). 상태 변경 요청은 `X-CSRF-Token`(HMAC, `APP_SECRET` 은 t-box 와 **다른 값**) |
| S-07 | 감사 로그 | 자체 표 `community.audit_logs`(앱 계정 SELECT·INSERT 만) + 파일. 문장 표·detail 등록부·금지 키(비밀번호·토큰·코드)는 t-box `Audit.php` 규칙 그대로 |
| S-08 | **t-box 표에 대한 최소 권한** | 역할 `community_app` 은 t-box 표 중 `users`·`teams`·`permissions` 에 **SELECT** 와 `users` 의 **3열(`failed_count`·`locked_until`·`mfa_last_step`) UPDATE** 만. `files`·`folders`·`sessions`(t-box)·`audit_logs`(t-box)·`api_tokens`·`share_links`·`fetch_hosts` 는 권한 0 — 커뮤니티 코드에 결함이 있어도 파일·t-box 세션·t-box 감사 로그에 닿지 못한다. `permissions` 는 SELECT 만(권한 상승 경로 없음). DDL 은 `community_owner` 만 — 이 역할은 FK 를 걸기 위해 `public.users(id)`·`public.teams(id)` 의 **REFERENCES** 열 권한을 갖는다(값 읽기·쓰기 불가, 2026-09-22 추가). 스키마 `community` 의 CREATE 는 PUBLIC 회수 |
| S-09 | 비밀 미기록 | 비밀번호·OTP·세션 토큰·`mfa_secret` 은 응답·감사·`app.log` 어디에도 없다. `mfa_secret` 은 로그인 검증 순간 메모리에서만 |
| S-10 | 앱 밖 | php-fpm 풀 `community`(계정 `community-php`, `open_basedir`·`disable_functions`·`allow_url_fopen=Off` t-box 와 동일), nginx 는 `public/` 만 노출, `.env` 는 저장소 루트 0640. 로그 `/var/log/community/`(audit.log `chattr +a`) |
| **S-11** | **문서 스키마 검증** | 저장되는 본문은 `src/Doc.php` 가 `docs/doc-schema.json` 허용 목록으로 검증한 블록 JSON 만. 모르는 타입·키·값·`javascript:`·`data:` 링크·깊이·크기 초과는 **400**. 렌더러(서버·클라이언트)는 텍스트를 `textContent`/`htmlspecialchars` 로만, 속성은 허용 목록 → CSS 클래스로만. 스키마 파일 하나가 검증기·에디터·렌더러의 공통 진실 |
| **S-12** | **공급망 — 에디터 엔진** | ProseMirror(MIT)는 이 앱의 **유일한 외부 코드**. 고정 버전·sha256(`deploy/DEPENDENCIES.md`), 번들은 `public/assets/vendor/` 에 저장소 안 고정(CDN 없음, CSP `script-src 'self'`). 서버 실행 0. 분기 재검토 |
| **S-13** | **트리·범위 불변** | 하위 페이지 범위 ≤ 부모, 첨부 파일 등급 ≤ 공간 등급 — **쓰기 시점 불변 규칙**. `Authz::decidePageRead` 한 함수 |
| **S-14** | **기록 불변** | `page_versions`·`checklist_events`·`handovers`·`page_reads` 는 앱 계정에 UPDATE·DELETE 없음 |
| **S-15** | **런처는 정적** | 루트 런처·앱 전환 팝업은 스크립트 0·요청 값 0 의 정적 HTML. 앱 목록은 저장소 파일에서 빌드. 어느 앱의 세션도 필요하지 않고, 어느 앱의 세션도 새지 않는다 |

---

## 7. API 명세 요약

모든 응답은 JSON. `authenticated` 이상은 세션 필수, 상태 변경은 CSRF 필수. 프로그램 토큰 없음.

| 메서드 | 경로 | 기능 | 정책 → 핸들러 판정 | 실패 |
|---|---|---|---|---|
| GET | `/login` | 로그인 화면(HTML) | `public` | — |
| POST | `/api/login` | 로그인 `{username,password,otp?}` (F-01) | `public` (nginx IP 속도 제한 + 계정 5회 잠금 **t-box 와 공유**) | 400 / 401 / 423 / 429 |
| POST | `/api/logout` | 로그아웃(커뮤니티 세션만) | `authenticated` + CSRF | 401 / 403 |
| GET | `/api/me` | `{username, role, team_id, team_name, csrf_token, can_write, can_manage_boards, next, reauth_fresh, tbox_url}` | `authenticated` | 401 |
| GET | `/api/health` | 살아 있나 + DB (F-12) | `public` | 503 |
| POST | `/api/me/reauth` | 관리 기능 재인증 `{password}` → `{ok, reauth_until}` (F-09) | `authenticated` + CSRF. 실패 5회 → 잠금(t-box 와 공유) | 400 / 401 / 423 |
| GET | `/` | 커뮤니티 화면(HTML) — 게시판 목록 · 글 목록·검색 · 글 한 편 + 댓글이 **한 화면**. 상태는 쿼리 `?board=<num>&post=<uuid>`(JS 가 형식 검사 뒤에만 쓴다). 관문(F-02) 중이면 안내만 | `authenticated` | — |
| GET | `/api/boards` | 볼 수 있는 게시판 목록 (F-03) | `authenticated` → SQL WHERE | 401 |
| GET | `/api/teams` | 게시판 관리 폼의 팀 선택지 — **활성 팀의 `id`·`name` 만** `{teams:[{id,name}]}`. t-box 의 같은 창구와 같은 노출 범위(2026-09-22 추가, ARCH 7.8) | `authenticated` | 401 |
| POST | `/api/boards` | 게시판 만들기 (F-09) → 201 `{id}` | `board.manage` + 재인증 + CSRF | 400 / 403 / 409 |
| PATCH | `/api/boards/{num}` | 이름·설명·공지·활성 (F-09) → 200 `{ok:true}` | `board.manage` + 재인증 + CSRF | 400 / 403 / 404 / 409 |
| GET | `/api/boards/{num}/posts` | 글 목록·검색 `?q&sort=new\|old&page` (F-04) → `{board:{id,name,access_level,notice,is_active,can_post}, posts:[…], total, page, per_page}` | `authenticated` → `decideRead(board)` | 403 |
| POST | `/api/boards/{num}/posts` | 글 쓰기 (F-05) → 201 `{id}` | `post.write` → `decidePostCreate` | 400 / 403 |
| GET | `/api/posts/{id}` | 글 + 댓글 (F-06) → `{post:{id,board:{id,name,access_level},title,body,author,created_at,updated_at,can_edit}, comments:[{id,author,body,created_at,can_delete}]}` | `authenticated` → `decideRead(board)` | 403 / 404 |
| PATCH | `/api/posts/{id}` | 글 고치기 (F-07) → 200 | `post.write` → `decideRead` → `decidePostChange` | 400 / 403 / 404 |
| DELETE | `/api/posts/{id}` | 글 삭제(논리) (F-07) → 200 | `post.write` → `decideRead` → `decidePostChange` | 403 / 404 |
| POST | `/api/posts/{id}/comments` | 댓글 쓰기 (F-08) → 201 `{id}` | `post.write` → `decideCommentCreate` | 400 / 403 / 404 |
| DELETE | `/api/comments/{id}` | 댓글 삭제(논리) (F-08) → 200 | `post.write` → `decideRead` → `decidePostChange` | 403 / 404 |

경로 자리표시자: `{id}` = UUID 36자, `{num}` = 정수 1~9자리. 실패 문구는 t-box 와 같은 상수(`권한이 없습니다`·`찾을 수 없습니다`·`잘못된 요청입니다`·`이미 있는 이름입니다`). 사유는 감사 로그에만.

---

## 8. 데이터 모델 요약 — 스키마 `community`

| 표 | 주요 컬럼 | 메모 |
|---|---|---|
| (t-box) `public.users` · `public.teams` · `public.permissions` | t-box 것 그대로 | **읽기만**(+ `users` 3열 UPDATE). 커뮤니티가 만들지도, 바꾸지도 않는다 |
| `community.sessions` | `id(토큰 sha256), user_id(→public.users), created_at, last_seen_at, expires_at, ip, user_agent, reauth_at` | t-box `sessions` 에서 `act_*` 네 열을 뺀 것. 앱 계정 SIUD |
| `community.audit_logs` | t-box `audit_logs` 와 같은 15열 | Append-only(앱 계정 SELECT·INSERT) |
| `community.boards` | `id, name, description, access_level, team_id(→public.teams), notice, is_active, created_by(→public.users, NULL=시드), created_at, updated_at` | 파일·폴더와 같은 등급 CHECK(team 이면 team_id NOT NULL). `UNIQUE (lower(name))`. 앱 계정 SIU(**DELETE 없음**) |
| `community.posts` | `id, public_id(UUID), board_id, author_id(→public.users), title, body, created_at, updated_at, deleted_at, deleted_by` | 등급 열 **없음**. `length(title) 1~100`·`length(body) 1~10000` CHECK. 논리 삭제, 앱 계정 SIU |
| `community.comments` | `id, public_id(UUID), post_id, author_id, body, created_at, deleted_at, deleted_by` | `length(body) 1~2000` CHECK. 논리 삭제, 앱 계정 SIU |

제약: `access_level` 4종 CHECK · `team` 이면 `team_id NOT NULL` · 외부 키는 t-box 표를 가리키되 **`ON DELETE` 없음**(t-box 는 계정·팀을 지우지 않는다 — 비활성만).

---

## 9. 화면 요구사항

| 화면 | 구성 | 주의 |
|---|---|---|
| 로그인 (`/login`) | 아이디·비밀번호·(펼쳐지는) 2단계 코드. 브랜드 「커뮤니티 · t-box 계정으로 로그인」 | 실패 사유 비노출(t-box 와 같은 문구). 「계정·비밀번호는 t-box 에서 관리합니다」 한 줄 + t-box 링크 |
| 커뮤니티 (`/`) | 좌: 게시판 목록(이름·등급 배지·글 수, 비활성은 「보관」 배지) · 우: 글 목록(제목·글쓴이·시각·댓글 수) + 검색·정렬·페이지 + 「글쓰기」 → 글 한 편(제목·글쓴이·시각·본문·「고치기」「삭제」) + 댓글 목록·「댓글 쓰기」 | 본문·댓글은 `textContent` + `pre-wrap` 만. 「글쓰기」「고치기」「삭제」 표시는 서버가 준 `can_post`·`can_edit`·`can_delete` 로만, 판정은 서버. 쿼리 `board`·`post` 는 형식 검사 통과 값만 |
| 게시판 관리 (홈 머리 단추 → 모달) | 목록(이름·등급·팀·공지·활성) · 만들기·고치기 폼(사유 칸, 팀은 `GET /api/teams` 의 활성 팀 `<select>`) | 단추 표시는 `/api/me` 의 `can_manage_boards`. API 가 `next:"reauth"` 를 돌려주면 **재인증 모달**(t-box F-23 과 같은 모양) |
| 관문 안내 (`/`, F-02) | 「t-box 에서 비밀번호 변경(또는 2단계 인증 설정)을 먼저 마치세요」 + 「t-box 로 가기」 + 「로그아웃」 | 다른 내비·목록은 그리지 않는다 |
| 공통 셸 | 좌측 내비 「게시판」 · 「t-box 로 가기」(외부 링크, `rel="noopener"`) · 사용자 칩 · 로그아웃 · 버전 · 해/달 · 「사용 방법」 | t-box `DESIGN.md` 6장 체크리스트 그대로. 외부 자원 0 |

---

## 10. 수락 기준 ★ 이걸 통과해야 "완료"

시험 스크립트 `tests/stage1.sh`(1단계)·`tests/stage2.sh`(2단계). 검토 계정은 t-box 와 같은 `qa_admin01`·`qa_manager01`·`qa_employee01`·`qa_employee02`·`qa_guest01`(`/etc/tbox-v2/seed-accounts.txt`·`qa-totp.txt`), 요청마다 `-A "community-review/<날짜>"`. 만드는 것은 전부 `C1-`·`C2-` 접두.

| # | 테스트 | 방법 | 기대 결과 |
|---|---|---|---|
| T-1 | 로그인·세션 (F-01, S-06) | qa_employee01 아이디·비밀번호만 → ; + 코드 → ; 틀린 비밀번호 5회 → ; `GET /api/me` 쿠키 없이 → | 401 `mfa_required` ; 200 `ok` + 쿠키 `comm_session`(HttpOnly·Secure·SameSite) ; 423 + **t-box `users.locked_until` 이 찍힘**(공유 증명, `psql` 로 확인) ; 401 |
| T-2 | 잠금 공유 (S-08) | T-1 의 잠금 뒤 **t-box**(`:8082`) 로 같은 계정 로그인 | 423 — 커뮤니티에서 만든 잠금이 t-box 에도 적용됨. 10분 뒤(또는 시험은 `locked_until` 만료 대기 대신 다른 qa 계정으로) 정상 |
| T-3 | 코드 재사용 공유 (S-08) | 커뮤니티에서 코드 X 로 로그인 성공 → 같은 30초 창에서 t-box 에 코드 X | t-box 401 + 감사 `code_reused`(t-box 쪽) — `mfa_last_step` 공유 증명 |
| T-4 | 강제 절차 관문 (F-02) | qa 계정 하나를 **t-box 에서**(관리자 화면, 사용자가 수행) 임시 비밀번호 재발급 → 커뮤니티 로그인 | 로그인 200 `next:"tbox"` ; `GET /api/boards` → 403 `{next:"tbox"}` ; `GET /` → 안내 화면(목록 요소 0) ; 감사 `auth.gate deny tbox_required`. (사용자 관문 ③ 항목 — 스크립트는 SKIP) |
| T-5 | 최소 권한 (S-08) | `psql` 을 `community_app` 으로: `SELECT count(*) FROM public.files` · `UPDATE public.users SET role='Admin' WHERE …` · `DELETE FROM community.audit_logs` · `SELECT * FROM public.sessions` · `CREATE TABLE community.hax(i int)` | 전부 `permission denied`. `UPDATE public.users SET failed_count=0 WHERE username='qa_employee01'` 만 성공 |
| T-6 | t-box 영향 0 (G-4) | t-box `tests/stage26.sh`·`tests/help_button.sh` 를 설치 전·후 | 통과 수 동일. t-box `db/schema.sql`·`grants.sql`·코드 `git status` 무변경. `tbox_backup` 으로 `pg_dump -s tbox_re` 성공(새 스키마 포함) |
| T-7 | 노출 (S-02, F-03·F-04) | qa_admin01 이 `C2-팀`(team, employee01 의 팀) 게시판 생성 ; qa_guest01 `GET /api/boards` ; qa_employee02(다른 팀) 목록 ; qa_employee02 가 그 id 로 `GET …/posts` ; 없는 id | guest 응답 **원문**에 internal·team 이름·id 0건 ; employee02 응답에 `C2-팀` 0건 ; 403 동일 문구 + 감사 `post.read deny team_mismatch` ; 403 같은 문구 |
| T-8 | 쓰기 자격 (S-01, F-05) | qa_guest01 글쓰기 ; qa_employee01 → 공지사항 ; → 자유게시판 ; 관리자가 `C2-팀` `is_active:false` → employee01 글쓰기 → 다시 true | 403 + `authz.route deny` ; 403 `post.create deny role_not_permitted` ; 201 ; 400 `board_inactive` |
| T-9 | IDOR·소유 (S-01, F-07) | qa_employee02 가 employee01 글 `PATCH`·`DELETE` ; qa_manager01(같은 팀)이 `C2-팀` 의 employee01 글 삭제 ; 자유게시판(internal)의 employee01 글을 manager 가 삭제 ; qa_admin01 삭제 ; 없는 UUID ; 지워진 글 GET·댓글 | 403 + `not_owner`, DB 불변 ; 200 `granted_by:team_scope` ; 403 `not_owner` ; 200 `admin_all` ; 404 ; 404·404 |
| T-10 | XSS (S-03) | 제목 `"><img src=x onerror=alert(1)>` · 본문 `<script>alert(1)</script>\n{level}{target}` 로 글 → `GET /api/posts/{id}` ; `GET /` HTML ; 감사 summary ; Playwright 로 글 열기 | JSON 에 원문 그대로 ; HTML 응답 본문에 `<img`·`<script>alert` 0건 ; summary 의 제목이 「 」 안에 글자 그대로 ; dialog 0·본문 텍스트 글자 그대로·콘솔 오류 0 |
| T-11 | SQLi·CSRF (S-04, S-06) | `q=' OR '1'='1` · `q=%' UNION SELECT username,password_hash,1,1 FROM users --` · `q=#%_` · `sort=id;DROP TABLE posts--` · `page=-1` ; 쿠키만·헤더 없이 `POST …/posts` | 전부 200 정상 모양, 응답에 `password`·`SQLSTATE` 0, `app.log` 오류 0 ; 403 + `csrf deny token_mismatch` |
| T-12 | 댓글·게시판 관리 (F-08·F-09) | 본인 댓글 201→삭제 200 `owner` ; 남의 댓글 삭제 ; 2,001자 ; 빈 본문 ; 허용 키 밖 `{body, html}` ; qa_employee01 `POST /api/boards` ; qa_admin01 재인증 없이 ; 재인증 뒤 사유 없이 ; `PATCH {access_level}` ; 같은 이름 대소문자 다르게 ; 51자 이름 | 200 ; 403 `not_owner` ; 400 ; 400 ; 400 ; 403 `authz.route deny` ; 403 `next:"reauth"` ; 400 ; 400 ; 409 ; 400 |
| T-13 | 정적·회귀 | `scripts/security_lint.sh` 0건 · 바뀐 `.php` 전부 `php -l` · `bash -n tests/*.sh` · `sudo nginx -t`·`php-fpm8.1 -t` · `scripts/ui_snapshot.sh`(있으면) 콘솔 0 | 전부 통과. 린트에 **cURL 예외가 없다**(`Fetch.php` 자체가 없다) |

---

## 11. 로드맵 (v0.2)

| 단계 | 만드는 것 | 검증 | 기간 |
|---|---|---|---|
| 1·2 | 골격·계정 공유 · 게시판(평문) — **완료** v0.2.0 (2026-09-22) | T-1~T-13 | — |
| **3** 본문 형식·에디터·입구 | F-15 블록 JSON(스키마·검증기·렌더러 2벌·이관) · F-16 에디터 · F-26 태그·조회수·검색 · F-33 팔레트 저장·표시 · **F-36 앱 런처 + 공개 주소**(루트 런처, `/tbox`, `/community`) | T-14 스키마 퍼징 · T-15 렌더 XSS · T-16 409 · T-17 이관 왕복 · T-18 런처 정적(스크립트 0·쿠키 0) · 회귀 | 2~3주 |
| **4** 개인 공간·트리·이력 | F-17 · F-18 · F-19 · F-20 · **F-37 상태·담당자 칸** · toggle·table·child_page · 「내 공간」 | T-19 트리 불변 · T-20 private 는 소유자+Admin 200·Manager 403 · T-21 게시·해제 · T-22 체크 이력 · T-23 되돌리기 · T-23b 상태·담당자 이력 | 2주 |
| **5** 토론·참조·첨부 | F-21 · F-22 · F-23 · F-24 · F-25 · F-30 · F-35 · image·file·column·link_to_page | T-24 멘션 후보 범위 · T-25 첨부 등급 · T-26 역참조 가시성 · T-27 읽음 확인 범위 | 2주 |
| **6** 데이터베이스 | F-27 | T-28 필터 인젝션 · T-29 상태 보드 · T-30 병합 표 | 2~3주 |
| **7** 마무리 | F-28 · F-29 · F-31 · F-32 · F-33 관리 화면 · F-34 | T-31 인계 후 이력 불변 · T-32 30일 정리 · T-33 임베드 허용 목록 밖 400 | 1~2주 |

---

## 11-1. 기획에서 남은 것 (사용자 결정 대기 — Fable 기본안 병기, 2026-09-22)

| # | 항목 | Fable 기본안 | 결정 |
|---|---|---|---|
| ③ | 개인 공간 기본값 | 새 페이지는 **비공개**로 시작 · 팀 공간은 팀마다 자동 · 첫 로그인 때 「시작하기」 페이지 | **확정(사용자 2026-09-22 「기본안 그대로」)** |
| ④ | 게시판 종류 첫 범위 | 토론·질문답변·공지 셋 + 시드(공지사항·자유게시판·질문과 답변). 사례기록·문서형은 뒤에 | **확정(사용자 2026-09-22)** |
| ⑤ | 업무 관리(데이터베이스) 깊이·시점 | 노션 `MAIN` 이 기준. 표 전체는 6단계 | **확정(사용자 2026-09-22 「상태·담당자 먼저」)** — 페이지에 **상태 라벨·담당자 칸**(고정 속성 2개, F-37)을 **4단계**에 먼저, 표 전체(F-27)는 6단계 |
| ⑥ | 댓글·알림 범위 | 멘션 후보 = 그 페이지를 볼 수 있는 사람 전체(Guest 제외) · 알림은 앱 안 종 아이콘부터, 메일·메신저는 연동 지점만 | **확정(사용자 2026-09-22)** — F-22 의 「같은 팀 + 열람자」 를 「열람자 전체」 로 고침 |
| ⑦ | 인계 절차 | 본인→같은 팀 · Manager→자기 팀원 사이(비열람) · Admin→누구에게나(재인증) · 비활성 계정 「인계 대기」 90일 경고 | **확정(사용자 2026-09-22)** — 「그 그림 맞아. 관리자는 내용 볼 수 있게」 → `private` = 소유자 + Admin. Manager 권한은 기본안대로(사용자 이견 없음) |
| ⑧ | 첫 공개 범위 | 런처 + t-box + 커뮤니티 1차(블록 에디터·개인 공간·공개 범위·게시·게시판 셋·댓글·멘션·알림·검색·버전·체크 이력·상태·담당자 칸). 첨부·DB 전체·인계·휴지통은 2차 | **확정(사용자 2026-09-22)** |

## 12. 열린 질문

| # | 질문 | 결정 시점 | 상태 |
|---|---|---|---|
| Q-1 | 커뮤니티를 어디에·어떤 모양으로 | 1단계 전 | **확정(사용자 2026-09-21)** — ① **별개 앱** `/var/www/community`(Fable 이 t-box 안 F-68 로 착수했다가 사용자 「T-BOX 는 T-BOX 이고 별도 앱」·「B 가 맞는데」 로 되돌림) ② **계정은 t-box 공유**(같은 DB, 읽기 + 잠금 3열) ③ **골격은 t-box 복사** ④ 모양은 게시판(공지·자유·팀), 게시판 등급·팀은 만든 뒤 불변, 본문은 평문만(Fable 기본안, 이견 없음) |
| Q-2 | 2차 범위 | — | **v0.2 로 흡수**(F-15~F-36). 남은 것: 내 활동(F-13)·감사 조회 화면·본문 민감정보 검출 — 7단계 뒤 |
| Q-3 | **공개 주소** | 3단계 | **확정안(사용자 2026-09-22 「구글처럼 기능별로 선택하여 접근」)** — 루트 = **앱 런처**(F-36), `/tbox` = t-box(28단계 base-path), `/community` = 커뮤니티. 강사 「루트 = 기존 솔루션」은 런처가 그 자리. 강사 최종 확인 뒤 3단계에서 적용 |
| Q-4 | 이름 — 화면에 「커뮤니티」로 표기. 다른 이름을 원하면 브랜드 문자열 두 곳(셸·로그인)만 바꾼다 | 2단계 | 임시 「커뮤니티」 |
| Q-5 | 커뮤니티가 잠금을 만들었을 때 **t-box 의 살아 있는 세션**은 끊지 않는다(t-box 는 자기 잠금에서 세션을 끊는다, R-48). 끊어야 하나? 끊으려면 커뮤니티가 t-box `sessions` 에 DELETE 권한을 가져야 한다(S-08 위반) | 1단계 검토 | **기본안: 끊지 않는다**(위험 수용, SECURITY R-02) |
| Q-6 | 에디터 엔진에 외부 라이브러리(ProseMirror) — 의존성 0 원칙의 첫 예외 | 3단계 전 | **확정(사용자 2026-09-22, D-1)** — 들인다. MIT · 고정 버전·해시 · 저장소 안 번들 · 서버 실행 0 · 자체 구현은 후퇴 경로 |
| Q-7 | 판단 기준 — 「노션에 있나」 vs 「우리가 쓰기에 더 좋은가」 | 즉시 | **확정(사용자 2026-09-22)** — 후자. 항목별 N-1~N-11 은 `docs/community/notion-structure-and-design.md` 9장 |
| Q-8 | 실시간 공동 편집·자유 수식·인라인 글자 크기 | 7단계 뒤 | **보류** |
| Q-9 | 앱 간 **한 번 로그인(SSO)** — 지금은 앱마다 로그인(세션 분리). 런처에서 한 번 로그인해 두 앱을 쓰게 할지 | 3단계 뒤 | 미결 — 계정이 같으니 아이디·비밀번호는 하나. 세션을 공유하면 한 앱의 세션 탈취가 다른 앱까지 열린다(R-09 방향과 반대). 수요 보고 결정 |
