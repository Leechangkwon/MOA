#!/usr/bin/env bash
# tests/sec4_r31_nginx.sh — 보안 넷 ② R-31 「사내망에서 VM 80 에 직접 붙으면 평문 로그인 화면」 수락 시험
#   (SECURITY R-31, docs/sec4-implementation.md 2장 — 사용자 결정 d52 「넷 다 진행」 2026-09-25)
#
#   무엇을 보나: deploy/sites/tbox-common.conf 의 map($realip_remote_addr → $tbox_via_proxy)과
#   deploy/sites/tbox-v2.conf 80 서버 블록 첫머리의 `if ($tbox_via_proxy = 0) { return 301 https://도메인$request_uri; }`
#     N1 설정 검사 — 저장소 두 파일을 **글자 그대로**(로그 경로·listen 포트만 임시로) 넣은 임시 nginx.conf 로 `nginx -t`
#     N2 임시 nginx(이 사용자 · 127.0.0.1/::1 의 높은 포트)에서 TCP 상대별 응답
#          · 이 서버 자신 127.0.0.1 · ::1                 → 화면·자산이 그대로(301 아님)
#          · 프록시 대역 흉내 127.0.0.100(임시 사본에서만 프록시 주소를 이 값으로) → 그대로 + XFF 로 실제 IP 복원
#          · 직접 접속 흉내 127.0.0.7                     → 모든 경로 301 https://changkwon.mso.so<경로+쿼리>
#            (로그인 속도 제한 location · 생성기 · 공유 링크 · 옛 자산 308 자리까지 — 서버 단 rewrite 가 먼저)
#          · 직접 접속 + 위조 X-Forwarded-For(127.0.0.1·프록시 값) → 그대로 301(위조 불가)
#          · 요청 줄에 다른 호스트(`GET http://evil.example/x`)·`//evil.example` → Location 호스트는 늘 도메인
#     N3 정적 — map 의 프록시 주소 = set_real_ip_from 주소(어긋나면 프록시로 온 요청이 301 고리) ·
#          로컬 8082(tbox-v2-local)·8081(tbox-v1-local)은 이 map 을 쓰지 않는다 · upgrade_smoke 의 asset_cache.py 가
#          새 tbox-v2.conf 를 전과 같게 읽는다
#
# ★ 운영 nginx·운영 포트(80·8082·8081)·운영 php-fpm 에 닿지 않는다.
#   임시 nginx 는 이 사용자로 127.0.0.1·::1 의 R31_PORT(기본 8191)에만 열고, 로그·pid·임시 파일은 mktemp 폴더(0700)에 둔다.
#   그 폴더는 TMPDIR 이 아니라 짧은 /tmp 아래다 — 없는 FastCGI 소켓 경로(unix:…/run/no-fpm.sock)도 nginx 가 설정을 읽을 때 108바이트
#   한도를 따지므로, 긴 TMPDIR(에이전트 작업 폴더 등)에서는 「too long path in the unix domain socket」으로 N1·N2 가 틀렸다(디버깅 묶음 T).
#   FastCGI 는 **없는 임시 소켓**으로 돌린다(PHP 경로는 502 — 「관문을 지나 location 에 닿았다」는 뜻으로만 쓴다).
#   정적 파일은 이 복제본의 public/ 을 읽는다. sudo 를 쓰지 않는다.
# 사용: bash tests/sec4_r31_nginx.sh
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
P="${R31_PORT:-8191}"
case "$P" in 80|8081|8082) echo "운영 포트($P)는 쓰지 않는다"; exit 3 ;; esac
UA="tbox-review/$(date +%F)"
DOMAIN="changkwon.mso.so"
PROXY_REAL="192.168.104.100"     # 운영 프록시 주소(저장소 파일의 값)
PROXY_SIM="127.0.0.100"          # 임시 사본에서 프록시 자리를 대신하는 루프백 주소
DIRECT_SIM="127.0.0.7"           # 사내망 PC 의 직접 접속을 대신하는 루프백 주소
WORK="$(mktemp -d /tmp/r31.XXXXXX)"; chmod 700 "$WORK"
[ "$(printf '%s' "$WORK/run/no-fpm.sock" | wc -c)" -lt 100 ] || { echo "소켓 경로가 너무 길다($WORK) — 중단"; rm -rf "$WORK"; exit 3; }
# nginx 설정에서 지시어 줄만 센다 — `#` 로 시작하는 주석 줄에 같은 글자가 들어 있어도(예: 임베드 260d036 이 tbox-v2.conf 에 더한 설명 주석)
#   세지 않는다(디버깅 묶음 T, 2026-09-26 기능 점검 — 주석 줄까지 세어 「관문 1개」가 2 로 틀렸다)
count_directive() { grep -vE '^[[:space:]]*#' "$2" | grep -cF -- "$1" || true; }
NGX_UP=0
PASS=0; FAIL=0

hr()  { printf '\n════════════════════════════════════════════════════════════\n%s\n════════════════════════════════════════════════════════════\n' "$1"; }
sub() { printf '\n── %s\n' "$1"; }
expect() {
  if [ "$2" = "$3" ]; then printf '  ✔ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; PASS=$((PASS+1))
  else printf '  ✖ %s  (기대=%s 실제=%s)\n' "$1" "$2" "$3"; FAIL=$((FAIL+1)); fi
}
note() { printf '  · %s\n' "$1"; }
cleanup() {
  if [ "$NGX_UP" = 1 ] && [ -f "$WORK/run/nginx.pid" ]; then
    nginx -c "$WORK/ngx/nginx.conf" -p "$WORK/ngx/" -s stop 2>/dev/null
    sleep 0.5
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT

command -v nginx >/dev/null 2>&1 || { echo "nginx 가 없다 — 시작하지 않는다"; exit 3; }
if ss -ltn 2>/dev/null | grep -qE "[:.]$P\b"; then echo "포트 $P 이 이미 쓰이고 있다 — 중단"; exit 3; fi
mkdir -p "$WORK/ngx" "$WORK/log" "$WORK/run" "$WORK/tmp" "$WORK/storage" "$WORK/exact"
cp /etc/nginx/fastcgi_params "$WORK/ngx/fastcgi_params"
cp /etc/nginx/fastcgi_params "$WORK/exact/fastcgi_params"

# ════════════════════════════════════════════════════════════════
hr "N1 — 저장소 두 파일을 글자 그대로 nginx -t (로그 경로·listen 포트만 임시)"
sed -e "s#/var/log/nginx/#$WORK/log/#g" "$ROOT/deploy/sites/tbox-common.conf" > "$WORK/exact/tbox-common.conf"
sed -e "s#/var/log/nginx/#$WORK/log/#g" \
    -e "s#^\(    listen  *\)80 default_server;#\1127.0.0.1:$P default_server;#" \
    -e "s#^\(    listen  *\)\[::\]:80 default_server;#\1[::1]:$P default_server;#" \
    "$ROOT/deploy/sites/tbox-v2.conf" > "$WORK/exact/tbox-v2"
# 바꾼 것은 로그 경로와 listen 두 줄뿐인가 — 나머지는 한 글자도 다르지 않아야 한다
NDIFF=$(diff <(sed "s#/var/log/nginx/#$WORK/log/#g" "$ROOT/deploy/sites/tbox-v2.conf") "$WORK/exact/tbox-v2" | grep -c '^[<>]')
expect "N1 사본과 저장소 파일의 차이 = listen 두 줄(바뀐 줄 × 2)" "4" "$NDIFF"
cat > "$WORK/exact/nginx.conf" <<NG
pid $WORK/run/exact.pid;
error_log $WORK/log/exact-main.err;
worker_processes 1;
events { worker_connections 32; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    client_body_temp_path $WORK/tmp/cb; proxy_temp_path $WORK/tmp/px; fastcgi_temp_path $WORK/tmp/fc; uwsgi_temp_path $WORK/tmp/uw; scgi_temp_path $WORK/tmp/sc;
    access_log $WORK/log/exact-access.log;
    include $WORK/exact/tbox-common.conf;
    include $WORK/exact/tbox-v2;
}
NG
nginx -t -c "$WORK/exact/nginx.conf" -p "$WORK/exact/" > "$WORK/exact.t" 2>&1
RC=$?
grep -v 'could not open error log file' "$WORK/exact.t" | sed 's/^/    | /'
expect "N1 nginx -t 종료 코드(글자 그대로의 map·if)" "0" "$RC"
expect "N1 nginx -t 「test is successful」" "1" "$(grep -c 'test is successful' "$WORK/exact.t")"

# ════════════════════════════════════════════════════════════════
hr "N2 — 임시 nginx(이 사용자 · 127.0.0.1/::1 :$P)에서 TCP 상대별 응답"
sed -e "s#/var/log/nginx/#$WORK/log/#g" -e "s#$PROXY_REAL#$PROXY_SIM#g" "$ROOT/deploy/sites/tbox-common.conf" > "$WORK/ngx/tbox-common.conf"
sed -e "s#/var/log/nginx/#$WORK/log/#g" \
    -e "s#^\(    listen  *\)80 default_server;#\1127.0.0.1:$P default_server;#" \
    -e "s#^\(    listen  *\)\[::\]:80 default_server;#\1[::1]:$P default_server;#" \
    -e "s#$PROXY_REAL#$PROXY_SIM#g" \
    -e "s#/var/www/qaredi/tbox-v2/public#$ROOT/public#g" \
    -e "s#/var/lib/tbox-v2/storage/#$WORK/storage/#g" \
    -e "s#unix:/run/php/php8.1-fpm-tbox.sock#unix:$WORK/run/no-fpm.sock#g" \
    "$ROOT/deploy/sites/tbox-v2.conf" > "$WORK/ngx/tbox-v2"
if grep -hvE '^[[:space:]]*#' "$WORK/ngx/tbox-v2" "$WORK/ngx/tbox-common.conf" \
     | grep -qE "/var/www/qaredi|php8\.1-fpm-tbox\.sock|/var/log/nginx|/var/lib/tbox-v2|$PROXY_REAL|listen +80 |listen +\[::\]:80"; then
  echo "사본에 운영 경로·포트·주소가 남았다 — 중단"; exit 4
fi
expect "N2 사본에도 map·if 가 그대로(주석 줄 빼고 셈)" "1|1" \
  "$(count_directive 'map $realip_remote_addr $tbox_via_proxy' "$WORK/ngx/tbox-common.conf")|$(count_directive 'if ($tbox_via_proxy = 0)' "$WORK/ngx/tbox-v2")"
cat > "$WORK/ngx/nginx.conf" <<NG
pid $WORK/run/nginx.pid;
error_log $WORK/log/nginx-main.err;
worker_processes 1;
events { worker_connections 64; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    client_body_temp_path $WORK/tmp/cb; proxy_temp_path $WORK/tmp/px; fastcgi_temp_path $WORK/tmp/fc; uwsgi_temp_path $WORK/tmp/uw; scgi_temp_path $WORK/tmp/sc;
    access_log $WORK/log/nginx-access.log;
    include $WORK/ngx/tbox-common.conf;
    include $WORK/ngx/tbox-v2;
}
NG
nginx -c "$WORK/ngx/nginx.conf" -p "$WORK/ngx/" 2>"$WORK/log/start.err" || { echo "임시 nginx 가 뜨지 않았다"; cat "$WORK/log/start.err"; exit 4; }
NGX_UP=1
sleep 0.5

B="http://127.0.0.1:$P"
B6="http://[::1]:$P"
# code <출발 주소> <주소> [curl 인자…] → 상태 코드
code() { local src=$1 url=$2; shift 2; curl -s -o /dev/null -w '%{http_code}' --interface "$src" -A "$UA" "$@" "$url" || true; }
# loc <출발 주소> <주소> [curl 인자…] → Location (없으면 (none))
loc() {
  local src=$1 url=$2 v; shift 2
  v=$(curl -s -D - -o /dev/null --interface "$src" -A "$UA" "$@" "$url" | tr -d '\r' | sed -n 's/^[Ll]ocation: //p' | head -1 || true)
  printf '%s' "${v:-(none)}"
}

sub "이 서버 자신(127.0.0.1) — 전과 같다"
expect "127.0.0.1 자산 app.css 200"               "200" "$(code 127.0.0.1 "$B/tbox/assets/app.css")"
expect "127.0.0.1 생성기 200"                     "200" "$(code 127.0.0.1 "$B/tbox/assets/totp-generator.html")"
expect "127.0.0.1 로그인 화면 = location 에 닿음(임시 FastCGI 없음 502 — 301 아님)" "502" "$(code 127.0.0.1 "$B/tbox/login")"
expect "127.0.0.1 POST /tbox/api/login = 속도 제한 location 에 닿음(502)" "502" "$(code 127.0.0.1 "$B/tbox/api/login" -X POST -H 'Content-Type: application/json' -d '{}')"
expect "127.0.0.1 옛 /api/health 308 그대로" "308" "$(code 127.0.0.1 "$B/api/health")"
expect "127.0.0.1 숨김 파일 404 그대로" "404" "$(code 127.0.0.1 "$B/.env")"

sub "이 서버 자신(::1) — 전과 같다"
if curl -s -o /dev/null --max-time 3 "$B6/tbox/assets/app.css" 2>/dev/null; then
  expect "::1 자산 app.css 200" "200" "$(code ::1 "$B6/tbox/assets/app.css")"
else
  printf '  ○ SKIP ::1 (IPv6 루프백에 붙지 못했다)\n'
fi

sub "프록시(임시 사본에서 $PROXY_SIM) — 전과 같다 · X-Forwarded-For 로 실제 IP 복원"
expect "프록시 자산 app.css 200" "200" "$(code "$PROXY_SIM" "$B/tbox/assets/app.css" -H 'X-Forwarded-For: 10.31.0.1')"
expect "프록시 로그인 화면 = location 에 닿음(502)" "502" "$(code "$PROXY_SIM" "$B/tbox/login" -H 'X-Forwarded-For: 10.31.0.2')"
expect "프록시 UA 없는 헬스체크 GET / = location 에 닿음(502 — 301 아님)" "502" \
  "$(curl -s -o /dev/null -w '%{http_code}' --interface "$PROXY_SIM" -A '' "$B/" || true)"
sleep 0.3
# 자산 location 은 access_log off 라 로그인 화면 요청(XFF 10.31.0.2)으로 본다
expect "접근 로그: 실제 IP 10.31.0.2 · TCP 상대 \"$PROXY_SIM\"" "1" \
  "$(grep -c "^10\.31\.0\.2 \"$PROXY_SIM\" \"10\.31\.0\.2\"" "$WORK/log/tbox-v2.access.log" || true)"

sub "직접 접속($DIRECT_SIM) — 모든 경로가 https 도메인으로 301"
expect "직접 로그인 화면 301"                    "301" "$(code "$DIRECT_SIM" "$B/tbox/login")"
expect "직접 로그인 화면 Location"               "https://$DOMAIN/tbox/login" "$(loc "$DIRECT_SIM" "$B/tbox/login")"
expect "직접 런처 / 301 → 도메인 /"              "301 https://$DOMAIN/" "$(code "$DIRECT_SIM" "$B/") $(loc "$DIRECT_SIM" "$B/")"
expect "직접 자산 app.css 도 301(화면 조각을 주지 않는다)" "301" "$(code "$DIRECT_SIM" "$B/tbox/assets/app.css")"
expect "직접 생성기 301"                          "301" "$(code "$DIRECT_SIM" "$B/tbox/assets/totp-generator.html")"
expect "직접 POST /tbox/api/login 301(속도 제한 location 보다 먼저)" "301" \
  "$(code "$DIRECT_SIM" "$B/tbox/api/login" -X POST -H 'Content-Type: application/json' -d '{"username":"x","password":"y"}')"
expect "직접 POST /tbox/api/login/ 301"           "301" "$(code "$DIRECT_SIM" "$B/tbox/api/login/" -X POST -d '{}')"
expect "직접 공유 링크 모양 301"                  "301" "$(code "$DIRECT_SIM" "$B/tbox/s/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")"
expect "직접 커뮤니티 301"                        "301" "$(code "$DIRECT_SIM" "$B/community/")"
expect "직접 옛 /api/health 도 301(308 보다 먼저)" "301" "$(code "$DIRECT_SIM" "$B/api/health")"
expect "직접 쿼리는 그대로 붙는다"                "https://$DOMAIN/tbox/api/health?a=1&b=%2F" "$(loc "$DIRECT_SIM" "$B/tbox/api/health?a=1&b=%2F")"
BODY=$(curl -s --interface "$DIRECT_SIM" -A "$UA" "$B/tbox/login" || true)
expect "직접 301 본문에 로그인 폼 0(비밀번호 칸 없음)" "0" "$( (grep -o 'type="password"' <<<"$BODY" || true) | wc -l | tr -d ' ')"

sleep 0.3
# 반영 뒤 「301 을 받은 출처」를 접근 로그로 볼 수 있어야 한다 — 자산 location 은 access_log off 지만, 301 은 서버 단 rewrite 에서
# location 에 들어가기 전에 끝나므로 서버의 access_log(UA 있는 요청)로 남는다(독립 검토 반영 2026-09-26 — R-31 사전 조사 사각의 뒷받침)
expect "직접 자산 app.css 301 도 접근 로그에 남는다(출처 $DIRECT_SIM · 상태 301)" "1" \
  "$(awk -v ip="\"$DIRECT_SIM\"" '$2==ip && $8=="/tbox/assets/app.css" && $10=="301"' "$WORK/log/tbox-v2.access.log" | head -1 | wc -l | tr -d ' ')"
expect "UA 없는 직접 요청은 301 이어도 로그에 없다(\$tbox_loggable — 사전 조사의 사각)" "301|0" \
  "$(curl -s -o /dev/null -w '%{http_code}' --interface "$DIRECT_SIM" -A '' "$B/tbox/login?s4noua=1" || true)|$(sleep 0.3; grep -c 's4noua=1' "$WORK/log/tbox-v2.access.log" || true)"

sub "위조 — X-Forwarded-For 는 TCP 상대를 바꾸지 못한다"
expect "직접 + XFF 127.0.0.1 → 그대로 301"        "301" "$(code "$DIRECT_SIM" "$B/tbox/login" -H 'X-Forwarded-For: 127.0.0.1')"
expect "직접 + XFF 프록시 주소 → 그대로 301"      "301" "$(code "$DIRECT_SIM" "$B/tbox/login" -H "X-Forwarded-For: $PROXY_SIM")"
expect "직접 + X-Real-IP 127.0.0.1 → 그대로 301"  "301" "$(code "$DIRECT_SIM" "$B/tbox/login" -H 'X-Real-IP: 127.0.0.1')"

sub "열린 리다이렉트 없음 — Location 의 호스트는 늘 도메인"
expect "요청 줄 절대 주소(GET http://evil.example/x)" "https://$DOMAIN/x" \
  "$(loc "$DIRECT_SIM" "$B/x" --request-target 'http://evil.example/x')"
expect "Host: evil.example 이어도"                "https://$DOMAIN/tbox/login" "$(loc "$DIRECT_SIM" "$B/tbox/login" -H 'Host: evil.example')"
L2=$(loc "$DIRECT_SIM" "$B//evil.example/x" --path-as-is)
expect "경로 //evil.example/x — 호스트 부분이 도메인" "https://$DOMAIN/" "$(printf '%s' "$L2" | cut -c1-$(( ${#DOMAIN} + 9 )))"
note "경로 //evil.example/x 의 Location: $L2"

# ════════════════════════════════════════════════════════════════
hr "N3 — 정적"
MAPIP=$(awk '/^map \$realip_remote_addr \$tbox_via_proxy/{f=1;next} f&&/^}/{f=0} f&&$2=="1;"&&$1!~/^(127\.0\.0\.1|::1)$/{print $1}' "$ROOT/deploy/sites/tbox-common.conf")
RIP=$(sed -n 's/^    set_real_ip_from  *\([0-9.]*\);.*/\1/p' "$ROOT/deploy/sites/tbox-v2.conf")
# 빈 값끼리 같다고 통과하지 않게 — 둘 다 주소 모양인지 먼저 본다(독립 검토 반영 2026-09-26)
expect "map 의 프록시 주소·set_real_ip_from 둘 다 읽힘(IPv4 모양)" "yes|yes" \
  "$([[ "$MAPIP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && echo yes || echo no)|$([[ "$RIP" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && echo yes || echo no)"
expect "map 의 프록시 주소 = set_real_ip_from (어긋나면 301 고리)" "${RIP:-(none)}" "${MAPIP:-(empty)}"
expect "map 의 1 값은 셋뿐(프록시 · 127.0.0.1 · ::1)" "3" \
  "$(awk '/^map \$realip_remote_addr \$tbox_via_proxy/{f=1;next} f&&/^}/{f=0} f&&$2=="1;"' "$ROOT/deploy/sites/tbox-common.conf" | wc -l | tr -d ' ')"
expect "map 기본값 0" "1" "$(awk '/^map \$realip_remote_addr \$tbox_via_proxy/{f=1;next} f&&/^}/{f=0} f&&$1=="default"&&$2=="0;"' "$ROOT/deploy/sites/tbox-common.conf" | wc -l | tr -d ' ')"
expect "로컬 8082(tbox-v2-local)·8081(tbox-v1-local)은 이 값을 쓰지 않는다" "0" \
  "$(cat "$ROOT/deploy/sites/tbox-v2-local.conf" "$ROOT/deploy/sites/tbox-v1-local.conf" | grep -c 'tbox_via_proxy')"
expect "공개용 사이트의 관문은 한 곳(if 블록 1개 — 주석 줄 빼고 셈)" "1" "$(count_directive 'if ($tbox_via_proxy = 0)' "$ROOT/deploy/sites/tbox-v2.conf")"
printf '%s\n' '    # 설명 주석: server 단 if ($tbox_via_proxy = 0) 는 rewrite 단계라 …' '    if ($tbox_via_proxy = 0) {' '        return 301 https://x$request_uri;' '    }' > "$WORK/count_probe.conf"
expect "  (자기 확인) 주석 줄 하나 + 지시어 줄 하나 → 지시어 셈 1 · 글자 셈(옛 식) 2" "1|2" \
  "$(count_directive 'if ($tbox_via_proxy = 0)' "$WORK/count_probe.conf")|$(grep -cF 'if ($tbox_via_proxy = 0)' "$WORK/count_probe.conf")"
if python3 -B "$ROOT/tests/upgrade/asset_cache.py" parse "$ROOT/deploy/sites/tbox-v2.conf" "$ROOT/deploy/sites/tbox-common.conf" "$ROOT/public/assets" > "$WORK/ac-new.json" 2>"$WORK/ac.err"; then
  BASEREV="${R31_BASE:-335791d}"
  mkdir -p "$WORK/old"   # 파일 이름이 결과의 「from」 글에 들어가므로 같은 이름으로 꺼낸다
  if git -C "$ROOT" show "$BASEREV:deploy/sites/tbox-v2.conf" > "$WORK/old/tbox-v2.conf" 2>/dev/null \
     && git -C "$ROOT" show "$BASEREV:deploy/sites/tbox-common.conf" > "$WORK/old/tbox-common.conf" 2>/dev/null \
     && python3 -B "$ROOT/tests/upgrade/asset_cache.py" parse "$WORK/old/tbox-v2.conf" "$WORK/old/tbox-common.conf" "$ROOT/public/assets" > "$WORK/ac-old.json" 2>>"$WORK/ac.err"; then
    expect "upgrade_smoke 의 asset_cache.py 가 새 파일을 전($BASEREV)과 같게 읽는다" "same" "$(cmp -s "$WORK/ac-old.json" "$WORK/ac-new.json" && echo same || echo diff)"
  else
    printf '  ○ SKIP asset_cache 옛 판 대조 (%s 를 꺼내지 못했다)\n' "$BASEREV"
  fi
else
  expect "asset_cache.py parse 종료 코드" "0" "1"; sed 's/^/    /' "$WORK/ac.err"
fi

hr "결과"
printf '통과 %d · 실패 %d\n' "$PASS" "$FAIL"
note "임시 nginx·폴더는 이 스크립트가 끝나며 지워진다. 운영 nginx·포트는 건드리지 않았다."
[ "$FAIL" -eq 0 ] || exit 1
exit 0
