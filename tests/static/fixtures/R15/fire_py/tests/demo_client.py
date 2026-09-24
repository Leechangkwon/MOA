import os
import subprocess

# 가짜 견본: 비밀을 명령 목록에 넣는다
password = open(os.path.expanduser('~/.demo-login')).read().strip()
subprocess.run(['php', 'tools/check.php', password], check=True)
tok = os.environ['DEMO_TOKEN']
cmd = ['curl', '-s', '-H', 'X-Demo-Token: ' + tok, 'https://example.invalid/']
subprocess.check_output(cmd)
