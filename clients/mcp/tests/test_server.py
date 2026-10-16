"""server.py 시험 — 도구 셋의 이름·설명·힌트와, 도구가 오류를 토큰 없이 ToolError 로 돌려주는지.

`mcp` 패키지가 설치돼 있으면 진짜 FastMCP 로, 없으면(이 저장소의 검토 문맥 — pypi 가 막혀 있다) 이 파일 안의
**최소 흉내**(`mcp.server.fastmcp.FastMCP.tool` · `ToolError` · `ToolAnnotations` · `pydantic.Field`)로 import 한다.
흉내로 돈 경우 stdio 전송·스키마 생성은 확인하지 못한 것이다(시험 이름에 `stub` 으로 드러난다).
"""

from __future__ import annotations

import importlib
import json
import os
import pathlib
import sys
import types
import unittest

PKG = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(PKG))
sys.path.insert(0, str(PKG / "tests"))

try:
    import mcp.server.fastmcp  # noqa: F401
    REAL_MCP = True
except ModuleNotFoundError as exc:
    if exc.name != "mcp":
        raise  # An installed but broken SDK must fail, never silently become a stub.
    REAL_MCP = False


def _install_stubs() -> None:
    class ToolError(Exception):
        pass

    class ToolAnnotations:
        def __init__(self, **kw):
            self.__dict__.update(kw)

    class FastMCP:
        def __init__(self, name):
            self.name = name
            self.tools = {}

        def tool(self, name=None, description=None, annotations=None, **_):
            def deco(fn):
                self.tools[name or fn.__name__] = {"fn": fn, "description": description, "annotations": annotations}
                return fn
            return deco

        def run(self):
            raise RuntimeError("stub")

    mods = {
        "mcp": types.ModuleType("mcp"),
        "mcp.server": types.ModuleType("mcp.server"),
        "mcp.server.fastmcp": types.ModuleType("mcp.server.fastmcp"),
        "mcp.server.fastmcp.exceptions": types.ModuleType("mcp.server.fastmcp.exceptions"),
        "mcp.types": types.ModuleType("mcp.types"),
    }
    mods["mcp.server.fastmcp"].FastMCP = FastMCP
    mods["mcp.server.fastmcp.exceptions"].ToolError = ToolError
    mods["mcp.types"].ToolAnnotations = ToolAnnotations
    sys.modules.update(mods)
    if "pydantic" not in sys.modules:
        try:
            import pydantic  # noqa: F401
        except ImportError:
            pyd = types.ModuleType("pydantic")
            pyd.Field = lambda *a, **k: None
            sys.modules["pydantic"] = pyd


if not REAL_MCP:
    _install_stubs()

server = importlib.import_module("qaredi_mcp.server")
from mcp.server.fastmcp.exceptions import ToolError  # noqa: E402

import test_client as tc  # noqa: E402  (가짜 창구 서버를 함께 쓴다)


class ServerTools(unittest.TestCase):
    def _tools(self):
        if REAL_MCP:
            import asyncio
            listed = asyncio.run(server.mcp.list_tools())
            return {t.name: {"description": t.description, "annotations": t.annotations} for t in listed}
        return server.mcp.tools

    def test_stub_or_real_tools(self):
        tools = self._tools()
        self.assertEqual(set(tools), {"qaredi_whoami", "qaredi_write_page", "qaredi_read_page", "qaredi_search_pages", "qaredi_list_travel_plans", "qaredi_read_travel_plan", "qaredi_save_travel_plan", "qaredi_link_travel_record", "qaredi_list_work_projects", "qaredi_list_work_documents", "qaredi_read_work_document", "qaredi_update_work_document", "qaredi_create_work_document"})
        for name, t in tools.items():
            with self.subTest(tool=name):
                lines = t["description"].split("\n")
                self.assertRegex(lines[0], r"[가-힣]")       # 한국어 한 줄
                self.assertRegex(lines[1], r"^[A-Za-z]")    # 영어 한 줄
        self.assertIn("비공개", tools["qaredi_write_page"]["description"])
        self.assertIn("사람이 화면에서", tools["qaredi_write_page"]["description"])
        self.assertIn("PRIVATE", tools["qaredi_write_page"]["description"])
        self.assertTrue(tools["qaredi_whoami"]["annotations"].readOnlyHint)
        self.assertFalse(tools["qaredi_write_page"]["annotations"].readOnlyHint)
        self.assertFalse(tools["qaredi_write_page"]["annotations"].destructiveHint)
        self.assertTrue(tools["qaredi_search_pages"]["annotations"].readOnlyHint)
        self.assertFalse(tools["qaredi_search_pages"]["annotations"].destructiveHint)


class ServerCalls(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        tc.ClientTest.setUpClass()
        cls.base = tc.ClientTest.base

    @classmethod
    def tearDownClass(cls):
        tc.ClientTest.tearDownClass()

    def setUp(self):
        tc.Fake.hits = []
        tc.Fake.mode = {}
        self._old = dict(os.environ)
        os.environ["QAREDI_URL"] = self.base
        os.environ["QAREDI_TOKEN"] = tc.TOKEN
        server._client = None

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self._old)
        server._client = None

    def test_whoami_tool(self):
        out = json.loads(server.qaredi_whoami())
        self.assertEqual(out, {"username": "claude_employee01", "role": "Employee"})

    def test_write_and_read_tools(self):
        out = json.loads(server.qaredi_write_page("제목", "# 안녕\n\n본문"))
        self.assertEqual(out["visibility"], "private")
        self.assertEqual(out["url"], self.base + "/community/p/" + tc.PAGE_ID)
        back = json.loads(server.qaredi_read_page(tc.PAGE_ID))
        self.assertEqual(back["markdown"], "## 안녕")

    def test_errors_are_tool_errors_without_secrets(self):
        tc.Fake.mode["/tbox/api/whoami"] = (401, {"error": "만료된 API 토큰입니다"})
        with self.assertRaises(ToolError) as cm:
            server.qaredi_whoami()
        self.assertNotIn(tc.TOKEN, str(cm.exception))
        with self.assertRaises(ToolError) as cm:
            server.qaredi_write_page("t", "\n\n".join([tc.BODY_SECRET] * 3001))
        self.assertIn("blocks", str(cm.exception))
        self.assertNotIn(tc.BODY_SECRET, str(cm.exception))

    def test_search_tool(self):
        out = server.qaredi_search_pages("회의")
        self.assertEqual(out.split("\n")[0], "주간 회의 — 내 공간 — 이번 주 회의 내용 — " + tc.PAGE_ID)
        self.assertEqual(server.qaredi_search_pages("회의", limit=1).count("\n"), 0)
        self.assertNotIn(tc.TOKEN, out)

    def test_search_tool_scope_403_is_text(self):
        tc.Fake.mode["/community/api/search?q=%ED%9A%8C%EC%9D%98"] = (403, {"error": "token_scope"})
        out = server.qaredi_search_pages("회의")          # 예외가 아니다
        self.assertIn("HTTP 403", out)
        self.assertIn("token_scope", out)
        self.assertNotIn(tc.TOKEN, out)

    def test_search_tool_401_is_tool_error_without_token(self):
        tc.Fake.mode["/community/api/search?q=%ED%9A%8C%EC%9D%98"] = (401, {"error": "만료된 API 토큰입니다"})
        with self.assertRaises(ToolError) as cm:
            server.qaredi_search_pages("회의")
        self.assertNotIn(tc.TOKEN, str(cm.exception))

    def test_whoami_tool_scopes_held(self):
        tc.Fake.mode["/tbox/api/whoami"] = (200, {"username": "claude_employee01", "role": "Employee",
                                                  "token_scopes_held": ["community.read", "community.write"]})
        out = json.loads(server.qaredi_whoami())
        self.assertEqual(out["token_scopes_held"], ["community.read", "community.write"])

    def test_missing_token_env(self):
        os.environ.pop("QAREDI_TOKEN")
        with self.assertRaises(ToolError) as cm:
            server.qaredi_whoami()
        self.assertIn("QAREDI_TOKEN", str(cm.exception))


if __name__ == "__main__":
    unittest.main()
