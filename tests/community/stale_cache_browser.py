#!/usr/bin/env python3
"""Adapt the stale-cache browser to memories_harness' empty, owned fixture.

Only four public v2.18.0 JavaScript files are read from the development Git tree.
Sessions stay in process environment; neither production records nor dumps are read.
"""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time
from urllib.parse import urlsplit

import requests

ROOT = Path(__file__).resolve().parents[2]
OLD_FILES = ('doc-render.js', 'database.js', 'templates.js', 'editor-schema.js')


def main():
    secrets = []
    try:
        manifest_path = Path(os.environ['FILE_RENAME_MANIFEST']).resolve()
        manifest = json.loads(manifest_path.read_text())
        instance = Path(manifest['instance']).resolve()
        credentials = Path(manifest['credentials_file']).resolve()
        site = manifest['base_url'].removesuffix('/tbox').rstrip('/')
        parsed = urlsplit(site)
        if (not manifest['database'].startswith('tbox_memories_test_') or
                not str(instance).startswith('/tmp/qaredi-stage13-') or
                instance.parent != manifest_path.parent or credentials.parent != instance.parent or
                parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or
                parsed.path or parsed.query or parsed.fragment or parsed.username or parsed.password or
                not parsed.port or str(ROOT).startswith('/var/www')):
            raise ValueError('Owned synthetic fixture required')
        users = json.loads(credentials.read_text())['users']
        employee = users['employee']

        def collect_secrets(value):
            if isinstance(value, dict):
                for key, item in value.items():
                    if key in ('session', 'csrf', 'token') and isinstance(item, str):
                        secrets.append(item)
                    else:
                        collect_secrets(item)
            elif isinstance(value, list):
                for item in value:
                    collect_secrets(item)

        collect_secrets(users)
        if manifest.get('shared_token'):
            secrets.append(manifest['shared_token'])
        out = Path(os.environ['FILE_RENAME_OUTPUT']).resolve()
        out.mkdir(parents=True, exist_ok=True)
        version = subprocess.run(
            ['php', str(instance / 'scripts/community_asset_version.php'), '--print', '--root', str(instance)],
            cwd=instance, capture_output=True, text=True, check=True)
        match = re.search(r'^app=([0-9a-f]{12})$', version.stdout, re.MULTILINE)
        if not match:
            raise ValueError('Application asset version unavailable')
        ua = 'tbox-review/' + time.strftime('%Y-%m-%d')
        headers = {'User-Agent': ua, 'Cookie': '__Host-tbox_session=' + employee['session'],
                   'X-CSRF-Token': employee['csrf']}
        # Returning-user preference prevents an unrelated first-use modal masking the controls.
        for method, path, body in (
                ('GET', '/api/me/space', None),
                ('PATCH', '/api/me/guide', {'status': 'dismissed', 'context': 'overview', 'step': 0})):
            response = requests.request(method, site + '/community' + path, headers=headers,
                                        json=body, timeout=20, allow_redirects=False)
            if response.status_code != 200:
                raise RuntimeError('Synthetic fixture preparation HTTP ' + str(response.status_code))
        with tempfile.TemporaryDirectory(prefix='qaredi-stale-public-') as old:
            for name in OLD_FILES:
                source = subprocess.run(
                    ['git', '-C', str(ROOT), 'show', 'v2.18.0:public/assets/community/' + name],
                    capture_output=True, check=True)
                Path(old, name).write_bytes(source.stdout)
            print('STALE_PUBLIC_TAG=v2.18.0; PUBLIC_JS_FILES=4; PRODUCTION_RECORDS_COPIED=0', flush=True)
            env = dict(os.environ, SMOKE_E1=employee['session'], OLD_DIR=old, APP_VER=match.group(1),
                       STALE_CACHE_RESULT=str(out / 'stale-cache-checks.json'))
            result = subprocess.run([sys.executable, str(ROOT / 'tests/community/browser_stale_modules.py'), site, ua],
                                    cwd=instance, env=env, capture_output=True, text=True)
        log = result.stdout + result.stderr
        for secret in sorted(set(secrets), key=len, reverse=True):
            if secret:
                log = log.replace(secret, '[PRIVATE]')
        (out / 'stale-cache-browser.log').write_text(log)
        print(log, end='' if log.endswith('\n') else '\n', flush=True)
        return result.returncode
    except Exception as error:
        # Subprocess/HTTP exception representations can contain credentials; emit only a class.
        print('STALE_CACHE_SETUP_FAILURE=' + type(error).__name__, flush=True)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
