# 전체 웹 성능·사용성 개선과 MOA 저장소 이전

- 상태: 개발 중, 최종 통합 검증 진행
- 작업 브랜치: `cloud/whole-site-improvements`
- 기준 main 커밋: `d575fed6144a` (v2.79.1, 기존 운영 모바일 nowrap 수정 보존)
- 담당 환경: 서버의 별도 작업본 `/home/changkwon/moa-modernization-20261119`
- 지시서: `docs/modern-web-improvements-directive.md`

## 목적과 범위

사용자가 승인한 전체 성능 평가 Page의 적용 대상 개선을 하나의 묶음으로 진행한다. GoogleChrome/modern-web-guidance-src의 고정 판 `e8feb43e0e61c791f905904222492bf2e4e573fe`에서 252개 가이드를 분류했다. 가이드별 현재 변경/보존/조건/미검증 대조는 `docs/reports/2026-11-19/guidance-252-implementation.csv`다. 이번 변경이 직접 닿는35개 핵심 가이드를 연결했으며113개 적용 대상 전체의 모든 규칙을 준수 완료했다는 의미가 아니다. 113개 현재 적용 대상·117개 조건부·14개 비대상·8개 초안이다. 모든 웹 기술을 무조건 도입하는 의미는 아니다. 기준 평가는 실제 HTTPS 36회(6화면×2프로필×3회), CLS 별도 12회이며 lab 결과를 실제 사용자 p75/INP로 해석하지 않는다.

기능 변경:

- `app.js`의 자료실을 `files-page.js`로, 커뮤니티 문서 화면과 실제 편집을 `page.js`·`editor-runtime.js`·`editor-loader.js`로 분리한다. 읽기에는 ProseMirror·스키마·표 편집기를 받지 않는다. 첫 입력·IME·첨부·초점·실패 재시도·닫기·임시저장·기존 CAS를 유지한다. 같은 그룹의 동적 import에도 자산 버전을 적용한다.
- 문서 머리 설명의 서버/클라이언트 문구와 flex 배치를 맞추고 시작 안내를 기본 접힘으로 둔다. 첫 사용 안내는 고정 입구에서 선택한다. 기존 진행 중 안내·법적 동의는 기존 계약을 유지한다.
- 이야기의 작성자 도구·상태 영역을 예약하고 빈 화면 글자 대비를 높인다. 첫 이미지의 우선순위, 뒤 영상의 preload, 독립된 화면 밖 카드의 content-visibility, 추가 페이지의 DOM 보존을 적용한다. 현재 API에 없는 이미지 가로·세로를 지어내지 않는다.
- 허용된 그림판 입구를 작업 머리에 둔다. 그림판은 요소 직렬화 재사용·미리보기 묶음 전송·프레임당 장면 갱신·숨긴 탭 연결 중지·전체 보기·DOM 요소 목록·연결/저장 상태·수치 진단을 제공한다. 늦은 저장·폴링·판 전환·되감기 응답이 새 그림을 덮지 않도록 보완한다. 서버 권한·검증·LWW·사건 사슬 계약은 그대로다.
- 업로드는 실제 전송 바이트→서버 확인→목록의 검사 상태를 구분한다. 결과 불명 전송을 자동 재시도하지 않는다. 기존 동일 계정 CSRF 새로 받기만 같은 FormData로 한 번 재시도한다. 한글 Enter/229 보호와 native 오류 설명/초점을 적용한다.
- 명시적으로 켠 `MoaPerformanceDiagnostics`는 이 탭의 식별자 없는 수치만 메모리에 보관·다운로드한다. 진단 서버 전송·브라우저 영구 저장·새 API·DB는 없다. interaction maximum은 정식 INP가 아니다.

신규 저장소 `git@github.com:Leechangkwon/MOA.git`에 기존 main 952커밋을 강제 push 없이 전송했다. 기존 Git2는 `legacy-git2`로 보존했다. 사용자 등록 deploy key는 서버 개인 경로에서만 사용한다. Gitleaks 8.30.1 전체 main 이력 검사 62개는 공개 테스트 값/무결성 해시/런타임 PEM 문자열 조립/고정 upstream 공개 Firebase 설정으로 검토했고 `.gitleaksignore`의 개별 fingerprint만 승인했다. 미검토 실제 비밀 탐지 0개. 개인키·토큰·실제 사용자 원문을 공개 저장소에 추가하지 않는다.

## 검사

구현 집중 검사: 편집기 지연 11/0, UI/미디어 114/0, 사용 안내 126/0. 별도 문맥 검토와 수정 후 그림판 경합 34/0, 공통 클라이언트 36/0. 구현 문맥과 별도 검토 문맥을 구분했고 메인이 합친 판의 PHP/SQL/브라우저 검증을 수행한다.

증거는 비공개 `/home/changkwon/moa-modernization-results-20261119/`에 보관한다. `editor-loading.md`, `ui-media.md`, `whiteboard-independent-review.md`, `root-client-review.md`에 변경 파일·명령·원문·한계가 있다. 최종 합친 판: 실제 PHP/DB/Chromium 공통69/0, 편집·첨부·이미지·권한·360/412/1280 밝게/어둡게/고대비153/0, 템플릿194/0, 사용 안내52/0. 공통 단위36/0·그림판34/0·편집14/0, 자산61/0, 보안 린트0건, 위키71편/0. 그림판 실제 엔진 잔여 기능54/0도 통과했다(그리기·기록·되감기·확정·새 판·DOM 목록/전체 보기·CSP·360/412 화면). 300ms 부하는 별도 미달로 유지하며, 배포 후 HTTPS·동일 조건 성능 수치는 아래 배포 결과에 추가한다. 회귀는 해당일 밤 대기열에서 한 번 수행한다.

미검증: 물리 휴대폰/Safari/스크린리더/물리 한글 IME, 분산 실제 기기의 그림판 p95≤300ms, 현장 Core Web Vitals p75. 그림판 최종 부하(같은2코어 호스트·실제 FPM8·6 Chromium세션·608.05초): 282 strokes, 3821 미리보기, p95 1195ms로300ms 미달(127/1). 기존1333ms보다 낮지만 기존330 strokes와 호스트 부하가 달라 순수 개선율로 해석하지 않는다. HTTP p95 532.2ms는 기존356.5ms보다 증가했고, FPM최대활성7/8·대기열0·중계기최대67.7MiB다. 근거 whiteboard-integrated-final/load-results.json. 성능 단언을 낮추거나 통과 처리하지 않았다. 배경 캡처는 기존 1차 b 후속이며 이번 성능 개선 범위가 아니다.

### 마지막 브라우저 게이트에서 찾은 재시도 결함

기존 Promise loader 단위 검사는 통과했으나 실제 Chromium에서는 첫 runtime 네트워크 실패가 module map에 남아 같은 URL을 다시 import해도 요청이 한 번뿐이었다. 빈 합성 fixture의 A/B/C 검사에서18/6을 기록했고 C의 한글 보존은 통과, 재요청/복원/저장은 실패했다. B의 추가3실패는 엄격 CSP 아래 테스트의 문자열 대기 문제와 함께 분리해 조사한다. 이 결과는 최종 통과로 계산하지 않는다. 고정 canonical 경로의 실패 후 재요청과 기존 app/PM 의존 모듈 identity 보존을 보정한 뒤 같은 실패 주입 게이트만 다시 확인한다. 원문 stale-cache-final.log와 stale-cache-adapter.md.

## 서버 적용

- DB 변경: 없음. 데이터 migration·권한 모델·검색 SQL·사건 사슬 변경 없음.
- 패키지/설정/cron/서비스: 새 운영 패키지·진단 서버·서비스 없음. Git 원격과 전용 SSH 쓰기 인증만 변경. PHP 화면 반영에 필요한 기존 FPM reload는 최종 반영 때 기록한다. 중계기/vendor 재빌드 없음.
- 보존: 기존 업무 행/소유권/ACL·원본 저장소·기존 설정 지문을 전후 대조한다. 원래 SECURITY/STAGES의 다른 작업 메모와 미추적 문서를 유지하고, app.css의 기존 모바일 nowrap hunk를 새 판에 포함한다. 기존 변경 원본·패치·Git bundle을 비공개 결과 폴더에 보관했다.
- 반영: 후보 격리 검증 → 암호화 백업 checksum·전체 복호화·archive 구조2/2 확인 → 고정 커밋/원격 드리프트/모의 FF → 원래 변경 보존하며 main FF → 실제 HTTPS/동일 조건 성능/행·파일 보존 확인 → 운영 기록 push. `deploy/install.sh` 사용 없음.
- 실패 시: 기존 커밋과 모바일 CSS를 보관한 별도 checkout으로 코드만 되돌릴 수 있다. 이번 판은 스키마를 바꾸지 않는다. 업무 데이터의 삭제/복원/재시드 명령을 실행하지 않는다.

## 배포 결과

통합 기능 수락 통과, 성능 목표300ms 미달 유지. 백업 checksum·전체 복호화·archive 구조2/2 확인. Git 이력 이전 완료는 운영 반영 완료를 뜻하지 않는다. 검증한 발행 커밋·버전·백업·실제 확인·잔여 항목은 운영 확인 후 기록한다.

- 캐시 최종: 첫 runtime 요청 실패에서 retry query를 붙이는 entry 재요청으로 보정. 첫 보정 실제25/0, 오류 문구 소거 후 최종26/0, 집중 단위14/0. app 그룹 `a9fddd18fefd`·PM `5823578fa61a`의 의존 모듈 identity를 보존한다. 정적 의존 모듈 자체의 module-map 실패 복구까지 보장하지 않는다. 자산 검사는 최종61/0. 독립 검토의 optional-call P2는 동일 재현에서 write/check가 모두 거부됨을 확인해 해소했다. 검토 원문 editor-retry-independent-review.md.
