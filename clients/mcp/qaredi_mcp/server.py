"""Qaredi MCP 서버 — stdio (Q:F-8 조각 C).

Claude Desktop · Claude Code 가 이 프로그램을 띄워 표준입출력으로 말한다. 그래서 **표준출력에는
아무것도 찍지 않는다**(프로토콜이 깨진다). 토큰·본문은 어디에도 찍지 않는다.

도구 셋
  qaredi_whoami()                       토큰의 계정 이름·역할
  qaredi_write_page(title, markdown)    Markdown → 블록 문서 → 내 개인 공간에 **비공개** 페이지
  qaredi_read_page(id)                  페이지 → Markdown (고치기 창구는 없다 — 새 페이지로 다시 쓴다)
"""

# `from __future__ import annotations` 를 쓰지 않는다 — 그러면 인자 주석이 글자가 되어 FastMCP(mcp 1.12.x)
# 의 `Tool.from_function` 이 `issubclass(param.annotation, Context)` 에서 TypeError 로 죽는다(PC 실측 2026-09-29).
# Python 3.11+ 에서 `Annotated[...]`·`X | None` 은 실행 시에도 그대로 된다.
import json
from typing import Annotated, Any

from mcp.server.fastmcp import FastMCP
from mcp.server.fastmcp.exceptions import ToolError
from mcp.types import ToolAnnotations
from pydantic import Field

from .client import QarediClient, QarediError
from .markdown_doc import DocLimitError

mcp = FastMCP("qaredi_mcp")

_client: QarediClient | None = None


def _get_client() -> QarediClient:
    # 켜질 때가 아니라 처음 부를 때 환경 변수를 읽는다 — 설정이 틀려도 서버는 뜨고, 도구가 까닭을 말한다.
    global _client
    if _client is None:
        _client = QarediClient.from_env()
    return _client


def _run(fn, *args: Any) -> str:
    try:
        result = fn(_get_client(), *args)
    except DocLimitError as exc:
        raise ToolError(f"문서 상한({exc.limit}): {exc}") from None
    except QarediError as exc:
        raise ToolError(str(exc)) from None
    return json.dumps(result, ensure_ascii=False, indent=2)


@mcp.tool(
    name="qaredi_whoami",
    description=(
        "Qaredi 토큰이 누구의 것인지(계정 이름·역할) 확인한다. 토큰 값은 돌려주지 않는다.\n"
        "Show which Qaredi account (username, role) the configured token belongs to. Never returns the token."
    ),
    annotations=ToolAnnotations(
        title="Qaredi: 나는 누구", readOnlyHint=True, destructiveHint=False, idempotentHint=True, openWorldHint=True
    ),
)
def qaredi_whoami() -> str:
    return _run(lambda c: c.whoami_summary())


@mcp.tool(
    name="qaredi_write_page",
    description=(
        "Markdown 글을 Qaredi 커뮤니티 **내 개인 공간에 비공개 페이지**로 새로 만든다. 공개·게시는 사람이 화면에서 한다.\n"
        "Create a new PRIVATE page in my personal Qaredi community space from Markdown; publishing is done by a human in the web UI.\n"
        "지원: 제목(#~###) · 문단 · 목록(-, 1., 들여쓰기) · 할 일(- [ ]) · 인용(>) · 코드 펜스 · 구분선(---) · 표 · "
        "**굵게** *기울임* ~~취소~~ `코드` [링크](https://…). 이미지·HTML·각주는 글자로 남는다. "
        "상한: 블록 3000 · 표 200행×20열 · 목록 깊이 10 · 1MB. 돌려줌: {id, url, title, visibility, space, blocks}."
    ),
    annotations=ToolAnnotations(
        title="Qaredi: 비공개 페이지 쓰기", readOnlyHint=False, destructiveHint=False, idempotentHint=False, openWorldHint=True
    ),
)
def qaredi_write_page(
    title: Annotated[str, Field(description="페이지 제목 (서버 규칙: 1~200자, 줄바꿈 없음) · page title")],
    markdown: Annotated[str, Field(description="본문 Markdown · page body in Markdown")],
) -> str:
    return _run(lambda c, t, m: c.write_page(t, m), title, markdown)


@mcp.tool(
    name="qaredi_read_page",
    description=(
        "Qaredi 커뮤니티 페이지 하나를 읽어 Markdown 으로 돌려준다(내가 볼 수 있는 페이지만). 고치려면 새 페이지로 다시 쓴다.\n"
        "Read one Qaredi community page (only pages I may see) back as Markdown. To revise, write a new page."
    ),
    annotations=ToolAnnotations(
        title="Qaredi: 페이지 읽기", readOnlyHint=True, destructiveHint=False, idempotentHint=True, openWorldHint=True
    ),
)
def qaredi_read_page(
    id: Annotated[str, Field(description="페이지 id(UUID) 또는 …/community/p/<id> 주소 · page id or page URL")],  # noqa: A002
) -> str:
    return _run(lambda c, i: c.read_page_markdown(i), id)


def main() -> None:
    mcp.run()  # 기본 전송 stdio


if __name__ == "__main__":
    main()
