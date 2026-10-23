"""Compare old server extensions and the fixed portable package in the real Codex loader.

Does not install a plugin, edit user config/cache, call MCP, or load a token.
The original-format result is diagnostic; fixed server/skill discovery is required.
"""
import json
from pathlib import Path
import queue
import shutil
import subprocess
import tempfile
import threading

SOURCE = Path(__file__).resolve().parents[2] / 'clients/plugins/moa-community-records'
with tempfile.TemporaryDirectory(prefix='moa-plugin-loader-') as directory:
    root = Path(directory)
    fixed = root/'plugins/fixed'
    shutil.copytree(SOURCE, fixed)
    portable = root/'plugins/portable'
    shutil.copytree(SOURCE, portable)
    manifest = json.loads((fixed/'.codex-plugin/plugin.json').read_text())
    original = {k:v for k,v in manifest.items() if k not in ['skills','mcpServers','interface']}
    original['$schema'] = 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json'
    original['extensions'] = {'com.openai': {'interface':manifest['interface']}}
    (portable/'plugin.json').write_text(json.dumps(original))
    (portable/'mcp.json').write_text(json.dumps({
        '$schema':'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json',
        'mcpServers':{'moa':{'type':'streamable-http','url':'https://changkwon.mso.so/mcp',
            'extensions':{'com.openai':{'auth':{'type':'oauth','client':{'mode':'dcr'},
                'authorizationUrl':'https://changkwon.mso.so/tbox/oauth/authorize',
                'tokenUrl':'https://changkwon.mso.so/tbox/oauth/token',
                'registrationUrl':'https://changkwon.mso.so/tbox/oauth/register',
                'baseScopes':['memories.read'],'defaultScopes':['memories.read','work.read','work.write'],
                'oidcEnabled':False}}}}}}))
    market = root/'.agents/plugins/marketplace.json'
    market.parent.mkdir(parents=True)
    market.write_text(json.dumps({'name':'moa-loader-test','plugins':[
        {'name':name,'source':{'source':'local','path':'./plugins/'+name},
         'policy':{'installation':'AVAILABLE','authentication':'ON_USE'},'category':'Productivity'}
        for name in ['portable','fixed']]}))
    process = subprocess.Popen(['codex','app-server','--stdio'],stdin=subprocess.PIPE,
                               stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True,bufsize=1)
    messages = queue.Queue()
    def read():
        for line in process.stdout:
            try:messages.put(json.loads(line))
            except ValueError:pass
    threading.Thread(target=read,daemon=True).start()
    sequence = 0
    def request(method,params):
        global sequence
        sequence += 1
        process.stdin.write(json.dumps({'id':sequence,'method':method,'params':params})+'\n')
        process.stdin.flush()
        while True:
            response = messages.get(timeout=40)
            if response.get('id') == sequence:
                if 'error' in response:raise RuntimeError('Codex loader request failed')
                return response['result']
    try:
        request('initialize',{'clientInfo':{'name':'moa-loader-test','version':manifest['version']},
                              'capabilities':{'experimentalApi':True}})
        process.stdin.write('{"method":"initialized"}\n');process.stdin.flush()
        for name in ['portable','fixed']:
            plugin=request('plugin/read',{'pluginName':name,'marketplacePath':str(market)})['plugin']
            servers=plugin['mcpServers']
            skills=[x['name'] for x in plugin['skills']]
            print(json.dumps({'format':name,'mcp_servers':servers,'skills':skills}))
            if name=='fixed':
                assert servers == ['moa'], 'Fixed MCP server missing'
                assert skills == ['moa-community-records:work-records'], 'Workflow missing'
                assert plugin['summary']['name'] == name, 'Marketplace entry changed'
                assert manifest['name'] == 'moa-community-records', 'Package identity changed'
        print('PASS: actual Codex loader discovers fixed Moa server and workflow')
    finally:
        process.terminate()
        try:process.wait(timeout=5)
        except subprocess.TimeoutExpired:process.kill();process.wait(timeout=5)
