# 견본(가짜)
import os
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = os.environ.get("APP_BASE", "http://127.0.0.1:9090/shop")
urllib.request.urlopen(BASE + "/api/ping")
urllib.request.urlopen(f"{BASE}/api/items")
open(os.path.join(ROOT, 'docs', 'GUIDE.md'), encoding='utf-8').read()
