# Railway deployment

Deploys this fork to Railway as a single app service plus a MariaDB service,
with the demo database baked into the image and restored on first boot.

Synthetic Synthea data only. No PHI. A production deployment would additionally
require a BAA-capable host — Railway does not offer one.

## Layout

| Path | Role |
|---|---|
| `railway-entrypoint.sh` | Boot wrapper. Restores the seed, registers the module, rotates the admin password, then `exec`s the stock `openemr.sh`. |
| `harden.php` | Rotates the admin password. Run dropped to `apache` via `su-exec`. |
| `seed/openemr-seed.sql.gz` | Full 283-table demo dump (46 patients, 9,768 encounters, 1,669 A1c rows). |
| `/railway.json` | Start command, healthcheck, restart policy. |
| `/.railwayignore` | Upload filter. **Railway does not read `.dockerignore` for uploads.** |

## One-time setup

```bash
railway link --project <project> --environment production --service openemr

railway volume add --service openemr \
  --mount-path /var/www/localhost/htdocs/openemr/sites/default/documents

railway domain --service openemr --port 80
```

The volume mount path matters. Mounting at `sites/` instead would blank
`sites/default/docker-version`, and `check_upgrade()` in `openemr.sh` compares
that against `/root/docker-version` — a blank read of `0` makes `11 > 0` true and
runs `fsupgrade-1.sh` … `fsupgrade-11.sh` against the restored seed on every
boot. Mounting at `documents/` persists uploads and the crypto drive keys while
leaving `docker-version` in the image layer.

Port 80 must be pinned explicitly: the container listens on both 80 and 443, so
Railway's port detection is ambiguous. TLS terminates at the edge, so the
commented-out HTTPS redirect in `openemr.conf` must stay commented — enabling it
causes a redirect loop.

## Variables

```bash
railway variables set -s openemr --skip-deploys \
  MYSQL_HOST='${{MySQL.MYSQLHOST}}' \
  MYSQL_PORT='${{MySQL.MYSQLPORT}}' \
  MYSQL_USER='${{MySQL.MYSQLUSER}}' \
  MYSQL_PASS='${{MySQL.MYSQLPASSWORD}}' \
  MYSQL_DATABASE='${{MySQL.MYSQLDATABASE}}' \
  MYSQL_ROOT_PASS='${{MySQL.MYSQL_ROOT_PASSWORD}}' \
  MYSQL_COLLATION=utf8mb4_general_ci \
  MANUAL_SETUP=no SWARM_MODE=no SEED_RESET=if-changed \
  OPENEMR_SETTING_rest_api=0 OPENEMR_SETTING_rest_fhir_api=0 \
  OPENEMR_SETTING_rest_portal_api=0 OPENEMR_SETTING_rest_system_scopes_api=0 \
  OPENEMR_SETTING_oauth_password_grant=0
```

Then set the two secrets in the dashboard (not on a command line):

- `OE_ADMIN_PASSWORD` — the container refuses to boot without it, rather than
  falling back to the `pass` default baked into `openemr.sh`.
- `OPENEMR__COPILOT_API_KEY` — Anthropic API key for the co-pilot.

Optional, for Langfuse tracing (PUNCH_LIST.md 1.1) -- both LangfuseTracer and
LangfuseCheck (the `/meta/health/readyz` dependency check) no-op until both
are set, so the co-pilot works the same without them:

- `OPENEMR__LANGFUSE_PUBLIC_KEY` / `OPENEMR__LANGFUSE_SECRET_KEY` — from a
  Langfuse Cloud project (standard `us.cloud.langfuse.com` region -- this
  fork only sends synthetic Synthea data through the co-pilot, so the
  HIPAA-BAA region and its higher plan tier are not needed; see the
  "LangFuse region decision" note in project memory before changing that).

Leave `OE_PASS` unset. Prefer a **MariaDB** service: the dump carries the
MariaDB-only `/*M!999999 ... */` sandbox sentinel and is untested on MySQL 8.

`MANUAL_SETUP` must stay `no` and `SWARM_MODE` must stay `no` — both suppress the
`setGlobalSettings()` call that applies the `OPENEMR_SETTING_*` hardening on
every boot.

## Deploying

Deploys are manual. The old `.gitlab-ci.yml` auto-deploy is dead (the primary
remote is GitHub) and there is no GitHub Actions equivalent. Run:

```bash
railway up --service openemr --environment production \
  --project 6b2854a5-a805-4f32-91ab-01ed6981584c --ci --message "<description>"
```

`--project` needs the project UUID, not its display name.

## SEED_RESET

| Value | Behaviour |
|---|---|
| `if-changed` (default) | Restore when the database is empty or the dump's sha256 differs from the recorded marker. Ordinary redeploys leave demo data alone. |
| `if-empty` | Restore only into an empty database. |
| `force` | Always restore. The reset path. |
| `never` | Never restore. |

Reset to a pristine demo:

```bash
railway variables set -s openemr SEED_RESET=force
railway redeploy -s openemr -y
railway variables set -s openemr SEED_RESET=if-changed --skip-deploys
```

A restore deletes the drive key files under
`documents/logs_and_misc/methods/`. This is deliberate: those files are not raw
keys, they are ciphertext wrapped under the *database* key that the restore just
replaced. Left in place they fail to decrypt and `CryptoGen` throws
`Key in drive is not compatible (ie. can not be decrypted) with key in database`.
Deleting them lets `collectDriveKey()` regenerate a consistent pair.

For the same reason the drive keys are **not** shipped in the image: with the
files absent and the `keys` table restored from the dump, generation is
self-consistent. Shipping keys from another database guarantees the exception.

## Regenerating the seed

```bash
docker compose -f docker/development-easy/docker-compose.yml exec mysql \
  mariadb-dump -uroot -proot openemr --no-create-db \
  --ignore-table=openemr.log --ignore-table=openemr.log_comment_encrypt \
  --ignore-table=openemr.lang_definitions --ignore-table=openemr.lang_constants \
  > /tmp/seed-main.sql
docker compose -f docker/development-easy/docker-compose.yml exec mysql \
  mariadb-dump -uroot -proot --no-data openemr \
  log log_comment_encrypt lang_definitions lang_constants \
  > /tmp/seed-empty-tables.sql
cat /tmp/seed-main.sql /tmp/seed-empty-tables.sql \
  | gzip > docker/release/seed/openemr-seed.sql.gz
```

**Why the two-pass dump.** `lang_definitions`/`lang_constants` (~250k rows) are
static UI translation reference data, unrelated to patient count — OpenEMR's
`xl()` falls back to the untranslated English string when no row matches, so
an English-only demo needs none of it. `log`/`log_comment_encrypt` (~70k rows)
is the audit trail from whatever local dev session the dump was captured
from — not demo content, and `log_comment_encrypt` specifically is encrypted
under drive keys that the entrypoint deliberately wipes and regenerates on
every restore (see "Crypto drive keys" below), so any rows shipped in it
would already be permanently undecryptable. Dropping all four cut the
compressed dump from ~14.6MB to ~6.2MB and the row count by roughly half —
material for restore time on Railway's network/disk, which is much slower
than a local restore. The second dump pass keeps their *schema* (`--no-data`)
so the app doesn't break on a missing table; only the data is excluded, and
the four tables come up empty (fresh audit log, no stale translations) on a
freshly deployed demo.

**Do not add `--databases` here.** `--databases openemr` makes mariadb-dump emit
`USE \`openemr\`;`, which hardcodes the source database's name into the dump
and breaks the restore against Railway's MySQL service, whose database is
named `railway` (or whatever `MYSQL_DATABASE` resolves to) — restore fails
immediately with `ERROR 1049: Unknown database 'openemr'`. The positional
single-database form above omits any `USE`/`CREATE DATABASE` statement, so the
dump is just table structure and data, safe to import into a database of any
name via `db --database="${MYSQL_DATABASE}"`.

The sha256 changes, so the next deploy re-seeds automatically under
`SEED_RESET=if-changed`.

## Notes

- **Upload cap.** Railway rejects uploads over roughly 45 MB with HTTP 413. The
  current tarball measures ~38.7 MB. `.railwayignore` drops `Documentation`
  (150 MB) and `contrib/util/language_translations` (32 MB, referenced only by
  the fresh-install path that never runs here). Keep it in sync with
  `.dockerignore`, or the build fails on files the upload dropped.
- **Healthcheck.** `/meta/health/readyz` returns HTTP 200 even on failure, so it
  is a weak gate. The real protection is the entrypoint: any failure exits
  non-zero and the deploy is marked failed. `readyz` also reports
  `"installed": false` — a pre-existing quirk that a normal install shows too.
- **Deploys briefly interrupt the demo.** A service with a volume gets no
  overlap, so avoid pushing to `main` while someone is reviewing.
