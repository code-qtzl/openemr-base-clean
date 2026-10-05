---
name: eval-schema-validator
description: Validate that extracted lab-PDF and intake-form documents contain every required schema field. Use when building or running AgentForge evals, reviewing document-extraction code, or checking schema_valid regressions.
---

# Schema Validation Eval

## Purpose

Evaluate whether a structured document extracted by `attach_and_extract`
(AgentForge2 Core Requirement #1/#2) contains every field the strict
schema requires for its document type, before that extraction is trusted
anywhere downstream -- persisted as a FHIR/OpenEMR record, cited in a
response, or fed to evidence retrieval.

This is a deterministic eval. It checks field presence and shape only --
never the field's clinical correctness (that is `factually_consistent`'s
job) and never whether a claim built from it is cited (that is
`citation_present`'s job). Keep those boundaries; do not let this skill's
checks bleed into theirs.

The corresponding rubric category is:

`schema_valid`

## Required Schema Contract

Per AgentForge2's Core Requirement #2, by document type:

**`lab_pdf`** -- test_name, value, unit, reference_range, collection_date,
abnormal_flag, source_citation.

**`intake_form`** -- demographics, chief_concern, current_medications,
allergies, family_history, source_citation.

Every document type also requires `source_citation`: a citation-shaped
object must be *present* in the extraction. Whether that citation's own
five sub-fields (`source_type`, `source_id`, `page_or_section`,
`field_or_chunk_id`, `quote_or_value`) are complete is
`citation_present`'s question, not this skill's -- do not duplicate that
check here.

## Field Shape Rules

Not every required field is validated the same way -- a naive
"non-empty" check across the board would wrongly fail legitimate data:

- **Scalar fields** (`test_name`, `value`, `unit`, `reference_range`,
  `collection_date`, `chief_concern`, `family_history`): must be present
  and, if a string, non-empty after trimming. A non-string scalar (e.g. a
  numeric `value`) only needs to be non-null.
- **`abnormal_flag`**: must be present and non-null, but an *empty string is
  valid*. Lab reports print no flag for a normal result, so blank means "not
  flagged"; failing it would reject most real panels. (The key being absent
  is still a failure.)
- **List fields** (`current_medications`, `allergies`): must be present
  and be an array. An *empty* array is valid and must pass -- "no known
  allergies" is real extracted content, not a missing field. Only an
  absent key or a non-array value fails.
- **Object fields** (`demographics`, `source_citation`): must be present
  and be a non-empty array/object. An empty `{}` is a missing field in
  practice, even though the key exists.

Getting the list-vs-scalar distinction wrong is the most likely way to
misimplement this skill -- a validator that requires `allergies` to be
non-empty will fail every genuinely healthy patient.

## Validation Process

For one extracted document:

1. Determine its document type (`lab_pdf` or `intake_form`) and look up
   that type's required-field list and each field's shape rule.
2. For each required field, apply its shape rule (scalar / list / object)
   from the section above.
3. Record every field that fails its rule, with the reason (missing,
   wrong shape, or empty when non-empty was required).
4. Return the final boolean result.

Example:

Input:

{
"doc_type": "lab_pdf",
"fields": {
"test_name": "HbA1c",
"value": "7.2",
"unit": "%",
"reference_range": "4.0-5.6",
"collection_date": "2026-01-15",
"abnormal_flag": true,
"source_citation": {
"source_type": "lab_pdf",
"source_id": "lab-001",
"page_or_section": "page_1",
"field_or_chunk_id": "hba1c",
"quote_or_value": "7.2%"
}
}
}

Expected:

{
"schema_valid": true
}

Failure example (missing field):

Input:

{
"doc_type": "lab_pdf",
"fields": {
"test_name": "HbA1c",
"value": "7.2"
}
}

Expected:

{
"schema_valid": false,
"failures": [
{"field": "unit", "reason": "missing"},
{"field": "reference_range", "reason": "missing"},
{"field": "collection_date", "reason": "missing"},
{"field": "abnormal_flag", "reason": "missing"},
{"field": "source_citation", "reason": "missing"}
]
}

Negative-control example (must NOT be flagged -- the list-field boundary
case that most often gets this rule wrong):

Input:

{
"doc_type": "intake_form",
"fields": {
"demographics": {"age": 54, "sex": "F"},
"chief_concern": "Follow-up for elevated A1C.",
"current_medications": ["metformin 500mg"],
"allergies": [],
"family_history": "Mother: type 2 diabetes.",
"source_citation": {
"source_type": "intake_form",
"source_id": "intake-2026-01-05",
"page_or_section": "allergies",
"field_or_chunk_id": "allergies",
"quote_or_value": "NKDA"
}
}
}

Expected:

{
"schema_valid": true
}

(`allergies` is an empty array here -- a genuinely healthy "no known
allergies" result, not a missing field. Re-run with the `allergies` key
removed entirely and it should fail.)

## Failure Reporting

When validation fails, report the field and why: `missing` (key absent or
null), `wrong_shape` (present but not the type the field's rule requires,
e.g. a string where a list was required), or `empty` (present as the
right shape but empty when non-empty was required, e.g. a blank
`test_name` string or an empty `demographics` object). Do not silently
default a missing field to a placeholder value during evaluation.

## Golden Set Integration

Each case should have: stable case ID, input (`doc_type` + `fields`),
expected `schema_valid` result, and tags. Include both positive and
negative cases, covering:

- A fully populated lab_pdf extraction (pass).
- A fully populated intake_form extraction (pass).
- A lab_pdf missing several fields (fail).
- An intake_form missing `source_citation` only (fail).
- An intake_form with empty-array `current_medications`/`allergies` --
  the negative-control boundary case above (pass).
- An intake_form with an empty `demographics` object (fail -- object
  fields require non-empty, unlike list fields).
- A lab_pdf with a non-string `value` (e.g. a float) and a boolean
  `abnormal_flag` (pass -- non-string scalars only need to be non-null).
- A document whose `source_citation` is present but structurally empty,
  e.g. `{}` (fail here, even though whether its *sub-fields* are complete
  is a separate `citation_present` question).

## CI Behavior

The validator must produce machine-readable results suitable for the
Week 2 eval gate. A schema regression -- a required field silently
dropped by an extraction-pipeline change -- must be detectable
automatically before it reaches storage or a response.

Keep this skill focused on `schema_valid`. Do not implement unrelated
eval categories (`citation_present`, `factually_consistent`,
`safe_refusal`, `no_phi_in_logs`) unless explicitly requested.
