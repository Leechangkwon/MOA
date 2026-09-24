#!/bin/bash
# 견본: 문서를 옛 자리에서 읽는 시험(가짜)
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
grep -c 단계 "$ROOT/docs/GUIDE.md"
