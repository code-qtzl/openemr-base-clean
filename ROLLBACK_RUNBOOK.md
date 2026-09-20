# ROLLBACK_RUNBOOK.md — Railway incident response for the Clinical Co-Pilot fork

Closes `Appendix_CheckList.md` Phase 3 Item 15's "rollback strategy?" gap.
Both incidents this doc is built from were diagnosed live, from scratch, in
a single session (2026-09-20) — this document exists so the *next* one
starts from a lookup table instead of a re-investigation. Every command
below was run against the real project (`discerning-imagination`) while
writing this doc, not copied from memory unverified.

## Step 1: is this the app or the database?

Run `railway status --json` (or `railway status` for the human-readable
form) and check which service is `Crashed`. This determines everything
else — rolling back the app image does nothing for a database problem, and
resizing a volume does nothing for a bad app image.

| `openemr` | `MySQL` | Go to |
|---|---|---|
| Online | Crashed | **Step 3 (database)** |
| Crashed / crash-looping | Online | **Step 2 (app)** |
| Crashed | Crashed | Fix MySQL first (Step 3) — `openemr` depends on it and may recover on its own once MySQL is healthy again |

A misleading symptom to know in advance: `openemr` staying `Online` while
every page throws `mysqli_query(): Argument #1 ($mysql) must be of type
mysqli, false given` means MySQL never finished booting — that is a
**database** problem wearing an app-error costume. Check `railway status`
before assuming the app image is at fault.

## Step 2: app rollback (bad code / bad image)

Deploys are a Docker image tag pointer — there is no in-place "undo," but
pointing back at the last known-good tag is fast:

```bash
railway service source connect --image awhale4000/openemr-clinical-copilot:v<last-good> --service openemr
railway redeploy -s openemr -y
```

Then verify, don't assume:

```bash
railway logs -s openemr --lines 100          # no repeating error, boot completed
railway status --json                        # openemr shows Online
curl -s -o /dev/null -w "%{http_code}\n" https://openemr-production-a819.up.railway.app/chatbot-metrics/
```

**Which tag is "last-good"?** Check `railway deployment list -s openemr`
for recent deploy timestamps, cross-reference against commit history and
this repo's own incident log below. As of 2026-09-20, all eight built tags
(`v1`-`v8`) are still on Docker Hub; **`v6` is confirmed bad — do not use
it** (see incident log). `v7` and `v8` are confirmed good.

**Keep at least 3 built tags on Docker Hub at all times.** Nothing prunes
them automatically today; that is what makes "point at N-1" possible on
short notice. Don't delete a tag until its successor has run in production
for a while without incident.

## Step 3: database / volume issue

The only real incident so far (2026-09-20) was `MySQL` crash-looping on
`ENOSPC` during a Railway-initiated in-place version upgrade — see the
incident log below for the full timeline. The general procedure:

1. `railway logs -s MySQL --lines 100` — look for `ENOSPC`, `No space left
   on device`, or `InnoDB` error codes `MY-012144`/`MY-012640` during an
   `ALTER TABLE` step.
2. `railway volume list --json` — check `currentSizeMB` against `sizeMB`
   for `mysql-volume`. Headroom under ~25% free is worth resizing
   proactively, not just reactively; an in-place MySQL version bump needs
   temporary space to rewrite system tables.
3. **Resize in the Railway *dashboard*** (MySQL service → Volumes tab) —
   `railway volume update` only supports `--mount-path`/`--name`, no size
   flag, so this step cannot be done from the CLI.
4. **Click through the "changes to apply" review panel.** The resize does
   not take effect until you do — a dashboard settings change here sits
   unapplied until you explicitly review and confirm it, and that gap is
   exactly what made this incident take longer than it needed to the one
   time it happened. Don't stop at "I changed the number in the dashboard."
5. MySQL should now complete the pending upgrade on its own boot retry.
   Confirm via `railway logs -s MySQL` for a line like `Server upgrade from
   'X' to 'Y' completed`.
6. Re-check `railway status` — `openemr` typically recovers immediately
   with no image change once MySQL is healthy, since it was never the
   actual fault.

**Do not roll back the app image for a database-only incident.** It fixes
nothing here and just adds a second variable to an already-diagnosed
problem.

## Known crash-loop signatures

| Symptom | Cause | Fix |
|---|---|---|
| `openemr` Online, every page throws `mysqli_query(): Argument #1 ($mysql) must be of type mysqli, false given` | MySQL still booting or crashed | Step 3 |
| `MySQL` Crashed, logs show `ENOSPC` / `No space left on device` mid `ALTER TABLE` | Volume too small for an in-place MySQL version upgrade | Step 3 |
| `openemr` crash-loops right after a fresh redeploy, `MySQL` unaffected | Bad app image or entrypoint script bug | Step 2; also check `railway logs -s openemr --build` and `docker/release/railway/railway-entrypoint.sh` specifically |
| App boots but REST/FHIR/OAuth settings are unexpectedly on | Railway env-var safety net (`OPENEMR_SETTING_rest_api=0` etc.) missing or `MANUAL_SETUP`/`SWARM_MODE` flipped | Re-apply the required Railway variables per `docker/release/railway/README.md`; see `AUDIT_Extra.md` F3 |

## Incident log

Kept short and factual — this is a lookup table, not the full write-up.

- **2026-09-20 — MySQL `ENOSPC` crash-loop.** Root cause: `mysql-volume`
  capped at 500MB during a Railway-driven MySQL 9.4.0→9.7.2 upgrade. Fixed
  by resizing to 2000MB via the dashboard (Step 3). No app image involved.
- **2026-09-19/20 — `v6` crash-loop.** Root cause: `railway-entrypoint.sh`'s
  `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` doesn't parse against real
  Railway MySQL (a stale comment wrongly assumed "MariaDB" because the CLI
  binary is named `mariadb`). Present in the tree since 2026-09-16/17 but
  never re-exercised until a redeploy actually restarted the container.
  Fixed permanently in `v7` (commit `7c9a53905b`) — **never redeploy `v6`**.

## Post-rollback checklist

- `railway status` shows both services `Online`
- `curl -s -o /dev/null -w "%{http_code}\n" https://openemr-production-a819.up.railway.app/chatbot-metrics/` returns `200`
- Patient Finder loads the expected patient count in a real browser check
  (not just an HTTP status) if the incident touched the database at all
- If the root cause is a *new* bug class (not already in the table above),
  add it here and to the relevant memory file before closing out — this
  table is only useful if it stays current
