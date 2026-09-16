# t-box v2 복구 키트 (외장하드)

이 폴더는 **서버도 없고 인터넷도 없는 상황**을 위해 만들었다.
외장하드를 꽂을 수 있는 Windows PC 한 대만 있으면 암호화 백업을 평문으로 되돌릴 수 있다.

만든 날 2026-09-16 · 근거 F-55 / T-67 · `deploy/pc/README.md`

---

## 급할 때 — 이 두 줄

```powershell
F:\tbox-recovery\recover.ps1                              # 무엇을 풀 수 있는지 본다
F:\tbox-recovery\recover.ps1 -Latest -Out D:\복구          # 최근 db·files 를 푼다
```

`F:` 는 이 외장하드의 드라이브 문자다. 다른 문자로 잡히면 그 문자로 바꿔 쓴다
(스크립트는 자기가 놓인 자리를 기준으로 gpg 와 키를 찾으므로 문자가 바뀌어도 동작한다).

실행이 막히면 앞에 `powershell -ExecutionPolicy Bypass -File` 을 붙인다.

---

## 여기 있는 것

| | 무엇 |
|---|---|
| `..\tbox-key\backup.key` | **복호화 키.** 65바이트. 이것을 잃으면 백업 전체가 영구히 무용지물이다 |
| `gpg\` | GnuPG 2.4.8 (Windows). 설치 없이 그 자리에서 실행된다 |
| `recover.ps1` | 무결성(sha256) 대조 → 복호화 → 선두 바이트 확인까지 한 번에 |
| `RECOVER.md` | 이 문서 |

`gpg` 는 gnupg.org 공식 배포본이며, 내려받은 뒤 OpenPGP 서명을 검증했다
(Werner Koch dist signing 2020, 지문 `6DAA 6E64 A76D 2840 571B 4902 5288 97B8 2640 3ADA`).

---

## 왜 백업은 여기 없나

**일부러 그렇게 했다.** 암호화 백업은 `C:\tbox-backup` 에 있고(매일 05:00 자동), 키는 이 외장하드에 있다.
둘을 갈라 놓았기 때문에 한쪽만 도둑맞아도 데이터는 열리지 않는다.

이 드라이브에 백업까지 복사해 두면 **키와 자물쇠를 한 봉투에 넣는 것**이 된다. 하지 마라.
백업을 한 벌 더 두고 싶으면 키가 없는 **다른** 매체에 둔다.

같은 이유로, 이 외장하드는 PC 에 상시 꽂아 두지 말고 빼서 따로 보관한다.

---

## 전체 복구 순서

### 1. 푼다

```powershell
F:\tbox-recovery\recover.ps1 -Latest -Out D:\복구
```

`db-YYYYMMDD-HHMM.dump` 와 `files-YYYYMMDD.tar.gz` 가 나온다.
`sha256 일치` 가 보이면 사본이 온전한 것이다.

백업이 이 PC 에 없다면(PC 도 잃은 경우) 다른 곳에서 복사해 온 폴더를 `-Backup` 으로 준다.

```powershell
F:\tbox-recovery\recover.ps1 -Backup E:\어딘가\tbox-backup -Latest -Out D:\복구
```

### 2. 서버에 되돌린다

새 서버(또는 되살린 서버)에서:

```bash
# 데이터베이스
pg_restore -d tbox_re db-YYYYMMDD-HHMM.dump

# 업로드 파일
tar -xzf files-YYYYMMDD.tar.gz -C /var/www/tbox-v2/
```

`pg_restore` 전에 빈 데이터베이스가 필요하면 `createdb tbox_re` 를 먼저 한다.

### 3. 치운다

푼 평문에는 실제 업무 데이터가 들어 있다. 복구가 끝나면 지운다.

---

## 알아 둘 것

- **키가 전부다.** `backup.key` 없이는 어떤 방법으로도 백업을 열 수 없다.
  이 외장하드가 유일한 키 사본이라면 한 벌 더 만들어 다른 장소에 두는 것을 고려하라
  (단, 백업과 같은 매체에는 두지 않는다).
- **백업은 45일까지만 남는다.** `C:\tbox-backup` 은 45일 지난 사본을 자동으로 지운다.
  더 오래 보관해야 할 것이 있으면 따로 챙긴다.
- **사본은 받은 날 이후 다시 검증되지 않는다.** `recover.ps1` 이 풀기 전에 sha256 을 대조하니,
  가끔 `-Latest` 없이 한 번 돌려 목록만이라도 확인해 두면 좋다.
- **이 드라이브가 죽으면 키가 사라진다.** 외장하드도 고장 난다. 연 1회 정도 다른 매체로 옮겨 적는다.
