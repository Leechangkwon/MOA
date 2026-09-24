#!/bin/bash
# 가짜 견본: 비밀 파일을 읽어 받은 값(이름은 평범)을 인자로 넘긴다
k=$(awk '{print $2}' /srv/demo/mfa-secret.list)
code=$(php tools/calc.php "$k")
echo "$code"
