#!/usr/bin/env python3
# 견본: 접두 없는 주소로 본문을 받아 쓰는 시험(가짜, 파이썬) — 결과를 받는 곁 줄이 전달을 기대하지 않는다. 울려야 한다(하나).
import json
import urllib.request

SITE = "http://127.0.0.1:9090"

body = urllib.request.urlopen(SITE + "/api/items/4").read()
assert json.loads(body)["id"] == 4
