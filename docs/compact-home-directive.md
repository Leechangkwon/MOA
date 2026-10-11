# 홈 첫 화면과 바로가기 정리 — 2026-10-11

목표: 큰 상단 안내/중복 편집 진입을 줄이고 첫 화면에서 내 위젯을 사용한다. 아이콘 바로가기의 표시·순서를 하나의 홈 편집에서 바꿔 계정에 저장한다. 기준 main7c48db1/v2.56.2. Q:F-1·기존 홈/위젯 계약과 사용자 이카운트·구글 예시의 간결한 정보 배치를 따른다. 앱 기능은 재배치하며 권한/기존 데이터와 레거시 배치를 보존한다.

## 화면 계약

- 로그인하고 동의가 완료된 개인 홈에만 compact 레이아웃. 상단 브랜드/계정/전체 서비스/테마의 작은 한 줄, 다음 줄 내 홈 제목과 단일 `홈 편집`(#home-edit)·작은 새로고침. 별도 인사말, 상시 설명2줄, 큰 우리공간CTA, 텍스트 서비스줄의 중복을 통합한다.
- 아이콘+짧은 한글 라벨 바로가기: 데스크톱 한 줄, 모바일 한 줄 가로스크롤(명확한 스크롤 단서, 키보드 접근)로 첫 위젯 공간을 확보한다. 허용8개/기본6개, 원형 또는 둥근사각형40px 자체 SVG와 기존 서비스색 토큰 사용. 이모지·외부아이콘·외부URL0. 각터치영역 최소44px, 제목 잘림/겹침0. 긴 계정명은 줄임·접근가능 이름 보존.
- 상단 전체 서비스 메뉴는 바로가기를 모두 숨겨도 모든 기존 경로에 접근 가능. 메뉴/테마는 이름으로 구별하고 '화면 꾸미기'처럼 대시보드 편집과 혼동되는 명칭을 피한다. 기존 테마 dialog hook 재사용. 모든 launcher/service 링크의 접두 유지. 모바일에 동일 링크를 두 줄로 반복하지 않는다.
- 기본 시작 안내는 한 줄 summary의 닫힌 details로 접근 가능. 편집 중 숨김. 공간/업로드 안내·나중에/완료는 보존하되 중복 홈 구성하기 버튼은 없애고 홈 편집 위치를 문장으로 안내한다. 추천 구성은 편집 내부 배치 옵션으로 옮겨 편집진입을 하나로 한다. 기존 추천·취소·browser구성 가져오기 의미는 보존한다.
- 초기 정상 계정동기화 안내를 상시 본문행으로 점유하지 않는다. 오류/저장 상태는 접근 가능한 role=status로 표시하며 오류를 CSS로 숨기지 않는다. 기존 안내/서비스/레거시 위젯·lazy loading 유지.
- 홈 편집 오른쪽 패널(모바일 명시 설정창)에 `상단 바로가기` details를 위젯 설정 앞에 배치. 표시 선택·순서 앞/뒤 이동·기본 바로가기 복원. 바로가기 초안 변화가 상단에 즉시 보이고 전체 저장/취소를 공유한다. draft 조작 중 새HTTP쓰기0, 저장 중 잠금,409보존,401/403정리. 공통 Canvas options.homeSettings(area,layout,canvas) 선택훅 사용; 공동 홈에는 미노출. 기존 배치 옵션/위젯 설정과 기능중복 금지.
- PC1280/1440/1920, tablet820, mobile360/390, Aurora/Moa 밝음/어두움·고대비 확인. 기본 정상상태(시작안내pending 포함) 첫 카드 top은 PC300px 이하, 모바일340px 이하 목표. 실제 사용자 저장 y좌표의 임의 빈공간은 강제변경하지 않는다. 상단 편집 도구/크기/이동/잠금/출처는 이전 계약 그대로.

## 저장 계약

- 기존 개인 v3 root의 선택 필드 `shortcuts: string[]`만 추가. IDs 정확히 `spaces,work,tasks,library,story,messages,assistant,account` (중복불가/최대8). 기본 순서 `spaces,work,library,tasks,messages,assistant`. 필드 없음=기본, 명시 []=모두 숨김, 배열순서=표시순서. URL/이름/아이콘/HTML/기타 필드 저장0.
- 고정 경로: spaces=/tbox/spaces, work=/community/, tasks=/community/my-tasks, library=/tbox/, story=/community/memories, messages=/tbox/messages, assistant=/tbox/welcome?section=pc-assistant, account=/tbox/settings. 실제생성은 Http::url/communityUrl 또는 base 경로 사용. 기존 AI/PC 링크는 같은 목적지로 묶고 전체서비스에서 접근 가능.
- 서버 layoutV3 exact 허용키에 개인용 optional만 추가. 공동v3·v1/v2는 shortcuts 존재 자체를400거부. 서버 GET/기본layout에 강제로 키를 추가하지 않고 쓰기0. 개인v3 PATCH에서 키누락 시 현재저장값에shortcuts가있으면 revision검증/행잠금안에서보존한다(구버전클라이언트설정손실방지). 기본복원은명시기본배열을전송. 현재shortcuts가있는v3를v1/v2로내리는쓰기만409 layout_version_conflict로막고그외legacy계약유지. 기존 엄격한 나머지 키/타입·크기·32KiB·멤버십/CSRF/acting/revision unchanged.
- 클라이언트 valid는 optional배열형식과allowlist를검증, clean은 존재할 때만 보존. 기존 v1/v2 clean/upgrade/개인·공동문맥 보존. 새 기본 field를 무조작으로 넣지 않는다. preset/drag/lock/reset·가져오기·guide·추천preview/save/cancel에 shortcuts 손실0. 추천preview는현재초안 shortcuts를 보존하며 전체취소는편집전서버값복원. 공유공간에는개인pref혼입금지.
- DB/SQL/route/auth/config/package변경없음. 개인JSONB와 기존PATCH를 재사용한다. 새외부요청0.

## 분담·수정 허용 파일

- 엔진/서버 담당: src/site/ProductSpaces.php, src/handlers/product_spaces.php(개인PATCH의 JSON배열/객체shape구별만), public/assets/dashboard-canvas.js, tests/dashboard_canvas_v3.py, 신규 tests/compact_home_contract.php 및 격리 서비스/HTTP검사(관련 product-space tests). homeSettings DOM훅 생성/정리/busy 지원을 frontend와 합의. CSS 필요시 frontend에게 위임한다. DB/Authz/라우트/설정수정 금지.
- 화면 담당: src/views/launcher.php, public/assets/home-dashboard.js, public/assets/dashboard-widgets.css, 필요시 public/assets/moa.css, 신규 tests/compact_home_ui.py·tests/compact_home_legacy_auth.py, 관련 service_home/home/sync/widgets/inspector UItests. Apps::ICON_PATHS/moa_icon 재사용. 필요하면 고정 allowlist shortcode들을 서버template로 만들고 JS로 clone/reorder해경로/아이콘중복을피한다. Canvas/서버담당파일동시수정금지.
- 독립 검토 담당: 코드읽기·별도 합성fixture·보안검토/공통회귀, 앱수정0/운영호출0. root가 최종정확바이트/배포확인.
- Root: DESIGN/API/ARCH/PRD범위문서/위키/STAGES·지시서·검토·백업/복호화/고정후보모의/drift·실제HTTPS·원본보존·commit/push. 실제역할은Codex에이전트이며 로컬문서의사용불가능한Fable/Opus로가장하지않는다.

## 완료 검사

실제 PHP 렌더+strict-CSP 브라우저에서 위 firstcard위치·단일편집·전체서비스·라벨/아이콘·정렬/숨김/전체숨김/기본복원·preview/cancel/저장/reload/다른기기·409/401/403·최초/기존v1/v2/v3·guest/acting/미동의/익명·테마 및키보드/모바일확인. 캔버스·서비스/HTTP의정확타입경계·URL/중복/객체/공동/legacy필드거부·no-op키보존·GET무쓰기·cross-user/CSRF/acting거부실행. 기존테스트는단순삭제/약화하지말고 구조변화에맞게의미유지. 단일기능묶음검토/회귀/반영. 운영은root claude_ QA·검토UA만, DB원본삭제/복원/재시드금지. 실물/Safari미검증구분.

추가 안전계약: Http associative decode가 shortcuts:{}를[]로바꾸므로 개인dashboardPATCH에서 해당필드존재시만 원본JSONshape를확인해object/array를구별한다. 공통Http수정0. frontend는서버layout검증실패시편집/저장을막고계정구성을localfallback으로덮지않는다. persist는응답layout검증후서버정규화값을saved에반영한다.
