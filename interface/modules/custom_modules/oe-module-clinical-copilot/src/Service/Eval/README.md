# Clinical Co-Pilot Evals

Deterministic, programmatic evals for the Clinical Co-Pilot, following
`.claude/skills/eval-citation-validator/SKILL.md`'s guidance: no LLM judge
where a rubric category can be validated structurally. Each eval category is
its own subdirectory here, paired with a golden set and an isolated PHPUnit
test so a regression is caught automatically on every PR.

| Category | Status |
|---|---|
| `citation_present` | Implemented (`Citation/`) |
| `schema_valid` | Not yet implemented |
| `factually_consistent` | Not yet implemented |
| `safe_refusal` | Not yet implemented |
| `no_phi_in_logs` | Not yet implemented |

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

### How to test locally

All commands run inside the dev container — no host PHP toolchain needed.

**Just this eval's tests** (fastest, what to run while iterating):

```bash
cd docker/development-easy
docker compose exec openemr bash -c "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpunit -c phpunit-isolated.xml --filter CitationValidatorTest --testdox"
```

Expected output:

```
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.6
Configuration: /var/www/localhost/htdocs/openemr/phpunit-isolated.xml

..............                                                    14 / 14 (100%)

Time: 00:00.203, Memory: 50.00 MB

Citation Validator (OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Service\Eval\Citation\CitationValidator)
 ✔ Fully cited claim passes
 ✔ Partial citation fails and reports missing fields
 ✔ Missing citation object fails with all fields reported missing
 ✔ Empty string fields count as missing
 ✔ Batch passes only when every claim is grounded
 ✔ To array matches skill contract shape
 ✔ Golden set case with data set "valid-lab-pdf"
 ✔ Golden set case with data set "valid-intake-form"
 ✔ Golden set case with data set "missing-citation"
 ✔ Golden set case with data set "partial-citation"
 ✔ Golden set case with data set "empty-citation-fields"
 ✔ Golden set case with data set "multi-claim-one-missing"
 ✔ Golden set case with data set "evidence-retrieval-with-source"
 ✔ Golden set case with data set "unsupported-claim"

OK (14 tests, 36 assertions)
```

> **Gotcha:** `openemr-cmd phpunit-isolated -- --filter ...` (alias `pit`)
> does not actually pass the filter through — it silently runs the entire
> isolated suite instead. Use the `docker compose exec ... vendor/bin/phpunit`
> form above when you want to scope to one test class.

**Full isolated suite** (what actually gates every PR — the new tests are
folded into this run):

```bash
openemr-cmd phpunit-isolated    # alias: pit
```

**Static analysis / style on just this eval's files:**

```bash
cd docker/development-easy

docker compose exec openemr bash -c "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpstan analyse --no-progress --memory-limit=1G interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/ tests/Tests/Fixtures/ClinicalCopilot/Eval/ tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/Citation/"

docker compose exec openemr bash -c "cd /var/www/localhost/htdocs/openemr && vendor/bin/phpcs --standard=PSR12 interface/modules/custom_modules/oe-module-clinical-copilot/src/Service/Eval/Citation/ tests/Tests/Fixtures/ClinicalCopilot/Eval/ tests/Tests/Isolated/Modules/ClinicalCopilot/Service/Eval/Citation/"
```

If PHPStan crashes with an OOM on a stale result cache, clear it first:

```bash
docker compose exec openemr rm -f /var/www/localhost/htdocs/openemr/tmp-phpstan/resultCache.php
```
