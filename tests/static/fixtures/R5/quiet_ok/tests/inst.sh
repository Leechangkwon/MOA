#!/bin/bash
# 견본: 임시 인스턴스(가짜)
PORT=9555
SITE="http://127.0.0.1:$PORT"
php -S "127.0.0.1:$PORT" -t public public/index.php &
curl -s "$SITE/shop/api/ping"
