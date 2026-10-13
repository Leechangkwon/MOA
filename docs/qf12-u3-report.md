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

## 2부 — U3-2 화면: 칸 넓히기 · 칸 편집기 · 오른쪽 칸 하나로 · 바로 올리기

구현: Opus 에이전트 · worktree `/home/changkwon/qaredi-qf12u3s-20261013`(브랜치 `feat/qf12-u3-screen` — U3-1 `9703afe` + 지시 `fe2305f` 위) · 결과 폴더 `/home/changkwon/qaredi-qf12u3-results-20261013/U3-2/`.
서버 PHP(화면 PHP 둘의 주석 말고) · db · scripts 0. push · 발행 · 합치기 안 함. 커밋 해시는 이 파일이 든 커밋 — 마지막 답에 적는다.

### 1. 변경 파일

| 파일 | 내용 |
|---|---|
| `public/assets/community/work.js` | **WK-26**: `inboxView` 가 `createPane(aside)` 한 벌만 쓴다 — `div.task-inbox__pane` · 1200 넘나들기 다시 열기 · 함수 자리(`createPane((id)=>…)`) 갈래 삭제. 줄 = aria-current · aria-expanded, Esc 는 목록 · 칸 안에서 칸 닫기(입력 · 편집기 · 메뉴 · 창 안 제외) + 초점은 그 줄. 보기(목록 · 보드 · 달력)를 바꿔도 칸 그대로. **폭 조절** `paneSizer` — 손잡이 `div.task-pane__resize`(role=separator · aria-orientation · aria-label 「작업 상세 폭」 · aria-valuemin/max/now) 끌기(pointer capture) · ←/→ 16 · Home · 두 번 누르기 = 기본 폭 · 머리 줄 「넓게 보기」(`[data-pane-wide]` · aria-pressed · 켜면 위 끝, 끄면 앞 폭) · 폭은 `<body>` CSS 변수 `--task-pane-w` 하나(기본 폭이면 지움) · localStorage `qaredi-task-pane-w:v1:<아이디>` · 화면 폭 · 사이드바 접기(`data-sidebar`)가 바뀌면 같은 고른 값을 새 범위로. **알림 색** `say(node, text, ok)` — 「링크를 복사했어요」만 `.msg--ok`(그 밖 알림 줄은 그대로 오류 색). **파일 첨부** 목록 = 자료실 파일 + `attachment_titles`(clean = 받기 링크 · pending 「검사 중」 배지 · 그 밖 「받을 수 없음」 배지). 칸 읽기 ctx 에 `attachment_titles` · `attachment_page` |
| `public/assets/community/editor.js` | **창 한 벌**: `blockDialogFor` · `atomDialogFor` — `createBlockDialog` · `createAtomDialog` 를 화면에 한 번만 만들고 연 쪽 편집기(getView · hooks)로 묶어 연다(페이지 편집기 · 칸 편집기 공용). `ensureEditorDialogs` — 창 마크업이 없는 화면(업무 홈 · 내 작업)에 page_view.php 와 **같은 id · 클래스**로 createElement(구조 지문 시험으로 같음 확인). `blockKeymapFor` · `atomEditCommand` 를 모듈 함수로(페이지 편집기도 이것을 부른다). **도구줄** `createToolbar(view, {wrap, addBlock, paper:false})` — 칸 편집기가 같은 함수로 「블록 추가 · 색 · 배경 · 링크 · 멘션」을 단다(칸 줄은 id 0 — 페이지 편집기와 겹치지 않게). **`mountPaneEditor`** — 도구줄 · 블록 추가(같은 `BLOCK_TYPES` 목록) · 블록 고치기 창 · Ctrl+Alt+숫자 · 덩어리 Enter/두 번 누르기 · 할 일 상자 · 목차 · 「하위 페이지」 블록 · 붙여넣기 · 끌어놓기. 걷은 뒤에도 마지막 저장은 그대로(`gone` 표시) · 「편집 마침」은 올리던 것이 들어간 뒤 저장. **바로 올리기** `createUploads` 한 벌(입구 셋 공용): XHR multipart `POST /api/pages/{id}/attachments`(진행률 · 같은 CSRF) · 자리표시는 ProseMirror 장식(본문에 저장 0) · 차례로 한 번에 하나 · 취소 · 실패면 자리표시 지우고 창구 `error` 그대로 · 401 → 로그인 · 413 → 「파일이 100MB 를 넘습니다」. 붙여넣기는 **파일만**(글이 함께 든 붙여넣기 · HTML 의 외부 그림은 지금처럼) · 끌어놓기는 파일만(편집기 안 블록 옮기기 그대로). **「이 문서에 올리기」 탭** — 그림 · 파일 창 `#atom-file` 에 탭 둘(`.toolbar.db-viewbar` + `.db-type` 단추 · role=tablist/tab/tabpanel · ←/→) · 파일 고르기 `.btn--quiet`(aria-label 「이 문서에 그림/파일 올리기」) · 넣기 = 창 닫고 커서 자리 자리표시(창의 설명 · 그림 폭 반영) · 고치기 = 창 안에서 올리고 「고치기」로 바꿈 · 올릴 수 없는 편집기(제안 중 등)면 탭 줄을 숨기고 자료실만. 페이지 편집기: `state.attachmentTitles` · 읽기 ctx · fromDoc ctx · 붙여넣기 · 끌어놓기 플러그인(고칠 수 있고 제안 중이 아닐 때만) |
| `public/assets/community/editor-schema.js` | `fromDoc` — `att:` 이름은 `attachment_titles` 에서 · `toDoc` — `att:` 그림 · 파일 블록도 저장(전엔 `tbox:` 만 남기고 버렸다 — 1부 「못 한 것」) |
| `public/assets/community/doc-render.js` | `renderAttachmentBlock` — `att:` → `ctx.base + /api/pages/{문서}/attachments/{첨부}/raw|download`. 지도 · 문서 id 없으면 「볼 수 없는 파일」(서버와 같은 문구) · clean 그림만 `<img>` · **pending 그림 = 「검사 중이에요」 회색 상자(`.doc-upload[data-kind=image]`) · pending 파일 = 이름 + 「검사 중」 배지(링크 0)** · 그 밖은 서버와 같은 받기 카드 |
| `public/assets/app.css` | `.msg--ok`(`--ok-fg`) · 칸 폭 = `max(400px, min(var(--task-pane-w, clamp(400px, 34vw, 480px)), 70vw))` 를 칸 · 목록 여백 · 손잡이 자리에 · `.task-pane__resize`(fixed · 8px · col-resize · 올리면 2px `--primary` · 초점 고리) · `body[data-task-resizing]` · `[data-pane-wide]` 아이콘 돌림 · <1200 손잡이 · 넓게 보기 숨김 · 칸 안 `.editor-toolbar` 폭 · 색/링크/멘션 줄은 툴바 아래로 · `.doc-upload` · `__name` · `__progress` · `.task-inbox__pane` 규칙 삭제(`:is(.task-pane, .task-inbox__pane)` → `.task-pane`). 새 색값 0 |
| `src/community/handlers/work_home_page.php` · `my_tasks_page.php` | 주석만(「줄 아래 펼침」 → 오른쪽 칸 하나) — 마크업 · 로직 0 |
| `public/assets/community/{dashboard,database,suggestions,table-editor,templates}.js` · `public/assets/messenger.js` | `php scripts/community_asset_version.php` 가 고친 import 버전 줄뿐 |
| `tests/community/qf12_work_browser.py` | U2 「줄 아래 펼침」 단언을 WK-26(aside 하나 · 줄 아래 판 0)로 고침 + U3 묶음(`QF12D_ONLY=u3`) |
| `tests/catalog.json` | `community.qf12_work_browser` covers(editor-schema.js · community.css · guide-work.json) · notes(U3 · QF12D_ONLY u3) |
| `wiki/community/guide-work.json` | 업무 홈 · 내 작업의 「줄 아래 펼침」 → 「오른쪽 칸」 · 프로젝트 절에 폭 조절 · 칸 편집기 한 줄 · 첨부 사용법 한 단락(callout how) |

### 2. 실행한 명령과 출력 (원문 = 결과 폴더 `U3-2/`)

| 명령 | 결과 | 원문 |
|---|---|---|
| `QF12D_ONLY=u3 memories_harness.py --suite qf12_work_browser.py`(개발 중 iter1~3) | iter3 **pass 228 · fail 0** | `iter3.log` · `iter3/` |
| `memories_harness.py --suite qf12_work_browser.py`(전체 — 바뀐 판) | **pass 1586 · fail 0** (rc 0 · 기준 판 1387/0 + U3 단언 · U2 펼침 단언은 WK-26 로 고침) | `work-final.log` · `work-final/qf12-work-browser-results.json` · `metrics.json` · `shots/` |
| 같은 명령 — 기준 판 `fe2305f` | pass 1387 · fail 0 | `baseline-qf12_work_browser.log` |
| `--suite qf12_shell_browser.py` | pass 611 · fail 13 — **기준 판도 611 / 13, 실패 단언 이름 13개 글자까지 같음**(Q:T-38 런처 타일 5 · Q:T-39 돌아갈 주소 3 · 폰 서랍 360/412 등 — 이번 변경 밖, `diff` 0) | `shell-final.log` · `baseline-qf12_shell_browser.log` |
| `--suite messenger_store_browser.py` | pass 75 · fail 0 (기준 75/0) | `messenger-store-final.log` · `baseline-messenger_store_browser.log` |
| `python3 tests/community/messenger_rail_browser.py` | pass 614 · fail 0 (기준 614/0) | `messenger-rail-final.log` · `baseline-messenger_rail.log` |
| 정적: `php -l`(PHP 2) · `node --check`(커뮤니티 JS 전부 + messenger.js) · `community_asset_version.php --check` · `tests/community/asset_version.sh` · `git diff --check` · `security_lint.sh` · `tests/wiki_check.sh` · `tests/lib/contrast.py` · innerHTML 류 grep · 새 `.style` grep | 전부 0 / 통과(asset_version 37/0 · 금지 패턴 0 · wiki 69편 · CONTRAST_FAIL=0 · 새 `.style` 는 `--task-pane-w` set/remove 한 줄뿐) | `static.log` |

기준 판은 이 브랜치 시작 HEAD `fe2305f` 를 `git archive` 로 결과 폴더에 풀어 같은 harness 로 돌렸다(끝난 뒤 지움). 격리 틀 포트 8768 은 상대 에이전트(U3-1b)와 한 번 겹쳤다 — 그 회(iter1 첫 회)는 내 harness 의 php 가 포트를 못 잡아 401 만 나고 끝났다(상대 서버에 보낸 것은 내 합성 세션 쿠키뿐 · 200 0). 그 뒤 실행기에 「harness 프로세스가 없고 포트가 빌 때까지」 기다리기를 넣었다.

수락 시험(U3 묶음)이 실제로 확인한 것 — 단언 이름은 결과 JSON 원문:

- **WK-26** — 1440 업무함 줄 → `aside#task-pane`(목록 오른쪽 · 2.21 요소 전부) · `.task-inbox__pane` 0 · 줄 aria-current/expanded 한 줄 · 다른 줄 → 같은 칸 내용만 바뀜 · 같은 줄 다시 · ✕ · Esc(초점 그 줄) · 뒤로 가기 → 닫힘 · 홈도 같은 칸
- **A 폭 조절** — 손잡이 role=separator · vertical · 「작업 상세 폭」 · valuemin 400 · valuemax(위 끝) · valuenow · 폭 8 · 높이 900 · 왼쪽 경계 위 / 끌기 120px → 600 · 목록 여백 = 폭 + 32 · localStorage 숫자 하나 · 인라인 style 은 `<body>` 의 `--task-pane-w` 하나 / ← +16 · → −16 · Home 기본 480(기억 · body style 지움) / 끝까지 끌기 = 위 끝 · 반대 400 · 위 끝에서 목록 ≥ 480 · 넘침 0 / 두 번 누르기 480 / 넓게 보기 → 위 끝 · aria-pressed true · 끄면 앞 폭 560 / 새로고침 뒤 560 · 홈에서도 560 / 사이드바 접으면 위 끝 832 · 넓게 보기 = 832 · 넘침 0 / 1199 · 412: 손잡이 · 넓게 보기 숨음 · 기억 700 무시 · 넘침 0
- **알림 색** — 「링크를 복사했어요.」 `.msg--ok` rgb(21,128,61) ≠ `--danger` rgb(220,38,38) · 오류(합성 400)는 `--danger` · `.msg--ok` 0
- **B 칸 편집기** — 칸 도구줄 = 전체 편집기 도구줄 `["블록 추가","색","배경","링크","멘션"]` · 「블록 추가」 목록 22개 = 전체 편집기 목록(종류 · 번호 · 이름 같은 순서) · 창 한 벌 · 내 작업 화면이 지은 두 창의 id · 클래스 지문 = 페이지 화면 마크업 지문 · 「블록 추가 › 표」 → 같은 블록 고치기 창 → 표 블록
- **C 바로 올리기** — 붙여넣기(합성 png 240×120 클립보드 · ClipboardEvent) → 201 · 자리표시(`.doc-upload` · role=status · aria-live polite · 이름 · 「올리는 중 n%」 · 「취소」) → 그림 블록 · 글이 함께 든 붙여넣기는 올리지 않음 · 서버 첨부 행 1(clean) · 저장된 본문 `file_ref` = `att:<그 첨부>` · 읽기 화면 `<img src=/community/api/pages/{id}/attachments/{att}/raw>` 실제로 그려짐(naturalWidth > 0) · 「파일 첨부 1」 = 받기 주소 / 끌어놓기(txt · DragEvent) → 201 → 파일 블록 · 서버 행 1 · 본문 att: / 허용 밖(.exe) → 400 `ext_not_allowed` · 알림 줄 = 창구 `error` 글자 그대로 · 오류 색 · 자리표시 사라짐 · 블록 0 / 「블록 추가 › 그림」 창 탭 「이 문서에 올리기」(첫 탭 · aria-selected) · 「자료실에서 고르기」(지금 찾기 칸) · 파일 고르기 aria-label 「이 문서에 그림 올리기」 · 파일 고르면 창 닫힘 → 201 → 그림 블록 / SCAN_ENABLED=1(시험이 임시 .env 를 잠깐) → pending 201 → 읽기 화면 「검사 중이에요」 상자 · clean 그림은 그대로 · 「파일 첨부」 pending = 이름 + 「검사 중」 · 받기 링크 0 / 전체 화면 편집기에서도 붙여넣기 → att: 그림 → 읽기 화면에 그려짐
- **입구 셋 0** — 보기 공유(다른 팀 직원) · 손님: 칸 설명 누르기 · 편집기 · 도구줄 · 올리기 단추 0 · 전체 화면 「편집」 0 · 그래도 문서를 볼 수 있으니 첨부 그림 · 「파일 첨부 1」은 보임
- **폭 여섯 × 밝게 · 어둡게 · 고대비** — 360 · 412(터치) · 820 · 1199 · 1280 · 1440 × 읽기 · 편집(멘션 줄 열고) · 넓게(≥1200): 가로 넘침 0 · 누름 자리 < 44 0(폰 폭 · 터치) · 인라인 style 0(CSSOM `--task-pane-w` 제외) · 멘션 줄이 칸 안 · 콘솔 0 · 페이지 오류 0 · 5xx 0

스크린샷(직접 열어 봄): `work-final/shots/u3-wide-image-1440.png`(넓힌 칸 656 + 그림이 든 설명 — 목록 480 남음 · 최종 실행). 같은 시험의 앞 실행(`iter3/shots/` — 지운 뒤 다시 찍지 않음) `u3-pane-412.png`(412 칸 — 「‹ 목록」 · 전체 폭) · `u3-412t-light-edit.png` · `u3-1440-dark-edit.png`(칸 편집기 도구줄 · 표 · 그림 카드)도 열어 봤다. `u3-wide-collapsed-1440.png`(사이드바 접고 832)는 열어 보지 않았다(단언만).

### 3. 못 한 것과 이유 (미검증 포함)

| 항목 | 상태 · 이유 |
|---|---|
| 서버 렌더러(`DocRender.php`)의 pending 그림 | 화면(doc-render.js)은 DESIGN 2.24 대로 「검사 중이에요」 상자인데, 서버 렌더러는 pending 그림을 받기 카드로 그린다(1부 그대로 — 서버 PHP 는 이번 범위 밖). 두 렌더러 같음 시험(stage3 의 C:T-19)은 att: 를 쓰지 않아 지금은 걸리지 않는다. 맞출지는 메인 판단 |
| 칸을 닫는 중에 올리던 파일 | 「편집 마침」은 올리던 것이 블록으로 들어간 뒤 저장한다. 칸을 ✕ · 다른 줄로 **닫으면** 올리던 것은 서버에 첨부로 남지만 본문 블록은 들어가지 않는다(「블록에서 지워도 첨부는 남는다」와 같은 상태) — 미검증(시험 없음) |
| 「취소」 단추 | 자리표시 · 취소 단추가 서는 것은 시험했지만, 진행 중 실제로 눌러 XHR 이 끊기는 것은 합성 파일이 작아(곧 끝남) 시험하지 않았다 — 미검증 |
| 여러 파일 한 번에(차례로) | 코드는 줄을 세워 한 번에 하나지만 두 개 이상 놓기는 시험하지 않았다 — 미검증 |
| nginx 413(100MB 넘음) 문장 | 격리 틀은 php -S 라 nginx 413 길을 탈 수 없다 — 미검증(화면은 413 이면 서버 too_large 와 같은 문장) |
| 프로젝트 화면(page_view)의 칸 편집기 | 같은 함수 · 같은 창(page_view.php 마크업)이라 따로 시험하지 않았다 — 전체 편집기 쪽 붙여넣기만 시험 |
| 다른 회귀(stage7d4 · browser_7d4 · stage89_ui · dbg_u*_ui · ux_r1_ui …) | 카탈로그 `covers` 가 editor.js · app.css 와 겹치지만 지시서 완료 기준 밖 — 묶음 끝 회귀(메인) 몫. 돌리지 않음 |
| ui_snapshot | qf12_work_browser `snap` 묶음(1440 · 1280) 그대로 통과 — 기준 판과 결함 수 대조는 하지 않았다 |
| qf12_shell_browser 13건 | 기준 판에서도 같은 13건(위 표) — 고치지 않았다(범위 밖). 메인 지시대로 다시 돌리지 않음 |
| 목록 480px 에서 업무함 줄 | 위 끝 폭(목록 480)일 때 업무함 줄은 제목이 줄임표(「U2 …」)가 되고, 꼬리(「댓글 1 @」)가 있는 줄은 마감 「3일 지남」이 패널 오른쪽 안쪽 여백까지 밀린다(문서 가로 넘침은 0 — `work-final/shots/u3-wide-image-1440.png`). 업무함 줄의 좁은 폭 모양은 손대지 않았다 |

### 4. 확정한 값

- **폭 범위**: 아래 끝 400px · 위 끝 = `min(화면 폭 70%, 보이는 폭 − 본문 왼쪽(사이드바 · 아이콘 줄) − 본문 여백 64 − 480)` — 1440 사이드바 펼침 656 · 접음 832 · 1280 펼침 496 · 1200 펼침 416. 기본 = `clamp(400px, 34vw, 480px)`(지금 값). 키보드 한 칸 16px
- **기억**: localStorage `qaredi-task-pane-w:v1:<아이디>` = 사람이 고른 폭 숫자 하나(기본 폭이면 지움) · 화면 폭 · 사이드바가 바뀌면 그 값을 새 범위로 묶어 그린다(값은 그대로)
- **CSSOM**: `<body style="--task-pane-w: Npx">` 하나(기본이면 style 속성째 없음) — 칸 · 목록 여백 · 손잡이가 CSS 한 식
- **새 클래스**: `.task-pane__resize` · `.msg--ok` · `.doc-upload` · `__name` · `__progress`(DESIGN 2.21 · 2.24 에 적힌 것만). 상태는 속성으로 — `[data-pane-wide]` · `body[data-task-resizing]` · `.doc-upload[data-kind=image|file]` · `[data-atom-tab]` · `[data-atom-upload]` · `[data-atom-upload-input]` · `li[data-attachment]`
- **문구**: 「작업 상세 폭」 · 「넓게 보기」/「넓게 보기 끄기」 · 「올리는 중 n%」 · 「취소」 · 「이 문서에 올리기」 · 「자료실에서 고르기」 · 「파일 고르기」 · 「이 문서에 그림 올리기」/「…파일 올리기」 · 「검사 중이에요」 · 「검사 중」 · 「받을 수 없음」(감염 등 clean 아닌 첨부) · 「올렸어요 — 「고치기」를 누르면 이 파일로 바뀌어요.」
- **올리기 응답 처리**: 201 `{id,name,size,image,scan_status}` → `image` 참이면 그림 블록, 아니면 파일 블록(사용자가 「그림」 창에서 골라도 서버가 그림 아님이면 파일) · 400/403/404 → `error` 그대로 · 401 → 로그인 · 413 → 「파일이 100MB 를 넘습니다」

### 5. DESIGN · 지시서와 다르게 한 것 (이유)

1. **위 끝을 「70%」 대신 `min(70%, 목록 480 남김)`** — DESIGN 2.21 「범위 400px ~ 화면 폭 70%(목록이 480px 아래로 줄지 않게)」의 두 조건이 1440 에서 어긋난다(70% = 1008 이면 사이드바 240 을 빼고 목록이 ~130px — 처음 구현의 스크린샷에서 업무함 줄이 칸 밑으로 잘려 쓸 수 없었다). 괄호 조건(기획 15.4 「목록이 최소 480px 남게」)을 지켜 위 끝을 둘 중 작은 값으로 했다. 「넓게 보기」도 이 위 끝. 70% 를 그대로 원하면 업무함 줄의 좁은 폭 모양이 따로 필요하다 — **메인 · 사용자 확인 필요**
2. **창 마크업을 JS 로 한 벌 더 지음** — 업무 홈 · 내 작업 화면에는 「블록 추가」 · 「블록 고치기」 창 마크업이 없고(page_view.php 에만) page_view.php 는 허용 목록 밖이다. 편집기 함수는 한 벌이고, 마크업은 `ensureEditorDialogs` 가 없을 때만 같은 id · 클래스로 짓는다(지문 같음을 시험이 본다). 마크업 원본이 PHP · JS 둘이 된 것은 남는 부채 — 다음에 page_view.php 의 두 창을 지우고 JS 한 곳으로 모으면 한 벌이 된다(메인 판단)
3. **「넓게 보기」 아이콘** — 셸 아이콘 모음에 넓히기 아이콘이 없고(shell_bottom.php 밖) 새로 그리지 않았다. 내림 꺾쇠(`chevron`)를 CSS 로 돌려 ‹(넓히기) · ›(되돌리기)로 쓴다
4. **용지 고르개 · 블록 손잡이(⋮⋮)는 칸에 넣지 않음** — 「같은 꾸밈 도구줄 · 블록 넣기」는 같은 함수로 붙였지만, 용지(A4 …)는 전체 화면 편집기 판 크기 설정이고 블록 손잡이는 블록 왼쪽 바깥(−1.5rem)에 그려져 칸에서 잘린다. 칸 도구줄은 단추 다섯이라 360 에서도 줄바꿈으로 들어가 「더보기」로 접을 단추가 없었다(DESIGN 「덜 쓰는 단추는 더보기」 — 필요 없음)
5. **칸 도구줄 id 0** — 페이지 편집기 줄(`#tool-color` · `#mention-row` …)과 한 화면에 함께 있을 수 있어(프로젝트 화면) 칸 쪽은 id 를 달지 않는다. 그 때문에 community.css 의 `#mention-row` · `#mention-list` 규칙(넓은 화면 폭 상한 · 한 줄 줄임)은 칸에 적용되지 않고, 칸은 app.css 의 「툴바 아래로」 규칙을 쓴다
6. **Esc** — DESIGN 「Esc 는 2.21 그대로」인데 2.21 aside 에는 Esc 가 없었다. 업무함(홈 · 내 작업)에서는 목록 · 칸 안의 Esc 가 칸을 닫고 그 줄로 초점을 돌린다(U2 의 줄 아래 펼침 Esc 를 옮긴 것). 프로젝트 화면 칸은 지금처럼 Esc 없음
7. **파일 첨부 목록의 감염 · 그 밖** — DESIGN 에 없어 「받을 수 없음」 배지(링크 0)로 했다
8. **`toDoc` 의 `att:`** — 모양 검사는 하지 않고 접두사만 본다(전의 `tbox:` 와 같은 태도 — 모양 · 「이 문서 첨부인가」는 서버 Doc.php · SaveDoc.php)
