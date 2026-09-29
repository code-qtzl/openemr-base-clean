# PROJECT_CONTEXT.md — Team, skill constraints, and open-source planning

Closes two `Appendix_CheckList.md` items that are neither audit findings nor
architecture decisions — they're about the team and the project's public
posture, not the system. Phase 1 Item 4 ("Team & Skill Constraints") and
Phase 3 Item 14 ("Open Source Planning").

## Team & skill constraints

**Team: one engineer, working with Claude Code (Anthropic) as the primary
implementation, audit, and testing partner** — not a team of specialists
divided by role. That shapes what this project could realistically cover
and how it compensated for gaps, more than any resume line would.

- **Agent-framework familiarity going in was shallow, and it shows in the
  git history, not just in this paragraph.** The Anthropic tool-use SDK
  integration hit two real bugs only once tested against the live API —
  tool schemas using camelCase `inputSchema` instead of the wire format's
  snake_case `input_schema`, and an empty-object/empty-array JSON encoding
  ambiguity that produced a real 400 (both documented in `EVAL_RESULTS.md`).
  Neither is a design mistake; both are exactly the kind of bug you only
  find by building the integration and calling the real API, which is what
  happened here rather than assuming correctness from documentation alone.
- **Clinical-domain expertise was not assumed, and the architecture is built
  around that constraint rather than around pretending it away.** The
  co-pilot never free-forms a clinical judgment: every claim must cite a
  tool call actually made this turn (`ResponseVerifier`), and every
  answer is decision support framed for a clinician who is the actual
  domain expert, never a diagnosis or a prescription. Domain risk (stale
  medications, unresolved decades-old problems) is caught structurally —
  `MedicationStalenessPolicy` / `ActiveProblemStalenessPolicy` — rather
  than relying on the builder's own clinical judgment to spot it by eye.
  This is the project's actual answer to "experience with your chosen
  domain": substitute verification for expertise where expertise doesn't
  exist, and say so, rather than quietly assuming competence the team
  doesn't have.
- **Eval/testing framework comfort was built up through the project, not
  assumed at the start.** 111+ PHPUnit tests exist, each naming the
  specific failure mode it guards (see `EVAL_RESULTS.md`'s own framing:
  boundary, invariant, regression, adversarial) — a discipline that is a
  direct product of the iterative, AI-assisted build-test-audit loop this
  project ran on, not a pre-existing testing background applied top-down.

**How this actually constrained the build:** scope stayed narrow on purpose
— four read-only chart tools, one patient, no free-text prescription
authority — specifically because a small, one-person-plus-agent team
without deep prior clinical-AI experience should not be building a system
that acts on its own judgment in a domain neither the team nor (fully) the
underlying model can be trusted to get right unsupervised. The verification
layer, staleness policies, and citation-forced answers are the direct
mitigation for this constraint, not incidental features.

## Open-source planning

**What will be released, today:** the deployed app
(`https://openemr-production-a819.up.railway.app`) is public — that is a
hard requirement of the case study this fork was built for. **The source
code is not, yet.** This repo's primary remote is now a private GitHub
repository (`github.com/code-qtzl/openemr-base-clean`, `origin`) — migrated
here from the original self-hosted GitLab instance (`labs.gauntletai.com`)
specifically so real CI (branch protection, the eval-gate PR check, etc.)
could run against it. The `gitlab` remote itself has since been removed
from this checkout — GitHub is the sole remote in active use. So the
honest current answer to "what will you release" is: a
live demo with synthetic data, and no published source while the GitHub
repo stays private -- flipping it to public (a plain visibility toggle, not
a platform migration) is the only remaining step if/when the source itself
is released.

**Licensing:** already settled by inheritance, not a new decision. This is
a fork of OpenEMR, licensed GPLv3 (`LICENSE`, repo root). The Clinical
Co-Pilot module lives inside that same repository under
`interface/modules/custom_modules/`, has no separate license file, and
every file header in it already cites the same GPLv3 license link
(`CLAUDE.md`'s File Headers convention) — so if the source is ever
published, it is GPLv3-compatible with no additional licensing work needed.
A standalone module release outside the OpenEMR tree (unlikely, given how
tightly it's coupled to OpenEMR's session/ACL/audit-log primitives) would
need its own explicit GPLv3 grant, but that is not the shape this module is
built in.

**Documentation requirements:** largely already met if source publication
happened today — `AUDIT.md`, `ARCHITECTURE.md`, `USERS.md`,
`EVAL_RESULTS.md`, `ALERTS.md`, `AI_SPEND.md`, `PERFORMANCE_BASELINE.md`,
and `ROLLBACK_RUNBOOK.md` cover setup rationale, architecture, known
limitations, real measured cost and latency, and incident response — the
things an external reader or contributor would actually need, not just a
README. What's missing if this specifically were published as an
open-source project (distinct from the base OpenEMR project's own
`CONTRIBUTING.md`) is a contribution guide scoped to the module itself:
how to run its tests in isolation, its own coding conventions beyond the
base repo's, and how to propose a new chart tool safely (the allowlisting
model in `ChartContextTools`'s docblock is the de facto answer today, but
it isn't written as a contributor-facing guide).

**Community engagement plan: deliberately none, and that is the actual
plan, not an oversight.** This is a healthcare AI tool with disclosed,
still-real limitations — no BAA on the hosting provider, a hallucination
guard that is deliberately over-conservative rather than fully solved (see
`ResponseVerifier`'s documented known limitation), and a single-person
audit rather than a security review by outside expertise. Publishing this
for outside contribution or reuse before those gaps close would invite
exactly the failure mode `AgentForge.md`'s "Verification & Trust" hard
problem warns about, just aimed at a wider, less-informed audience instead
of one clinician who already knows the tool is a prototype. The deployed
demo stays public because the case study requires it; broader open-source
engagement is out of scope until the compliance and review gaps above are
actually closed, not just documented.
