# USERS.md — Clinical Co-Pilot Target User & Use Cases

Stage 4 deliverable per `.claude/AgentForge.md`. This is the source of truth
`ARCHITECTURE.md` (Stage 5) must trace back to — every agent capability
planned there should point to a use case here, and every use case here
should be checked against the gaps already surfaced in `AUDIT.md`.

## Target user

**Dr. Elena Ruiz — solo community endocrinologist, general endocrinology
practice weighted toward type 2 diabetes management.**

Not "physicians need help finding information." A specific, narrow person:

- Solo or small-group practice, no dedicated scribe or care coordinator —
  she does her own chart review between patients.
- Sees 20–24 patients across a full clinic day, mostly 15–20 minute
  follow-ups booked back-to-back, with the ~90-second gap between rooms the
  case study specifies.
- Her panel is predominantly **chronic-disease follow-up**, not new-patient
  workup or acute triage: type 2 diabetes (majority), thyroid disease, some
  osteoporosis. A given patient might be her 6th, 10th, or 20th visit — she
  already knows them; what she needs each time is what's *different* since
  last time, not a first-time orientation to the chart.
- This is a deliberately different shape of user than the case study's other
  named examples (an ED resident meeting a stranger, a hospitalist rounding
  on inpatients). Dr. Ruiz's problem isn't "orient me to an unfamiliar
  patient fast" — it's "confirm my mental model of a patient I already
  manage is still accurate, in the time it takes to walk down the hall."

This choice is not arbitrary: it's the persona the fork's own data and
tooling were already built around. The seed dataset was specifically
augmented to carry a full HbA1c history per patient (see project memory
`synthea-ccda-drops-a1c` — "discovered while seeding endocrinology data"),
and the co-pilot's first tool, `get_a1c_series`, exists for exactly this
user. Choosing Dr. Ruiz makes the existing build legible instead of
retrofitting a persona onto it after the fact.

**Explicitly out of scope for this persona** (contrast, not a roadmap item):
a hospitalist juggling twelve unfamiliar admissions, an ED resident doing
overnight intake, a nurse taking vitals, a biller. Building for Dr. Ruiz
means prioritizing longitudinal trend synthesis for known patients over
broad first-contact chart summarization — a different tool, a different
system prompt, and a different notion of "useful" than those other users
would need.

## The 90-second window

Thirty seconds before she opens the co-pilot: she has just finished with
the prior patient and is walking to the next room. She glances at the day
sheet — a name and a one-line visit reason ("T2DM, 3-month f/u"). She has
not had time to open the chart, scroll the problem list, cross-reference
the med list against the last visit's plan, and eyeball a lab flowsheet —
that's the 5-minute version of chart review, and she has 90 seconds.

What she needs from the co-pilot in that window: not the raw data (she
could get that from the chart itself, slower) but a synthesized answer to
"is this patient's diabetes better, worse, or stable since I last saw them,
and is there anything new I should know before I walk in." What she does
with the answer: forms a mental model before entering the room, then uses
the visit itself to confirm or discuss it with the patient — the co-pilot
primes the conversation, it doesn't replace it.

## Use cases

Each use case states the trigger, what's asked, what data it needs, why a
conversational agent — not a dashboard, a sorted list, or a better chart
view — is the right shape, and its current build status against
`AUDIT.md`.

### UC1 — Pre-room synthesis: "what's changed since last visit"

**Trigger:** 60–90 seconds before entering the room, day sheet already
glanced at.
**Question:** *"What's changed for this patient since their last visit?"*
**Needs:** A1c trend and direction (`get_a1c_series`), whether any problem
was added/resolved (`get_active_problems`), whether the medication regimen
changed (`get_medications`), and what actually happened at the last visit
(`get_recent_encounters`) — four different data types, correlated into one
judgment.

**Why an agent, not a dashboard:** the value isn't *displaying* four
widgets, it's *judging* whether the A1c moved meaningfully, whether a new
problem appeared, and whether that's consistent with a medication change
already on file — a synthesis task across time and across data types. A
dashboard would make Dr. Ruiz do that correlation herself, at exactly the
moment (walking between rooms) she has the least time to do it. She also
doesn't know in advance which of the four signals will matter for *this*
patient — a fixed set of tiles forces her to scan all of them every time;
a conversational "tell me what changed" lets the synthesis work happen
once, on her behalf.

**Status:** the four tools this depends on are built and, per `AUDIT.md`,
mostly sound — except `get_medications`, where the Data Quality audit found
100% of seeded prescriptions marked "active" regardless of end date (some
from the 1940s–60s). This use case is the primary reason that bug is
Critical, not cosmetic: it directly produces a wrong "did their meds
change" answer for the single most time-pressured use case in this
document. Must be fixed in the import pipeline before this use case can be
trusted, per `AUDIT.md`'s priority list.

### UC2 — Mid-visit targeted follow-up (multi-turn)

**Trigger:** during the visit itself — the patient says something ("I've
been more tired lately") that prompts a quick, specific check without Dr.
Ruiz breaking eye contact to navigate the chart UI.
**Question:** an initial synthesis question, then a narrower follow-up in
the same conversation — e.g. *"and how long has that problem been on the
list?"* or *"was that medication change at the last visit or the one
before?"*

**Why an agent, not a dashboard:** this is specifically a conversation, not
a lookup — the value is that the second question doesn't require
re-navigating anything; it's asked exactly as specifically as the moment
demands, in the physician's own words, mid-conversation with the patient.
A static report can't be interrogated; a chat that remembers what was just
discussed can.

**Status:** **not currently supported.** `AUDIT.md`'s Architecture Audit
found no conversation state exists anywhere — each question is sent as a
brand-new, memory-less request; a follow-up today gets no benefit from
what was just asked. This is the clearest concrete gap between an existing
use case and the current build, and it's why multi-turn state is item 4 on
`AUDIT.md`'s cross-cutting priority list, not an optional nice-to-have.

### UC3 — Medication-safety cross-check before adjusting therapy

**Trigger:** Dr. Ruiz is considering titrating a diabetes medication or
adding a new one, and wants a fast sanity check against the patient's other
conditions before committing to a plan out loud.
**Question:** *"Any active problems that would make me reconsider adjusting
their diabetes regimen?"* — a question that requires reading
`get_active_problems` (e.g., chronic kidney disease, cardiac history)
*against* `get_medications`, not either list alone.

**Why an agent, not a dashboard:** this is a cross-referencing judgment
(does condition X change what's safe to prescribe), not a retrieval — the
same argument as UC1, but time-critical in a different way: it's a safety
check she wants answered in the moment of deciding, not a report she reads
beforehand. A list of problems next to a list of meds still requires her to
do the cross-referencing; the agent's job is to do that correlation and
flag it.

**Status:** partially supported and explicitly gated on two `AUDIT.md`
findings: the `get_medications` data-quality bug (UC1) has to be fixed
first, since a med list that can't distinguish current from decades-old
prescriptions can't support a safety check either. Renal-function context
(e.g. eGFR) isn't exposed by any current tool — this use case is the
concrete justification for adding one in Stage 5, not a hypothetical
"more tools would be nice."

The cross-check is also incomplete without label-level safety context, a
second, separate gap from the eGFR one above: this use case's own worked
example — a condition that "would make me reconsider adjusting their
diabetes regimen" — is precisely a `contraindications`/`drug_interactions`/
`warnings` fact that lives in the drug's own FDA label, not in this
patient's chart. `get_active_problems` and `get_medications` can tell Dr.
Ruiz *what* the patient has and takes; neither can tell her whether a given
adjustment is contraindicated for a patient with that condition — that
answer lives in guideline/label text, external to the chart by definition.
`search_guideline_evidence` (via `EvidenceRetrieverWorker`, hybrid
sparse+dense retrieval over a small FDA drug-label corpus) is the concrete
tool this gap calls for, so a UC3 answer can cite label guidance (e.g.
"metformin is contraindicated in severe renal impairment, per its label")
alongside chart data, rather than only restating the chart's two lists next
to each other and leaving the cross-reference to her. This supplements,
not replaces, the eGFR/renal-function chart-data gap above — that gap is
about a missing *lab value*; this one is about missing *label guidance*,
and closing one does not close the other.

### UC4 — End-of-encounter gut-check: "did I miss anything"

**Trigger:** just before closing the visit and moving to the next room —
a last check that nothing overdue was overlooked.
**Question:** *"Is anything overdue for this patient — labs, follow-up
interval?"* e.g. no A1c drawn in the expected interval, or the gap since
the last encounter is longer than her usual follow-up cadence for this
condition.

**Why an agent, not a dashboard:** "overdue" is relative to a clinical
interval judgment (typically ~3 months for A1c in an uncontrolled diabetic,
~6 for a controlled one) that a static "last A1c: 4 months ago" tile
doesn't express — the agent has to reason about what's overdue *for this
patient's situation*, not just surface a raw date. This is a closing
safety net, deliberately asked in conversational form so it costs her one
sentence, not a second screen to check.

**Status:** supported today at the data level (`get_a1c_series` and
`get_recent_encounters` both exist and, per `AUDIT.md`, are correctly
indexed and return clean date data), but the "is this actually overdue for
this patient" judgment depends on the model reasoning about clinical
intervals rather than a hardcoded threshold — this is the use case most
directly testing the Verification & Trust requirement, since an
overconfident "nothing overdue" when something actually is would be a
worse failure than not asking at all. Should be a priority case in the
eval suite (a required deliverable, not built yet).

## What this rules in and out for Stage 5

`ARCHITECTURE.md` should treat UC1 and UC4 as buildable now, contingent on
the `get_medications` fix; UC2 as requiring conversation-state design
before anything else in this document can safely expand; and UC3 as the
concrete driver for two further tools this system needs — renal function /
eGFR (chart data) and `search_guideline_evidence` (label/guideline
evidence) — not a speculative addition. No use case in this document requires
schedule-wide or cross-patient capability — the co-pilot's existing
single-patient-scoped architecture (patient id fixed server-side, per
`AUDIT.md`'s Security Audit) is the right shape for this user and should
not be widened without a new use case that actually needs it.
