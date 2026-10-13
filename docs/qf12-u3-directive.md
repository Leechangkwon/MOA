# 지시서 U3 — 작업 상세 칸: 넓히기 · 칸 편집기 · 오른쪽 칸 하나로 · 문서 전용 첨부 (2026-10-13)

사용자 요청(2026-10-13 「칸이 고정되고 작아 쓰기 불편 · 설명에 이미지 · 첨부를 넣어 가며 상세 기록을 남기기 어렵다」 · 「입력 UI 와 경로가 2곳이네?」) ·
결정 WK-24 ②(A+B+C 한 번에) · WK-25 ①(문서 전용 첨부) · WK-26 ②(오른쪽 칸 하나로 — WK-21 변경) · WK-27(첨부 기본안 넷 그대로).
기준 문서(**정답지**): 기획 `/var/www/qaredi/community/work-ux-plan.md` **15장** · 커뮤니티 `/var/www/qaredi/community/ARCHITECTURE.md` **24장**(첨부 판정 · 표 · 창구 · 검사 · 수명) ·
`DESIGN.md` **2.20-A**(펼침 줄 — WK-26) · **2.21**(폭 조절 · 칸 편집기 · 알림 색) · **2.24**(문서에 바로 올리기) · 6장 · `ARCHITECTURE.md` 13장 · `/var/www/qaredi/CLAUDE.md` 2 · 3 · 4장.
단위: CLAUDE.md 3.1 — **U3-1(보안 바닥)을 먼저 구현 → 메인 독립 검토 → U3-2(화면)** → 묶음 끝에 회귀 · 운영 반영 한 번(minor).

## 공통

| 항목 | 내용 |
|---|---|
| 구현 자리 | 격리 worktree **`/home/changkwon/qaredi-qf12u3-20261013`**(브랜치 `feat/qf12-u3` — 메인이 만들었다). 결과 폴더 **`/home/changkwon/qaredi-qf12u3-results-20261013/`**(U3-1 은 `U3-1/`, U3-2 는 `U3-2/`). 운영 `/var/www/qaredi/tbox-v2` · 운영 DB · 다른 worktree 는 읽기도 쓰기도 하지 않는다(기준 문서 둘만 읽기 허용) |
| 격리 틀 | `python3 tests/community/memories_harness.py --suite <파일> --output <결과 폴더>` — 합성 DB · 로컬 PHP(포트 8768 고정 — 한 번에 하나. 다른 세션이 쓰면 기다린다: `ss -ltn | grep 8768`). 새 스위트는 `memories_harness.py` 의 `--suite` choices 에, 새 마이그레이션은 그 파일의 `migrations` 목록 **끝**에 더한다(그 두 자리만) |
| 브라우저 | `. /home/changkwon/qaredi-qf12u3-results-20261013/env.sh`(CHROMIUM · PYTHONPATH · PATH) |
| 계정 | 격리 틀 fixture 만. 운영 · 시드 계정 0 |
| 커밋 | worktree 브랜치에 파일을 지정해(`git add -A` 금지) 한글 · 첫 줄 `Qaredi Q:F-12 U3-1: …` / `U3-2: …` · 끝에 `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. push · 발행 · main 합치기 없음 |
| 디스크 | 루트 여유 약 6GB — 스크린샷은 필요한 것만, 영상 · trace 0, 시험용 큰 파일은 결과 폴더에 만들고 끝나면 지운다 |

## U3-1 보안 바닥 — 문서 전용 첨부 (서버)

```
목표        : 작업(문서)에 딸린 첨부를 올리고 · 받고 · 본문에서 참조할 수 있게 하되, 권한은 그 문서의 읽기 · 고치기 판정을 그대로 따른다.
요구사항    : 커뮤니티 ARCH 24장 전부(24.1 원칙 ~ 24.7) · 기획 15.4 C 표 · 15.5 수락 시험의 「판정 · 검사 · 머리 · 스키마 · 수명」 — 읽고 시작할 것.
수정 파일   : db/migrations/2026-10-13_community_page_attachments.sql(새 — 표 · 색인 · GRANT · community.purge_trash 갈래(첨부 행 삭제 · 지운 경로 돌려주기 또는 표시)) ·
              db/community/schema.sql · db/community/grants.sql(같은 내용을 정본에 — 마이그레이션과 한 벌) ·
              src/community/CommunityAuthz.php(decideAttachmentRead · decideAttachmentUpload 두 갈래만 — 24.1 · 그 밖 판정 0) ·
              src/community/routes.php(COMMUNITY_ROUTES 세 줄 · 첨부 행 읽기 도우미 · 일일 총량 합산 도우미 · community_file_titles 옆 첨부 제목 도우미) ·
              src/community/handlers/attachments_upload.php · attachments_raw.php · attachments_download.php(새) ·
              src/Storage.php(attachments 하위 폴더 경로 · putLocal 재사용 · streamInline/stream 재사용 — 새 함수가 필요하면 최소) ·
              src/Upload.php(일일 총량 합산 — checkDailyQuota 가 첨부 표도 세게. 그 밖 0) ·
              src/Audit.php(문장 표 네 줄: attachment.upload allow|deny · attachment.download allow|deny) ·
              scripts/scan_pending.php(첨부 표 pending 도 같은 규칙으로) · scripts/community_purge.php(영구 삭제 때 첨부 디스크 파일) ·
              public/assets/community/doc-schema.json(`Doc.php:33` 이 읽는 사본 — `att-ref` 를 더한다. 원본 `/var/www/qaredi/community/doc-schema.json` 은 읽기만 — 동기화는 메인이 · `clients/mcp/qaredi_mcp/doc-schema.json` 은 0) ·
              src/community/Doc.php(att-ref 모양 · extract 에 att 수집) · src/community/SaveDoc.php(새 att: 이 이 문서 첨부인지) ·
              src/community/handlers/version_restore.php(같은 검사) · pages_visibility.php · pages_publish.php · pages_move.php(민감 첨부가 있으면 사내 · 게시됨으로 넓히기 거부 — 24.4) ·
              src/community/handlers/pages_get.php(`attachment_titles` 키 하나 — 그 문서 첨부 {name,size,image,scan_status}) ·
              src/community/DocRender.php · DocMarkdown.php(att: 를 창구 주소로 · 공개 링크 ctx 에서는 「볼 수 없는 파일」) ·
              Http.php 의 사유 문장 표에 bad_attachment_ref 한 줄(필요하면) ·
              tests/community/memories_harness.py(choices · migrations 두 자리) · tests/community/qf12_u3_attach_http.py(새 수락 시험) · tests/catalog.json(새 항목)
              여기 없는 파일 금지. 보안 핵심 파일(Authz.php · Auth.php · Db.php · Http.php · Storage.php · Upload.php · Audit.php · Doc.php · db/** · public/index.php)은
              **위에 적은 범위만**. Authz.php · public/index.php 는 0(커뮤니티 라우트는 routes.php).
금지 패턴   : SQL 문자열 결합 · innerHTML · 사용자 입력이 든 파일 경로(디스크 이름은 난수 uuid 만 — 원래 이름은 표에만) · 외부 요청 코드 ·
              역할 판단을 CommunityAuthz 밖에서 · 요청 파라미터의 role 값 · 브라우저 HTML 저장 · 판정 두 벌(첨부용 문서 판정 복제 0 — decidePageRead/Change 를 부른다) ·
              첨부를 자료실 files 표 · 자료실 판정에 섞기 · 원문 · 비밀을 감사 · 로그에 찍기 · DELETE 권한을 tbox_app 에 주기.
완료 기준   : qf12_u3_attach_http(격리 틀) — 기획 15.5 의 판정 · 검사 · 머리 · 스키마 · 수명 전부. 최소:
              ① 판정: 비공개 문서 첨부 — 소유자 200 · 같은 팀 아닌 직원 404 · Admin(관리 갈래 꺼짐) 404 · 보기 공유 200 · 편집 공유 200 · 인계 뒤 새 소유자 200 · 옛 소유자(공유 없음) 404 ·
                 팀 공간 문서 — 그 팀 200 · 다른 팀 404 · 휴지통 문서 404 · 복원 뒤 200 · 남의 문서 첨부 id 를 내 문서 주소에 → 404 · 올리기는 고칠 수 있는 사람만(보기 공유 403 · 손님 403 · 비로그인 401) · CSRF 없이 403
              ② 검사: 허용 밖 확장자 · 이중 확장자 · 100MB 초과(경계 값) · zip 폭탄 · 매크로 docx · PDF 능동 내용 → 400 + 자료실과 같은 사유 문장 · SCAN_ENABLED=1 일 때 pending → raw/download 423 · infected 403 · clean 200 ·
                 민감정보(합성 주민번호 txt): 사내 문서 400 sensitive_level_required · 팀 문서 201 + sensitive_flags · 민감 첨부 문서를 사내로 넓히기 400 · 게시 400 · 이동으로 넓어짐 400 · 일일 총량: 자료실 + 첨부 합산 경계
              ③ 머리: raw — png/jpeg/gif · clean 만 200 · Content-Type 그림 · CSP sandbox · nosniff · no-store · no-referrer / pdf · txt 를 raw 로 → 404 / download — attachment; filename* · octet-stream · no-store
              ④ 스키마: 본문에 다른 문서의 att: → 저장 400 bad_attachment_ref · 판 복원으로 끌어오기 400 · 모양 틀린 att: 400 · pages_get 의 attachment_titles 는 그 문서 것만
              ⑤ 수명: 영구 삭제(purge_trash 를 시험이 직접 불러) 뒤 첨부 행 0 · 디스크 파일 0(community_purge 스크립트)
              ⑥ 감사: upload/download allow · deny 행 · 원문 · 경로 노출 0
              ⑦ 회귀: qf12_floor_http · qf12_server_http 지금 수 그대로 · stage89 교차 · 정적(security_lint 0 · php -l · node --check · asset_version --check · git diff --check · wiki_check)
보고 형식   : docs/qf12-u3-report.md 1부 — 변경 파일 / 실행한 명령과 출력 원문(결과 폴더 U3-1/) / 못 한 것과 이유 / 확정한 값(디스크 경로 규칙 · 합산 총량 함수 · 사유 코드 목록) / ARCH 24장과 다르게 한 것.
```

## U3-2 화면 — 칸 넓히기 · 칸 편집기 · 오른쪽 칸 하나로 · 바로 올리기

(U3-1 독립 검토 통과 뒤 메인이 시작 신호를 준다. 그 전에는 시작하지 않는다.)

```
목표        : 작업 상세를 어느 화면에서든 오른쪽 칸 하나에서 열고, 그 칸을 넓히고, 칸 안 설명에서도 전체 편집기와 같은 도구로 그림 · 파일을 바로 넣게 한다.
요구사항    : DESIGN 2.20-A 펼침 줄(WK-26) · 2.21 폭 조절 · 칸 편집기 · 알림 색 · 2.24 전부 · 6장 체크리스트 · 기획 15.4 A · B · C 「쓰는 자리」.
수정 파일   : public/assets/community/work.js(업무함 줄 → aside 칸 · div.task-inbox__pane 갈래 삭제 · 폭 손잡이 · 넓게 보기 · 알림 색 · 「파일 첨부」 목록에 attachment_titles) ·
              public/assets/community/editor.js(mountPaneEditor 에 전체 편집기의 도구줄 · 블록 넣기를 같은 함수로 · 붙여넣기 · 끌어놓기 업로드(handlePaste/handleDrop — 파일만) ·
                블록 넣기 그림/파일 창의 「이 문서에 올리기」 탭 · 자리표시 블록) · public/assets/community/editor-schema.js(att: 를 다루는 데 필요하면 최소) ·
              public/assets/community/doc-render.js(att: → /community/api/pages/{id}/attachments/{att}/raw|download) ·
              public/assets/app.css(2.21 손잡이 · 넓게 보기 · .doc-upload · 알림 색 — 새 클래스는 DESIGN 에 적힌 것만) ·
              src/community/handlers/work_home_page.php · my_tasks_page.php(줄 아래 펼침 흔적 정리가 필요할 때만) ·
              커뮤니티 JS import 버전 줄(`php scripts/community_asset_version.php` 인자 없이) ·
              tests/community/qf12_work_browser.py(U2 의 「줄 아래 펼침」 단언을 WK-26 로 고침 + U3 단언) · tests/catalog.json · 위키 wiki/community/guide-work.json(업무 홈 · 내 작업 절의 「줄 아래 펼침」 문장을 「오른쪽 칸」으로 · 첨부 사용법 한 단락)
금지 패턴   : U3-1 과 같음 + innerHTML(붙여넣은 HTML 을 그대로 넣기 0 — 파일만 받는다) · 인라인 style(폭은 CSS 변수 하나만 CSSOM) · 새 색값 · 편집기 두 벌.
완료 기준   : qf12_work_browser(격리 틀) — 지금 단언(WK-26 로 고친 것 포함) + U3: 업무함 줄 누름 → aside 칸(줄 아래 판 0) · 손잡이 끌기 · ←/→ · Home · 두 번 누르기 · 범위 400~70% · 새로고침 뒤 기억 · 넓게 보기 ·
              칸 편집기 도구줄 · 블록 넣기 메뉴 항목 = 전체 편집기와 같은 목록 · 붙여넣기(합성 png 클립보드) → 자리표시 → 그림 블록 · 서버의 첨부 행 1 · 본문 file_ref att: ·
              끌어놓기(파일) → 파일 블록 · 「이 문서에 올리기」 탭 · 허용 밖 파일 → 이유 문장 · 자리표시 사라짐 · pending → 「검사 중」 표시 · 성공 안내 색 ≠ --danger ·
              보기만 하는 사람 · 손님: 입구 셋 0 · 폭 여섯(360 · 412 · 820 · 1199 · 1280 · 1440) × 밝게 · 어둡게 · 고대비 넘침 0 · 콘솔 0 · 누름 자리 < 44 0 · 인라인 style 0(CSSOM 변수 제외) ·
              qf12_shell_browser · messenger_store_browser · messenger_rail_browser 지금 수 그대로 · 정적 전부 0. 스크린샷(1440 넓힌 칸 + 그림이 든 설명 · 412 칸)을 직접 열어 본 뒤 통과라고 쓴다.
보고 형식   : docs/qf12-u3-report.md 2부 — 1부와 같은 형식.
```

## U3-1b 바닥 후속 — 메인 독립 검토에서 나온 것 (2026-10-13)

U3-1(`9703afe`) 보고서 「못 한 것」과 메인 검토 결과. **운영 반영 전에 모두 닫는다.** 같은 worktree · 같은 브랜치(`feat/qf12-u3`)에서. U3-2(화면)는 다른 worktree 에서 동시에 진행되므로 그 파일(`public/assets/**` · `work_home_page.php` · `my_tasks_page.php` · `qf12_work_browser.py` · 위키)은 건드리지 않는다.

```
목표        : 첨부가 정합성 검사 · 탈퇴 파기 · 내보내기 · 다른 쓰기 입구에서도 문서와 한 몸으로 다뤄지게 한다.
요구사항    : 커뮤니티 ARCH 24장 · U3-1 보고서 「못 한 것」 · 아래 표.
수정 파일   : scripts/consistency_check.php(첨부 구역: 표의 stored_name ↔ STORAGE_DIR/attachments 대조 — 자료실과 같은 세 갈래(누락 · 해시 불일치 · 고아) · 영구 삭제 대기열 행은 고아로 세지 않음) ·
              db/migrations/2026-10-13_community_page_attachments.sql(같은 파일 끝에 — 아직 운영 미적용이므로 새 파일을 만들지 않는다) 안에서
                community.privacy_purge_pages 를 첨부까지 지우도록 다시 정의(지금 정의는 2026-10-21_privacy_retention.sql — 같은 서명 · 같은 권한 · 첨부 행은 영구 삭제 대기열로) ·
              탈퇴 파기 스크립트(privacy_purge_pages 를 부르는 cron 스크립트 — 조사해 보고서에 이름)가 대기열의 디스크 파일도 지우게(community_purge.php 와 같은 함수 재사용) ·
              src/community/export_helpers.php(내보내기 · 인쇄 ctx: att: → 로그인 필요한 창구 주소 링크 · 그림은 그 raw 주소 — 자료실 file_href 와 같은 방식) ·
              다른 쓰기 입구: src/community/handlers/pages_create.php · templates_create.php · (게시판 새 글 · AI 초안 · MCP/어시스턴트 쓰기 — 조사해 목록) —
                새 문서는 아직 첨부가 없으므로 att: 이 하나라도 있으면 400 bad_attachment_ref(자료실 파일 거부와 같은 자리) · 기존 문서를 고치는 입구는 SaveDoc 경유인지 확인하고 아니면 같은 검사 ·
              tests/community/qf12_u3_attach_http.py(새 단언) · tests/catalog.json · docs/qf12-u3-report.md(1부 뒤에 「1b」)
금지 패턴   : U3-1 과 같음.
완료 기준   : ① consistency_check — 첨부 정상 → 불일치 0(--strict rc 0) · 첨부 디스크 파일 지움 → 누락 1 · 바이트 바꿈 → 해시 불일치 1 · DB 에 없는 파일을 attachments/ 에 둠 → 고아 1 · 대기열 행 → 고아 아님
              ② 탈퇴 파기: 합성 사용자의 개인 공간 문서에 첨부 → 파기 함수 · 스크립트 → 첨부 행 0 · 디스크 파일 0 · 그 사용자가 팀 공간 문서에 올린 첨부는 남는다(문서가 팀 것이므로 — 파기 규칙 문서와 대조해 보고)
              ③ 내보내기: att: 그림 · 파일 블록이 「볼 수 없는 파일」이 아니라 링크로 나온다 · 공개 링크(/pub/)는 그대로 「볼 수 없는 파일」
              ④ 새 문서 · 템플릿 · 게시판 새 글 · AI · MCP 쓰기에 att: → 400(입구마다 하나씩)
              ⑤ qf12_u3_attach_http 지금 175 + 새 단언 전부 · qf12_floor_http · qf12_server_http 지금 수 · 정적 전부 0
보고 형식   : docs/qf12-u3-report.md 「1b」 — 1부와 같은 형식.
```
