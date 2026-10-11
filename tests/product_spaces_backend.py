"""Dedicated empty Postgres database only; no production database/content reads."""
import json,os,subprocess,tempfile,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
out=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/product-spaces-backend');out.mkdir(parents=True,exist_ok=True)
pgpass=Path.home()/'.config/qaredi/pgpass-testdb';password=next(line.split(':',4)[4] for line in pgpass.read_text().splitlines() if len(line.split(':',4))==5 and line.split(':',4)[3]=='tbox_testdb')
db='tbox_product_test_'+str(os.getpid());env=dict(os.environ,PGPASSFILE=str(pgpass));opts=['-h','127.0.0.1','-U','tbox_testdb','-w']
def call(tool,args,**kw):
 result=subprocess.run([tool,*opts,*args],env=env,capture_output=True,cwd=ROOT/'db',**kw)
 if result.returncode:raise RuntimeError(result.stderr.decode())
 return result
def sql(text):return call('psql',['-X','-d',db,'-v','ON_ERROR_STOP=1'],input=text.encode())
created=False
try:
 call('createdb',['-O','tbox_testdb',db]);created=True
 logs=[sql((ROOT/'db/schema.sql').read_text()).stdout]
 migration=(ROOT/'db/migrations/2026-10-11_product_spaces.sql').read_text().replace('SET LOCAL ROLE tbox_owner;','SET LOCAL ROLE tbox_testdb;').replace('OWNER TO tbox_owner;','OWNER TO tbox_testdb;')
 for _ in range(2):logs.append(sql(migration).stdout)
 # Retained exact matrix only: no original teams/accounts.
 sql((ROOT/'db/seed.sql').read_text().split('-- 팀 2개')[0])
 runenv=dict(os.environ,PRODUCT_TEST_DB=db,PRODUCT_TEST_PASS=password)
 result=subprocess.run(['php',str(ROOT/'tests/product_spaces_backend.php')],env=runenv,capture_output=True,text=True)
 (out/'test.log').write_text(result.stdout+result.stderr);(out/'schema.log').write_bytes(b''.join(logs));print(result.stdout);print(result.stderr,file=sys.stderr)
 if result.returncode:sys.exit(result.returncode)
finally:
 if created:call('dropdb',[db])
