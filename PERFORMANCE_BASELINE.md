# Performance baseline

PUNCH_LIST.md 3.1 (`[ER-8]`). Captured 2026-09-18, git SHA
`e24f293425da45fec8946d47179ba9765c1cab70` (Tier 2 HEAD -- these numbers
were measured before Tier 3.3's dashboard instrumentation was written, and
that instrumentation wraps the transporter with negligible overhead, so it
does not invalidate them). Full methodology, scripts, and raw output in
`tests/loadtest/`.

**Read this table's "LLM" column before citing any number.** The mocked
10/50-VU numbers measure this application's own request-handling capacity
(session bootstrap, PHP-FPM/Apache workers, DB connections) in isolation
from Anthropic's latency -- they are the right numbers for capacity
planning and for `ALERTS.md`'s thresholds, but they are **not** what a real
50-concurrent-user real-LLM-call burst would look like. The real-API smoke
row shows that delta directly.

## Local docker (`development-easy`), mocked LLM

Chat endpoint only (`copilot_chat_duration` -- the actual `ajax.php` call,
excluding the login/session-bootstrap requests each k6 iteration also
makes). Environment: this machine's Docker Desktop, `development-easy-openemr-1`,
sharing the host with several other concurrently-running OpenEMR worktree
stacks -- see "Caveats" below.

| Level | Requests | p50 | p90 | p95 | p99 | Error rate | Peak CPU | Peak mem |
|-------|---------:|----:|----:|----:|----:|-----------:|---------:|---------:|
| 10 VU | 232 | 228ms | 277ms | 304ms | 357ms | 0.0% | 424% | 941 MiB |
| 50 VU | 586 | 823ms | 997ms | 1.04s | 1.16s | **28.0%** | 794% | 2.17 GiB |

Full-HTTP-flow numbers (`http_req_duration`, i.e. including the
login-page/login/set-patient requests each iteration also makes, not just
the chat call) for context:

| Level | p50 | p90 | p95 | p99 |
|-------|----:|----:|----:|----:|
| 10 VU | 400ms | 1.55s | 1.70s | 2.00s |
| 50 VU | 1.00s | 7.73s | 8.24s | 8.65s |

**This is the AUDIT_Extra.md Finding P5 degradation, observed directly, not
theorized.** 10 VUs is clean (0% errors). At 50 VUs, throughput does not
scale linearly with concurrency (iterations/sec rose only ~1.8x for 5x the
VUs) and the error rate jumps to 28% -- almost all `session_setup_errors`
(the login/CSRF-token bootstrap step timing out or failing under contention),
not chat-endpoint failures once a session was established. Consistent with
a single PHP-FPM/Apache worker pool and a single MySQL connection pool with
no autoscaling, exactly what P5 names.

Peak CPU is reported by `docker stats` as a percentage of one core, so
>100% is expected on a multi-core host; treat the CPU numbers as relative
(50-VU run used ~1.9x the 10-VU run's peak CPU and ~2.4x the memory), not
as an absolute ceiling.

## Local docker, real Anthropic API (small sequential smoke pass)

6 sequential (not concurrent) real requests, `claude-opus-5`,
`thinking: adaptive`, real chart data.

| Requests | p50 | p90 | p95 | p99 | Error rate |
|---------:|----:|----:|----:|----:|-----------:|
| 6 | 13.2s | 18.1s | 18.5s | 18.9s | 0.0% |

**The real-LLM chat latency is ~40-60x the mocked figure** (13.2s vs. 228ms
at p50). This is expected -- the mocked stub returns instantly with no
generation cost, while the real path does up to 8 sequential Anthropic
round-trips (`CopilotService::MAX_ITERATIONS`) with `thinking: adaptive` on
`claude-opus-5`. The mocked 10/50-VU numbers above measure this
application's own overhead; they say nothing about what real-LLM latency
looks like under concurrency, only about how much of the total request time
is *not* the LLM call. All 6 real requests returned `verificationPassed:
true` with correctly cited claims.

## Railway (live deployment), real Anthropic API

**Not captured.** `docker/release/railway/harden.php` rotates the Railway
deployment's admin password away from the `admin`/`pass` default on every
boot (it's publicly reachable) via the `OE_ADMIN_PASSWORD` service
variable. Retrieving that value via `railway variables` was correctly
refused by this session's permission classifier as a credential-exposure
risk, so the k6 login flow could not authenticate against Railway. See
`tests/loadtest/README.md`'s matrix for exactly what's needed to fill this
in (the login credential, then the commands are already written).

**Practical implication**: `ALERTS.md`'s thresholds below are keyed to the
local-docker mocked baseline, not a Railway-measured one. Local docker and
Railway are different container sizing/CPU allocation, so a Railway p95 in
practice will differ from the local number -- recalibrate `ALERTS.md`'s
thresholds once a Railway run exists.

## Caveats

- **Host resource contention**: this machine had 3 other OpenEMR worktree
  docker stacks running concurrently during these runs (`openemr-video-script-*`,
  `openemr-agent-requirements-*`, plus the `docs/stage5-architecture-plan`
  worktree's stack, though that one was stopped) sharing host CPU/memory
  with the `development-easy` stack under test. The mocked-50VU numbers are
  therefore a conservative (worse) measurement of what an isolated
  single-instance deployment would show -- Railway's dedicated instance is
  the more representative number once it can be captured.
- **Run-to-run variance observed**: an earlier combined 0->10->50 ramp run
  (`tests/loadtest/results/mocked-local-ramp-0-10-50vu.txt`) saw a 16% error
  rate at its 50-VU plateau vs. this table's 28% from the dedicated
  50-VU-only run -- both under the same host contention, different moment.
  Treat these as directional (clear signal of degradation onset between 10
  and 50 concurrent users) rather than a precise SLA number.
- PHPStan level-10 full-codebase analysis could not be run to completion in
  this environment (repeated OOM under the same host memory pressure noted
  above, independent of these particular code changes) -- see the Tier 3
  completion report for what verification did run (PSR-12, `php -l`, full
  isolated suite, the DB-backed ClinicalCopilot suite).
