# F-55 지시서 — 백업 원격 사본: 사용자 PC 가 매일 끌어온다 (pull)

> Fable 작성 2026-09-16. 배경: 2026-09-15 보안 재점검(`docs/tbox/security-review-2026-09-15.md`) 3번(높음) 「백업이 같은 디스크·같은 호스트에만 있다」·5번(높음) 「로그가 서버 안에만 있다」.
> 사용자 결정 2026-09-16: 끌어올 곳은 **「여기」 = 지금 접속 중인 사용자 PC**(Windows, PowerShell, 출처 10.0.4.173). 사내 NAS·클라우드(Q-4)는 여전히 미결 — PC 는 **임시 원격 1개**다.

## 목표

서버가 매일 03:00·03:30 에 만드는 **암호화 백업 산출물**(`db-*.dump.gpg`, `files-*.tar.gz.gpg`, 각 `.sha256`)과 **앱 로그**(`/var/log/tbox-v2/*`)를, 사용자 PC 가 매일 **끌어와(pull)** 서버 밖 사본을 만든다. 서버에는 아무 자격증명도 두지 않는다(원격 쪽 비밀을 서버에 두면 root 를 잡은 공격자가 원격 사본까지 지운다 — SECURITY 8.4-4).

## 요구사항

- PRD 7.8 S-08(3-2-1 원칙 · 보관 정책 45일 · 키는 백업과 다른 장소) · Q-4 · 7.8 백업 보안 표
- ARCHITECTURE 11.1(백업 일정 · 산출물 이름) · 11.2(복구 순서)
- SECURITY R-08(키 관리) · 8.4-4(원격 사본 권고) · R-88(새: PC 사본의 위험)
- CLAUDE.md 4장(서버 보안 핵심 파일 수정 금지 — 이번 작업은 **서버 파일을 하나도 바꾸지 않는다**)

## 설계 (Fable 결정)

| 항목 | 결정 | 이유 |
|---|---|---|
| 방향 | **PC → 서버 pull**. PC 가 `ssh changkwon@192.168.105.44` 로 들어가 root 권한 `tar` 를 표준 출력으로 받는다 | 서버에 원격 자격증명 0. 사용자는 이미 키로 SSH 접속 중(비밀번호 로그인은 09-15 차단) |
| 서버 쪽 명령 | `sudo -n tar -C / --newer-mtime='3 days ago' -cf - var/backups/tbox-v2/db var/backups/tbox-v2/files var/backups/tbox-v2/weekly var/backups/tbox-v2/monthly var/log/tbox-v2` | 산출물은 root 0700 이라 `scp` 로는 못 읽는다. `sudo -n`(NOPASSWD, R-53 수용) + `tar` 한 번으로 해결. **3일분만** 골라 하루 약 140MB(09-16 실측 19개 항목). 서버 파일 변경 없음 |
| 제외 | `files-mirror/`(평문 미러, 190MB) · 백업 루트의 `tbox_re_*.dump`(09-11 사고 평문 덤프, 증거) | 평문을 PC 로 옮기지 않는다. 암호화 산출물과 로그만 |
| 전송 방식 | PowerShell 에서 `ssh` 의 표준 출력을 **파일로 직접 리다이렉트**(`Start-Process -RedirectStandardOutput` 또는 `cmd /c "ssh … > file.tar"`) → `tar.exe -xf` 로 풀기 → tar 삭제 | PowerShell 파이프(`\|`)는 바이너리를 문자열로 바꿔 깨뜨린다. Windows 10 이상은 `ssh.exe`·`tar.exe`(bsdtar) 내장 |
| PC 저장 위치 | 기본 `C:\tbox-backup\` — 안에 `var\backups\tbox-v2\…`·`var\log\tbox-v2\…` 그대로(tar 경로 유지) + `pull.log` | 서버 경로를 그대로 두면 `restore.sh` 절차(ARCH 11.2)와 1:1 로 대응된다 |
| 검증 | 풀어낸 뒤 **최신 db·files 산출물 각 1개**를 `.sha256` 과 `Get-FileHash -Algorithm SHA256` 로 대조. 불일치·파일 없음 → 종료 코드 1, `pull.log` 에 `FAIL` | 서버 `verify_backup.sh` ①단계와 같은 기준. 복호화 시험(②)은 PC 에 gpg 가 없을 수 있어 하지 않는다 — 대신 분기 리허설(PRD 7.8) 때 서버에서 |
| 보관 | PC 사본도 **45일** 지나면 삭제(파일 이름의 날짜 `YYYYMMDD` 기준, 로그는 수정 시각 기준) | PRD 7.8 보관 정책과 같게. 어떤 백업도 45일 넘게 남지 않는다 |
| 키 | `backup.key` 는 **백업 폴더에 두지 않는다**. `-Key <경로>` 스위치로 **한 번만** `ssh … sudo -n cat /etc/tbox-v2/backup.key` 를 받아 사용자가 지정한 위치(USB 등)에 저장. 기본 실행에서는 키를 건드리지 않는다 | PRD 7.8 「키는 백업과 다른 장소」. 키와 백업이 같은 PC 에 있으면 PC 유출 = 전체 데이터 유출(R-88) |
| 일정 | Windows 작업 스케줄러 **매일 05:00**, `StartWhenAvailable`(PC 가 꺼져 있었으면 켜진 뒤 실행) | 서버 04:30 `verify_backup.sh` 뒤. 등록 명령은 스크립트 `-Install` 스위치로 제공 |
| 실패 통보 | `pull.log` 마지막 줄 + 종료 코드. 3일 이상 `OK` 가 없으면 사용자가 알아채야 한다(자동 경보 없음 — 한계로 기록) | PC 에는 MTA·경보 체계가 없다 |

### 서버 층 확인 (Fable, 09-16 실측)
- `sudo -n tar … \| tar -tf -` 46개 항목(전체), `--newer-mtime='3 days ago'` 19개 항목 · 약 140MB.
- `scripts/alerts.php` AL-9 의 위험 명령 목록은 `psql·pg_dump·pg_restore·dropdb·truncate·chattr·rm·find -delete` — `tar`·`cat` 은 없어 매일 실행해도 헛경보가 나지 않는다. AL-3(새 출처 SSH 성공)은 10.0.4.173 이 이미 알려진 출처.
- `.sha256` 형식은 `<해시>  <파일이름>` (경로 없음, `sha256sum -c` 호환) — PC 검증은 이 형식을 파싱한다.

## 수정(생성) 파일 — 허용 목록

| 파일 | 내용 |
|---|---|
| `deploy/pc/pull_backup.ps1` | **새 파일**. 아래 「스크립트 요구」 |
| `deploy/pc/README.md` | **새 파일**. 처음 설정 순서(키 접속 확인 → 첫 실행 → 스케줄 등록 → 키 사본), 매일 확인법, 실패 시 볼 것, 한계 |

이 밖의 파일은 **만들거나 고치지 않는다.** 특히 `README.md`·`deploy/install.sh`·`deploy/cron.d/tbox-v2`·`scripts/*`·`src/*` 는 다른 작업(F-54)이 진행 중이라 손대면 충돌한다. PRD·ARCH·SECURITY·STAGES 는 Fable 이 고친다.

## 스크립트 요구 (`deploy/pc/pull_backup.ps1`)

```
매개변수 (모두 기본값 있음 — 사용자가 편집 없이 실행할 수 있어야 한다)
  -Server   기본 '192.168.105.44'      -User  기본 'changkwon'
  -Dest     기본 'C:\tbox-backup'       -Days  기본 3   (서버 tar 의 --newer-mtime)
  -KeepDays 기본 45
  -Key <경로>   : backup.key 를 그 경로에 한 번 저장하고 종료 (백업 pull 은 하지 않음). 이미 있으면 덮어쓰지 않고 종료 코드 2
  -Install      : 작업 스케줄러에 'tbox-v2 backup pull' 등록(매일 05:00, StartWhenAvailable, 현재 사용자, 이 스크립트의 절대 경로) 후 종료
  -Uninstall    : 그 작업 제거
동작 (기본)
  1. Dest 없으면 생성. pull.log 에 시작 시각 기록 (모든 출력은 화면 + pull.log 두 곳)
  2. ssh -o BatchMode=yes -o ConnectTimeout=15 <User>@<Server> "sudo -n tar -C / --newer-mtime='<Days> days ago' -cf - var/backups/tbox-v2/db var/backups/tbox-v2/files var/backups/tbox-v2/weekly var/backups/tbox-v2/monthly var/log/tbox-v2"
     → 표준 출력을 <Dest>\_incoming.tar 로 바이너리 그대로 저장 (PowerShell 파이프 금지)
     → ssh 종료 코드 0 이 아니면 FAIL (비밀번호를 물으면 BatchMode 때문에 즉시 실패 — 그 안내 문구를 남긴다: 「키 등록 필요」)
  3. tar.exe -xf <Dest>\_incoming.tar -C <Dest>  (tar.exe 없으면 FAIL 안내)  → _incoming.tar 삭제
  4. 검증: <Dest>\var\backups\tbox-v2\db 의 최신 db-*.dump.gpg 와 files 의 최신 files-*.tar.gz.gpg 각각
     .sha256 첫 칸 해시 == Get-FileHash 결과(대소문자 무시). 둘 다 맞아야 OK
     오늘 날짜(yyyyMMdd) 산출물이 없으면 WARN (서버 백업이 아직 안 돌았거나 실패)
  5. 보관: db·files·weekly·monthly 의 파일 이름 날짜가 KeepDays 보다 오래되면 삭제(.sha256 도 함께). 로그는 LastWriteTime 기준
  6. 요약 한 줄: OK|WARN|FAIL, 받은 항목 수, 바이트, 최신 db·files 이름 → pull.log. 종료 코드 OK/WARN 0, FAIL 1
금지
  - 서버 쪽에서 rm·psql·pg_dump·gpg -d 를 실행하지 않는다 (읽기 전용 tar·cat 만)
  - 비밀값(키 내용)을 화면·pull.log 에 찍지 않는다. -Key 는 저장 경로와 바이트 수만 출력
  - 외부 모듈·설치 없음. Windows 내장 ssh.exe·tar.exe·PowerShell 5.1 이상에서 동작
  - 자리표시자(<서버IP>)를 사용자가 편집해야 하는 구조 금지 — 전부 매개변수 기본값
  - PowerShell 파이프로 바이너리를 흘리지 않는다
```

## 완료 기준

- **T-67**(새, PRD 12.2 에 Fable 이 추가): (a) 기본 실행으로 `<Dest>\var\backups\tbox-v2\db\db-<오늘>-0300.dump.gpg` 와 `files\files-<오늘>.tar.gz.gpg` 가 생기고 sha256 대조 OK, `pull.log` 마지막 줄 `OK` (b) `.sha256` 한 글자를 바꾼 뒤 재검증 → FAIL, 종료 코드 1 (c) `-Key` 로 키 저장 → 65바이트, 백업 폴더 안에는 키 파일 없음 (d) `-Install` 뒤 `Get-ScheduledTask 'tbox-v2 backup pull'` 존재, 05:00·StartWhenAvailable (e) 이름 날짜 46일 전 가짜 파일을 두고 실행 → 삭제됨
- **이 VM 에는 PowerShell 이 없다.** 구현자는 실행하지 못한다 → 정적 점검만 하고 **「미검증 — 사용자 PC 에서 (a)~(e) 실행」** 으로 보고한다. 「테스트했다」고 쓰지 않는다.
- 구현자가 할 수 있는 확인: 서버 쪽 명령을 이 서버에서 그대로 실행해 `tar -tf -` 목록과 크기를 보고에 붙인다(읽기 전용). `deploy/pc/README.md` 의 명령이 스크립트 매개변수와 일치하는지 대조.

## 보고 형식

변경 파일 목록 / 실행한 명령과 출력 원문(서버 쪽 tar 목록) / 정적 점검 내용 / **못 한 것과 이유**(PowerShell 실행 불가 — 사용자 PC 에서 T-67 (a)~(e))
