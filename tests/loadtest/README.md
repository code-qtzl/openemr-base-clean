# Clinical Co-Pilot load testing

PUNCH_LIST.md 3.2 (`[ER-9]`). A [k6](https://k6.io/) script that drives the same
login -> select-patient -> extract-CSRF -> ask-a-question flow
`tests/bruno/clinical-copilot/` already verified against a running instance,
at 10 and 50 concurrent virtual users, recording p50/p95/p99 latency and
error rate at each level.

## The mocked-vs-real / local-vs-Railway matrix

Two decisions this run matrix is built from (see `PERFORMANCE_BASELINE.md`'s
own notes section for the full reasoning):

|                          | Local docker (`development-easy`)        | Railway (live deploy)          |
|--------------------------|-------------------------------------------|---------------------------------|
| **Mocked LLM** (`mock-anthropic-stub.php`) | Full 10-VU and 50-VU load test -- primary data source for `PERFORMANCE_BASELINE.md` and `ALERTS.md`. | Not run. Would require deploying this stub to the live image; not worth the redeploy cycle for a mocked number Railway itself can't validate. |
| **Real Anthropic API**  | Small sequential smoke pass (5-10 requests, not concurrent) -- sanity-checks the mocked-vs-real latency delta. | **Blocked in this session**: `docker/release/railway/harden.php` deliberately rotates the admin password away from `admin`/`pass` on every boot (the deployment is publicly reachable), driven by the `OE_ADMIN_PASSWORD` Railway service variable. Retrieving that value via `railway variables` was correctly refused by this session's permission classifier as a credential-exposure risk. Whoever has that credential needs to either run the commands below themselves, or share `OE_ADMIN_PASSWORD` through a channel outside an agent transcript (e.g. `railway.bru`'s `password` var, edited locally and never committed) before this can run. |

## Mocked run: how the stub works

`mock-anthropic-stub.php` is a stateless stand-in for `POST /v1/messages`,
modeled on `tests/Tests/Fixtures/ClinicalCopilot/ScriptedAnthropicClientFactory.php`'s
response shapes. It always scripts the same 3-turn conversation --
`get_active_problems` tool call, then a cited `submit_answer`, then
`end_turn` -- deriving which turn to answer from `count(messages)` in the
incoming request body, so it needs no shared state and is safe under
concurrent VUs.

The real running app is redirected at it via `ANTHROPIC_BASE_URL`
(`vendor/anthropic-ai/sdk/src/Client.php` reads this env var directly when no
explicit `baseUrl` is passed, and `DefaultAnthropicClientFactory` never
passes one) -- **zero application code changes**, only local environment
setup:

```bash
# 1. Start the stub inside the running openemr container.
cd docker/development-easy
docker compose exec -d openemr php -S 127.0.0.1:8888 \
  /var/www/localhost/htdocs/openemr/tests/loadtest/mock-anthropic-stub.php

# 2. Point the app at it. Add to docker/development-easy/.env (gitignored):
#      ANTHROPIC_BASE_URL=http://127.0.0.1:8888
# then restart just the openemr container so the new env is actually read by
# the apache/php process (a docker exec -e does NOT affect an already-running
# server process, only compose-level env at container start does):
docker compose up -d openemr
# wait for it to report healthy: docker inspect -f '{{.State.Health.Status}}' development-easy-openemr-1

# 3. Run the load test (see below).

# 4. Tear down: remove/blank ANTHROPIC_BASE_URL from .env, restart again:
docker compose up -d openemr
# and confirm a real chat request succeeds before considering the stack back
# to normal.
```

## Running the k6 script

Installed via `brew install k6` (not vendored in this repo or the container
image). All three modes live in `k6-copilot-chat.js`:

```bash
# Per-level runs (what 3.2's acceptance criteria actually wants -- separate
# p50/p95/p99 and error rate AT EACH level, not blended into one ramp):
BASE_URL=http://localhost:8300 LEVEL_VUS=10 LEVEL_RAMP=15s LEVEL_DURATION=90s \
  k6 run --summary-trend-stats="avg,min,med,max,p(50),p(90),p(95),p(99)" \
  tests/loadtest/k6-copilot-chat.js \
  --summary-export=tests/loadtest/results/mocked-local-10vu.json \
  | tee tests/loadtest/results/mocked-local-10vu.txt

BASE_URL=http://localhost:8300 LEVEL_VUS=50 LEVEL_RAMP=20s LEVEL_DURATION=120s \
  k6 run --summary-trend-stats="avg,min,med,max,p(50),p(90),p(95),p(99)" \
  tests/loadtest/k6-copilot-chat.js \
  --summary-export=tests/loadtest/results/mocked-local-50vu.json \
  | tee tests/loadtest/results/mocked-local-50vu.txt

# Capture docker stats alongside (CPU/mem) for PERFORMANCE_BASELINE.md:
docker stats --no-stream=false --format '{{.Container}}\t{{.CPUPerc}}\t{{.MemUsage}}' \
  development-easy-openemr-1 > tests/loadtest/results/docker-stats-<level>vu.txt &

# Small real-API smoke pass (sequential, not concurrent -- run this with
# ANTHROPIC_BASE_URL unset/blank, i.e. after step 4 above):
SMOKE=1 SMOKE_REQUESTS=6 BASE_URL=http://localhost:8300 \
  k6 run --summary-trend-stats="avg,min,med,max,p(50),p(90),p(95),p(99)" \
  tests/loadtest/k6-copilot-chat.js \
  --summary-export=tests/loadtest/results/real-local-smoke.json \
  | tee tests/loadtest/results/real-local-smoke.txt

# Railway smoke pass (once OE_ADMIN_PASSWORD is available locally -- see the
# matrix above): copy railway.bru's baseUrl, then e.g.
BASE_URL=https://openemr-production-a819.up.railway.app PASSWORD='<OE_ADMIN_PASSWORD>' \
  LEVEL_VUS=5 LEVEL_RAMP=15s LEVEL_DURATION=45s \
  k6 run tests/loadtest/k6-copilot-chat.js \
  --summary-export=tests/loadtest/results/real-railway-smoke.json \
  | tee tests/loadtest/results/real-railway-smoke.txt
# Deliberately small (5 VUs, ~1 min) -- this is the one live, publicly
# reachable deployment; a heavier real-API concurrent run there is both real
# Anthropic cost and a risk of visibly degrading it for anyone else looking
# at it. Do not run the full 50-VU level against Railway.
```

## Results in this repo

`tests/loadtest/results/`:

- `mocked-local-10vu.{txt,json}`, `mocked-local-50vu.{txt,json}` -- the
  per-level mocked runs 3.2's acceptance criteria is built from.
- `docker-stats-10vu.txt`, `docker-stats-50vu.txt` -- raw `docker stats`
  samples captured during each mocked run, feeding `PERFORMANCE_BASELINE.md`'s
  CPU/memory numbers.
- `mocked-local-ramp-0-10-50vu.{txt,json}` -- an earlier combined 0->10->50
  ramp, kept for reference (shows the degradation transition) but NOT the
  source of the per-level numbers -- see that run's blended error rate for
  why a single continuous ramp isn't the right shape for this acceptance
  criterion.
- `real-local-smoke.{txt,json}` -- the small real-API sequential pass
  against local docker.
- `real-railway-smoke.*` -- not present in this commit; blocked, see the
  matrix above.

See `PERFORMANCE_BASELINE.md` (repo root) for the numbers pulled out of
these files into a baseline table, and `ALERTS.md` for the thresholds
derived from them.
