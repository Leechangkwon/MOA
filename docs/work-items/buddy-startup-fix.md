# PC 비서 Windows 시작 실패 수정

- 상태: 사내 게시 완료·해당 Windows 실기 확인 대기
- 웹 기록 브랜치: cloud/buddy-startup-publication
- PC 구현 브랜치: cloud/buddy-startup-diagnostic / 7a0ff3f
- 기준 web main: 9387acf, 운영 v2.53.7 유지
- 환경: 서버 Codex 직접 구현·검증; 이번 실제 Windows 트레이는 사용자 확인 대기

## 원인과 범위
사용자가 update.3에서 `run.cmd` 실행 실패를 보고했다. 직접 Python 실행은 기존 인스턴스 연결 timeout 뒤 성공 문구를 출력했으며 netstat 50573 점유 없음, 직접 bind에서 WinError 10013을 확인했다. Windows 포트 접근 거부를 이미 실행 중으로 처리하던 버그다. 어떤 Windows 구성요소가 막았는지는 미확정이며 설정/방화벽을 바꾸지 않는다.

PC update.4는 접근 거부된 50573을 고정 예비 루프백 포트로 대체하고, 실제 사용 중인 포트에서는 중복 실행하지 않는다. 기존 인스턴스 연결 실패를 성공으로 안내하지 않는다. run.cmd의 pythonw 오류는 startup.py에서 네이티브 창/민감값 없는 로그로 알린다. setup.cmd 종료 pause, run-diagnose.cmd 추가. updater는 예비 포트의 실행 인스턴스도 확인한다.

웹 앱 코드·DB 스키마·설정·패키지 변경 없음. 이 작업의 웹 변경 허용 파일: 본 문서, STAGES.md, SECURITY.md, docs/CLOUD-HANDOFF.md, deploy/production-state.json(실제 게시 후 기록).

## 검사
- Python 단위/실제 루프백 IPC/10013 모사/실제 엔트리 실패 전달/시작 오류·민감값 가림/업데이트 보존 16/0. compileall 및 diff check 통과.
- update.3 ZIP을 격리 설치 폴더로 만들어 update.4 적용: 87개 파일 SHA 일치, config/queue/activity/.venv 원본 4개 그대로, 소스 백업 확인.
- ZIP: 271127 bytes, SHA256 9701b63fa83a2dc5033fdc68a843436a08ec34d0c34496bb7bfcb752593690e1.
- 증거: /home/changkwon/qaredi-buddy-startup-results-20261011.
- 미검증: 이 Windows PC의 최종 트레이·키·실제 GPT 응답. 과거 update.2 성공과 구분한다.

## 서버 적용
- 암호화 DB·코드·설정·storage 백업 및 복호화 확인 후 정상 검토 관리자 비밀번호/OTP 업로드 → 운영 악성코드 검사 → 사내 게시 → 일반 구성원 다운로드 SHA 확인.
- 기존 파일 삭제 없음. 새 업데이트 파일 추가 및 기존 current 플래그 변경만 정상 게시 API 사용. 이전 update.3 보존.
- 실패 시 기존 릴리스 유지. DB 복원/삭제/재시드 없음.

## 배포 결과
2026-10-11T04:08:10+09:00: 정상 업로드/ClamAV clean/사내 게시/실제 회원 다운로드 SHA 검증 23/0. 파일 ID `84c72359-015c-4cfc-a45b-5c1302dc1469`. 현재 update.4, 기존 update.3 파일 보존(삭제0). 기존 974개 storage SHA 및 1041개 파일 행의 current 플래그 외 값 보존. 직전 작업 이후 사라진 storage 22개는 이번 작업 전부터 DB purged 상태임을 확인했고 이번 수정과 무관하다.
백업 `/var/backups/tbox-v2/buddy-startup-20261011`, 복호화 확인. 웹 앱 런타임은 v2.53.7 `77ed769` 그대로. PC 소스 Git2 cloud/buddy-startup-diagnostic `7a0ff3f` 반영 완료. 해당 Windows 실제 실행은 사용자가 update.cmd로 기존 update.3 폴더를 고른 뒤 기존 폴더 run.cmd로 확인한다. 추가 의존성 없음.
