# Clinical Co-Pilot — Stage 3 Audit

Scope: this fork of OpenEMR (`ef3d490` initial import onward) plus the Clinical Co-Pilot
module (`interface/modules/custom_modules/oe-module-clinical-copilot`) and its Railway
deployment (`docker/release/railway/`). Performed as five parallel passes — security,
performance, architecture, data quality, compliance & regulatory — per the Stage 3
requirements in `.claude/AgentForge.md`. Method: static code review across all five
domains, live queries against the seeded 46-patient `development-easy` database for the
data quality pass, and live inspection of the deployed Railway instance's boot logs/config
for compliance and security infrastructure questions. No dynamic penetration testing and
no load testing were performed in this pass — those are separate Engineering Requirements
covered later.

## Summary

This audit covers five required passes against the OpenEMR fork and its Clinical
Co-Pilot module, plus the Railway infrastructure it runs on. The headline finding,
surfacing independently across three of the five passes (security, architecture,
compliance), is that **the co-pilot audits what data it read but not what it told the
clinician.** `ChartContextTools::audit()` correctly writes a standard OpenEMR audit-log
entry for every tool call — this part works and is well-built. But the
`clinical_copilot_log` table that exists specifically to record each question/reply/model
triple has zero writers anywhere in the module. If a clinician disputes what the AI said
about a patient, or an incident review needs to reconstruct exactly what claim was made,
that text does not exist anywhere durable today. This is a direct blocker against the case
study's own "Verification & Trust" requirement, not a nice-to-have.

The second-most consequential finding is a genuine clinical hallucination risk sitting in
the seed data itself, not the agent code: `get_medications` filters on an `active` flag
that is `1` for **100% of 919 prescriptions**, including courses that ended as far back as 1948. Asked "what is this patient currently taking," the co-pilot would present a
decades-old, one-time prescription with the same confidence as an actual current
medication, because the underlying data pipeline never populates a meaningful
active/inactive signal. This needs to be fixed in the import pipeline before the agent is
trusted with medication questions.

Third: the chat flow is fully synchronous with no streaming, driving up to 8 sequential
round-trips through Claude Opus with adaptive thinking enabled, for tools that need at
most 1–2 calls — a direct tension with the case study's "seconds, not minutes" bar, and
the single most important unmeasured number (actual p50/p95 latency) going into Stage 5
planning. Relatedly, no multi-turn conversation state exists anywhere — each question is a
fresh, memory-less request, a gap against the Agentic Chatbot requirement for
follow-up-aware conversation.

On authorization: the co-pilot correctly inherits (rather than widens) OpenEMR's existing
chart-access permission, and cannot be redirected to another patient's data by a crafted
question — patient ID is always server-session-derived. But that underlying permission is
role-wide, not care-team/facility-scoped, so any user who can view _a_ chart can view
_any_ chart through the co-pilot. Free-text chart fields also flow into the model
unfiltered, a real, if narrow, prompt-injection surface.

Encouragingly, the foundational engineering is solid where it matters most for safety:
CSRF is enforced, SQL is fully parameterized with column allowlisting, no IDOR is
possible, exception detail never reaches the browser, and the database queries themselves
are well-indexed with no N+1 patterns — performance risk is concentrated entirely in the
LLM call chain, not the data layer. The Railway deployment is correctly synthetic-data-only
and self-aware of its no-BAA hosting limitation; that gap is informational today but would
become a hard blocker the moment real PHI is ever loaded.

--

### Critical Clinical & Compliance Risks

Missing Audit Trail: While the system correctly logs what chart data the AI reads, it fails to record the actual response given to the clinician. Without this durable record, it is impossible to verify claims or investigate incidents, directly violating "Verification & Trust" requirements.

Severe Hallucination Risk: A flaw in the seed data marks 100% of historical prescriptions (dating back to 1948) as "active." The AI will confidently present decades-old, one-time prescriptions as current medications until the underlying data pipeline is fixed.

### Performance & Usability Blockers

Unbounded Latency: The chat flow is entirely synchronous with no UI streaming. It relies on up to eight sequential round-trips through Claude Opus (a high-latency model) with adaptive thinking enabled. This severely risks the "seconds, not minutes" latency requirement.

No Conversation Memory: The bot lacks multi-turn state; every question is treated as a fresh, memory-less request, failing the Agentic Chatbot requirement for follow-up awareness.

### Security & Architecture Gaps

Overly Broad Access: The co-pilot inherits OpenEMR's role-wide access controls rather than enforcing care-team or facility-specific scoping. A user with chart access can view any patient's chart through the tool.

Prompt Injection: Clinician-entered free text flows unfiltered into the LLM, creating a surface where malicious or erroneous chart notes could manipulate the AI's output.

Scaling Limits: The current single-instance deployment lacks autoscaling, meaning concurrent users will quickly bottleneck the server during long LLM wait times.
