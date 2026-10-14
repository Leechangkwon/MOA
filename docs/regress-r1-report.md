# R1 보고 — 밤 회귀(2026-10-14) 보정 (2026-10-15)

지시서 `docs/regress-r1-directive.md` · 격리 worktree `/home/changkwon/qaredi-r1-20261015`(브랜치 `fix/regress-r1`) · 결과 `/home/changkwon/qaredi-r1-results-20261015/` · 운영(작업 트리 · `tbox_re` · /etc · /var/lib/tbox-v2) 변경 0 · push · 발행 · 합치기 0.

## 변경 파일 · 커밋

| 커밋 | 파일 | 갈래 |
|---|---|---|
| `22083a4` | `public/assets/app.css` | **제품** — 글자 로고 고대비 줄을 고대비 블록 셋 안으로(2.16 위반 고침 · lib_contrast) |
| `6d057e7` | `tests/design_themes.php` · `tests/design_themes_browser.py` · `tests/community/qf12_floor_http.py` · `tests/sec4.sh` | 시험 — 여섯 중 넷의 낡은 기대값 · 준비 |
| `d74a40e` | `tests/lib/results.py` | 시험 틀 — 진행 줄(「멈춤」 오판의 원인) |
| `acc0c34` | `public/assets/app.css` | **제품** — W1b 메뉴 규칙 범위 좁힘(폰 메뉴 줄 44 → 21 · 멘션 목록 뒤집힘) |
| `8c92b54` | `tests/community/{home_h1,messenger_window,qf12_shell,qf12_u4,qf12_u5}_browser.py` · `tests/dbg_u_ui.sh` | 시험 — 열셋의 낡은 기대값 · 준비 |
| `8754d9b` | `tests/community/{qf12_u4,messenger_window,home_h1}_browser.py` | 시험 — 다시 돈 뒤 남은 셋 |
| (이 보고) | `docs/regress-r1-report.md` | 보고 |

보안 핵심 파일(Authz · Auth · Http · Db · Upload · Doc · `public/index.php`) · `db/**`(schema · grants · 이주 파일) 변경 0. 제품 파일은 `public/assets/app.css` 하나(두 커밋) — 마크업 · JS · PHP 0. `security_lint` 0건 · `asset_version` 37/0 · `selftest.sh` 297/0.

## 여섯 — 원인 · 분류 · 고친 내용 · 다시 돈 결과

다시 돈 실행: run **#24** (`tests/run.sh run --select id:… ×6 --skip-known-fail --env-src … --fresh`, 커밋 전 같은 내용의 작업 트리) — **6/6 통과 · 항목 2076/0 · rc 0 · 7분 5초**. 원문 `~/.cache/qaredi-check/runs/24/raw/` · 출력 `run-six.log`.

| 시험 | 후보 #22 | 기준 #23 | R1 #24 | 원인 | 분류 |
|---|---|---|---|---|---|
| `community.lib_contrast` | 145/1 rc 1 | 146/0 | **146/0 rc 0** | H4 가 `app.css` 2186행에 고대비 블록 **밖** 갈래 `:root[data-contrast="high"][data-wordmark], :root[data-contrast="high"] [data-wordmark-preview] {…}` 를 더해 `data-contrast` 가 3 → 5 | **제품 문제**(2.16 「화면 CSS 에 [data-contrast] 갈래 0 · 토큰 값만」 위반) |
| `design.allowlist` | 0/0 rc 255 | 21/0 | **34/0 rc 0** | H4 뒤 `Design::attrs()` 가 끝에 ` data-wordmark="d"` 를 붙임 — 시험은 옛 글자 그대로 견줌(값 자체는 허용 목록 밖이면 `d` — 옳음) | 시험의 낡은 기대값 |
| `community.qf12_floor_http` | 0/0 rc 1 | 267/0 | **268/0 rc 0** | 시험이 「옛 모양 = schema.sql 의 Q:F-12 표식 앞 글자 · Q:F-12 절은 끝」이라 가정하고 Q:F-12 이주만 돌림. 그 뒤 카페 C1(`2026-10-14_community_cafe.sql`) · W1(`2026-10-14_task_start_date.sql`) 절이 **표식 뒤에** 붙어, 새 설치에만 `anon_aliases · reports · board_requests`(열 · 제약 · 색인 · 권한 · 시퀀스) · `pages.start_date` · 알림 CHECK `moderated…` · 사건 CHECK `start` 가 있었다(차이 103줄 — 재현) | 시험의 낡은 가정 — **스키마 · grants 는 이주와 같다**(아래 증거) |
| `tbox.sec4` | 29/35 rc 1 | 67/0 | **69/0 rc 0** | 운영 사본의 검토 계정 `claude_admin01` 의 처리방침 마지막 줄이 옛 판 `2026-10-08|agree`, 지금 판은 `2026-10-14` → 세션 관문 `Legal::needsConsent` 가 `POST /api/users` 를 403 `consent_required`. 뒤 34건은 계정을 못 만든 데서 이어진 것 | 시험 준비(합성 데이터)의 낡음 — 관문은 설계대로 |
| `design.browser` | 0/0 rc 1(71행 예외) | 1354/0 | **1355/0 rc 0** | ① H1(2.31) 뒤 로그인한 런처의 테마 입구는 내 계정 판의 「테마」 타일(`.app-menu__app` — 판은 app.js 가 연다)뿐인데 이 시험은 app.js 를 비워 두어 `[data-design-open].first` 가 보이지 않음 → 30초 클릭 시간 초과 ② 설정 화면 입구 2 → 3(타일 더해짐) ③ 예외에 가려 있던 48건: H2(2.32 ⑥) 뒤 로그인 전 첫 화면에서 `.moa-service-card` 4 → 0(landing 절이 대신) | 시험의 낡은 기대값(셋 다 H1 · H2 의 의도된 변화) |
| `community.qf12_server_http` | 0/0 rc -15 「멈춤」 | 204/0 | **204/0 rc 0 · 61초** | **멈추지 않았다.** `nightly.py` 는 run.sh 출력 파일의 mtime 이 30분 안 바뀌면 끊는데, `results.py` 는 진행 줄을 `stderr.isatty()` 일 때만 써서 파일로 받으면 끝 요약 전까지 0 바이트였다 → 시작 22:00:05 + 1801초 = 22:30:07 에 끊김. 그때 돌던 것이 qf12_server_http(44초째 · 「board 360」 다음 412t 차례) — 앞 시험 합계 ≈ 1766초 | 시험 틀(진행 출력) 문제 — 시험 · 서버 · 브라우저 0 |

### 고친 내용

1. **lib_contrast — `public/assets/app.css`(제품)**: 기본 `:root` 에 토큰 `--wm-fill-text: transparent`(A · B 갈래 글자색 — 그라데이션 `-fill` 이 칠하므로 투명) 한 줄 · A · B 줄의 `--brand-wordmark-1/-2: transparent` → `var(--wm-fill-text)` · 고대비 블록 셋(밝게 · 어둡게 · OS 어둡게)에 `--wm-fill-text: var(--fg)` · 바깥 갈래 줄 삭제. 결과: 고대비에서 A · B 는 `--wm-a-fill: none` + 글자 `--fg` — 지우기 전과 같은 단색(미리보기 `[data-wordmark-preview]` 는 상속으로 같은 값). `contrast.py` 의 「새 토큰 이름 0(기본 :root 에 있는 이름만)」 · 「어두운 블록이 밝은 고대비 이름을 다 적음」도 통과(146/0). DESIGN 2.33 표의 「app.css 의 :root · 다크 · 고대비 블록에 한 줄씩」 모양 그대로(DESIGN.md 는 지시서 허용 밖이라 고치지 않음 — `--wm-*` 내부 토큰 이름은 원래 문서에 없다).
2. **design.allowlist — `tests/design_themes.php`**: 기대 글자에 ` data-wordmark="d"` 를 넣고(이유: H4 의 의도된 출력), 단언을 **더함** — 잘못된 `qaredi_wordmark` 값 9개(null · '' · 'e' · 'D' · 배열 · 따옴표 주입 · `<svg>` · 정수) → `d` · 허용 값 4개는 그대로 나감. 지운 단언 0.
3. **qf12_floor_http — `tests/community/qf12_floor_http.py`**: 표식 뒤 글자에서 `db/migrations/<파일>` 이름을 나오는 차례대로 모아(지금 `qf12_work → community_cafe → task_start_date`) 각 두 번 돌린다(두 DB 모두 — 멱등). 새 단언 「표식 뒤 절이 있는 이주 파일을 이름 대고 Q:F-12 가 처음」. SAME 단언은 그대로(문구만 migration → migrations).
4. **sec4 — `tests/sec4.sh`**: 「①-b」 절 — 임시 DB(운영 사본)에서만 `claude_admin01` 의 terms · privacy 마지막 줄이 지금 판(`Legal::current` — 저장소 `content/legal`)의 agree 가 아니면 agree 한 줄을 더하고 단언 2. 원문(#24): `terms 지금 판 2026-10-08 — 이미 동의` · `privacy 지금 판 2026-10-14 — 마지막 줄 2026-10-08|agree → 임시 DB 에만 agree 한 줄` — 원인 확인. 관문 자체의 시험은 `tbox.qf13_consent_http` 몫(이 시험은 R-39).
5. **design.browser — `tests/design_themes_browser.py`**: 런처에서는 「입구 = 내 계정 판 타일 하나 + 같은 창 `#q-design-dialog`」 단언(새)만 보고, 창 흐름(누름 · Esc 취소 · 초점 되돌림 · 저장 · 다른 화면에서 유지)은 보이는 입구가 있는 설정 화면(`[data-design-open]:visible`)에서 → 저장 뒤 런처 · 설정 둘 다 `nova/reduce` 확인. 입구 수 2 → 3(사이드바 바닥 · 내 계정 타일 · 설정 「테마 고르기」 — 모두 `aria-controls="q-design-dialog"`). 로그인 전 화면: `.moa-service-card` 0 + `section.landing-hero/-features/-steps/-trust/-faq` 각 하나 + 기능 카드 넷(2.32 ②). OS 움직임 확인도 설정 화면 입구로.
6. **qf12_server_http — `tests/lib/results.py`(시험 틀)**: stderr 가 터미널이거나 **일반 파일**이면 시험마다 `… k/n id (시작 HH:MM:SS)` · `← id 판정 · rc · 합계 · 초` + 긴 시험은 300초마다 `… id 진행 중 N초`(`wait_with_beat` — 시간 제한 판정은 그대로 `TimeoutExpired`). 파이프로 받는 쪽(자체 시험 `selftest.sh` 의 `$(…)`)은 그대로 조용 — **selftest 297/0**. #24 · 아래 13 실행의 출력 파일에 진행 줄이 실제로 찍힘(`run-six.log`). 남은 위험: 진행 줄은 시험 사이 · 300초마다라, 시험 하나가 정말 멈추면 nightly 의 30분 감시가 아니라 시험별 시간 제한(RC — 최대 10800초 `tbox.dbg_u2_ui`)이 잡는다 — 이것이 원래 설계(3.1 「시험마다 시간 제한」).

### 증거 — schema · grants = 이주 (qf12_floor 의 「제품 문제인가」 확인)

- 커뮤니티: 합성 DB 둘(`tbox_testdb` · 127.0.0.1)에 시험과 같은 모양 질의 — 「표식 앞 + qf12_work×2」만 = **DIFF 103줄**(nightly 와 같음), 「+ community_cafe×2 + task_start_date×2」 = **SAME 1597줄 · 차이 0**. 시험 #24 원문 `SHAPE SAME lines=1597 diff=0`.
- public(초대 요청): 기준 판 `6b18600` 의 `db/schema.sql` + `2026-10-14_invite_requests.sql`×2 + 지금 `db/grants.sql` ↔ 지금 `db/schema.sql` + `db/grants.sql` — public 열 · 제약 · 색인 · 표 권한 · `invite_requests` 열 권한 · 시퀀스 **SAME 1256줄 · 차이 0**.
- `2026-10-13_community_page_attachments.sql` 절은 표식 **앞**(schema.sql 776행)이라 두 DB 에 이미 같다. 임시 DB 는 모두 지움.

## 돌지 못한 열셋

실행: 먼저 R1 여섯 고침 뒤 그대로 **run #25**(13개 · 1시간 5분) → 실패한 것 중 기준 판에 있는 아홉을 **기준 판 `6b18600` 임시 worktree**(`/home/changkwon/qaredi-r1-base-20261015` — 만들고 지움)에서 **run #26** → 고친 뒤 **run #27**(12개 · 1시간 9분) · **run #28**(3개) · design.browser 다시 **run #29**(1355/0 — 둘째 app.css 고침 뒤). 대조 `tests/run.sh compare 25 26` · `27 26` · `28 26`(원문 `compare-*.md`).

| 시험 | #25(R1 여섯 뒤) | 기준 #26(6b18600) | 최종 | 판정 |
|---|---|---|---|---|
| `community.qf12_u4_browser` | ✖ 26건 | ✔ 284/0 | **✔ 284/0**(#28) | 회귀 → 고침(제품 1 + 시험 3) |
| `community.qf12_u5_browser` | ✖ 6건 | ✔ 145/0 | **✔ 145/0**(#27) | 회귀 → 고침(시험) |
| `community.qf12_shell_browser` | ✖ | ✖ | ✖(#27) — 실패 집합 ⊂ 기준 판 | **기준 판과 같은 실패**(+ H3 · C6 로 새로 깨진 5종은 고침) |
| `community.qf12_work_browser` | ✖ 86건 | ✖ 2건 | ✖ 2건(#27) = 기준 판 그대로 | **기준 판과 같은 실패**(메뉴 84건은 제품 고침) |
| `tbox.messenger_store_browser` | ✖ | ✔ 75/0 | ✖(#25) | **회귀 · 고치지 않음 — 제품 문제(DB) · 판단 필요**(아래 ★) |
| `tbox.messenger_window_browser` | ✖(로그아웃에서 끊김) | ✖(Q:T-19 GPT 17 · 259행에서 끊김) | ✖ 381/17(#28) — Q:T-19 17 = 기준 판, 끝 714행 `#assist-result` = ★ | 기준 판과 같은 17 + ★ |
| `community.memory_ai_http` | ✖ 40/1 | ✖(기준 판 시험 코드가 `PIL` 을 불러 시작 전 끊김 — 대조 불가) | ✖ 40/1(#25) | **★ 와 같은 원인** |
| `community.qf13h_privacy_browser` | ✔ 38/0 | — | ✔ | 통과 |
| `community.cafe_c2_browser` | ✖ 6건 | (기준 판에 없는 시험) | **✔ 380/0**(#27) | 회귀(W1b) → 제품 고침 |
| `community.cafe_c4_browser` | ✖ 4건 | (없음) | **✔ 520/0**(#27) | 회귀(W1b) → 제품 고침 |
| `community.home_h1_browser` | ✖ 1건 | (없음) | **✔ 380/0**(#28) | 낡은 기대값(H4) → 고침 |
| `tbox.dbg_u_ui` | ✖ 16/3 · 화면 83 | ✖ 15/4 · 화면 125 | ✖ 22/3 · 화면 56(#27) | **기준 판부터 깨짐**(아래) — 동의 관문 준비만 고침 |
| `core.ux_r1_ui` | ③ 시작 거부(rc 3) | ③ 같은 거부 | ③ | **기준 판과 같음**(아래) |

**합계(최종 · 열셋)**: **통과 6**(u4 · u5 · qf13h · cafe_c2 · cafe_c4 · home_h1) · **기준 판과 같은 실패 4**(shell · work · window 의 Q:T-19 17 · dbg_u_ui) · **③ 1**(ux_r1_ui — 기준 판도 같은 거부) · **★ 판단 필요 2**(messenger_store · memory_ai_http — window 의 끝 끊김도 같은 원인). 덤으로 고친 곳에 닿는 수락 시험: `community.qf12_w1_browser` **471/0**(W1b 의 「창 안」 그대로) · `community.home_h4_browser` **370/0**(글자 로고 · 고대비) · `design.browser` **1355/0**(#29) · `community.lib_contrast` 146/0(#27).

### 열셋에서 고친 것 — 원인 · 분류

| 무엇 | 원인 | 분류 · 고침 |
|---|---|---|
| 메뉴 줄 손가락 자리 44 → 21(u4 13건 · work 84건 · cafe_c2 6건 · cafe_c4 4건) | W1b(`2fa35d5`)가 **모든** `.menu__list` 에 `max-height: calc(100% - 14px)` — 목록이 세로 flex 라 자리가 모자라면 줄이 줄어듦(폰 44 → 21) | **제품**(2.15 손가락 자리 위반) · `acc0c34`: 규칙을 `.menu > .menu__list` 로 좁히고 `> * { flex-shrink: 0 }`(목록 안 스크롤). W1b 수락 시험 471/0 그대로 |
| 댓글 멘션 후보가 칸 위로(u4 「doc comment」 · 「task full」 gap -165 / -148) | 같은 W1b 규칙의 `position-try-order: most-height` 가 멘션 목록(`.mention-field > .menu__list`)에도 걸려 자리가 더 큰 위로 뒤집힘 | **제품** · 같은 커밋(범위 좁힘으로 멘션 목록 제외) |
| u4 「side pane」 멘션 후보 위(gap -148) | W1 「시작 예정」 줄로 작업 칸이 길어져 댓글 칸이 창 바닥에 닿음 → 아래 자리 < 목록 → 앵커 폴백(`top span-right`)이 바로 위 — 창 안에 머묾(2.15) | 시험의 낡은 가정 · 「아래, 또는 아래 자리가 없을 때만 바로 위」(자리 있는데 위면 여전히 실패) |
| u4 「시작 예정」 속성 · 문서 대조군 | W1(2.34) 속성 더함 · W1c(2.35) `#page-doc` 상자 제거 → 내용 전 높이 0 | 시험 · 기대값에 「시작 예정」 · 대조군은 「그려져 있음(숨김 · display none 0)」 |
| u5 칩 높이 40 · 이름표 · 바로가기 가운데 | C6(2.30) 칩 44 · `aria-label` 「내 계정 메뉴 — 이름」 · H1(2.31 ③) 줄 끝 「＋ 바로가기」(button) · 줄바꿈 | 시험 · 44 · 새 이름표 · 줄마다 a+button 전체로 가운데 |
| shell 앱 순서 · 이름 · 격자 링크 · `.app-menu` strict | H3 넷째 앱 「우리 공간」(`/community/cafe`) · C6 내 계정 판도 `.panel.app-menu` | 시험 · 넷째 앱 · 다섯째 타일 · `#app-grid .app-menu` |
| messenger_window 로그아웃 · 여는 도우미 | C6 로그아웃 줄은 내 계정 판 · 여는 단추는 **열고 닫음** + 같은 탭 이동은 창을 이어 엶 → 도우미가 열린 창을 눌러 닫음 | 시험 · `#account-menu > summary` · `open()` 은 이미 열렸으면 누르지 않음 |
| home_h1 「동의 전 런처 HTML = H1 전」 | H4(2.33) 머리 로고 · 바닥 「모아」 → 글자 로고 부품 | 시험 · 그 두 자리만 H4 전 글자로 되돌린 뒤 글자 그대로 같음(+ 글자 로고 수 차이 = 2) |
| dbg_u_ui 동의 관문 | sec4 와 같음 — 운영 사본의 검토 계정 셋이 지금 판 처리방침에 동의하지 않음 | 시험 준비 · 임시 DB 에서만 agree 줄(단언 6). 손님 시작하기 · 파일 · 토큰 · 표 페이지 403 은 사라짐 |

### ★ 고치지 않은 회귀 — 제품 문제 · 판단 필요 (운영 영향)

**메신저 「문장 도우미」(다듬기 · 맞춤법 · 번역)가 운영에서 실패할 것으로 보인다.** `2f5e99a`(Gemini 3.6 · v2.67.1 운영 반영 2026-10-14 14:59)가 `MemberAi::MODEL` 을 `gemini-3.6-flash` 로 바꿨는데, `MemberAssist::run`(src/MemberAssist.php:97~102)은 `member_ai_assists.model` 에 그 값을 넣고, DB 는 `CHECK (model IN ('gemini-3.5-flash','gemini-3.1-flash-lite-image'))`(db/schema.sql:563 · `2026-10-02_moa_assists.sql`) 그대로다(작업 기록 「DB … 변경 없음」). → `PDOException` → 500 → 화면 결과 0.

- 증거: 합성 DB(db/schema.sql)에 같은 INSERT — `ERROR: new row for relation "member_ai_assists" violates check constraint "member_ai_assists_model_check"` (3.5 값은 1행 들어감) · memory_ai_http 서버 로그 `[moa] member.ai.assist failed: PDOException` · messenger_store 200행 · messenger_window 714행 `#assist-result` 숨김. 기준 판(3.5)은 messenger_store 75/0.
- 같은 꼴: `member_ai_turns.model` 은 `DEFAULT 'gemini-3.5-flash' CHECK(model='gemini-3.5-flash')` — 대화는 열을 넣지 않아 실패는 없지만 3.6 으로 부른 대화가 3.5 로 기록된다. 이미지 도구는 `IMAGE_MODEL` 이라 영향 없음.
- 고치지 않은 이유: 바른 고침은 **새 이주 파일 + db/schema.sql CHECK 넓힘(3.6 더함)** — `db/**` 는 CLAUDE.md 4장 보안 핵심 · 지시서 「수정 파일」 밖 · 운영 DB 반영이 따른다. 코드만으로 돌리면(저장 값을 3.5 로) 기록이 거짓이 된다. → **메인 · 사용자 판단**: (가) 이주 `ALTER TABLE public.member_ai_assists DROP CONSTRAINT member_ai_assists_model_check, ADD CONSTRAINT … CHECK (model IN ('gemini-3.5-flash','gemini-3.6-flash','gemini-3.1-flash-lite-image'))` + `member_ai_turns` 같은 식(기본값 3.6) + schema.sql, 또는 (나) 핫픽스로 저장 값만 되돌림. 운영 DB 는 이 작업에서 읽지 않았다(운영 실패는 코드 · 스키마 · 기록으로 본 추정 — **미검증**).

### 기준 판과 같은 실패(그대로 둠 · known_fail 0)

- `qf12_shell_browser`(#27 실패 14건 = 기준 판 #26 실패 15건의 부분 집합): 런처 타일 넷(H1 · H2 전 「moa-shortcuts」 기대 — 기준 판도 실패) · Q:T-39 목록 밖 돌아갈 주소 → `/`(로그인 화면의 고정 `'/'` 기본값 — 2026-10-10 `65b095b` 부터, 기준 판 같음 · 요청 값이 Location 에 닿지 않음) · 폰 서랍 3 · C-2 사이드바 2 · ui_snapshot 2.
- `qf12_work_browser`: `ui_snapshot.py 1440/1280 ran on this instance (?)` 2건 = 기준 판.
- `messenger_window_browser`: Q:T-19 「GPT/OpenAI 글자 0」 17화면 — PC 비서 안내 · 변경 기록의 「GPT 연결」 문구(기준 판에도 같은 문구 · 같은 17).
- `dbg_u_ui`: 기준 판 화면 실패 125 → R1 56(모두 기준 판에도 있는 이름 — 직원 쓰기 단추 · 하위 추가 · 커뮤니티 홈 · 임베드 창 시간 초과 · 계정 관리 등). 새 이름 둘은 기준 판에서 가려져 있던 것: 「H1 토큰 회수 단추 화면 안 820」(기준 판은 토큰 발급이 403 이라 줄이 없었음 — 같은 화면의 「토큰 창 회수」 820 은 기준 판도 실패) · 「관리자 배지 1280 — Page.goto 30초」(기준 판은 같은 자리 「검토 L-3 Page.goto」 시간 초과).
- `ux_r1_ui`: 운영 사본에 `2026-10-13_api_tokens_multi_scope.sql` 을 다시 돌리는 준비 단계가 거부(「Q:F-10 test schema failed」) — 기준 판도 같은 거부(운영 데이터 쪽 변화로 보임 · 운영 DB 는 이 작업에서 직접 보지 않음).

### 범위 밖(밤 회귀 표의 나머지)

`stage5c` · `stage5d` · `qf2_search` · `stage7d3` · `stage89_*` · `qf12_u4_http` · `stage5e` 는 기준 판에서도 실패(SAME 이거나 합계만 다름)라 지시서의 여섯 · 열셋 밖 — 손대지 않음. 원문의 `consent_required` 가 기준 판에도 보인다.

## 정리 · 남은 것

- 남긴 것 0: 시험 틀 · php · 브라우저 프로세스 0 · 기준 판 worktree 지움(`git worktree list` 에 없음) · 이 작업의 임시 DB 0(합성 비교 DB 4개 모두 지움). 디스크 여유 4.1G.
- 이 작업 것이 아닌 잔여(보고만): 밤 회귀 22:30 끊김이 남긴 `tbox_memories_test_8768_3029628` DB · `/tmp/qaredi-stage13-0ia2r_g_` · (다른 세션의) `tbox_qf9_8421_test` · `tbox_qf9_8423_test`.
- 밤 회귀 실행기(`~/.cache/qaredi-check/nightly/nightly.py`)는 바꾸지 않았다 — 진행 줄은 저장소의 `results.py` 가 내고, main 에 합쳐지면 다음 밤부터 적용. 시험 하나가 30분 넘게 조용히 돌면 300초 진행 줄이 감시를 살려 두고, 진짜 멈춤은 시험별 시간 제한(RC)이 잡는다.
- 다음 조치(메인): ★ 문장 도우미 DB 판단 · 이 묶음 합치기 뒤 밤 회귀 대기열에 넣을 것 — `community.lib_contrast` · `design.*` · `community.qf12_floor_http` · `tbox.sec4` · 위 열셋 · `community.qf12_w1_browser` · `community.home_h4_browser`.
