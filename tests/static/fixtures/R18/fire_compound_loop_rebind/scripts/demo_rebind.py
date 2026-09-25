#!/usr/bin/env python3
"""가짜 견본(v4 검토 중간 1, 파이썬 for) — 가드 뒤 반복 대상이 조각 변수 held 를 다시 묶음(이름 하나 · 튜플 풀기) → 울림(2)."""
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
        for held in held.parent.glob(p.name + '.*'):
            if held.exists():
                held.unlink()
    for p in Path('/var/lib/demo-hold/in').iterdir():
        held = Path('/var/lib/demo-hold/held') / p.name
        if held.exists() and args.dry_run:
            continue
        for _i, held in enumerate(held.parent.glob(p.name + '.*')):
            if held.exists():
                held.unlink()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
