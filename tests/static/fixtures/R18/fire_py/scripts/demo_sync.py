#!/usr/bin/env python3
"""견본: 동기화 — --dry-run 이면 비교만."""
import argparse
import json
import os
import tempfile
from pathlib import Path


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()
    state = Path('/var/lib/demo-sync/state.json')
    data = {'n': 1}
    tmpd = tempfile.mkdtemp()
    with open(os.path.join(tmpd, 'scratch.txt'), 'w') as fh:
        fh.write('x')
    if not args.dry_run:
        state.write_text(json.dumps(data))
    stamp = Path('/var/lib/demo-sync/stamp')
    stamp.write_text('now')                 # ← dry-run 에서도 쓴다
    label = 'a-b'.replace('-', '_')
    if args.dry_run:
        print('dry', label)
        return 0
    os.remove('/var/lib/demo-sync/old.json')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
