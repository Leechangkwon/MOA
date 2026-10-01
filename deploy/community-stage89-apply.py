#!/usr/bin/env python3
"""Immutable stage8/9 file package. Default verify never writes target; no DB/cron/service/Git writes."""
import argparse, fcntl, hashlib, json, os, re, stat, subprocess, sys, uuid
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath

BASELINE='7ef69bedaf5075c400f6faf8fd218297c609cc26'
OPERATING_BASELINE='a39ec1542412533df19a1a1f8fa703cc1ab47ab7'
ROOT_FILES={'VERSION','CHANGELOG.json','CHANGELOG.md','SECURITY.md','ARCHITECTURE.md','STAGES.md','README.md','API.md','DESIGN.md','PRD.md'}
TOPS={'src','public','db','scripts','deploy','docs','wiki'}
EXTS={'.php','.js','.mjs','.css','.json','.md','.sql','.sh','.py','.svg','.png','.txt','.html','.webmanifest','.patch'}
F2=['src/Audit.php','src/Authz.php','src/FileRename.php','src/handlers/files_update.php','src/bootstrap.php']
SHA=re.compile(r'[0-9a-f]{64}\Z')

class Refusal(RuntimeError):pass

def digest(data):return hashlib.sha256(data).hexdigest()
def canonical(value):return (json.dumps(value,ensure_ascii=False,indent=2,sort_keys=True)+'\n').encode()
def relpath(name):
    if not isinstance(name,str) or not name or '\\' in name:raise Refusal('Invalid relative path')
    parts=name.split('/')
    if any(not re.fullmatch(r'[A-Za-z0-9_-][A-Za-z0-9_.-]*',s) or s in ('.','..') for s in parts):raise Refusal('Unsafe path: '+name)
    if len(parts)==1:
        if name not in ROOT_FILES:raise Refusal('Unapproved root file: '+name)
    elif parts[0] not in TOPS or (PurePosixPath(name).suffix not in EXTS and name!='deploy/cron.d/tbox-v2'):raise Refusal('Unapproved file: '+name)
    if any(s.lower() in {'storage','uploads','logs','secrets','credentials','node_modules','__pycache__'} for s in parts):raise Refusal('Excluded data directory: '+name)
    return parts

def checked_root(value):
    raw=Path(value)
    if not raw.is_absolute() or '..' in raw.parts:raise Refusal('Use an absolute non-traversing root')
    now=Path(raw.anchor)
    for piece in raw.parts[1:]:
        now=now/piece
        mode=os.lstat(now).st_mode
        if stat.S_ISLNK(mode) or not stat.S_ISDIR(mode):raise Refusal('Root/ancestor is not a real directory: '+str(now))
    return raw

def parent_fd(root,parts,create=False,mode=0o755):
    fd=os.open(str(root),os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        for piece in parts[:-1]:
            if create:
                try:os.mkdir(piece,mode,dir_fd=fd)
                except FileExistsError:pass
            new=os.open(piece,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW,dir_fd=fd)
            os.close(fd);fd=new
        return fd
    except BaseException:os.close(fd);raise

def read_at(root,name,missing=False):
    parts=name.split('/')
    try:fd=parent_fd(root,parts)
    except FileNotFoundError:
        if missing:return None,None
        raise
    try:
        try:src=os.open(parts[-1],os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK,dir_fd=fd)
        except FileNotFoundError:
            if missing:return None,None
            raise
        try:
            info=os.fstat(src)
            if not stat.S_ISREG(info.st_mode):raise Refusal('Not a regular file: '+name)
            with os.fdopen(src,'rb',closefd=False) as stream:data=stream.read()
            return data,info
        finally:os.close(src)
    finally:os.close(fd)

def atomic_at(root,name,data,mode,uid=None,gid=None):
    parts=name.split('/');fd=parent_fd(root,parts,True)
    temporary='.stage89-'+uuid.uuid4().hex
    try:
        handle=os.open(temporary,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600,dir_fd=fd)
        try:
            with os.fdopen(handle,'wb',closefd=False) as stream:stream.write(data);stream.flush()
            if uid is not None:os.fchown(handle,uid,gid)
            os.fchmod(handle,mode);os.fsync(handle)
        finally:os.close(handle)
        os.replace(temporary,parts[-1],src_dir_fd=fd,dst_dir_fd=fd);os.fsync(fd)
    finally:
        try:os.unlink(temporary,dir_fd=fd)
        except FileNotFoundError:pass
        os.close(fd)

def git(source,*args):
    return subprocess.run(['git','-C',str(source),*args],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE).stdout

def baseline_blob(source,baseline,name):
    row=git(source,'ls-tree','-z',baseline,'--',name)
    if not row:return None
    head,path=row.rstrip(b'\0').split(b'\t',1)
    mode,kind,oid=head.split()
    if kind!=b'blob' or mode not in (b'100644',b'100755') or path.decode()!=name:raise Refusal('Unsupported baseline object: '+name)
    return git(source,'cat-file','blob',oid.decode())

def prepare(args):
    source=checked_root(args.source)
    if args.baseline!=BASELINE:raise Refusal('Unexpected baseline; review a changed baseline explicitly in code')
    if git(source,'rev-parse',BASELINE+'^{commit}').decode().strip()!=BASELINE:raise Refusal('Missing baseline')
    package=Path(args.package)
    if not package.is_absolute() or '..' in package.parts or package.exists() or package.is_symlink():raise Refusal('Package must be a new absolute directory')
    checked_root(package.parent)
    tracked=set(git(source,'diff','--name-only','-z',OPERATING_BASELINE,'--').decode().rstrip('\0').split('\0'))-{''}
    if args.files_from:
        names=json.loads(Path(args.files_from).read_text())
        if not isinstance(names,list) or any(not isinstance(x,str) for x in names):raise Refusal('files-from must be an explicit JSON path list')
        selection='explicit reviewed candidate file list'
    else:
        untracked=git(source,'ls-files','--others','--exclude-standard','-z').decode().rstrip('\0').split('\0')
        if any(n and not n.startswith('tests/') and (n.split('/')[0] in TOPS or n in ROOT_FILES) for n in untracked):
            raise Refusal('Untracked deployment files require --files-from with a reviewed explicit list')
        names=sorted(tracked);selection='tracked diff from operating baseline; F2 five-file override'
    names=[n for n in names if not n.startswith('tests/')]
    if len(names)!=len(set(names)) or len(names)>500:raise Refusal('Duplicate or excessive file list')
    entries=[];payload={}
    for name in sorted(names):
        relpath(name)
        data,info=read_at(source,name)
        old=baseline_blob(source,BASELINE if name in F2 else OPERATING_BASELINE,name)
        if old==data:continue
        payload[name]=data
        entries.append(dict(path=name,old_sha256=None if old is None else digest(old),new_sha256=digest(data),new_mode=0o755 if info.st_mode&0o111 else 0o644))
    if not entries:raise Refusal('No files selected')
    guards={name:digest(baseline_blob(source,BASELINE,name)) for name in F2}
    runner=Path(__file__).read_bytes()
    manifest=dict(format=1,state='candidate_not_deployed',baseline=BASELINE,operating_baseline=OPERATING_BASELINE,source_head=git(source,'rev-parse','HEAD').decode().strip(),
        created_at=datetime.now(timezone.utc).isoformat(),selection=selection,runner_sha256=digest(runner),guards=guards,files=entries,
        schema_prerequisite='Operator must confirm trust + automation migrations and grants before applying files; this tool runs no SQL.',
        rollback_warning='No automatic rollback. After stage8 button documents exist, old code may reject those documents; preserve DB and choose a compatible forward fix.')
    # Recheck all source bytes before creating the immutable candidate.
    for name,data in payload.items():
        if read_at(source,name)[0]!=data:raise Refusal('Source changed during prepare: '+name)
    package.mkdir(mode=0o700)
    (package/'payload').mkdir(mode=0o700)
    for name,data in payload.items():atomic_at(package/'payload',name,data,0o444)
    atomic_at(package,'apply.py',runner,0o444)
    raw=canonical(manifest);atomic_at(package,'manifest.json',raw,0o444)
    atomic_at(package,'manifest.sha256',(digest(raw)+'  manifest.json\n').encode(),0o444)
    for folder in sorted((p for p in package.rglob('*') if p.is_dir()),key=lambda x:len(x.parts),reverse=True):folder.chmod(0o555)
    package.chmod(0o555)
    print(json.dumps({'prepared':str(package),'files':len(entries),'manifest_sha256':digest(raw),'state':'candidate_not_deployed'}))

def no_duplicates(pairs):
    result={}
    for key,value in pairs:
        if key in result:raise Refusal('Duplicate manifest key')
        result[key]=value
    return result

def inspect(args):
    package=checked_root(args.package);target=checked_root(args.target)
    raw,_=read_at(package,'manifest.json');manifest_hash=digest(raw)
    printed,_=read_at(package,'manifest.sha256')
    if printed.decode()!=manifest_hash+'  manifest.json\n':raise Refusal('Manifest checksum mismatch')
    if args.manifest_sha256 and args.manifest_sha256!=manifest_hash:raise Refusal('Unexpected manifest checksum')
    manifest=json.loads(raw,object_pairs_hook=no_duplicates)
    if not isinstance(manifest,dict):raise Refusal('Manifest must be an object')
    if manifest.get('format')!=1 or manifest.get('baseline')!=BASELINE or manifest.get('operating_baseline')!=OPERATING_BASELINE:raise Refusal('Unsupported package baseline/format')
    runner,_=read_at(package,'apply.py')
    if digest(runner)!=manifest.get('runner_sha256') or digest(Path(__file__).read_bytes())!=digest(runner):raise Refusal('Runner checksum mismatch; use package/apply.py')
    entries=manifest.get('files')
    if not isinstance(entries,list) or not 1<=len(entries)<=500:raise Refusal('Bad files manifest')
    seen=set();states=[]
    for row in entries:
        if not isinstance(row,dict) or set(row)!={'path','old_sha256','new_sha256','new_mode'}:raise Refusal('Invalid entry shape')
        name=row['path'];relpath(name)
        if name in seen:raise Refusal('Duplicate path');
        seen.add(name)
        if not isinstance(row['new_sha256'],str) or not SHA.fullmatch(row['new_sha256']):raise Refusal('Invalid new hash')
        if row['old_sha256'] is not None and (not isinstance(row['old_sha256'],str) or not SHA.fullmatch(row['old_sha256'])):raise Refusal('Invalid old hash')
        if type(row['new_mode']) is not int or row['new_mode'] not in (0o644,0o755):raise Refusal('Unsafe mode')
        content,_=read_at(package/'payload',name)
        if digest(content)!=row['new_sha256']:raise Refusal('Payload checksum mismatch: '+name)
        old,info=read_at(target,name,True);now=None if old is None else digest(old)
        if now==row['new_sha256']:state='already_applied'
        elif now==row['old_sha256']:state='pending'
        else:raise Refusal('Destination drift: '+name)
        states.append((row,state,info))
    guards=manifest.get('guards')
    if not isinstance(guards,dict) or set(guards)!=set(F2):raise Refusal('F2 baseline guards missing')
    by_name={row['path']:row for row in entries}
    for name,expected in guards.items():
        if not isinstance(expected,str) or not SHA.fullmatch(expected):raise Refusal('Bad F2 guard hash')
        current,_=read_at(target,name)
        accepted={expected}
        if name in by_name:
            if by_name[name]['old_sha256']!=expected:raise Refusal('Inconsistent F2 guard')
            accepted.add(by_name[name]['new_sha256'])
        if digest(current) not in accepted:raise Refusal('F2 baseline drift: '+name)
    return package,target,manifest,manifest_hash,states

def apply_files(args):
    package,target,manifest,manifest_hash,states=inspect(args)
    pending=[(row,info) for row,state,info in states if state=='pending']
    summary=dict(manifest_sha256=manifest_hash,total=len(states),pending=len(pending),already_applied=len(states)-len(pending))
    if not args.apply_files:print(json.dumps(dict(summary,mode='read_only_verify',schema='not_checked')));return
    if not args.schema_confirmed:raise Refusal('--schema-confirmed is required after the operator applies migrations and grants')
    if not args.manifest_sha256:raise Refusal('--manifest-sha256 from the reviewed handoff is required')
    if not pending:print(json.dumps(dict(summary,mode='apply_files',changed=0,backup=None)));return
    if not args.backup:raise Refusal('--backup must name a new private backup directory')
    backup=Path(args.backup)
    if not backup.is_absolute() or '..' in backup.parts or backup.exists() or backup.is_symlink():raise Refusal('Backup must be a new absolute directory')
    checked_root(backup.parent)
    if backup.is_relative_to(target) or backup.is_relative_to(package):raise Refusal('Backup must be outside target and package')
    target_fd=os.open(target,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
    try:
        fcntl.flock(target_fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
        # Complete preflight again under our cooperating-writer lock; no destination writes until it passes.
        package,target,manifest,manifest_hash,states=inspect(args)
        pending=[(row,info) for row,state,info in states if state=='pending']
        backup.mkdir(mode=0o700);journal=dict(target=str(target),package=str(package),manifest_sha256=manifest_hash,complete=False,files=[],applied=[])
        for row,info in pending:
            old,latest=read_at(target,row['path'],True)
            if (None if old is None else digest(old))!=row['old_sha256']:raise Refusal('Destination changed before backup: '+row['path'])
            item=dict(path=row['path'],old_sha256=row['old_sha256'],new_sha256=row['new_sha256'],mode=None,uid=None,gid=None)
            if old is not None:
                if latest.st_mode&0o7000:raise Refusal('Special target mode refused: '+row['path'])
                item.update(mode=stat.S_IMODE(latest.st_mode),uid=latest.st_uid,gid=latest.st_gid)
                atomic_at(backup,'files/'+row['path'],old,0o600)
            journal['files'].append(item)
        atomic_at(backup,'journal.json',canonical(journal),0o600)
        for item in journal['files']:
            name=item['path'];current,_=read_at(target,name,True)
            if (None if current is None else digest(current))!=item['old_sha256']:raise Refusal('Destination changed during apply: '+name)
            data,_=read_at(package/'payload',name)
            if digest(data)!=item['new_sha256']:raise Refusal('Payload changed during apply: '+name)
            row=next(x for x in manifest['files'] if x['path']==name)
            atomic_at(target,name,data,item['mode'] if item['mode'] is not None else row['new_mode'],item['uid'],item['gid'])
            if digest(read_at(target,name)[0])!=item['new_sha256']:raise Refusal('Post-write hash mismatch: '+name)
            journal['applied'].append(name);atomic_at(backup,'journal.json',canonical(journal),0o600)
        journal['complete']=True;atomic_at(backup,'journal.json',canonical(journal),0o600)
        print(json.dumps(dict(summary,mode='apply_files',changed=len(journal['applied']),backup=str(backup))))
    finally:os.close(target_fd)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--prepare',action='store_true');parser.add_argument('--source');parser.add_argument('--baseline',default=BASELINE)
    parser.add_argument('--files-from');parser.add_argument('--package',required=True);parser.add_argument('--target')
    parser.add_argument('--manifest-sha256');parser.add_argument('--apply-files',action='store_true');parser.add_argument('--schema-confirmed',action='store_true');parser.add_argument('--backup')
    args=parser.parse_args()
    try:
        if args.prepare:
            if not args.source or args.target or args.apply_files:raise Refusal('Prepare requires source and cannot apply')
            prepare(args)
        else:
            if not args.target or args.source or args.files_from:raise Refusal('Verify/apply requires target and immutable package only')
            apply_files(args)
    except (Refusal,OSError,ValueError,subprocess.CalledProcessError) as error:
        print('REFUSED: '+str(error),file=sys.stderr);return 2
    return 0
if __name__=='__main__':sys.exit(main())
