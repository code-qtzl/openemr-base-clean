# Alert definitions

PUNCH_LIST.md 3.4 (`[ER-7]`). Three alerts on top of 3.3's Langfuse
dashboard. Thresholds are tied to `PERFORMANCE_BASELINE.md`'s numbers
(2026-09-18, git SHA `e24f293425`) -- see that file for full methodology
and caveats (host resource contention, Railway not yet captured) before
treating these as final production values.

## 1. p95 chat latency > threshold

- **Trigger condition**: `clinical-copilot.ask` root-span p95 latency (the
  full `ajax.php` request, from `langfuse.trace.metadata.*` / the
  `clinical-copilot.ask` span duration) over a 15-minute rolling window
  exceeds **37 seconds**.
- **Threshold derivation**: baseline p95 x2, per the punch list's own
  suggested formula. The baseline used here is the **real-API local smoke
  pass's** p95 (18.5s from `PERFORMANCE_BASELINE.md`), not the mocked
  10/50-VU numbers -- real production chat requests are dominated by actual
  Anthropic round-trip time (up to 8 sequential calls,
  `CopilotService::MAX_ITERATIONS`, `thinking: adaptive`), so the mocked
  baseline (which has no LLM latency at all) would produce a threshold real
  traffic trips on every request. 37s is deliberately generous given the
  smoke pass was only 6 requests -- revisit once a larger real-traffic
  sample (or the Railway smoke pass, once credentials are available) gives
  a tighter p95 estimate.
- **On-call first step**: check the Anthropic API status page first
  (external dependency, and the dominant cost in every request per the
  baseline above) -- then check instance resource saturation (CPU/memory,
  the mocked-load numbers show this instance saturating well before 50
  concurrent users).

## 2. Error rate > threshold

- **Trigger condition**: more than **5% of `POST .../ajax.php` requests**
  return a non-2xx response (or the SDK/verification failure path in
  `CopilotChatController`'s catch block) over a 5-minute rolling window.
- **Threshold derivation**: the punch list's own suggested figure (>5% over
  5 minutes). `PERFORMANCE_BASELINE.md`'s mocked-load runs show what
  "already degraded" looks like in concrete terms: 0% error rate at 10
  concurrent users, 28% at 50 -- so 5% is a genuine early-warning threshold
  well below the demonstrated saturation point, not an arbitrary round
  number picked without a reference.
- **On-call first step**: check `/meta/health/readyz` first (distinguishes
  "app is broken" from "a dependency -- DB, Anthropic API, Langfuse -- is
  down," per PUNCH_LIST.md Tier 1.2), then recent deploys, then the
  Anthropic API status page.

## 3. Tool failure rate > threshold

- **Trigger condition**: more than **2% of chart-reading tool calls**
  (`get_a1c_series`, `get_active_problems`, `get_medications`,
  `get_recent_encounters` -- tagged `langfuse.observation.type: tool` spans)
  return `ok: false` (`ChartContextTools`' result wrapper) over a 15-minute
  rolling window.
- **Threshold derivation**: the punch list's own suggested figure (>2%).
  The mocked local load test runs exercised `get_active_problems` against
  the real local database on every one of ~800 successful chat requests
  across the 10-VU and 50-VU runs combined, with **zero observed tool
  failures** -- so 2% is a deliberately generous margin above the ~0%
  baseline this environment actually showed, not an unguided guess. All
  four tools are pure parameterized SQL reads against OpenEMR's own
  database with no external dependency, so a nonzero failure rate here is a
  meaningfully different signal from alert #1 (Anthropic API-side) or #2
  (whole-request level).
- **On-call first step**: check DB connectivity first (all four tools are
  pure SQL reads against OpenEMR's own DB, per `CopilotService`'s and
  `ChartContextTools`' own docblocks), then check for a schema migration
  mismatch (a column the tool's query expects having changed).

## Implementation note

These are defined here as PUNCH_LIST.md 3.4 requires (trigger condition +
threshold + on-call step, checked into the repo) but not yet wired into an
actual alerting backend -- Langfuse Cloud's own alerting/webhook
configuration (dashboard-side, not code) is the natural place to implement
all three against what Tier 3.3 emits: `langfuse.trace.tags`
(`verification-passed`/`verification-failed`, `retried`),
`langfuse.trace.metadata.retry_count`, and, per tool-call span,
`langfuse.observation.level` (`ERROR` on a failed tool call, queryable
directly without parsing `observation.output`). See the Tier 3 completion
report for open follow-ups (no Langfuse alerting rules configured yet --
that's dashboard-side setup, not a code change).
