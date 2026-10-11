# 콘텐츠 중심 대시보드 위젯 — 2026-10-11

사용자가 전체 기능 조사/위젯 계획 후 “이렇게 한번 해볼까?”로 구현을 승인했다. 기준 main 93668ff466ba604d99deb266c26a50707c83b62f, 계획 dfa7dd7. 요구사항 Q:F-1, docs/dashboard-widget-plan.md의 W01~W07 및 기존 공동 홈 5종. 새 계정 기본 구성, 기능 안내, 소/중/대별 실제 콘텐츠, 자유 배치, 개별 위치·크기 잠금, 전체 저장까지 구현한다. 후속 후보 W10~W31은 이번 범위가 아니다.

## 확정 저장 계약

- v1/v2 읽기·저장 호환 유지. GET은 설정 행을 만들거나 변환하지 않는다. 기존 저장 배치와 로컬 명시 가져오기 유지. 명시 편집 저장만 v3로 전환한다.
- v3 root 정확히 `version,widgets,source,view,guide`. version=3, source=`all|files|pages`, view=`recent|favorites`는 레거시 호환 선호. guide=`pending|later|done`. 안내 상태는 계정 배치와 함께 revision을 사용해 저장하며 편집 중 별도 저장하지 않는다.
- widgets 최대20개(0개 허용), JSON 최대32KiB. widget 정확히 `id,type,source,visible,locked,desktop,mobile`. id는 `^[a-z][a-z0-9_-]{0,63}$` 고유 인스턴스 식별자. 새 ID는 `widget-` 접두어 + UUID. 제목·본문·URL·토큰을 저장하지 않는다.
- 개인 타입: `my_tasks,briefing,recent,favorites,space_notice,space_event,space_task`. 레거시 호환 전용 `legacy_recent,legacy_services,legacy_tools,legacy_stories`도 허용하되 신규 추가 카탈로그에 표시하지 않는다. 레거시 타입은 DOM 호환을 위해 타입별 최대1개만 허용한다. 공동 홈: `space_notice,space_task,space_event,space_file,space_story`만 허용.
- 일반 개인 source는 `{}`. PHP associative decode의 빈 `[]` 입력은 빈 object로 정규화해 DB/응답에서는 `{}`를 유지한다(빈 widgets는 리스트 `[]`). recent/favorites source는 정확히 `{kind:"all|files|pages"}`. 레거시 source는 `{}`. 공간 타입 source는 정확히 `{space_id:UUID,scope:"all|mine"}`. mine은 space_task에만 허용, 공동 홈은 all 및 현재 공간 ID만 허용. 개인 저장 시 각 출처 멤버십/보관 상태 확인; 조회는 항상 기존 권한 재확인. 회수된 출처는 내용 제거 및 출처 변경/위젯 제거 안내. 더 이상 접근할 수 없는 위젯 제거 후 저장 가능해야 한다.
- desktop/mobile 각각 정확히 `{x,y,w,h,size}`. size=`s|m|l`. 정수 좌표 기존 경계(폭1200/360, 최소240/160, 높이160..2000, bottom≤20000) 유지. 서로 겹치는 visible locked 앵커는 같은 화면에서 저장 거부. 잠금이 없는 기존 v1/v2는 이 조건 적용 대상 아님.
- JSONB/DB migration 없음. CSRF, acting 제한, revision409, 공동 editHome 권한 유지. 잠금은 접근 권한이 아닌 편집 동작 규칙이다.

## 캔버스/렌더러 계약

- `MoaDashboardCanvas`의 기존 export와 v1/v2 입력을 유지한다. v3는 instance id로 노드/이름을 매핑한다. `Canvas(host,nodesByInstance,namesByInstance,options)` 및 기존 method 유지.
- `options.onSize(widget,size,mode,node)`로 프레임 밖 콘텐츠 렌더러에 알린다. 모드/유효 표현 크기 변경 시 호출. `options.requestAdd(canvas)`는 외부 타입/출처 선택기를 연다. `canvas.addWidget(widget,node,name)`로 새 인스턴스를 추가한다. 프론트와 엔진 담당자는 초기 구현 전에 정확한 기존 namespace/method를 서로 확인한다.
- v1/v2→v3 명시 편집 변환에는 개인 legacy 타입 또는 공동 공간 타입/source 매핑을 사용하고 geometry를 보존한다. 추천 새 구성은 별도 미리보기→저장으로 전환하고 취소하면 기존 배치를 복원한다. 숨긴 항목 복원과 새 인스턴스 추가는 구분한다.
- 선택한 위젯에만 소/중/대, 위치·크기 잠금, 더보기. 더보기에서 숨기기/직접 크기 조절. 이동·resize 손잡이와 키보드 입력 지원. 저장/취소는 모바일에서도 보이는 고정 도구막대.
- 잠금은 양쪽 화면 공통이며 드래그/resize/preset/숨김/기본배치/다른 카드 충돌로 움직이지 않는다. 충돌 처리 순서는 모든 locked 앵커→조작 카드→나머지 unlocked. 배치 실패는 원자적으로 되돌린다. 잠금 해제는 눈에 보이는 동작으로 제공.
- 프리셋 추천 실제 최소 가독성을 검증한다. 픽셀값은 시험 결과에 맞춰 확정하며 소 본문14px 이상. 자유 resize는 폭·높이에 맞춰 size 표현을 전환하고 hysteresis를 둔다. 좁은 화면 fallback은 표시 전용이며 저장된 잠금 좌표를 몰래 변경하지 않는다.
- 편집 중 업무 변경/다운로드/원문 이동은 잠시 멈추고 편집 종료 시 복원. 취소는 배치·출처·잠금만 복원. 저장 실패/409는 초안 유지. 공간 전환·늦은 응답·권한 회수 시 이전 DOM/cache 제거.

## 실제 데이터와 기본 구성

- W01 기존 my-tasks 버킷을 사용. 소1/중4/대8(모바일 대6), 프로젝트/기한/상태별 묶음. API에 없는 전체 건수·즉시 완료 capability를 만들지 않고 원문으로 연결한다.
- W02 briefing 소1/중4/대8. Briefing::group에 하위 호환 reasons를 보존. W01에 실제 표시한 작업의 기한 이유만 중복 제외, 멘션·검토 이유 유지. 홈 자동 AI/읽음 처리 금지.
- W03 최근 자료 소2/중4/대8. 최근 사용 시각 병합, 파일은 정확 UUID 미리보기. W04 즐겨찾기 소2/중4/대8, 파일/폴더/문서 기존 출처 순서 보존. 개별 출처 실패 구분.
- W05 공지 소1/중3/대6, 중은 발췌, 대는 더 긴 발췌. W06 일정 소1/중4/대8, 날짜별 구분. W07 할 일 소1/중4/대8, 권한 있는 can_edit 행만 기존 revision 완료 API 사용. 모든 표현은 전체 보기 제공, 원본 제목/상태/시간을 지어내지 않는다.
- 기존 `/api/product-spaces/{id}/items`에 선택 `view=dashboard&kind=notice|task|event|file|story&assignee=me|all&limit=1..8`. 정상 기본 조회는 변경 없음. dashboard는 SQL에서 필터/정렬 후 LIMIT+1, `{items,truncated,as_of}`. task 완료 제외/나의 담당 정확 필터/마감 우선. event 종료 제외, 진행 중 우선→가까운 시작, null ends는 시작 당일 유지 후 다음날 제외(Asia/Seoul). notice/file/story는 기존 최신순 및 권한 필터를 유지한 bounded dashboard 조회를 제공한다. assignee=me는 task에만 허용한다. 파일 권한은 LIMIT 전 적용.
- 공동 기존 file/story도 같은 크기별 렌더러를 사용한다. 개인 카탈로그는 첫7종으로 한정한다.
- 새 개인 배치: 공간 없음=최근 중/즐겨찾기 소/브리핑 소. 확인된 회사 한 곳 문맥=그 공간 할 일 중/공지 소/일정 중/최근 중/즐겨찾기 소. 모임 문맥=일정 중/공지 소/최근 중/즐겨찾기 소. 모임 이야기/자료는 공동 홈에서 바로 접근하고 개인 위젯 추가는 후속. 여러 공간이면 하나를 임의로 주업무로 단정하지 않고 일반형+공간 선택 안내. 서버 기본값과 클라이언트 추천 생성 결과 일치.
- guide pending/later/done: 짧은 시작 안내(참여공간/첫자료/위젯편집), 완료/나중에/다시보기. 인증·동의 관문 후 표시. 기존 구성 이용자는 추천 미리보기 제공. 공통 서비스 탐색은 위젯을 숨겨도 접근 가능.
- 숨김 위젯은 fetch하지 않는다. 사용자/출처/필터별 메모리 캐시, 권한 오류에 stale 내용 제거. 레거시 자료 요청은 실제 레거시 표시 시에만 시작하며 새 기본 화면에서 숨긴 기존 영역 스크립트가 불필요한 요청을 하지 않게 한다.

## 구현 파일 분담

- Backend 담당: src/site/ProductSpaces.php, src/handlers/product_spaces.php, src/community/Briefing.php, src/site/launcher_page.php, tests/community/briefing_integration.py, tests/product_spaces_layout.php, tests/product_spaces_backend.php, tests/product_spaces_backend.py, tests/community/product_spaces_http.py, 새 해당 격리 테스트.
- Content/integration 담당: public/assets/dashboard-widgets.js, public/assets/dashboard-widgets.css, public/assets/home-dashboard.js, public/assets/space-page.js, public/assets/space-page.css, src/views/launcher.php, src/views/space_page.php, tests/home_dashboard.py, tests/home_dashboard_sync.py, tests/space_dashboard_ui.py, tests/space_dashboard_stale.py, tests/space_actions_ui.py 및 새 widget UI 테스트. start.js/product-home.js 수정은 사유 보고 후 범위 확장.
- Canvas 담당: public/assets/dashboard-canvas.js, public/assets/dashboard-canvas.css, tests/freeform_dashboard_ui.py 및 새 canvas 격리 UI 테스트. 위 Content 담당과 namespace/method 즉시 합의.
- Root: 본 지시서, DESIGN.md, ARCHITECTURE.md, API/보안/단계/wiki/work-item/배포기록, 독립 검토·후보 검증·운영 배포. 구현자는 commit/deploy하지 않는다.
- public/index.php, Auth/Authz/Db/Http/Storage, DB schema/migration, 배포 동작·설정·패키지 변경 없음. SQL 문자열 결합/innerHTML/외부 CDN/새 라이브러리/실제 사용자 데이터 시험 금지.

## 완료 기준

v1/v2 보존·v3 정상/타입/범위/출처 권한/CSRF/revision/GET 쓰기0. 실제 브라우저 S/M/L 정보 변화, 자유 이동/resize, 잠금 충돌/숨김/기본배치, 저장/reload/취소/409, 모바일 touch·키보드·한글·테마·넘침. 원본 조회 필터/정렬 LIMIT 이전 증명과 권한 회수/지연응답 검증. 독립 리뷰, 관련 기존 회귀, 격리 공통 회귀, 보안 lint·자산·wiki. root는 검증 후보 고정→백업/전체 복호화 확인→모의 적용→drift→운영 코드→실제 HTTPS/원본보존→기록 push. 운영 DB 복원/재시드/삭제 없음. 실물 기기와 브라우저 모의 검증을 구별한다.

## 통합 검토에 따른 범위 보완

숨긴 위젯의 불필요한 조회를 막기 위해 launcher_page.php의 기존 SSR Briefing::collect 집계를 제거한다. 개인 위젯이 표시될 때 기존 API로 조회하며, 레거시 tools의 내 작업 진입은 건수 없는 링크로 유지한다. 로그인/동의/세션/no-store 관문은 변경하지 않는다. Briefing 기존 HTTP 시험은 오래된 moa-briefing 클래스 의존을 제거하고 SSR 출처 조회 없음·원문 미노출·no-store·비로그인 비조회·업무DB 쓰기0을 검증한다. 현재 소/중/대 공통 추천 높이는220/420/640이며 실제 내용이 넘치면 글자를 줄이지 않고 완전한 행 단위로 표시를 제한한다.


공동 홈의 새 렌더러는 `/home?view=layout`을 사용한다. 이 선택형 조회는 기존 멤버십과 layout revision 검증을 거친 `space,layout,revision` 및 빈 `widgets,truncated` 객체만 반환한다. 콘텐츠5종 SQL을 수행하지 않으며 표시된 각 위젯만 `/items`로 읽는다. 쿼리 없는 기존 `/home`은 기존 응답을 유지한다. 잘못된 view는400. 따라서 레거시 배치를 읽는 새 브라우저도 숨김 위젯 때문에 불필요한 콘텐츠 조회를 하지 않는다.
