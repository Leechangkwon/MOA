"""HTTP 클라이언트 시험 — 이 PC 안 가짜 서버(127.0.0.1:8404, 막혀 있으면 8405)로. 네트워크 밖으로 나가지 않는다.

확인하는 것: 창구 넷만 부른다 · 헤더(X-API-Token · User-Agent) · POST 본문 키는 title·doc 둘 ·
리다이렉트를 따라가지 않는다 · 오류 문장에 토큰·본문이 없다 · 주소·id·토큰 모양 검사.
"""

from __future__ import annotations

import json
import os
import pathlib
import sys
import tempfile
import threading
import traceback
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

PKG = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PKG))

from qaredi_mcp.client import (  # noqa: E402
    QarediClient, QarediError, USER_AGENT, normalize_base_url, page_id_from, read_token_file,
)

TOKEN = "tok_TEST_0123456789abcdef_SECRET"
PAGE_ID = "1b4e28ba-2fa1-41d2-883f-0016d3cca427"
BODY_SECRET = "본문에만있는문장XYZ"
WRONG_TOKEN = "wrong_token_value_123"
PAGE_ID2 = "2c5f39cb-3fb2-42e3-994f-1127e4ddb538"
SEARCH_RESULTS = [
    {"id": PAGE_ID, "title": "주간 회의", "icon": None, "space": {"kind": "personal", "name": "내 공간"},
     "path": [], "snippet": "이번 주\n회의 내용", "in_title": True},
    {"id": "not-a-uuid", "title": "버려질 행", "space": {"kind": "team"}, "snippet": "", "in_title": False},
    {"id": PAGE_ID2.upper(), "title": "회의록\r\n둘째", "icon": "📝", "space": {"kind": "team", "name": ""},
     "path": [{"id": PAGE_ID, "title": "주간 회의"}], "snippet": "", "in_title": True},
]


class Fake(BaseHTTPRequestHandler):
    hits: list[tuple[str, str, dict, bytes]] = []
    mode: dict = {}

    def log_message(self, *a):  # 조용히
        pass

    def _send(self, status, obj, headers=None):
        raw = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def _handle(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n) if n else b""
        Fake.hits.append((self.command, self.path, {k.lower(): v for k, v in self.headers.items()}, body))
        m = Fake.mode.get(self.path)
        if m:
            return self._send(*m)
        if self.headers.get("x-api-token") != TOKEN:
            return self._send(401, {"error": "API 토큰이 올바르지 않습니다"})
        if self.command == "GET" and self.path == "/tbox/api/whoami":
            return self._send(200, {"username": "claude_employee01", "role": "Employee", "team_id": 1, "upload_levels": []})
        if self.command == "GET" and self.path == "/community/api/me/space":
            return self._send(200, {"space": {"id": 7, "name": "내 공간", "kind": "personal"}, "created": False})
        if self.command == "POST" and self.path == "/community/api/pages":
            req = json.loads(body)
            return self._send(201, {"page": {
                "id": PAGE_ID, "title": req["title"], "visibility": "private", "block_count": len(req["doc"]["blocks"]),
                "space": {"id": 7, "kind": "personal", "name": "내 공간"}, "parent_id": None,
                "url": "/community/p/" + PAGE_ID,
            }})
        if self.command == "GET" and self.path == "/community/api/pages/" + PAGE_ID:
            return self._send(200, {"page": {"id": PAGE_ID, "title": "t", "visibility": "private", "doc": {
                "schema": 1, "blocks": [{"id": "a", "type": "heading", "level": 2, "text": [{"t": "안녕", "m": {}}]}]}}})
        if self.command == "GET" and self.path.startswith("/community/api/search?"):
            from urllib.parse import parse_qs, urlsplit
            q = parse_qs(urlsplit(self.path).query).get("q", [""])[0]
            return self._send(200, {"q": q, "comments": [], "results": SEARCH_RESULTS})
        return self._send(404, {"error": "찾을 수 없습니다"})

    do_GET = do_POST = do_PATCH = do_DELETE = _handle


class ClientTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        os.environ["no_proxy"] = os.environ["NO_PROXY"] = "127.0.0.1,localhost"
        last = None
        for port in (8404, 8405):
            try:
                cls.srv = ThreadingHTTPServer(("127.0.0.1", port), Fake)
                break
            except OSError as e:
                last = e
        else:
            raise last
        cls.base = f"http://127.0.0.1:{cls.srv.server_address[1]}"
        cls.th = threading.Thread(target=cls.srv.serve_forever, daemon=True)
        cls.th.start()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()
        cls.srv.server_close()

    def setUp(self):
        Fake.hits = []
        Fake.mode = {}
        self.c = QarediClient(self.base, TOKEN, timeout=5)

    def assertNoSecret(self, text):
        self.assertNotIn(TOKEN, text)
        self.assertNotIn(BODY_SECRET, text)

    def test_whoami_only_name_and_role(self):
        out = self.c.whoami_summary()
        self.assertEqual(out, {"username": "claude_employee01", "role": "Employee"})
        method, path, headers, _ = Fake.hits[0]
        self.assertEqual((method, path), ("GET", "/tbox/api/whoami"))
        self.assertEqual(headers.get("x-api-token"), TOKEN)
        self.assertEqual(headers.get("user-agent"), USER_AGENT)
        self.assertTrue(USER_AGENT.startswith("qaredi-mcp/"))
        self.assertNoSecret(json.dumps(out))

    def test_write_page_flow(self):
        out = self.c.write_page("주간 보고", "# 제목\n\n" + BODY_SECRET + "\n\n- a\n- b")
        self.assertEqual([(m, p) for m, p, _, _ in Fake.hits],
                         [("GET", "/community/api/me/space"), ("POST", "/community/api/pages")])
        sent = json.loads(Fake.hits[1][3])
        self.assertEqual(set(sent), {"title", "doc"})
        self.assertEqual(sent["doc"]["schema"], 1)
        self.assertEqual(Fake.hits[1][2].get("content-type"), "application/json; charset=utf-8")
        self.assertEqual(out["id"], PAGE_ID)
        self.assertEqual(out["url"], self.base + "/community/p/" + PAGE_ID)
        self.assertEqual(out["visibility"], "private")
        self.assertEqual(out["blocks"], 4)
        self.assertNoSecret(json.dumps(out, ensure_ascii=False))

    def test_limit_error_stops_before_any_request(self):
        from qaredi_mcp.markdown_doc import DocLimitError
        with self.assertRaises(DocLimitError):
            self.c.write_page("t", "\n\n".join(["p"] * 3001))
        self.assertEqual(Fake.hits, [])

    def test_read_page_markdown(self):
        out = self.c.read_page_markdown(PAGE_ID.upper())
        self.assertEqual(Fake.hits[0][1], "/community/api/pages/" + PAGE_ID)
        self.assertEqual(out["markdown"], "## 안녕")
        out = self.c.read_page_markdown("https://changkwon.mso.so/community/p/" + PAGE_ID)
        self.assertEqual(out["id"], PAGE_ID)

    def test_read_page_rejects_bad_id_without_request(self):
        for bad in ("../../tbox/api/files", PAGE_ID + "/comments", "1", "", "https://evil.example/x/" + PAGE_ID):
            with self.subTest(bad=bad), self.assertRaises(QarediError):
                self.c.read_page_markdown(bad)
        self.assertEqual(Fake.hits, [])

    def test_validation_error_message(self):
        Fake.mode["/community/api/pages"] = (400, {"error": "요청이 올바르지 않습니다", "errors": [
            {"path": "blocks[0].text[0].t", "reason": "too_long"}]})
        with self.assertRaises(QarediError) as cm:
            self.c.write_page("t", BODY_SECRET)
        msg = str(cm.exception)
        self.assertEqual(cm.exception.status, 400)
        self.assertIn("blocks[0].text[0].t:too_long", msg)
        self.assertNoSecret(msg)

    def test_scope_and_auth_errors(self):
        Fake.mode["/tbox/api/whoami"] = (403, {"error": "이 토큰의 범위에 없는 창구입니다", "reason": "token_scope"})
        with self.assertRaises(QarediError) as cm:
            self.c.whoami()
        self.assertEqual((cm.exception.status, cm.exception.reason), (403, "token_scope"))
        self.assertIn("커뮤니티 글쓰기", str(cm.exception))
        bad = QarediClient(self.base, "wrong_token_value_123", timeout=5)
        with self.assertRaises(QarediError) as cm:
            bad.my_space()
        self.assertEqual(cm.exception.status, 401)
        self.assertNotIn("wrong_token_value_123", str(cm.exception))
        self.assertNoSecret(str(cm.exception))

    # ── Q:F-10 조각 C — 검색 · whoami 범위 ──────────────────────────────────

    def test_search_lines_and_request(self):
        out = self.c.search_pages_text("회의 & 결정/../x?y=1", 10)
        self.assertEqual(out.split("\n"), [
            "주간 회의 — 내 공간 — 이번 주 회의 내용 — " + PAGE_ID,
            "회의록 둘째 — team —  — " + PAGE_ID2,
        ])
        self.assertEqual(len(Fake.hits), 1)
        method, path, headers, body = Fake.hits[0]
        self.assertEqual(method, "GET")
        # 검색어는 쿼리 값 하나로만 — 경로는 고정, 특수 문자는 인코딩된다
        self.assertTrue(path.startswith("/community/api/search?q="))
        self.assertNotIn("/../", path)
        self.assertEqual(path.count("?"), 1)
        self.assertNotIn("&", path)
        self.assertEqual(headers.get("x-api-token"), TOKEN)
        self.assertEqual(body, b"")
        self.assertNoSecret(out)

    def test_search_limit_and_empty(self):
        self.assertEqual(self.c.search_pages_text("회의", 1).count("\n"), 0)
        self.assertEqual(self.c.search_pages_text("회의", 0).count("\n"), 0)       # 1 로 올린다
        self.assertEqual(self.c.search_pages_text("회의", 999).count("\n"), 1)     # 20 으로 내린다(결과는 둘)
        Fake.hits = []
        self.assertIn("비었습니다", self.c.search_pages_text("  \n\t ", 10))
        self.assertEqual(Fake.hits, [])                                             # 빈 말은 부르지 않는다
        long_q = "가" * 300
        self.c.search_pages_text(long_q, 10)
        from urllib.parse import parse_qs, urlsplit
        self.assertEqual(parse_qs(urlsplit(Fake.hits[0][1]).query)["q"][0], "가" * 100)

    def test_search_no_results(self):
        global SEARCH_RESULTS
        old = SEARCH_RESULTS
        try:
            SEARCH_RESULTS = []
            self.assertIn("검색 결과가 없습니다", self.c.search_pages_text("없는말", 10))
        finally:
            SEARCH_RESULTS = old

    def test_search_scope_403_is_text_not_exception(self):
        # 계약 모양 {"error":"token_scope"} · 서버 실제 모양(한국어 문구) 둘 다
        for body in ({"error": "token_scope"}, {"error": "이 토큰의 범위에 없는 창구입니다 — 범위가 더 넓은 토큰을 발급하세요"}):
            with self.subTest(body=body):
                Fake.mode["/community/api/search?q=%ED%9A%8C%EC%9D%98"] = (403, body)
                out = self.c.search_pages_text("회의", 10)
                self.assertIsInstance(out, str)
                self.assertIn("HTTP 403", out)
                self.assertIn(body["error"], out)
                self.assertNoSecret(out)
                self.assertIn("커뮤니티 읽기", out)                   # 무엇이 필요한지 말한다

    def test_search_other_errors_raise_without_token(self):
        bad = QarediClient(self.base, WRONG_TOKEN, timeout=5)
        with self.assertRaises(QarediError) as cm:
            bad.search_pages_text("회의", 10)
        self.assertEqual(cm.exception.status, 401)
        self.assertNotIn(WRONG_TOKEN, str(cm.exception))

    def test_whoami_scopes_held_when_present(self):
        Fake.mode["/tbox/api/whoami"] = (200, {"username": "claude_employee01", "role": "Employee",
                                               "token_scope": "read+community.read",
                                               "token_scopes_held": ["read", "community.read"]})
        out = self.c.whoami_summary()
        self.assertEqual(out, {"username": "claude_employee01", "role": "Employee",
                               "token_scopes_held": ["read", "community.read"]})
        Fake.mode["/tbox/api/whoami"] = (200, {"username": "u", "role": "Employee", "token_scopes_held": "read"})
        self.assertNotIn("token_scopes_held", self.c.whoami_summary())   # 배열이 아니면 싣지 않는다

    def test_redirect_not_followed(self):
        Fake.mode["/tbox/api/whoami"] = (302, {"error": "x"}, {"Location": self.base + "/community/api/me/space"})
        with self.assertRaises(QarediError) as cm:
            self.c.whoami()
        self.assertEqual(cm.exception.status, 302)
        self.assertEqual([p for _, p, _, _ in Fake.hits], ["/tbox/api/whoami"])  # 따라간 요청 0
        self.assertNoSecret(str(cm.exception))

    def test_non_json_response(self):
        Fake.mode["/tbox/api/whoami"] = (200, ["list", "not", "object"])
        with self.assertRaises(QarediError):
            self.c.whoami()

    def test_connection_error_has_no_token(self):
        c = QarediClient("http://127.0.0.1:9", TOKEN, timeout=2)
        with self.assertRaises(QarediError) as cm:
            c.whoami()
        self.assertNoSecret(str(cm.exception))

    def test_repr_hides_token(self):
        self.assertNotIn(TOKEN, repr(self.c))
        self.assertNotIn(TOKEN, str(vars(self.c).get("base_url")))


class TokenSecrecy(ClientTest):
    """검토 RV-QF8-3 — capture_locals traceback(디버거·rich 류가 쓰는 것)에도 토큰 글자가 0."""

    def _locals_text(self, fn):
        try:
            fn()
        except Exception as exc:  # noqa: BLE001
            te = traceback.TracebackException(type(exc), exc, exc.__traceback__, capture_locals=True)
            return "".join(te.format())
        self.fail("예외가 나지 않았다")

    def test_capture_locals_has_no_token(self):
        cases = {
            "401": lambda: QarediClient(self.base, WRONG_TOKEN, timeout=5).my_space(),
            "400": lambda: self.c.write_page("t", BODY_SECRET),
            "302": lambda: self.c.whoami(),
            "conn": lambda: QarediClient("http://127.0.0.1:9", TOKEN, timeout=2).whoami(),
        }
        Fake.mode["/community/api/pages"] = (400, {"error": "x", "errors": [{"path": "p", "reason": "r"}]})
        Fake.mode["/tbox/api/whoami"] = (302, {"error": "x"}, {"Location": "/elsewhere"})
        for name, fn in cases.items():
            with self.subTest(case=name):
                text = self._locals_text(fn)
                self.assertIn("self =", text)            # 지역 변수가 실제로 찍혔는지(시험이 헛돌지 않게)
                self.assertNotIn(TOKEN, text)
                self.assertNotIn(WRONG_TOKEN, text)
                # 본문(Markdown)은 도구 입력 그 자체라 호출한 프레임의 지역 변수에 있다 — 이 시험의 대상이 아니다(토큰만)

    def test_bad_shape_token_not_in_locals(self):
        bad = "bad token " + "with space"
        text = self._locals_text(lambda: QarediClient(self.base, bad))
        client_frames = text[text.index("qaredi_mcp/client.py"):]   # 시험 자신의 지역 변수(bad)는 빼고 클라이언트 프레임만
        self.assertIn("secret = <token>", client_frames)
        self.assertNotIn(bad, client_frames)


class TokenFile(ClientTest):
    """검토 RV-QF8-2 — QAREDI_TOKEN_FILE 을 먼저 본다 · 0600 일반 파일 · 한 줄 · 심볼릭 링크 거부."""

    def setUp(self):
        super().setUp()
        self.tmp = tempfile.TemporaryDirectory(prefix="qaredi-mcp-test-")
        self.dir = pathlib.Path(self.tmp.name)
        self._env = dict(os.environ)
        os.environ["QAREDI_URL"] = self.base
        os.environ.pop("QAREDI_TOKEN", None)
        os.environ.pop("QAREDI_TOKEN_FILE", None)

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self._env)
        self.tmp.cleanup()

    def write(self, name, content, mode=0o600):
        f = self.dir / name
        f.write_bytes(content if isinstance(content, bytes) else content.encode())
        os.chmod(f, mode)
        return str(f)

    def test_file_ok_and_preferred_over_env(self):
        os.environ["QAREDI_TOKEN"] = "wrong_token_value_123"
        os.environ["QAREDI_TOKEN_FILE"] = self.write("tok", TOKEN + "\n")
        c = QarediClient.from_env()
        self.assertEqual(c.whoami_summary()["username"], "claude_employee01")
        os.environ["QAREDI_TOKEN_FILE"] = self.write("tok_crlf", TOKEN + "\r\n", 0o400)
        self.assertEqual(QarediClient.from_env().whoami_summary()["role"], "Employee")

    def test_env_still_works(self):
        os.environ["QAREDI_TOKEN"] = TOKEN
        self.assertEqual(QarediClient.from_env().whoami_summary()["role"], "Employee")

    @unittest.skipUnless(os.name == "posix", "권한 비트 검사는 POSIX 만")
    def test_rejects(self):
        good = self.write("good", TOKEN)
        link = self.dir / "link"
        os.symlink(good, link)
        bad = {
            "group_readable": self.write("g", TOKEN, 0o640),
            "world_readable": self.write("w", TOKEN, 0o644),
            "two_lines": self.write("two", TOKEN + "\n" + TOKEN + "\n"),
            "empty": self.write("empty", ""),
            "symlink": str(link),
            "directory": str(self.dir),
            "missing": str(self.dir / "nope"),
            "not_utf8": self.write("bin", b"\xff\xfe" + TOKEN.encode()),
        }
        for name, path in bad.items():
            with self.subTest(case=name):
                os.environ["QAREDI_TOKEN"] = TOKEN          # 파일이 틀리면 환경 변수로 넘어가지 않는다
                os.environ["QAREDI_TOKEN_FILE"] = path
                with self.assertRaises(QarediError) as cm:
                    QarediClient.from_env()
                self.assertNotIn(TOKEN, str(cm.exception))
        self.assertEqual(Fake.hits, [])

    def test_read_token_file_repr_hidden(self):
        s = read_token_file(self.write("t", TOKEN))
        self.assertEqual((repr(s), str(s)), ("<token>", "<token>"))


class Validation(unittest.TestCase):
    def test_base_url(self):
        self.assertEqual(normalize_base_url("https://changkwon.mso.so/"), "https://changkwon.mso.so")
        self.assertEqual(normalize_base_url("http://127.0.0.1:8404"), "http://127.0.0.1:8404")
        for bad in ("http://changkwon.mso.so", "ftp://x.y", "https://u:p@x.y", "https://x.y/tbox",
                    "https://x.y/?a=1", "https://x.y/#f", "changkwon.mso.so", "", "https://x.y:99999"):
            with self.subTest(bad=bad), self.assertRaises(QarediError):
                normalize_base_url(bad)

    def test_token_shape(self):
        for bad in ("", "short", "has space in token", "line\nbreak_token", "탭\t토큰값입니다요"):
            with self.subTest(bad=repr(bad)), self.assertRaises(QarediError) as cm:
                QarediClient("https://x.y", bad)
            if bad:
                self.assertNotIn(bad, str(cm.exception))

    def test_from_env(self):
        old = dict(os.environ)
        try:
            os.environ.pop("QAREDI_TOKEN", None)
            with self.assertRaises(QarediError):
                QarediClient.from_env()
            os.environ["QAREDI_TOKEN"] = TOKEN
            os.environ.pop("QAREDI_URL", None)
            self.assertEqual(QarediClient.from_env().base_url, "https://changkwon.mso.so")
        finally:
            os.environ.clear()
            os.environ.update(old)

    def test_page_id(self):
        self.assertEqual(page_id_from(" " + PAGE_ID.upper() + " "), PAGE_ID)
        with self.assertRaises(QarediError):
            page_id_from(PAGE_ID + "x")


if __name__ == "__main__":
    unittest.main()
