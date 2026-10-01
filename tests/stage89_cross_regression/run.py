#!/usr/bin/env python3
"""Run common affected-route checks against an empty disposable DB; never run legacy scripts."""
import argparse, hashlib, importlib.util, json, os, secrets, shutil, signal, socket, subprocess, sys, tempfile, time
from pathlib import Path
ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('isolated_base', ROOT/'tests/community/stage89_harness.py')
helpers = importlib.util.module_from_spec(spec); spec.loader.exec_module(helpers)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--output',type=Path,required=True)
    parser.add_argument('--port',type=int,default=8548,choices=(8548,8558))
    args=parser.parse_args(); args.output=args.output.resolve()
    if str(ROOT).startswith('/var/www/'):
        raise SystemExit('Refusing operational source directory; use the reviewed source snapshot')
    with socket.socket() as sock:
        if sock.connect_ex(('127.0.0.1',args.port))==0: raise SystemExit('Port occupied; nothing stopped')
    args.output.mkdir(parents=True,exist_ok=False); args.output.chmod(0o700)
    pgpass=Path.home()/'.config/qaredi/pgpass-testdb'
    password=next((l.split(':',4)[4] for l in pgpass.read_text().splitlines() if len(l.split(':',4))==5 and l.split(':',4)[3]=='tbox_testdb'),None)
    if password is None: raise SystemExit('Dedicated test DB credentials unavailable')
    work=Path(tempfile.mkdtemp(prefix='qaredi-cross89-',dir='/tmp')); work.chmod(0o700)
    instance=work/'tbox-v2'; db_name=f'tbox_stage89_test_cross_{os.getpid()}'
    env={k:v for k,v in os.environ.items() if not k.startswith('PG')};env['PGPASSFILE']=str(pgpass)
    opts=['-h','127.0.0.1','-p','5432','-U','tbox_testdb','-w'];created=False;server=None;config={}
    def pg(program,tail,**kw):
        return subprocess.run([program]+opts+tail,env=env,check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,**kw)
    def sqlfile(path):return pg('psql',['-X','-d',db_name,'-v','ON_ERROR_STOP=1','-f',str(path)])
    try:
        shutil.copytree(ROOT,instance,ignore=shutil.ignore_patterns('.git','.env','__pycache__','.stage89-fixture.env'))
        for name in ['storage','logs']: (work/name).mkdir(mode=0o700)
        config=helpers.values(ROOT/'.env.example')
        connection=helpers.values(Path('/var/www/qaredi/tbox-v2/.env'))
        for key in ['DB_HOST','DB_PORT','DB_USER','DB_PASS']:config[key]=connection[key]
        config.update(DB_NAME=db_name,APP_SECRET=secrets.token_hex(32),STORAGE_DIR=str(work/'storage'),
            AUDIT_LOG_FILE=str(work/'logs/audit.log'),APP_URL=f'http://127.0.0.1:{args.port}',
            API_TOKEN=secrets.token_hex(24),API_TOKEN_USER='claude_employee01',SCAN_ENABLED='0',ALERT_COMMAND='')
        for name,values in [('.env',config),('.stage89-fixture.env',dict(config,DB_USER='tbox_testdb',DB_PASS=password))]:
            helpers.private_write(instance/name,''.join(f'{k}={v}\n' for k,v in values.items()))
        pg('createdb',['-O','tbox_testdb',db_name]);created=True
        logs=[sqlfile(instance/'db/schema.sql').stdout]
        pg('psql',['-X','-d',db_name,'-v','ON_ERROR_STOP=1'],input=b'CREATE SCHEMA community;')
        logs.append(sqlfile(instance/'db/community/schema.sql').stdout)
        for name in ['2026-09-29_community_trust.sql','2026-09-30_community_automation.sql']:
            logs.append(sqlfile(instance/'db/migrations'/name).stdout)
        grants=(instance/'db/grants.sql').read_text().replace('tbox_re',db_name)
        assert 'DATABASE tbox_re ' not in grants and db_name.startswith('tbox_stage89_test_')
        (work/'grants.sql').write_text(grants);logs.append(sqlfile(work/'grants.sql').stdout)
        logs.append(sqlfile(instance/'db/community/grants.sql').stdout)
        (args.output/'schema.log').write_bytes(b''.join(logs))
        auth=work/'credentials.json'; testenv=dict(os.environ,FILE_RENAME_FIXTURE_CONFIG=str(instance/'.stage89-fixture.env'))
        for path in ['tests/community/stage89_fixture.php','tests/stage89_cross_regression/fixture.php']:
            subprocess.run(['php',path,'init'],cwd=instance,env=testenv,input=str(auth).encode(),stdout=subprocess.PIPE,stderr=subprocess.PIPE,check=True)
        manifest=dict(base_url=config['APP_URL']+'/tbox',database=db_name,instance=str(instance),credentials_file=str(auth),shared_token=config['API_TOKEN'])
        helpers.private_write(work/'manifest.json',json.dumps(manifest))
        source_hashes={str(f.relative_to(instance)):hashlib.sha256(f.read_bytes()).hexdigest() for directory in ['src','public','db'] for f in sorted((instance/directory).rglob('*')) if f.is_file()}
        (args.output/'source-hashes.json').write_text(json.dumps(source_hashes,indent=2)+'\n')
        with (work/'logs/php.log').open('wb') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{args.port}','-t','public','tests/community/export_router.php'],cwd=instance,
                env={k:v for k,v in os.environ.items() if k!='PHP_CLI_SERVER_WORKERS'},stdout=log,stderr=log,start_new_session=True)
            for _ in range(50):
                if server.poll() is not None:raise RuntimeError('Owned PHP test server stopped')
                with socket.socket() as sock:
                    if sock.connect_ex(('127.0.0.1',args.port))==0:break
                time.sleep(.1)
            else:raise RuntimeError('Owned HTTP startup timed out')
            print('EMPTY_SYNTHETIC_DB=1; PRODUCTION_RECORDS_COPIED=0; LEGACY_SCRIPTS_EXECUTED=0',flush=True)
            ret=subprocess.run([sys.executable,'tests/stage89_cross_regression/suite.py'],cwd=instance,
                env=dict(testenv,FILE_RENAME_MANIFEST=str(work/'manifest.json'),FILE_RENAME_OUTPUT=str(args.output)))
            log.flush();raw=(work/'logs/php.log').read_text(errors='replace')
            for secret in [password]+[config.get(k,'') for k in ['DB_PASS','APP_SECRET','API_TOKEN']]:
                if secret:raw=raw.replace(secret,'[PRIVATE]')
            (args.output/'http.log').write_text(raw)
            if ret.returncode:raise RuntimeError('Cross regression failed; see results.json')
    except subprocess.CalledProcessError as exc:
        raw=(exc.stderr or b'').decode(errors='replace')
        for secret in [password]+[config.get(k,'') for k in ['DB_PASS','APP_SECRET','API_TOKEN']]:
            if secret:raw=raw.replace(secret,'[PRIVATE]')
        print('HARNESS_FAILURE '+raw[-1400:],flush=True);raise SystemExit(1)
    finally:
        if server is not None:
            try:os.killpg(server.pid,signal.SIGTERM);server.wait(timeout=5)
            except ProcessLookupError:pass
        if created:pg('dropdb',['--if-exists',db_name])
        if work.resolve().parent==Path('/tmp') and work.name.startswith('qaredi-cross89-'):shutil.rmtree(work)
        print('OWNED_SERVER_STOPPED=1; SYNTHETIC_DB_DROPPED=1',flush=True)
if __name__=='__main__':main()
