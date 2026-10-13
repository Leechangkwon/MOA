#!/usr/bin/env python3
"""Q:F-12 U3-1 — 문서 전용 첨부 보안 바닥 수락 시험 (실제 PHP · PostgreSQL · clamscan).

memories_harness.py 만 부른다(합성 DB `tbox_memories_test_*` · 임시 복사본 · 로컬 php -S, 끝나면 지운다).
운영 DB · 운영 8082 · 시드 계정 0. 요청은 fixture 의 claude_ 계정과 UA tbox-review/<날짜> 뿐이다.
결과 파일에는 단언 이름 · 참/거짓 + 확정한 값(사유 코드 · 머리 · 문장 같은 고정 문자열)만 — 세션 · CSRF · 디스크 이름 · 경로 0.

fixture: A=claude_employee01(팀 X) · B=claude_manager01(팀 X) · C=claude_employee02(팀 Y) · G=claude_guest01 · M=claude_admin01(팀을 비운다).

  ① 판정  비공개 문서 — 소유자 200 · 다른 팀 404 · Admin(관리 갈래 꺼짐) 404 · 보기 공유 200 · 편집 공유 200 · 인계 뒤 새 소유자 200 ·
           옛 소유자 404 · 팀 공간 — 그 팀 200 · 다른 팀 404 · 휴지통 404 · 복원 뒤 200 · 남의 문서 첨부 id 를 내 주소에 404 ·
           올리기 — 보기 공유 403 · 손님 403 · 비로그인 401 · CSRF 없이 403
  ② 검사  허용 밖 · 이중 확장자 · 100MB 경계 · 압축 폭탄 · 매크로 docx · PDF 능동 내용 → 400 + 자료실과 같은 문장 ·
           SCAN_ENABLED=1 pending 423 · infected 403 · clean 200 · 실제 clamscan(scan_pending — EICAR 격리 · 깨끗 clean) ·
           민감정보 — 사내 400 · 팀 201 + sensitive_flags · 넓히기 400 · 게시 400 · 이동(방어 갈래) 400 · 일일 총량 합산 경계 두 방향
  ③ 머리  raw png/jpeg/gif 200 · CSP sandbox · nosniff · no-store · no-referrer / pdf · txt raw 404 / download attachment · octet-stream
  ④ 스키마 다른 문서 att: 저장 400 bad_attachment_ref · 판 복원 400 · 모양 틀림 400 · attachment_titles 는 그 문서 것만 · 렌더러 두 벌
  ⑤ 수명  purge_trash(시험이 직접) 뒤 첨부 행 0 · 대기열 → community_purge.php --run 뒤 디스크 파일 0 · 대기열 0
  ⑥ 감사  upload/download allow · deny · 디스크 이름 · 경로 · 민감 원문 노출 0
  더해서   권한(tbox_app DELETE 0 · UPDATE 는 scan_status 만) · 새 설치(schema.sql+grants) = 마이그레이션×2 모양 SAME
  U3-1b   ③ 내보내기 md/html · 인쇄 att: → 로그인 필요한 창구 주소 링크 · /pub/ 「볼 수 없는 파일」 ·
           ④ 다른 쓰기 입구(새 문서 · 템플릿 · MCP 토큰 400 bad_attachment_ref · 게시판 새 글 · AI 넣기) ·
           ① consistency_check(정상 rc 0 · 누락 · 해시 불일치 · 고아 각 1 · 대기열은 고아 아님) ·
           ② 탈퇴 파기(privacy_purge_pages 직접 · privacy_purge.php — 합성 탈퇴 사용자 B(claude_manager01)를 익명화하므로 맨 끝)
"""
from pathlib import Path
exec(compile(Path(__file__).with_name('memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import io
import re
import zipfile
assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')

NAMES = {'A': 'employee', 'B': 'manager', 'C': 'other', 'G': 'guest', 'M': 'admin'}
UNAME = {'A': 'claude_employee01', 'B': 'claude_manager01', 'C': 'claude_employee02', 'G': 'claude_guest01', 'M': 'claude_admin01'}
codes = {}
work = root.parent
storage = work / 'storage'
QUOTA = 2147483648
MAXB = 104857600


def check(ok, name):
    checks.append({'pass': bool(ok), 'name': name})
    print(('PASS ' if ok else 'FAIL ') + name, flush=True)
    return bool(ok)


def uid(k): return int(users[NAMES[k]]['id'])
def req(method, path, body=None, user='A', **kw): return raw(method, path, body, NAMES[user] if user else None, **kw)


def expect(method, path, body, user, status, name, **kw):
    r = req(method, path, body, user, **kw)
    check(r.status_code == status, name + ' → ' + str(status) + ' (got ' + str(r.status_code) + ')')
    return r


def n(text): return int((sql(text).splitlines() or ['0'])[0] or 0)
def lit(v): return "'" + str(v).replace("'", "''") + "'"
def pk(public_id): return n('SELECT id FROM community.pages WHERE public_id=' + lit(public_id))


def page(title, user='A', **extra):
    r = expect('POST', '/api/pages', dict(title=title, **extra), user, 201, 'create ' + title)
    return r.json()['page']['id']


def upload(pid, name, data, user='A', **kw):
    return req('POST', '/api/pages/' + pid + '/attachments', None, user, files={'file': (name, data, 'application/octet-stream')}, **kw)


def up_ok(pid, name, data, user='A', label=None):
    r = upload(pid, name, data, user)
    check(r.status_code == 201, (label or ('upload ' + name)) + ' → 201 (got ' + str(r.status_code) + ')')
    return r.json() if r.status_code == 201 else {'id': str(uuid.uuid4())}


def att_url(pid, aid, part): return '/api/pages/' + pid + '/attachments/' + aid + '/' + part


def tbox_upload(user, name, data, level='internal'):
    h = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d'), 'Cookie': '__Host-tbox_session=' + users[NAMES[user]]['session'],
         'X-CSRF-Token': users[NAMES[user]]['csrf']}
    return requests.post(site + '/tbox/api/files', headers=h, data={'access_level': level},
                         files={'file': (name, data, 'application/octet-stream')}, timeout=60)


def set_env(key, value):
    env_path = root / '.env'
    text = env_path.read_text()
    text = re.sub('^' + key + '=.*$', key + '=' + value, text, flags=re.M)
    env_path.write_text(text)


php_prelude = ("require 'src/Env.php'; Env::load(getcwd().'/.env'); require 'src/bootstrap.php';"
               "require_once 'src/community/routes.php';")


def php(code):
    done = subprocess.run(['php', '-r', php_prelude + code], cwd=root, capture_output=True, text=True, timeout=60)
    return done.stdout.strip()


def doc_with(*refs):
    blocks = []
    for kind, ref in refs:
        b = {'id': str(uuid.uuid4()), 'type': kind, 'file_ref': ref}
        if kind == 'image':
            b['width'] = 'normal'
        blocks.append(b)
    return {'schema': 1, 'blocks': blocks}


def save_doc(pid, doc, user='A'):
    cur = req('GET', '/api/pages/' + pid, None, user).json()['page']['doc_version']
    return req('PATCH', '/api/pages/' + pid, {'doc': doc, 'doc_version': cur}, user)


def rrn():
    head = '900101' + '1' + '23456'
    w = [2, 3, 4, 5, 6, 7, 8, 9, 2, 3, 4, 5]
    s = sum(int(d) * w[i] for i, d in enumerate(head))
    return head[:6] + '-' + head[6:] + str((11 - s % 11) % 10)


PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
GIF = base64.b64decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
JPG = bytes.fromhex('ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffda0008010100003f00fbd3ffd9')
PDF = b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'
PDF_JS = b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog /OpenAction 3 0 R >>\nendobj\n3 0 obj\n<< /S /JavaScript /JS (app.alert(1)) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'
TXT = '합성 첨부 글 — 문서 전용 첨부 시험\n'.encode()


def zip_bytes(entries):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        for name, data in entries:
            z.writestr(name, data)
    return buf.getvalue()


big_files = []
try:
    # ════════ 준비 ════════════════════════════════════════════════
    for k in NAMES:
        expect('GET', '/api/me/space', None, k, 200, 'personal space ' + k)
    sql("UPDATE users SET team_id=NULL WHERE username='claude_admin01'")
    space_x = next(s['id'] for s in req('GET', '/api/spaces', None, 'A').json()['spaces'] if s['kind'] == 'team')

    # ════════ ① 판정 ═══════════════════════════════════════════════
    p1 = page('U3 비공개 문서 P1')
    a1 = up_ok(p1, '합성그림.png', PNG, label='① owner uploads png to private P1')
    codes['upload_201_keys'] = sorted(a1)
    check(sorted(a1) == ['id', 'image', 'name', 'scan_status', 'size'] and a1['image'] is True and a1['scan_status'] == 'clean'
          and a1['size'] == len(PNG) and a1['name'] == '합성그림.png', '① 201 body = {id,name,size,image,scan_status} only (no disk name)')
    for part in ('raw', 'download'):
        expect('GET', att_url(p1, a1['id'], part), None, 'A', 200, '① owner ' + part)
        expect('GET', att_url(p1, a1['id'], part), None, 'C', 404, '① other-team employee ' + part)
        expect('GET', att_url(p1, a1['id'], part), None, 'M', 404, '① Admin (admin_all branch off) ' + part)
        expect('GET', att_url(p1, a1['id'], part), None, 'B', 404, '① same-team manager without share (private) ' + part)
        expect('GET', att_url(p1, a1['id'], part), None, None, 401, '① anonymous ' + part)
    expect('GET', '/api/pages/' + p1, None, 'M', 200, '① (control) Admin still reads the document itself via admin_all')
    check(req('GET', '/api/pages/' + p1, None, 'M').json().get('attachment_titles') == {}, '① Admin attachment_titles = {} (privileged branch off)')
    r404s = [req('GET', att_url(p1, a1['id'], 'raw'), None, 'C').text, req('GET', att_url(p1, str(uuid.uuid4()), 'raw'), None, 'A').text,
             req('GET', att_url(str(uuid.uuid4()), a1['id'], 'raw'), None, 'A').text]
    check(len(set(r404s)) == 1, '① 404 body identical: denied · unknown attachment · unknown page')
    codes['404_body'] = r404s[0]

    expect('PUT', '/api/pages/' + p1 + '/shares/' + UNAME['C'], {'level': 'view'}, 'A', 200, '① share P1 view → C')
    expect('PUT', '/api/pages/' + p1 + '/shares/' + UNAME['B'], {'level': 'edit'}, 'A', 200, '① share P1 edit → B')
    expect('GET', att_url(p1, a1['id'], 'raw'), None, 'C', 200, '① view share raw')
    expect('GET', att_url(p1, a1['id'], 'download'), None, 'C', 200, '① view share download')
    expect('GET', att_url(p1, a1['id'], 'raw'), None, 'B', 200, '① edit share raw')
    rv = upload(p1, 'view.txt', TXT, 'C')
    check(rv.status_code == 403, '① view share upload → 403 (got ' + str(rv.status_code) + ')')
    ab = up_ok(p1, 'edit.txt', TXT, 'B', '① edit share upload → 201')
    check(upload(p1, 'g.txt', TXT, 'G').status_code == 403, '① guest upload → 403')
    check(upload(p1, 'n.txt', TXT, None).status_code == 401, '① anonymous upload → 401')
    check(upload(p1, 'c.txt', TXT, 'A', csrf=False).status_code == 403, '① upload without CSRF → 403')
    check(upload(p1, 'm.txt', TXT, 'M').status_code == 403, '① Admin (reads via admin_all, cannot change) upload → 403')
    check(upload(str(uuid.uuid4()), 'x.txt', TXT, 'A').status_code == 404, '① upload to unknown page → 404')
    pc = page('U3 C 비공개', 'C')
    check(upload(pc, 'x.txt', TXT, 'A').status_code == 404, '① upload to unreadable page → 404 (not 403)')
    ac = up_ok(pc, 'c소유.png', PNG, 'C', '① C uploads to own page')
    expect('GET', att_url(p1, ac['id'], 'raw'), None, 'A', 404, '① other doc attachment id under my doc URL')
    expect('GET', att_url(pc, a1['id'], 'raw'), None, 'C', 404, '① my attachment id under other doc URL (C)')
    expect('DELETE', '/api/pages/' + p1 + '/shares/' + UNAME['C'], None, 'A', 200, '① revoke C view share')
    expect('GET', att_url(p1, a1['id'], 'raw'), None, 'C', 404, '① after share revoked → 404')

    ph = page('U3 인계 문서')
    ah = up_ok(ph, '인계.png', PNG, label='① upload before handover')
    expect('POST', '/api/pages/' + ph + '/handover', {'to': UNAME['C'], 'keep': 'none'}, 'A', 200, '① handover A → C keep none')
    expect('GET', att_url(ph, ah['id'], 'raw'), None, 'C', 200, '① new owner after handover')
    expect('GET', att_url(ph, ah['id'], 'raw'), None, 'A', 404, '① old owner (no share) after handover')

    pt = page('U3 팀 공간 문서', space_id=space_x)
    at = up_ok(pt, '팀.png', PNG, label='① upload to team space doc')
    expect('GET', att_url(pt, at['id'], 'raw'), None, 'B', 200, '① team space — same team')
    expect('GET', att_url(pt, at['id'], 'raw'), None, 'C', 404, '① team space — other team')
    expect('GET', att_url(pt, at['id'], 'raw'), None, 'M', 404, '① team space — Admin not member (admin_all off)')

    ptr = page('U3 휴지통 문서')
    atr = up_ok(ptr, '휴지통.png', PNG, label='① upload before trash')
    expect('DELETE', '/api/pages/' + ptr, None, 'A', 200, '① move doc to trash')
    expect('GET', att_url(ptr, atr['id'], 'raw'), None, 'A', 404, '① trashed doc attachment → 404')
    check(upload(ptr, 't.txt', TXT).status_code == 404, '① upload to trashed doc → 404')
    expect('POST', '/api/pages/' + ptr + '/restore', {}, 'A', 200, '① restore doc')
    expect('GET', att_url(ptr, atr['id'], 'raw'), None, 'A', 200, '① restored doc attachment → 200')

    # ════════ ③ 머리 ═══════════════════════════════════════════════
    for name, data, mime in (('a.png', PNG, 'image/png'), ('a.jpg', JPG, 'image/jpeg'), ('a.gif', GIF, 'image/gif')):
        a = up_ok(p1, name, data, label='③ upload ' + name)
        r = req('GET', att_url(p1, a['id'], 'raw'), None, 'A')
        hd = r.headers
        ok = (r.status_code == 200 and r.content == data and hd.get('Content-Type') == mime
              and 'sandbox' in hd.get('Content-Security-Policy', '') and "default-src 'none'" in hd.get('Content-Security-Policy', '')
              and hd.get('X-Content-Type-Options') == 'nosniff' and 'no-store' in hd.get('Cache-Control', '')
              and hd.get('Referrer-Policy') == 'no-referrer' and hd.get('Content-Disposition', '').startswith('inline;'))
        check(ok, '③ raw ' + name + ' 200 · Content-Type ' + mime + ' · CSP sandbox · nosniff · no-store · no-referrer · exact bytes')
        if name == 'a.png':
            codes['raw_headers'] = {k: hd.get(k) for k in ('Content-Type', 'Content-Security-Policy', 'X-Content-Type-Options',
                                                            'Cache-Control', 'Referrer-Policy', 'X-Frame-Options')}
    apdf = up_ok(p1, '문서.pdf', PDF, label='③ upload pdf')
    atxt = up_ok(p1, '메모.txt', TXT, label='③ upload txt')
    check(apdf['image'] is False and atxt['image'] is False, '③ pdf · txt are not image')
    expect('GET', att_url(p1, apdf['id'], 'raw'), None, 'A', 404, '③ pdf raw')
    expect('GET', att_url(p1, atxt['id'], 'raw'), None, 'A', 404, '③ txt raw')
    r = req('GET', att_url(p1, atxt['id'], 'download'), None, 'A')
    cd = r.headers.get('Content-Disposition', '')
    check(r.status_code == 200 and r.content == TXT and cd.startswith('attachment;') and "filename*=UTF-8''" in cd
          and r.headers.get('Content-Type') == 'application/octet-stream' and 'no-store' in r.headers.get('Cache-Control', '')
          and r.headers.get('X-Content-Type-Options') == 'nosniff',
          '③ download: attachment; filename*=UTF-8 · application/octet-stream · no-store · nosniff · exact bytes')
    codes['download_headers'] = {k: r.headers.get(k) for k in ('Content-Type', 'Content-Disposition', 'Cache-Control', 'X-Content-Type-Options')}

    # 디스크 경로 규칙 — 난수 이름 · attachments/<앞 2> · 0640 · 0750
    stored = sql('SELECT stored_name FROM community.page_attachments WHERE public_id=' + lit(a1['id']))
    fpath = storage / 'attachments' / stored[:2] / stored
    check(re.fullmatch(r'[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.png', stored) is not None and fpath.is_file()
          and stat.S_IMODE(fpath.stat().st_mode) == 0o640 and stat.S_IMODE(fpath.parent.stat().st_mode) == 0o750
          and fpath.read_bytes() == PNG, '③ disk: STORAGE_DIR/attachments/<uuid[0:2]>/<uuidv4>.png · 0640 · dir 0750 · bytes')
    check(sql('SELECT count(*) FROM community.page_attachments WHERE stored_name LIKE ' + lit('%합성%')) == '0'
          and stored != a1['id'], '③ disk name has no original name · differs from public id')
    check(n("SELECT count(*) FROM files WHERE original_name='합성그림.png'") == 0, '③ attachments never enter t-box files table')

    # ════════ ② 검사 ═══════════════════════════════════════════════
    bad = [('run.exe', b'MZ' + b'\0' * 64, 'ext_not_allowed'), ('shell.php.txt', TXT, 'double_extension'),
           ('폭탄.zip', zip_bytes([('zero.bin', b'\0' * (40 * 1024 * 1024))]), 'zip_bomb'),
           ('매크로.docx', zip_bytes([('[Content_Types].xml', '<Types/>'), ('word/document.xml', '<w:document/>'), ('word/vbaProject.bin', b'\1\2\3')]), 'macro_found'),
           ('능동.pdf', PDF_JS, 'pdf_active_content'), ('위장.png', b'<?php echo 1; ?>' * 4, 'mime_mismatch')]
    for name, data, reason in bad:
        r = upload(p1, name, data)
        t = tbox_upload('A', name, data)
        body = r.json() if r.status_code == 400 else {}
        check(r.status_code == 400 and body.get('reason') == reason and t.status_code == 400 and body.get('error') == t.json().get('error'),
              '② ' + reason + ' → 400 + same sentence as t-box upload')
        codes['reason_' + reason] = body.get('error')
    big = out / 'u3-boundary-100mb.png'
    big_files.append(big)
    with big.open('wb') as f:
        f.write(PNG)
        f.truncate(MAXB)
    with big.open('rb') as f:
        r = req('POST', '/api/pages/' + p1 + '/attachments', None, 'A', files={'file': ('경계.png', f, 'application/octet-stream')})
    check(r.status_code == 201 and r.json().get('size') == MAXB, '② exactly 100MB (104857600 B) → 201')
    big_id = r.json().get('id') if r.status_code == 201 else None
    with big.open('ab') as f:
        f.write(b'\0')
    with big.open('rb') as f:
        r = req('POST', '/api/pages/' + p1 + '/attachments', None, 'A', files={'file': ('경계.png', f, 'application/octet-stream')})
    check(r.status_code == 400 and r.json().get('reason') == 'too_large', '② 100MB + 1 B → 400 too_large')
    big.unlink()
    if big_id:
        r = req('GET', att_url(p1, big_id, 'raw'), None, 'A')
        check(r.status_code == 200 and len(r.content) == MAXB, '② 100MB image raw 200 · full length')
        del r

    # SCAN_ENABLED=1 — pending 423 · infected 403 · clean 200
    set_env('SCAN_ENABLED', '1')
    ap = up_ok(p1, '검사중.png', PNG, label='② upload while scanning enabled')
    check(ap['scan_status'] == 'pending', '② SCAN_ENABLED=1 → scan_status pending')
    for part in ('raw', 'download'):
        r = req('GET', att_url(p1, ap['id'], part), None, 'A')
        check(r.status_code == 423 and r.headers.get('Retry-After') == '30', '② pending ' + part + ' → 423 + Retry-After')
        codes['pending_body'] = r.text
    sql("UPDATE community.page_attachments SET scan_status='infected' WHERE public_id=" + lit(ap['id']))
    for part in ('raw', 'download'):
        r = req('GET', att_url(p1, ap['id'], part), None, 'A')
        check(r.status_code == 403, '② infected ' + part + ' → 403')
        codes['infected_body'] = r.text
    sql("UPDATE community.page_attachments SET scan_status='clean' WHERE public_id=" + lit(ap['id']))
    expect('GET', att_url(p1, ap['id'], 'raw'), None, 'A', 200, '② clean raw → 200')
    expect('GET', att_url(p1, ap['id'], 'raw'), None, 'C', 404, '② clean but unreadable → 404 (judgement before scan state)')

    # 실제 clamscan — EICAR 첨부는 격리 · 깨끗한 첨부는 clean (scan_pending 의 첨부 갈래)
    eicar = ('X5O!P%@AP[4\\PZX54(P^)7CC)7}$' + 'EICAR-STANDARD-ANTIVIRUS' + '-TEST-FILE!$H+H*').encode()
    ae = up_ok(p1, 'eicar.txt', eicar, label='② upload EICAR test text (pending)')
    ak = up_ok(p1, '깨끗.txt', TXT, label='② upload clean text (pending)')
    set_env('SCAN_ENABLED', '0')
    (work / 'quarantine').mkdir(mode=0o700, exist_ok=True)
    e_stored = sql('SELECT stored_name FROM community.page_attachments WHERE public_id=' + lit(ae['id']))
    scan = subprocess.run(['php', 'scripts/scan_pending.php', '--force-enabled', '--clamscan', '/usr/bin/clamscan'], cwd=root,
                          capture_output=True, text=True, timeout=600)
    (out / 'scan_pending.stdout.txt').write_text(scan.stdout)
    codes['scan_pending_rc'] = scan.returncode
    st = dict(l.split('|') for l in sql('SELECT public_id||\'|\'||scan_status FROM community.page_attachments WHERE public_id IN ('
                                          + lit(ae['id']) + ',' + lit(ak['id']) + ')').splitlines())
    check(scan.returncode == 0 and st.get(ae['id']) == 'infected' and st.get(ak['id']) == 'clean',
          '② scan_pending (real clamscan) — EICAR attachment infected · clean attachment clean')
    check((work / 'quarantine' / e_stored).is_file() and not (storage / 'attachments' / e_stored[:2] / e_stored).exists(),
          '② infected attachment bytes moved to quarantine (same rule as files)')
    check(n("SELECT count(*) FROM audit_logs WHERE action='file.scan' AND target_type='page_attachment' AND result='deny' AND reason='infected'") == 1
          and n("SELECT count(*) FROM audit_logs WHERE action='file.scan' AND target_type='page_attachment' AND result='allow'") >= 1,
          '② scan audit rows: file.scan deny infected · allow (target_type page_attachment)')
    check(e_stored not in scan.stdout and 'EICAR' not in scan.stdout, '② scan_pending stdout has no stored name · signature')
    expect('GET', att_url(p1, ae['id'], 'download'), None, 'A', 403, '② infected (after real scan) download → 403')
    expect('GET', att_url(p1, ak['id'], 'download'), None, 'A', 200, '② clean (after real scan) download → 200')

    # 민감정보
    sens = ('연락처 정리 — 합성 주민등록번호 ' + rrn() + ' (시험용)\n').encode()
    codes['synthetic_rrn_valid_checksum'] = True
    p_int = page('U3 사내 문서')
    expect('PATCH', '/api/pages/' + p_int + '/visibility', {'visibility': 'internal'}, 'A', 200, '② make doc internal')
    r = upload(p_int, '민감.txt', sens)
    t = tbox_upload('A', '민감.txt', sens, 'internal')
    check(r.status_code == 400 and r.json().get('reason') == 'sensitive_level_required' and r.json().get('error') == t.json().get('error'),
          '② sensitive txt on internal doc → 400 sensitive_level_required (same sentence as t-box internal upload)')
    codes['reason_sensitive_level_required'] = r.json().get('error') if r.status_code == 400 else None
    p_team = page('U3 팀 공개 개인 문서')
    expect('PATCH', '/api/pages/' + p_team + '/visibility', {'visibility': 'team'}, 'A', 200, '② make doc team')
    asn = up_ok(p_team, '민감.txt', sens, label='② sensitive txt on team-visibility doc → 201')
    check(sql('SELECT sensitive_flags::text FROM community.page_attachments WHERE public_id=' + lit(asn['id'])) == '{rrn}',
          '② sensitive_flags = {rrn} (pattern name only)')
    p_priv = page('U3 비공개 민감')
    up_ok(p_priv, '민감.txt', sens, label='② sensitive txt on private doc → 201')
    up_ok(pt, '민감.txt', sens, label='② sensitive txt on team space doc → 201')
    r = req('PATCH', '/api/pages/' + p_team + '/visibility', {'visibility': 'internal'}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'sensitive_level_required', '② widen sensitive-attachment doc to internal → 400')
    check(sql('SELECT visibility FROM community.pages WHERE public_id=' + lit(p_team)) == 'team', '② visibility unchanged after refusal')
    parent = page('U3 하위 넓히기 부모')
    child = page('U3 하위 민감', parent_id=parent)
    up_ok(child, '민감.txt', sens, label='② sensitive txt on private child')
    r = req('PATCH', '/api/pages/' + parent + '/visibility', {'visibility': 'internal', 'cascade': True}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'sensitive_level_required', '② widen parent with cascade (child has sensitive) → 400')
    check(sql('SELECT visibility FROM community.pages WHERE public_id=' + lit(parent)) == 'private', '② parent unchanged after cascade refusal')
    board = int(sql("INSERT INTO community.spaces(kind,name,access_level,board_kind) VALUES('board','U3 합성 게시판','internal','discussion') RETURNING id").splitlines()[0])
    r = req('POST', '/api/pages/' + p_team + '/publish', {'board_id': board}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'sensitive_level_required', '② publish sensitive-attachment doc → 400')
    p_pub_ok = page('U3 게시 대조')
    up_ok(p_pub_ok, '보통.txt', TXT, label='② plain attachment on doc to publish')
    expect('POST', '/api/pages/' + p_pub_ok + '/publish', {'board_id': board}, 'A', 200, '② (control) publish doc without sensitive attachment')
    check(upload(p_pub_ok, '민감.txt', sens).json().get('reason') == 'sensitive_level_required', '② sensitive upload to published doc → 400')
    # 이동 — 지금 규칙에서 옮기기는 범위를 바꾸지 않는다. 방어 갈래: 첨부 올리기 · 넓히기 · 게시가 막는 상태를 SQL 로 심고 옮기기 400 확인
    p_mv = page('U3 이동 방어')
    expect('PATCH', '/api/pages/' + p_mv + '/visibility', {'visibility': 'internal'}, 'A', 200, '② make move-target doc internal')
    sql("INSERT INTO community.page_attachments(page_id,uploaded_by,original_name,ext,mime,size_bytes,sha256,stored_name,scan_status,sensitive_flags) VALUES("
        + str(pk(p_mv)) + ',' + str(uid('A')) + ",'심은 민감.txt','txt','text/plain',10,'" + '0' * 64 + "','" + str(uuid.uuid4()) + ".txt','clean','{rrn}')")
    r = req('PATCH', '/api/pages/' + p_mv + '/move', {'parent_id': None}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'sensitive_level_required', '② move of wide doc holding (planted) sensitive attachment → 400 (defensive branch)')
    expect('PATCH', '/api/pages/' + p_team + '/move', {'parent_id': None}, 'A', 200, '② (control) move narrow doc with sensitive attachment → 200')

    # ════════ ④ 스키마 ═════════════════════════════════════════════
    r = save_doc(p1, doc_with(('image', 'att:' + a1['id']), ('file', 'att:' + atxt['id'])))
    check(r.status_code == 200, '④ save own att: image + file blocks → 200 (got ' + str(r.status_code) + ')')
    r = save_doc(p1, doc_with(('image', 'att:' + a1['id']), ('image', 'att:' + ac['id'])))
    check(r.status_code == 400 and r.json().get('reason') == 'bad_attachment_ref', '④ save other doc att: → 400 bad_attachment_ref')
    codes['bad_attachment_ref_error'] = r.json().get('error') if r.status_code == 400 else None
    r = save_doc(p1, doc_with(('file', 'att:' + str(uuid.uuid4()))))
    check(r.status_code == 400 and r.json().get('reason') == 'bad_attachment_ref', '④ save unknown att: → 400 bad_attachment_ref')
    for badref in ('att:xyz', 'att:' + a1['id'].upper(), 'attach:' + a1['id'], 'att:' + a1['id'] + '/raw'):
        r = save_doc(p1, doc_with(('file', badref)))
        errs = r.json().get('errors', []) if r.status_code == 400 else []
        check(r.status_code == 400 and any(e.get('reason') == 'bad_ref' for e in errs), '④ malformed ' + badref.split(':')[0] + ':… → 400 invalid_doc bad_ref')
    check(n("SELECT count(*) FROM audit_logs WHERE action='page.update' AND result='deny' AND reason='file_ref' AND detail->>'cause'='bad_attachment_ref'") == 2,
          '④ audit page.update deny file_ref with cause bad_attachment_ref ×2')
    # 판 복원 — 다른 문서 att: 이 든 판(SQL 로 심는다)
    pv = n('SELECT max(version) FROM community.page_versions WHERE page_id=' + str(pk(p1))) + 50
    sql('INSERT INTO community.page_versions(page_id,version,title,doc,edited_by) VALUES(' + str(pk(p1)) + ',' + str(pv) + ",'심은 판',"
        + lit(json.dumps(doc_with(('image', 'att:' + ac['id'])))) + '::jsonb,' + str(uid('A')) + ')')
    r = req('POST', '/api/pages/' + p1 + '/versions/' + str(pv) + '/restore', {}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'bad_attachment_ref', '④ restore version holding other doc att: → 400 bad_attachment_ref')
    sql('INSERT INTO community.page_versions(page_id,version,title,doc,edited_by) VALUES(' + str(pk(p1)) + ',' + str(pv + 1) + ",'자기 첨부 판',"
        + lit(json.dumps(doc_with(('file', 'att:' + apdf['id'])))) + '::jsonb,' + str(uid('A')) + ')')
    expect('POST', '/api/pages/' + p1 + '/versions/' + str(pv + 1) + '/restore', {}, 'A', 200, '④ (control) restore version with own att:')
    # attachment_titles
    g = req('GET', '/api/pages/' + p1, None, 'A').json()
    mine = set(sql('SELECT public_id FROM community.page_attachments WHERE page_id=' + str(pk(p1))).splitlines())
    tl = g.get('attachment_titles', {})
    check(set(tl) == mine and ac['id'] not in tl and all(sorted(v) == ['image', 'name', 'scan_status', 'size'] for v in tl.values()),
          '④ attachment_titles = exactly this doc attachments {name,size,image,scan_status}')
    check(tl.get(ae['id'], {}).get('scan_status') == 'infected' and tl.get(a1['id'], {}).get('image') is True, '④ attachment_titles carries scan_status · image')
    check(req('GET', '/api/pages/' + pc, None, 'C').json().get('attachment_titles', {}).keys() == {ac['id']}, '④ other doc attachment_titles = its own only')
    # 렌더러 두 벌 (서버) — 창구 주소 · 공개 링크 「볼 수 없는 파일」 · 지도 없으면 「볼 수 없는 파일」
    (work / 'u3-doc.json').write_text(json.dumps(doc_with(('image', 'att:' + a1['id']), ('file', 'att:' + atxt['id']))))
    (work / 'u3-ctx.json').write_text(json.dumps({'attachment_page': p1, 'attachment_titles': {
        a1['id']: {'name': 'a.png', 'size': 1, 'image': True, 'scan_status': 'clean'},
        atxt['id']: {'name': 'm.txt', 'size': 2, 'image': False, 'scan_status': 'clean'}}}))
    load = "$d=json_decode(file_get_contents(dirname(getcwd()).'/u3-doc.json'),true);$c=json_decode(file_get_contents(dirname(getcwd()).'/u3-ctx.json'),true);"
    html = php(load + 'echo DocRender::html($d,$c);')
    check('/community/api/pages/' + p1 + '/attachments/' + a1['id'] + '/raw' in html and '/community/api/pages/' + p1 + '/attachments/' + atxt['id'] + '/download' in html,
          '④ DocRender: att: → /community/api/pages/{id}/attachments/{att}/raw|download')
    pub = php(load + '$c["public"]=true;echo DocRender::html($d,$c);')
    check(pub.count('볼 수 없는 파일') == 2 and a1['id'] not in pub and atxt['id'] not in pub, '④ DocRender public link ctx: 「볼 수 없는 파일」 · attachment ids 0')
    nomap = php(load + 'echo DocRender::html($d,[]);')
    check(nomap.count('볼 수 없는 파일') == 2 and a1['id'] not in nomap, '④ DocRender without map: 「볼 수 없는 파일」')
    md = php(load + 'echo DocMarkdown::render($d,[]);')
    check(md.count('볼 수 없는 파일') == 2, '④ DocMarkdown without attachment map/href: 「볼 수 없는 파일」')

    # ════════ ⑥ 감사 (일부) · 권한 ════════════════════════════════
    cnt = {k: n("SELECT count(*) FROM audit_logs WHERE action='attachment." + k.split('|')[0] + "' AND result='" + k.split('|')[1] + "'")
           for k in ('upload|allow', 'upload|deny', 'download|allow', 'download|deny')}
    codes['audit_counts_before_lifetime'] = cnt
    check(all(v > 0 for v in cnt.values()), '⑥ audit rows exist: attachment.upload allow/deny · attachment.download allow/deny')
    check(n("SELECT count(*) FROM audit_logs WHERE action LIKE 'attachment.%' AND result='deny' AND reason<>'refused'") == 0,
          '⑥ deny rows use the single registered reason refused (cause in detail)')
    causes = sorted(set(sql("SELECT DISTINCT action||':'||(detail->>'cause') FROM audit_logs WHERE action LIKE 'attachment.%' AND result='deny'").splitlines()))
    codes['deny_causes'] = causes
    check('attachment.download:privileged_only' in causes and 'attachment.upload:sensitive_level_required' in causes
          and 'attachment.download:scan_pending' in causes and 'attachment.download:not_found' in causes,
          '⑥ deny causes include privileged_only · sensitive_level_required · scan_pending · not_found')
    privs = sql("SELECT has_table_privilege('tbox_app','community.page_attachments','DELETE')||'|'||"
                "has_table_privilege('tbox_app','community.page_attachments','INSERT')||'|'||"
                "has_column_privilege('tbox_app','community.page_attachments','scan_status','UPDATE')||'|'||"
                "has_column_privilege('tbox_app','community.page_attachments','original_name','UPDATE')||'|'||"
                "has_column_privilege('tbox_app','community.page_attachments','page_id','UPDATE')||'|'||"
                "has_table_privilege('tbox_app','community.page_attachment_purge_queue','SELECT')||'|'||"
                "has_table_privilege('tbox_backup','community.page_attachments','SELECT')||'|'||"
                "has_table_privilege('tbox_backup','community.page_attachment_purge_queue','SELECT')")
    codes['grants'] = privs
    check(privs == 'false|true|true|false|false|false|true|true', 'grants: tbox_app DELETE 0 · INSERT · UPDATE(scan_status) only · queue 0 · tbox_backup SELECT both')
    denied = php("try{Db::instance()->run('DELETE FROM community.page_attachments WHERE id=0');echo 'ran';}catch(Throwable $e){echo (str_contains($e->getMessage(),'permission denied')?'permission denied':'other');}")
    upd = php("try{Db::instance()->run(\"UPDATE community.page_attachments SET original_name='x' WHERE id=0\");echo 'ran';}catch(Throwable $e){echo (str_contains($e->getMessage(),'permission denied')?'permission denied':'other');}")
    check(denied == 'permission denied' and upd == 'permission denied', 'app account DELETE / UPDATE(original_name) → permission denied')
    fn = sql("SELECT prosrc FROM pg_proc WHERE proname='purge_trash' AND pronamespace='community'::regnamespace")
    check('page_attachment_purge_queue' in fn and 'page_suggestions' in fn and fn.index('purge_queue') < fn.index('DELETE FROM community.page_attachments'),
          'purge_trash body: queue names before deleting attachment rows · suggestions line kept')

    # ════════ ⑤ 수명 ═══════════════════════════════════════════════
    pp = page('U3 영구 삭제 문서')
    pa1 = up_ok(pp, '지울그림.png', PNG, label='⑤ upload 1 to purge doc')
    pa2 = up_ok(pp, '지울글.txt', TXT, label='⑤ upload 2 to purge doc')
    names = sql('SELECT stored_name FROM community.page_attachments WHERE page_id=' + str(pk(pp)) + ' ORDER BY id').splitlines()
    paths = [storage / 'attachments' / s[:2] / s for s in names]
    check(len(paths) == 2 and all(p.is_file() for p in paths), '⑤ two attachment files on disk before purge')
    expect('DELETE', '/api/pages/' + pp, None, 'A', 200, '⑤ trash purge doc')
    sql("UPDATE community.pages SET purge_after=now()-interval '1 day' WHERE public_id=" + lit(pp))
    purged = sql('SELECT page_public_id FROM community.purge_trash(false, 500)')
    check(pp in purged.splitlines(), '⑤ purge_trash(false) (called directly) purged the doc')
    check(n('SELECT count(*) FROM community.page_attachments WHERE page_id=' + str(pk(pp))) == 0, '⑤ attachment rows 0 after purge_trash')
    check(set(sql('SELECT stored_name FROM community.page_attachment_purge_queue').splitlines()) >= set(names), '⑤ disk names queued')
    check(all(p.is_file() for p in paths), '⑤ files still on disk until community_purge.php runs (rows first, files by the script)')
    line = (Path.home() / '.config/qaredi/pgpass-testdb').read_text().splitlines()[0].split(':', 4)
    own_pgpass = work / 'owner.pgpass'
    own_pgpass.write_text('*:*:' + manifest['database'] + ':' + line[3] + ':' + line[4] + '\n')
    own_pgpass.chmod(0o600)
    dry = subprocess.run(['php', 'scripts/community_purge.php'], cwd=root, capture_output=True, text=True, timeout=120,
                         env=dict(os.environ, TBOX_OWNER_PGPASS=str(own_pgpass), TBOX_OWNER_DB_USER='tbox_testdb'))
    check(dry.returncode == 0 and all(p.is_file() for p in paths) and '[dry] 지울 첨부 파일: 2개' in dry.stdout, '⑤ community_purge.php dry: files kept · count shown')
    run = subprocess.run(['php', 'scripts/community_purge.php', '--run'], cwd=root, capture_output=True, text=True, timeout=120,
                         env=dict(os.environ, TBOX_OWNER_PGPASS=str(own_pgpass), TBOX_OWNER_DB_USER='tbox_testdb'))
    own_pgpass.unlink()
    (out / 'community_purge.stdout.txt').write_text(dry.stdout + '---\n' + run.stdout)
    codes['community_purge_rc'] = [dry.returncode, run.returncode]
    check(run.returncode == 0 and not any(p.exists() for p in paths), '⑤ community_purge.php --run: attachment files 0 on disk')
    check(n('SELECT count(*) FROM community.page_attachment_purge_queue') == 0, '⑤ purge queue 0 after script')
    check(not any(s in run.stdout for s in names) and str(storage) not in run.stdout, '⑤ script stdout has no disk name · path')
    check(n("SELECT count(*) FROM audit_logs WHERE action='page.purge' AND result='allow' AND (detail->>'attachment_files')::int=2") == 1,
          '⑤ audit page.purge allow detail attachment_files=2')

    # ════════ ② 일일 총량 — 자료실 + 첨부 합산 경계 (두 방향) ═══════════════
    def used(k):
        return n("SELECT (SELECT COALESCE(SUM(size_bytes),0) FROM files WHERE uploaded_by=" + str(uid(k)) + " AND created_at > now()-interval '24 hours')"
                 "+(SELECT COALESCE(SUM(size_bytes),0) FROM community.page_attachments WHERE uploaded_by=" + str(uid(k)) + " AND created_at > now()-interval '24 hours')")

    def chunks(total):
        while total > 0:
            yield min(total, MAXB)
            total -= min(total, MAXB)
    # B: 자료실 행으로 채우고 첨부 올리기
    fill = QUOTA - used('B') - 100
    for i, size in enumerate(chunks(fill)):
        sql("INSERT INTO files(original_name,stored_name,ext,mime,size_bytes,sha256,access_level,uploaded_by) VALUES('합성 총량 "
            + str(i) + ".txt','" + str(uuid.uuid4()) + ".txt','txt','text/plain'," + str(size) + ",'" + '0' * 64 + "','internal'," + str(uid('B')) + ')')
    check(used('B') == QUOTA - 100, '② quota setup B: t-box files rows bring B to quota-100 B')
    r = upload(p1, '총량101.txt', b'q' * 101, 'B')
    check(r.status_code == 400 and r.json().get('reason') == 'quota_exceeded', '② attachment over (files + attachments) quota by 1 B → 400 quota_exceeded')
    codes['reason_quota_exceeded'] = r.json().get('error') if r.status_code == 400 else None
    up_ok(p1, '총량100.txt', b'q' * 100, 'B', '② attachment exactly at quota → 201')
    # C: 첨부 행으로 채우고 자료실 올리기
    fill = QUOTA - used('C') - 100
    for i, size in enumerate(chunks(fill)):
        sql("INSERT INTO community.page_attachments(page_id,uploaded_by,original_name,ext,mime,size_bytes,sha256,stored_name,scan_status) VALUES("
            + str(pk(pc)) + ',' + str(uid('C')) + ",'합성 총량 " + str(i) + ".txt','txt','text/plain'," + str(size) + ",'" + '0' * 64 + "','"
            + str(uuid.uuid4()) + ".txt','clean')")
    check(used('C') == QUOTA - 100, '② quota setup C: attachment rows bring C to quota-100 B')
    t = tbox_upload('C', '총량101.txt', b'q' * 101)
    check(t.status_code == 400 and t.json().get('error') == codes['reason_quota_exceeded'], '② t-box upload over quota counted from attachments → 400 (same sentence)')
    t = tbox_upload('C', '총량100.txt', b'q' * 100)
    check(t.status_code == 201, '② t-box upload exactly at quota → 201')

    # ════════ ⑥ 감사 · 노출 ════════════════════════════════════════
    all_names = set(sql('SELECT stored_name FROM community.page_attachments').splitlines()) | set(names)
    blob = sql("SELECT coalesce(string_agg(coalesce(detail::text,'')||' '||summary||' '||coalesce(target_name,''),' '),'') FROM audit_logs")
    logtext = (work / 'logs/audit.log').read_text() if (work / 'logs/audit.log').exists() else ''
    leak = [s for s in all_names if s in blob or s in logtext]
    check(not leak, '⑥ audit DB + audit.log: no attachment disk name (' + str(len(all_names)) + ' checked)')
    check(str(storage) not in blob and str(storage) not in logtext, '⑥ audit: no storage path')
    check(rrn() not in blob and rrn() not in logtext and rrn().replace('-', '') not in blob, '⑥ audit: sensitive raw text (synthetic RRN) 0')
    check('"rrn": 1' in blob or '"rrn":1' in blob, '⑥ audit keeps only pattern name → count for sensitive (like t-box)')
    httplog = (work / 'logs/php.log').read_text(errors='replace') if (work / 'logs/php.log').exists() else ''
    check(not any(s in httplog for s in all_names) and rrn() not in httplog, '⑥ php server log: no disk name · sensitive text')
    cnt = {k: n("SELECT count(*) FROM audit_logs WHERE action='attachment." + k.split('|')[0] + "' AND result='" + k.split('|')[1] + "'")
           for k in ('upload|allow', 'upload|deny', 'download|allow', 'download|deny')}
    codes['audit_counts_final'] = cnt
    sample = sql("SELECT summary FROM audit_logs WHERE action='attachment.upload' AND result='deny' ORDER BY id LIMIT 1")
    codes['audit_summary_sample_upload_deny'] = re.sub(r'claude_[a-z0-9]+', '{actor}', sample)

    # 기록만(단언 아님) — 시험이 SQL 로 심은 행(바이트 없음)이 남은 상태의 정합성 검사. 단언은 아래 1b ① (심은 행을 걷어 낸 뒤).
    # 출력에는 디스크 이름이 들 수 있으므로 종료 코드와 「orphan」 줄 수만 남긴다.
    cc = subprocess.run(['php', 'scripts/consistency_check.php', '--strict'], cwd=root, capture_output=True, text=True, timeout=300)
    codes['consistency_check_strict_rc_with_attachments'] = cc.returncode
    codes['consistency_check_orphan_mentions'] = (cc.stdout + cc.stderr).count('orphan')

    # ══════════════════════════════════════════════════════════════════════════════════════════════
    # U3-1b 바닥 후속 (지시서 「U3-1b」 완료 기준 ①~④) — 순서: ③ 내보내기 → ④ 다른 쓰기 입구 → ① 정합성 검사 → ② 탈퇴 파기(B 를 익명화하므로 마지막)
    # ══════════════════════════════════════════════════════════════════════════════════════════════
    import hashlib
    import secrets

    # ════════ 1b ③ 내보내기 · 인쇄 · 공개 링크 ═════════════════════════
    pe = page('U3b 내보내기 문서')
    ei = up_ok(pe, '내보낼그림.png', PNG, label='1b③ upload png to export doc')
    ef = up_ok(pe, '내보낼글.txt', TXT, label='1b③ upload txt to export doc')
    r = save_doc(pe, doc_with(('image', 'att:' + ei['id']), ('file', 'att:' + ef['id'])))
    check(r.status_code == 200, '1b③ export doc body holds own att: image + file (got ' + str(r.status_code) + ')')
    raw_path = '/community/api/pages/' + pe + '/attachments/' + ei['id'] + '/raw'
    dl_path = '/community/api/pages/' + pe + '/attachments/' + ef['id'] + '/download'
    for fmt in ('md', 'html'):
        r = req('GET', '/api/pages/' + pe + '/export?format=' + fmt, None, 'A')
        body = r.text if r.status_code == 200 else ''
        esc = (lambda s: s.replace('.', '\\.')) if fmt == 'md' else (lambda s: s)   # md 는 글자 속 문장 부호를 \ 로 막는다(DocMarkdown::escape)
        check(r.status_code == 200 and raw_path in body and dl_path in body and '볼 수 없는 파일' not in body
              and esc('내보낼그림.png') in body and esc('내보낼글.txt') in body,
              '1b③ export ' + fmt + ': att: image → login-required /raw link · file → /download link (not 「볼 수 없는 파일」)')
        check(manifest['base_url'].removesuffix('/tbox') + raw_path in body, '1b③ export ' + fmt + ': link is the absolute APP_URL address (like t-box file_href)')
    r = req('GET', '/p/' + pe + '/print', None, 'A')
    body = r.text if r.status_code == 200 else ''
    check(r.status_code == 200 and raw_path in body and dl_path in body and '볼 수 없는 파일' not in body, '1b③ print page: att: links to /raw · /download')
    codes['export_att_href_shape'] = '{APP_URL}/community/api/pages/{page}/attachments/{att}/raw|download'
    expect('GET', raw_path.removeprefix('/community'), None, 'A', 200, '1b③ exported image link works for the owner (window re-judges)')
    expect('GET', dl_path.removeprefix('/community'), None, None, 401, '1b③ exported link without login → 401 (login-required address)')
    # 관리자 특권 갈래로 읽히는 문서의 내보내기에는 첨부 지도가 없다 — 내보내기 자체가 닫혀 있다(decidePageExport)
    r = req('GET', '/api/pages/' + pe + '/export?format=md', None, 'M')
    check(r.status_code in (403, 404), '1b③ Admin (privileged branch) export of private doc closed (' + str(r.status_code) + ')')
    # 다른 문서의 att: 을 SQL 로 심은 문서 — 내보내기는 「볼 수 없는 파일」(그 문서 첨부 지도에 없음)
    pe2 = page('U3b 남의 첨부 심은 문서')
    sql('UPDATE community.pages SET doc=' + lit(json.dumps(doc_with(('file', 'att:' + ef['id'])))) + '::jsonb WHERE public_id=' + lit(pe2))
    r = req('GET', '/api/pages/' + pe2 + '/export?format=md', None, 'A')
    check(r.status_code == 200 and '볼 수 없는 파일' in r.text and ef['id'] not in r.text, '1b③ export: (planted) other doc att: → 「볼 수 없는 파일」 · id 0')
    # 공개 링크(/pub/) — 그대로 「볼 수 없는 파일」
    space_a = int(sql('SELECT space_id FROM community.pages WHERE public_id=' + lit(pe)))
    # (준비) 공간의 웹 게시 허용은 Admin 재인증 창구라 시험이 SQL 로 켠다(합성 DB) — 판정 대상은 아래 공개 화면이다
    sql('UPDATE community.spaces SET web_publish_allowed=true WHERE id=' + str(space_a))
    r = expect('POST', '/api/pages/' + pe + '/web-publish', {'confirm_public': True}, 'A', 201, '1b③ web-publish export doc')
    pub_url = r.json().get('url', '') if r.status_code == 201 else ''
    pr = requests.get(site + pub_url, headers={'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d')}, timeout=35)
    check(pr.status_code == 200 and pr.text.count('볼 수 없는 파일') >= 2 and ei['id'] not in pr.text and ef['id'] not in pr.text
          and '/attachments/' not in pr.text, '1b③ public link /pub/: att: still 「볼 수 없는 파일」 · attachment ids · window address 0')

    # ════════ 1b ④ 다른 쓰기 입구 — 새 문서 · 템플릿 · 게시판 새 글 · AI · MCP ═══════════════
    other_att = doc_with(('image', 'att:' + a1['id']))
    r = req('POST', '/api/pages', {'title': 'U3b 새 문서 남의 첨부', 'doc': other_att}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'bad_attachment_ref' and r.json().get('error') == codes['bad_attachment_ref_error'],
          '1b④ new doc (session) with att: (even own other doc) → 400 bad_attachment_ref (same sentence as save)')
    r = req('POST', '/api/pages', {'title': 'U3b 새 문서 없는 첨부', 'doc': doc_with(('file', 'att:' + str(uuid.uuid4())))}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'bad_attachment_ref', '1b④ new doc with unknown att: → 400 bad_attachment_ref')
    check(n("SELECT count(*) FROM community.pages WHERE title LIKE 'U3b 새 문서%'") == 0, '1b④ refused new docs not created')
    # MCP — 프로그램 토큰(개인 · community.write)으로 POST /api/pages (clients/mcp create_page 와 같은 창구)
    tok = secrets.token_hex(24)
    sql("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES(" + str(uid('A')) + ",'U3b MCP 합성','"
        + hashlib.sha256(tok.encode()).hexdigest() + "','" + tok[:6] + "'," + str(uid('A')) + ",'community.read+community.write',now()+interval '1 hour')")
    th = {'User-Agent': 'tbox-review/' + time.strftime('%Y-%m-%d'), 'X-API-Token': tok}
    r = requests.post(base + '/api/pages', json={'title': 'U3b MCP 남의 첨부', 'doc': other_att}, headers=th, timeout=35)
    check(r.status_code == 400 and r.json().get('reason') == 'bad_attachment_ref', '1b④ MCP (program token POST /api/pages) with att: → 400 bad_attachment_ref')
    r = requests.post(base + '/api/pages', json={'title': 'U3b MCP 대조', 'doc': {'schema': 1, 'blocks': [{'id': str(uuid.uuid4()), 'type': 'paragraph', 'text': []}]}},
                      headers=th, timeout=35)
    check(r.status_code == 201, '1b④ (control) MCP token create without att: → 201 (got ' + str(r.status_code) + ')')
    sql("UPDATE api_tokens SET revoked_at=now() WHERE token_hash='" + hashlib.sha256(tok.encode()).hexdigest() + "'")
    # 템플릿 — 템플릿 만들기는 그림 · 파일 블록을 빼고 담는다(자료실 파일과 같다) · 심어 둔 att: 템플릿으로 새 문서 → 400
    r = req('POST', '/api/templates', {'page_id': pe, 'name': 'U3b 첨부 든 문서 틀'}, 'A')
    tpl_id = (r.json().get('template') or {}).get('id') if r.status_code == 201 else None
    tdoc = sql('SELECT doc::text FROM community.templates WHERE public_id=' + lit(tpl_id)) if tpl_id else ''
    check(r.status_code == 201 and r.json().get('dropped_blocks', 0) >= 2 and 'att:' not in tdoc and tdoc != '',
          '1b④ template from doc with att: blocks → att: blocks dropped (like t-box files) · template body has no att:')
    if tpl_id:
        sql('UPDATE community.templates SET doc=' + lit(json.dumps(doc_with(('file', 'att:' + ef['id'])))) + '::jsonb WHERE public_id=' + lit(tpl_id))
    r = req('POST', '/api/pages', {'template_id': tpl_id}, 'A')
    check(r.status_code == 400 and r.json().get('reason') == 'bad_attachment_ref', '1b④ new doc from (planted) template holding att: → 400 bad_attachment_ref')
    # 게시판 새 글 — 본문을 받지 않는다(허용 키 title · tags) → doc 를 보내면 400
    r = req('POST', '/api/boards/' + str(board) + '/pages', {'title': 'U3b 게시판 글', 'doc': other_att}, 'A')
    check(r.status_code == 400, '1b④ board new post with doc (att:) → 400 (body not accepted — allowed keys)')
    expect('POST', '/api/boards/' + str(board) + '/pages', {'title': 'U3b 게시판 대조'}, 'A', 201, '1b④ (control) board new post without doc → 201')
    # AI 넣기 — 받는 키는 log_id · doc_version 뿐(doc 를 보내면 400) · AI 글은 문단 글자로만 들어간다(att: 글자도 file_ref 가 되지 않음)
    pai = page('U3b AI 넣기 문서')
    r = req('POST', '/api/pages/' + pai + '/ai-insert', {'log_id': str(uuid.uuid4()), 'doc_version': 1, 'doc': other_att}, 'A')
    check(r.status_code == 400, '1b④ AI insert with doc key (att:) → 400 (keys log_id · doc_version only)')
    log_id = sql("INSERT INTO community.ai_log(user_id,feature,page_refs,file_refs,input_text,output_text,model,started_at,status,chars_in,chars_out) VALUES("
                 + str(uid('A')) + ",'assistant','[]','[]','합성 입력'," + lit('att:' + a1['id'] + ' 합성 AI 글') + ",'synthetic',now(),'ok',5,40) RETURNING public_id").splitlines()[0]
    cur = req('GET', '/api/pages/' + pai, None, 'A').json()['page']['doc_version']
    r = req('POST', '/api/pages/' + pai + '/ai-insert', {'log_id': log_id, 'doc_version': cur}, 'A')
    adoc = json.dumps(req('GET', '/api/pages/' + pai, None, 'A').json()['page']['doc'], ensure_ascii=False)
    check(r.status_code == 200 and 'file_ref' not in adoc and ('att:' + a1['id']) in adoc,
          '1b④ AI insert of output holding "att:<id>" text → paragraph text only (no file_ref) via common save (SaveDoc)')
    codes['write_entrances'] = {
        'POST /api/pages (session · template_id · program token = MCP)': 'pages_create.php — att: → 400 bad_attachment_ref',
        'POST /api/templates': 'templates_create.php — Doc::templateBody drops image/file blocks · defensive att: → 400',
        'POST /api/boards/{num}/pages': 'board_pages_create.php — no body accepted (title · tags) → doc 400',
        'POST /api/pages/{id}/ai-insert': 'ai_helpers.php — text paragraphs only · community_save_doc (SaveDoc att: check)',
        'PATCH /api/pages/{id} · suggestion accept': 'community_save_doc (SaveDoc att: check — U3-1)',
        'POST /api/pages/{id}/versions/{n}/restore': 'version_restore.php (U3-1)',
        'server-built bodies': 'space_me · forms · integrations(connector) · automation create_page(Doc::templateBody) — no att:',
    }

    # ════════ 1b ① 정합성 검사 — 첨부 구역(누락 · 해시 불일치 · 고아 · 대기열은 고아 아님) ═══════════════
    def cc_run():
        done = subprocess.run(['php', 'scripts/consistency_check.php', '--strict'], cwd=root, capture_output=True, text=True, timeout=600)
        d = json.loads(sql("SELECT detail::text FROM audit_logs WHERE action='audit.integrity' ORDER BY id DESC LIMIT 1") or '{}')
        kinds = {}
        for m in (d.get('mismatches') if isinstance(d.get('mismatches'), list) else []):
            key = (m.get('area') or 'files') + ':' + m.get('kind', '?')
            kinds[key] = kinds.get(key, 0) + 1
        info = {k: d.get(k) for k in ('attachment_rows', 'attachment_disk_files', 'attachment_queued', 'attachment_trash_missing',
                                      'quarantined', 'quarantine_orphan', 'db_rows', 'disk_files')}
        return done.returncode, kinds, info

    rc, kinds, info = cc_run()
    codes['cc_before_cleanup'] = {'rc': rc, 'kinds': kinds, 'info': info}
    # 시험이 SQL 로 심은 행(바이트 없음 — 총량 채우기 · 이동 방어)을 걷어 낸다(합성 DB) — 그 뒤가 「첨부 정상」 기준 상태다
    sql("DELETE FROM files WHERE original_name LIKE '합성 총량 %'")
    sql("DELETE FROM community.page_attachments WHERE sha256='" + '0' * 64 + "'")
    rc, kinds, info = cc_run()
    codes['cc_clean'] = {'rc': rc, 'kinds': kinds, 'info': info}
    check(rc == 0 and kinds == {} and (info['attachment_rows'] or 0) > 10 and (info['attachment_disk_files'] or 0) > 10,
          '1b① consistency --strict with healthy attachments → rc 0 · mismatches 0 (attachment area counted: rows · disk files, EICAR in quarantine)')
    check((info['quarantined'] or 0) >= 1 and info['quarantine_orphan'] == 0, '1b① infected attachment in quarantine counted as quarantined (not quarantine orphan)')
    pcx = page('U3b 정합성 문서')
    m1 = up_ok(pcx, '정합1.txt', TXT, label='1b① upload 1')
    m2 = up_ok(pcx, '정합2.png', PNG, label='1b① upload 2')
    s1 = sql('SELECT stored_name FROM community.page_attachments WHERE public_id=' + lit(m1['id']))
    s2 = sql('SELECT stored_name FROM community.page_attachments WHERE public_id=' + lit(m2['id']))
    f1 = storage / 'attachments' / s1[:2] / s1
    f2 = storage / 'attachments' / s2[:2] / s2
    rc, kinds, info = cc_run()
    check(rc == 0 and kinds == {}, '1b① (control) two new attachments → rc 0')
    # 누락
    hold = work / 'u3b-held.bin'
    f1.rename(hold)
    rc, kinds, info = cc_run()
    codes['cc_missing'] = {'rc': rc, 'kinds': kinds}
    check(rc == 2 and kinds == {'attachments:missing': 1}, '1b① attachment disk file removed → missing 1 (area attachments) · strict rc 2')
    hold.rename(f1)
    # 해시 불일치
    keep = f2.read_bytes()
    f2.write_bytes(keep + b'\0')
    rc, kinds, info = cc_run()
    codes['cc_hash'] = {'rc': rc, 'kinds': kinds}
    check(rc == 2 and kinds == {'attachments:hash_mismatch': 1}, '1b① attachment bytes changed → hash_mismatch 1 · strict rc 2')
    f2.write_bytes(keep)
    # 고아 — DB 가 모르는 파일을 attachments/ 에
    on = str(uuid.uuid4()) + '.txt'
    orphan = storage / 'attachments' / on[:2] / on
    orphan.parent.mkdir(mode=0o750, parents=True, exist_ok=True)
    orphan.write_bytes(b'orphan')
    rc, kinds, info = cc_run()
    codes['cc_orphan'] = {'rc': rc, 'kinds': kinds}
    check(rc == 2 and kinds == {'attachments:orphan': 1}, '1b① unknown file under attachments/ → orphan 1 · strict rc 2')
    # 제자리가 아닌 폴더에 둔 같은 이름(행 있는 이름)도 고아
    misplaced = storage / 'attachments' / 'zz' / s1
    misplaced.parent.mkdir(mode=0o750, exist_ok=True)
    misplaced.write_bytes(f1.read_bytes())
    orphan.unlink()
    rc, kinds, info = cc_run()
    check(rc == 2 and kinds == {'attachments:orphan': 1}, '1b① copy of a known name in a wrong subfolder → orphan 1')
    misplaced.unlink()
    misplaced.parent.rmdir()
    # 대기열 — 영구 삭제(purge_trash)로 행은 지워지고 파일은 스크립트를 기다리는 상태 → 고아 아님
    pq = page('U3b 대기열 문서')
    qa = up_ok(pq, '대기.txt', TXT, label='1b① upload to doc that will be purged')
    qname = sql('SELECT stored_name FROM community.page_attachments WHERE public_id=' + lit(qa['id']))
    expect('DELETE', '/api/pages/' + pq, None, 'A', 200, '1b① trash queue doc')
    sql("UPDATE community.pages SET purge_after=now()-interval '1 day' WHERE public_id=" + lit(pq))
    sql('SELECT count(*) FROM community.purge_trash(false, 500)')
    qfile = storage / 'attachments' / qname[:2] / qname
    check(qfile.is_file() and n('SELECT count(*) FROM community.page_attachments WHERE page_id=' + str(pk(pq))) == 0, '1b① (setup) row purged · file waiting in queue')
    rc, kinds, info = cc_run()
    codes['cc_queue'] = {'rc': rc, 'kinds': kinds, 'info': info}
    check(rc == 0 and kinds == {} and info['attachment_queued'] == 1, '1b① purge-queue file is not an orphan (attachment_queued 1) · rc 0')
    qfn = sql("SELECT has_function_privilege('tbox_app','community.page_attachment_queued(text[])','EXECUTE')||'|'||"
              "has_function_privilege('tbox_backup','community.page_attachment_queued(text[])','EXECUTE')||'|'||"
              "has_table_privilege('tbox_app','community.page_attachment_purge_queue','SELECT')||'|'||"
              "(SELECT prosecdef FROM pg_proc WHERE oid='community.page_attachment_queued(text[])'::regprocedure)||'|'||"
              "(SELECT array_to_string(proconfig,',') FROM pg_proc WHERE oid='community.page_attachment_queued(text[])'::regprocedure)")
    codes['queue_fn_privs'] = qfn
    check(qfn == 'true|false|false|true|search_path=community, pg_temp',
          '1b① queue check fn: tbox_app EXECUTE · backup none · queue table SELECT still 0 · SECURITY DEFINER · search_path fixed')
    probe = php("echo json_encode(Db::instance()->run('SELECT n FROM community.page_attachment_queued(CAST(:a AS text[])) AS n',"
                "[':a'=>'{" + qname + "," + str(uuid.uuid4()) + ".txt}'])->fetchAll(PDO::FETCH_COLUMN));")
    check(probe == json.dumps([qname]), '1b① app account: queue fn answers only membership of given names')
    tq = php("try{Db::instance()->run('SELECT count(*) FROM community.page_attachment_purge_queue');echo 'ran';}catch(Throwable $e){echo (str_contains($e->getMessage(),'permission denied')?'permission denied':'other');}")
    check(tq == 'permission denied', '1b① app account still cannot read the queue table directly')

    # ════════ 1b ② 탈퇴 파기 — 개인 공간 문서 첨부: 행 0 · 디스크 0 / 팀 공간 · 남의 문서에 올린 첨부는 남는다 ═══════════
    # (a) 함수 직접(소유자) — A 의 문서 하나
    pfd = page('U3b 파기 함수 직접')
    fd = up_ok(pfd, '파기직접.txt', TXT, label='1b② upload to doc for direct function call')
    fdn = sql('SELECT stored_name FROM community.page_attachments WHERE public_id=' + lit(fd['id']))
    sql('SELECT community.privacy_purge_pages(ARRAY[' + str(pk(pfd)) + ']::bigint[])')
    check(n('SELECT count(*) FROM community.page_attachments WHERE page_id=' + str(pk(pfd))) == 0
          and n('SELECT count(*) FROM community.page_attachment_purge_queue WHERE stored_name=' + lit(fdn)) == 1
          and (storage / 'attachments' / fdn[:2] / fdn).is_file(),
          '1b② privacy_purge_pages (called directly) → attachment rows 0 · name queued · file waits for the script')
    pfn = sql("SELECT prosrc FROM pg_proc WHERE oid='community.privacy_purge_pages(bigint[])'::regprocedure")
    check('page_attachment_purge_queue' in pfn and pfn.index('purge_queue') < pfn.index('DELETE FROM community.page_attachments')
          and 'page_embeddings' in pfn and 'synced_sources' in pfn, '1b② privacy_purge_pages body: queue before delete · old lines kept (AI index · synced)')
    pfp = sql("SELECT has_function_privilege('tbox_app','community.privacy_purge_pages(bigint[])','EXECUTE')||'|'||"
              "(SELECT prosecdef FROM pg_proc WHERE oid='community.privacy_purge_pages(bigint[])'::regprocedure)||'|'||"
              "pg_get_function_result('community.privacy_purge_pages(bigint[])'::regprocedure)")
    check(pfp == 'false|false|bigint', '1b② privacy_purge_pages: same signature (returns bigint) · INVOKER · tbox_app EXECUTE 0')
    # (b) 합성 탈퇴 사용자 B — 개인 공간 문서 둘(하나는 휴지통) · 팀 공간 문서 · 남의 문서(A 의 p1 — 앞에서 올린 총량100.txt)
    pb = page('U3b 탈퇴 개인 문서', 'B')
    b1 = up_ok(pb, '탈퇴그림.png', PNG, 'B', '1b② B uploads png to own personal doc')
    b2 = up_ok(pb, '탈퇴글.txt', TXT, 'B', '1b② B uploads txt to own personal doc')
    pbt = page('U3b 탈퇴 휴지통 문서', 'B')
    b3 = up_ok(pbt, '휴지통.txt', TXT, 'B', '1b② B uploads to personal doc then trashes it')
    expect('DELETE', '/api/pages/' + pbt, None, 'B', 200, '1b② B trashes second personal doc')
    bt = up_ok(pt, '팀에남을글.txt', TXT, 'B', '1b② B uploads to team-space doc (team X)')
    b_names = sql('SELECT stored_name FROM community.page_attachments WHERE public_id IN (' + ','.join(lit(x['id']) for x in (b1, b2, b3)) + ') ORDER BY id').splitlines()
    keep_names = sql('SELECT stored_name FROM community.page_attachments WHERE uploaded_by=' + str(uid('B'))
                     + ' AND public_id NOT IN (' + ','.join(lit(x['id']) for x in (b1, b2, b3)) + ') ORDER BY id').splitlines()
    check(len(b_names) == 3 and len(keep_names) >= 2, '1b② (setup) B: 3 personal-doc attachments · ' + str(len(keep_names)) + ' on team/other docs')
    queued_before = set(sql('SELECT stored_name FROM community.page_attachment_purge_queue').splitlines())
    adm = uid('M')
    sql('INSERT INTO account_deletion_requests(user_id) VALUES(' + str(uid('B')) + ')')
    sql("UPDATE account_deletion_requests SET status='processed', processed_by=" + str(adm) + ' WHERE user_id=' + str(uid('B')))
    sql("ALTER TABLE account_deletion_requests DISABLE TRIGGER account_deletion_requests_guard; UPDATE account_deletion_requests SET processed_at=now()-interval '31 days' WHERE user_id="
        + str(uid('B')) + '; ALTER TABLE account_deletion_requests ENABLE TRIGGER account_deletion_requests_guard;')
    sql('UPDATE users SET is_active=false WHERE id=' + str(uid('B')))
    line = (Path.home() / '.config/qaredi/pgpass-testdb').read_text().splitlines()[0].split(':', 4)
    own_pgpass = work / 'owner.pgpass'
    own_pgpass.write_text('*:*:' + manifest['database'] + ':' + line[3] + ':' + line[4] + '\n')
    own_pgpass.chmod(0o600)
    penv = dict(os.environ, TBOX_OWNER_PGPASS=str(own_pgpass), TBOX_OWNER_ROLE='tbox_testdb')
    dry = subprocess.run(['php', 'scripts/privacy_purge.php'], cwd=root, capture_output=True, text=True, timeout=180, env=penv)
    all_b = [storage / 'attachments' / s[:2] / s for s in b_names]
    check(dry.returncode == 0 and all(p.is_file() for p in all_b) and n('SELECT count(*) FROM community.page_attachments WHERE uploaded_by=' + str(uid('B'))) == 3 + len(keep_names)
          and ('[dry] 대기열의 문서 첨부 파일: ' + str(len(queued_before)) + '개') in dry.stdout,
          '1b② privacy_purge.php dry: rows · files unchanged · queue count shown')
    run = subprocess.run(['php', 'scripts/privacy_purge.php', '--run'], cwd=root, capture_output=True, text=True, timeout=180, env=penv)
    own_pgpass.unlink()
    (out / 'privacy_purge.stdout.txt').write_text('$ php scripts/privacy_purge.php\nexit=' + str(dry.returncode) + '\n' + dry.stdout
                                                   + '---\n$ php scripts/privacy_purge.php --run\nexit=' + str(run.returncode) + '\n' + run.stdout)
    codes['privacy_purge_rc'] = [dry.returncode, run.returncode]
    removed_expected = len(queued_before) + 3
    check(run.returncode == 0 and ('문서 첨부 파일: ' + str(removed_expected) + '개 지움') in run.stdout,
          '1b② privacy_purge.php --run exit 0 · removed ' + str(removed_expected) + ' attachment files (B personal 3 + queued before)')
    check(n('SELECT count(*) FROM community.page_attachments WHERE public_id IN (' + ','.join(lit(x['id']) for x in (b1, b2, b3)) + ')') == 0,
          '1b② B personal-doc attachment rows 0 (incl. trashed personal doc)')
    check(not any(p.exists() for p in all_b), '1b② B personal-doc attachment disk files 0')
    check(not (storage / 'attachments' / fdn[:2] / fdn).exists() and not qfile.exists(), '1b② files queued earlier (direct call · purge_trash) also removed by the same drain')
    check(n('SELECT count(*) FROM community.page_attachment_purge_queue') == 0, '1b② purge queue 0 after the script')
    keep_paths = [storage / 'attachments' / s[:2] / s for s in keep_names]
    check(n('SELECT count(*) FROM community.page_attachments WHERE uploaded_by=' + str(uid('B'))) == len(keep_names) and all(p.is_file() for p in keep_paths),
          '1b② attachments B put on team-space / other people\'s docs remain (row + file)')
    expect('GET', att_url(pt, bt['id'], 'download'), None, 'A', 200, '1b② team doc attachment uploaded by purged B still downloadable by team member A')
    check(sql('SELECT title||\'|\'||(purged_at IS NOT NULL) FROM community.pages WHERE public_id=' + lit(pb)) == '영구 삭제된 페이지|true'
          and sql('SELECT username FROM users WHERE id=' + str(uid('B'))) == 'deleted-' + str(uid('B')), '1b② B anonymized · personal doc purged')
    check(n("SELECT count(*) FROM audit_logs WHERE action='privacy.purge' AND result='allow' AND (detail->>'attachment_files')::int=" + str(removed_expected)) == 1,
          '1b② audit privacy.purge allow detail.attachment_files=' + str(removed_expected))
    check(n("SELECT count(*) FROM audit_logs WHERE action='account.purge' AND result='allow' AND target_id=" + str(uid('B'))) == 1, '1b② audit account.purge allow for B')
    leak_names = set(b_names) | {fdn, qname}
    check(not any(s in run.stdout or s in dry.stdout for s in leak_names) and str(storage) not in run.stdout, '1b② script stdout: no disk name · path')
    codes['purge_rule_check'] = ('privacy-terms-plan 5.1 「탈퇴」: 개인 공간 문서(게시하지 않은 것) 내용 삭제 · 팀 공간 · 게시판 글은 남김 — '
                                 '첨부는 문서를 따른다(개인 문서 첨부 삭제 · 팀 공간 · 남의 문서에 올린 첨부는 남음)')
    # 파기 뒤 정합성 — 대기열을 비운 뒤 불일치 0
    rc, kinds, info = cc_run()
    codes['cc_after_privacy'] = {'rc': rc, 'kinds': kinds, 'info': info}
    check(rc == 0 and kinds == {} and info['attachment_queued'] == 0, '1b①② consistency after privacy purge + drain → rc 0 · no orphan left')
    # 1b 감사 노출 — 정합성 검사 detail 은 자료실과 같이 이름만(불일치 때) · 그 밖 1b 기록에 디스크 경로 · 민감 원문 0
    blob = sql("SELECT coalesce(string_agg(coalesce(detail::text,'')||' '||summary||' '||coalesce(target_name,''),' '),'') FROM audit_logs WHERE action IN ('privacy.purge','account.purge','page.purge')")
    check(not any(s in blob for s in leak_names) and str(storage) not in blob, '1b audit (privacy.purge · account.purge · page.purge): no attachment disk name · path')
    blob_all = sql("SELECT coalesce(string_agg(coalesce(detail::text,''),' '),'') FROM audit_logs")
    check(str(storage) not in blob_all and rrn() not in blob_all, '1b audit (all rows incl. audit.integrity): no storage path · sensitive text')

    # ════════ 새 설치 = 마이그레이션 ×2 (표 · 제약 · 색인 · 권한 · 시퀀스) ══════════════════
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    pgo = ['-h', '127.0.0.1', '-p', '5432', '-U', 'tbox_testdb', '-w']
    tag = str(os.getpid())
    dbs = {'fresh': 'tbox_memories_test_u3_fresh_' + tag, 'mig': 'tbox_memories_test_u3_mig_' + tag}

    def pg(prog, args, text=None):
        return subprocess.run([prog] + pgo + args, env=env, input=text.encode() if text is not None else None, capture_output=True, check=True).stdout.decode()

    new_schema = (root / 'db/community/schema.sql').read_text()
    start = new_schema.index('\n-- ─────────────────────────────────────────────────────────────\n-- Q:F-12 U3-1 문서 전용 첨부')
    end = new_schema.index('\n-- ─────────────────────────────────────────────────────────────\n-- Q:F-12 업무 화면')
    oldp = out / 'u3-old-community-schema.sql'
    oldp.write_text(new_schema[:start] + new_schema[end:])
    try:
        for key, db in dbs.items():
            pg('createdb', ['-O', 'tbox_testdb', db])
            pg('psql', ['-X', '-q', '-d', db, '-v', 'ON_ERROR_STOP=1', '-f', str(root / 'db/schema.sql')])
            pg('psql', ['-X', '-q', '-d', db, '-v', 'ON_ERROR_STOP=1'], 'CREATE SCHEMA community;')
            pg('psql', ['-X', '-q', '-d', db, '-v', 'ON_ERROR_STOP=1', '-f', str(root / 'db/community/schema.sql' if key == 'fresh' else oldp)])
        check(pg('psql', ['-X', '-At', '-d', dbs['mig']], "SELECT to_regclass('community.page_attachments') IS NULL").strip() == 't', 'shape: base (pre-U3) schema has no attachment table')
        for _ in range(2):
            pg('psql', ['-X', '-q', '-d', dbs['mig'], '-v', 'ON_ERROR_STOP=1', '-f', str(root / 'db/migrations/2026-10-13_community_page_attachments.sql')])
        for key, db in dbs.items():
            gpath = out / ('u3-grants-' + key + '.sql')
            text = (root / 'db/grants.sql').read_text().replace('tbox_re', db)
            assert 'DATABASE tbox_re ' not in text and db.startswith('tbox_memories_test_u3_')
            gpath.write_text(text)
            pg('psql', ['-X', '-q', '-d', db, '-v', 'ON_ERROR_STOP=1', '-f', str(gpath)])
            pg('psql', ['-X', '-q', '-d', db, '-v', 'ON_ERROR_STOP=1', '-f', str(root / 'db/community/grants.sql')])
            gpath.unlink()
        shape_sql = """
            SELECT 'col|'||c.relname||'|'||a.attnum||'|'||a.attname||'|'||format_type(a.atttypid,a.atttypmod)||'|'||a.attnotnull||'|'||coalesce(pg_get_expr(d.adbin,d.adrelid),'')
              FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace s ON s.oid=c.relnamespace
              LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
             WHERE s.nspname='community' AND c.relkind IN ('r','p') AND a.attnum>0 AND NOT a.attisdropped
            UNION ALL
            SELECT 'con|'||c.relname||'|'||k.conname||'|'||pg_get_constraintdef(k.oid)
              FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid JOIN pg_namespace s ON s.oid=c.relnamespace WHERE s.nspname='community'
            UNION ALL
            SELECT 'idx|'||indexname||'|'||indexdef FROM pg_indexes WHERE schemaname='community'
            UNION ALL
            SELECT 'acl|'||table_name||'|'||grantee||'|'||privilege_type FROM information_schema.role_table_grants WHERE table_schema='community'
            UNION ALL
            SELECT 'colacl|'||table_name||'|'||column_name||'|'||grantee||'|'||privilege_type FROM information_schema.column_privileges
             WHERE table_schema='community' AND table_name LIKE 'page_attach%'
            UNION ALL
            SELECT 'seq|'||sequence_name FROM information_schema.sequences WHERE sequence_schema='community'
            ORDER BY 1;
        """
        shapes = {key: pg('psql', ['-X', '-At', '-d', db, '-v', 'ON_ERROR_STOP=1'], shape_sql).strip() for key, db in dbs.items()}
        for key in shapes:
            (out / ('u3-shape-' + key + '.txt')).write_text(shapes[key] + '\n')
        diff = sorted(set(shapes['fresh'].splitlines()) ^ set(shapes['mig'].splitlines()))
        (out / 'u3-shape-diff.txt').write_text('\n'.join(diff) + '\n')
        codes['shape_lines'] = len(shapes['fresh'].splitlines())
        check(shapes['fresh'] == shapes['mig'], 'shape: fresh install (schema.sql+grants) and pre-U3 schema + migration×2 (+grants) SAME (' + str(codes['shape_lines']) + ' lines)')
        check(sum(1 for l in shapes['fresh'].splitlines() if 'page_attach' in l) >= 25, 'shape: compared set includes the attachment objects')
    finally:
        for db in dbs.values():
            subprocess.run(['dropdb'] + pgo + ['--if-exists', db], env=env, capture_output=True)
        oldp.unlink(missing_ok=True)
        check(all(n("SELECT count(*) FROM pg_database WHERE datname='" + db + "'") == 0 for db in dbs.values()), 'shape: temporary comparison DBs dropped')
finally:
    for f in big_files:
        f.unlink(missing_ok=True)
    (out / 'qf12-u3-attach-results.json').write_text(json.dumps({'checks': checks, 'confirmed_values': codes}, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
    if any(not x['pass'] for x in checks):
        raise SystemExit(1)
