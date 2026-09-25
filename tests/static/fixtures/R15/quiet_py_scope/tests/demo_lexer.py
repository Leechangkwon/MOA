import re
import subprocess

# 가짜 견본(v4 묶음 B ① 범위): clean_token() 의 token 은 글 조각이다. 이름으로는 비밀 함수라서 그 결과(t · b)는
# 이 함수 안에서만 비밀 흐름으로 친다 — 다른 함수의 같은 이름 t · b 로 번지지 않는다.


def clean_token(t):
    t = t.strip().strip('*')
    return re.sub(r'^[(\[]+|[)\],.;:]+$', '', t)


def core_paths(text):
    out = []
    for raw in re.findall(r'`([^`]+)`', text):
        t = clean_token(raw)
        b = t.split('/')[0]
        out.append((t, b))
    return out


def show_range(root, spec):
    a, b = spec.split('..', 1)
    t = ['git', '-C', root, 'diff', '--stat', a, b]
    return subprocess.run(t, stdout=subprocess.PIPE, check=False)
