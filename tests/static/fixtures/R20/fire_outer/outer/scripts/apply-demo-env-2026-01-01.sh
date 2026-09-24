#!/usr/bin/env bash
# 견본: 바깥 반영 스크립트가 .env 사본으로 dry-run 한다
set -euo pipefail
APP=/srv/demo.example.invalid/app
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
cp "$APP/.env" "$T/.env"
