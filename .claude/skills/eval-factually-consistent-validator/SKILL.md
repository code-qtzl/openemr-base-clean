---
name: eval-factually-consistent-validator
description: Validate that a claim's asserted value matches its own cited source value, for numeric/discrete facts. Use when building or running AgentForge evals, reviewing agent responses, or checking factually_consistent regressions.
---

# Factual Consistency Eval

## Purpose

Evaluate whether a clinical claim accurately restates the value it cites --
not whether it *has* a citation (`citation_present`'s job), and not
whether the underlying document/guideline the citation points to is
itself accurate (extraction-pipeline accuracy, out of this skill's scope
entirely). This is a narrower, harder question than either: given a claim
already carries a real citation with a real `quote_or_value`, did the
model restate that value faithfully, or did it drift -- the concrete
failure mode of a model citing a lab PDF's `7.2%` and then writing `7.9%`
in its prose.

The corresponding rubric category is:

`factually_consistent`

## Deliberately Narrow Scope (read this before extending the code)

This validator only checks **numeric/discrete values against their own
citation**, and it is deterministic -- no LLM judge anywhere in it. That
scope is a direct response to the Common Pitfall "using llm-as-a-judge
without clear rubric": narrative/interpretive claims (e.g. "the patient's
diabetes is well controlled") would require semantic judgment this skill
does not attempt. Rather than bolt on a shaky judge for that half of the
problem, narrative claims are simply **not checked by this validator** --
a documented limitation, the same kind of explicit MVP gap
`ResponseVerifier`'s own docblock already carries elsewhere in this
module, not a silent one.

Do not widen this validator to judge narrative consistency without first
designing a strict, single-question boolean rubric for it (the same
discipline `eval-safe-refusal-validator/SKILL.md` applies) -- do not add
a general-purpose "is this claim true" LLM call.

## Input Contract

Distinct from `citation_present`'s `ClinicalClaim`: this eval needs a
claim's asserted value as its own structured field, not something parsed
out of free prose (parsing `"7.2%"` back out of `"The patient's A1C is
7.2%."` is exactly the kind of brittle text-scraping a deterministic
validator should not do). Each case is:

- `claim` -- the free-text claim, kept for failure reporting only.
- `asserted_value` -- the specific value the claim asserts, as its own
  string field.
- `citation` -- the same five-field citation object `citation_present`
  validates, in particular `quote_or_value`.

## Checkability

A claim is **checkable** only if:

1. It has a citation, and that citation's `quote_or_value` is present and
   non-empty (if not, the claim is `citation_present`'s failure to
   report, not this validator's -- skip it here rather than double-count
   the same defect across two rubric categories).
2. `quote_or_value` contains at least one digit -- the signal that it is
   a numeric/discrete value (a lab result, a date, a dosage, a diagnosis
   code) rather than narrative text. This is deliberately broader than a
   strict "is a number" regex: it also catches dates (`2026-01-15`),
   dosages (`500mg`), and codes (`E11.9`) -- anything exact and
   drift-prone, not just plain numbers.

A non-checkable claim is **silently skipped**: no finding, does not
affect the batch result. There is no third "not applicable" status --
every other validator in this eval suite is strictly `{bool, failures[]}`,
and this one stays consistent with that shape.

## Comparison Rule

For a checkable claim, compare `asserted_value` to `quote_or_value`:

1. Always: trim, collapse internal whitespace, case-insensitive.
2. If, after stripping a trailing `%`, both sides are `is_numeric()` --
   compare as floats with a small epsilon. This is the only tolerance in
   this validator, and it exists for one reason: formatting/precision
   (`"7.20"` vs `"7.2"`), not to paper over an actual value difference.
3. Otherwise -- exact string comparison after step 1's normalization
   only. No date reparsing, no unit conversion (`500mg` is not compared
   to `0.5g`). A model that restates a date or code in a different
   format is flagged, not guessed at.

## Eval Output

Per claim, a boolean:

- `true` -- either not checkable (skipped), or checkable and the values
  match.
- `false` -- checkable and the values do not match.

Per response (a batch of claims), `factually_consistent` is true only if
every *checkable* claim in it matches; skipped claims never cause a
failure.

Example:

Input:

{
"claim": "The patient's A1C is 7.2%.",
"asserted_value": "7.2%",
"citation": {
"source_type": "lab_pdf",
"source_id": "lab-001",
"page_or_section": "page_1",
"field_or_chunk_id": "a1c",
"quote_or_value": "7.2%"
}
}

Expected:

{
"factually_consistent": true
}

Failure example (drift):

Input:

{
"claim": "The patient's A1C is 7.9%.",
"asserted_value": "7.9%",
"citation": {
"source_type": "lab_pdf",
"source_id": "lab-001",
"page_or_section": "page_1",
"field_or_chunk_id": "a1c",
"quote_or_value": "7.2%"
}
}

Expected:

{
"factually_consistent": false,
"failures": [
{
"claim": "The patient's A1C is 7.9%.",
"asserted_value": "7.9%",
"quote_or_value": "7.2%",
"reason": "asserted value does not match the cited source's quote_or_value"
}
]
}

Negative-control example (must NOT be flagged -- the precision-formatting
boundary case that most often gets this rule wrong):

Input:

{
"claim": "The patient's A1C is 7.20%.",
"asserted_value": "7.20%",
"citation": {
"source_type": "lab_pdf",
"source_id": "lab-001",
"page_or_section": "page_1",
"field_or_chunk_id": "a1c",
"quote_or_value": "7.2%"
}
}

Expected:

{
"factually_consistent": true
}

## Failure Reporting

When validation fails, report the claim, both values, and the fixed
reason string above -- there is only one way this check fails (a value
mismatch), unlike `schema_valid`'s several distinct shape failures. Do
not silently accept a mismatch as "close enough" beyond the numeric
epsilon in the Comparison Rule.

## Golden Set Integration

Each case should have: stable case ID, input, expected
`factually_consistent` result, and tags. Include:

- An exact numeric match (pass).
- A precision-formatting difference on an otherwise-matching numeric
  value -- the negative-control boundary case above (pass).
- A genuine numeric drift, cited value vs. asserted value differ (fail).
- A non-numeric discrete value (a date or diagnosis code) that matches
  exactly (pass) and one that drifts (fail).
- A narrative claim with no digit in `quote_or_value` -- skipped, passes
  by exclusion (pass).
- A claim with no citation at all -- skipped here, not double-counted
  against `citation_present` (pass).
- A batch with one failing checkable claim among otherwise-passing and
  skipped claims -- the whole batch fails (fail).

## CI Behavior

The validator must produce machine-readable results suitable for the
Week 2 eval gate. A numeric-drift regression -- a model paraphrasing a
lab value or date away from what it actually retrieved -- must be
detectable automatically.

Keep this skill focused on `factually_consistent`'s narrow,
numeric/discrete scope. Do not implement unrelated eval categories
(`schema_valid`, `citation_present`, `safe_refusal`, `no_phi_in_logs`),
and do not widen this one to narrative-claim judgment without designing
a dedicated, strictly-boolean rubric for it first.
