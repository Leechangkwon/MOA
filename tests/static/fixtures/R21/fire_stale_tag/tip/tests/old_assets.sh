#!/usr/bin/env bash
# 가짜 견본 — 옛 자산 비교의 기본 태그 뒤로 그 자산(public/assets/)이 바뀐 커밋이 있다 — 울림 1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OLD_TAG="${OLD_TAG:-v1.0.0}"
git -C "$ROOT" show "$OLD_TAG:public/assets/x.js" > /dev/null
