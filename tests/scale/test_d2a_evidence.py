#!/usr/bin/env python3
"""D2a 증거 음성 시험. 합성 JSON/가짜 worker/로컬 가짜 PG 소켓만 사용(DB/HTTP 접속 0)."""
import contextlib
import io
import json
import os
from pathlib import Path
import socket
import struct
import sys
import tempfile
import threading
import unittest
from unittest.mock import MagicMock, patch

import d2a_budget as budget
import d1_measure as measure
from d2a_equivalence import run_counted_writers


def complete_evidence():
    rows = []
    for name, cap in budget.SELECT.items():
        statement = dict(sql='SELECT id FROM community.pages LIMIT 20', params=[], ms=3)
        plan = dict(statement, gist=dict(seq=[], exec_ms=2), deep=False)
        row = dict(name=name, category='list', status=200, proxied_status=200, p50=10, p95=20,
                   queries=cap, statements=[statement], plans=[plan])
        if name in budget.DEEP_REQUIRED:
            row['deep'] = dict(status=200, p95=30, statements=[statement])
            row['plans'].append(dict(plan, deep=True))
        rows.append(row)
    return dict(results=rows)


class BudgetEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.payload = complete_evidence()
        self.row = self.payload['results'][0]

    def assert_rejected(self):
        self.assertTrue(any(not check['passed'] for check in budget.evaluate_results(self.payload)))

    def test_complete_measurement_passes(self):
        checks = budget.evaluate_results(self.payload)
        self.assertEqual(len(checks), 48)
        self.assertTrue(all(check['passed'] for check in checks))

    def test_missing_or_invalid_latency_is_not_zero(self):
        for value in (None, float('nan'), float('inf'), True, -1, '20'):
            with self.subTest(value=value):
                self.row['p95'] = value
                self.assert_rejected()
        self.row.pop('p95')
        self.assert_rejected()

    def test_missing_or_invalid_query_count_is_not_zero(self):
        for value in (None, 0, True, -1, 12.0, '12'):
            with self.subTest(value=value):
                self.row['queries'] = value
                self.assert_rejected()
        self.row.pop('queries')
        self.assert_rejected()

    def test_next_page_http_failure_is_rejected(self):
        self.row['next_pages'] = [dict(status=500, p95=5)]
        self.assert_rejected()

    def test_deep_page_http_failure_is_rejected(self):
        row = next(r for r in self.payload['results'] if r['name'] == 'GET /tbox/api/files')
        row['deep']['status'] = 500
        self.assert_rejected()

    def test_missing_required_deep_page_is_rejected(self):
        row = next(r for r in self.payload['results'] if r['name'] == 'GET /tbox/api/files')
        row.pop('deep')
        self.assert_rejected()

    def test_missing_next_page_latency_is_rejected(self):
        self.row['next_pages'] = [dict(status=200)]
        self.assert_rejected()

    def test_empty_or_missing_execution_plans_are_rejected(self):
        self.row['plans'] = []
        self.assert_rejected()
        self.row.pop('plans')
        self.assert_rejected()

    def test_failed_or_missing_plan_gist_is_rejected(self):
        for gist in (None, {}, dict(error='EXPLAIN timeout'), dict(seq=[], exec_ms=None)):
            with self.subTest(gist=gist):
                self.row['plans'][0]['gist'] = gist
                self.assert_rejected()

    def test_unplanned_captured_big_table_select_is_rejected(self):
        self.row['statements'].append(dict(sql='SELECT id FROM public.files WHERE size_bytes > $1', params=['100'], ms=4))
        self.assert_rejected()

    def test_plan_for_different_parameters_is_not_coverage(self):
        self.row['plans'][0]['params'] = ['wrong']
        self.assert_rejected()

    def test_plan_for_different_set_local_is_not_coverage(self):
        self.row['statements'][0]['sets'] = ['SET LOCAL jit = off']
        self.assert_rejected()

    def test_identical_count_query_can_share_plan_across_pages(self):
        for row in self.payload['results']:
            row['plans'] = [p for p in row['plans'] if not p['deep']]
        self.assertTrue(all(check['passed'] for check in budget.evaluate_results(self.payload)))

    def test_table_wide_scan_is_rejected(self):
        self.row['plans'][0]['gist']['seq'] = [dict(rel='pages', rows=100001, removed=0)]
        self.assert_rejected()

    def test_category_cannot_relax_endpoint_time_budget(self):
        self.row['category'] = 'search'
        self.row['p95'] = 301
        self.assert_rejected()

    def test_reuse_preserves_source_and_does_not_measure(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('D2A_EVIDENCE_TMP')) as tmp:
            source, output = Path(tmp) / 'source', Path(tmp) / 'output'
            source.mkdir()
            (source / 'tier-M.json').write_text(json.dumps(self.payload))
            (source / 'budget.json').write_text('original verdict')
            (source / 'budget-mode.txt').write_text('measured\n')
            before = {p.name: p.read_bytes() for p in source.iterdir()}
            with patch.object(budget.subprocess, 'run', side_effect=AssertionError('measurement forbidden')), contextlib.redirect_stdout(io.StringIO()):
                budget.main(['--from-dir', str(source), '--output', str(output)])
            self.assertEqual(before, {p.name: p.read_bytes() for p in source.iterdir()})
            self.assertEqual((output / 'budget-mode.txt').read_text(), 'reuse\n')
            self.assertEqual(json.loads((output / 'budget-source.json').read_text())['path'], str(source / 'tier-M.json'))
            self.assertEqual(len(json.loads((output / 'budget.json').read_text())), 48)

    def test_reuse_refuses_source_as_output(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('D2A_EVIDENCE_TMP')) as tmp:
            with self.assertRaisesRegex(SystemExit, 'must differ'):
                budget.main(['--from-dir', tmp, '--output', tmp])
            self.assertEqual(list(Path(tmp).iterdir()), [])


class ConcurrentWriterEvidenceTests(unittest.TestCase):
    def test_worker_exception_reaches_caller(self):
        def writer(tag):
            if tag == 'b':
                raise RuntimeError('synthetic deadlock')
        with self.assertRaisesRegex(RuntimeError, 'synthetic deadlock'):
            run_counted_writers(writer, lambda: 0)

    def test_partial_write_cannot_pass_count_consistency(self):
        counts = iter([17, 217])
        with self.assertRaisesRegex(AssertionError, 'inserted 200 rows, expected 400'):
            run_counted_writers(lambda tag: None, lambda: next(counts))

    def test_both_writers_must_add_exactly_400_rows(self):
        state, lock = dict(count=17), threading.Lock()
        def writer(tag):
            for _ in range(200):
                with lock:
                    state['count'] += 1
        self.assertEqual(run_counted_writers(writer, lambda: state['count']), 400)
        self.assertEqual(state['count'], 417)


class MeasurementEvidenceTests(unittest.TestCase):
    def test_all_plans_keeps_slow_and_fast_reads_beyond_top_four(self):
        statements = [dict(sql='SELECT id FROM community.pages WHERE id=$1', params=[str(i)], ms=i / 10, sets=[])
                      for i in range(7)] + [dict(sql='DEALLOCATE pdo_stmt_1', params=[], ms=10, sets=[])]
        self.assertEqual(len(measure.captured_statements(statements, False, 4)), 4)
        reads = measure.captured_statements(statements, True, 4)
        self.assertEqual(len(reads), 7)
        candidates = measure.plan_candidates(dict(statements=reads), 'M', True)
        self.assertEqual(len(candidates), 7)

    def test_all_plans_deduplicates_only_identical_sql_params_and_settings(self):
        first = dict(sql='WITH p AS (SELECT 1) SELECT * FROM p', params=['1'], ms=1, sets=['SET LOCAL jit = off'])
        different = dict(first, sets=[])
        result = dict(statements=[first], deep=dict(statements=[dict(first), different]))
        plans = measure.plan_candidates(result, 'M', True)
        self.assertEqual(len(plans), 2)
        self.assertEqual(plans[0]['sets'], ['SET LOCAL jit = off'])
        self.assertEqual(plans[1]['sets'], [])

    def test_vacuum_guard_cancels_only_owned_database_work_when_space_falls(self):
        pg = MagicMock()
        pg.sql.return_value = '0'
        proc = MagicMock()
        proc.poll.return_value = None
        proc.communicate.return_value = (b'', b'')
        with patch.object(measure, 'free_gb', side_effect=[4, 4, 2.9]), patch.object(measure.time, 'sleep'):
            with self.assertRaisesRegex(RuntimeError, 'disk guard stopped VACUUM'):
                measure.wait_disk_guarded(pg, proc, 3, 3, 'VACUUM', timeout=7200)
        proc.terminate.assert_called_once()
        cancel = pg.sql.call_args.args[0]
        self.assertIn('datname=current_database()', cancel)
        self.assertIn('usename=current_user', cancel)
        self.assertNotIn('pg_terminate_backend', cancel)

    def test_cleanup_failure_cannot_return_success(self):
        for db_left in (True, False):
            with self.subTest(db_left=db_left), tempfile.TemporaryDirectory(dir=os.environ.get('D2A_EVIDENCE_TMP')) as tmp:
                h = MagicMock()
                h.created = db_left
                h.work = Path(tmp) / 'already-removed'
                h.config = dict(DB_USER='synthetic')
                h.pg.sql.return_value = ''
                h.scrub.side_effect = lambda text: text
                if not db_left:
                    h.teardown.side_effect = RuntimeError('synthetic teardown failure')
                with patch.object(measure, 'Harness', return_value=h), patch.object(measure, 'route_inventory', return_value=[]), \
                     patch.object(measure, 'make_unlogged', return_value='0/0'), patch.object(measure, 'free_gb', return_value=4), \
                     patch.object(measure.subprocess, 'check_output', return_value='consent'), \
                     patch.object(sys, 'argv', ['d1_measure.py', '--output', tmp, '--tiers', '']), contextlib.redirect_stdout(io.StringIO()):
                    with self.assertRaises(SystemExit) as raised:
                        measure.main()
                self.assertEqual(raised.exception.code, 1)
                self.assertFalse(json.loads((Path(tmp) / 'meta.json').read_text())['cleanup_ok'])


class ProxyLifecycleTests(unittest.TestCase):
    def setUp(self):
        self.listener = socket.socket()
        self.listener.bind(('127.0.0.1', 0))
        self.listener.listen(4)
        self.listener.settimeout(2)
        self.proxy = measure.PgProxy(0, backend_port=self.listener.getsockname()[1])
        self.client = self.backend = None

    def connect(self):
        self.client = socket.create_connection(('127.0.0.1', self.proxy.port), timeout=2)
        self.backend, _ = self.listener.accept()
        self.backend.settimeout(2)
        startup = struct.pack('!II', 8, 196608)
        self.client.sendall(startup)
        self.assertEqual(measure.PgProxy.recv_exact(self.backend, 8), startup)
        ready = b'Z' + struct.pack('!I', 5) + b'I'
        self.backend.sendall(ready)
        self.assertEqual(measure.PgProxy.recv_exact(self.client, 6), ready)

    def tearDown(self):
        self.proxy.close()
        for s in (self.client, self.backend, self.listener):
            if s is not None:
                s.close()
        self.assertFalse(self.proxy.accept_thread.is_alive())
        self.assertFalse(self.proxy.workers)
        self.assertFalse(self.proxy.open_socks)

    def test_client_eof_closes_backend_blocked_in_recv(self):
        self.connect()
        self.client.shutdown(socket.SHUT_RDWR)
        self.client.close()
        self.assertEqual(self.backend.recv(1), b'')

    def test_backend_eof_closes_client_blocked_in_recv(self):
        self.connect()
        self.backend.shutdown(socket.SHUT_RDWR)
        self.backend.close()
        self.assertEqual(self.client.recv(1), b'')

    def test_proxy_close_stops_both_directions_and_threads(self):
        self.connect()
        self.proxy.close()
        self.assertEqual(self.client.recv(1), b'')
        self.assertEqual(self.backend.recv(1), b'')

    def test_backend_registration_after_close_cannot_leak_socket(self):
        entered, release = threading.Event(), threading.Event()
        create_connection = socket.create_connection
        def delayed_connect(address, timeout=None):
            entered.set()
            if not release.wait(2):
                raise TimeoutError('fake connection gate timed out')
            return create_connection(address, timeout=timeout)
        with patch.object(measure.socket, 'create_connection', side_effect=delayed_connect):
            self.client = socket.socket()
            self.client.settimeout(2)
            self.client.connect(('127.0.0.1', self.proxy.port))
            self.assertTrue(entered.wait(2))
            timer = threading.Timer(0.03, release.set)
            timer.start()
            self.proxy.close()
            timer.join(2)
        self.backend, _ = self.listener.accept()
        self.backend.settimeout(2)
        self.assertEqual(self.backend.recv(1), b'')


if __name__ == '__main__':
    unittest.main(verbosity=2)
