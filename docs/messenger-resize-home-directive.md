# AI 창 공간·크기 조절과 홈 바로가기 — 2026-10-12

목표: 사용자가 지적한 AI 창 안내의 과도한 점유를 줄이고 창을 상하좌우/모서리에서 직접 조절하며, 홈 업무/내 작업 구분을 실제 경로와 함께 검증한다. 기준 d61c1d8020e532896cf686ffaceebeb3de5419bc/v2.59.0. 담당은 실제 Codex root/구현/별도컨텍스트 독립검토. 기존 resize제외 계약은 이번 사용자 요청으로 대체한다. 구현·검증·운영·push 기존승인, 추가질문은 홈재현 위치 선택만이며 독립작업 계속.

## 요구와 UX

Q:F-11/12/1. AGENTS·CLAUDE·DESIGN2.17/2.18·SECURITY R-116~118·API 메신저/AI·기존 messenger-movable-directive 관련절을 읽는다. API/인가/동의/대화state·암호화초안·원문/개인홈설정 보존. 운영전송/AI생성0. DOM 고정 id·기존 hidden/모아AI 연동 유지. AI 안내는 읽을 수 있게 남기되 독립된 큰 줄을 줄인다. 최초동의 관문은 그대로다.

1. 떠 있는 AI창: 상단 별도 '이 창 사용 안내' 행은 머리의 작은 도움말 동작으로 옮긴다(기존 data-usage-guide-open을 직접 두어 자동삽입 방지 재사용). AI제목/새대화/기록선택/남은횟수는 compact 배치. 긴 모델/설명은 기본닫힌 details로 이동하되 짧은 Google전송·민감정보주의 요약은 계속 볼 수 있게 한다. 관리자 설정링크·전체설명 접근 유지. 글자 과도축소 대신행/여백절약. 초기 환영글도 작은 창에서 본문을 밀어내지 않게 창한정 CSS로 정리. 큰메신저 페이지 다른기능 의미는 그대로.
2. PC>=720: 4변+4모서리 resize hit area(커서/보이는 우하단 손잡이·접근이름), 반대변고정. 기존 제목끌기는 계속. 각 손잡이 방향키/Shift로 키보드크기조절 지원. 최소360폭·약420높이(실제대화최소공간 측정후확정), 최대visualViewport 여백내. 더작은visualViewport는 물리화면안으로 축소하며 원래선호크기는보존. 입력·선택·버튼 클릭이resize/drag로오인되지않음. pointercancel/lostcapture/blur/모달/닫기/pagehide/권한상실은확정전원복; 완료시만저장.
3. 모바일<720: 폭은화면에맞게유지, 위쪽높이손잡이로상하조절. 기본88dvh, 화면안최소/최대 및가상키보드viewport보정. desktop좌표/크기를덮어쓰지않고 desktop복귀시그대로. 기본크기·위치복원동작제공(모바일도기본높이로돌아갈수있어야함). 작은창에서도입력과닫기접근가능.
4. 저장: 기존 position v1(x,y)는읽기/이동호환을유지한다. 크기는 별도 qaredi-messenger-size:v1:<owner>의 엄격한 숫자payload(v,w,h,mobileH)로 분리하는 안을우선한다. 다른안을고르면근거/호환보고. malformed/비유한/범위/키검증, 소유자전환/명시로그아웃/denied 정리, 저장소거부시메모리동작. 초기GET만으로기존선호쓰기0. 기존대화v1/암호화draftshape변경0. 기본복원은해당위치/크기선호제거. 모바일기본값/범위명시. CSSOM숫자properties만, CSP완화0.
5. 홈: 현재코드와root실제HTTPS에서 업무=/community/·내작업=/community/my-tasks이며각각200·다른제목으로확인됐다. 다른홈메뉴/공개상태·저장구성도조사해실제겹치는동작만고친다. 의미는업무전체홈(프로젝트/요약/공지) vs 개인작업. /workspace는내문서이므로업무대체로돌리지않는다. 재현확정전라우트버그수정이라주장하지않음. 업무아이콘명은 '업무 홈'과목적설명·서로다른기존아이콘으로구분할수있다. shortcut id/order/hidden·위젯/개인설정DB를바꾸지않는다.

## 분담/수정 파일

창 담당: public/assets/messenger.js, src/views/messenger_window.php, public/assets/app.css, public/assets/moa.css, public/assets/app.js(기존로그아웃의새크기키정리만), tests/community/messenger_resize_browser.py 및 tests/fixtures/messenger_resize_render.php. 기존창회귀에서승인된새도움말위치/reset의미와직접겹치는selector기대값만증거확인후정합허용. 라우터·API·Authz·패키지변경0. member-ai.js/usage-guide.js 수정은필요시먼저근거보고.
홈 담당: src/views/launcher.php, tests/home_shortcuts_browser.py 및필요한합성fixture. 읽기조사후현상근거와최소수정보고. 기본라우터수정없음; 필요시근거부터.
root: docs/위키/SECURITY/단계·backup/보존·모의/drift/운영/기록/push. 독립검토: 초기격리196 한 번, 최종실제PHP/JS 독립시험/4부/hash. 자신소유격리포트/DB만. 운영접근은root만.

## 수락과 완료

8방향실제pointerresize·keyboard·반대변고정·제목이동·reset·close/reopen/reload/페이지이동·모바일높이/왕복·가상키보드visualViewport·확대·최소대화/입력가시영역·한글긴제목·접힌안내/열기/관리링크/usageguide·모달층/Escape/IME·저장거부/손상/계정/로그아웃·기존초안검증. 전송은합성API만, 운영비GET차단. 기존movable/store/window 관련영향범위와홈shortcut실제click검증. lint0·자산/위키·PHP/JS, runtimehash2인. 암호화3종전체복호화·원본/25표(14legacy+6product+5message)·ownerACL/85설정baseline→고정후보/모의/freshdrift→FF/minor/FPM→실제HTTPS/보존→기록/atomicpush. 사용자동시정상저장은감사/기준revision확인하고그대로유지하며예외를숨기지않는다. 운영삭제/복원/재시드0. 실물IME/휴대폰/Safari/스크린리더미검증구분.

구현결정: size:v1 payload는 {v:1,w,h,mobileH}이며 mobileH=0은 기본88dvh. PC기본복원은PC위치/크기를복원하고모바일선호유지, 모바일기본복원은mobileH만0으로하고PC위치/크기는보존한다. 기존movable시험의 모바일reset숨김 기대는 새요청에맞춰 가시/기본높이복원으로 정합허용. 홈고정경로오류는실제HTTP/클릭·다른홈메뉴조사에서도미재현, 업무 홈 명칭/목적설명/기존grid아이콘과내작업check아이콘으로구분하는최소표시개선진행. route/id/선호변경없음.

기존 시험 정합 범위: compact_home_ui.py checkbox명 업무→업무 홈 한 곳, messenger_movable_browser.py 모바일 기본복원 표시 한 곳, messenger_window_browser.py 도움말 머리 내부 selector 및44px 일반동작검사의 새 resize 손잡이 제외(테두리8px·모서리18px 전용 pointer/키보드검사 별도). 동작검사 우회가 아닌 승인된 UI 계약 변경 정합이다.

확대 후속 보정: 실제 visualViewport가 극도로 짧은 h<260일 때 창 전체를 순차스크롤해 안내·입력 접근을 보장한다. 손잡이는 dialog.scrollTop 숫자만 CSSOM translate에 적용해 창테두리에 유지하고, 크기복원/tiny해제/destroy에서 정리한다. 일반 short(260~419)에서는 내부 대화영역 스크롤과 입력접근을 유지. 별도 4배확대 hit-test/포인터·cleanup검증을 수행한다.
