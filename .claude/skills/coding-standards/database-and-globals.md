# Database, globals & authorization

## Database access

- Use `QueryUtils` for queries.
- New schema changes use **Doctrine Migrations**.
- **Do not instantiate database connections directly** — use the centralized `DatabaseConnectionFactory`.

MySQL is reached via Doctrine DBAL 4.x, with an ADODB surface API retained for legacy code.

Note that the ADODB layer auto-audits every query through `EventAuditLogger::auditSQLEvent()` (`library/ADODB_mysqli_log.php`). Going through `sqlStatement()`/`QueryUtils` therefore gets PHI access auditing for free; raw PDO/mysqli connections bypass it.

## Global settings

Use `OEGlobalsBag` (extends Symfony `ParameterBag`) instead of `$GLOBALS`. Prefer typed getters over `get()` + cast:

- `getString($key)` instead of `(string) get($key)`
- `getInt($key)` instead of `(int) get($key)`
- `getBoolean($key)` instead of `(bool) get($key)`
- `getKernel()` for the Kernel instance

Check the parent class for more: `getAlpha()`, `getAlnum()`, `getDigits()`, `getEnum()`.

Accessing the event dispatcher:

```php
OpenEMR\Core\OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher()
```

Guard with `->hasKernel()` where the kernel may not be constructed yet.

## Authorization modeling

When an operation requires authorization, **type the principal** — do not pass authorization context as strings or bare integers:

```php
// Bad
function approveOrder(int $userId, int $orderId): void {}

// Good
function approveOrder(ClinicalUser $approver, OrderId $orderId): void {}
```

When an operation is scoped to a facility or tenant, encode that scope in the type (e.g. a scoped repository) rather than relying on runtime checks scattered through the codebase.

### OpenEMR's actual ACL surface

The core check is `OpenEMR\Common\Acl\AclMain::aclCheckCore($section, $value)`. Sections and values are documented in `src/Common/Acl/AclMain.php`.

**Important limitation to design around:** core OpenEMR has *no* per-patient or care-team scoping. Any logged-in user holding `patients|demo` can read **any** patient record. The only patient-level scoping mechanisms are opt-in extension points:

- `OpenEMR\Events\PatientDemographics\ViewEvent` — a listener calls `$event->setAuthorized(false)` to deny
- `OpenEMR\Events\PatientFinder\PatientFinderFilterEvent` — appends a bound SQL WHERE clause
- `AppointmentsFilterEvent`, `PatientSelectFilterEvent`

Coarser mechanisms that do exist: the `squads` and `sensitivities` ACL sections, and the `restrict_user_facility` global (which restricts user/provider lists and the calendar, **not** patient record reads).

If a feature requires "this user may only access their own patients," that must be built explicitly — the framework does not provide it.

## Audit logging

Write audit entries via:

```php
use OpenEMR\Common\Logging\EventAuditLogger;

EventAuditLogger::getInstance()->newEvent(
    $event,       // 'select','update','insert','delete','login', or custom
    $user,        // session authUser
    $groupname,   // session authProvider
    $success,     // 1|0
    $comments,
    $patient_id,  // ?int — the PHI linkage
    $log_from,    // 'open-emr' | 'patient-portal'
    $menu_item,
);
```

Entries land in the `log` table. Gating globals: `enable_auditlog`, `audit_events_query` (needed for SELECT logging — **off by default upstream**), `audit_events_patient-record`, `audit_events_http-request`.
