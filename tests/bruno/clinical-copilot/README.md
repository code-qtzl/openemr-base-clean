# Clinical Co-Pilot API collection

PUNCH_LIST.md Tier 2.2. A [Bruno](https://www.usebruno.com/) collection
covering login/session bootstrap, the Clinical Co-Pilot chat endpoint
(happy path, oversized-question, and unauthorized-session variants), and
the `/meta/health/livez` and `/meta/health/readyz` probes -- runnable
against a local docker stack or a deployed instance (e.g. Railway) with no
source access, source checkout, or PHPUnit/composer toolchain required.

Every request in `02-copilot-chat/` and `03-health/` carries a Bruno
`assert` block, so **Bruno's own Run button (or the `bru` CLI) reports
pass/fail per request from the HTTP response alone** -- that is the
acceptance criterion this collection is built to satisfy.

## Running it

1. Install [Bruno](https://www.usebruno.com/) (desktop app) or the CLI
   (`npm install -g @usebruno/cli`).
2. Open this folder (`tests/bruno/clinical-copilot/`) as a collection.
3. Pick (or duplicate and edit) an environment in `environments/`:
   - `local.bru` -- `http://localhost:8300`, matching CLAUDE.md's
     `docker/development-easy` stack, `admin`/`pass`.
   - `railway.bru` -- edit `baseUrl` to the deployed instance's URL first;
     update `username`/`password` if they differ from the local demo
     credentials.
   - `patientId` (both environments default to `1`, the standard OpenEMR
     demo patient present in a fresh install) -- point it at a real patient
     pid in the target environment if `1` doesn't exist there.
4. Run the whole collection **in folder order** (`01-auth`, then
   `02-copilot-chat`, then `03-health`) -- `01-auth` establishes the session
   cookie and the co-pilot's per-session CSRF token that
   `02-copilot-chat`'s requests depend on. `03-health` has no dependency on
   the other two and can be run standalone.
   - Desktop app: select the collection, choose the environment, click
     "Run".
   - CLI: `bru run --env local` (or `--env railway`) from this directory.

## What each folder does

- **`01-auth/`** -- logs in as a real user (`POST
  /interface/main/main_screen.php?auth=login`, form fields `authUser`/
  `clearPass`), then loads a patient's chart
  (`GET .../demographics.php?set_pid={{patientId}}`), which both sets the
  session's selected patient (`$_SESSION['pid']`, the same server-side
  scoping `CopilotChatController` reads -- see its own docblock) and renders
  the co-pilot chat panel, whose HTML carries a session-scoped CSRF token
  (`<input id="copilot-csrf">`). A post-response script in the third
  request extracts that token into `copilotCsrfToken` for
  `02-copilot-chat/` to use. Bruno's cookie jar carries the session cookie
  automatically across every later request in the run -- nothing here
  needs manual cookie handling.
- **`02-copilot-chat/`** -- the endpoint under test
  (`POST .../oe-module-clinical-copilot/public/ajax.php`):
  - `01-happy-path` -- a real question; asserts the success response shape
    (`reply`, `toolsUsed`, `correlationId`, `verificationPassed`).
  - `02-oversized-question` -- a >2000-character question; asserts the 400
    `"Question is too long."` rejection.
  - `03-unauthorized-session` -- a request whose CSRF token does not match
    the logged-in session's key (the same failure shape an expired/replayed
    session produces); asserts the 403 `"Session expired..."` rejection.
    See that request's own `docs` block for why this collection uses this
    rather than a second, environment-specific limited-ACL user account.
- **`03-health/`** -- `GET /meta/health/livez` and `/meta/health/readyz`,
  unauthenticated. `readyz`'s own HTTP status code reflects real dependency
  health (200 when healthy, 503 otherwise -- PUNCH_LIST.md Tier 1.2); see
  that request's `docs` block for why `status: "setup_required"` is an
  expected, documented reading against this fork's demo data even when the
  instance is fully installed and working.

## Request/response shapes

Pinned down against a running instance during this collection's
construction (Tier 2.1's PHPUnit suite exercises these same shapes at the
integration level -- see `tests/Tests/Services/Modules/ClinicalCopilot/`):

```
POST .../ajax.php  (happy path)              → 200
{"reply": "...", "toolsUsed": ["get_active_problems"],
 "correlationId": "...", "verificationPassed": true}

POST .../ajax.php  (oversized question)      → 400
{"error": "Question is too long.", "correlationId": "..."}

POST .../ajax.php  (stale/invalid CSRF token) → 403
{"error": "Session expired. Reload the page and try again.",
 "correlationId": "..."}

GET /meta/health/livez                        → 200
{"status": "alive"}

GET /meta/health/readyz                        → 200 or 503
{"status": "ready"|"setup_required"|"error", "healthy": true|false,
 "checks": {...}}
```
