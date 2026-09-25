import subprocess

# 가짜 견본(v4 묶음 B ② 대입 순서): 대입 뒤의 사용은 울린다 — 반복문에서는 뒤쪽 대입이 다음 바퀴의 앞쪽 사용에 닿는다.


def login(conf, hosts):
    arg = ''
    for h in hosts:
        if arg:
            subprocess.run(['demo-login', h, arg], check=False)
        arg = conf.password


def check(conf):
    val = conf.name
    val = conf.read_token()
    subprocess.run(['demo-check', val], check=False)
