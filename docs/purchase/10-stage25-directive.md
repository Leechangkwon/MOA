# 25단계 구현 지시서 (Fable → Opus) — 구매·재고 모듈 UI 정돈: 공통 CSS 한 벌 · 시안 충실도 · 정보구조 · 2단 레이아웃

작성 2026-09-14. 근거: 사용자 피드백 2026-09-14 「구매 기능들이 최초 기획 초안 대비 상당히 퀄리티가 떨어져 보이고, 정리가 안 되어 보이는 모습이 굉장히 많다 …
구성 요소 크기가 들쭉날쭉, 같은 줄의 버튼 하나만 크다, 텍스트가 잘리거나 찝힘, 카드가 겹치거나 잘림, 한눈에 정보를 알기 어렵다」 →
PRD 13장 25단계 · 12.2 T-50, ARCH 5.18 「구매 화면 공통 규칙」, STAGES 25단계. **기능은 더하지 않는다. 보이는 것만 고친다.**

## 0. 왜 이렇게 됐나 — 검증된 원인 (고치기 전에 읽을 것)

스크린샷(`scripts/ui_snapshot.sh`, 1440px, qa_admin01)과 파일 대조로 확인한 사실이다. 추정이 아니다.

| # | 원인 | 증거 | 보이는 결과 |
|---|---|---|---|
| 1 | **화면별 CSS 5벌** — `purchase.css`·`purchase_docs.css`·`purchase_stock.css`·`purchase_recon.css`·`purchase_structure.css` 가 각각 「앞 파일을 가져다 쓰지 않고 스스로 정의한다」 | 각 파일 3~6행 주석 | `.panel__tools`·`.field--inline`·`.check` 가 앞 2벌에만 있어 **입고·이동·재고현황·주간 사용량·월 대사·브랜드지점창고 6개 화면에서 필터가 세로로 무너지고 체크박스가 라벨 없이 떠 있다**(스크린샷 `purchase_stock.png`·`purchase_receipt.png`) |
| 2 | 같은 개념 클래스가 여러 벌·이름 충돌 | `.p-badge` 5벌(structure 만 테두리형), 변형 접미어 두 체계(`--insert/--update/--review/--error` vs `--ok/--warn/--danger/--accent`), `.p-tab` 4벌, `.p-sub` 는 docs(h3용)와 stock/recon(셀 부제) 이 **반대 의미**, 표 푸터 5종, 칩 4종 | 화면마다 같은 요소가 다르게 보임 |
| 3 | `.panel__head` 를 툴바 컨테이너로 오용 | `app.css:234` 는 `flex-direction: column` (제목+부제용) | 제목 아래 툴바가 전체 폭으로 붙고, 시안의 「좌 제목 / 우 컨트롤」이 어느 화면에도 없음 |
| 4 | 발주요청·발주서 입력 화면의 머리 칸과 줄 표가 **같은 그리드의 좌·우 칸**에 들어감 | `purchase_pr.php:56-102`, `purchase_po.php:51-90` — 스크린샷 `purchase_pr.png`: 머리 칸 5개가 왼쪽 세로로, 표가 오른쪽 좁은 칸에 3열만 보이고 합계·저장 버튼이 표 옆에 떠 있음 | 시안(`Main.dc.html`)은 머리 카드 4열 그리드 **위**, 줄 카드 **아래** 전체 폭 |
| 5 | `id="msg"` 가 셸(`shell_bottom.php:21`)과 6개 화면에 중복, `.form-msg` 는 어느 CSS에도 없음 | receipt·move·stock·weekly·recon·structure | 오류가 색 없이 뜨거나(6화면) 페이지 맨 아래에 뜸(7화면) |
| 6 | 호박색 `.notice` 를 화면 상단에 쌓음 | `purchase_import.php` 7개, pr·po·masters·structure 1개씩. 시안엔 안내 박스 0개 | 폼보다 경고색 상자가 먼저 보임 |
| 7 | 시안의 시각 신호를 버림 | `.mono` 사용 0회(docs/stock/recon/purchase JS), 수량·판정 셀 평문(`purchase_stock.js:456`, `purchase_docs.js:1108`), 포장·낱개 배지 0회 | 전표번호·금액 열이 들쭉날쭉, 「무엇이 부족한가」가 한눈에 안 보임 |
| 8 | 시안의 2단 레이아웃 5장을 전부 1단으로 펼침 | 전표 목록 우측 「연결 전표」 패널 없음(`Docs.dc.html:30-56`), 불러오기 트리 2단→1단(`purchase_po.php:213`), 추천·내 리스트 2열→세로, 가져오기 좌 요약 카드 없음 | 넓은 화면이 비고 스크롤이 길다 |
| 9 | IA: 시안 3허브(기초정보/전표/현황) → 사이드바 평평한 링크 11개 + **화면마다 헤더에 다른 화면 링크** | `shell_top.php:146-194`, `purchase_pr.php:27-29` 외 6곳 | 내비가 두 벌, 시안이 그 자리에 둔 실제 액션이 밀려남 |
| 10 | 개발 잔재 노출 | 문구 「21단계」 3곳(`purchase_docs.php:109`, `purchase_item.php:60`, `purchase_pr.php:198`), 수불부 탭 「품목 id」 숫자 입력(`purchase_stock.php:107`), 자기 자신으로 가는 「인쇄·PDF」 링크(`purchase_po.php:143`), 라벨 없는 `<th>` 12개, 도달 불가 품목 폼(`purchase.js:200-213`) | |
| 11 | 헬퍼 5벌 중복 · 사이클 표 2벌 | `el/setText/dash/errorMessage/request/cell/initTabs/qty/kpi` 가 JS 5개에 각각, `purchase_docs.js:669` vs `:1125` 가 같은 API를 다른 열로 그림 | 고치면 한쪽만 고쳐진다 |
| 12 | 빈 화면의 큰 공백 | 부제 아래 ~90px 빈 공간(`.p-tabs` margin 18px + `.notice` 자리) — 스크린샷 `purchase_stock.png`·`purchase_recon.png` | 짜임새 없어 보임 |

```
목표        : 구매·재고 13개 화면이 **한 제품**으로 보이게 한다 — 공통 CSS 한 벌, 시안(docs/purchase/design 16장)의 배치·배지·글꼴 신호를 그대로,
              사이드바 3묶음, 시안의 2단 레이아웃 복원, 개발 잔재 제거. 서버 코드·API·DB·권한은 한 줄도 바꾸지 않는다.
요구사항    : PRD 13장 25단계 · 12.2 T-50 ; ARCH 5.18 「구매 화면 공통 규칙」(이번에 신설) · 13장 체크리스트 ; SECURITY R-65(범위)·CSP(style-src 'self' — 인라인 style 금지 유지) ;
              시안 docs/purchase/design/*.dc.html 16장 전부 + README.md(어휘) ; 셸 규범 public/assets/app.css(토큰·.page-head·.panel·.toolbar·.table-foot·.badge·.btn·.modal__*) 과 src/views/home.php(사용례) ;
              이 지시서 0장 — 읽고 시작할 것. 시안 PNG 는 검토자가 /tmp 에 렌더한 것이라 저장소에 없다 — 필요하면 `scripts/ui_snapshot.py` 와 같은 방법으로 file:// 로 열어 본다.
수정 파일   :
  [고침]
    public/assets/app.css                — 「공통 조각」 절 추가만(기존 규칙 변경 금지): .badge--warn .badge--danger .badge--accent · .chip .chip.is-on · .tabs .tab .tab.is-on ·
                                            .panel__head--row(좌 제목/우 도구, flex-wrap) · .panel__tools · .field--inline(라벨+컨트롤 가로, gap 8px) · .check(체크박스+라벨 가로, 높이 var(--h-input) 정렬) ·
                                            .kpi .kpi__value .kpi__label .kpi--danger · .num(우측 정렬·tabular-nums) · .kbd · .empty(빈 상태: muted, padding 28px, 가운데) · .help(표 아래 얇은 회색 설명 줄, 시안 Masters.dc.html:21 형태) ·
                                            .grid-form(머리 카드 4열 auto-fit minmax(14rem,1fr)) · .split(2단: grid-template-columns: var(--split-side, 340px) minmax(0,1fr); 1024px 아래에서 1단) · .filelabel(input[type=file] 을 .btn--quiet 로 감싼 라벨, 원본 input 은 sr-only)
    public/assets/purchase.css           — **모듈 공통 한 벌**로 재작성. 다른 4벌의 내용 중 app.css 로 올라가지 않은 것만 여기로 모은다. 접두 `p-` 하나. 같은 이름 두 정의 금지(.p-sub 는 셀 부제 의미 하나로).
    public/assets/purchase_print.css     — (새 이름) purchase_po_print.php 전용. 인쇄 양식은 시안 밖(이카운트 캡처)이라 그대로 두되 파일 이름만 정리.
    public/assets/purchase_common.js     — [새로 만듦] el/setText/dash/errorMessage/request/cell/badge/qty/money/kpi/initTabs/say/openModal/closeModal 한 벌(textContent 만). 5개 번들의 중복 헬퍼를 지우고 이것을 쓴다. `window.PurchaseUI` 하나만 노출.
    public/assets/purchase.js · purchase_docs.js · purchase_stock.js · purchase_recon.js · purchase_structure.js — 헬퍼 제거·공통 클래스 이름으로 교체·사이클 표 한 벌(purchase_docs.js 의 두 렌더러를 하나로, 두 화면이 같은 함수 호출)·아래 화면별 지시. API 호출 경로·본문·응답 처리 로직은 바꾸지 않는다.
    src/views/purchase_pr.php · purchase_po.php · purchase_receipt.php · purchase_move.php · purchase_stock.php · purchase_weekly.php · purchase_recon.php · purchase_docs.php ·
    purchase_masters.php · purchase_item.php · purchase_import.php · purchase_structure.php · purchase_po_print.php — 아래 「화면별 지시」. 모든 view 는 `<link>` 를 **shell_top 이 head 에서** 싣도록 바꾼다(아래 shell_top).
    src/views/shell_top.php              — (a) `$pageCss` 배열(handler 가 `$pageTitle`·`$activeNav` 옆에서 넘김, 없으면 빈 배열, 허용 값은 `public/assets/` 안 파일명만 — 화이트리스트 배열로 검사)을 head 에서 `<link>?v=filemtime` 로 출력 — 구매 view 13개는 본문 안 `<link>` 를 지우고 handler 에서 `['purchase.css']` 를 넘긴다(FOUC 제거) ;
                                            (b) 「구매관리」 그룹을 세 소제목(`.nav-subtitle`)으로 묶고 순서를 업무 순으로: **전표** 발주요청·발주서·입고·이동·사용·실사·전표·사이클 / **현황** 재고·미입고·주간 사용량·월 대사·마감 / **기초정보** 기초정보·브랜드·지점·창고·가져오기.
                                            링크 id·href·hidden 규칙·can_purchase 로 여는 방식은 그대로(역할 비교 없음 유지). 라벨은 화면 제목과 같게(「이동·사용·실사」, 「재고·미입고」, 「월 대사·마감」, 「전표·사이클」).
    src/views/shell_bottom.php           — `#msg` 를 그대로 두되 handler 가 `$pageMsgTop = true` 를 주면 view 가 .page-head 바로 아래에 `<p id="msg" class="msg" role="alert">` 를 한 번 출력하고 bottom 은 생략(id 중복 0). 구매 13개 화면은 전부 top 방식.
    src/handlers/purchase_*_page.php (13개) — `$pageCss`·`$pageMsgTop` 넘기기와 `$pageTitle` 문자열만. 그 밖의 로직 변경 금지.
    scripts/ui_snapshot.sh · scripts/ui_snapshot.py — 검토 도구(이미 있음). Opus 는 **실행만** 한다(qa_admin01, UA tbox-review). 고치지 않는다.
    tests/purchase_stage25.sh            — [새로 만듦] T-50 (a)~(g). 시험 데이터를 만들지 않는다(읽기·정적 검사만) — 그래서 R-73 사용자 활동 관문은 필요 없다.
    deploy/DEPENDENCIES.md               — 「검토 도구(런타임 아님)」 절에 python3-playwright(사용자 로컬 `~/.local`, chromium 채널) 한 줄.
    README.md                            — 구매 모듈 절의 내비 설명 3묶음으로.
    STAGES.md                            — 25단계 「개발 사항」 체크 표시만.
  [지움]
    public/assets/purchase_docs.css · purchase_stock.css · purchase_recon.css · purchase_structure.css (git rm). 참조가 한 곳도 남지 않아야 한다(`grep -rn "purchase_\(docs\|stock\|recon\|structure\)\.css" src public` = 0).
금지 패턴   : 인라인 `style=`(CSP) · innerHTML/outerHTML/insertAdjacentHTML · `<script>` 인라인 · 서버 PHP 로직 변경(handlers 의 SQL·Authz·응답 모양·index.php·Authz.php·Audit.php 등 보안 핵심 파일 전부) ·
             새 API/라우트 · DB 변경 · 시안에 없는 새 기능(포장 열·납기 희망일·품목 등록 폼·미입고 행 액션·독촉 문구 복사는 **26단계 후보** — 이번엔 만들지 않는다) ·
             `!important` · 화면별 CSS 파일 신설 · 같은 클래스 이름 두 정의 · id 중복 · 화면 문구에 「N단계」 · DB 내부 id 를 사용자가 타이핑하게 하는 입력 · 라벨 없는 `<th>`(빈 머리는 `<span class="sr-only">`) ·
             qa_ 아닌 계정 사용 · 시험 데이터 생성(이번 단계는 읽기·정적 검사만).
완료 기준   : PRD 12.2 **T-50** 을 tests/purchase_stage25.sh 로 실행(출력 원문) —
             (a) `scripts/ui_snapshot.sh <폴더> 1440` 과 `… 1024` 두 번: 모든 화면·탭에서 undefined_class=0 · dup_id=0 · stage_text=0 · clipped=0 · overlap=0 · row_height=0 · offscreen=0 · overflow_x=false (합계 0건). 개선 전 기준: 1440px 에서 46건.
             (b) 구매 view 13개가 싣는 CSS = app.css + purchase.css (+ 인쇄 화면만 purchase_print.css). 본문 안 `<link rel="stylesheet">` 0개(shell_top 의 head 출력만).
             (c) `grep -rn "purchase_\(docs\|stock\|recon\|structure\)\.css" src public` = 0 ; 정의가 두 번 이상인 클래스 0개(`grep -ohE '^\.[a-z0-9_-]+' public/assets/app.css public/assets/purchase.css | sort | uniq -d` 가 비어야 함).
             (d) 상태 어휘 = README 고정 어휘(미확인·진행중·일부 발주·완료·취소·보냄) — `partial → '일부'` 같은 축약 0개 ; 발주요청 전표 탭 상태 셀렉트에 「진행중」 있음 ; 배지 색 = 시안(진행중 warn·완료 ok·일부 발주 accent·취소 off·미확인 기본).
             (e) 헤더의 다른 화면 링크 0개(`.page-head__actions a[href^="/purchase/"]` 중 현재 화면 밖으로 가는 것 0 — 인쇄·PDF 는 예외).
             (f) 회귀: tests/purchase_stage19.sh ~ purchase_stage24.sh **전부** 통과(수치 원문). 시험 스크립트가 `R-73 사용자 활동 관문` 으로 종료 3 이면 그대로 적고 기다렸다가 다시 돈다.
             (g) `scripts/security_lint.sh` 0건 ; `php -l` 13 view · 13 handler ; JS 는 `node` 가 없으므로 브라우저 콘솔 오류 0(ui_snapshot 실행 중 page.on('console') — 스크립트가 찍는 report.json 의 `console_errors` 가 있으면 0, 없으면 수동으로 각 화면 열어 확인했다고 적는다).
             그리고 **눈으로**: 1440·1024 스크린샷을 시안 PNG 와 나란히 보고 화면마다 「같은 줄 컨트롤 높이 같음 / 텍스트 잘림 없음 / 카드 겹침·잘림 없음 / 빈 공간 90px 이상 없음 / 표 열 정렬(숫자 우측·mono)」 5항목 표를 보고서에 적는다.
보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문(ui_snapshot 요약 2벌, 회귀 6벌 수치, 린트) / 화면별 5항목 눈 점검 표 / 못 한 것과 이유 / ARCH 13장 체크리스트.
```

## 1. 공통 규범 — 모든 구매 화면이 따르는 뼈대

시안 16장은 전부 이 뼈대다. 화면마다 다르게 하지 않는다.

```
<main class="main">
  <header class="page-head">
    <div class="page-head__text">
      <nav class="crumbs">구매관리 › 전표 › 발주요청</nav>            ← .crumbs 는 app.css 에 있음. h1 안에 「›」 문자열 넣지 않는다
      <h1 class="page-head__title">발주요청 입력</h1>                   ← 시안 제목 그대로(전표번호·상태는 JS 가 <span class="badge"> 로 덧붙임)
      <p class="page-head__sub">한 줄 리드문</p>                        ← 설명은 여기 한 줄. .notice 로 상단에 쌓지 않는다
    </div>
    <div class="page-head__actions">…이 화면의 액션만…</div>           ← 다른 화면 링크 금지(사이드바가 한다)
  </header>
  <p id="msg" class="msg" role="alert"></p>                              ← shell 이 한 번만 출력(shellMsgTop)
  <nav class="tabs" role="tablist">…</nav>                               ← 있을 때만. margin 0
  <section class="panel">
    <div class="panel__head panel__head--row">
      <h2 class="panel__title">…</h2>
      <div class="panel__tools">…필터·버튼 한 줄(wrap)…</div>            ← 컨트롤 높이 전부 var(--h-input)=40, 버튼 var(--h-btn)=36 → .panel__tools .btn { height: var(--h-input) } 로 맞춘다
    </div>
    <div class="table-wrap"><table class="files">…</table></div>
    <p class="empty" hidden>…</p>                                        ← 빈 상태는 항상 hidden 토글. 「항상 보이는 안내문」 금지
    <div class="table-foot"><span class="muted">…요약…</span><div class="pager">…</div></div>
    <p class="help">…필요할 때만, 표 아래 얇게…</p>
  </section>
</main>
```

- **글꼴 신호**: 전표번호·품목코드 `<span class="mono">`, 수량·금액 `<td class="num mono">`, 큰 합계는 `.p-total`(18px mono, 시안 Main 푸터).
- **배지 색 = 의미**(시안 README): `badge--ok` 완료·일치·재고 충분 / `badge--warn` 진행중·부분·주의 / `badge--danger` 부족·납기 지남·명세서만 있음·오류 / `badge--accent` 일부 발주·발주서·정보 / `badge--team` 이동·중앙장 / `badge--off` 취소·중단. 수량 셀의 「부족·미입고·남는 미입고」는 숫자를 배지로 감싼다(0 이면 배지 없이 muted).
- **버튼 위계**: 화면에 파란 `.btn` 은 **한 줄에 하나**(주 동작). 나머지 `.btn--quiet`. 표 안 행 액션은 `.btn--quiet.btn--small`. 버튼이 그리드 칸을 가득 채우지 않게(`.form-actions` 안에 둔다 — 스크린샷 `purchase_masters.png` 「만들기」).
- **파일 선택**: 브라우저 기본 「Choose File」 대신 `.filelabel`(quiet 버튼 모양 + 선택된 파일명 span). import·recon·structure 세 곳.
- **모달**: app.css 표준 `.modal__head / .modal__body / .modal__foot` 만. `.modal__form`·`.modal__actions` 는 쓰지 않는다. 닫기 X 버튼은 head 우측.
- **빈 상태·페이징·재조회 라벨**: 빈 상태 `.empty`(hidden 토글) 하나, 페이징 `.table-foot > .pager`(「이전」「다음」 + 「N건 · 20건씩 · p/총p」) 하나, 재조회 버튼 라벨 「다시 보기」 하나(불러오기 목록의 「불러오기」는 담기 동작이므로 유지).
- **아이콘**: 헤더 액션·모달 버튼에 셸의 `data-icon` 을 쓴다(home.php 참조). 표 안 버튼은 글자만.
- **너비**: 1024px 에서 모든 2단(.split)이 1단으로, 표는 `.table-wrap` 가로 스크롤. 페이지 자체는 가로 스크롤 금지.

## 2. 화면별 지시 (시안 아트보드 ↔ 파일)

| 시안 | 파일 | 해야 할 것 |
|---|---|---|
| ① Main | purchase_pr.php · purchase_docs.js | **머리 카드**(`.panel` + `.grid-form` 4열: 일자·번호 / 지점·장소 / 담당자(현재 사용자 이름, 읽기 전용 + `badge` 「본인」) / 비고 full) 를 **위에**, **줄 카드**를 **아래 전체 폭**으로(원인 4 해소). 줄 카드 head 는 좌 툴바(품목 찾기 · 추천/내 리스트 두 버튼 유지) 우 `.kbd` 안내 없음(기능 없는 안내는 넣지 않는다). 푸터 `.table-foot`: 좌 「품목 N건 · 수량 M · <badge accent>거래처 K곳</badge>」 우 「합계(부가세 별도) <b class="p-total">…</b>」. 저장 버튼은 카드 밖 우하단 `.form-actions`. 전표 탭 상태 셀렉트에 「진행중」 추가. 헤더 액션 → 「전표 보기」(탭 전환)·「다시 작성」. 안내 `.notice` → `.help` 한 줄(표 아래). 「21단계」 문구 삭제. 사이클 탭은 purchase_docs 와 **같은 함수**로 그림 |
| ② ItemPicker | purchase_pr.php 모달 | `.modal__head/body/foot`. 필터 한 줄(`.panel__tools`): 검색 · 거래처 · 「찾기」. 표: 빈 `<th>` 에 sr-only 「선택」. 열 `코드(mono)` `품목명·규격(2줄: 이름 굵게 / 규격 muted 12.5px)` `거래처` `단가(num mono)` `수량(input 72px)`. foot 좌 「선택 N개 · 수량 없는 줄은 1로 담김」 우 pager+「담기」(.btn) |
| ③ MyList | purchase_pr.php 모달 | `.split`(--split-side: 1fr; 2열 같은 폭) 좌 「추천」 카드 우 「내 발주 리스트」 카드(시안 MyList.dc.html). 기능은 지금 그대로(셀렉트·전체 담기·리스트 만들기) — 배치만 |
| ④ ImportModal | purchase_po.php 모달 · purchase_docs.js | `.split`(--split-side: 300px) 좌 트리(`.p-tree`: 들여쓰기·건수 `badge--off`·펼침 ▸/▾), 우 줄 표. 기준 칩에 누른 순서 번호(`.chip` 안 `<b>①</b>`) — `#load-order` 텍스트 제거. foot 「선택 N건 · 담을 수량 M」+「담기」 |
| ⑤ PO | purchase_po.php | ①과 같은 머리/줄 2단 적층. 머리 4열: 일자 / 입고 장소 / 거래처 / 참조·비고. 줄 표 `출처 요청` 열은 `mono` + 잔량 있으면 `badge--warn`. 푸터 「공급가액 … / 합계 <b class="p-total">」. 상세 패널 헤더: 「인쇄 · PDF」 는 **전표를 고른 뒤에만** 활성(그전엔 `disabled`, href 더미 금지). 머리 수정 폼(7칸)은 상세 패널 안 `<details class="help">` 로 접어 둔다(이카운트 양식 필드가 화면을 점령하지 않게) |
| ⑥ GR | purchase_receipt.php | **탭 없이 두 카드**가 맞다(시안은 탭 3개지만 불러오기→줄 흐름이 이미 있음) — 대신 「불러오기」 카드 head 를 `panel__head--row` 로, 필터 한 줄(거래처·검색·`.check` 납기 지남만·「불러오기」). 줄 카드 머리 칸(입고일·비고)을 `.grid-form` 2열로 표 위에. 「남는 미입고」 셀은 배지(0 ok / 부분 warn / 전량 danger). 결과 패널은 저장 뒤에만 보이게(hidden 토글) |
| ⑦ MoveUse | purchase_move.php · purchase_stock.js | 탭 3개 유지. 각 탭 머리 칸 `.grid-form`(일자·보내는 곳·받는 곳·비고). 부족 경고: `.p-row-bad`·`.p-short` 를 이동 표 렌더에 실제로 적용(CSS 만 있고 안 쓰임) |
| ⑧ Docs | purchase_docs.php · purchase_docs.js | `.split`(--split-side: 1fr 380px — 좌 표, **우 「연결 전표」 aside**). 행을 누르면 aside 에 타임라인: PR→PO→GR 점·`badge`·번호(mono, 링크 → 해당 화면 `?doc=`)·품목 소표. 데이터는 기존 `GET /api/purchase/pr/{id}`·`po/{id}`·`cycle` 만 쓴다(새 API 금지). 구분 필터는 셀렉트 유지(입고·이동·사용 전표는 이 API 가 안 내려주므로 리드문의 「이동·사용·실사는 전표 목록에서」 문장을 **삭제**). 구분 셀 배지(발주요청 기본·발주서 accent). 페이징 표준 |
| ⑨ Cycle | purchase_docs.js 한 함수 | 열 10개 한 벌(발주서 번호 뒤 「보냄」 배지 포함), 기간 필터 한 줄, 전표번호 `mono`, 상태 배지. `#cycle-info` → `.table-foot` 요약 |
| ⑩ Status | purchase_stock.php · purchase_stock.js | 필터 한 줄(지점·장소·품목 검색·`.check` 부족만·`.check` 제안 있는 것만·「다시 보기」 quiet) + 우측 주 버튼 「선택 제안을 발주요청으로」. KPI 3장 `.kpi`(값 26px, 「인가량 미달」 은 값>0 이면 `.kpi--danger`). 표 `현재고` `인가량` `과부족`(배지: 음수 danger·0 warn·양수 없음) `미입고`(배지 warn) 전부 `num mono`. **수불부 탭의 「품목 id」 입력 제거** → 장소별 재고 표의 현재고 숫자를 링크로 눌러 수불부 탭이 열리며 그 품목·장소로 조회(품목명을 탭 제목에 표시) |
| ⑪ Backorder | purchase_stock.php · purchase_stock.js | KPI 4장 중 「납기 지남」 은 값>0 이면 `.kpi--danger`. 미입고 수량 배지(danger), 납기 지남 행 `.p-row-late`(배경 danger-soft). 그룹 헤더(거래처) 접기 ▸/▾. 필터 한 줄 |
| ⑫ Weekly | purchase_weekly.php · purchase_recon.js | 제목에 기간을 JS 가 덧붙임(「주간 사용량 보고 — 09-07 ~ 09-13」). 필터 한 줄. 헤더 액션 「‹ 전주」「다음 주 ›」(from/to 를 7일 이동 후 다시 보기 — 기존 조회 로직 재사용). `#wk-cut` 상한 안내는 `.table-foot` 좌측 muted 로 |
| ⑬ Recon | purchase_recon.php · purchase_recon.js | 탭 3개 유지. 업로드 한 줄(거래처·월·`.filelabel`·「올리기」). 열 맞추기와 결과 표는 **별도 패널** 두 개(hidden 토글, 한 패널 3상태 금지). 판정 배지 색 = 시안(일치 ok / 단가차이 warn / 명세서만 있음·우리만 있음 danger / 수량차이 warn). KPI 3장 「차이」 ≠0 이면 `.kpi--danger` |
| ⑭ Masters | purchase_masters.php · purchase.js | 필터 한 줄(검색·`.check` 중단 포함). 「새로 만들기」 패널: 폼 `.grid-form` 3열, 버튼은 `.form-actions` 우측(가득 채우지 않음). 장소 탭 용도 열 배지(창고 기본·중앙장 team·수술방 warn), 상태 열 `badge--ok/off`. 도달 불가 품목 폼 정의(`purchase.js:200-213`)는 **삭제**(품목 등록 화면은 26단계 후보) |
| ⑮ Item | purchase_item.php | 읽기 전용 상세 유지. `.panel` 두 장(기본 정보 `.p-facts` 2열 / 지점별 인가량 표). 「현재고는 21단계」 박스 삭제 → 현재고는 `GET /api/purchase/stock?q=<품목코드>` 응답에서 같은 item id 행의 합으로 표시(API 있음, 파라미터 q·branch·location·short·page 만), 행이 없으면 「—」 |
| ⑯ ExcelImport | purchase_import.php · purchase.js | `.notice` 7개 → 리드문 1줄 + 표 아래 `<details class="help">` 1개(안 열면 한 줄). 걸음 표시 `.chip` 4개 + 우측 파일 컨텍스트(파일명 · N행 · 매핑 이름) 한 줄. 2단계부터 `.split`(--split-side: 340px) 좌 「열 맞추기 요약」 카드가 미리보기·결과 동안 남아 있음. 판정 요약은 `.badge` 4개(insert accent·update ok·review warn·error danger) — `.chip` 버튼 아님. 파일 선택 `.filelabel` |
| — Structure | purchase_structure.php · purchase_structure.js | `.st-*` 26종 → 공통 클래스로(좌 트리 `.panel`, 우 표 `.panel`, `.split` --split-side 320px). 배지·mono 재정의 삭제. 파일 업로드 `.filelabel`. 긴 표는 `.table-wrap` 최대 높이 70vh 스크롤(`data-allow-scroll`) |
| — Print | purchase_po_print.php | 내용 그대로, CSS 파일 이름만 purchase_print.css |

## 3. 시험 스크립트 tests/purchase_stage25.sh 가 할 일 (T-50)

로그인 없이 정적 검사 + `scripts/ui_snapshot.sh` 호출(내부에서 qa_admin01 로그인). 데이터 생성 0.

1. (a) `scripts/ui_snapshot.sh "$WORK/1440" 1440`, `… "$WORK/1024" 1024` → 각 `합계 0건` 이 아니면 실패, report.json 의 결함 항목 원문 출력.
2. (b) 13 view 에 `grep -c '<link rel="stylesheet"'` = 0 ; 13 handler 가 `$pageCss` 를 넘김.
3. (c) 지운 CSS 참조 0 ; 클래스 중복 정의 0.
4. (d) JS 에서 `'일부'` 단독 문자열 0 ; `purchase_pr.php` 상태 셀렉트에 `in_progress`.
5. (e) 헤더 액션의 다른 화면 링크 0.
6. (f) 회귀 6개 스크립트를 순서대로 실행하고 마지막 줄(N/N) 수집. 종료 3(활동 관문)이면 그대로 표기.
7. (g) `scripts/security_lint.sh` ; `php -l`.

## 4. 하지 않는 것 (26단계 후보로 STAGES 에 남긴다)

포장(낱개·박스) 열과 배지 · 납기 희망일 · 품목 등록 화면(시안 ⑮ 편집형) · 품목 찾기 브랜드·분류 필터와 칩 4종 · 미입고 행 「입고 입력」 버튼 · 거래처별 독촉 문구 복사 · 주간 사용량 열 축(지점/장소)·금액 보기 · 임시 저장 · 인라인 셀 검색(F3) · 전표 목록에 입고·이동·사용 전표 포함(API 필요).
