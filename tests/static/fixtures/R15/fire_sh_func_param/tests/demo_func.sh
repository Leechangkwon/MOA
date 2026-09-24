#!/bin/bash
# 가짜 견본: 함수의 $1 이 외부 명령 인자로 가고, 부르는 쪽이 비밀을 넘긴다
mk() {
  python3 tools/gen.py --seed "$1"
}
s=$(get_secret demo_user)
mk "$s"
mk "plain-value"
