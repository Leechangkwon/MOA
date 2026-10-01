#!/usr/bin/env python3
"""Deployment-runner regression against fresh baseline archives only; never production."""
import argparse, hashlib, importlib.util, io, json, os, shutil, stat, subprocess, tarfile
from pathlib import Path

BASELINE = 'a39ec1542412533df19a1a1f8fa703cc1ab47ab7'
F2_BASELINE = '7ef69bedaf5075c400f6faf8fd218297c609cc26'
F2 = ['src/Audit.php', 'src/Authz.php', 'src/FileRename.php', 'src/handlers/files_update.php', 'src/bootstrap.php']

def sha(data):
    return hashlib.sha256(data).hexdigest()

def fingerprint(root):
    result = {}
    for p in sorted(root.rglob('*')):
        if p.is_symlink():
            result[str(p.relative_to(root))] = ('link', os.readlink(p))
        elif p.is_file():
            info = p.stat()
            result[str(p.relative_to(root))] = (sha(p.read_bytes()), stat.S_IMODE(info.st_mode), info.st_uid, info.st_gid)
        elif p.is_dir():
            result[str(p.relative_to(root))] = ('dir', stat.S_IMODE(p.stat().st_mode))
        else:
            result[str(p.relative_to(root))] = ('nonregular', p.lstat().st_mode)
    return result

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    parser.add_argument('--package', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    source, package, output = [Path(x).resolve() for x in (args.source, args.package, args.output)]
    if any(str(p).startswith('/var/www') for p in (source, package, output)):
        raise SystemExit('Refuse any operating path')
    if output.exists() or not output.parent.is_dir():
        raise SystemExit('Output must be a new private directory with an existing parent')
    output.mkdir(mode=0o700)
    manifest = json.loads((package / 'manifest.json').read_text())
    pin = sha((package / 'manifest.json').read_bytes())
    baseline = output / 'baseline'
    baseline.mkdir()
    archive = subprocess.run(['git', '-C', str(source), 'archive', BASELINE], check=True, stdout=subprocess.PIPE).stdout
    with tarfile.open(fileobj=io.BytesIO(archive)) as tar:
        for item in tar.getmembers():
            dest = baseline / item.name
            if not dest.resolve().is_relative_to(baseline) or not (item.isdir() or item.isfile()):
                raise SystemExit('Unsafe baseline archive member')
        tar.extractall(baseline)
    for name in F2:
        old = subprocess.run(['git', '-C', str(source), 'show', F2_BASELINE + ':' + name], check=True, stdout=subprocess.PIPE).stdout
        (baseline / name).write_bytes(old)
    outcomes = []

    def check(name, condition):
        outcomes.append({'name': name, 'pass': bool(condition)})
        if not condition:
            raise AssertionError(name)

    def target(name):
        dest = output / name
        shutil.copytree(baseline, dest)
        return dest

    def invoke(dest, extra=(), pkg=package):
        command = ['python3', str(pkg / 'apply.py'), '--package', str(pkg), '--target', str(dest), *extra]
        result = subprocess.run(command, capture_output=True, text=True, timeout=20)
        with (output / 'commands.jsonl').open('a') as log:
            log.write(json.dumps({'command': command, 'code': result.returncode, 'stdout': result.stdout, 'stderr': result.stderr}) + '\n')
        return result

    def apply_args(backup, include_schema=True, include_pin=True):
        opts = ['--apply-files', '--backup', str(backup)]
        if include_schema:
            opts.append('--schema-confirmed')
        if include_pin:
            opts.extend(['--manifest-sha256', pin])
        return opts

    def refusal(name, dest, extra=(), pkg=package):
        before = fingerprint(dest)
        result = invoke(dest, extra, pkg)
        check(name + ': refused', result.returncode == 2 and 'REFUSED:' in result.stderr)
        check(name + ': destination unchanged', fingerprint(dest) == before)

    try:
        pristine = target('read-only')
        before = fingerprint(pristine)
        result = invoke(pristine)
        check('default verify succeeds', result.returncode == 0)
        data = json.loads(result.stdout)
        check('default verify reports all pending and no schema claim', data['pending'] == len(manifest['files']) and data['mode'] == 'read_only_verify' and data['schema'] == 'not_checked')
        check('default verify writes nothing to target', fingerprint(pristine) == before)
        refusal('schema acknowledgement required', pristine, apply_args(output / 'no-schema-backup', include_schema=False))
        check('missing schema creates no backup', not (output / 'no-schema-backup').exists())
        refusal('external manifest pin required', pristine, apply_args(output / 'no-pin-backup', include_pin=False))
        refusal('wrong external manifest pin', pristine, ['--manifest-sha256', '0' * 64])
        refusal('backup under target rejected', pristine, apply_args(pristine / 'forbidden-backup'))
        existing = next(row for row in manifest['files'] if row['old_sha256'] is not None)
        drift = target('drift')
        (drift / existing['path']).write_bytes(b'unreviewed operator change\n')
        refusal('drift before any writes', drift, apply_args(output / 'drift-backup'))
        check('drift creates no backup', not (output / 'drift-backup').exists())
        f2 = target('f2-drift')
        (f2 / 'src/FileRename.php').write_bytes(b'preserve existing F2 patch\n')
        refusal('F2 guard drift', f2)
        linked = target('symlink-file')
        outside = output / 'outside.txt'
        outside.write_text('outside untouched')
        (linked / existing['path']).unlink()
        (linked / existing['path']).symlink_to(outside)
        refusal('symlink file', linked)
        check('symlink outside content unchanged', outside.read_text() == 'outside untouched')
        parent_link = target('symlink-parent')
        (parent_link / 'public').rename(parent_link / 'public-original')
        (parent_link / 'public').symlink_to(parent_link / 'public-original', target_is_directory=True)
        refusal('symlink parent', parent_link)
        fifo = target('fifo')
        (fifo / existing['path']).unlink()
        os.mkfifo(fifo / existing['path'])
        refusal('FIFO promptly rejected', fifo)
        special = target('special-mode')
        (special / existing['path']).chmod(0o4644)
        refusal('special mode refuses apply', special, apply_args(output / 'special-backup'))

        def mutable_package(name):
            copy = output / name
            shutil.copytree(package, copy)
            for p in [copy, *copy.rglob('*')]:
                p.chmod(0o755 if p.is_dir() else 0o644)
            return copy

        corrupt = mutable_package('corrupt-payload')
        item = corrupt / 'payload' / manifest['files'][0]['path']
        item.write_bytes(item.read_bytes() + b'\ntamper\n')
        refusal('payload tamper', pristine, pkg=corrupt)
        unsafe = mutable_package('unsafe-manifest')
        altered = json.loads((unsafe / 'manifest.json').read_text())
        altered['files'][0]['path'] = '../outside.txt'
        raw = (json.dumps(altered) + '\n').encode()
        (unsafe / 'manifest.json').write_bytes(raw)
        (unsafe / 'manifest.sha256').write_text(sha(raw) + '  manifest.json\n')
        refusal('traversal even with consistent checksum', pristine, pkg=unsafe)
        spec = importlib.util.spec_from_file_location('stage89_apply', package / 'apply.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        for name in ['.env', '/etc/passwd', 'src/../.env', 'src/storage/item.php', 'public/uploads/item.php', 'src/link\\x.php']:
            try:
                module.relpath(name)
            except module.Refusal:
                check('path rejected: ' + name, True)
            else:
                check('path rejected: ' + name, False)
        check('extensionless cron source allowed', module.relpath('deploy/cron.d/tbox-v2') == ['deploy', 'cron.d', 'tbox-v2'])
        untracked = subprocess.run(['git', '-C', str(source), 'ls-files', '--others', '--exclude-standard'], check=True, capture_output=True, text=True).stdout.splitlines()
        if any(n.split('/')[0] in module.TOPS and not n.startswith('tests/') for n in untracked):
            prepared = subprocess.run(['python3', str(package / 'apply.py'), '--prepare', '--source', str(source), '--package', str(output / 'incomplete-default')], capture_output=True, text=True)
            check('default prepare refuses untracked deployment files', prepared.returncode == 2 and '--files-from' in prepared.stderr)
            check('incomplete prepare creates no package', not (output / 'incomplete-default').exists())
        else:
            outcomes.append({'name': 'default prepare untracked guard', 'skip': 'source is fully tracked; guard covered by candidate run'})
        applied = target('apply')
        (applied / existing['path']).chmod(0o640)
        original = fingerprint(applied)
        backup = output / 'apply-backup'
        result = invoke(applied, apply_args(backup))
        check('first apply succeeds', result.returncode == 0)
        check('first apply changes selected files only', json.loads(result.stdout)['changed'] == len(manifest['files']))
        journal = json.loads((backup / 'journal.json').read_text())
        check('backup root is private', stat.S_IMODE(backup.stat().st_mode) == 0o700)
        check('journal is private and complete', stat.S_IMODE((backup / 'journal.json').stat().st_mode) == 0o600 and journal['complete'] and set(journal['applied']) == {r['path'] for r in manifest['files']})
        check('all new payload hashes match', all(sha((applied / row['path']).read_bytes()) == row['new_sha256'] for row in manifest['files']))
        check('all old backup hashes match', all(sha((backup / 'files' / row['path']).read_bytes()) == row['old_sha256'] for row in manifest['files'] if row['old_sha256'] is not None))
        check('existing modes and owners preserved', all(fingerprint(applied)[r['path']][1:] == original[r['path']][1:] for r in manifest['files'] if r['old_sha256'] is not None))
        check('new modes from manifest', all(stat.S_IMODE((applied / r['path']).stat().st_mode) == r['new_mode'] for r in manifest['files'] if r['old_sha256'] is None))
        names = {r['path'] for r in manifest['files']}
        after = fingerprint(applied)
        check('all unrelated baseline files preserved', all(after.get(name) == data for name, data in original.items() if name not in names))
        check('no unfinished replacement temporary files', not list(applied.rglob('.stage89-*')))
        second = invoke(applied, apply_args(output / 'second-backup'))
        check('second apply succeeds with zero changes', second.returncode == 0 and json.loads(second.stdout)['changed'] == 0)
        check('second apply creates no backup and no mutations', not (output / 'second-backup').exists() and fingerprint(applied) == after)
        partial = target('partial')
        first = manifest['files'][0]
        destination = partial / first['path']
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes((package / 'payload' / first['path']).read_bytes())
        resume = invoke(partial, apply_args(output / 'partial-backup'))
        check('partly applied package resumes only remaining files', resume.returncode == 0 and json.loads(resume.stdout)['changed'] == len(manifest['files']) - 1)
        check('resumed package hashes match', all(sha((partial / row['path']).read_bytes()) == row['new_sha256'] for row in manifest['files']))
    finally:
        result = {'passed': sum(x.get('pass') is True for x in outcomes), 'failed': sum(x.get('pass') is False for x in outcomes), 'skipped': sum('skip' in x for x in outcomes), 'manifest_sha256': pin, 'files': len(manifest['files']), 'operating_mutations': False, 'cases': outcomes}
        (output / 'results.json').write_text(json.dumps(result, indent=2) + '\n')
        print(json.dumps(result))

if __name__ == '__main__':
    main()
