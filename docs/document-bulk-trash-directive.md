# 내 문서·업무 사이드바 선택 삭제 — 2026-10-12

기준 e618320c6297843dd9367971849593b4e49c5db8/v2.60.0, branch cloud/document-bulk-trash. 실제 담당 Codex root/구현2명/별도 독립검토. 사용자 요청: 항상 펼쳐진 업무 목록 정리, 내문서·사이드바에서 개별·부분·전체선택 삭제. 기존사용자원본에 시험삭제0, 운영시험은 새예약QA문서만. C:F-17/20, Q:F-3a/12, C:S-01/02/04, DESIGN2.19, CommunityAuthz와 기존 BulkRunner/휴지통 계약을 따른다.

## 확정 동작

1. 최근·프로젝트·문서 상위묶음은 기본접기. 사용자가 직접 여닫은 PC 상태는 사용자별허용목록/boolean 선호로기억하되 모바일프로그램접기/초기화/화면왕복이PC선호를덮지않는다. 잘못된storage/저장거부는기본동작유지. 기존즐겨찾기/모바일native dialog/초점/한묶음펼침은보존.
2. 내문서 일반트리·검색목록 둘다 행의휴지통동작과 부분/현재목록전체선택·선택휴지통을명확하게제공. 검색어/현재공간/필터를선택시지우지않는다. 목록/필터/공간/쪽변경때기존선택을초기화해숨은삭제를막는다. 내문서의다목적선택은기존일괄즐겨찾기를유지하기위해읽기항목을허용한다. 휴지통은can_delete허용분만대상으로하며혼합선택은확인창에전체N/허용M/제외K를명시한다. 전부삭제불가면휴지통버튼비활성. 사이드바의삭제전용선택은권한없는항목을고를수없고사유/비활성으로구별한다. 기존옮기기/즐겨찾기 기능은유지하고변경범위에서회귀.
3. 사이드바 문서행(개인/팀 트리·최근·즐겨찾기, 페이지형프로젝트 포함)에 별아이콘과구분되는 휴지통아이콘. 선택 진입→checkbox·현재보이는문서전체선택/해제·선택수·휴지통·완료. 동일문서가여러곳에보이면UUID로선택동기화/요청중복0. 프로젝트/그외내비 링크 자체가 아닌 page UUID 항목만. 팀공간·게시판·포트폴리오그룹 자체삭제는추가하지않는다.
4. 전체선택은 현재조회/표시된항목(사이드바는삭제허용항목만), 한 번에기존100개한도. 계정전체나검색다음쪽까지묵시적자동확장0. 100초과일때선택100/전체개수·추가정리안내를명시하고전체완료로속이지않는다. sidebar collapsed자손/닫힌그룹은전체선택으로새로선택하지않는다. 직접고른항목은확인창에서제목·건수로확인가능. 부모삭제는선택하지않은하위도함께휴지통으로가므로반드시확인문구유지; 알수없는하위총수는만들지않는다.
5. 기존 DELETE /community/api/pages/{id} 순차실행, 권한은서버가매번재검사. 기존 createBulkRunner의401/403·같은계정재개·부분실패/실패사유·중복방지·성공뿌리만10초되돌리기/30일휴지통복원을재사용. 선택부모/자식은한번처리, 필터로부모없는orphan도누락없이처리. 찾지못한부모관계를추정하거나거부응답을성공으로세지않는다. native확인창취소/ESC는전송0, 중복클릭0, 모바일사이드바위확인/결과창도초점·닫기정상.
6. 열린편집기의내용보호: 새sidebar삭제전저장flush/진행중저장/409상태를확인. 저장실패·미해결충돌이면삭제차단하고쓰던글보존. 현재문서의조상이포함될수있으므로직접선택id만보지않는다. 성공삭제/되돌리기후 내문서·최근·즐겨찾기·트리·프로젝트목록갱신. 현재문서가실제로휴지통으로갔을때만안전한내문서화면으로이동/상태정리; 관련없는삭제에서편집내용/커서유지. 단순cap조회용 GET pages/{id} N+1은하지않는다(조회이력부작용).
7. 서버읽기응답 pages[].can_delete(프로젝트 items[].can_delete)는 기존 Authz::canCommunityWrite + CommunityAuthz::decidePageManage의진짜판정. can_edit·역할문자열대체금지. 필요한판정필드는기존질의SELECT만확장, 기존WHERE/정렬/limit·응답가시범위/단일삭제/감사/복원/DB스키마변경0. 새라우트/인가정책/패키지/CSP완화0. 프로젝트 삭제·복원 후 페이지 재로드 없이 최신권한 목록을 갱신하도록 기존 GET /api/pages?view=projects 읽기갈래만 추가한다. community_work_projects(user,10) 그대로 재사용하며 pages/items 매핑과 more, progress null을 반환한다. view전용이며 다른 필터는 이 갈래에 적용하지 않음을 명시한다. 읽기통계/최근조회 쓰기0, 새 질의/N+1 없음.

## 소유 파일

백엔드 freeform_backend: src/community/handlers/pages_list.php, favorites.php, recent_list.php; src/community/routes.php의 community_work_projects 읽기helper만(라우팅변경금지); tests/community/document_trash_api.py(신규), tests/community/memories_harness.py(새격리suite 허용만), 필요한별도합성fixture. 포트8818·자신생성DB만. API문서계약변경내용root보고. 운영DB/실서버HTTP쓰기0.
프론트 space_frontend: public/assets/community/editor.js, community.css, public/assets/app.js(사이드바접기선호관련만), public/assets/app.css(필요한새행/선택UI만), src/views/shell_top.php, shell_bottom.php(공통view include필요시), src/views/document_trash.php(필요한공통확인/진행view 신규), src/community/handlers/home_page.php, tests/community/document_trash_browser.py 및 tests/fixtures/document_trash_render.php. 기존관련UI기대값이승인새동작과충돌할때근거보고후최소정합. member-ai/메신저/테마/DB/라우트변경금지. 포트8828·자신fixture만. createBulkRunner/공통함수최소재사용; 큰분리refactor없이명확한계약. 다른새파일이필요하면먼저파일명보고.
root: DESIGN/SECURITY/단계/위키/서버사본 API설명/작업기록, community asset version생성, backup/보존/모의/drift/운영/기록/push. 독립 document_trash_review: runtime수정0·별도컨텍스트·최종관련공격/4부/hash. 초기공통196/0은빈합성DB·8799에서이미완료, broad재반복없음.

## 수락과 운영

실제인가API(본인·팀·공유edit/view·Admin타인·board작성자/타인·Guest·CSRF·401), 목록범위/n+1,3세대부모/자식중복/cascade·원문/복원·감사, 전부/부분/검색·필터·100초과·중복row·키보드/모바일·확인취소/이중클릭/부분실패/권한회수/세션다른계정·dirty/409·목록refresh·undo·접힘저장/경계왕복. 기존 qf3b.sh는운영dump, stage4a/b/c는운영대상이라실행금지, 의미를빈합성DB suite로검증한다. lint0·assets·wiki·PHP/JS검사,두사람최종runtime SHA. 별도암호화3종checksum/전체복호화/25표원래행·959원본파일·ownerACL·설정baseline→고정후보/모의/freshdrift→FF/minor/FPM→HTTPS신규QA만생성/삭제/복원·원본보존→기록/atomicpush. 실물휴대폰/Safari/스크린리더/실물IME미검증명시.
