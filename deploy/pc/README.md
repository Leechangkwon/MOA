# 백업 원격 사본 — 사용자 PC 가 매일 끌어온다 (F-55)

서버(`192.168.105.44`)가 매일 만드는 **암호화 백업**과 **앱 로그**를, 이 PC 가 매일 한 번
끌어와(pull) 서버 밖 사본을 만든다. 실행 파일은 이 폴더의 `pull_backup.ps1` 하나다.

## 왜 필요한가

| 근거 | 내용 |
|---|---|
| PRD 7.8 S-08 | 3-2-1 원칙 — 사본 3개 · 매체 2종 · **원격 1개**. 보관 정책은 45일 상한. 키는 백업과 **다른 장소** |
| ARCHITECTURE 11.1 | 서버가 03:00 `backup_db.sh`, 03:30 `backup_files.sh`, 04:30 `verify_backup.sh` 를 돌려 만든 산출물이 대상 |
| SECURITY 8.4-4 | 2026-09-11 침해 조치 4번 「백업 원격 사본 + `backup.key` 사본을 서버 밖에」 |
| SECURITY R-08 | 키를 잃으면 암호화 백업 전체가 무용지물. 키와 백업이 한 곳에 있으면 그 한 곳이 뚫릴 때 전부 뚫린다 |

지금까지 백업은 **서버 같은 디스크에만** 있었다. 서버 root 를 잡은 공격자는 백업까지 지울 수 있다.
그래서 방향을 **PC → 서버 pull** 로 잡았다. 서버에는 이 PC 의 자격증명을 하나도 두지 않으므로,
서버를 통째로 잃어도 PC 사본은 남는다.

## 준비물

- Windows 10 1803 이상 (`ssh.exe`·`tar.exe` 내장) · PowerShell 5.1 이상
- 이 PC 의 SSH 공개키가 서버 `changkwon` 계정에 등록돼 있을 것 (서버는 2026-09-15 부터 비밀번호 로그인 차단)
- 서버 `changkwon` 이 `sudo` 를 비밀번호 없이 쓸 수 있을 것 (현재 설정)
- 설치할 것 없음. 외부 모듈을 쓰지 않는다

## 처음 설정 — 네 단계

PowerShell 창을 열고 이 폴더로 이동한 뒤 순서대로 한다.

### 0. 스크립트를 PC 로 가져오기

이 파일은 서버 저장소(`/var/www/tbox-v2/deploy/pc/`)에 있다. PC 의 PowerShell 에서 한 번 복사한다(서버 터미널이 아니라 **PC** 에서).

```powershell
mkdir C:\tbox-tools -Force
scp changkwon@192.168.105.44:/var/www/tbox-v2/deploy/pc/pull_backup.ps1 C:\tbox-tools\
cd C:\tbox-tools
```

스크립트가 갱신되면(저장소 커밋) 같은 명령으로 다시 받는다. `-Install` 은 이 경로(`C:\tbox-tools\pull_backup.ps1`)를 작업에 박아 두므로 파일을 옮기면 `-Uninstall` 뒤 다시 `-Install` 한다.

### 1. 키 접속 확인

```powershell
ssh changkwon@192.168.105.44 echo ok
```

- `ok` 가 나오면 통과다. (처음이라면 호스트 키를 믿겠냐고 한 번 묻는다 — `yes`)
- **비밀번호를 물으면** 아직 키가 등록되지 않은 것이다. 서버 콘솔에서 이 PC 의
  `%USERPROFILE%\.ssh\id_ed25519.pub` 내용을 서버 `~/.ssh/authorized_keys` 에 넣은 뒤 다시 한다.
  (키가 없으면 `ssh-keygen -t ed25519` 로 먼저 만든다.)
- 이 단계를 건너뛰면 다음 단계가 반드시 실패한다. 스크립트는 `BatchMode=yes` 라 비밀번호를 물을 수 없다.

### 2. 첫 실행

```powershell
powershell -ExecutionPolicy Bypass -File .\pull_backup.ps1
```

처음에는 최근 3일분(약 140MB)을 받는다. 끝나면 마지막 줄이 `OK` 여야 한다.

받은 것은 서버 경로 그대로 쌓인다 — 복구 절차(ARCHITECTURE 11.2)와 1:1 로 대응시키기 위해서다.

```
C:\tbox-backup\
  pull.log                                     실행 기록 (매일 확인할 곳)
  var\backups\tbox-v2\db\      db-YYYYMMDD-HHMM.dump.gpg + .sha256
  var\backups\tbox-v2\files\   files-YYYYMMDD.tar.gz.gpg + .sha256
  var\backups\tbox-v2\weekly\  주간 보관분
  var\backups\tbox-v2\monthly\ 월간 보관분
  var\log\tbox-v2\             app.log · audit.log · alerts.log (회전본 포함)
```

### 3. 매일 자동 실행 등록

```powershell
.\pull_backup.ps1 -Install
```

작업 스케줄러에 `tbox-v2 backup pull` 이름으로 **매일 05:00** 등록된다(서버 04:30 검증 뒤).
PC 가 꺼져 있어 시각을 놓치면 켜진 뒤 실행된다(`StartWhenAvailable`).

확인:

```powershell
Get-ScheduledTask 'tbox-v2 backup pull' | Format-List TaskName, State
```

「액세스가 거부되었습니다」가 나오면 PowerShell 을 **관리자 권한으로 실행**해 다시 한다.
그만두려면 `.\pull_backup.ps1 -Uninstall`.

### 4. 복호화 키 사본을 USB 에

```powershell
.\pull_backup.ps1 -Key E:\tbox-key\backup.key
```

`E:` 자리에 USB 드라이브 문자를 넣는다. 65바이트가 저장되면 성공이다
(키 내용은 화면에도 `pull.log` 에도 찍지 않는다. 저장 경로와 바이트 수만 알려 준다).

- **이 USB 를 백업 폴더가 있는 이 PC 에 꽂아 두지 않는다.** 키와 백업이 같은 곳에 있으면
  그 한 곳이 털릴 때 전체 데이터가 털린다 (PRD 7.8 · SECURITY R-08).
- 백업 폴더(`C:\tbox-backup`) 안 경로를 지정하면 스크립트가 거부한다(종료 코드 2).
- 이미 파일이 있으면 덮어쓰지 않고 종료 코드 2 로 끝난다.
- 이 단계는 **한 번만** 하면 된다. 매일 실행에서는 키를 건드리지 않는다.

## 매일 확인하는 법

```powershell
Get-Content C:\tbox-backup\pull.log -Tail 3
```

마지막 줄이 요약이다.

```
2026-09-16 05:00:41 OK | 항목 24개 | 142,305,280 바이트 | db=db-20260916-0300.dump.gpg | files=files-20260916.tar.gz.gpg
```

| 마지막 줄 | 뜻 | 할 일 |
|---|---|---|
| `OK` | 내려받기·sha256 대조까지 정상 | 없음 |
| `WARN` | 받기는 했는데 **오늘 날짜 산출물이 없다** | 서버 03:00/03:30 백업이 돌았는지 확인 |
| `FAIL` | 접속·전송·검증 중 실패 (종료 코드 1) | 아래 표 |

**3일 넘게 `OK` 가 없으면 원격 사본이 끊긴 것이다.** 자동 경보는 없으니 사람이 봐야 한다(아래 한계).

## 실패하면 볼 것

`pull.log` 의 `FAIL` 줄에 원인과 조치가 함께 적힌다.

| 로그에 보이는 것 | 원인 | 조치 |
|---|---|---|
| `키 등록 필요` / `Permission denied (publickey)` | 이 PC 의 공개키가 서버에 없다 | 위 1단계를 다시 한다 |
| `Host key verification failed` | 서버 호스트 키가 `known_hosts` 와 다르다 | 손으로 `ssh` 접속해 확인. 서버를 새로 깐 게 아니라면 중간자 공격을 의심한다 |
| `sudo: a password is required` / `not allowed to execute` | 서버 sudo 가 `tar` 를 비밀번호 없이 허용하지 않는다 | 서버 관리자에게 확인 |
| `Connection timed out` / `No route to host` | 서버에 닿지 않는다 | 사내망·VPN 연결, 서버 전원 확인 |
| `ssh.exe 없음` | OpenSSH 클라이언트가 꺼져 있다 | [설정] > [앱] > [선택적 기능] 에서 「OpenSSH 클라이언트」 설치 |
| `tar.exe 없음` | Windows 가 1803 미만 | Windows 를 올린다 |
| `sha256 불일치` | 받은 파일이 깨졌거나 서버 산출물이 손상됐다 | 다시 실행한다. 또 나면 서버에서 `sudo bash scripts/verify_backup.sh` 를 확인한다 |
| `받은 파일이 올바른 tar 가 아니다` | 전송 중 깨졌다 | `C:\tbox-backup\_incoming.tar` 를 메모장으로 열어 오류 문구가 들었는지 보고, 확인 뒤 지우고 다시 실행 |

## 매개변수

전부 기본값이 있다. **스크립트를 편집할 일은 없다.** 다르게 쓸 때만 붙인다.

| 매개변수 | 기본값 | 뜻 |
|---|---|---|
| `-Server` | `192.168.105.44` | 서버 주소 |
| `-User` | `changkwon` | 서버 SSH 계정 |
| `-Dest` | `C:\tbox-backup` | PC 쪽 저장 위치 |
| `-Days` | `3` | 최근 며칠분을 받을지 (서버 `tar --newer-mtime`) |
| `-KeepDays` | `45` | PC 사본 보관 일수. 넘으면 지운다 (PRD 7.8 45일 상한) |
| `-Key <경로>` | — | 복호화 키를 그 경로에 한 번 저장하고 종료. 백업은 받지 않는다 |
| `-Install` | — | 작업 스케줄러 등록(매일 05:00) 후 종료 |
| `-Uninstall` | — | 그 작업 제거 후 종료 |

`-Key`·`-Install`·`-Uninstall` 은 한 번에 하나만 쓴다.

예: 다른 드라이브에 일주일치를 받고 그 설정으로 등록하기

```powershell
.\pull_backup.ps1 -Dest D:\tbox-backup -Days 7
.\pull_backup.ps1 -Dest D:\tbox-backup -Days 7 -Install
```

(`-Install` 은 그때 준 값을 작업에 그대로 박아 둔다.)

종료 코드: `0` = OK·WARN, `1` = FAIL, `2` = `-Key` 를 저장하지 않음(이미 있거나 위치가 부적절).

## 서버에서 실제로 실행되는 명령

**읽기 전용 두 개뿐이다.** 서버 파일을 만들지도 고치지도 지우지도 않는다.

```bash
# 기본 실행
sudo -n tar -C / --newer-mtime='3 days ago' -cf - \
  var/backups/tbox-v2/db var/backups/tbox-v2/files \
  var/backups/tbox-v2/weekly var/backups/tbox-v2/monthly var/log/tbox-v2

# -Key 를 쓸 때만, 한 번
sudo -n cat /etc/tbox-v2/backup.key
```

- `rm`·`psql`·`pg_dump`·`gpg -d` 는 서버에서 실행하지 않는다.
- `files-mirror/`(평문 미러)와 백업 루트의 평문 덤프는 **일부러 뺐다.** 평문 데이터를 PC 로 옮기지 않는다.
- 서버 경보 AL-9 의 위험 명령 목록(`psql`·`pg_dump`·`rm`·`find -delete` 등)에 `tar`·`cat` 은 없다.
  매일 돌아도 헛경보가 나지 않는다.

## PC 사본으로 복구할 때

PC 사본은 **보관용**이다. 복구는 서버에서 한다 (ARCHITECTURE 11.2).

1. 필요한 `db-*.dump.gpg`(+`.sha256`)와 `files-*.tar.gz.gpg` 를 서버로 올린다.
2. USB 의 `backup.key` 를 서버 `/etc/tbox-v2/backup.key` 로 되돌린다(0600 root).
3. 서버에서 `sha256sum -c` → `scripts/restore.sh --db <파일>` / `--files <파일>` 순서로 진행한다.
4. 복구 뒤 `db/grants.sql` 재실행과 `scripts/consistency_check.php --strict` 를 잊지 않는다.

## 한계 — 알고 쓴다

| 한계 | 뜻 |
|---|---|
| **자동 경보가 없다** | 실패해도 메일·메신저가 오지 않는다. `pull.log` 마지막 줄을 사람이 본다. 3일 이상 `OK` 가 없으면 끊긴 것이다 |
| **PC 가 켜져 있어야 한다** | 꺼져 있으면 켜진 뒤 실행된다(`StartWhenAvailable`). 등록 계정으로 로그인해 있어야 돈다. 장기 휴가 때는 사본이 밀린다 |
| **서버 sudo NOPASSWD 에 기댄다** | 서버가 `MaxAuthTries`·`ufw`·sudo 정책을 조이면(SECURITY 8.4-3) 이 스크립트도 같이 막힐 수 있다. 서버 설정을 바꾼 날은 1단계를 다시 확인한다 |
| **복호화 시험은 하지 않는다** | PC 에 gpg 가 없을 수 있어 sha256 대조까지만 한다. 실제로 풀리는지(`gpg -d` → `pg_restore -l`)는 서버 `verify_backup.sh` 와 **분기 복구 리허설**(PRD 7.8)에서 확인한다 |
| **PC 는 임시 원격 1개다** | 사내 NAS·클라우드(Q-4)는 아직 미결이다. PC 한 대가 도난·랜섬웨어를 당하면 이 사본도 없다. 잔여 위험 R-88 |
| **PC 에 암호화 전체 데이터가 쌓인다** | 키가 없으면 열리지 않지만, 키 USB 를 같은 PC 에 꽂아 두면 그 보호가 사라진다. 디스크 암호화(BitLocker)를 권한다 |

## 검증 상태

`T-67` (a)~(e) 는 **미검증**이다. 이 스크립트를 만든 서버에는 PowerShell 이 없어 실행할 수 없었다.
**사용자 PC 에서** 다음을 직접 확인해야 완료다.

| | 확인할 것 |
|---|---|
| (a) | 기본 실행 → `db-<오늘>-0300.dump.gpg`·`files-<오늘>.tar.gz.gpg` 생성 + sha256 대조 OK, `pull.log` 마지막 줄 `OK` |
| (b) | `.sha256` 한 글자를 바꾸고 재실행 → `FAIL`, 종료 코드 1 (`$LASTEXITCODE`) |
| (c) | `-Key` → 65바이트 저장, 백업 폴더 안에는 키 파일 없음 |
| (d) | `-Install` → `Get-ScheduledTask 'tbox-v2 backup pull'` 존재, 05:00 · StartWhenAvailable |
| (e) | 이름 날짜가 46일 전인 가짜 파일을 `var\backups\tbox-v2\db` 에 두고 실행 → 삭제됨 |
