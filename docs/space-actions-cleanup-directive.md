# 공간 홈 버튼 정리 — 2026-10-11

목표: 13053.jpg의 공간 홈에서 기록보다 관리 버튼이 먼저 보이는 문제를 수정한다. 기능/권한은 보존하고 표시를 단계별로 나눈다.
기준: main7708d36b929497ca4066353f1af1ae1247000896; cloud/space-actions-cleanup.
요구사항: PRD 회사·모임 제품 공간, ARCHITECTURE 제품 멤버십 경계, API 제품 공간 계약, DESIGN2.1/회사·모임 공간/6장. 사용자 버튼 과다 지적에 따라 아래 구성을 결정하며 기존 토큰·native dialog를 재사용한다. 필요한 공간 전용 배치 클래스 허용, 새 색/라이브러리0.

- 평소 공간 선택과 `등록하기`(쓰기 가능자), `공간 메뉴`만 주요 도구로 보인다. 홈/자료실 이동은 작은 글자 링크로, 반복 소개 문구는 간결하게 한다.
- 등록하기 → 권한에 맞는 공지/할 일/일정/자료/이야기 선택 목록(native dialog, 본문 목록형 버튼). 유형 선택은 기존 등록 modal로 이어지며 제출/취소/권한/원본자료선택 그대로.
- 공간 메뉴 → 홈 구성, 멤버, 초대, 이름 등 기존 권한 있는 관리 기능 목록. 새 공간/초대 참여/새로고침은 다른 공간 그룹, 이전/보관/나가기 등 주의 기능은 별도 구분. 기능 제거0. 빈 계정에도 공간 메뉴로 만들기/참여 가능. 계정 제한/acting은 기존mayMutate대로.
- 모든 닫기/Escape/뒤로 흐름은 적절한 원래 진입점으로 초점 복귀. 메뉴→기존modal 전환 때 저장중잠금/초대비밀/소유권 OTP정리·generationGuard 보존. 권한거부/공간전환으로 열린메뉴 비움.
- 카드 전체보기는 작은 plain 버튼으로 머리 오른쪽 배치, 수정/삭제는 항목더보기로 접어 상시 단추 더 줄임. 할 일 완료와 자료받기는 바로 실행 유지. 더보기 닫힘/키보드/권한유지. 기존confirm삭제 보존.
- 모바일360/390에서 선택+핵심도구 짧은 한 묶음, 첫카드가 buttonwall보다위로. PC/6테마/밝기/고대비·글자44px컨트롤가독성 유지.

구현허용: src/views/space_page.php, public/assets/space-page.js, public/assets/space-page.css, tests/space_dashboard_ui.py, tests/space_dashboard_stale.py, 필요시 신규 tests/space_actions_ui.py. 이 외파일변경 root에 먼저 전달. 기존테스트는 메뉴진입만 적응하고 권한/지연응답/secret assertions 삭제금지.
금지: innerHTML/외부요청/권한확대/DB/API/backend/auth변경/새dependency/운영자료쓰기/commit배포. textContent/htmlspecialchars와 서버capabilities 유지.
완료기준: syntax/securitylint/asset/wiki, 공간UI·지연응답·신규메뉴키보드/선택→등록/권한별/empty/archive/acting/stateclear·360/390/1280overflow·버튼상시개수/첫카드 위치 증거. 합성DB/fixture만. root독립검토·공통회귀·백업·고정후보/모의/드리프트·운영HTTPS·원본보존·release/push 담당.
보고: 변경파일/실행명령과출력/못한것이유/SHA동결. 실물Android는모바일viewport와구분.
