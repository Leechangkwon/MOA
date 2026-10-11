# 대시보드 편집 사이드바 — 2026-10-11

사용자는 하단의 큰 상세설정창이 보기 좋지 않으며 우측 사이드바 또는 더 나은 방식이 필요하다고 지적했다. 기존 승인된 자유배치·크기별 콘텐츠·잠금/전체저장은 유지하며 편집 화면의 구성과 조작을 개선한다. 기준0331485, Q:F-1 및 DESIGN의 공통 편집 구성요소.

## 확정 방향

- 편집 전용 상단 도구막대: 편집 제목, 위젯 추가, 취소, 배치 저장·완료. 저장/취소는 패널 본문 스크롤과 분리하여 항상 접근 가능. 큰 하단 고정창을 대체한다.
- 넓은 PC: 오른쪽 약300~320px 위젯 설정 패널. 선택한 위젯 이름/출처, 소·중·대와 짧은 정보 설명, 위치·크기 잠금, 출처 변경, 숨기기/제거/직접조절. 드물게 쓰는 기본배치·숨긴위젯복원·시작안내/레거시 취향은 배치 옵션에 묶는다. 기존 기능을 삭제하지 않는다.
- PC 패널은 캔버스와 겹치지 않도록 편집 작업 영역에 자리를 확보한다. 패널 표시만으로 저장된 geometry/size를 변경하지 않는다. 반응형 전환/닫기에도 원래 폭·스크롤·다른 화면 배치를 보존한다.
- 좁은 화면: 작고 고정된 편집 도구와 명시적인 '위젯 설정' 열기. 상세 패널은 필요할 때만 열고 닫은 뒤 선택 위젯으로 돌아간다. 저장과 취소는 패널을 열지 않아도 접근 가능. 일반 카드 선택과 drag는 자동으로 상세 창을 열지 않는다. 상세창이 열리면 적절한 modal/focus/Escape/배경 처리를 한다.
- 위젯의 편집 머리는 이동·설정 진입만 간결하게. 선택이 명확하고 잠금 위젯도 설정을 선택해 해제할 수 있다. 카드마다 출처/숨김 등 중복 버튼을 나열하지 않고 설정 패널로 이동한다. 평상시의 전체보기·업무 동작은 유지한다.
- 패널은 선명한 제목, 영역 간 여백/구분선, 기존 surface/fg/line/primary 토큰만 재사용한다. 소중대는 짧은 세그먼트, 선택 결과가 명확해야 한다. 기존 녹색 테마도 대비/글자 잘림을 확인한다. 새외부폰트·라이브러리 없음.

## 계약과 분담

Canvas options에 selectedActions(area,widget,canvas) 또는 합의한 동등 훅으로 출처 등 외부 도메인 설정을 삽입한다. 기존 valid/clean/upgrade/layout v1-v3, mode760px 및 API/DB/인가/revision 계약은 바꾸지 않는다. Sidebar DOM 정리·저장/취소/403·공간전환·지연응답·다중 캔버스 생명주기에 이벤트/영역/클래스 누수가 없어야 한다. CSSOM 검증 숫자만, textContent만, strict CSP 유지.

- Canvas 구현: public/assets/dashboard-canvas.js, public/assets/dashboard-canvas.css, tests/dashboard_canvas_v3.py, tests/freeform_dashboard_ui.py, 신규 tests/dashboard_inspector_ui.py. 엔진을 다른 사용자 데이터 기능으로 확장하지 않는다.
- 통합 구현: public/assets/home-dashboard.js, public/assets/space-page.js, public/assets/dashboard-widgets.js, public/assets/dashboard-widgets.css 및 관련 home/sync/space/stale/actions/widgets UI시험. 새 selectedActions 훅은 Canvas 담당과 먼저 합의. 기존 출처선택 dialog/변경 semantics 재사용. PHP/백엔드 수정 없음.
- Root: 문서·위키·별도 검토·검사/백업·고정후보 모의 적용·drift·운영검증·commit/push. 구현자는 운영 호출/commit/deploy 금지.
- 금지: innerHTML, 새외부요청, SQL/DB/라우트/auth 파일 변경, 기존 기능 삭제, 이름뿐인 성공 검사, viewport 변경을 저장된 layout 변경으로 처리.

## 완료 기준

실제 PHP 렌더링과 strict-CSP Chromium에서1280/1440/1920×900 PC,390×844/360×640 모바일, 태블릿 경계를 확인. 사이드바/캔버스 비겹침·상단 저장/취소 가시성·한국어 대비/넘침·위젯선택/잠금해제/크기/출처/숨김복원/추가·키보드·touch·모바일설정 닫기 초점 복귀. 실제 drag/resize 결과·같은 크기의 보기 영역 변화와 데이터 변화 구분. 저장/reload/취소/409·403 purge·공간 전환·늦은 응답·기존 v1/v2를 회귀한다. UI에 맞춘 selector 갱신은 의미있는 기존 assertion을 유지한다. 별도 검토 후 관련 회귀 한 번·보안lint·자산버전·wiki; 운영은 root만 기존 claude_ QA/검토UA로 시행. DB/설정/패키지 변경0, 운영 데이터 복원·재시드0. 실물휴대폰/Safari는 브라우저 모의와 구별.
