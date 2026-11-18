"""Qaredi 창구 클라이언트 (Q:F-8 조각 C) — 표준 라이브러리(urllib)만 쓴다.

문서 창구는 아래 다섯이다. 여행 확장은 고정 /community/api/travel-plans 경로와 검사한 UUID만 추가한다.
주소는 코드 상수 + 검사한 id · urlencode 한 검색어로만 만든다:
    GET  /tbox/api/whoami
    GET  /community/api/me/space
    POST /community/api/pages          {title, doc}
    GET  /community/api/pages/{uuid}
    GET  /community/api/search?q=…     (Q:F-10 — 검색어는 쿼리 값 하나로만, 경로에는 들어가지 않는다)

지키는 것
  · 토큰은 `QAREDI_TOKEN_FILE`(내 계정만 읽는 한 줄 파일 — 권장) 또는 환경 변수 `QAREDI_TOKEN` 로 받는다. 이 프로그램은 토큰을
    파일에 쓰지 않고, 로그·오류 문장·돌려주는 값에 넣지 않는다. 메모리에서는 `_Secret` 로 들고(repr·str 이 가려진다),
    값은 요청을 만드는 한 함수(`_build_request`) 안에서만 꺼낸다 — capture_locals traceback 에도 글자가 남지 않게(검토 RV-QF8-3).
  · 리다이렉트를 따라가지 않는다(3xx 는 오류) — 토큰 헤더가 다른 곳으로 따라가지 않게.
  · TLS 검증을 끄지 않는다(`ssl.create_default_context()`). http 는 이 PC 안(127.0.0.1·localhost·::1)만.
  · 본문(글 내용)을 오류 문장에 넣지 않는다 — 서버가 준 `error`·`reason`·검증 오류의 `path`·`reason` 만 옮긴다.
"""

from __future__ import annotations

import json
import os
import re
import stat
import uuid
import ssl
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

from . import __version__
from .markdown_doc import doc_to_markdown, markdown_to_doc

DEFAULT_URL = "https://changkwon.mso.so"
USER_AGENT = f"qaredi-mcp/{__version__}"
MAX_RESPONSE_BYTES = 4 * 1024 * 1024
TIMEOUT_SECONDS = 30
SEARCH_Q_MAX = 100          # 서버 `Http::queryText('q', 100)` 와 같은 상한
SEARCH_LIMIT_MAX = 20       # 서버가 페이지 결과를 최대 20건 준다

_UUID = re.compile(r"[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}")
_LOOPBACK = {"127.0.0.1", "localhost", "::1"}
_TOKEN_OK = re.compile(r"[\x21-\x7e]{8,512}")


class _Secret:
    """토큰 한 벌. repr·str 은 언제나 `<token>` — 지역 변수·속성으로 traceback 에 찍혀도 값이 나오지 않는다."""

    __slots__ = ("_v",)

    def __init__(self, value: str) -> None:
        self._v = value

    def __repr__(self) -> str:
        return "<token>"

    __str__ = __repr__

    def shape_ok(self) -> bool:
        return isinstance(self._v, str) and _TOKEN_OK.fullmatch(self._v) is not None

    def reveal(self) -> str:
        return self._v


def read_token_file(path: str) -> _Secret:
    """QAREDI_TOKEN_FILE — 일반 파일 · 심볼릭 링크 아님 · (POSIX) 내 것이고 다른 사람 권한 0(0600/0400) · 한 줄.

    검사는 연 파일 기술자로 한다(lstat 뒤 바꿔치기 틈을 두지 않는다). 오류 문장에는 경로만 — 내용은 넣지 않는다.
    """
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_NONBLOCK", 0)
    try:
        if os.path.islink(path):
            raise QarediError(f"QAREDI_TOKEN_FILE 이 심볼릭 링크입니다 — 실제 파일 경로를 적으세요: {path}")
        fd = os.open(path, flags)
    except QarediError:
        raise
    except OSError as exc:
        raise QarediError(f"QAREDI_TOKEN_FILE 을 열 수 없습니다({type(exc).__name__}) — 경로·권한 확인: {path}") from None
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode):
            raise QarediError(f"QAREDI_TOKEN_FILE 이 일반 파일이 아닙니다: {path}")
        if os.name == "posix":
            if hasattr(os, "getuid") and st.st_uid != os.getuid():
                raise QarediError(f"QAREDI_TOKEN_FILE 의 주인이 나(이 계정)가 아닙니다: {path}")
            if st.st_mode & 0o077:
                raise QarediError(
                    f"QAREDI_TOKEN_FILE 을 다른 사람이 읽거나 쓸 수 있습니다(권한 {stat.S_IMODE(st.st_mode):o}) — "
                    f"chmod 600 {path} 뒤 다시"
                )
        with os.fdopen(fd, "rb", closefd=True) as fh:
            fd = -1
            secret, ok = _one_line_secret(fh.read(1025))
    finally:
        if fd >= 0:
            os.close(fd)
    if not ok:
        raise QarediError(f"QAREDI_TOKEN_FILE 은 토큰 한 줄만 담습니다(끝 줄바꿈 하나까지 허용): {path}")
    return secret


def _one_line_secret(raw: bytes) -> tuple[_Secret, bool]:
    """파일 내용 → (_Secret, 모양 맞음). 값은 이 프레임을 넘어 글자로 나가지 않는다(예외도 여기서 던지지 않는다)."""
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        return _Secret(""), False
    if text.endswith("\r\n"):
        text = text[:-2]
    elif text.endswith("\n"):
        text = text[:-1]
    ok = len(raw) <= 1024 and "\n" not in text and "\r" not in text
    return _Secret(text), ok


class QarediError(Exception):
    """창구 호출 실패. 문장에는 토큰·본문이 들어가지 않는다."""

    def __init__(self, message: str, status: int | None = None, reason: str | None = None) -> None:
        super().__init__(message)
        self.status = status
        self.reason = reason


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401 — urllib 의 이름
        return None  # 따라가지 않는다 → HTTPError(3xx) 로 올라온다


def normalize_base_url(raw: str) -> str:
    """QAREDI_URL 검사 — https(또는 이 PC 안 http) · 호스트만 · 경로·쿼리·사용자 정보 없음."""
    try:
        u = urllib.parse.urlsplit(raw.strip())
        host = u.hostname
        _ = u.port  # 잘못된 포트면 여기서 ValueError
    except ValueError as exc:
        raise QarediError("QAREDI_URL 모양이 잘못됐습니다 — 예: https://changkwon.mso.so") from exc
    if u.scheme not in ("https", "http") or not host:
        raise QarediError("QAREDI_URL 은 https:// 로 시작하는 주소여야 합니다 — 예: https://changkwon.mso.so")
    if u.scheme == "http" and host not in _LOOPBACK:
        raise QarediError("QAREDI_URL 의 http:// 는 이 PC 안(127.0.0.1·localhost)만 됩니다 — 바깥 서버는 https:// 로")
    if u.username is not None or u.password is not None or u.query or u.fragment or u.path not in ("", "/"):
        raise QarediError("QAREDI_URL 에는 호스트(와 포트)만 적습니다 — 경로·쿼리·사용자 정보 없이")
    netloc = u.netloc.rsplit("@", 1)[-1]
    return f"{u.scheme}://{netloc}"


def page_id_from(value: str) -> str:
    """페이지 id(UUID) 또는 페이지 주소(…/community/p/<id>) → 소문자 UUID. 그 밖은 거절."""
    v = (value or "").strip()
    if _UUID.fullmatch(v):
        return v.lower()
    m = re.fullmatch(r"https?://[^/\s]+/community/p/(" + _UUID.pattern + r")/?", v)
    if m:
        return m.group(1).lower()
    raise QarediError("페이지 id 는 UUID(예: 1b4e28ba-2fa1-41d2-883f-0016d3cca427) 또는 …/community/p/<id> 주소입니다")


class QarediClient:
    def __init__(self, base_url: str, token: "str | _Secret", timeout: float = TIMEOUT_SECONDS) -> None:
        secret = token if isinstance(token, _Secret) else _Secret(token)
        del token  # 날 글자를 프레임에 남기지 않는다
        self.base_url = normalize_base_url(base_url)
        if not secret.shape_ok():
            # 값은 문장에 넣지 않는다
            raise QarediError("토큰이 비었거나 모양이 잘못됐습니다 — 내 계정에서 발급한 토큰을 그대로 넣으세요")
        self.__token = secret
        self.timeout = timeout
        ctx = ssl.create_default_context()
        self._opener = urllib.request.build_opener(_NoRedirect(), urllib.request.HTTPSHandler(context=ctx))

    def __repr__(self) -> str:  # 토큰이 repr 로 새지 않게
        return f"QarediClient(base_url={self.base_url!r}, token=***)"

    @classmethod
    def from_env(cls) -> "QarediClient":
        """QAREDI_TOKEN_FILE 을 먼저 본다(있으면 그것만 — 틀려도 환경 변수로 넘어가지 않는다). 없으면 QAREDI_TOKEN."""
        base = os.environ.get("QAREDI_URL", "") or DEFAULT_URL
        path = os.environ.get("QAREDI_TOKEN_FILE", "")
        if path:
            return cls(base, read_token_file(os.path.expanduser(path)))
        if os.environ.get("QAREDI_TOKEN", "") == "":
            raise QarediError(
                "QAREDI_TOKEN_FILE(권장) 또는 QAREDI_TOKEN 환경 변수가 없습니다 — Qaredi 「내 계정 → 프로그램 토큰」에서 "
                "범위 「커뮤니티 글쓰기」로 발급해 내 계정만 읽는 파일에 두고 그 경로를 MCP 설정의 env 에 넣으세요(README)"
            )
        return cls(base, _Secret(os.environ["QAREDI_TOKEN"]))

    # ── 문서 창구 ────────────────────────────────────────────────────────────

    def whoami(self) -> dict[str, Any]:
        return self._call("GET", "/tbox/api/whoami")

    def my_space(self) -> dict[str, Any]:
        return self._call("GET", "/community/api/me/space")

    def create_page(self, title: str, doc: dict[str, Any]) -> dict[str, Any]:
        return self._call("POST", "/community/api/pages", {"title": title, "doc": doc})

    def get_page(self, page_id: str) -> dict[str, Any]:
        return self._call("GET", "/community/api/pages/" + page_id_from(page_id))

    def search(self, q: str) -> dict[str, Any]:
        return self._call("GET", "/community/api/search?" + urllib.parse.urlencode({"q": q}))

    def page_url(self, page_id: str) -> str:
        return self.base_url + "/community/p/" + page_id_from(page_id)

    # Travel is opt-in: existing community scopes never grant these private endpoints.
    def list_travel_plans(self) -> dict[str, Any]:
        return self._call("GET", "/community/api/travel-plans")

    @staticmethod
    def _travel_id(value: str) -> str:
        if not isinstance(value, str) or not _UUID.fullmatch(value):
            raise QarediError("여행 계획과 기록의 id 는 UUID여야 합니다")
        return value.lower()

    def get_travel_plan(self, plan_id: str) -> dict[str, Any]:
        return self._call("GET", "/community/api/travel-plans/" + self._travel_id(plan_id))

    def save_travel_plan(self, plan: dict[str, Any], plan_id: str | None = None,
                         revision: int | None = None, request_id: str | None = None) -> dict[str, Any]:
        if not isinstance(plan, dict) or set(plan) - {"title", "destination", "starts_on", "ends_on", "notes", "steps"}:
            raise QarediError("계획은 title, destination, starts_on, ends_on, notes, steps만 받습니다")
        body = dict(plan)
        if not isinstance(body.get("title"), str) or not body["title"].strip():
            raise QarediError("여행 이름 title을 적어 주세요")
        steps = body.get("steps", [])
        if not isinstance(steps, list) or len(steps) > 100 or not all(isinstance(s, dict) for s in steps):
            raise QarediError("steps는 일정 객체를 최대 100개 담는 배열입니다")
        # New activities receive stable IDs in the saved result. Existing IDs are preserved.
        body["steps"] = [dict(step, id=step.get("id") or str(uuid.uuid4())) for step in steps]
        if plan_id is None:
            body["request_id"] = self._travel_id(request_id) if request_id else str(uuid.uuid4())
            path, method = "/community/api/travel-plans", "POST"
        else:
            if not isinstance(revision, int) or isinstance(revision, bool) or revision < 1:
                raise QarediError("먼저 계획을 읽고 현재 revision 번호를 넣어 주세요")
            body["revision"] = revision
            path, method = "/community/api/travel-plans/" + self._travel_id(plan_id), "PATCH"
        if len(json.dumps(body, ensure_ascii=False).encode("utf-8")) > 240000:
            raise QarediError("여행 계획이 너무 큽니다")
        return self._call(method, path, body)

    def link_travel_record(self, plan_id: str, post_id: str, step_id: str | None = None) -> dict[str, Any]:
        return self._call("POST", "/community/api/travel-plans/" + self._travel_id(plan_id) + "/records",
                          {"post_id": self._travel_id(post_id), "step_id": self._travel_id(step_id) if step_id else None})

    # ── 도구가 쓰는 흐름 ────────────────────────────────────────────────────

    def whoami_summary(self) -> dict[str, Any]:
        data = self.whoami()
        out: dict[str, Any] = {"username": data.get("username"), "role": data.get("role")}
        # Q:F-10 — 서버가 이 토큰이 가진 범위(원자 배열)를 주면 함께 보인다. 없으면(옛 서버) 칸을 만들지 않는다.
        held = data.get("token_scopes_held")
        if isinstance(held, list) and all(isinstance(x, str) for x in held):
            out["token_scopes_held"] = [x[:64] for x in held[:32]]
        return out

    def search_pages_text(self, q: str, limit: int = 10) -> str:
        """검색 → 「제목 — 공간 — snippet — id」 한 줄씩. 범위 밖 403 은 **예외가 아니라 문장**으로 돌려준다(C-1)."""
        query = _one_line(q if isinstance(q, str) else "")[:SEARCH_Q_MAX].strip()
        if query == "":
            return "찾을 말이 비었습니다 — 한 글자 이상 적으세요"
        try:
            n = int(limit)
        except (TypeError, ValueError):
            n = 10
        n = max(1, min(SEARCH_LIMIT_MAX, n))
        try:
            data = self.search(query)
        except QarediError as exc:
            if exc.status == 403:
                # 범위 밖 — 도구 오류가 아니라 까닭 문장으로(C-1). 검색 창구에는 행 단위 403 이 없다(볼 수 없는 글은 결과에서 빠진다).
                msg = str(exc)
                if "커뮤니티 읽기" not in msg:
                    msg += " · 검색에는 범위 「커뮤니티 읽기」 이상이 든 토큰이 필요합니다"
                return msg
            raise
        results = data.get("results") if isinstance(data.get("results"), list) else []
        lines = []
        for r in results:
            if not isinstance(r, dict):
                continue
            pid = str(r.get("id", ""))
            if not _UUID.fullmatch(pid):
                continue
            space = r.get("space") if isinstance(r.get("space"), dict) else {}
            space_name = space.get("name") if isinstance(space.get("name"), str) and space.get("name") else space.get("kind")
            lines.append(" — ".join([
                _one_line(r.get("title"))[:200] or "(제목 없음)",
                _one_line(space_name)[:100] or "(공간 없음)",
                _one_line(r.get("snippet"))[:300],
                pid.lower(),
            ]))
            if len(lines) >= n:
                break
        if not lines:
            return f"「{query}」 검색 결과가 없습니다(내가 볼 수 있는 페이지 안에서)"
        return "\n".join(lines)

    def list_work_projects(self, limit: int = 30, after_id: str | None = None) -> dict[str, Any]:
        return self._work_list('/community/api/work/projects', limit, after_id)

    def list_work_documents(self, parent_id: str, limit: int = 30, after_id: str | None = None) -> dict[str, Any]:
        return self._work_list('/community/api/work/documents', limit, after_id, parent_id)

    def _work_list(self, path: str, limit: int, after_id: str | None, parent_id: str | None = None) -> dict[str, Any]:
        if type(limit) is not int or not 1 <= limit <= 100:
            raise QarediError('한 번에 1~100개를 읽을 수 있습니다')
        query = {'limit': str(limit)}
        if after_id is not None:
            query['after_id'] = self._travel_id(after_id)
        if parent_id is not None:
            query['parent_id'] = page_id_from(parent_id)
        return self._call('GET', path + '?' + urllib.parse.urlencode(query))

    def read_work_document(self, page_id: str) -> dict[str, Any]:
        return self._call('GET', '/community/api/work/documents/' + page_id_from(page_id))

    def create_work_document(self, project_id: str, request_id: str, title: str,
                             blocks: list[dict[str, Any]], status: str = 'todo', section: str | None = None) -> dict[str, Any]:
        body = dict(project_id=page_id_from(project_id), request_id=page_id_from(request_id),
                    title=title, blocks=blocks, status=status)
        if section is not None:
            body['section'] = section
        return self._call('POST', '/community/api/work/documents', body)

    def update_work_document(self, page_id: str, doc_version: int,
                             replacements: list[dict[str, Any]] | None = None,
                             append: list[dict[str, Any]] | None = None) -> dict[str, Any]:
        if type(doc_version) is not int or not 1 <= doc_version <= 2147483646:
            raise QarediError('먼저 읽은 문서의 판 번호가 필요합니다')
        body = {'doc_version': doc_version}
        if replacements is not None:
            body['replacements'] = replacements
        if append is not None:
            body['append'] = append
        # One request only. A conflict or uncertain response must never cause a blind retry.
        return self._call('PATCH', '/community/api/work/documents/' + page_id_from(page_id), body)

    def list_whiteboards(self, project_id: str | None = None, kind: str | None = None,
                         limit: int = 30, after_id: str | None = None) -> dict[str, Any]:
        if type(limit) is not int or not 1 <= limit <= 100:
            raise QarediError('한 번에 1~100개를 읽을 수 있습니다')
        query = {'limit': str(limit)}
        if project_id is not None:
            query['project_id'] = page_id_from(project_id)
        if after_id is not None:
            query['after_id'] = page_id_from(after_id)
        if kind is not None:
            if kind != 'meeting':
                raise QarediError('지원하는 판 유형은 meeting입니다')
            query['kind'] = kind
        return self._call('GET', '/community/api/work/whiteboards?' + urllib.parse.urlencode(query))

    def _whiteboard(self, page_id: str, version_no: int | None, suffix: str = '', **args) -> dict[str, Any]:
        query = {}
        if version_no is not None:
            if type(version_no) is not int or not 1 <= version_no <= 2147483646:
                raise QarediError('판 번호를 확인해 주세요')
            query['version_no'] = str(version_no)
        for name, value in args.items():
            if type(value) is not int or value < 0 or value > 2147483646:
                raise QarediError('순번과 개수를 확인해 주세요')
            query[name] = str(value)
        path = '/community/api/work/whiteboards/' + page_id_from(page_id) + suffix
        return self._call('GET', path + ('?' + urllib.parse.urlencode(query) if query else ''))

    def read_whiteboard(self, page_id: str, version_no: int | None = None, after: int = 0) -> dict[str, Any]:
        return self._whiteboard(page_id, version_no, after=after)

    def read_whiteboard_events(self, page_id: str, version_no: int | None = None,
                               after_seq: int = 0, limit: int = 100) -> dict[str, Any]:
        if type(limit) is not int or not 1 <= limit <= 500:
            raise QarediError('한 번에 1~500개 기록을 읽을 수 있습니다')
        return self._whiteboard(page_id, version_no, '/events', after_seq=after_seq, limit=limit)

    def read_whiteboard_image(self, page_id: str, version_no: int | None = None) -> dict[str, Any]:
        return self._whiteboard(page_id, version_no, '/image')

    def save_meeting_minutes(self, page_id: str, whiteboard_version: int, doc_version: int,
                             request_id: str, minutes: dict[str, Any]) -> dict[str, Any]:
        for version in (whiteboard_version, doc_version):
            if type(version) is not int or not 1 <= version <= 2147483646:
                raise QarediError('현재 판 번호를 확인해 주세요')
        body = dict(whiteboard_version=whiteboard_version, doc_version=doc_version,
                    request_id=page_id_from(request_id), minutes=minutes)
        return self._call('POST', '/community/api/work/whiteboards/' + page_id_from(page_id) + '/minutes', body)

    def write_page(self, title: str, markdown: str) -> dict[str, Any]:
        doc = markdown_to_doc(markdown)             # 상한을 넘으면 DocLimitError — 부르기 전에 멈춘다
        self.my_space()                             # 개인 공간이 없으면 만든다(멱등) — POST 의 409 를 막는다
        data = self.create_page(title, doc)
        page = data.get("page") if isinstance(data.get("page"), dict) else {}
        pid = str(page.get("id", ""))
        space = page.get("space") if isinstance(page.get("space"), dict) else {}
        return {
            "id": pid,
            "url": self.page_url(pid) if _UUID.fullmatch(pid) else None,
            "title": page.get("title"),
            "visibility": page.get("visibility"),
            "space": {"kind": space.get("kind"), "name": space.get("name")},
            "blocks": page.get("block_count"),
        }

    def read_page_markdown(self, page_id: str) -> dict[str, Any]:
        data = self.get_page(page_id)
        page = data.get("page") if isinstance(data.get("page"), dict) else {}
        pid = str(page.get("id", ""))
        return {
            "id": pid,
            "url": self.page_url(pid) if _UUID.fullmatch(pid) else None,
            "title": page.get("title"),
            "visibility": page.get("visibility"),
            "markdown": doc_to_markdown(page.get("doc")),
        }

    # ── HTTP 한 벌 ──────────────────────────────────────────────────────────

    def _build_request(self, method: str, path: str, data: bytes | None) -> urllib.request.Request:
        """토큰 글자를 꺼내는 유일한 자리. 헤더 dict 는 이 프레임 밖으로 이름을 달고 나가지 않는다(예외도 던지지 않는다)."""
        req = urllib.request.Request(self.base_url + path, data=data, method=method, headers={
            "X-API-Token": self.__token.reveal(),
            "User-Agent": USER_AGENT,
            "Accept": "application/json",
        })
        if data is not None:
            req.add_header("Content-Type", "application/json; charset=utf-8")
        return req

    def _call(self, method: str, path: str, body: dict[str, Any] | None = None) -> dict[str, Any]:
        data = None
        if body is not None:
            data = json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        req = _Masked(self._build_request(method, path, data))
        try:
            with self._opener.open(req.obj, timeout=self.timeout) as resp:
                status = resp.status
                raw = resp.read(MAX_RESPONSE_BYTES + 1)
        except urllib.error.HTTPError as exc:
            status = exc.code
            try:
                raw = exc.read(MAX_RESPONSE_BYTES + 1)
            except Exception:  # noqa: BLE001 — 오류 본문을 못 읽어도 상태로 말한다
                raw = b""
            finally:
                exc.close()
            raise _http_error(status, raw, method, path) from None
        except urllib.error.URLError as exc:
            raise QarediError(f"{self.base_url} 에 닿지 못했습니다 — {exc.reason}") from None
        except (TimeoutError, OSError) as exc:
            raise QarediError(f"{self.base_url} 에 닿지 못했습니다 — {type(exc).__name__}") from None
        if len(raw) > MAX_RESPONSE_BYTES:
            raise QarediError("응답이 너무 큽니다(4MB 초과)", status)
        try:
            parsed = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise QarediError(f"{method} {path} 응답이 JSON 이 아닙니다(HTTP {status})", status) from None
        if not isinstance(parsed, dict):
            raise QarediError(f"{method} {path} 응답 모양이 예상과 다릅니다(HTTP {status})", status)
        return parsed


def _one_line(value: Any) -> str:
    """서버가 준 글자를 한 줄로 — 줄바꿈·제어 문자는 빈칸으로(결과 한 건 = 한 줄이 흐트러지지 않게)."""
    if not isinstance(value, str):
        return ""
    return re.sub(r"[\x00-\x1f\x7f]+", " ", value).strip()


class _Masked:
    """요청 객체를 든 지역 변수의 repr 을 가린다 — capture_locals 가 `req` 의 속성을 펼쳐 찍는 도구(rich 등)에도 헤더가 나가지 않게."""

    __slots__ = ("obj",)

    def __init__(self, obj: Any) -> None:
        self.obj = obj

    def __repr__(self) -> str:
        return "<request>"


_HINTS = {
    401: "토큰이 없거나 틀리거나 만료·회수됐습니다 — 「내 계정 → 프로그램 토큰」에서 범위 「커뮤니티 글쓰기」로 새로 발급해 QAREDI_TOKEN 에 넣으세요",
    403: "이 토큰으로는 할 수 없는 일입니다 — 범위가 「커뮤니티 글쓰기」인 개인 토큰인지 확인하세요(남의 비공개 글도 403/404 입니다)",
    404: "없거나 볼 수 없는 페이지입니다",
    409: "개인 공간이 아직 없습니다 — 다시 시도하세요(공간을 먼저 만듭니다)",
    413: "글이 너무 큽니다 — 나눠 올리세요",
    429: "요청이 너무 잦습니다 — 잠시 뒤 다시",
}


def _http_error(status: int, raw: bytes, method: str, path: str) -> QarediError:
    if 300 <= status < 400:
        return QarediError(
            f"{method} {path} 가 HTTP {status} 로 다른 주소를 가리킵니다 — 따라가지 않습니다. QAREDI_URL 이 맞는지 확인하세요",
            status,
        )
    err: dict[str, Any] = {}
    try:
        parsed = json.loads(raw[:MAX_RESPONSE_BYTES].decode("utf-8"))
        if isinstance(parsed, dict):
            err = parsed
    except (UnicodeDecodeError, json.JSONDecodeError):
        pass
    parts = [f"HTTP {status}"]
    if isinstance(err.get("error"), str):
        parts.append(err["error"][:300])
    reason = err.get("reason") if isinstance(err.get("reason"), str) else None
    if reason:
        parts.append(f"reason={reason[:64]}")
    errors = err.get("errors")
    if isinstance(errors, list) and errors:
        shown = [
            f"{e.get('path', '')}:{e.get('reason', '')}"[:120]
            for e in errors[:10] if isinstance(e, dict)
        ]
        parts.append("검증 오류 " + ", ".join(shown) + (" …" if len(errors) > 10 else ""))
    scope_denied = reason == "token_scope" or (status == 403 and err.get("error") == "token_scope")
    if '/api/work/documents' in path and status == 409:
        parts.append('프로젝트와 해당 문서를 다시 읽어 저장 여부·최신 판을 확인하세요. 새 요청 번호로 중복 생성하거나 기존 내용을 덮어쓰지 마세요')
        return QarediError(' · '.join(parts), status, reason)
    if "/api/travel-plans" in path and status in (401, 403, 404, 409):
        travel_hint = {
            401: "여행 권한을 선택해 발급한 개인 토큰의 만료·회수를 확인하세요",
            403: "여행 읽기에는 travel.read, 저장·연결에는 travel.write 권한을 직접 선택한 토큰이 필요합니다. 기존 커뮤니티 토큰은 여행을 열지 않습니다",
            404: "없거나 내 소유가 아닌 여행 계획·기록입니다",
            409: "계획의 최신 내용을 읽고 변경 사항을 확인한 뒤 새 revision으로 다시 저장하세요",
        }
        parts.append(travel_hint[status])
        return QarediError(" · ".join(parts), status, reason)
    hint = (
        "권한 범위 밖입니다 — 이 일에 맞는 범위(글쓰기는 「커뮤니티 글쓰기」, 검색은 「커뮤니티 읽기」 이상)를 가진 토큰이 필요합니다"
        if scope_denied else _HINTS.get(status)
    )
    if hint:
        parts.append(hint)
    return QarediError(" · ".join(parts), status, reason)
