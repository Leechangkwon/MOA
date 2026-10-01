# 8~9단계 공통 경로 교차 회귀

운영을 고정 참조하는 기존 common8 스크립트를 실행하지 않고, 이번 변경과 맞닿는 행동을 **실제 HTTP·브라우저**로 확인하는 별도 시험이다. 기존8개 스크립트 전체 통과를 뜻하지 않는다.

```bash
bash tests/stage89_cross_regression/run.sh
# 또는 결과 경로를 지정
python3 tests/stage89_cross_regression/run.py --output /absolute/new/results-directory
```

실행 전에 현재 소스를 독립 사본으로 고정한다. 운영 `/var/www/` 아래에서는 실행을 거부한다. 포트는127.0.0.1:8548(또는 --port8558)만 쓰며 사용 중이면 종료한다. 결과 폴더가 이미 있어도 종료한다. Python requests·playwright/Chromium, PHP, PostgreSQL 도구와 전용 `~/.config/qaredi/pgpass-testdb`가 필요하다.

## 격리와 정리

빈 `tbox_stage89_test_cross_<pid>` DB에 저장소 스키마·마이그레이션·최소 권한을 설치한다. 기존 stage89 합성 fixture로 claude_ 계정5개와 파일 바이트를 만들고, 별도 fixture가 무작위 비밀번호·TOTP를 비공개 파일에만 보관한다. 운영 DB의 행·덤프·업무 파일은 복사하지 않는다. 기존 앱 연결키만 읽어 새 DB에서 앱 권한을 사용한다. 시험 SQL과 모든 HTTP 쓰기는 임시 DB·저장소로 향한다. 종료 시 자신이 만든 서버 프로세스 그룹·DB·임시 폴더만 정리한다. 토큰·쿠키·비밀번호·TOTP는 결과에 저장하지 않는다.

## 대응 범위

| 기존 시험 | 여기서 실제 확인하는 행동 |
|---|---|
| stage26 |5역할 세션, 관리자 재인증 관문, 역할보기 허용/거부·유효역할·실제 감사행위자·중지·유휴만료, 자식/파일 있는 폴더 삭제 거부 |
| help_button | t-box/설정/커뮤니티/오늘의 도움말 열기·내용·Esc,1280라이트·390다크·가로넘침·JS오류 |
| api_whoami | 최소응답·업로드 권한 일치·토큰/세션 분리 |
| api_read | 현재6필드 응답(exec_content 포함), 권한별 목록·검색 입력·한도·실제 다운로드 바이트·403/404 |
| api_folders | 세션/토큰 목록 일치, 생성·중복·수정·순환 거부·삭제, 파일 업로드·이동·범위별 삭제 |
| f66 | 비밀번호+TOTP 발급·180일·목록 비밀 비노출·코드 재사용·타인 회수 거부·본인 회수·감사 비밀 비노출 |
| f68 | 폴더순환/설명길이 사유 문구와 감사 코드, 모르는 키·인증실패·403/404 통일 |
| q_launcher | 익명 타일 비노출·쿠키0·로그인후 타일·no-store, 옛 동적경로308·POST무처리, 새 정적주소 |

## 남는 한계

운영 nginx/FPM/TLS/속도제한과 옛 정적주소 리다이렉트는 이 PHP 사설 인스턴스의 검증 범위가 아니다. stage26의108개 원본 항목, 역할 강등·모든관리폼 행렬, F66 만료·계정상한·실패횟수 전체, F68의15가지 전체(매크로·실행파일 등)는 실행했다고 표시하지 않는다. 원본 도움말 시험의 고정13개 개수는 현재 확장된 화면 수와 다르므로 그대로 합격시키지 않는다. 파일명 변경 전체 회귀는 별도 file_rename 시험이 맡는다.

결과: results.json(각 상태/행동), source-hashes.json(실제 사본), schema.log, http.log, 도움말PNG. 실패나 예외는 실패로 기록하며 부분 완료를 합격으로 쓰지 않는다.

## 실행 기록

2026-10-01 최신 독립 사본 `root-cross-review-02`에서 **149통과·0실패**. 결과는 작업 부모 `root-cross-results-08/`에 있고 합성 DB/소유 서버 정리도 확인했다.1280라이트·390다크 PNG를 직접 열어 문구·배치·가독성을 확인했다. 이 기록은 그 사본의 source-hashes.json으로 한정된다.

## 파일 반영 도구 모의 검사

`deploy_cases.py`는 운영 기준 a39ec15의 새 Git archive 사본에 기준7ef의 F2코드5개만 덮어 운영과 같은 이전 파일을 만든다. 이 임시 디렉터리에서만 파일 적용을 수행하며 운영 경로를 인수로 받으면 거부한다. DB·웹·cron·서비스를 실행하지 않는다.

```bash
python3 tests/stage89_cross_regression/deploy_cases.py \
  --source /absolute/reviewed/repository \
  --package /absolute/immutable/package \
  --output /absolute/new/private/results
```

첫 후보에서53통과·0실패(`deploy-package-results-01/results.json`). 기본 검증의 무변경, 외부 manifest pin·schema 확인 필수, 원본 드리프트·F2보존·심볼릭링크·FIFO·잘못된 경로·payload 변조 거부,75파일 최초 적용과 백업/모드/소유권, 재적용0변경, 부분 적용 재개를 확인했다. 결과 폴더에 명령과 종료코드·저널·임시 파일 사본을 보존한다. 최종 발행본 재생성 후에도 별도 새 결과 경로로 실행한다.
