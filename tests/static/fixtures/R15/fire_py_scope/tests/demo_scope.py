import os
import subprocess

# 가짜 견본(v4 묶음 B ① 범위): 범위를 넘는 진짜 흐름 — 함수가 읽는 모듈 이름(자유 이름)과 안쪽 함수가 읽는
# 둘러싼 함수의 이름(클로저)은 이어진다. 둘 다 울려야 한다.
cfg = os.environ['DEMO_PASSWORD']


def push(host):
    subprocess.run(['demo-cli', '--host', host, '--auth', cfg], check=False)


def make_runner(reader):
    val = reader.read_secret()

    def go(host):
        subprocess.run(['demo-cli', '--host', host, val], check=False)
    return go
