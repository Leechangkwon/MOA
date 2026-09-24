#!/usr/bin/env bash
# 견본: 시험이 임시 nginx 조각과 PHP 한 줄을 만든다
T=$(mktemp -d)
cat > "$T/site.conf" <<EOF
server {
    listen 127.0.0.1:18099;
    location / { try_files $uri $uri/ =404; }
}
EOF
echo Location: $request_uri
php -r "echo $_SERVER['HTTP_HOST'];"
rm -rf "$T"
