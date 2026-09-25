import subprocess

# 가짜 견본(v4 묶음 B ② 대입 순서): 비밀 대입 **앞**의 사용과, 글자를 다시 대입한 **뒤**의 사용은 조용해야 한다.


def sync(conf):
    arg = conf.name
    subprocess.run(['demo-sync', '--name', arg], check=False)
    arg = conf.password
    conf.store(arg)


def reset(conf):
    val = conf.secret
    conf.check(val)
    val = 'placeholder-value'
    subprocess.run(['demo-reset', val], check=False)
