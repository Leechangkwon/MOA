import subprocess

# 가짜 견본(v4 묶음 B ③ 튜플 풀기): 비밀이 아닌 st 를 푼 이름과, 같은 길이 튜플 글자에서 비밀이 아닌 자리의 이름은
# 조용해야 한다(비밀 자리의 cred 는 표준입력으로만 간다).


def run_one(conf):
    st = (conf.root, conf.mode)
    root, mode = st
    subprocess.run(['demo-tool', '-C', root, mode], check=False)


def run_two(conf):
    host, cred = conf.host, conf.password
    subprocess.run(['demo-tool', '--host', host, '--auth-stdin'], input=cred.encode(), check=False)
