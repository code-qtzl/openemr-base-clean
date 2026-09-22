---
name: eval-citation-validator
description: Validate that clinical claims contain complete source citation metadata. Use when building or running AgentForge evals, reviewing agent responses, or checking citation regressions.
---

# Citation Validation Eval

## Purpose

Evaluate whether clinical claims produced by the Clinical Co-Pilot are grounded in source evidence.

This is a deterministic eval. Do not use an LLM judge when the citation structure can be validated programmatically.

## Required Citation Contract

Every clinical claim must include citation metadata containing:

- `source_type`
- `source_id`
- `page_or_section`
- `field_or_chunk_id`
- `quote_or_value`

A citation passes only when all required fields exist and contain meaningful values.

## Eval Output

Return a boolean result:

- `true` — required citation metadata is present.
- `false` — citation metadata is missing, incomplete, or empty.

The corresponding rubric category is:

`citation_present`

## Validation Process

For each clinical claim:

1. Determine whether the claim requires source grounding.
2. Locate its associated citation metadata.
3. Verify all required citation fields exist.
4. Verify required fields are not null or empty.
5. Record failures with enough information to identify the claim and missing field.
6. Return the final boolean result.

Example:

Input:

{
"claim": "The patient's A1C is 7.2%.",
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
"citation_present": true
}

Failure example:

{
"claim": "The patient's A1C is 7.2%.",
"citation": {
"source_type": "lab_pdf",
"source_id": "lab-001"
}
}

Expected:

{
"citation_present": false
}

## Failure Reporting

When validation fails, report why.

Example:

{
"citation_present": false,
"failures": [
{
"claim": "The patient's A1C is 7.2%.",
"missing_fields": [
"page_or_section",
"field_or_chunk_id",
"quote_or_value"
]
}
]
}

Do not silently repair missing citations during evaluation.

## Golden Set Integration

This validator should be usable against synthetic/demo golden-set cases.

Each case should have:

- stable case ID
- input
- expected behavior
- expected `citation_present` result
- category/tags where useful

Include both positive and negative cases.

Examples should cover:

- Valid lab PDF citation
- Valid intake-form citation
- Missing citation
- Partial citation
- Empty citation fields
- Multiple claims where one lacks a citation
- Evidence-retrieval response with source metadata
- Response containing an unsupported clinical claim

## CI Behavior

The validator must produce machine-readable results suitable for the Week 2 eval gate.

A citation regression must be detectable automatically.

Do not replace deterministic validation with subjective scoring.

The broader Week 2 eval suite must eventually support the required boolean categories:

- `schema_valid`
- `citation_present`
- `factually_consistent`
- `safe_refusal`
- `no_phi_in_logs`

Keep this skill focused on `citation_present`. Do not implement unrelated eval categories unless explicitly requested.
