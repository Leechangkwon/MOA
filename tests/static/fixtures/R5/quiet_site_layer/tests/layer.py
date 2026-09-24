#!/usr/bin/env python3
# 견본: 사이트 층 시험(가짜, 파이썬) — 결과를 담은 변수를 받는 곁 줄이 전달 상태를 기대한다. 조용해야 한다.
import urllib.error
import urllib.request

SITE = "http://127.0.0.1:9090"


class NoFollow(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


def status(url):
    try:
        return urllib.request.build_opener(NoFollow).open(url).status
    except urllib.error.HTTPError as e:
        return e.code


code = status(SITE + "/api/ping")
assert code == 308, code
