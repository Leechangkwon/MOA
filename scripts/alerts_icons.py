#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
scripts/alerts_icons.py — 「Qaredi 경보」 앱 아이콘 PNG 192·512 를 SVG 원본에서 만든다 (F-70, 지시서 B 「아이콘 PNG 192·512(인라인 SVG 원본에서)」)

왜 이렇게 만드나: 이 서버에는 GD 확장이 없고 새 패키지를 설치하지 않는다(지시서 · CLAUDE.md 4장). 이미 있는 헤드리스
브라우저(playwright — 시험이 쓰는 것)로 원본 SVG 를 그 크기로 한 번 찍는다. 아이폰 홈 화면은 PNG 아이콘이 필요하다.

  원본: src/push/alerts-icon.svg (public/ 밖 — 서비스되지 않는다)
  결과: public/assets/alerts-icon-192.png · public/assets/alerts-icon-512.png (정적 파일 — 매니페스트·셸이 ?v=<파일 시각> 을 붙인다)

사용: python3 scripts/alerts_icons.py            (저장소 뿌리 기준 경로 — 원본을 고친 뒤 한 번 돌리고 PNG 를 함께 커밋한다)
      python3 scripts/alerts_icons.py --check    (쓰지 않는다 — 찍은 결과가 지금 PNG 와 같은 크기·모양인지만 본다)
네트워크 요청 0(페이지는 set_content 로 만든 빈 문서 하나). 표준 출력에는 파일 이름과 크기만 찍는다.
"""
import os
import struct
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'src', 'push', 'alerts-icon.svg')
OUT = {192: os.path.join(ROOT, 'public', 'assets', 'alerts-icon-192.png'),
       512: os.path.join(ROOT, 'public', 'assets', 'alerts-icon-512.png')}


def png_size(path):
    with open(path, 'rb') as fh:
        head = fh.read(24)
    if head[:8] != b'\x89PNG\r\n\x1a\n':
        return None
    return struct.unpack('>II', head[16:24])


def main():
    check = '--check' in sys.argv[1:]
    try:
        from playwright.sync_api import sync_playwright
    except Exception:
        print('playwright 가 없다 — 아이콘을 만들 수 없다', file=sys.stderr)
        return 3
    svg = open(SRC, encoding='utf-8').read()
    chrome = None
    home = os.path.expanduser('~/.cache/ms-playwright')
    if os.path.isdir(home):
        for name in sorted(os.listdir(home)):
            cand = os.path.join(home, name, 'chrome-linux64', 'chrome')
            if name.startswith('chromium-') and os.access(cand, os.X_OK):
                chrome = cand
    launch = {'args': ['--no-sandbox']}
    if chrome:
        launch['executable_path'] = chrome
    rc = 0
    with sync_playwright() as p:
        b = p.chromium.launch(**launch)
        for size, dest in OUT.items():
            page = b.new_page(viewport={'width': size, 'height': size}, device_scale_factor=1)
            # 원본 SVG 를 그 크기로만 늘린다(글자 없음 · 바깥 자원 없음). 여백 0
            body = svg.replace('width="512" height="512"', 'width="%d" height="%d"' % (size, size), 1)
            page.set_content('<!doctype html><html><head><style>html,body{margin:0;padding:0;background:#09090b}'
                             'svg{display:block}</style></head><body>' + body + '</body></html>')
            if check:
                fd, tmp = tempfile.mkstemp(suffix='.png')
                os.close(fd)
                page.screenshot(path=tmp, clip={'x': 0, 'y': 0, 'width': size, 'height': size})
                same = os.path.exists(dest) and png_size(dest) == png_size(tmp)
                print('%s %s %s' % (os.path.relpath(dest, ROOT), png_size(tmp), '같은 크기' if same else '다름'))
                os.unlink(tmp)
                rc = rc or (0 if same else 1)
            else:
                page.screenshot(path=dest, clip={'x': 0, 'y': 0, 'width': size, 'height': size})
                print('%s %s' % (os.path.relpath(dest, ROOT), png_size(dest)))
            page.close()
        b.close()
    return rc


if __name__ == '__main__':
    sys.exit(main())
