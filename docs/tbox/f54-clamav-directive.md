# Opus 구현 지시서 — F-54 악성코드 검사 파이프라인 (pending → clamscan 배치 → clean/infected · 격리 · 403 · AL-19)

> Fable 작성 2026-09-16. CLAUDE.md 2장 형식. **ClamAV 는 앱 밖 배치(`scripts/`)만 부른다.** 앱 코드에는 exec·소켓이 들어가지 않는다.
> 서버에는 이미 `clamav 1.5.3`·`clamav-freshclam`(enabled·active, 시그니처 main 63/daily 28124 수신 완료)이 설치돼 있고 `/var/lib/tbox-v2/quarantine`(0750 tbox-php)이 있다. `clamav-daemon` 은 없다(설치하지 않는다). 실측: `clamscan` 1회 = 약 22초, 최대 RSS 약 991MB.

```
목표      : SCAN_ENABLED=1 이면 새 파일은 'pending' 으로 저장되고, 1분 cron 배치가 clamscan 으로 검사해 'clean'/'infected' 로 바꾼다.
            'clean' 이 아니면 다운로드·미리보기·공유 링크가 403 이고, 'infected' 는 격리 폴더로 옮겨지며 감사·경보(AL-19)가 남는다.
요구사항  : PRD F-54 · 7.6 S-06 표 · 8장 download 행 · 7.11 AL-19 · 12.2 T-66 ; ARCHITECTURE 5.28 · 5.3 [4] · 11.1 · 11.4 · 7.4 · 13장 ; SECURITY R-02 · R-86 · R-87 — 읽고 시작.
수정 파일  : src/Upload.php(initialScanStatus 한 함수만) · src/handlers/_upload_common.php · src/handlers/files_fetch.php · src/handlers/purchase_recon_upload.php · src/purchase/Importer.php(INSERT 한 줄씩)
            · src/handlers/files_download.php · files_preview.php · files_preview_raw.php · share_download.php(상태 검사 블록) · src/handlers/files_list.php(scan_status 투영)
            · src/Audit.php(문장 틀만) · public/assets/app.js · public/assets/app.css(배지)
            · scripts/scan_pending.php(새) · scripts/alerts.php(AL-19) · scripts/check_baseline.sh(시그니처 나이) · deploy/cron.d/tbox-v2 · deploy/install.sh(격리 디렉터리·cron) · .env.example(SCAN_ENABLED)
            · README.md(악성코드 검사 절 한 단락) · tests/f54.sh(새) — 이 스물둘만.
금지 패턴  : 앱(src/·public/)에 exec/proc_open/shell_exec/system/소켓, SQL 문자열 결합, innerHTML, 역할 판단을 Authz 밖에서,
            사용자 입력이 든 파일 경로(배치도 `Storage::pathFor` 만), 격리 파일 삭제, `deleted_at` 조작, `.env` 읽기(존재는 grep -c), DB 스키마 변경
완료 기준  : PRD 12.2 T-66 (a)~(g) 전부 실행. tests/f54.sh 가 그것을 한다.
보고 형식  : 변경 파일 / 실행 명령·출력 원문 / 못 한 것과 이유
```

## 손대지 않는다
`src/Authz.php`·`src/Auth.php`·`src/Storage.php`(필요한 `pathFor`·`assertExists` 는 이미 있다)·`db/*`·`public/index.php`·nginx conf·`Upload::validatePath` 의 F-53 블록·문서(PRD/ARCH/SECURITY/STAGES/DEPENDENCIES). **`.env` 는 Fable 이 배치 코드가 준비된 뒤 `SCAN_ENABLED=1` 로 켠다** — 시험 (a)(b) 는 `.env` 를 바꾸지 말고 아래 5) 의 방법으로 켜진 상태를 만든다.
시험은 `qa_employee01`·`qa_admin01` 만, 모든 요청에 `-A "tbox-review/$(date +%F)"`. 운영 데이터 불변. 격리로 옮긴 EICAR 시험 파일은 **그대로 둔다**(증거, PRD T-66 (g)).

## 1) 저장 시 초기 상태 — `Upload::initialScanStatus(): string`
```php
/** 새 파일 행의 scan_status — SCAN_ENABLED=1 이면 'pending'(배치가 검사), 아니면 'clean'(지금까지의 동작). PRD F-54 ① */
public static function initialScanStatus(): string
{
    return Env::getOrEmpty('SCAN_ENABLED') === '1' ? 'pending' : 'clean';
}
```
INSERT 네 곳(`_upload_common.php` 268행·`files_fetch.php` 384행·`purchase_recon_upload.php` 184행·`Importer.php` 2433행)의 열 목록에 `scan_status` 를, VALUES 에 `:scan_status` 를 더하고 바인딩에 `':scan_status' => Upload::initialScanStatus()`. 그 밖은 손대지 않는다.

## 2) 읽기 창구 네 곳 — `[권한 ✔]` **뒤**, 저장소 접근 **앞** (ARCH 5.3 [4])
`files_download.php`(78행 403 뒤·`assertExists` 앞)·`files_preview.php`·`files_preview_raw.php`(같은 자리)·`share_download.php`(링크 검증 뒤·스트림 앞):
```php
// F-54 (ARCH 5.28) — 검사 전·격리 파일은 아무도 받지 못한다. 권한 판정(IDOR 403) 뒤에 두어 403 의 뜻이 섞이지 않게 한다.
$scan = (string) ($file['scan_status'] ?? 'clean');
if ($scan !== 'clean') {
    $reason = $scan === 'infected' ? 'scan_infected' : 'scan_pending';
    Audit::deny('file.download', $reason, [ /* 위 deny 와 같은 target 정보 + detail $auditWhat */ ]);
    Http::error(403, $scan === 'infected' ? Http::MSG_SCAN_INFECTED : Http::MSG_SCAN_PENDING);
}
```
- `Http.php` 는 수정 파일이 아니다 → 문구 상수를 못 넣는다면 **각 핸들러 상단에 같은 문자열 상수 두 개**를 둔다: 「검사 중입니다. 잠시 뒤 다시 시도하세요」 / 「악성코드가 발견되어 격리된 파일입니다」. (Fable 메모: Http.php 에 넣는 편이 맞으나 보안 핵심 파일이라 이번 범위 밖. 보고에 「Http.php 로 옮길 것」이라 적는다.)
- 각 핸들러의 SELECT 에 `f.scan_status` 가 없으면 열을 더한다. 미리보기 두 창구의 action 은 `file.preview`, 공유는 `share.download`(기존 action 이름 확인) — 문장 틀은 4) 에.
- `share_download.php` 는 `decideRead` 를 하지 않는 창구다 — 상태 검사는 **링크 검증 통과 뒤**에 둔다(링크가 유효해도 격리 파일은 못 받는다).

## 3) 목록·화면
- `files_list.php`: SELECT 에 `f.scan_status`, 투영에 `'scan_status' => (string) $row['scan_status']`(웹 응답만; F-50 토큰 간결 배열은 **불변** — API.md 안정성). 
- `app.js` `addNameCell` 옆: `scan_status === 'pending'` → 회색 배지 「검사 중」, `'infected'` → 빨간 배지 「격리됨」(`badge badge--scan-pending` / `badge--scan-infected`, 기존 `badge--sensitive` 와 같은 만들기 방식 — textContent 만). 두 상태에서는 행의 다운로드·미리보기 단추를 `disabled` 로 두고 `title` 에 같은 문구. 값은 서버가 준 문자열만 보고 그 밖의 값은 배지 없음.
- `app.css`: 두 배지 색은 토큰만(`--muted`/`--danger` 계열).

## 4) `src/Audit.php` 문장 틀 (다른 줄 불변)
- `file.download|deny|scan_pending` → `{actor} 이(가) 아직 검사 중인 파일 「{target}」 을(를) 내려받으려 해서 막았습니다`
- `file.download|deny|scan_infected` → `{actor} 이(가) 악성코드로 격리된 파일 「{target}」 을(를) 내려받으려 해서 막았습니다`
- `file.preview|deny|scan_pending` · `scan_infected` (「미리 보려」), `share.download|deny|scan_pending` · `scan_infected` (「공유 링크로 … 내려받으려」 — action 이름은 share_download.php 의 실제 값)
- `file.scan|allow` → `배치가 파일 「{target}」 을(를) 검사했습니다 — 이상 없음`
- `file.scan|deny|infected` → `배치가 파일 「{target}」 에서 악성코드를 발견해 격리했습니다`
- `file.scan|error|scan_failed` → `파일 「{target}」 검사 중 오류가 나서 다음 실행에 다시 시도합니다`
- `alert.raised` 는 이미 있다.
보고에 문장 원문을 적는다(Fable 이 ARCH 7.4 로 옮긴다).

## 5) `scripts/scan_pending.php` — `purge_trash.php` 와 같은 골격(`cli_bootstrap`·`cli_flag`·`Audit`)
```
사용법: sudo -u tbox-php php scripts/scan_pending.php [--dry-run] [--limit 200] [--clamscan <경로>] [--force-enabled]
```
- `--force-enabled`: `.env` 의 `SCAN_ENABLED` 와 무관하게 돈다(시험용). 그 밖에는 `Env::getOrEmpty('SCAN_ENABLED') !== '1'` 이면 「꺼져 있음」 한 줄 찍고 종료 0.
- [1] 잠금: `/var/lib/tbox-v2/scan.lock` 을 `fopen('c')` + `flock(LOCK_EX|LOCK_NB)`; 못 잡으면 「이미 실행 중」 종료 0.
- [2] `SELECT id, public_id, stored_name, original_name, access_level, sha256, size_bytes FROM files WHERE scan_status='pending' AND purged_at IS NULL ORDER BY created_at LIMIT :limit`. 0건 → 「대기 없음」 종료 0 (**clamscan 실행 없음**).
- [3] 경로는 `Storage::pathFor($stored_name)` 만(존재하지 않으면 그 건은 `scan_failed` 로 error 기록 후 다음). `proc_open` 으로
  `clamscan --no-summary --infected --stdout --max-filesize=100M --max-scansize=1G --max-recursion=8 -- <경로…>` (인자 배열, 셸 문자열 결합 금지). stdout 을 줄 단위로 `<경로>: <시그니처> FOUND` 파싱 → 경로→시그니처 맵. 종료 코드 0/1 이면 정상, 2 이면 **전체를 `scan_failed`** 로 기록하고 상태는 그대로(다음 실행 재시도). `--dry-run` 이면 [4] 를 건너뛰고 결과만 찍는다.
- [4] 건마다 트랜잭션(`purge_trash` 순서 원칙 — **바이트 이동 먼저, 그 다음 UPDATE+감사**):
  - clean: `UPDATE files SET scan_status='clean' WHERE id=:id AND scan_status='pending'` + `Audit::allow('file.scan', target…, detail {sha256,size_bytes,signatures: 'main 63/daily N'(sigtool 없이 `clamscan --version` 문자열)})`.
  - infected: `rename(storage 경로, /var/lib/tbox-v2/quarantine/<stored_name>)`(같은 파일시스템; 실패면 `scan_failed`) → `UPDATE … scan_status='infected'` + `Audit::deny('file.scan','infected', detail {signature, sha256, size_bytes, uploader_id})`. `deleted_at` 은 건드리지 않는다.
  - 오류: `Audit::error('file.scan','scan_failed', detail {reason 한 낱말})`, 상태 유지.
- 출력은 건수 요약만(파일 이름·경로·시그니처는 감사 로그에). 비밀 없음.
- 머리말 주석: 왜 앱 밖인가(ARCH 5.28), 왜 `clamd` 가 아닌가(메모리), 잠금·재시도·격리 원칙.

## 6) 경보·기준선·배포
- `scripts/alerts.php` AL-19: AL-18 과 **같은 형태** — `action='file.scan' AND result='deny' AND reason='infected' AND happened_at > now() - interval '5 minutes'` 를 `target_name` 별로 → `$raise('AL-19', target_name, {rule_text, signature(detail->>'signature'), uploader(detail->>'uploader_id'), last_at})`. 머리말 주석의 규칙 목록에 AL-19 추가.
- `scripts/check_baseline.sh`: 「악성코드 검사」 절 추가 — `clamscan` 있음 · `clamav-freshclam` active · `/var/lib/clamav/daily.c*d` mtime 24h 이내(넘으면 `warn`) · `clamav-daemon` 미설치(`dpkg -s` 실패가 정상) · `/var/lib/tbox-v2/quarantine` 0750 tbox-php · cron 행에 `scan_pending.php` 있음. 형식은 기존 `ok/warn/todo`.
- `deploy/cron.d/tbox-v2`: `*  *  *  *  *  tbox-php  php /var/www/tbox-v2/scripts/scan_pending.php` (실행 계정 **tbox-php** — root 로 악성 파일을 파싱하지 않는다). `deploy/install.sh`: `install -d -o "$APP_USER" -g "$APP_USER" -m 0750 /var/lib/tbox-v2/quarantine` 한 줄(152행 옆) + cron 파일은 기존 복사 로직 그대로. `.env.example`: `SCAN_ENABLED=` + 주석 두 줄.
- **실제 서버의 `/etc/cron.d/tbox-v2` 는 손대지 않는다** — Fable 이 검토 뒤 복사한다. 시험은 배치를 직접 부른다.
- README: 「악성코드 검사」 단락 — 업로드 직후 1~2분 「검사 중」, 격리되면 관리자에게 경보, ClamAV 는 알려진 시그니처만 잡으므로 **PC 백신을 대체하지 않는다**.

## 7) `tests/f54.sh` — PRD T-66
`tests/f53.sh` 골격 복사. 켜진 상태 만들기: `.env` 를 바꾸지 않고, 시험 중 올린 파일의 행을 **`psql` 로 `scan_status='pending'` 으로 바꾸는 것도 금지**(운영 데이터 원칙) → 대신 **(a)(b) 는 `SCAN_ENABLED=1` 이 실제로 켜진 뒤에만 의미가 있으므로**, 스크립트는 시작 시 `grep -c '^SCAN_ENABLED=1' /etc/tbox-v2/.env`(sudo, 값 출력 금지)로 상태를 읽고: 꺼져 있으면 (a)(b) 를 「Fable 이 켠 뒤 재실행」으로 SKIP 표시하고 (c)(d)(e)(f)(g) 만 돌린다. (Fable 이 켠 뒤 다시 돌린다.)
- **(a)** 정상 txt 업로드 → 201 · `psqlq scan_status` = pending · `GET /api/files/{id}/download` 403 + 본문 「검사 중」 + 감사 `file.download deny scan_pending` · 목록 `scan_status:"pending"` → `sudo -n -u tbox-php php scripts/scan_pending.php` → 출력 요약 · DB clean · 감사 `file.scan allow` · 다운로드 200.
- **(b)** EICAR: `printf 'X5O!P%%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'` 를 `eicar-test.txt` 로 업로드 → 201·pending → 배치 → DB infected · `sudo -n ls /var/lib/tbox-v2/quarantine/<stored_name>` 있음(0640) · storage 경로 없음 · 감사 `file.scan deny infected` detail.signature 에 `Eicar` 포함 · 다운로드 403 「격리」 · 미리보기 403 · 목록 `infected` · `sudo -n php scripts/alerts.php --rule AL-19 --dry-run --state <임시파일>` 출력에 AL-19 발동.
- **(c)** 대기 0건에서 배치 `time` < 2초·출력 「대기 없음」 · 잠금: 배경에서 `flock` 으로 잠근 채 배치 실행 → 「이미 실행 중」 즉시 종료.
- **(d)** `Upload::initialScanStatus()` 를 `php -r` 로 `SCAN_ENABLED` 미설정/`0`/`1` 세 경우(환경변수로 Env 를 흉내 낼 수 없으면 `Env` 의 로드 방식을 읽고 임시 `.env` 파일 경로로 `Env::load` 호출) → clean/clean/pending.
- **(e)** 서버 층: `dpkg -l clamav clamav-freshclam` ii · `dpkg -s clamav-daemon` 실패 · `systemctl is-active clamav-freshclam` · `daily.c*d` mtime ≤ 24h · quarantine 0750 tbox-php · `deploy/cron.d/tbox-v2` 에 scan_pending 행 · `ss -tlnp` 에 clam 리스너 없음(T-18) · `check_baseline.sh` 새 절 ok.
- **(f)** 배치 1회(대기 1건)의 `/usr/bin/time -v` 최대 RSS·시간 기록(기대 < 1.5GB, < 60초).
- **(g)** 린트 0 · `php -l` 전부 · `bash -n` · 시험 파일 휴지통(격리 파일 제외).

## 8) 완료 전 자기 점검
ARCHITECTURE 13장. `git diff` 가 스물둘 밖으로 번지지 않았는지, 앱 코드(src/·public/)에 exec·proc_open·소켓이 0인지(`grep -rn "proc_open\|exec(" src public` = 0), 비밀 값 출력 0. 커밋하지 않는다.
