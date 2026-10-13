# 보고 — 지시서 U3 (2026-10-13)

## 1부 — U3-1 보안 바닥: 문서 전용 첨부 (서버)

구현: Opus 에이전트 · worktree `/home/changkwon/qaredi-qf12u3-20261013`(브랜치 `feat/qf12-u3`) · 결과 폴더 `/home/changkwon/qaredi-qf12u3-results-20261013/U3-1/`.
U3-2(화면)는 하지 않았다. push · 발행 · main 합치기 안 함. 커밋 해시는 이 파일이 든 커밋 — 마지막 답에 적는다.
기준 판(작업 시작 HEAD) `3a1cd56`.

### 1. 변경 파일

| 파일 | 내용 |
|---|---|
| `db/migrations/2026-10-13_community_page_attachments.sql`(새) | ① `community.page_attachments`(ARCH 24.2 열 전부 + NOT NULL · CHECK — 이름 1~255자 · ext 형태 · 크기 1~100MB · sha256 형태 · stored_name = UUIDv4.ext 정규식 · scan_status 셋) · 색인 `(page_id)` · `(uploaded_by, created_at)` ② `community.page_attachment_purge_queue`(영구 삭제한 첨부의 디스크 이름 대기열) ③ `community.purge_trash` 지금 몸(2026-10-01_community_suggestions.sql) 글자 그대로 + 두 줄(대기열에 이름 적기 → 첨부 행 지우기) — CREATE OR REPLACE(소유자 · EXECUTE 그대로) ④ 권한: `tbox_app` SELECT · INSERT · **UPDATE(scan_status) 한 칸** · 시퀀스 USAGE — DELETE 0 · 대기열 0 / `tbox_backup` SELECT / tbox_perm · tbox_push 0 ⑤ 슈퍼유저로 돌렸을 때만 소유자를 tbox_owner 로 |
| `db/community/schema.sql` | 같은 두 표(새 설치). ★ Q:F-12 업무 화면 절 **앞**에 둠 — qf12_floor_http 의 「Q:F-12 전 schema + 마이그레이션 = 새 설치」 비교가 그 절 앞을 기준 판으로 자르기 때문(두 쪽 모두 이 표를 가져 그 비교가 그대로 SAME) |
| `db/community/grants.sql` | 끝 절 — 마이그레이션 4 절과 같은 권한(표 없으면 NOTICE 후 건너뜀) |
| `src/community/CommunityAuthz.php` | `decideAttachmentRead($u,$pageRow,$attRow)` — ① `attRow.page_id === pageRow.id` 아니면 `not_found` ② `decidePageRead` 를 부름 · 관리자 특권 갈래(`admin_all` · `board_moderator`)는 `privileged_only` 로 닫음(내보내기 `decidePageExport` 와 같은 코드). `decideAttachmentUpload($u,$pageRow)` — `decidePageChange` + `Authz::canCommunityWrite` AND. 그 밖 판정 0 |
| `src/community/routes.php` | COMMUNITY_ROUTES 세 줄(`POST /api/pages/{id}/attachments` community.write · `GET …/attachments/{gid}/raw` · `…/download` community.read — `{gid}` 는 기존 UUID 자리표시자를 다시 씀) · 첨부 절: `community_attachment_row`(page_id AND public_id) · `community_attachment_refs_ok` · `community_attachments_sensitive` · `community_attachment_wide`(「사내 · 게시됨」 넓이 — `baseVisibility` 를 읽기만) · `community_attachment_gate`(raw/download 한 벌 관문 · 감사) · `community_attachment_titles` · 상수 `COMMUNITY_ATTACHMENT_REF_REFUSED` 등 |
| `src/community/handlers/attachments_upload.php`(새) | 문서 행 → 판정(못 고침: 읽을 수 있으면 403 · 아니면 404) → 파일 · 이름 정리 → `Upload::validate` → `Upload::checkDailyQuota` → `Sensitive::scan` → 트랜잭션(문서 행 잠금 `COMMUNITY_PAGE_LOCK_SQL` → **잠근 뒤** 판정 · 넓이 다시 → INSERT → `Storage::put(…, AREA_ATTACHMENTS)` → 감사 → COMMIT, 실패 시 파일 회수) → 201 `{id,name,size,image,scan_status}` |
| `src/community/handlers/attachments_raw.php` · `attachments_download.php`(새) | 관문 한 벌 뒤 `Storage::streamInline` / `Storage::stream`(같은 함수 · 끝 인자 구역만) |
| `src/Storage.php` | 상수 `AREA_ATTACHMENTS='attachments'` · `pathFor/assertExists/put/removeUploaded/stream/streamInline/purge/sha256` 에 끝 인자 `$area`(기본 '' = 자료실 경로 글자까지 같음 · 상수 밖 값은 예외) — 새 함수 0 |
| `src/Upload.php` | `checkDailyQuota` SQL 한 곳이 `files` + `community.page_attachments` 합산 |
| `src/Audit.php` | 문장 네 줄: `attachment.upload|allow` · `attachment.upload|deny|refused` · `attachment.download|allow` · `attachment.download|deny|refused` |
| `scripts/scan_pending.php` | 첨부 표의 pending 도 같은 clamscan 한 번 · 같은 격리 · 같은 순서(바이트 먼저 → UPDATE+감사) · 감사 `file.scan`(target_type `page_attachment`) · 표 없으면 건너뜀 · --limit 은 두 표 합 |
| `scripts/community_purge.php` | `--run` 때 대기열 → `Storage::purge(이름, AREA_ATTACHMENTS)` → 대기열 줄 지움 · dry 는 건수만 · `page.purge allow` detail `attachment_files` · 실패 `page.purge error purge_failed` · 소유자 역할 이름 `TBOX_OWNER_DB_USER`(기본 tbox_owner, 형태 검사 — 합성 시험 DB 용) |
| `public/assets/community/doc-schema.json` | 그림 · 파일 `file_ref` 모양 `tbox-ref` → `tbox-ref|att-ref`(원본 `/var/www/qaredi/community/doc-schema.json` 동기화는 메인 몫 · `clients/mcp/…` 0) |
| `src/community/Doc.php` | 모양 `tbox-ref|att-ref`(`^(tbox|att):<uuid>$`, 옛 `tbox-ref` 갈래도 남김) · `extract()` 에 `atts`(자료실 `files` 와 따로) |
| `src/community/SaveDoc.php` · `handlers/version_restore.php` | 새로 들어온 `att:` 이 이 문서 첨부인지(`community_attachment_refs_ok`) — 아니면 400 `reason: bad_attachment_ref` |
| `handlers/pages_visibility.php` · `pages_publish.php` | 트랜잭션 안에서 대상 행(넓히기는 「하위까지 함께」로 바뀌는 하위 포함)을 **먼저 잠그고** 민감 첨부가 있으면 400 `sensitive_level_required` |
| `handlers/pages_move.php` | 옮긴 뒤 「사내 · 게시됨」 넓이 문서에 민감 첨부가 있으면 400(아래 5절 — 방어 갈래) |
| `handlers/pages_get.php` | `attachment_titles` 한 키 — 그 문서 첨부 `{name,size,image,scan_status}`(받을 수 있는 사람에게만, 관리자 특권 열람은 `{}`) |
| `src/community/DocRender.php` · `DocMarkdown.php` | `att:` → `/community/api/pages/{id}/attachments/{att}/raw|download`(ctx `attachment_titles` · `attachment_page`) · 공개 링크 ctx 「볼 수 없는 파일」(첨부 id 0) · 내보내기 · 인쇄는 ctx `attachment_href` 가 있을 때만 링크, 없으면 「볼 수 없는 파일」 |
| `tests/community/qf12_u3_attach_http.py`(새) · `memories_harness.py`(choices · migrations 두 자리) · `tests/catalog.json`(새 항목 `community.qf12_u3_attach_http`) | 수락 시험 |
| `docs/qf12-u3-report.md`(새) | 이 보고서 |

`Http.php` · `Authz.php` · `public/index.php` · `Auth.php` · `Db.php` 0.

### 2. 실행한 명령과 출력 (원문 = 결과 폴더 `U3-1/`)

| 명령 | 결과 | 원문 |
|---|---|---|
| `python3 tests/community/memories_harness.py --suite qf12_u3_attach_http.py --output …/U3-1/accept-final` | **pass 175 · fail 0** (rc 0) | `accept-final.log` · `accept-final/qf12-u3-attach-results.json`(단언 175 + 확정 값) · `scan_pending.stdout.txt` · `community_purge.stdout.txt` · `u3-shape-*.txt` |
| 같은 명령(첫 실행 — 마지막에 기록 한 줄 더하기 전) | pass 175 · fail 0 | `accept.log` · `accept/` |
| `… --suite qf12_floor_http.py` | **pass 267 · fail 0** — 기준 판 `3a1cd56` 복사본 267/0 과 같은 수 | `floor.log` · `baseline-floor.log` |
| `… --suite qf12_server_http.py` | **pass 204 · fail 0** — 기준 판 204/0 과 같은 수 | `server.log` · `baseline-server.log` |
| `STAGE89_CROSS_OUTPUT=… bash tests/stage89_cross_regression/run.sh` | **PASS=196 FAIL=0** (rc 0 · 포트 8548) | `stage89-cross.log` |
| `bash scripts/security_lint.sh` | 통과 (금지 패턴 0건) | `static.log` |
| `php -l`(바뀐 · 새 PHP 19개) | 전부 No syntax errors | `static.log` |
| `node --check public/assets/community/*.js` | 실패 0 (JS 변경 0) | `static.log` |
| `php scripts/community_asset_version.php --check` · `bash tests/community/asset_version.sh` | rc 0 · 통과 37 실패 0 | `static.log` |
| `git diff --check`(작업 트리) · 커밋 직전 `git diff --cached --check` | 0 | `static.log` · 커밋 로그 |
| `bash tests/wiki_check.sh` | pass=1 fail=0 | `static.log` |

기준 판 비교는 `git archive 3a1cd56` 을 결과 폴더에 풀어 같은 harness 로 돌렸다(끝난 뒤 지움). 100MB 경계 파일도 결과 폴더에 만들고 지웠다.

수락 시험이 실제로 확인한 것(단언 이름은 결과 JSON 원문):

- ① 판정 — 비공개 문서: 소유자 200 · 다른 팀 직원 404 · 같은 팀 Manager(공유 없음) 404 · Admin(팀 없음, 문서 자체는 admin_all 로 200) raw/download 404 · `attachment_titles` `{}` · 비로그인 401 · 보기 공유 200 · 편집 공유 200 · 공유 회수 뒤 404 · 인계(keep none) 뒤 새 소유자 200 · 옛 소유자 404 · 팀 공간 — 그 팀 200 · 다른 팀 404 · 팀 아닌 Admin 404 · 휴지통 404 → 복원 200 · 남의 문서 첨부 id 를 내 문서 주소에 404(두 방향) · 거부 · 없는 첨부 · 없는 문서의 404 본문 동일 `{"error":"찾을 수 없습니다"}` · 올리기: 보기 공유 403 · 손님 403 · 비로그인 401 · CSRF 없이 403 · Admin(admin_all) 403 · 못 읽는 문서 404 · 휴지통 문서 404
- ② 검사 — `ext_not_allowed` · `double_extension` · `zip_bomb`(40MB 0 바이트 압축) · `macro_found`(docx + vbaProject.bin) · `pdf_active_content` · `mime_mismatch` → 400, 문장이 같은 파일을 자료실 웹 업로드에 보낸 응답과 **글자 그대로 같음** · 정확히 104,857,600 B → 201(raw 전체 길이 200) · +1 B → 400 `too_large` · SCAN_ENABLED=1 → pending · raw/download 423 + Retry-After 30 · infected 403 「악성코드가 발견되어 격리된 파일입니다」 · clean 200 · **실제 `/usr/bin/clamscan`** 으로 `scan_pending.php --force-enabled` — EICAR 첨부 infected + 격리 폴더로 이동 · 깨끗한 첨부 clean · 감사 `file.scan deny infected`/`allow`(target_type page_attachment) · 표준출력에 디스크 이름 · 시그니처 0(clamscan 종료 코드 1 = 발견 · 24.1초) · 민감정보(체크 숫자가 맞는 합성 주민번호 txt): 사내 문서 400 `sensitive_level_required`(자료실 internal 업로드와 같은 문장) · 팀 범위 문서 201 + `sensitive_flags={rrn}` · 비공개 201 · 팀 공간 201 · 사내로 넓히기 400(범위 그대로) · 「하위까지 함께」로 넓히는데 하위에 민감 첨부 400 · 게시 400 · 게시된 문서에 민감 업로드 400 · 이동 방어 갈래 400 · 일일 총량: B — 자료실 행으로 2GB−100B 채움 → 첨부 101B 400 `quota_exceeded` · 100B 201 / C — 첨부 행으로 채움 → 자료실 웹 업로드 101B 400(같은 문장) · 100B 201
- ③ 머리 — raw png/jpeg/gif: 200 · Content-Type 그 그림 · `Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'self'` · nosniff · no-store · `Referrer-Policy: no-referrer` · inline · 바이트 같음 / pdf · txt raw 404 / download: `application/octet-stream` · `attachment; filename="download.txt"; filename*=UTF-8''%EB%A9%94%EB%AA%A8.txt` · no-store · nosniff
- ④ 스키마 — 자기 첨부 `att:` 그림 · 파일 저장 200 · 다른 문서 `att:` 400 `bad_attachment_ref` · 없는 id 400 · 모양 틀림 넷(`att:xyz` · 대문자 · `attach:` · 꼬리 붙음) 400 `invalid_doc` + `bad_ref` · 감사 `page.update deny file_ref` cause `bad_attachment_ref` ×2 · 다른 문서 `att:` 이 든 판(SQL 로 심음) 복원 400 · 자기 첨부 판 복원 200 · `attachment_titles` = 그 문서 첨부 집합과 정확히 같음(남의 것 0 · 키 넷) · DocRender 창구 주소 · 공개 ctx 「볼 수 없는 파일」 ×2 + 첨부 id 0 · 지도 없음 「볼 수 없는 파일」 · DocMarkdown 「볼 수 없는 파일」
- ⑤ 수명 — 휴지통 → `purge_after` 과거로 → **시험이 직접** `community.purge_trash(false,500)` → 그 문서 첨부 행 0 · 대기열에 이름 · 디스크 파일은 아직 있음 → `community_purge.php`(dry: 파일 그대로 · 「지울 첨부 파일: 2개」) → `--run`: 디스크 파일 0 · 대기열 0 · 표준출력에 이름 · 경로 0 · 감사 `page.purge allow` `attachment_files=2`
- ⑥ 감사 — 최종 수 `upload allow 23 · upload deny 14 · download allow 15 · download deny 23` · deny 의 reason 은 `refused` 하나 · detail.cause 원문(`not_found · not_image · not_member · not_owner · privileged_only · scan_infected · scan_pending · double_extension · ext_not_allowed · macro_found · mime_mismatch · pdf_active_content · sensitive_level_required · too_large · zip_bomb`) · 감사 DB(detail · summary · target_name) + `audit.log` + php 서버 로그에 첨부 디스크 이름 0(전부 대조) · 저장소 경로 0 · 합성 주민번호 원문 0 · 민감은 `{"rrn": 1}`(이름 → 건수)만
- 권한 — `has_*_privilege`: tbox_app DELETE false · INSERT true · UPDATE(scan_status) true · UPDATE(original_name · page_id) false · 대기열 SELECT false · tbox_backup 두 표 SELECT true · 앱 계정으로 DELETE / UPDATE(original_name) 실제 실행 → `permission denied`
- 새 설치 = 마이그레이션 — 임시 DB 둘(새 설치 schema.sql+grants / 이 절을 뺀 schema + 마이그레이션×2 + grants) 열 · 제약 · 색인 · 표 권한 · **열 권한** · 시퀀스 1,603줄 **SAME** · 비교 DB 지움

### 3. 못 한 것과 이유 (미검증 포함)

| 항목 | 상태 · 이유 |
|---|---|
| **`scripts/consistency_check.php` 가 첨부 파일을 「고아」로 셈** | **운영 반영 전 막는 것.** 이 스크립트는 저장소 전체를 훑어 `files`·`memory_media` 에 없는 이름을 고아로 센다. 수락 시험 끝에 기록만 해 둠: 첨부가 있는 상태에서 `--strict` 종료 코드 **2**(출력의 orphan 언급 20). 허용 목록 밖 파일이라 고치지 않았다 — `community.page_attachments`(살아 있는 것 · 격리 · 대기열) 갈래가 필요하다 |
| 탈퇴 파기 `community.privacy_purge_pages`(2026-10-21) | 첨부 행 · 파일을 지우지 않는다(「purge_trash 와 같은 목록 — 표가 늘면 둘 다 고친다」 주석). 허용 목록 밖 — 다음 조치 |
| 내보내기 · 인쇄(`export_helpers.php`) | 렌더러는 `attachment_titles` · `attachment_href` ctx 를 받으면 링크를 그리지만, ctx 를 만드는 `community_export_context` 는 허용 목록 밖이라 지금 내보내기 · 인쇄에서 `att:` 은 「볼 수 없는 파일」(닫힌 쪽). 페이지 화면(`doc-render.js`)도 U3-2 몫 |
| 다른 쓰기 입구의 `att:` 검사 | `pages_create`(새 문서 본문) · 템플릿 · 게시판 새 글 · AI 넣기 · MCP 는 `att:` 을 모양만 본다(허용 목록 밖). 남의 첨부 id 를 넣어도 받기는 `page_id` 조건으로 404 라 노출은 0 이지만 깨진 참조가 남을 수 있다. 동기화 블록이 다른 문서의 `att:` 을 끼워 보여 줄 때도 404(그림 깨짐) |
| 편집기(`editor-schema.js:886`) | 저장할 때 `tbox:` 아닌 `file_ref` 블록을 버린다 — U3-2 범위 |
| `SECURITY.md` C:R(24.7) · ARCH 7.4 문장 표 · `community/doc-schema.json` 원본 동기화 | 허용 목록 밖 — 메인 몫 |
| 배포 순서 | `Upload::checkDailyQuota` 가 새 표를 읽으므로 **마이그레이션 → 코드** 순서여야 한다(반대면 자료실 업로드도 500). `db/community/schema.sql` 을 싣지 않는 옛 t-box 단독 시험 틀(f65.sh (g) 의 schema 확인 갈래는 업로드를 하지 않음을 확인 — 다른 틀은 미확인)에서 HTTP 업로드를 하면 같은 이유로 실패한다 |
| 일일 총량 동시 요청 | 두 업로드가 동시에 한도를 넘는 겹침은 자료실과 같다(기존 동작 · 이번에 바꾸지 않음) — 미검증 |
| 운영 cron 실제 계정(root · `/etc/tbox-v2/owner.pgpass`)으로 `community_purge.php` | 미검증 — 합성 DB 에서 `TBOX_OWNER_DB_USER=tbox_testdb` · 임시 pgpass 로만 실행 |
| 백업 · 복원이 `attachments/` 하위 폴더를 싣는지 | 미검증(저장소 폴더 통째 백업이면 따라가지만 실행해 보지 않음) |
| nginx 101m · php-fpm 실제 운영 경로로 100MB | 미검증 — 격리 틀(php -S, upload_max_filesize 100M)로만 |

### 4. 확정한 값

- **디스크 경로 규칙**: `STORAGE_DIR/attachments/<stored_name 앞 2글자>/<UUIDv4>.<ext>` — 이름은 `Storage::newStoredName` 난수뿐(원래 이름은 표 `original_name` 에만) · 파일 0640 · 폴더 0750 · 경로는 `Storage::pathFor($name, Storage::AREA_ATTACHMENTS)` 한 곳(이름 정규식 + realpath 로 저장소 안 확인). 자료실은 `aa/bb/` 두 층 그대로, 첨부는 ARCH 24.2 글자대로 한 층
- **합산 총량 함수**: `Upload::checkDailyQuota(int $userId, int $newSize)` — SQL 한 문장이 `files` 24시간 합 + `community.page_attachments` 24시간 합, 한도 `Upload::DAILY_QUOTA_BYTES`(2,147,483,648). 자료실 업로드 · 첨부 올리기 둘 다 이 함수
- **사유 코드 · 상태**
  - 올리기 400(`{"error": Http::reasonMessage(code), "reason": code}`): `no_file` · `bad_encoding` · `upload_error` · `not_uploaded_file` · `empty_file` · `size_unreadable` · `too_large` · `ext_not_allowed` · `double_extension` · `mime_mismatch` · `zip_unreadable` · `zip_bad_path` · `zip_blocked_entry` · `nested_zip` · `zip_bomb` · `macro_found` · `pdf_active_content` · `hwp_not_hwp` · `hash_failed` · `quota_exceeded` · `sensitive_level_required` / 403 `MSG_FORBIDDEN`(판정 — 읽을 수는 있음) / 404(못 읽음 · 없음)
  - 받기: 404(판정 거부 · 없음 · 남의 문서 첨부 · raw 인데 그림 아님) · 423 `MSG_SCAN_PENDING` + Retry-After 30 · 403 `MSG_SCAN_INFECTED` · 403(실행 내용 표식 — 첨부에는 생기지 않음) · 500(바이트 없음 · sha256 다름)
  - 본문: 400 `reason: bad_attachment_ref`, 문장 `COMMUNITY_ATTACHMENT_REF_REFUSED` / 모양은 `invalid_doc` + `bad_ref`
  - 넓히기 · 게시 · 이동: 400 `reason: sensitive_level_required`, 문장 `Http::MSG_SENSITIVE_LEVEL_REQUIRED`
  - 감사 detail.cause(deny): 판정 코드(`not_found` · `not_owner` · `not_member` · `privileged_only` · `role_not_permitted` · `not_author` …) · 검사 코드(위 400 목록) · `not_image` · `scan_pending` · `scan_infected` · `exec_content` · `file_missing` · `hash_mismatch`
- **표 · 권한**: 위 1절 · `tbox_app` UPDATE 는 열 권한 `scan_status` 하나
- **응답**: 201 `{id, name, size, image, scan_status}` · `attachment_titles` 값 `{name, size, image, scan_status}` — `image` = mime 이 png/jpeg/gif

### 5. ARCH 24장과 다르게 한 것 (이유)

1. **관리자 특권 갈래를 첨부에 열지 않음** — 24.1 은 「`decidePageRead` 를 그대로」인데, 지시서 ① 「Admin(관리 갈래 꺼짐) 404」 · 기획 15.5 「비공개 문서 첨부를 Admin 도 못 받음」을 따라 `admin_all` · `board_moderator` 로만 읽히면 `privileged_only`(404). 문서 판정을 복제하지 않고 그 결과 코드만 거른다(`decidePageExport` 와 같은 방식). 게시판 관리자의 숨긴 글 첨부도 같이 닫힘 — 메인 확인 필요
2. **올리기에 `canCommunityWrite` AND** — 손님 자기 공간의 「주인」 갈래가 참이어도 닫힘(라우트 정책과 같은 표 · pages_get `can_edit` 와 같은 AND)
3. **영구 삭제 대기열 표 하나 더**(`page_attachment_purge_queue`) — 24.6 「행을 지우기 전에 경로 목록을 받아 두는 순서」와 지시서 ⑤ 「purge_trash 를 시험이 직접 부르고 → 디스크는 스크립트」를 함께 만족하려면 함수가 지운 행의 이름을 어딘가에 남겨야 한다. 함수의 반환 모양을 바꾸면(DROP 필요) 소유자 · 권한이 흔들려서 표로 했다. 앱 권한 0
4. **감사 거부는 reason 하나(`refused`) + detail.cause** — 문장 표는 `action|result|reason` 키라 사유마다 줄이 필요한데 지시서가 「네 줄」로 정했다. 사유 원문은 `detail.cause` 에 있다. 본문 거부는 기존 문장 `page.update|deny|file_ref` · `page.restore_version|deny|file_ref`, 넓히기 · 게시 거부는 기존 `…|deny|file_narrower` 를 쓰고 cause 로 가른다(새 문장 0)
5. **이동은 방어 갈래** — 지금 규칙에서 옮기기는 범위를 바꾸지 않는다(같은 공간만 · 4-B 트리 불변 규칙). 그래서 「이동으로 넓어짐」은 올리기 · 넓히기 · 게시가 막는 상태가 다른 길로 생겼을 때만 닿고, 시험은 그 상태를 SQL 로 심어 400 을 확인했다. 형식 거부라 감사 문장 없이 서버 로그 한 줄(이 창구의 `db_in_db` 와 같은 태도)
6. **경쟁 막기** — 올리기 · 넓히기 · 게시가 같은 문서 행을 먼저 잠그고(넓히기는 함께 바뀌는 하위까지), 올리기는 잠근 뒤 판정 · 넓이를 다시 본다. ARCH 에 없던 순서다
7. **공개 링크 문구** — 자료실 그림은 지금처럼 「로그인이 필요한 파일」, `att:` 만 「볼 수 없는 파일」(지시서 글자)
8. 400 응답에 `reason` 코드를 함께 실음(화면 U3-2 가 문장을 고르지 않고 그대로 보이되 갈래를 알 수 있게) · 표 열을 NOT NULL + CHECK 로 더 좁힘 · `Http.php` 사유 표에 `bad_attachment_ref` 를 더하지 않음(그 표는 ARCH 5.30 과 글자 대조되는 표라 — 문장은 routes.php 상수)
9. Storage 는 `putLocal` 이 아니라 `put`(HTTP 업로드 · `move_uploaded_file`)을 같은 함수로 재사용 — 첨부는 HTTP 업로드라 `putLocal`(가져오기 임시 폴더 전용)의 출처 검사와 맞지 않는다. routes.php 의 「일일 총량 합산 도우미」는 만들지 않았다(합산이 `Upload::checkDailyQuota` 한 곳이라 감싸는 함수가 필요 없다)
