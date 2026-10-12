# 메신저 공통 진입과 창 이동 — 2026-10-12

목표: 사용자가 승인한 PC 왼쪽 공통 아이콘바의 메신저 진입과, 상단을 끌어 원하는 위치에 놓는 떠 있는 대화창을 구현·검토·운영 반영한다. 기준 main 01114a9fd667cfd6c58124a81c8c44d6a10a618d/v2.58.2. 실제 담당 Codex root/구현/별도 컨텍스트 독립검토. 추가 승인 불필요. 기존 '아이콘줄에 넣지 않는다/오른쪽 아래 고정' 계약은 이번 사용자 승인으로 변경한다.

## 요구·참조

Q:F-11·Q:F-12, DESIGN 2.17/2.18/6장, SECURITY R-116~118, docs/server-context/docs/persistent-messenger-plan.md(없으면 /var/www/qaredi/docs/persistent-messenger-plan.md) 4.1~4.8, API의 모아 메신저, CLAUDE/AGENTS 관련 절을 읽는다. 기존 인가/역할로보기/동의/제한계정 이용안내, 암호화 초안·대화/AI·읽음·배지/폴링·같은탭복원·모달상위층·Escape/IME를 유지한다. 원문·메시지·사용자취향 쓰기0, QA검증에서도 타인에게 메시지를 보내지 않는다.

## UX 계약

1. PC>=960 셸: 자료실/업무/이야기 아래 구분선+메신저 아이콘/이름/안읽은수. 내부 사이드바의 기존 메신저 항목은 제거하여 중복진입 없앰. 모바일/태블릿<960 상단 말풍선은 유지; 모바일 메뉴 안 중복메신저는 제거한다. 원래 제한계정의 안내경로도 유지. /messages 전체화면·모아홈/회원화면의 기존 진입점과 기능은 유지한다.
2. 창>=720: 최초 위치/폭/높이는 기존 오른쪽아래. 창 머리의 제목·이동손잡이를 잡아 pointer drag, 닫기·목록·크게보기 등 버튼/링크와 입력·내용 선택은 이동을 시작하지 않는다. 손잡이에 이동가능 cursor/한국어 설명, keyboard 방향키(Shift는큰폭) 지원. reset을 위한 작은 「기본 위치」 버튼(접근이름 명시)을 머리에 두고 모바일에서는 이동/reset 숨김. 버튼과 제목은 400px 안에 잘리지 않아야 한다. 불필요한 resize/팝아웃/메시지기능 확대0.
3. 화면 가장자리 여백 안에 창 전체(최소한 머리·닫기)를 유지하고 resize/회전/줌/visualViewport·모바일전환 뒤 다시 안으로 보정. 기본 위치는 기존 도움말과 겹치지 않음, 사용자 이동 위치는 스스로 고르는것이며 도움말을 피하려고 강제복귀하지 않음. 모바일<720은 기존 88dvh 시트; PC위치를 저장하되 좁은화면에서는 적용하지 않고 PC복귀시복원한다.
4. 위치는 사용자별 같은 탭 sessionStorage에 숫자/버전만 저장. 기존 v1 대화 state와 암호화 draft의 shape/보호 유지; 별도 position key를 쓰면 소유자전환/명시로그아웃에서 정리하고 막힌저장소에서도 현재세션드래그동작 유지. malformed/비유한수/큰값/모양오류는 무시·제거하고 데이터만 사용. 메시지본문/이름/토큰 평문추가0, 서버API/DB변경0.
5. pointercancel/lostcapture/blur/닫기/pagehide/모달/권한상실 등 이동종료 처리. 저장은pointerup/키보드/상태종료 시 적절히, move마다storage쓰지 않음. CSP는 기존 그대로, inline script/event/setAttribute style 없음; 검증된 숫자의 element.style property 설정은 기존 dashboard 선례대로 검증.

## 분담/파일

창 담당: public/assets/messenger.js, src/views/messenger_window.php, public/assets/app.css(창+레일CSS 모두), tests/community/messenger_movable_browser.py 및 필요한 합성 fixture. shell 담당과 다음 스타일 계약 공유: 레일버튼 .app-rail__messenger .app-rail__item, 구분선 .app-rail__divider; button reset·focus·badge 작동.
레일 담당: src/views/shell_top.php, tests/community/messenger_rail_browser.py 및 필요한 합성 fixture. app.css 직접 수정하지 말고 창 담당에게 요구 전달. id messenger-open을 rail에 하나만 유지하고 모바일 appbar-messenger별도; 기존 data-messenger-open + unread observer재사용. 권한 core/라우터/Apps목록변경0.
root: 문서/위키/기록, 필요한 생성import판만 community_asset_version 도구로 갱신, 백업/모의/드리프트/배포/HTTPS/보존/push. 새공용함수/파일 필요시 근거보고후 목록추가. 독립검토: 합성/격리전용테스트·4부보고·hash. 운영 접근·수정은root만.

## 검증·완료

실제 productionJS/PHP mockAPI로 PC pointer drag·키보드·reset·닫기/reopen·reload/다른앱·모바일↔PC·viewport축소·좌표손상·storage거부·계정전환/로그아웃·모달/Escape·IME·클릭보존·배지/단일진입·권한·초안보존. 기존 messenger window/store 회귀와 shell 테스트 영향범위 실행; old fixture기대값이baseline부터깨지면증거남기고현재계약범위만수정. 초기공통196 격리한번(운영쓰기shell0). lint/asset/wiki/php/js, 최종hash2인확인. 운영은예약 claude_ 정상인증·tbox-review/2026-10-12, 메시지전송/기존설정쓰기0. backup3종 checksum/전체복호화·기존행/storage/config/ACL baseline→고정후보/정확파일모의/freshdrift→FF릴리스/FPM→HTTPSdrag/복원·데이터보존→기록/atomicpush. 실물기기/Safari/스크린리더미검증구분.

추가 허용: public/assets/app.js의 기존 clearMessengerState(owner)에 같은 사용자 position key 제거를 추가한다. 메신저 모듈이 없는 화면에서도 명시 로그아웃 때 숫자위치를 정리해야 한다. 창 담당만 수정하며 다른 로그인/인증/반환경로/스토리지 규칙은 변경하지 않는다. tests/fixtures/messenger_movable_render.php와 레일 대응 합성 fixture 허용. 오래된 기존시험은 현재계약불일치가확인되면 root에 근거전달후 최소한의 selector/fixture 정합만 허용.

레일 기존회귀 정합: tests/community/qf12_shell_browser.py에서 예전 sidebar opener1을 검사하던 두 기대값은 이번 승인에 맞춰 rail opener1/sidebar0으로 바꾼다. 모바일은 원래 dialog.show 비모달 시트를 유지하며 새 showModal 변경을 요구하지 않는다. 기존 모바일 메뉴 native dialog와의 충돌은 별도 확인한다.

레일 경계 보정 허용: public/assets/messenger-access.js에서 compact .app-rail__messenger/#appbar-messenger만 긴 inline 이용제한 문구를 생략하고 aria/title·기존 안내 dialog를 유지한다(959px 가로 넘침 실증). 레일 담당 소유. qf12_shell_browser.py의 sidebar 상단 순서 두 기대값도 이번 진입 이동에 맞춰 수정한다.

기존 창 회귀 정합 허용: tests/community/messenger_window_browser.py의 t16 모바일 메뉴 검사는 현재 native #mobile-nav-dialog 안의 로그아웃 항목 및 닫기/Escape로 대상을 맞춘다. 메뉴가 떠 있는 메신저보다 위에 있고 닫힌 뒤 기존 시트로 돌아오는 검증 의미는 유지한다.
