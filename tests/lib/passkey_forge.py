#!/usr/bin/env python3
# tests/lib/passkey_forge.py — T:F-72 시험용 **소프트웨어 인증기** (지시서 4장 「틀린 응답은 … passkey_forge.py(파이썬 cryptography)가 만든다」)
#
# 구현(src/Passkey.php)과 **다른 코드**로 등록 응답(attestationObject)·로그인 응답(authenticatorData·서명)을 만든다 — 교차 확인(PK-T1).
# 쓰는 것: 파이썬 표준 + cryptography(서버에 있는 3.4.8). CBOR 은 여기 작은 인코더(정수·바이트·글자·배열·맵·참거짓·null) 하나.
# 틀린 모양(무한 길이·태그·실수·8바이트 길이·깊이·중복 키·남는 바이트·깨진 UTF-8·길이 넘침)은 raw_* 도우미로 **바이트를 직접** 만든다.
# 비밀은 없다 — 시험마다 새 열쇠를 만든다(개인 키는 메모리에만). 가상 인증기에서 꺼낸 PKCS#8 개인 키도 받는다(from_pkcs8_b64).
import base64
import hashlib
import json
import os
import struct

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec

UP, UV, BE, BS, AT, ED = 0x01, 0x04, 0x08, 0x10, 0x40, 0x80


def b64u(b):
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def unb64u(s):
    s = s + "=" * (-len(s) % 4)
    return base64.urlsafe_b64decode(s)


# ─── CBOR 인코더(정규 모양 — 짧은 길이 우선) ─────────────────────────────
def _head(major, n):
    if n < 24:
        return bytes([(major << 5) | n])
    if n < 0x100:
        return bytes([(major << 5) | 24, n])
    if n < 0x10000:
        return bytes([(major << 5) | 25]) + struct.pack(">H", n)
    if n < 0x100000000:
        return bytes([(major << 5) | 26]) + struct.pack(">I", n)
    return bytes([(major << 5) | 27]) + struct.pack(">Q", n)


def cbor(v):
    if v is True:
        return b"\xf5"
    if v is False:
        return b"\xf4"
    if v is None:
        return b"\xf6"
    if isinstance(v, int):
        return _head(0, v) if v >= 0 else _head(1, -1 - v)
    if isinstance(v, (bytes, bytearray)):
        return _head(2, len(v)) + bytes(v)
    if isinstance(v, str):
        e = v.encode("utf-8")
        return _head(3, len(e)) + e
    if isinstance(v, list):
        return _head(4, len(v)) + b"".join(cbor(x) for x in v)
    if isinstance(v, dict):
        return _head(5, len(v)) + b"".join(cbor(k) + cbor(x) for k, x in v.items())
    if isinstance(v, RawCbor):
        return v.b
    raise TypeError("cbor: %r" % type(v))


class RawCbor:
    """이미 인코딩된 CBOR 조각(틀린 모양을 끼워 넣을 때)."""
    def __init__(self, b):
        self.b = b


# ─── 열쇠 ─────────────────────────────────────────────────────────────
class SoftKey:
    def __init__(self, priv=None, cred_id=None):
        self.priv = priv or ec.generate_private_key(ec.SECP256R1())
        self.cred_id = cred_id or os.urandom(32)
        self.count = 0

    @classmethod
    def from_pkcs8_b64(cls, b64, cred_id):
        priv = serialization.load_der_private_key(base64.b64decode(b64), password=None)
        return cls(priv, cred_id)

    def xy(self):
        n = self.priv.public_key().public_numbers()
        return n.x.to_bytes(32, "big"), n.y.to_bytes(32, "big")

    def cose(self, **override):
        x, y = self.xy()
        m = {1: 2, 3: -7, -1: 1, -2: x, -3: y}
        m.update(override)
        return m

    def sign(self, data):
        return self.priv.sign(data, ec.ECDSA(hashes.SHA256()))

    def spki_der(self):
        return self.priv.public_key().public_bytes(serialization.Encoding.DER,
                                                   serialization.PublicFormat.SubjectPublicKeyInfo)


def auth_data(rp_id, flags, count=0, cred=None, cose_map=None, ext=None, rp_hash=None, aaguid=b"\x00" * 16, tail=b""):
    h = rp_hash if rp_hash is not None else hashlib.sha256(rp_id.encode()).digest()
    out = h + bytes([flags]) + struct.pack(">I", count)
    if flags & AT:
        cid = cred.cred_id if cred is not None else os.urandom(32)
        out += aaguid + struct.pack(">H", len(cid)) + cid
        out += cose_map if isinstance(cose_map, (bytes, bytearray)) else cbor(cose_map if cose_map is not None else cred.cose())
    if flags & ED:
        out += cbor(ext if ext is not None else {"credProtect": 2})
    return out + tail


def client_data(typ, challenge_b64u, origin, cross=False, extra=None):
    d = {"type": typ, "challenge": challenge_b64u, "origin": origin, "crossOrigin": cross}
    if extra:
        d.update(extra)
    return json.dumps(d, separators=(",", ":")).encode()


def attestation(ad, fmt="none", att_stmt=None, extra=None):
    m = {"fmt": fmt, "attStmt": att_stmt if att_stmt is not None else {}, "authData": ad}
    if extra:
        m.update(extra)
    return cbor(m)


def register_body(cred, name, challenge_b64u, origin, rp_id, flags=UP | UV | AT | BE | BS, fmt="none", att_stmt=None,
                  cdj=None, ad=None, transports=("internal", "hybrid"), attach="platform", raw_id=None):
    """POST /api/me/passkeys 본문(화면 passkey.js 와 같은 모양)."""
    ad = ad if ad is not None else auth_data(rp_id, flags, 0, cred)
    cdj = cdj if cdj is not None else client_data("webauthn.create", challenge_b64u, origin)
    rid = raw_id if raw_id is not None else cred.cred_id
    return {
        "name": name, "id": b64u(rid), "rawId": b64u(rid), "type": "public-key",
        "clientDataJSON": b64u(cdj), "attestationObject": b64u(attestation(ad, fmt, att_stmt)),
        "transports": list(transports), "authenticatorAttachment": attach,
    }


def login_body(cred, user_handle, challenge_b64u, origin, rp_id, flags=UP | UV | BE | BS, count=None, typ="webauthn.get",
               rp_hash=None, flip_sig=False, cdj=None, ad=None, sign_with=None, user_handle_b64u=None):
    """POST /api/login/passkey 본문. count 가 None 이면 열쇠의 횟수를 쓰지 않는다(0 — 동기화 열쇠처럼)."""
    c = 0 if count is None else count
    ad = ad if ad is not None else auth_data(rp_id, flags, c, rp_hash=rp_hash)
    cdj = cdj if cdj is not None else client_data(typ, challenge_b64u, origin)
    sig = (sign_with or cred).sign(ad + hashlib.sha256(cdj).digest())
    if flip_sig:
        sig = sig[:-1] + bytes([sig[-1] ^ 0x01])
    uh = user_handle_b64u if user_handle_b64u is not None else (b64u(user_handle) if user_handle is not None else None)
    return {
        "id": b64u(cred.cred_id), "rawId": b64u(cred.cred_id), "type": "public-key",
        "clientDataJSON": b64u(cdj), "authenticatorData": b64u(ad), "signature": b64u(sig),
        "userHandle": uh, "authenticatorAttachment": "platform",
    }


# ─── 틀린 CBOR 목록 (PK-T1 거부 목록 — 이름, 바이트) ─────────────────────
def bad_cbor_samples():
    s = []
    s.append(("무한 길이 바이트", b"\x5f\x41\x00\xff"))
    s.append(("무한 길이 배열", b"\x9f\x01\xff"))
    s.append(("무한 길이 맵", b"\xbf\x01\x02\xff"))
    s.append(("태그", b"\xc2\x41\x01"))
    s.append(("실수 16", b"\xf9\x3c\x00"))
    s.append(("실수 32", b"\xfa\x3f\x80\x00\x00"))
    s.append(("실수 64", b"\xfb" + b"\x00" * 8))
    s.append(("8바이트 길이", b"\x1b" + b"\x00" * 7 + b"\x01"))
    s.append(("8바이트 길이 바이트열", b"\x5b" + b"\x00" * 7 + b"\x01" + b"\x00"))
    s.append(("예약 추가 정보 28", b"\x1c"))
    s.append(("undefined", b"\xf7"))
    s.append(("단순값 24", b"\xf8\x20"))
    s.append(("깊이 5", b"\x81\x81\x81\x81\x81\x01"))
    s.append(("깊이 5 맵", b"\xa1\x01\xa1\x01\xa1\x01\xa1\x01\xa1\x01\x02"))
    s.append(("중복 키 정수", b"\xa2\x01\x02\x01\x03"))
    s.append(("중복 키 글자", b"\xa2\x61\x61\x01\x61\x61\x02"))
    s.append(("맵 키가 바이트", b"\xa1\x41\x00\x01"))
    s.append(("맵 키가 배열", b"\xa1\x80\x01"))
    s.append(("UTF-8 깨짐", b"\x62\xc3\x28"))
    s.append(("길이 넘침 바이트", b"\x45\x00\x00"))
    s.append(("길이 넘침 배열", b"\x83\x01"))
    s.append(("길이 넘침 맵", b"\xa3\x01\x02"))
    s.append(("잘린 인자", b"\x19\x01"))
    s.append(("빈 입력", b""))
    s.append(("4KB 넘음", b"\x5a" + struct.pack(">I", 4100) + b"\x00" * 4100))
    return s


def make_samples(rp_id, origin):
    """PK-T1 표본 — 등록 3 · 로그인 3(정상) + 틀린 COSE·틀린 CBOR. JSON 으로 돌려준다(PHP 단위 시험이 읽는다)."""
    out = {"rp_id": rp_id, "origin": origin, "register": [], "login": [], "bad_cbor": [], "bad_cose": []}
    for i in range(3):
        k = SoftKey()
        ch = b64u(os.urandom(32))
        flags = [UP | UV | AT, UP | UV | AT | BE, UP | UV | AT | BE | BS][i]
        ad = auth_data(rp_id, flags, [0, 7, 0][i], k)
        cdj = client_data("webauthn.create", ch, origin)
        out["register"].append({"attestationObject": b64u(attestation(ad)), "clientDataJSON": b64u(cdj),
                                "challenge": ch, "credential_id": b64u(k.cred_id), "spki_der_hex": k.spki_der().hex(),
                                "be": bool(flags & BE), "bs": bool(flags & BS)})
        ch2 = b64u(os.urandom(32))
        ad2 = auth_data(rp_id, UP | UV | (flags & (BE | BS)), [0, 8, 0][i])
        cdj2 = client_data("webauthn.get", ch2, origin)
        sig = k.sign(ad2 + hashlib.sha256(cdj2).digest())
        out["login"].append({"spki_der_hex": k.spki_der().hex(), "authenticatorData": b64u(ad2), "clientDataJSON": b64u(cdj2),
                             "signature": b64u(sig), "challenge": ch2})
    for name, b in bad_cbor_samples():
        out["bad_cbor"].append({"name": name, "hex": b.hex()})
    k = SoftKey()
    x, y = k.xy()
    out["bad_cose"] = [
        {"name": "x 31바이트", "hex": cbor({1: 2, 3: -7, -1: 1, -2: x[1:], -3: y}).hex()},
        {"name": "alg -257", "hex": cbor({1: 2, 3: -257, -1: 1, -2: x, -3: y}).hex()},
        {"name": "kty 3", "hex": cbor({1: 3, 3: -7, -1: 1, -2: x, -3: y}).hex()},
        {"name": "crv 2", "hex": cbor({1: 2, 3: -7, -1: 2, -2: x, -3: y}).hex()},
        {"name": "곡선 밖 점", "hex": cbor({1: 2, 3: -7, -1: 1, -2: x, -3: bytes([y[0] ^ 1]) + y[1:]}).hex()},
        {"name": "y 없음", "hex": cbor({1: 2, 3: -7, -1: 1, -2: x}).hex()},
        {"name": "x 가 글자", "hex": cbor({1: 2, 3: -7, -1: 1, -2: "x" * 32, -3: y}).hex()},
        {"name": "맵이 아니라 배열", "hex": cbor([2, -7, 1, x, y]).hex()},
    ]
    # 등록 거부 표본(attestationObject 모양)
    ch = b64u(os.urandom(32))
    good_ad = auth_data(rp_id, UP | UV | AT, 0, k)
    out["bad_attestation"] = [
        {"name": "fmt packed", "hex": attestation(good_ad, "packed", {"alg": -7, "sig": b"\x00" * 70}).hex()},
        {"name": "attStmt 비지 않음", "hex": attestation(good_ad, "none", {"x": 1}).hex()},
        {"name": "남는 바이트", "hex": (attestation(good_ad) + b"\x00").hex()},
        {"name": "authData 뒤 남는 바이트", "hex": attestation(good_ad + b"\x00").hex()},
        {"name": "키 하나 더", "hex": attestation(good_ad, extra={"x": 1}).hex()},
        {"name": "UV 없음", "hex": attestation(auth_data(rp_id, UP | AT, 0, k)).hex()},
        {"name": "UP 없음", "hex": attestation(auth_data(rp_id, UV | AT, 0, k)).hex()},
        {"name": "AT 없음", "hex": attestation(auth_data(rp_id, UP | UV, 0)).hex()},
        {"name": "rpIdHash 다름", "hex": attestation(auth_data(rp_id, UP | UV | AT, 0, k, rp_hash=hashlib.sha256(b"evil.example").digest())).hex()},
        {"name": "BS 인데 BE 없음", "hex": attestation(auth_data(rp_id, UP | UV | AT | BS, 0, k)).hex()},
        {"name": "열쇠 id 15바이트", "hex": attestation(auth_data(rp_id, UP | UV | AT, 0, SoftKey(k.priv, os.urandom(15)))).hex()},
        {"name": "fmt 없음", "hex": cbor({"attStmt": {}, "authData": good_ad}).hex()},
    ]
    return out


if __name__ == "__main__" and len(__import__("sys").argv) >= 4 and __import__("sys").argv[1] == "--samples":
    import sys as _s
    print(json.dumps(make_samples(_s.argv[2], _s.argv[3]), ensure_ascii=False))
    raise SystemExit(0)

if __name__ == "__main__":
    # 자체 확인: 인코더가 표준 예시와 같은 바이트를 내는가(RFC 8949 부록 A 몇 줄)
    assert cbor(0) == b"\x00" and cbor(23) == b"\x17" and cbor(24) == b"\x18\x18" and cbor(-1) == b"\x20"
    assert cbor(1000) == b"\x19\x03\xe8" and cbor("a") == b"\x61\x61" and cbor([1, [2, 3]]) == b"\x82\x01\x82\x02\x03"
    assert cbor({1: 2}) == b"\xa1\x01\x02" and cbor(b"\x01\x02") == b"\x42\x01\x02"
    print("passkey_forge self-check ok")
