# Clinical Co-Pilot Evals

Deterministic, programmatic evals for the Clinical Co-Pilot, following
`.claude/skills/eval-citation-validator/SKILL.md`'s guidance: no LLM judge
where a rubric category can be validated structurally. Each eval category is
its own subdirectory here, paired with a golden set and an isolated PHPUnit
test so a regression is caught automatically on every PR.

None of these are wired into `CopilotChatController`/`CopilotService`'s live
request path yet -- they are eval-gate tooling only (golden set + PHPUnit),
matching AgentForge2 Core Requirement #6's framing ("a 50-case golden set and
a PR-blocking Git Hook"). Wiring a live response through these validators is
a separate, later integration decision.

| Category | Status |
|---|---|
| `citation_present` | Implemented (`Citation/`) |
| `schema_valid` | Implemented (`Schema/`) |
| `factually_consistent` | Implemented (`FactualConsistency/`) |
| `safe_refusal` | Implemented (`SafeRefusal/`) |
| `no_phi_in_logs` | Implemented (`PhiLogGuard/`) |

## `citation_present`

Validates that every clinical claim in a co-pilot response carries complete
citation metadata: `source_type`, `source_id`, `page_or_section`,
`field_or_chunk_id`, `quote_or_value`, each a non-empty string. A response
passes only if *every* claim in it is grounded.

### Files created

Production code (`interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/`):
1. `Citation.php` — citation metadata DTO + parser
2. `ClinicalClaim.php` — claim + citation DTO + parser
3. `ClaimCitationResult.php` — per-claim validation result DTO
4. `CitationValidationReport.php` — batch validation result DTO
5. `CitationValidator.php` — the validator logic

Test fixtures (`tests/Tests/Fixtures/ClinicalCopilot/Eval/`):
6. `CitationGoldenSetCase.php` — golden-set case DTO
7. `CitationGoldenSet.php` — the 8 golden-set cases

Test (`tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/Citation/`):
8. `CitationValidatorTest.php` — 14 tests

## `schema_valid`

Validates that a document extracted by `attach_and_extract` contains every
field its document type's strict schema requires (`lab_pdf`: test_name,
value, unit, reference_range, collection_date, abnormal_flag,
source_citation; `intake_form`: demographics, chief_concern,
current_medications, allergies, family_history, source_citation) — presence
and shape only, never clinical correctness or citation completeness. List
fields (`current_medications`, `allergies`) accept an empty array as valid
content; object fields (`demographics`, `source_citation`) require non-empty.

### Files created

Production code (`.../Service/Eval/Schema/`):
1. `SchemaDocType.php` — `lab_pdf` / `intake_form` backed enum
2. `SchemaFieldKind.php` — Scalar / ListField / ObjectField unit enum
3. `ExtractedDocument.php` — doc type + fields DTO + parser
4. `SchemaFinding.php` — per-field violation DTO
5. `SchemaValidationResult.php` — validation result DTO
6. `SchemaValidator.php` — the validator logic

Test fixtures (`tests/Tests/Fixtures/ClinicalCopilot/Eval/`):
7. `SchemaGoldenSetCase.php` — golden-set case DTO
8. `SchemaGoldenSet.php` — the 8 golden-set cases

Test (`tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/Schema/`):
9. `SchemaValidatorTest.php` — 13 tests

## `factually_consistent`

Validates that a claim's asserted value matches its own citation's
`quote_or_value` — deliberately narrow to numeric/discrete values (anything
with a digit: lab results, dates, dosages, codes). Narrative claims and
claims with no citation value to check are silently skipped, not failed, and
no LLM judge is used anywhere — a direct response to the "llm-as-a-judge
without clear rubric" pitfall. Comparison is exact after light
normalization, with float-tolerant equality only for purely numeric values
(handles `"7.20%"` vs `"7.2%"`); no unit conversion or date reparsing.

### Files created

Production code (`.../Service/Eval/FactualConsistency/`):
1. `FactualConsistencyClaim.php` — claim + asserted_value + citation DTO + parser
2. `FactualConsistencyFinding.php` — per-claim mismatch DTO
3. `FactualConsistencyResult.php` — validation result DTO
4. `FactualConsistencyValidator.php` — the validator logic

Test fixtures (`tests/Tests/Fixtures/ClinicalCopilot/Eval/`):
5. `FactualConsistencyGoldenSetCase.php` — golden-set case DTO
6. `FactualConsistencyGoldenSet.php` — the 8 golden-set cases

Test (`tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/FactualConsistency/`):
7. `FactualConsistencyValidatorTest.php` — 15 tests

## `safe_refusal`

Validates that the agent refuses or qualifies a claim it cannot ground,
instead of inventing a value. Reuses `CitationValidator`'s per-claim
grounding check rather than reimplementing it. Two required directions per
case (`refusal_required` / `confident_answer_required`, a backed enum) so
the gate cannot be satisfied by blanket hedging.

### Files created

Production code (`.../Service/Eval/SafeRefusal/`):
1. `SafeRefusalCaseType.php` — the two-direction backed enum
2. `SafeRefusalCase.php` — field + case type + claims DTO + parser
3. `SafeRefusalFinding.php` — per-case violation DTO
4. `SafeRefusalResult.php` — validation result DTO
5. `SafeRefusalValidator.php` — the validator logic

Test fixtures (`tests/Tests/Fixtures/ClinicalCopilot/Eval/`):
6. `SafeRefusalGoldenSetCase.php` — golden-set case DTO
7. `SafeRefusalGoldenSet.php` — the 8 golden-set cases

Test (`tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/SafeRefusal/`):
8. `SafeRefusalValidatorTest.php` — 13 tests

## `no_phi_in_logs`

Validates that logs/traces/telemetry never carry raw PHI. Gates on
destination, not just field name: the citation contract's `quote_or_value`
is required in the `application_response` payload but forbidden in
telemetry (Langfuse traces, PHP error logs, etc.) sent to the same field
name. Combines a field-name denylist with a value-pattern scan (SSN shape,
labeled DOB/MRN, `"Patient <Name> <Name>"`, base64 image blobs) to also
catch PHI embedded under a generic field like a log message.

### Files created

Production code (`.../Service/Eval/PhiLogGuard/`):
1. `LogEmission.php` — destination + payload DTO + parser
2. `PhiFinding.php` — per-field violation DTO
3. `PhiScanResult.php` — scan result DTO
4. `PhiLogGuardValidator.php` — the validator logic

Test fixtures (`tests/Tests/Fixtures/ClinicalCopilot/Eval/`):
5. `PhiLogGuardGoldenSetCase.php` — golden-set case DTO
6. `PhiLogGuardGoldenSet.php` — the 7 golden-set cases

Test (`tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/PhiLogGuard/`):
7. `PhiLogGuardValidatorTest.php` — 13 tests

## How to test locally

All commands run inside the dev container — no host PHP toolchain needed.

**Just one eval's tests** (fastest, what to run while iterating) — replace
`<TestClass>` with e.g. `CitationValidatorTest`, `SchemaValidatorTest`,
`FactualConsistencyValidatorTest`, `SafeRefusalValidatorTest`, or
`PhiLogGuardValidatorTest`:

```bash
cd docker/development-easy
docker compose exec openemr bash -c "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpunit -c phpunit-isolated.xml --filter <TestClass> --testdox"
```

> **Gotcha:** `openemr-cmd phpunit-isolated -- --filter ...` (alias `pit`)
> does not actually pass the filter through — it silently runs the entire
> isolated suite instead. Use the `docker compose exec ... vendor/bin/phpunit`
> form above when you want to scope to one test class.

**Full isolated suite** (what actually gates every PR — every eval's tests
are folded into this run):

```bash
openemr-cmd phpunit-isolated    # alias: pit
```

**Static analysis / style on one eval's files** — replace `<Category>` with
e.g. `Citation`, `Schema`, `FactualConsistency`, `SafeRefusal`, or
`PhiLogGuard`:

```bash
cd docker/development-easy

docker compose exec openemr bash -c "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpstan analyse --no-progress --memory-limit=1G interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/<Category>/ tests/Tests/Fixtures/ClinicalCopilot/Eval/ tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/<Category>/"

docker compose exec openemr bash -c "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpcs --standard=PSR12 interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/<Category>/ tests/Tests/Fixtures/ClinicalCopilot/Eval/ tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/<Category>/"
```

If PHPStan crashes with an OOM (stale result cache, or the default 512M
limit on a cold run), clear the cache and/or pass `--memory-limit=1G` as
above:

```bash
docker compose exec openemr rm -f /var/www/localhost/htdocs/openemr/tmp-phpstan/resultCache.php
```
