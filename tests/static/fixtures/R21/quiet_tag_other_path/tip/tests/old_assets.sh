#!/usr/bin/env bash
# 가짜 견본 — 기본 태그 뒤로 바뀐 것은 src/ 뿐, 이 시험이 꺼내는 public/assets/ 는 그대로 — 조용
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OLD_TAG="${OLD_TAG:-v1.0.0}"
git -C "$ROOT" show "$OLD_TAG:public/assets/x.js" > /dev/null
