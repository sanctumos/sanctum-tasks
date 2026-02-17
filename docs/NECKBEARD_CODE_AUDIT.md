# Neckbeard Code Audit Report

Generated: 2026-02-17  
Branch audited: `cursor/system-feature-gaps-3cc2`  
Scope: application/runtime code and docs only (explicitly excluding CI/CD and precommit topics).

---

## Severity legend

- **Critical**: immediate security/data-integrity risk or direct privilege compromise.
- **High**: serious correctness/security issue with high likelihood or broad blast radius.
- **Medium**: meaningful flaw that can cause incorrect behavior, weakened security, or operational pain.
- **Low**: polish/consistency/perf/documentation issues with limited direct risk.

---

## Executive summary

- **Critical**: 4
- **High**: 9
- **Medium**: 14
- **Low**: 8

Primary risk cluster: auth/authz boundaries, secret handling, and security control bypass paths.

---

## Findings

## Critical

### C-01 — API keys are stored in plaintext at rest
**Evidence**
- `public/includes/config.php:371-386` (bootstrap key inserted raw into DB)
- `public/includes/functions.php:679-703` (new keys generated/stored raw)
- `public/includes/functions.php:749-771` / `774-791` (raw keys fetched)

**Why this is wrong**
A DB read compromise immediately gives fully usable bearer credentials. No one-way hashing or split-secret pattern is used.

**Impact**
Instant API account takeover for all compromised keys.

**Recommendation**
Store only key hashes (e.g., SHA-256/Argon2 keyed hash), show plaintext once at creation, compare hash on auth.

---

### C-02 — MFA secrets are stored in plaintext
**Evidence**
- Schema: `public/includes/config.php:177-178` (`mfa_secret` TEXT)
- Write path: `public/includes/functions.php:517-528`
- Read/verify path: `public/includes/auth.php:94-100`

**Why this is wrong**
If DB contents leak, attacker can generate valid TOTP codes and bypass MFA.

**Impact**
MFA downgraded to “security theater” under DB compromise.

**Recommendation**
Encrypt MFA secrets at rest with a server-side key (KMS/env secret), rotate support, and add secure key management.

---

### C-03 — Missing object-level authorization on task resources
**Evidence**
- Task endpoints gate on auth only, not ownership/assignment/role:
  - `public/api/get-task.php:4-17`
  - `public/api/list-tasks.php:4-47`
  - `public/api/update-task.php:4-43`
  - `public/api/delete-task.php:4-27`
  - collaboration endpoints (`create-comment`, `add-attachment`, `watch/unwatch`) also only require auth.

**Why this is wrong**
Any valid API key can read/update/delete arbitrary tasks across users.

**Impact**
Horizontal privilege escalation and cross-tenant style data tampering.

**Recommendation**
Add authorization policy checks (creator/assignee/project membership/admin override) in every task-mutating/reading endpoint.

---

### C-04 — API key accepted via query string (`?api_key=...`)
**Evidence**
- `public/includes/api_auth.php:59-62`

**Why this is wrong**
Query parameters leak via logs, browser history, referrers, proxies, and analytics.

**Impact**
Credential exposure with passive observation.

**Recommendation**
Remove query-string auth path; accept only headers.

---

## High

### H-01 — Session login endpoint is CSRF-prone due form fallback
**Evidence**
- `public/api/session-login.php:13-15` (`$_POST` fallback when JSON body empty)
- No CSRF/origin checks in this endpoint.

**Why this is wrong**
A cross-site form POST can log a victim browser into attacker-chosen credentials (login CSRF/session confusion).

**Impact**
Session confusion, user actions occurring under attacker-selected account context.

**Recommendation**
Remove `$_POST` fallback for API login, enforce JSON + origin checks, optionally anti-CSRF token for browser session auth.

---

### H-02 — Security controls trust spoofable IP headers
**Evidence**
- `public/includes/functions.php:11-26` uses `HTTP_CF_CONNECTING_IP` and `HTTP_X_FORWARDED_FOR` unconditionally.

**Why this is wrong**
Without trusted proxy validation, clients can spoof source IP and bypass lockout/rate logic tied to IP.

**Impact**
Brute-force mitigation weakening and polluted audit logs.

**Recommendation**
Trust forwarded headers only behind known reverse proxies; otherwise use `REMOTE_ADDR`.

---

### H-03 — Lockout logic uses `username OR ip` and clears by `username OR ip`
**Evidence**
- Lock check query: `public/includes/functions.php:563-568`
- Reset query: `public/includes/functions.php:594-597`

**Why this is wrong**
Successful login for one username can clear failed attempts for other usernames from same IP; shared-IP false lockouts and bypass conditions are possible.

**Impact**
Unreliable brute-force protection and lockout behavior.

**Recommendation**
Track and enforce lockouts on independent dimensions (username and IP buckets), and clear only the principal’s own counters.

---

### H-04 — SDK packaging/install is broken
**Evidence**
- `tasks_sdk/pyproject.toml` lacks explicit package/module discovery configuration.
- Practical result: `pip install -e ./tasks_sdk` fails with setuptools “Multiple top-level modules discovered”.

**Why this is wrong**
Published installation instructions are not reliably executable.

**Impact**
SDK cannot be installed as documented; integration friction and broken automation setup.

**Recommendation**
Define `packages`/`py_modules` explicitly or move to `src/` layout with proper setuptools config.

---

### H-05 — User-state mutation helpers return success even when target user does not exist
**Evidence**
- `public/includes/functions.php:394-405` (`setUserActive`)
- `public/includes/functions.php:411-428` (`resetUserPassword`)
- Neither checks affected row count or prior existence.

**Why this is wrong**
APIs/UI can report successful updates for nonexistent IDs.

**Impact**
False-positive administrative actions, audit ambiguity, operational confusion.

**Recommendation**
Check user existence before update and/or validate `changes()`/affected rows.

---

### H-06 — “Set default status” operation is not transactional
**Evidence**
- `public/includes/functions.php:282-300`
  - Clears existing defaults first, then attempts insert.
  - On insert failure (e.g., duplicate slug), no default remains.

**Why this is wrong**
Partial write leaves workflow state inconsistent.

**Impact**
No canonical default status; unexpected task creation fallback behavior.

**Recommendation**
Wrap in transaction; only clear old defaults after successful insert/update.

---

### H-07 — Admin due-date input is timezone-misaligned
**Evidence**
- UI uses `datetime-local`: `public/admin/index.php:151-153`, `public/admin/view.php:173-175`
- Parser forces UTC when timezone omitted: `public/includes/functions.php:109-113`

**Why this is wrong**
`datetime-local` is local-time semantics; backend interprets it as UTC, introducing silent offsets.

**Impact**
Incorrect due dates in production data.

**Recommendation**
Send explicit timezone/UTC from UI (e.g., JS convert to ISO Z) or interpret posted local time with user timezone context.

---

### H-08 — Rate-limit table amplification with invalid random keys
**Evidence**
- `public/includes/api_auth.php:112-123` applies rate-limit before API key validity check.
- `public/includes/functions.php:629-637` inserts new row for unseen key hash.

**Why this is wrong**
Attackers can spray random keys to force write amplification in `api_rate_limits`.

**Impact**
Unnecessary DB growth/churn and avoidable load.

**Recommendation**
Validate key first (or maintain separate “unknown key” limiter) before per-key accounting row creation.

---

### H-09 — Plugin emits tracebacks in structured output
**Evidence**
- `smcp_plugin/tasks/cli.py:170,177,197,204,211,218,239,246,253,260,555,614`

**Why this is wrong**
Tracebacks can leak internal details/paths and upstream API internals to consuming systems.

**Impact**
Information disclosure and noisy, unstable tool outputs.

**Recommendation**
Return concise user-safe errors by default; gate traceback behind explicit debug flag.

---

## Medium

### M-01 — Schema bootstrap/migration logic runs on every request
**Evidence**
- `public/includes/functions.php:5` calls `initializeDatabase()` at include time.
- Multiple request paths include this file.

**Why this is wrong**
Repeated DDL checks/index checks increase request latency and lock contention on SQLite.

**Impact**
Scalability and latency degradation.

**Recommendation**
Move migrations to explicit migration step or startup command; keep runtime path read/write only.

---

### M-02 — Bootstrap/migration operations are not wrapped in transactions
**Evidence**
- `public/includes/config.php:166-389` performs many dependent schema/seed operations without transaction boundaries.

**Why this is wrong**
Partial failures can leave mixed schema/data state.

**Impact**
Hard-to-debug startup failures and inconsistent metadata.

**Recommendation**
Use explicit transaction around migration batches where possible.

---

### M-03 — Admin task mutation pages swallow errors
**Evidence**
- `public/admin/create.php:33-50` ignores create result and always redirects.
- `public/admin/update.php:53-56` ignores update result.
- `public/admin/delete.php:13-19` no user feedback.

**Why this is wrong**
Validation or persistence failures are invisible to operators.

**Impact**
Silent data loss/confusion and repeated failed actions.

**Recommendation**
Surface operation result via flash messages or inline errors.

---

### M-04 — `apiSuccess()` can be semantically inconsistent
**Evidence**
- `public/includes/api_auth.php:29-36`
  - `array_merge(['success'=>true], $payload)` allows payload to overwrite `success`.

**Why this is wrong**
A function named “success” can emit `success: false` depending on payload keys.

**Impact**
Confusing API contract and brittle clients.

**Recommendation**
Disallow payload `success` override or namespace payload under `data` only.

---

### M-05 — Pagination URL builder trusts `HTTP_HOST`
**Evidence**
- `public/includes/api_auth.php:72-77`

**Why this is wrong**
Host header injection can taint returned pagination URLs.

**Impact**
Client-visible poisoned links and potential phishing/open-redirect style confusion.

**Recommendation**
Use configured canonical base URL or sanitize/whitelist host.

---

### M-06 — Search pagination links drop non-`q` filters
**Evidence**
- `public/api/search-tasks.php:25` builds pagination base params with `['q' => $q]` only.

**Why this is wrong**
`next_url`/`prev_url` do not preserve status/priority/sort filters.

**Impact**
Incorrect paging behavior for filtered searches.

**Recommendation**
Carry through all accepted filter/sort query params in pagination link generation.

---

### M-07 — Task existence is not validated for list-* collaboration endpoints
**Evidence**
- `public/api/list-comments.php:11-19`
- `public/api/list-attachments.php:11-16`
- `public/api/list-watchers.php:11-16`

**Why this is wrong**
Nonexistent task IDs return `200` + empty list instead of `404`.

**Impact**
Masking client bugs and inconsistent endpoint semantics.

**Recommendation**
Validate task existence first and return `404` on missing task.

---

### M-08 — Revoke API key helper always returns success
**Evidence**
- `public/includes/functions.php:793-800`

**Why this is wrong**
No affected-row check; revoking nonexistent IDs appears successful.

**Impact**
False-positive admin/API responses.

**Recommendation**
Check affected rows and return not-found error path.

---

### M-09 — Logout does not clear session cookie explicitly
**Evidence**
- `public/includes/auth.php:123-126`

**Why this is wrong**
Server-side session destruction without client cookie invalidation can leave stale session cookie artifacts.

**Impact**
Inconsistent logout behavior across clients.

**Recommendation**
Expire session cookie (`setcookie(..., time()-3600, ...)`) during logout.

---

### M-10 — Privilege changes do not propagate to active sessions immediately
**Evidence**
- `public/includes/auth.php:66-72` checks `$_SESSION['role']`.
- `getCurrentUser()` does not refresh session role from DB.

**Why this is wrong**
Demoted users may retain elevated session privileges until re-login.

**Impact**
Delayed authorization revocation.

**Recommendation**
Refresh role from DB per request or version sessions against user auth version.

---

### M-11 — Plaintext temporary passwords are exposed by API and admin UI
**Evidence**
- API response: `public/api/reset-user-password.php:33-37`
- UI display: `public/admin/users.php:84-89`

**Why this is wrong**
Temporary credentials become visible in logs/screenshots/browser history.

**Impact**
Credential leakage risk.

**Recommendation**
Prefer one-time reset links/tokens over returning raw passwords.

---

### M-12 — Plugin `argparse` handling treats normal help exits as errors
**Evidence**
- `smcp_plugin/tasks/cli.py:538-549`

**Why this is wrong**
`argparse` raises `SystemExit(0)` for `-h/--help`; current code catches and converts to error JSON exit 2.

**Impact**
Broken CLI UX and misleading automation diagnostics.

**Recommendation**
Only intercept non-zero `SystemExit` or let argparse handle help exits naturally.

---

### M-13 — Plugin `--describe` metadata is stale/incomplete vs implemented args
**Evidence**
- Description block `smcp_plugin/tasks/cli.py:264-444` omits newer options (`priority`, `project`, `tags`, etc.) and still hardcodes old status wording.

**Why this is wrong**
Tool discovery consumers get inaccurate schemas.

**Impact**
Auto-generated tool bindings can be wrong.

**Recommendation**
Generate description directly from argparse definitions or keep a single source of truth.

---

### M-14 — SDK JSON parse error handling is too narrow
**Evidence**
- `tasks_sdk/client.py:86-89` catches `json.JSONDecodeError` only.

**Why this is wrong**
`requests.Response.json()` may raise other decode exceptions depending on environment/backend.

**Impact**
Unhandled exceptions leaking to callers.

**Recommendation**
Catch `ValueError` (or broader decode exception family) for response JSON parsing.

---

## Low

### L-01 — Session secure cookie flag defaults to false
**Evidence**
- `public/includes/config.php:32`

**Why this is wrong**
Defaulting insecure for sessions is risky if deployed with HTTPS and misconfigured env.

**Impact**
Potential cookie exposure over non-TLS paths.

**Recommendation**
Default secure cookies on; allow explicit local override only.

---

### L-02 — Performance-heavy task listing query pattern
**Evidence**
- `public/includes/functions.php:1086-1088` (3 correlated subqueries per row)
- plus optional relations fetch in `getTaskById` (`983-985`)

**Why this is wrong**
Correlated counts per row degrade as table grows.

**Impact**
Latency spikes on larger datasets.

**Recommendation**
Use pre-aggregated joins/materialized counters or separate batched count queries.

---

### L-03 — API key list endpoint still materializes full key values before masking
**Evidence**
- `getAllApiKeys()` returns raw key: `public/includes/functions.php:752-771`
- masking later: `public/api/list-api-keys.php:15-18`

**Why this is wrong**
Secrets traverse more code paths than needed.

**Impact**
Increased accidental leak surface.

**Recommendation**
Return preview-only projection from SQL (`substr(api_key,1,12)`), never full key for listing endpoints.

---

### L-04 — Task comment create response timestamp is synthesized, not DB source of truth
**Evidence**
- `public/api/create-comment.php:39` uses `nowUtc()`

**Why this is wrong**
Response can differ from actual DB write timestamp.

**Impact**
Minor consistency drift for clients.

**Recommendation**
Fetch inserted row from DB and return persisted values.

---

### L-05 — Search/filter behavior silently ignores invalid enum filters
**Evidence**
- `listTasks()` only applies status/priority filter when sanitized value valid:
  - status: `public/includes/functions.php:1001-1006`
  - priority: `1009-1014`

**Why this is wrong**
Bad client input should fail loudly, not broaden result set.

**Impact**
Unexpected query behavior and hard-to-debug clients.

**Recommendation**
Return validation errors for invalid filter enums.

---

### L-06 — README/docs include unresolved license placeholders
**Evidence**
- `tasks_sdk/README.md:86-88`
- `smcp_plugin/tasks/README.md:167-169`

**Why this is wrong**
Legal/compliance ambiguity.

**Impact**
Distribution friction.

**Recommendation**
Add explicit license and repository root `LICENSE` file.

---

### L-07 — Public landing page points to authenticated health endpoint
**Evidence**
- `public/index.php:21` links `/api/health.php` which requires API key.

**Why this is wrong**
Public “health” affordance is misleading without auth context.

**Impact**
Minor UX confusion.

**Recommendation**
Either expose unauthenticated health probe or relabel button as “Authenticated API Health”.

---

### L-08 — Documentation overstates role semantics relative to enforced policies
**Evidence**
- `README.md:31-37` describes role model and “typical permissions”.
- Runtime enforcement is coarse; task-level ownership/permission boundaries are absent.

**Why this is wrong**
Docs imply stronger access segmentation than implemented.

**Impact**
Operator/security expectation mismatch.

**Recommendation**
Document current enforcement honestly or implement policy layer to match docs.

---

## Additional architectural observations (non-severity)

- SQLite is being used for both operational data and runtime security counters (lockout/rate limits). This is acceptable for small deployments but becomes contention-prone under concurrent API load.
- Secrets are generated and persisted from app runtime. Operationally convenient, but stronger separation (provisioning-time secret injection) is safer.

---

## Suggested remediation order

1. Fix **C-01/C-02/C-03/C-04** first (secret handling + authz + key transport).
2. Address **H-01/H-02/H-03/H-08** (login and abuse protections).
3. Fix **H-04** (SDK packaging) to restore dependable integration setup.
4. Tackle medium consistency/data-integrity items and admin UX error surfacing.

