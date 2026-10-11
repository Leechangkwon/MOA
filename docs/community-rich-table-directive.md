# 커뮤니티 표 밀도와 서식 보존 편집 — 2026-10-11

목표: 사용자가 지적한 과하게 큰 표를 조밀하게 읽도록 개선하고, 색·링크·열 폭이 있는 표 때문에 문서 편집 전체가 막히는 문제를 해결한다. 기준 main f3bec3509f271fb549731bf52780781e7baa68c3 / v2.57.1. C:F-15·F-16, 커뮤니티 ARCHITECTURE 12.2~12.5·14, DESIGN 2.14·6. 실제 역할은 Codex root·구현·별도 검토이며 다른 모델로 주장하지 않는다.

## 구현 계약

- 읽기 표는 글자 크기를 줄이지 않고 셀의 강제 동일 최소 너비와 상속된 넓은 줄 간격을 해소한다. 짧은 수량은 좁게, 품목명은 불필요하게 여러 줄로 꺾이지 않게 하고 긴 문장은 제한된 폭에서 줄바꿈한다. 넓은 표는 내부 가로 스크롤, 페이지 전체 넘침0. 화면용 A4/A3/Letter 최소폭은 좁은 컨테이너 안에 맞추되 기존 용지 선택/저장/인쇄 치수는 유지한다. 저장된 내용/색/열폭을 읽기 정돈을 위해 변경하지 않는다.
- 표만 기존 평문 atom 제한에서 해제한다. atom attrs에 원본 table JSON을 복제 보존하고 기존 일반 표 attrs와 호환한다. fromDoc→PM→toDoc 무수정 표는 모든 키/ID/마크/색/열폭/합침을 보존한다. 다른 토글·파일·단 나누기 등의 기존 손실 방지는 유지한다.
- 표 고치기 창은 셀 미리보기 격자와 선택 셀 하나의 기존 ProseMirror 입력 영역을 사용한다. EditorView는 하나만 유지한다. 셀 이동 전에 변경된 셀 text만 반영하며 미수정 셀은 원문을 그대로 둔다. 표 안 링크를 누르면 외부로 이동하지 않고 셀 선택으로 처리한다. HTML 문자열 저장·주입, 새 패키지/외부 요청0.
- 적용은 기존 부모 PM transaction 한 번으로 반영해 취소/undo/redo와 저장 경합·revision·권한을 유지한다. 닫기/취소는 원본 attrs를 변이하지 않는다. 한글 조합 중 Enter는 확정하지 않는다. 셀 입력은 기존 허용 marks/링크/멘션의 변환·파서 검사를 재사용한다.
- 행/열 추가·끝 삭제는 raw 행/셀 metadata와 기존 col_widths를 함께 처리한다. 새 열의 width는 배열이 이미 있을 때만 유효 기본값을 추가한다. 삭제되는 내용은 명시적인 UI 확인 후 제거한다. 합침이 남은 범위를 넘는 구조 삭제는 안내하고 막아 먼저 합침을 풀도록 한다. 합칠 대상에 텍스트 또는 색/서식이 있으면 자동 제거하지 않고 합침을 막는다. 행/열 제한과 서버 Doc 검증을 그대로 유지한다.
- 운영 원문은 정상 인가 읽기로만 확인하며 수정/재저장/권한 우회0. 실제 편집·저장 검증은 예약 QA 계정이 새로 만든 합성 문서에서 수행한다. DB migration/서버 설정/패키지/서버 인가 변경0.

## 파일과 역할

편집 구현: public/assets/community/editor-schema.js, editor.js, src/community/handlers/page_view.php의 atom-table 안내/구조만, 신규 table-editor.js(필요 시), tests/community/rich_table_editor.py 및 익명 fixture, 기존 tests/community/browser_5e.py의 표 격자/무손실 기대값, 이전 tests/community/editor_surface.py의 표 보호 기대값을 새 무손실 계약으로 갱신. CSS 구현: public/assets/community/community.css, 필요 시 design.css의 읽기 표 관련 절만, tests/community/table_density.py. 두 구현자는 CSS 클래스 계약을 서로 전달한다. 편집 구현자는 CSS를 직접 수정하지 않는다.

root: 이 지시서·DESIGN/견본·SECURITY/STAGES·ARCHITECTURE 사본·위키·workitem·배포 기록, scripts/community_asset_version.php가 생성하는 상대 import 버전 갱신. 별도 검토자는 앱 수정/운영 접근0, 합성 실제 브라우저·PHP/DB와 소스 검토 결과/최종 해시·4부 보고. 원문/운영비밀은 Git에 넣지 않는다.

## 완료 기준

실제 편집 버튼→표 고치기→한 셀 한글/숫자 변경→적용/저장/재열기, 변경하지 않은 JSON/서식/열폭·링크·멘션·합침 보존. 원문 모양 익명 fixture도 문서 편집이 열릴 것. 무수정·취소·undo/redo·부분수정·IME·행열·합침·200×20 경계, strict CSP와 잘못된 링크/붙여넣기/403/409 경합, hidden/can_edit 권한 보존. 실제 PHP Doc 검증/JS·PHP 렌더 동등성. 모바일 360/390/412/820/1280과 주요 밝음/어둠/고대비, 실제 셀 높이/전체표폭 전후 측정. 기존 표/본문/다른 atom 회귀와 격리 공통 회귀, lint·자산·위키검사. 최종 코드 hash 고정, 암호화 백업3종 전체복호화, 모의 적용·drift·patch/minor 릴리스·실제 HTTPS/원본 보존·기록/push. 실물/Safari/스크린리더는 별도 미검증 기록.

## 운영 후 발견 경계 보정

v2.58.0 신규 예약QA에서 셀100→1005 실제 저장과 원문서식 보존은 통과했다. 390px에서 page-editor/PM/표 모달은 맞지만 inline-toolbar의 link/mention이 #main scrollWidth447px를 만들어 내부 가로 넘침이 재현됐다. 기존 .editor-toolbar__row의 nowrap을 최소 CSS로 보정하고 실제 toolbar markup을 포함해 좁은 화면 회귀를 추가한다. 본래 좁은 화면 편집 폭 완료 기준을 끝내는 변경이며 다른 화면 재설계/서버 수정은 하지 않는다. 기존 암호화 baseline/원본보존은 계속 유지하며 QA샘플은 최초 자기문서만 정리한다. 보정 후보도 고정/정확모의/drift 후 patch 반영과 실제 검증을 완료한다.

보정 검사는 tests/community/editor_toolbar_fit.py에서 실제 PHP page_view·production editor JS·합성 API로 수행하며, toolbar와 각 팝오버·Tab·표 모달까지 main 내부 가로 폭을 검사한다.
