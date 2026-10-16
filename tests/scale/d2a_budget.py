#!/usr/bin/env python3
"""규모 D2a 시간 기준 단언(core.scale_d2a_budget) — 합성 M 단계(글 10만)에서 D2a 창구 A1~A9 를 d1_measure 그대로 재고 단언한다.

  ① 상태 200 · ② p95(첫 쪽 · 다음 쪽 · 깊은 쪽 중 가장 나쁜 값) ≤ 창구 분류의 기준(D1 과 같은 표 — 목록 150 · 홈 300 ms)
  ③ 질의 수 ≤ 상한(알림 — 행마다 질의 0 · 고정 24 · 나머지는 D1 M + JIT 제어문) · ④ 기록된 큰 표 SELECT의 실행 계획 전체에
     community.pages · public.files 의 **표 훑기 Seq Scan 0** — LIMIT 로 멈추는 짧은 훑기(읽은 행 ≤ 10,000 — 예: 루트 폴더 개수 상한
     1,001 을 앞에서부터 세기)는 행 수와 무관해 허용하고 결과에 적는다.
측정 틀 · 격리 · 정리는 d1_measure.py 그대로(합성 DB 만 · 끝나면 DROP). 결과 폴더: --output 의 tier-M.json · budget.json.
"""
import argparse
import hashlib
import json
import math
import re
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
# 창구 → 질의 수 상한(D1 과 같은 셈 — PDO DEALLOCATE 포함). 알림 24 = SQL 12(지시서 A9 「≤ 15」 문장) · 그 밖은 D1 의 M 값 +
# JIT 끄기 문장(`SET LOCAL jit = off` 의 DEALLOCATE — 공간 목록 · 개수 2 · 나무 · 폴더 · 브리핑 1). 행 수와 무관한 상수다.
SELECT = {
    'GET /community/api/cafe': 16,
    'GET /community/api/boards': 12,
    'GET /community/api/boards#cafe': 12,
    'GET /tbox/api/files': 12,
    'GET /tbox/api/folders': 15,
    'GET /community/api/pages#shared': 14,
    'GET /community/api/pages': 18,
    'GET /community/api/pages#team': 18,
    'GET /community/api/pages#tree': 15,
    'GET /community/api/me/trash': 12,
    'GET /community/api/briefing': 37,
    'GET /community/api/notifications': 24,
}
BUDGET_MS = dict(list=150, cursor=150, offset=150, search=1000, home=300, count=150, detail=150)
BIG = {'pages', 'files'}
SHORT_SCAN = 10_000   # LIMIT 로 멈춘 짧은 훑기(읽은 행 = 남긴 행 + 버린 행)
DEEP_REQUIRED = {'GET /tbox/api/files', 'GET /community/api/pages', 'GET /community/api/pages#team'}


def number(value):
    return type(value) in (int, float) and math.isfinite(value) and value >= 0


def statement_key(stmt):
    # 첫 쪽/깊은 쪽에서 SQL와 인자가 같은 개수 질의는 같은 계획 증거를 공유할 수 있다.
    return (stmt.get('sql'), json.dumps(stmt.get('params', []), sort_keys=True), tuple(stmt.get('sets', [])))


def evaluate_results(payload):
    """저장된 측정 증거만 판정한다. 누락된 측정/계획은 통과로 간주하지 않는다."""
    rows = payload.get('results', [])
    results = {r['name']: r for r in rows}
    checks = []

    def check(ok, name, **ev):
        checks.append(dict(name=name, passed=bool(ok), **ev))

    for name, qcap in SELECT.items():
        r = results.get(name)
        if r is None or r.get('error'):
            check(False, name + ' measured', error=(r or {}).get('error'))
            continue
        # 예산은 창구 정의에서 고정한다. 입력 JSON의 category로 완화하지 않는다.
        budget = BUDGET_MS['home' if name in ('GET /community/api/cafe', 'GET /community/api/briefing') else 'list']
        next_pages = r.get('next_pages', [])
        pages_ok = isinstance(next_pages, list) and all(isinstance(p, dict) for p in next_pages)
        pages = [r] + (next_pages if pages_ok else [])
        if 'deep' in r:
            pages_ok = pages_ok and isinstance(r['deep'], dict)
            if isinstance(r['deep'], dict):
                pages.append(r['deep'])
        elif name in DEEP_REQUIRED:
            pages_ok = False
        if r.get('has_cursor') and not next_pages:
            pages_ok = False
        statuses = [p.get('status') for p in pages]
        check(pages_ok and r.get('proxied_status') == 200 and all(s == 200 for s in statuses)
              and all(p.get('proxied_status', 200) == 200 for p in pages),
              name + ' HTTP 200 (first/next/deep)', statuses=statuses, proxied_status=r.get('proxied_status'))
        timings_ok = pages_ok and all(number(p.get('p95')) for p in pages)
        worst = max(p['p95'] for p in pages) if timings_ok else None
        check(timings_ok and worst <= budget, '%s p95 %s ≤ %d ms' % (name, worst, budget),
              p50=r.get('p50'), worst=worst, complete=timings_ok)
        queries = r.get('queries')
        check(type(queries) is int and 0 < queries <= qcap, '%s queries %s ≤ %d' % (name, queries, qcap))
        seq, short, errors = [], [], []
        plans = r.get('plans')
        if not isinstance(plans, list) or not plans:
            errors.append('no execution plans')
            plans = []
        covered = set()
        for p in plans:
            if not isinstance(p, dict):
                errors.append('invalid plan record')
                continue
            g = p.get('gist')
            if not isinstance(g, dict) or g.get('error') or not isinstance(g.get('seq'), list) or not number(g.get('exec_ms')):
                errors.append('missing/failed execution plan: ' + str(g)[:200])
                continue
            covered.add(statement_key(p))
            for s in g['seq']:
                if not isinstance(s, dict) or not s.get('rel') or not number(s.get('rows')) or not number(s.get('removed')):
                    errors.append('invalid sequential scan evidence')
                    continue
                if s['rel'] in BIG:
                    read = s['rows'] + s['removed']
                    (seq if read > SHORT_SCAN else short).append(dict(rel=s['rel'], read=read, sql=' '.join(p.get('sql', '').split())[:200]))
        # 기록된 큰 표 SELECT가 계획에서 빠져도 실패한다(느린 두 문장만 살핀 증거를 차단).
        for page in pages:
            for stmt in page.get('statements', []):
                sql = stmt.get('sql', '').lstrip('( \n').upper()
                if sql.startswith(('SELECT', 'WITH')) and re.search(r'\b(?:PAGES|FILES)\b', sql):
                    if statement_key(stmt) not in covered:
                        errors.append('missing plan for captured SELECT: ' + ' '.join(sql.split())[:160])
        check(not errors and not seq,
              '%s no table-wide Seq Scan on pages/files (%d plans · short LIMIT scans %d)' % (name, len(plans), len(short)),
              errors=errors, seq=seq[:3], short=short[:3])
    return checks


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument('--output', type=Path, default=None)
    ap.add_argument('--tier', default='M')
    ap.add_argument('--reps', type=int, default=7)
    ap.add_argument('--from-dir', type=Path, default=None, help='재지 않고 이미 잰 폴더(tier-M.json)의 값으로만 단언(개발 · 재판정용)')
    a = ap.parse_args(argv)
    out = (a.output or Path(tempfile.mkdtemp(prefix='qaredi-scale-d2a-budget-'))).resolve()
    if str(out).startswith(str(ROOT)):
        raise SystemExit('output inside repository refused')
    source = a.from_dir.resolve() if a.from_dir is not None else out
    if a.from_dir is not None and source == out:
        raise SystemExit('--from-dir and --output must differ; source evidence is read-only')
    out.mkdir(parents=True, exist_ok=True)
    cmd = [sys.executable, str(ROOT / 'tests/scale/d1_measure.py'), '--output', str(out), '--tiers', a.tier, '--reps', str(a.reps),
           '--select', ','.join(SELECT), '--all-plans']
    if a.from_dir is not None:
        rc = 0
        print('REUSE ' + str(source) + ' OUTPUT ' + str(out), flush=True)
    else:
        print('RUN ' + ' '.join(cmd[1:]), flush=True)
        rc = subprocess.run(cmd).returncode
    tier = source / ('tier-%s.json' % a.tier)
    (out / 'budget-mode.txt').write_text('reuse\n' if a.from_dir is not None else 'measured\n')
    if rc or not tier.exists():
        print('MEASURE_FAILED rc=%s' % rc, flush=True)
        raise SystemExit(1)
    raw = tier.read_bytes()
    checks = evaluate_results(json.loads(raw))
    for c in checks:
        print(('PASS ' if c['passed'] else 'FAIL ') + c['name'] + ('' if c['passed'] else ' ' + json.dumps(c, ensure_ascii=False)[:500]), flush=True)
    (out / 'budget-source.json').write_text(json.dumps(dict(path=str(tier), sha256=hashlib.sha256(raw).hexdigest(),
                                                          mode='reuse' if a.from_dir is not None else 'measured'), indent=1))
    (out / 'budget.json').write_text(json.dumps(checks, ensure_ascii=False, indent=1))
    passed = sum(c['passed'] for c in checks)
    print('D2A_BUDGET pass=%d fail=%d output=%s' % (passed, len(checks) - passed, out), flush=True)
    if passed != len(checks):
        raise SystemExit(1)


if __name__ == '__main__':
    main()
