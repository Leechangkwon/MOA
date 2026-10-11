# 전역 드롭다운 한글 잘림과 단색 화면 개선

- 상태: 운영 v2.54.2 적용·HTTPS/원본 보존 확인 완료
- 브랜치: cloud/global-visual-fix
- 기준 main: a69fe989e712f193a8e6d57d6b480198f75099ca
- 사용자 근거: 13051.jpg, 홈 구성 modal의 select 한글이 세로로 잘림. 다른 화면에도 동일 문제, 원톤 화면이 답답하다는 요청.

## 목적과 범위
고정 높이 입력창에 모아 공통 padding/font 상속이 겹쳐 실제 텍스트 영역이 좁아지는 원인을 공통 select CSS에서 수정한다. native select/option·모바일 글자 확대·긴 선택값·여러 줄 목록·밝음/어두움·6디자인·고대비/forced-colors를 보존한다. 기본 모아의 배경/카드/모달/입력창 명도와 서비스별 강조색을 분리하고 과한 발광을 줄인다. 기존 다른 테마 선택과 오류/위험 의미 색은 보존한다.
CSS 표현만 변경, 계정/권한/API/DB/파일 변경 없음. 구현: 기존 프런트 에이전트, 독립 검토: 별도 보안 검토 에이전트. root 문서·검증·운영 적용.

## 검사
글꼴 크기+padding+테두리에 비해 contentbox 높이가 부족하지 않은지 실제 렌더에서 재현/검증. 360/390/1280px, 밝게/어둡게, 각 디자인, 고대비 및 Chromium/WebKit 가능한 환경 구분. 기존 홈/공간 수락·기존 서비스 및 실제 PHP/DB 공통 회귀, 전체 보안 린트·자산버전·위키 검사. 실물 Android native popup 렌더는 사용자의 기기 확인으로 구분한다.

## 후보 검증 결과
Chromium native control/6디자인/밝음·어두움/360·390·1280px·고대비/forced-colors 1408/0. 실제 PHP/격리 합성 DB 공통 회귀196/0. 보안 lint 금지패턴0·자산버전/위키69/문법/diff 확인 통과. 기존 홈57/0·서비스70/0. 독립 최종 suite1408/0·공간 UI26/0·기하72case/잘림0 확인. 별도 검토 baseline72case에서 선택창 content10px보다 한글 glyph11~14px이 높아 잘림 재현. 후보 content26px/모바일16px·glyph15px로 개선 확인. WebKit 실행파일 없음으로 명시 skip; 실물 Android native popup 미검증.

## 서버 적용
암호화 DB/코드/설정/storage 백업+복호화, 고정 후보 모의 파일 적용/드리프트 확인, main FF+발행+PHP reload, 실제 HTTPS/CSS 버전/모바일 화면과 원본 보존 검증. DB migration/패키지/nginx/cron 변경0. 실패 시 코드 복귀만 판단하며 DB 삭제/복원/재시드하지 않는다.

## 배포 결과
고정 후보5f4e4ef→main FF, v2.54.2 런타임 `c22d993d84a2666dd9cd4d02fad0c6702d40182e` / 2026-10-11T13:47:19+09:00. 코드7파일 정확 모의 적용/삭제0, 실제 변경CSS2개·발행이력3개, DB migration0·패키지/설정/PC 변경0. 암호화3종 SHA/전체복호화/원본975 storage SHA 확인; 기존 cron cursor로 tar directorymtime 경고 발생했으나 원본데이터 정상 검증. PHP-FPM reload 후 실제HTTPS42/0, 밝음·어두움390/1280px 한글 glyph 여유/모바일16px/checkbox가로/overflow0/Escape/JS·5xx0·CSS 정확바이트 확인. 기존14표+제품6표 모든 원래행 및 ownerACL·파일1042행/975원본SHA 보존,85설정파일 SHA 동일. DB복원/재시드0. Git2 atomic push는 아래 동기화 기록을 따른다. 실물 Android nativepopup/WebKit 미검증은 유지.

Git2 최종: main·cloud/global-visual-fix·v2.54.2 atomic push 완료. fetch로 두브랜치 ed9273974fc2 일치 확인. 이 동기화 기록 후속은 같은브랜치로 전달하며 태그는 검증된 런타임 c22d993 유지.
