#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
R18 dry-run 이 상태를 쓴다 (dry-run reaches a state write) — 1-F 정적 규칙, python3 stdlib 만, 파일은 읽기만.

answers: BF-0001 · FP-0005(v3 묶음 K — dry-run 참 갈래가 mktemp 임시 사본 아래 링크를 돌리는 것을 「조건 없는 쓰기」로 읽던 오탐) ·
         v4 묶음 A: v3 독립 검토 낮음 6(scripts/scan_pending.php 205·258·262 @52b3d1c·31c785d — 상수 가드·복합 조건 가드 오탐) ·
         BF-0008(결함 판 dd25d24 의 193·239·243 은 dry 확인이 없어 여전히 울려야 · 고친 판 242dece 는 조용해야)

무엇을 부류로 잡나:
  dry-run 류 플래그(`--dry-run`·`dry_run`·`DRY_RUN`·`$dryRun`·`$dry`·`noop` …)를 **받는** 스크립트(PHP·셸·파이썬)는
  「판정만 하고 아무것도 남기지 않는다」는 약속을 한다. 이 규칙은 그런 스크립트에서 파일·상태를 쓰는 호출이
  dry-run 조건에 막히지 않고 닿는 길을 잡는다. 특정 파일·변수 이름이 아니라 제어 흐름으로 본다.
    쓰기 — PHP: file_put_contents·fopen(쓰기 모드)·fwrite/fputs(STDOUT·STDERR·php://std* 말고, fopen 에서 온 손잡이는
      fopen 에서 한 번만)·rename·unlink·copy·mkdir·rmdir·touch·chmod·chown·symlink·link·move_uploaded_file,
      `->run|exec|query|prepare(…)`·pg_query 에 들어간 SQL 쓰기 문장(INSERT·UPDATE…SET·DELETE·TRUNCATE·DDL·GRANT).
      셸: 파일로 가는 `>`·`>>`·`&>`(/dev/null·/dev/std*·`>&2` 말고) · cp·mv·rm·install·mkdir·rmdir·touch·ln·truncate·
      rsync·chmod·chown·shred · tee · dd of= · sed/perl -i · git 의 쓰는 하위 명령(add·commit·tag 이름·push·reset…) ·
      psql·mysql·sqlite3 에 준 SQL 쓰기(-c·heredoc·<<<, 그리고 "$1" 을 psql 에 넘기는 도우미 함수 호출).
      파이썬: open(쓰기 모드)·Path.write_text 류·os.remove·os.rename·shutil.copy·rmtree … · execute("INSERT…").
      「쓰기 도우미」 — 몸에 막히지 않은 쓰기가 있는 함수(같은 파일 · require 한 파일 · src/ 의 클래스 정적 메서드 ·
      source 한 셸 파일, 여러 단계)를 부르는 것도 쓰기다. 같은 파일 도우미면 그 안의 쓰기 줄을, 다른 파일이면 부르는 줄을 적는다.
      mktemp·tempnam·sys_get_temp_dir·/tmp·$TMPDIR 에서 온 경로(이번 실행의 임시 자리)와 표준 출력·오류는 상태가 아니라 뺀다.
      임시 자리는 변수 흐름으로 따라간다: 대입(`X="$T/a"` · PHP `$p = "$t/x"`)과 반복 변수 한 단계(v3 — 셸
      `for l in "$T"/nginx/sites-enabled/*` · PHP `foreach ([$t . '.a', …] as $f)` · `foreach (glob($t . '/*') as $f)` 처럼
      반복 대상이 전부 임시 자리면 반복 변수도 그 아래). 그래서 dry-run **참** 갈래가 같은 갈래의 mktemp 사본 아래에
      쓰는 준비(nginx -t 를 사본으로 돌리기 등)는 조용하고, 같은 갈래라도 임시 밖(/etc 등)에 쓰면 여전히 울린다.
      같은 줄에 `qcheck-allow: R18` 가 있으면 뺀다(skip 으로 센다).
    막힘 — 쓰기 문장을 감싸는 if 의 조건이 「dry-run 아님」을 뜻하거나(`!$dryRun && …`·`[ "$DRY" -eq 0 ]`·`not args.dry_run`),
      dry-run 조건 갈래의 else·뒤 elseif 이거나, 같은 블록·바깥 블록의 앞선 문장이 `if (dry-run) { … return|exit|continue|
      break|throw; }`(셸 `[ "$DRY" = 1 ] && exit`, 끝내는 함수 호출 포함)이거나, 식 안에서 `$dry || w()`·`$dry ? … : w()` 이면.
      한 단계 흐름: 쓰기가 `if ($flag)` 안이면(깃발 = dry-run 아닌 변수), 그 깃발에 참 값을 넣는 자리마다 위 조건이 있는지
      본다(`||` 로 이은 깃발은 전부, `&&` 는 하나라도). 깃발 자리가 함수 안이면 그 함수를 부르는 자리까지 한 단계 본다.
      상수 가드(v4 · PHP): `define('X', <식>)`·맨 위 `const X = <식>;` 의 식이 dry-run 플래그로 정해지면(`$dryRun` ·
      `!$dryRun` · 앞의 그런 상수) X 를 그 조건으로 읽는다 — 함수 안 `if (X) { …; return; }` 뒤의 쓰기는 막힘.
      복합 조건 가드(v4 · PHP·셸·파이썬): 앞선 문장 `if (A && dry) { …; continue|return|exit; }` 가 있고, 쓰기를 감싼 if 갈래들의
      맨 위 && 조각이 A 의 조각을 **글이 같게** 모두 담으며, 그 사이에 A 의 변수에 값이 다시 들어가지 않으면 막힘
      (`if (is_file($q) && $dryRun) { continue; } if (is_file($q)) { 쓰기 }`). 조각이 다르거나 앞 갈래가 끝내지 않으면 그대로 울림.
  대상 밖: dry-run 류 플래그를 스스로 받지 않는 스크립트(다른 스크립트에 `--dry-run` 을 넘기기만 하는 시험 등).
  심각도: 지우기(unlink·rm·rmdir·DELETE·DROP·TRUNCATE·rmtree)는 높음, 나머지는 중간.

대상: --root 아래 --dirs(기본 scripts,tests)의 셸(*.sh·*.bash·셸 셔뱅)·PHP(*.php·php 셔뱅)·파이썬(*.py·python 셔뱅).
      --root 가 git 작업 트리의 맨 위이면 `git ls-files --cached --others --exclude-standard`, 아니면 디렉터리 전체.
      기본으로 `tests/static/fixtures/*` 는 뺀다(--exclude 로 바꿈). 도우미 사전은 src·scripts·tests 의 PHP·셸에서 모은다.
      --outer <바깥 배치>(기본 <root>/..): 그 아래 scripts/ 가 있으면 함께 본다(`none` 이면 끔). 경로는 뿌리 기준(../scripts/…).
출력: FINDING R18 <심각도> <파일:줄> <설명> · 끝 줄 RESULT pass=<dry-run 스크립트 - 발견 파일> fail=<발견 수> skip=<못 읽음·구문 오류>
      --verbose 는 본 dry-run 스크립트를 NOTE 줄로 적는다.
rc:   0 발견 없음 · 1 발견 있음 · 2 실행 오류(뿌리 없음·git 목록 실패 = 검사 못 함)
한계: heredoc·`php -r`·`bash -c` 로 만드는 속 스크립트, 동적 함수 이름, `$obj->m()` 인스턴스 메서드, 인자 값에 따라 쓰기가
      갈리는 도우미(`f write`/`f print`)는 보지 않는다. 깃발 흐름은 한 단계까지다(깃발을 켜는 조건의 깃발은 보지 않음).
"""
import argparse
import ast
import fnmatch
import os
import re
import subprocess
import sys

RULE = 'R18'
ALLOW_RE = re.compile(r'qcheck-allow\s*[:=]?\s*(?:[A-Za-z0-9_-]+\s*,\s*)*R18\b')
DEFAULT_DIRS = ('scripts', 'tests')
LIB_DIRS = ('src', 'scripts', 'tests')
DEFAULT_EXCLUDES = ['tests/static/fixtures/*']

# ─── 이름 판정 ────────────────────────────────────────────────────────────
DRY_TOKENS = {'dry', 'dryrun', 'noop', 'whatif'}
_TOK_RE = re.compile(r'[A-Z]?[a-z]+|[A-Z]+(?![a-z])|[0-9]+')
DRY_LIT_RE = re.compile(r'(?i)(?:^|[^a-z])(?:--?)?(?:dry[-_ ]?run|no[-_]?op)(?:$|[^a-z])')
# 플래그 자체를 뜻하는 글자(옵션 이름·환경 변수 이름) — 스크립트가 dry-run 을 「받는다」의 근거
DRY_FLAG_RE = re.compile(r'(?i)-{0,2}(?:dry[-_]?run|no[-_]?op|what[-_]?if)|--?dry')
DRY_WORD_RE = re.compile(r'(?i)dry|no[-_]?op|what[-_]?if')
FLAGLIKE = {'', '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off', 'y', 'n'}


def name_tokens(name):
    return [t.lower() for t in _TOK_RE.findall(name or '')]


def is_dry_name(name):
    return any(t in DRY_TOKENS for t in name_tokens(name))


SQL_WRITE_RE = re.compile(
    r'(?is)\b(?:insert\s+into|update\s+[a-z_."0-9]+(?:\s+(?:as\s+)?[a-z_]+)?\s+set\b|delete\s+from|truncate\b|'
    r'drop\s+(?:table|schema|index|view|database|role|user|function|trigger|sequence|type|extension|owned)|'
    r'alter\s+(?:table|schema|index|view|database|role|user|sequence|default)|'
    r'create\s+(?:table|schema|index|unique|view|database|role|user|function|or\s+replace|trigger|sequence|type|extension)|'
    r'grant\s|revoke\s|merge\s+into|reassign\s+owned)')
SQL_DESTRUCT_RE = re.compile(r'(?is)\b(?:delete\s+from|truncate\b|drop\s+)')
TEMP_LIT_RE = re.compile(r'^(?:/tmp/|/tmp$|/var/tmp/|/dev/shm/|\$\{?TMPDIR\b|\$\{?TMP\b)')
STD_TARGETS = {'/dev/null', '/dev/stdout', '/dev/stderr', '/dev/tty', '-'}


# ─── 조건(dry-run 과의 관계) ──────────────────────────────────────────────
class Cond(object):
    """on: dry-run 이면 조건이 참 · off: 조건이 참이면 dry-run 이 아님 · dnf: 깃발 흐름용 (논리합 · 논리곱 목록)."""
    __slots__ = ('on', 'off', 'dnf')

    def __init__(self, on=False, off=False, dnf=None):
        self.on, self.off = on, off
        self.dnf = dnf if dnf is not None else [[('x',)]]


def c_dry(positive=True):
    return Cond(on=positive, off=not positive, dnf=[[('x',)]] if positive else [[('dryoff',)]])


def c_flag(name, positive=True):
    return Cond(False, False, [[('flag', name)]] if positive else [[('x',)]])


def c_other():
    return Cond()


def c_not(c):
    return Cond(on=c.off, off=c.on, dnf=[[('dryoff',)]] if c.on else [[('x',)]])


def c_and(cs):
    cs = [c for c in cs if c is not None]
    if not cs:
        return c_other()
    dnf = [[]]
    for c in cs:
        dnf = [a + b for a in dnf for b in c.dnf]
        if len(dnf) > 32:
            dnf = [[('x',)]]
            break
    return Cond(on=all(c.on for c in cs), off=any(c.off for c in cs), dnf=dnf)


def c_or(cs):
    cs = [c for c in cs if c is not None]
    if not cs:
        return c_other()
    dnf = []
    for c in cs:
        dnf.extend(c.dnf)
    if len(dnf) > 32:
        dnf = [[('x',)]]
    return Cond(on=any(c.on for c in cs), off=all(c.off for c in cs), dnf=dnf)


# ─── 언어 중립 중간 표현 ───────────────────────────────────────────────────
class Event(object):
    __slots__ = ('kind', 'what', 'line', 'destr', 'callee', 'eguard', 'stmt', 'sql')

    def __init__(self, kind, what, line, destr=False, callee=None, eguard=False):
        self.kind, self.what, self.line, self.destr = kind, what, line, destr
        self.callee, self.eguard, self.stmt, self.sql = callee, eguard, None, None


class Stmt(object):
    def __init__(self, kind, line):
        self.kind, self.line = kind, line
        self.parent = None          # Block
        self.pos = 0
        self.events, self.sets, self.term = [], [], False
        self.children = []          # 속 블록(인라인: 명령 치환 · 이름 없는 클로저 · 그룹 · 반복 몸 …)
        self.branches, self.els, self.conds = [], None, []   # if
        self.name, self.body, self.cls = None, None, None     # func
        self.loop_cond = None       # while
        self.bconj = None           # if: 갈래마다 맨 위 && 조각 [(글 열쇠, Cond, 변수 이름들)] — 복합 조건 가드(v4)
        self.loop = False           # 반복(while·until·for·foreach·do) — 복합 조건 가드가 반복 밖으로 올라갈 때 본다(v4 검토 중간 1)
        self.binds = []             # 반복 머리가 묶는 이름(PHP foreach `as` · 파이썬 튜플 풀기) — sets 밖(깃발 흐름엔 안 씀)

    def blocks(self):
        out = list(self.children)
        out.extend(self.conds)
        out.extend(b for _, b in self.branches)
        if self.els is not None:
            out.append(self.els)
        if self.body is not None:
            out.append(self.body)
        return out


class Block(object):
    __slots__ = ('stmts', 'owner', 'role')

    def __init__(self, stmts, owner=None, role=('top',)):
        self.stmts, self.owner, self.role = [], owner, role
        for s in stmts:
            self.add(s)

    def add(self, s):
        s.parent = self
        s.pos = len(self.stmts)
        self.stmts.append(s)


def walk_stmts(block, into_funcs=False):
    """블록 안 문장 전부(함수 몸은 into_funcs 일 때만)."""
    for s in block.stmts:
        yield s
        if s.kind == 'func' and not into_funcs:
            continue
        for b in s.blocks():
            for x in walk_stmts(b, into_funcs):
                yield x


def terminates(block):
    for s in block.stmts:
        if s.kind == 'simple' and s.term:
            return True
        if s.kind == 'block' and any(terminates(b) for b in s.children if b.role == ('group',)):
            return True
        if s.kind == 'if' and s.els is not None and all(terminates(b) for _, b in s.branches) and terminates(s.els):
            return True
    return False


def early_exit(s):
    """dry-run 이면 여기서 끝나는 문장인가 — if (dry) { …; exit; } · 무조건 끝내기."""
    if s.kind == 'simple':
        return s.term
    if s.kind == 'block':
        return any(b.role == ('group',) and terminates(b) for b in s.children)
    if s.kind != 'if':
        return False
    for c, body in s.branches:
        if c.on:
            return terminates(body)
        if c.off:
            continue
        return False
    return s.els is not None and terminates(s.els)


def partial_exit(s):
    """복합 조건 가드(v4): `if (A && dry) { …; continue|return|exit; }` — dry-run 조각과 나머지 A 가 함께 참이면 끝나는
    첫 갈래. 나머지 조각들 [(글 열쇠, 변수 이름들)] 을, 그런 모양이 아니면 None."""
    if s.kind != 'if' or not s.branches or not s.bconj or not s.bconj[0]:
        return None
    if not terminates(s.branches[0][1]):
        return None
    parts = s.bconj[0]
    if not any(c.on for _k, c, _v in parts):
        return None
    rest = [(k, v) for k, c, v in parts if not c.on]
    if not rest or any(k is None for k, _v in rest):
        return None
    return rest


def assigned_names(stmts):
    """문장들(속 블록 포함 · 함수 몸 빼고)이 값을 넣는 이름."""
    out = set()
    for s in stmts:
        if s.kind == 'func':
            continue
        out.update(n for n, _v in s.sets)
        out.update(s.binds)
        for b in s.blocks():
            out |= assigned_names(b.stmts)
    return out


class Analyzer(object):
    """한 파일의 중간 표현 위에서 「dry-run 에서 닿나」를 판정한다."""

    def __init__(self, top, funcs, ext_writer, ext_what):
        self.top = top
        self.funcs = funcs              # 이름 → func Stmt (같은 파일)
        self.ext_writer = ext_writer    # 이름 → (무엇, 정의 위치) 다른 파일의 쓰기 도우미
        self.ext_what = ext_what
        self.sets = {}
        self.calls = {}
        for s in walk_stmts(top, into_funcs=True):
            for name, val in s.sets:
                if val:
                    self.sets.setdefault(name, []).append(s)
            for e in s.events:
                e.stmt = s
                if e.kind == 'call':
                    self.calls.setdefault(e.callee, []).append(s)
        self._flag_cache = {}
        self._writer = {}

    # ── 막힘 판정 ──
    def guard(self, st, flags_ok=True, why=None):
        """(막힘?, 경계 함수 Stmt|None). why 에 깃발 흐름이 실패한 까닭을 모은다."""
        node = st
        known = []      # 쓰기를 감싼 if 갈래의 && 조각: [({열쇠: 변수들}, 그 if 와 위 층 앞선 문장 사이에 대입된 이름들)]
        while True:
            blk = node.parent
            if blk is None:
                return False, None
            pre = blk.stmts[:node.pos]
            for prev in pre:
                if early_exit(prev):
                    return True, None
            if known and self.compound_guard(pre, known):
                return True, None
            if known:
                names = assigned_names(pre)
                for _keys, seen in known:
                    seen |= names
            owner, role = blk.owner, blk.role
            if owner is None:
                return False, None
            if owner.kind == 'func':
                return False, owner
            if owner.kind == 'if':
                if role[0] == 'branch':
                    i = role[1]
                    c = owner.branches[i][0]
                    if c.off or any(owner.branches[j][0].on for j in range(i)):
                        return True, None
                    if flags_ok and self.flag_guard(c, why):
                        return True, None
                    conj = owner.bconj[i] if owner.bconj and i < len(owner.bconj) else None
                    if conj:
                        keys = dict((k, v) for k, _c, v in conj if k is not None and not _c.on)
                        if keys:
                            known.append((keys, set()))
                elif role[0] == 'else':
                    if any(c.on for c, _ in owner.branches):
                        return True, None
                elif role[0] == 'cond':
                    if any(owner.branches[j][0].on for j in range(role[1])):
                        return True, None
            elif owner.kind == 'block' and role[0] == 'body' and owner.loop_cond is not None and owner.loop_cond.off:
                return True, None
            if known and owner.loop:
                # 반복 밖의 가드에서 보면 반복 안 조건은 바퀴마다 다시 판정된다 — 반복 머리가 묶는 이름(for 변수 ·
                # foreach `as` · while read)과 몸 어디서든 넣는 이름은 「다시 묶임」(v4 검토 중간 1, v3 대비 퇴행)
                names = assigned_names([owner])
                for _keys, seen in known:
                    seen |= names
            node = owner

    def compound_guard(self, pre, known):
        """앞선 문장 `if (A && dry) { …; 끝냄 }` 이 있고, 쓰기를 감싼 if 갈래들의 조각이 A 를 모두 담으며(글이 같은 조각),
        그 사이에 A 의 변수에 값이 다시 들어가지 않으면 — 쓰기에 닿을 때 dry-run 이면 이미 빠졌다."""
        for pi, prev in enumerate(pre):
            rest = partial_exit(prev)
            if not rest:
                continue
            between = assigned_names(pre[pi + 1:])
            ok = True
            for key, vs in rest:
                if not any(key in keys and not (vs & (between | seen)) for keys, seen in known):
                    ok = False
                    break
            if ok:
                return True
        return False

    def flag_guard(self, c, why=None):
        if not c.dnf:
            return False
        for disj in c.dnf:
            ok = False
            for a in disj:
                if a[0] == 'dryoff':
                    ok = True
                elif a[0] == 'flag':
                    good, bad = self.flag_sites_guarded(a[1])
                    if good:
                        ok = True
                    elif why is not None and bad:
                        why.append((a[1], bad))
                if ok:
                    break
            if not ok:
                return False
        return True

    def flag_sites_guarded(self, name):
        if name in self._flag_cache:
            return self._flag_cache[name]
        self._flag_cache[name] = (False, [])       # 되돌이 막기
        sites = self.sets.get(name, [])
        bad = []
        if not sites:
            res = (False, [])
        else:
            for s in sites:
                g, fn = self.guard(s, flags_ok=False)
                if g:
                    continue
                if fn is not None and fn.name in self.calls:
                    callers = self.calls[fn.name]
                    if callers and all(self.guard(cs, flags_ok=False)[0] for cs in callers):
                        continue
                bad.append(s.line)
            res = (not bad, bad)
        self._flag_cache[name] = res
        return res

    # ── 쓰기 도우미 ──
    def is_writer(self, name):
        if name in self.funcs:
            return bool(self.func_writes(self.funcs[name]))
        return name in self.ext_writer

    def func_writes(self, f):
        """함수 몸 안에서 막히지 않은 쓰기 사건들 [(사건, 까닭)]."""
        if f.name in self._writer:
            return self._writer[f.name] or []
        self._writer[f.name] = None     # 되돌이 막기(재귀 함수)
        out = []
        for s in walk_stmts(f.body):
            for e in s.events:
                if e.eguard:
                    continue
                if e.kind == 'call' and not (e.callee != f.name and self.is_writer(e.callee)):
                    continue
                why = []
                g, fn = self.guard(s, True, why)
                if g or fn is not f:
                    continue
                out.append((e, why))
        self._writer[f.name] = out
        return out

    def expand(self, e, via, seen):
        """막히지 않은 사건 e 를 보고할 (줄, 사건, 경유, 까닭) 들로 편다."""
        if e.kind == 'write':
            return [(e, via)]
        if e.callee in self.funcs:
            if e.callee in seen:
                return []
            out = []
            for inner, _why in self.func_writes(self.funcs[e.callee]):
                out.extend(self.expand(inner, via + [(e.callee, e.line)], seen | {e.callee}))
            return out
        if e.callee in self.ext_writer:
            return [(e, via)]
        return []

    def findings(self):
        out = []
        for s in walk_stmts(self.top):
            for e in s.events:
                if e.eguard:
                    continue
                if e.kind == 'call' and not self.is_writer(e.callee):
                    continue
                why = []
                g, fn = self.guard(s, True, why)
                if g or fn is not None:
                    continue
                for inner, via in self.expand(e, [], set()):
                    out.append((inner, via, why))
        return out


# ═══════════════════════════ 셸 ═══════════════════════════════════════════
SH_REDIR_RE = re.compile(r'(\d*|\{[A-Za-z_][A-Za-z0-9_]*\})(&>>|&>|<<<|<<-|<<|>>|>\||>&|<&|<>|>|<)')
SH_OPS = ('&&', '||', ';;&', ';;', ';&', '|&', '|', '&', ';', '(', ')')
ASSIGN_RE = re.compile(r'^([A-Za-z_][A-Za-z0-9_]*)(\[[^\]]*\])?\+?=')


class ShW(object):
    __slots__ = ('parts', 'line', 'raw')

    def __init__(self, parts, line, raw=''):
        self.parts, self.line, self.raw = parts, line, raw

    def lit(self):
        s = []
        for p in self.parts:
            if p[0] != 'lit':
                return None
            s.append(p[1])
        return ''.join(s)

    def bare(self):
        if len(self.parts) == 1 and self.parts[0][0] == 'lit' and self.parts[0][2] == 0:
            return self.parts[0][1]
        return None

    def text(self):
        out = []
        for p in self.parts:
            k = p[0]
            if k == 'lit':
                out.append(p[1])
            elif k == 'var':
                out.append('${%s}' % p[1])
            elif k == 'pos':
                out.append('$%s' % p[1])
            elif k == 'sub':
                out.append('$(%s)' % p[2])
            else:
                out.append('$((…))')
        return ''.join(out)

    def vars(self):
        return [p[1] for p in self.parts if p[0] == 'var']

    def subs(self):
        return [p[1] for p in self.parts if p[0] == 'sub']

    def single_var(self):
        """단어 전체가 변수 하나인가("$X" · ${X:-0} · $X) → 이름."""
        vs = [p for p in self.parts if not (p[0] == 'lit' and p[1] == '')]
        if len(vs) == 1 and vs[0][0] in ('var', 'pos'):
            return vs[0][1] if vs[0][0] == 'var' else '$%s' % vs[0][1]
        return None


class SN(object):
    def __init__(self, kind, line, **kw):
        self.kind, self.line = kind, line
        self.words, self.assigns, self.redirs, self.heredocs, self.herestr = [], [], [], [], []
        self.__dict__.update(kw)


class ShParser(object):
    def __init__(self, text, pos=0, line=1):
        self.t, self.n, self.i, self.line = text, len(text), pos, line
        self.pend = []
        self.peeked = None

    # ── 글자 단위 ──
    def _blank(self):
        while self.i < self.n:
            c = self.t[self.i]
            if c in ' \t\r':
                self.i += 1
            elif c == '\\' and self.t.startswith('\\\n', self.i):
                self.i += 2
                self.line += 1
            elif c == '#':
                k = self.t.find('\n', self.i)
                self.i = self.n if k < 0 else k
            else:
                break

    def _match(self, i, op, cl):
        depth, j = 0, i
        while j < self.n:
            c = self.t[j]
            if c == '\\':
                j += 2
                continue
            if c == "'" and op == '(':
                k = self.t.find("'", j + 1)
                j = self.n if k < 0 else k + 1
                continue
            if c == op:
                depth += 1
            elif c == cl:
                depth -= 1
                if depth == 0:
                    return j
            j += 1
        return self.n - 1

    def _heredocs(self):
        for delim, strip, quoted, node in self.pend:
            start = self.line
            lines = []
            while self.i < self.n:
                k = self.t.find('\n', self.i)
                if k < 0:
                    k = self.n
                ln = self.t[self.i:k]
                self.i = min(k + 1, self.n)
                self.line += 1
                cmp_ln = ln.lstrip('\t') if strip else ln
                if cmp_ln.rstrip('\r') == delim:
                    break
                lines.append(ln)
            node.heredocs.append(('\n'.join(lines), start, quoted))
        self.pend = []

    def peek(self):
        if self.peeked is None:
            self.peeked = self._lex()
        return self.peeked

    def take(self):
        t = self.peek()
        self.peeked = None
        return t

    def _lex(self):
        self._blank()
        if self.i >= self.n:
            return ('eof', None, self.line)
        c = self.t[self.i]
        line = self.line
        if c == '\n':
            self.i += 1
            self.line += 1
            if self.pend:
                self._heredocs()
            return ('nl', None, line)
        m = SH_REDIR_RE.match(self.t, self.i)
        if m:
            self.i = m.end()
            return ('redir', m.group(2), line, m.group(1))
        for op in SH_OPS:
            if self.t.startswith(op, self.i):
                self.i += len(op)
                return ('op', op, line)
        w = self._word()
        return ('w', w, line)

    def _word(self):
        parts, buf = [], []
        start, line0 = self.i, self.line

        def flush():
            if buf:
                parts.append(('lit', ''.join(buf), 0))
                del buf[:]

        while self.i < self.n:
            c = self.t[self.i]
            if c in ' \t\n;&|()<>\r':
                if c == '(' and not parts and re.match(r'^[A-Za-z_][A-Za-z0-9_]*(\[[^\]]*\])?\+?=$', ''.join(buf)):
                    j = self._match(self.i, '(', ')')
                    inner = self.t[self.i + 1:j]
                    flush()
                    sp = ShParser(inner, 0, self.line)
                    while True:
                        tk = sp.take()
                        if tk[0] == 'eof':
                            break
                        if tk[0] == 'w':
                            parts.extend(tk[1].parts)
                            parts.append(('lit', ' ', 0))
                    self.line += inner.count('\n')
                    self.i = j + 1
                    continue
                if c in '<>' and self.t.startswith('(', self.i + 1):
                    # 프로세스 치환 <(…) >(…)
                    j = self._match(self.i + 1, '(', ')')
                    sp = ShParser(self.t, self.i + 2, self.line)
                    node = sp.parse_list(stop_ops={')'})
                    flush()
                    parts.append(('sub', node, 'proc'))
                    self.line = sp.line
                    self.i = max(sp.i, j + 1) if sp.peek()[0] != 'op' else sp.i
                    if self.i <= j:
                        self.i = j + 1
                    continue
                break
            if c == '\\':
                if self.t.startswith('\\\n', self.i):
                    self.i += 2
                    self.line += 1
                    continue
                buf.append(self.t[self.i + 1:self.i + 2])
                self.i += 2
                continue
            if c == "'":
                flush()
                k = self.t.find("'", self.i + 1)
                if k < 0:
                    k = self.n
                s = self.t[self.i + 1:k]
                parts.append(('lit', s, 1))
                self.line += s.count('\n')
                self.i = k + 1
                continue
            if c == '"':
                self.i += 1
                flush()
                parts.extend(self._dquote())
                continue
            if c == '$':
                flush()
                parts.extend(self._dollar(0))
                continue
            if c == '`':
                flush()
                parts.append(self._backtick())
                continue
            buf.append(c)
            self.i += 1
        flush()
        return ShW(parts, line0, self.t[start:self.i])

    def _dquote(self):
        parts, buf = [], []

        def flush():
            if buf:
                parts.append(('lit', ''.join(buf), 2))
                del buf[:]

        while self.i < self.n:
            c = self.t[self.i]
            if c == '"':
                self.i += 1
                break
            if c == '\\' and self.i + 1 < self.n and self.t[self.i + 1] in '$`"\\\n':
                if self.t[self.i + 1] == '\n':
                    self.line += 1
                else:
                    buf.append(self.t[self.i + 1])
                self.i += 2
                continue
            if c == '$':
                flush()
                parts.extend(self._dollar(2))
                continue
            if c == '`':
                flush()
                parts.append(self._backtick())
                continue
            if c == '\n':
                self.line += 1
            buf.append(c)
            self.i += 1
        flush()
        if not parts:
            parts.append(('lit', '', 2))
        return parts

    def _dollar(self, q):
        t, i = self.t, self.i
        nx = t[i + 1] if i + 1 < self.n else ''
        if nx == '(':
            if t.startswith('((', i + 1):
                j = self._match(i + 1, '(', ')')
                self.line += t[i:j].count('\n')
                self.i = j + 1
                return [('arith', t[i + 3:j - 1])]
            sp = ShParser(t, i + 2, self.line)
            start = i + 2
            node = sp.parse_list(stop_ops={')'})
            tk = sp.peek()
            if tk[0] == 'op' and tk[1] == ')':
                sp.take()
            self.i, self.line = sp.i, sp.line
            return [('sub', node, t[start:max(start, self.i - 1)])]
        if nx == '[':
            j = t.find(']', i)
            self.i = self.n if j < 0 else j + 1
            return [('arith', '')]
        if nx == '{':
            j = self._match(i + 1, '{', '}')
            inner = t[i + 2:j]
            self.line += inner.count('\n')
            self.i = j + 1
            m = re.match(r'(!|#)?([A-Za-z_][A-Za-z0-9_]*|[0-9]+|[@*#?$!-])', inner)
            if not m:
                return [('lit', '', q)]
            name = m.group(2)
            rest = inner[m.end():]
            out = []
            if name.isdigit():
                out.append(('pos', name, q))
            elif name in ('@', '*'):
                out.append(('pos', '@', q))
            elif re.match(r'[A-Za-z_]', name):
                out.append(('var', name, q))
            if '$' in rest or '`' in rest:
                sp = ShParser(rest + '"', 0, self.line)
                for p in sp._dquote():
                    if p[0] != 'lit':
                        out.append(p)
            return out
        if nx == "'":
            j = i + 2
            while j < self.n and t[j] != "'":
                j += 2 if t[j] == '\\' else 1
            self.i = j + 1
            return [('lit', t[i + 2:j], 1)]
        if nx == '"':
            self.i = i + 2
            return self._dquote()
        m = re.match(r'[A-Za-z_][A-Za-z0-9_]*', t[i + 1:i + 200])
        if m:
            self.i = i + 1 + m.end()
            return [('var', m.group(0), q)]
        if nx.isdigit():
            self.i = i + 2
            return [('pos', nx, q)]
        if nx in ('@', '*'):
            self.i = i + 2
            return [('pos', '@', q)]
        if nx and nx in '#?$!-':
            self.i = i + 2
            return [('lit', '$' + nx, q)]
        self.i = i + 1
        return [('lit', '$', q)]

    def _backtick(self):
        j = self.i + 1
        while j < self.n and self.t[j] != '`':
            j += 2 if self.t[j] == '\\' else 1
        raw = self.t[self.i + 1:j]
        inner = re.sub(r'\\([`$\\])', r'\1', raw)
        node = ShParser(inner, 0, self.line).parse_list()
        self.line += raw.count('\n')
        self.i = j + 1
        return ('sub', node, inner)

    # ── 문법 ──
    def _skip_nl(self):
        while self.peek()[0] == 'nl':
            self.take()

    def _kw(self, t):
        return t[1].bare() if t[0] == 'w' else None

    def parse_list(self, stops=frozenset(), stop_ops=frozenset()):
        items = []
        line = self.peek()[2]
        while True:
            t = self.peek()
            if t[0] == 'eof':
                break
            if t[0] == 'nl' or (t[0] == 'op' and t[1] in (';', '&')):
                self.take()
                continue
            if t[0] == 'op' and t[1] in stop_ops:
                break
            kw = self._kw(t)
            if kw is not None and kw in stops:
                break
            if t[0] == 'op' and t[1] in (')', ';;', ';&', ';;&'):
                if stop_ops:
                    break
                self.take()
                continue
            mark = (self.i, self.peeked)
            node = self.parse_andor(stops, stop_ops)
            if node is None:
                if (self.i, self.peeked) == mark:
                    self.take()
                continue
            items.append(node)
        return SN('list', line, items=items)

    def parse_andor(self, stops, stop_ops):
        first = self.parse_pipeline(stops, stop_ops)
        if first is None:
            return None
        items, ops = [first], []
        while True:
            t = self.peek()
            if t[0] == 'op' and t[1] in ('&&', '||'):
                self.take()
                self._skip_nl()
                nx = self.parse_pipeline(stops, stop_ops)
                if nx is None:
                    break
                ops.append(t[1])
                items.append(nx)
            else:
                break
        if not ops:
            return first
        return SN('andor', first.line, items=items, ops=ops)

    def parse_pipeline(self, stops, stop_ops):
        neg = False
        t = self.peek()
        while self._kw(t) in ('!', 'time'):
            if self._kw(t) == '!':
                neg = not neg
            self.take()
            t = self.peek()
        c = self.parse_command(stops, stop_ops)
        if c is None:
            return None
        items = [c]
        while self.peek()[0] == 'op' and self.peek()[1] in ('|', '|&'):
            self.take()
            self._skip_nl()
            c2 = self.parse_command(stops, stop_ops)
            if c2 is None:
                break
            items.append(c2)
        if len(items) == 1 and not neg:
            return c
        return SN('pipe', c.line, items=items, neg=neg)

    def _trailing_redirs(self, node):
        while self.peek()[0] == 'redir':
            op = self.take()
            tw = self.peek()
            if tw[0] != 'w':
                break
            self.take()
            self._add_redir(node, op, tw[1])
        return node

    def _add_redir(self, node, op, w):
        o = op[1]
        if o in ('<<', '<<-'):
            quoted = any(p[0] == 'lit' and p[2] for p in w.parts)
            delim = ''.join(p[1] for p in w.parts if p[0] == 'lit')
            self.pend.append((delim, o == '<<-', quoted, node))
        elif o == '<<<':
            node.herestr.append(w)
        else:
            node.redirs.append((o, op[3], w))

    def parse_command(self, stops, stop_ops):
        if self.peeked is None:
            self._blank()
            if self.t.startswith('((', self.i):
                line = self.line
                j = self._match(self.i, '(', ')')
                text = self.t[self.i + 2:max(self.i + 2, j - 1)]
                self.line += self.t[self.i:j].count('\n')
                self.i = j + 1
                return self._trailing_redirs(SN('arith', line, text=text))
        t = self.peek()
        if t[0] == 'op' and t[1] == '(':
            self.take()
            body = self.parse_list(stop_ops={')'})
            if self.peek()[0] == 'op' and self.peek()[1] == ')':
                self.take()
            return self._trailing_redirs(SN('group', t[2], body=body, sub=True))
        if t[0] == 'w':
            kw = t[1].bare()
            if kw == 'if':
                return self._trailing_redirs(self.parse_if())
            if kw in ('while', 'until'):
                self.take()
                cond = self.parse_list(stops={'do'})
                if self._kw(self.peek()) == 'do':
                    self.take()
                body = self.parse_list(stops={'done'})
                if self._kw(self.peek()) == 'done':
                    self.take()
                return self._trailing_redirs(SN('while', t[2], cond=cond, body=body, until=(kw == 'until')))
            if kw in ('for', 'select'):
                return self._trailing_redirs(self.parse_for())
            if kw == 'case':
                return self._trailing_redirs(self.parse_case())
            if kw == '{':
                self.take()
                body = self.parse_list(stops={'}'})
                if self._kw(self.peek()) == '}':
                    self.take()
                return self._trailing_redirs(SN('group', t[2], body=body, sub=False))
            if kw == 'function':
                self.take()
                nt = self.take()
                name = nt[1].text() if nt[0] == 'w' else '?'
                if self.peek()[0] == 'op' and self.peek()[1] == '(':
                    self.take()
                    if self.peek()[0] == 'op' and self.peek()[1] == ')':
                        self.take()
                self._skip_nl()
                body = self.parse_command(stops, stop_ops)
                return SN('func', t[2], name=name, body=body)
            if kw == '[[':
                self.take()
                words = [t[1]]
                while True:
                    x = self.take()
                    if x[0] in ('eof', 'nl'):
                        break
                    if x[0] == 'w':
                        words.append(x[1])
                        if x[1].bare() == ']]':
                            break
                    else:
                        words.append(ShW([('lit', x[1], 0)], x[2], x[1]))
                return self._trailing_redirs(SN('simple', t[2], words=words))
            if kw in ('then', 'do', 'done', 'fi', 'elif', 'else', 'esac', '}', ']]', 'in'):
                return None
        if t[0] == 'op':
            return None
        if t[0] in ('eof', 'nl'):
            return None
        return self.parse_simple()

    def parse_if(self):
        line = self.take()[2]
        node = SN('if', line, clauses=[], els=None)
        cond = self.parse_list(stops={'then'})
        if self._kw(self.peek()) == 'then':
            self.take()
        body = self.parse_list(stops={'elif', 'else', 'fi'})
        node.clauses.append((cond, body))
        while True:
            kw = self._kw(self.peek())
            if kw == 'elif':
                self.take()
                cond = self.parse_list(stops={'then'})
                if self._kw(self.peek()) == 'then':
                    self.take()
                body = self.parse_list(stops={'elif', 'else', 'fi'})
                node.clauses.append((cond, body))
            elif kw == 'else':
                self.take()
                node.els = self.parse_list(stops={'fi'})
            elif kw == 'fi':
                self.take()
                break
            else:
                break
        return node

    def parse_for(self):
        line = self.take()[2]
        self._blank()
        var, words = None, []
        if self.peeked is None and self.t.startswith('((', self.i):
            j = self._match(self.i, '(', ')')
            self.line += self.t[self.i:j].count('\n')
            self.i = j + 1
        else:
            vt = self.take()
            var = vt[1].text() if vt[0] == 'w' else None
            self._skip_nl()
            if self._kw(self.peek()) == 'in':
                self.take()
                while self.peek()[0] == 'w':
                    words.append(self.take()[1])
        while self.peek()[0] == 'nl' or (self.peek()[0] == 'op' and self.peek()[1] == ';'):
            self.take()
        if self._kw(self.peek()) == 'do':
            self.take()
        body = self.parse_list(stops={'done'})
        if self._kw(self.peek()) == 'done':
            self.take()
        return SN('for', line, var=var, iter=words, body=body)

    def parse_case(self):
        line = self.take()[2]
        wt = self.take()
        word = wt[1] if wt[0] == 'w' else ShW([], line)
        self._skip_nl()
        if self._kw(self.peek()) == 'in':
            self.take()
        arms = []
        while True:
            self._skip_nl()
            t = self.peek()
            if t[0] == 'eof':
                break
            if self._kw(t) == 'esac':
                self.take()
                break
            if t[0] == 'op' and t[1] == '(':
                self.take()
            pats = []
            while True:
                x = self.take()
                if x[0] == 'w':
                    pats.append(x[1])
                if self.peek()[0] == 'op' and self.peek()[1] == '|':
                    self.take()
                    continue
                if self.peek()[0] == 'op' and self.peek()[1] == ')':
                    self.take()
                break
            body = self.parse_list(stops={'esac'}, stop_ops={';;', ';&', ';;&'})
            if self.peek()[0] == 'op' and self.peek()[1] in (';;', ';&', ';;&'):
                self.take()
            arms.append((pats, body))
            if not pats and not body.items:
                if self.peek()[0] not in ('w', 'nl', 'op'):
                    break
        return SN('case', line, word=word, arms=arms)

    def parse_simple(self):
        node = SN('simple', self.peek()[2])
        while True:
            t = self.peek()
            if t[0] == 'redir':
                self.take()
                tw = self.peek()
                if tw[0] != 'w':
                    break
                self.take()
                self._add_redir(node, t, tw[1])
                continue
            if t[0] != 'w':
                break
            w = t[1]
            if not node.words:
                sp = split_assign(w)
                if sp is not None:
                    self.take()
                    node.assigns.append((sp[0], sp[1], w.line))
                    continue
            self.take()
            node.words.append(w)
            if len(node.words) == 1 and not node.assigns and self.peek()[0] == 'op' and self.peek()[1] == '(':
                self.take()
                if self.peek()[0] == 'op' and self.peek()[1] == ')':
                    self.take()
                    self._skip_nl()
                    body = self.parse_command(frozenset(), frozenset())
                    return SN('func', node.line, name=w.text(), body=body)
        return node


def split_assign(w):
    if not w.parts or w.parts[0][0] != 'lit' or w.parts[0][2] != 0:
        return None
    m = ASSIGN_RE.match(w.parts[0][1])
    if not m:
        return None
    rest = w.parts[0][1][m.end():]
    parts = ([('lit', rest, 0)] if rest else []) + list(w.parts[1:])
    return m.group(1), ShW(parts, w.line)


def sh_parse(text):
    p = ShParser(text)
    return p.parse_list()


def sh_iter(node):
    """구문 나무의 모든 마디(명령 치환 속 포함)."""
    if node is None:
        return
    yield node
    k = node.kind
    kids = []
    if k == 'list':
        kids = node.items
    elif k in ('andor', 'pipe'):
        kids = node.items
    elif k == 'if':
        for c, b in node.clauses:
            kids.extend([c, b])
        if node.els is not None:
            kids.append(node.els)
    elif k == 'while':
        kids = [node.cond, node.body]
    elif k == 'for':
        kids = [node.body]
    elif k == 'case':
        kids = [b for _, b in node.arms]
    elif k == 'group':
        kids = [node.body]
    elif k == 'func':
        kids = [node.body]
    for kid in kids:
        for x in sh_iter(kid):
            yield x
    if k == 'simple' or k in ('group', 'while', 'for', 'if', 'case', 'arith'):
        ws = list(getattr(node, 'words', []))
        ws.extend(v for _, v, _ in getattr(node, 'assigns', []))
        ws.extend(w for _, _, w in getattr(node, 'redirs', []))
        ws.extend(getattr(node, 'herestr', []))
        if k == 'for':
            ws.extend(node.iter)
        if k == 'case':
            ws.append(node.word)
        for w in ws:
            for s in w.subs():
                for x in sh_iter(s):
                    yield x


SH_WRAP = {'sudo', 'doas', 'env', 'nohup', 'nice', 'ionice', 'timeout', 'stdbuf', 'command', 'exec', 'builtin',
           'time', 'chronic', 'xargs', 'setsid', 'flock', 'runuser'}
SH_WRAP_ARGOPT = {'-u', '-g', '-C', '-p', '-h', '-U', '-D', '-R', '-T', '-n', '-c', '-I', '-L', '-P', '-s', '-o',
                  '-i', '-E', '-k', '-w'}
DECL = {'local', 'declare', 'export', 'readonly', 'typeset'}
FILE_CMDS = {'cp', 'mv', 'rm', 'install', 'mkdir', 'rmdir', 'touch', 'ln', 'truncate', 'rsync', 'chmod', 'chown',
             'chgrp', 'shred', 'unlink', 'mkfifo', 'tee', 'dd', 'sed', 'perl', 'patch'}
DESTR_CMDS = {'rm', 'rmdir', 'unlink', 'shred'}
GIT_WRITE = {'add', 'commit', 'push', 'reset', 'checkout', 'switch', 'merge', 'rebase', 'rm', 'mv', 'apply', 'am',
             'cherry-pick', 'revert', 'update-ref', 'clean', 'restore', 'pull', 'gc', 'prune', 'init', 'clone'}
DB_CLIENTS = {'psql', 'mysql', 'mariadb', 'sqlite3'}


def effective_words(words):
    k = 0
    while k < len(words):
        lit = words[k].lit()
        if lit is None:
            break
        base = os.path.basename(lit)
        if base not in SH_WRAP:
            break
        k += 1
        while k < len(words):
            l2 = words[k].lit()
            if l2 is None:
                break
            if base == 'flock' and not l2.startswith('-'):
                k += 1       # 잠금 파일
                break
            if l2.startswith('-') or ASSIGN_RE.match(l2) or re.fullmatch(r'[0-9.]+[smhd]?', l2):
                if l2 in SH_WRAP_ARGOPT and base in ('sudo', 'doas', 'env', 'nice', 'xargs', 'runuser', 'ionice'):
                    k += 1
                k += 1
                continue
            break
    return words[k:]


class ShIR(object):
    """셸 구문 나무 → 중간 표현. funcs: 이 파일에서 부를 수 있는 셸 함수(이 파일 + source 한 파일)."""

    def __init__(self, rel, tree, ext_funcs, db_runners, exiting):
        self.rel = rel
        self.tree = tree
        self.ext_funcs = ext_funcs
        self.db_runners = db_runners
        self.exiting = exiting
        self.local_funcs = {}
        self.dry_vars = set()
        self.temp_vars = set()
        self.dry_seen = False
        self._scan()
        self.funcs = {}
        self.top = Block([self.stmt(x) for x in tree.items])

    def _scan(self):
        assigns = []
        loops = []          # (반복 변수, 반복 단어들) — 임시 자리 흐름 한 단계(FP-0005)
        self.lock_fds = set()
        self.dry_pairs = {}
        for nd in sh_iter(self.tree):
            if nd.kind == 'func':
                self.local_funcs[nd.name] = nd
            if nd.kind == 'simple':
                standalone = not nd.words
                pairs = [(name, val) for name, val, _ in nd.assigns]
                if nd.words and nd.words[0].lit() in DECL:
                    pairs.extend(sp for sp in (split_assign(w) for w in nd.words[1:]) if sp)
                    standalone = True
                for name, val in pairs:
                    assigns.append((name, val))
                    # dry 변수에 깃발 값(0·1·true…)이나 ${DRY_RUN:-0} 을 넣으면 = 이 스크립트가 dry-run 을 받는다
                    lv = val.lit()
                    if standalone and is_dry_name(name) and ((lv is not None and lv.strip().lower() in FLAGLIKE) or
                                                             val.single_var() == name):
                        self.dry_seen = True
                ew = effective_words(nd.words)
                if ew and ew[0].lit() in ('[', 'test', '[['):
                    if any(DRY_FLAG_RE.fullmatch((w.lit() or '').strip()) for w in ew[1:]):
                        self.dry_seen = True
                lits = [w.lit() or '' for w in nd.words]
                if 'flock' in lits:
                    for x in lits[lits.index('flock') + 1:]:
                        if re.fullmatch(r'[0-9]+', x):
                            self.lock_fds.add(x)
            if nd.kind == 'for' and nd.var and nd.iter:
                loops.append((nd.var, nd.iter))
            if nd.kind == 'case':
                for pats, body in nd.arms:
                    if any(DRY_FLAG_RE.fullmatch((p.lit() or '').strip()) for p in pats):
                        self.dry_seen = True
                        self._dry_pairs_from(body)
            if nd.kind == 'andor' and nd.ops[0] == '&&' and nd.items[0].kind == 'simple':
                ew = effective_words(nd.items[0].words)
                if ew and ew[0].lit() in ('[', 'test', '[[') and \
                        any(DRY_FLAG_RE.fullmatch((w.lit() or '').strip()) for w in ew[1:]):
                    self._dry_pairs_from(nd.items[1])
            if nd.kind == 'if':
                c0 = nd.clauses[0][0]
                if c0.items and c0.items[-1].kind == 'simple':
                    ew = effective_words(c0.items[-1].words)
                    if ew and ew[0].lit() in ('[', 'test', '[[') and \
                            any(DRY_FLAG_RE.fullmatch((w.lit() or '').strip()) for w in ew[1:]):
                        self._dry_pairs_from(nd.clauses[0][1])
        changed = True
        while changed:
            changed = False
            for name, val in assigns:
                if name in self.temp_vars:
                    continue
                if self.word_temp(val) or any(self._sub_mktemp(s) for s in val.subs()):
                    self.temp_vars.add(name)
                    changed = True
            # `for l in "$T"/nginx/sites-enabled/*` — 반복 단어가 전부 이번 실행의 임시 자리(mktemp 결과 아래)면
            # 반복 변수도 그 아래다. dry-run 참 갈래가 임시 사본을 만드는 준비(nginx -t 를 사본으로)가 이 모양이다
            for name, ws in loops:
                if name not in self.temp_vars and all(self.word_temp(w) for w in ws):
                    self.temp_vars.add(name)
                    changed = True

    def _dry_pairs_from(self, body):
        """dry-run 플래그를 받은 갈래에서 dry 아닌 이름의 변수에 넣는 글자 값 → 그 (변수, 값) 이 곧 dry-run."""
        for sub in sh_iter(body):
            if sub.kind == 'simple' and not sub.words:
                for name, val, _ in sub.assigns:
                    lv = val.lit()
                    if lv is not None and not is_dry_name(name):
                        self.dry_pairs.setdefault(name, set()).add(lv)

    def _sub_mktemp(self, sub):
        for nd in sh_iter(sub):
            if nd.kind == 'simple':
                ew = effective_words(nd.words)
                if ew and ew[0].lit() and os.path.basename(ew[0].lit()) in ('mktemp', 'tempfile'):
                    return True
        return False

    def word_temp(self, w):
        txt = w.text().lstrip('"\'')
        if TEMP_LIT_RE.match(txt):
            return True
        for p in w.parts:
            if p[0] == 'lit' and p[1] == '':
                continue
            if p[0] == 'var':
                return p[1] in self.temp_vars or p[1] in ('TMPDIR', 'TMP')
            if p[0] == 'sub':
                return self._sub_mktemp(p[1])
            return False
        return False

    def word_std(self, w):
        txt = w.text()
        return txt in STD_TARGETS or txt.startswith('/dev/fd/') or txt.startswith('/proc/self/fd/')

    # ── 조건 ──
    def cond_of(self, nd):
        if nd is None:
            return c_other()
        k = nd.kind
        if k == 'list':
            return self.cond_of(nd.items[-1]) if nd.items else c_other()
        if k == 'pipe':
            c = self.cond_of(nd.items[-1])
            return c_not(c) if nd.neg else c
        if k == 'andor':
            c = self.cond_of(nd.items[0])
            for op, it in zip(nd.ops, nd.items[1:]):
                c2 = self.cond_of(it)
                c = c_and([c, c2]) if op == '&&' else c_or([c, c2])
            return c
        if k == 'group':
            return self.cond_of(nd.body)
        if k == 'arith':
            return self.arith_cond(nd.text)
        if k == 'simple':
            return self.simple_cond(nd)
        return c_other()

    def arith_cond(self, text):
        m = re.fullmatch(r'\s*(!\s*)?\$?\{?([A-Za-z_][A-Za-z0-9_]*)\}?\s*(?:(==|!=|>|>=|<|<=)\s*([0-9]+))?\s*', text or '')
        if not m:
            return c_other()
        neg, name, op, val = m.group(1), m.group(2), m.group(3), m.group(4)
        pos = True
        if op:
            v = int(val)
            truth = {'==': v != 0, '!=': v == 0, '>': v >= 0, '>=': v >= 1, '<': v <= 0, '<=': v < 0}[op]
            pos = truth
        if neg:
            pos = not pos
        if is_dry_name(name):
            return c_dry(pos)
        return c_flag(name, pos)

    def simple_cond(self, nd):
        words = effective_words(nd.words)
        if not words:
            return c_other()
        w0 = words[0].lit()
        if w0 in ('[', 'test', '[['):
            args = words[1:]
            if args and args[-1].lit() in (']', ']]'):
                args = args[:-1]
            return self.test_cond(args)
        if w0 == 'true':
            return c_other()
        name = words[0].single_var()
        if name and len(words) == 1:
            if is_dry_name(name):
                return c_dry(True)
            return c_flag(name, True)
        return c_other()

    def test_cond(self, args):
        # -o / || 로 나눈 뒤 각각 -a / && 로 나눈다
        parts_or = []
        cur = []
        for a in args:
            if a.bare() in ('-o', '||'):
                parts_or.append(cur)
                cur = []
            else:
                cur.append(a)
        parts_or.append(cur)
        ors = []
        for seg in parts_or:
            ands, cur2 = [], []
            for a in seg:
                if a.bare() in ('-a', '&&'):
                    ands.append(cur2)
                    cur2 = []
                else:
                    cur2.append(a)
            ands.append(cur2)
            ors.append(c_and([self.test_atom(x) for x in ands]))
        return c_or(ors)

    def test_atom(self, args):
        neg = False
        while args and args[0].bare() == '!':
            neg = not neg
            args = args[1:]
        while args and args[0].bare() == '(' and args[-1].bare() == ')':
            args = args[1:-1]
        c = self._test_atom(args)
        return c_not(c) if neg else c

    def _test_atom(self, args):
        def mk(var, pos):
            if var is None:
                return c_other()
            if is_dry_name(var):
                return c_dry(pos)
            return c_flag(var, pos)

        if len(args) == 3 and args[1].lit() in ('=', '==', '!='):
            a, b = args[0], args[2]
            var, val = a.single_var(), b.lit()
            if var is None:
                var, val = b.single_var(), a.lit()
            if var in self.dry_pairs and val is not None:
                eq = args[1].lit() != '!='
                if val in self.dry_pairs[var]:
                    return c_dry(eq)
                if len(self.dry_pairs[var]) == 1 and eq:
                    return Cond(on=False, off=True, dnf=[[('dryoff',)]])   # MODE=check 이면 dry 가 아니다
                return c_other()

        if len(args) == 1:
            return mk(args[0].single_var(), True)
        if len(args) == 2 and args[0].bare() in ('-n', '-z'):
            return mk(args[1].single_var(), args[0].bare() == '-n')
        if len(args) == 3:
            a, op, b = args[0], args[1].lit(), args[2]
            var, val = a.single_var(), b.lit()
            if var is None:
                var, val = b.single_var(), a.lit()
            if var is None or val is None:
                return c_other()
            val = val.strip().lower()
            truthy = {'1', 'true', 'yes', 'y', 'on'}
            falsy = {'0', 'false', 'no', 'n', 'off', ''}
            if op in ('=', '==', '-eq'):
                if val in truthy:
                    return mk(var, True)
                if val in falsy:
                    return mk(var, False)
            elif op in ('!=', '-ne'):
                if val in truthy:
                    return mk(var, False)
                if val in falsy:
                    return mk(var, True)
            elif op in ('-gt', '-ge', '-lt', '-le') and re.fullmatch(r'-?[0-9]+', val):
                v = int(val)
                pos = {'-gt': v >= 0, '-ge': v >= 1, '-lt': not (v <= 0), '-le': not (v <= 0)}[op]
                if op in ('-lt', '-le'):
                    pos = False if (op == '-lt' and v <= 1) or (op == '-le' and v <= 0) else None
                    if pos is None:
                        return c_other()
                return mk(var, pos)
        return c_other()

    # ── 복합 조건 가드의 조각(v4) ──
    def conj_item(self, nd):
        """조건 명령 하나 → (글 열쇠, Cond, 변수 이름들). 열쇠는 리다이렉트·대입 없는 단순 명령만(아니면 None)."""
        key = None
        vs = set()
        if nd.kind == 'simple' and not nd.assigns and not nd.redirs and not nd.heredocs and not nd.herestr and nd.words:
            key = '\x1f'.join(w.text() for w in nd.words)
            for w in nd.words:
                vs.update(w.vars())
        return key, self.cond_of(nd), frozenset(vs)

    def conj_of(self, nd):
        """if 조건 목록 → 맨 위 && 조각들(마지막 명령이 판정 · && 만으로 이은 목록이면 조각마다)."""
        if nd is None:
            return []
        if nd.kind == 'list':
            if not nd.items:
                return []
            nd = nd.items[-1]
        if nd.kind == 'andor' and all(op == '&&' for op in nd.ops):
            return [self.conj_item(x) for x in nd.items]
        return [self.conj_item(nd)]

    # ── 문장 ──
    def block(self, lst, owner, role):
        items = lst.items if lst is not None and lst.kind == 'list' else ([lst] if lst is not None else [])
        return Block([self.stmt(x) for x in items], owner, role)

    def stmt(self, nd):
        k = nd.kind
        if k == 'list':
            s = Stmt('block', nd.line)
            s.children.append(self.block(nd, s, ('group',)))
            return s
        if k == 'andor':
            cur = self.stmt(nd.items[0])
            cond = self.cond_of(nd.items[0])
            sofar, all_and = [nd.items[0]], True
            for op, it in zip(nd.ops, nd.items[1:]):
                s = Stmt('if', cur.line)
                s.conds.append(Block([cur], s, ('cond', 0)))
                c = cond if op == '&&' else c_not(cond)
                s.branches.append((c, Block([self.stmt(it)], s, ('branch', 0))))
                all_and = all_and and op == '&&'
                if all_and:
                    s.bconj = [[self.conj_item(x) for x in sofar]]
                sofar.append(it)
                c2 = self.cond_of(it)
                cond = c_and([cond, c2]) if op == '&&' else c_or([cond, c2])
                cur = s
            return cur
        if k == 'pipe':
            s = Stmt('block', nd.line)
            s.children.append(Block([self.stmt(x) for x in nd.items], s, ('inline',)))
            return s
        if k == 'if':
            s = Stmt('if', nd.line)
            s.bconj = []
            for i, (c, b) in enumerate(nd.clauses):
                s.conds.append(self.block(c, s, ('cond', i)))
                s.branches.append((self.cond_of(c), self.block(b, s, ('branch', i))))
                s.bconj.append(self.conj_of(c))
            if nd.els is not None:
                s.els = self.block(nd.els, s, ('else',))
            self._redir_events(s, nd)
            return s
        if k == 'while':
            s = Stmt('block', nd.line)
            s.loop = True
            c = self.cond_of(nd.cond)
            s.loop_cond = c_not(c) if nd.until else c
            s.children.append(self.block(nd.cond, s, ('cond', 0)))
            s.children.append(self.block(nd.body, s, ('body',)))
            self._redir_events(s, nd)
            return s
        if k == 'for':
            s = Stmt('block', nd.line)
            s.loop = True
            if nd.var:
                s.sets.append((nd.var, True))
            s.children.append(self.block(nd.body, s, ('body',)))
            for w in nd.iter:
                for sub in w.subs():
                    s.children.append(self.block(sub, s, ('inline',)))
            self._redir_events(s, nd)
            return s
        if k == 'case':
            s = Stmt('block', nd.line)
            for _, b in nd.arms:
                s.children.append(self.block(b, s, ('arm',)))
            self._redir_events(s, nd)
            return s
        if k == 'group':
            s = Stmt('block', nd.line)
            s.children.append(self.block(nd.body, s, ('group',) if not nd.sub else ('inline',)))
            self._redir_events(s, nd)
            return s
        if k == 'func':
            s = Stmt('func', nd.line)
            s.name = nd.name
            s.body = Block([self.stmt(nd.body)] if nd.body is not None else [], s, ('body',))
            self.funcs[s.name] = s
            return s
        if k == 'arith':
            s = Stmt('simple', nd.line)
            for m in re.finditer(r'([A-Za-z_][A-Za-z0-9_]*)\s*(\+\+|--|[-+*/|&]?=(?!=))|(\+\+|--)\s*([A-Za-z_][A-Za-z0-9_]*)',
                                 nd.text or ''):
                name = m.group(1) or m.group(4)
                s.sets.append((name, True))
            return s
        return self.simple(nd)

    def _redir_events(self, s, nd):
        for op, fd, w in nd.redirs:
            ev = self._redir_event(op, w, nd.line, fd)
            if ev:
                s.events.append(ev)

    def _redir_event(self, op, w, line, fd=''):
        if fd and fd in self.lock_fds:
            return None     # flock 이 쓰는 잠금 자리
        if op in ('>', '>>', '>|', '&>', '&>>'):
            if self.word_std(w) or self.word_temp(w):
                return None
            txt = w.text()
            if re.fullmatch(r'[0-9-]+', txt):
                return None
            return Event('write', "리다이렉트 '%s %s'" % (op, shorten(txt)), line)
        return None

    def simple(self, nd):
        s = Stmt('simple', nd.line)
        # 속 명령 치환
        for w in list(nd.words) + [v for _, v, _ in nd.assigns] + [w for _, _, w in nd.redirs] + list(nd.herestr):
            for sub in w.subs():
                s.children.append(self.block(sub, s, ('inline',)))
        for name, val, _ in nd.assigns:
            if not nd.words:
                s.sets.append((name, not self._falsy(val)))
        for op, fd, w in nd.redirs:
            ev = self._redir_event(op, w, nd.line, fd)
            if ev:
                s.events.append(ev)
        words = effective_words(nd.words)
        if not words:
            return s
        name = words[0].lit()
        if name is None:
            return s
        base = os.path.basename(name)
        args = words[1:]
        if base in DECL:
            for w in args:
                sp = split_assign(w)
                if sp:
                    s.sets.append((sp[0], not self._falsy(sp[1])))
            return s
        if base == 'read':
            for w in args:
                lt = w.lit()
                if lt and re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', lt):
                    s.sets.append((lt, True))
            return s
        if base in ('exit', 'return', 'continue', 'break') and len(words) == len(nd.words):
            s.term = True
            return s
        if name in self.exiting and len(words) == len(nd.words):
            s.term = True
        ev = self.cmd_event(base, args, nd)
        if ev:
            s.events.append(ev)
        if name in self.local_funcs or name in self.ext_funcs:
            if name in self.db_runners and any(SQL_WRITE_RE.search(a.text()) for a in args):
                e = Event('write', '%s() 로 SQL 쓰기' % name, nd.line,
                          destr=any(SQL_DESTRUCT_RE.search(a.text()) for a in args))
                s.events.append(e)
            elif name in self.db_runners and self.sql_file_input(args, nd):
                s.events.append(Event('write', '%s() 로 SQL 파일 실행' % name, nd.line))
            else:
                s.events.append(Event('call', '%s()' % name, nd.line, callee=name))
        return s

    def _falsy(self, w):
        lt = w.lit()
        return lt is not None and lt.strip().lower() in ('', '0', 'false', 'no', 'off', 'n')

    def _nonopt(self, args):
        out, stop = [], False
        for a in args:
            lt = a.lit()
            if not stop and lt is not None:
                if lt == '--':
                    stop = True
                    continue
                if lt.startswith('-') and lt != '-':
                    continue
            out.append(a)
        return out

    def cmd_event(self, base, args, nd):
        line = nd.line
        if base in FILE_CMDS:
            if base in ('sed', 'perl'):
                if not any((a.lit() or '').startswith('-') and re.match(r'^-[a-zA-Z]*i', a.lit() or '') or
                           (a.lit() or '').startswith('--in-place') for a in args):
                    return None
                targets = self._nonopt(args)[-1:]
            elif base == 'tee':
                targets = [a for a in self._nonopt(args)]
                if not targets:
                    return None
            elif base == 'dd':
                targets = []
                for a in args:
                    t = a.text()
                    if t.startswith('of='):
                        targets.append(ShW([('lit', t[3:], 0)] + list(a.parts[1:]), a.line))
                if not targets:
                    return None
            elif base in ('cp', 'mv', 'install', 'ln', 'rsync'):
                if base == 'install' and any((a.lit() or '') == '-d' for a in args):
                    targets = self._nonopt(args)
                else:
                    targets = self._nonopt(args)[-1:]
                    if base == 'mv':
                        targets = self._nonopt(args)
            elif base == 'patch':
                targets = self._nonopt(args)[:1] or [ShW([('lit', '.', 0)], line)]
            else:
                targets = self._nonopt(args)
                if base in ('chmod', 'chown', 'chgrp'):
                    targets = targets[1:]
            targets = [t for t in targets if not self.word_std(t)]
            if not targets or all(self.word_temp(t) for t in targets):
                return None
            return Event('write', '%s %s' % (base, shorten(targets[-1].text())), line, destr=base in DESTR_CMDS)
        if base == 'git':
            k = 0
            repo = None
            while k < len(args):
                lt = args[k].lit()
                if lt in ('-C', '-c', '--git-dir', '--work-tree'):
                    if lt == '-C' and k + 1 < len(args):
                        repo = args[k + 1]
                    k += 2
                    continue
                if lt is not None and lt.startswith('-'):
                    k += 1
                    continue
                break
            if k >= len(args):
                return None
            sub = args[k].lit()
            rest = [a.lit() if a.lit() is not None else a.text() for a in args[k + 1:]]
            write = sub in GIT_WRITE
            if sub == 'tag':
                write = bool([r for r in rest if r and not r.startswith('-')]) and not any(
                    r in ('-l', '--list', '--contains', '--points-at', '-n', '--merged', '--no-merged', '-v', '--verify')
                    for r in rest)
            elif sub == 'branch':
                write = any(r in ('-d', '-D', '-m', '-M', '--delete', '--move', '-f', '--force', '-c', '-C')
                            for r in rest)
            elif sub == 'stash':
                write = not (rest and rest[0] in ('list', 'show'))
            elif sub == 'config':
                write = len([r for r in rest if r and not r.startswith('-')]) >= 2 and not any(
                    r in ('--get', '--get-all', '--list', '-l', '--get-regexp') for r in rest)
            elif sub in ('checkout', 'switch', 'restore', 'reset'):
                write = True
            elif sub == 'apply':
                write = not any(r in ('--check', '--stat', '--numstat', '--summary') for r in rest)
            if not write:
                return None
            if repo is not None and self.word_temp(repo):
                return None
            return Event('write', 'git %s' % sub, line, destr=sub in ('clean', 'reset', 'rm'))
        if base in DB_CLIENTS:
            texts = [a.text() for a in args] + [w.text() for w in nd.herestr] + [b for b, _, _ in nd.heredocs]
            hit = [t for t in texts if SQL_WRITE_RE.search(t)]
            if hit:
                return Event('write', '%s SQL 쓰기' % base, line, destr=any(SQL_DESTRUCT_RE.search(t) for t in hit))
            if self.sql_file_input(args, nd):
                return Event('write', '%s 로 SQL 파일 실행' % base, line)
        return None

    def sql_file_input(self, args, nd):
        """SQL 파일을 먹이는 실행(`psql < f.sql` · `-f f.sql`) — 마이그레이션·권한 반영."""
        for op, fd, w in nd.redirs:
            if op == '<' and (fd in ('', '0')) and not self.word_std(w) and not self.word_temp(w):
                return True
        for k, a in enumerate(args):
            lt = a.lit()
            if lt in ('-f', '--file') and k + 1 < len(args):
                t = args[k + 1]
                if not self.word_std(t) and not self.word_temp(t):
                    return True
            if lt is not None and lt.startswith('--file='):
                return True
        return False


def sh_func_index(rel, tree):
    """셸 파일의 함수: 이름 → (마디, 줄). db_runners: "$1"/"$@" 을 psql 류에 넘기는 함수. exiting: 몸 끝에 exit."""
    funcs, runners, exiting = {}, set(), set()
    for nd in sh_iter(tree):
        if nd.kind != 'func':
            continue
        funcs[nd.name] = nd
        for sub in sh_iter(nd.body):
            if sub.kind == 'simple':
                ew = effective_words(sub.words)
                if ew and ew[0].lit() and os.path.basename(ew[0].lit()) in DB_CLIENTS and \
                        any(p[0] == 'pos' for w in ew[1:] for p in w.parts):
                    runners.add(nd.name)
        body = nd.body
        items = body.body.items if body is not None and body.kind == 'group' else []
        for it in items:
            if it.kind == 'simple':
                ew = effective_words(it.words)
                if ew and ew[0].lit() == 'exit' and len(ew) == len(it.words):
                    exiting.add(nd.name)
    return funcs, runners, exiting


def sh_sourced(tree):
    """`. path` · `source path` 로 부르는 파일의 이름(끝 조각)."""
    out = set()
    for nd in sh_iter(tree):
        if nd.kind == 'simple' and nd.words and nd.words[0].lit() in ('.', 'source') and len(nd.words) > 1:
            txt = nd.words[1].text()
            out.add(os.path.basename(txt))
    return out


# ═══════════════════════════ PHP ═══════════════════════════════════════════
MULTI_OPS = ['<<=', '>>=', '**=', '...', '===', '!==', '<=>', '??=', '?->',
             '::', '->', '=>', '==', '!=', '<>', '<=', '>=', '&&', '||', '??', '++', '--',
             '+=', '-=', '*=', '/=', '.=', '%=', '&=', '|=', '^=', '<<', '>>', '**']
DQ_ESC = {'n': '\n', 't': '\t', 'r': '\r', 'v': '\v', 'f': '\f', '0': '\0', '"': '"', '\\': '\\', '$': '$', 'e': '\x1b'}


class Tok(object):
    __slots__ = ('kind', 'val', 'line')

    def __init__(self, kind, val, line):
        self.kind, self.val, self.line = kind, val, line

    def __repr__(self):
        return '%s:%r@%d' % (self.kind, self.val, self.line)


def lex_php(src):
    """PHP 원문 → 토막. kind: str · istr(보간) · var · ident · num · op. 주석·HTML 은 버린다."""
    toks = []
    i, n, line = 0, len(src), 1
    in_php = False
    while i < n:
        if not in_php:
            j = src.find('<?', i)
            if j < 0:
                break
            line += src.count('\n', i, j)
            i = j + (5 if src.startswith('<?php', j) else 3 if src.startswith('<?=', j) else 2)
            in_php = True
            continue
        c = src[i]
        if c == '\n':
            line += 1
            i += 1
            continue
        if c in ' \t\r\f\v':
            i += 1
            continue
        if src.startswith('?>', i):
            in_php = False
            toks.append(Tok('op', ';', line))
            i += 2
            continue
        if src.startswith('//', i) or (c == '#' and not src.startswith('#[', i)):
            j = i
            while j < n and src[j] != '\n' and not src.startswith('?>', j):
                j += 1
            i = j
            continue
        if src.startswith('#[', i):
            depth, j = 0, i + 1
            while j < n:
                if src[j] == '[':
                    depth += 1
                elif src[j] == ']':
                    depth -= 1
                    if depth == 0:
                        break
                j += 1
            line += src.count('\n', i, j)
            i = j + 1
            continue
        if src.startswith('/*', i):
            j = src.find('*/', i + 2)
            j = n if j < 0 else j + 2
            line += src.count('\n', i, j)
            i = j
            continue
        if src.startswith('<<<', i):
            m = re.match(r'<<<[ \t]*(["\']?)([A-Za-z_][A-Za-z0-9_]*)\1[ \t]*\r?\n', src[i:])
            if m:
                ident = m.group(2)
                body_start = i + m.end()
                cm = re.compile(r'^[ \t]*' + re.escape(ident) + r'\b', re.M).search(src, body_start)
                body_end, after = (n, n) if cm is None else (cm.start(), cm.end())
                body = src[body_start:body_end]
                interp = m.group(1) != "'" and re.search(r'\$[A-Za-z_{]|\{\$', body) is not None
                toks.append(Tok('istr' if interp else 'str', body, line))
                line += src.count('\n', i, after)
                i = after
                continue
        if c == "'":
            j, buf = i + 1, []
            while j < n:
                ch = src[j]
                if ch == '\\' and j + 1 < n and src[j + 1] in "\\'":
                    buf.append(src[j + 1])
                    j += 2
                    continue
                if ch == "'":
                    break
                buf.append(ch)
                j += 1
            toks.append(Tok('str', ''.join(buf), line))
            line += src.count('\n', i, j + 1)
            i = j + 1
            continue
        if c == '"' or c == '`':
            q, j, buf, interp = c, i + 1, [], False
            while j < n:
                ch = src[j]
                if ch == '\\' and j + 1 < n:
                    buf.append(DQ_ESC.get(src[j + 1], '\\' + src[j + 1]))
                    j += 2
                    continue
                if ch == q:
                    break
                if ch == '$' and j + 1 < n and (src[j + 1].isalpha() or src[j + 1] in '_{'):
                    interp = True
                if ch == '{' and j + 1 < n and src[j + 1] == '$':
                    interp = True
                buf.append(ch)
                j += 1
            toks.append(Tok('istr' if (interp or q == '`') else 'str', ''.join(buf), line))
            line += src.count('\n', i, j + 1)
            i = j + 1
            continue
        if c == '$' and i + 1 < n and (src[i + 1].isalpha() or src[i + 1] == '_' or ord(src[i + 1]) > 127):
            j = i + 1
            while j < n and (src[j].isalnum() or src[j] == '_' or ord(src[j]) > 127):
                j += 1
            toks.append(Tok('var', src[i + 1:j], line))
            i = j
            continue
        if c.isalpha() or c == '_' or c == '\\' or ord(c) > 127:
            j = i
            while j < n and (src[j].isalnum() or src[j] in '_\\' or ord(src[j]) > 127):
                j += 1
            toks.append(Tok('ident', src[i:j], line))
            i = j
            continue
        if c.isdigit():
            m = re.match(r'0[xX][0-9a-fA-F_]+|0[bB][01_]+|\d[\d_]*(?:\.\d+)?(?:[eE][+-]?\d+)?', src[i:])
            toks.append(Tok('num', m.group(0), line))
            i += m.end()
            continue
        for op in MULTI_OPS:
            if src.startswith(op, i):
                toks.append(Tok('op', op, line))
                i += len(op)
                break
        else:
            toks.append(Tok('op', c, line))
            i += 1
    return toks


def match_brackets(toks):
    pairs, stack = {}, []
    opener = {'(': ')', '[': ']', '{': '}'}
    for k, t in enumerate(toks):
        if t.kind != 'op':
            continue
        if t.val in opener:
            stack.append(k)
        elif t.val in (')', ']', '}'):
            while stack:
                o = stack.pop()
                if opener[toks[o].val] == t.val:
                    pairs[o] = k
                    break
    return pairs


def split_args(toks, lo, hi):
    out, depth, start = [], 0, lo
    for k in range(lo, hi):
        t = toks[k]
        if t.kind == 'op':
            if t.val in '([{':
                depth += 1
            elif t.val in ')]}':
                depth -= 1
            elif t.val == ',' and depth == 0:
                out.append((start, k))
                start = k + 1
    if start < hi:
        out.append((start, hi))
    return out


PHP_TERM = {'return', 'exit', 'die', 'continue', 'break', 'throw'}
PHP_FILE_W = {'file_put_contents': 0, 'rename': 1, 'unlink': 0, 'copy': 1, 'mkdir': 0, 'rmdir': 0, 'touch': 0,
              'chmod': 0, 'chown': 0, 'chgrp': 0, 'lchown': 0, 'lchgrp': 0, 'symlink': 1, 'link': 1,
              'move_uploaded_file': 1, 'tempnam': None, 'ftruncate': None}
PHP_DESTR = {'unlink', 'rmdir'}
PHP_HANDLE_W = {'fwrite', 'fputs', 'fputcsv', 'ftruncate'}
PHP_DB_METHODS = {'run', 'exec', 'query', 'prepare', 'execute', 'executequery', 'executestatement'}
PHP_DB_FUNCS = {'pg_query', 'pg_exec', 'pg_query_params', 'pg_prepare', 'mysqli_query', 'mysql_query', 'sqlite_exec'}
PHP_TEMP_FUNCS = {'sys_get_temp_dir', 'tempnam', 'tmpfile'}
PHP_STD_STREAM_RE = re.compile(r'^php://(?:stdout|stderr|output|memory|temp|stdin|fd/[12])')
PHP_KW_STMT = {'if', 'elseif', 'else', 'while', 'for', 'foreach', 'switch', 'do', 'try', 'catch', 'finally',
               'function', 'class', 'interface', 'trait', 'enum', 'abstract', 'final', 'readonly', 'namespace',
               'declare', 'use', 'match'}


class PhpIR(object):
    """PHP 토막 → 중간 표현."""

    def __init__(self, rel, text):
        self.rel = rel
        self.toks = lex_php(text)
        self.pairs = match_brackets(self.toks)
        self.funcs = {}
        self.classes = {}
        self.dry_seen = False
        self.temp_vars = set()
        self.fopen_vars = set()
        self.requires = []
        self.dry_consts = {}        # 상수 이름 → Cond (define('X', $dryRun) · const X = …; — dry-run 플래그에서 정해짐, v4)
        self._prescan()
        self.cur_class = None
        self.top = Block(self.parse_block(0, len(self.toks)))

    # ── 미리 훑기: dry 변수 · 임시 경로 변수 · fopen 손잡이 ──
    def _prescan(self):
        T = self.toks
        self.lock_vars = set()
        for k, t in enumerate(T):
            # 옵션·환경 변수 이름 글자('dry-run' · '--dry-run' · 'DRY_RUN')
            if t.kind == 'str' and DRY_FLAG_RE.fullmatch(t.val.strip()):
                self.dry_seen = True
            # 함수 매개변수 이름이 dry(`function f(bool $dryRun)`)
            if t.kind == 'ident' and t.val.lower() in ('function', 'fn'):
                j = k + 1
                while j < len(T) and not (T[j].kind == 'op' and T[j].val == '('):
                    j += 1
                    if j - k > 3:
                        break
                if j < len(T) and T[j].val == '(' and j in self.pairs:
                    if any(T[x].kind == 'var' and is_dry_name(T[x].val) for x in range(j + 1, self.pairs[j])):
                        self.dry_seen = True
            if t.kind == 'ident' and t.val.lower() == 'flock' and k + 2 < len(T) and T[k + 1].val == '(' and \
                    T[k + 2].kind == 'var':
                self.lock_vars.add(T[k + 2].val)
            if t.kind == 'ident' and t.val.lower() in ('require', 'require_once', 'include', 'include_once'):
                j = k + 1
                lits = []
                while j < len(T) and not (T[j].kind == 'op' and T[j].val == ';'):
                    if T[j].kind == 'str':
                        lits.append(T[j].val)
                    j += 1
                if lits:
                    self.requires.append(''.join(lits))
        changed = True
        assigns = []
        for k, t in enumerate(T):
            if t.kind == 'var' and k + 1 < len(T) and T[k + 1].kind == 'op' and T[k + 1].val == '=':
                j = k + 2
                depth = 0
                while j < len(T):
                    x = T[j]
                    if x.kind == 'op' and x.val in '([{':
                        depth += 1
                    elif x.kind == 'op' and x.val in ')]}':
                        depth -= 1
                        if depth < 0:
                            break
                    elif x.kind == 'op' and x.val in (';', ',') and depth == 0:
                        break
                    j += 1
                assigns.append((t.val, k + 2, j))
                if j > k + 2 and T[k + 2].kind == 'ident' and T[k + 2].val.lower() == 'fopen':
                    self.fopen_vars.add(t.val)
        # foreach (<임시 자리들> as [$k =>] $v) — 반복 대상이 전부 임시 자리면 $v 도 그 아래다(FP-0005 · 흐름 한 단계)
        loops = []
        for k, t in enumerate(T):
            if t.kind == 'ident' and t.val.lower() == 'foreach' and k + 1 < len(T) and T[k + 1].val == '(' and \
                    (k + 1) in self.pairs:
                lo, hi = k + 2, self.pairs[k + 1]
                as_at = next((j for j in range(lo, hi) if T[j].kind == 'ident' and T[j].val.lower() == 'as'), None)
                if as_at is not None and T[hi - 1].kind == 'var':
                    loops.append((T[hi - 1].val, lo, as_at))
        while changed:
            changed = False
            for name, lo, hi in assigns:
                if name not in self.temp_vars and self.range_temp(lo, hi):
                    self.temp_vars.add(name)
                    changed = True
            for name, lo, hi in loops:
                if name not in self.temp_vars and self.iter_temp(lo, hi):
                    self.temp_vars.add(name)
                    changed = True
        self._scan_dry_consts()

    def _scan_dry_consts(self):
        """dry-run 상수(v4): `define('X', <식>)` · 맨 위 `const X = <식>;` 에서 식이 dry-run 플래그로 정해지면(조건으로 읽어
        참이면 dry-run · 참이면 dry-run 아님) 그 상수를 dry-run 조건으로 읽는다. 앞의 상수를 쓰는 상수도 차례로."""
        T = self.toks
        depth = 0
        for k, t in enumerate(T):
            if t.kind == 'op' and t.val in '{':
                depth += 1
            elif t.kind == 'op' and t.val in '}':
                depth -= 1
            if t.kind != 'ident' or (k > 0 and T[k - 1].kind == 'op' and T[k - 1].val in ('->', '?->', '::')):
                continue
            name, rng = None, None
            if t.val.lower().lstrip('\\') == 'define' and k + 1 < len(T) and T[k + 1].val == '(':
                args = self._call_args(k) or []
                if len(args) >= 2 and args[0][1] - args[0][0] == 1 and T[args[0][0]].kind == 'str':
                    name, rng = T[args[0][0]].val, args[1]
            elif t.val.lower() == 'const' and depth == 0 and k + 2 < len(T) and T[k + 1].kind == 'ident' and \
                    T[k + 2].kind == 'op' and T[k + 2].val == '=':
                name, rng = T[k + 1].val, (k + 3, self.stmt_end(k + 3, len(T)))
            if not name or not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', name) or rng[0] >= rng[1]:
                continue
            c = self.cond(*rng)
            if c.on or c.off:
                self.dry_consts[name] = c

    def const_at(self, k):
        """k 의 낱말이 dry-run 상수(메서드·속성·함수 이름 아님)면 그 Cond."""
        T = self.toks
        t = T[k]
        if t.kind != 'ident' or t.val not in self.dry_consts:
            return None
        if k > 0 and T[k - 1].kind == 'op' and T[k - 1].val in ('->', '?->', '::'):
            return None
        if k + 1 < len(T) and T[k + 1].kind == 'op' and T[k + 1].val == '(':
            return None
        return self.dry_consts[t.val]

    def range_temp(self, lo, hi):
        T = self.toks
        for k in range(lo, min(hi, len(T))):
            t = T[k]
            if t.kind == 'ident' and t.val.lower().lstrip('\\') in PHP_TEMP_FUNCS:
                return True
            if t.kind in ('str', 'istr') and k == lo and (TEMP_LIT_RE.match(t.val) or PHP_STD_STREAM_RE.match(t.val)):
                return True
            if t.kind == 'istr' and k == lo and self.istr_temp(t.val):
                return True
            if t.kind == 'var' and k == lo and t.val in self.temp_vars:
                return True
        return False

    def istr_temp(self, val):
        """\"$t/x\" · \"{$t}.b\" — 끼운 글자가 임시 자리 변수로 시작한다."""
        m = re.match(r'^\{?\$([A-Za-z_][A-Za-z0-9_]*)', val)
        return bool(m) and m.group(1) in self.temp_vars

    def iter_temp(self, lo, hi):
        """foreach 반복 대상(lo..hi)이 전부 임시 자리인가 — 배열 글자면 원소마다, 아니면 식 하나로."""
        T = self.toks
        if lo < hi and T[lo].kind == 'op' and T[lo].val in ('[',) and self.pairs.get(lo) == hi - 1:
            elems = split_args(T, lo + 1, hi - 1)
            return bool(elems) and all(self.range_temp(a, b) for a, b in elems)
        if lo < hi and T[lo].kind == 'ident' and T[lo].val.lower() in ('glob', 'scandir') and \
                lo + 1 < hi and T[lo + 1].val == '(' and (lo + 1) in self.pairs:
            args = split_args(T, lo + 2, self.pairs[lo + 1])
            return bool(args) and self.range_temp(*args[0])
        return self.range_temp(lo, hi)

    def range_std(self, lo, hi):
        T = self.toks
        if lo < hi and T[lo].kind == 'ident' and T[lo].val.lstrip('\\') in ('STDOUT', 'STDERR'):
            return True
        if lo < hi and T[lo].kind in ('str', 'istr') and PHP_STD_STREAM_RE.match(T[lo].val):
            return True
        return False

    # ── 조건 ──
    def is_dry_tok(self, k):
        T = self.toks
        t = T[k]
        if t.kind == 'var' and is_dry_name(t.val):
            return True
        if t.kind == 'ident' and k > 0 and T[k - 1].kind == 'op' and T[k - 1].val in ('->', '?->', '::') and \
                is_dry_name(t.val):
            return True
        if t.kind == 'str' and DRY_LIT_RE.search(t.val) and len(t.val) < 40:
            return True
        c = self.const_at(k)
        if c is not None and c.on:
            return True
        return False

    def cond(self, lo, hi):
        T = self.toks
        # 바깥 괄호 벗기기
        while lo < hi and T[lo].kind == 'op' and T[lo].val == '(' and self.pairs.get(lo) == hi - 1:
            lo, hi = lo + 1, hi - 1
        ors = self._split(lo, hi, ('||',), ('or',))
        if len(ors) > 1:
            return c_or([self.cond(a, b) for a, b in ors])
        ands = self._split(lo, hi, ('&&',), ('and',))
        if len(ands) > 1:
            return c_and([self.cond(a, b) for a, b in ands])
        return self.atom(lo, hi)

    def _split(self, lo, hi, ops, words):
        T = self.toks
        out, depth, start = [], 0, lo
        for k in range(lo, hi):
            t = T[k]
            if t.kind == 'op' and t.val in '([{':
                depth += 1
            elif t.kind == 'op' and t.val in ')]}':
                depth -= 1
            elif depth == 0 and ((t.kind == 'op' and t.val in ops) or (t.kind == 'ident' and t.val.lower() in words)):
                out.append((start, k))
                start = k + 1
        out.append((start, hi))
        return [x for x in out if x[0] < x[1]]

    def atom(self, lo, hi):
        T = self.toks
        neg = False
        while lo < hi and T[lo].kind == 'op' and T[lo].val == '!':
            neg = not neg
            lo += 1
        while lo < hi and T[lo].kind == 'op' and T[lo].val == '(' and T[lo + 1:lo + 3] and \
                T[lo + 1].kind == 'ident' and T[lo + 1].val.lower() in ('bool', 'int', 'boolean', 'integer') and \
                T[lo + 2].kind == 'op' and T[lo + 2].val == ')':
            lo += 3
        if lo < hi and T[lo].kind == 'op' and T[lo].val == '(' and self.pairs.get(lo) == hi - 1:
            c = self.cond(lo + 1, hi - 1)
            return c_not(c) if neg else c
        # dry-run 상수 하나(`if (SCAN_DRY_RUN)` · `if (!LIVE_RUN)`) — 정의 식의 조건 그대로(v4)
        if hi - lo == 1 and self.const_at(lo) is not None:
            c = self.const_at(lo)
            return c_not(c) if neg else c
        dry = any(self.is_dry_tok(k) for k in range(lo, hi))
        # 비교
        cmp_at = None
        depth = 0
        for k in range(lo, hi):
            t = T[k]
            if t.kind == 'op' and t.val in '([{':
                depth += 1
            elif t.kind == 'op' and t.val in ')]}':
                depth -= 1
            elif depth == 0 and t.kind == 'op' and t.val in ('===', '==', '!==', '!=', '<>'):
                cmp_at = k
                break
        pos = True
        if cmp_at is not None:
            left, right = (lo, cmp_at), (cmp_at + 1, hi)
            other = right if any(self.is_dry_tok(k) for k in range(*left)) else left
            if other[1] - other[0] != 1:
                return c_other()
            v = T[other[0]]
            val = v.val.lower() if v.kind in ('ident', 'num') else ('' if v.kind == 'str' and v.val == '' else None)
            eq = T[cmp_at].val in ('===', '==')
            if val in ('false', '0', 'null', ''):
                pos = not eq
            elif val in ('true', '1'):
                pos = eq
            else:
                return c_other()
        elif lo < hi and T[lo].kind == 'ident' and T[lo].val.lower() == 'empty':
            pos = False
        if dry:
            return c_dry(pos != neg)
        # 깃발: $x · $this->x · self::$x (뒤에 다른 것 없이)
        name = self.flag_name(lo, hi)
        if name is not None and cmp_at is None:
            return c_flag(name, not neg)
        return c_other()

    def conj_of(self, lo, hi):
        """조건 식의 맨 위 && 조각들 → [(글 열쇠, Cond, 변수 이름들)] — 맨 위가 || 면 식 하나(복합 조건 가드, v4)."""
        T = self.toks

        def strip(a, b):
            while a < b and T[a].kind == 'op' and T[a].val == '(' and self.pairs.get(a) == b - 1:
                a, b = a + 1, b - 1
            return a, b
        lo, hi = strip(lo, hi)
        if lo >= hi:
            return []
        parts = [(lo, hi)] if len(self._split(lo, hi, ('||',), ('or',))) > 1 else \
            self._split(lo, hi, ('&&',), ('and',))
        out = []
        for a, b in parts:
            a, b = strip(a, b)
            if a >= b:
                continue
            key = '\x1f'.join('%s\x1e%s' % (T[k].kind, T[k].val) for k in range(a, b))
            vs = set()
            for k in range(a, b):
                if T[k].kind == 'var':
                    if T[k].val == 'this' and k + 2 < b and T[k + 1].val in ('->', '?->') and T[k + 2].kind == 'ident':
                        vs.add('$this->' + T[k + 2].val)
                    vs.add('$' + T[k].val)
                elif T[k].kind == 'istr':
                    vs.update('$' + m for m in re.findall(r'\$\{?([A-Za-z_][A-Za-z0-9_]*)', T[k].val))
            out.append((key, self.cond(a, b), frozenset(vs)))
        return out

    def flag_name(self, lo, hi):
        T = self.toks
        if hi - lo == 1 and T[lo].kind == 'var':
            return '$' + T[lo].val
        if hi - lo == 3 and T[lo].kind == 'var' and T[lo].val == 'this' and T[lo + 1].val in ('->', '?->') and \
                T[lo + 2].kind == 'ident':
            return '$this->' + T[lo + 2].val
        return None

    # ── 블록 · 문장 ──
    def parse_block(self, lo, hi):
        out = []
        k = lo
        while k < hi:
            st, nk = self.parse_stmt(k, hi)
            if nk <= k:
                nk = k + 1
            if st is not None:
                out.append(st)
            k = nk
        return out

    def body_at(self, k, hi, owner, role):
        """k 에서 시작하는 몸(블록 또는 한 문장) → (Block, 다음 k)."""
        T = self.toks
        if k < hi and T[k].kind == 'op' and T[k].val == '{' and k in self.pairs:
            e = self.pairs[k]
            return Block(self.parse_block(k + 1, e), owner, role), e + 1
        if k < hi and T[k].kind == 'op' and T[k].val == ':':
            # 다른 문법(if: … endif;)
            j = k + 1
            depth = 0
            while j < hi:
                t = T[j]
                if t.kind == 'op' and t.val in '([{':
                    depth += 1
                elif t.kind == 'op' and t.val in ')]}':
                    depth -= 1
                elif depth == 0 and t.kind == 'ident' and t.val.lower() in (
                        'elseif', 'else', 'endif', 'endwhile', 'endfor', 'endforeach', 'endswitch'):
                    break
                j += 1
            return Block(self.parse_block(k + 1, j), owner, role), j
        st, nk = self.parse_stmt(k, hi)
        return Block([st] if st is not None else [], owner, role), nk

    def stmt_end(self, k, hi):
        T = self.toks
        j = k
        while j < hi:
            t = T[j]
            if t.kind == 'op' and t.val in '([{' and j in self.pairs:
                j = self.pairs[j] + 1
                continue
            if t.kind == 'op' and t.val == ';':
                return j
            j += 1
        return hi

    def parse_stmt(self, k, hi):
        T = self.toks
        t = T[k]
        if t.kind == 'op' and t.val == ';':
            return None, k + 1
        if t.kind == 'op' and t.val == '{' and k in self.pairs:
            s = Stmt('block', t.line)
            s.children.append(Block(self.parse_block(k + 1, self.pairs[k]), s, ('group',)))
            return s, self.pairs[k] + 1
        if t.kind == 'ident':
            kw = t.val.lower()
            if kw == 'if':
                return self.parse_if(k, hi)
            if kw in ('while', 'for', 'foreach', 'switch', 'declare') and k + 1 < hi and T[k + 1].val == '(':
                pe = self.pairs.get(k + 1, k + 1)
                s = Stmt('block', t.line)
                s.loop = kw in ('while', 'for', 'foreach')
                if kw == 'while':
                    s.loop_cond = self.cond(k + 2, pe)
                if kw == 'foreach':
                    s.binds.extend(self._foreach_binds(k + 2, pe))
                s.children.append(self.inline_range(s, k + 2, pe))
                body, nk = self.body_at(pe + 1, hi, s, ('body',))
                s.children.append(body)
                nk = self._skip_alt_end(nk, hi)
                return s, nk
            if kw == 'do':
                s = Stmt('block', t.line)
                s.loop = True
                body, nk = self.body_at(k + 1, hi, s, ('body',))
                s.children.append(body)
                if nk < hi and T[nk].kind == 'ident' and T[nk].val.lower() == 'while':
                    nk = self.stmt_end(nk, hi) + 1
                return s, nk
            if kw == 'try':
                s = Stmt('block', t.line)
                body, nk = self.body_at(k + 1, hi, s, ('group',))
                s.children.append(body)
                while nk < hi and T[nk].kind == 'ident' and T[nk].val.lower() in ('catch', 'finally'):
                    j = nk + 1
                    if j < hi and T[j].val == '(':
                        j = self.pairs.get(j, j) + 1
                    b2, nk = self.body_at(j, hi, s, ('arm',))
                    s.children.append(b2)
                return s, nk
            if kw == 'function' and k + 1 < hi and T[k + 1].kind == 'ident':
                return self.parse_func(k, hi, T[k + 1].val)
            if kw == 'function' and k + 2 < hi and T[k + 1].val == '&' and T[k + 2].kind == 'ident':
                return self.parse_func(k + 1, hi, T[k + 2].val)
            if kw in ('abstract', 'final', 'readonly', 'class', 'interface', 'trait', 'enum') and \
                    self._class_start(k, hi) is not None:
                return self.parse_class(k, hi)
            if kw == 'namespace':
                j = k + 1
                while j < hi and not (T[j].kind == 'op' and T[j].val in (';', '{')):
                    j += 1
                if j < hi and T[j].val == '{' and j in self.pairs:
                    s = Stmt('block', t.line)
                    s.children.append(Block(self.parse_block(j + 1, self.pairs[j]), s, ('group',)))
                    return s, self.pairs[j] + 1
                return None, j + 1
            if kw == 'use' and self.cur_class is None:
                return None, self.stmt_end(k, hi) + 1
            if kw in ('public', 'private', 'protected', 'static', 'var', 'const') and self.cur_class is not None:
                j = k
                while j < hi and T[j].kind == 'ident' and T[j].val.lower() in (
                        'public', 'private', 'protected', 'static', 'readonly', 'final', 'abstract'):
                    j += 1
                if j < hi and T[j].kind == 'ident' and T[j].val.lower() == 'function':
                    nm = T[j + 1].val if j + 1 < hi and T[j + 1].kind == 'ident' else \
                        (T[j + 2].val if j + 2 < hi else '?')
                    return self.parse_func(j if T[j + 1].kind == 'ident' else j + 1, hi, nm)
                return None, self.stmt_end(k, hi) + 1
        e = self.stmt_end(k, hi)
        return self.simple(k, e), e + 1

    def _skip_alt_end(self, nk, hi):
        T = self.toks
        if nk < hi and T[nk].kind == 'ident' and T[nk].val.lower() in ('endwhile', 'endfor', 'endforeach',
                                                                        'endswitch', 'endif', 'enddeclare'):
            return self.stmt_end(nk, hi) + 1
        return nk

    def _class_start(self, k, hi):
        T = self.toks
        j = k
        while j < hi and T[j].kind == 'ident' and T[j].val.lower() in ('abstract', 'final', 'readonly'):
            j += 1
        if j < hi and T[j].kind == 'ident' and T[j].val.lower() in ('class', 'interface', 'trait', 'enum') and \
                j + 1 < hi and T[j + 1].kind == 'ident':
            return j
        return None

    def parse_class(self, k, hi):
        T = self.toks
        j = self._class_start(k, hi)
        name = T[j + 1].val
        b = j + 2
        while b < hi and not (T[b].kind == 'op' and T[b].val == '{'):
            b += 1
        if b >= hi or b not in self.pairs:
            return None, hi
        e = self.pairs[b]
        prev = self.cur_class
        self.cur_class = name
        s = Stmt('block', T[k].line)
        s.children.append(Block(self.parse_block(b + 1, e), s, ('class',)))
        self.cur_class = prev
        self.classes[name.lower()] = True
        return s, e + 1

    def parse_func(self, k, hi, name):
        T = self.toks
        j = k + 2
        if j < hi and T[j].val == '(':
            j = self.pairs.get(j, j) + 1
        while j < hi and not (T[j].kind == 'op' and T[j].val in ('{', ';')):
            j += 1
        s = Stmt('func', T[k].line)
        full = (self.cur_class + '::' + name) if self.cur_class else name
        s.name = full.lower()
        s.cls = self.cur_class
        if j < hi and T[j].val == '{' and j in self.pairs:
            e = self.pairs[j]
            s.body = Block(self.parse_block(j + 1, e), s, ('body',))
            self.funcs[s.name] = s
            return s, e + 1
        s.body = Block([], s, ('body',))
        return s, j + 1

    def parse_if(self, k, hi):
        T = self.toks
        s = Stmt('if', T[k].line)
        i = 0
        nk = k
        while True:
            # T[nk] 은 if / elseif
            p = nk + 1
            if p >= hi or T[p].val != '(':
                break
            pe = self.pairs.get(p, p)
            s.conds.append(self.inline_range(s, p + 1, pe, role=('cond', i)))
            c = self.cond(p + 1, pe)
            body, nk = self.body_at(pe + 1, hi, s, ('branch', i))
            s.branches.append((c, body))
            if s.bconj is None:
                s.bconj = []
            s.bconj.append(self.conj_of(p + 1, pe))
            i += 1
            if nk < hi and T[nk].kind == 'ident' and T[nk].val.lower() == 'elseif':
                continue
            if nk + 1 < hi and T[nk].kind == 'ident' and T[nk].val.lower() == 'else' and \
                    T[nk + 1].kind == 'ident' and T[nk + 1].val.lower() == 'if':
                nk += 1
                continue
            if nk < hi and T[nk].kind == 'ident' and T[nk].val.lower() == 'else':
                s.els, nk = self.body_at(nk + 1, hi, s, ('else',))
            break
        nk = self._skip_alt_end(nk, hi)
        return s, nk

    def _foreach_binds(self, lo, hi):
        """foreach 머리 `… as [$k =>] $v` · `as [$a, $b]` · `as list(…)` 가 묶는 변수 이름들."""
        T = self.toks
        as_at = next((j for j in range(lo, hi) if T[j].kind == 'ident' and T[j].val.lower() == 'as'), None)
        if as_at is None:
            return []
        return ['$' + T[j].val for j in range(as_at + 1, hi) if T[j].kind == 'var']

    def inline_range(self, owner, lo, hi, role=('inline',)):
        """조건·머리 괄호 안 식 → 인라인 블록(속 호출을 사건으로)."""
        st = self.simple(lo, hi)
        return Block([st] if st is not None else [], owner, role)

    # ── 단순 문장 ──
    def simple(self, lo, hi):
        T = self.toks
        if lo >= hi:
            return None
        s = Stmt('simple', T[lo].line)
        masked = set()
        # 클로저(function (…) use (…) { … }) — 이름 붙은 것은 함수로, 나머지는 인라인
        k = lo
        named = None
        if T[lo].kind == 'var' and lo + 1 < hi and T[lo + 1].val == '=':
            named = '$' + T[lo].val
        while k < hi:
            t = T[k]
            if t.kind == 'ident' and t.val.lower() == 'function' and k + 1 < hi and \
                    (T[k + 1].val == '(' or (T[k + 1].val == '&' and k + 2 < hi and T[k + 2].val == '(')):
                p = k + 1 if T[k + 1].val == '(' else k + 2
                j = self.pairs.get(p, p) + 1
                while j < hi and not (T[j].kind == 'op' and T[j].val == '{'):
                    j += 1
                if j < hi and j in self.pairs:
                    e = self.pairs[j]
                    for x in range(k, e + 1):
                        masked.add(x)
                    lead = lo + 2
                    while lead < hi and T[lead].kind == 'ident' and T[lead].val.lower() == 'static':
                        lead += 1
                    if named is not None and k == lead:
                        f = Stmt('func', t.line)
                        f.name = named
                        f.cls = self.cur_class
                        f.body = Block(self.parse_block(j + 1, e), f, ('body',))
                        self.funcs[named] = f
                        s.children.append(Block([f], s, ('inline',)))
                    else:
                        s.children.append(Block(self.parse_block(j + 1, e), s, ('inline',)))
                    k = e + 1
                    continue
            k += 1
        # 끝내기
        if T[lo].kind == 'ident' and T[lo].val.lower() in PHP_TERM:
            s.term = True
        # 대입(깃발)
        self._sets(s, lo, hi, masked)
        # 사건
        for k in range(lo, hi):
            if k in masked:
                continue
            ev = self._event(k, lo, hi)
            if ev is not None:
                ev.eguard = self.expr_guard(lo, hi, k)
                s.events.append(ev)
        return s

    def _sets(self, s, lo, hi, masked):
        T = self.toks
        for k in range(lo, hi):
            if k in masked:
                continue
            name = None
            nk = k
            if T[k].kind == 'var' and T[k].val == 'this' and k + 2 < hi and T[k + 1].val in ('->', '?->') and \
                    T[k + 2].kind == 'ident':
                name, nk = '$this->' + T[k + 2].val, k + 3
            elif T[k].kind == 'var':
                if k > lo and T[k - 1].kind == 'op' and T[k - 1].val in ('->', '::'):
                    continue
                name, nk = '$' + T[k].val, k + 1
            else:
                continue
            if nk < hi and T[nk].kind == 'op' and T[nk].val == '=':
                e = nk + 1
                depth = 0
                j = e
                while j < hi:
                    x = T[j]
                    if x.kind == 'op' and x.val in '([{':
                        depth += 1
                    elif x.kind == 'op' and x.val in ')]}':
                        depth -= 1
                        if depth < 0:
                            break
                    elif depth == 0 and x.kind == 'op' and x.val in (',', ';'):
                        break
                    j += 1
                falsy = j - e == 1 and ((T[e].kind == 'ident' and T[e].val.lower() in ('false', 'null')) or
                                        (T[e].kind == 'num' and T[e].val == '0') or
                                        (T[e].kind == 'str' and T[e].val in ('', '0')))
                if j - e == 2 and T[e].val == '[' and T[e + 1].val == ']':
                    falsy = True
                s.sets.append((name, not falsy))
            elif nk < hi and T[nk].kind == 'op' and T[nk].val in ('|=', '+=', '++', '.=', '??=', '-=', '--'):
                s.sets.append((name, True))
            elif k > lo and T[k - 1].kind == 'op' and T[k - 1].val in ('++', '--'):
                s.sets.append((name, True))

    def _call_args(self, k):
        T = self.toks
        if k + 1 >= len(T) or T[k + 1].val != '(':
            return None
        e = self.pairs.get(k + 1)
        if e is None:
            return None
        return split_args(T, k + 2, e)

    def _event(self, k, lo, hi):
        T = self.toks
        t = T[k]
        if t.kind == 'var' and k + 1 < hi and T[k + 1].val == '(' and not (k > lo and T[k - 1].val in ('->', '::')):
            return Event('call', '$%s()' % t.val, t.line, callee='$' + t.val)
        if t.kind != 'ident' or k + 1 >= hi or T[k + 1].val != '(':
            if t.kind == 'ident' and t.val.lower() == 'new' and k + 1 < hi and T[k + 1].kind == 'ident' and \
                    T[k + 1].val.lstrip('\\').lower() == 'splfileobject':
                args = self._call_args(k + 1) or []
                if len(args) >= 2 and self._mode_writes(args[1]) and not self.range_temp(*args[0]):
                    return Event('write', 'new SplFileObject(쓰기)', t.line)
            return None
        prev = T[k - 1] if k > 0 else None
        name = t.val.lstrip('\\')
        lname = name.lower()
        if prev is not None and prev.kind == 'ident' and prev.val.lower() in ('function', 'new', 'fn'):
            return None
        args = self._call_args(k) or []
        if prev is not None and prev.kind == 'op' and prev.val in ('->', '?->'):
            if lname in PHP_DB_METHODS and args:
                a0 = args[0]
                if self._range_sql_write(*a0):
                    return self._db_event(name + '()', a0, t.line)
            if k >= 2 and T[k - 2].kind == 'var' and T[k - 2].val == 'this' and self.cur_class:
                return Event('call', '%s()' % name, t.line, callee=(self.cur_class + '::' + name).lower())
            return None
        if prev is not None and prev.kind == 'op' and prev.val == '::':
            cls = T[k - 2].val.lstrip('\\') if k >= 2 and T[k - 2].kind == 'ident' else None
            if cls is None:
                return None
            if cls.lower() in ('self', 'static', 'parent'):
                cls = self.cur_class or cls
            cls = cls.rsplit('\\', 1)[-1]
            return Event('call', '%s::%s()' % (cls, name), t.line, callee=(cls + '::' + name).lower())
        if lname in PHP_KW_STMT or lname in ('isset', 'empty', 'array', 'list', 'unset', 'eval', 'exit', 'die',
                                             'echo', 'print', 'return'):
            return None
        if lname in PHP_FILE_W and PHP_FILE_W[lname] is not None:
            ai = PHP_FILE_W[lname]
            if not args:
                return None
            tgt = args[min(ai, len(args) - 1)]
            if self.range_temp(*tgt) or self.range_std(*tgt):
                if lname != 'rename' or self.range_temp(*args[0]):
                    return None
            return Event('write', '%s()' % name, t.line, destr=lname in PHP_DESTR)
        if lname == 'fopen' and len(args) >= 2:
            if lo + 2 <= k and T[lo].kind == 'var' and T[lo].val in self.lock_vars:
                return None     # flock 잠금 파일
            if self._mode_writes(args[1]) and not self.range_temp(*args[0]) and not self.range_std(*args[0]):
                return Event('write', 'fopen(쓰기 모드)', t.line)
            return None
        if lname in PHP_HANDLE_W and args:
            a0 = args[0]
            if self.range_std(*a0):
                return None
            if a0[1] - a0[0] == 1 and T[a0[0]].kind == 'var' and (T[a0[0]].val in self.fopen_vars or
                                                                   T[a0[0]].val in self.temp_vars):
                return None
            if a0[1] - a0[0] >= 2 and T[a0[0]].kind == 'var' and T[a0[0] + 1].val == '[':
                return None     # proc_open 파이프 등
            return Event('write', '%s()' % name, t.line)
        if lname in PHP_DB_FUNCS and args:
            for a in args:
                if self._range_sql_write(*a):
                    return self._db_event(name + '()', a, t.line)
            return None
        return Event('call', '%s()' % name, t.line, callee=lname)

    def _mode_writes(self, rng):
        T = self.toks
        lo, hi = rng
        if hi - lo == 1 and T[lo].kind in ('str', 'istr'):
            return bool(re.search(r'[waxc+]', T[lo].val))
        return True

    def _range_sql_write(self, lo, hi):
        T = self.toks
        for k in range(lo, hi):
            if T[k].kind in ('str', 'istr') and SQL_WRITE_RE.search(T[k].val):
                return True
        return False

    def _db_event(self, what, rng, line):
        T = self.toks
        txt = ' '.join(T[k].val for k in range(*rng) if T[k].kind in ('str', 'istr'))
        return Event('write', '%s 로 SQL 쓰기' % what, line, destr=bool(SQL_DESTRUCT_RE.search(txt)))

    def expr_guard(self, lo, hi, p):
        """식 안의 dry-run 막음: A || w() · A && w() · A ? … : w() (괄호·인자 안으로 내려가며)."""
        T = self.toks
        while True:
            # 앞의 대입 · return · echo 벗기기
            k = lo
            if k + 1 < hi and T[k].kind == 'var' and T[k + 1].kind == 'op' and T[k + 1].val in ('=', '??='):
                k += 2
            if k < hi and T[k].kind == 'ident' and T[k].val.lower() in ('return', 'echo', 'print'):
                k += 1
            lo2 = k
            depth = 0
            q = None
            ops = []
            for j in range(lo2, hi):
                t = T[j]
                if t.kind == 'op' and t.val in '([{':
                    depth += 1
                elif t.kind == 'op' and t.val in ')]}':
                    depth -= 1
                elif depth == 0 and ((t.kind == 'op' and t.val in ('||', '&&', '?', ':', '??')) or
                                     (t.kind == 'ident' and t.val.lower() in ('or', 'and'))):
                    ops.append(j)
            before = [j for j in ops if j < p]
            if before:
                # 가장 먼저 나온 ? 가 있으면 삼항
                qs = [j for j in ops if T[j].val == '?']
                if qs and qs[0] < p:
                    q = qs[0]
                    colon = None
                    lvl = 0
                    for j in ops:
                        if j <= q:
                            continue
                        if T[j].val == '?':
                            lvl += 1
                        elif T[j].val == ':':
                            if lvl == 0:
                                colon = j
                                break
                            lvl -= 1
                    c = self.cond(lo2, q)
                    if colon is not None and p > colon and c.on:
                        return True
                    if colon is not None and q < p < colon and c.off:
                        return True
                else:
                    j = before[-1]
                    op = T[j].val.lower()
                    c = self.cond(lo2, j)
                    if op in ('||', 'or') and c.on:
                        return True
                    if op in ('&&', 'and') and c.off:
                        return True
            # p 를 감싸는 괄호 한 겹 안으로
            inner = None
            j = lo2
            while j < hi:
                t = T[j]
                if t.kind == 'op' and t.val in '([' and j in self.pairs:
                    e = self.pairs[j]
                    if j < p < e:
                        inner = (j, e)
                        break
                    j = e + 1
                    continue
                j += 1
            if inner is None:
                return False
            a, b = inner
            for x, y in split_args(T, a + 1, b):
                if x <= p < y:
                    lo, hi = x, y
                    break
            else:
                return False


# ═══════════════════════════ 파이썬 ════════════════════════════════════════
PY_WRITE_ATTR = {'write_text', 'write_bytes', 'touch', 'mkdir', 'unlink', 'rename', 'replace', 'rmdir',
                 'symlink_to', 'hardlink_to', 'link_to'}
PY_OS_W = {'remove', 'unlink', 'rename', 'replace', 'makedirs', 'mkdir', 'rmdir', 'removedirs', 'chmod', 'chown',
           'symlink', 'link', 'truncate', 'utime', 'mkfifo'}
PY_SHUTIL_W = {'copy', 'copy2', 'copyfile', 'copytree', 'copymode', 'copystat', 'move', 'rmtree', 'chown'}
PY_DESTR = {'remove', 'unlink', 'rmdir', 'removedirs', 'rmtree'}


def _dotted(node):
    parts = []
    while isinstance(node, ast.Attribute):
        parts.append(node.attr)
        node = node.value
    if isinstance(node, ast.Name):
        parts.append(node.id)
        return '.'.join(reversed(parts))
    return None


class PyIR(object):
    def __init__(self, rel, text):
        self.rel = rel
        self.tree = ast.parse(text)
        self.funcs = {}
        self.dry_seen = False
        self.temp_vars = set()
        for n in ast.walk(self.tree):
            if isinstance(n, ast.arg) and is_dry_name(n.arg):
                self.dry_seen = True
            elif isinstance(n, ast.Constant) and isinstance(n.value, str) and DRY_FLAG_RE.fullmatch(n.value.strip()) \
                    and (n.value.startswith('-') or n.value.isupper()):
                self.dry_seen = True     # '--dry-run'(argparse·sys.argv) · 'DRY_RUN'(환경 변수)
        changed = True
        while changed:
            changed = False
            for n in ast.walk(self.tree):
                if isinstance(n, ast.Assign) and len(n.targets) == 1 and isinstance(n.targets[0], ast.Name):
                    nm = n.targets[0].id
                    if nm not in self.temp_vars and self.expr_temp(n.value):
                        self.temp_vars.add(nm)
                        changed = True
                if isinstance(n, ast.With):
                    for it in n.items:
                        if isinstance(it.optional_vars, ast.Name) and self.expr_temp(it.context_expr) and \
                                it.optional_vars.id not in self.temp_vars:
                            self.temp_vars.add(it.optional_vars.id)
                            changed = True
        self.top = Block(self.stmts(self.tree.body))

    def expr_temp(self, e):
        for n in ast.walk(e):
            if isinstance(n, ast.Call):
                d = _dotted(n.func) or ''
                if d.startswith('tempfile.') or d in ('mkdtemp', 'mkstemp', 'NamedTemporaryFile',
                                                      'TemporaryDirectory', 'gettempdir'):
                    return True
            if isinstance(n, ast.Constant) and isinstance(n.value, str) and TEMP_LIT_RE.match(n.value):
                return True
            if isinstance(n, ast.Name) and n.id in self.temp_vars:
                return True
        return False

    def cond(self, e):
        if isinstance(e, ast.BoolOp):
            cs = [self.cond(v) for v in e.values]
            return c_and(cs) if isinstance(e.op, ast.And) else c_or(cs)
        if isinstance(e, ast.UnaryOp) and isinstance(e.op, ast.Not):
            return c_not(self.cond(e.operand))
        if isinstance(e, ast.Compare) and len(e.ops) == 1:
            other = e.comparators[0]
            left = e.left
            if isinstance(other, ast.Constant) and other.value in (True, False, None, 0, 1) and self._is_dry(left):
                eq = isinstance(e.ops[0], (ast.Is, ast.Eq))
                truthy = other.value in (True, 1)
                return c_dry(truthy == eq)
            return c_other()
        if self._is_dry(e):
            return c_dry(True)
        if isinstance(e, ast.Call) and _dotted(e.func) in ('bool',) and e.args:
            return self.cond(e.args[0])
        if isinstance(e, ast.Name):
            return c_flag(e.id, True)
        if isinstance(e, ast.Attribute) and isinstance(e.value, ast.Name) and e.value.id == 'self':
            return c_flag('self.' + e.attr, True)
        return c_other()

    def conj_of(self, e):
        """if 조건 → 맨 위 and 조각들 [(글 열쇠, Cond, 변수 이름들)] (복합 조건 가드, v4)."""
        vals = e.values if isinstance(e, ast.BoolOp) and isinstance(e.op, ast.And) else [e]
        out = []
        for v in vals:
            vs = set()
            for n in ast.walk(v):
                if isinstance(n, ast.Name):
                    vs.add(n.id)
                elif isinstance(n, ast.Attribute) and isinstance(n.value, ast.Name) and n.value.id == 'self':
                    vs.add('self.' + n.attr)
            out.append((ast.dump(v), self.cond(v), frozenset(vs)))
        return out

    def _is_dry(self, e):
        if isinstance(e, ast.Name):
            return is_dry_name(e.id)
        if isinstance(e, ast.Attribute):
            return is_dry_name(e.attr)
        if isinstance(e, ast.Subscript):
            s = e.slice
            if isinstance(s, ast.Index):     # 3.8 이하
                s = s.value
            return isinstance(s, ast.Constant) and isinstance(s.value, str) and bool(DRY_LIT_RE.search(s.value))
        if isinstance(e, ast.Call) and isinstance(e.func, ast.Attribute) and e.func.attr == 'get' and e.args:
            a = e.args[0]
            return isinstance(a, ast.Constant) and isinstance(a.value, str) and bool(
                DRY_LIT_RE.search(a.value) or is_dry_name(a.value))
        return False

    def stmts(self, body, owner=None, role=None):
        return [self.stmt(s) for s in body]

    def stmt(self, n):
        line = getattr(n, 'lineno', 0)
        if isinstance(n, ast.If):
            s = Stmt('if', line)
            branches = []
            cur = n
            i = 0
            s.bconj = []
            while True:
                st = self.simple_expr(cur.test, getattr(cur, 'lineno', line))
                s.conds.append(Block([st], s, ('cond', i)))
                s.branches.append((self.cond(cur.test), Block(self.stmts(cur.body), s, ('branch', i))))
                s.bconj.append(self.conj_of(cur.test))
                i += 1
                if len(cur.orelse) == 1 and isinstance(cur.orelse[0], ast.If):
                    cur = cur.orelse[0]
                    continue
                if cur.orelse:
                    s.els = Block(self.stmts(cur.orelse), s, ('else',))
                break
            return s
        if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef)):
            s = Stmt('func', line)
            s.name = n.name
            s.body = Block(self.stmts(n.body), s, ('body',))
            self.funcs[n.name] = s
            return s
        if isinstance(n, ast.ClassDef):
            s = Stmt('block', line)
            s.children.append(Block(self.stmts(n.body), s, ('class',)))
            return s
        if isinstance(n, (ast.While,)):
            s = Stmt('block', line)
            s.loop = True
            s.loop_cond = self.cond(n.test)
            s.children.append(Block([self.simple_expr(n.test, line)], s, ('cond', 0)))
            s.children.append(Block(self.stmts(n.body), s, ('body',)))
            s.children.append(Block(self.stmts(n.orelse), s, ('arm',)))
            return s
        if isinstance(n, (ast.For, ast.AsyncFor, ast.With, ast.AsyncWith, ast.Try)):
            s = Stmt('block', line)
            head = []
            if isinstance(n, (ast.For, ast.AsyncFor)):
                head.append(n.iter)
                s.loop = True
                if isinstance(n.target, ast.Name):
                    s.sets.append((n.target.id, True))
                else:
                    s.binds.extend(x.id for x in ast.walk(n.target) if isinstance(x, ast.Name))
            if isinstance(n, (ast.With, ast.AsyncWith)):
                head.extend(it.context_expr for it in n.items)
            for h in head:
                s.children.append(Block([self.simple_expr(h, line)], s, ('inline',)))
            s.children.append(Block(self.stmts(n.body), s, ('group',) if isinstance(n, (ast.With, ast.AsyncWith))
                                    else ('body',)))
            for extra in ('orelse', 'finalbody'):
                if getattr(n, extra, None):
                    s.children.append(Block(self.stmts(getattr(n, extra)), s, ('arm',)))
            for h in getattr(n, 'handlers', []) or []:
                s.children.append(Block(self.stmts(h.body), s, ('arm',)))
            return s
        s = self.simple_expr(n, line)
        if isinstance(n, (ast.Return, ast.Raise, ast.Continue, ast.Break)):
            s.term = True
        if isinstance(n, ast.Expr) and isinstance(n.value, ast.Call) and \
                (_dotted(n.value.func) or '') in ('sys.exit', 'exit', 'quit', 'os._exit'):
            s.term = True
        if isinstance(n, (ast.Assign, ast.AugAssign, ast.AnnAssign)):
            tg = n.targets if isinstance(n, ast.Assign) else [n.target]
            val = n.value
            falsy = isinstance(val, ast.Constant) and val.value in (False, None, 0, '')
            for t in tg:
                nm = t.id if isinstance(t, ast.Name) else (
                    'self.' + t.attr if isinstance(t, ast.Attribute) and isinstance(t.value, ast.Name) and
                    t.value.id == 'self' else None)
                if nm:
                    s.sets.append((nm, not falsy or isinstance(n, ast.AugAssign)))
        return s

    def simple_expr(self, n, line):
        s = Stmt('simple', line)
        if n is None:
            return s
        for c in ast.walk(n):
            if isinstance(c, (ast.Lambda,)):
                continue
            if isinstance(c, ast.Call):
                ev = self.call_event(c)
                if ev is not None:
                    ev.eguard = self.py_eguard(n, c)
                    s.events.append(ev)
        return s

    def py_eguard(self, root, call):
        for c in ast.walk(root):
            if isinstance(c, ast.IfExp):
                if self._contains(c.orelse, call) and self.cond(c.test).on:
                    return True
                if self._contains(c.body, call) and self.cond(c.test).off:
                    return True
            if isinstance(c, ast.BoolOp):
                for k, v in enumerate(c.values[1:], 1):
                    if self._contains(v, call):
                        lead = c_or([self.cond(x) for x in c.values[:k]]) if isinstance(c.op, ast.Or) else \
                            c_and([self.cond(x) for x in c.values[:k]])
                        if isinstance(c.op, ast.Or) and lead.on:
                            return True
                        if isinstance(c.op, ast.And) and lead.off:
                            return True
        return False

    def _contains(self, tree, node):
        return any(x is node for x in ast.walk(tree))

    def call_event(self, c):
        d = _dotted(c.func) or ''
        line = getattr(c, 'lineno', 0)
        if d == 'open' or d.endswith('.open') and d.split('.')[0] in ('io', 'codecs'):
            mode = None
            if len(c.args) >= 2 and isinstance(c.args[1], ast.Constant):
                mode = c.args[1].value
            for kw in c.keywords:
                if kw.arg == 'mode' and isinstance(kw.value, ast.Constant):
                    mode = kw.value.value
            if isinstance(mode, str) and re.search(r'[wax+]', mode):
                if c.args and self.expr_temp(c.args[0]):
                    return None
                if c.args and isinstance(c.args[0], ast.Constant) and c.args[0].value in STD_TARGETS:
                    return None
                return Event('write', 'open(%r)' % mode, line)
            return None
        if isinstance(c.func, ast.Attribute):
            attr = c.func.attr
            base = _dotted(c.func.value) or ''
            if base == 'os' and attr in PY_OS_W or base == 'shutil' and attr in PY_SHUTIL_W:
                tgt = c.args[-1] if c.args else None
                if tgt is not None and self.expr_temp(tgt):
                    return None
                return Event('write', '%s.%s()' % (base, attr), line, destr=attr in PY_DESTR)
            if attr in PY_WRITE_ATTR:
                if self.expr_temp(c.func.value):
                    return None
                if base in ('os', 'shutil', 're', 'str', 'dict'):
                    return None
                npos = len(c.args)
                # str.replace(a, b) 와 Path.replace(target) 를 인자 수로 가른다
                if attr in ('replace', 'rename', 'symlink_to', 'hardlink_to', 'link_to') and npos != 1:
                    return None
                if attr in ('unlink', 'rmdir', 'touch') and npos != 0:
                    return None
                if attr in ('write_text', 'write_bytes') and npos < 1:
                    return None
                return Event('write', '.%s()' % attr, line, destr=attr in ('unlink', 'rmdir'))
            if attr in ('execute', 'executemany', 'executescript') and c.args and \
                    isinstance(c.args[0], ast.Constant) and isinstance(c.args[0].value, str) and \
                    SQL_WRITE_RE.search(c.args[0].value):
                return Event('write', '%s() 로 SQL 쓰기' % attr, line,
                             destr=bool(SQL_DESTRUCT_RE.search(c.args[0].value)))
            return None
        if isinstance(c.func, ast.Name):
            return Event('call', '%s()' % c.func.id, line, callee=c.func.id)
        return None


# ═══════════════════════════ 파일 · 사전 ═══════════════════════════════════
def file_lang(rel, head):
    low = rel.lower()
    first = head.split('\n', 1)[0]
    if low.endswith(('.sh', '.bash')):
        return 'sh'
    if low.endswith(('.php', '.phtml')):
        return 'php'
    if low.endswith('.py'):
        return 'py'
    if first.startswith('#!'):
        if re.search(r'\b(ba|da|k|z)?sh\b', first):
            return 'sh'
        if 'php' in first:
            return 'php'
        if 'python' in first:
            return 'py'
    return None


def list_files(root, use_git):
    if use_git:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, '-c', 'core.quotepath=off', 'ls-files', '-z', '--cached', '--others',
                            '--exclude-standard'], stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=300)
        if r.returncode != 0:
            raise RuntimeError('git ls-files 실패: ' + r.stderr.decode('utf-8', 'replace').strip())
        return sorted(set(os.fsdecode(p) for p in r.stdout.split(b'\0') if p))
    out = []
    for d, dirs, files in os.walk(root):
        dirs[:] = sorted(x for x in dirs if x != '.git')
        for f in sorted(files):
            out.append(os.path.relpath(os.path.join(d, f), root).replace(os.sep, '/'))
    return out


def repo_top(root):
    try:
        r = subprocess.run(['git', '--no-optional-locks', '-C', root, 'rev-parse', '--show-toplevel'], stdout=subprocess.PIPE,
                           stderr=subprocess.DEVNULL, timeout=60)
    except (OSError, subprocess.SubprocessError):
        return None
    if r.returncode != 0:
        return None
    return os.path.realpath(r.stdout.decode('utf-8', 'replace').strip())


def shorten(s, n=48):
    s = re.sub(r'\s+', ' ', s or '').strip()
    return s if len(s) <= n else s[:n - 1] + '…'


class PhpIndex(object):
    """PHP 쓰기 도우미 사전 — 함수 이름·클래스::메서드 → (무엇, 파일:줄). 여러 단계 전파."""

    def __init__(self):
        self.irs = {}          # rel → PhpIR
        self.by_name = {}      # 이름 → [(rel, func Stmt)]

    def add(self, rel, ir):
        self.irs[rel] = ir
        for name, f in ir.funcs.items():
            if name.startswith('$'):
                continue
            self.by_name.setdefault(name, []).append((rel, f))

    def writers(self):
        """전역 쓰기 도우미 사전(파일 경계를 넘어 고정점까지)."""
        writer = {}
        changed = True
        rounds = 0
        while changed and rounds < 12:
            changed = False
            rounds += 1
            for rel, ir in self.irs.items():
                an = Analyzer(ir.top, ir.funcs, writer, None)
                for name, f in ir.funcs.items():
                    if name.startswith('$') or name in writer:
                        continue
                    prim = self._prim(an, rel, f, writer, set())
                    if prim is not None:
                        writer[name] = prim
                        changed = True
        return writer

    def _prim(self, an, rel, f, writer, seen):
        """함수 f 가 결국 닿는 첫 쓰기(무엇, 파일:줄)."""
        if f.name in seen:
            return None
        for e, _why in an.func_writes(f):
            if e.kind == 'write':
                return (e.what, '%s:%d' % (rel, e.line))
            if e.callee in writer:
                return writer[e.callee]
            if e.callee in an.funcs:
                r = self._prim(an, rel, an.funcs[e.callee], writer, seen | {f.name})
                if r is not None:
                    return r
        return None


def main(argv=None):
    here = os.path.dirname(os.path.abspath(__file__))
    ap = argparse.ArgumentParser(description='R18 dry-run 이 상태를 쓴다 — dry-run 조건 없이 닿는 쓰기(읽기만)')
    ap.add_argument('--root', default=os.path.normpath(os.path.join(here, '..', '..')),
                    help='검사할 트리(기본: 이 파일 기준 저장소 뿌리)')
    ap.add_argument('--dirs', default=','.join(DEFAULT_DIRS), help='뿌리 아래 볼 디렉터리(쉼표, 기본 scripts,tests)')
    ap.add_argument('--outer', default=None, help='바깥 배치(기본 <root>/..) — 그 아래 scripts/ 를 함께 본다, none 이면 끔')
    ap.add_argument('--exclude', action='append', default=None,
                    help='뺄 경로 glob(여러 번). 주면 기본값(tests/static/fixtures/*)을 대신한다')
    ap.add_argument('--walk', action='store_true', help='git 목록 대신 디렉터리 전체를 본다')
    ap.add_argument('--verbose', action='store_true', help='본 dry-run 스크립트를 NOTE 줄로')
    args = ap.parse_args(argv)

    root = os.path.realpath(args.root)
    if not os.path.isdir(root):
        sys.stderr.write('R18: 뿌리가 디렉터리가 아님: %s\n' % args.root)
        print('RESULT pass=0 fail=0 skip=1')
        return 2
    excludes = DEFAULT_EXCLUDES if args.exclude is None else args.exclude
    dirs = [d.strip('/ ') for d in args.dirs.split(',') if d.strip('/ ')]
    try:
        use_git = (not args.walk) and repo_top(root) == root
        rels = list_files(root, use_git)
    except (RuntimeError, OSError, subprocess.SubprocessError) as e:
        sys.stderr.write('R18: 대상 목록을 만들지 못함: %s\n' % e)
        print('RESULT pass=0 fail=0 skip=1')
        return 2

    entries = []     # (표시 경로, 절대 경로, 대상?)
    for rel in rels:
        if any(fnmatch.fnmatch(rel, g) for g in excludes):
            continue
        top = rel.split('/', 1)[0]
        is_target = any(rel == d or rel.startswith(d + '/') for d in dirs)
        if not is_target and top not in LIB_DIRS:
            continue
        entries.append((rel, os.path.join(root, rel), is_target))
    outer = None
    if args.outer is None:
        outer = os.path.dirname(root)
    elif args.outer.lower() != 'none':
        outer = os.path.realpath(args.outer)
        if not os.path.isdir(outer):
            sys.stderr.write('R18: 바깥 배치가 디렉터리가 아님: %s\n' % args.outer)
            print('RESULT pass=0 fail=0 skip=1')
            return 2
    if outer is not None:
        od = os.path.join(outer, 'scripts')
        if os.path.isdir(od) and os.path.realpath(od) != os.path.realpath(os.path.join(root, 'scripts')):
            for d, dd, files in os.walk(od):
                dd[:] = sorted(x for x in dd if x != '.git')
                for f in sorted(files):
                    full = os.path.join(d, f)
                    entries.append(('../' + os.path.relpath(full, outer).replace(os.sep, '/'), full, True))

    skipped = 0
    loaded = []      # (rel, lang, text, target)
    for rel, full, is_target in entries:
        if os.path.islink(full) or not os.path.isfile(full):
            continue
        try:
            with open(full, 'rb') as fh:
                data = fh.read()
        except OSError as e:
            if is_target:
                sys.stderr.write('R18: 읽지 못함: %s (%s)\n' % (rel, e.strerror))
                skipped += 1
            continue
        text = data.decode('utf-8', 'replace').lstrip(chr(0xFEFF))
        lang = file_lang(rel, text[:200])
        if lang:
            loaded.append((rel, lang, text, is_target))

    # ── 사전: PHP 쓰기 도우미 · 셸 함수 ──
    # dry 낱말이 한 번도 없는 파일은 dry-run 스크립트일 수 없다 — 도우미 사전(src·scripts 의 PHP, source 되는 셸)
    # 으로만 읽는다.
    php_index = PhpIndex()
    php_irs, sh_trees, py_irs = {}, {}, {}
    sh_text = {}
    for rel, lang, text, is_target in loaded:
        maybe = is_target and DRY_WORD_RE.search(text) is not None
        try:
            if lang == 'php' and (maybe or rel.split('/', 1)[0] in ('src', 'scripts')):
                ir = PhpIR(rel, text)
                php_irs[rel] = ir
                php_index.add(rel, ir)
            elif lang == 'sh':
                sh_text[rel] = text
                if maybe:
                    sh_trees[rel] = sh_parse(text)
            elif lang == 'py' and maybe:
                py_irs[rel] = PyIR(rel, text)
        except (SyntaxError, ValueError, RecursionError, IndexError, KeyError, AttributeError, TypeError) as e:
            if maybe:
                sys.stderr.write('R18: 구문 분석 실패로 건너뜀: %s (%s)\n' % (rel, e.__class__.__name__))
                skipped += 1
    php_writers = php_index.writers()
    sh_funcs_by_base = {}
    for rel in sh_text:
        sh_funcs_by_base.setdefault(os.path.basename(rel), []).append(rel)

    def sh_tree(orel):
        if orel not in sh_trees:
            try:
                sh_trees[orel] = sh_parse(sh_text[orel])
            except (RecursionError, IndexError, ValueError):
                sh_trees[orel] = SN('list', 1, items=[])
        return sh_trees[orel]

    findings = []
    checked = 0
    for rel, lang, text, is_target in loaded:
        if not is_target or DRY_WORD_RE.search(text) is None:
            continue
        try:
            if lang == 'php':
                ir = php_irs.get(rel)
                if ir is None or not ir.dry_seen:
                    continue
                checked += 1
                an = Analyzer(ir.top, ir.funcs, php_writers, None)
                for e, via, why in an.findings():
                    findings.append((rel, e, via, why, php_writers))
            elif lang == 'sh':
                tree = sh_trees.get(rel)
                if tree is None:
                    continue
                funcs, runners, exiting = sh_func_index(rel, tree)
                ext = {}
                for b in sh_sourced(tree):
                    for orel in sh_funcs_by_base.get(b, []):
                        if orel == rel:
                            continue
                        of, orun, oexit = sh_func_index(orel, sh_tree(orel))
                        for nm, nd in of.items():
                            ext.setdefault(nm, (orel, nd))
                        runners |= orun
                        exiting |= oexit
                ir = ShIR(rel, tree, set(ext), runners, exiting)
                if not ir.dry_seen:
                    continue
                checked += 1
                ext_writer = {}
                for nm, (orel, nd) in ext.items():
                    if nm in ir.local_funcs:
                        continue
                    oir = ShIR(orel, SN('list', 1, items=[nd]), set(), runners, exiting)
                    oan = Analyzer(oir.top, oir.funcs, {}, None)
                    f = oir.funcs.get(nm)
                    if f is not None:
                        ws = oan.func_writes(f)
                        if ws:
                            ext_writer[nm] = (ws[0][0].what, '%s:%d' % (orel, ws[0][0].line))
                an = Analyzer(ir.top, ir.funcs, ext_writer, None)
                for e, via, why in an.findings():
                    findings.append((rel, e, via, why, ext_writer))
            elif lang == 'py':
                ir = py_irs.get(rel)
                if ir is None or not ir.dry_seen:
                    continue
                checked += 1
                an = Analyzer(ir.top, ir.funcs, {}, None)
                for e, via, why in an.findings():
                    findings.append((rel, e, via, why, {}))
            if args.verbose:
                print('NOTE R18 dry-run 스크립트 %s' % rel)
        except (RecursionError, IndexError, KeyError, AttributeError, TypeError, ValueError) as e:
            sys.stderr.write('R18: 분석 실패로 건너뜀: %s (%s)\n' % (rel, e.__class__.__name__))
            skipped += 1

    groups = {}
    for rel, e, via, why, ext in findings:
        g = groups.setdefault((rel, e.line), {'e': e, 'vias': [], 'why': [], 'ext': ext, 'rel': rel})
        if via:
            g['vias'].append(via)
        g['why'].extend(why)
        if e.destr:
            g['e'] = e
    out = sorted((make_finding(g['rel'], g['e'], g['vias'], g['why'], g['ext']) for g in groups.values()),
                 key=lambda f: (f[1], f[2]))
    texts = {rel: text for rel, _, text, _ in loaded}
    kept = []
    for f in out:
        lines = texts.get(f[1], '').split('\n')
        if 0 < f[2] <= len(lines) and ALLOW_RE.search(lines[f[2] - 1]):
            skipped += 1         # 줄 표식 `qcheck-allow: R18` — 받아들인 쓰기
            continue
        kept.append(f)
    out = kept
    for sev, path, line, msg in out:
        print('FINDING %s %s %s:%d %s' % (RULE, sev, path, line, msg))
    bad = len(set(f[1] for f in out))
    print('RESULT pass=%d fail=%d skip=%d' % (max(0, checked - bad), len(out), skipped))
    return 1 if out else 0


def make_finding(rel, e, vias, why, ext_writer):
    sev = '높음' if e.destr else '중간'
    if e.kind == 'call':
        w = ext_writer.get(e.callee)
        what = '쓰기 도우미 %s(→ %s @%s)' % (e.what, w[0], w[1]) if w else e.what
    else:
        what = e.what
    msg = 'dry-run 스크립트에서 %s 이(가) dry-run 조건 없이 닿는다' % what
    if vias:
        chain = ' → '.join('%s()' % n for n, _ in vias[0])
        entry = sorted(set(v[0][1] for v in vias))
        more = '' if len(entry) <= 6 else ' 외 %d곳' % (len(entry) - 6)
        msg += ' — %s 를 거쳐(부르는 줄 %s%s)' % (chain, '·'.join(str(x) for x in entry[:6]), more)
    if why:
        seen = []
        for name, lines in why:
            s = '%s(%s행)' % (name, ','.join(str(x) for x in sorted(set(lines))[:4]))
            if s not in seen:
                seen.append(s)
        msg += ' — 감싼 if 의 깃발을 켜는 자리에 dry-run 조건이 없다: %s' % ' · '.join(seen[:3])
    return (sev, rel, e.line, msg)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        sys.exit(2)
    except Exception as ex:     # 실행 오류 = 검사 못 함(H-1)
        sys.stderr.write('R18: 실행 오류: %s: %s\n' % (ex.__class__.__name__, ex))
        print('RESULT pass=0 fail=0 skip=1')
        sys.exit(2)
