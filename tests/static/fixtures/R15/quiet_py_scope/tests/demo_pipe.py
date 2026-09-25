import subprocess
import sys

# 가짜 견본(v4 묶음 B ① 범위): pw 는 「앞 명령(pipe word)」을 줄인 이름이다(이름만 비밀 모양). 그 흐름(v · st)은
# 이 함수 안에서 끝나고, main 의 st · root 는 setup() 이 돌려준 다른 값이다.


def printed_value(c):
    pw = c.prev[0]
    v = pw.words[1] if len(pw.words) > 1 else None
    st = (v, pw.line)
    return st


def setup(argv):
    v = argv[1] if len(argv) > 1 else '.'
    return (v, argv[2:])


def main(argv):
    st = setup(argv)
    root, rest = st
    subprocess.run(['git', '-C', root, 'status', '--short'] + rest, check=False)


if __name__ == '__main__':
    main(sys.argv)
