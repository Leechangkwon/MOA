#!/bin/bash
# 가짜 견본: 비밀을 표준입력·환경·파일로 넘기는 모양, 비밀이 아닌 값들
SECRET_X=$(cat "$HOME/.demo-secret")
DB_PASS=$(cat "$HOME/.demo-db")
API_TOKEN=$(cat "$HOME/.demo-token")
TOKEN_FILE="$HOME/.demo-token"
TOKEN_USER=demo_bot
printf '%s' "$SECRET_X" | php tools/calc.php -
php tools/calc.php - <<< "$SECRET_X"
PGPASSWORD="$DB_PASS" psql -h localhost demo -c 'select 1'
export API_TOKEN
demo-tool --sync
curl -s --data-binary @"$TOKEN_FILE" https://example.invalid/upload
curl -s -H @- https://example.invalid/ <<< "X-Demo-Token: $API_TOKEN"
echo "token for $TOKEN_USER"
demo-tool --user "$TOKEN_USER"
PASS=0
PASS=$((PASS + 1))
python3 tools/report.py "$PASS"
BAD_TOKEN='not-a-real-token'
curl -s -H "X-Demo-Token: $BAD_TOKEN" https://example.invalid/
code=$(php tools/calc.php - <<< "$SECRET_X")
curl -s -d "otp=$code" https://example.invalid/verify
LEN=${#SECRET_X}
demo-tool --len "$LEN"
digest=$(printf '%s' "$SECRET_X" | sha256sum | cut -d' ' -f1)
demo-tool --digest "$digest"
if [ -n "$SECRET_X" ] && [[ "$SECRET_X" != x && -n "$API_TOKEN" ]]; then
  echo ok
fi
php tools/calc.php <<EOF
$SECRET_X
EOF
case "$1" in
  start) demo-tool --start ;;
  *) echo "사용법" ;;
esac
