# 대시보드 편집 사이드바

- 상태: 구현·후보 검증 완료, 운영 검증 전
- 기준: main `03314850e240745278d424752eb5af7f78893fed` / 운영 v2.56.1
- 브랜치: `cloud/dashboard-inspector`
- 사용자 지적: 하단의 큰 상세설정창이 위젯을 가리고 보기 좋지 않음.
- 지시서: `docs/dashboard-inspector-directive.md`

## 구현

개인·공동 대시보드에 상단 추가/취소/배치 저장·완료 도구와 오른쪽 선택 위젯 설정을 적용했다. PC1100px 이상은304px 설정 영역을 따로 확보하고, 좁은 화면은 명시적인 위젯 설정 버튼으로 native dialog를 연다. 소·중·대/내용 설명/출처/잠금을 한곳에 모으고 숨김·직접 조절은 더보기, 복원·기본 배치·시작 안내는 배치 옵션에 묶었다. 카드 머리는 이동·설정으로 줄였다. 녹색 테마 대비와409 오류 안내 표시를 보정했다.

기존 저장 v1/v2/v3, desktop/mobile760px 기준, 자유 배치·출처·개별 잠금·revision·권한은 보존한다. 설정 열기/닫기만으로 배치를 바꾸지 않는다. 저장/취소/제거 복원/401·403/화면 전환 시 DOM·초점·배경 스크롤을 정리하며 권한을 잃은 출처 이름과 선택 목록도 비운다. API/DB/설정/패키지/라우트 변경0, 런타임은 public/assets의 canvas JS/CSS, widgets JS/CSS, home-dashboard.js, space-page.js 6개다.

## 검사 증거

- 통합 관련7suite427/0: home57·sync15·space26·stale14·actions96·widgets166·inspector53. 기존 assertion은 유지하고 상세창/배치 옵션에 맞게 selector를 갱신했다.
- 최종 바이트 Canvas62/0·실제 PHP/strict-CSP inspector81/0: 최종409 표시와 모든 위젯을 숨긴 뒤 닫기 초점 복귀 포함. 기존 자유 배치142/0은 마지막 두 좁은 보정 직전 검사이며 보정은 최종 Canvas/독립 검토로 확인했다.
- 격리 공통196/0: 신규 합성 DB의 PHP/HTTP/브라우저 동등 기능 검사. 운영 레거시 shell8개를 실행했다고 주장하지 않는다. 마지막 UI 보완 이전 스냅샷이며 최종 UI 전용 검사가 이후 차이를 검증한다.
- root JS4개 구문·diff check·보안 lint 금지0·자산버전37/0·위키69편 구조/누락0.
- 별도 컨텍스트 실제 PHP UI52/0·최종 차이24/0·출처 권한회수/지연13/0. 최종6자산 해시 일치. 초기 focus/lifecycle 로그의 실제 all-hidden 초점 결함은 수정 후 통과; native Tab→browser chrome BODY와 hiddenlegacy DOM 중복 계수는 하네스 오판을 정정하고 배경컨트롤 차단/실제 위젯 수로 검증했다. 상세4부 SECURITY.md.
- 증거: 작업공간 outputs/dashboard-inspector-checks, outputs/inspector-review, outputs/inspector-cross, outputs/inspector-final-source-hashes.json. 운영 원본·비밀을 저장소에 넣지 않았다.

## 운영 순서·원본 보존

신규 암호화백업 `/var/backups/tbox-v2/product-spaces-inspector-20261011` 3종 checksum·전체 복호화 확인, 원본975개 SHA 및 설정85개/기존14표·제품6표 baseline 완료. feature commit을 고정하고 파일 모의 적용을 마친 뒤 origin/main·운영 checkout drift를 확인한다. 코드 fast-forward와 patch 릴리스 후 실제 HTTPS에서 검토 계정만으로 저장/reload/잠금/취소와 모바일 설정을 확인한다. 검토 개인 취향은 API로 원래값 복구하고 새 합성 공간은 보관한다. 기존 행·owner/ACL·1042파일행·975원본SHA·85설정을 비교한 뒤 배포 기록과 원격을 동기화한다. DB migration/운영 복원/재시드/삭제 없음.

실패 시 운영 데이터는 복원하거나 재시드하지 않고 증거를 보존한다. 코드만 문제라면 차이를 고친 새 커밋으로 검증·릴리스한다. 기존 배치와 다른 화면의 좌표를 보존하며 강제 push하지 않는다.

## 미검증

실물 Android·Safari/WebKit·스크린리더·사용자 자기 공격은 미검증이다. Chromium viewport/touch 모의 시험을 실기기 시험으로 부르지 않는다. 운영 HTTPS와 원본 보존의 최종 결과는 아래에 별도 기록한다.
