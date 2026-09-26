#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""tests/lib/results.py — 시험 결과 파서·요약기 (점검 봇 1-A, docs/checkbot/stage1-directive.md A-6)

python3 표준 라이브러리만 쓴다. 두 가지로 쓰인다.

1) 도구 (tests/lib/selftest.sh 가 부른다)
     python3 tests/lib/results.py judge <원문> --rc N [--status S] [--expires YYYY-MM-DD] [--today YYYY-MM-DD]
     python3 tests/lib/results.py redact <원문>     가림 뒤 원문
     python3 tests/lib/results.py denoise <원문>    요약용 잡음 제거 뒤 원문
2) tests/run.sh 의 몸체
     python3 tests/lib/results.py cli --root <저장소> <list|run|show|summary|report> …
   run.sh 가 umask 077 · 실행 폴더 0700 · flock 을 먼저 하고 이 파일을 exec 한다.
   잠금은 실행 폴더가 아니라 사용자 단위 한 곳: ${XDG_RUNTIME_DIR:-$HOME/.cache}/qaredi-check/run.lock
   (폴더 0700) — QCHECK_RUNS 를 달리 준 두 실행도 서로 막는다.
   시험 자식은 077 이 아니라 부른 쪽 umask(QCHECK_CHILD_UMASK)로 돈다.

세는 법(지시서 A-6):
  - 원문 끝부분에서 합계 줄을 찾는다. 끝에서부터 처음 걸리는 줄을 쓴다(중첩 시험의 합계에 속지 않게).
  - `RESULT pass= fail= skip=` 줄이 있으면 그것 → 없으면 한국어 다섯 변형 → 파이썬 X_FAIL= → 정적 「결과:」.
  - rc 와 합계가 어긋나면 더 나쁜 쪽(실패)을 쓰고 「형식 위반」으로 표시한다.
  - rc 3·4 는 ③ 시작 거부/준비 실패. status=required 인데 SKIP>0 이면 ③ skipped_by_test.
  - known_fail 은 ③ known_fail(사유·만료일). 만료가 지났거나 만료일이 없으면 required 로 판정한다.
  - 운영에 쓰는 시험(writes_prod: true · 또는 cmd 가 저장소 카탈로그의 writes_prod 시험 파일을 부름)은 **기본 거부** —
    ③ writes_prod. --select·--catalog(사용자 지정 목록)·--include-manual 로는 풀리지 않는다. `--allow-writes-prod` 를 **명시**하고
    그 시험 스크립트의 대상(첫 `BASE="…"`·`DB="…"` 줄)이 tests/lib/prod_guard.sh 판정으로 「prod」일 때만 돈다 — temp·unknown 이면
    이 인자가 있어도 거부(디버깅 묶음 T 9번 — 2026-09-26 회귀 틀이 f51·f52 를 돌려 운영 qa-totp.txt 를 덮어쓴 사고 · 취합 결정).
"""

import argparse
import datetime as _dt
import fcntl
import hashlib
import json
import os
import re
import shlex
import signal
import subprocess
import sys
import time

MASK = "‹가림›"
RC_OK, RC_FAIL, RC_USAGE, RC_REFUSE, RC_PREP = 0, 1, 2, 3, 4
KNOWN_STATUS = ("required", "advisory", "known_fail", "manual_only", "helper", "orphan")
DEFAULT_EXCLUDED = ("manual_only", "helper", "orphan")
TAIL_LINES = 400          # 합계 줄을 찾는 끝부분 줄 수
SHOW_MAX = 200            # show 한 번에 보이는 줄 수 상한
SUMMARY_MAX = 40          # 요약 줄 수 상한
CACHE_TTL = 24 * 3600     # 캐시: 24시간 안에 통과한 기록만
LINE_CUT = 400            # show 에서 한 줄을 자르는 길이
SNIP_CUT = 110            # 요약의 실패 한 줄 조각 길이
FOOTER = "로컬 요약 — 독립 검토(②)·관문 ③ 을 대신하지 않음"
PROD_MARK = "운영 인스턴스를 침(qa 계정·R-73 가드는 시험 안)"
ID_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.\-]*$")
ENV_ASSIGN_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*=")

# .env 키 이름(값 없음). .env.example 에서 이름만 더 읽는다 — 값은 읽지도 쓰지도 않는다.
ENV_KEYS_DEFAULT = (
    "ALERT_COMMAND", "API_TOKEN", "API_TOKEN_USER", "APP_SECRET", "APP_URL", "AUDIT_LOG_FILE",
    "DB_HOST", "DB_NAME", "DB_PASS", "DB_PERM_PASS", "DB_PERM_USER", "DB_PORT", "DB_USER",
    "FETCH_ALLOWED_HOSTS", "SCAN_ENABLED", "SESSION_IDLE_MIN", "SESSION_MAX_HOURS", "STORAGE_DIR",
)

# ─── 줄 모양 ────────────────────────────────────────────────────────────────
ANSI_RE = re.compile(r"\x1b\[[0-9;?]*[A-Za-z]")
RE_RESULT = re.compile(r"^RESULT[ \t]+pass=(\d+)[ \t]+fail=(\d+)[ \t]+skip=(\d+)[ \t]*$")
# 한국어 다섯 변형 (원문: tests/*.sh · tests/community/*.sh 의 printf/echo 합계 줄)
#   ko_skip_unverified  printf '통과 %d · 실패 %d · 건너뜀/미검증 %d\n'   api_* · f53·f54·f65·f66·f68 · q_launcher · stage3·4a
#   ko_skip             printf '통과 %d · 실패 %d · 건너뜀 %d\n'         community stage4b~7a
#   ko_indent_pf        printf '  통과 %d · 실패 %d\n'                    stage26 · f46·f51·f52 · changelog · fetch_hosts · help_button · totp_time_sync
#   ko_health           echo "  통과 $PASS · 실패 $FAIL · 건너뜀 $SKIP"   health
#   ko_wiki_books       hr "결과: 통과 $PASS · 실패 $FAIL · 건너뜀 $SKIP"  wiki_books
RE_KO = re.compile(
    r"^(?P<lead>[ \t]*)(?P<res>결과:[ \t]*)?통과[ \t]+(?P<p>\d+)[ \t]*·[ \t]*실패[ \t]+(?P<f>\d+)"
    r"(?:[ \t]*·[ \t]*(?P<sl>건너뜀/미검증|건너뜀)[ \t]+(?P<s>\d+))?[ \t]*$")
# 파이썬 네 형식: SMOKE_FAIL=(browser_*.py) · BROWSER_FAIL=(wiki_books_browser) · FAIL=(stage5a_handover_self) · SEARCH_FAIL=(stage5b_search)
RE_PY = re.compile(r"^(SMOKE_FAIL|BROWSER_FAIL|SEARCH_FAIL|FAIL)=(\d+)[ \t]*$")
# 정적: security_lint.sh 「결과: 통과 (금지 패턴 0건)」 · wiki_check.php 「결과: 통과 (…)」/「결과: 실패 — …」
RE_STATIC = re.compile(r"^결과:[ \t]*(통과|실패)(?![ \t]*\d)")
RE_PASS_MARK = re.compile(r"^[ \t]*✔[ \t]|^PASS[ \t]")
RE_FAIL_MARK = re.compile(
    r"^[ \t]*✖|^[ \t]*FAIL(?![=_A-Za-z0-9])|^RESULT_ITEM[ \t]+\S+[ \t]+fail\b|^결과:[ \t]*실패"
    r"|^(?:SMOKE_FAIL|BROWSER_FAIL|SEARCH_FAIL|FAIL)=[1-9]|^Traceback \(most recent call last\)|^[ \t]+x[ \t]")
RE_SKIP_MARK = re.compile(r"^[ \t]*○[ \t]*SKIP\b|^[ \t]*SKIP\b|^RESULT_ITEM[ \t]+\S+[ \t]+skip\b")
# 잡음(요약에서만 뺀다, 원문엔 남긴다): psql NOTICE·BEGIN·COMMIT·CREATE·ALTER·GRANT, Playwright 재시도 줄
RE_NOISE = re.compile(
    r"^[ \t]*(?:(?:psql:\S+:[ \t]*)?NOTICE:|BEGIN[ \t]*$|COMMIT[ \t]*$|(?:CREATE|ALTER)(?:[ \t]+[A-Z]+)*[ \t]*$"
    r"|GRANT[ \t]*$|-[ \t]+(?:retrying\b|waiting[ \t]+\d+[ \t]*m?s\b))")


def _lines(text):
    return [ANSI_RE.sub("", ln).rstrip("\r") for ln in text.split("\n")]


def _ko_format(m):
    lead, res, sl = m.group("lead"), m.group("res"), m.group("sl")
    if res:
        return "ko_wiki_books" if sl == "건너뜀" else "ko_result_other"
    if lead:
        if sl is None:
            return "ko_indent_pf"
        return "ko_health" if sl == "건너뜀" else "ko_other"
    if sl == "건너뜀/미검증":
        return "ko_skip_unverified"
    if sl == "건너뜀":
        return "ko_skip"
    return "ko_other"


def parse_counts(text):
    """원문 끝부분에서 합계를 찾는다. 없으면 None."""
    lines = _lines(text)
    start = max(0, len(lines) - TAIL_LINES)
    for i in range(len(lines) - 1, start - 1, -1):
        ln = lines[i]
        m = RE_RESULT.match(ln)
        if m:
            p, f, s = (int(x) for x in m.groups())
            out = {"pass": p, "fail": f, "skip": s, "format": "result", "lineno": i + 1, "violation": ""}
            # 바로 위(5줄 안)의 한국어 합계 줄과 대조한다 — 다르면 형식 위반, 실패는 큰 쪽
            for j in range(i - 1, max(start, i - 6) - 1, -1):
                k = RE_KO.match(lines[j])
                if not k:
                    continue
                kp, kf = int(k.group("p")), int(k.group("f"))
                ks = int(k.group("s")) if k.group("s") is not None else None
                if (kp, kf) != (p, f) or (ks is not None and ks != s):
                    out["violation"] = "RESULT 줄(%d/%d/%d)과 한국어 합계 줄(%d/%d/%s)이 다름" % (
                        p, f, s, kp, kf, "-" if ks is None else ks)
                    out["fail"] = max(f, kf)
                break
            return out
        m = RE_KO.match(ln)
        if m:
            return {"pass": int(m.group("p")), "fail": int(m.group("f")),
                    "skip": int(m.group("s") or 0), "format": _ko_format(m), "lineno": i + 1, "violation": ""}
        m = RE_PY.match(ln)
        if m:
            passes = sum(1 for x in lines if RE_PASS_MARK.match(x))
            return {"pass": passes, "fail": int(m.group(2)), "skip": 0,
                    "format": "py_" + m.group(1).lower(), "lineno": i + 1, "violation": ""}
        m = RE_STATIC.match(ln)
        if m:
            ok = m.group(1) == "통과"
            return {"pass": 1 if ok else 0, "fail": 0 if ok else 1, "skip": 0,
                    "format": "static", "lineno": i + 1, "violation": ""}
    return None


# ─── 가림 ─────────────────────────────────────────────────────────────────
_REDACTORS = None
_REDACT_ROOT = None


def _env_keys(root):
    keys = set(ENV_KEYS_DEFAULT)
    cands = []
    if root:
        cands.append(os.path.join(root, ".env.example"))
    cands.append(os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", ".env.example")))
    for p in cands:
        try:
            with open(p, encoding="utf-8", errors="replace") as fh:
                for line in fh:
                    m = re.match(r"^[ \t]*#?[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=", line)
                    if m:
                        keys.add(m.group(1))
        except OSError:
            pass
    return keys


def set_redact_root(root):
    global _REDACTORS, _REDACT_ROOT
    _REDACT_ROOT = root
    _REDACTORS = None


def _redactors():
    global _REDACTORS
    if _REDACTORS is None:
        val = r"""(?:"[^"\n]*"|'[^'\n]*'|[^\s;'"&,)]+)"""
        jstr = r'(?:[^"\\\n]|\\.)*'   # JSON 문자열 몸 — \" 같은 이스케이프를 넘어 끝 따옴표까지
        keys = sorted(_env_keys(_REDACT_ROOT), key=len, reverse=True)
        _REDACTORS = [
            (re.compile(r"(tbox_session=)[^\s;'\"&,]+"), r"\1" + MASK),
            (re.compile(r"(tbox_session\t)\S+"), r"\1" + MASK),                  # curl 쿠키 병(jar) 줄
            (re.compile(r"(?i)(X-CSRF-Token:[ \t]*)[^\s'\"]+"), r"\1" + MASK),
            (re.compile(r"(?i)(X-API-Token:[ \t]*)[^\s'\"]+"), r"\1" + MASK),     # 개인 API 토큰 헤더(src/Http.php)
            (re.compile(r"(?i)(Authorization:[ \t]*)[^\n'\"]+"), r"\1" + MASK),
            (re.compile(r"(PGPASSWORD=)" + val), r"\1" + MASK),
            (re.compile(r"\$argon2(?:id|i|d)\$[A-Za-z0-9$=,+/.\-]+"), MASK),
            (re.compile(r"otpauth://[^\s'\"<>]+"), MASK),
            (re.compile(r"(\"csrf(?:_token)?\"[ \t]*:[ \t]*\")[^\"]*(\")"), r"\1" + MASK + r"\2"),
            (re.compile(r"(\"token\"[ \t]*:[ \t]*\")[^\"]*(\")"), r"\1" + MASK + r"\2"),  # 토큰 발급 응답(me_tokens_issue)
            # JSON 의 비밀 칸: "secret" · "password" · "current_password" · "new_password" 와 같은 꼴(…_secret·…_password·…_token)
            (re.compile(r'(?i)("(?:[A-Za-z0-9]+_)*(?:secret|password|token)"[ \t]*:[ \t]*")' + jstr + r'(")'),
             r"\1" + MASK + r"\2"),
            # URL 쿼리·폼 본문의 token= · secret= · password= (…_token= 등). otpauth:// 는 위에서 통째로 가렸다
            (re.compile(r"(?i)(?<![A-Za-z0-9_])((?:[A-Za-z0-9]+_)*(?:token|secret|password)=)[^&\s#'\"<>]+"),
             r"\1" + MASK),
            (re.compile(r"(?i)(<meta[ \t]+name=[\"']csrf-token[\"'][ \t]+content=[\"'])[^\"']*([\"'])"),
             r"\1" + MASK + r"\2"),
            # .env 키 이름 목록의 KEY=값 · KEY = 값(등호 앞뒤 빈칸)
            (re.compile(r"\b(" + "|".join(re.escape(k) for k in keys) + r")([ \t]*=[ \t]*)" + val), r"\1\2" + MASK),
        ]
    return _REDACTORS


def redact(text):
    for rx, rep in _redactors():
        text = rx.sub(rep, text)
    return text


def is_noise(line):
    return bool(RE_NOISE.match(line))


def denoise(text):
    return "\n".join(ln for ln in text.split("\n") if not is_noise(ln))


def _snip(line, cut=SNIP_CUT):
    s = redact(ANSI_RE.sub("", line).strip())
    return s if len(s) <= cut else s[: cut - 1] + "…"


def first_mark(text, rx):
    for ln in _lines(text):
        if is_noise(ln):
            continue
        if rx.search(ln):
            return _snip(ln)
    return ""


def last_nonempty(text):
    for ln in reversed(_lines(text)):
        if ln.strip() and not is_noise(ln):
            return _snip(ln)
    return ""


# ─── 판정 ─────────────────────────────────────────────────────────────────
def _parse_date(s):
    try:
        return _dt.date.fromisoformat(str(s))
    except (TypeError, ValueError):
        return None


def judge(text, rc, entry=None, today=None):
    """원문·rc·카탈로그 항목 → 판정 dict.

    verdict: pass | fail | unverified(③)   code: ③ 사유 코드(없으면 None)
    """
    entry = entry or {}
    today = today or _dt.date.today()
    status = entry.get("status") or "required"
    if status not in KNOWN_STATUS:
        status = "required"   # 모르는 status 는 가장 엄한 쪽으로
    res = {"rc": rc, "verdict": None, "code": None, "counts": {"pass": 0, "fail": 0, "skip": 0},
           "format": None, "violation": False, "note": "", "first_fail": "", "summary_lineno": None}
    notes = []
    p = parse_counts(text)
    if p:
        res["counts"] = {"pass": p["pass"], "fail": p["fail"], "skip": p["skip"]}
        res["format"] = p["format"]
        res["summary_lineno"] = p["lineno"]

    if rc == RC_REFUSE:
        res.update(verdict="unverified", code="refused")
        notes.append("시작 거부(rc 3)")
        hint = last_nonempty(text)
        if hint:
            notes.append(hint)
    elif rc == RC_PREP:
        res.update(verdict="unverified", code="prep_failed")
        notes.append("준비 실패(rc 4)")
        hint = first_mark(text, RE_FAIL_MARK) or last_nonempty(text)
        if hint:
            notes.append(hint)
    else:
        rc_txt = ("신호 %d" % -rc) if rc < 0 else ("rc %d" % rc)
        if p is None:
            res["verdict"] = "fail"
            if rc == 0:
                res["violation"] = True
                notes.append("형식 위반: 합계 줄 없음 — rc 0 이지만 셀 수 없음 → 실패로 셈")
            else:
                notes.append("합계 줄 없음(%s) — 합계 전에 멈춤" % rc_txt)
        else:
            if p["violation"]:
                res["violation"] = True
                notes.append("형식 위반: " + p["violation"])
                res["counts"]["fail"] = p["fail"]
            f_items = res["counts"]["fail"] > 0
            f_rc = rc != 0
            if f_items and f_rc:
                res["verdict"] = "fail"
            elif f_items and not f_rc:
                res["verdict"] = "fail"
                res["violation"] = True
                notes.append("형식 위반: rc 0 인데 합계 실패 %d → 실패로 셈" % res["counts"]["fail"])
            elif f_rc and not f_items:
                res["verdict"] = "fail"
                res["violation"] = True
                notes.append("형식 위반: %s 인데 합계 실패 0 → 실패로 셈" % rc_txt)
            else:
                res["verdict"] = "pass"

        eff = status
        if status == "known_fail":
            kf = entry.get("known_fail") if isinstance(entry.get("known_fail"), dict) else {}
            exp = _parse_date(kf.get("expires"))
            if exp is None:
                eff = "required"
                notes.append("known_fail 만료일 없음 → required 로 판정")
            elif today > exp:
                eff = "required"
                notes.append("known_fail 만료(%s) → required 로 판정" % exp.isoformat())
            else:
                was = res["verdict"]
                res["verdict"] = "unverified"
                res["code"] = "known_fail"
                reason = str(kf.get("reason") or "사유 없음")
                notes.insert(0, "「%s」 만료 %s" % (reason, exp.isoformat()))
                if was == "pass":
                    notes.append("이번엔 통과 — 해소 후보")
        if eff == "required" and res["verdict"] == "pass" and res["counts"]["skip"] > 0:
            res["verdict"] = "unverified"
            res["code"] = "skipped_by_test"
            notes.insert(0, "SKIP %d" % res["counts"]["skip"])
            hint = first_mark(text, RE_SKIP_MARK)
            if hint:
                notes.append(hint)

    if res["verdict"] == "fail":
        res["first_fail"] = first_mark(text, RE_FAIL_MARK) or last_nonempty(text)
    res["note"] = redact(" · ".join(n for n in notes if n))
    return res


# ─── 카탈로그 ──────────────────────────────────────────────────────────────
class UsageError(Exception):
    pass


class Refuse(Exception):
    pass


def load_catalog(path):
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
    except FileNotFoundError:
        raise UsageError("카탈로그가 없다: %s (--catalog <경로>)" % path)
    except (OSError, ValueError) as e:
        raise UsageError("카탈로그를 읽지 못함: %s — %s" % (path, e))
    items = None
    if isinstance(data, list):
        items = data
    elif isinstance(data, dict):
        for k in ("tests", "entries", "items", "catalog"):
            if isinstance(data.get(k), list):
                items = data[k]
                break
    if items is None:
        raise UsageError("카탈로그 모양이 아니다(목록 또는 {\"tests\": [...]}): %s" % path)
    errors, seen, out = [], set(), []
    for i, e in enumerate(items):
        if not isinstance(e, dict):
            errors.append("%d번 항목이 객체가 아니다" % i)
            continue
        tid, cmd = e.get("id"), e.get("cmd")
        if not isinstance(tid, str) or not ID_RE.match(tid):
            errors.append("%d번 항목 id 가 잘못됨: %r" % (i, tid))
            continue
        if tid in seen:
            errors.append("id 중복: %s" % tid)
        seen.add(tid)
        if not isinstance(cmd, str) or not cmd.strip():
            errors.append("%s: cmd 없음" % tid)
        st = e.get("status") or "required"
        if st not in KNOWN_STATUS:
            sys.stderr.write("run.sh: 경고 — %s 의 status %r 를 모른다 → required 로 판정\n" % (tid, st))
        out.append(e)
    if errors:
        raise UsageError("카탈로그 오류 %d건: %s" % (len(errors), "; ".join(errors[:8])))
    return out


def entry_hash(e):
    return hashlib.sha256(json.dumps(e, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def e_status(e):
    st = e.get("status") or "required"
    return st if st in KNOWN_STATUS else "required"


def e_list(e, k):
    v = e.get(k)
    if isinstance(v, list):
        return [str(x) for x in v]
    if isinstance(v, str) and v:
        return [v]
    return []


def is_prod(e):
    return e.get("env") == "prod" or e.get("writes_prod") is True


# ─── glob ─────────────────────────────────────────────────────────────────
_GLOB = {}


def glob_re(g):
    r = _GLOB.get(g)
    if r is not None:
        return r
    i, n, out = 0, len(g), []
    while i < n:
        c = g[i]
        if c == "*":
            if i + 1 < n and g[i + 1] == "*":
                i += 2
                if i < n and g[i] == "/":
                    i += 1
                    out.append("(?:.*/)?")
                else:
                    out.append(".*")
                continue
            out.append("[^/]*")
        elif c == "?":
            out.append("[^/]")
        elif c == "[":
            j = g.find("]", i + 1)
            if j == -1:
                out.append(re.escape(c))
            else:
                body = g[i + 1:j].replace("\\", "\\\\")
                if body.startswith("!"):
                    body = "^" + body[1:]
                out.append("[" + body + "]")
                i = j
        else:
            out.append(re.escape(c))
        i += 1
    pat = "".join(out)
    if g.endswith("/"):
        pat += ".*"
    r = re.compile("^" + pat + "$")
    _GLOB[g] = r
    return r


def has_magic(g):
    return any(c in g for c in "*?[")


def path_match(g, path):
    g = g[2:] if g.startswith("./") else g
    if glob_re(g).match(path):
        return True
    if not has_magic(g):
        base = g.rstrip("/")
        return path == base or path.startswith(base + "/")
    return False


def globs_overlap(a, b, files):
    if a == b:
        return True
    if not has_magic(a) and path_match(b, a.rstrip("/")):
        return True
    if not has_magic(b) and path_match(a, b.rstrip("/")):
        return True
    return any(path_match(a, f) and path_match(b, f) for f in files)


# ─── git ──────────────────────────────────────────────────────────────────
def git(root, *args):
    try:
        r = subprocess.run(["git", "--no-optional-locks", "-C", root] + list(args),
                           stdin=subprocess.DEVNULL, capture_output=True, text=True, timeout=60)
    except (OSError, subprocess.TimeoutExpired):
        return 1, "", ""
    return r.returncode, r.stdout, r.stderr


def git_state(root):
    rc, tree, _ = git(root, "rev-parse", "HEAD^{tree}")
    if rc != 0:
        return None, None, True
    _, head, _ = git(root, "rev-parse", "HEAD")
    rc2, st, _ = git(root, "status", "--porcelain", "--untracked-files=normal")
    dirty = rc2 != 0 or bool(st.strip())
    return tree.strip(), head.strip(), dirty


def repo_files(root):
    rc, out, _ = git(root, "ls-files")
    return [x for x in out.split("\n") if x] if rc == 0 else []


DIFF_SPEC_RE = re.compile(r"^[A-Za-z0-9_./~^@{}\-]+(?:\.\.\.?[A-Za-z0-9_./~^@{}\-]+)?$")


def diff_files(root, spec):
    """spec: 'a..b' | 'a...b' | 'auto' → (설명, 바뀐 파일 목록)"""
    if spec == "auto":
        rc, _, _ = git(root, "rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{u}")
        if rc == 0:
            _, base, _ = git(root, "merge-base", "HEAD", "@{u}")
            base = base.strip() or "HEAD"
            desc = "auto: 업스트림 갈래점 %s..작업 트리" % base[:7]
        else:
            base, desc = "HEAD", "auto: HEAD..작업 트리"
        _, a, _ = git(root, "diff", "--name-only", "--no-renames", base, "--")
        _, u, _ = git(root, "ls-files", "--others", "--exclude-standard")
        files = sorted(set(x for x in (a + "\n" + u).split("\n") if x))
        if not files and base == "HEAD" and rc != 0:
            _, b, _ = git(root, "diff", "--name-only", "--no-renames", "HEAD~1", "HEAD", "--")
            files = sorted(set(x for x in b.split("\n") if x))
            desc = "auto: 바뀐 것 없음 → HEAD~1..HEAD"
        return desc, files
    if not DIFF_SPEC_RE.match(spec) or spec.startswith("-"):
        raise UsageError("diff 선택자가 잘못됨: %r (예: diff:HEAD~3..HEAD, diff:auto)" % spec)
    rc, out, err = git(root, "diff", "--name-only", "--no-renames", spec, "--")
    if rc != 0:
        raise UsageError("git diff %s 실패: %s" % (spec, err.strip()[:200]))
    return spec, sorted(set(x for x in out.split("\n") if x))


# no_test 로 올리지 않는 파일(문서·자료). 코드가 아닌 것만 둔다.
DOC_GLOBS = ("**/*.md", "**/*.txt", "docs/**", "wiki/**", "tests/**/fixtures/**", "CHANGELOG.json", "VERSION",
             ".gitignore", ".env.example")


def is_code_file(f):
    return not any(path_match(g, f) for g in DOC_GLOBS)


# 「시험 파일」: tests/ 아래 .sh·.py (lib·fixtures·요약기 자신은 뺀다). 카탈로그에 실린 것 = 어느 항목의 cmd 가 부르는 것.
TEST_FILE_GLOBS = ("tests/**/*.sh", "tests/**/*.py")
NOT_TEST_GLOBS = ("tests/lib/**", "tests/**/fixtures/**", "tests/run.sh")


def outside_catalog(entries, root):
    """저장소(추적 + 추적 안 된 새 파일, .gitignore 제외)의 시험 파일 가운데 카탈로그 cmd 가 부르지 않는 것."""
    files = set(repo_files(root))
    rc, out, _ = git(root, "ls-files", "--others", "--exclude-standard", "--", "tests")
    if rc == 0:
        files.update(x for x in out.split("\n") if x)
    tests = sorted(f for f in files
                   if any(path_match(g, f) for g in TEST_FILE_GLOBS) and not any(path_match(g, f) for g in NOT_TEST_GLOBS))
    fileset = set(tests)
    listed = set()
    for e in entries:
        listed |= own_files(e, fileset)
    return [f for f in tests if f not in listed]


def writes_prod_files(root, entries):
    """writes_prod 인 항목이 부르는 시험 파일 — 지금 카탈로그 **와 저장소 카탈로그(tests/catalog.json)** 둘에서 모은다.
    사용자 지정 카탈로그(--catalog)가 writes_prod 표시를 빼거나 다른 id 로 같은 파일을 불러도 거부하려고(디버깅 묶음 T 9번)."""
    files = set(repo_files(root))
    out = set()
    lists = [entries]
    try:
        lists.append(load_catalog(os.path.join(root, "tests", "catalog.json")))
    except UsageError:
        pass
    for es in lists:
        for e in es:
            if e.get("writes_prod") is True:
                out |= own_files(e, files)
    return out


_KIND_CACHE = {}


def script_target_kind(e, root):
    """cmd 가 부르는 시험 스크립트의 대상 — prod · temp · unknown (tests/lib/prod_guard.sh pg_target_kind 그대로).
    스크립트의 첫 `BASE="…"`·`DB="…"` 줄(시험이 쓰는 값)을 읽어 넘긴다. 스크립트·두 줄·가드를 못 찾으면 unknown."""
    try:
        toks, _ = split_cmd(str(e.get("cmd") or ""))
    except (UsageError, ValueError):
        return "unknown"
    script = None
    for t in toks:
        if t.endswith(".sh"):
            p = t if os.path.isabs(t) else os.path.join(root, t)
            if os.path.isfile(p):
                script = os.path.realpath(p)
                break
    if not script:
        return "unknown"
    if script in _KIND_CACHE:
        return _KIND_CACHE[script]
    vals = {}
    try:
        with open(script, encoding="utf-8", errors="replace") as fh:
            for line in fh:
                m = re.match(r'^(BASE|DB)="([^"$`\\]*)"\s*(#.*)?$', line.rstrip("\n"))
                if m and m.group(1) not in vals:
                    vals[m.group(1)] = m.group(2)
    except OSError:
        return "unknown"
    guard = os.path.join(root, "tests", "lib", "prod_guard.sh")
    kind = "unknown"
    if "BASE" in vals and "DB" in vals and os.path.isfile(guard):
        env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "ROOT": root, "BASE": vals["BASE"], "DB": vals["DB"]}
        try:
            r = subprocess.run(["bash", "-c", '. "$ROOT/tests/lib/prod_guard.sh" && pg_target_kind'], env=env,
                               capture_output=True, text=True, timeout=10)
            out = r.stdout.strip()
            if r.returncode == 0 and out in ("prod", "temp", "unknown"):
                kind = out
        except (OSError, subprocess.SubprocessError):
            kind = "unknown"
    _KIND_CACHE[script] = kind
    return kind


def prod_write_decision(e, wp_files, root, allow):
    """(거부 이유 | None, 허용 메모 | None) — 운영에 쓰는 시험은 기본 거부 · --allow-writes-prod + 대상 prod 일 때만 허용"""
    why = refuses_prod_write(e, wp_files, root)
    if not why:
        return None, None
    if not allow:
        return why + " — 기본 거부(--allow-writes-prod 와 대상 prod 일 때만)", None
    kind = script_target_kind(e, root)
    if kind != "prod":
        return why + " — --allow-writes-prod 이어도 대상이 %s(prod_guard) → 거부" % kind, None
    return None, "--allow-writes-prod · 대상 prod(prod_guard) — 운영에 씀"


def refuses_prod_write(e, wp_files, root="."):
    """운영에 쓰는 시험이면 거부 이유(문자열), 아니면 None.
    cmd 의 파일 토큰은 저장소 뿌리 기준 **realpath** 로 writes_prod 시험 파일과 견준다(`./tests/../tests/f52.sh`·링크).
    `-c` 문자열(bash -c · sh -c …)에 writes_prod 시험 파일의 **이름(basename)** 이 들어 있어도 거부한다(독립 검토 L1).
    한계: 다른 스크립트가 **안에서** 부르는 것(감싸는 스크립트)·이름을 바꾼 사본은 못 잡는다 — 그 길은 시험 안 가드(tests/lib/prod_guard.sh)가 막는다."""
    if e.get("writes_prod") is True:
        return "writes_prod: true"
    cmd = str(e.get("cmd") or "")
    try:
        toks = split_cmd(cmd)[0]
    except (UsageError, ValueError):
        toks = cmd.split()
    real = {}
    for f in wp_files:
        real[os.path.realpath(os.path.join(root, f))] = f
    hit = set(f for f in wp_files if f in cmd)
    for t in toks:
        rp = os.path.realpath(t if os.path.isabs(t) else os.path.join(root, t))
        if rp in real:
            hit.add(real[rp])
    if "-c" in toks:
        cstr = " ".join(toks[toks.index("-c") + 1:])
        for f in wp_files:
            if os.path.basename(f) and os.path.basename(f) in cstr:
                hit.add(f)
    if hit:
        return "cmd 가 운영에 쓰는 시험 파일을 부른다(%s — 저장소 카탈로그 writes_prod)" % ", ".join(sorted(hit))
    return None


def split_cmd(cmd):
    toks = shlex.split(cmd)
    env = {}
    while toks and ENV_ASSIGN_RE.match(toks[0]):
        k, _, v = toks.pop(0).partition("=")
        env[k] = v
    if not toks:
        raise UsageError("cmd 에 실행할 것이 없다: %r" % cmd)
    return toks, env


def own_files(e, fileset):
    try:
        toks, _ = split_cmd(e.get("cmd", ""))
    except (UsageError, ValueError):
        return set()
    out = set()
    for t in toks:
        t = os.path.normpath(t) if "/" in t else t
        if t in fileset:
            out.add(t)
    return out


# ─── 고르기 ────────────────────────────────────────────────────────────────
SELECTOR_KINDS = ("id", "module", "env", "status", "path", "diff")


def parse_selector(s):
    kind, sep, val = s.partition(":")
    kind = {"test": "id"}.get(kind, kind)
    if not sep or not val or kind not in SELECTOR_KINDS:
        raise UsageError("선택자가 잘못됨: %r — id:|module:|env:|status:|path:|diff:<a>..<b>|diff:auto" % s)
    return kind, val


def select(entries, selectors, root):
    """선택자 합집합. 반환 (고른 항목 목록(카탈로그 순서), 정보 dict)"""
    info = {"diff": [], "no_test": []}
    if not selectors:
        return list(entries), info
    parsed = [parse_selector(s) for s in selectors]
    need_files = any(k in ("path", "diff") for k, _ in parsed)
    files = repo_files(root) if need_files else []
    fileset = set(files)
    chosen = set()
    for kind, val in parsed:
        for e in entries:
            if e["id"] in chosen:
                continue
            if kind == "id":
                if (glob_re(val).match(e["id"]) if has_magic(val) else e["id"] == val):
                    chosen.add(e["id"])
            elif kind in ("module", "env", "status"):
                got = e_status(e) if kind == "status" else str(e.get(kind, ""))
                if (glob_re(val).match(got) if has_magic(val) else got == val):
                    chosen.add(e["id"])
            elif kind == "path":
                if any(globs_overlap(val, c, files) for c in e_list(e, "covers")) or \
                        any(path_match(val, f) for f in own_files(e, fileset)):
                    chosen.add(e["id"])
        if kind == "diff":
            desc, changed = diff_files(root, val)
            info["diff"].append({"spec": desc, "files": len(changed)})
            for e in entries:
                covers = e_list(e, "covers")
                own = own_files(e, fileset)
                if any(f in own or any(path_match(c, f) for c in covers) for f in changed):
                    chosen.add(e["id"])
            for f in changed:
                if not is_code_file(f):
                    continue
                if not any(f in own_files(e, fileset) or any(path_match(c, f) for c in e_list(e, "covers"))
                           for e in entries):
                    if f not in info["no_test"]:
                        info["no_test"].append(f)
    return [e for e in entries if e["id"] in chosen], info


def run_order(e_idx):
    i, e = e_idx
    needs = e_list(e, "needs")
    return (e.get("env") != "static", "totp_wait" in needs, "browser" in needs, i)


# ─── 실행 폴더 ──────────────────────────────────────────────────────────────
def runs_dir():
    return os.path.abspath(os.path.expanduser(os.environ.get("QCHECK_RUNS") or "~/.cache/qaredi-check/runs"))


def inside(path, root):
    rp, rr = os.path.realpath(path), os.path.realpath(root)
    return rp == rr or rp.startswith(rr.rstrip("/") + "/")


def ensure_runs(root):
    d = runs_dir()
    if inside(d, root):
        raise Refuse("실행 폴더가 저장소 안이다 — 거부: %s" % d)
    os.makedirs(d, mode=0o700, exist_ok=True)
    if os.path.islink(d):
        raise Refuse("실행 폴더가 심볼릭 링크다 — 거부: %s" % d)
    st = os.stat(d)
    if st.st_uid != os.getuid():
        raise Refuse("실행 폴더가 내 소유가 아니다 — 거부: %s" % d)
    if st.st_mode & 0o077:
        os.chmod(d, 0o700)
    return d


def lock_path():
    """사용자 단위 잠금 한 곳 — ${XDG_RUNTIME_DIR:-$HOME/.cache}/qaredi-check/run.lock (run.sh 와 같은 식).

    실행 폴더(QCHECK_RUNS)에 두면 폴더를 달리 준 두 실행이 서로 막지 못한다(같은 운영 8082·같은 qa 계정·
    같은 임시 DB 이름을 동시에 쓰게 된다). 그래서 실행 폴더와 상관없이 사용자마다 한 파일이다.
    """
    base = os.environ.get("XDG_RUNTIME_DIR") or os.path.join(os.path.expanduser("~"), ".cache")
    return os.path.join(base, "qaredi-check", "run.lock")


def ensure_lock_dir(path):
    d = os.path.dirname(path)
    if not os.path.isabs(d):
        raise Refuse("잠금 폴더가 절대 경로가 아니다(XDG_RUNTIME_DIR 확인) — 거부: %s" % d)
    os.makedirs(d, mode=0o700, exist_ok=True)
    if os.path.islink(d) or not os.path.isdir(d):
        raise Refuse("잠금 폴더가 폴더가 아니다(링크 등) — 거부: %s" % d)
    st = os.stat(d)
    if st.st_uid != os.getuid():
        raise Refuse("잠금 폴더가 내 소유가 아니다 — 거부: %s" % d)
    if st.st_mode & 0o077:
        os.chmod(d, 0o700)
    return d


def acquire_lock():
    path = lock_path()
    fd_env = os.environ.get("QCHECK_LOCK_FD")
    fd = None
    if fd_env and fd_env.isdigit():
        try:
            fd = int(fd_env)
            os.fstat(fd)
        except OSError:
            fd = None
    if fd is None:
        ensure_lock_dir(path)
        try:
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600)
        except OSError as ex:
            raise Refuse("잠금 파일을 열지 못함: %s — %s" % (path, ex))
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError:
        raise Refuse("다른 실행이 진행 중이다(flock %s) — 거부" % path)
    return fd


def run_numbers(d):
    try:
        names = os.listdir(d)
    except OSError:
        return []
    return sorted(int(x) for x in names if x.isdigit() and os.path.isdir(os.path.join(d, x)))


def write_private(path, data):
    tmp = path + ".tmp"
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write(data)
    os.chmod(tmp, 0o600)
    os.replace(tmp, path)


def resolve_run(ref):
    d = runs_dir()
    nums = run_numbers(d)
    if ref in ("last", "latest", "마지막"):
        if not nums:
            raise UsageError("실행 기록이 없다: %s" % d)
        n = nums[-1]
    elif ref.isdigit():
        n = int(ref)
    else:
        raise UsageError("실행 번호가 잘못됨: %r (숫자 또는 last)" % ref)
    p = os.path.join(d, str(n), "summary.json")
    try:
        with open(p, encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, ValueError):
        raise UsageError("실행 #%s 기록이 없다: %s" % (n, p))


# ─── 실행 ─────────────────────────────────────────────────────────────────
class _Stop(Exception):
    pass


def _on_term(signum, frame):
    raise _Stop()


CHILD_UMASK_DEFAULT = 0o022


def child_umask():
    """시험 자식에게 줄 umask = 부른 쪽 umask(run.sh 가 umask 077 전에 QCHECK_CHILD_UMASK 로 넘김).

    요약기 자신(실행 폴더·원문·요약)은 077 을 쓰지만, 시험은 직접 돌릴 때와 같은 umask 로 돌아야 한다
    (077 을 물려주면 sudo -u tbox-php 쪽이 임시 파일을 못 읽는 거짓 실패가 생길 수 있다).
    """
    v = os.environ.get("QCHECK_CHILD_UMASK", "")
    if re.match(r"^[0-7]{1,4}$", v) and int(v, 8) <= 0o777:
        return int(v, 8)
    sys.stderr.write("run.sh: 경고 — QCHECK_CHILD_UMASK=%r 가 umask 꼴이 아니다 → 시험 자식은 %04o\n"
                     % (v, CHILD_UMASK_DEFAULT))
    return CHILD_UMASK_DEFAULT


def execute(e, root, raw_path):
    """시험 하나를 돌려 원문을 raw_path(0600)에 쓴다. (rc, 걸린 초, 중단 여부)"""
    argv, env_add = split_cmd(e["cmd"])
    env = os.environ.copy()
    env.pop("QCHECK_LOCK_FD", None)
    env.pop("QCHECK_CHILD_UMASK", None)
    env.update(env_add)
    um = child_umask()
    if sys.version_info >= (3, 9):
        um_kw = {"umask": um}
    else:
        um_kw = {"preexec_fn": lambda: os.umask(um)}
    # 원문 파일은 요약기(umask 077) 쪽에서 0600 을 명시해 연다 — 자식 umask 와 무관하다.
    fd = os.open(raw_path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    interrupted = False
    t0 = time.time()
    with os.fdopen(fd, "wb") as out:
        try:
            proc = subprocess.Popen(argv, cwd=root, stdin=subprocess.DEVNULL, stdout=out,
                                    stderr=subprocess.STDOUT, env=env, close_fds=True, **um_kw)
        except OSError as ex:
            out.write(("run.sh: 실행하지 못함: %s\n" % ex).encode())
            return 127, time.time() - t0, False
        try:
            rc = proc.wait()
        except (KeyboardInterrupt, _Stop) as ex:
            interrupted = True
            # 시험의 trap 정리(덤프·임시 DB 삭제)가 끝나도록 기다린다. SIGKILL 은 쓰지 않는다.
            try:
                if isinstance(ex, _Stop):
                    proc.send_signal(signal.SIGTERM)
                rc = proc.wait(timeout=30)
            except subprocess.TimeoutExpired:
                try:
                    proc.send_signal(signal.SIGTERM)
                    rc = proc.wait(timeout=120)
                except (subprocess.TimeoutExpired, KeyboardInterrupt, _Stop):
                    rc = proc.poll()
                    rc = 130 if rc is None else rc
            except (KeyboardInterrupt, _Stop):
                rc = proc.poll()
                rc = 130 if rc is None else rc
    return rc, time.time() - t0, interrupted


def read_text(path):
    try:
        with open(path, "rb") as fh:
            return fh.read().decode("utf-8", errors="replace")
    except OSError:
        return ""


def base_record(e):
    kf = e.get("known_fail") if isinstance(e.get("known_fail"), dict) else None
    return {"id": e["id"], "module": e.get("module"), "env": e.get("env"), "status": e_status(e),
            "writes_prod": e.get("writes_prod") is True, "cmd": e.get("cmd"),
            "known_fail": ({"reason": kf.get("reason"), "expires": kf.get("expires")} if kf else None)}


def find_cache(d, key, now, current):
    for n in reversed(run_numbers(d)):
        if n == current:
            continue
        try:
            with open(os.path.join(d, str(n), "summary.json"), encoding="utf-8") as fh:
                S = json.load(fh)
        except (OSError, ValueError):
            continue
        if now - float(S.get("started_at") or 0) > CACHE_TTL + 7 * 24 * 3600:
            break
        for t in S.get("tests", []):
            if t.get("cache_key") == key and t.get("verdict") == "pass" and \
                    now - float(t.get("passed_at") or 0) < CACHE_TTL:
                return n, t
    return None


def cmd_run(a, root):
    entries = load_catalog(a.catalog)
    selected, info = select(entries, a.select or [], root)
    if not selected:
        # 0개를 「전부 통과(rc 0)」로 끝내지 않는다 — 선택자 오타가 통과 보고가 되지 않게(fail-closed).
        extra = "".join(" · diff %s 바뀐 파일 %d" % (x["spec"], x["files"]) for x in info["diff"])
        if info["no_test"]:
            extra += " · ③ no_test %d: %s" % (len(info["no_test"]), _join_ids(info["no_test"], 5))
        raise UsageError("선택자가 아무것도 고르지 않음: %s%s (tests/run.sh list --select … 로 확인)" % (
            " ∪ ".join(a.select) if a.select else "전체(카탈로그가 비어 있음)", extra))
    d = ensure_runs(root)
    acquire_lock()
    signal.signal(signal.SIGTERM, _on_term)
    signal.signal(signal.SIGHUP, _on_term)
    n = (run_numbers(d) or [0])[-1] + 1
    rdir = os.path.join(d, str(n))
    os.mkdir(rdir, 0o700)
    os.mkdir(os.path.join(rdir, "raw"), 0o700)
    tree, head, dirty = git_state(root)
    try:
        with open(a.catalog, "rb") as fh:
            cat_sha = hashlib.sha256(fh.read()).hexdigest()
    except OSError:
        cat_sha = None
    now = time.time()
    S = {"version": 1, "kind": "local", "run": n, "root": root, "catalog": os.path.abspath(a.catalog),
         "catalog_sha256": cat_sha, "tree": tree, "head": head, "dirty": dirty,
         "started_at": now, "finished_at": None, "selectors": a.select or [],
         "include_manual": a.include_manual, "allow_writes_prod": a.allow_writes_prod, "fresh": a.fresh, "fail_fast": a.fail_fast,
         "total": len(entries), "selected": len(selected), "diff": info["diff"], "no_test": info["no_test"],
         "tests": [], "interrupted": False, "exit_code": None}
    idx = {e["id"]: i for i, e in enumerate(entries)}
    to_run = []
    wp_files = writes_prod_files(root, entries)
    for e in selected:
        why, _allowed = prod_write_decision(e, wp_files, root, a.allow_writes_prod)
        if why:
            r = base_record(e)
            r.update(verdict="unverified", code="writes_prod", note="돌리지 않음 — " + why,
                     counts={"pass": 0, "fail": 0, "skip": 0}, rc=None)
            S["tests"].append(r)
        elif e_status(e) in DEFAULT_EXCLUDED and not a.include_manual:
            r = base_record(e)
            r.update(verdict="unverified", code=e_status(e), note="기본 제외(--include-manual)",
                     counts={"pass": 0, "fail": 0, "skip": 0}, rc=None)
            S["tests"].append(r)
        else:
            to_run.append(e)
    to_run = [e for _, e in sorted(((idx[e["id"]], e) for e in to_run), key=run_order)]
    sp = os.path.join(rdir, "summary.json")
    write_private(sp, json.dumps(S, ensure_ascii=False, indent=1))
    stop_reason = None
    tty = sys.stderr.isatty()
    for k, e in enumerate(to_run):
        r = base_record(e)
        if stop_reason:
            r.update(verdict="unverified", code="not_run", note=stop_reason, counts={"pass": 0, "fail": 0, "skip": 0}, rc=None)
            S["tests"].append(r)
            continue
        key = None
        if tree and not dirty and e.get("env") != "prod":
            key = hashlib.sha256(("%s\n%s\n%s" % (tree, e["id"], entry_hash(e))).encode()).hexdigest()
        if key and not a.fresh:
            hit = find_cache(d, key, time.time(), n)
            if hit:
                hn, ht = hit
                r.update(verdict="pass", code=None, counts=ht.get("counts"), format=ht.get("format"),
                         rc=ht.get("rc"), note="", first_fail="", cache_key=key, passed_at=ht.get("passed_at"),
                         cached_from=ht.get("cached_from") or hn, raw_run=ht.get("raw_run") or hn,
                         raw="raw/%s.log" % e["id"], elapsed_sec=0.0)
                S["tests"].append(r)
                continue
        if tty:
            sys.stderr.write("… %d/%d %s\n" % (k + 1, len(to_run), e["id"]))
        raw_rel = "raw/%s.log" % e["id"]
        try:
            rc, el, intr = execute(e, root, os.path.join(rdir, raw_rel))
        except (UsageError, ValueError) as ex:
            rc, el, intr = 2, 0.0, False
            efd = os.open(os.path.join(rdir, raw_rel), os.O_WRONLY | os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW, 0o600)
            with os.fdopen(efd, "a", encoding="utf-8") as fh:
                fh.write("run.sh: cmd 오류: %s\n" % ex)
        J = judge(read_text(os.path.join(rdir, raw_rel)), rc, e)
        r.update(J)
        r.update(raw=raw_rel, raw_run=n, elapsed_sec=round(el, 1), cache_key=key,
                 passed_at=(time.time() if J["verdict"] == "pass" else None), cached_from=None)
        S["tests"].append(r)
        write_private(sp, json.dumps(S, ensure_ascii=False, indent=1))
        if intr:
            S["interrupted"] = True
            stop_reason = "중단됨(신호) — 돌리지 않음"
        elif a.fail_fast and J["verdict"] == "fail":
            stop_reason = "--fail-fast — 앞 실패로 멈춤"
    S["finished_at"] = time.time()
    fails = [t for t in S["tests"] if t.get("verdict") == "fail"]
    S["exit_code"] = RC_FAIL if (fails or S["interrupted"]) else RC_OK
    write_private(sp, json.dumps(S, ensure_ascii=False, indent=1))
    text = render_summary(S)
    write_private(os.path.join(rdir, "summary.txt"), text + "\n")
    print(text)
    return S["exit_code"]


# ─── 요약 · 보고 ────────────────────────────────────────────────────────────
def _dur(sec):
    sec = int(round(sec or 0))
    if sec < 60:
        return "%d초" % sec
    if sec < 3600:
        return "%d분 %d초" % (sec // 60, sec % 60)
    return "%d시간 %d분" % (sec // 3600, (sec % 3600) // 60)


def _short_home(p):
    h = os.path.expanduser("~")
    return "~" + p[len(h):] if h and h != "/" and p.startswith(h + "/") else p


def _join_ids(ids, limit=6):
    ids = list(ids)
    s = " · ".join(ids[:limit])
    return s + (" … 외 %d" % (len(ids) - limit) if len(ids) > limit else "")


CODE_ORDER = ("known_fail", "skipped_by_test", "refused", "prep_failed", "not_run", "writes_prod", "manual_only", "orphan")
CODE_HINT = {"skipped_by_test": "→ ai", "prep_failed": "→ ai",
             "writes_prod": "운영에 씀 — 기본 거부 · --allow-writes-prod(대상 prod 만) · → user",
             "manual_only": "기본 제외 · --include-manual · → user",
             "orphan": "기본 제외 · 부르는 곳 없음"}
PER_ITEM_CODES = ("known_fail", "skipped_by_test", "refused", "prep_failed")


def tally(S):
    T = S.get("tests", [])
    ran = [t for t in T if t.get("verdict") in ("pass", "fail") or t.get("code") in
           ("known_fail", "skipped_by_test")]
    c = {"pass": sum(1 for t in T if t.get("verdict") == "pass"),
         "cached": sum(1 for t in T if t.get("verdict") == "pass" and t.get("cached_from")),
         "fail": sum(1 for t in T if t.get("verdict") == "fail"),
         "unv": sum(1 for t in T if t.get("verdict") == "unverified" and t.get("code") != "helper"),
         "ip": sum(int((t.get("counts") or {}).get("pass", 0)) for t in ran),
         "if": sum(int((t.get("counts") or {}).get("fail", 0)) for t in ran),
         "is": sum(int((t.get("counts") or {}).get("skip", 0)) for t in ran)}
    return c


def render_summary(S, show_all=False):
    T = S.get("tests", [])
    n = S.get("run")
    tree = (S.get("tree") or "")[:7] or "tree?"
    dirty = "+dirty" if S.get("dirty") else ""
    fin = S.get("finished_at")
    el = (fin or time.time()) - float(S.get("started_at") or 0)
    head = "점검(로컬) #%s · %s%s · %s%s · %d/%d" % (n, tree, dirty, _dur(el), "" if fin else "(진행 중)",
                                                  S.get("selected", 0), S.get("total", 0))
    c = tally(S)
    top = [head]
    # 실제로 돌았거나(원문 있음) 캐시로 센 시험 가운데 운영을 치는 것
    prod = [t for t in T if (t.get("env") == "prod" or t.get("writes_prod")) and t.get("raw")]
    if prod:
        # 통과한 시험은 나열하지 않는다 — id 는 실패·③ 인 것만(개수는 전부)
        bad_ids = [t["id"] for t in prod if t.get("verdict") != "pass"]
        top.append("⚠ %s: %d개%s" % (PROD_MARK, len(prod),
                                     (" — 실패·③ %s" % _join_ids(bad_ids, 5)) if bad_ids else ""))
    top.append("시험 통과 %d%s · 실패 %d · ③ %d │ 항목 통과 %d · 실패 %d · SKIP %d%s" % (
        c["pass"], (" (캐시 %d)" % c["cached"]) if c["cached"] else "", c["fail"], c["unv"],
        c["ip"], c["if"], c["is"], " · 중단됨" if S.get("interrupted") else ""))
    body = []
    for t in T:
        if t.get("verdict") != "fail":
            continue
        cnt = t.get("counts") or {}
        desc = "실패 %s (rc %s)" % (cnt.get("fail", 0), t.get("rc"))
        if t.get("note"):
            desc += " · " + t["note"]
        ff = t.get("first_fail") or ""
        line = "✖ %s %s%s   » tests/run.sh show %s %s" % (t["id"], redact(desc or ""),
                                                          (" — " + ff) if ff else "", n, t["id"])
        body.append(line)
    groups = {}
    for t in T:
        if t.get("verdict") == "unverified":
            groups.setdefault(t.get("code") or "?", []).append(t)
    for code in CODE_ORDER + tuple(k for k in groups if k not in CODE_ORDER and k != "helper"):
        g = groups.get(code)
        if not g:
            continue
        hint = CODE_HINT.get(code, "")
        if code in PER_ITEM_CODES:
            for t in g:
                body.append("③ %s %s %s%s" % (code, t["id"], redact(t.get("note") or ""), (" " + hint) if hint else ""))
        else:
            if code == "not_run":
                hint = g[0].get("note") or ""
            body.append("③ %s %d — %s%s" % (code, len(g), _join_ids([t["id"] for t in g]),
                                            (" (%s)" % hint) if hint else ""))
    nt = S.get("no_test") or []
    if nt:
        body.append("③ no_test %d — %s (→ ai)" % (len(nt), _join_ids(nt, 5)))
    helpers = groups.get("helper") or []
    if helpers:
        body.append("· helper %d 는 부르는 시험 안에서 돎(따로 안 돌림)" % len(helpers))
    for dinfo in S.get("diff") or []:
        body.append("· diff %s · 바뀐 파일 %d" % (dinfo.get("spec"), dinfo.get("files", 0)))
    tail = []
    if n is not None:
        tail.append("원문 %s/%s/raw/ (0600) · 보고 » tests/run.sh report %s --md" % (_short_home(runs_dir()), n, n))
    tail.append(FOOTER)
    budget = SUMMARY_MAX - len(top) - len(tail)
    if not show_all and len(body) > budget:
        cut = budget - 1
        rest = len(body) - cut
        body = body[:cut] + ["… 외 %d줄 » tests/run.sh summary %s --all" % (rest, n)]
    return "\n".join(redact(x) for x in top + body + tail)


def render_report(S):
    T = S.get("tests", [])
    n = S.get("run")
    c = tally(S)
    tree = (S.get("tree") or "")[:7] or "tree?"
    when = _dt.datetime.fromtimestamp(float(S.get("started_at") or 0)).strftime("%Y-%m-%d %H:%M")
    fin = S.get("finished_at")
    el = (fin or time.time()) - float(S.get("started_at") or 0)
    sel = ", ".join("`%s`" % s for s in S.get("selectors") or []) or "전체"
    L = ["### 점검(로컬) #%s 보고 조각" % n,
         "",
         "- 트리 `%s`%s · %s · %s · 카탈로그 `%s`(sha256 %s) · 선택 %s · 고름 %d/%d" % (
             tree, "+dirty" if S.get("dirty") else "", when, _dur(el), _short_home(S.get("catalog") or "?"),
             (S.get("catalog_sha256") or "?")[:7], sel, S.get("selected", 0), S.get("total", 0)),
         "- 숫자는 `tests/run.sh` 가 원문 끝의 합계 줄과 종료 코드로 센 것이다. %s." % FOOTER,
         ""]
    prod = [t["id"] for t in T if (t.get("env") == "prod" or t.get("writes_prod")) and t.get("raw")]
    L.append("**① 안전한 이유(증거)**")
    passed = [t for t in T if t.get("verdict") == "pass"]
    if passed:
        L.append("- 통과 %d개 · 항목 통과 %d · SKIP %d (요약 합계)" % (c["pass"], c["ip"], c["is"]))
        for t in passed:
            cnt = t.get("counts") or {}
            src = ("캐시 #%s" % t.get("cached_from")) if t.get("cached_from") else ("rc %s" % t.get("rc"))
            L.append("  - `%s` 통과 %s · 실패 %s · SKIP %s (%s, 형식 %s) — 원문 `%s/%s/%s`" % (
                t["id"], cnt.get("pass", 0), cnt.get("fail", 0), cnt.get("skip", 0), src, t.get("format"),
                _short_home(runs_dir()), t.get("raw_run") or n, t.get("raw") or "?"))
    else:
        L.append("- (통과한 시험 없음)")
    if prod:
        L.append("- 참고: %s — %s" % (PROD_MARK, _join_ids(prod, 10)))
    L += ["", "**② 불안전한 이유**"]
    fails = [t for t in T if t.get("verdict") == "fail"]
    if fails:
        for t in fails:
            cnt = t.get("counts") or {}
            L.append("- ✖ `%s` 실패 %s (rc %s)%s%s" % (
                t["id"], cnt.get("fail", 0), t.get("rc"), (" · " + t["note"]) if t.get("note") else "",
                (" — " + t["first_fail"]) if t.get("first_fail") else ""))
    else:
        L.append("- (실패 0)")
    L += ["", "**③ 미검증**"]
    unv = [t for t in T if t.get("verdict") == "unverified" and t.get("code") != "helper"]
    if unv or S.get("no_test"):
        for code in CODE_ORDER + tuple(sorted({t.get("code") for t in unv} - set(CODE_ORDER))):
            for t in unv:
                if t.get("code") == code:
                    L.append("- %s `%s` %s" % (code, t["id"], t.get("note") or ""))
        for f in S.get("no_test") or []:
            L.append("- no_test `%s` — 어느 시험의 covers 에도 없음" % f)
    else:
        L.append("- (없음)")
    helpers = [t["id"] for t in T if t.get("code") == "helper"]
    if helpers:
        L.append("- (정보) helper %d 는 부르는 시험 안에서 돎: %s" % (len(helpers), _join_ids(helpers, 10)))
    L += ["", "**④ 다음 조치**"]
    nxt = []
    for t in fails:
        nxt.append("- `tests/run.sh show %s %s` 로 실패 조각을 보고 고친 뒤 `tests/run.sh run --select id:%s`" % (n, t["id"], t["id"]))
    codes = {t.get("code") for t in unv}
    if "skipped_by_test" in codes:
        nxt.append("- skipped_by_test: 필수 시험의 SKIP 을 없앨 조건(검토 계정·환경)을 채우거나 시험을 보완한다(AI)")
    if "known_fail" in codes:
        nxt.append("- known_fail: 만료일 전에 원인을 고치거나 사용자 승인으로 연장한다(완화는 사용자 승인)")
    if "refused" in codes:
        nxt.append("- 시작 거부(rc 3): 가드 사유(R-73 사용자 활동·R-80 디스크·동시 실행)가 풀린 뒤 다시 돌린다")
    if "prep_failed" in codes:
        nxt.append("- 준비 실패(rc 4): 원문으로 준비 단계 오류를 확인한다")
    if "manual_only" in codes:
        nxt.append("- manual_only: 사용자가 `!` 로 실행하고 결과를 보고서에 붙인다")
    if "writes_prod" in codes:
        nxt.append("- writes_prod: 운영에 쓰는 시험 — 기본 거부. 운영 대상이 맞으면 `--allow-writes-prod` 로 다시(대상 temp·unknown 은 그래도 거부)")
    if S.get("no_test"):
        nxt.append("- no_test: 바뀐 코드 파일을 지키는 시험·카탈로그 covers 를 더한다(AI)")
    if S.get("dirty"):
        nxt.append("- 작업 트리가 dirty 라 캐시를 쓰지 않았다. 커밋 기준 증거가 필요하면 커밋 뒤 다시 돌린다")
    L += nxt or ["- (없음)"]
    return "\n".join(redact(x) for x in L)


# ─── show ─────────────────────────────────────────────────────────────────
def cmd_show(a):
    S = resolve_run(a.run)
    t = next((x for x in S.get("tests", []) if x.get("id") == a.id), None)
    if t is None:
        raise UsageError("#%s 에 %s 가 없다" % (S.get("run"), a.id))
    if not t.get("raw"):
        print("#%s %s · 원문 없음 — %s" % (S.get("run"), a.id, redact(t.get("note") or "돌리지 않음")))
        return RC_OK
    rr = t.get("raw_run") or S.get("run")
    path = os.path.join(runs_dir(), str(rr), t["raw"])
    raw = read_text(path)
    lines = raw.split("\n")
    if lines and lines[-1] == "":
        lines.pop()
    N = len(lines)
    picks = []
    mode = ""
    if a.lines:
        m = re.match(r"^(\d+)-(\d+)$", a.lines)
        if not m:
            raise UsageError("--lines 는 a-b 꼴이다")
        lo, hi = max(1, int(m.group(1))), min(N, int(m.group(2)))
        picks = list(range(lo - 1, hi))
        mode = "줄 %d-%d" % (lo, hi)
    elif a.grep:
        try:
            rx = re.compile(a.grep)
        except re.error as ex:
            raise UsageError("--grep 정규식 오류: %s" % ex)
        # 가린 **뒤의** 줄에 맞춘다 — 원문에 맞추면 「tbox_session=a」「…=ab」 처럼 가린 값을 한 글자씩
        # 알아낼 수 있다(맞은 줄 수·줄 번호가 답이 된다). 보이는 줄과 맞추는 줄이 같아야 한다.
        picks = [i for i, ln in enumerate(lines) if rx.search(redact(ln))]
        mode = "grep %r %d줄(가린 뒤)" % (a.grep, len(picks))
    else:
        ctx = max(0, min(int(a.context), 60))
        fmarks = [i for i, ln in enumerate(lines) if RE_FAIL_MARK.search(ANSI_RE.sub("", ln)) and not is_noise(ln)]
        smarks = [i for i, ln in enumerate(lines) if RE_SKIP_MARK.search(ln)] if t.get("code") == "skipped_by_test" else []
        sl = t.get("summary_lineno")
        marks = fmarks + smarks + ([sl - 1] if sl else [])
        if not marks:
            picks = list(range(max(0, N - 30), N))
            mode = "표시 줄 없음 → 끝 30줄"
        else:
            keep = set()
            for i in marks:
                keep.update(range(max(0, i - ctx), min(N, i + ctx + 1)))
            picks = sorted(keep)
            mode = "실패 %d곳%s%s ±%d줄" % (len(fmarks), (" · SKIP %d곳" % len(smarks)) if smarks else "",
                                          " · 합계 줄" if sl else "", ctx)
    # 머리 줄 + 본문(구분선 포함)을 합쳐 SHOW_MAX 줄 이하로 자른다
    out, prev, shown = [], None, 0
    for i in picks:
        if prev is not None and i != prev + 1:
            if len(out) >= SHOW_MAX - 2:
                break
            out.append("      ⋯")
        if len(out) >= SHOW_MAX - 1:
            break
        s = redact(lines[i])
        if len(s) > LINE_CUT:
            s = s[:LINE_CUT] + "…(+%d자)" % (len(s) - LINE_CUT)
        out.append("%6d│ %s" % (i + 1, s))
        shown += 1
        prev = i
    more = len(picks) - shown
    print("#%s %s · rc %s · 원문 %d줄 · %s%s · 가림 적용%s" % (
        S.get("run"), a.id, t.get("rc"), N, mode, (" · 캐시 #%s 의 원문" % rr) if t.get("cached_from") else "",
        (" · %d줄 더 있음(--lines 로)" % more) if more > 0 else ""))
    for x in out:
        print(x)
    return RC_OK


# ─── list ─────────────────────────────────────────────────────────────────
def cmd_list(a, root):
    entries = load_catalog(a.catalog)
    selected, info = select(entries, a.select or [], root)
    run_, excl, wp = [], [], []
    wp_files = writes_prod_files(root, entries)
    for e in selected:
        why, _allowed = prod_write_decision(e, wp_files, root, a.allow_writes_prod)
        if why:
            wp.append((e, why))
            continue
        (excl if (e_status(e) in DEFAULT_EXCLUDED and not a.include_manual) else run_).append(e)
    idx = {e["id"]: i for i, e in enumerate(entries)}
    run_ = [e for _, e in sorted(((idx[e["id"]], e) for e in run_), key=run_order)]
    w = max([len(e["id"]) for e in selected] + [4])
    print("카탈로그 %s · 전체 %d · 고름 %d · 돌릴 것 %d · 기본 제외 %d · 선택 %s" % (
        _short_home(os.path.abspath(a.catalog)), len(entries), len(selected), len(run_), len(excl),
        " ∪ ".join(a.select) if a.select else "전체"))
    for dinfo in info["diff"]:
        print("diff %s · 바뀐 파일 %d" % (dinfo["spec"], dinfo["files"]))
    for e in run_:
        needs = ",".join(e_list(e, "needs")) or "-"
        print("  %s  %-13s %-11s %5ss  needs %s%s" % (e["id"].ljust(w), e.get("env", "?"), e_status(e),
                                                    e.get("est_sec", "?"), needs, "  [운영]" if is_prod(e) else ""))
    if wp:
        print("운영에 쓰는 시험 — 돌리지 않음(기본 거부 · --allow-writes-prod 와 대상 prod 일 때만) %d:" % len(wp))
        for e, why in wp:
            print("  %s  %-11s %s" % (e["id"].ljust(w), e_status(e), _snip(why, 80)))
    if excl:
        print("기본 제외(--include-manual 없이는 안 돌림) %d:" % len(excl))
        for e in excl:
            note = str(e.get("notes") or "").replace("\n", " ")
            print("  %s  %-11s %s" % (e["id"].ljust(w), e_status(e), _snip(note, 80) if note else ""))
    if info["no_test"]:
        print("③ no_test %d (바뀐 코드 파일 중 어느 covers 에도 없음): %s" % (len(info["no_test"]), _join_ids(info["no_test"], 12)))
    if any(is_prod(e) for e in run_):
        print("⚠ %s: %d개" % (PROD_MARK, sum(1 for e in run_ if is_prod(e))))
    out = outside_catalog(entries, root)
    if out:
        # 선택과 상관없이 카탈로그 전체와 대조한다 — 실리지 않은 시험은 어느 선택자로도 돌지 않는다
        print("⚠ 카탈로그 밖 시험 %d개(어느 cmd 도 부르지 않음 — tests/catalog.json 에 싣기): %s" % (len(out), _join_ids(out, 8)))
    return RC_OK


# ─── CLI ──────────────────────────────────────────────────────────────────
class _Parser(argparse.ArgumentParser):
    def error(self, message):
        self.print_usage(sys.stderr)
        sys.stderr.write("사용법 오류: %s\n" % message)
        sys.exit(RC_USAGE)


def _cli(argv):
    ap = _Parser(prog="tests/run.sh", description="로컬 요약기 — 카탈로그에서 고르고·돌리고·세고·줄여서 보인다")
    ap.add_argument("--root", required=True, help=argparse.SUPPRESS)
    sub = ap.add_subparsers(dest="cmd", parser_class=_Parser)
    p = sub.add_parser("list", help="고른 목록만(실행 0)")
    p.add_argument("--select", action="append")
    p.add_argument("--include-manual", action="store_true")
    p.add_argument("--allow-writes-prod", action="store_true")
    p.add_argument("--catalog")
    p = sub.add_parser("run", help="고른 시험을 돌리고 요약")
    p.add_argument("--select", action="append")
    p.add_argument("--include-manual", action="store_true")
    p.add_argument("--allow-writes-prod", action="store_true")
    p.add_argument("--fail-fast", action="store_true")
    p.add_argument("--fresh", action="store_true")
    p.add_argument("--catalog")
    p = sub.add_parser("show", help="실패 조각(한 번에 200줄 이하)")
    p.add_argument("run")
    p.add_argument("id")
    g = p.add_mutually_exclusive_group()
    g.add_argument("--context", type=int, default=8)
    g.add_argument("--lines")
    g.add_argument("--grep")
    p = sub.add_parser("summary", help="요약 다시 보기")
    p.add_argument("run")
    p.add_argument("--all", action="store_true")
    p = sub.add_parser("report", help="CLAUDE.md 3장 ①~④ 조각")
    p.add_argument("run")
    p.add_argument("--md", action="store_true")
    a = ap.parse_args(argv)
    if not a.cmd:
        ap.print_usage(sys.stderr)
        return RC_USAGE
    root = os.path.realpath(a.root)
    set_redact_root(root)
    if hasattr(a, "catalog"):
        a.catalog = a.catalog or os.path.join(root, "tests", "catalog.json")
    try:
        if a.cmd == "list":
            return cmd_list(a, root)
        if a.cmd == "run":
            return cmd_run(a, root)
        if a.cmd == "show":
            return cmd_show(a)
        if a.cmd == "summary":
            print(render_summary(resolve_run(a.run), show_all=a.all))
            return RC_OK
        if a.cmd == "report":
            print(render_report(resolve_run(a.run)))
            return RC_OK
    except UsageError as ex:
        sys.stderr.write("run.sh: %s\n" % ex)
        return RC_USAGE
    except Refuse as ex:
        sys.stderr.write("run.sh: %s\n" % ex)
        return RC_REFUSE
    return RC_USAGE


def _tool(argv):
    ap = _Parser(prog="results.py")
    sub = ap.add_subparsers(dest="cmd", parser_class=_Parser)
    p = sub.add_parser("judge")
    p.add_argument("file")
    p.add_argument("--rc", type=int, required=True)
    p.add_argument("--status", default="required")
    p.add_argument("--expires")
    p.add_argument("--reason", default="견본 사유")
    p.add_argument("--today")
    p = sub.add_parser("redact")
    p.add_argument("file")
    p = sub.add_parser("denoise")
    p.add_argument("file")
    a = ap.parse_args(argv)
    if not a.cmd:
        ap.print_usage(sys.stderr)
        return RC_USAGE
    text = read_text(a.file) if a.file != "-" else sys.stdin.read()
    if a.cmd == "redact":
        sys.stdout.write(redact(text))
        return RC_OK
    if a.cmd == "denoise":
        sys.stdout.write(denoise(text))
        return RC_OK
    entry = {"id": "fixture", "status": a.status}
    if a.status == "known_fail":
        entry["known_fail"] = {"reason": a.reason, "expires": a.expires}
    today = _parse_date(a.today) if a.today else None
    J = judge(text, a.rc, entry, today)
    c = J["counts"]
    print("verdict=%s code=%s pass=%d fail=%d skip=%d format=%s violation=%d" % (
        J["verdict"], J["code"] or "-", c["pass"], c["fail"], c["skip"], J["format"] or "-", 1 if J["violation"] else 0))
    return RC_OK


def main(argv):
    prev = os.umask(0o077)
    # run.sh 를 거치지 않고 불렸으면 지금 umask 를 시험 자식용으로 남긴다(run.sh 가 넘긴 값이 있으면 그것).
    os.environ.setdefault("QCHECK_CHILD_UMASK", "%04o" % prev)
    if argv and argv[0] == "cli":
        return _cli(argv[1:])
    return _tool(argv)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
