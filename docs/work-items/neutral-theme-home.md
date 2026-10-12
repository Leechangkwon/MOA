# 중립색 테마와 모바일 첫 화면

- 상태: 구현·독립 검토 완료, 운영 반영 전
- 브랜치: cloud/neutral-theme-home
- 기준 main: 566872d0eeacbd0609967ef3383bb87d2372d8f8, v2.58.1
- 담당: Codex root / 테마 구현 / 모바일 구현 / 독립 검토

## 요청과 범위

Aurora 전역 녹색·Bloom 전역 분홍으로 답답한 색 구성을 중립 표면+포인트색으로 바꾸는 시안을 사용자가 승인했다. 이어 모바일에서 이 화면이 첫 화면으로 뜨지 않는 문제를 함께 요청했다. 구현 계약은 docs/neutral-theme-home-directive.md. 시안의 예시 데이터는 서비스에 넣지 않는다. 기존 계정의 대시보드 설정·원문 데이터와 안전한 명시 목적지/인증 절차를 유지한다.

## 초기 조사

공통 theme 토큰이 canvas/surface/surface-2/fg/muted/line까지 같은 색 계열이고 컬러 shadow가 강조 면적을 키운다. Moa는 앞선 중립화가 있으나 다른4테마에는 반영되지 않았다. 모바일은 root/로그인/앱바홈/manifest와 실제 배치를 나누어 원인을 확인 중이다. 사용자에게 현재 보이는 화면 유형을 질문했으며 독립 조사는 계속한다.

## 검증과 운영

실행한 명령·출력·정확 범위를 완료 시 기록. 보안 lint/자산/위키와 관련 테마·문서·홈·로그인 회귀. 고정 후보/backup3종복호화·원본baseline/모의/drift 뒤 실제 HTTPS/원본 보존까지 확인하고 버전·기록·원격을 동기화한다. DB/패키지/설정 변경은 현재 없다. 실물Android·Safari·스크린리더 미검증. 실패 시 원인/증거를 유지하고 검토한 코드만 보정, 운영 데이터 복원/재시드/기존행 삭제0.

## 확정 구현과 모바일 진단

런타임 두 파일: public/assets/design.css(중립 표면·글자/테두리, 제한된 브랜드 강조, 대형 gradient/glow 감소), src/views/launcher.php(공개 홈 주요 CTA의 내 홈 로그인 안내, 동의필요 상태 기존 관문 안내). 문서 --bg/nt/pal·정해진 셀/행색·권한/API/저장형식 변경0. Moa와 Claude·고대비 예외 유지. 위키 테마 설명의 오래된 Astra 기본값도 현재 기본 Moa에 맞추었다.

모바일만의 redirect 분기나 위젯을 밀어내는 추가 hero는 재현되지 않았다. 일반 로그인/기존 세션/manifest는 이미 `/`로 간다. 명시적인 tbox·community·my-tasks 목적지는 원래대로 돌아간다. 사용자 실물 기기의 첫 주소·세션은 미확인이다. 공개 홈의 기존 서비스 CTA를 「내 대시보드 열기」로 명확히 하고, 동의가 필요한 경우에는 「동의하고 내 홈 열기」를 제공한다. 인증·동의 관문 우회0.

예약QA의 실제 저장 구성은 version1/revision16이고 이전 최근 작업·즐겨찾기 모듈로 그려지므로 `.dashboard-card` 수0이 정상이다. 최초 운영 진단에서 신규 v3 selector만 기다려 timeout한 로그를 보존했다. 실제 캡처에서 y248의 위젯 확인, 신규 합성 version3는 모바일y260/PCy270. 기존 v1을 강제 전환하지 않는다. 시안과 달리 보이는 데이터/배치는 계정 취향을 따른다. 필요 시 기존 「추천 구성 미리보기」를 사용자가 저장할 수 있다.

## 보존 기준과 사전 준비

암호화 DB/코드/설정·storage 3종 checksum과 전체 복호화 완료, stable 원본959 SHA·파일1042행·기존14표와제품6표 모든 원래행·ownerACL·설정85 baseline. 이전 작업975개와의 차이16개는 전부 옛 비활성 qa_ 계정이 2026-09-11 soft-delete한 파일이며 2026-10-12 04:00 기존 30일 휴지통 배치가 purged_at을 기록한 것으로 읽기전용 DB 대조 확인했다. 이번 작업 전의 정상 정기정리이고 원문/운영자료 삭제 작업0. backup 경로 /var/backups/tbox-v2/product-spaces-neutral-home-20261012. worker cursor1만 기존 운영상태로 제외한다.

selftest-base/neutral-theme-home·review-base/neutral-theme-home 태그는 공통 기준566872d0에 고정하고 번들을 만들었다. 태그 생성 시점은 초기 수락·독립 검토 시작 후이며 최종후보 검토 전에 보완한 것이다. 실제 시점과 실행 범위를 구분한다.

## 실행한 검사

- 기본 allowlist21/0, PHP 문법, security_lint 금지0, 자산40파일/71import, 위키69편·누락0.
- actual PHP/productionJS 모바일 진입76/0: 공개 CTA→login?to=home→root, consent gate, 명시 목적지, private layout 비노출, v1 저장배치·v3 추천과 단일 편집경로. 계정설정 PATCH0.
- 독립 CSS1334/0: 6테마·explicit/반대OS/system·5폭, 중립 표면/fg-muted-primary와 실제 버튼 대비, 문서 DOM/nt/pal·셀>행>머리 우선순위, 고대비/forced DOM. 원래 document CSS와 실제PM45/0. 고대비 모든 제어 대비를 전수 확인했다고 주장하지 않는다.
- 초기 snapshot의 빈 격리DB 공통196/0. 운영을 쓰는 옛 shell 전체 묶음을 실행한 것으로 과장하지 않는다. 마지막 CSS/view 변경은 최종 표적 검사로 덮는다.
- 운영 데이터 읽기+후보 CSS 가로채기 미리보기119/0: Aurora/Bloom×밝음/어둠×390/1280 홈·자료실·지목 원문4표. JS/5xx/브라우저쓰기0, 원문 전체 API 응답·기존 취향/revision 동일. **이는 후보 미리보기이며 배포 성공이 아니다.**
- 실제 본문 표면317/0. 표밀도391/0는 옛 일회성 시험의 HEAD 비교 baseline을 실제 밀도변경전 f3bec35로 고정한 격리 runner로 확인했다. 동일CSS끼리30%개선을 요구한 첫 실패로그 보존, 앱/원래시험 변경0.

기존 design_* 시험은 baseline에서 기본값Astra/Legal·사용자id·Authz stub/옛hero selector로 실패했다. 현재 Moa 기본/실제 공개 서비스 소개와 private loader 비노출로 의미를 맞추었다. 취소·저장·허용목록·OS/모션·개인자료 경계는 유지한다. 신규 색 중립폭 검사cutoff11은 muted #5f636b의 RGB차12를 과도하게 제한해12로 바로잡았고 앱수정0. 독립 전환30ms 혼합색 측정 실패는650ms 완료대기로 바로잡아1334/0; 런타임 완화0. 실패/중단 로그는 작업outputs에 남긴다.

DBmigration/설정/패키지/서비스설치 변경0. 실물Android/iOS·Safari·스크린리더·사용자 자기공격은 미검증. 운영 적용은 후보고정→정확파일모의→fresh drift→FF/patch/FPM→HTTPS/전체보존→기록/push 순서로 이어간다.

최종 테마 자체검사: 신규 actual PHP 1594/0(원래CSS와비교·legacy카드·private쓰기0), 갱신된 기존 디자인1354/0·console0. 최종 runtime SHA: design.css `2fd1d43af72893174bc2aa8136cec49982d2df04e42e1fe966f4ff9d4bd27ad1`, launcher.php `481f610fd58f1e30ee918ff5eafa149e51e02f2a1d5953f5f45d0d83fbeb3416`.

최종 독립 진입13/0·runtime2 SHA일치, 4부검토완료. consent CTA는 to미지정시 기존 member.js가 동의API성공뒤`/`로돌아가는계약과일치한다. 검토 outputs/neutral-theme-review/review.md·source-sha256.json.
