#!/bin/bash
# 가짜 견본(묶음 X ④-c): 쿠키 jar 에서 name=value 를 뽑아 명령 인자로 넘긴다
BASE="https://demo.example.invalid"
JAR=$(mktemp)
WORK=$(mktemp -d)
curl -s -c "$JAR" "$BASE/api/login" >/dev/null
C=$(awk '!/^#/ && NF>=7 {printf "%s=%s;", $6, $7}' "$JAR")
python3 tools/demo_shot.py "$BASE" "$C"
K=$(cut -f7 "$WORK/demo.jar")
node tools/demo_smoke.js "$BASE" "$K"
