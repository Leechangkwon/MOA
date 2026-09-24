#!/usr/bin/env bash
# 견본: 셸이 펼치지 않는 자리 · 셸 변수와 같은 이름 — 모두 조용
set -euo pipefail
CONF=/srv/demo.example.invalid/demo.conf
grep -q 'map $arg_ver $demo_expires' "$CONF" || true          # 작은따옴표
grep -q "set \$demo_backend" "$CONF" || true                    # \$ 로 막음
echo "map(\$demo_expires) 를 읽는다"
sed -n $'/^map \\$arg_ver/p' "$CONF" || true                    # $'…'
cat > /dev/null <<'EOF'
location / { try_files $uri $uri/ =404; add_header X-Demo $http_x_demo; }
EOF
uri=/demo/path
echo "요청 경로: $uri"
for host in a.example.invalid b.example.invalid; do echo "$host"; done
check() { local status; status=$(printf '%s' "$1"); echo "$status"; }
check ok
read -r args <<< "x y"; echo "$args"
echo $(( 1 + 2 ))
echo "$remote_addr"   # 알고 쓰는 환경 값(부르는 쪽이 export) — qcheck-allow: R26
