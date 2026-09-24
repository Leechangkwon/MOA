#!/bin/bash
# 견본: 임시 인스턴스를 띄워 묻는 시험(가짜)
PORT=9555
SITE="http://127.0.0.1:$PORT"
php -S "127.0.0.1:$PORT" -t public public/index.php &
curl -s "$SITE/api/items/$ID"
curl -s "$SITE/shop/api/ping"
