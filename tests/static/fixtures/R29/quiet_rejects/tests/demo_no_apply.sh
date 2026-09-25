#!/usr/bin/env bash
# 견본: 반영하지 않고 목록만 보여 준다
set -eu
P="${1:?}"
grep -a '^diff --git ' "$P" | awk '{print $4}' | sed 's#^b/##' | grep -v '^docs/' || echo "docs 뿐"
