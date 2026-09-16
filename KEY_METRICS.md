# KEY_METRICS.md — Clinical Co-Pilot Success Metrics

Hard Gate per `.claude/AgentForge.md`'s "Key Metrics" section. This document
answers one question precisely: what does "the Clinical Co-Pilot is working"
mean for Dr. Elena Ruiz (`USERS.md`), and what small set of numbers would
prove it to a hospital CTO deciding whether to keep it in front of her.

**Status: none of these metrics can be measured today.** The instrumentation
they depend on — `clinical_copilot_log`, the verification layer, correlation
IDs, Langfuse tracing — is designed in `ARCHITECTURE.md` (Sections 4, 7, 9)
but not yet built; that work is Phase 0–2 of the roadmap there. This document
defines the metrics precisely *now*, in advance of instrumentation, so the
build target is unambiguous — matching the case study's own framing: "the
skill is deciding, in advance, what success actually means... not 'we're
measuring a lot,' but 'we know exactly what we're claiming.'" Numeric
targets below are stated as provisional hypotheses pending the Phase 0
latency benchmark (`ARCHITECTURE.md` Section 5) and an initial usage period,
not invented false precision — each is marked accordingly.

## How to read each metric

Definition (exact formula), why it's the signal that matters for this
specific user and this specific case study requirement (not a generic
AI-product metric), how it's measured (which piece of `ARCHITECTURE.md`'s
design it depends on), a provisional target, and what it deliberately does
**not** tell you — every metric has a blind spot, and naming it here is
part of defending the metric, not a weakness to hide.

---

## Primary metrics (the set to defend)

### 1. Claim-Grounding Rate

**Definition:** of all individual claims the agent makes across all
conversations in a period, the percentage that passed the source-attribution
check (`ARCHITECTURE.md` §4.1 — every claim cites a tool actually called
that turn) on first pass, before any stripping.

**Why this, for this user:** this is the case study's Verification & Trust
requirement made numeric. Dr. Ruiz has no time in a 90-second window to
independently check a claim against the chart — the entire value
proposition depends on not needing to. A claim that fails this check and
gets silently stripped never reaches her, so this metric is not a
user-facing harm rate; it's a **leading quality indicator**. A falling rate
means the underlying model, prompt, or tool descriptions are degrading
*before* a bad answer ever gets close to a physician — the earliest place
this system can catch its own failure.

**Measured via:** the verification layer's pass/fail log, keyed by
correlation id (`ARCHITECTURE.md` §4, §7).

**Provisional target:** 99%+. Anything below that on a system with only
four to five narrow, well-described, zero-argument tools indicates a
structural problem (prompt, tool description, or model choice), not noise
to average away.

**Blind spot:** catches "not backed by any tool call at all." Does **not**
catch a claim that cites a real tool but mischaracterizes what it returned
(`ARCHITECTURE.md` §4.1's stated limitation) — that failure mode isn't
visible in this number at all, which is exactly why Metric 2 exists
separately rather than being folded into this one.

### 2. Verified Incident Rate

**Definition:** count of clinician-reported disputes — "the co-pilot told
me something that wasn't right" — per 1,000 conversations, confirmed
against the retained `clinical_copilot_log` record of what was actually
said (`ARCHITECTURE.md` §9).

**Why this, for this user:** this is the single metric a hospital CTO will
ask for first, and it is the one Metric 1 cannot see — a claim that
correctly cites a tool but mischaracterizes the data underneath it. It
requires a human in the loop (a one-click "flag this" affordance on the
co-pilot panel, not yet built — noted here as a required companion feature
this metric depends on, and tracked as an open item, not assumed to exist).
This is the honest, lagging counterpart to the leading indicator above:
Metric 1 tells you the system is behaving as designed; this tells you
whether "as designed" is actually sufficient.

**Measured via:** the flag affordance (to be built) writing against the
correlation id; confirmed, not just counted, by pulling the exact stored
reply and tool outputs for that conversation — the entire reason
`ARCHITECTURE.md` §9 wires the log table in the first place is so this
confirmation step is possible at all.

**Provisional target:** treated as zero-tolerance, not a rate to optimize
downward gradually. Any confirmed incident is a stop-and-review event, not
a data point on a trend line — a single confirmed wrong clinical claim is a
CTO-level event regardless of how many thousands of correct answers
preceded it. The *rate* is tracked so a pattern (e.g., clustering on one
tool or one question type) is visible, not to normalize an acceptable
non-zero baseline.

**Blind spot:** depends entirely on Dr. Ruiz noticing and flagging a wrong
claim. An error she doesn't catch (because it's plausible-sounding, per the
case study's own framing of the danger) doesn't appear here. This is the
sharpest limitation in this document and is stated plainly: this metric
measures *detected* incidents, not *actual* incidents, and the gap between
the two is unmeasurable by definition. Metric 1 and the domain-constraint
checks in `ARCHITECTURE.md` §4.2 exist specifically to shrink that gap
upstream, since this metric alone cannot.

### 3. p95 Time-to-Answer

**Definition:** 95th-percentile wall-clock time from question submitted to
full reply rendered, measured separately for single-tool and multi-tool
questions (they have structurally different cost, per
`ARCHITECTURE.md` §5).

**Why this, for this user:** the case study's constraint is explicit —
"an answer in seconds, not minutes" — and Dr. Ruiz's window is 90 seconds
total, not just for the co-pilot. p95, not average, because the failure
mode that matters is a slow outlier landing exactly when she's already
walking into the room, not the typical case.

**Measured via:** Langfuse tracing on `CopilotService::ask()`, tagged by
correlation id (`ARCHITECTURE.md` §7); requires the Phase 0 benchmark to
run first (§5) since no real number exists yet under any configuration.

**Provisional target:** not set numerically here on purpose — deferred to
the Phase 0 benchmark's output rather than picked in advance. Precedent
from `AUDIT.md`: inventing a latency SLA before measuring the current Opus
+ adaptive-thinking baseline would repeat the exact mistake the audit
flagged (locking in a production config before measuring it). Once the
benchmark lands, this section gets a real number and stops being a
placeholder — tracked as an explicit TODO against `ARCHITECTURE.md`
Phase 0, not left silently unresolved.

**Blind spot:** a fast wrong answer is worse than a slow right one; this
metric says nothing about correctness and must never be read alone — always
paired with Metric 1 in any dashboard view, never reported in isolation.

### 4. Audit Completeness Rate

**Definition:** percentage of completed conversations with a fully populated
`clinical_copilot_log` row — question, reply, tools used, model, correlation
id, verification outcome — against total conversations attempted.

**Why this, for this user:** this is the direct, numeric fix for
`AUDIT.md`'s highest-severity cross-cutting finding: today, if a claim is
disputed, there is no record of what was actually said. This metric is the
proof that gap stays closed, not just closed once at launch. It is also the
concrete, numeric answer to the case study's "every claim... must be
traceable" requirement and to HIPAA §164.312(b)'s audit-control requirement
(`AUDIT.md` Compliance Finding 1).

**Measured via:** a count query against `clinical_copilot_log` compared
against total requests reaching `CopilotChatController::buildResponse()`
(`ARCHITECTURE.md` §9).

**Provisional target:** 100%, no exceptions, including failed/errored
requests (the design in §9 logs both success and failure paths for exactly
this reason). This is a completeness metric, not a quality metric — it has
no acceptable partial value the way a latency or grounding rate might; a
gap here means an entire conversation is legally and clinically
unreconstructable, not "mostly reconstructable."

**Blind spot:** proves a record exists, says nothing about whether the
record is accurate to what actually happened (e.g., a logging bug that
writes a malformed reply). Paired in review with periodic manual spot-checks
of logged rows against Langfuse trace data, not assumed correct because the
row exists.

### 5. Adoption Rate per Eligible Visit

**Definition:** of Dr. Ruiz's patient visits on a given clinic day, the
percentage where the co-pilot was actually opened and used (at least one
question asked) before or during the visit.

**Why this, for this user:** every other metric in this document can be
excellent and the product can still fail, because none of them measure
whether she actually reaches for it. `USERS.md`'s own closing standard is
"the agent is the thing the user would actually choose" — not that it's
technically capable. This is the only metric here that tests product-market
fit for this one user rather than system correctness, and it's the metric
most likely to reveal a problem none of the others would catch (e.g., the
panel's discoverability issue `AUDIT.md`'s Architecture Audit already
flagged — a technically perfect co-pilot she never opens scores zero here
regardless of how the other four metrics look).

**Measured via:** panel-open and question-submitted events correlated
against the day's scheduled visit count (from existing OpenEMR scheduling
data, not new infrastructure) — this is the one metric in this set that
doesn't depend on `clinical_copilot_log` at all, and can be instrumented
earlier than the others as a result.

**Provisional target:** not fixed numerically here either — genuinely
unknown until real usage exists, and asserting a number now (e.g., "70% of
visits") would be exactly the kind of unearned precision this document
argues against elsewhere. What's fixed is the *direction of concern*: a
rate that stays low or declines after the discoverability fix
(`ARCHITECTURE.md` Phase 1 panel repositioning) ships is the signal that the
problem isn't placement, it's usefulness — and that distinction is the
actual point of tracking this metric before-and-after that specific change.

**Blind spot:** usage isn't the same as value — she might open it out of
habit even when it doesn't help, or avoid it on days it would have helped
because of one bad recent experience (which Metric 2 would also catch,
which is why these two are read together, not separately).

### 6. Cross-Patient Access Integrity

**Definition:** count of tool invocations, across all conversations, where
the patient id embedded in the audit log entry does not match the patient
id in the originating session — should always be exactly zero.

**Why this, for this user:** the case study names authorization as a hard
problem explicitly, and `ChartContextTools`' entire design
(`ARCHITECTURE.md` §2) rests on this never happening *by construction*
(the model is never given a patient-id parameter to misuse). This metric
doesn't measure a rate to optimize — it's a **security canary**: since the
architecture makes this structurally impossible today, its only job is to
prove that invariant hasn't silently broken (e.g., a future tool added
without following the zero-argument pattern `ARCHITECTURE.md` §2 requires
of every new tool).

**Measured via:** a scheduled query joining `clinical-copilot-tool` audit
events against session records; trivial to compute from existing data
(no new instrumentation required beyond what `ChartContextTools::audit()`
already writes today).

**Target:** 0, always. Any non-zero value is a Sev-1 security incident, not
a metric to trend.

**Blind spot:** none within its own scope — this is a narrow structural
check, and its narrowness is the point. It says nothing about the *role-wide*
ACL gap `AUDIT.md` Security Finding F1 documents (a user legitimately
allowed to view a chart via the co-pilot, who shouldn't be) — that's a
policy question this metric cannot detect by design, since it only checks
"did the tool respect the session's own patient," not "should this session
have this patient at all." Tracked separately as the Phase 2 facility/
care-team scoping gate in `ARCHITECTURE.md` §3, not conflated with this
metric.

---

## Secondary / supporting operational metrics

Tracked on the observability dashboard (`ARCHITECTURE.md` §7) alongside the
six above, but not part of the primary defend-to-a-CTO set — these answer
"is the system healthy" rather than "is the product succeeding":

- **Tool failure rate** (already an Engineering Requirement alert,
  `ARCHITECTURE.md` §11) — operational health of the DB/query layer, not a
  clinical-trust signal on its own.
- **Cost per conversation** (tokens × rate, from Langfuse) — relevant to
  whether the model-choice decision in `ARCHITECTURE.md` §5 stays
  sustainable at real usage volume; a business-viability number, not a
  clinical one.
- **p95/p99 latency under load** (10/50 concurrent users,
  `ARCHITECTURE.md` §11) — infrastructure capacity, distinct from Metric 3's
  single-user experience number.

## Metrics considered and rejected

Named explicitly, because the case study warns against "a dump of
everything" — these were considered and are deliberately left out, not
overlooked:

- **Total questions asked (raw count).** A vanity metric — high volume
  with a low Metric 1 or a low Metric 5 would still mean the product is
  failing; volume alone carries no signal about success as defined here.
- **Average session length / turns per conversation.** Ambiguous in either
  direction — a longer conversation could mean UC2's follow-up flow is
  working well, or could mean the first answer wasn't good enough and she's
  digging for it herself. Without a way to distinguish the two, this number
  doesn't support a claim either way.
- **Model self-reported confidence.** Not used as a metric anywhere in this
  system, and deliberately excluded here too — LLM confidence expressions
  are not calibrated probabilities and treating one as a trustworthy signal
  would itself be a form of the overconfidence risk the case study warns
  against.
- **Number of tools called per session.** An implementation detail of how
  a question got answered, not a signal of whether the answer was good or
  fast enough — already implicitly covered by Metric 3 (which splits by
  single- vs. multi-tool) where it actually matters.

---

*Depends on `ARCHITECTURE.md` Phases 0–2 for instrumentation (correlation
IDs, `clinical_copilot_log`, the verification layer, Langfuse tracing) and
on a not-yet-built "flag this answer" affordance for Metric 2 specifically —
tracked as an open item against Phase 1. Prior documents: `AUDIT.md`
(Stage 3), `USERS.md` (Stage 4), `ARCHITECTURE.md` (Stage 5).*
