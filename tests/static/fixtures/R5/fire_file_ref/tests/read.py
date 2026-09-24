# 견본(가짜)
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
open(os.path.join(ROOT, 'docs', 'SPEC.md'), encoding='utf-8').read()
