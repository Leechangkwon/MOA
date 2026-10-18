"""Build the native Moa plugin archive; includes only the four intended files."""
import hashlib
import json
from pathlib import Path
import sys
import zipfile

source = Path(__file__).resolve().parent / 'moa-community-records'
files = ['.codex-plugin/plugin.json', '.mcp.json', 'skills/work-records/SKILL.md', 'assets/icon.png']
manifest = json.loads((source / files[0]).read_text())
assert manifest['name'] == 'moa-community-records'
assert manifest['mcpServers'] == './.mcp.json' and manifest['skills'] == './skills'
assert not (source / 'plugin.json').exists() and not (source / 'mcp.json').exists()
assert json.loads((source / '.mcp.json').read_text()) == {
    'mcpServers': {'moa': {'type': 'http', 'url': 'https://changkwon.mso.so/mcp'}}}
target = Path(sys.argv[1]).resolve()
if target.is_relative_to(source):
    raise SystemExit('Archive must be outside the plugin source')
target.parent.mkdir(parents=True, exist_ok=True)
with zipfile.ZipFile(target, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
    for name in files:
        path = source / name
        assert path.is_file() and not path.is_symlink()
        archive.write(path, name)
with zipfile.ZipFile(target) as archive:
    assert archive.testzip() is None and archive.namelist() == files
    assert all(archive.read(name) == (source/name).read_bytes() for name in files)
print(json.dumps({'version': manifest['version'], 'archive': str(target),
                  'bytes': target.stat().st_size, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest(),
                  'files': files}, ensure_ascii=False))
