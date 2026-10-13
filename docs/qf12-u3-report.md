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

## 1b — U3-1b 바닥 후속: 정합성 검사 · 탈퇴 파기 · 내보내기 · 다른 쓰기 입구

구현: Opus 에이전트 · 같은 worktree · 같은 브랜치(`feat/qf12-u3`) · 시작 HEAD `fe2305f` · 결과 폴더 `/home/changkwon/qaredi-qf12u3-results-20261013/U3-1b/`.
U3-2 쪽 파일(`public/assets/**` · `work_home_page.php` · `my_tasks_page.php` · `qf12_work_browser.py` · 위키)과 U3-2 worktree 는 손대지 않았다. push · 발행 · 합치기 없음.
격리 틀 포트 8768 은 U3-2 에이전트의 실행이 끝나기를 기다렸다가(`ss -ltn`) 썼다.

### 1. 변경 파일

| 파일 | 내용 |
|---|---|
| `db/migrations/2026-10-13_community_page_attachments.sql` | **같은 파일 끝**(새 파일 0)에 6 · 7 절 — 6: `community.privacy_purge_pages(bigint[])` 를 같은 서명 · `RETURNS bigint` · `SECURITY INVOKER` · 같은 search_path 로 다시 정의(몸은 2026-10-21 글자 그대로 + 첨부 두 줄 — purge_trash 와 같은 「대기열에 이름 먼저 → 행 삭제」) · `REVOKE … FROM PUBLIC, tbox_app` · 슈퍼유저로 돌릴 때만 소유자 tbox_owner. 7: 정합성 검사용 `community.page_attachment_queued(text[])`(아래 5-2) · 머리 주석 · 되돌리기 줄 |
| `db/community/schema.sql` | U3-1 절(Q:F-12 업무 화면 절 앞)에 `page_attachment_queued` 함수 — 마이그레이션 7 절과 같은 문장(새 설치). `privacy_purge_pages` 는 원래 schema.sql 에 없는 함수(마이그레이션 전용)라 더하지 않음 |
| `db/community/grants.sql` | 끝 절 — `page_attachment_queued` EXECUTE 는 tbox_app 하나(PUBLIC · tbox_backup 회수, 함수 없으면 NOTICE 후 건너뜀). `privacy_purge_pages` 권한은 `db/grants.sql` 의 기존 「탈퇴 요청 · 보관 기간 파기」 절이 그대로 건다(바꿀 것 0) |
| `scripts/consistency_check.php` | 첨부 구역: `community.page_attachments`(살아 있음 = 문서가 휴지통 · 영구 삭제 아님) ↔ `STORAGE_DIR/attachments/**` 를 자료실과 **따로** 대조. 행 대조를 함수 `consistency_compare_rows()` 하나로 묶어 자료실 · 첨부가 같은 규칙(① 누락 ② 해시 불일치 ③ 감염 행의 격리 규칙 · 휴지통 바이트 없음은 보고만)을 탄다 — 자료실 갈래의 판단 · 순서 · 출력 모양은 그대로. 고아: 제자리(`attachments/<앞 2>/<이름>`)가 아니거나 행도 대기열 이름도 아닌 파일. 영구 삭제 대기열 이름은 고아 아님(`attachment_queued` 로 보고). 자료실 훑기는 `attachments/` 아래를 보지 않음. 감염 첨부(격리 폴더)는 `quarantine_orphan` 에서 빠짐. 불일치 항목에 `area: attachments` · 출력 줄 `, area=attachments` · detail 에 `attachment_rows · attachment_disk_files · attachment_queued · attachment_trash_missing` |
| `scripts/lib/attachment_purge.php`(새) | `attachment_purge_drain(PDO $owner, bool $run)` — 대기열 → `Storage::purge(이름, AREA_ATTACHMENTS)` → 지운 뒤에야 대기열 줄 삭제 · dry 는 건수만 · 이름 · 경로는 반환 · 출력 0. U3-1 의 community_purge.php 고리를 그대로 옮긴 것 |
| `scripts/community_purge.php` | 위 함수를 부름(출력 문장 · 감사 `page.purge` detail 그대로) |
| `scripts/privacy_purge.php` | **탈퇴 파기 cron 스크립트**(조사 결과: `deploy/cron.d/tbox-v2` 04:40 `root php …/scripts/privacy_purge.php --run` — `public.privacy_purge_accounts` → `community.privacy_purge_pages` 를 부르는 유일한 곳). ② 를 커밋한 **뒤** `attachment_purge_drain()` 로 대기열을 비움 · 출력 「문서 첨부 파일: N개 지움」(dry 는 「[dry] 대기열의 문서 첨부 파일: N개」) · `privacy.purge allow` detail `attachment_files` · 실패는 `privacy.purge error purge_failed` detail `attachment_failures` + 종료 1 |
| `src/community/export_helpers.php` | `community_export_context(…, ?array $page = null)` — 문서 행이 오면 `decideAttachmentRead`(관리자 특권 갈래 닫힘 — pages_get 과 같은 조건)일 때만 본문이 가리키는 첨부의 `attachment_titles` 와 `attachment_href`(`community_export_url(Http::communityUrl('/api/pages/{문서}/attachments/{첨부}/raw|download'))` — 그림 형식이면 `/raw`, 그 밖 `/download`) |
| `src/community/handlers/pages_export.php` · `page_print.php` | 위 함수에 막 다시 읽고 판정한 문서 행(`community_export_document` 의 `$row`)을 넘기는 한 줄씩 |
| `src/community/handlers/pages_create.php` | 첫 본문의 `att:` 이 하나라도 있으면 400 `{error: COMMUNITY_ATTACHMENT_REF_REFUSED, reason: bad_attachment_ref}` — 자료실 파일 거부(`file_ref`) 바로 뒤 자리. 직접 본문 · 템플릿 본문 · 프로그램 토큰(MCP) 모두 이 자리를 지남 |
| `src/community/handlers/templates_create.php` | `Doc::templateBody` 뒤 `att:` 이 남으면 400 `bad_attachment_ref`(방어 줄 — 아래 5-4) |
| `tests/community/qf12_u3_attach_http.py` | U3-1b 단언 74개(아래) · 머리 주석 |
| `tests/catalog.json` | `community.qf12_u3_attach_http` covers 에 바뀐 파일 · 입구 파일 · notes 에 U3-1b · est_sec 330 |

#### 다른 쓰기 입구 조사 결과 (목록)

| 입구 | 본문이 들어오는 길 | 조치 · 확인 |
|---|---|---|
| `POST /api/pages`(세션) | 요청 `doc` · `template_id`(템플릿 본문) | pages_create.php 400 `bad_attachment_ref` — 시험 |
| `POST /api/pages`(프로그램 토큰 = **MCP** `qaredi_create_page` · `clients/mcp` `create_page`) | 요청 `doc` | 같은 자리 — 시험(토큰 `community.read+community.write`) |
| `POST /api/templates` | 원본 문서 본문 → `Doc::templateBody` | 그림 · 파일 블록을 뺀다(자료실 파일과 같음 · `dropped_blocks`) + 방어 400 — 시험(빠짐 확인 · 심은 템플릿 → 새 문서 400) |
| `POST /api/boards/{num}/pages`(게시판 새 글) | **본문을 받지 않음**(허용 키 `title · tags` — 빈 문단으로 만들고 저장은 PATCH) | 바꿀 것 0 — `doc` 를 보내면 400 시험 |
| `POST /api/pages/{id}/ai-insert`(문서 AI 「초안을 문서에 넣기」) | AI 글을 **문단 글자로만** 붙여 `community_save_doc` 경유 | 바꿀 것 0 — `doc` 키 400 · `att:<id>` 글자가 든 AI 출력 → 문단 글자(file_ref 0) 시험 |
| `PATCH /api/pages/{id}` · 제안 받아들이기 · AI 넣기 | `community_save_doc`(SaveDoc) | U3-1 검사 그대로 |
| 판 복원 | version_restore.php | U3-1 검사 그대로 |
| 서버가 만드는 본문 | `space_me` · 양식 응답(forms) · 연결기(Integrations) · 자동화 `create_page`(`Doc::templateBody` 거침) | 빈 문단 · 그림/파일 블록 뺌 — `att:` 0 |
| 원격 MCP(`src/mcp`) · 비서(`src/assistant`) | 사진 기록 · 여행만 / 커뮤니티 문서 쓰기 없음 | 해당 없음 |

### 2. 실행한 명령과 출력 (원문 = 결과 폴더 `U3-1b/`)

| 명령 | 결과 | 원문 |
|---|---|---|
| `python3 tests/community/memories_harness.py --suite qf12_u3_attach_http.py --output …/U3-1b/accept-final` | **pass 249 · fail 0** (rc 0) = U3-1 의 175 그대로 + U3-1b 74. 커밋한 트리 그대로(머리 주석만 고치기 전 판 `accept/` 도 249/0) | `accept-final.log` · `accept-final/qf12-u3-attach-results.json` · `accept-final/privacy_purge.stdout.txt` · `accept-final/community_purge.stdout.txt` · `accept.log` |
| 같은 명령 — 첫 실행 | pass 246 · fail 4(시험 쪽 잘못: md 내보내기는 `.` 을 `\.` 로 막는다 · 공간 웹 게시 허용 창구는 Admin 재인증이 필요 → 준비를 SQL 로) — 고친 뒤 위 249/0 | `accept1.log` · `accept1/` |
| `… --suite qf12_floor_http.py` | **pass 267 · fail 0** — U3-1 기준 수와 같음 | `qf12_floor_http.log` |
| `… --suite qf12_server_http.py` | **pass 204 · fail 0** — 같음 | `qf12_server_http.log` |
| `… --suite memories_http.py`(consistency_check 를 부르는 옛 시험) | pass 122 · fail 0 | `memories_http.log` |
| `. env.sh; … --suite qf13_retention_http.py`(privacy_purge.php 의 옛 시험) | pass 147 · fail 1 — 실패는 `MIG base (main) schema has no account_deletion_requests table`(`git show main:db/schema.sql` 이 Q:F-13 합친 뒤라 이미 표를 가짐 — 시점에 기대는 단언, 이번 변경과 무관). 같은 시험을 `fe2305f` 복사본으로 돌린 기준도 147 통과 뒤 같은 자리(복사본은 git 저장소가 아니라 `git show` 에서 멈춤). 그 앞의 탈퇴 · 파기 · 스크립트 단언 147 은 전부 통과 | `qf13_retention_http.log` · `baseline-qf13_retention_http.log` |
| 같은 retention — 첫 시도 둘 | ① PIL 없음(env.sh 안 읽음) ② 첫 요청 `GET /api/me/space` 401 한 번(다시 돌리니 재현 안 됨 — 원인 미확인) | 덮어써서 원문 없음 |
| `bash scripts/security_lint.sh` | 통과(금지 패턴 0건) | `static.log` |
| `php -l`(바뀐 · 새 PHP 9개) | 전부 No syntax errors | `static.log` |
| `node --check public/assets/community/*.js` | 실패 0(JS 변경 0) | `static.log` |
| `php scripts/community_asset_version.php --check` · `bash tests/community/asset_version.sh` | rc 0 · 통과 37 실패 0 | `static.log` |
| `bash tests/wiki_check.sh` | pass=1 fail=0 | `static.log` |
| `git diff --check` · catalog.json JSON 읽기 | 0 · ok | `static.log` |

U3-1b 단언이 실제로 확인한 것(이름은 결과 JSON 원문):

- **① 정합성 검사** — 시험이 SQL 로 심은 바이트 없는 행(총량 채우기 · 이동 방어)을 걷어 낸 뒤 `--strict` **rc 0 · 불일치 0**(첨부 행 23 · 디스크 22 · 감염 1 은 격리 폴더 = `quarantined` 로 셈, `quarantine_orphan` 0) · 첨부 파일 하나 치움 → `attachments:missing` 1 · rc 2 · 바이트 1 더함 → `attachments:hash_mismatch` 1 · rc 2 · DB 가 모르는 파일 → `attachments:orphan` 1 · 아는 이름을 엉뚱한 하위 폴더에 복사 → `orphan` 1 · 휴지통 → `purge_trash` 로 행만 지워진(대기열) 파일 → **rc 0 · `attachment_queued` 1**(고아 아님) · 함수 권한 `tbox_app EXECUTE · tbox_backup 0 · 대기열 표 SELECT 여전히 0 · SECURITY DEFINER · search_path=community, pg_temp` · 앱 계정으로 함수 → 준 이름 중 대기열에 있는 것만 · 앱 계정으로 대기열 표 SELECT → permission denied · 파기 뒤 다시 rc 0(대기열 0). 정리 전 기록(단언 아님): rc 2 · `files:missing 21 · attachments:missing 22`(심은 행)
- **② 탈퇴 파기** — 함수 직접(소유자): 첨부 행 0 · 이름 대기열 · 파일은 스크립트 차례 · 몸에 대기열이 삭제보다 먼저 · 옛 줄(AI 색인 · 동기화) 그대로 · `RETURNS bigint` · INVOKER · tbox_app EXECUTE 0 / 합성 탈퇴 사용자 B(`claude_manager01` — 개인 문서 둘(하나는 휴지통)에 첨부 3 · 팀 공간 문서 · A 의 문서에 첨부 3): 요청 → 처리 → 31일 당김 → 비활성 → `privacy_purge.php`(TBOX_OWNER_ROLE=tbox_testdb · 임시 0600 pgpass) dry: 행 · 파일 그대로 · 대기열 수 표시 / `--run` rc 0 · 「문서 첨부 파일: 5개 지움」(B 개인 3 + 앞서 대기열의 2) · B 개인 문서 첨부 행 0 · 디스크 0 · 대기열 0 · **팀 공간 · 남의 문서에 올린 B 첨부 3 은 행 + 파일 남음** · 팀원 A 가 그 첨부 download 200 · B 는 `deleted-<번호>` · 개인 문서 「영구 삭제된 페이지」 · 감사 `privacy.purge allow attachment_files=5` · `account.purge allow` · 표준출력에 디스크 이름 · 경로 0. **파기 규칙 문서 대조**: `docs/privacy-terms-plan.md` 5.1 「탈퇴한 회원의 작성물 — 개인 공간의 문서 · 파일 … 30일 뒤 삭제 · 팀 공간 · 게시판 글 · 댓글: 남기고」 · 5.2 「자료실 파일 · 팀 공간 · 게시판 글 · 댓글은 남기고」 — 첨부는 문서를 따르므로 개인 공간(게시하지 않은) 문서의 첨부는 지우고, 팀 공간 · 남의 문서 · 게시한 글의 첨부는 남는다(그 사람이 올렸어도). 일치
- **③ 내보내기** — md · html 둘 다: 그림 `att:` → `{APP_URL}/community/api/pages/{문서}/attachments/{첨부}/raw`, 파일 → `…/download` 링크 · 「볼 수 없는 파일」 0 · 이름 표시 / 인쇄 화면 같은 링크 / 내보낸 그림 주소 소유자 200 · 비로그인 401 / Admin(관리 갈래) 내보내기 403(그대로 닫힘) / 남의 `att:` 을 심은 문서 → 「볼 수 없는 파일」 · id 0 / **공개 링크 `/pub/`** → 「볼 수 없는 파일」 ×2 · 첨부 id · 창구 주소 0
- **④ 쓰기 입구** — 새 문서(세션) 남의 첨부 · 없는 id → 400 `bad_attachment_ref`(저장과 같은 문장) · 문서 0개 생김 / MCP 토큰 → 400 · (대조) 토큰 일반 생성 201 / 템플릿 만들기 → `att:` 블록 빠짐(`dropped_blocks` ≥ 2) · 심은 템플릿으로 새 문서 → 400 / 게시판 새 글 `doc` → 400 · (대조) 201 / AI 넣기 `doc` 키 → 400 · `att:<id>` 글자 AI 출력 → 문단 글자(file_ref 0)
- 감사 — `privacy.purge · account.purge · page.purge` 에 첨부 디스크 이름 · 경로 0 · 감사 전체에 저장소 경로 · 민감 원문 0

### 3. 못 한 것과 이유 (미검증 포함)

| 항목 | 상태 · 이유 |
|---|---|
| 운영 cron 실제 계정(root · `/etc/tbox-v2/owner.pgpass` · tbox_owner)으로 `privacy_purge.php` · `consistency_check.php` | **미검증** — 합성 DB(소유자 tbox_testdb · 임시 pgpass · 앱 계정은 .env 의 계정)로만. 운영 앱 계정이 `page_attachment_queued` 를 EXECUTE 할 수 있는지는 운영 마이그레이션 뒤 `has_function_privilege('tbox_app', …)` 로 볼 것 |
| 마이그레이션 적용 순서 | 이 파일(파일 이름 날짜 10-13)은 **2026-10-21_privacy_retention.sql 뒤에** 돌아야 새 `privacy_purge_pages` 가 남는다. 운영은 10-21 이 이미 적용돼 있어 그대로 맞다. 파일 이름 순서로 처음부터 까는 새 설치(10-13 → 10-21)는 옛 몸이 이긴다 — 그때는 이 파일을 한 번 더(멱등). 합성 틀은 10-21 → 10-13 순서(목록 그대로) |
| 정합성 검사 운영 실행 | 운영에서 돌리지 않음(읽기 전용이지만 운영 감사에 `audit.integrity` 행이 늘고 불일치면 AL-6). 운영 반영 뒤 첫 02:00 결과를 볼 것 |
| `qf13_retention_http` 의 `MIG base (main)` 단언 | 지금 main 기준이라 실패(위) — 이번 변경과 무관 · 고치지 않음(허용 목록 밖) |
| retention 첫 시도의 401 한 번 | 다시 돌려 재현 안 됨 — 원인 미확인(미검증) |
| stage89 교차 회귀 | 이번에 돌리지 않음(지시서 1b 완료 기준에 없음 — 묶음 끝 회귀 몫) |
| 동기화 블록이 다른 문서의 `att:` 을 끼워 보일 때 | U3-1 그대로(창구 404 · 렌더러 「볼 수 없는 파일」) — 이번 범위 밖 |
| 백업 · 복원이 `attachments/` 를 싣는지 | U3-1 과 같이 미검증 |

### 4. 확정한 값

- 내보내기 · 인쇄 링크: `{APP_URL}/community/api/pages/{문서 public_id}/attachments/{첨부 public_id}/raw`(mime png/jpeg/gif) · `…/download`(그 밖). 지도는 본문이 가리키는 첨부만 · `decideAttachmentRead` 가 열릴 때만
- 새 문서 · 템플릿 거부: 400 `{"error": COMMUNITY_ATTACHMENT_REF_REFUSED, "reason": "bad_attachment_ref"}`(저장 · 판 복원과 같은 글자) · 형식 거부라 감사 0(자료실 `file_ref` 거부와 같음)
- 정합성 검사 detail 새 키: `attachment_rows · attachment_disk_files · attachment_queued · attachment_trash_missing` · 불일치 항목 `area: "attachments"` · 종류는 자료실과 같은 `missing · hash_mismatch · orphan`(+ 감염 갈래 `infected_in_storage · quarantine_missing · quarantine_hash_mismatch · quarantine_unreadable`)
- 함수: `community.page_attachment_queued(p_names text[]) RETURNS SETOF text` — LANGUAGE sql · STABLE · SECURITY DEFINER · `SET search_path = community, pg_temp` · EXECUTE tbox_app 만 / `community.privacy_purge_pages(p_ids bigint[]) RETURNS bigint` — 서명 · 권한 그대로
- 탈퇴 파기 스크립트 출력: `문서 첨부 파일: N개 지움[ · 실패 M개(다음 실행이 다시)]` · dry `[dry] 대기열의 문서 첨부 파일: N개(…)` · 감사 `privacy.purge allow detail.attachment_files` · `privacy.purge error purge_failed detail.attachment_failures`

### 5. 지시서와 다르게 한 것 (이유)

1. **새 파일 `scripts/lib/attachment_purge.php`** — 「community_purge.php 와 같은 함수 재사용」을 글자대로 지키려면 두 스크립트가 함께 부를 자리가 필요하다(스크립트끼리는 서로 require 할 수 없다 — 실행된다). U3-1 의 고리를 그대로 옮겼다
2. **새 DB 함수 `community.page_attachment_queued(text[])`(SECURITY DEFINER)** — 정합성 검사는 앱 계정(tbox_app)으로 돌고, U3-1 에서 대기열 표의 앱 권한을 0 으로 정했다. 「대기열 행은 고아가 아님」을 지키면서 앱 권한을 넓히지 않으려고, 저장소에서 찾은 이름의 **소속만** 묻는 읽기 함수를 두었다(목록을 꺼낼 수 없다 · 쓰기 0 · search_path 고정). 같은 마이그레이션 파일 · schema.sql · community/grants.sql 에 한 벌. 대안(tbox_app 에 대기열 SELECT)은 U3-1 의 「대기열 앱 권한 0」 단언을 깨서 택하지 않았다 — 메인 판단 필요
3. **`pages_export.php` · `page_print.php` 한 줄씩** — 허용 목록은 `export_helpers.php` 였지만 ctx 를 만드는 함수가 문서 행을 받지 않아(본문만), 막 다시 읽고 판정한 행을 넘기는 인자 하나를 더했다
4. **템플릿 입구는 400 이 아니라 「뺌」** — 템플릿 만들기는 원래 `Doc::templateBody` 가 그림 · 파일 블록을 모두 빼므로(자료실 파일과 같은 규칙) `att:` 이 템플릿에 닿지 않는다. 지시서의 「입구마다 400」은 ① 템플릿 본문으로 새 문서를 만드는 길(pages_create — 심은 템플릿으로 400 확인) ② templates_create 의 방어 줄(지금 규칙으로는 닿지 않음)로 지켰다. 게시판 새 글 · AI 넣기는 본문 블록을 받지 않는 창구라 코드를 바꾸지 않고 400(허용 키) · 문단 글자로만 들어감을 시험으로 확인했다
5. **정합성 검사 감사 detail 의 첨부 디스크 이름** — 불일치가 있을 때 detail · AL-6 sample 에 자료실과 같이 `stored_name`(난수 이름 — 경로 아님)이 실린다(운영자가 파일을 찾을 유일한 값). U3-1 ⑥ 의 「감사에 첨부 디스크 이름 0」은 올리기 · 받기 · 영구 삭제 감사에 대한 것으로 그대로 지켜진다(1b 감사 단언). 경로 · 원문은 0 — 메인 확인 필요
6. 「살아 있음」을 문서 기준(휴지통 · 영구 삭제 아님)으로 — 휴지통 문서 첨부의 바이트 없음은 자료실 휴지통과 같이 보고만(`attachment_trash_missing`)
7. 탈퇴 파기의 파일 순서 — 사진 · AI 그림은 「커밋 전에 파일 먼저」인데, 첨부는 purge_trash 와 같은 대기열 방식(커밋 뒤 비움 · 실패해도 이름이 대기열에 남아 다음 실행이 잇는다)으로 했다
