#!/usr/bin/env python3
"""D2a counter acceptance tests in a new, socket-only PostgreSQL cluster.

No existing cluster, DB, credentials, or source configuration is used. Python
stdlib and the installed PostgreSQL tools are sufficient. Runtime instrumentation
records spaces UPDATE statements/rows and briefly pauses the first board write;
the candidate migration/function is applied verbatim.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import queue
import shutil
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[2]
MIGRATION = ROOT / 'db/migrations/2026-10-26_scale_d2a_board_counts.sql'
SCHEMA = ROOT / 'db/community/schema.sql'
DATABASE = 'd2a_counter_acceptance'
ROLE = 'd2a_counter_owner'
APP_ROLE = 'd2a_counter_app'
PORT = '55491'


class Cluster:
    def __init__(self, output):
        self.output = output
        self.work = Path(tempfile.mkdtemp(prefix='qaredi-d2a-counter-'))
        self.bin = Path(subprocess.check_output(['pg_config', '--bindir'], text=True).strip())
        self.env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
        self.env.update(PGPASSFILE=str(self.work / 'no-password-file'), PGAPPNAME='d2a-counter-acceptance')
        self.started = False
        self.sessions = []

    def run(self, args, **kwargs):
        return subprocess.run([str(x) for x in args], env=self.env, text=True, capture_output=True, check=True, **kwargs)

    def command(self, database=DATABASE):
        return [str(self.bin / 'psql'), '-X', '-qAt', '-h', str(self.work), '-p', PORT,
                '-U', ROLE, '-d', database, '-v', 'ON_ERROR_STOP=1']

    def sql(self, sql, database=DATABASE):
        return self.run(self.command(database), input=sql, timeout=20).stdout.strip()

    def start(self):
        self.run([self.bin / 'initdb', '-D', self.work / 'cluster', '-U', ROLE,
                  '--auth-local=trust', '--auth-host=reject', '--no-locale', '--encoding=UTF8'], timeout=30)
        self.run([self.bin / 'pg_ctl', '-D', self.work / 'cluster', '-l', self.work / 'postgres.log',
                  '-o', "-c listen_addresses='' -c unix_socket_directories=" + str(self.work)
                  + ' -p ' + PORT + ' -c max_connections=10 -c shared_buffers=16MB -c deadlock_timeout=100ms',
                  '-w', 'start'], timeout=30)
        self.started = True
        self.sql('CREATE DATABASE ' + DATABASE, 'postgres')

    def close(self):
        for session in self.sessions:
            if session.process.poll() is None:
                session.process.terminate()
                session.process.wait(timeout=5)
        cleaned = {'database_removed': False, 'cluster_stopped': False, 'directory_removed': False}
        if self.started:
            self.sql('DROP DATABASE ' + DATABASE + ' WITH (FORCE)', 'postgres')
            cleaned['database_removed'] = self.sql(
                "SELECT count(*) FROM pg_database WHERE datname='" + DATABASE + "'", 'postgres') == '0'
            stopped = self.run([self.bin / 'pg_ctl', '-D', self.work / 'cluster', '-m', 'fast', '-w', 'stop'], timeout=20)
            cleaned['cluster_stopped'] = stopped.returncode == 0
            shutil.copy2(self.work / 'postgres.log', self.output / 'postgres.log')
        if not self.started or cleaned['cluster_stopped']:
            shutil.rmtree(self.work)
            cleaned['directory_removed'] = not self.work.exists()
        return cleaned


class Session:
    def __init__(self, cluster, name):
        self.name = name
        self.process = subprocess.Popen(cluster.command(), env=cluster.env, text=True, stdin=subprocess.PIPE,
                                        stdout=subprocess.PIPE, stderr=subprocess.STDOUT, bufsize=1)
        self.lines = []
        self.events = queue.Queue()
        self.reader = threading.Thread(target=self.read, daemon=True)
        self.reader.start()
        cluster.sessions.append(self)

    def read(self):
        for line in self.process.stdout:
            self.lines.append(line.rstrip())
            self.events.put(line.strip())

    def send(self, sql):
        self.process.stdin.write(sql + '\n')
        self.process.stdin.flush()

    def until(self, marker):
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if self.events.get(timeout=max(.01, deadline - time.monotonic())) == marker:
                return
        raise RuntimeError('Missing session marker: ' + marker)

    def finish(self):
        self.process.stdin.close()
        code = self.process.wait(timeout=20)
        self.reader.join(timeout=2)
        return {'name': self.name, 'exit': code, 'output': self.lines}


CREATE = """
CREATE ROLE d2a_counter_app;
CREATE SCHEMA community;
CREATE TABLE community.spaces(id bigint PRIMARY KEY, kind text NOT NULL);
CREATE TABLE community.pages(
 id bigint PRIMARY KEY, space_id bigint NOT NULL REFERENCES community.spaces(id),
 parent_page_id bigint REFERENCES community.pages(id), visibility text NOT NULL DEFAULT 'private',
 published_board_id bigint REFERENCES community.spaces(id), published_from text,
 deleted_at timestamptz, deleted_by bigint, hidden_at timestamptz, purged_at timestamptz,
 purge_after timestamptz, updated_at timestamptz DEFAULT now(), position integer DEFAULT 0,
 doc jsonb DEFAULT '{}'::jsonb, title text DEFAULT 'synthetic');
GRANT USAGE ON SCHEMA community TO d2a_counter_app;
GRANT SELECT,INSERT,UPDATE,DELETE ON community.spaces,community.pages TO d2a_counter_app;
INSERT INTO community.spaces VALUES(1,'personal'),(2,'personal'),(101,'board'),(102,'board');
INSERT INTO community.pages(id,space_id) VALUES(10,1),(20,2),(31,101),(32,102);
INSERT INTO community.pages(id,space_id,parent_page_id,visibility,published_board_id,published_from)
 VALUES(11,1,10,'published',101,'private'),(12,1,10,'published',102,'private'),
       (21,2,20,'published',102,'private'),(22,2,20,'published',101,'private');
"""

# Observation only: no advisory locks, new cross-transaction dependencies, or
# changes to the candidate trigger. Sleep merely permits another writer to enter.
SPY = """
CREATE TABLE community.review_space_writes(kind text,board_id bigint,backend integer DEFAULT pg_backend_pid());
GRANT SELECT,INSERT ON community.review_space_writes TO d2a_counter_app;
CREATE FUNCTION community.review_space_row() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO community.review_space_writes(kind,board_id) VALUES('row',NEW.id);
 IF current_setting('d2a.test_pause',true)='on' AND NEW.id=101 THEN
   RAISE NOTICE 'FIRST_BOARD_LOCKED'; PERFORM pg_sleep(0.4);
 END IF;
 RETURN NULL;
END $$;
CREATE TRIGGER review_space_row AFTER UPDATE ON community.spaces FOR EACH ROW EXECUTE FUNCTION community.review_space_row();
CREATE FUNCTION community.review_space_statement() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN INSERT INTO community.review_space_writes(kind) VALUES('statement'); RETURN NULL; END $$;
CREATE TRIGGER review_space_statement AFTER UPDATE ON community.spaces FOR EACH STATEMENT EXECUTE FUNCTION community.review_space_statement();
"""

LIVE = """
SELECT b.id,b.post_count,(SELECT count(*) FROM community.pages p WHERE p.deleted_at IS NULL AND p.hidden_at IS NULL
 AND(p.space_id=b.id OR(p.visibility='published' AND p.published_board_id=b.id)))
FROM community.spaces b WHERE kind='board' ORDER BY b.id
"""


def delete_tree(root):
    # Same single recursive UPDATE as handlers/pages_delete.php.
    return """WITH RECURSIVE tree AS (
      SELECT id FROM community.pages WHERE id=%d AND deleted_at IS NULL
      UNION ALL SELECT c.id FROM community.pages c JOIN tree t ON c.parent_page_id=t.id WHERE c.deleted_at IS NULL)
      UPDATE community.pages SET deleted_at=now(),deleted_by=1,purge_after=now()+interval '30 days',updated_at=now()
      WHERE id IN(SELECT id FROM tree);""" % root


def restore_tree(root):
    # Same single recursive UPDATE as handlers/page_restore.php.
    return """WITH RECURSIVE down AS (
      SELECT id,deleted_at FROM community.pages WHERE id=%d AND deleted_at IS NOT NULL AND purged_at IS NULL
      UNION ALL SELECT c.id,d.deleted_at FROM community.pages c JOIN down d ON c.parent_page_id=d.id
      WHERE c.deleted_at=d.deleted_at AND c.purged_at IS NULL)
      UPDATE community.pages SET deleted_at=NULL,deleted_by=NULL,purge_after=NULL,updated_at=now()
      WHERE id IN(SELECT id FROM down) AND purged_at IS NULL;""" % root


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    output = args.output.resolve()
    if output == ROOT or ROOT in output.parents:
        raise SystemExit('Output must be outside the repository')
    if os.geteuid() == 0:
        raise SystemExit('Run as an unprivileged user; initdb does not support root')
    output.mkdir(mode=0o700, parents=True, exist_ok=True)
    result = {'migration_sha256': hashlib.sha256(MIGRATION.read_bytes()).hexdigest(), 'checks': []}
    cluster = Cluster(output)

    def check(name, ok, **detail):
        result['checks'].append({'name': name, 'passed': bool(ok), **detail})
        print(('PASS ' if ok else 'FAIL ') + name, flush=True)
        if not ok:
            raise AssertionError(name)

    def equal(name):
        rows = [r.split('|') for r in cluster.sql(LIVE).splitlines()]
        check(name, all(row[1] == row[2] for row in rows)
              and cluster.sql("SELECT count(*) FROM community.spaces WHERE kind<>'board' AND post_count<>0") == '0', rows=rows)

    def pair(name, first, second):
        one, two = Session(cluster, name + '-one'), Session(cluster, name + '-two')
        prefix = 'SET ROLE ' + APP_ROLE + "; SET d2a.test_pause='on'; BEGIN; "
        one.send(prefix + first + ' COMMIT;')
        one.until('NOTICE:  FIRST_BOARD_LOCKED')
        two.send(prefix + second + ' COMMIT;')
        outputs = [one.finish(), two.finish()]
        check(name, all(item['exit'] == 0 for item in outputs), sessions=outputs)
        equal(name + ': counters match')

    try:
        cluster.start()
        result['isolation'] = cluster.sql("SELECT current_database(),current_user,pg_get_userbyid(datdba),"
            "current_setting('data_directory'),current_setting('listen_addresses') FROM pg_database WHERE datname=current_database()")
        cluster.sql(CREATE)
        before_acl = cluster.sql("SELECT relname,relowner,relacl FROM pg_class WHERE oid IN('community.spaces'::regclass,'community.pages'::regclass) ORDER BY relname")
        cluster.run(cluster.command() + ['-f', MIGRATION], timeout=20)
        check('table owners and grants preserved', before_acl == cluster.sql("SELECT relname,relowner,relacl FROM pg_class WHERE oid IN('community.spaces'::regclass,'community.pages'::regclass) ORDER BY relname"))
        definition = MIGRATION.read_text().split('CREATE OR REPLACE FUNCTION community.scale_board_post_count()', 1)[1].split('-- 한 번 채움', 1)[0]
        schema_definition = SCHEMA.read_text().split('CREATE OR REPLACE FUNCTION community.scale_board_post_count()', 1)[1].split('CREATE INDEX IF NOT EXISTS pages_space_updated_idx', 1)[0]
        check('fresh schema and migration function/triggers identical', definition == schema_definition)
        security = cluster.sql("SELECT prosecdef,proconfig,has_function_privilege('public','community.scale_board_post_count()','EXECUTE') FROM pg_proc WHERE oid='community.scale_board_post_count()'::regprocedure")
        check('invoker, fixed search path and PUBLIC execute revoked', security == 'f|{search_path=pg_catalog}|f', actual=security)
        equal('initial migration backfill')
        cluster.sql(SPY)
        pair('single statements swap boards', 'UPDATE community.pages SET space_id=102 WHERE id=31;',
             'UPDATE community.pages SET space_id=101 WHERE id=32;')
        pair('handler-shaped concurrent subtree delete', delete_tree(10), delete_tree(20))
        check('both subtree deletions committed', cluster.sql('SELECT count(*) FROM community.pages WHERE id IN(10,11,12,20,21,22) AND deleted_at IS NOT NULL') == '6')
        pair('handler-shaped concurrent subtree restore', restore_tree(10), restore_tree(20))
        check('both subtree restorations committed', cluster.sql('SELECT count(*) FROM community.pages WHERE id IN(10,11,12,20,21,22) AND deleted_at IS NULL') == '6')

        workers = [Session(cluster, 'inserts-one'), Session(cluster, 'inserts-two')]
        for worker, start in zip(workers, (1000, 2000)):
            worker.send('SET ROLE ' + APP_ROLE + ';\n' + '\n'.join(
                "INSERT INTO community.pages(id,space_id,title) VALUES(%d,101,'d2a-concurrent');" % number
                for number in range(start, start + 200)))
        workers_result = [worker.finish() for worker in workers]
        count = cluster.sql("SELECT count(*) FROM community.pages WHERE title='d2a-concurrent'")
        check('two concurrent writers commit exactly 400 inserts', count == '400' and all(w['exit'] == 0 for w in workers_result), count=count, sessions=workers_result)
        equal('400 inserted rows counted')

        xmin_sql = "SELECT string_agg(id||':'||xmin::text,',' ORDER BY id) FROM community.spaces"
        before_xmin = cluster.sql(xmin_sql)
        cluster.sql('TRUNCATE community.review_space_writes')
        cluster.sql("SET ROLE " + APP_ROLE + "; UPDATE community.pages SET doc=jsonb_build_object('schema',1,'blocks','[]'::jsonb),title=title||' saved' WHERE id=11;"
                    "UPDATE community.pages SET doc='{}',title=title||' bulk saved' WHERE id BETWEEN 1000 AND 1199;"
                    "UPDATE community.pages SET position=position+1,parent_page_id=parent_page_id WHERE id IN(11,12);"
                    "UPDATE community.pages SET published_from='team' WHERE id IN(11,12);")
        writes = cluster.sql('SELECT count(*) FROM community.review_space_writes')
        check('autosave, position and published_from: zero counter UPDATE statements/rows and unchanged xmin',
              before_xmin == cluster.sql(xmin_sql) and writes == '0', recorded_writes=writes)
        equal('non-count fields leave counts unchanged')

        cluster.sql("SET ROLE " + APP_ROLE + "; INSERT INTO community.pages(id,space_id,visibility,published_board_id) VALUES(301,101,'published',101);")
        equal('same board counted once')
        for label, statement in [
            ('hide', 'UPDATE community.pages SET hidden_at=now() WHERE id=301'),
            ('hide and delete', 'UPDATE community.pages SET deleted_at=now() WHERE id=301'),
            ('restore both flags', 'UPDATE community.pages SET hidden_at=NULL,deleted_at=NULL WHERE id=301'),
            ('unpublish', "UPDATE community.pages SET visibility='private',published_board_id=NULL WHERE id=301"),
            ('hard delete', 'DELETE FROM community.pages WHERE id=301'),
        ]:
            cluster.sql('SET ROLE ' + APP_ROLE + '; ' + statement)
            equal(label)

        # An already added column and the counter data survive a second application.
        before_xmin = cluster.sql(xmin_sql)
        cluster.sql('TRUNCATE community.review_space_writes')
        cluster.run(cluster.command() + ['-f', MIGRATION], timeout=20)
        check('migration reapply changes no counter rows', before_xmin == cluster.sql(xmin_sql)
              and cluster.sql("SELECT count(*) FROM community.review_space_writes WHERE kind='row'") == '0')
        equal('reapply counts')

        # Add a marker/sleep AFTER the real backfill, before its final COMMIT.
        # The migration on disk is unmodified; all acquired locks remain observable.
        migration_text = MIGRATION.read_text()
        prefix, suffix = migration_text.rsplit('COMMIT;', 1)
        cluster.sql('UPDATE community.spaces SET post_count=0 WHERE post_count<>0')
        migration = Session(cluster, 'migration-read-probe')
        migration.send(prefix + "SELECT 'BACKFILL_HELD'; SELECT pg_sleep(1); COMMIT;" + suffix)
        migration.until('BACKFILL_HELD')
        locks = cluster.sql("SELECT c.relname,l.mode FROM pg_locks l JOIN pg_class c ON c.oid=l.relation "
            "WHERE c.oid IN('community.spaces'::regclass,'community.pages'::regclass) AND l.granted ORDER BY c.relname,l.mode")
        reads = cluster.sql("SET lock_timeout='300ms'; SELECT count(*) FROM community.spaces; SELECT count(*) FROM community.pages")
        outcome = migration.finish()
        check('backfill permits concurrent spaces/pages reads and holds no ACCESS EXCLUSIVE',
              outcome['exit'] == 0 and 'AccessExclusiveLock' not in locks and len(reads.splitlines()) == 2,
              locks=locks, reads=reads, session=outcome)
        equal('backfill repair counts')

        # Deliberately outside the one-counter-changing-statement application
        # paths: arbitrary multi-statement transactions still need global order.
        cluster.sql('INSERT INTO community.pages(id,space_id) VALUES(501,101),(502,102),(503,102),(504,101)')
        one, two = Session(cluster, 'residual-one'), Session(cluster, 'residual-two')
        one.send("BEGIN; UPDATE community.pages SET deleted_at=now() WHERE id=501; SELECT 'ONE_READY';")
        two.send("BEGIN; UPDATE community.pages SET deleted_at=now() WHERE id=503; SELECT 'TWO_READY';")
        one.until('ONE_READY')
        two.until('TWO_READY')
        one.send('UPDATE community.pages SET deleted_at=now() WHERE id=502; COMMIT;')
        two.send('UPDATE community.pages SET deleted_at=now() WHERE id=504; COMMIT;')
        residual = [one.finish(), two.finish()]
        check('documented residual: arbitrary reverse multi-statement transactions can deadlock',
              sorted(s['exit'] for s in residual) == [0, 3] and any('deadlock detected' in line for s in residual for line in s['output']), sessions=residual)
        equal('residual failure rolls back without count drift')
    except Exception as error:
        result['error'] = repr(error)
        raise
    finally:
        result['cleanup'] = cluster.close()
        result['migration_unchanged_during_test'] = result['migration_sha256'] == hashlib.sha256(MIGRATION.read_bytes()).hexdigest()
        result['passed'] = sum(check['passed'] for check in result['checks'])
        result['failed'] = sum(not check['passed'] for check in result['checks']) + int('error' in result)
        (output / 'result.json').write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
        print('D2A_COUNTER_LOCK pass=%d fail=%d cleanup=%s' % (result['passed'], result['failed'], json.dumps(result['cleanup'])), flush=True)
    if not all(result['cleanup'].values()):
        raise SystemExit('Temporary cluster cleanup incomplete')


if __name__ == '__main__':
    main()
