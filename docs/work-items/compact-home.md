# 홈 상단과 아이콘 바로가기

- 상태: 구현·최종 독립 검토 완료, 고정 후보 운영 검증 전
- 기준: main `7c48db11db777fdea8a7ad8521a6443bdeb73930` / 운영 v2.56.2
- 작업 브랜치: `cloud/compact-home`
- 계약·허용 범위: `docs/compact-home-directive.md`

## 사용자 요청과 구현

큰 인사말/설명/공간CTA·텍스트 링크/펼쳐진 시작 안내 때문에 위젯이 아래로 밀렸고 편집 진입점도 중복됐다. 로그인 홈을 작은 메뉴 바→아이콘 바로가기→접힌 안내→내 위젯으로 정리했다. `홈 편집` 한 곳에서 위젯과 상단 바로가기의 표시·순서를 구성하고 전체 저장/취소를 공유한다. 오른쪽 설정의 상단 바로가기에서8기능 중 선택하고 기본6개를 복원할 수 있다. 모두 숨겨도 전체 서비스에서 기능에 접근한다. 테마는 별도 명칭으로 구별한다. 기존 추천 구성은 편집 내부의 배치 옵션으로 옮겼다.

실제 PHP 렌더·브라우저에서 기본 첫 카드 위치는 PC1280/1440/1920 y270px, 모바일360/390 y260px. 안내·계정명·아이콘 대비/키보드/가로 스크롤을 확인했다. 기존 사용자 저장 좌표와 잠금을 강제 변경하지 않으며 익명/미동의 화면 및 레거시4위젯 DOM·기능은 유지한다.

## 저장과 안전 경계

개인 v3 JSONB의 선택 shortcuts에8개 고정ID·순서만 저장한다. 없음은기본/[]는모두숨김이며 URL·아이콘·HTML을 저장하지 않는다. 개인 PATCH에서 키 누락은 같은 revision/행잠금 안에서 기존 저장된 선택을 보존하고 기본복원은 기본배열을 명시한다. 이 선택이 있는 v3를v1/v2로 내리는 요청은409; 기존v1/v2/API/CSRF/acting/계정격리/출처권한은유지한다. HTTP에서{}와[]를 별도로 구별한다. 서버의 정규화 응답을 saved와Canvas 양쪽에 반영하고 잘못된 서버layout은 편집을 차단한다. 로그인 만료 시 새 위젯과레거시목록·늦은응답을 정리한다.

변경 런타임6: src/views/launcher.php, src/site/ProductSpaces.php, src/handlers/product_spaces.php, public/assets/home-dashboard.js, dashboard-canvas.js, dashboard-widgets.css. SQL/DB migration/라우트/인증공통/설정/패키지 변경0. 문서·위키·관련합성검사만 추가/갱신.

## 검사

- 신규compact212/0: 24 viewport×테마 조합 및고대비6해상도, 첫위젯높이·단일편집·전체메뉴·표시/순서/숨김/기본·저장/취소·추천·다른브라우저·오류/반응형·접근성.
- 기존UI361/0: home57·sync15·widgets166·inspector53·service70. 구조변화에맞게fixture/selector를갱신하고기존의미있는assertion유지.
- 격리 서비스543/0, 실제HTTP342/0, Canvas84/0(최종canvas-final-results.json; 이전stdout로그는83항목). 직접/HTTP 모두기존검사+새allowlist·누락보존·엄격배열·다운그레이드·revision·본인격리·인가회귀.
- 공통 회귀196/0: 초기스냅샷의실제PHP/격리DB/HTTP/브라우저동등기능. 운영용옛shell8개실행이라고부르지않는다. 첫20/1은합성로그인OTP가30초경계(window0)에걸린하네스시점실패로기록보존,원본그대로재실행196/0. HTTP신규suite의동의변경뒤계정사용/전역변수오염은검사순서와globals분리로보정후342/0.
- root PHP3·JS2 구문, diff check·보안lint금지0·자산버전37/0·위키69편구조/누락0.
- 독립 PHP validator46/0·최종 브라우저55/0·legacy4014/0/지연8/0·source권한13/0. 구현 legacy401/늦은응답7/0·sync15/0 추가통과. canonical Canvas 반영·PATCH401 purge·체크박스Space 후초점·레거시비공개목록잔존을발견해모두보정했다. 마지막PHP 메뉴/버튼52px 변경은 독립10/0·21/0 및 화면담당8/0·6/0으로 좁혀 검사했다. 화면담당 총594/0, 최종6런타임SHA는 root·구현·독립 검토가 일치하며 미해결 결함0. 마지막 설정360px캡처도 직접 확인했다.
- 증거: 작업공간 outputs/compact-home-checks, compact-home-review, compact-home-contract, compact-home-cross-retry, compact-final-source-hashes.json. 개인정보/비밀/운영원본덤프는저장소에추가하지않았다.

## 운영 절차와 원본 보존

암호화백업 `/var/backups/tbox-v2/product-spaces-compact-20261011`의3종checksum·전체복호화확인. tar의sourcechanged경고는저장소운영cursor를구분하고원본975파일SHA를비교해모두일치확인. 설정85·기존14표·파일1042행/ownerACL baseline. 제품6표는기존QA취향포함원래행을전부비교하는추가baseline(`product-all-before.json`),제외행0.

검토후commit고정→변경파일exact모의적용→운영/원격drift→코드fast-forward/minor릴리스→실제HTTPS→원본보존→기록/push. 운영검사는원래저장행없는예약검토계정claude_manager01에서새합성개인설정/공간만작성한다. 종료시계정의원래기본구성은API로복구(기본바로가기명시배열),새공간은보관한다. 기존admin01및사용자취향행은수정하지않는다. 원래행삭제/복원/재시드0. 실패시데이터를복원하지않고증거보존후코드보정검증.

## 미검증

실물Android·Safari/WebKit·스크린리더·사용자자기공격. Chromium viewport/touch모의를실기기검사로부르지않는다. 운영HTTPS·배포/원격동기화는실측후아래에별도기록한다.
