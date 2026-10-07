#!/usr/bin/env python3
"""사이트 Q:F-13 지시서 P2 — 보관 기간 정리 · 탈퇴 · 사진 위치 정보(G-2) · 감사 3년(G-8) + P2-a~e (실제 PHP·PostgreSQL + Chromium).

memories_harness.py 로만 부른다(`--suite qf13_retention_http.py`) — 빈 합성 DB · 저장소 임시 사본 · 로컬 php -S 127.0.0.1:8768 · 끝나면 지운다.
운영 DB·운영 8082·시드 계정 0. 계정은 fixture 의 claude_ 와 이 시험이 만드는 qf13r_ 합성 계정뿐 · UA tbox-review/<날짜> · 외부 요청 0.
소유자 함수는 합성 DB 의 소유자(tbox_testdb)로 부른다 — 시험이 작업 폴더에 0600 pgpass 를 만들어 scripts/privacy_purge.php 에
TBOX_OWNER_PGPASS · TBOX_OWNER_ROLE 로 넘긴다(운영 cron 은 /etc/tbox-v2/owner.pgpass · tbox_owner). 체인 파일은 TBOX_AUDIT_CHAIN_FILE 로 작업 폴더에.
결과 파일(qf13-retention-results.json)에는 단언 이름과 참/거짓만 — 세션·CSRF·비밀번호·본문 원문은 싣지 않는다. 명령 출력(건수만)은 *.txt 로.

시험(지시서 P2 표)
  파기     시각을 당긴 줄(366일 전 메시지·AI 대화·AI 도구·커뮤니티 메시지·GPT·커뮤니티 AI · 31일 전 지운 사진 기록·여행 계획 · 3년+1일 전 감사)과
           경계 안 줄(364일 · 29일 · 3년−1일) → dry 는 수만(바뀐 것 0 · 감사 0) → --run 뒤 지난 것만 0 · 사진·AI 그림 파일도 0 · 두 번째 0 ·
           정합성 검사 통과 · 출력·감사에 내용 0
  권한     앱 계정으로 파기 함수 넷 → 42501 · audit_logs DELETE → 42501 · 탈퇴 요청 표 DELETE·purged_at → 42501 · 처리 때 당기기 → 트리거 거부
  탈퇴     요청(재인증 없으면 403 next:reauth) → 재인증 → 201 · 다시 409 → 관리자 처리(재인증 관문 403 → 재인증 → 200 · 자기 자신 400) →
           즉시 로그인 불가 · 세션·패스키·토큰·OTP 0 → 30일 당긴 뒤 파기: 아이디 deleted-<번호> · 「탈퇴한 사용자 #번호」 ·
           개인 공간 문서·사진 기록(파일)·여행 계획 0 · 팀 공간 글·게시판 글·댓글·자료실 파일 남음(작성자 표시 바뀜) · 감사 셋
  G-2      GPS·XMP·주석이 든 JPEG·PNG·WebP 를 사진 기록에 올림 → 저장된 파일에 EXIF/GPS/XMP 0 · 그림 바이트(픽셀) 그대로 · 방향 유지 ·
           DB 지문 = 저장 파일 · 내려받기·화면 표시 정상 · 이미 저장된 옛 사진은 그대로
  감사 사슬 3년 지난 날 접기 → 검증 시작점 · 파기 뒤 audit_chain.php --verify 통과(시작점부터) · 남은 날을 고치면 여전히 실패
  마이그레이션 main 스키마 위 2회 = 새 설치 모양(표·제약·인덱스·트리거·권한) · 함수 넷 소유자만
  P2-a~e   배경 요청은 동의 관문 감사 0 · 옛 AI 판 동의는 다시 묻기 · 「내 계정」 동의 줄 · 처리방침 제4조 문장 · (P2-d 는 고정 파일 시험)
  화면     「내 계정」 탈퇴 패널 · 관리자 「탈퇴 요청」 표 — 확인 창 → 재인증 창 → 결과 · 360·412·820·1440 × 밝게·어둡게 + 고대비 가로 넘침 0 · 콘솔 0
"""
from pathlib import Path
exec(compile(Path(__file__).resolve().parent.joinpath('community/memories_http.py').read_text().split('try:\n for u in users:')[0], __file__, 'exec'))
import hmac
import io
import re
import secrets
import struct
import zlib
from urllib.parse import urlsplit
from PIL import Image, PngImagePlugin
from playwright.sync_api import sync_playwright, expect

assert str(root).startswith('/tmp/qaredi-stage13-') and manifest['database'].startswith('tbox_memories_test_')
UA = 'tbox-review/' + time.strftime('%Y-%m-%d')
TB = site + '/tbox'
WORK = root.parent
PW = 'Qf13-retention-synthetic-only-2026!'


def env_values(path):
    out_v = {}
    for line in path.read_text().splitlines():
        if line.strip() and not line.lstrip().startswith('#') and '=' in line:
            k, v = line.strip().split('=', 1)
            out_v[k] = v.strip().strip('"\'')
    return out_v


APP = env_values(root / '.env')
FIX = env_values(Path(os.environ['FILE_RENAME_FIXTURE_CONFIG']))
LEGAL = json.loads((root / 'config/legal.json').read_text())
VER = {'terms': LEGAL['terms'][-1]['version'], 'privacy': LEGAL['privacy'][-1]['version'],
       'age14': LEGAL['terms'][-1]['version'], 'ai_overseas': LEGAL['ai_overseas']['version']}


def q(text, db=None):
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    r = subprocess.run(['psql', '-h', '127.0.0.1', '-U', 'tbox_testdb', '-w', '-X', '-q', '-At', '-d', db or manifest['database'],
                        '-v', 'ON_ERROR_STOP=1'], env=env, input=text.encode(), capture_output=True, check=True)
    return r.stdout.decode().strip()


def save_text(name, text):
    (out / name).write_text(text)


# ── 소유자 pgpass(작업 폴더 · 0600 · 시험 끝에 작업 폴더째 지워진다) ──
def pg_escape(v):
    return v.replace('\\', '\\\\').replace(':', '\\:')


OWNER_PGPASS = WORK / 'owner-test.pgpass'
OWNER_PGPASS.write_text(':'.join([pg_escape(FIX['DB_HOST']), pg_escape(FIX.get('DB_PORT', '5432') or '5432'), pg_escape(FIX['DB_NAME']),
                                  'tbox_testdb', pg_escape(FIX['DB_PASS'])]) + '\n')
OWNER_PGPASS.chmod(0o600)
CHAIN = WORK / 'chain' / 'audit-chain.txt'
CHAIN.parent.mkdir(mode=0o700)
LOGS = WORK / 'logs'
STORE = WORK / 'storage/memories'


def purge(*args, name=None):
    env = dict(os.environ, TBOX_OWNER_PGPASS=str(OWNER_PGPASS), TBOX_OWNER_ROLE='tbox_testdb')
    r = subprocess.run(['php', 'scripts/privacy_purge.php', *args], cwd=root, env=env, capture_output=True, text=True, timeout=120)
    if name:
        save_text(name, '$ php scripts/privacy_purge.php ' + ' '.join(args) + '\nexit=' + str(r.returncode) + '\n' + r.stdout + r.stderr)
    return r


def chain(*args, name=None):
    env = dict(os.environ, TBOX_AUDIT_CHAIN_FILE=str(CHAIN))
    r = subprocess.run(['php', 'scripts/audit_chain.php', *args], cwd=root, env=env, capture_output=True, text=True, timeout=120)
    if name:
        save_text(name, '$ php scripts/audit_chain.php ' + ' '.join(args) + '\nexit=' + str(r.returncode) + '\n' + r.stdout + r.stderr)
    return r


APP_PROBE = r'''<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
if (!str_starts_with(Env::get('DB_NAME'), 'tbox_memories_test_')) { exit(3); }
require_once __DIR__ . '/src/Legal.php';
$db = Db::instance();
$in = json_decode((string) stream_get_contents(STDIN), true);
$out = ['user' => Env::get('DB_USER')];
foreach ($in['sql'] ?? [] as $k => $q) {
    try { $db->run($q); $out[$k] = 'ok'; } catch (PDOException $e) { $out[$k] = (string) $e->getCode(); }
}
foreach ($in['ai'] ?? [] as $uid) { $out['ai_' . $uid] = Legal::aiAgreed((int) $uid); }
echo json_encode($out);
'''
(root / 'qf13r_app_probe.php').write_text(APP_PROBE)


def app_probe(sqls=None, ai=None):
    r = subprocess.run(['php', 'qf13r_app_probe.php'], cwd=root, input=json.dumps({'sql': sqls or {}, 'ai': ai or []}),
                       capture_output=True, text=True, timeout=60)
    if r.returncode != 0:
        print('PROBE_FAILED exit=' + str(r.returncode), flush=True)
    return json.loads(r.stdout)


pw_hash = subprocess.run(['php', '-r', 'echo password_hash($argv[1],PASSWORD_ARGON2ID);', PW], capture_output=True, text=True, check=True).stdout


def make_user(key, name, role='Employee', mfa=False, consents=('terms', 'privacy', 'age14', 'ai_overseas'), team_of='employee'):
    team = q("SELECT coalesce(team_id::text,'NULL') FROM users WHERE id=" + str(users[team_of]['id']))
    uid = int(q("INSERT INTO users(username,display_name,password_hash,role,team_id,mfa_secret,mfa_enabled,password_changed_at,expires_at) VALUES('"
                + name + "','합성 " + name + "','" + pw_hash + "','" + role + "'," + team + ","
                + ("'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'" if mfa else 'NULL') + "," + ('true' if mfa else 'false')
                + ",now(),clock_timestamp()+interval '14 days') RETURNING id"))
    for doc in consents:
        q("INSERT INTO user_consents(user_id,doc,version,action) VALUES(" + str(uid) + ",'" + doc + "','" + VER[doc] + "','agree')")
    q("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT " + str(uid) + ",1,'dismissed',c FROM unnest(ARRAY['overview','threads']) c ON CONFLICT DO NOTHING")
    users[key] = {'id': uid, 'username': name}
    return uid


def new_session(key):
    token = secrets.token_hex(32)
    digest = hashlib.sha256(token.encode()).hexdigest()
    q("INSERT INTO sessions(id,user_id,expires_at,ip,user_agent) VALUES('" + digest + "',"
      + str(users[key]['id']) + ",clock_timestamp()+interval '8 hours','127.0.0.1','" + UA + "')")
    users[key]['session'] = token
    users[key]['csrf'] = hmac.new(APP['APP_SECRET'].encode(), ('csrf:' + digest).encode(), hashlib.sha256).hexdigest()


def tb(method, path, body=None, user=None, csrf=True, headers=None, html=False):
    h = {'User-Agent': UA}
    if user:
        h['Cookie'] = '__Host-tbox_session=' + users[user]['session']
        if csrf:
            h['X-CSRF-Token'] = users[user]['csrf']
    if html:
        h['Accept'] = 'text/html,application/xhtml+xml'
    h.update(headers or {})
    return requests.request(method, TB + path, json=body, headers=h, timeout=40, allow_redirects=False)


def jbody(r):
    try:
        return r.json()
    except ValueError:
        return {}


def count(sql_text):
    return int(q(sql_text))


def audits(action, reason=None, target=None, result=None):
    s = "SELECT count(*) FROM audit_logs WHERE action='" + action + "'"
    if result is not None:
        s += " AND result='" + result + "'"
    if reason is not None:
        s += " AND reason='" + reason + "'"
    if target is not None:
        s += ' AND target_id=' + str(target)
    return count(s)


samples = {}

try:
    for u in list(users):
        users[u]['username'] = sql('SELECT username FROM users WHERE id=' + str(users[u]['id']))
    E, O, ADM = users['employee']['id'], users['other']['id'], users['admin']['id']
    q("INSERT INTO community.usage_guides(user_id,version,status,context) SELECT id,1,'dismissed',c FROM users, unnest(ARRAY['overview','threads']) c ON CONFLICT DO NOTHING")
    for u in ('employee', 'other'):
        call('GET', '/api/me/space', user=u)

    # ═════════ 정적 — cron · logrotate · 처리방침 제4조(P2-e) ═════════
    cron = (root / 'deploy/cron.d/tbox-v2').read_text()
    jobs = [l.split() for l in cron.splitlines() if l.strip() and not l.lstrip().startswith('#') and not re.match(r'^[A-Z_]+=', l)]
    mine = [j for j in jobs if 'privacy_purge.php' in ' '.join(j)]
    check(len(mine) == 1 and mine[0][:5] == ['40', '4', '*', '*', '*'] and mine[0][5] == 'root'
          and mine[0][6:] == ['php', '/var/www/qaredi/tbox-v2/scripts/privacy_purge.php', '--run']
          and not [j for j in jobs if j[:2] == ['40', '4'] and 'privacy_purge.php' not in ' '.join(j)],
          'CRON exactly one 04:40 root line: php /var/www/qaredi/tbox-v2/scripts/privacy_purge.php --run (no other job at 04:40)')
    lr = (root / 'deploy/logrotate.d/tbox-v2').read_text()
    audit_block = lr.split('/var/log/tbox-v2/audit.log {', 1)[1].split('\n}', 1)[0]
    check(re.search(r'^\s*rotate 1096\s*$', audit_block, re.M) and re.search(r'^\s*maxage 1096\s*$', audit_block, re.M)
          and 'rotate 365' not in audit_block and re.search(r'^\s*rotate 90\s*$', lr.split('/var/log/tbox-v2/app.log {', 1)[1], re.M),
          'LOGROTATE audit.log keeps 1096 daily files and removes older than 1096 days (3 years); app.log unchanged (90)')
    lrtmp = WORK / 'logrotate-check'
    lrtmp.mkdir(mode=0o700)
    (lrtmp / 'audit.log').write_text('x\n')
    (lrtmp / 'app.log').write_text('x\n')
    (lrtmp / 'conf').write_text('\n'.join(l for l in lr.replace('/var/log/tbox-v2/', str(lrtmp) + '/').splitlines() if not l.strip().startswith('su ')) + '\n')
    lrr = subprocess.run(['/usr/sbin/logrotate', '-d', '-s', str(lrtmp / 'state'), str(lrtmp / 'conf')], capture_output=True, text=True)
    save_text('logrotate-debug.txt', lrr.stdout + lrr.stderr)
    check(lrr.returncode == 0 and '(1096 rotations)' in lrr.stdout + lrr.stderr and 'error' not in (lrr.stdout + lrr.stderr).lower(),
          'LOGROTATE copy of the shipped file parses (logrotate -d, temp paths): 1096 rotations, no error')
    r = tb('GET', '/privacy', html=True)
    check(r.status_code == 200 and '다른 법령이 보존을 요구하는 경우에는 그 법령이 정한 기간 동안 다른 정보와 분리해 보관합니다.' in r.text
          and '[법령에 따라' not in r.text and r.text.count('— 확인]') == 2,
          'P2-e privacy 제4조 sentence replaced; the two 제7조 [… — 확인] markers untouched')

    # ═════════ P2-a 배경 요청은 동의 관문 감사 없이 403 ═════════
    make_user('noconsent', 'qf13r_noconsent', consents=())
    new_session('noconsent')
    before = audits('auth.gate', 'consent_required', users['noconsent']['id'])
    r = tb('GET', '/api/files', user='noconsent', headers={'X-Qaredi-Background': '1'})
    r2 = tb('GET', '/api/files', user='noconsent', headers={'X-Qaredi-Background': '1'})
    check(r.status_code == 403 and jbody(r).get('code') == 'consent_required' and r2.status_code == 403
          and audits('auth.gate', 'consent_required', users['noconsent']['id']) == before,
          'P2-a background request (X-Qaredi-Background: 1) gets 403 consent_required with no audit row')
    r = tb('GET', '/api/files', user='noconsent')
    check(r.status_code == 403 and jbody(r).get('code') == 'consent_required'
          and audits('auth.gate', 'consent_required', users['noconsent']['id']) == before + 1,
          'P2-a person request still gets 403 consent_required and one audit row')

    # ═════════ P2-b AI 국외 전송 판 — 옛 판 동의는 다시 묻는다 ═════════
    make_user('oldai', 'qf13r_oldai', consents=('terms', 'privacy', 'age14'))
    q("INSERT INTO user_consents(user_id,doc,version,action) VALUES(" + str(users['oldai']['id']) + ",'ai_overseas','2020-01-01','agree')")
    new_session('oldai')
    st = jbody(tb('GET', '/api/me/consents', user='oldai'))
    probe = app_probe(ai=[users['oldai']['id'], E])
    check(VER['ai_overseas'] != '2020-01-01' and st.get('ai_overseas') is False and st.get('consents', {}).get('ai_overseas') is None
          and probe['ai_' + str(users['oldai']['id'])] is False and probe['ai_' + str(E)] is True,
          'P2-b agree on an old AI version is not consent (state null, Legal::aiAgreed false); current-version agree is')
    r = tb('POST', '/api/me/consents', {'doc': 'ai_overseas'}, user='oldai')
    st = jbody(r)
    rows = q("SELECT string_agg(version||':'||action,',' ORDER BY id) FROM user_consents WHERE user_id=" + str(users['oldai']['id']) + " AND doc='ai_overseas'")
    check(r.status_code == 200 and st.get('changed') is True and st.get('ai_overseas') is True
          and st['consents']['ai_overseas']['version'] == VER['ai_overseas'] and rows == '2020-01-01:agree,' + VER['ai_overseas'] + ':agree'
          and app_probe(ai=[users['oldai']['id']])['ai_' + str(users['oldai']['id'])] is True,
          'P2-b agreeing again adds a current-version row (old row kept) and AI consent becomes valid')
    check(json.loads((root / 'config/legal.json').read_text()).get('ai_overseas', {}).get('version') == VER['ai_overseas']
          and re.fullmatch(r'[0-9]{4}-[0-9]{2}-[0-9]{2}', VER['ai_overseas']),
          'P2-b config/legal.json carries its own ai_overseas version (date-shaped — fits the user_consents CHECK)')

    # ═════════ P2-c 「내 계정」 동의 줄 ═════════
    r = tb('GET', '/settings', user='employee', html=True)
    check(r.status_code == 200 and '약관 · 개인정보 처리방침 · AI 국외 전송 동의' in r.text and 'href="/tbox/consent"' in r.text
          and 'id="delete-request-panel"' in r.text, 'P2-c settings page has the consent row linking /tbox/consent and the withdrawal panel')

    # ═════════ 권한 — 앱 계정 ═════════
    pr = app_probe({
        'expired': 'SELECT * FROM public.privacy_purge_expired(true, 1)',
        'accounts': 'SELECT * FROM public.privacy_purge_accounts(true, 1)',
        'pages': 'SELECT community.privacy_purge_pages(ARRAY[1]::bigint[])',
        'posts': "SELECT * FROM community.privacy_purge_memory_posts(ARRAY['00000000-0000-4000-8000-000000000000']::uuid[])",
        'audit_delete': 'DELETE FROM audit_logs WHERE id = (SELECT min(id) FROM audit_logs)',
        'audit_update': "UPDATE audit_logs SET summary = 'x' WHERE id = (SELECT min(id) FROM audit_logs)",
        'adr_delete': 'DELETE FROM account_deletion_requests',
        'adr_purged': 'UPDATE account_deletion_requests SET purged_at = now()',
        'adr_insert_status': "INSERT INTO account_deletion_requests(user_id, status) VALUES (" + str(E) + ", 'processed')",
        'consent_delete': 'DELETE FROM user_consents',
    })
    save_text('app-role-probe.json', json.dumps(pr, ensure_ascii=False, indent=1))
    check(pr['user'] == APP['DB_USER'] and all(pr[k] == '42501' for k in ('expired', 'accounts', 'pages', 'posts')),
          'PERM app role cannot call any of the four purge functions (42501 permission denied)')
    check(all(pr[k] == '42501' for k in ('audit_delete', 'audit_update', 'consent_delete')),
          'PERM app role still cannot DELETE/UPDATE audit_logs or delete user_consents (42501)')
    check(all(pr[k] == '42501' for k in ('adr_delete', 'adr_purged', 'adr_insert_status')),
          'PERM app role cannot delete deletion requests, set purged_at, or insert a non-default status (42501)')
    fn = q("SELECT string_agg(p.proname||':'||pg_get_userbyid(p.proowner)||':'||has_function_privilege('" + APP['DB_USER']
           + "',p.oid,'EXECUTE')::text||':'||coalesce(array_to_string(p.proacl,'|'),'-'),',' ORDER BY p.proname) FROM pg_proc p "
           "WHERE p.proname IN ('privacy_purge_expired','privacy_purge_accounts','privacy_purge_pages','privacy_purge_memory_posts')")
    save_text('function-acl.txt', fn + '\n')
    check(fn.count(':tbox_testdb:false:') == 4 and 'PUBLIC' not in fn and '=X/' not in fn.replace('tbox_testdb=X/', ''),
          'PERM the four functions are owned by the table owner (here tbox_testdb) and only the owner holds EXECUTE')

    # ═════════ G-2 사진 위치 정보 ═════════
    MARK = b'QF13R-SyntheticCam'
    XMP = (b'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
           b'<rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="37,33.99N"/></rdf:RDF></x:xmpmeta>')

    def gps_exif(orientation=None):
        ex = Image.Exif()
        ex[0x010F] = MARK.decode()
        if orientation:
            ex[0x0112] = orientation
        gps = ex.get_ifd(0x8825)
        gps[1] = 'N'; gps[2] = (37.0, 33.0, 59.5); gps[3] = 'E'; gps[4] = (126.0, 58.0, 40.2)
        return ex.tobytes()

    def picture(w, h):
        return Image.merge('RGB', (Image.linear_gradient('L').resize((w, h)), Image.radial_gradient('L').resize((w, h)),
                                   Image.effect_noise((w, h), 40).convert('L')))

    def jpeg_segments(data):
        i, segs = 2, []
        while i + 4 <= len(data) and data[i] == 0xFF and data[i + 1] != 0xDA:
            ln = int.from_bytes(data[i + 2:i + 4], 'big')
            segs.append((data[i + 1], data[i + 4:i + 2 + ln]))
            i += 2 + ln
        return segs

    def tiff_tags(tiff):
        bo = 'little' if tiff[:2] == b'II' else 'big'
        ifd = int.from_bytes(tiff[4:8], bo)
        n = int.from_bytes(tiff[ifd:ifd + 2], bo)
        return [int.from_bytes(tiff[ifd + 2 + k * 12:ifd + 4 + k * 12], bo) for k in range(n)]

    buf = io.BytesIO()
    picture(320, 240).save(buf, 'JPEG', quality=90, exif=gps_exif(6), xmp=XMP)
    jpg = buf.getvalue()
    second = io.BytesIO()
    picture(64, 48).save(second, 'JPEG', quality=80, exif=gps_exif())
    jpg = jpg[:2] + b'\xff\xfe' + (2 + 17).to_bytes(2, 'big') + b'secret-loc-COMxyz' + jpg[2:] + second.getvalue()   # COM 조각 + EOI 뒤 둘째 그림(MPF 흉내)
    check(b'Exif\x00\x00' in jpg and MARK in jpg and b'ns.adobe.com' in jpg and b'secret-loc-COM' in jpg
          and dict(Image.open(io.BytesIO(jpg)).getexif().get_ifd(0x8825)).get(1) == 'N' and Image.open(io.BytesIO(jpg)).getexif().get(0x0112) == 6,
          'G-2 control: original JPEG carries EXIF GPS + camera mark + orientation 6, XMP, a comment and a trailing second image')
    mj = upload(data=jpg, name='qf13r-gps.jpg')
    sj = (STORE / mj['id']).read_bytes()
    segs = jpeg_segments(sj)
    exif_payloads = [p for m, p in segs if m == 0xE1 and p.startswith(b'Exif\x00\x00')]
    check(mj['mime'] == 'image/jpeg' and sj[:2] == b'\xff\xd8' and sj.endswith(b'\xff\xd9') and MARK not in sj and b'ns.adobe.com' not in sj
          and b'adobe:ns:meta' not in sj and b'secret-loc-COM' not in sj and sj.count(b'\xff\xd8') == 1
          and [m for m, _ in segs if m == 0xE1] == [0xE1] and len(exif_payloads) == 1 and tiff_tags(exif_payloads[0][6:]) == [0x0112]
          and not [m for m, _ in segs if m in (0xED, 0xFE)],
          'G-2 JPEG stored file: no GPS/camera EXIF, no XMP, no comment, no trailing image — only one Exif segment holding Orientation alone')
    sim = Image.open(io.BytesIO(sj))
    oim = Image.open(io.BytesIO(jpg))
    check(sim.getexif().get(0x0112) == 6 and not dict(sim.getexif().get_ifd(0x8825)) and sim.size == oim.size
          and list(sim.getdata()) == list(oim.getdata()),
          'G-2 JPEG: orientation 6 kept, GPS IFD empty, same size and identical decoded pixels (lossless — scan data untouched)')
    exif_php = subprocess.run(['php', '-r', '$e=@exif_read_data($argv[1]);echo json_encode(["gps"=>is_array($e)&&count(array_filter(array_keys($e),fn($k)=>str_starts_with($k,"GPS")))>0,"o"=>$e["Orientation"]??null,"make"=>$e["Make"]??null]);',
                               str(STORE / mj['id'])], capture_output=True, text=True)
    check(json.loads(exif_php.stdout) == {'gps': False, 'o': 6, 'make': None}, 'G-2 JPEG: PHP exif_read_data on the stored file sees no GPS keys and no camera make, orientation 6')
    check(sql("SELECT sha256||':'||bytes FROM community.memory_media WHERE id='" + mj['id'] + "'") == hashlib.sha256(sj).hexdigest() + ':' + str(len(sj))
          and mj['bytes'] == len(sj) and len(sj) < len(jpg), 'G-2 JPEG: DB sha256 and bytes (and upload response bytes) describe the stripped stored file')
    served = raw('GET', '/api/memories/media/' + mj['id'])
    check(served.status_code == 200 and served.headers.get('Content-Type') == 'image/jpeg' and served.content == sj,
          'G-2 JPEG: owner download serves exactly the stripped bytes as image/jpeg')

    pinfo = PngImagePlugin.PngInfo()
    pinfo.add_text('Comment', 'GPS 37.5665N 126.9780E secret')
    pinfo.add_itxt('XML:com.adobe.xmp', XMP.decode())
    bufp = io.BytesIO()
    picture(120, 80).save(bufp, 'PNG', pnginfo=pinfo, exif=gps_exif())
    pngm = bufp.getvalue()

    def png_types(data):
        i, types = 8, []
        while i + 8 <= len(data):
            ln = int.from_bytes(data[i:i + 4], 'big')
            types.append(data[i + 4:i + 8])
            i += 12 + ln
        return types

    def png_chunk_bytes(data, kind):
        i, got = 8, b''
        while i + 8 <= len(data):
            ln = int.from_bytes(data[i:i + 4], 'big')
            if data[i + 4:i + 8] == kind:
                got += data[i:i + 12 + ln]
            i += 12 + ln
        return got

    check({b'eXIf', b'tEXt', b'iTXt'} <= set(png_types(pngm)), 'G-2 control: original PNG has eXIf (GPS), tEXt and iTXt (XMP) chunks')
    mp = upload(data=pngm, name='qf13r-gps.png')
    sp = (STORE / mp['id']).read_bytes()
    check(not ({b'eXIf', b'tEXt', b'zTXt', b'iTXt', b'tIME'} & set(png_types(sp))) and png_chunk_bytes(sp, b'IDAT') == png_chunk_bytes(pngm, b'IDAT')
          and b'GPS 37.5665N' not in sp and list(Image.open(io.BytesIO(sp)).convert('RGB').getdata()) == list(Image.open(io.BytesIO(pngm)).convert('RGB').getdata())
          and sql("SELECT sha256 FROM community.memory_media WHERE id='" + mp['id'] + "'") == hashlib.sha256(sp).hexdigest(),
          'G-2 PNG stored file: no eXIf/tEXt/zTXt/iTXt/tIME, image chunks byte-identical, same pixels, DB sha256 = stored file')
    clean_png = png
    mclean = upload(data=clean_png, name='qf13r-clean.png')
    check((STORE / mclean['id']).read_bytes() == clean_png, 'G-2 a PNG without metadata is stored byte-for-byte unchanged')

    bufw = io.BytesIO()
    picture(96, 64).save(bufw, 'WEBP', quality=80)
    vp8 = bufw.getvalue()[12:]
    exif_b = gps_exif()
    vp8x = b'VP8X' + struct.pack('<I', 10) + bytes([0x0C, 0, 0, 0]) + (95).to_bytes(3, 'little') + (63).to_bytes(3, 'little')

    def riff_chunk(cc, data):
        return cc + struct.pack('<I', len(data)) + data + (b'\x00' if len(data) & 1 else b'')
    body_w = vp8x + vp8 + riff_chunk(b'EXIF', exif_b) + riff_chunk(b'XMP ', XMP)
    webpm = b'RIFF' + struct.pack('<I', 4 + len(body_w)) + b'WEBP' + body_w

    def riff_types(data):
        i, types = 12, []
        while i + 8 <= len(data):
            ln = struct.unpack('<I', data[i + 4:i + 8])[0]
            types.append(data[i:i + 4])
            i += 8 + ln + (ln & 1)
        return types
    check(Image.open(io.BytesIO(webpm)).size == (96, 64) and {b'EXIF', b'XMP '} <= set(riff_types(webpm)),
          'G-2 control: original WebP (extended) carries EXIF (GPS) and XMP chunks and decodes')
    mw = upload(data=webpm, name='qf13r-gps.webp')
    sw = (STORE / mw['id']).read_bytes()
    check(mw['mime'] == 'image/webp' and not ({b'EXIF', b'XMP '} & set(riff_types(sw))) and sw[20] & 0x0C == 0 and MARK not in sw
          and struct.unpack('<I', sw[4:8])[0] == len(sw) - 8 and Image.open(io.BytesIO(sw)).size == (96, 64)
          and list(Image.open(io.BytesIO(sw)).convert('RGB').getdata()) == list(Image.open(io.BytesIO(webpm)).convert('RGB').getdata()),
          'G-2 WebP stored file: EXIF/XMP chunks removed, VP8X flags cleared, RIFF size fixed, same pixels')

    # 이미 저장된 옛 사진(이번 변경 전 올린 것을 흉내 — 행·파일을 직접 둔다)은 어느 단계도 고치지 않는다
    old_id = str(uuid.uuid4())
    (STORE / old_id).write_bytes(jpg)
    (STORE / old_id).chmod(0o600)
    q("INSERT INTO community.memory_media(id,owner_user_id,mime,filename,sha256,bytes) VALUES('" + old_id + "'," + str(E)
      + ",'image/jpeg','old.jpg','" + hashlib.sha256(jpg).hexdigest() + "'," + str(len(jpg)) + ")")
    old_post = save(dict(payload(), media_ids=[old_id]))
    photo_post = save(dict(payload(), media_ids=[mj['id'], mp['id'], mw['id']], visibility='community'))

    # ═════════ 파기 — 시각을 당긴 줄 ═════════
    def ins(sql_text):
        return q(sql_text)
    lo, hi = min(E, O), max(E, O)
    m_old = ins("INSERT INTO member_messages(sender_id,recipient_id,body,sent_at) VALUES(" + str(E) + "," + str(O) + ",'qf13r-OLD-BODY 366',now()-interval '366 days') RETURNING id")
    m_in = ins("INSERT INTO member_messages(sender_id,recipient_id,body,sent_at) VALUES(" + str(E) + "," + str(O) + ",'qf13r-IN-BODY 364',now()-interval '364 days') RETURNING id")
    img_name = str(uuid.uuid4()) + '.png'
    img_path = subprocess.run(['php', '-r', 'require "src/bootstrap.php"; echo Storage::pathFor($argv[1]);', img_name], cwd=root, capture_output=True, text=True, check=True).stdout
    Path(img_path).parent.mkdir(parents=True, exist_ok=True)
    Path(img_path).write_bytes(png)

    def assist(created, action='polish', source='NULL', stored=None):
        aid = str(uuid.uuid4())
        if stored:
            ins("INSERT INTO member_ai_assists(id,user_id,peer_id,request_id,action,input,language,aspect,state,stored_name,image_mime,image_bytes,image_sha256,model,created_at,finished_at) VALUES('"
                + aid + "'," + str(E) + "," + str(O) + ",'" + str(uuid.uuid4()) + "','image','qf13r-ASSIST-INPUT','ko','1:1','complete','" + stored + "','image/png',"
                + str(len(png)) + ",'" + hashlib.sha256(png).hexdigest() + "','gemini-3.1-flash-lite-image',now()-interval '" + created + "',now()-interval '" + created + "')")
        else:
            ins("INSERT INTO member_ai_assists(id,user_id,peer_id,request_id,action,source_message_id,input,language,aspect,state,result,model,created_at) VALUES('"
                + aid + "'," + str(E) + "," + str(O) + ",'" + str(uuid.uuid4()) + "','" + action + "'," + source + ",'qf13r-ASSIST-INPUT','ko','1:1','complete','qf13r-ASSIST-RESULT','gemini-3.5-flash',now()-interval '" + created + "')")
        return aid
    a_old = assist('366 days')
    a_img = assist('366 days', stored=img_name)
    a_in = assist('200 days', source=m_old)
    a_att = assist('366 days')
    m_att = ins("INSERT INTO member_messages(sender_id,recipient_id,body,sent_at,ai_attachment_id) VALUES(" + str(E) + "," + str(O) + ",'qf13r attached',now()-interval '100 days','" + a_att + "') RETURNING id")
    th_old = ins("INSERT INTO member_ai_threads(user_id,title,created_at,updated_at) VALUES(" + str(E) + ",'qf13r old thread',now()-interval '400 days',now()-interval '366 days') RETURNING id")
    th_mix = ins("INSERT INTO member_ai_threads(user_id,title,created_at,updated_at) VALUES(" + str(E) + ",'qf13r mixed thread',now()-interval '400 days',now()-interval '10 days') RETURNING id")

    def turn(thread, created):
        return ins("INSERT INTO member_ai_turns(user_id,thread_id,request_id,prompt,answer,state,created_at,finished_at) VALUES(" + str(E) + "," + thread + ",'"
                   + str(uuid.uuid4()) + "','qf13r-PROMPT','qf13r-ANSWER','complete',now()-interval '" + created + "',now()-interval '" + created + "') RETURNING id")
    t_old, t_mix_old, t_mix_new = turn(th_old, '366 days'), turn(th_mix, '366 days'), turn(th_mix, '10 days')
    conv = str(uuid.uuid4())
    ins("INSERT INTO community.conversations(id,user_low,user_high) VALUES('" + conv + "'," + str(lo) + "," + str(hi) + ")")
    cm_old = ins("INSERT INTO community.messages(conversation_id,sender_id,request_id,body,created_at) VALUES('" + conv + "'," + str(E) + ",'" + str(uuid.uuid4()) + "','qf13r-CM-OLD',now()-interval '366 days') RETURNING id")
    cm_in = ins("INSERT INTO community.messages(conversation_id,sender_id,request_id,body,created_at) VALUES('" + conv + "'," + str(E) + ",'" + str(uuid.uuid4()) + "','qf13r-CM-IN',now()-interval '364 days') RETURNING id")
    g_old = ins("INSERT INTO community.gpt_turns(user_id,request_id,prompt,reply,created_at) VALUES(" + str(E) + ",'" + str(uuid.uuid4()) + "','qf13r-GPT-P','qf13r-GPT-R',now()-interval '366 days') RETURNING id")
    g_in = ins("INSERT INTO community.gpt_turns(user_id,request_id,prompt,reply,created_at) VALUES(" + str(E) + ",'" + str(uuid.uuid4()) + "','qf13r-GPT-P','qf13r-GPT-R',now()-interval '364 days') RETURNING id")
    r_old = ins("INSERT INTO community.ai_requests(user_id,feature,started_at) VALUES(" + str(E) + ",'summary',now()-interval '366 days') RETURNING id")
    r_in = ins("INSERT INTO community.ai_requests(user_id,feature,started_at) VALUES(" + str(E) + ",'summary',now()-interval '364 days') RETURNING id")
    l_old = ins("INSERT INTO community.ai_log(request_id,user_id,feature,input_text,output_text,model,started_at,status,chars_in,chars_out) VALUES(" + r_old + "," + str(E) + ",'summary','qf13r-AI-IN','qf13r-AI-OUT','m',now()-interval '366 days','ok',1,1) RETURNING id")
    l_in = ins("INSERT INTO community.ai_log(request_id,user_id,feature,input_text,output_text,model,started_at,status,chars_in,chars_out) VALUES(" + r_in + "," + str(E) + ",'summary','qf13r-AI-IN','qf13r-AI-OUT','m',now()-interval '364 days','ok',1,1) RETURNING id")

    # 사진 기록: 31일 전 지움(사진·좋아요·댓글 · 여행 연결) / 29일 전 지움 / 지우지 않음
    def memory(days_deleted):
        media = upload(name='qf13r-' + str(days_deleted) + '.png')['id']
        pid = save(dict(payload(), media_ids=[media], visibility='community'))
        call('POST', '/api/memories/' + pid + '/like', {}, user='other')
        call('POST', '/api/memories/' + pid + '/comments', {'body': 'qf13r-COMMENT'}, user='other')
        if days_deleted is not None:
            call('DELETE', '/api/memories/' + pid)
            q("UPDATE community.memory_posts SET deleted_at=now()-interval '" + str(days_deleted) + " days' WHERE id='" + pid + "'")
        return pid, media
    p_old, p_old_media = memory(31)
    p_in, p_in_media = memory(29)
    p_live, p_live_media = memory(None)
    q("UPDATE community.memory_posts SET created_at=now()-interval '400 days' WHERE id='" + p_live + "'")

    def plan(days_deleted):
        pid = str(uuid.uuid4())
        q("INSERT INTO community.travel_plans(id,owner_user_id,title,request_id,deleted_at) VALUES('" + pid + "'," + str(E) + ",'qf13r plan','" + str(uuid.uuid4()) + "',"
          + ("now()-interval '" + str(days_deleted) + " days'" if days_deleted is not None else 'NULL') + ")")
        return pid
    tp_old, tp_in = plan(31), plan(29)
    q("INSERT INTO community.travel_plan_records(plan_id,post_id) VALUES('" + tp_in + "','" + p_old + "'),('" + tp_in + "','" + p_in + "')")

    def audit_row(offset):
        return ins("INSERT INTO audit_logs(happened_at,request_id,action,result,summary,detail) VALUES(now()-interval '3 years' " + offset
                   + ",'" + str(uuid.uuid4()) + "','qf13r.synthetic','allow','합성 감사 줄','{\"qf13r\":true}') RETURNING id")
    au_old = audit_row("- interval '1 day'")
    au_in = audit_row("+ interval '1 day'")

    snapshot_sql = ("SELECT concat_ws(',',(SELECT count(*) FROM member_messages),(SELECT count(*) FROM member_ai_assists),(SELECT count(*) FROM member_ai_turns),"
                    "(SELECT count(*) FROM member_ai_threads),(SELECT count(*) FROM community.messages),(SELECT count(*) FROM community.gpt_turns),"
                    "(SELECT count(*) FROM community.ai_log),(SELECT count(*) FROM community.ai_requests),(SELECT count(*) FROM community.memory_posts),"
                    "(SELECT count(*) FROM community.memory_media),(SELECT count(*) FROM community.travel_plans),(SELECT count(*) FROM audit_logs WHERE action='qf13r.synthetic'))")
    snap = q(snapshot_sql)
    files_before = sorted(p.name for p in STORE.iterdir())
    dry = purge(name='purge-dry.txt')
    exp_line = ('[dry] 보관 기간 정리: member_messages=1 member_ai_assists=2 member_ai_turns=2 member_ai_threads=1 community_messages=1 '
                'community_gpt_turns=1 community_ai_log=1 community_ai_requests=1 memory_posts=1 travel_plans=1 audit_logs=1 파일=2')
    check(dry.returncode == 0 and dry.stdout.splitlines()[0] == exp_line and '[dry] 탈퇴 계정: 대상 없음' in dry.stdout,
          'PURGE dry run reports per-kind counts only (exactly the expired rows: 1 message, 2 AI tools, 2 turns, 1 thread, … 1 audit row, 2 files)')
    check(q(snapshot_sql) == snap and sorted(p.name for p in STORE.iterdir()) == files_before and Path(img_path).is_file()
          and audits('privacy.purge') == 0, 'PURGE dry run changes nothing (row counts, files) and writes no audit row')
    run = purge('--run', name='purge-run.txt')
    check(run.returncode == 0 and run.stdout.splitlines()[0] == exp_line.replace('[dry] ', ''), 'PURGE --run exits 0 with the same per-kind counts')
    gone = {
        'member_messages': ('member_messages', m_old), 'member_ai_turns': ('member_ai_turns', t_old), 'member_ai_turns2': ('member_ai_turns', t_mix_old),
        'member_ai_threads': ('member_ai_threads', th_old), 'community.messages': ('community.messages', cm_old), 'community.gpt_turns': ('community.gpt_turns', g_old),
        'community.ai_log': ('community.ai_log', l_old), 'community.ai_requests': ('community.ai_requests', r_old), 'audit_logs': ('audit_logs', au_old),
    }
    check(all(count('SELECT count(*) FROM ' + t + ' WHERE id=' + i) == 0 for t, i in gone.values())
          and count("SELECT count(*) FROM member_ai_assists WHERE id IN ('" + a_old + "','" + a_img + "')") == 0,
          'PURGE expired rows are gone: 366-day message/turns/thread/assists/community message/GPT/AI log+request, 3y+1d audit row')
    kept = [('member_messages', m_in), ('member_messages', m_att), ('member_ai_turns', t_mix_new), ('member_ai_threads', th_mix), ('community.messages', cm_in),
            ('community.gpt_turns', g_in), ('community.ai_log', l_in), ('community.ai_requests', r_in), ('audit_logs', au_in)]
    check(all(count('SELECT count(*) FROM ' + t + ' WHERE id=' + i) == 1 for t, i in kept)
          and count("SELECT count(*) FROM member_ai_assists WHERE id='" + a_att + "'") == 1
          and q("SELECT coalesce(source_message_id::text,'null') FROM member_ai_assists WHERE id='" + a_in + "'") == 'null',
          'PURGE rows inside the boundary stay (364 days, 3y-1d, thread with a newer turn); an attachment of a kept message stays; a kept assist loses only its link to the purged message')
    check(not Path(img_path).exists() and count("SELECT count(*) FROM community.memory_posts WHERE id='" + p_old + "'") == 0
          and count("SELECT count(*) FROM community.memory_media WHERE id='" + p_old_media + "'") == 0 and not (STORE / p_old_media).exists()
          and count("SELECT count(*) FROM community.memory_likes WHERE post_id='" + p_old + "'") == 0
          and count("SELECT count(*) FROM community.memory_comments WHERE post_id='" + p_old + "'") == 0
          and count("SELECT count(*) FROM community.travel_plan_records WHERE post_id='" + p_old + "'") == 0,
          'PURGE post deleted 31 days ago: post, photo row, photo file, likes, comments and travel links gone; AI image file gone')
    check(count("SELECT count(*) FROM community.memory_posts WHERE id IN ('" + p_in + "','" + p_live + "')") == 2 and (STORE / p_in_media).is_file()
          and (STORE / p_live_media).is_file() and count("SELECT count(*) FROM community.travel_plan_records WHERE post_id='" + p_in + "'") == 1
          and count("SELECT count(*) FROM community.travel_plans WHERE id='" + tp_old + "'") == 0
          and count("SELECT count(*) FROM community.travel_plans WHERE id='" + tp_in + "'") == 1,
          'PURGE post deleted 29 days ago and a 400-day-old live post stay with files; plan deleted 31 days ago gone, 29 days ago stays')
    pa = q("SELECT detail::text FROM audit_logs WHERE action='privacy.purge' AND result='allow' ORDER BY id DESC LIMIT 1")
    texts = run.stdout + run.stderr + pa + (LOGS / 'audit.log').read_text()
    check(audits('privacy.purge') == 1 and '"audit_logs": 1' in pa and '"files": 2' in pa
          and not any(s in texts for s in ('qf13r-OLD-BODY', 'qf13r-PROMPT', 'qf13r-ASSIST', 'qf13r-CM-OLD', 'qf13r-GPT', 'qf13r-AI-IN', 'qf13r-COMMENT', img_name, p_old_media)),
          'PURGE one privacy.purge audit row with counts; no message/prompt/AI text or file name in stdout, the audit row or the audit file')
    again = purge('--run', name='purge-run-again.txt')
    check(again.returncode == 0 and re.fullmatch(r'보관 기간 정리:( [a-z_]+=0)+ 파일=0', again.stdout.splitlines()[0]),
          'PURGE second --run finds nothing (all counts 0)')
    integ = subprocess.run(['php', 'scripts/consistency_check.php', '--strict', '--quiet'], cwd=root, capture_output=True, text=True)
    save_text('consistency-after-purge.txt', 'exit=' + str(integ.returncode) + '\n' + integ.stdout + integ.stderr)
    check(integ.returncode == 0, 'PURGE storage/DB integrity check (--strict) passes after purge (no orphan or missing files)')
    check((STORE / old_id).read_bytes() == jpg and (STORE / mj['id']).read_bytes() == sj,
          'G-2 a previously stored photo is not modified by upload or purge (old file bytes unchanged, EXIF still there)')

    # ═════════ 탈퇴 ═════════
    T = make_user('member', 'qf13r_member', mfa=True)
    make_user('boss', 'qf13r_admin', role='Admin', mfa=True)
    new_session('member')
    new_session('boss')
    q("INSERT INTO passkeys(user_id,credential_id,public_key,alg,user_handle,backup_eligible,backed_up,name,device_hint) VALUES("
      + str(T) + ",decode(repeat('ab',16),'hex'),decode(repeat('cd',64),'hex'),-7,decode(repeat('ef',32),'hex'),false,false,'qf13r 휴대폰','phone')")
    tok = secrets.token_hex(24)
    q("INSERT INTO api_tokens(user_id,name,token_hash,hint,created_by,scope,expires_at) VALUES(" + str(T) + ",'qf13r token','"
      + hashlib.sha256(tok.encode()).hexdigest() + "','" + tok[:6] + "'," + str(T) + ",'read',now()+interval '30 days')")
    call('GET', '/api/me/space', user='member')
    pg_private = call('POST', '/api/pages', {'title': 'qf13r 개인 문서'}, user='member', expected=201)['page']['id']
    pg_private2 = call('POST', '/api/pages', {'title': 'qf13r 개인 문서 둘'}, user='member', expected=201)['page']['id']
    team_id = q('SELECT team_id FROM users WHERE id=' + str(T))
    team_space = q("SELECT id FROM community.spaces WHERE kind='team' AND team_id=" + team_id) or q(
        "INSERT INTO community.spaces(kind,team_id,name,access_level,created_by) VALUES('team'," + team_id + ",'qf13r 팀','team'," + str(T) + ") RETURNING id")
    board = q("INSERT INTO community.spaces(kind,name,access_level,board_kind,created_by) VALUES('board','qf13r 게시판','internal','discussion'," + str(ADM) + ") RETURNING id")
    doc = '{"schema":1,"blocks":[{"type":"paragraph","text":"qf13r 팀 글 본문"}]}'
    team_page = q("INSERT INTO community.pages(space_id,title,doc,plain_text,author_id,last_edited_by,visibility) VALUES(" + team_space
                  + ",'qf13r 팀 글','" + doc + "','qf13r 팀 글 본문'," + str(T) + "," + str(T) + ",'team') RETURNING public_id")
    board_page = q("INSERT INTO community.pages(space_id,title,doc,plain_text,author_id,last_edited_by) VALUES(" + board
                   + ",'qf13r 게시판 글','" + doc + "','qf13r 게시판 글 본문'," + str(T) + "," + str(T) + ") RETURNING public_id")
    q("INSERT INTO community.comments(page_id,author_id,body) SELECT id," + str(T) + ",'qf13r 남는 댓글' FROM community.pages WHERE public_id='" + board_page + "'")
    file_bytes = b'qf13r synthetic library file\n'
    file_stored = subprocess.run(['php', '-r', 'require "src/bootstrap.php"; echo Storage::newStoredName("txt");'], cwd=root, capture_output=True, text=True, check=True).stdout
    file_path = Path(subprocess.run(['php', '-r', 'require "src/bootstrap.php"; echo Storage::pathFor($argv[1]);', file_stored], cwd=root, capture_output=True, text=True, check=True).stdout)
    file_path.parent.mkdir(parents=True, exist_ok=True)
    file_path.write_bytes(file_bytes)
    file_row = q("INSERT INTO files(public_id,original_name,stored_name,ext,mime,size_bytes,sha256,access_level,uploaded_by) VALUES(gen_random_uuid(),'qf13r-자료.txt','"
                 + file_stored + "','txt','text/plain'," + str(len(file_bytes)) + ",'" + hashlib.sha256(file_bytes).hexdigest() + "','internal'," + str(T) + ") RETURNING id")
    t_media = upload(user='member', name='qf13r-member.png')['id']
    t_post = save(dict(payload(), media_ids=[t_media]), user='member')
    t_loose = upload(user='member', name='qf13r-loose.png')['id']
    t_plan = str(uuid.uuid4())
    q("INSERT INTO community.travel_plans(id,owner_user_id,title,request_id) VALUES('" + t_plan + "'," + str(T) + ",'qf13r 계획','" + str(uuid.uuid4()) + "')")
    q("INSERT INTO community.travel_plan_records(plan_id,post_id) VALUES('" + t_plan + "','" + t_post + "')")

    r = tb('POST', '/api/me/delete-request', {'confirm': True}, user='member')
    samples['request_reauth_403'] = jbody(r)
    check(r.status_code == 403 and jbody(r).get('next') == 'reauth' and count("SELECT count(*) FROM account_deletion_requests WHERE user_id=" + str(T)) == 0
          and audits('auth.gate', 'reauth_required', T) == 1, 'DEL request without a fresh password check → 403 next:reauth, no row, auth.gate audit')
    check(tb('POST', '/api/me/delete-request', {'confirm': False}, user='member').status_code == 400
          and tb('POST', '/api/me/delete-request', {'confirm': True, 'user_id': O}, user='member').status_code == 400
          and tb('POST', '/api/me/delete-request', {'confirm': True}, user='member', csrf=False).status_code == 403,
          'DEL request body must be exactly {"confirm":true} (400 otherwise) and needs CSRF (403)')
    r = tb('POST', '/api/me/reauth', {'password': PW}, user='member')
    check(r.status_code == 200, 'DEL member re-enters the password (POST /api/me/reauth 200)')
    r = tb('POST', '/api/me/delete-request', {'confirm': True}, user='member')
    samples['request_201'] = jbody(r)
    req_id = jbody(r).get('request', {}).get('id')
    check(r.status_code == 201 and jbody(r)['request']['status'] == 'pending' and audits('account.delete_request', target=T, result='allow') == 1,
          'DEL request after re-auth → 201 pending + account.delete_request audit')
    r = tb('POST', '/api/me/delete-request', {'confirm': True}, user='member')
    samples['request_409'] = jbody(r)
    check(r.status_code == 409 and audits('account.delete_request', 'already_requested', T) == 1, 'DEL second request → 409 already_requested (one open request)')
    check(jbody(tb('GET', '/api/me/delete-request', user='member')).get('request', {}).get('id') == req_id
          and jbody(tb('GET', '/api/me/delete-request', user='other')).get('request') is None,
          'DEL GET shows only the caller own open request')
    check(tb('GET', '/api/admin/deletion-requests', user='member').status_code == 403
          and tb('POST', '/api/admin/deletion-requests/' + str(req_id), {'confirm': True}, user='member').status_code == 403,
          'DEL a non-admin cannot list or process deletion requests (403)')
    r = tb('POST', '/api/admin/deletion-requests/' + str(req_id), {'confirm': True}, user='boss')
    samples['process_reauth_403'] = jbody(r)
    check(r.status_code == 403 and jbody(r).get('next') == 'reauth' and q("SELECT status FROM account_deletion_requests WHERE id=" + str(req_id)) == 'pending',
          'DEL admin processing without a fresh password check → 403 next:reauth (gate [3-2]), still pending')
    check(tb('POST', '/api/me/reauth', {'password': PW}, user='boss').status_code == 200, 'DEL admin re-enters the password')
    exp_pages = count("SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id=p.space_id WHERE s.kind='personal' AND s.owner_user_id="
                      + str(T) + " AND p.deleted_at IS NULL AND p.purged_at IS NULL AND p.visibility<>'published'")
    lst = jbody(tb('GET', '/api/admin/deletion-requests', user='boss'))
    row = [x for x in lst.get('requests', []) if x['id'] == req_id]
    samples['admin_list_row'] = row[0] if row else None
    check(exp_pages >= 2 and row and row[0]['status'] == 'pending' and row[0]['remaining'] == {'personal_pages': exp_pages, 'memory_posts': 1, 'travel_plans': 1},
          'DEL admin list shows the pending request with remaining personal pages (the two made here + defaults) · photo posts 1 · travel plans 1 (handover check)')
    # 자기 자신 — 관리자가 자기 요청을 처리하려 하면 거부
    r = tb('POST', '/api/me/delete-request', {'confirm': True}, user='boss')
    own = jbody(r).get('request', {}).get('id')
    r = tb('POST', '/api/admin/deletion-requests/' + str(own), {'confirm': True}, user='boss')
    samples['process_self_400'] = jbody(r)
    check(r.status_code == 400 and audits('account.delete_process', 'self_change') == 1 and q("SELECT is_active FROM users WHERE id=" + str(users['boss']['id'])) == 't',
          'DEL admin cannot process own request (400 self_change, still active)')
    check(tb('POST', '/api/admin/deletion-requests/999999', {'confirm': True}, user='boss').status_code == 404, 'DEL unknown request number → 404')
    sess_before = count('SELECT count(*) FROM sessions WHERE user_id=' + str(T))
    r = tb('POST', '/api/admin/deletion-requests/' + str(req_id), {'confirm': True}, user='boss')
    samples['process_200'] = jbody(r)
    check(r.status_code == 200 and jbody(r)['request']['status'] == 'processed' and sess_before >= 1,
          'DEL admin processes the request → 200 processed')
    u = q("SELECT concat_ws('|',is_active,coalesce(mfa_secret,'null'),mfa_enabled,password_hash<>'" + pw_hash + "') FROM users WHERE id=" + str(T))
    check(u == 'f|null|f|t' and count('SELECT count(*) FROM sessions WHERE user_id=' + str(T)) == 0
          and count('SELECT count(*) FROM passkeys WHERE user_id=' + str(T) + ' AND revoked_at IS NULL') == 0
          and count('SELECT count(*) FROM api_tokens WHERE user_id=' + str(T) + ' AND revoked_at IS NULL') == 0,
          'DEL immediately: inactive, OTP secret gone, password replaced, sessions 0, live passkeys 0, live tokens 0')
    r_login = requests.post(TB + '/api/login', json={'username': 'qf13r_member', 'password': PW}, headers={'User-Agent': UA}, timeout=30)
    r_old = tb('GET', '/api/me', user='member')
    r_tok = requests.get(TB + '/api/whoami', headers={'User-Agent': UA, 'X-API-Token': tok}, timeout=30)
    check(r_login.status_code in (401, 403) and r_old.status_code == 401 and r_tok.status_code in (401, 403),
          'DEL immediately: password login refused, old session 401, program token refused')
    check(audits('account.delete_process', target=T, result='allow') == 1 and audits('auth.passkey_revoke', 'deactivate', T) == 1
          and q("SELECT detail->>'program_keys_revoked' FROM audit_logs WHERE action='account.delete_process' AND result='allow' AND target_id=" + str(T)) == '1',
          'DEL account.delete_process audit (counts) + one auth.passkey_revoke per key')
    r = tb('POST', '/api/admin/deletion-requests/' + str(req_id), {'confirm': True}, user='boss')
    check(r.status_code == 409 and audits('account.delete_process', 'not_pending', T) == 1, 'DEL processing again → 409 not_pending')
    pr2 = app_probe({'backdate': "UPDATE account_deletion_requests SET processed_at = now() - interval '40 days' WHERE id = " + str(req_id),
                     'reopen': "UPDATE account_deletion_requests SET status = 'pending', processed_by = NULL, processed_at = NULL WHERE id = " + str(req_id)})
    check(pr2['backdate'] == 'P0001' and pr2['reopen'] == 'P0001', 'DEL app role cannot move the 30-day clock or reopen a processed request (trigger P0001)')

    dry = purge(name='purge-account-dry-day0.txt')
    check(dry.returncode == 0 and '[dry] 탈퇴 계정: 대상 없음' in dry.stdout and q('SELECT username FROM users WHERE id=' + str(T)) == 'qf13r_member',
          'DEL before 30 days the purge does not touch the account')
    q("ALTER TABLE account_deletion_requests DISABLE TRIGGER account_deletion_requests_guard;"
      "UPDATE account_deletion_requests SET processed_at = processed_at - interval '31 days' WHERE id = " + str(req_id) + ";"
      "ALTER TABLE account_deletion_requests ENABLE TRIGGER account_deletion_requests_guard;")
    dry = purge(name='purge-account-dry.txt')
    check(dry.returncode == 0 and ('[dry] 탈퇴 계정 #' + str(T) + ': memory_posts=1 travel_plans=1 personal_pages=' + str(exp_pages) + ' device_keys=1 program_keys=1 anonymized=1 파일=2') in dry.stdout
          and q('SELECT username FROM users WHERE id=' + str(T)) == 'qf13r_member', 'DEL dry run after 30 days lists counts only and changes nothing')
    run = purge('--run', name='purge-account-run.txt')
    check(run.returncode == 0 and ('탈퇴 계정 #' + str(T) + ': memory_posts=1 travel_plans=1 personal_pages=' + str(exp_pages) + ' device_keys=1 program_keys=1 anonymized=1 파일=2') in run.stdout,
          'DEL purge --run anonymizes the account')
    uname, dname = q('SELECT username||chr(9)||display_name FROM users WHERE id=' + str(T)).split('\t')
    check(uname == 'deleted-' + str(T) and dname == '탈퇴한 사용자 #' + str(T) and q("SELECT status FROM account_deletion_requests WHERE id=" + str(req_id)) == 'purged'
          and q("SELECT name FROM community.spaces WHERE kind='personal' AND owner_user_id=" + str(T)) == '탈퇴한 사용자 #' + str(T) + ' 의 공간',
          'DEL after 30 days: username deleted-<id>, display name 「탈퇴한 사용자 #<id>」, personal space renamed, request purged')
    check(count("SELECT count(*) FROM community.pages p JOIN community.spaces s ON s.id=p.space_id WHERE s.kind='personal' AND s.owner_user_id=" + str(T)
                + " AND (p.purged_at IS NULL OR p.title<>'영구 삭제된 페이지' OR p.plain_text<>'')") == 0
          and count("SELECT count(*) FROM community.page_versions v JOIN community.pages p ON p.id=v.page_id WHERE p.public_id IN ('" + pg_private + "','" + pg_private2 + "')") == 0
          and count('SELECT count(*) FROM community.memory_posts WHERE owner_user_id=' + str(T)) == 0
          and count('SELECT count(*) FROM community.memory_media WHERE owner_user_id=' + str(T)) == 0
          and not (STORE / t_media).exists() and not (STORE / t_loose).exists()
          and count('SELECT count(*) FROM community.travel_plans WHERE owner_user_id=' + str(T)) == 0,
          'DEL personal space documents emptied (content and versions gone), photo posts + files (attached and loose) 0, travel plans 0')
    check(count('SELECT count(*) FROM passkeys WHERE user_id=' + str(T)) == 0 and count('SELECT count(*) FROM api_tokens WHERE user_id=' + str(T)) == 0
          and count('SELECT count(*) FROM sessions WHERE user_id=' + str(T)) == 0, 'DEL leftover login-means rows deleted (passkeys 0, tokens 0, sessions 0)')
    check(q("SELECT plain_text FROM community.pages WHERE public_id='" + team_page + "'") == 'qf13r 팀 글 본문'
          and q("SELECT plain_text FROM community.pages WHERE public_id='" + board_page + "'") == 'qf13r 게시판 글 본문'
          and count("SELECT count(*) FROM community.comments WHERE author_id=" + str(T) + " AND body='qf13r 남는 댓글'") == 1
          and count('SELECT count(*) FROM files WHERE id=' + file_row + ' AND deleted_at IS NULL') == 1,
          'DEL team-space page, board post, comment and 자료실 file stay')
    bp = raw('GET', '/api/pages/' + board_page, user='employee')
    w = jbody(bp).get('writer') or jbody(bp).get('page', {}).get('writer')
    samples['board_writer_after_purge'] = w
    check(bp.status_code == 200 and w == {'username': 'deleted-' + str(T), 'display_name': '탈퇴한 사용자 #' + str(T)},
          'DEL the board post now shows its writer as deleted-<id> / 「탈퇴한 사용자 #<id>」')
    check(audits('account.delete_request', target=T, result='allow') == 1 and audits('account.delete_process', target=T, result='allow') == 1
          and audits('account.purge', target=T, result='allow') == 1,
          'DEL three audit actions recorded: account.delete_request · account.delete_process · account.purge')
    again = purge('--run', name='purge-account-again.txt')
    check(again.returncode == 0 and '탈퇴 계정: 대상 없음' in again.stdout, 'DEL second purge finds no account')
    integ = subprocess.run(['php', 'scripts/consistency_check.php', '--strict', '--quiet'], cwd=root, capture_output=True, text=True)
    save_text('consistency-after-account-purge.txt', 'exit=' + str(integ.returncode) + '\n' + integ.stdout + integ.stderr)
    check(integ.returncode == 0 and file_path.read_bytes() == file_bytes, 'DEL integrity check (--strict) passes after the account purge; the 자료실 file bytes are untouched')

    # 이름 충돌 · 다시 켠 계정은 건너뛴다
    make_user('clash', 'qf13r_clash')
    make_user('react', 'qf13r_react')
    for k in ('clash', 'react'):
        q("INSERT INTO account_deletion_requests(user_id) VALUES(" + str(users[k]['id']) + ")")
        q("UPDATE account_deletion_requests SET status='processed', processed_by=" + str(ADM) + " WHERE user_id=" + str(users[k]['id']))
        q("ALTER TABLE account_deletion_requests DISABLE TRIGGER account_deletion_requests_guard; UPDATE account_deletion_requests SET processed_at=now()-interval '31 days' WHERE user_id="
          + str(users[k]['id']) + "; ALTER TABLE account_deletion_requests ENABLE TRIGGER account_deletion_requests_guard;")
    q('UPDATE users SET is_active=false WHERE id=' + str(users['clash']['id']))
    q("INSERT INTO users(username,password_hash,role) VALUES('deleted-" + str(users['clash']['id']) + "','x','Employee')")
    skip = purge('--run', name='purge-account-skip.txt')
    check(skip.returncode == 0 and 'qf13r_clash' == q('SELECT username FROM users WHERE id=' + str(users['clash']['id']))
          and 'qf13r_react' == q('SELECT username FROM users WHERE id=' + str(users['react']['id']))
          and audits('account.purge', 'name_conflict', users['clash']['id']) == 1 and audits('account.purge', 'still_active', users['react']['id']) == 1,
          'DEL a name clash (deleted-<id> taken) and a re-activated account are skipped with error audits (not anonymized)')

    # ═════════ P2-R1 (RV-P2-1) · P2-R2 — 동의하지 않은 회원도 탈퇴는 요청한다 · 관문은 탈퇴 요청 둘 + 비밀번호 재인증 하나만 더 열린다 ═════════
    gate = subprocess.run(['php', '-r', 'require "src/bootstrap.php"; echo json_encode(Auth::CONSENT_GATE_ALLOW);'], cwd=root, capture_output=True, text=True, check=True).stdout
    save_text('r2-consent-gate-allow.json', gate + '\n')
    check(json.loads(gate) == ['GET /consent', 'GET /api/me', 'GET /api/me/consents', 'POST /api/me/consents', 'POST /api/logout',
                               'GET /api/me/delete-request', 'POST /api/me/delete-request', 'POST /api/me/reauth'],
          'R2 Auth::CONSENT_GATE_ALLOW = the five P1 routes + GET/POST /api/me/delete-request + POST /api/me/reauth (eight · nothing else · no passkey re-auth route)')
    NC = make_user('ncdel', 'qf13r_ncdel', consents=())
    new_session('ncdel')
    r = tb('GET', '/api/me/delete-request', user='ncdel')
    check(r.status_code == 200 and 'request' in jbody(r) and jbody(r)['request'] is None,
          'R1 non-consented member: GET /api/me/delete-request passes the consent gate (200 · request null)')
    gate_audits = audits('auth.gate', 'consent_required', NC)
    r = tb('POST', '/api/me/delete-request', {'confirm': True}, user='ncdel')
    samples['r1_nc_request_reauth_403'] = jbody(r)
    check(r.status_code == 403 and jbody(r).get('next') == 'reauth' and jbody(r).get('code') != 'consent_required'
          and audits('auth.gate', 'reauth_required', NC) == 1 and audits('auth.gate', 'consent_required', NC) == gate_audits
          and count('SELECT count(*) FROM account_deletion_requests WHERE user_id=' + str(NC)) == 0,
          'R1 non-consented member: POST without a fresh password check → 403 next:reauth from the handler (not consent_required) · no row')
    check(tb('POST', '/api/me/delete-request', {'confirm': True}, user='ncdel', csrf=False).status_code == 403
          and tb('POST', '/api/me/delete-request', {'confirm': True, 'user_id': O}, user='ncdel').status_code == 400
          and count('SELECT count(*) FROM account_deletion_requests WHERE user_id=' + str(NC)) == 0,
          'R1 non-consented member: the opened route still needs CSRF (403) and the exact body (400)')
    # P2-R2 — 비밀번호 재인증 창구가 동의 관문을 지난다(실제 창구 · 시험 DB 에 reauth_at 을 직접 넣지 않는다)
    check(q('SELECT reauth_at IS NULL FROM sessions WHERE user_id=' + str(NC)) == 't', 'R2 non-consented member starts with no re-auth window (sessions.reauth_at NULL)')
    gate_audits = audits('auth.gate', 'consent_required', NC)
    r = tb('POST', '/api/me/reauth', {'password': PW + 'x'}, user='ncdel')
    samples['r2_nc_reauth_wrong_400'] = jbody(r)
    check(r.status_code == 400 and jbody(r).get('code') != 'consent_required' and audits('auth.reauth', 'bad_credentials', NC) == 1
          and audits('auth.gate', 'consent_required', NC) == gate_audits and q('SELECT reauth_at IS NULL FROM sessions WHERE user_id=' + str(NC)) == 't',
          'R2 non-consented member: wrong password at POST /api/me/reauth → 400 from the handler (not consent_required) · auth.reauth|deny|bad_credentials · no window')
    check(tb('POST', '/api/me/reauth', {'password': PW}, user='ncdel', csrf=False).status_code == 403
          and tb('POST', '/api/me/reauth', {'password': PW, 'user_id': O}, user='ncdel').status_code == 400
          and q('SELECT reauth_at IS NULL FROM sessions WHERE user_id=' + str(NC)) == 't',
          'R2 non-consented member: the opened re-auth route still needs CSRF (403) and the exact body (400) · no window')
    r = tb('POST', '/api/me/reauth', {'password': PW}, user='ncdel')
    samples['r2_nc_reauth_200'] = {'status': r.status_code, 'keys': sorted(jbody(r).keys())}
    check(r.status_code == 200 and jbody(r).get('ok') is True and isinstance(jbody(r).get('reauth_until'), str)
          and q('SELECT reauth_at IS NOT NULL FROM sessions WHERE user_id=' + str(NC)) == 't'
          and audits('auth.reauth', target=NC, result='allow') == 1 and audits('auth.gate', 'consent_required', NC) == gate_audits
          and q('SELECT count(*) FROM user_consents WHERE user_id=' + str(NC)) == '0',
          'R2 non-consented member: POST /api/me/reauth (password) → 200 {ok, reauth_until} · window opened by the route · auth.reauth|allow · no consent_required · still no consent rows')
    r = tb('POST', '/api/me/delete-request', {'confirm': True}, user='ncdel')
    samples['r1_nc_request_201'] = jbody(r)
    nc_req = jbody(r).get('request', {}).get('id')
    check(r.status_code == 201 and jbody(r)['request']['status'] == 'pending' and audits('account.delete_request', target=NC, result='allow') == 1
          and q('SELECT count(*) FROM user_consents WHERE user_id=' + str(NC)) == '0',
          'R2 non-consented member: password re-auth 200 → POST /api/me/delete-request 201 pending + account.delete_request audit · still no consent rows')
    check(jbody(tb('GET', '/api/me/delete-request', user='ncdel')).get('request', {}).get('id') == nc_req
          and tb('POST', '/api/me/delete-request', {'confirm': True}, user='ncdel').status_code == 409,
          'R1 non-consented member: GET shows the own request · a second request → 409')
    # 재인증 창이 열린 뒤에도 다른 창구는 그대로 — 재인증은 권한을 주지 않는다 · 패스키 재인증 창구·재인증 화면은 열지 않았다
    gate_audits = audits('auth.gate', 'consent_required', NC)
    nc_gated = (('GET', '/api/files', None), ('GET', '/api/me/activity', None), ('POST', '/api/folders', {'name': 'x'}), ('GET', '/api/messages', None),
                ('GET', '/api/admin/deletion-requests', None), ('POST', '/api/admin/deletion-requests/' + str(nc_req), {'confirm': True}),
                ('POST', '/api/me/reauth/passkey/options', {}), ('POST', '/api/me/reauth/passkey', {}))
    for method, path, body in nc_gated:
        r = tb(method, path, body, user='ncdel')
        check(r.status_code == 403 and jbody(r).get('code') == 'consent_required',
              'R2 after the password re-auth, other session API still gated: ' + method + ' ' + path.replace(str(nc_req), '{num}') + ' → 403 consent_required')
    check(audits('auth.gate', 'consent_required', NC) == gate_audits + len(nc_gated), 'R2 each of those blocked calls leaves auth.gate|deny|consent_required')
    r = requests.get(site + '/community/api/me/space', headers={'User-Agent': UA, 'Cookie': '__Host-tbox_session=' + users['ncdel']['session']}, timeout=30, allow_redirects=False)
    check(r.status_code == 403 and jbody(r).get('code') == 'consent_required', 'R2 after the re-auth, community session API still gated: GET /community/api/me/space → 403 consent_required')
    for path, loc in (('/settings', '/tbox/consent?to=%2Ftbox%2Fsettings'), ('/', '/tbox/consent'), ('/messages', '/tbox/consent?to=%2Ftbox%2Fmessages')):
        r = tb('GET', path, user='ncdel', html=True)
        check(r.status_code == 302 and r.headers.get('Location') == loc, 'R2 after the re-auth, screens still gated: /tbox' + path + ' → 302 ' + loc)
    r = tb('GET', '/reauth', user='ncdel', html=True)
    check(r.status_code == 302 and (r.headers.get('Location') or '').startswith('/tbox/consent'), 'R2 the re-auth screen GET /tbox/reauth stays gated → 302 /tbox/consent…')
    # 관리자 쪽 창구는 열리지 않았다 — 동의하지 않은 관리자가 **실제 창구로** 재인증해도 목록·처리·계정 관리 403 consent_required
    make_user('ncadmin', 'qf13r_ncadmin', role='Admin', mfa=True, consents=())
    new_session('ncadmin')
    r = tb('POST', '/api/me/reauth', {'password': PW}, user='ncadmin')
    check(r.status_code == 200 and q("SELECT reauth_at IS NOT NULL FROM sessions WHERE user_id=" + str(users['ncadmin']['id'])) == 't'
          and audits('auth.reauth', target=users['ncadmin']['id'], result='allow') == 1,
          'R2 a non-consented admin re-authenticates through POST /api/me/reauth → 200 (window open)')
    check(all(tb(m, p, b, user='ncadmin').status_code == 403 and jbody(tb(m, p, b, user='ncadmin')).get('code') == 'consent_required'
              for m, p, b in (('GET', '/api/admin/deletion-requests', None), ('POST', '/api/admin/deletion-requests/' + str(nc_req), {'confirm': True}),
                              ('GET', '/api/users', None)))
          and q('SELECT status FROM account_deletion_requests WHERE id=' + str(nc_req)) == 'pending',
          'R2 that non-consented admin (fresh re-auth via the route) still cannot list or process deletion requests or list users (403 consent_required · still pending)')
    r = tb('GET', '/admin', user='ncadmin', html=True)
    check(r.status_code == 302 and (r.headers.get('Location') or '').startswith('/tbox/consent'), 'R2 that admin\'s admin screen GET /tbox/admin → 302 /tbox/consent…')
    # 처리는 그대로 — 동의한 관리자가 재인증하고 처리 → 즉시 로그인 불가
    check(tb('POST', '/api/me/reauth', {'password': PW}, user='boss').status_code == 200, 'R1 consented admin re-enters the password')
    lst = jbody(tb('GET', '/api/admin/deletion-requests', user='boss'))
    check([x['status'] for x in lst.get('requests', []) if x['id'] == nc_req] == ['pending'], 'R1 admin list shows the non-consented member request (pending)')
    r = tb('POST', '/api/admin/deletion-requests/' + str(nc_req), {'confirm': True}, user='boss')
    samples['r1_process_200'] = jbody(r)
    check(r.status_code == 200 and jbody(r)['request']['status'] == 'processed' and q('SELECT is_active FROM users WHERE id=' + str(NC)) == 'f'
          and count('SELECT count(*) FROM sessions WHERE user_id=' + str(NC)) == 0 and tb('GET', '/api/me', user='ncdel').status_code == 401
          and audits('account.delete_process', target=NC, result='allow') == 1,
          'R1 admin processes it → 200 processed · account inactive · sessions 0 · old session 401 · account.delete_process audit')

    # ═════════ P2-R1 (RV-P2-2) — `deleted-` 아이디 예약: 계정 만들기 · 초대 가입 · 아이디 바꾸기 ═════════
    MSG_DELETED = '탈퇴한 계정 전용 접두(deleted-)로 시작하는 아이디는 쓸 수 없습니다 — 다른 아이디로 정해 주세요'
    check(tb('POST', '/api/me/reauth', {'password': PW}, user='boss').status_code == 200, 'R1 admin re-auth before the user-management calls')
    bad_before = audits('user.create', 'bad_request')
    for name in ('deleted-424242', 'deleted-' + str(T), 'deleted-x'):
        r = tb('POST', '/api/users', {'username': name, 'role': 'Employee', 'reason_text': 'qf13r P2-R1 시험'}, user='boss')
        samples['r1_create_' + name.replace(str(T), 'T')] = jbody(r)
        check(r.status_code == 400 and jbody(r).get('error') == MSG_DELETED and count("SELECT count(*) FROM users WHERE username='" + name + "'") == (1 if name == 'deleted-' + str(T) else 0),
              'R1 admin create username ' + name.replace(str(T), '<purged id>') + ' → 400 with the deleted- message (no new account)')
    check(audits('user.create', 'bad_request') == bad_before + 3, 'R1 admin create deleted-… rejections leave user.create|deny|bad_request audits (3)')
    r = tb('POST', '/api/users', {'username': 'qf13r-deleted-ok', 'role': 'Employee', 'reason_text': 'qf13r P2-R1 대조'}, user='boss')
    check(r.status_code == 201 and count("SELECT count(*) FROM users WHERE username='qf13r-deleted-ok'") == 1,
          'R1 control: a name that only contains deleted- (qf13r-deleted-ok) is still created (201)')
    code = secrets.token_hex(32)
    q("INSERT INTO member_invites(code_hash,label,team_id,created_by,expires_at,max_uses) VALUES('" + hashlib.sha256(code.encode()).hexdigest()
      + "','qf13r R1',NULL," + str(ADM) + ",now()+interval '1 day',5)")
    join = requests.get(TB + '/join', headers={'User-Agent': UA, 'Accept': 'text/html'}, timeout=30)
    jcsrf = re.search(r'data-join-csrf="([a-f0-9]{64})"', join.text).group(1)
    jh = {'User-Agent': UA, 'X-Join-CSRF': jcsrf, 'Cookie': '__Host-moa_join=' + jcsrf}

    def register(name):
        q("UPDATE member_rate_limits SET window_start=now()-interval '2 minutes'")
        return requests.post(TB + '/api/register', json={'invite': code, 'username': name, 'display_name': '합성 가입', 'password': PW,
                                                         'agree_terms': True, 'age14': True}, headers=jh, timeout=40, allow_redirects=False)
    for name in ('deleted-31337', 'deleted-' + str(T)):
        r = register(name)
        samples['r1_register_' + name.replace(str(T), 'T')] = jbody(r)
        check(r.status_code == 400 and jbody(r).get('error') == MSG_DELETED and 'set-cookie' not in {k.lower() for k in r.headers}
              and q("SELECT used_count FROM member_invites WHERE label='qf13r R1'") == '0'
              and count("SELECT count(*) FROM users WHERE username='" + name + "'") == (1 if name == 'deleted-' + str(T) else 0),
              'R1 invite sign-up username ' + name.replace(str(T), '<purged id>') + ' → 400 with the same message · no account · invite unused · no session cookie')
    r = register('qf13r_join_ok')
    check(r.status_code == 201 and q("SELECT used_count FROM member_invites WHERE label='qf13r R1'") == '1', 'R1 control: invite sign-up with a normal name → 201')
    ren = make_user('rename', 'qf13r_rename')
    upd_before = audits('user.update', 'bad_request', ren)
    r = tb('PATCH', '/api/users/' + str(ren), {'username': 'deleted-' + str(ren), 'reason_text': 'qf13r P2-R1 시험'}, user='boss')
    samples['r1_rename_400'] = jbody(r)
    check(r.status_code == 400 and jbody(r).get('error') == MSG_DELETED and q('SELECT username FROM users WHERE id=' + str(ren)) == 'qf13r_rename'
          and audits('user.update', 'bad_request', ren) == upd_before + 1
          and q("SELECT count(*) FROM audit_logs WHERE action='user.update' AND target_id=" + str(ren) + " AND (detail::text LIKE '%deleted-%' OR target_name LIKE 'deleted-%')") == '0',
          'R1 username change to deleted-<id> → 400 with the same message · name unchanged · user.update|deny|bad_request audit without the tried name')
    r = tb('PATCH', '/api/users/' + str(ren), {'username': 'qf13r_renamed', 'reason_text': 'qf13r P2-R1 대조'}, user='boss')
    check(r.status_code == 200 and q('SELECT username FROM users WHERE id=' + str(ren)) == 'qf13r_renamed', 'R1 control: username change to a normal name → 200')
    r = tb('PATCH', '/api/users/' + str(T), {'username': 'deleted-' + str(T), 'reason_text': 'qf13r P2-R1 같은 이름'}, user='boss')
    check(r.status_code == 400 and jbody(r).get('error') != MSG_DELETED and q('SELECT username FROM users WHERE id=' + str(T)) == 'deleted-' + str(T),
          'R1 sending the purged account its own deleted-<id> name is not a rename (no deleted- message — the usual nothing-changed 400)')

    # ═════════ 감사 사슬 — 3년 지난 날 접기 · 검증 시작점 ═════════
    import datetime
    today = datetime.date.today()
    three = today.replace(year=today.year - 3) if not (today.month == 2 and today.day == 29) else today.replace(year=today.year - 3, day=28)
    old1, old2 = (three - datetime.timedelta(days=5)).isoformat(), (three - datetime.timedelta(days=4)).isoformat()
    new1, new2 = (today - datetime.timedelta(days=3)).isoformat(), (today - datetime.timedelta(days=2)).isoformat()
    yday = (today - datetime.timedelta(days=1)).isoformat()
    for d in (old1, old2, new1, new2, yday):
        (LOGS / ('audit.log-' + d.replace('-', ''))).write_text('{"synthetic":"' + d + '","n":1}\n{"synthetic":"' + d + '","n":2}\n')
    for d in (old1, old2, new1, new2):
        rr = chain('--date', d)
        check(rr.returncode == 0, 'CHAIN seal ' + ('old day' if d in (old1, old2) else 'recent day') + ' with --date')
    before_trim = CHAIN.read_text()
    old2_hash = [l.split()[1] for l in before_trim.splitlines() if l.startswith(old2)][0]
    check(not [l for l in before_trim.splitlines() if l.startswith('start ')] and chain('--verify', name='chain-verify-before.txt').returncode == 0, 'CHAIN 4 days sealed (2 older than 3 years), verify passes from GENESIS')
    for d in (old1, old2):
        (LOGS / ('audit.log-' + d.replace('-', ''))).unlink()   # logrotate maxage 1096 가 지운 것처럼
    rr = chain(name='chain-daily-trim.txt')
    after = CHAIN.read_text()
    entries = [l.split() for l in after.splitlines() if l and not l.startswith('#') and not l.startswith('start ')]
    check(rr.returncode == 0 and ('start ' + old2 + ' ' + old2_hash) in after and [e[0] for e in entries] == [new1, new2, yday]
          and entries[0][2] == old2_hash and audits('audit.chain_trim') == 1,
          'CHAIN daily run folds days older than 3 years into the verification start point (start <last folded day> <its hash>) and seals yesterday')
    vr = chain('--verify', name='chain-verify-after-trim.txt')
    check(vr.returncode == 0 and '결과: 통과' in vr.stdout and '시작점 ' + old2 in vr.stdout, 'CHAIN verify passes from the start point after folding')
    purge('--run', name='purge-before-chain-verify.txt')
    vr = chain('--verify', name='chain-verify-after-purge.txt')
    check(vr.returncode == 0 and '결과: 통과' in vr.stdout, 'CHAIN verify still passes after privacy_purge removed 3-year-old audit rows')
    check(chain('--date', old1).returncode == 3, 'CHAIN sealing a day before the start point is refused (usage error 3)')
    f = LOGS / ('audit.log-' + new1.replace('-', ''))
    keep = f.read_bytes()
    f.write_bytes(keep.replace(b'"n":2', b'"n":9'))
    vr = chain('--verify', name='chain-verify-tampered.txt')
    f.write_bytes(keep)
    check(vr.returncode == 2 and '해시 불일치' in vr.stderr, 'CHAIN a changed remaining day still fails verification (start point does not hide tampering)')
    bad_start = after.replace('start ' + old2 + ' ' + old2_hash, 'start ' + old2 + ' ' + '0' * 64)
    CHAIN.write_text(bad_start)
    vr = chain('--verify', name='chain-verify-bad-start.txt')
    CHAIN.write_text(after)
    check(vr.returncode == 2, 'CHAIN a forged start point breaks the first link (prev_broken)')

    # ═════════ 마이그레이션 — main 스키마 위 2회 = 새 설치 ═════════
    mig = 'tbox_memories_test_qf13rm_' + str(os.getpid())
    env = {k: v for k, v in os.environ.items() if not k.startswith('PG')}
    env['PGPASSFILE'] = str(Path.home() / '.config/qaredi/pgpass-testdb')
    opts = ['-h', '127.0.0.1', '-U', 'tbox_testdb', '-w']
    base_dir = WORK / 'mig-base'
    base_dir.mkdir(mode=0o700)
    repo = Path(__file__).resolve().parents[1]
    for name, tgt in (('db/schema.sql', 'schema.sql'), ('db/assistant.sql', 'assistant.sql'), ('db/community/schema.sql', 'community_schema.sql')):
        # schema.sql 이 \ir 로 assistant.sql 을 부른다 — 같은 판(main)을 나란히 둔다(P1 시험과 같은 준비)
        (base_dir / tgt).write_bytes(subprocess.run(['git', 'show', 'main:' + name], cwd=repo, check=True, capture_output=True).stdout)
    subprocess.run(['createdb'] + opts + ['-O', 'tbox_testdb', mig], env=env, check=True, capture_output=True)
    try:
        def mq(text):
            return subprocess.run(['psql'] + opts + ['-X', '-q', '-At', '-d', mig, '-v', 'ON_ERROR_STOP=1'], env=env, input=text.encode(), capture_output=True, check=True).stdout.decode().strip()

        def mf(path, adapt=True):
            src = Path(path)
            if adapt:
                # 합성 DB 소유자만(운영 마이그레이션은 tbox_owner 그대로) · grants 의 DB 이름만 이 임시 DB 로
                text = src.read_text().replace('OWNER TO tbox_owner;', 'OWNER TO tbox_testdb;').replace('DATABASE tbox_re ', 'DATABASE ' + mig + ' ')
                src = WORK / ('mig-' + src.name)
                src.write_text(text)
            r = subprocess.run(['psql'] + opts + ['-X', '-q', '-d', mig, '-v', 'ON_ERROR_STOP=1', '-f', str(src)], env=env, capture_output=True)
            if r.returncode != 0:
                print('MIG_SQL_FAILED ' + Path(path).name + ' ' + r.stderr.decode(errors='replace')[-600:], flush=True)
                raise subprocess.CalledProcessError(r.returncode, 'psql')
            return r
        mf(base_dir / 'schema.sql', adapt=False)
        mq('CREATE SCHEMA community;')
        mf(base_dir / 'community_schema.sql', adapt=False)
        mq("INSERT INTO users(username,password_hash,role) VALUES('qf13r_existing','x','Employee')")
        check(mq("SELECT to_regclass('public.account_deletion_requests') IS NULL") == 't', 'MIG base (main) schema has no account_deletion_requests table')
        for _ in range(2):
            mf(root / 'db/migrations/2026-10-21_privacy_retention.sql')
        mf(root / 'db/grants.sql')
        mf(root / 'db/community/grants.sql')
        shape = ("SELECT string_agg(column_name||':'||data_type||':'||is_nullable||':'||coalesce(column_default,''),',' ORDER BY ordinal_position) "
                 "FROM information_schema.columns WHERE table_schema='public' AND table_name='account_deletion_requests'")
        cons = "SELECT string_agg(conname||'='||pg_get_constraintdef(c.oid),' | ' ORDER BY conname) FROM pg_constraint c WHERE c.conrelid='public.account_deletion_requests'::regclass"
        idx = "SELECT string_agg(indexdef,' | ' ORDER BY indexdef) FROM pg_indexes WHERE schemaname='public' AND tablename IN ('account_deletion_requests','member_messages')"
        trg = "SELECT string_agg(tgname||':'||pg_get_triggerdef(t.oid),' | ') FROM pg_trigger t WHERE t.tgrelid='public.account_deletion_requests'::regclass AND NOT tgisinternal"
        priv = ("SELECT string_agg(r||':'||p||'='||has_table_privilege(r,'public.account_deletion_requests',p)::text,',' ORDER BY r,p) FROM "
                "unnest(ARRAY['" + APP['DB_USER'] + "','tbox_backup']) r, unnest(ARRAY['SELECT','INSERT','UPDATE','DELETE','TRUNCATE']) p")
        colpriv = ("SELECT string_agg(c||':'||has_column_privilege('" + APP['DB_USER'] + "','public.account_deletion_requests',c,'UPDATE')::text||'/'||"
                   "has_column_privilege('" + APP['DB_USER'] + "','public.account_deletion_requests',c,'INSERT')::text,',' ORDER BY c) FROM "
                   "unnest(ARRAY['id','user_id','status','requested_at','processed_by','processed_at','purged_at']) c")
        fns = ("SELECT string_agg(p.oid::regprocedure::text||':'||has_function_privilege('" + APP['DB_USER'] + "',p.oid,'EXECUTE')::text,',' ORDER BY p.oid::regprocedure::text) "
               "FROM pg_proc p WHERE p.proname LIKE 'privacy_purge%'")
        fresh = {}
        for label, sq in (('columns', shape), ('constraints', cons), ('indexes', idx), ('trigger', trg), ('privileges', priv), ('column privileges', colpriv), ('functions', fns)):
            fresh[label] = q(sq)
            check(mq(sq) == fresh[label] and fresh[label] != '', 'MIG migration x2 on main schema == fresh schema.sql + migration shape: ' + label)
        save_text('migration-shape.json', json.dumps(fresh, ensure_ascii=False, indent=1))
        check(fresh['column privileges'] == 'id:false/false,processed_at:true/false,processed_by:true/false,purged_at:false/false,requested_at:false/false,status:true/false,user_id:false/true'
              and 'DELETE=false' in fresh['privileges'] and fresh['functions'].count(':false') == 4,
              'MIG app role: INSERT only user_id, UPDATE only status/processed_by/processed_at, no DELETE; no EXECUTE on the four functions')
        check(mq('SELECT count(*) FROM account_deletion_requests') == '0' and mq("SELECT count(*) FROM users WHERE username='qf13r_existing' AND is_active") == '1',
              'MIG migration changes no business row (0 requests, existing account untouched)')
    finally:
        subprocess.run(['dropdb'] + opts + ['--if-exists', mig], env=env, capture_output=True)

    # ═════════ 화면 — 「내 계정」 탈퇴 패널 · 관리자 「탈퇴 요청」 표 ═════════
    make_user('ui', 'qf13r_ui')
    make_user('uiadmin', 'qf13r_uiadmin', role='Admin', mfa=True)
    new_session('ui')
    new_session('uiadmin')
    # P2-R2 — 재인증 창은 실제 창구로 연다(시험 DB 에 reauth_at 을 넣지 않는다)
    check(tb('POST', '/api/me/reauth', {'password': PW}, user='uiadmin').status_code == 200, 'UI admin re-enters the password (POST /api/me/reauth 200) before opening /tbox/admin')
    host = urlsplit(site).hostname

    def cookie(key):
        return [{'name': '__Host-tbox_session', 'value': users[key]['session'], 'domain': host, 'path': '/', 'secure': True, 'httpOnly': True, 'sameSite': 'Lax'}]

    def overflow(tab):
        return tab.evaluate('document.documentElement.scrollWidth - window.innerWidth')

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=os.environ.get('CHROMIUM_EXECUTABLE'))
        console, errors, bad = [], [], []

        def watch(page):
            page.on('console', lambda m: console.append((m.type, m.text)))
            page.on('pageerror', lambda e: errors.append(str(e)))
            page.on('response', lambda r: bad.append(r.url) if r.status >= 500 else None)
        ctx = browser.new_context(viewport={'width': 1280, 'height': 900}, locale='ko-KR', user_agent=UA)
        ctx.add_cookies(cookie('ui'))
        tab = ctx.new_page()
        watch(tab)
        tab.goto(TB + '/settings', wait_until='networkidle')
        expect(tab.locator('#delete-request-open')).to_be_visible()
        check(tab.locator('#delete-request-state').is_hidden(), 'UI settings: withdrawal panel shows the 「탈퇴 요청」 button when there is no request')
        tab.locator('#delete-request-open').click()
        expect(tab.locator('#confirm-dialog')).to_be_visible()
        check(tab.locator('#confirm-dialog-title').inner_text() == '탈퇴를 요청할까요?' and count('SELECT count(*) FROM account_deletion_requests WHERE user_id=' + str(users['ui']['id'])) == 0,
              'UI settings: the shared confirm dialog asks first (no request yet)')
        tab.keyboard.press('Escape')
        expect(tab.locator('#confirm-dialog')).not_to_be_visible()
        check(count('SELECT count(*) FROM account_deletion_requests WHERE user_id=' + str(users['ui']['id'])) == 0, 'UI settings: closing the confirm dialog sends nothing')
        tab.locator('#delete-request-open').click()
        tab.locator('#confirm-dialog .btn--danger').click()
        expect(tab.locator('#reauth-dialog-form input[type=password]')).to_be_visible()
        reauth_meta = tab.locator('dialog.modal[open]:has(#reauth-dialog-form) .modal__meta').inner_text()
        samples['r1_settings_reauth_meta'] = reauth_meta
        check(reauth_meta.startswith('탈퇴를 요청하려면 비밀번호를 다시 확인해 주세요') and '관리 기능' not in reauth_meta,
              'R1 UI settings: the re-auth dialog for the withdrawal request says 「탈퇴를 요청하려면 비밀번호를 다시 확인해 주세요」 (not the admin-function wording)')
        tab.screenshot(path=str(out / 'ui-settings-reauth.png'))
        tab.locator('#reauth-dialog-form input[type=password]').fill(PW)
        tab.locator('#reauth-dialog-form input[type=password]').press('Enter')
        expect(tab.locator('#delete-request-state')).to_be_visible(timeout=15000)
        check('탈퇴를 요청했습니다' in tab.locator('#delete-request-state').inner_text() and tab.locator('#delete-request-open').is_hidden()
              and count("SELECT count(*) FROM account_deletion_requests WHERE status='pending' AND user_id=" + str(users['ui']['id'])) == 1,
              'UI settings: confirm → shared re-auth dialog → the same request is sent once → state 「탈퇴를 요청했습니다」')
        tab.screenshot(path=str(out / 'ui-settings-requested.png'), full_page=True)
        # 대조 — 목적 키 없이 부르는 공용 재인증 창(관리 기능 · 커뮤니티 모듈들)은 전과 같은 문구
        tab.evaluate("window.qarediHandleReauth({status: 403}, {next: 'reauth'}, function () {})")
        expect(tab.locator('#reauth-dialog-form input[type=password]')).to_be_visible()
        admin_meta = tab.locator('dialog.modal[open]:has(#reauth-dialog-form) .modal__meta').inner_text()
        check(admin_meta.startswith('관리 기능을 쓰려면 비밀번호를 다시 확인합니다') and '탈퇴' not in admin_meta,
              'R1 UI control: the shared re-auth dialog without the withdrawal key keeps the admin-function wording')
        tab.keyboard.press('Escape')
        expect(tab.locator('#reauth-dialog-form')).to_have_count(0)
        ui_req = q("SELECT id FROM account_deletion_requests WHERE user_id=" + str(users['ui']['id']))
        ctx.clear_cookies()
        ctx.add_cookies(cookie('uiadmin'))
        tab.goto(TB + '/admin', wait_until='networkidle')
        row_ui = tab.locator('#admin-deletions tr', has_text='qf13r_ui')
        expect(row_ui).to_have_count(1)
        row_ui.locator('button', has_text='탈퇴 처리').click()
        expect(tab.locator('#confirm-dialog')).to_be_visible()
        check('개인 문서 0 · 사진 기록 0 · 여행 계획 0' in tab.locator('#confirm-dialog').inner_text(), 'UI admin: the confirm dialog shows what will be deleted in 30 days (handover check)')
        tab.locator('#confirm-dialog .btn--danger').click()
        expect(tab.locator('#admin-deletions-ok')).to_be_visible(timeout=15000)
        check(q('SELECT status FROM account_deletion_requests WHERE id=' + ui_req) == 'processed' and q("SELECT is_active FROM users WHERE id=" + str(users['ui']['id'])) == 'f',
              'UI admin: 「탈퇴 처리」 → confirm → processed (account inactive) and the success line shows')
        tab.screenshot(path=str(out / 'ui-admin-processed.png'), full_page=True)
        check(not errors and not bad and not [c for c in console if c[0] in ('error', 'warning') and 'Failed to load resource' not in c[1]],
              'UI flows: no page errors, no 5xx, no console errors/warnings from page scripts')
        ctx.close()

        # 넓이 · 밝게/어둡게 · 고대비 — 가로 넘침 0
        new_session('employee')
        q('UPDATE users SET high_contrast=false WHERE id IN (' + str(E) + ',' + str(users['uiadmin']['id']) + ')')
        results = []
        for contrast in (False, True):
            q('UPDATE users SET high_contrast=' + ('true' if contrast else 'false') + ' WHERE id IN (' + str(E) + ',' + str(users['uiadmin']['id']) + ')')
            for scheme in ('light', 'dark'):
                for width in (360, 412, 820, 1440):
                    if contrast and width in (820,):
                        continue
                    for key, path in (('employee', '/settings'), ('uiadmin', '/admin')):
                        c2 = browser.new_context(viewport={'width': width, 'height': 900}, locale='ko-KR', user_agent=UA, color_scheme=scheme)
                        c2.add_cookies(cookie(key))
                        t2 = c2.new_page()
                        watch(t2)
                        t2.goto(TB + path, wait_until='networkidle')
                        sel = '#delete-request-panel' if key == 'employee' else '#admin-deletions'
                        visible = t2.locator(sel).count() == 1
                        ov = overflow(t2)
                        hc = t2.evaluate("document.documentElement.getAttribute('data-contrast')")
                        results.append({'page': path, 'width': width, 'scheme': scheme, 'contrast': contrast, 'overflow': ov, 'present': visible, 'hc': hc})
                        if width in (360, 1440) and scheme == 'light':
                            t2.screenshot(path=str(out / ('ui' + path.replace('/', '-') + '-' + str(width) + ('-hc' if contrast else '') + '.png')), full_page=True)
                        c2.close()
        save_text('ui-widths.json', json.dumps(results, ensure_ascii=False, indent=1))
        check(all(r['overflow'] <= 0 and r['present'] for r in results) and all((r['hc'] == 'high') == r['contrast'] for r in results),
              'UI settings & admin at 360·412·820·1440 × light·dark (+ high contrast 360·412·1440): horizontal overflow 0, panels present')
        check(not errors and not bad and not [c for c in console if c[0] in ('error', 'warning') and 'Failed to load resource' not in c[1]],
              'UI width sweep: no page errors, no 5xx, no console errors/warnings from page scripts')
        browser.close()
finally:
    save_text('samples.json', json.dumps(samples, ensure_ascii=False, indent=1))
    (out / 'qf13-retention-results.json').write_text(json.dumps(checks, ensure_ascii=False, indent=2))
    print('RESULT pass=' + str(sum(x['pass'] for x in checks)) + ' fail=' + str(sum(not x['pass'] for x in checks)), flush=True)
