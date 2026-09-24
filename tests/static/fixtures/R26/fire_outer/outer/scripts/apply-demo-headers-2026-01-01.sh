#!/usr/bin/env bash
# 견본: 바깥 저장소 반영 스크립트 — 설정에 머리 줄이 몇 개인지 센다
set -u
SRC=/srv/demo.example.invalid/sites/demo.conf
n=$(grep -c "add_header X-Demo-Echo $http_x_demo" "$SRC")
echo "머리 줄 $n"
