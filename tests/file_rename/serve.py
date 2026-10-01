#!/usr/bin/env python3
"""Empty synthetic DB and local PHP HTTP; no production records, storage or services."""
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


def values(path):
    result = {}
    for line in path.read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            key, val = line.strip().split('=', 1)
            result[key] = val.strip().strip('"\'')
    return result


def private_write(path, text):
    path.write_text(text)
    path.chmod(0o600)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--port', type=int, default=8495, choices=(8495, 8496))
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    with socket.socket() as sock:
        if sock.connect_ex(('127.0.0.1', args.port)) == 0:
            raise SystemExit('Port occupied; no existing process stopped')
    args.output.mkdir(parents=True, exist_ok=True)
    pgpass = Path.home() / '.config/qaredi/pgpass-testdb'
    password = None
    for line in pgpass.read_text().splitlines():
        fields = line.split(':', 4)
        if len(fields) == 5 and fields[3] == 'tbox_testdb':
            password = fields[4]
            break
    if password is None:
        raise SystemExit('Dedicated test DB credentials unavailable')
    work = Path(tempfile.mkdtemp(prefix='qaredi-rename-', dir='/tmp'))
    work.chmod(0o700)
    instance = work / 'tbox-v2'
    db_name = 'tbox_rename_test_{}_{}'.format(args.port, os.getpid())
    db_env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    db_env['PGPASSFILE'] = str(pgpass)
    db_options = ['-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w']
    server = None
    created = False

    def db_cmd(program, tail, **kwargs):
        return subprocess.run([program] + db_options + tail, env=db_env, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)

    def sql_file(path):
        return db_cmd('psql', ['-X', '-d', db_name, '-v', 'ON_ERROR_STOP=1', '-f', str(path)])

    try:
        shutil.copytree(ROOT, instance, ignore=shutil.ignore_patterns('.git', '.env', '__pycache__'))
        for name in ('storage', 'logs'):
            (work / name).mkdir(mode=0o700)
        config = values(ROOT / '.env.example')
        # Connection keys only, used against our new empty DB. Never copy production records/secrets.
        connection = values(Path('/var/www/qaredi/tbox-v2/.env'))
        for key in ('DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS'):
            config[key] = connection[key]
        config.update(DB_NAME=db_name, APP_SECRET=secrets.token_hex(32),
                      STORAGE_DIR=str(work / 'storage'), AUDIT_LOG_FILE=str(work / 'logs/audit.log'),
                      APP_URL='http://127.0.0.1:{}'.format(args.port), API_TOKEN=secrets.token_hex(24),
                      API_TOKEN_USER='claude_employee01', SCAN_ENABLED='0', ALERT_COMMAND='')

        def write_config(path, data):
            private_write(path, ''.join('{}={}\n'.format(k, v) for k, v in data.items()))

        write_config(instance / '.env', config)
        write_config(instance / '.rename-fixture.env', dict(config, DB_USER='tbox_testdb', DB_PASS=password))
        db_cmd('createdb', ['-O', 'tbox_testdb', db_name])
        created = True
        logs = [sql_file(instance / 'db/schema.sql').stdout]
        grants = (instance / 'db/grants.sql').read_text().replace('tbox_re', db_name)
        if 'DATABASE tbox_re ' in grants or not db_name.startswith('tbox_rename_test_'):
            raise RuntimeError('Temporary DB guard failed')
        grant_path = work / 'grants.sql'
        grant_path.write_text(grants)
        logs.append(sql_file(grant_path).stdout)
        (args.output / 'schema.log').write_bytes(b''.join(logs))
        auth_path = work / 'credentials.json'
        fixture_env = dict(os.environ, FILE_RENAME_FIXTURE_CONFIG=str(instance / '.rename-fixture.env'))
        fixture = subprocess.run(['php', 'tests/file_rename/fixture.php', 'init'], cwd=instance,
                                 env=fixture_env, input=str(auth_path).encode(), check=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        (args.output / 'fixture.log').write_bytes(fixture.stdout)
        if not auth_path.is_file():
            raise RuntimeError('Synthetic fixture creation failed')
        manifest = dict(base_url=config['APP_URL'] + '/tbox', database=db_name,
                        instance=str(instance), credentials_file=str(auth_path), shared_token=config['API_TOKEN'])
        private_write(work / 'manifest.json', json.dumps(manifest))
        with (work / 'logs/php.log').open('wb') as server_log:
            server = subprocess.Popen(['php', '-S', '127.0.0.1:{}'.format(args.port),
                                       '-t', 'public', 'public/index.php'], cwd=instance,
                                      env=dict(os.environ, PHP_CLI_SERVER_WORKERS='4'),
                                      stdout=server_log, stderr=server_log, start_new_session=True)
            for attempt in range(50):
                if server.poll() is not None:
                    raise RuntimeError('Owned test server stopped')
                with socket.socket() as sock:
                    if sock.connect_ex(('127.0.0.1', args.port)) == 0:
                        break
                time.sleep(0.1)
            else:
                raise RuntimeError('Test HTTP startup timed out')
            print('SYNTHETIC_DB_CREATED=1; PRODUCTION_RECORDS_COPIED=0', flush=True)
            result = subprocess.run([sys.executable, str(ROOT / 'tests/file_rename/integration.py')],
                                    env=dict(fixture_env, FILE_RENAME_MANIFEST=str(work / 'manifest.json'),
                                             FILE_RENAME_OUTPUT=str(args.output)), cwd=instance)
            server_log.flush()
            (args.output / 'http.log').write_bytes((work / 'logs/php.log').read_bytes())
            if result.returncode:
                raise RuntimeError('Integration failed; see sanitized results')
    except subprocess.CalledProcessError as error:
        # SQL errors may contain private connection details; do not dump them to chat/logs.
        print('HARNESS_FAILURE=' + type(error).__name__, flush=True)
        raise SystemExit(1)
    finally:
        if server is not None:
            try:
                os.killpg(server.pid, signal.SIGTERM)
                server.wait(timeout=5)
            except ProcessLookupError:
                pass
        if created:
            db_cmd('dropdb', ['--if-exists', db_name])
        shutil.rmtree(work)
        print('OWNED_TEST_PROCESSES_STOPPED=1; SYNTHETIC_DB_DROPPED=1', flush=True)


if __name__ == '__main__':
    main()
