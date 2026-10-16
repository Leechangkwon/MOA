# 커뮤니티 외부 답변 붙여넣기 보존

## 목표와 기준

ChatGPT 답변을 마우스로 선택해 Ctrl+C/Ctrl+V 했을 때 비교표·문단·목록·강조·링크가 커뮤니티 문서와 작업 설명에 보존되도록 한다. 사용자가 확인한 복사 경로는 HTML 선택 복사다. 현재 v2.68.2, 기준 main `352b2e2765c6936f0c3edf18f1ec17695232332f`.

관련 계약: C:F-15/F-16, C:S-11/S-12; docs/server-context/community/ARCHITECTURE.md 12.2, 14.3 및 DESIGN.md 2.14. 기존 schema의 table atom에는 외부 table parseDOM이 없어서 DOMParser가 셀 내용을 평문으로 펴 버린다. clipboardDomParser는 별도 멘션 규칙 없이 허용 스키마만 사용한다.

## 소유권과 허용 파일

- 구현 담당: `public/assets/community/editor-schema.js`; 필요한 순수 보조 파일은 `public/assets/community/clipboard-table.js`만. 연계 수정은 `public/assets/community/table-editor.js`의 `transformPasted` 한 곳으로 제한한다(표 셀에 외부 표를 붙일 때 새 atom의 텍스트가 유실되지 않게 평탄화).
- 읽기 화면 보정: root가 `community.css`의 일반 표 셀과 셀 미리보기 두 white-space 규칙을 수정하고, 6개 집중 표시 수락으로 검증한다.
- 새 수락 시험: `tests/community/clipboard_html.py`와 합성 fixture `tests/community/fixtures/clipboard_html.json` (필요한 것만 생성).
- 다른 개발자도 작업 중이다. 타인의 변경을 되돌리거나 기존 운영 저장소를 직접 편집하지 않는다.
- root 담당: 이 계획·작업 기록·설계 기록·위키/단계 기록 조정 및 구현자와 분리된 독립 검토. 서버 세션이 사용자의 별도 D2a 작업을 재개하여, 붙여넣기의 자산 버전 정리·최종 수락/정적 검사·백업/모의/운영 반영·밤 회귀 등록은 로컬 root가 SSH 격리 후보에서 직접 맡는다. 서버 세션에는 중복 반영 금지와 main 드리프트 공유를 전달했다.

## 구현 제한

- 저장 형태는 기존 블록 JSON만. DB/서버 검증기/인가/라우터/의존성/설정 변경 없음.
- 외부 table을 기존 table atom의 안전한 raw 블록으로 변환해 rich text와 유효한 병합을 보존한다. 행/열/셀/텍스트 상한과 직사각형/빈 covered cell 계약을 지킨다. 초과/깨진 표는 내용을 잃지 않는 안전한 평문 fallback 또는 명시적 거부를 택하고 근거를 기록한다.
- 표 셀에서 스크립트·이벤트·외부 이미지/iframe·임의 속성/스타일·조작된 멘션/내부 atom 참조를 받아들이지 않는다. 링크는 기존 schemeOkForEditor와 서버 계약을 유지한다.
- createElement/createTextNode/textContent와 ProseMirror 파서를 사용한다. innerHTML로 렌더링하거나 브라우저 HTML을 저장하지 않는다. 새 네트워크 요청/외부 라이브러리 금지.
- 같은 clipboardDomParser를 사용하는 일반 문서와 작업 설명 모두에 적용한다. 셀 내부 문단/줄바꿈/inline code/링크/굵기(HTML strong,b,font-weight 700 등 실제 복사 형태) 보존을 검사한다.
- `**`가 외부 HTML 안에 이미 문자로 들어오는 경우에는 임의 변환하지 않는다. 실제 HTML 경로에서 강조가 어디서 손실되는지 재현으로 구분한다. Markdown 전체 importer 확장은 별도 필요성이 확인될 때 root에 알린다.
- 기존 표 raw 보존/편집기 own DOM 멘션 유지/clipboard 멘션 차단/파일 붙여넣기 경로를 깨뜨리지 않는다.

## 수락 시험과 보고

변경 전 실패를 재현한 뒤 같은 시험으로 통과를 확인한다. 합성 HTML은 이번 답변과 같은 3열 비교표, 문단/목록/강조/인라인 코드/외부 링크를 포함한다. 실제 production PM schema → JSON → 서버 Doc::validate → renderer 왕복, 저장/reload/Undo-Redo는 가능한 실제 UI 하네스로 확인한다. text/plain paste 및 Ctrl+Shift+V의 기존 동작도 보존한다. nested/malformed/oversize tables, rowspan/colspan, XSS/forged mention을 포함한다. 운영 데이터는 쓰지 않는다.

구현자는 이 수락 묶음만 실행한다. 메인/서버는 최종 합친 후보에서 수락 및 security_lint/asset/wiki를 한 번 실행한다. 비례 회귀는 최신 CLAUDE 3.1에 따라 밤 대기열로 보낸다. 사용자의 실제 Chrome 글은 로그인 상태가 없어 접근하지 못했으며 합성 검증과 혼동하지 않는다.

보고: 변경 파일, 변경 전 재현, 실행 명령/결과, 남은 문제 및 과거 저장 글 자동 복구 여부. 자동 복구/운영 반영/사용자 글 편집을 구현자가 수행하지 않는다. 커밋은 root가 한다.
