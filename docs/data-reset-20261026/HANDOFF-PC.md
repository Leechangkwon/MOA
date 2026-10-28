# [PC 쪽 세션에 줄 프롬프트] 모아 서버 통째 사본을 외장하드(F:)의 WSL 로 가져와 켜기

> 이 블록 전체를 사용자 PC(Windows, PowerShell)에서 여는 Claude 세션에 붙여 넣는다. 작성 2026-10-26 18:4x.

## 목표

모아 서버(Ubuntu 22.04.5, 192.168.105.44)의 통째 사본 — 이미 받아 둔 잠긴 파일 — 을 풀어 **외장하드 F: 에 WSL 배포판 `Qaredi`** 로 가져오고, DB 를 되살려 **브라우저에서 모아 첫 화면이 뜨는 것**까지 확인한다. 이 사본은 서버가 내려갈 때 쓸 **임시 대기용**이다. 평소에는 서버를 그대로 쓴다.

## 지켜야 할 것

- 사용자에게 보이는 답은 **반드시 한국어로만**. 답마다 **남은 단계를 순서대로 다시** 적고 지금 위치와 실행 장소([PowerShell] / [Ubuntu] / [Qaredi])를 표시한다. 사용자가 바꿔야 하는 경로 같은 값은 없앤다.
- **서버의 운영 자료는 건드리지 않는다.** 서버 쪽 지우기는 서버 세션이 따로 한다. 서버에는 ssh 로 **읽기(scp 로 파일 받기)와 `stream-rootfs.sh` 실행만** 한다. 다른 명령을 서버에서 실행하지 않는다.
- `stream-rootfs.sh` 의 출력은 **반드시 PC 쪽 파일로 받는다**(`ssh … > /mnt/f/...`). 서버 안에서 파일로 받으면 서버 디스크가 찬다.
- PowerShell 의 `>` 는 바이너리를 망가뜨린다. 받기·풀기는 **PC Ubuntu(WSL)** 에서 한다.

## PC 상태 (확인된 것)

- Windows 사용자 폴더 `C:\Users\PL-PD-CHANGGWON-LEE`. WSL 배포판 `Ubuntu`(WSL 2, 사용자 `lck`)가 있다 — **받고 푸는 도구로만** 쓰고 바꾸지 않는다.
- F: = NTFS, 899GB 중 약 890GB 비어 있었다.
- 서버 접속: 비밀번호 로그인 막힘, **키 로그인만**. Windows 키 `C:\Users\PL-PD-CHANGGWON-LEE\.ssh\id_ed25519` 를 WSL `~/.ssh/id_ed25519`(600)로 복사해 두었고 `ssh changkwon@192.168.105.44 'echo 접속 OK'` 성공. 서버 호스트 키 지문 `SHA256:RcbL0uwjVXEjcI3Qpl6P8sY3pVTOxnp5T6aTylciAeU`(서버에서 확인함).
- 받은 파일: `F:\qaredi-backups\server-clone-20261026\qaredi-server-rootfs.tar.zst.gpg` — **12,519,492,485 B · sha256 `86b34219b14aa42068ac5a6f804def1b77e2541a263ff4a0cc265653677c6625`, 서버 기록과 일치**(18:24 받기 끝, 항목 1,110,271, tar=1 은 운영 중 바뀐 파일이라 정상).
- 따로 받은 전체 백업 `F:\qaredi-backups\offsite-backups-20261026-predelete\`(25개 271,223,847 B, 대조 끝).
- 이 사본에 **빠진 것**(일부러): `/proc` `/sys` `/dev` `/run` `/tmp` 내용(빈 폴더는 있음) · 스왑 · journal · `/boot` · 운영 DB 파일(같은 순간 덤프로 대신) · `/var/backups/tbox-v2` · 로그인 세션 · `/home/eric`(관리 쪽 계정) · 회사 보안 감시 자격(wazuh `client.keys`·`authd.pass`, alloy `nexus-token`).

## 할 일 — 순서

### A. 풀기 [Ubuntu] (`wsl` 로 들어간 PC Ubuntu)
1. 절차서 최신판 받기(18:3x 에 DB 명령을 고쳤다):
   `scp changkwon@192.168.105.44:/home/changkwon/qaredi-clone/RESTORE-ROOTFS.md /mnt/f/qaredi-backups/`
2. 키 받기: `scp changkwon@192.168.105.44:/home/changkwon/offsite-key-20261026/backup.key ~/backup.key && chmod 600 ~/backup.key`
3. 도구: `sudo apt install -y zstd gnupg`(이미 했으면 건너뜀 — PC Ubuntu 비밀번호를 묻는다)
4. 풀기(약 28GB, 15~30분): `cd /mnt/f/qaredi-backups/server-clone-20261026 && gpg --batch --decrypt --passphrase-file ~/backup.key qaredi-server-rootfs.tar.zst.gpg | zstd -d -o qaredi-server-rootfs.tar`
5. `ls -l` 로 크기 확인(약 28GB 안팎) 뒤 `exit`

### B. 가져오기 [PowerShell]
1. `wsl --shutdown`(받기·풀기 동안 WSL 이 잡은 파일 캐시 메모리를 돌려받는다)
2. `mkdir F:\wsl -Force; wsl --import Qaredi F:\wsl\Qaredi F:\qaredi-backups\server-clone-20261026\qaredi-server-rootfs.tar --version 2`(10~20분)
3. `wsl -l -v` 에 `Qaredi` 확인

### C. 처음 켜기 전 서버 전용 설정 끄기
1. [PowerShell] `wsl -d Qaredi -u root`
2. [Qaredi root] `printf '[boot]\nsystemd=true\n[user]\ndefault=changkwon\n[automount]\nmountFsTab=false\n[network]\nhostname=qaredi-pc\ngenerateHosts=true\ngenerateResolvConf=true\n' > /etc/wsl.conf`
3. [Qaredi root] 서버 고정 IP 치우기: `mkdir -p /root/server-only && mv /etc/netplan/00-installer-config.yaml /root/server-only/`
4. [Qaredi root] 회사 보안 감시·VM 도구·서버 전용 서비스 끄기:
   `for u in wazuh-agent alloy open-vm-tools vgauth multipathd ModemManager snapd fwupd unattended-upgrades systemd-networkd; do ln -sf /dev/null /etc/systemd/system/$u.service; done; for s in snapd systemd-networkd; do ln -sf /dev/null /etc/systemd/system/$s.socket; done`
5. [Qaredi root] 앱 정기 작업 잠시 끄기: `mv /etc/cron.d/tbox-v2 /etc/cron.d/tbox-v2.off && exit`
6. [PowerShell] `wsl --terminate Qaredi; wsl -d Qaredi`(이제 `changkwon@qaredi-pc`)

이유: 서버 고정 IP 를 WSL 에 붙이면 사본의 네트워크가 끊긴다. wazuh·alloy 는 회사 보안 관제·모니터링에 같은 서버 이름으로 신고해 「서버가 두 대」가 된다. VMware 도구·multipath·모뎀·펌웨어·snap 은 WSL 에 없는 장치라 오류만 낸다. 자동 업데이트는 사본만 판이 달라지게 한다. 정기 작업은 확인 전에 실제 사용자에게 푸시를 보내거나 외부 AI 를 겹쳐 부를 수 있다. 모두 **끄기만** 했다(되돌리기 가능).

### D. DB 다시 만들기 [Qaredi]
1. `R=/home/changkwon/qaredi-clone/run-20261026-174530; cd /`
2. `sudo -u postgres /usr/lib/postgresql/14/bin/initdb -D /var/lib/postgresql/14/main --encoding=UTF8 --locale=en_US.UTF-8`
3. `sudo systemctl start postgresql@14-main`
4. `sudo -u postgres psql -X < $R/db/globals.sql`(「role "postgres" already exists」 한 줄은 정상)
5. `sudo -u postgres createdb -O tbox_owner tbox_re && sudo -u postgres pg_restore -d tbox_re < $R/db/tbox_re.dump`
6. 행 수 대조(아무것도 안 나오면 서버와 123표 같음):
   `sudo -u postgres psql -X -At -d tbox_re -c "select format('select %L||''|''||count(*) from %I.%I;', n.nspname||'.'||c.relname, n.nspname, c.relname) from pg_class c join pg_namespace n on n.oid=c.relnamespace where c.relkind in ('r','p') and n.nspname not in ('pg_catalog','information_schema') order by 1" | sudo -u postgres psql -X -At -d tbox_re | diff - $R/db/tbox_re.rowcounts.txt`

주의: 파일을 `-f` 로 넘기면 postgres 계정이 홈 폴더(750)를 못 읽어 실패한다 — 위처럼 `<` 로 넘긴다. `sudo` 는 사본 안 changkwon 이 비밀번호 없이 쓸 수 있다(서버 설정 그대로).

### E. 화면 확인
1. [Qaredi] `sudo systemctl restart php8.1-fpm nginx && curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8082/` — 200 이나 30x
2. [Windows 브라우저] `http://127.0.0.1:8082/` — 모아 첫 화면. 로그인 쿠키가 `__Host-`·Secure 라 Chrome/Edge 의 `127.0.0.1`·`localhost` 에서는 되지만, 안 되면 결과를 보여 달라고 한다(서버 `.env` 의 `APP_URL` 은 https 서버 주소다).
3. 화면이 뜨면 **사용자에게 「PC 사본 켜짐 확인」을 서버 세션에 알리라고** 안내한다. 서버의 운영 자료 지우기는 그 확인 뒤에 서버 세션이 한다.

### F. 확인 뒤 정리 — 사용자에게 고르게 한다
- 풀어 둔 `qaredi-server-rootfs.tar`(약 28GB)는 지운다. 잠긴 `.tar.zst.gpg` 는 보관한다(처리방침 백업 최대 45일).
- **가져온 `Qaredi` 디스크(`F:\wsl\Qaredi\ext4.vhdx`)는 잠겨 있지 않다**. 민감 자료가 다 들어 있으니 ① 확인만 하고 `wsl --unregister Qaredi` 로 지우거나 ② F: 에 BitLocker 를 건다.
- 이 사본은 **서버에서 자료를 지우기 전** 상태다. 서버 세션이 지우기를 마치면, 대기용으로 **한 번 더 통째로 받기**를 권한다(같은 명령: `mkdir -p /mnt/f/qaredi-backups/server-clone-<날짜> && cd $_ && ssh changkwon@192.168.105.44 'sudo -n bash /home/changkwon/qaredi-clone/stream-rootfs.sh' > qaredi-server-rootfs.tar.zst.gpg`, 약 40분, 끝나면 서버의 `~/qaredi-clone/run-<시각>/stream.bytes`·`stream.sha256` 과 대조). 이때는 사본 안 덤프 위치 `run-<시각>` 이 바뀌니 D-1 경로를 맞춘다.

## 메모리가 꽉 차 보일 때
WSL 이 받기·풀기 동안 파일 캐시로 메모리를 잡고 Windows 에 바로 돌려주지 않아서다(오류 아님). `wsl --shutdown` 으로 돌려받는다. 상한을 두려면 `C:\Users\PL-PD-CHANGGWON-LEE\.wslconfig` 에 `[wsl2]` `memory=6GB`, `[experimental]` `autoMemoryReclaim=gradual`. 서버는 메모리 3.8GB · CPU 2개로 돈다 — 사본도 4~6GB 면 충분하다.
