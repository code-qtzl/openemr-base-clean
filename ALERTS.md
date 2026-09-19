# Alert definitions

PUNCH_LIST.md 3.4 (`[ER-7]`). Three alerts on top of 3.3's Langfuse
dashboard. Thresholds are tied to `PERFORMANCE_BASELINE.md`'s numbers
(2026-09-18, git SHA `e24f293425`) -- see that file for full methodology
and caveats (host resource contention, Railway not yet captured) before
treating these as final production values.

**Status (2026-09-19): 2 of 3 are live**, wired via Langfuse's own
Automations feature to a private Slack channel (`#az-langfuse-alerts`,
visible only to the project owner). Alert 2 is documented but not wired --
see its own section below for why. `PUNCH_LIST_2.md` Item 2 tracks this.

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
- **Live configuration** (Langfuse Alerts, 2026-09-19): View `Observations`,
  Measure `Latency`, Aggregation `p95`, Filters `Is Root Observation: true`
  + `Environment: production`, trigger `above` `37` over the past
  `15 minutes`, notifying `#az-langfuse-alerts` via Slack. `Is Root
  Observation` (not a name match on `clinical-copilot.ask`) is what scopes
  this to one full request per trace -- Langfuse's alert builder has no
  separate "Traces" view, only `Observations`/`Scores`, so this is how a
  whole-request metric is expressed here.

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
- **Not live, for two independent reasons** (2026-09-19): the connected
  Langfuse plan (Hobby) caps live alerts at 2, so a choice had to be made --
  but that cap didn't actually cost anything here, because of the second,
  more fundamental reason: **`CopilotChatController`'s `catch` block never
  calls `LangfuseTracer::traceAsk()`.** A request that throws (missing API
  key, Anthropic API error, SQL error -- exactly the failures this alert is
  meant to catch) produces *no Langfuse trace at all* today, even though it
  is still correctly written to `clinical_copilot_log` and the PHP error
  log. There is currently no trace data in Langfuse for this alert to watch
  regardless of plan tier. Closing that gap (tracing the failure path with
  a root span tagged `level: ERROR`) is a real, scoped follow-up, tracked
  in `PUNCH_LIST_2.md` Item 2 -- once it exists, this alert becomes a
  same-shape addition to alerts 1/3 above.

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
- **Live configuration** (Langfuse Alerts, 2026-09-19): View `Observations`,
  Measure `Count`, Filters `Type: TOOL` + `Status: ERROR` +
  `Environment: production`, trigger `above` **`1`** over the past
  `15 minutes`, notifying `#az-langfuse-alerts` via Slack.
  `Status: ERROR` maps directly to `LangfuseTracer`'s existing
  `langfuse.observation.level` attribute (`ERROR`/`DEFAULT` per
  `ToolCallSpan->success`) -- no code change needed for this one. The
  threshold is an **absolute-count approximation of ">2%"**, not a true
  percentage: Langfuse's alert builder has no ratio/rate Measure, only
  absolute ones (Count, Latency, Cost, Tokens). At today's real traffic
  volume, "2%" doesn't round to a meaningful number yet -- and the
  documented baseline is **zero** observed tool failures across ~800 mocked
  requests, so any single observed failure is already worth surfacing.
  Revisit and raise this threshold once real usage is high enough that
  occasional transient failures become statistically normal rather than
  notable.

## Implementation note

Alerts 1 and 3 are wired to a live backend: Langfuse's own Automations
feature, sending to a private Slack channel (`#az-langfuse-alerts`, visible
only to the project owner -- not broadcast to a shared team channel). Each
live configuration is documented in its own section above, next to the
`ALERTS.md`-derived trigger/threshold/on-call-step it implements, so the
two stay traceable to each other.

Alert 2 is not wired -- see its own section for the two independent
reasons (plan tier + a real tracing gap for the failure path), and
`PUNCH_LIST_2.md` Item 2 for the tracked follow-up.
