#!/bin/bash
# 견본: 라우트 표에 없는 경로
BASE="http://127.0.0.1:9090/shop"
curl -s "$BASE/api/itemz"
