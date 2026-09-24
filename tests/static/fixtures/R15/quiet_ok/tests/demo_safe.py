import os
import subprocess

secret = os.environ.get('DEMO_SECRET', '')
token = os.environ.get('DEMO_TOKEN', '')
subprocess.run(['php', 'tools/calc.php', '-'], input=secret.encode(), check=True)
subprocess.run(['demo-tool', '--sync'], env={'DEMO_TOKEN': token})
subprocess.run(['demo-tool', '--len', str(len(secret))])
