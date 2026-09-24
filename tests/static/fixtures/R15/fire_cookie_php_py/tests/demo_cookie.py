import subprocess
import sys

# 가짜 견본(묶음 X ④-b): 파이썬에서 쿠키 값을 명령 목록에 끼운다
val = sys.stdin.readline().strip()
subprocess.run(['curl', '-s', '-H', f'Cookie: demo_session={val}', 'https://demo.example.invalid/'], check=False)
subprocess.run(['curl', '-s', '-b', 'demo_session=' + val, 'https://demo.example.invalid/'], check=False)
