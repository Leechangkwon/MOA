#!/bin/bash
# 가짜 견본(FP-0001 짝): 명령 앞 환경 대입 뒤, 줄 이어짐 다음의 **진짜 인자**는 여전히 잡는다
DEMO_PW=$(demo-vault get demo)
A=1 \
  python3 tools/demo_a.py "$DEMO_PW"
A=1 B="$(cat /dev/null)" \
  C=2 \
    python3 tools/demo_b.py --user demo "$DEMO_PW"
