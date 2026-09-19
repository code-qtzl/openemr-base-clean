# PUNCH_LIST_2.md — Closing the Grading-Feedback Gap

## Summary

`PUNCH_LIST.md` closed every `AgentForge.md` Engineering Requirement and Agent
Requirement to at least a defensible minimum (see that document's
traceability matrix, and the assessment given in this project's chat history
on 2026-09-18). Submission feedback received afterward asked for less feature
expansion and more **engineering evidence** on five specific fronts, quoted
verbatim:

> "Add the full verification layer, build out the required eval and
> regression suite, make observability visible through a live dashboard,
> complete the API contracts and health/readiness checks, and document actual
> AI spend with the required scaling projections."

This document tracks exactly those five items, current state verified against
the actual codebase (not assumed from prior docs), gap, and what closes each
one. **Nothing here is a new feature.** Every item is either finishing,
proving, or documenting something that already exists in code — matching the
feedback's own instruction to stop expanding scope.

Ordered by a mix of priority (weakest area first) and dependency.

---

## Item 1 — Document actual AI spend with scaling projections

**Current state: 90%. Done except an actual billed-dollar confirmation.**
`AI_SPEND.md` (new, repo root) now states a real measured cost per question
($0.0759 average, 3 real requests against real chart data, tied to git SHA
`3f82c71789`), sourced from `AskResult::inputTokens`/`outputTokens` (the
Anthropic SDK's own usage numbers) × Anthropic's published per-token rate for
`claude-opus-5` ($5/$25 per MTok in/out, confirmed against `claude.com/pricing`
directly, not a third-party estimate). A scaling table projects this to
1/5/50/500-physician scale using `USERS.md`'s own 20–24-patient-day figure.
`KEY_METRICS.md`'s stale "Status: none can be measured today" line is fixed.
The measurement tool (`tests/loadtest/real-api-cost-smoke.php`) is committed,
not a throwaway script, so this can be re-run after any pricing or model
change.
- **What's left (the 10%):** the multi-turn (Tier 4.1) per-conversation cost
  is an *estimate*, not directly measured — `AI_SPEND.md` says so explicitly
  rather than presenting a guess as measured. And this is a published-rate
  estimate, not a confirmed Anthropic Console billed dollar amount for this
  API key — closing that gap needs the console's billing view, which should
  come from whoever holds that login directly (same reasoning as the Railway
  credential note elsewhere in this project's history), not the agent
  fetching billing credentials itself.
- **Effort remaining:** S (a second real 2-turn smoke-test run, same script
  pattern, to replace the multi-turn estimate with a measured figure).

---

## Item 2 — Make observability visible through a live dashboard

**Current state: ~65%.** Confirmed, with real evidence, this session:
- The user opened the actual live Langfuse dashboard and confirmed trace
  latency percentiles rendering correctly, with the right span hierarchy
  (`clinical-copilot.ask` root, `anthropic.messages` generation, per-tool
  spans) — screenshotted and reviewed.
- **Cost and token count DO render for `claude-opus-5`** — confirmed via a
  "Sum cost by model over time" / "Sum tokens by model over time" chart
  showing real non-zero dollar and token figures. This resolves what had
  been an open, unverified risk.
- That same check surfaced a real, previously-unknown bug: every PHPUnit
  test run (`CopilotChatControllerTest` included) was sending real traces
  with fixture token counts into the *same* Langfuse environment as genuine
  usage, with no way to tell them apart — proven by that data showing up as
  a spike matching this session's own test-suite runs. **Fixed**:
  `LangfuseTracer`/`LangfuseOtlpPayloadBuilder` now tag every trace with the
  OTel `deployment.environment.name` resource attribute (`test` for PHPUnit
  runs, reusing this project's existing `ENV`/`ENVIRONMENT` convention;
  `production` otherwise). Verified against the real Langfuse API: the
  Item 1 real-API smoke-test traces are now correctly tagged
  `"environment": "production"`, distinct from test noise. 3 new tests
  added (`LangfuseTracerTest`, `LangfuseOtlpPayloadBuilderTest`); 71/71
  copilot tests still pass; zero new PHPStan errors.
- **Still open:**
  1. No screenshot/link is committed into a repo doc yet (`KEY_METRICS.md`
     or `ARCHITECTURE.md`) as durable evidence — it exists only in this
     session's chat history so far.
  2. `ALERTS.md`'s alerts are still not wired into a live alerting backend —
     unchanged from before; not attempted this session.
- **Build remaining:**
  1. Commit a dashboard screenshot (or a short walkthrough) into
     `KEY_METRICS.md`, now that cost/tokens/latency are confirmed rendering
     with `environment: production` correctly separating real traffic from
     test noise.
  2. Wire at least the p95-latency and error-rate alerts from `ALERTS.md`
     into a real Langfuse (or equivalent) alerting rule, or explicitly scope
     that out with a stated reason.
- **Acceptance:** `KEY_METRICS.md` links or embeds a real dashboard view with
  real (production-tagged) traffic on it; `ALERTS.md` no longer says alerts
  are undefined in a live backend, or explicitly scopes that out with a
  reason.
- **Effort remaining:** S (screenshot + doc commit) + S–M (live alert
  wiring, if in scope).

---

## Item 3 — Build out the required eval and regression suite

**Current state: ~85%** (was ~65-70% before this session's CI fix). Test
*design* was already strong — every test's doc comment names the failure
mode it guards (boundary: oversized question, missing patient, no ACL;
invariant: claims cite a called tool; regression: stale-medication guard;
adversarial: prompt injection). The gap this session closed: the entire
DB-backed suite (including Tier 4.1's new conversation tests) was never
running in CI — fixed via a new step in `integration-tests.yml` plus
`apply-schema.php` (the module's schema was never applied by a bare
`./cli install` either, a second, previously-unknown gap that fix also
closed). Verified: 19/19 Services tests + the full Isolated suite pass
cleanly and now run on every PR.

- **Gap:** Mostly closed. Remaining: no eval case yet exercises Tier 4.1's
  multi-turn history *combined* with an adversarial or boundary condition
  (e.g., a prompt-injection payload arriving in turn 2, referencing turn 1's
  context) — Tier 2.1's adversarial case and Tier 4.1's continuity case exist
  independently, not composed.
- **Build:** One additional test: a second-turn question that attempts to
  exploit persisted first-turn context (e.g., an injected instruction in a
  first-turn reply attempting to leak into a second patient's session).
- **Acceptance:** New test passes, documented failure mode matches the
  existing `CopilotChatControllerTest` doc-comment convention.
- **Effort:** S.

---

## Item 4 — Complete the API contracts and health/readiness checks

**Current state: ~80%.**

- **Health/readiness: ~95%.** `AnthropicApiCheck`/`LangfuseCheck` are real
  HTTP reachability probes (3s timeout, fail closed), `/readyz` returns 503
  on failure, `/livez`/`/readyz` are separate endpoints. One narrow edge
  case: an uncaught exception during the health-checker's own bootstrap
  falls through to a default HTTP 200 instead of failing closed.
- **API contracts: ~70%.** The *output* side is solid — DTOs
  (`A1cSeriesResult`, etc.) are the one canonical shape, PHPStan-level-10
  enforced, no duplication. The *input* side (the four tools' Anthropic
  JSON-schema definitions, and `submit_answer`'s schema) are hand-written
  inline PHP arrays co-located with the implementation that uses them — real
  and correct, but not a separate, standalone contract document/file that
  exists independently of the PHP that consumes it, which is what
  `AgentForge.md`'s "contracts must be the source of truth, not the
  implementation" phrasing asks for. Separately: the module's own external
  HTTP contract (`ajax.php`'s request/response shape) exists only as
  PHP code + the Bruno collection's example requests — no standalone
  schema document (OpenAPI/JSON Schema) describes it.
- **Build:**
  1. Fix the `/readyz` bootstrap exception path to fail closed (return a
     5xx) instead of defaulting to 200.
  2. Extract the four tool input schemas + `submit_answer`'s schema into a
     single schema file (JSON Schema, one file per tool or one combined
     file) that `ChartContextTools::definitions()`/
     `CopilotService::submitAnswerToolDefinition()` load from, rather than
     hand-rewriting the array inline — makes the schema the actual source of
     truth PHPStan/tests can validate against, not just documentation.
  3. Add a minimal OpenAPI (or equivalent) document for the `/ajax.php` chat
     endpoint's request/response shape, generated from or validated against
     the same contract the Bruno collection already exercises.
- **Acceptance:** `/readyz` returns non-200 on an internal exception (new
  test case); tool schemas load from a committed schema file, not an inline
  array literal; an OpenAPI/JSON-Schema doc for the chat endpoint exists and
  matches what `CopilotChatControllerTest` already asserts about the
  response shape.
- **Effort:** S (readyz fix) + M (schema extraction + OpenAPI doc).

---

## Item 5 — Add the full verification layer

**Current state: ~85%.** Both halves `AgentForge.md` names exist and are
tested: source attribution (`ResponseVerifier` — every claim must cite a tool
call actually made this turn, or the whole answer is rejected to a safe
fallback) and domain constraint enforcement (`MedicationStalenessPolicy` +
`ResponseVerifier`'s hard-coded zero-row guard on `get_medications`). Both
have a documented, disclosed known limitation (can't distinguish a truthful
"no medications on file" claim from a hallucinated one — both get the same
conservative rejection).

- **Gap:** Verification currently enforces exactly **one** domain-constraint
  family (medication staleness/absence). `AgentForge.md`'s "Hard Problems"
  section names a broader set as illustrative of what's in scope — "clinical
  rules, dosage thresholds, interaction flags" — none of which exist today.
  Given the feedback's own instruction to spend *less* time expanding scope,
  this is the lowest-priority item here, not a blocker.
- **Build (optional, pick based on time remaining):** One additional
  domain-constraint check with the same "reject to safe fallback" posture as
  the existing medication guard — e.g., a stale-active-problem check (an
  "active" diagnosis coded decades ago, analogous to the medication-staleness
  finding `AUDIT_Extra.md` already documents for problems, not just meds).
- **Acceptance:** New constraint has the same test-suite treatment as
  `MedicationStalenessPolicyTest`/`ResponseVerifierTest` — a documented
  failure mode per case, a regression test for the specific data-quality
  finding it closes.
- **Effort:** M, and explicitly optional/last given the feedback's own
  "spend less time expanding the feature set" instruction.

---

## Priority order

1. ~~**Item 1 (AI spend)**~~ — **done to 90%**; `AI_SPEND.md` ships a real
   measured cost and scaling table. Only remaining: a measured (not
   estimated) multi-turn figure, and an actual-billed confirmation if that
   precision is wanted.
2. **Item 2 (live dashboard)** — **65%, up from 35%**; dashboard visibility
   and cost rendering are now confirmed, and the environment-tagging bug
   this surfaced is fixed. Remaining: commit a screenshot into
   `KEY_METRICS.md` as durable evidence, and live-wire `ALERTS.md`'s alerts
   (or explicitly scope that out).
3. **Item 3 (eval suite)** — already strong; one composed test case left.
4. **Item 4 (API contracts / health)** — the readyz fix is trivial; schema
   extraction and an OpenAPI doc are the real work here.
5. **Item 5 (verification layer)** — already meets `AgentForge.md`'s literal
   bar; treat as optional given the feedback's explicit steer away from
   scope expansion.

## Traceability

| Feedback item | This document | Current state |
|---|---|---|
| "full verification layer" | Item 5 | ~85% |
| "required eval and regression suite" | Item 3 | ~85% |
| "observability visible through a live dashboard" | Item 2 | ~65% |
| "complete the API contracts and health/readiness checks" | Item 4 | ~80% |
| "document actual AI spend with... scaling projections" | Item 1 | ~90% |
