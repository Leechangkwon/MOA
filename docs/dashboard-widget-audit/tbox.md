**T-box 전체 기능 조사와 위젯 설계 재료 — 2026-10-11**

조사 기준은 `/var/www/qaredi/tbox-v2`의 `main 93668ff`, `VERSION 2.55.0`이다. 실제 라우트, 핸들러, Authz, 화면 PHP, `app.js`, 검색·홈·프로그램 자산을 읽고 PRD와 대조했다. 조사 시작 시 작업 트리는 깨끗했다. 운영 DB 조회, 실제 API 호출, 사용자 데이터 변경, 앱 코드 변경은 하지 않았다. 아래의 **현재 제공**은 이 커밋에 구현된 경로와 화면을 뜻한다. 운영 계정별 권한값, 파일 수, 서버 설정의 켜짐 여부, 실제 기기 연결 상태까지 실측했다는 의미는 아니다. 조건부 기능은 조건을 따로 적었다.

주소 표기의 기본 접두는 `/tbox`다. 예를 들어 `/api/files`는 실제 `/tbox/api/files`다. `/community` 주소만 별도 접두를 쓴다. S/M/L은 제안하는 정보 밀도 수준이며 이미 구현된 크기별 콘텐츠 계약이 아니다. 모든 크기에서 제목·숫자만 남기지 않고 실제 대상과 다음 행동을 제공하는 방향이다. 최종 픽셀 크기나 정식 위젯 목록은 아직 결정하지 않았다.

**현재 홈과 권한에서 먼저 확정되는 사실**

- 현재 개인 홈의 파일 관련 위젯은 독립적인 파일 도구 모음이 아니라 `최근 작업 · 즐겨찾기` 한 영역이다. 파일/문서 소스 선택, 최근/즐겨찾기 선택 후 둘을 합쳐 **최대 6행**의 이름 링크를 만든다. 파일 링크는 UUID 미리보기 대신 **파일명 검색 주소**다. 폴더 즐겨찾기는 현재 이 홈 목록에 포함되지 않는다. 크기를 늘려도 `start.js`의 `slice(0,6)`은 그대로다. 근거: [start.js:20](/var/www/qaredi/tbox-v2/public/assets/start.js:20), [start.js:46](/var/www/qaredi/tbox-v2/public/assets/start.js:46), [launcher.php:44](/var/www/qaredi/tbox-v2/src/views/launcher.php:44).
- 자료실 자체는 `/tbox/`의 **파일·폴더 탐색기**이며 `/`의 모아 개인 홈과 다른 화면이다. 파일/즐겨찾기/최근 탭, 폴더 트리, 검색, 일괄 작업, 업로드/가져오기 모달, 권한 요청/공유받음이 이미 있다. 근거: [home.php:118](/var/www/qaredi/tbox-v2/src/handlers/home.php:118), [home.php:161](/var/www/qaredi/tbox-v2/src/handlers/home.php:161), [home.php:238](/var/www/qaredi/tbox-v2/src/handlers/home.php:238).
- 기본 역할은 Admin/Manager/Employee/Guest, 자료 등급은 public/internal/team/admin-only/**private**다. `public`도 일반 목록·다운로드는 로그인 필요하며, 별도의 만료 공유 링크만 익명 전달 통로다. 일반 등급의 읽기/업로드는 변경 가능한 권한표와 팀 범위를 따른다. 위젯에서 역할 이름만으로 버튼을 확정하면 안 된다. `/api/me`의 `upload_levels`, `can_fetch`, `can_admin` 등과 행의 `can_edit` 등을 표시 참고값으로 사용하고 동작마다 서버가 재판정한다. 근거: [PRD.md:123](/var/www/qaredi/tbox-v2/PRD.md:123), [Authz.php:265](/var/www/qaredi/tbox-v2/src/Authz.php:265), [me.php:78](/var/www/qaredi/tbox-v2/src/handlers/me.php:78).
- **개인(private) 항목은 역할표가 아니라 소유자·지정된 사람·상위 개인 폴더의 권한으로 연다.** 편집 권한 수령자는 이름/설명/올리기 등 제한된 편집만 가능하며 이동/등급 변경/삭제/재공유/권한 관리와 같지 않다. 무관한 Admin은 목록·검색·즐겨찾기·최근·건수에 개인 항목을 보지 못하고, 주소로 접근할 때만 별도 특권 경로가 있다. 근거: [PRD.md:176](/var/www/qaredi/tbox-v2/PRD.md:176), [Authz.php:395](/var/www/qaredi/tbox-v2/src/Authz.php:395), [Authz.php:423](/var/www/qaredi/tbox-v2/src/Authz.php:423).
- 계정 최초 비밀번호·필요한 MFA·약관 관문은 화면뿐 아니라 실제 API에도 적용된다. 관리자 기능(`user.manage`, `org.manage`, `audit.read`, `role:Admin`)에는 재인증 창이 있다. 위젯의 자동 요청이 재인증 팝업을 반복 띄우는 구조는 피하고, 상태를 표시한 뒤 명시적으로 열어야 한다. 근거: [public/index.php:1017](/var/www/qaredi/tbox-v2/public/index.php:1017), [Authz.php:96](/var/www/qaredi/tbox-v2/src/Authz.php:96).

**기능별 조사 — 자료를 찾고 다루기**

**01. 파일 목록·검색·정렬·페이지 이동 — 현재 화면/API 모두 제공**

- 사용자는 파일명/설명, 크기, 등급/팀, 올린 사람/날짜, 경로, 미리보기 종류, 민감·검사·실행압축 표시를 보고 연다. 검색은 파일명·설명 부분 일치다. 파일 본문 OCR/전문검색이 아니다. 정렬은 이름/올린 시각/크기와 오름·내림차순이다.
- `GET /api/files?q=&sort=name|created_at|size&dir=asc|desc&page=&folder=` → `{files,total,page,per_page,scope,folder}`. 웹은 20행 고정. **q가 없으면 지정 폴더, 기본은 루트만**이다. q가 있고 folder를 생략하면 권한 내 전역 검색이다. q와 folder가 모두 있으면 해당 폴더 검색이다. 따라서 기본 응답의 total을 ‘내 전체 파일 수’로 표현할 수 없다.
- 권한은 목록 SQL 자체의 `visibleFilesWhere`, 부모 폴더 판정, 개인 경로 가림에 적용된다. 행의 `can_edit`, `can_delete`, `can_manage_access`는 서로 다르다. 토큰 응답은 `{id,name,level,size,uploaded_at,exec_content}` 배열로 웹 응답과 다르므로 브라우저 위젯은 세션 계약을 사용해야 한다.
- 위젯 가치 높음: **지정 폴더/저장한 검색 조건**을 명시한 자료 목록. S=대상 범위+실제 파일 1~2개+열기; M=4~6개+날짜/크기+검색/폴더 열기; L=8~12개+정렬/페이지+선택 항목 미리보기. 전체 탐색기와 일괄 관리 기능은 상세 화면 유지. 저장한 검색 조건 자체의 서버 API는 아직 없다.
- 근거: [files_list.php:40](/var/www/qaredi/tbox-v2/src/handlers/files_list.php:40), [files_list.php:161](/var/www/qaredi/tbox-v2/src/handlers/files_list.php:161), [files_list.php:264](/var/www/qaredi/tbox-v2/src/handlers/files_list.php:264), [files_list.php:341](/var/www/qaredi/tbox-v2/src/handlers/files_list.php:341).

**02. 폴더 탐색·경로·하위 건수 — 현재 화면/API 모두 제공**

- `GET /api/folders?parent=` → 현재 folder와 breadcrumb, 자식 folders. 자식별 id/name/depth/등급/팀/`child_count`/`file_count`/직접 권한 수를 준다. 한 층씩, 최대 500폴더. 각 count는 현재 사용자가 볼 수 있는 **직접 자식** 수다. 하위 전체 용량/재귀 총 파일 수가 아니다.
- 숨은 조상 경로는 `…` 등으로 가린다. 없는/못 보는 부모는 동일 403이며, 부모가 보인다고 안의 파일 모두가 보이지 않는다.
- 위젯 가치 높음: 자주 쓰는 폴더 바로 접근. S=선택 폴더명+보이는 직접 파일 수+열기; M=하위 폴더 3~5개와 각각 파일 수; L=breadcrumb+한 층 탐색+현재 폴더 파일 목록. 큰 재귀 트리는 자료실 유지.
- 근거: [folders_list.php:42](/var/www/qaredi/tbox-v2/src/handlers/folders_list.php:42), [folders_list.php:105](/var/www/qaredi/tbox-v2/src/handlers/folders_list.php:105), [folders_list.php:165](/var/www/qaredi/tbox-v2/src/handlers/folders_list.php:165), [app.js:5021](/var/www/qaredi/tbox-v2/public/assets/app.js:5021).

**03. 폴더 만들기·이름·이동·등급·삭제 — 일부는 API만 제공**

- `POST /api/folders {name,parent_id?,access_level,team_id?}`. `PATCH /api/folders/{num} {name?,parent_id?,access_level?,team_id?,cascade?,reason_text?}`. `DELETE /api/folders/{num}`. 깊이 5, 순환 이동 방지, 부모보다 넓은 범위 금지. 하위 함께 변경은 좁히기만 가능하며 관련 권한을 모두 확인한다. 토큰 PATCH는 name/parent_id만 허용한다.
- UI에는 새 폴더·이름·등급·빈 폴더 삭제가 있다. **parent_id 이동 API는 있으나 현재 폴더 모달의 모드는 create/rename/level뿐**이므로 ‘폴더 이동 버튼이 이미 있다’고 설계 근거로 쓰면 안 된다. 파일 이동 UI와 구별한다.
- 살아 있는 파일이나 하위 폴더가 있으면 삭제 불가. 삭제 파일의 참조만 남아 있으면 별도 확인 `?move_trashed=1`로 휴지통 파일을 상위로 옮긴 뒤 빈 폴더를 삭제한다. 폴더 자체는 파일과 같은 30일 복구 대상이 아니다.
- 위젯은 요약/진입 우선. S=폴더 맥락+열기; M=현재 폴더/직접 자식+새 폴더 열기; L=폴더 탐색과 생성 폼은 후보지만 등급 일괄 변경·삭제 확인은 기존 상세 흐름 유지.
- 근거: [public/index.php:481](/var/www/qaredi/tbox-v2/public/index.php:481), [folders_update.php:125](/var/www/qaredi/tbox-v2/src/handlers/folders_update.php:125), [folders_update.php:363](/var/www/qaredi/tbox-v2/src/handlers/folders_update.php:363), [folders_delete.php:115](/var/www/qaredi/tbox-v2/src/handlers/folders_delete.php:115), [app.js:6303](/var/www/qaredi/tbox-v2/public/assets/app.js:6303).

**04. 파일 업로드·여러 파일 올리기 — 현재 화면/API 모두 제공**

- 업로드 모달은 파일 선택/드롭, 설명, 등급/팀, 현재 폴더, 파일별 결과를 제공한다. 웹 요청은 `POST /api/files` multipart **한 요청 한 파일**, 응답 `{id,name}` 201. 프로그램용 `/api/upload`는 별도 응답 계약이지만 공통 저장 검증을 사용한다.
- 브라우저는 최대 50개를 한 번에 골라 순차 전송한다. 400은 해당 파일만 거부하고 다음 진행, 401/403은 중지한다. 파일 100MiB, 사용자 일일 2GiB 제한, 확장자·MIME·압축본·민감 패턴 검증. 목적 폴더 편집/등급 권한이 필요하다. 단순히 ‘공간 구성원’이라는 이유로 올릴 수 있는 API가 아니다.
- 업로드는 새 파일 저장이며 기존 파일 UUID의 바이트를 덮어쓰는 ‘새 버전 올리기’가 아니다. 서버에 위젯용 전체 업로드 큐/남은 일일 한도 조회 API는 확인되지 않았다.
- 위젯 가치 중간: 자주 올리는 폴더를 지정하면 유용. S=목적 폴더와 ‘파일 올리기’ 한 동작; M=드롭 영역+목적/등급 요약+마지막 결과; L=현재 선택한 파일별 큐/성공·실패. 권한/실행압축 확인은 기존 모달 동작 재사용이 전제이며 큐는 현재 브라우저 작업 상태다.
- 근거: [home.php:371](/var/www/qaredi/tbox-v2/src/handlers/home.php:371), [app.js:1508](/var/www/qaredi/tbox-v2/public/assets/app.js:1508), [app.js:5782](/var/www/qaredi/tbox-v2/public/assets/app.js:5782), [files_upload.php:86](/var/www/qaredi/tbox-v2/src/handlers/files_upload.php:86), [Upload.php:156](/var/www/qaredi/tbox-v2/src/Upload.php:156).

**05. URL로 파일 가져오기 — 권한/서버 허용 목록에 따른 조건부 제공**

- `POST /api/files/fetch {url,description?,access_level,team_id?,folder_id?}` → `{id,name}`. 허용된 HTTPS 파일 주소를 서버가 받아 같은 파일 정책으로 저장한다. Manager 이상 자격과 실제 허용 호스트 설정이 필요하다. `/api/me`의 `can_fetch`, `fetch_enabled`, 자격 있는 사람에게만 주는 `fetch_hosts_public`이 실제 UI 조건이다.
- 임의 웹 북마크/웹페이지 수집/외부 드라이브 동기화/로그인 쿠키 대행 기능으로 일반화하면 안 된다. 호스트·DNS/IP·용량·시간·리다이렉트 제한은 Fetch 계층의 통제다.
- 별도 상시 위젯보다는 업로드의 보조 동작 적합. S=허용 여부+가져오기 진입; M=목적 폴더/허용 호스트 안내+URL 입력 진입; L=URL·저장 범위·결과 확인 폼 후보. 설정 차단은 ‘자료 없음’과 구분한다.
- 근거: [files_fetch.php:107](/var/www/qaredi/tbox-v2/src/handlers/files_fetch.php:107), [Fetch.php:186](/var/www/qaredi/tbox-v2/src/Fetch.php:186), [me.php:82](/var/www/qaredi/tbox-v2/src/handlers/me.php:82), [me.php:216](/var/www/qaredi/tbox-v2/src/handlers/me.php:216), [app.js:5720](/var/www/qaredi/tbox-v2/public/assets/app.js:5720).

**06. 파일 설명·등급·팀·이동·이름 변경 — 웹/토큰 기능 범위가 다름**

- 웹 `PATCH /api/files/{id}`는 `{description,access_level,team_id,folder_id}` 중 변경한 항목만 받는다. 읽기와 편집, 노출 범위 변경, 목적 폴더를 다시 판단한다. 낮은 등급으로 넓히기는 Admin 재인증, 민감 파일의 public/internal 변경 금지, 실행압축 등급 잠금이 적용된다.
- **파일 이름 변경은 현재 개인 API 토큰 전용** `{name,expected_name}` 독립 요청이다. 웹 세션으로 보내면 403. 토큰도 업로더 본인 조건, 확장자 유지, `expected_name` 충돌 확인을 거친다. 웹 모달의 설명/등급 변경과 혼동하지 않는다.
- 위젯의 주 행동은 열기/미리보기, 보조 메뉴는 권한 있는 사람에게만. S=이름+상태+열기; M=설명/경로+더보기; L=선택 파일 상세와 기존 편집·이동 모달 진입. ‘빠른 이름 변경’은 현재 웹 기능으로 제안하면 안 되며 새 범위 결정이 필요하다.
- 근거: [files_update.php:59](/var/www/qaredi/tbox-v2/src/handlers/files_update.php:59), [files_update.php:98](/var/www/qaredi/tbox-v2/src/handlers/files_update.php:98), [files_update.php:180](/var/www/qaredi/tbox-v2/src/handlers/files_update.php:180), [app.js:1998](/var/www/qaredi/tbox-v2/public/assets/app.js:1998), [app.js:5484](/var/www/qaredi/tbox-v2/public/assets/app.js:5484).

**07. 다운로드·실행압축 확인·선택 파일 ZIP — 현재 제공, 세 경로 구별 필요**

- 일반 `GET /api/files/{id}/download`는 첨부 전송, 읽기 권한·검사 상태·원본 SHA 확인. 검사 중 423/재시도 안내, 감염 격리 거부. 실행 파일이 든 일반 압축본은 이 경로/미리보기/토큰 다운로드로 우회할 수 없다.
- `POST /api/files/{id}/exec-download {password,otp}`는 실행압축의 별도 사용자 확인 동작이다. 게시된 프로그램 받기는 17번의 **다른 계약**이다.
- `POST /api/files/zip {ids}`는 선택 파일 최대 100개/합계 500MiB, 각각 다시 권한·상태를 검사한다. 못 넣는 파일은 제외하고 제외 건수/결과 안내를 제공한다. 모든 선택 파일이 반드시 들어갔다고 표시하면 안 된다.
- S=한 파일 이름+허용된 받기; M=목록별 받기/검사 중 안내; L=다중 선택 ZIP 준비와 결과. 암호/OTP는 명시 확인 모달로 남기고 일반 카드에 상시 입력칸을 두지 않는다.
- 근거: [public/index.php:156](/var/www/qaredi/tbox-v2/public/index.php:156), [_download_common.php:104](/var/www/qaredi/tbox-v2/src/handlers/_download_common.php:104), [files_exec_download.php:64](/var/www/qaredi/tbox-v2/src/handlers/files_exec_download.php:64), [files_zip.php:46](/var/www/qaredi/tbox-v2/src/handlers/files_zip.php:46), [app.js:4779](/var/www/qaredi/tbox-v2/public/assets/app.js:4779).

**08. 파일 미리보기 — 형식별 제한된 읽기 기능**

- `GET /api/files/{id}/preview` → 공통 name/size/mime/ext/등급/올린 사람/날짜/`sha256_short`와 kind별 데이터. image/pdf는 `src`인 `/preview/raw`; txt/md는 text; csv는 table; zip은 entries; docx/pptx/xlsx/hwpx는 텍스트 발췌; 지원 안 되는 형식은 정보 카드다. 동영상/음성 업로드 허용과 별개의 기능이며 이 Preview 맵에는 플레이어가 없다.
- 텍스트 응답 64KiB, CSV 200행×50열, ZIP 200항목, Office 20,000자 상한과 truncated 표시가 있다. 문서 편집기/Office 원본 레이아웃 뷰어/파일 버전 비교는 아니다.
- **preview GET은 성공 감사 기록을 만들고 ‘최근 연 파일’에 반영된다.** 모든 카드의 본문을 자동 미리읽기하면 사용자 최근 목록을 바꾼다. 상시 자동 썸네일 수집 API처럼 사용하면 안 된다.
- S=형식/이름+미리보기 열기; M=선택한 파일의 간단 메타와 미리보기 동작; L=사용자가 선택한 1개 파일의 제한된 본문·다운로드. 자동 본문 표시는 별도 설계 판단 필요.
- 근거: [Preview.php:38](/var/www/qaredi/tbox-v2/src/Preview.php:38), [Preview.php:55](/var/www/qaredi/tbox-v2/src/Preview.php:55), [files_preview.php:169](/var/www/qaredi/tbox-v2/src/handlers/files_preview.php:169), [files_preview.php:233](/var/www/qaredi/tbox-v2/src/handlers/files_preview.php:233), [app.js:2280](/var/www/qaredi/tbox-v2/public/assets/app.js:2280).

**09. 즐겨찾기 파일·폴더 — 현재 제공, 두 목록은 별도**

- `GET /api/files/favorites` 최대 200파일, `GET /api/folders/favorites` 최대 50폴더. 파일/폴더 각각 `PUT/DELETE .../{id}/favorite`. 추가 순으로 정렬, 지금 읽을 수 있는 항목만 반환. 표시한 목록 길이는 보이는 항목 수이며 숨은 총량은 반환하지 않는다. 즐겨찾기는 권한 부여가 아니다.
- 자료실 화면에는 파일과 폴더 둘 다, 홈 start.js에는 파일만 있다. 파일 응답은 일반 메타+favorite/favorited_at, 폴더는 id/name/path/등급/팀/favorited_at. 서버에 사용자 정의 즐겨찾기 재정렬이나 폴더 묶음 API는 없다.
- 위젯 가치 높음. S=고정 대상 1~2개 실제 이름+열기; M=파일·폴더 4~6개+종류/경로; L=둘의 탭/분류+8~12개+별 해제와 전체 목록. 카드 전체가 설정 단추가 되지 않도록 항목 열기를 주 동작으로 둔다.
- 근거: [favorites_files.php:33](/var/www/qaredi/tbox-v2/src/handlers/favorites_files.php:33), [favorites_files.php:136](/var/www/qaredi/tbox-v2/src/handlers/favorites_files.php:136), [favorites_folders.php:34](/var/www/qaredi/tbox-v2/src/handlers/favorites_folders.php:34), [app.js:3307](/var/www/qaredi/tbox-v2/public/assets/app.js:3307), [start.js:47](/var/www/qaredi/tbox-v2/public/assets/start.js:47).

**10. 최근 연 파일 — 현재 제공, 최근 업로드/전체 활동과 다름**

- `GET /api/files/recent` → `{files,days:30,limit:20}`. 본인의 성공 `file.download`/`file.preview` 감사에서 파일별 마지막 시각을 취하고 현재 읽기 범위를 다시 적용한다. query로 다른 사람/기간/limit를 고를 수 없다. created_at과 last_used_at은 다른 필드다.
- 위젯 가치 높음. S=가장 최근 1~2개+마지막 연 시각; M=4~6개+폴더/형식; L=최근 20개 안에서 8~12행과 더 보기. 날짜 구분은 가능하지만 팀의 최근 업무·최근 수정 문서로 이름 붙이면 안 된다.
- 근거: [files_recent.php:7](/var/www/qaredi/tbox-v2/src/handlers/files_recent.php:7), [files_recent.php:48](/var/www/qaredi/tbox-v2/src/handlers/files_recent.php:48), [files_recent.php:105](/var/www/qaredi/tbox-v2/src/handlers/files_recent.php:105).

**11. 통합 검색 — 현재 화면 제공, 기존 두 API를 합성**

- 검색 모달은 `/community/api/search?q=`의 페이지/댓글과 `/tbox/api/files?q=`의 파일을 따로 요청해 합친다. 각 서비스의 현재 읽기 권한을 그대로 따른다. 별도 ‘전체 제품 검색’ 서버 API가 아니다. t-box 결과는 파일명/설명 검색이고 파일 본문 검색이 아니다.
- 위젯보다는 공통 셸 검색 유지 가치가 높다. S=검색 입력/실행; M=실행한 검색의 종류별 실제 결과 2~3개; L=페이지/댓글/파일 묶음별 결과와 더 보기. 파일명·문서명 입력 없는 장식 검색 타일은 가치가 낮다. 저장 검색/최근 검색 자동 수집은 현재 계약에 없다.
- 근거: [search.js:6](/var/www/qaredi/tbox-v2/public/assets/search.js:6), [search.js:212](/var/www/qaredi/tbox-v2/public/assets/search.js:212), [search.js:254](/var/www/qaredi/tbox-v2/public/assets/search.js:254).

**12. 여러 파일 일괄 작업 — 현재 화면 제공, 대부분 단건 API의 조합**

- 선택 최대 100개, 등급/팀 변경·폴더 이동·즐겨찾기 추가/해제·휴지통 보내기. ZIP만 전용 일괄 API이고 나머지는 단건 요청을 순차 실행한다. 파일별 성공/실패/중단, 세션 확인 후 이어가기, 삭제 후 10초 복구 안내가 있다. 이는 모든 항목이 한 번에 성공하는 트랜잭션이 아니다.
- S=‘선택 n개’만 상시 카드로 두기보다 상세 목록 진입; M=선택한 항목+가능한 한 동작+결과; L=진행 목록/실패 이유/재개. 전용 위젯보다는 자료 목록의 확장 동작이 적합하다.
- 근거: [home.php:254](/var/www/qaredi/tbox-v2/src/handlers/home.php:254), [app.js:4300](/var/www/qaredi/tbox-v2/public/assets/app.js:4300), [app.js:4614](/var/www/qaredi/tbox-v2/public/assets/app.js:4614), [app.js:4736](/var/www/qaredi/tbox-v2/public/assets/app.js:4736).

**기능별 조사 — 공유와 접근, 보존**

**13. 개인 항목의 사람 지정 권한·공유받음 — 현재 제공**

- `GET/POST /api/files/{id}/grants`, `GET/POST /api/folders/{num}/grants`, 대상별 `DELETE .../grants/{userId}`. GET은 `{item,effective,can_manage,is_owner,grants}`; 직접 권한과 상위 개인 폴더에서 이어지는 권한을 구별한다. POST `{user:아이디 또는 이름,write:boolean}`. `GET /api/access/people?q=`은 활성 계정 후보 최대 20명이다.
- 권한 관리는 소유자 또는 Admin 특권+재인증. Guest는 보기만 받을 수 있다. 일반 편집 수령자가 다시 공유할 수 있는 기능이 아니다. private가 아닌 상태에서는 기존 권한 줄이 있어도 효과가 없다.
- `GET /api/grants/mine`은 **나에게 직접 부여된 개인 폴더/파일** 최근 200줄에서 유효한 항목. 폴더 grant로 열리는 모든 자손을 낱개로 확장한 목록이나 팀 공유 전체가 아니다. `{folders,files}`에 owner/write/granted_at 포함.
- ‘공유받은 자료’ 위젯 가치 높음. S=가장 최근 대상+누가 공유했는지+열기; M=4~6개+보기/편집 표식; L=폴더/파일 분리+소유자·받은 때·선택 상세. 권한 편집은 해당 항목의 기존 권한 모달에 남긴다.
- 근거: [access_grants.php:62](/var/www/qaredi/tbox-v2/src/handlers/access_grants.php:62), [access_grants.php:138](/var/www/qaredi/tbox-v2/src/handlers/access_grants.php:138), [access_mine.php:20](/var/www/qaredi/tbox-v2/src/handlers/access_mine.php:20), [app.js:3689](/var/www/qaredi/tbox-v2/public/assets/app.js:3689).

**14. 권한 요청·승인·거절·철회 — 요청/승인 UI 제공, 철회는 API 존재**

- `POST /api/access-requests {file:UUID|folder:번호,write?,message?}`. 메모 200자/하루 20건. 없는/비개인/중복 대상도 같은 응답으로 존재 노출을 막으므로 응답만 보고 실제 등록/처리 대기 건수를 늘리면 안 된다.
- `GET /api/access-requests?box=in|out` → `{box,requests,pending}`. 대기 우선·결정 내역 30일·최대 100줄. 받은 항목은 요청자/메모/원한 권한/현재 대상 정보, 보낸 쪽은 현재 볼 수 없는 대기 항목이 목록에서 빠진다. 승인 뒤 보이는 대상은 열 수 있다.
- `POST /api/access-requests/{num} {decision:approve_view|approve_write|reject}`는 소유자(소유자 비활성 시 Admin 대리)에게만. `DELETE /api/access-requests/{num}`는 보낸 사람의 대기 요청 철회로 구현되어 있으나 현재 `accessOutRow`에는 승인 항목 열기만 있고 철회 단추는 없다.
- `/api/me.access_requests_pending`과 목록의 pending은 서버가 세는 **내가 처리할 수 있는 받은 대기 건수**다. 잘린 100행을 세지 않고 이 값을 쓸 수 있다. 커뮤니티 알림에도 요청/결정이 연결된다.
- 위젯 가치 매우 높음. S=정확한 대기 n건+요청자/대상 1개+검토; M=요청 3개+원한 권한/메모 일부+검토; L=대기/결정 목록+선택 요청의 보기 허락/편집 허락/거절. 보낸 요청의 숨김 정책과 Guest 편집 불가를 유지한다.
- 근거: [access_requests.php:57](/var/www/qaredi/tbox-v2/src/handlers/access_requests.php:57), [access_requests.php:168](/var/www/qaredi/tbox-v2/src/handlers/access_requests.php:168), [access_requests.php:248](/var/www/qaredi/tbox-v2/src/handlers/access_requests.php:248), [_access_common.php:294](/var/www/qaredi/tbox-v2/src/handlers/_access_common.php:294), [app.js:4136](/var/www/qaredi/tbox-v2/public/assets/app.js:4136).

**15. 만료 공유 링크 — 현재 제공, 사람 지정 권한과 다른 기능**

- `POST /api/files/{id}/share {expires_hours,max_downloads,reason_text?}` → `{id,url,expires_at,max_downloads}`. 기본 24시간/1회, 최대 7일/10회. 원문 URL은 만들 때 **한 번만** 보여준다. 생성 자격은 일반 파일 편집 조건, 개인 파일은 소유자이며 Admin 경로는 재인증.
- `GET /api/me/shares`는 내가 만든 최근 100개, `DELETE /api/shares/{num}`는 만든 사람/Admin 회수. 목록은 file_name/expires_at/max_downloads/download_count/revoked_at/is_expired 등이며 원문 URL·토큰은 없다. `GET /s/{token}`은 익명 받기, 발급자 현재 권한/상태·파일 검사·만료/회수/횟수를 매번 확인한다.
- 위젯 가치 중간: 링크를 관리하는 사람에게만. S=최근 공유 파일+만료/사용 횟수+관리; M=활성으로 보이는 최근 링크 3~5개와 상태; L=만료/회수 포함 기록+회수 확인. ‘링크 다시 복사’는 기존 목록 API로 불가능하다. 현재 발급자 권한상실까지 목록이 계산하는 ‘실제로 다운로드 가능한 링크 수’ API도 없다.
- 근거: [public/index.php:357](/var/www/qaredi/tbox-v2/public/index.php:357), [shares_list.php:26](/var/www/qaredi/tbox-v2/src/handlers/shares_list.php:26), [shares_list.php:47](/var/www/qaredi/tbox-v2/src/handlers/shares_list.php:47), [app.js:2616](/var/www/qaredi/tbox-v2/public/assets/app.js:2616), [PRD.md:215](/var/www/qaredi/tbox-v2/PRD.md:215).

**16. 보안 표시·검사·민감 정보·휴지통·내 활동 — 여러 계약을 구별**

- 파일 행의 `sensitive`, `scan_status`(pending/clean/infected), `exec_content`, access_level은 콘텐츠 상태다. 주민번호/카드번호/민감 키워드 패턴 검사는 일부 형식 대상이며 ‘민감 없음=안전 인증’이 아니다. 검사 중/격리/실행압축은 일반 받기·미리보기 차단 이유로 그대로 보여야 한다. 전체 파일 보안 통계/재검사 위젯 API는 없다. 근거: [Sensitive.php:37](/var/www/qaredi/tbox-v2/src/Sensitive.php:37), [_upload_common.php:356](/var/www/qaredi/tbox-v2/src/handlers/_upload_common.php:356), [files_list.php:290](/var/www/qaredi/tbox-v2/src/handlers/files_list.php:290).
- `DELETE /api/files/{id}`는 논리 삭제, `/trash`와 `GET /api/files/trash?page=`는 `{files,total,page,per_page:20}`. Admin은 전체/재인증, 다른 업로드 가능 사용자는 **내가 올리고 내가 지운** 파일만. `POST /api/files/{id}/restore`로 아직 영구 정리되지 않은 행을 복구한다. 일괄 삭제 UI의 10초 되돌리기도 이 API다. 사용자용 즉시 영구 삭제/폴더 버전 복원은 아니다. 근거: [files_trash.php:34](/var/www/qaredi/tbox-v2/src/handlers/files_trash.php:34), [Authz.php:974](/var/www/qaredi/tbox-v2/src/Authz.php:974), [public/index.php:267](/var/www/qaredi/tbox-v2/public/index.php:267).
- 휴지통 위젯은 선택형: S=복구 가능한 총 n개+마지막 삭제 파일+휴지통; M=최근 삭제 3개+삭제 시각; L=목록·선택 파일 복구 확인. `deleted_at+30일`은 정책상 정리 기준이지 실제 영구 삭제 실행 시각을 보장하는 타이머가 아니다.
- `/activity`, `GET /api/me/activity?page=`는 본인의 지정된 감사 행동 20건과 total. `{happened_at,action,result,reason,target_name,summary}`만, IP/UA/원본 detail 없음. 거부된 대상 이름은 가림. **파일 버전 이력이나 바이트 이전판 복원과 무관하다.** 활동 위젯 S=최근 실제 행동 1개+시각; M=4개; L=8~12개/페이지+전체 활동. 근거: [activity_list.php:70](/var/www/qaredi/tbox-v2/src/handlers/activity_list.php:70), [activity_list.php:149](/var/www/qaredi/tbox-v2/src/handlers/activity_list.php:149), [app.js:9822](/var/www/qaredi/tbox-v2/public/assets/app.js:9822).

**17. 프로그램 배포함 — 현재 제공, 한 제품의 현재 게시판**

- `/programs`, `GET /api/programs` → `{programs:[{id,name,version,sha256,size_bytes,access_level,published_at,uploaded_by}]}`. 코드상 `program_current && scan_status=clean && visibleFilesWhere`, **최대 1개**다. 일반 앱스토어·복수 제품 카탈로그·내 PC 설치 목록이 아니다.
- 받기는 `POST /api/programs/{id}/download {ack:true}` + 세션/CSRF/현재 원본 권한·스캔·해시. 일반 실행압축 받기의 매 요청 비밀번호+OTP와 구별한다.
- 게시 `POST /api/programs`는 실제 Admin·역할로 보기 아님, 본인이 올린 루트/admin-only/clean/비민감 실행 ZIP만 후보. 버전 문자열·SHA·배포 등급/팀·확인·비밀번호/새 OTP, 검사 켜짐과 실제 검사 감사 기록 필요. 새로운 게시가 이전 current를 해제한다. version은 라벨이며 서버가 PC 설치 버전과 비교해 ‘업데이트 필요’를 판정하지 않는다.
- 위젯 가치 중간/실용적: S=현재 프로그램명·버전+받기 진입; M=게시일·크기·설치/업데이트 안내; L=SHA 확인·변경/설치 안내 영역+명시 다운로드 확인. 게시·배포 범위 변경은 전체 관리자 흐름 유지. 비어 있으면 ‘권한 내 현재 게시 없음’, 설치됨 여부는 추정하지 않는다.
- 근거: [Programs.php:12](/var/www/qaredi/tbox-v2/src/Programs.php:12), [Programs.php:24](/var/www/qaredi/tbox-v2/src/Programs.php:24), [Programs.php:36](/var/www/qaredi/tbox-v2/src/Programs.php:36), [programs.php:11](/var/www/qaredi/tbox-v2/src/handlers/programs.php:11), [programs.js:17](/var/www/qaredi/tbox-v2/public/assets/programs.js:17).

**기능별 조사 — 내 계정과 운영 관리**

**18. 로그인·로그아웃·비밀번호·MFA·패스키·재인증 — 현재 제공, 기기/설정 조건 존재**

- 로그인/로그아웃, 내 비밀번호 변경, TOTP 등록/enroll·confirm·disable, 패스키 로그인/options, 내 패스키 등록/options·목록·해제, 비밀번호/패스키 재인증 경로가 있다. 비밀번호 변경은 현재 확인과 최소 길이/동일 비밀번호 금지, 다른 기기 세션 종료. MFA 해제는 비밀번호와 새 코드 확인. 계정 잠금/복구도 서버 관문이다.
- `/api/me`는 `next`, MFA 필요/설정/권장/복구, `reauth_fresh`, `passkey_available`, `passkeys_active`를 제공한다. `/api/me/passkeys`는 `{available,passkeys,max}`와 이름/기기 힌트/등록·마지막 사용/active|stale/synced. 최대 5개, 미사용 90일 상태. 실제 등록 가능 여부는 서버 origin/준비 상태와 브라우저 지원에 따른다.
- 위젯은 **내 계정 보안 상태와 다음 한 단계**에 적합. S=MFA/등록기기 상태 중 중요한 한 줄+설정; M=상태 2~3개+필요 행동; L=기기 요약·마지막 사용·계정 설정 진입. 비밀번호·OTP·복구·기기 삭제는 기존 계정/확인 모달 유지. 실시간 로그인 기기·현재 온라인 세션 목록 API와 같지 않다.
- 근거: [public/index.php:94](/var/www/qaredi/tbox-v2/public/index.php:94), [public/index.php:221](/var/www/qaredi/tbox-v2/public/index.php:221), [public/index.php:380](/var/www/qaredi/tbox-v2/public/index.php:380), [me.php:153](/var/www/qaredi/tbox-v2/src/handlers/me.php:153), [me_passkeys_list.php:17](/var/www/qaredi/tbox-v2/src/handlers/me_passkeys_list.php:17), [settings_page.php:118](/var/www/qaredi/tbox-v2/src/handlers/settings_page.php:118).

**19. 프로그램 토큰·등록 PC 로그인 — 현재 제공, 권한 위임 수단**

- 내 토큰 `GET/POST /api/me/api-tokens`, `DELETE /api/me/api-tokens/{num}`. 목록에 라벨/용도/hint/범위/생성·만료·마지막 사용·회수·bound_ip, `expires_soon`이 있다. 비밀 원문은 발급 때 한 번만. 본인 발급은 password+OTP/목적/허용 범위, 180일/살아 있는 토큰 5개 제한. 관리자는 남의 목록/회수만 가능하고 **남의 토큰 발급 라우트는 제거**됐다.
- 현재 범위 후보는 read/upload/delete/community.read/community.write/travel.read/travel.write/session.open, 설정이 켜지면 assistant.run 등 서버 `tokenScopesFor` 반환값이다. 예전 문서의 3종/6종 고정 선택을 재사용하지 않는다. 범위를 가지고 있어도 대상 파일/문서 권한은 늘지 않는다.
- `POST /api/me/session-link`는 개인 토큰/허용 scope/묶인 IP로 30초 1회용 링크 발급, `/session/open`은 교환한다. 단순 브라우저 홈 위젯 기능이 아니며 토큰을 레이아웃 JSON에 넣어 호출할 이유도 없다.
- 위젯 후보는 만료/연결 관리. S=회수되지 않은 토큰의 만료 주의 1건+설정; M=이름·용도·만료·마지막 사용 3개; L=범위/상태 목록+관리 진입. `expires_soon`은 이미 만료된/회수된 행에도 존재하므로 회수·만료 상태를 구별한다. 실제 PC가 실행 중인지/연결 성공인지 이 목록으로 단정하면 안 된다.
- 근거: [me_tokens_list.php:32](/var/www/qaredi/tbox-v2/src/handlers/me_tokens_list.php:32), [public/index.php:216](/var/www/qaredi/tbox-v2/public/index.php:216), [public/index.php:240](/var/www/qaredi/tbox-v2/public/index.php:240), [Authz.php:1540](/var/www/qaredi/tbox-v2/src/Authz.php:1540), [public/index.php:253](/var/www/qaredi/tbox-v2/public/index.php:253).

**20. 화면 취향·테마·내 정보·동의·탈퇴 — 현재 제공, 저장 위치 다름**

- `/settings`의 내 정보/팀은 표시 기능이며 본인 계정 이름·역할·팀을 자유 편집하는 프로필 API가 아니다. 고대비는 `PATCH /api/me/settings {high_contrast:boolean}`로 계정에 저장한다. 디자인 테마/움직임은 allowlist 쿠키로 **현재 브라우저**에 저장, API 호출 없음. 대시보드 레이아웃 계정 저장과 구별한다.
- `/privacy`, `/terms`, `/consent`, `GET/POST /api/me/consents`로 문서 버전별 동의/확인·AI 국외 전송 동의/철회. `GET/POST /api/me/delete-request`는 열린 요청 확인/`{confirm:true}` 탈퇴 요청. 요청은 재인증 필요, 관리자 처리 전 즉시 삭제가 아니다. 승인 동의/탈퇴를 홈 카드의 기본 동작으로 삼지 않는다.
- S=내 정보/필수 설정 상태+계정 열기; M=테마/고대비 현재 값+설정 진입; L=지원 가능한 취향 제어+동의/탈퇴 상태 링크. 테마 전용 위젯은 정보/업무 가치가 낮아 공통 메뉴 유지가 우선이다.
- 근거: [settings_page.php:68](/var/www/qaredi/tbox-v2/src/handlers/settings_page.php:68), [public/index.php:229](/var/www/qaredi/tbox-v2/public/index.php:229), [design.js:27](/var/www/qaredi/tbox-v2/public/assets/design.js:27), [me_consents.php:25](/var/www/qaredi/tbox-v2/src/handlers/me_consents.php:25), [me_delete_request.php:9](/var/www/qaredi/tbox-v2/src/handlers/me_delete_request.php:9).

**21. 계정 관리·자격 초기화·탈퇴 처리 — Admin+재인증 전용 현재 제공**

- `/users`, `GET /api/users?page=` 50행+total. username/display_name/role/team/active/만료/MFA/잠금/생성·수정/is_self. 생성은 서버 임시 비밀번호 1회 표시, Guest 만료 등 규칙. PATCH는 역할/팀/활성/만료 및 로그인 아이디 변경, 사유 필수. 자기 계정 핵심 변경/마지막 Admin 보호, 검토·공용 토큰 귀속 계정 이름 제한이 있다.
- 비밀번호 재발급, MFA 초기화, 대상 개인 토큰 목록/회수는 별도 동작. 다른 사람의 비밀 토큰 발급이나 패스키 열쇠 목록 열람은 제공하지 않는다. 조직도 배정도 이 계정 변경 API를 사용한다.
- `/api/admin/deletion-requests`는 최대 200줄과 purge_days, 요청/처리/정리 시각, 남은 개인 페이지/사진 이야기/여행 계획 건수. POST 해당 요청 `{confirm:true}`는 실제 탈퇴 처리다. 일반 권한 요청 승인과 합치면 안 된다.
- 위젯은 관리자 선택형. S=받은 탈퇴 요청 1개/확인 필요 항목+관리; M=요청 3개/계정 상태+목록 열기; L=남은 자료 요약·처리 내역 목록. 계정 생성·역할·초기화·탈퇴 처리 버튼은 상세 확인 화면 유지. 50행의 잠금 수를 전체 계정 지표로 표시하면 안 된다.
- 근거: [users_list.php:23](/var/www/qaredi/tbox-v2/src/handlers/users_list.php:23), [users_list.php:60](/var/www/qaredi/tbox-v2/src/handlers/users_list.php:60), [public/index.php:193](/var/www/qaredi/tbox-v2/public/index.php:193), [Authz.php:890](/var/www/qaredi/tbox-v2/src/Authz.php:890), [admin_deletion_requests.php:40](/var/www/qaredi/tbox-v2/src/handlers/admin_deletion_requests.php:40), [app.js:7590](/var/www/qaredi/tbox-v2/public/assets/app.js:7590).

**22. 팀/조직·구성원 배정 — Admin+재인증 전용 현재 제공**

- `/org`, `GET/POST /api/org/teams`, `PATCH /api/org/teams/{num}`, 팀 members GET, `/api/org/permissions` 읽기 표. 생성·이름·비활성·구성원 배정. 팀은 삭제 대신 비활성, 활성 계정이 남아 있으면 제한한다.
- 팀 목록에는 **집계된** 활성 member_count/manager_count/살아 있는 file_count가 있다. 이 수는 Admin 조직 관리 기준이지 개인이 읽을 수 있는 파일 수가 아니다. ‘제품 공간(company/group)의 멤버십’과 기존 조직 team_id는 별도 모델이다.
- 일반 사용자 위젯으로 전용하지 않는다. Admin S=특정 팀 이름/활성 인원·관리자 수+조직; M=팀 3~5개 집계; L=팀 목록+선택 구성원. 배정/역할 변경은 기존 상세 흐름 유지.
- 근거: [org_teams.php:7](/var/www/qaredi/tbox-v2/src/handlers/org_teams.php:7), [org_teams.php:64](/var/www/qaredi/tbox-v2/src/handlers/org_teams.php:64), [public/index.php:313](/var/www/qaredi/tbox-v2/public/index.php:313), [app.js:8894](/var/www/qaredi/tbox-v2/public/assets/app.js:8894).

**23. 권한표·가져오기 허용 사이트·역할로 보기 — Admin+재인증 전용 현재 제공**

- `/admin/permissions`, `GET/PATCH /api/admin/permissions`: 일반 4등급×4역할 16행, can_read/can_upload/locked/rules. private는 사람 지정 권한이며 이 표 편집에서 제외된다. 변경은 changes/reason_text/OTP와 고정 불변 규칙을 거친다.
- `/admin/fetch-hosts`, `GET/POST /api/fetch-hosts`: host/note/active/생성·수정·작성자/rules. 추가·활성/비활성, 삭제 없음; changes/reason_text/OTP 확인. 일반 사용자의 웹 링크 관리 기능이 아니다.
- `POST /api/me/act {role,team_id?}`와 DELETE는 관리자 자신의 유효 역할을 낮춰 보기/복귀, 실제 계정 바꾸기나 권한 부여 기능이 아니다. `/api/me`는 acting/acting_as/can_stop_acting을 준다. 편집·특권 UI는 각 기능의 별도 제한을 따라야 한다.
- 상시 위젯의 효용 낮음. S=관리 범위/역할로 보기 상태+설정; M=현재 보기 역할·팀/돌아가기; L=읽기 요약과 관리 화면 진입까지. 권한 매트릭스/호스트 편집·OTP 입력은 전체 관리 화면 유지.
- 근거: [admin_permissions_get.php:64](/var/www/qaredi/tbox-v2/src/handlers/admin_permissions_get.php:64), [fetch_hosts_list.php:40](/var/www/qaredi/tbox-v2/src/handlers/fetch_hosts_list.php:40), [public/index.php:406](/var/www/qaredi/tbox-v2/public/index.php:406), [me.php:186](/var/www/qaredi/tbox-v2/src/handlers/me.php:186), [admin_page.php:123](/var/www/qaredi/tbox-v2/src/handlers/admin_page.php:123).

**24. 감사 로그·전체 공유 링크·경보·푸시 — Admin+재인증 전용, 상태/준비 구별**

- `/audit`, `GET /api/audit`: 기간/행위자/IP/대상/등급/행동/결과/이유/허용 근거 필터, 20행+total. 개인 내 활동보다 상세하다. 사건 수·추세 전용 지표 API는 아니다.
- `/api/admin/shares`는 최근 200개 전체 공유 링크 관리 목록, 비밀 원문 없음. `/api/admin/alerts`는 로그 끝 50줄에서 읽은 `{alerts,skipped,readable}`. alert row는 raised_at/rule/dispatch/evidence/origin/title. **readable=false와 경보 0건은 다르다.** 읽음/해결/담당자 지정 기능은 없다.
- `/alerts-app`, `/api/admin/push/key`, subscriptions GET/POST/DELETE, test POST. 본인 최대 3기기, 목록은 host/device_hint/생성/마지막 성공. 서버 공개 키/표/브라우저 알림 준비가 필요하며 미준비는 503일 수 있다. 현재 설치/실수신은 코드만으로 확인할 수 없다.
- 선택형 관리자 위젯 가치: S=마지막 경보 제목·시각+열기; M=3개+전송 상태; L=8개/근거·감사 화면 연결. ‘미해결 n건’은 상태가 없어서 만들 수 없다. 구독 관리·테스트 전송은 기존 관리자 화면 유지.
- 근거: [audit_list.php:100](/var/www/qaredi/tbox-v2/src/handlers/audit_list.php:100), [admin_shares.php:46](/var/www/qaredi/tbox-v2/src/handlers/admin_shares.php:46), [admin_alerts.php:44](/var/www/qaredi/tbox-v2/src/handlers/admin_alerts.php:44), [admin_alerts.php:114](/var/www/qaredi/tbox-v2/src/handlers/admin_alerts.php:114), [push_subscriptions.php:36](/var/www/qaredi/tbox-v2/src/handlers/push_subscriptions.php:36), [push_key.php:24](/var/www/qaredi/tbox-v2/src/handlers/push_key.php:24).

**25. 도움말·위키·사이트 변경 이력·헬스·서버 운영 — 제공 방식이 제각각**

- 각 화면 사용 방법은 `src/help/screens.php` 고정 안내를 셸 모달로 렌더한다. 위키는 Admin/재인증, qaredi/tbox/community 책과 글을 읽는 저장소 기반 화면이다. 사이트 v2.55.0과 변경 이력은 `VERSION`/Changelog의 셸 UI다. 파일 버전 이력과 다르다.
- 공개 `/api/health`는 DB 연결/질의 성공과 서버 시각만 준다. 전체 서버 자원/디스크/저장 용량/백업 성공/악성코드 큐 상태는 주지 않는다. 백업·복호화·복원·배포·스캐너 운영은 서버 스크립트/운영 절차이며 사용자용 대시보드 API로 확인되지 않았다.
- S=버전 또는 관련 도움말+열기; M=현재 화면 안내 2~3개; L=최근 사이트 변경 항목. 위키/관리 감사/서버 운영을 일반 홈에 자동 펼칠 필요는 낮다. ‘서버 정상’은 health의 DB 확인 범위만 명시해야 한다.
- 근거: [shell_top.php:130](/var/www/qaredi/tbox-v2/src/views/shell_top.php:130), [shell_bottom.php:37](/var/www/qaredi/tbox-v2/src/views/shell_bottom.php:37), [shell_bottom.php:114](/var/www/qaredi/tbox-v2/src/views/shell_bottom.php:114), [public/index.php:336](/var/www/qaredi/tbox-v2/public/index.php:336), [health.php:45](/var/www/qaredi/tbox-v2/src/handlers/health.php:45).

**실제 데이터가 있는 우선 위젯 후보 비교**

| 후보 | S에서도 남길 실제 정보/동작 | M에서 늘어나는 정보 | L에서 늘어나는 정보/동작 | 현재 재사용할 데이터 | 상세 화면에 남길 것 |
|---|---|---|---|---|---|
| 최근 파일 | 파일명 1~2개·마지막 연 시각·열기 | 4~6개·경로/형식 | 8~12개·선택 상세/미리보기 | `/api/files/recent` | 전체 탐색/일괄 관리 |
| 즐겨찾기 | 고정 파일/폴더 실제 이름·열기 | 둘을 합친 4~6개·경로 | 파일/폴더 분류·목록·별 해제 | favorites 두 API | 새 항목 찾기·복잡한 폴더 탐색 |
| 지정 폴더 | 폴더명·보이는 직접 파일 수·열기 | 하위 폴더와 최근 업로드된 그 폴더 파일 | 폴더 한 층 탐색·목록 정렬/페이지 | folders + files?folder | 등급 cascade/폴더 삭제 |
| 공유받은 자료 | 대상·공유한 사람·열기 | 4~6개·보기/편집 | 폴더/파일·공유 시각·상세 | `/api/grants/mine` | 권한 편집 |
| 받은 권한 요청 | 정확한 대기 수·요청자/대상 1개·검토 | 요청 3개·권한·짧은 메모 | 선택 요청 상세·권한별 승인/거절 | `/api/me`, `/api/access-requests` | 대리 처리 재인증/새 권한 부여 상세 |
| 내 공유 링크 | 최근 파일·만료/사용 횟수·관리 | 최근 링크 몇 개와 상태 | 이력·선택 링크 회수 확인 | `/api/me/shares` | 신규 URL 1회 표시/범위 확인 |
| 업로드 | 목적 폴더·올리기 | 드롭/대상 범위·마지막 결과 | 현재 브라우저 파일 큐/결과 | 기존 upload API, 로컬 큐 | 특수 파일 자격 확인 |
| 내 계정 연결 | MFA/기기 또는 만료 주의 한 항목·설정 | 기기/토큰 라벨·상태 | 최근 사용·만료/범위 요약 | `/api/me`, 내 passkeys/tokens | 비밀번호/OTP/발급/복구/탈퇴 |
| 프로그램 | 현재 게시명·버전·받기 진입 | 날짜·크기·업데이트 안내 | SHA/주의·명시 받기 | `/api/programs` | 관리자 게시 |
| 휴지통/내 활동 | 실제 마지막 항목·시각·열기 | 3~5행 | 목록/페이지·선택 복구 | trash/activity | 관리자 전체 복구·감사 조사 |
| 관리자 경보 | 최근 경보 제목·시각·확인 | 최근 3개·전송상태 | 근거 포함 목록·감사 링크 | `/api/admin/alerts` | 권한표·호스트·구독·계정 관리 |

행 수는 정보 밀도 제안이다. 현재 콘텐츠 API의 상한과 표시 가능한 실제 높이를 고려해 조정해야 하며, 크기만 늘리고 똑같은 6행을 내부 스크롤하는 현재 홈을 S/M/L 완성으로 간주하지 않는다. 작은 카드도 ‘권한 요청 3’ 같은 숫자만 두지 않고 어떤 요청인지 한 건은 보여야 한다.

**설계에서 새로 만들어야 하거나 잘못 추정하기 쉬운 계약**

1. 파일의 이전 버전/복원/비교, 덮어쓰기 업로드, 웹 파일 이름 변경, 중복 파일 정리, 파일 본문 전문검색, 전체 디스크/개인 용량·남은 일일 업로드량 통계는 현재 확인한 위젯 API에 없다. 일반 파일 조작 라우트/업로드 INSERT와 토큰 이름 변경 계약을 근거로 구별했다.
2. 최근 업로드 전체 목록, 특정 팀의 ‘모든 활동’, 인기 파일/조회수·다운로드 추세, 개인에게 새로 공유된 전체 재귀 항목 총량, 미해결 보안 사건 수는 현재 목록 API에서 바로 나오지 않는다. 페이지/상한 배열 길이를 전체 지표로 포장하지 않는다.
3. `can_*`는 화면 표시 자료, 실제 행 권한은 별도 재검사다. Dashboard layout v2의 `revision`은 **배치 저장 충돌값**이며 파일 버전이나 업무 데이터 버전이 아니다. 파일 PATCH는 일반 레이아웃 revision 계약을 공유하지 않는다.
4. 제품 공간의 `file` 항목은 원본 t-box 파일을 연결한 별도 콘텐츠 모델이다. 제품 공간 멤버십으로 원본 파일의 읽기 권한이 늘지 않는다. 기존 team 조직의 집계/관리자를 제품 공간 관리자와 합치지 않는다.
5. 모든 위젯은 서버 GET만으로 만들 수 있다는 가정도 틀릴 수 있다. 파일 미리보기 GET은 감사/최근 사용을 갱신하고, 관리자 GET은 재인증 관문에 걸린다. 기본 렌더는 목록 메타 중심, 본문/민감 관리 내용은 명시 선택으로 여는 것이 현재 동작과 맞다.
6. 실제 바로가기: `/tbox/?file=UUID`는 권한 조회 후 미리보기/요청 화면, `?folder=번호`, `?requests=in|out`, `?view=shared`가 구현되어 있다. 현재 홈 파일명 검색 링크를 UUID 링크로 개선할 근거가 이미 있다. 반면 `?view=favorites|recent`나 `?upload=1`을 이미 지원한다고 가정하지 않는다. 근거: [app.js:4231](/var/www/qaredi/tbox-v2/public/assets/app.js:4231), [app.js:4250](/var/www/qaredi/tbox-v2/public/assets/app.js:4250).
7. 파일 목록/최근/즐겨찾기/공유받음의 응답 필드가 같지 않다. 예를 들어 grants와 can_manage_access는 기본 파일 목록에 있고 최근/즐겨찾기에는 없다. 화면에서 공통 스키마로 추정해 편집 버튼을 노출하지 말고 source별 adapter 또는 대상별 조회가 필요하다.
8. 현재 요청의 ‘철회’와 폴더 ‘이동’처럼 API가 있지만 일반 UI에 노출되지 않은 항목은 **기능 확장 후보**로 표시해야 한다. 기존 기능의 위젯 재배치와 같은 비용으로 계산하지 않는다.

이번 조사로 문서/코드를 근거로 한 제품 지도가 확보되었다. 사용자별 실제 데이터와 활성 설정을 확인하거나 위젯별 수락 시나리오를 실행한 결과는 아직 아니며, 이후 설계 확정과 구현 단계에서 별도로 검증해야 한다.
