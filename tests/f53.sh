#!/usr/bin/env bash
# tests/f53.sh — F-53 「업로드 내용 검사 강화」 수락 기준 실행 (PRD 12.2 T-65, S-06, R-02)
#
#   (a) zip 속  x.exe · a/../b.txt · /etc/x · a.zip.zip.zip · 항목 10,001개 · 압축 폭탄(200MB→1MB)
#       → 각각 400 + 감사 `file.upload deny zip_blocked_entry|zip_bad_path|nested_zip|zip_bomb` ·
#       정상 zip(txt·png) → 201 · **오탐 확인** 압축비는 100:1 을 넘지만 푼 합계가 500KB 뿐인
#       정상 zip(반복 텍스트) → 201 (압축비 규칙의 32MB 바닥 ZIP_RATIO_FLOOR)
#   (b) 정상 docx → 201 · 같은 것에 `word/vbaProject.bin` 추가 → 400 `macro_found`
#   (c) 정상 PDF → 201 · `/OpenAction << /S /JavaScript /JS (app.alert(1)) >>` → 400 `pdf_active_content` ·
#       `/Launch` → 400 · **오탐 확인** 슬래시 없는 평문 `JavaScript` → 201 ·
#       9MB PDF 의 「뒤 4MB 창」 바로 뒤에 놓인 `/Launch` → 400 (경계 검사)
#   (d) OLE 머리만 있고 `FileHeader`·`HWP Document File` 이 없는 `.hwp` → 400 `hwp_not_hwp`
#       ★ 진짜 HWP 5.x 표본이 서버에 없다 — 「정상 hwp → 201」 은 **미검증**이다(관문 ③ 사용자).
#   (e) URL 가져오기(F-14) — 허용 호스트(fetch_hosts)에 시험 파일을 둘 수 없으므로
#       `Upload::validatePath` **단위 시험**으로 대체한다. 가져오기 경로는 같은 함수를 탄다.
#   (f) 회귀 — `shell.php` `ext_not_allowed` · `shell.php` 를 `x.pdf` 로 `mime_mismatch` ·
#       `a.php.txt` `double_extension` · 14종 정상 파일 201(hwp 는 표본 없음 → SKIP) ·
#       100MB zip 왕복 시간과 속 검사만의 시간(< 1초)
#   (g) `scripts/security_lint.sh` 0건 · `php -l` · 올린 파일 전부 휴지통 · 작업 폴더 삭제
#
# 규칙 (CLAUDE.md 3장)
#   · **검토 계정은 `qa_employee01` 하나만** 쓴다. 시드 계정(admin01 …)은 사용자의 자기 공격 전용이다.
#   · 모든 요청에 `-A "tbox-review/<날짜>"` 를 붙인다 — 감사 로그에서 사람의 행동과 갈라 보려는 것.
#   · **운영 데이터에 손대지 않는다.** 올린 파일은 앱 창구(`DELETE /api/files/{id}`)로 휴지통에
#     보낼 뿐, `psql` 로 지우지 않는다 (SECURITY R-73). 다른 계정·표·설정은 건드리지 않는다.
#   · 세션 업로드(`POST /api/files`, 세션 + CSRF)만 쓴다. 토큰 창구(`POST /api/upload`)는
#     진짜 토큰을 읽어야 해서 쓰지 않는다 (SECURITY R-62) — 두 창구는 같은 검증 함수를 탄다.
#
# ★ 시험 안전 (SECURITY R-73 · R-80)
#   ① 최근 10분 감사 로그에 비-qa actor 가 있으면 시작하지 않는다(종료 코드 3)
#   ② 만드는 파일은 전부 `F53-` 접두이고, 올린 것은 같은 세션으로 되돌린다
#   ③ 루트 파티션 여유가 1GB 미만이면 시작하지 않는다 (100MB 시험 파일을 만든다)
#
# 준비물: curl · python3(표준 라이브러리만) · php(TOTP) · sudo -n(seed-accounts.txt · psql).
# 사용: sudo -v && bash tests/f53.sh
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASE="http://127.0.0.1:8082"
UA="tbox-review/$(date +%F)"
DB="tbox_re"
JAR_DIR="$(mktemp -d)"
WORK="$(mktemp -d)"
PASS=0
FAIL=0
SKIP=0
UPLOADED=""

cleanup() { rm -rf "$JAR_DIR" "$WORK"; }
trap cleanup EXIT

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
skip() { printf '  ○ SKIP %s  (%s)\n' "$1" "$2"; SKIP=$((SKIP+1)); }

psqlq() { sudo -n -u postgres psql -d "$DB" -t -A -c "$1" 2>/dev/null; }

# 감사 로그의 물길 표시. 시각이 아니라 id 로 자른다(같은 초에 들어온 앞 항목이 섞이지 않게).
mark() { psqlq "SELECT coalesce(max(id),0) FROM audit_logs" 2>/dev/null; }

code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }
uuid_of() { printf '%s' "$1" | sed -n 's/.*"id":"\([0-9a-f-]\{36\}\)".*/\1/p' | head -1; }

# ─── 시작 전 관문 ────────────────────────────────────────────────
hr "F-53 업로드 속 검사 — 시험 시작 전 확인"

avail_kb=$(df -Pk / | awk 'NR==2{print $4}')
echo "루트 파티션 여유: $(df -Ph / | awk 'NR==2{print $4}') (${avail_kb}KB)"
[ "$avail_kb" -ge 1048576 ] || { echo "루트 디스크 여유 1GB 미만 — 시험을 시작하지 않는다 (SECURITY R-80)"; exit 3; }

HAVE_PSQL=0
if psqlq "SELECT 1" >/dev/null 2>&1; then HAVE_PSQL=1; fi
if [ "$HAVE_PSQL" -eq 1 ]; then
  BUSY=$(psqlq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%'")
  echo "최근 10분 비-qa 감사 행: ${BUSY:-?}"
  if [ "${BUSY:-0}" -gt 0 ] 2>/dev/null; then
    echo "사용자가 지금 서비스를 쓰고 있다 — 시험을 시작하지 않는다 (SECURITY R-73 ①)"; exit 3
  fi
else
  echo "psql 을 쓸 수 없다 — 감사 사유 확인 항목은 SKIP 된다"
fi

command -v python3 >/dev/null 2>&1 || { echo "python3 가 없다 — 시험 파일을 만들 수 없다"; exit 3; }
echo "python3: $(python3 -V 2>&1)"

# ─── 시험 파일 만들기 (python3 표준 라이브러리만) ───────────────
hr "시험 파일 만들기 — $WORK"

cat > "$WORK/make.py" <<'PYEOF'
# -*- coding: utf-8 -*-
# F-53 시험 파일. 표준 라이브러리(zipfile·base64·os)만 쓴다. 전부 F53- 접두.
import base64, os, sys, zipfile

W = sys.argv[1]
def p(n): return os.path.join(W, n)

PNG = base64.b64decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==')
JPG = base64.b64decode(
    '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
    'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
    'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')
GIF = base64.b64decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')

CT_DOCX = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="xml" ContentType="application/xml"/>'
    '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-'
    'officedocument.wordprocessingml.document.main+xml"/></Types>')
DOCUMENT = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    '<w:body><w:p><w:r><w:t>F-53</w:t></w:r></w:p></w:body></w:document>')
CT_XLSX = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="xml" ContentType="application/xml"/>'
    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-'
    'officedocument.spreadsheetml.sheet.main+xml"/></Types>')
WORKBOOK = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    '<sheets><sheet name="s1" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats'
    '.org/officeDocument/2006/relationships"/></sheets></workbook>')
CT_PPTX = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="xml" ContentType="application/xml"/>'
    '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-'
    'officedocument.presentationml.presentation.main+xml"/></Types>')
PRESENTATION = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>')

# ── (a) zip ───────────────────────────────────────────────────────
with zipfile.ZipFile(p('F53-ok.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.txt', 'F-53 시험용 텍스트입니다.\n' * 3)
    z.writestr('b.png', PNG)
with zipfile.ZipFile(p('F53-exe.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.txt', 'x' * 50)
    z.writestr('x.exe', 'MZ fake executable')
with zipfile.ZipFile(p('F53-dotdot.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr(zipfile.ZipInfo('a/../b.txt'), 'escape')
with zipfile.ZipFile(p('F53-abs.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    # zipfile 의 writestr(문자열 이름)은 앞 '/' 를 떼므로 ZipInfo 로 이름을 직접 지정한다
    z.writestr(zipfile.ZipInfo('/etc/x'), 'absolute')
with zipfile.ZipFile(p('F53-nested.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.zip.zip.zip', 'PK\x05\x06' + '\0' * 18)
with zipfile.ZipFile(p('F53-many.zip'), 'w', zipfile.ZIP_STORED) as z:
    for i in range(10001):
        z.writestr('e%05d' % i, '')          # 빈 파일 10,001개
# 오탐 확인 — 압축비는 100:1 을 훌쩍 넘지만 푼 합계가 500KB 뿐인 **정상** 파일.
# (로그·CSV 처럼 같은 글자가 반복되는 텍스트가 이렇게 줄어든다.)
# 압축비 규칙의 32MB 바닥(ZIP_RATIO_FLOOR)이 이것을 살린다 — 폭탄은 수십 MB 이상일 때만 위험하다.
with zipfile.ZipFile(p('F53-ratio.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('log.txt', b'A' * 500000)
with zipfile.ZipFile(p('F53-bomb.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    # 한 항목에 스트리밍으로 200MB — 메모리에 200MB 를 올리지 않는다. 결과 파일은 200KB 안팎
    with z.open('zero.bin', 'w') as fh:
        block = b'\0' * (1 << 20)
        for _ in range(200):
            fh.write(block)

# ── (b) docx ──────────────────────────────────────────────────────
with zipfile.ZipFile(p('F53-ok.docx'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', CT_DOCX)
    z.writestr('word/document.xml', DOCUMENT)
with zipfile.ZipFile(p('F53-macro.docx'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', CT_DOCX)
    z.writestr('word/document.xml', DOCUMENT)
    z.writestr('word/vbaProject.bin', b'\xd0\xcf\x11\xe0' + b'\0' * 60)

# ── (c) pdf ───────────────────────────────────────────────────────
def pdf(catalog_extra=b'', stream_extra=b''):
    objs = [
        b'1 0 obj\n<< /Type /Catalog /Pages 2 0 R ' + catalog_extra + b' >>\nendobj\n',
        b'2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n',
        b'3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>\nendobj\n',
    ]
    stream = b'BT /F1 12 Tf 20 100 Td (F-53 test) Tj ET\n' + stream_extra
    objs.append(b'4 0 obj\n<< /Length ' + str(len(stream)).encode() + b' >>\nstream\n'
                + stream + b'endstream\nendobj\n')
    out = b'%PDF-1.4\n%\xe2\xe3\xcf\xd3\n'
    offs = []
    for o in objs:
        offs.append(len(out)); out += o
    start = len(out)
    out += b'xref\n0 ' + str(len(objs) + 1).encode() + b'\n0000000000 65535 f \n'
    for off in offs:
        out += ('%010d 00000 n \n' % off).encode()
    out += (b'trailer\n<< /Size ' + str(len(objs) + 1).encode() + b' /Root 1 0 R >>\nstartxref\n'
            + str(start).encode() + b'\n%%EOF\n')
    return out

open(p('F53-ok.pdf'), 'wb').write(pdf())
open(p('F53-js.pdf'), 'wb').write(pdf(b'/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>'))
open(p('F53-launch.pdf'), 'wb').write(pdf(b'/Extra << /S /Launch /F (calc.exe) >>'))
# 오탐 확인 — 슬래시 없는 평문 낱말만 있는 정상 PDF
open(p('F53-plain.pdf'), 'wb').write(
    pdf(stream_extra=b'% the words JavaScript, JS, OpenAction, Launch appear without a slash\n'))

# 9MB PDF — `/Launch` 를 「뒤 4MB 창」이 시작되는 자리 바로 뒤에 놓는다(경계 검사).
# %PDF 머리는 그대로라 finfo 는 application/pdf 로 본다. 덧붙이는 것은 전부 PDF 주석(%)이다.
base = pdf()
marker = b'%/Launch (x)\n'
target = 9 * 1024 * 1024
nb = 4 * 1024 * 1024 - 200                       # 마커 뒤에 남길 바이트 수 (< 4MB → 뒤 창 안)
na = target - len(base) - len(marker) - nb - 4
blob = base + b'%' + b'A' * na + b'\n' + marker + b'%' + b'B' * nb + b'\n'
open(p('F53-big.pdf'), 'wb').write(blob)
off = blob.index(marker)
sys.stderr.write('  · F53-big.pdf 크기=%d · /Launch 위치=%d · 앞 4MB 창 밖=%s · 뒤 4MB 창 안=%s\n'
                 % (len(blob), off, off >= 4 * 1024 * 1024, off >= len(blob) - 4 * 1024 * 1024))

# ── (d) hwp — OLE 머리 + 0 패딩 512 + 임의 4096 (FileHeader·서명 없음) ──
open(p('F53-fake.hwp'), 'wb').write(
    bytes(bytearray([0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1]))
    + b'\0' * 512 + bytes(bytearray(range(256))) * 16)

# ── (f) 회귀 페이로드 ─────────────────────────────────────────────
php_payload = b'<?php system($_GET["c"]); ?>\n'
open(p('F53-shell.php'), 'wb').write(php_payload)
open(p('F53-shell.pdf'), 'wb').write(php_payload)     # 위장 — 내용은 PHP
open(p('F53-a.php.txt'), 'wb').write(b'double extension\n')

# ── (f) 14종 정상 파일 (hwp 는 표본이 없어 만들지 않는다) ────────
open(p('F53-good.txt'), 'wb').write('정상 텍스트입니다.\n'.encode('utf-8'))
open(p('F53-good.csv'), 'wb').write('a,b,c\n1,2,3\n'.encode('utf-8'))
open(p('F53-good.md'), 'wb').write('# 제목\n\n본문\n'.encode('utf-8'))
open(p('F53-good.png'), 'wb').write(PNG)
open(p('F53-good.jpg'), 'wb').write(JPG)
open(p('F53-good.jpeg'), 'wb').write(JPG)
open(p('F53-good.gif'), 'wb').write(GIF)
open(p('F53-good.pdf'), 'wb').write(pdf())
with zipfile.ZipFile(p('F53-good.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('a.txt', 'ok\n')
with zipfile.ZipFile(p('F53-good.docx'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', CT_DOCX); z.writestr('word/document.xml', DOCUMENT)
with zipfile.ZipFile(p('F53-good.xlsx'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', CT_XLSX); z.writestr('xl/workbook.xml', WORKBOOK)
with zipfile.ZipFile(p('F53-good.pptx'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('[Content_Types].xml', CT_PPTX); z.writestr('ppt/presentation.xml', PRESENTATION)
with zipfile.ZipFile(p('F53-good.hwpx'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr('mimetype', 'application/hwp+zip')
    z.writestr('Contents/content.hpf', '<?xml version="1.0"?><package/>')

# ── (f) 100MB 급 zip — 0 바이트 한 항목을 STORED(압축비 1:1 이라 폭탄이 아니다) ──
# 99MiB 로 만든다: 정확히 100MiB 를 넣으면 zip 머리말·중앙 디렉터리가 붙어 파일이
# MAX_BYTES(104,857,600)를 넘어 `too_large` 로 막히고, 그러면 속 검사까지 가지 못한다.
with zipfile.ZipFile(p('F53-100mb.zip'), 'w', zipfile.ZIP_STORED) as z:
    with z.open('big.bin', 'w') as fh:
        block = b'\0' * (1 << 20)
        for _ in range(99):
            fh.write(block)
print('ok')
PYEOF

python3 "$WORK/make.py" "$WORK" || { echo "시험 파일 생성 실패"; exit 3; }
ls -la "$WORK" | sed 's/^/  /'
echo "  finfo 실측:"
for f in "$WORK"/F53-*; do
  printf '    %-20s %s\n' "$(basename "$f")" "$(file -b --mime-type "$f")"
done

# ─── 로그인 (qa_employee01 하나만) ───────────────────────────────
hr "로그인 — qa_employee01"

# TOTP 코드는 1회성이다(R-45) — 같은 창에서 두 번 쓰면 거부되는 것이 정상이라 기다렸다 다시 한다.
login() {
  local who="$1" jar="$JAR_DIR/$1.jar" pw sec raw code left out try
  pw=$(sudo -n awk -v u="$who" '$1==u{print $3}' /etc/tbox-v2/seed-accounts.txt 2>/dev/null)
  sec=$(sudo -n awk -v u="$who" '$1==u{print $2}' /etc/tbox-v2/qa-totp.txt 2>/dev/null)
  [ -n "$pw" ] && [ -n "$sec" ] || return 2
  for try in 1 2 3; do
    raw=$(php "$ROOT/scripts/totp_code.php" "$sec")
    code=${raw%%$'\n'*}
    out=$(curl -s -c "$jar" -A "$UA" -H 'Content-Type: application/json' \
          -d "{\"username\":\"$who\",\"password\":\"$pw\",\"otp\":\"$code\"}" "$BASE/api/login")
    case "$out" in
      *'"ok":true'*)
        curl -s -b "$jar" -A "$UA" "$BASE/api/me" \
          | sed -n 's/.*"csrf_token":"\([0-9a-f]*\)".*/\1/p'
        return 0 ;;
    esac
    left=$(printf '%s' "$raw" | sed -n 's/^남은 시간: \([0-9]*\)초.*/\1/p')
    [ -n "$left" ] || left=31
    printf '  · %s 코드 재사용으로 거부됨 — 다음 창까지 %s초 기다림 (R-45 가 의도대로 막은 것)\n' "$who" "$left" >&2
    sleep $((left + 1))
  done
  return 1
}

CSRF=$(login qa_employee01)
JAR="$JAR_DIR/qa_employee01.jar"
HAVE_SESSION=0
if [ -n "${CSRF:-}" ]; then HAVE_SESSION=1; echo "  qa_employee01 로그인 OK (CSRF 토큰 확보)"
else echo "  qa_employee01 로그인 실패 — HTTP 항목은 전부 SKIP 되고 단위 시험만 돈다"; fi

CODE=""; BODY=""; REASON=""; NEWID=""
up() {   # $1 파일경로 → CODE·BODY·REASON·NEWID 설정 · 성공하면 UPLOADED 에 UUID 를 모은다
  local f="$1" m out
  m=$(mark)
  out=$(curl -s -w '\n%{http_code}' -X POST -b "$JAR" -A "$UA" -H "X-CSRF-Token: $CSRF" \
        -F "file=@$f" -F 'access_level=internal' "$BASE/api/files")
  CODE=$(code_of "$out"); BODY=$(body_of "$out"); REASON=""
  if [ "$HAVE_PSQL" -eq 1 ]; then
    REASON=$(psqlq "SELECT reason FROM audit_logs WHERE id > ${m:-0} AND action='file.upload' ORDER BY id DESC LIMIT 1")
  fi
  NEWID=$(uuid_of "$BODY")
  [ -n "$NEWID" ] && UPLOADED="$UPLOADED $NEWID"
}

deny() {   # $1 설명  $2 기대 사유 코드  $3 파일
  if [ "$HAVE_SESSION" -eq 0 ]; then skip "$1" "세션 없음"; return; fi
  up "$3"
  expect "$1 — 상태" "400" "$CODE"
  expect "$1 — 응답 문구(통일)" '{"error":"잘못된 요청입니다"}' "$BODY"
  if [ "$HAVE_PSQL" -eq 1 ]; then expect "$1 — 감사 사유" "$2" "${REASON:-없음}"
  else skip "$1 감사 사유" "psql 없음"; fi
}

accept() { # $1 설명  $2 파일
  if [ "$HAVE_SESSION" -eq 0 ]; then skip "$1" "세션 없음"; return; fi
  up "$2"
  expect "$1 — 상태" "201" "$CODE"
}

# ─── (a) zip 속 이름·크기 표 ─────────────────────────────────────
hr "(a) zip — 차단 확장자 · 경로 탈출 · 절대 경로 · 중첩 · 항목 수 · 압축 폭탄"
deny   "zip 속 x.exe"            "zip_blocked_entry" "$WORK/F53-exe.zip"
deny   "zip 속 a/../b.txt"       "zip_bad_path"      "$WORK/F53-dotdot.zip"
deny   "zip 속 /etc/x"           "zip_bad_path"      "$WORK/F53-abs.zip"
deny   "zip 속 a.zip.zip.zip"    "nested_zip"        "$WORK/F53-nested.zip"
deny   "zip 항목 10,001개"        "zip_bomb"          "$WORK/F53-many.zip"
deny   "압축 폭탄 200MB→200KB"    "zip_bomb"          "$WORK/F53-bomb.zip"
accept "정상 zip (txt·png)"       "$WORK/F53-ok.zip"
note "오탐 확인 — 압축비 ≥ 100:1 이지만 푼 합계가 500KB 뿐인 정상 zip(반복 텍스트)은 통과해야 한다 (ZIP_RATIO_FLOOR 32MB)"
ls -l "$WORK/F53-ratio.zip" | awk '{printf "  · F53-ratio.zip 크기=%s 바이트 · 푼 합계=500000 바이트 · 압축비≈%d:1\n", $5, 500000/$5}'
accept "오탐 확인 — 고압축비·소용량 zip" "$WORK/F53-ratio.zip"

# ─── (b) 오피스 매크로 ───────────────────────────────────────────
hr "(b) docx — 매크로 부분(word/vbaProject.bin)"
accept "정상 docx"                "$WORK/F53-ok.docx"
deny   "docx + vbaProject.bin"   "macro_found"       "$WORK/F53-macro.docx"

# ─── (c) PDF 능동 내용 ───────────────────────────────────────────
hr "(c) pdf — 열리면 실행되는 내용"
accept "정상 PDF"                 "$WORK/F53-ok.pdf"
deny   "/OpenAction + /JS"       "pdf_active_content" "$WORK/F53-js.pdf"
deny   "/Launch"                 "pdf_active_content" "$WORK/F53-launch.pdf"
accept "오탐 확인 — 슬래시 없는 평문 JavaScript" "$WORK/F53-plain.pdf"
deny   "9MB PDF · 뒤 4MB 창의 /Launch" "pdf_active_content" "$WORK/F53-big.pdf"

# ─── (d) HWP OLE 위장 ────────────────────────────────────────────
hr "(d) hwp — OLE 서명 휴리스틱"
HWP_MIME=$(file -b --mime-type "$WORK/F53-fake.hwp")
note "finfo 가 본 F53-fake.hwp: $HWP_MIME"
case "$(printf '%s' "$HWP_MIME" | tr 'A-Z' 'a-z')" in
  application/cdfv2|application/x-ole-storage)
    deny "가짜 hwp (FileHeader·서명 없음)" "hwp_not_hwp" "$WORK/F53-fake.hwp" ;;
  *)
    note "finfo 가 hwp 칸에 없는 값을 주므로 이 파일은 **mime_mismatch 로 이미 막힌다** — 속 검사까지 가지 않는다."
    deny "가짜 hwp (mime 단계에서 막힘)" "mime_mismatch" "$WORK/F53-fake.hwp" ;;
esac

sub "inspectHwpOle 단위 시험 (php -r · Reflection)"
php -r '
require "'"$ROOT"'/src/Upload.php";
$m = new ReflectionMethod("Upload", "inspectHwpOle");
$m->setAccessible(true);
$dir = sys_get_temp_dir() . "/f53hwp";
@mkdir($dir);
$ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
$cases = [
  "머리만(FileHeader·서명 없음)" => $ole . str_repeat("\0", 512),
  "머리 + FileHeader 만"        => $ole . str_repeat("\0", 64) . "F\0i\0l\0e\0H\0e\0a\0d\0e\0r\0" . str_repeat("\0", 64),
  "머리 + 서명 만"              => $ole . str_repeat("\0", 64) . "HWP Document File" . str_repeat("\0", 64),
  "머리 + 둘 다 (정상 모양)"     => $ole . str_repeat("\0", 64) . "F\0i\0l\0e\0H\0e\0a\0d\0e\0r\0" . str_repeat("\0", 64) . "HWP Document File V5.00" . str_repeat("\0", 64),
  "OLE 머리가 아님"             => "MZ" . str_repeat("\0", 512),
];
foreach ($cases as $label => $bytes) {
  $f = $dir . "/case.bin";
  file_put_contents($f, $bytes);
  try { $m->invoke(null, $f); echo "    통과  ", $label, "\n"; }
  catch (Throwable $e) { echo "    거부  ", $label, " → ", $e->getMessage(), "\n"; }
  @unlink($f);
}
@rmdir($dir);
' > "$WORK/hwp.out" 2>&1
cat "$WORK/hwp.out"
HWPOK=$(grep -c '통과  머리 + 둘 다' "$WORK/hwp.out"); HWPOK=${HWPOK:-0}
HWPNG=$(grep -c '거부  .* → hwp_not_hwp' "$WORK/hwp.out"); HWPNG=${HWPNG:-0}
expect "둘 다 갖춘 OLE 만 통과" "1" "$HWPOK"
expect "나머지 네 가지는 hwp_not_hwp" "4" "$HWPNG"
skip "정상 hwp → 201" "진짜 HWP 5.x 표본이 서버에 없다 — **미검증**. 관문 ③에서 사용자가 실제 한글 파일로 확인한다"

# ─── (e) URL 가져오기 — 같은 함수인지 ───────────────────────────
hr "(e) URL 가져오기(F-14) — 같은 validatePath 를 타는지"
HOSTS=$(psqlq "SELECT string_agg(host, ', ' ORDER BY host) FROM fetch_hosts WHERE is_active")
note "허용 호스트: ${HOSTS:-확인 불가} — 여기에 시험 파일을 둘 수 없다(우리 서버가 아니다)."
note "가져오기 경로는 files_fetch.php 가 **같은** Upload::validatePath 를 부른다(ARCH 5.6, PRD S-05 ⑨) → 단위로 대체한다."
php -r '
require "'"$ROOT"'/src/Upload.php";
$want = [
  "F53-exe.zip" => "zip_blocked_entry", "F53-dotdot.zip" => "zip_bad_path",
  "F53-abs.zip" => "zip_bad_path",      "F53-nested.zip" => "nested_zip",
  "F53-many.zip" => "zip_bomb",         "F53-bomb.zip"   => "zip_bomb",
  "F53-macro.docx" => "macro_found",    "F53-js.pdf"     => "pdf_active_content",
  "F53-launch.pdf" => "pdf_active_content", "F53-big.pdf" => "pdf_active_content",
  "F53-fake.hwp" => "hwp_not_hwp",
  "F53-ok.zip" => "", "F53-ok.docx" => "", "F53-ok.pdf" => "", "F53-plain.pdf" => "",
  "F53-ratio.zip" => "",
];
$bad = 0;
foreach ($want as $name => $code) {
  $path = "'"$WORK"'/" . $name;
  $got = "";
  try { Upload::validatePath($path, $name); }
  catch (Throwable $e) { $got = $e->getMessage(); }
  $ok = ($got === $code);
  if (!$ok) { $bad++; }
  printf("    %s %-16s 기대=%-19s 실제=%s\n", $ok ? "✔" : "✖", $name, $code === "" ? "(통과)" : $code, $got === "" ? "(통과)" : $got);
}
echo "BAD=", $bad, "\n";
' > "$WORK/unit.out" 2>&1
sed '$d' "$WORK/unit.out"
UBAD=$(sed -n 's/^BAD=//p' "$WORK/unit.out")
expect "validatePath 단위 시험 불일치 건수" "0" "${UBAD:-?}"
note "→ 가져오기(F-14)도 이 함수 하나를 타므로 같은 코드로 거부된다. 단, **HTTP 왕복으로는 미검증**이다."
SKIP=$((SKIP+1))

# ─── (f) 회귀 ────────────────────────────────────────────────────
hr "(f) 회귀 — 기존 거부 규칙과 14종 정상 파일"
deny "shell.php"                 "ext_not_allowed"   "$WORK/F53-shell.php"
deny "PHP 내용을 x.pdf 로 위장"    "mime_mismatch"     "$WORK/F53-shell.pdf"
deny "a.php.txt (이중 확장자)"     "double_extension"  "$WORK/F53-a.php.txt"

sub "14종 정상 파일"
for ext in txt csv md png jpg jpeg gif pdf zip docx xlsx pptx hwpx; do
  accept "정상 .$ext" "$WORK/F53-good.$ext"
done
skip "정상 .hwp" "진짜 HWP 5.x 표본이 서버에 없다 — **미검증**(관문 ③)"

sub "100MB zip — 왕복 시간과 속 검사 시간"
ls -l "$WORK/F53-100mb.zip" | sed 's/^/    /'
if [ "$HAVE_SESSION" -eq 1 ]; then
  T_START=$(date +%s.%N)
  up "$WORK/F53-100mb.zip"
  T_END=$(date +%s.%N)
  expect "100MB zip 업로드 상태" "201" "$CODE"
  note "업로드 왕복(전송·저장·sha256 포함): $(awk -v a="$T_START" -v b="$T_END" 'BEGIN{printf "%.2f초", b-a}')"
else
  skip "100MB zip 업로드" "세션 없음"
fi
php -r '
require "'"$ROOT"'/src/Upload.php";
$m = new ReflectionMethod("Upload", "inspectContent");
$m->setAccessible(true);
$f = "'"$WORK"'/F53-100mb.zip";
$size = filesize($f);
$t = microtime(true);
$m->invoke(null, $f, "zip", (int) $size);
printf("SEC=%.4f\n", microtime(true) - $t);
' > "$WORK/perf.out" 2>&1
cat "$WORK/perf.out" | sed 's/^/    /'
PSEC=$(sed -n 's/^SEC=//p' "$WORK/perf.out")
if [ -n "${PSEC:-}" ]; then
  UNDER=$(awk -v s="$PSEC" 'BEGIN{print (s < 1) ? "예" : "아니오"}')
  expect "속 검사만 1초 미만 (${PSEC}초)" "예" "$UNDER"
else
  skip "속 검사 시간 측정" "php -r 실패 — $(head -3 "$WORK/perf.out" | tr '\n' ' ')"
fi

# ─── (g) 정리와 린트 ────────────────────────────────────────────
hr "(g) 정리 — 올린 파일은 휴지통으로 · 린트 · php -l"

N_UP=0; N_DEL=0
for id in $UPLOADED; do
  N_UP=$((N_UP+1))
  D=$(curl -s -o /dev/null -w '%{http_code}' -X DELETE -b "$JAR" -A "$UA" \
      -H "X-CSRF-Token: $CSRF" "$BASE/api/files/$id")
  [ "$D" = "200" ] && N_DEL=$((N_DEL+1)) || printf '  ✖ 정리 실패 %s → %s\n' "$id" "$D"
done
expect "올린 파일 전부 휴지통으로 (psql 삭제 없음)" "$N_UP" "$N_DEL"
note "삭제는 앱 창구(DELETE /api/files/{id})로만 했다 — 논리 삭제이며 DB 행은 남는다 (SECURITY R-73)"

bash "$ROOT/scripts/security_lint.sh" >"$WORK/lint.out" 2>&1
expect "scripts/security_lint.sh" "0" "$?"
grep -c '^✖' "$WORK/lint.out" | sed 's/^/    ✖ 표시 줄 수: /'
tail -1 "$WORK/lint.out" | sed 's/^/    /'

PHPBAD=0
for f in src/Upload.php src/Audit.php; do
  php -l "$ROOT/$f" >/dev/null 2>&1 || { echo "  ✖ php -l 실패: $f"; PHPBAD=1; }
done
expect "php -l src/Upload.php src/Audit.php" "0" "$PHPBAD"
bash -n "$ROOT/tests/f53.sh" 2>/dev/null
expect "bash -n tests/f53.sh" "0" "$?"

# ─── 마무리 ─────────────────────────────────────────────────────
hr "결과"
printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n' "$PASS" "$FAIL" "$SKIP"
echo "미검증: ① 정상 hwp → 201 (진짜 HWP 5.x 표본 없음) ② URL 가져오기 HTTP 왕복(허용 호스트에 시험 파일을 둘 수 없음 — 단위로 대체)"
echo "작업 폴더 $WORK 는 종료와 함께 지워진다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
