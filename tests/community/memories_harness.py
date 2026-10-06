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
    parser.add_argument('--port', type=int, default=8768, choices=(8768,))
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--suite', choices=('memories_http.py','memories_runtime_browser.py','memory_ai_http.py','memory_story_http.py','memory_social_http.py','travel_plans_http.py','remote_http.py','messenger_http.py','messenger_store_browser.py','messenger_window_browser.py','qf12_floor_http.py','qf12_server_http.py','qf12_shell_browser.py','qf12_work_browser.py','memories_http.py','qf13h_privacy_browser.py'), default='memories_http.py')
    parser.add_argument('--baseline-code', action='store_true')
    args = parser.parse_args()
    args.output = args.output.resolve()
    for port in (args.port,args.port+1):
        with socket.socket() as sock:
            if sock.connect_ex(('127.0.0.1',port))==0:
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
    work = Path(tempfile.mkdtemp(prefix='qaredi-stage13-', dir='/tmp'))
    work.chmod(0o700)
    instance = work / 'tbox-v2'
    db_name = 'tbox_memories_test_{}_{}'.format(args.port, os.getpid())
    db_env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    db_env['PGPASSFILE'] = str(pgpass)
    db_options = ['-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w']
    server = None
    second_server = None
    created = False

    def db_cmd(program, tail, **kwargs):
        return subprocess.run([program] + db_options + tail, env=db_env, check=True,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)

    def sql_file(path):
        if path.name in ('2026-10-01_community_suggestions.sql','2026-10-18_community_ai.sql'):
            # Isolated DB owner only; production migration retains tbox_owner unchanged.
            adapted = work / 'test-owner-suggestions.sql'
            adapted.write_text(path.read_text().replace('OWNER TO tbox_owner;', 'OWNER TO tbox_testdb;'))
            path = adapted
        return db_cmd('psql', ['-X', '-d', db_name, '-v', 'ON_ERROR_STOP=1', '-f', str(path)])

    try:
        shutil.copytree(ROOT, instance, ignore=shutil.ignore_patterns('.git', '.env', '__pycache__'))
        # Actual application classes; GPT remains disabled and no outside calls.
        if args.baseline_code:
            import tarfile, io
            archive = subprocess.run(['git','archive','905d352','src','public','db'],cwd=ROOT,check=True,capture_output=True).stdout
            with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
                for member in tar.getmembers():
                    target=(instance/member.name).resolve()
                    if instance.resolve() not in target.parents: raise RuntimeError('Archive path guard')
                    if member.isdir(): target.mkdir(parents=True,exist_ok=True)
                    elif member.isfile():
                        target.parent.mkdir(parents=True,exist_ok=True)
                        target.write_bytes(tar.extractfile(member).read())
        if str(ROOT).startswith('/var/www'): raise RuntimeError('Operating source refused')
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
        if args.suite == 'remote_http.py':
            # OAuth issuer stays HTTPS; tests connect directly to the owned loopback
            # PHP listener and never resolve/follow this reserved .invalid origin.
            config.update(APP_URL='https://mcp-fixture.invalid', MCP_REMOTE_ENABLED='1',
                          MCP_OAUTH_REDIRECT_URIS='["https://mcp-fixture.invalid/oauth/callback"]')

        def write_config(path, data):
            private_write(path, ''.join('{}={}\n'.format(k, v) for k, v in data.items()))

        write_config(instance / '.env', config)
        write_config(instance / '.stage13-fixture.env', dict(config, DB_USER='tbox_testdb', DB_PASS=password))
        db_cmd('createdb', ['-O', 'tbox_testdb', db_name])
        created = True
        logs = [sql_file(instance / 'db/schema.sql').stdout]
        db_cmd('psql', ['-X','-d',db_name,'-v','ON_ERROR_STOP=1'], input=b'CREATE SCHEMA community;')
        community_schema = instance / 'db/community/schema.sql'
        logs.append(sql_file(community_schema).stdout)
        # Run feature migrations twice to verify reapplication on the fresh-install shape.
        migrations = ['2026-09-29_community_trust.sql', '2026-09-30_community_automation.sql']
        if not args.baseline_code:
            migrations += ['2026-10-01_community_suggestions.sql', '2026-10-18_community_ai.sql',
                           '2026-10-03_community_memories.sql', '2026-10-03_community_messenger.sql',
                           '2026-10-03_memory_story.sql', '2026-10-14_memory_social.sql',
                           '2026-10-14_optional_member_auth.sql', '2026-10-14_usage_guide.sql',
                           '2026-10-14_travel_plans.sql', '2026-10-14_travel_scopes.sql',
                           '2026-10-15_remote_mcp.sql', '2026-10-19_qf12_work.sql']
        for _ in range(2):
            for migration in migrations:
                logs.append(sql_file(instance / 'db/migrations' / migration).stdout)
        grants = (instance / 'db/grants.sql').read_text().replace('tbox_re', db_name)
        if 'DATABASE tbox_re ' in grants or not db_name.startswith('tbox_memories_test_'):
            raise RuntimeError('Temporary DB guard failed')
        grant_path = work / 'grants.sql'
        grant_path.write_text(grants)
        logs.append(sql_file(grant_path).stdout)
        logs.append(sql_file(instance / 'db/community/grants.sql').stdout)
        (args.output / 'schema.log').write_bytes(b''.join(logs))
        auth_path = work / 'credentials.json'
        fixture_env = dict(os.environ, FILE_RENAME_FIXTURE_CONFIG=str(instance / '.stage13-fixture.env'))
        fixture = subprocess.run(['php', 'tests/community/memories_fixture.php', 'init'], cwd=instance,
                                 env=fixture_env, input=str(auth_path).encode(), check=True,
                                 stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        (args.output / 'fixture.log').write_bytes(fixture.stdout)
        if not auth_path.is_file():
            raise RuntimeError('Synthetic fixture creation failed')
        manifest = dict(base_url='http://127.0.0.1:{}/tbox'.format(args.port), database=db_name,
                        instance=str(instance), credentials_file=str(auth_path), shared_token=config['API_TOKEN'])
        private_write(work / 'manifest.json', json.dumps(manifest))
        with (work / 'logs/php.log').open('wb') as server_log:
            server = subprocess.Popen(['php', '-d', 'upload_max_filesize=100M', '-d', 'post_max_size=105M', '-S', '127.0.0.1:{}'.format(args.port),
                                       '-t', 'public', 'tests/community/export_router.php'], cwd=instance,
                                      env={k:v for k,v in os.environ.items() if k != 'PHP_CLI_SERVER_WORKERS'},
                                      stdout=server_log, stderr=server_log, start_new_session=True)
            second_server = subprocess.Popen(['php','-d','upload_max_filesize=100M','-d','post_max_size=105M','-S','127.0.0.1:{}'.format(args.port+1),'-t','public','tests/community/export_router.php'],cwd=instance,env={k:v for k,v in os.environ.items() if k != 'PHP_CLI_SERVER_WORKERS'},stdout=server_log,stderr=server_log,start_new_session=True)
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
            suite_directory = 'tests/mcp' if args.suite == 'remote_http.py' else 'tests/community'
            result = subprocess.run([sys.executable, str(ROOT / suite_directory / args.suite)],
                                    env=dict(fixture_env, FILE_RENAME_MANIFEST=str(work / 'manifest.json'),
                                             FILE_RENAME_OUTPUT=str(args.output), STAGE89_SECOND_PORT=str(args.port+1),
                                             REMOTE_MCP_HARNESS='1' if args.suite == 'remote_http.py' else '0'), cwd=instance)
            server_log.flush()
            (args.output / 'http.log').write_bytes((work / 'logs/php.log').read_bytes())
            if result.returncode:
                raise RuntimeError('Integration failed; see sanitized results')
    except subprocess.CalledProcessError as error:
        # SQL errors may contain private connection details; do not dump them to chat/logs.
        detail = (error.stderr or b'').decode(errors='replace')
        for secret in [password] + [config.get(k,'') for k in ('DB_PASS','APP_SECRET','API_TOKEN')]:
            if secret: detail = detail.replace(secret,'[PRIVATE]')
        print('HARNESS_FAILURE=' + type(error).__name__ + ': ' + detail[-1800:], flush=True)
        raise SystemExit(1)
    finally:
        if second_server is not None:
            try:
                os.killpg(second_server.pid, signal.SIGTERM)
                second_server.wait(timeout=5)
            except ProcessLookupError: pass
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
