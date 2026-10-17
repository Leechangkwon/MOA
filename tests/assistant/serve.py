#!/usr/bin/env python3
"""Synthetic PostgreSQL/HTTP harness. Never copies production records or registers a service."""
import argparse
import json
import os
from pathlib import Path
import secrets
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]


def env_values(path):
    values = {}
    for line in path.read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            key, value = line.strip().split('=', 1)
            values[key] = value.strip().strip('"\'')
    return values


def private_write(path, value):
    path.write_text(value)
    path.chmod(0o600)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8497, choices=(8497, 8498))
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--serve-only', action='store_true')
    parser.add_argument('--suite', choices=('all','closing','closing-ui'), default='all')
    parser.add_argument('--ttl', type=int, default=7200)
    args = parser.parse_args()
    if not 60 <= args.ttl <= 7200:
        raise SystemExit('TTL must be between 60 and 7200 seconds')
    with socket.socket() as sock:
        if sock.connect_ex(('127.0.0.1', args.port)) == 0:
            raise SystemExit('Test port already in use; no process was stopped')
    args.output.mkdir(parents=True, exist_ok=True)
    pgpass = Path.home() / '.config/qaredi/pgpass-testdb'
    password = None
    for line in pgpass.read_text().splitlines():
        parts = line.split(':', 4)
        if len(parts) == 5 and parts[3] == 'tbox_testdb':
            password = parts[4]
            break
    if password is None:
        raise SystemExit('Dedicated temporary database credentials unavailable')
    work = Path(tempfile.mkdtemp(prefix='qaredi-assistant-', dir='/tmp'))
    work.chmod(0o700)
    instance = work / 'tbox-v2'
    db_name = 'tbox_assistant_test_{}_{}'.format(args.port, os.getpid())
    db_env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    db_env['PGPASSFILE'] = str(pgpass)
    db_options = ['-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w']
    server = None
    stop = False
    created = False
    def request_stop(*_):
        nonlocal stop
        stop = True
    signal.signal(signal.SIGTERM, request_stop)
    signal.signal(signal.SIGINT, request_stop)
    def db_cmd(program, tail, **kwargs):
        return subprocess.run([program] + db_options + tail, env=db_env, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)
    def psql_file(path):
        return db_cmd('psql', ['-X', '-d', db_name, '-v', 'ON_ERROR_STOP=1', '-f', str(path)])
    def psql_sql(sql):
        return db_cmd('psql', ['-X', '-At', '-d', db_name, '-v', 'ON_ERROR_STOP=1'],
                      input=sql.encode()).stdout
    try:
        shutil.copytree(ROOT, instance, ignore=shutil.ignore_patterns('.git', '.env', '.assistant-fixture.env', '__pycache__'))
        for name in ('storage', 'tmp', 'logs'):
            (work / name).mkdir(mode=0o700)
        values = env_values(ROOT / '.env.example')
        # Read only connection keys. Never copy production APP_SECRET, token, logs or storage.
        connection = env_values(Path('/var/www/qaredi/tbox-v2/.env'))
        for name in ('DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS'):
            values[name] = connection[name]
        values.update(DB_NAME=db_name, APP_SECRET=secrets.token_hex(32),
                      STORAGE_DIR=str(work / 'storage'), AUDIT_LOG_FILE=str(work / 'logs/audit.log'),
                      APP_URL='http://127.0.0.1:{}'.format(args.port),
                      API_TOKEN=secrets.token_hex(24), API_TOKEN_USER='claude_employee01',
                      ASSISTANT_ENABLED='1', ASSISTANT_TEST_ADAPTER='0', SCAN_ENABLED='0',
                      FETCH_ALLOWED_HOSTS='', ALERT_COMMAND='')
        def write_config(path, config):
            private_write(path, ''.join('{}={}\n'.format(k, v) for k, v in config.items()))
        write_config(instance / '.env', values)
        fixture_values = dict(values, DB_USER='tbox_testdb', DB_PASS=password)
        write_config(instance / '.assistant-fixture.env', fixture_values)
        db_cmd('createdb', db_options[:0] + ['-O', 'tbox_testdb', db_name])
        created = True
        logs = [psql_file(instance / 'db/schema.sql').stdout]
        psql_sql('CREATE SCHEMA community;')
        logs.append(psql_file(instance / 'db/community/schema.sql').stdout)
        for migration in ('2026-09-29_community_trust.sql','2026-09-30_community_automation.sql','2026-10-14_usage_guide.sql'):
            logs.append(psql_file(instance / 'db/migrations' / migration).stdout)
        logs.append(psql_file(instance / 'db/community/grants.sql').stdout)
        grant_text = (instance / 'db/grants.sql').read_text().replace('tbox_re', db_name)
        if 'tbox_re' in grant_text or not db_name.startswith('tbox_assistant_test_'):
            raise RuntimeError('Temporary grants guard failed')
        grant_copy = work / 'grants-temporary.sql'
        grant_copy.write_text(grant_text)
        logs.append(psql_file(grant_copy).stdout)
        # Ensure old-scope migration path works, and then compare repeat application.
        psql_sql("ALTER TABLE api_tokens DROP CONSTRAINT api_tokens_scope_check; ALTER TABLE api_tokens ADD CONSTRAINT api_tokens_scope_check CHECK(scope IN ('read','read+upload','read+upload+delete','community.write','session.open'));")
        metadata_sql = "SELECT conname,pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid IN ('assistant_runs'::regclass,'assistant_events'::regclass,'api_tokens'::regclass) ORDER BY conname; SELECT indexdef FROM pg_indexes WHERE tablename IN ('assistant_runs','assistant_events') ORDER BY indexname;"
        logs.append(psql_file(instance / 'db/migrations/2026-10-13_assistant_runs.sql').stdout)
        logs.append(psql_file(instance / 'db/migrations/2026-10-09_program_distribution.sql').stdout)
        logs.append(psql_file(instance / 'db/migrations/2026-10-09_program_distribution.sql').stdout)
        first = psql_sql(metadata_sql)
        logs.append(psql_file(instance / 'db/migrations/2026-10-13_assistant_runs.sql').stdout)
        if first != psql_sql(metadata_sql):
            raise RuntimeError('Repeated migration differs')
        (args.output / 'migration.log').write_bytes(b''.join(logs) + b'REPEATED_MIGRATION_IDENTICAL=1\n')
        credentials = work / 'credentials.json'
        fixture_env=dict(os.environ, ASSISTANT_FIXTURE_CONFIG_FILE=str(instance / '.assistant-fixture.env'))
        fixture = subprocess.run(['php', str(instance / 'tests/assistant/fixture.php'), 'init'],
                                 input=str(credentials).encode(), cwd=instance,
                                 env=fixture_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True)
        (args.output / 'fixture.log').write_bytes(fixture.stdout)
        # Simulate an existing pre-F67 schema with real synthetic rows; additive migration must preserve them.
        psql_sql('ALTER TABLE files DROP COLUMN program CASCADE, DROP COLUMN program_version, DROP COLUMN program_current, DROP COLUMN program_published_at;')
        files_before = psql_sql("SELECT md5(string_agg(to_jsonb(f)::text,'' ORDER BY id)) FROM files f;")
        program_migration = instance / 'db/migrations/2026-10-09_program_distribution.sql'
        psql_file(program_migration)
        psql_file(program_migration)
        files_after = psql_sql("SELECT md5(string_agg((to_jsonb(f)-'program'-'program_version'-'program_current'-'program_published_at')::text,'' ORDER BY id)) FROM files f;")
        if files_before != files_after:
            raise RuntimeError('Program migration changed original file data')
        tokens_before=psql_sql("SELECT md5(string_agg(to_jsonb(t)::text,'' ORDER BY id)) FROM api_tokens t;")
        diag_migration=instance/'db/migrations/2026-10-17_client_diagnostics.sql'
        if diag_migration.exists():
            psql_file(diag_migration)
            diag_meta_sql="SELECT conname,pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid IN ('client_diagnostic_events'::regclass,'api_tokens'::regclass) ORDER BY conname;"
            diag_meta=psql_sql(diag_meta_sql)
            psql_file(diag_migration)
            if diag_meta!=psql_sql(diag_meta_sql) or tokens_before!=psql_sql("SELECT md5(string_agg(to_jsonb(t)::text,'' ORDER BY id)) FROM api_tokens t;"):
                raise RuntimeError('Diagnostic migration changed existing tokens or repeated schema')
        (args.output / 'program-migration.log').write_text('EXISTING_SYNTHETIC_FILES_UNCHANGED=1; REPEAT_APPLY=1\n')

        manifest = dict(base_url=values['APP_URL'] + '/tbox', database=db_name, instance=str(instance),
                        credentials_file=str(credentials), parent_pid=os.getpid(),
                        expires_at=int(time.time()) + args.ttl, provider='unconfigured', inference_available=False)
        private_write(work / 'manifest.json', json.dumps(manifest, ensure_ascii=False, indent=2))
        server_log = open(work / 'logs/php.log', 'wb')
        server_env = dict(os.environ, PHP_CLI_SERVER_WORKERS='4')
        server = subprocess.Popen(['php', '-S', '127.0.0.1:{}'.format(args.port), '-t', 'public', 'tests/community/export_router.php'],
                                  cwd=instance, env=server_env, stdout=server_log, stderr=server_log,
                                  start_new_session=True)
        for _ in range(50):
            if server.poll() is not None:
                raise RuntimeError('Test HTTP process stopped')
            with socket.socket() as sock:
                if sock.connect_ex(('127.0.0.1', args.port)) == 0:
                    break
            time.sleep(0.1)
        print('SYNTHETIC_DB_CREATED=1; MIGRATION_REPEAT_IDENTICAL=1', flush=True)
        if not args.serve_only:
            test_env = dict(fixture_env, ASSISTANT_TEST_MANIFEST=str(work / 'manifest.json'),
                            ASSISTANT_TEST_OUTPUT=str(args.output))
            result = subprocess.run([sys.executable, str(ROOT / ('tests/assistant/client_diagnostics_ui.py' if args.suite=='closing-ui' else 'tests/assistant/client_diagnostics.py' if args.suite=='closing' else 'tests/assistant/integration.py'))],
                                    env=test_env, cwd=instance)
            # The server log contains no credentials in URI; integration uses headers/stdin only.
            server_log.flush()
            (args.output / 'http.log').write_bytes((work / 'logs/php.log').read_bytes())
            if result.returncode:
                raise RuntimeError('Integration failed; sanitized report contains checks')
            if args.suite=='all':
                subprocess.run([sys.executable, str(ROOT / 'tests/assistant/chat.py')], env=test_env, cwd=instance, check=True)
            if args.suite!='closing-ui':
                subprocess.run([sys.executable, str(ROOT / 'tests/assistant/programs.py')], env=test_env, cwd=instance, check=True)
            if args.suite=='closing':
                subprocess.run([sys.executable, str(ROOT / 'tests/assistant/client_diagnostics_ui.py')], env=test_env, cwd=instance, check=True)
        else:
            private_dir = args.output / 'private'
            private_dir.mkdir(mode=0o700, exist_ok=True)
            private_dir.chmod(0o700)
            auth = json.loads(credentials.read_text())
            user = auth['users']['employee']
            private_write(private_dir / 'assistant-test-auth.json', json.dumps(dict(
                base_url=manifest['base_url'], token=user['assistant.run']['token'],
                file_token=user['read']['token'], synthetic_files=auth['files'],
                expires_at=manifest['expires_at']), ensure_ascii=False, indent=2))
            private_write(private_dir / 'service-manifest.json', json.dumps(manifest, indent=2))
            print('LOCAL_TEST_HTTP_READY={}'.format(manifest['base_url']), flush=True)
            print('PRIVATE_AUTH_FILE={}'.format(private_dir / 'assistant-test-auth.json'), flush=True)
            print('TTL_SECONDS={}'.format(args.ttl), flush=True)
            while not stop and time.time() < manifest['expires_at']:
                subprocess.run(['php', 'scripts/assistant_worker.php', '--drain'], cwd=instance,
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20, check=True)
                time.sleep(0.5)
    except subprocess.CalledProcessError as error:
        # SQL/PHP errors can contain secrets; print only exception kind and sanitized stderr stored privately.
        private_write(work / 'failure.private', error.stderr.decode(errors='replace') if error.stderr else '')
        print('HARNESS_FAILURE={}; details kept in private temporary file'.format(type(error).__name__))
        raise SystemExit(1)
    finally:
        http_log = work / 'logs/php.log'
        if http_log.exists():
            private_write(args.output / 'http.log', http_log.read_text(errors='replace'))
        if server is not None:
            try:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=5)
            except ProcessLookupError:
                pass
        if created:
            db_cmd('dropdb', ['--if-exists', '--force', db_name])
        shutil.rmtree(work)
        if args.serve_only:
            for name in ('assistant-test-auth.json', 'service-manifest.json'):
                (args.output / 'private' / name).unlink(missing_ok=True)
        print('OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1', flush=True)


if __name__ == '__main__':
    main()
