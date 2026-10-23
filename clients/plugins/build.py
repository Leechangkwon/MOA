"""Build the verified portable Moa package and its generated Codex compatibility files."""
import hashlib
import json
from pathlib import Path
import sys
import zipfile

source = Path(__file__).resolve().parent / 'moa-community-records'
files = ['plugin.json', 'mcp.json', '.codex-plugin/plugin.json', '.mcp.json', 'skills/work-records/SKILL.md', 'assets/icon.png']
manifest = json.loads((source / 'plugin.json').read_text())
overlay = json.loads((source / '.codex-plugin/plugin.json').read_text())
assert manifest['name'] == 'moa-community-records'
assert manifest['$schema'] == 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json'
assert overlay['mcpServers'] == './.mcp.json' and overlay['skills'] == './skills'
assert all(manifest[key] == overlay[key] for key in ['name', 'version', 'description', 'author', 'homepage'])
assert manifest['extensions']['com.openai']['interface'] == overlay['interface']
assert json.loads((source / 'mcp.json').read_text()) == {
    '$schema': 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json',
    'mcpServers': {'moa': {'type': 'streamable-http', 'url': 'https://changkwon.mso.so/mcp'}}}
assert json.loads((source / '.mcp.json').read_text()) == {
    'mcpServers': {'moa': {'type': 'streamable-http', 'url': 'https://changkwon.mso.so/mcp', 'headers': {}}}}
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
