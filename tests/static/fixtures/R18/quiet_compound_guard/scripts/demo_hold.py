#!/usr/bin/env python3
"""가짜 견본(v4 복합 조건 가드, 파이썬) — `if A and args.dry_run: continue` 뒤의 쓰기가 같은 A 아래 — 조용(0)."""
import argparse
from pathlib import Path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()
    for p in Path('/var/lib/demo-hold/in').iterdir():
        held = Path('/var/lib/demo-hold/held') / p.name
        if held.exists() and args.dry_run:
            print('dry', p.name)
            continue
        if held.exists():
            p.unlink()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
