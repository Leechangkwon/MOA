#!/usr/bin/env bash
# 견본: 받아들인 위험 표식(이 폴더는 배포 때 git 을 쓰지 않는다)
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
find "$ROOT" -maxdepth 1 -name '.env.hand.*' -mmin +10 -delete
# qcheck-allow: R20 — 배포 트리에서 git add 를 하지 않는다(사본 이름은 .gitignore 밖)
T="$(mktemp -p "$ROOT" .env.hand.XXXXXX)"
cp "$ROOT/.env" "$T"
