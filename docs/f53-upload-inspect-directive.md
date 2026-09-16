# Opus 구현 지시서 — F-53 업로드 내용 검사 강화 (zip 내부 · 매크로 · PDF 능동 내용 · HWP 서명)

> Fable 작성 2026-09-16. CLAUDE.md 2장 형식. **파일을 풀지 않는다(추출 0).** 외부 라이브러리·확장·임시 파일·외부 호출 없이 이름·크기 표·바이트만 본다.

```
목표      : Upload::validatePath 가 확장자·MIME 짝 검사 뒤에 네 가지 「속」 검사를 더해 R-02 의 절반을 앱 안에서 막는다.
요구사항  : PRD F-53 · 7.6 S-06 표 · 12.2 T-65 · S-06 ; ARCHITECTURE 5.27 · 5.2 · 7.4 · 13장 ; SECURITY R-02 · R-86 — 읽고 시작.
수정 파일  : src/Upload.php · src/Audit.php(SUMMARY_TEMPLATES 문장 틀 추가만) · tests/f53.sh(새) — 이 셋만.
금지 패턴  : 파일 추출(extractTo·getFromName 으로 항목 내용 읽기 포함 — 예외: 아래 ④ HWP 는 zip 이 아니라 OLE 바이트 검색)
            , 임시 파일 생성, 외부 호출(exec/proc_open/curl/소켓), SQL, innerHTML, 역할 판단,
            ALLOWED_EXT·BLOCKED_EXT·MIME_BY_EXT·OOXML_MARKERS 값 변경, 예외 문구에 파일 내용·경로 넣기
완료 기준  : PRD 12.2 T-65 (a)~(g) 전부 실행. tests/f53.sh 가 그것을 한다.
보고 형식  : 변경 파일 / 실행 명령·출력 원문 / 못 한 것과 이유
```

## 손대지 않는다
핸들러 전부(`_upload_common.php`·`files_fetch.php`·`api_upload.php`·purchase 업로드) — `validatePath` 를 이미 타므로 자동 적용. `Storage.php`·`Authz`·`Auth`·`db/*`·`deploy/*`·`.env`·문서(PRD/ARCH/SECURITY/STAGES — ARCH 7.4 표는 Fable 이 보고서의 문장으로 채운다).
시험은 `qa_employee01` 만, 모든 요청에 `-A "tbox-review/$(date +%F)"`. 시험 파일은 스크립트가 만들고 끝에 **휴지통으로**(psql 삭제 금지, R-73). 운영 데이터·다른 계정 불변.

## 1) `src/Upload.php` — `validatePath` 에 한 줄, 새 private 함수 넷
```php
$ext  = self::checkExtension($originalName);
$mime = self::checkMime($tmpPath, $ext);
self::inspectContent($tmpPath, $ext, (int) $size);   // F-53 — 속 검사 (ARCH 5.27). 실패는 사유 코드 예외
```
`inspectContent` 는 확장자로 갈래를 탄다: `zip`·`hwpx`·`docx`·`xlsx`·`pptx` → `inspectZip`; `pdf` → `inspectPdf`; `hwp` 이고 `$mime === 'application/cdfv2'`(또는 MIME_BY_EXT 의 hwp 칸에 있는 OLE 계열 값) → `inspectHwpOle`. 그 밖(txt·csv·md·png·jpg·jpeg·gif)은 아무것도 하지 않는다.

### ① `inspectZip(string $path, string $ext, int $fileSize): void`
- `ZipArchive::open($path, RDONLY)` 실패 → `zip_unreadable`(zip/hwpx 는 열려야 정상; OOXML 은 checkMime 이 이미 열었다).
- `numFiles > 10000` → **`zip_bomb`** (항목 수 폭탄도 폭탄이다 — PRD F-53 ①).
- 각 `statIndex($i)` 의 `name`·`size`(원본 크기)에 대해:
  - 이름을 `\` → `/` 로 정규화. 세그먼트에 `..` 가 있거나, `/` 로 시작하거나, 둘째 글자가 `:`(드라이브) → `zip_bad_path`.
  - 마지막 세그먼트의 마지막 확장자(소문자)가 `BLOCKED_EXT` 에 있으면 → `zip_blocked_entry`. 디렉터리 항목(이름이 `/` 로 끝남)은 건너뛴다.
  - 마지막 확장자가 `zip` 이면 중첩 깊이 1 로 센다 — **이 검사는 이름만 본다**(속 zip 을 열지 않는다). 이름에 `.zip` 세그먼트가 **3개 이상**(`a.zip.zip.zip` 같은 이름) 또는 항목 중 `.zip` 항목이 하나라도 있고 그 항목의 `size` 가 `fileSize` 의 50배 초과 → `nested_zip`. (실제 중첩 깊이는 풀지 않고는 알 수 없다 — PRD 의 「3단 이상」은 **이름 기준**으로 구현하고 주석에 그렇게 적는다. 깊은 중첩의 실 위험은 ClamAV `--max-recursion` 몫.)
  - `size` 합계를 누적. 합 > `1024*1024*1024` 또는 (`fileSize > 0` 이고 합 / `fileSize` > 100) → `zip_bomb`.
- `close()` 는 `finally`.

### ② 매크로 — `inspectZip` 안에서 `$ext` 가 OOXML 셋일 때
- 항목 이름(소문자)이 `vbaproject.bin` 으로 끝나거나 `/vbadata.xml` 로 끝나면 → `macro_found`. (`[Content_Types].xml` 을 읽어 `macroEnabled` 를 찾는 것도 「읽기」이므로 하지 않는다 — 이름만.)

### ③ `inspectPdf(string $path, int $fileSize): void`
- 앞 4MB 와 뒤 4MB(파일이 8MB 이하이면 전체 한 번)를 `fopen/fread` 로 읽는다(`file_get_contents` 로 전체를 올리지 않는다 — 100MB PDF).
- 아래 키를 **바이트 그대로**(대소문자 구분, PDF 이름 객체) 찾으면 → `pdf_active_content`:
  `/JavaScript` `/JS` `/OpenAction` `/AA` `/Launch` `/EmbeddedFile` `/RichMedia`
  `/JS` 와 `/AA` 는 짧아 오탐이 있을 수 있으니 **뒤에 구분자**(공백·`<`·`(`·`[`·`/`·줄바꿈)가 오는 경우만 센다: 정규식 `#/(JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia)(?=[\s<(\[/])#`.
- 두 조각의 경계(4MB 지점)에 걸친 키를 놓치지 않게 앞 조각 끝 64바이트를 뒤 조각 앞에 이어 붙여 검사한다.

### ④ `inspectHwpOle(string $path): void` — 휴리스틱(ARCH 5.27 표의 「서명」을 이렇게 구현)
- 첫 8바이트가 `D0 CF 11 E0 A1 B1 1A E1` 이 아니면 → `hwp_not_hwp`.
- 파일 전체를 1MB 조각으로 읽으며(경계 64바이트 겹침) 두 문자열을 찾는다: 디렉터리 항목 이름 UTF-16LE `"F\0i\0l\0e\0H\0e\0a\0d\0e\0r\0"` 와 ASCII 서명 `"HWP Document File"`. **둘 다** 있어야 통과, 하나라도 없으면 → `hwp_not_hwp`.
- 주석에 적는다: 「OLE 디렉터리·미니 FAT 를 해석하지 않는 이유 — 목적은 `.doc/.xls/.msi` 를 `.hwp` 로 바꿔 올린 것을 잡는 것이고, 두 문자열을 갖춘 OLE 를 일부러 만든 공격자는 어차피 ClamAV(F-54) 몫」.

### 공통
- 예외는 전부 `RuntimeException('<코드>')` — 코드는 위 일곱: `zip_unreadable` `zip_bad_path` `zip_blocked_entry` `nested_zip` `zip_bomb` `macro_found` `pdf_active_content` `hwp_not_hwp`(여덟). 다른 정보는 넣지 않는다.
- 머리말 주석의 「여기서 하는 것」에 F-53 네 줄을 더하고, 「하지 않는 것」의 ClamAV 줄을 「F-54 배치 몫」으로 고친다.

## 2) `src/Audit.php` — SUMMARY_TEMPLATES 문장 틀
표에 없는 조합은 예외를 던지므로(`summary()`), 업로드 창구 다섯 action 각각에 여덟 코드를 넣는다: `file.upload`, `file.fetch`, `purchase.import.upload`, `purchase.structure.upload`, `purchase.recon.upload` (각 action 의 기존 `mime_mismatch` 줄 바로 아래, 같은 어투·같은 자리표시자). 예:
- `'file.upload|deny|zip_blocked_entry' => '{actor} 이(가) 올린 압축 파일 「{target}」 안에 실행 파일이 들어 있어 거부했습니다'`
- `zip_bad_path` → 「…안에 경로를 벗어나는 항목이 있어…」 · `nested_zip` → 「…압축 파일이 여러 겹 겹쳐 있어…」 · `zip_bomb` → 「…압축을 풀면 지나치게 커지거나 항목이 너무 많아(압축 폭탄 의심)…」 · `zip_unreadable` → 「…압축 파일 구조를 읽을 수 없어…」 · `macro_found` → 「…문서 「{target}」 에 매크로가 들어 있어…」 · `pdf_active_content` → 「…PDF 「{target}」 에 열리면 실행되는 내용(스크립트·자동 실행)이 들어 있어…」 · `hwp_not_hwp` → 「…파일 「{target}」 이 한글 문서 형식이 아닌데 .hwp 이름이라(위장 의심)…」.
`file.fetch` 는 「올린」 대신 「가져온」, `purchase.recon.upload` 는 「명세서」 어투를 기존 줄과 맞춘다. **다른 줄은 손대지 않는다.** 보고서에 40줄을 그대로 적는다(Fable 이 ARCH 7.4 에 옮긴다).

## 3) `tests/f53.sh` — PRD T-65
`tests/api_upload.sh` 의 골격(`login`·`expect`·`psqlq`·`mark`·`uuid_of`·휴지통 정리·R-73 사전 검사)을 복사. 시험 파일은 `python3`(표준 라이브러리 `zipfile`·`struct` 만)로 작업 폴더에 만든다.
- **(a)** zip: `x.exe`(내용 아무거나) · `a/../b.txt` · `/etc/x`(zipfile 은 앞 `/` 를 떼므로 `ZipInfo` 로 이름을 직접 지정) · `a.zip.zip.zip` 항목 · 항목 10,001개(빈 파일, `ZIP_STORED`) · 압축 폭탄(`b'\0'*200MB` 를 `ZIP_DEFLATED` — 메모리를 피하려면 1MB 씩 200번 `writestr` 하지 말고 **한 항목**에 `zipfile.ZipFile.open(..., 'w')` 스트리밍으로 200MB 쓰기; 결과 파일은 1MB 안팎) → 각각 400 + 감사 사유. 정상 zip(txt·png) → 201.
  각 업로드는 `-F "file=@..."` 로 `POST /api/upload`(세션+CSRF, `api_upload.sh` 방식). 사유는 `psqlq "SELECT reason FROM audit_logs WHERE id > $M AND action='file.upload' ORDER BY id DESC LIMIT 1"`.
- **(b)** docx: 정상 docx(`[Content_Types].xml`·`word/document.xml` 두 항목, 최소 XML) → 201; 같은 것에 `word/vbaProject.bin` 추가 → 400 `macro_found`.
- **(c)** pdf: 최소 PDF(`%PDF-1.4` … `%%EOF`) → 201; `/OpenAction << /S /JavaScript /JS (app.alert(1)) >>` 삽입 → 400 `pdf_active_content`; `/Launch` 삽입 → 400; **오탐 확인**: 본문 스트림 안의 평문 `JavaScript`(슬래시 없음)만 있는 PDF → 201. 큰 PDF(9MB, 뒤쪽 4MB 경계 바로 뒤에 `/Launch`) → 400(경계 검사).
- **(d)** hwp: OLE 헤더 8바이트 + 0 패딩 512바이트 + 임의 4096바이트 → finfo 가 무엇으로 보는지 먼저 출력하고, `application/cdfv2`(또는 hwp 칸의 OLE 값)이면 업로드 → 400 `hwp_not_hwp`; finfo 가 다른 값을 주면 `mime_mismatch` 로 이미 막힌다는 것을 출력에 적고 `inspectHwpOle` 는 **php -r 단위 시험**으로 대체(헤더만 → 예외 / 헤더+두 문자열 → 통과). 진짜 HWP 샘플이 서버에 없으므로 「정상 hwp → 201」 은 **미검증**으로 표시(관문 ③ 사용자).
- **(e)** URL 가져오기: 허용 호스트 목록(`psqlq "SELECT host FROM fetch_hosts WHERE is_active"`)에 시험 파일을 둘 수 없으면 `php -r` 로 `Upload::validatePath` 를 직접 호출해 (a)~(d) 파일이 같은 코드로 던지는지 확인하고 「가져오기 경로는 같은 함수 — 단위로 대체」라고 적는다.
- **(f)** 회귀: `shell.php` → `ext_not_allowed`, `shell.php` 를 `x.pdf` 로 → `mime_mismatch`, `a.php.txt` → `double_extension`; 14종 정상 파일(각 확장자 최소 유효 파일 — `hwp` 는 샘플 없음 → SKIP 표시) → 201. 100MB zip(`b'\0'` 100MB 한 항목 STORED — 압축비 1:1 이라 폭탄 아님) 업로드 왕복 시간과, `php -r` 로 `inspectContent` 만 잰 시간 < 1초.
- **(g)** `scripts/security_lint.sh` 0 · `php -l src/Upload.php src/Audit.php` · 만든 파일 전부 휴지통(`DELETE /api/files/{id}`) · 작업 폴더 삭제.

## 4) 완료 전 자기 점검
ARCHITECTURE 13장. `git diff src/Upload.php` 가 `validatePath` 한 줄 + 새 private 함수 + 머리말 주석 밖으로 번지지 않았는지, `Audit.php` diff 가 문장 틀 40줄뿐인지 보고에 적는다. `git status` 에 세 파일 밖 변경 없음. 커밋하지 않는다.
