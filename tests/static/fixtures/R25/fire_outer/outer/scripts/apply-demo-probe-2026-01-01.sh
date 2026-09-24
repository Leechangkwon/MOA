#!/usr/bin/env bash
# 견본: 바깥 반영 스크립트 — 같은 꼬리 겹침
set -u
probe() { curl -s -o /dev/null -w '%{http_code}' "${@:2}" "$1" ; echo " $4"; }
probe http://127.0.0.1:18091/demo -H 'X-Demo: 1' 라벨
