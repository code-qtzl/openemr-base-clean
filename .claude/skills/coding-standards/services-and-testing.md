# Services & testing

## Service layer pattern

New services extend `BaseService`:

```php
namespace OpenEMR\Services;

class ExampleService extends BaseService
{
    public const TABLE_NAME = "table_name";

    public function __construct()
    {
        parent::__construct(self::TABLE_NAME);
    }
}
```

Prefer reusing existing services over raw SQL — they handle UUID registration, event dispatch, and dependent-row creation. Notable ones:

| Service | Key methods | Notes |
|---|---|---|
| `PatientService` | `insert()` | validates, assigns `pid`+`uuid`, dispatches `PatientCreatedEvent` |
| `EncounterService` | `insertEncounter()`, `insertVital()` | generates the encounter number **and** the `forms` row — do not hand-roll |
| `ConditionService` | `insert()` | writes `lists` with `type='medical_problem'` |
| `PrescriptionService` | `insert()` | requires `drug` + `patient_id` |
| `VitalsService` | `save()` | `create()` is a stub — don't use it |
| `ObservationService` | `saveObservation()` | writes `form_observation` |

**Known gap:** there is no service-level or REST path to create lab results. `ProcedureService` has no `insert()` and `/api/procedure` is GET-only. Lab data reaches `procedure_order` → `procedure_order_code` → `procedure_report` → `procedure_result` only via C-CDA import (`src/Services/Cda/CdaTemplateImportDispose::InsertLabResults()`), HL7 ORU ingestion, or direct SQL.

## Running tests

Tests run inside the openemr container. Invoke via `openemr-cmd` (see CONTRIBUTING.md to install). Works from any directory.

```bash
openemr-cmd clean-sweep-tests            # alias: cst -- all tests
openemr-cmd unit-test                    # alias: ut
openemr-cmd api-test                     # alias: at
openemr-cmd e2e-test                     # alias: et
openemr-cmd services-test                # alias: st
openemr-cmd php-log                      # alias: pl -- view PHP error log
```

Target a specific worktree's container from outside it: `openemr-cmd worktree exec <branch> ut`.

Each is equivalent to `docker compose exec openemr /root/devtools <cmd>` from `docker/development-easy/` — useful as a fallback where `openemr-cmd` isn't available.

### Isolated tests

Run without a database — fast; pure-PHP logic, Twig compilation/render tests. Available in-container (no host PHP toolchain needed) or on the host:

```bash
openemr-cmd phpunit-isolated        # in container (alias: pit)
composer phpunit-isolated           # on host (requires PHP + Composer + vendor/)
```

## Data providers: mark as `@codeCoverageIgnore`

PHPUnit data providers execute *before* coverage instrumentation starts, so their lines never register as hit even though they run on every test. Without an explicit ignore they show as uncovered in Codecov patch reports and drag the number down for no real reason.

Use this exact wording so a repo-wide grep finds every provider in one pass:

```php
/**
 * @return array<string, array{string, int}>
 *
 * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
 */
public static function exampleProvider(): array
{
    return [
        'case one' => ['input-1', 1],
    ];
}
```

See `tests/Tests/Isolated/Common/Utils/ValidationUtilsIsolatedTest.php`.

## Twig template tests

Two layers, both isolated:

- **Compilation tests** verify every `.twig` file parses and references valid filters/functions/tests. These run automatically over all templates.
- **Render tests** render specific templates and compare full HTML output to fixtures in `tests/Tests/Isolated/Common/Twig/fixtures/render/`.

When modifying a Twig template with render coverage, regenerate fixtures. **Mutating command** — overwrites recorded expected output:

```bash
openemr-cmd update-twig-fixtures    # in container (alias: utf)
composer update-twig-fixtures       # on host
```

Review the diff before committing. See the [fixtures README](../../../tests/Tests/Isolated/Common/Twig/fixtures/render/README.md).

## Layout field rendering tests

`tests/Tests/Services/Common/Layouts/FieldRenderingSnapshotTest.php` is a **DB-backed** snapshot test (default suite, not isolated) exercising each layout-field renderer branch in `library/options.inc.php`. When intentionally changing the renderer:

```bash
openemr-cmd update-layout-field-fixtures    # in container (alias: ulff)
composer update-layout-field-fixtures       # on host
```

Review the diff before committing.

## Test fixtures

`tests/Tests/Fixtures/` holds reusable managers — prefer these over hand-built records:

- `FixtureManager` — patients, allergies; `PATIENT_FIXTURE_PUBPID_PREFIX = "test-fixture"`
- `ConditionFixtureManager` — `createTestPatient()`, `createTestEncounter()`, `createConditionWithDiagnosis($patientData, $code, $description, $system)`
- `EncounterFixtureManager`, `MedicationDispenseFixtureManager`, `FacilityFixtureManager`, `PractitionerFixtureManager`

API tests use `tests/Tests/Api/ApiTestClient.php`, a Guzzle wrapper handling the full OAuth2 dance.

## Browser debugging via Selenium

The dev stack's Selenium container is a real Chrome session against the running app, drivable via `symfony/panther` from inside the openemr container. Inside the container the grid is `http://selenium:4444/wd/hub` and the app is `http://openemr`.

```php
<?php
use Symfony\Component\Panther\Client;
require '/var/www/localhost/htdocs/openemr/vendor/autoload.php';
$c = Client::createSeleniumClient('http://selenium:4444/wd/hub', null, 'http://openemr');
// ... drive the session ...
$c->quit();
```

Drop into `tmp/debug.php` and run:

```bash
openemr-cmd worktree exec <worktree> e 'php /var/www/localhost/htdocs/openemr/tmp/debug.php'
openemr-cmd e 'php /var/www/localhost/htdocs/openemr/tmp/debug.php'   # non-worktree
```

Files written under the container's `tmp/` appear on the host — handy for `takeScreenshot()` output.

Note: Selenium is only present in the full `development-easy` stack, not `development-easy-light`.
