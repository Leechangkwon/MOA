# G-0 t-box 「개인」 등급 · 사람 지정 권한 · 권한 요청 — 구현 기록 (복제본 g0w · 검토 전)

> 지시서: `~/.local/share/qaredi-dev/prompt-g0-private-grants.txt`(결정 표 ★ 기본안) · 이어 하기 `prompt-g0-resume.txt`.
> 기준 판: 운영 t-box **c2b4d76**. 작성: claude(구현 작업자 — 메인 세션 changkwon-93 이 맡김). 앞 작업자가 2026-09-28 13:52 사용 한도로
> 멈춘 뒤(커밋 48c2dfc · 5b684b3 = `selftest-base/g0`) 14:20 에 이어받았다. **독립 검토·운영 반영·발행은 메인 몫** — 이 기록은 복제본까지다.
> 기능 번호: PRD **F-73**. 다음 조각 G-1(커뮤니티 페이지 폴더)이 이 등급 위에 선다 — 지금 만들지 않았다.

## 0. 한 줄

권한 표(역할 × 등급)로 한꺼번에 여는 네 등급 옆에 **「개인」**(`private`) 등급을 둔다. 개인 폴더·파일은 **표가 아니라 사람**으로 판정한다 —
주인(파일 = 올린 사람 · 폴더 = 만든 사람 · 그 위 개인 폴더의 주인)과 **아이디·이름으로 권한을 받은 사람**(보기/편집 · 폴더 권한은 안의 모든
하위에 이어진다)만 본다. 권한이 없으면 주소로 연 화면에서 **주인에게 요청**하고, 주인은 사이드바 「권한 요청 n」 에서 허락·거절한다.

## 1. 커밋 (복제본 g0w — 모두 작성자 claude)

| 커밋 | 무엇 |
|---|---|
| `48c2dfc` wip | DB(마이그레이션 `2026-10-10_access_grants.sql` · schema · seed · grants · 커뮤니티 알림) · `Authz`(개인 갈래 · 짝 SQL 한 벌) · 창구 넷(권한 · 사람 후보 · 공유받음 · 요청) · 기존 핸들러의 종류 표식(`item_kind`) |
| `5b684b3` wip | 화면(권한 창 · 요청 화면 · 공유받음 · 사이드바 「권한 요청 n」 · 개인 배지) · 수락 시험 초안 — 태그 `selftest-base/g0` |
| `5e4ab4f` | **폴더에 넣는 저장의 잠금 재확인**(`_folder_lock.php` — 아래 6장) · 수락 시험 2회차 고침(1회차 원인 3장) |
| `b00ad5d` | 화면 다듬기 — 「요청 보내기」 자리 · 요청 화면 아래 빈 목록 틀 숨김 · 권한 줄 고르개 도구줄 |
| `268a95b` | 수락 시험 — `db/grants.sql` 은 **G-0 절만** 임시 DB 에(파일 앞머리의 `… ON DATABASE tbox_re` 문장이 운영 DB 목록을 건드리지 않게 — 7장) |
| (문서 · 반영 스크립트) | PRD F-73 · ARCH 4.1·5.36·7.4·10 · SECURITY R-111·7장 · DESIGN · API · 도움말 · 위키 · 이 기록 · `review-scripts/` |

## 2. 결정 표 ★ 대조

| ★ 기본안 | 따랐나 | 자리 · 시험 |
|---|---|---|
| `LEVEL_RANK['private'] = 4`(가장 좁다) · 권한 표 행 넷(읽기 전부 거짓 · 올리기 Admin·Manager·Employee 참 · Guest 거짓) | 따름 | `Authz` · 마이그레이션 2 · CHECK `permissions_private_by_people`(매트릭스 편집으로도 개인 읽기를 켤 수 없다) · T-G0-1 |
| Admin 특권 — **목록에는 없고** 주소로 열 때만 · 감사 `granted_by: admin_override` | 따름 | `decideRead` [P] 는 허용, `visibleWhere` 개인 갈래에는 특권 없음 · `canSeeInList` = 특권 뺀 판정 · T-G0-9 |
| 고치기 — 주인 + 「편집」 받은 사람은 **올리기·이름·설명까지** · 등급·권한 주고 빼기·공유 링크·지우기는 주인만 | 따름(옮기기도 주인만 — 괄호 「편집 권한자는 올리기·이름·설명 고치기까지」 대로) | `decideEdit`·`decidePrivateOwner`·`decideFolderRename`·`decideFolderPut` · T-G0-5 |
| 폴더 불변 ① — 개인 폴더 아래는 개인만 · 개인 항목은 어느 폴더 아래든 | 따름(`fitsUnderFolder` 가 rank 로 그대로) + **동시 요청에도**(6장) | T-G0-3 준비 · T-G0-10 ④ |
| 민감정보 — 개인 허용(좁은 등급) | 따름 | T-G0-7 |
| 개인 → 넓히기 = R-20 그대로(Admin 만 · 15분 재인증 · 감사 before/after) · 받은 권한 줄은 **남긴다**(다시 좁히면 되살아남) | 따름 — 파일 등급 창구는 기준 판부터 사유 칸이 없다(허용 키 넷 · R-07) → 새 키를 만들지 않았다 | T-G0-7 |
| 권한 받는 사람 — 켜진 계정 · 손님은 보기만 | 따름(꺼짐·없음·만료·주인 자신 = 같은 400 · 같은 이름 여럿 400 「아이디로」) | T-G0-5 |
| 권한 창구 — 주인만 · Admin 은 특권 + 재인증 + 감사 | 따름 | `decideGrantManage` · T-G0-9 |
| 공유 링크 — 개인 파일은 주인만 만든다 · 만든 사람이 주인이 아닌 옛 링크는 끝난다 | 따름 | `share_create` [권한 ✔ 2-P] · `share_download` ⑤-A-P · T-G0-9 |
| 커뮤니티 — 개인 파일은 **파일 주인 = 페이지 주인인 비공개 페이지**에만 · 그 밖 400 이유 | 따름 | `CommunityAuthz::fitsFileRef` · `community_file_refs_ok` · T-G0-8 |
| 요청 — 호스트 = 주인(꺼지면 Admin) · 같은 (항목, 요청자) pending 하나 · 하루 20건 · 없는 id = 못 보는 id 같은 답 | 따름(하루 한도는 저장 안 한 요청도 센다 — 한도에 닿는 횟수로 존재가 갈리지 않게) | T-G0-6 |
| 알림 — 커뮤니티 알림 종에 두 종류(요청 · 결정) · 만드는 곳 한 함수 | 따름(`tbox_access_notify`) | T-G0-6 |

**지시서에 없던 것 — 앞 작업자가 정했고 나는 그대로 둔 것(사람 판단 칸 9장에도)**
- 「주인」 의 뜻에 **위 개인 폴더의 주인**을 넣었다: 편집 권한자가 내 개인 폴더에 올린 파일은 그 사람 것이지만, 폴더 주인이 못 보면 내 폴더에 내가 못 보는 것이 생긴다(G-1 의 페이지 폴더가 그 모양).
- 다른 등급 → 개인(좁히기)은 **그 항목을 올린·만든 사람만**(팀장·Admin 도 남의 파일을 개인으로 돌리지 못한다 — 돌리면 팀이 못 보는 파일이 된다).
- 권한 줄 보기(`GET …/grants`)는 그 항목을 **읽을 수 있는 사람 모두** — 받은 사람도 누가 함께 받았는지 본다(배지 「개인 · n명」 도). 고치기는 주인만.

## 3. 수락 시험 1회차 실패 — 원인과 고친 쪽

| 로그 줄 | 무엇 | 원인 | 고친 쪽 |
|---|---|---|---|
| 236 | 손님 검색 `folder_id` 기대 `None` 실제 빈 칸 | 시험 도우미 `jget` 이 None 을 빈 글자로 찍는다 — 응답은 `null` 로 맞았다 | 시험(`str(…)`) |
| 268 | 팀 파일에 권한 주기 400 기대 · 404 | `team` 올리기에 `team_id` 를 안 보내 올리기가 400 → 파일 번호가 비어 `/api/files//grants` 404 | 시험(올리기 도우미에 팀 번호 칸) — 코드는 400 `not_private` 로 맞다(2회차 ✔) |
| 313·315·316·318 | 관리자 넓히기 재인증 403 기대 · 400 | 시험이 `reason_text` 를 보냈다 — 파일 등급 창구 허용 키 넷 밖이라 400 `bad_request`(R-07). 좁히기 400 은 넓히기가 안 된 연쇄(같은 등급 = 바뀐 것 없음) | 시험(사유 칸 빼기 · 감사는 `granted_by matrix` 로) — `private` 는 허용 목록·CHECK 에 원래 있었다 |
| 320·321·354 | 등급 바꾸기 405 | 위 268 과 같은 빈 번호 → `PATCH /api/files/` 405 | 시험 |
| 355 | 팀장이 만든 옛 링크가 개인으로 좁힌 뒤에도 200 | 링크용 팀 파일 올리기가 같은 까닭으로 실패 → 링크 주소가 비어 **사이트 뿌리 `/`(200)** 를 받았다. 코드는 `share_download` ⑤-A-P(만든 사람이 지금도 주인인가)로 이미 막는다 — 2회차 404 ✔ | 시험(빈 링크면 멈춤) — **새는 길 아님**을 2회차가 확인 |
| ④ | 등급 바꾸기 경쟁 시간 초과(rc=124) | `… & … & wait` 의 인자 없는 `wait` 가 **배경의 PHP 서버까지** 기다렸다. 격리 재현(`timeout 30` 아래 두 curl `&`)은 0.1초에 둘 다 403 — 교착 아님 | 시험(두 PID 만 wait · 여러 일꾼 서버) — 그 과정에서 **진짜 경쟁**을 찾아 코드를 고쳤다(6장) |
| (✔ 로 보였던 것) | zip 「개인 = 없는 파일」 이 둘 다 500 | 임시 인스턴스에 `Storage::tmpDir`(저장소의 형제 `tmp/`)가 없어 둘 다 500 — 비교가 헛돌았다 | 시험(tmp 폴더) — 2회차 둘 다 403 · skipped 같음 |

## 4. 판정 ↔ 목록 조건절 짝 (C:T-56 방식)

- 한 벌: `Authz::privateChainSql($alias, $ph, $me)` 이 조상 조인(파일 다섯 · 폴더 넷 — 개인인 동안만 잇는다) · 주인 식 · 권한 식(편집/아무거나)을 만든다.
  - 목록 조건절 `visibleWhere` 의 개인 갈래 = `access_level = 'private' AND EXISTS(… 주인 식 OR 권한 식)` — `privateVisibleSql`
  - 판정 `decideRead` [P] = `privateReach`(같은 조각으로 `CASE owner/write/view`) → 주인·받은 사람 허용 · 그 밖 Admin `admin_override` · 그 밖 거부
  - 한 건 목록 판정 `canSeeInList` = `decideRead` 에서 `admin_override` 만 뺀 것(목록과 같은 뜻 — 폴더 지우기의 휴지통 이름 · 공유받음 · 알림 이름 · 요청 목록)
- **전수 대조**(T-G0-2 · 수락 4회차): 역할 넷 × 권한 자리 열(없음 · 주인 · 사슬 주인 · 폴더 보기 · 폴더 편집 · 조상 보기 · 파일 보기 · 파일 편집 · 개인 아닌 맨 위 폴더의 권한 · 개인 아닌 맨 위 폴더의 주인) × 깊이 1~5 × 항목 열일곱 = **3400 사례 · 불일치 0**
  (목록 조건절 포함 = `canSeeInList` = 따로 계산한 기대값 · `decideRead`·`privateReach`·`decideEdit` = 기대값).

## 5. 읽기 경로 호출부 전수 목록 (grep 근거 — `grep -rn "Authz::visibleWhere\|visibleFilesWhere\|decideRead\|canSeeInList\|privateReach\|restorableFilesWhere" src/`)

개인 파일의 **이름이나 바이트**가 나갈 수 있는 자리 전부. 32 자리 — 24 자리는 판정을 지나고, 8 자리는 판정 밖인 까닭을 적었다.

| # | 경로 | 자리(파일:줄) | 판정 | 권한 없는 사람이 얻는 것 |
|---|---|---|---|---|
| 1 | `GET /api/files`(목록·폴더·전역 검색·토큰) | `files_list.php:96` 폴더 `decideRead` · `:115` `visibleFilesWhere` | 짝 | 0(개수 `total` 도 조건절 안에서 셈) — T-G0-3 |
| 2 | 파일 줄의 폴더 경로 | `files_list.php:251` → `_folder_paths.php:26` `visibleWhere(d)` | 짝 | 못 보는 조상은 「…」 — T-G0-4 손님 |
| 3 | `GET /api/folders?parent=`(트리·토큰) | `folders_list.php:63` 부모 `decideRead` · `:82`·`:83` 자식 수 `visibleWhere(d/f)` · `:142` | 짝 | 0 · 없는 번호와 같은 403 · 개수에도 안 셈 — T-G0-3 |
| 4 | breadcrumb | `folders_list.php` · `files_list.php` → `_folder_paths.php` | 짝 | 보이는 폴더만 |
| 5 | `GET /api/files/{id}/download`(세션·토큰) | `files_download.php` → `_download_common.php:90` `decideRead` | 판정 | 403 |
| 6 | `GET …/preview` | `files_preview.php:64` | 판정 | 403 |
| 7 | `GET …/preview/raw` | `files_preview_raw.php:58` | 판정 | 403 |
| 8 | `POST …/exec-download` | `files_exec_download.php:130`(비밀번호 확인 **전**) | 판정 | 403 |
| 9 | `POST /api/files/zip`(일괄 받기) | `files_zip.php:237` → `_download_common.php:90` | 판정 | 없는 id 와 같은 답(상태 · skipped 개수) — T-G0-3 |
| 10 | `GET /api/files/favorites` · 넣기 · 정리 | `favorites_files.php:38`·`:145`·`:182` | 짝 | 넣기 404(없는 것과 같다) · 목록 0 |
| 11 | `/api/folders/favorites` | `favorites_folders.php:34`·`:101`·`:130` | 짝 | 0 |
| 12 | `GET /api/files/recent` | `files_recent.php:26` `visibleFilesWhere` | 짝 | 0(Admin 도 — 목록) |
| 13 | `GET /api/grants/mine`(공유받음) | `access_mine.php:48`·`:62` `canSeeInList` | 짝 | 나에게 준 것만 |
| 14 | `GET …/grants`(권한 줄) | `access_grants.php:54` `decideRead` | 판정 | 403 |
| 15 | `GET /api/access-requests`(받은·보낸) | `access_requests.php:119` `privateReach` · `:198` `canSeeInList` | 짝 | 보낸 목록에 못 보는 항목의 기다리는 줄 0 |
| 16 | 요청·결정 알림 | `community/handlers/notifications_list.php:68` `canSeeInList` | 짝 | 거절 알림에 항목 이름 0 — T-G0-6 |
| 17 | `POST …/share`(링크 만들기) | `share_create.php:92` `decideRead` · `:108` `decidePrivateOwner` | 판정 | 403 · 주인만 |
| 18 | `GET /s/{token}`(링크로 받기) | `share_download.php:188` `decideRead` · `:200` `decidePrivateOwner`(+`decideEdit`) | 판정 | 좁힌 뒤 남의 옛 링크 404 — T-G0-9 |
| 19 | `DELETE /api/folders/{num}` 의 휴지통 이름 | `folders_delete.php:96` · `:187` `canSeeInList` | 짝 | 이름 없이 「다른 사람의 파일」 |
| 20 | `PATCH /api/files/{id}` | `files_update.php:117` `decideRead`(응답에 이름 0) | 판정 | 403 |
| 21 | `DELETE /api/files/{id}` | `files_delete.php:77` | 판정 | 403 |
| 22 | `PATCH /api/folders/{num}` | `folders_update.php:135` | 판정 | 403 |
| 23 | 커뮤니티 본문 파일 지도(`file_titles`) | `community/routes.php:2557` `visibleFilesWhere` | 짝 | 「볼 수 없는 파일」(Admin 도) — T-G0-8 |
| 24 | 커뮤니티 파일 붙이기 검사 | `community/routes.php:2491` `decideRead` + `fitsFileRef` · `:2532` `canSeeInList` | 판정 | 400 이유(개인 파일이 있다는 것은 **볼 수 있는 사람에게만**) |
| 25 | `GET /api/files/trash` · 되살리기 | `files_trash.php:38` · `files_restore.php:45` `restorableFilesWhere` | 판정 밖 — 올리고 지운 **본인** · Admin 전체(15분 재인증) | 권한 없는 사람 0 · **Admin 전체 휴지통에는 남의 개인 파일 이름이 보인다**(9장) |
| 26 | `GET /api/admin/shares` | `admin_shares.php`(Admin + 재인증) | 판정 밖 — 밖으로 나간 링크 감독 | **주인이 만든 링크의 파일 이름이 Admin 에게 보인다**(9장) |
| 27 | `GET /api/me/shares` | `shares_list.php`(내가 만든 링크만) | 판정 밖 | 만들 때 이미 알던 이름뿐 — 좁혀진 뒤 링크는 받기에서 끝난다 |
| 28 | 사이드바 휴지통 수 | `views/shell_top.php:507` `restorableFilesWhere` | 25 와 같다 | 개수만 |
| 29 | `/api/me` 기다리는 요청 수 | `me.php` | 호스트 본인 | 개수만 |
| 30 | 감사 로그 화면(Admin) | `audit_list.php` | 판정 밖 — 감사는 전부 본다 | Admin 만 |
| 31 | 하루 올리기 총량 | `Upload.php:807` | 본인 것 합계 | 숫자 |
| 32 | 팀 카드 파일 수 | `org_teams.php:57` | 팀 번호로 셈 — 개인은 팀이 없다 | 0 |

**표만 보는 자리 0**: `decideRead` 를 부르는 자리는 모두 행에 `item_kind`·`id` 를 싣는다. 표식을 빠뜨리면 `privateReachOf` 가 null → **못 보는 쪽으로** 틀린다(닫힌 실패).
일괄 작업(고르기 → 받기·옮기기·지우기·즐겨찾기)은 화면이 한 건 창구를 차례로 부른다(새 창구 0) — 위 5·9·10·20·21 과 같은 판정이다.

## 6. 동시 요청 — 불변 ①(개인 폴더 안은 개인만)

- **찾은 것**: 넣는 저장(올리기 · URL 가져오기 · 폴더 만들기 · 파일/폴더 옮기기 · 등급 바꾸기)은 트랜잭션 **전**에 대상 폴더를 읽어 판정했다.
  같은 순간 그 폴더가 좁혀지면 둘 다 통과한다. 여러 일꾼 서버(PHP_CLI_SERVER_WORKERS=4)로 「사내 폴더를 개인으로 좁히기 ↔ 다른 사람이 같은 폴더에 사내 파일 올리기」 를
  동시에 보낸 재현에서 **10회 중 1회 · 12회 중 1회** 「개인 폴더 + 사내 파일」 이 남았다(좁히기 200 · 올리기 201). 트랜잭션 전 판정 구조는 18-1 부터 있던 것이다(다른 등급 좁히기도 같은 틈).
- **고친 것**: `src/handlers/_folder_lock.php` `tbox_folder_lock_for_put()` — 저장 트랜잭션 **안에서** 대상 폴더 행을 `FOR SHARE` 로 잠그고 **같은 두 함수**(`decideFolderPut` · `fitsUnderFolder`)를 다시 부른다.
  좁히는 쪽이 먼저면 이 SELECT 가 커밋을 기다렸다가 바뀐 행으로 거부하고, 넣는 쪽이 먼저면 좁히는 UPDATE 가 기다렸다가 `folders_update` 의 「마지막 그물」(UPDATE 뒤 새 스냅숏)이 되돌린다.
  FOR SHARE 끼리는 막지 않는다(같은 폴더 동시 올리기는 그대로). 응답은 트랜잭션 전 검사와 **같은 답**(없는 폴더 = 못 넣는 폴더).
- **확인**: 같은 재현 16회 · 수락 시험 T-G0-10 ④ 10회 — 남은 회수 **0**(좁히기가 이김 4 · 올리기가 이김 6 · 그 밖 0).

## 7. 운영 기본값 함정 — 이번에 찾은 것

- 수락 시험 초안(앞 작업자)의 T-G0-1 이 `db/grants.sql` **전체**를 임시 DB 에 돌렸다. 파일 앞머리에 `REVOKE/GRANT CONNECT ON DATABASE tbox_re …` 가 있어
  임시 DB 에 붙어 있어도 **운영 DB 의 접속 권한 목록**을 건드린다(1~4회차 네 번). 같은 권한을 다시 건 것이라 결과는 그대로다 — 14:5x 운영 `pg_database.datacl` =
  `{=T/tbox_owner, tbox_owner=CTc, tbox_app=c, tbox_backup=c, tbox_perm=c, tbox_testread=c, tbox_push=c}`(PUBLIC 은 TEMP 만 · 역할 일곱 그대로). 앞으로는 G-0 절만 잘라 돌린다(`268a95b` · qf3a 와 같은 방식 · DATABASE 문장 0 확인).

## 8. 시험

- 수락 `tests/g0_private_grants.sh`(T-G0-1~12) + `tests/browser/g0_private_grants.py` — 4회차 **250 / 0 / 0** · 6회차 **252 / 0 / 0**(브라우저 포함 · flock). 로그 `wk-g0/accept-1..6.log`.
- **AL-9 소음 조치(메인 지시 2026-09-28 15:2x)**: 1~5회차와 회귀는 임시 DB 를 `sudo -u postgres psql/createdb/dropdb` 로 다뤄 운영 경보 로그 AL-9 가 13:05~15:25 사이 718줄 늘었다
  (대부분 회귀의 옛 시험 — `psql -c '<SQL>'` 모양이 달라 명령마다 한 줄 · qf3c 252 · qf3b 86 · d1s 77 · qf3a 59 · 5a2 48 · 5e 37 · sec4 34 · qf4u2b 33 · qf2b 22 · 6b 20 · G-0 12 · tbox_re pg_dump 등 12 —
  전부 log_only · 휴대폰 알림 0). 6회차부터 G-0 시험은 **시험 전용 역할 tbox_testdb** 로만 만들고·붓고·묻고·지운다(운영 사본은 `pg_dump --no-owner` 한 번을 틀 DB 에 · `createdb -T`) —
  6회차 동안 alerts.log 더한 줄 **0**. 시험 역할은 `OWNER TO tbox_owner` 를 못 하므로 T-G0-1 은 그 두 줄만 뺀 사본을 붓고(차이 = 그 두 줄 확인), 소유자는 DB 반영 스크립트의 확인·모의 연습이 본다.
- 브라우저 스크린샷 `~/.claude/ui-shots/g0/`(360 · 412 터치 · 820 터치 · 1280 × 라이트·다크 — 주인 폴더 · 권한 창 · 손님 요청 화면 · 공유받음 · 받은 요청) — 눈으로 봄.
- 회귀: 10장.

## 9. 사람 판단이 필요한 것

1. **Admin 의 전체 휴지통(`/api/files/trash`)·공유 링크 전체(`/api/admin/shares`)에는 남의 개인 파일 이름이 보인다** — 둘 다 Admin + 15분 재인증의 감독 화면이다.
   ★ 「목록에는 안 나온다」 를 여기까지 넓힐지(이름 가림 · 개수만) 정해 주세요. 지금은 감독 쪽을 택해 그대로 두었다(밖으로 나간 링크가 안 보이면 감독이 비고, 되살리기는 Admin 몫이다).
2. 권한 줄 보기는 받은 사람에게도 열려 있다(누가 함께 받았는지 · 「개인 · n명」) — 주인만 보게 좁힐지.
3. 개인 → 넓히기에 사유 칸을 새로 둘지(지금은 R-20 그대로 — 파일 등급 창구에는 기준 판부터 사유 칸이 없다).
4. 되돌리기 — 개인 등급 행이 하나라도 생긴 뒤에는 DB 되돌리기를 하지 않는다(마이그레이션 머리 주석 · DB 반영 스크립트가 센다).

## 10. 회귀 (2026-09-28 14:57~15:18 · 임시 인스턴스만 · 운영 쓰기 0 · 예상 약 65분(카탈로그 est) → 실제 17분 + 기준 판 대조 3분)

- 기존 시험은 운영 사본(tbox_re pg_dump)에 **자기** 마이그레이션만 붓는다 — 새 코드는 G-0 표를 읽으므로 그대로는 전부 500 이다. 그래서 「운영 사본 + G-0 마이그레이션」
  원본 DB(`tbox_g0_src_test` — 운영을 읽어 붓기만 · 끝에 지움)를 만들고 시험 사본의 `SRC_DB="tbox_re"` **한 줄만** 그 이름으로 바꿔 돌렸다(틀 `wk-g0/regress.sh` —
  브라우저가 든 시험은 시험마다 flock · 메모리 700MB).
- 실패가 있는 넷은 **기준 판 c2b4d76**(worktree · G-0 마이그레이션 없는 원본 DB)에서 같은 틀로 다시 돌려 실패 목록을 줄 단위로 대조했다.

| 시험 | G-0 판 | 기준 판 c2b4d76 | 판정 |
|---|---|---|---|
| qf3c_zip(zip 받기) | 114 / 3 | 114 / 3 | **같은 실패** — 정적 「index.php 더한 라우트 1줄」(옛 기준 판 5de7467 대비 — 다른 단계가 이미 11줄 더함) · 관리자 넓히기 재인증(S-8 이 시험보다 뒤) |
| qf3a_tbox(즐겨찾기·최근) | 72 / 12 | 72 / 12 | **같은 실패** — `db/grants.sql` 즐겨찾기 절 뒤 DO 블록 수(기준 2 · G-0 3 — 뒤 절이 붙는 구조) · 관리자 넓히기 재인증에서 이어진 연쇄 |
| qf3b_tbox(일괄) | 48 / 2 | 48 / 2 | **같은 실패** — 정적 「a0d5503 대비 라우트 줄 0」(다른 단계가 이미 더함) |
| qf2b_files_search(검색 색인) | 38 / 2 | 38 / 2 | **같은 실패** — 관리자 넓히기 재인증 |
| sec4 · dbg_s_security · dbg_s2_security · qf4_restore_owner | 67/0 · 153/0 · 110/0 · 101/0 | — | 통과 |
| 커뮤니티 stage5e · stage6b · stage5a2 | 77/0 · 70/0 · 139/0 | — | 통과 |
| security_lint · community_asset_version --check · wiki_check | 0 · 0 · 0 | — | 통과(wiki_check 는 앱 사본 옆 사이트 STAGES 링크에서 — 복제본 자리에는 사이트 STAGES 가 없다) |

**G-0 이 만든 새 실패 0.** 기준 판에서도 같은 네 시험의 실패는 시험 쪽 기대가 뒤 단계(S-8 재인증 · 라우트 추가)를 따라가지 못한 것 — 이 조각 범위 밖이라 고치지 않았다.
