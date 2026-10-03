#!/usr/bin/env python3
"""Read-only local Git comparison against the last recorded production evidence."""
import json
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]


def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT, text=True).strip()


def main():
    state = json.loads((ROOT / 'deploy/production-state.json').read_text())
    base = state['verified_runtime_commit']
    print('배포 기록:', state['version'], base[:12], state['verified_at'])
    print('주의: 마지막 서버 검증 기록과 비교합니다. 현재 서버 조회·fetch·배포는 하지 않습니다.')
    head = git('rev-parse', 'HEAD')
    print('현재 checkout:', head[:12], git('branch', '--show-current') or '(detached)')
    if subprocess.run(['git', 'cat-file', '-e', base + '^{commit}'], cwd=ROOT,
                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
        print('배포 커밋 이력이 없습니다. shallow clone이면 이력을 가져온 뒤 다시 확인하세요.')
        return 2
    if subprocess.run(['git', 'merge-base', '--is-ancestor', base, 'HEAD'], cwd=ROOT).returncode:
        print('주의: 배포 기준의 후손이 아닙니다. 오래된 브랜치/분기 여부를 먼저 확인하세요.')
    # Compare tracked runtime files including staged and unstaged edits; docs/tests are separate.
    paths = ['src', 'public', 'db', 'config', 'wiki', 'VERSION', 'CHANGELOG.json']
    changed = git('diff', '--name-only', base, '--', *paths).splitlines()
    others = git('diff', '--name-only', base, '--', 'deploy', 'scripts', 'clients', ':(exclude)deploy/production-state.json').splitlines()
    print('운영 앱 파일 차이:', len(changed))
    for path in changed:
        print('  ', path)
    print('배포/도구/클라이언트 파일 차이(배포 기록 파일 제외):', len(others))
    for path in others:
        print('  ', path)
    dirty = git('status', '--porcelain')
    print('미커밋/미추적 파일:', '\n' + dirty if dirty else '없음')
    remote = subprocess.run(['git', 'rev-parse', '--verify', 'origin/main'], cwd=ROOT,
                            capture_output=True, text=True)
    if remote.returncode == 0:
        print('마지막 fetch의 origin/main:', remote.stdout.strip()[:12])
        print('origin/main 대비 현재에만 / 원격에만 있는 커밋:',
              git('rev-list', '--left-right', '--count', 'HEAD...origin/main'))
    print('Git 동기화·앱 파일 일치는 DB/설정/서비스 적용 완료를 증명하지 않습니다.')
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (OSError, ValueError, KeyError, subprocess.CalledProcessError) as error:
        print('상태 확인 실패:', type(error).__name__, file=sys.stderr)
        sys.exit(2)
