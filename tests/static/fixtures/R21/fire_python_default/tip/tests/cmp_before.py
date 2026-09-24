#!/usr/bin/env python3
# 가짜 견본 — 파이썬 os.environ.get 기본값이 앞선 판 — 울림 1
import os
import subprocess
ref = os.environ.get('BASE_REF', '@C1@')
subprocess.run(['git', 'archive', ref], stdout=subprocess.DEVNULL, check=False)
