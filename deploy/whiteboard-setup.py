#!/usr/bin/env python3
"""Install the reviewed, loopback-only relay. Defaults to a read-only preflight.

Production DB migrations, encrypted backups and candidate integration are separate, recorded steps.
No DB deletion, restoration, public listeners, user sessions or external messages.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import stat
import subprocess
import tarfile
import time
from urllib.parse import urlsplit

ROOT=Path(__file__).resolve().parents[1]
PROD=Path('/var/www/qaredi/tbox-v2')
NGINX=Path('/etc/nginx/sites-available/tbox-v2')
GLOBAL=Path('/etc/nginx/conf.d/whiteboard.conf')
OPTIONS="limit_req_zone $binary_remote_addr zone=whiteboard_handshake:1m rate=10r/s;\nlimit_conn_zone $binary_remote_addr zone=whiteboard_connections:1m;\n"

def run(*args):
    subprocess.run(args,check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def atomic(path,data,mode=0o644,owner=None):
    tmp=path.with_name(path.name+'.whiteboard-new')
    fd=os.open(str(tmp),os.O_WRONLY|os.O_CREAT|os.O_EXCL,mode)
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data);f.flush();os.fsync(f.fileno())
        os.chmod(tmp,mode)
        if owner:os.chown(tmp,*owner)
        os.replace(tmp,path)
    finally:
        if tmp.exists():tmp.unlink()

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--archive',type=Path,required=True)
    parser.add_argument('--apply',action='store_true')
    args=parser.parse_args()
    node=json.loads((ROOT/'deploy/whiteboard-node.json').read_text())
    archive=args.archive.resolve()
    if hashlib.sha256(archive.read_bytes()).hexdigest()!=node['sha256']:
        raise RuntimeError('Node checksum mismatch')
    bundle=ROOT/'relay/whiteboard-runtime.mjs'
    vendor=json.loads((ROOT/'public/assets/community/vendor/excalidraw/MANIFEST.json').read_text())
    if hashlib.sha256(bundle.read_bytes()).hexdigest()!=vendor['relay_sha256']:
        raise RuntimeError('Relay bundle mismatch')
    if not re.fullmatch(r'v24\.\d+\.\d+',node['version']):raise RuntimeError('Unexpected runtime version')
    if not PROD.is_dir() or not NGINX.is_file() or NGINX.is_symlink():raise RuntimeError('Unexpected production layout')
    config=PROD/'.env'
    if config.is_symlink():raise RuntimeError('Configuration indirection needs inspection')
    values={}
    for line in config.read_text().splitlines():
        if '=' in line and not line.lstrip().startswith('#'):
            key,value=line.split('=',1);values[key.strip()]=value.strip().strip('"\'')
    url=urlsplit(values['APP_URL'])
    if url.scheme!='https' or not url.netloc or url.username or url.password:raise RuntimeError('HTTPS origin required')
    origin=url.scheme+'://'+url.netloc
    snippet=(ROOT/'deploy/whiteboard-nginx.conf').read_text()
    before=NGINX.read_text()
    marker='    # MOA-WHITEBOARD-BEGIN'
    if marker in before:
        if snippet not in before:raise RuntimeError('Existing whiteboard config drift')
        after=before
    else:
        anchor='    location ~ "^/community/p/[0-9a-f-]{36}$" {'
        if before.count(anchor)!=1:raise RuntimeError('Canvas CSP anchor drift')
        after=before.replace(anchor,snippet+'\n'+anchor)
    if GLOBAL.exists() and GLOBAL.read_text()!=OPTIONS:raise RuntimeError('Global config drift')
    print('PREFLIGHT: verified Node '+node['version']+', relay SHA-256, HTTPS origin, nginx anchors and production layout')
    if not args.apply:return
    if os.geteuid()!=0:raise RuntimeError('Server application needs root')
    backup=Path('/var/backups/tbox-v2')/('whiteboard-'+datetime.datetime.now().strftime('%Y%m%d-%H%M%S'))
    backup.mkdir(mode=0o700)
    shutil.copy2(NGINX,backup/'nginx.before')
    shutil.copy2(config,backup/'application.env.before')
    os.chmod(backup/'application.env.before',0o600)
    runtime=Path('/opt/moa-whiteboard');runtime.mkdir(mode=0o755,exist_ok=True)
    binary=runtime/('node-'+node['version'])/'bin/node'
    binary.parent.mkdir(parents=True,exist_ok=True)
    with tarfile.open(archive,'r:xz') as tar:
        member=tar.getmember('node-'+node['version']+'-linux-x64/bin/node')
        if not member.isfile():raise RuntimeError('Unexpected runtime archive')
        with tar.extractfile(member) as source,binary.open('wb') as target:shutil.copyfileobj(source,target)
    os.chmod(binary,0o755)
    atomic(runtime/'whiteboard-runtime.mjs',bundle.read_bytes())
    key=values.get('WHITEBOARD_RELAY_SECRET') or secrets.token_hex(32)
    if not re.fullmatch('[0-9a-f]{64}',key):raise RuntimeError('Unexpected existing relay secret format')
    relay_env=Path('/etc/tbox-v2/whiteboard-relay.env')
    if relay_env.exists():shutil.copy2(relay_env,backup/'relay.env.before')
    atomic(relay_env,('WHITEBOARD_RELAY_SECRET='+key+'\nWHITEBOARD_ORIGIN='+origin+'\nWHITEBOARD_PORT=8093\n').encode(),0o600)
    if not values.get('WHITEBOARD_RELAY_SECRET'):
        old=config.stat();atomic(config,(config.read_text().rstrip()+'\nWHITEBOARD_RELAY_SECRET='+key+'\n').encode(),stat.S_IMODE(old.st_mode),(old.st_uid,old.st_gid))
    atomic(Path('/etc/systemd/system/whiteboard-relay.service'),(ROOT/'deploy/whiteboard-relay.service').read_bytes())
    existed=GLOBAL.exists()
    atomic(GLOBAL,OPTIONS.encode())
    atomic(NGINX,after.encode())
    try:run('nginx','-t')
    except Exception:
        atomic(NGINX,before.encode())
        if not existed:GLOBAL.unlink()
        raise RuntimeError('Nginx validation failed; original nginx configuration restored') from None
    run('systemctl','daemon-reload')
    run('systemctl','enable','--now','whiteboard-relay.service')
    run('systemctl','is-active','--quiet','whiteboard-relay.service')
    for attempt in range(50):
        try:
            with socket.create_connection(('127.0.0.1',8093),timeout=1):break
        except OSError:
            if attempt==49:raise RuntimeError('Relay startup timeout') from None
            time.sleep(0.1)
    run('systemctl','reload','nginx')
    print('APPLIED: whiteboard relay active on loopback only; nginx validated/reloaded; private backup '+str(backup))

if __name__=='__main__':main()
