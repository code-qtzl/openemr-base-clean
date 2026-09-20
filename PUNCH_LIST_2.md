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

**Update (2026-09-19): a separate 12-gate formal rubric was received**, with
5 gates failing: 7 (source attribution / domain constraints), 8 (logs +
correlation IDs + live dashboard), 9 (boundaries/invariants/regression, with
results), 10 (strict schemas, separate health/ready, runnable API
collection), 12 (actual spend + projections at four scales, with
architectural changes). These map directly onto Items 5, 2, 3, 4, and 1
respectively — closing this document's five items was the mechanism for
addressing all five failing gates, not a separate effort. Gates 7 (Item 5)
and 12 (Item 1) had genuine, specific remaining sub-gaps beyond what Items 1
and 5 originally scoped; both were reprioritized and closed as a direct
result of the rubric feedback (see each item's own "reprioritized" note).

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

**Current state: ~98%. Done.** This session, in order:
1. **Confirmed live**, with screenshots now committed into `KEY_METRICS.md`
   (`docs/images/clinical-copilot/`): trace latency percentiles, and cost/
   token count rendering correctly for `claude-opus-5` — the latter
   resolves what had been an open, unverified risk (whether Langfuse's
   pricing catalog even recognized the model name).
2. **Fixed a real bug that check surfaced**: every PHPUnit test run was
   sending real traces with fixture token counts into the same Langfuse
   bucket as genuine usage. `LangfuseTracer`/`LangfuseOtlpPayloadBuilder`
   now tag every trace with the OTel `deployment.environment.name` resource
   attribute (`test` for PHPUnit, `production` otherwise) — confirmed via
   both the Langfuse API and, later, the dashboard UI itself (a filter
   screenshot showing `test: 202` / `default: 100` / `production: 49` as
   distinct, countable buckets).
3. **Wired 2 of 3 alerts to a live backend**: `ALERTS.md`'s p95-latency and
   tool-failure-rate alerts are live in Langfuse (Automations →
   private Slack channel `#az-langfuse-alerts`, visible only to the project
   owner, not a shared team channel). Exact configuration for both is
   documented in `ALERTS.md` next to the trigger/threshold they implement.
4. **Found, then closed, a second real bug while wiring the third alert**:
   `CopilotChatController`'s `catch` block never called `traceAsk()`, so a
   request that threw produced *no Langfuse trace at all* — invisible on
   the dashboard even though `clinical_copilot_log` and the PHP error log
   both correctly recorded it. **Fixed**: `LangfuseTracer::traceFailure()`
   sends a root span tagged `level: ERROR` (queryable via the same
   `Is Root Observation` + `Status` filters alerts 1/3 already use), with
   the exception's class name only — never its message. A new
   `CopilotChatControllerTest` case captures the raw outgoing OTLP body via
   a new `CapturingHttpTransporter` fixture and confirms both the `ERROR`
   tagging and that the exception message never appears in it. 83/83
   copilot tests pass; zero new PHPStan errors.
- **What's left (the 2%):** the error-rate alert still isn't live —
  purely a Langfuse plan-tier limit (2 live alerts on the connected Hobby
  plan), not a missing-data gap anymore. Documented as an explicit, reasoned
  scope decision in `ALERTS.md`, not left ambiguous. A same-shape addition
  to alerts 1/3 whenever the plan allows a third.
- **Acceptance:** met — `KEY_METRICS.md` embeds real dashboard views with
  real (production-tagged) traffic; `ALERTS.md` states exactly which alerts
  are live and why the third isn't, rather than leaving it undefined; the
  trace data that alert needs now genuinely exists.
- **Effort remaining:** none — only a Langfuse plan upgrade would unblock
  the third alert, which is a billing decision, not engineering work.

---

## Item 3 — Build out the required eval and regression suite

**Current state: ~95%. Done.** Test *design* was already strong — every
test's doc comment names the failure mode it guards (boundary: oversized
question, missing patient, no ACL; invariant: claims cite a called tool;
regression: stale-medication guard; adversarial: prompt injection). This
session closed two gaps:
1. The entire DB-backed suite (including Tier 4.1's new conversation tests)
   was never running in CI — fixed via a new step in `integration-tests.yml`
   plus `apply-schema.php` (the module's schema was never applied by a bare
   `./cli install` either, a second, previously-unknown gap that fix also
   closed).
2. Tier 2.1's adversarial case and Tier 4.1's continuity case existed only
   independently — added
   `injectedInstructionPersistedInFirstPatientsHistoryNeverReachesASecondPatientsConversation()`
   (`CopilotChatControllerTest.php`), which scripts a genuine injection
   attempt (not benign marker text) into a first patient's persisted
   conversation turn and proves it never reaches a second patient's request
   in the same browser session — the composition the earlier tests didn't
   cover on their own.

Verified: 72/72 copilot tests pass (Services + Isolated), zero new PHPStan
errors, PSR-12/Rector/codespell clean.
- **What's left (the 5%):** nothing blocking — this is now a genuinely
  strong eval suite by `AgentForge.md`'s own bar. Further cases (more
  boundary conditions, more adversarial shapes) would be additive polish,
  not gap-closing, and the feedback's own "spend less time expanding scope"
  instruction argues against chasing that further right now.
- **Effort:** Done.

---

## Item 4 — Complete the API contracts and health/readiness checks

**Current state: ~98%. Done.** All three planned sub-tasks landed, and two
surfaced real bugs neither had a test for before this:

1. **`/readyz` fails closed.** An uncaught exception anywhere in
   `meta/health/index.php`'s dispatch previously fell through to a bare
   200 with `$e->getMessage()` echoed straight into the body (a real
   CLAUDE.md violation on top of the readiness bug — a publicly-reachable
   endpoint leaking internal detail). Extracted the response-building logic
   into `HealthEndpointResponse` (both the healthy/unhealthy path and the
   new fail-closed exception path) so it's unit-testable without
   `interface/globals.php`'s DB bootstrap. 5 new tests.
2. **Tool schemas extracted to `schemas/tool-definitions.json`**, loaded via
   a new `ToolSchemaRegistry`. This surfaced two real, previously-untested
   bugs: the four chart tools used camelCase `inputSchema` (Anthropic's
   wire format is snake_case `input_schema` — silently harmless only
   because all four take no arguments), and fixing that then surfaced that
   `json_decode(..., associative: true)` collapses an empty `properties:
   {}` into a PHP array indistinguishable from `[]`, which the real
   Anthropic API rejects ("Input should be an object") once the key name
   was corrected and the tool call was no longer silently malformed in a
   way that happened to be harmless. Both confirmed and fixed against the
   real API, not just mocked tests. 8 new tests.
3. **`openapi.yaml`** documents `ajax.php`'s chat endpoint plus
   `/meta/health/livez`/`readyz` (the same three the Bruno collection
   exercises) — validated with `@redocly/cli` (0 errors).
- **What's left (the 2%):** two accepted Redocly style warnings ("operation
  should have a 4xx response") on the two health endpoints, which take no
  input and require no auth, so genuinely never produce one — a stylistic
  lint opinion, not a real gap.
- **Effort:** Done.

---

## Item 5 — Add the full verification layer

**Current state: ~95%. Done**, reprioritized after external rubric feedback
named this a specific failing gate (Gate 7: "source attribution and domain
constraint enforcement on every response"). Both halves `AgentForge.md`
names exist and are tested: source attribution (`ResponseVerifier` — every
claim must cite a tool call actually made this turn, or the whole answer is
rejected to a safe fallback) and, as of this session, **two** independent
domain-constraint families:
1. `MedicationStalenessPolicy` (advisory warning) +
   `ResponseVerifier`'s hard-coded zero-row guard on `get_medications`
   (hard rejection) — pre-existing.
2. `ActiveProblemStalenessPolicy` (new) — a live query against this fork's
   own seeded database confirmed the same shape of gap exists for active
   problems, not just medications: 2,943 of 6,126 active problem rows (48%)
   have an onset over 20 years in the past (some as far back as 1946),
   still marked active. Mirrors `MedicationStalenessPolicy`'s advisory-
   warning shape, with a deliberately longer threshold (20 years vs. 2)
   reflecting that chronic problems legitimately persist far longer than a
   prescription should go unreconciled. Verified against the real
   Anthropic API, not just mocked tests. 11 new tests.
- **What's left (the 5%):** both constraint families share the same known,
  disclosed limitation (can't distinguish a truthful negative claim from a
  hallucinated one at zero rows) — this is an accepted MVP tradeoff, not an
  oversight, per `ResponseVerifier`'s own docblock. Further constraint types
  (dosage thresholds, interaction flags) remain unbuilt; two independent
  families is a defensible demonstration of breadth without over-expanding
  scope.
- **Effort:** Done.

---

## Priority order

1. ~~**Item 1 (AI spend)**~~ — **done to 90%**; `AI_SPEND.md` ships a real
   measured cost and scaling table. Only remaining: a measured (not
   estimated) multi-turn figure, and an actual-billed confirmation if that
   precision is wanted.
2. ~~**Item 2 (live dashboard)**~~ — **done, ~98%**; screenshots committed,
   the environment-tagging bug fixed, 2 of 3 alerts live-wired to Slack, and
   the third's original blocker (a real tracing gap) found *and closed* —
   only a Langfuse plan-tier limit remains, a billing decision, not
   engineering work.
3. ~~**Item 3 (eval suite)**~~ — **done, ~95%**; the composed test case
   landed.
4. ~~**Item 4 (API contracts / health)**~~ — **done, ~98%**; readyz fails
   closed, tool schemas extracted (fixing two real bugs along the way), and
   an OpenAPI contract is committed.
5. ~~**Item 5 (verification layer)**~~ — **done, ~95%**; reprioritized after
   rubric Gate 7 failed on this specifically. A second, independently-tested
   domain constraint (`ActiveProblemStalenessPolicy`) now demonstrates
   breadth, not just one narrow rule.

## Traceability

| Feedback item | This document | Current state |
|---|---|---|
| "full verification layer" | Item 5 | ~95% |
| "required eval and regression suite" | Item 3 | ~95% |
| "observability visible through a live dashboard" | Item 2 | ~98% |
| "complete the API contracts and health/readiness checks" | Item 4 | ~98% |
| "document actual AI spend with... scaling projections" | Item 1 | ~90% |
