# 커뮤니티 — 단계별 개발·점검표와 학습 기록 (STAGES.md)

> t-box `STAGES.md` 와 같은 규칙(CLAUDE.md 9장). 상태 ✅ 완료(커밋) · 🔄 진행 · ⬜ 미착수 · ⚠ 사용자 확인 대기(관문 ③).
> 관문: ① 구현(Opus) → ② 검토(Fable, 실행 증거) → ③ 자기 공격(**사용자**) → ④ 커밋(Fable).
> 작업 저장소 `/var/www/community` (로컬 `http://127.0.0.1:8083`, 공개 주소는 Q-3). t-box 저장소는 **읽기만**.

## 0. 진행 현황 한눈에

| 단계 | 만드는 것 | 상태 | ① 구현 | ② 검토 | ③ 자기 공격 | ④ 커밋 |
|---|---|---|---|---|---|---|
| 0 | 저장소·설계 문서(PRD·ARCH·SECURITY·STAGES·CLAUDE·DESIGN 복사)·지시서 | ✅ | Fable | — | — | `9054c41` |
| 1 | 골격·계정 공유 — t-box 골격 복사, 스키마 `community`(sessions·audit_logs)·역할 둘·grants, `.env`, 풀·nginx(:8083), 로그인·로그아웃·`/api/me`·`/api/health`·재인증·관문 `tbox_required`, 린트·`install.sh` | ✅ 구현·검토 | ✅ Opus 2026-09-22 | ✅ 2026-09-22 stage1 50/1→시험 결함 정정(50/0)·T-6 회귀 108/0·16/0·pg_dump 5표 | ⚠ 대기 (T-4·T-10(e) 포함) | `3f3f7f5` · v0.2.0 `4a006e9` |
| 2 | 게시판 — boards·posts·comments + 시드 2개, F-03~F-09, 화면·도움말, 감사 문장 표 | ✅ 구현·검토 | ✅ Opus 2026-09-22 (1단계에 이어, 재지시 1회 `/api/teams`) | ✅ 2026-09-22 stage2 97/0/1 | ⚠ 대기 | `3f3f7f5` · v0.2.0 `4a006e9` |
| 3 | 공개 경로(Q-3) — 루트 또는 `/community`, t-box 28단계와 함께 | ⬜ | — | — | — | 사용자·강사 결정 뒤 |

---

## 1. 보안 점검은 이렇게 한다

t-box STAGES 1장과 같다. 이 앱만의 추가: 모든 검토에 **t-box 회귀**(`/var/www/tbox-v2/tests/stage26.sh`·`help_button.sh`)와 `community_app` 권한 부정 확인(T-5), `tbox_backup` 으로 `pg_dump -s tbox_re` 성공(T-6)을 넣는다. 사용자 최소 명령:

```bash
curl -s http://127.0.0.1:8083/api/health                                   # 200 {"status":"ok","db":"ok",…}
sudo -u postgres psql -d tbox_re -c "\dt community.*"                       # 5표 (sessions·audit_logs·boards·posts·comments)
sudo -u postgres psql -d tbox_re -c "\dp public.users" | grep community_app # SELECT + UPDATE(3열) 만
sudo tail -n 5 /var/log/community/audit.log
```

---

## 2. 단계별 기록

### 0단계 — 저장소·설계 (2026-09-21)

**개발 사항** — [x] `/var/www/community` 생성(root 소유 `/var/www` 아래라 `sudo install -d -o changkwon`) · `git init -b main` · [x] `DESIGN.md`·`.gitignore` 를 t-box 에서 복사 · [x] PRD·ARCHITECTURE·SECURITY·STAGES·CLAUDE·README·`.env.example` 작성 · [x] 지시서 `docs/stage1-2-directive.md`

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-21 | 요청(사용자) | 「커뮤니티를 만들라는데… 어떤 커뮤니티를 어떻게」 | Fable: 사내 게시판(5대 취약점 표본) 권고, 위치는 A(t-box 안) / B(별도 앱) 두 갈래 제시 — B 권장 |
| 2026-09-21 | 결정(사용자) | 「현재는 T-box 프로젝트가 있었어, 커뮤니티를 추가해야 해」 → Fable 이 **A 로 잘못 읽고** t-box PRD 에 F-68 을 쓰기 시작 → 사용자 「엥 아닌데? T-BOX 는 T-BOX 이고 별도 앱인데」 「B 가 맞는데」 | t-box PRD 를 백업본으로 **되돌림**(F-68 0건 확인). 교훈: 「추가」 라는 말만으로 위치를 단정하지 않는다 — 갈림길은 확인 질문 하나가 싸다 |
| 2026-09-21 | 결정(사용자) | 위치 `/var/www/community` 새 저장소 · **계정은 t-box 공유** · 골격은 t-box 복사 | PRD Q-1. 계정 공유 방식은 Fable 설계: 같은 DB `tbox_re` 안 스키마 `community`, 역할 `community_app` 에 `users`·`teams`·`permissions` SELECT + `users` 3열 UPDATE (ARCH 5장, SECURITY R-01) |
| 2026-09-21 | 결정(Fable) | 잠금 카운터·TOTP 스텝을 두 앱이 공유한다 | 공유하지 않으면 커뮤니티가 무차별 대입·코드 재사용의 우회 입구(R-01). 대신 t-box 세션 회수는 못 한다(R-02, Q-5 기본안) |
| 2026-09-21 | 발견(Fable) | t-box 백업 계정 `tbox_backup` 이 새 스키마를 읽을 권한이 없으면 t-box `pg_dump` 가 **통째로 실패**한다 | grants.sql 에 `tbox_backup` 권한 3줄 + 역할 없으면 예외. SECURITY R-05, T-6 |
| 2026-09-21 | 대기 | 공개 주소(루트 vs `/community`), 이름(「커뮤니티」 임시) | PRD Q-3·Q-4 |
| 2026-09-22 | 결정(사용자) | 1·2단계 게시판은 「만들어진 것 없이 만들어진 상태 — 기존 커뮤니티 내용은 쓸모 없다」. **기획을 끝낸 뒤 그 결정대로 처음부터** 짠다. 구현·통합 작업은 기획 완료 뒤 | v0.2.0 코드는 참고용 뼈대. 지시서·통합 작업 보류 |
| 2026-09-22 | 결정(사용자) | **프로젝트 이름 = Qaredi** — 사용자 이름(이창권)을 암호화했을 때 나온 문자열, 「내 것」이라는 워터마크. 더 이상 t-box 가 아니고 커뮤니티가 합쳐진 **한 사이트**. 첫 화면은 구글식 앱 런처로 t-box(파일)·커뮤니티(게시판·개인 공간)에 접근 | 한 앱·한 저장소(`qaredi`)·한 로그인. 주소 `/`=런처 · `/tbox` · `/community`. 강사 지시는 「커뮤니티를 만들라」 였고 「따로 만들라」 는 아니었음(사용자 확인) — 분리 권고의 근거 소멸, Fable 권고도 합치기로 바뀜 |
| 2026-09-22 | 해소 | 「분리하면 인터넷에 둘 다 배포할 수 없지 않나」 | 프록시 → VM:80 하나를 nginx 가 경로로 나누므로 분리·통합 어느 쪽도 도메인 하나로 배포된다. 배포는 결정 요인이 아님 |
| 2026-09-22 | 정리 | 기획에서 남은 것: ③ 개인 공간 기본값 ④ 게시판 종류 첫 범위 ⑤ 업무 관리(데이터베이스) 깊이·시점 ⑥ 댓글·알림 범위 ⑦ 인계 절차(Manager 권한) ⑧ 첫 공개 범위 | 차례로 결정 뒤 Qaredi PRD 로 통합 |

### 1단계 — 골격·계정 공유 (착수 대기)

> 지시서: `docs/stage1-2-directive.md` 1부. 근거: PRD F-01·F-02·F-10·F-12 · S-01·S-06~S-10 · T-1~T-6·T-13, ARCH 2~5·8~9장, SECURITY R-01~R-06.

**개발 사항 (① 구현 — Opus)** — 지시서 1부 체크리스트 그대로. HTTP 시험은 **사용자가 `sudo bash deploy/install.sh` 를 실행한 뒤** 돈다(DB 역할·스키마·풀·nginx 는 시스템 변경 — 사용자 몫).

**체크 (② 검토 — Fable)** — 정적 검토 2026-09-22 통과: `php -l` 34/34 · `bash -n` 6/6 · `security_lint.sh` 0건(13항목) · 잔존 낱말 0 · innerHTML 0 · 라우트 18/18 · Opus 임시 DB 검증 원문(T-5 다섯 문장 permission denied · 3열 UPDATE 만 성공 · `tbox_backup` pg_dump -s rc=0). 코드 읽기: 핸들러 검사 순서·사유 코드·403/404·논리 삭제·grants 가 ARCH 5·7·8장과 일치. Opus 판단 요청 처리 — ① `community_owner` 에 `REFERENCES (id)` (ARCH 5.2·PRD S-08 반영) ② CONNECT 를 schema.sql 앞에 (ARCH 9.4) ③ 팀 선택지 → `GET /api/teams` 신설(PRD 7장·ARCH 7.8, 재지시 1회). Fable 이 도움말 별표를 지우며 `/**` 독블록을 깨뜨린 것을 Opus 가 잡아 복구(교훈: 문자열 치환 뒤 `php -l`). **HTTP 시험 (2026-09-22, 사용자가 11:30 `--baseline` 뒤 `install.sh` 실행)** — `tests/stage1.sh` **통과 50 · 실패 1 → 실패는 시험 결함**: 쿠키 `Secure` 검사가 대소문자를 구분했는데 PHP `setcookie` 는 `secure;` 소문자로 낸다(응답 원문 `comm_session=…; path=/; secure; HttpOnly; SameSite=Lax`). 검사 문자열을 `secure;` 로 정정(Fable, 시험 파일 — 경미). 실측 요지: T-5 다섯 문장 permission denied·3열 UPDATE 1건·search_path 5표 의도대로 · T-1 401 `mfa_required`→200·쿠키 이름·HttpOnly·SameSite·`/api/me` 키 10개·비밀 0 · **T-3 커뮤니티에서 쓴 코드를 t-box 에 → 401 + t-box 감사 `code_reused`(스텝 공유 증명)** · 재인증 400/200·`reauth_fresh` · CSRF 403/200 · health 200·405·404·302 · **T-6 `tbox_backup` pg_dump -s rc=0(커뮤니티 5표 포함)·t-box 회귀 stage26 108/0 불변·help_button 15/1 은 그 순간 다른 세션이 t-box `app.js`·`org_page.php` 를 편집 중이던 일시 현상(재실행 16/0)** · **T-2 커뮤니티 6번째 시도 423 · `public.users.locked_until` 찍힘 · t-box 로그인도 423(잠금 공유 증명)** — `qa_employee02` 는 10분 뒤 자동 해제. 설치 뒤 권한 표(`information_schema`): `community_app` = community 5표(sessions SIUD·audit_logs SI·나머지 SIU) + `public.users/teams/permissions` SELECT + `users` 3열 UPDATE — ARCH 5.2 와 일치.

**③ 자기 공격 (사용자)** — T-4(t-box 관리자 화면에서 qa 계정 임시 비밀번호 재발급 → 커뮤니티 로그인 `next:"tbox"`·안내 화면·`/api/boards` 403) · 다른 브라우저에서 커뮤니티 로그인 5회 틀리기 → t-box 로그인 시도 423 · 커뮤니티 쿠키를 t-box 에 붙여 `/api/me` → 401 · `psql -U community_app` 으로 `SELECT * FROM public.files` → permission denied · t-box 화면·업로드가 그대로인지.

### 2단계 — 게시판 (구현·검토 2026-09-22)

> 지시서 2부. 근거: PRD 4장 표 · F-03~F-09·F-11 · T-7~T-13, ARCH 6~8장.

**① 구현 (Opus)** — 핸들러 11개(+ 재지시 1회로 `teams_list.php`)·표 3개·시드 2개·화면(`home.php`·`app.js` 1,699줄·`app.css` 끝 절)·도움말·감사 문장 표 55행·`tests/stage2.sh`. HTML 을 만드는 코드 0, 역할 비교 0.

**② 검토 (Fable, 2026-09-22)** — `tests/stage2.sh` **통과 97 · 실패 0 · 건너뜀 1**(T-10 (e) 브라우저 — 관문 ③). 실측 요지: T-7 guest 응답 원문에 internal·team 이름 0·다른 팀 `C2-팀` 0·403 동일 문구 + `post.read deny team_mismatch` · T-8 guest 403 `authz.route deny`·공지 403 `role_not_permitted`·자유 201·보관 400 `board_inactive` · T-9 남의 글 PATCH/DELETE 403 `not_owner` + DB 불변·같은 팀 Manager 200 `team_scope`·internal 은 403·Admin `admin_all`·없는 UUID 404·지워진 글 404 · T-10 JSON 원문 그대로·HTML 에 `<img`·`alert(`·`onerror` 0·summary 「 」 안 원문·`{level}` 이중 치환 없음 · T-11 5종 전부 200 정상·`password`·`SQLSTATE`·서버 오류 0·posts 표 그대로·CSRF 403 + `csrf deny` · T-12 댓글 201/200 `owner`·남의 것 403·2,001자·공백·허용 키 밖 400·게시판 관리 Employee 403·사유 없이 400·`access_level` 400·대소문자 409·51자 400·`/api/teams` 200(비활성 팀 0·활성 2=2·`is_active` 비노출) · T-13 린트 0·php -l·bash -n·nginx -t·php-fpm -t. 치우기: 글 DELETE 창구, `C2-팀` 보관.

**③ 자기 공격 (사용자)** — 아래 절(원안) + T-10 (e): 브라우저로 `"><img src=x onerror=alert(1)>` 제목 글을 열어 경고창 0·글자 그대로·콘솔 오류 0.

**③ 자기 공격 (사용자)** — Employee 로 남의 글 「삭제」 단추를 개발자도구로 되살려 클릭 → 403 · 콘솔에서 `fetch('/api/posts/<남의 글>',{method:'DELETE'})` → 403 · Guest 로 `/api/boards` 응답 원문에 internal 게시판 없음 · 글 본문에 `<img src=x onerror=alert(1)>` → 글자 그대로 · 검색창에 `' OR '1'='1` → 정상 0건.

---

## 3. 이 문서를 채우는 규칙
t-box STAGES 4장 = CLAUDE.md 9장. 한 줄 형식 `날짜 | 구분 | 내용 | 해소·근거`, 사용자 말은 요지 그대로, 확인 안 된 것은 「대기」.
