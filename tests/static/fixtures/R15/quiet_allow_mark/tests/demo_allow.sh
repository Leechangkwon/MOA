#!/usr/bin/env bash
# 가짜 견본(줄 표식) — 받아들인 위험은 같은 줄의 `qcheck-allow: R15` 로 뺀다(skip 으로 센다).
API_TOKEN=$(cat "$HOME/.config/demo/api.token")
curl -s -H "Authorization: Bearer $API_TOKEN" https://example.invalid/demo/ping   # qcheck-allow: R15 — 가짜 인스턴스 전용(보기 예)
