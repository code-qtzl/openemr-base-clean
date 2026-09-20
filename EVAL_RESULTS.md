# EVAL_RESULTS.md — Clinical Co-Pilot Eval & Regression Suite Results

Closes `PUNCH_LIST_2.md` Item 3 / 12-gate rubric Gate 9's remaining gap: the
suite was already strong on *design* (every test names the failure mode it
guards), but had no standalone, easy-to-find **results** artifact — only
prose scattered across commit messages and `PUNCH_LIST_2.md`. This document
is that artifact: a real run, on a real date, against a real git SHA, not a
description of what the suite is supposed to do.

---

## Run record

- **Date:** 2026-09-20
- **Git SHA:** `81e568b966` (HEAD at time of this run)
- **Environment:** `development-easy` docker stack, run as the `apache` user
  inside the `openemr` container (same execution context CI uses)
- **Commands run, verbatim:**
  ```
  vendor/bin/phpunit tests/Tests/Services/Modules/ClinicalCopilot
  vendor/bin/phpunit tests/Tests/Isolated/Modules/ClinicalCopilot tests/Tests/Isolated/Health
  ```
  (schema pre-applied via `tests/Tests/Fixtures/ClinicalCopilot/apply-schema.php`,
  the same script CI's "Apply Clinical Co-Pilot module schema" step runs)

## Result

**114 tests, 553 assertions, 0 failures, 0 errors, 0 skipped.**

| Suite | Tests | Assertions | Result |
|---|---:|---:|---|
| DB-backed (`tests/Tests/Services/Modules/ClinicalCopilot`) | 25 | 92 | OK |
| Isolated (`tests/Tests/Isolated/Modules/ClinicalCopilot` + `.../Health`) | 89 | 461 | OK |
| **Total** | **114** | **553** | **OK** |

This is not a subset or a cherry-picked run — it's every test PHPUnit
discovers under both paths, the same paths `.github/workflows/integration-tests.yml`
(lines 102–117) and `.github/workflows/isolated-tests.yml` wire into CI on
every push.

## Full test list, by class

Grouped exactly as PHPUnit's own `--testdox` output groups them (test class
= one behavioral unit). ✔ marks a passing test from this run; there are no
✘ or skipped entries to report.

### Verification layer (Gate 7 / `PUNCH_LIST_2.md` Item 5)

**Response Verifier** — 10 tests: honest insufficient-information passes;
insufficient-information without summary uses fallback; fully cited claim
passes; uncited claim is rejected; claim citing a tool not called this turn
is rejected; no claims without insufficient-information flag is rejected;
missing submit-answer call is rejected; malformed claim is rejected;
medication claim with zero rows is rejected regardless of wording;
medication claim with rows passes.

**Medication Staleness Policy** — 8 tests (boundary + threshold coverage):
recent open-ended medication is not flagged; medication with an end date is
never flagged regardless of age; decades-old open-ended medication is
flagged; just-under-the-threshold is not flagged; at-the-threshold is
flagged; null start date is not flagged; malformed start date is not
flagged; future start date is not flagged.

**Active Problem Staleness Policy** — 9 tests (same boundary shape as
above, longer threshold): recent unresolved problem is not flagged; problem
with a resolved date is never flagged regardless of age; decades-old
unresolved problem is flagged; chronic problem under twenty years is not
flagged; just-under-the-threshold is not flagged; at-the-threshold is
flagged; null onset date is not flagged; malformed onset date is not
flagged; future onset date is not flagged.

**Copilot Service** (DB-backed) — 8 tests: empty chart produces honest
insufficient-information reply; empty chart rejects a claim with no
supporting tool call; claim citing a tool actually called passes; claim
citing a tool never called is rejected; truthful zero-medications claim is
conservatively rejected (known limitation, documented not hidden); open-ended
old prescription carries staleness warning; unresolved decades-old active
problem carries staleness warning; **chart tool output is delimited as
untrusted data in the outgoing request** (AUDIT_Extra.md F2 prompt-injection
mitigation — asserts the actual wire body, not just that a wrapping method
exists).

### Boundary / authorization / adversarial (`PUNCH_LIST.md` Tier 2 + `PUNCH_LIST_2.md` Item 3)

**Copilot Chat Controller** (DB-backed) — 12 tests: oversized question is
rejected before any copilot work; **request over the rate limit is rejected
before any copilot work** and **the rate limit resets after the window
elapses** (a sliding-window per-session cap on real Anthropic spend, proven
with a mutable test clock rather than a real sleep); stale CSRF token is
rejected before anything else; user without patients ACL never reaches
patient data; missing API key degrades to a generic error without leaking
the exception message; exception path still produces a Langfuse trace
tagged error without leaking the exception message; **prompt injection in
chart data never leaks another patient's data** (adversarial); happy path
returns a verified reply with a correlation ID; follow-up question in the
same session and patient carries prior turns forward (Tier 4.1 multi-turn);
new patient in the same session never sees a prior patient's conversation
(isolation invariant); **injected instruction persisted in one patient's
history never reaches a second patient's conversation** (composed
adversarial + isolation case — the one `PUNCH_LIST_2.md` Item 3 added this
session).

### Multi-turn state (`PUNCH_LIST.md` Tier 4.1)

**Conversation** — 6 tests: empty conversation has no messages; append adds
user then assistant turn in order; append trims to the most recent 20 turns
(bounded-history invariant); turn round-trips through array; from-array
rejects an unknown role; from-array rejects missing content.

**Sql Conversation Store** (DB-backed) — 5 tests: load for an unknown
session/patient returns an empty conversation; save then load round-trips
turns in order; saving twice updates the same row instead of inserting;
different patients in the same session have independent conversations;
conversation older than the session timeout is not returned and is evicted.

### Observability (Gate 8 / `PUNCH_LIST_2.md` Item 2)

**Langfuse Tracer** — 12 tests, including: not-configured sends nothing;
configured sends to the OTel endpoint with basic auth; trace ID derives from
the correlation ID (logs and traces share one identifier); emits one span
per tool call plus one generation span when tokens are known; failed
tool-call span is leveled ERROR so it's alertable without parsing output;
failed verification and retries are tagged and counted on the root span;
**trace failure sends one error-leveled root span**; **trace failure never
sends the exception's own message** — the regression pair for the
failure-path tracing gap found and fixed this session; environment defaults
to the ambient env variable PHPUnit sets, and an explicit environment
overrides it (the test-data/production tagging fix).

**Langfuse Otlp Payload Builder** — 7 tests covering span/resource attribute
encoding correctness (parent-span-id presence, timestamp conversion,
attribute type variants, shared trace ID across spans, environment resource
attribute).

### API contracts / health (Gate 10 / `PUNCH_LIST_2.md` Item 4)

**Tool Schema Registry** — 5 tests, including the two regression tests for
real bugs this session found and fixed against the real Anthropic API:
chart tool definition uses the snake_case `input_schema` key (not the
camelCase bug that shipped silently because all four no-argument tools
happened to tolerate it); chart tool with no arguments encodes `properties`
as a JSON object, not an array (the `json_decode`/`json_encode` empty-array
ambiguity that produced a real 400 from the Anthropic API until fixed).

**Chart Context Tools Definitions** — 3 tests: definitions return exactly
the four allowlisted chart tools; definitions never include `submit_answer`
(the tool the model must never call as a chart lookup); every definition
encodes empty `properties` as a JSON object (same regression, asserted at
the call-site consumer too).

**Health Checker** — 3 tests: healthy requires every check to pass; healthy
ignores the installation check despite its status reflecting it; all checks
healthy and installed is fully healthy.

**Health Endpoint Response** — 5 tests, including the fail-closed regression
this session added: exception returns HTTP 503 (not the prior silent-200
bug); exception message never reaches the response body (the CLAUDE.md
`$e->getMessage()`-leak rule, enforced by a test); exception is logged with
PSR-3 context.

**Anthropic Api Check** — 4 tests; **Langfuse Check** — 5 tests (both
health-dependency checks: not-configured/partially-configured is healthy,
2xx is healthy, error status and unreachable transport are unhealthy).

### Data-shape contracts

**A1c Series Result**, **Active Problems Result**, **Medications Result**,
**Recent Encounters Result** — 3–4 tests each, all following the same shape:
`ok` wraps rows and reports a count, an empty/zero-row result is never
conflated with a failure, `failed` carries an error message and no rows.
`Active Problems Result` and `Medications Result` each add a
staleness-warning-is-carried-through-to-array test tying the result DTO to
its staleness policy.

## Real bugs this suite (and the audit work behind it) has caught

Not a hypothetical claim — the specific defects found and fixed, each with
its own regression test above so it cannot silently regress:

1. **CI never ran this DB-backed suite at all**, and a bare install never
   applied the module's schema either — two independent gaps, both fixed
   (`.github/workflows/integration-tests.yml` lines 102–117,
   `apply-schema.php`).
2. **Tool schemas used camelCase `inputSchema`** instead of Anthropic's
   snake_case wire format — silently harmless only because the affected
   tools took no arguments; confirmed and fixed against the real API.
3. **`json_decode(..., associative: true)` collapsed an empty JSON object
   into an empty PHP array**, which the real Anthropic API rejected once
   the key-name bug above was fixed and the malformed shape was no longer
   accidentally masked — confirmed and fixed against the real API.
4. **`CopilotChatController`'s exception path never called the Langfuse
   tracer** — a failing request produced no trace at all, invisible on the
   dashboard even though the PHP error log and `clinical_copilot_log` both
   recorded it correctly. Fixed with `LangfuseTracer::traceFailure()`.
5. **`/readyz` fell through to HTTP 200 with `$e->getMessage()` echoed into
   the body** on an uncaught exception — both a readiness-signal bug and a
   CLAUDE.md information-leak violation. Fixed via `HealthEndpointResponse`.
6. **48% of this fork's own seeded active-problem rows** (2,943 of 6,126)
   carry an onset date over 20 years old with no resolution date, still
   marked active — a real data-quality finding from a live query against
   this fork's own database, not a synthetic test fixture, that justified
   and now backs `ActiveProblemStalenessPolicy`.

## Real-model correctness eval (2026-09-20)

Everything above is the PHPUnit suite, which scripts the model's responses
(`ScriptedAnthropicClientFactory`) to test the surrounding code — citation
verification, staleness policies, audit logging — deterministically and for
free. It cannot tell you whether the real model's answers are actually
correct. `tests/eval/clinical-copilot-correctness-eval.php` closes that gap:
it seeds a patient with exact, known ground truth (a diabetes diagnosis, an
active metformin prescription, two A1c results establishing a clear upward
trend) and checks the real Anthropic API's actual reply against that ground
truth on three questions (active-problem recall, medication recall, A1c
trend direction).

**Real run, 2026-09-20:** 3/3 cases passed against `claude-opus-5`, run
manually inside the `development-easy` container (`docker exec -u apache
... php tests/eval/clinical-copilot-correctness-eval.php`) — verified via the
project's own dev stack, not the CI defined below, since that CI is not
currently live (see the CI-status note).

`.github/workflows/eval.yml` wires this into CI on `workflow_dispatch` and a
weekly schedule — deliberately not on every PR, since it costs real
Anthropic spend (~$0.30/run) and the model's exact wording isn't fully
deterministic, so it shouldn't gate merges the way the free scripted suite
does.

**CI-status note:** this project's live CI is GitLab (`.gitlab-ci.yml`, this
repo's actual remote), which today only has a dormant `deploy` stage (no
runner configured). The `.github/workflows/*.yml` files — `integration-tests.yml`
included, not just the new `eval.yml` — are inherited from upstream
`openemr/openemr` on GitHub and do not currently execute anywhere for this
fork; there is no GitHub mirror. They remain accurate, ready-to-run
definitions (this is also why `integration-tests.yml`'s Clinical Co-Pilot
step, cited earlier in this document, has never actually gated a real merge
here). Whoever eventually connects a GitHub mirror or a GitLab runner also
needs to add an `OPENEMR__COPILOT_API_KEY` secret before `eval.yml` can call
the real API — until then it skips itself cleanly with a warning rather than
failing on a missing key.

## What this is not

- **Not a CI run's own output.** This is a manual run against the same
  commands and same paths CI executes, on the date and SHA stated above —
  not a captured CI job log or a `gh run` link. Re-running the two commands
  in "Run record" against any later SHA reproduces this artifact; it isn't
  a one-time snapshot meant to be trusted forever.
- **Not the real-API or load-test suites.** Those are separate, already-documented
  artifacts with their own real results: `PERFORMANCE_BASELINE.md` (k6 +
  real-Anthropic-API latency) and `AI_SPEND.md` (real token/cost
  measurement via `tests/loadtest/real-api-cost-smoke.php`). This document
  covers only the PHPUnit eval/regression suite proper, plus the real-model
  correctness eval documented just above.
- **Not exhaustive.** `PUNCH_LIST_2.md` Item 3 already names the accepted
  gap: both staleness-policy families share one known, disclosed limitation
  (can't distinguish a truthful negative claim from a hallucinated one at
  zero rows), and further domain-constraint types (dosage thresholds,
  interaction flags) remain unbuilt. Documented as a scope decision, not
  hidden.
