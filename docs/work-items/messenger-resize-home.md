# AI 창 공간·크기 조절과 홈 바로가기

- 상태: 운영 v2.60.0 검증 및 Git2 동기화 완료
- 브랜치: cloud/messenger-resize-home
- 기준: d61c1d8020e532896cf686ffaceebeb3de5419bc / v2.59.0
- 담당: Codex root·창구현·홈조사/구현·독립검토

사용자 요청: AI 비서 안내가 대화를 가리는 문제, 상하좌우 창크기 조절, 홈 업무/내작업 중복연결. 계약 docs/messenger-resize-home-directive.md. 기존 동의·인가·대화/초안·개인홈배치 보존, 운영쓰기0. root의 최초 실제HTTPS에서는 업무 /community/(인사·업무요약), 내작업 /community/my-tasks(내작업)로 각각200·다른화면, source에서도분리확인. 사용자에게위치를물었으며다른작업계속. 원인미확정사항을고쳤다고하지않는다.

백업/전체복호화·원본행/파일/config/ownerACL baseline, 독립검토/고정후보모의/freshdrift·실배포·HTTPS/보존·원격동기화를순서대로기록한다. DB/설정/패키지변경없음목표. 실패시코드근거보정, 운영데이터삭제/복원/재시드없음. 실물기기/Safari/스크린리더미검증.

## 구현 및 원본 보존 준비

창 도움말을 머리의 ? 동작으로 옮기고, AI 모델/긴 설명을 기본 닫힌 안내로 묶었다. 개인정보·기밀 주의 요약은 유지한다. PC 네 변·네 모서리 포인터/방향키 크기조절, 모바일 위쪽 높이조절과 기기별 기본복원을 제공한다. 위치/크기는 같은 탭의 사용자별 숫자 선호이며 대화 v1·암호화 초안/API는 변경하지 않았다. 홈은 기존경로가 서로 달라서 명칭·기존아이콘·목적설명만 구분하며 ID·순서·계정저장 구성을 유지한다.

Runtime 6파일: public/assets/app.css, app.js(크기키 로그아웃정리1줄), messenger.js, moa.css, src/views/launcher.php, messenger_window.php. 문서·위키·격리검사만 추가. DB migration·새패키지·서버설정 변경0. 실제운영에는 메시지·AI·개인설정 쓰기를 하지 않는다.

암호화 백업 /var/backups/tbox-v2/product-spaces-messenger-resize-20261012: DB·코드·설정/저장소3종 checksum 및 전체 복호화 완료. 1042파일행/960저장소파일(운영worker cursor1 제외한959원본SHA), 14legacy+6product+5messenger 원래행, ownerACL,85설정 baseline. 이전작업 사용자동시저장예외를 이번baseline에 이월하지 않았다. 이번작업은 모든 원래행 비교한다. selftest-base/messenger-resize-home·review-base/messenger-resize-home 태그는 검사 전 기준 d61c1d8에 생성, baseline bundle 보관.

## 검사 기록

홈 actual PHP handler+production JS 실제링크/기존선호71/0, service_home70/0, compact_home212/0. work=/community/, tasks=/community/my-tasks 실제접속·제목구분. compact_home checkbox명 업무→업무 홈 한 곳만 새명칭에 정합. 새ID/라우트/개인설정 쓰기0.

정적 PHP/JS syntax·diff 공백검사 통과, security_lint 금지패턴0, community_asset_version40파일/71import, wiki69편/문제0. 초기 공통 격리196/0은 synthetic DB에서 수행, 운영쓰기shell wrapper는 실행하지 않았다. 최종resize/window/독립검토 결과를 아래 추가한다.

초안에서 최소360×420 머리줄바꿈과 짧은viewport 입력밀림을 발견해 헤더여백·짧은창 스크롤로 보정했다. 독립검토가 후속4배확대의 resize손잡이/안내 hit-test겹침을 감지했고 수정후 실제클릭 검증을 요구했다. 실제 모바일/IME/Safari/스크린리더는 미검증이며 Chromium의 viewport/touch/확대 모의시험과 구분한다.

창 구현검사: 신규190/0(actual PHP+AI 활성,8방향/반대변/키보드·취소·storage검증·48테마/폭조합), 기존movable226/0, 선택window(t12/t14/t16/t17/c3)215/0, store75/0, app.js 단독로그아웃6/0. 도움말selector·모바일기본복원·전용resize손잡이의 일반버튼크기검사 제외는 새UX 계약에맞춰정합했으며 나머지기존조건은유지했다.

4배확대 보정: h<260에서 순차스크롤하고 scrollTop 숫자 translate로resize손잡이를창테두리에 유지. preview/final 모두같은viewport경계에맞춤. 기본복귀/tiny해제에서 scroll/translate초기화, destroy시listener제거. 자체영향delta52/0: 4테마 실제안내·보내기hit, keyboard clamp, pointercancel, restore, 모바일250px입력접근, destroy. 초기검사의 최소높이193px clamp를+10px로기대한하네스오판은정합했고 runtimepreview경계보정도별도로기록한다. 독립최종결과를아래추가한다.

추가독립조합에서 PC크기저장→모바일왕복→4배확대 후 관리자설정 링크 actualclick이 막혔다. 공통 coarse button::before의44px터치확장이8pxresize손잡이에도적용되는것을 pointer-intercept 로그로확인했다. 전용resize손잡이만 공통가상누름영역에서 제외하는 CSS 보정으로 해결하며, 일반동작 버튼과 모바일위쪽그립은유지한다. 이문제는하네스타이밍수정과구분한실제런타임보정이다.

독립최종: 핵심90/0·최소/짧은창10/0·최종native/확대/클릭50/0·draw경로27/0·짧은모바일4/0·홈71/0·초기공통196/0. 최종차단0. root 보안lint0·assets40/71·wiki69/0 재확인. 구현최종영향8방향/키보드/취소48/0. 전체네이티브4배확대에서공통버튼pseudo확장이resize와겹치던문제를전용손잡이만제외해해소했다. SECURITY 7장4부검토와 outputs/messenger-resize-review/ 보고서 참조.

최종coarse저장크기/모바일왕복/4배확대 설정link와모바일top손잡이 actualclick·drag 추가22/0. 일반44px버튼가상영역과mobile n::after 그립은유지했고resize손잡이 ::before만제외했다.

## 운영 최종 결과

운영 최종: v2.60.0 `6fbd01424104` / 2026-10-12T11:15:07+09:00. AI 창 도움말을 머리로 옮기고 긴 모델·전송설명은 접어 대화영역을 확보했다. 같은400×640 창 본문 217.5→351.0px. PC 8방향 드래그·방향키 크기조절, 모바일 위쪽 높이조절, 같은탭 사용자별 PC/모바일 크기기억과 기본복원. 홈 업무 홈/내 작업 이름·기존아이콘·설명 구분; 기존경로는 각각 /community/·/community/my-tasks로 실제클릭200·중복redirect미재현. 고정후보23파일/runtime6 모의·freshdrift·FF/minor/FPM reload, 실제HTTPS80/0·4자산정확바이트·JS/5xx/브라우저쓰기0. 기존14표+제품6표+메신저5표 모든원래행(제외0), 1042파일행·959원본SHA·ownerACL·85설정 보존. 암호화3종checksum/전체복호화 확인. DBmigration/설정/패키지변경0. 실물휴대폰/Safari/스크린리더미검증. 상세 docs/work-items/messenger-resize-home.md.

후보 `acab7f5f3a5b78fe60425c55154fb27e889af6b8`, 릴리스 `6fbd01424104895299fe686b41eb18b18d3629b3`. outputs/resize-live.log·messenger-resize-live/results.json, resize-preservation.log·resize-product-preservation.log, messenger-resize-home-rehearsal.json·resize-drift.json 참조. 예약QA claude_admin01 정상인증, 브라우저 비GET/HEAD/OPTIONS 차단. 기존문서/개인설정/메시지/AI생성쓰기0.

배포전드리프트에서 부모문서의 다른세션 community/STAGES.md·docs/scale-review-2026-10-12.md 규모점검작업을 발견했다. 이2파일은수정/스테이지하지않고해시로보존확인했으며, 부모의이번작업 PRD.md/STAGES.md/persistent-messenger-plan.md만선택병합했다. 부모전체clean을주장하지않는다. drift helper의 porcelain문자열앞공백 strip오판은 원출력행으로고쳤고운영 main/원격/후보파일은정확히일치했다. 원본검사첫시도는보호된백업/스토리지 읽기권한으로실행전중단되어, 기존sudo경로로수행하고25표/959원본/85설정보존을확인했다. 파일권한변경0.

원격 동기화: main/cloud/messenger-resize-home·v2.60.0·selftest-base/messenger-resize-home·review-base/messenger-resize-home atomic push 후 fresh fetch에서 `38e62540c1f22f26cf23ce2c4c3218847491460b` 일치. 부모선택문서 커밋9463c58, 별도세션규모점검2파일은그대로유지. 이 후속 커밋은 동기화 기록뿐이며 검증runtime6fbd014와6파일SHA는불변.
