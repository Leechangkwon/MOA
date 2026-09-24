#!/usr/bin/env bash
# 견본: set -u 인데 도우미를 인자 둘로 부른다
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
. "$ROOT/tests/lib/demo_calls.sh"
post_as demo_user /api/demo
