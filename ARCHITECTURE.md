# ARCHITECTURE.md — Clinical Co-Pilot AI Integration Plan

This document synthesizes `AUDIT.md` (Stage 3) and `USERS.md` (Stage 4) into
the roadmap for building the Clinical Co-Pilot out from its current state to
something defensible in front of a hospital CTO. Every capability below traces
to a use case in `USERS.md`; every risk below traces to a finding in `AUDIT.md`.

**A note on sequencing:** a working co-pilot (tool-calling chat, patient-scoped
data access, disclosure-level audit logging) was built before this planning
document existed. That is out of order relative to the case study's
recommended stages, but it means this plan is not speculative — it is a
gap analysis against real, running code, informed by a real audit rather than
a greenfield design exercise. Where the existing build already matches what
this plan would have specified, that is called out as "keep." Where it
doesn't, that is the roadmap.

---

## Summary

The Clinical Co-Pilot is a tool-calling chat agent embedded in OpenEMR's
patient demographics page, built for Dr. Elena Ruiz — a solo endocrinologist
who needs a 90-second pre-room synthesis of what changed for a patient she
already knows, not a first-contact chart summary. The architecture keeps the
chart out of the prompt entirely: the model receives four narrow,
zero-argument tools (`get_a1c_series`, `get_active_problems`,
`get_medications`, `get_recent_encounters`), each scoped server-side to the
patient id already open in the clinician's session. This is the single most
important design decision in the system — the model can never request another
patient's data because it is never given the means to ask for one, closing
the IDOR/cross-patient-access risk by construction rather than by validation.
`AUDIT.md` confirms this holds today; the plan below keeps this invariant for
every future tool.

Three gaps stand between the current build and a defensible production
system, all surfaced by the audit and all addressed here. First,
**verification is incomplete**: tool-level disclosures are audited, but the
model's actual reply is never persisted, and there is no post-hoc check that
a claim is actually grounded in the tool output that came back. This plan
adds a conversation-log table (doubling as the missing audit trail) and a
verification pass between the model's answer and the browser — checking both
source attribution (does the reply cite tool-returned facts) and a small set
of hard domain constraints (does the reply contradict what a tool actually
returned). Second, **latency is unmeasured and the current configuration is
the most expensive plausible choice** — Opus, adaptive thinking, up to 8
tool round-trips, fully blocking with no streaming. Before locking in a
production model choice, this plan calls for benchmarking against Sonnet and
non-adaptive thinking, and adds SSE streaming so a physician sees progress
instead of a static spinner. Third, **there is no conversation memory across
turns**, which blocks UC2 (a mid-visit follow-up question) outright — this
plan adds session-scoped, database-persisted conversation state, which also
supplies the history needed for the verification layer to check consistency
across a conversation.

A fourth, cross-cutting decision this plan makes explicitly rather than by
default: **authorization stays role-based for v1, with a documented decision
to add facility/care-team scoping as the first post-launch hardening step**,
not because the gap doesn't matter but because Dr. Ruiz's persona (solo
practice, no shared patient panel) does not exercise it — the moment this
system is used by more than one clinician sharing a facility, this becomes a
blocking requirement, not an optional one, and the plan sequences it
accordingly rather than pretending it's solved.

The rest of this document works through: where the agent lives and how it
reaches patient data (unchanged architecture, documented); the verification
design (new); multi-turn state (new); the engineering requirements this
project is graded on independent of the case study narrative — correlation
IDs, typed contracts, observability, health/readiness, load testing — none
of which exist today and all of which are sequenced into a phased roadmap; and
a traceability matrix tying every planned capability back to a `USERS.md` use
case and an `AUDIT.md` finding, so nothing here is speculative.

---

## 1. Where the Agent Lives

**Current (keep):** The co-pilot is a custom OpenEMR module
(`interface/modules/custom_modules/oe-module-clinical-copilot`), not a
separate service. It hooks into the existing demographics page via
`RenderEvent::EVENT_SECTION_LIST_RENDER_BEFORE` and posts to its own
`public/ajax.php` endpoint, which boots the full legacy OpenEMR stack
(session, ACL, DB) via `globals.php` before handing off to
`CopilotChatController`. This is the correct integration shape for this
case study: patient context, session identity, and ACL state already exist
in that request — standing up a separate service would mean re-deriving or
transporting all three, which is a larger attack surface than reusing them
in-process. `AUDIT.md`'s Architecture Audit confirms this is the load-bearing
pattern the rest of OpenEMR uses too (`AclMain::aclCheckCore()`,
`$_SESSION`, `globals.php` — legacy substrate that even the modern PSR-4
layer sits on top of, not instead of).

**Planned changes to placement, not architecture:** `AUDIT.md`'s Architecture
Audit found a concrete, reusable fix for the panel's current position (below
three full-width cards, requiring a scroll before the physician can see it
exists) — `oe-module-dashboard-context` already demonstrates the pattern via
a second `PageHeadingRenderEvent::EVENT_PAGE_HEADING_RENDER` listener gated
to `page_id === 'core.mrd'`. Phase 1 (below) adds a second, lightweight
listener that places a persistent launcher in the page header nav, above the
fold, while leaving the full chat panel where it is. This is a placement fix,
not an architecture change.

## 2. How It Accesses Patient Data

**Current (keep, this is the core safety property of the system):**
`ChartContextTools` is constructed once per request with `$patientId` fixed
from the session-derived value `CopilotChatController` already validated. Its
four tool methods are explicit-column, parameterized `QueryUtils` queries,
each capped at `MAX_ROWS = 60`. The tool _schemas_ the model sees take no
parameters at all — there is no `patient_id` field anywhere in
`ChartContextTools::definitions()` — so the model has no channel through
which to request a different patient's chart even if a malicious or injected
prompt tried to induce it to. This is minimum-necessary access enforced by
construction, and every future tool added to this system must follow the
same zero-argument, constructor-scoped pattern or it reopens the
cross-patient risk the current design deliberately closes.

**Planned addition — the fifth tool, justified by UC3:** `USERS.md` UC3
(medication-safety cross-check) needs renal-function context (eGFR) that no
current tool exposes. This is added the same way as the existing four: a new
static definition in `ChartContextTools::definitions()`, a new private
query method filtered by the constructor's `$patientId`, a new `match` arm
in `call()`. No exception to the access pattern.

**Planned fix, blocking before any of the above ships (Phase 0):**
`AUDIT.md` Data Quality Finding 1 — `get_medications`' `active = 1` filter is
uninformative (100% of 919 rows are flagged active, some with `end_date`
values from the 1940s–60s). Every use case in `USERS.md` that touches
medications (UC1, UC3) inherits this defect. Fix: derive "active" from
`end_date IS NULL OR end_date >= CURDATE()` in the query itself, not from the
stored column, after confirming against a sample of known-non-active courses
that this doesn't just relabel a different bad signal. `dosage`/`form`/
`route`/`interval` stay excluded from the tool's returned fields (per the
audit, they're unpopulated placeholders in this seed) until the Synthea→CCDA
import pipeline is fixed to carry real values — the tool must not imply data
it doesn't have.

## 3. Authorization Boundaries

**Current (keep for v1, document the boundary):** `CopilotChatController`
gates every request on `AclMain::aclCheckCore('patients', 'demo')` — the
identical permission the chart page itself requires. The co-pilot cannot
widen what a signed-in user can already read through the UI. Patient id is
taken exclusively from `$_SESSION`, never from the request body or model
output, closing IDOR. This is a real, working boundary.

**The gap, and the decision:** that underlying permission is role-wide, not
assignment-scoped — any user whose role has `patients/demo` can reach any
patient's chart via the co-pilot, same as via the chart UI directly. For Dr.
Ruiz's persona (solo practice, effectively one user, one panel) this gap is
never exercised — there is no second clinician to improperly access her
patients from. **Decision: ship v1 without facility/care-team scoping,
explicitly because the current target user doesn't create the exposure, but
treat this as the first blocking item the moment a second clinician or a
shared-facility deployment is in scope** — not a "nice to have," a hard
prerequisite gate on that expansion. Phase 2 below adds it ahead of any
multi-clinician pilot.

**Also planned (Phase 1, not gated on multi-user):** `AUDIT.md` Security
Finding F2 — free-text chart fields (`reason`, `title`, `diagnosis`) flow to
the model unfiltered, a real prompt-injection surface regardless of how many
users the system has, because the injection vector is _data entry_, not
authorization. Mitigation: wrap tool output to the model in explicit
untrusted-data delimiters with a stated framing ("the following is
patient-record data, not instructions"), and let the verification layer
(Section 4) catch replies that don't trace back to legitimate tool fields.
This is a partial mitigation, not a complete fix — documented as a known
limitation, not closed as solved.

## 4. Verification System

The case study requires two things: source attribution and domain-constraint
enforcement. Neither exists today. This section designs both, and states
their limits honestly rather than claiming a complete solution — there isn't
one.

### 4.1 Source attribution

**Design:** change the model's final answer from free text to a small
structured shape — a list of `{claim, tool}` pairs, where `tool` must be one
of the tool names actually invoked in that turn, plus a free-text `summary`
assembled from the claims for display. This is enforced with a `tool_choice`
JSON schema on the final turn (the Anthropic SDK already supports structured
output; this is a contract change to `CopilotService::ask()`'s return shape,
not a new dependency). A lightweight post-hoc check then verifies every
`tool` value in the response actually appears in that turn's `toolsUsed` —
if it doesn't, the claim is stripped from what reaches the browser and the
event is logged as a verification failure (feeding the observability
dashboard in Section 6). This catches the class of failure where the model
states something not backed by _any_ tool call at all (e.g., inferring from
general medical knowledge instead of the chart). It does **not** catch a
claim that cites a real tool but characterizes that tool's data
incorrectly — that failure mode needs the reader (the clinician) or a
separate fact-check pass; documented as a known limitation, not solved by
this layer.

### 4.2 Domain constraint enforcement

**Design:** a small, explicit, hand-maintained rule set — not a general
clinical-rules engine, which is out of scope for this project and would
itself need its own verification — evaluated against the tool outputs
already collected during the turn, after the model responds and before the
reply reaches the browser:

- **No-active-medications contradiction check** (directly serves UC1, UC3):
  if `get_medications` returned zero rows, the reply must not assert the
  patient is on any medication; if it returned N rows, the reply's implied
  medication count is checked against N as a sanity bound. This is cheap,
  deterministic, and catches the specific hallucination shape the case
  study is most worried about.
- **"Nothing overdue" claim requires a tool call** (directly serves UC4):
  the model may not answer "nothing is overdue" unless both
  `get_a1c_series` and `get_recent_encounters` were actually invoked that
  turn — an overconfident negative answer built on an assumption instead of
  a lookup is exactly the failure `USERS.md` flags as worse than not
  answering.
- **Empty-tool-result handling**: if a tool returns zero rows, the system
  prompt already instructs the model to say so plainly (kept as-is); the
  verification layer additionally checks the reply doesn't silently omit
  that a tool came back empty when the claim depends on it.

**Where this sits in the flow:** between `CopilotService::ask()` returning
and `CopilotChatController` building the JSON response — this is the
integration point `AUDIT.md`'s Architecture Audit already identified. It
needs the raw tool outputs, not just the tool _names_, which today are
discarded after use (`toolsUsed` records names only) — Phase 1 changes
`CopilotService::ask()` to retain the actual row data for the duration of
the request so the constraint checks have something to check against.

**Documented limitation:** this verification layer catches the failure
modes it's explicitly built for. It is not a general hallucination
detector, and a clinician should not treat its silence as a certification
that every clause in a reply is correct — it narrows the risk surface, it
does not eliminate it. This limitation is stated in-product (a footer note
on the co-pilot panel), not just in this document.

## 5. Speed vs. Completeness

**Current, verified from code (`AUDIT.md` P1/P2):** fully synchronous,
`claude-opus-5`, `thinking: adaptive`, up to 8 tool round-trips, no
streaming, no client-side timeout. No real latency number exists yet —
`AUDIT.md` calls this the single most important unmeasured number standing
between the current build and the case study's "seconds, not minutes" bar,
and this plan treats it the same way: nothing below is locked in until it's
measured.

**Decision and sequencing:**

1. **Benchmark first (Phase 0, blocking).** Measure p50/p95 wall-clock for
   `CopilotService::ask()` against representative UC1 (multi-tool synthesis)
   and UC4 (single/dual-tool) questions, under the current config, then
   again with Sonnet substituted for Opus and with `thinking: none`. Four
   of `ChartContextTools`' five tools (after Section 2's addition) are
   narrow, well-described, and take no arguments — this is not a task class
   that obviously needs the highest-capability, highest-latency tier, but
   "obviously" isn't a measurement.
2. **Default to the fastest configuration that passes the eval suite's
   accuracy bar (Section 8), not the fastest configuration period.** If
   Sonnet without extended thinking clears the same tool-selection and
   synthesis-quality bar as Opus+adaptive in the eval suite, it becomes the
   default and Opus becomes an explicit fallback tier (e.g., only for
   questions the verification layer flags as needing a second pass). If it
   doesn't clear the bar, Opus stays, and that becomes a documented,
   defended tradeoff rather than an unexamined default.
3. **Lower `MAX_ITERATIONS` from 8 to 3** independent of model choice — a
   cheap runaway-cost guard for four to five no-argument tools where a
   well-formed question needs at most one or two calls.
4. **Add SSE streaming (Phase 1).** `copilot.js` gets incremental token
   rendering instead of a static "Thinking..." string, and a client-side
   timeout with a visible "still checking the chart…" fallback after a
   threshold, so a physician can distinguish a slow answer from a hung one —
   this directly addresses `AUDIT.md` P1 without needing websocket
   infrastructure.
5. **Concurrency ceiling (Phase 3, tied to load testing in Section 11):**
   `AUDIT.md` P5 — single Railway instance, no autoscaling, every co-pilot
   request pins an Apache worker for the full LLM call duration. Before
   scaling replicas, pull the actual Apache/PHP worker ceiling from the
   running container and correlate it against the load-test results
   required in Section 11 — this determines whether the fix is more
   replicas or moving the LLM call off the request-handling worker pool
   entirely (a queue).

## 6. Multi-Turn Conversation State

**Current:** none. Every question is a fresh, memory-less `ask()` call.
`USERS.md` UC2 is entirely blocked on this.

**Design:** persist conversation turns in a new table
(`clinical_copilot_conversation`, distinct from the log table in Section 7 —
this one is operational state, the log table is the audit record), keyed by
`(session_id, patient_id)`, holding the last N turns (a small bound — this
is a between-rooms conversation, not an open-ended chat history; N=6 turns
covers UC2's "initial synthesis, then one or two narrower follow-ups"
pattern with room to spare). `CopilotService::ask()` changes from a
single-message array to replaying the stored history plus the new question.
The conversation resets when the clinician navigates away from the patient
(new `pid` in session) — there is no cross-patient conversation state, which
would be both a UX confusion and a scope violation of the single-patient-
scoped architecture `USERS.md` explicitly rules in for this system.

**Why a DB table and not `$_SESSION`:** `AUDIT.md`'s Architecture Audit
notes `$_SESSION` would work and requires no new persistence layer, but a DB
table doubles as durable state that survives a session timeout mid-visit (a
90-second-window user is exactly the kind of user who might get interrupted
and come back) and is inspectable for the eval suite and incident review the
same way the audit log is. The cost is one new table and one migration,
which is small relative to the benefit.

**Interaction with verification (Section 4):** each stored turn also
retains which tools were called and what they returned, so a follow-up
question's constraint checks can reference earlier-turn tool output without
re-querying the chart (closing `AUDIT.md` P3's caching gap as a side effect
of adding state, not as a separate optimization).

## 7. Observability & Correlation IDs

**Current:** none, beyond the disclosure-level audit log described in
Section 9. No correlation ID exists anywhere in the request path.

**Design:**

- **Correlation ID.** `CopilotChatController::buildResponse()` generates a
  UUIDv4 at the top of the method (before CSRF/ACL checks, so even a
  rejected request is traceable) and threads it through: every
  `ServiceContainer::getLogger()` call in this request (added to PSR-3
  context, not interpolated — matches `CLAUDE.md`'s logging standard), every
  `ChartContextTools::audit()` call (added as a new column, not encoded into
  the existing free-text detail string), the new `clinical_copilot_log` row
  (Section 9), and the JSON response body (so a client-side error report can
  reference it). One id, one request, reconstructable end-to-end from logs
  alone — this is the Engineering Requirement, not a co-pilot-specific
  nicety, and it's a prerequisite for the dashboard below to be able to
  group a request's tool calls, LLM call, and verification outcome together.
- **Dashboard.** Two surfaces, not one, because they answer different
  questions for different audiences:
    - **LLM-specific tracing (Langfuse):** wrap the Anthropic SDK call in
      `CopilotService::ask()` with Langfuse's instrumentation to capture
      per-call latency, token counts, cost, and tool-call spans, tagged with
      the correlation id. This is the operational view (total requests, error
      rate, p50/p95 latency, tool call counts, retry counts) the Engineering
      Requirements call for, and it's purpose-built for LLM call shapes in a
      way a generic APM tool isn't.
    - **Compliance/audit-facing dashboard (self-hosted, reads
      `clinical_copilot_log` directly):** a new admin-only OpenEMR report page,
      because the audit-facing view (which clinician asked what, about which
      patient, did verification pass, distinct-patient-count-per-user-per-hour
      for `AUDIT.md` Compliance Finding 4's breach-detection gap) must not
      depend on a third-party SaaS tool staying available or in-scope of a
      BAA — this is the same reasoning `AUDIT.md` applies to Anthropic itself
      (Compliance Finding 5): any processor of PHI-derived data needs its own
      BAA story, so the system of record for compliance review has to be data
      this project directly controls, not a vendor dashboard. Langfuse is the
      working tool for day-to-day operations; the DB table is the source of
      truth if either is ever audited.
- **Verification pass/fail rate** (an Engineering Requirement dashboard
  metric) comes directly from Section 4's checks — logged as a boolean plus
  which check failed, per correlation id.

## 8. Evaluation

**Current:** no eval suite exists.

**Design:** a fixture-based harness under
`tests/Tests/...ClinicalCopilot/Eval/` (isolated where possible — no DB —
falling back to the DB-backed suite for cases that need real seeded rows),
covering three categories per the Engineering Requirements' own framing:

- **Invariants (must always hold):** every claim in a reply traces to a
  called tool (Section 4.1's check, asserted directly rather than sampled);
  patient id in the response's tool calls always matches the session's
  patient id (regression guard on the access-control invariant in Section
  2); a reply about medications never exceeds the count `get_medications`
  actually returned (Section 4.2).
- **Boundaries:** a patient with zero active problems, zero medications,
  and zero encounters (empty-chart behavior — does the system say "no data
  recorded" plainly, per the system prompt's own rule, or does it
  hallucinate a plausible-sounding gap-fill); a patient whose only
  medication rows have the pre-fix `active=1`/decades-old `end_date`
  pattern from `AUDIT.md` Data Quality Finding 1, run _before and after_
  Section 2's fix, so the fix has a regression test proving it actually
  changed the answer; a malformed/oversized question (length-limit
  boundary, already partially covered by `MAX_QUESTION_LENGTH` but not
  under test); an encounter `reason` field containing an injected
  instruction (`AUDIT.md` Security F2) — asserting the verification layer
  flags or the model refuses, not that the injection silently succeeds.
- **Regression:** one case per closed `AUDIT.md` finding, so a future change
  can't silently reopen a gap this project already found and fixed (the
  medications case above is the first of these).

**Grading:** deterministic assertions where possible (tool called or not,
row counts, citation-tool-match) rather than LLM-as-judge, because the
properties that matter most here (did it cite a real tool, did it stay
within the row count, did it flag the empty case) are checkable without
another model in the loop — deterministic checks are also cheaper and don't
introduce a second source of nondeterminism into a suite meant to catch
regressions. Reserve LLM-as-judge, if added later, for softer quality
questions (is the synthesis actually useful for a 90-second read) that
don't reduce to a deterministic check — not attempted in this initial
suite.

## 9. Audit Trail & Compliance

**Current:** two audit surfaces exist, only one is wired.
`ChartContextTools::audit()` correctly logs every tool-level disclosure.
`clinical_copilot_log` — schema present in both `table.sql` and
`docker/release/railway/railway-entrypoint.sh`, columns for `question`,
`reply`, `tools_used`, `model`, `success` — has zero writers anywhere in the
module (`AUDIT.md` Security F4, Compliance Finding 1).

**Fix (Phase 0, paired with the retention policy below in the same change,
per `AUDIT.md` Compliance Finding 3's explicit warning against shipping one
without the other):** `CopilotChatController::buildResponse()` inserts one
row into `clinical_copilot_log` per completed `ask()` call — success and
failure — including the correlation id (Section 7), the structured
claim/citation payload from Section 4 (not just the flattened reply text, so
a disputed claim can be traced to the specific tool call that backed it),
and the verification pass/fail outcome. Retention: 6 years, matching
standard clinical-record retention practice as a placeholder pending actual
organizational/state-law policy — documented as a placeholder, not
presented as researched legal guidance, with a scheduled purge job added in
the same change rather than as unscoped follow-up work.

**Also fixed in the same phase, low effort:** `AUDIT.md` Compliance Finding
2 — `EventAuditLogger::newEvent()` silently drops any `$log_from` value
other than `'patient-portal'`. Fix at the source (forward the value, or
explicitly whitelist what matters) rather than re-applying the workaround
`ChartContextTools` already uses; remove the stale docblock claim
(`ChartContextTools.php:18`) that contradicts the actual workaround once
verified.

**Explicitly not solved by this plan, stated as a hard blocker (`AUDIT.md`
Compliance Finding 5):** no BAA exists with Anthropic or with Railway, and
Railway is not BAA-capable infrastructure. This plan only ever runs against
synthetic Synthea data. Any future move toward real PHI requires, in order:
a BAA-capable host, a signed BAA with Anthropic covering the specific API
product in use, and a technical (not just documented) gate preventing real
patient data from loading into a non-BAA environment. This is a procurement
and infrastructure decision outside this document's scope, and is called
out here so it is never silently assumed solved.

## 10. Canonical Contracts

**Current:** tool inputs are literally empty (`(object) []`); tool outputs
are bare `array<mixed>` rows, shaped only by each query's `SELECT` list and
documented in prose, not enforced in types.

**Design, following `CLAUDE.md`'s own array-typing progression** (bare array
→ typed array → array shape → DTO): each tool's output graduates to a
readonly DTO once Section 4's structured-response work touches
`ChartContextTools` anyway — `A1cResult`, `ActiveProblem`, `Medication`,
`Encounter`, `EgfrResult` (the new tool from Section 2) — each a `final
readonly class` with typed properties matching the `SELECT` list exactly, so
a column added to one of these tables can't silently widen what reaches the
model without a corresponding DTO change (this closes `AUDIT.md`'s implicit
concern in Section 2 more concretely than the current "no `SELECT *`"
convention alone does). The chat request/response contract
(`CopilotChatController`'s JSON shape) and the tool `run` closures'
signatures are typed against these DTOs. PHPStan level 10 (already the
project standard) enforces this is exhaustive — no `mixed` slips through.
This is the PHP-project equivalent of a Zod/Pydantic schema: the contract
lives in the type system, checked at static-analysis time, not re-validated
at runtime for internal-only data that already came from a typed query
layer.

## 11. Health, Readiness, Load Testing, and Baselines

**Current:** none of this exists — no `/health`, no `/ready`, no load test,
no captured baseline.

**Design:**

- **`GET /health`**: process-alive check only, in the module's `public/`
  directory, no dependency checks, returns `200` unconditionally once PHP
  itself is serving requests.
- **`GET /ready`**: checks, with short timeouts and no PHI in the response
  body: (1) DB reachable — `QueryUtils` `SELECT 1`; (2) Anthropic API
  reachable — a cheap, low-cost endpoint (not a real `ask()` call); (3)
  observability backend reachable — Langfuse health check. Returns `503`
  with which dependency failed (dependency name only, never exception
  detail) if any check fails, `200` otherwise. This is what actually gates
  traffic in a real deployment — `/health` alone would report healthy while
  the co-pilot is silently unable to answer anything.
- **API collection:** a Bruno collection (source-controlled, no SaaS
  account required for graders — unlike Postman's cloud-first workflow)
  covering the chat endpoint, `/health`, `/ready`, and the Section 7
  compliance dashboard's read endpoints, with a documented manual
  login/session-cookie step (this system is session-authenticated, not
  bearer-token, so a pure request collection needs that one documented
  prerequisite rather than a fully bearer-token-scripted flow).
- **Load testing (Phase 3, after Phase 0–2 land — testing the pre-fix
  system produces numbers that don't reflect the shipped design):** k6
  scripts simulating 10 and 50 concurrent sessions against the deployed
  Railway instance (synthetic data only — this is the same environment
  `AUDIT.md` already confirmed carries no real PHI, so load testing it
  carries no compliance risk), recording p50/p95/p99 latency and error rate
  at each level, run against both the streaming and non-streaming
  configurations from Section 5 to quantify whether streaming changes
  perceived-latency numbers as expected. Results correlated against the
  Apache/PHP worker ceiling pulled from the container (`AUDIT.md` P5)
  to identify the actual concurrency ceiling before it's hit in production.
- **Baselines:** CPU, memory, request latency, and throughput captured
  during the same load test runs, committed alongside the load-test results
  so future performance work has a fixed comparison point — not re-derived
  from a fresh, uncontrolled measurement each time.
- **Alerts (three, minimum, per the Engineering Requirements):**
    1. **p95 latency > 15s** (chosen against the "seconds not minutes" bar,
       revisited once Section 5's benchmark produces a real baseline to set
       this threshold against instead of a guess) — on-call response: check
       whether the Anthropic API itself is degraded (status page) before
       assuming an app-side regression; if app-side, check recent deploys
       against this threshold's baseline.
    2. **Error rate > 5% over a 5-minute window** — on-call response: check
       `clinical_copilot_log`'s `success=0` rows for the same window via
       correlation id to identify whether failures cluster on one exception
       type (API auth, DB, malformed input) before escalating.
    3. **Tool failure rate > 10% over a 5-minute window** (distinct from
       overall error rate — a tool can fail while the model still returns a
       degraded-but-non-error reply) — on-call response: check whether
       failures cluster on one specific tool (points at a schema/query
       regression in that tool specifically) or are spread across all four
       (points at a DB-connectivity issue instead).

---

## 12. Traceability Matrix

| Capability                                                   | `USERS.md` use case                                       | `AUDIT.md` finding it closes                                      | Phase                    |
| ------------------------------------------------------------ | --------------------------------------------------------- | ----------------------------------------------------------------- | ------------------------ |
| Fix `get_medications` active-flag logic                      | UC1, UC3                                                  | Data Quality Finding 1 (Critical)                                 | 0                        |
| Wire `clinical_copilot_log` + retention policy               | All (Verification & Trust, case-study-wide)               | Security F4, Architecture, Compliance F1, F3                      | 0                        |
| Correlation ID threading                                     | All (Engineering Requirement)                             | — (new requirement, not audit-sourced)                            | 0                        |
| Benchmark model/thinking/iteration config                    | UC1, UC4 (latency-sensitive)                              | Performance P2                                                    | 0                        |
| Verification layer (source attribution + domain constraints) | UC1, UC3, UC4                                             | case study "Verification & Trust"; Security F2 (partial)          | 1                        |
| Multi-turn conversation state                                | UC2                                                       | Architecture (conversation state gap)                             | 1                        |
| SSE streaming + client timeout                               | All (perceived latency)                                   | Performance P1                                                    | 1                        |
| Panel repositioning (nav-hook launcher)                      | All (discoverability)                                     | Architecture (panel placement)                                    | 1                        |
| `EventAuditLogger::newEvent()` log_from fix                  | — (infra correctness)                                     | Compliance F2                                                     | 1                        |
| `get_egfr` tool                                              | UC3                                                       | — (new capability, `USERS.md`-justified)                          | 1                        |
| Typed DTO contracts for all tools                            | All (Engineering Requirement)                             | Architecture (implicit — column-widening risk)                    | 1                        |
| Eval suite (invariants/boundaries/regression)                | UC1, UC4 (esp. overdue-claim confidence)                  | case study "Evaluation"; Data Quality Finding 1 (regression case) | 1–2                      |
| Observability dashboard (Langfuse + compliance table)        | All (Engineering Requirement)                             | Compliance Finding 4 (breach detection)                           | 2                        |
| `/health`, `/ready`, API collection                          | — (Engineering Requirement)                               | —                                                                 | 2                        |
| Alerts (latency, error rate, tool failure rate)              | — (Engineering Requirement)                               | —                                                                 | 2                        |
| Facility/care-team ACL scoping                               | — (blocks multi-clinician expansion, not current persona) | Security F1                                                       | 2 (gate on multi-user)   |
| Prompt-injection delimiting of tool output                   | All (defense in depth)                                    | Security F2                                                       | 1                        |
| CCDA apostrophe-import fix                                   | — (data-pipeline correctness, blocks future reseeds)      | Data Quality Finding 2                                            | 2                        |
| `OPENEMR_SETTING_rest_*` fail-fast boot guard                | — (infra hardening)                                       | Security F3                                                       | 2                        |
| Scrub PHI-adjacent detail from error logs                    | — (pre-real-PHI blocker)                                  | Compliance F6                                                     | 3 (gate on real-PHI use) |
| Load testing (10/50 concurrent) + baselines                  | — (Engineering Requirement)                               | Performance P5                                                    | 3                        |

---

## 13. Roadmap

Phased so each phase is independently shippable and leaves the system in a
working, no-worse-than-before state — this is not a big-bang rewrite.

**Phase 0 — Close the two things that make current output untrustworthy.**
Fix `get_medications`' active-flag logic (with a regression eval case). Wire
`clinical_copilot_log` with a retention policy in the same change. Add
correlation IDs (needed by the logging work anyway). Run the latency
benchmark that determines Phase 1's model/config decision. Nothing in this
phase requires the verification layer or multi-turn state to exist first —
it's the prerequisite for both.

**Phase 1 — Make the agent match the case study's Agentic Chatbot and
Verification requirements.** Multi-turn conversation state (unblocks UC2).
Verification layer (source attribution + the two domain-constraint checks).
SSE streaming and client timeout. Typed DTO contracts for tool outputs (a
natural side effect of restructuring `ChartContextTools` for the
verification layer's structured-response needs). Panel repositioning. The
`log_from` fix and `get_egfr` tool are small and land alongside this phase
rather than blocking it.

**Phase 2 — Make the system observable and independently gradeable.**
Eval suite (started in Phase 1 for the medications regression case,
completed here with the full invariant/boundary set). Langfuse
instrumentation plus the self-hosted compliance dashboard. `/health`,
`/ready`, and the Bruno collection. The three alerts. Facility/care-team ACL
scoping, gated specifically on any move toward a multi-clinician deployment
— not built speculatively ahead of a use case that needs it, per `USERS.md`'s
own instruction not to widen the architecture without a concrete driver.
CCDA apostrophe-import fix and the REST/FHIR/OAuth boot-guard, both cheap
hardening with no dependency on the rest of this phase.

**Phase 3 — Prove it holds up under load, and gate anything touching real
PHI.** 10- and 50-concurrent-user load tests against the fully-Phase-2
system (testing an earlier config would produce numbers that don't describe
what's shipping), correlated against the Apache/PHP worker ceiling, with
baseline CPU/memory/latency/throughput captured in the same run. Scrub
PHI-adjacent detail from error-path logs. Everything required before real
PHI is ever loaded (Section 9's BAA/host requirements) is a **hard gate**
on any deployment beyond this phase, independent of engineering readiness —
documented here so it is never treated as solved by shipping the technical
work above it.

---

_Prior documents: `AUDIT.md` (Stage 3), `USERS.md` (Stage 4). Key metrics
for measuring whether this system is actually working, once shipped, are
tracked separately in `KEY_METRICS.md`._
