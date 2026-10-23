# Remote MCP verification

`remote_http.py` tests the actual PHP application, OAuth handlers, PostgreSQL
tables, existing Memories/TravelPlans services and GD photo output. It contains
no mock OAuth/MCP server or in-memory copy of the application implementation.

The real integration suite has **not been run in the Git-only cloud environment**:
PHP, PostgreSQL tools, Python `requests` and the dedicated local fixture credentials
are unavailable there.
Python compilation and the guard selftest are separate checks; they do not prove
the PHP/DB suite passes. No test here establishes actual ChatGPT compatibility.

## Run in an isolated server checkout

Use a checkout outside `/var/www`, with Python 3.9+ and `requests`, PHP with
`pdo_pgsql`, `gd`, `fileinfo`, `iconv` and the application's existing extensions,
and local PostgreSQL command-line tools. Ports 8768 and 8769 must be free.

The existing `tests/community/memories_harness.py` requires:

- Dedicated `tbox_testdb` credentials at
  `~/.config/qaredi/pgpass-testdb`, permission 0600, and permission to create/drop
  its own synthetic databases on `127.0.0.1:5432`.
- The existing application roles/grants used by that harness. It reads only the
  application connection keys from `/var/www/qaredi/tbox-v2/.env`, substitutes a
  new database name, and never copies production records or media. Do not point
  the suite at an operational database or change the guard to make it run.

From the isolated checkout root:

```bash
python3 -m py_compile tests/mcp/remote_http.py tests/community/memories_harness.py
python3 tests/mcp/remote_http.py --guard-selftest
python3 tests/community/memories_harness.py --suite remote_http.py --output /tmp/qaredi-remote-mcp-acceptance
```

The harness creates `tbox_memories_test_8768_<pid>`, applies migrations including
`2026-10-15_remote_mcp.sql` twice, creates synthetic accounts, copies the application
to `/tmp/qaredi-stage13-*/tbox-v2`, and starts its own loopback PHP listeners. Its
`finally` cleanup stops only those processes and drops only that new database.
The source checkout, production database, services and storage are preserved.
Do not combine the remote suite with `--baseline-code`.

For this suite only, the copied config enables remote MCP and uses
`APP_URL=https://mcp-fixture.invalid` and the exact redirect allowlist
`["https://mcp-fixture.invalid/oauth/callback"]`. This exercises the production
HTTPS issuer validation. All test HTTP goes directly to `127.0.0.1:8768` or the
second owned listener `127.0.0.1:8769`; redirects
are never followed, proxies are disabled, and `.invalid` addresses are never
resolved. Local HTTP does not verify TLS termination, nginx forwarding/log policy,
or Secure-cookie browser behavior; those remain deployment checks.

The suite refuses invocation without the harness marker, exact loopback target,
synthetic DB name, disposable paths, matching app/fixture DB config, dedicated
fixture role and explicit isolated MCP settings. `--guard-selftest` verifies
acceptance of the local fixture shape and rejection of production hosts/databases,
wrong ports, paths, roles and settings without opening HTTP or DB connections.

## Coverage and evidence

- Discovery bound to configured issuer rather than Host; exact redirect
  registration; authorization resource/client/redirect bindings; S256 PKCE;
  expired/single-use codes; hash storage; state; denial and narrowed consent;
  CSRF; consent session/account binding; token-login, role-view and forced-gate
  rejection. Concurrent exchanges start together through a two-worker barrier,
  using separate HTTP sessions and both real PHP processes against the same DB.
  SQL assertions require one code exchange/token pair and one refresh rotation;
  the refresh replay must revoke both original and winning credentials.
- Bearer-only MCP authentication, cookie/API-token fallback rejection, distinct
  OAuth principals, Origin, 401 metadata challenges, GET/OPTIONS, content types,
  Accept, supported version negotiation, initialize/notification/ping, IDs,
  malformed JSON, batch rejection, body bound, tool catalogs and scope denial.
  An MCP write with another account's incidental cookie must log both domain and
  tool audit rows with only the Bearer owner's identity and OAuth grant UUID.
- Two owners' memories and travel plans; owner filtering even for public posts;
  read/create/update and idempotency; stale revisions; ownership failures;
  forbidden publicity/owner/media fields; preserved originals, media bytes,
  visibility/discovery and omitted fields; photo permission, current attachment
  and revision, bounded JPEG reencoding and metadata removal; deletion via the
  existing UI API followed by MCP denial. MCP exposes no delete tool.
- Travel save/link revisions, cross-owner denial and complete linked-memory
  preservation; browser and existing local-client API record changes/removal must advance the shared revision,
  reject stale MCP links and preserve the newer record placement/removal; access
  expiry; refresh binding/rotation/replay; token and UI
  revocation; cross-account/CSRF revocation denial; browser logout independence;
  password/MFA/recovery/active/expiry epoch invalidation with no old-grant revival;
  cosmetic/TOTP-counter epoch stability.

`remote-mcp-results.json` contains assertion names and pass/fail booleans, not
response bodies, credentials or photos. The harness also writes `schema.log`,
`fixture.log` and `http.log`. Keep artifacts local and review them before sharing.
A nonzero exit or any failed assertion is a failed run. A successful guard check
alone must never be reported as a successful integration run.
The concurrent OAuth, audit attribution and cross-client revision cases are also
**unrun** until the complete real PHP/Postgres command above succeeds.

After this passes, independently verify production-shaped nginx/PHP-FPM HTTPS,
DB owner/ACL preservation, fail-closed disabled/unconfigured behavior, callback
allowlist values taken from the actual client, and an actual ChatGPT OAuth +
read/photo/confirmed-write/disconnect cycle. Record those results separately.

## Server verification 2026-10-16

The isolated actual PHP/PostgreSQL suite passed **755/0** for the RFC9207 issuer
change (metadata, successful consent, denial). The initial run stopped at an old
photo fixture expectation: uploads now strip private PNG metadata under G-2.
The fixture now checks the exact PNG without the private tEXt chunk, keeping
image bytes and post-upload preservation assertions. No production media code
was changed. Evidence: `/home/changkwon/qaredi-mcp-activation-results-20261016/`.
This does not establish the user's actual ChatGPT OAuth connection.

## Desktop loader and loopback callback (2026-10-18)

`php tests/mcp/loopback_unit.php 0`, `1`, and `true` verify explicit opt-in,
literal host/path, port bounds, client binding, URL-confusion rejection and exact
HTTPS compatibility without a DB. The isolated remote suite now enables only
the synthetic instance's loopback option and tests native DCR, variable listener
ports, consent/state/issuer, exact code-exchange URI, PKCE/client binding, scoped
work reads and revocation. No test follows a callback or contacts another host.

`python3 tests/mcp/plugin_loader.py` uses the installed Codex app-server's actual
`plugin/read` parser to compare the original portable server extension shape
and the fixed minimal portable package (1.0.2, updated 2026-10-23). It does not call a model, install anything, edit user
config/cache, or fetch credentials. Fixed server and skill discovery are required;
the portable result is recorded as diagnostics for the installed CLI version.
This is a loader test on this server, not a home Windows or mobile success claim.
