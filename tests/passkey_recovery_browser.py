"""Real login view/JS: a stale device marker plus unavailable credentials must expose recovery.

The browser credential API and challenge response are synthetic; no account or DB is touched.
The separate passkey.sh suite verifies real WebAuthn registration/signatures with a virtual device.
"""
import json
import os
import socket
import subprocess
import time
from pathlib import Path

from playwright.sync_api import sync_playwright, expect

ROOT = Path(__file__).resolve().parents[1]
OUT = Path(os.environ.get("PK_RECOVERY_RESULTS", "/tmp/qaredi-passkey-recovery-results"))
OUT.mkdir(parents=True, exist_ok=True)
with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
BASE = f"http://localhost:{port}"
server = subprocess.Popen(
    ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT / "public"), str(ROOT / "tests/design_fixture.php")],
    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
)
checks, failures, errors, writes = 0, [], [], []


def check(label, ok):
    global checks
    checks += 1
    if not ok:
        failures.append(label)


INIT = """() => {
  localStorage.setItem('qaredi.passkey-here', '1');
  sessionStorage.setItem('qaredi.just-logged-out', '1');
  sessionStorage.setItem('qaredi.passkey-tab-cancel', '1');
  PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(true);
  PublicKeyCredential.isConditionalMediationAvailable = () => Promise.resolve(false);
  window.__pkCalls = [];
  Object.defineProperty(navigator.credentials, 'get', {value: opts => {
    window.__pkCalls.push({uv:opts.publicKey.userVerification, rp:opts.publicKey.rpId});
    return Promise.reject(new DOMException('unavailable', window.__pkError));
  }});
}"""

try:
    time.sleep(.4)
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for theme in ["claude", "astra", "aurora", "bloom", "nova"]:
            for kind in ["NotAllowedError", "AbortError"]:
                tag = f"{theme}/{kind}"
                context = browser.new_context(
                    viewport={"width": 412 if kind == "NotAllowedError" else 360, "height": 915},
                    is_mobile=True, has_touch=True,
                    user_agent="tbox-review/2026-10-01 (Linux; Android 17) Chrome/140.0 Mobile",
                )
                context.add_cookies([{"name":"qaredi_design", "value":theme, "url":BASE}])
                context.add_init_script("(" + INIT + ")()")
                context.add_init_script("window.__pkError = " + json.dumps(kind))
                page = context.new_page()
                page.on("pageerror", lambda e: errors.append(str(e)))

                def api(route):
                    req = route.request
                    if req.url.endswith("/api/login/passkey/options"):
                        route.fulfill(status=200, content_type="application/json", body=json.dumps({
                            "publicKey": {"challenge":"Y2hhbGxlbmdl", "rpId":"localhost", "timeout":300000,
                                          "userVerification":"required", "allowCredentials":[]},
                        }))
                    elif req.method != "GET":
                        writes.append(req.url)
                        route.abort()
                    else:
                        route.fulfill(status=200, content_type="application/json", body="{}")

                page.route("**/api/**", api)
                page.goto(BASE + "/?screen=login")
                expect(page.locator("#passkey-login-btn")).to_be_visible()
                check(tag + " password initially folded", not page.locator("#password").is_visible())
                check(tag + " help initially hidden", not page.locator("#passkey-recovery").is_visible())
                page.locator("#passkey-login-btn").click()
                expect(page.locator("#password")).to_be_visible()
                expect(page.locator("#login-submit")).to_be_visible()
                expect(page.locator("#passkey-recovery")).to_be_visible()
                expect(page.locator("#passkey-login-btn")).to_be_enabled()
                check(tag + " username receives focus", page.locator("#username").evaluate("e => e === document.activeElement"))
                check(tag + " recovery folded to keep login close", not page.locator("#passkey-recovery").evaluate("e => e.open"))
                page.locator("#passkey-recovery summary").click()
                check(tag + " recovery opens on demand", page.locator("#passkey-recovery").evaluate("e => e.open"))
                check(tag + " recovery keeps previous keys", "해제하지 않아도" in page.locator("#passkey-recovery").inner_text())
                check(tag + " provider explanation", "Samsung Pass" in page.locator("#passkey-recovery").inner_text())
                check(tag + " overflow", page.evaluate("document.documentElement.scrollWidth <= innerWidth"))
                check(tag + " no inline code/styles", page.locator("[style],[onclick],script:not([src])").count() == 0)
                page.locator("#passkey-recovery summary").click()
                page.locator("#passkey-login-btn").click()
                page.wait_for_function("window.__pkCalls.length === 2")
                expect(page.locator("#passkey-login-btn")).to_be_enabled()
                check(tag + " UV and RP unchanged", page.evaluate("window.__pkCalls.every(x => x.uv === 'required' && x.rp === 'localhost')"))
                check(tag + " existing marker kept", page.evaluate("localStorage.getItem('qaredi.passkey-here')") == "1")
                if theme == "bloom" and kind == "NotAllowedError":
                    page.screenshot(path=str(OUT / "bloom-recovery-mobile.png"), full_page=True)
                context.close()
        browser.close()
finally:
    server.terminate()
    server.wait(timeout=5)

check("no unexpected writes", not writes)
check("no JavaScript errors", not errors)
result = {"checks":checks, "failures":failures, "errors":errors, "unexpected_writes":writes,
          "scope":"rendered PHP/actual JS; simulated unavailable credentials; no production account writes"}
(OUT / "results.json").write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
print(json.dumps(result, ensure_ascii=False))
raise SystemExit(bool(failures or errors or writes))
