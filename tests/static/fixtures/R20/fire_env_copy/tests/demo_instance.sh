#!/usr/bin/env bash
# 견본: 시험이 임시 인스턴스를 띄우려고 운영 .env 를 옮겨 쓴다(가짜)
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/app"
sed -e "s#^DB_NAME=.*#DB_NAME=demo_test#" \
    -e "s#^STORAGE_DIR=.*#STORAGE_DIR=$WORK/storage#" \
    "$ROOT/.env" > "$WORK/app/.env"
chmod 600 "$WORK/app/.env"
cp -p "$WORK/app/.env" "$WORK/app/.env.bak"
