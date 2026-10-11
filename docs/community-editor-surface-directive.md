# 커뮤니티 본문 배경색 수정 — 2026-10-11

목표: 사용자가 지목한 「레이저팁 오차22개…(배경색판)」 읽기 본문의 검정 사각형과 잘못 표시되는 배경색을 수정한다. 사용자는 후속 답변으로 **글을 열었을 때 본문** 문제라고 확정했고 검정 영역/회색 강조상자 캡처도 제공했다. 기준45a7b909/v2.57.0. C:F-15·F-16, 커뮤니티 ARCHITECTURE12.4/12.5, DESIGN2.14·6. 담당은 실제 Codex root/구현/별도검토 에이전트이며 불가한 Fable/Opus로 주장하지 않는다.

## 확인한 원인·범위

실제 원문은17블록/표4개와 기본 배경색59개다. QA의 정상 인가 API 읽기를 사용했고 can_edit=false를 존중해 권한우회/원문쓰기0. Moa dark panel rgb(32,44,59) 안 .doc가 rgb(16,23,34), Aurora dark .doc는 rgb(9,9,11). design.css 모든 .doc의 var(--bg) 강제 규칙이 별도검정영역을 만든다. .doc-callout.nt-blue-bg는 기본회색 rgb(47,47,47)에 가려져 저장한파랑을표시하지못한다.

## 수정 계약

- 문서 내부 .doc는 바깥panel의 테마surface를 이어 쓰며 별도 검정 사각형을 만들지 않는다. read/edit/표·인용·중첩문서·미리보기에서 동일 원칙. 테마를 강제로light로바꾸거나 모든 글자/지정배경을흰색으로덮지않는다.
- 기본9색과 관리자팔레트의 명시배경이 기본callout·표header보다우선한다. 표 cell배경 > row배경 > 기본header. 글자색만 지정한셀/행을 배경선택으로 취급하지않는다. 색없는callout/header구분유지. 원본JSON·ID·순서·마크·행열·서식변경0.
- 삭제된 관리자 팔레트는 색 정의가 없어 배경이 해제된다. 이때 header_col 혼합 행의 머리칸은 부모 바탕을 따를 수 있으며, 굵기와 테두리로 구분한다. 이 경계를 해결하려고 일반 데이터 칸의 배경까지 변경하지 않는다. 살아 있는 팔레트와 기본 9색의 우선순위는 그대로 보장한다.
- 최종runtime는 public/assets/design.css, public/assets/community/community.css 두CSS만. .doc전역배경override제거와 기본callout/header스타일우선순위 수정. 기존용지/에디터JS·스키마·렌더러JS/PHP·인쇄경로·API·DB·인가·설정·패키지변경0. CSS기존토큰/클래스만, 외부요청/inline style/innerHTML0.
- 최초조사에서 발견한 A4강제폭 문제는 사용자확인후 이번수정에서제외한다. 해당초안hunk만원복. 색/marks있는표는기존fromDoc 보호예외로편집이막히는별도제약이며 이번에해제하지않는다. 지원되는문서의기존편집/배경동작회귀는검사한다.

## 분담·완료기준

구현에이전트는위CSS2와 tests/community/editor_surface.py(신규합성회귀) 및 tests/community/fixtures/editor_surface.json(익명 합성표본)만수정. root는DESIGN·design-guide·지시서·workitem·SECURITY/STAGES·위키/배포기록. 별도검토는앱수정/운영접근0, 합성시험과실행증거·최종SHA·4부보고. root만운영백업·드리프트·모의·배포·HTTPS·원본비교·기록/push.

실제PHP/CSS/JS+strictCSP 합성브라우저: 테마6개 밝음/어두움, 주요테마고대비, 360/390/412/820/1280. 원문은 text/UUID익명화표본의동일구조로검사. 블록/row/cell/inline9bg·팔레트우선순위·글자색/배경구분, document surface일관성·표scroll·pageoverflow0·JS/CSP오류0. 읽기DOM/JSON불변과원문shape fromDoc 보호예외유지. 별도지원문서의PM배경/기존서식보존, 출력문서CSS회귀. 보안lint·자산버전·위키·격리공통회귀1회. 운영은실제지목문서정상읽기로확인하고원래행·원본파일·ownerACL·설정hash비교. 운영본문쓰기0. 실물/Safari/스크린리더미검증명시.
