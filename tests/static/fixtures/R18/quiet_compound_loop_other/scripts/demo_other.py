#!/usr/bin/env python3
"""가짜 견본(대조 — v4 검토 중간 1, 파이썬) — 안쪽 for 가 다른 이름(ext)만 묶음 → 조용(0)."""
import argparse
from pathlib import Path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()
    for p in Path('/var/lib/demo-hold/in').iterdir():
        held = Path('/var/lib/demo-hold/held') / p.name
        if held.exists() and args.dry_run:
            continue
        for _i, ext in enumerate(('.a', '.b')):
            if held.exists():
                held.with_suffix(ext).unlink()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
