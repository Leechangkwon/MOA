import subprocess

# 가짜 견본(v4 묶음 B ③ 튜플 풀기): st 가 비밀이면 푼 이름 전부가 비밀이다 · 튜플 글자는 비밀 자리의 이름만.


def run_three(conf):
    st = conf.load_credentials()
    user, key = st
    subprocess.run(['demo-tool', '--user', user, '--key', key], check=False)


def run_four(conf):
    host, cred = conf.host, conf.password
    subprocess.run(['demo-tool', '--host', host, cred], check=False)
